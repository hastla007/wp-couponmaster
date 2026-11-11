/* Voucher Captain – product edit screen: show/hide the Voucher tab and dependent fields. */
( function ( $ ) {
	'use strict';
	function toggleTab() {
		var on = $( '#_wcvs_enabled' ).is( ':checked' );
		$( '.show_if_wcvs' ).toggle( on );
		$( '.show_if_wcvs_parent, .show_if_wcvs_variation' ).toggle( on );
		if ( ! on && $( '.wcvs_options.active' ).length ) {
			$( '.general_options a' ).trigger( 'click' );
		}
	}
	function toggleFields() {
		var source = $( '#_wcvs_amount_source' ).val();
		$( '.wcvs-show-fixed' ).toggle( 'fixed' === source );
		$( '.wcvs-show-custom' ).toggle( 'custom' === source );
		$( '.wcvs-show-unlimited' ).toggle( 'unlimited' === $( '#_wcvs_usage' ).val() );
		var exp = $( '#_wcvs_expiry_mode' ).val();
		$( '.wcvs-show-expiry-days' ).toggle( 'days' === exp || 'default' === exp );
		$( '.wcvs-show-expiry-date' ).toggle( 'date' === exp );
		var del = $( '#_wcvs_delivery' ).val();
		$( '.wcvs-show-delay' ).toggle( 'delay' === del );
		$( '.wcvs-show-date' ).toggle( 'date' === del );
		$( '.wcvs-show-ticket' ).toggle( 'ticket' === $( '#_wcvs_type' ).val() );
	}
	$( function () {
		$( '#_wcvs_enabled' ).on( 'change', toggleTab );
		$( '#woocommerce-product-data' ).on( 'change', '#_wcvs_amount_source, #_wcvs_usage, #_wcvs_expiry_mode, #_wcvs_delivery, #_wcvs_type', toggleFields );
		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', toggleTab );
		$( document.body ).on( 'woocommerce-product-type-change', toggleTab );
		toggleTab();
		toggleFields();
	} );
} )( jQuery );
