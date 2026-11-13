<?php
namespace VCAP;

use VCAP\Licensing\Entitlements;

defined( 'ABSPATH' ) || exit;

/**
 * Support information in Tools → Site Health → Info → "Voucher Captain" (also part of "Copy site info to clipboard"):
 * edition, license status, snapshot age, locked features, database version, schema errors, WP Pass Captain version,
 * the last run of each daily maintenance step, the number of given-up deliveries and the "multi-purpose vouchers as
 * payment" setting. Contains no license key, secret or customer data.
 */
final class Diagnostics {
	public function register(): void {
		add_filter( 'debug_information', array( $this, 'debug_information' ) );
	}

	public function debug_information( $info ) {
		if ( ! is_array( $info ) ) {
			return $info;
		}
		$info['voucher-captain'] = array(
			'label'  => __( 'Voucher Captain', 'voucher-captain' ),
			'fields' => $this->fields(),
		);
		return $info;
	}

	/** @return array<string, array{label:string, value:string, debug?:string}> */
	public function fields(): array {
		$edition = Entitlements::edition();
		$editions = array(
			Entitlements::FREE        => __( 'Free', 'voucher-captain' ),
			Entitlements::PREMIUM     => __( 'Premium', 'voucher-captain' ),
			Entitlements::DEVELOPMENT => __( 'Development (all features)', 'voucher-captain' ),
		);
		$fields = array(
			'version' => array(
				'label' => __( 'Version', 'voucher-captain' ),
				'value' => defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '',
			),
			'edition' => array(
				'label' => __( 'Edition', 'voucher-captain' ),
				'value' => $editions[ $edition ] ?? $edition,
				'debug' => $edition,
			),
		);
		if ( Entitlements::is_premium() ) {
			$fields += $this->license_fields();
		}
		$locked = Entitlements::locked();
		$fields['locked_features'] = array(
			'label' => __( 'Locked premium features', 'voucher-captain' ),
			'value' => $locked ? implode( ', ', array_map( array( Entitlements::class, 'label' ), $locked ) ) : __( 'None', 'voucher-captain' ),
			'debug' => $locked ? implode( ', ', $locked ) : 'none',
		);
		$db     = (string) get_option( Install::OPTION_DB_VERSION, '' );
		$schema = get_option( Install::SCHEMA_ERROR_OPTION, '' );
		$fields['db_version'] = array(
			'label' => __( 'Database version', 'voucher-captain' ),
			/* translators: 1: installed database version, 2: version this plugin version expects */
			'value' => sprintf( __( '%1$s (expected: %2$s)', 'voucher-captain' ), '' !== $db ? $db : '—', defined( 'VCAP_DB_VERSION' ) ? VCAP_DB_VERSION : '—' ),
		);
		$fields['schema_error'] = array(
			'label' => __( 'Database setup error', 'voucher-captain' ),
			'value' => '' !== $schema && false !== $schema ? ( is_scalar( $schema ) ? (string) $schema : (string) wp_json_encode( $schema ) ) : __( 'None', 'voucher-captain' ),
		);
		$fields['pass_captain'] = array(
			'label' => __( 'WP Pass Captain', 'voucher-captain' ),
			'value' => Compat\PassCaptain::active() ? ( defined( 'WPCAP_VERSION' ) ? (string) WPCAP_VERSION : __( 'Active', 'voucher-captain' ) ) : __( 'Not active', 'voucher-captain' ),
		);
		$fields['maintenance'] = array(
			'label' => __( 'Daily maintenance (last run per step)', 'voucher-captain' ),
			'value' => self::maintenance_summary(),
		);
		$fields['deliveries_given_up'] = array(
			'label' => __( 'Deliveries given up after repeated failures', 'voucher-captain' ),
			'value' => (string) vcap()->vouchers->given_up_count(),
		);
		$fields['payment_after_tax'] = array(
			'label' => __( 'Multi-purpose vouchers as payment', 'voucher-captain' ),
			'value' => PaymentVouchers::enabled() ? __( 'On', 'voucher-captain' ) : __( 'Off', 'voucher-captain' ),
			'debug' => PaymentVouchers::enabled() ? 'yes' : 'no',
		);
		return $fields;
	}

	/** "step: date ok|failed (error)" per maintenance step (Delivery::MAINTENANCE_STEPS), technical names for support. */
	private static function maintenance_summary(): string {
		$parts = array();
		foreach ( Delivery::last_runs() as $step => $run ) {
			if ( ! $run ) {
				$parts[] = $step . ': —';
				continue;
			}
			$state   = $run['ok'] ? 'ok' : 'failed';
			$note    = '' !== (string) $run['note'] ? ' (' . $run['note'] . ')' : '';
			$parts[] = $step . ': ' . gmdate( 'Y-m-d H:i', (int) $run['time'] ) . ' UTC ' . $state . $note;
		}
		return implode( '; ', $parts );
	}

	/** License status of the signed local snapshot (no server call while Site Health loads). */
	private function license_fields(): array {
		$credentials = get_option( Entitlements::CREDENTIALS_OPTION, array() );
		$client      = Entitlements::client();
		$snapshot    = $client ? $client->cached_entitlements() : null;
		$cache       = get_option( Entitlements::CREDENTIALS_OPTION . '_entitlements_cache', array() );
		$cached_at   = is_array( $cache ) ? absint( $cache['cached_at'] ?? 0 ) : 0;
		if ( ! is_array( $credentials ) || empty( $credentials['activation_id'] ) ) {
			$status = __( 'Not activated', 'voucher-captain' );
		} elseif ( is_array( $snapshot ) ) {
			$status = Licensing\AdminPage::status_label( (string) ( $snapshot['license_status'] ?? '' ) );
		} else {
			$status = __( 'No valid license snapshot (premium features locked)', 'voucher-captain' );
		}
		return array(
			'license_server' => array(
				'label' => __( 'License server', 'voucher-captain' ),
				'value' => Entitlements::server_url(),
			),
			'license_status' => array(
				'label' => __( 'License status', 'voucher-captain' ),
				'value' => $status,
			),
			'snapshot_age'   => array(
				'label' => __( 'License snapshot age', 'voucher-captain' ),
				'value' => $cached_at ? human_time_diff( $cached_at, time() ) : '—',
				'debug' => $cached_at ? (string) ( time() - $cached_at ) . 's' : 'none',
			),
		);
	}
}
