<?php
/**
 * Locked premium features keep processing what was sold earlier (TODO "Lizenz-Gate beim Booten"): with every premium
 * feature locked, refunds still reverse top-ups, referral rewards and bonus vouchers, cancelled orders give promotion
 * uses back, Pass Captain ticket vouchers are still exchanged – while new top-up and ticket-voucher sales, new
 * promotions and referral codes are refused.
 *
 *   wp eval-file tests/license-lifecycle-integration.php            development build (locks through the test filter)
 *   wp eval-file tests/license-lifecycle-integration.php free       free release ZIP (VCAP_EDITION 'free')
 *   wp eval-file tests/license-lifecycle-integration.php premium    premium release ZIP before license activation
 *
 * free/premium boot with the features locked, so the hook checks also prove the boot-time registration.
 */

use VCAP\Compat\PassCaptain;
use VCAP\Compat\PassCaptainTickets;
use VCAP\Discounts\Bonus;
use VCAP\Discounts\CartIntegration;
use VCAP\Discounts\Usage;
use VCAP\Licensing\Entitlements;
use VCAP\Referrals;
use VCAP\TopUp;

function vcap_lc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

/** Whether $hook has a callback [instance of $class, $method] (or the static [$class, $method]). */
function vcap_lc_hooked( string $hook, string $class, string $method ): bool {
	global $wp_filter;
	if ( empty( $wp_filter[ $hook ] ) ) {
		return false;
	}
	foreach ( $wp_filter[ $hook ]->callbacks as $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$fn = $callback['function'];
			if ( is_array( $fn ) && $method === $fn[1] && ( $fn[0] instanceof $class || $class === $fn[0] ) ) {
				return true;
			}
		}
	}
	return false;
}

