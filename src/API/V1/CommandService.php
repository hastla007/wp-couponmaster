<?php
/**
 * The four voucher commands for automatic callers: issue, void, reactivate, redeem.
 *
 * @package VCAP\API\V1
 */

namespace VCAP\API\V1;

use VCAP\Note;
use VCAP\Voucher;

defined( 'ABSPATH' ) || exit;

/**
 * Entry points for automatic callers such as workflow automations (VC-1, suite spec 8.3, decisions D11/D11b):
 * voucher.issue, voucher.void, voucher.reactivate and voucher.redeem. Limits, protocol, idempotency, permission and
 * abuse protection are enforced here, in the core, for every caller; nothing here checks a licence (R-8.3f).
 *
 * - Every call names the voucher by its `voucher_id` (never by code) and carries `idempotency_key` (at most 100
 *   characters), `reason` (fixed key, no free text), `workflow_id`, `run_id`, `step_id` and `authorized_by_user_id`
 *   (R-8.2, R-8.3). Fields not listed for a command (an own `code`, `allow_weak`, `paid`, a percentage …) are refused.
 * - The capability of `authorized_by_user_id` is checked with user_can() at every call; the current user is neither
 *   used nor changed (R-8.4 to R-8.8): issue and void need vcap_manage_vouchers, reactivate vcap_increase_balance,
 *   redeem vcap_redeem_api (or vcap_manage_vouchers).
 * - Each command is off until the operator switches it on; the fixed limits of CommandLimits apply, lowered by the
 *   settings (R-8.3d). A call above a limit is refused, never shortened.
 * - Checking, counting and executing run under one lock per site (R-8.3b). The same key returns the stored reply of
 *   the first call without doing anything; the same key with other values is refused as idempotency_conflict (R-8.3a).
 * - Every call – also a refused one and a replay – is written to the protocol of the day without code; calls on a
 *   known voucher also get an entry in its history (R-8.3c). The core sends its own mails on its own rules (R-8.3e).
 * - The reply is a fixed list of keys (RESULT_KEYS) with `voucher_id` and state, never a code or personal data.
 *   Refusals are final (`final` = true, R-8.13); only `failed` (lock busy, temporary database error) may be retried
 *   with the same key.
 *
 * @since 1.3.0 (contract 1.3.0)
 */
final class CommandService {
	/** Keys of every reply, in this order. */
	public const RESULT_KEYS = array( 'status', 'code', 'command', 'voucher_id', 'voucher_status', 'amount_minor', 'currency', 'balance_minor', 'field', 'final', 'replayed', 'at' );

	/** Values of `status`. */
	public const STATUSES = array( 'done', 'duplicate', 'refused', 'failed' );

	/** Keys of every protocol entry. */
	public const PROTOCOL_KEYS = array( 'at', 'command', 'status', 'code', 'voucher_id', 'amount_minor', 'currency', 'workflow_id', 'run_id', 'step_id', 'authorized_by_user_id', 'reason', 'replayed', 'key_hash' );

	/** Fields every command requires (R-8.3). */
	public const COMMON_FIELDS = array( 'idempotency_key', 'reason', 'workflow_id', 'run_id', 'step_id', 'authorized_by_user_id' );

	/** Fields of each command: name => required. */
	public const FIELDS = array(
		'voucher.issue'      => array(
			'recipient_user_id' => true,
			'amount_minor'      => true,
			'currency'          => true,
			'product_id'        => false,
		),
		'voucher.void'       => array( 'voucher_id' => true ),
		'voucher.reactivate' => array( 'voucher_id' => true ),
		'voucher.redeem'     => array(
			'voucher_id'   => true,
			'amount_minor' => true,
			'currency'     => true,
			'target'       => true,
		),
	);

	/** Capabilities the authorising user needs (any of them, R-8.6). */
	public const CAPABILITIES = array(
		'voucher.issue'      => array( 'vcap_manage_vouchers' ),
		'voucher.void'       => array( 'vcap_manage_vouchers' ),
		'voucher.reactivate' => array( 'vcap_increase_balance' ),
		'voucher.redeem'     => array( 'vcap_redeem_api', 'vcap_manage_vouchers' ),
	);

