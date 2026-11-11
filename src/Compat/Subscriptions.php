<?php
namespace WCVS\Compat;

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
		add_filter( 'wcvs_should_issue_for_order', array( $this, 'should_issue' ), 10, 2 );
		add_filter( 'wc_subscriptions_renewal_order_data', array( $this, 'strip_meta' ) );
		add_filter( 'wcs_renewal_order_meta', array( $this, 'strip_meta_rows' ) );
		add_filter( 'product_type_options', array( $this, 'type_option_classes' ), 20 );
	}

	public function should_issue( bool $issue, \WC_Order $order ): bool {
		if ( function_exists( 'wcs_order_contains_renewal' ) && wcs_order_contains_renewal( $order ) ) {
			return 'yes' === get_option( 'wcvs_subscription_renewals', 'no' );
		}
		return $issue;
	}

	public function strip_meta( $data ) {
		if ( is_array( $data ) ) {
			foreach ( array_keys( $data ) as $key ) {
				if ( is_string( $key ) && str_starts_with( $key, '_wcvs_' ) ) {
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
					static fn( $row ) => ! ( is_array( $row ) && isset( $row['meta_key'] ) && str_starts_with( (string) $row['meta_key'], '_wcvs_' ) )
				)
			);
		}
		return $meta;
	}

	public function type_option_classes( array $options ): array {
		if ( isset( $options['wcvs_enabled'] ) ) {
			$options['wcvs_enabled']['wrapper_class'] .= ' show_if_subscription show_if_variable-subscription';
		}
		return $options;
	}
}
