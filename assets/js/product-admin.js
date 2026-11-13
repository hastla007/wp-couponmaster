/* Voucher Captain – product edit screen: show/hide the Voucher tab, dependent fields and setup hints. */
( function ( $ ) {
	'use strict';
	function toggleTab() {
		var on = $( '#_vcap_enabled' ).is( ':checked' );
		$( '.show_if_vcap' ).toggle( on );
		$( '.show_if_vcap_parent, .show_if_vcap_variation' ).toggle( on );
		if ( ! on && $( '.vcap_options.active' ).length ) {
			$( '.general_options a' ).trigger( 'click' );
		}
		toggleHints();
	}
	function toggleFields() {
		var source = $( '#_vcap_amount_source' ).val();
		$( '.vcap-show-fixed' ).toggle( 'fixed' === source );
		$( '.vcap-show-custom' ).toggle( 'custom' === source );
		$( '.vcap-show-unlimited' ).toggle( 'unlimited' === $( '#_vcap_usage' ).val() );
		var exp = $( '#_vcap_expiry_mode' ).val();
		// "Valid for (days)" belongs to "Days after purchase" only; the global default has its own validity.
		$( '.vcap-show-expiry-days' ).toggle( 'days' === exp );
		$( '.vcap-show-expiry-date' ).toggle( 'date' === exp );
		var del = $( '#_vcap_delivery' ).val();
		$( '.vcap-show-delay' ).toggle( 'delay' === del );
		$( '.vcap-show-date' ).toggle( 'date' === del );
		$( '.vcap-show-ticket' ).toggle( 'ticket' === $( '#_vcap_type' ).val() );
		$( '.vcap-show-batch' ).toggle( '' !== ( $( '#_vcap_code_batch' ).val() || '' ) );
		toggleHints();
	}
	function toggleHints() {
		var on = $( '#_vcap_enabled' ).is( ':checked' );
		var type = $( '#_vcap_type' ).val();
		var single = 'single' === $( '#_vcap_usage' ).val() && 'percent' !== $( '#_vcap_discount_type' ).val();
		var restrict = $( '#_vcap_email_restrict' ).val();
		var recipientField = $( 'input[name="_vcap_builtin_fields[]"][value="recipient_email"]' ).is( ':checked' );
		$( '.vcap-hint-giftcard-single' ).toggle( on && single && 'giftcard' === type );
		$( '.vcap-hint-value-single' ).toggle( on && single && 'voucher' === type );
		$( '.vcap-hint-buyer-recipient' ).toggle( on && recipientField && 'buyer' === $( '#_vcap_send_to' ).val() && 'recipient' !== restrict && 'both' !== restrict );
		$( '.vcap-hint-virtual' ).toggle( on && 'simple' === $( '#product-type' ).val() && $( '#_virtual' ).length > 0 && ! $( '#_virtual' ).is( ':checked' ) );
	}
	$( function () {
		$( '#_vcap_enabled' ).on( 'change', toggleTab );
		$( '#woocommerce-product-data' ).on( 'change', '#_vcap_amount_source, #_vcap_usage, #_vcap_expiry_mode, #_vcap_delivery, #_vcap_type, #_vcap_code_batch', toggleFields );
		$( '#woocommerce-product-data' ).on( 'change', '#_vcap_discount_type, #_vcap_send_to, #_vcap_email_restrict, input[name="_vcap_builtin_fields[]"], #_virtual, #product-type', toggleHints );
		// Switching the expiry mode clears a number of days that would otherwise linger unseen.
		$( '#woocommerce-product-data' ).on( 'change', '#_vcap_expiry_mode', function () {
			if ( 'days' !== $( this ).val() ) {
				$( '#_vcap_expiry_days' ).val( '' );
			}
		} );
		// The customer's delivery date only works with the delivery "On a date chosen by the customer": ticking the
		// field (or "required") selects that delivery; another delivery switches the field off (saving enforces it too).
		var dateBoxes = 'input[name="_vcap_builtin_fields[]"][value="delivery_date"], input[name="_vcap_builtin_fields[]"][value="delivery_date_required"]';
		$( '#woocommerce-product-data' ).on( 'change', dateBoxes, function () {
			if ( $( this ).is( ':checked' ) && 'customer' !== $( '#_vcap_delivery' ).val() ) {
				$( '#_vcap_delivery' ).val( 'customer' ).trigger( 'change' );
			}
		} );
		$( '#woocommerce-product-data' ).on( 'change', '#_vcap_delivery', function () {
			if ( 'customer' !== $( this ).val() ) {
				$( dateBoxes ).filter( ':checked' ).prop( 'checked', false );
			}
		} );
		// A gift card keeps its remaining value: choosing the type switches "single use" to the stored balance.
		$( '#woocommerce-product-data' ).on( 'change', '#_vcap_type', function () {
			if ( 'giftcard' === $( this ).val() && 'single' === $( '#_vcap_usage' ).val() ) {
				$( '#_vcap_usage' ).val( 'balance' ).trigger( 'change' );
			}
		} );
		$( '#woocommerce-product-data' ).on( 'woocommerce_variations_loaded', toggleTab );
		$( document.body ).on( 'woocommerce-product-type-change', toggleTab );
		toggleTab();
		toggleFields();
	} );
} )( jQuery );
