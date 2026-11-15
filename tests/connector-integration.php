<?php
/**
 * Connector extension points: custom voucher types, redemption channels, per-line order/refund data.
 *   wp eval-file tests/connector-integration.php
 */

function vcap_conn_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

add_filter( 'vcap_voucher_types', static fn( $types ) => $types + array( 'course' => 'Course voucher', 'ticket' => 'Overwritten?' ) );
$types = VCAP\Voucher::types();
vcap_conn_assert( 'Course voucher' === ( $types['course'] ?? '' ) && 'Overwritten?' !== $types['ticket'], 'plugins can add voucher types but not replace built-in ones' );

function vcap_conn_product( string $name, array $channels, string $type = 'voucher' ): WC_Product_Simple {
	$p = new WC_Product_Simple();
	$p->set_name( $name );
	$p->set_regular_price( '40' );
	$p->set_virtual( true );
	$p->update_meta_data( '_vcap_enabled', 'yes' );
	$p->update_meta_data( '_vcap_type', $type );
	$p->update_meta_data( '_vcap_channels', $channels );
	$p->save();
	return $p;
}

function vcap_conn_issue( WC_Product $product, int $qty ): array {
	$order = wc_create_order();
	$order->add_product( $product, $qty );
	$order->set_billing_email( 'connector@example.com' );
	$order->calculate_totals();
	$order->set_status( 'processing' );
	$order->save();
	vcap()->issuer->issue_for_order( $order, true );
	$order = wc_get_order( $order->get_id() );
	return array( $order, vcap()->vouchers->for_order( $order->get_id() ) );
}

$external = vcap_conn_product( 'Course voucher product', array( 'external' ), 'course' );
list( $order, $vouchers ) = vcap_conn_issue( $external, 2 );
vcap_conn_assert( 2 === count( $vouchers ) && 'course' === $vouchers[0]->type, 'a voucher of a custom type is issued' );

$items = $order->get_items();
$item  = reset( $items );
$line  = VCAP\Issuer::line_data( $item );
vcap_conn_assert(
	40.0 === (float) $line['face_value'] && 2 === $line['vouchers'] && $order->get_currency() === $line['order_currency'] && get_woocommerce_currency() === $line['voucher_currency']
		&& 80.0 === (float) $line['total'] && array_key_exists( 'total_tax', $line ) && array_key_exists( 'tax_class', $line ) && array( 'external' ) === $line['channels'],
	'value, currency, tax data and channels are stored on the order line'
);

$service = vcap()->redemptions;
$code    = $vouchers[0]->code;
$refused = 0;
try {
	vcap()->coupons->validate( true, new WC_Coupon( $code ) );
} catch ( Exception $e ) {
	$refused = (int) $e->getCode();
}
vcap_conn_assert( 9006 === $refused, 'an external-only voucher is refused in the shop' );
$key      = 'conn-' . wp_generate_uuid4();
$reserved = $service->reserve( $code, array( 'key' => $key, 'target' => 'test:course:7' ) );
vcap_conn_assert( ! is_wp_error( $reserved ) && 'reserved' === $reserved['state'], 'an external-only voucher can be redeemed through the API' );
$service->release( $key );

$shop = vcap_conn_product( 'Shop only voucher', array( 'shop' ) );
list( , $shop_vouchers ) = vcap_conn_issue( $shop, 1 );
$blocked = $service->reserve( $shop_vouchers[0]->code, array( 'key' => 'conn-' . wp_generate_uuid4(), 'target' => 'test:course:7' ) );
vcap_conn_assert( is_wp_error( $blocked ) && 'vcap_not_redeemable' === $blocked->get_error_code(), 'a shop-only voucher is refused by the redemption API' );
$any = vcap_conn_product( 'Unrestricted voucher', array() );
list( , $any_vouchers ) = vcap_conn_issue( $any, 1 );
vcap_conn_assert( VCAP\Channels::allowed( $any_vouchers[0], 'shop' ) && VCAP\Channels::allowed( $any_vouchers[0], 'external' ), 'without a selection every channel is allowed' );

// Later product changes do not change what was sold.
$external->update_meta_data( '_vcap_channels', array( 'shop' ) );
$external->save();
vcap_conn_assert( ! VCAP\Channels::allowed( $vouchers[1], 'shop' ), 'channels are fixed when the voucher is sold' );

