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
