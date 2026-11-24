<?php
/**
 * Contact and consent of a voucher id (VOUCH-WF-15, suite spec F-13).
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Integrations\ReviewSource;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Lets an add-on reach the recipient (or buyer) of a voucher who has no WordPress account – only for a purpose the
 * person consented to, and only after the operator switched the function on (contract 1.5.0, owner's decision of
 * 23.11.2026 on F-13: build it, keep it off until the data protection review D8 is done).
 *
 * - Off by default (setting "Contact read function", option vcap_contact_read_enabled). While off, contact()
 *   answers vcap_contact_disabled; recording and withdrawing consent work, so nothing is lost before the switch.
 * - Consent is recorded per voucher and purpose (record_consent(), e.g. from a form the recipient sent) and bound to
 *   the address it was given for: a keyed hash of the address is stored, never the address. When the address changes
 *   or is erased, the consent no longer matches and contact() refuses. A withdrawal (withdraw_consent()) wins over
 *   an earlier consent.
 * - contact() is for the same callers as the review source's recipient(): voucher managers, shop managers and
 *   background runs without a logged-in user (filter vcap_contact_read_allowed). Every answer is written to the
 *   voucher history (purpose and acting user, no address) and announced with vcap_voucher_contact_read.
 * - No payload of Voucher Captain ever carries the address; it leaves the core only through contact().
 *
 * @since 1.3.0 (contract 1.5.0)
 */
final class ContactService {
	/** Setting that switches contact() on ('yes'/'no', default 'no'). */
	public const OPTION_ENABLED = 'vcap_contact_read_enabled';

	/** History events (core events, CORE_EVENTS). */
	public const EVENT_CONSENT   = 'contact_consent';
	public const EVENT_WITHDRAWN = 'contact_consent_withdrawn';
	public const EVENT_READ      = 'contact_read';

	/** Roles a consent and an answer can have. */
	public const ROLES = array( 'recipient', 'buyer' );

	/** Keys of the answer of contact(), in this order. */
	public const CONTACT_KEYS = array( 'voucher_id', 'purpose', 'role', 'email', 'name', 'locale', 'consent' );

	/**
	 * Construct the service.
	 *
	 * @param \Closure $lookup Voucher by id: fn( int $id ): ?Voucher.
	 * @param \Closure $events History rows of a voucher, oldest first: fn( int $id ): array<int, array<string, mixed>>.
	 * @param \Closure $log    Writes a history entry: fn( int $id, string $event, string $note ): bool.
	 */
	public function __construct( private \Closure $lookup, private \Closure $events, private \Closure $log ) {}

	/**
	 * Settings field and history labels.
	 */
	public function register(): void {
		add_filter( 'vcap_settings_fields', array( self::class, 'settings_fields' ), 10, 2 );
		add_filter( 'vcap_event_labels', array( self::class, 'event_labels' ) );
	}

	/**
	 * Whether the operator switched the contact read function on.
	 *
	 * @since 1.3.0 (contract 1.5.0)
	 */
	public static function enabled(): bool {
		return 'yes' === get_option( self::OPTION_ENABLED, 'no' );
	}

	/**
	 * Whether $purpose is a valid purpose name: lower case letters, digits and underscores, 3 to 40 characters,
	 * starting with a letter (e.g. "review_invitation").
	 *
	 * @since 1.3.0 (contract 1.5.0)
	 * @param string $purpose Purpose.
	 */
	public static function valid_purpose( string $purpose ): bool {
		return (bool) preg_match( '/^[a-z][a-z0-9_]{2,39}$/', $purpose );
	}

