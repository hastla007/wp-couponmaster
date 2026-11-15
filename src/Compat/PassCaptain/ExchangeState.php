<?php
namespace VCAP\Compat\PassCaptain;

use VCAP\API\V1\RedemptionService;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Where a voucher's exchange stands (D14, split from PassCaptainTickets): the current attempt and its redemption,
 * and Pass Captain's state of a committed exchange, cached per request.
 */
final class ExchangeState {
	public function __construct( private RedemptionService $redemptions ) {}

	/**
	 * Pass Captain's state of the voucher's committed exchange (event, starts_at, email, ticket_ids, status), or
	 * null when it was not exchanged (yet).
	 */
	/** @var array<int, array|null> Exchange state per voucher for this request (account pages ask several times). */
	private array $states = array();

	public function exchange_state( Voucher $voucher ): ?array {
		if ( array_key_exists( $voucher->id, $this->states ) ) {
			return $this->states[ $voucher->id ];
		}
		[ $attempt, $redemption ] = $this->attempt( $voucher );
		$service                  = TicketContract::service();
		if ( ! $redemption || RedemptionService::STATE_COMMITTED !== $redemption['state'] || ! $service ) {
			return $this->states[ $voucher->id ] = null;
		}
		$state = $service->get( TicketContract::exchange_key( $voucher, $attempt ) );
		return $this->states[ $voucher->id ] = ( is_array( $state ) ? $state + array( 'key' => TicketContract::exchange_key( $voucher, $attempt ) ) : null );
	}

	/** Forget cached exchange states (after an exchange, a revocation or a void). */
	public function forget_states(): void {
		$this->states = array();
	}

	/**
	 * The current attempt of a voucher: the first attempt whose redemption is reserved or committed, else the next
	 * unused one.
	 *
	 * @return array{0:int,1:array|null} Attempt number and its redemption (null when unused).
	 */
	public function attempt( Voucher $voucher ): array {
		for ( $n = 1; $n <= 50; $n++ ) {
			$row = $this->redemptions->get( TicketContract::key( $voucher, $n ) );
			if ( is_wp_error( $row ) ) {
				return array( $n, null );
			}
			// Released attempts and exchanges given back in full (tickets revoked in Pass Captain) are finished.
			$refunded = array_sum( array_map( 'floatval', (array) ( $row['meta']['_refunds'] ?? array() ) ) );
			$returned = RedemptionService::STATE_COMMITTED === $row['state'] && (float) $row['amount'] > 0 && $refunded + 0.0001 >= (float) $row['amount'];
			if ( RedemptionService::STATE_RELEASED !== $row['state'] && ! $returned ) {
				return array( $n, $row );
			}
		}
		return array( 51, null );
	}
}
