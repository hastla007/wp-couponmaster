<?php
namespace VCAP\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce Subscriptions: subscription products can be vouchers; renewals optionally issue new vouchers.
 */
final class Subscriptions {
	public function register(): void {
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
		$this->init();
	}

	private bool $done = false;

	public function init(): void {
		if ( $this->done || ! class_exists( 'WC_Subscriptions' ) && ! function_exists( 'wcs_order_contains_renewal' ) ) {
			return;
		}
		$this->done = true;
		add_filter( 'vcap_should_issue_for_order', array( $this, 'should_issue' ), 10, 2 );
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'strip_meta' ) );
		add_filter( 'wcs_renewal_order_meta', array( $this, 'strip_meta_rows' ) );
		add_filter( 'product_type_options', array( $this, 'type_option_classes' ), 20 );
	}

	/** Order meta: the vouchers of a free trial's sign-up order wait for the first paid renewal (M12-38). */
	public const TRIAL_META = '_vcap_trial_waiting';

	/**
	 * Which subscription orders issue vouchers:
	 *  - Renewals only with the setting "vcap_subscription_renewals" – except the first paid renewal after a free
	 *    trial, which issues what the sign-up order did not (M12-38).
	 *  - Switch and resubscribe orders do not issue (the subscription already got its voucher; filter
	 *    "vcap_subscription_issue_on_switch" with the order and its kind "switch" or "resubscribe" allows it).
	 *  - A sign-up order with a total of 0 because of a free trial waits for the first paid renewal.
	 */
	public function should_issue( bool $issue, \WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return 'yes' === get_option( 'vcap_subscription_renewals', 'no' ) || ( $issue && $this->first_paid_renewal( $order ) );
		}
		foreach ( array( 'switch', 'resubscribe' ) as $kind ) {
			$check = 'wcs_order_contains_' . $kind;
			if ( function_exists( $check ) && $check( $order ) ) {
				return $issue && (bool) apply_filters( 'vcap_subscription_issue_on_switch', false, $order, $kind );
			}
		}
		if ( $issue && (float) $order->get_total() <= 0 && $this->free_trial( $order ) ) {
			if ( 'yes' !== $order->get_meta( self::TRIAL_META ) ) {
				$order->update_meta_data( self::TRIAL_META, 'yes' );
				$order->save_meta_data();
				$order->add_order_note( __( 'Free trial: the vouchers of this subscription are issued with the first paid renewal.', 'voucher-captain' ) );
			}
			return false;
		}
		return $issue;
	}

	/** Whether the order signs up for a subscription product with a free trial (M12-38). */
	private function free_trial( \WC_Order $order ): bool {
		if ( ! class_exists( 'WC_Subscriptions_Product' ) || ! function_exists( 'wcs_order_contains_subscription' ) || ! wcs_order_contains_subscription( $order ) ) {
			return false;
		}
		foreach ( $order->get_items() as $item ) {
			$product = $item instanceof \WC_Order_Item_Product ? $item->get_product() : null;
			if ( $product && \WC_Subscriptions_Product::get_trial_length( $product ) > 0 ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * A paid renewal of a subscription whose sign-up order waited for it (free trial): it issues the vouchers once,
	 * also without the renewal setting (M12-38).
	 */
	private function first_paid_renewal( \WC_Order $order ): bool {
		if ( (float) $order->get_total() <= 0 || ! function_exists( 'wcs_get_subscriptions_for_renewal_order' ) ) {
			return false;
		}
		foreach ( (array) wcs_get_subscriptions_for_renewal_order( $order ) as $subscription ) {
			$parent = is_object( $subscription ) && method_exists( $subscription, 'get_parent' ) ? $subscription->get_parent() : null;
			$state  = $parent instanceof \WC_Order ? (string) $parent->get_meta( self::TRIAL_META ) : '';
			if ( 'issued:' . $order->get_id() === $state ) {
				return true; // Asked again for the same renewal (e.g. processing, then completed).
			}
			if ( 'yes' === $state && $parent instanceof \WC_Order ) {
				$parent->update_meta_data( self::TRIAL_META, 'issued:' . $order->get_id() );
				$parent->save_meta_data();
				return true;
			}
		}
		return false;
	}

	public function strip_meta( $data ) {
		if ( is_array( $data ) ) {
			foreach ( array_keys( $data ) as $key ) {
				if ( is_string( $key ) && str_starts_with( $key, '_vcap_' ) ) {
					unset( $data[ $key ] );
				}
			}
		}
		return $data;
	}

	public function strip_meta_rows( $meta ) {
		if ( is_array( $meta ) ) {
			$meta = array_values(
				array_filter(
					$meta,
					static fn( $row ) => ! ( is_array( $row ) && isset( $row['meta_key'] ) && str_starts_with( (string) $row['meta_key'], '_vcap_' ) )
				)
			);
		}
		return $meta;
	}

	/** @param mixed $options What the previous filter returned (AP-Q2-6: a non-array is normalised). */
	public function type_option_classes( $options ): array {
		$options = is_array( $options ) ? $options : array();
		if ( isset( $options['vcap_enabled'] ) && is_array( $options['vcap_enabled'] ) ) {
			$options['vcap_enabled']['wrapper_class'] .= ' show_if_subscription show_if_variable-subscription';
		}
		return $options;
	}
}
