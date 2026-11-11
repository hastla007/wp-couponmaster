<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * Product-page personalisation: built-in recipient/message/delivery-date fields and merchant-defined field groups
 * (assignable to all, selected products, categories or tags), carried through cart → order item → voucher.
 */
final class Fields {
	public const POST_TYPE = 'wcvs_field_group';
	public const TYPES     = array( 'text', 'textarea', 'email', 'number', 'date', 'select', 'checkbox' );

	public const POSITIONS = array(
		'before_quantity' => 'woocommerce_before_add_to_cart_quantity',
		'before_button'   => 'woocommerce_before_add_to_cart_button',
		'after_button'    => 'woocommerce_after_add_to_cart_button',
	);

	public function register(): void {
		add_action( 'init', array( $this, 'register_post_type' ) );
		foreach ( self::POSITIONS as $position => $hook ) {
			add_action(
				$hook,
				function () use ( $position ) {
					$this->render( $position );
				},
				'before_button' === $position ? 5 : 10
			);
		}
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 4 );
		add_filter( 'woocommerce_add_cart_item_data', array( $this, 'cart_item_data' ), 10, 3 );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item' ), 10, 4 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'woocommerce_product_add_to_cart_url', array( $this, 'loop_add_to_cart_url' ), 10, 2 );
		add_filter( 'woocommerce_product_supports', array( $this, 'loop_ajax_support' ), 10, 3 );
		add_filter( 'woocommerce_product_add_to_cart_text', array( $this, 'loop_add_to_cart_text' ), 10, 2 );

