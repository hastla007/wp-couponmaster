/* Voucher Captain – "Add row" / "Remove" of the country tables in the settings (SET-VC-04). Without this script the
 * table still works: one empty row is always shown, and a row whose fields are emptied is removed on save. */
( function () {
	'use strict';

	function speak( text ) {
		if ( text && window.wp && window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( text );
		}
	}

	// Labels name the row ("Country, row 2"): renumber them after a change.
	function renumber( tbody ) {
		Array.prototype.forEach.call( tbody.rows, function ( row, i ) {
			row.querySelectorAll( '[data-label]' ).forEach( function ( el ) {
				el.textContent = el.getAttribute( 'data-label' ).replace( '%d', String( i + 1 ) );
			} );
		} );
	}

	function setup( table ) {
		var cell = table.parentNode;
		var tbody = table.tBodies[ 0 ];
		var template = cell.querySelector( 'template.vcap-row-template' );
		var add = cell.querySelector( '.vcap-row-add' );
		if ( ! tbody || ! template || ! add ) {
			return;
		}
		var next = tbody.rows.length + 1000;

		function show( scope ) {
			scope.querySelectorAll( '.vcap-row-remove' ).forEach( function ( button ) {
				button.hidden = false;
			} );
		}

		add.hidden = false;
		show( tbody );
		renumber( tbody );

		add.addEventListener( 'click', function () {
			var html = template.innerHTML.replace( /__index__/g, String( next++ ) );
			tbody.insertAdjacentHTML( 'beforeend', html );
			var row = tbody.rows[ tbody.rows.length - 1 ];
			show( row );
			renumber( tbody );
			var first = row.querySelector( 'select, input, textarea' );
			if ( first ) {
				first.focus();
			}
			speak( table.getAttribute( 'data-added' ) );
		} );

		tbody.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.vcap-row-remove' );
			if ( ! button ) {
				return;
			}
			var row = button.closest( 'tr' );
			var target = row.nextElementSibling || row.previousElementSibling;
			row.parentNode.removeChild( row );
			renumber( tbody );
			// Focus stays in the table: the remove button of the following (else previous) row, else "Add row".
			var focus = target ? target.querySelector( '.vcap-row-remove' ) : null;
			( focus || add ).focus();
			speak( table.getAttribute( 'data-removed' ) );
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( 'table[data-vcap-country-table]' ).forEach( setup );
	} );
} )();
