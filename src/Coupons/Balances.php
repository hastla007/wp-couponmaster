<?php
namespace VCAP\Coupons;

use VCAP\API;
use VCAP\Db;
use VCAP\Install;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stored balances (D14, split from CouponManager): every booking (reserve, commit, release, deduct, restore, set,
 * adjust) runs under the voucher row lock in one transaction, with the per-order charge state machine read from the
 * ledger (ORDER_CHARGE_EVENTS) and the currency rounding tolerance (AP-Q1-5).
 */
final class Balances {
	public function __construct( private VoucherRepository $repo ) {}

	/**
	 * AP-Q1-1: the ledger events of an order's charge on a stored balance. The per-order state machine (reserved →
	 * committed/redeemed → reservation_released/restored) reads only these: other events carry the same order id
	 * (refund as store credit, top-up of the paying card and its reversal, validity extended, a partial return)
	 * and must never hide the state of the charge.
	 */
	public const ORDER_CHARGE_EVENTS = array( 'reserved', 'committed', 'redeemed', 'reservation_released', 'restored' );

	/** ORDER_CHARGE_EVENTS as a constant SQL list (interpolated, never user input; unit-tested to match). */
	public const ORDER_CHARGE_EVENTS_SQL = "'reserved','committed','redeemed','reservation_released','restored'";

