<?php
/**
 * Settings register (SET-VC-01): the one source for default, type, bounds, section, legal weight, export and secret
 * of every setting Voucher Captain stores. Read through VCAP\SettingsRegistry (filter "vcap_settings_registry").
 *
 * Labels and help texts stay at the fields (src/Settings.php and the classes it names); this file holds data only,
 * no translated text. Option names, defaults and stored formats are those of 1.2.0 – changing a default here
 * changes the behaviour of every shop that never saved the setting.
 *
 * settings  – option name => attributes. Missing attributes take these values:
 *             type     'string' (also: 'bool' = 'yes'/'no', 'int', 'decimal', 'text' = several lines, 'select',
 *                      'list' = array of strings, 'post' = post/page ID, 'map' = key-value array of an add-on (keys and nested values kept, text cleaned; the add-on checks the content with vcap_settings_validate_value), 'table' = list of rows, checked by
 *                      VCAP\Admin\CountryTableField::validate())
 *             default  '' – what Settings::get() returns while the option was never saved
 *             min/max  null – bounds of 'int'/'decimal', also checked on the server when the settings are saved
 *             empty    false – 'int'/'decimal' only: an empty value is a valid setting (e.g. "automatic")
 *             choices  null – allowed values of a 'select'
 *             section  'hidden' – section of the "Vouchers" settings tab: '' (General), 'redemption', 'promotions',
 *                      'codes', 'delivery', 'counter', 'privacy', 'api';
 *                      'hidden' = no field (created by the plugin)
 *             legal    false – the value has legal or tax weight (have it checked for the country)
 *             export   true (false for secrets) – part of an export of the settings (SET-VC-14)
 *             secret   false – never shown, exported or logged
 *             fresh    – value a first installation stores (Install::activate_site()); missing = the default
 * user_meta – settings per user (user profile), same attributes; 'per_site' = stored with get_user_option().
 * internal  – options that are no settings: state, counters, cursors, locks, claims, caches, versions. They are
 *             never exported or reset. internal_prefixes: the same for options named "<prefix><id>".
 *
 * Rule for new options (tests/run.php "SET-VC-01"): every option the code reads is a setting here or listed as
 * internal. Business policy is a setting with a safe default plus a filter (the filter wins); technical values stay
 * constants in the code (owner's rule S2).
 *
 * @package VCAP
 */

defined( 'ABSPATH' ) || exit;

