const { test, expect } = require( '@playwright/test' );
const { addVoucherToCart, trackPluginErrors } = require( './helpers' );

// Runs while the cart/checkout pages are the WooCommerce blocks (tests/e2e/pages.php blocks).
test( 'cart and checkout blocks with a personalised voucher', async ( { page } ) => {
	test.skip( process.env.E2E_CHECKOUT !== 'blocks', 'block checkout run only' );
	const errors = trackPluginErrors( page );
	await addVoucherToCart( page, 'block-recipient@example.test' );

	await page.goto( '/cart/' );
	await expect( page.getByText( 'block-recipient@example.test' ).first() ).toBeVisible();

	await page.goto( '/checkout/' );
	await page.locator( '#email' ).fill( 'e2e-block@example.test' );
	await page.locator( '#billing-first_name' ).fill( 'Bruno' );
	await page.locator( '#billing-last_name' ).fill( 'Block' );
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
	expect( errors ).toEqual( [] );
} );