	/**
	 * The order's latest charge event on the voucher (event, amount, balance_after), or null. Read under the
	 * voucher's row lock by the atomic operations.
	 */
	private function latest_charge_event( int $voucher_id, int $order_id ): ?array {
		global $wpdb;
		$events = Install::tables()['events'];
		$in     = self::ORDER_CHARGE_EVENTS_SQL;
		$row    = $wpdb->get_row( $wpdb->prepare( "SELECT event, amount, balance_after FROM {$events} WHERE voucher_id = %d AND order_id = %d AND event IN ({$in}) ORDER BY id DESC LIMIT 1", $voucher_id, $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- constant event list.
		return is_array( $row ) ? $row : null;
	}

	public function reserve_balance_atomic( Voucher $voucher, float $requested, int $order_id, string $note ): ?array {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		if ( $order_id <= 0 || $requested <= 0 || ! Install::tables_support_transactions() ) {
			return null;
		}
		$order = wc_get_order( $order_id );
		if ( ! Db::begin() ) {
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			$latest = $this->latest_charge_event( $voucher->id, $order_id );
			if ( $latest && in_array( $latest['event'], array( 'committed', 'redeemed' ), true ) ) {
				Db::finish();
				return array( 'amount' => (float) $latest['amount'], 'balance' => (float) $latest['balance_after'], 'state' => 'committed' );
			}
			if ( $latest && 'reserved' === $latest['event'] ) {
				$amount = (float) $latest['amount'];
				Db::finish();
				return abs( $amount - $requested ) < 0.0001
					? array( 'amount' => $amount, 'balance' => (float) $latest['balance_after'], 'state' => 'reserved' )
					: null;
			}

			$fresh = $this->repo->get( $voucher->id );
			$requested = round( $requested, $voucher->decimals() );
			if ( $fresh && $fresh->balance < $requested && $requested - $fresh->balance <= self::rounding_tolerance( $order instanceof \WC_Order ? $order : null, $requested, $fresh ) ) {
				// Currency round trips (order currency → base currency) can exceed the full balance by a few cents;
				// commit_order_reservations() records the gap on the order (AP-Q1-5).
				$requested = $fresh->balance;
			}
			if ( ! $fresh || ! $fresh->is_multi_use() || Voucher::STATUS_ACTIVE !== $fresh->status || $fresh->is_expired() || $fresh->balance < $requested ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			$fresh->balance = max( 0.0, round( $fresh->balance - $requested, $fresh->decimals() ) );
			if ( $fresh->balance <= 0 ) {
				$fresh->status = Voucher::STATUS_REDEEMED;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( ! $this->repo->log( $fresh->id, 'reserved', $requested, $fresh->balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'reserve_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}

		CouponSync::sync_coupon( $fresh, array( 'amount' ) );
		return array( 'amount' => $requested, 'balance' => $fresh->balance, 'state' => 'reserved' );
	}

	/**
	 * Commit a previously reserved balance without deducting it a second time.
	 *
	 * @return array{amount:float,balance:float}|null
	 */
	public function commit_reservation_atomic( Voucher $voucher, int $order_id ): ?array {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		if ( ! Install::tables_support_transactions() || ! Db::begin() ) {
			return null;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			$latest = $this->latest_charge_event( $voucher->id, $order_id );
			if ( ! $latest ) {
				Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( 'committed' !== $latest['event'] && 'redeemed' !== $latest['event'] ) {
				if ( 'reserved' !== $latest['event'] ) {
					Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					return null;
				}
				$fresh = $this->repo->get( $voucher->id );
				if ( ! $fresh || ! $this->repo->log( $voucher->id, 'committed', (float) $latest['amount'], $fresh->balance, '', $order_id ) ) {
					Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					return null;
				}
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'commit_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		$fresh = $this->repo->get( $voucher->id );
		return array( 'amount' => (float) $latest['amount'], 'balance' => $fresh ? $fresh->balance : (float) $latest['balance_after'] );
	}

	/**
	 * Release an unpaid order's reservation and return the balance to the voucher atomically.
	 */
	public function release_reservation_atomic( Voucher $voucher, int $order_id, string $note = '' ): bool {
		global $wpdb;
		$table  = Install::tables()['vouchers'];
		if ( ! Install::tables_support_transactions() || ! Db::begin() ) {
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$latest = $this->latest_charge_event( $voucher->id, $order_id );
			if ( ! $latest || 'reserved' !== $latest['event'] ) {
				Db::finish();
				return (bool) ( $latest && in_array( $latest['event'], array( 'reservation_released', 'restored' ), true ) );
			}
			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$amount         = (float) $latest['amount'];
			$fresh->balance = round( $fresh->balance + $amount, $fresh->decimals() );
			if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
				$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! $this->repo->log( $fresh->id, 'reservation_released', $amount, $fresh->balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'release_reservation_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		CouponSync::sync_coupon( $fresh, array( 'amount' ) );
		return true;
	}

	/**
	 * Value of a stored balance that is held right now and not part of the balance: reservations of unpaid orders
	 * (latest ledger event "reserved") and open external reservations. Released holds return to the balance.
	 */
	public function open_holds( Voucher $voucher ): float {
		global $wpdb;
		if ( ! $voucher->is_multi_use() ) {
			return 0.0;
		}
		$t      = Install::tables();
		$in     = self::ORDER_CHARGE_EVENTS_SQL;
		$orders = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( e.amount ), 0 ) FROM {$t['events']} e WHERE e.voucher_id = %d AND e.event = 'reserved' AND e.order_id > 0 AND NOT EXISTS ( SELECT 1 FROM {$t['events']} x WHERE x.voucher_id = e.voucher_id AND x.order_id = e.order_id AND x.id > e.id AND x.event IN ({$in}) )", $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$api    = (float) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE( SUM( amount ), 0 ) FROM {$t['redemptions']} WHERE voucher_id = %d AND state = %s", $voucher->id, API\V1\RedemptionService::STATE_RESERVED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return round( $orders + $api, $voucher->decimals() );
	}

	/** Amount of the order's hold on the voucher when its latest ledger event is an open reservation, else null. */
	public function open_hold( Voucher $voucher, int $order_id ): ?float {
		$latest = $this->latest_charge_event( $voucher->id, $order_id );
		return $latest && 'reserved' === $latest['event'] ? (float) $latest['amount'] : null;
	}

	/** Most minor units a currency round trip may exceed a stored balance by (AP-Q1-5). */
	public const ROUNDING_TOLERANCE_UNITS = 5;

	/**
	 * AP-Q1-5: how far an order's use of a stored balance may exceed it (the full balance is taken instead). Only a
	 * currency round trip (order currency → base currency) rounds like that, so only for an order in another
	 * currency than the shop's – 0 otherwise – and at most ROUNDING_TOLERANCE_UNITS minor units. The filter
	 * "vcap_balance_rounding_tolerance" can lower it, never raise it above that cap.
	 */
	public static function rounding_tolerance( ?\WC_Order $order, float $amount, ?Voucher $voucher ): float {
		$base     = (string) get_option( 'woocommerce_currency' );
		$decimals = $voucher ? $voucher->decimals() : API\V1\RedemptionService::decimals( $base );
		$default  = self::tolerance_for( $order ? (string) $order->get_currency() : '', $base, $amount, $decimals );
		if ( $default <= 0 ) {
			return 0.0;
		}
		$cap = self::ROUNDING_TOLERANCE_UNITS / ( 10 ** max( 0, $decimals ) );
		return max( 0.0, min( $cap, (float) apply_filters( 'vcap_balance_rounding_tolerance', $default, $voucher ) ) );
	}

	/** The default of rounding_tolerance(): 0 in the base currency, else 0.1 % of the amount within 1 to 5 minor units. */
	public static function tolerance_for( string $currency, string $base, float $amount, int $decimals ): float {
		if ( '' === $currency || '' === $base || 0 === strcasecmp( $currency, $base ) ) {
			return 0.0;
		}
		$decimals = max( 0, $decimals );
		$unit     = 1 / ( 10 ** $decimals );
		return round( min( self::ROUNDING_TOLERANCE_UNITS * $unit, max( $unit, abs( $amount ) * 0.001 ) ), $decimals );
	}

	/**
	 * Apply a new balance to voucher + coupon.
	 */
	public function set_balance( Voucher $voucher, float $balance, string $event, ?float $amount = null, string $note = '', int $order_id = 0 ): bool {
		return $this->write_balance( $voucher, static fn() => $balance, $event, $amount, $note, $order_id );
	}

	/**
	 * Add (positive) or remove (negative) value relative to the balance stored right now, under the
	 * row lock. Admin edits use this so a redemption that lands while the edit form is open is not
	 * overwritten by a balance computed from the stale form value.
	 */
	public function adjust_balance( Voucher $voucher, float $delta, string $event, string $note = '', int $order_id = 0, ?float &$booked = null ): bool {
		return $this->write_balance( $voucher, static fn( float $current ) => $current + $delta, $event, null, $note, $order_id, $booked );
	}

	/**
	 * @param callable(float):float $compute New balance from the locked, current balance.
	 * @param float|null            $booked  Set to the change really booked (new minus old balance, signed; 0.0 when
	 *                                       nothing was written): what notes report, not what was asked (M12-60).
	 */
	public function write_balance( Voucher $voucher, callable $compute, string $event, ?float $amount, string $note, int $order_id, ?float &$booked = null ): bool {
		global $wpdb;
		$booked = 0.0;
		$table = Install::tables()['vouchers'];
		if ( ! Install::tables_support_transactions() || ! Db::begin() ) {
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			$fresh = $locked ? $this->repo->get( $voucher->id ) : null;
			if ( ! $fresh ) {
				Db::rollback_if_error( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$before            = (float) $fresh->balance;
			$balance           = max( 0.0, round( (float) $compute( $before ), $fresh->decimals() ) );
			// U6: an admin correction keeps its direction (+ raised, − lowered) so the journal can be summed.
			$amount            = $amount ?? ( 'adjusted' === $event ? round( $balance - $before, $fresh->decimals() ) : abs( $balance - $before ) );
			$fresh->usage_mode = $voucher->usage_mode;
			$fresh->balance    = $balance;
			if ( $fresh->is_multi_use() ) {
				if ( $balance <= 0 && Voucher::STATUS_ACTIVE === $fresh->status ) {
					$fresh->status = Voucher::STATUS_REDEEMED;
				} elseif ( $balance > 0 && Voucher::STATUS_REDEEMED === $fresh->status ) {
					$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
				}
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, $event, $amount, $balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'write_balance', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		$booked = round( $balance - $before, $fresh->decimals() );
		CouponSync::sync_coupon( $fresh, array( 'amount' ) );
		return true;
	}

	/**
	 * Why a stored-value voucher cannot be charged right now ('' = it can): one of
	 * 'not_found', 'void', 'expired', 'reserved', 'scheduled', 'redeemed' (balance used up), 'not_active'
	 * (any other status), 'not_multi_use' (active, but no stored value). Pure, so the counter and the
	 * tests share one verdict.
	 */
	public static function balance_refusal( ?Voucher $voucher ): string {
		if ( ! $voucher ) {
			return 'not_found';
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return 'void';
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			return 'expired';
		}
		if ( Voucher::STATUS_RESERVED === $voucher->status ) {
			return 'reserved';
		}
		if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
			return 'scheduled';
		}
		if ( Voucher::STATUS_REDEEMED === $voucher->status ) {
			return 'redeemed';
		}
		if ( Voucher::STATUS_ACTIVE !== $voucher->status ) {
			return 'not_active';
		}
		if ( ! $voucher->is_multi_use() ) {
			return 'not_multi_use';
		}
		return $voucher->balance <= 0 ? 'redeemed' : '';
	}

	/** Amount in minor units (cents), so comparisons never depend on float noise. */
	public static function minor_units( float $amount, int $decimals ): int {
		return (int) round( $amount * ( 10 ** max( 0, $decimals ) ) );
	}

	/**
	 * Atomically deduct stored value under a row lock. For order redemptions, the audit row also
	 * makes retries for the same order idempotent.
	 *
	 * By default a request above the balance deducts the whole balance (orders: the shortfall is flagged by
	 * the caller). With $allow_partial = false (counter/scanner) such a request is refused and nothing is
	 * deducted. $reason receives why nothing was deducted ('' on success): a balance_refusal() code, or
	 * 'exceeds_balance', 'invalid_amount', 'no_transactions', 'db_error'.
	 *
	 * @return array{amount:float,balance:float,duplicate:bool}|null
	 */
	public function deduct_balance_atomic( Voucher $voucher, float $requested, string $note = '', int $order_id = 0, bool $allow_partial = true, ?string &$reason = null ): ?array {
		global $wpdb;
		$reason = '';
		$table  = Install::tables()['vouchers'];
		if ( ! Install::tables_support_transactions() ) {
			$reason = 'no_transactions';
			return null;
		}
		if ( ! Db::begin() ) {
			$reason = 'db_error';
			return null;
		}
		$reason = 'db_error';
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				$reason = 'not_found';
				return null;
			}

			if ( $order_id > 0 ) {
				$prior = $this->latest_charge_event( $voucher->id, $order_id );
				if ( $prior && in_array( $prior['event'], array( 'reserved', 'committed', 'redeemed' ), true ) ) {
					if ( 'reserved' === $prior['event'] ) {
						// The balance read under the lock now, not the one after the reservation (other bookings
						// may have changed it since): the journal and the liability read balance_after.
						$locked_voucher         = $this->repo->get( $voucher->id );
						$prior['balance_after'] = $locked_voucher ? (float) $locked_voucher->balance : (float) $prior['balance_after'];
						if ( ! $this->repo->log( $voucher->id, 'committed', (float) $prior['amount'], (float) $prior['balance_after'], $note, $order_id ) ) {
							Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
							return null;
						}
					}
					if ( ! Db::commit() ) {
						Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
						return null;
					}
					$reason = '';
					return array(
						'amount'    => (float) $prior['amount'],
						'balance'   => (float) $prior['balance_after'],
						'duplicate' => true,
					);
				}
			}

			$fresh   = $this->repo->get( $voucher->id );
			$refusal = self::balance_refusal( $fresh );
			// A used-up balance stays chargeable with 0 for orders (the caller records the shortfall).
			if ( '' !== $refusal && ! ( 'redeemed' === $refusal && $allow_partial && Voucher::STATUS_ACTIVE === $fresh->status ) ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				$reason = $refusal;
				return null;
			}
			$decimals = $fresh->decimals();
			if ( ! $allow_partial ) {
				$wanted = is_finite( $requested ) ? self::minor_units( $requested, $decimals ) : 0;
				if ( $wanted <= 0 ) {
					Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					$reason = 'invalid_amount';
					return null;
				}
				if ( $wanted > self::minor_units( $fresh->balance, $decimals ) ) {
					Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					$reason = 'exceeds_balance';
					return null;
				}
			}

			$amount          = min( $fresh->balance, max( 0.0, round( $requested, $decimals ) ) );
			$fresh->balance  = max( 0.0, round( $fresh->balance - $amount, $fresh->decimals() ) );
			if ( $fresh->balance <= 0 ) {
				$fresh->status = Voucher::STATUS_REDEEMED;
			}
			if ( ! $this->repo->update( $fresh ) ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
			if ( $amount > 0 || $order_id > 0 ) {
				if ( ! $this->repo->log( $fresh->id, 'redeemed', $amount, $fresh->balance, $note, $order_id ) ) {
					Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
					return null;
				}
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return null;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'deduct_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}

		CouponSync::sync_coupon( $fresh, array( 'amount' ) );
		$reason = '';
		if ( $amount > 0 ) {
			VoucherRepository::fire_redeemed( $fresh, $amount, $order_id > 0 ? 'shop' : 'counter', $order_id );
		}
		return array(
			'amount'    => $amount,
			'balance'   => $fresh->balance,
			'duplicate' => false,
		);
	}

	/**
	 * @param float $amount  Amount to book back.
	 * @param float $charged Amount the order was charged (null: $amount); differs when part of it was booked back by
	 *                       hand already (event "returned", ignored when looking for the charge).
	 */
	public function restore_balance_atomic( Voucher $voucher, float $amount, int $order_id, string $note, ?float $charged = null ): bool {
		global $wpdb;
		$table   = Install::tables()['vouchers'];
		$charged = $charged ?? $amount;
		if ( $amount <= 0 && $charged > 0 && $order_id > 0 ) {
			return true; // Booked back completely by hand already.
		}
		if ( $amount <= 0 || $order_id <= 0 || ! Install::tables_support_transactions() || ! Db::begin() ) {
			return false;
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			if ( ! $locked ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$latest = $this->latest_charge_event( $voucher->id, $order_id );
			if ( $latest && 'restored' === $latest['event'] ) {
				Db::finish();
				return true;
			}
			if ( ! $latest || ! in_array( $latest['event'], array( 'committed', 'redeemed' ), true ) || abs( (float) $latest['amount'] - $charged ) >= 0.0001 ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$fresh = $this->repo->get( $voucher->id );
			if ( ! $fresh ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			$fresh->balance = round( $fresh->balance + $amount, $fresh->decimals() );
			if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
				$fresh->status = $fresh->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
			}
			if ( ! $this->repo->update( $fresh ) || ! $this->repo->log( $fresh->id, 'restored', $amount, $fresh->balance, $note, $order_id ) ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
			if ( ! Db::commit() ) {
				Db::rollback_if_error( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ) );
				return false;
			}
		} catch ( \Throwable $e ) {
			Db::rollback( 'restore_balance_atomic', array( 'voucher_id' => $voucher->id, 'order_id' => $order_id ), $e );
			throw $e;
		}
		CouponSync::sync_coupon( $fresh, array( 'amount' ) );
		return true;
	}
}
