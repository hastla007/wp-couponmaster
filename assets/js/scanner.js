/* Voucher Captain – QR scanner (BarcodeDetector with jsQR fallback). */
( function () {
	'use strict';
	var cfg = window.vcapScanner || {};
	var t = cfg.i18n || {};
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

	function render( d, query ) {
		if ( 'passcaptain' === d.type ) {
			renderPassCaptain( d, query );
			return;
		}
		var rows = [
			[ t.product, d.product ],
			[ t.holder, d.holder ],
			[ t.order, d.order ? '#' + d.order : '' ],
			[ t.value, d.value_html ],
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
		} else if ( 'ok' === d.state && 'redeemed' !== d.status ) {
			if ( false !== d.redeemable ) {
				if ( d.multi_use ) {
					html += '<label for="vcap-amount">' + esc( t.amount ) + '</label> <input type="text" inputmode="decimal" id="vcap-amount" class="small-text">';
				}
				html += '<button type="button" class="button button-primary" data-act="redeem">' + esc( t.redeem ) + '</button>';
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
				return api( 'checkin', 'POST', { q: query, idempotency_key: uuid() } );
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

	/** "12,50", "1.000,00", "1,000.00", "1.000" (thousands) → number. */
	function parseAmount( raw ) {
		var v = String( raw || '' ).replace( /[\s\u00a0']/g, '' );
		if ( /,\d{1,2}$/.test( v ) ) {
			v = v.replace( /\./g, '' ).replace( ',', '.' );
		} else if ( /\.\d{3}$/.test( v ) && -1 === v.indexOf( ',' ) ) {
			v = v.replace( /\./g, '' );
		} else {
			v = v.replace( /,/g, '' );
		}
		return parseFloat( v ) || 0;
	}

	function uuid() {
		if ( window.crypto && window.crypto.randomUUID ) {
			return window.crypto.randomUUID();
		}
		return String( Date.now() ) + '-' + Math.random().toString( 16 ).slice( 2 );
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
			req = api( 'checkin', 'POST', { q: query, count: c ? parseInt( c.value, 10 ) || 1 : 1, idempotency_key: uuid() } );
		} else if ( 'undo' === act ) {
			req = api( 'checkin', 'POST', { q: query, undo: true } );
		} else if ( 'redeem' === act ) {
			var a = document.getElementById( 'vcap-amount' );
			req = api( 'redeem', 'POST', { q: query, amount: a ? parseAmount( a.value ) : 0 } );
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
