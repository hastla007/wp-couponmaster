<?php
namespace VCAP;

defined( 'ABSPATH' ) || exit;

/**
 * Global settings, rendered as a "Vouchers" tab under WooCommerce → Settings.
 */
final class Settings {
	/**
	 * Defaults of the settings (option name => value) from the settings register (config/settings.php, SET-VC-01): what
	 * get() returns while an option was never saved. Replaces the former constant Settings::DEFAULTS (same values).
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return SettingsRegistry::defaults();
	}

	/**
	 * A setting outside of an instance (static helpers such as ProductConfig): the same value Settings::get()
	 * returns – the stored option, else the default, through the filter "vcap_setting_{key}".
	 *
	 * @return mixed
	 */
	public static function value( string $key ) {
		return apply_filters( 'vcap_setting_' . $key, get_option( $key, SettingsRegistry::default( $key ) ) );
	}

	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_vcap', array( $this, 'output' ) );
		add_action( 'woocommerce_settings_save_vcap', array( $this, 'save' ) );
		add_action( 'woocommerce_sections_vcap', array( $this, 'sections' ) );
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Logger::VERBOSE_OPTION, array( Logger::class, 'sanitize_verbose' ) );
		foreach ( array( 'vcap_code_prefix', 'vcap_code_suffix', 'vcap_code_separator' ) as $affix ) {
			add_filter( 'woocommerce_admin_settings_sanitize_option_' . $affix, array( $this, 'sanitize_affix' ), 10, 2 );
		}
		add_filter( 'woocommerce_admin_settings_sanitize_option_vcap_terms_by_country', array( $this, 'report_country_lines' ), 10, 2 );
		// M12-84: "30, 7, 1" is stored normalised (largest first), anything else as 0 (no reminders).
		add_filter(
			'woocommerce_admin_settings_sanitize_option_vcap_expiry_reminder_days',
			static function ( $value ) {
				$days = Delivery::expiry_reminder_offsets( $value );
				return $days ? implode( ', ', $days ) : '0';
			}
		);
		add_filter( 'woocommerce_admin_settings_sanitize_option_' . Markets::EXPIRY_OPTION, array( $this, 'report_country_lines' ), 10, 2 );
	}

	/**
	 * Q12: the country settings are saved as entered, but lines that are not used are reported – text before the
	 * first country block of the conditions, validity lines that are not "CC: years [end]".
	 *
	 * @param mixed $value
	 * @param array $option
	 * @return mixed
	 */
	public function report_country_lines( $value, $option = array() ) {
		$id     = (string) ( $option['id'] ?? '' );
		$lines  = Markets::EXPIRY_OPTION === $id ? Markets::unparsed_expiry_lines( (string) $value ) : ProductConfig::unassigned_terms_lines( (string) $value );
		if ( $lines && class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: setting name, 2: lines that are not used */
					__( '%1$s: these lines are not used because they do not start with a country code (e.g. "AT:") or are not in the expected format: %2$s', 'voucher-captain' ),
					(string) ( $option['title'] ?? $id ),
					implode( ' | ', array_slice( $lines, 0, 5 ) )
				)
			);
		}
		return $value;
	}

	/**
	 * Prefix, suffix and separator of generated codes: letters, digits, ".", "_" and "-" only (prefix/suffix up
	 * to 20, separator up to 3 characters). Anything else ("B&B", spaces, umlauts) is changed by WooCommerce's
	 * code formatting, so the code would never be found again; the previous value is kept and an error shown.
	 *
	 * @param mixed $value
	 * @param array $option
	 */
	public function sanitize_affix( $value, $option = array() ) {
		$id    = (string) ( $option['id'] ?? '' );
		$value = trim( (string) $value );
		if ( CodeGenerator::valid_affix( $value, 'vcap_code_separator' === $id ) ) {
			return $value;
		}
		if ( class_exists( '\WC_Admin_Settings' ) ) {
			\WC_Admin_Settings::add_error(
				sprintf(
					/* translators: 1: setting name, 2: value entered */
					__( '%1$s "%2$s" was not saved: use only letters, digits, dot, hyphen and underscore (up to 20 characters, separator up to 3).', 'voucher-captain' ),
					(string) ( $option['title'] ?? $id ),
					$value
				)
			);
		}
		return $this->get( $id );
	}

	/**
	 * AP-P1-7: settings read on (almost) every storefront request (cart, checkout, account, coupon links). Shops that
	 * never saved them had one options query per request for each (notoptions); their defaults are stored as
	 * autoloaded options on installation and upgrade instead (store_request_defaults()).
	 */
	public const REQUEST_SETTINGS = array(
		'vcap_coupon_link',
		'vcap_coupon_link_param',
		'vcap_referral_enabled',
		'vcap_store_credit',
		'vcap_credit_with_coupons',
		'vcap_credit_apply_order',
		'vcap_campaigns_enabled',
		'vcap_discounts_on_vouchers',
		'vcap_vouchers_buy_vouchers',
		'vcap_customer_daily_limit',
		'vcap_show_myaccount',
		'vcap_myaccount_tab',
		'vcap_show_thankyou',
	);

	/**
	 * Stores the defaults of REQUEST_SETTINGS as autoloaded options where none is saved yet; a value an admin saved
	 * (also an empty one or 0) is never changed (add_option()).
	 *
	 * @return string[] settings stored now
	 */
	public static function store_request_defaults(): array {
		$stored = array();
		foreach ( self::REQUEST_SETTINGS as $key ) {
			if ( SettingsRegistry::has( $key ) && false !== add_option( $key, SettingsRegistry::default( $key ), '', 'yes' ) ) {
				$stored[] = $key;
			}
		}
		return $stored;
	}

	public function get( string $key ) {
		return apply_filters( 'vcap_setting_' . $key, get_option( $key, SettingsRegistry::default( $key ) ) );
	}

	public function yes( string $key ): bool {
		return 'yes' === $this->get( $key );
	}

	/** @param mixed $tabs What the previous filter returned (AP-Q2-6: a non-array is normalised). */
	public function add_tab( $tabs ): array {
		$tabs = is_array( $tabs ) ? $tabs : array();
		$tabs['vcap'] = __( 'Vouchers', 'voucher-captain' );
		return $tabs;
	}

	/**
	 * Sections of the "Vouchers" settings tab (id => label): the core's own, then those of add-ons (A4, filter
	 * "vcap_settings_sections"). Add-ons cannot rename or remove a core section.
	 *
	 * @return array<string, string>
	 */
	public function section_list(): array {
		$core = array(
			''           => __( 'General', 'voucher-captain' ),
			// M12-90: the former "General" page split up (the option keys are unchanged).
			'redemption' => __( 'Redemption & balance', 'voucher-captain' ),
			'promotions' => __( 'Promotions & refer a friend', 'voucher-captain' ),
			'codes'      => __( 'Codes', 'voucher-captain' ),
			'delivery'   => __( 'Delivery & downloads', 'voucher-captain' ),
			'privacy'  => __( 'Privacy & advanced', 'voucher-captain' ),
			'api'      => __( 'Integrations (API)', 'voucher-captain' ),
		);
		/**
		 * Extra sections of the "Vouchers" settings tab (arg: array of section id => label). Add-ons add their own
		 * section here and its fields with "vcap_settings_fields" (second argument: the section id); WooCommerce
		 * saves them. Core sections cannot be renamed or removed.
		 */
		$extra = apply_filters( 'vcap_settings_sections', array() );
		foreach ( is_array( $extra ) ? $extra : array() as $id => $label ) {
			$id    = sanitize_title( (string) $id );
			$label = trim( wp_strip_all_tags( (string) $label ) );
			if ( '' !== $id && '' !== $label && ! array_key_exists( $id, $core ) ) {
				$core[ $id ] = $label;
			}
		}
		return $core;
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
		SettingsRegistry::hook_number_checks(); // SET-VC-01: every number is checked on the server, too.
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
				$fields = $this->delivery_fields();
				break;
			case 'api':
				$fields = $this->api_fields();
				break;
			case 'privacy':
				$fields = $this->privacy_fields();
				break;
			case 'redemption':
			case 'promotions':
			case 'codes':
				$fields = self::general_part( $this->general_fields(), $section );
				break;
			default:
				// A4: a section of an add-on starts empty (its fields come from "vcap_settings_fields"); unknown
				// sections show "General" as before.
				$fields = '' !== $section && array_key_exists( $section, $this->section_list() ) ? array() : self::general_part( $this->general_fields(), '' );
		}
		/**
		 * Fields of a section of the "Vouchers" settings tab (args: WooCommerce settings fields, section id – '' is
		 * "General"). Add-ons add fields to a core section or fill their own section from "vcap_settings_sections".
		 */
		$fields = apply_filters( 'vcap_settings_fields', $fields, $section );
		// SET-VC-07: hint lines for add-on functions after the add-ons' own fields (a field with 'replaces' removes
		// its line); text only, see docs/FREE-PREMIUM.md rule 3.
		return Admin\ProHints::append( is_array( $fields ) ? $fields : array(), $section );
	}

	/** Section "Delivery & downloads" (and bundled delivery). */
	private function delivery_fields(): array {
		$fields = array(
			array( 'type' => 'title', 'title' => __( 'Delivery & downloads', 'voucher-captain' ), 'id' => 'vcap_delivery' ),
			SettingsRegistry::field(
				'vcap_attach_pdf',
				array(
					'title' => __( 'Attach PDF to emails', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Attach the voucher PDF to voucher emails.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_gift_toggle',
				array(
					'title' => __( '"Gift / for me" choice', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'On product pages with recipient fields, let the customer choose "As a gift" or "For me"; "For me" hides the recipient fields and sends the voucher to the buyer.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_new_product_delivery',
				array(
					'title' => __( 'Delivery date for new products', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'New voucher products offer the "Delivery date" field by default (existing products keep their fields).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_include_link',
				array(
					'title' => __( 'Download link in emails', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Include a secure download link in voucher emails.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_download_link_days',
				array(
					'title'             => __( 'Download link lifetime (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Signed links in emails expire after this many days (default 90). Customers get a fresh link in My Account at any time; reissuing a voucher invalidates its old links. 0 = never expire (not recommended).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_delivery_hour',
				array(
					'title'             => __( 'Delivery hour', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Hour of the day (shop time, 0–23) at which vouchers with a delivery date are sent (default 8).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_expiry_reminder_days',
				array(
					'title'    => __( 'Expiry reminders (days before)', 'voucher-captain' ),
					'type'     => 'text',
					'default'  => '0',
					'desc_tip' => __( 'Email the voucher holder this many days before a voucher with remaining value expires. Several reminders are separated by commas, e.g. "30, 7, 1"; each one is sent once. 0 = no reminders. The email text is edited under WooCommerce → Settings → Emails.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_show_thankyou',
				array(
					'title' => __( 'Order confirmation', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Show voucher downloads on the order received page.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_show_myaccount',
				array(
					'title' => __( 'My Account', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Show voucher downloads in the My Account order details.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_myaccount_tab',
				array(
					'title' => __( 'My Account "Vouchers" tab', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Add a "Vouchers" tab listing all purchased vouchers with balance and status.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_pdf_filename',
				array(
					'title'    => __( 'PDF file name', 'voucher-captain' ),
					'type'     => 'text',
					'desc_tip' => __( 'Placeholders are allowed, e.g. {coupon_code}, {order_number}.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_date_format',
				array(
					'title'    => __( 'Date format', 'voucher-captain' ),
					'type'     => 'text',
					'desc_tip' => __( 'PHP date format used in PDFs and emails. Leave empty to use the WordPress date format.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_delivery' ),
			array(
				'type'  => 'title',
				'title' => __( 'Bulk orders', 'voucher-captain' ),
				'id'    => 'vcap_bundle',
				'desc'  => __( 'Many vouchers of one order line for the same recipient are sent in one email with a list of all codes instead of one email per voucher; the buyer gets one copy per order. Vouchers with different delivery dates are sent separately. The email text is edited under WooCommerce → Settings → Emails → "Vouchers (bulk order)"; switching that email off sends every voucher on its own again.', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				'vcap_bundle_threshold',
				array(
					'title'             => __( 'One email from (vouchers)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'From this many vouchers of the same order line and recipient, due at the same time, they are sent together in one email (default 5). 0 = always one email per voucher.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_bundle_pdf_limit',
				array(
					'title'             => __( 'One PDF up to (vouchers)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					/* translators: 1: value a new installation stores, 2: value of shops that never saved the setting */
					'desc_tip'          => sprintf( __( 'A bundled email with up to this many vouchers carries one PDF with a page per voucher. New installations start with %1$d; shops installed before that and never saved this field use %2$d. Larger bundles carry no attachment but a download link per voucher and the link to My Account. 0 = never attach a PDF to a bundled email.', 'voucher-captain' ), (int) SettingsRegistry::fresh_default( 'vcap_bundle_pdf_limit' ), (int) SettingsRegistry::default( 'vcap_bundle_pdf_limit' ) ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_bundle' ),
			// SET-VC-18 (1.12 no. 16–18): what customers may change themselves.
			array(
				'type'  => 'title',
				'title' => __( 'Customer self-service', 'voucher-captain' ),
				'id'    => 'vcap_self_service',
			),
			SettingsRegistry::field(
				'vcap_customer_can_resend',
				array(
					'title' => __( 'Resend from My Account', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Buyers may send their delivered vouchers again from My Account (scheduled gifts are never sent early).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_customer_can_change',
				array(
					'title' => __( 'Change gifts before delivery', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Buyers may correct the recipient email (and, where the product lets them choose it, the delivery date) of a scheduled gift in My Account until it is sent.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_recipient_fix_days',
				array(
					'title'             => __( '"Correct recipient" link valid for (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'The order confirmation and the buyer email carry a link with which the buyer – also a guest – corrects a mistyped recipient address of an unused gift. It expires after this many days (default 30, 1–365).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_recipient_fix_max',
				array(
					'title'             => __( 'Recipient corrections per voucher', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'How often the recipient of one voucher can be corrected through that link (default 2, at most 2). 0 = the link is not offered.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_printed_link_grace_days',
				array(
					'title'             => __( 'Printed link valid after expiry (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'The download link printed on the voucher PDF (QR code) stays valid this many days after the voucher expired (default 30). Vouchers without an expiry date keep the link.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_self_service' ),
			// SET-VC-29: accent colour and logo of the voucher emails (free in the core, owner's rule S6), test email.
			array(
				'type'  => 'title',
				'title' => __( 'Email appearance', 'voucher-captain' ),
				'id'    => 'vcap_email_look',
				'desc'  => __( 'Applies to the emails of Voucher Captain only. Empty fields use the WooCommerce email settings (WooCommerce → Settings → Emails).', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				Emails\Branding::COLOR_OPTION,
				array(
					'title'       => __( 'Accent colour', 'voucher-captain' ),
					'type'        => 'color',
					'css'         => 'width:6em;',
					'placeholder' => (string) get_option( 'woocommerce_email_base_color', '' ),
					'desc_tip'    => __( 'Colour of the header, buttons and the voucher frame in the voucher emails, e.g. #1f3a4d. Empty = the WooCommerce base colour.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Emails\Branding::LOGO_OPTION,
				array(
					'title'       => __( 'Logo', 'voucher-captain' ),
					'type'        => 'text',
					'css'         => 'min-width:400px;',
					'placeholder' => (string) get_option( 'woocommerce_email_header_image', '' ),
					'desc_tip'    => __( 'Address (URL) of the logo shown at the top of the voucher emails, e.g. from the media library. Empty = the WooCommerce header image.', 'voucher-captain' ),
				)
			),
			array(
				'type'      => 'vcap_test_email',
				'title'     => __( 'Test email', 'voucher-captain' ),
				'id'        => 'vcap_test_email',
				'is_option' => false,
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_email_look' ),
			// SET-VC-30: own CSS for the redeem form and the account area.
			array(
				'type'  => 'title',
				'title' => __( 'Additional CSS', 'voucher-captain' ),
				'id'    => 'vcap_custom_css_block',
			),
			SettingsRegistry::field(
				CustomCss::OPTION,
				array(
					'title'    => __( 'Additional CSS', 'voucher-captain' ),
					'type'     => 'textarea',
					'css'      => 'width:100%;min-height:10em;font-family:monospace;',
					'desc'     => sprintf(
						/* translators: %d: maximum number of characters */
						__( 'Printed only on pages with Voucher Captain output (redeem form, balance check, blocks, voucher fields, My Account). Tags, @import, expression() and script addresses are removed; at most %d characters.', 'voucher-captain' ),
						CustomCss::MAX_LENGTH
					),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_custom_css_block' ),
		);
		return $fields;
	}

	/** Section "API". */
	private function api_fields(): array {
		$fields = array(
			array(
				'type'  => 'title',
				'title' => __( 'External redemptions', 'voucher-captain' ),
				'id'    => 'vcap_api',
				'desc'  => __( 'Other plugins and apps can redeem vouchers through the PHP service vcap()->redemptions or the REST API /wp-json/vcap/v1/redemptions (see API.md).', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				'vcap_redemption_ttl',
				array(
					'title'             => __( 'Reservation lifetime (minutes)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'A reserved voucher that is neither completed nor cancelled within this time is released automatically.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_redemption_max_failures',
				array(
					'title'             => __( 'Invalid code attempts', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Number of invalid codes a user may enter within 15 minutes before further attempts are blocked. 0 = no limit.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_redemption_site_wide_failures',
				array(
					'title'             => __( 'Invalid code attempts of all callers', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Invalid codes within 15 minutes across all users of the redemption API (default 300, at least 50). Above it every user\'s quota shrinks to a fifth, which slows down guessing from many accounts.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_api' ),
			array(
				'type'  => 'title',
				'title' => __( 'Pass Captain (tickets)', 'voucher-captain' ),
				'id'    => 'vcap_passcaptain',
				'desc'  => Compat\PassCaptain::status_html(),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_passcaptain' ),
			array(
				'type'  => 'title',
				'title' => __( 'Workflow Captain', 'voucher-captain' ),
				'id'    => 'vcap_suite_workflowcaptain',
				'desc'  => Compat\WorkflowCaptain::status_html(),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_suite_workflowcaptain' ),
		);
		return array_merge( $fields, DatevExport::settings_fields() ); // M12-69.
	}

	/** Section "Privacy & advanced" with the counter limits. */
	private function privacy_fields(): array {
		$fields = array(
			array( 'type' => 'title', 'title' => __( 'Privacy & advanced', 'voucher-captain' ), 'id' => 'vcap_privacy' ),
			SettingsRegistry::field(
				'vcap_retention_days',
				array(
					'title'             => __( 'Anonymise recipient data after (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Recipient name, email, message and custom fields are erased this many days after a voucher has expired or been fully redeemed. 0 = keep.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_ticket_public_status',
				array(
					'title' => __( 'Public ticket status', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'When a ticket QR code is opened by someone without scanning rights, show whether the ticket is valid (no personal data).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_scanner_show_holder',
				array(
					'title' => __( 'Holder name in the scanner', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Show the name of the buyer or recipient when a code is scanned. Personal tickets always show the name so it can be checked. Order number and original value are only shown to staff who manage vouchers.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_subscription_renewals',
				array(
					'title' => __( 'Subscription renewals', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Issue new vouchers for WooCommerce Subscriptions renewal orders.', 'voucher-captain' ),
				)
			),
			...Admin\DataRemoval::fields( self::delete_on_uninstall_description() ),
			SettingsRegistry::field(
				Logger::VERBOSE_OPTION,
				array(
					'title' => __( 'Verbose log', 'voucher-captain' ),
					'type'  => 'checkbox',
					/* translators: %d: hours */
					'desc'  => sprintf( __( 'Write detailed entries about issuing and deliveries to the WooCommerce log (source "voucher-captain") for support. Switches itself off after %d hours.', 'voucher-captain' ), Logger::VERBOSE_HOURS ),
				)
			),
			Health::proxy_header_field(), // SET-VC-26.
			array( 'type' => 'sectionend', 'id' => 'vcap_privacy' ),
		);
		$fields = array_merge( $fields, self::counter_limit_fields(), Admin\RoleCaps::fields() ); // SET-VC-21.
		return $fields;
		$fields = array_merge( $fields, self::counter_limit_fields() );
		// SET-VC-14/15/16: export, import, reset and change log of the settings.
		return array_merge( $fields, Admin\SettingsTools::fields() );
	}

	/* ---- M12-90: sections of the former "General" page. ---------------------------------------------------- */

	/** Blocks of general_fields() shown on their own section (other blocks stay on "General"). */
	private const GENERAL_BLOCKS = array(
		'vcap_codes'    => 'codes',
		'vcap_redeem'         => 'redemption',
		'vcap_redeem_windows' => 'redemption',
		'vcap_guessing'       => 'redemption',
		'vcap_referral' => 'promotions',
	);

	/** Single options shown on another section than their block's. */
	private const GENERAL_OPTIONS = array(
		'vcap_campaigns_enabled'     => 'promotions',
		'vcap_bonus_wait_days'       => 'promotions',
		'vcap_discounts_on_vouchers' => 'promotions',
		'vcap_coupon_link'           => 'promotions',
		'vcap_coupon_link_param'     => 'promotions',
	);

	/** Section ('' = General) of an option of general_fields() in block $block. */
	public static function general_section_of( string $block, string $option ): string {
		return self::GENERAL_OPTIONS[ $option ] ?? self::GENERAL_BLOCKS[ $block ] ?? '';
	}

	/**
	 * The fields of general_fields() that belong to one section, with their blocks (title, fields, end). Options
	 * moved out of their block get the block title "Promotions and links". Fields other code adds to a block of the
	 * general page (filter or later additions) follow their block; unknown blocks stay on "General".
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<int, array<string, mixed>>
	 */
	public static function general_part( array $fields, string $section ): array {
		$out    = array();
		$block  = '';
		$title  = null;
		$opened = false;
		foreach ( $fields as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			if ( 'title' === $type ) {
				$block  = (string) ( $field['id'] ?? '' );
				$title  = $field;
				$opened = false;
				continue;
			}
			if ( 'sectionend' === $type ) {
				if ( $opened ) {
					$out[] = array( 'type' => 'sectionend', 'id' => (string) ( $title['id'] ?? $block ) );
				}
				$block  = '';
				$title  = null;
				$opened = false;
				continue;
			}
			$own = self::GENERAL_BLOCKS[ $block ] ?? '';
			if ( self::general_section_of( $block, (string) ( $field['id'] ?? '' ) ) !== $section ) {
				continue;
			}
			if ( ! $opened ) {
				$opened = true;
				$title  = $own !== $section
					? array( 'type' => 'title', 'title' => __( 'Promotions and links', 'voucher-captain' ), 'id' => $block . '_' . $section )
					: ( $title ?? array( 'type' => 'title', 'id' => $block ) );
				$out[]  = $title;
			}
			$out[] = $field;
		}
		if ( $opened ) {
			$out[] = array( 'type' => 'sectionend', 'id' => (string) ( $title['id'] ?? $block ) );
		}
		return $out;
	}

	/** Section "General": issuing, codes, redemption and the other shop-wide options. */
	private function general_fields(): array {
		// Unpaid statuses are not offered: vouchers are means of payment and must not go out before the
		// money arrived. A previously saved one stays visible (and marked) so it can be removed.
		$saved    = array_map( 'strval', (array) $this->get( 'vcap_issue_statuses' ) );
		$unpaid   = array( 'pending', 'on-hold', 'failed', 'cancelled', 'refunded', 'checkout-draft' );
		$statuses = array();
		foreach ( wc_get_order_statuses() as $key => $label ) {
			$status = substr( $key, 3 );
			if ( in_array( $status, $unpaid, true ) ) {
				if ( in_array( $status, $saved, true ) ) {
					/* translators: %s: order status */
					$statuses[ $status ] = sprintf( __( '%s (not paid – vouchers wait for the payment)', 'voucher-captain' ), $label );
				}
				continue;
			}
			$statuses[ $status ] = $label;
		}
		$issue_desc = __( 'Vouchers are created once per purchased item the first time an order reaches one of these statuses. Leave empty to issue manually only.', 'voucher-captain' );
		$issue_desc .= ' ' . __( 'Orders paid by cash on delivery, bank transfer or cheque issue vouchers only once the payment is confirmed: the order is completed, the payment is recorded, or a paid status is set by hand.', 'voucher-captain' );
		if ( Issuer::cod_for_virtual() ) {
			$issue_desc .= ' ' . __( 'Cash on delivery is offered for virtual products: vouchers of such orders wait until the order is completed. Consider switching off "Accept for virtual orders" in the cash on delivery settings.', 'voucher-captain' );
		}
		if ( array_intersect( $saved, $unpaid ) ) {
			$issue_desc .= ' ' . __( 'Unpaid statuses (e.g. "On hold" for bank transfer) issue nothing until the payment is recorded – remove them and keep "Processing" and "Completed".', 'voucher-captain' );
		}
		$fields = array(
			array( 'type' => 'title', 'title' => __( 'Voucher issuing', 'voucher-captain' ), 'id' => 'vcap_general' ),
			SettingsRegistry::field(
				'vcap_issue_statuses',
				array(
					'title'   => __( 'Generate vouchers when order is', 'voucher-captain' ),
					'type'    => 'multiselect',
					'class'   => 'wc-enhanced-select',
					'options' => $statuses,
					'desc'    => $issue_desc,
				)
			),
			SettingsRegistry::field(
				'vcap_default_terms',
				array(
					'title'    => __( 'Default redemption conditions', 'voucher-captain' ),
					'type'     => 'textarea',
					'css'      => 'min-width:400px;height:80px;',
					'desc_tip' => __( 'Used when a product has no conditions of its own, e.g. "Not exchangeable for cash. Residual value remains on the voucher."', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_terms_by_country',
				array(
					'title' => __( 'Redemption conditions per country', 'voucher-captain' ),
					'type'  => 'textarea',
					'css'   => 'min-width:400px;height:120px;',
					'desc'  => __( 'Optional, for shops selling to several countries. One block per country, starting with the two-letter country code and a colon, e.g. "AT: Valid for 30 years." The billing country of the order decides; products with their own conditions and countries without a block use the conditions above. Have the texts checked legally for each country.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Markets::EXPIRY_OPTION,
				array(
					'title' => __( 'Statutory validity per country', 'voucher-captain' ),
					'type'  => 'textarea',
					'css'   => 'min-width:400px;height:80px;',
					'desc'  => __( 'Optional. One line per country: two-letter code, colon, years, and "end" for "to the end of the year" (0 = never expires), e.g. "DE: 3 end" or "AT: 30". Used for vouchers with the statutory validity; overrides the verified defaults (Germany 3 years to the end of the year, Austria 30 years). Countries without a rule use 3 years to the end of the year. Have the rules checked legally for each country.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_default_template',
				array(
					'title'   => __( 'Default template', 'voucher-captain' ),
					'type'    => 'select',
					'options' => $this->template_options(),
					'desc_tip' => __( 'PDF template of voucher products without a template of their own (product → Voucher settings). "— None —" uses the built-in layout.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_default_expiry_rule',
				array(
					'title'    => __( 'Default validity', 'voucher-captain' ),
					'type'     => 'select',
					'options'  => array(
						'statutory' => __( 'Statutory default for the buyer\'s country (Germany: 3 years to the end of the year, Austria: 30 years)', 'voucher-captain' ),
						'days'      => __( 'Fixed number of days (below)', 'voucher-captain' ),
					),
					'default'  => ProductConfig::default_expiry_rule(),
					'desc_tip' => __( 'Used when a product does not define its own expiry. Shorter validity periods can be invalid towards consumers; have them checked legally.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_default_expiry_days',
				array(
					'title'             => __( 'Default validity (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Used with "Fixed number of days" when a product does not define its own expiry. 0 = never expires.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_general' ),

			array(
				'type'  => 'title',
				'title' => __( 'Abuse protection', 'voucher-captain' ),
				'desc'  => __( 'Limits against vouchers bought with stolen payment details. 0 switches a limit off.', 'voucher-captain' ),
				'id'    => 'vcap_abuse',
			),
			SettingsRegistry::field(
				'vcap_custom_max_default',
				array(
					'title'             => __( 'Default maximum of customer-chosen amounts', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => '0.01' ),
					'desc_tip'          => __( 'Applies to "customer chooses the amount" products without their own maximum (shop currency). 0 = no limit.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_customer_daily_limit',
				array(
					'title'             => __( 'Maximum voucher value per customer and 24 hours', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => '0.01' ),
					'desc_tip'          => __( 'Checked at checkout: vouchers in the cart plus vouchers ordered in the last 24 hours by the same account or billing email (shop currency). 0 = no limit.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_hold_new_customer_above',
				array(
					'title'             => __( 'Hold vouchers of new customers above', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => '0.01' ),
					'desc_tip'          => __( 'Vouchers of a first order above this value are issued, but delivered and redeemable only after the hold below. 0 = off.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_hold_hours',
				array(
					'title'             => __( 'Hold for (hours)', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'How long vouchers held by the limit above wait after the payment before they are delivered and redeemable (default 24). 0 = no hold.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_abuse' ),

			array( 'type' => 'title', 'title' => __( 'Coupon codes', 'voucher-captain' ), 'id' => 'vcap_codes' ),
			SettingsRegistry::field(
				'vcap_code_prefix',
				array(
					'title'             => __( 'Prefix', 'voucher-captain' ),
					'type'              => 'text',
					'desc_tip'          => __( 'Letters, digits, dot, hyphen and underscore, up to 20 characters.', 'voucher-captain' ),
					'custom_attributes' => array( 'maxlength' => 20, 'pattern' => '[A-Za-z0-9._\-]*' ),
				)
			),
			SettingsRegistry::field(
				'vcap_code_suffix',
				array(
					'title'             => __( 'Suffix', 'voucher-captain' ),
					'type'              => 'text',
					'desc_tip'          => __( 'Letters, digits, dot, hyphen and underscore, up to 20 characters.', 'voucher-captain' ),
					'custom_attributes' => array( 'maxlength' => 20, 'pattern' => '[A-Za-z0-9._\-]*' ),
				)
			),
			SettingsRegistry::field(
				'vcap_code_length',
				array(
					'title'             => __( 'Random part length', 'voucher-captain' ),
					'type'              => 'number',
					'desc'              => __( 'Voucher codes are made at least as long as needed to be safe from guessing (about 2^50 combinations): 10 letters and digits, 11 letters or 16 digits.', 'voucher-captain' ),
					'custom_attributes' => array( 'step' => 1 ),
				)
			),
			SettingsRegistry::field(
				'vcap_code_charset',
				array(
					'title'   => __( 'Characters', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						'alnum_upper' => __( 'Letters and digits, uppercase (no ambiguous characters)', 'voucher-captain' ),
						'alpha_upper' => __( 'Letters only, uppercase', 'voucher-captain' ),
						'numeric'     => __( 'Digits only', 'voucher-captain' ),
						'alnum'       => __( 'Letters and digits, mixed case', 'voucher-captain' ),
					),
					'desc_tip' => __( 'Characters of the random part of new codes. Fewer characters make longer codes (see "Random part length"). Existing codes do not change.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_code_separator',
				array(
					'title'             => __( 'Separator', 'voucher-captain' ),
					'type'              => 'text',
					'css'               => 'width:60px',
					'desc_tip'          => __( 'Placed between prefix, order number, random part and suffix, e.g. "-". Letters, digits, dot, hyphen and underscore, up to 3 characters; empty = no separator.', 'voucher-captain' ),
					'custom_attributes' => array( 'maxlength' => 3, 'pattern' => '[A-Za-z0-9._\-]*' ),
				)
			),
			SettingsRegistry::field(
				'vcap_code_order_ref',
				array(
					'title' => __( 'Include order number', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Add the order number to the code, e.g. GIFT-1042-7KQ2M9XA.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_codes' ),

			array( 'type' => 'title', 'title' => __( 'Redemption', 'voucher-captain' ), 'id' => 'vcap_redeem' ),
			SettingsRegistry::field(
				'vcap_redeem_page_id',
				array(
					'title'    => __( 'Redeem page', 'voucher-captain' ),
					'type'     => 'single_select_page',
					'class'    => 'wc-enhanced-select-nostd',
					'css'      => 'min-width:300px;',
					'desc_tip' => true,
					'desc'     => __( 'Page with the "Redeem voucher" block or [vcap_redeem]. Emails, PDFs ({redeem_url}) and the customer account link there, with the code filled in. Ticket vouchers are exchanged for tickets on this page.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_restore_on_cancel',
				array(
					'title' => __( 'Restore balance on cancellation', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Give the redeemed amount back when an order that used a voucher is cancelled, refunded or failed.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				PaymentVouchers::OPTION,
				array(
					'title' => __( 'Multi-purpose vouchers as payment', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Settle multi-purpose vouchers (gift cards, value vouchers sold in the shop, store credit) as a payment after tax: the order keeps the full VAT and the voucher pays part of the total as a separate line. This applies only to vouchers with the VAT treatment "Multi-purpose voucher" (or automatic) and the discount type "Fixed cart discount"; single-purpose vouchers, promotions, percentages, "Fixed product discount" and ticket vouchers without a value keep lowering price and VAT. Off: every voucher is a coupon and lowers price and VAT (previous behaviour). Have the switch confirmed by your tax adviser.', 'voucher-captain' ) . $this->not_settled_note(),
				)
			),
			SettingsRegistry::field(
				'vcap_void_on_refund',
				array(
					'title' => __( 'Void vouchers on refund', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Disable vouchers when the order they were bought with is fully refunded or cancelled.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_campaigns_enabled',
				array(
					'title' => __( 'Automatic promotions', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Apply published promotions (Vouchers → Promotions) automatically in the cart. The discount is shown as its own line in the cart and the order; catalog prices do not change.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_bonus_wait_days',
				array(
					'title'             => __( 'Bonus vouchers valid after (days)', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'A bonus voucher is created when the order is paid, but sent and redeemable only after this many days; a cancelled or refunded order withdraws it before. 0 = at once.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_store_credit',
				array(
					'title' => __( 'Store credit', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Enable store credit accounts: credit customers manually (user profile) or refund orders as store credit (order screen); customers see and use their credit in My Account and the cart.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_credit_with_coupons',
				array(
					'title' => __( 'Store credit with discount codes', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Allow store credit in the same order as discount codes. Discounts are always calculated first; gift cards and store credit then pay the remaining amount.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_credit_apply_order',
				array(
					'title'   => __( 'Order of gift cards and store credit', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						'last'  => __( 'Gift cards first, then store credit', 'voucher-captain' ),
						'first' => __( 'Store credit first, then gift cards', 'voucher-captain' ),
					),
					'desc_tip' => __( 'Which pays first when a customer uses gift cards and store credit in the same order. Discount codes are always calculated before both.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_discounts_on_vouchers',
				array(
					'title' => __( 'Discount codes on vouchers', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Allow discount codes to reduce the price of voucher products. Vouchers keep their full face value, so a 10 % code on a 50 € voucher costs the shop 5 €.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_vouchers_buy_vouchers',
				array(
					'title' => __( 'Pay vouchers with vouchers', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Allow vouchers and gift cards to pay for voucher products (off by default to prevent exchanging or extending vouchers).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_coupon_link',
				array(
					'title' => __( 'Voucher links', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Apply a coupon automatically when a customer opens a link such as https://your-shop/?gutschein=CODE (any WooCommerce coupon). The code is applied once, validated by WooCommerce, and only when the cart has products.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_coupon_link_param',
				array(
					'title'    => __( 'Link parameter', 'voucher-captain' ),
					'type'     => 'text',
					'css'      => 'width:160px',
					'desc_tip' => __( 'Name of the URL parameter (letters, digits, - and _).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_giftcard_field',
				array(
					'title' => __( 'Gift card field', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Show a separate "Redeem a gift card" field in the cart and at checkout (classic pages and blocks). Gift cards and store credit are applied without reloading the page.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_minicart_balance',
				array(
					'title' => __( 'Balance in the mini cart', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Show the remaining balance of applied gift cards in the mini cart widget.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_redeem' ),

			// SET-VC-18 (1.12 no. 19–21): time windows of top-ups and the counter.
			array(
				'type'  => 'title',
				'title' => __( 'Top-ups and counter', 'voucher-captain' ),
				'id'    => 'vcap_redeem_windows',
			),
			SettingsRegistry::field(
				'vcap_topup_min_remaining_days',
				array(
					'title'             => __( 'Top-up needs remaining validity (days)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'A gift card is only topped up while it is valid for at least this many more days, so the new value does not expire with it soon (default 30). 0 = any valid card.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_checkin_undo_minutes',
				array(
					'title'             => __( 'Undo own check-in within (minutes)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Scanner staff may revert their own last check-in of a ticket within this time (default 10). Managers can always revert, with a reason. 0 = only managers.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_counter_reversal_minutes',
				array(
					'title'             => __( 'Cancel own counter redemption within (minutes)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Scanner staff may cancel a redemption they booked at the counter within this time (default 15). Managers can always cancel. 0 = only managers.', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_redeem_windows' ),

			// SET-VC-31 (1.12 no. 22, 24): limits against guessing codes; the register's floor keeps them switched on.
			array(
				'type'  => 'title',
				'title' => __( 'Protection against code guessing', 'voucher-captain' ),
				'id'    => 'vcap_guessing',
				'desc'  => __( 'Every form where a code can be entered (redeem form, cart, gift card field, balance check, top-up, links) counts in one quota per visitor and 15 minutes. These limits cannot be switched off.', 'voucher-captain' ),
			),
			SettingsRegistry::field(
				'vcap_code_attempts_per_visitor',
				array(
					'title'             => __( 'Code attempts per visitor', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Unknown codes one visitor may enter within 15 minutes before further attempts are refused (default 15, 3–100). Existing codes do not count.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_code_attempts_site_wide',
				array(
					'title'             => __( 'Unknown codes of all visitors', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Unknown codes within 15 minutes across all visitors (default 300, at least 50). Above it every visitor\'s quota shrinks to a fifth, which slows down guessing from many addresses.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_email_confirm_per_hour',
				array(
					'title'             => __( 'Confirmation emails per account and hour', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'How often a customer can request the link that confirms their email address for personal vouchers (default 3, 1–10).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_email_confirm_link_hours',
				array(
					'title'             => __( 'Confirmation link valid for (hours)', 'voucher-captain' ),
					'type'              => 'number',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Lifetime of the link that confirms a customer\'s email address for personal vouchers (default 48, 1–168).', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_guessing' ),

			array(
				'type'  => 'title',
				'title' => __( 'Refer a friend', 'voucher-captain' ),
				'desc'  => __( 'Customers find their personal code in My Account → Vouchers. Friends use it on their first order; the referring customer is rewarded after the waiting period once that order is completed and paid. Own codes cannot be used.', 'voucher-captain' ),
				'id'    => 'vcap_referral',
			),
			SettingsRegistry::field(
				'vcap_referral_enabled',
				array(
					'title' => __( 'Refer a friend', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'Enable referral codes', 'voucher-captain' ),
					'desc_tip' => __( 'Gives every customer a personal referral code in My Account → Vouchers; the rules are set below.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_friend_amount',
				array(
					'title'             => __( 'Discount for the friend', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => '0.01' ),
					'desc_tip'          => __( 'Discount the friend gets on the first order with the referral code: an amount in the shop currency or a percentage (see "Discount type").', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_friend_type',
				array(
					'title'   => __( 'Discount type', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						'fixed_cart' => __( 'Fixed amount', 'voucher-captain' ),
						'percent'    => __( 'Percentage', 'voucher-captain' ),
					),
					'desc_tip' => __( 'Fixed amount off the order, or a percentage of the order value.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_min_spend',
				array(
					'title'             => __( 'Minimum order value for the friend', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'placeholder'       => __( 'automatic', 'voucher-captain' ),
					'custom_attributes' => array( 'step' => '0.01' ),
					'desc_tip'          => __( 'Empty: twice a fixed discount. A fixed discount always needs an order value above it (at least the discount plus 1).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_reward_amount',
				array(
					'title'             => __( 'Reward for the referrer', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => '0.01' ),
					'desc_tip'          => __( 'Amount in the shop currency the referring customer gets for each successful referral. 0 = no reward.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_reward_as',
				array(
					'title'   => __( 'Reward as', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						'coupon' => __( 'Coupon by email', 'voucher-captain' ),
						'credit' => __( 'Store credit (requires store credit)', 'voucher-captain' ),
					),
					'desc_tip' => __( 'A single-use coupon sent by email, or a credit on the customer\'s store credit account (only when "Store credit" is switched on).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_reward_days',
				array(
					'title'             => __( 'Reward coupon valid for (days)', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'The reward coupon expires this many days after it was created (default 90). 0 = never expires.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_wait_days',
				array(
					'title'             => __( 'Waiting period before the reward (days)', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Counted from the payment of the friend\'s order (e.g. the withdrawal period). A cancelled or refunded order earns nothing. 0 = at once.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_max_rewards',
				array(
					'title'             => __( 'Rewards per referrer and 30 days', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => array( 'step' => 1 ),
					'desc_tip'          => __( 'Highest number of rewards one referring customer can earn within 30 days; a referral beyond the limit earns nothing. 0 = unlimited.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				'vcap_referral_address_check',
				array(
					'title' => __( 'Address check', 'voucher-captain' ),
					'type'  => 'checkbox',
					'desc'  => __( 'No reward when the friend\'s billing address is the referrer\'s own address', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_referral' ),
		);
		return array_merge( $fields, PaymentMethods::settings_fields() ); // SET-VC-19: block on "General".
	}

	/** Voucher products the setting "Multi-purpose vouchers as payment" leaves out (only on the settings page). */
	/** R6: daily limits of counter (scanner) redemptions (Counter), a block of the "Privacy & advanced" section. */
	private static function counter_limit_fields(): array {
		$money = array( 'step' => '0.01' );
		$count = array( 'step' => 1 );
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Counter limits (scanner)', 'voucher-captain' ),
				'desc'  => __( 'Daily limits for redemptions at the counter, per shop day (shop time zone). 0 = unlimited. Staff who manage vouchers are not limited; a staff member can get own limits on their user profile. The amount counts gift cards and value vouchers in the currency of the voucher being redeemed.', 'voucher-captain' ),
				'id'    => 'vcap_counter_limits',
			),
			SettingsRegistry::field(
				Counter::USER_AMOUNT,
				array(
					'title'             => __( 'Amount per staff member and day', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => $money,
					'desc_tip'          => __( 'Highest total value one staff member may redeem at the counter per shop day. 0 = unlimited.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Counter::USER_COUNT,
				array(
					'title'             => __( 'Redemptions per staff member and day', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => $count,
					'desc_tip'          => __( 'Highest number of counter redemptions per staff member and shop day. 0 = unlimited.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Counter::TILL_AMOUNT,
				array(
					'title'             => __( 'Amount per till/branch and day', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => $money,
					'desc_tip'          => __( 'Counts by the till/branch set on the scanner device; devices without one share one limit.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Counter::TILL_COUNT,
				array(
					'title'             => __( 'Redemptions per till/branch and day', 'voucher-captain' ),
					'type'              => 'number',
					'css'               => 'width:90px',
					'custom_attributes' => $count,
					'desc_tip'          => __( 'Highest number of counter redemptions per till/branch and shop day; devices without a till share one limit. 0 = unlimited.', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Counter::REQUIRE_RECEIPT,
				array(
					'title'   => __( 'Receipt no. required', 'voucher-captain' ),
					'type'    => 'select',
					'options' => array(
						'auto' => __( 'Automatic (required for shops based in Germany or Austria)', 'voucher-captain' ),
						'yes'  => __( 'Required', 'voucher-captain' ),
						'no'   => __( 'Optional', 'voucher-captain' ),
					),
					'desc'    => __( 'A counter redemption is only booked with the receipt number of the till receipt for the goods (checked on the server).', 'voucher-captain' ),
				)
			),
			SettingsRegistry::field(
				Counter::LOCATIONS,
				array(
					'title' => __( 'Tills / branches', 'voucher-captain' ),
					'type'  => 'textarea',
					'css'   => 'width:300px;height:90px',
					'desc'  => __( 'One per line. When set, the scanner offers this list instead of a free text field, and a staff member can be fixed to one on their user profile. The scanner works online only: without a connection nothing is booked (on purpose – an offline scanner could redeem the same code twice).', 'voucher-captain' ),
				)
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_counter_limits' ),
		);
	}

	private function not_settled_note(): string {
		if ( ! is_admin() || ! current_user_can( 'manage_woocommerce' ) ) {
			return '';
		}
		$note = PaymentVouchers::not_settled_note();
		return '' !== $note ? '<br><strong>' . $note . '</strong>' : '';
	}

	public function date_format(): string {
		$format = (string) $this->get( 'vcap_date_format' );
		return '' !== $format ? $format : (string) get_option( 'date_format', 'Y-m-d' );
	}

	/**
	 * Site secret for signed links. Created once (insert_once(), never overwritten): when a read fails for a
	 * moment (database hiccup, stale or poisoned cache), the stored secret is read again instead of being
	 * replaced – replacing it would invalidate every PDF and confirmation link already sent. If it still cannot
	 * be read, a secret for this request only is used (links signed with it simply do not validate) and nothing
	 * is stored.
	 */
	public function secret(): string {
		static $runtime = '';
		$secret = (string) get_option( 'vcap_secret' );
		if ( '' !== $secret ) {
			return $secret;
		}
		$stored = self::insert_once( 'vcap_secret', wp_generate_password( 64, true, true ), false );
		if ( '' !== $stored ) {
			return $stored;
		}
		if ( '' === $runtime ) {
			$runtime = wp_generate_password( 64, true, true );
		}
		return $runtime;
	}

	/**
	 * Description of "Delete data on uninstall" (M12-67): what is deleted, what happens to the coupons, the
	 * retention periods of bookkeeping records and a link to export the complete redemption journal first.
	 */
	public static function delete_on_uninstall_description(): string {
		$text = __( 'Off (default): deleting the plugin keeps all data. On: deleting the plugin removes the voucher tables (including the redemption journal), templates, field groups, promotions, the voucher settings of products, the private PDF folder and all settings. The WooCommerce coupons of the vouchers are moved to the trash (restorable there), other coupons are kept.', 'voucher-captain' )
			. ' ' . __( 'On a network each site decides for itself. Scheduled background jobs are always removed. This setting is not part of any export of settings.', 'voucher-captain' )
			. ' ' . __( 'The journal is part of your bookkeeping records: retention periods are 8 or 10 years in Germany (§ 147 AO, § 257 HGB) and 7 years in Austria (§ 132 BAO). Export it before deleting the plugin.', 'voucher-captain' );
		$url = Admin\ReportsAdmin::journal_csv_url( Admin\ReportsAdmin::JOURNAL_START, wp_date( 'Y-m-d' ) );
		return esc_html( $text ) . ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Export the complete journal (CSV)', 'voucher-captain' ) . '</a>';
	}

	/**
	 * M12-17: creates an option that must never change once set (secrets, "since" dates) and returns the value
	 * actually stored. add_option() is not safe for this: when the option is listed in the "notoptions" cache
	 * (object cache race, a failed SELECT) it skips its existence check and runs INSERT … ON DUPLICATE KEY UPDATE,
	 * overwriting the stored value. Here the row is written with INSERT IGNORE (an empty row is filled, a value
	 * is never replaced), the option caches are dropped and the row is read back raw.
	 *
	 * @return string The stored value, '' when nothing could be stored or read (the caller uses a runtime value).
	 */
	public static function insert_once( string $name, string $value, bool $autoload ): string {
		global $wpdb;
		// 'yes'/'no' are understood by every WordPress from 6.4 on (6.6 maps them to on/off).
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, %s)", $name, $value, $autoload ? 'yes' : 'no' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = ''", $value, $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$stored = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return is_string( $stored ) ? $stored : '';
	}
}