function vcap_lc_card( float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'vcap-lc-' . strtolower( wp_generate_password( 10, false, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 98000000 + $coupon->get_id();
	$v->type           = 'giftcard';
	$v->usage_mode     = 'balance';
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

function vcap_lc_order( WC_Product $product, string $email, int $customer = 0 ): WC_Order {
	$order = wc_create_order( array( 'customer_id' => $customer ) );
	$order->add_product( $product, 1 );
	$order->set_billing_email( $email );
	$order->calculate_totals();
	$order->save();
	return $order;
}

/** Full refund the way the order screen books it (no gateway refund): the order becomes "refunded". */
function vcap_lc_refund( WC_Order $order ): WC_Order {
	$refund = wc_create_refund( array( 'order_id' => $order->get_id(), 'amount' => $order->get_total(), 'reason' => 'License lifecycle test' ) );
	vcap_lc_assert( ! is_wp_error( $refund ), 'full refund booked for order #' . $order->get_id() );
	$order = wc_get_order( $order->get_id() );
	if ( 'refunded' !== $order->get_status() ) {
		$order->update_status( 'refunded' );
	}
	return wc_get_order( $order->get_id() );
}

$expect = (string) ( $args[0] ?? Entitlements::DEVELOPMENT );
$tag    = strtolower( wp_generate_password( 6, false ) );
vcap_lc_assert( $expect === Entitlements::edition(), "edition is {$expect}" );
if ( Entitlements::DEVELOPMENT === $expect ) {
	add_filter( 'vcap_license_required', '__return_true' ); // Development: lock through the test hook.
}
Entitlements::reset();
vcap_lc_assert( array( 'promotions', 'referrals', 'topup', 'reports', 'passcaptain' ) === Entitlements::locked(), 'all five premium features are locked' );

/* ------------------------------------------------------------------ hooks */

$lifecycle = array(
	array( 'vcap_vouchers_per_item', TopUp::class, 'top_up_instead' ),
	array( 'woocommerce_order_status_refunded', TopUp::class, 'revoke' ),
	array( 'woocommerce_order_status_cancelled', TopUp::class, 'revoke' ),
	array( 'woocommerce_order_status_refunded', Referrals::class, 'revoke' ),
	array( Referrals::RETRY_HOOK, Referrals::class, 'revoke' ),
	array( 'woocommerce_order_status_completed', Referrals::class, 'reward' ),
	array( 'woocommerce_coupon_is_valid', Referrals::class, 'validate' ),
	array( 'woocommerce_order_status_refunded', Bonus::class, 'withdraw' ),
	array( 'woocommerce_order_status_cancelled', Usage::class, 'revert' ),
	array( 'vcap_release_stale_order_reservations', Usage::class, 'release_abandoned' ),
	array( 'woocommerce_coupon_discount_types', CartIntegration::class, 'types' ),
	array( 'woocommerce_get_shop_coupon_data', CartIntegration::class, 'virtual_coupon' ),
	array( PassCaptain::FILTER, PassCaptain::class, 'provider' ),
	array( 'vcap_redemption_channels', PassCaptain::class, 'channels' ),
	array( 'vcap_redeem_code', PassCaptainTickets::class, 'redeem_page' ),
	array( 'vcap_voucher_event', PassCaptainTickets::class, 'on_voucher_event' ),
	array( 'vcap_line_snapshot', PassCaptainTickets::class, 'snapshot' ),
);
$missing = array_filter( $lifecycle, static fn( $h ) => ! vcap_lc_hooked( ...$h ) );
vcap_lc_assert( ! $missing, 'lifecycle hooks of top-ups, referrals, promotions and Pass Captain are registered while locked' . ( $missing ? ': ' . wp_json_encode( array_values( $missing ) ) : '' ) );
vcap_lc_assert( array_key_exists( CartIntegration::TYPE, wc_get_coupon_types() ), 'the promotion coupon type stays registered (orders with promotions can be recalculated)' );

if ( Entitlements::DEVELOPMENT !== $expect ) {
	// Booted locked: the sales and configuration side is not registered.
	vcap_lc_assert( ! vcap_lc_hooked( 'vcap_product_settings_fields', TopUp::class, 'product_field' ) && ! vcap_lc_hooked( 'woocommerce_before_add_to_cart_button', TopUp::class, 'code_field' ), 'top-up product setting and code field are not registered' );
	vcap_lc_assert( ! vcap_lc_hooked( 'vcap_account_vouchers_top', Referrals::class, 'account_block' ), 'the refer-a-friend block in My Account is not registered' );
	vcap_lc_assert( ! vcap_lc_hooked( 'vcap_product_settings_fields', PassCaptainTickets::class, 'product_fields' ) && ! vcap_lc_hooked( 'vcap_scanner_lookup', VCAP\Compat\PassCaptainScanner::class, 'lookup' ), 'ticket-voucher product settings and the shared scanner are not registered' );
	vcap_lc_assert( ! vcap_lc_hooked( 'admin_post_vcap_report_csv', VCAP\Admin\ReportsAdmin::class, 'export' ), 'the report export is not registered' );
}
if ( Entitlements::FREE === $expect ) {
	vcap_lc_assert( ! vcap_lc_hooked( 'pre_set_site_transient_update_plugins', VCAP\Licensing\PremiumUpdater::class, 'inject_update' ) && false === has_action( Entitlements::REFRESH_HOOK ) && null === Entitlements::client(), 'free edition: no premium updater, no license refresh, no license server' );
	vcap_lc_assert( str_contains( Entitlements::locked_message( 'promotions' ), 'Premium' ), 'free edition: locked features point to Voucher Captain Premium' );
}

/* ------------------------------------------------------------------ top-up */

$card    = vcap_lc_card( 20 );
$product = new WC_Product_Simple();
$product->set_name( 'Locked top-up ' . $tag );
$product->set_regular_price( '30' );
$product->set_virtual( true );
$product->update_meta_data( '_vcap_enabled', 'yes' );
$product->update_meta_data( '_vcap_type', 'giftcard' );
$product->update_meta_data( '_vcap_usage', 'balance' );
$product->update_meta_data( TopUp::PRODUCT_META, 'yes' );
$product->set_status( 'publish' );
$product->save();
$order = wc_create_order();
$line  = $order->get_item( $order->add_product( $product, 1 ) );
$line->add_meta_data( TopUp::ITEM_META, $card->code, true );
$line->save();
$order->set_billing_email( 'locked-topup@example.com' );
$order->calculate_totals();
$order->set_status( 'processing' );
$order->save();
vcap()->issuer->issue_for_order( wc_get_order( $order->get_id() ), true );
vcap_lc_assert( 50.0 === (float) vcap()->vouchers->get( $card->id )->balance && array() === vcap()->vouchers->for_order( $order->get_id() ), 'an open top-up order paid while locked tops up the card (no new gift card)' );
vcap_lc_refund( wc_get_order( $order->get_id() ) );
vcap_lc_assert( 20.0 === (float) vcap()->vouchers->get( $card->id )->balance, 'refunding the top-up order while locked takes the top-up back' );
if ( Entitlements::DEVELOPMENT !== $expect ) {
	vcap_lc_assert( ! wc_get_product( $product->get_id() )->is_purchasable(), 'top-up products are not sold while locked' );
}

/* ------------------------------------------------------------------ referral reward */

update_option( 'vcap_store_credit', 'yes' );
update_option( 'vcap_referral_enabled', 'yes' );
$referrer = (int) wp_insert_user( array( 'user_login' => 'vcap_lc_ref_' . $tag, 'user_pass' => wp_generate_password( 20 ), 'user_email' => 'lc-ref-' . $tag . '@example.com', 'role' => 'customer' ) );
$plain    = new WC_Product_Simple();
$plain->set_name( 'Locked lifecycle product ' . $tag );
$plain->set_regular_price( '20' );
$plain->set_status( 'publish' );
$plain->save();
$friend = vcap_lc_order( $plain, 'lc-friend-' . $tag . '@example.com' );
$friend->set_status( 'completed' );
$friend->update_meta_data( Referrals::REWARDED, $referrer );
$friend->update_meta_data( Referrals::REWARD, array( 'type' => 'credit', 'user' => $referrer, 'amount' => 15.0 ) );
$friend->save();
$credited = vcap()->store_credit->adjust( $referrer, 15, 'Referral reward (lifecycle test)', $friend->get_id(), 'credit' );
vcap_lc_assert( ! is_wp_error( $credited ) && 15.0 === vcap()->store_credit->balance( $referrer ), 'the referrer holds a 15 store credit reward from before the lock' );
vcap_lc_refund( wc_get_order( $friend->get_id() ) );
$reward = wc_get_order( $friend->get_id() )->get_meta( Referrals::REWARD );
vcap_lc_assert( 0.0 === vcap()->store_credit->balance( $referrer ) && ! empty( $reward['revoked'] ), 'refunding the friend order while locked withdraws the referral reward' );
$program = new Referrals( vcap()->settings, vcap()->store_credit );
$code    = $program->coupon_for( $referrer );
$other   = vcap_lc_order( $plain, 'lc-new-friend-' . $tag . '@example.com' );
vcap_lc_assert( $code && is_wp_error( $other->apply_coupon( $code->get_code() ) ), 'referral codes are refused while locked (no new referrals)' );

/* ------------------------------------------------------------------ promotion hold and bonus voucher */

$campaign = (int) wp_insert_post( array( 'post_type' => VCAP\Discounts\Repository::POST_TYPE, 'post_status' => 'publish', 'post_title' => 'Locked promotion ' . $tag ) );
$held     = vcap_lc_order( $plain, 'lc-hold-' . $tag . '@example.com' );
$held->update_meta_data( Usage::ORDER_KEY, 'e:lc-hold-' . $tag . '@example.com' );
$held->update_meta_data( Usage::ORDER_META, array( $campaign ) );
$held->save();
Usage::add( $campaign, 'e:lc-hold-' . $tag . '@example.com' );
vcap_lc_assert( 1 === Usage::count( $campaign ), 'an unpaid order holds one use of the promotion' );
wc_get_order( $held->get_id() )->update_status( 'cancelled' );
vcap_lc_assert( 0 === Usage::count( $campaign ), 'cancelling the order while locked gives the promotion use back' );
update_option( 'vcap_campaigns_enabled', 'yes' );
vcap_lc_assert( ! ( new CartIntegration( vcap()->settings, new VCAP\Discounts\Repository() ) )->enabled(), 'no promotion is applied to new carts while locked (even with promotions switched on)' );
update_option( 'vcap_campaigns_enabled', 'no' );

$bonus = new WC_Coupon();
$bonus->set_code( 'lc-bonus-' . $tag );
$bonus->set_discount_type( 'fixed_cart' );
$bonus->set_amount( 5 );
$bonus->update_meta_data( Bonus::COUPON_SOURCE, $campaign );
$bonus->save();
$bonus_order = vcap_lc_order( $plain, 'lc-bonus-' . $tag . '@example.com' );
$bonus_order->set_status( 'processing' );
$bonus_order->update_meta_data( Bonus::ORDER_META, array( $campaign => $bonus->get_id() ) );
$bonus_order->save();
vcap_lc_refund( wc_get_order( $bonus_order->get_id() ) );
vcap_lc_assert( 'trash' === get_post_status( $bonus->get_id() ), 'refunding the order while locked withdraws its unused bonus voucher' );

/* ------------------------------------------------------------------ Pass Captain ticket voucher */

if ( PassCaptain::active() && PassCaptainTickets::service() ) {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Locked concert', 'slug' => 'lc' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => 2000, 'currency' => get_woocommerce_currency(), 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
	$type = (int) $wpdb->insert_id;

	$ticket = vcap_lc_card( 20 );
	$ticket->usage_mode  = 'single';
	$ticket->type        = 'voucher';
	$ticket->buyer_email = 'lc-ticket-' . $tag . '@example.com';
	vcap()->vouchers->update( $ticket );
	$give = static fn( $t, $v ) => $v->id === $ticket->id ? array( 'ticket_type_id' => $type, 'quantity' => 1 ) : $t;
	add_filter( 'vcap_passcaptain_ticket_target', $give, 10, 2 );
	$done = vcap()->passcaptain_tickets->exchange( $ticket->code );
	remove_filter( 'vcap_passcaptain_ticket_target', $give, 10 );
	vcap_lc_assert( is_array( $done ) && 1 === (int) $done['tickets'] && VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $ticket->id )->status, 'a sold ticket voucher is still exchanged for Pass Captain tickets while locked' . ( is_wp_error( $done ) ? ' – ' . $done->get_error_code() : '' ) );

	$ticket_product = new WC_Product_Simple();
	$ticket_product->set_name( 'Locked ticket voucher ' . $tag );
	$ticket_product->set_regular_price( '20' );
	$ticket_product->set_status( 'publish' );
	$ticket_product->update_meta_data( '_vcap_enabled', 'yes' );
	$ticket_product->update_meta_data( PassCaptainTickets::META_TYPE, $type );
	$ticket_product->save();
	if ( Entitlements::DEVELOPMENT !== $expect ) {
		vcap_lc_assert( ! wc_get_product( $ticket_product->get_id() )->is_purchasable(), 'ticket-voucher products are not sold while locked' );
	}
} else {
	echo "SKIP: WP Pass Captain is not active – ticket voucher exchange not checked here (runs in the pass-captain job).\n";
}

update_option( 'vcap_store_credit', 'no' );
update_option( 'vcap_referral_enabled', 'no' );
if ( Entitlements::DEVELOPMENT === $expect ) {
	remove_filter( 'vcap_license_required', '__return_true' );
	Entitlements::reset();
}
echo "Voucher Captain license lifecycle checks passed ({$expect}).\n";
