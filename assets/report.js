/**
 * Link Ahead – report under Tools → Link Ahead.
 *
 * Counts up the tiles once, links the ring chart with the tiles, filters the list by group and search term
 * without reloading, and shows the placeholders of one target when its dot on the timeline is chosen.
 * Everything works without this script; it only reads the page and sends nothing anywhere.
 * Texts: window.linkAheadReport.
 */
( function () {
	'use strict';

	var t = window.linkAheadReport || {};
	var wrap = document.querySelector( '.link-ahead-report' );
	if ( ! wrap ) {
		return;
	}
	var still = window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	var numbers = window.Intl && Intl.NumberFormat ? new Intl.NumberFormat( document.documentElement.lang || undefined ) : null;

	function fill( text, a, b ) {
		return String( text || '' ).replace( '%1$d', a ).replace( '%2$d', b ).replace( '%s', a );
	}

	// Tiles count up once (not with reduced motion); the final number is in the page.
	if ( ! still && window.requestAnimationFrame ) {
		Array.prototype.forEach.call( wrap.querySelectorAll( '[data-la-count]' ), function ( node ) {
			var target = parseInt( node.getAttribute( 'data-la-count' ), 10 );
			var final = node.textContent;
			if ( ! target || target < 3 ) {
				return;
			}
			var start = null;
			node.textContent = '0';
			function step( now ) {
				start = start || now;
				var p = Math.min( 1, ( now - start ) / 700 );
				var v = Math.round( target * ( 1 - Math.pow( 1 - p, 3 ) ) );
				node.textContent = p < 1 ? ( numbers ? numbers.format( v ) : String( v ) ) : final;
				if ( p < 1 ) {
					window.requestAnimationFrame( step );
				}
			}
			window.requestAnimationFrame( step );
		} );
		var ring = wrap.querySelector( '.link-ahead-ring' );
		if ( ring ) {
			ring.classList.add( 'is-drawing' );
		}
	}

	// Ring and tiles light up together.
	Array.prototype.forEach.call( wrap.querySelectorAll( '.link-ahead-ring-seg' ), function ( seg ) {
		var key = ( seg.getAttribute( 'class' ).match( /is-(linked|waiting|attention)/ ) || [] )[ 1 ];
		var tile = key ? wrap.querySelector( '.link-ahead-tile.is-' + key ) : null;
		function on() {
			seg.classList.add( 'is-active' );
			if ( tile ) {
				tile.classList.add( 'is-active' );
			}
		}
		function off() {
			seg.classList.remove( 'is-active' );
			if ( tile ) {
				tile.classList.remove( 'is-active' );
			}
		}
		seg.addEventListener( 'mouseenter', on );
		seg.addEventListener( 'focus', on );
		seg.addEventListener( 'mouseleave', off );
		seg.addEventListener( 'blur', off );
	} );

	var tools = wrap.querySelector( '[data-la-tools]' );
	var table = wrap.querySelector( '.link-ahead-table' );
	if ( ! tools || ! table ) {
		return;
	}
	var rows = table.querySelectorAll( 'tbody tr[data-la-group]' );
	var allHere = 'all' === tools.getAttribute( 'data-la-view-now' );
	var group = 'all';
	var target = '';
	var term = '';

	// Search field with "/" as shortcut, and the line that names the filter.
	var label = document.createElement( 'label' );
	label.className = 'link-ahead-search';
	var icon = document.createElement( 'span' );
	icon.className = 'dashicons dashicons-search';
	icon.setAttribute( 'aria-hidden', 'true' );
	var search = document.createElement( 'input' );
	search.type = 'search';
	search.placeholder = t.search || '';
	search.setAttribute( 'aria-label', t.search || '' );
	search.setAttribute( 'aria-keyshortcuts', '/' );
	search.autocomplete = 'off';
	var kbd = document.createElement( 'kbd' );
	kbd.className = 'link-ahead-kbd';
	kbd.setAttribute( 'aria-hidden', 'true' );
	kbd.textContent = '/';
	label.appendChild( icon );
	label.appendChild( search );
	label.appendChild( kbd );
	var status = document.createElement( 'p' );
	status.className = 'link-ahead-shown';
	status.setAttribute( 'role', 'status' );
	var only = document.createElement( 'p' );
	only.className = 'link-ahead-only';
	only.hidden = true;
	var onlyText = document.createElement( 'span' );
	var onlyReset = document.createElement( 'button' );
	onlyReset.type = 'button';
	onlyReset.className = 'button button-small';
	onlyReset.textContent = t.showAll || '';
	only.appendChild( onlyText );
	only.appendChild( onlyReset );
	var none = document.createElement( 'p' );
	none.className = 'link-ahead-none';
	none.textContent = t.none || '';
	none.hidden = true;
	tools.appendChild( label );
	tools.appendChild( status );
	tools.parentNode.insertBefore( only, tools.nextSibling );
	table.parentNode.insertBefore( none, table.nextSibling );
	tools.hidden = false;

	function unmark( cell ) {
		Array.prototype.forEach.call( cell.querySelectorAll( 'mark.link-ahead-hit' ), function ( m ) {
			m.parentNode.replaceChild( document.createTextNode( m.textContent ), m );
		} );
		cell.normalize();
	}
	function markIn( cell ) {
		var walker = document.createTreeWalker( cell, NodeFilter.SHOW_TEXT );
		var hits = [];
		while ( walker.nextNode() ) {
			if ( walker.currentNode.nodeValue.toLowerCase().indexOf( term ) >= 0 ) {
				hits.push( walker.currentNode );
			}
		}
		hits.forEach( function ( text ) {
			var hit = text.splitText( text.nodeValue.toLowerCase().indexOf( term ) );
			hit.splitText( term.length );
			var m = document.createElement( 'mark' );
			m.className = 'link-ahead-hit';
			m.textContent = hit.nodeValue;
			hit.parentNode.replaceChild( m, hit );
		} );
	}
	function apply() {
		var n = 0;
		Array.prototype.forEach.call( rows, function ( row ) {
			var cells = Array.prototype.slice.call( row.cells, 0, 3 );
			cells.forEach( unmark );
			var words = cells.map( function ( c ) {
				return c.textContent;
			} ).join( ' ' ).toLowerCase();
			var show = ( 'all' === group || row.getAttribute( 'data-la-group' ) === group ) &&
				( ! target || row.getAttribute( 'data-la-target' ) === target ) &&
				( ! term || words.indexOf( term ) >= 0 );
			row.hidden = ! show;
			n += show ? 1 : 0;
			if ( show && term ) {
				cells.forEach( markIn );
			}
		} );
		none.hidden = n > 0;
		table.hidden = 0 === n;
		status.textContent = term || target || 'all' !== group ? fill( t.shown, n, rows.length ) : '';
	}
	search.addEventListener( 'input', function () {
		term = search.value.trim().toLowerCase();
		apply();
	} );
	search.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' === event.key && search.value ) {
			event.preventDefault();
			search.value = '';
			term = '';
			apply();
		}
	} );
	document.addEventListener( 'keydown', function ( event ) {
		var el = event.target || {};
		if ( '/' === event.key && ! event.ctrlKey && ! event.metaKey && ! event.altKey && ! /^(INPUT|TEXTAREA|SELECT)$/.test( el.tagName || '' ) && ! el.isContentEditable ) {
			event.preventDefault();
			search.focus();
		}
	} );

	// The group links filter in place when the page shows all placeholders.
	if ( allHere ) {
		var views = wrap.querySelectorAll( '.subsubsub a[data-la-view]' );
		Array.prototype.forEach.call( views, function ( link ) {
			link.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				group = link.getAttribute( 'data-la-view' );
				Array.prototype.forEach.call( views, function ( other ) {
					var current = other === link;
					other.classList.toggle( 'current', current );
					if ( current ) {
						other.setAttribute( 'aria-current', 'page' );
					} else {
						other.removeAttribute( 'aria-current' );
					}
				} );
				if ( window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', link.href );
				}
				apply();
			} );
		} );
	}

	// A dot on the timeline shows the placeholders of its target.
	Array.prototype.forEach.call( wrap.querySelectorAll( 'a.link-ahead-dot[data-la-target]' ), function ( dot ) {
		dot.addEventListener( 'click', function ( event ) {
			var has = table.querySelector( 'tbody tr[data-la-target="' + dot.getAttribute( 'data-la-target' ) + '"]' );
			if ( ! has ) {
				return; // not in this view: the link jumps to the date instead
			}
			event.preventDefault();
			target = dot.getAttribute( 'data-la-target' );
			onlyText.textContent = fill( t.target, dot.getAttribute( 'data-la-title' ) ) + ' ';
			only.hidden = false;
			apply();
			only.scrollIntoView( { behavior: still ? 'auto' : 'smooth', block: 'start' } );
			onlyReset.focus( { preventScroll: true } );
		} );
	} );
	onlyReset.addEventListener( 'click', function () {
		target = '';
		only.hidden = true;
		apply();
		search.focus();
	} );
	apply();
}() );
