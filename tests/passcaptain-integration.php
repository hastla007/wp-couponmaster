<?php
/**
 * Voucher Captain as voucher provider of WP Pass Captain (both plugins active).
 *   wp eval-file tests/passcaptain-integration.php
 */

use PassCaptain\Integrations\Vouchers\Bridge;
use PassCaptain\Integrations\Vouchers\Checkout;
use PassCaptain\Orders;

function vcap_pc_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( 'FAIL: ' . $message );
	}
	echo "PASS: {$message}\n";
}

function vcap_pc_voucher( string $mode, float $value ): VCAP\Voucher {
	$coupon = new WC_Coupon();
	$coupon->set_code( 'pc-' . $mode . '-' . strtolower( wp_generate_password( 8, false ) ) );
	$coupon->set_discount_type( 'fixed_cart' );
	$coupon->set_amount( $value );
	$coupon->set_usage_limit( 'single' === $mode ? 1 : 0 );
	$coupon->update_meta_data( '_vcap_voucher', 'yes' );
	$coupon->save();
	$v                 = new VCAP\Voucher();
	$v->code           = wc_format_coupon_code( $coupon->get_code() );
	$v->coupon_id      = $coupon->get_id();
	$v->order_item_id  = 99000000 + $coupon->get_id();
	$v->type           = 'balance' === $mode ? 'giftcard' : 'voucher';
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

function vcap_pc_ticket_type( int $price_minor, string $currency = 'EUR' ): int {
	global $wpdb;
	$p   = $wpdb->prefix;
	$now = gmdate( 'Y-m-d H:i:s' );
	$wpdb->insert( "{$p}wpcap_events", array( 'name' => 'Voucher Konzert', 'slug' => 'vc' . uniqid(), 'description' => '', 'status' => 'published', 'created_at' => $now, 'updated_at' => $now ) );
	$event = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_occurrences", array( 'event_id' => $event, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 7 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
	$occ = $wpdb->insert_id;
	$wpdb->insert( "{$p}wpcap_ticket_types", array( 'occurrence_id' => $occ, 'name' => 'Standard', 'price_minor' => $price_minor, 'currency' => $currency, 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
	return (int) $wpdb->insert_id;
}

function vcap_pc_order( int $type, int $qty ): array {
	return Orders::create( array( 'email' => 'pc-' . wp_generate_password( 6, false ) . '@example.com', 'items' => array( array( 'ticket_type_id' => $type, 'quantity' => $qty ) ), 'idempotency_key' => 'pc-' . wp_generate_uuid4() ) );
}

function vcap_pc_redemption( int $order_id ): array {
	$snapshot = json_decode( (string) Orders::get( $order_id )->voucher_snapshot, true );
	$key      = (string) ( $snapshot['reservation']['redemption_key'] ?? '' );
	return '' === $key ? array() : vcap()->redemptions->get( $key );
}

wp_set_current_user( 1 ); // Pass Captain refunds need a user allowed to refund.
update_option( 'woocommerce_currency', 'EUR' );
PassCaptain\Settings::update( array( 'refund_approval_threshold' => 0 ) );

vcap_pc_assert( Bridge::available() && Bridge::provider() instanceof VCAP\Compat\PassCaptainProvider, 'Voucher Captain is the voucher provider of Pass Captain' );
vcap_pc_assert( isset( VCAP\Channels::all()['passcaptain'] ), 'the Pass Captain redemption channel is offered on voucher products' );

$type = vcap_pc_ticket_type( 2000 );

$bad = Checkout::apply( vcap_pc_order( $type, 1 )['order_id'], 'NO-SUCH-CODE' );
vcap_pc_assert( is_wp_error( $bad ), 'unknown codes are refused in the ticket checkout' );

// Gift card (50 €) on 3 tickets (60 €): 50 € reserved, 10 € left to pay.
$card  = vcap_pc_voucher( 'balance', 50 );
$order = vcap_pc_order( $type, 3 );
$applied = Checkout::apply( $order['order_id'], strtoupper( $card->code ) );
vcap_pc_assert( ! is_wp_error( $applied ) && 5000 === $applied['applied_minor'] && 1000 === $applied['total_minor'], 'a gift card pays 50 of a 60 ticket order' );
vcap_pc_assert( 0.0 === vcap()->vouchers->get( $card->id )->balance && 'reserved' === vcap_pc_redemption( $order['order_id'] )['state'], 'the gift card balance is reserved before payment' );
$held_until = strtotime( vcap_pc_redemption( $order['order_id'] )['expires_at'] . ' UTC' );
$manual     = (int) PassCaptain\Settings::get( 'manual_hold_hours' ) * HOUR_IN_SECONDS;
vcap_pc_assert( $held_until > time() + max( $manual, 15 * MINUTE_IN_SECONDS ), 'the reservation outlives Pass Captain\'s payment window (no release before a late bank transfer)' );

Orders::confirm_manual_payment( $order['order_id'] );
Checkout::commit( $order['order_id'] );
Checkout::commit( $order['order_id'] );
$redemption = vcap_pc_redemption( $order['order_id'] );
vcap_pc_assert( 'committed' === $redemption['state'], 'payment commits the redemption (repeats are harmless)' );
$events = array_count_values( array_column( vcap()->vouchers->events( $card->id ), 'event' ) );
vcap_pc_assert( 1 === ( $events['api_reserved'] ?? 0 ) && 1 === ( $events['api_committed'] ?? 0 ), 'the voucher ledger shows one reservation and one commit' );

// Refund one of three tickets: one third of the voucher share goes back to the gift card.
$tickets = Orders::tickets_for_order( $order['order_id'] );
$refund  = PassCaptain\Refunds::request( $order['order_id'], array( (int) $tickets[0]->ticket_id ), 'Storno', 'pc-r1' );
vcap_pc_assert( is_array( $refund ) && ! empty( $refund['refund_id'] ), 'Pass Captain records the ticket refund' );
Checkout::refund( $refund['refund_id'], $order['order_id'] );
Checkout::refund( $refund['refund_id'], $order['order_id'] );
vcap_pc_assert( 16.66 === vcap()->vouchers->get( $card->id )->balance, 'the voucher share of the refunded ticket returns to the gift card exactly once (16.66)' );

// Single-use voucher (15 €) on a cancelled order: released and usable again.
$single = vcap_pc_voucher( 'single', 15 );
$order2 = vcap_pc_order( $type, 1 );
$applied2 = Checkout::apply( $order2['order_id'], $single->code );
vcap_pc_assert( ! is_wp_error( $applied2 ) && 1500 === $applied2['applied_minor'] && VCAP\Voucher::STATUS_RESERVED === vcap()->vouchers->get( $single->id )->status, 'a single-use voucher is reserved for the ticket order' );
Orders::cancel_order( $order2['order_id'], 'test' );
Checkout::release( $order2['order_id'] );
vcap_pc_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $single->id )->status && 'released' === vcap_pc_redemption( $order2['order_id'] )['state'], 'cancelling the ticket order releases the voucher' );

// The same voucher pays a whole order; a full refund makes it usable again.
$order3 = vcap_pc_order( $type, 1 );
$order3_total = (int) Orders::get( $order3['order_id'] )->total_minor;
$big = vcap_pc_voucher( 'single', 30 );
$applied3 = Checkout::apply( $order3['order_id'], $big->code );
vcap_pc_assert( ! is_wp_error( $applied3 ) && 0 === $applied3['total_minor'] && 'paid' === Orders::get( $order3['order_id'] )->payment_status, 'a voucher covering the whole order confirms it' );
Checkout::commit( $order3['order_id'] );
vcap_pc_assert( VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $big->id )->status, 'the single-use voucher is redeemed after the free order' );
$tickets3 = Orders::tickets_for_order( $order3['order_id'] );
$refund3  = PassCaptain\Refunds::request( $order3['order_id'], array( (int) $tickets3[0]->ticket_id ), 'Storno', 'pc-r3' );
Checkout::refund( $refund3['refund_id'], $order3['order_id'] );
vcap_pc_assert( VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $big->id )->status, 'a full ticket refund makes the single-use voucher usable again' );
vcap_pc_assert( true === vcap()->coupons->validate( true, new WC_Coupon( $big->coupon_id ) ), 'the WooCommerce coupon accepts the restored voucher again' );

