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
		'vcap_download_link_days'    => 90, // V10: for shops that never saved it; a saved value (also 0) is kept.
		'vcap_show_thankyou'         => 'yes',
		'vcap_show_myaccount'        => 'yes',
		'vcap_myaccount_tab'         => 'yes',
		'vcap_attach_pdf'            => 'yes',
		'vcap_include_link'          => 'yes',
		'vcap_expiry_reminder_days'  => 0,
		'vcap_delivery_hour'         => 8,
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
		// M12-95: storefront conveniences, off by default (existing shops keep their storefront).
		'vcap_giftcard_field'          => 'no',
		'vcap_minicart_balance'        => 'no',
		'vcap_gift_toggle'             => 'no',
		// Stored as "yes" on a first installation (Install::activate_site()); existing shops keep "no".
		'vcap_new_product_delivery'    => 'no',
		'vcap_redemption_max_failures' => 10,
		'vcap_credit_with_coupons'     => 'yes',
		'vcap_credit_apply_order'      => 'last',
		'vcap_terms_by_country'        => '',
		'vcap_expiry_by_country'       => '',
		'vcap_referral_enabled'        => 'no',
		'vcap_referral_friend_type'    => 'fixed_cart',
		'vcap_referral_friend_amount'  => 10,
		// V3: empty = automatic (twice a fixed friend discount); a fixed discount always needs a higher order value.
		'vcap_referral_min_spend'      => '',
		'vcap_referral_reward_amount'  => 10,
		'vcap_referral_reward_as'      => 'coupon',
		'vcap_referral_reward_days'    => 90,
		// P12 abuse protection, safe defaults (0 switches each one off).
		'vcap_referral_wait_days'      => 14,
		'vcap_referral_max_rewards'    => 10,
		'vcap_referral_address_check'  => 'yes',
		'vcap_bonus_wait_days'         => 14,
		'vcap_custom_max_default'      => 500,
		'vcap_customer_daily_limit'    => 1000,
		'vcap_hold_new_customer_above' => 250,
		'vcap_hold_hours'              => 24,
		// Z6: bundled delivery of large quantities (0 switches each one off).
		'vcap_bundle_threshold'        => 5,
		'vcap_bundle_pdf_limit'        => 50,
		// R6: daily limits of counter (scanner) redemptions, off by default (0 = unlimited).
		Counter::USER_AMOUNT           => 0,
		Counter::USER_COUNT            => 0,
		Counter::TILL_AMOUNT           => 0,
		Counter::TILL_COUNT            => 0,
		// M12-31: receipt number of the till required at the counter ('auto' = shops based in Germany or Austria).
		Counter::REQUIRE_RECEIPT       => 'auto',
		// M12-81: managed list of tills/branches ('' = free text on the scanner device).
		Counter::LOCATIONS             => '',
	);

	/**
	 * A setting outside of an instance (static helpers such as ProductConfig): the same value Settings::get()
	 * returns – the stored option, else the default, through the filter "vcap_setting_{key}".
	 *
	 * @return mixed
	 */
	public static function value( string $key ) {
		return apply_filters( 'vcap_setting_' . $key, get_option( $key, self::DEFAULTS[ $key ] ?? null ) );
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
			if ( array_key_exists( $key, self::DEFAULTS ) && false !== add_option( $key, self::DEFAULTS[ $key ], '', 'yes' ) ) {
				$stored[] = $key;
			}
		}
		return $stored;
	}

	public function get( string $key ) {
		$default = self::DEFAULTS[ $key ] ?? null;
		$value   = get_option( $key, $default );
		return apply_filters( 'vcap_setting_' . $key, $value );
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
		return apply_filters( 'vcap_settings_fields', $fields, $section );
	}

	/** Section "Delivery & downloads" (and bundled delivery). */
	private function delivery_fields(): array {
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
				'title'   => __( '"Gift / for me" choice', 'voucher-captain' ),
				'id'      => 'vcap_gift_toggle',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'On product pages with recipient fields, let the customer choose "As a gift" or "For me"; "For me" hides the recipient fields and sends the voucher to the buyer.', 'voucher-captain' ),
			),
			array(
				'title'   => __( 'Delivery date for new products', 'voucher-captain' ),
				'id'      => 'vcap_new_product_delivery',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'New voucher products offer the "Delivery date" field by default (existing products keep their fields).', 'voucher-captain' ),
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
				'default'           => self::DEFAULTS['vcap_download_link_days'],
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => __( 'Signed links in emails expire after this many days (default 90). Customers get a fresh link in My Account at any time; reissuing a voucher invalidates its old links. 0 = never expire (not recommended).', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'Delivery hour', 'voucher-captain' ),
				'id'                => 'vcap_delivery_hour',
				'type'              => 'number',
				'default'           => self::DEFAULTS['vcap_delivery_hour'],
				'custom_attributes' => array( 'min' => 0, 'max' => 23, 'step' => 1 ),
				'desc_tip'          => __( 'Hour of the day (shop time, 0–23) at which vouchers with a delivery date are sent (default 8).', 'voucher-captain' ),
			),
			array(
				'title'    => __( 'Expiry reminders (days before)', 'voucher-captain' ),
				'id'       => 'vcap_expiry_reminder_days',
				'type'     => 'text',
				'default'  => '0',
				'desc_tip' => __( 'Email the voucher holder this many days before a voucher with remaining value expires. Several reminders are separated by commas, e.g. "30, 7, 1"; each one is sent once. 0 = no reminders. The email text is edited under WooCommerce → Settings → Emails.', 'voucher-captain' ),
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
			array(
				'type'  => 'title',
				'title' => __( 'Bulk orders', 'voucher-captain' ),
				'id'    => 'vcap_bundle',
				'desc'  => __( 'Many vouchers of one order line for the same recipient are sent in one email with a list of all codes instead of one email per voucher; the buyer gets one copy per order. Vouchers with different delivery dates are sent separately. The email text is edited under WooCommerce → Settings → Emails → "Vouchers (bulk order)"; switching that email off sends every voucher on its own again.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'One email from (vouchers)', 'voucher-captain' ),
				'id'                => 'vcap_bundle_threshold',
				'type'              => 'number',
				'default'           => self::DEFAULTS['vcap_bundle_threshold'],
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => __( 'From this many vouchers of the same order line and recipient, due at the same time, they are sent together in one email (default 5). 0 = always one email per voucher.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'One PDF up to (vouchers)', 'voucher-captain' ),
				'id'                => 'vcap_bundle_pdf_limit',
				'type'              => 'number',
				'default'           => self::DEFAULTS['vcap_bundle_pdf_limit'],
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => __( 'A bundled email with up to this many vouchers carries one PDF with a page per voucher (default 20 for new shops). Larger bundles carry no attachment but a download link per voucher and the link to My Account. 0 = never attach a PDF to a bundled email.', 'voucher-captain' ),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_bundle' ),
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
		return array_merge( $fields, DatevExport::settings_fields() ); // M12-69.
	}

	/** Section "Privacy & advanced" with the counter limits. */
	private function privacy_fields(): array {
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
				'desc'    => self::delete_on_uninstall_description(),
			),
			array(
				'title'   => __( 'Verbose log', 'voucher-captain' ),
				'id'      => Logger::VERBOSE_OPTION,
				'type'    => 'checkbox',
				'default' => 'no',
				/* translators: %d: hours */
				'desc'    => sprintf( __( 'Write detailed entries about issuing and deliveries to the WooCommerce log (source "voucher-captain") for support. Switches itself off after %d hours.', 'voucher-captain' ), Logger::VERBOSE_HOURS ),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_privacy' ),
		);
		$fields = array_merge( $fields, self::counter_limit_fields() );
		return $fields;
	}

	/* ---- M12-90: sections of the former "General" page. ---------------------------------------------------- */

	/** Blocks of general_fields() shown on their own section (other blocks stay on "General"). */
	private const GENERAL_BLOCKS = array(
		'vcap_codes'    => 'codes',
		'vcap_redeem'   => 'redemption',
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
			array(
				'title'   => __( 'Generate vouchers when order is', 'voucher-captain' ),
				'id'      => 'vcap_issue_statuses',
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'options' => $statuses,
				'default' => array( 'processing', 'completed' ),
				'desc'    => $issue_desc,
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
					'statutory' => __( 'Statutory default for the buyer\'s country (Germany: 3 years to the end of the year, Austria: 30 years)', 'voucher-captain' ),
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

			array(
				'type'  => 'title',
				'title' => __( 'Abuse protection', 'voucher-captain' ),
				'desc'  => __( 'Limits against vouchers bought with stolen payment details. 0 switches a limit off.', 'voucher-captain' ),
				'id'    => 'vcap_abuse',
			),
			array(
				'title'             => __( 'Default maximum of customer-chosen amounts', 'voucher-captain' ),
				'id'                => 'vcap_custom_max_default',
				'type'              => 'number',
				'default'           => 500,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
				'desc_tip'          => __( 'Applies to "customer chooses the amount" products without their own maximum (shop currency). 0 = no limit.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'Maximum voucher value per customer and 24 hours', 'voucher-captain' ),
				'id'                => 'vcap_customer_daily_limit',
				'type'              => 'number',
				'default'           => 1000,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
				'desc_tip'          => __( 'Checked at checkout: vouchers in the cart plus vouchers ordered in the last 24 hours by the same account or billing email (shop currency). 0 = no limit.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'Hold vouchers of new customers above', 'voucher-captain' ),
				'id'                => 'vcap_hold_new_customer_above',
				'type'              => 'number',
				'default'           => 250,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
				'desc_tip'          => __( 'Vouchers of a first order above this value are issued, but delivered and redeemable only after the hold below. 0 = off.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'Hold for (hours)', 'voucher-captain' ),
				'id'                => 'vcap_hold_hours',
				'type'              => 'number',
				'default'           => 24,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_abuse' ),

			array( 'type' => 'title', 'title' => __( 'Coupon codes', 'voucher-captain' ), 'id' => 'vcap_codes' ),
			array(
				'title'             => __( 'Prefix', 'voucher-captain' ),
				'id'                => 'vcap_code_prefix',
				'type'              => 'text',
				'default'           => '',
				'desc_tip'          => __( 'Letters, digits, dot, hyphen and underscore, up to 20 characters.', 'voucher-captain' ),
				'custom_attributes' => array( 'maxlength' => 20, 'pattern' => '[A-Za-z0-9._\-]*' ),
			),
			array(
				'title'             => __( 'Suffix', 'voucher-captain' ),
				'id'                => 'vcap_code_suffix',
				'type'              => 'text',
				'default'           => '',
				'desc_tip'          => __( 'Letters, digits, dot, hyphen and underscore, up to 20 characters.', 'voucher-captain' ),
				'custom_attributes' => array( 'maxlength' => 20, 'pattern' => '[A-Za-z0-9._\-]*' ),
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
				'title'             => __( 'Separator', 'voucher-captain' ),
				'id'                => 'vcap_code_separator',
				'type'              => 'text',
				'default'           => '-',
				'css'               => 'width:60px',
				'custom_attributes' => array( 'maxlength' => 3, 'pattern' => '[A-Za-z0-9._\-]*' ),
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
				'desc'    => __( 'Settle multi-purpose vouchers (gift cards, value vouchers sold in the shop, store credit) as a payment after tax: the order keeps the full VAT and the voucher pays part of the total as a separate line. This applies only to vouchers with the VAT treatment "Multi-purpose voucher" (or automatic) and the discount type "Fixed cart discount"; single-purpose vouchers, promotions, percentages, "Fixed product discount" and ticket vouchers without a value keep lowering price and VAT. Off: every voucher is a coupon and lowers price and VAT (previous behaviour). Have the switch confirmed by your tax adviser.', 'voucher-captain' ) . $this->not_settled_note(),
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
				'title'             => __( 'Bonus vouchers valid after (days)', 'voucher-captain' ),
				'id'                => 'vcap_bonus_wait_days',
				'type'              => 'number',
				'default'           => 14,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => __( 'A bonus voucher is created when the order is paid, but sent and redeemable only after this many days; a cancelled or refunded order withdraws it before. 0 = at once.', 'voucher-captain' ),
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
			array(
				'title'   => __( 'Gift card field', 'voucher-captain' ),
				'id'      => 'vcap_giftcard_field',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Show a separate "Redeem a gift card" field in the cart and at checkout (classic pages and blocks). Gift cards and store credit are applied without reloading the page.', 'voucher-captain' ),
			),
			array(
				'title'   => __( 'Balance in the mini cart', 'voucher-captain' ),
				'id'      => 'vcap_minicart_balance',
				'type'    => 'checkbox',
				'default' => 'no',
				'desc'    => __( 'Show the remaining balance of applied gift cards in the mini cart widget.', 'voucher-captain' ),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_redeem' ),

			array(
				'type'  => 'title',
				'title' => __( 'Refer a friend', 'voucher-captain' ),
				'desc'  => __( 'Customers find their personal code in My Account → Vouchers. Friends use it on their first order; the referring customer is rewarded after the waiting period once that order is completed and paid. Own codes cannot be used.', 'voucher-captain' ),
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
				'default'           => '',
				'css'               => 'width:90px',
				'placeholder'       => __( 'automatic', 'voucher-captain' ),
				'custom_attributes' => array( 'min' => 0, 'step' => '0.01' ),
				'desc_tip'          => __( 'Empty: twice a fixed discount. A fixed discount always needs an order value above it (at least the discount plus 1).', 'voucher-captain' ),
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
			array(
				'title'             => __( 'Waiting period before the reward (days)', 'voucher-captain' ),
				'id'                => 'vcap_referral_wait_days',
				'type'              => 'number',
				'default'           => 14,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => __( 'Counted from the payment of the friend\'s order (e.g. the withdrawal period). A cancelled or refunded order earns nothing. 0 = at once.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'Rewards per referrer and 30 days', 'voucher-captain' ),
				'id'                => 'vcap_referral_max_rewards',
				'type'              => 'number',
				'default'           => 10,
				'css'               => 'width:90px',
				'custom_attributes' => array( 'min' => 0, 'step' => 1 ),
				'desc_tip'          => __( '0 = unlimited.', 'voucher-captain' ),
			),
			array(
				'title'   => __( 'Address check', 'voucher-captain' ),
				'id'      => 'vcap_referral_address_check',
				'type'    => 'checkbox',
				'default' => 'yes',
				'desc'    => __( 'No reward when the friend\'s billing address is the referrer\'s own address', 'voucher-captain' ),
			),
			array( 'type' => 'sectionend', 'id' => 'vcap_referral' ),
		);
		return $fields;
	}

	/** Voucher products the setting "Multi-purpose vouchers as payment" leaves out (only on the settings page). */
	/** R6: daily limits of counter (scanner) redemptions (Counter), a block of the "Privacy & advanced" section. */
	private static function counter_limit_fields(): array {
		$money = array( 'min' => 0, 'step' => '0.01' );
		$count = array( 'min' => 0, 'step' => 1 );
		return array(
			array(
				'type'  => 'title',
				'title' => __( 'Counter limits (scanner)', 'voucher-captain' ),
				'desc'  => __( 'Daily limits for redemptions at the counter, per shop day (shop time zone). 0 = unlimited. Staff who manage vouchers are not limited; a staff member can get own limits on their user profile. The amount counts gift cards and value vouchers in the currency of the voucher being redeemed.', 'voucher-captain' ),
				'id'    => 'vcap_counter_limits',
			),
			array(
				'title'             => __( 'Amount per staff member and day', 'voucher-captain' ),
				'id'                => Counter::USER_AMOUNT,
				'type'              => 'number',
				'default'           => 0,
				'css'               => 'width:90px',
				'custom_attributes' => $money,
			),
			array(
				'title'             => __( 'Redemptions per staff member and day', 'voucher-captain' ),
				'id'                => Counter::USER_COUNT,
				'type'              => 'number',
				'default'           => 0,
				'css'               => 'width:90px',
				'custom_attributes' => $count,
			),
			array(
				'title'             => __( 'Amount per till/branch and day', 'voucher-captain' ),
				'id'                => Counter::TILL_AMOUNT,
				'type'              => 'number',
				'default'           => 0,
				'css'               => 'width:90px',
				'custom_attributes' => $money,
				'desc_tip'          => __( 'Counts by the till/branch set on the scanner device; devices without one share one limit.', 'voucher-captain' ),
			),
			array(
				'title'             => __( 'Redemptions per till/branch and day', 'voucher-captain' ),
				'id'                => Counter::TILL_COUNT,
				'type'              => 'number',
				'default'           => 0,
				'css'               => 'width:90px',
				'custom_attributes' => $count,
			),
			array(
				'title'   => __( 'Receipt no. required', 'voucher-captain' ),
				'id'      => Counter::REQUIRE_RECEIPT,
				'type'    => 'select',
				'default' => 'auto',
				'options' => array(
					'auto' => __( 'Automatic (required for shops based in Germany or Austria)', 'voucher-captain' ),
					'yes'  => __( 'Required', 'voucher-captain' ),
					'no'   => __( 'Optional', 'voucher-captain' ),
				),
				'desc'    => __( 'A counter redemption is only booked with the receipt number of the till receipt for the goods (checked on the server).', 'voucher-captain' ),
			),
			array(
				'title'    => __( 'Tills / branches', 'voucher-captain' ),
				'id'       => Counter::LOCATIONS,
				'type'     => 'textarea',
				'default'  => '',
				'css'      => 'width:300px;height:90px',
				'desc'     => __( 'One per line. When set, the scanner offers this list instead of a free text field, and a staff member can be fixed to one on their user profile. The scanner works online only: without a connection nothing is booked (on purpose – an offline scanner could redeem the same code twice).', 'voucher-captain' ),
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
		$text = __( 'Remove voucher tables (including the redemption journal), templates and settings when the plugin is deleted. The WooCommerce coupons of the vouchers are moved to the trash (restorable there), other coupons are kept.', 'voucher-captain' )
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
