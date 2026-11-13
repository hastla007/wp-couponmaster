<?php
namespace VCAP\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Multi-currency: vouchers bought in a foreign currency are converted to the shop base currency,
 * because WooCommerce coupons are stored in the base currency (currency switchers convert them on the fly).
 * Supported: WooCommerce Multilingual (WCML), Aelia Currency Switcher, WOOCS, CURCY (free and premium),
 * WooPayments Multi-Currency; others via the `vcap_amount_to_base_currency` filter.
 *
 * Money is never taken 1:1 from a foreign currency. Whether a rate is known is decided by can_convert():
 * the base currency, an exchange rate stored on the order at checkout (RATE_META: order-currency units per
 * base-currency unit), a supported switcher, or another callback of `vcap_amount_to_base_currency` that changes
 * a probe amount (a converter returning the amount unchanged counts as "no rate"). Filter
 * `vcap_currency_rate_known` (bool, currency, order) overrides the result, e.g. for a converter whose rate is
 * exactly 1. Without a rate the money operation is refused: voucher sales are held (Issuer), store-credit
 * refunds return an error (StoreCredit), fixed-value vouchers are not valid in such a cart (CouponManager).
 */
final class MultiCurrency {
	/** Order meta: order-currency units per base-currency unit, stored at checkout (base = amount / rate). */
	public const RATE_META = '_vcap_exchange_rate';
	/** Option: currency => last time a money operation was refused for want of an exchange rate. */
	public const UNKNOWN_OPTION = 'vcap_fx_unknown';
	/** Option: order id => currency of voucher orders held for want of an exchange rate. */
	public const HELD_OPTION = 'vcap_fx_held_orders';
	/** Probe amount: a converter that changes it knows the currency. */
	private const PROBE = 7919.0;

