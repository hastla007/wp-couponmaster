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
		$from_status           = $voucher->status;
		$voucher->delivery_at  = $delivery_at_utc;
		$voucher->delivered_at = null;
		if ( $delivery_at_utc && strtotime( $delivery_at_utc . ' UTC' ) > time() && Voucher::STATUS_ACTIVE === $voucher->status ) {
			$voucher->status = Voucher::STATUS_SCHEDULED;
		}
		$this->repo->update_columns( $voucher, array( 'delivery_at', 'delivered_at' ), $from_status );
		if ( Voucher::STATUS_SCHEDULED === $voucher->status && Voucher::STATUS_SCHEDULED !== $from_status ) {
			// Only once the stored row really is scheduled (a concurrent redemption wins).
			vcap()->coupons->set_coupon_enabled( $voucher, false );
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

			// A retry after a partial failure only mails who is still missing (no duplicate for the others).
			$pending_key = 'vcap_delivery_missing_' . $voucher->id;
			$pending     = get_transient( $pending_key );
			// (array) false would be array( false ): no transient means "send to everyone".
			$pending     = is_array( $pending ) ? array_values( array_intersect( $pending, array( 'recipient', 'buyer' ) ) ) : array();
			$only        = 1 === count( $pending ) ? (string) reset( $pending ) : null;
			$required    = $this->required_roles( $voucher );
			$sent_roles  = array();
			$sent        = $this->send( $voucher, $only, $sent_roles );
			if ( ! $sent ) {
				// Retried by the daily maintenance until 30 days after the due date (VoucherRepository::due_for_delivery()).
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, __( 'No email was sent (email disabled or no valid address).', 'voucher-captain' ) );
				return;
			}
			$missing = array_values( array_diff( null === $only ? $required : array( $only ), $sent_roles ) );
			if ( $missing ) {
				// "Send to buyer and recipient": delivered only when both got it; the rest is retried later.
				set_transient( $pending_key, $missing, 31 * DAY_IN_SECONDS );
				/* translators: %s: buyer/recipient */
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, sprintf( __( 'Not delivered to: %s (will be retried).', 'voucher-captain' ), implode( ', ', $missing ) ) );
				return;
			}
			delete_transient( $pending_key );

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
			$this->repo->log( $voucher->id, 'delivered', null, null, sprintf( /* translators: %d: number of addresses */ _n( 'Sent to %d address', 'Sent to %d addresses', count( $sent ), 'voucher-captain' ), count( $sent ) ) );
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
	/**
	 * Who must receive the voucher for it to count as delivered ('recipient', 'buyer'); a buyer copy next to a
	 * gift for the recipient is a courtesy and not required.
	 *
	 * @return string[]
	 */
	public function required_roles( Voucher $voucher ): array {
		$to_recipient = in_array( $voucher->send_to, array( 'recipient', 'both' ), true ) && is_email( $voucher->recipient_email );
		$roles        = $to_recipient ? array( 'recipient' ) : array();
		if ( ! $to_recipient || 'both' === $voucher->send_to ) {
			$roles[] = 'buyer';
		}
		return $roles;
	}

	/**
	 * @param string[] $sent_roles Filled with the roles that were mailed ('recipient', 'buyer').
	 */
	public function send( Voucher $voucher, ?string $only = null, array &$sent_roles = array() ): array {
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$sent   = array();
		$config = ProductConfig::get( $voucher->variation_id ?: $voucher->product_id );

		$to_recipient = in_array( $voucher->send_to, array( 'recipient', 'both' ), true ) && is_email( $voucher->recipient_email );
		$to_buyer     = ! $to_recipient || 'both' === $voucher->send_to || 'yes' === $config['buyer_copy'];

		if ( $to_recipient && ( null === $only || 'recipient' === $only ) && isset( $emails['VCAP_Email_Voucher_Recipient'] ) ) {
			if ( $emails['VCAP_Email_Voucher_Recipient']->trigger( $voucher ) ) {
				$sent[]       = $voucher->recipient_email;
				$sent_roles[] = 'recipient';
			}
		}
		if ( $to_buyer && ( null === $only || 'buyer' === $only ) && isset( $emails['VCAP_Email_Voucher_Buyer'] ) ) {
			if ( $emails['VCAP_Email_Voucher_Buyer']->trigger( $voucher ) ) {
				$sent[]       = $voucher->buyer_email;
				$sent_roles[] = 'buyer';
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
			if ( $email->trigger( $voucher ) ) {
				$this->repo->log( $voucher->id, 'expiry_reminder', null, $voucher->balance, '' );
				++$sent;
			} else {
				Claim::release( $claim );
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, __( 'Expiry reminder could not be sent; it is retried on the next run.', 'voucher-captain' ) );
			}
		}
		return $sent;
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
		Plugin::purge_rate_limits();
		Tickets::purge_scan_keys();
		foreach ( glob( Install::upload_dir() . '/tmp/*.pdf' ) ?: array() as $file ) {
			if ( filemtime( $file ) < time() - HOUR_IN_SECONDS ) {
				wp_delete_file( $file );
			}
		}
	}
}
