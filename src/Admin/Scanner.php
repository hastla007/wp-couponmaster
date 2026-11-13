<?php
namespace VCAP\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile/desktop ticket & voucher scanner (camera via BarcodeDetector or jsQR fallback, or manual/USB scanner input).
 */
final class Scanner {
	public function page(): void {
		if ( ! \VCAP\Tickets::can_scan() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		wp_enqueue_style( 'vcap-admin', VCAP_URL . 'assets/css/admin.css', array(), VCAP_VERSION );
		wp_enqueue_script( 'vcap-jsqr', VCAP_URL . 'assets/vendor/jsqr/jsQR.js', array(), '1.4.0', true );
		wp_enqueue_script( 'vcap-scanner-amount', VCAP_URL . 'assets/js/scanner-amount.js', array(), VCAP_VERSION, true );
		wp_enqueue_script( 'vcap-scanner', VCAP_URL . 'assets/js/scanner.js', array( 'vcap-jsqr', 'vcap-scanner-amount' ), VCAP_VERSION, true );
		$currency = get_woocommerce_currency();
		wp_localize_script(
			'vcap-scanner',
			'vcapScanner',
			array(
				'root'  => esc_url_raw( rest_url( 'vcap/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				// Shop defaults for amount entry; each voucher brings its own currency, symbol and decimals (describe()).
				'money' => array(
					'currency'    => $currency,
					'symbol'      => html_entity_decode( get_woocommerce_currency_symbol( $currency ) ),
					'decimals'    => \VCAP\API\V1\RedemptionService::decimals( $currency ),
					'decimalSep'  => wc_get_price_decimal_separator(),
					'thousandSep' => wc_get_price_thousand_separator(),
					'priceFormat' => html_entity_decode( get_woocommerce_price_format() ),
				),
				'i18n'  => array(
					'start'      => __( 'Start camera', 'voucher-captain' ),
					'stop'       => __( 'Stop camera', 'voucher-captain' ),
					'noCamera'   => __( 'No camera available. Use the input field or a USB scanner.', 'voucher-captain' ),
					'checkin'    => __( 'Check in', 'voucher-captain' ),
					'undo'       => __( 'Undo check-in', 'voucher-captain' ),
					'redeem'     => __( 'Redeem', 'voucher-captain' ),
					'amount'     => __( 'Amount to deduct', 'voucher-captain' ),
					'systemError'    => __( 'System error – the code was not checked. Please scan again.', 'voucher-captain' ),
					'sessionExpired' => __( 'Session expired – please reload the page and scan again.', 'voucher-captain' ),
					'admissions' => __( 'Admissions', 'voucher-captain' ),
					'balance'    => __( 'Balance', 'voucher-captain' ),
					'value'      => __( 'Value', 'voucher-captain' ),
					'holder'     => __( 'Holder', 'voucher-captain' ),
					'product'    => __( 'Product', 'voucher-captain' ),
					'order'      => __( 'Order', 'voucher-captain' ),
					'expires'    => __( 'Valid until', 'voucher-captain' ),
					'lastCheckin'=> __( 'Last check-in', 'voucher-captain' ),
					'details'    => __( 'Details', 'voucher-captain' ),
					'autoCheckin'=> __( 'Check in automatically after scan', 'voucher-captain' ),
					'error'      => __( 'Request failed.', 'voucher-captain' ),
					'event'      => __( 'Event', 'voucher-captain' ),
					'ticketType' => __( 'Ticket type', 'voucher-captain' ),
					'date'       => __( 'Date', 'voucher-captain' ),
					'attendee'   => __( 'Attendee', 'voucher-captain' ),
					'passCaptainCheckins' => __( 'Check-ins in WP Pass Captain', 'voucher-captain' ),
					'confirmUndo' => __( 'Revert this check-in? The ticket can then be used for entry again.', 'voucher-captain' ),
					'booked'      => __( 'Deducted', 'voucher-captain' ),
					'uncovered'   => __( 'Still to be paid', 'voucher-captain' ),
					/* translators: 1: amount to deduct, 2: last characters of the voucher code, 3: remaining balance afterwards */
					'confirmRedeem'    => __( 'Deduct %1$s from card …%2$s? Remaining balance afterwards: %3$s', 'voucher-captain' ),
					/* translators: 1: whole remaining balance that will be deducted, 2: last characters of the voucher code, 3: amount still to be paid in another way, 4: remaining balance afterwards */
					'confirmPartial'   => __( 'Deduct only the remaining balance of %1$s from card …%2$s? Still to be paid otherwise: %3$s. Remaining balance afterwards: %4$s', 'voucher-captain' ),
					/* translators: %s: remaining balance of the voucher */
					'bookPartial'      => __( 'Deduct remaining balance only (%s)', 'voucher-captain' ),
					'amountEmpty'      => __( 'Please enter an amount.', 'voucher-captain' ),
					'amountZero'       => __( 'Please enter an amount greater than zero.', 'voucher-captain' ),
					/* translators: %s: example amount, e.g. 12,50 */
					'amountInvalid'    => __( 'Please enter only the amount as a number, e.g. %s.', 'voucher-captain' ),
					/* translators: %s: example amount, e.g. 12,50 */
					'amountAmbiguous'  => __( 'The amount is ambiguous (thousands or decimal separator?). Please enter it without thousands separators, e.g. %s.', 'voucher-captain' ),
					/* translators: %d: number of decimal places of the currency */
					'amountDecimals'   => __( 'This currency has at most %d decimal places.', 'voucher-captain' ),
					'amountNoDecimals' => __( 'This currency has no decimal places.', 'voucher-captain' ),
					/* translators: 1: entered amount, 2: remaining balance of the voucher */
					'amountExceeds'    => __( 'Not deducted: the amount (%1$s) exceeds the remaining balance (%2$s).', 'voucher-captain' ),
				),
			)
		);
		?>
		<div class="wrap vcap-wrap vcap-scanner-wrap">
			<h1><?php esc_html_e( 'Scanner', 'voucher-captain' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Scan ticket or voucher QR codes with the camera of this device, a USB/Bluetooth barcode scanner, or type a code.', 'voucher-captain' ); ?></p>
			<?php if ( \VCAP\Compat\PassCaptainScanner::service() ) : ?>
				<p class="description"><?php esc_html_e( 'Shared scanner: WP Pass Captain tickets are recognised too and checked in at Pass Captain (needs the Pass Captain check-in permission). Voucher codes are always looked up in Voucher Captain first.', 'voucher-captain' ); ?></p>
			<?php endif; ?>
			<div class="vcap-scanner">
				<div class="vcap-scanner-video">
					<video id="vcap-video" playsinline muted aria-label="<?php esc_attr_e( 'Camera preview', 'voucher-captain' ); ?>"></video>
					<canvas id="vcap-canvas" hidden></canvas>
					<button type="button" class="button button-primary button-hero" id="vcap-camera-toggle"><?php esc_html_e( 'Start camera', 'voucher-captain' ); ?></button>
				</div>
				<form id="vcap-manual" class="vcap-scanner-form">
					<label for="vcap-code" class="vcap-code-label"><?php esc_html_e( 'Code', 'voucher-captain' ); ?></label>
					<input type="text" id="vcap-code" autocomplete="off" autofocus placeholder="<?php esc_attr_e( 'Scan or enter code…', 'voucher-captain' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Look up', 'voucher-captain' ); ?></button>
				</form>
				<label class="vcap-auto"><input type="checkbox" id="vcap-auto-checkin" /> <?php esc_html_e( 'Check in tickets automatically after scan', 'voucher-captain' ); ?></label>
				<div id="vcap-result" class="vcap-result"></div>
				<p id="vcap-live" class="screen-reader-text" role="status" aria-live="assertive" aria-atomic="true"></p>
			</div>
		</div>
		<?php
	}
}
