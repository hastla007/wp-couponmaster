<?php
namespace VCAP\Coupons;

use VCAP\API;
use VCAP\Db;
use VCAP\Install;
use VCAP\Logger;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * A voucher's WooCommerce coupon follows the voucher row (D14, split from CouponManager): amount, usage, expiry
 * and status are written in one place, and the coupon is protected against being edited, trashed or deleted on
 * its own.
 */
final class CouponSync {
	public function __construct( private VoucherRepository $repo ) {}

	/** @var bool Set while sync_coupon_amount() saves a coupon (its own save hook must not sync again). */
	private static bool $syncing = false;

	/** Most rounds of sync_coupon_amount() (read, save, read again) before it gives up. */
	private const SYNC_ROUNDS = 3;

	/**
	 * Write the voucher's current balance to its WooCommerce coupon. Bookings change the balance under the voucher
	 * row lock but save the coupon after their COMMIT, so two bookings could save in the opposite order and leave an
	 * old amount on the coupon (then every checkout with the card fails until the next booking).
	 *
	 * M12-62: the balance is read under the row lock, the lock is released, and only then is the coupon saved
	 * (WC_Coupon::save() runs third-party hooks, which must not hold the voucher row). Afterwards the balance is
	 * read under the lock again: when a booking changed it meanwhile (its own sync may have saved before this one),
	 * the coupon is saved again. The last request to save therefore always leaves the latest balance on the coupon,
	 * whatever the passed (possibly stale) voucher object says.
	 */
	public static function sync_coupon_amount( Voucher $voucher ): bool {
		if ( ! $voucher->coupon_id || self::$syncing || ! class_exists( '\WC_Coupon' ) ) {
			return false;
		}
		self::$syncing = true;
		try {
			for ( $round = 0; $round < self::SYNC_ROUNDS; $round++ ) {
				$balance = self::locked_balance( $voucher );
				if ( null === $balance ) {
					return false;
				}
				// Read the coupon past this request's cache (another process may have saved it).
				wp_cache_delete( $voucher->coupon_id, 'posts' );
				wp_cache_delete( $voucher->coupon_id, 'post_meta' );
				$coupon = new \WC_Coupon( $voucher->coupon_id );
				if ( ! $coupon->get_id() ) {
					return false;
				}
				if ( abs( (float) $coupon->get_amount( 'edit' ) - $balance ) <= 0.000001 ) {
					return true; // In line with the balance read last (after this request's own save, if any).
				}
				$coupon->set_amount( $balance );
				$coupon->save();
			}
			Logger::warning( sprintf( 'The coupon amount of voucher %d kept changing while it was synced; the next booking or the CLI command "sync" brings it in line.', (int) $voucher->id ) );
			return true;
		} finally {
			self::$syncing = false;
		}
	}