$vcap_settings = array(
	/* ---- General: issuing and validity ---- */
	'vcap_issue_statuses'            => array( 'type' => 'list', 'default' => array( 'processing', 'completed' ), 'section' => '', 'legal' => true ),
	'vcap_default_terms'             => array( 'type' => 'text', 'section' => '', 'legal' => true ),
	// SET-VC-04: tables (list of rows); text stored before 1.3.0 is still read (VCAP\Markets::terms_rows()/expiry_rows()).
	'vcap_terms_by_country'          => array( 'type' => 'table', 'default' => array(), 'section' => '', 'legal' => true ),
	'vcap_expiry_by_country'         => array( 'type' => 'table', 'default' => array(), 'section' => '', 'legal' => true ),
	'vcap_default_template'          => array( 'type' => 'post', 'default' => 0, 'section' => '', 'export' => false ),
	// Unsaved: ProductConfig::default_expiry_rule() decides ("days" for shops that saved a number of days).
	'vcap_default_expiry_rule'       => array( 'type' => 'select', 'choices' => array( 'statutory', 'days' ), 'section' => '', 'legal' => true ),
	// PV-P2-10: at most 100 years (the product field "Valid for (days)" has the same ceiling).
	'vcap_default_expiry_days'       => array( 'type' => 'int', 'default' => 365, 'min' => 0, 'max' => 36500, 'section' => '', 'legal' => true ),
	// SET-VC-06: what happens when the validity ends (VCAP\ExpiryPolicy; filters vcap_expiry_behaviour,
	// vcap_expiry_grace_days, vcap_expiry_staff_extension_days win). Defaults = 1.2.0: expires at the date (booked off),
	// staff may extend without limit (0). Derecognition is income – not legally or tax checked.
	'vcap_expiry_behaviour'            => array( 'type' => 'select', 'default' => 'expires', 'choices' => array( 'expires', 'grace', 'never' ), 'section' => '', 'legal' => true ),
	'vcap_expiry_grace_days'           => array( 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 3650, 'section' => '', 'legal' => true ),
	'vcap_expiry_staff_extension_days' => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 36500, 'section' => '', 'legal' => true ),
	/* ---- General: abuse protection (P12, 0 switches each one off) ---- */
	'vcap_custom_max_default'        => array( 'type' => 'decimal', 'default' => 500, 'min' => 0, 'section' => '' ),
	'vcap_customer_daily_limit'      => array( 'type' => 'decimal', 'default' => 1000, 'min' => 0, 'section' => '' ),
	'vcap_hold_new_customer_above'   => array( 'type' => 'decimal', 'default' => 250, 'min' => 0, 'section' => '' ),
	'vcap_hold_hours'                => array( 'type' => 'int', 'default' => 24, 'min' => 0, 'section' => '' ),
	/* ---- General: payment methods for vouchers (SET-VC-19; filters vcap_voucher_excluded_gateways, vcap_offline_payment_methods win) ---- */
	'vcap_voucher_excluded_gateways' => array( 'type' => 'list', 'default' => array(), 'section' => '' ),
	'vcap_offline_payment_methods'   => array( 'type' => 'list', 'default' => array( 'cod', 'bacs', 'cheque' ), 'section' => '' ),
	/* ---- General: issuer and legal texts (SET-VC-11; empty = shop details at render time, placeholders only) ---- */
	'vcap_issuer_name'               => array( 'section' => '', 'legal' => true ),
	'vcap_issuer_address'            => array( 'type' => 'text', 'section' => '', 'legal' => true ),
	'vcap_issuer_contact'            => array( 'section' => '', 'legal' => true ),
	'vcap_terms_url'                 => array( 'section' => '', 'legal' => true ),
	'vcap_privacy_url'               => array( 'section' => '', 'legal' => true ),
	'vcap_withdrawal_url'            => array( 'section' => '', 'legal' => true ),
	'vcap_legal_text'                => array( 'type' => 'text', 'section' => '', 'legal' => true ),
	/* ---- General: currencies (SET-VC-32; filter vcap_vouchers_shop_currency_only wins) ---- */
	'vcap_vouchers_shop_currency_only' => array( 'type' => 'bool', 'default' => 'no', 'section' => '' ),
	/* ---- General: consent checkbox (SET-VC-12, E6; off = no checkbox as in 1.2.0; filters vcap_consent_required, vcap_consent_text win) ---- */
	// Products choose "use global" / "always" / "never" (product meta _vcap_consent). Text and effect not legally checked.
	'vcap_consent_enabled'           => array( 'type' => 'bool', 'default' => 'no', 'section' => '', 'legal' => true ),
	'vcap_consent_text'              => array( 'type' => 'text', 'default' => '', 'section' => '', 'legal' => true ),

	/* ---- Codes ---- */
	'vcap_code_prefix'               => array( 'section' => 'codes' ),
	'vcap_code_suffix'               => array( 'section' => 'codes' ),
	'vcap_code_length'               => array( 'type' => 'int', 'default' => 12, 'min' => 8, 'max' => 40, 'section' => 'codes' ),
	'vcap_code_charset'              => array( 'type' => 'select', 'default' => 'alnum_upper', 'choices' => array( 'alnum_upper', 'alpha_upper', 'numeric', 'alnum' ), 'section' => 'codes' ),
	'vcap_code_separator'            => array( 'default' => '-', 'section' => 'codes' ),
	'vcap_code_order_ref'            => array( 'type' => 'bool', 'default' => 'no', 'section' => 'codes' ),
	/* ---- Codes: sequential voucher number (SET-VC-13, E9 a: off by default; filter vcap_voucher_number_enabled wins) ---- */
	'vcap_voucher_number_enabled'    => array( 'type' => 'bool', 'default' => 'no', 'section' => 'codes', 'legal' => true ),
	'vcap_voucher_number_pattern'    => array( 'default' => '{year}-{number}', 'section' => 'codes' ),
	'vcap_voucher_number_prefix'     => array( 'section' => 'codes' ),
	'vcap_voucher_number_digits'     => array( 'type' => 'int', 'default' => 5, 'min' => 1, 'max' => 12, 'section' => 'codes' ),
	'vcap_voucher_number_reset'      => array( 'type' => 'select', 'default' => 'yearly', 'choices' => array( 'yearly', 'never' ), 'section' => 'codes' ),

	/* ---- Redemption & balance ---- */
	'vcap_redeem_page_id'            => array( 'type' => 'post', 'section' => 'redemption', 'export' => false ),
	'vcap_restore_on_cancel'         => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'redemption' ),
	// M12-03: missing = off (existing shops), a first installation stores "yes".
	'vcap_mpv_after_tax'             => array( 'type' => 'bool', 'default' => 'no', 'fresh' => 'yes', 'section' => 'redemption', 'legal' => true ),
	'vcap_void_on_refund'            => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'redemption' ),
	'vcap_store_credit'              => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption' ),
	'vcap_credit_with_coupons'       => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'redemption' ),
	'vcap_credit_apply_order'        => array( 'type' => 'select', 'default' => 'last', 'choices' => array( 'last', 'first' ), 'section' => 'redemption' ),
	'vcap_vouchers_buy_vouchers'     => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption' ),
	// M12-95: storefront conveniences, off by default (existing shops keep their storefront).
	'vcap_giftcard_field'            => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption' ),
	'vcap_minicart_balance'          => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption' ),
	// SET-VC-18 (1.12 no. 19–21): top-up and counter time windows; defaults = the fixed values of 1.2.0, the filters
	// of the same name get the setting and win.
	'vcap_topup_min_remaining_days'  => array( 'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 3650, 'section' => 'redemption' ),
	'vcap_checkin_undo_minutes'      => array( 'type' => 'int', 'default' => 10, 'min' => 0, 'max' => 1440, 'section' => 'redemption' ),
	'vcap_counter_reversal_minutes'  => array( 'type' => 'int', 'default' => 15, 'min' => 0, 'max' => 1440, 'section' => 'redemption' ),
	// SET-VC-31 (1.12 no. 22, 24): limits against code guessing. The floor (min) keeps every protection on – 0 would
	// switch a limit off (Plugin::rate_limited()); the ceiling keeps it meaningful. Windows stay constants.
	'vcap_code_attempts_per_visitor' => array( 'type' => 'int', 'default' => 15, 'min' => 3, 'max' => 100, 'section' => 'redemption' ),
	'vcap_code_attempts_site_wide'   => array( 'type' => 'int', 'default' => 300, 'min' => 50, 'max' => 10000, 'section' => 'redemption' ),
	'vcap_email_confirm_per_hour'    => array( 'type' => 'int', 'default' => 3, 'min' => 1, 'max' => 10, 'section' => 'redemption' ),
	'vcap_email_confirm_link_hours'  => array( 'type' => 'int', 'default' => 48, 'min' => 1, 'max' => 168, 'section' => 'redemption' ),
	// SET-VC-05 (1.12 no. 1–7): refund and cancellation rules; defaults = the fixed filter defaults of 1.2.0, the
	// filters of the same name get the setting and win. Legal/tax weight: help texts not legally checked.
	'vcap_allow_refund_of_spent_vouchers' => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption', 'legal' => true ),
	'vcap_allow_refund_of_spent_top_ups'  => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption', 'legal' => true ),
	'vcap_allow_refund_of_used_bonus'     => array( 'type' => 'bool', 'default' => 'no', 'section' => 'redemption', 'legal' => true ),
	'vcap_refund_guest_as_gift_card'      => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'redemption', 'legal' => true ),
	'vcap_keep_status_on_partial_return'  => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'redemption', 'legal' => true ),
	'vcap_restore_extension_days'         => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'max' => 3650, 'section' => 'redemption', 'legal' => true ),
	// Paid statuses only (empty = default), resp. cancelled/refunded/failed only (empty = all three): Coupons\Settlement.
	'vcap_balance_deduct_statuses'        => array( 'type' => 'list', 'default' => array( 'processing', 'completed' ), 'section' => 'redemption', 'legal' => true ),
	'vcap_balance_restore_statuses'       => array( 'type' => 'list', 'default' => array( 'cancelled', 'refunded', 'failed' ), 'section' => 'redemption', 'legal' => true ),

	/* ---- Promotions & refer a friend ---- */
	'vcap_campaigns_enabled'         => array( 'type' => 'bool', 'default' => 'no', 'section' => 'promotions' ),
	'vcap_bonus_wait_days'           => array( 'type' => 'int', 'default' => 14, 'min' => 0, 'section' => 'promotions' ),
	'vcap_discounts_on_vouchers'     => array( 'type' => 'bool', 'default' => 'no', 'section' => 'promotions' ),
	'vcap_coupon_link'               => array( 'type' => 'bool', 'default' => 'no', 'section' => 'promotions' ),
	// SET-VC-24 (E10 a): shops that never saved it keep "gutschein"; a first installation stores the parameter of the site
	// language instead (Install::activate_site() → CouponLink::fresh_param(): de* "gutschein", else "voucher"). The old
	// parameter "gutschein" always stays valid as an alias (CouponLink::params()).
	'vcap_coupon_link_param'         => array( 'default' => 'gutschein', 'section' => 'promotions' ),
	// SET-VC-24: prefixes of generated bonus and referral codes; defaults = the fixed prefixes of 1.2.0, the filters
	// vcap_bonus_code_prefix / vcap_referral_code_prefix get the setting and win. '' = no prefix.
	'vcap_bonus_code_prefix'         => array( 'default' => 'BONUS', 'section' => 'promotions' ),
	'vcap_referral_code_prefix'      => array( 'default' => 'FRIEND', 'section' => 'promotions' ),
	'vcap_referral_enabled'          => array( 'type' => 'bool', 'default' => 'no', 'section' => 'promotions' ),
	'vcap_referral_friend_amount'    => array( 'type' => 'decimal', 'default' => 10, 'min' => 0, 'section' => 'promotions' ),
	'vcap_referral_friend_type'      => array( 'type' => 'select', 'default' => 'fixed_cart', 'choices' => array( 'fixed_cart', 'percent' ), 'section' => 'promotions' ),
	// V3: empty = automatic (twice a fixed friend discount); a fixed discount always needs a higher order value.
	'vcap_referral_min_spend'        => array( 'type' => 'decimal', 'default' => '', 'min' => 0, 'empty' => true, 'section' => 'promotions' ),
	'vcap_referral_reward_amount'    => array( 'type' => 'decimal', 'default' => 10, 'min' => 0, 'section' => 'promotions' ),
	'vcap_referral_reward_as'        => array( 'type' => 'select', 'default' => 'coupon', 'choices' => array( 'coupon', 'credit' ), 'section' => 'promotions' ),
	'vcap_referral_reward_days'      => array( 'type' => 'int', 'default' => 90, 'min' => 0, 'section' => 'promotions' ),
	'vcap_referral_wait_days'        => array( 'type' => 'int', 'default' => 14, 'min' => 0, 'section' => 'promotions' ),
	'vcap_referral_max_rewards'      => array( 'type' => 'int', 'default' => 10, 'min' => 0, 'section' => 'promotions' ),
	'vcap_referral_address_check'    => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'promotions' ),

	/* ---- Delivery & downloads ---- */
	'vcap_attach_pdf'                => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	'vcap_gift_toggle'               => array( 'type' => 'bool', 'default' => 'no', 'section' => 'delivery' ),
	// Stored as "yes" on a first installation; existing shops keep "no".
	'vcap_new_product_delivery'      => array( 'type' => 'bool', 'default' => 'no', 'fresh' => 'yes', 'section' => 'delivery' ),
	'vcap_include_link'              => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	// V10: for shops that never saved it; a saved value (also 0) is kept.
	'vcap_download_link_days'        => array( 'type' => 'int', 'default' => 90, 'min' => 0, 'section' => 'delivery' ),
	'vcap_delivery_hour'             => array( 'type' => 'int', 'default' => 8, 'min' => 0, 'max' => 23, 'section' => 'delivery' ),
	// M12-84: "30, 7, 1" (largest first) or "0"; normalised by its own sanitizer, so it is a text setting.
	'vcap_expiry_reminder_days'      => array( 'default' => 0, 'section' => 'delivery' ),
	'vcap_show_thankyou'             => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	'vcap_show_myaccount'            => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	'vcap_myaccount_tab'             => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	'vcap_pdf_filename'              => array( 'default' => 'voucher-{coupon_code}', 'section' => 'delivery' ),
	'vcap_date_format'               => array( 'section' => 'delivery' ),
	// Z6: bundled delivery of large quantities (0 switches each one off); M12-48: new installations attach up to 20.
	'vcap_bundle_threshold'          => array( 'type' => 'int', 'default' => 5, 'min' => 0, 'section' => 'delivery' ),
	'vcap_bundle_pdf_limit'          => array( 'type' => 'int', 'default' => 50, 'fresh' => 20, 'min' => 0, 'section' => 'delivery' ),
	// SET-VC-18 (1.12 no. 16–18): customer self-service; defaults = the fixed values of 1.2.0, the filters of the same
	// name get the setting and win. At most Downloads::FIX_MAX (2) corrections, as before.
	'vcap_customer_can_resend'       => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	'vcap_customer_can_change'       => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'delivery' ),
	'vcap_recipient_fix_days'        => array( 'type' => 'int', 'default' => 30, 'min' => 1, 'max' => 365, 'section' => 'delivery' ),
	'vcap_recipient_fix_max'         => array( 'type' => 'int', 'default' => 2, 'min' => 0, 'max' => 2, 'section' => 'delivery' ),
	'vcap_printed_link_grace_days'   => array( 'type' => 'int', 'default' => 30, 'min' => 0, 'max' => 3650, 'section' => 'delivery' ),
	// SET-VC-29: '' = the WooCommerce email settings (base colour, header image) – shops look as before.
	'vcap_email_color'               => array( 'section' => 'delivery' ),
	'vcap_email_logo'                => array( 'section' => 'delivery' ),
	// SET-VC-30: own CSS for the redeem form and the account area (stored cleaned, see VCAP\CustomCss).
	'vcap_custom_css'                => array( 'type' => 'text', 'section' => 'delivery' ),

	/* ---- Privacy & advanced ---- */
	// SET-VC-08 (block "Retention"; 1.12 no. 25, 26, 28, 29, 31, 34): periods with legal weight, not legally checked.
	// Defaults = the fixed values of 1.2.0 (owner decision S9: recipient data stays 0 = never, Site Health warns); the
	// filters of the same name (vcap_command_log_days, vcap_scanner_failed_lookup_days: new) get the setting and win.
	'vcap_retention_days'            => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'section' => 'privacy', 'legal' => true ),
	'vcap_voucher_requests_open_months'   => array( 'type' => 'int', 'default' => \VCAP\Integrations\VoucherRequests::RETENTION_MONTHS['open'], 'min' => 1, 'max' => 120, 'section' => 'privacy', 'legal' => true ),
	'vcap_voucher_requests_closed_months' => array( 'type' => 'int', 'default' => \VCAP\Integrations\VoucherRequests::RETENTION_MONTHS['closed'], 'min' => 1, 'max' => 120, 'section' => 'privacy', 'legal' => true ),
	'vcap_review_retention_months'        => array( 'type' => 'int', 'default' => \VCAP\Integrations\ReviewSource::RETENTION_MONTHS, 'min' => 1, 'max' => 120, 'section' => 'privacy', 'legal' => true ),
	'vcap_review_revoked_retention_days'  => array( 'type' => 'int', 'default' => \VCAP\Integrations\ReviewSource::REVOKED_DAYS, 'min' => 30, 'max' => 3650, 'section' => 'privacy', 'legal' => true ),
	'vcap_command_log_days'               => array( 'type' => 'int', 'default' => \VCAP\API\V1\OptionCommandStore::LOG_DAYS, 'min' => 30, 'max' => 3650, 'section' => 'privacy', 'legal' => true ),
	'vcap_scanner_failed_lookup_days'     => array( 'type' => 'int', 'default' => \VCAP\Counter::FAILED_DAYS, 'min' => 31, 'max' => 3650, 'section' => 'privacy', 'legal' => true ),
	'vcap_ticket_public_status'      => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'privacy' ),
	'vcap_scanner_show_holder'       => array( 'type' => 'bool', 'default' => 'no', 'section' => 'privacy', 'legal' => true ),
	'vcap_subscription_renewals'     => array( 'type' => 'bool', 'default' => 'no', 'section' => 'privacy' ),
	// M12-67 / SET-VC-S10: never part of an export (switching it on needs the typed word).
	'vcap_delete_on_uninstall'       => array( 'type' => 'bool', 'default' => 'no', 'section' => 'privacy', 'legal' => true, 'export' => false ),
	// Switches itself off after Logger::VERBOSE_HOURS; a support aid, not exported.
	'vcap_debug_log'                 => array( 'type' => 'bool', 'default' => 'no', 'section' => 'privacy', 'export' => false ),
	// SET-VC-26: header with the visitor address ('none' = REMOTE_ADDR only); the filter vcap_client_ip wins.
	'vcap_proxy_header'              => array( 'type' => 'select', 'default' => 'none', 'choices' => array( 'none', 'cloudflare', 'xff_last' ), 'section' => 'privacy' ),
	// SET-VC-16: days the change log of the settings is kept (0 = until the entry limit SettingsLog::MAX_ENTRIES).
	'vcap_settings_log_days'         => array( 'type' => 'int', 'default' => 365, 'min' => 0, 'section' => 'privacy' ),

	/* ---- Counter & locations (SET-VC-22: moved from "Privacy & advanced", option names unchanged) ---- */
	// R6: daily limits of counter (scanner) redemptions, off by default (0 = unlimited).
	'vcap_counter_limit_amount'      => array( 'type' => 'decimal', 'default' => 0, 'min' => 0, 'section' => 'counter' ),
	'vcap_counter_limit_count'       => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'section' => 'counter' ),
	'vcap_counter_till_limit_amount' => array( 'type' => 'decimal', 'default' => 0, 'min' => 0, 'section' => 'counter' ),
	'vcap_counter_till_limit_count'  => array( 'type' => 'int', 'default' => 0, 'min' => 0, 'section' => 'counter' ),
	// M12-31: receipt number of the till required at the counter ('auto' = shops based in Germany or Austria).
	'vcap_counter_require_receipt'   => array( 'type' => 'select', 'default' => 'auto', 'choices' => array( 'auto', 'yes', 'no' ), 'section' => 'counter', 'legal' => true ),
	// M12-81: managed list of tills/branches ('' = free text on the scanner device). SET-VC-22: a JSON list of rows
	// (name, key, active, columns of add-ons – CounterLocations); one name per line (1.2.0) is still read.
	'vcap_counter_locations'         => array( 'type' => 'text', 'section' => 'counter' ),

	/* ---- Integrations (API) ---- */
	'vcap_redemption_ttl'            => array( 'type' => 'int', 'default' => 15, 'min' => 1, 'max' => 1440, 'section' => 'api' ),
	'vcap_redemption_max_failures'   => array( 'type' => 'int', 'default' => 10, 'min' => 0, 'section' => 'api' ),
	// SET-VC-31 (1.12 no. 23): invalid codes per 15 minutes across all API callers (brake); floor as above.
	'vcap_redemption_site_wide_failures' => array( 'type' => 'int', 'default' => 300, 'min' => 50, 'max' => 10000, 'section' => 'api' ),
	// SET-VC-20: accounting export, country-neutral (block "Accounting export", AccountingExport). The three accounts
	// keep their names of 1.2.0 and serve the journal CSV and DATEV (digits only). Defaults = output of 1.2.0.
	'vcap_datev_liability_account'   => array( 'section' => 'api', 'legal' => true ),
	'vcap_datev_clearing_account'    => array( 'section' => 'api', 'legal' => true ),
	'vcap_datev_income_account'      => array( 'section' => 'api', 'legal' => true ),
	'vcap_journal_account_columns'   => array( 'type' => 'bool', 'default' => 'no', 'section' => 'api', 'legal' => true ),
	// Start of the financial year (MMDD) for every export; name of 1.2.0 kept, field now in the general block.
	'vcap_datev_fy_start'            => array( 'default' => '0101', 'section' => 'api', 'legal' => true ),
	// '' = automatic by the exporting user's language (Csv::format()); the filter vcap_csv_format wins.
	'vcap_csv_separator'             => array( 'type' => 'select', 'default' => '', 'choices' => array( '', ';', ',', 'tab' ), 'section' => 'api' ),
	'vcap_csv_decimal'               => array( 'type' => 'select', 'default' => '', 'choices' => array( '', ',', '.' ), 'section' => 'api' ),
	'vcap_csv_charset'               => array( 'type' => 'select', 'default' => 'utf-8-bom', 'choices' => array( 'utf-8-bom', 'utf-8', 'windows-1252' ), 'section' => 'api' ),
	'vcap_csv_date_format'           => array( 'type' => 'select', 'default' => 'Y-m-d', 'choices' => array( 'Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y' ), 'section' => 'api' ),
	// DATEV block: 'auto' = shown for the country profile DE, shops based in Germany and shops with DATEV values
	// saved (AccountingExport::show_datev()); 'yes' always, 'no' never (values are kept).
	'vcap_show_datev'                => array( 'type' => 'select', 'default' => 'auto', 'choices' => array( 'auto', 'yes', 'no' ), 'section' => 'api' ),
	// M12-69: DATEV export (digits only; DatevExport::config() strips anything else). Block shown per vcap_show_datev.
	'vcap_datev_consultant'          => array( 'section' => 'api' ),
	'vcap_datev_client'              => array( 'section' => 'api' ),
	'vcap_datev_account_length'      => array( 'type' => 'int', 'default' => 4, 'min' => 4, 'max' => 9, 'section' => 'api' ),
	// VOUCH-WF-15: contact read function, off by default.
	'vcap_contact_read_enabled'      => array( 'type' => 'bool', 'default' => 'no', 'section' => 'api', 'legal' => true ),
	// SET-VC-33 (1.12 no. 12, 35, 38–40): rules of physical cards and partner plugins; defaults = the fixed values of
	// 1.2.0, the filters of the same name get the setting and win. Partner fields are shown only while the partner
	// plugin is active (Settings::partner_active()).
	'vcap_physical_activation_statuses'          => array( 'type' => 'list', 'default' => array( 'completed' ), 'section' => 'api' ),
	'vcap_review_first_redemption_only'          => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'api' ),
	'vcap_affiliate_commission_on_voucher_sales' => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'api' ),
	'vcap_passcaptain_restore_on_revoke'         => array( 'type' => 'bool', 'default' => 'yes', 'section' => 'api' ),
	'vcap_subscription_issue_on_switch'          => array( 'type' => 'bool', 'default' => 'no', 'section' => 'api' ),

	/* ---- Created by the plugin (no field) ---- */
	// Key of signed links: created once, never replaced (Settings::secret()).
	'vcap_secret'                    => array( 'secret' => true ),
	// Key of the customer email hash (CustomerEmail).
	'vcap_customer_key_secret'       => array( 'secret' => true ),
);

