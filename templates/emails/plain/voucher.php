<?php
/**
 * Voucher email (plain text).
 *
 * @var \VCAP\Voucher $voucher
 * @var string        $email_heading
 * @var string        $intro
 * @var string        $additional_content
 * @var string        $download_url
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================================\n\n";

echo esc_html( wp_strip_all_tags( wptexturize( $intro ) ) ) . "\n\n";

if ( ! $voucher->is_ticket() || $voucher->initial_amount > 0 ) {
	$value = 'percent' === $voucher->discount_type ? wc_format_localized_decimal( $voucher->initial_amount ) . '%' : html_entity_decode( wp_strip_all_tags( wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) ) ) );
	/* translators: %s: value */
	echo esc_html( sprintf( __( 'Value: %s', 'voucher-captain' ), $value ) ) . "\n";
}
/* translators: %s: code */
echo esc_html( sprintf( __( 'Code: %s', 'voucher-captain' ), $voucher->code ) ) . "\n";
if ( $voucher->expires_at ) {
	/* translators: %s: date */
	echo esc_html( sprintf( __( 'Valid until %s', 'voucher-captain' ), wp_date( vcap()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ) . "\n";
}
if ( $voucher->message ) {
	echo "\n" . esc_html( $voucher->message ) . "\n";
}
if ( $download_url ) {
	echo "\n" . esc_html__( 'Download:', 'voucher-captain' ) . ' ' . esc_url_raw( $download_url ) . "\n";
}
echo "\n=================================================================\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
