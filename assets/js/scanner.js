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
	var audio = null;

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

	try {
		auto.checked = '1' === window.localStorage.getItem( 'vcapAutoCheckin' );
	} catch ( e ) {}
	auto.addEventListener( 'change', function () {
		try {
			window.localStorage.setItem( 'vcapAutoCheckin', auto.checked ? '1' : '0' );
		} catch ( e ) {}
	} );

	function api( path, method, body ) {
		// With plain permalinks the REST root already contains "?rest_route=…".
		if ( -1 !== cfg.root.indexOf( '?' ) ) {
			path = path.replace( '?', '&' );
		}
		return fetch( cfg.root + path, {
			method: method || 'GET',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' },
			body: body ? JSON.stringify( body ) : undefined
		} ).then( function ( r ) {
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
		].filter( function ( r ) { return r && r[ 1 ]; } );
		var html = '<div class="vcap-r-head vcap-r-' + esc( d.state ) + '">' + esc( d.message ) + ' · <code>' + esc( d.code ) + '</code></div><div class="vcap-r-body"><dl>';
		rows.forEach( function ( r ) {
			html += '<dt>' + esc( r[ 0 ] ) + '</dt><dd>' + esc( r[ 1 ] ) + '</dd>';
		} );
		html += '</dl><div class="vcap-r-actions">';
		if ( 'ticket' === d.type ) {
			if ( d.checkins < d.max_checkins && 'error' !== d.state ) {
				html += ( d.max_checkins - d.checkins > 1 ? '<input type="number" min="1" value="1" max="' + ( d.max_checkins - d.checkins ) + '" class="small-text" id="vcap-count" aria-label="' + esc( t.admissions ) + '">' : '' );
				html += '<button type="button" class="button button-primary button-hero" data-act="checkin">' + esc( t.checkin ) + '</button>';
			}
			if ( d.checkins > 0 ) {
				html += '<button type="button" class="button" data-act="undo">' + esc( t.undo ) + '</button>';
			}
		} else if ( ( 'ok' === d.state || 'exceeds_balance' === d.reason ) && 'redeemed' !== d.status ) {
			if ( false !== d.redeemable ) {
				if ( d.multi_use ) {
					var cur = d.currency || money.currency || '';
					html += '<label for="vcap-amount">' + esc( t.amount ) + ( cur ? ' (' + esc( cur ) + ')' : '' ) + '</label> ';
					html += '<span class="vcap-amount-field"><input type="text" inputmode="decimal" id="vcap-amount" class="small-text" autocomplete="off" placeholder="' + esc( amounts.formatNumber( 0, decimalsOf( d ), money.decimalSep, money.thousandSep ) ) + '" aria-describedby="vcap-amount-error">';
					html += ' <span class="vcap-amount-currency">' + esc( symbolOf( d ) ) + '</span></span>';
				}
				html += '<button type="button" class="button button-primary" data-act="redeem">' + esc( t.redeem ) + '</button>';
				if ( d.multi_use ) {
					// Above the balance: only an explicit second action books the remaining balance.
					var partial = 'exceeds_balance' === d.reason && d.requested > 0;
					html += '<button type="button" class="button" data-act="redeem-partial"' + ( partial ? ' data-amount="' + esc( d.requested ) + '"' : ' hidden' ) + '>' + esc( fill( t.bookPartial, [ d.balance_html ] ) ) + '</button>';
					html += '<p id="vcap-amount-error" class="vcap-amount-error" role="alert"' + ( partial ? '>' + esc( d.message ) : ' hidden>' ) + '</p>';
				}
			}
		}
		html += '<a class="button-link" href="' + esc( d.admin_url ) + '">' + esc( t.details ) + '</a></div></div>';
		result.innerHTML = html;
		result.setAttribute( 'data-query', query );
	}

	function showError( err ) {
		var code   = String( ( err && err.code ) || '' );
		var system = ! err || ! err.status || err.status >= 500 || 'rest_cookie_invalid_nonce' === code || /retry|busy/.test( code );
		// A server fault or an expired session is no verdict on the code: yellow "try again", never red "invalid".
		var text = system ? ( 'rest_cookie_invalid_nonce' === code ? t.sessionExpired : t.systemError ) || t.error : ( err.message || t.error );
		result.innerHTML = '<div class="vcap-r-head ' + ( system ? 'vcap-r-warning' : 'vcap-r-error' ) + '">' + esc( text ) + '</div>';
		beep( false );
	}

	function lookup( query ) {
		if ( busy || ! query ) {
			return;
		}
		busy = true;
		// Always look up first; auto check-in applies to valid tickets only (never to vouchers or gift cards).
		var p = api( 'lookup?q=' + encodeURIComponent( query ) ).then( function ( d ) {
			if ( auto.checked && 'ok' === d.state && ( 'ticket' === d.type || ( 'passcaptain' === d.type && d.can_checkin ) ) ) {
				return send( 'checkin', { q: query }, 'checkin|' + query + '|1' );
			}
			return d;
		} );
		p.then( function ( d ) {
			render( d, query );
			beep( 'ok' === d.state );
		} ).catch( showError ).then( function () {
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
	// admitting or deducting twice; a new key is only made once the previous attempt got an answer.
	var pendingKeys = {};
	function send( path, body, sig ) {
		var key = pendingKeys[ sig ] || ( pendingKeys[ sig ] = uuid() );
		body.idempotency_key = key;
		return api( path, 'POST', body ).then( function ( d ) {
			delete pendingKeys[ sig ];
			return d;
		}, function ( err ) {
			// Answered (4xx) = done; no answer, server error or "still processing" (409) = keep the key for a retry.
			if ( err && err.status && err.status < 500 && 409 !== err.status ) {
				delete pendingKeys[ sig ];
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
		if ( 'undo' === act && ! window.confirm( t.confirmUndo ) ) {
			return;
		}
		var req;
		if ( 'checkin' === act ) {
			var c = document.getElementById( 'vcap-count' );
			var count = c ? parseInt( c.value, 10 ) || 1 : 1;
			req = send( 'checkin', { q: query, count: count }, 'checkin|' + query + '|' + count );
		} else if ( 'undo' === act ) {
			req = send( 'checkin', { q: query, undo: true }, 'undo|' + query );
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
			req = send( 'redeem', { q: query, amount: parsed.value }, 'redeem|' + query + '|' + parsed.value );
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
			req = send( 'redeem', { q: query, amount: wanted, allow_partial: true }, 'redeem|' + query + '|' + wanted + '|partial' );
		} else if ( 'redeem' === act ) {
			req = send( 'redeem', { q: query }, 'redeem|' + query + '|0' );
		}
		if ( req ) {
			acting = true;
			Array.prototype.forEach.call( result.querySelectorAll( 'button' ), function ( b ) {
				b.disabled = true;
			} );
			req.then( function ( d ) {
				render( d, query );
				beep( !! d.success );
			} ).catch( showError ).then( function () {
				acting = false;
				input.focus(); // Ready for the next scan (USB scanners type into the focused field).
				input.select();
			} );
		}
	} );

	form.addEventListener( 'submit', function ( e ) {
		e.preventDefault();
		lookup( input.value.trim() );
		input.select();
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
				var res = window.jsQR( img.data, img.width, img.height, { inversionAttempts: 'dontInvert' } );
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

	function start() {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			showError( { message: t.noCamera } );
			return;
		}
		if ( starting ) {
			return;
		}
		starting = true;
		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' }, audio: false } ).then( function ( s ) {
			starting = false;
			stream = s;
			video.srcObject = s;
			video.play();
			video.parentNode.classList.add( 'is-live' );
			toggleBtn.textContent = t.stop;
			if ( 'BarcodeDetector' in window ) {
				try {
					detector = new window.BarcodeDetector( { formats: [ 'qr_code', 'code_128', 'code_39', 'ean_13' ] } );
				} catch ( e ) {
					detector = null;
				}
			}
			runId++;
			tick( runId );
		} ).catch( function () {
			starting = false;
			showError( { message: t.noCamera } );
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
	}

	toggleBtn.addEventListener( 'click', function () {
		if ( stream ) {
			stop();
		} else {
			start();
		}
	} );
} )();
