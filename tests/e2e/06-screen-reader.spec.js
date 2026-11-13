const { test, expect } = require( '@playwright/test' );
const { login } = require( './helpers' );

/*
 * What a screen reader gets, checked through the browser's accessibility tree (roles, accessible names, states and
 * live regions) for the flows of ACCESSIBILITY.md. The listening test with NVDA/VoiceOver itself stays a manual
 * release check (ACCESSIBILITY.md); these tests keep its preconditions from regressing.
 */

/** Every visible form control inside `scope` is found again by its role and accessible name (label association). */
async function expectNamedControls( page, scope ) {
	const controls = page.locator( `${ scope } input:not([type=hidden]), ${ scope } textarea, ${ scope } select` );
	const count = await controls.count();
	expect( count, `form controls in ${ scope }` ).toBeGreaterThan( 0 );
	for ( let i = 0; i < count; i++ ) {
		const control = controls.nth( i );
		if ( ! ( await control.isVisible() ) ) {
			continue;
		}
		const name = await control.evaluate( ( el ) => {
			const labels = el.labels ? Array.from( el.labels ).map( ( l ) => l.textContent.trim() ).filter( Boolean ) : [];
			return el.getAttribute( 'aria-label' ) || labels.join( ' ' );
		} );
		expect( name, `accessible name of ${ await control.evaluate( ( el ) => el.outerHTML.slice( 0, 120 ) ) }` ).not.toBe( '' );
	}
}

test.describe( 'Screen reader: product page', () => {
	test( 'voucher fields have accessible names and announce required fields', async ( { page } ) => {
		await page.goto( '/product/e2e-voucher/' );
		await expectNamedControls( page, '.vcap-fields' );
		const label = ( await page.locator( 'label[for="vcap-field-recipient_email"]' ).textContent() ).replace( /\*/g, '' ).trim();
		const email = page.getByRole( 'textbox', { name: new RegExp( label.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'i' ) } );
		await expect( email ).toHaveId( 'vcap-field-recipient_email' );
		await expect( email ).toHaveJSProperty( 'required', true );
		await expect( page.getByRole( 'button', { name: /Preview voucher/i } ) ).toBeVisible();
	} );

	test( 'a refused field is announced as an alert', async ( { page } ) => {
		await page.goto( '/product/e2e-voucher/' );
		await page.locator( '#vcap-field-recipient_email' ).evaluate( ( el ) => el.removeAttribute( 'required' ) );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
		await expect( page.getByRole( 'alert' ).filter( { hasText: /is a required field/i } ).first() ).toBeVisible();
	} );
} );

test.describe( 'Screen reader: customer pages', () => {
	test( 'balance and redeem forms are named; a balance error is announced', async ( { page } ) => {
		await page.goto( '/voucher-tools/' );
		await expectNamedControls( page, '.vcap-balance-form' );
		await expectNamedControls( page, '.vcap-redeem-form' );
		await expect( page.locator( '.vcap-balance-form' ).getByRole( 'button', { name: /Check balance/i } ) ).toBeVisible();
		await page.locator( '.vcap-balance-form' ).getByRole( 'textbox' ).fill( 'NOPE-SR-0000' );
		await Promise.all( [ page.waitForLoadState( 'load' ), page.locator( '.vcap-balance-form' ).getByRole( 'button' ).click() ] );
		await expect( page.getByRole( 'alert' ).filter( { hasText: /Voucher code not found/i } ) ).toBeVisible();
	} );

	test( 'My Account voucher table has column headers', async ( { page } ) => {
		await login( page, 'e2e-customer', 'e2e-Pass-123!' );
		await page.goto( '/my-account/vouchers/' );
		const table = page.locator( '.vcap-vouchers' ).getByRole( 'table' ).first();
		if ( await table.count() ) {
			await expect( table.getByRole( 'columnheader', { name: /Code/i } ) ).toBeVisible();
			await expect( table.getByRole( 'columnheader', { name: /Value/i } ) ).toBeVisible();
		}
	} );
} );

test.describe( 'Screen reader: admin', () => {
	test.beforeEach( async ( { page } ) => {
		await login( page, 'admin', 'ci-password' );
	} );

	test( 'scanner announces each result in a live region', async ( { page } ) => {
		await page.goto( '/wp-admin/admin.php?page=vcap-scanner' );
		await expectNamedControls( page, '.vcap-scanner' );
		const live = page.locator( '#vcap-live' );
		await expect( live ).toHaveAttribute( 'role', 'status' );
		await page.focus( '#vcap-code' );
		await page.keyboard.type( 'NOPE-SR-0000' );
		await page.keyboard.press( 'Enter' );
		await expect( live ).not.toBeEmpty();
	} );

	test( 'promotion editor: named controls and a live preview result', async ( { page } ) => {
		await page.goto( '/wp-admin/post-new.php?post_type=vcap_discount' );
		await expectNamedControls( page, '#vcap-campaign-rules .inside' );
		await expect( page.locator( '#vcap-campaign-preview-result' ) ).toHaveAttribute( 'aria-live', 'polite' );
		await expect( page.getByRole( 'button', { name: /test/i } ).or( page.locator( '#vcap-campaign-preview' ) ).first() ).toBeVisible();
	} );
} );
