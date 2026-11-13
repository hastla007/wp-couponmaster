/* Voucher Captain – promotion editor: show fields for the chosen discount, test-cart preview. */
( function ( $ ) {
	'use strict';
	var cfg = window.vcapCampaigns || {};

	/**
	 * Screen readers: select2 (WooCommerce enhanced selects) draws its own search input without a name. It gets the
	 * accessible name of the original select (aria-label or its <label>) – after init, after a change (multi-selects
	 * redraw the input) and when the dropdown with its search field opens.
	 */
	function nameSelect2Fields( $scope ) {
		$scope.find( 'select.enhanced, select.select2-hidden-accessible' ).each( function () {
			var $select = $( this );
			var label = $select.attr( 'aria-label' ) || $.trim( $( 'label[for="' + this.id + '"]' ).first().text() );
			if ( label ) {
				$select.next( '.select2-container' ).find( '.select2-search__field, .select2-selection' ).attr( 'aria-label', label );
			}
		} );
	}
	$( document.body ).on( 'select2:open', 'select', function () {
		var label = $( this ).attr( 'aria-label' ) || $.trim( $( 'label[for="' + this.id + '"]' ).first().text() );
		if ( label ) {
			$( '.select2-container--open .select2-search__field' ).attr( 'aria-label', label );
		}
	} ).on( 'change select2:select select2:unselect', 'select', function () {
		nameSelect2Fields( $( this ).parent() );
	} );

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
		nameSelect2Fields( $( document.body ) );

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
