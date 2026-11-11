<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic placeholders used in PDF templates and emails.
 *
 * Static tokens: {coupon_code}, {coupon_value}, … (see definitions()).
 * Dynamic tokens: {field:KEY} personalisation field, {item_meta:KEY} order item meta (e.g. Flexible Product Fields),
 * {order_meta:KEY} order meta (e.g. Flexible Checkout Fields), {billing_FIELD} / {shipping_FIELD} address fields.
 * Extend with the `wcvs_placeholder_definitions` and `wcvs_placeholders` filters.
 */
final class Placeholders {
	public static function definitions(): array {
		$defs = array(
			'coupon_code'       => __( 'Coupon code', 'wc-voucher-studio' ),
			'coupon_value'      => __( 'Voucher value (formatted)', 'wc-voucher-studio' ),
			'coupon_value_plain'=> __( 'Voucher value (number)', 'wc-voucher-studio' ),
			'coupon_balance'    => __( 'Remaining balance', 'wc-voucher-studio' ),
			'expiry_date'       => __( 'Expiry date', 'wc-voucher-studio' ),
			'issue_date'        => __( 'Issue date', 'wc-voucher-studio' ),
			'voucher_type'      => __( 'Voucher type', 'wc-voucher-studio' ),
			'product_name'      => __( 'Product name', 'wc-voucher-studio' ),
			'product_price'     => __( 'Product price', 'wc-voucher-studio' ),
			'product_sku'       => __( 'Product SKU', 'wc-voucher-studio' ),
			'product_short_description' => __( 'Product short description', 'wc-voucher-studio' ),
			'buyer_name'        => __( 'Buyer name', 'wc-voucher-studio' ),
			'buyer_email'       => __( 'Buyer email', 'wc-voucher-studio' ),
			'recipient_name'    => __( 'Recipient name', 'wc-voucher-studio' ),
			'recipient_email'   => __( 'Recipient email', 'wc-voucher-studio' ),
			'message'           => __( 'Personal message', 'wc-voucher-studio' ),
			'delivery_date'     => __( 'Delivery date', 'wc-voucher-studio' ),
			'order_id'          => __( 'Order ID', 'wc-voucher-studio' ),
			'order_number'      => __( 'Order number', 'wc-voucher-studio' ),
			'order_date'        => __( 'Order date', 'wc-voucher-studio' ),
			'event_date'        => __( 'Event date (tickets)', 'wc-voucher-studio' ),
			'event_location'    => __( 'Event location (tickets)', 'wc-voucher-studio' ),
			'ticket_admissions' => __( 'Admissions per ticket', 'wc-voucher-studio' ),
			'site_name'         => __( 'Site name', 'wc-voucher-studio' ),
			'site_url'          => __( 'Site URL', 'wc-voucher-studio' ),
		);
		return apply_filters( 'wcvs_placeholder_definitions', $defs );
	}

