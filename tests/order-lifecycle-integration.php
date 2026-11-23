<?php
/**
 * Order lifecycle regression checks for stored-balance vouchers and scheduled delivery.
 * Run through WP-CLI after activating WooCommerce and Voucher Captain:
 *   wp eval-file tests/order-lifecycle-integration.php
 */

function vcap_life_assert( bool $condition, string $message, $detail = null ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message . ( null === $detail ? '' : ' – ' . wp_json_encode( $detail ) ) );
	}
	echo "PASS: {$message}\n";
}

// A5: the core works alone (no add-on installed) and offline: every outgoing HTTP request of this suite is blocked.
require_once __DIR__ . '/no-network.php';
$vcap_offline = vcap_test_no_network();

function vcap_life_voucher( float $balance = 50.0, array $props = array() ): array {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-life-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $balance );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();

	$voucher                 = new VCAP\Voucher();
	$voucher->code           = $props['code'] ?? wc_format_coupon_code( $coupon->get_code() );
	$voucher->coupon_id      = $coupon->get_id();
	$voucher->order_item_id  = 70000000 + $coupon->get_id();
	$voucher->type           = 'giftcard';
	$voucher->usage_mode     = 'balance';
	$voucher->discount_type  = 'fixed_cart';
	$voucher->initial_amount = $balance;
	$voucher->balance        = $balance;
	$voucher->currency       = get_woocommerce_currency();
	$voucher->status         = $props['status'] ?? VCAP\Voucher::STATUS_ACTIVE;
	$voucher->delivery_at    = $props['delivery_at'] ?? null;
	$voucher->buyer_email    = $props['buyer_email'] ?? '';
	$voucher->send_to        = 'buyer';
	$voucher->token          = VCAP\VoucherRepository::new_token();
	$voucher->id             = vcap()->vouchers->insert( $voucher );
	if ( ! $voucher->id ) {
		throw new RuntimeException( 'Could not insert a test voucher.' );
	}
	return array( $voucher, $coupon );
}

function vcap_life_order( array $coupon_discounts, string $currency = '' ): WC_Order {
	$order = wc_create_order( array( 'status' => 'pending' ) );
	if ( $currency ) {
		$order->set_currency( $currency );
	}
	foreach ( $coupon_discounts as $code => $discount ) {
		$item = new WC_Order_Item_Coupon();
		$item->set_code( $code );
		$item->set_discount( $discount );
		$item->set_discount_tax( 0 );
		$order->add_item( $item );
	}
	$order->save();
	return $order;
}

$manager = vcap()->coupons;
$repo    = vcap()->vouchers;

// A paid order whose voucher was spent elsewhere meanwhile must not slip through with a free discount.
list( $shared, $shared_coupon ) = vcap_life_voucher( 50.0 );
$late  = vcap_life_order( array( $shared_coupon->get_code() => 50 ) );
$other = vcap_life_order( array( $shared_coupon->get_code() => 50 ) );
$manager->order_status_changed( $other->get_id(), 'pending', 'processing', $other );
vcap_life_assert( 0.0 === (float) $repo->get( $shared->id )->balance, 'the first paid order uses the whole balance' );
$manager->order_status_changed( $late->get_id(), 'failed', 'processing', $late );
$late = wc_get_order( $late->get_id() );
vcap_life_assert( 'on-hold' === $late->get_status(), 'a paid order whose voucher balance is gone is put on hold for review' );

// Coupon removed from an order after checkout: the hold is released instead of committed.
list( $edited, $edited_coupon ) = vcap_life_voucher( 60.0 );
$edited_order = vcap_life_order( array( $edited_coupon->get_code() => 20 ) );
$manager->reserve_order( $edited_order );
vcap_life_assert( 40.0 === (float) $repo->get( $edited->id )->balance, 'checkout reserves the coupon amount' );
foreach ( $edited_order->get_items( 'coupon' ) as $item_id => $item ) {
	$edited_order->remove_item( $item_id );
}
$edited_order->save();
$manager->order_status_changed( $edited_order->get_id(), 'on-hold', 'processing', $edited_order );
$edited_order = wc_get_order( $edited_order->get_id() );
vcap_life_assert( 60.0 === (float) $repo->get( $edited->id )->balance, 'a coupon removed before payment is not charged' );
vcap_life_assert( ! $edited_order->get_meta( '_vcap_reservations' ) && ! $edited_order->get_meta( '_vcap_redemptions' ), 'no reservation or redemption remains on the edited order' );

// Trashed unpaid order releases its hold.
list( $trashed, $trashed_coupon ) = vcap_life_voucher( 30.0 );
$trash_order = vcap_life_order( array( $trashed_coupon->get_code() => 30 ) );
$manager->reserve_order( $trash_order );
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $trashed->id )->status, 'reserving the full balance blocks the voucher' );
$trash_order->delete( false );
vcap_life_assert( 30.0 === (float) $repo->get( $trashed->id )->balance && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $trashed->id )->status, 'trashing an unpaid order releases its voucher hold' );

