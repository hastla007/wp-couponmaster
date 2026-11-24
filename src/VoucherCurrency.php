<?php
/**
 * Multi-currency status and "sell vouchers only in the shop currency" (SET-VC-32).
 *
 * @package VCAP
 */

namespace VCAP;

use VCAP\API\V1\CommandLimits;
use VCAP\Compat\MultiCurrency;

defined( 'ABSPATH' ) || exit;

/**
 * SET-VC-32: a status line in "General" shows the detected currency switcher, the leading currency of vouchers (the
 * shop base currency, in which vouchers are stored and redeemed) and what the fixed ceiling of the automatic voucher
 * commands means for it (F-05a: 100 EUR; the same number in CHF, USD, GBP; any other currency needs an own maximum).
 *
 * The setting "Sell vouchers only in the shop currency" (default off = as before) refuses voucher products while the
 * customer has another currency active (the currency switcher's currency, read through get_woocommerce_currency()):
 * adding to the cart is refused with a notice, and a cart that already holds a voucher product cannot be checked out
 * (classic and block checkout both run "woocommerce_check_cart_items"). Filter "vcap_vouchers_shop_currency_only" wins.
 *
 * @since 1.3.0
 */
final class VoucherCurrency {
	/** Option: 'yes' = voucher products can be bought in the shop base currency only. */
	public const OPTION = 'vcap_vouchers_shop_currency_only';

