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
	await expect( page.locator( '#vcap-field-recipient_email' ), 'the recipient field is sent with the form' ).toBeEnabled();
	// Wait for the answer to the add-to-cart POST itself: waiting for "load" of the page that is already loaded
	// returned at once, so the next step could run before the cart was saved.
	const [ response ] = await Promise.all( [
		page.waitForResponse( ( r ) => 'POST' === r.request().method() && /e2e-voucher|add-to-cart|wc\/store\/v1\/cart\/add-item/.test( r.url() + ( r.request().postData() || '' ) ) ),
		page.click( '.single_add_to_cart_button' ),
	] );
	expect( response.status(), 'the add-to-cart request succeeds' ).toBeLessThan( 400 );
	await page.waitForLoadState( 'domcontentloaded' );
	await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();
	await expectCartLineFor( page, recipient );
}

/**
 * The cart (Store API, same session as the page) holds a voucher line personalised for the recipient. On failure
 * the message lists what the cart holds instead, so a broken run shows its cause without the screenshot.
 */
async function expectCartLineFor( page, recipient ) {
	const cart = await ( await page.request.get( '/wp-json/wc/store/v1/cart' ) ).json();
	const lines = ( cart.items || [] ).map( ( item ) => ( {
		name: item.name,
		quantity: item.quantity,
		data: ( item.item_data || [] ).map( ( d ) => `${ d.key || d.name }: ${ d.value || d.display || '' }` ),
	} ) );
	const found = lines.some( ( line ) => line.data.some( ( d ) => d.includes( recipient ) ) );
	expect( found, `a cart line for ${ recipient }; the cart holds ${ JSON.stringify( lines ) }` ).toBeTruthy();
}

/** Visible text in the cart; on failure the message carries the cart content of the page. */
async function expectCartShows( page, text, container ) {
	try {
		await expect( page.getByText( text ).first() ).toBeVisible();
	} catch ( error ) {
		const shown = await page.locator( container ).first().innerText().catch( () => '(cart not found)' );
		throw new Error( `"${ text }" is not visible in the cart. Page ${ page.url() } shows:\n${ shown }\n\n${ error.message }` );
	}
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

module.exports = { state, expectAccessible, trackPluginErrors, login, addVoucherToCart, expectCartLineFor, expectCartShows, emptyCart, freshCode };