// Vouchers in another currency are refused.
$usd   = vcap_pc_ticket_type( 2000, 'USD' );
$other = Checkout::apply( vcap_pc_order( $usd, 1 )['order_id'], vcap_pc_voucher( 'balance', 20 )->code );
vcap_pc_assert( is_wp_error( $other ), 'vouchers are refused for ticket orders in another currency' );

// Shop-only vouchers stay in the shop.
$shop = vcap_pc_voucher( 'balance', 20 );
add_filter( 'vcap_redemption_channel_allowed', $only_shop = static fn( $allowed, $voucher, $channel ) => $voucher->id === $shop->id ? 'shop' === $channel : $allowed, 10, 3 );
vcap_pc_assert( is_wp_error( Checkout::apply( vcap_pc_order( $type, 1 )['order_id'], $shop->code ) ), 'vouchers without the Pass Captain channel are refused' );
remove_filter( 'vcap_redemption_channel_allowed', $only_shop, 10 );

// Late payment after Pass Captain expired the order and released the voucher (Pass Captain with the commit fix).
if ( method_exists( Checkout::class, 'handle_job' ) ) {
	global $wpdb;
	$late_card  = vcap_pc_voucher( 'balance', 15 ); // Less than the 20 € ticket: the order stays unpaid until the transfer.
	$late_order = vcap_pc_order( $type, 1 );
	Checkout::apply( $late_order['order_id'], $late_card->code );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_reservations SET status='expired', expires_at=%s WHERE order_id=%d", gmdate( 'Y-m-d H:i:s', time() - 60 ), $late_order['order_id'] ) );
	$wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->prefix}wpcap_orders SET status='expired', payment_status='expired' WHERE order_id=%d", $late_order['order_id'] ) );
	Checkout::release( $late_order['order_id'] );
	vcap_pc_assert( 'pending' === Orders::get( $late_order['order_id'] )->payment_status || 'expired' === Orders::get( $late_order['order_id'] )->payment_status, 'the partly voucher-paid ticket order waits for the transfer' );
	vcap_pc_assert( 15.0 === vcap()->vouchers->get( $late_card->id )->balance, 'an expired ticket order gives the gift card balance back' );
	$paid = Orders::confirm_external_payment( $late_order['order_id'], 'late-transfer' );
	vcap_pc_assert( true === $paid && 'committed' === vcap_pc_redemption( $late_order['order_id'] )['state'] && 0.0 === vcap()->vouchers->get( $late_card->id )->balance, 'a late payment reserves and charges the gift card again (no free discount)' );
}