		if ( is_admin() ) {
			add_action( 'add_meta_boxes_' . self::POST_TYPE, array( $this, 'meta_boxes' ) );
			add_action( 'save_post_' . self::POST_TYPE, array( $this, 'save_group' ), 10, 2 );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );
		}
	}

	public function register_post_type(): void {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Field groups', 'wc-voucher-studio' ),
					'singular_name' => __( 'Field group', 'wc-voucher-studio' ),
					'add_new'       => __( 'Add field group', 'wc-voucher-studio' ),
					'add_new_item'  => __( 'Add field group', 'wc-voucher-studio' ),
					'edit_item'     => __( 'Edit field group', 'wc-voucher-studio' ),
					'all_items'     => __( 'Field groups', 'wc-voucher-studio' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wcvs-vouchers',
				'supports'        => array( 'title', 'page-attributes' ),
				'capability_type' => 'product',
				'map_meta_cap'    => true,
				'rewrite'         => false,
			)
		);
	}

	// ----------------------------------------------------------------- Definitions.

	public static function builtin_definitions( array $config ): array {
		$defs = array(
			'recipient_name'  => array(
				'key'         => 'recipient_name',
				'label'       => __( 'Recipient name', 'wc-voucher-studio' ),
				'type'        => 'text',
				'required'    => false,
				'maxlength'   => 100,
				'placeholder' => '',
				'tooltip'     => __( 'Printed on the voucher.', 'wc-voucher-studio' ),
			),
			'recipient_email' => array(
				'key'         => 'recipient_email',
				'label'       => __( 'Recipient email', 'wc-voucher-studio' ),
				'type'        => 'email',
				'required'    => false,
				'maxlength'   => 200,
				'placeholder' => '',
				'tooltip'     => __( 'Leave empty to receive the voucher yourself.', 'wc-voucher-studio' ),
			),
			'message'         => array(
				'key'         => 'message',
				'label'       => __( 'Personal message', 'wc-voucher-studio' ),
				'type'        => 'textarea',
				'required'    => false,
				'maxlength'   => max( 10, (int) $config['message_maxlength'] ),
				'placeholder' => '',
				'tooltip'     => '',
			),
			'delivery_date'   => array(
				'key'         => 'delivery_date',
				'label'       => __( 'Delivery date', 'wc-voucher-studio' ),
				'type'        => 'date',
				'required'    => false,
				'maxlength'   => 10,
				'placeholder' => '',
				'tooltip'     => __( 'The voucher is emailed on this day. Leave empty to send it right away.', 'wc-voucher-studio' ),
			),
		);
		$enabled = (array) $config['builtin_fields'];
		if ( 'customer' === $config['delivery'] && ! in_array( 'delivery_date', $enabled, true ) ) {
			$enabled[] = 'delivery_date';
		}
		$out = array();
		foreach ( $enabled as $key ) {
			$base = preg_replace( '/_required$/', '', (string) $key );
			if ( isset( $defs[ $base ] ) ) {
				$out[ $base ] = $defs[ $base ];
			}
		}
		foreach ( $enabled as $key ) {
			if ( str_ends_with( (string) $key, '_required' ) ) {
				$base = substr( (string) $key, 0, -9 );
				if ( isset( $out[ $base ] ) ) {
					$out[ $base ]['required'] = true;
				}
			}
		}
		return apply_filters( 'wcvs_builtin_fields', $out, $config );
	}

	/** @return array<int, array{id:int,title:string,position:string,fields:array,assign:array}> */
	public static function groups(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $post ) {
			$fields = json_decode( (string) get_post_meta( $post->ID, '_wcvs_fields', true ), true );
			$assign = json_decode( (string) get_post_meta( $post->ID, '_wcvs_assign', true ), true );
			$cache[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'position' => (string) ( get_post_meta( $post->ID, '_wcvs_position', true ) ?: 'before_button' ),
				'fields'   => is_array( $fields ) ? $fields : array(),
				'assign'   => is_array( $assign ) ? $assign : array( 'mode' => 'all' ),
			);
		}
		return $cache;
	}

	public static function group_applies( array $group, \WC_Product $product ): bool {
		$id     = $product->get_parent_id() ?: $product->get_id();
		$assign = $group['assign'];
		switch ( $assign['mode'] ?? 'all' ) {
			case 'products':
				$applies = in_array( $id, array_map( 'intval', (array) ( $assign['products'] ?? array() ) ), true );
				break;
			case 'categories':
				$applies = (bool) array_intersect( wc_get_product_cat_ids( $id ), array_map( 'intval', (array) ( $assign['categories'] ?? array() ) ) );
				break;
			case 'tags':
				$tags    = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'ids' ) );
				$applies = ! is_wp_error( $tags ) && (bool) array_intersect( $tags, array_map( 'intval', (array) ( $assign['tags'] ?? array() ) ) );
				break;
			default:
				$applies = true;
		}
		return (bool) apply_filters( 'wcvs_field_group_applies', $applies, $group, $product );
	}

	/**
	 * All field definitions for a product, grouped by position.
	 *
	 * @return array<string, array<string, array>> position => key => definition
	 */
	public function definitions_for( \WC_Product $product ): array {
		if ( ! ProductConfig::is_voucher( $product ) && ! ( $product->is_type( 'variable' ) && $this->variable_has_vouchers( $product ) ) ) {
			return array();
		}
		$config = ProductConfig::get( $product );
		$out    = array( 'before_button' => self::builtin_definitions( $config ) );
		foreach ( self::groups() as $group ) {
			if ( ! self::group_applies( $group, $product ) ) {
				continue;
			}
			$position = isset( self::POSITIONS[ $group['position'] ] ) ? $group['position'] : 'before_button';
			foreach ( $group['fields'] as $field ) {
				if ( empty( $field['key'] ) ) {
					continue;
				}
				$out[ $position ][ $field['key'] ] = $field;
			}
		}
		return apply_filters( 'wcvs_field_definitions', array_filter( $out ), $product );
	}

	private function variable_has_vouchers( \WC_Product $product ): bool {
		return 'yes' === $product->get_meta( '_wcvs_enabled' );
	}

	private function flat_definitions( \WC_Product $product ): array {
		$flat = array();
		foreach ( $this->definitions_for( $product ) as $fields ) {
			$flat = array_merge( $flat, $fields );
		}
		return $flat;
	}

	public static function all_field_keys(): array {
		$keys = array();
		foreach ( self::groups() as $group ) {
			foreach ( $group['fields'] as $field ) {
				if ( ! empty( $field['key'] ) ) {
					$keys[ $field['key'] ] = $field['label'] ?? $field['key'];
				}
			}
		}
		return $keys;
	}

	// ----------------------------------------------------------------- Frontend.

	public function assets(): void {
		if ( ! is_product() ) {
			return;
		}
		$product = wc_get_product( get_queried_object_id() );
		if ( ! $product || ! $this->definitions_for( $product ) ) {
			return;
		}
		wp_enqueue_style( 'wcvs-frontend', WCVS_URL . 'assets/css/frontend.css', array(), WCVS_VERSION );
		wp_enqueue_script( 'wcvs-frontend', WCVS_URL . 'assets/js/frontend.js', array( 'jquery' ), WCVS_VERSION, true );
		wp_localize_script(
			'wcvs-frontend',
			'wcvsFront',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wcvs_preview' ),
				'i18n'    => array(
					/* translators: %d: characters left */
					'charsLeft' => __( '%d characters left', 'wc-voucher-studio' ),
				),
			)
		);
	}

	public function render( string $position ): void {
		global $product;
		if ( ! $product instanceof \WC_Product ) {
			return;
		}
		$defs = $this->definitions_for( $product )[ $position ] ?? array();
		$amount_html = '';
		if ( 'before_button' === $position ) {
			ob_start();
			do_action( 'wcvs_before_fields', $product );
			$amount_html = (string) ob_get_clean();
		}
		if ( ! $defs && '' === $amount_html ) {
			return;
		}
		$config = ProductConfig::get( $product );
		echo '<div class="wcvs-fields wcvs-fields--' . esc_attr( $position ) . '">';
		echo $amount_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built by CustomAmount with escaping.
		foreach ( $defs as $key => $field ) {
			$this->render_field( $key, $field );
		}
		if ( 'before_button' === $position && 'yes' === $config['preview'] ) {
			echo '<p class="wcvs-preview-row"><button type="button" class="button wcvs-preview-button" data-product="' . esc_attr( (string) $product->get_id() ) . '">' . esc_html__( 'Preview voucher', 'wc-voucher-studio' ) . '</button></p>';
		}
		echo '</div>';
	}

	private function posted_value( string $key ): string {
		// phpcs:ignore WordPress.Security.NonceVerification -- Add-to-cart form; WooCommerce handles the request.
		$raw = isset( $_POST['wcvs_fields'][ $key ] ) ? wp_unslash( $_POST['wcvs_fields'][ $key ] ) : ( isset( $_REQUEST['wcvs_fields'][ $key ] ) ? wp_unslash( $_REQUEST['wcvs_fields'][ $key ] ) : null ); // phpcs:ignore
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	private function render_field( string $key, array $field ): void {
		$id       = 'wcvs-field-' . sanitize_html_class( $key );
		$name     = 'wcvs_fields[' . esc_attr( $key ) . ']';
		$type     = in_array( $field['type'] ?? 'text', self::TYPES, true ) ? ( $field['type'] ?? 'text' ) : 'text';
		$required = ! empty( $field['required'] );
		$max      = (int) ( $field['maxlength'] ?? 0 );
		$value    = $this->posted_value( $key );
		if ( '' === $value && isset( $field['default'] ) ) {
			$value = (string) $field['default'];
		}
		$desc_id = $id . '-desc';
		$attrs   = sprintf( 'id="%s" name="%s"%s%s', esc_attr( $id ), $name, $required ? ' required aria-required="true"' : '', ! empty( $field['tooltip'] ) ? ' aria-describedby="' . esc_attr( $desc_id ) . '"' : '' );
		if ( $max > 0 && in_array( $type, array( 'text', 'textarea', 'email' ), true ) ) {
			$attrs .= ' maxlength="' . esc_attr( (string) $max ) . '" data-wcvs-counter="1"';
		}
		if ( ! empty( $field['placeholder'] ) ) {
			$attrs .= ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
		}

		echo '<p class="form-row wcvs-field wcvs-field--' . esc_attr( $type ) . '">';
		if ( 'checkbox' !== $type ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ?? $key ) . ( $required ? ' <abbr class="required" title="' . esc_attr__( 'required', 'wc-voucher-studio' ) . '">*</abbr>' : ' <span class="optional">(' . esc_html__( 'optional', 'wc-voucher-studio' ) . ')</span>' ) . '</label>';
		}
		switch ( $type ) {
			case 'textarea':
				echo '<textarea ' . $attrs . ' rows="3">' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'select':
				echo '<select ' . $attrs . '><option value="">' . esc_html__( '— Select —', 'wc-voucher-studio' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( self::options( $field ) as $option ) {
					echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				echo '</select>';
				break;
			case 'checkbox':
				echo '<label for="' . esc_attr( $id ) . '" class="checkbox"><input type="checkbox" value="1" ' . $attrs . checked( '1', $value, false ) . ' /> ' . esc_html( $field['label'] ?? $key ) . ( $required ? ' <abbr class="required" title="' . esc_attr__( 'required', 'wc-voucher-studio' ) . '">*</abbr>' : '' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'date':
				$min = 'delivery_date' === $key ? wp_date( 'Y-m-d', time() + DAY_IN_SECONDS ) : '';
				$maxd = 'delivery_date' === $key ? wp_date( 'Y-m-d', time() + YEAR_IN_SECONDS ) : '';
				echo '<input type="date" ' . $attrs . ' value="' . esc_attr( $value ) . '"' . ( $min ? ' min="' . esc_attr( $min ) . '" max="' . esc_attr( $maxd ) . '"' : '' ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				echo '<input type="' . esc_attr( $type ) . '" class="input-text" ' . $attrs . ' value="' . esc_attr( $value ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
		if ( ! empty( $field['tooltip'] ) ) {
			echo '<span class="description wcvs-field-help" id="' . esc_attr( $desc_id ) . '">' . esc_html( $field['tooltip'] ) . '</span>';
		}
		echo '</p>';
	}

	private static function options( array $field ): array {
		$options = $field['options'] ?? array();
		if ( is_string( $options ) ) {
			$options = preg_split( '/\r\n|\r|\n|\|/', $options );
		}
		return array_values( array_filter( array_map( 'trim', (array) $options ), 'strlen' ) );
	}

	/**
	 * Read and sanitise posted values for a product.
	 *
	 * @return array<string, array{label:string,value:string}>
	 */
	public function collect_posted( \WC_Product $product, bool $only_filled = true ): array {
		$out = array();
		foreach ( $this->flat_definitions( $product ) as $key => $field ) {
			$raw = $this->posted_value( (string) $key );
			switch ( $field['type'] ?? 'text' ) {
				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;
				case 'email':
					$value = sanitize_email( $raw );
					break;
				case 'number':
					$value = '' === $raw ? '' : (string) wc_format_decimal( $raw );
					break;
				case 'date':
					$value = preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) ? $raw : '';
					break;
				case 'checkbox':
					$value = '' !== $raw ? __( 'Yes', 'wc-voucher-studio' ) : '';
					break;
				default:
					$value = sanitize_text_field( $raw );
			}
			$max = (int) ( $field['maxlength'] ?? 0 );
			if ( $max > 0 && mb_strlen( $value ) > $max ) {
				$value = mb_substr( $value, 0, $max );
			}
			if ( '' === $value && $only_filled ) {
				continue;
			}
			$out[ $key ] = array(
				'label' => (string) ( $field['label'] ?? $key ),
				'value' => $value,
			);
		}
		return $out;
	}

	public function validate( $passed, $product_id, $quantity, $variation_id = 0 ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return $passed;
		}
		foreach ( $this->flat_definitions( $product ) as $key => $field ) {
			$raw   = trim( $this->posted_value( (string) $key ) );
			$label = (string) ( $field['label'] ?? $key );
			if ( ! empty( $field['required'] ) && '' === $raw ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '%s is a required field.', 'wc-voucher-studio' ), $label ), 'error' );
				$passed = false;
				continue;
			}
			if ( '' === $raw ) {
				continue;
			}
			$type = $field['type'] ?? 'text';
			if ( 'email' === $type && ! is_email( $raw ) ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '%s is not a valid email address.', 'wc-voucher-studio' ), $label ), 'error' );
				$passed = false;
			}
			if ( 'date' === $type ) {
				$ts = strtotime( $raw );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) || ! $ts ) {
					/* translators: %s: field label */
					wc_add_notice( sprintf( __( '%s is not a valid date.', 'wc-voucher-studio' ), $label ), 'error' );
					$passed = false;
				} elseif ( 'delivery_date' === $key && ( $raw < wp_date( 'Y-m-d' ) || $ts > time() + YEAR_IN_SECONDS + DAY_IN_SECONDS ) ) {
					wc_add_notice( __( 'Please choose a delivery date between today and one year from now.', 'wc-voucher-studio' ), 'error' );
					$passed = false;
				}
			}
			if ( 'number' === $type && ! is_numeric( wc_format_decimal( $raw ) ) ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( '%s must be a number.', 'wc-voucher-studio' ), $label ), 'error' );
				$passed = false;
			}
			if ( 'select' === $type && ! in_array( $raw, self::options( $field ), true ) ) {
				/* translators: %s: field label */
				wc_add_notice( sprintf( __( 'Please choose a valid option for %s.', 'wc-voucher-studio' ), $label ), 'error' );
				$passed = false;
			}
			$max = (int) ( $field['maxlength'] ?? 0 );
			if ( $max > 0 && mb_strlen( $raw ) > $max ) {
				/* translators: 1: field label, 2: max length */
				wc_add_notice( sprintf( __( '%1$s may contain at most %2$d characters.', 'wc-voucher-studio' ), $label, $max ), 'error' );
				$passed = false;
			}
		}
		return $passed;
	}

	public function cart_item_data( $data, $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return $data;
		}
		$fields = $this->collect_posted( $product );
		if ( $fields ) {
			$data['wcvs_fields'] = $fields;
			$data['wcvs_key']    = md5( wp_json_encode( $fields ) ); // Separate cart lines per personalisation.
		}
		return $data;
	}

	public function display_item_data( $item_data, $cart_item ) {
		foreach ( (array) ( $cart_item['wcvs_fields'] ?? array() ) as $field ) {
			$item_data[] = array(
				'key'   => $field['label'],
				'value' => $field['value'],
			);
		}
		return $item_data;
	}

	public function save_order_item( $item, $cart_item_key, $values, $order ): void {
		if ( empty( $values['wcvs_fields'] ) ) {
			return;
		}
		$item->add_meta_data( '_wcvs_fields', $values['wcvs_fields'], true );
		// Visible copy (admin order screen, emails, {item_meta:Label} placeholders).
		foreach ( $values['wcvs_fields'] as $field ) {
			if ( '' !== (string) $field['value'] ) {
				$item->add_meta_data( wp_strip_all_tags( $field['label'] ), $field['value'], true );
			}
		}
	}

	public function order_again( $data, $item, $order ) {
		$fields = $item->get_meta( '_wcvs_fields' );
		if ( $fields ) {
			$data['wcvs_fields'] = $fields;
			$data['wcvs_key']    = md5( wp_json_encode( $fields ) );
		}
		return $data;
	}

	/** Loop "Add to cart" must go to the product page when personalisation fields exist. */
	public function loop_add_to_cart_url( $url, $product ) {
		if ( $product instanceof \WC_Product && $product->is_type( 'simple' ) && ProductConfig::is_voucher( $product ) && $this->has_required_or_custom( $product ) ) {
			return $product->get_permalink();
		}
		return $url;
	}

	public function loop_ajax_support( $supports, $feature, $product ) {
		if ( 'ajax_add_to_cart' === $feature && $product instanceof \WC_Product && ProductConfig::is_voucher( $product ) && $this->has_required_or_custom( $product ) ) {
			return false;
		}
		return $supports;
	}

	public function loop_add_to_cart_text( $text, $product ) {
		if ( $product instanceof \WC_Product && $product->is_type( 'simple' ) && ProductConfig::is_voucher( $product ) && $this->has_required_or_custom( $product ) ) {
			return __( 'Personalise', 'wc-voucher-studio' );
		}
		return $text;
	}

	private function has_required_or_custom( \WC_Product $product ): bool {
		$config = ProductConfig::get( $product );
		return 'custom' === $config['amount_source'] || (bool) $this->flat_definitions( $product );
	}

	// ----------------------------------------------------------------- Admin (field group editor).

	public function admin_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'wcvs-admin', WCVS_URL . 'assets/css/admin.css', array(), WCVS_VERSION );
		wp_enqueue_script( 'wcvs-field-groups', WCVS_URL . 'assets/js/field-groups.js', array( 'jquery', 'jquery-ui-sortable', 'wc-enhanced-select' ), WCVS_VERSION, true );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_localize_script(
			'wcvs-field-groups',
			'wcvsFieldGroups',
			array(
				'types' => array(
					'text'     => __( 'Text', 'wc-voucher-studio' ),
					'textarea' => __( 'Text area', 'wc-voucher-studio' ),
					'email'    => __( 'Email', 'wc-voucher-studio' ),
					'number'   => __( 'Number', 'wc-voucher-studio' ),
					'date'     => __( 'Date', 'wc-voucher-studio' ),
					'select'   => __( 'Select', 'wc-voucher-studio' ),
					'checkbox' => __( 'Checkbox', 'wc-voucher-studio' ),
				),
				'i18n'  => array(
					'label'       => __( 'Label', 'wc-voucher-studio' ),
					'key'         => __( 'Key', 'wc-voucher-studio' ),
					'type'        => __( 'Type', 'wc-voucher-studio' ),
					'required'    => __( 'Required', 'wc-voucher-studio' ),
					'maxlength'   => __( 'Max. characters', 'wc-voucher-studio' ),
					'placeholder' => __( 'Placeholder', 'wc-voucher-studio' ),
					'tooltip'     => __( 'Help text', 'wc-voucher-studio' ),
					'options'     => __( 'Options (one per line)', 'wc-voucher-studio' ),
					'remove'      => __( 'Remove field', 'wc-voucher-studio' ),
					'placeholderToken' => __( 'PDF placeholder:', 'wc-voucher-studio' ),
				),
			)
		);
	}

	public function meta_boxes(): void {
		add_meta_box( 'wcvs-fields', __( 'Fields', 'wc-voucher-studio' ), array( $this, 'fields_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'wcvs-assign', __( 'Display rules', 'wc-voucher-studio' ), array( $this, 'assign_box' ), self::POST_TYPE, 'normal', 'default' );
	}

	public function fields_box( \WP_Post $post ): void {
		wp_nonce_field( 'wcvs_field_group', 'wcvs_field_group_nonce' );
		$fields = json_decode( (string) get_post_meta( $post->ID, '_wcvs_fields', true ), true ) ?: array();
		echo '<p class="description">' . esc_html__( 'These fields are shown on the product page of matching voucher products. Use {field:key} in PDF templates and emails to print a value.', 'wc-voucher-studio' ) . '</p>';
		echo '<div id="wcvs-field-list" data-fields="' . esc_attr( wp_json_encode( $fields ) ) . '"></div>';
		echo '<p><button type="button" class="button" id="wcvs-add-field">' . esc_html__( 'Add field', 'wc-voucher-studio' ) . '</button></p>';
		echo '<input type="hidden" name="wcvs_fields_json" id="wcvs-fields-json" value="" />';
	}

	public function assign_box( \WP_Post $post ): void {
		$assign   = json_decode( (string) get_post_meta( $post->ID, '_wcvs_assign', true ), true ) ?: array( 'mode' => 'all' );
		$position = (string) ( get_post_meta( $post->ID, '_wcvs_position', true ) ?: 'before_button' );
		$mode     = $assign['mode'] ?? 'all';
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="wcvs-assign-mode"><?php esc_html_e( 'Show on', 'wc-voucher-studio' ); ?></label></th>
				<td>
					<select name="wcvs_assign[mode]" id="wcvs-assign-mode">
						<option value="all" <?php selected( $mode, 'all' ); ?>><?php esc_html_e( 'All voucher products', 'wc-voucher-studio' ); ?></option>
						<option value="products" <?php selected( $mode, 'products' ); ?>><?php esc_html_e( 'Selected products', 'wc-voucher-studio' ); ?></option>
						<option value="categories" <?php selected( $mode, 'categories' ); ?>><?php esc_html_e( 'Products in categories', 'wc-voucher-studio' ); ?></option>
						<option value="tags" <?php selected( $mode, 'tags' ); ?>><?php esc_html_e( 'Products with tags', 'wc-voucher-studio' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="wcvs-assign-row" data-mode="products">
				<th scope="row"><label for="wcvs-assign-products"><?php esc_html_e( 'Products', 'wc-voucher-studio' ); ?></label></th>
				<td>
					<select class="wc-product-search" multiple style="width:100%" id="wcvs-assign-products" name="wcvs_assign[products][]" data-action="woocommerce_json_search_products">
						<?php
						foreach ( (array) ( $assign['products'] ?? array() ) as $pid ) {
							$p = wc_get_product( $pid );
							if ( $p ) {
								echo '<option value="' . esc_attr( (string) $pid ) . '" selected>' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
							}
						}
						?>
					</select>
				</td>
			</tr>
			<?php foreach ( array( 'categories' => 'product_cat', 'tags' => 'product_tag' ) as $key => $taxonomy ) : ?>
				<tr class="wcvs-assign-row" data-mode="<?php echo esc_attr( $key ); ?>">
					<th scope="row"><label for="wcvs-assign-<?php echo esc_attr( $key ); ?>"><?php echo 'categories' === $key ? esc_html__( 'Categories', 'wc-voucher-studio' ) : esc_html__( 'Tags', 'wc-voucher-studio' ); ?></label></th>
					<td>
						<select class="wc-enhanced-select" multiple style="width:100%" id="wcvs-assign-<?php echo esc_attr( $key ); ?>" name="wcvs_assign[<?php echo esc_attr( $key ); ?>][]">
							<?php
							$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
							foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
								echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( $term->term_id, array_map( 'intval', (array) ( $assign[ $key ] ?? array() ) ), true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
			<tr>
				<th scope="row"><label for="wcvs-position"><?php esc_html_e( 'Position', 'wc-voucher-studio' ); ?></label></th>
				<td>
					<select name="wcvs_position" id="wcvs-position">
						<option value="before_quantity" <?php selected( $position, 'before_quantity' ); ?>><?php esc_html_e( 'Before quantity field', 'wc-voucher-studio' ); ?></option>
						<option value="before_button" <?php selected( $position, 'before_button' ); ?>><?php esc_html_e( 'Before "Add to cart" button', 'wc-voucher-studio' ); ?></option>
						<option value="after_button" <?php selected( $position, 'after_button' ); ?>><?php esc_html_e( 'After "Add to cart" button', 'wc-voucher-studio' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Groups with a lower "Order" value (Page attributes) are shown first.', 'wc-voucher-studio' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_group( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['wcvs_field_group_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['wcvs_field_group_nonce'] ) ), 'wcvs_field_group' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$fields_in = json_decode( wp_unslash( (string) ( $_POST['wcvs_fields_json'] ?? '' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised below.
		$fields    = array();
		$reserved  = array( 'recipient_name', 'recipient_email', 'message', 'delivery_date' );
		foreach ( is_array( $fields_in ) ? $fields_in : array() as $f ) {
			$key = sanitize_key( $f['key'] ?? '' ) ?: sanitize_key( sanitize_title( $f['label'] ?? '' ) );
			$key = str_replace( '-', '_', $key );
			if ( '' === $key || in_array( $key, $reserved, true ) || isset( $fields[ $key ] ) ) {
				continue;
			}
			$fields[ $key ] = array(
				'key'         => $key,
				'label'       => sanitize_text_field( $f['label'] ?? $key ),
				'type'        => in_array( $f['type'] ?? 'text', self::TYPES, true ) ? ( $f['type'] ?? 'text' ) : 'text',
				'required'    => ! empty( $f['required'] ),
				'maxlength'   => absint( $f['maxlength'] ?? 0 ),
				'placeholder' => sanitize_text_field( $f['placeholder'] ?? '' ),
				'tooltip'     => sanitize_text_field( $f['tooltip'] ?? '' ),
				'options'     => sanitize_textarea_field( $f['options'] ?? '' ),
			);
		}
		update_post_meta( $post_id, '_wcvs_fields', wp_slash( wp_json_encode( array_values( $fields ) ) ) );

		$assign_in = isset( $_POST['wcvs_assign'] ) ? (array) wp_unslash( $_POST['wcvs_assign'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$assign    = array(
			'mode'       => in_array( $assign_in['mode'] ?? 'all', array( 'all', 'products', 'categories', 'tags' ), true ) ? ( $assign_in['mode'] ?? 'all' ) : 'all',
			'products'   => array_map( 'absint', (array) ( $assign_in['products'] ?? array() ) ),
			'categories' => array_map( 'absint', (array) ( $assign_in['categories'] ?? array() ) ),
			'tags'       => array_map( 'absint', (array) ( $assign_in['tags'] ?? array() ) ),
		);
		update_post_meta( $post_id, '_wcvs_assign', wp_slash( wp_json_encode( $assign ) ) );
		$position = sanitize_key( wp_unslash( $_POST['wcvs_position'] ?? 'before_button' ) );
		update_post_meta( $post_id, '_wcvs_position', isset( self::POSITIONS[ $position ] ) ? $position : 'before_button' );
	}
}
