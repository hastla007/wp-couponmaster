<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-53: a lightweight live preview next to the voucher fields of the product page. The design of the product's
 * template (page size, background, texts, boxes, images) is handed to frontend.js as data; the script draws it as
 * HTML and fills in the recipient name, the message and the amount while the customer types. Placeholders that do
 * not depend on the input are filled in here with sample values; QR codes and barcodes are grey boxes. The PDF
 * stays available as "Print preview" (Preview::product_preview()).
 */
final class LivePreview {
	/** Placeholders the script fills from the form (all others are filled in here). */
	public const LIVE = array( 'recipient_name', 'holder_name', 'message', 'coupon_value', 'coupon_value_plain', 'coupon_balance' );

	public function register(): void {
		add_action( 'vcap_before_fields', array( $this, 'render' ), 40 );
	}

	/** One template's design reduced to what the HTML preview draws. */
	public static function design_data( int $template_id, Voucher $sample ): array {
		$design = TemplatePostType::get_design( $template_id );
		list( $w, $h ) = PdfRenderer::page_size( (array) $design['page'] );
		$values = Placeholders::values( $sample );
		foreach ( self::LIVE as $token ) {
			$values[ $token ] = '{' . $token . '}'; // Left for the script.
		}
		$page     = (array) $design['page'];
		$bg_image = (string) ( $page['backgroundImage'] ?? '' );
		if ( ! empty( $page['backgroundImageId'] ) ) {
			$bg_image = (string) wp_get_attachment_image_url( (int) $page['backgroundImageId'], 'large' );
		}
		$elements = array();
		foreach ( (array) $design['elements'] as $el ) {
			$el = (array) $el;
			if ( ! empty( $el['hidden'] ) ) {
				continue;
			}
			$out = array(
				'type' => (string) ( $el['type'] ?? '' ),
				'x'    => (float) ( $el['x'] ?? 0 ),
				'y'    => (float) ( $el['y'] ?? 0 ),
				'w'    => (float) ( $el['w'] ?? 0 ),
				'h'    => (float) ( $el['h'] ?? 0 ),
				'rot'  => (float) ( $el['rotation'] ?? 0 ),
				'op'   => (float) ( $el['opacity'] ?? 1 ),
			);
			switch ( $out['type'] ) {
				case 'text':
				case 'heading':
					$out['text']   = Placeholders::replace( (string) ( $el['text'] ?? '' ), $sample, $values );
					$out['size']   = (float) ( $el['size'] ?? 10 );
					$out['color']  = (string) ( $el['color'] ?? '#000000' );
					$out['align']  = (string) ( $el['align'] ?? 'left' );
					$out['bold']   = ! empty( $el['bold'] ) || 'heading' === $out['type'];
					$out['italic'] = ! empty( $el['italic'] );
					$out['upper']  = ! empty( $el['uppercase'] );
					$out['serif']  = str_contains( (string) ( $el['font'] ?? '' ), 'Serif' );
					$out['mono']   = str_contains( (string) ( $el['font'] ?? '' ), 'Mono' );
					break;
				case 'rect':
				case 'line':
					$out['fill']   = (string) ( $el['fill'] ?? 'transparent' );
					$out['border'] = (string) ( $el['borderColor'] ?? ( 'line' === $out['type'] ? (string) ( $el['color'] ?? '#000000' ) : 'transparent' ) );
					$out['bw']     = (float) ( $el['borderWidth'] ?? ( 'line' === $out['type'] ? 0.3 : 0 ) );
					$out['radius'] = (float) ( $el['radius'] ?? 0 );
					break;
				case 'image':
					$src        = ! empty( $el['imageId'] ) ? (string) wp_get_attachment_image_url( (int) $el['imageId'], 'medium' ) : (string) ( $el['src'] ?? '' );
					$out['src'] = esc_url_raw( $src );
					if ( '' === $out['src'] ) {
						continue 2;
					}
					break;
				case 'qrcode':
				case 'barcode':
					$out['type'] = 'code';
					break;
				default:
					continue 2;
			}
			$elements[] = $out;
		}
		return array(
			'title'    => get_the_title( $template_id ),
			'w'        => (float) $w,
			'h'        => (float) $h,
			'bg'       => (string) ( $page['background'] ?? '#ffffff' ),
			'bgImage'  => esc_url_raw( $bg_image ),
			'bgFit'    => (string) ( $page['backgroundFit'] ?? 'cover' ),
			'elements' => $elements,
		);
	}

	/**
	 * Preview data of a product: the designs it can be printed with (its template, the templates of its variations
	 * and the design gallery's), the amount and template per variation and the money format.
	 */
	public static function data( \WC_Product $product ): array {
		$config  = ProductConfig::get( $product );
		$sample  = Preview::sample_voucher();
		$sample->code          = 'PREVIEW';
		$sample->type          = (string) $config['type'];
		$sample->product_id    = $product->get_parent_id() ?: $product->get_id();
		$sample->discount_type = (string) $config['discount_type'];
		$sample->recipient_name = '';
		$sample->message        = '';
		$templates  = array_values( array_unique( array_filter( array_merge( array( (int) $config['template'] ), DesignGallery::allowed( $config ) ) ) ) );
		$variations = array();
		if ( $product->is_type( 'variable' ) ) {
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( ! $child ) {
					continue;
				}
				$child_config               = ProductConfig::get( $child );
				$variations[ (int) $child_id ] = array(
					'template' => (int) $child_config['template'],
					'amount'   => self::fixed_amount( $child_config, $child ),
				);
				$templates[] = (int) $child_config['template'];
			}
		}
		$designs = array();
		foreach ( array_unique( array_filter( $templates ) ) as $id ) {
			$designs[ (int) $id ] = self::design_data( (int) $id, $sample );
		}
		return array(
			'template'   => (int) $config['template'],
			'designs'    => $designs,
			'variations' => $variations,
			'amount'     => self::fixed_amount( $config, $product ),
			'percent'    => 'percent' === $config['discount_type'],
			'money'      => array(
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'pos'      => (string) get_option( 'woocommerce_currency_pos', 'left' ),
				'decimals' => wc_get_price_decimals(),
				'dec'      => wc_get_price_decimal_separator(),
				'thousand' => wc_get_price_thousand_separator(),
			),
		);
	}

	/** The value shown until the customer types an amount: the fixed value, else the price (null: none). */
	private static function fixed_amount( array $config, \WC_Product $product ): ?float {
		if ( 'fixed' === $config['amount_source'] && '' !== (string) $config['amount'] ) {
			return (float) wc_format_decimal( (string) $config['amount'] );
		}
		$price = $product->get_price();
		return '' !== (string) $price ? (float) $price : null;
	}

	public function render( $product ): void {
		if ( ! $product instanceof \WC_Product || ! ProductConfig::is_voucher( $product ) || TopUp::is_topup( $product ) ) {
			return;
		}
		$config = ProductConfig::get( $product );
		if ( 'yes' !== $config['preview'] ) {
			return;
		}
		$data = self::data( $product );
		if ( ! $data['designs'] ) {
			return;
		}
		echo '<div class="vcap-live-preview" role="img" aria-label="' . esc_attr__( 'Preview of your voucher (updates while you type)', 'voucher-captain' ) . '" data-vcap-preview="' . esc_attr( (string) wp_json_encode( $data ) ) . '"></div>';
	}
}
