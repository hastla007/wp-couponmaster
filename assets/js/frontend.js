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

	/* ---- M12-53: live preview (HTML drawing of the template design, filled while typing). ---- */

	var esc = function ( text ) {
		return String( text ).replace( /[&<>"']/g, function ( c ) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
		} );
	};

	var money = function ( value, fmt ) {
		var decimals = parseInt( fmt.decimals, 10 ) || 0;
		var parts = Math.abs( value ).toFixed( decimals ).split( '.' );
		parts[ 0 ] = parts[ 0 ].replace( /\B(?=(\d{3})+(?!\d))/g, fmt.thousand || '' );
		var number = ( value < 0 ? '-' : '' ) + parts.join( fmt.dec || '.' );
		switch ( fmt.pos ) {
			case 'right':
				return number + fmt.symbol;
			case 'right_space':
				return number + ' ' + fmt.symbol;
			case 'left_space':
				return fmt.symbol + ' ' + number;
			default:
				return fmt.symbol + number;
		}
	};

	/** Values of the live placeholders from the product form. */
	var liveValues = function ( $form, data, variation ) {
		var name = $.trim( $form.find( '[name="vcap_fields[recipient_name]"]' ).val() || '' );
		var message = $.trim( $form.find( '[name="vcap_fields[message]"]' ).val() || '' );
		var typed = parseFloat( String( $form.find( 'input[name="vcap_amount"]' ).val() || '' ).replace( ',', '.' ) );
		var amount = ! isNaN( typed ) && typed > 0 ? typed : ( variation && null !== variation.amount ? variation.amount : data.amount );
		var none = null === amount || undefined === amount;
		var shown = none ? '' : ( data.percent ? amount + ' %' : money( amount, data.money ) );
		return {
			recipient_name: name,
			holder_name: name,
			message: message,
			coupon_value: shown,
			coupon_value_plain: none ? '' : String( amount ),
			coupon_balance: shown
		};
	};

	var drawPreview = function ( box ) {
		var $box = $( box );
		var data = $box.data( 'vcapPreview' );
		if ( ! data || ! data.designs ) {
			return;
		}
		var $form = $box.closest( 'form' );
		var variationId = parseInt( $form.find( '[name="variation_id"]' ).val(), 10 ) || 0;
		var variation = data.variations ? data.variations[ variationId ] : null;
		var chosen = parseInt( $form.find( 'input[name="vcap_template"]:checked' ).val(), 10 ) || 0;
		var id = chosen && data.designs[ chosen ] ? chosen : ( variation && data.designs[ variation.template ] ? variation.template : data.template );
		var design = data.designs[ id ] || data.designs[ Object.keys( data.designs )[ 0 ] ];
		if ( ! design ) {
			return;
		}
		var values = liveValues( $form, data, variation );
		var width = $box.width() || 300;
		var scale = width / design.w; // Pixels per millimetre.
		var pct = function ( v, of ) {
			return ( v / of * 100 ).toFixed( 3 ) + '%';
		};
		var html = '';
		$.each( design.elements, function ( i, el ) {
			var style = 'left:' + pct( el.x, design.w ) + ';top:' + pct( el.y, design.h ) + ';width:' + pct( el.w, design.w ) + ';height:' + pct( el.h, design.h ) + ';opacity:' + el.op + ';';
			if ( el.rot ) {
				style += 'transform:rotate(' + el.rot + 'deg);';
			}
			if ( 'text' === el.type || 'heading' === el.type ) {
				var text = el.text.replace( /\{([a-z_]+)\}/g, function ( m, token ) {
					return Object.prototype.hasOwnProperty.call( values, token ) ? values[ token ] : m;
				} );
				// pt -> mm -> px of this box.
				style += 'font-size:' + ( el.size * 0.3528 * scale ).toFixed( 2 ) + 'px;color:' + el.color + ';text-align:' + el.align + ';' + ( el.bold ? 'font-weight:700;' : '' ) + ( el.italic ? 'font-style:italic;' : '' ) + ( el.upper ? 'text-transform:uppercase;' : '' ) + ( el.serif ? 'font-family:Georgia,serif;' : ( el.mono ? 'font-family:monospace;' : '' ) );
				html += '<div class="vcap-lp-el vcap-lp-text" style="' + esc( style ) + '">' + esc( text ).replace( /\n/g, '<br>' ) + '</div>';
			} else if ( 'rect' === el.type || 'line' === el.type ) {
				style += 'background:' + el.fill + ';border:' + Math.max( 0, el.bw * scale ).toFixed( 2 ) + 'px solid ' + el.border + ';border-radius:' + ( el.radius * scale ).toFixed( 2 ) + 'px;';
				html += '<div class="vcap-lp-el" style="' + esc( style ) + '"></div>';
			} else if ( 'image' === el.type ) {
				html += '<img class="vcap-lp-el" alt="" src="' + esc( el.src ) + '" style="' + esc( style + 'object-fit:contain;' ) + '">';
			} else if ( 'code' === el.type ) {
				html += '<div class="vcap-lp-el vcap-lp-code" style="' + esc( style ) + '"></div>';
			}
		} );
		var page = 'aspect-ratio:' + design.w + '/' + design.h + ';background-color:' + design.bg + ';';
		if ( design.bgImage ) {
			page += 'background-image:url("' + encodeURI( design.bgImage ) + '");background-size:' + ( 'stretch' === design.bgFit ? '100% 100%' : design.bgFit ) + ';background-position:center;background-repeat:no-repeat;';
		}
		$box.html( '<div class="vcap-lp-page" style="' + esc( page ) + '">' + html + '</div>' );
	};

	var drawAll = function ( scope ) {
		$( scope || document ).find( '.vcap-live-preview' ).each( function () {
			drawPreview( this );
		} );
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

		// Live preview: drawn now, again while typing, when a suggested amount, a design or a variation is chosen
		// and when the width changes (font sizes follow the box).
		drawAll();
		var redraw = function () {
			var $form = $( this ).closest( 'form' );
			drawAll( $form.length ? $form : document );
		};
		$( document ).on( 'input change', 'form.cart [name^="vcap_fields"], form.cart [name="vcap_amount"], form.cart [name="vcap_template"], form.cart .vcap-amount-presets input', redraw );
		$( document ).on( 'found_variation reset_data', 'form.variations_form', function () {
			var form = this;
			setTimeout( function () {
				drawAll( form );
			}, 0 );
		} );
		var resizeTimer;
		$( window ).on( 'resize', function () {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function () {
				drawAll();
			}, 150 );
		} );

		$( document ).on( 'click', '.vcap-preview-button', function ( e ) {
			e.preventDefault();
			var $form = $( this ).closest( 'form.cart' );
			var $post = $( '<form method="post" target="_blank" style="display:none"></form>' ).attr( 'action', cfg.previewUrl || cfg.ajaxUrl );
			var add = function ( name, value ) {
				$( '<input type="hidden">' ).attr( 'name', name ).val( value ).appendTo( $post );
			};
			add( 'action', 'vcap_product_preview' );
			add( 'nonce', cfg.nonce );
			add( 'product_id', $form.find( '[name="add-to-cart"]' ).val() || $( this ).data( 'product' ) );
			add( 'variation_id', $form.find( '[name="variation_id"]' ).val() || 0 );
			$form.find( '[name^="vcap_fields"], [name="vcap_amount"], [name="vcap_template"]' ).each( function () {
				var $f = $( this );
				if ( ( 'checkbox' === this.type || 'radio' === this.type ) && ! this.checked ) {
					return;
				}
				add( $f.attr( 'name' ), $f.val() );
			} );
			$post.appendTo( document.body ).trigger( 'submit' ).remove();
		} );

		// The referral link is selected when focused, ready to copy (no inline handler: Content Security Policy).
		$( document ).on( 'focus', '.vcap-referral-link', function () {
			this.select();
		} );

		// The result of a form sent before this page load (balance check, redeem): focused, so screen readers read it.
		var $result = $( '[data-vcap-focus]' ).first();
		if ( $result.length ) {
			$result.trigger( 'focus' );
		}
	} );
} )( jQuery );
