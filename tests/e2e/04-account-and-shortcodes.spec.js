const { test, expect } = require( '@playwright/test' );
const { expectAccessible, login, trackPluginErrors } = require( './helpers' );

test.describe( 'Customer pages', () => {
	test( 'balance and redeem shortcodes are accessible and keyboard friendly', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await page.goto( '/voucher-tools/' );
		await expectAccessible( page, '.vcap-balance-form' );
		await expectAccessible( page, '.vcap-redeem-form' );
		await page.focus( '#vcap-redeem-code' );
		await page.keyboard.type( 'E2E10' );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.keyboard.press( 'Enter' ) ] );
		await expect( page.getByText( /will be applied as soon as you add products/i ).first() ).toBeVisible();
		expect( errors ).toEqual( [] );
	} );

	// M12-50 / M12-51: a redeem link lands on a URL no page cache has a copy of (random vcap_nc), without the code
	// in the URL, sent with no-cache headers – and only then the form shows the code.
	test( 'a redeem link fills in the form on an uncached URL without the code', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		const response = await page.goto( '/voucher-tools/?vcap_code=E2E10' );
		const url = new URL( page.url() );
		expect( url.searchParams.has( 'vcap_code' ) ).toBe( false );
		expect( url.searchParams.get( 'vcap_nc' ) || '' ).toMatch( /^[a-z0-9]{8}$/ );
		expect( response.headers()[ 'cache-control' ] || '' ).toMatch( /no-cache|no-store/ );
		await expect( page.locator( '#vcap-redeem-code' ) ).toHaveValue( 'E2E10' );
		expect( errors ).toEqual( [] );
	} );

	test( 'the voucher blocks render on the front end', async ( { page } ) => {
		await page.goto( '/voucher-blocks/' );
		await expect( page.locator( '.vcap-balance-form' ) ).toBeVisible();
		await expect( page.locator( '.vcap-redeem-form' ) ).toBeVisible();
	} );

	test( 'My Account → Vouchers lists vouchers and store credit accessibly', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await login( page, 'e2e-customer', 'e2e-Pass-123!' );
		await page.goto( '/my-account/vouchers/' );
		await expect( page.locator( '.vcap-vouchers' ) ).toBeVisible();
		await expect( page.locator( '.vcap-store-credit' ) ).toBeVisible();
		await expectAccessible( page, '.vcap-vouchers' );
		await expectAccessible( page, '.vcap-store-credit' );
		expect( errors ).toEqual( [] );
	} );
} );
