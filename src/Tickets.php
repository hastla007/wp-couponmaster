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
				'methods'             => 'GET',
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
					'q'     => array( 'required' => true, 'type' => 'string' ),
					'count' => array( 'type' => 'integer', 'default' => 1 ),
					'undo'  => array( 'type' => 'boolean', 'default' => false ),
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
					'q'      => array( 'required' => true, 'type' => 'string' ),
					'amount' => array( 'type' => 'number' ),
					'note'   => array( 'type' => 'string', 'default' => '' ),
				),
			)
		);
	}

	/**
	 * Accepts a raw code, a token, or a scanned check-in URL.
	 */
	public function resolve( string $input ): ?Voucher {
		$input = trim( $input );
		if ( preg_match( '/[?&]vcap_ticket=([a-f0-9]{48})/', $input, $m ) ) {
			return $this->repo->get_by_token( $m[1] );
		}
		if ( preg_match( '/^[a-f0-9]{48}$/', $input ) ) {
			$voucher = $this->repo->get_by_token( $input );
			if ( $voucher ) {
				return $voucher;
			}
		}
		$code = wc_format_coupon_code( sanitize_text_field( $input ) );
		return $this->repo->get_by_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) );
	}

	/** Whether the scanner shows the holder's name (setting "vcap_scanner_show_holder"; personal tickets always). */
	public static function show_holder( Voucher $voucher ): bool {
		$show = 'yes' === get_option( 'vcap_scanner_show_holder', 'no' ) || ( $voucher->is_ticket() && '' !== (string) $voucher->recipient_name );
		return (bool) apply_filters( 'vcap_scanner_show_holder', $show, $voucher );
	}

	public function describe( Voucher $voucher ): array {
		$product = $voucher->product();
		$state   = 'ok';
		$message = __( 'Valid', 'voucher-captain' );
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			$state   = 'error';
			$message = __( 'Cancelled', 'voucher-captain' );
		} elseif ( $voucher->is_expired() || Voucher::STATUS_EXPIRED === $voucher->status ) {
			$state   = 'error';
			$message = __( 'Expired', 'voucher-captain' );
		} elseif ( $voucher->is_ticket() && $voucher->checkins >= $voucher->max_checkins ) {
			$state   = 'warning';
			$message = __( 'Already checked in', 'voucher-captain' );
		} elseif ( ! $voucher->is_ticket() && Voucher::STATUS_REDEEMED === $voucher->status ) {
			$state   = 'warning';
			$message = __( 'Already redeemed', 'voucher-captain' );
		} elseif ( Voucher::STATUS_SCHEDULED === $voucher->status ) {
			$state   = 'warning';
			$message = __( 'Not yet delivered', 'voucher-captain' );
		} elseif ( Voucher::STATUS_RESERVED === $voucher->status ) {
			$state   = 'warning';
			$message = __( 'Currently being redeemed elsewhere', 'voucher-captain' );
		}
		$manager = current_user_can( 'vcap_manage_vouchers' ) || current_user_can( 'manage_woocommerce' );
		$data    = array(
			'id'             => $voucher->id,
			'code'           => $voucher->code,
			'type'           => $voucher->type,
			'type_label'     => Voucher::types()[ $voucher->type ] ?? $voucher->type,
			'status'         => $voucher->status,
			'status_label'   => $voucher->status_label(),
			'state'          => $state,
			'message'        => $message,
			'product'        => $product ? $product->get_name() : '',
			// Privacy at the door: the name only when enabled (or for personal tickets, where it is checked);
			// order number and original value only for staff who manage vouchers.
			'holder'         => self::show_holder( $voucher ) ? ( $voucher->recipient_name ?: $voucher->buyer_name ) : '',
			'order'          => $manager && $voucher->order() ? $voucher->order()->get_order_number() : '',
			'checkins'       => $voucher->checkins,
			'max_checkins'   => $voucher->max_checkins,
			'last_checkin'   => $voucher->last_checkin_at ? get_date_from_gmt( $voucher->last_checkin_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
			'multi_use'      => $voucher->is_multi_use(),
			'balance'        => $voucher->balance,
			'balance_html'   => html_entity_decode( wp_strip_all_tags( wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) ),
			'value_html'     => ! $manager ? '' : ( 'percent' === $voucher->discount_type ? $voucher->initial_amount . '%' : html_entity_decode( wp_strip_all_tags( wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) ) ) ) ),
			'expires'        => $voucher->expires_at ? get_date_from_gmt( $voucher->expires_at, get_option( 'date_format' ) ) : '',
			'admin_url'      => admin_url( 'admin.php?page=vcap-vouchers&voucher=' . $voucher->id ),
		);
		/** Connector details for the scanner (e.g. "ticket voucher – exchange first", redeemable false). */
		return (array) apply_filters( 'vcap_scanner_describe', $data, $voucher );
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

	public function undo_checkin( Voucher $voucher ): bool {
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
		$this->repo->log( $voucher->id, 'checkin_undo', 1 );
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
		$key   = sanitize_text_field( (string) $request['idempotency_key'] );
		$sig   = md5( 'checkin|' . $voucher->id . '|' . ( $request['undo'] ? 'undo' : max( 1, (int) $request['count'] ) ) );
		$prior = $this->scan_claim( $key, $sig );
		if ( null !== $prior ) {
			return is_wp_error( $prior ) ? $prior : rest_ensure_response( $prior );
		}
		try {
			$ok = $request['undo'] ? $this->undo_checkin( $voucher ) : $this->checkin( $voucher, (int) $request['count'] );
		} catch ( \Throwable $e ) {
			$this->scan_release( $key );
			throw $e;
		}
		$data            = $this->describe( $this->repo->get( $voucher->id ) );
		$data['success'] = $ok;
		if ( $ok ) {
			$data['state']   = 'ok';
			$data['message'] = $request['undo'] ? __( 'Check-in reverted', 'voucher-captain' ) : __( 'Checked in', 'voucher-captain' );
		} elseif ( 'ok' === $data['state'] ) {
			$data['state']   = 'error';
			$data['message'] = __( 'Check-in not possible.', 'voucher-captain' );
		}
		$this->scan_store( $key, $data );
		return rest_ensure_response( $data );
	}

	private const SCAN_PREFIX = '_vcap_scan_';

	/**
	 * Idempotency of native scanner mutations (check-in, undo, counter redemption). The first request with a key
	 * claims it atomically (unique option row); a repeat with the same key gets the stored answer, a repeat
	 * while the first is still running gets 409 (the scanner retries with the same key), and a key reused
	 * for another action is refused. Keys are kept two days.
	 *
	 * @return array|\WP_Error|null stored response, error, or null when this request may act
	 */
	private function scan_claim( string $key, string $sig ) {
		if ( '' === $key ) {
			return null;
		}
		global $wpdb;
		$name = self::SCAN_PREFIX . md5( $key );
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", $name, time() . '|' . $sig . '|' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( 1 === (int) $wpdb->rows_affected ) {
			return null;
		}
		$row                      = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		list( , $stored, $json )  = array_pad( explode( '|', $row, 3 ), 3, '' );
		if ( $stored !== $sig ) {
			return new \WP_Error( 'vcap_idempotency_conflict', __( 'This scanner request was already used for another action. Please scan the code again.', 'voucher-captain' ), array( 'status' => 422 ) );
		}
		if ( '' === $json ) {
			return new \WP_Error( 'vcap_in_progress', __( 'This action is still being processed. Please wait a moment and try again.', 'voucher-captain' ), array( 'status' => 409 ) );
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
		$prior = $this->scan_claim( $key, md5( 'redeem|' . $voucher->id . '|' . round( max( 0, (float) $request['amount'] ), 4 ) ) );
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

	/** @return \WP_REST_Response|\WP_Error */
	private function redeem_at_counter( \WP_REST_Request $request, Voucher $voucher ) {
		if ( ! $voucher->is_usable() || Voucher::STATUS_REDEEMED === $voucher->status ) {
			$data            = $this->describe( $voucher );
			$data['success'] = false;
			return rest_ensure_response( $data );
		}
		// Same rules as the redemption API: store credit belongs to a customer account, tickets are checked in
		// (not redeemed), and a voucher restricted to other channels is not valid at the counter.
		$refused = '';
		if ( 'credit' === $voucher->type ) {
			$refused = __( 'Store credit can only be used in the shop.', 'voucher-captain' );
		} elseif ( $voucher->is_ticket() ) {
			$refused = __( 'Tickets are checked in at the entrance and cannot be redeemed here.', 'voucher-captain' );
		} elseif ( ! Channels::allowed( $voucher, Channels::COUNTER ) ) {
			$refused = __( 'This voucher cannot be redeemed at the counter.', 'voucher-captain' );
		} elseif ( \VCAP\CouponManager::held_by_pending_order( $voucher ) ) {
			$refused = __( 'This voucher is in an unpaid shop order right now. Redeem it after that order is cancelled.', 'voucher-captain' );
		}
		/** Vouchers that must not be redeemed at the counter (e.g. ticket vouchers: exchange them for tickets). */
		$refused = apply_filters( 'vcap_scanner_redeem_refused', $refused, $voucher );
		if ( '' !== (string) $refused ) {
			$data            = $this->describe( $voucher );
			$data['success'] = false;
			$data['state']   = 'error';
			$data['message'] = (string) $refused;
			return rest_ensure_response( $data );
		}
		$note = sanitize_text_field( (string) $request['note'] ) ?: __( 'Redeemed in store', 'voucher-captain' );
		if ( $voucher->is_multi_use() ) {
			$amount = max( 0, (float) $request['amount'] );
			if ( $amount <= 0 ) {
				return new \WP_Error( 'vcap_amount', __( 'Please enter an amount.', 'voucher-captain' ), array( 'status' => 400 ) );
			}
			$deduction = vcap()->coupons->deduct_balance_atomic( $voucher, $amount, $note );
			if ( ! $deduction ) {
				$fresh           = $this->repo->get( $voucher->id );
				$data            = $this->describe( $fresh );
				$data['success'] = false;
				$data['state']   = 'error';
				/* translators: %s: remaining balance */
				$data['message'] = sprintf( __( 'Not deducted: the amount exceeds the balance (remaining %s).', 'voucher-captain' ), $data['balance_html'] );
				return rest_ensure_response( $data );
			}
			$voucher = $this->repo->get( $voucher->id );
		} elseif ( 'unlimited' === $voucher->usage_mode ) {
			// Reusable vouchers: count one use, WooCommerce's usage limit decides when it is used up.
			$this->repo->log( $voucher->id, 'redeemed', $voucher->initial_amount, null, $note );
			if ( $voucher->coupon_id ) {
				$coupon = new \WC_Coupon( $voucher->coupon_id );
				if ( $coupon->get_id() ) {
					$coupon->increase_usage_count();
				}
			}
		} else {
			// Atomic: of two parallel redemptions (two tills, or till and online) only one wins.
			if ( ! $this->repo->redeem_single_if_active( $voucher->id ) ) {
				$data            = $this->describe( $this->repo->get( $voucher->id ) );
				$data['success'] = false;
				return rest_ensure_response( $data );
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
		}
		$data            = $this->describe( $this->repo->get( $voucher->id ) );
		$data['success'] = true;
		$data['state']   = 'ok';
		$data['message'] = __( 'Redeemed', 'voucher-captain' );
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

		if ( $voucher && self::can_scan() && isset( $_POST['vcap_checkin_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_checkin_nonce'] ) ), 'vcap_checkin_' . $voucher->id ) ) {
			$count  = max( 1, absint( $_POST['count'] ?? 1 ) );
			$notice = $this->checkin( $voucher, $count ) ? __( 'Checked in', 'voucher-captain' ) : __( 'Check-in not possible.', 'voucher-captain' );
			$voucher = $this->repo->get( $voucher->id );
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
				'voucher' => $voucher,
				'data'    => $data,
				'staff'   => self::can_scan(),
				'public'  => 'yes' === get_option( 'vcap_ticket_public_status', 'yes' ),
				'notice'  => $notice,
			)
		);
		exit;
	}
}
