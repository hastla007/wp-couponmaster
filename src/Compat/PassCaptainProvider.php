<?php
namespace VCAP\Compat;

use VCAP\API\V1\RedemptionService;
use VCAP\ProductConfig;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Implements Pass Captain's voucher provider contract (version 1) on top of the redemption API.
 * Only loaded when Pass Captain's interface exists (see PassCaptain::provider()).
 *
 * Pass Captain works in minor units of the order currency; Voucher Captain in major units of the voucher
 * currency. Both must be the same currency. Keys: Pass Captain's reserve key ("wpcap-order-…") becomes the
 * redemption key; commit/release use it from the reservation, refunds use Pass Captain's refund key.
 */
final class PassCaptainProvider implements \PassCaptain\Integrations\Vouchers\ProviderInterface {
	private const ZERO_DECIMAL = array( 'BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );

	public function __construct( private RedemptionService $redemptions, private VoucherRepository $vouchers ) {}

	/** ISO 4217 minor units (as Pass Captain uses them) – never the shop's display decimals. */
	public static function decimals( string $currency ): int {
		return in_array( strtoupper( $currency ), self::ZERO_DECIMAL, true ) ? 0 : 2;
	}

	private static function to_minor( float $amount, int $decimals ): int {
		return (int) round( $amount * ( 10 ** $decimals ) );
	}

	private static function to_major( int $minor, int $decimals ): float {
		return round( $minor / ( 10 ** $decimals ), $decimals );
	}

	/** @return array|\WP_Error */
	public function quote( array $context ) {
		$code     = (string) ( $context['code'] ?? '' );
		$currency = strtoupper( (string) ( $context['currency'] ?? '' ) );
		$total    = (int) ( $context['amount_minor'] ?? 0 );
		$target   = PassCaptain::CHANNEL . ':' . (string) ( $context['order_reference'] ?? 'order' );
		if ( '' === $code || $total < 1 ) {
			return new \WP_Error( 'vcap_invalid_request', __( 'Please enter a voucher code.', 'voucher-captain' ) );
		}
		$email = sanitize_email( (string) ( $context['customer_email'] ?? '' ) );
		$check = $this->redemptions->check( $code, array( 'target' => $target, 'email' => $email, 'user_id' => 0 ) );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		if ( strtoupper( (string) $check['currency'] ) !== $currency ) {
			return new \WP_Error( 'vcap_currency_mismatch', __( 'This voucher is in a different currency.', 'voucher-captain' ) );
		}
		$voucher  = $this->vouchers->get_by_code( (string) $check['code'] );
		if ( $voucher && PassCaptainTickets::target( $voucher ) && ! PassCaptainTickets::value_use_allowed( $voucher ) ) {
			// A ticket voucher stands for tickets, not for money: it is exchanged on the redeem page.
			return new \WP_Error( 'vcap_ticket_voucher', __( 'This voucher is exchanged for tickets on the voucher redemption page and cannot pay for an order.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		$decimals = self::decimals( $currency );
		$order    = self::to_major( $total, $decimals );
		if ( null !== $check['balance'] ) {
			$amount = min( (float) $check['balance'], $order );
		} elseif ( $voucher && 'percent' === $voucher->discount_type ) {
			$amount = round( $order * min( 100.0, (float) $check['value'] ) / 100, $decimals );
		} else {
			$amount = min( (float) $check['value'], $order );
		}
		$minor = min( $total, self::to_minor( $amount, $decimals ) );
		if ( $minor < 1 ) {
			return new \WP_Error( 'vcap_no_value', __( 'This voucher has no value left.', 'voucher-captain' ) );
		}
		$treatment = $voucher ? ProductConfig::tax_treatment( $voucher ) : 'discount';
		$purpose   = $voucher ? ProductConfig::purpose( $voucher ) : 'discount';
		return array_filter( array(
			'version'      => 1,
			'code'         => (string) $check['code'],
			'currency'     => $currency,
			'amount_minor' => $minor,
			'amount'       => self::to_major( $minor, $decimals ),
			'balance'      => null !== $check['balance'],
			// VAT treatment for the ticket checkout: "payment" keeps the VAT, "single"/"discount" reduce it.
			'treatment'    => $treatment,
			// Pass Captain's terms: multi-purpose voucher (means of payment), single-purpose voucher (VAT charged at the
			// sale: reduces price and VAT) or promotion code ("discount": reduces price and VAT, nothing charged before).
			'purpose'      => $purpose,
			// Single-purpose only: VAT rate and country of the sale – Pass Captain treats the voucher as multi-purpose
			// when the tickets carry another rate or are taxed in another country.
			'sale_tax'     => 'single' === $purpose && $voucher ? ProductConfig::sale_tax( $voucher ) : null,
			'target'       => $target,
			'email'        => $email,
		), static fn( $v ) => null !== $v );
	}

	/**
	 * How long a reservation must survive: Pass Captain keeps unpaid orders open for its own hold time
	 * (online payments: minutes; manual/bank payments: up to days) and releases the voucher itself when an order
	 * is cancelled or expires. Our automatic release is only a safety net after that, never before payment.
	 */
	public static function hold_minutes(): int {
		$minutes = 7 * 24 * 60;
		if ( class_exists( '\PassCaptain\Settings' ) && is_callable( array( '\PassCaptain\Settings', 'get' ) ) ) {
			$online  = (int) \PassCaptain\Settings::get( 'hold_minutes' );
			$manual  = (int) \PassCaptain\Settings::get( 'manual_hold_hours' ) * 60;
			$minutes = max( $online, $manual, 30 );
		}
		return (int) apply_filters( 'vcap_passcaptain_reservation_minutes', $minutes + 24 * 60 );
	}

	/** @return array|\WP_Error */
	public function reserve( array $quote, $idempotency_key ) {
		$args = array(
			'key'         => (string) $idempotency_key,
			'target'      => (string) ( $quote['target'] ?? PassCaptain::CHANNEL . ':order' ),
			'meta'        => array( 'source' => 'passcaptain' ),
			'ttl_minutes' => self::hold_minutes(),
			'email'       => (string) ( $quote['email'] ?? '' ),
			// Owned by the integration, not by the logged-in buyer: only Pass Captain commits or releases it.
			'user_id'     => 0,
		);
		if ( ! empty( $quote['balance'] ) ) {
			$args['amount'] = (float) $quote['amount'];
		}
		$reserved = $this->redemptions->reserve( (string) ( $quote['code'] ?? '' ), $args );
		if ( is_wp_error( $reserved ) ) {
			return $reserved;
		}
		// A replay of the same key returns the stored redemption: only an open reservation (or one Pass Captain
		// already committed itself) is a success; a released one holds nothing and must not look reserved.
		if ( ! in_array( (string) $reserved['state'], array( RedemptionService::STATE_RESERVED, RedemptionService::STATE_COMMITTED ), true ) ) {
			return new \WP_Error( 'vcap_redemption_released', __( 'This voucher reservation was already released. Please apply the voucher again.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		return array(
			'version'        => 1,
			'redemption_key' => (string) $reserved['redemption_key'],
			'voucher_id'     => (int) $reserved['voucher_id'],
			'currency'       => (string) ( $quote['currency'] ?? $reserved['currency'] ),
			'applied_minor'  => (int) ( $quote['amount_minor'] ?? 0 ),
			'redeemed'       => (float) $reserved['amount'],
			'state'          => (string) $reserved['state'],
		);
	}

	/** @return array|\WP_Error */
	public function commit( array $reservation, $idempotency_key ) {
		$result = $this->redemptions->commit( (string) ( $reservation['redemption_key'] ?? '' ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		return array_merge(
			$reservation,
			array(
				'state'        => (string) $result['state'],
				'committed_at' => (string) $result['committed_at'],
			)
		);
	}

	/** @return true|\WP_Error */
	public function release( array $reservation, $idempotency_key ) {
		$result = $this->redemptions->release( (string) ( $reservation['redemption_key'] ?? '' ) );
		return is_wp_error( $result ) ? $result : true;
	}

	/**
	 * Refund the voucher share of a ticket refund. The share is converted in proportion to what the voucher paid,
	 * so single-use vouchers become usable again once the whole order share is refunded.
	 *
	 * @return array|\WP_Error
	 */
	public function refund( array $redemption, $amount_minor, $idempotency_key ) {
		$key      = (string) ( $redemption['redemption_key'] ?? '' );
		$applied  = max( 1, (int) ( $redemption['applied_minor'] ?? 0 ) );
		$redeemed = (float) ( $redemption['redeemed'] ?? 0 );
		$decimals = self::decimals( (string) ( $redemption['currency'] ?? get_woocommerce_currency() ) );
		$current  = $this->redemptions->get( $key );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$done_by_key = (array) ( $current['meta']['_refunds'] ?? array() );
		if ( isset( $done_by_key[ (string) $idempotency_key ] ) ) {
			return $this->redemptions->refund( $key, 0.0, (string) $idempotency_key ); // Retry: stored result.
		}
		$done   = array_sum( array_map( 'floatval', $done_by_key ) );
		$amount = round( $redeemed * min( 1.0, absint( $amount_minor ) / $applied ), $decimals );
		if ( $done + $amount >= $redeemed - ( 1 / ( 10 ** $decimals ) ) ) {
			$amount = round( $redeemed - $done, $decimals ); // Last refund: give back the exact rest.
		}
		return $this->redemptions->refund( $key, $amount, (string) $idempotency_key );
	}
}
