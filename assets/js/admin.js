/* Voucher Captain – admin helpers. */
( function ( $ ) {
	'use strict';
	$( function () {
		$( '.wcvs-wrap form[data-confirm]' ).on( 'submit', function () {
			return window.confirm( $( this ).data( 'confirm' ) );
		} );
	} );
} )( jQuery );
