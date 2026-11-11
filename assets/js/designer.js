/**
 * Voucher Studio – visual PDF template designer.
 *
 * Data model (stored as JSON in the hidden #wcvs-design-json textarea):
 *   { page: { format, orientation, width, height, background, backgroundImageId, backgroundImage, backgroundFit },
 *     elements: [ { id, type, name, x, y, w, h, rotation, opacity, locked, hidden, ...typeProps } ] }
 * All geometry is in millimetres; elements later in the array are rendered on top.
 */
( function () {
	'use strict';

	var cfg = window.wcvsDesigner || {};
	var t = cfg.i18n || {};
	var root = document.getElementById( 'wcvs-designer' );
	var store = document.getElementById( 'wcvs-design-json' );
	if ( ! root || ! store ) {
		return;
	}

	var PT_TO_MM = 0.352778;
	var SNAP_MM = 1.5;
	var FONT_STACKS = {
		'DejaVu Sans': '"DejaVu Sans", Verdana, Geneva, sans-serif',
		'DejaVu Serif': '"DejaVu Serif", Georgia, "Times New Roman", serif',
		'DejaVu Sans Mono': '"DejaVu Sans Mono", Menlo, Consolas, monospace',
		Helvetica: 'Helvetica, Arial, sans-serif',
		Times: '"Times New Roman", Times, serif',
		Courier: '"Courier New", Courier, monospace'
	};

	var design;
	try {
		design = JSON.parse( store.value );
	} catch ( e ) {
		design = null;
	}
	if ( ! design || ! design.page ) {
		design = JSON.parse( JSON.stringify( cfg.starters.giftcard ) );
	}
	design.elements = design.elements || [];

	var state = {
		selected: null,
		scale: 3, // px per mm
		snap: true,
		history: [],
		future: []
	};

	// ------------------------------------------------------------------ helpers.

	function el( tag, attrs, children ) {
		var node = document.createElement( tag );
		Object.keys( attrs || {} ).forEach( function ( key ) {
			var value = attrs[ key ];
			if ( 'class' === key ) {
				node.className = value;
			} else if ( 'text' === key ) {
				node.textContent = value;
			} else if ( 'html' === key ) {
				node.innerHTML = value;
			} else if ( 0 === key.indexOf( 'on' ) && 'function' === typeof value ) {
				node.addEventListener( key.substring( 2 ), value );
			} else if ( true === value ) {
				node.setAttribute( key, key );
			} else if ( false !== value && null !== value && undefined !== value ) {
				node.setAttribute( key, value );
			}
		} );
		( children || [] ).forEach( function ( child ) {
			if ( null === child || undefined === child ) {
				return;
			}
			node.appendChild( 'string' === typeof child ? document.createTextNode( child ) : child );
		} );
		return node;
	}

	function round( v, d ) {
		var f = Math.pow( 10, d || 2 );
		return Math.round( v * f ) / f;
	}

	function uid() {
		return 'e' + Date.now().toString( 36 ) + Math.floor( Math.random() * 1e4 ).toString( 36 );
	}

	function pageSize() {
		var p = design.page;
		var w, h;
		if ( 'custom' === p.format ) {
			return [ Math.max( 20, +p.width || 148 ), Math.max( 20, +p.height || 210 ) ];
		}
		var f = cfg.formats[ p.format ] || cfg.formats.A5;
		w = Math.min( f[ 0 ], f[ 1 ] );
		h = Math.max( f[ 0 ], f[ 1 ] );
		return 'landscape' === p.orientation ? [ h, w ] : [ w, h ];
	}

	function findEl( id ) {
		for ( var i = 0; i < design.elements.length; i++ ) {
			if ( design.elements[ i ].id === id ) {
				return design.elements[ i ];
			}
		}
		return null;
	}

	function indexOf( id ) {
		for ( var i = 0; i < design.elements.length; i++ ) {
			if ( design.elements[ i ].id === id ) {
				return i;
			}
		}
		return -1;
	}

	function sampleText( text ) {
		return String( text || '' ).replace( /\{([a-z0-9_]+)(?::([^}]+))?\}/gi, function ( m, key, arg ) {
			if ( arg ) {
				return 'field' === key ? '[' + arg + ']' : m;
			}
			return Object.prototype.hasOwnProperty.call( cfg.sample, key ) ? cfg.sample[ key ] : m;
		} );
	}

	// ------------------------------------------------------------------ history.

	var commitTimer = null;

	function save() {
		store.value = JSON.stringify( design );
	}

	function commit( immediate ) {
		save();
		var snapshot = store.value;
		var push = function () {
			if ( state.history[ state.history.length - 1 ] !== snapshot ) {
				state.history.push( snapshot );
				if ( state.history.length > 60 ) {
					state.history.shift();
				}
				state.future = [];
			}
			updateToolbar();
		};
		clearTimeout( commitTimer );
		if ( immediate ) {
			push();
		} else {
			commitTimer = setTimeout( push, 400 );
		}
	}

	function undo() {
		if ( state.history.length < 2 ) {
			return;
		}
		state.future.push( state.history.pop() );
		design = JSON.parse( state.history[ state.history.length - 1 ] );
		if ( state.selected && ! findEl( state.selected ) ) {
			state.selected = null;
		}
		save();
		renderAll();
	}

	function redo() {
		if ( ! state.future.length ) {
			return;
		}
		var snap = state.future.pop();
		state.history.push( snap );
		design = JSON.parse( snap );
		save();
		renderAll();
	}

	// ------------------------------------------------------------------ layout.

	root.innerHTML = '';
	var toolbar = el( 'div', { class: 'wcvs-d-toolbar', role: 'toolbar', 'aria-label': t.add } );
	var workspace = el( 'div', { class: 'wcvs-d-workspace', tabindex: '0', 'aria-label': t.shortcuts } );
	var page = el( 'div', { class: 'wcvs-d-page' } );
	var guidesLayer = el( 'div', { class: 'wcvs-d-guides', 'aria-hidden': 'true' } );
	var sidebar = el( 'div', { class: 'wcvs-d-sidebar' } );
	var propsPanel = el( 'div', { class: 'wcvs-d-panel wcvs-d-props' } );
	var layersPanel = el( 'div', { class: 'wcvs-d-panel wcvs-d-layers' } );
	var pagePanel = el( 'div', { class: 'wcvs-d-panel wcvs-d-pageprops' } );

	page.appendChild( guidesLayer );
	workspace.appendChild( page );
	sidebar.appendChild( propsPanel );
	sidebar.appendChild( layersPanel );
	sidebar.appendChild( pagePanel );
	root.appendChild( toolbar );
	root.appendChild( el( 'div', { class: 'wcvs-d-main' }, [ workspace, sidebar ] ) );
	root.appendChild( el( 'p', { class: 'description wcvs-d-help', text: t.shortcuts } ) );

	// ------------------------------------------------------------------ toolbar.

	var undoBtn, redoBtn, zoomLabel;

	function addElement( type ) {
		var size = pageSize();
		var base = { id: uid(), type: type, name: t[ type ] || type, x: 10, y: 10, w: 60, h: 12, rotation: 0, opacity: 1 };
		switch ( type ) {
			case 'heading':
				Object.assign( base, { text: t.heading, font: 'DejaVu Sans', size: 24, color: '#222222', bold: true, h: 14 } );
				break;
			case 'text':
				Object.assign( base, { text: '{coupon_code}', font: 'DejaVu Sans', size: 12, color: '#222222' } );
				break;
			case 'image':
				Object.assign( base, { w: 40, h: 30, fit: 'contain', src: '', imageId: 0 } );
				break;
			case 'qrcode':
				Object.assign( base, { w: 30, h: 30, qrContent: 'code', color: '#000000' } );
				break;
			case 'barcode':
				Object.assign( base, { w: 60, h: 18, qrValue: '{coupon_code}', barcodeType: 'C128', color: '#000000', showText: true } );
				break;
			case 'rect':
				Object.assign( base, { w: 50, h: 30, fill: '#e8e2d6', borderColor: '#000000', borderWidth: 0, radius: 0 } );
				break;
			case 'line':
				Object.assign( base, { w: 80, h: 1, borderColor: '#000000', borderWidth: 0.5 } );
				break;
		}
		base.x = round( Math.max( 0, ( size[ 0 ] - base.w ) / 2 ) );
		base.y = round( Math.max( 0, ( size[ 1 ] - base.h ) / 2 ) );
		design.elements.push( base );
		state.selected = base.id;
		commit( true );
		renderAll();
		if ( 'image' === type ) {
			chooseImage( function ( att ) {
				base.imageId = att.id;
				base.src = att.url;
				if ( att.width && att.height ) {
					base.h = round( base.w * att.height / att.width );
				}
				commit( true );
				renderAll();
			} );
		}
	}

	function buildToolbar() {
		toolbar.innerHTML = '';
		var group = el( 'div', { class: 'wcvs-d-group' }, [ el( 'span', { class: 'wcvs-d-label', text: t.add + ':' } ) ] );
		[ 'heading', 'text', 'image', 'qrcode', 'barcode', 'rect', 'line' ].forEach( function ( type ) {
			group.appendChild( el( 'button', { type: 'button', class: 'button wcvs-d-add wcvs-d-add--' + type, text: t[ type ], onclick: function () { addElement( type ); } } ) );
		} );
		toolbar.appendChild( group );

		undoBtn = el( 'button', { type: 'button', class: 'button', title: t.undo + ' (Ctrl+Z)', 'aria-label': t.undo, html: '&#8630;', onclick: undo } );
		redoBtn = el( 'button', { type: 'button', class: 'button', title: t.redo + ' (Ctrl+Y)', 'aria-label': t.redo, html: '&#8631;', onclick: redo } );
		zoomLabel = el( 'span', { class: 'wcvs-d-zoom', 'aria-live': 'polite' } );
		var snapBox = el( 'input', { type: 'checkbox', id: 'wcvs-d-snap', checked: state.snap, onchange: function ( e ) { state.snap = e.target.checked; } } );
		var starter = el( 'select', { 'aria-label': t.starter, onchange: function ( e ) {
			var key = e.target.value;
			e.target.value = '';
			if ( key && cfg.starters[ key ] && window.confirm( t.confirmStarter ) ) {
				design = JSON.parse( JSON.stringify( cfg.starters[ key ] ) );
				state.selected = null;
				commit( true );
				renderAll();
			}
		} }, [
			el( 'option', { value: '', text: t.starter } ),
			el( 'option', { value: 'giftcard', text: t.starterGift } ),
			el( 'option', { value: 'ticket', text: t.starterTicket } )
		] );

		toolbar.appendChild( el( 'div', { class: 'wcvs-d-group' }, [
			undoBtn, redoBtn,
			el( 'button', { type: 'button', class: 'button', 'aria-label': t.zoom + ' -', text: '−', onclick: function () { setZoom( state.scale / 1.2 ); } } ),
			zoomLabel,
			el( 'button', { type: 'button', class: 'button', 'aria-label': t.zoom + ' +', text: '+', onclick: function () { setZoom( state.scale * 1.2 ); } } ),
			el( 'label', { class: 'wcvs-d-check', for: 'wcvs-d-snap' }, [ snapBox, ' ' + t.grid ] ),
			starter,
			el( 'button', { type: 'button', class: 'button button-primary', text: t.preview, onclick: preview } )
		] ) );
		updateToolbar();
	}

	function updateToolbar() {
		if ( undoBtn ) {
			undoBtn.disabled = state.history.length < 2;
			redoBtn.disabled = ! state.future.length;
			zoomLabel.textContent = Math.round( state.scale / 3 * 100 ) + '%';
		}
	}

	function setZoom( scale ) {
		state.scale = Math.max( 1, Math.min( 10, scale ) );
		renderCanvas();
		updateToolbar();
	}

	function fitZoom() {
		var size = pageSize();
		var avail = Math.max( 300, workspace.clientWidth - 40 );
		state.scale = Math.max( 1, Math.min( 6, avail / size[ 0 ] ) );
	}

	// ------------------------------------------------------------------ canvas.

	function qrPlaceholder() {
		// Static QR-like pattern used only for on-screen display.
		var cells = [];
		var n = 21;
		var seed = 7;
		for ( var y = 0; y < n; y++ ) {
			for ( var x = 0; x < n; x++ ) {
				var finder = ( x < 7 && y < 7 ) || ( x > 13 && y < 7 ) || ( x < 7 && y > 13 );
				var on;
				if ( finder ) {
					var fx = x > 13 ? x - 14 : x;
					var fy = y > 13 ? y - 14 : y;
					on = fx === 0 || fx === 6 || fy === 0 || fy === 6 || ( fx > 1 && fx < 5 && fy > 1 && fy < 5 );
				} else {
					seed = ( seed * 9301 + 49297 ) % 233280;
					on = seed / 233280 > 0.55;
				}
				if ( on ) {
					cells.push( '<rect x="' + x + '" y="' + y + '" width="1" height="1"/>' );
				}
			}
		}
		return '<svg viewBox="0 0 21 21" preserveAspectRatio="xMidYMid meet" width="100%" height="100%" shape-rendering="crispEdges">' + cells.join( '' ) + '</svg>';
	}

	var QR_SVG = qrPlaceholder();

	function renderCanvas() {
		var s = state.scale;
		var size = pageSize();
		page.style.width = size[ 0 ] * s + 'px';
		page.style.height = size[ 1 ] * s + 'px';
		page.style.backgroundColor = design.page.background || '#ffffff';
		if ( design.page.backgroundImage ) {
			page.style.backgroundImage = 'url("' + design.page.backgroundImage.replace( /"/g, '%22' ) + '")';
			var fit = design.page.backgroundFit || 'cover';
			page.style.backgroundSize = 'stretch' === fit ? '100% 100%' : fit;
			page.style.backgroundPosition = 'center';
			page.style.backgroundRepeat = 'no-repeat';
		} else {
			page.style.backgroundImage = 'none';
		}

		Array.prototype.slice.call( page.querySelectorAll( '.wcvs-d-el' ) ).forEach( function ( n ) {
			n.parentNode.removeChild( n );
		} );

		design.elements.forEach( function ( item, i ) {
			var node = el( 'div', {
				class: 'wcvs-d-el wcvs-d-el--' + item.type + ( item.id === state.selected ? ' is-selected' : '' ) + ( item.locked ? ' is-locked' : '' ) + ( item.hidden ? ' is-hidden' : '' ),
				'data-id': item.id,
				role: 'button',
				tabindex: '-1',
				'aria-label': item.name || t[ item.type ]
			} );
			node.style.left = item.x * s + 'px';
			node.style.top = item.y * s + 'px';
			node.style.width = item.w * s + 'px';
			node.style.height = Math.max( 1, item.h * s ) + 'px';
			node.style.zIndex = String( i + 1 );
			node.style.transform = item.rotation ? 'rotate(' + item.rotation + 'deg)' : '';
			node.style.opacity = undefined === item.opacity ? 1 : item.opacity;

			var inner = el( 'div', { class: 'wcvs-d-inner' } );
			switch ( item.type ) {
				case 'text':
				case 'heading':
					var txt = sampleText( item.text );
					inner.textContent = item.uppercase ? txt.toUpperCase() : txt;
					inner.style.fontFamily = FONT_STACKS[ item.font ] || FONT_STACKS[ 'DejaVu Sans' ];
					inner.style.fontSize = ( item.size || 12 ) * PT_TO_MM * s + 'px';
					inner.style.lineHeight = item.lineHeight || 1.25;
					inner.style.color = item.color || '#000';
					inner.style.textAlign = item.align || 'left';
					inner.style.fontWeight = item.bold || ( 'heading' === item.type && undefined === item.bold ) ? '700' : '400';
					inner.style.fontStyle = item.italic ? 'italic' : 'normal';
					inner.style.textDecoration = item.underline ? 'underline' : 'none';
					inner.style.letterSpacing = item.letterSpacing ? item.letterSpacing * PT_TO_MM * s + 'px' : 'normal';
					inner.style.whiteSpace = 'pre-wrap';
					inner.style.background = item.fill && 'transparent' !== item.fill ? item.fill : 'transparent';
					inner.style.display = 'flex';
					inner.style.flexDirection = 'column';
					inner.style.justifyContent = { top: 'flex-start', middle: 'center', bottom: 'flex-end' }[ item.valign || 'top' ];
					break;
				case 'image':
					if ( item.src ) {
						var img = el( 'img', { src: item.src, alt: '', draggable: 'false' } );
						img.style.objectFit = 'stretch' === item.fit ? 'fill' : ( item.fit || 'contain' );
						inner.appendChild( img );
					} else {
						inner.className += ' wcvs-d-empty';
						inner.textContent = t.image;
					}
					break;
				case 'qrcode':
					inner.innerHTML = QR_SVG;
					inner.style.color = item.color || '#000';
					inner.firstChild.setAttribute( 'fill', item.color || '#000' );
					break;
				case 'barcode':
					inner.className += ' wcvs-d-barcode';
					inner.style.color = item.color || '#000';
					inner.innerHTML = '<span class="bars"></span>' + ( false !== item.showText ? '<span class="lbl">' + sampleText( item.qrValue || '{coupon_code}' ).replace( /</g, '&lt;' ) + '</span>' : '' );
					break;
				case 'rect':
					inner.style.background = item.fill && 'transparent' !== item.fill ? item.fill : 'transparent';
					inner.style.border = item.borderWidth ? item.borderWidth * s + 'px solid ' + ( item.borderColor || '#000' ) : 'none';
					inner.style.borderRadius = ( item.radius || 0 ) * s + 'px';
					inner.style.boxSizing = 'border-box';
					break;
				case 'line':
					node.style.height = Math.max( 1, ( item.borderWidth || 0.5 ) * s ) + 'px';
					inner.style.background = item.borderColor || '#000';
					break;
			}
			node.appendChild( inner );

			if ( item.id === state.selected && ! item.locked ) {
				[ 'nw', 'n', 'ne', 'e', 'se', 's', 'sw', 'w' ].forEach( function ( h ) {
					if ( 'line' === item.type && -1 === [ 'e', 'w' ].indexOf( h ) ) {
						return;
					}
					node.appendChild( el( 'span', { class: 'wcvs-d-handle wcvs-d-handle--' + h, 'data-handle': h } ) );
				} );
				node.appendChild( el( 'span', { class: 'wcvs-d-rotate', 'data-handle': 'rotate', title: t.rotation } ) );
			}
			page.appendChild( node );
		} );
	}

	// ------------------------------------------------------------------ interaction (drag, resize, rotate, snap).

	function showGuides( lines ) {
		guidesLayer.innerHTML = '';
		var s = state.scale;
		lines.forEach( function ( g ) {
			var line = el( 'div', { class: 'wcvs-d-guide wcvs-d-guide--' + g.axis } );
			if ( 'x' === g.axis ) {
				line.style.left = g.pos * s + 'px';
			} else {
				line.style.top = g.pos * s + 'px';
			}
			guidesLayer.appendChild( line );
		} );
	}

	function snapTargets( excludeId ) {
		var size = pageSize();
		var xs = [ 0, size[ 0 ] / 2, size[ 0 ] ];
		var ys = [ 0, size[ 1 ] / 2, size[ 1 ] ];
		design.elements.forEach( function ( o ) {
			if ( o.id === excludeId || o.hidden ) {
				return;
			}
			xs.push( o.x, o.x + o.w / 2, o.x + o.w );
			ys.push( o.y, o.y + o.h / 2, o.y + o.h );
		} );
		return { xs: xs, ys: ys };
	}

	function snapValue( candidates, targets ) {
		var best = null;
		candidates.forEach( function ( c ) {
			targets.forEach( function ( tg ) {
				var d = tg - c.value;
				if ( Math.abs( d ) <= SNAP_MM && ( null === best || Math.abs( d ) < Math.abs( best.delta ) ) ) {
					best = { delta: d, pos: tg };
				}
			} );
		} );
		return best;
	}

	var drag = null;

	page.addEventListener( 'pointerdown', function ( e ) {
		var node = e.target.closest( '.wcvs-d-el' );
		if ( ! node ) {
			if ( e.target === page || e.target === guidesLayer ) {
				state.selected = null;
				renderAll();
			}
			return;
		}
		var item = findEl( node.getAttribute( 'data-id' ) );
		if ( ! item ) {
			return;
		}
		e.preventDefault();
		workspace.focus( { preventScroll: true } );
		if ( state.selected !== item.id ) {
			state.selected = item.id;
			renderAll();
		}
		if ( item.locked ) {
			return;
		}
		var handle = e.target.getAttribute( 'data-handle' );
		var rect = page.getBoundingClientRect();
		drag = {
			id: item.id,
			mode: handle ? ( 'rotate' === handle ? 'rotate' : 'resize' ) : 'move',
			handle: handle,
			startX: e.clientX,
			startY: e.clientY,
			orig: { x: item.x, y: item.y, w: item.w, h: item.h, rotation: item.rotation || 0 },
			pageRect: rect,
			targets: snapTargets( item.id ),
			moved: false
		};
		page.setPointerCapture( e.pointerId );
	} );

	page.addEventListener( 'pointermove', function ( e ) {
		if ( ! drag ) {
			return;
		}
		var item = findEl( drag.id );
		if ( ! item ) {
			return;
		}
		var s = state.scale;
		var dx = ( e.clientX - drag.startX ) / s;
		var dy = ( e.clientY - drag.startY ) / s;
		var o = drag.orig;
		var guides = [];
		drag.moved = true;

		if ( 'move' === drag.mode ) {
			var nx = o.x + dx;
			var ny = o.y + dy;
			if ( state.snap && ! e.altKey ) {
				var sx = snapValue( [ { value: nx }, { value: nx + o.w / 2 }, { value: nx + o.w } ], drag.targets.xs );
				var sy = snapValue( [ { value: ny }, { value: ny + o.h / 2 }, { value: ny + o.h } ], drag.targets.ys );
				if ( sx ) {
					nx += sx.delta;
					guides.push( { axis: 'x', pos: sx.pos } );
				}
				if ( sy ) {
					ny += sy.delta;
					guides.push( { axis: 'y', pos: sy.pos } );
				}
			}
			item.x = round( nx );
			item.y = round( ny );
		} else if ( 'resize' === drag.mode ) {
			var h = drag.handle;
			var x = o.x, y = o.y, w = o.w, hh = o.h;
			if ( -1 !== h.indexOf( 'e' ) ) {
				w = Math.max( 2, o.w + dx );
			}
			if ( -1 !== h.indexOf( 's' ) ) {
				hh = Math.max( 1, o.h + dy );
			}
			if ( -1 !== h.indexOf( 'w' ) ) {
				w = Math.max( 2, o.w - dx );
				x = o.x + o.w - w;
			}
			if ( -1 !== h.indexOf( 'n' ) ) {
				hh = Math.max( 1, o.h - dy );
				y = o.y + o.h - hh;
			}
			var keepRatio = e.shiftKey || 'qrcode' === item.type;
			if ( keepRatio && 2 === h.length ) {
				var ratio = o.w / o.h;
				if ( w / hh > ratio ) {
					hh = w / ratio;
				} else {
					w = hh * ratio;
				}
				if ( -1 !== h.indexOf( 'w' ) ) {
					x = o.x + o.w - w;
				}
				if ( -1 !== h.indexOf( 'n' ) ) {
					y = o.y + o.h - hh;
				}
			}
			if ( state.snap && ! e.altKey ) {
				if ( -1 !== h.indexOf( 'e' ) ) {
					var se = snapValue( [ { value: x + w } ], drag.targets.xs );
					if ( se ) {
						w += se.delta;
						guides.push( { axis: 'x', pos: se.pos } );
					}
				}
				if ( -1 !== h.indexOf( 's' ) ) {
					var ss = snapValue( [ { value: y + hh } ], drag.targets.ys );
					if ( ss ) {
						hh += ss.delta;
						guides.push( { axis: 'y', pos: ss.pos } );
					}
				}
			}
			item.x = round( x );
			item.y = round( y );
			item.w = round( w );
			if ( 'line' !== item.type ) {
				item.h = round( hh );
			}
		} else if ( 'rotate' === drag.mode ) {
			var cx = drag.pageRect.left + ( o.x + o.w / 2 ) * s;
			var cy = drag.pageRect.top + ( o.y + o.h / 2 ) * s;
			var angle = Math.atan2( e.clientY - cy, e.clientX - cx ) * 180 / Math.PI + 90;
			if ( e.shiftKey || state.snap ) {
				var snapped = Math.round( angle / 15 ) * 15;
				if ( e.shiftKey || Math.abs( snapped - angle ) < 3 ) {
					angle = snapped;
				}
			}
			angle = ( ( angle % 360 ) + 360 ) % 360;
			item.rotation = round( angle > 180 ? angle - 360 : angle, 1 );
		}
		showGuides( guides );
		renderCanvas();
		renderProps( true );
	} );

	function endDrag() {
		if ( drag ) {
			if ( drag.moved ) {
				commit( true );
			}
			drag = null;
			showGuides( [] );
			renderAll();
		}
	}
	page.addEventListener( 'pointerup', endDrag );
	page.addEventListener( 'pointercancel', endDrag );

	page.addEventListener( 'dblclick', function ( e ) {
		var node = e.target.closest( '.wcvs-d-el' );
		if ( node ) {
			var field = propsPanel.querySelector( 'textarea, [data-key="qrValue"]' );
			if ( field ) {
				field.focus();
			}
		}
	} );

	workspace.addEventListener( 'keydown', function ( e ) {
		var item = state.selected ? findEl( state.selected ) : null;
		var mod = e.ctrlKey || e.metaKey;
		if ( mod && 'z' === e.key.toLowerCase() ) {
			e.preventDefault();
			if ( e.shiftKey ) {
				redo();
			} else {
				undo();
			}
			return;
		}
		if ( mod && 'y' === e.key.toLowerCase() ) {
			e.preventDefault();
			redo();
			return;
		}
		if ( ! item ) {
			return;
		}
		var step = e.shiftKey ? 5 : 0.5;
		var moves = { ArrowLeft: [ -step, 0 ], ArrowRight: [ step, 0 ], ArrowUp: [ 0, -step ], ArrowDown: [ 0, step ] };
		if ( moves[ e.key ] && ! item.locked ) {
			e.preventDefault();
			item.x = round( item.x + moves[ e.key ][ 0 ] );
			item.y = round( item.y + moves[ e.key ][ 1 ] );
			commit();
			renderAll();
		} else if ( ( 'Delete' === e.key || 'Backspace' === e.key ) && ! item.locked ) {
			e.preventDefault();
			removeSelected();
		} else if ( mod && 'd' === e.key.toLowerCase() ) {
			e.preventDefault();
			duplicateSelected();
		} else if ( 'Escape' === e.key ) {
			state.selected = null;
			renderAll();
		}
	} );

	function removeSelected() {
		var i = indexOf( state.selected );
		if ( i > -1 ) {
			design.elements.splice( i, 1 );
			state.selected = null;
			commit( true );
			renderAll();
		}
	}

	function duplicateSelected() {
		var item = findEl( state.selected );
		if ( ! item ) {
			return;
		}
		var copy = JSON.parse( JSON.stringify( item ) );
		copy.id = uid();
		copy.x = round( copy.x + 3 );
		copy.y = round( copy.y + 3 );
		copy.locked = false;
		design.elements.splice( indexOf( item.id ) + 1, 0, copy );
		state.selected = copy.id;
		commit( true );
		renderAll();
	}

	function moveLayer( id, dir ) {
		var i = indexOf( id );
		var j = i + dir;
		if ( i < 0 || j < 0 || j >= design.elements.length ) {
			return;
		}
		var tmp = design.elements[ i ];
		design.elements[ i ] = design.elements[ j ];
		design.elements[ j ] = tmp;
		commit( true );
		renderAll();
	}

	// ------------------------------------------------------------------ media.

	function chooseImage( done ) {
		if ( ! window.wp || ! wp.media ) {
			return;
		}
		var frame = wp.media( { title: t.choose, multiple: false, library: { type: 'image' } } );
		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first().toJSON();
			done( att );
		} );
		frame.open();
	}

	// ------------------------------------------------------------------ property panels.

	function field( label, input, wide ) {
		var id = 'wcvs-d-f-' + Math.random().toString( 36 ).slice( 2 );
		input.id = input.id || id;
		return el( 'div', { class: 'wcvs-d-field' + ( wide ? ' is-wide' : '' ) }, [ el( 'label', { for: input.id, text: label } ), input ] );
	}

	function numberInput( obj, key, opts ) {
		opts = opts || {};
		return el( 'input', {
			type: 'number',
			step: opts.step || '0.5',
			min: undefined === opts.min ? null : opts.min,
			max: undefined === opts.max ? null : opts.max,
			value: undefined === obj[ key ] ? ( opts.def || 0 ) : obj[ key ],
			'data-key': key,
			oninput: function ( e ) {
				var v = parseFloat( e.target.value );
				if ( ! isNaN( v ) ) {
					obj[ key ] = v;
					commit();
					renderCanvas();
					renderLayers();
				}
			}
		} );
	}

	function textInput( obj, key, multiline ) {
		return el( multiline ? 'textarea' : 'input', {
			type: multiline ? null : 'text',
			rows: multiline ? 4 : null,
			value: multiline ? null : ( obj[ key ] || '' ),
			text: multiline ? ( obj[ key ] || '' ) : null,
			'data-key': key,
			oninput: function ( e ) {
				obj[ key ] = e.target.value;
				commit();
				renderCanvas();
				if ( 'name' === key ) {
					renderLayers();
				}
			}
		} );
	}

	function colorInput( obj, key, allowTransparent ) {
		var transparent = 'transparent' === obj[ key ] || ( allowTransparent && ! obj[ key ] );
		var color = el( 'input', { type: 'color', value: transparent ? '#ffffff' : ( obj[ key ] || '#000000' ), disabled: transparent, oninput: function ( e ) {
			obj[ key ] = e.target.value;
			commit();
			renderCanvas();
		} } );
		var wrap = el( 'span', { class: 'wcvs-d-color' }, [ color ] );
		if ( allowTransparent ) {
			var box = el( 'input', { type: 'checkbox', checked: transparent, onchange: function ( e ) {
				obj[ key ] = e.target.checked ? 'transparent' : color.value;
				color.disabled = e.target.checked;
				commit( true );
				renderCanvas();
			} } );
			wrap.appendChild( el( 'label', { class: 'wcvs-d-check' }, [ box, ' ∅' ] ) );
		}
		return wrap;
	}

	function selectInput( obj, key, options, after ) {
		var sel = el( 'select', { 'data-key': key, onchange: function ( e ) {
			obj[ key ] = e.target.value;
			commit( true );
			renderCanvas();
			if ( after ) {
				after();
			}
		} } );
		Object.keys( options ).forEach( function ( value ) {
			sel.appendChild( el( 'option', { value: value, text: options[ value ], selected: String( obj[ key ] ) === value } ) );
		} );
		return sel;
	}

	function toggle( obj, key, label ) {
		var input = el( 'input', { type: 'checkbox', checked: !! obj[ key ], onchange: function ( e ) {
			obj[ key ] = e.target.checked;
			commit( true );
			renderCanvas();
		} } );
		return el( 'label', { class: 'wcvs-d-toggle' }, [ input, ' ' + label ] );
	}

	function placeholderSelect( textarea, obj, key ) {
		var sel = el( 'select', { 'aria-label': t.insert, onchange: function ( e ) {
			var token = e.target.value;
			e.target.value = '';
			if ( ! token ) {
				return;
			}
			var start = textarea.selectionStart || 0;
			var end = textarea.selectionEnd || 0;
			var value = textarea.value;
			textarea.value = value.slice( 0, start ) + token + value.slice( end );
			obj[ key ] = textarea.value;
			commit( true );
			renderCanvas();
			textarea.focus();
			textarea.selectionStart = textarea.selectionEnd = start + token.length;
		} }, [ el( 'option', { value: '', text: t.insert } ) ] );
		( cfg.placeholders || [] ).forEach( function ( p ) {
			sel.appendChild( el( 'option', { value: p.token, text: p.label + '  ' + p.token } ) );
		} );
		return sel;
	}

	function alignButtons( item ) {
		var size = pageSize();
		var defs = [
			[ t.left, function () { item.x = 0; } ],
			[ t.center, function () { item.x = round( ( size[ 0 ] - item.w ) / 2 ); } ],
			[ t.right, function () { item.x = round( size[ 0 ] - item.w ); } ],
			[ t.top, function () { item.y = 0; } ],
			[ t.middle, function () { item.y = round( ( size[ 1 ] - item.h ) / 2 ); } ],
			[ t.bottom, function () { item.y = round( size[ 1 ] - item.h ); } ]
		];
		return el( 'div', { class: 'wcvs-d-align', role: 'group', 'aria-label': t.alignPage }, defs.map( function ( d ) {
			return el( 'button', { type: 'button', class: 'button button-small', text: d[ 0 ], onclick: function () {
				d[ 1 ]();
				commit( true );
				renderAll();
			} } );
		} ) );
	}

	function renderProps( geometryOnly ) {
		var item = state.selected ? findEl( state.selected ) : null;
		if ( geometryOnly && item ) {
			[ 'x', 'y', 'w', 'h', 'rotation' ].forEach( function ( key ) {
				var input = propsPanel.querySelector( '[data-key="' + key + '"]' );
				if ( input && document.activeElement !== input ) {
					input.value = item[ key ];
				}
			} );
			return;
		}
		propsPanel.innerHTML = '';
		propsPanel.appendChild( el( 'h3', { text: t.properties } ) );
		if ( ! item ) {
			propsPanel.appendChild( el( 'p', { class: 'description', text: t.noSelection } ) );
			return;
		}
		var grid = el( 'div', { class: 'wcvs-d-grid' } );
		grid.appendChild( field( t.name, textInput( item, 'name' ), true ) );

		if ( 'text' === item.type || 'heading' === item.type ) {
			var ta = textInput( item, 'text', true );
			grid.appendChild( field( t.content, ta, true ) );
			grid.appendChild( el( 'div', { class: 'wcvs-d-field is-wide' }, [ placeholderSelect( ta, item, 'text' ) ] ) );
			var fonts = {};
			( cfg.fonts || [] ).forEach( function ( f ) {
				fonts[ f ] = f;
			} );
			grid.appendChild( field( t.font, selectInput( item, 'font', fonts ) ) );
			grid.appendChild( field( t.size, numberInput( item, 'size', { step: 1, min: 4, max: 300, def: 12 } ) ) );
			grid.appendChild( field( t.color, colorInput( item, 'color' ) ) );
			grid.appendChild( field( t.fill, colorInput( item, 'fill', true ) ) );
			grid.appendChild( field( t.align, selectInput( item, 'align', { left: t.left, center: t.center, right: t.right, justify: t.justify } ) ) );
			grid.appendChild( field( t.valign, selectInput( item, 'valign', { top: t.top, middle: t.middle, bottom: t.bottom } ) ) );
			grid.appendChild( field( t.lineHeight, numberInput( item, 'lineHeight', { step: 0.05, min: 0.8, max: 3, def: 1.25 } ) ) );
			grid.appendChild( field( t.letterSpacing, numberInput( item, 'letterSpacing', { step: 0.1, min: -2, max: 20, def: 0 } ) ) );
			grid.appendChild( el( 'div', { class: 'wcvs-d-field is-wide wcvs-d-toggles' }, [
				toggle( item, 'bold', t.bold ), toggle( item, 'italic', t.italic ), toggle( item, 'underline', t.underline ), toggle( item, 'uppercase', t.uppercase )
			] ) );
		} else if ( 'image' === item.type ) {
			grid.appendChild( el( 'div', { class: 'wcvs-d-field is-wide' }, [
				el( 'button', { type: 'button', class: 'button', text: t.choose, onclick: function () {
					chooseImage( function ( att ) {
						item.imageId = att.id;
						item.src = att.url;
						commit( true );
						renderAll();
					} );
				} } )
			] ) );
			grid.appendChild( field( t.fit, selectInput( item, 'fit', { contain: t.contain, cover: t.cover, stretch: t.stretch } ) ) );
		} else if ( 'qrcode' === item.type ) {
			grid.appendChild( field( t.qrContent, selectInput( item, 'qrContent', { code: t.qrCode, checkin: t.qrCheckin, download: t.qrDownload, custom: t.qrCustom }, function () { renderProps(); } ), true ) );
			if ( 'custom' === item.qrContent ) {
				grid.appendChild( field( t.value, textInput( item, 'qrValue' ), true ) );
			}
			grid.appendChild( field( t.color, colorInput( item, 'color' ) ) );
		} else if ( 'barcode' === item.type ) {
			grid.appendChild( field( t.value, textInput( item, 'qrValue' ), true ) );
			grid.appendChild( field( t.barcodeType, selectInput( item, 'barcodeType', { C128: 'Code 128', C39: 'Code 39', EAN13: 'EAN-13' } ) ) );
			grid.appendChild( field( t.color, colorInput( item, 'color' ) ) );
			grid.appendChild( el( 'div', { class: 'wcvs-d-field is-wide' }, [ toggle( item, 'showText', t.showText ) ] ) );
		} else if ( 'rect' === item.type ) {
			grid.appendChild( field( t.fill, colorInput( item, 'fill', true ) ) );
			grid.appendChild( field( t.borderColor, colorInput( item, 'borderColor' ) ) );
			grid.appendChild( field( t.border, numberInput( item, 'borderWidth', { step: 0.1, min: 0, max: 20 } ) ) );
			grid.appendChild( field( t.radius, numberInput( item, 'radius', { step: 0.5, min: 0, max: 100 } ) ) );
		} else if ( 'line' === item.type ) {
			grid.appendChild( field( t.color, colorInput( item, 'borderColor' ) ) );
			grid.appendChild( field( t.border, numberInput( item, 'borderWidth', { step: 0.1, min: 0.1, max: 20, def: 0.5 } ) ) );
		}

		grid.appendChild( el( 'h4', { class: 'wcvs-d-sub', text: t.position } ) );
		grid.appendChild( field( 'X', numberInput( item, 'x' ) ) );
		grid.appendChild( field( 'Y', numberInput( item, 'y' ) ) );
		grid.appendChild( field( t.width, numberInput( item, 'w', { min: 0.5 } ) ) );
		if ( 'line' !== item.type ) {
			grid.appendChild( field( t.height, numberInput( item, 'h', { min: 0.5 } ) ) );
		}
		grid.appendChild( field( t.rotation, numberInput( item, 'rotation', { step: 1, min: -360, max: 360 } ) ) );
		grid.appendChild( field( t.opacity, numberInput( item, 'opacity', { step: 0.05, min: 0, max: 1, def: 1 } ) ) );
		grid.appendChild( el( 'div', { class: 'wcvs-d-field is-wide' }, [ el( 'span', { class: 'wcvs-d-label', text: t.alignPage } ), alignButtons( item ) ] ) );
		propsPanel.appendChild( grid );
		propsPanel.appendChild( el( 'p', { class: 'wcvs-d-actions' }, [
			el( 'button', { type: 'button', class: 'button', text: t.duplicate, onclick: duplicateSelected } ),
			el( 'button', { type: 'button', class: 'button button-link-delete', text: t['delete'], onclick: removeSelected } )
		] ) );
	}

	function renderLayers() {
		layersPanel.innerHTML = '';
		layersPanel.appendChild( el( 'h3', { text: t.layers } ) );
		var list = el( 'ul', { class: 'wcvs-d-layer-list' } );
		design.elements.slice().reverse().forEach( function ( item ) {
			var li = el( 'li', { class: item.id === state.selected ? 'is-selected' : '' } );
			li.appendChild( el( 'button', { type: 'button', class: 'wcvs-d-layer-name', 'aria-pressed': item.id === state.selected ? 'true' : 'false', onclick: function () {
				state.selected = item.id;
				renderAll();
			} }, [ el( 'span', { class: 'wcvs-d-layer-type', text: t[ item.type ] || item.type } ), ' ' + ( item.name || '' ) ] ) );
			li.appendChild( el( 'button', { type: 'button', class: 'wcvs-d-icon' + ( item.hidden ? ' is-off' : '' ), title: t.hide, 'aria-label': t.hide, 'aria-pressed': item.hidden ? 'true' : 'false', html: item.hidden ? '&#9711;' : '&#9673;', onclick: function () {
				item.hidden = ! item.hidden;
				commit( true );
				renderAll();
			} } ) );
			li.appendChild( el( 'button', { type: 'button', class: 'wcvs-d-icon' + ( item.locked ? ' is-on' : '' ), title: t.lock, 'aria-label': t.lock, 'aria-pressed': item.locked ? 'true' : 'false', html: item.locked ? '&#128274;' : '&#128275;', onclick: function () {
				item.locked = ! item.locked;
				commit( true );
				renderAll();
			} } ) );
			li.appendChild( el( 'button', { type: 'button', class: 'wcvs-d-icon', title: t.up, 'aria-label': t.up, html: '&#9650;', onclick: function () { moveLayer( item.id, 1 ); } } ) );
			li.appendChild( el( 'button', { type: 'button', class: 'wcvs-d-icon', title: t.down, 'aria-label': t.down, html: '&#9660;', onclick: function () { moveLayer( item.id, -1 ); } } ) );
			list.appendChild( li );
		} );
		layersPanel.appendChild( list );
	}

	function renderPagePanel() {
		var p = design.page;
		pagePanel.innerHTML = '';
		pagePanel.appendChild( el( 'h3', { text: t.page } ) );
		var grid = el( 'div', { class: 'wcvs-d-grid' } );
		var formats = {};
		Object.keys( cfg.formats ).forEach( function ( k ) {
			formats[ k ] = k + ' (' + cfg.formats[ k ][ 0 ] + '×' + cfg.formats[ k ][ 1 ] + ' mm)';
		} );
		formats.custom = t.custom;
		var rerender = function () {
			renderPagePanel();
			renderCanvas();
		};
		grid.appendChild( field( t.format, selectInput( p, 'format', formats, rerender ), true ) );
		if ( 'custom' === p.format ) {
			grid.appendChild( field( t.width, numberInput( p, 'width', { step: 1, min: 20, max: 1000, def: 148 } ) ) );
			grid.appendChild( field( t.height, numberInput( p, 'height', { step: 1, min: 20, max: 1000, def: 210 } ) ) );
		} else {
			grid.appendChild( field( t.orientation, selectInput( p, 'orientation', { portrait: t.portrait, landscape: t.landscape }, rerender ), true ) );
		}
		grid.appendChild( field( t.background, colorInput( p, 'background' ) ) );
		grid.appendChild( field( t.fit, selectInput( p, 'backgroundFit', { cover: t.cover, contain: t.contain, stretch: t.stretch } ) ) );
		var bgButtons = el( 'div', { class: 'wcvs-d-field is-wide' }, [
			el( 'span', { class: 'wcvs-d-label', text: t.bgImage } ),
			el( 'button', { type: 'button', class: 'button', text: t.choose, onclick: function () {
				chooseImage( function ( att ) {
					p.backgroundImageId = att.id;
					p.backgroundImage = att.url;
					commit( true );
					renderAll();
				} );
			} } ),
			p.backgroundImage ? el( 'button', { type: 'button', class: 'button button-link-delete', text: t.remove, onclick: function () {
				p.backgroundImageId = 0;
				p.backgroundImage = '';
				commit( true );
				renderAll();
			} } ) : null
		] );
		grid.appendChild( bgButtons );
		pagePanel.appendChild( grid );
	}

	function renderAll() {
		renderCanvas();
		renderProps();
		renderLayers();
		renderPagePanel();
		updateToolbar();
	}

	// ------------------------------------------------------------------ preview.

	function preview() {
		save();
		var form = el( 'form', { method: 'post', action: cfg.previewUrl, target: '_blank', style: 'display:none' } );
		form.appendChild( el( 'input', { type: 'hidden', name: 'nonce', value: cfg.previewNonce } ) );
		form.appendChild( el( 'input', { type: 'hidden', name: 'post_id', value: cfg.postId || 0 } ) );
		var ta = el( 'textarea', { name: 'design' } );
		ta.value = store.value;
		form.appendChild( ta );
		document.body.appendChild( form );
		form.submit();
		document.body.removeChild( form );
	}

	// ------------------------------------------------------------------ boot.

	buildToolbar();
	save();
	state.history.push( store.value );
	fitZoom();
	renderAll();
	window.addEventListener( 'resize', function () {
		if ( ! drag ) {
			fitZoom();
			renderCanvas();
			updateToolbar();
		}
	} );
} )();
