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
	public const MAINTENANCE_STEPS = array( 'tmp_pdfs', 'rate_limits', 'mail_failures', 'scan_keys', 'claims', 'upload_dir', 'expire_overdue', 'retention', 'voucher_requests', 'review_refs', 'protocols', 'settings_log', 'legacy_rows', 'delivery_net', 'expiry_reminders' );

	public function __construct( private Settings $settings, private VoucherRepository $repo ) {}

	/** UTC time from which vouchers carry their routing (buyer copy) themselves (U12); older ones read the product. */
	public const ROUTING_SINCE_OPTION = 'vcap_routing_fixed_since';

	public function register(): void {
		if ( false === get_option( self::ROUTING_SINCE_OPTION ) ) {
			Settings::insert_once( self::ROUTING_SINCE_OPTION, gmdate( 'Y-m-d H:i:s' ), true ); // M12-17: never overwritten.
		}
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
		$customer = (string) ( $fields['delivery_date']['value'] ?? '' );
		$mode     = '' !== $customer && self::customer_date_enabled( $config ) ? 'customer' : (string) $config['delivery'];
		$ts       = self::mode_timestamp( $mode, $config, $customer, $order, $hour, $tz );
		if ( 'customer' === $mode && in_array( (string) $config['delivery'], array( 'date', 'delay' ), true ) ) {
			// M12-16: a date the customer chose never brings a presale date or a waiting period of the shop forward.
			$shop = self::mode_timestamp( (string) $config['delivery'], $config, '', $order, $hour, $tz );
			if ( $shop && ( ! $ts || $ts < $shop ) ) {
				$ts = $shop;
			}
		}
		$ts = apply_filters( 'vcap_delivery_timestamp', $ts, $config, $fields, $order );
		return $ts && $ts > time() ? gmdate( 'Y-m-d H:i:s', (int) $ts ) : null;
	}

	/** Delivery time of one mode ("delay", "date", "customer" with the customer's local date), null for none. */
	private static function mode_timestamp( string $mode, array $config, string $customer, ?\WC_Order $order, int $hour, \DateTimeZone $tz ): ?int {
		switch ( $mode ) {
			case 'delay':
				$days = (int) $config['delivery_delay_days'];
				if ( $days > 0 ) {
					$base = $order && $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
					return $base + $days * DAY_IN_SECONDS;
				}
				return null;
			case 'date':
				return $config['delivery_date'] ? self::local_date_ts( (string) $config['delivery_date'], $hour, $tz ) : null;
			case 'customer':
				return '' !== $customer ? self::local_date_ts( $customer, $hour, $tz ) : null;
		}
		return null;
	}

	/**
	 * M12-16: earliest local date ("Y-m-d") a customer may choose as delivery date – on the product page and when
	 * changing it in My Account: today, and in the modes "date"/"delay" never before the shop's own delivery date
	 * (presale, waiting period), see compute_time().
	 */
	public static function earliest_customer_date( array $config, ?\WC_Order $order = null ): string {
		$today = wp_date( 'Y-m-d' );
		if ( in_array( (string) ( $config['delivery'] ?? '' ), array( 'date', 'delay' ), true ) ) {
			$shop = self::mode_timestamp( (string) $config['delivery'], $config, '', $order, self::delivery_hour(), wp_timezone() );
			$day  = $shop ? (string) wp_date( 'Y-m-d', $shop ) : '';
			if ( $day > $today ) {
				return $day;
			}
		}
		return $today;
	}

	/** Order meta: V7 hold of a new customer's vouchers – Unix time until which they are held, 0 for none. */
	public const HOLD_META = '_vcap_delivery_hold';

	/**
	 * V7: until when the vouchers of an order are held (null = not held). A first order (no earlier order of the
	 * account or email that is at least the hold period old, see VoucherPurchaseRules::is_new_customer()) whose
	 * vouchers – M12-23: together with the customer's other orders within the hold window, window_value() – are
	 * worth more than "vcap_hold_new_customer_above" (default 250, 0 = off; or that comes too fast from one
	 * address, too_fast()) is held for
	 * "vcap_hold_hours" (default 24, 0 = off) from the payment: the vouchers are issued, but delivered and
	 * redeemable only then (a scheduled delivery). With $persist the decision is stored once on the order (the
	 * first issuing run decides; later orders of the customer do not change it).
	 */
	public static function hold_until( \WC_Order $order, bool $persist = false ): ?int {
		$stored = $order->get_meta( self::HOLD_META );
		if ( '' !== (string) $stored ) {
			return (int) $stored > 0 ? (int) $stored : null;
		}
		$above = (float) Settings::value( 'vcap_hold_new_customer_above' );
		$hours = (float) Settings::value( 'vcap_hold_hours' );
		$until  = null;
		$window = (int) round( $hours * HOUR_IN_SECONDS );
		if ( $above > 0 && $hours > 0 && VoucherPurchaseRules::is_new_customer( $order, $window ) && ( VoucherPurchaseRules::voucher_value( $order ) + self::window_value( $order, $window ) > $above + 0.00001 || ( $persist && self::too_fast( $order, $window ) ) ) ) {
			$base  = $order->get_date_paid() ? $order->get_date_paid()->getTimestamp() : time();
			$until = $base + $window;
		}
		/** Until when (Unix time, null = not held) the vouchers of a new customer's order are held. */
		$until = apply_filters( 'vcap_delivery_hold_until', $until, $order );
		$until = $until && (int) $until > time() ? (int) $until : null;
		if ( $persist ) {
			$order->update_meta_data( self::HOLD_META, (string) (int) $until );
			$order->save_meta_data();
			if ( $until ) {
				$order->add_order_note(
					sprintf(
						/* translators: 1: date and time, 2: amount */
						__( 'Security hold: the vouchers of this first order (above %2$s) are delivered and redeemable from %1$s. Use "Deliver now" on a voucher to release it earlier, or void it.', 'voucher-captain' ),
						LocalDate::from_utc( gmdate( 'Y-m-d H:i:s', $until ), vcap()->settings->date_format() . ' H:i' ),
						wp_strip_all_tags( wc_price( $above, array( 'currency' => $order->get_currency() ) ) )
					)
				);
			}
		}
		return $until;
	}

	/**
	 * M12-23: voucher value of the same customer's other counted orders (account, billing email or its normalised
	 * key) placed within the hold window before this one or since – four orders of 250 are held like one of 1000.
	 * Refunded shares no longer count.
	 */
	private static function window_value( \WC_Order $order, int $window ): float {
		$created = $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time();
		$value   = 0.0;
		foreach ( VoucherPurchaseRules::customer_orders( (int) $order->get_customer_id(), (string) $order->get_billing_email(), $created - $window, 0, $order->get_id() ) as $id ) {
			$other = wc_get_order( $id );
			if ( $other instanceof \WC_Order && ! $other instanceof \WC_Order_Refund ) {
				$total  = (float) $other->get_total();
				$share  = $total > 0 ? max( 0.0, 1 - (float) $other->get_total_refunded() / $total ) : 1.0;
				$value += VoucherPurchaseRules::voucher_value( $other ) * $share;
			}
		}
		return $value;
	}

	/**
	 * M12-23: optional velocity limit per address (new email addresses defeat the per-customer sum): more voucher
	 * orders of new customers from one address (IPv6 /64) within the hold window than the filter allows are held.
	 * Counted once per order, when the hold is decided.
	 */
	private static function too_fast( \WC_Order $order, int $window ): bool {
		/**
		 * Maximum voucher orders of new customers per address (IPv6 /64) within the hold window before further
		 * ones are held (default 0 = no velocity limit; rate limit bucket "voucher_buy").
		 *
		 * @param int      $max   Orders per address.
		 * @param WC_Order $order Order being decided.
		 */
		$max = (int) apply_filters( 'vcap_hold_velocity', 0, $order );
		$ip  = (string) $order->get_customer_ip_address();
		if ( $max <= 0 || '' === $ip || VoucherPurchaseRules::voucher_value( $order ) <= 0 ) {
			return false;
		}
		return Plugin::rate_limited( 'voucher_buy', $max, 0, max( HOUR_IN_SECONDS, $window ), true, Plugin::client_network( $ip ) );
	}

	/** Delivery time (UTC) of a voucher of the order: its own time, but never before the order's hold ends. */
	public static function with_hold( ?string $delivery_at, \WC_Order $order, bool $persist = false ): ?string {
		$hold = self::hold_until( $order, $persist );
		if ( ! $hold || ( $delivery_at && strtotime( $delivery_at . ' UTC' ) >= $hold ) ) {
			return $delivery_at;
		}
		return gmdate( 'Y-m-d H:i:s', $hold );
	}

	/** Whether a voucher is still held for its order's security check (scheduled, hold not over). */
	public static function is_held( Voucher $voucher ): bool {
		if ( Voucher::STATUS_SCHEDULED !== $voucher->status || ! $voucher->order_id ) {
			return false;
		}
		$order = wc_get_order( $voucher->order_id );
		return $order instanceof \WC_Order && (int) $order->get_meta( self::HOLD_META ) > time();
	}

	private static function local_date_ts( string $ymd, int $hour, \DateTimeZone $tz ): ?int {
		$dt = \DateTimeImmutable::createFromFormat( '!Y-m-d', $ymd, $tz );
		return $dt ? $dt->setTime( $hour, 0 )->getTimestamp() : null;
	}

	/** Local hour at which dated deliveries go out (setting "vcap_delivery_hour", default 8; filter "vcap_delivery_hour"). */
	public static function delivery_hour(): int {
		return max( 0, min( 23, (int) apply_filters( 'vcap_delivery_hour', (int) Settings::value( 'vcap_delivery_hour' ) ) ) );
	}

	/** Does the product page ask for a delivery date (mode "customer", or the built-in field switched on)? */
	public static function customer_date_enabled( array $config ): bool {
		$builtin = array_map( 'strval', (array) ( $config['builtin_fields'] ?? array() ) );
		return 'customer' === ( $config['delivery'] ?? '' ) || (bool) array_intersect( array( 'delivery_date', 'delivery_date_required' ), $builtin );
	}

	/**
	 * Last local date ("Y-m-d") on which a voucher of this product may still be delivered: a voucher bought at $from
	 * gets the expiry ProductConfig::expiry_for() gives it (from the payment, the same rule as Issuer), and must
	 * reach its recipient at least "vcap_delivery_min_validity_days" days (default 0) before it ends. Null when the
	 * voucher never expires.
	 */
	public static function latest_delivery_date( array $config, ?int $from = null, string $country = '' ): ?string {
		$expiry = ProductConfig::expiry_for( $config, $from ?? time(), $country );
		return $expiry ? self::latest_date_before( $expiry, $config ) : null;
	}

	/**
	 * Last local date ("Y-m-d") on which a voucher that ends at $expiry (UTC) may still be delivered, at least
	 * "vcap_delivery_min_validity_days" days before its end (M12-16: the same rule for a voucher already issued).
	 */
	public static function latest_date_before( string $expiry, array $config ): string {
		$tz      = wp_timezone();
		$min     = max( 0, (int) apply_filters( 'vcap_delivery_min_validity_days', 0, $config ) );
		$limit   = (int) strtotime( $expiry . ' UTC' ) - $min * DAY_IN_SECONDS;
		$day     = ( new \DateTimeImmutable( '@' . $limit ) )->setTimezone( $tz );
		$ymd     = $day->format( 'Y-m-d' );
		$deliver = self::local_date_ts( $ymd, self::delivery_hour(), $tz );
		if ( null !== $deliver && $deliver > $limit ) {
			// The voucher ends on that day before the delivery hour: the day before is the last one.
			$ymd = $day->modify( '-1 day' )->format( 'Y-m-d' );
		}
		return $ymd;
	}

	/**
	 * Delivery after expiry for products with a delivery delay or a fixed delivery date: the local delivery date,
	 * the expiry date ("Y-m-d" both) of a voucher bought at $from, or null when the delivery is in time.
	 *
	 * @return array{delivery:string, expiry:string}|null
	 */
	public static function delivery_after_expiry( array $config, ?int $from = null ): ?array {
		$from = $from ?? time();
		$tz   = wp_timezone();
		switch ( $config['delivery'] ?? '' ) {
			case 'delay':
				$days = (int) $config['delivery_delay_days'];
				$ts   = $days > 0 ? $from + $days * DAY_IN_SECONDS : null;
				break;
			case 'date':
				$ts = $config['delivery_date'] ? self::local_date_ts( (string) $config['delivery_date'], self::delivery_hour(), $tz ) : null;
				break;
			default:
				$ts = null;
		}
		$expiry = $ts ? ProductConfig::expiry_for( $config, $from ) : null;
		if ( ! $expiry || $ts <= (int) strtotime( $expiry . ' UTC' ) ) {
			return null;
		}
		return array(
			'delivery' => wp_date( 'Y-m-d', (int) $ts, $tz ),
			'expiry'   => wp_date( 'Y-m-d', (int) strtotime( $expiry . ' UTC' ), $tz ),
		);
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

	/**
	 * After an admin sent the voucher by hand (H7): the role counts as delivered, and once every required role got
	 * it the voucher is marked delivered – the daily safety net does not send it a second time.
	 */
	public function record_manual_send( Voucher $voucher, array $roles ): void {
		if ( $voucher->delivered_at || ! in_array( $voucher->status, array( Voucher::STATUS_ACTIVE ), true ) ) {
			return;
		}
		$delivered = array_values( array_unique( array_merge( $this->delivered_roles( $voucher ), array_intersect( $roles, array( 'recipient', 'buyer' ) ) ) ) );
		$missing   = array_values( array_diff( $this->required_roles( $voucher ), $delivered ) );
		$this->store_delivered_roles( $voucher->id, $delivered, $missing );
		if ( $missing ) {
			return;
		}
		global $wpdb;
		$now     = $this->repo->now();
		$table   = Install::tables()['vouchers'];
		$updated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET delivered_at = %s, updated_at = %s WHERE id = %d AND delivered_at IS NULL AND status = 'active'", $table, $now, $now, $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === (int) $updated ) {
			$voucher->delivered_at = $now;
			delete_transient( 'vcap_undelivered_count' );
			delete_transient( 'vcap_given_up_count' );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( self::RETRY_HOOK, array( 'voucher_id' => $voucher->id ), self::GROUP );
			}
		}
	}

	/**
	 * Brings a scheduled delivery forward to now on the admin's request (H7): the voucher is activated, delivered
	 * to everyone it is meant for and marked delivered, as the scheduled delivery would have done.
	 *
	 * @return bool Whether it is delivered now.
	 */
	public function deliver_now( Voucher $voucher ): bool {
		if ( Voucher::STATUS_SCHEDULED !== $voucher->status ) {
			return false;
		}
		$voucher->delivery_at = $this->repo->now();
		$this->repo->update_columns( $voucher, array( 'delivery_at' ) );
		$this->repo->log( $voucher->id, 'scheduled', null, null, get_date_from_gmt( $voucher->delivery_at ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => $voucher->id ), self::GROUP );
		}
		$this->forced = true;
		try {
			$this->deliver( $voucher->id, false );
		} finally {
			$this->forced = false;
		}
		$fresh = $this->repo->get( $voucher->id );
		return $fresh && null !== $fresh->delivered_at;
	}

	public function reschedule( Voucher $voucher, ?string $delivery_at_utc ): void {
		$from_status           = $voucher->status;
		// A voucher the holder already has stays redeemable when only its email is planned again (H7/Z2).
		$was_delivered         = null !== $voucher->delivered_at && '' !== (string) $voucher->delivered_at;
		$voucher->delivery_at  = $delivery_at_utc;
		$voucher->delivered_at = null;
		if ( $delivery_at_utc && strtotime( $delivery_at_utc . ' UTC' ) > time() && Voucher::STATUS_ACTIVE === $voucher->status && ! $was_delivered ) {
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
	 * @param bool      $bundle     Whether vouchers due together may go out in one bundled email (Z6); false
	 *                              delivers exactly this voucher (e.g. "Deliver now" on one voucher).
	 */
	public function deliver( $voucher_id, bool $bundle = true ): void {
		$voucher_id = is_array( $voucher_id ) ? (int) ( $voucher_id['voucher_id'] ?? 0 ) : (int) $voucher_id;
		if ( $voucher_id <= 0 ) {
			return;
		}

		// A connection-scoped lock is released automatically if a worker crashes.
		global $wpdb;
		// Z6: a voucher that may be part of a bundled email locks its order first (before its own lock), so the
		// worker that bundles the order can lock every voucher of it; a parallel worker steps back instead.
		$order_lock = $bundle ? $this->take_bundle_lock( $voucher_id ) : '';
		if ( null === $order_lock ) {
			$this->retry_soon( $voucher_id );
			return;
		}
		$lock_name = Install::lock_name( 'delivery_' . $voucher_id );
		$locked    = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $locked ) {
			// Another worker delivers this voucher right now. Should it stop before sending, a retry follows.
			$this->release_lock( $order_lock );
			$this->retry_soon( $voucher_id );
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
			if ( $voucher->expires_at && strtotime( $voucher->expires_at . ' UTC' ) < time() ) {
				// Never mail (or activate) a voucher that has already ended: the shop has to decide.
				$this->refuse_expired( $voucher );
				return;
			}

			if ( $this->wait_for_payment( $voucher ) ) {
				return; // M12-08: the order is not (or no longer) paid – nothing is activated or mailed.
			}

			// Z6: a large quantity of the same order line for the same recipient goes out as one bundled email.
			if ( '' !== $order_lock && $this->deliver_bundle( $voucher ) ) {
				return;
			}

			// The delivery date has passed: the gift becomes redeemable even if no email can be sent
			// (email disabled, invalid address). The download link and the admin "resend" still work.
			if ( ! $this->activate_due( $voucher, $now ) ) {
				// Voided or changed meanwhile (or a database error): do not enable its coupon, try again later.
				return;
			}

			// A retry after a partial failure only mails who is still missing (no duplicate for the others): who
			// already got it is stored with the voucher (delivered_roles), so a cache flush cannot cause a second mail.
			$required  = $this->required_roles( $voucher );
			$delivered = $this->delivered_roles( $voucher );
			$pending   = array_values( array_diff( $required, $delivered ) );
			$only      = $delivered && 1 === count( $pending ) ? (string) reset( $pending ) : null;
			$sent_roles  = array();
			$failures    = array();
			$without_pdf = array();
			$sent        = $delivered && ! $pending ? array() : $this->send( $voucher, $only, $sent_roles, $failures, $without_pdf );
			$this->record_attempt( $voucher, $required, $delivered, $sent, $sent_roles, $failures, $without_pdf, $now );
		} catch ( \Throwable $e ) {
			Logger::error( 'Voucher delivery failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_id' => $voucher_id ) );
			if ( isset( $voucher ) && $voucher instanceof Voucher ) {
				$this->repo->log( $voucher->id, 'delivery_failed', null, null, Note::make( 'delivery_error', array( Mask::addresses( mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 300 ) ) ) ) );
				try {
					$this->retry_later( $voucher );
				} catch ( \Throwable $retry_error ) {
					// The daily safety net picks the voucher up again.
					Logger::error( 'Voucher delivery retry could not be scheduled: ' . $retry_error->getMessage(), array( 'voucher_id' => $voucher_id ) );
				}
			}
			throw $e;
		} finally {
			$this->release_lock( $lock_name );
			$this->release_lock( $order_lock );
		}
	}

	/** Whether "Deliver now" runs (an administrator released the voucher: no payment hold, M12-08). */
	private bool $forced = false;

	/** Order meta: a held or scheduled voucher waits because its order is not paid (note written once, M12-08). */
	public const UNPAID_META = '_vcap_delivery_unpaid';

	/**
	 * M12-08 (V7): a held or scheduled voucher of an order is activated and mailed only while the order is paid – one
	 * whose order went on hold meanwhile (dispute, PayPal reversal), failed or is pending again waits: order
	 * note and notice (Attention) once, then another try in an hour (not counted as a failed delivery). Offline
	 * payment methods count as paid only once the payment was confirmed (Issuer::payment_ok()). Filter
	 * "vcap_issue_requires_payment" false turns the check off like the payment check before issuing; "Deliver now"
	 * (an administrator's decision) is not held.
	 *
	 * @return bool Whether the delivery waits.
	 */
	private function wait_for_payment( Voucher $voucher ): bool {
		if ( Voucher::STATUS_SCHEDULED !== $voucher->status || $this->forced ) {
			return false; // Active vouchers are redeemable already; only the activation of a held one is stopped.
		}
		$order = $voucher->order_id > 0 && function_exists( 'wc_get_order' ) ? wc_get_order( (int) $voucher->order_id ) : null;
		if ( ! $order instanceof \WC_Order || $order instanceof \WC_Order_Refund || ! apply_filters( 'vcap_issue_requires_payment', true, $order ) ) {
			return false;
		}
		if ( $order->is_paid() && ( ! Issuer::offline_payment( $order ) || Issuer::payment_ok( $order ) ) ) {
			if ( '' !== (string) $order->get_meta( self::UNPAID_META ) ) {
				$order->delete_meta_data( self::UNPAID_META );
				$order->save_meta_data();
				Attention::resolve( $order->get_id(), 'delivery-unpaid' );
			}
			return false;
		}
		if ( '' === (string) $order->get_meta( self::UNPAID_META ) ) {
			$order->update_meta_data( self::UNPAID_META, (string) time() );
			$order->save_meta_data();
			$text = sprintf(
				/* translators: %s: order status */
				__( 'Vouchers of this order are due but are not activated or sent: the order is not paid (status %s). They follow automatically once the order is paid again; void them if the payment does not come.', 'voucher-captain' ),
				wc_get_order_status_name( $order->get_status() )
			);
			$order->add_order_note( $text );
			Attention::add( $order->get_id(), 'delivery-unpaid', $text );
		}
		if ( function_exists( 'as_schedule_single_action' ) ) {
			$args = array( 'voucher_id' => $voucher->id );
			as_unschedule_all_actions( self::RETRY_HOOK, $args, self::GROUP );
			as_schedule_single_action( time() + HOUR_IN_SECONDS, self::RETRY_HOOK, $args, self::GROUP );
		}
		return true;
	}

	/** Another worker holds a lock this delivery needs: it is tried again in five minutes (one retry per voucher). */
	private function retry_soon( int $voucher_id ): void {
		if ( function_exists( 'as_schedule_single_action' ) && ! as_has_scheduled_action( self::RETRY_HOOK, array( 'voucher_id' => $voucher_id ), self::GROUP ) ) {
			as_schedule_single_action( time() + 5 * MINUTE_IN_SECONDS, self::RETRY_HOOK, array( 'voucher_id' => $voucher_id ), self::GROUP );
		}
	}

	private function release_lock( ?string $name ): void {
		if ( null === $name || '' === $name ) {
			return;
		}
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Activates a scheduled voucher whose delivery date has passed (status and coupon), so the gift is redeemable
	 * even if no email can be sent. False when the row changed meanwhile (voided, or a database error).
	 */
	private function activate_due( Voucher $voucher, string $now ): bool {
		if ( Voucher::STATUS_SCHEDULED !== $voucher->status ) {
			return true;
		}
		global $wpdb;
		$table     = Install::tables()['vouchers'];
		$activated = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'active', updated_at = %s WHERE id = %d AND status = 'scheduled'", $table, $now, $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $activated ) {
			return false;
		}
		vcap()->coupons->set_coupon_enabled( $voucher, true );
		$voucher->status = Voucher::STATUS_ACTIVE;
		return true;
	}

	/**
	 * Records one delivery attempt of a voucher: the roles that got it, the ledger entry ("delivered",
	 * "delivered_without_pdf", "delivery_failed"), a retry for who is still missing, the delivered state and the
	 * action "vcap_voucher_delivered". Shared by the single and the bundled delivery (Z6), so both keep the same
	 * per-voucher state and history.
	 *
	 * @param string[]              $required    Roles that must get it.
	 * @param string[]              $delivered   Roles that got it in earlier attempts.
	 * @param string[]              $sent        Addresses mailed now.
	 * @param string[]              $sent_roles  Roles mailed now.
	 * @param array<string, string> $failures    Reason per role not mailed now.
	 * @param string[]              $without_pdf Roles mailed now without the PDF they should carry.
	 * @param int                   $batch       Vouchers in the email (bundled delivery), 1 for its own email.
	 */
	private function record_attempt( Voucher $voucher, array $required, array $delivered, array $sent, array $sent_roles, array $failures, array $without_pdf, string $now, int $batch = 1 ): void {
		global $wpdb;
		$pending = array_values( array_diff( $required, $delivered ) );
		Logger::debug( 'Delivery attempt.', array( 'voucher_id' => $voucher->id, 'required' => $required, 'delivered_before' => $delivered, 'sent' => $sent_roles, 'failed' => $failures, 'without_pdf' => $without_pdf, 'bundle' => $batch ) );
		if ( $without_pdf ) {
			// D5: the mail went out, but without the PDF it should carry – said so instead of a plain "sent".
			$this->repo->log( $voucher->id, 'delivered_without_pdf', null, null, Note::make( 'delivered_without_pdf', array( implode( ',', $without_pdf ) ) ) );
		}
		if ( ! $sent && $pending ) {
			// Retried with growing pauses (retry_later()); the daily safety net catches retries that got lost.
			// The reason per role (D4): email disabled, no address and a mail system error are different tasks.
			$this->repo->log( $voucher->id, 'delivery_failed', null, null, Note::make( 'delivery_not_sent', array( (string) wp_json_encode( $failures ) ) ) );
			$this->retry_later( $voucher );
			return;
		}
		$delivered = array_values( array_unique( array_merge( $delivered, $sent_roles ) ) );
		$missing   = array_values( array_diff( $required, $delivered ) );
		$this->store_delivered_roles( $voucher->id, $delivered, $missing );
		if ( $missing ) {
			// "Send to buyer and recipient": delivered only when both got it; the rest is retried later.
			$this->repo->log( $voucher->id, 'delivery_failed', null, null, Note::make( 'delivery_partial', array( implode( ',', $missing ), (string) wp_json_encode( array_intersect_key( $failures, array_flip( $missing ) ) ) ) ) );
			$this->retry_later( $voucher );
			return;
		}

		$table   = Install::tables()['vouchers'];
		$updated = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->prepare(
				"UPDATE %i SET delivered_at = %s, status = CASE WHEN status = 'scheduled' THEN 'active' ELSE status END, updated_at = %s
				WHERE id = %d AND delivered_at IS NULL AND status IN ('scheduled','active')",
				$table,
				$now,
				$now,
				$voucher->id
			)
		);
		if ( 1 !== (int) $updated ) {
			throw new \RuntimeException( esc_html__( 'Voucher email was sent but its delivery state could not be saved.', 'voucher-captain' ) );
		}
		$voucher->delivered_at = $now;
		delete_transient( 'vcap_undelivered_count' );
		delete_transient( 'vcap_given_up_count' );
		$args = array( count( $sent ), implode( ',', $sent_roles ) );
		if ( $batch > 1 ) {
			$args[] = $batch;
		}
		$this->repo->log( $voucher->id, 'delivered', null, null, Note::make( 'delivered', $args ) );
		/** A voucher was delivered to everyone it is meant for (args: Voucher $voucher, string[] $addresses). */
		Db::action( 'vcap_voucher_delivered', $voucher, $sent );
	}

	/**
	 * Z6: number of due vouchers of the same order line for the same recipient from which they go out together in
	 * one email with a code table and one PDF (setting "vcap_bundle_threshold", default 5; filter
	 * "vcap_bundle_threshold" with the voucher; 0 = every voucher gets its own email). At least 2.
	 */
	public static function bundle_threshold( ?Voucher $voucher = null ): int {
		/** Vouchers of one order line and recipient from which they are bundled into one email (0 = never). */
		$threshold = (int) apply_filters( 'vcap_bundle_threshold', (int) Settings::value( 'vcap_bundle_threshold' ), $voucher );
		return $threshold > 0 ? max( 2, $threshold ) : 0;
	}

	/**
	 * Z6: a bundled email with more vouchers than this carries no PDF but a download link per voucher (and the
	 * link to My Account) instead (setting "vcap_bundle_pdf_limit", default 50, 20 on new installs; filter "vcap_bundle_pdf_limit"
	 * with the vouchers; 0 = never a PDF in a bundled email).
	 *
	 * @param Voucher[] $vouchers
	 */
	public static function bundle_pdf_limit( array $vouchers = array() ): int {
		/** Most vouchers a bundled email attaches the PDF for; above it, download links only (0 = never a PDF). */
		return max( 0, (int) apply_filters( 'vcap_bundle_pdf_limit', (int) Settings::value( 'vcap_bundle_pdf_limit' ), $vouchers ) );
	}

	/**
	 * M12-48: largest PDF attachment of a voucher email in bytes (filter "vcap_max_attachment_bytes", default 8 MB);
	 * a larger PDF is not attached – the email carries download links instead.
	 */
	public static function max_attachment_bytes( ?Voucher $voucher = null ): int {
		/** Largest PDF attachment (bytes) of a voucher email; above it download links are sent instead. */
		return max( 1, (int) apply_filters( 'vcap_max_attachment_bytes', 8 * MB_IN_BYTES, $voucher ) );
	}

	/** M12-48: ledger event written before a delivery renders and sends (a crash still counts as an attempt). */
	public const ATTEMPT_EVENT = 'delivery_attempt';

	/** Z6: vouchers with the same key may share one email: the same order line, routing and main address. */
	public static function bundle_key( Voucher $voucher ): string {
		$address = self::to_recipient( $voucher ) ? $voucher->recipient_email : $voucher->buyer_email;
		return $voucher->order_item_id . '|' . $voucher->send_to . '|' . strtolower( trim( $address ) );
	}

	/**
	 * Roles a first delivery attempt mails ('recipient', 'buyer'), the buyer's courtesy copy of a gift included
	 * (the same routing as send()).
	 *
	 * @return string[]
	 */
	private static function wanted_roles( Voucher $voucher ): array {
		$to_recipient = self::to_recipient( $voucher );
		$roles        = $to_recipient ? array( 'recipient' ) : array();
		if ( ! $to_recipient || 'both' === $voucher->send_to || self::legacy_buyer_copy( $voucher ) ) {
			$roles[] = 'buyer';
		}
		return $roles;
	}

	/** The bundled email when it is registered and switched on (else every voucher gets its own email). */
	private static function bundle_email(): ?Emails\BundleEmail {
		if ( ! function_exists( 'WC' ) ) {
			return null;
		}
		$email = WC()->mailer()->get_emails()['VCAP_Email_Voucher_Bundle'] ?? null;
		return $email instanceof Emails\BundleEmail && $email->is_enabled() ? $email : null;
	}

	/** Z6: whether a voucher (as read) is due for its delivery now and may join a bundle. */
	private static function bundle_candidate( Voucher $voucher ): bool {
		return $voucher->order_item_id > 0
			&& null === $voucher->delivered_at
			&& in_array( $voucher->status, array( Voucher::STATUS_SCHEDULED, Voucher::STATUS_ACTIVE ), true )
			&& ( ! $voucher->delivery_at || strtotime( $voucher->delivery_at . ' UTC' ) <= time() )
			&& ( ! $voucher->expires_at || strtotime( $voucher->expires_at . ' UTC' ) >= time() );
	}

	/**
	 * Z6: the order lock of a voucher that may go out in a bundled email (its order line has at least
	 * bundle_threshold() due, undelivered vouchers and the bundled email is switched on).
	 *
	 * @return string|null The lock held ('' = no bundle possible, nothing locked), null when another worker holds it.
	 */
	private function take_bundle_lock( int $voucher_id ): ?string {
		$voucher   = $this->repo->get( $voucher_id );
		$threshold = $voucher ? self::bundle_threshold( $voucher ) : 0;
		if ( ! $voucher || ! $threshold || $voucher->order_id <= 0 || ! self::bundle_candidate( $voucher ) || ! self::bundle_email() ) {
			return '';
		}
		global $wpdb;
		$table = Install::tables()['vouchers'];
		$due   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE order_item_id = %d AND delivered_at IS NULL AND status IN ('scheduled','active') AND ( delivery_at IS NULL OR delivery_at <= %s )", $table, $voucher->order_item_id, $this->repo->now() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $due < $threshold ) {
			return '';
		}
		$name = Install::lock_name( 'delivery_order_' . $voucher->order_id );
		return 1 === (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ? $name : null; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Z6: delivers the vouchers of the order that are due now in bundled emails – one email per order line and
	 * recipient with a code table and one multi-page PDF (above bundle_pdf_limit() download links instead), and
	 * one buyer copy for all of them instead of one per voucher. Only lines with at least bundle_threshold() due
	 * vouchers for the same recipient are bundled; vouchers due at another time wait for their own delivery.
	 *
	 * Every voucher keeps its own state: it is locked, activated, recorded (delivered_roles, delivered_at, ledger)
	 * and retried on its own, so a failed bundle is retried for the vouchers and roles that are still missing.
	 * The caller holds the order lock and the entry voucher's lock.
	 *
	 * @return bool false when the voucher is not part of a bundle (it is then delivered on its own).
	 */
	private function deliver_bundle( Voucher $entry ): bool {
		$email = self::bundle_email();
		if ( ! $email ) {
			return false;
		}
		global $wpdb;
		$groups = array();
		foreach ( $this->repo->for_order( $entry->order_id ) as $candidate ) {
			if ( $candidate->id === $entry->id ) {
				$candidate = $entry;
			} elseif ( ! self::bundle_candidate( $candidate ) ) {
				continue;
			}
			$groups[ self::bundle_key( $candidate ) ][] = $candidate;
		}
		$entry_key = self::bundle_key( $entry );
		$groups    = array_filter( $groups, static fn( $members ) => self::bundle_threshold( $members[0] ) > 0 && count( $members ) >= self::bundle_threshold( $members[0] ) );
		if ( ! isset( $groups[ $entry_key ] ) ) {
			return false;
		}

		$locks = array();
		try {
			foreach ( $groups as $key => $members ) {
				$kept = array();
				foreach ( $members as $member ) {
					if ( $member->id === $entry->id ) {
						$kept[] = $entry;
						continue;
					}
					$name = Install::lock_name( 'delivery_' . $member->id );
					if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $name ) ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
						continue; // Delivered by another worker right now.
					}
					$locks[] = $name;
					$fresh   = $this->repo->get( $member->id );
					// A given-up delivery waits for the shop (it is not mailed again with the others).
					if ( $fresh && self::bundle_candidate( $fresh ) && self::bundle_key( $fresh ) === $key && ! $this->repo->delivery_attempts( $fresh->id )['given_up'] ) {
						$kept[] = $fresh;
					}
				}
				if ( count( $kept ) < self::bundle_threshold( $kept[0] ?? $members[0] ) ) {
					unset( $groups[ $key ] ); // Too few left: each one gets its own email from its own action.
					continue;
				}
				$groups[ $key ] = $kept;
			}
			if ( ! isset( $groups[ $entry_key ] ) ) {
				return false;
			}

			$now     = $this->repo->now();
			$members = array_merge( ...array_values( $groups ) );
			$plan = array();
			foreach ( $members as $member ) {
				if ( ! $this->activate_due( $member, $now ) ) {
					continue; // Voided or changed meanwhile.
				}
				$required  = $this->required_roles( $member );
				$delivered = $this->delivered_roles( $member );
				$pending   = array_values( array_diff( $required, $delivered ) );
				// A retry after a partial failure only mails who is still missing (as for a single voucher).
				$plan[ $member->id ] = array(
					'voucher'     => $member,
					'required'    => $required,
					'delivered'   => $delivered,
					'wanted'      => $delivered ? $pending : self::wanted_roles( $member ),
					'sent'        => array(),
					'sent_roles'  => array(),
					'failures'    => array(),
					'without_pdf' => array(),
					'batch'       => 1,
				);
			}

			// One email per order line and recipient; one buyer copy (per buyer address) for the whole order.
			$mails = array(
				'recipient' => array(),
				'buyer'     => array(),
			);
			foreach ( $plan as $item ) {
				$voucher = $item['voucher'];
				if ( in_array( 'recipient', $item['wanted'], true ) ) {
					$mails['recipient'][ self::bundle_key( $voucher ) ][] = $voucher;
				}
				if ( in_array( 'buyer', $item['wanted'], true ) ) {
					$mails['buyer'][ strtolower( trim( $voucher->buyer_email ) ) ][] = $voucher;
				}
			}
			// M12-48: each voucher records the attempt before the PDF is rendered (a crash still counts towards
			// max_attempts); after a failed attempt the bundle goes out with download links instead of the PDF.
			$retry = false;
			foreach ( $plan as $item ) {
				$retry = $retry || $this->repo->delivery_attempts( $item['voucher']->id )['failures'] >= 1;
			}
			foreach ( $plan as $item ) {
				$this->repo->log( $item['voucher']->id, self::ATTEMPT_EVENT );
			}
			foreach ( $mails as $role => $lists ) {
				foreach ( $lists as $list ) {
					$sent   = false;
					$reason = 'mail_failed';
					try {
						$email->force_links = $retry;
						$sent   = $email->trigger_bundle( $list, $role );
						$reason = '' !== $email->last_failure ? $email->last_failure : 'mail_failed';
					} catch ( \Throwable $e ) {
						Logger::error( 'Bundled voucher email failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_ids' => array_map( static fn( $v ) => $v->id, $list ), 'role' => $role ) );
					}
					foreach ( $list as $voucher ) {
						$item = &$plan[ $voucher->id ];
						if ( $sent ) {
							$item['sent'][]       = 'recipient' === $role ? $voucher->recipient_email : $voucher->buyer_email;
							$item['sent_roles'][] = $role;
							if ( $email->pdf_failed ) {
								$item['without_pdf'][] = $role;
							}
						} else {
							$item['failures'][ $role ] = $reason;
						}
						$item['batch'] = max( $item['batch'], count( $list ) );
						unset( $item );
					}
				}
			}
			if ( array_filter( array_column( $plan, 'failures' ) ) ) {
				Logger::warning( 'Bundled voucher email not sent.', array( 'order_id' => $entry->order_id, 'vouchers' => count( $plan ) ) );
			}

			$error = null;
			foreach ( $plan as $id => $item ) {
				try {
					$this->record_attempt( $item['voucher'], $item['required'], $item['delivered'], $item['sent'], $item['sent_roles'], $item['failures'], $item['without_pdf'], $now, $item['batch'] );
					if ( $id !== $entry->id && $item['voucher']->delivered_at && function_exists( 'as_unschedule_all_actions' ) ) {
						// AP-P1-8: the member went out with the bundle – its own pending action would do nothing.
						as_unschedule_all_actions( 'vcap_deliver_voucher', array( 'voucher_id' => (int) $id ), self::GROUP );
					}
				} catch ( \Throwable $e ) {
					if ( $id === $entry->id ) {
						$error = $e; // deliver() records it and plans the retry.
						continue;
					}
					Logger::error( 'Voucher delivery failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_id' => $id ) );
					$this->repo->log( $id, 'delivery_failed', null, null, Note::make( 'delivery_error', array( Mask::addresses( mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 300 ) ) ) ) );
					try {
						$this->retry_later( $item['voucher'] );
					} catch ( \Throwable $retry_error ) {
						Logger::error( 'Voucher delivery retry could not be scheduled: ' . $retry_error->getMessage(), array( 'voucher_id' => $id ) );
					}
				}
			}
			if ( $error ) {
				throw $error;
			}
			return true;
		} finally {
			foreach ( $locks as $name ) {
				$this->release_lock( $name );
			}
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
		$this->repo->log( $voucher->id, 'delivery_given_up', null, null, Note::make( 'delivery_given_up', array( $failures ) ) );
		delete_transient( 'vcap_undelivered_count' );
		delete_transient( 'vcap_given_up_count' );
		$order = $voucher->order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $voucher->order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: voucher code, 2: number of failed attempts */
					__( 'Voucher %1$s could not be delivered after %2$d attempts and is no longer sent automatically. Check the email address and the email settings, then send it again on the voucher screen.', 'voucher-captain' ),
					Mask::code( $voucher->code ),
					$failures
				)
			);
		}
		Logger::warning( 'Voucher delivery given up after repeated failures.', array( 'voucher_id' => $voucher->id, 'attempts' => $failures ) );
		/** A delivery was given up after $failures failed attempts. */
		Db::action( 'vcap_voucher_delivery_given_up', $voucher, $failures );
	}

	/**
	 * The voucher ended before it could be delivered (e.g. a delivery date after the expiry): it is not mailed or
	 * activated but marked as expired, and the shop is told on the order and in the ledger.
	 */
	private function refuse_expired( Voucher $voucher ): void {
		global $wpdb;
		$table   = Install::tables()['vouchers'];
		$changed = $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = 'expired', updated_at = %s WHERE id = %d AND delivered_at IS NULL AND status IN ('scheduled','active')", $table, $this->repo->now(), $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $changed ) {
			return; // Changed meanwhile, or recorded by a parallel run.
		}
		$expiry = wp_date( (string) get_option( 'date_format' ), (int) strtotime( $voucher->expires_at . ' UTC' ) );
		$this->repo->log( $voucher->id, 'delivery_expired', null, null, Note::make( 'delivery_expired', array( (string) $voucher->expires_at ) ) );
		delete_transient( 'vcap_undelivered_count' );
		delete_transient( 'vcap_given_up_count' );
		$order = $voucher->order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $voucher->order_id ) : null;
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: voucher code, 2: expiry date */
					__( 'Voucher %1$s was not delivered: it had already expired on %2$s, before its delivery date. Extend its validity and send it again, or refund the buyer.', 'voucher-captain' ),
					Mask::code( $voucher->code ),
					$expiry
				)
			);
		}
		Logger::warning( 'Voucher not delivered because it had already expired.', array( 'voucher_id' => $voucher->id ) );
	}

	/**
	 * M12-49: reports a voucher email that came back (bounce, or a queue SMTP plugin that failed later): "sent"
	 * only meant that wp_mail() accepted it. The role no longer counts as delivered (delivered_roles), the history
	 * gets a "delivery_failed" entry, the order a note, and with $notify_buyer the buyer is told by email (with the
	 * "Correct the recipient" link where it applies). Fires "vcap_voucher_bounced". The voucher emails carry the
	 * headers "X-VCAP-Voucher" (voucher ids) and "X-VCAP-Role" for bounce handlers.
	 *
	 * @param string $role 'recipient' or 'buyer'.
	 * @return bool Whether the bounce was recorded.
	 */
	public function mark_bounced( int $voucher_id, string $role, string $reason = '', bool $notify_buyer = false ): bool {
		$voucher = in_array( $role, array( 'recipient', 'buyer' ), true ) ? $this->repo->get( $voucher_id ) : null;
		if ( ! $voucher ) {
			return false;
		}
		$reason    = trim( mb_substr( wp_strip_all_tags( $reason ), 0, 200 ) );
		$delivered = array_values( array_diff( $this->delivered_roles( $voucher ), array( $role ) ) );
		$this->store_delivered_roles( $voucher->id, $delivered, array_values( array_diff( $this->required_roles( $voucher ), $delivered ) ) );
		$label = 'recipient' === $role ? __( 'recipient', 'voucher-captain' ) : __( 'buyer', 'voucher-captain' );
		$this->repo->log(
			$voucher->id,
			'delivery_failed',
			null,
			null,
			'' !== $reason
				/* translators: 1: "recipient" or "buyer", 2: reason reported by the mail system */
				? sprintf( __( 'The email to the %1$s came back (bounce): %2$s', 'voucher-captain' ), $label, $reason )
				/* translators: %s: "recipient" or "buyer" */
				: sprintf( __( 'The email to the %s came back (bounce).', 'voucher-captain' ), $label )
		);
		delete_transient( 'vcap_undelivered_count' );
		$order = $voucher->order();
		if ( $order instanceof \WC_Order ) {
			$order->add_order_note(
				sprintf(
					/* translators: 1: voucher code (masked), 2: email address (masked), 3: reason */
					__( 'The email with voucher %1$s to %2$s came back undelivered. %3$s Check the address and send the voucher again on the voucher screen.', 'voucher-captain' ),
					Mask::code( $voucher->code ),
					Mask::email( 'recipient' === $role ? $voucher->recipient_email : $voucher->buyer_email ),
					$reason
				)
			);
		}
		if ( $notify_buyer && 'recipient' === $role && is_email( $voucher->buyer_email ) && function_exists( 'WC' ) ) {
			$fix     = vcap()->downloads->correction_url( $voucher );
			$mailer  = WC()->mailer();
			$message = '<p>' . esc_html(
				sprintf(
					/* translators: %s: recipient email */
					__( 'The voucher you sent to %s could not be delivered: the email came back.', 'voucher-captain' ),
					$voucher->recipient_email
				)
			) . '</p>';
			$message .= '' !== $fix
				? '<p><a href="' . esc_url( $fix ) . '">' . esc_html__( 'Correct the recipient', 'voucher-captain' ) . '</a></p>'
				: '<p>' . esc_html__( 'Please contact us with the correct address.', 'voucher-captain' ) . '</p>';
			$subject = __( 'Your gift voucher could not be delivered', 'voucher-captain' );
			$mailer->send( $voucher->buyer_email, $subject, $mailer->wrap_message( $subject, $message ) );
		}
		Logger::warning( 'Voucher email bounced.', array( 'voucher_id' => $voucher->id, 'role' => $role ) );
		/**
		 * A voucher email came back (Delivery::mark_bounced()). Args: Voucher, role ('recipient'|'buyer'), reason.
		 *
		 * @since 1.2.0
		 */
		Db::action( 'vcap_voucher_bounced', $voucher, $role, $reason );
		return true;
	}

	/**
	 * Roles ('recipient', 'buyer') that already received this voucher in an earlier, partly failed attempt.
	 *
	 * @return string[]
	 */
	private function delivered_roles( Voucher $voucher ): array {
		if ( Install::storage_ready() ) {
			global $wpdb;
			$table = Install::tables()['vouchers'];
			$roles = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT delivered_roles FROM %i WHERE id = %d', $table, $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$roles = array_values( array_intersect( array_filter( explode( ',', $roles ) ), array( 'recipient', 'buyer' ) ) );
			if ( $roles ) {
				return $roles;
			}
		}
		// Before schema 1.4 (or a retry recorded by an older version) a transient listed who was still missing.
		$missing = get_transient( 'vcap_delivery_missing_' . $voucher->id );
		// (array) false would be array( false ): no transient means nobody got it yet.
		$missing = is_array( $missing ) ? array_values( array_intersect( $missing, array( 'recipient', 'buyer' ) ) ) : array();
		return $missing ? array_values( array_diff( $this->required_roles( $voucher ), $missing ) ) : array();
	}

	/**
	 * Remembers who got the voucher. Before schema 1.4 only the missing roles are kept, in a transient.
	 *
	 * @param string[] $delivered Roles mailed so far.
	 * @param string[] $missing   Required roles still missing.
	 */
	private function store_delivered_roles( int $voucher_id, array $delivered, array $missing ): void {
		$key = 'vcap_delivery_missing_' . $voucher_id;
		if ( Install::storage_ready() ) {
			global $wpdb;
			$table = Install::tables()['vouchers'];
			$wpdb->query( $wpdb->prepare( 'UPDATE %i SET delivered_roles = %s WHERE id = %d', $table, implode( ',', $delivered ), $voucher_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			delete_transient( $key );
			return;
		}
		if ( $missing ) {
			set_transient( $key, $missing, 31 * DAY_IN_SECONDS );
		} else {
			delete_transient( $key );
		}
	}

	/**
	 * Whether the voucher goes to a recipient of its own: "Send to" names the recipient, the address is valid and
	 * it is not the buyer's own (a gift to one's own address gets only the buyer's mail, not "X sent you a gift"
	 * next to "Your voucher" in the same inbox).
	 */
	public static function to_recipient( Voucher $voucher ): bool {
		return in_array( $voucher->send_to, array( 'recipient', 'both' ), true ) && is_email( $voucher->recipient_email ) && ! self::same_address( $voucher->recipient_email, $voucher->buyer_email );
	}

	/**
	 * Valid addresses of a comma separated list ("Additional recipients" of an email), without the main address(es)
	 * and without duplicates: each gets its own copy.
	 *
	 * @return string[]
	 */
	public static function extra_addresses( string $list, string $main = '' ): array {
		$seen = array_filter( array_map( static fn( $a ) => strtolower( trim( $a ) ), explode( ',', $main ) ) );
		$out  = array();
		foreach ( array_map( 'trim', explode( ',', $list ) ) as $address ) {
			if ( is_email( $address ) && ! in_array( strtolower( $address ), $seen, true ) ) {
				$seen[] = strtolower( $address );
				$out[]  = $address;
			}
		}
		return $out;
	}

	/** Two email addresses are the same inbox (case and surrounding spaces ignored). */
	public static function same_address( string $a, string $b ): bool {
		$a = strtolower( trim( $a ) );
		return '' !== $a && strtolower( trim( $b ) ) === $a;
	}

	/**
	 * Who must receive the voucher for it to count as delivered ('recipient', 'buyer'); a buyer copy next to a
	 * gift for the recipient is a courtesy and not required.
	 *
	 * @return string[]
	 */
	public function required_roles( Voucher $voucher ): array {
		$to_recipient = self::to_recipient( $voucher );
		$roles        = $to_recipient ? array( 'recipient' ) : array();
		if ( ! $to_recipient || 'both' === $voucher->send_to ) {
			$roles[] = 'buyer';
		}
		return $roles;
	}

	/**
	 * Send the voucher emails according to the voucher's routing.
	 *
	 * @param string[]              $sent_roles  Filled with the roles that were mailed ('recipient', 'buyer').
	 * @param array<string, string> $failures    Filled per role that was not mailed with the reason (D4):
	 *                                           'disabled', 'no_recipient', 'mail_failed', 'not_registered'.
	 * @param string[]              $without_pdf Filled with the roles mailed without the PDF they should carry (D5).
	 * @return string[] addresses mailed
	 */
	public function send( Voucher $voucher, ?string $only = null, array &$sent_roles = array(), array &$failures = array(), array &$without_pdf = array() ): array {
		$mailer = WC()->mailer();
		$emails = $mailer->get_emails();
		$sent   = array();
		if ( $voucher->id && ( null === $voucher->delivered_at || '' === (string) $voucher->delivered_at ) ) {
			// M12-48: recorded before the PDF is rendered, so an attempt that crashes (fatal error, time limit) still
			// counts towards max_attempts (VoucherRepository::delivery_attempts(); the outcome event pairs it).
			$this->repo->log( $voucher->id, self::ATTEMPT_EVENT );
		}

		$to_recipient = self::to_recipient( $voucher );
		$to_buyer     = ! $to_recipient || 'both' === $voucher->send_to || self::legacy_buyer_copy( $voucher );
		$routes       = array(
			'recipient' => array( $to_recipient, 'VCAP_Email_Voucher_Recipient', $voucher->recipient_email ),
			'buyer'     => array( $to_buyer, 'VCAP_Email_Voucher_Buyer', $voucher->buyer_email ),
		);
		foreach ( $routes as $role => list( $wanted, $class, $address ) ) {
			if ( ! $wanted || ( null !== $only && $role !== $only ) ) {
				continue;
			}
			$email = $emails[ $class ] ?? null;
			if ( ! $email || ! method_exists( $email, 'trigger' ) ) {
				$failures[ $role ] = 'not_registered';
				continue;
			}
			try {
				$mailed = $email->trigger( $voucher );
			} catch ( \Throwable $e ) {
				// AP-Q2-3: one role's mail failing (template error in the buyer copy) does not lose the roles mailed
				// before it – they are recorded, the retry only mails who is still missing.
				Logger::error( 'Voucher email failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_id' => $voucher->id, 'role' => $role ) );
				$failures[ $role ] = 'mail_failed';
				continue;
			}
			if ( $mailed ) {
				$sent[]       = $address;
				$sent_roles[] = $role;
				if ( ! empty( $email->pdf_failed ) ) {
					$without_pdf[] = $role;
				}
			} else {
				$failures[ $role ] = isset( $email->last_failure ) && '' !== (string) $email->last_failure ? (string) $email->last_failure : 'mail_failed';
			}
		}
		if ( $failures ) {
			Logger::warning( 'Voucher email not sent.', array( 'voucher_id' => $voucher->id, 'reasons' => $failures ) );
		}
		return $sent;
	}

	/**
	 * U12: the buyer copy is fixed on the voucher (send_to "both"). Vouchers issued before that read the product's
	 * old "Buyer copy" flag as they always did, so gifts already scheduled keep their copy.
	 */
	private static function legacy_buyer_copy( Voucher $voucher ): bool {
		$since = (string) get_option( self::ROUTING_SINCE_OPTION, '' );
		if ( 'recipient' !== $voucher->send_to || '' === $since || '' === $voucher->created_at || $voucher->created_at >= $since ) {
			return false;
		}
		$product = wc_get_product( $voucher->variation_id ?: $voucher->product_id );
		$base    = $product && $product->is_type( 'variation' ) ? wc_get_product( $product->get_parent_id() ) : $product;
		return $base && 'no' !== $base->get_meta( '_vcap_buyer_copy' );
	}

	/** Called by Plugin::ensure_schedules() (once a day and after activation or an update, not on every request). */
	public function schedule_maintenance(): void {
		if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( 'vcap_daily_maintenance', array(), self::GROUP ) ) {
			as_schedule_recurring_action( strtotime( 'tomorrow 03:15' ), DAY_IN_SECONDS, 'vcap_daily_maintenance', array(), self::GROUP );
		}
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
	 * pending referral withdrawals, background issuing and issue retries of orders (AP-Q2-2), running bulk jobs and
	 * retries of failed voids. Runs as Action Scheduler action in batches of RESTORE_BATCH rows; each batch
	 * queues the next one.
	 *
	 * @param string|array $phase "vouchers", "redemptions", "referrals", "issue", "issue_failed", "jobs" or "voids"
	 *                            (or the action's args array).
	 * @param int          $after Last id done (order id for "referrals", "issue" and "issue_failed").
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
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, delivery_at FROM %i WHERE status = 'scheduled' AND delivered_at IS NULL AND id > %d ORDER BY id LIMIT %d", $tables['vouchers'], $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
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
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, expires_at FROM %i WHERE state = %s AND expires_at IS NOT NULL AND id > %d ORDER BY id LIMIT %d', $tables['redemptions'], API\V1\RedemptionService::STATE_RESERVED, $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $rows ? $rows : array();
			foreach ( $rows as $row ) {
				$args = array( 'redemption_id' => (int) $row['id'] );
				if ( ! as_has_scheduled_action( 'vcap_release_expired_redemption', $args, self::GROUP ) ) {
					as_schedule_single_action( max( time(), (int) strtotime( $row['expires_at'] . ' UTC' ) ) + MINUTE_IN_SECONDS, 'vcap_release_expired_redemption', $args, self::GROUP );
				}
			}
			return count( $rows ) >= $limit ? array( 'redemptions', (int) end( $rows )['id'] ) : array( 'referrals', 0 );
		}
		if ( 'referrals' === $phase && function_exists( 'wc_get_orders' ) ) {
			// Keyset by order id ($after = last id; 1 = start), read from the order store in use (OrderLookup).
			$orders = OrderLookup::ids_with_meta(
				Referrals::REWARD,
				null,
				array(
					'status'   => array( 'cancelled', 'refunded' ),
					'limit'    => 200,
					'after_id' => max( 0, $after ),
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
			return count( $orders ) >= 200 ? array( 'referrals', (int) end( $orders ) ) : array( 'issue', 0 );
		}
		if ( ( 'issue' === $phase || 'issue_failed' === $phase ) && function_exists( 'wc_get_orders' ) ) {
			// AP-Q2-2: orders whose vouchers were being issued in the background, or whose issuing failed and was
			// to be retried, lost their pending vcap_issue_order action with the deactivation.
			$failed = 'issue_failed' === $phase;
			$orders = OrderLookup::ids_with_meta(
				$failed ? Issuer::ISSUE_FAILED_META : Issuer::ASYNC_META,
				null,
				array(
					'status'   => array(),
					'limit'    => 200,
					'after_id' => max( 0, $after ),
				)
			);
			foreach ( $orders as $order_id ) {
				$args = array( 'order_id' => (int) $order_id );
				if ( as_has_scheduled_action( Issuer::ISSUE_RETRY_HOOK, $args, self::GROUP ) ) {
					continue;
				}
				$at = time();
				if ( $failed ) {
					$order = wc_get_order( $order_id );
					$state = $order instanceof \WC_Order ? $order->get_meta( Issuer::ISSUE_FAILED_META ) : null;
					$tries = is_array( $state ) ? (int) ( $state['attempts'] ?? 0 ) : 0;
					if ( $tries < 1 || $tries > Issuer::ISSUE_RETRIES ) {
						continue; // Given up before the deactivation: only the listing stays, as before.
					}
					$at = max( $at, (int) ( $state['time'] ?? 0 ) + Issuer::issue_retry_delay( $tries ) );
				}
				as_schedule_single_action( $at, Issuer::ISSUE_RETRY_HOOK, $args, self::GROUP );
			}
			if ( count( $orders ) >= 200 ) {
				return array( $phase, (int) end( $orders ) );
			}
			return $failed ? array( 'jobs', 0 ) : array( 'issue_failed', 0 );
		}
		if ( 'jobs' === $phase ) {
			Jobs::reschedule_running(); // Background code generation and imports (a handful of records).
			return array( 'voids', 0 );
		}
		if ( 'voids' === $phase ) {
			// Voids that failed after a refund are retried until they work: open cases without a pending retry.
			$pending = array();
			foreach ( as_get_scheduled_actions( array( 'hook' => Issuer::VOID_RETRY_HOOK, 'status' => \ActionScheduler_Store::STATUS_PENDING, 'per_page' => -1 ) ) as $action ) {
				$pending[ (int) ( $action->get_args()['voucher_id'] ?? 0 ) ] = true;
			}
			foreach ( Attention::all() as $case ) {
				if ( ! preg_match( '/^void(\d+)$/', (string) $case['kind'], $m ) || isset( $pending[ (int) $m[1] ] ) ) {
					continue;
				}
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, Issuer::VOID_RETRY_HOOK, array( 'voucher_id' => (int) $m[1], 'attempt' => 1 ), self::GROUP );
				$pending[ (int) $m[1] ] = true;
			}
			return null;
		}
		return null;
	}

	/**
	 * Remind holders of vouchers with remaining value before they expire. Each voucher is reminded at most once
	 * (ledger event 'expiry_reminder'); admins can suppress the reminder per voucher. Works in batches of 200
	 * until every due voucher was handled or $budget seconds are used (the maintenance step then continues), so
	 * many vouchers expiring on one day (e.g. on 31 December) are all reminded in time. Nothing is tried while
	 * the email is disabled, and vouchers without a valid address are skipped (no failed attempt each day).
	 *
	 * @param float     $budget   Seconds; 0 = until done.
	 * @param bool|null $finished Set to false when the budget ran out with vouchers left.
	 * @return int number of reminders sent
	 */
	public function send_expiry_reminders( float $budget = 0.0, ?bool &$finished = null ): int {
		$finished = true;
		$offsets  = self::expiry_reminder_offsets( $this->settings->get( 'vcap_expiry_reminder_days' ) );
		if ( ! $offsets ) {
			return 0;
		}
		$emails = WC()->mailer()->get_emails();
		$email  = $emails['VCAP_Email_Voucher_Expiry'] ?? null;
		if ( ! $email || ! $email->is_enabled() || ! method_exists( $email, 'trigger' ) ) {
			return 0;
		}
		$start = microtime( true );
		$sent  = 0;
		// M12-84: several reminders (e.g. 30, 7 and 1 day before). Each one covers the vouchers ending before its
		// day and after the next smaller one, so a voucher found late gets only the reminder that fits.
		foreach ( $offsets as $i => $days ) {
			$after = (int) ( $offsets[ $i + 1 ] ?? 0 );
			$seen  = array();
			do {
				$batch = $this->repo->due_for_expiry_reminder( $days, 200, $seen, $after );
				foreach ( $batch as $voucher ) {
					if ( $budget > 0 && microtime( true ) - $start >= $budget ) {
						$finished = false;
						return $sent;
					}
					$seen[] = $voucher->id;
					if ( ! is_email( $voucher->delivery_email() ) || ! apply_filters( 'vcap_send_expiry_reminder', true, $voucher, $days ) ) {
						continue;
					}
					// Claimed first (a parallel run or a crash after sending never leads to a second reminder), recorded
					// only after a successful send; a failed send gives the claim back and is retried after a day.
					$claim = 'expiry-reminder:' . $voucher->id . ':' . $days;
					if ( ! Claim::take( $claim ) ) {
						continue;
					}
					try {
						$mailed = method_exists( $email, 'trigger' ) && $email->trigger( $voucher );
					} catch ( \Throwable $e ) {
						// One broken voucher (PDF, template, mail plugin) neither keeps its claim nor stops the others.
						Claim::release( $claim );
						Logger::error( 'Expiry reminder failed: ' . Mask::addresses( $e->getMessage() ), array( 'voucher_id' => $voucher->id ) );
						continue;
					}
					if ( $mailed ) {
						$this->repo->log( $voucher->id, 'expiry_reminder', null, $voucher->balance, self::REMINDER_NOTE_PREFIX . $days );
						++$sent;
					} else {
						Claim::release( $claim );
						$this->repo->log( $voucher->id, 'delivery_failed', null, null, Note::make( 'reminder_failed' ) );
					}
				}
				$more = count( $batch ) === 200;
			} while ( $more );
		}
		return $sent;
	}

	/** M12-84: note of an "expiry_reminder" event sent N days before the expiry ("days_before=N"). */
	public const REMINDER_NOTE_PREFIX = 'days_before=';

	/**
	 * M12-84: the reminder days of the setting "vcap_expiry_reminder_days" – one number as before, or several
	 * separated by commas ("30, 7, 1"); 1–365, without duplicates, largest first. 0 or nothing: no reminders.
	 *
	 * @param mixed $value Stored setting.
	 * @return int[]
	 */
	public static function expiry_reminder_offsets( $value ): array {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', trim( (string) $value ), -1, PREG_SPLIT_NO_EMPTY ) ?: array() as $part ) {
			if ( ctype_digit( $part ) && (int) $part >= 1 && (int) $part <= 365 ) {
				$out[] = (int) $part;
			}
		}
		$out = array_values( array_unique( $out ) );
		rsort( $out, SORT_NUMERIC );
		return $out;
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
			Logger::error( sprintf( 'Daily maintenance step "%s" failed: %s', $step, Mask::addresses( $e->getMessage() ) ), array( 'step' => $step ) );
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
				// Temporary PDFs and dompdf's temp images contain voucher codes in plain text (M12-43).
				Install::purge_temp_files();
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
				// SET-VC-06: the expiry behaviour decides (ExpiryPolicy) – at the date, when the grace days end, or never.
				$this->repo->expire_overdue( $budget, $finished );
				return $finished;
			case 'retention':
				$days     = (int) $this->settings->get( 'vcap_retention_days' );
				$finished = true;
				if ( $days > 0 ) {
					// Batches of short transactions; an error is thrown and recorded as the step's last run.
					$this->repo->anonymise_older_than( $days, $budget, $finished );
				}
				return $finished;
			case 'voucher_requests':
				// D10: open/partial requests after 6 months, issued/handed-over ones after 24 (batches of 50); SET-VC-08:
				// the periods are settings (block "Retention").
				return ! isset( vcap()->voucher_requests ) || vcap()->voucher_requests->purge_expired( $budget );
			case 'review_refs':
				// R4: reference records of the review source (revoked after 90 days, all after 24 months; settings since
				// SET-VC-08).
				return ! isset( vcap()->review_source ) || vcap()->review_source->purge( $budget );
			case 'protocols':
				// SET-VC-08: failed scanner lookups and the voucher command protocol, kept as set in "Retention".
				Counter::prune_failed_lookups();
				( new API\V1\OptionCommandStore() )->prune();
				return true;
			case 'settings_log':
				// SET-VC-16: change log of the settings, kept "vcap_settings_log_days" days.
				SettingsLog::prune( (int) $this->settings->get( SettingsLog::DAYS_OPTION ) );
				return true;
			case 'legacy_rows':
				Install::move_legacy_rows_locked( $budget );
				return Install::hash_event_notes( $budget );
			case 'delivery_net':
				return $this->deliver_due( $budget );
			case 'expiry_reminders':
				$this->send_expiry_reminders( $budget, $finished );
				return $finished;
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
				if ( $this->give_up_if_exhausted( $id ) ) {
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

	/**
	 * M12-48: gives a delivery up once max_attempts() attempts failed – also attempts that crashed before they could
	 * record their failure or plan a retry (a "delivery_attempt" without outcome, see delivery_attempts()), which
	 * would otherwise be picked up by the safety net again and again.
	 *
	 * @return bool Whether it was given up now.
	 */
	public function give_up_if_exhausted( int $voucher_id ): bool {
		$state   = $this->repo->delivery_attempts( $voucher_id );
		$voucher = $state['failures'] > 0 && ! $state['given_up'] ? $this->repo->get( $voucher_id ) : null;
		if ( ! $voucher || $voucher->delivered_at || $state['failures'] < self::max_attempts( $voucher ) ) {
			return false;
		}
		$this->give_up( $voucher, $state['failures'] );
		return true;
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
				'note'    => mb_substr( Mask::addresses( wp_strip_all_tags( $note ) ), 0, 200 ),
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
