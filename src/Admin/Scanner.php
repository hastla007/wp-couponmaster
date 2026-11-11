<?php
namespace WCVS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile/desktop ticket & voucher scanner (camera via BarcodeDetector or jsQR fallback, or manual/USB scanner input).
 */
final class Scanner {
	public function page(): void {
		if ( ! \WCVS\Tickets::can_scan() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ) );
		}
		wp_enqueue_style( 'wcvs-admin', WCVS_URL . 'assets/css/admin.css', array(), WCVS_VERSION );
		wp_enqueue_script( 'wcvs-jsqr', WCVS_URL . 'assets/vendor/jsQR.js', array(), '1.4.0', true );
		wp_enqueue_script( 'wcvs-scanner', WCVS_URL . 'assets/js/scanner.js', array( 'wcvs-jsqr' ), WCVS_VERSION, true );
		wp_localize_script(
			'wcvs-scanner',
			'wcvsScanner',
			array(
				'root'  => esc_url_raw( rest_url( 'wcvs/v1/' ) ),
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
				),
			)
		);
		?>
		<div class="wrap wcvs-wrap wcvs-scanner-wrap">
			<h1><?php esc_html_e( 'Scanner', 'voucher-captain' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Scan ticket or voucher QR codes with the camera of this device, a USB/Bluetooth barcode scanner, or type a code.', 'voucher-captain' ); ?></p>
			<div class="wcvs-scanner">
				<div class="wcvs-scanner-video">
					<video id="wcvs-video" playsinline muted aria-label="<?php esc_attr_e( 'Camera preview', 'voucher-captain' ); ?>"></video>
					<canvas id="wcvs-canvas" hidden></canvas>
					<button type="button" class="button button-primary button-hero" id="wcvs-camera-toggle"><?php esc_html_e( 'Start camera', 'voucher-captain' ); ?></button>
				</div>
				<form id="wcvs-manual" class="wcvs-scanner-form">
					<label for="wcvs-code" class="screen-reader-text"><?php esc_html_e( 'Code', 'voucher-captain' ); ?></label>
					<input type="text" id="wcvs-code" autocomplete="off" autofocus placeholder="<?php esc_attr_e( 'Scan or enter code…', 'voucher-captain' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Look up', 'voucher-captain' ); ?></button>
				</form>
				<label class="wcvs-auto"><input type="checkbox" id="wcvs-auto-checkin" /> <?php esc_html_e( 'Check in tickets automatically after scan', 'voucher-captain' ); ?></label>
				<div id="wcvs-result" class="wcvs-result" aria-live="assertive"></div>
			</div>
		</div>
		<?php
	}
}
