<?php
namespace WCVS\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Mobile/desktop ticket & voucher scanner (camera via BarcodeDetector or jsQR fallback, or manual/USB scanner input).
 */
final class Scanner {
	public function page(): void {
		if ( ! \WCVS\Tickets::can_scan() ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wc-voucher-studio' ) );
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
					'start'      => __( 'Start camera', 'wc-voucher-studio' ),
					'stop'       => __( 'Stop camera', 'wc-voucher-studio' ),
					'noCamera'   => __( 'No camera available. Use the input field or a USB scanner.', 'wc-voucher-studio' ),
					'checkin'    => __( 'Check in', 'wc-voucher-studio' ),
					'undo'       => __( 'Undo check-in', 'wc-voucher-studio' ),
					'redeem'     => __( 'Redeem', 'wc-voucher-studio' ),
					'amount'     => __( 'Amount to deduct', 'wc-voucher-studio' ),
					'admissions' => __( 'Admissions', 'wc-voucher-studio' ),
					'balance'    => __( 'Balance', 'wc-voucher-studio' ),
					'value'      => __( 'Value', 'wc-voucher-studio' ),
					'holder'     => __( 'Holder', 'wc-voucher-studio' ),
					'product'    => __( 'Product', 'wc-voucher-studio' ),
					'order'      => __( 'Order', 'wc-voucher-studio' ),
					'expires'    => __( 'Valid until', 'wc-voucher-studio' ),
					'lastCheckin'=> __( 'Last check-in', 'wc-voucher-studio' ),
					'details'    => __( 'Details', 'wc-voucher-studio' ),
					'autoCheckin'=> __( 'Check in automatically after scan', 'wc-voucher-studio' ),
					'error'      => __( 'Request failed.', 'wc-voucher-studio' ),
				),
			)
		);
		?>
		<div class="wrap wcvs-wrap wcvs-scanner-wrap">
			<h1><?php esc_html_e( 'Scanner', 'wc-voucher-studio' ); ?></h1>
			<p class="description"><?php esc_html_e( 'Scan ticket or voucher QR codes with the camera of this device, a USB/Bluetooth barcode scanner, or type a code.', 'wc-voucher-studio' ); ?></p>
			<div class="wcvs-scanner">
				<div class="wcvs-scanner-video">
					<video id="wcvs-video" playsinline muted aria-label="<?php esc_attr_e( 'Camera preview', 'wc-voucher-studio' ); ?>"></video>
					<canvas id="wcvs-canvas" hidden></canvas>
					<button type="button" class="button button-primary button-hero" id="wcvs-camera-toggle"><?php esc_html_e( 'Start camera', 'wc-voucher-studio' ); ?></button>
				</div>
				<form id="wcvs-manual" class="wcvs-scanner-form">
					<label for="wcvs-code" class="screen-reader-text"><?php esc_html_e( 'Code', 'wc-voucher-studio' ); ?></label>
					<input type="text" id="wcvs-code" autocomplete="off" autofocus placeholder="<?php esc_attr_e( 'Scan or enter code…', 'wc-voucher-studio' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Look up', 'wc-voucher-studio' ); ?></button>
				</form>
				<label class="wcvs-auto"><input type="checkbox" id="wcvs-auto-checkin" /> <?php esc_html_e( 'Check in tickets automatically after scan', 'wc-voucher-studio' ); ?></label>
				<div id="wcvs-result" class="wcvs-result" aria-live="assertive"></div>
			</div>
		</div>
		<?php
	}
}
