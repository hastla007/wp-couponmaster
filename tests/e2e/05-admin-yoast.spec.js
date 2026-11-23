const { test, expect } = require( '@playwright/test' );
const { expectAccessible, login, state, trackPluginErrors } = require( './helpers' );

test.describe( 'Admin with Yoast SEO active', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page, 'admin', 'ci-password' );
	} );

	const pages = [
		[ 'Issued vouchers', '/wp-admin/admin.php?page=vcap-vouchers' ],
		[ 'Promotions', '/wp-admin/edit.php?post_type=vcap_discount' ],
		[ 'New promotion', '/wp-admin/post-new.php?post_type=vcap_discount' ],
		[ 'Reports', '/wp-admin/admin.php?page=vcap-reports' ],
		[ 'Generate codes', '/wp-admin/admin.php?page=vcap-generate' ],
		[ 'Settings', '/wp-admin/admin.php?page=wc-settings&tab=vcap' ],
		[ 'Yoast dashboard', '/wp-admin/admin.php?page=wpseo_dashboard' ],
	];
	for ( const [ name, url ] of pages ) {
		test( `${ name } loads without errors`, async ( { page } ) => {
			const errors = trackPluginErrors( page );
			const response = await page.goto( url );
			expect( response.status() ).toBeLessThan( 400 );
			const html = await page.content();
			expect( html ).not.toMatch( /Fatal error|Parse error|There has been a critical error/i );
			expect( errors ).toEqual( [] );
		} );
	}

	test( 'product editor shows the voucher tab next to Yoast SEO', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await page.goto( `/wp-admin/post.php?post=${ state().voucher_product }&action=edit` );
		await expect( page.locator( '#vcap_product_data' ) ).toBeAttached();
		await expect( page.locator( '#wpseo_meta' ) ).toBeAttached();
		expect( errors ).toEqual( [] );
	} );

	test( 'scanner is accessible and usable with the keyboard (barcode scanners type + Enter)', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await page.goto( '/wp-admin/admin.php?page=vcap-scanner' );
		await expectAccessible( page, '.vcap-scanner' );
		await page.focus( '#vcap-code' );
		await page.keyboard.type( 'NOPE-E2E-0000' );
		await page.keyboard.press( 'Enter' );
		await expect( page.locator( '#vcap-result' ) ).not.toBeEmpty();
		expect( errors ).toEqual( [] );
	} );

	test( 'promotion rules editor is accessible and the test cart works', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await page.goto( '/wp-admin/post-new.php?post_type=vcap_discount' );
		await expectAccessible( page, '#vcap-campaign-rules .inside' );
		await page.selectOption( '#vcap-action', 'percent' );
		await page.fill( '#vcap-percent', '10' );
		await page.click( '#vcap-campaign-preview' );
		await expect( page.locator( '#vcap-campaign-preview-result' ) ).toContainText( /Choose at least one product|Total discount/ );
		expect( errors ).toEqual( [] );
	} );

	// PV-P5-2: Enter in a settings field saves (hidden default button first in the form), and the action buttons of
	// the country profile do not trigger WooCommerce's "leave page?" prompt.
	test( 'settings: Enter saves, action buttons ask no "leave page?" question', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		const dialogs = [];
		page.on( 'dialog', async ( dialog ) => {
			dialogs.push( dialog.type() );
			await dialog.dismiss();
		} );
		await page.goto( '/wp-admin/admin.php?page=wc-settings&tab=vcap' );
		await expect( page.locator( '#mainform button[type="submit"], #mainform input[type="submit"]' ).first() ).toHaveClass( /vcap-default-submit/ );
		const days = page.locator( '#vcap_default_expiry_days' );
		await days.fill( await days.inputValue() );
		await Promise.all( [ page.waitForNavigation(), days.press( 'Enter' ) ] );
		await expect( page.locator( '#message.updated, .notice-success, .updated' ).first() ).toBeVisible();
		await expect( page.locator( '.vcap-profile-preview' ) ).toHaveCount( 0 );
		await page.selectOption( '#vcap_profile', { index: 0 } );
		await Promise.all( [ page.waitForNavigation(), page.getByRole( 'button', { name: 'Show suggested changes' } ).click() ] );
		await expect( page.locator( '.vcap-profile-preview' ) ).toBeVisible();
		await Promise.all( [ page.waitForNavigation(), page.locator( '.vcap-profile-preview' ).getByRole( 'button', { name: 'Cancel' } ).click() ] );
		expect( dialogs ).toEqual( [] );
		expect( errors ).toEqual( [] );
	} );
} );
