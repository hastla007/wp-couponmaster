/*
 * Voucher Captain – "Redeem a gift card" field and "Use store credit" in the classic cart and checkout (setting
 * "Gift card field", VCAP\GiftCardField). Applies the code through the WooCommerce AJAX endpoint and refreshes the
 * totals without reloading the page; without JavaScript the field is an ordinary form.
 */
( function ( $ ) {
	'use strict';
	var cfg = window.vcapGiftcard || {};
	if ( ! cfg.url ) {
		return;
	}

	function refresh() {
		var $body = $( document.body );
		if ( $( 'form.woocommerce-checkout' ).length ) {
			$body.trigger( 'update_checkout' );
		} else {
			// The classic cart reloads its table and totals on this event (cart.js).
			$body.trigger( 'wc_update_cart' );
		}
	}

	function send( data, $form ) {
		var $result = $form ? $form.find( '.vcap-giftcard-result' ) : $();
		if ( $form ) {
			$form.addClass( 'processing' ).find( 'button' ).prop( 'disabled', true );
		}
		return $.post( cfg.url, $.extend( { security: cfg.nonce }, data ) )
			.always( function ( response ) {
				var notices = response && response.data && response.data.notices ? response.data.notices : '';
				if ( $form ) {
					$form.removeClass( 'processing' ).find( 'button' ).prop( 'disabled', false );
				}
				$( '.woocommerce-notices-wrapper' ).first().html( notices );
				$result.text( $( '<div>' ).html( notices ).text() );
				if ( response && response.success ) {
					if ( $form ) {
						$form.find( 'input[type="text"]' ).val( '' );
					}
					refresh();
				}
			} );
	}

	$( document ).on( 'submit', '.vcap-giftcard-form', function ( event ) {
		event.preventDefault();
		var $form = $( this );
		send( { code: $form.find( 'input[name="vcap_giftcard_code"]' ).val() }, $form );
	} );

	$( document ).on( 'click', 'a.vcap-use-store-credit', function ( event ) {
		event.preventDefault();
		$( this ).closest( '.woocommerce-info, .woocommerce-message' ).remove();
		send( { credit: 1 }, null );
	} );
} )( jQuery );
