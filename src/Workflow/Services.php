<?php
namespace VCAP\Workflow;

use VCAP\API\V1\RedemptionService;
use VCAP\Claim;
use VCAP\Mask;
use VCAP\Note;
use VCAP\Source;
use VCAP\Voucher;
use VCAP\VoucherRepository;

defined( 'ABSPATH' ) || exit;

/**
 * WF-03: services of the workflow provider contract v1 – issue, resend, void, reactivate, redeem and contact.
 * Each one runs through the plugin's own logic (Issuer::issue_standalone(), Delivery::send(), Issuer::void(),
 * Issuer::reactivate(), RedemptionService::reserve()/commit()), never writes a balance or the ledger directly,
 * and is
 *  - permitted: the current user needs the capability of the service (CAPS), or the filter
 *    "vcap_workflow_service_allowed" allows it (e.g. for the technical user of the workflow plugin);
 *  - idempotent: every changing call needs an idempotency key (8–64 characters A–Z a–z 0–9 . _ : -). The first
 *    call with a key acts; a repeat with the same request answers the stored outcome ("replayed": true) without
 *    acting again, the same key with another request answers "vcap_idempotency_conflict", a repeat while the first
 *    call is still running "vcap_in_progress" (retry later with the same key). A failed call frees its key.
 *  - audited: the voucher history gets a "workflow_command" entry (service, hash of key and request), and every
 *    booking it causes carries the source "workflow".
 * Results never contain a code, a PDF or a link; only contact() returns an address, for a communication the
 * shop handed over to the workflow plugin.
 */
final class Services {
	/** Prefix of redemption keys, targets and issue references of the workflow services (reserved, not over REST). */
	public const PREFIX = 'workflow:';

	/** Ledger event of the audit entry. */
	public const AUDIT_EVENT = 'workflow_command';

	/**
	 * D9 (owner decision 16.11.2026): history entry of every successful contact() call – plugin, communication and
	 * role (time and user are columns of the row). No address, name or code; no workflow event, no money.
	 */
	public const CONTACT_EVENT = 'workflow_contact_read';

	/** Seconds after which a key without an outcome counts as abandoned (the call stopped before it finished). */
	private const STALE = 120;

	/** Capabilities per service (any one of them). */
	public const CAPS = array(
		'issue'      => array( 'vcap_manage_vouchers', 'manage_woocommerce' ),
		'resend'     => array( 'vcap_manage_vouchers', 'manage_woocommerce' ),
		'void'       => array( 'vcap_manage_vouchers', 'manage_woocommerce' ),
		'reactivate' => array( 'vcap_increase_balance', 'manage_woocommerce' ),
		'redeem'     => array( 'vcap_redeem_api', 'vcap_manage_vouchers', 'manage_woocommerce' ),
		'contact'    => array( 'vcap_manage_vouchers', 'manage_woocommerce' ),
	);

	/** Arguments of issue() handed to Issuer::issue_standalone() (no own code, no balance below the value). */
	public const ISSUE_ARGS = array( 'product_id', 'amount', 'type', 'usage', 'discount_type', 'template_id', 'expires_at', 'expiry_days', 'recipient_name', 'recipient_email', 'buyer_name', 'buyer_email', 'buyer_copy', 'message', 'send', 'delivery_at', 'paid', 'reason' );

	public function __construct( private VoucherRepository $vouchers, private RedemptionService $redemptions ) {}

	/** Translated name of a service (history). */
	public static function label( string $service ): string {
		$labels = array(
			'issue'      => __( 'issue voucher', 'voucher-captain' ),
			'resend'     => __( 'send voucher email again', 'voucher-captain' ),
			'void'       => __( 'void voucher', 'voucher-captain' ),
			'reactivate' => __( 'reactivate voucher', 'voucher-captain' ),
			'redeem'     => __( 'redeem voucher', 'voucher-captain' ),
		);
		return $labels[ $service ] ?? $service;
	}

