<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Product-page personalisation: built-in recipient/message/delivery-date fields and merchant-defined field groups
 * (assignable to all, selected products, categories or tags), carried through cart → order item → voucher.
 */
final class Fields {
	public const POST_TYPE = 'vcap_field_group';
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
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'validate' ), 10, 6 );
		add_filter( 'woocommerce_store_api_add_to_cart_data', array( $this, 'store_api_data' ), 10, 2 );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'store_api_validate' ), 10, 2 );
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
					'name'          => __( 'Field groups', 'voucher-captain' ),
					'singular_name' => __( 'Field group', 'voucher-captain' ),
					'add_new'       => __( 'Add field group', 'voucher-captain' ),
					'add_new_item'  => __( 'Add field group', 'voucher-captain' ),
					'edit_item'     => __( 'Edit field group', 'voucher-captain' ),
					'all_items'     => __( 'Field groups', 'voucher-captain' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'vcap-vouchers',
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
				'label'       => __( 'Recipient name', 'voucher-captain' ),
				'type'        => 'text',
				'required'    => false,
				'maxlength'   => 100,
				'placeholder' => '',
				'tooltip'     => __( 'Printed on the voucher.', 'voucher-captain' ),
			),
			'recipient_email' => array(
				'key'         => 'recipient_email',
				'label'       => __( 'Recipient email', 'voucher-captain' ),
				'type'        => 'email',
				'required'    => false,
				'maxlength'   => 200,
				'placeholder' => '',
				'tooltip'     => __( 'Leave empty to receive the voucher yourself.', 'voucher-captain' ),
			),
			'message'         => array(
				'key'         => 'message',
				'label'       => __( 'Personal message', 'voucher-captain' ),
				'type'        => 'textarea',
				'required'    => false,
				'maxlength'   => max( 10, (int) ( $config['message_maxlength'] ?: 300 ) ),
				'placeholder' => '',
				'tooltip'     => '',
			),
			'delivery_date'   => array(
				'key'         => 'delivery_date',
				'label'       => __( 'Delivery date', 'voucher-captain' ),
				'type'        => 'date',
				'required'    => false,
				'maxlength'   => 10,
				'placeholder' => '',
				'tooltip'     => __( 'The voucher is emailed on this day. Leave empty to send it right away.', 'voucher-captain' ),
			),
		);
		$enabled = (array) $config['builtin_fields'];
		if ( 'customer' === $config['delivery'] && ! in_array( 'delivery_date', $enabled, true ) ) {
			$enabled[] = 'delivery_date';
		}
		if ( 'buyer' === ( $config['send_to'] ?? '' ) ) {
			// "Buyer only": nothing is ever sent to a recipient address. Ask for it only when it decides who may
			// redeem the voucher, and say so.
			$restrict = (string) ( $config['email_restrict'] ?? 'none' );
			if ( 'recipient' === $restrict ) {
				$defs['recipient_email']['tooltip'] = __( 'The voucher is sent to you, not to this address. Only this address can redeem it (yours, if you leave it empty).', 'voucher-captain' );
			} elseif ( 'both' === $restrict ) {
				$defs['recipient_email']['tooltip'] = __( 'The voucher is sent to you, not to this address. Only this address or yours can redeem it.', 'voucher-captain' );
			} else {
				$enabled = array_diff( $enabled, array( 'recipient_email', 'recipient_email_required' ) );
			}
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
		return apply_filters( 'vcap_builtin_fields', $out, $config );
	}

	/** @return array<int, array{id:int,title:string,position:string,fields:array,assign:array}> */
	public static function groups(): array {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$cache = array();
		foreach ( get_posts( array( 'post_type' => self::POST_TYPE, 'post_status' => 'publish', 'numberposts' => 100, 'orderby' => 'menu_order', 'order' => 'ASC' ) ) as $post ) {
			$fields = json_decode( (string) get_post_meta( $post->ID, '_vcap_fields', true ), true );
			$assign = json_decode( (string) get_post_meta( $post->ID, '_vcap_assign', true ), true );
			$cache[] = array(
				'id'       => $post->ID,
				'title'    => $post->post_title,
				'position' => (string) ( get_post_meta( $post->ID, '_vcap_position', true ) ?: 'before_button' ),
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
		return (bool) apply_filters( 'vcap_field_group_applies', $applies, $group, $product );
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
		return apply_filters( 'vcap_field_definitions', array_filter( $out ), $product );
	}

	private function variable_has_vouchers( \WC_Product $product ): bool {
		return 'yes' === $product->get_meta( '_vcap_enabled' );
	}

	private function flat_definitions( \WC_Product $product ): array {
		$flat = array();
		foreach ( $this->definitions_for( $product ) as $fields ) {
			$flat = $flat + $fields; // Not array_merge(): numeric field keys must not be renumbered.
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
		// Also needed without personalisation fields: amount presets and the preview button use it.
		if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return;
		}
		wp_enqueue_style( 'vcap-frontend', VCAP_URL . 'assets/css/frontend.css', array(), VCAP_VERSION );
		wp_enqueue_script( 'vcap-frontend', VCAP_URL . 'assets/js/frontend.js', array( 'jquery' ), VCAP_VERSION, true );
		wp_localize_script(
			'vcap-frontend',
			'vcapFront',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'vcap_preview' ),
				'i18n'    => array(
					/* translators: %d: characters left */
					'charsLeft' => __( '%d characters left', 'voucher-captain' ),
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
			do_action( 'vcap_before_fields', $product );
			$amount_html = (string) ob_get_clean();
		}
		if ( ! $defs && '' === $amount_html ) {
			return;
		}
		$config = ProductConfig::get( $product );
		echo '<div class="vcap-fields vcap-fields--' . esc_attr( $position ) . '">';
		echo $amount_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built by CustomAmount with escaping.
		foreach ( $defs as $key => $field ) {
			$this->render_field( $key, $field );
		}
		if ( 'before_button' === $position ) {
			$terms = ProductConfig::terms( $config, ProductConfig::country_of( WC()->customer ) );
			if ( '' !== $terms || 'no' === $config['transferable'] ) {
				echo '<details class="vcap-terms"><summary>' . esc_html__( 'Redemption conditions', 'voucher-captain' ) . '</summary>';
				if ( 'no' === $config['transferable'] ) {
					echo '<p>' . esc_html( ProductConfig::restriction_label( (string) $config['email_restrict'] ) ) . '</p>';
				}
				if ( '' !== $terms ) {
					echo wp_kses_post( wpautop( esc_html( $terms ) ) );
				}
				echo '</details>';
			}
		}
		if ( 'before_button' === $position && 'yes' === $config['preview'] ) {
			echo '<p class="vcap-preview-row"><button type="button" class="button vcap-preview-button" data-product="' . esc_attr( (string) $product->get_id() ) . '">' . esc_html__( 'Preview voucher', 'voucher-captain' ) . '</button></p>';
		}
		echo '</div>';
	}

	/** @var array<string, mixed>|null field values of a Store API request (blocks), used instead of $_POST */
	private ?array $override = null;

	private function posted_value( string $key ): string {
		if ( null !== $this->override ) {
			$raw = $this->override[ $key ] ?? null;
			return is_scalar( $raw ) ? (string) $raw : '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- Add-to-cart form; WooCommerce handles the request.
		$raw = isset( $_POST['vcap_fields'][ $key ] ) ? wp_unslash( $_POST['vcap_fields'][ $key ] ) : ( isset( $_REQUEST['vcap_fields'][ $key ] ) ? wp_unslash( $_REQUEST['vcap_fields'][ $key ] ) : null ); // phpcs:ignore
		return is_scalar( $raw ) ? (string) $raw : '';
	}

	private function render_field( string $key, array $field ): void {
		$id       = 'vcap-field-' . sanitize_html_class( $key );
		$name     = 'vcap_fields[' . esc_attr( $key ) . ']';
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
			$attrs .= ' maxlength="' . esc_attr( (string) $max ) . '" data-vcap-counter="1"';
		}
		if ( ! empty( $field['placeholder'] ) ) {
			$attrs .= ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
		}

		echo '<p class="form-row vcap-field vcap-field--' . esc_attr( $type ) . '">';
		if ( 'checkbox' !== $type ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ?? $key ) . ( $required ? ' <abbr class="required" title="' . esc_attr__( 'required', 'voucher-captain' ) . '">*</abbr>' : ' <span class="optional">(' . esc_html__( 'optional', 'voucher-captain' ) . ')</span>' ) . '</label>';
		}
		switch ( $type ) {
			case 'textarea':
				echo '<textarea ' . $attrs . ' rows="3">' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'select':
				echo '<select ' . $attrs . '><option value="">' . esc_html__( '— Select —', 'voucher-captain' ) . '</option>'; // phpcs:ignore WordPress.Security.EscapeOutput
				foreach ( self::options( $field ) as $option ) {
					echo '<option value="' . esc_attr( $option ) . '"' . selected( $value, $option, false ) . '>' . esc_html( $option ) . '</option>';
				}
				echo '</select>';
				break;
			case 'checkbox':
				echo '<label for="' . esc_attr( $id ) . '" class="checkbox"><input type="checkbox" value="1" ' . $attrs . checked( '1', $value, false ) . ' /> ' . esc_html( $field['label'] ?? $key ) . ( $required ? ' <abbr class="required" title="' . esc_attr__( 'required', 'voucher-captain' ) . '">*</abbr>' : '' ) . '</label>'; // phpcs:ignore WordPress.Security.EscapeOutput
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
			echo '<span class="description vcap-field-help" id="' . esc_attr( $desc_id ) . '">' . esc_html( $field['tooltip'] ) . '</span>';
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
					$value = '' !== $raw ? __( 'Yes', 'voucher-captain' ) : '';
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

	public function validate( $passed, $product_id, $quantity, $variation_id = 0, $variations = array(), $cart_item_data = array() ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return $passed;
		}
		if ( is_array( $cart_item_data ) && ! empty( $cart_item_data['vcap_fields'] ) ) {
			return $passed; // "Order again": values were validated when first ordered.
		}
		foreach ( $this->errors( $product ) as $message ) {
			wc_add_notice( $message, 'error' );
			$passed = false;
		}
		return $passed;
	}

	/**
	 * Validation messages for the submitted field values (empty when valid).
	 *
	 * @return string[]
	 */
	public function errors( \WC_Product $product ): array {
		$errors = array();
		foreach ( $this->flat_definitions( $product ) as $key => $field ) {
			$raw   = trim( $this->posted_value( (string) $key ) );
			$label = (string) ( $field['label'] ?? $key );
			if ( ! empty( $field['required'] ) && '' === $raw ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is a required field.', 'voucher-captain' ), $label );
				continue;
			}
			if ( '' === $raw ) {
				continue;
			}
			$type = $field['type'] ?? 'text';
			if ( 'email' === $type && ! is_email( $raw ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s is not a valid email address.', 'voucher-captain' ), $label );
			}
			if ( 'date' === $type ) {
				$ts = strtotime( $raw );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) || ! $ts ) {
					/* translators: %s: field label */
					$errors[] = sprintf( __( '%s is not a valid date.', 'voucher-captain' ), $label );
				} elseif ( 'delivery_date' === $key && ( $raw < wp_date( 'Y-m-d' ) || $ts > time() + YEAR_IN_SECONDS + DAY_IN_SECONDS ) ) {
					$errors[] = __( 'Please choose a delivery date between today and one year from now.', 'voucher-captain' );
				}
			}
			if ( 'number' === $type && ! is_numeric( wc_format_decimal( $raw ) ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( '%s must be a number.', 'voucher-captain' ), $label );
			}
			if ( 'select' === $type && ! in_array( $raw, self::options( $field ), true ) ) {
				/* translators: %s: field label */
				$errors[] = sprintf( __( 'Please choose a valid option for %s.', 'voucher-captain' ), $label );
			}
			$max = (int) ( $field['maxlength'] ?? 0 );
			if ( $max > 0 && mb_strlen( $raw ) > $max ) {
				/* translators: 1: field label, 2: max length */
				$errors[] = sprintf( __( '%1$s may contain at most %2$d characters.', 'voucher-captain' ), $label, $max );
			}
		}
		return $errors;
	}

	/**
	 * Store API (cart/checkout blocks, product collection buttons): voucher fields can be sent as "vcap_fields"
	 * in the add-item request. They are validated like the product form and stored as cart item data.
	 */
	public function store_api_data( $data, $request ) {
		$product = wc_get_product( (int) ( $data['id'] ?? 0 ) );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) || ! $request instanceof \WP_REST_Request ) {
			return $data;
		}
		$values         = $request->get_param( 'vcap_fields' );
		$this->override = is_array( $values ) ? $values : array();
		$fields         = $this->collect_posted( $product );
		if ( $fields ) {
			$data['cart_item_data']                = (array) ( $data['cart_item_data'] ?? array() );
			$data['cart_item_data']['vcap_fields'] = $fields;
			$data['cart_item_data']['vcap_key']    = md5( wp_json_encode( $fields ) );
		}
		return $data;
	}

	public function store_api_validate( $product, $request ): void {
		if ( ! $product instanceof \WC_Product || ! ProductConfig::is_voucher( $product ) ) {
			return;
		}
		if ( $request instanceof \WP_REST_Request ) {
			$values         = $request->get_param( 'vcap_fields' );
			$this->override = is_array( $values ) ? $values : array();
		}
		$errors = $this->errors( $product );
		if ( $errors && class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'vcap_invalid_voucher_fields', esc_html( implode( ' ', $errors ) ), 400 );
		}
	}

	public function cart_item_data( $data, $product_id, $variation_id ) {
		$product = wc_get_product( $variation_id ?: $product_id );
		if ( ! $product || ! ProductConfig::is_voucher( $product ) ) {
			return $data;
		}
		if ( ! empty( $data['vcap_fields'] ) && is_array( $data['vcap_fields'] ) ) {
			return $data; // Already supplied ("order again").
		}
		$fields = $this->collect_posted( $product );
		if ( $fields ) {
			$data['vcap_fields'] = $fields;
			$data['vcap_key']    = md5( wp_json_encode( $fields ) ); // Separate cart lines per personalisation.
		}
		return $data;
	}

	public function display_item_data( $item_data, $cart_item ) {
		foreach ( (array) ( $cart_item['vcap_fields'] ?? array() ) as $field ) {
			$item_data[] = array(
				'key'   => $field['label'],
				'value' => $field['value'],
			);
		}
		return $item_data;
	}

	public function save_order_item( $item, $cart_item_key, $values, $order ): void {
		if ( empty( $values['vcap_fields'] ) ) {
			return;
		}
		$item->add_meta_data( '_vcap_fields', $values['vcap_fields'], true );
		// Visible copy (admin order screen, emails, {item_meta:Label} placeholders).
		foreach ( $values['vcap_fields'] as $field ) {
			if ( '' !== (string) $field['value'] ) {
				$item->add_meta_data( wp_strip_all_tags( $field['label'] ), $field['value'], true );
			}
		}
	}

	public function order_again( $data, $item, $order ) {
		$fields = $item->get_meta( '_vcap_fields' );
		if ( $fields ) {
			$data['vcap_fields'] = $fields;
			$data['vcap_key']    = md5( wp_json_encode( $fields ) );
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
			return TopUp::is_topup( $product ) ? __( 'Top up', 'voucher-captain' ) : __( 'Personalise', 'voucher-captain' );
		}
		return $text;
	}

	/** Whether the product page has to be filled in first: a custom amount, voucher fields or a top-up card code. */
	private function has_required_or_custom( \WC_Product $product ): bool {
		if ( TopUp::is_topup( $product ) ) {
			return true;
		}
		$config = ProductConfig::get( $product );
		return 'custom' === $config['amount_source'] || (bool) $this->flat_definitions( $product );
	}

	// ----------------------------------------------------------------- Admin (field group editor).

	public function admin_assets( string $hook ): void {
		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type || ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}
		wp_enqueue_style( 'vcap-admin', VCAP_URL . 'assets/css/admin.css', array(), VCAP_VERSION );
		wp_enqueue_script( 'vcap-field-groups', VCAP_URL . 'assets/js/field-groups.js', array( 'jquery', 'jquery-ui-sortable', 'wc-enhanced-select' ), VCAP_VERSION, true );
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_localize_script(
			'vcap-field-groups',
			'vcapFieldGroups',
			array(
				'types' => array(
					'text'     => __( 'Text', 'voucher-captain' ),
					'textarea' => __( 'Text area', 'voucher-captain' ),
					'email'    => __( 'Email', 'voucher-captain' ),
					'number'   => __( 'Number', 'voucher-captain' ),
					'date'     => __( 'Date', 'voucher-captain' ),
					'select'   => __( 'Select', 'voucher-captain' ),
					'checkbox' => __( 'Checkbox', 'voucher-captain' ),
				),
				'i18n'  => array(
					'label'       => __( 'Label', 'voucher-captain' ),
					'key'         => __( 'Key', 'voucher-captain' ),
					'type'        => __( 'Type', 'voucher-captain' ),
					'required'    => __( 'Required', 'voucher-captain' ),
					'maxlength'   => __( 'Max. characters', 'voucher-captain' ),
					'placeholder' => __( 'Placeholder', 'voucher-captain' ),
					'tooltip'     => __( 'Help text', 'voucher-captain' ),
					'options'     => __( 'Options (one per line)', 'voucher-captain' ),
					'remove'      => __( 'Remove field', 'voucher-captain' ),
					'placeholderToken' => __( 'PDF placeholder:', 'voucher-captain' ),
				),
			)
		);
	}

	public function meta_boxes(): void {
		add_meta_box( 'vcap-fields', __( 'Fields', 'voucher-captain' ), array( $this, 'fields_box' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'vcap-assign', __( 'Display rules', 'voucher-captain' ), array( $this, 'assign_box' ), self::POST_TYPE, 'normal', 'default' );
	}

	public function fields_box( \WP_Post $post ): void {
		wp_nonce_field( 'vcap_field_group', 'vcap_field_group_nonce' );
		$fields = json_decode( (string) get_post_meta( $post->ID, '_vcap_fields', true ), true ) ?: array();
		echo '<p class="description">' . esc_html__( 'These fields are shown on the product page of matching voucher products. Use {field:key} in PDF templates and emails to print a value.', 'voucher-captain' ) . '</p>';
		echo '<div id="vcap-field-list" data-fields="' . esc_attr( wp_json_encode( $fields ) ) . '"></div>';
		echo '<p><button type="button" class="button" id="vcap-add-field">' . esc_html__( 'Add field', 'voucher-captain' ) . '</button></p>';
		echo '<input type="hidden" name="vcap_fields_json" id="vcap-fields-json" value="" />';
	}

	public function assign_box( \WP_Post $post ): void {
		$assign   = json_decode( (string) get_post_meta( $post->ID, '_vcap_assign', true ), true ) ?: array( 'mode' => 'all' );
		$position = (string) ( get_post_meta( $post->ID, '_vcap_position', true ) ?: 'before_button' );
		$mode     = $assign['mode'] ?? 'all';
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="vcap-assign-mode"><?php esc_html_e( 'Show on', 'voucher-captain' ); ?></label></th>
				<td>
					<select name="vcap_assign[mode]" id="vcap-assign-mode">
						<option value="all" <?php selected( $mode, 'all' ); ?>><?php esc_html_e( 'All voucher products', 'voucher-captain' ); ?></option>
						<option value="products" <?php selected( $mode, 'products' ); ?>><?php esc_html_e( 'Selected products', 'voucher-captain' ); ?></option>
						<option value="categories" <?php selected( $mode, 'categories' ); ?>><?php esc_html_e( 'Products in categories', 'voucher-captain' ); ?></option>
						<option value="tags" <?php selected( $mode, 'tags' ); ?>><?php esc_html_e( 'Products with tags', 'voucher-captain' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="vcap-assign-row" data-mode="products">
				<th scope="row"><label for="vcap-assign-products"><?php esc_html_e( 'Products', 'voucher-captain' ); ?></label></th>
				<td>
					<select class="wc-product-search" multiple style="width:100%" id="vcap-assign-products" name="vcap_assign[products][]" data-action="woocommerce_json_search_products">
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
				<tr class="vcap-assign-row" data-mode="<?php echo esc_attr( $key ); ?>">
					<th scope="row"><label for="vcap-assign-<?php echo esc_attr( $key ); ?>"><?php echo 'categories' === $key ? esc_html__( 'Categories', 'voucher-captain' ) : esc_html__( 'Tags', 'voucher-captain' ); ?></label></th>
					<td>
						<select class="wc-enhanced-select" multiple style="width:100%" id="vcap-assign-<?php echo esc_attr( $key ); ?>" name="vcap_assign[<?php echo esc_attr( $key ); ?>][]">
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
				<th scope="row"><label for="vcap-position"><?php esc_html_e( 'Position', 'voucher-captain' ); ?></label></th>
				<td>
					<select name="vcap_position" id="vcap-position">
						<option value="before_quantity" <?php selected( $position, 'before_quantity' ); ?>><?php esc_html_e( 'Before quantity field', 'voucher-captain' ); ?></option>
						<option value="before_button" <?php selected( $position, 'before_button' ); ?>><?php esc_html_e( 'Before "Add to cart" button', 'voucher-captain' ); ?></option>
						<option value="after_button" <?php selected( $position, 'after_button' ); ?>><?php esc_html_e( 'After "Add to cart" button', 'voucher-captain' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'Groups with a lower "Order" value (Page attributes) are shown first.', 'voucher-captain' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public function save_group( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['vcap_field_group_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['vcap_field_group_nonce'] ) ), 'vcap_field_group' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$fields_in = json_decode( wp_unslash( (string) ( $_POST['vcap_fields_json'] ?? '' ) ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised below.
		$fields    = array();
		$reserved  = array( 'recipient_name', 'recipient_email', 'message', 'delivery_date' );
		foreach ( is_array( $fields_in ) ? $fields_in : array() as $f ) {
			$key = sanitize_key( $f['key'] ?? '' ) ?: sanitize_key( sanitize_title( $f['label'] ?? '' ) );
			$key = str_replace( '-', '_', $key );
			if ( '' === $key ) {
				continue;
			}
			if ( is_numeric( $key ) ) {
				$key = 'field_' . $key;
			}
			// Never drop a field silently: make reserved or duplicate keys unique instead.
			$base = $key;
			$n    = 2;
			while ( in_array( $key, $reserved, true ) || isset( $fields[ $key ] ) ) {
				$key = $base . '_' . $n++;
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
		update_post_meta( $post_id, '_vcap_fields', wp_slash( wp_json_encode( array_values( $fields ) ) ) );

		$assign_in = isset( $_POST['vcap_assign'] ) ? (array) wp_unslash( $_POST['vcap_assign'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$assign    = array(
			'mode'       => in_array( $assign_in['mode'] ?? 'all', array( 'all', 'products', 'categories', 'tags' ), true ) ? ( $assign_in['mode'] ?? 'all' ) : 'all',
			'products'   => array_map( 'absint', (array) ( $assign_in['products'] ?? array() ) ),
			'categories' => array_map( 'absint', (array) ( $assign_in['categories'] ?? array() ) ),
			'tags'       => array_map( 'absint', (array) ( $assign_in['tags'] ?? array() ) ),
		);
		update_post_meta( $post_id, '_vcap_assign', wp_slash( wp_json_encode( $assign ) ) );
		$position = sanitize_key( wp_unslash( $_POST['vcap_position'] ?? 'before_button' ) );
		update_post_meta( $post_id, '_vcap_position', isset( self::POSITIONS[ $position ] ) ? $position : 'before_button' );
	}
}
