<?php
namespace VCAP\Discounts;

use VCAP\CodeIndex;

defined( 'ABSPATH' ) || exit;

/**
 * "Bonus voucher" promotions: after a qualifying order is paid, the customer receives a new single-use coupon
 * (restricted to their billing email) by email. Rules (products, categories, customer group, minimum value, period,
 * usage limits) are the same as for cart promotions. Cancelled or refunded orders take back an unused bonus.
 *
 * V5: the bonus becomes valid only after "vcap_bonus_wait_days" (default 14): it is created as a draft coupon
 * (WooCommerce does not find drafts, and coupons have no start date) and published and mailed by a scheduled
 * action (with a daily sweep as fallback). A partial refund below the promotion's threshold withdraws an unused
 * bonus; a refund that would do so while the bonus is already used is reduced by the used bonus (guard_refund()).
 */
final class Bonus {
	public const ORDER_META    = '_vcap_bonus_coupons';
	public const COUPON_ORDER  = '_vcap_bonus_order';
	public const COUPON_SOURCE = '_vcap_bonus_campaign';
	/** Coupon meta: Unix time from which a bonus is valid (it stays a draft until then). */
	public const VALID_FROM    = '_vcap_bonus_valid_from';
	/** Action Scheduler hook that publishes and sends a bonus once it is valid (args: coupon id). */
	public const ACTIVATE_HOOK = 'vcap_bonus_activate';
	/** Order meta: campaigns whose bonus was withdrawn. */
	public const WITHDRAWN     = '_vcap_bonus_withdrawn';

