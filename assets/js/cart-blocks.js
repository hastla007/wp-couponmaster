/*
 * Voucher Captain – cart and checkout blocks (no build step: plain script on the WordPress/WooCommerce globals).
 * Shows what the classic cart prints with PHP hooks, from the Store API cart data extensions["voucher-captain"]:
 * - one row "Voucher CODE −x" per voucher that pays after tax, and the remaining balances of applied gift cards
 *   (slot ExperimentalDiscountsMeta, below the discounts of the totals; the balance left after this order as a note);
 * - a note per coupon of such a voucher, whose own discount is 0, saying that it pays below ("CODE pays as voucher
 *   payment (see below)", same slot, right under the coupons). Not through the checkout filter "coupons": newer
 *   cart blocks show coupons as chips with their code only, so a changed label would never be seen. A 0 discount
 *   row of older blocks stays: it comes from the Store API cart totals, which WooCommerce offers no filter for
 *   (TAX.md 4.1);
 * - the title of an automatic promotion instead of its virtual coupon code "vcap-campaign-ID" (filter "coupons";
 *   removing it is refused by the Store API with a message, see Discounts\CartIntegration::refuse_removal());
 * - the "use store credit" hint (slot ExperimentalOrderMeta); with the setting "Gift card field" the credit is applied
 *   without reloading the page (extensionCartUpdate, Store API update callback "voucher-captain");
 * - with the setting "Gift card field", a "Redeem a gift card" field (slot ExperimentalDiscountsMeta) that applies the
 *   code through the same update callback (M12-95);
 * - the warning that a single-use voucher worth more than the cart loses its remaining value (slot
 *   ExperimentalDiscountsMeta, in a polite live region that is always rendered, so screen readers announce the
 *   warning when it appears after a coupon is applied or the cart changes).
 * All texts come translated from PHP (VCAP\CartBlocks).
 */