// Per-line order and refund data for connectors.
$refund = wc_create_refund(
	array(
		'order_id'   => $order->get_id(),
		'amount'     => 40,
		'line_items' => array( $item->get_id() => array( 'qty' => 1, 'refund_total' => 40 ) ),
	)
);
vcap_conn_assert( ! is_wp_error( $refund ), 'a line refund is recorded' );
$lines = vcap()->api_v1->order_lines( wc_get_order( $order->get_id() ) );
$data  = $lines[ $item->get_id() ] ?? array();
vcap_conn_assert( 2 === $data['quantity'] && 1 === $data['refunded_quantity'] && 40.0 === $data['refunded_total'], 'order_lines() reports quantities and refunds per line' );
vcap_conn_assert( 2 === count( $data['vouchers'] ) && $code === $data['vouchers'][0]['code'] && 40.0 === (float) $data['voucher_line']['face_value'], 'order_lines() lists the vouchers and the line snapshot' );
vcap_conn_assert( VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $vouchers[1]->id )->status && VCAP\Voucher::STATUS_VOID !== vcap()->vouchers->get( $vouchers[0]->id )->status, 'the line refund voids the unused voucher, not the one in use' );
// Refund before the external redemption is completed: the voided voucher can no longer be committed.
list( , $late_vouchers ) = vcap_conn_issue( $any, 1 ); // Unrestricted: $external is shop-only by now.
$late_key = 'conn-' . wp_generate_uuid4();
$late     = $service->reserve( $late_vouchers[0]->code, array( 'key' => $late_key, 'target' => 'test:course:8' ) );
vcap_conn_assert( ! is_wp_error( $late ), 'a voucher is reserved externally' );
vcap()->issuer->void( vcap()->vouchers->get( $late_vouchers[0]->id ), 'Purchase order refunded' );
$commit = $service->commit( $late_key );
vcap_conn_assert( is_wp_error( $commit ) && 'vcap_cancelled' === $commit->get_error_code(), 'a voucher voided during the reservation cannot be committed' );
$released = $service->release( $late_key );
vcap_conn_assert( ! is_wp_error( $released ) && VCAP\Voucher::STATUS_VOID === vcap()->vouchers->get( $late_vouchers[0]->id )->status, 'releasing the reservation keeps the voucher voided' );
// Refunds of completed external redemptions (partial, idempotent, capped).
$gift = new WC_Coupon();
$gift->set_code( 'conn-gift-' . strtolower( wp_generate_password( 8, false ) ) );
$gift->set_discount_type( 'fixed_cart' );
$gift->set_amount( 50 );
$gift->update_meta_data( '_vcap_voucher', 'yes' );
$gift->save();
$card                 = new VCAP\Voucher();
$card->code           = wc_format_coupon_code( $gift->get_code() );
$card->coupon_id      = $gift->get_id();
$card->order_item_id  = 98000000 + $gift->get_id();
$card->type           = 'giftcard';
$card->usage_mode     = 'balance';
$card->discount_type  = 'fixed_cart';
$card->initial_amount = 50;
$card->balance        = 50;
$card->currency       = get_woocommerce_currency();
$card->status         = VCAP\Voucher::STATUS_ACTIVE;
$card->send_to        = 'buyer';
$card->token          = VCAP\VoucherRepository::new_token();
$card->id             = vcap()->vouchers->insert( $card );
$rkey = 'conn-r-' . wp_generate_uuid4();
$service->reserve( $card->code, array( 'key' => $rkey, 'target' => 'test:refund', 'amount' => 30 ) );
vcap_conn_assert( 'vcap_not_committed' === $service->refund( $rkey, 10, 'conn-refund-0001' )->get_error_code(), 'reservations that are not completed cannot be refunded' );
$service->commit( $rkey );
$first = $service->refund( $rkey, 10, 'conn-refund-0001' );
vcap_conn_assert( ! is_wp_error( $first ) && 10.0 === $first['restored'] && 30.0 === vcap()->vouchers->get( $card->id )->balance, 'a partial refund returns the amount to the gift card balance' );
$again = $service->refund( $rkey, 10, 'conn-refund-0001' );
vcap_conn_assert( ! is_wp_error( $again ) && 0.0 === $again['restored'] && 30.0 === vcap()->vouchers->get( $card->id )->balance, 'repeating the same refund key changes nothing' );
$retry = $service->refund( $rkey, 7.5, 'conn-refund-0001' );
vcap_conn_assert( ! is_wp_error( $retry ) && 0.0 === $retry['restored'] && 30.0 === vcap()->vouchers->get( $card->id )->balance, 'a retry of a known refund key answers with the stored result, even with a recalculated amount' );
$rest = $service->refund( $rkey, 50, 'conn-refund-0002' );
vcap_conn_assert( ! is_wp_error( $rest ) && 20.0 === $rest['restored'] && 30.0 === $rest['refunded'] && 50.0 === vcap()->vouchers->get( $card->id )->balance, 'refunds are capped at the redeemed amount' );
vcap_conn_assert( 'vcap_refund_exceeded' === $service->refund( $rkey, 1, 'conn-refund-0003' )->get_error_code(), 'a fully refunded redemption cannot be refunded again' );


