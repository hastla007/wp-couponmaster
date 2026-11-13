/*
 * Voucher Captain – cart and checkout blocks (no build step: plain script on the WordPress/WooCommerce globals).
 * Shows what the classic cart prints with PHP hooks, from the Store API cart data extensions["voucher-captain"]:
 * - one row "Voucher CODE −x" per voucher that pays after tax, and the remaining balances of applied gift cards
 *   (slot ExperimentalDiscountsMeta, below the discounts of the totals);
 * - a note on the coupon of such a voucher, whose own discount is 0, saying that it pays below (checkout filter
 *   "coupons", which can change the coupon label only). The 0 of the discount row itself stays: it comes from the
 *   Store API cart (totals.total_discount, coupons[].totals.total_discount), which WooCommerce offers no filter
 *   for, and the coupon markup has no class per coupon that CSS could hide for paying coupons alone (TAX.md 4.1);
 * - the "use store credit" hint (slot ExperimentalOrderMeta);
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

	function data( extensions ) {
		return ( extensions && extensions[ NAMESPACE ] ) || {};
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
		( d.payments || [] ).forEach( function ( payment ) {
			rows.push( row( 'payment-' + payment.code, 'vcap-voucher-payment', payment.label, payment.formatted ) );
		} );
		( d.balances || [] ).forEach( function ( balance ) {
			rows.push( row( 'balance-' + balance.code, 'vcap-balance', balance.label, balance.formatted ) );
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

	/** "You have X store credit. Use store credit" (the link applies it, like in the classic cart). */
	function StoreCreditHint( props ) {
		var credit = data( props.extensions ).store_credit;
		if ( ! credit || ! credit.url ) {
			return null;
		}
		return el(
			'div',
			{ className: 'wc-block-components-totals-wrapper vcap-store-credit-hint' },
			el( 'p', null, credit.text + ' ', el( 'a', { href: credit.url, className: 'vcap-use-store-credit' }, credit.link_label ) )
		);
	}

	/** Whether the coupon itself discounts nothing (Store API amounts are strings in minor units). */
	function discountsNothing( coupon ) {
		var totals = coupon.totals || {};
		return ! parseInt( totals.total_discount || 0, 10 ) && ! parseInt( totals.total_discount_tax || 0, 10 );
	}

	if ( checkout.registerCheckoutFilters ) {
		checkout.registerCheckoutFilters( NAMESPACE, {
			coupons: function ( coupons, extensions ) {
				var d = data( extensions );
				var paying = d.paying_coupons || [];
				if ( ! paying.length || ! Array.isArray( coupons ) ) {
					return coupons;
				}
				return coupons.map( function ( coupon ) {
					if ( ! coupon || paying.indexOf( String( coupon.code ).toLowerCase() ) === -1 || ! discountsNothing( coupon ) ) {
						return coupon;
					}
					return Object.assign( {}, coupon, { label: ( coupon.label || coupon.code ) + ' · ' + d.coupon_note } );
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
				checkout.ExperimentalDiscountsMeta ? el( checkout.ExperimentalDiscountsMeta, null, el( Payments ), el( Forfeits ) ) : null,
				checkout.ExperimentalOrderMeta ? el( checkout.ExperimentalOrderMeta, null, el( StoreCreditHint ) ) : null
			);
		},
	} );
} )( window.wp, window.wc );