( function ( wp, wc ) {
	'use strict';
	if ( ! wp || ! wp.element || ! wp.plugins || ! wc || ! wc.blocksCheckout ) {
		return;
	}
	var el = wp.element.createElement;
	var checkout = wc.blocksCheckout;
	var NAMESPACE = 'voucher-captain';
	var TotalsItem = checkout.TotalsItem || ( wc.blocksComponents && wc.blocksComponents.TotalsItem );
	var TotalsWrapper = checkout.TotalsWrapper || ( wc.blocksComponents && wc.blocksComponents.TotalsWrapper );

	// Sample rows for the editor, whose preview cart carries no voucher (VCAP\CartBlocks::preview_data()).
	var settings = wc.wcSettings && wc.wcSettings.getSetting ? wc.wcSettings.getSetting( NAMESPACE + '_data', {} ) : {};
	var preview = ( settings && settings.preview ) || null;

	function data( extensions ) {
		return ( extensions && extensions[ NAMESPACE ] ) || preview || {};
	}

	function row( key, className, label, formatted ) {
		var value = el( 'span', { className: 'vcap-block-amount' }, formatted );
		if ( TotalsItem ) {
			return el( TotalsItem, { key: key, className: className, label: label, value: value } );
		}
		return el(
			'div',
			{ key: key, className: 'wc-block-components-totals-item ' + className },
			el( 'span', { className: 'wc-block-components-totals-item__label' }, label ),
			el( 'span', { className: 'wc-block-components-totals-item__value' }, value )
		);
	}

	/** Voucher payments after tax and remaining balances. */
	function Payments( props ) {
		var d = data( props.extensions );
		var rows = [];
		( d.paying_coupons || [] ).forEach( function ( code ) {
			rows.push(
				el(
					'div',
					{ key: 'note-' + code, className: 'wc-block-components-totals-item vcap-coupon-note' },
					el( 'small', null, String( code ).toUpperCase() + ' ' + ( d.coupon_note || '' ) )
				)
			);
		} );
		( d.payments || [] ).forEach( function ( payment ) {
			rows.push( row( 'payment-' + payment.code, 'vcap-voucher-payment', payment.label, payment.formatted ) );
		} );
		// "Remaining balance of voucher X after this order: 20.00" – a note, not an amount row between the discounts.
		( d.balances || [] ).forEach( function ( balance ) {
			rows.push(
				el(
					'div',
					{ key: 'balance-' + balance.code, className: 'wc-block-components-totals-item vcap-balance' },
					el( 'small', null, balance.text || balance.label + ': ' + balance.formatted )
				)
			);
		} );
		if ( ! rows.length ) {
			return null;
		}
		return TotalsWrapper ? el( TotalsWrapper, { className: 'vcap-voucher-payments' }, rows ) : el( 'div', { className: 'wc-block-components-totals-wrapper vcap-voucher-payments' }, rows );
	}

	/**
	 * "Voucher CODE: only x of y is used … the remaining z will be lost." (ProductChecks::residual_notice()). The live
	 * region stays mounted and empty while there is nothing to say; only its content changes.
	 */
	function Forfeits( props ) {
		var forfeits = data( props.extensions ).forfeits || [];
		return el(
			'div',
			{ className: 'vcap-forfeits', role: 'status', 'aria-live': 'polite' },
			forfeits.length
				? el(
						'div',
						{ className: 'wc-block-components-totals-wrapper' },
						forfeits.map( function ( forfeit ) {
							return el(
								'div',
								{ key: 'forfeit-' + forfeit.code, className: 'wc-block-components-notice-banner is-info vcap-residual-warning' },
								el( 'div', { className: 'wc-block-components-notice-banner__content' }, forfeit.text )
							);
						} )
				  )
				: null
		);
	}

	/** Sends an action to the Store API update callback "voucher-captain"; resolves with the new cart. */
	function update( payload ) {
		if ( ! checkout.extensionCartUpdate ) {
			return Promise.reject( new Error( '' ) );
		}
		return checkout.extensionCartUpdate( { namespace: NAMESPACE, data: payload } );
	}

	function errorText( error ) {
		return ( error && ( error.message || ( error.data && error.data.message ) ) ) || '';
	}

	/**
	 * "You have X store credit. Use store credit": a link that applies it (like the classic cart), or with the setting
	 * "Gift card field" a button that applies it without reloading the page.
	 */
	function StoreCreditHint( props ) {
		var credit = data( props.extensions ).store_credit;
		var state = wp.element.useState( '' );
		if ( ! credit || ! credit.url ) {
			return null;
		}
		var action = credit.inline && checkout.extensionCartUpdate
			? el(
					'a',
					{
						href: credit.url,
						className: 'vcap-use-store-credit',
						onClick: function ( event ) {
							event.preventDefault();
							update( { action: 'apply_credit' } ).catch( function ( error ) {
								state[ 1 ]( errorText( error ) );
							} );
						},
					},
					credit.link_label
			  )
			: el( 'a', { href: credit.url, className: 'vcap-use-store-credit' }, credit.link_label );
		return el(
			'div',
			{ className: 'wc-block-components-totals-wrapper vcap-store-credit-hint' },
			el( 'p', null, credit.text + ' ', action ),
			state[ 0 ] ? el( 'p', { className: 'vcap-giftcard-error', role: 'alert' }, state[ 0 ] ) : null
		);
	}

	/** "Redeem a gift card": code field and button (setting "Gift card field"). */
	function GiftCardField( props ) {
		var texts = data( props.extensions ).giftcard_field;
		var code = wp.element.useState( '' );
		var message = wp.element.useState( { text: '', error: false } );
		var busy = wp.element.useState( false );
		if ( ! texts || ! checkout.extensionCartUpdate ) {
			return null;
		}
		var id = 'vcap-giftcard-block';
		function submit( event ) {
			event.preventDefault();
			if ( busy[ 0 ] || ! code[ 0 ] ) {
				return;
			}
			busy[ 1 ]( true );
			update( { action: 'apply_code', code: code[ 0 ] } )
				.then( function () {
					code[ 1 ]( '' );
					message[ 1 ]( { text: texts.applied, error: false } );
				} )
				.catch( function ( error ) {
					message[ 1 ]( { text: errorText( error ), error: true } );
				} )
				.finally( function () {
					busy[ 1 ]( false );
				} );
		}
		return el(
			'form',
			{ className: 'wc-block-components-totals-wrapper vcap-giftcard-block', onSubmit: submit },
			el( 'label', { htmlFor: id }, texts.label ),
			el( 'input', {
				id: id,
				type: 'text',
				className: 'vcap-giftcard-input',
				autoComplete: 'off',
				maxLength: 100,
				placeholder: texts.placeholder,
				value: code[ 0 ],
				onChange: function ( event ) {
					code[ 1 ]( event.target.value );
				},
			} ),
			el( 'button', { type: 'submit', className: 'wc-block-components-button wp-element-button', disabled: busy[ 0 ] }, texts.button ),
			el( 'p', { className: message[ 0 ].error ? 'vcap-giftcard-error' : 'vcap-giftcard-applied', role: 'status', 'aria-live': 'polite' }, message[ 0 ].text )
		);
	}

	if ( checkout.registerCheckoutFilters ) {
		checkout.registerCheckoutFilters( NAMESPACE, {
			coupons: function ( coupons, extensions ) {
				var d = data( extensions );
				var promotions = {};
				( d.promotions || [] ).forEach( function ( promotion ) {
					promotions[ String( promotion.code ).toLowerCase() ] = promotion.label;
				} );
				if ( ! Object.keys( promotions ).length || ! Array.isArray( coupons ) ) {
					return coupons;
				}
				return coupons.map( function ( coupon ) {
					var code = coupon ? String( coupon.code ).toLowerCase() : '';
					// An automatic promotion: its title instead of "vcap-campaign-12".
					return coupon && promotions[ code ] ? Object.assign( {}, coupon, { label: promotions[ code ] } ) : coupon;
				} );
			},
		} );
	}

	wp.plugins.registerPlugin( 'voucher-captain-cart', {
		scope: 'woocommerce-checkout',
		render: function () {
			return el(
				wp.element.Fragment,
				null,
				checkout.ExperimentalDiscountsMeta ? el( checkout.ExperimentalDiscountsMeta, null, el( GiftCardField ), el( Payments ), el( Forfeits ) ) : null,
				checkout.ExperimentalOrderMeta ? el( checkout.ExperimentalOrderMeta, null, el( StoreCreditHint ) ) : null
			);
		},
	} );
} )( window.wp, window.wc );
