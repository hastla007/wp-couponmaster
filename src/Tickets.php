<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * QR tickets: check-in (single or multiple admissions), in-store redemption and the REST API used by the scanner.
 */
final class Tickets {
	public function __construct( private VoucherRepository $repo ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'routes' ) );
		add_action( 'template_redirect', array( $this, 'ticket_page' ), 2 );
		// WooCommerce sends users without edit_posts/manage_woocommerce away from wp-admin: scanner staff (role
		// "Voucher cashier") need the scanner page there.
		add_filter( 'woocommerce_prevent_admin_access', array( self::class, 'allow_scanner_admin' ) );
		add_filter( 'woocommerce_disable_admin_bar', array( self::class, 'allow_scanner_admin' ) );
	}

	/** @param bool $prevent WooCommerce's verdict. */
	public static function allow_scanner_admin( $prevent ) {
		return $prevent && current_user_can( 'vcap_scan_tickets' ) ? false : $prevent; // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install.
	}

	public static function checkin_url( Voucher $voucher ): string {
		return add_query_arg( 'vcap_ticket', $voucher->token, home_url( '/' ) );
	}

	public static function can_scan(): bool {
		return current_user_can( 'vcap_scan_tickets' ) || current_user_can( 'manage_woocommerce' );
	}

	public function routes(): void {
		register_rest_route(
			'vcap/v1',
			'/lookup',
			array(
				// The scanner posts the code (codes in URLs end up in server, proxy and CDN logs); GET stays for clients.
				'methods'             => 'GET, POST',
				'callback'            => array( $this, 'rest_lookup' ),
				'permission_callback' => array( self::class, 'can_scan' ),
				'args'                => array( 'q' => array( 'required' => true, 'type' => 'string' ) ),
			)
		);
		register_rest_route(
			'vcap/v1',
			'/checkin',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_checkin' ),
				'permission_callback' => array( self::class, 'can_scan' ),
				'args'                => array(
					'q'      => array( 'required' => true, 'type' => 'string' ),
					'count'  => array( 'type' => 'integer', 'default' => 1 ),
					'undo'   => array( 'type' => 'boolean', 'default' => false ),
					// Required to revert someone else's (or an older) check-in.
					'reason' => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
		register_rest_route(
			'vcap/v1',
			'/redeem',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_redeem' ),
				'permission_callback' => array( self::class, 'can_scan' ),
				'args'                => array(
					'q'             => array( 'required' => true, 'type' => 'string' ),
					'amount'        => array( 'type' => 'number' ),
					'note'          => array( 'type' => 'string', 'default' => '' ),
					// Receipt number of the till and the till/branch of this device, kept with the booking.
					'receipt'       => array( 'type' => 'string', 'default' => '' ),
					'till'          => array( 'type' => 'string', 'default' => '' ),
					// Only with this flag may an amount above the balance book the remaining balance.
					'allow_partial' => array( 'type' => 'boolean', 'default' => false ),
				),
			)
		);
		register_rest_route(
			'vcap/v1',
			'/redeem/reverse',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_reverse' ),
				'permission_callback' => array( self::class, 'can_scan' ),
				'args'                => array(
					'q'        => array( 'required' => true, 'type' => 'string' ),
					'event_id' => array( 'required' => true, 'type' => 'integer' ),
					'reason'   => array( 'required' => true, 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Accepts a raw code, a token, or a scanned URL: the check-in link, the PDF download link or a custom QR
	 * link that carries the code in a parameter (e.g. "?gutschein=CODE"). See code_candidates().
	 */
	public function resolve( string $input ): ?Voucher {
		$input = trim( $input );
		if ( preg_match( '/[?&](?:vcap_ticket|vcap_pdf)=([a-f0-9]{48})/', $input, $m ) || preg_match( '/^([a-f0-9]{48})$/', $input, $m ) ) {
			$voucher = $this->repo->get_by_token( $m[1] );
			if ( $voucher ) {
				return $voucher;
			}
		}
		foreach ( self::code_candidates( $input ) as $code ) {
			$voucher = $this->repo->get_by_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) );
			if ( $voucher ) {
				return $voucher;
			}
		}
		return null;
	}

	/**
	 * Codes to look up for a scanned or typed input, most likely first: the input itself (or the parameter
	 * values and last path segment of a URL), then without spaces, with spaces as dashes, without separators,
	 * and finally with Y and Z swapped (a USB scanner set to a US layout on a German keyboard, where "-" also
	 * arrives as "ß").
	 *
	 * @return string[]
	 */
	public static function code_candidates( string $input ): array {
		$input = trim( $input );
		$raw   = array( $input );
		if ( preg_match( '#^https?://#i', $input ) ) {
			$raw = array();
			parse_str( (string) wp_parse_url( $input, PHP_URL_QUERY ), $params );
			foreach ( $params as $value ) {
				if ( is_string( $value ) && '' !== trim( $value ) ) {
					$raw[] = trim( $value );
				}
			}
			$path = trim( (string) wp_parse_url( $input, PHP_URL_PATH ), '/' );
			if ( '' !== $path ) {
				$raw[] = rawurldecode( basename( $path ) );
			}
		}
		$forms = array();
		foreach ( $raw as $value ) {
			$value   = sanitize_text_field( $value );
			$forms[] = $value;
			$forms[] = (string) preg_replace( '/\s+/u', '', $value );
			$forms[] = (string) preg_replace( '/\s+/u', '-', $value );
			$forms[] = (string) preg_replace( '/[\s\-_.\/]+/u', '', $value );
		}
		$swapped = array_map( static fn( $f ) => strtr( $f, array( 'y' => 'z', 'z' => 'y', 'Y' => 'Z', 'Z' => 'Y', 'ß' => '-' ) ), $forms );
		$codes   = array_map( 'wc_format_coupon_code', array_merge( $forms, $swapped ) );
		return array_slice( array_values( array_unique( array_filter( $codes, static fn( $c ) => '' !== (string) $c ) ) ), 0, 12 );
	}

	/** Whether the scanner shows the holder's name (setting "vcap_scanner_show_holder"; personal tickets always). */
	public static function show_holder( Voucher $voucher ): bool {
		$show = 'yes' === get_option( 'vcap_scanner_show_holder', 'no' ) || ( $voucher->is_ticket() && '' !== (string) $voucher->recipient_name );
		return (bool) apply_filters( 'vcap_scanner_show_holder', $show, $voucher );
	}

	/** Staff who manage vouchers: they see order numbers, customer data and the admin details. */
	public static function is_manager(): bool {
		return current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install / WooCommerce.
	}

	public function describe( Voucher $voucher ): array {
		$product  = $voucher->product();
		$manager  = self::is_manager();
		$events   = $voucher->id ? $this->repo->events( $voucher->id ) : array();
		$state    = 'ok';
		$message  = __( 'Valid', 'voucher-captain' );
		$context  = array(); // Extra rows: why the code is refused (when, where, by whom, until when).
		$reserved = $voucher->is_multi_use() ? self::open_reservation( $events ) : null;
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			$state   = 'error';
			$message = __( 'Cancelled', 'voucher-captain' );
			$voided  = self::last_event( $events, array( 'voided' ) );
			if ( $voided && '' !== (string) $voided['note'] ) {
				$context[] = array( __( 'Reason', 'voucher-captain' ), (string) $voided['note'] );
			}
		} elseif ( $voucher->is_expired() || Voucher::STATUS_EXPIRED === $voucher->status ) {
			$state   = 'error';
			$message = __( 'Expired', 'voucher-captain' );
		} elseif ( $voucher->is_ticket() && $voucher->checkins >= $voucher->max_checkins ) {
			$state   = 'warning';
			$message = __( 'Already checked in', 'voucher-captain' );
		} elseif ( ! $voucher->is_ticket() && Voucher::STATUS_REDEEMED === $voucher->status && $reserved ) {
			// The whole balance is held by a shop order that is not paid yet: it may come back.
			$state     = 'warning';
			$message   = __( 'Reserved in an unpaid shop order', 'voucher-captain' );
			$context[] = array( __( 'Reserved since', 'voucher-captain' ), self::local_time( (string) $reserved['created_at'] ) );
			$until     = self::reservation_until( (int) $reserved['order_id'] );
			if ( $until ) {
				$label     = __( 'Reserved until', 'voucher-captain' );
				/* translators: %s: time */
				$text      = __( 'about %s, unless the order is paid', 'voucher-captain' );
				$context[] = array( $label, sprintf( $text, wp_date( (string) get_option( 'time_format' ), $until ) ) );
			}
		} elseif ( ! $voucher->is_ticket() && Voucher::STATUS_REDEEMED === $voucher->status ) {
			$state   = 'warning';
			$message = __( 'Already redeemed', 'voucher-captain' );
		} elseif ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
			$state   = 'warning';
			$message = __( 'Not yet delivered', 'voucher-captain' );
			if ( $voucher->delivery_at ) {
				$context[] = array( __( 'Delivery planned for', 'voucher-captain' ), self::local_time( $voucher->delivery_at ) );
			}
		} elseif ( Voucher::STATUS_RESERVED === $voucher->status ) {
			$state   = 'warning';
			$message = __( 'Currently being redeemed elsewhere', 'voucher-captain' );
		}
		// Last use: when, where and by whom (staff names only; customers' names and order numbers for managers).
		$last = $voucher->is_ticket() ? null : self::last_event( $events, array( 'redeemed', 'committed', 'api_committed', 'reserved', 'double_use' ) );
		if ( $last && ( Voucher::STATUS_REDEEMED === $voucher->status || $voucher->is_multi_use() ) ) {
			$context = array_merge( $context, self::use_rows( $last, $manager, $voucher->currency ) );
		}
		// Same rules as the counter redemption: what would be refused is not shown as a green "Valid".
		$refusal = $this->counter_refusal_reason( $voucher );
		if ( 'ok' === $state && '' !== $refusal ) {
			$state   = 'warning';
			$message = $refusal;
		}
		$undo     = $voucher->is_ticket() && $voucher->checkins > 0 ? self::undo_mode( $voucher, self::last_event( $events, array( 'checkin' ) ), get_current_user_id(), $manager, time() ) : '';
		$currency = '' !== $voucher->currency ? $voucher->currency : get_woocommerce_currency();
		$data     = array(
			'id'             => $voucher->id,
			'code'           => $voucher->code,
			'type'           => $voucher->type,
			'type_label'     => Voucher::types()[ $voucher->type ] ?? $voucher->type,
			'status'         => $voucher->status,
			'status_label'   => $voucher->status_label(),
			'state'          => $state,
			'message'        => $message,
			'context'        => $context,
			'product'        => $product ? $product->get_name() : '',
			// Privacy at the door: the name only when enabled (or for personal tickets, where it is checked);
			// order number and admin details only for staff who manage vouchers. Value and balance are
			// always shown: the cashier needs them to redeem.
			'holder'         => self::show_holder( $voucher ) ? ( $voucher->recipient_name ?: $voucher->buyer_name ) : '',
			'order'          => $manager && $voucher->order() ? $voucher->order()->get_order_number() : '',
			'checkins'       => $voucher->checkins,
			'max_checkins'   => $voucher->max_checkins,
			'last_checkin'   => $voucher->last_checkin_at ? self::local_time( $voucher->last_checkin_at ) : '',
			// '' = no undo, 'own' = own check-in within the time window, 'reason' = needs a reason (manager).
			'undo'           => $undo,
			'multi_use'      => $voucher->is_multi_use(),
			'balance'        => $voucher->balance,
			'balance_html'   => self::money_text( $voucher->balance, $voucher->currency ),
			// Amount entry at the counter: currency shown next to the field, parsed with its decimal places.
			'currency'        => $currency,
			'currency_symbol' => html_entity_decode( get_woocommerce_currency_symbol( $currency ) ),
			'decimals'        => $voucher->decimals(),
			'value_html'     => 'percent' === $voucher->discount_type ? Format::percent( $voucher->initial_amount ) : self::money_text( $voucher->initial_amount, $voucher->currency ),
			'expires'        => $voucher->expires_at ? LocalDate::from_utc( $voucher->expires_at, get_option( 'date_format' ) ) : '',
			'admin_url'      => $manager ? admin_url( 'admin.php?page=vcap-vouchers&voucher=' . $voucher->id ) : '',
		);
		if ( '' !== $refusal ) {
			$data['redeemable'] = false;
		}
		/** Connector details for the scanner (e.g. "ticket voucher – exchange first", redeemable false). */
		return (array) apply_filters( 'vcap_scanner_describe', $data, $voucher );
	}

	/** Local date and time of a stored UTC time. */
	private static function local_time( string $utc ): string {
		return LocalDate::from_utc( $utc, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) );
	}

	/** Newest event of the given kinds, or null. */
	public static function last_event( array $events, array $kinds ): ?array {
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			if ( in_array( $events[ $i ]['event'] ?? '', $kinds, true ) ) {
				return $events[ $i ];
			}
		}
		return null;
	}

	/**
	 * The balance reservation of an unpaid shop order that is still open: the newest balance event is a
	 * "reserved" one (payment commits or releases it).
	 */
	public static function open_reservation( array $events ): ?array {
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			if ( null === ( $events[ $i ]['balance_after'] ?? null ) ) {
				continue;
			}
			return 'reserved' === $events[ $i ]['event'] && (int) $events[ $i ]['order_id'] > 0 ? $events[ $i ] : null;
		}
		return null;
	}

	/** When WooCommerce cancels the unpaid order (hold stock setting), or null when it does not. */
	private static function reservation_until( int $order_id ): ?int {
		$order   = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$minutes = (int) get_option( 'woocommerce_hold_stock_minutes', 60 );
		if ( ! $order || ! $order->has_status( 'pending' ) || $minutes <= 0 || ! $order->get_date_created() ) {
			return null;
		}
		return $order->get_date_created()->getTimestamp() + $minutes * MINUTE_IN_SECONDS;
	}

	/** Rows "last use / where / by / note" of a history event for the scanner. */
	private static function use_rows( array $e, bool $manager, string $currency ): array {
		$user  = (int) $e['user_id'] ? get_userdata( (int) $e['user_id'] ) : null;
		$staff = $user && ( user_can( $user, 'vcap_scan_tickets' ) || user_can( $user, 'manage_woocommerce' ) ); // phpcs:ignore WordPress.WP.Capabilities.Unknown -- Registered by Install / WooCommerce.
		$when  = self::local_time( (string) $e['created_at'] );
		if ( null !== $e['amount'] && (float) $e['amount'] > 0 ) {
			$when .= ' · ' . self::money_text( (float) $e['amount'], $currency );
		}
		$rows = array( array( __( 'Last use', 'voucher-captain' ), $when ) );
		if ( (int) $e['order_id'] > 0 ) {
			$label  = __( 'Where', 'voucher-captain' );
			/* translators: %s: order number */
			$order  = __( 'Shop order #%s', 'voucher-captain' );
			$rows[] = array( $label, $manager ? sprintf( $order, (int) $e['order_id'] ) : __( 'Shop order', 'voucher-captain' ) );
		} elseif ( 'api_committed' === $e['event'] ) {
			$rows[] = array( __( 'Where', 'voucher-captain' ), __( 'External system', 'voucher-captain' ) );
		} elseif ( $staff ) {
			$rows[] = array( __( 'Where', 'voucher-captain' ), __( 'In store', 'voucher-captain' ) );
		}
		if ( $staff || ( $manager && $user ) ) {
			$rows[] = array( __( 'By', 'voucher-captain' ), $user->display_name );
		}
		// Notes of shop orders may hold the customer's email address: managers only.
		if ( '' !== (string) $e['note'] && ( $staff || $manager ) ) {
			$rows[] = array( __( 'Note', 'voucher-captain' ), (string) $e['note'] );
		}
		return $rows;
	}

	/**
	 * Whether the current user may revert the last check-in: 'own' (their own check-in within the time window,
	 * filter "vcap_checkin_undo_minutes", default 10), 'reason' (managers, with a reason) or '' (not at all; also
	 * for cancelled or expired tickets).
	 */
	public static function undo_mode( Voucher $voucher, ?array $last_checkin, int $user_id, bool $manager, int $now ): string {
		if ( ! $voucher->is_ticket() || $voucher->checkins < 1 || Voucher::STATUS_VOID === $voucher->status || Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			return '';
		}
		$window = max( 0, (int) apply_filters( 'vcap_checkin_undo_minutes', 10, $voucher ) );
		if ( $last_checkin && $user_id > 0 && (int) $last_checkin['user_id'] === $user_id && $now - (int) strtotime( $last_checkin['created_at'] . ' UTC' ) <= $window * MINUTE_IN_SECONDS ) {
			return 'own';
		}
		return $manager ? 'reason' : '';
	}

	/**
	 * Why the counter refuses to redeem this voucher ('' = it may): store credit, tickets, other channels, an
	 * unpaid shop order holding the voucher, or a connector's reason. Used by the lookup and the redemption alike.
	 */
	public function counter_refusal_reason( Voucher $voucher ): string {
		if ( $voucher->is_ticket() ) {
			return ''; // Checked in, not redeemed: the check-in rules apply.
		}
		$refused = '';
		if ( 'credit' === $voucher->type ) {
			$refused = __( 'Store credit can only be used in the shop.', 'voucher-captain' );
		} elseif ( ! Channels::allowed( $voucher, Channels::COUNTER ) ) {
			$refused = __( 'This voucher cannot be redeemed at the counter.', 'voucher-captain' );
		} elseif ( Voucher::STATUS_ACTIVE === $voucher->status && CouponManager::held_by_pending_order( $voucher ) ) {
			$refused = __( 'This voucher is in an unpaid shop order right now. Redeem it after that order is cancelled.', 'voucher-captain' );
		}
		/** Vouchers that must not be redeemed at the counter (e.g. ticket vouchers: exchange them for tickets). */
		return (string) apply_filters( 'vcap_scanner_redeem_refused', $refused, $voucher );
	}

	public function rest_lookup( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			/** Codes Voucher Captain does not know may belong to another plugin (shared scanner, e.g. Pass Captain tickets). */
			$external = apply_filters( 'vcap_scanner_lookup', null, (string) $request['q'] );
			if ( null !== $external ) {
				return is_wp_error( $external ) ? $external : rest_ensure_response( $external );
			}
			return new \WP_Error( 'vcap_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->describe( $voucher ) );
	}

	/**
	 * Atomic check-in: never exceeds the admission count, even with parallel scanners.
	 */
	public function checkin( Voucher $voucher, int $count = 1 ): bool {
		global $wpdb;
		if ( ! $voucher->is_ticket() ) {
			return false; // Vouchers and gift cards are redeemed, not checked in.
		}
		$table = Install::tables()['vouchers'];
		$count = max( 1, $count );
		$now   = gmdate( 'Y-m-d H:i:s' );
		$rows  = $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"UPDATE {$table} SET checkins = checkins + %d, last_checkin_at = %s, updated_at = %s
				WHERE id = %d AND checkins + %d <= max_checkins AND status IN ('active') AND (expires_at IS NULL OR expires_at >= %s)",
				$count,
				$now,
				$now,
				$voucher->id,
				$count,
				$now
			)
		);
		if ( 1 !== (int) $rows ) {
			return false;
		}
		$fresh = $this->repo->get( $voucher->id );
		/* translators: 1: used admissions, 2: total admissions */
		$this->repo->log( $voucher->id, 'checkin', $count, null, sprintf( __( '%1$d of %2$d admissions used', 'voucher-captain' ), $fresh->checkins, $fresh->max_checkins ) );
		if ( $fresh->checkins >= $fresh->max_checkins && $fresh->is_ticket() ) {
			$fresh->status = Voucher::STATUS_REDEEMED;
			$this->repo->update( $fresh );
		}
		do_action( 'vcap_ticket_checked_in', $fresh, $count );
		return true;
	}

	public function undo_checkin( Voucher $voucher, string $note = '' ): bool {
		global $wpdb;
		$table = Install::tables()['vouchers'];
		// Atomic like checkin(): parallel scanners must not lose updates.
		$rows = $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET checkins = checkins - 1, updated_at = %s WHERE id = %d AND checkins > 0", gmdate( 'Y-m-d H:i:s' ), $voucher->id ) ); // phpcs:ignore
		if ( 1 !== (int) $rows ) {
			return false;
		}
		if ( $voucher->is_ticket() ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = 'active' WHERE id = %d AND status = 'redeemed' AND checkins < max_checkins", $voucher->id ) ); // phpcs:ignore
		}
		$this->repo->log( $voucher->id, 'checkin_undo', 1, null, $note );
		return true;
	}

	public function rest_checkin( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			$external = $request['undo'] ? null : apply_filters( 'vcap_scanner_checkin', null, (string) $request['q'], sanitize_text_field( (string) $request['idempotency_key'] ) );
			if ( null !== $external ) {
				return is_wp_error( $external ) ? $external : rest_ensure_response( $external );
			}
			return new \WP_Error( 'vcap_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$undo  = (bool) $request['undo'];
		$key   = sanitize_text_field( (string) $request['idempotency_key'] );
		$sig   = md5( 'checkin|' . $voucher->id . '|' . ( $undo ? 'undo' : max( 1, (int) $request['count'] ) ) );
		$prior = $this->scan_claim( $key, $sig, $voucher, array( $undo ? 'checkin_undo' : 'checkin' ) );
		if ( null !== $prior ) {
			return is_wp_error( $prior ) ? $prior : rest_ensure_response( $prior );
		}
		$note = '';
		if ( $undo ) {
			// Reverting is limited: one's own check-in within a few minutes, otherwise a manager with a reason.
			$mode   = self::undo_mode( $voucher, self::last_event( $this->repo->events( $voucher->id ), array( 'checkin' ) ), get_current_user_id(), self::is_manager(), time() );
			$reason = sanitize_text_field( (string) $request['reason'] );
			$error  = null;
			if ( '' === $mode ) {
				$error = new \WP_Error( 'vcap_undo_not_allowed', __( 'This check-in cannot be reverted here: only your own check-in can be reverted within a few minutes, and never for cancelled or expired tickets. Please ask a manager.', 'voucher-captain' ), array( 'status' => 403 ) );
			} elseif ( 'reason' === $mode && '' === $reason ) {
				$error = new \WP_Error( 'vcap_reason_required', __( 'Please enter a reason for reverting this check-in.', 'voucher-captain' ), array( 'status' => 400 ) );
			}
			if ( $error ) {
				$this->scan_release( $key );
				return $error;
			}
			$note = $reason;
		}
		try {
			$ok = $undo ? $this->undo_checkin( $voucher, $note ) : $this->checkin( $voucher, (int) $request['count'] );
		} catch ( \Throwable $e ) {
			$this->scan_release( $key );
			throw $e;
		}
		$data            = $this->describe( $this->repo->get( $voucher->id ) );
		$data['success'] = $ok;
		if ( $ok ) {
			$data['state']   = 'ok';
			$data['message'] = $undo ? __( 'Check-in reverted', 'voucher-captain' ) : __( 'Checked in', 'voucher-captain' );
		} elseif ( 'ok' === $data['state'] ) {
			$data['state']   = 'error';
			$data['message'] = __( 'Check-in not possible.', 'voucher-captain' );
		}
		$this->scan_store( $key, $data );
		return rest_ensure_response( $data );
	}

	private const SCAN_PREFIX = '_vcap_scan_';

	/** Seconds after which a claim without an answer counts as abandoned (the request died after claiming). */
	private const SCAN_STALE = 120;

	/**
	 * Idempotency of native scanner mutations (check-in, undo, counter redemption, cancellation). The first request
	 * with a key claims it atomically (unique option row, with the newest history event of the voucher); a repeat
	 * with the same key gets the stored answer, a repeat while the first is still running gets 409 (the scanner
	 * retries with the same key), and a key reused for another action is refused. Keys are kept two days.
	 *
	 * A claim left without an answer (PHP stopped after booking, before storing the answer) is resolved after
	 * SCAN_STALE seconds from the history: an event of this action by this user after the claim means it was
	 * booked (that is answered), none means nothing happened and the repeat may act.
	 *
	 * @param string[] $kinds History events the action writes.
	 * @return array|\WP_Error|null stored response, error, or null when this request may act
	 */
	private function scan_claim( string $key, string $sig, Voucher $voucher, array $kinds ) {
		if ( '' === $key ) {
			return null;
		}
		global $wpdb;
		$name   = self::SCAN_PREFIX . md5( $key );
		$events = Install::tables()['events'];
		$mark   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(MAX(id), 0) FROM {$events} WHERE voucher_id = %d", $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, time() . '|' . $sig . '#' . $mark . '|' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === (int) $wpdb->rows_affected ) {
			return null;
		}
		$row                          = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		list( $time, $stored, $json ) = array_pad( explode( '|', $row, 3 ), 3, '' );
		list( $stored, $since )       = array_pad( explode( '#', $stored, 2 ), 2, '' );
		if ( $stored !== $sig ) {
			return new \WP_Error( 'vcap_idempotency_conflict', __( 'This scanner request was already used for another action. Please scan the code again.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		if ( '' === $json ) {
			if ( time() - (int) $time < self::SCAN_STALE || '' === $since ) {
				return new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
			}
			$in     = implode( ',', array_map( static fn( $k ) => "'" . esc_sql( (string) $k ) . "'", $kinds ) );
			$booked = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$events} WHERE voucher_id = %d AND id > %d AND user_id = %d AND event IN ({$in}) ORDER BY id LIMIT 1", $voucher->id, (int) $since, get_current_user_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $booked ) {
				$fresh             = $this->repo->get( $voucher->id ) ?? $voucher;
				$data              = $this->describe( $fresh );
				$data['success']   = true;
				$data['state']     = 'warning';
				$data['recovered'] = true;
				$data['event_id']  = $booked;
				$data['message']   = __( 'This action was already booked (found in the voucher history). Do not repeat it.', 'voucher-captain' );
				$this->scan_store( $key, $data );
				return $data;
			}
			// Nothing was booked: take the claim over (only one repeat wins) and act.
			$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", time() . '|' . $sig . '#' . $mark . '|', $name, $row ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1 === (int) $taken ? null : new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		$data             = (array) json_decode( $json, true );
		$data['replayed'] = true;
		return $data;
	}

	private function scan_store( string $key, $data ): void {
		if ( '' === $key ) {
			return;
		}
		global $wpdb;
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 2), '|', %s) WHERE option_name = %s", (string) wp_json_encode( $data ), self::SCAN_PREFIX . md5( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Stored answer of a finished scanner action ('' answer or another action = null). */
	private function scan_result( string $key, string $sig ): ?array {
		global $wpdb;
		$row                         = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SCAN_PREFIX . md5( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		list( , $stored, $json )     = array_pad( explode( '|', $row, 3 ), 3, '' );
		return '' !== $json && strtok( $stored, '#' ) === $sig ? (array) json_decode( $json, true ) : null;
	}

	private function scan_release( string $key ): void {
		if ( '' !== $key ) {
			global $wpdb;
			$wpdb->delete( $wpdb->options, array( 'option_name' => self::SCAN_PREFIX . md5( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Removes scanner idempotency keys older than two days (daily maintenance), in batches until done or $budget
	 * seconds are used.
	 *
	 * @return bool true when all old keys are removed.
	 */
	public static function purge_scan_keys( float $budget = 10.0 ): bool {
		global $wpdb;
		$start = microtime( true );
		$limit = 1000;
		do {
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT %d", $wpdb->esc_like( self::SCAN_PREFIX ) . '%', time() - 2 * DAY_IN_SECONDS, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Scanner keys could not be removed: ' . $wpdb->last_error );
			}
		} while ( (int) $deleted >= $limit && microtime( true ) - $start < $budget );
		return (int) $deleted < $limit;
	}

	/**
	 * In-store redemption: deduct an amount from a gift card or mark a single-use voucher as used.
	 */
	public function rest_redeem( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$key   = sanitize_text_field( (string) $request['idempotency_key'] );
		$sig   = 'redeem|' . $voucher->id . '|' . round( max( 0, (float) $request['amount'] ), 4 ) . ( self::allow_partial( $request ) ? '|partial' : '' );
		$prior = $this->scan_claim( $key, md5( $sig ), $voucher, array( 'redeemed' ) );
		if ( null !== $prior ) {
			return is_wp_error( $prior ) ? $prior : rest_ensure_response( $prior );
		}
		try {
			$response = $this->redeem_at_counter( $request, $voucher );
		} catch ( \Throwable $e ) {
			$this->scan_release( $key );
			throw $e;
		}
		if ( is_wp_error( $response ) ) {
			$this->scan_release( $key ); // Nothing was changed (e.g. no amount): the same key may act later.
			return $response;
		}
		$this->scan_store( $key, $response instanceof \WP_REST_Response ? $response->get_data() : $response );
		return $response;
	}

	private static function allow_partial( \WP_REST_Request $request ): bool {
		return (bool) rest_sanitize_boolean( $request['allow_partial'] ?? false );
	}

	/** Plain-text price ("12,50 €") for scanner messages. */
	public static function money_text( float $amount, string $currency ): string {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $currency ) ) ) );
	}

	/**
	 * Plausibility of an amount entered at the counter: '' when it may be booked, otherwise
	 * 'amount_missing' (not above zero, not a number), 'amount_precision' (more decimal places than the
	 * currency has) or 'exceeds_balance' (above the balance without an explicit partial booking).
	 */
	public static function counter_amount_error( float $amount, float $balance, int $decimals, bool $allow_partial ): string {
		if ( ! is_finite( $amount ) || $amount <= 0 ) {
			return 'amount_missing';
		}
		$scaled = $amount * ( 10 ** max( 0, $decimals ) );
		if ( abs( $scaled - round( $scaled ) ) > 1e-6 ) {
			return 'amount_precision';
		}
		if ( CouponManager::minor_units( $amount, $decimals ) <= 0 ) {
			return 'amount_missing';
		}
		if ( ! $allow_partial && CouponManager::minor_units( $amount, $decimals ) > CouponManager::minor_units( $balance, $decimals ) ) {
			return 'exceeds_balance';
		}
		return '';
	}

	/**
	 * Scanner message for a counter booking that deducted nothing (reason codes of
	 * CouponManager::deduct_balance_atomic() and counter_amount_error()).
	 */
	public static function refusal_message( string $reason, string $requested_html = '', string $balance_html = '', int $decimals = 2 ): string {
		switch ( $reason ) {
			case 'exceeds_balance':
				/* translators: 1: entered amount, 2: remaining balance of the voucher */
				return sprintf( __( 'Not deducted: the amount (%1$s) exceeds the remaining balance (%2$s).', 'voucher-captain' ), $requested_html, $balance_html );
			case 'amount_missing':
			case 'invalid_amount':
				return __( 'Please enter an amount greater than zero.', 'voucher-captain' );
			case 'amount_precision':
				/* translators: %d: number of decimal places of the currency */
				return sprintf( __( 'Not deducted: the amount has more decimal places than the currency allows (%d).', 'voucher-captain' ), $decimals );
			case 'void':
				return __( 'Not deducted: this voucher has been cancelled.', 'voucher-captain' );
			case 'expired':
				return __( 'Not deducted: this voucher has expired.', 'voucher-captain' );
			case 'reserved':
				return __( 'Not deducted: this voucher is being redeemed elsewhere right now. Please try again in a moment.', 'voucher-captain' );
			case 'scheduled':
				return __( 'Not deducted: this voucher has not been delivered yet.', 'voucher-captain' );
			case 'redeemed':
				return __( 'Not deducted: the balance is used up.', 'voucher-captain' );
			case 'not_active':
			case 'not_found':
			case 'not_multi_use':
				return __( 'Not deducted: this voucher is not active.', 'voucher-captain' );
			case 'no_transactions':
				return __( 'Not deducted: the database cannot deduct balances safely (no transaction support). Please contact the shop administrator.', 'voucher-captain' );
			default:
				return __( 'Not deducted: the balance could not be updated. Please try again.', 'voucher-captain' );
		}
	}

	/**
	 * Scanner answer for a counter booking of a stored-value voucher that deducted nothing.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function counter_refusal( int $voucher_id, string $reason, float $requested ) {
		$fresh = $this->repo->get( $voucher_id );
		if ( ! $fresh ) {
			return new \WP_Error( 'vcap_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$data                       = $this->describe( $fresh );
		$data['success']            = false;
		$data['state']              = in_array( $reason, array( 'no_transactions', 'db_error', 'reserved' ), true ) ? 'warning' : 'error';
		$data['reason']             = $reason;
		$data['requested']          = $requested;
		$data['requested_html']     = self::money_text( $requested, $fresh->currency );
		$data['booked']             = 0.0;
		$data['booked_html']        = self::money_text( 0, $fresh->currency );
		$data['balance_after']      = $fresh->balance;
		$data['balance_after_html'] = $data['balance_html'];
		$data['message']            = self::refusal_message( $reason, $data['requested_html'], $data['balance_html'], $fresh->decimals() );
		return rest_ensure_response( $data );
	}

	/** @return \WP_REST_Response|\WP_Error */
	private function redeem_at_counter( \WP_REST_Request $request, Voucher $voucher ) {
		// Only active vouchers: not yet delivered (scheduled), reserved, cancelled or expired ones are refused with
		// their own reason (describe()).
		if ( Voucher::STATUS_ACTIVE !== $voucher->status || $voucher->is_expired() ) {
			$data            = $this->describe( $voucher );
			$data['success'] = false;
			$data['reason']  = CouponManager::balance_refusal( $voucher ) ?: 'not_active';
			if ( 'ok' === $data['state'] ) {
				$data['state']   = 'error';
				$data['message'] = __( 'This voucher is not active.', 'voucher-captain' );
			}
			return rest_ensure_response( $data );
		}
		// Same rules as the redemption API and the lookup: store credit belongs to a customer account, tickets are
		// checked in (not redeemed), and a voucher restricted to other channels is not valid at the counter.
		$refused = $voucher->is_ticket() ? __( 'Tickets are checked in at the entrance and cannot be redeemed here.', 'voucher-captain' ) : $this->counter_refusal_reason( $voucher );
		if ( '' !== $refused ) {
			$data            = $this->describe( $voucher );
			$data['success'] = false;
			$data['state']   = 'error';
			$data['message'] = $refused;
			return rest_ensure_response( $data );
		}
		$note = self::counter_note( (string) $request['note'], (string) $request['receipt'], (string) $request['till'] );
		if ( $voucher->is_multi_use() ) {
			$amount  = (float) $request['amount'];
			$partial = self::allow_partial( $request );
			$check   = self::counter_amount_error( $amount, $voucher->balance, $voucher->decimals(), $partial );
			if ( 'amount_missing' === $check ) {
				return new \WP_Error( 'vcap_amount', self::refusal_message( $check ), array( 'status' => 400 ) );
			}
			if ( 'amount_precision' === $check ) {
				return new \WP_Error( 'vcap_amount_precision', self::refusal_message( $check, '', '', $voucher->decimals() ), array( 'status' => 400 ) );
			}
			if ( '' !== $check ) {
				return $this->counter_refusal( $voucher->id, $check, $amount );
			}
			// The balance is checked again under the row lock: above it, nothing is booked unless a partial
			// booking was asked for explicitly (the till must collect the rest otherwise).
			$reason    = '';
			$deduction = vcap()->coupons->deduct_balance_atomic( $voucher, $amount, $note, 0, $partial, $reason );
			if ( ! $deduction || $deduction['amount'] <= 0 ) {
				return $this->counter_refusal( $voucher->id, $deduction ? 'redeemed' : (string) $reason, $amount );
			}
			$fresh                      = $this->repo->get( $voucher->id );
			$data                       = $this->describe( $fresh );
			$booked                     = (float) $deduction['amount'];
			$uncovered                  = max( 0.0, round( $amount - $booked, $fresh->decimals() ) );
			$data['success']            = true;
			$data['state']              = $uncovered > 0 ? 'warning' : 'ok';
			$data['reason']             = '';
			$data['partial']            = $uncovered > 0;
			$data['requested']          = $amount;
			$data['requested_html']     = self::money_text( $amount, $fresh->currency );
			$data['booked']             = $booked;
			$data['booked_html']        = self::money_text( $booked, $fresh->currency );
			$data['balance_after']      = (float) $deduction['balance'];
			$data['balance_after_html'] = self::money_text( (float) $deduction['balance'], $fresh->currency );
			$data['uncovered']          = $uncovered;
			$data['uncovered_html']     = self::money_text( $uncovered, $fresh->currency );
			if ( $uncovered > 0 ) {
				/* translators: 1: amount deducted, 2: amount still to be paid in another way, 3: remaining balance */
				$data['message'] = sprintf( __( 'Partially redeemed: %1$s deducted, %2$s still to be paid otherwise. Remaining balance: %3$s.', 'voucher-captain' ), $data['booked_html'], $data['uncovered_html'], $data['balance_after_html'] );
			} else {
				/* translators: 1: amount deducted, 2: remaining balance */
				$data['message'] = sprintf( __( 'Redeemed: %1$s deducted. Remaining balance: %2$s.', 'voucher-captain' ), $data['booked_html'], $data['balance_after_html'] );
			}
			$data['event_id'] = $this->own_last_redemption( $voucher->id );
			return rest_ensure_response( $data );
		} elseif ( ! $this->redeem_single( $voucher, $note ) ) {
			// Of two parallel redemptions (two tills, or till and online) only one wins.
			$data            = $this->describe( $this->repo->get( $voucher->id ) );
			$data['success'] = false;
			return rest_ensure_response( $data );
		}
		$data             = $this->describe( $this->repo->get( $voucher->id ) );
		$data['success']  = true;
		$data['state']    = 'ok';
		$data['message']  = __( 'Redeemed', 'voucher-captain' );
		$data['event_id'] = $this->own_last_redemption( $voucher->id );
		return rest_ensure_response( $data );
	}

	/** Booking note of a counter redemption: the note, receipt number and till/branch of the device. */
	public static function counter_note( string $note, string $receipt = '', string $till = '' ): string {
		$note    = sanitize_text_field( $note );
		$receipt = sanitize_text_field( $receipt );
		$till    = sanitize_text_field( $till );
		$parts   = array( '' !== $note ? $note : __( 'Redeemed in store', 'voucher-captain' ) );
		if ( '' !== $receipt ) {
			/* translators: %s: receipt number of the till */
			$parts[] = sprintf( __( 'Receipt %s', 'voucher-captain' ), $receipt );
		}
		if ( '' !== $till ) {
			/* translators: %s: till or branch name */
			$parts[] = sprintf( __( 'Till %s', 'voucher-captain' ), $till );
		}
		return mb_substr( implode( ' · ', $parts ), 0, 250 );
	}

	/** Id of the newest counter redemption the current user booked on this voucher (0 = none). */
	private function own_last_redemption( int $voucher_id ): int {
		$last = null;
		foreach ( $this->repo->events( $voucher_id ) as $e ) {
			if ( 'redeemed' === $e['event'] && 0 === (int) $e['order_id'] && (int) $e['user_id'] === get_current_user_id() ) {
				$last = $e;
			}
		}
		return $last ? (int) $last['id'] : 0;
	}

	/**
	 * Redeem a single-use or reusable voucher in the store (scanner, or "Mark as redeemed (store)" in the voucher
	 * admin). Single-use vouchers are redeemed atomically: of two parallel redemptions only one wins.
	 */
	public function redeem_single( Voucher $voucher, string $note ): bool {
		if ( $voucher->is_multi_use() ) {
			return false;
		}
		if ( 'unlimited' === $voucher->usage_mode ) {
			// Reusable vouchers: count one use, WooCommerce's usage limit decides when it is used up.
			$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, null, $note );
			if ( $voucher->coupon_id ) {
				$coupon = new \WC_Coupon( $voucher->coupon_id );
				if ( $coupon->get_id() ) {
					$coupon->increase_usage_count();
				}
			}
			return true;
		}
		if ( ! $this->repo->redeem_single_if_active( $voucher->id ) ) {
			return false;
		}
		$voucher->status  = Voucher::STATUS_REDEEMED;
		$voucher->balance = 0;
		$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, 0, $note );
		if ( $voucher->coupon_id ) {
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			if ( $coupon->get_id() ) {
				$coupon->set_usage_limit( max( 1, $coupon->get_usage_count() ) );
				$coupon->set_usage_count( max( 1, $coupon->get_usage_count() ) );
				$coupon->save();
			}
		}
		return true;
	}

	/* ---- Cancelling a counter redemption ------------------------------------------------------------- */

	/**
	 * Why a redemption cannot be cancelled ('' = it can): 'not_found' (no redemption of this voucher), 'order'
	 * (booked by a shop order: cancel or refund the order instead), 'reversed' (already cancelled), 'void'
	 * (voucher cancelled), 'forbidden' (only managers, or the person who booked it within the time window,
	 * filter "vcap_counter_reversal_minutes", default 15).
	 */
	public static function reversal_refusal( ?array $event, Voucher $voucher, int $user_id, bool $manager, int $now, bool $reversed ): string {
		if ( ! $event || 'redeemed' !== ( $event['event'] ?? '' ) || (int) $event['voucher_id'] !== $voucher->id || (float) $event['amount'] <= 0 ) {
			return 'not_found';
		}
		if ( (int) $event['order_id'] > 0 ) {
			return 'order';
		}
		if ( $reversed ) {
			return 'reversed';
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			return 'void';
		}
		if ( $manager ) {
			return '';
		}
		$window = max( 0, (int) apply_filters( 'vcap_counter_reversal_minutes', 15, $voucher ) );
		$own    = $user_id > 0 && (int) $event['user_id'] === $user_id && $now - (int) strtotime( $event['created_at'] . ' UTC' ) <= $window * MINUTE_IN_SECONDS;
		return $own ? '' : 'forbidden';
	}

	/** Message for a reversal_refusal() code. */
	public static function reversal_message( string $code ): string {
		switch ( $code ) {
			case 'reason':
				return __( 'Please enter a reason for the cancellation.', 'voucher-captain' );
			case 'order':
				return __( 'This redemption belongs to a shop order. Cancel or refund the order instead.', 'voucher-captain' );
			case 'reversed':
				return __( 'This redemption has already been cancelled.', 'voucher-captain' );
			case 'void':
				return __( 'The voucher has been cancelled; its redemptions cannot be cancelled any more.', 'voucher-captain' );
			case 'forbidden':
				return __( 'Only a manager, or the person who booked it shortly before, can cancel this redemption.', 'voucher-captain' );
			case 'used':
				return __( 'The voucher has been used again since then. Correct the balance instead.', 'voucher-captain' );
			case 'failed':
				return __( 'The cancellation could not be saved. Please try again.', 'voucher-captain' );
			default:
				return __( 'This redemption cannot be cancelled.', 'voucher-captain' );
		}
	}

	/** Whether a redemption (history event id) was cancelled already. */
	public static function is_reversed( int $event_id ): bool {
		return '' !== (string) get_option( 'vcap_reversal_' . $event_id, '' );
	}

	/**
	 * Cancels a counter redemption (wrong card scanned, wrong amount): the amount goes back to the balance, a
	 * single-use voucher becomes active again and its coupon counter goes back. Booked as "redeem_reversed" with a
	 * reference to the redemption and the reason, in the redemption journal.
	 *
	 * @return float|\WP_Error amount booked back
	 */
	public function reverse_redemption( Voucher $voucher, int $event_id, string $reason ) {
		$reason = trim( sanitize_text_field( $reason ) );
		$event  = null;
		foreach ( $this->repo->events( $voucher->id ) as $e ) {
			if ( (int) $e['id'] === $event_id ) {
				$event = $e;
			}
		}
		$code = '' === $reason ? 'reason' : self::reversal_refusal( $event, $voucher, get_current_user_id(), self::is_manager(), time(), self::is_reversed( $event_id ) );
		if ( '' === $code && ! $voucher->is_multi_use() && 'single' === $voucher->usage_mode && $voucher->coupon_id ) {
			// A single-use voucher used by a shop order meanwhile is not given back here.
			$coupon = new \WC_Coupon( $voucher->coupon_id );
			$code   = $coupon->get_id() && array_filter( (array) $coupon->get_used_by() ) ? 'used' : '';
		}
		if ( '' !== $code ) {
			return new \WP_Error( 'vcap_reversal_' . $code, self::reversal_message( $code ), array( 'status' => in_array( $code, array( 'forbidden' ), true ) ? 403 : 409 ) );
		}
		global $wpdb;
		// One cancellation per redemption, also with two clicks or two admins at once.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'vcap_reversal_' . $event_id, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return new \WP_Error( 'vcap_reversal_reversed', self::reversal_message( 'reversed' ), array( 'status' => 409 ) );
		}
		wp_cache_delete( 'vcap_reversal_' . $event_id, 'options' );
		$amount = (float) $event['amount'];
		/* translators: 1: history entry number of the redemption, 2: reason */
		$note = sprintf( __( 'Cancels redemption #%1$d: %2$s', 'voucher-captain' ), $event_id, $reason );
		$ok   = false;
		if ( $voucher->is_multi_use() ) {
			$ok = vcap()->coupons->adjust_balance( $voucher, $amount, 'redeem_reversed', $note );
		} elseif ( 'unlimited' === $voucher->usage_mode ) {
			$ok = $this->repo->log( $voucher->id, 'redeem_reversed', $amount, null, $note );
			if ( $ok && $voucher->coupon_id ) {
				$coupon = new \WC_Coupon( $voucher->coupon_id );
				if ( $coupon->get_id() && $coupon->get_usage_count() > 0 ) {
					$coupon->decrease_usage_count();
				}
			}
		} else {
			$table = Install::tables()['vouchers'];
			$done  = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$table} SET status = %s, balance = initial_amount, updated_at = %s WHERE id = %d AND status = %s", Voucher::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ), $voucher->id, Voucher::STATUS_REDEEMED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( 1 === $done ) {
				$ok = $this->repo->log( $voucher->id, 'redeem_reversed', $amount, null, $note );
				if ( $voucher->coupon_id ) {
					$coupon = new \WC_Coupon( $voucher->coupon_id );
					if ( $coupon->get_id() ) {
						$coupon->set_usage_count( max( 0, $coupon->get_usage_count() - 1 ) );
						$coupon->set_usage_limit( 1 );
						$coupon->save();
					}
				}
				$fresh = $this->repo->get( $voucher->id );
				if ( $fresh ) {
					do_action( 'vcap_voucher_updated', $fresh );
				}
			}
		}
		if ( ! $ok ) {
			delete_option( 'vcap_reversal_' . $event_id );
			return new \WP_Error( 'vcap_reversal_failed', self::reversal_message( 'failed' ), array( 'status' => 500 ) );
		}
		/** A counter redemption was cancelled (amount booked back). */
		do_action( 'vcap_redemption_reversed', $voucher->id, $event_id, $amount, $reason );
		return $amount;
	}

	public function rest_reverse( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			return new \WP_Error( 'vcap_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		$event_id = (int) $request['event_id'];
		$key      = sanitize_text_field( (string) $request['idempotency_key'] );
		$prior    = $this->scan_claim( $key, md5( 'reverse|' . $voucher->id . '|' . $event_id ), $voucher, array( 'redeem_reversed' ) );
		if ( null !== $prior ) {
			return is_wp_error( $prior ) ? $prior : rest_ensure_response( $prior );
		}
		try {
			$amount = $this->reverse_redemption( $voucher, $event_id, (string) $request['reason'] );
		} catch ( \Throwable $e ) {
			$this->scan_release( $key );
			throw $e;
		}
		if ( is_wp_error( $amount ) ) {
			$this->scan_release( $key );
			return $amount;
		}
		$fresh           = $this->repo->get( $voucher->id ) ?? $voucher;
		$data            = $this->describe( $fresh );
		$data['success'] = true;
		$data['state']   = 'ok';
		/* translators: %s: amount booked back */
		$data['message'] = sprintf( __( 'Redemption cancelled: %s booked back.', 'voucher-captain' ), self::money_text( $amount, $fresh->currency ) );
		$this->scan_store( $key, $data );
		return rest_ensure_response( $data );
	}

	/**
	 * Opening a ticket QR code: staff get the check-in screen, everyone else a privacy-safe status page.
	 */
	public function ticket_page(): void {
		if ( empty( $_GET['vcap_ticket'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$voucher = $this->repo->get_by_token( sanitize_key( wp_unslash( $_GET['vcap_ticket'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$notice  = '';
		$level   = 'ok';

		if ( $voucher && self::can_scan() && isset( $_POST['vcap_checkin_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_checkin_nonce'] ) ), 'vcap_checkin_' . $voucher->id ) ) {
			// Post/Redirect/Get with a one-time key per form: reloading, a double tap or resending the form
			// never admits twice; the answer is shown after the redirect.
			$count = max( 1, absint( $_POST['count'] ?? 1 ) );
			// Theme overrides of the template without the key still get the redirect (no repeat on reload).
			$key   = sanitize_key( wp_unslash( $_POST['vcap_checkin_key'] ?? '' ) ) ?: wp_generate_uuid4();
			$prior = $this->scan_claim( $key, md5( 'checkin-page|' . $voucher->id ), $voucher, array( 'checkin' ) );
			if ( null === $prior ) {
				try {
					$ok = $this->checkin( $voucher, $count );
				} catch ( \Throwable $e ) {
					$this->scan_release( $key );
					throw $e;
				}
				$this->scan_store(
					$key,
					array(
						'success' => $ok,
						/* translators: %d: number of admissions */
						'message' => $ok ? sprintf( _n( 'Checked in (%d admission)', 'Checked in (%d admissions)', $count, 'voucher-captain' ), $count ) : __( 'Check-in not possible.', 'voucher-captain' ),
					)
				);
			}
			if ( null === $prior || is_array( $prior ) ) {
				wp_safe_redirect( add_query_arg( array( 'vcap_ticket' => $voucher->token, 'vcap_done' => $key ), home_url( '/' ) ), 303 );
				exit;
			}
			$notice = $prior->get_error_message();
			$level  = 'vcap_in_progress' === $prior->get_error_code() ? 'warning' : 'error';
		} elseif ( $voucher && self::can_scan() && ! empty( $_GET['vcap_done'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			$done = $this->scan_result( sanitize_key( wp_unslash( $_GET['vcap_done'] ) ), md5( 'checkin-page|' . $voucher->id ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( $done ) {
				$notice = (string) ( $done['message'] ?? '' );
				$level  = empty( $done['success'] ) ? 'error' : 'ok';
			}
		}

		if ( ! $voucher ) {
			status_header( 404 );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );

		$data = $voucher ? $this->describe( $voucher ) : null;
		Plugin::template(
			'ticket-status.php',
			array(
				'voucher'      => $voucher,
				'data'         => $data,
				'staff'        => self::can_scan(),
				'public'       => 'yes' === get_option( 'vcap_ticket_public_status', 'yes' ),
				'notice'       => $notice,
				// 'ok' (green ✓), 'warning' or 'error' (red ✕): a refused check-in must never look like a success.
				'notice_level' => $level,
				'checkin_key'  => wp_generate_uuid4(),
			)
		);
		exit;
	}
}
