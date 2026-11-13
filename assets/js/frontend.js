/* Voucher Captain – product page: character counters, amount presets, personalised preview. */
( function ( $ ) {
	'use strict';
	var cfg = window.vcapFront || {};

	// Character counters; also for fields inserted later (quick view, page builder widgets loaded by AJAX).
	var counter = function ( input ) {
		var $input = $( input );
		var $counter = $input.next( '.vcap-counter' );
		if ( ! $counter.length ) {
			$counter = $( '<span class="vcap-counter" aria-live="polite"></span>' );
			$input.after( $counter );
		}
		var max = parseInt( $input.attr( 'maxlength' ), 10 );
		$counter.text( ( ( cfg.i18n && cfg.i18n.charsLeft ) || '%d' ).replace( '%d', Math.max( 0, max - $input.val().length ) ) );
	};

	$( function () {
		$( '[data-vcap-counter]' ).each( function () {
			counter( this );
		} );
		$( document ).on( 'input focus', '[data-vcap-counter]', function () {
			counter( this );
		} );

		$( document ).on( 'click', '.vcap-amount-preset', function () {
			var $btn = $( this );
			$( '#vcap-amount' ).val( $btn.data( 'amount' ) ).trigger( 'change' );
			$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' ).siblings().removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );
		} );

		$( document ).on( 'click', '.vcap-preview-button', function ( e ) {
			e.preventDefault();
			var $form = $( this ).closest( 'form.cart' );
			var $post = $( '<form method="post" target="_blank" style="display:none"></form>' ).attr( 'action', cfg.ajaxUrl );
			var add = function ( name, value ) {
				$( '<input type="hidden">' ).attr( 'name', name ).val( value ).appendTo( $post );
			};
			add( 'action', 'vcap_product_preview' );
			add( 'nonce', cfg.nonce );
			add( 'product_id', $form.find( '[name="add-to-cart"]' ).val() || $( this ).data( 'product' ) );
			add( 'variation_id', $form.find( '[name="variation_id"]' ).val() || 0 );
			$form.find( '[name^="vcap_fields"], [name="vcap_amount"]' ).each( function () {
				var $f = $( this );
				if ( 'checkbox' === this.type && ! this.checked ) {
					return;
				}
				add( $f.attr( 'name' ), $f.val() );
			} );
			$post.appendTo( document.body ).trigger( 'submit' ).remove();
		} );
	} );
} )( jQuery );
