<?php
/**
 * Voucher email (plain text).
 *
 * Override: yourtheme/woocommerce/voucher-captain/emails/plain/voucher.php
 * @version  1.2.0
 *
 * @var \VCAP\Voucher $voucher
 * @var string        $email_heading
 * @var string        $intro
 * @var string        $additional_content
 * @var string        $download_url
 */

defined( 'ABSPATH' ) || exit;

// Plain text: no HTML escaping. WooCommerce strips tags and drops entities it does not know (&#039; would make an
// apostrophe vanish), so values are printed as text without tags (AP-F1-2).
// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped

echo "=================================================================\n";
echo wp_strip_all_tags( $email_heading ) . "\n";
echo "=================================================================\n\n";

echo wp_strip_all_tags( wptexturize( $intro ) ) . "\n\n";

if ( ! $voucher->is_ticket() || $voucher->initial_amount > 0 ) {
	$value = 'percent' === $voucher->discount_type ? \VCAP\Format::percent( $voucher->initial_amount ) : html_entity_decode( wp_strip_all_tags( wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) ) ) );
	/* translators: %s: value */
	echo wp_strip_all_tags( sprintf( __( 'Value: %s', 'voucher-captain' ), $value ) ) . "\n";
}
/* translators: %s: code */
echo wp_strip_all_tags( sprintf( __( 'Code: %s', 'voucher-captain' ), $voucher->code ) ) . "\n";
if ( $voucher->expires_at ) {
	/* translators: %s: date */
	echo wp_strip_all_tags( sprintf( __( 'Valid until %s', 'voucher-captain' ), wp_date( vcap()->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) ) ) . "\n";
}
if ( ! $voucher->transferable ) {
	echo wp_strip_all_tags( $voucher->transferable_label() ) . "\n";
}
if ( '' !== $voucher->terms ) {
	echo "\n" . wp_strip_all_tags( $voucher->terms ) . "\n";
}
if ( $voucher->message ) {
	echo "\n" . wp_strip_all_tags( $voucher->message ) . "\n";
}
if ( $download_url ) {
	echo "\n" . __( 'Download:', 'voucher-captain' ) . ' ' . esc_url_raw( $download_url ) . "\n";
}
foreach ( \VCAP\Downloads::voucher_links( $voucher, 'email_plain' ) as $vcap_link ) {
	echo wp_strip_all_tags( $vcap_link['label'] ) . ': ' . esc_url_raw( $vcap_link['url'] ) . "\n";
}
$next_steps = array_filter( array_map( 'strval', (array) apply_filters( 'vcap_voucher_next_steps', array(), $voucher ) ) );
if ( $next_steps ) {
	echo "\n" . __( 'What happens next', 'voucher-captain' ) . "\n";
	foreach ( array_values( $next_steps ) as $i => $step ) {
		echo wp_strip_all_tags( ( $i + 1 ) . '. ' . $step ) . "\n";
	}
	$redeem_url = $voucher->id ? \VCAP\Shortcodes::redeem_url( $voucher->code ) : '';
	if ( $redeem_url ) {
		echo __( 'Redeem now:', 'voucher-captain' ) . ' ' . esc_url_raw( $redeem_url ) . "\n";
	}
} elseif ( '' !== \VCAP\Channels::instructions( $voucher ) ) {
	echo "\n" . wp_strip_all_tags( \VCAP\Channels::instructions( $voucher ) ) . "\n";
}
echo "\n=================================================================\n\n";

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) );