	public function register(): void {
		add_filter( 'vcap_amount_to_base_currency', array( $this, 'convert' ), 10, 3 );
		add_action( 'woocommerce_checkout_create_order', array( $this, 'store_rate' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_update_order_meta', array( $this, 'store_rate' ), 20, 1 );
		add_action( 'admin_notices', array( $this, 'held_notice' ) );
	}

	public function convert( $amount, $currency, $order = null ) {
		$base   = get_option( 'woocommerce_currency' );
		$amount = (float) $amount;
		if ( ! $currency || $currency === $base ) {
			return $amount;
		}

		// Rate of the sale, stored at checkout: what the customer paid is converted as agreed then.
		if ( $order instanceof \WC_Order ) {
			$rate = (float) $order->get_meta( self::RATE_META );
			if ( $rate > 0 ) {
				return $amount / $rate;
			}
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

		$rate = self::switcher_rate( (string) $currency );
		return $rate > 0 ? $amount / $rate : $amount; // Unchanged = no rate known (see can_convert()).
	}

	/**
	 * Order-currency units per base-currency unit from a switcher that publishes its rates (WOOCS, CURCY,
	 * WooPayments Multi-Currency); 0 when none knows the currency.
	 */
	private static function switcher_rate( string $currency ): float {
		// WOOCS.
		global $WOOCS; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( is_object( $WOOCS ) && method_exists( $WOOCS, 'get_currencies' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$currencies = $WOOCS->get_currencies(); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$rate       = (float) ( $currencies[ $currency ]['rate'] ?? 0 );
			if ( $rate > 0 ) {
				return $rate;
			}
		}

		// CURCY (VillaTheme): premium and free (wordpress.org "woo-multi-currency") edition.
		foreach ( array( '\WOOMULTI_CURRENCY_Data', '\WOOMULTI_CURRENCY_F_Data' ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'get_ins' ) ) {
				$settings = $class::get_ins();
				$rates    = method_exists( $settings, 'get_list_currencies' ) ? (array) $settings->get_list_currencies() : array();
				$rate     = (float) ( $rates[ $currency ]['rate'] ?? 0 );
				if ( $rate > 0 ) {
					return $rate;
				}
			}
		}

		// WooPayments Multi-Currency: price in the currency = base price × rate.
		$multi = self::woopayments();
		if ( $multi ) {
			$list  = (array) $multi->get_enabled_currencies();
			$entry = $list[ $currency ] ?? null;
			$rate  = is_object( $entry ) && method_exists( $entry, 'get_rate' ) ? (float) $entry->get_rate() : 0.0;
			if ( $rate > 0 ) {
				return $rate;
			}
		}
		return 0.0;
	}

	/** Name of the supported switcher that converts right now ('' when none). */
	public static function adapter(): string {
		global $woocommerce_wpml, $WOOCS; // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		if ( isset( $woocommerce_wpml->multi_currency->prices ) && method_exists( $woocommerce_wpml->multi_currency->prices, 'unconvert_price_amount' ) ) {
			return 'WooCommerce Multilingual';
		}
		if ( has_filter( 'wc_aelia_cs_convert' ) ) {
			return 'Aelia Currency Switcher';
		}
		if ( is_object( $WOOCS ) && method_exists( $WOOCS, 'get_currencies' ) ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			return 'WOOCS';
		}
		foreach ( array( '\WOOMULTI_CURRENCY_Data', '\WOOMULTI_CURRENCY_F_Data' ) as $class ) {
			if ( class_exists( $class ) && method_exists( $class, 'get_ins' ) ) {
				return 'CURCY';
			}
		}
		if ( self::woopayments() ) {
			return 'WooPayments Multi-Currency';
		}
		return '';
	}

	/** WooPayments' multi-currency object when it lists currencies with rates (null otherwise). */
	private static function woopayments(): ?object {
		$function = 'WC_Payments_Multi_Currency';
		if ( ! function_exists( $function ) ) {
			return null;
		}
		$multi = $function();
		return is_object( $multi ) && method_exists( $multi, 'get_enabled_currencies' ) ? $multi : null;
	}

	/**
	 * Whether an amount in $currency can be converted to the base currency (see the class comment).
	 *
	 * @param \WC_Order|null $order Order whose stored exchange rate counts.
	 */
	public static function can_convert( string $currency, $order = null ): bool {
		if ( '' === $currency || get_option( 'woocommerce_currency' ) === $currency ) {
			return true;
		}
		$known = ( $order instanceof \WC_Order && (float) $order->get_meta( self::RATE_META ) > 0 )
			|| '' !== self::adapter() && ( self::switcher_rate( $currency ) > 0 || ! self::rate_listing_adapter() );
		if ( ! $known ) {
			// Another converter on the public filter: it changes the probe (ours returns it unchanged here).
			$probe = (float) apply_filters( 'vcap_amount_to_base_currency', self::PROBE, $currency, $order );
			$known = $probe > 0 && abs( $probe - self::PROBE ) > 1e-9;
		}
		/** Whether amounts in this currency can be converted to the shop base currency (bool, currency, order). */
		return (bool) apply_filters( 'vcap_currency_rate_known', $known, $currency, $order );
	}

	/** Whether the active adapter publishes a rate list (a missing currency then means "no rate"). */
	private static function rate_listing_adapter(): bool {
		return in_array( self::adapter(), array( 'WOOCS', 'CURCY', 'WooPayments Multi-Currency' ), true );
	}

	/**
	 * $amount in $currency converted to the base currency, or null when no exchange rate is known (the refusal
	 * is recorded for Site Health).
	 *
	 * @param \WC_Order|null $order Order whose stored exchange rate counts.
	 */
	public static function to_base( float $amount, string $currency, $order = null ): ?float {
		if ( '' === $currency || get_option( 'woocommerce_currency' ) === $currency ) {
			return $amount;
		}
		if ( ! self::can_convert( $currency, $order ) ) {
			self::record_unknown( $currency );
			return null;
		}
		return (float) apply_filters( 'vcap_amount_to_base_currency', $amount, $currency, $order );
	}

	/** Remembers (at most once an hour per currency) that a money operation was refused for want of a rate. */
	public static function record_unknown( string $currency ): void {
		$log = get_option( self::UNKNOWN_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		if ( (int) ( $log[ $currency ] ?? 0 ) > time() - HOUR_IN_SECONDS ) {
			return;
		}
		$log[ sanitize_text_field( $currency ) ] = time();
		update_option( self::UNKNOWN_OPTION, array_slice( $log, -20, null, true ), false );
	}

	/** @return array<string, int> currency => last refusal (last 30 days). */
	public static function unknown_currencies(): array {
		$log = get_option( self::UNKNOWN_OPTION, array() );
		return array_filter( is_array( $log ) ? $log : array(), static fn( $time ) => (int) $time > time() - 30 * DAY_IN_SECONDS );
	}

	/**
	 * Checkout: stores the exchange rate of a foreign-currency order (RATE_META) while a converter knows it, so
	 * issuing, refunds and gift card charges later use the rate of the sale – also when the switcher is gone.
	 *
	 * @param \WC_Order $order Order being created.
	 */
	public function store_rate( $order ): void {
		if ( ! $order instanceof \WC_Order || (float) $order->get_meta( self::RATE_META ) > 0 ) {
			return;
		}
		$currency = (string) $order->get_currency();
		if ( '' === $currency || get_option( 'woocommerce_currency' ) === $currency ) {
			return;
		}
		$rate = self::switcher_rate( $currency );
		if ( $rate <= 0 && self::can_convert( $currency ) ) {
			$unit = (float) apply_filters( 'vcap_amount_to_base_currency', 1000.0, $currency, null ) / 1000;
			$rate = $unit > 0 ? 1 / $unit : 0.0;
		}
		if ( $rate > 0 ) {
			$order->update_meta_data( self::RATE_META, wc_format_decimal( $rate, 10 ) );
			if ( $order->get_id() ) {
				$order->save_meta_data();
			}
		}
	}

	/** Remembers a voucher order held for want of an exchange rate (admin notice until it is no longer on hold). */
	public static function record_held( \WC_Order $order ): void {
		$held = get_option( self::HELD_OPTION, array() );
		$held = is_array( $held ) ? $held : array();
		$held[ $order->get_id() ] = (string) $order->get_currency();
		update_option( self::HELD_OPTION, array_slice( $held, -50, null, true ), false );
	}

	/** Admin notice (dashboard, orders, voucher screens): voucher orders held for want of an exchange rate. */
	public function held_notice(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		if ( ! in_array( $screen->id, array( 'dashboard', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true ) && ! str_contains( (string) $screen->id, 'vcap' ) ) {
			return;
		}
		$held  = get_option( self::HELD_OPTION, array() );
		$held  = is_array( $held ) ? $held : array();
		$open  = array();
		foreach ( $held as $order_id => $currency ) {
			$order = wc_get_order( (int) $order_id );
			if ( $order instanceof \WC_Order && $order->has_status( 'on-hold' ) && ! self::can_convert( (string) $currency, $order ) ) {
				$open[ (int) $order_id ] = $order;
			}
		}
		if ( count( $open ) !== count( $held ) ) {
			update_option( self::HELD_OPTION, array_intersect_key( $held, $open ), false );
		}
		if ( ! $open ) {
			return;
		}
		$links = implode(
			', ',
			array_map( static fn( \WC_Order $o ) => '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>', $open )
		);
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Voucher Captain', 'voucher-captain' ) . ':</strong> '
			. esc_html__( 'These orders were paid in a foreign currency without a known exchange rate, so their vouchers were not issued and the orders are on hold. Set up a supported currency switcher (or the exchange rate on the order) and issue the vouchers from the order screen, or refund the orders:', 'voucher-captain' )
			. ' ' . $links . '</p></div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
	}

	/**
	 * A currency switcher that Voucher Captain has no adapter for (and no other converter on the filter):
	 * name of the plugin, '' when none is detected.
	 */
	public static function unsupported_switcher(): string {
		if ( '' !== self::adapter() ) {
			return '';
		}
		$hook = $GLOBALS['wp_filter']['vcap_amount_to_base_currency'] ?? null;
		if ( $hook instanceof \WP_Hook && array_sum( array_map( 'count', $hook->callbacks ) ) > 1 ) {
			return ''; // Someone converts through the public filter.
		}
		$known = array(
			'Price Based on Country'      => class_exists( 'WC_Product_Price_Based_Country' ) || function_exists( 'wcpbc' ),
			'YITH Multi Currency Switcher' => defined( 'YITH_WCMCS_VERSION' ),
			'Currency Switcher for WooCommerce' => class_exists( 'Alg_WC_Currency_Switcher' ),
		);
		foreach ( $known as $name => $active ) {
			if ( $active ) {
				return $name;
			}
		}
		// Anything else that switches the shop currency.
		return has_filter( 'woocommerce_currency' ) ? __( 'a currency switcher', 'voucher-captain' ) : '';
	}
}
