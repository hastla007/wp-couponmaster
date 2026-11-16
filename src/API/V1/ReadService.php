<?php
/**
 * Read-only facade for the state of vouchers, gift cards, store credit and external redemptions.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Install;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the state of a voucher by its record id, for add-ons and automations that must re-check an event or
 * evaluate a condition without knowing the voucher code (VC-7, suite spec 8.1 "read" and 7.4 "re-read").
 *
 * Every method returns a fixed list of keys (positive list, documented in API.md → "Read functions") or null for an
 * unknown id. The SQL names its columns: the voucher code, the token, buyer and recipient names and addresses, the
 * message, personalisation fields, the redemption key, the target, meta, notes, tills and receipts are never selected,
 * so they cannot reach a caller. Amounts are integers in the smallest unit of the currency (`*_minor` with
 * `currency`), timestamps are RFC 3339 in UTC (`2026-11-16T09:30:00Z`) or null.
 *
 * Nothing is written: no reservation is released, no coupon repaired, no cache primed, no hook fired. The methods do
 * not check the current user; the caller decides who may see the result.
 *
 * @since 1.2.0 (contract 1.2.0)
 */
final class ReadService {
	/** Keys of read_voucher(), in this order. */
	public const VOUCHER_KEYS = array( 'voucher_id', 'type', 'status', 'usage_mode', 'currency', 'amount_minor', 'balance_minor', 'order_id', 'product_id', 'user_id', 'transferable', 'expires_at', 'created_at', 'updated_at', 'send_to', 'delivery_state', 'delivery_at', 'delivered_at', 'delivered_roles', 'delivery_failures', 'checkins', 'max_checkins', 'last_checkin_at', 'void_reason', 'redemption_counts' );

	/** Further keys of read_gift_card() (after the voucher keys). */
	public const GIFT_CARD_KEYS = array( 'top_up_count', 'top_up_reversed_count' );

	/** Keys of read_store_credit(). */
	public const STORE_CREDIT_KEYS = array( 'user_id', 'account_id', 'status', 'currency', 'balance_minor', 'created_at', 'updated_at' );

	/** Keys of read_redemption() and of each entry of read_redemptions(). */
	public const REDEMPTION_KEYS = array( 'redemption_id', 'voucher_id', 'state', 'channel', 'amount_minor', 'currency', 'user_id', 'expires_at', 'created_at', 'committed_at' );

	/** Keys of read_order_vouchers(). */
	public const ORDER_KEYS = array( 'order_id', 'voucher_count', 'voucher_ids' );

	/** Values of `delivery_state`. */
	public const DELIVERY_STATES = array( 'delivered', 'scheduled', 'pending', 'given_up' );

	/** Most entries read_redemptions() returns (newest first). */
	public const MAX_REDEMPTIONS = 100;

	/** Columns of the voucher table this facade reads – never code, token, names, addresses, message, fields, terms. */
	private const VOUCHER_COLUMNS = 'id, type, status, order_id, product_id, variation_id, usage_mode, initial_amount, balance, currency, expires_at, user_id, send_to, delivery_at, delivered_at, delivered_roles, transferable, max_checkins, checkins, last_checkin_at, created_at, updated_at';

	/** Columns of the redemption table this facade reads – never redemption_key, target, request_hash, meta. */
	private const REDEMPTION_COLUMNS = 'id, voucher_id, user_id, amount, state, expires_at, created_at, updated_at';

	/**
	 * Construct the read service.
	 *
	 * @param VoucherRepository             $vouchers    Repository (only its read methods are used: delivery attempts, event counts).
	 * @param (\Closure(Voucher):bool)|null $void_reason Whether a void voucher was voided because of a refund (Issuer::voided_after_refund()); null leaves `void_reason` null.
	 */
	public function __construct( private VoucherRepository $vouchers, private ?\Closure $void_reason = null ) {}

	/**
	 * State of a voucher, gift card, ticket or store credit account by its record id.
	 *
	 * @since 1.2.0
	 * @param int|string $id Voucher id (`voucher_id` of the events); a string must be a positive whole number.
	 * @return array<string, mixed>|null The keys of VOUCHER_KEYS, or null when no voucher has this id.
	 */
	public function voucher( int|string $id ): ?array {
		$row = $this->voucher_row( $id );
		return null === $row ? null : $this->voucher_data( $row );
	}