	/** History event of a command call and the code of its note. */
	public const HISTORY_EVENT = 'command';
	public const NOTE_CODE     = 'vcap_command';

	/** Longest idempotency key (R-8.3). */
	public const MAX_KEY_LENGTH = 100;

	/** Fixed keys (reason, target) and ids of workflow, run and step. */
	private const KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.:\-]{0,63}$/';

	/** Voucher types the commands never act on (store credit accounts, entry tickets). */
	private const UNSUPPORTED_TYPES = array( 'credit', 'ticket' );

	/**
	 * Construct the service.
	 *
	 * @param CommandBackend $backend Core paths.
	 * @param CommandStore   $store   Lock, replies, counters, protocol.
	 */
	public function __construct( private CommandBackend $backend, private CommandStore $store ) {}

	/**
	 * Settings fields, history label and note text.
	 */
	public function register(): void {
		add_filter( 'vcap_settings_fields', array( self::class, 'settings_fields' ), 10, 2 );
		$options = CommandLimits::setting_options();
		foreach ( $options['counts'] as $option ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $option,
				static fn( $value ) => CommandLimits::sanitize_count( $option, $value )
			);
		}
		foreach ( $options['amounts'] as $option ) {
			add_filter(
				'woocommerce_admin_settings_sanitize_option_' . $option,
				static fn( $value ) => CommandLimits::sanitize_amount( $option, $value )
			);
		}
		add_filter( 'vcap_event_labels', array( self::class, 'event_label' ) );
		add_filter( 'vcap_note_text', array( self::class, 'note_text' ), 10, 3 );
	}

	/**
	 * Adds the command settings to the section "Integrations (API)".
	 *
	 * @param mixed  $fields  Fields.
	 * @param string $section Section id.
	 * @return mixed
	 */
	public static function settings_fields( $fields, $section = '' ) {
		return 'api' === $section && is_array( $fields ) ? array_merge( $fields, CommandLimits::settings_fields() ) : $fields;
	}

	/**
	 * History label of the event "command".
	 *
	 * @param mixed $labels Labels.
	 * @return mixed
	 */
	public static function event_label( $labels ) {
		if ( is_array( $labels ) ) {
			$labels[ self::HISTORY_EVENT ] = __( 'Automatic command', 'voucher-captain' );
		}
		return $labels;
	}

	/**
	 * Text of the coded history note of a command call.
	 *
	 * @param mixed  $text Text so far.
	 * @param string $code Note code.
	 * @param mixed  $args Note arguments (an array for a note of this class).
	 * @return mixed
	 */
	public static function note_text( $text, $code = '', $args = array() ) {
		if ( self::NOTE_CODE !== $code || ! is_array( $args ) ) {
			return $text;
		}
		$a = array_map( static fn( $v ) => is_scalar( $v ) ? (string) $v : '', array_pad( array_values( $args ), 8, '' ) );
		/* translators: 1: command, 2: result, 3: result code, 4: workflow, 5: run, 6: step, 7: authorising user id, 8: reason key */
		return sprintf( __( '%1$s: %2$s (%3$s) – workflow %4$s, run %5$s, step %6$s, authorised by user #%7$s, reason %8$s', 'voucher-captain' ), $a[0], $a[1], $a[2], $a[3], $a[4], $a[5], $a[6], $a[7] );
	}

	/**
	 * Command voucher.issue: issues a voucher with a fixed value to a WordPress user (address from the account).
	 *
	 * @param array<string, mixed> $args Common fields plus recipient_user_id, amount_minor, currency, product_id (optional).
	 * @return array<string, mixed> Reply (RESULT_KEYS).
	 */
	public function issue( array $args ): array {
		return $this->run( 'voucher.issue', $args );
	}

	/**
	 * Command voucher.void: voids a voucher without refund.
	 *
	 * @param array<string, mixed> $args Common fields plus voucher_id.
	 * @return array<string, mixed> Reply (RESULT_KEYS).
	 */
	public function void( array $args ): array {
		return $this->run( 'voucher.void', $args );
	}

	/**
	 * Command voucher.reactivate: reactivates a void voucher that was not voided because of a refund.
	 *
	 * @param array<string, mixed> $args Common fields plus voucher_id.
	 * @return array<string, mixed> Reply (RESULT_KEYS).
	 */
	public function reactivate( array $args ): array {
		return $this->run( 'voucher.reactivate', $args );
	}

	/**
	 * Command voucher.redeem: redeems a voucher wholly or partly (reserve and commit in one call, no refund).
	 *
	 * @param array<string, mixed> $args Common fields plus voucher_id, amount_minor, currency, target.
	 * @return array<string, mixed> Reply (RESULT_KEYS).
	 */
	public function redeem( array $args ): array {
		return $this->run( 'voucher.redeem', $args );
	}

	/**
	 * Protocol of a calendar day of the site (default today), oldest first; no codes, no personal data.
	 *
	 * @param string $day Y-m-d.
	 * @return array<int, array<string, mixed>>
	 */
	public function protocol( string $day = '' ): array {
		$day = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) ? $day : $this->backend->today();
		return $this->store->entries( $day );
	}

	/**
	 * Limits of the four commands as they apply now, for a display (SET-WC-15): switch, fixed ceilings, effective limits
	 * for $currency (default: the shop currency) and today's calls per site. No codes, no personal data; per-recipient
	 * counters are not included.
	 *
	 * @since 1.3.0 (contract 1.6.0)
	 * @param string $currency ISO 4217 currency for the amount limits ('' = shop currency).
	 * @return array{day: string, currency: string, shop_currency: string, fixed_currency: string, similar_currency: bool, commands: array<string, array{enabled: bool, fixed: array{max_amount_minor: int|null, per_day: int, per_recipient: int|null}, effective: array{per_day: int, per_recipient: int|null, max_amount_minor: int|null, amount_allowed: bool}, used_today: int, remaining_today: int}>}
	 */
	public function limits( string $currency = '' ): array {
		$day      = $this->backend->today();
		$shop     = strtoupper( (string) get_option( 'woocommerce_currency', '' ) );
		$currency = preg_match( '/^[A-Za-z]{3}$/', $currency ) ? strtoupper( $currency ) : $shop;
		$commands = array();
		foreach ( CommandLimits::COMMANDS as $command ) {
			$effective            = CommandLimits::effective( $command, $currency );
			$used                 = $this->store->count( $day, $command );
			$commands[ $command ] = array(
				'enabled'         => CommandLimits::enabled( $command ),
				'fixed'           => CommandLimits::FIXED[ $command ],
				'effective'       => $effective,
				'used_today'      => $used,
				'remaining_today' => max( 0, $effective['per_day'] - $used ),
			);
		}
		return array(
			'day'              => $day,
			'currency'         => $currency,
			'shop_currency'    => $shop,
			'fixed_currency'   => CommandLimits::FIXED_CURRENCY,
			'similar_currency' => in_array( $currency, CommandLimits::SIMILAR_CURRENCIES, true ),
			'commands'         => $commands,
		);
	}

	/**
	 * Runs a command by its spec name.
	 *
	 * @param string               $command voucher.issue, voucher.void, voucher.reactivate or voucher.redeem.
	 * @param array<string, mixed> $args    Fields.
	 * @return array<string, mixed> Reply (RESULT_KEYS).
	 */
	public function run( string $command, array $args ): array {
		$day = $this->backend->today();
		if ( ! isset( self::FIELDS[ $command ] ) ) {
			return self::result( '', 'refused', 'unknown_command' );
		}
		$input = self::normalise( $command, $args );
		$key   = $input['key'];
		if ( null === $key ) {
			$result = self::result( $command, 'refused', 'invalid_request', array( 'field' => 'idempotency_key' ) );
			$this->record( $day, $result, $input['values'], '', false );
			return $result;
		}
		if ( ! $this->store->lock() ) {
			$result = self::result( $command, 'failed', 'busy' );
			$this->record( $day, $result, $input['values'], $key, false );
			return $result;
		}
		try {
			$hash  = self::fingerprint( $command, $input['values'] );
			$known = $this->store->reply( $key );
			if ( null !== $known ) {
				if ( hash_equals( $known['hash'], $hash ) ) {
					$result = array_merge( array_fill_keys( self::RESULT_KEYS, null ), $known['result'], array( 'replayed' => true ) );
				} else {
					$result = self::result( $command, 'refused', 'idempotency_conflict' );
				}
				$this->record( $day, $result, $input['values'], $key, false );
				return $result;
			}
			$outcome = null === $input['error'] ? $this->decide( $command, $input['values'], $key, $day ) : array(
				'result'  => self::result( $command, 'refused', 'invalid_request', array( 'field' => $input['error'] ) ),
				'buckets' => array(),
			);
			$result  = $outcome['result'];
			if ( 'failed' !== $result['status'] ) {
				$this->store->save_reply( $key, $hash, $result, $day );
			}
			if ( 'done' === $result['status'] && $outcome['buckets'] ) {
				$this->store->increment( $day, $outcome['buckets'] );
			}
			$this->record( $day, $result, $input['values'], $key, true );
			return $result;
		} finally {
			$this->store->unlock();
		}
	}

	/**
	 * Checks and executes a command whose fields are valid.
	 *
	 * @param string               $command Command.
	 * @param array<string, mixed> $v       Normalised fields.
	 * @param string               $key     Idempotency key.
	 * @param string               $day     Calendar day of the site.
	 * @return array{result: array<string, mixed>, buckets: string[]}
	 */
	private function decide( string $command, array $v, string $key, string $day ): array {
		$refuse = static fn( string $code, array $extra = array() ): array => array(
			'result'  => self::result( $command, 'refused', $code, $extra ),
			'buckets' => array(),
		);
		if ( ! CommandLimits::enabled( $command ) ) {
			return $refuse( 'command_disabled' );
		}
		$allowed = false;
		foreach ( self::CAPABILITIES[ $command ] as $capability ) {
			$allowed = $allowed || $this->backend->user_can( (int) $v['authorized_by_user_id'], $capability );
		}
		if ( ! $allowed ) {
			return $refuse( 'not_authorized' );
		}
		switch ( $command ) {
			case 'voucher.issue':
				return $this->decide_issue( $v, $key, $day, $refuse );
			case 'voucher.redeem':
				return $this->decide_redeem( $v, $key, $day, $refuse );
			default:
				return $this->decide_status( $command, $v, $day, $refuse );
		}
	}

	/**
	 * Command voucher.issue.
	 *
	 * @param array<string, mixed> $v      Fields.
	 * @param string               $key    Idempotency key.
	 * @param string               $day    Day.
	 * @param callable             $refuse Refusal builder.
	 * @return array{result: array<string, mixed>, buckets: string[]}
	 */
	private function decide_issue( array $v, string $key, string $day, callable $refuse ): array {
		$command  = 'voucher.issue';
		$currency = (string) $v['currency'];
		$amount   = (int) $v['amount_minor'];
		$extra    = array(
			'amount_minor' => $amount,
			'currency'     => $currency,
		);
		if ( $currency !== $this->backend->shop_currency() ) {
			return $refuse( 'currency_mismatch', $extra );
		}
		$limits = CommandLimits::effective( $command, $currency );
		if ( ! $limits['amount_allowed'] ) {
			return $refuse( 'currency_not_allowed', $extra );
		}
		if ( $amount > (int) $limits['max_amount_minor'] ) {
			return $refuse( 'amount_over_limit', $extra );
		}
		if ( ! empty( $v['product_id'] ) ) {
			$refusal = $this->backend->product_refusal( (int) $v['product_id'] );
			if ( '' !== $refusal ) {
				return $refuse( $refusal, $extra );
			}
		}
		$recipient = $this->backend->recipient( (int) $v['recipient_user_id'] );
		if ( null === $recipient ) {
			return $refuse( 'recipient_unknown', $extra );
		}
		$bucket = $command . ':recipient:' . (int) $v['recipient_user_id'];
		if ( $this->store->count( $day, $command ) >= $limits['per_day'] ) {
			return $refuse( 'daily_limit_reached', $extra );
		}
		if ( $this->store->count( $day, $bucket ) >= (int) $limits['per_recipient'] ) {
			return $refuse( 'recipient_daily_limit_reached', $extra );
		}
		$args = array(
			'amount'          => $amount / ( 10 ** RedemptionService::decimals( $currency ) ),
			'recipient_email' => $recipient['email'],
			'recipient_name'  => $recipient['name'],
			'send'            => true, // The core delivers the voucher with its own mail (R-8.3e).
			'source'          => 'workflow',
			'reference'       => 'command:' . sha1( $key ), // Second guard: the core issues once per key.
			'reason'          => (string) $v['reason'],
			'paid'            => false,
		);
		if ( ! empty( $v['product_id'] ) ) {
			$args['product_id'] = (int) $v['product_id'];
		}
		$voucher = $this->backend->issue( $args );
		if ( is_wp_error( $voucher ) ) {
			$data = $voucher->get_error_data();
			switch ( $voucher->get_error_code() ) {
				case 'vcap_reference_used':
					$id = is_array( $data ) ? (int) ( $data['voucher_id'] ?? 0 ) : 0;
					return array(
						'result'  => self::result( $command, 'duplicate', 'already_issued', $extra + $this->state( $id > 0 ? $this->backend->voucher( $id ) : null ) ),
						'buckets' => array(),
					);
				case 'vcap_invalid_product':
					return $refuse( 'invalid_product', $extra );
				case 'vcap_no_address':
					return $refuse( 'recipient_unknown', $extra );
				case 'vcap_invalid_amount':
				case 'vcap_invalid_args':
					return $refuse( 'invalid_request', $extra );
				default:
					return array(
						'result'  => self::result( $command, 'failed', 'issue_failed', $extra ),
						'buckets' => array(),
					);
			}
		}
		return array(
			'result'  => self::result( $command, 'done', 'issued', $extra + $this->state( $voucher ) ),
			'buckets' => array( $command, $bucket ),
		);
	}

	/**
	 * Commands voucher.void and voucher.reactivate.
	 *
	 * @param string               $command Command.
	 * @param array<string, mixed> $v       Fields.
	 * @param string               $day     Day.
	 * @param callable             $refuse  Refusal builder.
	 * @return array{result: array<string, mixed>, buckets: string[]}
	 */
	private function decide_status( string $command, array $v, string $day, callable $refuse ): array {
		$voucher = $this->backend->voucher( (int) $v['voucher_id'] );
		if ( ! $voucher ) {
			return $refuse( 'voucher_not_found' );
		}
		$state = $this->state( $voucher );
		if ( in_array( $voucher->type, self::UNSUPPORTED_TYPES, true ) ) {
			return $refuse( 'unsupported_voucher', $state );
		}
		$void = 'voucher.void' === $command;
		if ( $void && Voucher::STATUS_VOID === $voucher->status ) {
			return array(
				'result'  => self::result( $command, 'duplicate', 'already_void', $state ),
				'buckets' => array(),
			);
		}
		if ( ! $void ) {
			if ( Voucher::STATUS_ACTIVE === $voucher->status ) {
				return array(
					'result'  => self::result( $command, 'duplicate', 'already_active', $state ),
					'buckets' => array(),
				);
			}
			if ( Voucher::STATUS_VOID !== $voucher->status ) {
				return $refuse( 'not_void', $state );
			}
			if ( $this->backend->voided_after_refund( $voucher ) ) {
				return $refuse( 'voided_after_refund', $state );
			}
		}
		if ( $this->store->count( $day, $command ) >= CommandLimits::effective( $command )['per_day'] ) {
			return $refuse( 'daily_limit_reached', $state );
		}
		$ok    = $void ? $this->backend->void( $voucher ) : $this->backend->reactivate( $voucher );
		$after = $this->state( $this->backend->voucher( $voucher->id ) ?? $voucher );
		if ( ! $ok ) {
			return array(
				'result'  => self::result( $command, 'failed', $void ? 'void_failed' : 'reactivate_failed', $after ),
				'buckets' => array(),
			);
		}
		return array(
			'result'  => self::result( $command, 'done', $void ? 'voided' : 'reactivated', $after ),
			'buckets' => array( $command ),
		);
	}

	/**
	 * Command voucher.redeem.
	 *
	 * @param array<string, mixed> $v      Fields.
	 * @param string               $key    Idempotency key.
	 * @param string               $day    Day.
	 * @param callable             $refuse Refusal builder.
	 * @return array{result: array<string, mixed>, buckets: string[]}
	 */
	private function decide_redeem( array $v, string $key, string $day, callable $refuse ): array {
		$command  = 'voucher.redeem';
		$currency = (string) $v['currency'];
		$amount   = (int) $v['amount_minor'];
		$extra    = array(
			'amount_minor' => $amount,
			'currency'     => $currency,
		);
		$voucher  = $this->backend->voucher( (int) $v['voucher_id'] );
		if ( ! $voucher ) {
			return $refuse( 'voucher_not_found', $extra );
		}
		$state = $this->state( $voucher );
		if ( in_array( $voucher->type, self::UNSUPPORTED_TYPES, true ) || 'percent' === $voucher->discount_type || ! in_array( $voucher->usage_mode, array( 'single', 'balance' ), true ) ) {
			return $refuse( 'unsupported_voucher', $extra + $state );
		}
		if ( strtoupper( (string) $voucher->currency ) !== $currency ) {
			return $refuse( 'currency_mismatch', $extra + $state );
		}
		$limits = CommandLimits::effective( $command, $currency );
		if ( ! $limits['amount_allowed'] ) {
			return $refuse( 'currency_not_allowed', $extra + $state );
		}
		if ( $amount > (int) $limits['max_amount_minor'] ) {
			return $refuse( 'amount_over_limit', $extra + $state );
		}
		$status_refusals = array(
			Voucher::STATUS_VOID      => 'voucher_void',
			Voucher::STATUS_EXPIRED   => 'voucher_expired',
			Voucher::STATUS_REDEEMED  => 'voucher_redeemed',
			Voucher::STATUS_SCHEDULED => 'voucher_not_active',
		);
		if ( isset( $status_refusals[ $voucher->status ] ) ) {
			return $refuse( $status_refusals[ $voucher->status ], $extra + $state );
		}
		if ( $voucher->is_multi_use() ) {
			if ( $amount > self::minor( $voucher->balance, $currency ) ) {
				return $refuse( 'insufficient_balance', $extra + $state ); // Never more than the remaining value.
			}
		} elseif ( self::minor( $voucher->initial_amount, $currency ) !== $amount ) {
			return $refuse( 'amount_mismatch', $extra + $state ); // A single-use voucher is redeemed as a whole.
		}
		if ( $this->store->count( $day, $command ) >= $limits['per_day'] ) {
			return $refuse( 'daily_limit_reached', $extra + $state );
		}
		$after = $this->backend->redeem(
			$voucher,
			$amount / ( 10 ** RedemptionService::decimals( $currency ) ),
			'workflow:' . (string) $v['target'],
			'vcapcmd:' . substr( hash( 'sha256', $key ), 0, 40 )
		);
		if ( is_wp_error( $after ) ) {
			$map  = array(
				'vcap_cancelled'            => 'voucher_void',
				'vcap_expired'              => 'voucher_expired',
				'vcap_already_redeemed'     => 'voucher_redeemed',
				'vcap_not_active'           => 'voucher_not_active',
				'vcap_insufficient_balance' => 'insufficient_balance',
				'vcap_not_redeemable'       => 'not_redeemable',
				'vcap_not_allowed'          => 'not_redeemable',
				'vcap_login_required'       => 'not_redeemable',
				'vcap_target_unavailable'   => 'not_redeemable',
				'vcap_currency_mismatch'    => 'currency_mismatch',
				'vcap_idempotency_conflict' => 'idempotency_conflict',
				'vcap_redemption_released'  => 'reservation_released',
				'vcap_invalid_request'      => 'invalid_request',
			);
			$code = $after->get_error_code();
			if ( isset( $map[ $code ] ) ) {
				return $refuse( $map[ $code ], $extra + $state );
			}
			return array(
				'result'  => self::result( $command, 'failed', 'redeem_failed', $extra + $state ),
				'buckets' => array(),
			);
		}
		return array(
			'result'  => self::result( $command, 'done', 'redeemed', $extra + $this->state( $after ) ),
			'buckets' => array( $command ),
		);
	}

	/**
	 * Id, status and (stored balance) remaining value of a voucher for a reply – never its code.
	 *
	 * @param Voucher|null $voucher Voucher.
	 * @return array<string, mixed>
	 */
	private function state( ?Voucher $voucher ): array {
		if ( ! $voucher || ! $voucher->id ) {
			return array();
		}
		return array(
			'voucher_id'     => (int) $voucher->id,
			'voucher_status' => (string) $voucher->status,
			'balance_minor'  => $voucher->is_multi_use() ? self::minor( $voucher->balance, (string) $voucher->currency ) : null,
		);
	}

	/**
	 * A reply with all RESULT_KEYS.
	 *
	 * @param string               $command Command.
	 * @param string               $status  done, duplicate, refused or failed.
	 * @param string               $code    Stable result code.
	 * @param array<string, mixed> $extra   voucher_id, voucher_status, amount_minor, currency, balance_minor, field.
	 * @return array<string, mixed>
	 */
	private static function result( string $command, string $status, string $code, array $extra = array() ): array {
		$out = array_fill_keys( self::RESULT_KEYS, null );
		foreach ( array( 'voucher_id', 'voucher_status', 'amount_minor', 'currency', 'balance_minor', 'field' ) as $k ) {
			if ( array_key_exists( $k, $extra ) ) {
				$out[ $k ] = $extra[ $k ];
			}
		}
		$out['status']   = $status;
		$out['code']     = $code;
		$out['command']  = $command;
		$out['final']    = 'failed' !== $status;
		$out['replayed'] = false;
		$out['at']       = gmdate( 'Y-m-d\TH:i:s\Z' );
		return $out;
	}

	/**
	 * Writes the protocol entry of a call and, for a call on a known voucher that was not a replay, its history entry.
	 *
	 * @param string               $day     Day.
	 * @param array<string, mixed> $result  Reply.
	 * @param array<string, mixed> $v       Normalised fields.
	 * @param string               $key     Idempotency key ('' = invalid).
	 * @param bool                 $history Whether to write a history entry.
	 */
	private function record( string $day, array $result, array $v, string $key, bool $history ): void {
		$voucher_id = (int) ( $result['voucher_id'] ?? 0 );
		$voucher_id = $voucher_id > 0 ? $voucher_id : (int) ( $v['voucher_id'] ?? 0 );
		$entry      = array(
			'at'                    => gmdate( 'Y-m-d\TH:i:s\Z' ),
			'command'               => (string) $result['command'],
			'status'                => (string) $result['status'],
			'code'                  => (string) $result['code'],
			'voucher_id'            => $voucher_id > 0 ? $voucher_id : null,
			'amount_minor'          => isset( $v['amount_minor'] ) ? (int) $v['amount_minor'] : null,
			'currency'              => isset( $v['currency'] ) ? (string) $v['currency'] : null,
			'workflow_id'           => isset( $v['workflow_id'] ) ? (string) $v['workflow_id'] : null,
			'run_id'                => isset( $v['run_id'] ) ? (string) $v['run_id'] : null,
			'step_id'               => isset( $v['step_id'] ) ? (string) $v['step_id'] : null,
			'authorized_by_user_id' => isset( $v['authorized_by_user_id'] ) ? (int) $v['authorized_by_user_id'] : null,
			'reason'                => isset( $v['reason'] ) ? (string) $v['reason'] : null,
			'replayed'              => ! empty( $result['replayed'] ),
			'key_hash'              => '' !== $key ? substr( hash( 'sha256', $key ), 0, 16 ) : null,
		);
		$this->store->protocol( $day, $entry );
		if ( ! $history || $voucher_id <= 0 || ! $this->backend->voucher( $voucher_id ) ) {
			return;
		}
		$amount = null;
		if ( null !== $entry['amount_minor'] && null !== $entry['currency'] ) {
			$amount = $entry['amount_minor'] / ( 10 ** RedemptionService::decimals( $entry['currency'] ) );
		}
		$this->backend->history(
			$voucher_id,
			$amount,
			Note::make(
				self::NOTE_CODE,
				array( $entry['command'], $entry['status'], $entry['code'], (string) $entry['workflow_id'], (string) $entry['run_id'], (string) $entry['step_id'], (string) $entry['authorized_by_user_id'], (string) $entry['reason'] )
			)
		);
	}

	/**
	 * Validates and normalises the fields of a call.
	 *
	 * @param string               $command Command.
	 * @param array<string, mixed> $args    Fields as given.
	 * @return array{key: string|null, values: array<string, mixed>, error: string|null} error: name of the first field
	 *         that is missing, invalid or not allowed for this command.
	 */
	private static function normalise( string $command, array $args ): array {
		$values  = array();
		$error   = null;
		$allowed = array_merge( self::COMMON_FIELDS, array_keys( self::FIELDS[ $command ] ) );
		foreach ( array_keys( $args ) as $name ) {
			if ( ! in_array( (string) $name, $allowed, true ) ) {
				// An own code, allow_weak, paid, a percentage, a refund switch or a free note: never accepted.
				$error = substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $name ) ), 0, 64 );
				$error = '' !== $error ? $error : 'unknown';
				break;
			}
		}
		$key      = $args['idempotency_key'] ?? null;
		$key      = is_string( $key ) && preg_match( '/^[\x21-\x7E]{1,' . self::MAX_KEY_LENGTH . '}$/', $key ) ? $key : null;
		$required = array_fill_keys( self::COMMON_FIELDS, true ) + self::FIELDS[ $command ];
		unset( $required['idempotency_key'] );
		foreach ( $required as $name => $must ) {
			$raw = $args[ $name ] ?? null;
			if ( null === $raw || '' === $raw ) {
				if ( $must && null === $error ) {
					$error = $name;
				}
				continue;
			}
			$value = self::field( $name, $raw );
			if ( null === $value ) {
				$error = $error ?? $name;
				continue;
			}
			$values[ $name ] = $value;
		}
		return array(
			'key'    => $key,
			'values' => $values,
			'error'  => $error,
		);
	}

	/**
	 * One normalised field value, null when invalid.
	 *
	 * @param string $name Field.
	 * @param mixed  $raw  Value as given.
	 * @return int|string|null
	 */
	private static function field( string $name, mixed $raw ): int|string|null {
		switch ( $name ) {
			case 'authorized_by_user_id':
			case 'voucher_id':
			case 'recipient_user_id':
			case 'product_id':
			case 'amount_minor':
				return self::positive_int( $raw );
			case 'currency':
				return is_string( $raw ) && preg_match( '/^[A-Za-z]{3}$/', $raw ) ? strtoupper( $raw ) : null;
			case 'reason':
			case 'target':
				return is_string( $raw ) && preg_match( self::KEY_PATTERN, $raw ) ? $raw : null;
			case 'workflow_id':
			case 'run_id':
			case 'step_id':
				if ( is_int( $raw ) ) {
					return $raw > 0 ? (string) $raw : null;
				}
				return is_string( $raw ) && preg_match( self::KEY_PATTERN, $raw ) ? $raw : null;
		}
		return null;
	}

	/**
	 * A positive whole number from an int or a string of digits (no floats: money is given in minor units).
	 *
	 * @param mixed $raw Value.
	 * @return int|null
	 */
	private static function positive_int( mixed $raw ): ?int {
		if ( is_string( $raw ) && preg_match( '/^[1-9][0-9]{0,17}$/', $raw ) ) {
			return (int) $raw;
		}
		return is_int( $raw ) && $raw > 0 ? $raw : null;
	}

	/**
	 * Fingerprint of what a call does (command and effect fields), for the idempotency check.
	 *
	 * @param string               $command Command.
	 * @param array<string, mixed> $v       Normalised fields.
	 * @return string
	 */
	private static function fingerprint( string $command, array $v ): string {
		$effect = array( 'command' => $command );
		foreach ( array_merge( array( 'reason' ), array_keys( self::FIELDS[ $command ] ) ) as $name ) {
			$effect[ $name ] = $v[ $name ] ?? null;
		}
		return hash( 'sha256', (string) wp_json_encode( $effect ) );
	}

	/**
	 * A stored amount in minor units of the currency.
	 *
	 * @param mixed  $amount   Amount (major units).
	 * @param string $currency Currency.
	 * @return int
	 */
	private static function minor( mixed $amount, string $currency ): int {
		return (int) round( (float) ( is_numeric( $amount ) ? $amount : 0 ) * ( 10 ** RedemptionService::decimals( $currency ) ) );
	}
}
