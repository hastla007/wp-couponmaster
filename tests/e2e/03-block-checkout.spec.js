const { test, expect } = require( '@playwright/test' );
const { addVoucherToCart, emptyCart, expectAccessible, freshCode, login, trackPluginErrors } = require( './helpers' );

// Runs while the cart/checkout pages are the WooCommerce blocks (tests/e2e/pages.php blocks), twice
// (plugin-smoke.yml → browser-e2e): with "Multi-purpose vouchers as payment" on (vcap_mpv_after_tax=yes, the
// default run) and once more with it off (E2E_MPV_AFTER_TAX=no: the gift card is a coupon discount). The voucher
// rows come from assets/js/cart-blocks.js (Store API data extensions["voucher-captain"], VCAP\CartBlocks).
// Every test starts with an empty cart and uses a voucher code of its own per attempt (M12-101: retries are
// idempotent).
const AFTER_TAX = process.env.E2E_MPV_AFTER_TAX !== 'no';

test.describe( 'cart and checkout blocks', () => {
	test.skip( process.env.E2E_CHECKOUT !== 'blocks', 'block checkout run only' );

	test.beforeEach( async ( { page } ) => {
		await page.goto( '/' );
		await emptyCart( page );
	} );

	/** Fills the block checkout and places the order (cash on delivery when offered). */
	async function placeBlockOrder( page, email, lastName ) {
		await page.goto( '/checkout/' );
		await page.locator( '#email' ).fill( email );
		await page.locator( '#billing-first_name' ).fill( 'Bruno' );
		await page.locator( '#billing-last_name' ).fill( lastName );
		await page.locator( '#billing-address_1' ).fill( 'Blockweg 2' );
		await page.locator( '#billing-postcode' ).fill( '10115' );
		await page.locator( '#billing-city' ).fill( 'Berlin' );
		const phone = page.locator( '#billing-phone' );
		if ( await phone.isVisible() ) {
			await phone.fill( '030654321' );
		}
		const cod = page.locator( 'input[value="cod"]' );
		if ( await cod.isVisible() ) {
			await cod.check();
		}
		await page.waitForLoadState( 'networkidle' );
		await page.locator( '.wc-block-components-checkout-place-order-button' ).click();
		await page.waitForURL( /order-received/, { timeout: 60000 } );
		await expect( page.getByText( /Thank you|order has been received/i ).first() ).toBeVisible();
	}

	/** Puts the mug (30.00) into the cart. */
	async function addGoodsToCart( page ) {
		await page.goto( '/product/e2e-mug/' );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
		await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();
	}

	/** Applies a code through the coupon form of the cart block. */
	async function applyCoupon( page, code ) {
		const input = page.getByLabel( /enter code/i ).first();
		if ( ! ( await input.isVisible() ) ) {
			await page.getByRole( 'button', { name: /add (a )?coupons?/i } ).first().click();
		}
		await input.fill( code );
		await page.getByRole( 'button', { name: /^apply$/i } ).first().click();
		await expect( page.locator( '.wc-block-components-totals-discount, .wc-block-components-totals-coupon' ).getByText( code, { exact: false } ).first() ).toBeVisible();
		await page.waitForLoadState( 'networkidle' );
	}

	/** Text of the order total row of the cart/checkout block. */
	const orderTotal = ( page ) => page.locator( '.wc-block-components-totals-footer-item .wc-block-components-totals-item__value' ).first();

	test( 'a personalised voucher is bought through the block checkout', async ( { page } ) => {
		test.skip( ! AFTER_TAX, 'payment after tax run only' );
		const errors = trackPluginErrors( page );
		await addVoucherToCart( page, 'block-recipient@example.test' );
		await page.goto( '/cart/' );
		await expect( page.getByText( 'block-recipient@example.test' ).first() ).toBeVisible();
		await placeBlockOrder( page, 'e2e-block@example.test', 'Block' );
		expect( errors ).toEqual( [] );
	} );

	test( 'a gift card pays after tax: payment row, remaining balance, coupon note and the right total', async ( { page }, testInfo ) => {
		test.skip( ! AFTER_TAX, 'payment after tax run only' );
		const errors = trackPluginErrors( page );
		const code = freshCode( 'gift_cards', testInfo );
		await addGoodsToCart( page );
		await page.goto( '/cart/' );
		await applyCoupon( page, code );
		// 30.00 goods, the 20.00 card pays below the discounts: "Voucher CODE −20.00", total 10.00 (never "−0.00").
		const payment = page.locator( '.vcap-voucher-payment' ).filter( { hasText: code } );
		await expect( payment ).toBeVisible();
		await expect( payment ).toContainText( /20[.,]00/ );
		// The note shows what is left after this order (20.00 card pays 20.00 → 0.00), not the balance before it.
		await expect( page.locator( '.vcap-balance' ).filter( { hasText: new RegExp( code, 'i' ) } ) ).toContainText( /after this order:\s*\D*0[.,]00/i );
		await expect( page.getByText( /pays as voucher payment/i ).first() ).toBeVisible();
		await expect( orderTotal( page ) ).toContainText( /10[.,]00/ );
		await page.goto( '/checkout/' );
		await expect( page.locator( '.vcap-voucher-payment' ).filter( { hasText: code } ) ).toBeVisible();
		await expect( orderTotal( page ) ).toContainText( /10[.,]00/ );
		await placeBlockOrder( page, 'e2e-block-giftcard@example.test', 'Giftcard' );
		expect( errors ).toEqual( [] );
	} );

	test( 'a single-use voucher worth more than the cart warns in an accessible live region', async ( { page }, testInfo ) => {
		test.skip( ! AFTER_TAX, 'payment after tax run only' );
		const errors = trackPluginErrors( page );
		const code = freshCode( 'single_vouchers', testInfo );
		await addGoodsToCart( page );
		await page.goto( '/cart/' );
		// The live region is rendered (empty) before anything happens, so the later warning is announced.
		const region = page.locator( '.vcap-forfeits[role="status"][aria-live="polite"]' );
		await expect( region ).toHaveCount( 1 );
		await applyCoupon( page, code );
		await expect( region.locator( '.vcap-residual-warning' ) ).toContainText( new RegExp( code, 'i' ) );
		await expect( region ).toContainText( /20[.,]00/ );
		await expectAccessible( page, '.vcap-forfeits' );
		// Leave the cart without the voucher for the next test.
		await page.getByRole( 'button', { name: new RegExp( 'remove.*' + code, 'i' ) } ).first().click();
		await expect( region.locator( '.vcap-residual-warning' ) ).toHaveCount( 0 );
		expect( errors ).toEqual( [] );
	} );

	test( 'logged-in customers use their store credit from the block cart', async ( { page } ) => {
		test.skip( ! AFTER_TAX, 'payment after tax run only' );
		const errors = trackPluginErrors( page );
		await login( page, 'e2e-customer', 'e2e-Pass-123!' );
		await emptyCart( page ); // The customer's cart persists (also from a failed attempt).
		await addGoodsToCart( page );
		await page.goto( '/cart/' );
		const hint = page.locator( '.vcap-store-credit-hint' );
		await expect( hint ).toContainText( /store credit/i );
		await expectAccessible( page, '.vcap-store-credit-hint' );
		await Promise.all( [ page.waitForLoadState( 'load' ), hint.locator( 'a.vcap-use-store-credit' ).click() ] );
		await page.goto( '/cart/' );
		// 15.00 credit pays after tax: payment row, the hint is gone, 15.00 left to pay.
		await expect( page.locator( '.vcap-voucher-payment' ) ).toContainText( /15[.,]00/ );
		await expect( page.locator( '.vcap-store-credit-hint' ) ).toHaveCount( 0 );
		await expect( orderTotal( page ) ).toContainText( /15[.,]00/ );
		expect( errors ).toEqual( [] );
	} );
	// M12-58: with "Multi-purpose vouchers as payment" off the gift card is a coupon discount (fixed_cart) on the
	// goods; tests/e2e/verify.php then checks the order total, the card's balance and its ledger booking.
	test( 'with payment after tax off, a gift card is a coupon discount and the order is charged once', async ( { page }, testInfo ) => {
		test.skip( AFTER_TAX, 'run with vcap_mpv_after_tax=no only' );
		const errors = trackPluginErrors( page );
		const code = freshCode( 'gift_cards_coupon', testInfo );
		await addGoodsToCart( page );
		await page.goto( '/cart/' );
		await applyCoupon( page, code );
		// No payment row: the 20.00 card reduces the goods like a coupon, 10.00 left to pay.
		await expect( page.locator( '.vcap-voucher-payment' ) ).toHaveCount( 0 );
		await expect( orderTotal( page ) ).toContainText( /10[.,]00/ );
		await page.goto( '/checkout/' );
		await expect( orderTotal( page ) ).toContainText( /10[.,]00/ );
		await placeBlockOrder( page, 'e2e-block-giftcard-coupon@example.test', 'Coupon' );
		expect( errors ).toEqual( [] );
	} );
} );
