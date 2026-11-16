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
	/** V3: Action Scheduler hook that rewards once the waiting period of the friend's order is over (args: order id). */
	public const DUE_HOOK      = 'vcap_referral_reward_due';
	/** Order meta: Unix time from which the reward is due (waiting period running). */
	public const DUE_META      = '_vcap_referral_due';
	/** Order meta: why the order earns no reward (zero_total, refunded, same_address, limit, cancelled). */
	public const DECLINED_META = '_vcap_referral_declined';
	/** Order meta: Unix time of the reward (the per-referrer limit counts these). */
	public const REWARDED_AT   = '_vcap_referral_rewarded_at';

	public function __construct( private Settings $settings, private StoreCredit $credit ) {}

	public function register(): void {
		$this->register_lifecycle();
		$this->register_features();
	}

	/** Customer screen (My Account). */
	public function register_features(): void {
		add_action( 'vcap_account_vouchers_top', array( $this, 'account_block' ), 20 );
	}

	/**
	 * Always registered: the own-code check (refuses referral codes while referrals are switched off), rewards for
	 * orders that already used a code, and their withdrawal on cancellation or refund (with retries).
	 */
	public function register_lifecycle(): void {
		add_filter( 'woocommerce_coupon_is_valid', array( $this, 'validate' ), 28, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'reward' ), 30 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'revoke' ), 30 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'revoke' ), 30 );
		add_action( self::RETRY_HOOK, array( $this, 'revoke' ) );
		add_action( self::DUE_HOOK, array( $this, 'reward_due' ) );
		// A lost scheduled action never loses a reward: the daily maintenance pays what is overdue.
		add_action( 'vcap_daily_maintenance', array( $this, 'reward_overdue' ) );
		add_filter( 'woocommerce_coupon_get_minimum_amount', array( $this, 'minimum_amount' ), 10, 2 );
	}

	/**
	 * V3: minimum order value of the friend's code. Empty setting: twice a fixed discount. A fixed discount
	 * always needs an order value above it (at least the discount plus one currency unit), so the code never
	 * makes an article free. Percentage discounts use the setting as entered.
	 */
	public function effective_min_spend(): float {
		$raw    = $this->settings->get( 'vcap_referral_min_spend' );
		$amount = (float) $this->settings->get( 'vcap_referral_friend_amount' );
		$min    = '' === trim( (string) $raw ) ? null : max( 0.0, (float) $raw );
		if ( 'percent' !== $this->settings->get( 'vcap_referral_friend_type' ) && $amount > 0 ) {
			$min = null === $min ? 2 * $amount : $min;
			$min = $min > $amount ? $min : $amount + 1;
		}
		/** Minimum order value of referral codes (V3). */
		return (float) apply_filters( 'vcap_referral_min_spend', (float) $min, $amount );
	}

	/**
	 * Referral codes (also ones created before the minimum existed) never ask for less than effective_min_spend().
	 *
	 * @param mixed $value
	 * @param mixed $coupon
	 * @return mixed
	 */
	public function minimum_amount( $value, $coupon = null ) {
		if ( ! $coupon instanceof \WC_Coupon || ! self::owner( $coupon ) ) {
			return $value;
		}
		$min = $this->effective_min_spend();
		return $min > (float) $value ? $min : $value;
	}

	/**
	 * Email reduced to its mailbox, so aliases of the same person compare equal:
	 * lower case, "+tag" removed, and for Gmail the dots of the local part removed.
	 */
	public static function normalize_email( string $email ): string {
		return CustomerEmail::normalize( $email ); // V4: shared by all per-customer keys.
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
				$code = CodeIndex::unique( array( 'prefix' => (string) apply_filters( 'vcap_referral_code_prefix', 'FRIEND' ), 'length' => 8 ) );
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
		$min    = $this->effective_min_spend();
		if ( ! $coupon->get_id() || $coupon->get_discount_type() !== $type || (float) $coupon->get_amount() !== $amount || (float) $coupon->get_minimum_amount( 'edit' ) !== $min ) {
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
		if ( ! $this->enabled() ) {
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
	 * Reward the referring customer once the friend's order is completed and paid – after the waiting period
	 * (V3: "vcap_referral_wait_days" from the payment, default 14; the reward is scheduled and dropped when the
	 * order is cancelled or refunded meanwhile), within the limit per referrer, for an order with a total above 0
	 * whose billing address is not the referrer's own.
	 *
	 * @param int|string $order_id Order id.
	 */
	public function reward( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->enabled() || $order->get_meta( self::REWARDED ) || '' !== (string) $order->get_meta( self::DECLINED_META ) || CouponManager::has_shortfall( $order ) ) {
			return;
		}
		// Cash on delivery and other offline payments count only once paid (Issuer::payment_ok()).
		if ( ! Issuer::payment_ok( $order ) ) {
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
		if ( (float) $order->get_total() - (float) $order->get_total_refunded() <= 0 ) {
			$this->decline( $order, (float) $order->get_total_refunded() > 0 ? 'refunded' : 'zero_total' );
			return;
		}
		if ( $this->settings->yes( 'vcap_referral_address_check' ) && self::same_address( $order, $owner ) ) {
			$this->decline( $order, 'same_address' );
			return;
		}
		$wait = max( 0, (int) $this->settings->get( 'vcap_referral_wait_days' ) );
		if ( $wait > 0 ) {
			$paid = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
			$at   = (int) $order->get_meta( self::DUE_META );
			$at   = $at > 0 ? $at : $paid + $wait * DAY_IN_SECONDS;
			if ( $at > time() ) {
				$this->schedule_reward( $order, $at );
				return;
			}
		}
		$max = max( 0, (int) $this->settings->get( 'vcap_referral_max_rewards' ) );
		if ( $max > 0 && self::recent_rewards( $owner, time() - 30 * DAY_IN_SECONDS, $max ) >= $max ) {
			$this->decline( $order, 'limit' );
			return;
		}
		// Claim and mark first, so neither a parallel request nor a failure later can reward twice.
		if ( ! Claim::take( 'referral:' . $order->get_id() ) ) {
			return;
		}
		$order->update_meta_data( self::REWARDED, $owner );
		$order->update_meta_data( self::REWARDED_AT, time() );
		$order->delete_meta_data( self::DUE_META );
		$order->save_meta_data();
		self::add_to_count( $owner, 1 );

		if ( 'credit' === $this->settings->get( 'vcap_referral_reward_as' ) && $this->credit->enabled() ) {
			/* translators: %s: order number */
			$result = $this->credit->adjust( $owner, $amount, sprintf( __( 'Referral reward for order #%s', 'voucher-captain' ), $order->get_order_number() ), $order->get_id(), 'credit' );
			if ( ! is_wp_error( $result ) ) {
				$order->update_meta_data( self::REWARD, array( 'type' => 'credit', 'user' => $owner, 'amount' => $amount ) );
				$order->save_meta_data();
				// The note is part of the friend's order: it names the referrer by account number only.
				/* translators: 1: amount, 2: customer account id */
				$order->add_order_note( sprintf( __( 'Referral reward: %1$s store credit for customer account #%2$d.', 'voucher-captain' ), wp_strip_all_tags( wc_price( $amount ) ), $owner ) );
				return;
			}
		}
		$coupon = $this->reward_coupon( $user, $amount, $order );
		if ( ! $coupon ) {
			return;
		}
		$order->update_meta_data( self::REWARD, array( 'type' => 'coupon', 'user' => $owner, 'coupon' => $coupon->get_id() ) );
		$order->save_meta_data();
		// The note is part of the friend's order: referrer by account number, the reward code masked.
		/* translators: 1: masked coupon code, 2: customer account id */
		$order->add_order_note( sprintf( __( 'Referral reward: coupon %1$s for customer account #%2$d.', 'voucher-captain' ), Mask::code( (string) $coupon->get_code() ), $owner ) );
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if ( isset( $emails['VCAP_Email_Referral'] ) ) {
			$emails['VCAP_Email_Referral']->trigger( $coupon, $order, __( 'Refer a friend', 'voucher-captain' ), (string) $user->user_email );
		}
	}

	/** Plans the reward for the end of the waiting period (once; order meta DUE_META keeps the time). */
	private function schedule_reward( \WC_Order $order, int $at ): void {
		if ( (int) $order->get_meta( self::DUE_META ) === $at ) {
			return;
		}
		$order->update_meta_data( self::DUE_META, $at );
		$order->save_meta_data();
		if ( function_exists( 'as_schedule_single_action' ) && ! as_has_scheduled_action( self::DUE_HOOK, array( $order->get_id() ), Delivery::GROUP ) ) {
			as_schedule_single_action( $at, self::DUE_HOOK, array( $order->get_id() ), Delivery::GROUP );
		}
		$order->add_order_note(
			sprintf(
				/* translators: %s: date */
				__( 'Referral reward planned for %s (waiting period). It is dropped if the order is cancelled or refunded before.', 'voucher-captain' ),
				wp_date( $this->settings->date_format(), $at )
			)
		);
	}

	/** The waiting period of an order is over: reward if it still qualifies (still paid, not refunded). */
	public function reward_due( $order_id ): void {
		$order = wc_get_order( $order_id );
		$at    = $order instanceof \WC_Order ? (int) $order->get_meta( self::DUE_META ) : 0;
		if ( ! $at || $at > time() + MINUTE_IN_SECONDS ) {
			return; // Nothing planned, or a stray early action (the planned one follows).
		}
		if ( ! $order->has_status( wc_get_is_paid_statuses() ) ) {
			$this->decline( $order, 'cancelled' );
			return;
		}
		$this->reward( $order->get_id() );
	}

	/** Daily: rewards whose scheduled action was lost (e.g. Action Scheduler data cleaned up). */
	public function reward_overdue(): void {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return;
		}
		$after = 0;
		for ( $page = 0; $page < 10; $page++ ) {
			$ids = OrderLookup::ids_with_meta( self::DUE_META, null, array( 'limit' => 200, 'after_id' => $after ) );
			foreach ( $ids as $id ) {
				$this->reward_due( $id );
			}
			if ( count( $ids ) < 200 ) {
				return;
			}
			$after = (int) end( $ids );
		}
	}

	/** The order earns no reward (once, with an order note naming the reason). */
	private function decline( \WC_Order $order, string $reason ): void {
		$order->update_meta_data( self::DECLINED_META, $reason );
		$order->delete_meta_data( self::DUE_META );
		$order->save_meta_data();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::DUE_HOOK, array( $order->get_id() ), Delivery::GROUP );
		}
		$reasons = array(
			'zero_total'   => __( 'the order total is 0', 'voucher-captain' ),
			'refunded'     => __( 'the order was refunded', 'voucher-captain' ),
			'cancelled'    => __( 'the order was cancelled or refunded during the waiting period', 'voucher-captain' ),
			'same_address' => __( 'the billing address is the referrer\'s own', 'voucher-captain' ),
			'limit'        => __( 'the referrer reached the limit of rewards per 30 days', 'voucher-captain' ),
		);
		/* translators: %s: reason */
		$order->add_order_note( sprintf( __( 'No referral reward: %s.', 'voucher-captain' ), $reasons[ $reason ] ?? $reason ) );
	}

	/** Rewards of a referrer since $since (counting stops at $stop). */
	public static function recent_rewards( int $owner, int $since, int $stop ): int {
		$count = 0;
		foreach ( OrderLookup::ids_with_meta( self::REWARDED, (string) $owner, array( 'limit' => 200, 'order' => 'DESC' ) ) as $id ) {
			$order = wc_get_order( $id );
			if ( $order instanceof \WC_Order && (int) $order->get_meta( self::REWARDED_AT ) >= $since && ++$count >= $stop ) {
				break;
			}
		}
		return $count;
	}

	/** Lower case letters and digits only, "straße"/"strasse" as "str" ("Hauptstraße 1" = "Hauptstr. 1" = "hauptstr1"). */
	private static function address_part( string $value ): string {
		$value = strtolower( remove_accents( str_replace( array( 'ß', 'ẞ' ), 'ss', $value ) ) );
		return str_replace( 'strasse', 'str', (string) preg_replace( '/[^a-z0-9]/', '', $value ) );
	}

	/**
	 * V3: whether the friend's order goes to the referrer's own address (street and postcode of its billing or
	 * shipping address equal to the referrer's saved billing or shipping address).
	 */
	public static function same_address( \WC_Order $order, int $owner ): bool {
		$mine = array();
		foreach ( array( 'billing', 'shipping' ) as $type ) {
			$street = self::address_part( (string) get_user_meta( $owner, $type . '_address_1', true ) );
			$zip    = self::address_part( (string) get_user_meta( $owner, $type . '_postcode', true ) );
			if ( '' !== $street && '' !== $zip ) {
				$mine[] = $street . '|' . $zip;
			}
		}
		$theirs = array(
			self::address_part( $order->get_billing_address_1() ) . '|' . self::address_part( $order->get_billing_postcode() ),
			self::address_part( $order->get_shipping_address_1() ) . '|' . self::address_part( $order->get_shipping_postcode() ),
		);
		$theirs = array_filter( $theirs, static fn( $a ) => ! str_starts_with( $a, '|' ) && ! str_ends_with( $a, '|' ) );
		return (bool) apply_filters( 'vcap_referral_same_address', (bool) array_intersect( $mine, $theirs ), $order, $owner );
	}

	/**
	 * The friend's order was cancelled or refunded after the reward: take back what is still unused –
	 * an unused reward coupon goes to the trash, store credit is debited (as far as it is still there).
	 * During the waiting period the planned reward is dropped instead.
	 */
	public function revoke( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( $order instanceof \WC_Order && (int) $order->get_meta( self::DUE_META ) && ! $order->get_meta( self::REWARDED ) ) {
			$this->decline( $order, 'cancelled' );
			return;
		}
		$reward = $order instanceof \WC_Order ? $order->get_meta( self::REWARD ) : null;
		if ( ! is_array( $reward ) || ! empty( $reward['revoked'] ) || ! Claim::take( 'referral-revoke:' . $order->get_id() ) ) {
			return;
		}
		$owner = (int) ( $reward['user'] ?? 0 );
		if ( 'coupon' === ( $reward['type'] ?? '' ) ) {
			$coupon = new \WC_Coupon( (int) ( $reward['coupon'] ?? 0 ) );
			if ( $coupon->get_id() && $coupon->get_usage_count() > 0 ) {
				/* translators: %s: coupon code */
				$order->add_order_note( sprintf( __( 'Referral reward coupon %s was already used and stays valid.', 'voucher-captain' ), Mask::code( (string) $coupon->get_code() ) ) );
			} elseif ( $coupon->get_id() ) {
				wp_trash_post( $coupon->get_id() );
				/* translators: %s: coupon code */
				$order->add_order_note( sprintf( __( 'Unused referral reward coupon %s withdrawn.', 'voucher-captain' ), Mask::code( (string) $coupon->get_code() ) ) );
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
			$code = CodeIndex::unique( array( 'prefix' => 'THANKS', 'length' => 10 ) );
		} catch ( \RuntimeException $e ) {
			return null;
		}
		$days   = (int) $this->settings->get( 'vcap_referral_reward_days' );
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( $amount );
		$coupon->set_usage_limit( 1 );
		// S6: one reward per order, never stacked with further rewards or other discount codes (multi-purpose
		// vouchers still pay the rest: CartCoupons::keep_payment_means()).
		$coupon->set_individual_use( true );
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
		$wait   = max( 0, (int) $this->settings->get( 'vcap_referral_wait_days' ) );
		$min    = (float) $coupon->get_minimum_amount();
		$reward = wp_strip_all_tags( wc_price( (float) $this->settings->get( 'vcap_referral_reward_amount' ) ) );
		$link   = $this->settings->yes( 'vcap_coupon_link' ) ? add_query_arg( (string) $this->settings->get( 'vcap_coupon_link_param' ), rawurlencode( $code ), home_url( '/' ) ) : '';
		$count  = (int) self::count( get_current_user_id() );
		$shown  = true;
		echo '<section class="vcap-referral"><h2>' . esc_html__( 'Refer a friend', 'voucher-captain' ) . '</h2><p>';
		if ( $wait > 0 ) {
			/* translators: 1: friend discount, 2: minimum order value, 3: reward, 4: number of days */
			echo esc_html( sprintf( _n( 'Your friends get %1$s off their first order from %2$s with your code, and you receive %3$s %4$d day after their order is completed and paid.', 'Your friends get %1$s off their first order from %2$s with your code, and you receive %3$s %4$d days after their order is completed and paid.', $wait, 'voucher-captain' ), $friend, wp_strip_all_tags( wc_price( $min ) ), $reward, $wait ) );
		} else {
			/* translators: 1: friend discount, 2: reward */
			echo esc_html( sprintf( __( 'Your friends get %1$s off their first order with your code, and you receive %2$s once their order is completed.', 'voucher-captain' ), $friend, $reward ) );
		}
		echo '</p><p><strong class="vcap-referral-code">' . esc_html( $code ) . '</strong>';
		if ( $link ) {
			// F16: labelled, and selected on focus by frontend.js instead of an inline handler (Content Security Policy).
			Downloads::register_assets();
			wp_enqueue_script( 'vcap-frontend' );
			echo ' · <label class="screen-reader-text" for="vcap-referral-link">' . esc_html__( 'Link to share', 'voucher-captain' ) . '</label><input type="text" readonly id="vcap-referral-link" class="input-text vcap-referral-link" value="' . esc_attr( $link ) . '" style="max-width:100%;width:28em" />';
		}
		/* translators: %d: number of completed referrals */
		echo '</p><p>' . esc_html( sprintf( _n( '%d friend has ordered so far.', '%d friends have ordered so far.', $count, 'voucher-captain' ), $count ) ) . '</p></section>';
	}
}
