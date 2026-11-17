<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-95: storefront conveniences around gift cards, each behind its own setting (all off by default):
 *  - "Gift card field" (vcap_giftcard_field): a separate "Redeem a gift card" field in the classic cart and checkout
 *    (works without JavaScript; with it, applied without a page reload through the WooCommerce AJAX endpoint
 *    "vcap_apply_giftcard") and in the cart and checkout blocks (Store API update callback, namespace
 *    "voucher-captain", called by assets/js/cart-blocks.js with extensionCartUpdate()). Store credit is applied the
 *    same way, without reloading the page.
 *  - "Balance in the mini cart" (vcap_minicart_balance): the remaining balance of applied gift cards in the classic
 *    mini cart widget.
 * Codes go through WooCommerce's own coupon validation, and the attempts count towards the code lookup limit.
 */
final class GiftCardField {
	public const FIELD  = 'vcap_giftcard_code';
	public const NONCE  = 'vcap_giftcard';
	public const AJAX   = 'vcap_apply_giftcard';
	public const SCRIPT = 'vcap-giftcard';

	public function __construct( private VoucherRepository $repo, private PaymentVouchers $payments, private StoreCredit $credit ) {}

	public static function enabled(): bool {
		return 'yes' === Settings::value( 'vcap_giftcard_field' );
	}

	public static function minicart_enabled(): bool {
		return 'yes' === Settings::value( 'vcap_minicart_balance' );
	}

