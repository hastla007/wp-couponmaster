<?php
namespace WCVS;

defined( 'ABSPATH' ) || exit;

/**
 * Global settings, rendered as a "Vouchers" tab under WooCommerce → Settings.
 */
final class Settings {
	public const DEFAULTS = array(
		'wcvs_issue_statuses'        => array( 'processing', 'completed' ),
		'wcvs_code_prefix'           => '',
		'wcvs_code_suffix'           => '',
		'wcvs_code_length'           => 10,
		'wcvs_code_charset'          => 'alnum_upper',
		'wcvs_code_order_ref'        => 'no',
		'wcvs_code_separator'        => '-',
		'wcvs_default_template'      => 0,
		'wcvs_default_expiry_days'   => 365,
		'wcvs_balance_include_tax'   => 'yes',
		'wcvs_restore_on_cancel'     => 'yes',
		'wcvs_void_on_refund'        => 'yes',
		'wcvs_download_link_days'    => 0,
		'wcvs_show_thankyou'         => 'yes',
		'wcvs_show_myaccount'        => 'yes',
		'wcvs_myaccount_tab'         => 'yes',
		'wcvs_attach_pdf'            => 'yes',
		'wcvs_include_link'          => 'yes',
		'wcvs_date_format'           => '',
		'wcvs_retention_days'        => 0,
		'wcvs_delete_on_uninstall'   => 'no',
		'wcvs_ticket_public_status'  => 'yes',
		'wcvs_subscription_renewals' => 'no',
		'wcvs_pdf_filename'          => 'voucher-{coupon_code}',
	);

	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_wcvs', array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_wcvs', array( $this, 'save' ) );
		add_action( 'woocommerce_sections_wcvs', array( $this, 'sections' ) );
	}

	public function get( string $key ) {
		$default = self::DEFAULTS[ $key ] ?? null;
		$value   = get_option( $key, $default );
		return apply_filters( 'wcvs_setting_' . $key, $value );
	}

	public function yes( string $key ): bool {
		return 'yes' === $this->get( $key );
	}

	public function add_tab( array $tabs ): array {
		$tabs['wcvs'] = __( 'Vouchers', 'wc-voucher-studio' );
		return $tabs;
	}

	private function section_list(): array {
		return array(
			''         => __( 'General', 'wc-voucher-studio' ),
			'delivery' => __( 'Delivery & downloads', 'wc-voucher-studio' ),
			'privacy'  => __( 'Privacy & advanced', 'wc-voucher-studio' ),
		);
	}

	public function sections(): void {
		global $current_section;
		$links = array();
		foreach ( $this->section_list() as $id => $label ) {
			$url     = admin_url( 'admin.php?page=wc-settings&tab=wcvs&section=' . sanitize_title( $id ) );
			$links[] = '<li><a href="' . esc_url( $url ) . '" class="' . ( $current_section === $id ? 'current' : '' ) . '">' . esc_html( $label ) . '</a></li>';
		}
		echo '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	public function output(): void {
		global $current_section;
		\WC_Admin_Settings::output_fields( $this->fields( (string) $current_section ) );
	}

	public function save(): void {
		global $current_section;
		\WC_Admin_Settings::save_fields( $this->fields( (string) $current_section ) );
	}

	private function template_options(): array {
		$options = array( 0 => __( '— None —', 'wc-voucher-studio' ) );
		foreach ( get_posts( array( 'post_type' => TemplatePostType::POST_TYPE, 'numberposts' => 200, 'post_status' => 'publish' ) ) as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}
		return $options;
	}

	public function fields( string $section ): array {
		switch ( $section ) {
			case 'delivery':
				$fields = array(
					array( 'type' => 'title', 'title' => __( 'Delivery & downloads', 'wc-voucher-studio' ), 'id' => 'wcvs_delivery' ),
					array(
						'title'   => __( 'Attach PDF to emails', 'wc-voucher-studio' ),
						'id'      => 'wcvs_attach_pdf',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Attach the voucher PDF to voucher emails.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Download link in emails', 'wc-voucher-studio' ),
						'id'      => 'wcvs_include_link',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Include a secure download link in voucher emails.', 'wc-voucher-studio' ),
					),
					array(
						'title'             => __( 'Download link lifetime (days)', 'wc-voucher-studio' ),
						'id'                => 'wcvs_download_link_days',
						'type'              => 'number',
						'default'           => 0,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Signed links in emails expire after this many days. Links shown to logged-in customers are always re-signed. 0 = never expire.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Order confirmation', 'wc-voucher-studio' ),
						'id'      => 'wcvs_show_thankyou',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Show voucher downloads on the order received page.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'My Account', 'wc-voucher-studio' ),
						'id'      => 'wcvs_show_myaccount',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Show voucher downloads in the My Account order details.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'My Account "Vouchers" tab', 'wc-voucher-studio' ),
						'id'      => 'wcvs_myaccount_tab',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Add a "Vouchers" tab listing all purchased vouchers with balance and status.', 'wc-voucher-studio' ),
					),
					array(
						'title'    => __( 'PDF file name', 'wc-voucher-studio' ),
						'id'       => 'wcvs_pdf_filename',
						'type'     => 'text',
						'default'  => 'voucher-{coupon_code}',
						'desc_tip' => __( 'Placeholders are allowed, e.g. {coupon_code}, {order_number}.', 'wc-voucher-studio' ),
					),
					array(
						'title'    => __( 'Date format', 'wc-voucher-studio' ),
						'id'       => 'wcvs_date_format',
						'type'     => 'text',
						'default'  => '',
						'desc_tip' => __( 'PHP date format used in PDFs and emails. Leave empty to use the WordPress date format.', 'wc-voucher-studio' ),
					),
					array( 'type' => 'sectionend', 'id' => 'wcvs_delivery' ),
				);
				break;

			case 'privacy':
				$fields = array(
					array( 'type' => 'title', 'title' => __( 'Privacy & advanced', 'wc-voucher-studio' ), 'id' => 'wcvs_privacy' ),
					array(
						'title'             => __( 'Anonymise recipient data after (days)', 'wc-voucher-studio' ),
						'id'                => 'wcvs_retention_days',
						'type'              => 'number',
						'default'           => 0,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Recipient name, email, message and custom fields are erased this many days after a voucher has expired or been fully redeemed. 0 = keep.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Public ticket status', 'wc-voucher-studio' ),
						'id'      => 'wcvs_ticket_public_status',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'When a ticket QR code is opened by someone without scanning rights, show whether the ticket is valid (no personal data).', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Subscription renewals', 'wc-voucher-studio' ),
						'id'      => 'wcvs_subscription_renewals',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Issue new vouchers for WooCommerce Subscriptions renewal orders.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Delete data on uninstall', 'wc-voucher-studio' ),
						'id'      => 'wcvs_delete_on_uninstall',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Remove voucher tables, templates and settings when the plugin is deleted. WooCommerce coupons are kept.', 'wc-voucher-studio' ),
					),
					array( 'type' => 'sectionend', 'id' => 'wcvs_privacy' ),
				);
				break;

			default:
				$statuses = array();
				foreach ( wc_get_order_statuses() as $key => $label ) {
					$statuses[ substr( $key, 3 ) ] = $label;
				}
				$fields = array(
					array( 'type' => 'title', 'title' => __( 'Voucher issuing', 'wc-voucher-studio' ), 'id' => 'wcvs_general' ),
					array(
						'title'   => __( 'Generate vouchers when order is', 'wc-voucher-studio' ),
						'id'      => 'wcvs_issue_statuses',
						'type'    => 'multiselect',
						'class'   => 'wc-enhanced-select',
						'options' => $statuses,
						'default' => array( 'processing', 'completed' ),
						'desc'    => __( 'Vouchers are created once per purchased item the first time an order reaches one of these statuses. Leave empty to issue manually only.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Default template', 'wc-voucher-studio' ),
						'id'      => 'wcvs_default_template',
						'type'    => 'select',
						'options' => $this->template_options(),
						'default' => 0,
					),
					array(
						'title'             => __( 'Default validity (days)', 'wc-voucher-studio' ),
						'id'                => 'wcvs_default_expiry_days',
						'type'              => 'number',
						'default'           => 365,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Used when a product does not define its own expiry. 0 = never expires.', 'wc-voucher-studio' ),
					),
					array( 'type' => 'sectionend', 'id' => 'wcvs_general' ),

					array( 'type' => 'title', 'title' => __( 'Coupon codes', 'wc-voucher-studio' ), 'id' => 'wcvs_codes' ),
					array(
						'title'   => __( 'Prefix', 'wc-voucher-studio' ),
						'id'      => 'wcvs_code_prefix',
						'type'    => 'text',
						'default' => '',
					),
					array(
						'title'   => __( 'Suffix', 'wc-voucher-studio' ),
						'id'      => 'wcvs_code_suffix',
						'type'    => 'text',
						'default' => '',
					),
					array(
						'title'             => __( 'Random part length', 'wc-voucher-studio' ),
						'id'                => 'wcvs_code_length',
						'type'              => 'number',
						'default'           => 10,
						'custom_attributes' => array( 'min' => 4, 'max' => 40, 'step' => 1 ),
					),
					array(
						'title'   => __( 'Characters', 'wc-voucher-studio' ),
						'id'      => 'wcvs_code_charset',
						'type'    => 'select',
						'default' => 'alnum_upper',
						'options' => array(
							'alnum_upper' => __( 'Letters and digits, uppercase (no ambiguous characters)', 'wc-voucher-studio' ),
							'alpha_upper' => __( 'Letters only, uppercase', 'wc-voucher-studio' ),
							'numeric'     => __( 'Digits only', 'wc-voucher-studio' ),
							'alnum'       => __( 'Letters and digits, mixed case', 'wc-voucher-studio' ),
						),
					),
					array(
						'title'   => __( 'Separator', 'wc-voucher-studio' ),
						'id'      => 'wcvs_code_separator',
						'type'    => 'text',
						'default' => '-',
						'css'     => 'width:60px',
					),
					array(
						'title'   => __( 'Include order number', 'wc-voucher-studio' ),
						'id'      => 'wcvs_code_order_ref',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Add the order number to the code, e.g. GIFT-1042-7KQ2M9XA.', 'wc-voucher-studio' ),
					),
					array( 'type' => 'sectionend', 'id' => 'wcvs_codes' ),

					array( 'type' => 'title', 'title' => __( 'Redemption', 'wc-voucher-studio' ), 'id' => 'wcvs_redeem' ),
					array(
						'title'   => __( 'Balance includes tax', 'wc-voucher-studio' ),
						'id'      => 'wcvs_balance_include_tax',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Deduct the discount including tax from multi-use voucher balances (recommended for gift cards).', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Restore balance on cancellation', 'wc-voucher-studio' ),
						'id'      => 'wcvs_restore_on_cancel',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Give the redeemed amount back when an order that used a voucher is cancelled, refunded or failed.', 'wc-voucher-studio' ),
					),
					array(
						'title'   => __( 'Void vouchers on refund', 'wc-voucher-studio' ),
						'id'      => 'wcvs_void_on_refund',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Disable vouchers when the order they were bought with is fully refunded or cancelled.', 'wc-voucher-studio' ),
					),
					array( 'type' => 'sectionend', 'id' => 'wcvs_redeem' ),
				);
		}
		return apply_filters( 'wcvs_settings_fields', $fields, $section );
	}

	public function date_format(): string {
		$format = (string) $this->get( 'wcvs_date_format' );
		return '' !== $format ? $format : (string) get_option( 'date_format', 'Y-m-d' );
	}

	public function secret(): string {
		$secret = (string) get_option( 'wcvs_secret' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'wcvs_secret', $secret, false );
		}
		return $secret;
	}
}
