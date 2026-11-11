<?php
/**
 * The release ZIP ships its own, prefixed copy of libraries other plugins also bundle.
 *   wp eval-file tests/scoping-integration.php
 */

function vcap_scope_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

vcap_scope_assert( class_exists( 'VCAP\\Vendor\\Sabberworm\\CSS\\Parser' ), 'the CSS parser used for PDFs is loaded from the VCAP\\Vendor namespace' );
$plugin_dir = WP_PLUGIN_DIR . '/voucher-captain/vendor/sabberworm';
$leftover   = is_dir( $plugin_dir ) ? (string) shell_exec( 'grep -rlE ' . escapeshellarg( '(^|[^\\\\])Sabberworm\\\\CSS' ) . ' ' . escapeshellarg( $plugin_dir ) ) : '';
vcap_scope_assert( '' === trim( $leftover ), 'no unprefixed Sabberworm namespace is left in the plugin' );

$svg  = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40"><style>.a{fill:#c00}</style><rect class="a" width="40" height="40"/></svg>';
$pdf  = new \Dompdf\Dompdf();
$pdf->loadHtml( '<html><head><style>p{color:#333}</style></head><body><p>Scoped</p><img src="data:image/svg+xml;base64,' . base64_encode( $svg ) . '"></body></html>' );
$pdf->render();
vcap_scope_assert( str_starts_with( $pdf->output(), '%PDF' ), 'a PDF with CSS and SVG styles renders with the scoped parser' );

// WooCommerce formats its emails with its own CSS tools; both must work in the same request.
$mailer = WC()->mailer();
$html   = $mailer->wrap_message( 'Scoping check', '<p style="color:red">Hello</p>' );
$styled = ( new WC_Email() )->style_inline( $html );
vcap_scope_assert( is_string( $styled ) && '' !== $styled, 'WooCommerce email styling still works after a PDF was rendered' );
echo "Voucher Captain scoping checks passed.\n";
