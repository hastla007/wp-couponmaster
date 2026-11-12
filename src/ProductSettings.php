<?php
namespace VCAP;

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
			wp_enqueue_style( 'vcap-admin', VCAP_URL . 'assets/css/admin.css', array(), VCAP_VERSION );
			wp_enqueue_script( 'vcap-product-admin', VCAP_URL . 'assets/js/product-admin.js', array( 'jquery' ), VCAP_VERSION, true );
		}
	}

	public function type_option( array $options ): array {
		$options['vcap_enabled'] = array(
			'id'            => '_vcap_enabled',
			'wrapper_class' => 'show_if_simple show_if_variable',
			'label'         => __( 'Voucher', 'voucher-captain' ),
			'description'   => __( 'Sell this product as a PDF voucher, gift card or ticket.', 'voucher-captain' ),
			'default'       => 'no',
		);
		return $options;
	}

	public function tab( array $tabs ): array {
		$tabs['vcap'] = array(
			'label'    => __( 'Voucher', 'voucher-captain' ),
			'target'   => 'vcap_product_data',
			'class'    => array( 'show_if_vcap' ),
			'priority' => 25,
		);
		return $tabs;
	}

	private function template_options(): array {
		$options = array( '' => __( 'Default template', 'voucher-captain' ) );
		foreach ( get_posts( array( 'post_type' => TemplatePostType::POST_TYPE, 'numberposts' => 200, 'post_status' => 'publish' ) ) as $p ) {
			$options[ $p->ID ] = $p->post_title;
		}
		return $options;
	}

	private function batch_options(): array {
		$options = array( '' => __( 'Generate random codes', 'voucher-captain' ) );
		foreach ( vcap()->code_pool->batches() as $b ) {
			/* translators: 1: batch, 2: free codes, 3: total */
			$options[ $b['batch'] ] = sprintf( __( 'Imported batch "%1$s" (%2$d of %3$d free)', 'voucher-captain' ), $b['batch'], $b['free'], $b['total'] );
		}
		return $options;
	}

	private function v( \WC_Product $product, string $key ) {
		return $product->meta_exists( '_vcap_' . $key ) ? $product->get_meta( '_vcap_' . $key ) : ProductConfig::DEFAULTS[ $key ];
	}

	public function panel(): void {
		global $product_object;
		$p = $product_object instanceof \WC_Product ? $product_object : new \WC_Product_Simple();
		echo '<div id="vcap_product_data" class="panel woocommerce_options_panel hidden">';

		echo '<div class="options_group">';
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_type',
				'label'   => __( 'Type', 'voucher-captain' ),
				'value'   => $this->v( $p, 'type' ),
				'options' => array_diff_key( Voucher::types(), array( 'credit' => true ) ),
				'desc_tip'    => true,
				'description' => __( 'Tickets get a QR check-in code and do not create a discount coupon unless a value is set.', 'voucher-captain' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_template',
				'label'   => __( 'PDF template', 'voucher-captain' ),
				'value'   => $this->v( $p, 'template' ),
				'options' => $this->template_options(),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_per_item',
				'label'             => __( 'PDFs per item', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'per_item' ),
				'custom_attributes' => array( 'min' => 1, 'max' => 100, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'Number of separate vouchers/tickets issued for each purchased quantity (e.g. 2 for a "ticket for two").', 'voucher-captain' ),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_vcap_preview',
				'label'       => __( 'Customer preview', 'voucher-captain' ),
				'value'       => $this->v( $p, 'preview' ),
				'description' => __( 'Show a "Preview voucher" button on the product page.', 'voucher-captain' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="vcap-group-title">' . esc_html__( 'Value', 'voucher-captain' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_amount_source',
				'label'   => __( 'Voucher value', 'voucher-captain' ),
				'value'   => $this->v( $p, 'amount_source' ),
				'options' => array(
					'price'  => __( 'Product price paid', 'voucher-captain' ),
					'fixed'  => __( 'Fixed value', 'voucher-captain' ),
					'custom' => __( 'Customer chooses the amount', 'voucher-captain' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_vcap_amount',
				'label'         => __( 'Fixed value', 'voucher-captain' ),
				'data_type'     => 'price',
				'value'         => $this->v( $p, 'amount' ),
				'wrapper_class' => 'vcap-show-fixed',
				'desc_tip'      => true,
				'description'   => __( 'Amount (or percentage for percentage discounts) the voucher is worth.', 'voucher-captain' ),
			)
		);
		foreach ( array( 'custom_min' => __( 'Minimum amount', 'voucher-captain' ), 'custom_max' => __( 'Maximum amount', 'voucher-captain' ), 'custom_step' => __( 'Step', 'voucher-captain' ) ) as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'            => '_vcap_' . $key,
					'label'         => $label,
					'data_type'     => 'price',
					'value'         => $this->v( $p, $key ),
					'wrapper_class' => 'vcap-show-custom',
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'            => '_vcap_custom_presets',
				'label'         => __( 'Suggested amounts', 'voucher-captain' ),
				'value'         => $this->v( $p, 'custom_presets' ),
				'placeholder'   => '25, 50, 100',
				'wrapper_class' => 'vcap-show-custom',
				'desc_tip'      => true,
				'description'   => __( 'Comma separated amounts shown as quick-pick buttons.', 'voucher-captain' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_discount_type',
				'label'   => __( 'Discount type', 'voucher-captain' ),
				'value'   => $this->v( $p, 'discount_type' ),
				'options' => wc_get_coupon_types(),
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_vcap_tax_treatment',
				'label'       => __( 'VAT treatment', 'voucher-captain' ),
				'value'       => $this->v( $p, 'tax_treatment' ),
				'options'     => array(
					'auto'     => __( 'Automatic (value vouchers: multi-purpose, percentage: discount)', 'voucher-captain' ),
					'payment'  => __( 'Multi-purpose voucher (means of payment, VAT on redemption)', 'voucher-captain' ),
					'single'   => __( 'Single-purpose voucher (VAT already charged at sale)', 'voucher-captain' ),
					'discount' => __( 'Promotion (price reduction)', 'voucher-captain' ),
				),
				'desc_tip'    => true,
				'description' => __( 'How connected checkouts (e.g. WP Pass Captain) treat the voucher for VAT. Have the classification checked by your tax adviser.', 'voucher-captain' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="vcap-group-title">' . esc_html__( 'Redemption & validity', 'voucher-captain' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'          => '_vcap_usage',
				'label'       => __( 'Usage', 'voucher-captain' ),
				'value'       => $this->v( $p, 'usage' ),
				'options'     => array(
					'single'    => __( 'Single use (whole value at once)', 'voucher-captain' ),
					'balance'   => __( 'Multi-use with stored balance (gift card)', 'voucher-captain' ),
					'unlimited' => __( 'Reusable (usage limit below)', 'voucher-captain' ),
				),
				'desc_tip'    => true,
				'description' => __( 'Gift cards keep the remaining balance after each purchase until it is used up.', 'voucher-captain' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_usage_limit',
				'label'             => __( 'Usage limit', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'usage_limit' ),
				'placeholder'       => __( 'Unlimited', 'voucher-captain' ),
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'wrapper_class'     => 'vcap-show-unlimited',
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_expiry_mode',
				'label'   => __( 'Expiry', 'voucher-captain' ),
				'value'   => $this->v( $p, 'expiry_mode' ),
				'options' => array(
					'default' => __( 'Global default', 'voucher-captain' ),
					'days'    => __( 'Days after purchase', 'voucher-captain' ),
					'date'    => __( 'Fixed date', 'voucher-captain' ),
					'never'   => __( 'Never expires', 'voucher-captain' ),
				),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_expiry_days',
				'label'             => __( 'Valid for (days)', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'expiry_days' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'wrapper_class'     => 'vcap-show-expiry-days',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_vcap_expiry_date',
				'label'         => __( 'Expiry date', 'voucher-captain' ),
				'type'          => 'date',
				'value'         => $this->v( $p, 'expiry_date' ),
				'wrapper_class' => 'vcap-show-expiry-date',
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_vcap_transferable',
				'label'       => __( 'Transferable', 'voucher-captain' ),
				'value'       => $this->v( $p, 'transferable' ),
				'description' => __( 'Anyone with the code may redeem it. Untick to restrict redemption to the buyer and the named recipient (their email addresses).', 'voucher-captain' ),
			)
		);
		$channels = (array) ProductConfig::get( $p )['channels'];
		echo '<fieldset class="form-field vcap-channels"><legend>' . esc_html__( 'Redeemable via', 'voucher-captain' ) . '</legend>';
		foreach ( Channels::all() as $key => $label ) {
			echo '<label style="display:block;margin-left:0"><input type="checkbox" name="_vcap_channels[]" value="' . esc_attr( $key ) . '" ' . checked( in_array( $key, $channels, true ), true, false ) . ' /> ' . esc_html( $label ) . '</label>';
		}
		echo '<span class="description">' . esc_html__( 'Leave all unticked to allow every channel. Stored with each sold voucher.', 'voucher-captain' ) . '</span></fieldset>';
		/** Extra product fields of connectors (saved in vcap_product_settings_save). */
		do_action( 'vcap_product_settings_fields', $p );
		woocommerce_wp_textarea_input(
			array(
				'id'          => '_vcap_redemption_terms',
				'label'       => __( 'Redemption conditions', 'voucher-captain' ),
				'value'       => $this->v( $p, 'redemption_terms' ),
				'placeholder' => __( 'Shop default (WooCommerce → Settings → Vouchers)', 'voucher-captain' ),
				'desc_tip'    => true,
				'description' => __( 'Shown on the product page, in emails, in the customer account and via {redemption_terms} on the PDF. Stored with each voucher at purchase.', 'voucher-captain' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="vcap-group-title">' . esc_html__( 'Coupon restrictions', 'voucher-captain' ) . '</h4>';
		foreach ( array(
			'free_shipping'  => __( 'Allow free shipping', 'voucher-captain' ),
			'individual_use' => __( 'Individual use only', 'voucher-captain' ),
			'exclude_sale'   => __( 'Exclude sale items', 'voucher-captain' ),
		) as $key => $label ) {
			woocommerce_wp_checkbox(
				array(
					'id'    => '_vcap_' . $key,
					'label' => $label,
					'value' => $this->v( $p, $key ),
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'        => '_vcap_min_spend',
				'label'     => __( 'Minimum spend', 'voucher-captain' ),
				'data_type' => 'price',
				'value'     => $this->v( $p, 'min_spend' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'        => '_vcap_max_spend',
				'label'     => __( 'Maximum spend', 'voucher-captain' ),
				'data_type' => 'price',
				'value'     => $this->v( $p, 'max_spend' ),
			)
		);
		$this->product_search( '_vcap_product_ids', __( 'Valid for products', 'voucher-captain' ), (array) $this->v( $p, 'product_ids' ) );
		$this->product_search( '_vcap_exclude_product_ids', __( 'Exclude products', 'voucher-captain' ), (array) $this->v( $p, 'exclude_product_ids' ) );
		$this->category_select( '_vcap_category_ids', __( 'Valid for categories', 'voucher-captain' ), (array) $this->v( $p, 'category_ids' ) );
		$this->category_select( '_vcap_exclude_category_ids', __( 'Exclude categories', 'voucher-captain' ), (array) $this->v( $p, 'exclude_category_ids' ) );
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_email_restrict',
				'label'   => __( 'Restrict to email', 'voucher-captain' ),
				'value'   => $this->v( $p, 'email_restrict' ),
				'options' => array(
					'none'      => __( 'Anyone with the code', 'voucher-captain' ),
					'recipient' => __( 'Recipient email only', 'voucher-captain' ),
					'buyer'     => __( 'Buyer email only', 'voucher-captain' ),
					'both'      => __( 'Buyer or recipient', 'voucher-captain' ),
				),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="vcap-group-title">' . esc_html__( 'Codes', 'voucher-captain' ) . '</h4>';
		woocommerce_wp_select(
			array(
				'id'          => '_vcap_code_batch',
				'label'       => __( 'Code source', 'voucher-captain' ),
				'value'       => $this->v( $p, 'code_batch' ),
				'options'     => $this->batch_options(),
				'desc_tip'    => true,
				'description' => __( 'Use codes imported under Vouchers → Import codes. Random codes are generated when the batch is empty.', 'voucher-captain' ),
			)
		);
		foreach ( array( 'code_prefix' => __( 'Code prefix', 'voucher-captain' ), 'code_suffix' => __( 'Code suffix', 'voucher-captain' ) ) as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'          => '_vcap_' . $key,
					'label'       => $label,
					'value'       => $this->v( $p, $key ),
					'placeholder' => __( 'Global setting', 'voucher-captain' ),
				)
			);
		}
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_code_length',
				'label'             => __( 'Random part length', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'code_length' ),
				'placeholder'       => __( 'Global setting', 'voucher-captain' ),
				'custom_attributes' => array( 'min' => 4, 'max' => 40 ),
			)
		);
		echo '</div>';

		echo '<div class="options_group"><h4 class="vcap-group-title">' . esc_html__( 'Personalisation & delivery', 'voucher-captain' ) . '</h4>';
		$builtin = (array) $this->v( $p, 'builtin_fields' );
		echo '<fieldset class="form-field vcap-builtin-fields"><legend>' . esc_html__( 'Product page fields', 'voucher-captain' ) . '</legend><div class="vcap-builtin-grid">';
		foreach ( array(
			'recipient_name'  => __( 'Recipient name', 'voucher-captain' ),
			'recipient_email' => __( 'Recipient email', 'voucher-captain' ),
			'message'         => __( 'Personal message', 'voucher-captain' ),
			'delivery_date'   => __( 'Delivery date', 'voucher-captain' ),
		) as $key => $label ) {
			printf(
				'<span><label><input type="checkbox" name="_vcap_builtin_fields[]" value="%1$s" %2$s /> %3$s</label> <label class="vcap-required"><input type="checkbox" name="_vcap_builtin_fields[]" value="%1$s_required" %4$s /> %5$s</label></span>',
				esc_attr( $key ),
				checked( in_array( $key, $builtin, true ), true, false ),
				esc_html( $label ),
				checked( in_array( $key . '_required', $builtin, true ), true, false ),
				esc_html__( 'required', 'voucher-captain' )
			);
		}
		echo '</div><p class="description">' . esc_html__( 'Additional fields can be added under Vouchers → Field groups.', 'voucher-captain' ) . '</p></fieldset>';
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_message_maxlength',
				'label'             => __( 'Message max. characters', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'message_maxlength' ),
				'custom_attributes' => array( 'min' => 10, 'max' => 2000 ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_vcap_delivery',
				'label'   => __( 'Delivery', 'voucher-captain' ),
				'value'   => $this->v( $p, 'delivery' ),
				'options' => Delivery::delivery_modes(),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_delivery_delay_days',
				'label'             => __( 'Delay (days)', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'delivery_delay_days' ),
				'custom_attributes' => array( 'min' => 1 ),
				'wrapper_class'     => 'vcap-show-delay',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => '_vcap_delivery_date',
				'label'         => __( 'Delivery date', 'voucher-captain' ),
				'type'          => 'date',
				'value'         => $this->v( $p, 'delivery_date' ),
				'wrapper_class' => 'vcap-show-date',
			)
		);
		woocommerce_wp_select(
			array(
				'id'          => '_vcap_send_to',
				'label'       => __( 'Send voucher to', 'voucher-captain' ),
				'value'       => $this->v( $p, 'send_to' ),
				'options'     => array(
					'recipient' => __( 'Recipient (if an email was entered), otherwise buyer', 'voucher-captain' ),
					'both'      => __( 'Recipient and buyer', 'voucher-captain' ),
					'buyer'     => __( 'Buyer only (no email to recipient)', 'voucher-captain' ),
				),
			)
		);
		woocommerce_wp_checkbox(
			array(
				'id'          => '_vcap_buyer_copy',
				'label'       => __( 'Buyer copy', 'voucher-captain' ),
				'value'       => $this->v( $p, 'buyer_copy' ),
				'description' => __( 'Also send the voucher to the buyer when it goes to a recipient.', 'voucher-captain' ),
			)
		);
		echo '</div>';

		echo '<div class="options_group vcap-show-ticket"><h4 class="vcap-group-title">' . esc_html__( 'Ticket', 'voucher-captain' ) . '</h4>';
		woocommerce_wp_text_input(
			array(
				'id'                => '_vcap_max_checkins',
				'label'             => __( 'Admissions per ticket', 'voucher-captain' ),
				'type'              => 'number',
				'value'             => $this->v( $p, 'max_checkins' ),
				'custom_attributes' => array( 'min' => 1, 'step' => 1 ),
				'desc_tip'          => true,
				'description'       => __( 'How many times the QR code can be checked in (e.g. 10 for a 10-visit pass).', 'voucher-captain' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_vcap_event_date',
				'label' => __( 'Event date & time', 'voucher-captain' ),
				'type'  => 'datetime-local',
				'value' => $this->v( $p, 'event_date' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_vcap_event_location',
				'label' => __( 'Event location', 'voucher-captain' ),
				'value' => $this->v( $p, 'event_location' ),
			)
		);
		echo '</div>';

		echo '</div>';
	}

	private function product_search( string $id, string $label, array $ids ): void {
		echo '<p class="form-field"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label>';
		echo '<select class="wc-product-search" multiple style="width:50%" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" data-placeholder="' . esc_attr__( 'Search for a product…', 'voucher-captain' ) . '" data-action="woocommerce_json_search_products_and_variations">';
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
		echo '<select class="wc-enhanced-select" multiple style="width:50%" id="' . esc_attr( $id ) . '" name="' . esc_attr( $id ) . '[]" data-placeholder="' . esc_attr__( 'Any category', 'voucher-captain' ) . '">';
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		foreach ( is_wp_error( $terms ) ? array() : $terms as $term ) {
			echo '<option value="' . esc_attr( (string) $term->term_id ) . '" ' . selected( in_array( $term->term_id, $ids, true ), true, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		echo '</select></p>';
	}

	public function save( \WC_Product $product ): void {
		// Nonce is verified by WooCommerce before woocommerce_admin_process_product_object fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$product->update_meta_data( '_vcap_enabled', isset( $_POST['_vcap_enabled'] ) ? 'yes' : 'no' );
		if ( ! isset( $_POST['_vcap_type'] ) ) {
			return;
		}
		$post = wp_unslash( $_POST );
		$text = array( 'type', 'template', 'amount_source', 'custom_presets', 'discount_type', 'tax_treatment', 'usage', 'expiry_mode', 'email_restrict', 'code_batch', 'code_prefix', 'code_suffix', 'delivery', 'send_to', 'event_location' );
		foreach ( $text as $key ) {
			$product->update_meta_data( '_vcap_' . $key, sanitize_text_field( (string) ( $post[ '_vcap_' . $key ] ?? '' ) ) );
		}
		$decimals = array( 'amount', 'custom_min', 'custom_max', 'custom_step', 'min_spend', 'max_spend' );
		foreach ( $decimals as $key ) {
			$value = (string) ( $post[ '_vcap_' . $key ] ?? '' );
			$product->update_meta_data( '_vcap_' . $key, '' === $value ? '' : wc_format_decimal( $value ) );
		}
		$ints = array( 'per_item', 'usage_limit', 'expiry_days', 'code_length', 'delivery_delay_days', 'max_checkins', 'message_maxlength' );
		foreach ( $ints as $key ) {
			$value = (string) ( $post[ '_vcap_' . $key ] ?? '' );
			$product->update_meta_data( '_vcap_' . $key, '' === $value ? '' : absint( $value ) );
		}
		foreach ( array( 'expiry_date', 'delivery_date' ) as $key ) {
			$value = (string) ( $post[ '_vcap_' . $key ] ?? '' );
			$product->update_meta_data( '_vcap_' . $key, preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '' );
		}
		$event = (string) ( $post['_vcap_event_date'] ?? '' );
		$product->update_meta_data( '_vcap_event_date', preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $event ) ? $event : '' );
		$product->update_meta_data( '_vcap_redemption_terms', sanitize_textarea_field( (string) ( $post['_vcap_redemption_terms'] ?? '' ) ) );
		foreach ( array( 'free_shipping', 'individual_use', 'exclude_sale', 'preview', 'buyer_copy', 'transferable' ) as $key ) {
			$product->update_meta_data( '_vcap_' . $key, isset( $post[ '_vcap_' . $key ] ) ? 'yes' : 'no' );
		}
		$registered = array_keys( Channels::all() );
		$channels   = array_intersect( array_map( 'sanitize_key', (array) ( $post['_vcap_channels'] ?? array() ) ), $registered );
		// Channels of plugins that are inactive right now are not shown in the form: keep them, never drop them.
		$hidden     = array_diff( array_map( 'sanitize_key', (array) $product->get_meta( '_vcap_channels' ) ), $registered );
		$product->update_meta_data( '_vcap_channels', array_values( array_filter( array_unique( array_merge( $channels, $hidden ) ) ) ) );
		do_action( 'vcap_product_settings_save', $product, $post );
		foreach ( array( 'product_ids', 'exclude_product_ids', 'category_ids', 'exclude_category_ids' ) as $key ) {
			$product->update_meta_data( '_vcap_' . $key, array_values( array_filter( array_map( 'absint', (array) ( $post[ '_vcap_' . $key ] ?? array() ) ) ) ) );
		}
		$allowed = array( 'recipient_name', 'recipient_email', 'message', 'delivery_date' );
		$builtin = array();
		foreach ( (array) ( $post['_vcap_builtin_fields'] ?? array() ) as $value ) {
			$value = sanitize_key( $value );
			if ( in_array( preg_replace( '/_required$/', '', $value ), $allowed, true ) ) {
				$builtin[] = $value;
			}
		}
		$product->update_meta_data( '_vcap_builtin_fields', $builtin );
		// phpcs:enable
	}

	public function variation_option( $loop, $variation_data, $variation ): void {
		$value = get_post_meta( $variation->ID, '_vcap_enabled', true );
		printf(
			'<label class="tips show_if_vcap_parent" data-tip="%1$s"> %2$s <input type="checkbox" class="checkbox vcap_variation_enabled" name="vcap_variation_enabled[%3$d]" %4$s /></label>',
			esc_attr__( 'Issue vouchers for this variation.', 'voucher-captain' ),
			esc_html__( 'Voucher', 'voucher-captain' ),
			(int) $loop,
			checked( 'no' !== $value, true, false )
		);
	}

	public function variation_fields( $loop, $variation_data, $variation ): void {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}
		echo '<div class="vcap-variation-fields show_if_vcap_variation">';
		echo '<p class="form-row form-row-full"><strong>' . esc_html__( 'Voucher overrides (empty = use product settings)', 'voucher-captain' ) . '</strong></p>';
		woocommerce_wp_select(
			array(
				'id'            => "vcap_var_template_{$loop}",
				'name'          => "vcap_var[{$loop}][template]",
				'label'         => __( 'PDF template', 'voucher-captain' ),
				'value'         => $product->get_meta( '_vcap_template' ),
				'options'       => array( '' => __( 'Inherit', 'voucher-captain' ) ) + array_slice( $this->template_options(), 1, null, true ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "vcap_var_amount_{$loop}",
				'name'          => "vcap_var[{$loop}][amount]",
				'label'         => __( 'Fixed value', 'voucher-captain' ),
				'data_type'     => 'price',
				'value'         => $product->get_meta( '_vcap_amount' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "vcap_var_expiry_days_{$loop}",
				'name'          => "vcap_var[{$loop}][expiry_days]",
				'label'         => __( 'Valid for (days)', 'voucher-captain' ),
				'type'          => 'number',
				'value'         => $product->get_meta( '_vcap_expiry_days' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "vcap_var_per_item_{$loop}",
				'name'          => "vcap_var[{$loop}][per_item]",
				'label'         => __( 'PDFs per item', 'voucher-captain' ),
				'type'          => 'number',
				'value'         => $product->get_meta( '_vcap_per_item' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "vcap_var_max_checkins_{$loop}",
				'name'          => "vcap_var[{$loop}][max_checkins]",
				'label'         => __( 'Admissions per ticket', 'voucher-captain' ),
				'type'          => 'number',
				'value'         => $product->get_meta( '_vcap_max_checkins' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => "vcap_var_event_date_{$loop}",
				'name'          => "vcap_var[{$loop}][event_date]",
				'label'         => __( 'Event date & time', 'voucher-captain' ),
				'type'          => 'datetime-local',
				'value'         => $product->get_meta( '_vcap_event_date' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
		echo '</div>';
	}

	public function save_variation( \WC_Product_Variation $variation, int $loop ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WooCommerce verifies the variations nonce.
		$variation->update_meta_data( '_vcap_enabled', isset( $_POST['vcap_variation_enabled'][ $loop ] ) ? 'yes' : 'no' );
		$data = isset( $_POST['vcap_var'][ $loop ] ) ? (array) wp_unslash( $_POST['vcap_var'][ $loop ] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		// phpcs:enable
		$variation->update_meta_data( '_vcap_template', isset( $data['template'] ) && '' !== $data['template'] ? absint( $data['template'] ) : '' );
		$variation->update_meta_data( '_vcap_amount', isset( $data['amount'] ) && '' !== $data['amount'] ? wc_format_decimal( $data['amount'] ) : '' );
		foreach ( array( 'expiry_days', 'per_item', 'max_checkins' ) as $key ) {
			$variation->update_meta_data( '_vcap_' . $key, isset( $data[ $key ] ) && '' !== $data[ $key ] ? absint( $data[ $key ] ) : '' );
		}
		$event = (string) ( $data['event_date'] ?? '' );
		$variation->update_meta_data( '_vcap_event_date', preg_match( '/^\d{4}-\d{2}-\d{2}(T\d{2}:\d{2})?$/', $event ) ? $event : '' );
	}
}