	/** Error of the contract: stable code, HTTP-like status, whether a retry with the same key may succeed. */
	public static function error( string $code, string $message, int $status, bool $retryable = false ): \WP_Error {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status'           => $status,
				'retryable'        => $retryable,
				'contract_version' => Provider::CONTRACT_VERSION,
			)
		);
	}

	/** An error of the underlying service with the contract's data (status, retryable) added. */
	public static function wrap( \WP_Error $error ): \WP_Error {
		$data   = $error->get_error_data();
		$data   = is_array( $data ) ? $data : array();
		$status = (int) ( $data['status'] ?? 500 );
		$code   = (string) $error->get_error_code();
		return self::error( $code, $error->get_error_message(), $status, $status >= 500 || in_array( $code, array( 'vcap_in_progress', 'vcap_temporary_failure', 'vcap_reserved' ), true ) );
	}

	/** Whether the current user may use a service (capability, filter "vcap_workflow_service_allowed"). */
	public static function allowed( string $service, array $args = array() ): bool {
		$allowed = false;
		foreach ( self::CAPS[ $service ] ?? array() as $cap ) {
			if ( current_user_can( $cap ) ) { // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
				$allowed = true;
				break;
			}
		}
		/**
		 * Whether the current user may call a workflow service (issue, resend, void, reactivate, redeem, contact).
		 * Default: one of the capabilities in Workflow\Services::CAPS. Args: bool, service, the call's arguments.
		 */
		return (bool) apply_filters( 'vcap_workflow_service_allowed', $allowed, $service, $args );
	}

	/** Hash of an idempotency key (stored instead of the key). */
	public static function key_hash( string $key ): string {
		return substr( hash( 'sha256', $key ), 0, 32 );
	}

	/** Fingerprint of a request: same service, voucher and arguments = same fingerprint. */
	public static function signature( string $service, int $voucher_id, array $request ): string {
		ksort( $request );
		return substr( hash( 'sha256', $service . '|' . $voucher_id . '|' . (string) wp_json_encode( $request ) ), 0, 16 );
	}

	/** Refusal of a call before anything is read: permission and idempotency key. Null when it may go on. */
	public static function refusal( string $service, array $args, bool $needs_key = true ): ?\WP_Error {
		if ( ! self::allowed( $service, $args ) ) {
			return self::error( 'vcap_not_allowed', __( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		if ( $needs_key && ! preg_match( RedemptionService::KEY_PATTERN, (string) ( $args['idempotency_key'] ?? '' ) ) ) {
			return self::error( 'vcap_invalid_request', __( 'An idempotency key of 8–64 characters (letters, digits, . _ : -) is required.', 'voucher-captain' ), 400 );
		}
		return null;
	}

	/** Result of a service. */
	private static function result( Voucher $voucher, bool $changed, bool $replayed, array $extra = array() ): array {
		return array_merge(
			array(
				'contract_version' => Provider::CONTRACT_VERSION,
				'voucher_id'       => (int) $voucher->id,
				'status'           => $voucher->status,
				'changed'          => $changed,
				'replayed'         => $replayed,
			),
			$extra
		);
	}

	/**
	 * The voucher a call is about (permission, key and voucher checked).
	 *
	 * @return Voucher|\WP_Error
	 */
	private function target( string $service, array $args, bool $needs_key = true ) {
		$refusal = self::refusal( $service, $args, $needs_key );
		if ( $refusal ) {
			return $refusal;
		}
		$voucher = $this->vouchers->get( (int) ( $args['voucher_id'] ?? 0 ) );
		if ( ! $voucher || 'credit' === $voucher->type ) {
			return self::error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), 404 );
		}
		return $voucher;
	}

	/**
	 * The audit entry of an idempotency key: service, fingerprint and voucher, null when there is none.
	 *
	 * @return array{service:string, sig:string, voucher_id:int}|null
	 */
	private function prior( string $hash ): ?array {
		$row     = $this->vouchers->event_with_note( self::AUDIT_EVENT, '"' . $hash . '"' );
		$decoded = $row ? Note::decode( (string) $row['note'] ) : null;
		if ( ! $decoded || self::AUDIT_EVENT !== $decoded['code'] || $hash !== (string) ( $decoded['args'][1] ?? '' ) ) {
			return null;
		}
		return array(
			'service'    => (string) ( $decoded['args'][0] ?? '' ),
			'sig'        => (string) ( $decoded['args'][2] ?? '' ),
			'voucher_id' => (int) $row['voucher_id'],
		);
	}

	/**
	 * The answer for a key that was used before: replay of the same request, or a conflict.
	 *
	 * @param array{service:string, sig:string, voucher_id:int} $prior
	 * @return array|\WP_Error
	 */
	private function answer_prior( array $prior, string $service, string $sig ) {
		if ( $prior['service'] !== $service || ! hash_equals( $prior['sig'], $sig ) ) {
			return self::error( 'vcap_idempotency_conflict', __( 'This idempotency key was already used for another request.', 'voucher-captain' ), 409 );
		}
		$voucher = $this->vouchers->get( $prior['voucher_id'] );
		return $voucher ? self::result( $voucher, false, true ) : self::error( 'vcap_not_found', __( 'Voucher not found.', 'voucher-captain' ), 404 );
	}

	private static function in_progress(): \WP_Error {
		return self::error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), 409, true );
	}

	/**
	 * Runs $act once per idempotency key (claim + audit entry, see the class comment).
	 *
	 * @param callable $act Returns the result array or a WP_Error (then the key is free again).
	 * @return array|\WP_Error
	 */
	private function once( string $service, string $key, Voucher $voucher, array $request, callable $act ) {
		$hash  = self::key_hash( $key );
		$sig   = self::signature( $service, (int) $voucher->id, $request );
		$claim = 'workflow:' . $hash;
		// The audit entry is the durable record (the claim is removed by the daily maintenance after a while).
		$prior = $this->prior( $hash );
		if ( $prior ) {
			return $this->answer_prior( $prior, $service, $sig );
		}
		if ( ! Claim::take( $claim ) ) {
			$prior = $this->prior( $hash );
			if ( $prior ) {
				return $this->answer_prior( $prior, $service, $sig );
			}
			$at = Claim::taken_at( $claim );
			if ( null === $at || strtotime( $at . ' UTC' ) > time() - self::STALE ) {
				return self::in_progress();
			}
			// The first call stopped before it recorded its outcome: take the key over (only one repeat wins).
			Claim::release( $claim );
			if ( ! Claim::take( $claim ) ) {
				return self::in_progress();
			}
		}
		try {
			$result = Source::with( Source::WORKFLOW, $act );
		} catch ( \Throwable $e ) {
			Claim::release( $claim );
			throw $e;
		}
		if ( is_wp_error( $result ) ) {
			Claim::release( $claim );
			return $result;
		}
		$this->audit( (int) $voucher->id, $service, $hash, $sig );
		return $result;
	}

	private function audit( int $voucher_id, string $service, string $hash, string $sig ): void {
		Source::with( Source::WORKFLOW, fn() => $this->vouchers->log( $voucher_id, self::AUDIT_EVENT, null, null, Note::make( self::AUDIT_EVENT, array( $service, $hash, $sig ) ) ) );
	}

	/**
	 * Issues a voucher without an order (Issuer::issue_standalone(), source "workflow"): arguments ISSUE_ARGS,
	 * "paid" defaults to false (goodwill). The voucher is delivered by Voucher Captain's own email; the result
	 * carries its id and status, never its code.
	 *
	 * @return array|\WP_Error
	 */
	public function issue( array $args ) {
		$refusal = self::refusal( 'issue', $args );
		if ( $refusal ) {
			return $refusal;
		}
		$request = array_intersect_key( $args, array_flip( self::ISSUE_ARGS ) );
		$hash    = self::key_hash( (string) $args['idempotency_key'] );
		$sig     = self::signature( 'issue', 0, $request );
		$prior   = $this->prior( $hash );
		if ( $prior ) {
			return $this->answer_prior( $prior, 'issue', $sig );
		}
		$voucher = Source::with(
			Source::WORKFLOW,
			fn() => vcap()->issuer->issue_standalone(
				array(
					'paid'      => false,
					'source'    => 'workflow',
					'reference' => self::PREFIX . $hash, // One voucher per key (Issuer's own idempotency).
				) + $request
			)
		);
		if ( is_wp_error( $voucher ) ) {
			if ( 'vcap_reference_used' !== $voucher->get_error_code() ) {
				return self::wrap( $voucher );
			}
			$prior = $this->prior( $hash );
			if ( $prior ) {
				return $this->answer_prior( $prior, 'issue', $sig );
			}
			$data     = (array) $voucher->get_error_data();
			$existing = $this->vouchers->get( (int) ( $data['voucher_id'] ?? 0 ) );
			return $existing ? self::result( $existing, false, true ) : self::in_progress();
		}
		$this->audit( (int) $voucher->id, 'issue', $hash, $sig );
		return self::result( $voucher, true, false );
	}

	/**
	 * Sends the voucher email again (Delivery::send(), like the bulk action "Send again"): to the recipient or
	 * the buyer ("role") or as the voucher is routed (no role). Refused for scheduled, void, expired, used-up and
	 * reserved vouchers.
	 *
	 * @return array|\WP_Error
	 */
	public function resend( array $args ) {
		$voucher = $this->target( 'resend', $args );
		if ( is_wp_error( $voucher ) ) {
			return $voucher;
		}
		$role = sanitize_key( (string) ( $args['role'] ?? '' ) );
		if ( '' !== $role && ! in_array( $role, array( 'recipient', 'buyer' ), true ) ) {
			return self::error( 'vcap_invalid_request', __( 'The role must be "recipient" or "buyer".', 'voucher-captain' ), 400 );
		}
		return $this->once(
			'resend',
			(string) $args['idempotency_key'],
			$voucher,
			array( 'role' => $role ),
			function () use ( $voucher, $role ) {
				$refusal = \VCAP\Admin\VoucherAdmin::resend_refusal( $voucher );
				if ( '' !== $refusal ) {
					/* translators: %s: reason */
					return self::error( 'vcap_invalid_state', sprintf( __( 'The voucher email was not sent: %s.', 'voucher-captain' ), $refusal ), 409 );
				}
				$roles    = array();
				$failures = array();
				$sent     = vcap()->delivery->send( $voucher, '' === $role ? null : $role, $roles, $failures );
				if ( ! $sent ) {
					$this->vouchers->log( $voucher->id, 'delivery_failed', null, null, Note::make( 'delivery_not_sent', array( (string) wp_json_encode( $failures ) ) ) );
					return self::error( 'vcap_delivery_failed', __( 'The voucher email could not be sent. The reason is in the voucher history.', 'voucher-captain' ), 502, true );
				}
				$this->vouchers->log( $voucher->id, 'resent', null, null, Note::make( 'resent_workflow', array( implode( ',', $roles ) ) ) );
				vcap()->delivery->record_manual_send( $voucher, $roles );
				return self::result( $this->vouchers->get( $voucher->id ) ?? $voucher, true, false, array( 'roles' => array_values( $roles ) ) );
			}
		);
	}

	/**
	 * Voids a voucher (Issuer::void(), with the reason in the history). A voucher that is void already answers
	 * "changed": false.
	 *
	 * @return array|\WP_Error
	 */
	public function void( array $args ) {
		$voucher = $this->target( 'void', $args );
		if ( is_wp_error( $voucher ) ) {
			return $voucher;
		}
		$reason = mb_substr( trim( sanitize_text_field( (string) ( $args['reason'] ?? '' ) ) ), 0, 200 );
		if ( '' === $reason ) {
			return self::error( 'vcap_invalid_request', __( 'Please give a reason for voiding the voucher.', 'voucher-captain' ), 400 );
		}
		return $this->once(
			'void',
			(string) $args['idempotency_key'],
			$voucher,
			array( 'reason' => $reason ),
			function () use ( $voucher, $reason ) {
				if ( Voucher::STATUS_VOID === $voucher->status ) {
					return self::result( $voucher, false, false );
				}
				/* translators: %s: reason given by the workflow */
				if ( ! vcap()->issuer->void( $voucher, sprintf( __( 'Voided by the workflow plugin: %s', 'voucher-captain' ), $reason ) ) ) {
					return self::error( 'vcap_action_failed', __( 'The voucher could not be voided completely (see its history). Please try again.', 'voucher-captain' ), 500, true );
				}
				return self::result( $this->vouchers->get( $voucher->id ) ?? $voucher, true, false );
			}
		);
	}

	/**
	 * Reactivates a void voucher (Issuer::reactivate()). Never one that was voided because its purchase was
	 * refunded or cancelled – that needs the admin's confirmation on the voucher screen.
	 *
	 * @return array|\WP_Error
	 */
	public function reactivate( array $args ) {
		$voucher = $this->target( 'reactivate', $args );
		if ( is_wp_error( $voucher ) ) {
			return $voucher;
		}
		return $this->once(
			'reactivate',
			(string) $args['idempotency_key'],
			$voucher,
			array(),
			function () use ( $voucher ) {
				if ( Voucher::STATUS_VOID !== $voucher->status ) {
					return self::error( 'vcap_invalid_state', __( 'Only void vouchers can be reactivated. Nothing was changed.', 'voucher-captain' ), 409 );
				}
				if ( vcap()->issuer->voided_after_refund( $voucher ) ) {
					return self::error( 'vcap_voided_after_refund', __( 'This voucher was voided because its purchase was refunded or cancelled. It can only be reactivated on the voucher screen.', 'voucher-captain' ), 409 );
				}
				if ( ! vcap()->issuer->reactivate( $voucher ) ) {
					return self::error( 'vcap_action_failed', __( 'The voucher could not be reactivated (see its history).', 'voucher-captain' ), 500, true );
				}
				return self::result( $this->vouchers->get( $voucher->id ) ?? $voucher, true, false );
			}
		);
	}

	/**
	 * Redeems a voucher for a service of the workflow (RedemptionService: reserve, then commit; channel
	 * "external"): code (as the customer entered it, also "code#PIN"), target (prefixed with "workflow:"), amount
	 * (gift cards), currency, user_id / email + email_verified (personal vouchers, see API.md → Identity). The
	 * redemption key is derived from the idempotency key, so a repeat never redeems twice. A gift card can be
	 * given back by the shop only (refunds are no workflow service).
	 *
	 * @return array|\WP_Error
	 */
	public function redeem( array $args ) {
		$refusal = self::refusal( 'redeem', $args );
		if ( $refusal ) {
			return $refusal;
		}
		$code   = trim( (string) ( $args['code'] ?? '' ) );
		$target = RedemptionService::clean_target( (string) ( $args['target'] ?? '' ) );
		if ( '' === $code || '' === $target ) {
			return self::error( 'vcap_invalid_request', __( 'A voucher code and a redemption target are required.', 'voucher-captain' ), 400 );
		}
		$target = str_starts_with( $target, self::PREFIX ) ? $target : self::PREFIX . $target;
		$hash   = self::key_hash( (string) $args['idempotency_key'] );
		$key    = self::PREFIX . $hash;
		$prior  = $this->prior( $hash );
		if ( $prior && 'redeem' !== $prior['service'] ) {
			return self::error( 'vcap_idempotency_conflict', __( 'This idempotency key was already used for another request.', 'voucher-captain' ), 409 );
		}
		$call   = array(
			'key'    => $key,
			'target' => $target,
		) + array_intersect_key( $args, array_flip( array( 'amount', 'currency', 'user_id', 'email', 'email_verified' ) ) );
		$reserved = Source::with( Source::WORKFLOW, fn() => $this->redemptions->reserve( $code, $call ) );
		if ( is_wp_error( $reserved ) ) {
			return self::wrap( $reserved );
		}
		$replayed  = ! empty( $reserved['replayed'] );
		$committed = Source::with( Source::WORKFLOW, fn() => $this->redemptions->commit( $key ) );
		if ( is_wp_error( $committed ) ) {
			return self::wrap( $committed );
		}
		if ( ! $prior ) {
			$this->audit( (int) $committed['voucher_id'], 'redeem', $hash, self::signature( 'redeem', (int) $committed['voucher_id'], array( 'target' => $target, 'amount' => $committed['amount'] ?? null ) ) );
		}
		$currency = (string) ( $committed['currency'] ?? '' );
		$decimals = RedemptionService::decimals( '' !== $currency ? $currency : 'EUR' );
		$minor    = static fn( $v ) => null === $v || '' === $v ? null : (int) round( (float) $v * ( 10 ** $decimals ) );
		return array(
			'contract_version'    => Provider::CONTRACT_VERSION,
			'voucher_id'          => (int) $committed['voucher_id'],
			'redemption_key'      => $key,
			'state'               => (string) $committed['state'],
			'target'              => (string) $committed['target'],
			'amount_minor'        => $minor( $committed['amount'] ?? null ),
			'balance_after_minor' => $minor( $committed['balance_after'] ?? null ),
			'currency'            => '' !== $currency ? $currency : null,
			'committed_at'        => Provider::iso( isset( $committed['committed_at'] ) ? (string) $committed['committed_at'] : null ),
			'changed'             => ! $replayed,
			'replayed'            => $replayed,
		);
	}

	/**
	 * D9: records that the workflow plugin read the contact data of a voucher (history entry CONTACT_EVENT with the
	 * consumer's name, the communication and the role; the row keeps time and user). The entry maps to no workflow
	 * event (Provider::map()) – reading contact data never triggers the workflow again – and carries no amount, so it
	 * is in neither the liabilities nor the journal. The voucher's privacy export lists it.
	 */
	private function log_contact_read( Voucher $voucher, string $communication, string $role ): void {
		$consumer = Provider::consumer();
		$plugin   = mb_substr( sanitize_text_field( (string) ( $consumer['name'] ?? '' ) ), 0, 100 );
		Source::with( Source::WORKFLOW, fn() => $this->vouchers->log( (int) $voucher->id, self::CONTACT_EVENT, null, null, Note::make( self::CONTACT_EVENT, array( '' !== $plugin ? $plugin : 'workflow', $communication, $role ) ) ) );
	}

	/**
	 * Address and name for a communication the shop handed over to the workflow plugin (Handover::active()), so
	 * the workflow can send it: role, email, name, the last characters of the code ("…7KQ2", no secret), balance,
	 * currency and expiry. Refused for every other communication. Needs no idempotency key; every successful call is
	 * recorded in the voucher history (D9, CONTACT_EVENT).
	 *
	 * @return array|\WP_Error
	 */
	public function contact( array $args ) {
		$voucher = $this->target( 'contact', $args, false );
		if ( is_wp_error( $voucher ) ) {
			return $voucher;
		}
		$communication = sanitize_key( (string) ( $args['communication'] ?? '' ) );
		if ( ! Handover::active( $communication ) ) {
			return self::error( 'vcap_not_allowed', __( 'This communication is not handed over to the workflow plugin.', 'voucher-captain' ), 403 );
		}
		$role     = Handover::role( $voucher, $communication );
		$decimals = RedemptionService::decimals( '' !== $voucher->currency ? $voucher->currency : 'EUR' );
		$this->log_contact_read( $voucher, $communication, $role );
		return array(
			'contract_version' => Provider::CONTRACT_VERSION,
			'voucher_id'       => (int) $voucher->id,
			'communication'    => $communication,
			'role'             => $role,
			'email'            => 'recipient' === $role ? $voucher->recipient_email : $voucher->buyer_email,
			'name'             => 'recipient' === $role ? $voucher->recipient_name : $voucher->buyer_name,
			'code_hint'        => Mask::code( $voucher->code ),
			'balance_minor'    => $voucher->is_multi_use() ? (int) round( $voucher->balance * ( 10 ** $decimals ) ) : null,
			'currency'         => $voucher->currency,
			'expires_at'       => Provider::iso( $voucher->expires_at ),
		);
	}
}