// Abandoned unpaid orders are released by the hourly sweep.
list( $abandoned, $abandoned_coupon ) = vcap_life_voucher( 25.0 );
$abandoned_order = vcap_life_order( array( $abandoned_coupon->get_code() => 10 ) );
$manager->reserve_order( $abandoned_order );
// Age the order directly in the database (saving through CRUD would reset the modification date).
global $wpdb;
$aged = gmdate( 'Y-m-d H:i:s', time() - 3 * HOUR_IN_SECONDS );
if ( class_exists( Automattic\WooCommerce\Utilities\OrderUtil::class ) && Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled() ) {
	$wpdb->update( $wpdb->prefix . 'wc_orders', array( 'date_updated_gmt' => $aged ), array( 'id' => $abandoned_order->get_id() ) );
} else {
	$wpdb->update( $wpdb->posts, array( 'post_modified' => get_date_from_gmt( $aged ), 'post_modified_gmt' => $aged ), array( 'ID' => $abandoned_order->get_id() ) );
	clean_post_cache( $abandoned_order->get_id() );
}
wp_cache_flush();
$manager->release_stale_order_reservations();
$abandoned_order = wc_get_order( $abandoned_order->get_id() );
vcap_life_assert( 25.0 === (float) $repo->get( $abandoned->id )->balance && ! $abandoned_order->get_meta( '_vcap_reservations' ), 'the stale-reservation sweep releases abandoned pending orders' );
$manager->order_status_changed( $abandoned_order->get_id(), 'pending', 'processing', $abandoned_order );
vcap_life_assert( 15.0 === (float) $repo->get( $abandoned->id )->balance, 'a late payment after the sweep is still deducted' );

// Order currency differs from the shop currency: the balance is charged in the base currency.
list( $fx, $fx_coupon ) = vcap_life_voucher( 50.0 );
$foreign = 'EUR' === get_option( 'woocommerce_currency', 'USD' ) ? 'USD' : 'EUR';
$half    = static function ( $amount, $currency ) use ( $foreign ) {
	return $currency === $foreign ? $amount / 2 : $amount;
};
add_filter( 'vcap_amount_to_base_currency', $half, 10, 2 );
$fx_order = vcap_life_order( array( $fx_coupon->get_code() => 40 ), $foreign );
$manager->reserve_order( $fx_order );
remove_filter( 'vcap_amount_to_base_currency', $half, 10 );
vcap_life_assert( 30.0 === (float) $repo->get( $fx->id )->balance, 'foreign-currency discounts are converted before reserving the balance' );

// The order-scoped API stores meta keys like checkout does (lower-case codes), so payment commits them.
list( $upper, $upper_coupon ) = vcap_life_voucher( 40.0, array( 'code' => strtoupper( 'vcap-life-up-' . wp_generate_password( 8, false, false ) ) ) );
$api_order = vcap_life_order( array( $upper_coupon->get_code() => 10 ) );
vcap()->api_v1->reserve( $upper->code, 10.0, $api_order );
$api_order    = wc_get_order( $api_order->get_id() );
$reservations = (array) $api_order->get_meta( '_vcap_reservations' );
vcap_life_assert( isset( $reservations[ wc_format_coupon_code( $upper->code ) ] ), 'API reservations use the normalised coupon code as meta key' );

// A scheduled gift becomes redeemable on its date even if no email can be sent.
list( $scheduled, $scheduled_coupon ) = vcap_life_voucher( 20.0, array( 'status' => VCAP\Voucher::STATUS_SCHEDULED, 'delivery_at' => gmdate( 'Y-m-d H:i:s', time() - 60 ) ) );
vcap()->coupons->set_coupon_enabled( $scheduled, false );
$no_mail = static fn() => false;
add_filter( 'woocommerce_email_enabled_vcap_voucher_buyer', $no_mail );
add_filter( 'woocommerce_email_enabled_vcap_voucher_recipient', $no_mail );
vcap()->delivery->deliver( $scheduled->id );
remove_filter( 'woocommerce_email_enabled_vcap_voucher_buyer', $no_mail );
remove_filter( 'woocommerce_email_enabled_vcap_voucher_recipient', $no_mail );
vcap_life_assert( VCAP\Voucher::STATUS_ACTIVE === $repo->get( $scheduled->id )->status && 'publish' === get_post_status( $scheduled_coupon->get_id() ), 'a due scheduled voucher is activated even when no email is sent' );

// Gift-card coupons are always cart-wide, never per item.
$card             = new VCAP\Voucher();
$card->code       = 'vcap-life-fp-' . strtolower( wp_generate_password( 8, false, false ) );
$card->usage_mode = 'balance';
$card->discount_type = 'fixed_product';
$card->initial_amount = 10;
$card->balance    = 10;
$coupon_id        = vcap()->coupons->create_coupon( $card, VCAP\ProductConfig::DEFAULTS );
vcap_life_assert( 'fixed_cart' === ( new WC_Coupon( $coupon_id ) )->get_discount_type(), 'stored-balance coupons are created as fixed_cart' );

