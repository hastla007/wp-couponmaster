<?php
namespace VCAP\Discounts;

use VCAP\CodeGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * "Bonus voucher" promotions: after a qualifying order is paid, the customer receives a new single-use coupon
 * (restricted to their billing email) by email. Rules (products, categories, customer group, minimum value, period,
 * usage limits) are the same as for cart promotions. Cancelled or refunded orders take back an unused bonus.
 */
final class Bonus {
	public const ORDER_META    = '_vcap_bonus_coupons';
	public const COUPON_ORDER  = '_vcap_bonus_order';
	public const COUPON_SOURCE = '_vcap_bonus_campaign';

	public function __construct( private Repository $campaigns, private CartIntegration $cart ) {}

	public function register(): void {
		add_action( 'woocommerce_order_status_processing', array( $this, 'issue' ), 25 );
		add_action( 'woocommerce_order_status_completed', array( $this, 'issue' ), 25 );
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'withdraw' ), 25 );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'withdraw' ), 25 );
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
	 * @return array<int, int> campaign id => new coupon id
	 */
	public function issue( $order_id ): array {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order || ! $this->cart->enabled() || $order->get_parent_id() ) {
			return array();
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
			if ( '' !== Usage::blocker( $campaign, (int) $order->get_customer_id(), (string) $order->get_billing_email() ) ) {
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
			Usage::add( $id, Usage::order_key( $order ) );
			$issued[ $id ]  = $coupon_id;
			$created[ $id ] = $coupon_id;
			// Save before mailing, so a mail error can never lead to a second coupon.
			$order->update_meta_data( self::ORDER_META, $issued );
			$order->save_meta_data();
			$coupon = new \WC_Coupon( $coupon_id );
			/* translators: 1: coupon code, 2: promotion title */
			$order->add_order_note( sprintf( __( 'Bonus voucher %1$s issued (promotion "%2$s").', 'voucher-captain' ), $coupon->get_code(), $campaign['title'] ) );
			$this->send( $coupon, $order, $campaign );
		}
		return $created;
	}

	private function create_coupon( array $campaign, \WC_Order $order ): int {
		$r = $campaign['rules'];
		try {
			$code = CodeGenerator::unique(
				array(
					'prefix' => (string) apply_filters( 'vcap_bonus_code_prefix', 'BONUS', $campaign, $order ),
					'length' => 10,
				),
				static fn( $c ) => wc_get_coupon_id_by_code( $c ) > 0
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
		if ( $r['bonus_days'] > 0 ) {
			$coupon->set_date_expires( time() + $r['bonus_days'] * DAY_IN_SECONDS );
		}
		if ( is_email( $order->get_billing_email() ) ) {
			$coupon->set_email_restrictions( array( strtolower( $order->get_billing_email() ) ) );
		}
		/* translators: 1: promotion title, 2: order number */
		$coupon->set_description( sprintf( __( 'Bonus voucher (%1$s) for order #%2$s', 'voucher-captain' ), $campaign['title'], $order->get_order_number() ) );
		$coupon->update_meta_data( self::COUPON_ORDER, $order->get_id() );
		$coupon->update_meta_data( self::COUPON_SOURCE, (int) $campaign['id'] );
		$coupon = apply_filters( 'vcap_bonus_coupon', $coupon, $campaign, $order );
		return (int) $coupon->save();
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
	 */
	public function withdraw( $order_id ): void {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$issued    = self::issued( $order );
		$left      = $issued;
		$withdrawn = array_map( 'intval', (array) $order->get_meta( '_vcap_bonus_withdrawn' ) );
		foreach ( $issued as $campaign_id => $coupon_id ) {
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
				$order->add_order_note( sprintf( __( 'Bonus voucher %s was already used and stays valid.', 'voucher-captain' ), $coupon->get_code() ) );
				continue;
			}
			wp_trash_post( $coupon_id );
			Usage::remove( (int) $campaign_id, Usage::order_key( $order ) );
			unset( $left[ $campaign_id ] );
			/* translators: %s: coupon code */
			$order->add_order_note( sprintf( __( 'Unused bonus voucher %s withdrawn.', 'voucher-captain' ), $coupon->get_code() ) );
		}
		// Keep withdrawn campaigns marked, so a later status change does not issue the bonus again.
		$order->update_meta_data( self::ORDER_META, $issued );
		$order->update_meta_data( '_vcap_bonus_withdrawn', array_values( array_unique( array_merge( $withdrawn, array_map( 'intval', array_keys( array_diff_key( $issued, $left ) ) ) ) ) ) );
		$order->save_meta_data();
	}
}
