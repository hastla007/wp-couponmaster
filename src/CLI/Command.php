<?php
namespace VCAP\CLI;

use VCAP\Delivery;
use VCAP\Diagnostics;
use VCAP\Health;
use VCAP\Install;
use VCAP\Plugin;
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
	 * Issues the missing vouchers of orders (like the order action "Generate vouchers").
	 *
	 * ## OPTIONS
	 *
	 * <order_id>...
	 * : Order ids.
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Options.
	 */
	public function issue( $args = array(), $assoc_args = array() ): void {
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
		} while ( ! $ids && count( $batch ) === 200 );
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
