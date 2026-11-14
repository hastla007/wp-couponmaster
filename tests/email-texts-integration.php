<?php
/**
 * Default texts of the voucher emails fit the voucher (EMAILS.md): no "Hi ," without a name, no order number
 * for vouchers without an order, no value for ticket vouchers, the discount for percentage vouchers; a text the
 * shop wrote itself is always kept.
 *   wp eval-file tests/email-texts-integration.php
 */

function vcap_mail_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

$captured = array();
$capture  = static function ( $short, $atts ) use ( &$captured ) {
	$captured[] = $atts;
	return true; // Do not send.
};
add_filter( 'pre_wp_mail', $capture, 10, 2 );
update_option( 'vcap_attach_pdf', 'no' );

/** Sends one email for a voucher and returns [subject, plain-text body]. */
function vcap_mail_send( string $class, VCAP\Voucher $voucher ): array {
	$emails = WC()->mailer()->get_emails();
	$email  = $emails[ $class ];
	$email->settings['email_type'] = 'plain';
	$email->settings['enabled']    = 'yes';
	$before                         = count( $GLOBALS['vcap_mail_captured'] );
	$email->trigger( $voucher );
	$last = end( $GLOBALS['vcap_mail_captured'] );
	return array( (string) ( $last['subject'] ?? '' ), wp_strip_all_tags( (string) ( $last['message'] ?? '' ) ), count( $GLOBALS['vcap_mail_captured'] ) > $before );
}
$GLOBALS['vcap_mail_captured'] = &$captured;

// M4 / M9 (first in this suite: the template lookup is cached per request): staff with an English profile on a German site. Default PDF templates are created and admin PDFs rendered in
// the site language. The test site has no core language packs: de_DE is made "available" for switch_to_locale().
global $wpdb;
$lang_available = static fn( $languages ) => array_values( array_unique( array_merge( (array) $languages, array( 'de_DE' ) ) ) );
add_filter( 'get_available_languages', $lang_available );
$lang_switcher_before          = $GLOBALS['wp_locale_switcher'];
$GLOBALS['wp_locale_switcher'] = new WP_Locale_Switcher();
$GLOBALS['wp_locale_switcher']->init();
$lang_wplang = get_option( 'WPLANG', '' );
update_option( 'WPLANG', 'de_DE' );
unset( $GLOBALS['locale'] );
$lang_admin = (int) wp_insert_user( array( 'user_login' => 'vcap-lang-' . wp_generate_password( 6, false, false ), 'user_pass' => wp_generate_password( 20 ), 'role' => 'administrator', 'locale' => 'en_US' ) );
wp_set_current_user( $lang_admin );
require_once ABSPATH . 'wp-admin/includes/class-wp-screen.php';
require_once ABSPATH . 'wp-admin/includes/screen.php';
set_current_screen( 'edit-vcap_template' ); // wp-admin: translations follow the profile language (determine_locale()).
unload_textdomain( 'voucher-captain' );
load_plugin_textdomain( 'voucher-captain', false, dirname( plugin_basename( VCAP_FILE ) ) . '/languages' );
vcap_mail_assert( 'de_DE' === get_locale() && 'en_US' === determine_locale() && 'GIFT VOUCHER' === __( 'GIFT VOUCHER', 'voucher-captain' ), 'M4: (setup) German site, English admin profile, plugin texts in English' );

