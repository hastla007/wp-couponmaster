<?php
/**
 * Bundled voucher email (plain text, Z6): several vouchers of one order with a code list.
 *
 * @var \VCAP\Voucher[]    $vouchers
 * @var \VCAP\Voucher      $voucher
 * @var string             $email_heading
 * @var string             $intro
 * @var string             $additional_content
 * @var bool               $pdf_attached
 * @var array<int, string> $download_urls
 * @var string             $account_url
 * @var bool               $show_product
 * @var bool               $show_holder
 */

defined( 'ABSPATH' ) || exit;

echo "=================================================================\n";
echo esc_html( wp_strip_all_tags( $email_heading ) ) . "\n";
echo "=================================================================\n\n";

echo esc_html( wp_strip_all_tags( wptexturize( $intro ) ) ) . "\n\n";

if ( $pdf_attached ) {
	echo esc_html__( 'All vouchers are attached as one PDF, one page per voucher, ready to print.', 'voucher-captain' ) . "\n\n";
}

foreach ( array_values( $vouchers ) as $vcap_i => $vcap_v ) {
	$vcap_parts = array( ( $vcap_i + 1 ) . '. ' . $vcap_v->code );
	if ( $show_product && $vcap_v->product() ) {
		$vcap_parts[] = wp_strip_all_tags( $vcap_v->product()->get_name() );
	}
	if ( ! $vcap_v->is_ticket() || $vcap_v->initial_amount > 0 ) {
		$vcap_parts[] = 'percent' === $vcap_v->discount_type ? \VCAP\Format::percent( $vcap_v->initial_amount ) : html_entity_decode( wp_strip_all_tags( wc_price( $vcap_v->initial_amount, array( 'currency' => $vcap_v->currency ) ) ) );
	}
	if ( $vcap_v->expires_at ) {
		/* translators: %s: date */
		$vcap_parts[] = sprintf( __( 'Valid until %s', 'voucher-captain' ), wp_date( vcap()->settings->date_format(), strtotime( $vcap_v->expires_at . ' UTC' ) ) );
	}
	if ( $show_holder && \VCAP\Delivery::to_recipient( $vcap_v ) ) {
		$vcap_parts[] = '' !== trim( $vcap_v->recipient_name ) ? $vcap_v->recipient_name : $vcap_v->recipient_email;
	}
	echo esc_html( implode( ' · ', $vcap_parts ) ) . "\n";
	if ( ! empty( $download_urls[ $vcap_v->id ] ) ) {
		echo '   ' . esc_html__( 'Download:', 'voucher-captain' ) . ' ' . esc_url_raw( $download_urls[ $vcap_v->id ] ) . "\n";
	}
}

if ( ! $voucher->transferable ) {
	echo "\n" . esc_html( $voucher->transferable_label() ) . "\n";
}
$vcap_terms = count( array_unique( array_map( static fn( $v ) => $v->terms, $vouchers ) ) ) === 1 ? $voucher->terms : '';
if ( '' !== $vcap_terms ) {
	echo "\n" . esc_html( $vcap_terms ) . "\n";
}
$vcap_message = count( array_unique( array_map( static fn( $v ) => $v->message, $vouchers ) ) ) === 1 ? $voucher->message : '';
if ( $vcap_message ) {
	echo "\n" . esc_html( $vcap_message ) . "\n";
}
if ( $account_url ) {
	echo "\n" . esc_html__( 'All vouchers in your account:', 'voucher-captain' ) . ' ' . esc_url_raw( $account_url ) . "\n";
}
if ( '' !== \VCAP\Channels::instructions( $voucher ) ) {
	echo "\n" . esc_html( \VCAP\Channels::instructions( $voucher ) ) . "\n";
}
echo "\n=================================================================\n\n";

if ( $additional_content ) {
	echo esc_html( wp_strip_all_tags( wptexturize( $additional_content ) ) ) . "\n\n";
}

echo wp_kses_post( apply_filters( 'woocommerce_email_footer_text', get_option( 'woocommerce_email_footer_text' ) ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce filter.