// E2: partial return of an order paid with a gift card (guest, total 0): part of the charge is booked back.
list( $ret, $ret_coupon ) = vcap_life_voucher( 100.0 );
$ret_order = vcap_life_order( array( $ret_coupon->get_code() => 60 ) );
$manager->order_status_changed( $ret_order->get_id(), 'pending', 'processing', $ret_order );
vcap_life_assert( 40.0 === (float) $repo->get( $ret->id )->balance, 'the order charges 60 to the card' );
$returns = new VCAP\OrderReturn( $repo, $manager );
$back    = $returns->book_back( wc_get_order( $ret_order->get_id() ), $ret_coupon->get_code(), 20, 'One item returned' );
vcap_life_assert( ! is_wp_error( $back ) && 60.0 === (float) $repo->get( $ret->id )->balance, 'a returned item is booked back onto the card (guest order)' );
vcap_life_assert( is_wp_error( $returns->book_back( wc_get_order( $ret_order->get_id() ), $ret_coupon->get_code(), 41, 'Too much' ) ), 'more than the charge minus earlier returns cannot be booked back' );
vcap_life_assert( in_array( VCAP\CouponManager::RETURNED_EVENT, array_column( $repo->events( $ret->id ), 'event' ), true ), 'the return is a ledger event of its own' );
$ret_order = wc_get_order( $ret_order->get_id() );
$manager->order_status_changed( $ret_order->get_id(), 'processing', 'cancelled', $ret_order );
vcap_life_assert( 100.0 === (float) $repo->get( $ret->id )->balance, 'cancelling afterwards books back only the rest (never twice)' );

// T6: the charge goes back onto a card that has expired meanwhile – never silently.
list( $old, $old_coupon ) = vcap_life_voucher( 50.0 );
$old_order = vcap_life_order( array( $old_coupon->get_code() => 50 ) );
$manager->order_status_changed( $old_order->get_id(), 'pending', 'processing', $old_order );
$old_card             = $repo->get( $old->id );
$old_card->expires_at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$repo->update_columns( $old_card, array( 'expires_at' ) );
$old_order = wc_get_order( $old_order->get_id() );
$manager->order_status_changed( $old_order->get_id(), 'processing', 'cancelled', $old_order );
vcap_life_assert( 50.0 === (float) $repo->get( $old->id )->balance && VCAP\Attention::has( $old_order->get_id(), 'restored:' . $old->id ), 'a balance booked back onto an expired card is listed for the shop' );
VCAP\Attention::resolve( $old_order->get_id(), 'restored:' . $old->id );
add_filter( 'vcap_restore_extension_days', static fn() => 30 );
list( $ext, $ext_coupon ) = vcap_life_voucher( 50.0 );
$ext_order = vcap_life_order( array( $ext_coupon->get_code() => 50 ) );
$manager->order_status_changed( $ext_order->get_id(), 'pending', 'processing', $ext_order );
$ext_card             = $repo->get( $ext->id );
$ext_card->expires_at = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
$repo->update_columns( $ext_card, array( 'expires_at' ) );
$manager->order_status_changed( $ext_order->get_id(), 'processing', 'cancelled', wc_get_order( $ext_order->get_id() ) );
vcap_life_assert( ! $repo->get( $ext->id )->is_expired() && VCAP\Voucher::STATUS_ACTIVE === $repo->get( $ext->id )->status, 'with "vcap_restore_extension_days" the expired card is extended' );
remove_all_filters( 'vcap_restore_extension_days' );

/** A single-use 20 voucher (coupon usage limit 1), optionally bought in $order_id. */
function vcap_life_single( int $order_id = 0 ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-single-' . strtolower( wp_generate_password( 12, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( 20 );
	$coupon->set_usage_limit( 1 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_id       = $order_id;
	$v->order_item_id  = 71000000 + $coupon->get_id();
	$v->type           = 'voucher';
	$v->usage_mode     = 'single';
	$v->discount_type  = 'fixed_cart';
	$v->initial_amount = 20;
	$v->balance        = 20;
	$v->currency       = get_woocommerce_currency();
	$v->status         = VCAP\Voucher::STATUS_ACTIVE;
	$v->send_to        = 'buyer';
	$v->token          = VCAP\VoucherRepository::new_token();
	$v->id             = vcap()->vouchers->insert( $v );
	return $v;
}

// P9: a paid voucher order cancelled by hand (no refund): unused vouchers are voided, used ones stay.
$bought = wc_create_order( array( 'status' => 'processing' ) );
$bought->set_billing_email( 'p9@example.com' );
$bought->save();
$unused_single = vcap_life_single( $bought->get_id() );
$used_single   = vcap_life_single( $bought->get_id() );
$repo->update_status( $used_single->id, VCAP\Voucher::STATUS_REDEEMED );
wc_get_order( $bought->get_id() )->update_status( 'cancelled' );
vcap_life_assert( VCAP\Voucher::STATUS_VOID === $repo->get( $unused_single->id )->status, 'P9: cancelling a paid order voids its unused vouchers' );
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $used_single->id )->status, 'P9: a voucher already used stays as it is when the order is cancelled without a refund' );

// T5: a fully refunded order gives its single-use voucher back (WooCommerce does so only for cancelled orders).
$single   = vcap_life_single();
$goods_t5 = new WC_Product_Simple();
$goods_t5->set_name( 'T5 goods' );
$goods_t5->set_regular_price( '80' );
$goods_t5->set_virtual( true );
$goods_t5->save();
$t5 = wc_create_order();
$t5->add_product( $goods_t5, 1 );
$t5->set_billing_email( 't5@example.com' );
$t5->apply_coupon( $single->code );
$t5->calculate_totals( false );
$t5->set_status( 'processing' );
$t5->save();
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $single->id )->status, 'T5: paying the order redeems the single-use voucher' );
// The whole order comes back (M12-02: an order paid with a voucher becomes "Refunded" only without items left).
$t5_total = (float) wc_get_order( $t5->get_id() )->get_total();
$t5_line  = array_key_first( wc_get_order( $t5->get_id() )->get_items() );
wc_create_refund( array( 'order_id' => $t5->get_id(), 'amount' => $t5_total, 'line_items' => array( $t5_line => array( 'qty' => 1, 'refund_total' => $t5_total ) ) ) );
vcap_life_assert( wc_get_order( $t5->get_id() )->has_status( 'refunded' ), 'T5: the order is fully refunded' );
vcap_life_assert( VCAP\Voucher::STATUS_ACTIVE === $repo->get( $single->id )->status && 0 === ( new WC_Coupon( $single->coupon_id ) )->get_usage_count(), 'T5: the single-use voucher can be used again after the full refund' );

