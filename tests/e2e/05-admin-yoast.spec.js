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
} );