	public function __construct( private Repository $campaigns, private CartIntegration $cart ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'issue' ), 25, 3 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue' ), 25, 3 );
		// Offline payments (cash on delivery, bank transfer, …) count once recorded; see Issuer::payment_ok().
		add_action( 'woocommerce_payment_complete', array( $this, 'issue' ), 25, 1 );
		add_action( 'woocommerce_payment_complete_order_status_processing', array( $this, 'issue' ), 25, 1 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'withdraw' ), 25 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'withdraw' ), 25 );
		add_action( 'woocommerce_order_partially_refunded', array( $this, 'withdraw_below_threshold' ), 25, 1 );
		add_action( 'woocommerce_create_refund', array( $this, 'guard_refund' ), 12, 2 );
		add_action( self::ACTIVATE_HOOK, array( $this, 'activate' ) );
		add_action( 'vcap_daily_maintenance', array( $this, 'activate_overdue' ) );
	}

	/** V5: days after the payment until a bonus is valid (0 = at once). */
	public static function wait_days(): int {
		return max( 0, (int) \VCAP\Settings::value( 'vcap_bonus_wait_days' ) );
	}

	/**
	 * Lines of an order in Engine format, at the price actually paid: discount codes and promotions are
	 * deducted (a 50 % code must not unlock a "spend 100" bonus with 50 paid), gift cards and store credit are
	 * not (they are a means of payment, not a discount).
	 */
	public static function order_lines( \WC_Order $order ): array {
		$all      = 0.0;
		$discount = 0.0;
		foreach ( $order->get_items( 'coupon' ) as $coupon_item ) {
			$amount = (float) $coupon_item->get_discount() + ( wc_prices_include_tax() ? (float) $coupon_item->get_discount_tax() : 0.0 );
			$all   += $amount;
			$id     = wc_get_coupon_id_by_code( $coupon_item->get_code() );
			if ( ! $id || 'yes' !== get_post_meta( $id, '_vcap_voucher', true ) ) {
				$discount += $amount;
			}
		}
		$share = $all > 0 ? min( 1.0, $discount / $all ) : 1.0;
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || ! $item->get_product() || $item->get_quantity() <= 0 ) {
				continue;
			}
			$tax      = wc_prices_include_tax();
			$subtotal = (float) $item->get_subtotal() + ( $tax ? (float) $item->get_subtotal_tax() : 0.0 );
			$total    = (float) $item->get_total() + ( $tax ? (float) $item->get_total_tax() : 0.0 );
			$unit     = max( 0.0, $subtotal - ( $subtotal - $total ) * $share ) / $item->get_quantity();
			$lines[] = CartIntegration::line( (string) $item_id, $item->get_product(), (int) $item->get_quantity(), $unit );
		}
		return $lines;
	}

	/** Issued bonus coupons of an order: campaign id => coupon id. */
	public static function issued( \WC_Order $order ): array {
		$map = $order->get_meta( self::ORDER_META );
		return is_array( $map ) ? array_map( 'intval', $map ) : array();
	}

	/**
	 * Issues the bonus vouchers of a paid order. Paid follows the voucher rule (Issuer::payment_ok()): cash on
	 * delivery and other offline payments only once the order is completed, the payment is recorded or a paid
	 * status was set by hand – a bonus is worth money as well.
	 *
	 * @param int|string     $order_id   Order id.
	 * @param \WC_Order|null $order      Order (status hooks).
	 * @param array          $transition Status transition (status hooks).
	 * @return array<int, int> campaign id => new coupon id
	 */
	public function issue( $order_id, $order = null, $transition = array() ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->cart->enabled() || $order->get_parent_id() ) {
			return array();
		}
		if ( ! \VCAP\Issuer::payment_ok( $order, $transition ) ) {
			return array(); // Follows when the payment is confirmed (completed, payment recorded, set by hand).
		}
		if ( \VCAP\CouponManager::has_shortfall( $order ) ) {
			return array(); // Gift card not charged yet: bonus follows when the order is fulfilled after the review.
		}
		$issued  = self::issued( $order );
		$lines   = self::order_lines( $order );
		$context = $this->cart->context( $order );
		$created = array();
		foreach ( $this->campaigns->active() as $campaign ) {
			$id = (int) $campaign['id'];
			if ( 'bonus' !== $campaign['rules']['action'] || isset( $issued[ $id ] ) ) {
				continue;
			}
			if ( '' !== Engine::bonus_blocker( $campaign['rules'], $lines, $context ) ) {
				continue;
			}
			if ( '' !== Usage::blocker( $campaign, (int) $order->get_customer_id(), (string) $order->get_billing_email(), true ) ) {
				continue;
			}
			// Processing and completed can fire in parallel requests: only one may issue the bonus.
			if ( ! \VCAP\Claim::take( 'bonus:' . $order->get_id() . ':' . $id ) ) {
				continue;
			}
			$coupon_id = $this->create_coupon( $campaign, $order );
			if ( ! $coupon_id ) {
				\VCAP\Claim::release( 'bonus:' . $order->get_id() . ':' . $id );
				continue;
			}
			Usage::add( $id, Usage::order_key( $order ), (int) $order->get_id() );
			$issued[ $id ]  = $coupon_id;
			$created[ $id ] = $coupon_id;
			// Save before mailing, so a mail error can never lead to a second coupon.
			$order->update_meta_data( self::ORDER_META, $issued );
			$order->save_meta_data();
			$coupon = new \WC_Coupon( $coupon_id );
			$from   = (int) $coupon->get_meta( self::VALID_FROM );
			if ( $from > time() ) {
				if ( function_exists( 'as_schedule_single_action' ) ) {
					as_schedule_single_action( $from, self::ACTIVATE_HOOK, array( $coupon_id ), \VCAP\Delivery::GROUP );
				}
				/* translators: 1: masked coupon code (last four characters), 2: promotion title, 3: date */
				$order->add_order_note( sprintf( __( 'Bonus voucher %1$s created (promotion "%2$s"); it is sent and valid from %3$s unless the order is cancelled or refunded before.', 'voucher-captain' ), \VCAP\Downloads::masked_code( (string) $coupon->get_code() ), $campaign['title'], wp_date( vcap()->settings->date_format(), $from ) ) );
				continue;
			}
			/* translators: 1: masked coupon code (last four characters), 2: promotion title */
			$order->add_order_note( sprintf( __( 'Bonus voucher %1$s issued (promotion "%2$s").', 'voucher-captain' ), \VCAP\Downloads::masked_code( (string) $coupon->get_code() ), $campaign['title'] ) );
			$this->send( $coupon, $order, $campaign );
		}
		return $created;
	}

	/**
	 * V5: a waiting bonus becomes valid – published (WooCommerce finds it from now on) and sent – if its order is
	 * still paid and the bonus was not withdrawn meanwhile.
	 *
	 * @param int|string $coupon_id Coupon id.
	 */
	public function activate( $coupon_id ): bool {
		$coupon_id = (int) $coupon_id;
		$from      = (int) get_post_meta( $coupon_id, self::VALID_FROM, true );
		if ( ! $from || 'shop_coupon' !== get_post_type( $coupon_id ) || 'draft' !== get_post_status( $coupon_id ) || $from > time() + MINUTE_IN_SECONDS ) {
			return false;
		}
		$order       = wc_get_order( (int) get_post_meta( $coupon_id, self::COUPON_ORDER, true ) );
		$campaign_id = (int) get_post_meta( $coupon_id, self::COUPON_SOURCE, true );
		if ( ! $order instanceof \WC_Order || ! in_array( $coupon_id, self::issued( $order ), true ) ) {
			return false;
		}
		if ( ! $order->has_status( wc_get_is_paid_statuses() ) || in_array( $campaign_id, array_map( 'intval', (array) $order->get_meta( self::WITHDRAWN ) ), true ) ) {
			$this->withdraw( $order->get_id() ); // Cancelled, refunded or failed meanwhile: never valid.
			return false;
		}
		if ( ! \VCAP\Claim::take( 'bonus-activate:' . $coupon_id ) ) {
			return false;
		}
		$updated = wp_update_post( array( 'ID' => $coupon_id, 'post_status' => 'publish' ), true );
		if ( is_wp_error( $updated ) || ! $updated ) {
			\VCAP\Claim::release( 'bonus-activate:' . $coupon_id );
			return false;
		}
		$coupon = new \WC_Coupon( $coupon_id );
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . wc_format_coupon_code( (string) $coupon->get_code() ), 'coupons' );
		}
		$campaign = $this->campaigns->get_any( $campaign_id );
		$title    = $campaign ? (string) $campaign['title'] : '';
		/* translators: 1: masked coupon code (last four characters), 2: promotion title */
		$order->add_order_note( sprintf( __( 'Bonus voucher %1$s issued (promotion "%2$s").', 'voucher-captain' ), \VCAP\Downloads::masked_code( (string) $coupon->get_code() ), $title ) );
		$this->send( $coupon, $order, array( 'title' => $title ) );
		return true;
	}

	/** Daily: bonus vouchers whose scheduled activation was lost (at most 100 per run). */
	public function activate_overdue(): void {
		$ids = get_posts(
			array(
				'post_type'   => 'shop_coupon',
				'post_status' => 'draft',
				'numberposts' => 100,
				'fields'      => 'ids',
				'meta_query'  => array( array( 'key' => self::VALID_FROM, 'value' => time(), 'compare' => '<=', 'type' => 'NUMERIC' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		foreach ( $ids as $id ) {
			$this->activate( (int) $id );
		}
	}

	/**
	 * Share (0–1) of the order still paid after its refunds, plus $extra being refunded now.
	 */
	private static function kept_share( \WC_Order $order, float $extra = 0.0, ?float $refunded = null ): float {
		$total = (float) $order->get_total();
		if ( $total <= 0 ) {
			return 1.0;
		}
		$refunded = null === $refunded ? (float) $order->get_total_refunded() : $refunded;
		return max( 0.0, min( 1.0, ( $total - $refunded - $extra ) / $total ) );
	}

	/**
	 * Whether the bonus of a campaign still qualifies when only $share of the order is kept (lines scaled).
	 */
	private function qualifies( \WC_Order $order, int $campaign_id, float $share ): bool {
		if ( $share <= 0.0 ) {
			return false;
		}
		$campaign = $this->campaigns->get_any( $campaign_id );
		if ( ! $campaign ) {
			return $share >= 1.0;
		}
		$lines = array_map(
			static function ( array $line ) use ( $share ) {
				$line['unit'] = (float) $line['unit'] * $share;
				return $line;
			},
			self::order_lines( $order )
		);
		return '' === Engine::bonus_blocker( $campaign['rules'], $lines, $this->cart->context( $order ) );
	}

	/** Value a used bonus coupon took off (its discounts; a fixed bonus at most its amount). */
	public static function used_value( \WC_Coupon $coupon ): float {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_order_coupon_lookup';
		$sum    = null;
		if ( $lookup === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $lookup ) ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$sum = $wpdb->get_var( $wpdb->prepare( "SELECT SUM(discount_amount) FROM {$lookup} WHERE coupon_id = %d", $coupon->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		if ( 'percent' === $coupon->get_discount_type() ) {
			return max( 0.0, (float) $sum );
		}
		return null !== $sum && (float) $sum > 0 ? min( (float) $coupon->get_amount(), (float) $sum ) : (float) $coupon->get_amount();
	}

	/** Bonus coupons of the order that are not withdrawn: campaign id => coupon. */
	private static function live_bonuses( \WC_Order $order ): array {
		$withdrawn = array_map( 'intval', (array) $order->get_meta( self::WITHDRAWN ) );
		$out       = array();
		foreach ( self::issued( $order ) as $campaign_id => $coupon_id ) {
			$coupon = new \WC_Coupon( $coupon_id );
			if ( ! in_array( (int) $campaign_id, $withdrawn, true ) && $coupon->get_id() && 'trash' !== get_post_status( $coupon_id ) ) {
				$out[ (int) $campaign_id ] = $coupon;
			}
		}
		return $out;
	}

	/**
	 * V5 refund guard (like the top-up guard): a refund that takes the order below a promotion's threshold while
	 * its bonus is already used is refused above "order rest minus used bonus" – the customer would keep the
	 * bonus for a purchase they gave back. Filter "vcap_allow_refund_of_used_bonus" (default false) allows it.
	 *
	 * @param \WC_Order_Refund $refund Refund being created (not saved yet).
	 * @param array            $args   wc_create_refund() arguments.
	 */
	public function guard_refund( $refund, $args ): void {
		$order = wc_get_order( (int) ( $args['order_id'] ?? ( $refund instanceof \WC_Order_Refund ? $refund->get_parent_id() : 0 ) ) );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$amount = (float) ( $args['amount'] ?? 0 );
		// wc_create_refund() may already have saved this refund before the hook (tax recalculation; always on the
		// classic order store), so only earlier refunds count as refunded – like the voucher purchase guard.
		$current_id = $refund instanceof \WC_Order_Refund ? (int) $refund->get_id() : 0;
		$refunded   = 0.0;
		foreach ( wc_get_orders( array( 'type' => 'shop_order_refund', 'parent' => $order->get_id(), 'limit' => -1 ) ) as $earlier ) {
			if ( (int) $earlier->get_id() !== $current_id ) {
				$refunded += abs( (float) $earlier->get_amount() );
			}
		}
		$share  = self::kept_share( $order, $amount, $refunded );
		$used   = 0.0;
		$codes  = array();
		foreach ( self::live_bonuses( $order ) as $campaign_id => $coupon ) {
			if ( $coupon->get_usage_count() > 0 && ! $this->qualifies( $order, $campaign_id, $share ) ) {
				$used   += self::used_value( $coupon );
				$codes[] = \VCAP\Downloads::masked_code( (string) $coupon->get_code() );
			}
		}
		$max = max( 0.0, round( (float) $order->get_total() - $refunded - $used, wc_get_price_decimals() ) );
		if ( ! $codes || $amount <= $max + 0.00001 || apply_filters( 'vcap_allow_refund_of_used_bonus', false, $order, $amount, $used ) ) {
			return;
		}
		throw new \Exception(
			sprintf(
				/* translators: 1: masked bonus codes, 2: maximum refund */
				esc_html__( 'The bonus voucher of this order was already used (%1$s); the order would no longer qualify for it. At most %2$s can be refunded now.', 'voucher-captain' ),
				esc_html( implode( ', ', $codes ) ),
				esc_html( html_entity_decode( wp_strip_all_tags( wc_price( $max, array( 'currency' => $order->get_currency() ) ) ) ) )
			)
		);
	}

	/** V5: a partial refund below a promotion's threshold withdraws its unused bonus. */
	public function withdraw_below_threshold( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$share = self::kept_share( $order );
		$drop  = array();
		foreach ( self::live_bonuses( $order ) as $campaign_id => $coupon ) {
			if ( 0 === $coupon->get_usage_count() && ! $this->qualifies( $order, $campaign_id, $share ) ) {
				$drop[] = $campaign_id;
			}
		}
		if ( $drop ) {
			$this->withdraw( $order->get_id(), $drop );
		}
	}

	private function create_coupon( array $campaign, \WC_Order $order ): int {
		$r = $campaign['rules'];
		try {
			$code = CodeIndex::unique(
				array(
					'prefix' => (string) apply_filters( 'vcap_bonus_code_prefix', 'BONUS', $campaign, $order ),
					'length' => 10,
				)
			);
		} catch ( \RuntimeException $e ) {
			return 0;
		}
		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$coupon->set_discount_type( 'percent' === $r['bonus_type'] ? 'percent' : 'fixed_cart' );
		$coupon->set_amount( $r['bonus_amount'] );
		$coupon->set_usage_limit( 1 );
		$coupon->set_usage_limit_per_user( 1 );
		$coupon->set_individual_use( false );
		if ( $r['bonus_min_spend'] > 0 ) {
			$coupon->set_minimum_amount( $r['bonus_min_spend'] );
		}
		// V5: valid only after the waiting period – a draft until then, its validity counted from that day.
		$from = time() + self::wait_days() * DAY_IN_SECONDS;
		if ( $from > time() ) {
			$coupon->set_status( 'draft' );
			$coupon->update_meta_data( self::VALID_FROM, $from );
		}
		if ( $r['bonus_days'] > 0 ) {
			$coupon->set_date_expires( $from + $r['bonus_days'] * DAY_IN_SECONDS );
		}
		if ( is_email( $order->get_billing_email() ) ) {
			$coupon->set_email_restrictions( array( strtolower( $order->get_billing_email() ) ) );
		}
		/* translators: 1: promotion title, 2: order number */
		$coupon->set_description( sprintf( __( 'Bonus voucher (%1$s) for order #%2$s', 'voucher-captain' ), $campaign['title'], $order->get_order_number() ) );
		$coupon->update_meta_data( self::COUPON_ORDER, $order->get_id() );
		$coupon->update_meta_data( self::COUPON_SOURCE, (int) $campaign['id'] );
		$coupon = apply_filters( 'vcap_bonus_coupon', $coupon, $campaign, $order );
		return self::save_new_coupon( $coupon );
	}

	/**
	 * Saves a new coupon with the post status it carries. WooCommerce before 9.x always inserts new coupons as
	 * "publish" (its data store ignores the status prop on create), which would make a waiting-period bonus usable
	 * at once – so the status is passed to the insert itself, never published even for a moment.
	 */
	private static function save_new_coupon( \WC_Coupon $coupon ): int {
		$status = (string) $coupon->get_status( 'edit' );
		if ( $coupon->get_id() || '' === $status || 'publish' === $status ) {
			return (int) $coupon->save();
		}
		$code   = $coupon->get_code( 'edit' );
		$insert = static function ( $data ) use ( $code, $status ) {
			if ( is_array( $data ) && 'shop_coupon' === ( $data['post_type'] ?? '' ) && $code === ( $data['post_title'] ?? null ) ) {
				$data['post_status'] = $status;
			}
			return $data;
		};
		add_filter( 'woocommerce_new_coupon_data', $insert, PHP_INT_MAX );
		try {
			return (int) $coupon->save();
		} finally {
			remove_filter( 'woocommerce_new_coupon_data', $insert, PHP_INT_MAX );
		}
	}

	private function send( \WC_Coupon $coupon, \WC_Order $order, array $campaign ): void {
		$mailer = WC()->mailer();
		$emails = $mailer ? $mailer->get_emails() : array();
		if ( isset( $emails['VCAP_Email_Bonus'] ) ) {
			$emails['VCAP_Email_Bonus']->trigger( $coupon, $order, $campaign['title'] );
		}
	}

	/**
	 * Cancelled/refunded order: unused bonus coupons are moved to the trash and the use is given back.
	 *
	 * @param int|string $order_id Order id.
	 * @param int[]|null $only     Only these campaigns (partial refund below their threshold).
	 */
	public function withdraw( $order_id, $only = null ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$issued    = self::issued( $order );
		$left      = $issued;
		$withdrawn = array_map( 'intval', (array) $order->get_meta( self::WITHDRAWN ) );
		foreach ( $issued as $campaign_id => $coupon_id ) {
			if ( is_array( $only ) && ! in_array( (int) $campaign_id, array_map( 'intval', $only ), true ) ) {
				continue;
			}
			if ( in_array( (int) $campaign_id, $withdrawn, true ) ) {
				unset( $left[ $campaign_id ] ); // Already withdrawn (cancelled, then refunded): never give back another order's use.
				continue;
			}
			$coupon = new \WC_Coupon( $coupon_id );
			if ( ! $coupon->get_id() || 'trash' === get_post_status( $coupon_id ) ) {
				unset( $left[ $campaign_id ] );
				continue;
			}
			if ( $coupon->get_usage_count() > 0 ) {
				/* translators: %s: coupon code */
				$order->add_order_note( sprintf( __( 'Bonus voucher %s was already used and stays valid.', 'voucher-captain' ), \VCAP\Downloads::masked_code( (string) $coupon->get_code() ) ) );
				continue;
			}
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::ACTIVATE_HOOK, array( (int) $coupon_id ), \VCAP\Delivery::GROUP );
			}
			wp_trash_post( $coupon_id );
			Usage::remove( (int) $campaign_id, Usage::order_key( $order ), (int) $order->get_id() );
			unset( $left[ $campaign_id ] );
			/* translators: %s: coupon code */
			$order->add_order_note( sprintf( __( 'Unused bonus voucher %s withdrawn.', 'voucher-captain' ), \VCAP\Downloads::masked_code( (string) $coupon->get_code() ) ) );
		}
		// Keep withdrawn campaigns marked, so a later status change does not issue the bonus again.
		$order->update_meta_data( self::ORDER_META, $issued );
		$order->update_meta_data( self::WITHDRAWN, array_values( array_unique( array_merge( $withdrawn, array_map( 'intval', array_keys( array_diff_key( $issued, $left ) ) ) ) ) ) );
		$order->save_meta_data();
	}
}