// T5: with "Restore balance on cancellation" off a cancelled order keeps its single-use voucher redeemed.
$keep_restore = get_option( 'vcap_restore_on_cancel', false );
update_option( 'vcap_restore_on_cancel', 'no' );
$kept_single = vcap_life_single();
$t5c         = wc_create_order();
$t5c->add_product( $goods_t5, 1 );
$t5c->set_billing_email( 't5c@example.com' );
$t5c->apply_coupon( $kept_single->code );
$t5c->calculate_totals( false );
$t5c->set_status( 'processing' );
$t5c->save();
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $kept_single->id )->status && 1 === ( new WC_Coupon( $kept_single->coupon_id ) )->get_usage_count(), 'T5: paying the second order redeems its single-use voucher' );
wc_get_order( $t5c->get_id() )->update_status( 'cancelled' );
$t5c_refused = 0;
try {
	$manager->validate( true, new WC_Coupon( $kept_single->coupon_id ) );
} catch ( Exception $e ) {
	$t5c_refused = $e->getCode();
}
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $kept_single->id )->status && 9002 === $t5c_refused, 'T5: with vcap_restore_on_cancel = no the single-use voucher stays redeemed when the order is cancelled (the shop refuses the code)' );
vcap_life_assert( str_contains( implode( "\n", array_map( static fn( $n ) => (string) $n->content, wc_get_order_notes( array( 'order_id' => $t5c->get_id() ) ) ) ), 'stay redeemed' ), 'T5: the order note says the single-use voucher stays redeemed' );
false === $keep_restore ? delete_option( 'vcap_restore_on_cancel' ) : update_option( 'vcap_restore_on_cancel', $keep_restore );

/** Order notes of an order as plain strings. */
function vcap_life_notes( int $order_id ): array {
	return array_map( static fn( $note ) => (string) $note->content, wc_get_order_notes( array( 'order_id' => $order_id ) ) );
}

/** Makes every coupon post update fail (wp_update_post() returns a WP_Error) – a void that cannot disable its coupon. */
function vcap_life_block_coupon_updates( $empty, $postarr ) {
	return ( isset( $postarr['ID'] ) && 'shop_coupon' === get_post_type( (int) $postarr['ID'] ) ) ? true : $empty;
}

// P7: a void that fails after a refund is reported (order note, Attention) and retried by Action Scheduler.
$p7 = wc_create_order( array( 'status' => 'processing' ) );
$p7->set_billing_email( 'p7@example.com' );
$p7->save();
$p7_voucher = vcap_life_single( $p7->get_id() );
add_filter( 'wp_insert_post_empty_content', 'vcap_life_block_coupon_updates', 10, 2 );
wc_get_order( $p7->get_id() )->update_status( 'refunded' );
remove_filter( 'wp_insert_post_empty_content', 'vcap_life_block_coupon_updates', 10 );
$p7_notes = implode( "\n", vcap_life_notes( $p7->get_id() ) );
vcap_life_assert( 'publish' === get_post_status( $p7_voucher->coupon_id ) && str_contains( $p7_notes, 'could NOT be voided' ), 'P7: a void that fails after the refund leaves an order note' );
vcap_life_assert( VCAP\Attention::has( $p7->get_id(), 'void:' . $p7_voucher->id ), 'P7: the failed void is listed for the shop (admin notice / Site Health)' );
vcap_life_assert( (bool) as_next_scheduled_action( VCAP\Issuer::VOID_RETRY_HOOK, array( 'voucher_id' => (int) $p7_voucher->id, 'attempt' => 1 ), 'vcap' ), 'P7: the void is retried by an Action Scheduler action' );
vcap()->issuer->retry_void( $p7_voucher->id, 1 );
vcap_life_assert( 'draft' === get_post_status( $p7_voucher->coupon_id ) && VCAP\Voucher::STATUS_VOID === $repo->get( $p7_voucher->id )->status && ! VCAP\Attention::has( $p7->get_id(), 'void:' . $p7_voucher->id ), 'P7: the retry disables the coupon and resolves the case' );
vcap_life_assert( str_contains( implode( "\n", vcap_life_notes( $p7->get_id() ) ), 'voided (retried after the refund or cancellation)' ), 'P7: the successful retry is noted on the order' );
as_unschedule_all_actions( VCAP\Issuer::VOID_RETRY_HOOK, null, 'vcap' );

