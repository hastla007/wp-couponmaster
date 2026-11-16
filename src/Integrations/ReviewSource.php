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
 * Review source contract v1 (API.md → "Captain Suite: review source"); 1.1.0 adds `locale` to recipient(). Voucher Captain only reports that a
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
 * One service = one request (owner decision 16.11.2026): only the FIRST confirmed redemption of a voucher becomes
 * eligible – later redemptions of the same voucher (a gift card used in several visits) create no event. When that
 * first redemption is revoked (redeem_reversed, refunded), the next confirmed redemption may become eligible again.
 * Filter `vcap_review_first_redemption_only` (default true) switches back to one event per redemption.
 *
 * Every redemption has one stable reference (same redemption ⇒ same reference); a repeated event for it is ignored.
 * It is "revoked" once when the counter redemption is cancelled (redeem_reversed), the external redemption is
 * refunded (any amount) or the voucher is voided. Payloads carry no code, name, email or amount; the address is
 * given only to an authorised caller through recipient().
 *
 * @since 1.2.0
 */
final class ReviewSource {
	/** 1.1.0: recipient() also answers `locale` (additive). */
	public const CONTRACT         = '1.1.0';
	public const SOURCE           = 'voucher-captain';
	public const PRODUCT_META     = '_vcap_review_service';
	public const EVENT_ELIGIBLE   = 'vcap_review_redemption_eligible';
	public const EVENT_REVOKED    = 'vcap_review_redemption_revoked';
	public const REFERENCE_PREFIX = 'vcr1_';
	/** Record of a reference (option, not autoloaded): state, voucher id, product, channel, times. */
	public const OPTION_PREFIX = 'vcap_review_ref_';
	/** References of a voucher (for "voided"). */
	public const INDEX_PREFIX = 'vcap_review_voucher_';
	/** Claim of a voucher's open review source (option, not autoloaded): the eligible reference (first-only mode). */
	public const CLAIM_PREFIX = 'vcap_review_claim_';
	/** Seconds after which a claim without a record counts as abandoned. */
	private const CLAIM_STALE = 60;
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
		if ( self::first_redemption_only( $voucher ) && ! $this->claim( (int) $voucher->id, $reference ) ) {
			return; // One service = one request: the voucher has an eligible redemption already.
		}
		$record = array(
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
	 * Whether only the first confirmed redemption of a voucher counts (default true, owner decision 16.11.2026):
	 * a gift card redeemed in several visits asks for one review, not one per visit.
	 */
	public static function first_redemption_only( Voucher $voucher ): bool {
		/**
		 * Whether only the first confirmed redemption of a voucher becomes a review source ("eligible"). Further
		 * redemptions of the same voucher create no event while that one is eligible; after it was revoked
		 * (redeem_reversed, refunded) the next confirmed redemption may become eligible. False: every redemption
		 * counts on its own.
		 *
		 * @since 1.2.0
		 * @param bool    $only    Default true.
		 * @param Voucher $voucher Voucher.
		 */
		return (bool) apply_filters( 'vcap_review_first_redemption_only', true, $voucher );
	}

	/**
	 * The eligible reference of a voucher, '' when none is eligible (any more).
	 *
	 * @param int $voucher_id Voucher id.
	 */
	public function open_reference( int $voucher_id ): string {
		foreach ( (array) get_option( self::INDEX_PREFIX . $voucher_id, array() ) as $reference ) {
			$record = self::record( (string) $reference );
			if ( $record && 'eligible' === $record['state'] ) {
				return (string) $reference;
			}
		}
		return '';
	}

	/**
	 * First-only mode: takes the voucher's claim for $reference. False when another (or this) reference of the
	 * voucher is eligible already. The claim is an option created with add_option() (one row per voucher), so two
	 * redemptions at the same moment do not both become eligible; a claim whose reference was revoked is taken over.
	 */
	private function claim( int $voucher_id, string $reference ): bool {
		if ( '' !== $this->open_reference( $voucher_id ) ) {
			return false;
		}
		$key   = self::CLAIM_PREFIX . $voucher_id;
		$claim = array(
			'reference' => $reference,
			'at'        => time(),
		);
		if ( add_option( $key, $claim, '', false ) ) {
			return true;
		}
		$held   = (array) get_option( $key, array() );
		$record = self::record( (string) ( $held['reference'] ?? '' ) );
		if ( $record ? 'eligible' === $record['state'] : (int) ( $held['at'] ?? 0 ) > time() - self::CLAIM_STALE ) {
			return false; // Eligible, or claimed by a redemption that is being recorded right now.
		}
		update_option( $key, $claim, false ); // The claimed reference was revoked (or never recorded): take it over.
		return true;
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
		delete_option( self::CLAIM_PREFIX . $voucher_id );
	}

	/** Records checked per database round in purge(). */
	private const PURGE_BATCH = 200;

	/**
	 * R4: daily retention of the reference records (one option each). Revoked records go after
	 * `vcap_review_revoked_retention_days` (default 90 – long enough for the review plugin to reconcile them with
	 * get()), every record after `vcap_review_retention_months` (default 24). 0 keeps them. Works in batches until
	 * done or $budget seconds are used.
	 *
	 * @return bool True when finished (nothing left to check within the budget).
	 */
	public function purge( float $budget = 10.0 ): bool {
		global $wpdb;
		$days   = (int) apply_filters( 'vcap_review_revoked_retention_days', 90 );
		$months = (int) apply_filters( 'vcap_review_retention_months', 24 );
		if ( $days <= 0 && $months <= 0 ) {
			return true;
		}
		$revoked_before = $days > 0 ? gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) : '';
		$created_before = $months > 0 ? gmdate( 'Y-m-d H:i:s', (int) strtotime( '-' . $months . ' months', time() ) ) : '';
		$start          = microtime( true );
		$after          = 0;
		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- option rows by prefix, keyset paging on option_id.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_id, option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_id > %d ORDER BY option_id LIMIT %d", $wpdb->esc_like( self::OPTION_PREFIX ) . '%', $after, self::PURGE_BATCH ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$after  = (int) $row['option_id'];
				$record = maybe_unserialize( $row['option_value'] );
				if ( ! is_array( $record ) ) {
					continue;
				}
				$revoked = 'revoked' === ( $record['state'] ?? '' ) && '' !== $revoked_before && '' !== (string) ( $record['revoked_at'] ?? '' ) && (string) $record['revoked_at'] < $revoked_before;
				$old     = '' !== $created_before && '' !== (string) ( $record['at'] ?? '' ) && (string) $record['at'] < $created_before;
				if ( ! $revoked && ! $old ) {
					continue;
				}
				delete_option( (string) $row['option_name'] );
				$voucher_id = (int) ( $record['voucher_id'] ?? 0 );
				if ( $voucher_id > 0 ) {
					$reference = substr( (string) $row['option_name'], strlen( self::OPTION_PREFIX ) );
					$index     = array_values( array_diff( (array) get_option( self::INDEX_PREFIX . $voucher_id, array() ), array( $reference ) ) );
					if ( $index ) {
						update_option( self::INDEX_PREFIX . $voucher_id, $index, false );
					} else {
						delete_option( self::INDEX_PREFIX . $voucher_id );
					}
				}
			}
			if ( microtime( true ) - $start >= $budget ) {
				return count( (array) $rows ) < self::PURGE_BATCH;
			}
		} while ( self::PURGE_BATCH === count( (array) $rows ) );
		return true;
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
	 * Since contract 1.1.0 the answer also carries `locale` (locale_for()).
	 *
	 * @return array{reference:string, email:string, name:string, role:string, locale:string}|\WP_Error
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
			'locale'    => self::locale_for( $voucher, $email, $role ),
		);
	}

	/**
	 * Language of a review invitation (contract 1.1.0): the language of the voucher's order when it is known (WPML,
	 * Polylang, `vcap_order_language`; then through `vcap_email_locale` like the voucher emails), else the locale of
	 * the customer account with that address (get_user_locale()), else the site language (get_locale()).
	 *
	 * @param string $email Address the invitation goes to.
	 * @param string $role  recipient or buyer.
	 */
	public static function locale_for( Voucher $voucher, string $email, string $role = 'recipient' ): string {
		$order  = $voucher->order();
		$locale = \VCAP\Compat\Multilingual::locale_of( \VCAP\Compat\Multilingual::order_language( $order ) );
		if ( '' !== $locale ) {
			$locale = (string) apply_filters( 'vcap_email_locale', $locale, $voucher, $order, null );
		}
		if ( '' === $locale && '' !== $email && function_exists( 'get_user_by' ) ) {
			$user = get_user_by( 'email', $email );
			if ( is_object( $user ) && (int) ( $user->ID ?? 0 ) > 0 ) {
				$locale = (string) get_user_locale( (int) $user->ID );
			}
		}
		if ( '' === $locale ) {
			$locale = (string) get_locale();
		}
		/**
		 * Locale of a review invitation answered by recipient() (contract 1.1.0).
		 *
		 * @since 1.2.0
		 * @param string  $locale  Order language, else account locale, else site language.
		 * @param Voucher $voucher Voucher.
		 * @param string  $role    recipient or buyer.
		 */
		return (string) apply_filters( 'vcap_review_recipient_locale', $locale, $voucher, $role );
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
			 * (contract 1.x, no personal data): contract, event, event_id, reference, source, site_id, channel,
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
