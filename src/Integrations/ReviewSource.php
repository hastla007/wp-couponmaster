<?php
/**
 * Captain Suite: review source for WP Review Captain (contract v1).
 *
 * @package VCAP\Integrations
 */

namespace VCAP\Integrations;

use VCAP\API\V1\RestController;
use VCAP\Db;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Review source contract v1 (API.md → "Captain Suite: review source"). Voucher Captain only reports that a
 * redemption confirmed a service – it never invites, emails or stores reviews; that is WP Review Captain's job.
 *
 * A redemption becomes a review source ("eligible") only when all of this holds:
 * - the voucher's product is marked "Redemption confirms a service" (product meta `_vcap_review_service`, default
 *   off; filter `vcap_review_service_voucher` for vouchers without a product), and it is no store credit or ticket;
 * - it was completed at the counter (scanner, "Mark as redeemed (store)": staff confirm on site), or through the
 *   redemption API for a target prefix a connector registered with `vcap_review_external_target_prefixes`
 *   (never the built-in "passcaptain:" targets). Shop orders never count: the order is a purchase, which a review
 *   plugin judges by the order itself (and would otherwise invite twice); neither do purchases, issuing, top-ups,
 *   store credit or discount codes (plain WooCommerce coupons that are no vouchers);
 * - a review plugin listens (`vcap_review_redemption_eligible`, or the filter `vcap_review_source_active`).
 *
 * Every redemption has one stable reference (same redemption ⇒ same reference); a repeated event for it is ignored.
 * It is "revoked" once when the counter redemption is cancelled (redeem_reversed), the external redemption is
 * refunded (any amount) or the voucher is voided. Payloads carry no code, name, email or amount; the address is
 * given only to an authorised caller through recipient().
 *
 * @since 1.2.0
 */
final class ReviewSource {
	public const CONTRACT         = '1.0.0';
	public const SOURCE           = 'voucher-captain';
	public const PRODUCT_META     = '_vcap_review_service';
	public const EVENT_ELIGIBLE   = 'vcap_review_redemption_eligible';
	public const EVENT_REVOKED    = 'vcap_review_redemption_revoked';
	public const REFERENCE_PREFIX = 'vcr1_';
	/** Record of a reference (option, not autoloaded): state, voucher id, product, channel, times. */
	public const OPTION_PREFIX = 'vcap_review_ref_';
	/** References of a voucher (for "voided"). */
	public const INDEX_PREFIX = 'vcap_review_voucher_';
	/** Most references kept per voucher index (a reusable card redeemed very often). */
	public const INDEX_MAX = 100;
	/** The only keys of an event payload (allowlist, no personal data). */
	public const PAYLOAD_KEYS = array( 'contract', 'event', 'event_id', 'reference', 'source', 'site_id', 'channel', 'experience_id', 'occurred_at', 'reason' );
	/** Channels that can confirm a service at all ("shop" never). */
	public const CHANNELS = array( 'counter', 'external' );

	/** @var callable(int): ?Voucher */
	private $lookup;

	/**
	 * @param callable|null $lookup Voucher by id (default: vcap()->vouchers->get()).
	 */
	public function __construct( ?callable $lookup = null ) {
		$this->lookup = $lookup ?? static fn( int $id ) => vcap()->vouchers->get( $id );
	}

	public function register(): void {
		add_filter( 'vcap_review_source_v1', array( $this, 'service' ) );
		add_action( 'vcap_voucher_redeemed', array( $this, 'on_redeemed' ), 30 );
		add_action( 'vcap_redemption_reversed', array( $this, 'on_reversed' ), 10, 2 );
		add_action( 'vcap_redemption_refunded', array( $this, 'on_refunded' ) );
		add_action( 'vcap_voucher_event', array( $this, 'on_voucher_event' ), 10, 2 );
		add_action( 'vcap_voucher_deleted', array( $this, 'on_voucher_deleted' ) );
	}

	/**
	 * `vcap_review_source_v1`: the service for a review plugin, which applies this filter to null (API.md).
	 *
	 * @param mixed $current Earlier answer.
	 * @return mixed
	 */
	public function service( $current ) {
		return null === $current ? $this : $current;
	}

	public function contract(): string {
		return self::CONTRACT;
	}

	/** Whether a review plugin takes the events (nothing is recorded otherwise). */
	public static function active(): bool {
		/**
		 * Whether redemptions are recorded as review sources. Default: something listens to
		 * vcap_review_redemption_eligible.
		 *
		 * @since 1.2.0
		 * @param bool $active Active.
		 */
		return (bool) apply_filters( 'vcap_review_source_active', has_filter( self::EVENT_ELIGIBLE ) );
	}