	public function register(): void {
		add_action( 'woocommerce_before_cart_collaterals', array( $this, 'render' ) );
		add_action( 'woocommerce_before_checkout_form', array( $this, 'render' ), 15 );
		add_action( 'wp_loaded', array( $this, 'handle_post' ), 21 );
		add_action( 'wc_ajax_' . self::AJAX, array( $this, 'ajax' ) );
		add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'minicart_balance' ) );
	}

	/** Classic cart and checkout: the field (a form of its own, outside the cart and checkout forms). */
	public function render(): void {
		if ( ! self::enabled() || ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return;
		}
		Downloads::enqueue_style();
		self::enqueue_script();
		$id = Shortcodes::unique_id( 'vcap-giftcard' );
		echo '<form method="post" class="vcap-giftcard-form" action="">';
		wp_nonce_field( self::NONCE, '_vcap_giftcard_nonce', false );
		echo '<label for="' . esc_attr( $id ) . '">' . esc_html__( 'Redeem a gift card', 'voucher-captain' ) . '</label> ';
		echo '<input type="text" class="input-text" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::FIELD ) . '" autocomplete="off" maxlength="100" placeholder="' . esc_attr__( 'Gift card code', 'voucher-captain' ) . '" /> ';
		echo '<button type="submit" class="' . esc_attr( Shortcodes::button_class() ) . '">' . esc_html__( 'Redeem', 'voucher-captain' ) . '</button>';
		echo '<span class="vcap-giftcard-result" role="status" aria-live="polite"></span>';
		echo '</form>';
	}

	/** The script of the classic field and of "Use store credit" without reload. */
	public static function enqueue_script(): void {
		if ( ! wp_script_is( self::SCRIPT, 'registered' ) ) {
			wp_register_script( self::SCRIPT, VCAP_URL . 'assets/js/giftcard.js', array( 'jquery' ), VCAP_VERSION, true );
		}
		wp_enqueue_script( self::SCRIPT );
		wp_localize_script(
			self::SCRIPT,
			'vcapGiftcard',
			array(
				'url'   => class_exists( '\WC_AJAX' ) ? \WC_AJAX::get_endpoint( self::AJAX ) : '',
				'nonce' => wp_create_nonce( self::NONCE ),
			)
		);
	}

	/**
	 * Applies a gift card code (or, with $code '' and $credit true, the customer's store credit) to the cart.
	 * WooCommerce validates the code and adds its notices.
	 */
	public function apply( string $code, bool $credit = false ): bool {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		if ( $credit ) {
			$account = $this->credit->enabled() && is_user_logged_in() ? $this->credit->account( get_current_user_id() ) : null;
			if ( ! $account || $account->balance <= 0 ) {
				wc_add_notice( __( 'You have no store credit to use.', 'voucher-captain' ), 'error' );
				return false;
			}
			return WC()->cart->has_discount( $account->code ) || WC()->cart->apply_coupon( $account->code );
		}
		$code = wc_format_coupon_code( sanitize_text_field( $code ) );
		if ( '' === $code || strlen( $code ) > 100 ) {
			wc_add_notice( __( 'Please enter a gift card code.', 'voucher-captain' ), 'error' );
			return false;
		}
		if ( CodeLookup::begin() ) {
			wc_add_notice( CodeLookup::limited_message(), 'error' );
			return false;
		}
		if ( WC()->cart->has_discount( $code ) ) {
			CodeLookup::found();
			wc_add_notice( __( 'This gift card is already applied.', 'voucher-captain' ), 'notice' );
			return true;
		}
		$applied = WC()->cart->apply_coupon( $code );
		CodeLookup::finish( $applied || 0 !== wc_get_coupon_id_by_code( $code ) );
		return $applied;
	}

	/** The field was sent without JavaScript: apply and show the page again. */
	public function handle_post(): void {
		if ( is_admin() || ! isset( $_POST[ self::FIELD ] ) || ! is_string( $_POST[ self::FIELD ] ) || ! self::enabled() ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['_vcap_giftcard_nonce'] ?? '' ) ), self::NONCE ) ) {
			return;
		}
		$this->apply( sanitize_text_field( wp_unslash( $_POST[ self::FIELD ] ) ) );
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : wc_get_cart_url() );
		exit;
	}

	/** wc-ajax=vcap_apply_giftcard: code (or "credit": 1) → JSON with the notices; the page then refreshes its totals. */
	public function ajax(): void {
		if ( ! self::enabled() || ! check_ajax_referer( self::NONCE, 'security', false ) ) {
			wp_send_json_error( array( 'notices' => '' ), 403 );
		}
		$credit  = ! empty( $_POST['credit'] );
		$code    = isset( $_POST['code'] ) && is_string( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
		$applied = $this->apply( $code, $credit );
		WC()->cart->calculate_totals();
		$notices = wc_print_notices( true );
		if ( $applied ) {
			wp_send_json_success( array( 'notices' => $notices ) );
		}
		wp_send_json_error( array( 'notices' => $notices ) );
	}

	/**
	 * Store API update callback (cart and checkout blocks): data "action" = "apply_code" with "code", or
	 * "apply_credit". Refusals answer 400 with WooCommerce's message.
	 *
	 * @param array<string, mixed> $data Data sent by extensionCartUpdate().
	 * @throws \Exception Store API RouteException.
	 */
	public function store_api_update( $data ): void {
		$data   = is_array( $data ) ? $data : array();
		$action = (string) ( $data['action'] ?? '' );
		if ( ! self::enabled() || ! in_array( $action, array( 'apply_code', 'apply_credit' ), true ) ) {
			self::refuse( __( 'This action is not available.', 'voucher-captain' ), 403 );
		}
		wc_clear_notices();
		$code    = isset( $data['code'] ) && is_scalar( $data['code'] ) ? (string) $data['code'] : '';
		$applied = $this->apply( $code, 'apply_credit' === $action );
		$errors  = array_map( static fn( $notice ) => wp_strip_all_tags( is_array( $notice ) ? (string) ( $notice['notice'] ?? '' ) : (string) $notice ), wc_get_notices( 'error' ) );
		wc_clear_notices(); // The blocks show the result themselves; nothing waits for the next classic page.
		if ( ! $applied ) {
			self::refuse( $errors ? implode( ' ', $errors ) : __( 'This gift card could not be applied.', 'voucher-captain' ), 400 );
		}
	}

	/** @throws \Exception Store API RouteException (or a plain exception without the Store API). */
	private static function refuse( string $message, int $status ): void {
		if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_giftcard_refused', esc_html( $message ), $status ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is escaped, the third argument is the HTTP status.
		}
		throw new \Exception( esc_html( $message ) );
	}

	/** Texts of the block field (Store API cart data "giftcard_field"); null while the setting is off. */
	public static function block_texts(): ?array {
		if ( ! self::enabled() ) {
			return null;
		}
		return array(
			'label'       => __( 'Redeem a gift card', 'voucher-captain' ),
			'placeholder' => __( 'Gift card code', 'voucher-captain' ),
			'button'      => __( 'Redeem', 'voucher-captain' ),
			'applied'     => __( 'The gift card was applied.', 'voucher-captain' ),
		);
	}

	/** Mini cart widget: "Remaining balance of voucher CODE after this order: …" per applied gift card. */
	public function minicart_balance(): void {
		if ( ! self::minicart_enabled() || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		foreach ( self::balance_notes( $this->repo, $this->payments, WC()->cart ) as $note ) {
			echo '<p class="woocommerce-mini-cart__vcap-balance vcap-minicart-balance"><small>' . esc_html( $note ) . '</small></p>';
		}
	}

	/**
	 * The remaining-balance notes of the stored-balance vouchers applied to a cart (masked codes: the mini cart is
	 * shown on every page).
	 *
	 * @return string[]
	 */
	public static function balance_notes( VoucherRepository $repo, PaymentVouchers $payments, \WC_Cart $cart ): array {
		$notes = array();
		foreach ( $cart->get_applied_coupons() as $code ) {
			$voucher = $repo->get_by_code( wc_format_coupon_code( (string) $code ) );
			if ( ! $voucher || ! $voucher->is_multi_use() ) {
				continue;
			}
			$remaining = Coupons\CartCoupons::remaining_after_cart( $voucher, $cart, (string) $code, $payments->cart_payments() );
			$masked    = clone $voucher;

			$masked->code = Mask::code( $voucher->code );
			$notes[]      = Coupons\CartCoupons::remaining_balance_note( $masked, $remaining );
		}
		return $notes;
	}
}