// Ticket vouchers: a voucher product names a Pass Captain ticket type; the voucher is exchanged for tickets.
if ( class_exists( 'PassCaptain\\Integrations\\Vouchers\\TicketService' ) ) {
	global $wpdb;
	$ticket_type = vcap_pc_ticket_type( 3500 );
	vcap_pc_assert( null !== VCAP\Compat\PassCaptainTickets::service(), 'Pass Captain offers its ticket service to Voucher Captain' );
	$tv_product = new WC_Product_Simple();
	$tv_product->set_name( 'Concert voucher (2 tickets)' );
	$tv_product->set_regular_price( '70' );
	$tv_product->set_virtual( true );
	$tv_product->update_meta_data( '_vcap_enabled', 'yes' );
	$tv_product->update_meta_data( '_vcap_type', 'voucher' );
	$tv_product->update_meta_data( VCAP\Compat\PassCaptainTickets::META_TYPE, $ticket_type );
	$tv_product->update_meta_data( VCAP\Compat\PassCaptainTickets::META_QTY, 2 );
	$tv_product->save();
	$tv_order = wc_create_order();
	$tv_order->add_product( $tv_product, 1 );
	$tv_order->set_billing_email( 'ticket-buyer@example.com' );
	$tv_order->calculate_totals();
	$tv_order->set_status( 'processing' );
	$tv_order->save();
	vcap()->issuer->issue_for_order( $tv_order, true );
	$tv = vcap()->vouchers->for_order( $tv_order->get_id() )[0] ?? null;
	$tv_target = $tv ? VCAP\Compat\PassCaptainTickets::target( $tv ) : null;
	vcap_pc_assert( $tv_target && $ticket_type === $tv_target['ticket_type_id'] && 2 === $tv_target['quantity'], 'the sold voucher stores its ticket target with the order line' );

	wp_set_current_user( 0 ); // A guest on the redeem page: tickets go to the voucher's recipient or buyer.
	$done = vcap()->passcaptain_tickets->exchange( $tv->code );
	if ( is_wp_error( $done ) ) {
		echo 'Exchange error: ' . $done->get_error_code() . ' – ' . $done->get_error_message() . "\n";
	}
	$pc_order = is_array( $done ) ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}wpcap_orders WHERE public_id=%s", $done['order_public_id'] ) ) : null;
	vcap_pc_assert( is_array( $done ) && 2 === $done['tickets'] && 'ticket-buyer@example.com' === $done['email'] && $pc_order && 'paid' === $pc_order->payment_status && 0 === (int) $pc_order->total_minor, 'the voucher is exchanged for two free Pass Captain tickets' );
	vcap_pc_assert( VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $tv->id )->status && 'committed' === vcap()->redemptions->get( VCAP\Compat\PassCaptainTickets::key( $tv ) )['state'], 'the voucher is redeemed only after the tickets were issued' );
	$again = vcap()->passcaptain_tickets->exchange( $tv->code );
	vcap_pc_assert( is_array( $again ) && $again['order_public_id'] === $done['order_public_id'] && 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d", $pc_order->order_id ) ), 'exchanging again returns the same tickets (no second order)' );
	vcap_pc_assert( 'passcaptain_tickets' === array_column( vcap()->vouchers->events( $tv->id ), 'event' )[ count( vcap()->vouchers->events( $tv->id ) ) - 1 ], 'the exchange is in the voucher history' );

	wp_set_current_user( 1 );
	vcap()->issuer->void( vcap()->vouchers->get( $tv->id ), 'Purchase order refunded' );
	vcap_pc_assert( 0 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}wpcap_tickets WHERE order_id=%d AND status='valid'", $pc_order->order_id ) ), 'voiding the voucher (purchase refunded) revokes its tickets' );

	// Sold out: nothing is redeemed, the voucher stays usable.
	$full_type = vcap_pc_ticket_type( 1000 );
	$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", array( 'capacity' => 1 ), array( 'ticket_type_id' => $full_type ) );
	$full = vcap_pc_voucher( 'single', 20 );
	$give = static fn( $t, $v ) => $v->id === $full->id ? array( 'ticket_type_id' => $full_type, 'quantity' => 2 ) : $t;
	add_filter( 'vcap_passcaptain_ticket_target', $give, 10, 2 );
	$refused = vcap()->passcaptain_tickets->exchange( $full->code );
	vcap_pc_assert( is_wp_error( $refused ) && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $full->id )->status, 'a sold-out ticket type refuses the exchange and the voucher stays usable' );
	$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", array( 'capacity' => 10 ), array( 'ticket_type_id' => $full_type ) );
	$second_try = vcap()->passcaptain_tickets->exchange( $full->code );
	vcap_pc_assert( is_array( $second_try ) && 2 === $second_try['tickets'] && 'committed' === vcap()->redemptions->get( VCAP\Compat\PassCaptainTickets::key( $full, 2 ) )['state'], 'after a failed attempt the voucher can be exchanged again (new keys)' );
	remove_filter( 'vcap_passcaptain_ticket_target', $give, 10 );
	vcap_pc_assert( is_wp_error( vcap()->passcaptain_tickets->exchange( vcap_pc_voucher( 'single', 10 )->code ) ), 'vouchers without a ticket target are not exchanged' );

	// "Customer chooses the date": the voucher names an event; the customer picks one of its upcoming dates.
	if ( method_exists( VCAP\Compat\PassCaptainTickets::service(), 'options' ) ) {
		$first = vcap_pc_ticket_type( 3000 );
		$ev    = $wpdb->get_row( $wpdb->prepare( "SELECT o.event_id FROM {$wpdb->prefix}wpcap_ticket_types tt JOIN {$wpdb->prefix}wpcap_occurrences o ON o.occurrence_id=tt.occurrence_id WHERE tt.ticket_type_id=%d", $first ) );
		$wpdb->insert( "{$wpdb->prefix}wpcap_occurrences", array( 'event_id' => $ev->event_id, 'starts_at' => gmdate( 'Y-m-d H:i:s', time() + 21 * DAY_IN_SECONDS ), 'timezone' => 'Europe/Vienna', 'location' => 'Wien', 'capacity' => 0, 'status' => 'scheduled' ) );
		$wpdb->insert( "{$wpdb->prefix}wpcap_ticket_types", array( 'occurrence_id' => $wpdb->insert_id, 'name' => 'Standard', 'price_minor' => 3000, 'currency' => 'EUR', 'capacity' => 100, 'max_per_order' => 10, 'status' => 'active' ) );
		$second = (int) $wpdb->insert_id;
		$ev_product = new WC_Product_Simple();
		$ev_product->set_name( 'Concert voucher (any date)' );
		$ev_product->set_regular_price( '30' );
		$ev_product->set_virtual( true );
		$ev_product->update_meta_data( '_vcap_enabled', 'yes' );
		$ev_product->update_meta_data( '_vcap_type', 'voucher' );
		$ev_product->update_meta_data( VCAP\Compat\PassCaptainTickets::META_EVENT, (int) $ev->event_id );
		$ev_product->update_meta_data( VCAP\Compat\PassCaptainTickets::META_NAME, 'Standard' );
		$ev_product->update_meta_data( VCAP\Compat\PassCaptainTickets::META_QTY, 1 );
		$ev_product->save();
		$ev_order = wc_create_order();
		$ev_order->add_product( $ev_product, 1 );
		$ev_order->set_billing_email( 'any-date@example.com' );
		$ev_order->calculate_totals();
		$ev_order->set_status( 'processing' );
		$ev_order->save();
		vcap()->issuer->issue_for_order( $ev_order, true );
		$ev_voucher = vcap()->vouchers->for_order( $ev_order->get_id() )[0];
		$choices    = array_map( 'intval', array_column( VCAP\Compat\PassCaptainTickets::options( $ev_voucher ), 'ticket_type_id' ) );
		vcap_pc_assert( array( $first, $second ) === $choices, 'an event voucher offers every upcoming date of the event' );
		wp_set_current_user( 0 );
		$ask = vcap()->passcaptain_tickets->exchange( $ev_voucher->code );
		vcap_pc_assert( is_wp_error( $ask ) && 'vcap_ticket_choice_required' === $ask->get_error_code() && 2 === count( $ask->get_error_data()['options'] ) && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $ev_voucher->id )->status, 'without a chosen date the customer is asked to choose one (nothing is redeemed)' );
		vcap_pc_assert( is_wp_error( vcap()->passcaptain_tickets->exchange( $ev_voucher->code, array( 'ticket_type_id' => $ticket_type ) ) ), 'a date of another event cannot be chosen' );
		$picked = vcap()->passcaptain_tickets->exchange( $ev_voucher->code, array( 'ticket_type_id' => $second ) );
		$picked_type = is_array( $picked ) ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT t.ticket_type_id FROM {$wpdb->prefix}wpcap_tickets t JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=t.order_id WHERE o.public_id=%s", $picked['order_public_id'] ) ) : 0;
		vcap_pc_assert( is_array( $picked ) && 1 === $picked['tickets'] && $second === $picked_type && VCAP\Voucher::STATUS_REDEEMED === vcap()->vouchers->get( $ev_voucher->id )->status, 'the ticket is issued for the chosen date' );
		// Sold-out dates are not offered; required attendee fields are asked before the tickets are issued.
		$wpdb->update( "{$wpdb->prefix}wpcap_ticket_types", array( 'capacity' => 1 ), array( 'ticket_type_id' => $first ) );
		vcap_pc_order( $first, 1 ); // The last place of the first date is held by another buyer.
		$wpdb->insert( "{$wpdb->prefix}wpcap_participant_fields", array( 'ticket_type_id' => $second, 'field_key' => 'name', 'label' => 'Name', 'field_type' => 'text', 'required' => 1, 'status' => 'active' ) );
		$ev_order2 = wc_create_order();
		$ev_order2->add_product( $ev_product, 1 );
		$ev_order2->set_billing_email( 'any-date2@example.com' );
		$ev_order2->calculate_totals();
		$ev_order2->set_status( 'processing' );
		$ev_order2->save();
		vcap()->issuer->issue_for_order( $ev_order2, true );
		$ev_voucher2 = vcap()->vouchers->for_order( $ev_order2->get_id() )[0];
		vcap_pc_assert( array( $second ) === array_map( 'intval', array_column( VCAP\Compat\PassCaptainTickets::options( $ev_voucher2 ), 'ticket_type_id' ) ), 'a sold-out date is no longer offered' );
		wp_set_current_user( 0 );
		$need = vcap()->passcaptain_tickets->exchange( $ev_voucher2->code, array( 'ticket_type_id' => $second ) );
		vcap_pc_assert( is_wp_error( $need ) && 'vcap_ticket_attendees_required' === $need->get_error_code() && 'name' === $need->get_error_data()['fields'][0]['key'] && VCAP\Voucher::STATUS_ACTIVE === vcap()->vouchers->get( $ev_voucher2->id )->status, 'required attendee details are asked before anything is redeemed' );
		$with = vcap()->passcaptain_tickets->exchange( $ev_voucher2->code, array( 'ticket_type_id' => $second, 'attendees' => array( array( 'answers' => array( 'name' => 'Erika Muster' ) ) ) ) );
		$answer = is_array( $with ) ? $wpdb->get_var( $wpdb->prepare( "SELECT a.answer FROM {$wpdb->prefix}wpcap_participant_answers a JOIN {$wpdb->prefix}wpcap_tickets t ON t.ticket_id=a.ticket_id JOIN {$wpdb->prefix}wpcap_orders o ON o.order_id=t.order_id WHERE o.public_id=%s", $with['order_public_id'] ) ) : '';
		vcap_pc_assert( is_array( $with ) && 'Erika Muster' === $answer, 'the attendee details are stored with the issued ticket' );
		wp_set_current_user( 1 );
	}
}