// P7 (partial refund): no "voided (refunded)" note when the void failed.
$p7_goods = new WC_Product_Simple();
$p7_goods->set_name( 'P7 voucher line' );
$p7_goods->set_regular_price( '20' );
$p7_goods->set_virtual( true );
$p7_goods->save();
$p7p = wc_create_order();
$p7p_line = $p7p->add_product( $p7_goods, 2 );
$p7p->set_billing_email( 'p7p@example.com' );
$p7p->calculate_totals( false );
$p7p->set_status( 'processing' );
$p7p->save();
$p7p_vouchers = array();
foreach ( array( 0, 1 ) as $p7_index ) {
	$p7p_v                = vcap_life_single( $p7p->get_id() );
	$p7p_v->order_item_id = $p7p_line;
	$p7p_v->item_index    = $p7_index;
	$repo->update_columns( $p7p_v, array( 'order_item_id', 'item_index' ) );
	$p7p_vouchers[] = $p7p_v;
}
add_filter( 'wp_insert_post_empty_content', 'vcap_life_block_coupon_updates', 10, 2 );
$p7_refund = wc_create_refund( array( 'order_id' => $p7p->get_id(), 'amount' => 20, 'line_items' => array( $p7p_line => array( 'qty' => 1, 'refund_total' => 20 ) ) ) );
remove_filter( 'wp_insert_post_empty_content', 'vcap_life_block_coupon_updates', 10 );
$p7p_notes = implode( "\n", vcap_life_notes( $p7p->get_id() ) );
vcap_life_assert( ! is_wp_error( $p7_refund ) && str_contains( $p7p_notes, 'could NOT be voided' ) && ! str_contains( $p7p_notes, 'voided (refunded)' ), 'P7: a partial refund whose void failed notes the failure, not "voided (refunded)"' );
foreach ( $p7p_vouchers as $p7p_v ) {
	VCAP\Attention::resolve( $p7p->get_id(), 'void:' . $p7p_v->id );
}
as_unschedule_all_actions( VCAP\Issuer::VOID_RETRY_HOOK, null, 'vcap' );

// P8: a partial refund of a voucher line never voids a voucher that was used up completely.
$p8_goods = new WC_Product_Simple();
$p8_goods->set_name( 'P8 goods' );
$p8_goods->set_regular_price( '80' );
$p8_goods->set_virtual( true );
$p8_goods->save();
$p8 = wc_create_order();
$p8_line = $p8->add_product( $p7_goods, 1 );
$p8->add_product( $p8_goods, 1 );
$p8->set_billing_email( 'p8@example.com' );
$p8->calculate_totals( false );
$p8->set_status( 'processing' );
$p8->save();
$p8_used                = vcap_life_single( $p8->get_id() );
$p8_used->order_item_id = $p8_line;
$repo->update_columns( $p8_used, array( 'order_item_id' ) );
$repo->update_status( $p8_used->id, VCAP\Voucher::STATUS_REDEEMED );
$p8_refund = wc_create_refund( array( 'order_id' => $p8->get_id(), 'amount' => 10, 'line_items' => array( $p8_line => array( 'qty' => 1, 'refund_total' => 10 ) ) ) );
vcap_life_assert( ! is_wp_error( $p8_refund ) && ! wc_get_order( $p8->get_id() )->has_status( 'refunded' ), 'P8: the order is partly refunded (quantity of the voucher line)' );
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $p8_used->id )->status && 'publish' === get_post_status( $p8_used->coupon_id ) && ! in_array( 'voided', array_column( $repo->events( $p8_used->id ), 'event' ), true ), 'P8: a used-up voucher is not voided by a partial refund (no "voided" event for connectors)' );
vcap_life_assert( str_contains( implode( "\n", vcap_life_notes( $p8->get_id() ) ), 'was already used up and stays as it is' ), 'P8: an order note names the used-up voucher' );

// K3: "On hold" as the only issue status (older setups), bank transfer: the vouchers come once the order is paid.
$keep_statuses = get_option( 'vcap_issue_statuses', false );
update_option( 'vcap_issue_statuses', array( 'on-hold' ) );
$k3_product = new WC_Product_Simple();
$k3_product->set_name( 'K3 voucher' );
$k3_product->set_regular_price( '30' );
$k3_product->set_virtual( true );
$k3_product->update_meta_data( '_vcap_enabled', 'yes' );
$k3_product->update_meta_data( '_vcap_type', 'voucher' );
$k3_product->save();
$k3 = wc_create_order();
$k3->add_product( $k3_product, 1 );
$k3->set_billing_email( 'k3@example.com' );
$k3->set_payment_method( 'bacs' );
$k3->calculate_totals( false );
$k3->set_status( 'on-hold' );
$k3->save();
vcap()->issuer->on_status( $k3->get_id(), wc_get_order( $k3->get_id() ), array( 'from' => 'pending', 'to' => 'on-hold' ) );
vcap_life_assert( ! $repo->for_order( $k3->get_id() ), 'K3: nothing is issued while the bank transfer is open' );
$k3 = wc_get_order( $k3->get_id() );
$k3->set_status( 'processing' );
$k3->save();
vcap()->issuer->on_paid_status( $k3->get_id(), wc_get_order( $k3->get_id() ), array( 'from' => 'on-hold', 'to' => 'processing', 'manual' => true ) );
vcap_life_assert( 1 === count( $repo->for_order( $k3->get_id() ) ), 'K3: setting the order to "Processing" by hand issues the voucher that waited for the payment' );
// Unset before: delete it again (storing false would leave "" = issue manually only for every later suite).
false === $keep_statuses ? delete_option( 'vcap_issue_statuses' ) : update_option( 'vcap_issue_statuses', $keep_statuses );

/* ---- Audit panel package A ------------------------------------------------------------------------------ */

