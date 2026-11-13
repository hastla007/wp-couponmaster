<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Dynamic placeholders used in PDF templates and emails.
 *
 * Static tokens: {coupon_code}, {coupon_value}, … (see definitions()).
 * Dynamic tokens: {field:KEY} personalisation field, {item_meta:KEY} order item meta (e.g. Flexible Product Fields),
 * {order_meta:KEY} order meta (e.g. Flexible Checkout Fields), {billing_FIELD} / {shipping_FIELD} address fields.
 * Extend with the `vcap_placeholder_definitions` and `vcap_placeholders` filters.
 */
final class Placeholders {
	public static function definitions(): array {
		$defs = array(
			'coupon_code'       => __( 'Coupon code', 'voucher-captain' ),
			'coupon_value'      => __( 'Voucher value (formatted)', 'voucher-captain' ),
			'coupon_value_plain'=> __( 'Voucher value (number)', 'voucher-captain' ),
			'coupon_balance'    => __( 'Remaining balance', 'voucher-captain' ),
			'expiry_date'       => __( 'Expiry date', 'voucher-captain' ),
			'issue_date'        => __( 'Issue date', 'voucher-captain' ),
			'voucher_type'      => __( 'Voucher type', 'voucher-captain' ),
			'product_name'      => __( 'Product name', 'voucher-captain' ),
			'product_price'     => __( 'Product price', 'voucher-captain' ),
			'product_sku'       => __( 'Product SKU', 'voucher-captain' ),
			'product_short_description' => __( 'Product short description', 'voucher-captain' ),
			'buyer_name'        => __( 'Buyer name', 'voucher-captain' ),
			'buyer_email'       => __( 'Buyer email', 'voucher-captain' ),
			'recipient_name'    => __( 'Recipient name', 'voucher-captain' ),
			'recipient_email'   => __( 'Recipient email', 'voucher-captain' ),
			'message'           => __( 'Personal message', 'voucher-captain' ),
			'delivery_date'     => __( 'Delivery date', 'voucher-captain' ),
			'order_id'          => __( 'Order ID', 'voucher-captain' ),
			'order_number'      => __( 'Order number', 'voucher-captain' ),
			'order_date'        => __( 'Order date', 'voucher-captain' ),
			'event_date'        => __( 'Event date (tickets)', 'voucher-captain' ),
			'event_location'    => __( 'Event location (tickets)', 'voucher-captain' ),
			'ticket_admissions' => __( 'Admissions per ticket', 'voucher-captain' ),
			'site_name'         => __( 'Site name', 'voucher-captain' ),
			'site_url'          => __( 'Site URL', 'voucher-captain' ),
			'redeem_url'        => __( 'Redeem page link with the code', 'voucher-captain' ),
			'redemption_terms'  => __( 'Redemption conditions', 'voucher-captain' ),
			'transferable'      => __( 'Transferability', 'voucher-captain' ),
		);
		return apply_filters( 'vcap_placeholder_definitions', $defs );
	}

	private static function money( float $amount, string $currency ): string {
		$html = wc_price( $amount, array( 'currency' => $currency ?: get_woocommerce_currency() ) );
		return trim( html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	private static function date( ?string $utc ): string {
		if ( ! $utc ) {
			return '';
		}
		$format = vcap()->settings->date_format();
		return wp_date( $format, strtotime( $utc . ' UTC' ) );
	}

	/**
	 * A date entered in the shop's local time ("2026-12-24", or "2026-12-24T19:00" from a datetime-local field such
	 * as the event date) in the voucher date format, with the time format when it has a time of day. It stays the
	 * wall-clock time that was entered (LocalDate, not strtotime() as UTC). Other text is returned unchanged.
	 */
	public static function local_date( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$formatted = LocalDate::format( $value, vcap()->settings->date_format(), (string) get_option( 'time_format', 'H:i' ) );
		return '' !== $formatted ? $formatted : $value;
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

		$delivery = $voucher->delivery_at ? self::date( $voucher->delivery_at ) : self::local_date( (string) $voucher->field( 'delivery_date' ) );

		$event_date = self::local_date( (string) $config['event_date'] );

		$values = array(
			'coupon_code'       => $voucher->code,
			'coupon_value'      => $value,
			'coupon_value_plain'=> wc_format_localized_decimal( $voucher->initial_amount ),
			'coupon_balance'    => 'percent' === $voucher->discount_type ? $value : self::money( $voucher->balance, $voucher->currency ),
			'expiry_date'       => $voucher->expires_at ? self::date( $voucher->expires_at ) : __( 'unlimited', 'voucher-captain' ),
			'issue_date'        => self::date( $voucher->created_at ?: gmdate( 'Y-m-d H:i:s' ) ),
			'voucher_type'      => Voucher::types()[ $voucher->type ] ?? $voucher->type,
			'product_name'      => $product ? $product->get_name() : '',
			'product_price'     => $product ? self::money( (float) wc_get_price_to_display( $product ), get_woocommerce_currency() ) : '',
			'product_sku'       => $product ? (string) $product->get_sku() : '',
			'product_short_description' => $product ? wp_strip_all_tags( (string) ( $product->get_short_description() ?: ( $product->is_type( 'variation' ) ? $product->get_description() : '' ) ) ) : '',
			// Vouchers created before names were stored (or by hand) fall back to the order's billing name.
			'buyer_name'        => '' !== trim( $voucher->buyer_name ) ? $voucher->buyer_name : ( $order ? trim( $order->get_formatted_billing_full_name() ) : '' ),
			'buyer_email'       => $voucher->buyer_email,
			'recipient_name'    => $voucher->recipient_name ?: ( '' !== trim( $voucher->buyer_name ) ? $voucher->buyer_name : ( $order ? trim( $order->get_formatted_billing_full_name() ) : '' ) ),
			'recipient_email'   => $voucher->recipient_email,
			'message'           => $voucher->message,
			'delivery_date'     => $delivery,
			'order_id'          => $voucher->order_id ? (string) $voucher->order_id : '',
			'order_number'      => $order ? (string) $order->get_order_number() : ( $voucher->order_id ? (string) $voucher->order_id : '' ),
			'order_date'        => $order && $order->get_date_created() ? wp_date( vcap()->settings->date_format(), $order->get_date_created()->getTimestamp() ) : '',
			'event_date'        => $event_date,
			'event_location'    => (string) $config['event_location'],
			'ticket_admissions' => (string) $voucher->max_checkins,
			'site_name'         => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url'          => home_url( '/' ),
			'redeem_url'        => $voucher->code ? Shortcodes::redeem_url( $voucher->code ) : '',
			'redemption_terms'  => '' !== $voucher->terms ? $voucher->terms : ProductConfig::terms( $config ),
			'transferable'      => $voucher->transferable_label(),
		);
		return apply_filters( 'vcap_placeholders', $values, $voucher );
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
		$custom = apply_filters( 'vcap_dynamic_placeholder', null, $token, $arg, $voucher );
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
