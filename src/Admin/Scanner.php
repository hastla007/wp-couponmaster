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
		$currency  = get_woocommerce_currency();
		$locations = \VCAP\Counter::locations();
		$fixed     = \VCAP\Counter::fixed_location( get_current_user_id() );
		wp_localize_script(
			'vcap-scanner',
			'vcapScanner',
			array(
				'root'  => esc_url_raw( rest_url( 'vcap/v1/' ) ),
				'nonce' => wp_create_nonce( 'wp_rest' ),
				// Fresh REST nonce for long shifts (core's "rest-nonce" Ajax action, same login session).
				'nonceUrl' => admin_url( 'admin-ajax.php?action=rest-nonce' ),
				// M12-81: managed tills/branches and the one this staff member is fixed to ('' = chosen on the device).
				'locations' => $locations,
				'fixedTill' => $fixed,
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
					'noCameraHttps' => __( 'The camera only works on HTTPS pages. Open the scanner via https:// – or use the input field or a USB scanner.', 'voucher-captain' ),
					'cameraDenied'  => __( 'Camera access was denied. Allow the camera for this site in the browser settings – or use the input field or a USB scanner.', 'voucher-captain' ),
					'checking'      => __( 'Checking…', 'voucher-captain' ),
					'unclear'       => __( 'Result unclear: no answer from the server. The booking may have been made – query the code again before repeating it.', 'voucher-captain' ),
					'queryAgain'    => __( 'Query again', 'voucher-captain' ),
					'undoReason'    => __( 'Reason for reverting this check-in (required):', 'voucher-captain' ),
					'reverse'       => __( 'Cancel this redemption', 'voucher-captain' ),
					'reverseReason' => __( 'Reason for cancelling this redemption (required), e.g. wrong card or wrong amount:', 'voucher-captain' ),
					'reasonRequired' => __( 'Nothing was changed: a reason is required.', 'voucher-captain' ),
					'receipt'       => __( 'Receipt no.', 'voucher-captain' ),
					'print'         => __( 'Print', 'voucher-captain' ),
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
					'replayed'         => __( 'This is the answer to an earlier request with the same scan – nothing was booked again.', 'voucher-captain' ),
					'personal'         => __( 'Personal voucher – check the ID:', 'voucher-captain' ),
					'idChecked'        => __( 'ID checked', 'voucher-captain' ),
					'idMissing'        => __( 'Not redeemed: this voucher is personal. Check the holder\'s ID and tick "ID checked".', 'voucher-captain' ),
					'receiptRequired'  => __( 'Receipt no. (required)', 'voucher-captain' ),
					'receiptMissing'   => __( 'Not redeemed: please enter the receipt number of the till receipt.', 'voucher-captain' ),
					'tillMissing'      => __( 'Please choose the till/branch of this device from the list.', 'voucher-captain' ),
					'vat'              => __( 'VAT', 'voucher-captain' ),
					/* translators: 1: amount, 2: date and time of the redemption */
					'reverseOne'       => __( 'Cancel my redemption of %1$s (%2$s)', 'voucher-captain' ),
					'cameraPaused'     => __( 'The camera was paused by the browser. Tap "Start camera" again.', 'voucher-captain' ),
					'slipTitle'        => __( 'Voucher redemption', 'voucher-captain' ),
					'slipCode'         => __( 'Voucher', 'voucher-captain' ),
					'slipTime'         => __( 'Time', 'voucher-captain' ),
					'slipStaff'        => __( 'Staff member', 'voucher-captain' ),
					'slipTill'         => __( 'Till / branch', 'voucher-captain' ),
					'slipBooking'      => __( 'Booking no.', 'voucher-captain' ),
					'slipAmount'       => __( 'Redeemed', 'voucher-captain' ),
					'slipNote'         => __( 'This slip is not a till receipt. The sale is documented by the till receipt with the number above.', 'voucher-captain' ),
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
					<button type="button" class="button" id="vcap-torch" aria-pressed="false" hidden><?php esc_html_e( 'Light', 'voucher-captain' ); ?></button>
				</div>
				<form id="vcap-manual" class="vcap-scanner-form">
					<label for="vcap-code" class="vcap-code-label"><?php esc_html_e( 'Code', 'voucher-captain' ); ?></label>
					<input type="text" id="vcap-code" autocomplete="off" placeholder="<?php esc_attr_e( 'Scan or enter code…', 'voucher-captain' ); ?>" />
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Look up', 'voucher-captain' ); ?></button>
				</form>
				<label class="vcap-auto"><input type="checkbox" id="vcap-auto-checkin" /> <?php esc_html_e( 'Check in tickets automatically after scan', 'voucher-captain' ); ?></label>
				<p class="vcap-till"><label for="vcap-till"><?php esc_html_e( 'Till / branch of this device', 'voucher-captain' ); ?></label>
				<?php if ( $locations ) : ?>
					<select id="vcap-till" <?php disabled( '' !== $fixed ); ?>>
						<option value=""><?php esc_html_e( '— choose —', 'voucher-captain' ); ?></option>
						<?php foreach ( $locations as $location ) : ?>
							<option value="<?php echo esc_attr( $location ); ?>" <?php selected( $fixed, $location ); ?>><?php echo esc_html( $location ); ?></option>
						<?php endforeach; ?>
					</select>
					<span class="description"><?php echo esc_html( '' !== $fixed ? __( 'Fixed for your account.', 'voucher-captain' ) : __( 'Saved on this device and kept with every redemption and check-in.', 'voucher-captain' ) ); ?></span></p>
				<?php else : ?>
					<input type="text" id="vcap-till" class="regular-text" maxlength="40" autocomplete="off" /> <span class="description"><?php esc_html_e( 'Saved on this device and kept with every redemption and check-in.', 'voucher-captain' ); ?></span></p>
				<?php endif; ?>
				<p class="description vcap-online-only"><?php esc_html_e( 'The scanner works online only: without a connection nothing is booked, so no code can be redeemed twice.', 'voucher-captain' ); ?></p>
				<div id="vcap-result" class="vcap-result"></div>
				<p id="vcap-live" class="screen-reader-text" role="status" aria-live="assertive" aria-atomic="true"></p>
			</div>
			<div id="vcap-slip" class="vcap-slip" aria-hidden="true"></div>
		</div>
		<?php
	}
}