	/** Field type of the status line (WooCommerce action "woocommerce_admin_field_{type}"). */
	public const FIELD_TYPE = 'vcap_currency_status';

	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( self::class, 'render' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( self::class, 'add_to_cart' ), 20, 3 );
		add_action( 'woocommerce_check_cart_items', array( self::class, 'check_cart' ) );
	}

	/** The shop base currency – the leading currency of every voucher (balances are stored in it). */
	public static function base_currency(): string {
		return strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
	}

	/** The currency the customer has active right now (a currency switcher filters it), else the base currency. */
	public static function active_currency(): string {
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		return '' !== $currency ? strtoupper( $currency ) : self::base_currency();
	}

	/** Whether voucher products can be bought in the shop base currency only (setting, then the filter). */
	public static function shop_currency_only(): bool {
		/**
		 * Whether voucher products can be bought only while the shop base currency is active. Default: the setting
		 * "Sell vouchers only in the shop currency" (off). The filter wins over the setting.
		 *
		 * @since 1.3.0
		 * @param bool $only Whether vouchers are sold in the shop currency only.
		 */
		return (bool) apply_filters( 'vcap_vouchers_shop_currency_only', 'yes' === Settings::value( self::OPTION ) );
	}

	/** Whether buying a voucher product is refused right now (setting on and another currency active). */
	public static function refused(): bool {
		$base = self::base_currency();
		return '' !== $base && self::shop_currency_only() && self::active_currency() !== $base;
	}

	/** The notice shown to the customer when a voucher cannot be bought in the active currency. */
	public static function notice(): string {
		/* translators: 1: shop base currency (e.g. EUR), 2: currency the customer has chosen */
		return sprintf( __( 'Vouchers can only be bought in %1$s. Please switch the currency from %2$s to %1$s to buy a voucher.', 'voucher-captain' ), self::base_currency(), self::active_currency() );
	}

	/**
	 * WooCommerce filter "woocommerce_add_to_cart_validation": refuses a voucher product in another currency.
	 *
	 * @param mixed $passed     Whether the product may be added.
	 * @param mixed $product_id Product id.
	 * @param mixed $quantity   Quantity.
	 * @return mixed
	 */
	public static function add_to_cart( $passed, $product_id = 0, $quantity = 1 ) {
		unset( $quantity );
		if ( ! $passed || ! self::refused() ) {
			return $passed;
		}
		return self::allow_product( $passed, wc_get_product( absint( $product_id ) ) );
	}

	/**
	 * $passed, or false with an error notice when $product is a voucher product and buying it is refused (refused()).
	 *
	 * @param mixed $passed  Whether the product may be added.
	 * @param mixed $product Product.
	 * @return mixed
	 */
	public static function allow_product( $passed, $product ) {
		if ( ! $passed || ! $product instanceof \WC_Product || ! self::refused() || ! ProductConfig::is_voucher( $product ) ) {
			return $passed;
		}
		wc_add_notice( self::notice(), 'error' );
		return false;
	}

	/** Action "woocommerce_check_cart_items": a cart with a voucher product cannot be checked out in another currency. */
	public static function check_cart(): void {
		if ( self::refused() && PaymentMethods::voucher_in_checkout() ) {
			wc_add_notice( self::notice(), 'error' );
		}
	}

	/**
	 * The lines of the status (label => text): currency switcher, leading currency, automatic commands.
	 *
	 * @return array<string, string>
	 */
	public static function status(): array {
		$base        = self::base_currency();
		$adapter     = MultiCurrency::adapter();
		$unsupported = '' === $adapter ? MultiCurrency::unsupported_switcher() : '';
		if ( '' !== $adapter ) {
			/* translators: %s: name of the currency switcher plugin */
			$switcher = sprintf( __( '%s (supported: amounts paid in another currency are converted with the exchange rate of the sale).', 'voucher-captain' ), $adapter );
		} elseif ( '' !== $unsupported ) {
			/* translators: %s: name of the currency switcher plugin */
			$switcher = sprintf( __( '%s – not supported: voucher sales in another currency are held until an exchange rate is known (filter vcap_amount_to_base_currency).', 'voucher-captain' ), $unsupported );
		} else {
			$switcher = __( 'None detected – the shop sells in one currency.', 'voucher-captain' );
		}
		/* translators: %s: shop base currency (e.g. EUR) */
		$leading = sprintf( __( '%s (shop base currency): every voucher value and balance is stored and redeemed in it.', 'voucher-captain' ), $base );
		return array(
			__( 'Currency switcher', 'voucher-captain' )       => $switcher,
			__( 'Leading currency of vouchers', 'voucher-captain' ) => $leading,
			__( 'Automatic voucher commands', 'voucher-captain' ) => self::commands_hint( $base ),
		);
	}

	/**
	 * F-05a for the shop currency: the fixed ceiling is 100 EUR; CHF, USD and GBP get the same number; any other
	 * currency needs an own maximum amount (Integrations (API)) before amounts are accepted. Reads CommandLimits, no
	 * rule of its own.
	 */
	public static function commands_hint( string $base ): string {
		$ceiling = intdiv( (int) CommandLimits::FIXED['voucher.issue']['max_amount_minor'], 100 );
		if ( CommandLimits::FIXED_CURRENCY === $base ) {
			/* translators: 1: ceiling number (100), 2: currency (EUR) */
			return sprintf( __( 'Amounts are limited to at most %1$s %2$s per voucher (fixed ceiling, can only be lowered).', 'voucher-captain' ), $ceiling, $base );
		}
		if ( in_array( $base, CommandLimits::SIMILAR_CURRENCIES, true ) ) {
			/* translators: 1: ceiling number (100), 2: shop currency, 3: currency of the fixed ceiling (EUR) */
			return sprintf( __( 'The ceiling is the same number as in %3$s: at most %1$s %2$s per voucher (no exchange rate, can only be lowered).', 'voucher-captain' ), $ceiling, $base, CommandLimits::FIXED_CURRENCY );
		}
		$effective = CommandLimits::effective( 'voucher.issue', $base );
		if ( $effective['amount_allowed'] ) {
			/* translators: 1: shop currency, 2: currency of the fixed ceiling (EUR) */
			return sprintf( __( 'Your own maximum amount in %1$s applies (the fixed ceiling is set in %2$s; the plugin uses no exchange rate).', 'voucher-captain' ), $base, CommandLimits::FIXED_CURRENCY );
		}
		/* translators: 1: shop currency, 2: currency of the fixed ceiling (EUR) */
		return sprintf( __( 'The fixed ceiling is set in %2$s. For %1$s enter your own maximum amount under Integrations (API) → Automatic voucher commands; until then issuing and redeeming amounts are refused.', 'voucher-captain' ), $base, CommandLimits::FIXED_CURRENCY );
	}

	/**
	 * Block "Currencies" of the section "General": the status line (display only) and the setting.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function settings_fields(): array {
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Currencies', 'voucher-captain' ),
				'id'    => 'vcap_currencies',
			),
			array(
				'title'     => __( 'Multi-currency status', 'voucher-captain' ),
				'id'        => 'vcap_currency_status',
				'type'      => self::FIELD_TYPE,
				'is_option' => false,
			),
			SettingsRegistry::field(
				self::OPTION,
				array(
					'title' => __( 'Sell vouchers only in the shop currency', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Voucher products cannot be added to the cart or checked out while the customer has another currency active (a notice asks to switch). Off = vouchers can be bought in every currency of the currency switcher.', 'voucher-captain' ),
				)
			),
			array(
				'type' => 'sectionend',
				'id'   => 'vcap_currencies',
			),
		);
	}

	/**
	 * Renders the status line.
	 *
	 * @param mixed $field Field.
	 */
	public static function render( $field ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$title = is_array( $field ) ? (string) ( $field['title'] ?? '' ) : '';
		echo '<tr valign="top"><th scope="row" class="titledesc">' . esc_html( $title ) . '</th><td class="forminp"><ul class="vcap-currency-status" style="margin:0">';
		foreach ( self::status() as $label => $text ) {
			echo '<li><strong>' . esc_html( $label ) . ':</strong> ' . esc_html( $text ) . '</li>';
		}
		echo '</ul></td></tr>';
	}
}