	/**
	 * Channels whose redemptions confirm a service: "counter" by default; "external" only together with a target
	 * prefix in `vcap_review_external_target_prefixes`. "shop" is never accepted.
	 *
	 * @return string[]
	 */
	public static function channels(): array {
		/**
		 * Redemption channels that confirm a service for reviews ("counter", "external").
		 *
		 * @since 1.2.0
		 * @param string[] $channels Default array( 'counter' ).
		 */
		$channels = (array) apply_filters( 'vcap_review_channels', array( 'counter' ) );
		return array_values( array_intersect( self::CHANNELS, array_map( 'strval', $channels ) ) );
	}

	/** Whether the voucher is marked as a service/experience voucher (store credit and tickets never are). */
	public static function is_service_voucher( Voucher $voucher ): bool {
		if ( in_array( $voucher->type, array( 'credit', 'ticket' ), true ) ) {
			return false;
		}
		$marked = $voucher->product_id > 0 && 'yes' === (string) get_post_meta( $voucher->product_id, self::PRODUCT_META, true );
		/**
		 * Whether a redemption of this voucher confirms a service (default: its product is marked so).
		 *
		 * @since 1.2.0
		 * @param bool    $marked  Marked.
		 * @param Voucher $voucher Voucher.
		 */
		return (bool) apply_filters( 'vcap_review_service_voucher', $marked, $voucher );
	}

	/**
	 * Identity of a completed redemption (data of vcap_voucher_redeemed) that may confirm a service, or '' when it
	 * cannot: "counter:{history entry id}" or "external:{redemption key}".
	 *
	 * @param array<string, mixed> $data Redemption data.
	 */
	public static function identity( array $data ): string {
		$channel = (string) ( $data['channel'] ?? '' );
		if ( ! in_array( $channel, self::channels(), true ) ) {
			return '';
		}
		if ( 'counter' === $channel ) {
			$event = (int) ( $data['event_id'] ?? 0 );
			return $event > 0 ? 'counter:' . $event : '';
		}
		$key    = (string) ( $data['redemption_key'] ?? '' );
		$target = (string) ( $data['target'] ?? '' );
		if ( '' === $key || '' === $target || RestController::is_internal_target( $target ) ) {
			return '';
		}
		/**
		 * Target prefixes of external redemptions that confirm a delivered service (e.g. "spa-booking:").
		 *
		 * @since 1.2.0
		 * @param string[] $prefixes Default none.
		 */
		foreach ( (array) apply_filters( 'vcap_review_external_target_prefixes', array() ) as $prefix ) {
			if ( '' !== (string) $prefix && str_starts_with( $target, (string) $prefix ) ) {
				return 'external:' . $key;
			}
		}
		return '';
	}

	/** Stable reference of a redemption identity on this site (no code, no personal data). */
	public static function reference( string $identity ): string {
		return self::REFERENCE_PREFIX . substr( hash( 'sha256', self::SOURCE . '|' . get_current_blog_id() . '|' . $identity ), 0, 32 );
	}

	/** Whether the string has the form of a reference. */
	public static function valid_reference( string $reference ): bool {
		return (bool) preg_match( '/^' . self::REFERENCE_PREFIX . '[0-9a-f]{32}$/', $reference );
	}

	/**
	 * `vcap_voucher_redeemed` (after the COMMIT).
	 *
	 * @param mixed $data Redemption data.
	 */
	public function on_redeemed( $data ): void {
		if ( ! is_array( $data ) || ! self::active() ) {
			return;
		}
		$identity = self::identity( $data );
		$voucher  = '' !== $identity ? ( $this->lookup )( (int) ( $data['voucher_id'] ?? 0 ) ) : null;
		if ( ! $voucher instanceof Voucher || ! self::is_service_voucher( $voucher ) ) {
			return;
		}
		$reference = self::reference( $identity );
		$record    = array(
			'v'             => 1,
			'state'         => 'eligible',
			'voucher_id'    => (int) $voucher->id,
			'experience_id' => (int) $voucher->product_id,
			'channel'       => (string) $data['channel'],
			'at'            => gmdate( 'Y-m-d H:i:s' ),
			'revoked_at'    => '',
			'reason'        => '',
		);
		// One record per reference: a repeated event for the same redemption changes nothing and is not sent again.
		if ( ! add_option( self::OPTION_PREFIX . $reference, $record, '', false ) ) {
			return;
		}
		$index   = (array) get_option( self::INDEX_PREFIX . (int) $voucher->id, array() );
		$index[] = $reference;
		update_option( self::INDEX_PREFIX . (int) $voucher->id, array_slice( array_values( array_unique( $index ) ), -self::INDEX_MAX ), false );
		$this->emit( $reference, $record );
	}

