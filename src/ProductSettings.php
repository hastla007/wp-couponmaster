<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * "Voucher" product type option, product data tab and per-variation overrides.
 */
final class ProductSettings {
	public function register(): void {
		add_filter( 'product_type_options', array( $this, 'type_option' ) );
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( $this, 'save' ) );
		add_action( 'woocommerce_variation_options', array( $this, 'variation_option' ), 10, 3 );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_admin_process_variation_object', array( $this, 'save_variation' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	public function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( $screen && 'product' === $screen->post_type && in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			wp_enqueue_style( 'wcvs-admin', WCVS_URL . 'assets/css/admin.css', array(), WCVS_VERSION );
			wp_enqueue_script( 'wcvs-product-admin', WCVS_URL . 'assets/js/product-admin.js', array( 'jquery' ), WCVS_VERSION, true );
		}
	}

	public function type_option( array $options ): array {
		$options['wcvs_enabled'] = array(
			'id'            => '_wcvs_enabled',
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => __( 'Voucher', 'wc-voucher-studio' ),
			'description'   => __( 'Sell this product as a PDF voucher, gift card or ticket.', 'wc-voucher-studio' ),
			'default'       => 'no',
		);
		return $options;
	}

	public function tab( array $tabs ): array {
		$tabs['wcvs'] = array(
			'label'    => __( 'Voucher', 'wc-voucher-studio' ),
			'target'   => 'wcvs_product_data',
			'class'    => array( 'show_if_wcvs' ),
			'priority' => 25,
		);
		return $tabs;
	}

	private function template_options(): array {
		$options = array( '' => __( 'Default template', 'wc-voucher-studio' ) );
		foreach ( get_posts( array( 'post_type' => TemplatePostType::POST_TYPE, 'numberposts' => 200, 'post_status' => 'publish' ) ) as $p ) {
			$options[ $p->ID ] = $p->post_title;
		}
		return $options;
	}

	private function batch_options(): array {
		$options = array( '' => __( 'Generate random codes', 'wc-voucher-studio' ) );
		foreach ( wcvs()->code_pool->batches() as $b ) {
			/* translators: 1: batch, 2: free codes, 3: total */
			$options[ $b['batch'] ] = sprintf( __( 'Imported batch "%1$s" (%2$d of %3$d free)', 'wc-voucher-studio' ), $b['batch'], $b['free'], $b['total'] );
		}
		return $options;
	}

	private function v( \WC_Product $product, string $key ) {
		return $product->meta_exists( '_wcvs_' . $key ) ? $product->get_meta( '_wcvs_' . $key ) : ProductConfig::DEFAULTS[ $key ];
	}

	public function panel(): void {
		global $product_object;
		$p = $product_object instanceof \WC_Product ? $product_object : new \WC_Product_Simple();
		echo '<div id="wcvs_product_data" class="panel woocommerce_options_panel hidden">';

		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_type',
				'label'   => __( 'Type', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'type' ),
				'options' => Voucher::types(),
				'desc_tip'    => true,
				'description' => __( 'Tickets get a QR check-in code and do not create a discount coupon unless a value is set.', 'wc-voucher-studio' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_template',
				'label'   => __( 'PDF template', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'template' ),
				'options' => $this->template_options(),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_per_item',
				'label'             => __( 'PDFs per item', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'per_item' ),
				'custom_attributes' => array( 'min' => 1, 'max' => 100, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'Number of separate vouchers/tickets issued for each purchased quantity (e.g. 2 for a "ticket for two").', 'wc-voucher-studio' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_wcvs_preview',
				'label'       => __( 'Customer preview', 'wc-voucher-studio' ),
				'value'       => $this->v( $p, 'preview' ),
				'description' => __( 'Show a "Preview voucher" button on the product page.', 'wc-voucher-studio' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="wcvs-group-title">' . esc_html__( 'Value', 'wc-voucher-studio' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_amount_source',
				'label'   => __( 'Voucher value', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'amount_source' ),
				'options' => array(
					'price'  => __( 'Product price paid', 'wc-voucher-studio' ),
					'fixed'  => __( 'Fixed value', 'wc-voucher-studio' ),
					'custom' => __( 'Customer chooses the amount', 'wc-voucher-studio' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_wcvs_amount',
				'label'         => __( 'Fixed value', 'wc-voucher-studio' ),
				'data_type'     => 'price',
				'value'         => $this->v( $p, 'amount' ),
				'wrapper_class' => 'wcvs-show-fixed',
				'desc_tip'      => true,
				'description'   => __( 'Amount (or percentage for percentage discounts) the voucher is worth.', 'wc-voucher-studio' ),
			)
		);
		foreach ( array( 'custom_min' => __( 'Minimum amount', 'wc-voucher-studio' ), 'custom_max' => __( 'Maximum amount', 'wc-voucher-studio' ), 'custom_step' => __( 'Step', 'wc-voucher-studio' ) ) as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'            => '_wcvs_' . $key,
					'label'         => $label,
					'data_type'     => 'price',
					'value'         => $this->v( $p, $key ),
					'wrapper_class' => 'wcvs-show-custom',
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'            => '_wcvs_custom_presets',
				'label'         => __( 'Suggested amounts', 'wc-voucher-studio' ),
				'value'         => $this->v( $p, 'custom_presets' ),
				'placeholder'   => '25, 50, 100',
				'wrapper_class' => 'wcvs-show-custom',
				'desc_tip'      => true,
				'description'   => __( 'Comma separated amounts shown as quick-pick buttons.', 'wc-voucher-studio' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_discount_type',
				'label'   => __( 'Discount type', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'discount_type' ),
				'options' => wc_get_coupon_types(),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="wcvs-group-title">' . esc_html__( 'Redemption & validity', 'wc-voucher-studio' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'          => '_wcvs_usage',
				'label'       => __( 'Usage', 'wc-voucher-studio' ),
				'value'       => $this->v( $p, 'usage' ),
				'options'     => array(
					'single'    => __( 'Single use (whole value at once)', 'wc-voucher-studio' ),
					'balance'   => __( 'Multi-use with stored balance (gift card)', 'wc-voucher-studio' ),
					'unlimited' => __( 'Reusable (usage limit below)', 'wc-voucher-studio' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Gift cards keep the remaining balance after each purchase until it is used up.', 'wc-voucher-studio' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_usage_limit',
				'label'             => __( 'Usage limit', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'usage_limit' ),
				'placeholder'       => __( 'Unlimited', 'wc-voucher-studio' ),
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'wrapper_class'     => 'wcvs-show-unlimited',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_expiry_mode',
				'label'   => __( 'Expiry', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'expiry_mode' ),
				'options' => array(
					'default' => __( 'Global default', 'wc-voucher-studio' ),
					'days'    => __( 'Days after purchase', 'wc-voucher-studio' ),
					'date'    => __( 'Fixed date', 'wc-voucher-studio' ),
					'never'   => __( 'Never expires', 'wc-voucher-studio' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_expiry_days',
				'label'             => __( 'Valid for (days)', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'expiry_days' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'wrapper_class'     => 'wcvs-show-expiry-days',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_wcvs_expiry_date',
				'label'         => __( 'Expiry date', 'wc-voucher-studio' ),
				'type'          => 'date',
				'value'         => $this->v( $p, 'expiry_date' ),
				'wrapper_class' => 'wcvs-show-expiry-date',
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="wcvs-group-title">' . esc_html__( 'Coupon restrictions', 'wc-voucher-studio' ) . '</h4>';
		foreach ( array(
			'free_shipping'  => __( 'Allow free shipping', 'wc-voucher-studio' ),
			'individual_use' => __( 'Individual use only', 'wc-voucher-studio' ),
			'exclude_sale'   => __( 'Exclude sale items', 'wc-voucher-studio' ),
		) as $key => $label ) {
			woocommerce_wp_checkbox(
				array(
					'id'    => '_wcvs_' . $key,
					'label' => $label,
					'value' => $this->v( $p, $key ),
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'        => '_wcvs_min_spend',
				'label'     => __( 'Minimum spend', 'wc-voucher-studio' ),
				'data_type' => 'price',
				'value'     => $this->v( $p, 'min_spend' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => '_wcvs_max_spend',
				'label'     => __( 'Maximum spend', 'wc-voucher-studio' ),
				'data_type' => 'price',
				'value'     => $this->v( $p, 'max_spend' ),
			)
		);
		$this->product_search( '_wcvs_product_ids', __( 'Valid for products', 'wc-voucher-studio' ), (array) $this->v( $p, 'product_ids' ) );
		$this->product_search( '_wcvs_exclude_product_ids', __( 'Exclude products', 'wc-voucher-studio' ), (array) $this->v( $p, 'exclude_product_ids' ) );
		$this->category_select( '_wcvs_category_ids', __( 'Valid for categories', 'wc-voucher-studio' ), (array) $this->v( $p, 'category_ids' ) );
		$this->category_select( '_wcvs_exclude_category_ids', __( 'Exclude categories', 'wc-voucher-studio' ), (array) $this->v( $p, 'exclude_category_ids' ) );
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_email_restrict',
				'label'   => __( 'Restrict to email', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'email_restrict' ),
				'options' => array(
					'none'      => __( 'Anyone with the code', 'wc-voucher-studio' ),
					'recipient' => __( 'Recipient email only', 'wc-voucher-studio' ),
					'buyer'     => __( 'Buyer email only', 'wc-voucher-studio' ),
					'both'      => __( 'Buyer or recipient', 'wc-voucher-studio' ),
				),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="wcvs-group-title">' . esc_html__( 'Codes', 'wc-voucher-studio' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'          => '_wcvs_code_batch',
				'label'       => __( 'Code source', 'wc-voucher-studio' ),
				'value'       => $this->v( $p, 'code_batch' ),
				'options'     => $this->batch_options(),
				'desc_tip'    => true,
				'description' => __( 'Use codes imported under Vouchers → Import codes. Random codes are generated when the batch is empty.', 'wc-voucher-studio' ),
			)
		);
		foreach ( array( 'code_prefix' => __( 'Code prefix', 'wc-voucher-studio' ), 'code_suffix' => __( 'Code suffix', 'wc-voucher-studio' ) ) as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'          => '_wcvs_' . $key,
					'label'       => $label,
					'value'       => $this->v( $p, $key ),
					'placeholder' => __( 'Global setting', 'wc-voucher-studio' ),
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_code_length',
				'label'             => __( 'Random part length', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'code_length' ),
				'placeholder'       => __( 'Global setting', 'wc-voucher-studio' ),
				'custom_attributes' => array( 'min' => 4, 'max' => 40 ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="wcvs-group-title">' . esc_html__( 'Personalisation & delivery', 'wc-voucher-studio' ) . '</h4>';
		$builtin = (array) $this->v( $p, 'builtin_fields' );
		echo '<fieldset class="form-field wcvs-builtin-fields"><legend>' . esc_html__( 'Product page fields', 'wc-voucher-studio' ) . '</legend><div class="wcvs-builtin-grid">';
		foreach ( array(
			'recipient_name'  => __( 'Recipient name', 'wc-voucher-studio' ),
			'recipient_email' => __( 'Recipient email', 'wc-voucher-studio' ),
			'message'         => __( 'Personal message', 'wc-voucher-studio' ),
			'delivery_date'   => __( 'Delivery date', 'wc-voucher-studio' ),
		) as $key => $label ) {
			printf(
				'<span><label><input type="checkbox" name="_wcvs_builtin_fields[]" value="%1$s" %2$s /> %3$s</label> <label class="wcvs-required"><input type="checkbox" name="_wcvs_builtin_fields[]" value="%1$s_required" %4$s /> %5$s</label></span>',
				esc_attr( $key ),
				checked( in_array( $key, $builtin, true ), true, false ),
				esc_html( $label ),
				checked( in_array( $key . '_required', $builtin, true ), true, false ),
				esc_html__( 'required', 'wc-voucher-studio' )
			);
		}
		echo '</div><p class="description">' . esc_html__( 'Additional fields can be added under Vouchers → Field groups.', 'wc-voucher-studio' ) . '</p></fieldset>';
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_message_maxlength',
				'label'             => __( 'Message max. characters', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'message_maxlength' ),
				'custom_attributes' => array( 'min' => 10, 'max' => 2000 ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_wcvs_delivery',
				'label'   => __( 'Delivery', 'wc-voucher-studio' ),
				'value'   => $this->v( $p, 'delivery' ),
				'options' => Delivery::delivery_modes(),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_delivery_delay_days',
				'label'             => __( 'Delay (days)', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'delivery_delay_days' ),
				'custom_attributes' => array( 'min' => 1 ),
				'wrapper_class'     => 'wcvs-show-delay',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_wcvs_delivery_date',
				'label'         => __( 'Delivery date', 'wc-voucher-studio' ),
				'type'          => 'date',
				'value'         => $this->v( $p, 'delivery_date' ),
				'wrapper_class' => 'wcvs-show-date',
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_wcvs_send_to',
				'label'       => __( 'Send voucher to', 'wc-voucher-studio' ),
				'value'       => $this->v( $p, 'send_to' ),
				'options'     => array(
					'recipient' => __( 'Recipient (if an email was entered), otherwise buyer', 'wc-voucher-studio' ),
					'both'      => __( 'Recipient and buyer', 'wc-voucher-studio' ),
					'buyer'     => __( 'Buyer only (no email to recipient)', 'wc-voucher-studio' ),
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_wcvs_buyer_copy',
				'label'       => __( 'Buyer copy', 'wc-voucher-studio' ),
				'value'       => $this->v( $p, 'buyer_copy' ),
				'description' => __( 'Also send the voucher to the buyer when it goes to a recipient.', 'wc-voucher-studio' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group wcvs-show-ticket"><h4 class="wcvs-group-title">' . esc_html__( 'Ticket', 'wc-voucher-studio' ) . '</h4>';
		woocommerce_wp_text_input(
			array(
				'id'                => '_wcvs_max_checkins',
				'label'             => __( 'Admissions per ticket', 'wc-voucher-studio' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'max_checkins' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'How many times the QR code can be checked in (e.g. 10 for a 10-visit pass).', 'wc-voucher-studio' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_wcvs_event_date',
				'label' => __( 'Event date & time', 'wc-voucher-studio' ),
				'type'  => 'datetime-local',
				'value' => $this->v( $p, 'event_date' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_wcvs_event_location',
				'label' => __( 'Event location', 'wc-voucher-studio' ),
				'value' => $this->v( $p, 'event_location' ),
			)
		);
		echo '</div>';

		echo '</div>';
	}

	private function product_search( string $id, string $label, array $ids ): void {
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="wc-product-search" multiple style="width:50%" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'wc-voucher-studio' ) . '" data-action="woocommerce_json_search_products_and_variations">';
		foreach ( $ids as $pid ) {
			$product = wc_get_product( $pid );
			if ( $product ) {
				echo '<option value="' . esc_attr( (string) $pid ) . '" selected>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>';
			}
		}
		echo '</select></p>';
	}

	private function category_select( string $id, string $label, array $ids ): void {
		$ids = array_map( 'intval', $ids );
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="wc-enhanced-select" multiple style="width:50%" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" data-placeholder="' . esc_attr__( 'Any category', 'wc-voucher-studio' ) . '">';
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( $term->term_id, $ids, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select></p>';
	}

	public function save( \WC_Product $product ): void {
		// Nonce is verified by WooCommerce before woocommerce_admin_process_product_object fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_wcvs_enabled', isset( $_POST['_wcvs_enabled'] ) ? 'yes' : 'no' );
		if ( ! isset( $_POST['_wcvs_type'] ) ) {
			return;
		}
		$post = wp_unslash( $_POST );
		$text = array( 'type', 'template', 'amount_source', 'custom_presets', 'discount_type', 'usage', 'expiry_mode', 'email_restrict', 'code_batch', 'code_prefix', 'code_suffix', 'delivery', 'send_to', 'event_location' );
		foreach ( $text as $key ) {
			$product->update_meta_data( '_wcvs_' . $key, sanitize_text_field( (string) ( $post[ '_wcvs_' . $key ] ?? '' ) ) );
		}
		$decimals = array( 'amount', 'custom_min', 'custom_max', 'custom_step', 'min_spend', 'max_spend' );
		foreach ( $decimals as $key ) {
			$value = (string) ( $post[ '_wcvs_' . $key ] ?? '' );
			$product->update_meta_data( '_wcvs_' . $key, '' === $value ? '' : wc_format_decimal( $value ) );
		}
		$ints = array( 'per_item', 'usage_limit', 'expiry_days', 'code_length', 'delivery_delay_days', 'max_checkins', 'message_maxlength' );
		foreach ( $ints as $key ) {
			$value = (string) ( $post[ '_wcvs_' . $key ] ?? '' );
			$product->update_meta_data( '_wcvs_' . $key, '' === $value ? '' : absint( $value ) );
		}
		foreach ( array( 'expiry_date', 'delivery_date' ) as $key ) {
			$value = (string) ( $post[ '_wcvs_' . $key ] ?? '' );
			$product->update_meta_data( '_wcvs_' . $key, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '' );
		}
		$event = (string) ( $post['_wcvs_event_date'] ?? '' );
		$product->update_meta_data( '_wcvs_event_date', preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $event ) ? $event : '' );
		foreach ( array( 'free_shipping', 'individual_use', 'exclude_sale', 'preview', 'buyer_copy' ) as $key ) {
			$product->update_meta_data( '_wcvs_' . $key, isset( $post[ '_wcvs_' . $key ] ) ? 'yes' : 'no' );
		}
		foreach ( array( 'product_ids', 'exclude_product_ids', 'category_ids', 'exclude_category_ids' ) as $key ) {
			$product->update_meta_data( '_wcvs_' . $key, array_values( array_filter( array_map( 'absint', (array) ( $post[ '_wcvs_' . $key ] ?? array() ) ) ) ) );
		}
		$allowed = array( 'recipient_name', 'recipient_email', 'message', 'delivery_date' );
		$builtin = array();
		foreach ( (array) ( $post['_wcvs_builtin_fields'] ?? array() ) as $value ) {
			$value = sanitize_key( $value );
			if ( in_array( preg_replace( '/_required$/', '', $value ), $allowed, true ) ) {
				$builtin[] = $value;
			}
		}
		$product->update_meta_data( '_wcvs_builtin_fields', $builtin );
		// phpcs:enable
	}

	public function variation_option( $loop, $variation_data, $variation ): void {
		$value = get_post_meta( $variation->ID, '_wcvs_enabled', true );
		printf(
			'<label class="tips show_if_wcvs_parent" data-tip="%1$s"> %2$s <input type="checkbox" class="checkbox wcvs_variation_enabled" name="wcvs_variation_enabled[%3$d]" %4$s /></label>',
			esc_attr__( 'Issue vouchers for this variation.', 'wc-voucher-studio' ),
			esc_html__( 'Voucher', 'wc-voucher-studio' ),
			(int) $loop,
			checked( 'no' !== $value, true, false )
		);
	}

	public function variation_fields( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}
		echo '<div class="wcvs-variation-fields show_if_wcvs_variation">';
		echo '<p class="form-row form-row-full"><strong>' . esc_html__( 'Voucher overrides (empty = use product settings)', 'wc-voucher-studio' ) . '</strong></p>';
		woocommerce_wp_select(
			array(
				'id'            => "wcvs_var_template_{$loop}",
				'name'          => "wcvs_var[{$loop}][template]",
				'label'         => __( 'PDF template', 'wc-voucher-studio' ),
				'value'         => $product->get_meta( '_wcvs_template' ),
				'options'       => array( '' => __( 'Inherit', 'wc-voucher-studio' ) ) + array_slice( $this->template_options(), 1, null, true ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "wcvs_var_amount_{$loop}",
				'name'          => "wcvs_var[{$loop}][amount]",
				'label'         => __( 'Fixed value', 'wc-voucher-studio' ),
				'data_type'     => 'price',
				'value'         => $product->get_meta( '_wcvs_amount' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "wcvs_var_expiry_days_{$loop}",
				'name'          => "wcvs_var[{$loop}][expiry_days]",
				'label'         => __( 'Valid for (days)', 'wc-voucher-studio' ),
				'type'          => 'number',
				'value'         => $product->get_meta( '_wcvs_expiry_days' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "wcvs_var_per_item_{$loop}",
				'name'          => "wcvs_var[{$loop}][per_item]",
				'label'         => __( 'PDFs per item', 'wc-voucher-studio' ),
				'type'          => 'number',
				'value'         => $product->get_meta( '_wcvs_per_item' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "wcvs_var_max_checkins_{$loop}",
				'name'          => "wcvs_var[{$loop}][max_checkins]",
				'label'         => __( 'Admissions per ticket', 'wc-voucher-studio' ),
				'type'          => 'number',
				'value'         => $product->get_meta( '_wcvs_max_checkins' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "wcvs_var_event_date_{$loop}",
				'name'          => "wcvs_var[{$loop}][event_date]",
				'label'         => __( 'Event date & time', 'wc-voucher-studio' ),
				'type'          => 'datetime-local',
				'value'         => $product->get_meta( '_wcvs_event_date' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		echo '</div>';
	}

	public function save_variation( \WC_Product_Variation $variation, int $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variations nonce.
		$variation->update_meta_data( '_wcvs_enabled', isset( $_POST['wcvs_variation_enabled'][ $loop ] ) ? 'yes' : 'no' );
		$data = isset( $_POST['wcvs_var'][ $loop ] ) ? (array) wp_unslash( $_POST['wcvs_var'][ $loop ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$variation->update_meta_data( '_wcvs_template', isset( $data['template'] ) && '' !== $data['template'] ? absint( $data['template'] ) : '' );
		$variation->update_meta_data( '_wcvs_amount', isset( $data['amount'] ) && '' !== $data['amount'] ? wc_format_decimal( $data['amount'] ) : '' );
		foreach ( array( 'expiry_days', 'per_item', 'max_checkins' ) as $key ) {
			$variation->update_meta_data( '_wcvs_' . $key, isset( $data[ $key ] ) && '' !== $data[ $key ] ? absint( $data[ $key ] ) : '' );
		}
		$event = (string) ( $data['event_date'] ?? '' );
		$variation->update_meta_data( '_wcvs_event_date', preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $event ) ? $event : '' );
	}
}