// VC-1: the four voucher commands. The fixed table (CommandLimits::FIXED) is the default and the ceiling; an empty
// count means the ceiling (CommandLimits::sanitize_count()), an empty amount 100 or "refused" (F-05a).
foreach ( \VCAP\API\V1\CommandLimits::FIXED as $vcap_command => $vcap_fixed ) {
	$vcap_settings[ \VCAP\API\V1\CommandLimits::option( $vcap_command, 'enabled' ) ] = array( 'type' => 'bool', 'default' => 'no', 'section' => 'api' );
	$vcap_settings[ \VCAP\API\V1\CommandLimits::option( $vcap_command, 'per_day' ) ] = array( 'type' => 'int', 'default' => (string) $vcap_fixed['per_day'], 'min' => 0, 'max' => $vcap_fixed['per_day'], 'empty' => true, 'section' => 'api' );
	if ( null !== $vcap_fixed['per_recipient'] ) {
		$vcap_settings[ \VCAP\API\V1\CommandLimits::option( $vcap_command, 'per_recipient' ) ] = array( 'type' => 'int', 'default' => (string) $vcap_fixed['per_recipient'], 'min' => 0, 'max' => $vcap_fixed['per_recipient'], 'empty' => true, 'section' => 'api' );
	}
	if ( null !== $vcap_fixed['max_amount_minor'] ) {
		// No fixed maximum here: the ceiling depends on the shop currency (CommandLimits::sanitize_amount()).
		$vcap_settings[ \VCAP\API\V1\CommandLimits::option( $vcap_command, 'max_amount' ) ] = array( 'type' => 'decimal', 'default' => '', 'min' => 0, 'empty' => true, 'section' => 'api' );
		// PV-P3-4: currency of the maximum amount (read-only field; written on save, exported with the amount so an
		// import into a shop with another currency does not carry the number over).
		$vcap_settings[ \VCAP\API\V1\CommandLimits::option( $vcap_command, 'max_amount_currency' ) ] = array( 'section' => 'api' );
	}
}