	/**
	 * `vcap_redemption_reversed`: a counter redemption was cancelled.
	 *
	 * @param mixed $voucher_id Voucher id.
	 * @param mixed $event_id   History entry id of the cancelled redemption.
	 */
	public function on_reversed( $voucher_id, $event_id = 0 ): void {
		if ( (int) $event_id > 0 ) {
			$this->revoke( self::reference( 'counter:' . (int) $event_id ), 'redeem_reversed' );
		}
	}

	/**
	 * `vcap_redemption_refunded`: (part of) an external redemption was refunded – the service is disputed.
	 *
	 * @param mixed $data Redemption data.
	 */
	public function on_refunded( $data ): void {
		$key = is_array( $data ) ? (string) ( $data['redemption_key'] ?? '' ) : '';
		if ( '' !== $key ) {
			$this->revoke( self::reference( 'external:' . $key ), 'refunded' );
		}
	}

	/**
	 * `vcap_voucher_event`: a voided voucher (e.g. its purchase refunded) revokes all its references.
	 *
	 * @param mixed $voucher_id Voucher id.
	 * @param mixed $event      History event.
	 */
	public function on_voucher_event( $voucher_id, $event = '' ): void {
		if ( 'voided' === $event ) {
			foreach ( (array) get_option( self::INDEX_PREFIX . (int) $voucher_id, array() ) as $reference ) {
				$this->revoke( (string) $reference, 'voided' );
			}
		} elseif ( \VCAP\TaxAccounting::ERASED_EVENT === $event ) {
			$this->forget_voucher( (int) $voucher_id, 'erased' ); // Privacy eraser: personal data of the voucher removed.
		}
	}

	/**
	 * `vcap_voucher_deleted`.
	 *
	 * @param mixed $voucher Voucher as it was stored.
	 */
	public function on_voucher_deleted( $voucher ): void {
		if ( $voucher instanceof Voucher ) {
			$this->forget_voucher( (int) $voucher->id, 'deleted' );
		}
	}

	/**
	 * The voucher's personal data was erased or the voucher deleted: its references are revoked (reported once if
	 * still eligible) and no longer linked to the voucher, so no recipient can be resolved; the index goes.
	 */
	public function forget_voucher( int $voucher_id, string $reason ): void {
		$index = (array) get_option( self::INDEX_PREFIX . $voucher_id, array() );
		foreach ( $index as $reference ) {
			$this->revoke( (string) $reference, $reason );
			$record = self::record( (string) $reference );
			if ( $record && 0 !== (int) $record['voucher_id'] ) {
				$record['voucher_id'] = 0;
				update_option( self::OPTION_PREFIX . $reference, $record, false );
			}
		}
		if ( $index ) {
			delete_option( self::INDEX_PREFIX . $voucher_id );
		}
	}

	/** Marks an eligible reference as revoked and reports it once. False when unknown or revoked already. */
	public function revoke( string $reference, string $reason ): bool {
		$record = self::record( $reference );
		if ( ! $record || 'eligible' !== $record['state'] ) {
			return false;
		}
		$record['state']      = 'revoked';
		$record['revoked_at'] = gmdate( 'Y-m-d H:i:s' );
		$record['reason']     = sanitize_key( $reason );
		update_option( self::OPTION_PREFIX . $reference, $record, false );
		$this->emit( $reference, $record );
		return true;
	}

	/**
	 * Current state of a reference as an event payload (event "eligible" or "revoked"), for reconciliation.
	 *
	 * @return array<string, mixed>|null
	 */
	public function get( string $reference ): ?array {
		$record = self::record( $reference );
		return $record ? self::payload( $reference, $record ) : null;
	}