	/**
	 * State of a gift card (type `giftcard`) by its record id, with the number of top-ups.
	 *
	 * @since 1.2.0
	 * @param int|string $id Voucher id of the gift card.
	 * @return array<string, mixed>|null The keys of VOUCHER_KEYS and GIFT_CARD_KEYS, or null when no gift card has this id.
	 */
	public function gift_card( int|string $id ): ?array {
		$row = $this->voucher_row( $id );
		if ( null === $row || 'giftcard' !== (string) $row['type'] ) {
			return null;
		}
		$id = (int) $row['id'];
		return $this->voucher_data( $row ) + array(
			'top_up_count'          => $this->vouchers->count_event( $id, 'topup' ),
			'top_up_reversed_count' => $this->vouchers->count_event( $id, 'topup_reversed' ),
		);
	}

	/**
	 * Store credit account of a customer by WordPress user id. Opens no account and repairs nothing.
	 *
	 * @since 1.2.0
	 * @param int|string $user_id WordPress user id of the customer.
	 * @return array<string, mixed>|null The keys of STORE_CREDIT_KEYS, or null when the user has no account.
	 */
	public function store_credit( int|string $user_id ): ?array {
		$user_id = self::id( $user_id );
		if ( null === $user_id ) {
			return null;
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, status, currency, balance, user_id, created_at, updated_at FROM ' . $table . " WHERE user_id = %d AND type = 'credit' ORDER BY id ASC LIMIT 1", $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name of this plugin.
		if ( ! is_array( $row ) ) {
			return null;
		}
		$currency = (string) $row['currency'];
		return array(
			'user_id'       => (int) $row['user_id'],
			'account_id'    => (int) $row['id'],
			'status'        => (string) $row['status'],
			'currency'      => $currency,
			'balance_minor' => self::minor( $row['balance'], $currency ),
			'created_at'    => self::utc( $row['created_at'] ),
			'updated_at'    => self::utc( $row['updated_at'] ),
		);
	}

	/**
	 * An external redemption (vcap()->redemptions) by its record id. Reads the row as stored: an overdue
	 * reservation is reported as `reserved` with its past `expires_at` (the background job releases it).
	 *
	 * @since 1.2.0
	 * @param int|string $id Record id of the redemption (`redemption_id` of read_redemptions()).
	 * @return array<string, mixed>|null The keys of REDEMPTION_KEYS, or null when no redemption has this id.
	 */
	public function redemption( int|string $id ): ?array {
		$id = self::id( $id );
		if ( null === $id ) {
			return null;
		}
		global $wpdb;
		$tables = Install::tables();
		$row    = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::REDEMPTION_COLUMNS . ' FROM ' . $tables['redemptions'] . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed columns, table name of this plugin.
		if ( ! is_array( $row ) ) {
			return null;
		}
		return $this->redemption_data( $row, $this->currency_of( (int) $row['voucher_id'] ) );
	}

	/**
	 * External redemptions of a voucher, newest first (at most MAX_REDEMPTIONS).
	 *
	 * @since 1.2.0
	 * @param int|string $voucher_id Voucher id.
	 * @return array<int, array<string, mixed>>|null Entries with the keys of REDEMPTION_KEYS (empty when it has none), or null when no voucher has this id.
	 */
	public function redemptions( int|string $voucher_id ): ?array {
		$voucher_id = self::id( $voucher_id );
		$currency   = null === $voucher_id ? null : $this->currency_of( $voucher_id );
		if ( null === $currency ) {
			return null;
		}
		global $wpdb;
		$tables = Install::tables();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . self::REDEMPTION_COLUMNS . ' FROM ' . $tables['redemptions'] . ' WHERE voucher_id = %d ORDER BY id DESC LIMIT %d', $voucher_id, self::MAX_REDEMPTIONS ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed columns, table name of this plugin.
		$list   = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( is_array( $row ) ) {
				$list[] = $this->redemption_data( $row, $currency );
			}
		}
		return $list;
	}

