<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Refer a friend: every logged-in customer gets a personal code (My Account → Vouchers). A friend uses it on their
 * first order and gets a discount; once that order is completed, the referring customer is rewarded with a coupon
 * (sent by email) or store credit. Own codes cannot be used, and every order rewards at most once.
 */
final class Referrals {
	/** Scheduled retry of a reward withdrawal that failed with a booking error. */
	public const RETRY_HOOK = 'vcap_referral_revoke_retry';
	public const OWNER      = '_vcap_referral_owner';
	/**
	 * Per-site user options (user meta with the site's table prefix, update_user_option()): the referral coupon id
	 * and the number of rewarded referrals belong to one site – coupon ids of one site mean another coupon (or
	 * nothing) on the other sites of a network. Earlier versions stored them network-wide under the plain key; such
	 * values are adopted once by the main site (the only site of a single-site install), after a check.
	 */
	public const USER_META  = '_vcap_referral_coupon';
	public const COUNT_META = '_vcap_referral_count';
	public const REWARDED   = '_vcap_referral_rewarded';
	public const REWARD     = '_vcap_referral_reward';

	public function __construct( private Settings $settings, private StoreCredit $credit ) {}

	public function register(): void {
		$this->register_lifecycle();
		$this->register_features();
	}

	/** Customer screen (My Account) – only with the premium right "referrals". */
	public function register_features(): void {
		add_action( 'vcap_account_vouchers_top', array( $this, 'account_block' ), 20 );
	}

