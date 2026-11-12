<?php
namespace VCAP\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Shared scanner: Voucher Captain's scanner (Vouchers → Scanner) also recognises WP Pass Captain tickets and hands
 * them to Pass Captain's versioned check-in service ("wp_pass_captain_checkin_service_v1"). Voucher codes are always
 * looked up in Voucher Captain first; only codes it does not know are offered to Pass Captain. Voucher redemption and
 * ticket check-in stay separate actions with each plugin's own rules, locks, audit and capability (Pass Captain
 * checks "wpcap_checkin" itself).
 */
final class PassCaptainScanner {
	public const FILTER = 'wp_pass_captain_checkin_service_v1';

	public function register(): void {
		add_filter( 'vcap_scanner_lookup', array( $this, 'lookup' ), 10, 2 );
		add_filter( 'vcap_scanner_checkin', array( $this, 'checkin' ), 10, 2 );
	}

	/** Pass Captain's check-in service (contract v1), or null. */
	public static function service(): ?object {
		$service = apply_filters( self::FILTER, null, 1 );
		return is_object( $service ) && method_exists( $service, 'recognizes' ) && method_exists( $service, 'preview' ) && method_exists( $service, 'check_in' ) ? $service : null;
	}

	public function lookup( $result, $query ) {
		$service = self::service();
		if ( null !== $result || ! $service || ! $service->recognizes( (string) $query ) ) {
			return $result;
		}
		return self::view( $service->preview( (string) $query ), false );
	}

	public function checkin( $result, $query ) {
		$service = self::service();
		if ( null !== $result || ! $service || ! $service->recognizes( (string) $query ) ) {
			return $result;
		}
		return self::view( $service->check_in( (string) $query ), true );
	}

	/**
	 * Scanner payload for a Pass Captain result (type "passcaptain"; rendered by assets/js/scanner.js).
	 *
	 * @param array|\WP_Error $res     Preview or check-in result.
	 * @param bool            $checkin Whether a check-in was attempted.
	 */
	public static function view( $res, bool $checkin ): array {
		$service = self::service();
		$base    = array(
			'type'        => 'passcaptain',
			'type_label'  => __( 'WP Pass Captain ticket', 'voucher-captain' ),
			'code'        => '',
			'can_checkin' => false,
			'admin_url'   => $service && method_exists( $service, 'admin_url' ) ? (string) $service->admin_url() : '',
		);
		if ( is_wp_error( $res ) ) {
			$data   = (array) $res->get_error_data();
			$status = (string) ( $data['result'] ?? $res->get_error_code() );
			$warn   = in_array( $status, array( 'already_checked_in', 'already_inside', 'daily_limit_reached', 'entry_limit_reached' ), true );
			return $base + array(
				'state'   => $warn ? 'warning' : 'error',
				'status'  => $status,
				'message' => $res->get_error_message(),
				'success' => false,
			);
		}
		$status = (string) ( $res['status'] ?? '' );
		$valid  = 'valid' === $status;
		$done   = $checkin && 'checked_in' === $status;
		if ( $done ) {
			$message = ! empty( $res['replayed'] ) ? __( 'Already checked in by this scan', 'voucher-captain' ) : __( 'Checked in', 'voucher-captain' );
		} elseif ( $valid ) {
			$message = __( 'Valid ticket', 'voucher-captain' );
		} elseif ( 'checked_in' === $status ) {
			$message = __( 'Already checked in', 'voucher-captain' );
		} else {
			/* translators: %s: Pass Captain ticket status */
			$message = sprintf( __( 'Not admitted (%s)', 'voucher-captain' ), $status );
		}
		$start = '';
		if ( ! empty( $res['starts_at'] ) ) {
			try {
				$tz    = new \DateTimeZone( (string) ( $res['timezone'] ?? 'UTC' ) ?: 'UTC' );
				$start = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $res['starts_at'] ), $tz );
			} catch ( \Exception $e ) {
				$start = (string) $res['starts_at'];
			}
		}
		return $base + array(
			'state'       => $valid || $done ? 'ok' : ( 'checked_in' === $status ? 'warning' : 'error' ),
			'status'      => $status,
			'message'     => $message,
			'event'       => (string) ( $res['event'] ?? '' ),
			'ticket'      => (string) ( $res['ticket'] ?? '' ),
			'starts'      => $start,
			'attendee'    => (string) ( $res['attendee_name'] ?? '' ),
			'can_checkin' => $valid,
			'success'     => $done,
		);
	}
}
