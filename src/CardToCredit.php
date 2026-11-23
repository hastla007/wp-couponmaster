<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-98: "Add to my store credit" in My Account → Vouchers. A gift card with a stored balance becomes store
 * credit of the logged-in customer: its remaining value is added to the customer's credit account, the card is
 * voided and both histories show the booking ("moved_to_credit" on the card, "credit" on the account), in one
 * transaction. Allowed for the verified owner (the card was bought in the customer's own order) or whoever holds
 * the full code (typed into the form, rate limited like every code entry); personal cards only for their named
 * addresses. Needs store credit to be switched on. Merging cards is not part of the core.
 */
final class CardToCredit {
	public const ACTION = 'vcap_card_to_credit';

	public function __construct( private StoreCredit $credit, private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'vcap_account_vouchers_top', array( $this, 'form' ), 15 );
		add_action( 'vcap_account_voucher_actions', array( $this, 'button' ) );
		add_action( 'wp_loaded', array( $this, 'handle' ), 30 );
	}

	/**
	 * Why the card cannot be moved into this customer's credit ('' = it can).
	 *
	 * @param bool $holds_code Whether the customer typed the full code (else: owner check by the order).
	 */
	public function refusal( Voucher $card, int $user_id, bool $holds_code ): string {
		if ( ! $this->credit->enabled() || $user_id <= 0 ) {
			return __( 'Store credit is not available.', 'voucher-captain' );
		}
		if ( 'credit' === $card->type || 'balance' !== $card->usage_mode || 'percent' === $card->discount_type || $card->is_ticket() ) {
			return __( 'Only gift cards with a remaining value can be added to the store credit.', 'voucher-captain' );
		}
		if ( Voucher::STATUS_ACTIVE !== $card->status || $card->is_expired() || $card->balance <= 0 ) {
			return __( 'This card is not active or has no remaining value.', 'voucher-captain' );
		}
		if ( strtoupper( (string) $card->currency ) !== strtoupper( (string) get_option( 'woocommerce_currency' ) ) ) {
			return __( 'This card is in another currency.', 'voucher-captain' );
		}
		if ( vcap()->coupons->open_holds( $card ) > 0.00001 ) {
			return __( 'Part of this card is reserved for an unfinished order. Please try again later.', 'voucher-captain' );
		}
		$user  = get_userdata( $user_id );
		$email = $user ? strtolower( (string) $user->user_email ) : '';
		if ( ! $card->transferable && ! in_array( $email, array( strtolower( $card->buyer_email ), strtolower( $card->recipient_email ) ), true ) ) {
			return __( 'This personal card can only be used by the person it is made out to.', 'voucher-captain' );
		}
		if ( ! $holds_code ) {
			$order = $card->order();
			if ( ! $order || (int) $order->get_customer_id() !== $user_id ) {
				return __( 'Enter the full code of the card to add it.', 'voucher-captain' );
			}
		}
		return '';
	}

	/**
	 * Moves the remaining value of the card into the customer's store credit.
	 *
	 * @return float|\WP_Error the amount moved
	 */
	public function move( Voucher $card, int $user_id, bool $holds_code ) {
		$refusal = $this->refusal( $card, $user_id, $holds_code );
		if ( '' !== $refusal ) {
			return new \WP_Error( 'vcap_card_refused', $refusal );
		}
		$account = $this->credit->account( $user_id, true ); // Opened before the transaction (it creates a coupon).
		if ( ! $account ) {
			return new \WP_Error( 'vcap_credit_account', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
		}
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		$amount = 0.0;
		$error  = null;
		$ok     = Db::transaction(
			function () use ( $card, $user_id, $holds_code, $account, $table, $wpdb, &$amount, &$error ): bool {
				$wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE id = %d FOR UPDATE', $table, $card->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$fresh = $this->repo->get( $card->id );
				if ( ! $fresh || '' !== $this->refusal( $fresh, $user_id, $holds_code ) ) {
					$error = new \WP_Error( 'vcap_card_refused', $fresh ? $this->refusal( $fresh, $user_id, $holds_code ) : __( 'Voucher code not found.', 'voucher-captain' ) );
					return false;
				}
				$amount  = (float) $fresh->balance;
				$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET balance = 0, status = 'void', updated_at = %s WHERE id = %d AND status = 'active'", $table, $this->repo->now(), $fresh->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( 1 !== (int) $changed || ! $this->repo->log( $fresh->id, 'moved_to_credit', $amount, 0.0, Note::make( 'moved_to_credit', array( Format::money_text( $amount, $fresh->currency ), Mask::code( (string) $account->code ) ) ) ) ) {
					return false;
				}
				/* translators: %s: masked gift card code */
				$result = $this->credit->adjust( $user_id, $amount, sprintf( __( 'Gift card %s added', 'voucher-captain' ), Mask::code( (string) $fresh->code ) ) );
				if ( is_wp_error( $result ) ) {
					$error = $result;
					return false;
				}
				return true;
			},
			'card_to_credit',
			array( 'voucher_id' => $card->id, 'user_id' => $user_id )
		);
		if ( ! $ok ) {
			return $error instanceof \WP_Error ? $error : new \WP_Error( 'vcap_temporary_failure', __( 'Store credit is temporarily unavailable.', 'voucher-captain' ) );
		}
		$card->status  = Voucher::STATUS_VOID;
		$card->balance = 0.0;
		vcap()->coupons->set_coupon_enabled( $card, false );
		/** A gift card was moved into a customer's store credit (args: card Voucher, user id, amount). */
		do_action( 'vcap_card_moved_to_credit', $card, $user_id, $amount );
		return $amount;
	}

	private static function account_url(): string {
		return function_exists( 'wc_get_account_endpoint_url' ) ? (string) wc_get_account_endpoint_url( 'vouchers' ) : home_url( '/' );
	}

	/** "Add a gift card to my store credit" above the voucher list. */
	public function form(): void {
		if ( ! $this->credit->enabled() || ! is_user_logged_in() || Shortcodes::is_editor_preview() ) {
			return;
		}
		echo '<form method="post" class="vcap-card-to-credit"><p><label for="vcap-card-code">' . esc_html__( 'Add a gift card to my store credit', 'voucher-captain' ) . '</label> ';
		echo '<input type="text" id="vcap-card-code" name="vcap_card_code" autocomplete="off" required maxlength="100" /> ';
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="1" />';
		wp_nonce_field( self::ACTION, 'vcap_card_nonce' );
		echo '<button type="submit" class="' . esc_attr( Shortcodes::button_class( 'button' ) ) . '">' . esc_html__( 'Add to my store credit', 'voucher-captain' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'The remaining value is added to your store credit and the card can no longer be used on its own.', 'voucher-captain' ) . '</p></form>';
	}

	/** One-click button next to an own card in the list. */
	public function button( $voucher ): void {
		if ( ! $voucher instanceof Voucher || '' !== $this->refusal( $voucher, get_current_user_id(), false ) ) {
			return;
		}
		echo '<form method="post" class="vcap-card-to-credit-inline" style="display:inline">';
		echo '<input type="hidden" name="' . esc_attr( self::ACTION ) . '" value="1" /><input type="hidden" name="vcap_card_id" value="' . esc_attr( (string) $voucher->id ) . '" />';
		wp_nonce_field( self::ACTION, 'vcap_card_nonce' );
		echo '<button type="submit" class="' . esc_attr( Shortcodes::button_class( 'woocommerce-button button' ) ) . '">' . esc_html__( 'Add to my store credit', 'voucher-captain' ) . '</button></form> ';
	}

	public function handle(): void {
		if ( empty( $_POST[ self::ACTION ] ) || ! is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked next.
			return;
		}
		if ( ! isset( $_POST['vcap_card_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_card_nonce'] ) ), self::ACTION ) ) {
			wc_add_notice( __( 'The form has expired. Please try again.', 'voucher-captain' ), 'error' );
			return;
		}
		$user_id = get_current_user_id();
		$card    = null;
		$holds   = false;
		if ( ! empty( $_POST['vcap_card_id'] ) ) {
			$card = $this->repo->get( absint( $_POST['vcap_card_id'] ) );
		} else {
			if ( CodeLookup::begin() ) {
				wc_add_notice( CodeLookup::limited_message(), 'error' );
				return;
			}
			$raw = sanitize_text_field( wp_unslash( (string) ( $_POST['vcap_card_code'] ?? '' ) ) );
			foreach ( Tickets::code_candidates( $raw ) as $candidate ) {
				$card = $this->repo->get_by_code( $candidate );
				if ( $card ) {
					break;
				}
			}
			if ( ! $card || ! CodeLookup::visible( $card, $user_id ) ) {
				CodeLookup::failed();
				wc_add_notice( CodeLookup::not_found_message(), 'error' );
				return;
			}
			CodeLookup::found();
			$holds = true;
		}
		if ( ! $card ) {
			wc_add_notice( CodeLookup::not_found_message(), 'error' );
			return;
		}
		$moved = $this->move( $card, $user_id, $holds );
		if ( is_wp_error( $moved ) ) {
			wc_add_notice( $moved->get_error_message(), 'error' );
		} else {
			/* translators: %s: amount */
			wc_add_notice( sprintf( __( '%s was added to your store credit.', 'voucher-captain' ), Format::money_text( $moved, (string) get_option( 'woocommerce_currency' ) ) ), 'success' );
		}
		wp_safe_redirect( self::account_url() );
		exit;
	}
}