	private static function money( float $amount, string $currency ): string {
		$html = wc_price( $amount, array( 'currency' => $currency ?: get_woocommerce_currency() ) );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private static function date( ?string $utc ): string {
		if ( ! $utc ) {
			return '';
		}
		$format = wcvs()->settings->date_format();
		return wp_date( $format, strtotime( $utc . ' UTC' ) );
	}

	/**
	 * @return array<string,string> token (without braces) => value
	 */
	public static function values( Voucher $voucher ): array {
		$order   = $voucher->order();
		$product = $voucher->product();
		$config  = $product ? ProductConfig::get( $product ) : ProductConfig::DEFAULTS;

		$value = 'percent' === $voucher->discount_type
			? wc_format_localized_decimal( $voucher->initial_amount ) . '%'
			: self::money( $voucher->initial_amount, $voucher->currency );

		$delivery = $voucher->delivery_at ? self::date( $voucher->delivery_at ) : ( $voucher->field( 'delivery_date' ) ? wp_date( wcvs()->settings->date_format(), strtotime( $voucher->field( 'delivery_date' ) ) ) : '' );

		$event_date = (string) $config['event_date'];
		if ( $event_date && strtotime( $event_date ) ) {
			$event_date = wp_date( wcvs()->settings->date_format() . ( str_contains( $event_date, ':' ) ? ' ' . get_option( 'time_format' ) : '' ), strtotime( $event_date ), wp_timezone() );
		}

		$values = array(
			'coupon_code'       => $voucher->code,
			'coupon_value'      => $value,
			'coupon_value_plain'=> wc_format_localized_decimal( $voucher->initial_amount ),
			'coupon_balance'    => 'percent' === $voucher->discount_type ? $value : self::money( $voucher->balance, $voucher->currency ),
			'expiry_date'       => $voucher->expires_at ? self::date( $voucher->expires_at ) : __( 'unlimited', 'wc-voucher-studio' ),
			'issue_date'        => self::date( $voucher->created_at ?: gmdate( 'Y-m-d H:i:s' ) ),
			'voucher_type'      => Voucher::types()[ $voucher->type ] ?? $voucher->type,
			'product_name'      => $product ? $product->get_name() : '',
			'product_price'     => $product ? self::money( (float) wc_get_price_to_display( $product ), get_woocommerce_currency() ) : '',
			'product_sku'       => $product ? (string) $product->get_sku() : '',
			'product_short_description' => $product ? wp_strip_all_tags( (string) ( $product->get_short_description() ?: ( $product->is_type( 'variation' ) ? $product->get_description() : '' ) ) ) : '',
			'buyer_name'        => $voucher->buyer_name,
			'buyer_email'       => $voucher->buyer_email,
			'recipient_name'    => $voucher->recipient_name ?: $voucher->buyer_name,
			'recipient_email'   => $voucher->recipient_email,
			'message'           => $voucher->message,
			'delivery_date'     => $delivery,
			'order_id'          => $voucher->order_id ? (string) $voucher->order_id : '',
			'order_number'      => $order ? (string) $order->get_order_number() : ( $voucher->order_id ? (string) $voucher->order_id : '' ),
			'order_date'        => $order && $order->get_date_created() ? wp_date( wcvs()->settings->date_format(), $order->get_date_created()->getTimestamp() ) : '',
			'event_date'        => $event_date,
			'event_location'    => (string) $config['event_location'],
			'ticket_admissions' => (string) $voucher->max_checkins,
			'site_name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'          => home_url( '/' ),
		);
		return apply_filters( 'wcvs_placeholders', $values, $voucher );
	}

	public static function replace( string $text, Voucher $voucher, ?array $values = null ): string {
		$values = $values ?? self::values( $voucher );
		$text   = preg_replace_callback(
			'/\{([a-z0-9_]+)(?::([^}]+))?\}/i',
			static function ( array $m ) use ( $values, $voucher ): string {
				$token = strtolower( $m[1] );
				$arg   = $m[2] ?? null;
				if ( null === $arg && array_key_exists( $token, $values ) ) {
					return (string) $values[ $token ];
				}
				return self::dynamic( $token, $arg, $voucher, $m[0] );
			},
			$text
		);
		return (string) $text;
	}

	private static function dynamic( string $token, ?string $arg, Voucher $voucher, string $original ): string {
		$order = $voucher->order();
		switch ( $token ) {
			case 'field':
				return $voucher->field( (string) $arg );
			case 'item_meta':
				if ( $order && $voucher->order_item_id ) {
					$item = $order->get_item( $voucher->order_item_id );
					if ( $item ) {
						$value = $item->get_meta( (string) $arg );
						if ( '' === $value || null === $value ) {
							foreach ( $item->get_formatted_meta_data( '' ) as $meta ) {
								if ( 0 === strcasecmp( wp_strip_all_tags( $meta->display_key ), (string) $arg ) ) {
									$value = wp_strip_all_tags( $meta->display_value );
									break;
								}
							}
						}
						return is_scalar( $value ) ? trim( (string) $value ) : '';
					}
				}
				return '';
			case 'order_meta':
				if ( $order ) {
					$value = $order->get_meta( (string) $arg );
					return is_scalar( $value ) ? (string) $value : '';
				}
				return '';
		}
		if ( $order && null === $arg && preg_match( '/^(billing|shipping)_[a-z0-9_]+$/', $token ) ) {
			$getter = 'get_' . $token;
			if ( is_callable( array( $order, $getter ) ) ) {
				return (string) $order->$getter();
			}
			return (string) $order->get_meta( '_' . $token );
		}
		$custom = apply_filters( 'wcvs_dynamic_placeholder', null, $token, $arg, $voucher );
		return null === $custom ? ( $order ? '' : $original ) : (string) $custom;
	}

	/**
	 * Placeholders in the "{token}" form expected by WC_Email::format_string().
	 */
	public static function email_map( ?Voucher $voucher ): array {
		$map = array();
		if ( null === $voucher ) {
			foreach ( array_keys( self::definitions() ) as $key ) {
				$map[ '{' . $key . '}' ] = '';
			}
			return $map;
		}
		foreach ( self::values( $voucher ) as $key => $value ) {
			$map[ '{' . $key . '}' ] = $value;
		}
		return $map;
	}
}
