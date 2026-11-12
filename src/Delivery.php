<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Delivery scheduling (immediate, relative delay, fixed date, customer-chosen date) via Action Scheduler.
 */
final class Delivery {
	public const GROUP = 'vcap';

	public function __construct( private Settings $settings, private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'vcap_deliver_voucher', array( $this, 'deliver' ), 10, 1 );
		add_action( 'vcap_retry_expiry_reminder', array( $this, 'retry_expiry_reminder' ), 10, 3 );
		add_action( 'vcap_daily_maintenance', array( $this, 'maintenance' ) );
		add_action( 'init', array( $this, 'schedule_maintenance' ), 20 );
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
	 * UTC datetime for delivery, or null for immediate.
	 */
	public static function compute_time( array $config, array $fields, ?\WC_Order $order = null ): ?string {
		$tz   = wp_timezone();
		$hour = (int) apply_filters( 'vcap_delivery_hour', 8 );
		$ts   = null;
		switch ( $config['delivery'] ) {
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
		$voucher->delivery_at  = $delivery_at_utc;
		$voucher->delivered_at = null;
		if ( $delivery_at_utc && strtotime( $delivery_at_utc . ' UTC' ) > time() && Voucher::STATUS_ACTIVE === $voucher->status ) {
			$voucher->status = Voucher::STATUS_SCHEDULED;
			vcap()->coupons->set_coupon_enabled( $voucher, false );
		}
		$this->repo->update( $voucher );
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
				$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active', updated_at = %s WHERE id = %d AND status = 'scheduled'", $now, $voucher_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				vcap()->coupons->set_coupon_enabled( $voucher, true );
				$voucher->status = Voucher::STATUS_ACTIVE;
			}

			$sent = $this->send( $voucher );
			if ( ! $sent ) {
				// Retried by the daily maintenance until 30 days after the due date (VoucherRepository::due_for_delivery()).
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, __( 'No email was sent (email disabled or no valid address).', 'voucher-captain' ) );
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
			$this->repo->log( $voucher->id, 'delivered', null, null, sprintf( 'Sent to %d recipient(s)', count( $sent ) ) );
			do_action( 'vcap_voucher_delivered', $voucher, $sent );
		} catch ( \Throwable $e ) {
			if ( isset( $voucher ) && $voucher instanceof Voucher ) {
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, $e->getMessage() );
			}
			throw $e;
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Send the voucher emails according to the voucher's routing.
	 *
	 * @return string[] addresses mailed
	 */
	public function send( Voucher $voucher, ?string $only = null ): array {
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$sent   = array();
		$config = ProductConfig::get( $voucher->variation_id ?: $voucher->product_id );

		$to_recipient = in_array( $voucher->send_to, array( 'recipient', 'both' ), true ) && is_email( $voucher->recipient_email );
		$to_buyer     = ! $to_recipient || 'both' === $voucher->send_to || 'yes' === $config['buyer_copy'];

		if ( $to_recipient && ( null === $only || 'recipient' === $only ) && isset( $emails['VCAP_Email_Voucher_Recipient'] ) ) {
			if ( $emails['VCAP_Email_Voucher_Recipient']->trigger( $voucher ) ) {
				$sent[] = $voucher->recipient_email;
			}
		}
		if ( $to_buyer && ( null === $only || 'buyer' === $only ) && isset( $emails['VCAP_Email_Voucher_Buyer'] ) ) {
			if ( $emails['VCAP_Email_Voucher_Buyer']->trigger( $voucher ) ) {
				$sent[] = $voucher->buyer_email;
			}
		}
		return $sent;
	}