	/**
	 * The stored balance of a balance voucher, rounded to its currency, read under the row lock in a short
	 * transaction of its own (a locking read sees the latest committed balance, also inside an outer transaction);
	 * null when the voucher is gone or not a balance voucher.
	 */
	private static function locked_balance( Voucher $voucher ): ?float {
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$tx    = Install::tables_support_transactions() && Db::begin();
		try {
			$lock = $tx ? ' FOR UPDATE' : '';
			$row  = $wpdb->get_row( $wpdb->prepare( "SELECT balance, usage_mode, currency FROM {$table} WHERE id = %d{$lock}", $voucher->id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $tx ) {
				Db::finish();
			}
		} catch ( \Throwable $e ) {
			if ( $tx ) {
				Db::rollback_silently();
			}
			throw $e;
		}
		if ( ! is_array( $row ) || 'balance' !== $row['usage_mode'] ) {
			return null;
		}
		return round( max( 0.0, (float) $row['balance'] ), API\V1\RedemptionService::decimals( (string) $row['currency'] ) );
	}

	/** What sync_coupon() can bring in line with the voucher row. */
	public const SYNC_ASPECTS = array( 'amount', 'usage', 'return_use', 'expiry', 'status' );

	/**
	 * D14: the one place that writes a voucher's state to its WooCommerce coupon after the voucher row changed
	 * (API, scanner/counter, admin, top-up, CLI repair, reissue, store credit). The rules:
	 *  - "amount": a stored balance is the coupon amount, read and saved under the voucher row lock
	 *    (sync_coupon_amount()); single-use coupons keep their face value.
	 *  - "usage": a non-balance voucher's usage limit and count follow its status (coupon_usage_for()):
	 *    redeemed elsewhere = used up for WooCommerce too, active again = at least one use left.
	 *  - "return_use": one counted use is given back first (a cancelled counter redemption), then "usage" applies.
	 *  - "expiry": the coupon expires with the voucher (none = never).
	 *  - "status": active vouchers have a published coupon, void and scheduled ones a disabled coupon; redeemed,
	 *    expired and reserved vouchers keep the coupon as it is (usage limit, expiry and the reservation guard
	 *    refuse it already).
	 * Only what differs is saved.
	 *
	 * @param Voucher  $voucher Voucher as stored (status, expiry); the balance is re-read under the lock.
	 * @param string[] $what    Aspects, see SYNC_ASPECTS.
	 * @return bool False when the voucher has no coupon or a part could not be written.
	 */
	public static function sync_coupon( Voucher $voucher, array $what ): bool {
		if ( ! $voucher->coupon_id || ! class_exists( '\WC_Coupon' ) ) {
			return false;
		}
		$ok = true;
		if ( in_array( 'amount', $what, true ) && $voucher->is_multi_use() ) {
			$ok = self::sync_coupon_amount( $voucher );
		}
		$usage  = ! $voucher->is_multi_use() && ( in_array( 'usage', $what, true ) || in_array( 'return_use', $what, true ) );
		$expiry = in_array( 'expiry', $what, true );
		if ( $usage || $expiry ) {
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			if ( ! $coupon->get_id() ) {
				return false;
			}
			$changed = false;
			if ( $usage ) {
				$count = (int) $coupon->get_usage_count( 'edit' );
				if ( in_array( 'return_use', $what, true ) ) {
					$count = max( 0, $count - 1 );
				}
				list( $limit, $count ) = self::coupon_usage_for( $voucher->usage_mode, $voucher->status, (int) $coupon->get_usage_limit( 'edit' ), $count );
				if ( $count !== (int) $coupon->get_usage_count( 'edit' ) || $limit !== (int) $coupon->get_usage_limit( 'edit' ) ) {
					$coupon->set_usage_count( $count );
					$coupon->set_usage_limit( $limit );
					$changed = true;
				}
			}
			if ( $expiry ) {
				$ends    = $voucher->expires_at ? strtotime( $voucher->expires_at . ' UTC' ) : null;
				$current = $coupon->get_date_expires( 'edit' );
				if ( ( $current ? $current->getTimestamp() : null ) !== ( $ends ? $ends : null ) ) {
					$coupon->set_date_expires( $ends ? $ends : null );
					$changed = true;
				}
			}
			if ( $changed ) {
				$ok = $coupon->save() && $ok;
			}
		}
		if ( in_array( 'status', $what, true ) ) {
			$enabled = self::coupon_enabled_for( $voucher->status );
			if ( null !== $enabled && $enabled !== ( 'publish' === get_post_status( $voucher->coupon_id ) ) ) {
				$ok = self::set_coupon_post_status( (int) $voucher->coupon_id, (string) $voucher->code, $enabled ) && $ok;
			}
		}
		return $ok;
	}

	/**
	 * Usage limit and count of a non-balance voucher's coupon for the voucher status (D14, one rule for every path):
	 * redeemed outside the cart makes WooCommerce's own limit refuse it (at least one use counted, limit = count);
	 * an active single-use voucher has exactly one use left (limit = count + 1: never none, never two); an active
	 * reusable voucher whose coupon is used up (after a refund or cancellation) gets one more use. Balance vouchers
	 * and every other state keep what they have.
	 *
	 * @return array{0:int, 1:int} usage limit, usage count
	 */
	public static function coupon_usage_for( string $usage_mode, string $status, int $limit, int $count ): array {
		if ( 'balance' === $usage_mode ) {
			return array( $limit, $count );
		}
		if ( Voucher::STATUS_REDEEMED === $status ) {
			$count = max( 1, $count );
			return array( $count, $count );
		}
		if ( Voucher::STATUS_ACTIVE === $status && 'single' === $usage_mode ) {
			return array( $count + 1, $count );
		}
		if ( Voucher::STATUS_ACTIVE === $status && $limit > 0 && $count >= $limit ) {
			return array( $count + 1, $count );
		}
		return array( $limit, $count );
	}

	/** Whether a voucher in this status has an enabled coupon (null: leave the coupon as it is). */
	public static function coupon_enabled_for( string $status ): ?bool {
		if ( in_array( $status, array( Voucher::STATUS_VOID, Voucher::STATUS_SCHEDULED ), true ) ) {
			return false;
		}
		return Voucher::STATUS_ACTIVE === $status ? true : null;
	}

	/**
	 * Publishes or disables (draft) a coupon post and drops WooCommerce's code → ID cache, so a persistent object
	 * cache cannot keep a disabled coupon alive.
	 */
	public static function set_coupon_post_status( int $coupon_id, string $code, bool $enabled ): bool {
		$updated = wp_update_post(
			array(
				'ID'          => $coupon_id,
				'post_status' => $enabled ? 'publish' : 'draft',
			),
			true
		);
		if ( class_exists( '\WC_Cache_Helper' ) ) {
			wp_cache_delete( \WC_Cache_Helper::get_cache_prefix( 'coupons' ) . 'coupon_id_from_code_' . wc_format_coupon_code( $code ), 'coupons' );
		}
		return ! is_wp_error( $updated ) && $updated;
	}

	/**
	 * A voucher coupon was saved in WooCommerce (coupon editor, REST API, another plugin): a stored balance is kept
	 * by the voucher row, so a different amount is set back to it (an edited or failed save would otherwise send
	 * every checkout into a shortfall).
	 *
	 * @param int        $coupon_id Coupon id.
	 * @param \WC_Coupon $coupon    Coupon.
	 */
	public function coupon_saved( $coupon_id, $coupon = null ): void {
		if ( self::$syncing || ! $coupon instanceof \WC_Coupon || 'yes' !== $coupon->get_meta( '_vcap_voucher' ) ) {
			return;
		}
		$voucher = $this->repo->get_by_coupon( (int) $coupon_id );
		if ( $voucher && $voucher->is_multi_use() && abs( (float) $coupon->get_amount( 'edit' ) - (float) $voucher->balance ) > 0.000001 ) {
			self::sync_coupon( $voucher, array( 'amount' ) );
		}
	}

	/** Voucher whose coupon this post is (null for every other post). */
	private function voucher_of_coupon_post( $post ): ?Voucher {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || 'shop_coupon' !== $post->post_type || 'yes' !== get_post_meta( $post->ID, '_vcap_voucher', true ) ) {
			return null;
		}
		return $this->repo->get_by_coupon( (int) $post->ID );
	}

