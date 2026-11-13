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
$email->trigger( vcap_mail_voucher( array( 'amount' => 30 ) ) );
$last = end( $captured );
vcap_mail_assert( str_starts_with( (string) $last['subject'], 'Sie haben ein Geschenk von' ) && str_contains( wp_strip_all_tags( (string) $last['message'] ), 'Hallo, Sie haben einen Gutschein im Wert von' ), 'the neutral German variant reads correctly', $last );
unload_textdomain( 'voucher-captain' );
remove_filter( 'woocommerce_email_setup_locale', '__return_false' );

remove_filter( 'pre_wp_mail', $capture, 10 );
delete_option( 'vcap_attach_pdf' );
echo "Voucher Captain email text checks passed.\n";
