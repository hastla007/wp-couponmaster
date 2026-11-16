const { test, expect } = require( '@playwright/test' );
const { addVoucherToCart, emptyCart, expectCartShows, freshCode, trackPluginErrors } = require( './helpers' );

// Runs while the cart/checkout pages are the classic shortcode pages (tests/e2e/pages.php classic). Every test
// starts with an empty cart and uses a voucher code of its own per attempt (M12-101: retries are idempotent).
test.describe( 'classic cart and checkout', () => {
	test.skip( process.env.E2E_CHECKOUT !== 'classic', 'classic checkout run only' );

	test.beforeEach( async ( { page } ) => {
		await page.goto( '/' );
		await emptyCart( page );
	} );

	/** Fills the classic checkout and places the order (cash on delivery when offered). */
	async function placeClassicOrder( page, email, lastName ) {
		await page.goto( '/classic-checkout/' );
		await page.fill( '#billing_first_name', 'Klara' );
		await page.fill( '#billing_last_name', lastName );
		await page.fill( '#billing_address_1', 'Hauptstraße 1' );
		await page.fill( '#billing_postcode', '10115' );
		await page.fill( '#billing_city', 'Berlin' );
		if ( await page.locator( '#billing_phone' ).isVisible() ) {
			await page.fill( '#billing_phone', '030123456' );
		}
		await page.fill( '#billing_email', email );
		await page.waitForLoadState( 'networkidle' );
		const cod = page.locator( '#payment_method_cod' );
		if ( await cod.isVisible() ) {
			await cod.check();
		}
		await page.click( '#place_order' );
		await page.waitForURL( /order-received/, { timeout: 60000 } );
		await expect( page.getByText( /Thank you|order has been received/i ).first() ).toBeVisible();
	}

	test( 'classic cart and checkout with a personalised voucher', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await addVoucherToCart( page, 'classic-recipient@example.test' );

		await page.goto( '/classic-cart/' );
		await expectCartShows( page, 'classic-recipient@example.test', 'form.woocommerce-cart-form, .woocommerce, main' );

		await placeClassicOrder( page, 'e2e-classic@example.test', 'Klassik' );
		expect( errors ).toEqual( [] );
	} );

	// M12-58: the classic checkout redeems a gift card – entered in the [vcap_redeem] form, applied to the cart,
	// paid in the classic checkout; tests/e2e/verify.php checks the order total, the balance and the ledger.
	test( 'a gift card entered in the redeem form pays part of a classic checkout', async ( { page }, testInfo ) => {
		const errors = trackPluginErrors( page );
		const code = freshCode( 'classic_cards', testInfo );
		await page.goto( '/product/e2e-mug/' );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
		await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();

		await page.goto( '/voucher-tools/' );
		await page.fill( '#vcap-redeem-code', code );
		// The form applies the code to the cart and goes on to the (classic) cart page.
		await Promise.all( [ page.waitForURL( /classic-cart/ ), page.locator( '.vcap-redeem-form button[type="submit"]:not(.vcap-default-submit)' ).click() ] );
		await expect( page.locator( '.cart_totals' ) ).toContainText( new RegExp( code, 'i' ) );
		await expect( page.locator( '.cart_totals .order-total' ) ).toContainText( /10[.,]00/ );

		await placeClassicOrder( page, 'e2e-classic-redeem@example.test', 'Einloesung' );
		expect( errors ).toEqual( [] );
	} );
} );
