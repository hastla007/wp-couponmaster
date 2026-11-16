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

/**
 * Empties the cart of the current visitor (items and codes) through the Store API, so a test – and its retry –
 * starts from an empty cart (a logged-in customer's cart persists across tests).
 */
async function emptyCart( page ) {
	const cart = await page.request.get( '/wp-json/wc/store/v1/cart' );
	const headers = { Nonce: cart.headers().nonce || '' };
	if ( cart.headers()[ 'cart-token' ] ) {
		headers[ 'Cart-Token' ] = cart.headers()[ 'cart-token' ];
	}
	const items = await page.request.delete( '/wp-json/wc/store/v1/cart/items', { headers } );
	const coupons = await page.request.delete( '/wp-json/wc/store/v1/cart/coupons', { headers } );
	expect( items.ok() && coupons.ok(), 'the cart is emptied before the test' ).toBeTruthy();
}

/**
 * A voucher code of its own for this test attempt (tests/e2e/setup.php creates a few per pool): a retry after a
 * failure never meets a card the failed attempt already charged.
 */
function freshCode( pool, testInfo ) {
	const codes = state()[ pool ] || [];
	const code = codes[ testInfo.retry ];
	if ( ! code ) {
		throw new Error( `no unused code left in pool "${ pool }" (attempt ${ testInfo.retry + 1 })` );
	}
	return code;
}

module.exports = { state, expectAccessible, trackPluginErrors, login, addVoucherToCart, emptyCart, freshCode };
