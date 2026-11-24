<?php
/**
 * Stable PHP facade for voucher integrations.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Contracts\RedemptionProvider;
use VCAP\CouponManager;
use VCAP\Issuer;
use VCAP\Logger;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Stable PHP facade for voucher integrations. Order ids scope idempotent reserve/commit retries.
 *
 * @since 1.1.0
 */
final class VoucherService {
	/**
	 * Construct the voucher service.
	 *
	 * @param VoucherRepository $vouchers Voucher repository.
	 * @param CouponManager     $coupons Coupon manager.
	 */
	public function __construct( private VoucherRepository $vouchers, private CouponManager $coupons ) {}

	/**
	 * Look up a voucher through the configured provider.
	 *
	 * @param string $code Voucher code.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function lookup( string $code ): array|\WP_Error {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : self::checked( $provider->lookup( $code ), $provider );
	}

	/**
	 * Reserve a balance amount for a saved order.
	 *
	 * @param string    $code Voucher code.
	 * @param float     $amount Amount to reserve.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function reserve( string $code, float $amount, \WC_Order $order ): array|\WP_Error {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : self::checked( $provider->reserve( $code, $amount, $order ), $provider );
	}

	/**
	 * Commit a saved order's reservation.
	 *
	 * @param string    $code Voucher code.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function commit( string $code, \WC_Order $order ): array|\WP_Error {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : self::checked( $provider->commit( $code, $order ), $provider );
	}

	/**
	 * Release a saved order's reservation.
	 *
	 * @param string    $code Voucher code.
	 * @param \WC_Order $order Saved WooCommerce order.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function release( string $code, \WC_Order $order ): array|\WP_Error {
		$provider = $this->provider( $code );
		return is_wp_error( $provider ) ? $provider : self::checked( $provider->release( $code, $order ), $provider );
	}

	/**
	 * Third-party providers implement the untyped v1 contract (RedemptionProvider declares no native return types,
	 * so existing providers keep working): a result that is neither an array nor a WP_Error is turned into a
	 * WP_Error here instead of a TypeError at the typed API boundary.
	 *
	 * @param mixed              $result   Provider result.
	 * @param RedemptionProvider $provider Provider that produced it.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function checked( mixed $result, RedemptionProvider $provider ): array|\WP_Error {
		if ( is_array( $result ) || $result instanceof \WP_Error ) {
			return $result;
		}
		Logger::error( 'A redemption provider returned neither an array nor a WP_Error.', array( 'provider' => get_class( $provider ) ) );
		return new \WP_Error( 'vcap_provider_invalid_response', __( 'The voucher system returned an invalid response.', 'voucher-captain' ), array( 'status' => 502 ) );
	}

	/**
	 * VAT refunded for one order line, over all its tax rates.
	 *
	 * @param \WC_Order              $order Order the line belongs to.
	 * @param \WC_Order_Item_Product $item  Order line.
	 */
	private static function refunded_tax( \WC_Order $order, \WC_Order_Item_Product $item ): float {
		$total = 0.0;
		$taxes = (array) $item->get_taxes();
		foreach ( array_keys( (array) ( $taxes['total'] ?? array() ) ) as $rate_id ) {
			$total += (float) $order->get_tax_refunded_for_item( $item->get_id(), (int) $rate_id );
		}
		return round( $total, wc_get_price_decimals() );
	}

