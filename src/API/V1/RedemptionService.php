<?php
/**
 * External (order-less) voucher redemptions with idempotency keys.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Channels;
use VCAP\Install;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Redeems vouchers outside the WooCommerce cart, e.g. to unlock a course or issue a ticket in another plugin.
 *
 * Flow: reserve() locks the voucher value for a caller-supplied idempotency key → the integration delivers its
 * service → commit() makes the redemption final, or release() returns the value. Reservations that are neither
 * committed nor released expire after the configured TTL and are released automatically.
 *
 * Both single-use vouchers (whole voucher) and stored-balance gift cards (partial amount) are supported.
 * The WooCommerce cart sees the same voucher state, so a code can never be spent in the cart and externally.
 *
 * @since 1.1.0
 */
final class RedemptionService {
	public const STATE_RESERVED  = 'reserved';
	public const STATE_COMMITTED = 'committed';
	public const STATE_RELEASED  = 'released';

	/** Allowed idempotency key format (callers typically use a UUID or "<system>:<id>"). */
	public const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._:\-]{7,63}$/';

	/**
	 * Construct the service.
	 *
	 * @param VoucherRepository $vouchers Voucher repository.
	 */
	public function __construct( private VoucherRepository $vouchers ) {}

	/**
	 * Register background hooks (automatic release of expired reservations).
	 */
	public function register(): void {
		add_action( 'vcap_release_expired_redemption', array( $this, 'release_expired' ) );
		add_action( 'vcap_daily_maintenance', array( $this, 'release_all_expired' ) );
	}

	/**
	 * Stable error with HTTP-style status for REST responses.
	 *
	 * @param string $code    Error code.
	 * @param string $message Human readable message.
	 * @param int    $status  HTTP status.
	 * @return \WP_Error
	 */
	public static function error( string $code, string $message, int $status ): \WP_Error {
		return new \WP_Error( $code, $message, array( 'status' => $status ) );
	}

	/**
	 * Check whether a code can be redeemed, without reserving anything.
	 *
	 * @param string $code    Voucher code.
	 * @param array  $args    Optional: target (string), amount (float), user_id (int, PHP callers only),
	 *                        email (string, PHP callers only: customer email of a guest, for personal vouchers).
	 * @return array<string, mixed>|\WP_Error
	 */
	public function check( string $code, array $args = array() ) {
		$user_id = $this->user_id( $args );
		if ( $this->too_many_failures( $user_id ) ) {
			return self::error( 'vcap_rate_limited', __( 'Too many invalid voucher codes. Please try again later.', 'voucher-captain' ), 429 );
		}
		$voucher = $this->find( $code );
		if ( ! $voucher ) {
			$this->record_failure( $user_id );
			return self::error( 'vcap_not_found', __( 'This voucher code is not valid.', 'voucher-captain' ), 404 );
		}
		$target = isset( $args['target'] ) ? $this->clean_target( (string) $args['target'] ) : '';
		$amount = isset( $args['amount'] ) ? (float) $args['amount'] : null;
		$check  = $this->eligibility( $voucher, $target, $amount, $user_id, (string) ( $args['email'] ?? '' ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		return $this->voucher_data( $voucher );
	}

	/**
	 * Reserve a voucher (or part of a gift card balance) for an external redemption.
	 *
	 * Idempotent: repeating the call with the same key and the same request returns the existing redemption;
	 * the same key with a different request returns vcap_idempotency_conflict.
	 *
	 * @param string $code Voucher code.
	 * @param array  $args key (string, required), target (string, required), amount (float, gift cards),
	 *                     user_id (int, PHP callers only; REST always uses the authenticated user), meta (array),
	 *                     ttl_minutes (int, PHP callers only: how long the reservation is held before it is released
	 *                     automatically; default the "Reservation lifetime" setting, at most 60 days),
	 *                     email (string, PHP callers only: verified customer email of a guest, for personal vouchers).
	 * @return array<string, mixed>|\WP_Error
	 * @throws \Throwable Database errors are re-thrown after the transaction was rolled back.
	 */
	public function reserve( string $code, array $args ) {
		$key    = (string) ( $args['key'] ?? '' );
		$target = $this->clean_target( (string) ( $args['target'] ?? '' ) );
		if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
			return self::error( 'vcap_invalid_request', __( 'A redemption key of 8–64 characters (letters, digits, . _ : -) is required.', 'voucher-captain' ), 400 );
		}
		if ( '' === $target ) {
			return self::error( 'vcap_invalid_request', __( 'A redemption target is required.', 'voucher-captain' ), 400 );
		}
		$user_id = $this->user_id( $args );
		if ( $this->too_many_failures( $user_id ) ) {
			return self::error( 'vcap_rate_limited', __( 'Too many invalid voucher codes. Please try again later.', 'voucher-captain' ), 429 );
		}

		// A known key is answered before the voucher lookup, so retries keep working after the voucher changed.
		$existing = $this->get_row( $key );
		$voucher  = $this->find( $code );
		if ( ! $voucher ) {
			if ( $existing ) {
				return self::error( 'vcap_idempotency_conflict', __( 'This redemption key was already used for another request.', 'voucher-captain' ), 409 );
			}
			$this->record_failure( $user_id );
			return self::error( 'vcap_not_found', __( 'This voucher code is not valid.', 'voucher-captain' ), 404 );
		}
		$amount = $voucher->is_multi_use() ? round( (float) ( $args['amount'] ?? 0 ), self::decimals( $voucher->currency ) ) : round( $voucher->initial_amount, self::decimals( $voucher->currency ) );
		$hash   = $this->request_hash( $voucher->id, $target, $amount, $user_id );
		if ( $existing ) {
			return hash_equals( (string) $existing['request_hash'], $hash )
				? $this->redemption_data( $existing )
				: self::error( 'vcap_idempotency_conflict', __( 'This redemption key was already used for another request.', 'voucher-captain' ), 409 );
		}
		if ( $voucher->is_multi_use() && $amount <= 0 ) {
			return self::error( 'vcap_invalid_request', __( 'Please specify the amount to redeem from this gift card.', 'voucher-captain' ), 400 );
		}

		if ( ! Install::tables_support_transactions() ) {
			return self::error( 'vcap_temporary_failure', __( 'Voucher redemption is temporarily unavailable.', 'voucher-captain' ), 503 );
		}

		global $wpdb;
		$tables = Install::tables();
		$ttl    = max( 1, min( 60 * 24 * 60, (int) ( $args['ttl_minutes'] ?? get_option( 'vcap_redemption_ttl', 15 ) ) ) ) * MINUTE_IN_SECONDS;
		$now    = gmdate( 'Y-m-d H:i:s' );
		$id     = 0;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $this->temporary();
		}
		try {
			$locked = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['vouchers']} WHERE id = %d FOR UPDATE", $voucher->id ) ); // phpcs:ignore
			$fresh  = $locked ? $this->vouchers->get( $voucher->id ) : null;
			if ( ! $fresh ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
			$check = $this->eligibility( $fresh, $target, $fresh->is_multi_use() ? $amount : null, $user_id, (string) ( $args['email'] ?? '' ) );
			if ( is_wp_error( $check ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $check;
			}

			if ( $fresh->is_multi_use() ) {
				$fresh->balance = max( 0.0, round( $fresh->balance - $amount, self::decimals( $fresh->currency ) ) );
				if ( $fresh->balance <= 0 ) {
					$fresh->status = Voucher::STATUS_REDEEMED;
				}
			} else {
				$fresh->status = Voucher::STATUS_RESERVED;
			}
			$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$tables['redemptions'],
				array(
					'redemption_key' => $key,
					'voucher_id'     => $fresh->id,
					'user_id'        => $user_id,
					'target'         => $target,
					'amount'         => $amount,
					'state'          => self::STATE_RESERVED,
					'request_hash'   => $hash,
					'meta'           => wp_json_encode( self::caller_meta( $args['meta'] ?? null ) ),
					'expires_at'     => gmdate( 'Y-m-d H:i:s', time() + $ttl ),
					'created_at'     => $now,
					'updated_at'     => $now,
				)
			);
			if ( ! $inserted ) {
				// Same key inserted concurrently (unique index): answer like an idempotent retry.
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$row = $this->get_row( $key );
				if ( $row ) {
					return hash_equals( (string) $row['request_hash'], $hash )
						? $this->redemption_data( $row )
						: self::error( 'vcap_idempotency_conflict', __( 'This redemption key was already used for another request.', 'voucher-captain' ), 409 );
				}
				return $this->temporary();
			}
			$id = (int) $wpdb->insert_id;
			/* translators: 1: redemption key, 2: target */
			$note = sprintf( __( 'Redemption %1$s for %2$s', 'voucher-captain' ), $key, $target );
			if ( ! $this->vouchers->update( $fresh ) || ! $this->vouchers->log( $fresh->id, 'api_reserved', $amount, $fresh->balance, $note ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}

		$this->sync_coupon( $fresh );
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $ttl + MINUTE_IN_SECONDS, 'vcap_release_expired_redemption', array( 'redemption_id' => $id ), 'vcap' );
		}
		$data = $this->redemption_data( $this->get_row( $key ) );
		/**
		 * Fires after a voucher was reserved for an external redemption.
		 *
		 * @since 1.1.0
		 * @param array $data Redemption data (see API.md).
		 */
		do_action( 'vcap_redemption_reserved', $data );
		return $data;
	}

	/**
	 * Make a reservation final. Idempotent: committing a committed redemption returns it unchanged.
	 *
	 * @param string $key  Redemption key.
	 * @param array  $args user_id (int, PHP callers only) to enforce ownership; omit for trusted server code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function commit( string $key, array $args = array() ) {
		return $this->transition( $key, self::STATE_COMMITTED, $args );
	}

	/**
	 * Cancel a reservation and give the voucher value back. Idempotent for already released redemptions.
	 *
	 * @param string $key  Redemption key.
	 * @param array  $args user_id (int, PHP callers only) to enforce ownership; omit for trusted server code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function release( string $key, array $args = array() ) {
		return $this->transition( $key, self::STATE_RELEASED, $args );
	}

	/**
	 * Give back (part of) a completed external redemption, e.g. when the delivered service is refunded.
	 *
	 * Gift cards: the amount returns to the balance. Single-use vouchers: once the refunds reach the redeemed
	 * amount, the voucher becomes usable again; partial refunds are recorded only. Idempotent per refund key;
	 * the same refund key with a different amount is a conflict.
	 *
	 * @since 1.1.0
	 * @param string $key        Redemption key.
	 * @param float  $amount     Amount to give back (voucher currency).
	 * @param string $refund_key Idempotency key of this refund.
	 * @param array  $args       user_id (int, PHP callers only) to enforce ownership; omit for trusted server code.
	 * @return array<string, mixed>|\WP_Error Redemption data plus refunded (total) and restored (this call).
	 * @throws \Throwable Database errors are re-thrown after the transaction was rolled back.
	 */
	public function refund( string $key, float $amount, string $refund_key, array $args = array() ) {
		$amount = (float) $amount; // Rounded to the voucher currency below.
		if ( ! preg_match( self::KEY_PATTERN, $key ) || ! preg_match( self::KEY_PATTERN, $refund_key ) ) {
			return self::error( 'vcap_invalid_request', __( 'A redemption key, a refund key and a positive amount are required.', 'voucher-captain' ), 400 );
		}
		$row = $this->get_row( $key );
		if ( ! $row ) {
			return self::error( 'vcap_reservation_missing', __( 'No redemption exists for this key.', 'voucher-captain' ), 404 );
		}
		$known = json_decode( (string) $row['meta'], true );
		if ( is_array( $known ) && isset( $known['_refunds'][ $refund_key ] ) ) {
			// Repeated refund (retry): answer with the stored result, whatever amount the retry computed.
			return $this->redemption_data( $row ) + array(
				'refunded' => array_sum( array_map( 'floatval', (array) $known['_refunds'] ) ),
				'restored' => 0.0,
			);
		}
		if ( $amount <= 0 ) {
			return self::error( 'vcap_invalid_request', __( 'A redemption key, a refund key and a positive amount are required.', 'voucher-captain' ), 400 );
		}
		if ( isset( $args['user_id'] ) && (int) $args['user_id'] !== (int) $row['user_id'] ) {
			return self::error( 'vcap_not_allowed', __( 'This redemption belongs to another user.', 'voucher-captain' ), 403 );
		}
		if ( self::STATE_COMMITTED !== $row['state'] ) {
			return self::error( 'vcap_not_committed', __( 'Only completed redemptions can be refunded.', 'voucher-captain' ), 409 );
		}
		if ( ! Install::tables_support_transactions() ) {
			return $this->temporary();
		}

		global $wpdb;
		$tables = Install::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $this->temporary();
		}
		try {
			$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['vouchers']} WHERE id = %d FOR UPDATE", (int) $row['voucher_id'] ) ); // phpcs:ignore
			$row     = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['redemptions']} WHERE redemption_key = %s FOR UPDATE", $key ), ARRAY_A ); // phpcs:ignore
			$voucher = $row ? $this->vouchers->get( (int) $row['voucher_id'] ) : null;
			if ( ! $row || ! $voucher ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
			$meta    = json_decode( (string) $row['meta'], true );
			$meta    = is_array( $meta ) ? $meta : array();
			$refunds = isset( $meta['_refunds'] ) && is_array( $meta['_refunds'] ) ? array_map( 'floatval', $meta['_refunds'] ) : array();
			if ( isset( $refunds[ $refund_key ] ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->redemption_data( $row ) + array(
					'refunded' => array_sum( $refunds ),
					'restored' => 0.0,
				);
			}
			$redeemed = (float) $row['amount'];
			$give     = round( min( $amount, $redeemed - array_sum( $refunds ) ), self::decimals( $voucher->currency ) );
			if ( $give <= 0 ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return self::error( 'vcap_refund_exceeded', __( 'This redemption has already been refunded completely.', 'voucher-captain' ), 409 );
			}
			$refunds[ $refund_key ] = $give;
			$total                  = array_sum( $refunds );
			$restored               = 0.0;
			if ( $voucher->is_multi_use() ) {
				$voucher->balance = round( $voucher->balance + $give, self::decimals( $voucher->currency ) );
				$restored         = $give;
				if ( Voucher::STATUS_REDEEMED === $voucher->status && $voucher->balance > 0 ) {
					$voucher->status = $voucher->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
				}
			} elseif ( $total + 0.0001 >= $redeemed && Voucher::STATUS_REDEEMED === $voucher->status ) {
				$voucher->status  = $voucher->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
				$voucher->balance = $voucher->initial_amount;
				$restored         = $redeemed;
			}
			$meta['_refunds'] = $refunds;
			$updated          = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$tables['redemptions'],
				array(
					'meta'       => wp_json_encode( $meta ),
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $row['id'] )
			);
			/* translators: 1: refund key, 2: redemption key */
			$note = sprintf( __( 'Refund %1$s of redemption %2$s', 'voucher-captain' ), $refund_key, $key );
			if ( 1 !== (int) $updated || ! $this->vouchers->update( $voucher ) || ! $this->vouchers->log( $voucher->id, 'api_refunded', $give, $voucher->balance, $note ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}

		$this->sync_coupon( $voucher );
		$data = $this->redemption_data( $this->get_row( $key ) ) + array(
			'refunded' => $total,
			'restored' => $restored,
		);
		/**
		 * Fires once per refund of a completed external redemption.
		 *
		 * @since 1.1.0
		 * @param array $data   Redemption data with refunded (total) and restored (this call).
		 * @param float $amount Amount refunded with this call.
		 */
		do_action( 'vcap_redemption_refunded', $data, $give );
		return $data;
	}

	/**
	 * Caller metadata as stored with a redemption. Keys starting with "_" are reserved for the service's own
	 * bookkeeping (e.g. "_refunds"): a caller could otherwise pre-seed refunds and restore a voucher or lift the
	 * refund cap. Nested values are kept as given.
	 *
	 * @param mixed $meta Caller metadata.
	 * @return array<string, mixed>
	 */
	public static function caller_meta( $meta ): array {
		if ( ! is_array( $meta ) ) {
			return array();
		}
		foreach ( array_keys( $meta ) as $key ) {
			if ( 0 === strpos( (string) $key, '_' ) ) {
				unset( $meta[ $key ] );
			}
		}
		return $meta;
	}

	/**
	 * Decimal places of a currency (ISO 4217 minor units), not the shop's price display setting: a shop that
	 * shows whole forints still books exact amounts. Unknown or empty currency: 2 (the internal precision).
	 *
	 * @param string $currency ISO 4217 code.
	 * @return int
	 */
	public static function decimals( string $currency ): int {
		$currency = strtoupper( $currency );
		$zero     = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$three    = array( 'BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND' );
		$decimals = in_array( $currency, $zero, true ) ? 0 : ( in_array( $currency, $three, true ) ? 3 : 2 );
		/**
		 * Decimal places used for voucher amounts of a currency.
		 *
		 * @since 1.2.0
		 * @param int    $decimals Decimal places.
		 * @param string $currency ISO 4217 code.
		 */
		return (int) apply_filters( 'vcap_currency_decimals', $decimals, $currency );
	}

	/**
	 * Read a redemption.
	 *
	 * @param string $key Redemption key.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( string $key ) {
		$row = preg_match( self::KEY_PATTERN, $key ) ? $this->get_row( $key ) : null;
		return $row ? $this->redemption_data( $row ) : self::error( 'vcap_reservation_missing', __( 'No redemption exists for this key.', 'voucher-captain' ), 404 );
	}

	/**
	 * Action Scheduler callback: release one reservation if it is still pending after its TTL.
	 *
	 * @param int|array $redemption_id Redemption id.
	 */
	public function release_expired( $redemption_id ): void {
		global $wpdb;
		$redemption_id = is_array( $redemption_id ) ? (int) ( $redemption_id['redemption_id'] ?? 0 ) : (int) $redemption_id;
		$table         = Install::tables()['redemptions'];
		$key           = $wpdb->get_var( $wpdb->prepare( "SELECT redemption_key FROM {$table} WHERE id = %d AND state = %s AND expires_at <= %s", $redemption_id, self::STATE_RESERVED, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		if ( $key ) {
			$this->transition( (string) $key, self::STATE_RELEASED, array(), true );
		}
	}

	/**
	 * Safety net for lost scheduled actions: release every expired reservation.
	 */
	public function release_all_expired(): void {
		global $wpdb;
		$table = Install::tables()['redemptions'];
		$keys  = $wpdb->get_col( $wpdb->prepare( "SELECT redemption_key FROM {$table} WHERE state = %s AND expires_at <= %s LIMIT 500", self::STATE_RESERVED, gmdate( 'Y-m-d H:i:s' ) ) ); // phpcs:ignore
		foreach ( $keys ? $keys : array() as $key ) {
			$this->transition( (string) $key, self::STATE_RELEASED, array(), true );
		}
	}

	// ------------------------------------------------------------------ internals.

	/**
	 * Move a reservation to committed or released inside one transaction.
	 *
	 * @param string $key     Redemption key.
	 * @param string $to      Target state.
	 * @param array  $args    user_id for ownership checks.
	 * @param bool   $expired Called by the expiry job.
	 * @return array<string, mixed>|\WP_Error
	 * @throws \Throwable Database errors are re-thrown after the transaction was rolled back.
	 */
	private function transition( string $key, string $to, array $args, bool $expired = false ) {
		if ( ! preg_match( self::KEY_PATTERN, $key ) ) {
			return self::error( 'vcap_invalid_request', __( 'Invalid redemption key.', 'voucher-captain' ), 400 );
		}
		$row = $this->get_row( $key );
		if ( ! $row ) {
			return self::error( 'vcap_reservation_missing', __( 'No redemption exists for this key.', 'voucher-captain' ), 404 );
		}
		if ( isset( $args['user_id'] ) && (int) $args['user_id'] !== (int) $row['user_id'] ) {
			return self::error( 'vcap_not_allowed', __( 'This redemption belongs to another user.', 'voucher-captain' ), 403 );
		}
		if ( $to === $row['state'] ) {
			return $this->redemption_data( $row ); // Idempotent repeat.
		}
		if ( self::STATE_RESERVED !== $row['state'] ) {
			return self::STATE_COMMITTED === $row['state']
				? self::error( 'vcap_already_redeemed', __( 'This redemption has already been completed.', 'voucher-captain' ), 409 )
				: self::error( 'vcap_reservation_missing', __( 'This reservation has expired or was cancelled. Please redeem the voucher again.', 'voucher-captain' ), 409 );
		}
		if ( ! Install::tables_support_transactions() ) {
			return $this->temporary();
		}

		global $wpdb;
		$tables = Install::tables();
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $this->temporary();
		}
		try {
			// Same lock order as reserve(): voucher row first, then the redemption row.
			$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$tables['vouchers']} WHERE id = %d FOR UPDATE", (int) $row['voucher_id'] ) ); // phpcs:ignore
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$tables['redemptions']} WHERE redemption_key = %s FOR UPDATE", $key ), ARRAY_A ); // phpcs:ignore
			if ( ! $row || self::STATE_RESERVED !== $row['state'] ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $row && $to === $row['state'] ? $this->redemption_data( $row ) : self::error( 'vcap_reservation_missing', __( 'This reservation has expired or was cancelled. Please redeem the voucher again.', 'voucher-captain' ), 409 );
			}
			$voucher = $this->vouchers->get( (int) $row['voucher_id'] );
			if ( ! $voucher ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
			$amount = (float) $row['amount'];
			/* translators: 1: redemption key, 2: target */
			$note = sprintf( __( 'Redemption %1$s for %2$s', 'voucher-captain' ), $key, $row['target'] );

			if ( self::STATE_COMMITTED === $to && Voucher::STATUS_VOID === $voucher->status ) {
				// Voided while reserved (e.g. the purchase order was refunded): never complete the redemption.
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return self::error( 'vcap_cancelled', __( 'This voucher has been cancelled.', 'voucher-captain' ), 410 );
			}
			if ( self::STATE_COMMITTED === $to ) {
				if ( ! $voucher->is_multi_use() ) {
					$voucher->status  = Voucher::STATUS_REDEEMED;
					$voucher->balance = 0;
				}
				$event = 'api_committed';
			} else {
				if ( $voucher->is_multi_use() ) {
					$voucher->balance = round( $voucher->balance + $amount, self::decimals( $voucher->currency ) );
				}
				if ( in_array( $voucher->status, array( Voucher::STATUS_RESERVED, Voucher::STATUS_REDEEMED ), true ) && ( ! $voucher->is_multi_use() || $voucher->balance > 0 ) ) {
					$voucher->status = $voucher->is_expired() ? Voucher::STATUS_EXPIRED : Voucher::STATUS_ACTIVE;
				}
				$event = 'api_released';
				if ( $expired ) {
					$note .= ' (' . __( 'expired', 'voucher-captain' ) . ')';
				}
			}
			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$tables['redemptions'],
				array(
					'state'      => $to,
					'updated_at' => gmdate( 'Y-m-d H:i:s' ),
				),
				array( 'id' => (int) $row['id'] )
			);
			if ( 1 !== (int) $updated || ! $this->vouchers->update( $voucher ) || ! $this->vouchers->log( $voucher->id, $event, $amount, $voucher->balance, $note ) ) {
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				return $this->temporary();
			}
		} catch ( \Throwable $e ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			throw $e;
		}

		$this->sync_coupon( $voucher );
		$data = $this->redemption_data( $this->get_row( $key ) );
		if ( self::STATE_COMMITTED === $to ) {
			/**
			 * Fires once when an external redemption is completed.
			 *
			 * @since 1.1.0
			 * @param array $data Redemption data: redemption_key, voucher_id, code, user_id, target, amount,
			 *                    currency, state, committed_at (UTC), meta.
			 */
			do_action( 'vcap_voucher_redeemed', $data );
		} else {
			/**
			 * Fires when a reservation was released (cancelled by the caller or expired).
			 *
			 * @since 1.1.0
			 * @param array $data    Redemption data.
			 * @param bool  $expired Whether the reservation expired.
			 */
			do_action( 'vcap_redemption_released', $data, $expired );
		}
		return $data;
	}

	/**
	 * Business rules that decide whether a voucher may be redeemed for a target by a user.
	 *
	 * @param Voucher    $voucher Voucher.
	 * @param string     $target  Redemption target.
	 * @param float|null $amount  Requested gift card amount (null = whole voucher / not checked).
	 * @param int        $user_id User id.
	 * @param string     $email   Customer email given by a trusted PHP caller (e.g. a guest checkout), else ''.
	 * @return true|\WP_Error
	 */
	private function eligibility( Voucher $voucher, string $target, ?float $amount, int $user_id, string $email = '' ) {
		if ( 'credit' === $voucher->type ) {
			// Store credit belongs to one customer account and is only used in the shop (owner check there).
			return self::error( 'vcap_not_redeemable', __( 'Store credit can only be used in the shop.', 'voucher-captain' ), 422 );
		}
		if ( $voucher->is_ticket() ) {
			return self::error( 'vcap_not_redeemable', __( 'Tickets are checked in at the entrance and cannot be redeemed here.', 'voucher-captain' ), 422 );
		}
		if ( ! in_array( $voucher->usage_mode, array( 'single', 'balance' ), true ) ) {
			return self::error( 'vcap_not_redeemable', __( 'This voucher can only be used in the shop.', 'voucher-captain' ), 422 );
		}
		if ( ! Channels::allowed( $voucher, Channels::for_target( $target ) ) ) {
			return self::error( 'vcap_not_redeemable', __( 'This voucher can only be used in the shop.', 'voucher-captain' ), 422 );
		}
		switch ( $voucher->status ) {
			case Voucher::STATUS_VOID:
				return self::error( 'vcap_cancelled', __( 'This voucher has been cancelled.', 'voucher-captain' ), 410 );
			case Voucher::STATUS_SCHEDULED:
				return self::error( 'vcap_not_active', __( 'This voucher is not active yet.', 'voucher-captain' ), 409 );
			case Voucher::STATUS_RESERVED:
				return self::error( 'vcap_reserved', __( 'This voucher is currently being redeemed. Please try again in a few minutes.', 'voucher-captain' ), 409 );
			case Voucher::STATUS_REDEEMED:
				return self::error( 'vcap_already_redeemed', __( 'This voucher has already been redeemed.', 'voucher-captain' ), 409 );
		}
		if ( \VCAP\CouponManager::held_by_pending_order( $voucher ) ) {
			// In an unpaid shop order right now: redeeming it here too would spend it twice.
			return self::error( 'vcap_reserved', __( 'This voucher is currently being redeemed. Please try again in a few minutes.', 'voucher-captain' ), 409 );
		}
		if ( Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			return self::error( 'vcap_expired', __( 'This voucher has expired.', 'voucher-captain' ), 410 );
		}
		if ( $voucher->is_multi_use() ) {
			if ( $voucher->balance <= 0 ) {
				return self::error( 'vcap_already_redeemed', __( 'This voucher has already been redeemed.', 'voucher-captain' ), 409 );
			}
			if ( null !== $amount && $amount > $voucher->balance + 0.00001 ) {
				return self::error( 'vcap_insufficient_balance', __( 'The remaining voucher balance is not sufficient.', 'voucher-captain' ), 409 );
			}
		}
		// Email-restricted coupons (e.g. "recipient only") also restrict external redemption.
		if ( $voucher->coupon_id ) {
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			$emails = $coupon->get_id() ? array_map( 'strtolower', (array) $coupon->get_email_restrictions() ) : array();
			if ( $emails ) {
				$user     = $user_id ? get_userdata( $user_id ) : null;
				$accepted = array_filter( array( $user ? strtolower( (string) $user->user_email ) : '', strtolower( trim( $email ) ) ) );
				if ( ! array_intersect( $accepted, $emails ) ) {
					return self::error( 'vcap_not_allowed', __( 'This voucher is not valid for your account.', 'voucher-captain' ), 403 );
				}
			}
		}
		/**
		 * Filters whether a voucher may be redeemed for a target (e.g. "only course vouchers unlock courses").
		 * Return false or a WP_Error to refuse.
		 *
		 * @since 1.1.0
		 * @param true|\WP_Error|bool $allowed Current decision.
		 * @param Voucher            $voucher Voucher.
		 * @param string             $target  Redemption target.
		 * @param int                $user_id Authenticated user id (0 for anonymous/system).
		 */
		$allowed = apply_filters( 'vcap_redemption_allowed', true, $voucher, $target, $user_id );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}
		if ( true !== $allowed ) {
			return self::error( 'vcap_not_allowed', __( 'This voucher cannot be used for this purpose.', 'voucher-captain' ), 403 );
		}
		/**
		 * Filters whether the redemption target is currently available (e.g. the course still exists).
		 *
		 * @since 1.1.0
		 * @param bool    $available Whether the target can be delivered.
		 * @param string  $target    Redemption target.
		 * @param Voucher $voucher   Voucher.
		 */
		if ( '' !== $target && ! apply_filters( 'vcap_redemption_target_available', true, $target, $voucher ) ) {
			return self::error( 'vcap_target_unavailable', __( 'The selected offer is currently not available.', 'voucher-captain' ), 409 );
		}
		return true;
	}

	/**
	 * Find a voucher by code (codes are case-insensitive like WooCommerce coupons).
	 *
	 * @param string $code Code.
	 * @return Voucher|null
	 */
	private function find( string $code ): ?Voucher {
		$code = trim( sanitize_text_field( $code ) );
		if ( '' === $code || strlen( $code ) > 100 ) {
			return null;
		}
		return $this->vouchers->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->vouchers->get_by_code( strtoupper( $code ) ) ?? $this->vouchers->get_by_code( $code );
	}

	/**
	 * The acting user: explicit user_id from trusted PHP callers, otherwise the logged-in user.
	 *
	 * @param array $args Arguments.
	 * @return int
	 */
	private function user_id( array $args ): int {
		return isset( $args['user_id'] ) ? max( 0, (int) $args['user_id'] ) : get_current_user_id();
	}

	/**
	 * Normalise a target identifier such as "learnboating:product:123".
	 *
	 * @param string $target Target.
	 * @return string
	 */
	private function clean_target( string $target ): string {
		return substr( (string) preg_replace( '/[^A-Za-z0-9._:\/\-]/', '', $target ), 0, 191 );
	}

	/**
	 * Fingerprint of a reserve request for idempotency checks.
	 *
	 * @param int    $voucher_id Voucher id.
	 * @param string $target     Target.
	 * @param float  $amount     Amount.
	 * @param int    $user_id    User id.
	 * @return string
	 */
	public static function request_hash( int $voucher_id, string $target, float $amount, int $user_id ): string {
		return hash( 'sha256', implode( '|', array( $voucher_id, $target, number_format( $amount, 4, '.', '' ), $user_id ) ) );
	}

	/**
	 * Load a redemption row.
	 *
	 * @param string $key Key.
	 * @return array<string, mixed>|null
	 */
	private function get_row( string $key ): ?array {
		global $wpdb;
		$table = Install::tables()['redemptions'];
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE redemption_key = %s", $key ), ARRAY_A ); // phpcs:ignore
		return $row ? $row : null;
	}

	/**
	 * Public representation of a redemption.
	 *
	 * @param array|null $row Row.
	 * @return array<string, mixed>
	 */
	private function redemption_data( ?array $row ): array {
		if ( ! $row ) {
			return array();
		}
		$voucher = $this->vouchers->get( (int) $row['voucher_id'] );
		$meta    = json_decode( (string) $row['meta'], true );
		return array(
			'redemption_key' => (string) $row['redemption_key'],
			'state'          => (string) $row['state'],
			'voucher_id'     => (int) $row['voucher_id'],
			'code'           => $voucher ? $voucher->code : '',
			'user_id'        => (int) $row['user_id'],
			'target'         => (string) $row['target'],
			'amount'         => (float) $row['amount'],
			'currency'       => $voucher ? $voucher->currency : '',
			'balance_after'  => $voucher && $voucher->is_multi_use() ? $voucher->balance : null,
			'expires_at'     => self::STATE_RESERVED === $row['state'] ? (string) $row['expires_at'] : null,
			'created_at'     => (string) $row['created_at'],
			'committed_at'   => self::STATE_COMMITTED === $row['state'] ? (string) $row['updated_at'] : null,
			'meta'           => is_array( $meta ) ? $meta : array(),
		);
	}

	/**
	 * Public representation of a voucher for availability checks (no personal data).
	 *
	 * @param Voucher $voucher Voucher.
	 * @return array<string, mixed>
	 */
	private function voucher_data( Voucher $voucher ): array {
		return array(
			'code'       => $voucher->code,
			'type'       => $voucher->type,
			'usage_mode' => $voucher->usage_mode,
			'value'      => $voucher->initial_amount,
			'balance'    => $voucher->is_multi_use() ? $voucher->balance : null,
			'currency'   => $voucher->currency,
			'expires_at' => $voucher->expires_at,
			'product_id' => $voucher->variation_id ? $voucher->variation_id : $voucher->product_id,
		);
	}

	/**
	 * Keep the WooCommerce coupon in line with the voucher (gift card amount; single-use usage).
	 *
	 * @param Voucher $voucher Voucher.
	 */
	private function sync_coupon( Voucher $voucher ): void {
		if ( ! $voucher->coupon_id ) {
			return;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		if ( ! $coupon->get_id() ) {
			return;
		}
		if ( $voucher->is_multi_use() ) {
			$coupon->set_amount( $voucher->balance );
		} elseif ( Voucher::STATUS_REDEEMED === $voucher->status ) {
			// Used up outside the cart: make WooCommerce's own usage limit refuse it too.
			$coupon->set_usage_count( max( 1, (int) $coupon->get_usage_count() ) );
			$coupon->set_usage_limit( max( 1, (int) $coupon->get_usage_count() ) );
		} elseif ( Voucher::STATUS_ACTIVE === $voucher->status && $coupon->get_usage_limit() > 0 && $coupon->get_usage_count() >= $coupon->get_usage_limit() ) {
			// Usable again after a full refund: allow exactly one more use.
			$coupon->set_usage_limit( (int) $coupon->get_usage_count() + 1 );
		}
		$coupon->save();
	}

	/**
	 * Generic retryable failure.
	 *
	 * @return \WP_Error
	 */
	private function temporary(): \WP_Error {
		return self::error( 'vcap_temporary_failure', __( 'The voucher could not be redeemed right now. Please try again.', 'voucher-captain' ), 503 );
	}

	/**
	 * Failed-code rate limit per user (and per visitor for anonymous calls).
	 *
	 * @param int $user_id User id.
	 * @return string
	 */
	private function failure_key( int $user_id ): string {
		return 'vcap_rl_redeem_' . ( $user_id ? 'u' . $user_id : md5( (string) \WC_Geolocation::get_ip_address() ) );
	}

	/**
	 * Whether the caller exceeded the configured number of invalid codes.
	 *
	 * @param int $user_id User id.
	 * @return bool
	 */
	private function too_many_failures( int $user_id ): bool {
		$max = (int) get_option( 'vcap_redemption_max_failures', 10 );
		return $max > 0 && (int) get_transient( $this->failure_key( $user_id ) ) >= $max;
	}

	/**
	 * Count an invalid code.
	 *
	 * @param int $user_id User id.
	 */
	private function record_failure( int $user_id ): void {
		$key = $this->failure_key( $user_id );
		set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
	}
}
