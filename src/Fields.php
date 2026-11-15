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
		add_filter( 'woocommerce_order_item_display_meta_key', array( $this, 'display_meta_key' ), 10, 3 );
		add_filter( 'woocommerce_order_item_display_meta_value', array( $this, 'display_meta_value' ), 10, 3 );
		add_action( 'woocommerce_before_order_item_object_save', array( $this, 'sync_visible_meta' ), 10, 1 );
		add_filter( 'woocommerce_order_again_cart_item_data', array( $this, 'order_again' ), 10, 3 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ) );
		add_action( 'woocommerce_privacy_remove_order_personal_data', array( self::class, 'anonymise_order' ), 10, 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'woocommerce_after_shop_loop_item', array( $this, 'loop_assets' ) );
		add_filter( 'woocommerce_blocks_product_grid_item_html', array( $this, 'grid_assets' ), 10, 3 );
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
				/* translators: %s: time of day (e.g. 8:00 am) */
				'tooltip'     => sprintf( __( 'The voucher is emailed on this day at about %s (shop time). Leave empty to send it right away.', 'voucher-captain' ), self::delivery_time_text() ),
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
					$out[ $base ]['tooltip']  = self::required_tooltip( $base, $out[ $base ]['tooltip'], $config );
				}
			}
		}
		return apply_filters( 'vcap_builtin_fields', $out, $config );
	}

	/** Help text of a required built-in field: no "leave empty to …", which a required field does not allow. */
	private static function required_tooltip( string $key, string $tooltip, array $config ): string {
		if ( 'delivery_date' === $key ) {
			/* translators: %s: time of day (e.g. 8:00 am) */
			return sprintf( __( 'The voucher is emailed on this day at about %s (shop time).', 'voucher-captain' ), self::delivery_time_text() );
		}
		if ( 'recipient_email' === $key ) {
			if ( 'buyer' !== ( $config['send_to'] ?? '' ) ) {
				return '';
			}
			if ( 'recipient' === (string) ( $config['email_restrict'] ?? 'none' ) ) {
				return __( 'The voucher is sent to you, not to this address. Only this address can redeem it.', 'voucher-captain' );
			}
		}
		return $tooltip;
	}

	/**
	 * Value of a "number" field, read strictly like amounts ("2,50" and "2.50" are 2.5; "1e3", "1.500" and
	 * "12,50; 25" are refused instead of guessed). Null when it is empty or not one unambiguous number.
	 */
	public static function parse_number( string $raw ): ?string {
		$parsed = Amount::parse(
			$raw,
			array(
				'decimals'       => 4,
				'decimal_sep'    => function_exists( 'wc_get_price_decimal_separator' ) ? wc_get_price_decimal_separator() : '.',
				'strip'          => array(),
				'allow_negative' => true,
				'allow_zero'     => true,
			)
		);
		return $parsed['ok'] ? (string) $parsed['value'] : null;
	}

	/** @return array<int, array{id:int,title:string,position:string,fields:array,assign:array}> */
	public static function groups(): array {
		static $by_site = array(); // Per site: switch_to_blog() must not return another site's groups.
		$site = get_current_blog_id();
		if ( isset( $by_site[ $site ] ) ) {
			return $by_site[ $site ];
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
		return $by_site[ $site ] = $cache;
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
		self::enqueue_assets();
	}

	/**
	 * Shop pages, categories and product shortcodes: quick views (Astra, Flatsome, YITH, Woodmart …) load the
	 * product form by AJAX, without the scripts of a product page. With a voucher product in the loop, the script
	 * is printed in the footer, so suggested amounts, character counters and the preview work in the quick view.
	 */
	public function loop_assets(): void {
		global $product;
		if ( $product instanceof \WC_Product && ProductConfig::is_voucher( $product ) ) {
			self::enqueue_assets();
		}
	}

	/** Same for the product grid blocks (WooCommerce "All products", "Products by category" …). */
	public function grid_assets( $html, $data = null, $product = null ) {
		if ( $product instanceof \WC_Product && ProductConfig::is_voucher( $product ) ) {
			self::enqueue_assets();
		}
		return $html;
	}

	/**
	 * Style and script of the voucher fields. Enqueued on voucher product pages, on shop pages with voucher
	 * products (quick views) and whenever the fields render, so [product_page] and page builder add-to-cart
	 * widgets get them as well (printed in the footer).
	 */
	public static function enqueue_assets(): void {
		Downloads::enqueue_style();
		if ( wp_script_is( 'vcap-frontend', 'enqueued' ) ) {
			return;
		}
		Downloads::register_assets();
		wp_enqueue_script( 'vcap-frontend' );
		wp_localize_script(
			'vcap-frontend',
			'vcapFront',
			array(
				'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
				// The preview goes through WooCommerce's front-end AJAX endpoint (also with a protected /wp-admin).
				'previewUrl' => class_exists( '\\WC_AJAX' ) ? \WC_AJAX::get_endpoint( 'vcap_product_preview' ) : admin_url( 'admin-ajax.php' ),
				// Only for logged-in customers (product pages of guests stay cacheable, see Preview::product_preview()).
				'nonce'   => is_user_logged_in() ? wp_create_nonce( 'vcap_preview' ) : '',
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
		self::enqueue_assets();
		$config = ProductConfig::get( $product );
		echo '<div class="vcap-fields vcap-fields--' . esc_attr( $position ) . '">';
		echo $amount_html; // phpcs:ignore WordPress.Security.EscapeOutput -- built by CustomAmount with escaping.
		if ( self::gift_toggle_enabled() && array_intersect_key( $defs, array_flip( self::GIFT_KEYS ) ) ) {
			$this->render_gift_toggle();
		}
		foreach ( $defs as $key => $field ) {
			$this->render_field( $key, $field, $config );
		}
		if ( 'before_button' === $position ) {
			// The same HTML for every visitor (product pages are cached): conditions per country are all shown,
			// each under its country, instead of the variant of the visitor's (geolocated) country.
			$variants = ProductConfig::terms_variants( $config );
			if ( $variants || 'no' === $config['transferable'] ) {
				echo '<details class="vcap-terms"><summary>' . esc_html__( 'Redemption conditions', 'voucher-captain' ) . '</summary>';
				if ( 'no' === $config['transferable'] ) {
					echo '<p>' . esc_html( ProductConfig::restriction_label( (string) $config['email_restrict'] ) ) . '</p>';
				}
				foreach ( $variants as $country => $text ) {
					$country  = (string) $country;
					$labelled = '' !== $country || count( $variants ) > 1;
					if ( $labelled ) {
						$name  = WC()->countries ? ( WC()->countries->get_countries()[ $country ] ?? $country ) : $country;
						$title = '' === $country
							? __( 'Billing address in another country:', 'voucher-captain' )
							/* translators: %s: country name */
							: sprintf( __( 'Billing address in %s:', 'voucher-captain' ), $name );
						echo '<div class="vcap-terms-country" data-country="' . esc_attr( $country ) . '"><p><strong>' . esc_html( $title ) . '</strong></p>';
					}
					echo wp_kses_post( wpautop( esc_html( $text ) ) );
					if ( $labelled ) {
						echo '</div>';
					}
				}
				echo '</details>';
			}
		}
		if ( 'before_button' === $position ) {
			$hint = NextSteps::product_hint( $config );
			if ( '' !== $hint ) {
				echo '<p class="vcap-delivery-hint">' . esc_html( $hint ) . '</p>';
			}
		}
		if ( 'before_button' === $position && 'yes' === $config['preview'] ) {
			echo '<p class="vcap-preview-row"><button type="button" class="button vcap-preview-button" data-product="' . esc_attr( (string) $product->get_id() ) . '">' . esc_html__( 'Preview voucher', 'voucher-captain' ) . '</button></p>';
		}
		echo '</div>';
	}

	/** M12-95: fields that only matter for a gift (hidden and ignored when the customer buys "for me"). */
	public const GIFT_KEYS = array( 'recipient_name', 'recipient_email', 'message' );

	/** M12-95: setting "Gift / for me" choice. */
	public static function gift_toggle_enabled(): bool {
		return 'yes' === Settings::value( 'vcap_gift_toggle' );
	}

	/** Whether the customer chose "For me" (field "gift_mode" = "self", only with the setting on). */
	private function for_self(): bool {
		return self::gift_toggle_enabled() && 'self' === $this->posted_value( 'gift_mode' );
	}

	/** "As a gift" / "For me" (frontend.js hides the gift fields for "For me"; the server ignores them then). */
	private function render_gift_toggle(): void {
		$self = 'self' === $this->posted_value( 'gift_mode' );
		$id   = Shortcodes::unique_id( 'vcap-gift-mode' );
		echo '<fieldset class="vcap-gift-mode"><legend>' . esc_html__( 'Who is the voucher for?', 'voucher-captain' ) . '</legend>';
		foreach ( array(
			'gift' => __( 'As a gift', 'voucher-captain' ),
			'self' => __( 'For me', 'voucher-captain' ),
		) as $value => $label ) {
			echo '<label for="' . esc_attr( $id . '-' . $value ) . '"><input type="radio" id="' . esc_attr( $id . '-' . $value ) . '" name="vcap_fields[gift_mode]" value="' . esc_attr( $value ) . '"' . checked( ( 'self' === $value ) === $self, true, false ) . ' /> ' . esc_html( $label ) . '</label> ';
		}
		echo '</fieldset>';
	}

	/** @var array<string, mixed>|null field values of a Store API request (blocks), used instead of $_POST */
	private ?array $override = null;

	/** @var array<string, string> first error per field of the last errors() call (marked when the form shows again) */
	private array $invalid = array();

	private function posted_value( string $key ): string {
		if ( null !== $this->override ) {
			$raw = $this->override[ $key ] ?? null;
			return is_scalar( $raw ) ? self::normalize_newlines( (string) $raw ) : '';
		}
		// Only the posted add-to-cart form (AP-F1-1): a link with ?vcap_fields[...] must not prefill a field (e.g. the
		// recipient email of a gift) or carry text into the page. GET prefill is no documented feature.
		// Add-to-cart form, WooCommerce handles the request; validated in errors(), sanitised with the cart item data.
		$raw = isset( $_POST['vcap_fields'][ $key ] ) ? wp_unslash( $_POST['vcap_fields'][ $key ] ) : null; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		return is_scalar( $raw ) ? self::normalize_newlines( (string) $raw ) : '';
	}

	/**
	 * Line breaks as "\n": browsers submit a textarea's breaks as "\r\n" but count them as one character (counter,
	 * maxlength), so the length check and the shortening count them as one as well.
	 */
	public static function normalize_newlines( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}

	private function render_field( string $key, array $field, array $config = array() ): void {
		// Unique on the page (two product forms, quick views): the first form keeps "vcap-field-{key}".
		$id       = Shortcodes::unique_id( 'vcap-field-' . sanitize_html_class( $key ) );
		$name     = 'vcap_fields[' . esc_attr( $key ) . ']';
		$type     = in_array( $field['type'] ?? 'text', self::TYPES, true ) ? ( $field['type'] ?? 'text' ) : 'text';
		$required = ! empty( $field['required'] );
		$max      = (int) ( $field['maxlength'] ?? 0 );
		$value    = $this->posted_value( $key );
		if ( '' === $value && isset( $field['default'] ) ) {
			$value = (string) $field['default'];
		}
		$desc_id  = $id . '-desc';
		$error    = $this->invalid[ $key ] ?? '';
		// After a refused add to cart the field is marked invalid and names its error (the first one gets focus).
		$describe = trim( ( ! empty( $field['tooltip'] ) ? $desc_id : '' ) . ( '' !== $error ? ' ' . $id . '-error' : '' ) );
		$attrs    = sprintf( 'id="%s" name="%s"%s%s', esc_attr( $id ), $name, $required ? ' required aria-required="true"' : '', '' !== $describe ? ' aria-describedby="' . esc_attr( $describe ) . '"' : '' );
		if ( '' !== $error ) {
			$attrs .= ' aria-invalid="true"' . ( array_key_first( $this->invalid ) === $key ? ' data-vcap-focus="1"' : '' );
		}
		if ( $max > 0 && in_array( $type, array( 'text', 'textarea', 'email' ), true ) ) {
			$attrs .= ' maxlength="' . esc_attr( (string) $max ) . '" data-vcap-counter="1"';
		}
		if ( ! empty( $field['placeholder'] ) ) {
			$attrs .= ' placeholder="' . esc_attr( $field['placeholder'] ) . '"';
		}

		echo '<p class="form-row vcap-field vcap-field--' . esc_attr( $type ) . '" data-vcap-key="' . esc_attr( $key ) . '">';
		if ( 'checkbox' !== $type ) {
			echo '<label for="' . esc_attr( $id ) . '">' . esc_html( $field['label'] ?? $key ) . ( $required ? ' <abbr class="required" title="' . esc_attr__( 'required', 'voucher-captain' ) . '">*</abbr>' : ' <span class="optional">(' . esc_html__( 'optional', 'voucher-captain' ) . ')</span>' ) . '</label>';
		}
		switch ( $type ) {
			case 'textarea':
				// Visitor text with brackets as entities: a page with a product block runs do_shortcode() over the form.
				echo '<textarea ' . $attrs . ' rows="3">' . Shortcodes::esc_html_text( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_html_text().
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
				// H11: from today, like the server rule in errors() (a day already past the delivery hour sends at once).
				$min  = 'delivery_date' === $key ? wp_date( 'Y-m-d' ) : '';
				$maxd = 'delivery_date' === $key ? self::max_delivery_date( $config ) : '';
				echo '<input type="date" ' . $attrs . ' value="' . Shortcodes::esc_attr_text( $value ) . '"' . ( $min ? ' min="' . esc_attr( $min ) . '" max="' . esc_attr( $maxd ) . '"' : '' ) . ' />'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				echo '<input type="' . esc_attr( $type ) . '" class="input-text" ' . $attrs . ' value="' . Shortcodes::esc_attr_text( $value ) . '" />'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped by esc_attr_text().
		}
		if ( ! empty( $field['tooltip'] ) ) {
			echo '<span class="description vcap-field-help" id="' . esc_attr( $desc_id ) . '">' . esc_html( $field['tooltip'] ) . '</span>';
		}
		if ( '' !== $error ) {
			echo '<span class="vcap-field-error" id="' . esc_attr( $id . '-error' ) . '">' . esc_html( $error ) . '</span>';
		}
		echo '</p>';
	}

	/**
	 * Latest date the delivery date field offers: one year ahead, or earlier when the voucher would expire before
	 * (Delivery::latest_delivery_date(), same rule as the server-side check in errors()).
	 */
	private static function max_delivery_date( array $config ): string {
		$max    = wp_date( 'Y-m-d', time() + YEAR_IN_SECONDS );
		$latest = $config ? Delivery::latest_delivery_date( $config, time(), self::customer_country() ) : null;
		return null !== $latest && $latest < $max ? $latest : $max;
	}

	/** Country of the current customer (statutory validity, see ProductConfig::expiry_for()). */
	private static function customer_country(): string {
		return ProductConfig::country_of( function_exists( 'WC' ) && WC()->customer ? WC()->customer : null );
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
		$out  = array();
		$self = $this->for_self();
		foreach ( $this->flat_definitions( $product ) as $key => $field ) {
			if ( $self && in_array( (string) $key, self::GIFT_KEYS, true ) ) {
				continue; // "For me": no recipient, no gift message.
			}
			$raw = $this->posted_value( (string) $key );
			switch ( $field['type'] ?? 'text' ) {
				case 'textarea':
					$value = sanitize_textarea_field( $raw );
					break;
				case 'email':
					$value = sanitize_email( $raw );
					break;
				case 'number':
					$number = self::parse_number( $raw );
					$value  = null === $number ? '' : $number;
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
		if ( is_array( $cart_item_data ) && ! empty( $cart_item_data['vcap_fields'] ) && is_array( $cart_item_data['vcap_fields'] ) ) {
			// M12-59: "order again" (and other supplied values) are checked like the form, with today's rules
			// (fields that became required, a delivery date that has passed).
			$previous       = $this->override; // A Store API request keeps its own values (store_api_data()).
			$this->override = self::plain_values( $cart_item_data['vcap_fields'] );
			try {
				$errors = $this->errors( $product );
			} finally {
				$this->override = $previous;
			}
			foreach ( $errors as $message ) {
				wc_add_notice( $message, 'error' );
				$passed = false;
			}
			return $passed;
		}
		foreach ( $this->errors( $product ) as $message ) {
			wc_add_notice( $message, 'error' );
			$passed = false;
		}
		if ( $passed ) {
			foreach ( $this->print_warnings( $product ) as $message ) {
				wc_add_notice( $message, 'notice' ); // Accepted, but the customer knows how the PDF will look.
			}
		}
		return $passed;
	}

	/**
	 * Notices for submitted text values the voucher PDF cannot print as entered (characters without a glyph such
	 * as emoji or Chinese letters, right-to-left text).
	 *
	 * @return string[]
	 */
	public function print_warnings( \WC_Product $product ): array {
		$warnings = array();
		foreach ( $this->flat_definitions( $product ) as $key => $field ) {
			if ( ! in_array( $field['type'] ?? 'text', array( 'text', 'textarea' ), true ) ) {
				continue;
			}
			$warning = PdfRenderer::print_warning( (string) ( $field['label'] ?? $key ), trim( $this->posted_value( (string) $key ) ) );
			if ( '' !== $warning ) {
				$warnings[] = $warning;
			}
		}
		return $warnings;
	}

	/**
	 * Validation messages for the submitted field values (empty when valid).
	 *
	 * @return string[]
	 */
	public function errors( \WC_Product $product ): array {
		$errors        = array();
		$this->invalid = array();
		$self          = $this->for_self();
		foreach ( $this->flat_definitions( $product ) as $key => $field ) {
			if ( $self && in_array( (string) $key, self::GIFT_KEYS, true ) ) {
				continue; // "For me": the gift fields are not asked for.
			}
			$raw    = trim( $this->posted_value( (string) $key ) );
			$label  = (string) ( $field['label'] ?? $key );
			$before = count( $errors );
			if ( ! empty( $field['required'] ) && '' === $raw ) {
				/* translators: %s: field label */
				$errors[]                       = sprintf( __( '%s is a required field.', 'voucher-captain' ), $label );
				$this->invalid[ (string) $key ] = end( $errors );
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
				// A local date (shop time zone), not UTC midnight as strtotime() would read it.
				$ts = LocalDate::timestamp( $raw );
				if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $raw ) || null === $ts ) {
					/* translators: %s: field label */
					$errors[] = sprintf( __( '%s is not a valid date.', 'voucher-captain' ), $label );
				} elseif ( 'delivery_date' === $key && ( $raw < wp_date( 'Y-m-d' ) || $ts > time() + YEAR_IN_SECONDS + DAY_IN_SECONDS ) ) {
					$errors[] = __( 'Please choose a delivery date between today and one year from now.', 'voucher-captain' );
				} elseif ( 'delivery_date' === $key ) {
					$latest = Delivery::latest_delivery_date( ProductConfig::get( $product ), time(), self::customer_country() );
					if ( null !== $latest && $raw > $latest ) {
						$errors[] = $latest < wp_date( 'Y-m-d' )
							? __( 'This voucher cannot be delivered on a later date because it would already have expired. Leave the delivery date empty to receive it right away.', 'voucher-captain' )
							: sprintf(
								/* translators: %s: last possible delivery date */
								__( 'The voucher would already have expired on this delivery date. Please choose %s or an earlier date.', 'voucher-captain' ),
								wp_date( (string) get_option( 'date_format' ), (int) strtotime( $latest . ' 12:00:00 ' . wp_timezone_string() ) )
							);
					}
				}
			}
			if ( 'number' === $type && '' !== trim( $raw ) && null === self::parse_number( $raw ) ) {
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
			if ( count( $errors ) > $before ) {
				$this->invalid[ (string) $key ] = $errors[ $before ];
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
		$fields = (array) ( $cart_item['vcap_fields'] ?? array() );
		foreach ( $fields as $field ) {
			$item_data[] = array(
				'key'   => $field['label'],
				'value' => self::display_value( (string) $field['value'] ),
			);
		}
		// When and where the voucher goes (the buyer otherwise learns it only on the delivery day).
		$product = $cart_item['data'] ?? null;
		if ( $product instanceof \WC_Product && ProductConfig::is_voucher( $product ) && ! TopUp::is_topup( $product ) ) {
			$config      = ProductConfig::get( $product );
			$buyer       = function_exists( 'WC' ) && WC()->customer ? (string) WC()->customer->get_billing_email() : '';
			$item_data[] = array(
				'key'   => __( 'Delivery', 'voucher-captain' ),
				'value' => NextSteps::summary( $config, $fields, $buyer, Delivery::compute_time( $config, $fields ) ),
			);
		}
		return $item_data;
	}

	public function save_order_item( $item, $cart_item_key, $values, $order ): void {
		if ( empty( $values['vcap_fields'] ) ) {
			return;
		}
		$item->add_meta_data( '_vcap_fields', $values['vcap_fields'], true );
		// Visible copy (admin order screen, emails, {item_meta:…} placeholders). M10: under a stable key
		// ("vcap_recipient_name"), whatever the language of the order; the label is shown by display_meta_key().
		foreach ( $values['vcap_fields'] as $key => $field ) {
			if ( '' !== (string) $field['value'] ) {
				$item->add_meta_data( self::item_meta_key( (string) $key ), $field['value'], true );
			}
		}
	}

	/** M10: order item meta key of a field's visible copy. */
	public static function item_meta_key( string $field_key ): string {
		return self::ITEM_META_PREFIX . sanitize_key( $field_key );
	}

	/** Prefix of the visible order item meta of voucher fields (M10). */
	public const ITEM_META_PREFIX = 'vcap_';

	/** Built-in fields about the recipient (AP-S3-1): erased with the recipient's data. */
	public const RECIPIENT_FIELDS = array( 'recipient_name', 'recipient_email', 'message' );

	/**
	 * AP-S3-1: removes the personalisation copies from an order line – the value in "_vcap_fields" (the label stays)
	 * and the visible copy under the stable key ("vcap_recipient_email") or, for orders placed before M10, under
	 * the field's label. Scopes:
	 * - "recipient": the recipient fields and the merchant's own fields (the voucher row drops all its fields on
	 *   a recipient erasure, so the order line follows it);
	 * - "buyer": the merchant's own fields (typed by the buyer, they cannot be attributed to the recipient);
	 * - "all": every field (retention, WooCommerce's order anonymisation).
	 * The delivery date is no personal data and stays. Saves the line when something changed.
	 *
	 * @param \WC_Order_Item $item  Order line.
	 * @param string         $scope recipient|buyer|all.
	 * @return bool Whether something was removed.
	 */
	public static function erase_item_data( $item, string $scope ): bool {
		if ( ! $item instanceof \WC_Order_Item ) {
			return false;
		}
		$fields = $item->get_meta( '_vcap_fields' );
		$fields = is_array( $fields ) ? $fields : array();
		$keys   = array();
		foreach ( array_keys( $fields ) as $key ) {
			$key = (string) $key;
			if ( 'delivery_date' === $key ) {
				continue;
			}
			$recipient = in_array( $key, self::RECIPIENT_FIELDS, true );
			if ( 'all' === $scope || ! $recipient || 'recipient' === $scope ) {
				$keys[] = $key;
			}
		}
		if ( 'buyer' !== $scope ) {
			// Copies of the recipient fields without a "_vcap_fields" entry (edited lines, partial data).
			$keys = array_values( array_unique( array_merge( $keys, self::RECIPIENT_FIELDS ) ) );
		}
		$changed = false;
		foreach ( $keys as $key ) {
			$field = is_array( $fields[ $key ] ?? null ) ? $fields[ $key ] : null;
			$value = null !== $field ? (string) ( $field['value'] ?? '' ) : '';
			if ( $item->meta_exists( self::item_meta_key( $key ) ) ) {
				$item->delete_meta_data( self::item_meta_key( $key ) );
				$changed = true;
			}
			$label = null !== $field ? wp_strip_all_tags( (string) ( $field['label'] ?? '' ) ) : '';
			if ( '' !== $label && '' !== $value && $item->meta_exists( $label ) && (string) $item->get_meta( $label ) === $value ) {
				$item->delete_meta_data( $label );
				$changed = true;
			}
			if ( null !== $field && '' !== $value ) {
				$fields[ $key ]['value'] = '';
				$changed                 = true;
			}
		}
		if ( ! $changed ) {
			return false;
		}
		if ( $fields ) {
			$item->update_meta_data( '_vcap_fields', $fields );
		}
		$item->save();
		return true;
	}

	/**
	 * AP-S3-1: the order line a voucher was issued for (false when there is none any more).
	 *
	 * @return \WC_Order_Item|false
	 */
	public static function voucher_item( int $order_item_id ) {
		if ( $order_item_id <= 0 || ! class_exists( '\WC_Order_Factory' ) ) {
			return false;
		}
		return \WC_Order_Factory::get_order_item( $order_item_id );
	}

	/**
	 * AP-S3-1: order lines whose visible recipient email copy is this address (also lines of orders that never
	 * issued a voucher: unpaid, cancelled). At most 500 per call.
	 *
	 * @return int[] order item ids
	 */
	public static function items_for_recipient( string $email ): array {
		global $wpdb;
		$email = trim( $email );
		if ( '' === $email ) {
			return array();
		}
		$table = $wpdb->prefix . 'woocommerce_order_itemmeta';
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT order_item_id FROM {$table} WHERE meta_key = %s AND meta_value = %s LIMIT 500", self::item_meta_key( 'recipient_email' ), $email ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.SlowDBQuery -- table name from $wpdb.
	}

	/**
	 * AP-S3-1: WooCommerce's order anonymisation (erasure request with "Remove personal data from orders", or its
	 * own retention of orders) also clears the personalisation copies of the order lines.
	 *
	 * @param \WC_Order $order Order being anonymised.
	 */
	public static function anonymise_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			self::erase_item_data( $item, 'all' );
		}
	}

	/**
	 * H11/M10: a field value as people read it – a date in the voucher date format ("24.12.2026" instead of
	 * "2026-12-24"), anything else unchanged.
	 */
	public static function display_value( string $value ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		$ts = LocalDate::timestamp( $value . 'T12:00' );
		return null === $ts ? $value : wp_date( function_exists( 'vcap' ) ? vcap()->settings->date_format() : (string) get_option( 'date_format' ), $ts );
	}

	/** H11: the local time of day dated deliveries go out ("8:00 am"), for the help texts. */
	public static function delivery_time_text(): string {
		$dt = new \DateTimeImmutable( 'today', wp_timezone() );
		return wp_date( (string) get_option( 'time_format', 'H:i' ), $dt->setTime( Delivery::delivery_hour(), 0 )->getTimestamp() );
	}

	/**
	 * M10: label of a field's visible order item meta, from the field as ordered (_vcap_fields).
	 *
	 * @param string $display_key
	 * @param object $meta WC_Meta_Data
	 * @param mixed  $item
	 */
	public function display_meta_key( $display_key, $meta, $item = null ) {
		$key = is_object( $meta ) && isset( $meta->key ) ? (string) $meta->key : '';
		if ( ! str_starts_with( $key, self::ITEM_META_PREFIX ) || ! $item instanceof \WC_Order_Item ) {
			return $display_key;
		}
		$fields = $item->get_meta( '_vcap_fields' );
		$field  = is_array( $fields ) ? ( $fields[ substr( $key, strlen( self::ITEM_META_PREFIX ) ) ] ?? null ) : null;
		return is_array( $field ) && '' !== (string) ( $field['label'] ?? '' ) ? wp_strip_all_tags( (string) $field['label'] ) : $display_key;
	}

	/**
	 * M10/H11: dates of voucher fields (also the label-keyed copies of older orders) in the voucher date format.
	 *
	 * @param string $display_value
	 * @param object $meta WC_Meta_Data
	 * @param mixed  $item
	 */
	public function display_meta_value( $display_value, $meta, $item = null ) {
		if ( ! $item instanceof \WC_Order_Item || ! is_object( $meta ) || ! is_scalar( $meta->value ?? null ) || ! is_array( $item->get_meta( '_vcap_fields' ) ) ) {
			return $display_value;
		}
		$formatted = self::display_value( (string) $meta->value );
		return $formatted !== (string) $meta->value ? esc_html( $formatted ) : $display_value;
	}

	/**
	 * The visible copy of the personalisation (e.g. "Recipient email") can be edited on the order screen while the
	 * order waits for its payment, but vouchers are issued from "_vcap_fields" (Z12). A corrected value is taken
	 * over into "_vcap_fields" as long as no voucher of the line exists yet; an emptied or invalid value is not
	 * (a typo such as "gmial.com" is corrected, a voucher never goes to an address that is not one).
	 *
	 * @param \WC_Order_Item $item Order item about to be saved.
	 */
	public function sync_visible_meta( $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product || ! $item->get_id() ) {
			return;
		}
		$fields = $item->get_meta( '_vcap_fields' );
		if ( ! is_array( $fields ) || ! $fields || vcap()->vouchers->get_slot( (int) $item->get_id(), 0 ) ) {
			return;
		}
		$changed = false;
		foreach ( $fields as $key => $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$visible = self::visible_meta( $item, (string) $key, $field );
			if ( ! is_scalar( $visible ) || '' === (string) $visible || (string) $visible === (string) ( $field['value'] ?? '' ) ) {
				continue;
			}
			$value = self::visible_value( (string) $key, (string) $visible );
			if ( null === $value || $value === (string) ( $field['value'] ?? '' ) ) {
				continue;
			}
			$fields[ $key ]['value'] = $value;
			$changed                 = true;
		}
		if ( $changed ) {
			$item->update_meta_data( '_vcap_fields', $fields );
		}
	}

	/**
	 * Z12/M10: the visible copy of a field on an order line – under its stable key ("vcap_recipient_email") or,
	 * for orders placed before M10, under the field's label.
	 *
	 * @return mixed The value, '' when the line has no visible copy of the field.
	 */
	private static function visible_meta( \WC_Order_Item $item, string $key, array $field ) {
		$stable = self::item_meta_key( $key );
		if ( $item->meta_exists( $stable ) ) {
			return $item->get_meta( $stable );
		}
		$label = wp_strip_all_tags( (string) ( $field['label'] ?? '' ) );
		return '' !== $label ? $item->get_meta( $label ) : '';
	}

	/** A value typed into the visible order item meta, sanitised for its field (null: not accepted). */
	public static function visible_value( string $key, string $value ): ?string {
		$value = trim( $value );
		if ( str_contains( $key, 'email' ) ) {
			$email = sanitize_email( $value );
			return is_email( $email ) ? $email : null;
		}
		if ( 'delivery_date' === $key ) {
			$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
			return $date && $date->format( 'Y-m-d' ) === $value ? $value : null;
		}
		return sanitize_textarea_field( $value );
	}

	public function order_again( $data, $item, $order ) {
		$fields = $item->get_meta( '_vcap_fields' );
		if ( is_array( $fields ) ) {
			// M12-59: the old delivery date is not taken over (it has usually passed: the voucher would go out at once).
			unset( $fields['delivery_date'] );
		}
		if ( $fields ) {
			$data['vcap_fields'] = $fields;
			$data['vcap_key']    = md5( wp_json_encode( $fields ) );
		}
		return $data;
	}

	/**
	 * Field values as the form would send them (key => value) from stored cart item data (key => label/value).
	 *
	 * @param array<string, mixed> $fields Cart item "vcap_fields".
	 * @return array<string, string>
	 */
	private static function plain_values( array $fields ): array {
		$out = array();
		foreach ( $fields as $key => $field ) {
			$value = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;

			$out[ (string) $key ] = is_scalar( $value ) ? (string) $value : '';
		}
		return $out;
	}

	/**
	 * M12-59: a delivery date in the cart that has passed meanwhile (or now lies after the voucher's expiry) stops the
	 * checkout instead of sending the voucher at once (classic checkout and Store API run this action).
	 */
	public function check_cart_items(): void {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}
		$today = wp_date( 'Y-m-d' );
		foreach ( WC()->cart->get_cart() as $values ) {
			$date    = (string) ( $values['vcap_fields']['delivery_date']['value'] ?? '' );
			$product = $values['data'] ?? null;
			if ( '' === $date || ! $product instanceof \WC_Product ) {
				continue;
			}
			$latest = Delivery::latest_delivery_date( ProductConfig::get( $product ), time(), self::customer_country() );
			if ( $date >= $today && ( null === $latest || $date <= $latest ) ) {
				continue;
			}
			wc_add_notice(
				sprintf(
					/* translators: 1: product name, 2: delivery date */
					__( 'The delivery date of "%1$s" (%2$s) is no longer possible. Please remove the voucher from the cart and add it again with another date.', 'voucher-captain' ),
					$product->get_name(),
					wp_date( (string) get_option( 'date_format' ), (int) strtotime( $date . ' 12:00:00 ' . wp_timezone_string() ) )
				),
				'error'
			);
		}
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
		wp_enqueue_script( 'vcap-field-groups', VCAP_URL . 'assets/js/field-groups.js', array( 'jquery', 'jquery-ui-sortable', 'wc-enhanced-select', 'wp-a11y' ), VCAP_VERSION, true );
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
					'moveUp'      => __( 'Move up', 'voucher-captain' ),
					'moveDown'    => __( 'Move down', 'voucher-captain' ),
					/* translators: %d: position of the field in the list */
					'field'       => __( 'Field %d', 'voucher-captain' ),
					/* translators: 1: field label, 2: new position in the list */
					'moved'       => __( '%1$s moved to position %2$d.', 'voucher-captain' ),
					'removed'     => __( 'Field removed.', 'voucher-captain' ),
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

	/**
	 * M11: the {field:KEY} key of a field – the key entered, else the label, as lowercase ASCII with "_"; labels
	 * without Latin letters (Greek, Bulgarian …) get "field_N" (N = position in the group). Same rule as slug() in
	 * assets/js/field-groups.js, so the token shown while editing is the key saved. '' for an empty row.
	 */
	public static function field_key( string $key, string $label, int $position ): string {
		$raw = '' !== trim( $key ) ? $key : $label;
		if ( '' === trim( $raw ) ) {
			return '';
		}
		$slug = strtolower( remove_accents( str_replace( 'ß', 'ss', $raw ) ) );
		$slug = trim( (string) preg_replace( '/[^a-z0-9]+/', '_', $slug ), '_' );
		if ( '' === $slug ) {
			return 'field_' . max( 1, $position );
		}
		return ctype_digit( $slug ) ? 'field_' . $slug : $slug;
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
		foreach ( array_values( is_array( $fields_in ) ? $fields_in : array() ) as $index => $f ) {
			$key = self::field_key( (string) ( $f['key'] ?? '' ), (string) ( $f['label'] ?? '' ), $index + 1 );
			if ( '' === $key ) {
				continue;
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
