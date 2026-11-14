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

	/** Code of a redeem link, kept in the WooCommerce session to fill in the redeem form. */
	public const PREFILL_KEY = 'vcap_redeem_prefill';

	public function __construct( private Downloads $downloads, private Referrals $referrals ) {}

	public function register(): void {
		add_shortcode( 'vcap_voucher_status', array( $this->downloads, 'balance_shortcode' ) );
		add_shortcode( 'vcap_redeem', array( $this, 'redeem_shortcode' ) );
		add_action( 'save_post_page', static fn() => delete_transient( 'vcap_redeem_page_guess' ) );
		foreach ( array( 'save_post', 'deleted_post', 'update_option_widget_block', 'update_option_widget_text', 'update_option_widget_custom_html' ) as $hook ) {
			add_action( $hook, array( self::class, 'forget_redeem_templates' ) );
		}
		add_shortcode( 'vcap_my_vouchers', array( $this, 'my_vouchers_shortcode' ) );
		add_shortcode( 'vcap_referral', array( $this, 'referral_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'redeem_link' ), 7 );
		add_action( 'template_redirect', array( $this, 'handle_redeem' ), 8 );
		add_action( 'template_redirect', array( $this, 'maybe_no_cache' ), 9 );
		add_action( 'init', array( $this, 'blocks' ) );
	}

	/**
	 * Blocks from assets/blocks/{name}/block.json (apiVersion 3, editor script "vcap-blocks", editor style
	 * "vcap-frontend"), rendered on the server by the shortcode callbacks (the front end enqueues the style where
	 * a block renders). Titles and descriptions come from PHP so they use the plugin's translations.
	 */
	public function blocks(): void {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		Downloads::register_assets();
		wp_register_script( 'vcap-blocks', VCAP_URL . 'assets/js/blocks.js', array( 'wp-blocks', 'wp-element', 'wp-block-editor', 'wp-components', 'wp-server-side-render' ), VCAP_VERSION, true );
		wp_localize_script(
			'vcap-blocks',
			'vcapBlocks',
			array(
				'buttonText' => __( 'Button text', 'voucher-captain' ),
				'buttonHelp' => __( 'Empty: "Redeem".', 'voucher-captain' ),
				'showExtras' => __( 'Show store credit and referral code', 'voucher-captain' ),
				'settings'   => __( 'Settings', 'voucher-captain' ),
			)
		);
		$texts = array(
			'vcap/balance'     => array( __( 'Voucher balance', 'voucher-captain' ), __( 'Lets customers check the status, balance and expiry of a voucher code.', 'voucher-captain' ) ),
			'vcap/redeem'      => array( __( 'Redeem voucher', 'voucher-captain' ), __( 'Lets customers enter a voucher code; it is applied to the cart now or as soon as it has products.', 'voucher-captain' ) ),
			'vcap/my-vouchers' => array( __( 'My vouchers', 'voucher-captain' ), __( 'The logged-in customer\'s vouchers with PDF download and "send again".', 'voucher-captain' ) ),
			'vcap/referral'    => array( __( 'Refer a friend', 'voucher-captain' ), __( 'The logged-in customer\'s personal referral code.', 'voucher-captain' ) ),
		);
		foreach ( array_keys( self::BLOCKS ) as $name ) {
			register_block_type(
				VCAP_PATH . 'assets/blocks/' . substr( $name, strlen( 'vcap/' ) ),
				array(
					'title'           => $texts[ $name ][0],
					'description'     => $texts[ $name ][1],
					'render_callback' => fn( $attributes = array() ) => $this->render_block( $name, is_array( $attributes ) ? $attributes : array() ),
				)
			);
		}
	}

	/**
	 * Output of a block: the shortcode's output, with the block's attributes. Block output is part of the post
	 * content, which WordPress runs through do_shortcode() after the blocks: brackets are escaped, so text from
	 * visitors (a code from a link, a recipient name) can never run a shortcode.
	 */
	public function render_block( string $name, array $attributes = array() ): string {
		switch ( $name ) {
			case 'vcap/balance':
				$html = $this->downloads->balance_shortcode();
				break;
			case 'vcap/redeem':
				$text = isset( $attributes['buttonText'] ) && is_string( $attributes['buttonText'] ) ? trim( $attributes['buttonText'] ) : '';
				$html = $this->redeem_shortcode( '' !== $text ? array( 'button' => $text ) : array() );
				break;
			case 'vcap/my-vouchers':
				$html = $this->my_vouchers_shortcode( array( 'extras' => array_key_exists( 'showExtras', $attributes ) && ! $attributes['showExtras'] ? 'no' : 'yes' ) );
				break;
			case 'vcap/referral':
				$html = $this->referral_shortcode();
				break;
			default:
				$html = '';
		}
		return self::escape_shortcodes( $html );
	}

	/** Brackets of HTML as entities (outside scripts and styles), so do_shortcode() finds no shortcode in it. */
	public static function escape_shortcodes( string $html ): string {
		if ( ! str_contains( $html, '[' ) && ! str_contains( $html, ']' ) ) {
			return $html;
		}
		$parts = preg_split( '#(<script\b.*?</script>|<style\b.*?</style>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( (array) $parts as $i => $part ) {
			if ( 0 === $i % 2 ) {
				$parts[ $i ] = str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), $part );
			}
		}
		return implode( '', (array) $parts );
	}

	/** esc_attr() for text from visitors, with brackets as entities (see escape_shortcodes()). */
	public static function esc_attr_text( string $text ): string {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_attr( $text ) );
	}

	/** esc_html() for text from visitors, with brackets as entities (see escape_shortcodes()). */
	public static function esc_html_text( string $text ): string {
		return str_replace( array( '[', ']' ), array( '&#91;', '&#93;' ), esc_html( $text ) );
	}

	/**
	 * An id unique on the page: the first form or field keeps $base, more of them on the same page (two blocks,
	 * [product_page] twice, page builder widgets) get "-2", "-3", …, so labels and scripts reach their own field.
	 */
	public static function unique_id( string $base ): string {
		static $used = array();
		$used[ $base ] = ( $used[ $base ] ?? 0 ) + 1;
		return 1 === $used[ $base ] ? $base : $base . '-' . $used[ $base ];
	}

	/**
	 * Hidden text after a button's visible text naming the voucher (" – voucher CODE"): screen readers tell the
	 * buttons of a list apart, and voice control still finds the button by its visible text (WCAG 2.5.3).
	 */
	public static function voucher_suffix( string $code ): string {
		/* translators: %s: voucher code */
		return '<span class="screen-reader-text"> – ' . self::esc_html_text( sprintf( __( 'voucher %s', 'voucher-captain' ), $code ) ) . '</span>';
	}

	/** Classes of a button: "button" plus WooCommerce's theme button class ("wp-element-button" in block themes). */
	public static function button_class( string $classes = 'button' ): string {
		$theme = function_exists( 'wc_wp_theme_get_element_class_name' ) ? (string) wc_wp_theme_get_element_class_name( 'button' ) : '';
		return trim( $classes . ' ' . $theme );
	}

	/**
	 * Output of a shortcode or block inside div.woocommerce, like WooCommerce's own shortcodes: WooCommerce styles
	 * (buttons, notices, responsive tables) apply on any page, not only on WooCommerce pages.
	 */
	public static function wrap( string $html, string $id = '' ): string {
		return '<div class="woocommerce"' . ( '' !== $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '>' . $html . '</div>';
	}

	/**
	 * Whether the output is rendered for an editor's preview, not for a customer: the block editor (REST block
	 * renderer), page builders (Elementor, Divi, Beaver Builder, Bricks, Oxygen), the Customizer and post previews.
	 * Previews show example data and change nothing (no referral code is created, nothing is redeemed or repaired).
	 */
	public static function is_editor_preview(): bool {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- only decides what to show.
		$preview = ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( isset( $_REQUEST['action'] ) && in_array( $_REQUEST['action'], array( 'elementor_ajax', 'elementor', 'et_fb_ajax_render_shortcode', 'fl_builder_render_module' ), true ) )
			|| isset( $_GET['elementor-preview'] ) || isset( $_GET['et_fb'] ) || isset( $_GET['fl_builder'] ) || isset( $_GET['ct_builder'] )
			|| ( isset( $_GET['bricks'] ) && 'run' === $_GET['bricks'] )
			|| ( function_exists( 'is_customize_preview' ) && is_customize_preview() )
			|| ( function_exists( 'is_preview' ) && did_action( 'wp' ) && is_preview() );
		// phpcs:enable
		/** Whether shortcodes and blocks render an editor's preview (example data, no side effects). */
		return (bool) apply_filters( 'vcap_is_editor_preview', $preview );
	}

	/** Note shown in previews instead of a customer's own data. */
	private static function preview_note( string $text ): string {
		return '<p class="woocommerce-info vcap-preview-note">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Keeps the current page out of full-page caches: it shows session data (a waiting code, a guest email,
	 * notices) or a form whose nonce is tied to the visitor's session. Sets DONOTCACHEPAGE (respected by the common
	 * cache plugins) and sends no-cache headers while they can still be sent.
	 */
	public static function no_cache(): void {
		if ( function_exists( 'wc_maybe_define_constant' ) ) {
			wc_maybe_define_constant( 'DONOTCACHEPAGE', true );
		} elseif ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		if ( ! headers_sent() ) {
			nocache_headers();
		}
		/** Fires when a page must not be cached (hook for cache plugins without DONOTCACHEPAGE support). */
		do_action( 'vcap_no_cache' );
	}

	/**
	 * A page with the redeem form is kept out of caches while it shows something of this visitor (a code from a
	 * redeem link, the result of a redeem attempt, the nonce of a logged-in customer): decided before any output,
	 * so the no-cache headers can still be sent (the shortcode alone could only set DONOTCACHEPAGE). For guests
	 * without such data the page stays cacheable – their form carries no nonce.
	 */
	public function maybe_no_cache(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only decides about caching.
		if ( isset( $_GET['vcap_code'] ) || ( self::visitor_specific() && self::request_shows_redeem_form() ) ) {
			self::no_cache();
		}
	}

	/** Whether the redeem form would show data of this visitor (see maybe_no_cache()). */
	public static function visitor_specific(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only decides about caching.
		if ( is_user_logged_in() || ! empty( $_POST['vcap_redeem_code'] ) ) {
			return true;
		}
		// A code from a redeem link or a connector's pending step (e.g. the date of a ticket voucher) in the session.
		return '' !== (string) apply_filters( 'vcap_redeem_prefill', self::link_code() );
	}

	/**
	 * Whether the current request shows the redeem form: the redeem page, a page with the block or shortcode in
	 * its content (also in synced patterns and Elementor data), or a template, template part or widget with it
	 * (the form is then on every page – which stays cacheable for guests, see maybe_no_cache()).
	 */
	public static function request_shows_redeem_form(): bool {
		$id = is_singular() ? (int) get_queried_object_id() : 0;
		if ( $id > 0 && ( (int) get_option( 'vcap_redeem_page_id', 0 ) === $id || self::page_has_redeem_form( $id ) ) ) {
			return true;
		}
		return self::site_has_redeem_template();
	}

	/**
	 * Whether a site-wide template has the redeem form: block templates and template parts, reusable layouts of
	 * Elementor (Pro), Divi and Beaver Builder, block and text widgets. Cached for a day and forgotten when such
	 * content is saved. Theme files and other builders: filter "vcap_site_has_redeem_form".
	 */
	public static function site_has_redeem_template(): bool {
		$found = get_transient( 'vcap_redeem_in_templates' );
		if ( false === $found || ! in_array( $found, array( 'yes', 'no' ), true ) ) {
			global $wpdb;
			$types  = array( 'wp_template', 'wp_template_part', 'wp_block', 'elementor_library', 'et_template', 'et_header_layout', 'et_body_layout', 'et_footer_layout', 'fl-builder-template', 'fl-theme-layout' );
			$in     = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$like_b = '%' . $wpdb->esc_like( 'vcap/redeem' ) . '%';
			$like_s = '%' . $wpdb->esc_like( '[vcap_redeem' ) . '%';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above, cached in a transient.
			$post = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ({$in}) AND post_status = 'publish' AND ( post_content LIKE %s OR post_content LIKE %s ) LIMIT 1", array_merge( $types, array( $like_b, $like_s ) ) ) );
			$meta = $post ? 0 : (int) $wpdb->get_var( $wpdb->prepare( "SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE p.post_type IN ({$in}) AND p.post_status = 'publish' AND m.meta_key IN ('_elementor_data', '_fl_builder_data') AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s OR m.meta_value LIKE %s ) LIMIT 1", array_merge( $types, array( $like_b, $like_s, '%' . $wpdb->esc_like( 'vcap_redeem' ) . '%' ) ) ) );
			// phpcs:enable
			$widgets = '';
			foreach ( array( 'widget_block', 'widget_text', 'widget_custom_html' ) as $option ) {
				$widgets .= (string) wp_json_encode( get_option( $option, array() ) );
			}
			$found = $post || $meta || str_contains( $widgets, 'vcap\/redeem' ) || str_contains( $widgets, 'vcap/redeem' ) || str_contains( $widgets, '[vcap_redeem' ) ? 'yes' : 'no';
			set_transient( 'vcap_redeem_in_templates', $found, DAY_IN_SECONDS );
		}
		/** Whether a site-wide template (theme file, builder layout, widget) shows the redeem form. */
		return (bool) apply_filters( 'vcap_site_has_redeem_form', 'yes' === $found );
	}

	public static function forget_redeem_templates(): void {
		delete_transient( 'vcap_redeem_in_templates' );
	}

	/** Notices of WooCommerce (only printed by the form a visitor just sent, see redeem_shortcode()). */
	private static function notices(): string {
		if ( ! function_exists( 'wc_print_notices' ) || ! WC()->session ) {
			return '';
		}
		return (string) wc_print_notices( true );
	}

	/** The result of a form a visitor just sent: focused on load (frontend.js), so screen readers read it. */
	public static function result_region( string $html, string $id ): string {
		if ( '' === trim( $html ) ) {
			return '';
		}
		Downloads::register_assets();
		wp_enqueue_script( 'vcap-frontend' );
		return '<div class="vcap-form-result" id="' . esc_attr( $id ) . '" tabindex="-1" data-vcap-focus="1">' . $html . '</div>';
	}

	/**
	 * Redeem a code: 'applied' (cart had products), 'saved' (applied when products are added), 'invalid',
	 * 'rejected' (WooCommerce refused it) or 'limited' (too many attempts).
	 */
	public function redeem( string $raw ): string {
		// The attempt counts before the lookup and is given back for an existing code: a customer redeeming real
		// vouchers is never locked out, and the site-wide counter only brakes guessing (see Plugin::rate_limited()).
		if ( Plugin::rate_limited( 'redeem', 15, 300, 15 * MINUTE_IN_SECONDS, false ) ) {
			wc_add_notice( __( 'Too many attempts. Please try again later.', 'voucher-captain' ), 'error' );
			return 'limited';
		}
		$code = wc_format_coupon_code( sanitize_text_field( $raw ) );
		if ( '' === $code || strlen( $code ) > 100 || ! wc_get_coupon_id_by_code( $code ) ) {
			Plugin::rate_limit_failure( 'redeem', 15 * MINUTE_IN_SECONDS );
			wc_add_notice( __( 'Voucher code not found.', 'voucher-captain' ), 'error' );
			return 'invalid';
		}
		Plugin::rate_limit_success( 'redeem', 15 * MINUTE_IN_SECONDS );
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
			self::no_cache();
			wc_add_notice( CouponLink::waiting_text( $code ) );
			return 'saved';
		}
		if ( WC()->cart->has_discount( $code ) ) {
			return 'applied';
		}
		return WC()->cart->apply_coupon( $code ) ? 'applied' : 'rejected';
	}

	/** Whether a redeem form was sent in this request, its id and the result of redeem() for it. */
	private bool $sent          = false;
	private string $posted_form = '';
	private string $result      = '';

	/**
	 * Handles a sent redeem form (once per request, before any output). Guests send it without a nonce: a nonce
	 * in a cached page would expire or belong to another visitor's session, and attempts are rate limited.
	 * Logged-in customers keep the nonce check.
	 */
	public function handle_redeem(): void {
		if ( empty( $_POST['vcap_redeem_code'] ) || ! function_exists( 'WC' ) || ! is_string( $_POST['vcap_redeem_code'] ) ) {
			return;
		}
		// '' = a form without id (cached markup of an earlier version): the notices go to the first form.
		$this->posted_form = isset( $_POST['vcap_redeem_form'] ) && is_string( $_POST['vcap_redeem_form'] ) ? sanitize_key( wp_unslash( $_POST['vcap_redeem_form'] ) ) : '';
		$this->sent        = true;
		self::no_cache();
		if ( is_user_logged_in() && ( empty( $_POST['vcap_redeem_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_redeem_nonce'] ) ), 'vcap_redeem' ) ) ) {
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error' );
			$this->result = 'expired';
			return;
		}
		if ( WC()->session ) {
			WC()->session->set( self::PREFILL_KEY, null ); // The link's code was used (or replaced) by the customer.
		}
		$this->result = $this->redeem( wp_unslash( (string) $_POST['vcap_redeem_code'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in redeem().
		if ( 'applied' === $this->result ) {
			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}
	}

	/**
	 * Redeem links (?vcap_code=… in emails, PDFs and the customer account) carry the code in the URL. The code is
	 * moved into the WooCommerce session and the visitor is redirected to the clean URL (like CouponLink), so it
	 * does not stay in the browser history, server or CDN logs, analytics or Referer headers. The redeem page is
	 * sent with "Referrer-Policy: no-referrer" and, while it shows a code from the session, is not cached.
	 */
	public function redeem_link(): void {
		if ( is_admin() || wp_doing_ajax() || ! function_exists( 'WC' ) ) {
			return;
		}
		$session = WC()->session;
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- a public link; the code only fills in the form.
		if ( isset( $_GET['vcap_code'] ) && is_string( $_GET['vcap_code'] ) && $session ) {
			$code = self::clean_code( wp_unslash( $_GET['vcap_code'] ) );
			if ( ! $session->has_session() ) {
				$session->set_customer_session_cookie( true ); // Guests need a session to keep the code.
			}
			$session->set( self::PREFILL_KEY, '' !== $code ? $code : null );
			if ( ! headers_sent() ) {
				nocache_headers();
				header( 'Referrer-Policy: no-referrer' );
			}
			wp_safe_redirect( remove_query_arg( 'vcap_code' ) );
			exit;
		}
		// phpcs:enable
		$prefill = $session && '' !== (string) $session->get( self::PREFILL_KEY, '' );
		$on_page = is_singular() && self::page_has_redeem_form( (int) get_queried_object_id() );
		if ( headers_sent() || ! ( $on_page || ( $prefill && self::request_shows_redeem_form() ) ) ) {
			return;
		}
		header( 'Referrer-Policy: no-referrer' );
		if ( $prefill ) {
			if ( ! defined( 'DONOTCACHEPAGE' ) ) {
				define( 'DONOTCACHEPAGE', true );
			}
			nocache_headers();
		}
	}

	/** Code to fill in the redeem form: from a redeem link (session), else from the URL (no session). */
	public static function link_code(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only fills in the form.
		$code = isset( $_GET['vcap_code'] ) && is_string( $_GET['vcap_code'] ) ? wp_unslash( $_GET['vcap_code'] ) : '';
		if ( '' === $code && function_exists( 'WC' ) && WC()->session ) {
			$code = (string) WC()->session->get( self::PREFILL_KEY, '' );
		}
		return self::clean_code( $code );
	}

	/**
	 * A code from a link, reduced to what voucher and coupon codes consist of: no brackets, quotes, angle brackets
	 * or control characters (a link must not be able to put a shortcode or markup into the form), at most 100
	 * characters.
	 */
	public static function clean_code( string $raw ): string {
		$code = sanitize_text_field( $raw );
		$code = (string) preg_replace( '/[\[\]<>"\'`\\\\\x00-\x1f\x7f]/u', '', $code );
		return mb_substr( trim( $code ), 0, 100 );
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

	/** Whether a page shows the redeem form (block or shortcode in its content). */
	public static function page_has_redeem_form( int $page_id ): bool {
		$has = self::content_has_redeem_form( (string) get_post_field( 'post_content', $page_id ) );
		if ( ! $has ) {
			// Elementor keeps its layout in post meta (shortcode widget or block in a text widget).
			$builder = (string) get_post_meta( $page_id, '_elementor_data', true );
			$has     = '' !== $builder && ( str_contains( $builder, 'vcap_redeem' ) || str_contains( $builder, 'vcap\\/redeem' ) || str_contains( $builder, 'vcap/redeem' ) );
		}
		/** Whether the page shows the redeem form (e.g. true when a theme template renders it). */
		return (bool) apply_filters( 'vcap_page_has_redeem_form', $has, $page_id );
	}

	/** Whether content has the redeem block or shortcode, also inside synced patterns (core/block, nested). */
	public static function content_has_redeem_form( string $content, int $depth = 0 ): bool {
		if ( '' === $content || $depth > 5 ) {
			return false;
		}
		if ( has_shortcode( $content, 'vcap_redeem' ) || str_contains( $content, '<!-- wp:vcap/redeem' ) ) {
			return true;
		}
		if ( ! str_contains( $content, '<!-- wp:block' ) || ! function_exists( 'parse_blocks' ) ) {
			return false;
		}
		$refs = array();
		$walk = static function ( array $blocks ) use ( &$walk, &$refs ): void {
			foreach ( $blocks as $block ) {
				if ( 'core/block' === ( $block['blockName'] ?? '' ) && ! empty( $block['attrs']['ref'] ) ) {
					$refs[] = (int) $block['attrs']['ref'];
				}
				$walk( (array) ( $block['innerBlocks'] ?? array() ) );
			}
		};
		$walk( parse_blocks( $content ) );
		foreach ( array_unique( $refs ) as $ref ) {
			if ( 'wp_block' === get_post_type( $ref ) && self::content_has_redeem_form( (string) get_post_field( 'post_content', $ref ), $depth + 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * What is wrong with the redeem page, for an admin notice: '' when links lead to a working redeem form.
	 */
	public static function redeem_page_problem(): string {
		$page = (int) get_option( 'vcap_redeem_page_id', 0 );
		if ( $page > 0 && 'publish' === get_post_status( $page ) ) {
			return self::page_has_redeem_form( $page )
				/* translators: %s: page title */
				? '' : sprintf( __( 'The redeem page "%s" contains neither the "Redeem voucher" block nor the [vcap_redeem] shortcode.', 'voucher-captain' ), get_the_title( $page ) );
		}
		if ( '' === self::redeem_url() ) {
			return __( 'No published page contains the "Redeem voucher" block or the [vcap_redeem] shortcode.', 'voucher-captain' );
		}
		return $page > 0 ? __( 'The selected redeem page is not published; links use another page with the redeem form.', 'voucher-captain' ) : '';
	}

	public function redeem_shortcode( $atts = array() ): string {
		$atts    = shortcode_atts( array( 'button' => __( 'Redeem', 'voucher-captain' ) ), (array) $atts, 'vcap_redeem' );
		$form_id = self::unique_id( 'vcap-redeem' );
		$preview = self::is_editor_preview();
		// Only a form with data of this visitor keeps the page out of caches (an empty guest form in a header
		// template part must not switch off the page cache of the whole site).
		if ( ! $preview && self::visitor_specific() ) {
			self::no_cache();
		}
		Downloads::enqueue_style();
		// Notices only in the form the visitor just sent: a form in a header or sidebar does not take the notices
		// of other forms ("added to cart", field errors) from the page.
		$sent    = ! $preview && $this->sent && in_array( $this->posted_form, array( '', $form_id ), true );
		$result  = $sent ? self::result_region( self::notices(), $form_id . '-result' ) : '';
		if ( $sent ) {
			$this->sent = false; // Shown once.
		}
		$invalid = '' !== $result && in_array( $this->result, array( 'invalid', 'rejected', 'limited', 'expired' ), true );
		$value   = $preview ? '' : self::clean_code( (string) apply_filters( 'vcap_redeem_prefill', self::link_code() ) );
		ob_start();
		?>
		<form method="post" class="vcap-redeem-form" action="#<?php echo esc_attr( $form_id ); ?>">
			<?php
			if ( is_user_logged_in() ) {
				wp_nonce_field( 'vcap_redeem', 'vcap_redeem_nonce', false );
			}
			?>
			<input type="hidden" name="vcap_redeem_form" value="<?php echo esc_attr( $form_id ); ?>" />
			<?php // Default button for Enter (implicit submission uses the first submit button; connector steps such as "Cancel" come before the visible one). ?>
			<button type="submit" class="vcap-default-submit" tabindex="-1" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;"><?php echo esc_html( $atts['button'] ); ?></button>
			<label for="<?php echo esc_attr( $form_id . '-code' ); ?>"><?php esc_html_e( 'Voucher code', 'voucher-captain' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $form_id . '-code' ); ?>" name="vcap_redeem_code" required autocomplete="off" maxlength="100" value="<?php echo self::esc_attr_text( $value ); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_attr_text(). ?>"<?php echo $invalid ? ' aria-invalid="true" aria-describedby="' . esc_attr( $form_id . '-result' ) . '"' : ''; ?> />
			<?php
			/** Extra fields of connectors (e.g. the date for a ticket voucher), posted with the code. */
			do_action( 'vcap_redeem_form_fields' );
			?>
			<button type="submit" class="<?php echo esc_attr( self::button_class() ); ?>"><?php echo esc_html( $atts['button'] ); ?></button>
		</form>
		<?php
		return self::wrap( $result . ob_get_clean(), $form_id );
	}

	private static function login_hint(): string {
		$back = is_singular() ? (string) get_permalink() : '';
		$url  = '' !== $back ? wp_login_url( $back ) : wc_get_page_permalink( 'myaccount' );
		return self::wrap(
			'<p class="vcap-login-hint">' . wp_kses_post(
				sprintf(
					/* translators: %s: login link */
					__( 'Please %s to see your vouchers.', 'voucher-captain' ),
					'<a href="' . esc_url( $url ) . '">' . esc_html__( 'log in', 'voucher-captain' ) . '</a>'
				)
			) . '</p>'
		);
	}

	/**
	 * [vcap_my_vouchers extras="no"]: the customer's vouchers; extras="no" leaves out store credit and referral
	 * code (e.g. when the "Refer a friend" block is on the same page). Each of them is shown once per page.
	 */
	public function my_vouchers_shortcode( $atts = array() ): string {
		$atts = shortcode_atts( array( 'extras' => 'yes' ), (array) $atts, 'vcap_my_vouchers' );
		if ( self::is_editor_preview() ) {
			return self::wrap( self::preview_note( __( 'Preview: logged-in customers see their vouchers with PDF download here.', 'voucher-captain' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return self::login_hint();
		}
		self::no_cache();
		Downloads::enqueue_style();
		ob_start();
		$this->downloads->account_page( 'no' !== $atts['extras'] );
		return self::wrap( self::notices() . ob_get_clean() );
	}

	public function referral_shortcode(): string {
		if ( ! Licensing\Entitlements::allows( 'referrals' ) ) {
			// Locked premium feature: customers see nothing, shop managers see why.
			return current_user_can( 'manage_woocommerce' ) ? self::wrap( '<p class="vcap-license-locked">' . esc_html( Licensing\Entitlements::locked_message( 'referrals' ) ) . '</p>' ) : '';
		}
		if ( self::is_editor_preview() ) {
			return self::wrap( self::preview_note( __( 'Preview: logged-in customers see their personal referral code here.', 'voucher-captain' ) ) );
		}
		if ( ! is_user_logged_in() ) {
			return self::login_hint();
		}
		Downloads::enqueue_style();
		ob_start();
		$this->referrals->account_block();
		$html = (string) ob_get_clean();
		return '' !== $html ? self::wrap( $html ) : '';
	}
}
