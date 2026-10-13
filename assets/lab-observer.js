/**
 * BLT Optimized lab observer, payload version 1.
 *
 * Printed inline as the first thing in <head> of the one page request the
 * built-in lab test measures (see class-blt-optimized-lab-mode.php), never on
 * a normal visit. It registers PerformanceObservers straight away so nothing
 * of the page load is missed, keeps the data in memory and hands it to the
 * admin page that framed the request through window.__bltLab:
 *
 *   __bltLab.version   1
 *   __bltLab.state()   { loaded, quietFor, lcpFinal }  polled by the admin page
 *   __bltLab.collect() the payload documented in SPEC-LAB.md
 *
 * Plain ES5, no dependencies. It never modifies the page and never throws
 * into it: every step is wrapped, and a step that fails leaves its slice of
 * the payload empty rather than losing the whole measurement.
 *
 * Framed in the admin page (the normal case), Chrome delivers paint, LCP,
 * layout-shift, long-task and resource entries to this frame's observers but
 * reports long animation frames only to the top-level page, so loaf[] is
 * empty here and the admin-side runner (assets/performance.js) fills it in
 * from the entries naming this frame's scripts. Opened as a top-level page,
 * the observer collects them itself. Other browsers lack some entry types;
 * collect() says which in warnings[].
 */
