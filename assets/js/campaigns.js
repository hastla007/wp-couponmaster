/* Voucher Captain – promotion editor: show fields for the chosen discount, test-cart preview. */
( function ( $ ) {
	'use strict';
	var cfg = window.vcapCampaigns || {};

	function toggle() {
		var action = $( '#vcap-action' ).val();
		$( '.vcap-for' ).hide();
		$( '.vcap-for-' + action ).show();
		$( '.vcap-for-roles' ).toggle( 'roles' === $( '#vcap-customer' ).val() );
	}

	$( function () {
		$( '#vcap-action, #vcap-customer' ).on( 'change', toggle );
		toggle();
		$( document.body ).trigger( 'wc-enhanced-select-init' );

		$( '#vcap-campaign-preview' ).on( 'click', function () {
			var data = $( '#post' ).find( '[name^="vcap_rules"], [name^="vcap_preview"]' ).serializeArray();
			data.push( { name: 'action', value: 'vcap_campaign_preview' }, { name: 'nonce', value: cfg.nonce } );
			var $out = $( '#vcap-campaign-preview-result' ).text( '…' );
			$.post( cfg.ajaxUrl, $.param( data ) ).done( function ( res ) {
				$out.html( res && res.success ? res.data : ( res && res.data ? $( '<p>' ).text( res.data ) : '' ) );
			} ).fail( function () {
				$out.text( 'Error' );
			} );
		} );
	} );
} )( jQuery );