	public function schedule_maintenance(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'vcap_daily_maintenance', array(), self::GROUP ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:15' ), DAY_IN_SECONDS, 'vcap_daily_maintenance', array(), self::GROUP );
		}
	}

	/**
	 * Parse the configured reminder thresholds. Numeric settings from older versions remain valid.
	 *
	 * @param mixed $value Stored setting.
	 * @return int[] Unique reminder thresholds between 1 and 365 days.
	 */
	public static function expiry_reminder_offsets( $value ): array {
		$offsets = array();
		$parts   = preg_split( '/[\\s,;]+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $parts ?: array() as $part ) {
			if ( ! ctype_digit( $part ) ) {
				continue;
			}
			$days = (int) $part;
			if ( $days >= 1 && $days <= 365 ) {
				$offsets[] = $days;
			}
		}
		$offsets = array_values( array_unique( $offsets ) );
		rsort( $offsets, SORT_NUMERIC );
		return $offsets;
	}

	/**
	 * Remind holders of vouchers with remaining value before expiry. Each configured threshold is sent at most once;
	 * admins and connector filters can suppress reminders per voucher.
	 *
	 * @return int number of reminders sent
	 */
	public function send_expiry_reminders(): int {
		$offsets = self::expiry_reminder_offsets( $this->settings->get( 'vcap_expiry_reminder_days' ) );
		if ( ! $offsets ) {
			return 0;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['VCAP_Email_Voucher_Expiry'] ?? null;
		if ( ! $email ) {
			return 0;
		}
		$sent = 0;
		foreach ( $offsets as $days ) {
			foreach ( $this->repo->due_for_expiry_reminder( $days ) as $voucher ) {
				if ( $this->send_expiry_reminder_attempt( $voucher, $days, 0 ) ) {
					++$sent;
				}
			}
		}
		return $sent;
	}

	/** Retry a failed expiry reminder from Action Scheduler. */
	public function retry_expiry_reminder( $voucher_id, $days, $attempt ): void {
		$voucher_id = (int) $voucher_id;
		$days       = (int) $days;
		$attempt    = (int) $attempt;
		$voucher    = $this->repo->get( $voucher_id );
		if ( ! $voucher || $attempt < 1 || $attempt > 3 ) {
			return;
		}
		$this->send_expiry_reminder_attempt( $voucher, $days, $attempt );
	}

	/** Send one reminder with a voucher-and-threshold lock, retrying failed mail up to three times. */
	private function send_expiry_reminder_attempt( Voucher $voucher, int $days, int $attempt ): bool {
		global $wpdb;
		if ( ! in_array( $days, self::expiry_reminder_offsets( $this->settings->get( 'vcap_expiry_reminder_days' ) ), true ) ) {
			return false;
		}
		$lock_name = 'vcap_expiry_' . $voucher->id . '_' . $days;
		$locked    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $locked ) {
			return false;
		}
		$event_note = sprintf( 'days_before=%d', $days );
		$logged     = false;
		try {
			$fresh = $this->repo->get( $voucher->id );
			if (
				! $fresh || Voucher::STATUS_ACTIVE !== $fresh->status || ! $fresh->delivered_at || ! $fresh->expires_at ||
				strtotime( $fresh->expires_at . ' UTC' ) <= time() || 'ticket' === $fresh->type ||
				( 'balance' === $fresh->usage_mode && $fresh->balance <= 0 )
			) {
				return false;
			}
			foreach ( $this->repo->events( $fresh->id ) as $event ) {
				$note = (string) ( $event['note'] ?? '' );
				if ( 'expiry_reminder' === ( $event['event'] ?? '' ) && ( $event_note === $note || ( '' !== $note && ! str_starts_with( $note, 'days_before=' ) ) ) ) {
					return false;
				}
			}
			if ( ! apply_filters( 'vcap_send_expiry_reminder', true, $fresh, $days ) || ! $this->repo->log( $fresh->id, 'expiry_reminder', null, $fresh->balance, $event_note ) ) {
				return false;
			}
			$logged = true;
			$emails = WC()->mailer()->get_emails();
			$email  = $emails['VCAP_Email_Voucher_Expiry'] ?? null;
			if ( $email && $email->trigger( $fresh ) ) {
				return true;
			}
			$failed = $this->repo->mark_last_expiry_reminder_failed( $fresh->id, $days );
			$logged = false;
			if ( $failed ) {
				$this->schedule_expiry_reminder_retry( $fresh->id, $days, $attempt );
			}
		} catch ( \Throwable $e ) {
			if ( $logged && $this->repo->mark_last_expiry_reminder_failed( $voucher->id, $days ) ) {
				$this->schedule_expiry_reminder_retry( $voucher->id, $days, $attempt );
			}
			wc_get_logger()->error( 'Voucher expiry reminder failed: ' . $e->getMessage(), array( 'source' => 'voucher-captain' ) );
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		return false;
	}

	private function schedule_expiry_reminder_retry( int $voucher_id, int $days, int $attempt ): void {
		if ( $attempt >= 3 || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		as_schedule_single_action( time() + HOUR_IN_SECONDS, 'vcap_retry_expiry_reminder', array( $voucher_id, $days, $attempt + 1 ), self::GROUP, true );
	}

	public function maintenance(): void {
		$this->repo->expire_overdue();
		$this->send_expiry_reminders();
		// Safety net for deliveries whose scheduled action got lost.
		foreach ( $this->repo->due_for_delivery() as $id ) {
			$this->deliver( $id );
		}
		$days = (int) $this->settings->get( 'vcap_retention_days' );
		if ( $days > 0 ) {
			$this->repo->anonymise_older_than( $days );
		}
		Install::ensure_upload_dir();
		foreach ( glob( Install::upload_dir() . '/tmp/*.pdf' ) ?: array() as $file ) {
			if ( filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
