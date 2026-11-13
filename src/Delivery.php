<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery scheduling (immediate, relative delay, fixed date, customer-chosen date) via Action Scheduler.
 */
final class Delivery {
	public const GROUP = 'vcap';
	/** Retry of a failed delivery: an own hook, so a retry never replaces (or is replaced by) the scheduled delivery. */
	public const RETRY_HOOK = 'vcap_retry_voucher_delivery';
	/** One step of the daily maintenance (args: step), see MAINTENANCE_STEPS. */
	public const STEP_HOOK = 'vcap_maintenance_step';
	/** Re-plans the tasks a deactivation removed, in batches (args: phase, after). */
	public const RESTORE_HOOK = 'vcap_restore_tasks';
	/** Option per maintenance step with its last run (prefix + step). */
	public const RUN_OPTION_PREFIX = 'vcap_maintenance_run_';
	/** Failed attempts after which a delivery is given up (filter "vcap_delivery_max_attempts"). */
	public const MAX_ATTEMPTS = 12;
	/** Vouchers (and reservations) re-planned per restore batch. */
	public const RESTORE_BATCH = 500;
	/**
	 * Daily maintenance, one Action Scheduler action per step so that a failing or slow step never keeps the others
	 * from running. Cheap purges first, mails and PDFs last.
	 */
	public const MAINTENANCE_STEPS = array( 'tmp_pdfs', 'rate_limits', 'mail_failures', 'scan_keys', 'claims', 'upload_dir', 'expire_overdue', 'retention', 'legacy_rows', 'delivery_net', 'expiry_reminders' );

