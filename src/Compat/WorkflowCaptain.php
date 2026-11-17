<?php
namespace VCAP\Compat;

defined( 'ABSPATH' ) || exit;

/**
 * Optional status of Workflow Captain for Voucher Captain in Settings → Vouchers → Integrations (API) (suite task
 * "Providerstatus, aktive Workflowlinks und gemeinsame Run-/Correlation-IDs in Suite-Admins anzeigen"). Voucher
 * Captain neither requires nor configures Workflow Captain: it only reads the public function
 * workflow_captain_status_for() when it exists and uses no class of Workflow Captain. The status holds counts and
 * links only (adapter state, contract version, active workflows, events per state, last correlation ID), no personal
 * data.
 */
final class WorkflowCaptain {
	/** Product id of Voucher Captain in Workflow Captain. */
	public const PRODUCT = 'voucher-captain';

	/** Public status function of Workflow Captain. */
	public const SOURCE = 'workflow_captain_status_for';

	/** Link keys of the status, in display order. */
	private const LINKS = array( 'workflows', 'runs', 'events', 'providers' );

	public static function available(): bool {
		return function_exists( self::SOURCE );
	}

	/**
	 * Status of Voucher Captain in Workflow Captain, or null when Workflow Captain is missing or not loaded yet.
	 *
	 * @param callable|null $source Status function (tests); default workflow_captain_status_for().
	 * @return array<string, mixed>|null
	 */
	public static function status( ?callable $source = null ): ?array {
		if ( null === $source ) {
			if ( ! self::available() ) {
				return null;
			}
			$source = self::SOURCE;
		}
		$status = call_user_func( $source, self::PRODUCT );
		return is_array( $status ) ? $status : null;
	}

	/** Label of an event state of Workflow Captain; unknown states are shown by their key. */
	private static function state_label( string $state ): string {
		$labels = array(
			'received'     => __( 'received', 'voucher-captain' ),
			'processing'   => __( 'processing', 'voucher-captain' ),
			'done'         => __( 'done', 'voucher-captain' ),
			'retry_wait'   => __( 'waiting for retry', 'voucher-captain' ),
			'dead_letter'  => __( 'failed', 'voucher-captain' ),
			'discarded'    => __( 'discarded', 'voucher-captain' ),
			'loop_blocked' => __( 'stopped as a loop', 'voucher-captain' ),
			'paused'       => __( 'paused', 'voucher-captain' ),
		);
		return $labels[ $state ] ?? $state;
	}

	/** Only absolute http(s) links of the status are used. */
	private static function link( array $status, string $key ): string {
		$url = $status['links'][ $key ] ?? '';
		return is_string( $url ) && 1 === preg_match( '#^https?://#i', $url ) ? $url : '';
	}

	private static function anchor( string $url, string $html ): string {
		return '' === $url ? $html : '<a href="' . esc_url( $url ) . '">' . $html . '</a>';
	}

	/**
	 * Description of the settings section (escaped HTML).
	 *
	 * @param callable|null $source Status function (tests); default workflow_captain_status_for().
	 */
	public static function status_html( ?callable $source = null ): string {
		if ( null === $source && ! self::available() ) {
			return esc_html__( 'Not active – with Workflow Captain, voucher events such as "voucher redeemed" can start your own workflows. Voucher Captain works fully without it.', 'voucher-captain' );
		}
		$status = self::status( $source );
		if ( null === $status ) {
			return esc_html__( 'Active, but it does not report a status yet.', 'voucher-captain' );
		}
		$adapter = (string) ( $status['adapter'] ?? 'missing' );
		$reason  = is_string( $status['reason'] ?? null ) ? $status['reason'] : '';
		if ( 'registered' === $adapter ) {
			$lines = array( '✔ ' . esc_html__( 'Adapter: registered', 'voucher-captain' ) );
		} elseif ( 'refused' === $adapter ) {
			/* translators: %s: reason given by Workflow Captain */
			$lines = array( '– ' . esc_html( '' !== $reason ? sprintf( __( 'Adapter: refused (%s)', 'voucher-captain' ), $reason ) : __( 'Adapter: refused', 'voucher-captain' ) ) );
		} else {
			$lines = array( '– ' . esc_html__( 'Adapter: not registered – Workflow Captain does not know an adapter for Voucher Captain yet.', 'voucher-captain' ) );
		}
		$contract = is_scalar( $status['contract_version'] ?? null ) && '' !== (string) $status['contract_version'] ? (string) $status['contract_version'] : '—';
		/* translators: %s: contract version */
		$lines[] = esc_html( sprintf( __( 'Contract version: %s', 'voucher-captain' ), $contract ) );
		/* translators: %d: number of active workflows */
		$lines[] = esc_html( sprintf( __( 'Active workflows: %d', 'voucher-captain' ), (int) ( $status['active_workflows'] ?? 0 ) ) );
		$events_url = self::link( $status, 'events' );
		$events     = array();
		foreach ( (array) ( $status['events'] ?? array() ) as $state => $count ) {
			$state    = sanitize_key( (string) $state );
			$url      = '' !== $events_url && '' !== $state ? add_query_arg( 'state', $state, $events_url ) : '';
			$events[] = self::anchor( $url, esc_html( self::state_label( $state ) . ': ' . (int) $count ) );
		}
		$lines[] = esc_html__( 'Events by state:', 'voucher-captain' ) . ' ' . ( $events ? implode( ' · ', $events ) : esc_html__( 'none yet', 'voucher-captain' ) );
		$correlation = is_string( $status['last_correlation_id'] ?? null ) && '' !== $status['last_correlation_id'] ? self::anchor( $events_url, '<code>' . esc_html( $status['last_correlation_id'] ) . '</code>' ) : '—';
		$lines[]     = esc_html__( 'Last correlation ID:', 'voucher-captain' ) . ' ' . $correlation;
		$labels      = array(
			'workflows' => __( 'Workflows', 'voucher-captain' ),
			'runs'      => __( 'Runs', 'voucher-captain' ),
			'events'    => __( 'Events', 'voucher-captain' ),
			'providers' => __( 'Providers', 'voucher-captain' ),
		);
		$links = array();
		foreach ( self::LINKS as $key ) {
			$url = self::link( $status, $key );
			if ( '' !== $url ) {
				$links[] = self::anchor( $url, esc_html( $labels[ $key ] ) );
			}
		}
		$html = implode( '<br />', $lines );
		if ( $links ) {
			$html .= '<br />' . esc_html__( 'Open in Workflow Captain:', 'voucher-captain' ) . ' ' . implode( ' · ', $links );
		}
		return $html;
	}
}
