<?php
namespace WCVS\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-currency: vouchers bought in a foreign currency are converted to the shop base currency,
 * because WooCommerce coupons are stored in the base currency (currency switchers convert them on the fly).
 * Supported: WooCommerce Multilingual (WCML), Aelia Currency Switcher, WOOCS, CURCY; others via the
 * `wcvs_amount_to_base_currency` filter.
 */
final class MultiCurrency {
	public function register(): void {
		add_filter( 'wcvs_amount_to_base_currency', array( $this, 'convert' ), 10, 3 );
	}

	public function convert( $amount, $currency, $order = null ) {
		$base   = get_option( 'woocommerce_currency' );
		$amount = (float) $amount;
		if ( ! $currency || $currency === $base ) {
			return $amount;
		}

		// WooCommerce Multilingual.
		global $woocommerce_wpml;
		if ( isset( $woocommerce_wpml->multi_currency->prices ) && method_exists( $woocommerce_wpml->multi_currency->prices, 'unconvert_price_amount' ) ) {
			return (float) $woocommerce_wpml->multi_currency->prices->unconvert_price_amount( $amount, $currency );
		}

		// Aelia Currency Switcher.
		if ( has_filter( 'wc_aelia_cs_convert' ) ) {
			return (float) apply_filters( 'wc_aelia_cs_convert', $amount, $currency, $base );
		}

		// WOOCS.
		global $WOOCS; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( is_object( $WOOCS ) && method_exists( $WOOCS, 'get_currencies' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$currencies = $WOOCS->get_currencies(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$rate       = (float) ( $currencies[ $currency ]['rate'] ?? 0 );
			if ( $rate > 0 ) {
				return $amount / $rate;
			}
		}

		// CURCY (VillaTheme).
		if ( class_exists( '\WOOMULTI_CURRENCY_Data' ) ) {
			$settings = \WOOMULTI_CURRENCY_Data::get_ins();
			$rates    = method_exists( $settings, 'get_list_currencies' ) ? $settings->get_list_currencies() : array();
			$rate     = (float) ( $rates[ $currency ]['rate'] ?? 0 );
			if ( $rate > 0 ) {
				return $amount / $rate;
			}
		}

		// Order-stored rate (some switchers save it).
		if ( $order instanceof \WC_Order ) {
			$rate = (float) $order->get_meta( '_wcvs_exchange_rate' );
			if ( $rate > 0 ) {
				return $amount / $rate;
			}
		}
		return $amount;
	}
}