return array(
	'settings'          => $vcap_settings,
	'user_meta'         => array(
		// R6 / M12-81: own counter limits and fixed till of a staff member (user profile, per site; '' = shop limit).
		'vcap_counter_limit_amount' => array( 'type' => 'decimal', 'default' => '', 'min' => 0, 'empty' => true, 'per_site' => true ),
		'vcap_counter_limit_count'  => array( 'type' => 'int', 'default' => '', 'min' => 0, 'empty' => true, 'per_site' => true ),
		'vcap_counter_location'     => array( 'per_site' => true ),
		// SET-VC-07: '1' = the user hid the hint lines of add-on functions.
		'vcap_hide_pro_hints'       => array( 'type' => 'select', 'default' => '0', 'choices' => array( '0', '1' ), 'export' => false ),
	),
	'internal'          => array(
		// Versions, schema and installation state (Install).
		'vcap_db_version',
		'vcap_schema_error',
		'vcap_schema_step',
		'vcap_schedules_checked',
		'vcap_tables_innodb',
		'vcap_restore_tasks',
		'vcap_deactivated_at',
		'vcap_catch_up',
		'vcap_network_deactivating',
		'vcap_license_data_removed',
		'vcap_legacy_throttle_rows',
		'vcap_legacy_rows',
		'vcap_storage_version',
		'vcap_event_till_cursor',
		'vcap_event_hash_cursor',
		'vcap_suspended_coupons',
		'vcap_private_dir',
		'vcap_upload_dir_error',
		'vcap_stale_release_cursor',
		'vcap_flush_rewrite',
		'vcap_needs_default_template',
		'vcap_fc_requests_db_version',
		'vcap_fc_forget_unsupported',
		// SET-VC-21: 'yes' once the table "Rights per role" was saved (upgrades then keep the assignment).
		'vcap_role_caps_customised',
		// "Since" dates that must never change (Settings::insert_once()).
		'vcap_routing_fixed_since',
		'vcap_email_proof_since',
		// Notices, onboarding and health.
		'vcap_attention',
		'vcap_passcaptain_attention',
		'vcap_onboarding_dismissed',
		'vcap_onboarding_done',
		'vcap_validity_choice',
		// SET-VC-02: the country profile applied last (id, time, user).
		'vcap_country_profile',
		'vcap_mail_failures',
		'vcap_debug_log_until',
		// Counters, locks and bookkeeping of running work.
		'vcap_daily_limit_refusals',
		'vcap_usage_reconcile_due',
		'vcap_rate_limit_hits',
		'vcap_scanner_failed_lookups',
		'vcap_pins_in_use',
		'vcap_cmd_days',
		// PV-P4-4: last day whose per-recipient counters of the voucher commands were deleted.
		'vcap_cmd_recipients_until',
		'vcap_affiliate_campaigns',
		'vcap_fx_unknown',
		'vcap_fx_held_orders',
		// SET-VC-14/15/16: automatic backups of the settings and the change log (never autoloaded).
		'vcap_settings_backups',
		'vcap_settings_log',
		// SET-VC-13: counter of the sequential voucher numbers (restart "never"; per year: prefix below).
		'vcap_voucher_number_seq',
	),
	'internal_prefixes' => array(
		'vcap_cmd_count_',
		'vcap_cmd_log_',
		'vcap_cmd_reply_',
		'vcap_reversal_',
		'vcap_template_archive_',
		'vcap_maintenance_run_',
		'vcap_review_ref_',
		'vcap_review_voucher_',
		'vcap_review_claim_',
		'vcap_request_ref_',
		'vcap_pin_',
		'vcap_job_',
		'vcap_rl_',
		'vcap_voucher_number_seq_',
	),
);
