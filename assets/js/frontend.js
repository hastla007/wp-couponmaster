/* Voucher Captain – product page: character counters, amount presets, personalised preview. */
( function ( $ ) {
	'use strict';
	var cfg = window.wcvsFront || {};

	$( function () {
		$( '[data-wcvs-counter]' ).each( function () {
			var $input = $( this );
			var max = parseInt( $input.attr( 'maxlength' ), 10 );
			var $counter = $( '<span class="wcvs-counter" aria-live="polite"></span>' );
			$input.after( $counter );
			var update = function () {
				$counter.text( ( cfg.i18n.charsLeft || '%d' ).replace( '%d', Math.max( 0, max - $input.val().length ) ) );
			};
			$input.on( 'input', update );
			update();
		} );

		$( document ).on( 'click', '.wcvs-amount-preset', function () {
			var $btn = $( this );
			$( '#wcvs-amount' ).val( $btn.data( 'amount' ) ).trigger( 'change' );
			$btn.addClass( 'is-active' ).attr( 'aria-pressed', 'true' ).siblings().removeClass( 'is-active' ).attr( 'aria-pressed', 'false' );
		} );

		$( document ).on( 'click', '.wcvs-preview-button', function ( e ) {
			e.preventDefault();
			var $form = $( this ).closest( 'form.cart' );
			var $post = $( '<form method="post" target="_blank" style="display:none"></form>' ).attr( 'action', cfg.ajaxUrl );
			var add = function ( name, value ) {
				$( '<input type="hidden">' ).attr( 'name', name ).val( value ).appendTo( $post );
			};
			add( 'action', 'wcvs_product_preview' );
			add( 'nonce', cfg.nonce );
			add( 'product_id', $form.find( '[name="add-to-cart"]' ).val() || $( this ).data( 'product' ) );
			add( 'variation_id', $form.find( '[name="variation_id"]' ).val() || 0 );
			$form.find( '[name^="wcvs_fields"], [name="wcvs_amount"]' ).each( function () {
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
