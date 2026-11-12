<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the effective voucher configuration for a simple product or a variation
 * (variation overrides → parent product settings → global defaults).
 */
final class ProductConfig {
	public const DEFAULTS = array(
		'type'                => 'voucher',
		'template'            => 0,
		'amount_source'       => 'price',
		'amount'              => '',
		'custom_min'          => '',
		'custom_max'          => '',
		'custom_step'         => '1',
		'custom_presets'      => '',
		'discount_type'       => 'fixed_cart',
		'usage'               => 'single',
		'usage_limit'         => '',
		'expiry_mode'         => 'default',
		'expiry_days'         => '',
		'expiry_date'         => '',
		'free_shipping'       => 'no',
		'individual_use'      => 'no',
		'exclude_sale'        => 'no',
		'min_spend'           => '',
		'max_spend'           => '',
		'product_ids'         => array(),
		'exclude_product_ids' => array(),
		'category_ids'        => array(),
		'exclude_category_ids'=> array(),
		'email_restrict'      => 'none',
		'per_item'            => 1,
		'delivery'            => 'immediate',
		'delivery_delay_days' => '',
		'delivery_date'       => '',
		'send_to'             => 'recipient',
		'buyer_copy'          => 'yes',
		'code_batch'          => '',
		'code_prefix'         => '',
		'code_suffix'         => '',
		'code_length'         => '',
		'max_checkins'        => 1,
		'event_date'          => '',
		'event_location'      => '',
		'transferable'        => 'yes',
		'redemption_terms'    => '',
		'preview'             => 'yes',
		'channels'            => array(),
		'builtin_fields'      => array( 'recipient_name', 'recipient_email', 'message' ),
		'message_maxlength'   => 300,
	);

	/** Keys a variation may override (empty = inherit). */
	public const VARIATION_KEYS = array( 'template', 'amount', 'expiry_days', 'per_item', 'max_checkins', 'code_batch', 'event_date' );

	public static function meta_key( string $key ): string {
		return '_vcap_' . $key;
	}