function vcap_conn_voucher( string $type, string $mode, float $value, array $emails = array() ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'conn-' . $type . '-' . strtolower( wp_generate_password( 8, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->set_email_restrictions( $emails );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 97000000 + $coupon->get_id();
	$v->type           = $type;
	$v->usage_mode     = $mode;
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = $value;
	$v->balance        = $value;
	$v->currency       = get_woocommerce_currency();
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

// Store credit belongs to one customer account: never redeemable through the redemption API.
$credit = vcap_conn_voucher( 'credit', 'balance', 40 );
$refused = $service->check( $credit->code, array( 'target' => 'test:credit' ) );
vcap_conn_assert( is_wp_error( $refused ) && 'vcap_not_found' === $refused->get_error_code(), 'store credit cannot be redeemed through the redemption API (AP-S1-2: someone else\'s reads like an unknown code)' );
$credit_owner     = (int) wp_insert_user( array( 'user_login' => 'conn-credit-' . strtolower( wp_generate_password( 6, false ) ), 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'conn-credit-' . strtolower( wp_generate_password( 6, false ) ) . '@example.test', 'role' => 'customer' ) );
$credit->user_id  = $credit_owner;
vcap()->vouchers->update( $credit );
$refused = $service->check( $credit->code, array( 'target' => 'test:credit', 'user_id' => $credit_owner ) );
vcap_conn_assert( is_wp_error( $refused ) && 'vcap_not_redeemable' === $refused->get_error_code(), 'store credit cannot be redeemed through the redemption API, not even by its owner' );

// Personal vouchers (V6): an email a guest typed is no proof of identity. Only the logged-in account's email, or an
// address the trusted PHP caller vouches for (email_verified), unlocks them.
$personal = vcap_conn_voucher( 'voucher', 'single', 20, array( 'guest-buyer@example.com' ) );
vcap_conn_assert( is_wp_error( $service->check( $personal->code, array( 'target' => 'test:personal', 'user_id' => 0 ) ) ), 'a personal voucher is refused without the customer email' );
$v6_guest = $service->check( $personal->code, array( 'target' => 'test:personal', 'user_id' => 0, 'email' => 'Guest-Buyer@example.com' ) );
vcap_conn_assert( is_wp_error( $v6_guest ) && 'vcap_login_required' === $v6_guest->get_error_code() && 403 === $v6_guest->get_error_data()['status'] && str_contains( $v6_guest->get_error_message(), 'Please log in with g***@example.com' ), 'V6: a guest email passed without email_verified does not unlock a personal voucher ("Please log in with g***@example.com")' );
vcap_conn_assert( ! is_wp_error( $service->check( $personal->code, array( 'target' => 'test:personal', 'user_id' => 0, 'email' => 'Guest-Buyer@example.com', 'email_verified' => true ) ) ), 'a personal voucher is accepted with the matching customer email the caller vouches for (email_verified)' );
$v6_owner = wp_create_user( 'conn-v6-' . strtolower( wp_generate_password( 6, false ) ), wp_generate_password(), 'Guest-Buyer@example.com' );
$v6_unproven = $service->check( $personal->code, array( 'target' => 'test:personal', 'user_id' => $v6_owner ) );
vcap_conn_assert( is_wp_error( $v6_unproven ) && 'vcap_not_allowed' === $v6_unproven->get_error_code(), 'AP-S1-1: the matching account without a proof of mailbox may not redeem the personal voucher' );
VCAP\PersonalVoucher::mark_verified( $v6_owner ); // As after the confirmation link or a password reset.
vcap_conn_assert( ! is_wp_error( $service->check( $personal->code, array( 'target' => 'test:personal', 'user_id' => $v6_owner ) ) ), 'V6: the account with the matching email may redeem the personal voucher' );
add_filter( 'vcap_personal_voucher_email_verified', '__return_false' );
vcap_conn_assert( is_wp_error( $service->check( $personal->code, array( 'target' => 'test:personal', 'user_id' => $v6_owner ) ) ), 'V6: vcap_personal_voucher_email_verified can require a verification of its own' );
remove_filter( 'vcap_personal_voucher_email_verified', '__return_false' );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $v6_owner );

// Reactivating a voided single-use voucher that was already redeemed keeps it redeemed.
$single = vcap_conn_voucher( 'voucher', 'single', 25 );
$skey   = 'conn-s-' . wp_generate_uuid4();
$service->reserve( $single->code, array( 'key' => $skey, 'target' => 'test:single' ) );
$service->commit( $skey );
vcap()->issuer->void( vcap()->vouchers->get( $single->id ), 'Purchase order refunded' );
vcap()->issuer->reactivate( vcap()->vouchers->get( $single->id ) );
vcap_conn_assert( VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $single->id )->status, 'reactivating a redeemed single-use voucher does not make it usable again' );
$unused = vcap_conn_voucher( 'voucher', 'single', 25 );
vcap()->issuer->void( vcap()->vouchers->get( $unused->id ), 'Purchase order refunded' );
vcap()->issuer->reactivate( vcap()->vouchers->get( $unused->id ) );
vcap_conn_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $unused->id )->status, 'reactivating an unused voucher makes it active again' );

