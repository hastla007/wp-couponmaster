<?php
namespace VCAP\Workflow;

use VCAP\API\V1\RedemptionService;
use VCAP\Issuer;
use VCAP\Logger;
use VCAP\Mask;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Workflow provider contract v1 (WORKFLOW.md, VOUCH-WF-01): what a workflow plugin (e.g. Workflow Captain) may
 * rely on – contract version (SemVer), capabilities (events and services), the event envelope, the payload
 * allowlist, error format, idempotency and the deprecation rule.
 *
 * Events (WF-02) are built from the voucher history: every ledger entry fires "vcap_voucher_event" after its
 * COMMIT (D6); the entries that matter to workflows become one or two envelopes, fired with the action
 * "vcap_workflow_event" (one argument: the envelope array). The event id is derived from the ledger row, so a replay
 * (replay()) of the same entry carries the same id. Envelopes hold stable ids, states, amounts in minor units and
 * the currency – never a code, token, PDF, email address, name or free text.
 *
 * A workflow plugin is optional: without one (or with an incompatible one) nothing is built, nothing waits and
 * every native flow runs as before.
 */
final class Provider {
	/** Provider id in the envelope and the registries. */
	public const ID = 'voucher-captain';

	/** Contract version (SemVer). Major = breaking change; minor = additions; patch = fixes without contract change. */
	public const CONTRACT_VERSION = '1.0.0';

	/** Action the envelopes are fired with. */
	public const EVENT_HOOK = 'vcap_workflow_event';

	/** Non-autoloaded option with the dispatch status (last event, counters, last error). */
	public const STATUS_OPTION = 'vcap_workflow_status';

	/** Random id of this site, part of every event id (stable across requests, distinct between shops). */
	public const INSTANCE_OPTION = 'vcap_workflow_instance';

	/**
	 * D8 (owner decision 16.11.2026): the address the instance belongs to (autoloaded) – signature of the normalised
	 * home_url, the previous instances (newest last, at most 5) and whether the admin notice is still due.
	 */
	public const INSTANCE_SITE_OPTION = 'vcap_workflow_instance_site';

	/** Site address of this request (see stored_home()). */
	private static ?string $stored_home = null;

	/** Previous instances kept in INSTANCE_SITE_OPTION. */
	private const PREVIOUS_MAX = 5;

	/** Results of compatibility(). */
	public const MISSING      = 'missing';
	public const INCOMPATIBLE = 'incompatible';
	public const COMPATIBLE   = 'compatible';

	/** Event types of contract v1. */
	public const EVENTS = array(
		'voucher.created',
		'voucher.delivered',
		'voucher.delivery_failed',
		'voucher.redeemed',
		'voucher.redemption_refunded',
		'voucher.refunded',
		'voucher.voided',
		'voucher.reactivated',
		'voucher.expired',
		'voucher.communication_handed_over',
	);

	/** Services of contract v1 (methods of this class, see Services). */
	public const SERVICES = array( 'issue', 'resend', 'void', 'reactivate', 'redeem', 'contact', 'replay', 'status' );

	/** Top-level fields of an envelope. */
	public const ENVELOPE_FIELDS = array( 'contract_version', 'provider', 'event_id', 'type', 'occurred_at', 'source_type', 'source_id', 'sequence', 'blog_id', 'status', 'replayed', 'data' );

	/** Payload allowlist: the only keys "data" can hold (scalars or null). */
	public const DATA_FIELDS = array(
		'voucher_type',
		'usage_mode',
		'currency',
		'value_minor',
		'percent',
		'balance_minor',
		'amount_minor',
		'order_id',
		'product_id',
		'expires_at',
		'origin',
		'channel',
		'reason',
		'scope',
		'final',
		'resend',
		'communication',
		'role',
		'days',
	);

	/** R11: counts of this request, added to the stored ones at shutdown. */
	private array $delta = array( 'delivered' => 0, 'failures' => 0 );

	/** Dispatch status of this request, saved once at shutdown. */
	private array $status = array();

	private bool $dirty = false;

	private ?Services $services = null;

	public function __construct( private VoucherRepository $vouchers, private RedemptionService $redemptions ) {}

	public function register(): void {
		add_action( 'vcap_voucher_event', array( $this, 'on_ledger' ), 10, 9 );
		// Registries of a workflow plugin (generic: Workflow Captain or any plugin that implements the contract).
		add_action( 'workflow_captain_register_providers', array( $this, 'register_with' ) );
		add_filter( 'workflow_captain_providers', array( $this, 'add_to' ) );
		add_filter( 'vcap_workflow_provider', array( $this, 'provide' ) );
		// Redemption keys and targets of the workflow services are created on the server only (never over REST).
		add_filter( 'vcap_internal_redemption_key_prefixes', array( self::class, 'internal_prefix' ) );
		add_filter( 'vcap_internal_redemption_target_prefixes', array( self::class, 'internal_prefix' ) );
		add_filter( 'vcap_active_integrations', array( self::class, 'integration' ) );
		add_action( 'admin_notices', array( self::class, 'instance_notice' ) );
	}