	/**
	 * Refuse trashing or deleting the coupon of an existing voucher in wp-admin and over the REST API: the voucher
	 * would still look usable (liability, reminders) while its code no longer works, or vice versa. Plugin code
	 * (repairs, rollbacks) may still remove coupons; filter "vcap_protect_voucher_coupons".
	 *
	 * @param mixed    $check Short-circuit value of pre_trash_post / pre_delete_post.
	 * @param \WP_Post $post  Post.
	 * @return mixed
	 */
	public function protect_coupon( $check, $post = null ) {
		if ( null !== $check ) {
			return $check;
		}
		$voucher = $this->voucher_of_coupon_post( $post );
		if ( ! $voucher ) {
			return $check;
		}
		$interactive = is_admin() || ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		/**
		 * Whether trashing/deleting a voucher's WooCommerce coupon is refused (default: in wp-admin and REST requests).
		 *
		 * @param bool    $protect Refuse the removal.
		 * @param Voucher $voucher Voucher.
		 */
		if ( apply_filters( 'vcap_protect_voucher_coupons', $interactive, $voucher ) ) {
			return false;
		}
		return $check;
	}

	/** A voucher coupon that is trashed or deleted anyway is recorded in the voucher's history. */
	public function log_coupon_removed( $post_id ): void {
		$voucher = $this->voucher_of_coupon_post( $post_id );
		if ( $voucher ) {
			$this->repo->log( $voucher->id, 'coupon_removed', null, null, 'wp_trash_post' === current_action() ? __( 'WooCommerce coupon moved to the trash', 'voucher-captain' ) : __( 'WooCommerce coupon deleted', 'voucher-captain' ) );
		}
	}
}
