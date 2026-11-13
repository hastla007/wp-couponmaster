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
		'tax_treatment'       => 'auto',
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
		'code_pool_low'       => '',
		'code_pool_empty'     => 'random',
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
			if ( ! $base->meta_exists( self::meta_key( 'usage' ) ) ) {
				$config['usage'] = self::default_usage( (string) $config['type'] );
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
			// A variation's "Valid for (days)" is a validity in days, also when the product uses the global default.
			if ( (int) $product->get_meta( self::meta_key( 'expiry_days' ) ) > 0 && in_array( $config['expiry_mode'], array( 'default', 'days' ), true ) ) {
				$config['expiry_mode'] = 'days';
			}
		}
		$config = self::normalise_restriction( $config );
		$config['per_item']     = max( 1, (int) $config['per_item'] );
		$config['max_checkins'] = max( 1, (int) $config['max_checkins'] );
		$config['template']     = (int) $config['template'] ?: (int) get_option( 'vcap_default_template', 0 );
		if ( ! $config['template'] ) {
			$config['template'] = TemplatePostType::first_template_id();
		}
		return apply_filters( 'vcap_product_config', $config, $product );
	}

	/**
	 * Usage of a product without a saved usage: gift cards keep their remaining value (stored balance), other
	 * vouchers are redeemed at once.
	 */
	public static function default_usage( string $type ): string {
		return 'giftcard' === $type ? 'balance' : 'single';
	}

	/**
	 * Who may redeem a voucher of this product ("email_restrict"):
	 *  - none      anyone with the code (transferable),
	 *  - both      the buyer's or the recipient's email address,
	 *  - recipient the recipient's email address (the buyer's when no recipient address was given),
	 *  - buyer     the buyer's email address.
	 *
	 * @return array<string, string>
	 */
	public static function redemption_choices(): array {
		return array(
			'none'      => __( 'Anyone with the code (transferable)', 'voucher-captain' ),
			'both'      => __( 'Only with the buyer or recipient email (personal)', 'voucher-captain' ),
			'recipient' => __( 'Only with the recipient email (buyer email if none was entered)', 'voucher-captain' ),
			'buyer'     => __( 'Only with the buyer email', 'voucher-captain' ),
		);
	}

	/** Customer-facing text for a "who may redeem" setting (same wording as on the voucher). */
	public static function restriction_label( string $restrict ): string {
		switch ( $restrict ) {
			case 'buyer':
				return __( 'Personal – only the buyer can redeem it.', 'voucher-captain' );
			case 'recipient':
				return __( 'Personal – only the named recipient can redeem it.', 'voucher-captain' );
			case 'both':
				return __( 'Personal – only the buyer or the named recipient can redeem it.', 'voucher-captain' );
		}
		return __( 'Transferable – anyone with the code can redeem it.', 'voucher-captain' );
	}

	/**
	 * One setting decides who may redeem: "email_restrict". Products saved before it replaced the separate
	 * "Transferable" checkbox are read compatibly (not transferable + no restriction = buyer or recipient);
	 * "transferable" is derived from it, so labels and coupon restrictions never contradict each other.
	 */
	public static function normalise_restriction( array $config ): array {
		$restrict = (string) ( $config['email_restrict'] ?? 'none' );
		if ( ! isset( self::redemption_choices()[ $restrict ] ) ) {
			$restrict = 'none';
		}
		if ( 'none' === $restrict && 'no' === (string) ( $config['transferable'] ?? 'yes' ) ) {
			$restrict = 'both';
		}
		$config['email_restrict'] = $restrict;
		$config['transferable']   = 'none' === $restrict ? 'yes' : 'no';
		return $config;
	}

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
	 * VAT treatment of a voucher when it pays for something (EU Directive 2016/1065):
	 *  - "payment"  multi-purpose voucher (Mehrzweckgutschein): a means of payment, VAT is due on the full price
	 *               when it is redeemed;
	 *  - "single"   single-purpose voucher (Einzweckgutschein): VAT was already charged when it was sold;
	 *  - "discount" promotion: a price reduction, VAT is reduced.
	 * Product setting "tax_treatment" ("auto": percentage vouchers and promotion codes are discounts, value
	 * vouchers and gift cards multi-purpose vouchers). Filter "vcap_voucher_tax_treatment".
	 */
	public static function tax_treatment( Voucher $voucher ): string {
		$product = $voucher->product();
		$setting = $product ? (string) self::get( $product )['tax_treatment'] : 'auto';
		if ( in_array( $setting, array( 'payment', 'single', 'discount' ), true ) ) {
			$treatment = $setting;
		} else {
			if ( 'percent' === $voucher->discount_type ) {
				$treatment = 'discount';
			} elseif ( $voucher->is_multi_use() || in_array( $voucher->type, array( 'giftcard', 'credit' ), true ) || $product ) {
				$treatment = 'payment'; // Gift cards, store credit and value vouchers that were sold.
			} else {
				$treatment = 'discount'; // Codes without a sale behind them (promotions, campaigns).
			}
		}
		return (string) apply_filters( 'vcap_voucher_tax_treatment', $treatment, $voucher );
	}

	/**
	 * Countries in which the validity of a voucher sold by this product ends before the statutory limitation period
	 * (shop country and the EU countries the shop sells to). A validity shorter than the statutory period is often
	 * invalid in standard terms (e.g. Germany: 3 years to the end of the year) – the admin is warned, not blocked.
	 *
	 * @return array<string, array{product:string, statutory:string}> country => both expiry dates (UTC) for a sale today
	 */
	public static function short_validity( $product, ?array $selling = null ): array {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product );
		if ( ! $product || ! self::is_voucher( $product ) ) {
			return array();
		}
		$config    = self::get( $product );
		$base      = strtoupper( strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) ?: '' );
		// $selling: the EU selling countries when the caller checks many products (array_keys( Markets::readiness() )).
		$countries = array_unique( array_filter( array_merge( array( $base ), $selling ?? array_keys( Markets::readiness() ) ) ) );
		$now       = time();
		$short     = array();
		foreach ( $countries as $country ) {
			$statutory = self::statutory_expiry( $now, (string) $country );
			$own       = self::expiry_for( $config, $now, (string) $country );
			if ( null !== $statutory && null !== $own && $own < $statutory ) {
				$short[ (string) $country ] = array( 'product' => $own, 'statutory' => $statutory );
			}
		}
		return $short;
	}

	/** Admin text for short_validity(), empty when the validity is long enough everywhere. */
	public static function short_validity_message( $product ): string {
		$short = self::short_validity( $product );
		if ( ! $short ) {
			return '';
		}
		$parts = array();
		foreach ( $short as $country => $dates ) {
			$parts[] = $country . ' ' . wp_date( get_option( 'date_format' ), (int) strtotime( $dates['statutory'] . ' UTC' ) );
		}
		/* translators: %s: countries with the statutory end date, e.g. "DE 31/12/2029" */
		return sprintf( __( 'The validity of vouchers sold now ends before the statutory limitation period (%s). A shorter validity in the voucher conditions is often invalid – have it checked legally or use the statutory validity.', 'voucher-captain' ), implode( ', ', $parts ) );
	}

	/** VAT treatment configured for a voucher product ("auto": percentage vouchers are discounts, the rest multi-purpose). */
	public static function product_treatment( $product ): string {
		$config  = self::get( $product );
		$setting = (string) $config['tax_treatment'];
		if ( in_array( $setting, array( 'payment', 'single', 'discount' ), true ) ) {
			return $setting;
		}
		return 'percent' === $config['discount_type'] ? 'discount' : 'payment';
	}

	/**
	 * Does the product's tax status fit the voucher kind? Multi-purpose vouchers are sold without VAT (tax status
	 * "None", VAT is due at redemption), single-purpose vouchers with the VAT of the later service ("Taxable").
	 * Null when it fits, taxes are off or the product is no voucher / a promotion.
	 *
	 * @return array{treatment:string, expected:string, actual:string, message:string}|null
	 */
	public static function tax_status_conflict( $product ): ?array {
		$product = $product instanceof \WC_Product ? $product : wc_get_product( $product );
		if ( ! $product || ! self::is_voucher( $product ) || ! function_exists( 'wc_tax_enabled' ) || ! wc_tax_enabled() ) {
			return null;
		}
		$status = (string) $product->get_tax_status();
		if ( 'parent' === $status && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$status = $parent ? (string) $parent->get_tax_status() : 'taxable';
		}
		$treatment = self::product_treatment( $product );
		if ( 'payment' === $treatment && 'none' !== $status ) {
			$message = __( 'This product sells a multi-purpose voucher (means of payment): its sale is not subject to VAT, the VAT is due when the voucher is redeemed. Set the tax status to "None" – or choose "Single-purpose voucher" if place and VAT of the service are fixed at the sale.', 'voucher-captain' );
			return array( 'treatment' => $treatment, 'expected' => 'none', 'actual' => $status, 'message' => $message );
		}
		if ( 'single' === $treatment && 'taxable' !== $status ) {
			$message = __( 'This product sells a single-purpose voucher: the VAT is due at the sale. Set the tax status to "Taxable" with the tax class of the service the voucher is for.', 'voucher-captain' );
			return array( 'treatment' => $treatment, 'expected' => 'taxable', 'actual' => $status, 'message' => $message );
		}
		return null;
	}

	/**
	 * Pass Captain's term for the VAT treatment: "multi" (multi-purpose voucher, means of payment), "single"
	 * (single-purpose voucher, VAT was charged at the sale) or "discount" (promotion code: a plain price reduction,
	 * no VAT was charged in advance – never labelled as single-purpose voucher).
	 */
	public static function purpose( Voucher $voucher ): string {
		$treatment = self::tax_treatment( $voucher );
		return 'payment' === $treatment ? 'multi' : ( 'single' === $treatment ? 'single' : 'discount' );
	}

	/**
	 * VAT of the sale of a voucher: rate in basis points (1900 = 19 %) and the country whose VAT was charged. From
	 * the line snapshot (Issuer::LINE_META), for older vouchers from the sale order line; null without a sale line.
	 *
	 * @return array{rate_bp:int, country:string}|null
	 */
	public static function sale_tax( Voucher $voucher ): ?array {
		$order = $voucher->order();
		$item  = $order && $voucher->order_item_id ? $order->get_item( $voucher->order_item_id ) : null;
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return null;
		}
		$line = Issuer::line_data( $item );
		if ( isset( $line['tax_rate_bp'], $line['tax_country'] ) ) {
			return array( 'rate_bp' => (int) $line['tax_rate_bp'], 'country' => (string) $line['tax_country'] );
		}
		return self::line_tax( $order, $item );
	}

	/**
	 * VAT rate (basis points) and taxing country of an order line: the country of the tax rate WooCommerce applied,
	 * else the order's tax location ("Calculate tax based on": billing, shipping or shop address).
	 *
	 * @return array{rate_bp:int, country:string}
	 */
	public static function line_tax( \WC_Order $order, \WC_Order_Item_Product $item ): array {
		$net     = (float) $item->get_subtotal();
		$rate_bp = $net > 0 ? (int) round( (float) $item->get_subtotal_tax() / $net * 10000 ) : 0;
		$country = '';
		$applied = array_keys( array_filter( (array) ( $item->get_taxes()['subtotal'] ?? array() ), static fn( $v ) => '' !== (string) $v && 0.0 !== (float) $v ) );
		foreach ( $order->get_items( 'tax' ) as $tax ) {
			if ( in_array( (int) $tax->get_rate_id(), array_map( 'intval', $applied ), true ) && class_exists( '\WC_Tax' ) ) {
				$country = strtoupper( (string) ( \WC_Tax::_get_tax_rate( (int) $tax->get_rate_id() )['tax_rate_country'] ?? '' ) );
				if ( '' !== $country ) {
					break;
				}
			}
		}
		if ( '' === $country ) {
			$based_on = (string) get_option( 'woocommerce_tax_based_on', 'shipping' );
			$base     = strtoupper( strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) ?: '' );
			$country  = 'base' === $based_on ? $base : strtoupper( (string) ( 'shipping' === $based_on && $order->get_shipping_country() ? $order->get_shipping_country() : $order->get_billing_country() ) );
			$country  = '' !== $country ? $country : $base;
		}
		return array( 'rate_bp' => $rate_bp, 'country' => $country );
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
	 * Statutory default validity for a country (default: the shop's base country). The rule is configuration, not
	 * code: setting "Statutory validity per country", else the verified market data (config/markets.php: Germany
	 * 3 years to the end of the year, §§ 195, 199 BGB; Austria 30 years), else 3 years to the end of the year.
	 * Filter "vcap_statutory_expiry" (array{years:int, end_of_year:bool}, country code); years 0 = never.
	 *
	 * @return array{years:int, end_of_year:bool}
	 */
	public static function statutory_rule( string $country = '' ): array {
		if ( '' === $country ) {
			$country = strtoupper( strtok( (string) get_option( 'woocommerce_default_country', '' ), ':' ) ?: '' );
		}
		$known = Markets::expiry_rule( $country );
		$rule  = (array) apply_filters( 'vcap_statutory_expiry', $known ? array( 'years' => $known['years'], 'end_of_year' => $known['end_of_year'] ) : array( 'years' => 3, 'end_of_year' => true ), $country );
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

	/** End of a fixed expiry date (shop time zone) as a timestamp, null when no valid date is set. */
	public static function fixed_expiry_timestamp( array $config ): ?int {
		$date = (string) ( $config['expiry_date'] ?? '' );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return null;
		}
		$ts = strtotime( $date . ' 23:59:59 ' . wp_timezone_string() );
		return $ts ? (int) $ts : null;
	}

	/**
	 * What is wrong with the expiry setting of a product: '' (fine), "date_missing" (fixed date without a date),
	 * "date_past" (the fixed date has passed: a voucher sold now would be expired) or "days_missing" ("days after
	 * purchase" without a number of days – the global default is used).
	 */
	public static function expiry_problem( array $config, ?int $now = null ): string {
		$now = $now ?? time();
		if ( 'date' === $config['expiry_mode'] ) {
			$ts = self::fixed_expiry_timestamp( $config );
			if ( null === $ts ) {
				return 'date_missing';
			}
			return $ts < $now ? 'date_past' : '';
		}
		if ( 'days' === $config['expiry_mode'] && (int) $config['expiry_days'] <= 0 ) {
			return 'days_missing';
		}
		return '';
	}

	/**
	 * Expiry of a new voucher (UTC MySQL datetime) or null for "never". $country: the buyer's country – the
	 * statutory default validity follows the law of the buyer's country (consumer contract), not the shop's;
	 * empty = shop country. "Global default" ignores a number of days left in the product; "days after purchase"
	 * without a number of days uses the global default as well (never "never expires" by accident).
	 */
	public static function expiry_for( array $config, ?int $from = null, string $country = '' ): ?string {
		$from = $from ?? time();
		switch ( $config['expiry_mode'] ) {
			case 'never':
				return null;
			case 'date':
				$ts = self::fixed_expiry_timestamp( $config );
				return $ts ? gmdate( 'Y-m-d H:i:s', $ts ) : null;
			case 'days':
				$days = (int) $config['expiry_days'];
				if ( $days > 0 ) {
					break;
				}
				// No number of days: fall through to the global default.
			default:
				if ( 'statutory' === self::default_expiry_rule() ) {
					return self::statutory_expiry( $from, $country );
				}
				$days = (int) get_option( 'vcap_default_expiry_days', 365 );
		}
		if ( $days <= 0 ) {
			return null;
		}
		// End of the expiry day in the shop's time zone (like the fixed date), stored in UTC.
		$local = ( new \DateTimeImmutable( '@' . ( $from + $days * DAY_IN_SECONDS ) ) )->setTimezone( wp_timezone() )->setTime( 23, 59, 59 );
		return $local->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
	}
}