	/**
	 * The contract this provider offers (also what a registry receives).
	 *
	 * @return array<string, mixed>
	 */
	public static function descriptor(): array {
		return array(
			'id'                  => self::ID,
			'name'                => 'Voucher Captain',
			'version'             => defined( 'VCAP_VERSION' ) ? (string) VCAP_VERSION : '',
			'contract_version'    => self::CONTRACT_VERSION,
			'supported_contracts' => array( self::major( self::CONTRACT_VERSION ) ),
			'event_hook'          => self::EVENT_HOOK,
			'events'              => self::EVENTS,
			'services'            => self::SERVICES,
			'communications'      => array_keys( Handover::OPTIONS ),
			'envelope_fields'     => self::ENVELOPE_FIELDS,
			'data_fields'         => self::DATA_FIELDS,
			'deprecated'          => array(),
			'blog_id'             => (int) get_current_blog_id(),
		);
	}

	/** Action "workflow_captain_register_providers": hands this provider to a registry with register( $id, $provider ). */
	public function register_with( $registry ): void {
		if ( is_object( $registry ) && method_exists( $registry, 'register' ) ) {
			$registry->register( self::ID, $this );
		}
	}

	/**
	 * Filter "workflow_captain_providers": id => provider.
	 *
	 * @param mixed $providers What the previous filter returned (a non-array is normalised).
	 */
	public function add_to( $providers ): array {
		$providers             = is_array( $providers ) ? $providers : array();
		$providers[ self::ID ] = $this;
		return $providers;
	}

	/**
	 * Filter "vcap_workflow_provider": a workflow plugin asks for the provider with the filter and null as value.
	 *
	 * @param mixed $provider What the previous filter returned.
	 */
	public function provide( $provider ) {
		return is_object( $provider ) ? $provider : $this;
	}

	/**
	 * Adds the reserved "workflow:" prefix (keys and targets of Services::redeem()).
	 *
	 * @param mixed $prefixes What the previous filter returned.
	 */
	public static function internal_prefix( $prefixes ): array {
		$prefixes   = is_array( $prefixes ) ? $prefixes : array();
		$prefixes[] = Services::PREFIX;
		return array_values( array_unique( $prefixes ) );
	}

	/**
	 * Diagnostics: the registered workflow plugin.
	 *
	 * @param mixed $active What the previous filter returned.
	 */
	public static function integration( $active ): array {
		$active   = is_array( $active ) ? $active : array();
		$consumer = self::consumer();
		if ( $consumer ) {
			$active['workflow'] = trim( $consumer['name'] . ' ' . $consumer['version'] ) . ' (' . self::compatibility( $consumer ) . ')';
		}
		return $active;
	}

	// -----------------------------------------------------------------------------------------------------------
	// Consumer (the workflow plugin) and compatibility.
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * The registered workflow plugin, normalised, or null when none is registered. A workflow plugin announces
	 * itself with the filter "vcap_workflow_consumer" (array: id, name, version, contract = the contract version
	 * it was built for, edition core|pro, entitlement active|expired|missing, communications = what it handles now).
	 *
	 * @return array{id:string, name:string, version:string, contract:string, edition:string, entitlement:string, communications:string[]}|null
	 */
	public static function consumer(): ?array {
		/**
		 * A workflow plugin registers itself for the provider contract: return an array with id, name, version,
		 * contract (the contract version it was built for, e.g. "1.0.0"), edition (core|pro), entitlement
		 * (active|expired|missing) and communications (those it handles now, see WORKFLOW.md). Null = none.
		 */
		$raw = apply_filters( 'vcap_workflow_consumer', null );
		if ( ! is_array( $raw ) || '' === sanitize_key( (string) ( $raw['id'] ?? '' ) ) ) {
			return null;
		}
		$known = array_keys( Handover::OPTIONS );
		return array(
			'id'             => sanitize_key( (string) $raw['id'] ),
			'name'           => mb_substr( sanitize_text_field( (string) ( $raw['name'] ?? $raw['id'] ) ), 0, 80 ),
			'version'        => mb_substr( sanitize_text_field( (string) ( $raw['version'] ?? '' ) ), 0, 20 ),
			'contract'       => mb_substr( sanitize_text_field( (string) ( $raw['contract'] ?? '' ) ), 0, 20 ),
			'edition'        => in_array( $raw['edition'] ?? '', array( 'core', 'pro' ), true ) ? (string) $raw['edition'] : 'core',
			'entitlement'    => in_array( $raw['entitlement'] ?? '', array( 'active', 'expired', 'missing' ), true ) ? (string) $raw['entitlement'] : 'active',
			'communications' => array_values( array_intersect( $known, array_map( 'strval', (array) ( $raw['communications'] ?? array() ) ) ) ),
		);
	}

