const { test, expect } = require( '@playwright/test' );
const { emptyCart, expectAccessible, freshCode, login, state, trackPluginErrors } = require( './helpers' );

// Features of 1.2.0: live preview, design gallery, "As a gift / For me", the "Redeem a gift card" field, "Add to my
// store credit" and the admin pages "New voucher", "Import cards", "Voucher requests" and Reports → Overview.
// Runs with the cart/checkout pages switched to the classic shortcodes (tests/e2e/pages.php classic) and the
// settings vcap_gift_toggle and vcap_giftcard_field on (.github/workflows/plugin-smoke.yml switches them on for
// this run only); the data comes from tests/e2e/setup.php (product e2e-design-voucher, customer e2e-giftee, …).
test.describe( 'new features of 1.2.0', () => {
	test.skip( process.env.E2E_NEW_FEATURES !== 'yes', 'run of 07-new-features only (settings switched on in CI)' );

	/** No PHP error output in the page (WP_DEBUG_DISPLAY off in CI: the debug.log is checked by the workflow). */
	async function expectNoPhpErrors( page ) {
		const html = await page.content();
		expect( html ).not.toMatch( /Fatal error|Parse error|There has been a critical error|<b>(Warning|Notice|Deprecated)<\/b>:/i );
	}

	test.describe( 'product page', () => {
		const url = '/product/e2e-design-voucher/';

		test( 'the live preview follows recipient name, message and amount', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			await page.goto( url );
			const preview = page.locator( '.vcap-live-preview' );
			await expect( preview ).toBeVisible();
			await expect( preview ).toHaveAttribute( 'role', 'img' );
			await expect( preview.locator( '.vcap-lp-page' ) ).toBeVisible();
			await page.fill( '#vcap-field-recipient_name', 'Lina Live' );
			await expect( preview ).toContainText( 'Lina Live' );
			await page.fill( '#vcap-field-message', 'Happy birthday, Lina!' );
			await expect( preview ).toContainText( 'Happy birthday, Lina!' );
			await page.locator( 'input[name="vcap_amount"]' ).fill( '42' );
			await expect( preview ).toContainText( /42[.,]00/ );
			await page.locator( 'input[name="vcap_amount"]' ).fill( '75' );
			await expect( preview ).toContainText( /75[.,]00/ );
			await expect( preview ).not.toContainText( /42[.,]00/ );
			await expectAccessible( page, '.vcap-live-preview' );
			expect( errors ).toEqual( [] );
		} );

		test( 'the design gallery is a labelled radio group that switches the preview and reaches the cart', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			await page.goto( '/' );
			await emptyCart( page );
			await page.goto( url );
			const gallery = page.getByRole( 'group', { name: 'Choose a design' } );
			await expect( gallery ).toBeVisible();
			const radios = gallery.getByRole( 'radio' );
			await expect( radios ).toHaveCount( state().templates.length );
			await expect( radios.first() ).toBeChecked();
			await page.fill( '#vcap-field-recipient_name', 'Gallery Guest' );
			const preview = page.locator( '.vcap-live-preview' );
			await expect( preview ).toContainText( 'For: Gallery Guest' );
			// The second default design is the event ticket ("Guest: {holder_name}").
			const second = radios.nth( 1 );
			const secondName = ( await second.evaluate( ( el ) => el.closest( 'label' ).textContent ) ).trim();
			await second.check();
			await expect( second ).toBeChecked();
			await expect( radios.first() ).not.toBeChecked();
			await expect( preview ).toContainText( 'Guest: Gallery Guest' );
			await expect( preview ).not.toContainText( 'For: Gallery Guest' );
			await expectAccessible( page, '.vcap-design-gallery' );
			await expectAccessible( page, '.vcap-live-preview' );

			await page.locator( 'input[name="vcap_amount"]' ).fill( '30' );
			await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
			await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();
			const cart = await ( await page.request.get( '/wp-json/wc/store/v1/cart' ) ).json();
			const data = ( cart.items || [] ).flatMap( ( item ) => ( item.item_data || [] ).map( ( d ) => `${ d.key || d.name }: ${ d.value || d.display || '' }` ) );
			expect( data, 'the chosen design is kept with the cart line' ).toContain( `Design: ${ secondName }` );
			await emptyCart( page );
			expect( errors ).toEqual( [] );
		} );

		test( '"For me" hides and disables the recipient fields, "As a gift" brings them back', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			await page.goto( url );
			const toggle = page.getByRole( 'group', { name: 'Who is the voucher for?' } );
			await expect( toggle ).toBeVisible();
			const gift = toggle.getByRole( 'radio', { name: 'As a gift' } );
			const self = toggle.getByRole( 'radio', { name: 'For me' } );
			await expect( gift ).toBeChecked();
			const name = page.locator( '#vcap-field-recipient_name' );
			const email = page.locator( '#vcap-field-recipient_email' );
			const message = page.locator( '#vcap-field-message' );
			await expect( name ).toBeVisible();
			await expectAccessible( page, '.vcap-gift-mode' );

			await self.check();
			for ( const field of [ name, email, message ] ) {
				await expect( field ).toBeHidden();
				await expect( field ).toBeDisabled();
			}
			await expectAccessible( page, '.vcap-fields' );

			await gift.check();
			for ( const field of [ name, email, message ] ) {
				await expect( field ).toBeVisible();
				await expect( field ).toBeEnabled();
			}
			expect( errors ).toEqual( [] );
		} );
	} );

	test.describe( 'cart', () => {
		test.beforeEach( async ( { page } ) => {
			await page.goto( '/' );
			await emptyCart( page );
		} );

		async function addMug( page ) {
			await page.goto( '/product/e2e-mug/' );
			await Promise.all( [ page.waitForLoadState( 'load' ), page.click( '.single_add_to_cart_button' ) ] );
			await expect( page.getByText( /has been added to your cart/i ).first() ).toBeVisible();
		}

		test( 'the "Redeem a gift card" field of the classic cart applies a code without reloading', async ( { page }, testInfo ) => {
			const errors = trackPluginErrors( page );
			const code = freshCode( 'field_cards', testInfo );
			await addMug( page );
			await page.goto( '/classic-cart/' );
			const form = page.locator( 'form.vcap-giftcard-form' );
			await expect( form ).toBeVisible();
			await expectAccessible( page, 'form.vcap-giftcard-form' );
			await form.getByRole( 'textbox', { name: 'Redeem a gift card' } ).fill( code );
			await Promise.all( [
				page.waitForResponse( ( r ) => r.url().includes( 'wc-ajax=vcap_apply_giftcard' ) ),
				form.getByRole( 'button', { name: 'Redeem' } ).click(),
			] );
			// 30.00 mug − 20.00 card = 10.00, shown after the cart refreshed its totals.
			await expect( page.locator( '.cart_totals' ) ).toContainText( new RegExp( code, 'i' ) );
			await expect( page.locator( '.cart_totals .order-total' ) ).toContainText( /10[.,]00/ );
			await expect( form.locator( '.vcap-giftcard-result' ) ).not.toBeEmpty();
			await expect( form.getByRole( 'textbox', { name: 'Redeem a gift card' } ) ).toHaveValue( '' );
			expect( errors ).toEqual( [] );
		} );

		test( 'a wrong code in the classic field is announced in the status region', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			await addMug( page );
			await page.goto( '/classic-cart/' );
			const form = page.locator( 'form.vcap-giftcard-form' );
			await form.getByRole( 'textbox', { name: 'Redeem a gift card' } ).fill( 'NOPE-E2E-0000' );
			await form.getByRole( 'button', { name: 'Redeem' } ).click();
			const status = form.locator( '.vcap-giftcard-result[role="status"]' );
			await expect( status ).toContainText( /does not exist|not valid|not found|invalid/i );
			await expectAccessible( page, 'form.vcap-giftcard-form' );
			expect( errors ).toEqual( [] );
		} );

		test( 'the "Redeem a gift card" field of the cart block applies a code', async ( { page }, testInfo ) => {
			const errors = trackPluginErrors( page );
			// Indexes 2 and 3 of the pool: the classic test above uses 0 (and 1 on its retry).
			const code = state().field_cards[ 2 + testInfo.retry ];
			await addMug( page );
			await page.goto( '/cart/' ); // The cart block page (the classic pages are WooCommerce's cart page in this run).
			const form = page.locator( 'form.vcap-giftcard-block' );
			await expect( form ).toBeVisible();
			await expectAccessible( page, 'form.vcap-giftcard-block' );
			await form.getByRole( 'textbox', { name: 'Redeem a gift card' } ).fill( code );
			await form.getByRole( 'button', { name: 'Redeem' } ).click();
			// The answer is shown in the field's status region (also after the cart update re-mounted the field).
			await expect( page.locator( 'form.vcap-giftcard-block [role="status"]' ) ).toContainText( 'The gift card was applied.' );
			await expect( page.locator( '.wp-block-woocommerce-cart' ) ).toContainText( new RegExp( code, 'i' ) );
			await expect( page.locator( '.wc-block-components-totals-footer-item .wc-block-components-totals-item__value' ).first() ).toContainText( /10[.,]00/ );
			await expectAccessible( page, 'form.vcap-giftcard-block' );
			await emptyCart( page );
			expect( errors ).toEqual( [] );
		} );
	} );

	test( 'My Account: "Add to my store credit" moves an own gift card into the store credit', async ( { page } ) => {
		const errors = trackPluginErrors( page );
		await login( page, 'e2e-giftee', 'e2e-Pass-123!' );
		await page.goto( '/my-account/vouchers/' );
		const form = page.locator( 'form.vcap-card-to-credit' );
		await expect( form ).toBeVisible();
		await expectAccessible( page, 'form.vcap-card-to-credit' );
		const buttons = page.locator( 'form.vcap-card-to-credit-inline button' );
		const before = await buttons.count();
		expect( before, 'a one-click button next to each own gift card' ).toBeGreaterThan( 0 );
		await expectAccessible( page, '.vcap-vouchers' );
		await Promise.all( [ page.waitForURL( /my-account\/vouchers/ ), page.waitForLoadState( 'load' ), buttons.first().click() ] );
		await expect( page.getByText( /12[.,]00 was added to your store credit/ ).first() ).toBeVisible();
		await expect( page.locator( 'form.vcap-card-to-credit-inline button' ) ).toHaveCount( before - 1 );
		await expect( page.locator( '.vcap-store-credit' ) ).toContainText( /(12|24)[.,]00/ );
		await expectAccessible( page, '.vcap-store-credit' );
		expect( errors ).toEqual( [] );
	} );

	test.describe( 'admin', () => {
		test.beforeEach( async ( { page } ) => {
			await login( page, 'admin', 'ci-password' );
		} );

		test( 'New voucher: accessible form that issues a gift card without an order', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			const response = await page.goto( '/wp-admin/admin.php?page=vcap-issue' );
			expect( response.status() ).toBeLessThan( 400 );
			await expectNoPhpErrors( page );
			await expect( page.getByRole( 'heading', { level: 1, name: 'New voucher' } ) ).toBeVisible();
			await expectAccessible( page, '.vcap-wrap form.vcap-card' );
			await page.selectOption( '#vcap-issue-type', 'giftcard' );
			await page.getByLabel( 'Value' ).fill( '15' );
			await page.getByLabel( 'Recipient name' ).fill( 'Goodwill Gerda' );
			await page.getByRole( 'checkbox', { name: /Send by email now/ } ).uncheck();
			await page.getByLabel( 'Reason' ).fill( 'E2E goodwill' );
			await Promise.all( [ page.waitForURL( /page=vcap-vouchers/ ), page.getByRole( 'button', { name: 'Issue voucher' } ).click() ] );
			await expect( page.getByText( /Voucher .+ issued \(not sent/ ).first() ).toBeVisible();
			await expectNoPhpErrors( page );
			expect( errors ).toEqual( [] );
		} );

		test( 'Import cards: accessible upload form, a dry run imports nothing', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			const response = await page.goto( '/wp-admin/admin.php?page=vcap-import-cards' );
			expect( response.status() ).toBeLessThan( 400 );
			await expectNoPhpErrors( page );
			await expectAccessible( page, '.vcap-wrap form.vcap-card' );
			await expect( page.getByRole( 'checkbox', { name: /Only check the file/ } ) ).toBeChecked();
			await page.getByLabel( 'File', { exact: true } ).setInputFiles( {
				name: 'cards.csv',
				mimeType: 'text/csv',
				buffer: Buffer.from( 'code,initial,balance\nE2E-IMPORT-DRY-0001,30,12.5\n' ),
			} );
			await Promise.all( [ page.waitForURL( /page=vcap-import-cards/ ), page.getByRole( 'button', { name: 'Upload' } ).click() ] );
			await expect( page.getByText( /Dry run: 1 cards with .*12[.,]50.* would be imported/ ) ).toBeVisible();
			await expectNoPhpErrors( page );
			expect( errors ).toEqual( [] );
		} );

		test( 'Voucher requests: the list shows the request accessibly and opens it', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			const response = await page.goto( '/wp-admin/admin.php?page=vcap-requests' );
			expect( response.status() ).toBeLessThan( 400 );
			await expectNoPhpErrors( page );
			const table = page.locator( '.vcap-wrap table.widefat' );
			const row = table.locator( 'tbody tr' ).filter( { hasText: 'E2E GmbH' } );
			await expect( row ).toHaveCount( 1 );
			await expect( row ).toContainText( 'Company order' );
			await expect( row.getByRole( 'checkbox', { name: /Select request \d+/ } ) ).toBeVisible();
			await expectAccessible( page, '.vcap-wrap form:has(table.widefat)' );
			await Promise.all( [ page.waitForURL( /request=\d+/ ), row.getByRole( 'link' ).first().click() ] );
			await expect( page.locator( '.vcap-wrap' ) ).toContainText( 'Rita Request' );
			await expectNoPhpErrors( page );
			expect( errors ).toEqual( [] );
		} );

		test( 'Reports → Overview: filter form, cards and table are accessible', async ( { page } ) => {
			const errors = trackPluginErrors( page );
			const response = await page.goto( '/wp-admin/admin.php?page=vcap-reports&view=overview' );
			expect( response.status() ).toBeLessThan( 400 );
			await expectNoPhpErrors( page );
			await expect( page.getByRole( 'link', { name: 'Overview' } ).first() ).toBeVisible();
			await expect( page.locator( '.vcap-report-cards .vcap-card' ) ).toHaveCount( 5 );
			await expectAccessible( page, 'form.vcap-report-filter' );
			await expectAccessible( page, '.vcap-report-cards' );
			await expectAccessible( page, '.wrap table.widefat' );
			await page.getByLabel( 'From' ).fill( '2020-01-01' );
			await Promise.all( [ page.waitForURL( /from=2020-01-01/ ), page.getByRole( 'button', { name: 'Show' } ).click() ] );
			await expect( page.locator( '.vcap-report-cards .vcap-card' ) ).toHaveCount( 5 );
			await expectNoPhpErrors( page );
			expect( errors ).toEqual( [] );
		} );
	} );
} );
