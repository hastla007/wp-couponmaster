<?php
namespace WCVS;

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
		return add_query_arg( 'wcvs_ticket', $voucher->token, home_url( '/' ) );
	}

	public static function can_scan(): bool {
		return current_user_can( 'wcvs_scan_tickets' ) || current_user_can( 'manage_woocommerce' );
	}

	public function routes(): void {
		register_rest_route(
			'wcvs/v1',
			'/lookup',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'rest_lookup' ),
				'permission_callback' => array( self::class, 'can_scan' ),
				'args'                => array( 'q' => array( 'required' => true, 'type' => 'string' ) ),
			)
		);
		register_rest_route(
			'wcvs/v1',
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
			'wcvs/v1',
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
		if ( preg_match( '/[?&]wcvs_ticket=([a-f0-9]{48})/', $input, $m ) ) {
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
		}
		return array(
			'id'             => $voucher->id,
			'code'           => $voucher->code,
			'type'           => $voucher->type,
			'type_label'     => Voucher::types()[ $voucher->type ] ?? $voucher->type,
			'status'         => $voucher->status,
			'status_label'   => $voucher->status_label(),
			'state'          => $state,
			'message'        => $message,
			'product'        => $product ? $product->get_name() : '',
			'holder'         => $voucher->recipient_name ?: $voucher->buyer_name,
			'order'          => $voucher->order() ? $voucher->order()->get_order_number() : '',
			'checkins'       => $voucher->checkins,
			'max_checkins'   => $voucher->max_checkins,
			'last_checkin'   => $voucher->last_checkin_at ? get_date_from_gmt( $voucher->last_checkin_at, get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) : '',
			'multi_use'      => $voucher->is_multi_use(),
			'balance'        => $voucher->balance,
			'balance_html'   => html_entity_decode( wp_strip_all_tags( wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) ),
			'value_html'     => 'percent' === $voucher->discount_type ? $voucher->initial_amount . '%' : html_entity_decode( wp_strip_all_tags( wc_price( $voucher->initial_amount, array( 'currency' => $voucher->currency ) ) ) ),
			'expires'        => $voucher->expires_at ? get_date_from_gmt( $voucher->expires_at, get_option( 'date_format' ) ) : '',
			'admin_url'      => admin_url( 'admin.php?page=wcvs-vouchers&voucher=' . $voucher->id ),
		);
	}

	public function rest_lookup( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			return new \WP_Error( 'wcvs_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->describe( $voucher ) );
	}

	/**
	 * Atomic check-in: never exceeds the admission count, even with parallel scanners.
	 */
	public function checkin( Voucher $voucher, int $count = 1 ): bool {
		global $wpdb;
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
		do_action( 'wcvs_ticket_checked_in', $fresh, $count );
		return true;
	}

	public function undo_checkin( Voucher $voucher ): bool {
		if ( $voucher->checkins <= 0 ) {
			return false;
		}
		$voucher->checkins--;
		if ( Voucher::STATUS_REDEEMED === $voucher->status && $voucher->is_ticket() ) {
			$voucher->status = Voucher::STATUS_ACTIVE;
		}
		$this->repo->update( $voucher );
		$this->repo->log( $voucher->id, 'checkin_undo', 1 );
		return true;
	}

	public function rest_checkin( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			return new \WP_Error( 'wcvs_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		if ( $request['undo'] ) {
			$ok = $this->undo_checkin( $voucher );
		} else {
			$ok = $this->checkin( $voucher, (int) $request['count'] );
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
		return rest_ensure_response( $data );
	}

	/**
	 * In-store redemption: deduct an amount from a gift card or mark a single-use voucher as used.
	 */
	public function rest_redeem( \WP_REST_Request $request ) {
		$voucher = $this->resolve( (string) $request['q'] );
		if ( ! $voucher ) {
			return new \WP_Error( 'wcvs_not_found', __( 'No voucher or ticket found for this code.', 'voucher-captain' ), array( 'status' => 404 ) );
		}
		if ( ! $voucher->is_usable() || Voucher::STATUS_REDEEMED === $voucher->status ) {
			$data            = $this->describe( $voucher );
			$data['success'] = false;
			return rest_ensure_response( $data );
		}
		$note = sanitize_text_field( (string) $request['note'] ) ?: __( 'Redeemed in store', 'voucher-captain' );
		if ( $voucher->is_multi_use() ) {
			$amount = min( $voucher->balance, max( 0, (float) $request['amount'] ) );
			if ( $amount <= 0 ) {
				return new \WP_Error( 'wcvs_amount', __( 'Please enter an amount.', 'voucher-captain' ), array( 'status' => 400 ) );
			}
			wcvs()->coupons->set_balance( $voucher, $voucher->balance - $amount, 'redeemed', $amount, $note );
		} else {
			$voucher->status  = Voucher::STATUS_REDEEMED;
			$voucher->balance = 0;
			$this->repo->update( $voucher );
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
		if ( empty( $_GET['wcvs_ticket'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$voucher = $this->repo->get_by_token( sanitize_key( wp_unslash( $_GET['wcvs_ticket'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$notice  = '';

		if ( $voucher && self::can_scan() && isset( $_POST['wcvs_checkin_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wcvs_checkin_nonce'] ) ), 'wcvs_checkin_' . $voucher->id ) ) {
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
				'public'  => 'yes' === get_option( 'wcvs_ticket_public_status', 'yes' ),
				'notice'  => $notice,
			)
		);
		exit;
	}
}