/** A second database connection: changes and locks from "another request" that this request does not see coming. */
function vcap_life_other_connection(): mysqli {
	global $wpdb;
	list( $host, $port, $socket ) = $wpdb->parse_db_host( DB_HOST );
	return new mysqli( (string) $host, DB_USER, DB_PASSWORD, DB_NAME, $port ? (int) $port : 3306, $socket ? (string) $socket : null );
}

$ap_topup = new WC_Product_Simple();
$ap_topup->set_name( 'AP top-up' );
$ap_topup->set_regular_price( '100' );
$ap_topup->set_virtual( true );
$ap_topup->update_meta_data( '_vcap_enabled', 'yes' );
$ap_topup->update_meta_data( '_vcap_type', 'giftcard' );
$ap_topup->update_meta_data( '_vcap_usage', 'balance' );
$ap_topup->update_meta_data( VCAP\TopUp::PRODUCT_META, 'yes' );
$ap_topup->save();
$ap_goods = new WC_Product_Simple();
$ap_goods->set_name( 'AP goods' );
$ap_goods->set_regular_price( '50' );
$ap_goods->set_virtual( true );
$ap_goods->save();

/** An unpaid order with 50 of goods and a top-up of 100 of $card, paid with $card (30) – reserved like at checkout. */
function vcap_life_topup_order( WC_Product $topup, WC_Product $goods, VCAP\Voucher $card, WC_Coupon $card_coupon ): WC_Order {
	$order = wc_create_order();
	$line  = $order->add_product( $topup, 1, array( 'subtotal' => 100, 'total' => 100 ) );
	$item  = $order->get_item( $line );
	$item->add_meta_data( VCAP\TopUp::ITEM_META, $card->code, true );
	$item->save();
	$order->add_product( $goods, 1 );
	$order->set_billing_email( 'ap-topup@example.com' );
	$order->calculate_totals( false );
	$order->save();
	$order->apply_coupon( $card_coupon->get_code() );
	$order = wc_get_order( $order->get_id() );
	vcap()->coupons->reserve_order( $order );
	return wc_get_order( $order->get_id() );
}

// AP-Q1-1 (b): the card pays part of the order and is topped up by the same order; cancelling gives both back.
list( $q11b, $q11b_coupon ) = vcap_life_voucher( 30.0 );
$q11b_order = vcap_life_topup_order( $ap_topup, $ap_goods, $q11b, $q11b_coupon );
vcap_life_assert( 0.0 === (float) $repo->get( $q11b->id )->balance && 120.0 === (float) $q11b_order->get_total(), 'AP-Q1-1: the card (30) pays part of the order with its own top-up (reserved at checkout)' );
$q11b_order->set_status( 'processing' );
$q11b_order->save();
vcap()->issuer->issue_for_order( wc_get_order( $q11b_order->get_id() ), true );
vcap_life_assert( 100.0 === round( (float) $repo->get( $q11b->id )->balance, 2 ), 'AP-Q1-1: payment charges the 30 and books the top-up of 100 onto the same card' );
wc_get_order( $q11b_order->get_id() )->update_status( 'cancelled' );
vcap_life_assert( 30.0 === round( (float) $repo->get( $q11b->id )->balance, 2 ), 'AP-Q1-1: cancelling takes the top-up back and restores the 30 the order charged (the top-up does not hide the charge)' );

// AP-Q1-1 (c): "On hold" as an issue status books the top-up before the payment commits the reservation.
list( $q11c, $q11c_coupon ) = vcap_life_voucher( 30.0 );
$q11c_order = vcap_life_topup_order( $ap_topup, $ap_goods, $q11c, $q11c_coupon );
$q11c_order->set_status( 'on-hold' );
$q11c_order->save();
vcap()->issuer->issue_for_order( wc_get_order( $q11c_order->get_id() ), true ); // What the "on-hold" issue status does.
vcap_life_assert( 100.0 === round( (float) $repo->get( $q11c->id )->balance, 2 ), 'AP-Q1-1: the top-up is booked while the order is on hold (card 0 after the reservation + 100)' );
$q11c_order = wc_get_order( $q11c_order->get_id() );
$q11c_order->set_status( 'processing' );
$q11c_order->save();
$q11c_events = array_values( array_filter( $repo->events( $q11c->id ), static fn( $e ) => (int) $e['order_id'] === $q11c_order->get_id() && in_array( $e['event'], VCAP\CouponManager::ORDER_CHARGE_EVENTS, true ) ) );
vcap_life_assert( 100.0 === round( (float) $repo->get( $q11c->id )->balance, 2 ) && array( 'reserved', 'committed' ) === array_column( $q11c_events, 'event' ), 'AP-Q1-1: the payment commits the reservation once – no second deduction', array_column( $q11c_events, 'event' ) );
vcap_life_assert( 0.0 === $manager->open_holds( $repo->get( $q11c->id ) ) && null === VCAP\Tickets::open_reservation( $repo->events( $q11c->id ) ), 'AP-Q1-1: no reserved row is left open (no orphaned hold)' );
wc_get_order( $q11c_order->get_id() )->update_status( 'cancelled' );
vcap_life_assert( 30.0 === round( (float) $repo->get( $q11c->id )->balance, 2 ), 'AP-Q1-1: cancelling restores the card to 30 (charge back, top-up reversed)' );

