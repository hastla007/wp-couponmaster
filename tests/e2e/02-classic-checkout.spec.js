const { test, expect } = require( '@playwright/test' );
const { addVoucherToCart, trackPluginErrors } = require( './helpers' );

// Runs while the cart/checkout pages are the classic shortcode pages (tests/e2e/pages.php classic).
test( 'classic cart and checkout with a personalised voucher', async ( { page } ) => {
	test.skip( process.env.E2E_CHECKOUT !== 'classic', 'classic checkout run only' );
	const errors = trackPluginErrors( page );
	await addVoucherToCart( page, 'classic-recipient@example.test' );

	await page.goto( '/classic-cart/' );
	await expect( page.getByText( 'classic-recipient@example.test' ).first() ).toBeVisible();

	await page.goto( '/classic-checkout/' );
	await page.fill( '#billing_first_name', 'Klara' );
	await page.fill( '#billing_last_name', 'Klassik' );
	await page.fill( '#billing_address_1', 'Hauptstraße 1' );
	await page.fill( '#billing_postcode', '10115' );
	await page.fill( '#billing_city', 'Berlin' );
	if ( await page.locator( '#billing_phone' ).isVisible() ) {
		await page.fill( '#billing_phone', '030123456' );
	}
	await page.fill( '#billing_email', 'e2e-classic@example.test' );
	await page.waitForLoadState( 'networkidle' );
	const cod = page.locator( '#payment_method_cod' );
	if ( await cod.isVisible() ) {
		await cod.check();
	}
	await page.click( '#place_order' );
	await page.waitForURL( /order-received/, { timeout: 60000 } );
	await expect( page.getByText( /Thank you|order has been received/i ).first() ).toBeVisible();
	expect( errors ).toEqual( [] );
} );