( function () {
	'use strict';

	try {
		var win = window;
		if ( win.__bltLab ) {
			return;
		}

		var doc = document;
		var perf = win.performance;
		var PO = win.PerformanceObserver;
		var VERSION = 1;
		var CSS_BUDGET = 800;

		var supported = [];
		try {
			supported = PO && PO.supportedEntryTypes ? PO.supportedEntryTypes : [];
		} catch ( e ) {}

		/* ---------------------------------------------------------------- */
		/* Small helpers                                                     */
		/* ---------------------------------------------------------------- */

		function now() {
			return perf && perf.now ? perf.now() : 0;
		}

		function r1( n ) {
			n = Number( n );
			return isFinite( n ) ? Math.round( n * 10 ) / 10 : 0;
		}

		function uint( n ) {
			n = Number( n );
			return isFinite( n ) && n > 0 ? Math.round( n ) : 0;
		}

		function trunc( s, n ) {
			s = String( null === s || undefined === s ? '' : s );
			return s.length > n ? s.slice( 0, n ) : s;
		}

		function safe( fn, fallback ) {
			try {
				return fn();
			} catch ( e ) {
				return fallback;
			}
		}

		function supports( type ) {
			return supported.indexOf( type ) > -1;
		}

		// Drops the lab query arguments so the payload names the page itself.
		function stripParams( url ) {
			var hash = '';
			var hashAt = url.indexOf( '#' );
			if ( hashAt > -1 ) {
				hash = url.slice( hashAt );
				url = url.slice( 0, hashAt );
			}
			var queryAt = url.indexOf( '?' );
			if ( queryAt < 0 ) {
				return url + hash;
			}
			var keep = [];
			var parts = url.slice( queryAt + 1 ).split( '&' );
			for ( var i = 0; i < parts.length; i++ ) {
				var name = parts[ i ].split( '=' )[ 0 ];
				if ( parts[ i ] && 'blt_optimized_lab' !== name && 'blt_lab' !== name ) {
					keep.push( parts[ i ] );
				}
			}
			return url.slice( 0, queryAt ) + ( keep.length ? '?' + keep.join( '&' ) : '' ) + hash;
		}

		function cleanUrl( url ) {
			url = String( url || '' );
			if ( /^(data|blob):/i.test( url ) ) {
				return trunc( url, 80 );
			}
			return trunc( safe( function () {
				return stripParams( url );
			}, url ), 2000 );
		}

		function cssEscape( text ) {
			try {
				if ( win.CSS && win.CSS.escape ) {
					return win.CSS.escape( text );
				}
			} catch ( e ) {}
			return String( text ).replace( /([^\w-])/g, '\\$1' );
		}

		function isUniqueId( el ) {
			var id = el.id;
			if ( ! id ) {
				return false;
			}
			try {
				return 1 === doc.querySelectorAll( '[id="' + id.replace( /["\\]/g, '\\$&' ) + '"]' ).length;
			} catch ( e ) {
				return false;
			}
		}

		function selectorPart( el ) {
			var out = el.tagName ? el.tagName.toLowerCase() : '';
			var classes = el.classList;
			var added = 0;
			for ( var i = 0; classes && i < classes.length && added < 3; i++ ) {
				if ( classes[ i ] ) {
					out += '.' + cssEscape( classes[ i ] );
					added++;
				}
			}
			return out;
		}

		// "#id" when the element's id is unique, else tag.class chains of the
		// element and up to two ancestors ("section > div.card > img.hero").
		function selectorOf( el ) {
			try {
				if ( ! el || 1 !== el.nodeType ) {
					return '';
				}
				if ( isUniqueId( el ) ) {
					return trunc( '#' + cssEscape( el.id ), 200 );
				}
				var parts = [];
				var cur = el;
				var depth = 0;
				while ( cur && 1 === cur.nodeType && depth < 3 && ( cur !== doc.documentElement || 0 === depth ) ) {
					if ( depth > 0 && isUniqueId( cur ) ) {
						parts.unshift( '#' + cssEscape( cur.id ) );
						break;
					}
					parts.unshift( selectorPart( cur ) );
					cur = cur.parentElement;
					depth++;
				}
				return trunc( parts.join( ' > ' ), 200 );
			} catch ( e ) {
				return '';
			}
		}

		// Bricks puts id="brxe-xxxxxx" on every element it renders.
		function bricksIdOf( el ) {
			try {
				var cur = el;
				for ( var i = 0; cur && 1 === cur.nodeType && i < 7; i++ ) {
					var id = cur.id;
					if ( id && 0 === id.indexOf( 'brxe-' ) ) {
						var rest = id.slice( 5 );
						return /^[A-Za-z0-9_-]{1,40}$/.test( rest ) ? rest : '';
					}
					cur = cur.parentElement;
				}
			} catch ( e ) {}
			return '';
		}

		function rectOf( el ) {
			try {
				return el.getBoundingClientRect();
			} catch ( e ) {
				return { top: 0, left: 0, right: 0, bottom: 0, width: 0, height: 0 };
			}
		}

		function rectArray( r ) {
			return r ? [ r1( r.x !== undefined ? r.x : r.left ), r1( r.y !== undefined ? r.y : r.top ), r1( r.width ), r1( r.height ) ] : [ 0, 0, 0, 0 ];
		}

		function topN( list, key, n ) {
			return list.slice().sort( function ( a, b ) {
				return b[ key ] - a[ key ];
			} ).slice( 0, n );
		}

		/* ---------------------------------------------------------------- */
		/* Live collection                                                   */
		/* ---------------------------------------------------------------- */

		var store = {
			fcp: null,
			lcp: null,
			lcpSnap: null,
			lcpTime: 0,
			last: 0, // end of the latest resource / long task / LCP / layout shift
			input: false,
			hidden: 'hidden' === doc.visibilityState,
			loadSeen: false,
			cls: 0,
			sessionValue: 0,
			sessionFirst: 0,
			sessionLast: 0,
			sessionOpen: false,
			shifts: [],
			tasks: [],
			frames: [],
			res: []
		};
		var observers = [];

		function touch( time ) {
			if ( time > store.last ) {
				store.last = time;
			}
		}

		// Everything about the LCP element that can be read from the node.
		function describeLcp( el, entry ) {
			var url = cleanUrl( entry.url || '' );
			var out = {
				time: r1( entry.startTime ),
				size: uint( entry.size ),
				url: url,
				tag: '',
				selector: '',
				id: trunc( entry.id || '', 100 ),
				bricksId: '',
				text: '',
				isImage: '' !== url,
				loading: '',
				fetchpriority: '',
				decoding: '',
				naturalW: 0,
				naturalH: 0,
				renderedW: 0,
				renderedH: 0,
				isBackground: false,
				resource: null
			};
			if ( el && 1 === el.nodeType ) {
				var tag = el.tagName.toLowerCase();
				var r = rectOf( el );
				out.tag = tag;
				out.selector = selectorOf( el );
				out.id = trunc( el.id || out.id, 100 );
				out.bricksId = bricksIdOf( el );
				out.loading = trunc( el.getAttribute( 'loading' ) || '', 20 );
				out.fetchpriority = trunc( el.getAttribute( 'fetchpriority' ) || '', 20 );
				out.decoding = trunc( el.getAttribute( 'decoding' ) || '', 20 );
				out.renderedW = r1( r.width );
				out.renderedH = r1( r.height );
				if ( 'img' === tag ) {
					out.naturalW = uint( el.naturalWidth );
					out.naturalH = uint( el.naturalHeight );
				} else if ( 'video' === tag ) {
					out.naturalW = uint( el.videoWidth );
					out.naturalH = uint( el.videoHeight );
				}
				if ( ! url ) {
					out.text = trunc( String( el.textContent || '' ).replace( /\s+/g, ' ' ).trim(), 80 );
				}
				out.isBackground = '' !== url && 'img' !== tag && 'video' !== tag && 'image' !== tag && 'input' !== tag;
			}
			return out;
		}

		function onPaint( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				if ( 'first-contentful-paint' === list[ i ].name && null === store.fcp ) {
					store.fcp = list[ i ].startTime;
				}
			}
		}

		function onLcp( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				var entry = list[ i ];
				store.lcp = entry;
				store.lcpTime = entry.startTime;
				// The element can be removed before collect(): keep what it was.
				store.lcpSnap = safe( function () {
					return describeLcp( entry.element, entry );
				}, null );
				touch( entry.startTime );
			}
		}

		// web-vitals' session windows: a window ends after a 1 s gap or 5 s total.
		function onShift( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				var entry = list[ i ];
				if ( entry.hadRecentInput ) {
					continue;
				}
				var time = entry.startTime;
				if ( store.sessionOpen && time - store.sessionLast < 1000 && time - store.sessionFirst < 5000 ) {
					store.sessionValue += entry.value;
					store.sessionLast = time;
				} else {
					store.sessionOpen = true;
					store.sessionValue = entry.value;
					store.sessionFirst = time;
					store.sessionLast = time;
				}
				if ( store.sessionValue > store.cls ) {
					store.cls = store.sessionValue;
				}

				var sources = [];
				var raw = entry.sources || [];
				for ( var j = 0; j < raw.length && j < 5; j++ ) {
					var node = raw[ j ].node;
					sources.push( {
						selector: selectorOf( node ),
						tag: node && node.tagName ? node.tagName.toLowerCase() : '',
						bricksId: bricksIdOf( node ),
						prevRect: rectArray( raw[ j ].previousRect ),
						currRect: rectArray( raw[ j ].currentRect )
					} );
				}
				store.shifts.push( { value: entry.value, time: r1( time ), sources: sources } );
				if ( store.shifts.length > 120 ) {
					store.shifts = topN( store.shifts, 'value', 60 );
				}
				touch( time );
			}
		}

		// A long task is reported to every frame sharing the thread (here: the
		// admin page that frames this one). entry.name says whose task it was;
		// only this frame's own count.
		function onTask( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				if ( 'self' === list[ i ].name ) {
					store.tasks.push( { start: list[ i ].startTime, duration: list[ i ].duration } );
					touch( list[ i ].startTime + list[ i ].duration );
				}
			}
		}

		function onFrame( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				var entry = list[ i ];
				var scripts = [];
				var raw = entry.scripts || [];
				for ( var j = 0; j < raw.length; j++ ) {
					scripts.push( {
						url: cleanUrl( raw[ j ].sourceURL ),
						fn: trunc( raw[ j ].sourceFunctionName || '', 200 ),
						invoker: /^https?:/i.test( raw[ j ].invoker || '' ) ? cleanUrl( raw[ j ].invoker ) : trunc( raw[ j ].invoker || '', 200 ),
						invokerType: trunc( raw[ j ].invokerType || '', 50 ),
						duration: r1( raw[ j ].duration ),
						forcedLayout: r1( raw[ j ].forcedStyleAndLayoutDuration )
					} );
				}
				store.frames.push( {
					start: r1( entry.startTime ),
					duration: r1( entry.duration ),
					blocking: r1( entry.blockingDuration ),
					scripts: topN( scripts, 'duration', 12 )
				} );
				if ( store.frames.length > 80 ) {
					store.frames = topN( store.frames, 'duration', 40 );
				}
			}
		}

		function onResource( list ) {
			for ( var i = 0; i < list.length; i++ ) {
				touch( list[ i ].responseEnd );
				if ( store.res.length < 1500 ) {
					store.res.push( list[ i ] );
				}
			}
		}

		function observe( type, handler ) {
			if ( ! PO || ! supports( type ) ) {
				return;
			}
			try {
				var observer = new PO( function ( list ) {
					try {
						handler( list.getEntries() );
					} catch ( e ) {}
				} );
				observer.observe( { type: type, buffered: true } );
				observers.push( { observer: observer, handler: handler } );
			} catch ( e ) {}
		}

		// Process what the browser has queued but not delivered yet.
		function flush() {
			for ( var i = 0; i < observers.length; i++ ) {
				try {
					var records = observers[ i ].observer.takeRecords();
					if ( records && records.length ) {
						observers[ i ].handler( records );
					}
				} catch ( e ) {}
			}
		}

		observe( 'paint', onPaint );
		observe( 'largest-contentful-paint', onLcp );
		observe( 'layout-shift', onShift );
		observe( 'longtask', onTask );
		observe( 'long-animation-frame', onFrame );
		observe( 'resource', onResource );

		try {
			doc.addEventListener( 'visibilitychange', function () {
				if ( 'hidden' === doc.visibilityState ) {
					store.hidden = true;
				}
			}, true );
			win.addEventListener( 'load', function () {
				store.loadSeen = true;
			}, false );
			// The browser stops reporting LCP candidates at the first input.
			var onInput = function () {
				store.input = true;
			};
			win.addEventListener( 'keydown', onInput, true );
			win.addEventListener( 'pointerdown', onInput, true );
			win.addEventListener( 'click', onInput, true );
		} catch ( e ) {}

		/* ---------------------------------------------------------------- */
		/* Payload sections                                                  */
		/* ---------------------------------------------------------------- */

		function navEntry() {
			var list = perf && perf.getEntriesByType ? perf.getEntriesByType( 'navigation' ) : [];
			return list && list.length ? list[ 0 ] : null;
		}

		function isLoaded() {
			var nav = navEntry();
			return !! ( nav && nav.loadEventEnd > 0 ) || ( 'complete' === doc.readyState && store.loadSeen );
		}

		function pageUrl() {
			return trunc( stripParams( String( win.location.href ) ), 2000 );
		}

		function viewportInfo() {
			return { w: uint( win.innerWidth ), h: uint( win.innerHeight ), dpr: Math.round( ( win.devicePixelRatio || 1 ) * 100 ) / 100 };
		}

		function navInfo() {
			var nav = navEntry() || {};
			if ( null === store.fcp ) {
				var paints = perf.getEntriesByName ? perf.getEntriesByName( 'first-contentful-paint' ) : [];
				if ( paints && paints.length ) {
					store.fcp = paints[ 0 ].startTime;
				}
			}
			return {
				ttfb: r1( nav.responseStart ),
				fcp: null === store.fcp ? null : r1( store.fcp ),
				domInteractive: r1( nav.domInteractive ),
				dcl: r1( nav.domContentLoadedEventEnd ),
				load: r1( nav.loadEventEnd ),
				transfer: uint( nav.transferSize ),
				encoded: uint( nav.encodedBodySize ),
				decoded: uint( nav.decodedBodySize ),
				protocol: trunc( nav.nextHopProtocol || '', 20 )
			};
		}

		// Resource Timing entries, those still in the browser's buffer plus any
		// the observer saw after the 250-entry buffer filled up.
		function resourceList() {
			var seen = {};
			var entries = [];
			var buffered = perf && perf.getEntriesByType ? perf.getEntriesByType( 'resource' ) : [];
			var all = Array.prototype.slice.call( buffered ).concat( store.res );
			for ( var i = 0; i < all.length; i++ ) {
				var key = all[ i ].name + '|' + all[ i ].startTime;
				if ( ! seen[ key ] ) {
					seen[ key ] = true;
					entries.push( all[ i ] );
				}
			}
			entries.sort( function ( a, b ) {
				return a.startTime - b.startTime;
			} );
			return entries.map( function ( e ) {
				var transfer = uint( e.transferSize );
				var encoded = uint( e.encodedBodySize );
				return {
					url: cleanUrl( e.name ),
					type: trunc( e.initiatorType || '', 40 ),
					transfer: transfer,
					encoded: encoded,
					decoded: uint( e.decodedBodySize ),
					start: r1( e.startTime ),
					end: r1( e.responseEnd ),
					blocking: 'blocking' === e.renderBlockingStatus,
					cached: 0 === transfer && encoded > 0
				};
			} );
		}

		function lcpInfo( resources ) {
			if ( ! store.lcp ) {
				return null;
			}
			var entry = store.lcp;
			var el = entry.element;
			var live = el && 1 === el.nodeType && ( ! doc.contains || doc.contains( el ) );
			var out = live ? describeLcp( el, entry ) : ( store.lcpSnap || describeLcp( null, entry ) );
			if ( out.url ) {
				for ( var i = 0; i < resources.length; i++ ) {
					if ( resources[ i ].url === out.url ) {
						var blocking = 0;
						for ( var j = 0; j < resources.length; j++ ) {
							if ( resources[ j ].blocking && resources[ j ].end <= out.time ) {
								blocking++;
							}
						}
						out.resource = { start: resources[ i ].start, end: resources[ i ].end, renderBlockingBefore: blocking };
						break;
					}
				}
			}
			return out;
		}

		function clsInfo() {
			var shifts = topN( store.shifts, 'value', 10 ).map( function ( shift ) {
				return { value: Math.round( shift.value * 10000 ) / 10000, time: shift.time, sources: shift.sources };
			} );
			return { value: Math.round( store.cls * 10000 ) / 10000, shifts: shifts };
		}

		// Blocking time past 50 ms of every long task, from first contentful
		// paint on (a task straddling FCP counts the part after it).
		function tbtInfo() {
			var from = null === store.fcp ? 0 : store.fcp;
			var value = 0;
			var counted = [];
			for ( var i = 0; i < store.tasks.length; i++ ) {
				var task = store.tasks[ i ];
				var span = task.start + task.duration - Math.max( task.start, from );
				if ( span > 50 ) {
					value += span - 50;
					counted.push( { start: r1( task.start ), duration: r1( task.duration ) } );
				}
			}
			return { value: r1( value ), longTasks: topN( counted, 'duration', 15 ), count: counted.length };
		}

		function loafInfo() {
			return topN( store.frames, 'duration', 20 );
		}

		/* --- Unused CSS --------------------------------------------------- */

		var PSEUDO_ELEMENTS = /::?(?:before|after|first-line|first-letter)(?![\w-])|::[\w-]+(?:\([^)]*\))?/gi;
		var PSEUDO_DYNAMIC = /:(?:hover|focus|focus-visible|focus-within|active|visited|target|link|any-link)(?![\w-])/gi;

		// Split a selector list on top-level commas only.
		function splitSelectors( text ) {
			var out = [];
			var cur = '';
			var depth = 0;
			var quote = '';
			for ( var i = 0; i < text.length; i++ ) {
				var ch = text.charAt( i );
				if ( '\\' === ch ) {
					cur += ch + text.charAt( ++i );
					continue;
				}
				if ( quote ) {
					cur += ch;
					if ( ch === quote ) {
						quote = '';
					}
					continue;
				}
				if ( '"' === ch || '\'' === ch ) {
					quote = ch;
				} else if ( '(' === ch || '[' === ch ) {
					depth++;
				} else if ( ')' === ch || ']' === ch ) {
					depth--;
				} else if ( ',' === ch && 0 === depth ) {
					out.push( cur );
					cur = '';
					continue;
				}
				cur += ch;
			}
			out.push( cur );
			return out;
		}

		// A selector without the parts that depend on state the page has not
		// been put in (hover, focus) or that name no element (::before).
		function prepareSelector( selector ) {
			var cleaned = selector.replace( PSEUDO_ELEMENTS, '' ).replace( PSEUDO_DYNAMIC, '' ).replace( /^\s+|\s+$/g, '' );
			if ( ! cleaned || /:host|:state\(/i.test( cleaned ) ) {
				return '';
			}
			return /[>+~]$/.test( cleaned ) ? cleaned + ' *' : cleaned;
		}

		var matchCache = Object.create ? Object.create( null ) : {};

		function selectorMatches( text ) {
			var parts = text.indexOf( ',' ) > -1 ? splitSelectors( text ) : [ text ];
			for ( var i = 0; i < parts.length; i++ ) {
				var selector = prepareSelector( parts[ i ] );
				if ( ! selector ) {
					return true;
				}
				var hit = matchCache[ selector ];
				if ( undefined === hit ) {
					try {
						hit = !! doc.querySelector( selector );
					} catch ( e ) {
						hit = true; // not parseable here: assume it is used
					}
					matchCache[ selector ] = hit;
				}
				if ( hit ) {
					return true;
				}
			}
			return false;
		}

		function mediaMatches( condition ) {
			try {
				return ! condition || win.matchMedia( condition ).matches;
			} catch ( e ) {
				return true;
			}
		}

		function walkRules( rules, entry, run, otherMedia ) {
			for ( var i = 0; i < rules.length; i++ ) {
				var rule = rules[ i ];
				var type = rule.type;

				if ( 0 === ( ++run.count & 63 ) && now() - run.start > CSS_BUDGET ) {
					run.over = true;
				}

				if ( 4 === type ) { // @media
					var inner = rule.cssRules;
					if ( inner ) {
						walkRules( inner, entry, run, otherMedia || ! mediaMatches( rule.conditionText || ( rule.media && rule.media.mediaText ) ) );
					}
					continue;
				}
				if ( 3 === type ) { // @import: its rules belong to this sheet
					var sheet = rule.styleSheet;
					var imported = null;
					try {
						imported = sheet ? sheet.cssRules : null;
					} catch ( e ) {}
					if ( imported ) {
						walkRules( imported, entry, run, otherMedia );
					}
					continue;
				}
				if ( 1 !== type && 7 !== type && rule.cssRules && ! rule.selectorText ) {
					// @supports, @layer, @container, @scope...: look inside.
					walkRules( rule.cssRules, entry, run, otherMedia );
					continue;
				}

				var length = rule.cssText ? rule.cssText.length : 0;
				entry.rules++;
				entry.bytes += length;
				if ( otherMedia ) {
					entry.otherMediaRules++;
				}
				if ( 5 === type ) { // @font-face
					continue;
				}
				if ( 1 === type && ! otherMedia && ! run.over && rule.selectorText ) {
					// A style rule carrying nested rules is judged by its own selector.
					if ( ! selectorMatches( rule.selectorText ) ) {
						entry.unusedRules++;
						entry.unusedBytes += length;
					}
				}
			}
		}

		function cssInfo( resources ) {
			var out = [];
			var sheets = doc.styleSheets;
			var run = { start: now(), count: 0, over: false };
			var decodedByUrl = {};
			for ( var r = 0; r < resources.length; r++ ) {
				decodedByUrl[ resources[ r ].url ] = resources[ r ].decoded || resources[ r ].encoded;
			}

			for ( var i = 0; sheets && i < sheets.length && out.length < 100; i++ ) {
				var sheet = sheets[ i ];
				var href = cleanUrl( sheet.href || '' );
				var owner = sheet.ownerNode;
				var entry = {
					href: href,
					inlineId: ! href && owner && owner.id ? trunc( owner.id, 100 ) : '',
					bytes: 0,
					rules: 0,
					unusedRules: 0,
					unusedBytes: 0,
					otherMediaRules: 0,
					accessible: true
				};
				var rules = null;
				try {
					rules = sheet.cssRules;
				} catch ( e ) {}
				if ( ! rules ) {
					// Cross-origin without CORS: only its transfer size is known.
					entry.accessible = false;
					entry.bytes = uint( decodedByUrl[ href ] );
					out.push( entry );
					continue;
				}
				var media = '';
				try {
					media = sheet.media ? sheet.media.mediaText : '';
				} catch ( e ) {}
				walkRules( rules, entry, run, ! mediaMatches( media ) );
				out.push( entry );
			}
			cssInfo.truncated = run.over;
			return out;
		}

		/* --- Images, fonts, DOM ------------------------------------------- */

		function imagesInfo() {
			var out = [];
			var list = doc.images || [];
			var vw = win.innerWidth || 0;
			var vh = win.innerHeight || 0;
			var scrollY = win.pageYOffset || 0;
			for ( var i = 0; i < list.length && out.length < 150; i++ ) {
				var img = list[ i ];
				var src = img.currentSrc || img.src || '';
				if ( ! src ) {
					continue;
				}
				var r = rectOf( img );
				var parent = img.parentNode;
				out.push( {
					src: cleanUrl( src ),
					selector: selectorOf( img ),
					bricksId: bricksIdOf( img ),
					naturalW: uint( img.naturalWidth ),
					naturalH: uint( img.naturalHeight ),
					renderedW: r1( r.width ),
					renderedH: r1( r.height ),
					top: r1( r.top + scrollY ),
					inInitialViewport: r.width > 0 && r.height > 0 && r.bottom > 0 && r.top < vh && r.right > 0 && r.left < vw,
					loading: trunc( img.getAttribute( 'loading' ) || '', 20 ),
					fetchpriority: trunc( img.getAttribute( 'fetchpriority' ) || '', 20 ),
					decoding: trunc( img.getAttribute( 'decoding' ) || '', 20 ),
					hasSrcset: img.hasAttribute( 'srcset' ) || !! ( parent && 'PICTURE' === parent.tagName && parent.querySelector( 'source[srcset]' ) ),
					sizes: trunc( img.getAttribute( 'sizes' ) || '', 200 ),
					alt: img.hasAttribute( 'alt' ),
					complete: !! img.complete
				} );
			}
			return out;
		}

		// Faces the page actually used (loaded or loading), not every @font-face.
		function fontsInfo() {
			var out = [];
			if ( ! doc.fonts || ! doc.fonts.forEach ) {
				return out;
			}
			doc.fonts.forEach( function ( face ) {
				if ( out.length >= 40 || 'unloaded' === face.status ) {
					return;
				}
				out.push( {
					family: trunc( String( face.family ).replace( /^["']|["']$/g, '' ), 100 ),
					weight: trunc( face.weight, 20 ),
					style: trunc( face.style, 20 ),
					status: trunc( face.status, 20 ),
					display: trunc( face.display || '', 20 )
				} );
			} );
			return out;
		}

		function domInfo() {
			var nodes = doc.getElementsByTagName( '*' ).length;
			var node = doc.documentElement;
			var depth = 1;
			var max = 0;
			while ( node ) {
				if ( depth > max ) {
					max = depth;
				}
				if ( node.firstElementChild ) {
					node = node.firstElementChild;
					depth++;
					continue;
				}
				while ( node && ! node.nextElementSibling ) {
					node = node.parentElement;
					depth--;
				}
				node = node ? node.nextElementSibling : null;
			}
			return { nodes: nodes, depth: max };
		}

		/* ---------------------------------------------------------------- */
		/* Public API                                                        */
		/* ---------------------------------------------------------------- */

		function state() {
			flush();
			var loaded = safe( isLoaded, false );
			var time = now();
			var nav = safe( navEntry, null );
			var reference = store.lcpTime || ( nav ? nav.loadEventEnd : 0 );
			return {
				loaded: loaded,
				quietFor: r1( Math.max( 0, time - store.last ) ),
				lcpFinal: store.input || ( loaded && time - reference >= 2000 )
			};
		}

		function collect() {
			flush();
			var resources = safe( resourceList, [] );
			var css = safe( function () {
				return cssInfo( resources );
			}, [] );
			var lcp = safe( function () {
				return lcpInfo( resources );
			}, null );

			var warnings = [];
			if ( store.hidden ) {
				warnings.push( 'hidden_tab' );
			}
			if ( ! lcp ) {
				warnings.push( 'no_lcp' );
			}
			if ( ! supports( 'long-animation-frame' ) ) {
				warnings.push( 'no_loaf' );
			}
			if ( ! supports( 'longtask' ) ) {
				warnings.push( 'no_longtask' );
			}
			if ( cssInfo.truncated ) {
				warnings.push( 'css_truncated' );
			}

			return {
				version: VERSION,
				url: safe( pageUrl, '' ),
				viewport: safe( viewportInfo, { w: 0, h: 0, dpr: 1 } ),
				nav: safe( navInfo, { ttfb: 0, fcp: null, domInteractive: 0, dcl: 0, load: 0, transfer: 0, encoded: 0, decoded: 0, protocol: '' } ),
				lcp: lcp,
				cls: safe( clsInfo, { value: 0, shifts: [] } ),
				tbt: safe( tbtInfo, { value: 0, longTasks: [], count: 0 } ),
				loaf: safe( loafInfo, [] ),
				resources: resources.slice( 0, 300 ),
				css: css,
				images: safe( imagesInfo, [] ),
				fonts: safe( fontsInfo, [] ),
				dom: safe( domInfo, { nodes: 0, depth: 0 } ),
				collectedAt: r1( now() ),
				warnings: warnings
			};
		}

		win.__bltLab = { version: VERSION, state: state, collect: collect };
	} catch ( e ) {
		// Never let the measurement break the page being measured.
	}
}() );