	/**
	 * The vouchers of a WooCommerce order: their number and record ids (each readable with voucher()).
	 *
	 * @since 1.2.0
	 * @param int|string $order_id WooCommerce order id.
	 * @return array<string, mixed>|null The keys of ORDER_KEYS, or null when no voucher belongs to this order.
	 */
	public function order_vouchers( int|string $order_id ): ?array {
		$order_id = self::id( $order_id );
		if ( null === $order_id ) {
			return null;
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$ids   = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . $table . ' WHERE order_id = %d ORDER BY id ASC', $order_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name of this plugin.
		$ids   = array_values( array_filter( array_map( 'intval', is_array( $ids ) ? $ids : array() ) ) );
		if ( array() === $ids ) {
			return null;
		}
		return array(
			'order_id'      => $order_id,
			'voucher_count' => count( $ids ),
			'voucher_ids'   => $ids,
		);
	}

	/**
	 * The voucher row (allowed columns only) or null.
	 *
	 * @param int|string $id Voucher id.
	 * @return array<string, mixed>|null
	 */
	private function voucher_row( int|string $id ): ?array {
		$id = self::id( $id );
		if ( null === $id ) {
			return null;
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::VOUCHER_COLUMNS . ' FROM ' . $table . ' WHERE id = %d', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- fixed columns, table name of this plugin.
		return is_array( $row ) && (int) ( $row['id'] ?? 0 ) === $id ? $row : null;
	}

	/**
	 * The public state of a voucher row.
	 *
	 * @param array<string, mixed> $row Row with VOUCHER_COLUMNS.
	 * @return array<string, mixed>
	 */
	private function voucher_data( array $row ): array {
		$id        = (int) $row['id'];
		$currency  = (string) $row['currency'];
		$status    = (string) $row['status'];
		$order_id  = (int) $row['order_id'];
		$delivered = self::utc( $row['delivered_at'] ?? null );
		$scheduled = self::utc( $row['delivery_at'] ?? null );
		$attempts  = null === $delivered ? $this->vouchers->delivery_attempts( $id ) : array(
			'failures' => 0,
			'given_up' => false,
		);
		if ( null !== $delivered ) {
			$delivery_state = 'delivered';
		} elseif ( ! empty( $attempts['given_up'] ) ) {
			$delivery_state = 'given_up';
		} elseif ( null !== $scheduled && strtotime( $scheduled ) > time() ) {
			$delivery_state = 'scheduled';
		} else {
			$delivery_state = 'pending';
		}
		$product = (int) $row['variation_id'] > 0 ? (int) $row['variation_id'] : (int) $row['product_id'];
		$roles   = array_values( array_intersect( array( 'recipient', 'buyer' ), explode( ',', (string) ( $row['delivered_roles'] ?? '' ) ) ) );
		return array(
			'voucher_id'        => $id,
			'type'              => (string) $row['type'],
			'status'            => $status,
			'usage_mode'        => (string) $row['usage_mode'],
			'currency'          => $currency,
			'amount_minor'      => self::minor( $row['initial_amount'], $currency ),
			'balance_minor'     => self::minor( $row['balance'], $currency ),
			'order_id'          => $order_id > 0 ? $order_id : null,
			'product_id'        => $product > 0 ? $product : null,
			'user_id'           => (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
			'transferable'      => (bool) (int) $row['transferable'],
			'expires_at'        => self::utc( $row['expires_at'] ?? null ),
			'created_at'        => self::utc( $row['created_at'] ?? null ),
			'updated_at'        => self::utc( $row['updated_at'] ?? null ),
			'send_to'           => in_array( (string) $row['send_to'], array( 'buyer', 'recipient', 'both' ), true ) ? (string) $row['send_to'] : 'other',
			'delivery_state'    => $delivery_state,
			'delivery_at'       => $scheduled,
			'delivered_at'      => $delivered,
			'delivered_roles'   => $roles,
			'delivery_failures' => (int) ( $attempts['failures'] ?? 0 ),
			'checkins'          => (int) $row['checkins'],
			'max_checkins'      => (int) $row['max_checkins'],
			'last_checkin_at'   => self::utc( $row['last_checkin_at'] ?? null ),
			'void_reason'       => $this->void_reason( $id, $status, $order_id ),
			'redemption_counts' => $this->redemption_counts( $id ),
		);
	}

	/**
	 * `refund` or `manual` for a void voucher (Issuer::voided_after_refund(), never from the free-text note), else null.
	 *
	 * @param int    $id       Voucher id.
	 * @param string $status   Status.
	 * @param int    $order_id Order id.
	 * @return string|null
	 */
	private function void_reason( int $id, string $status, int $order_id ): ?string {
		if ( Voucher::STATUS_VOID !== $status || null === $this->void_reason ) {
			return null;
		}
		// A voucher object with the three fields the check needs; code, token and personal data stay empty.
		$voucher           = new Voucher();
		$voucher->id       = $id;
		$voucher->status   = $status;
		$voucher->order_id = $order_id;
		return ( $this->void_reason )( $voucher ) ? 'refund' : 'manual';
	}

	/**
	 * Number of external redemptions per state (`reserved`, `committed`, `released`).
	 *
	 * @param int $id Voucher id.
	 * @return array<string, int>
	 */
	private function redemption_counts( int $id ): array {
		global $wpdb;
		$tables = Install::tables();
		$rows   = $wpdb->get_results( $wpdb->prepare( 'SELECT state, COUNT(*) AS n FROM ' . $tables['redemptions'] . ' WHERE voucher_id = %d GROUP BY state', $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name of this plugin.
		$counts = array(
			RedemptionService::STATE_RESERVED  => 0,
			RedemptionService::STATE_COMMITTED => 0,
			RedemptionService::STATE_RELEASED  => 0,
		);
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$state = is_array( $row ) ? (string) ( $row['state'] ?? '' ) : '';
			if ( isset( $counts[ $state ] ) ) {
				$counts[ $state ] = (int) $row['n'];
			}
		}
		return $counts;
	}

	/**
	 * The public state of a redemption row.
	 *
	 * @param array<string, mixed> $row      Row with REDEMPTION_COLUMNS.
	 * @param string|null          $currency Currency of the voucher (null when the voucher is gone).
	 * @return array<string, mixed>
	 */
	private function redemption_data( array $row, ?string $currency ): array {
		$state = (string) $row['state'];
		return array(
			'redemption_id' => (int) $row['id'],
			'voucher_id'    => (int) $row['voucher_id'],
			'state'         => $state,
			'channel'       => 'external',
			'amount_minor'  => null === $currency ? null : self::minor( $row['amount'], $currency ),
			'currency'      => $currency,
			'user_id'       => (int) $row['user_id'] > 0 ? (int) $row['user_id'] : null,
			'expires_at'    => RedemptionService::STATE_RESERVED === $state ? self::utc( $row['expires_at'] ?? null ) : null,
			'created_at'    => self::utc( $row['created_at'] ?? null ),
			'committed_at'  => RedemptionService::STATE_COMMITTED === $state ? self::utc( $row['updated_at'] ?? null ) : null,
		);
	}

	/**
	 * Currency of a voucher, or null when no voucher has this id.
	 *
	 * @param int $voucher_id Voucher id.
	 * @return string|null
	 */
	private function currency_of( int $voucher_id ): ?string {
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT id, currency FROM ' . $table . ' WHERE id = %d', $voucher_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.NotPrepared -- table name of this plugin.
		return is_array( $row ) && (int) ( $row['id'] ?? 0 ) === $voucher_id ? (string) $row['currency'] : null;
	}

	/**
	 * A positive record id from an int or a string of digits, else null (a code or any other text is no id).
	 *
	 * @param int|string $id Id.
	 * @return int|null
	 */
	private static function id( int|string $id ): ?int {
		if ( is_string( $id ) ) {
			if ( ! preg_match( '/^[1-9][0-9]{0,18}$/', $id ) ) {
				return null;
			}
			$id = (int) $id;
		}
		return $id > 0 ? $id : null;
	}

	/**
	 * Amount as an integer in the smallest unit of the currency (ISO 4217 minor units, config/currencies.php).
	 *
	 * @param mixed  $amount   Stored amount (decimal string).
	 * @param string $currency Currency code.
	 * @return int
	 */
	private static function minor( mixed $amount, string $currency ): int {
		return (int) round( (float) ( is_numeric( $amount ) ? $amount : 0 ) * ( 10 ** RedemptionService::decimals( $currency ) ) );
	}

	/**
	 * A stored UTC datetime (`Y-m-d H:i:s`) as RFC 3339 with `Z`, or null.
	 *
	 * @param mixed $value Stored value.
	 * @return string|null
	 */
	private static function utc( mixed $value ): ?string {
		$value = is_string( $value ) ? trim( $value ) : '';
		if ( '' === $value || str_starts_with( $value, '0000-00-00' ) ) {
			return null;
		}
		$time = strtotime( $value . ' UTC' );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}
}
