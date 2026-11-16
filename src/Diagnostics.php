<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Support information in Tools → Site Health → Info → "Voucher Captain" (also part of "Copy site info to clipboard"):
 * version, database version, schema errors, WP Pass Captain version, the last run of each daily maintenance step,
 * given-up and undelivered deliveries, failed Action Scheduler tasks, open reservations, open cases (e.g. paid orders
 * without voucher), shortfalls, InnoDB, legacy rows, integrations, issuing statuses, retention, verbose log and the
 * "multi-purpose vouchers as payment" setting (D10). Contains no secret or customer data.
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
		$fields = array(
			'version' => array(
				'label' => __( 'Version', 'voucher-captain' ),
				'value' => defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '',
			),
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
			'value' => self::integrations()['passcaptain'] ?? __( 'Not active', 'voucher-captain' ),
		);
		$fields['maintenance'] = array(
			'label' => __( 'Daily maintenance (last run per step)', 'voucher-captain' ),
			'value' => self::maintenance_summary(),
		);
		$fields['deliveries_given_up'] = array(
			'label' => __( 'Deliveries given up after repeated failures', 'voucher-captain' ),
			'value' => (string) vcap()->vouchers->given_up_count(),
		);
		$fields['undelivered'] = array(
			'label' => __( 'Vouchers due but not delivered', 'voucher-captain' ),
			'value' => (string) vcap()->vouchers->undelivered_count(),
		);
		$fields['failed_actions'] = array(
			'label' => __( 'Failed voucher tasks (Action Scheduler, group "vcap", last 7 days)', 'voucher-captain' ),
			'value' => (string) self::failed_actions(),
		);
		$fields['open_reservations'] = array(
			'label' => __( 'Open external reservations', 'voucher-captain' ),
			'value' => (string) self::open_reservations(),
		);
		$cases = array_count_values( array_map( static fn( $c ) => preg_replace( '/:.*$/', '', (string) $c['kind'] ), Attention::all() ) );
		ksort( $cases );
		$fields['attention'] = array(
			'label' => __( 'Open cases for the shop (issue = paid order without voucher, void = void failed, …)', 'voucher-captain' ),
			'value' => $cases ? implode( ', ', array_map( static fn( $k, $n ) => $k . ': ' . $n, array_keys( $cases ), $cases ) ) : __( 'None', 'voucher-captain' ),
		);
		$fields['shortfalls'] = array(
			'label' => __( 'Orders held for a voucher shortfall', 'voucher-captain' ),
			'value' => (string) self::shortfall_orders(),
		);
		$innodb = (string) get_option( Install::INNODB_OPTION, '' );
		$fields['innodb'] = array(
			'label' => __( 'Voucher tables support transactions (InnoDB)', 'voucher-captain' ),
			'value' => 'yes' === $innodb ? __( 'Yes', 'voucher-captain' ) : ( 'no' === $innodb ? __( 'No', 'voucher-captain' ) : '—' ),
			'debug' => '' !== $innodb ? $innodb : 'unknown',
		);
		$fields['legacy_rows'] = array(
			'label' => __( 'Rows still to move to the voucher tables', 'voucher-captain' ),
			'value' => Install::legacy_rows() ? __( 'Yes (moved by the daily maintenance)', 'voucher-captain' ) : __( 'No', 'voucher-captain' ),
		);
		$fields['adapters'] = array(
			'label' => __( 'Active integrations', 'voucher-captain' ),
			'value' => self::adapters(),
		);
		$statuses = array_map( 'sanitize_key', (array) get_option( 'vcap_issue_statuses', array() ) );
		$fields['issuing'] = array(
			'label' => __( 'Issue vouchers at order status', 'voucher-captain' ),
			'value' => $statuses ? implode( ', ', $statuses ) : '—',
		);
		$retention = (int) get_option( 'vcap_retention_days', 0 );
		$fields['retention'] = array(
			'label' => __( 'Personal data retention (days after the voucher ended)', 'voucher-captain' ),
			'value' => $retention > 0 ? (string) $retention : __( 'Off', 'voucher-captain' ),
		);
		$fields['verbose_log'] = array(
			'label' => __( 'Verbose log', 'voucher-captain' ),
			'value' => Logger::verbose() ? __( 'On', 'voucher-captain' ) : __( 'Off', 'voucher-captain' ),
			'debug' => Logger::verbose() ? 'on' : 'off',
		);
		$fields['payment_after_tax'] = array(
			'label' => __( 'Multi-purpose vouchers as payment', 'voucher-captain' ),
			'value' => PaymentVouchers::enabled() ? __( 'On', 'voucher-captain' ) : __( 'Off', 'voucher-captain' ),
			'debug' => PaymentVouchers::enabled() ? 'yes' : 'no',
		);
		return $fields;
	}

	/** Failed Action Scheduler actions of the group "vcap" in the last 7 days (at most 100 counted). */
	private static function failed_actions(): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( '\ActionScheduler_Store' ) ) {
			return 0;
		}
		return count(
			(array) as_get_scheduled_actions(
				array(
					'group'        => Delivery::GROUP,
					'status'       => \ActionScheduler_Store::STATUS_FAILED,
					'date'         => gmdate( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS ),
					'date_compare' => '>=',
					'per_page'     => 100,
				),
				'ids'
			)
		);
	}

	private static function open_reservations(): int {
		global $wpdb;
		$table = Install::tables()['redemptions'];
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE state = %s", API\V1\RedemptionService::STATE_RESERVED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	private static function shortfall_orders(): int {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		return count( (array) wc_get_orders( array( 'limit' => 100, 'return' => 'ids', 'meta_key' => CouponManager::SHORTFALL_META, 'meta_value' => 'yes' ) ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	/**
	 * Integrations that report themselves (D14: the core does not ask the Compat classes): id => version or label,
	 * e.g. "currency" => switcher name, "passcaptain" => version.
	 *
	 * @return array<string, string>
	 */
	private static function integrations(): array {
		/** Active integrations for the diagnostics (id => version or label), added by the integrations themselves. */
		$active = apply_filters( 'vcap_active_integrations', array() );
		return is_array( $active ) ? array_map( 'strval', $active ) : array();
	}

	/** Integrations in use: currency switcher, WP Pass Captain, multilingual plugin, subscriptions. */
	private static function adapters(): string {
		$active       = array();
		$integrations = self::integrations();
		if ( '' !== ( $integrations['currency'] ?? '' ) ) {
			$active[] = 'currency: ' . $integrations['currency'];
		}
		if ( isset( $integrations['passcaptain'] ) ) {
			$active[] = 'passcaptain';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			$active[] = 'wpml';
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$active[] = 'polylang';
		}
		if ( class_exists( '\WC_Subscriptions' ) ) {
			$active[] = 'subscriptions';
		}
		return $active ? implode( ', ', $active ) : __( 'None', 'voucher-captain' );
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
}