// M4: no template yet (hidden from the lookup) → the defaults are created with German texts.
$lang_hide = static function ( $query ) {
	if ( VCAP\TemplatePostType::POST_TYPE === $query->get( 'post_type' ) ) {
		$query->set( 'post__in', array( 0 ) );
	}
};
add_action( 'pre_get_posts', $lang_hide );
$lang_default_before = get_option( 'vcap_default_template', false );
$lang_last_id        = (int) $wpdb->get_var( "SELECT MAX(ID) FROM {$wpdb->posts}" ); // phpcs:ignore
update_option( 'vcap_needs_default_template', 'yes' );
( new VCAP\TemplatePostType() )->maybe_create_defaults();
remove_action( 'pre_get_posts', $lang_hide );
$lang_created = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID", VCAP\TemplatePostType::POST_TYPE, $lang_last_id ) ); // phpcs:ignore
$lang_design  = $lang_created ? implode( "\n", array_column( (array) ( json_decode( (string) get_post_meta( (int) $lang_created[0], VCAP\TemplatePostType::META, true ), true )['elements'] ?? array() ), 'text' ) ) : '';
vcap_mail_assert( 2 === count( $lang_created ) && 'Geschenkkarte (A5 quer)' === get_the_title( (int) $lang_created[0] ) && str_contains( $lang_design, 'GESCHENKGUTSCHEIN' ) && str_contains( $lang_design, 'Gültig bis {expiry_date}' ) && ! str_contains( $lang_design, 'GIFT VOUCHER' ), 'M4: default PDF templates are created in the site language, not in the admin\'s profile language' );
vcap_mail_assert( 'en_US' === determine_locale() && 'GIFT VOUCHER' === __( 'GIFT VOUCHER', 'voucher-captain' ), 'M4: the admin\'s language is restored afterwards' );
foreach ( $lang_created as $lang_id ) {
	wp_delete_post( (int) $lang_id, true );
}
false === $lang_default_before ? delete_option( 'vcap_default_template' ) : update_option( 'vcap_default_template', $lang_default_before );

$lang_voucher = vcap_mail_voucher( array() );
// M9: the admin PDF of a customer voucher is rendered in the site (order) language.
$lang_seen = array();
$lang_stop = static function ( $template_id ) use ( &$lang_seen ) {
	$lang_seen = array( determine_locale(), __( 'Valid until {expiry_date}', 'voucher-captain' ) );
	throw new RuntimeException( 'vcap-lang-stop' ); // Stop before the PDF is streamed (exit).
};
add_filter( 'vcap_render_template_id', $lang_stop );
$lang_serve = new ReflectionMethod( VCAP\Downloads::class, 'serve' );
$lang_serve->setAccessible( true );
try {
	$lang_serve->invoke( vcap()->downloads, vcap()->vouchers->get( $lang_voucher->id ), false, false );
} catch ( RuntimeException $e ) {
	$lang_stopped = 'vcap-lang-stop' === $e->getMessage();
}
remove_filter( 'vcap_render_template_id', $lang_stop );
vcap_mail_assert( ! empty( $lang_stopped ) && array( 'de_DE', 'Gültig bis {expiry_date}' ) === $lang_seen, 'M9: the admin PDF is rendered in the site language (de_DE), not in the admin\'s profile language' );
vcap_mail_assert( 'en_US' === determine_locale() && 'GIFT VOUCHER' === __( 'GIFT VOUCHER', 'voucher-captain' ), 'M9: the admin\'s language is restored after rendering' );

// Back to the test site's language.
$GLOBALS['current_screen'] = null;
update_option( 'WPLANG', $lang_wplang );
unset( $GLOBALS['locale'] );
$GLOBALS['wp_locale_switcher'] = $lang_switcher_before;
remove_filter( 'get_available_languages', $lang_available );
unload_textdomain( 'voucher-captain' );
load_plugin_textdomain( 'voucher-captain', false, dirname( plugin_basename( VCAP_FILE ) ) . '/languages' );
wp_set_current_user( 0 );

// M12: the buyer name follows the name order of the shop language (WooCommerce's "full name" format).
$lang_order_name = static fn( $translation, $text, $context, $domain ) => ( 'full name' === $context && 'woocommerce' === $domain ) ? '%2$s %1$s' : $translation;
add_filter( 'gettext_with_context', $lang_order_name, 10, 4 );
$m12_order = wc_create_order();
$m12_product = new WC_Product_Simple();
$m12_product->set_name( 'M12 voucher' );
$m12_product->set_regular_price( '10' );
$m12_product->set_virtual( true );
$m12_product->update_meta_data( '_vcap_enabled', 'yes' );
$m12_product->save();
$m12_order->add_product( $m12_product, 1 );
$m12_order->set_billing_first_name( 'János' );
$m12_order->set_billing_last_name( 'Kovács' );
$m12_order->set_billing_email( 'm12@example.com' );
$m12_order->calculate_totals();
$m12_order->save();
$m12_issued = vcap()->issuer->issue_for_order( $m12_order );
remove_filter( 'gettext_with_context', $lang_order_name, 10 );
vcap_mail_assert( $m12_issued && 'Kovács János' === vcap()->vouchers->get( $m12_issued[0]->id )->buyer_name, 'M12: the buyer name uses the localised name order (get_formatted_billing_full_name)' );


