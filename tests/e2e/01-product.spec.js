const { test, expect } = require( '@playwright/test' );
const { expectAccessible, trackPluginErrors } = require( './helpers' );

test.describe( 'Product page', () => {
	test( 'voucher fields are shown, labelled and accessible', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await page.goto( '/product/e2e-voucher/' );
		const email = page.locator( '#vcap-field-recipient_email' );
		await expect( email ).toBeVisible();
		await expect( email ).toHaveAttribute( 'required', '' );
		await expect( page.locator( 'label[for="vcap-field-recipient_email"]' ) ).toBeVisible();
		await expectAccessible( page, '.vcap-fields' );
		expect( errors ).toEqual( [] );
	} );

	test( 'the fields can be used with the keyboard only', async ( { page } ) => {
		await page.goto( '/product/e2e-voucher/' );
		await page.focus( '#vcap-field-recipient_name' );
		await page.keyboard.type( 'Max Muster' );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#vcap-field-recipient_email' ) ).toBeFocused();
		await page.keyboard.type( 'keyboard@example.test' );
		await page.keyboard.press( 'Tab' );
		await expect( page.locator( '#vcap-field-message' ) ).toBeFocused();
		await page.keyboard.type( 'Mit der Tastatur bestellt' );
		await page.focus( '#vcap-field-recipient_email' );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.keyboard.press( 'Enter' ) ] );
		await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();
	} );

	test( 'the server refuses a missing required field', async ( { page } ) => {
		await page.goto( '/product/e2e-voucher/' );
		await page.locator( '#vcap-field-recipient_email' ).evaluate( ( el ) => el.removeAttribute( 'required' ) );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
		await expect( page.getByText( /is a required field/i ).first() ).toBeVisible();
	} );

	test( 'Yoast SEO and the voucher fields coexist on the product page', async ( { page } ) => {
		await page.goto( '/product/e2e-voucher/' );
		await expect( page.locator( '#vcap-field-recipient_email' ) ).toBeVisible();
		const yoast = await page.content();
		expect( yoast ).toMatch( /Yoast SEO/i );
	} );
} );
