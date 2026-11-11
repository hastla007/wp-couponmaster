<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * PDF previews: admin template preview with sample data, and customer preview on the product page.
 */
final class Preview {
	public function __construct( private PdfRenderer $pdf ) {}

	public function register(): void {
		add_action( 'wp_ajax_wcvs_product_preview', array( $this, 'product_preview' ) );
		add_action( 'wp_ajax_nopriv_wcvs_product_preview', array( $this, 'product_preview' ) );
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
		return apply_filters( 'wcvs_sample_voucher', $v );
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

	public static function stream( string $pdf, string $filename, bool $inline = true ): void {
		nocache_headers();
		header( 'Content-Type: application/pdf' );
		header( 'Content-Disposition: ' . ( $inline ? 'inline' : 'attachment' ) . '; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . strlen( $pdf ) );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo $pdf; // phpcs:ignore WordPress.Security.EscapeOutput -- binary PDF.
		exit;
	}

	/**
	 * Customer preview of the personalised voucher before purchase (watermarked, fake code).
	 */
	public function product_preview(): void {
		check_ajax_referer( 'wcvs_preview', 'nonce' );

		// Basic abuse protection: max 20 previews / 10 min per visitor IP.
		$ip_key = 'wcvs_pv_' . md5( (string) \WC_Geolocation::get_ip_address() );
		$count  = (int) get_transient( $ip_key );
		if ( $count > 20 ) {
			wp_die( esc_html__( 'Too many preview requests. Please try again later.', 'voucher-captain' ), 429 );
		}
		set_transient( $ip_key, $count + 1, 10 * MINUTE_IN_SECONDS );

		$product_id = absint( $_REQUEST['variation_id'] ?? 0 ) ?: absint( $_REQUEST['product_id'] ?? 0 );
		$product    = wc_get_product( $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) || 'publish' !== get_post_status( $product->get_parent_id() ?: $product->get_id() ) ) {
			wp_die( esc_html__( 'Invalid product.', 'voucher-captain' ), 400 );
		}
		$config = ProductConfig::get( $product );
		if ( 'yes' !== $config['preview'] ) {
			wp_die( esc_html__( 'Preview is not available for this product.', 'voucher-captain' ), 403 );
		}

		$fields = wcvs()->fields->collect_posted( $product, false );
		$v      = self::sample_voucher();
		$v->code            = 'PREVIEW';
		$v->type            = $config['type'];
		$v->product_id      = $product->get_parent_id() ?: $product->get_id();
		$v->variation_id    = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		$v->template_id     = (int) $config['template'];
		$v->discount_type   = $config['discount_type'];
		$v->buyer_name      = is_user_logged_in() ? wp_get_current_user()->display_name : '';
		$v->buyer_email     = '';
		$v->recipient_name  = (string) ( $fields['recipient_name']['value'] ?? '' );
		$v->recipient_email = (string) ( $fields['recipient_email']['value'] ?? '' );
		$v->message         = (string) ( $fields['message']['value'] ?? '' );
		$v->fields          = $fields;
		$v->max_checkins    = (int) $config['max_checkins'];
		$v->expires_at      = ProductConfig::expiry_for( $config );

		switch ( $config['amount_source'] ) {
			case 'fixed':
				$amount = (float) $config['amount'];
				break;
			case 'custom':
				$amount = (float) wc_format_decimal( wp_unslash( (string) ( $_REQUEST['wcvs_amount'] ?? '' ) ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				$amount = $amount > 0 ? $amount : (float) $config['custom_min'];
				break;
			default:
				$amount = (float) wc_get_price_including_tax( $product ) / max( 1, (int) $config['per_item'] );
		}
		$v->initial_amount = $amount;
		$v->balance        = $amount;

		$design               = TemplatePostType::get_design( $v->template_id );
		$design['elements'][] = array(
			'id'       => 'wcvs-watermark',
			'type'     => 'text',
			'x'        => 0,
			'y'        => PdfRenderer::page_size( $design['page'] )[1] / 2 - 12,
			'w'        => PdfRenderer::page_size( $design['page'] )[0],
			'h'        => 24,
			'text'     => __( 'PREVIEW', 'voucher-captain' ),
			'size'     => 48,
			'bold'     => true,
			'align'    => 'center',
			'color'    => '#cc0000',
			'opacity'  => 0.25,
			'rotation' => -20,
		);
		$design = apply_filters( 'wcvs_customer_preview_design', $design, $v, $product );
		self::stream( $this->pdf->render( $v, $design ), 'preview.pdf' );
	}
}