function vcap_mail_voucher( array $props ): VCAP\Voucher {
	$product = new WC_Product_Simple();
	$product->set_name( $props['product'] ?? 'Gift voucher' );
	$product->set_regular_price( '30' );
	$product->set_virtual( true );
	$product->update_meta_data( '_vcap_enabled', 'yes' );
	$product->save();
	$v                 = new VCAP\Voucher();
	$v->code           = 'MAIL-' . strtoupper( wp_generate_password( 8, false, false ) );
	$v->order_item_id  = 99000000 + random_int( 1, 900000 );
	$v->product_id     = $product->get_id();
	$v->type           = $props['type'] ?? 'voucher';
	$v->discount_type  = $props['discount_type'] ?? 'fixed_cart';
	$v->initial_amount = (float) ( $props['amount'] ?? 30 );
	$v->balance        = (float) ( $props['amount'] ?? 30 );
	$v->currency       = get_woocommerce_currency();
	$v->buyer_name     = $props['buyer_name'] ?? '';
	$v->buyer_email    = 'mail-buyer@example.com';
	$v->recipient_name = $props['recipient_name'] ?? '';
	$v->recipient_email = 'mail-recipient@example.com';
	$v->order_id       = (int) ( $props['order_id'] ?? 0 );
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return vcap()->vouchers->get( $v->id );
}

// Buyer email with name and order: the full default text.
$order = wc_create_order();
$order->set_billing_first_name( 'Anna' );
$order->set_billing_last_name( 'Buyer' );
$order->set_billing_email( 'mail-buyer@example.com' );
$order->save();
list( $subject, $body, $sent ) = vcap_mail_send( 'VCAP_Email_Voucher_Buyer', vcap_mail_voucher( array( 'buyer_name' => 'Anna Buyer', 'order_id' => $order->get_id(), 'product' => 'Spa day' ) ) );
vcap_mail_assert( $sent && str_contains( $body, 'Hi Anna Buyer, thank you for your order #' . $order->get_order_number() . '. Your voucher for Spa day is ready.' ), 'buyer email greets by name and names the order', $body );

