<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * PDF previews: admin template preview with sample data, and customer preview on the product page.
 */
final class Preview {
	/** SET-VC-29: test emails per user and hour (technical limit, not a setting). */
	public const TEST_MAIL_LIMIT = 5;

	/** SET-VC-29: transient of the test emails a user sent within the last hour ("<prefix><user id>"). */
	public const TEST_MAIL_TRANSIENT = 'vcap_test_mails_';

	/** SET-VC-29: admin-post action and nonce action of the test email. */
	public const TEST_MAIL_ACTION = 'vcap_test_email';

	public function __construct( private PdfRenderer $pdf ) {}

	public function register(): void {
		add_action( 'wp_ajax_vcap_product_preview', array( $this, 'product_preview' ) );
		add_action( 'wp_ajax_nopriv_vcap_product_preview', array( $this, 'product_preview' ) );
		// Front-end endpoint (?wc-ajax=…): works where /wp-admin is protected (HTTP auth, IP allow list).
		add_action( 'wc_ajax_vcap_product_preview', array( $this, 'product_preview' ) );
		// SET-VC-29: "Send test email to me" (Vouchers → Delivery & downloads).
		add_action( 'admin_post_' . self::TEST_MAIL_ACTION, array( self::class, 'test_email_request' ) );
		add_action( 'woocommerce_admin_field_vcap_test_email', array( self::class, 'test_email_field' ) );
	}

	/**
	 * SET-VC-29: test emails a user may still send within the hour; $consume counts one more (before sending, so a
	 * failing mail server cannot be hammered either).
	 */
	public static function test_email_quota( int $user_id, bool $consume = false ): int {
		$key    = self::TEST_MAIL_TRANSIENT . $user_id;
		$stored = get_transient( $key );
		$now    = time();
		$sent   = array_values( array_filter( is_array( $stored ) ? array_map( 'intval', $stored ) : array(), static fn( int $at ) => $at > $now - HOUR_IN_SECONDS && $at <= $now ) );
		if ( $consume && count( $sent ) < self::TEST_MAIL_LIMIT ) {
			$sent[] = $now;
			set_transient( $key, $sent, HOUR_IN_SECONDS );
		}
		return max( 0, self::TEST_MAIL_LIMIT - count( $sent ) );
	}

	/**
	 * SET-VC-29: sends the gift recipient email with the sample voucher to the current user's own address – never to
	 * the additional recipients of the email and never to any other address.
	 *
	 * @param int                                   $user_id User who asked.
	 * @param string                                $address The user's own email address.
	 * @param callable(string): bool|null            $send    Sends the test email to an address (tests); default mail_test_email().
	 * @return string 'sent', 'limit' (5 per hour used), 'no_address' or 'failed'
	 */
	public static function send_test_email( int $user_id, string $address, ?callable $send = null ): string {
		$address = sanitize_email( $address );
		if ( $user_id <= 0 || ! is_email( $address ) ) {
			return 'no_address';
		}
		$quota = self::test_email_quota( $user_id );
		if ( $quota <= 0 ) {
			return 'limit';
		}
		self::test_email_quota( $user_id, true );
		$send = $send ?? array( self::class, 'mail_test_email' );
		try {
			return $send( $address ) ? 'sent' : 'failed';
		} catch ( \Throwable $e ) {
			Logger::error( 'Test email failed: ' . Mask::addresses( $e->getMessage() ), array( 'element' => 'test_email' ) );
			return 'failed';
		}
	}

	/** SET-VC-29: renders the gift recipient email (the shop's texts, logo and colour) with the sample voucher and sends it to $address only. */
	public static function mail_test_email( string $address ): bool {
		$email = function_exists( 'WC' ) ? ( WC()->mailer()->get_emails()['VCAP_Email_Voucher_Recipient'] ?? null ) : null;
		if ( ! $email instanceof Emails\VoucherEmail ) {
			return false;
		}
		$voucher                  = self::sample_voucher();
		$voucher->recipient_email = $address;
		$email->voucher           = $voucher;
		$email->object            = false;
		$email->recipient         = $address;
		$email->placeholders      = array_merge( $email->placeholders, Placeholders::email_map( $voucher ) );
		try {
			/* translators: %s: subject of the voucher email */
			$subject = sprintf( __( '[Test] %s', 'voucher-captain' ), $email->get_subject() );
			// PV-P2-8: the test goes to $address only, never to the Cc/Bcc set for the email (WooCommerce email improvements).
			return (bool) self::without_copies( (string) $email->id, static fn() => $email->send( $address, $subject, $email->get_content(), $email->get_headers(), array() ) );
		} finally {
			$email->voucher   = null;
			$email->recipient = '';
		}
	}

