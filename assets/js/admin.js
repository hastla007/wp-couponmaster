/* Voucher Captain – admin helpers. */
( function ( $ ) {
	'use strict';
	$( function () {
		$( '.vcap-wrap form[data-confirm]' ).on( 'submit', function () {
			return window.confirm( $( this ).data( 'confirm' ) );
		} );
		// List table: the bulk action selects (top and bottom) are named action / action2.
		$( '.vcap-wrap form[data-bulk-void-confirm]' ).on( 'submit', function ( e ) {
			var form = $( this );
			var used = $( e.originalEvent && e.originalEvent.submitter ? e.originalEvent.submitter : [] ).attr( 'id' ) === 'doaction2' ? 'action2' : 'action';
			var action = form.find( 'select[name="' + used + '"]' ).val() || form.find( 'select[name="action"]' ).val();
			if ( 'void' === action && form.find( 'input[name="voucher_ids[]"]:checked' ).length ) {
				return window.confirm( form.data( 'bulk-void-confirm' ) );
			}
			return true;
		} );
	} );
} )( jQuery );
