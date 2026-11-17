<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * M12-96: the customer chooses the design. A voucher product lists the PDF templates allowed for it
 * ("allowed_templates"); with two or more, the product page shows them as a radio group with a small picture of
 * each. The choice is checked against the list, kept as cart item data "vcap_template" and order item meta
 * "_vcap_template", and Issuer::create_voucher() prints the voucher with it. An own photo is not part of the core.
 */
final class DesignGallery {
	public const CART_KEY  = 'vcap_template';
	public const ITEM_META = '_vcap_template';

	public function register(): void {
		add_action( 'vcap_before_fields', array( $this, 'render' ), 30 );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 20, 2 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 20, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item' ), 20, 3 );
		// The PDF print preview of the product page shows the chosen design as well.
		add_filter( 'vcap_product_config', array( $this, 'preview_template' ), 20, 1 );
	}

	/**
	 * Published templates the customer may choose from (in the order given); empty when there is no choice (fewer
	 * than two allowed templates).
	 *
	 * @return int[]
	 */
	public static function allowed( array $config ): array {
		$ids = array();
		foreach ( (array) ( $config['allowed_templates'] ?? array() ) as $id ) {
			$id = (int) $id;
			if ( $id > 0 && TemplatePostType::POST_TYPE === get_post_type( $id ) && 'publish' === get_post_status( $id ) ) {
				$ids[] = $id;
			}
		}
		$ids = array_values( array_unique( $ids ) );
		return count( $ids ) >= 2 ? $ids : array();
	}

	/** The template a choice stands for: a valid choice, else 0 (the product's template applies). */
	public static function valid_choice( array $config, $choice ): int {
		$choice = absint( $choice );
		return $choice && in_array( $choice, self::allowed( $config ), true ) ? $choice : 0;
	}

	/** Template of a voucher of this order line: the customer's valid choice, else the product's template. */
	public static function template_for_item( \WC_Order_Item_Product $item, array $config ): int {
		$choice = self::valid_choice( $config, $item->get_meta( self::ITEM_META ) );
		return $choice ? $choice : (int) $config['template'];
	}

