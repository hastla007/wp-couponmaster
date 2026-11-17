<?php
/**
 * Two checkouts at the same time with the same gift cards (run as separate processes).
 *   wp eval-file tests/parallel-checkout.php setup
 *   wp eval-file tests/parallel-checkout.php reserve 0 &  wp eval-file tests/parallel-checkout.php reserve 1 &  wait
 *   wp eval-file tests/parallel-checkout.php verify
 *
 * Each of several 50 € gift cards is reserved with 30 € by both processes for different orders at the same moment.
 * Exactly one reservation per card may succeed, and reserved amount + balance must always equal 50. Then both
 * processes book 5 × 1 € at the counter on every card at the same time: all 10 bookings succeed, and the WooCommerce
 * coupon amount must equal the stored balance afterwards (the coupon is saved under the row lock, T9).
 */

$vcap_parallel_option = 'vcap_parallel_checkout_test';
$vcap_parallel_cards  = 8;

$mode = $args[0] ?? '';

if ( 'setup' === $mode ) {
	$cards = array();
	for ( $i = 0; $i < $vcap_parallel_cards; $i++ ) {
		$coupon = new WC_Coupon();
		$coupon->set_code( 'vcap-par-' . strtolower( wp_generate_password( 10, false, false ) ) );
		$coupon->set_discount_type( 'fixed_cart' );
		$coupon->set_amount( 50 );
		$coupon->update_meta_data( '_vcap_voucher', 'yes' );
		$coupon->save();
		$v                 = new VCAP\Voucher();
		$v->code           = wc_format_coupon_code( $coupon->get_code() );
		$v->coupon_id      = $coupon->get_id();
		$v->order_item_id  = 95000000 + $coupon->get_id();
		$v->type           = 'giftcard';
		$v->usage_mode     = 'balance';
		$v->discount_type  = 'fixed_cart';
		$v->initial_amount = 50;
		$v->balance        = 50;
		$v->currency       = get_woocommerce_currency();
		$v->status         = VCAP\Voucher::STATUS_ACTIVE;
		$v->send_to        = 'buyer';
		$v->token          = VCAP\VoucherRepository::new_token();
		$v->id             = vcap()->vouchers->insert( $v );
		$orders            = array();
		foreach ( array( 0, 1 ) as $process ) {
			$order = wc_create_order();
			$order->save();
			$orders[ $process ] = $order->get_id();
		}
		$cards[] = array( 'voucher' => $v->id, 'orders' => $orders );
	}
	update_option( $vcap_parallel_option, array( 'start' => microtime( true ) + 5, 'cards' => $cards ), false );
	echo 'Parallel checkout test prepared with ' . $vcap_parallel_cards . " gift cards.\n";
	return;
}

$state = get_option( $vcap_parallel_option );
if ( ! is_array( $state ) ) {
	throw new RuntimeException( 'Run "setup" first.' );
}

if ( 'reserve' === $mode ) {
	$process = (int) ( $args[1] ?? 0 );
	while ( microtime( true ) < $state['start'] ) {
		usleep( 2000 ); // Start both processes at the same moment.
	}
	$ok = 0;
	foreach ( $state['cards'] as $card ) {
		$voucher = vcap()->vouchers->get( (int) $card['voucher'] );
		$order   = wc_get_order( (int) $card['orders'][ $process ] );
		if ( vcap()->coupons->reserve_voucher_balance( $voucher, 30, $order, 'parallel test ' . $process ) ) {
			++$ok;
		}
	}
	// Widen the window between a booking's COMMIT and its coupon save, as a slow request would.
	add_action( 'woocommerce_before_data_object_save', static function ( $object ) { // WC_Coupon has no own object type.
		if ( $object instanceof WC_Coupon ) {
			usleep( random_int( 0, 30000 ) );
		}
	} );
	$booked = 0;
	for ( $round = 0; $round < 5; $round++ ) {
		foreach ( $state['cards'] as $card ) {
			if ( vcap()->coupons->deduct_balance_atomic( vcap()->vouchers->get( (int) $card['voucher'] ), 1.0, 'parallel counter ' . $process, 0, false ) ) {
				++$booked;
			}
		}
	}
	echo "Process {$process}: {$ok} reservations and {$booked} counter bookings succeeded.\n";
	return;
}

if ( 'verify' === $mode ) {
	$won = array( 0 => 0, 1 => 0 );
	foreach ( $state['cards'] as $i => $card ) {
		$voucher  = vcap()->vouchers->get( (int) $card['voucher'] );
		$reserved = 0.0;
		$count    = 0;
		$counter  = 0.0;
		foreach ( vcap()->vouchers->events( $voucher->id ) as $event ) {
			if ( 'redeemed' === $event['event'] ) {
				$counter += (float) $event['amount'];
			}
			if ( 'reserved' === $event['event'] ) {
				$reserved += (float) $event['amount'];
				++$count;
				$won[ array_search( (int) $event['order_id'], array_map( 'intval', $card['orders'] ), true ) ]++;
			}
		}
		if ( 1 !== $count || abs( $counter - 10 ) > 0.001 || abs( $reserved + $counter + $voucher->balance - 50 ) > 0.001 || $voucher->balance < 0 ) {
			throw new RuntimeException( sprintf( 'FAIL: card %d has %d reservations, %.2f reserved, %.2f booked at the counter, balance %.2f.', $i, $count, $reserved, $counter, $voucher->balance ) );
		}
		wp_cache_delete( $voucher->coupon_id, 'post_meta' );
		$coupon_amount = (float) ( new WC_Coupon( $voucher->coupon_id ) )->get_amount( 'edit' );
		if ( abs( $coupon_amount - $voucher->balance ) > 0.001 ) {
			throw new RuntimeException( sprintf( 'FAIL: card %d: coupon amount %.2f, stored balance %.2f.', $i, $coupon_amount, $voucher->balance ) );
		}
	}
	echo 'PASS: every gift card was reserved exactly once; reserved + counter bookings + balance = 50 and the coupon amount equals the balance (process 0 won ' . $won[0] . ', process 1 won ' . $won[1] . ").\n";
	delete_option( $vcap_parallel_option );
	return;
}

throw new RuntimeException( 'Unknown mode.' );