	public static function is_voucher( $product ): bool {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product );
		if ( ! $product ) {
			return false;
		}
		if ( $product->is_type( 'variation' ) ) {
			// The parent product decides; a variation can only opt out. (The variation checkbox is submitted for
			// every variable product, so its own "yes" must never enable vouchers on a non-voucher product.)
			$parent  = wc_get_product( $product->get_parent_id() );
			$enabled = $parent && 'yes' === $parent->get_meta( '_vcap_enabled' ) && 'no' !== $product->get_meta( '_vcap_enabled' );
		} else {
			$enabled = 'yes' === $product->get_meta( '_vcap_enabled' );
		}
		return (bool) apply_filters( 'vcap_is_voucher_product', $enabled, $product );
	}

	public static function get( $product ): array {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product );
		$config  = self::DEFAULTS;
		if ( ! $product ) {
			return $config;
		}
		$base = $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		if ( $base ) {
			foreach ( self::DEFAULTS as $key => $default ) {
				if ( $base->meta_exists( self::meta_key( $key ) ) ) {
					$value          = $base->get_meta( self::meta_key( $key ) );
					$config[ $key ] = is_array( $default ) ? array_filter( (array) $value, 'strlen' ) : $value;
				}
			}
		}
		if ( $product->is_type( 'variation' ) ) {
			foreach ( self::VARIATION_KEYS as $key ) {
				$value = (string) $product->get_meta( self::meta_key( $key ) );
				if ( '' !== $value && ! ( 'template' === $key && '0' === $value ) ) {
					$config[ $key ] = $value;
				}
			}
			if ( '' !== (string) $product->get_meta( self::meta_key( 'amount' ) ) ) {
				$config['amount_source'] = 'fixed';
			}
		}
		$config['per_item']     = max( 1, (int) $config['per_item'] );
		$config['max_checkins'] = max( 1, (int) $config['max_checkins'] );
		$config['template']     = (int) $config['template'] ?: (int) get_option( 'vcap_default_template', 0 );
		if ( ! $config['template'] ) {
			$config['template'] = TemplatePostType::first_template_id();
		}
		return apply_filters( 'vcap_product_config', $config, $product );
	}

	/**
	 * Redemption conditions: product text, otherwise the shop default.
	 */
	/**
	 * Redemption conditions: the product's own, else the shop default for the customer's country, else the
	 * general shop default.
	 */
	public static function terms( array $config, string $country = '' ): string {
		$terms = trim( (string) ( $config['redemption_terms'] ?? '' ) );
		if ( '' !== $terms ) {
			return $terms;
		}
		$by_country = self::terms_by_country( (string) get_option( 'vcap_terms_by_country', '' ) );
		$country    = strtoupper( $country );
		if ( '' !== $country && isset( $by_country[ $country ] ) ) {
			return $by_country[ $country ];
		}
		return trim( (string) get_option( 'vcap_default_terms', '' ) );
	}

	/**
	 * Parse "AT: text" blocks (a block runs until the next line starting with a two-letter country code and a colon).
	 *
	 * @return array<string, string>
	 */
	public static function terms_by_country( string $raw ): array {
		$out     = array();
		$current = '';
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			if ( preg_match( '/^\s*([A-Za-z]{2})\s*:\s*(.*)$/', $line, $m ) ) {
				$current         = strtoupper( $m[1] );
				$out[ $current ] = $m[2];
				continue;
			}
			if ( '' !== $current ) {
				$out[ $current ] .= "\n" . $line;
			}
		}
		return array_filter( array_map( 'trim', $out ), 'strlen' );
	}

	/** Country for country-specific conditions: billing, else shipping, else the shop's base country. */
	public static function country_of( $order_or_customer ): string {
		$country = '';
		if ( $order_or_customer instanceof \WC_Order || $order_or_customer instanceof \WC_Customer ) {
			$country = (string) $order_or_customer->get_billing_country();
			if ( '' === $country ) {
				$country = (string) $order_or_customer->get_shipping_country();
			}
		}
		if ( '' === $country && function_exists( 'WC' ) && WC()->countries ) {
			$country = (string) WC()->countries->get_base_country();
		}
		return $country;
	}

	/**
	 * Global default for products without their own expiry: "statutory" (by the shop's country) or "days"
	 * (vcap_default_expiry_days). Shops that saved a number of days before this setting existed keep it.
	 */
	public static function default_expiry_rule(): string {
		$rule = (string) get_option( 'vcap_default_expiry_rule', '' );
		if ( in_array( $rule, array( 'statutory', 'days' ), true ) ) {
			return $rule;
		}
		return false !== get_option( 'vcap_default_expiry_days', false ) ? 'days' : 'statutory';
	}

	/**
	 * Statutory default validity for the shop's base country: Germany 3 years to the end of the year
	 * (§§ 195, 199 BGB), Austria 30 years (OGH case law), other countries 3 years to the end of the year.
	 * Filter "vcap_statutory_expiry" (array{years:int, end_of_year:bool}, country code); years 0 = never.
	 *
	 * @return array{years:int, end_of_year:bool}
	 */
	public static function statutory_rule( string $country = '' ): array {
		if ( '' === $country ) {
			$country = strtoupper( strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) ?: '' );
		}
		$rules = array(
			'AT' => array( 'years' => 30, 'end_of_year' => false ),
			'DE' => array( 'years' => 3, 'end_of_year' => true ),
		);
		$rule = (array) apply_filters( 'vcap_statutory_expiry', $rules[ $country ] ?? array( 'years' => 3, 'end_of_year' => true ), $country );
		return array( 'years' => max( 0, (int) ( $rule['years'] ?? 3 ) ), 'end_of_year' => ! empty( $rule['end_of_year'] ) );
	}

	/** Expiry by the statutory rule (end of day / end of year in the shop's time zone, stored in UTC). */
	public static function statutory_expiry( int $from, string $country = '' ): ?string {
		$rule = self::statutory_rule( $country );
		if ( $rule['years'] < 1 ) {
			return null;
		}
		$local = ( new \DateTimeImmutable( '@' . $from ) )->setTimezone( wp_timezone() )->modify( '+' . $rule['years'] . ' years' );
		$local = $rule['end_of_year'] ? $local->setDate( (int) $local->format( 'Y' ), 12, 31 ) : $local;
		return $local->setTime( 23, 59, 59 )->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}

	/**
	 * Expiry timestamp (UTC MySQL datetime) or null for "never".
	 */
	public static function expiry_for( array $config, ?int $from = null ): ?string {
		$from = $from ?? time();
		switch ( $config['expiry_mode'] ) {
			case 'never':
				return null;
			case 'date':
				if ( $config['expiry_date'] ) {
					$ts = strtotime( $config['expiry_date'] . ' 23:59:59 ' . wp_timezone_string() );
					return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
				}
				return null;
			case 'days':
				$days = (int) $config['expiry_days'];
				break;
			default:
				if ( '' !== (string) $config['expiry_days'] && null !== $config['expiry_days'] ) {
					$days = (int) $config['expiry_days'];
				} elseif ( 'statutory' === self::default_expiry_rule() ) {
					return self::statutory_expiry( $from );
				} else {
					$days = (int) get_option( 'vcap_default_expiry_days', 365 );
				}
		}
		if ( $days <= 0 ) {
			return null;
		}
		// End of the expiry day in the shop's time zone (like the fixed date), stored in UTC.
		$local = ( new \DateTimeImmutable( '@' . ( $from + $days * DAY_IN_SECONDS ) ) )->setTimezone( wp_timezone() )->setTime( 23, 59, 59 );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