	/** The posted choice of the add-to-cart form (0 = none). */
	private static function posted(): int {
		return absint( wp_unslash( $_POST[ self::CART_KEY ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce's add-to-cart form has no nonce; checked against the list.
	}

	/** Picture of a design for the radio group: background colour and image, page proportions. */
	public static function thumb_style( int $template_id ): string {
		$design = TemplatePostType::get_design( $template_id );
		$page   = (array) $design['page'];
		list( $w, $h ) = PdfRenderer::page_size( $page );
		$image  = ! empty( $page['backgroundImageId'] ) ? (string) wp_get_attachment_image_url( (int) $page['backgroundImageId'], 'medium' ) : (string) ( $page['backgroundImage'] ?? '' );
		$style  = 'background-color:' . TemplatePostType::sanitize_color( $page['background'] ?? '#ffffff' ) . ';aspect-ratio:' . round( (float) $w, 1 ) . '/' . round( (float) $h, 1 ) . ';';
		// The first filled box (e.g. a coloured band) as a stripe, so designs without an image still differ.
		foreach ( (array) $design['elements'] as $el ) {
			if ( 'rect' === ( $el['type'] ?? '' ) && ! empty( $el['fill'] ) && 'transparent' !== $el['fill'] && empty( $el['hidden'] ) ) {
				$from  = max( 0, min( 100, round( (float) $el['x'] / max( 1, (float) $w ) * 100 ) ) );
				$to    = max( $from, min( 100, round( ( (float) $el['x'] + (float) $el['w'] ) / max( 1, (float) $w ) * 100 ) ) );
				$color = TemplatePostType::sanitize_color( $el['fill'] );
				$style .= 'background-image:linear-gradient(90deg,transparent ' . $from . '%,' . $color . ' ' . $from . '%,' . $color . ' ' . $to . '%,transparent ' . $to . '%)' . ( '' !== $image ? ',url(' . esc_url_raw( $image ) . ')' : '' ) . ';background-size:cover;';
				return $style;
			}
		}
		if ( '' !== $image ) {
			$style .= 'background-image:url(' . esc_url_raw( $image ) . ');background-size:cover;background-position:center;';
		}
		return $style;
	}

	public function render( $product ): void {
		if ( ! $product instanceof \WC_Product || ! ProductConfig::is_voucher( $product ) ) {
			return;
		}
		$config  = ProductConfig::get( $product );
		$allowed = self::allowed( $config );
		if ( ! $allowed ) {
			return;
		}
		$current = self::valid_choice( $config, self::posted() );
		$current = $current ? $current : ( in_array( (int) $config['template'], $allowed, true ) ? (int) $config['template'] : $allowed[0] );
		echo '<fieldset class="vcap-design-gallery"><legend>' . esc_html__( 'Choose a design', 'voucher-captain' ) . '</legend><div class="vcap-design-options">';
		foreach ( $allowed as $id ) {
			$input_id = 'vcap-design-' . $product->get_id() . '-' . $id;
			echo '<label class="vcap-design-option" for="' . esc_attr( $input_id ) . '">';
			echo '<input type="radio" id="' . esc_attr( $input_id ) . '" name="' . esc_attr( self::CART_KEY ) . '" value="' . esc_attr( (string) $id ) . '"' . checked( $current, $id, false ) . ' />';
			echo '<span class="vcap-design-thumb" aria-hidden="true" style="' . esc_attr( self::thumb_style( $id ) ) . '"></span>';
			echo '<span class="vcap-design-name">' . esc_html( get_the_title( $id ) ) . '</span></label>';
		}
		echo '</div></fieldset>';
	}

	/**
	 * @param mixed $passed Whether the product may be added so far.
	 * @return mixed
	 */
	public function validate( $passed, $product_id ) {
		$product = wc_get_product( absint( wp_unslash( $_POST['variation_id'] ?? 0 ) ) ?: (int) $product_id ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see posted().
		if ( ! $passed || ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return $passed;
		}
		$config = ProductConfig::get( $product );
		if ( ! self::allowed( $config ) || ! isset( $_POST[ self::CART_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- see posted().
			return $passed; // No choice offered, or none sent (e.g. a shop page button): the product's template.
		}
		if ( ! self::valid_choice( $config, self::posted() ) ) {
			wc_add_notice( __( 'Please choose one of the designs shown.', 'voucher-captain' ), 'error' );
			return false;
		}
		return $passed;
	}

	/** @param mixed $data Cart item data so far. */
	public function cart_item_data( $data, $product_id, $variation_id ) {
		$data    = is_array( $data ) ? $data : array();
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) || isset( $data[ self::CART_KEY ] ) ) {
			return $data;
		}
		$choice = self::valid_choice( ProductConfig::get( $product ), self::posted() );
		if ( $choice ) {
			$data[ self::CART_KEY ] = $choice; // Part of the cart item key: one line per design.
		}
		return $data;
	}

	/** @param mixed $item_data Lines shown under the cart item. */
	public function display_item_data( $item_data, $cart_item ) {
		$item_data = is_array( $item_data ) ? $item_data : array();
		if ( ! empty( $cart_item[ self::CART_KEY ] ) ) {
			$item_data[] = array(
				'key'   => __( 'Design', 'voucher-captain' ),
				'value' => esc_html( get_the_title( (int) $cart_item[ self::CART_KEY ] ) ),
			);
		}
		return $item_data;
	}

	public function save_order_item( $item, $cart_item_key, $values ): void {
		if ( $item instanceof \WC_Order_Item_Product && ! empty( $values[ self::CART_KEY ] ) ) {
			$item->update_meta_data( self::ITEM_META, (int) $values[ self::CART_KEY ] );
		}
	}

	/**
	 * During the product page's PDF print preview the posted, valid design replaces the product's template.
	 *
	 * @param mixed $config Product configuration.
	 * @return mixed
	 */
	public function preview_template( $config ) {
		if ( ! is_array( $config ) || ! ( doing_action( 'wc_ajax_vcap_product_preview' ) || doing_action( 'wp_ajax_vcap_product_preview' ) || doing_action( 'wp_ajax_nopriv_vcap_product_preview' ) ) ) {
			return $config;
		}
		$choice = absint( wp_unslash( $_REQUEST[ self::CART_KEY ] ?? 0 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only preview, checked against the list.
		$choice = self::valid_choice( $config, $choice );
		if ( $choice ) {
			$config['template'] = $choice;
		}
		return $config;
	}
}
