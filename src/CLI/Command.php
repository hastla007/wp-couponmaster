<?php
namespace VCAP\CLI;

use VCAP\Admin\DataRemoval;
use VCAP\CountryProfiles;
use VCAP\Delivery;
use VCAP\Diagnostics;
use VCAP\Health;
use VCAP\Install;
use VCAP\Ledger;
use VCAP\Plugin;
use VCAP\SettingsLog;
use VCAP\SettingsRegistry;
use VCAP\SettingsTransfer;
use VCAP\Source;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Support and maintenance from the command line (D9): works when WP-Cron is off, the admin is locked out or there
 * is too much data for a web request. Registered only under WP-CLI ("wp vcap …").
 */
final class Command {
	/** Registers "wp vcap" (called only when WP_CLI is defined). */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI || ! class_exists( '\WP_CLI' ) ) {
			return;
		}
		\WP_CLI::add_command( 'vcap', self::class );
	}

	/**
	 * Checks the setup: database schema, Site Health checks of Voucher Captain and the diagnostics.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vcap doctor
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function doctor( $args = array(), $assoc_args = array() ): void {
		$problems = Install::verify_schema();
		self::line( $problems ? 'Schema: ' . implode( '; ', $problems ) : 'Schema: complete' );
		$health = new Health();
		$worst  = 'good';
		foreach ( (array) $health->tests( array() ) as $group ) {
			foreach ( (array) $group as $id => $test ) {
				$callback = $test['async_direct_test'] ?? $test['test'] ?? null;
				if ( ! is_callable( $callback ) ) {
					continue;
				}
				$result = (array) call_user_func( $callback );
				$status = (string) ( $result['status'] ?? '' );
				$worst  = 'critical' === $status || ( 'recommended' === $status && 'good' === $worst ) ? $status : $worst;
				self::line( sprintf( '%-26s %-12s %s', $id, $status, wp_strip_all_tags( (string) ( $result['label'] ?? '' ) ) ) );
			}
		}
		if ( $problems || 'critical' === $worst ) {
			self::error( 'Voucher Captain needs attention (see above).' );
			return;
		}
		self::success( 'good' === $worst ? 'Everything looks fine.' : 'Working, with recommendations (see above).' );
	}

	/**
	 * Prints the support information of Site Health → Info → Voucher Captain.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, json or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function diagnostics( $args = array(), $assoc_args = array() ): void {
		$rows = array();
		foreach ( ( new Diagnostics() )->fields() as $key => $field ) {
			$rows[] = array( 'key' => $key, 'label' => $field['label'], 'value' => $field['value'] );
		}
		self::table( $rows, array( 'key', 'label', 'value' ), (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/**
	 * Runs the database upgrade and the data migrations now (also moves remaining legacy rows and recounts promotion use counters that differ).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function migrate( $args = array(), $assoc_args = array() ): void {
		Source::with(
			Source::CLI,
			static function (): void {
				Install::maybe_upgrade();
				if ( Install::legacy_rows() || Install::legacy_throttle_rows() ) {
					Install::move_legacy_rows_locked( 60.0 );
				}
				// B12: promotion use counters recounted where they differ from the usage table (e.g. after a rollback).
				\VCAP\Discounts\Usage::reconcile();
			}
		);
		$version = (string) get_option( Install::OPTION_DB_VERSION, '' );
		if ( VCAP_DB_VERSION !== $version ) {
			self::error( sprintf( 'Database version %s, expected %s: %s', '' !== $version ? $version : '—', VCAP_DB_VERSION, (string) get_option( Install::SCHEMA_ERROR_OPTION, 'migration continues on the next run' ) ) );
			return;
		}
		self::success( sprintf( 'Database version %s.%s', $version, Install::legacy_rows() ? ' Legacy rows are left; run again.' : '' ) );
	}

	/**
	 * Delivers vouchers now: the given ones, or every due undelivered voucher (the daily safety net).
	 *
	 * ## OPTIONS
	 *
	 * [<voucher_id>...]
	 * : Voucher ids. Without ids, all due vouchers are delivered.
	 *
	 * [--budget=<seconds>]
	 * : Time budget of the safety net run.
	 * ---
	 * default: 300
	 * ---
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function deliver( $args = array(), $assoc_args = array() ): void {
		$delivery = vcap()->delivery;
		if ( ! $args ) {
			$done = $delivery->deliver_due( max( 1.0, (float) ( $assoc_args['budget'] ?? 300 ) ) );
			self::success( $done ? 'All due vouchers were handled.' : 'Time budget used up; run again for the rest.' );
			return;
		}
		foreach ( array_map( 'absint', $args ) as $id ) {
			try {
				$delivery->deliver( $id );
			} catch ( \Throwable $e ) {
				self::warning( sprintf( 'Voucher %d: %s', $id, $e->getMessage() ) );
				continue;
			}
			$voucher = vcap()->vouchers->get( $id );
			self::line( sprintf( 'Voucher %d: %s', $id, $voucher && $voucher->delivered_at ? 'delivered ' . $voucher->delivered_at . ' UTC' : 'not delivered (see its history)' ) );
		}
	}

	/**
	 * Issues the missing vouchers of orders (like the order action "Generate vouchers"), or with --amount or
	 * --product one voucher without an order (like Vouchers → New voucher; marked as not sold).
	 *
	 * ## OPTIONS
	 *
	 * [<order_id>...]
	 * : Order ids.
	 *
	 * [--amount=<amount>]
	 * : Value of a voucher without an order.
	 *
	 * [--email=<email>]
	 * : Recipient email.
	 *
	 * [--product=<product_id>]
	 * : Voucher product whose settings apply.
	 *
	 * [--type=<type>]
	 * : giftcard or voucher (without a product).
	 * ---
	 * default: giftcard
	 * ---
	 *
	 * [--name=<name>]
	 * : Recipient name.
	 *
	 * [--message=<message>]
	 * : Personal message.
	 *
	 * [--expiry=<date>]
	 * : Expiry date (YYYY-MM-DD) or "never"; default the product's or the global validity.
	 *
	 * [--reason=<reason>]
	 * : Reason kept in the history.
	 *
	 * [--send]
	 * : Send it by email now (otherwise it counts as handed over).
	 *
	 * ## EXAMPLES
	 *
	 *     wp vcap issue 4711
	 *     wp vcap issue --amount=50 --email=erika@example.com --send --reason="Prize draw"
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function issue( $args = array(), $assoc_args = array() ): void {
		if ( isset( $assoc_args['amount'] ) || isset( $assoc_args['product'] ) ) {
			$voucher = \VCAP\Admin\IssueVoucher::issue(
				array(
					'product_id'      => (int) ( $assoc_args['product'] ?? 0 ),
					'amount'          => (string) ( $assoc_args['amount'] ?? '' ),
					'type'            => (string) ( $assoc_args['type'] ?? 'giftcard' ),
					'recipient_email' => (string) ( $assoc_args['email'] ?? '' ),
					'recipient_name'  => (string) ( $assoc_args['name'] ?? '' ),
					'message'         => (string) ( $assoc_args['message'] ?? '' ),
					'expiry'          => (string) ( $assoc_args['expiry'] ?? '' ),
					'send'            => ! empty( $assoc_args['send'] ),
					'reason'          => (string) ( $assoc_args['reason'] ?? 'WP-CLI' ),
					'source'          => 'cli',
				)
			);
			if ( is_wp_error( $voucher ) ) {
				self::error( 'No voucher was issued: ' . $voucher->get_error_message() );
				return;
			}
			self::success( sprintf( 'Voucher %d issued: %s (%s%s).', $voucher->id, $voucher->code, (string) $voucher->balance, ! empty( $assoc_args['send'] ) ? ', being sent' : ', not sent' ) );
			return;
		}
		if ( ! $args ) {
			self::error( 'Usage: wp vcap issue <order_id>... or wp vcap issue --amount=<amount> [--email=<email>] [--product=<id>] [--send]' );
			return;
		}
		foreach ( array_map( 'absint', $args ) as $order_id ) {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof \WC_Order ) {
				self::warning( sprintf( 'Order %d not found.', $order_id ) );
				continue;
			}
			$created = vcap()->issuer->issue_for_order( $order, true, false ); // All at once (B4: no background batches).
			$failed  = vcap()->issuer->last_failures;
			self::line( sprintf( 'Order %d: %d issued, %d failed.', $order_id, count( $created ), $failed ) );
		}
	}

	/**
	 * Imports existing gift cards with their remaining value from a CSV, XLSX or ODS file (like Vouchers → Import
	 * cards). Columns: code, initial, balance, expiry, recipient, recipient name, type. Cards imported before are
	 * skipped, so the command can be run again.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path of the file.
	 *
	 * [--dry-run]
	 * : Only check the rows; nothing is imported.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vcap import cards.csv --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function import( $args = array(), $assoc_args = array() ): void {
		$file = (string) ( $args[0] ?? '' );
		if ( '' === $file || ! is_readable( $file ) ) {
			self::error( 'File not found or not readable: ' . $file );
			return;
		}
		try {
			$rows = \VCAP\SpreadsheetReader::read( $file, (string) pathinfo( $file, PATHINFO_EXTENSION ) );
		} catch ( \Throwable $e ) {
			self::error( 'The file could not be read: ' . $e->getMessage() );
			return;
		}
		$dry    = ! empty( $assoc_args['dry-run'] );
		$report = Source::with( Source::CLI, static fn() => \VCAP\Admin\CardImport::run( $rows, $dry, 'Import of ' . basename( $file ) . ' (WP-CLI)' ) );
		if ( '' !== $report['error'] ) {
			self::error( $report['error'] );
			return;
		}
		foreach ( $report['skipped'] as $line => $text ) {
			self::line( sprintf( 'Row %d skipped: %s', $line, $text ) );
		}
		foreach ( $report['errors'] as $line => $text ) {
			self::warning( sprintf( 'Row %d not imported: %s', $line, $text ) );
		}
		self::success( sprintf( '%d card(s) with %s remaining value %s.', $report['imported'], (string) $report['value'], $dry ? 'would be imported (dry run)' : 'imported' ) );
	}

	/**
	 * Brings the WooCommerce coupons in line with their vouchers: enabled while active, disabled while void or scheduled,
	 * active stored-value coupons carry the remaining balance.
	 *
	 * ## OPTIONS
	 *
	 * [<voucher_id>...]
	 * : Voucher ids. Without ids, all vouchers are checked.
	 *
	 * [--dry-run]
	 * : Only report what would change.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function sync( $args = array(), $assoc_args = array() ): void {
		$dry     = ! empty( $assoc_args['dry-run'] );
		$ids     = array_map( 'absint', $args );
		$changed = 0;
		$page    = 1;
		do {
			$batch = $ids ? array_filter( array_map( array( vcap()->vouchers, 'get' ), $ids ) ) : vcap()->vouchers->query( array( 'per_page' => 200, 'page' => $page ) )['items'];
			foreach ( $batch as $voucher ) {
				$changed += self::sync_one( $voucher, $dry ) ? 1 : 0;
			}
			++$page;
			$more = ! $ids && count( $batch ) === 200;
		} while ( $more );
		self::success( sprintf( '%d coupon(s) %s.', $changed, $dry ? 'would change' : 'changed' ) );
	}

	/** @return bool Whether the coupon differs (and was changed unless $dry). */
	private static function sync_one( Voucher $voucher, bool $dry ): bool {
		if ( ! $voucher->coupon_id ) {
			return false;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		if ( ! $coupon->get_id() ) {
			self::warning( sprintf( 'Voucher %d: its coupon #%d is missing.', $voucher->id, $voucher->coupon_id ) );
			return false;
		}
		$is_on = 'publish' === get_post_status( $voucher->coupon_id );
		// Active: enabled; void or scheduled: disabled. Redeemed, expired and reserved coupons are left as they are
		// (WooCommerce's usage limit, expiry date and the reservation guard refuse them already).
		$enabled = \VCAP\CouponManager::coupon_enabled_for( $voucher->status ) ?? $is_on;
		$amount  = Voucher::STATUS_ACTIVE === $voucher->status && $voucher->is_multi_use() && 'balance' === $voucher->usage_mode && abs( (float) $coupon->get_amount() - (float) $voucher->balance ) > 0.00001;
		if ( $enabled === $is_on && ! $amount ) {
			return false;
		}
		self::line( sprintf( 'Voucher %d: %s%s', $voucher->id, $enabled === $is_on ? '' : ( $enabled ? 'enable coupon ' : 'disable coupon ' ), $amount ? 'amount → ' . $voucher->balance : '' ) );
		if ( $dry ) {
			return true;
		}
		// The rules every other path uses (CouponManager::sync_coupon()): balance under the row lock, coupon status.
		\VCAP\CouponManager::sync_coupon( $voucher, array_merge( $amount ? array( 'amount' ) : array(), $enabled !== $is_on ? array( 'status' ) : array() ) );
		return true;
	}

	/**
	 * Reconciles stored balances with the ledger (M12-63).
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : "verify" compares the balance of every gift card and store credit account with the balance after its last
	 * booking in the history; differences are listed (and kept in the admin notice "needs your attention").
	 *
	 * [--format=<format>]
	 * : table, json, csv or yaml.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp vcap ledger verify
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function ledger( $args = array(), $assoc_args = array() ): void {
		if ( 'verify' !== ( $args[0] ?? '' ) ) {
			self::error( 'Usage: wp vcap ledger verify' );
			return;
		}
		$result = Ledger::reconcile();
		if ( ! $result['rows'] ) {
			self::success( sprintf( '%d stored balances checked, all match the ledger.', $result['checked'] ) );
			return;
		}
		self::table( $result['rows'], array( 'voucher_id', 'code', 'currency', 'balance', 'ledger', 'event_id' ), (string) ( $assoc_args['format'] ?? 'table' ) );
		self::error( sprintf( '%d of %d stored balances differ from the ledger (balance = stored, ledger = balance after the last booking).', count( $result['rows'] ), $result['checked'] ) );
	}

	/**
	 * Rate limits.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : "reset" removes all rate limit counters (e.g. after fixing a proxy setup).
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function ratelimit( $args = array(), $assoc_args = array() ): void {
		if ( 'reset' !== ( $args[0] ?? '' ) ) {
			self::error( 'Usage: wp vcap ratelimit reset' );
			return;
		}
		Plugin::reset_rate_limits();
		self::success( 'Rate limit counters removed.' );
	}

	/**
	 * Shows or changes "Remove all data when the plugin is deleted" of this site (off = data is kept, default).
	 *
	 * ## OPTIONS
	 *
	 * [<state>]
	 * : on, off or status (default).
	 *
	 * [--confirm=<word>]
	 * : Needed to switch it on: LÖSCHEN, LOESCHEN or DELETE (any letter case).
	 *
	 * ## EXAMPLES
	 *
	 *     wp vcap data-removal on --confirm=LÖSCHEN
	 *     wp vcap data-removal off
	 *
	 * @subcommand data-removal
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function data_removal( $args = array(), $assoc_args = array() ): void {
		$state = (string) ( $args[0] ?? 'status' );
		if ( 'off' === $state ) {
			update_option( DataRemoval::OPTION, 'no' );
			self::success( 'Data removal is off: deleting the plugin keeps all data.' );
			return;
		}
		if ( 'on' === $state ) {
			if ( ! DataRemoval::is_confirmation( $assoc_args['confirm'] ?? '' ) ) {
				self::error( 'Switching data removal on needs --confirm=LÖSCHEN (or LOESCHEN, DELETE). Nothing was changed.' );
				return;
			}
			update_option( DataRemoval::OPTION, 'yes' );
			self::warning( 'Data removal is on: deleting the plugin removes all voucher data of this site.' );
			return;
		}
		if ( 'status' !== $state ) {
			self::error( 'Usage: wp vcap data-removal [on|off|status] [--confirm=LÖSCHEN]' );
			return;
		}
		self::line( 'yes' === get_option( DataRemoval::OPTION, 'no' ) ? 'on' : 'off' );
	}

	/**
	 * Runs daily maintenance steps now.
	 *
	 * ## OPTIONS
	 *
	 * [<step>...]
	 * : Steps (default: all), see Delivery::MAINTENANCE_STEPS.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function maintenance( $args = array(), $assoc_args = array() ): void {
		foreach ( $args ? $args : Delivery::MAINTENANCE_STEPS as $step ) {
			$ok = vcap()->delivery->run_maintenance_step( (string) $step );
			self::line( sprintf( '%-18s %s', $step, $ok ? 'ok' : 'failed (see the WooCommerce log)' ) );
		}
	}

	/**
	 * Reads and changes the settings of the register (SET-VC-17): list, get, set, export, import, reset, profile.
	 *
	 * Values are checked like on the settings page (type, bounds, choices); secrets are never shown, exported or set.
	 * Every change is recorded in the change log with the source "WP-CLI". "profile" lists the country profiles
	 * (SET-VC-02), shows the changes one would make (current -> suggested) and applies them after a confirmation;
	 * like on the settings page, values you set yourself and the link parameter are kept unless --all is given.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : list, get, set, export, import, reset or profile.
	 *
	 * [<arg>...]
	 * : get <key> | set <key> <value> | import <file> | reset [<section>|all] | profile <list|show|apply>.
	 *
	 * [--country=<profile>]
	 * : profile show, apply: the profile (DE, AT, CH, EU, OTHER, or one added by the filter vcap_country_profiles).
	 *
	 * [--all]
	 * : profile apply: also replace values you set yourself and the link parameter.
	 *
	 * [--section=<section>]
	 * : list: only this section (general, redemption, promotions, codes, delivery, privacy, api, …).
	 *
	 * [--format=<format>]
	 * : list: table, json, csv or yaml; get: plain or json.
	 *
	 * [--file=<path>]
	 * : export: write the JSON to this file instead of the output.
	 *
	 * [--backup[=<time>]]
	 * : export: print an automatic backup instead (newest, or the one with this timestamp).
	 *
	 * [--dry-run]
	 * : import, reset, profile apply: only show what would change (reset: current -> default of each changing setting).
	 *
	 * [--yes]
	 * : import, reset, profile apply: do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vcap settings list --section=codes
	 *     wp vcap settings get vcap_code_length
	 *     wp vcap settings set vcap_code_length 16
	 *     wp vcap settings export --file=settings.json
	 *     wp vcap settings import settings.json --dry-run
	 *     wp vcap settings reset codes --yes
	 *     wp vcap settings profile list
	 *     wp vcap settings profile show --country=AT
	 *     wp vcap settings profile apply --country=DE --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function settings( $args = array(), $assoc_args = array() ): void {
		$action = (string) array_shift( $args );
		switch ( $action ) {
			case 'list':
				$this->settings_list( $assoc_args );
				return;
			case 'get':
				$this->settings_get( (string) ( $args[0] ?? '' ), (string) ( $assoc_args['format'] ?? 'plain' ) );
				return;
			case 'set':
				if ( count( $args ) < 2 ) {
					self::error( 'Usage: wp vcap settings set <key> <value>' );
					return;
				}
				$this->settings_set( (string) $args[0], (string) $args[1] );
				return;
			case 'export':
				$this->settings_export( $assoc_args );
				return;
			case 'import':
				$this->settings_import( (string) ( $args[0] ?? '' ), $assoc_args );
				return;
			case 'reset':
				$this->settings_reset( (string) ( $args[0] ?? '' ), $assoc_args );
				return;
			case 'profile':
				$this->settings_profile( (string) ( $args[0] ?? '' ), $assoc_args );
				return;
		}
		self::error( 'Usage: wp vcap settings list|get|set|export|import|reset|profile' );
	}

	/** Section id of a section argument ('general' = General, '' / 'all' = all sections = null), false when unknown. */
	private static function section_arg( string $section ): string|null|false {
		if ( '' === $section || 'all' === $section ) {
			return null;
		}
		return \VCAP\Admin\SettingsTools::reset_section( $section );
	}

	/** "wp vcap settings list". */
	private function settings_list( array $assoc_args ): void {
		$section = self::section_arg( (string) ( $assoc_args['section'] ?? '' ) );
		if ( false === $section ) {
			self::error( 'Unknown section.' );
			return;
		}
		$rows = array();
		foreach ( SettingsRegistry::all() as $key => $entry ) {
			if ( null !== $section && $entry['section'] !== $section ) {
				continue;
			}
			$rows[] = array(
				'key'     => $key,
				'section' => '' === $entry['section'] ? 'general' : $entry['section'],
				'type'    => $entry['type'],
				'value'   => $entry['secret'] ? SettingsLog::MASK : SettingsTransfer::display( SettingsTransfer::stored( $key ) ),
				'default' => $entry['secret'] ? '' : SettingsTransfer::display( $entry['default'] ),
				'export'  => $entry['export'] ? 'yes' : 'no',
			);
		}
		self::table( $rows, array( 'key', 'section', 'type', 'value', 'default', 'export' ), (string) ( $assoc_args['format'] ?? 'table' ) );
	}

	/** "wp vcap settings get <key>". */
	private function settings_get( string $key, string $format ): void {
		$entry = SettingsRegistry::get( $key );
		if ( null === $entry ) {
			self::error( sprintf( '%s is no setting of Voucher Captain.', $key ) );
			return;
		}
		if ( $entry['secret'] ) {
			self::error( sprintf( '%s is a secret and is never shown.', $key ) );
			return;
		}
		$value = SettingsTransfer::stored( $key );
		// PV-P1-4: tables are shown row by row ("DE 3 ✓; AT 30"), not as "Array"; --format=json gives the data.
		self::line( 'json' === $format ? (string) wp_json_encode( $value ) : SettingsTransfer::display( $value, PHP_INT_MAX ) );
	}

	/**
	 * "wp vcap settings set <key> <value>": checked like the settings page, recorded in the change log. PV-P1-4: a
	 * table or list takes a JSON array (a list also values separated by commas).
	 */
	private function settings_set( string $key, string $value ): void {
		if ( \VCAP\Admin\DataRemoval::OPTION === $key ) {
			self::error( 'Use "wp vcap data-removal on|off" for this setting.' );
			return;
		}
		$entry = SettingsRegistry::get( $key );
		$input = $value;
		if ( null !== $entry && in_array( $entry['type'], array( 'table', 'list' ), true ) && str_starts_with( ltrim( $value ), '[' ) ) {
			$input = json_decode( $value, true );
			if ( ! is_array( $input ) ) {
				self::error( sprintf( '%s: the value is no valid JSON array. Nothing was changed.', $key ) );
				return;
			}
		}
		$valid = SettingsTransfer::validate( $key, $input );
		if ( is_wp_error( $valid ) ) {
			self::error( wp_strip_all_tags( $valid->get_error_message() ) . ' Nothing was changed.' );
			return;
		}
		$changed = SettingsTransfer::apply( array( $key => $valid ), SettingsLog::VIA_CLI );
		self::success( $changed ? sprintf( '%s = %s', $key, SettingsTransfer::display( $valid ) ) : sprintf( '%s already has this value.', $key ) );
	}

	/** "wp vcap settings export". */
	private function settings_export( array $assoc_args ): void {
		if ( isset( $assoc_args['backup'] ) ) {
			$json = SettingsTransfer::backup_json( true === $assoc_args['backup'] ? 0 : (int) $assoc_args['backup'] );
			if ( '' === $json ) {
				self::error( 'No such backup.' );
				return;
			}
		} else {
			$json = SettingsTransfer::export_json();
		}
		$file = (string) ( $assoc_args['file'] ?? '' );
		if ( '' === $file ) {
			self::line( $json );
			return;
		}
		if ( false === file_put_contents( $file, $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP-CLI writes the file the admin named.
			self::error( sprintf( 'Could not write %s.', $file ) );
			return;
		}
		self::success( sprintf( 'Settings exported to %s.', $file ) );
	}

	/** "wp vcap settings import <file>": the whole file is checked first; one invalid value refuses it. */
	private function settings_import( string $file, array $assoc_args ): void {
		if ( '' === $file || ! is_readable( $file ) ) {
			self::error( 'Usage: wp vcap settings import <file> [--dry-run] [--yes]' );
			return;
		}
		$parsed = SettingsTransfer::parse( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file named by the admin.
		if ( is_wp_error( $parsed ) ) {
			self::error( 'The file was not imported, nothing was changed: ' . implode( ' ', array_map( 'strval', (array) ( $parsed->get_error_data() ?: array( $parsed->get_error_message() ) ) ) ) );
			return;
		}
		if ( $parsed['unknown'] ) {
			self::warning( 'Not known on this site and skipped: ' . implode( ', ', $parsed['unknown'] ) );
		}
		foreach ( $parsed['warnings'] as $warning ) {
			self::warning( wp_strip_all_tags( $warning ) ); // PV-P1-2: values of an older export that were converted.
		}
		$diff = SettingsTransfer::diff( $parsed['settings'] );
		if ( ! $diff ) {
			self::success( 'Nothing to change: every setting already has this value.' );
			return;
		}
		self::print_diff( $diff );
		if ( isset( $assoc_args['dry-run'] ) ) {
			self::line( sprintf( 'Dry run: %d settings would change.', count( $diff ) ) );
			return;
		}
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( sprintf( 'Change %d settings?', count( $diff ) ) );
		}
		$backup  = SettingsTransfer::backup( SettingsLog::VIA_IMPORT );
		$changed = SettingsTransfer::apply( $parsed['settings'], SettingsLog::VIA_IMPORT );
		self::success( sprintf( '%d settings imported. Previous settings: wp vcap settings export --backup=%d', count( $changed ), $backup ) );
	}

	/** "wp vcap settings reset [<section>|all]": automatic backup first; secrets and database state stay. */
	private function settings_reset( string $target, array $assoc_args ): void {
		$section = '' === $target ? false : self::section_arg( $target );
		if ( false === $section ) {
			self::error( 'Usage: wp vcap settings reset <section>|all [--dry-run] [--yes]' );
			return;
		}
		// PV-P1-3: back to the register default (what an existing shop runs on), shown as current -> default first.
		$diff = SettingsTransfer::reset_preview( $section );
		if ( ! $diff ) {
			self::success( 'Nothing to reset: every setting already has its default.' );
			return;
		}
		foreach ( $diff as $key => $change ) {
			self::line( sprintf( '%s: %s -> %s%s', $key, SettingsTransfer::display( $change['from'] ), SettingsTransfer::display( $change['to'] ), $change['legal'] ? ' [legal/tax weight – check]' : '' ) );
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			self::line( sprintf( 'Dry run: %d settings would be reset.', count( $diff ) ) );
			return;
		}
		if ( ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( sprintf( 'Reset %d settings to their defaults?', count( $diff ) ) );
		}
		$result = SettingsTransfer::reset( $section );
		self::success( sprintf( '%d settings reset. Previous settings: wp vcap settings export --backup=%d', count( $result['changed'] ), $result['backup'] ) );
	}

	/**
	 * "wp vcap settings profile <list|show|apply> [--country=] [--dry-run] [--yes] [--all]" (SET-VC-02): a profile is a
	 * suggestion – "show" and "--dry-run" change nothing, "apply" asks first, saves a backup and records the source
	 * "country profile" in the change log.
	 */
	private function settings_profile( string $action, array $assoc_args ): void {
		if ( 'list' === $action ) {
			$rows    = array();
			$applied = CountryProfiles::applied();
			foreach ( CountryProfiles::all() as $id => $profile ) {
				$rows[] = array(
					'profile' => $id,
					'name'    => CountryProfiles::label( $id ),
					'country' => $profile['country'],
					'checked' => $profile['checked'] ? 'yes' : ( '' !== $profile['country'] ? 'no (legal check pending)' : 'no (neutral, not checked)' ),
					'applied' => null !== $applied && $applied['id'] === $id ? 'yes' : '',
				);
			}
			self::table( $rows, array( 'profile', 'name', 'country', 'checked', 'applied' ), (string) ( $assoc_args['format'] ?? 'table' ) );
			return;
		}
		if ( ! in_array( $action, array( 'show', 'apply' ), true ) ) {
			self::error( 'Usage: wp vcap settings profile <list|show|apply> [--country=<profile>] [--dry-run] [--yes] [--all]' );
			return;
		}
		$id      = strtoupper( (string) ( $assoc_args['country'] ?? CountryProfiles::for_base_country() ) );
		$preview = CountryProfiles::preview( $id );
		if ( is_wp_error( $preview ) ) {
			self::error( $preview->get_error_message() );
			return;
		}
		self::line( sprintf( '%s (%s): %s', CountryProfiles::label( $id ), $id, CountryProfiles::status( $id ) ) );
		$all      = isset( $assoc_args['all'] );
		$selected = array();
		foreach ( $preview as $item => $row ) {
			$mark = '' !== $row['review'] ? ' [' . $row['review'] . ']' : '';
			if ( '' !== $row['error'] ) {
				self::warning( sprintf( '%s: %s', $row['label'], $row['error'] ) );
				continue;
			}
			if ( ! $row['changed'] ) {
				self::line( sprintf( '  = %s: %s (already set)%s', $row['label'], $row['to'], $mark ) );
				continue;
			}
			$take = $row['preselected'] || $all;
			if ( $take ) {
				$selected[] = $item;
			}
			$why = $take ? '' : ( $row['opt_in'] ? ' (suggestion only, kept – use --all)' : ' (your own value, kept – use --all)' );
			self::line( sprintf( '  %s %s: %s -> %s%s%s', $take ? '*' : '-', $row['label'], $row['from'], $row['to'], $why, $mark ) );
		}
		foreach ( CountryProfiles::hints( $id ) as $hint ) {
			self::line( sprintf( '  i %s: %s%s', $hint['label'], $hint['text'], '' !== $hint['review'] ? ' [' . $hint['review'] . ']' : '' ) );
		}
		if ( 'show' === $action ) {
			return;
		}
		if ( isset( $assoc_args['dry-run'] ) ) {
			self::line( sprintf( 'Dry run: %d settings would change.', count( $selected ) ) );
			return;
		}
		if ( $selected && ! isset( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( sprintf( 'Apply %d values of the country profile %s?', count( $selected ), $id ) );
		}
		$result = CountryProfiles::apply( $id, $selected );
		if ( is_wp_error( $result ) ) {
			self::error( wp_strip_all_tags( $result->get_error_message() ) );
			return;
		}
		self::success(
			$result['changed']
				? sprintf( '%d settings changed from the country profile %s. Previous settings: wp vcap settings export --backup=%d', count( $result['changed'] ), $id, $result['backup'] )
				: sprintf( 'Country profile %s chosen; no setting was changed.', $id )
		);
	}

	/**
	 * Prints "key: from → to" per change.
	 *
	 * @param array<string, array{from: mixed, to: mixed}> $diff Changes.
	 */
	private static function print_diff( array $diff ): void {
		foreach ( $diff as $key => $change ) {
			self::line( sprintf( '%s: %s -> %s', $key, SettingsTransfer::display( $change['from'] ), SettingsTransfer::display( $change['to'] ) ) );
		}
	}

	private static function line( string $text ): void {
		\WP_CLI::line( $text );
	}

	private static function success( string $text ): void {
		\WP_CLI::success( $text );
	}

	private static function warning( string $text ): void {
		\WP_CLI::warning( $text );
	}

	private static function error( string $text ): void {
		\WP_CLI::error( $text, false );
		\WP_CLI::halt( 1 );
	}

	private static function table( array $rows, array $fields, string $format ): void {
		\WP_CLI\Utils\format_items( $format, $rows, $fields );
	}
}