	public function __construct( private Settings $settings, private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'vcap_deliver_voucher', array( $this, 'deliver' ), 10, 1 );
		add_action( self::RETRY_HOOK, array( $this, 'deliver' ), 10, 1 );
		add_action( 'vcap_daily_maintenance', array( $this, 'maintenance' ) );
		add_action( self::STEP_HOOK, array( $this, 'run_maintenance_step' ), 10, 1 );
		add_action( self::RESTORE_HOOK, array( $this, 'restore_tasks' ), 10, 2 );
	}

	public static function delivery_modes(): array {
		return array(
			'immediate' => __( 'Immediately after purchase', 'voucher-captain' ),
			'delay'     => __( 'X days after purchase', 'voucher-captain' ),
			'date'      => __( 'On a fixed date', 'voucher-captain' ),
			'customer'  => __( 'On a date chosen by the customer', 'voucher-captain' ),
		);
	}

	/**
	 * UTC datetime for delivery, or null for immediate. A date the customer entered in the "Delivery date" field is
	 * used whenever that field is enabled for the product, whatever the delivery mode (the voucher shows that date).
	 */
	public static function compute_time( array $config, array $fields, ?\WC_Order $order = null ): ?string {
		$tz       = wp_timezone();
		$hour     = self::delivery_hour();
		$ts       = null;
		$customer = (string) ( $fields['delivery_date']['value'] ?? '' );
		$mode     = '' !== $customer && self::customer_date_enabled( $config ) ? 'customer' : (string) $config['delivery'];
		switch ( $mode ) {
			case 'delay':
				$days = (int) $config['delivery_delay_days'];
				if ( $days > 0 ) {
					$base = $order && $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
					$ts   = $base + $days * DAY_IN_SECONDS;
				}
				break;
			case 'date':
				if ( $config['delivery_date'] ) {
					$ts = self::local_date_ts( (string) $config['delivery_date'], $hour, $tz );
				}
				break;
			case 'customer':
				$date = (string) ( $fields['delivery_date']['value'] ?? '' );
				if ( $date ) {
					$ts = self::local_date_ts( $date, $hour, $tz );
				}
				break;
		}
		$ts = apply_filters( 'vcap_delivery_timestamp', $ts, $config, $fields, $order );
		return $ts && $ts > time() ? gmdate( 'Y-m-d H:i:s', (int) $ts ) : null;
	}

	private static function local_date_ts( string $ymd, int $hour, \DateTimeZone $tz ): ?int {
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $tz );
		return $dt ? $dt->setTime( $hour, 0 )->getTimestamp() : null;
	}

	/** Local hour at which dated deliveries go out (filter "vcap_delivery_hour"). */
	public static function delivery_hour(): int {
		return max( 0, min( 23, (int) apply_filters( 'vcap_delivery_hour', 8 ) ) );
	}

	/** Does the product page ask for a delivery date (mode "customer", or the built-in field switched on)? */
	public static function customer_date_enabled( array $config ): bool {
		$builtin = array_map( 'strval', (array) ( $config['builtin_fields'] ?? array() ) );
		return 'customer' === ( $config['delivery'] ?? '' ) || (bool) array_intersect( array( 'delivery_date', 'delivery_date_required' ), $builtin );
	}

	public function schedule( Voucher $voucher ): void {
		$args = array( 'voucher_id' => $voucher->id );
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			$this->deliver( $voucher->id );
			return;
		}
		if ( $voucher->delivery_at && strtotime( $voucher->delivery_at . ' UTC' ) > time() ) {
			as_unschedule_all_actions( 'vcap_deliver_voucher', $args, self::GROUP );
			as_schedule_single_action( strtotime( $voucher->delivery_at . ' UTC' ), 'vcap_deliver_voucher', $args, self::GROUP );
			$this->repo->log( $voucher->id, 'scheduled', null, null, get_date_from_gmt( $voucher->delivery_at ) );
		} else {
			as_enqueue_async_action( 'vcap_deliver_voucher', $args, self::GROUP );
		}
	}

	public function reschedule( Voucher $voucher, ?string $delivery_at_utc ): void {
		$from_status           = $voucher->status;
		$voucher->delivery_at  = $delivery_at_utc;
		$voucher->delivered_at = null;
		if ( $delivery_at_utc && strtotime( $delivery_at_utc . ' UTC' ) > time() && Voucher::STATUS_ACTIVE === $voucher->status ) {
			$voucher->status = Voucher::STATUS_SCHEDULED;
		}
		$this->repo->update_columns( $voucher, array( 'delivery_at', 'delivered_at' ), $from_status );
		// A new delivery goes to everyone again.
		$this->store_delivered_roles( $voucher->id, array(), array() );
		if ( Voucher::STATUS_SCHEDULED === $voucher->status && Voucher::STATUS_SCHEDULED !== $from_status ) {
			// Only once the stored row really is scheduled (a concurrent redemption wins).
			vcap()->coupons->set_coupon_enabled( $voucher, false );
		}
		if ( ! $delivery_at_utc || strtotime( $delivery_at_utc . ' UTC' ) <= time() ) {
			// A new delivery right away starts a new series of attempts (schedule() records future dates itself).
			$this->repo->log( $voucher->id, 'scheduled', null, null, get_date_from_gmt( $this->repo->now() ) );
		}
		$this->schedule( $voucher );
	}

	/**
	 * Action Scheduler callback. Idempotent: a delivered voucher is never mailed twice by the scheduler.
	 *
	 * @param int|array $voucher_id
	 */
	public function deliver( $voucher_id ): void {
		$voucher_id = is_array( $voucher_id ) ? (int) ( $voucher_id['voucher_id'] ?? 0 ) : (int) $voucher_id;
		if ( $voucher_id <= 0 ) {
			return;
		}

		// A connection-scoped lock is released automatically if a worker crashes.
		global $wpdb;
		$lock_name = 'vcap_delivery_' . $voucher_id;
		$locked    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $locked ) {
			return;
		}

		try {
			$voucher = $this->repo->get( $voucher_id );
			if ( ! $voucher || $voucher->delivered_at || ! in_array( $voucher->status, array( Voucher::STATUS_SCHEDULED, Voucher::STATUS_ACTIVE ), true ) ) {
				return;
			}
			$now = $this->repo->now();
			if ( $voucher->delivery_at && strtotime( $voucher->delivery_at . ' UTC' ) > time() ) {
				return;
			}

			// The delivery date has passed: the gift becomes redeemable even if no email can be sent
			// (email disabled, invalid address). The download link and the admin "resend" still work.
			if ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
				$table = Install::tables()['vouchers'];
				$activated = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active', updated_at = %s WHERE id = %d AND status = 'scheduled'", $now, $voucher_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				if ( 1 !== (int) $activated ) {
					// Voided or changed meanwhile (or a database error): do not enable its coupon, try again later.
					return;
				}
				vcap()->coupons->set_coupon_enabled( $voucher, true );
				$voucher->status = Voucher::STATUS_ACTIVE;
			}

			// A retry after a partial failure only mails who is still missing (no duplicate for the others): who
			// already got it is stored with the voucher (delivered_roles), so a cache flush cannot cause a second mail.
			$required  = $this->required_roles( $voucher );
			$delivered = $this->delivered_roles( $voucher );
			$pending   = array_values( array_diff( $required, $delivered ) );
			$only      = $delivered && 1 === count( $pending ) ? (string) reset( $pending ) : null;
			$sent_roles = array();
			$sent       = $delivered && ! $pending ? array() : $this->send( $voucher, $only, $sent_roles );
			if ( ! $sent && $pending ) {
				// Retried with growing pauses (retry_later()); the daily safety net catches retries that got lost.
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, __( 'No email was sent (email disabled or no valid address).', 'voucher-captain' ) );
				$this->retry_later( $voucher );
				return;
			}
			$delivered = array_values( array_unique( array_merge( $delivered, $sent_roles ) ) );
			$missing   = array_values( array_diff( $required, $delivered ) );
			$this->store_delivered_roles( $voucher->id, $delivered, $missing );
			if ( $missing ) {
				// "Send to buyer and recipient": delivered only when both got it; the rest is retried later.
				/* translators: %s: buyer/recipient */
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, sprintf( __( 'Not delivered to: %s (will be retried).', 'voucher-captain' ), implode( ', ', $missing ) ) );
				$this->retry_later( $voucher );
				return;
			}

			$table   = Install::tables()['vouchers'];
			$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->prepare(
					"UPDATE {$table} SET delivered_at = %s, status = CASE WHEN status = 'scheduled' THEN 'active' ELSE status END, updated_at = %s
					WHERE id = %d AND delivered_at IS NULL AND status IN ('scheduled','active')",
					$now,
					$now,
					$voucher_id
				)
			);
			if ( 1 !== (int) $updated ) {
				throw new \RuntimeException( __( 'Voucher email was sent but its delivery state could not be saved.', 'voucher-captain' ) );
			}
			$voucher->delivered_at = $now;
			delete_transient( 'vcap_undelivered_count' );
			delete_transient( 'vcap_given_up_count' );
			$this->repo->log( $voucher->id, 'delivered', null, null, sprintf( /* translators: %d: number of addresses */ _n( 'Sent to %d address', 'Sent to %d addresses', count( $sent ), 'voucher-captain' ), count( $sent ) ) );
			do_action( 'vcap_voucher_delivered', $voucher, $sent );
		} catch ( \Throwable $e ) {
			Logger::error( 'Voucher delivery failed: ' . Health::mask_addresses( $e->getMessage() ), array( 'voucher_id' => $voucher_id ) );
			if ( isset( $voucher ) && $voucher instanceof Voucher ) {
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, $e->getMessage() );
				try {
					$this->retry_later( $voucher );
				} catch ( \Throwable $retry_error ) {
					// The daily safety net picks the voucher up again.
					Logger::error( 'Voucher delivery retry could not be scheduled: ' . $retry_error->getMessage(), array( 'voucher_id' => $voucher_id ) );
				}
			}
			throw $e;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Pause before the next attempt after $failures failed ones: 15 minutes, doubled per failure, at most a day
	 * (filter "vcap_delivery_retry_delay", seconds).
	 */
	public static function retry_delay( int $failures ): int {
		$delay = 15 * MINUTE_IN_SECONDS * ( 2 ** max( 0, min( 12, $failures - 1 ) ) );
		$delay = (int) apply_filters( 'vcap_delivery_retry_delay', min( DAY_IN_SECONDS, $delay ), $failures );
		return max( MINUTE_IN_SECONDS, $delay );
	}

	/** Failed attempts after which a delivery is given up (filter "vcap_delivery_max_attempts"). */
	public static function max_attempts( ?Voucher $voucher = null ): int {
		return max( 1, (int) apply_filters( 'vcap_delivery_max_attempts', self::MAX_ATTEMPTS, $voucher ) );
	}

	/**
	 * After a failed attempt (already in the ledger as "delivery_failed"): plans the next one with a growing pause,
	 * or gives the delivery up once max_attempts() attempts failed. The attempts are counted from the ledger
	 * (VoucherRepository::delivery_attempts()), so no extra column is needed.
	 */
	private function retry_later( Voucher $voucher ): void {
		$state = $this->repo->delivery_attempts( $voucher->id );
		if ( $state['given_up'] ) {
			return;
		}
		if ( $state['failures'] >= self::max_attempts( $voucher ) ) {
			$this->give_up( $voucher, $state['failures'] );
			return;
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return; // Without Action Scheduler only the daily safety net retries.
		}
		$args = array( 'voucher_id' => $voucher->id );
		// Pending retries only (the running action is not affected): one retry per voucher.
		as_unschedule_all_actions( self::RETRY_HOOK, $args, self::GROUP );
		as_schedule_single_action( time() + self::retry_delay( $state['failures'] ), self::RETRY_HOOK, $args, self::GROUP );
	}

	/** No more automatic attempts: recorded in the ledger, on the order and in the WooCommerce log. */
	private function give_up( Voucher $voucher, int $failures ): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::RETRY_HOOK, array( 'voucher_id' => $voucher->id ), self::GROUP );
		}
		$this->repo->log(
			$voucher->id,
			'delivery_given_up',
			null,
			null,
			/* translators: %d: number of failed attempts */
			sprintf( _n( 'No more automatic attempts after %d failed delivery. Check the email address and the email settings, then send the voucher again.', 'No more automatic attempts after %d failed deliveries. Check the email address and the email settings, then send the voucher again.', $failures, 'voucher-captain' ), $failures )
		);
		delete_transient( 'vcap_undelivered_count' );
		delete_transient( 'vcap_given_up_count' );
		$order = $voucher->order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $voucher->order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: voucher code, 2: number of failed attempts */
					__( 'Voucher %1$s could not be delivered after %2$d attempts and is no longer sent automatically. Check the email address and the email settings, then send it again on the voucher screen.', 'voucher-captain' ),
					$voucher->code,
					$failures
				)
			);
		}
		Logger::warning( 'Voucher delivery given up after repeated failures.', array( 'voucher_id' => $voucher->id, 'attempts' => $failures ) );
		/** A delivery was given up after $failures failed attempts. */
		do_action( 'vcap_voucher_delivery_given_up', $voucher, $failures );
	}

	/** After a reactivation (Plugin::ensure_schedules()): re-plans the removed tasks in Action Scheduler batches. */
	public function queue_restore_tasks(): void {
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::RESTORE_HOOK, array( 'phase' => 'vouchers', 'after' => 0 ), self::GROUP );
		}
	}

	/**
	 * After a reactivation: schedules again what the deactivation removed – deliveries of scheduled vouchers (those
	 * that became due meanwhile right away, however long ago), the release of open external reservations and
	 * pending referral withdrawals. Runs as Action Scheduler action in batches of RESTORE_BATCH rows; each batch
	 * queues the next one.
	 *
	 * @param string|array $phase "vouchers", "redemptions" or "referrals" (or the action's args array).
	 * @param int          $after Last id done ("referrals": the page to do).
	 * @return array{0:string, 1:int}|null The next batch (also queued), null when everything is restored.
	 */
	public function restore_tasks( $phase = 'vouchers', $after = 0 ): ?array {
		if ( is_array( $phase ) ) {
			$after = (int) ( $phase['after'] ?? 0 );
			$phase = (string) ( $phase['phase'] ?? 'vouchers' );
		}
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return null;
		}
		$next = $this->restore_batch( (string) $phase, (int) $after );
		if ( $next && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::RESTORE_HOOK, array( 'phase' => $next[0], 'after' => $next[1] ), self::GROUP );
		}
		return $next;
	}

	/** @return array{0:string, 1:int}|null */
	private function restore_batch( string $phase, int $after ): ?array {
		global $wpdb;
		$tables = Install::tables();
		$limit  = self::RESTORE_BATCH;
		if ( 'vouchers' === $phase ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, delivery_at FROM {$tables['vouchers']} WHERE status = 'scheduled' AND delivered_at IS NULL AND id > %d ORDER BY id LIMIT %d", $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $rows ? $rows : array();
			foreach ( $rows as $row ) {
				$args = array( 'voucher_id' => (int) $row['id'] );
				if ( as_has_scheduled_action( 'vcap_deliver_voucher', $args, self::GROUP ) || as_has_scheduled_action( self::RETRY_HOOK, $args, self::GROUP ) ) {
					continue;
				}
				$at = $row['delivery_at'] ? (int) strtotime( $row['delivery_at'] . ' UTC' ) : 0;
				if ( $at > time() ) {
					as_schedule_single_action( $at, 'vcap_deliver_voucher', $args, self::GROUP );
				} else {
					// Became due while the plugin was off (also longer ago than the safety net looks back).
					as_enqueue_async_action( 'vcap_deliver_voucher', $args, self::GROUP );
				}
			}
			return count( $rows ) >= $limit ? array( 'vouchers', (int) end( $rows )['id'] ) : array( 'redemptions', 0 );
		}
		if ( 'redemptions' === $phase ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, expires_at FROM {$tables['redemptions']} WHERE state = %s AND expires_at IS NOT NULL AND id > %d ORDER BY id LIMIT %d", API\V1\RedemptionService::STATE_RESERVED, $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $rows ? $rows : array();
			foreach ( $rows as $row ) {
				$args = array( 'redemption_id' => (int) $row['id'] );
				if ( ! as_has_scheduled_action( 'vcap_release_expired_redemption', $args, self::GROUP ) ) {
					as_schedule_single_action( max( time(), (int) strtotime( $row['expires_at'] . ' UTC' ) ) + MINUTE_IN_SECONDS, 'vcap_release_expired_redemption', $args, self::GROUP );
				}
			}
			return count( $rows ) >= $limit ? array( 'redemptions', (int) end( $rows )['id'] ) : array( 'referrals', 1 );
		}
		if ( 'referrals' === $phase && function_exists( 'wc_get_orders' ) ) {
			$page   = max( 1, $after );
			$orders = (array) wc_get_orders(
				array(
					'status'     => array( 'cancelled', 'refunded' ),
					'limit'      => 200,
					'paged'      => $page,
					'orderby'    => 'ID',
					'order'      => 'ASC',
					'return'     => 'ids',
					'meta_query' => array( array( 'key' => Referrals::REWARD, 'compare' => 'EXISTS' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
				)
			);
			foreach ( $orders as $order_id ) {
				$order  = wc_get_order( $order_id );
				$reward = $order instanceof \WC_Order ? $order->get_meta( Referrals::REWARD ) : null;
				$tries  = is_array( $reward ) ? (int) ( $reward['revoke_attempts'] ?? 0 ) : 0;
				if ( $tries > 0 && $tries < 5 && empty( $reward['revoked'] ) && ! as_has_scheduled_action( Referrals::RETRY_HOOK, array( (int) $order_id ), self::GROUP ) ) {
					as_schedule_single_action( time() + HOUR_IN_SECONDS, Referrals::RETRY_HOOK, array( (int) $order_id ), self::GROUP );
				}
			}
			return count( $orders ) >= 200 ? array( 'referrals', $page + 1 ) : null;
		}
		return null;
	}

	/**
	 * Remind holders of vouchers with remaining value before they expire. Each voucher is reminded at most once
	 * (ledger event 'expiry_reminder'); admins can suppress the reminder per voucher.
	 *
	 * @return int number of reminders sent
	 */
	public function send_expiry_reminders(): int {
		$days = (int) $this->settings->get( 'vcap_expiry_reminder_days' );
		if ( $days <= 0 ) {
			return 0;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['VCAP_Email_Voucher_Expiry'] ?? null;
		if ( ! $email ) {
			return 0;
		}
		$sent = 0;
		foreach ( $this->repo->due_for_expiry_reminder( $days ) as $voucher ) {
			if ( ! apply_filters( 'vcap_send_expiry_reminder', true, $voucher ) ) {
				continue;
			}
			// Claimed first (a parallel run or a crash after sending never leads to a second reminder), recorded
			// only after a successful send; a failed send gives the claim back and is retried on the next run.
			$claim = 'expiry-reminder:' . $voucher->id;
			if ( ! Claim::take( $claim ) ) {
				continue;
			}
			try {
				$mailed = $email->trigger( $voucher );
			} catch ( \Throwable $e ) {
				// One broken voucher (PDF, template, mail plugin) neither keeps its claim nor stops the others.
				Claim::release( $claim );
				Logger::error( 'Expiry reminder failed: ' . Health::mask_addresses( $e->getMessage() ), array( 'voucher_id' => $voucher->id ) );
				continue;
			}
			if ( $mailed ) {
				$this->repo->log( $voucher->id, 'expiry_reminder', null, $voucher->balance, '' );
				++$sent;
			} else {
				Claim::release( $claim );
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, __( 'Expiry reminder could not be sent; it is retried on the next run.', 'voucher-captain' ) );
			}
		}
		return $sent;
	}

	/**
	 * Daily maintenance ("vcap_daily_maintenance"): queues one Action Scheduler action per step
	 * (MAINTENANCE_STEPS), so a step that fails or runs out of time never keeps the others from running. Without
	 * Action Scheduler the steps run here, each one isolated.
	 */
	public function maintenance(): void {
		if ( function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
			foreach ( self::MAINTENANCE_STEPS as $step ) {
				$args = array( 'step' => $step );
				if ( ! as_has_scheduled_action( self::STEP_HOOK, $args, self::GROUP ) ) {
					as_enqueue_async_action( self::STEP_HOOK, $args, self::GROUP );
				}
			}
			return;
		}
		foreach ( self::MAINTENANCE_STEPS as $step ) {
			$this->run_maintenance_step( $step );
		}
	}

	/**
	 * Runs one maintenance step. Errors are caught, written to the WooCommerce log and remembered as the step's last
	 * run (shown in Site Health → Info); a step that ran out of time continues in a new action.
	 *
	 * @param string|array $step Step name (or the action's args array).
	 * @return bool Whether the step ran without an error.
	 */
	public function run_maintenance_step( $step ): bool {
		$step = is_array( $step ) ? (string) ( $step['step'] ?? '' ) : (string) $step;
		if ( ! in_array( $step, self::MAINTENANCE_STEPS, true ) ) {
			return false;
		}
		$start = microtime( true );
		try {
			$finished = $this->maintenance_task( $step );
		} catch ( \Throwable $e ) {
			Logger::error( sprintf( 'Daily maintenance step "%s" failed: %s', $step, Health::mask_addresses( $e->getMessage() ) ), array( 'step' => $step ) );
			self::record_run( $step, false, $e->getMessage(), microtime( true ) - $start );
			return false;
		}
		self::record_run( $step, true, $finished ? '' : 'continued', microtime( true ) - $start );
		if ( ! $finished && function_exists( 'as_enqueue_async_action' ) ) {
			// Not done within the time budget: the rest follows in a new action (no fixed daily limit).
			as_enqueue_async_action( self::STEP_HOOK, array( 'step' => $step ), self::GROUP );
		}
		return true;
	}

	/** Seconds one maintenance step may work before it stops (filter "vcap_maintenance_time_budget"). */
	public static function time_budget( string $step ): float {
		return max( 1.0, (float) apply_filters( 'vcap_maintenance_time_budget', 20.0, $step ) );
	}

	/** @return bool false when the step stopped at its time budget and must continue. */
	private function maintenance_task( string $step ): bool {
		$budget = self::time_budget( $step );
		switch ( $step ) {
			case 'tmp_pdfs':
				// Temporary PDFs contain voucher codes in plain text.
				foreach ( glob( Install::upload_dir() . '/tmp/*.pdf' ) ?: array() as $file ) {
					if ( filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
						wp_delete_file( $file );
					}
				}
				return true;
			case 'rate_limits':
				return Plugin::purge_rate_limits( $budget );
			case 'mail_failures':
				Health::prune_mail_failures();
				return true;
			case 'scan_keys':
				return Tickets::purge_scan_keys( $budget );
			case 'claims':
				Claim::purge( $budget );
				return true;
			case 'upload_dir':
				Install::ensure_upload_dir();
				return true;
			case 'expire_overdue':
				$this->repo->expire_overdue();
				return true;
			case 'retention':
				$days = (int) $this->settings->get( 'vcap_retention_days' );
				if ( $days > 0 ) {
					$this->repo->anonymise_older_than( $days );
				}
				return true;
			case 'legacy_rows':
				Install::move_legacy_rows( $budget );
				return true;
			case 'delivery_net':
				return $this->deliver_due( $budget );
			case 'expiry_reminders':
				$this->send_expiry_reminders();
				return true;
		}
		return true;
	}

	/**
	 * Safety net for deliveries whose action got lost (or never ran: database restored, plugin was off): due,
	 * undelivered vouchers of the last 30 days that have no pending delivery or retry and were not given up,
	 * those never attempted first, then the longest-waiting attempt first. Works in batches until $budget
	 * seconds are used; every voucher is isolated (an error is logged with its id and the next one follows).
	 *
	 * @return bool true when every candidate was handled, false when the time budget ran out first.
	 */
	public function deliver_due( float $budget ): bool {
		$start = microtime( true );
		$seen  = array();
		while ( true ) {
			$ids = $this->repo->due_for_delivery( 50, $seen );
			if ( ! $ids ) {
				return true;
			}
			foreach ( $ids as $id ) {
				$seen[] = $id;
				if ( microtime( true ) - $start >= $budget ) {
					return false;
				}
				if ( $this->has_pending_delivery( $id ) ) {
					continue;
				}
				try {
					$this->deliver( $id );
				} catch ( \Throwable $e ) {
					// deliver() logged the error and planned a retry; go on with the next voucher.
					Logger::error( 'Delivery safety net: voucher skipped after an error, continuing.', array( 'voucher_id' => $id ) );
				}
			}
		}
	}

	/** Is a delivery or a retry of this voucher waiting in Action Scheduler? */
	private function has_pending_delivery( int $voucher_id ): bool {
		if ( ! function_exists( 'as_has_scheduled_action' ) ) {
			return false;
		}
		$args = array( 'voucher_id' => $voucher_id );
		return as_has_scheduled_action( 'vcap_deliver_voucher', $args, self::GROUP ) || as_has_scheduled_action( self::RETRY_HOOK, $args, self::GROUP );
	}

	private static function record_run( string $step, bool $ok, string $note, float $seconds ): void {
		update_option(
			self::RUN_OPTION_PREFIX . $step,
			array(
				'time'    => time(),
				'ok'      => $ok,
				'note'    => mb_substr( Health::mask_addresses( wp_strip_all_tags( $note ) ), 0, 200 ),
				'seconds' => round( $seconds, 2 ),
			),
			false
		);
	}

	/**
	 * Last run of each maintenance step (for the diagnostics).
	 *
	 * @return array<string, array{time:int, ok:bool, note:string, seconds:float}|null>
	 */
	public static function last_runs(): array {
		$out = array();
		foreach ( self::MAINTENANCE_STEPS as $step ) {
			$run          = get_option( self::RUN_OPTION_PREFIX . $step, null );
			$out[ $step ] = is_array( $run ) ? $run + array( 'time' => 0, 'ok' => false, 'note' => '', 'seconds' => 0.0 ) : null;
		}
		return $out;
	}
}
