/* Voucher Captain – product edit screen: show/hide the Voucher tab and dependent fields. */
( function ( $ ) {
	'use strict';
	function toggleTab() {
		var on = $( '#_vcap_enabled' ).is( ':checked' );
		$( '.show_if_vcap' ).toggle( on );
		$( '.show_if_vcap_parent, .show_if_vcap_variation' ).toggle( on );
		if ( ! on && $( '.vcap_options.active' ).length ) {
			$( '.general_options a' ).trigger( 'click' );
		}
	}
	function toggleFields() {
		var source = $( '#_vcap_amount_source' ).val();
		$( '.vcap-show-fixed' ).toggle( 'fixed' === source );
		$( '.vcap-show-custom' ).toggle( 'custom' === source );
		$( '.vcap-show-unlimited' ).toggle( 'unlimited' === $( '#_vcap_usage' ).val() );
		var exp = $( '#_vcap_expiry_mode' ).val();
		$( '.vcap-show-expiry-days' ).toggle( 'days' === exp || 'default' === exp );
		$( '.vcap-show-expiry-date' ).toggle( 'date' === exp );
		var del = $( '#_vcap_delivery' ).val();
		$( '.vcap-show-delay' ).toggle( 'delay' === del );
		$( '.vcap-show-date' ).toggle( 'date' === del );
		$( '.vcap-show-ticket' ).toggle( 'ticket' === $( '#_vcap_type' ).val() );
	}
	$( function () {
		$( '#_vcap_enabled' ).on( 'change', toggleTab );
		$( '#woocommerce-product-data' ).on( 'change', '#_vcap_amount_source, #_vcap_usage, #_vcap_expiry_mode, #_vcap_delivery, #_vcap_type', toggleFields );
		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', toggleTab );
		$( document.body ).on( 'woocommerce-product-type-change', toggleTab );
		toggleTab();
		toggleFields();
	} );
} )( jQuery );