	/**
	 * Order and refund data per order line, for connector plugins (e.g. to grant or revoke access per line).
	 *
	 * Each product line contains its quantities, totals and refunds in the order currency, the voucher line
	 * snapshot (face value, voucher currency, tax data, allowed channels) and the vouchers issued for it.
	 *
	 * @since 1.1.0
	 * @param \WC_Order $order Order.
	 * @return array<int, array<string, mixed>> Lines keyed by order item id.
	 */
	public function order_lines( \WC_Order $order ): array {
		$vouchers = array();
		foreach ( $this->vouchers->for_order( $order->get_id() ) as $voucher ) {
			$vouchers[ $voucher->order_item_id ][] = array(
				'id'       => $voucher->id,
				'code'     => $voucher->code,
				'type'     => $voucher->type,
				'status'   => $voucher->status,
				'value'    => $voucher->initial_amount,
				'balance'  => $voucher->balance,
				'currency' => $voucher->currency,
				'expires'  => $voucher->expires_at,
			);
		}
		$lines = array();
		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}
			$lines[ (int) $item_id ] = array(
				'item_id'           => (int) $item_id,
				'product_id'        => (int) $item->get_product_id(),
				'variation_id'      => (int) $item->get_variation_id(),
				'name'              => $item->get_name(),
				'quantity'          => (int) $item->get_quantity(),
				'refunded_quantity' => abs( (int) $order->get_qty_refunded_for_item( $item_id ) ),
				'subtotal'          => (float) $item->get_subtotal(),
				'subtotal_tax'      => (float) $item->get_subtotal_tax(),
				'total'             => (float) $item->get_total(),
				'total_tax'         => (float) $item->get_total_tax(),
				'refunded_total'    => (float) $order->get_total_refunded_for_item( $item_id ),
				// Refunded VAT of this line (sum over its tax rates), for the VAT correction of a refund.
				'refunded_tax'      => self::refunded_tax( $order, $item ),
				'currency'          => $order->get_currency(),
				'voucher_line'      => Issuer::line_data( $item ),
				'vouchers'          => $vouchers[ (int) $item_id ] ?? array(),
			);
		}
		/**
		 * Filters the per-line order data handed to connector plugins.
		 *
		 * @since 1.1.0
		 * @param array<int, array<string, mixed>> $lines Lines keyed by order item id.
		 * @param \WC_Order                        $order Order.
		 */
		return (array) apply_filters( 'vcap_order_lines', $lines, $order );
	}

	/** Keys of voucher() and voucher_by_code(), in this order (API.md → "Connector access"). */
	public const CONNECTOR_VOUCHER_KEYS = array( 'id', 'code', 'type', 'status', 'usage_mode', 'multi_use', 'value', 'balance', 'currency', 'expires', 'order_id', 'order_item_id', 'product_id', 'variation_id', 'user_id', 'created' );

	/** Most characters of a history note written by a connector. */
	public const MAX_NOTE_LENGTH = 500;

	/**
	 * Events Voucher Captain writes itself. A connector may not write them (log_event() refuses them), so the
	 * history, the reports that count events and the support screens stay true. tests/run.php checks that every
	 * event the plugin writes is in this list.
	 */
	public const CORE_EVENTS = array( 'activated', 'adjusted', 'api_committed', 'api_refunded', 'api_released', 'api_reserved', 'checkin', 'checkin_undo', 'command', 'committed', 'contact_consent', 'contact_consent_withdrawn', 'contact_read', 'coupon_removed', 'credit', 'debit', 'delivered', 'delivered_without_pdf', 'delivery_attempt', 'delivery_expired', 'delivery_failed', 'delivery_given_up', 'double_use', 'downloaded', 'edited', 'expired', 'expiry_reminder', 'expiry_reversed', 'forfeited', 'gift_used_notice', 'imported', 'issued', 'moved_to_credit', 'note', 'passcaptain_restore_failed', 'passcaptain_restore_notified', 'passcaptain_revoke_failed', 'passcaptain_tickets', 'passcaptain_tickets_failed', 'passcaptain_tickets_resent', 'passcaptain_tickets_restored', 'passcaptain_tickets_revoked', 'privacy_erased', 'purchase_consent', 'purchase_refund_reversed', 'purchase_refunded', 'reactivated', 'recipient_corrected', 'redeem_reversed', 'redeemed', 'refund_credit', 'refund_credit_reversed', 'reissued', 'reservation_released', 'reserved', 'resent', 'rest_converted', 'rest_reverted', 'restored', 'returned', 'rounding_written_off', 'scheduled', 'suspended_payment', 'topup', 'topup_reversed', 'validity_extended', 'voided', 'voided_partial_refund' );

	/** Prefixes a connector event may not use: they belong to Voucher Captain and its own integrations. */
	public const RESERVED_EVENT_PREFIXES = array( 'vcap', 'voucher', 'api', 'passcaptain', 'wc', 'woocommerce', 'wp' );

	/**
	 * A voucher by its record id, for connector plugins (VOUCH-E17-02). Unlike vcap()->read->voucher() the result
	 * contains the code, because a connector redeems with it; it never contains the token, names, addresses, the
	 * message, personalisation fields or terms. Amounts are decimals in the voucher currency (as in order_lines()),
	 * `expires` and `created` are `Y-m-d H:i:s` in UTC or null. Reads only; checks no user, PIN or channel – the
	 * redemption itself goes through vcap()->redemptions, which does.
	 *
	 * @since 1.3.0 (contract 1.4.0)
	 * @param int $voucher_id Voucher id (`voucher_id` of the hooks and events).
	 * @return array<string, mixed>|null The keys of CONNECTOR_VOUCHER_KEYS, or null when no voucher has this id.
	 */
	public function voucher( int $voucher_id ): ?array {
		$voucher = $voucher_id > 0 ? $this->vouchers->get( $voucher_id ) : null;
		return $voucher instanceof Voucher && $voucher->id === $voucher_id ? self::connector_data( $voucher ) : null;
	}

	/**
	 * A voucher by its code as a customer typed it (case-insensitive like WooCommerce coupons; a `code#PIN` input
	 * is reduced to the code, the PIN is not checked here). Same result as voucher().
	 *
	 * @since 1.3.0 (contract 1.4.0)
	 * @param string $code Voucher code.
	 * @return array<string, mixed>|null The keys of CONNECTOR_VOUCHER_KEYS, or null when no voucher has this code.
	 */
	public function voucher_by_code( string $code ): ?array {
		$code = trim( sanitize_text_field( \VCAP\CodeLookup::code_input( $code ) ) );
		if ( '' === $code || strlen( $code ) > 100 ) {
			return null;
		}
		$voucher = $this->vouchers->get_by_code( wc_format_coupon_code( $code ) ) ?? $this->vouchers->get_by_code( strtoupper( $code ) ) ?? $this->vouchers->get_by_code( $code );
		return $voucher instanceof Voucher ? self::connector_data( $voucher ) : null;
	}

	/**
	 * Writes an entry of the connector into the history of a voucher (shown on the voucher screen; label through
	 * the filter `vcap_event_labels`). The event name is the connector's own: `<prefix>_<name>`, lower case,
	 * letters, digits and underscores, at most 64 characters, not one of CORE_EVENTS and not starting with one of
	 * RESERVED_EVENT_PREFIXES (e.g. `learnboating_granted`). The note is plain text (tags removed, at most
	 * MAX_NOTE_LENGTH characters); write no full code, no address and no secret into it. Changes no balance.
	 *
	 * @since 1.3.0 (contract 1.4.0)
	 * @param int    $voucher_id Voucher id.
	 * @param string $event      Event name of the connector.
	 * @param string $note       Note (optional).
	 * @param int    $order_id   Order the entry belongs to (optional, 0 for none).
	 * @return true|\WP_Error `vcap_unknown_voucher`, `vcap_invalid_event` or `vcap_log_failed`.
	 */
	public function log_event( int $voucher_id, string $event, string $note = '', int $order_id = 0 ): bool|\WP_Error {
		if ( ! self::connector_event( $event ) ) {
			return new \WP_Error( 'vcap_invalid_event', __( 'This event name cannot be written to the voucher history.', 'voucher-captain' ) );
		}
		$voucher = $voucher_id > 0 ? $this->vouchers->get( $voucher_id ) : null;
		if ( ! $voucher instanceof Voucher ) {
			return new \WP_Error( 'vcap_unknown_voucher', __( 'Voucher not found.', 'voucher-captain' ) );
		}
		$note = mb_substr( trim( wp_strip_all_tags( $note ) ), 0, self::MAX_NOTE_LENGTH );
		if ( ! $this->vouchers->log( $voucher->id, $event, null, null, $note, max( 0, $order_id ) ) ) {
			return new \WP_Error( 'vcap_log_failed', __( 'The voucher history could not be saved.', 'voucher-captain' ) );
		}
		return true;
	}

	/**
	 * Whether a connector may write this event name (see log_event()).
	 *
	 * @since 1.3.0 (contract 1.4.0)
	 * @param string $event Event name.
	 */
	public static function connector_event( string $event ): bool {
		if ( strlen( $event ) > 64 || ! preg_match( '/^([a-z][a-z0-9]*)_[a-z0-9]+(?:_[a-z0-9]+)*$/', $event, $m ) ) {
			return false;
		}
		return ! in_array( $event, self::CORE_EVENTS, true ) && ! in_array( $m[1], self::RESERVED_EVENT_PREFIXES, true );
	}

	/**
	 * Address of the voucher on the Voucher Captain admin screen (for links in the connector's own screens).
	 * Whether the current user may open it is checked by that screen.
	 *
	 * @since 1.3.0 (contract 1.4.0)
	 * @param int $voucher_id Voucher id.
	 */
	public function admin_url( int $voucher_id ): string {
		return add_query_arg(
			array(
				'page'    => 'vcap-vouchers',
				'voucher' => max( 0, $voucher_id ),
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * The snapshot Voucher Captain stored at a voucher order line when it issued the vouchers: the core keys
	 * (`face_value`, `voucher_currency`, `channels`, tax data …, API.md → "Order line snapshot") and the keys
	 * connectors added through the filter `vcap_line_snapshot`. Same data as `voucher_line` in order_lines(),
	 * without loading the whole order. Empty array for an unknown line or a line without vouchers.
	 *
	 * @since 1.3.0 (contract 1.4.0)
	 * @param int $order_item_id Order item id (`order_item_id` of voucher()).
	 * @return array<string, mixed>
	 */
	public function line_snapshot( int $order_item_id ): array {
		if ( $order_item_id <= 0 || ! function_exists( 'wc_get_order_item_meta' ) ) {
			return array();
		}
		$line = wc_get_order_item_meta( $order_item_id, Issuer::LINE_META, true );
		return is_array( $line ) ? $line : array();
	}

	/**
	 * The connector view of a voucher (CONNECTOR_VOUCHER_KEYS).
	 *
	 * @param Voucher $voucher Voucher.
	 * @return array<string, mixed>
	 */
	private static function connector_data( Voucher $voucher ): array {
		return array(
			'id'            => $voucher->id,
			'code'          => $voucher->code,
			'type'          => $voucher->type,
			'status'        => $voucher->status,
			'usage_mode'    => $voucher->usage_mode,
			'multi_use'     => $voucher->is_multi_use(),
			'value'         => $voucher->initial_amount,
			'balance'       => $voucher->balance,
			'currency'      => $voucher->currency,
			'expires'       => $voucher->expires_at,
			'order_id'      => $voucher->order_id,
			'order_item_id' => $voucher->order_item_id,
			'product_id'    => $voucher->product_id,
			'variation_id'  => $voucher->variation_id,
			'user_id'       => $voucher->user_id,
			'created'       => '' !== $voucher->created_at ? $voucher->created_at : null,
		);
	}

	/**
	 * Find the first provider that supports the voucher code.
	 *
	 * @param string $code Voucher code.
	 * @return RedemptionProvider|\WP_Error
	 */
	private function provider( string $code ): RedemptionProvider|\WP_Error {
		/**
		 * Filters the redemption providers available for a voucher code.
		 *
		 * @since 1.1.0
		 * @param RedemptionProvider[] $providers Registered provider adapters.
		 * @param string               $code      Voucher code being resolved.
		 * @param VoucherService       $service   Voucher service instance.
		 */
		$providers = apply_filters(
			'vcap_redemption_providers',
			array( new WooCommerceRedemptionProvider( $this->vouchers, $this->coupons ) ),
			$code,
			$this
		);
		foreach ( is_array( $providers ) ? $providers : array() as $provider ) {
			if ( $provider instanceof RedemptionProvider && $provider->supports( $code ) ) {
				return $provider;
			}
		}
		return new \WP_Error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
	}
}
