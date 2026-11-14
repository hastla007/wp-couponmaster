/* Voucher Captain – field group editor (repeater). */
( function ( $ ) {
	'use strict';
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
	var cfg = window.vcapFieldGroups || { types: {}, i18n: {} };
	var t = cfg.i18n;

	function speak( message ) {
		if ( window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message );
		}
	}

	function row( f ) {
		f = $.extend( { key: '', label: '', type: 'text', required: false, maxlength: '', placeholder: '', tooltip: '', options: '' }, f || {} );
		var id = 'f' + Math.random().toString( 36 ).slice( 2 );
		var types = '';
		$.each( cfg.types, function ( k, v ) {
			types += '<option value="' + k + '"' + ( k === f.type ? ' selected' : '' ) + '>' + $( '<i>' ).text( v ).html() + '</option>';
		} );
		var $r = $(
			'<div class="vcap-fg-row">' +
				// Drag handle for the mouse; the arrow buttons move a field with the keyboard (and without dragging).
				'<div class="vcap-fg-move">' +
					'<span class="vcap-fg-handle dashicons dashicons-move" aria-hidden="true"></span>' +
					'<button type="button" class="button-link vcap-fg-up"><span class="dashicons dashicons-arrow-up-alt2" aria-hidden="true"></span><span class="screen-reader-text vcap-fg-name"></span></button>' +
					'<button type="button" class="button-link vcap-fg-down"><span class="dashicons dashicons-arrow-down-alt2" aria-hidden="true"></span><span class="screen-reader-text vcap-fg-name"></span></button>' +
				'</div>' +
				'<div class="vcap-fg-cols">' +
					'<p><label for="' + id + 'l">' + t.label + '</label><input type="text" id="' + id + 'l" data-k="label"></p>' +
					'<p><label for="' + id + 'k">' + t.key + '</label><input type="text" id="' + id + 'k" data-k="key" pattern="[a-z0-9_]+"></p>' +
					'<p><label for="' + id + 't">' + t.type + '</label><select id="' + id + 't" data-k="type">' + types + '</select></p>' +
					'<p><label for="' + id + 'm">' + t.maxlength + '</label><input type="number" min="0" id="' + id + 'm" data-k="maxlength"></p>' +
					'<p><label for="' + id + 'p">' + t.placeholder + '</label><input type="text" id="' + id + 'p" data-k="placeholder"></p>' +
					'<p><label for="' + id + 'h">' + t.tooltip + '</label><input type="text" id="' + id + 'h" data-k="tooltip"></p>' +
					'<p class="vcap-fg-options"><label for="' + id + 'o">' + t.options + '</label><textarea rows="3" id="' + id + 'o" data-k="options"></textarea></p>' +
					'<p class="vcap-fg-req"><label><input type="checkbox" data-k="required"> ' + t.required + '</label><br><small class="vcap-fg-token"></small></p>' +
				'</div>' +
				'<button type="button" class="button-link button-link-delete vcap-fg-remove">' + t.remove + '<span class="screen-reader-text vcap-fg-name"></span></button>' +
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

	var RESERVED = [ 'recipient_name', 'recipient_email', 'message', 'delivery_date' ];

	function slug( s ) {
		return String( s ).toLowerCase().replace( /ß/g, 'ss' ).normalize( 'NFD' ).replace( /[̀-ͯ]/g, '' ).replace( /[^a-z0-9]+/g, '_' ).replace( /^_+|_+$/g, '' );
	}

	/** Name of a row for screen readers: its label, else its key, else "Field 3". */
	function rowName( $r, index ) {
		var label = $.trim( $r.find( '[data-k="label"]' ).val() || '' ) || $.trim( $r.find( '[data-k="key"]' ).val() || '' );
		return label || ( t.field || 'Field %d' ).replace( '%d', index + 1 );
	}

	function refresh( $list ) {
		var data = [];
		var used = {};
		var $rows = $list.find( '.vcap-fg-row' );
		$rows.each( function ( index ) {
			// Buttons name their field (visible text first: voice control still finds "Remove field").
			var $row = $( this );
			var name = rowName( $row, index );
			$row.find( '.vcap-fg-up .vcap-fg-name' ).text( t.moveUp + ': ' + name );
			$row.find( '.vcap-fg-down .vcap-fg-name' ).text( t.moveDown + ': ' + name );
			$row.find( '.vcap-fg-remove .vcap-fg-name' ).text( ': ' + name );
			$row.find( '.vcap-fg-up' ).prop( 'disabled', 0 === index );
			$row.find( '.vcap-fg-down' ).prop( 'disabled', index === $rows.length - 1 );
		} );
		$rows.each( function () {
			var $r = $( this );
			var f = {};
			$r.find( '[data-k]' ).each( function () {
				f[ $( this ).data( 'k' ) ] = 'checkbox' === this.type ? this.checked : $( this ).val();
			} );
			// Same rules as Fields::save_group(): the token shown is exactly the key that will be saved.
			f.key = slug( f.key || f.label || '' );
			if ( f.key ) {
				if ( /^[0-9]+$/.test( f.key ) ) {
					f.key = 'field_' + f.key;
				}
				var base = f.key;
				var n = 2;
				while ( -1 !== RESERVED.indexOf( f.key ) || used[ f.key ] ) {
					f.key = base + '_' + n++;
				}
				used[ f.key ] = true;
			}
			$r.find( '.vcap-fg-options' ).toggle( 'select' === f.type );
			$r.find( '.vcap-fg-token' ).text( f.key ? t.placeholderToken + ' {field:' + f.key + '}' : '' );
			data.push( f );
		} );
		$( '#vcap-fields-json' ).val( JSON.stringify( data ) );
	}

	$( function () {
		var $list = $( '#vcap-field-list' );
		if ( ! $list.length ) {
			return;
		}
		( $list.data( 'fields' ) || [] ).forEach( function ( f ) {
			$list.append( row( f ) );
		} );
		$list.sortable( { handle: '.vcap-fg-handle', update: function () { refresh( $list ); } } );
		$( '#vcap-add-field' ).on( 'click', function () {
			$list.append( row() );
			refresh( $list );
			$list.find( '.vcap-fg-row:last [data-k="label"]' ).trigger( 'focus' );
		} );
		$list.on( 'input change', '[data-k]', function () { refresh( $list ); } );
		$list.on( 'click', '.vcap-fg-up, .vcap-fg-down', function () {
			var up = $( this ).hasClass( 'vcap-fg-up' );
			var $r = $( this ).closest( '.vcap-fg-row' );
			var $target = up ? $r.prev( '.vcap-fg-row' ) : $r.next( '.vcap-fg-row' );
			if ( ! $target.length ) {
				return;
			}
			if ( up ) {
				$r.insertBefore( $target );
			} else {
				$r.insertAfter( $target );
			}
			refresh( $list );
			// Focus stays on the moved field (on its other arrow when it reached the top or the bottom).
			var $button = $r.find( up ? '.vcap-fg-up' : '.vcap-fg-down' );
			if ( $button.prop( 'disabled' ) ) {
				$button = $r.find( up ? '.vcap-fg-down' : '.vcap-fg-up' );
			}
			$button.trigger( 'focus' );
			var index = $list.find( '.vcap-fg-row' ).index( $r );
			speak( ( t.moved || '%1$s: position %2$d' ).replace( '%1$s', rowName( $r, index ) ).replace( '%2$d', index + 1 ) );
		} );
		$list.on( 'click', '.vcap-fg-remove', function () {
			var $r = $( this ).closest( '.vcap-fg-row' );
			// Focus goes to the next field (or the previous one, or "Add field"), not to the page start.
			var $next = $r.next( '.vcap-fg-row' ).length ? $r.next( '.vcap-fg-row' ) : $r.prev( '.vcap-fg-row' );
			$r.remove();
			refresh( $list );
			( $next.length ? $next.find( '.vcap-fg-remove' ) : $( '#vcap-add-field' ) ).trigger( 'focus' );
			speak( t.removed || '' );
		} );
		$( '#post' ).on( 'submit', function () { refresh( $list ); } );
		refresh( $list );

		var $mode = $( '#vcap-assign-mode' );
		var toggle = function () {
			$( '.vcap-assign-row' ).each( function () {
				$( this ).toggle( $( this ).data( 'mode' ) === $mode.val() );
			} );
		};
		$mode.on( 'change', toggle );
		toggle();
		$( document.body ).trigger( 'wc-enhanced-select-init' );
		nameSelect2Fields( $( document.body ) );
	} );
} )( jQuery );
