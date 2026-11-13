/* Voucher Captain – strict amount parsing and formatting for the counter scanner. */
( function ( root ) {
	'use strict';

	var SEPARATORS = /[.,'’\s  ]/;
	var MINUS = /^[-−‐‑‒–—]/;

	function trimSpace( s ) {
		return s.replace( /^[\s  ]+|[\s  ]+$/g, '' );
	}

	/** Removes the currency symbol/code and spaces, but only at the start and the end ("€ 12,50", "12,50 EUR"). */
	function stripEdges( s, tokens ) {
		var list = ( tokens || [] ).map( function ( x ) {
			return String( x || '' ).toUpperCase();
		} ).filter( function ( x ) {
			return '' !== x;
		} ).sort( function ( a, b ) {
			return b.length - a.length;
		} );
		var changed = true;
		s = trimSpace( s );
		while ( changed && s ) {
			changed = false;
			for ( var i = 0; i < list.length; i++ ) {
				var tok = list[ i ];
				if ( s.length >= tok.length && s.slice( 0, tok.length ).toUpperCase() === tok ) {
					s = trimSpace( s.slice( tok.length ) );
					changed = true;
				} else if ( s.length >= tok.length && s.slice( s.length - tok.length ).toUpperCase() === tok ) {
					s = trimSpace( s.slice( 0, s.length - tok.length ) );
					changed = true;
				}
			}
		}
		return s;
	}

	function normaliseSeparator( c ) {
		if ( '.' === c || ',' === c ) {
			return c;
		}
		return /[\s  ]/.test( c ) ? ' ' : "'";
	}

	/**
	 * Strict parsing of a typed amount. Accepts digits with at most one decimal separator ("," or ".") and
	 * optional thousands separators (".", ",", space, apostrophe) in valid positions only; anything else is an
	 * error, never a guess. "1.500" / "1,500" (one separator, three digits after it) is ambiguous and refused,
	 * unless the currency has three decimals and the separator is the shop's decimal separator.
	 *
	 * @param {*}      raw  what was typed
	 * @param {Object} opts { decimals: 2, decimalSep: ',', strip: [ '€', 'EUR' ] }
	 * @return {{ok: boolean, value: number, minor: number, error: string}}
	 *         error: 'empty', 'negative', 'invalid', 'ambiguous', 'decimals', 'zero'
	 */
	function parse( raw, opts ) {
		opts = opts || {};
		var decimals = parseInt( opts.decimals, 10 );
		decimals = isNaN( decimals ) || decimals < 0 ? 2 : Math.min( decimals, 4 );
		var fail = function ( error ) {
			return { ok: false, value: 0, minor: 0, error: error };
		};
		var s = stripEdges( String( null === raw || undefined === raw ? '' : raw ), opts.strip );
		if ( '' === s ) {
			return fail( 'empty' );
		}
		if ( MINUS.test( s ) ) {
			return fail( 'negative' );
		}
		if ( ! /^[0-9.,'’\s  ]+$/.test( s ) || ! /^\d/.test( s ) || ! /\d$/.test( s ) ) {
			return fail( 'invalid' );
		}
		var groups = s.split( SEPARATORS );
		var seps = ( s.match( new RegExp( SEPARATORS.source, 'g' ) ) || [] ).map( normaliseSeparator );
		if ( groups.some( function ( g ) { return '' === g; } ) ) {
			return fail( 'invalid' ); // Two separators in a row.
		}
		var decimalSep = '';
		var last = seps.length ? seps[ seps.length - 1 ] : '';
		if ( ( '.' === last || ',' === last ) && 1 === seps.filter( function ( x ) { return x === last; } ).length ) {
			var tail = groups[ groups.length - 1 ];
			var otherMark = seps.slice( 0, -1 ).some( function ( x ) { return '.' === x || ',' === x; } );
			if ( 3 !== tail.length || otherMark || '0' === groups[ 0 ] && 2 === groups.length ) {
				decimalSep = last;
			} else if ( decimals >= 3 && last === opts.decimalSep ) {
				decimalSep = last;
			} else {
				return fail( 'ambiguous' );
			}
		}
		var intGroups = decimalSep ? groups.slice( 0, -1 ) : groups;
		var thousands = decimalSep ? seps.slice( 0, -1 ) : seps;
		var frac = decimalSep ? groups[ groups.length - 1 ] : '';
		if ( thousands.length ) {
			if ( thousands.some( function ( x ) { return x !== thousands[ 0 ]; } ) || ! /^[1-9]\d{0,2}$/.test( intGroups[ 0 ] ) ) {
				return fail( 'invalid' );
			}
			for ( var i = 1; i < intGroups.length; i++ ) {
				if ( 3 !== intGroups[ i ].length ) {
					return fail( 'invalid' );
				}
			}
		}
		if ( frac.length > decimals ) {
			return fail( 'decimals' );
		}
		var digits = intGroups.join( '' ).replace( /^0+(?=\d)/, '' );
		if ( digits.length > 12 ) {
			return fail( 'invalid' ); // Implausibly large; also keeps the integer arithmetic exact.
		}
		while ( frac.length < decimals ) {
			frac += '0';
		}
		var minor = parseInt( digits + frac, 10 );
		if ( ! minor ) {
			return fail( 'zero' );
		}
		return { ok: true, value: minor / Math.pow( 10, decimals ), minor: minor, error: '' };
	}

	/** Minor units (cents) → "1.234,50" with the shop's separators. */
	function formatNumber( minor, decimals, decimalSep, thousandSep ) {
		var neg = minor < 0;
		var str = String( Math.abs( Math.round( minor ) ) );
		while ( str.length <= decimals ) {
			str = '0' + str;
		}
		var intPart = decimals ? str.slice( 0, str.length - decimals ) : str;
		var frac = decimals ? str.slice( str.length - decimals ) : '';
		intPart = intPart.replace( /\B(?=(\d{3})+(?!\d))/g, undefined === thousandSep ? '' : thousandSep );
		return ( neg ? '-' : '' ) + intPart + ( decimals ? ( decimalSep || '.' ) + frac : '' );
	}

	/** Same, with the currency symbol placed by WooCommerce's price format ("%1$s" symbol, "%2$s" number). */
	function formatMoney( minor, decimals, symbol, opts ) {
		opts = opts || {};
		var number = formatNumber( minor, decimals, opts.decimalSep, opts.thousandSep );
		var format = opts.priceFormat || '%2$s %1$s';
		return format.replace( '%1$s', symbol || '' ).replace( '%2$s', number );
	}

	/** Minor units of a number with the given decimals (no float noise). */
	function toMinor( value, decimals ) {
		return Math.round( Number( value || 0 ) * Math.pow( 10, decimals ) );
	}

	var api = { parse: parse, formatNumber: formatNumber, formatMoney: formatMoney, toMinor: toMinor };
	if ( 'undefined' !== typeof module && module.exports ) {
		module.exports = api;
	} else {
		root.vcapAmount = api;
	}
}( 'undefined' !== typeof window ? window : this ) );
