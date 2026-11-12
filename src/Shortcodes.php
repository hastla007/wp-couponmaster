<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Reusable shortcodes and blocks for pages built by the merchant:
 *  - [vcap_balance] / [vcap_voucher_status] (block "Voucher balance"): status, balance and expiry of a code
 *  - [vcap_redeem] (block "Redeem voucher"): enter a code; applied to the cart now or as soon as it has products
 *  - [vcap_my_vouchers] (block "My vouchers"): the customer's vouchers with PDF and "send again"
 *  - [vcap_referral] (block "Refer a friend"): the customer's referral code
 * Blocks are rendered on the server, so they show the same output as the shortcodes.
 */
final class Shortcodes {
	public const BLOCKS = array(
		'vcap/balance'     => 'vcap_balance',
		'vcap/redeem'      => 'vcap_redeem',
		'vcap/my-vouchers' => 'vcap_my_vouchers',
		'vcap/referral'    => 'vcap_referral',
	);

	public function __construct( private Downloads $downloads, private Referrals $referrals ) {}

	public function register(): void {
		add_shortcode( 'vcap_voucher_status', array( $this->downloads, 'balance_shortcode' ) );
		add_shortcode( 'vcap_redeem', array( $this, 'redeem_shortcode' ) );
		add_action( 'save_post_page', static fn() => delete_transient( 'vcap_redeem_page_guess' ) );
		add_shortcode( 'vcap_my_vouchers', array( $this, 'my_vouchers_shortcode' ) );
		add_shortcode( 'vcap_referral', array( $this, 'referral_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'handle_redeem' ), 8 );
		add_action( 'init', array( $this, 'blocks' ) );
	}

	public function blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		wp_register_script( 'vcap-blocks', VCAP_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-server-side-render' ), VCAP_VERSION, true );
		wp_localize_script(
			'vcap-blocks',
			'vcapBlocks',
			array(
				'vcap/balance'     => __( 'Voucher balance', 'voucher-captain' ),
				'vcap/redeem'      => __( 'Redeem voucher', 'voucher-captain' ),
				'vcap/my-vouchers' => __( 'My vouchers', 'voucher-captain' ),
				'vcap/referral'    => __( 'Refer a friend', 'voucher-captain' ),
			)
		);
		foreach ( self::BLOCKS as $name => $shortcode ) {
			register_block_type(
				$name,
				array(
					'api_version'     => 2,
					'editor_script'   => 'vcap-blocks',
					'render_callback' => static fn() => do_shortcode( '[' . $shortcode . ']' ),
					'supports'        => array( 'html' => false ),
				)
			);
		}
	}

	private static function notices(): string {
		if ( ! function_exists( 'wc_print_notices' ) || ! WC()->session ) {
			return '';
		}
		return (string) wc_print_notices( true );
	}

	/**
	 * Redeem a code: 'applied' (cart had products), 'saved' (applied when products are added), 'invalid',
	 * 'rejected' (WooCommerce refused it) or 'limited' (too many attempts).
	 */
	public function redeem( string $raw ): string {
		if ( Plugin::rate_limited( 'redeem', 15, 300, 15 * MINUTE_IN_SECONDS ) ) {
			wc_add_notice( __( 'Too many attempts. Please try again later.', 'voucher-captain' ), 'error' );
			return 'limited';
		}
		$code = wc_format_coupon_code( sanitize_text_field( $raw ) );
		if ( '' === $code || strlen( $code ) > 100 || ! wc_get_coupon_id_by_code( $code ) ) {
			wc_add_notice( __( 'Voucher code not found.', 'voucher-captain' ), 'error' );
			return 'invalid';
		}
		/** Connectors may handle a code themselves (e.g. exchange it for tickets): return a result string. */
		$handled = apply_filters( 'vcap_redeem_code', null, $code );
		if ( is_string( $handled ) && '' !== $handled ) {
			return $handled;
		}
		if ( ! WC()->cart || WC()->cart->is_empty() ) {
			if ( WC()->session && ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( CouponLink::SESSION_KEY, $code );
			/* translators: %s: coupon code */
			wc_add_notice( sprintf( __( 'The voucher %s will be applied as soon as you add products to your cart.', 'voucher-captain' ), strtoupper( $code ) ) );
			return 'saved';
		}
		if ( WC()->cart->has_discount( $code ) ) {
			return 'applied';
		}
		return WC()->cart->apply_coupon( $code ) ? 'applied' : 'rejected';
	}

	public function handle_redeem(): void {
		if ( empty( $_POST['vcap_redeem_code'] ) || empty( $_POST['vcap_redeem_nonce'] ) || ! function_exists( 'WC' ) ) {
			return;
		}
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_redeem_nonce'] ) ), 'vcap_redeem' ) ) {
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error' );
			return;
		}
		$result = $this->redeem( wp_unslash( (string) $_POST['vcap_redeem_code'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in redeem().
		if ( 'applied' === $result ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	/**
	 * Link to the redeem page (setting "Redeem page"; otherwise the first published page that contains the
	 * redeem block or shortcode), with the code filled in. '' when there is none.
	 */
	public static function redeem_url( string $code = '' ): string {
		$page = (int) get_option( 'vcap_redeem_page_id', 0 );
		if ( $page <= 0 || 'publish' !== get_post_status( $page ) ) {
			$page = (int) get_transient( 'vcap_redeem_page_guess' );
			if ( ! $page ) {
				global $wpdb;
				$page = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'page' AND post_status = 'publish' AND ( post_content LIKE '%[vcap_redeem%' OR post_content LIKE '%<!-- wp:vcap/redeem%' ) ORDER BY ID LIMIT 1" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				set_transient( 'vcap_redeem_page_guess', $page ? $page : -1, DAY_IN_SECONDS );
			}
		}
		$url = $page > 0 ? (string) get_permalink( $page ) : '';
		$url = '' !== $url && '' !== $code ? add_query_arg( 'vcap_code', rawurlencode( $code ), $url ) : $url;
		/** Redeem page link of a code ('' = none). */
		return (string) apply_filters( 'vcap_redeem_url', $url, $code );
	}

	public function redeem_shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'button' => __( 'Redeem', 'voucher-captain' ) ), (array) $atts, 'vcap_redeem' );
		ob_start();
		?>
		<form method="post" class="vcap-redeem-form">
			<?php wp_nonce_field( 'vcap_redeem', 'vcap_redeem_nonce' ); ?>
			<?php // Default button for Enter (implicit submission uses the first submit button; connector steps such as "Cancel" come before the visible one). ?>
			<button type="submit" class="vcap-default-submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"><?php echo esc_html( $atts['button'] ); ?></button>
			<label for="vcap-redeem-code"><?php esc_html_e( 'Voucher code', 'voucher-captain' ); ?></label>
			<input type="text" id="vcap-redeem-code" name="vcap_redeem_code" required autocomplete="off" maxlength="100" value="<?php echo esc_attr( (string) apply_filters( 'vcap_redeem_prefill', isset( $_GET['vcap_code'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['vcap_code'] ) ), 0, 100 ) : '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only pre-fills the field. ?>" />
			<?php
			/** Extra fields of connectors (e.g. the date for a ticket voucher), posted with the code. */
			do_action( 'vcap_redeem_form_fields' );
			?>
			<button type="submit" class="button"><?php echo esc_html( $atts['button'] ); ?></button>
		</form>
		<?php
		return self::notices() . ob_get_clean();
	}

	private static function login_hint(): string {
		return '<p class="vcap-login-hint">' . wp_kses_post(
			sprintf(
				/* translators: %s: login link */
				__( 'Please %s to see your vouchers.', 'voucher-captain' ),
				'<a href="' . esc_url( wc_get_page_permalink( 'myaccount' ) ) . '">' . esc_html__( 'log in', 'voucher-captain' ) . '</a>'
			)
		) . '</p>';
	}

	public function my_vouchers_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return self::login_hint();
		}
		ob_start();
		$this->downloads->account_page();
		return self::notices() . ob_get_clean();
	}

	public function referral_shortcode(): string {
		if ( ! is_user_logged_in() ) {
			return self::login_hint();
		}
		ob_start();
		$this->referrals->account_block();
		return (string) ob_get_clean();
	}
}