	/**
	 * PV-P2-8: runs $send while the Cc and Bcc recipients of the email $email_id are empty (filters
	 * "woocommerce_email_cc_recipient_{id}" / "woocommerce_email_bcc_recipient_{id}" at the last priority), then
	 * removes the filters again.
	 *
	 * @template T
	 * @param string        $email_id WooCommerce email id.
	 * @param callable(): T $send     Sends the email.
	 * @return T
	 */
	public static function without_copies( string $email_id, callable $send ) {
		$none  = static fn(): string => '';
		$hooks = array( 'woocommerce_email_cc_recipient_' . $email_id, 'woocommerce_email_bcc_recipient_' . $email_id );
		foreach ( $hooks as $hook ) {
			add_filter( $hook, $none, PHP_INT_MAX );
		}
		try {
			return $send();
		} finally {
			foreach ( $hooks as $hook ) {
				remove_filter( $hook, $none, PHP_INT_MAX );
			}
		}
	}

	/** SET-VC-29: admin-post handler of "Send test email to me" (capability, nonce, then back to the settings with the result). */
	public static function test_email_request(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'voucher-captain' ), 403 );
		}
		check_admin_referer( self::TEST_MAIL_ACTION );
		$user   = wp_get_current_user();
		$result = self::send_test_email( (int) $user->ID, (string) $user->user_email );
		wp_safe_redirect( add_query_arg( 'vcap_test_email', $result, admin_url( 'admin.php?page=wc-settings&tab=vcap&section=delivery' ) ) );
		exit;
	}

	/**
	 * SET-VC-29: messages of the test email results (query argument "vcap_test_email").
	 *
	 * @return array<string, string>
	 */
	public static function test_email_messages(): array {
		return array(
			'sent'       => __( 'The test email was sent to your address.', 'voucher-captain' ),
			/* translators: %d: number of test emails per hour */
			'limit'      => sprintf( __( 'You have sent %d test emails within the last hour. Please try again later.', 'voucher-captain' ), self::TEST_MAIL_LIMIT ),
			'no_address' => __( 'Your user account has no valid email address.', 'voucher-captain' ),
			'failed'     => __( 'The test email could not be sent. Check the email settings of WooCommerce and your mail server.', 'voucher-captain' ),
		);
	}

	/**
	 * SET-VC-29: settings field "Test email" (WooCommerce field type "vcap_test_email"): a button that sends the
	 * gift recipient email with the sample voucher to the current user.
	 *
	 * @param array<string, mixed> $field Settings field.
	 */
	public static function test_email_field( $field ): void {
		$field    = is_array( $field ) ? $field : array();
		$url      = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::TEST_MAIL_ACTION ), self::TEST_MAIL_ACTION );
		$status   = isset( $_GET['vcap_test_email'] ) ? sanitize_key( wp_unslash( (string) $_GET['vcap_test_email'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display of a result only.
		$messages = self::test_email_messages();
		$user     = wp_get_current_user();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( (string) ( $field['title'] ?? '' ) ); ?></th>
			<td class="forminp">
				<a class="button" href="<?php echo esc_url( $url ); ?>"><?php esc_html_e( 'Send test email to me', 'voucher-captain' ); ?></a>
				<?php if ( isset( $messages[ $status ] ) ) : ?>
					<p role="status"><strong><?php echo esc_html( $messages[ $status ] ); ?></strong></p>
				<?php endif; ?>
				<p class="description">
					<?php
					/* translators: 1: email address of the current user, 2: number of test emails per hour */
					echo esc_html( sprintf( __( 'Sends the gift recipient email with a sample voucher (without PDF) to %1$s. Save your changes first. At most %2$d test emails per hour.', 'voucher-captain' ), (string) $user->user_email, self::TEST_MAIL_LIMIT ) );
					?>
				</p>
			</td>
		</tr>
		<?php
	}

	public static function sample_voucher(): Voucher {
		$v                  = new Voucher();
		$v->code            = 'GIFT-7KQ2-M9XA';
		$v->initial_amount  = 50;
		$v->balance         = 50;
		$v->currency        = get_woocommerce_currency();
		$v->expires_at      = gmdate( 'Y-m-d 23:59:59', time() + YEAR_IN_SECONDS );
		$v->buyer_name      = __( 'Alex Buyer', 'voucher-captain' );
		$v->buyer_email     = 'buyer@example.com';
		$v->recipient_name  = __( 'Sam Recipient', 'voucher-captain' );
		$v->recipient_email = 'recipient@example.com';
		$v->message         = __( 'Happy birthday! Enjoy something nice.', 'voucher-captain' );
		$v->created_at      = gmdate( 'Y-m-d H:i:s' );
		$v->token           = str_repeat( '0', 48 );
		$v->max_checkins    = 1;
		return apply_filters( 'vcap_sample_voucher', $v );
	}

	/** Sample placeholder values for the designer canvas. */
	public static function sample_values(): array {
		$values = Placeholders::values( self::sample_voucher() );
		$values['product_name']   = __( 'Gift card', 'voucher-captain' );
		$values['order_number']   = '1042';
		$values['order_id']       = '1042';
		$values['order_date']     = wp_date( get_option( 'date_format' ) );
		$values['event_date']     = wp_date( get_option( 'date_format' ), time() + MONTH_IN_SECONDS );
		$values['event_location'] = __( 'Main hall', 'voucher-captain' );
		return $values;
	}

	/**
	 * Sends a PDF and ends the request. Output buffers (e.g. of a theme or a plugin) are discarded and output
	 * compression is switched off, so the bytes match Content-Length and the PDF is not corrupted; the response
	 * is kept out of page caches (DONOTCACHEPAGE and no-cache headers, see Shortcodes::no_cache()).
	 */
	public static function stream( string $pdf, string $filename, bool $inline = true ): void {
		Shortcodes::no_cache();
		if ( function_exists( 'wc_clean_buffers' ) ) {
			wc_clean_buffers();
		} else {
			while ( ob_get_level() > 0 && @ob_end_clean() ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- a buffer that cannot be removed ends the loop.
				continue;
			}
		}
		if ( function_exists( 'ini_get' ) && ini_get( 'zlib.output_compression' ) && ! headers_sent() ) {
			@ini_set( 'zlib.output_compression', 'Off' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.IniSet.Risky -- needed for a correct Content-Length.
		}
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'Content-Encoding: identity' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	/**
	 * Customer preview of the personalised voucher before purchase (watermarked, fake code).
	 */
	public function product_preview(): void {
		// Guests need no nonce (a cached product page would carry an expired one or another session's): the
		// preview only renders a watermarked sample and is rate limited below. A logged-in customer whose nonce
		// is missing or expired (cached page) gets the preview of a guest, without the account's name.
		$trusted = is_user_logged_in() && false !== check_ajax_referer( 'vcap_preview', 'nonce', false );

		// Abuse protection (PDF rendering is expensive): 20 previews per visitor per 10 minutes; above 300
		// site-wide every visitor's quota shrinks (brake), so nobody can switch the preview off for everyone.
		if ( Plugin::rate_limited( 'preview', 20, 300, 10 * MINUTE_IN_SECONDS ) ) {
			wp_die( esc_html__( 'Too many preview requests. Please try again later.', 'voucher-captain' ), 429 );
		}

		$product_id = absint( $_REQUEST['variation_id'] ?? 0 ) ?: absint( $_REQUEST['product_id'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public, read-only preview (see above).
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
			wp_die( esc_html__( 'Invalid product.', 'voucher-captain' ), 400 );
		}
		$parent_id = $product->get_parent_id() ?: $product->get_id();
		$parent    = $parent_id === $product->get_id() ? $product : wc_get_product( $parent_id );
		if ( ! self::public_product( (string) get_post_status( $parent_id ), post_password_required( $parent_id ), $parent ? (string) $parent->get_catalog_visibility() : 'hidden', current_user_can( 'edit_post', $parent_id ) ) ) {
			wp_die( esc_html__( 'Invalid product.', 'voucher-captain' ), 400 );
		}
		$config = ProductConfig::get( $product );
		if ( 'yes' !== $config['preview'] ) {
			wp_die( esc_html__( 'Preview is not available for this product.', 'voucher-captain' ), 403 );
		}
		$amount = wp_unslash( (string) ( $_REQUEST['vcap_amount'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- formatted in preview().
		list( $v, $design ) = $this->preview( $product, (string) $amount, $trusted );
		$design             = self::with_fit_banner( $design, $v );
		// M12-73: the same personalisation is rendered once per 10 minutes (customers open the preview again and
		// again while typing; a template with a large transparent PNG costs seconds per PDF).
		$cache = self::cache_file( $design, $v );
		if ( is_readable( $cache ) && (int) filemtime( $cache ) > time() - self::CACHE_SECONDS ) {
			$pdf = (string) file_get_contents( $cache ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- private local file.
			if ( str_starts_with( $pdf, '%PDF-' ) ) {
				self::stream( $pdf, 'preview.pdf' );
			}
		}
		$pdf = $this->pdf->render( $v, $design );
		if ( Install::ensure_upload_dir() ) {
			$part = $cache . '.' . wp_generate_password( 8, false ) . '.part';
			if ( false !== file_put_contents( $part, $pdf ) && ! rename( $part, $cache ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				wp_delete_file( $part );
			}
		}
		self::stream( $pdf, 'preview.pdf' );
	}

	/**
	 * S13: whether the customer preview may show a product's template data (dates, places, texts): only for a
	 * published product whose password (if any) the visitor entered and that is not hidden from the catalog –
	 * people who may edit the product see it in any case.
	 *
	 * @param string $status            Post status of the (parent) product.
	 * @param bool   $password_required post_password_required() for the visitor.
	 * @param string $visibility        Catalog visibility (visible, catalog, search, hidden).
	 * @param bool   $can_edit          Whether the visitor may edit the product.
	 */
	public static function public_product( string $status, bool $password_required, string $visibility, bool $can_edit ): bool {
		return 'publish' === $status && ( $can_edit || ( ! $password_required && 'hidden' !== $visibility ) );
	}

	/** Lifetime of a cached customer preview in seconds (M12-73). */
	public const CACHE_SECONDS = 10 * MINUTE_IN_SECONDS;

	/**
	 * Private file of a cached customer preview. The name is a keyed hash of everything the PDF shows: the design,
	 * every placeholder value (personalisation, amount, names, dates), the language and the plugin version.
	 */
	public static function cache_file( array $design, Voucher $v ): string {
		$key = wp_json_encode( array( $design, Placeholders::values( $v ), $v->fields, function_exists( 'determine_locale' ) ? determine_locale() : get_locale(), defined( 'VCAP_VERSION' ) ? VCAP_VERSION : '' ) );
		return Install::upload_dir() . '/tmp/pv-' . hash_hmac( 'sha256', (string) $key, vcap()->settings->secret() ) . '.pdf';
	}

	/**
	 * M12-39: the customer preview says when a text does not fit (it would be cut off) or is printed small: a red
	 * note at the top of the page, with the number of lines the box holds.
	 */
	public static function with_fit_banner( array $design, Voucher $v ): array {
		$notes = array();
		foreach ( PdfRenderer::fit_report( $design, $v ) as $row ) {
			if ( ! $row['fits'] ) {
				/* translators: %d: number of lines */
				$notes[] = sprintf( __( 'Text too long: it is cut off on the voucher (max. approx. %d lines).', 'voucher-captain' ), $row['max_lines'] );
			} elseif ( $row['scale'] < PdfRenderer::SHRINK_NOTICE ) {
				/* translators: %d: percentage */
				$notes[] = sprintf( __( 'Long text: printed at %d %% of the normal size.', 'voucher-captain' ), (int) round( $row['scale'] * 100 ) );
			}
		}
		if ( ! $notes ) {
			return $design;
		}
		list( $page_w )        = PdfRenderer::page_size( (array) ( $design['page'] ?? array() ) );
		$design['elements'][] = array(
			'id'    => 'vcap-fit-note',
			'type'  => 'text',
			'x'     => 3,
			'y'     => 3,
			'w'     => max( 10, $page_w - 6 ),
			'h'     => 6 + 4 * count( array_unique( $notes ) ),
			'text'  => implode( "\n", array_unique( $notes ) ),
			'size'  => 9,
			'bold'  => true,
			'color' => '#ffffff',
			'fill'  => '#cc0000',
			'align' => 'center',
		);
		return $design;
	}

	/**
	 * Sample voucher and design of a customer preview: the customer's personalisation, an amount the product
	 * really sells (custom amounts outside the allowed range show the minimum), every QR code and barcode as a
	 * fixed placeholder (a preview must not carry a foreign link or code) and a watermark across the whole page.
	 *
	 * @param bool $customer Whether the account's billing name may be shown (logged-in customer, valid nonce).
	 * @return array{0: Voucher, 1: array}
	 */
	public function preview( \WC_Product $product, string $raw_amount, bool $customer ): array {
		$config = ProductConfig::get( $product );
		$fields = vcap()->fields->collect_posted( $product, false );
		$v      = self::sample_voucher();
		$v->code            = 'PREVIEW';
		$v->type            = $config['type'];
		$v->product_id      = $product->get_parent_id() ?: $product->get_id();
		$v->variation_id    = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		$v->template_id     = (int) $config['template'];
		$v->discount_type   = $config['discount_type'];
		// The name the order would carry (billing name), as Placeholders gives it for the real voucher.
		$account            = $customer && function_exists( 'WC' ) && WC()->customer ? WC()->customer : null;
		// WooCommerce's own name format, as WC_Order::get_formatted_billing_full_name().
		$billing            = $account ? trim( sprintf( translate_with_gettext_context( '%1$s %2$s', 'full name', 'woocommerce' ), $account->get_billing_first_name(), $account->get_billing_last_name() ) ) : ''; // phpcs:ignore WordPress.WP.I18n -- WooCommerce's own string.
		$v->buyer_name      = '' !== $billing ? $billing : ( $customer ? wp_get_current_user()->display_name : '' );
		$v->buyer_email     = '';
		$v->recipient_name  = (string) ( $fields['recipient_name']['value'] ?? '' );
		$v->recipient_email = (string) ( $fields['recipient_email']['value'] ?? '' );
		$v->message         = (string) ( $fields['message']['value'] ?? '' );
		$v->fields          = $fields;
		$v->max_checkins    = (int) $config['max_checkins'];
		$v->expires_at      = ProductConfig::expiry_for( $config );
		$v->delivery_at     = Delivery::compute_time( $config, $fields ); // The date the voucher would really go out.

		switch ( $config['amount_source'] ) {
			case 'fixed':
				$amount = (float) $config['amount'];
				break;
			case 'custom':
				$raw    = (string) wc_format_decimal( $raw_amount );
				$amount = null === CustomAmount::error_for( $config, $raw ) ? (float) $raw : (float) $config['custom_min'];
				break;
			default:
				$amount = (float) wc_get_price_including_tax( $product ) / max( 1, (int) $config['per_item'] );
		}
		$v->initial_amount = $amount;
		$v->balance        = $amount;

		$design = TemplatePostType::get_design( $v->template_id );
		foreach ( (array) ( $design['elements'] ?? array() ) as $i => $el ) {
			if ( is_array( $el ) && in_array( $el['type'] ?? '', array( 'qrcode', 'barcode' ), true ) ) {
				$design['elements'][ $i ]['qrContent'] = 'custom';
				$design['elements'][ $i ]['qrValue']   = 'PREVIEW';
			}
		}
		list( $page_w, $page_h ) = PdfRenderer::page_size( $design['page'] );
		$rows                    = max( 3, (int) ceil( $page_h / 45 ) );
		for ( $row = 0; $row < $rows; $row++ ) {
			$design['elements'][] = array(
				'id'       => 'vcap-watermark-' . $row,
				'type'     => 'text',
				'x'        => 0,
				'y'        => ( $row + 0.5 ) * $page_h / $rows - 12,
				'w'        => $page_w,
				'h'        => 24,
				'text'     => __( 'PREVIEW', 'voucher-captain' ),
				'size'     => 48,
				'bold'     => true,
				'align'    => 'center',
				'color'    => '#cc0000',
				'opacity'  => 0.5,
				'rotation' => -20,
			);
		}
		$design = apply_filters( 'vcap_customer_preview_design', $design, $v, $product );
		return array( $v, $design );
	}
}