// AP-Q1-4: refunded (single-use use given back) → used in another order → the refunded order is cancelled.
vcap_life_assert( false === (bool) wc_get_order( $t5->get_id() )->get_data_store()->get_recorded_coupon_usage_counts( wc_get_order( $t5->get_id() ) ), 'AP-Q1-4: after the full refund WooCommerce no longer counts the use of the refunded order' );
$t5_again = wc_create_order();
$t5_again->add_product( $goods_t5, 1 );
$t5_again->set_billing_email( 't5-again@example.com' );
$t5_again->apply_coupon( $single->code );
$t5_again->calculate_totals( false );
$t5_again->set_status( 'processing' );
$t5_again->save();
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $single->id )->status && 1 === ( new WC_Coupon( $single->coupon_id ) )->get_usage_count(), 'AP-Q1-4: the voucher given back is redeemed by another order' );
wc_get_order( $t5->get_id() )->update_status( 'cancelled' );
vcap_life_assert( VCAP\Voucher::STATUS_REDEEMED === $repo->get( $single->id )->status && 1 === ( new WC_Coupon( $single->coupon_id ) )->get_usage_count(), 'AP-Q1-4: cancelling the refunded order does not reactivate the voucher redeemed meanwhile (no third use)' );

// AP-Q1-5: the rounding tolerance only across currencies, at most a few minor units, and recorded.
list( $q15_base, $q15_base_coupon ) = vcap_life_voucher( 49.96 );
$q15_order = vcap_life_order( array( $q15_base_coupon->get_code() => 50 ) );
$q15_error = 0;
try {
	$manager->reserve_order( $q15_order );
} catch ( Exception $e ) {
	$q15_error = $e->getCode();
}
vcap_life_assert( VCAP\CouponManager::BALANCE_CHANGED === $q15_error && 49.96 === (float) $repo->get( $q15_base->id )->balance, 'AP-Q1-5: in the base currency a discount 4 cents above the balance is refused (no silent tolerance)' );
$q15_foreign = 'EUR' === get_option( 'woocommerce_currency', 'USD' ) ? 'USD' : 'EUR';
add_filter( 'vcap_currency_rate_known', '__return_true' ); // 1:1 rate (to_base() returns the amount unchanged).
list( $q15_fx, $q15_fx_coupon ) = vcap_life_voucher( 49.96 );
$q15_fx_order = vcap_life_order( array( $q15_fx_coupon->get_code() => 50 ), $q15_foreign );
$manager->reserve_order( $q15_fx_order );
$manager->order_status_changed( $q15_fx_order->get_id(), 'pending', 'processing', wc_get_order( $q15_fx_order->get_id() ) );
$q15_fx_order = wc_get_order( $q15_fx_order->get_id() );
$q15_key      = wc_format_coupon_code( $q15_fx_coupon->get_code() );
vcap_life_assert( 0.0 === (float) $repo->get( $q15_fx->id )->balance && 0.04 === (float) ( VCAP\CouponManager::order_map( $q15_fx_order, VCAP\CouponManager::ROUNDING_SHORTFALL_META )[ $q15_key ] ?? 0 ), 'AP-Q1-5: across currencies the full balance is charged and the 4 cents it lacked are recorded on the order' );
vcap_life_assert( str_contains( implode( "\n", vcap_life_notes( $q15_fx_order->get_id() ) ), 'currency rounding' ), 'AP-Q1-5: an order note names the uncovered rounding difference' );
list( $q15_big, $q15_big_coupon ) = vcap_life_voucher( 999.50 );
$q15_big_order = vcap_life_order( array( $q15_big_coupon->get_code() => 1000 ), $q15_foreign );
$q15_error     = 0;
try {
	$manager->reserve_order( $q15_big_order );
} catch ( Exception $e ) {
	$q15_error = $e->getCode();
}
remove_filter( 'vcap_currency_rate_known', '__return_true' );
vcap_life_assert( VCAP\CouponManager::BALANCE_CHANGED === $q15_error && 999.5 === (float) $repo->get( $q15_big->id )->balance, 'AP-Q1-5: the tolerance is capped at a few minor units (50 cents on 1000 are refused, not 0.1 %)' );