// No stored name: the order's billing name is used.
list( , $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Buyer', vcap_mail_voucher( array( 'order_id' => $order->get_id(), 'product' => 'Spa day' ) ) );
vcap_mail_assert( str_contains( $body, 'Hi Anna Buyer,' ), 'without a stored name the billing name of the order is used', $body );

// Voucher created by hand: no name, no order.
list( , $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Buyer', vcap_mail_voucher( array( 'product' => 'Spa day' ) ) );
vcap_mail_assert( str_contains( $body, 'Hello, your voucher for Spa day is ready.' ) && ! str_contains( $body, 'Hi ,' ) && ! str_contains( $body, 'order #' ), 'without name and order there is no empty greeting and no "order #"', $body );

// Gift recipient, value voucher.
list( $subject, $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Recipient', vcap_mail_voucher( array( 'buyer_name' => 'Max', 'recipient_name' => 'Lea', 'amount' => 30 ) ) );
vcap_mail_assert( str_starts_with( $subject, 'Max sent you a gift from' ) && str_contains( $body, 'Hi Lea, Max sent you a voucher worth' ), 'recipient email names giver and value', array( $subject, $body ) );

// Gift recipient, percentage voucher: the discount, not a "value".
list( , $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Recipient', vcap_mail_voucher( array( 'buyer_name' => 'Max', 'recipient_name' => 'Lea', 'discount_type' => 'percent', 'amount' => 15 ) ) );
vcap_mail_assert( str_contains( $body, 'Max sent you a voucher for 15% off.' ) && ! str_contains( $body, 'worth' ), 'percentage voucher speaks of the discount', $body );

// Gift recipient, ticket voucher without monetary value: the product, never "worth 0".
list( , $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Recipient', vcap_mail_voucher( array( 'buyer_name' => 'Max', 'recipient_name' => 'Lea', 'type' => 'ticket', 'amount' => 0, 'product' => 'Concert ticket' ) ) );
vcap_mail_assert( str_contains( $body, 'Max sent you Concert ticket.' ) && ! str_contains( $body, 'worth' ), 'ticket voucher names the product, not a value of 0', $body );

// Gift recipient without any names: neutral subject and greeting; the buyer is never greeted in the recipient's mail.
list( $subject, $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Recipient', vcap_mail_voucher( array( 'amount' => 30 ) ) );
vcap_mail_assert( str_starts_with( $subject, 'You received a gift from' ) && str_contains( $body, 'Hello, you received a voucher worth' ), 'no names: neutral subject and greeting', array( $subject, $body ) );
list( , $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Recipient', vcap_mail_voucher( array( 'buyer_name' => 'Max', 'amount' => 30 ) ) );
vcap_mail_assert( str_contains( $body, 'Hello, Max sent you a voucher worth' ) && ! str_contains( $body, 'Hi Max' ), 'the recipient is not greeted with the buyer\'s name', $body );

// A text the shop wrote itself is used as written.
$emails = WC()->mailer()->get_emails();
$emails['VCAP_Email_Voucher_Recipient']->settings['intro'] = 'Custom text for {coupon_code}.';
$custom = vcap_mail_voucher( array( 'type' => 'ticket', 'amount' => 0 ) );
list( , $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Recipient', $custom );
vcap_mail_assert( str_contains( $body, 'Custom text for ' . $custom->code . '.' ), 'a custom body text is kept', $body );
$emails['VCAP_Email_Voucher_Recipient']->settings['intro'] = '';

// The buyer's copy of a gift says where the gift went (not "your voucher is ready").
$copy          = vcap_mail_voucher( array( 'buyer_name' => 'Anna', 'recipient_name' => 'Lea' ) );
$copy->send_to = 'recipient';
list( $subject, $body ) = vcap_mail_send( 'VCAP_Email_Voucher_Buyer', $copy );
vcap_mail_assert( str_starts_with( $subject, 'Your gift for Lea is on its way' ) && str_contains( $body, 'Hi Anna, your gift for Lea is on its way to mail-recipient@example.com.' ), 'the buyer copy of a gift reads as a confirmation', array( $subject, $body ) );

// "Additional recipients" get their own copy; a refused copy does not make the mail count as failed.
$emails['VCAP_Email_Voucher_Buyer']->settings['extra_recipients'] = 'archive@example.com, MAIL-BUYER@example.com';
$refuse_archive = static fn( $pre, $atts ) => str_contains( implode( ',', (array) $atts['to'] ), 'archive@' ) ? false : $pre;
add_filter( 'pre_wp_mail', $refuse_archive, 20, 2 );
$before = count( $captured );
$sent   = $emails['VCAP_Email_Voucher_Buyer']->trigger( vcap_mail_voucher( array( 'buyer_name' => 'Anna' ) ) );
remove_filter( 'pre_wp_mail', $refuse_archive, 20 );
$tos = array_map( static fn( $m ) => implode( ',', (array) $m['to'] ), array_slice( $captured, $before ) );
vcap_mail_assert( $sent && array( 'mail-buyer@example.com', 'archive@example.com' ) === $tos, 'each additional recipient gets an own mail, the main address alone decides', $tos );
$emails['VCAP_Email_Voucher_Buyer']->settings['extra_recipients'] = '';

// Z6: the bundled email speaks of the number of vouchers, per role, and keeps a heading the shop wrote itself.
$z6_list = array();
for ( $z6_i = 0; $z6_i < 3; $z6_i++ ) {
	$z6_v          = vcap_mail_voucher( array( 'buyer_name' => 'Max', 'recipient_name' => 'Lea' ) );
	$z6_v->send_to = 'recipient';
	$z6_list[]     = $z6_v;
}
$z6_email                       = $emails['VCAP_Email_Voucher_Bundle'];
$z6_email->settings['email_type'] = 'plain';
$z6_email->settings['enabled']    = 'yes';
$z6_email->trigger_bundle( $z6_list, 'recipient' );
$z6_last = end( $captured );
$z6_body = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $z6_last['message'] ) ) );
vcap_mail_assert( 'mail-recipient@example.com' === implode( ',', (array) $z6_last['to'] ) && str_starts_with( (string) $z6_last['subject'], 'Max sent you 3 vouchers from' ) && str_contains( $z6_body, 'Hi Lea, Max sent you 3 vouchers.' ) && str_contains( $z6_body, 'You received 3 vouchers!' ) && str_contains( $z6_body, $z6_list[0]->code ) && str_contains( $z6_body, $z6_list[2]->code ), 'Z6: the bundled email to the recipient names the giver and the number of vouchers and lists every code', array( $z6_last['subject'] ?? '', $z6_body ) );
$z6_email->trigger_bundle( $z6_list, 'buyer' );
$z6_last = end( $captured );
$z6_body = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) $z6_last['message'] ) ) );
vcap_mail_assert( 'mail-buyer@example.com' === implode( ',', (array) $z6_last['to'] ) && str_starts_with( (string) $z6_last['subject'], 'Your 3 gift vouchers are on their way' ) && str_contains( $z6_body, 'This copy with all codes is for your records.' ) && str_contains( $z6_body, 'Your 3 vouchers' ) && str_contains( $z6_body, 'Lea' ), 'Z6: the buyer copy of bundled gifts reads as a confirmation and names the recipient per code', array( $z6_last['subject'] ?? '', $z6_body ) );
$z6_email->settings['heading'] = 'Codes for {recipient_name}';
$z6_email->trigger_bundle( $z6_list, 'recipient' );
$z6_email->settings['heading'] = '';
vcap_mail_assert( str_contains( wp_strip_all_tags( (string) end( $captured )['message'] ), 'Codes for Lea' ), 'Z6: a heading the shop wrote itself is kept' );
ob_start();
$z6_email->vouchers = array();
$z6_email->voucher  = null;
$z6_preview         = $z6_email->get_content_html();
ob_end_clean();
vcap_mail_assert( str_contains( $z6_preview, '<table' ) && substr_count( $z6_preview, VCAP\Preview::sample_voucher()->code . '-' ) >= 3, 'Z6: the WooCommerce email preview of the bundled email shows a code table with sample vouchers' );

// German: the same texts are translated (formal "Sie").
add_filter( 'woocommerce_email_setup_locale', '__return_false' );
unload_textdomain( 'voucher-captain' );
vcap_mail_assert( load_textdomain( 'voucher-captain', WP_PLUGIN_DIR . '/voucher-captain/languages/voucher-captain-de_DE.mo' ), 'the German translation loads' );
$emails = ( new VCAP\Emails() )->classes( array() );
$email  = $emails['VCAP_Email_Voucher_Recipient'];
$email->settings['email_type'] = 'plain';
$email->settings['enabled']    = 'yes';
$email->trigger( vcap_mail_voucher( array( 'buyer_name' => 'Max', 'recipient_name' => 'Lea', 'discount_type' => 'percent', 'amount' => 15 ) ) );
$last = end( $captured );
vcap_mail_assert( str_contains( wp_strip_all_tags( (string) $last['message'] ), 'Hallo Lea, Max hat Ihnen einen Gutschein über' ), 'the German recipient email is translated', $last['message'] ?? '' );
// A body saved as the English default (an admin with an English profile) is still the German default.
$email->settings['intro'] = 'Hi {recipient_name}, {buyer_name} sent you a voucher worth {coupon_value}.';
$email->trigger( vcap_mail_voucher( array( 'amount' => 30 ) ) );
$email->settings['intro'] = '';
$last = end( $captured );
vcap_mail_assert( str_starts_with( (string) $last['subject'], 'Sie haben ein Geschenk von' ) && str_contains( wp_strip_all_tags( (string) $last['message'] ), 'Hallo, Sie haben einen Gutschein im Wert von' ), 'the neutral German variant reads correctly', $last );
unload_textdomain( 'voucher-captain' );
remove_filter( 'woocommerce_email_setup_locale', '__return_false' );

remove_filter( 'pre_wp_mail', $capture, 10 );
delete_option( 'vcap_attach_pdf' );
echo "Voucher Captain email text checks passed.\n";
