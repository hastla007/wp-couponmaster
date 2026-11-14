/* Voucher Captain – storefront: character counters, suggested amounts, personalised preview, form results. */
( function ( $ ) {
	'use strict';
	var cfg = window.vcapFront || {};
	var i18n = cfg.i18n || {};
	// Screen readers hear the characters left only near the limit, and only after typing paused.
	var ANNOUNCE_BELOW = 20;
	var ANNOUNCE_DELAY = 700;

	/**
	 * Character counters; also for fields inserted later (quick view, page builder widgets loaded by AJAX). The
	 * counter is the field's description (aria-describedby: the limit is read when the field is focused) and is
	 * not a live region: a separate, visually hidden live region speaks the characters left near the limit.
	 */
	var counter = function ( input ) {
		var $input = $( input );
		var max = parseInt( $input.attr( 'maxlength' ), 10 );
		if ( ! max ) {
			return;
		}
		var $counter = $input.next( '.vcap-counter' );
		if ( ! $counter.length ) {
			var id = ( input.id || 'vcap-field' ) + '-counter';
			$counter = $( '<span class="vcap-counter"></span>' ).attr( 'id', id );
			var $live = $( '<span class="vcap-counter-live screen-reader-text" aria-live="polite"></span>' );
			$input.after( $counter, $live );
			var described = $.trim( ( $input.attr( 'aria-describedby' ) || '' ) + ' ' + id );
			$input.attr( 'aria-describedby', described );
		}
		var left = Math.max( 0, max - $input.val().length );
		var text = ( i18n.charsLeft || '%d' ).replace( '%d', left );
		if ( $counter.text() === text ) {
			return;
		}
		$counter.text( text );
		var $region = $counter.next( '.vcap-counter-live' );
		clearTimeout( $input.data( 'vcapAnnounce' ) );
		$input.data(
			'vcapAnnounce',
			setTimeout( function () {
				$region.text( left <= ANNOUNCE_BELOW ? text : '' );
			}, ANNOUNCE_DELAY )
		);
	};

	/** The amount field of the same product form (two forms on one page: each has its own field). */
	var amountField = function ( el ) {
		return $( el ).closest( '.vcap-amount' ).find( 'input[name="vcap_amount"]' );
	};

	$( function () {
		$( '[data-vcap-counter]' ).each( function () {
			counter( this );
		} );
		$( document ).on( 'input focus', '[data-vcap-counter]', function () {
			counter( this );
		} );

		// Suggested amounts are radio buttons (they work without JavaScript); the amount field follows them …
		$( '.vcap-amount-presets input[type="radio"]:checked' ).each( function () {
			var $field = amountField( this );
			if ( '' === $field.val() ) {
				$field.val( this.value );
			}
		} );
		$( document ).on( 'change', '.vcap-amount-presets input[type="radio"]', function () {
			amountField( this ).val( this.value ).trigger( 'change' );
		} );
		// … and a typed amount selects the matching suggestion, or none.
		$( document ).on( 'input', '.vcap-amount input[name="vcap_amount"]', function () {
			var value = parseFloat( this.value );
			$( this ).closest( '.vcap-amount' ).find( '.vcap-amount-presets input[type="radio"]' ).each( function () {
				this.checked = ! isNaN( value ) && Math.abs( parseFloat( this.value ) - value ) < 0.0001;
			} );
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

		// The result of a form sent before this page load (balance check, redeem): focused, so screen readers read it.
		var $result = $( '[data-vcap-focus]' ).first();
		if ( $result.length ) {
			$result.trigger( 'focus' );
		}
	} );
} )( jQuery );
