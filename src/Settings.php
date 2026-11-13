<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Global settings, rendered as a "Vouchers" tab under WooCommerce → Settings.
 */
final class Settings {
	public const DEFAULTS = array(
		'vcap_issue_statuses'        => array( 'processing', 'completed' ),
		'vcap_code_prefix'           => '',
		'vcap_code_suffix'           => '',
		'vcap_code_length'           => 12,
		'vcap_code_charset'          => 'alnum_upper',
		'vcap_code_order_ref'        => 'no',
		'vcap_code_separator'        => '-',
		'vcap_default_template'      => 0,
		'vcap_default_expiry_days'   => 365,
		'vcap_restore_on_cancel'     => 'yes',
		'vcap_void_on_refund'        => 'yes',
		'vcap_download_link_days'    => 0,
		'vcap_show_thankyou'         => 'yes',
		'vcap_show_myaccount'        => 'yes',
		'vcap_myaccount_tab'         => 'yes',
		'vcap_attach_pdf'            => 'yes',
		'vcap_include_link'          => 'yes',
		'vcap_expiry_reminder_days'  => 0,
		'vcap_date_format'           => '',
		'vcap_retention_days'        => 0,
		'vcap_delete_on_uninstall'   => 'no',
		'vcap_ticket_public_status'  => 'yes',
		'vcap_scanner_show_holder'   => 'no',
		'vcap_subscription_renewals' => 'no',
		'vcap_pdf_filename'          => 'voucher-{coupon_code}',
		'vcap_redemption_ttl'          => 15,
		'vcap_coupon_link'             => 'no',
		'vcap_discounts_on_vouchers'   => 'no',
		'vcap_store_credit'            => 'no',
		'vcap_campaigns_enabled'       => 'no',
		'vcap_vouchers_buy_vouchers'   => 'no',
		'vcap_coupon_link_param'       => 'gutschein',
		'vcap_redemption_max_failures' => 10,
		'vcap_credit_with_coupons'     => 'yes',
		'vcap_credit_apply_order'      => 'last',
		'vcap_terms_by_country'        => '',
		'vcap_expiry_by_country'       => '',
		'vcap_referral_enabled'        => 'no',
		'vcap_referral_friend_type'    => 'fixed_cart',
		'vcap_referral_friend_amount'  => 10,
		'vcap_referral_min_spend'      => 0,
		'vcap_referral_reward_amount'  => 10,
		'vcap_referral_reward_as'      => 'coupon',
		'vcap_referral_reward_days'    => 90,
	);

	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_vcap', array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_vcap', array( $this, 'save' ) );
		add_action( 'woocommerce_sections_vcap', array( $this, 'sections' ) );
	}

	public function get( string $key ) {
		$default = self::DEFAULTS[ $key ] ?? null;
		$value   = get_option( $key, $default );
		return apply_filters( 'vcap_setting_' . $key, $value );
	}

	public function yes( string $key ): bool {
		return 'yes' === $this->get( $key );
	}

	public function add_tab( array $tabs ): array {
		$tabs['vcap'] = __( 'Vouchers', 'voucher-captain' );
		return $tabs;
	}

	private function section_list(): array {
		return array(
			''         => __( 'General', 'voucher-captain' ),
			'delivery' => __( 'Delivery & downloads', 'voucher-captain' ),
			'privacy'  => __( 'Privacy & advanced', 'voucher-captain' ),
			'api'      => __( 'Integrations (API)', 'voucher-captain' ),
		);
	}

	public function sections(): void {
		global $current_section;
		$links = array();
		foreach ( $this->section_list() as $id => $label ) {
			$url     = admin_url( 'admin.php?page=wc-settings&tab=vcap&section=' . sanitize_title( $id ) );
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
		update_option( 'vcap_flush_rewrite', 'yes' ); // The My Account tab may have been toggled.
	}

	private function template_options(): array {
		$options = array( 0 => __( '— None —', 'voucher-captain' ) );
		foreach ( get_posts( array( 'post_type' => TemplatePostType::POST_TYPE, 'numberposts' => 200, 'post_status' => 'publish' ) ) as $post ) {
			$options[ $post->ID ] = $post->post_title;
		}
		return $options;
	}

	public function fields( string $section ): array {
		switch ( $section ) {
			case 'delivery':
				$fields = array(
					array( 'type' => 'title', 'title' => __( 'Delivery & downloads', 'voucher-captain' ), 'id' => 'vcap_delivery' ),
					array(
						'title'   => __( 'Attach PDF to emails', 'voucher-captain' ),
						'id'      => 'vcap_attach_pdf',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Attach the voucher PDF to voucher emails.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Download link in emails', 'voucher-captain' ),
						'id'      => 'vcap_include_link',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Include a secure download link in voucher emails.', 'voucher-captain' ),
					),
					array(
						'title'             => __( 'Download link lifetime (days)', 'voucher-captain' ),
						'id'                => 'vcap_download_link_days',
						'type'              => 'number',
						'default'           => 0,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Signed links in emails expire after this many days. Links shown to logged-in customers are always re-signed. 0 = never expire.', 'voucher-captain' ),
					),
					array(
						'title'             => __( 'Expiry reminder (days before)', 'voucher-captain' ),
						'id'                => 'vcap_expiry_reminder_days',
						'type'              => 'number',
						'default'           => 0,
						'custom_attributes' => array( 'min' => 0, 'max' => 365, 'step' => 1 ),
						'desc_tip'          => __( 'Email the voucher holder once this many days before a voucher with remaining value expires. 0 = no reminders. The email text is edited under WooCommerce → Settings → Emails.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Order confirmation', 'voucher-captain' ),
						'id'      => 'vcap_show_thankyou',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Show voucher downloads on the order received page.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'My Account', 'voucher-captain' ),
						'id'      => 'vcap_show_myaccount',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Show voucher downloads in the My Account order details.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'My Account "Vouchers" tab', 'voucher-captain' ),
						'id'      => 'vcap_myaccount_tab',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Add a "Vouchers" tab listing all purchased vouchers with balance and status.', 'voucher-captain' ),
					),
					array(
						'title'    => __( 'PDF file name', 'voucher-captain' ),
						'id'       => 'vcap_pdf_filename',
						'type'     => 'text',
						'default'  => 'voucher-{coupon_code}',
						'desc_tip' => __( 'Placeholders are allowed, e.g. {coupon_code}, {order_number}.', 'voucher-captain' ),
					),
					array(
						'title'    => __( 'Date format', 'voucher-captain' ),
						'id'       => 'vcap_date_format',
						'type'     => 'text',
						'default'  => '',
						'desc_tip' => __( 'PHP date format used in PDFs and emails. Leave empty to use the WordPress date format.', 'voucher-captain' ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_delivery' ),
				);
				break;

			case 'api':
				$fields = array(
					array(
						'type'  => 'title',
						'title' => __( 'External redemptions', 'voucher-captain' ),
						'id'    => 'vcap_api',
						'desc'  => __( 'Other plugins and apps can redeem vouchers through the PHP service vcap()->redemptions or the REST API /wp-json/vcap/v1/redemptions (see API.md).', 'voucher-captain' ),
					),
					array(
						'title'             => __( 'Reservation lifetime (minutes)', 'voucher-captain' ),
						'id'                => 'vcap_redemption_ttl',
						'type'              => 'number',
						'default'           => 15,
						'custom_attributes' => array( 'min' => 1, 'max' => 1440, 'step' => 1 ),
						'desc_tip'          => __( 'A reserved voucher that is neither completed nor cancelled within this time is released automatically.', 'voucher-captain' ),
					),
					array(
						'title'             => __( 'Invalid code attempts', 'voucher-captain' ),
						'id'                => 'vcap_redemption_max_failures',
						'type'              => 'number',
						'default'           => 10,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Number of invalid codes a user may enter within 15 minutes before further attempts are blocked. 0 = no limit.', 'voucher-captain' ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_api' ),
					array(
						'type'  => 'title',
						'title' => __( 'WP Pass Captain (tickets)', 'voucher-captain' ),
						'id'    => 'vcap_passcaptain',
						'desc'  => Compat\PassCaptain::status_html(),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_passcaptain' ),
				);
				break;

			case 'privacy':
				$fields = array(
					array( 'type' => 'title', 'title' => __( 'Privacy & advanced', 'voucher-captain' ), 'id' => 'vcap_privacy' ),
					array(
						'title'             => __( 'Anonymise recipient data after (days)', 'voucher-captain' ),
						'id'                => 'vcap_retention_days',
						'type'              => 'number',
						'default'           => 0,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Recipient name, email, message and custom fields are erased this many days after a voucher has expired or been fully redeemed. 0 = keep.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Public ticket status', 'voucher-captain' ),
						'id'      => 'vcap_ticket_public_status',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'When a ticket QR code is opened by someone without scanning rights, show whether the ticket is valid (no personal data).', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Holder name in the scanner', 'voucher-captain' ),
						'id'      => 'vcap_scanner_show_holder',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Show the name of the buyer or recipient when a code is scanned. Personal tickets always show the name so it can be checked. Order number and original value are only shown to staff who manage vouchers.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Subscription renewals', 'voucher-captain' ),
						'id'      => 'vcap_subscription_renewals',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Issue new vouchers for WooCommerce Subscriptions renewal orders.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Delete data on uninstall', 'voucher-captain' ),
						'id'      => 'vcap_delete_on_uninstall',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Remove voucher tables, templates and settings when the plugin is deleted. WooCommerce coupons are kept.', 'voucher-captain' ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_privacy' ),
				);
				break;

			default:
				$statuses = array();
				foreach ( wc_get_order_statuses() as $key => $label ) {
					$statuses[ substr( $key, 3 ) ] = $label;
				}
				$fields = array(
					array( 'type' => 'title', 'title' => __( 'Voucher issuing', 'voucher-captain' ), 'id' => 'vcap_general' ),
					array(
						'title'   => __( 'Generate vouchers when order is', 'voucher-captain' ),
						'id'      => 'vcap_issue_statuses',
						'type'    => 'multiselect',
						'class'   => 'wc-enhanced-select',
						'options' => $statuses,
						'default' => array( 'processing', 'completed' ),
						'desc'    => __( 'Vouchers are created once per purchased item the first time an order reaches one of these statuses. Leave empty to issue manually only.', 'voucher-captain' ),
					),
					array(
						'title'    => __( 'Default redemption conditions', 'voucher-captain' ),
						'id'       => 'vcap_default_terms',
						'type'     => 'textarea',
						'default'  => '',
						'css'      => 'min-width:400px;height:80px;',
						'desc_tip' => __( 'Used when a product has no conditions of its own, e.g. "Not exchangeable for cash. Residual value remains on the voucher."', 'voucher-captain' ),
					),
					array(
						'title'    => __( 'Redemption conditions per country', 'voucher-captain' ),
						'id'       => 'vcap_terms_by_country',
						'type'     => 'textarea',
						'default'  => '',
						'css'      => 'min-width:400px;height:120px;',
						'desc'     => __( 'Optional, for shops selling to several countries. One block per country, starting with the two-letter country code and a colon, e.g. "AT: Valid for 30 years." The billing country of the order decides; products with their own conditions and countries without a block use the conditions above. Have the texts checked legally for each country.', 'voucher-captain' ),
					),
					array(
						'title'    => __( 'Statutory validity per country', 'voucher-captain' ),
						'id'       => Markets::EXPIRY_OPTION,
						'type'     => 'textarea',
						'default'  => '',
						'css'      => 'min-width:400px;height:80px;',
						'desc'     => __( 'Optional. One line per country: two-letter code, colon, years, and "end" for "to the end of the year" (0 = never expires), e.g. "DE: 3 end" or "AT: 30". Used for vouchers with the statutory validity; overrides the verified defaults (Germany 3 years to the end of the year, Austria 30 years). Countries without a rule use 3 years to the end of the year. Have the rules checked legally for each country.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Default template', 'voucher-captain' ),
						'id'      => 'vcap_default_template',
						'type'    => 'select',
						'options' => $this->template_options(),
						'default' => 0,
					),
					array(
						'title'    => __( 'Default validity', 'voucher-captain' ),
						'id'       => 'vcap_default_expiry_rule',
						'type'     => 'select',
						'options'  => array(
							'statutory' => __( 'Statutory default for the shop country (Germany: 3 years to the end of the year, Austria: 30 years)', 'voucher-captain' ),
							'days'      => __( 'Fixed number of days (below)', 'voucher-captain' ),
						),
						'default'  => ProductConfig::default_expiry_rule(),
						'desc_tip' => __( 'Used when a product does not define its own expiry. Shorter validity periods can be invalid towards consumers; have them checked legally.', 'voucher-captain' ),
					),
					array(
						'title'             => __( 'Default validity (days)', 'voucher-captain' ),
						'id'                => 'vcap_default_expiry_days',
						'type'              => 'number',
						'default'           => 365,
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
						'desc_tip'          => __( 'Used with "Fixed number of days" when a product does not define its own expiry. 0 = never expires.', 'voucher-captain' ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_general' ),

					array( 'type' => 'title', 'title' => __( 'Coupon codes', 'voucher-captain' ), 'id' => 'vcap_codes' ),
					array(
						'title'   => __( 'Prefix', 'voucher-captain' ),
						'id'      => 'vcap_code_prefix',
						'type'    => 'text',
						'default' => '',
					),
					array(
						'title'   => __( 'Suffix', 'voucher-captain' ),
						'id'      => 'vcap_code_suffix',
						'type'    => 'text',
						'default' => '',
					),
					array(
						'title'             => __( 'Random part length', 'voucher-captain' ),
						'id'                => 'vcap_code_length',
						'type'              => 'number',
						'default'           => 12,
						'desc'              => __( 'Voucher codes are made at least as long as needed to be safe from guessing (about 2^50 combinations): 10 letters and digits, 11 letters or 16 digits.', 'voucher-captain' ),
						'custom_attributes' => array( 'min' => 8, 'max' => 40, 'step' => 1 ),
					),
					array(
						'title'   => __( 'Characters', 'voucher-captain' ),
						'id'      => 'vcap_code_charset',
						'type'    => 'select',
						'default' => 'alnum_upper',
						'options' => array(
							'alnum_upper' => __( 'Letters and digits, uppercase (no ambiguous characters)', 'voucher-captain' ),
							'alpha_upper' => __( 'Letters only, uppercase', 'voucher-captain' ),
							'numeric'     => __( 'Digits only', 'voucher-captain' ),
							'alnum'       => __( 'Letters and digits, mixed case', 'voucher-captain' ),
						),
					),
					array(
						'title'   => __( 'Separator', 'voucher-captain' ),
						'id'      => 'vcap_code_separator',
						'type'    => 'text',
						'default' => '-',
						'css'     => 'width:60px',
					),
					array(
						'title'   => __( 'Include order number', 'voucher-captain' ),
						'id'      => 'vcap_code_order_ref',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Add the order number to the code, e.g. GIFT-1042-7KQ2M9XA.', 'voucher-captain' ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_codes' ),

					array( 'type' => 'title', 'title' => __( 'Redemption', 'voucher-captain' ), 'id' => 'vcap_redeem' ),
					array(
						'title'    => __( 'Redeem page', 'voucher-captain' ),
						'id'       => 'vcap_redeem_page_id',
						'type'     => 'single_select_page',
						'default'  => '',
						'class'    => 'wc-enhanced-select-nostd',
						'css'      => 'min-width:300px;',
						'desc_tip' => true,
						'desc'     => __( 'Page with the "Redeem voucher" block or [vcap_redeem]. Emails, PDFs ({redeem_url}) and the customer account link there, with the code filled in. Ticket vouchers are exchanged for tickets on this page.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Restore balance on cancellation', 'voucher-captain' ),
						'id'      => 'vcap_restore_on_cancel',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Give the redeemed amount back when an order that used a voucher is cancelled, refunded or failed.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Multi-purpose vouchers as payment', 'voucher-captain' ),
						'id'      => PaymentVouchers::OPTION,
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Settle multi-purpose vouchers (gift cards, value vouchers) as a payment after tax: the order keeps the full VAT and the voucher pays part of the total as a separate line. Off: the voucher is a coupon and lowers price and VAT (previous behaviour). Have the switch confirmed by your tax adviser.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Void vouchers on refund', 'voucher-captain' ),
						'id'      => 'vcap_void_on_refund',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Disable vouchers when the order they were bought with is fully refunded or cancelled.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Automatic promotions', 'voucher-captain' ),
						'id'      => 'vcap_campaigns_enabled',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Apply published promotions (Vouchers → Promotions) automatically in the cart. The discount is shown as its own line in the cart and the order; catalog prices do not change.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Store credit', 'voucher-captain' ),
						'id'      => 'vcap_store_credit',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Enable store credit accounts: credit customers manually (user profile) or refund orders as store credit (order screen); customers see and use their credit in My Account and the cart.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Store credit with discount codes', 'voucher-captain' ),
						'id'      => 'vcap_credit_with_coupons',
						'type'    => 'checkbox',
						'default' => 'yes',
						'desc'    => __( 'Allow store credit in the same order as discount codes. Discounts are always calculated first; gift cards and store credit then pay the remaining amount.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Order of gift cards and store credit', 'voucher-captain' ),
						'id'      => 'vcap_credit_apply_order',
						'type'    => 'select',
						'default' => 'last',
						'options' => array(
							'last'  => __( 'Gift cards first, then store credit', 'voucher-captain' ),
							'first' => __( 'Store credit first, then gift cards', 'voucher-captain' ),
						),
					),
					array(
						'title'   => __( 'Discount codes on vouchers', 'voucher-captain' ),
						'id'      => 'vcap_discounts_on_vouchers',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Allow discount codes to reduce the price of voucher products. Vouchers keep their full face value, so a 10 % code on a 50 € voucher costs the shop 5 €.', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Pay vouchers with vouchers', 'voucher-captain' ),
						'id'      => 'vcap_vouchers_buy_vouchers',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Allow vouchers and gift cards to pay for voucher products (off by default to prevent exchanging or extending vouchers).', 'voucher-captain' ),
					),
					array(
						'title'   => __( 'Voucher links', 'voucher-captain' ),
						'id'      => 'vcap_coupon_link',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Apply a coupon automatically when a customer opens a link such as https://your-shop/?gutschein=CODE (any WooCommerce coupon). The code is applied once, validated by WooCommerce, and only when the cart has products.', 'voucher-captain' ),
					),
					array(
						'title'    => __( 'Link parameter', 'voucher-captain' ),
						'id'       => 'vcap_coupon_link_param',
						'type'     => 'text',
						'default'  => 'gutschein',
						'css'      => 'width:160px',
						'desc_tip' => __( 'Name of the URL parameter (letters, digits, - and _).', 'voucher-captain' ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_redeem' ),

					array(
						'type'  => 'title',
						'title' => __( 'Refer a friend', 'voucher-captain' ),
						'desc'  => __( 'Customers find their personal code in My Account → Vouchers. Friends use it on their first order; the referring customer is rewarded once that order is completed. Own codes cannot be used.', 'voucher-captain' ),
						'id'    => 'vcap_referral',
					),
					array(
						'title'   => __( 'Refer a friend', 'voucher-captain' ),
						'id'      => 'vcap_referral_enabled',
						'type'    => 'checkbox',
						'default' => 'no',
						'desc'    => __( 'Enable referral codes', 'voucher-captain' ),
					),
					array(
						'title'             => __( 'Discount for the friend', 'voucher-captain' ),
						'id'                => 'vcap_referral_friend_amount',
						'type'              => 'number',
						'default'           => 10,
						'css'               => 'width:90px',
						'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
					),
					array(
						'title'   => __( 'Discount type', 'voucher-captain' ),
						'id'      => 'vcap_referral_friend_type',
						'type'    => 'select',
						'default' => 'fixed_cart',
						'options' => array(
							'fixed_cart' => __( 'Fixed amount', 'voucher-captain' ),
							'percent'    => __( 'Percentage', 'voucher-captain' ),
						),
					),
					array(
						'title'             => __( 'Minimum order value for the friend', 'voucher-captain' ),
						'id'                => 'vcap_referral_min_spend',
						'type'              => 'number',
						'default'           => 0,
						'css'               => 'width:90px',
						'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
					),
					array(
						'title'             => __( 'Reward for the referrer', 'voucher-captain' ),
						'id'                => 'vcap_referral_reward_amount',
						'type'              => 'number',
						'default'           => 10,
						'css'               => 'width:90px',
						'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
					),
					array(
						'title'   => __( 'Reward as', 'voucher-captain' ),
						'id'      => 'vcap_referral_reward_as',
						'type'    => 'select',
						'default' => 'coupon',
						'options' => array(
							'coupon' => __( 'Coupon by email', 'voucher-captain' ),
							'credit' => __( 'Store credit (requires store credit)', 'voucher-captain' ),
						),
					),
					array(
						'title'             => __( 'Reward coupon valid for (days)', 'voucher-captain' ),
						'id'                => 'vcap_referral_reward_days',
						'type'              => 'number',
						'default'           => 90,
						'css'               => 'width:90px',
						'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
					),
					array( 'type' => 'sectionend', 'id' => 'vcap_referral' ),
				);
		}
		return apply_filters( 'vcap_settings_fields', $fields, $section );
	}

	public function date_format(): string {
		$format = (string) $this->get( 'vcap_date_format' );
		return '' !== $format ? $format : (string) get_option( 'date_format', 'Y-m-d' );
	}

	public function secret(): string {
		$secret = (string) get_option( 'vcap_secret' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( 'vcap_secret', $secret, false );
		}
		return $secret;
	}
}
