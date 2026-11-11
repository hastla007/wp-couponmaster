/* Voucher Studio – field group editor (repeater). */
( function ( $ ) {
	'use strict';
	var cfg = window.wcvsFieldGroups || { types: {}, i18n: {} };
	var t = cfg.i18n;

	function row( f ) {
		f = $.extend( { key: '', label: '', type: 'text', required: false, maxlength: '', placeholder: '', tooltip: '', options: '' }, f || {} );
		var id = 'f' + Math.random().toString( 36 ).slice( 2 );
		var types = '';
		$.each( cfg.types, function ( k, v ) {
			types += '<option value="' + k + '"' + ( k === f.type ? ' selected' : '' ) + '>' + $( '<i>' ).text( v ).html() + '</option>';
		} );
		var $r = $(
			'<div class="wcvs-fg-row">' +
				'<span class="wcvs-fg-handle dashicons dashicons-move" aria-hidden="true"></span>' +
				'<div class="wcvs-fg-cols">' +
					'<p><label for="' + id + 'l">' + t.label + '</label><input type="text" id="' + id + 'l" data-k="label"></p>' +
					'<p><label for="' + id + 'k">' + t.key + '</label><input type="text" id="' + id + 'k" data-k="key" pattern="[a-z0-9_]+"></p>' +
					'<p><label for="' + id + 't">' + t.type + '</label><select id="' + id + 't" data-k="type">' + types + '</select></p>' +
					'<p><label for="' + id + 'm">' + t.maxlength + '</label><input type="number" min="0" id="' + id + 'm" data-k="maxlength"></p>' +
					'<p><label for="' + id + 'p">' + t.placeholder + '</label><input type="text" id="' + id + 'p" data-k="placeholder"></p>' +
					'<p><label for="' + id + 'h">' + t.tooltip + '</label><input type="text" id="' + id + 'h" data-k="tooltip"></p>' +
					'<p class="wcvs-fg-options"><label for="' + id + 'o">' + t.options + '</label><textarea rows="3" id="' + id + 'o" data-k="options"></textarea></p>' +
					'<p class="wcvs-fg-req"><label><input type="checkbox" data-k="required"> ' + t.required + '</label><br><small class="wcvs-fg-token"></small></p>' +
				'</div>' +
				'<button type="button" class="button-link button-link-delete wcvs-fg-remove">' + t.remove + '</button>' +
			'</div>'
		);
		$r.find( '[data-k]' ).each( function () {
			var k = $( this ).data( 'k' );
			if ( 'checkbox' === this.type ) {
				this.checked = !! f[ k ];
			} else {
				$( this ).val( f[ k ] );
			}
		} );
		return $r;
	}

	function slug( s ) {
		return String( s ).toLowerCase().normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
	}

	function refresh( $list ) {
		var data = [];
		$list.find( '.wcvs-fg-row' ).each( function () {
			var $r = $( this );
			var f = {};
			$r.find( '[data-k]' ).each( function () {
				f[ $( this ).data( 'k' ) ] = 'checkbox' === this.type ? this.checked : $( this ).val();
			} );
			if ( ! f.key && f.label ) {
				f.key = slug( f.label );
			}
			$r.find( '.wcvs-fg-options' ).toggle( 'select' === f.type );
			$r.find( '.wcvs-fg-token' ).text( f.key ? t.placeholderToken + ' {field:' + f.key + '}' : '' );
			data.push( f );
		} );
		$( '#wcvs-fields-json' ).val( JSON.stringify( data ) );
	}

	$( function () {
		var $list = $( '#wcvs-field-list' );
		if ( ! $list.length ) {
			return;
		}
		( $list.data( 'fields' ) || [] ).forEach( function ( f ) {
			$list.append( row( f ) );
		} );
		$list.sortable( { handle: '.wcvs-fg-handle', update: function () { refresh( $list ); } } );
		$( '#wcvs-add-field' ).on( 'click', function () {
			$list.append( row() );
			refresh( $list );
			$list.find( '.wcvs-fg-row:last [data-k="label"]' ).trigger( 'focus' );
		} );
		$list.on( 'input change', '[data-k]', function () { refresh( $list ); } );
		$list.on( 'click', '.wcvs-fg-remove', function () {
			$( this ).closest( '.wcvs-fg-row' ).remove();
			refresh( $list );
		} );
		$( '#post' ).on( 'submit', function () { refresh( $list ); } );
		refresh( $list );

		var $mode = $( '#wcvs-assign-mode' );
		var toggle = function () {
			$( '.wcvs-assign-row' ).each( function () {
				$( this ).toggle( $( this ).data( 'mode' ) === $mode.val() );
			} );
		};
		$mode.on( 'change', toggle );
		toggle();
		$( document.body ).trigger( 'wc-enhanced-select-init' );
	} );
} )( jQuery );
