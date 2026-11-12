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
		wp_enqueue_script( 'vcap-jsqr', VCAP_URL . 'assets/vendor/jsQR.js', array(), '1.4.0', true );
		wp_enqueue_script( 'vcap-scanner', VCAP_URL . 'assets/js/scanner.js', array( 'vcap-jsqr' ), VCAP_VERSION, true );
		wp_localize_script(
			'vcap-scanner',
			'vcapScanner',
			array(
				'root'  => esc_url_raw( rest_url( 'vcap/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				'i18n'  => array(
					'start'      => __( 'Start camera', 'voucher-captain' ),
					'stop'       => __( 'Stop camera', 'voucher-captain' ),
					'noCamera'   => __( 'No camera available. Use the input field or a USB scanner.', 'voucher-captain' ),
					'checkin'    => __( 'Check in', 'voucher-captain' ),
					'undo'       => __( 'Undo check-in', 'voucher-captain' ),
					'redeem'     => __( 'Redeem', 'voucher-captain' ),
					'amount'     => __( 'Amount to deduct', 'voucher-captain' ),
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
					<label for="vcap-code" class="screen-reader-text"><?php esc_html_e( 'Code', 'voucher-captain' ); ?></label>
					<input type="text" id="vcap-code" autocomplete="off" autofocus placeholder="<?php esc_attr_e( 'Scan or enter code…', 'voucher-captain' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Look up', 'voucher-captain' ); ?></button>
				</form>
				<label class="vcap-auto"><input type="checkbox" id="vcap-auto-checkin" /> <?php esc_html_e( 'Check in tickets automatically after scan', 'voucher-captain' ); ?></label>
				<div id="vcap-result" class="vcap-result" aria-live="assertive"></div>
			</div>
		</div>
		<?php
	}
}