	/**
	 * Recipient of a review invitation for an eligible reference: the voucher's recipient, else its buyer. Only for
	 * voucher managers, shop managers and background runs (WP-Cron, WP-CLI) without a logged-in user (filter
	 * `vcap_review_recipient_allowed`); refused for revoked references and opted-out addresses. Consent, frequency
	 * limits and the opt-out link of the invitation are the review plugin's duty.
	 *
	 * @return array{reference:string, email:string, name:string, role:string}|\WP_Error
	 */
	public function recipient( string $reference ) {
		if ( ! self::can_resolve() ) {
			return new \WP_Error( 'vcap_review_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		$record = self::record( $reference );
		if ( $record && 'eligible' !== $record['state'] ) {
			return new \WP_Error( 'vcap_review_revoked', 'The redemption was cancelled, refunded or its personal data erased.', array( 'status' => 410 ) );
		}
		$voucher = $record && (int) $record['voucher_id'] > 0 ? ( $this->lookup )( (int) $record['voucher_id'] ) : null;
		if ( ! $record || ! $voucher instanceof Voucher ) {
			return new \WP_Error( 'vcap_review_unknown_reference', 'Unknown review reference.', array( 'status' => 404 ) );
		}
		$role  = is_email( $voucher->recipient_email ) ? 'recipient' : 'buyer';
		$email = 'recipient' === $role ? $voucher->recipient_email : ( is_email( $voucher->buyer_email ) ? $voucher->buyer_email : '' );
		if ( '' === $email ) {
			// The addresses are gone (retention cleanup or erasure): the reference is withdrawn for good.
			$this->forget_voucher( (int) $voucher->id, 'no_recipient' );
			return new \WP_Error( 'vcap_review_no_recipient', 'No address is known for this redemption.', array( 'status' => 404 ) );
		}
		/**
		 * Whether the address must not be invited (e.g. an opt-out list). An opted-out address is never returned.
		 *
		 * @since 1.2.0
		 * @param bool    $opted_out Opted out (default false).
		 * @param string  $email     Address.
		 * @param string  $reference Reference.
		 * @param Voucher $voucher   Voucher.
		 */
		if ( apply_filters( 'vcap_review_opted_out', false, $email, $reference, $voucher ) ) {
			return new \WP_Error( 'vcap_review_opted_out', 'The recipient does not want review invitations.', array( 'status' => 409 ) );
		}
		/**
		 * A recipient was given to a review plugin (for audit logs; no personal data).
		 *
		 * @since 1.2.0
		 * @param string $reference Reference.
		 * @param int    $user_id   Acting user (0 = background run).
		 */
		do_action( 'vcap_review_recipient_resolved', $reference, get_current_user_id() );
		return array(
			'reference' => $reference,
			'email'     => $email,
			'name'      => 'recipient' === $role ? $voucher->recipient_name : $voucher->buyer_name,
			'role'      => $role,
		);
	}

	/** Who may resolve addresses: voucher/shop managers, or a background run without a user. */
	private static function can_resolve(): bool {
		$user    = get_current_user_id();
		$allowed = current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
			|| ( 0 === $user && ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) );
		/**
		 * Whether the current caller may resolve the recipient of a review reference.
		 *
		 * @since 1.2.0
		 * @param bool $allowed Allowed.
		 * @param int  $user_id Current user.
		 */
		return (bool) apply_filters( 'vcap_review_recipient_allowed', $allowed, $user );
	}

	/** @return array<string, mixed>|null */
	private static function record( string $reference ): ?array {
		if ( ! self::valid_reference( $reference ) ) {
			return null;
		}
		$record = get_option( self::OPTION_PREFIX . $reference, null );
		return is_array( $record ) && isset( $record['state'], $record['voucher_id'] ) ? $record : null;
	}

	/**
	 * Event payload v1: only the keys of PAYLOAD_KEYS.
	 *
	 * @param array<string, mixed> $record Record.
	 * @return array<string, mixed>
	 */
	public static function payload( string $reference, array $record ): array {
		$event = 'revoked' === ( $record['state'] ?? '' ) ? 'revoked' : 'eligible';
		return array(
			'contract'      => self::CONTRACT,
			'event'         => $event,
			'event_id'      => $reference . ':' . $event,
			'reference'     => $reference,
			'source'        => self::SOURCE,
			'site_id'       => (int) get_current_blog_id(),
			'channel'       => (string) ( $record['channel'] ?? '' ),
			'experience_id' => (int) ( $record['experience_id'] ?? 0 ),
			'occurred_at'   => (string) ( 'revoked' === $event ? $record['revoked_at'] : $record['at'] ),
			'reason'        => (string) ( $record['reason'] ?? '' ),
		);
	}

	/** @param array<string, mixed> $record Record. */
	private function emit( string $reference, array $record ): void {
		$payload = self::payload( $reference, $record );
		if ( 'eligible' === $payload['event'] ) {
			/**
			 * A redemption confirmed a service and may be used for a review invitation (once per reference). Payload
			 * (contract 1.0.0, no personal data): contract, event, event_id, reference, source, site_id, channel,
			 * experience_id (voucher product), occurred_at (UTC), reason.
			 *
			 * @since 1.2.0
			 * @param array $payload Payload.
			 */
			Db::action( 'vcap_review_redemption_eligible', $payload );
			return;
		}
		/**
		 * A review source was withdrawn (once per reference): reason redeem_reversed, refunded, voided, erased
		 * (personal data of the voucher erased), deleted or no_recipient (no address left). Same
		 * payload as vcap_review_redemption_eligible with event "revoked".
		 *
		 * @since 1.2.0
		 * @param array $payload Payload.
		 */
		Db::action( 'vcap_review_redemption_revoked', $payload );
	}
}