	/**
	 * Records that the recipient (or buyer) of a voucher agreed to be contacted for $purpose.
	 *
	 * @since 1.3.0 (contract 1.5.0)
	 * @param int                  $voucher_id Voucher id.
	 * @param string               $purpose    Purpose (valid_purpose()).
	 * @param array<string, mixed> $consent    role (recipient|buyer, default recipient), source (who asked, e.g.
	 *                                         "form-captain", required), text (id or version of the consent text
	 *                                         shown, required, at most 64 characters).
	 * @return true|\WP_Error
	 */
	public function record_consent( int $voucher_id, string $purpose, array $consent ) {
		$role   = (string) ( $consent['role'] ?? 'recipient' );
		$source = sanitize_key( (string) ( $consent['source'] ?? '' ) );
		$text   = mb_substr( sanitize_text_field( (string) ( $consent['text'] ?? '' ) ), 0, 64 );
		if ( ! self::valid_purpose( $purpose ) || ! in_array( $role, self::ROLES, true ) || '' === $source || '' === $text ) {
			return new \WP_Error( 'vcap_contact_invalid', 'Give a valid purpose, role, source and consent text.', array( 'status' => 400 ) );
		}
		$voucher = $this->voucher( $voucher_id );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_unknown_voucher', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$email = self::address( $voucher, $role );
		if ( '' === $email ) {
			return new \WP_Error( 'vcap_contact_no_address', 'No address is known for this role.', array( 'status' => 409 ) );
		}
		$note = self::note(
			array(
				'purpose' => $purpose,
				'role'    => $role,
				'source'  => $source,
				'text'    => str_replace( array( ';', '=' ), ' ', $text ),
				'address' => self::address_hash( $email ),
			)
		);
		return ( $this->log )( $voucher->id, self::EVENT_CONSENT, $note ) ? true : new \WP_Error( 'vcap_log_failed', __( 'The voucher history could not be saved.', 'voucher-captain' ) );
	}

