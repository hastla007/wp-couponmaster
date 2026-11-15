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
		add_action( 'template_redirect', array( $this, 'customer_change' ), 5 );
		add_action( 'admin_post_vcap_admin_pdf', array( $this, 'admin_download' ) );
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'order_downloads' ), 5 );
		add_action( 'wp_loaded', array( $this, 'maybe_flush_rewrite' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'account_page' ), 10, 0 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_title' ) );
		add_shortcode( 'vcap_balance', array( $this, 'balance_shortcode' ) );
		add_action( 'template_redirect', array( $this, 'handle_balance' ), 8 );
		add_action( 'init', array( self::class, 'register_assets' ), 5 );
		add_action( 'wp_enqueue_scripts', array( $this, 'styles' ) );
	}

	/** Registers the storefront style and script (enqueued where vouchers are shown, see enqueue_style()). */
	public static function register_assets(): void {
		if ( ! wp_style_is( 'vcap-frontend', 'registered' ) ) {
			wp_register_style( 'vcap-frontend', VCAP_URL . 'assets/css/frontend.css', array(), VCAP_VERSION );
		}
		if ( ! wp_script_is( 'vcap-frontend', 'registered' ) ) {
			wp_register_script( 'vcap-frontend', VCAP_URL . 'assets/js/frontend.js', array( 'jquery' ), VCAP_VERSION, true );
		}
	}

	/**
	 * Enqueues the storefront style where Voucher Captain output is rendered (shortcodes, blocks, voucher fields),
	 * so it is styled on any page – also inside [product_page] or page builder widgets.
	 */
	public static function enqueue_style(): void {
		self::register_assets();
		wp_enqueue_style( 'vcap-frontend' );
	}

	public function styles(): void {
		if ( is_account_page() || is_order_received_page() || is_product() || is_cart() || is_checkout() ) {
			self::enqueue_style();
		}
	}

	/**
	 * V10: the signature binds token, expiry and the voucher's link version (number of reissues). Version 0 signs
	 * as before (token|exp), so links of vouchers that were never reissued stay valid.
	 */
	private function sign( string $token, int $exp, int $version = 0 ): string {
		return hash_hmac( 'sha256', $token . '|' . $exp . ( $version > 0 ? '|v' . $version : '' ), $this->settings->secret() );
	}

	/** V10: link version of a voucher – how often it was reissued (each reissue invalidates the links sent before). */
	public function link_version( Voucher $voucher ): int {
		return $voucher->id ? $this->repo->count_event( $voucher->id, Reissue::EVENT ) : 0;
	}

	private function valid_guest_link( string $token, int $exp, string $signature, int $version = 0 ): bool {
		return hash_equals( $this->sign( $token, $exp, $version ), $signature ) && ( 0 === $exp || $exp >= time() );
	}

	/**
	 * @param bool $for_email Email links honour the configured lifetime (default 90 days, V10); page links are
	 *                        short-lived and re-signed per view.
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
				'sig'      => $this->sign( $voucher->token, $exp, $this->link_version( $voucher ) ),
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
	 * H10: the buyer may correct the recipient email and the delivery date of a gift until it is delivered
	 * (scheduled, never delivered, own order).
	 */
	public function customer_can_change( Voucher $voucher ): bool {
		$order = $voucher->order();
		return is_user_logged_in()
			&& $order instanceof \WC_Order
			&& (int) $order->get_customer_id() === get_current_user_id()
			&& Voucher::STATUS_SCHEDULED === $voucher->status
			&& null === $voucher->delivered_at
			&& (bool) apply_filters( 'vcap_customer_can_change', true, $voucher );
	}

	/**
	 * H10: change recipient email and/or delivery date (local Y-m-d) of a scheduled gift. Only while it is still
	 * scheduled (the stored row is checked, a delivery running at the same time wins); the coupon's email
	 * restriction follows the new address, the delivery is planned anew, the history records old and new values.
	 *
	 * @return string '' on success, else the reason to show.
	 */
	public function change_delivery( Voucher $voucher, string $email, string $date ): string {
		$fresh = $this->repo->get( $voucher->id );
		if ( ! $fresh || ! $this->customer_can_change( $fresh ) ) {
			return __( 'This voucher has already been sent and can no longer be changed.', 'voucher-captain' );
		}
		$email = sanitize_email( trim( $email ) );
		if ( ! is_email( $email ) ) {
			return __( 'Please enter a valid email address for the recipient.', 'voucher-captain' );
		}
		$utc = null;
		if ( '' !== $date ) {
			$local = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? LocalDate::timestamp( $date . sprintf( 'T%02d:00', Delivery::delivery_hour() ) ) : null;
			if ( null === $local || $local <= time() || $local > time() + YEAR_IN_SECONDS + DAY_IN_SECONDS ) {
				return __( 'Please choose a delivery date between tomorrow and one year from now.', 'voucher-captain' );
			}
			if ( $fresh->expires_at && $local >= strtotime( $fresh->expires_at . ' UTC' ) ) {
				return __( 'The voucher would already have expired on this delivery date. Please choose an earlier date.', 'voucher-captain' );
			}
			$utc   = gmdate( 'Y-m-d H:i:s', $local );
			$order = $fresh->order();
			// V7: a new date never ends the security hold of the order early.
			$utc = $order instanceof \WC_Order ? Delivery::with_hold( $utc, $order ) : $utc;
		}
		$old_email = (string) $fresh->recipient_email;
		$old_at    = (string) $fresh->delivery_at;
		$changes   = array();
		if ( strtolower( $old_email ) !== strtolower( $email ) ) {
			$fresh->recipient_email = $email;
			if ( isset( $fresh->fields['recipient_email'] ) ) {
				$fresh->fields['recipient_email']['value'] = $email;
			}
			/* translators: 1: old email, 2: new email */
			$changes[] = sprintf( __( 'recipient %1$s → %2$s', 'voucher-captain' ), '' !== $old_email ? $old_email : '–', $email );
		}
		if ( null !== $utc && $utc !== $old_at ) {
			if ( isset( $fresh->fields['delivery_date'] ) ) {
				$fresh->fields['delivery_date']['value'] = $date;
			}
			/* translators: 1: old delivery date, 2: new delivery date */
			$changes[] = sprintf( __( 'delivery %1$s → %2$s', 'voucher-captain' ), '' !== $old_at ? LocalDate::from_utc( $old_at, $this->settings->date_format() ) : '–', LocalDate::from_utc( $utc, $this->settings->date_format() ) );
		}
		if ( ! $changes ) {
			return '';
		}
		if ( ! $this->repo->update_columns( $fresh, array( 'recipient_email', 'fields' ) ) ) {
			return __( 'The change could not be saved. Please contact us.', 'voucher-captain' );
		}
		if ( strtolower( $old_email ) !== strtolower( $email ) ) {
			vcap()->coupons->refresh_email_restrictions( $fresh, $old_email );
		}
		if ( null !== $utc && $utc !== $old_at ) {
			vcap()->delivery->reschedule( $fresh, $utc );
		}
		/* translators: %s: list of changes */
		$this->repo->log( $fresh->id, 'edited', null, null, sprintf( __( 'Changed by the customer before delivery: %s', 'voucher-captain' ), implode( '; ', $changes ) ) );
		return '';
	}

	/** POST handler of the "Change recipient or date" form in the voucher list (H10). */
	public function customer_change(): void {
		if ( empty( $_POST['vcap_change'] ) || empty( $_POST['vcap_change_nonce'] ) ) {
			return;
		}
		$id = absint( $_POST['vcap_change'] );
		if ( ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_change_nonce'] ) ), 'vcap_change_' . $id ) ) {
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		$voucher = $this->repo->get( $id );
		if ( ! $voucher || ! $this->customer_can_change( $voucher ) ) {
			wc_add_notice( __( 'This voucher has already been sent and can no longer be changed.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		if ( Plugin::rate_limited( 'change', 5, 0, HOUR_IN_SECONDS, true, 'v' . $voucher->id ) ) {
			wc_add_notice( __( 'This voucher was changed several times already. Please try again later.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		$error = $this->change_delivery( $voucher, (string) wp_unslash( $_POST['vcap_change_email'] ?? '' ), sanitize_text_field( wp_unslash( $_POST['vcap_change_date'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_email() in change_delivery().
		if ( '' !== $error ) {
			wc_add_notice( $error, 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		wc_add_notice( __( 'The gift has been changed. It will be sent as shown in your voucher list.', 'voucher-captain' ), 'success', Shortcodes::ACCOUNT_NOTICE );
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
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
			wc_add_notice( __( 'The request has expired. Please try again.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		$voucher = $this->repo->get( $id );
		if ( ! $voucher || ! $this->customer_can_resend( $voucher ) ) {
			wc_add_notice( __( 'This voucher cannot be sent again.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		// Atomic counter (parallel clicks cannot send more than 3 mails an hour).
		if ( Plugin::rate_limited( 'resend', 3, 0, HOUR_IN_SECONDS, true, 'v' . $voucher->id ) ) {
			wc_add_notice( __( 'This voucher was sent several times already. Please try again later.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
			return;
		}
		// H10: "to me" or "to the recipient" instead of always both roles.
		$to   = sanitize_key( wp_unslash( $_POST['vcap_resend_to'] ?? '' ) );
		$sent = vcap()->delivery->send( $voucher, in_array( $to, array( 'buyer', 'recipient' ), true ) ? $to : null );
		$this->repo->log( $voucher->id, 'resent', null, null, sprintf( /* translators: %d: number of addresses */ _n( 'Requested by the customer, sent to %d address', 'Requested by the customer, sent to %d addresses', count( $sent ), 'voucher-captain' ), count( $sent ) ) );
		if ( $sent ) {
			wc_add_notice( __( 'The voucher email has been sent again.', 'voucher-captain' ), 'success', Shortcodes::ACCOUNT_NOTICE );
		} else {
			wc_add_notice( __( 'The voucher email could not be sent. Please contact us.', 'voucher-captain' ), 'error', Shortcodes::ACCOUNT_NOTICE );
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

		// A leaked link must not keep the server rendering PDFs: per visitor and per link (the cached PDF below
		// makes repeated downloads cheap; the limits stop floods from one or many addresses).
		if ( Plugin::rate_limited( 'pdf', 30, 600, 10 * MINUTE_IN_SECONDS ) || Plugin::rate_limited( 'pdf_link', 60, 0, HOUR_IN_SECONDS, true, 't' . $token ) ) {
			wp_die( esc_html__( 'Too many downloads. Please try again later.', 'voucher-captain' ), esc_html__( 'Download unavailable', 'voucher-captain' ), array( 'response' => 429 ) );
		}
		$voucher = '' !== $sig ? $this->repo->get_by_token( $token ) : null;
		if ( $voucher && ! $this->valid_guest_link( $voucher->token, $exp, $sig, $this->link_version( $voucher ) ) ) {
			$voucher = null;
		}
		if ( ! $voucher ) {
			wp_die( esc_html__( 'This download link is invalid or has expired. Please log in to your account or contact us for a new link.', 'voucher-captain' ), esc_html__( 'Download unavailable', 'voucher-captain' ), array( 'response' => 403 ) );
		}
		if ( Voucher::STATUS_VOID === $voucher->status ) {
			wp_die( esc_html__( 'This voucher has been cancelled.', 'voucher-captain' ), '', array( 'response' => 410 ) );
		}
		if ( Delivery::is_held( $voucher ) ) {
			// V7: held for the security check of a new customer's order – the PDF carries the code.
			wp_die( esc_html__( 'This voucher is not available yet. It is delivered by email once released.', 'voucher-captain' ), esc_html__( 'Download unavailable', 'voucher-captain' ), array( 'response' => 403 ) );
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
		if ( $log && ! $this->downloaded_recently( $voucher->id ) ) {
			$this->repo->log( $voucher->id, 'downloaded' ); // At most once an hour: a link opened again and again does not flood the history.
		}
		$cache = $log ? $this->cached_pdf_file( $voucher ) : '';
		$pdf   = '' !== $cache && is_readable( $cache ) && filemtime( $cache ) > time() - HOUR_IN_SECONDS ? (string) file_get_contents( $cache ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private local file.
		if ( '' === $pdf ) {
			// Staff (admin download) print the customer's voucher: in the order/site language, as it was mailed, not
			// in the language of their profile.
			$state = $log ? null : Compat\Multilingual::switch_to( Compat\Multilingual::email_locale( $voucher ), Compat\Multilingual::order_language( $voucher->order() ) );
			try {
				$pdf = $this->pdf->render( $voucher );
			} finally {
				if ( $state ) {
					Compat\Multilingual::restore( $state );
				}
			}
			if ( '' !== $cache && Install::ensure_upload_dir() ) {
				file_put_contents( $cache, $pdf ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- private folder, removed by the daily maintenance after an hour.
			}
		}
		Preview::stream( $pdf, $this->pdf->filename( $voucher ), ! $download );
	}

	/** Whether a customer download of the voucher was logged within the last hour. */
	public function downloaded_recently( int $voucher_id ): bool {
		global $wpdb;
		$table = Install::tables()['events'];
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$table} WHERE voucher_id = %d AND event = 'downloaded' AND created_at > %s LIMIT 1", $voucher_id, gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from Install.
	}

	/**
	 * Private file of a rendered customer PDF, for at most an hour (the temp folder is emptied by the daily
	 * maintenance). The name changes with everything the PDF shows (voucher data, template, language, plugin
	 * version), so a changed or cancelled voucher is never served from an old file.
	 */
	public function cached_pdf_file( Voucher $voucher ): string {
		$template    = (int) $voucher->template_id;
		$fingerprint = wp_json_encode( get_object_vars( $voucher ) ) . '|' . ( $template ? (string) get_post_modified_time( 'U', true, $template ) : '' ) . '|' . determine_locale() . '|' . ( defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '' );
		return Install::upload_dir() . '/tmp/dl-' . hash_hmac( 'sha256', $fingerprint, $this->settings->secret() ) . '.pdf';
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
		$key     = isset( $_GET['key'] ) && is_string( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : '';
		$allowed = ( is_order_received_page() && '' !== $key && hash_equals( (string) $order->get_order_key(), (string) $key ) )
			|| ( is_user_logged_in() && (int) $order->get_customer_id() > 0 && (int) $order->get_customer_id() === get_current_user_id() );
		/** Whether the current visitor may see the full voucher codes and PDF links of an order (default: order key or own order). */
		return (bool) apply_filters( 'vcap_order_codes_visible', $allowed, $order );
	}

	/**
	 * R6: extra links of a voucher (e.g. "Add to Apple Wallet") from the filter "vcap_voucher_links", for the voucher
	 * email (context "email" and "email_plain") and the voucher list in My Account / on the order pages ("account").
	 * Only http(s) links with a label are kept; nothing for cancelled vouchers or vouchers held for the security
	 * check (V7). Templates print them escaped.
	 *
	 * @return array<int, array{label:string, url:string}>
	 */
	public static function voucher_links( Voucher $voucher, string $context ): array {
		if ( Voucher::STATUS_VOID === $voucher->status || Delivery::is_held( $voucher ) ) {
			return array();
		}
		/**
		 * Extra links of a voucher in its email and the customer's voucher list, e.g. "Add to Wallet". Args: list of
		 * array{label: string, url: string}, Voucher, context ("email", "email_plain" or "account").
		 */
		$links = apply_filters( 'vcap_voucher_links', array(), $voucher, $context );
		$out   = array();
		foreach ( is_array( $links ) ? $links : array() as $link ) {
			$label = is_array( $link ) ? trim( wp_strip_all_tags( (string) ( $link['label'] ?? '' ) ) ) : '';
			$url   = is_array( $link ) ? esc_url_raw( (string) ( $link['url'] ?? '' ), array( 'http', 'https' ) ) : '';
			if ( '' !== $label && '' !== $url ) {
				$out[] = array( 'label' => $label, 'url' => $url );
			}
		}
		return $out;
	}

	/** Last four characters of a code, e.g. "…7KQ2" (the code is a means of payment). Alias of Mask::code(). */
	public static function masked_code( string $code ): string {
		return Mask::code( $code );
	}

	/**
	 * @param Voucher[] $vouchers
	 */
	private function masked_list( array $vouchers ): void {
		echo '<section class="vcap-vouchers vcap-vouchers-masked"><h2 class="woocommerce-column__title">' . esc_html__( 'Your vouchers', 'voucher-captain' ) . '</h2><ul>';
		foreach ( $vouchers as $voucher ) {
			$product = $voucher->product();
			echo '<li>' . esc_html( ( $product ? $product->get_name() : __( 'Voucher', 'voucher-captain' ) ) . ' · ' . Mask::code( $voucher->code ) . ' · ' . $voucher->status_label() ) . '</li>';
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

	/** @param bool $extras Whether store credit and referral code are shown above the list (hook vcap_account_vouchers_top). */
	public function account_page( bool $extras = true ): void {
		$user = wp_get_current_user();
		if ( $extras ) {
			do_action( 'vcap_account_vouchers_top', $user );
		}
		// B15: one page of the list (newest first); one more voucher is read to know whether a next page exists.
		$page = max( 1, absint( wp_unslash( $_GET[ self::PAGE_ARG ] ?? 1 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only selects a page of the customer's own list.
		$list = $this->repo->for_customer( (int) $user->ID, self::PER_PAGE + 1, ( $page - 1 ) * self::PER_PAGE );
		$more = count( $list ) > self::PER_PAGE;
		$list = array_slice( $list, 0, self::PER_PAGE );
		// Products and orders of the page loaded together (one query each, not one per voucher).
		$products = array_values( array_unique( array_filter( array_map( static fn( $v ) => (int) ( $v->variation_id ?: $v->product_id ), $list ) ) ) );
		if ( $products ) {
			_prime_post_caches( $products, false, true );
		}
		Voucher::prime_orders( $list );
		Plugin::template(
			'myaccount/vouchers-table.php',
			array(
				'vouchers'  => $list,
				'downloads' => $this,
				'title'     => '',
				'show_order'=> true,
			)
		);
		Voucher::forget_orders();
		if ( $more || $page > 1 ) {
			echo '<nav class="woocommerce-pagination vcap-vouchers-pagination">';
			if ( $page > 1 ) {
				echo '<a class="woocommerce-button woocommerce-button--previous button" href="' . esc_url( add_query_arg( self::PAGE_ARG, $page - 1 ) ) . '">' . esc_html__( 'Newer vouchers', 'voucher-captain' ) . '</a> ';
			}
			if ( $more ) {
				echo '<a class="woocommerce-button woocommerce-button--next button" href="' . esc_url( add_query_arg( self::PAGE_ARG, $page + 1 ) ) . '">' . esc_html__( 'Older vouchers', 'voucher-captain' ) . '</a>';
			}
			echo '</nav>';
		}
	}

	/** B15: vouchers per page of My Account → Vouchers, and the query argument of the page. */
	public const PER_PAGE = 20;
	public const PAGE_ARG = 'vcap_page';

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
			$parts['value'] = sprintf( __( 'Discount: %s', 'voucher-captain' ), Format::percent( $voucher->initial_amount ) );
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
		// Shown in a region that is focused after the page reload (Shortcodes::result_region()), so screen readers read it.
		return '<div class="woocommerce-info vcap-balance-result">' . $html . '</div>';
	}

	/** @var array{key:string, form:string, html:string, error:bool, shown:bool}|null balance check sent in this request */
	private ?array $balance = null;

	/**
	 * Evaluates a sent balance check once per request (before any output, so the no-cache headers can still be
	 * sent): two balance blocks on a page or a builder rendering the content twice count one attempt, not two.
	 * Guests send it without a nonce: it only reads (rate limited) and a nonce in a cached page would expire or
	 * belong to another visitor's session. Logged-in customers without a valid nonce are answered like guests.
	 */
	public function handle_balance(): void {
		if ( ! isset( $_POST['vcap_balance_code'] ) || ! is_string( $_POST['vcap_balance_code'] ) ) {
			return;
		}
		// '' = a form without id (cached markup of an earlier version): the result goes to the first form.
		$form = isset( $_POST['vcap_balance_form'] ) && is_string( $_POST['vcap_balance_form'] ) ? sanitize_key( wp_unslash( $_POST['vcap_balance_form'] ) ) : '';
		$key  = md5( $form . '|' . wp_unslash( $_POST['vcap_balance_code'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- only a fingerprint.
		if ( $this->balance && $key === $this->balance['key'] ) {
			return; // Already checked in this request.
		}
		// A logged-in customer's form carries a nonce. A missing or expired one (cached page) does not drop the
		// check silently: it is answered like a guest's, i.e. without the account's own store credit.
		$verified = is_user_logged_in() && isset( $_POST['vcap_balance_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_balance_nonce'] ) ), 'vcap_balance' );
		Shortcodes::no_cache();
		$this->balance = array(
			'key'   => $key,
			'form'  => $form,
			'html'  => '',
			'error' => true,
			'shown' => false,
		);
		if ( CodeLookup::begin() ) {
			$this->balance['html'] = '<p class="woocommerce-error" role="alert">' . esc_html( CodeLookup::limited_message() ) . '</p>';
			return;
		}
		$code    = wc_format_coupon_code( sanitize_text_field( wp_unslash( $_POST['vcap_balance_code'] ) ) );
		$voucher = '' !== $code && strlen( $code ) <= 100 ? ( $this->repo->get_by_code( $code ) ?? $this->repo->get_by_code( strtoupper( $code ) ) ) : null;
		if ( ! CodeLookup::visible( $voucher, $verified ? get_current_user_id() : 0 ) ) {
			CodeLookup::failed(); // Unknown, or another customer's store credit: the same answer.
			$this->balance['html'] = '<p class="woocommerce-error" role="alert">' . esc_html( CodeLookup::not_found_message() ) . '</p>';
			return;
		}
		CodeLookup::found();
		$this->balance['html']  = $this->balance_result( $voucher );
		$this->balance['error'] = false;
	}

	/**
	 * [vcap_balance]: the balance check form. The result appears in the form that was sent (focused after the
	 * page reload, see Shortcodes::result_region()); editor previews show the form only.
	 */
	public function balance_shortcode(): string {
		if ( ! did_action( 'template_redirect' ) ) {
			$this->handle_balance(); // Rendered outside a page request (e.g. by a page builder's AJAX call).
		}
		self::enqueue_style();
		$form_id = Shortcodes::unique_id( 'vcap-balance' );
		$result  = '';
		$error   = false;
		if ( $this->balance && ! $this->balance['shown'] && in_array( $this->balance['form'], array( '', $form_id ), true ) && ! Shortcodes::is_editor_preview() ) {
			$this->balance['shown'] = true;
			$result                 = Shortcodes::result_region( $this->balance['html'], $form_id . '-result' );
			$error                  = $this->balance['error'];
		}
		ob_start();
		?>
		<form method="post" class="vcap-balance-form" action="#<?php echo esc_attr( $form_id ); ?>">
			<?php
			if ( is_user_logged_in() ) {
				wp_nonce_field( 'vcap_balance', 'vcap_balance_nonce', false );
			}
			?>
			<input type="hidden" name="vcap_balance_form" value="<?php echo esc_attr( $form_id ); ?>" />
			<label for="<?php echo esc_attr( $form_id . '-code' ); ?>"><?php esc_html_e( 'Voucher code', 'voucher-captain' ); ?></label>
			<input type="text" id="<?php echo esc_attr( $form_id . '-code' ); ?>" name="vcap_balance_code" required autocomplete="off" maxlength="100"<?php echo $error ? ' aria-invalid="true" aria-describedby="' . esc_attr( $form_id . '-result' ) . '"' : ''; ?> />
			<button type="submit" class="<?php echo esc_attr( Shortcodes::button_class() ); ?>"><?php esc_html_e( 'Check balance', 'voucher-captain' ); ?></button>
		</form>
		<?php
		return Shortcodes::wrap( $result . ob_get_clean(), $form_id );
	}
}