	/**
	 * Whether a workflow plugin can use this provider: missing, incompatible (no or another major contract
	 * version) or compatible. A consumer built for a newer minor version is compatible – it reads "capabilities"
	 * to see what this provider offers.
	 */
	public static function compatibility( ?array $consumer ): string {
		if ( null === $consumer ) {
			return self::MISSING;
		}
		$major = self::major( (string) ( $consumer['contract'] ?? '' ) );
		return '' !== $major && $major === self::major( self::CONTRACT_VERSION ) ? self::COMPATIBLE : self::INCOMPATIBLE;
	}

	/** Major part of a version ("1.2.0" → "1"), '' when it is no version. */
	public static function major( string $version ): string {
		return preg_match( '/^v?(\d+)(\.\d+){0,2}([-+].*)?$/', trim( $version ), $m ) ? (string) (int) $m[1] : '';
	}

	// -----------------------------------------------------------------------------------------------------------
	// Events.
	// -----------------------------------------------------------------------------------------------------------

	/**
	 * Workflow events of one ledger entry: list of [type, data]. Pure (no database), see WORKFLOW.md for the
	 * mapping. Store credit accounts never get here (on_ledger()).
	 *
	 * @param array|null $note Coded note of the entry (Note::decode()), null for free text.
	 * @return array<int, array{0:string, 1:array}>
	 */
	public static function map( string $event, ?array $note, ?float $amount, int $order_id ): array {
		$code = (string) ( $note['code'] ?? '' );
		$args = array_values( (array) ( $note['args'] ?? array() ) );
		switch ( $event ) {
			case 'issued':
				return array( array( 'voucher.created', array() ) );
			case 'delivered':
				return array( array( 'voucher.delivered', array( 'resend' => false ) ) );
			case 'resent':
				return array( array( 'voucher.delivered', array( 'resend' => true ) ) );
			case 'delivery_failed':
				return array( array( 'voucher.delivery_failed', array( 'reason' => '' !== $code ? $code : 'unknown', 'final' => false ) ) );
			case 'delivery_given_up':
				return array( array( 'voucher.delivery_failed', array( 'reason' => 'delivery_given_up', 'final' => true ) ) );
			case 'redeemed':
				return array( array( 'voucher.redeemed', array( 'channel' => $order_id > 0 ? 'shop' : 'counter', 'amount' => $amount ) ) );
			case 'committed':
				return array( array( 'voucher.redeemed', array( 'channel' => 'shop', 'amount' => $amount ) ) );
			case 'api_committed':
				return array( array( 'voucher.redeemed', array( 'channel' => 'external', 'amount' => $amount ) ) );
			case 'restored':
				return array( array( 'voucher.redemption_refunded', array( 'channel' => 'shop', 'amount' => $amount ) ) );
			case 'redeem_reversed':
				return array( array( 'voucher.redemption_refunded', array( 'channel' => 'counter', 'amount' => $amount ) ) );
			case 'api_refunded':
				return array( array( 'voucher.redemption_refunded', array( 'channel' => 'external', 'amount' => $amount ) ) );
			case Issuer::PURCHASE_REFUNDED_EVENT:
				return array( array( 'voucher.refunded', array( 'scope' => 'partial', 'amount' => $amount ) ) );
			case 'voided':
				if ( Issuer::REFUND_VOID_NOTE === $code ) {
					return array(
						array( 'voucher.refunded', array( 'scope' => 'full' ) ),
						array( 'voucher.voided', array( 'reason' => 'refund' ) ),
					);
				}
				return array( array( 'voucher.voided', array( 'reason' => 'other' ) ) );
			case 'reactivated':
				return array( array( 'voucher.reactivated', array() ) );
			case 'expired':
				return array( array( 'voucher.expired', array( 'amount' => $amount ) ) );
			case Handover::EVENT:
				$communication = (string) ( $args[0] ?? '' );
				if ( ! isset( Handover::OPTIONS[ $communication ] ) ) {
					return array();
				}
				$data = array( 'communication' => $communication );
				if ( ! empty( $args[1] ) ) {
					$data['days'] = (int) $args[1];
				}
				return array( array( 'voucher.communication_handed_over', $data ) );
		}
		return array();
	}