	/**
	 * Always registered: the own-code check (refuses referral codes while the right is locked), rewards for orders
	 * that already used a code, and their withdrawal on cancellation or refund (with retries).
	 */
	public function register_lifecycle(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 28, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'reward' ), 30 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'revoke' ), 30 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revoke' ), 30 );
		add_action( self::RETRY_HOOK, array( $this, 'revoke' ) );
	}

	/**
	 * Email reduced to its mailbox, so aliases of the same person compare equal:
	 * lower case, "+tag" removed, and for Gmail the dots of the local part removed.
	 */
	public static function normalize_email( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( ! str_contains( $email, '@' ) ) {
			return $email;
		}
		[ $local, $domain ] = explode( '@', $email, 2 );
		$local = explode( '+', $local, 2 )[0];
		if ( in_array( $domain, array( 'gmail.com', 'googlemail.com' ), true ) ) {
			$local  = str_replace( '.', '', $local );
			$domain = 'gmail.com';
		}
		return $local . '@' . $domain;
	}

	/** Whether the customer (account or email) is the owner of the referral code. */
	public static function is_owner( int $owner_id, int $user_id, string $email ): bool {
		if ( $owner_id <= 0 ) {
			return false;
		}
		if ( $user_id === $owner_id ) {
			return true;
		}
		$owner = get_userdata( $owner_id );
		if ( ! $owner || '' === trim( $email ) ) {
			return false;
		}
		$mailbox = self::normalize_email( $email );
		$own     = array( self::normalize_email( (string) $owner->user_email ), self::normalize_email( (string) get_user_meta( $owner_id, 'billing_email', true ) ) );
		return in_array( $mailbox, array_filter( $own ), true );
	}

	public function enabled(): bool {
		return $this->settings->yes( 'vcap_referral_enabled' );
	}

	/** The site's user meta key of a per-site referral value (what update_user_option() writes on this site). */
	public static function site_key( string $key ): string {
		global $wpdb;
		return $wpdb->get_blog_prefix() . $key;
	}

	/**
	 * Whether this site takes over the network-wide values of earlier versions: the main site of a network, or
	 * a single site (where they are simply moved to the per-site key).
	 */
	private static function adopts_legacy(): bool {
		return ! is_multisite() || is_main_site();
	}

	/**
	 * Whether the coupon is the customer's referral coupon on this site: a coupon of this site, not in the trash,
	 * created as the referral code of exactly this customer. Anything else is never used or changed as such.
	 */
	public static function is_referral_coupon_of( int $coupon_id, int $user_id ): bool {
		return $coupon_id > 0 && $user_id > 0
			&& 'shop_coupon' === get_post_type( $coupon_id )
			&& 'trash' !== get_post_status( $coupon_id )
			&& (int) get_post_meta( $coupon_id, self::OWNER, true ) === $user_id;
	}

	/**
	 * Id of the customer's referral coupon on this site, 0 for none. The stored id is only returned after
	 * is_referral_coupon_of(). On the main site (single site) a value of an earlier version stored network-wide is
	 * adopted when it passes the same check (and, with $migrate, moved to the per-site key).
	 */
	public static function coupon_id( int $user_id, bool $migrate = true ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		$id = (int) get_user_meta( $user_id, self::site_key( self::USER_META ), true );
		if ( $id ) {
			return self::is_referral_coupon_of( $id, $user_id ) ? $id : 0;
		}
		if ( ! self::adopts_legacy() ) {
			return 0;
		}
		$legacy = (int) get_user_meta( $user_id, self::USER_META, true );
		if ( ! $legacy || ! self::is_referral_coupon_of( $legacy, $user_id ) ) {
			return 0;
		}
		if ( $migrate ) {
			update_user_option( $user_id, self::USER_META, $legacy );
			delete_user_meta( $user_id, self::USER_META );
		}
		return $legacy;
	}

	/**
	 * Number of friends whose order rewarded the customer on this site; null when nothing was ever counted. On the
	 * main site (single site) the network-wide counter of an earlier version is adopted (and, with $migrate, moved).
	 */
	public static function count( int $user_id, bool $migrate = true ): ?int {
		if ( $user_id <= 0 ) {
			return null;
		}
		$raw = get_user_meta( $user_id, self::site_key( self::COUNT_META ), true );
		if ( '' === (string) $raw && self::adopts_legacy() ) {
			$raw = get_user_meta( $user_id, self::COUNT_META, true );
			if ( '' !== (string) $raw && $migrate ) {
				update_user_option( $user_id, self::COUNT_META, max( 0, (int) $raw ) );
				delete_user_meta( $user_id, self::COUNT_META );
			}
		}
		return '' === (string) $raw ? null : max( 0, (int) $raw );
	}

	/** Changes the customer's referral counter on this site by $delta (never below 0). */
	private static function add_to_count( int $user_id, int $delta ): void {
		update_user_option( $user_id, self::COUNT_META, max( 0, (int) self::count( $user_id ) + $delta ) );
	}

	/**
	 * The customer's referral coupon on this site (created on demand); its value follows the current settings.
	 * Only a coupon that passes is_referral_coupon_of() is reused and updated – a stored id that names another
	 * coupon (another site's id, a deleted or foreign coupon) is ignored and a new referral coupon is created.
	 */
	public function coupon_for( int $user_id, bool $create = true ): ?\WC_Coupon {
		if ( $user_id <= 0 ) {
			return null;
		}
		$id     = self::coupon_id( $user_id );
		$coupon = $id ? new \WC_Coupon( $id ) : null;
		$coupon = $coupon && $coupon->get_id() ? $coupon : null;
		if ( ! $coupon && ! $create ) {
			return null;
		}
		if ( ! $coupon ) {
			// Two requests at the same time (two tabs, a block and the account page) would create two codes: only
			// the request holding the claim creates one (the claim is per 5 minutes, so a crashed request does not
			// block the customer for good). The others show no code this time.
			$claim = 'referral-code:' . $user_id . ':' . (int) floor( time() / 300 );
			if ( ! Claim::take( $claim ) ) {
				return null;
			}
			$id = self::coupon_id( $user_id ); // Created by another request meanwhile?
			if ( $id ) {
				Claim::release( $claim );
				$coupon = new \WC_Coupon( $id );
				return $coupon->get_id() ? $coupon : null;
			}
			try {
				$code = CodeGenerator::unique( array( 'prefix' => (string) apply_filters( 'vcap_referral_code_prefix', 'FRIEND' ), 'length' => 8 ), static fn( $c ) => wc_get_coupon_id_by_code( $c ) > 0 );
			} catch ( \RuntimeException $e ) {
				Claim::release( $claim );
				return null;
			}
			$coupon = new \WC_Coupon();
			$coupon->set_code( $code );
			$coupon->update_meta_data( self::OWNER, $user_id );
			$coupon->update_meta_data( FirstOrderCoupons::META, 'yes' );
			/* translators: %d: user id */
			$coupon->set_description( sprintf( __( 'Refer a friend – code of customer #%d', 'voucher-captain' ), $user_id ) );
		}
		$type   = 'percent' === $this->settings->get( 'vcap_referral_friend_type' ) ? 'percent' : 'fixed_cart';
		$amount = (float) $this->settings->get( 'vcap_referral_friend_amount' );
		$min    = (float) $this->settings->get( 'vcap_referral_min_spend' );
		if ( ! $coupon->get_id() || $coupon->get_discount_type() !== $type || (float) $coupon->get_amount() !== $amount || (float) $coupon->get_minimum_amount() !== $min ) {
			$coupon->set_discount_type( $type );
			$coupon->set_amount( $amount );
			$coupon->set_minimum_amount( $min > 0 ? $min : '' );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->save();
			if ( $coupon->get_id() ) {
				update_user_option( $user_id, self::USER_META, $coupon->get_id() );
			}
		}
		if ( isset( $claim ) ) {
			Claim::release( $claim );
		}
		return $coupon;
	}

	public static function owner( \WC_Coupon $coupon ): int {
		return (int) $coupon->get_meta( self::OWNER );
	}

	public function validate( $valid, $coupon, $discounts = null ) {
		if ( ! $valid || ! $coupon instanceof \WC_Coupon || ! self::owner( $coupon ) ) {
			return $valid;
		}
		if ( ! $this->enabled() || ! Licensing\Entitlements::allows( 'referrals' ) ) {
			throw new \Exception( esc_html__( 'Referral codes are currently not available.', 'voucher-captain' ), 9060 );
		}
		$object = $discounts instanceof \WC_Discounts ? $discounts->get_object() : null;
		if ( $object instanceof \WC_Order ) {
			$user  = (int) $object->get_customer_id();
			$email = (string) $object->get_billing_email();
		} else {
			$user  = get_current_user_id();
			$email = WC()->customer ? (string) WC()->customer->get_billing_email() : '';
		}
		if ( self::is_owner( self::owner( $coupon ), $user, $email ) ) {
			throw new \Exception( esc_html__( 'You cannot use your own referral code.', 'voucher-captain' ), 9061 );
		}
		return $valid;
	}

	/**
	 * Reward the referring customer once the friend's order is completed.
	 */
	public function reward( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->enabled() || $order->get_meta( self::REWARDED ) || CouponManager::has_shortfall( $order ) ) {
			return;
		}
		$owner = 0;
		foreach ( $order->get_coupon_codes() as $code ) {
			$id = wc_get_coupon_id_by_code( $code );
			if ( $id && (int) get_post_meta( $id, self::OWNER, true ) ) {
				$owner = (int) get_post_meta( $id, self::OWNER, true );
				break;
			}
		}
		$user = $owner ? get_userdata( $owner ) : null;
		if ( ! $user || self::is_owner( $owner, (int) $order->get_customer_id(), (string) $order->get_billing_email() ) ) {
			return;
		}
		$amount = (float) $this->settings->get( 'vcap_referral_reward_amount' );
		if ( $amount <= 0 ) {
			return;
		}
		// Claim and mark first, so neither a parallel request nor a failure later can reward twice.
		if ( ! Claim::take( 'referral:' . $order->get_id() ) ) {
			return;
		}
		$order->update_meta_data( self::REWARDED, $owner );
		$order->save_meta_data();
		self::add_to_count( $owner, 1 );

		if ( 'credit' === $this->settings->get( 'vcap_referral_reward_as' ) && $this->credit->enabled() ) {
			/* translators: %s: order number */
			$result = $this->credit->adjust( $owner, $amount, sprintf( __( 'Referral reward for order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id(), 'credit' );
			if ( ! is_wp_error( $result ) ) {
				$order->update_meta_data( self::REWARD, array( 'type' => 'credit', 'user' => $owner, 'amount' => $amount ) );
				$order->save_meta_data();
				/* translators: 1: amount, 2: customer email */
				$order->add_order_note( sprintf( __( 'Referral reward: %1$s store credit for %2$s.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ), $user->user_email ) );
				return;
			}
		}
		$coupon = $this->reward_coupon( $user, $amount, $order );
		if ( ! $coupon ) {
			return;
		}
		$order->update_meta_data( self::REWARD, array( 'type' => 'coupon', 'user' => $owner, 'coupon' => $coupon->get_id() ) );
		$order->save_meta_data();
		/* translators: 1: coupon code, 2: customer email */
		$order->add_order_note( sprintf( __( 'Referral reward: coupon %1$s for %2$s.', 'voucher-captain' ), $coupon->get_code(), $user->user_email ) );
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if ( isset( $emails['VCAP_Email_Referral'] ) ) {
			$emails['VCAP_Email_Referral']->trigger( $coupon, $order, __( 'Refer a friend', 'voucher-captain' ), (string) $user->user_email );
		}
	}

	/**
	 * The friend's order was cancelled or refunded after the reward: take back what is still unused –
	 * an unused reward coupon goes to the trash, store credit is debited (as far as it is still there).
	 */
	public function revoke( $order_id ): void {
		$order  = wc_get_order( $order_id );
		$reward = $order instanceof \WC_Order ? $order->get_meta( self::REWARD ) : null;
		if ( ! is_array( $reward ) || ! empty( $reward['revoked'] ) || ! Claim::take( 'referral-revoke:' . $order->get_id() ) ) {
			return;
		}
		$owner = (int) ( $reward['user'] ?? 0 );
		if ( 'coupon' === ( $reward['type'] ?? '' ) ) {
			$coupon = new \WC_Coupon( (int) ( $reward['coupon'] ?? 0 ) );
			if ( $coupon->get_id() && $coupon->get_usage_count() > 0 ) {
				/* translators: %s: coupon code */
				$order->add_order_note( sprintf( __( 'Referral reward coupon %s was already used and stays valid.', 'voucher-captain' ), $coupon->get_code() ) );
			} elseif ( $coupon->get_id() ) {
				wp_trash_post( $coupon->get_id() );
				/* translators: %s: coupon code */
				$order->add_order_note( sprintf( __( 'Unused referral reward coupon %s withdrawn.', 'voucher-captain' ), $coupon->get_code() ) );
			}
		} elseif ( 'credit' === ( $reward['type'] ?? '' ) ) {
			$account = $this->credit->account( $owner, false );
			$amount  = min( (float) ( $reward['amount'] ?? 0 ), $account ? (float) $account->balance : 0.0 );
			/* translators: %s: order number */
			$result = $amount > 0 ? $this->credit->adjust( $owner, -$amount, sprintf( __( 'Referral reward withdrawn (order #%s cancelled)', 'voucher-captain' ), $order->get_order_number() ), $order->get_id(), 'debit' ) : null;
			if ( is_wp_error( $result ) && 'vcap_insufficient_balance' !== $result->get_error_code() ) {
				// A booking error, not a spent reward: nothing is finished – the withdrawal is tried again later.
				Claim::release( 'referral-revoke:' . $order->get_id() );
				$attempts = (int) ( $reward['revoke_attempts'] ?? 0 ) + 1;
				$reward['revoke_attempts'] = $attempts;
				$order->update_meta_data( self::REWARD, $reward );
				$order->save_meta_data();
				if ( $attempts < 5 && function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( time() + $attempts * HOUR_IN_SECONDS, self::RETRY_HOOK, array( $order->get_id() ), Delivery::GROUP );
				}
				/* translators: %s: error message */
				$order->add_order_note( sprintf( __( 'Referral reward could not be withdrawn yet (%s); it is tried again automatically.', 'voucher-captain' ), $result->get_error_message() ) );
				return;
			}
			if ( $result && ! is_wp_error( $result ) ) {
				$partly = $amount + 0.0001 < (float) ( $reward['amount'] ?? 0 );
				$order->add_order_note(
					$partly
						/* translators: %s: amount */
						? sprintf( __( 'Referral reward partly withdrawn: %s store credit debited, the rest was already spent.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ) )
						/* translators: %s: amount */
						: sprintf( __( 'Referral reward withdrawn: %s store credit debited.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ) )
				);
			} else {
				$order->add_order_note( __( 'Referral reward store credit was already spent and could not be withdrawn.', 'voucher-captain' ) );
			}
		}
		if ( $owner ) {
			self::add_to_count( $owner, -1 );
		}
		$reward['revoked'] = time();
		$order->update_meta_data( self::REWARD, $reward );
		$order->save_meta_data();
	}

	private function reward_coupon( \WP_User $user, float $amount, \WC_Order $order ): ?\WC_Coupon {
		try {
			$code = CodeGenerator::unique( array( 'prefix' => 'THANKS', 'length' => 10 ), static fn( $c ) => wc_get_coupon_id_by_code( $c ) > 0 );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$days   = (int) $this->settings->get( 'vcap_referral_reward_days' );
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( 1 );
		$coupon->set_email_restrictions( array( strtolower( (string) $user->user_email ) ) );
		if ( $days > 0 ) {
			$coupon->set_date_expires( time() + $days * DAY_IN_SECONDS );
		}
		/* translators: %s: order number */
		$coupon->set_description( sprintf( __( 'Referral reward for order #%s', 'voucher-captain' ), $order->get_order_number() ) );
		$coupon->update_meta_data( '_vcap_referral_reward_for', $order->get_id() );
		return $coupon->save() ? $coupon : null;
	}

	public function account_block(): void {
		static $shown = false;
		// Once per page (e.g. "My vouchers" and "Refer a friend" blocks on one page); never in an editor's
		// preview, which would create a referral code for the editor.
		if ( $shown || ! $this->enabled() || ! is_user_logged_in() || Shortcodes::is_editor_preview() ) {
			return;
		}
		$coupon = $this->coupon_for( get_current_user_id() );
		if ( ! $coupon ) {
			return;
		}
		$code   = strtoupper( $coupon->get_code() );
		$friend = 'percent' === $coupon->get_discount_type() ? Format::percent( $coupon->get_amount() ) : wp_strip_all_tags( wc_price( (float) $coupon->get_amount() ) );
		$reward = wp_strip_all_tags( wc_price( (float) $this->settings->get( 'vcap_referral_reward_amount' ) ) );
		$link   = $this->settings->yes( 'vcap_coupon_link' ) ? add_query_arg( (string) $this->settings->get( 'vcap_coupon_link_param' ), rawurlencode( $code ), home_url( '/' ) ) : '';
		$count  = (int) self::count( get_current_user_id() );
		$shown  = true;
		echo '<section class="vcap-referral"><h2>' . esc_html__( 'Refer a friend', 'voucher-captain' ) . '</h2><p>';
		/* translators: 1: friend discount, 2: reward */
		echo esc_html( sprintf( __( 'Your friends get %1$s off their first order with your code, and you receive %2$s once their order is completed.', 'voucher-captain' ), $friend, $reward ) );
		echo '</p><p><strong class="vcap-referral-code">' . esc_html( $code ) . '</strong>';
		if ( $link ) {
			echo ' · <input type="text" readonly class="input-text" value="' . esc_attr( $link ) . '" onfocus="this.select()" aria-label="' . esc_attr__( 'Link to share', 'voucher-captain' ) . '" style="max-width:100%;width:28em" />';
		}
		/* translators: %d: number of completed referrals */
		echo '</p><p>' . esc_html( sprintf( _n( '%d friend has ordered so far.', '%d friends have ordered so far.', $count, 'voucher-captain' ), $count ) ) . '</p></section>';
	}
}
