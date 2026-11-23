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
					// M12-78: till/branch (entrance) of this device, kept with the check-in.
					'till'   => array( 'type' => 'string', 'default' => '' ),
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
					// M12-24: personal vouchers are only redeemed once the holder's ID was checked.
					'id_checked'    => array( 'type' => 'boolean', 'default' => false ),
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
		if ( preg_match( '/[?&]vcap_pdf=([a-f0-9]{48})/', $input, $m ) ) {
			// M12-65: a PDF download link counts only with the shop's signature for the current link version (a
			// link from before a reissue or with a made-up signature is no proof); its parameters are no codes.
			parse_str( (string) wp_parse_url( $input, PHP_URL_QUERY ), $params );
			$voucher = $this->repo->get_by_token( $m[1] );
			$exp     = isset( $params['exp'] ) && is_string( $params['exp'] ) ? absint( $params['exp'] ) : 0;
			$sig     = isset( $params['sig'] ) && is_string( $params['sig'] ) ? sanitize_key( $params['sig'] ) : '';
			return $voucher && vcap()->downloads->signed_by_shop( $voucher, $exp, $sig ) ? $voucher : null;
		}
		if ( preg_match( '/[?&]vcap_ticket=([a-f0-9]{48})/', $input, $m ) || preg_match( '/^([a-f0-9]{48})$/', $input, $m ) ) {
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
				$context[] = array( __( 'Reason', 'voucher-captain' ), Note::render( (string) $voided['note'] ) );
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
		$undo     = $voucher->is_ticket() && $voucher->checkins > 0 ? self::current_undo_mode( $voucher, $events ) : '';
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
			// M12-24: personal voucher – the holder's ID is checked against these names (addresses masked).
			'personal'       => self::personal_info( $voucher ),
			// M12-45: the current user's own counter redemptions that can still be cancelled.
			'reversible'     => $voucher->is_ticket() ? array() : $this->reversible( $voucher, $events ),
			// M12-31: receipt number of the till receipt required for a redemption (setting).
			'receipt_required' => ! $voucher->is_ticket() && Counter::receipt_required(),
		) + self::tax_data( $voucher );
		if ( '' !== $refusal ) {
			$data['redeemable'] = false;
		}
		/** Connector details for the scanner (e.g. "ticket voucher – exchange first", redeemable false). */
		return (array) apply_filters( 'vcap_scanner_describe', $data, $voucher );
	}

	/**
	 * M12-31: VAT classification of a voucher for the counter (as in the redemption API): purpose ("multi",
	 * "single" or "discount"), treatment ("payment", "single", "discount"), the VAT of the sale for single-purpose
	 * vouchers ({rate_bp, country}, else null) and a short text for the cashier.
	 *
	 * @return array{purpose:string, treatment:string, sale_tax:?array, treatment_label:string}
	 */
	public static function tax_data( Voucher $voucher ): array {
		$purpose  = ProductConfig::purpose( $voucher );
		$sale_tax = 'single' === $purpose ? ProductConfig::sale_tax( $voucher ) : null;
		return array(
			'purpose'         => $purpose,
			'treatment'       => ProductConfig::tax_treatment( $voucher ),
			'sale_tax'        => $sale_tax,
			'treatment_label' => self::treatment_label( $purpose, $sale_tax ),
		);
	}

	/** Cashier's text for the VAT classification (purpose of ProductConfig::purpose()). */
	public static function treatment_label( string $purpose, ?array $sale_tax = null ): string {
		if ( 'multi' === $purpose ) {
			return __( 'Multi-purpose voucher (means of payment): book it as payment on the till receipt – the VAT of the goods is due now.', 'voucher-captain' );
		}
		if ( 'single' === $purpose ) {
			if ( $sale_tax && '' !== (string) $sale_tax['country'] ) {
				/* translators: 1: VAT rate, e.g. 19 %, 2: country code */
				return sprintf( __( 'Single-purpose voucher: VAT (%1$s, %2$s) was charged at the sale – book the goods without VAT again.', 'voucher-captain' ), Format::percent( (int) $sale_tax['rate_bp'] / 100 ), (string) $sale_tax['country'] );
			}
			return __( 'Single-purpose voucher: VAT was charged at the sale – book the goods without VAT again.', 'voucher-captain' );
		}
		return __( 'Discount voucher: reduces the price of the goods (and their VAT).', 'voucher-captain' );
	}

	/**
	 * M12-24: restriction of a personal voucher for the ID check at the counter: the names of buyer/recipient whose
	 * address is allowed, and the allowed addresses masked. Null for transferable vouchers and tickets.
	 *
	 * @return array{names:string[], emails:string[]}|null
	 */
	public static function personal_info( Voucher $voucher ): ?array {
		if ( $voucher->is_ticket() || ! $voucher->coupon_id ) {
			return null;
		}
		$coupon = new \WC_Coupon( $voucher->coupon_id );
		$emails = $coupon->get_id() ? PersonalVoucher::restrictions( $coupon ) : array();
		if ( ! $emails ) {
			return null;
		}
		$names = array();
		foreach ( array( array( $voucher->recipient_name, $voucher->recipient_email ), array( $voucher->buyer_name, $voucher->buyer_email ) ) as $person ) {
			if ( '' !== trim( $person[0] ) && PersonalVoucher::matches( $person[1], $emails ) ) {
				$names[] = trim( $person[0] );
			}
		}
		return array(
			'names'  => array_values( array_unique( $names ) ),
			'emails' => array_values( array_map( array( Mask::class, 'email' ), $emails ) ),
		);
	}

	/**
	 * M12-45: the current user's own counter redemptions of this voucher that can still be cancelled (within the
	 * cancellation window, not cancelled yet), newest first, at most five.
	 *
	 * @return array<int, array{event_id:int, amount_html:string, time:string, receipt:string}>
	 */
	private function reversible( Voucher $voucher, array $events ): array {
		$user = get_current_user_id();
		$now  = time();
		$out  = array();
		if ( $user <= 0 ) {
			return $out;
		}
		foreach ( array_reverse( $events ) as $e ) {
			if ( 5 === count( $out ) ) {
				break;
			}
			if ( 'redeemed' !== ( $e['event'] ?? '' ) || 0 !== (int) $e['order_id'] || (int) $e['user_id'] !== $user ) {
				continue;
			}
			if ( '' !== self::reversal_refusal( $e, $voucher, $user, false, $now, self::is_reversed( (int) $e['id'] ) ) ) {
				continue;
			}
			$out[] = array(
				'event_id'    => (int) $e['id'],
				'amount_html' => self::money_text( (float) $e['amount'], $voucher->currency ),
				'time'        => self::local_time( (string) $e['created_at'] ),
				'receipt'     => (string) ( $e['receipt'] ?? '' ),
			);
		}
		return $out;
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
	 * The newest balance reservation of an unpaid shop order that is still open: the order's latest charge event
	 * (CouponManager::ORDER_CHARGE_EVENTS) is "reserved" (payment commits it, a cancellation releases it). Other
	 * events of the same order (a top-up of the card, a refund as store credit) do not close it (AP-Q1-1).
	 */
	public static function open_reservation( array $events ): ?array {
		$closed = array();
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			$order = (int) ( $events[ $i ]['order_id'] ?? 0 );
			$kind  = (string) ( $events[ $i ]['event'] ?? '' );
			if ( $order <= 0 || isset( $closed[ $order ] ) || ! in_array( $kind, CouponManager::ORDER_CHARGE_EVENTS, true ) ) {
				continue;
			}
			if ( 'reserved' === $kind ) {
				return $events[ $i ];
			}
			$closed[ $order ] = true;
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
			$rows[] = array( __( 'Note', 'voucher-captain' ), Note::render( (string) $e['note'] ) );
		}
		return $rows;
	}

	/**
	 * Whether the current user may revert the last check-in: 'own' (their own check-in within the time window,
	 * filter "vcap_checkin_undo_minutes", default 10), 'reason' (managers, with a reason) or '' (not at all; also
	 * for cancelled or expired tickets).
	 */
	public static function undo_mode( Voucher $voucher, ?array $last_checkin, int $user_id, bool $manager, int $now, int $undone = 0 ): string {
		if ( ! $voucher->is_ticket() || $voucher->checkins < 1 || Voucher::STATUS_VOID === $voucher->status || Voucher::STATUS_EXPIRED === $voucher->status || $voucher->is_expired() ) {
			return '';
		}
		$window = max( 0, (int) apply_filters( 'vcap_checkin_undo_minutes', 10, $voucher ) );
		// M12-15: one's own undo reverts at most the admissions of that check-in ($undone: reverted since), never
		// earlier check-ins of others (a 3-day pass checked in once must not go back from 3/3 to 0/3).
		$admitted = $last_checkin ? max( 1, (int) round( (float) ( $last_checkin['amount'] ?? 1 ) ) ) : 0;
		if ( $last_checkin && $user_id > 0 && (int) $last_checkin['user_id'] === $user_id && $undone < $admitted && $now - (int) strtotime( $last_checkin['created_at'] . ' UTC' ) <= $window * MINUTE_IN_SECONDS ) {
			return 'own';
		}
		return $manager ? 'reason' : '';
	}

	/** Admissions reverted since the newest check-in (sum of the "checkin_undo" events after it). */
	public static function undone_since_checkin( array $events ): int {
		$undone = 0;
		for ( $i = count( $events ) - 1; $i >= 0; $i-- ) {
			$kind = (string) ( $events[ $i ]['event'] ?? '' );
			if ( 'checkin' === $kind ) {
				break;
			}
			if ( 'checkin_undo' === $kind ) {
				$undone += max( 1, (int) round( (float) ( $events[ $i ]['amount'] ?? 1 ) ) );
			}
		}
		return $undone;
	}

	/** undo_mode() of the current user for a ticket with its history. */
	private static function current_undo_mode( Voucher $voucher, array $events ): string {
		return self::undo_mode( $voucher, self::last_event( $events, array( 'checkin' ) ), get_current_user_id(), self::is_manager(), time(), self::undone_since_checkin( $events ) );
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

	/** M12-78: failed scanner lookups per staff member and 15 minutes (filter "vcap_rate_limit_scanner_lookup"). */
	private const LOOKUP_BUCKET = 'scanner_lookup';
	private const LOOKUP_LIMIT  = 100;
	private const LOOKUP_WINDOW = 900;

	public function rest_lookup( \WP_REST_Request $request ) {
		// M12-78: every lookup tries up to 12 code variants – unknown codes are counted per staff member (found
		// ones give the attempt back), so a scanner account cannot be used to guess codes.
		$visitor = 'u' . get_current_user_id();
		if ( Plugin::rate_limited( self::LOOKUP_BUCKET, self::LOOKUP_LIMIT, 0, self::LOOKUP_WINDOW, false, $visitor ) ) {
			return new \WP_Error( 'vcap_rate_limited', __( 'Too many unknown codes were looked up. Please wait a few minutes and scan again.', 'voucher-captain' ), array( 'status' => 429 ) );
		}
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			/** Codes Voucher Captain does not know may belong to another plugin (shared scanner, e.g. Pass Captain tickets). */
			$external = apply_filters( 'vcap_scanner_lookup', null, (string) $request['q'] );
			$unknown  = null === $external || ( is_wp_error( $external ) && 404 === (int) ( ( (array) $external->get_error_data() )['status'] ?? 0 ) );
			if ( ! $unknown ) {
				Plugin::rate_limit_success( self::LOOKUP_BUCKET, self::LOOKUP_WINDOW, $visitor );
				return is_wp_error( $external ) ? $external : rest_ensure_response( $external );
			}
			Plugin::rate_limit_failure( self::LOOKUP_BUCKET, self::LOOKUP_WINDOW );
			Counter::record_failed_lookup( get_current_user_id() );
			return null !== $external ? $external : new \WP_Error( 'vcap_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		Plugin::rate_limit_success( self::LOOKUP_BUCKET, self::LOOKUP_WINDOW, $visitor );
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
		// M12-22: the last admission sets the status in the same statement (status first, so it reads the count before
		// this check-in on MySQL and MariaDB alike): a separate full-row write could undo a parallel undo_checkin().
		$rows  = $wpdb->query( // phpcs:ignore
			$wpdb->prepare(
				"UPDATE %i SET status = IF(checkins + %d >= max_checkins, 'redeemed', status), checkins = checkins + %d, last_checkin_at = %s, updated_at = %s
				WHERE id = %d AND checkins + %d <= max_checkins AND status IN ('active') AND (expires_at IS NULL OR expires_at >= %s)",
				$table,
				$count,
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
		if ( Voucher::STATUS_REDEEMED === $fresh->status ) {
			Db::action( 'vcap_voucher_updated', $fresh ); // As before, when the last admission was written separately.
		}
		Db::action( 'vcap_ticket_checked_in', $fresh, $count );
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
		$till = Counter::device_till( (string) $request['till'] );
		if ( is_wp_error( $till ) ) {
			return $till;
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
			$mode   = self::current_undo_mode( $voucher, $this->repo->events( $voucher->id ) );
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
			$ok = Counter::with( $till, '', fn() => $undo ? $this->undo_checkin( $voucher, $note ) : $this->checkin( $voucher, (int) $request['count'] ) );
		} catch ( \Throwable $e ) {
			$booked = $this->scan_settle( $key, $voucher, array( $undo ? 'checkin_undo' : 'checkin' ) );
			if ( null === $booked ) {
				throw $e;
			}
			self::log_after_booking( $e );
			return rest_ensure_response( $booked );
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

	/** Option name prefix of scanner keys before schema 1.7 (moved into the table vcap_scan_keys by Install). */
	private const SCAN_PREFIX = Install::LEGACY_SCAN_PREFIX;

	/** Seconds after which a claim without an answer counts as abandoned (the request died after claiming). */
	private const SCAN_STALE = 120;

	/** Days a scanner key (and its answer) is kept. */
	private const SCAN_DAYS = 2;

	/**
	 * Idempotency of native scanner mutations (check-in, undo, counter redemption, cancellation). The first request
	 * with a key claims it atomically (unique row, with the newest history event of the voucher); a repeat
	 * with the same key gets the stored answer, a repeat while the first is still running gets 409 (the scanner
	 * retries with the same key), and a key reused for another action is refused. Keys are kept two days.
	 *
	 * A claim left without an answer (PHP stopped after booking, before storing the answer) is resolved after
	 * SCAN_STALE seconds from the history: an event of this action by this user after the claim means it was
	 * booked (that is answered), none means nothing happened and the repeat may act.
	 *
	 * B16: keys are rows of the table vcap_scan_keys (primary key = md5 of the key), one options row each before
	 * schema 1.7.
	 *
	 * @param string[] $kinds History events the action writes.
	 * @return array|\WP_Error|null stored response, error, or null when this request may act
	 */
	private function scan_claim( string $key, string $sig, Voucher $voucher, array $kinds ) {
		if ( '' === $key ) {
			return null;
		}
		global $wpdb;
		$hash   = md5( $key );
		$events = Install::tables()['events'];
		$mark   = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(MAX(id), 0) FROM %i WHERE voucher_id = %d', $events, $voucher->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( self::scan_insert( $hash, $sig, $mark ) ) {
			return null;
		}
		$row = self::scan_row( $hash );
		if ( null === $row ) {
			// Released by the request that held it just now: the scanner retries with the same key.
			return new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		if ( $row['sig'] !== $sig ) {
			return new \WP_Error( 'vcap_idempotency_conflict', __( 'This scanner request was already used for another action. Please scan the code again.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		if ( null === $row['response'] ) {
			if ( time() - $row['time'] < self::SCAN_STALE || null === $row['mark'] ) {
				return new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
			}
			$booked = self::booked_since( (int) $voucher->id, (int) $row['mark'], $kinds );
			if ( $booked ) {
				return $this->store_booked( $key, $voucher, $booked );
			}
			// Nothing was booked: take the claim over (only one repeat wins) and act.
			return self::scan_take_over( $hash, $row, $sig, $mark ) ? null : new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
		}
		$data             = (array) json_decode( $row['response'], true );
		$data['replayed'] = true;
		return $data;
	}

	/**
	 * Id of the first history event of one of $kinds that the current user wrote on the voucher after the event
	 * mark of a claim (0 = none): the action of the claim was booked.
	 *
	 * @param string[] $kinds History events the action writes.
	 */
	private static function booked_since( int $voucher_id, int $mark, array $kinds ): int {
		global $wpdb;
		$events = Install::tables()['events'];
		$in     = implode( ',', array_map( static fn( $k ) => "'" . esc_sql( (string) $k ) . "'", $kinds ) );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$events} WHERE voucher_id = %d AND id > %d AND user_id = %d AND event IN ({$in}) ORDER BY id LIMIT 1", $voucher_id, $mark, get_current_user_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** Stores and returns the answer "already booked" for a key whose action is found in the history. */
	private function store_booked( string $key, Voucher $voucher, int $event_id ): array {
		$fresh             = $this->repo->get( $voucher->id ) ?? $voucher;
		$data              = $this->describe( $fresh );
		$data['success']   = true;
		$data['state']     = 'warning';
		$data['recovered'] = true;
		$data['event_id']  = $event_id;
		$data['message']   = __( 'This action was already booked (found in the voucher history). Do not repeat it.', 'voucher-captain' );
		$this->scan_store( $key, $data );
		return $data;
	}

	/**
	 * M12-05: after an action failed (exception) or answered with an error, the key is released only when nothing
	 * was booked since the claim (the event mark stored with it). When the history shows the booking – e.g. a
	 * listener threw after the COMMIT – the answer "already booked" is stored and returned instead: the scanner's
	 * retry with the same key must never book a second time.
	 *
	 * @param string[] $kinds History events the action writes.
	 * @return array|null the stored answer, or null when the key was released
	 */
	private function scan_settle( string $key, Voucher $voucher, array $kinds ): ?array {
		if ( '' === $key ) {
			return null;
		}
		$row = self::scan_row( md5( $key ) );
		if ( $row && null !== $row['mark'] && null === $row['response'] ) {
			$booked = self::booked_since( (int) $voucher->id, (int) $row['mark'], $kinds );
			if ( $booked ) {
				return $this->store_booked( $key, $voucher, $booked );
			}
		}
		$this->scan_release( $key );
		return null;
	}

	/** M12-05: logs an exception of a scanner action whose booking is in the history (answered "already booked"). */
	private static function log_after_booking( \Throwable $e ): void {
		Logger::error( 'A scanner action failed after its booking (answered as already booked): ' . Mask::addresses( get_class( $e ) . ': ' . $e->getMessage() ) );
	}

	private function scan_store( string $key, $data ): void {
		if ( '' === $key ) {
			return;
		}
		global $wpdb;
		$json = (string) wp_json_encode( $data );
		if ( Install::counters_ready() ) {
			$wpdb->update( Install::tables()['scan'], array( 'response' => $json ), array( 'key_hash' => md5( $key ) ), array( '%s' ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return;
		}
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = CONCAT(SUBSTRING_INDEX(option_value, '|', 2), '|', %s) WHERE option_name = %s", $json, self::SCAN_PREFIX . md5( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Stored answer of a finished scanner action ('' answer or another action = null). */
	private function scan_result( string $key, string $sig ): ?array {
		$row = self::scan_row( md5( $key ) );
		return $row && null !== $row['response'] && $row['sig'] === $sig ? (array) json_decode( $row['response'], true ) : null;
	}

	private function scan_release( string $key ): void {
		if ( '' === $key ) {
			return;
		}
		global $wpdb;
		if ( Install::counters_ready() ) {
			$wpdb->delete( Install::tables()['scan'], array( 'key_hash' => md5( $key ) ), array( '%s' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! Install::legacy_throttle_rows() ) {
				return;
			}
		}
		$wpdb->delete( $wpdb->options, array( 'option_name' => self::SCAN_PREFIX . md5( $key ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/** Claims a key: true for exactly one request (INSERT IGNORE of a unique row). */
	private static function scan_insert( string $hash, string $sig, int $mark ): bool {
		global $wpdb;
		if ( Install::counters_ready() ) {
			self::adopt_legacy_scan_key( $hash );
			$table = Install::tables()['scan'];
			$wpdb->query( $wpdb->prepare( 'INSERT IGNORE INTO %i (key_hash, signature, event_mark, claimed_at, response) VALUES (%s, %s, %d, %d, NULL)', $table, $hash, $sig, $mark, time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1 === (int) $wpdb->rows_affected;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::SCAN_PREFIX . $hash, time() . '|' . $sig . '#' . $mark . '|' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $wpdb->rows_affected;
	}

	/**
	 * A stored key: array{sig:string, mark:int|null, time:int, response:string|null, raw?:string} (raw: the option
	 * value before schema 1.7, compared when a claim is taken over), or null.
	 */
	private static function scan_row( string $hash ): ?array {
		global $wpdb;
		if ( Install::counters_ready() ) {
			self::adopt_legacy_scan_key( $hash );
			$table = Install::tables()['scan'];
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT signature, event_mark, claimed_at, response FROM %i WHERE key_hash = %s', $table, $hash ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( ! $row ) {
				return null;
			}
			return array(
				'sig'      => (string) $row['signature'],
				'mark'     => null === $row['event_mark'] ? null : (int) $row['event_mark'],
				'time'     => (int) $row['claimed_at'],
				'response' => null === $row['response'] ? null : (string) $row['response'],
			);
		}
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::SCAN_PREFIX . $hash ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return null === $value ? null : self::parse_scan_option( (string) $value ) + array( 'raw' => (string) $value );
	}

	/** Takes over an abandoned claim without answer; true for exactly one request (compare-and-set). */
	private static function scan_take_over( string $hash, array $row, string $sig, int $mark ): bool {
		global $wpdb;
		if ( Install::counters_ready() ) {
			$table = Install::tables()['scan'];
			$taken = $wpdb->query( $wpdb->prepare( 'UPDATE %i SET claimed_at = %d, event_mark = %d WHERE key_hash = %s AND claimed_at = %d AND response IS NULL', $table, time(), $mark, $hash, $row['time'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return 1 === (int) $taken;
		}
		$taken = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", time() . '|' . $sig . '#' . $mark . '|', self::SCAN_PREFIX . $hash, (string) ( $row['raw'] ?? '' ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return 1 === (int) $taken;
	}

	/** Parts of a scanner key stored as option before schema 1.7: "{time}|{signature}#{event mark}|{answer}". */
	private static function parse_scan_option( string $value ): array {
		list( $time, $stored, $json ) = array_pad( explode( '|', $value, 3 ), 3, '' );
		list( $sig, $since )          = array_pad( explode( '#', $stored, 2 ), 2, '' );
		return array(
			'sig'      => $sig,
			'mark'     => '' === $since ? null : (int) $since,
			'time'     => (int) $time,
			'response' => '' === $json ? null : $json,
		);
	}

	/**
	 * Copies a scanner key stored as option before schema 1.7 into the table (kept when the table has the key
	 * already). Keys older than two days are not copied.
	 *
	 * @param string $hash  md5 of the key (the option name without its prefix).
	 * @param string $value Option value.
	 * @return bool false on a database error.
	 */
	public static function adopt_scan_option( string $hash, string $value ): bool {
		$row = self::parse_scan_option( $value );
		if ( ! preg_match( '/^[0-9a-f]{32}$/', $hash ) || $row['time'] < time() - self::SCAN_DAYS * DAY_IN_SECONDS ) {
			return true;
		}
		global $wpdb;
		$table = Install::tables()['scan'];
		// A key without event mark (written before the mark existed) or without answer keeps NULL (-1 / '').
		return false !== $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO %i (key_hash, signature, event_mark, claimed_at, response) VALUES (%s, %s, NULLIF(%d, -1), %d, NULLIF(%s, ''))", $table, $hash, substr( $row['sig'], 0, 32 ), $row['mark'] ?? -1, $row['time'], (string) $row['response'] ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * While options of the storage before schema 1.7 may exist (the upgrade moves them in batches), the option of
	 * this key is moved first, so a repeat of a request claimed just before the switch is still recognised.
	 */
	private static function adopt_legacy_scan_key( string $hash ): void {
		if ( ! Install::legacy_throttle_rows() ) {
			return;
		}
		global $wpdb;
		$name  = self::SCAN_PREFIX . $hash;
		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( null !== $value && self::adopt_scan_option( $hash, (string) $value ) ) {
			$wpdb->delete( $wpdb->options, array( 'option_name' => $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
	}

	/**
	 * Removes scanner idempotency keys older than two days (daily maintenance), in batches until done or $budget
	 * seconds are used: rows of the table vcap_scan_keys and options left from before schema 1.7.
	 *
	 * @return bool true when all old keys are removed.
	 */
	public static function purge_scan_keys( float $budget = 10.0 ): bool {
		global $wpdb;
		$start  = microtime( true );
		$limit  = 1000;
		$cutoff = time() - self::SCAN_DAYS * DAY_IN_SECONDS;
		$table  = Install::counters_ready() ? Install::tables()['scan'] : '';
		do {
			$deleted = 0;
			if ( '' !== $table ) {
				$deleted = $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE claimed_at < %d LIMIT %d', $table, $cutoff, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( false !== $deleted && (int) $deleted < $limit ) {
				$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT %d", $wpdb->esc_like( self::SCAN_PREFIX ) . '%', $cutoff, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			if ( false === $deleted ) {
				throw new \RuntimeException( 'Scanner keys could not be removed: ' . esc_html( $wpdb->last_error ) );
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
			$booked = $this->scan_settle( $key, $voucher, array( 'redeemed' ) );
			if ( null === $booked ) {
				throw $e;
			}
			self::log_after_booking( $e );
			return rest_ensure_response( $booked );
		}
		if ( is_wp_error( $response ) ) {
			// Every refusal is answered before a booking (an error never follows one): the same key may act later.
			// The history is not asked here – a parallel scan of the same user would be mistaken for this booking.
			$this->scan_release( $key );
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
		// M12-81: the fixed till of the staff member, else the device's one (from the managed list, if any).
		$till = Counter::device_till( (string) $request['till'] );
		if ( is_wp_error( $till ) ) {
			return $till;
		}
		// M12-31: the receipt number of the till receipt when the setting requires it (checked here, not only on the device).
		$receipt = Counter::clean_receipt( (string) $request['receipt'] );
		if ( '' === $receipt && Counter::receipt_required() ) {
			return new \WP_Error( 'vcap_receipt_required', __( 'Not redeemed: please enter the receipt number of the till receipt.', 'voucher-captain' ), array( 'status' => 400 ) );
		}
		// M12-24: a personal voucher only after the holder's ID was checked (online, the account proves it).
		$note = sanitize_text_field( (string) $request['note'] );
		if ( null !== self::personal_info( $voucher ) ) {
			if ( ! rest_sanitize_boolean( $request['id_checked'] ?? false ) ) {
				return new \WP_Error( 'vcap_id_check_required', __( 'Not redeemed: this voucher is personal. Check the holder\'s ID against the names shown and confirm "ID checked".', 'voucher-captain' ), array( 'status' => 409 ) );
			}
			$note = ( '' !== $note ? $note : __( 'Redeemed in store', 'voucher-captain' ) ) . ' · ' . __( 'ID checked', 'voucher-captain' );
		}
		$note = self::counter_note( $note, $receipt, $till );
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
			// R6: daily limits of the staff member and the till, checked and booked under their locks.
				// M12-61: checked with the amount asked for – the balance read before the row lock may be outdated
			// (a top-up in between), and a partial booking may deduct up to the requested amount.
			$limit = Counter::claim( $voucher, $amount, $till );
			if ( '' !== $limit['message'] ) {
				return $this->limit_refusal( $voucher, $limit );
			}
			$reason = '';
			try {
				$deduction = Counter::with(
					$till,
					$receipt,
					static function () use ( $voucher, $amount, $note, $partial, &$reason ) {
						return vcap()->coupons->deduct_balance_atomic( $voucher, $amount, $note, 0, $partial, $reason );
					}
				);
			} finally {
				Counter::release( $limit['locks'] );
			}
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
			$data['event_id']   = $this->own_last_redemption( $voucher->id );
			$data['slip']       = $this->slip( $fresh, $data['event_id'], $till, $receipt, $data['booked_html'] );
			return rest_ensure_response( $data );
		}
		$limit = Counter::claim( $voucher, Counter::value_of( $voucher ), $till );
		if ( '' !== $limit['message'] ) {
			return $this->limit_refusal( $voucher, $limit );
		}
		try {
			$redeemed = Counter::with( $till, $receipt, fn() => $this->redeem_single( $voucher, $note ) );
		} finally {
			Counter::release( $limit['locks'] );
		}
		if ( ! $redeemed ) {
			// Of two parallel redemptions (two tills, or till and online) only one wins.
			$data            = $this->describe( $this->repo->get( $voucher->id ) );
			$data['success'] = false;
			return rest_ensure_response( $data );
		}
		$fresh            = $this->repo->get( $voucher->id ) ?? $voucher;
		$data             = $this->describe( $fresh );
		$data['success']  = true;
		$data['state']    = 'ok';
		$data['message']  = __( 'Redeemed', 'voucher-captain' );
		$data['event_id'] = $this->own_last_redemption( $voucher->id );
		$data['slip']     = $this->slip( $fresh, $data['event_id'], $till, $receipt, (string) $data['value_html'] );
		return rest_ensure_response( $data );
	}

	/**
	 * Scanner answer for a counter redemption refused by a daily limit (Counter::claim()): nothing was booked.
	 *
	 * @param array $limit Result of Counter::claim().
	 */
	private function limit_refusal( Voucher $voucher, array $limit ): \WP_REST_Response {
		$data             = $this->describe( $this->repo->get( $voucher->id ) ?? $voucher );
		$data['success']  = false;
		$data['state']    = 'reserved' === $limit['reason'] ? 'warning' : 'error';
		$data['reason']   = $limit['reason'];
		$data['message']  = $limit['message'];
		$data['limit']    = $limit['exceeded'] ? array( 'scope' => $limit['scope'] ) + $limit['exceeded'] : null;
		$data['booked']   = 0.0;
		return rest_ensure_response( $data );
	}

	/**
	 * M12-31: data of the 80 mm redemption slip the scanner prints: shop, masked code, time, staff member, till,
	 * receipt and booking number, amount, remaining balance and the VAT classification. Never the full code: the
	 * slip may stay at the till or go to the customer.
	 *
	 * @return array<string, string|int>
	 */
	private function slip( Voucher $voucher, int $event_id, string $till, string $receipt, string $amount_html ): array {
		$user = wp_get_current_user();
		return array(
			'shop'      => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'code'      => Mask::code( $voucher->code ),
			'time'      => self::local_time( gmdate( 'Y-m-d H:i:s' ) ),
			'staff'     => $user instanceof \WP_User ? (string) $user->display_name : '',
			'till'      => $till,
			'receipt'   => $receipt,
			'booking'   => $event_id,
			'amount'    => $amount_html,
			'balance'   => $voucher->is_multi_use() ? self::money_text( $voucher->balance, $voucher->currency ) : '',
			'treatment' => self::tax_data( $voucher )['treatment_label'],
		);
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
			// Reusable vouchers: count one use, WooCommerce's usage limit decides when it is used up. The use is
			// counted with a conditional UPDATE first: of two tills scanning the last use of a 10-visit card at
			// the same time only one succeeds (no 11th visit), and only that one is booked.
			if ( $voucher->coupon_id && ! self::take_coupon_use( (int) $voucher->coupon_id ) ) {
				return false;
			}
			$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, null, $note );
			VoucherRepository::fire_redeemed( $voucher, (float) $voucher->initial_amount, 'counter' );
			return true;
		}
		if ( ! $this->repo->redeem_single_if_active( $voucher->id ) ) {
			return false;
		}
		$voucher->status  = Voucher::STATUS_REDEEMED;
		$voucher->balance = 0;
		$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, 0, $note );
		VoucherRepository::fire_redeemed( $voucher, (float) $voucher->initial_amount, 'counter' );
		CouponManager::sync_coupon( $voucher, array( 'usage' ) ); // Used up for WooCommerce's own usage limit too.
		return true;
	}

	/**
	 * Counts one use of a WooCommerce coupon atomically, only while it is below its usage limit (none = no
	 * limit). False when the coupon is used up (or gone).
	 */
	public static function take_coupon_use( int $coupon_id ): bool {
		global $wpdb;
		if ( 'shop_coupon' !== get_post_type( $coupon_id ) ) {
			return false;
		}
		$limit = (int) get_post_meta( $coupon_id, 'usage_limit', true );
		if ( $limit > 0 ) {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = 'usage_count' AND CAST(meta_value AS UNSIGNED) < %d", $coupon_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
		} else {
			$done = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->postmeta} SET meta_value = CAST(meta_value AS UNSIGNED) + 1 WHERE post_id = %d AND meta_key = 'usage_count'", $coupon_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
		}
		if ( 1 !== (int) $done ) {
			$has_count = null !== $wpdb->get_var( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = 'usage_count' LIMIT 1", $coupon_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery
			// A coupon without a usage count row yet: the first use creates it.
			if ( $has_count || ! add_post_meta( $coupon_id, 'usage_count', 1, true ) ) {
				wp_cache_delete( $coupon_id, 'post_meta' );
				return false;
			}
		}
		wp_cache_delete( $coupon_id, 'post_meta' );
		clean_post_cache( $coupon_id );
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
			// A missing reason is a bad request (400), not a conflict: the scanner keeps a 409 request for a retry.
			$status = 'forbidden' === $code ? 403 : ( 'reason' === $code ? 400 : 409 );
			return new \WP_Error( 'vcap_reversal_' . $code, self::reversal_message( $code ), array( 'status' => $status ) );
		}
		global $wpdb;
		// One cancellation per redemption, also with two clicks or two admins at once.
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", 'vcap_reversal_' . $event_id, (string) time() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 !== (int) $wpdb->rows_affected ) {
			return new \WP_Error( 'vcap_reversal_reversed', self::reversal_message( 'reversed' ), array( 'status' => 409 ) );
		}
		wp_cache_delete( 'vcap_reversal_' . $event_id, 'options' );
		// is_reversed() may have cached the option as missing (lookup, M12-45 list) in this request.
		$missing = wp_cache_get( 'notoptions', 'options' );
		if ( is_array( $missing ) && isset( $missing[ 'vcap_reversal_' . $event_id ] ) ) {
			unset( $missing[ 'vcap_reversal_' . $event_id ] );
			wp_cache_set( 'notoptions', $missing, 'options' );
		}
		$amount = (float) $event['amount'];
		/* translators: 1: history entry number of the redemption, 2: reason */
		$note = sprintf( __( 'Cancels redemption #%1$d: %2$s', 'voucher-captain' ), $event_id, $reason );
		$ok   = false;
		// R6: the cancellation is booked at the till of the redemption it cancels (till report, journal).
		Counter::push( (string) ( $event['till'] ?? '' ), (string) ( $event['receipt'] ?? '' ) );
		try {
			$ok = $this->book_reversal( $voucher, $amount, $note );
		} finally {
			Counter::pop();
		}
		if ( ! $ok ) {
			delete_option( 'vcap_reversal_' . $event_id );
			return new \WP_Error( 'vcap_reversal_failed', self::reversal_message( 'failed' ), array( 'status' => 500 ) );
		}
		/** A counter redemption was cancelled (amount booked back). */
		Db::action( 'vcap_redemption_reversed', $voucher->id, $event_id, $amount, $reason );
		return $amount;
	}

	/** Books the cancellation of a counter redemption back (balance, single-use state, coupon counter). */
	private function book_reversal( Voucher $voucher, float $amount, string $note ): bool {
		global $wpdb;
		$ok = false;
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
			$done  = (int) $wpdb->query( $wpdb->prepare( 'UPDATE %i SET status = %s, balance = initial_amount, updated_at = %s WHERE id = %d AND status = %s', $table, Voucher::STATUS_ACTIVE, gmdate( 'Y-m-d H:i:s' ), $voucher->id, Voucher::STATUS_REDEEMED ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			if ( 1 === $done ) {
				$ok    = $this->repo->log( $voucher->id, 'redeem_reversed', $amount, null, $note );
				$fresh = $this->repo->get( $voucher->id );
				// The counted use is given back; an active voucher keeps one use left (the API's refund rule).
				CouponManager::sync_coupon( $fresh ?? $voucher, array( 'return_use' ) );
				if ( $fresh ) {
					Db::action( 'vcap_voucher_updated', $fresh );
				}
			}
		}
		return (bool) $ok;
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
			$booked = $this->scan_settle( $key, $voucher, array( 'redeem_reversed' ) );
			if ( null === $booked ) {
				throw $e;
			}
			self::log_after_booking( $e );
			return rest_ensure_response( $booked );
		}
		if ( is_wp_error( $amount ) ) {
			$this->scan_release( $key ); // Refused before any booking.
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
			$key   = sanitize_key( wp_unslash( $_POST['vcap_checkin_key'] ?? '' ) );
			// AP-W1-2: a theme copy of ticket-status.php older than the one-time key cannot tell a resent form from a
			// new one; a key made up here would admit every resend. Refused (WooCommerce → Status lists the outdated
			// override, the scanner keeps working).
			$prior = '' === $key
				? new \WP_Error( 'vcap_checkin_key_missing', __( 'Check-in refused: this check-in form is outdated (theme copy of ticket-status.php without its one-time key). Please use the scanner or update the template.', 'voucher-captain' ) )
				: $this->scan_claim( $key, md5( 'checkin-page|' . $voucher->id ), $voucher, array( 'checkin' ) );
			if ( null === $prior ) {
				try {
					$ok = $this->checkin( $voucher, $count );
				} catch ( \Throwable $e ) {
					if ( null === $this->scan_settle( $key, $voucher, array( 'checkin' ) ) ) {
						throw $e;
					}
					self::log_after_booking( $e );
					wp_safe_redirect( add_query_arg( array( 'vcap_ticket' => $voucher->token, 'vcap_done' => $key ), home_url( '/' ) ), 303 );
					exit;
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
		// DONOTCACHEPAGE as well as no-cache headers: page caches such as LiteSpeed Cache must never keep a
		// "valid" status of a ticket that has been checked in since.
		Shortcodes::no_cache();
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