// Shared scanner: Voucher Captain's scanner checks in Pass Captain tickets (and still finds vouchers first).
if ( null !== VCAP\Compat\PassCaptainScanner::service() ) {
	$mails = array();
	$grab  = static function ( $args ) use ( &$mails ) { $mails[] = (string) ( $args['message'] ?? '' ); return $args; };
	add_filter( 'wp_mail', $grab );
	$scan_voucher = vcap_pc_voucher( 'single', 20 );
	$scan_type    = vcap_pc_ticket_type( 2000 );
	$give_scan    = static fn( $t, $v ) => $v->id === $scan_voucher->id ? array( 'ticket_type_id' => $scan_type, 'quantity' => 1 ) : $t;
	add_filter( 'vcap_passcaptain_ticket_target', $give_scan, 10, 2 );
	wp_set_current_user( 1 );
	$scan_done = vcap()->passcaptain_tickets->exchange( $scan_voucher->code, array( 'email' => 'scanner@example.com' ) );
	remove_filter( 'vcap_passcaptain_ticket_target', $give_scan, 10 );
	remove_filter( 'wp_mail', $grab );
	// The ticket code, not the order access token that the same mail may contain.
	preg_match( '/(?:wpcap_ticket=|Ticket-Code: )([a-f0-9]{64})/', implode( "\n", $mails ), $tm );
	$token = $tm[1] ?? '';
	vcap_pc_assert( is_array( $scan_done ) && '' !== $token, 'a Pass Captain ticket code reaches the customer by mail' );
	$scan = static function ( string $route, array $params ) {
		$req = new WP_REST_Request( 'lookup' === $route ? 'GET' : 'POST', '/vcap/v1/' . $route );
		foreach ( $params as $k => $v ) {
			$req->set_param( $k, $v );
		}
		$res = 'lookup' === $route ? vcap()->tickets->rest_lookup( $req ) : vcap()->tickets->rest_checkin( $req );
		return is_wp_error( $res ) ? $res : $res->get_data();
	};
	$seen = $scan( 'lookup', array( 'q' => home_url( '/?wpcap_ticket=' . $token ) ) );
	if ( ! is_array( $seen ) || 'ok' !== ( $seen['state'] ?? '' ) ) {
		echo 'Scanner lookup: ' . wp_json_encode( is_wp_error( $seen ) ? $seen->get_error_message() : $seen ) . "\n";
	}
	vcap_pc_assert( is_array( $seen ) && 'passcaptain' === $seen['type'] && 'ok' === $seen['state'] && true === $seen['can_checkin'], 'the shared scanner recognises a Pass Captain ticket (URL from the QR code)' );
	$in = $scan( 'checkin', array( 'q' => $token ) );
	vcap_pc_assert( is_array( $in ) && 'ok' === $in['state'] && true === $in['success'], 'the shared scanner checks the ticket in at Pass Captain' );
	$twice = $scan( 'checkin', array( 'q' => $token ) );
	vcap_pc_assert( is_array( $twice ) && 'ok' !== $twice['state'] && false === $twice['success'], 'a second scan does not admit the ticket again' );
	$own = $scan( 'lookup', array( 'q' => vcap_pc_voucher( 'balance', 10 )->code ) );
	vcap_pc_assert( is_array( $own ) && 'giftcard' === $own['type'], 'voucher codes are still handled by Voucher Captain' );
	vcap_pc_assert( false !== strpos( VCAP\Compat\PassCaptain::status_html(), '✔' ), 'the settings show the Pass Captain integration as active' );
}

// Minor units follow ISO 4217 (as Pass Captain does), not the shop's price display setting.
$decimals = static fn() => 0;
add_filter( 'wc_get_price_decimals', $decimals );
vcap_pc_assert( 2 === VCAP\Compat\PassCaptainProvider::decimals( get_woocommerce_currency() ) && 0 === VCAP\Compat\PassCaptainProvider::decimals( 'JPY' ), 'minor units use ISO 4217 decimals, whatever the shop displays' );
remove_filter( 'wc_get_price_decimals', $decimals );

echo "Voucher Captain + Pass Captain checks passed.\n";
