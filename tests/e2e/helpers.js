const AxeBuilder = require( '@axe-core/playwright' ).default;
const { expect } = require( '@playwright/test' );
const fs = require( 'fs' );
const path = require( 'path' );

const state = () => JSON.parse( fs.readFileSync( path.join( __dirname, '.state.json' ), 'utf8' ) );

/** axe scan limited to Voucher Captain markup; serious and critical findings fail the test. */
async function expectAccessible( page, selector ) {
	await expect( page.locator( selector ).first() ).toBeVisible();
	const results = await new AxeBuilder( { page } ).include( selector ).analyze();
	const serious = results.violations
		.filter( ( v ) => [ 'serious', 'critical' ].includes( v.impact ) )
		// WooCommerce's selectWoo library sets aria-haspopup on its combobox; not Voucher Captain markup.
		.filter( ( v ) => ! ( 'aria-allowed-attr' === v.id && v.nodes.every( ( n ) => n.target.join( ' ' ).includes( 'select2-selection' ) ) ) )
		.map( ( v ) => `${ v.id }: ${ v.help } (${ v.nodes.map( ( n ) => n.target.join( ' ' ) ).join( ', ' ) })` );
	expect( serious, `accessibility problems in ${ selector }` ).toEqual( [] );
}

/** Uncaught JavaScript errors caused by Voucher Captain scripts. */
function trackPluginErrors( page ) {
	const errors = [];
	page.on( 'pageerror', ( error ) => {
		const text = `${ error.message }\n${ error.stack || '' }`;
		if ( /voucher-captain|vcap/i.test( text ) ) {
			errors.push( text );
		}
	} );
	return errors;
}

async function login( page, user, password ) {
	await page.goto( '/wp-login.php' );
	await page.fill( '#user_login', user );
	await page.fill( '#user_pass', password );
	await Promise.all( [ page.waitForNavigation(), page.click( '#wp-submit' ) ] );
}

async function addVoucherToCart( page, recipient ) {
	await page.goto( '/product/e2e-voucher/' );
	await page.fill( '#vcap-field-recipient_name', 'Erika Muster' );
	await page.fill( '#vcap-field-recipient_email', recipient );
	await page.fill( '#vcap-field-message', 'Alles Gute!' );
	await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
	await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();
}

module.exports = { state, expectAccessible, trackPluginErrors, login, addVoucherToCart };