// AP-Q1-6: return → cancel → reopen → cancel books back the whole new charge.
list( $q16, $q16_coupon ) = vcap_life_voucher( 100.0 );
$q16_order = vcap_life_order( array( $q16_coupon->get_code() => 60 ) );
$manager->order_status_changed( $q16_order->get_id(), 'pending', 'processing', $q16_order );
$q16_back = ( new VCAP\OrderReturn( $repo, $manager ) )->book_back( wc_get_order( $q16_order->get_id() ), $q16_coupon->get_code(), 20, 'One item returned' );
$manager->order_status_changed( $q16_order->get_id(), 'processing', 'cancelled', wc_get_order( $q16_order->get_id() ) );
vcap_life_assert( ! is_wp_error( $q16_back ) && 100.0 === (float) $repo->get( $q16->id )->balance && '' === wc_get_order( $q16_order->get_id() )->get_meta( VCAP\CouponManager::RETURNED_META ), 'AP-Q1-6: cancelling after a return books back the rest and clears the returned amounts' );
$manager->order_status_changed( $q16_order->get_id(), 'cancelled', 'processing', wc_get_order( $q16_order->get_id() ) );
vcap_life_assert( 40.0 === (float) $repo->get( $q16->id )->balance, 'AP-Q1-6: reopening the order charges the 60 again' );
$manager->order_status_changed( $q16_order->get_id(), 'processing', 'cancelled', wc_get_order( $q16_order->get_id() ) );
vcap_life_assert( 100.0 === (float) $repo->get( $q16->id )->balance, 'AP-Q1-6: cancelling the reopened order books back the whole new charge (the old return no longer counts)' );
$manager->order_status_changed( $q16_order->get_id(), 'cancelled', 'processing', wc_get_order( $q16_order->get_id() ) );
$q16_other = vcap_life_other_connection();
$q16_lock  = $q16_other->query( "SELECT GET_LOCK('" . $q16_other->real_escape_string( VCAP\OrderReturn::lock_name( $q16_order->get_id() ) ) . "', 0)" )->fetch_row()[0];
$manager->restore_order( wc_get_order( $q16_order->get_id() ) );
vcap_life_assert( '1' === (string) $q16_lock && 40.0 === (float) $repo->get( $q16->id )->balance && str_contains( implode( "\n", vcap_life_notes( $q16_order->get_id() ) ), 'could not be restored' ), 'AP-Q1-6: while a booking back holds the order\'s lock, the cancellation waits and books nothing back twice' );
$q16_other->query( "SELECT RELEASE_LOCK('" . $q16_other->real_escape_string( VCAP\OrderReturn::lock_name( $q16_order->get_id() ) ) . "')" );
$q16_other->close();
$manager->restore_order( wc_get_order( $q16_order->get_id() ) );
vcap_life_assert( 100.0 === (float) $repo->get( $q16->id )->balance, 'AP-Q1-6: once the lock is free the cancellation books the charge back' );

// AP-P1-5: the request memo of get_by_code()/get_by_coupon() never serves a stale row inside a transaction.
list( $p15, $p15_coupon ) = vcap_life_voucher( 50.0 );
$p15_table = VCAP\Install::tables()['vouchers'];
vcap_life_assert( 50.0 === (float) $repo->get_by_code( $p15->code )->balance && 50.0 === (float) $repo->get_by_coupon( $p15->coupon_id )->balance, 'AP-P1-5: the first reads fill the memo' );
$p15_other = vcap_life_other_connection();
$p15_other->query( "UPDATE {$p15_table} SET balance = 10 WHERE id = " . (int) $p15->id );
vcap_life_assert( 50.0 === (float) $repo->get_by_code( $p15->code )->balance && 50.0 === (float) $repo->get_by_coupon( $p15->coupon_id )->balance, 'AP-P1-5: outside a transaction a repeated read comes from the memo (no query)' );
VCAP\Db::begin();
$p15_in = $repo->get_by_code( $p15->code );
VCAP\Db::finish();
vcap_life_assert( 10.0 === (float) $p15_in->balance, 'AP-P1-5: inside a transaction the voucher is read from the database, never from the memo' );
vcap_life_assert( 10.0 === (float) $repo->get_by_code( $p15->code )->balance, 'AP-P1-5: the transaction forgot the memo – the next read is fresh and fills it again' );
$p15_other->query( "UPDATE {$p15_table} SET balance = 20 WHERE id = " . (int) $p15->id );
$p15_cached = $repo->get_by_code( $p15->code ); // From the memo: 10.
$p15_deduct = $manager->deduct_balance_atomic( $p15_cached, 5.0, 'AP-P1-5', 0 );
vcap_life_assert( 10.0 === (float) $p15_cached->balance && is_array( $p15_deduct ) && 15.0 === (float) $p15_deduct['balance'] && 15.0 === (float) $repo->get( $p15->id )->balance, 'AP-P1-5: a booking decides on the row read under its lock (FOR UPDATE): 20 − 5 = 15, not the memo\'s 10 − 5' );
vcap_life_assert( 15.0 === (float) $repo->get_by_code( $p15->code )->balance, 'AP-P1-5: the memo is forgotten after the write' );
$p15_other->query( "UPDATE {$p15_table} SET balance = 12 WHERE id = " . (int) $p15->id );
do_action( 'vcap_voucher_updated', $repo->get( $p15->id ) );
vcap_life_assert( 12.0 === (float) $repo->get_by_code( $p15->code )->balance, 'AP-P1-5: "vcap_voucher_updated" forgets the memo' );
$p15_missing = 'vcap-p15-miss-' . strtolower( wp_generate_password( 8, false, false ) );
vcap_life_assert( null === $repo->get_by_code( $p15_missing ), 'AP-P1-5: a miss is memoised as well' );
$p15_other->query( "UPDATE {$p15_table} SET code = '" . $p15_other->real_escape_string( $p15_missing ) . "' WHERE id = " . (int) $p15->id );
$p15_miss_again = $repo->get_by_code( $p15_missing );
$wpdb->query( "UPDATE {$p15_table} SET updated_at = updated_at WHERE id = 0" ); // phpcs:ignore -- any write query of the request.
vcap_life_assert( null === $p15_miss_again && $p15->id === ( $repo->get_by_code( $p15_missing )->id ?? 0 ), 'AP-P1-5: any write query of the request (direct SQL too) forgets the memo' );
$p15_other->close();

$vcap_offline_core = $vcap_offline();
vcap_life_assert( array() === $vcap_offline_core, 'A5: the order lifecycle checks of this suite (scheduled delivery, cancellation, refunds, balances) ran with outgoing HTTP blocked and Voucher Captain requested nothing (' . implode( ', ', $vcap_offline_core ) . ')' );
echo "Voucher Captain order lifecycle checks passed.\n";
