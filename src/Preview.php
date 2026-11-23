<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * PDF previews: admin template preview with sample data, and customer preview on the product page.
 */
final class Preview {
	public function __construct( private PdfRenderer $pdf ) {}

	public function register(): void {
		add_action( 'wp_ajax_vcap_product_preview', array( $this, 'product_preview' ) );
		add_action( 'wp_ajax_nopriv_vcap_product_preview', array( $this, 'product_preview' ) );
		// Front-end endpoint (?wc-ajax=…): works where /wp-admin is protected (HTTP auth, IP allow list).
		add_action( 'wc_ajax_vcap_product_preview', array( $this, 'product_preview' ) );
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