	/**
	 * The envelope of one event (pure apart from the site id).
	 *
	 * @param array   $entry   Ledger row: id, created_at (UTC), order_id, source.
	 * @param Voucher $voucher The voucher as read when the event is built.
	 * @param string  $type    Event type (EVENTS).
	 * @param array   $data    Event data from map().
	 */
	public static function envelope( array $entry, Voucher $voucher, string $type, array $data, bool $replayed = false ): array {
		$decimals = RedemptionService::decimals( '' !== $voucher->currency ? $voucher->currency : 'EUR' );
		$percent  = 'percent' === $voucher->discount_type;
		$base     = array(
			'voucher_type'  => $voucher->type,
			'usage_mode'    => $voucher->usage_mode,
			'currency'      => $percent ? null : $voucher->currency,
			'value_minor'   => $percent ? null : self::minor( $voucher->initial_amount, $decimals ),
			'percent'       => $percent ? (float) $voucher->initial_amount : null,
			'balance_minor' => $voucher->is_multi_use() && ! $percent ? self::minor( $voucher->balance, $decimals ) : null,
			'order_id'      => (int) ( $entry['order_id'] ?? 0 ) > 0 ? (int) $entry['order_id'] : ( $voucher->order_id > 0 ? $voucher->order_id : null ),
			'product_id'    => $voucher->product_id > 0 ? $voucher->product_id : null,
			'expires_at'    => self::iso( $voucher->expires_at ),
			'origin'        => sanitize_key( (string) ( $entry['source'] ?? '' ) ),
		);
		if ( array_key_exists( 'amount', $data ) ) {
			$data['amount_minor'] = null === $data['amount'] || $percent ? null : self::minor( abs( (float) $data['amount'] ), $decimals );
			unset( $data['amount'] );
		}
		if ( 'voucher.communication_handed_over' === $type ) {
			$data['role'] = Handover::role( $voucher, (string) ( $data['communication'] ?? '' ) );
		}
		$blog = (int) get_current_blog_id();
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'provider'         => self::ID,
			'event_id'         => self::event_id( (int) $entry['id'], $type, $blog ),
			'type'             => $type,
			'occurred_at'      => (string) self::iso( (string) ( $entry['created_at'] ?? '' ) ),
			'source_type'      => 'voucher',
			'source_id'        => (int) $voucher->id,
			'sequence'         => (int) $entry['id'],
			'blog_id'          => $blog,
			'status'           => $voucher->status,
			'replayed'         => $replayed,
			'data'             => self::redact( array_merge( $base, $data ), $voucher ),
		);
	}

	/**
	 * Payload allowlist: only DATA_FIELDS, only scalars or null; a string that holds an "@" (an address), the
	 * voucher's code or token is dropped – a defence in depth, the mapping never puts them there.
	 */
	public static function redact( array $data, ?Voucher $voucher = null ): array {
		$out     = array();
		$secrets = $voucher ? array_filter( array( strtolower( $voucher->code ), strtolower( $voucher->token ) ) ) : array();
		foreach ( self::DATA_FIELDS as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$value = $data[ $key ];
			if ( null !== $value && ! is_scalar( $value ) ) {
				continue;
			}
			if ( is_string( $value ) ) {
				$lower = strtolower( $value );
				if ( str_contains( $value, '@' ) || mb_strlen( $value ) > 64 || in_array( $lower, $secrets, true ) ) {
					continue;
				}
				foreach ( $secrets as $secret ) {
					if ( strlen( $secret ) >= 8 && str_contains( $lower, $secret ) ) {
						continue 2;
					}
				}
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/** Stable, unique event id (UUID format) of a ledger row and event type on this site and blog. */
	public static function event_id( int $ledger_id, string $type, int $blog_id = 0 ): string {
		return self::uuid( sha1( 'vcap-workflow|' . self::instance() . '|' . ( $blog_id > 0 ? $blog_id : (int) get_current_blog_id() ) . '|' . $ledger_id . '|' . $type ) );
	}

	/**
	 * Random id of this site (created once). D8: it is bound to the site address – when the database runs under
	 * another address (a copy or staging site), a new instance id is derived and the old one noted, so the copy's
	 * events never carry the event ids of the original shop. http↔https and a trailing slash change nothing.
	 */
	public static function instance(): string {
		$id = (string) get_option( self::INSTANCE_OPTION, '' );
		if ( '' === $id ) {
			$id = wp_generate_uuid4();
			if ( ! add_option( self::INSTANCE_OPTION, $id, '', true ) ) {
				$id = (string) get_option( self::INSTANCE_OPTION, $id ); // Another request created it first.
			}
		}
		$signature = self::site_signature();
		$site      = get_option( self::INSTANCE_SITE_OPTION, null );
		if ( ! is_array( $site ) || '' === (string) ( $site['signature'] ?? '' ) ) {
			// First run (or an update from a version without the binding): the current address is the instance's own.
			update_option( self::INSTANCE_SITE_OPTION, array( 'signature' => $signature ) + ( is_array( $site ) ? $site : array() ), true );
			return $id;
		}
		if ( hash_equals( (string) $site['signature'], $signature ) ) {
			return $id;
		}
		// Derived, not random: requests that notice the new address at the same moment agree on the same id.
		$new        = self::uuid( sha1( 'vcap-workflow-instance|' . $id . '|' . $signature ) );
		$previous   = array_values( (array) ( $site['previous'] ?? array() ) );
		$previous[] = array(
			'instance'  => $id,
			'signature' => (string) $site['signature'],
			'until'     => gmdate( 'Y-m-d H:i:s' ),
		);
		update_option( self::INSTANCE_OPTION, $new, true );
		update_option(
			self::INSTANCE_SITE_OPTION,
			array(
				'signature'  => $signature,
				'changed_at' => gmdate( 'Y-m-d H:i:s' ),
				'previous'   => array_slice( $previous, -self::PREVIOUS_MAX ),
				'notice'     => true,
			),
			true
		);
		/**
		 * D8: the workflow instance id was renewed because the site address changed (copy/staging). Event ids of this
		 * site differ from the original's from now on.
		 *
		 * @since 1.2.0
		 * @param string $new_instance New instance id.
		 * @param string $old_instance Previous instance id.
		 */
		do_action( 'vcap_workflow_instance_changed', $new, $id );
		return $new;
	}

	/**
	 * Signature of the site address the instance is bound to: the stored site address without scheme and trailing
	 * slash, host in lower case – so http↔https or "/" at the end change nothing, another host, port or path does.
	 */
	public static function site_signature( ?string $url = null ): string {
		$url = strtolower( trim( null === $url ? self::stored_home() : $url ) );
		$url = (string) preg_replace( '#^[a-z][a-z0-9+.\-]*://#', '', $url );
		$url = rtrim( $url, '/' );
		return substr( hash( 'sha256', 'vcap-workflow-site|' . $url ), 0, 32 );
	}

	/**
	 * The site address as configured (WP_HOME or the stored "home" option), never the filtered home_url(): language
	 * plugins such as WPML return another address per language (directory or domain), which would renew the
	 * instance on every language switch. Read once per request.
	 */
	public static function stored_home(): string {
		if ( null !== self::$stored_home ) {
			return self::$stored_home;
		}
		$home = defined( 'WP_HOME' ) ? (string) WP_HOME : '';
		if ( '' === $home ) {
			global $wpdb;
			$raw  = isset( $wpdb->options ) ? $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", 'home' ) ) : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- unfiltered on purpose, cached per request.
			$home = is_string( $raw ) ? $raw : '';
		}
		if ( '' === $home ) {
			$home = (string) home_url(); // No database row (tests, unusual setups): the address WordPress reports.
		}
		self::$stored_home = $home;
		return $home;
	}

	/** Tests: read the site address again. */
	public static function forget_stored_home(): void {
		self::$stored_home = null;
	}

	/**
	 * D8: when the instance was renewed because the site address changed: time (UTC) and the previous instance ids.
	 *
	 * @return array{changed_at:string, previous:string[], notice:bool}|null
	 */
	public static function instance_change(): ?array {
		$site = get_option( self::INSTANCE_SITE_OPTION, null );
		if ( ! is_array( $site ) || '' === (string) ( $site['changed_at'] ?? '' ) ) {
			return null;
		}
		return array(
			'changed_at' => (string) $site['changed_at'],
			'previous'   => array_values( array_map( static fn( $p ) => (string) ( is_array( $p ) ? ( $p['instance'] ?? '' ) : $p ), (array) ( $site['previous'] ?? array() ) ) ),
			'notice'     => ! empty( $site['notice'] ),
		);
	}

	/** The text of the D8 notice (Site Health, admin notice). */
	public static function instance_change_text(): string {
		return __( 'New workflow instance because the address of the website changed (copy/staging).', 'voucher-captain' );
	}

	/**
	 * `admin_notices`: tells a shop manager once that the instance was renewed (D8); the notice is cleared when shown.
	 */
	public static function instance_notice(): void {
		if ( '' !== (string) get_option( self::INSTANCE_OPTION, '' ) ) {
			self::instance(); // Notices a new address also before the next event.
		}
		$change = self::instance_change();
		if ( ! $change || ! $change['notice'] || ! current_user_can( 'manage_woocommerce' ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- WooCommerce capability.
			return;
		}
		$site           = (array) get_option( self::INSTANCE_SITE_OPTION, array() );
		$site['notice'] = false;
		update_option( self::INSTANCE_SITE_OPTION, $site, true );
		echo '<div class="notice notice-info is-dismissible"><p><strong>' . esc_html( \VCAP\Brand::name( 'notice' ) ) . ':</strong> '
			. esc_html( self::instance_change_text() ) . ' '
			. esc_html__( 'A connected workflow plugin receives new event ids from now on; events of the original website are not repeated here.', 'voucher-captain' ) . '</p></div>';
	}

	/** A SHA-1 hex string in UUID form (version 5 bits, RFC 4122 variant). */
	private static function uuid( string $hash ): string {
		$variant = dechex( ( hexdec( substr( $hash, 16, 2 ) ) & 0x3f ) | 0x80 );
		return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-5' . substr( $hash, 13, 3 ) . '-' . $variant . substr( $hash, 18, 2 ) . '-' . substr( $hash, 20, 12 );
	}

	/** UTC "Y-m-d H:i:s" as ISO 8601 ("2026-11-16T09:30:00Z"), null for none. */
	public static function iso( ?string $utc ): ?string {
		if ( null === $utc || '' === $utc ) {
			return null;
		}
		$time = strtotime( $utc . ' UTC' );
		return false === $time ? null : gmdate( 'Y-m-d\TH:i:s\Z', $time );
	}

	private static function minor( float $amount, int $decimals ): int {
		return (int) round( $amount * ( 10 ** $decimals ) );
	}

	/**
	 * Listener of "vcap_voucher_event" (fired after the COMMIT of the entry): builds and fires the envelopes.
	 * Nothing is read or built while no plugin listens to "vcap_workflow_event".
	 *
	 * @param mixed $voucher_id    Voucher id.
	 * @param mixed $event         Ledger event.
	 * @param mixed $amount        Amount of the entry.
	 * @param mixed $balance_after Balance after the entry.
	 * @param mixed $note          Note as text (not used: it may hold free text).
	 * @param mixed $order_id      Order id.
	 * @param mixed $source        Source::current() of the entry.
	 * @param mixed $user_id       Acting user (not used: no personal data in events).
	 * @param mixed $entry         Ledger row: id, created_at, note (coded note or null).
	 */
	public function on_ledger( $voucher_id, $event, $amount = null, $balance_after = null, $note = '', $order_id = 0, $source = '', $user_id = 0, $entry = array() ): void {
		unset( $balance_after, $note, $user_id );
		if ( ! has_filter( self::EVENT_HOOK ) || ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return;
		}
		if ( self::INCOMPATIBLE === self::compatibility( self::consumer() ) ) {
			return; // R2: a registered workflow plugin built for another major contract gets no events (status, WORKFLOW.md).
		}
		$mapped = self::map( (string) $event, is_array( $entry['note'] ?? null ) ? $entry['note'] : null, null === $amount ? null : (float) $amount, (int) $order_id );
		if ( ! $mapped ) {
			return;
		}
		$voucher = $this->vouchers->get( (int) $voucher_id );
		if ( ! $voucher || 'credit' === $voucher->type ) {
			return; // Store credit is a customer account, not a voucher (no workflow events, WORKFLOW.md).
		}
		$row = array(
			'id'         => (int) $entry['id'],
			'created_at' => (string) ( $entry['created_at'] ?? gmdate( 'Y-m-d H:i:s' ) ),
			'order_id'   => (int) $order_id,
			'source'     => (string) $source,
		);
		foreach ( $mapped as $item ) {
			$this->dispatch( self::envelope( $row, $voucher, $item[0], $item[1] ) );
		}
	}

	/**
	 * Fires one envelope. A listener that throws is logged and counted (status()); the booking that caused the
	 * event is committed already and is never undone – the workflow plugin catches up with replay().
	 */
	public function dispatch( array $envelope ): bool {
		try {
			/**
			 * Workflow event of contract v1 (WORKFLOW.md): an envelope with contract_version, provider, event_id
			 * (stable, the same on a replay), type, occurred_at (UTC), source_type, source_id (voucher id),
			 * sequence (ledger row id), blog_id, status, replayed and data (allowlisted fields, no personal data,
			 * no codes). Fired after the COMMIT of the change.
			 */
			do_action( 'vcap_workflow_event', $envelope );
		} catch ( \Throwable $e ) {
			$this->note_status( $envelope, Mask::addresses( mb_substr( get_class( $e ) . ': ' . $e->getMessage(), 0, 300 ) ) );
			Logger::error( 'Workflow event listener failed: ' . Mask::addresses( $e->getMessage() ), array( 'event_id' => $envelope['event_id'] ?? '', 'voucher_id' => $envelope['source_id'] ?? 0 ) );
			return false;
		}
		$this->note_status( $envelope, '' );
		return true;
	}

	/**
	 * Events of the ledger entries after a sequence number (ledger row id), oldest first – for a workflow plugin
	 * that missed events (deactivated, failed listener). Same event ids as the live events, "replayed" true and
	 * the voucher state as it is now. "next" is the sequence to continue after (also when no row was mapped).
	 *
	 * @return array{events: array<int, array>, next: int, more: bool}
	 */
	public function replay( int $after = 0, int $limit = 100 ): array {
		$limit  = max( 1, min( 500, $limit ) );
		$rows   = $this->vouchers->events_after( $after, $limit );
		$events = array();
		$next   = $after;
		$cache  = array();
		foreach ( $rows as $row ) {
			$next   = max( $next, (int) $row['id'] );
			$mapped = self::map( (string) $row['event'], \VCAP\Note::decode( (string) $row['note'] ), null === $row['amount'] ? null : (float) $row['amount'], (int) $row['order_id'] );
			if ( ! $mapped ) {
				continue;
			}
			$id = (int) $row['voucher_id'];
			if ( ! array_key_exists( $id, $cache ) ) {
				$cache[ $id ] = $this->vouchers->get( $id );
			}
			$voucher = $cache[ $id ];
			if ( ! $voucher || 'credit' === $voucher->type ) {
				continue;
			}
			foreach ( $mapped as $item ) {
				$events[] = self::envelope( $row, $voucher, $item[0], $item[1], true );
			}
		}
		return array(
			'events' => $events,
			'next'   => $next,
			'more'   => count( $rows ) === $limit,
		);
	}

	// -----------------------------------------------------------------------------------------------------------
	// Services (WF-03): thin entry points, see Services.
	// -----------------------------------------------------------------------------------------------------------

	public function services(): Services {
		return $this->services ??= new Services( $this->vouchers, $this->redemptions );
	}

	/** @return array|\WP_Error */
	public function issue( array $args ) {
		return $this->services()->issue( $args );
	}

	/** @return array|\WP_Error */
	public function resend( array $args ) {
		return $this->services()->resend( $args );
	}

	/** @return array|\WP_Error */
	public function void( array $args ) {
		return $this->services()->void( $args );
	}

	/** @return array|\WP_Error */
	public function reactivate( array $args ) {
		return $this->services()->reactivate( $args );
	}

	/** @return array|\WP_Error */
	public function redeem( array $args ) {
		return $this->services()->redeem( $args );
	}

	/** @return array|\WP_Error */
	public function contact( array $args ) {
		return $this->services()->contact( $args );
	}

	// -----------------------------------------------------------------------------------------------------------
	// Status (WF-05).
	// -----------------------------------------------------------------------------------------------------------

	private function note_status( array $envelope, string $error ): void {
		if ( ! $this->status ) {
			$stored       = get_option( self::STATUS_OPTION, array() );
			$this->status = is_array( $stored ) ? $stored : array();
		}
		$now = gmdate( 'Y-m-d H:i:s' );
		if ( '' === $error ) {
			$this->status['last_event_id']   = (string) ( $envelope['event_id'] ?? '' );
			$this->status['last_event_type'] = (string) ( $envelope['type'] ?? '' );
			$this->status['last_sequence']   = (int) ( $envelope['sequence'] ?? 0 );
			$this->status['last_event_at']   = $now;
			$this->status['delivered']       = (int) ( $this->status['delivered'] ?? 0 ) + 1;
			++$this->delta['delivered'];
		} else {
			$this->status['failures']   = (int) ( $this->status['failures'] ?? 0 ) + 1;
			++$this->delta['failures'];
			$this->status['last_error'] = array(
				'at'       => $now,
				'event_id' => (string) ( $envelope['event_id'] ?? '' ),
				'sequence' => (int) ( $envelope['sequence'] ?? 0 ),
				'message'  => $error,
			);
		}
		if ( ! $this->dirty ) {
			$this->dirty = true;
			add_action( 'shutdown', array( $this, 'save_status' ) );
		}
	}

	/** Saves the dispatch status of this request (once, at shutdown). */
	public function save_status(): void {
		if ( ! $this->dirty ) {
			return;
		}
		// R11: other requests may have counted meanwhile – add this request's counts to the stored ones (read fresh)
		// instead of writing back the numbers read at its start.
		wp_cache_delete( self::STATUS_OPTION, 'options' );
		$stored = get_option( self::STATUS_OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$status = $this->status;
		foreach ( array( 'delivered', 'failures' ) as $counter ) {
			$status[ $counter ] = (int) ( $stored[ $counter ] ?? 0 ) + $this->delta[ $counter ];
		}
		update_option( self::STATUS_OPTION, $status, false );
		$this->status = $status;
		$this->delta  = array( 'delivered' => 0, 'failures' => 0 );
		$this->dirty  = false;
	}

	/**
	 * Admin status of the contract: version, workflow plugin and its compatibility, hand-overs switched on and in
	 * effect, last event, counters and the last listener error.
	 *
	 * @return array<string, mixed>
	 */
	public function status(): array {
		$stored   = $this->status ? $this->status : get_option( self::STATUS_OPTION, array() );
		$stored   = is_array( $stored ) ? $stored : array();
		$consumer = self::consumer();
		return array(
			'contract_version' => self::CONTRACT_VERSION,
			'consumer'         => $consumer,
			'compatibility'    => self::compatibility( $consumer ),
			'listening'        => has_filter( self::EVENT_HOOK ),
			'switched'         => Handover::switched(),
			'handed_over'      => array_values( array_filter( Handover::switched(), array( Handover::class, 'active' ) ) ),
			'last_event_id'    => (string) ( $stored['last_event_id'] ?? '' ),
			'last_event_type'  => (string) ( $stored['last_event_type'] ?? '' ),
			'last_sequence'    => (int) ( $stored['last_sequence'] ?? 0 ),
			'last_event_at'    => (string) ( $stored['last_event_at'] ?? '' ),
			'delivered'        => (int) ( $stored['delivered'] ?? 0 ),
			'failures'         => (int) ( $stored['failures'] ?? 0 ),
			'last_error'       => is_array( $stored['last_error'] ?? null ) ? $stored['last_error'] : null,
		);
	}

	/**
	 * The status as lines of plain text (Site Health info, settings).
	 *
	 * @return string[]
	 */
	public function status_lines(): array {
		$s      = $this->status();
		$labels = Handover::labels();
		/* translators: %s: contract version, e.g. 1.0.0 */
		$lines = array( sprintf( __( 'Provider contract %s', 'voucher-captain' ), $s['contract_version'] ) );
		if ( self::MISSING === $s['compatibility'] ) {
			$lines[] = __( 'No workflow plugin registered – all voucher flows and emails run natively.', 'voucher-captain' );
		} elseif ( self::INCOMPATIBLE === $s['compatibility'] ) {
			/* translators: 1: plugin name and version, 2: contract version the plugin was built for */
			$lines[] = sprintf( __( '%1$s is registered but expects contract %2$s – not compatible: no events, no hand-over, all voucher flows run natively.', 'voucher-captain' ), trim( $s['consumer']['name'] . ' ' . $s['consumer']['version'] ), '' !== $s['consumer']['contract'] ? $s['consumer']['contract'] : '—' );
		} else {
			/* translators: 1: plugin name and version, 2: edition (core/pro), 3: licence state */
			$lines[] = sprintf( __( '%1$s registered (edition: %2$s, licence: %3$s).', 'voucher-captain' ), trim( $s['consumer']['name'] . ' ' . $s['consumer']['version'] ), $s['consumer']['edition'], $s['consumer']['entitlement'] );
		}
		$names = static fn( array $items ): string => $items ? implode( ', ', array_map( static fn( $c ) => $labels[ $c ] ?? $c, $items ) ) : __( 'None', 'voucher-captain' );
		/* translators: %s: list of communications */
		$lines[] = sprintf( __( 'Handed over to the workflow now: %s', 'voucher-captain' ), $names( $s['handed_over'] ) );
		$fallback = array_values( array_diff( $s['switched'], $s['handed_over'] ) );
		if ( $fallback ) {
			/* translators: %s: list of communications */
			$lines[] = sprintf( __( 'Switched on, but sent by Voucher Captain because the workflow plugin does not take them over now: %s', 'voucher-captain' ), $names( $fallback ) );
		}
		$change = self::instance_change();
		if ( $change ) {
			/* translators: 1: notice that the instance is new, 2: date (UTC), 3: previous instance id */
			$lines[] = sprintf( __( '%1$s Since %2$s UTC, previous instance: %3$s', 'voucher-captain' ), self::instance_change_text(), $change['changed_at'], (string) end( $change['previous'] ) );
		}
		$lines[] = '' !== $s['last_event_id']
			/* translators: 1: event id, 2: event type, 3: date (UTC) */
			? sprintf( __( 'Last event: %1$s (%2$s, %3$s UTC)', 'voucher-captain' ), $s['last_event_id'], $s['last_event_type'], $s['last_event_at'] )
			: __( 'Last event: none yet', 'voucher-captain' );
		/* translators: 1: events delivered, 2: listener errors */
		$lines[] = sprintf( __( 'Events delivered: %1$d, listener errors: %2$d', 'voucher-captain' ), $s['delivered'], $s['failures'] );
		if ( $s['last_error'] ) {
			/* translators: 1: date (UTC), 2: ledger row id, 3: error message */
			$lines[] = sprintf( __( 'Last error: %1$s UTC at history entry %2$d – %3$s. The workflow plugin can fetch missed events again (replay from this entry).', 'voucher-captain' ), $s['last_error']['at'], $s['last_error']['sequence'], $s['last_error']['message'] );
		}
		return $lines;
	}

	/** The status for the settings page (escaped HTML). */
	public function status_html(): string {
		return implode( '<br />', array_map( 'esc_html', $this->status_lines() ) );
	}
}
