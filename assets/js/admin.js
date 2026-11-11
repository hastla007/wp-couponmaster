/* Voucher Captain – admin helpers. */
( function ( $ ) {
	'use strict';
	$( function () {
		$( '.vcap-wrap form[data-confirm]' ).on( 'submit', function () {
			return window.confirm( $( this ).data( 'confirm' ) );
		} );
	} );
} )( jQuery );
