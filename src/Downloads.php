<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * Secure PDF downloads (HMAC-signed, optionally expiring links) + display on order pages and My Account.
 */
final class Downloads {
	public const ENDPOINT = 'vouchers';

	public function __construct( private Settings $settings, private VoucherRepository $repo, private PdfRenderer $pdf ) {}

	public function register(): void {
		add_action( 'template_redirect', array( $this, 'maybe_serve' ), 1 );
		add_action( 'admin_post_wcvs_admin_pdf', array( $this, 'admin_download' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_downloads' ), 5 );
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'account_page' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_shortcode( 'wcvs_balance', array( $this, 'balance_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
	}

	public function styles(): void {
		if ( is_account_page() || is_order_received_page() || is_product() || is_cart() || is_checkout() ) {
			wp_enqueue_style( 'wcvs-frontend', WCVS_URL . 'assets/css/frontend.css', array(), WCVS_VERSION );
		}
	}

	private function sign( string $token, int $exp ): string {
		return hash_hmac( 'sha256', $token . '|' . $exp, $this->settings->secret() );
	}

	/**
	 * @param bool $for_email Email links honour the configured lifetime; page links are short-lived and re-signed per view.
	 */
	public function signed_url( Voucher $voucher, bool $for_email = false ): string {
		if ( $for_email ) {
			$days = (int) $this->settings->get( 'wcvs_download_link_days' );
			$exp  = $days > 0 ? time() + $days * DAY_IN_SECONDS : 0;
		} else {
			$exp = time() + DAY_IN_SECONDS;
		}
		return add_query_arg(
			array(
				'wcvs_pdf' => $voucher->token,
				'exp'      => $exp,
				'sig'      => $this->sign( $voucher->token, $exp ),
			),
			home_url( '/' )
		);
	}

	public function admin_url( Voucher $voucher, bool $download = false ): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=wcvs_admin_pdf&id=' . $voucher->id . ( $download ? '&dl=1' : '' ) ), 'wcvs_admin_pdf_' . $voucher->id );
	}

	public function maybe_serve(): void {
		if ( empty( $_GET['wcvs_pdf'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$token = sanitize_key( wp_unslash( $_GET['wcvs_pdf'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$exp   = absint( $_GET['exp'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification
		$sig   = sanitize_key( wp_unslash( $_GET['sig'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification

		$valid = hash_equals( $this->sign( $token, $exp ), $sig ) && ( 0 === $exp || $exp >= time() );
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
		check_admin_referer( 'wcvs_admin_pdf_' . $id );
		if ( ! current_user_can( 'wcvs_manage_vouchers' ) && ! current_user_can( 'manage_woocommerce' ) ) {
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
	 */
	public function order_downloads( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		$is_thankyou = is_order_received_page();
		if ( ( $is_thankyou && ! $this->settings->yes( 'wcvs_show_thankyou' ) ) || ( ! $is_thankyou && ! $this->settings->yes( 'wcvs_show_myaccount' ) ) ) {
			return;
		}
		$vouchers = $this->repo->for_order( $order->get_id() );
		if ( ! $vouchers ) {
			if ( $this->order_has_voucher_products( $order ) && ! $order->is_paid() ) {
				echo '<p class="wcvs-pending-notice">' . esc_html__( 'Your vouchers will be available here once the payment has been confirmed.', 'voucher-captain' ) . '</p>';
			}
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

	private function order_has_voucher_products( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product() && ProductConfig::is_voucher( $item->get_product() ) ) {
				return true;
			}
		}
		return false;
	}

	public function add_endpoint(): void {
		if ( $this->settings->yes( 'wcvs_myaccount_tab' ) ) {
			add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		}
		if ( 'yes' === get_option( 'wcvs_flush_rewrite', 'yes' ) ) {
			flush_rewrite_rules( false );
			update_option( 'wcvs_flush_rewrite', 'no' );
		}
	}

	public function query_vars( array $vars ): array {
		if ( $this->settings->yes( 'wcvs_myaccount_tab' ) ) {
			$vars[ self::ENDPOINT ] = self::ENDPOINT;
		}
		return $vars;
	}

	public function menu_item( array $items ): array {
		if ( ! $this->settings->yes( 'wcvs_myaccount_tab' ) ) {
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
		$list = $this->repo->for_customer( $user->ID, (string) $user->user_email );
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
	 * [wcvs_balance] – lets customers check the remaining value of a voucher code.
	 */
	public function balance_shortcode(): string {
		$result = '';
		if ( isset( $_POST['wcvs_balance_code'], $_POST['wcvs_balance_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wcvs_balance_nonce'] ) ), 'wcvs_balance' ) ) {
			$key   = 'wcvs_bal_' . md5( (string) \WC_Geolocation::get_ip_address() );
			$tries = (int) get_transient( $key );
			set_transient( $key, $tries + 1, 15 * MINUTE_IN_SECONDS );
			if ( $tries > 15 ) {
				$result = '<p class="woocommerce-error">' . esc_html__( 'Too many attempts. Please try again later.', 'voucher-captain' ) . '</p>';
			} else {
				$code    = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST['wcvs_balance_code'] ) ) );
				$voucher = $this->repo->get_by_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) );
				if ( ! $voucher ) {
					$result = '<p class="woocommerce-error">' . esc_html__( 'Voucher code not found.', 'voucher-captain' ) . '</p>';
				} else {
					$result = '<div class="woocommerce-info wcvs-balance-result">' . esc_html(
						sprintf(
							/* translators: 1: status, 2: balance, 3: expiry */
							__( 'Status: %1$s · Balance: %2$s · Valid until: %3$s', 'voucher-captain' ),
							$voucher->status_label(),
							html_entity_decode( wp_strip_all_tags( wc_price( $voucher->balance, array( 'currency' => $voucher->currency ) ) ) ),
							$voucher->expires_at ? wp_date( $this->settings->date_format(), strtotime( $voucher->expires_at . ' UTC' ) ) : __( 'unlimited', 'voucher-captain' )
						)
					) . '</div>';
				}
			}
		}
		ob_start();
		?>
		<form method="post" class="wcvs-balance-form">
			<?php wp_nonce_field( 'wcvs_balance', 'wcvs_balance_nonce' ); ?>
			<label for="wcvs-balance-code"><?php esc_html_e( 'Voucher code', 'voucher-captain' ); ?></label>
			<input type="text" id="wcvs-balance-code" name="wcvs_balance_code" required autocomplete="off" />
			<button type="submit" class="button"><?php esc_html_e( 'Check balance', 'voucher-captain' ); ?></button>
		</form>
		<?php
		return $result . ob_get_clean();
	}
}
