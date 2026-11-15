/* Voucher Captain – QR scanner (BarcodeDetector with jsQR fallback). */
( function () {
	'use strict';
	var cfg = window.vcapScanner || {};
	var t = cfg.i18n || {};
	var money = cfg.money || {};
	var amounts = window.vcapAmount;
	var video = document.getElementById( 'vcap-video' );
	var canvas = document.getElementById( 'vcap-canvas' );
	var toggleBtn = document.getElementById( 'vcap-camera-toggle' );
	var form = document.getElementById( 'vcap-manual' );
	var input = document.getElementById( 'vcap-code' );
	var result = document.getElementById( 'vcap-result' );
	var live = document.getElementById( 'vcap-live' );
	var till = document.getElementById( 'vcap-till' );
	var audio = null;
	var TIMEOUT = 10000; // No answer within 10 s: the result is unclear, never "invalid".
	// Phones and tablets: the on-screen keyboard would cover the result and the camera picture.
	var coarse = !! ( window.matchMedia && window.matchMedia( '(pointer: coarse)' ).matches );

	/** One AudioContext, created in a user gesture (iOS keeps contexts created later silent). */
	function unlockAudio() {
		if ( audio || ! ( window.AudioContext || window.webkitAudioContext ) ) {
			return;
		}
		try {
			audio = new ( window.AudioContext || window.webkitAudioContext )();
		} catch ( e ) {
			audio = null;
		}
	}
	document.addEventListener( 'pointerdown', unlockAudio, { once: true } );
	document.addEventListener( 'keydown', unlockAudio, { once: true } );

	/** Short announcement for screen readers (the result block holds buttons and is not a live region). */
	function announce() {
		var head = result.querySelector( '.vcap-r-head' );
		if ( live && head ) {
			live.textContent = '';
			live.textContent = head.textContent;
		}
	}
	var auto = document.getElementById( 'vcap-auto-checkin' );
	var acting = false;
	if ( ! video || ! form ) {
		return;
	}
	var stream = null;
	var detector = null;
	var lastCode = '';
	var lastTime = 0;
	var busy = false;
	var runId = 0;
	var starting = false;
	var current = null; // Voucher shown in the result block (amount entry, confirmation).
	var fromField = false; // Last code came from the input field (keyboard or USB scanner), not the camera.

	var torchBtn = document.getElementById( 'vcap-torch' );
	var slip = document.getElementById( 'vcap-slip' );
	var torchOn = false;

	try {
		auto.checked = '1' === window.localStorage.getItem( 'vcapAutoCheckin' );
		if ( till && ! cfg.fixedTill ) {
			// A managed list (select): a stored name that is no longer on it leaves the choice empty.
			till.value = window.localStorage.getItem( 'vcapTill' ) || '';
		}
	} catch ( e ) {}
	if ( till && cfg.fixedTill ) {
		till.value = cfg.fixedTill; // Fixed for this staff member (user profile); the server books there anyway.
	}
	auto.addEventListener( 'change', function () {
		try {
			window.localStorage.setItem( 'vcapAutoCheckin', auto.checked ? '1' : '0' );
		} catch ( e ) {}
	} );
	if ( till ) {
		// Till or branch of this device, kept with every counter booking.
		till.addEventListener( 'change', function () {
			try {
				window.localStorage.setItem( 'vcapTill', till.value.trim() );
			} catch ( e ) {}
		} );
	}

	/** One request with a time limit; no answer rejects with {timeout: true} (no status). */
	function request( path, method, body ) {
		var ctrl = window.AbortController ? new window.AbortController() : null;
		var timer = ctrl ? window.setTimeout( function () {
			ctrl.abort();
		}, TIMEOUT ) : 0;
		return fetch( cfg.root + path, {
			method: method,
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined,
			signal: ctrl ? ctrl.signal : undefined
		} ).then( function ( r ) {
			window.clearTimeout( timer );
			return r.json().catch( function () {
				return {};
			} ).then( function ( data ) {
				if ( ! r.ok ) {
					data = data || {};
					data.status = r.status;
					throw data;
				}
				return data;
			} );
		}, function () {
			window.clearTimeout( timer );
			throw { timeout: true };
		} );
	}

	/** The login nonce expires after 12–24 h: fetch a fresh one (same session) instead of "session expired". */
	function renewNonce() {
		if ( ! cfg.nonceUrl ) {
			return Promise.reject( {} );
		}
		return fetch( cfg.nonceUrl, { credentials: 'same-origin' } ).then( function ( r ) {
			return r.ok ? r.text() : Promise.reject( {} );
		} ).then( function ( n ) {
			n = String( n || '' ).trim();
			if ( ! /^[a-f0-9]{6,}$/.test( n ) ) {
				throw {};
			}
			cfg.nonce = n;
		} );
	}

	function api( path, method, body ) {
		return request( path, method || 'POST', body ).catch( function ( err ) {
			if ( err && 'rest_cookie_invalid_nonce' === err.code ) {
				return renewNonce().then( function () {
					return request( path, method || 'POST', body );
				}, function () {
					throw err;
				} );
			}
			throw err;
		} );
	}

	function esc( s ) {
		var d = document.createElement( 'div' );
		d.textContent = null === s || undefined === s ? '' : String( s );
		// Also safe inside attribute values (href, class, data-*).
		return d.innerHTML.replace( /"/g, '&quot;' ).replace( /'/g, '&#39;' );
	}

	function beep( ok ) {
		announce();
		try {
			if ( audio ) {
				if ( 'suspended' === audio.state ) {
					audio.resume();
				}
				var osc = audio.createOscillator();
				osc.frequency.value = ok ? 880 : 220;
				osc.connect( audio.destination );
				osc.start();
				osc.stop( audio.currentTime + ( ok ? 0.15 : 0.4 ) );
			}
		} catch ( e ) {}
		if ( navigator.vibrate ) {
			navigator.vibrate( ok ? 80 : [ 200, 100, 200 ] );
		}
	}

	function renderPassCaptain( d, query ) {
		var rows = [
			[ t.event, d.event ],
			[ t.ticketType, d.ticket ],
			[ t.date, d.starts ],
			[ t.attendee, d.attendee ]
		].filter( function ( r ) { return r && r[ 1 ]; } );
		var html = '<div class="vcap-r-head vcap-r-' + esc( d.state ) + '">' + esc( d.message ) + ' · ' + esc( d.type_label ) + '</div><div class="vcap-r-body"><dl>';
		rows.forEach( function ( r ) {
			html += '<dt>' + esc( r[ 0 ] ) + '</dt><dd>' + esc( r[ 1 ] ) + '</dd>';
		} );
		html += '</dl><div class="vcap-r-actions">';
		if ( d.can_checkin ) {
			html += '<button type="button" class="button button-primary button-hero" data-act="checkin">' + esc( t.checkin ) + '</button>';
		}
		if ( d.admin_url ) {
			html += '<a class="button-link" href="' + esc( d.admin_url ) + '">' + esc( t.passCaptainCheckins ) + '</a>';
		}
		html += '</div></div>';
		result.innerHTML = html;
		result.setAttribute( 'data-query', query );
	}

	/** sprintf-style templates from PHP: "%1$s … %2$s" (numbered) or "%s" / "%d" (in order). */
	function fill( tpl, args ) {
		var next = 0;
		return String( tpl || '' ).replace( /%(?:(\d)\$)?[sd]/g, function ( m, n ) {
			var v = args[ n ? parseInt( n, 10 ) - 1 : next++ ];
			return undefined === v ? m : String( v );
		} );
	}

	function decimalsOf( d ) {
		var n = parseInt( d && d.decimals, 10 );
		return isNaN( n ) ? ( parseInt( money.decimals, 10 ) || 0 ) : n;
	}

	function symbolOf( d ) {
		return ( d && d.currency_symbol ) || money.symbol || '';
	}

	function formatMinor( minor, d ) {
		return amounts.formatMoney( minor, decimalsOf( d ), symbolOf( d ), money );
	}

	/** Last characters of the code, enough for the cashier to compare with the card. */
	function cardEnd( d ) {
		return String( d.code || '' ).toUpperCase().slice( -4 );
	}

	function render( d, query ) {
		if ( 'passcaptain' === d.type ) {
			current = null;
			renderPassCaptain( d, query );
			return;
		}
		current = d;
		var rows = [
			[ t.product, d.product ],
			[ t.holder, d.holder ],
			[ t.order, d.order ? '#' + d.order : '' ],
			[ t.value, d.value_html ],
			d.multi_use && d.success && d.booked_html ? [ t.booked, d.booked_html ] : null,
			d.multi_use && d.uncovered > 0 ? [ t.uncovered, d.uncovered_html ] : null,
			d.multi_use ? [ t.balance, d.balance_html ] : null,
			'ticket' === d.type ? [ t.admissions, d.checkins + ' / ' + d.max_checkins ] : null,
			[ t.expires, d.expires ],
			d.last_checkin ? [ t.lastCheckin, d.last_checkin ] : null
		].concat( d.context || [] ).filter( function ( r ) { return r && r[ 1 ]; } );
		var html = '<div class="vcap-r-head vcap-r-' + esc( d.state ) + '">' + esc( d.message ) + ' · <code>' + esc( d.code ) + '</code></div>';
		if ( d.replayed ) {
			// Stored answer of an earlier request with the same key: nothing was booked again.
			html += '<p class="vcap-r-replayed" role="note">' + esc( t.replayed ) + '</p>';
		}
		var redeemable = 'ticket' !== d.type && ( 'ok' === d.state || 'exceeds_balance' === d.reason ) && 'redeemed' !== d.status && false !== d.redeemable;
		if ( d.personal && 'ticket' !== d.type ) {
			// Personal voucher: the holder must prove to be one of these persons (ID), online the account does.
			var who = ( d.personal.names || [] ).concat( d.personal.emails || [] ).join( ', ' );
			html += '<div class="vcap-r-personal" role="note"><strong>' + esc( t.personal ) + '</strong>' + ( who ? ' ' + esc( who ) : '' ) + '</div>';
		}
		html += '<div class="vcap-r-body"><dl>';
		rows.forEach( function ( r ) {
			html += '<dt>' + esc( r[ 0 ] ) + '</dt><dd>' + esc( r[ 1 ] ) + '</dd>';
		} );
		if ( d.treatment_label && 'ticket' !== d.type ) {
			html += '<dt>' + esc( t.vat ) + '</dt><dd>' + esc( d.treatment_label ) + '</dd>';
		}
		html += '</dl><div class="vcap-r-actions">';
		if ( 'ticket' === d.type ) {
			if ( d.checkins < d.max_checkins && 'error' !== d.state ) {
				html += ( d.max_checkins - d.checkins > 1 ? '<input type="number" min="1" value="1" max="' + ( d.max_checkins - d.checkins ) + '" class="small-text" id="vcap-count" aria-label="' + esc( t.admissions ) + '">' : '' );
				html += '<button type="button" class="button button-primary button-hero" data-act="checkin">' + esc( t.checkin ) + '</button>';
			}
			if ( d.checkins > 0 && d.undo ) {
				html += '<button type="button" class="button" data-act="undo" data-reason="' + ( 'reason' === d.undo ? '1' : '' ) + '">' + esc( t.undo ) + '</button>';
			}
		} else {
			if ( redeemable ) {
				if ( d.multi_use ) {
					var cur = d.currency || money.currency || '';
					html += '<label for="vcap-amount">' + esc( t.amount ) + ( cur ? ' (' + esc( cur ) + ')' : '' ) + '</label> ';
					html += '<span class="vcap-amount-field"><input type="text" inputmode="decimal" id="vcap-amount" class="small-text" autocomplete="off" placeholder="' + esc( amounts.formatNumber( 0, decimalsOf( d ), money.decimalSep, money.thousandSep ) ) + '" aria-describedby="vcap-amount-error">';
					html += ' <span class="vcap-amount-currency">' + esc( symbolOf( d ) ) + '</span></span>';
				}
				html += '<label for="vcap-receipt">' + esc( d.receipt_required ? t.receiptRequired : t.receipt ) + '</label> <input type="text" id="vcap-receipt" class="small-text vcap-receipt" autocomplete="off"' + ( d.receipt_required ? ' required aria-required="true"' : '' ) + '>';
				if ( d.personal ) {
					html += '<label class="vcap-id-checked"><input type="checkbox" id="vcap-id-checked"> ' + esc( t.idChecked ) + '</label>';
				}
				html += '<button type="button" class="button button-primary" data-act="redeem">' + esc( t.redeem ) + '</button>';
				html += '<p id="vcap-redeem-error" class="vcap-amount-error" role="alert" hidden></p>';
				if ( d.multi_use ) {
					// Above the balance: only an explicit second action books the remaining balance.
					var partial = 'exceeds_balance' === d.reason && d.requested > 0;
					html += '<button type="button" class="button" data-act="redeem-partial"' + ( partial ? ' data-amount="' + esc( d.requested ) + '"' : ' hidden' ) + '>' + esc( fill( t.bookPartial, [ d.balance_html ] ) ) + '</button>';
					html += '<p id="vcap-amount-error" class="vcap-amount-error" role="alert"' + ( partial ? '>' + esc( d.message ) : ' hidden>' ) + '</p>';
				}
			}
		}
		var listed = ( d.reversible || [] ).some( function ( r ) {
			return String( r.event_id ) === String( d.event_id );
		} );
		if ( d.success && d.event_id && 'ticket' !== d.type && ! listed ) {
			// Wrong card or amount: the booking can be cancelled right away (with a reason).
			html += '<button type="button" class="button" data-act="reverse" data-event="' + esc( d.event_id ) + '">' + esc( t.reverse ) + '</button>';
		}
		// The cashier's own bookings that can still be cancelled – also after the next scan (looked up again).
		( d.reversible || [] ).forEach( function ( r ) {
			html += '<button type="button" class="button vcap-r-reversible" data-act="reverse" data-event="' + esc( r.event_id ) + '">' + esc( fill( t.reverseOne, [ r.amount_html, r.time ] ) ) + '</button>';
		} );
		if ( d.success && d.slip && 'ticket' !== d.type ) {
			html += '<button type="button" class="button" data-act="print">' + esc( t.print ) + '</button>';
		}
		if ( d.admin_url ) {
			html += '<a class="button-link" href="' + esc( d.admin_url ) + '">' + esc( t.details ) + '</a>';
		}
		html += '</div></div>';
		result.innerHTML = html;
		result.setAttribute( 'data-query', query );
	}

	/** A hint that is no verdict on any code (e.g. no camera): yellow, with what to do instead. */
	function notice( text ) {
		result.removeAttribute( 'aria-busy' );
		result.innerHTML = '<div class="vcap-r-head vcap-r-warning">' + esc( text ) + '</div>';
		announce();
	}

	/** Shown while a request runs; the result block is marked busy for screen readers. */
	function loading( text ) {
		result.setAttribute( 'aria-busy', 'true' );
		if ( text ) {
			result.innerHTML = '<div class="vcap-r-head vcap-r-loading">' + esc( text ) + '</div>';
		} else {
			var head = result.querySelector( '.vcap-r-head' );
			if ( head ) {
				head.classList.add( 'vcap-r-loading' );
			}
		}
	}

	/**
	 * @param {Object}  err    Error of api() (status and code of the server, or timeout).
	 * @param {boolean} booking Whether the request could have booked something (check-in, redemption).
	 * @param {string}  query  Code to query again.
	 */
	function showError( err, booking, query ) {
		var code   = String( ( err && err.code ) || '' );
		var system = ! err || ! err.status || err.status >= 500 || 409 === err.status || 'rest_cookie_invalid_nonce' === code || /retry|busy/.test( code );
		// A server fault or an expired session is no verdict on the code: yellow "try again", never red "invalid".
		var text = system ? ( 'rest_cookie_invalid_nonce' === code ? t.sessionExpired : t.systemError ) || t.error : ( err.message || t.error );
		var again = '';
		if ( booking && system && ( ! err || ! err.status || err.status >= 500 ) ) {
			// No answer to a booking: it may have been made. Look the code up before doing anything again.
			text = t.unclear;
			again = query ? '<div class="vcap-r-body"><div class="vcap-r-actions"><button type="button" class="button button-primary" data-act="requery">' + esc( t.queryAgain ) + '</button></div></div>' : '';
		} else if ( 409 === ( err && err.status ) ) {
			text = err.message || t.systemError;
		}
		result.removeAttribute( 'aria-busy' );
		result.innerHTML = '<div class="vcap-r-head ' + ( system ? 'vcap-r-warning' : 'vcap-r-error' ) + '">' + esc( text ) + '</div>' + again;
		if ( query ) {
			result.setAttribute( 'data-query', query );
		}
		beep( false );
	}

	/** Back to the code field for the next scan – not on phones with the camera running (keyboard would cover it). */
	function refocus() {
		if ( stream || ( coarse && ! fromField ) ) {
			return;
		}
		input.focus();
		input.select();
	}

	function lookup( query ) {
		if ( busy || ! query ) {
			return;
		}
		busy = true;
		loading( t.checking );
		var booking = false;
		// Always look up first (POST: codes stay out of URLs and logs); auto check-in applies to valid tickets
		// only (never to vouchers or gift cards).
		var p = api( 'lookup', 'POST', { q: query } ).then( function ( d ) {
			if ( auto.checked && 'ok' === d.state && ( 'ticket' === d.type || ( 'passcaptain' === d.type && d.can_checkin ) ) ) {
				booking = true;
				return send( 'checkin', { q: query, till: tillValue() }, 'checkin|' + query + '|1' );
			}
			return d;
		} );
		p.then( function ( d ) {
			result.removeAttribute( 'aria-busy' );
			render( d, query );
			beep( 'ok' === d.state );
		} ).catch( function ( err ) {
			showError( err, booking, query );
		} ).then( function () {
			busy = false;
		} );
	}

	/** Inline message under the amount field (nothing is sent). */
	function amountError( text ) {
		var box = document.getElementById( 'vcap-amount-error' );
		if ( box ) {
			box.textContent = text;
			box.hidden = false;
		}
		var field = document.getElementById( 'vcap-amount' );
		if ( field ) {
			field.setAttribute( 'aria-invalid', 'true' );
			field.focus();
			field.select();
		}
		beep( false );
	}

	/**
	 * Strictly parsed amount of the field (see scanner-amount.js), or null after showing why it was refused.
	 * Never guesses: "12,50 €" is 12.50, "1.500" with two decimals is ambiguous, "0,999" has too many decimals.
	 */
	function readAmount( d ) {
		var field = document.getElementById( 'vcap-amount' );
		var dec = decimalsOf( d );
		var res = amounts.parse( field ? field.value : '', {
			decimals: dec,
			decimalSep: money.decimalSep,
			strip: [ symbolOf( d ), d.currency || money.currency ] // Only the voucher's own currency.
		} );
		if ( res.ok ) {
			return res;
		}
		var example = amounts.formatNumber( amounts.toMinor( 12.5, dec ), dec, money.decimalSep, '' );
		var msg = {
			empty: t.amountEmpty,
			zero: t.amountZero,
			negative: t.amountZero,
			ambiguous: fill( t.amountAmbiguous, [ example ] ),
			decimals: dec ? fill( t.amountDecimals, [ dec ] ) : t.amountNoDecimals
		}[ res.error ] || fill( t.amountInvalid, [ example ] );
		amountError( msg );
		return null;
	}

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return String( Date.now() ) + '-' + Math.random().toString( 16 ).slice( 2 );
	}

	// One idempotency key per intended action (code + action + count/amount). It is kept until the server
	// answered, so repeating an action after a timeout or network error replays the first result instead of
	// admitting or deducting twice; a new key is only made once the previous attempt got an answer (a key is
	// never used again after an answer). Kept in sessionStorage (this tab, a day at most): a reload after a
	// timeout still replays instead of booking again.
	var KEY_STORE = 'vcapPendingKeys';
	var pendingKeys = loadKeys();
	function loadKeys() {
		var keys = {};
		try {
			var stored = JSON.parse( window.sessionStorage.getItem( KEY_STORE ) || '{}' ) || {};
			Object.keys( stored ).forEach( function ( sig ) {
				if ( stored[ sig ] && stored[ sig ].k && Date.now() - ( stored[ sig ].t || 0 ) < 86400000 ) {
					keys[ sig ] = stored[ sig ];
				}
			} );
		} catch ( e ) {}
		return keys;
	}
	function saveKeys() {
		try {
			window.sessionStorage.setItem( KEY_STORE, JSON.stringify( pendingKeys ) );
		} catch ( e ) {}
	}
	function dropKey( sig ) {
		delete pendingKeys[ sig ];
		saveKeys();
	}
	function send( path, body, sig ) {
		if ( ! pendingKeys[ sig ] ) {
			pendingKeys[ sig ] = { k: uuid(), t: Date.now() };
			saveKeys();
		}
		body.idempotency_key = pendingKeys[ sig ].k;
		return api( path, 'POST', body ).then( function ( d ) {
			dropKey( sig );
			return d;
		}, function ( err ) {
			// Answered (4xx, also a refusal with 409) = done; no answer, server error or "still processing" = keep
			// the key for a retry.
			if ( err && err.status && err.status < 500 && 'vcap_in_progress' !== err.code ) {
				dropKey( sig );
			}
			throw err;
		} );
	}

	result.addEventListener( 'click', function ( e ) {
		var act = e.target.getAttribute( 'data-act' );
		var query = result.getAttribute( 'data-query' );
		if ( ! act || ! query || acting ) {
			return; // One action at a time: a double tap never admits twice.
		}
		if ( 'requery' === act ) {
			lookup( query );
			return;
		}
		if ( 'print' === act ) {
			printSlip( current && current.slip );
			return;
		}
		if ( 'undo' === act && ! window.confirm( t.confirmUndo ) ) {
			return;
		}
		if ( ( 'redeem' === act || 'redeem-partial' === act ) && ! counterReady() ) {
			return; // Receipt number or ID check missing: nothing is sent.
		}
		var reason = '';
		if ( ( 'undo' === act && e.target.getAttribute( 'data-reason' ) ) || 'reverse' === act ) {
			reason = window.prompt( 'reverse' === act ? t.reverseReason : t.undoReason, '' );
			if ( null === reason ) {
				return;
			}
			reason = reason.trim();
			if ( ! reason ) {
				window.alert( t.reasonRequired );
				return;
			}
		}
		var req;
		if ( 'checkin' === act ) {
			var c = document.getElementById( 'vcap-count' );
			var count = c ? parseInt( c.value, 10 ) || 1 : 1;
			req = send( 'checkin', { q: query, count: count, till: tillValue() }, 'checkin|' + query + '|' + count );
		} else if ( 'undo' === act ) {
			req = send( 'checkin', { q: query, undo: true, reason: reason, till: tillValue() }, 'undo|' + query );
		} else if ( 'reverse' === act ) {
			var eventId = parseInt( e.target.getAttribute( 'data-event' ), 10 ) || 0;
			req = send( 'redeem/reverse', { q: query, event_id: eventId, reason: reason }, 'reverse|' + query + '|' + eventId );
		} else if ( 'redeem' === act && current && current.multi_use ) {
			var pb = result.querySelector( '[data-act="redeem-partial"]' );
			var box = document.getElementById( 'vcap-amount-error' );
			var field = document.getElementById( 'vcap-amount' );
			if ( pb ) {
				pb.hidden = true; // Only for the amount just refused.
			}
			if ( box ) {
				box.hidden = true;
			}
			if ( field ) {
				field.removeAttribute( 'aria-invalid' );
			}
			var parsed = readAmount( current );
			if ( ! parsed ) {
				return;
			}
			var balance = amounts.toMinor( current.balance, decimalsOf( current ) );
			if ( parsed.minor > balance ) {
				// Refused before sending; booking the remaining balance needs its own button and confirmation.
				amountError( fill( t.amountExceeds, [ formatMinor( parsed.minor, current ), current.balance_html ] ) );
				if ( pb && balance > 0 ) {
					pb.setAttribute( 'data-amount', String( parsed.value ) );
					pb.hidden = false;
				}
				return;
			}
			if ( ! window.confirm( fill( t.confirmRedeem, [ formatMinor( parsed.minor, current ), cardEnd( current ), formatMinor( balance - parsed.minor, current ) ] ) ) ) {
				return;
			}
			req = send( 'redeem', counterBody( { q: query, amount: parsed.value } ), 'redeem|' + query + '|' + parsed.value );
		} else if ( 'redeem-partial' === act && current && current.multi_use ) {
			var wanted = parseFloat( e.target.getAttribute( 'data-amount' ) ) || 0;
			var dec = decimalsOf( current );
			var rest = amounts.toMinor( current.balance, dec );
			if ( wanted <= 0 || rest <= 0 ) {
				return;
			}
			var open = Math.max( 0, amounts.toMinor( wanted, dec ) - rest );
			if ( ! window.confirm( fill( t.confirmPartial, [ formatMinor( rest, current ), cardEnd( current ), formatMinor( open, current ), formatMinor( 0, current ) ] ) ) ) {
				return;
			}
			req = send( 'redeem', counterBody( { q: query, amount: wanted, allow_partial: true } ), 'redeem|' + query + '|' + wanted + '|partial' );
		} else if ( 'redeem' === act ) {
			req = send( 'redeem', counterBody( { q: query } ), 'redeem|' + query + '|0' );
		}
		if ( req ) {
			acting = true;
			Array.prototype.forEach.call( result.querySelectorAll( 'button' ), function ( b ) {
				b.disabled = true;
			} );
			loading( '' );
			req.then( function ( d ) {
				result.removeAttribute( 'aria-busy' );
				render( d, query );
				beep( !! d.success );
			} ).catch( function ( err ) {
				showError( err, true, query );
			} ).then( function () {
				acting = false;
				refocus(); // Ready for the next scan (USB scanners type into the focused field).
			} );
		}
	} );

	/** Receipt number of the till and the till/branch of this device, kept with the booking. */
	function counterBody( body ) {
		var receipt = document.getElementById( 'vcap-receipt' );
		var idChecked = document.getElementById( 'vcap-id-checked' );
		body.receipt = receipt ? receipt.value.trim() : '';
		body.till = tillValue();
		body.id_checked = !! ( idChecked && idChecked.checked );
		return body;
	}

	/** Till/branch of this device (fixed for the staff member, chosen from the list, or typed). */
	function tillValue() {
		return cfg.fixedTill || ( till ? String( till.value || '' ).trim() : '' );
	}

	/** Inline refusal above the redeem button (nothing is sent). */
	function redeemError( text, focus ) {
		var box = document.getElementById( 'vcap-redeem-error' );
		if ( box ) {
			box.textContent = text;
			box.hidden = false;
		}
		if ( focus ) {
			focus.focus();
		}
		beep( false );
	}

	/** Receipt number (when required), till from the list and ID check (personal vouchers) before a redemption. */
	function counterReady() {
		var receipt = document.getElementById( 'vcap-receipt' );
		var idChecked = document.getElementById( 'vcap-id-checked' );
		var box = document.getElementById( 'vcap-redeem-error' );
		if ( box ) {
			box.hidden = true;
		}
		if ( current && current.receipt_required && receipt && ! receipt.value.trim() ) {
			redeemError( t.receiptMissing, receipt );
			return false;
		}
		if ( ( cfg.locations || [] ).length && ! tillValue() ) {
			redeemError( t.tillMissing, till );
			return false;
		}
		if ( idChecked && ! idChecked.checked ) {
			redeemError( t.idMissing, idChecked );
			return false;
		}
		return true;
	}

	/** 80 mm redemption slip (masked code) printed instead of the page. */
	function printSlip( s ) {
		if ( ! s || ! slip ) {
			return;
		}
		var rows = [
			[ t.slipCode, s.code ],
			[ t.slipTime, s.time ],
			[ t.slipStaff, s.staff ],
			[ t.slipTill, s.till ],
			[ t.receipt, s.receipt ],
			[ t.slipBooking, s.booking ? '#' + s.booking : '' ],
			[ t.slipAmount, s.amount ],
			[ t.balance, s.balance ]
		].filter( function ( r ) { return r[ 1 ]; } );
		var html = '<p class="vcap-slip-shop">' + esc( s.shop ) + '</p><p class="vcap-slip-title">' + esc( t.slipTitle ) + '</p><dl>';
		rows.forEach( function ( r ) {
			html += '<dt>' + esc( r[ 0 ] ) + '</dt><dd>' + esc( r[ 1 ] ) + '</dd>';
		} );
		html += '</dl><p>' + esc( s.treatment ) + '</p><p class="vcap-slip-note">' + esc( t.slipNote ) + '</p>';
		slip.innerHTML = html;
		document.body.classList.add( 'vcap-printing-slip' );
		window.addEventListener( 'afterprint', function () {
			document.body.classList.remove( 'vcap-printing-slip' );
		}, { once: true } );
		window.print();
	}

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		fromField = true;
		lookup( input.value.trim() );
		if ( stream && coarse ) {
			input.blur(); // Hide the keyboard: the camera picture and the result stay visible.
		} else {
			input.select();
		}
	} );

	function onCode( code ) {
		var now = Date.now();
		if ( code === lastCode ) {
			var same = now - lastTime < 4000;
			lastTime = now;
			if ( same ) {
				return;
			}
		}
		if ( busy ) {
			return; // Not remembered: retried on the next frame once the running request is done.
		}
		lastCode = code;
		lastTime = now;
		fromField = false;
		input.value = code;
		lookup( code );
	}

	function tick( run ) {
		if ( ! stream || run !== runId ) {
			return; // Camera stopped or restarted: only the newest loop keeps running.
		}
		if ( video.readyState >= 2 ) {
			if ( detector ) {
				detector.detect( video ).then( function ( codes ) {
					if ( codes.length ) {
						onCode( codes[ 0 ].rawValue );
					}
				} ).catch( function () {
					detector = null; // Fall back to jsQR when the native detector fails.
				} );
			} else if ( window.jsQR ) {
				var w = video.videoWidth;
				var h = video.videoHeight;
				var scale = Math.min( 1, 640 / w );
				canvas.width = Math.round( w * scale );
				canvas.height = Math.round( h * scale );
				var ctx = canvas.getContext( '2d', { willReadFrequently: true } );
				ctx.drawImage( video, 0, 0, canvas.width, canvas.height );
				var img = ctx.getImageData( 0, 0, canvas.width, canvas.height );
				// Light-on-dark QR codes (inverted print designs) too: iOS/Firefox have no BarcodeDetector (M12-40).
				var res = window.jsQR( img.data, img.width, img.height, { inversionAttempts: 'attemptBoth' } );
				if ( res && res.data ) {
					onCode( res.data );
				}
			}
		}
		window.setTimeout( function () {
			window.requestAnimationFrame( function () {
				tick( run );
			} );
		}, 200 );
	}

	/** Why there is no camera picture – a hint, never "system error". */
	function noCamera( err ) {
		var name = String( ( err && err.name ) || '' );
		if ( false === window.isSecureContext ) {
			notice( t.noCameraHttps ); // Browsers only allow the camera on HTTPS pages.
		} else if ( 'NotAllowedError' === name || 'SecurityError' === name ) {
			notice( t.cameraDenied );
		} else {
			notice( t.noCamera );
		}
	}

	function start() {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			noCamera( null );
			return;
		}
		if ( starting ) {
			return;
		}
		starting = true;
		// About 1280 px wide: small printed codes stay readable (jsQR scales the frame down to 640 px anyway).
		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment', width: { ideal: 1280 }, height: { ideal: 720 } }, audio: false } ).then( function ( s ) {
			starting = false;
			stream = s;
			video.srcObject = s;
			var playing = video.play();
			if ( playing && playing.catch ) {
				playing.catch( function () {
					// Autoplay refused (power saving, iOS): a tap on the toggle starts it again.
					stop();
					notice( t.cameraPaused );
				} );
			}
			s.getVideoTracks().forEach( function ( track ) {
				// iOS ends the stream when the app goes to the background or another app takes the camera.
				track.addEventListener( 'ended', function () {
					if ( stream === s ) {
						stop();
					}
				} );
			} );
			setupTorch( s );
			video.parentNode.classList.add( 'is-live' );
			toggleBtn.textContent = t.stop;
			if ( coarse && document.activeElement === input ) {
				input.blur();
			}
			if ( 'BarcodeDetector' in window ) {
				try {
					detector = new window.BarcodeDetector( { formats: [ 'qr_code', 'code_128', 'code_39', 'ean_13' ] } );
				} catch ( e ) {
					detector = null;
				}
			}
			runId++;
			tick( runId );
		} ).catch( function ( err ) {
			starting = false;
			noCamera( err );
		} );
	}

	function stop() {
		if ( stream ) {
			stream.getTracks().forEach( function ( tr ) { tr.stop(); } );
		}
		stream = null;
		video.srcObject = null;
		video.parentNode.classList.remove( 'is-live' );
		toggleBtn.textContent = t.start;
		torchOn = false;
		if ( torchBtn ) {
			torchBtn.hidden = true;
			torchBtn.setAttribute( 'aria-pressed', 'false' );
		}
	}

	/** Torch button for cameras that have one (Chrome on Android; elsewhere the button stays hidden). */
	function setupTorch( s ) {
		var track = s.getVideoTracks()[ 0 ];
		var caps = track && track.getCapabilities ? track.getCapabilities() : {};
		if ( torchBtn ) {
			torchBtn.hidden = ! ( caps && caps.torch );
		}
	}

	if ( torchBtn ) {
		torchBtn.addEventListener( 'click', function () {
			var track = stream ? stream.getVideoTracks()[ 0 ] : null;
			if ( ! track || ! track.applyConstraints ) {
				return;
			}
			track.applyConstraints( { advanced: [ { torch: ! torchOn } ] } ).then( function () {
				torchOn = ! torchOn;
				torchBtn.setAttribute( 'aria-pressed', torchOn ? 'true' : 'false' );
			} ).catch( function () {
				torchBtn.hidden = true;
			} );
		} );
	}

	// Leaving the tab or locking the phone: release the camera (iOS would end the stream silently anyway).
	document.addEventListener( 'visibilitychange', function () {
		if ( document.hidden && stream ) {
			stop();
		}
	} );

	if ( ! coarse ) {
		input.focus(); // Desktop and USB scanners: ready to scan (no keyboard popping up on phones).
	}

	toggleBtn.addEventListener( 'click', function () {
		if ( stream ) {
			stop();
		} else {
			start();
		}
	} );
} )();