// D12 (Pass Captain connector, without Pass Captain: a stub ticket service): taking tickets back checks the answers
// of revoke_tickets() and release(); a failed restore after revoked tickets is logged – never silently.
$d12_service = new class() {
	public function issue_tickets( ...$args ) {
		return array();
	}
	public function revoke_tickets( $key, $reason ) {
		return new WP_Error( 'pc_down', 'Pass Captain unavailable' );
	}
	public function get( $key ) {
		return null;
	}
};
$d12_filter = static fn() => $d12_service;
add_filter( VCAP\Compat\PassCaptainTickets::SERVICE_FILTER, $d12_filter );
$d12_card  = vcap()->vouchers->get( $card->id );
$d12_key   = 'passcaptain:ticket:d12-' . wp_generate_uuid4();
$service->reserve( $d12_card->code, array( 'key' => $d12_key, 'target' => 'passcaptain:test', 'amount' => 5 ) );
$service->commit( $d12_key );
delete_option( VCAP\Compat\PassCaptainTickets::ATTENTION_OPTION );
$d12_revoke = new ReflectionMethod( VCAP\Compat\PassCaptainTickets::class, 'revoke_checked' );
$d12_revoke->setAccessible( true );
$d12_ok     = $d12_revoke->invoke( vcap()->passcaptain_tickets, $d12_card, 'd12-exchange', 'test', $d12_key );
$d12_events = array_values( array_filter( vcap()->vouchers->events( $d12_card->id ), static fn( $e ) => 'passcaptain_revoke_failed' === ( $e['event'] ?? '' ) ) );
$d12_note   = (string) ( end( $d12_events )['note'] ?? '' );
vcap_conn_assert( false === $d12_ok && str_contains( $d12_note, 'revoke_tickets pc_down' ) && str_contains( $d12_note, 'release ' ), 'D12: a failed revoke_tickets() and a refused release() are logged as passcaptain_revoke_failed with their error codes' );
vcap_conn_assert( in_array( (int) $d12_card->id, array_map( 'intval', (array) get_option( VCAP\Compat\PassCaptainTickets::ATTENTION_OPTION ) ), true ), 'D12: the voucher is listed in the admin notice' );
$d12_restore = new ReflectionMethod( VCAP\Compat\PassCaptainTickets::class, 'restore' );
$d12_restore->setAccessible( true );
$d12_restored = $d12_restore->invoke( vcap()->passcaptain_tickets, 'passcaptain:ticket:d12-missing-' . wp_generate_uuid4(), array( 'amount' => 5, 'meta' => array() ), $d12_card, 'abc123' );
vcap_conn_assert( false === $d12_restored && in_array( 'passcaptain_restore_failed', array_column( vcap()->vouchers->events( $d12_card->id ), 'event' ), true ), 'D12: a failed restore (refund) after revoked tickets is logged as passcaptain_restore_failed' );
remove_filter( VCAP\Compat\PassCaptainTickets::SERVICE_FILTER, $d12_filter );
delete_option( VCAP\Compat\PassCaptainTickets::ATTENTION_OPTION );

echo "Voucher Captain connector checks passed.\n";