	/**
	 * Records that the consent for $purpose was withdrawn (for every role).
	 *
	 * @since 1.3.0 (contract 1.5.0)
	 * @param int    $voucher_id Voucher id.
	 * @param string $purpose    Purpose.
	 * @param string $source     Who recorded the withdrawal (e.g. "unsubscribe-link").
	 * @return true|\WP_Error
	 */
	public function withdraw_consent( int $voucher_id, string $purpose, string $source ) {
		$source = sanitize_key( $source );
		if ( ! self::valid_purpose( $purpose ) || '' === $source ) {
			return new \WP_Error( 'vcap_contact_invalid', 'Give a valid purpose and source.', array( 'status' => 400 ) );
		}
		$voucher = $this->voucher( $voucher_id );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_unknown_voucher', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$note = self::note(
			array(
				'purpose' => $purpose,
				'source'  => $source,
			)
		);
		return ( $this->log )( $voucher->id, self::EVENT_WITHDRAWN, $note ) ? true : new \WP_Error( 'vcap_log_failed', __( 'The voucher history could not be saved.', 'voucher-captain' ) );
	}

	/**
	 * The valid consent of a voucher for $purpose: the latest consent that was not withdrawn afterwards and still
	 * matches the current address of its role; null when there is none.
	 *
	 * @since 1.3.0 (contract 1.5.0)
	 * @param int    $voucher_id Voucher id.
	 * @param string $purpose    Purpose.
	 * @return array{role:string, source:string, text:string, given_at:string}|null given_at: Y-m-d H:i:s in UTC.
	 */
	public function consent( int $voucher_id, string $purpose ): ?array {
		$voucher = self::valid_purpose( $purpose ) ? $this->voucher( $voucher_id ) : null;
		if ( ! $voucher ) {
			return null;
		}
		$rows = ( $this->events )( $voucher->id );
		foreach ( array_reverse( is_array( $rows ) ? $rows : array() ) as $row ) {
			$event = (string) ( $row['event'] ?? '' );
			if ( self::EVENT_CONSENT !== $event && self::EVENT_WITHDRAWN !== $event ) {
				continue;
			}
			$data = self::parse( (string) ( $row['note'] ?? '' ) );
			if ( ( $data['purpose'] ?? '' ) !== $purpose ) {
				continue;
			}
			if ( self::EVENT_WITHDRAWN === $event ) {
				return null; // The latest word on this purpose is a withdrawal.
			}
			$role  = (string) ( $data['role'] ?? '' );
			$email = in_array( $role, self::ROLES, true ) ? self::address( $voucher, $role ) : '';
			if ( '' === $email || ! hash_equals( (string) ( $data['address'] ?? '' ), self::address_hash( $email ) ) ) {
				return null; // Given for an address the voucher no longer has (changed or erased).
			}
			return array(
				'role'     => $role,
				'source'   => (string) ( $data['source'] ?? '' ),
				'text'     => (string) ( $data['text'] ?? '' ),
				'given_at' => (string) ( $row['created_at'] ?? '' ),
			);
		}
		return null;
	}

	/**
	 * Contact of a voucher for $purpose: the address and name of the person who consented, with the consent.
	 *
	 * Refused with: vcap_contact_disabled (403, setting off), vcap_contact_not_allowed (403, caller), vcap_contact_invalid
	 * (400, purpose), vcap_unknown_voucher (404), vcap_contact_no_consent (409, no valid consent), vcap_contact_opted_out
	 * (409, filter vcap_contact_opted_out).
	 *
	 * @since 1.3.0 (contract 1.5.0)
	 * @param int    $voucher_id Voucher id.
	 * @param string $purpose    Purpose the address is used for; must match the recorded consent.
	 * @return array{voucher_id:int, purpose:string, role:string, email:string, name:string, locale:string, consent:array<string, string>}|\WP_Error
	 */
	public function contact( int $voucher_id, string $purpose ) {
		if ( ! self::enabled() ) {
			return new \WP_Error( 'vcap_contact_disabled', 'The contact read function is switched off (Voucher Captain → Settings → Integrations (API)).', array( 'status' => 403 ) );
		}
		if ( ! self::can_read() ) {
			return new \WP_Error( 'vcap_contact_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), array( 'status' => 403 ) );
		}
		if ( ! self::valid_purpose( $purpose ) ) {
			return new \WP_Error( 'vcap_contact_invalid', 'Give a valid purpose.', array( 'status' => 400 ) );
		}
		$voucher = $this->voucher( $voucher_id );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_unknown_voucher', __( 'Voucher not found.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$consent = $this->consent( $voucher->id, $purpose );
		if ( null === $consent ) {
			return new \WP_Error( 'vcap_contact_no_consent', 'No valid consent is recorded for this purpose.', array( 'status' => 409 ) );
		}
		$role  = $consent['role'];
		$email = self::address( $voucher, $role );
		/**
		 * Whether the address must not be contacted for this purpose (e.g. an opt-out list). An opted-out address is
		 * never returned.
		 *
		 * @since 1.3.0
		 * @param bool    $opted_out Opted out (default false).
		 * @param string  $email     Address.
		 * @param string  $purpose   Purpose.
		 * @param Voucher $voucher   Voucher.
		 */
		if ( apply_filters( 'vcap_contact_opted_out', false, $email, $purpose, $voucher ) ) {
			return new \WP_Error( 'vcap_contact_opted_out', 'The person does not want to be contacted.', array( 'status' => 409 ) );
		}
		$user = get_current_user_id();
		( $this->log )(
			$voucher->id,
			self::EVENT_READ,
			self::note(
				array(
					'purpose' => $purpose,
					'role'    => $role,
					'user'    => (string) $user,
				)
			)
		);
		/**
		 * The contact of a voucher was given to a caller (for audit logs; no personal data).
		 *
		 * @since 1.3.0
		 * @param int    $voucher_id Voucher id.
		 * @param string $purpose    Purpose.
		 * @param int    $user_id    Acting user (0 = background run).
		 */
		do_action( 'vcap_voucher_contact_read', $voucher->id, $purpose, $user );
		return array(
			'voucher_id' => $voucher->id,
			'purpose'    => $purpose,
			'role'       => $role,
			'email'      => $email,
			'name'       => 'recipient' === $role ? $voucher->recipient_name : $voucher->buyer_name,
			'locale'     => ReviewSource::locale_for( $voucher, $email, $role ),
			'consent'    => array(
				'source'   => $consent['source'],
				'text'     => $consent['text'],
				'given_at' => $consent['given_at'],
			),
		);
	}

	/**
	 * Adds the switch to the section "Integrations (API)".
	 *
	 * @param mixed  $fields  Fields.
	 * @param string $section Section id.
	 * @return mixed
	 */
	public static function settings_fields( $fields, $section = '' ) {
		if ( 'api' !== $section || ! is_array( $fields ) ) {
			return $fields;
		}
		return array_merge(
			$fields,
			array(
				array(
					'title' => __( 'Contact read function', 'voucher-captain' ),
					'type'  => 'title',
					'desc'  => __( 'Lets authorised add-ons read the address of a voucher\'s recipient or buyer for a purpose this person consented to (e.g. a review invitation). Every read is written to the voucher history. Switch it on only after your data protection review and with a matching note in your privacy policy.', 'voucher-captain' ),
					'id'    => 'vcap_contact_read',
				),
				\VCAP\SettingsRegistry::field(
					self::OPTION_ENABLED,
					array(
						'title' => __( 'Allow contact reads', 'voucher-captain' ),
						'type'  => 'checkbox',
						'desc'  => __( 'Answer contact requests of add-ons (only with recorded consent)', 'voucher-captain' ),
					)
				),
				array(
					'type' => 'sectionend',
					'id'   => 'vcap_contact_read',
				),
			)
		);
	}

	/**
	 * History labels of the three events.
	 *
	 * @param mixed $labels Labels.
	 * @return mixed
	 */
	public static function event_labels( $labels ) {
		if ( is_array( $labels ) ) {
			$labels[ self::EVENT_CONSENT ]   = __( 'Contact consent recorded', 'voucher-captain' );
			$labels[ self::EVENT_WITHDRAWN ] = __( 'Contact consent withdrawn', 'voucher-captain' );
			$labels[ self::EVENT_READ ]      = __( 'Contact read by an add-on', 'voucher-captain' );
		}
		return $labels;
	}

	/**
	 * The voucher for an id, null for an unknown or invalid id.
	 *
	 * @param int $voucher_id Voucher id.
	 */
	private function voucher( int $voucher_id ): ?Voucher {
		$voucher = $voucher_id > 0 ? ( $this->lookup )( $voucher_id ) : null;
		return $voucher instanceof Voucher ? $voucher : null;
	}

	/**
	 * Current address of a role, '' when none is known.
	 *
	 * @param Voucher $voucher Voucher.
	 * @param string  $role    recipient or buyer.
	 */
	private static function address( Voucher $voucher, string $role ): string {
		$email = strtolower( trim( 'buyer' === $role ? $voucher->buyer_email : $voucher->recipient_email ) );
		return is_email( $email ) ? $email : '';
	}

	/**
	 * Keyed hash of an address (the address itself is never stored with the consent).
	 *
	 * @param string $email Address (lower case).
	 */
	private static function address_hash( string $email ): string {
		return substr( hash_hmac( 'sha256', $email, wp_salt( 'auth' ) . '|vcap_contact' ), 0, 32 );
	}

	/**
	 * Whether the caller may read a contact: like ReviewSource::recipient().
	 */
	private static function can_read(): bool {
		$user    = get_current_user_id();
		$allowed = current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' ) // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
			|| ( 0 === $user && ( ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) );
		/**
		 * Whether the current caller may read the contact of a voucher.
		 *
		 * @since 1.3.0
		 * @param bool $allowed Allowed.
		 * @param int  $user_id Current user.
		 */
		return (bool) apply_filters( 'vcap_contact_read_allowed', $allowed, $user );
	}

	/**
	 * History note "key=value;key=value" (values without ; and =).
	 *
	 * @param array<string, string> $data Data.
	 */
	private static function note( array $data ): string {
		$parts = array();
		foreach ( $data as $key => $value ) {
			$parts[] = $key . '=' . str_replace( array( ';', '=' ), ' ', $value );
		}
		return implode( ';', $parts );
	}

	/**
	 * Parses a history note written by note().
	 *
	 * @param string $note Note.
	 * @return array<string, string>
	 */
	private static function parse( string $note ): array {
		$data = array();
		foreach ( explode( ';', $note ) as $part ) {
			$pair = explode( '=', $part, 2 );
			if ( 2 === count( $pair ) ) {
				$data[ trim( $pair[0] ) ] = trim( $pair[1] );
			}
		}
		return $data;
	}
}
