<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Secure PDF downloads (HMAC-signed, optionally expiring links) + display on order pages and My Account.
 */
final class Downloads {
	public const ENDPOINT = 'vouchers';

	public function __construct( private Settings $settings, private VoucherRepository $repo, private PdfRenderer $pdf ) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );
		add_action( 'template_redirect', array( $this, 'customer_resend' ), 5 );
		add_action( 'admin_post_vcap_admin_pdf', array( $this, 'admin_download' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_downloads' ), 5 );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'account_page' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_shortcode( 'vcap_balance', array( $this, 'balance_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
	}

	public function styles(): void {
		if ( is_account_page() || is_order_received_page() || is_product() || is_cart() || is_checkout() ) {
			wp_enqueue_style( 'vcap-frontend', VCAP_URL . 'assets/css/frontend.css', array(), VCAP_VERSION );
		}
	}

	private function sign( string $token, int $exp ): string {
		return hash_hmac( 'sha256', $token . '|' . $exp, $this->settings->secret() );
	}

	private function valid_guest_link( string $token, int $exp, string $signature ): bool {
		return hash_equals( $this->sign( $token, $exp ), $signature ) && ( 0 === $exp || $exp >= time() );
	}

	/**
	 * @param bool $for_email Email links honour the configured lifetime; page links are short-lived and re-signed per view.
	 */
	public function signed_url( Voucher $voucher, bool $for_email = false ): string {
		if ( $for_email ) {
			$days = (int) $this->settings->get( 'vcap_download_link_days' );
			$exp  = $days > 0 ? time() + $days * DAY_IN_SECONDS : 0;
		} else {
			$exp = time() + DAY_IN_SECONDS;
		}
		return add_query_arg(
			array(
				'vcap_pdf' => $voucher->token,
				'exp'      => $exp,
				'sig'      => $this->sign( $voucher->token, $exp ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Buyers may resend their own delivered vouchers from My Account (not scheduled gifts – that would spoil them).
	 */
	public function customer_can_resend( Voucher $voucher ): bool {
		$order = $voucher->order();
		return is_user_logged_in()
			&& $order instanceof \WC_Order
			&& (int) $order->get_customer_id() === get_current_user_id()
			&& null !== $voucher->delivered_at
			&& in_array( $voucher->status, array( Voucher::STATUS_ACTIVE, Voucher::STATUS_REDEEMED ), true )
			&& (bool) apply_filters( 'vcap_customer_can_resend', true, $voucher );
	}

	/**
	 * POST handler for the "Send email again" button. Limited to 3 emails per voucher and hour.
	 */
	public function customer_resend(): void {
		if ( empty( $_POST['vcap_resend'] ) || empty( $_POST['vcap_resend_nonce'] ) ) {
			return;
		}
		$id = absint( $_POST['vcap_resend'] );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_resend_nonce'] ) ), 'vcap_resend_' . $id ) ) {
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error' );
			return;
		}
		$voucher = $this->repo->get( $id );
		if ( ! $voucher || ! $this->customer_can_resend( $voucher ) ) {
			wc_add_notice( __( 'This voucher cannot be sent again.', 'voucher-captain' ), 'error' );
			return;
		}
		$key   = 'vcap_resend_' . $voucher->id;
		$count = (int) get_transient( $key );
		if ( $count >= 3 ) {
			wc_add_notice( __( 'This voucher was sent several times already. Please try again later.', 'voucher-captain' ), 'error' );
			return;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		$sent = vcap()->delivery->send( $voucher );
		$this->repo->log( $voucher->id, 'resent', null, null, sprintf( 'Requested by customer, %d recipient(s)', count( $sent ) ) );
		if ( $sent ) {
			wc_add_notice( __( 'The voucher email has been sent again.', 'voucher-captain' ) );
		} else {
			wc_add_notice( __( 'The voucher email could not be sent. Please contact us.', 'voucher-captain' ), 'error' );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}

	public function admin_url( Voucher $voucher, bool $download = false ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=vcap_admin_pdf&id=' . $voucher->id . ( $download ? '&dl=1' : '' ) ), 'vcap_admin_pdf_' . $voucher->id );
	}

	public function maybe_serve(): void {
		if ( empty( $_GET['vcap_pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['vcap_pdf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$exp   = absint( $_GET['exp'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$sig   = sanitize_key( wp_unslash( $_GET['sig'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$valid = $this->valid_guest_link( $token, $exp, $sig );
		$voucher = $valid ? $this->repo->get_by_token( $token ) : null;
		if ( ! $voucher ) {
			wp_die( esc_html__( 'This download link is invalid or has expired. Please log in to your account or contact us for a new link.', 'voucher-captain' ), esc_html__( 'Download unavailable', 'voucher-captain' ), array( 'response' => 403 ) );
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			wp_die( esc_html__( 'This voucher has been cancelled.', 'voucher-captain' ), '', array( 'response' => 410 ) );
		}
		$this->serve( $voucher, ! empty( $_GET['dl'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	public function admin_download(): void {
		$id = absint( $_GET['id'] ?? 0 );
		check_admin_referer( 'vcap_admin_pdf_' . $id );
		if ( ! current_user_can( 'vcap_manage_vouchers' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		$voucher = $this->repo->get( $id );
		if ( ! $voucher ) {
			wp_die( esc_html__( 'Voucher not found.', 'voucher-captain' ), 404 );
		}
		$this->serve( $voucher, ! empty( $_GET['dl'] ), false );
	}

	private function serve( Voucher $voucher, bool $download, bool $log = true ): void {
		if ( ! class_exists( '\Dompdf\Dompdf' ) ) {
			wp_die( esc_html__( 'PDF generation is not available.', 'voucher-captain' ), 500 );
		}
		if ( $log ) {
			$this->repo->log( $voucher->id, 'downloaded' );
		}
		Preview::stream( $this->pdf->render( $voucher ), $this->pdf->filename( $voucher ), ! $download );
	}

	/**
	 * Voucher downloads under the order details (order received page + My Account → View order).
	 *
	 * The same hook also runs on the order tracking page ([woocommerce_order_tracking]: order number + billing
	 * email), where knowing an email address would be enough to collect codes of guessed order numbers. Full
	 * codes and PDF links are therefore shown only to whoever may see them: the order received page with the
	 * order key, or the logged-in customer of the order. Everywhere else the codes are masked, without links.
	 */
	public function order_downloads( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$is_thankyou = is_order_received_page();
		if ( ( $is_thankyou && ! $this->settings->yes( 'vcap_show_thankyou' ) ) || ( ! $is_thankyou && ! $this->settings->yes( 'vcap_show_myaccount' ) ) ) {
			return;
		}
		$vouchers = $this->repo->for_order( $order->get_id() );
		if ( ! $vouchers ) {
			if ( $this->order_has_voucher_products( $order ) && ! $order->is_paid() ) {
				echo '<p class="vcap-pending-notice">' . esc_html__( 'Your vouchers will be available here once the payment has been confirmed.', 'voucher-captain' ) . '</p>';
			}
			return;
		}
		if ( ! $this->may_see_codes( $order ) ) {
			$this->masked_list( $vouchers );
			return;
		}
		Plugin::template(
			'myaccount/vouchers-table.php',
			array(
				'vouchers'  => $vouchers,
				'downloads' => $this,
				'title'     => __( 'Your vouchers', 'voucher-captain' ),
			)
		);
	}

	/**
	 * Whether the current visitor may see the full codes and PDF links of an order: the order received page
	 * with the order's key, or the logged-in customer of the order (My Account → View order).
	 */
	public function may_see_codes( \WC_Order $order ): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the order key is the credential here.
		$key     = isset( $_GET['key'] ) ? wc_clean( wp_unslash( (string) $_GET['key'] ) ) : '';
		$allowed = ( is_order_received_page() && '' !== $key && hash_equals( (string) $order->get_order_key(), (string) $key ) )
			|| ( is_user_logged_in() && (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === get_current_user_id() );
		/** Whether the current visitor may see the full voucher codes and PDF links of an order (default: order key or own order). */
		return (bool) apply_filters( 'vcap_order_codes_visible', $allowed, $order );
	}

	/** Last four characters of a code, e.g. "…7KQ2" (the code is a means of payment). */
	public static function masked_code( string $code ): string {
		return '…' . substr( strtoupper( $code ), -4 );
	}

	/**
	 * @param Voucher[] $vouchers
	 */
	private function masked_list( array $vouchers ): void {
		echo '<section class="vcap-vouchers vcap-vouchers-masked"><h2 class="woocommerce-column__title">' . esc_html__( 'Your vouchers', 'voucher-captain' ) . '</h2><ul>';
		foreach ( $vouchers as $voucher ) {
			$product = $voucher->product();
			echo '<li>' . esc_html( ( $product ? $product->get_name() : __( 'Voucher', 'voucher-captain' ) ) . ' · ' . self::masked_code( $voucher->code ) . ' · ' . $voucher->status_label() ) . '</li>';
		}
		echo '</ul><p class="vcap-masked-hint">' . esc_html__( 'For security reasons the full codes are shown only in the customer account of the buyer and in the voucher email.', 'voucher-captain' ) . '</p></section>';
	}

	private function order_has_voucher_products( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product() && ProductConfig::is_voucher( $item->get_product() ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * WooCommerce registers the My Account endpoint itself from woocommerce_get_query_vars (see query_vars());
	 * we only flush once after activation / settings changes, after every plugin and theme registered its rules.
	 */
	public function maybe_flush_rewrite(): void {
		if ( 'yes' === get_option( 'vcap_flush_rewrite', 'yes' ) ) {
			flush_rewrite_rules( false );
			update_option( 'vcap_flush_rewrite', 'no' );
		}
	}

	public function query_vars( array $vars ): array {
		if ( $this->settings->yes( 'vcap_myaccount_tab' ) ) {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
		}
		return $vars;
	}

	public function menu_item( array $items ): array {
		if ( ! $this->settings->yes( 'vcap_myaccount_tab' ) ) {
			return $items;
		}
		$new = array();
		foreach ( $items as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'orders' === $key ) {
				$new[ self::ENDPOINT ] = __( 'Vouchers', 'voucher-captain' );
			}
		}
		if ( ! isset( $new[ self::ENDPOINT ] ) ) {
			$new[ self::ENDPOINT ] = __( 'Vouchers', 'voucher-captain' );
		}
		return $new;
	}

	public function endpoint_title(): string {
		return __( 'Vouchers', 'voucher-captain' );
	}

	public function account_page(): void {
		$user = wp_get_current_user();
		do_action( 'vcap_account_vouchers_top', $user );
		$list = $this->repo->for_customer( (int) $user->ID );
		Plugin::template(
			'myaccount/vouchers-table.php',
			array(
				'vouchers'  => $list,
				'downloads' => $this,
				'title'     => '',
				'show_order'=> true,
			)
		);
	}

	/**
	 * [vcap_balance] – lets customers check the remaining value of a voucher code.
	 */
	/** Status line of a code, by voucher kind: balance, value, percentage, admission ticket or Pass Captain tickets. */
	private function balance_result( Voucher $voucher ): string {
		$parts  = array(
			/* translators: %s: voucher status */
			'status' => sprintf( __( 'Status: %s', 'voucher-captain' ), $voucher->status_label() ),
		);
		$money  = static fn( float $amount ): string => html_entity_decode( wp_strip_all_tags( wc_price( $amount, array( 'currency' => $voucher->currency ) ) ) );
		$target = Compat\PassCaptainTickets::target( $voucher );
		if ( $target ) {
			$parts['value'] = $target['label']
				/* translators: 1: number of tickets, 2: event / ticket type */
				? sprintf( _n( 'For %1$d ticket: %2$s', 'For %1$d tickets: %2$s', $target['quantity'], 'voucher-captain' ), $target['quantity'], $target['label'] )
				/* translators: %d: number of tickets */
				: sprintf( _n( 'For %d ticket', 'For %d tickets', $target['quantity'], 'voucher-captain' ), $target['quantity'] );
		} elseif ( $voucher->is_ticket() ) {
			$parts['value'] = __( 'Admission ticket (shown at the entrance)', 'voucher-captain' );
		} elseif ( 'percent' === $voucher->discount_type ) {
			/* translators: %s: percentage */
			$parts['value'] = sprintf( __( 'Discount: %s', 'voucher-captain' ), wc_format_localized_decimal( $voucher->initial_amount ) . '%' );
		} elseif ( $voucher->is_multi_use() ) {
			/* translators: %s: remaining balance */
			$parts['value'] = sprintf( __( 'Balance: %s', 'voucher-captain' ), $money( $voucher->balance ) );
		} else {
			/* translators: %s: voucher value */
			$parts['value'] = sprintf( __( 'Value: %s', 'voucher-captain' ), $money( $voucher->initial_amount ) );
		}
		/* translators: %s: expiry date or "unlimited" */
		$parts['expiry'] = sprintf( __( 'Valid until: %s', 'voucher-captain' ), $voucher->expires_at ? wp_date( $this->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) : __( 'unlimited', 'voucher-captain' ) );
		/** Parts of the [vcap_balance] result line (status, value, expiry), plain text. */
		$parts = (array) apply_filters( 'vcap_balance_result_parts', $parts, $voucher );
		$html  = esc_html( implode( ' · ', array_map( 'strval', $parts ) ) );
		$url   = $target && $voucher->is_usable() ? Shortcodes::redeem_url( $voucher->code ) : '';
		if ( '' !== $url ) {
			$html .= ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Exchange for tickets', 'voucher-captain' ) . '</a>';
		}
		// role=status: screen readers announce the result after the form was sent (page reload).
		return '<div class="woocommerce-info vcap-balance-result" role="status">' . $html . '</div>';
	}

	public function balance_shortcode(): string {
		$result = '';
		if ( isset( $_POST['vcap_balance_code'], $_POST['vcap_balance_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_balance_nonce'] ) ), 'vcap_balance' ) ) {
			if ( Plugin::rate_limited( 'balance', 15, 300, 15 * MINUTE_IN_SECONDS, false ) ) {
				$result = '<p class="woocommerce-error" role="alert">' . esc_html__( 'Too many attempts. Please try again later.', 'voucher-captain' ) . '</p>';
			} else {
				$code    = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST['vcap_balance_code'] ) ) );
				$voucher = $this->repo->get_by_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) );
				if ( ! $voucher ) {
					Plugin::rate_limit_failure( 'balance', 15 * MINUTE_IN_SECONDS );
					$result = '<p class="woocommerce-error" role="alert">' . esc_html__( 'Voucher code not found.', 'voucher-captain' ) . '</p>';
				} else {
					Plugin::rate_limit_success( 'balance', 15 * MINUTE_IN_SECONDS );
					$result = $this->balance_result( $voucher );
				}
			}
		}
		ob_start();
		?>
		<form method="post" class="vcap-balance-form">
			<?php wp_nonce_field( 'vcap_balance', 'vcap_balance_nonce' ); ?>
			<label for="vcap-balance-code"><?php esc_html_e( 'Voucher code', 'voucher-captain' ); ?></label>
			<input type="text" id="vcap-balance-code" name="vcap_balance_code" required autocomplete="off" />
			<button type="submit" class="button"><?php esc_html_e( 'Check balance', 'voucher-captain' ); ?></button>
		</form>
		<?php
		return $result . ob_get_clean();
	}
}
