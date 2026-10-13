/**
 * BLT Optimized performance UI: the Performance Scan, Page Profiler and Speed
 * Tweaks screens.
 *
 * Modules (same shape as admin.js): util (escaping + formatters), findings
 * (the one finding-row renderer the scan and the profiler share), psi
 * (PageSpeed Insights block), labRunner (the built-in lab test: runs a page in a
 * visible device frame and reads the browser's own performance APIs) with
 * labui (its results view), scan, profiler, tweaks.
 *
 * Everything inserted into the DOM goes through escapeHtml(); URLs only reach
 * an href after safeUrl() has accepted them.
 */
/* global bltPerf, bltOptimized, jQuery */
( function ( $ ) {
	'use strict';

	if ( 'undefined' === typeof bltPerf || 'undefined' === typeof bltOptimized ) {
		return;
	}

	var i18n = bltPerf.i18n || {};
	var DASH = '—';

	var state = {
		backupAcked: !! bltPerf.backupAcked
	};

	var api = {
		post: function ( action, data ) {
			return $.post(
				bltOptimized.ajaxUrl,
				$.extend( { action: 'blt_optimized_' + action, nonce: bltOptimized.nonce }, data || {} )
			);
		}
	};

	/* ------------------------------------------------------------------ */
	/* Helpers                                                             */
	/* ------------------------------------------------------------------ */

	var ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', '\'': '&#39;' };

	// Escapes quotes too (admin.js's jQuery-text version leaves them alone,
	// which is not safe inside an attribute).
	function escapeHtml( text ) {
		return String( null === text || undefined === text ? '' : text ).replace( /[&<>"']/g, function ( ch ) {
			return ESCAPES[ ch ];
		} );
	}

	// Translated string; the key itself shows when one is missing so a gap is visible.
	function t( key ) {
		return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : key;
	}

	// %s / %d / %1$s substitution. Returns plain text: escape before use in HTML.
	function sprintf( format ) {
		var args = Array.prototype.slice.call( arguments, 1 );
		var next = 0;
		return String( format ).replace( /%(?:(\d+)\$)?([sd%])/g, function ( match, position, type ) {
			if ( '%' === type ) {
				return '%';
			}
			var value = args[ position ? parseInt( position, 10 ) - 1 : next++ ];
			return undefined === value || null === value ? '' : value;
		} );
	}

	function tf( key ) {
		var args = Array.prototype.slice.call( arguments );
		args[ 0 ] = t( key );
		return sprintf.apply( null, args );
	}

	function plural( count, oneKey, manyKey ) {
		return tf( 1 === Number( count ) ? oneKey : manyKey, num( count ) );
	}

	function trim( value ) {
		return String( null === value || undefined === value ? '' : value ).replace( /^\s+|\s+$/g, '' );
	}

	function isNumber( value ) {
		return null !== value && undefined !== value && '' !== value && isFinite( Number( value ) );
	}

	function num( value ) {
		return isNumber( value ) ? Number( value ).toLocaleString() : DASH;
	}

	function ms( value ) {
		if ( ! isNumber( value ) ) {
			return DASH;
		}
		var n = Number( value );
		if ( n >= 1000 ) {
			return ( Math.round( n / 10 ) / 100 ) + ' s';
		}
		return ( n >= 10 ? Math.round( n ) : Math.round( n * 10 ) / 10 ) + ' ms';
	}

	function bytes( value ) {
		if ( ! isNumber( value ) || Number( value ) < 0 ) {
			return DASH;
		}
		var n = Number( value );
		var units = [ 'B', 'KB', 'MB', 'GB' ];
		var i = 0;
		while ( n >= 1024 && i < units.length - 1 ) {
			n /= 1024;
			i++;
		}
		if ( ! i ) {
			return Math.round( n ) + ' B';
		}
		return ( n >= 100 ? Math.round( n ) : Math.round( n * 10 ) / 10 ) + ' ' + units[ i ];
	}

	function fmtDate( timestamp ) {
		return timestamp ? new Date( Number( timestamp ) * 1000 ).toLocaleString() : DASH;
	}

	function fmtDay( timestamp ) {
		return timestamp ? new Date( Number( timestamp ) * 1000 ).toLocaleDateString() : DASH;
	}

	// WordPress returns wp_nonce_url() HTML-escaped; JSON carries that through.
	function decodeAmp( url ) {
		return String( url || '' ).replace( /&#0?38;|&amp;/g, '&' );
	}

	// http(s) URLs, root-relative paths and wp-admin pages only.
	function safeUrl( url ) {
		url = trim( url );
		if ( /[\u0000-\u001f]/.test( url ) ) {
			return '';
		}
		return /^(https?:\/\/|\/(?![\/\\])|[a-z0-9_-]+\.php(\?|$))/i.test( url ) ? url : '';
	}

	function link( url, label, newTab ) {
		var safe = safeUrl( url );
		if ( ! safe ) {
			return escapeHtml( label );
		}
		return '<a href="' + escapeHtml( safe ) + '"' + ( newTab ? ' target="_blank" rel="noopener noreferrer"' : '' ) + '>' + escapeHtml( label ) + '</a>';
	}

	// Last path segment of a URL (for compact asset/image names).
	function fileName( url ) {
		var clean = String( url || '' ).split( '#' )[ 0 ].split( '?' )[ 0 ];
		var parts = clean.split( '/' );
		return parts[ parts.length - 1 ] || clean;
	}

	function hostOf( url ) {
		var match = /^https?:\/\/([^\/?#]+)/i.exec( String( url || '' ) );
		return match ? match[ 1 ].toLowerCase() : '';
	}

	function sameUrl( a, b ) {
		function norm( url ) {
			return String( url || '' ).replace( /#.*$/, '' ).replace( /\/+$/, '' ).toLowerCase();
		}
		return norm( a ) === norm( b );
	}

	function queryParam( name ) {
		var match = new RegExp( '[?&]' + name + '=([^&#]*)' ).exec( window.location.search );
		if ( ! match ) {
			return '';
		}
		try {
			return decodeURIComponent( match[ 1 ].replace( /\+/g, ' ' ) );
		} catch ( e ) {
			return '';
		}
	}

	function compLabel( component ) {
		if ( ! component || 'object' !== typeof component ) {
			return '';
		}
		var name = component.name || component.slug || '?';
		var suffixes = {
			plugin: t( 'compPlugin' ),
			'mu-plugin': t( 'compMuPlugin' ),
			theme: t( 'compTheme' ),
			dropin: t( 'compDropin' ),
			external: t( 'compExternal' )
		};
		var suffix = suffixes[ component.type ] || '';
		return suffix ? name + ' (' + suffix + ')' : name;
	}

	function compKey( component ) {
		component = component && 'object' === typeof component ? component : {};
		return ( component.type || 'other' ) + ':' + ( component.slug || 'unknown' );
	}

	function compChip( component ) {
		var label = compLabel( component );
		return label ? '<span class="blt-chip blt-perf-comp" title="' + escapeHtml( label ) + '">' + escapeHtml( label ) + '</span>' : '';
	}

	function badge( label, cls ) {
		return '<span class="blt-badge' + ( cls ? ' ' + cls : '' ) + '">' + escapeHtml( label ) + '</span>';
	}

	function scoreClass( score ) {
		return score >= 90 ? 'is-good' : ( score >= 50 ? 'is-warn' : 'is-bad' );
	}

	// Score gauge: an SVG ring whose stroke length is the score.
	function ring( score, size ) {
		score = Math.max( 0, Math.min( 100, Math.round( Number( score ) || 0 ) ) );
		var circumference = 2 * Math.PI * 52;
		var offset = circumference * ( 1 - score / 100 );
		return '<svg class="blt-ring ' + scoreClass( score ) + ( size ? ' blt-ring-' + size : '' ) + '" viewBox="0 0 120 120" role="img" aria-label="' + escapeHtml( tf( 'scoreLabel', score ) ) + '">' +
			'<circle class="blt-ring-track" cx="60" cy="60" r="52"></circle>' +
			'<circle class="blt-ring-fill" cx="60" cy="60" r="52" transform="rotate(-90 60 60)" stroke-dasharray="' + circumference.toFixed( 2 ) + '" stroke-dashoffset="' + offset.toFixed( 2 ) + '"></circle>' +
			'<text class="blt-ring-value" x="60" y="60" text-anchor="middle" dominant-baseline="central">' + score + '</text></svg>';
	}

	function statTile( label, value, sub, cls ) {
		return '<div class="blt-stat' + ( cls ? ' ' + cls : '' ) + '"><span class="blt-stat-label">' + escapeHtml( label ) + '</span>' +
			'<span class="blt-stat-value">' + escapeHtml( value ) + '</span>' +
			( sub ? '<span class="blt-stat-sub">' + escapeHtml( sub ) + '</span>' : '' ) + '</div>';
	}

	function callout( title, body, cls ) {
		return '<div class="blt-callout' + ( cls ? ' ' + cls : '' ) + '" role="status">' +
			( title ? '<strong>' + escapeHtml( title ) + '</strong>' : '' ) +
			( body ? '<span>' + escapeHtml( body ) + '</span>' : '' ) + '</div>';
	}

	function emptyState( title, text ) {
		return '<div class="blt-empty"><span class="blt-empty-title">' + escapeHtml( title ) + '</span>' +
			( text ? '<span>' + escapeHtml( text ) + '</span>' : '' ) + '</div>';
	}

	/**
	 * Table markup. headers: strings or { label, cls }. rows: arrays of cells,
	 * or { cells, cls }. A cell is an HTML string (already escaped) or { html, cls }.
	 * `flush` drops the border so the table can run edge to edge inside a card.
	 */
	function tableHtml( headers, rows, cls, flush ) {
		var html = '<div class="blt-table-scroll' + ( flush ? ' is-flush' : '' ) + '"><table class="widefat striped blt-perf-table' + ( cls ? ' ' + cls : '' ) + '"><thead><tr>';
		headers = headers.map( function ( header ) {
			return 'object' === typeof header ? header : { label: header };
		} );
		headers.forEach( function ( header ) {
			// An icon/action column still needs a name for screen readers.
			html += '<th scope="col"' + ( header.cls ? ' class="' + header.cls + '"' : '' ) + ( header.title ? ' title="' + escapeHtml( header.title ) + '"' : '' ) + '>' +
				( header.label ? escapeHtml( header.label ) : '<span class="screen-reader-text">' + escapeHtml( header.sr || t( 'colActions' ) ) + '</span>' ) + '</th>';
		} );
		html += '</tr></thead><tbody>';
		rows.forEach( function ( row ) {
			var cells = Array.isArray( row ) ? row : row.cells;
			html += '<tr' + ( ! Array.isArray( row ) && row.cls ? ' class="' + row.cls + '"' : '' ) + '>';
			cells.forEach( function ( cell, index ) {
				cell = cell && 'object' === typeof cell ? cell : { html: cell };
				var cellCls = cell.cls || ( headers[ index ] && headers[ index ].cls ) || '';
				html += '<td' + ( cellCls ? ' class="' + cellCls + '"' : '' ) + '>' + ( undefined === cell.html || null === cell.html ? '' : cell.html ) + '</td>';
			} );
			html += '</tr>';
		} );
		return html + '</tbody></table></div>';
	}

	// Two-line cell: a main value with a muted second line.
	function twoLine( main, sub ) {
		return '<span class="blt-perf-main">' + escapeHtml( main ) + '</span>' +
			( sub ? '<span class="blt-perf-sub">' + escapeHtml( sub ) + '</span>' : '' );
	}

	// Horizontal bar whose width is a computed percentage.
	function barHtml( value, max, label ) {
		var pct = max > 0 ? Math.max( value > 0 ? 2 : 0, Math.min( 100, ( 100 * value ) / max ) ) : 0;
		return '<span class="blt-perf-bar" aria-hidden="true"><span class="blt-perf-bar-fill" style="width:' + pct.toFixed( 1 ) + '%"></span></span>' +
			'<span class="blt-perf-bar-value">' + escapeHtml( label ) + '</span>';
	}

	function sqlCell( sql ) {
		sql = String( sql || '' ).replace( /\s+/g, ' ' );
		if ( sql.length <= 120 ) {
			return '<code class="blt-sql">' + escapeHtml( sql ) + '</code>';
		}
		return '<details class="blt-sql-details"><summary><code class="blt-sql">' + escapeHtml( sql.substr( 0, 110 ) ) + '…</code></summary>' +
			'<code class="blt-code">' + escapeHtml( sql ) + '</code></details>';
	}

	function sumBy( list, field ) {
		var total = 0;
		( list || [] ).forEach( function ( item ) {
			total += Number( item[ field ] ) || 0;
		} );
		return total;
	}

	/* ------------------------------------------------------------------ */
	/* Findings: the one renderer both screens share                       */
	/* ------------------------------------------------------------------ */

	var SEVERITY_BADGE = {
		critical: 'blt-badge-cancelled',
		warning: 'blt-badge-pending',
		notice: '',
		pass: 'blt-badge-confirmed'
	};

	var WHERE_LIMIT = 6;

	// Fallback category names for lists that did not come with the report payload.
	var categoryLabels = {
		server: t( 'cat_server' ),
		database: t( 'cat_database' ),
		assets: t( 'cat_assets' ),
		content: t( 'cat_content' ),
		storage: t( 'cat_storage' )
	};

	var findings = {
		uid: 0,

		/**
		 * Render a list of findings into $mount.
		 *
		 * ctx: {
		 *   list, dismissed[], categories{}, canDismiss, filter{category,severity,dismissed},
		 *   fixedLabel, onDismiss(key, dismissed, $button), onRender(counts)
		 * }
		 * Expanded rows and applied fixes survive a re-mount of the same $mount.
		 */
		mount: function ( $mount, ctx ) {
			var previous = $mount.data( 'bltCtx' );
			ctx.open = ctx.open || ( previous ? previous.open : {} );
			ctx.fixed = ctx.fixed || ( previous ? previous.fixed : {} );
			ctx.passOpen = previous ? previous.passOpen : false;
			ctx.filter = ctx.filter || { category: '', severity: '', dismissed: false };
			$mount.data( 'bltCtx', ctx );

			if ( ! $mount.data( 'bltBound' ) ) {
				$mount.data( 'bltBound', true );
				findings.bind( $mount );
			}
			findings.render( $mount );
		},

		bind: function ( $mount ) {
			$mount.on( 'click', '.blt-finding-head', function () {
				var ctx = $mount.data( 'bltCtx' );
				var $row = $( this ).closest( '.blt-finding' );
				var open = 'true' === $( this ).attr( 'aria-expanded' );
				$( this ).attr( 'aria-expanded', open ? 'false' : 'true' );
				$row.toggleClass( 'is-open', ! open ).children( '.blt-finding-body' ).prop( 'hidden', open );
				ctx.open[ $row.attr( 'data-key' ) ] = ! open;
			} );

			$mount.on( 'click', '.blt-finding-pass-toggle', function () {
				var ctx = $mount.data( 'bltCtx' );
				var open = 'true' === $( this ).attr( 'aria-expanded' );
				$( this ).attr( 'aria-expanded', open ? 'false' : 'true' );
				$( this ).siblings( '.blt-finding-list' ).prop( 'hidden', open );
				ctx.passOpen = ! open;
			} );

			$mount.on( 'click', '.blt-finding-more', function () {
				$( this ).closest( 'ul' ).find( '.is-extra' ).prop( 'hidden', false );
				$( this ).closest( 'li' ).remove();
			} );

			$mount.on( 'click', '.blt-finding-apply', function () {
				findings.apply( $mount, $( this ) );
			} );

			$mount.on( 'click', '.blt-finding-dismiss', function () {
				var ctx = $mount.data( 'bltCtx' );
				if ( ctx.onDismiss ) {
					ctx.onDismiss( $( this ).closest( '.blt-finding' ).attr( 'data-key' ), '1' === $( this ).attr( 'data-dismiss' ), $( this ) );
				}
			} );
		},

		render: function ( $mount ) {
			var ctx = $mount.data( 'bltCtx' );
			var filter = ctx.filter;
			var dismissedMap = {};
			( ctx.dismissed || [] ).forEach( function ( key ) {
				dismissedMap[ key ] = true;
			} );
			ctx.dismissedMap = dismissedMap;
			ctx.byKey = {};

			var active = [];
			var passes = [];
			var hiddenDismissed = 0;
			var issues = 0;

			( ctx.list || [] ).forEach( function ( item ) {
				ctx.byKey[ item.key ] = item;
				if ( 'pass' !== item.severity ) {
					issues++;
				}
				if ( filter.category && item.category !== filter.category ) {
					return;
				}
				if ( 'pass' === item.severity ) {
					if ( ! filter.severity ) {
						passes.push( item );
					}
					return;
				}
				if ( filter.severity && item.severity !== filter.severity ) {
					return;
				}
				if ( dismissedMap[ item.key ] && ! filter.dismissed ) {
					hiddenDismissed++;
					return;
				}
				active.push( item );
			} );

			var html = '';
			if ( active.length ) {
				html += '<div class="blt-finding-list" role="list">';
				active.forEach( function ( item ) {
					html += findings.row( ctx, item );
				} );
				html += '</div>';
			} else if ( ! issues ) {
				html += emptyState( t( 'noIssues' ), t( 'noIssuesHint' ) );
			} else {
				html += emptyState( t( 'noMatches' ), hiddenDismissed ? tf( 'dismissedHidden', hiddenDismissed ) : '' );
			}

			if ( active.length && hiddenDismissed ) {
				html += '<p class="blt-finding-note">' + escapeHtml( tf( 'dismissedHidden', hiddenDismissed ) ) + '</p>';
			}

			if ( passes.length ) {
				findings.uid++;
				var passId = 'blt-fp-' + findings.uid;
				html += '<div class="blt-finding-passes">' +
					'<button type="button" class="blt-finding-pass-toggle" aria-expanded="' + ( ctx.passOpen ? 'true' : 'false' ) + '" aria-controls="' + passId + '">' +
					'<span class="blt-finding-caret" aria-hidden="true"></span>' + escapeHtml( tf( 'passedChecks', passes.length ) ) + '</button>' +
					'<div class="blt-finding-list" role="list" id="' + passId + '"' + ( ctx.passOpen ? '' : ' hidden' ) + '>';
				passes.forEach( function ( item ) {
					html += findings.row( ctx, item );
				} );
				html += '</div></div>';
			}

			$mount.html( html );

			if ( ctx.onRender ) {
				ctx.onRender( { shown: active.length, passes: passes.length, hiddenDismissed: hiddenDismissed, issues: issues } );
			}
		},

		row: function ( ctx, item ) {
			findings.uid++;
			var id = 'blt-fd-' + findings.uid;
			var key = item.key;
			var open = !! ctx.open[ key ];
			var dismissed = !! ctx.dismissedMap[ key ];
			var fixed = ctx.fixed[ key ];
			var category = ctx.categories && ctx.categories[ item.category ] ? ctx.categories[ item.category ] : item.category;
			var impact = item.impact && item.impact.label ? item.impact.label : '';

			var cls = 'blt-finding is-' + item.severity + ( open ? ' is-open' : '' ) + ( dismissed ? ' is-dismissed' : '' ) + ( fixed ? ' is-fixed' : '' );
			var html = '<div class="' + cls + '" role="listitem" data-key="' + escapeHtml( key ) + '">';
			html += '<button type="button" class="blt-finding-head" aria-expanded="' + ( open ? 'true' : 'false' ) + '" aria-controls="' + id + '">';
			html += badge( t( 'sev_' + item.severity ), SEVERITY_BADGE[ item.severity ] );
			html += '<span class="blt-finding-main"><span class="blt-finding-title">' + escapeHtml( item.title ) + '</span>';
			html += '<span class="blt-finding-meta">' + compChip( item.component ) +
				'<span class="blt-finding-cat">' + escapeHtml( category ) + '</span>';
			if ( fixed ) {
				html += findings.fixedPill( ctx );
			}
			if ( dismissed ) {
				html += badge( t( 'dismissed' ), 'blt-finding-dismissedpill' );
			}
			html += '</span></span>';
			html += '<span class="blt-finding-impact">' + escapeHtml( impact ) + '</span>';
			html += '<span class="blt-finding-caret" aria-hidden="true"></span></button>';
			html += '<div class="blt-finding-body" id="' + id + '"' + ( open ? '' : ' hidden' ) + '>' + findings.body( ctx, item, dismissed, fixed ) + '</div>';
			return html + '</div>';
		},

		fixedPill: function ( ctx ) {
			return '<span class="blt-badge blt-badge-confirmed blt-finding-fixedpill">' + escapeHtml( ctx.fixedLabel || t( 'fixedRescan' ) ) + '</span>';
		},

		body: function ( ctx, item, dismissed, fixed ) {
			var html = '';
			if ( item.summary ) {
				html += '<p class="blt-finding-summary">' + escapeHtml( item.summary ) + '</p>';
			}
			html += findings.where( item.where );
			html += findings.evidence( item.details );
			html += findings.fix( item, fixed );
			if ( ctx.canDismiss ) {
				html += '<div class="blt-finding-foot"><button type="button" class="button-link blt-finding-dismiss" data-dismiss="' + ( dismissed ? '0' : '1' ) + '">' +
					escapeHtml( dismissed ? t( 'restore' ) : t( 'dismiss' ) ) + '</button>' +
					'<span class="blt-finding-hint">' + escapeHtml( t( 'dismissHint' ) ) + '</span></div>';
			}
			return html;
		},

		where: function ( list ) {
			if ( ! list || ! list.length ) {
				return '';
			}
			var html = '<div class="blt-finding-section"><h3>' + escapeHtml( t( 'where' ) ) + '</h3><ul class="blt-finding-where">';
			list.forEach( function ( entry, index ) {
				entry = String( entry );
				html += '<li' + ( index >= WHERE_LIMIT ? ' class="is-extra" hidden' : '' ) + '>';
				html += /^https?:\/\//i.test( entry ) && safeUrl( entry ) ? link( entry, entry, true ) : '<code>' + escapeHtml( entry ) + '</code>';
				html += '</li>';
			} );
			if ( list.length > WHERE_LIMIT ) {
				html += '<li><button type="button" class="button-link blt-finding-more">' + escapeHtml( tf( 'showMore', list.length - WHERE_LIMIT ) ) + '</button></li>';
			}
			return html + '</ul></div>';
		},

		cell: function ( value, header ) {
			var text = null === value || undefined === value ? '' : String( value );
			if ( /^https?:\/\/\S+$/i.test( text ) && safeUrl( text ) ) {
				return link( text, text, true );
			}
			if ( /\b(sql|query)\b/i.test( header || '' ) && text.length > 30 ) {
				return sqlCell( text );
			}
			return escapeHtml( text );
		},

		evidence: function ( details ) {
			if ( ! details || ! details.rows || ! details.rows.length ) {
				return '';
			}
			var columns = details.columns || [];
			var rows = details.rows.map( function ( row ) {
				return Array.isArray( row ) ? row : $.map( row, function ( value ) {
					return [ value ];
				} );
			} );
			var width = Math.max( columns.length, rows.reduce( function ( max, row ) {
				return Math.max( max, row.length );
			}, 0 ) );

			var headers = [];
			var i;
			for ( i = 0; i < width; i++ ) {
				var numeric = true;
				rows.forEach( function ( row ) {
					var text = null === row[ i ] || undefined === row[ i ] ? '' : String( row[ i ] );
					if ( '' !== text && ! /^[~+\-−]?[\d.,]+\s?(ms|s|KB|MB|GB|B|%|×)?$/i.test( text ) ) {
						numeric = false;
					}
				} );
				headers.push( { label: undefined === columns[ i ] ? '' : columns[ i ], cls: numeric ? 'num' : '' } );
			}

			var body = rows.map( function ( row ) {
				var cells = [];
				var j;
				for ( j = 0; j < width; j++ ) {
					cells.push( findings.cell( row[ j ], headers[ j ].label ) );
				}
				return cells;
			} );

			return '<div class="blt-finding-section"><h3>' + escapeHtml( t( 'evidence' ) ) + '</h3>' + tableHtml( headers, body, 'blt-finding-table' ) + '</div>';
		},

		fix: function ( item, fixed ) {
			var fix = item.fix;
			if ( ! fix || 'object' !== typeof fix ) {
				return '';
			}
			var html = '<div class="blt-finding-section blt-finding-fix">';

			if ( 'action' === fix.type && fix.action ) {
				html += '<h3>' + escapeHtml( t( 'fix' ) ) + '</h3><div class="blt-finding-actions">' +
					'<button type="button" class="button button-primary blt-finding-apply"' + ( fixed ? ' disabled' : '' ) + '>' + escapeHtml( fix.label || t( 'applyFix' ) ) + '</button>';
				if ( fix.destructive ) {
					html += badge( t( 'deletesData' ), 'blt-badge-cancelled' );
				}
				html += '<span class="blt-finding-result' + ( fixed ? ' is-ok' : '' ) + '" role="status" aria-live="polite">' + ( fixed ? escapeHtml( fixed ) : '' ) + '</span></div>';
			} else if ( 'link' === fix.type && safeUrl( fix.url ) ) {
				html += '<h3>' + escapeHtml( t( 'fix' ) ) + '</h3><div class="blt-finding-actions">' +
					'<a class="button button-primary" href="' + escapeHtml( safeUrl( fix.url ) ) + '">' + escapeHtml( fix.label || t( 'openFix' ) ) + '</a></div>';
			} else if ( 'manual' === fix.type && fix.steps ) {
				html += '<h3>' + escapeHtml( fix.label || t( 'howToFix' ) ) + '</h3><code class="blt-code">' + escapeHtml( fix.steps ) + '</code>';
			} else {
				return '';
			}
			return html + '</div>';
		},

		apply: function ( $mount, $button ) {
			var ctx = $mount.data( 'bltCtx' );
			var $row = $button.closest( '.blt-finding' );
			var key = $row.attr( 'data-key' );
			var item = ctx.byKey[ key ];
			if ( ! item || ! item.fix ) {
				return;
			}
			var fix = item.fix;
			var $result = $row.find( '.blt-finding-result' );

			if ( ! window.confirm( fix.confirm || ( fix.destructive ? t( 'confirmDelete' ) : t( 'confirmFix' ) ) ) ) {
				return;
			}
			// Same once-per-session gate as the database cleanup screen.
			if ( fix.destructive && ! state.backupAcked ) {
				if ( ! window.confirm( bltOptimized.i18n.backupTitle + '\n\n' + bltOptimized.i18n.backupAck ) ) {
					return;
				}
			}

			$button.prop( 'disabled', true );
			$result.removeClass( 'is-ok is-error' ).text( t( 'applying' ) );

			api.post( 'perf_fix', {
				fix_action: fix.action,
				args: JSON.stringify( fix.args || {} ),
				backup_ack: fix.destructive ? 1 : 0,
				finding_key: key
			} )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$button.prop( 'disabled', false );
						$result.addClass( 'is-error' ).text( response && response.data && response.data.message ? response.data.message : t( 'error' ) );
						return;
					}
					if ( fix.destructive ) {
						state.backupAcked = true;
					}
					var message = response.data && response.data.message ? response.data.message : t( 'applied' );
					ctx.fixed[ key ] = message;
					$row.addClass( 'is-fixed' );
					if ( ! $row.find( '.blt-finding-fixedpill' ).length ) {
						$row.find( '.blt-finding-meta' ).append( findings.fixedPill( ctx ) );
					}
					$result.addClass( 'is-ok' ).text( message );
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					$result.addClass( 'is-error' ).text( t( 'error' ) );
				} );
		}
	};

	/* ------------------------------------------------------------------ */
	/* PageSpeed Insights block (scan summary row + profiler result)       */
	/* ------------------------------------------------------------------ */

	var psi = {
		metrics: [ 'lcp', 'cls', 'tbt', 'fcp', 'si', 'ttfb' ],

		metricClass: function ( score ) {
			if ( ! isNumber( score ) ) {
				return '';
			}
			return score >= 0.9 ? 'is-good' : ( score >= 0.5 ? 'is-warn' : 'is-bad' );
		},

		metricVerdict: function ( score ) {
			if ( ! isNumber( score ) ) {
				return '';
			}
			return score >= 0.9 ? t( 'psiGood' ) : ( score >= 0.5 ? t( 'psiMid' ) : t( 'psiPoor' ) );
		},

		// entry: PSI summary (scan) or full result (profiler).
		block: function ( entry ) {
			var metrics = entry.metrics || {};
			var html = '<div class="blt-perf-psi">';
			html += '<div class="blt-perf-psi-head">' + ring( entry.score, 'sm' ) +
				'<div class="blt-perf-psi-title"><strong>' + link( entry.url, entry.url, true ) + '</strong>' +
				'<span class="blt-perf-sub">' + escapeHtml( 'desktop' === entry.strategy ? t( 'strategyDesktop' ) : t( 'strategyMobile' ) ) +
				( entry.fetched_at ? ' · ' + escapeHtml( fmtDate( entry.fetched_at ) ) : '' ) + '</span></div></div>';

			html += '<div class="blt-stats blt-perf-metrics">';
			psi.metrics.forEach( function ( key ) {
				var metric = metrics[ key ];
				if ( ! metric ) {
					return;
				}
				var value = metric.display || ( isNumber( metric.value ) ? String( metric.value ) : DASH );
				html += '<div class="blt-stat ' + psi.metricClass( metric.score ) + '">' +
					'<span class="blt-stat-label"><abbr title="' + escapeHtml( t( 'metric_' + key ) ) + '">' + escapeHtml( key.toUpperCase() ) + '</abbr></span>' +
					'<span class="blt-stat-value">' + escapeHtml( value ) + '</span>' +
					'<span class="blt-stat-sub">' + escapeHtml( psi.metricVerdict( metric.score ) || t( 'metric_' + key ) ) + '</span></div>';
			} );
			html += '</div>';

			html += psi.field( entry.field );

			if ( entry.lcp_element && ( entry.lcp_element.selector || entry.lcp_element.snippet ) ) {
				html += '<div class="blt-perf-lcp"><strong>' + escapeHtml( t( 'lcpElement' ) ) + '</strong> ' +
					( entry.lcp_element.label ? '<span>' + escapeHtml( entry.lcp_element.label ) + '</span> ' : '' ) +
					( entry.lcp_element.selector ? '<code>' + escapeHtml( entry.lcp_element.selector ) + '</code>' : '' ) +
					( entry.lcp_element.snippet ? '<code class="blt-code">' + escapeHtml( entry.lcp_element.snippet ) + '</code>' : '' ) + '</div>';
			}
			return html + '</div>';
		},

		field: function ( field ) {
			if ( ! field || 'object' !== typeof field ) {
				return '';
			}
			var items = [];
			if ( isNumber( field.lcp_ms ) ) {
				items.push( [ 'LCP', ms( field.lcp_ms ) ] );
			}
			if ( isNumber( field.cls ) ) {
				items.push( [ 'CLS', String( field.cls ) ] );
			}
			if ( isNumber( field.inp_ms ) ) {
				items.push( [ 'INP', ms( field.inp_ms ) ] );
			}
			if ( isNumber( field.fcp_ms ) ) {
				items.push( [ 'FCP', ms( field.fcp_ms ) ] );
			}
			if ( isNumber( field.ttfb_ms ) ) {
				items.push( [ 'TTFB', ms( field.ttfb_ms ) ] );
			}
			if ( ! items.length && ! field.overall ) {
				return '';
			}
			var verdicts = { FAST: [ t( 'psiGood' ), 'blt-badge-confirmed' ], AVERAGE: [ t( 'psiMid' ), 'blt-badge-pending' ], SLOW: [ t( 'psiPoor' ), 'blt-badge-cancelled' ] };
			var verdict = field.overall ? verdicts[ String( field.overall ).toUpperCase() ] : null;
			var html = '<div class="blt-perf-field"><strong>' + escapeHtml( t( 'fieldData' ) ) + '</strong>';
			if ( verdict ) {
				html += badge( verdict[ 0 ], verdict[ 1 ] );
			}
			items.forEach( function ( item ) {
				html += '<span class="blt-perf-field-item"><abbr title="' + escapeHtml( t( 'metric_' + item[ 0 ].toLowerCase() ) ) + '">' + escapeHtml( item[ 0 ] ) + '</abbr> ' + escapeHtml( item[ 1 ] ) + '</span>';
			} );
			return html + '</div>';
		}
	};

	/* ------------------------------------------------------------------ */
	/* Lab test: the built-in measurement, run in a visible device frame   */
	/* ------------------------------------------------------------------ */

	// The frame's own width/height are the page's layout viewport, so these
	// match PageSpeed's mobile emulation and a common laptop window.
	var LAB_VIEWPORTS = {
		mobile: { w: 412, h: 823 },
		desktop: { w: 1350, h: 940 }
	};
	var LAB_QUIET_MS = 2000; // no resource, long task, LCP or layout shift for this long = settled
	var LAB_POLL_MS = 250;
	var LAB_LOAD_CAP = 20000; // give up waiting for quiet this long after load
	var LAB_TOTAL_CAP = 30000; // and this long after starting

	// [ good up to, needs improvement up to ]; anything above is poor.
	var LAB_THRESHOLDS = {
		lcp: [ 2500, 4000 ],
		cls: [ 0.1, 0.25 ],
		tbt: [ 200, 600 ],
		fcp: [ 1800, 3000 ],
		ttfb: [ 800, 1800 ]
	};
	var LAB_RATING_CLASS = { good: 'is-good', 'needs-improvement': 'is-warn', poor: 'is-bad' };
	var LAB_METRICS = [ 'lcp', 'cls', 'tbt', 'fcp', 'ttfb' ];

	function labRate( metric, value ) {
		if ( ! isNumber( value ) || ! LAB_THRESHOLDS[ metric ] ) {
			return '';
		}
		value = Number( value );
		return value <= LAB_THRESHOLDS[ metric ][ 0 ] ? 'good' : ( value <= LAB_THRESHOLDS[ metric ][ 1 ] ? 'needs-improvement' : 'poor' );
	}

	function labValue( metric, value ) {
		if ( ! isNumber( value ) ) {
			return DASH;
		}
		if ( 'cls' === metric ) {
			value = Number( value );
			return value > 0 && value < 0.001 ? '<0.001' : String( Math.round( value * 1000 ) / 1000 );
		}
		return ms( value );
	}

	function labVerdict( rating ) {
		return { good: t( 'psiGood' ), 'needs-improvement': t( 'psiMid' ), poor: t( 'psiPoor' ) }[ rating ] || '';
	}

	function labPick( first, second ) {
		return isNumber( first ) ? Number( first ) : ( isNumber( second ) ? Number( second ) : null );
	}

	function median( values ) {
		values = values.filter( isNumber ).map( Number ).sort( function ( a, b ) {
			return a - b;
		} );
		if ( ! values.length ) {
			return null;
		}
		var mid = Math.floor( values.length / 2 );
		return values.length % 2 ? values[ mid ] : ( values[ mid - 1 ] + values[ mid ] ) / 2;
	}

	// A coloured value for tables: the colour carries the rating, the hidden
	// text says it for everyone who can't see the colour.
	function labValueHtml( metric, value, rating ) {
		if ( ! isNumber( value ) ) {
			return escapeHtml( DASH );
		}
		rating = rating || labRate( metric, value );
		return '<span class="blt-lab-val ' + ( LAB_RATING_CLASS[ rating ] || '' ) + '">' + escapeHtml( labValue( metric, value ) ) +
			( rating ? '<span class="screen-reader-text"> (' + escapeHtml( labVerdict( rating ) ) + ')</span>' : '' ) + '</span>';
	}

	var labRunner = {
		uid: 0,

		/**
		 * Measure one page in one viewport.
		 *
		 * opts: { url, viewport: mobile|desktop, scan: bool, $mount, title, step, steps, onPhase(key, text) }
		 * Returns { promise, abort }. The promise resolves with { viewport, data, payload } where data is
		 * the perf_lab_store response ({ lab, summary, findings }) and is rejected with { message } or
		 * { aborted: true }.
		 *
		 * Flow: token -> visible frame -> load -> poll __bltLab.state() until quiet -> collect() ->
		 * store. A hidden admin tab or a frame scrolled out of view makes the run unreliable (the browser
		 * stops painting, so no LCP): it is discarded and repeated once.
		 */
		run: function ( opts ) {
			var viewport = LAB_VIEWPORTS[ opts.viewport ] ? opts.viewport : 'mobile';
			var size = LAB_VIEWPORTS[ viewport ];
			var ns = '.bltLab' + ( ++labRunner.uid );
			var deferred = $.Deferred();
			var run = { aborted: false, retries: 0, frame: null, timer: 0, started: 0, loadedAt: 0, loaded: false, hiddenSeen: false, offscreen: false, lastScroll: 0, $phase: null, loafWatch: null, loafEntries: [] };

			function phase( key ) {
				var text = t( 'labPhase_' + key );
				if ( run.$phase ) {
					run.$phase.text( text );
				}
				if ( opts.onPhase ) {
					opts.onPhase( key, text );
				}
			}

			function teardown() {
					window.clearTimeout( run.timer );
					if ( run.loafWatch ) {
						try {
							run.loafWatch.disconnect();
						} catch ( e ) {}
						run.loafWatch = null;
					}
				$( window ).off( ns );
				$( document ).off( ns );
				if ( run.frame ) {
					try {
						run.frame.src = 'about:blank';
					} catch ( e ) {}
					$( run.frame ).remove();
					run.frame = null;
				}
			}

			function fail( message ) {
				teardown();
				deferred.reject( { message: message || t( 'error' ) } );
			}

			// The whole scaled frame has to be in the admin window, or the browser skips painting it.
			function onScreen() {
				var rect = run.frame.getBoundingClientRect();
				var vw = window.innerWidth || document.documentElement.clientWidth;
				var vh = window.innerHeight || document.documentElement.clientHeight;
				var w = Math.max( 0, Math.min( rect.right, vw ) - Math.max( rect.left, 0 ) );
				var h = Math.max( 0, Math.min( rect.bottom, vh ) - Math.max( rect.top, 0 ) );
				return rect.width > 0 && rect.height > 0 && w * h >= 0.9 * Math.min( rect.width, vw ) * Math.min( rect.height, vh );
			}

			function bringIntoView() {
				if ( run.frame && run.frame.scrollIntoView && Date.now() - run.lastScroll > 1000 ) {
					run.lastScroll = Date.now();
					try {
						run.frame.scrollIntoView( { block: 'nearest', inline: 'nearest' } );
					} catch ( e ) {}
				}
			}

			// What the framed page exposes: the observer, nothing, or a refusal to be framed.
			function inspect() {
				var win;
				try {
					win = run.frame.contentWindow;
					// Throws for a cross-origin document: the browser's own "refused to connect" page.
					if ( ! win || 'string' !== typeof win.location.href ) {
						return { blocked: true };
					}
				} catch ( e ) {
					return { blocked: true };
				}
				try {
					if ( win.__bltLab && 'function' === typeof win.__bltLab.collect && 'function' === typeof win.__bltLab.state ) {
						return { api: win.__bltLab };
					}
				} catch ( e ) {}
				return { missing: true };
			}

			// A frame's own observer is not told about its long animation frames (Chrome reports them
				// to the top-level page, naming the frame that ran each script), so this page watches them
				// and keeps the ones that ran the framed page's scripts.
				function watchLoaf() {
					run.loafEntries = [];
					try {
						var types = window.PerformanceObserver && window.PerformanceObserver.supportedEntryTypes;
						if ( types && types.indexOf( 'long-animation-frame' ) > -1 ) {
							run.loafWatch = new window.PerformanceObserver( function ( list ) {
								run.loafEntries = run.loafEntries.concat( list.getEntries() );
							} );
							run.loafWatch.observe( { type: 'long-animation-frame' } );
						}
					} catch ( e ) {
						run.loafWatch = null;
					}
				}

				function framedLoaf( win ) {
					var frames = [];
					if ( run.loafWatch ) {
						run.loafEntries = run.loafEntries.concat( run.loafWatch.takeRecords() );
					}
					// Times in the payload are relative to the framed page's own navigation start.
					var offset = window.performance.timeOrigin - win.performance.timeOrigin;
					run.loafEntries.forEach( function ( entry ) {
						var scripts = [];
						var all = 0;
						var own = 0;
						( entry.scripts || [] ).forEach( function ( script ) {
							var mine = false;
							try {
								mine = script.window === win;
							} catch ( e ) {}
							all += Number( script.duration ) || 0;
							if ( mine ) {
								own += Number( script.duration ) || 0;
								scripts.push( {
									url: String( script.sourceURL || '' ).replace( /([?&])blt_lab=[^&#]*&?/, '$1' ).replace( /[?&]$/, '' ),
									fn: String( script.sourceFunctionName || '' ),
									invoker: String( script.invoker || '' ).replace( /([?&])blt_lab=[^&#]*&?/, '$1' ).replace( /[?&]$/, '' ),
									invokerType: String( script.invokerType || '' ),
									duration: Math.round( ( Number( script.duration ) || 0 ) * 10 ) / 10,
									forcedLayout: Math.round( ( Number( script.forcedStyleAndLayoutDuration ) || 0 ) * 10 ) / 10
								} );
							}
						} );
						if ( ! scripts.length ) {
							return;
						}
						// A frame shared with this admin page's own scripts is only charged for the framed page's share.
						var shared = own < all - 0.5;
						scripts.sort( function ( a, b ) {
							return b.duration - a.duration;
						} );
						frames.push( {
							start: Math.max( 0, Math.round( ( entry.startTime + offset ) * 10 ) / 10 ),
							duration: Math.round( ( shared ? own : entry.duration ) * 10 ) / 10,
							blocking: Math.round( ( shared ? entry.blockingDuration * ( own / all ) : entry.blockingDuration ) * 10 ) / 10,
							scripts: scripts.slice( 0, 12 )
						} );
					} );
					frames.sort( function ( a, b ) {
						return b.duration - a.duration;
					} );
					return frames.slice( 0, 20 );
				}

				function addWarning( payload, code ) {
				payload.warnings = Array.isArray( payload.warnings ) ? payload.warnings : [];
				if ( payload.warnings.indexOf( code ) < 0 ) {
					payload.warnings.push( code );
				}
			}

			function save( payload ) {
				phase( 'saving' );
				api.post( 'perf_lab_store', {
					url: opts.url,
					viewport: viewport,
					payload: JSON.stringify( payload ),
					scan: opts.scan ? 1 : 0
				} )
					.done( function ( response ) {
						if ( run.aborted ) {
							return;
						}
						if ( ! response || ! response.success ) {
							fail( response && response.data && response.data.message );
							return;
						}
						teardown();
						deferred.resolve( { viewport: viewport, data: response.data || {}, payload: payload } );
					} )
					.fail( function () {
						if ( ! run.aborted ) {
							fail( t( 'error' ) );
						}
					} );
			}

			// kind: done | timeout (still busy, or never finished loading).
			function conclude( kind ) {
				window.clearTimeout( run.timer );
				var found = inspect();
				var payload = null;
				phase( 'collecting' );

				if ( found.blocked ) {
					payload = { version: 1, warnings: [ 'framed_blocked' ] };
				} else if ( found.api ) {
					try {
						payload = found.api.collect();
						if ( payload && payload.nav && ( ! payload.loaf || ! payload.loaf.length ) && run.loafWatch ) {
							payload.loaf = framedLoaf( run.frame.contentWindow );
						}
					} catch ( e ) {
						payload = payload && 'object' === typeof payload ? payload : null;
					}
				}
				if ( ! payload || 'object' !== typeof payload ) {
					payload = { version: 1, warnings: [ 'no_observer' ] };
				}

				// Unreliable run: repeat once when it can be.
				if ( payload.nav && ( run.hiddenSeen || ( run.offscreen && ! payload.lcp ) ) && run.retries < 1 ) {
					run.retries++;
					teardown();
					phase( 'retry' );
					run.timer = window.setTimeout( attempt, 400 );
					return;
				}

				if ( 'timeout' === kind ) {
					addWarning( payload, 'timeout' );
				}
				if ( run.hiddenSeen && payload.nav ) {
					addWarning( payload, 'hidden_tab' );
				}
				save( payload );
			}

			function check() {
				if ( run.aborted || ! run.frame ) {
					return;
				}
				if ( 'hidden' === document.visibilityState ) {
					// No painting happens in a background tab: wait for the admin to come back.
					run.hiddenSeen = true;
					phase( 'hidden' );
					run.timer = window.setTimeout( check, 1000 );
					return;
				}
				if ( ! onScreen() ) {
					run.offscreen = true;
					bringIntoView();
				}

				var elapsed = Date.now() - run.started;
				if ( ! run.loaded ) {
					if ( elapsed >= LAB_TOTAL_CAP ) {
						conclude( 'timeout' );
						return;
					}
					run.timer = window.setTimeout( check, LAB_POLL_MS );
					return;
				}

				var found = inspect();
				if ( ! found.api ) {
					// Refused (blocked) or no observer on the page: nothing will change.
					conclude( 'done' );
					return;
				}
				var observed = null;
				try {
					observed = found.api.state();
				} catch ( e ) {
					observed = null;
				}
				if ( ! observed ) {
					conclude( 'done' );
					return;
				}
				if ( observed.loaded && Number( observed.quietFor ) >= LAB_QUIET_MS ) {
					conclude( 'done' );
					return;
				}
				if ( Date.now() - run.loadedAt >= LAB_LOAD_CAP || elapsed >= LAB_TOTAL_CAP ) {
					conclude( 'timeout' );
					return;
				}
				phase( 'settling' );
				run.timer = window.setTimeout( check, LAB_POLL_MS );
			}

			function onLoad() {
				var href = '';
				try {
					href = run.frame.contentWindow.location.href;
				} catch ( e ) {
					href = 'blocked';
				}
				if ( 'about:blank' === href || run.loaded ) {
					return;
				}
				run.loaded = true;
				run.loadedAt = Date.now();
			}

			function startFrame( src ) {
				var $frame = $( '<iframe></iframe>' ).attr( {
					width: size.w,
					height: size.h,
					scrolling: 'no',
					title: tf( 'labFrameTitle', opts.url ),
					tabindex: -1
				} );
				run.frame = $frame[ 0 ];
					opts.$mount.find( '.blt-lab-frame' ).append( $frame );
					labRunner.fit( opts.$mount, viewport );
					run.loaded = false;
				run.loadedAt = 0;
				run.offscreen = false;
				run.hiddenSeen = 'hidden' === document.visibilityState;
				run.started = Date.now();
				watchLoaf();

				$( window ).on( 'resize' + ns, function () {
					labRunner.fit( opts.$mount, viewport );
				} );
				$( document ).on( 'visibilitychange' + ns, function () {
					if ( 'hidden' === document.visibilityState ) {
						run.hiddenSeen = true;
					}
				} );
				$frame.on( 'load', onLoad );
				phase( 'loading' );
				$frame.attr( 'src', src );
				bringIntoView();
				run.timer = window.setTimeout( check, LAB_POLL_MS );
			}

			function attempt() {
				if ( run.aborted ) {
						return;
					}
					// The panel shows straight away, so a wait for the token or for the tab is visible too.
					run.$phase = labRunner.buildStage( opts, viewport );
					// A background tab can't paint: don't start until it is visible.
					if ( 'hidden' === document.visibilityState ) {
					phase( 'hidden' );
					$( document ).one( 'visibilitychange' + ns, attempt );
					return;
				}
				phase( 'token' );
				api.post( 'perf_lab_token', { url: opts.url } )
					.done( function ( response ) {
						if ( run.aborted ) {
							return;
						}
						if ( ! response || ! response.success || ! response.data || ! response.data.src ) {
							fail( response && response.data && response.data.message );
							return;
						}
						startFrame( response.data.src );
					} )
					.fail( function () {
						if ( ! run.aborted ) {
							fail( t( 'error' ) );
						}
					} );
			}

			attempt();

			return {
				promise: deferred.promise(),
				abort: function () {
					if ( run.aborted ) {
						return;
					}
					run.aborted = true;
					$( document ).off( ns );
					teardown();
					deferred.reject( { aborted: true } );
				}
			};
		},

		/**
		 * The device-frame panel: a header (what is being measured, and what it is
		 * doing now) and an empty device to put the page's frame in. Returns the phase element.
		 */
		buildStage: function ( opts, viewport ) {
			var size = LAB_VIEWPORTS[ viewport ];
			var steps = opts.steps > 1 ? ' ' + tf( 'labStepOf', opts.step, opts.steps ) : '';
			var html = '<div class="blt-lab-head"><span class="spinner is-active" aria-hidden="true"></span>' +
				'<div class="blt-lab-title"><strong>' + escapeHtml( tf( 'labMeasuring', t( 'mobile' === viewport ? 'strategyMobile' : 'strategyDesktop' ) ) + steps ) + '</strong>' +
				'<span class="blt-lab-url">' + escapeHtml( opts.title || opts.url ) + '</span></div>' +
				'<span class="blt-lab-phase" role="status"></span></div>' +
				'<div class="blt-lab-stage"><div class="blt-lab-device is-' + viewport + '">' +
				( 'desktop' === viewport ? '<div class="blt-lab-bar" aria-hidden="true"><i></i><i></i><i></i><span>' + escapeHtml( opts.url ) + '</span></div>' : '' ) +
				'<div class="blt-lab-screen"><div class="blt-lab-frame"></div></div></div></div>' +
				'<p class="blt-lab-note">' + escapeHtml( tf( 'labFrameNote', size.w, size.h ) ) + '</p>';

			opts.$mount.prop( 'hidden', false ).html( html );
			labRunner.fit( opts.$mount, viewport );
			return opts.$mount.find( '.blt-lab-phase' );
		},

		// Scale the frame so the whole device fits the panel and the window.
		fit: function ( $mount, viewport ) {
			var size = LAB_VIEWPORTS[ viewport ];
			var $stage = $mount.find( '.blt-lab-stage' );
			if ( ! $stage.length ) {
				return;
			}
			var availableW = Math.max( 200, $stage.innerWidth() - 48 );
			var availableH = Math.max( 280, Math.min( 620, ( window.innerHeight || 800 ) - 300 ) );
			var scale = Math.min( 1, availableW / size.w, availableH / size.h );
			var w = Math.round( size.w * scale );
			var h = Math.round( size.h * scale );
			$mount.find( '.blt-lab-screen' ).css( { width: w + 'px', height: h + 'px' } );
			$mount.find( '.blt-lab-frame' ).css( { width: size.w + 'px', height: size.h + 'px', transform: 'scale(' + scale.toFixed( 4 ) + ')' } );
		},

		clearStage: function ( $mount ) {
			$mount.prop( 'hidden', true ).empty();
		}
	};

	/* ------------------------------------------------------------------ */
	/* Lab results: the Lab (Core Web Vitals) view, shared by both screens */
	/* ------------------------------------------------------------------ */

	var labui = {
		// Where a URL comes from, when the server did not say.
		guessComponent: function ( url ) {
			url = String( url || '' );
			if ( ! url ) {
				return { type: 'other', slug: 'inline', name: t( 'labInline' ) };
			}
			var host = hostOf( url );
			if ( host && host !== String( window.location.host ).toLowerCase() ) {
				return { type: 'external', slug: host, name: host };
			}
			var match = /\/wp-content\/(plugins|themes|mu-plugins)\/([^\/?#]+)/.exec( url );
			if ( match ) {
				return { type: 'plugins' === match[ 1 ] ? 'plugin' : ( 'themes' === match[ 1 ] ? 'theme' : 'mu-plugin' ), slug: match[ 2 ], name: match[ 2 ] };
			}
			if ( /\/wp-(includes|admin)\//.test( url ) ) {
				return { type: 'core', slug: 'wordpress', name: t( 'labCore' ) };
			}
			return { type: 'other', slug: 'site', name: t( 'labSite' ) };
		},

		component: function ( item ) {
			return item && item.component && 'object' === typeof item.component ? item.component : labui.guessComponent( item && ( item.url || item.href ) );
		},

		// The numbers every view shows, from the server summary when it has them and from the raw payload otherwise.
		metrics: function ( lab, summary ) {
			lab = lab || {};
			summary = summary && 'object' === typeof summary && ! Array.isArray( summary ) ? summary : {};
			var nav = lab.nav || {};
			var values = {
				lcp: labPick( summary.lcp, lab.lcp ? lab.lcp.time : null ),
				cls: labPick( summary.cls, lab.cls ? lab.cls.value : null ),
				tbt: labPick( summary.tbt, lab.tbt ? lab.tbt.value : null ),
				fcp: labPick( summary.fcp, nav.fcp ),
				ttfb: labPick( summary.ttfb, nav.ttfb ),
				load: labPick( summary.load, nav.load )
			};
			var ratings = {};
			LAB_METRICS.forEach( function ( key ) {
				ratings[ key ] = summary.ratings && summary.ratings[ key ] ? summary.ratings[ key ] : labRate( key, values[ key ] );
			} );
			return { values: values, ratings: ratings };
		},

		// One metric tile; the border and the verdict line carry the rating.
		tile: function ( key, value, rating, sub ) {
			return '<div class="blt-stat ' + ( LAB_RATING_CLASS[ rating ] || '' ) + '">' +
				'<span class="blt-stat-label"><abbr title="' + escapeHtml( t( 'metric_' + key ) ) + '">' + escapeHtml( key.toUpperCase() ) + '</abbr></span>' +
				'<span class="blt-stat-value">' + escapeHtml( labValue( key, value ) ) + '</span>' +
				'<span class="blt-stat-sub">' + escapeHtml( labVerdict( rating ) || sub || t( 'metric_' + key ) ) + '</span></div>';
		},

		tilesHtml: function ( metrics ) {
			var html = '<div class="blt-stats blt-perf-metrics blt-lab-metrics">';
			LAB_METRICS.forEach( function ( key ) {
				html += labui.tile( key, metrics.values[ key ], metrics.ratings[ key ], '' );
			} );
			return html + '</div>';
		},

		// What a request cost on the wire (the encoded size when it came from the cache, so reruns compare).
		resourceBytes: function ( resource ) {
			if ( isNumber( resource.size ) ) {
				return Number( resource.size );
			}
			return Number( resource.transfer ) > 0 ? Number( resource.transfer ) : ( Number( resource.encoded ) || 0 );
		},

		// css | js | img | font | other, from the file extension and what asked for it.
		resourceKind: function ( resource ) {
			if ( resource.kind && /^(css|js|img|font|other)$/.test( resource.kind ) ) {
				return resource.kind;
			}
			var path = String( resource.url || '' ).split( '#' )[ 0 ].split( '?' )[ 0 ].toLowerCase();
			if ( /\.(woff2?|ttf|otf|eot)$/.test( path ) ) {
				return 'font';
			}
			if ( /\.(png|jpe?g|gif|webp|avif|svg|ico|bmp)$/.test( path ) || 'img' === resource.type ) {
				return 'img';
			}
			if ( /\.css$/.test( path ) || 'css' === resource.type || ( 'link' === resource.type && ! /\.js$/.test( path ) ) ) {
				return 'css';
			}
			if ( /\.(m?js)$/.test( path ) || 'script' === resource.type ) {
				return 'js';
			}
			return 'other';
		},

		warningText: function ( code ) {
			var key = 'labWarn_' + code;
			return Object.prototype.hasOwnProperty.call( i18n, key ) ? i18n[ key ] : '';
		},

		warningsHtml: function ( lab ) {
			var html = '';
			( lab.warnings || [] ).forEach( function ( code ) {
				var text = labui.warningText( code );
				if ( text ) {
					var severe = 'framed_blocked' === code || 'no_observer' === code;
					html += '<div class="blt-stack-top">' + callout( severe ? t( 'labCouldNotRun' ) : '', text, severe ? 'is-error' : 'is-warning' ) + '</div>';
				}
			} );
			return html;
		},

		// Whether the page was measured at all (a refused frame leaves only warnings).
		measured: function ( lab ) {
			return !! ( lab && lab.nav );
		},

		// Same question for a compact per-viewport summary from the report.
		measuredSummary: function ( summary ) {
			return !! summary && ( isNumber( summary.lcp ) || isNumber( summary.cls ) || isNumber( summary.tbt ) || isNumber( summary.fcp ) );
		},

		// A coloured metric cell for the pages table.
		cellHtml: function ( summary, key ) {
			if ( ! summary || 'object' !== typeof summary ) {
				return escapeHtml( DASH );
			}
			if ( ! isNumber( summary[ key ] ) ) {
				var blocked = ( summary.warnings || [] ).some( function ( code ) {
					return 'framed_blocked' === code || 'no_observer' === code;
				} );
				return 'lcp' === key && blocked ? badge( t( 'labBlockedShort' ), 'blt-badge-cancelled' ) : escapeHtml( DASH );
			}
			return labValueHtml( key, summary[ key ], summary.ratings && summary.ratings[ key ] );
		},

		/* --- Panels ----------------------------------------------------- */

		lcpHtml: function ( lab ) {
			var lcp = lab.lcp;
			var nav = lab.nav || {};
			var dpr = Number( lab.viewport && lab.viewport.dpr ) || 1;
			var html = '<h3>' + escapeHtml( t( 'labLcpElement' ) ) + '</h3>';
			if ( ! lcp ) {
				return html + '<p class="description">' + escapeHtml( t( 'labNoLcp' ) ) + '</p>';
			}

			var facts = [];
			var element = '<code>' + escapeHtml( lcp.selector || lcp.tag || DASH ) + '</code>';
			var bricks = labui.bricksHtml( lcp.bricks, lcp.bricksId );
			facts.push( [ t( 'labFactElement' ), element + ( bricks ? '<span class="blt-perf-sub">' + bricks + '</span>' : '' ) ] );

			var kind = lcp.isImage ? ( lcp.isBackground ? t( 'labKindBackground' ) : t( 'labKindImage' ) ) : t( 'labKindText' );
			facts.push( [ t( 'labFactType' ), escapeHtml( kind ) + ( lcp.tag ? ' · <code>&lt;' + escapeHtml( lcp.tag ) + '&gt;</code>' : '' ) ] );
			facts.push( [ t( 'labFactRendered' ), escapeHtml( ms( lcp.time ) ) + ( lcp.size ? ' · ' + escapeHtml( tf( 'labPixels', num( lcp.size ) ) ) : '' ) ] );

			if ( lcp.isImage && lcp.url ) {
				var resource = null;
				( lab.resources || [] ).forEach( function ( item ) {
					if ( ! resource && item.url === lcp.url ) {
						resource = item;
					}
				} );
				facts.push( [ t( 'labFactImage' ), link( lcp.url, fileName( lcp.url ) || lcp.url, true ) +
					( resource ? ' ' + compChip( labui.component( resource ) ) + '<span class="blt-perf-sub">' + escapeHtml( bytes( labui.resourceBytes( resource ) ) + ( resource.cached ? ' · ' + t( 'labCached' ) : '' ) ) + '</span>' : '' ) ] );
				if ( lcp.naturalW && lcp.renderedW ) {
					var needW = Math.round( lcp.renderedW * dpr );
					var needH = Math.round( lcp.renderedH * dpr );
					facts.push( [ t( 'labFactSize' ), escapeHtml( tf( 'labSizeCompare', lcp.naturalW + '×' + lcp.naturalH, Math.round( lcp.renderedW ) + '×' + Math.round( lcp.renderedH ), needW + '×' + needH, dpr ) ) ] );
				}
				var attrs = [];
				[ 'loading', 'fetchpriority', 'decoding' ].forEach( function ( name ) {
					if ( lcp[ name ] ) {
						attrs.push( '<code>' + escapeHtml( name + '=' + lcp[ name ] ) + '</code>' );
					}
				} );
				facts.push( [ t( 'labFactAttrs' ), attrs.length ? attrs.join( ' ' ) : escapeHtml( t( 'labNoAttrs' ) ) ] );
			} else if ( lcp.text ) {
				facts.push( [ t( 'labFactText' ), escapeHtml( '“' + lcp.text + '”' ) ] );
			}

			html += '<div class="blt-lab-card">' + tableHtml( [ t( 'colMetric' ), t( 'colValue' ) ], facts.map( function ( fact ) {
				return [ escapeHtml( fact[ 0 ] ), fact[ 1 ] ];
			} ), 'blt-lab-facts' ) + '</div>';

			html += labui.lcpBreakdown( lcp, nav );

			var causes = labui.lcpCauses( lab );
			if ( causes.length ) {
				html += '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labCauses' ) ) + '</h4><ul class="blt-lab-causes">';
				causes.forEach( function ( cause ) {
					html += '<li>' + escapeHtml( cause ) + '</li>';
				} );
				html += '</ul>';
			}
			return html;
		},

		// Google's four LCP phases: server, finding the resource, downloading it, painting it.
		lcpBreakdown: function ( lcp, nav ) {
			var ttfb = Math.min( Number( nav.ttfb ) || 0, lcp.time );
			var resource = lcp.resource;
			var parts;
			if ( resource ) {
				var start = Math.max( ttfb, Number( resource.start ) || 0 );
				var end = Math.max( start, Number( resource.end ) || 0 );
				parts = [
					[ 'ttfb', t( 'labPartTtfb' ), ttfb ],
					[ 'delay', t( 'labPartDelay' ), start - ttfb ],
					[ 'load', t( 'labPartLoad' ), end - start ],
					[ 'render', t( 'labPartRender' ), Math.max( 0, lcp.time - end ) ]
				];
			} else {
				parts = [
					[ 'ttfb', t( 'labPartTtfb' ), ttfb ],
					[ 'render', t( 'labPartRenderText' ), Math.max( 0, lcp.time - ttfb ) ]
				];
			}
			var total = parts.reduce( function ( sum, part ) {
				return sum + part[ 2 ];
			}, 0 );
			if ( ! total ) {
				return '';
			}
			var html = '<div class="blt-perf-timeline blt-lab-timeline" role="img" aria-label="' + escapeHtml( tf( 'labBreakdownLabel', ms( lcp.time ) ) ) + '">';
			var legend = '<ol class="blt-perf-legend">';
			parts.forEach( function ( part, index ) {
				var seg = index + 1 + ( parts.length < 4 && index ? 2 : 0 );
				if ( part[ 2 ] > 0 ) {
					html += '<span class="blt-perf-seg blt-perf-seg-' + seg + '" style="width:' + ( ( 100 * part[ 2 ] ) / total ).toFixed( 2 ) + '%" title="' + escapeHtml( part[ 1 ] + ': ' + ms( part[ 2 ] ) ) + '"></span>';
				}
				legend += '<li><span class="blt-perf-swatch blt-perf-seg-' + seg + '" aria-hidden="true"></span><span class="blt-perf-legend-name"><strong>' + escapeHtml( part[ 1 ] ) + '</strong></span>' +
					'<span class="blt-perf-legend-ms">' + escapeHtml( ms( part[ 2 ] ) ) + '</span><span class="blt-perf-legend-pct">' + escapeHtml( Math.round( ( 100 * part[ 2 ] ) / total ) + '%' ) + '</span></li>';
			} );
			return '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labBreakdown' ) ) + '</h4>' + html + '</div>' + legend + '</ol>';
		},

		lcpCauses: function ( lab ) {
			var lcp = lab.lcp;
			var nav = lab.nav || {};
			var dpr = Number( lab.viewport && lab.viewport.dpr ) || 1;
			var causes = [];
			if ( ! lcp ) {
				return causes;
			}
			if ( Number( nav.ttfb ) > LAB_THRESHOLDS.ttfb[ 0 ] ) {
				causes.push( tf( 'labCauseTtfb', ms( nav.ttfb ) ) );
			}
			if ( lcp.isImage ) {
				if ( 'lazy' === lcp.loading ) {
					causes.push( t( 'labCauseLazy' ) );
				}
				if ( ! lcp.isBackground && 'high' !== lcp.fetchpriority && 'lazy' !== lcp.loading ) {
					causes.push( t( 'labCausePriority' ) );
				}
				if ( lcp.isBackground ) {
					causes.push( t( 'labCauseBackground' ) );
				}
				if ( lcp.naturalW && lcp.renderedW && lcp.naturalW > 1.5 * lcp.renderedW * dpr ) {
					causes.push( tf( 'labCauseOversized', lcp.naturalW + '×' + lcp.naturalH, Math.round( lcp.renderedW * dpr ) + '×' + Math.round( lcp.renderedH * dpr ) ) );
				}
				if ( lcp.resource && Number( lcp.resource.start ) - Number( nav.ttfb || 0 ) > 500 ) {
					causes.push( tf( 'labCauseLate', ms( Number( lcp.resource.start ) - Number( nav.ttfb || 0 ) ) ) );
				}
			}
			var blocking = lcp.resource ? Number( lcp.resource.renderBlockingBefore ) || 0 : ( lab.resources || [] ).filter( function ( item ) {
				return item.blocking && Number( item.end ) <= Number( lcp.time );
			} ).length;
			if ( blocking > 0 ) {
				causes.push( tf( 'labCauseBlocking', blocking ) );
			}
			return causes;
		},

		// The Bricks element (and the template or page it lives in) behind a selector, when the server found it.
		bricksHtml: function ( info, id ) {
			var parts = [];
			if ( info && 'object' === typeof info ) {
				var name = info.label || info.name || info.element_label || info.element_name || info.element || info.type;
				var where = info.template_title || info.template_name || info.template || info.post_title || info.title ||
					( info.post && ( info.post.title || info.post.name ) ) || ( info.source && ( info.source.title || info.source.name ) ) || '';
				var edit = info.edit_url || info.editUrl || info.edit_link || info.url ||
					( info.post && info.post.edit_url ) || ( info.source && info.source.edit_url ) || '';
				var text = tf( 'labBricksElement', name && 'object' !== typeof name ? name : ( id ? 'brxe-' + id : '' ) );
				parts.push( escapeHtml( text ) );
				if ( where && 'object' !== typeof where ) {
					var label = tf( 'labBricksIn', where );
					parts.push( safeUrl( edit ) ? '<a href="' + escapeHtml( safeUrl( edit ) ) + '">' + escapeHtml( label ) + '</a> <a class="button button-small" href="' + escapeHtml( safeUrl( edit ) ) + '">' + escapeHtml( t( 'labEditBricks' ) ) + '</a>' : escapeHtml( label ) );
				} else if ( safeUrl( edit ) ) {
					parts.push( '<a class="button button-small" href="' + escapeHtml( safeUrl( edit ) ) + '">' + escapeHtml( t( 'labEditBricks' ) ) + '</a>' );
				}
			} else if ( id ) {
				parts.push( escapeHtml( tf( 'labBricksElement', 'brxe-' + id ) ) );
			}
			return parts.join( ' · ' );
		},

		shiftsHtml: function ( lab ) {
			var cls = lab.cls || { value: 0, shifts: [] };
			var shifts = ( cls.shifts || [] ).slice().sort( function ( a, b ) {
				return Number( b.value ) - Number( a.value );
			} );
			var html = '<h3>' + escapeHtml( t( 'labShifts' ) ) + '</h3>';
			if ( ! shifts.length ) {
				return html + '<p class="description">' + escapeHtml( t( 'labNoShifts' ) ) + '</p>';
			}
			var max = Number( shifts[ 0 ].value ) || 0;
			var rows = [];
			function rect( box ) {
				return Array.isArray( box ) && 4 === box.length ? Math.round( box[ 2 ] ) + '×' + Math.round( box[ 3 ] ) + ' @ ' + Math.round( box[ 0 ] ) + ',' + Math.round( box[ 1 ] ) : DASH;
			}
			shifts.forEach( function ( shift ) {
				var sources = ( shift.sources || [] ).slice( 0, 3 );
				if ( ! sources.length ) {
					sources = [ null ];
				}
				sources.forEach( function ( source, index ) {
					var bricks = source ? labui.bricksHtml( source.bricks, source.bricksId ) : '';
					rows.push( [
						index ? '' : { html: barHtml( Number( shift.value ) || 0, max, labValue( 'cls', shift.value ) ), cls: 'blt-perf-bar-cell' },
						index ? '' : { html: escapeHtml( ms( shift.time ) ), cls: 'num' },
						source ? '<code>' + escapeHtml( source.selector || source.tag || DASH ) + '</code>' + ( bricks ? '<span class="blt-perf-sub">' + bricks + '</span>' : '' ) : escapeHtml( t( 'labRemovedNode' ) ),
						source ? escapeHtml( rect( source.prevRect ) + ' → ' + rect( source.currRect ) ) : escapeHtml( DASH )
					] );
				} );
			} );
			return html + '<p class="description">' + escapeHtml( tf( 'labShiftsHint', labValue( 'cls', cls.value ) ) ) + '</p>' +
				tableHtml( [ t( 'labColShift' ), { label: t( 'colTime' ), cls: 'num' }, t( 'labColElement' ), t( 'labColMoved' ) ], rows, 'blt-lab-shifts' );
		},

		blockingHtml: function ( lab ) {
			var list = ( lab.resources || [] ).filter( function ( item ) {
				return item.blocking;
			} ).sort( function ( a, b ) {
				return Number( b.end ) - Number( a.end );
			} );
			var html = '<h3>' + escapeHtml( t( 'labBlocking' ) ) + ' <span class="blt-perf-tab-count">' + escapeHtml( num( list.length ) ) + '</span></h3>';
			if ( ! list.length ) {
				return html + '<p class="description">' + escapeHtml( t( 'labNoBlocking' ) ) + '</p>';
			}
			var last = Number( list[ 0 ].end ) || 0;
			var rows = list.map( function ( item ) {
				return [
					link( item.url, fileName( item.url ) || item.url, true ) + '<span class="blt-perf-sub">' + escapeHtml( item.type || '' ) + '</span>',
					compChip( labui.component( item ) ) || escapeHtml( DASH ),
					bytes( labui.resourceBytes( item ) ),
					escapeHtml( ms( item.start ) + ' → ' + ms( item.end ) ),
					{ html: escapeHtml( ms( Math.max( 0, Number( item.end ) - Number( item.start ) ) ) ), cls: 'num' }
				];
			} );
			return html + '<p class="description">' + escapeHtml( tf( 'labBlockingHint', list.length, ms( last ) ) ) + '</p>' +
				tableHtml( [ t( 'colFile' ), t( 'colComponent' ), { label: t( 'colSize' ), cls: 'num' }, { label: t( 'labColWindow' ), cls: 'num' }, { label: t( 'colTime' ), cls: 'num' } ], rows, 'blt-lab-blocking' );
		},

		cssHtml: function ( lab ) {
			var sheets = ( lab.css || [] ).filter( function ( sheet ) {
				return Number( sheet.bytes ) > 0 || Number( sheet.rules ) > 0;
			} );
			var html = '<h3>' + escapeHtml( t( 'labUnusedCss' ) ) + '</h3>';
			if ( ! sheets.length ) {
				return html + '<p class="description">' + escapeHtml( t( 'labNoCss' ) ) + '</p>';
			}

			var total = sumBy( sheets, 'bytes' );
			var unused = sumBy( sheets, 'unusedBytes' );
			html += '<p class="description">' + escapeHtml( tf( 'labUnusedHint', bytes( unused ), total ? Math.round( ( 100 * unused ) / total ) : 0, bytes( total ) ) ) + '</p>';

			// By component first: that is who to ask to stop shipping it.
			var groups = {};
			sheets.forEach( function ( sheet ) {
				var component = labui.component( sheet );
				var key = compKey( component );
				groups[ key ] = groups[ key ] || { component: component, sheets: 0, bytes: 0, unused: 0 };
				groups[ key ].sheets++;
				groups[ key ].bytes += Number( sheet.bytes ) || 0;
				groups[ key ].unused += Number( sheet.unusedBytes ) || 0;
			} );
			var groupList = Object.keys( groups ).map( function ( key ) {
				return groups[ key ];
			} ).sort( function ( a, b ) {
				return b.unused - a.unused;
			} );
			var groupMax = groupList.length ? groupList[ 0 ].unused : 0;
			html += tableHtml( [ t( 'colComponent' ), { label: t( 'labColSheets' ), cls: 'num' }, { label: t( 'colSize' ), cls: 'num' }, t( 'labColUnused' ) ], groupList.map( function ( group ) {
				return [
					compChip( group.component ) || escapeHtml( DASH ),
					num( group.sheets ),
					bytes( group.bytes ),
					{ html: barHtml( group.unused, groupMax, bytes( group.unused ) + ' · ' + ( group.bytes ? Math.round( ( 100 * group.unused ) / group.bytes ) : 0 ) + '%' ), cls: 'blt-perf-bar-cell' }
				];
			} ), 'blt-lab-css-groups' );

			var byUnused = sheets.slice().sort( function ( a, b ) {
				return ( Number( b.unusedBytes ) || 0 ) - ( Number( a.unusedBytes ) || 0 ) || ( Number( b.bytes ) || 0 ) - ( Number( a.bytes ) || 0 );
			} );
			var shown = byUnused.slice( 0, 25 );
			var max = shown.length ? Number( shown[ 0 ].unusedBytes ) || 0 : 0;
			html += '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labByStylesheet' ) ) + '</h4>';
			html += tableHtml( [ t( 'labColStylesheet' ), t( 'colComponent' ), { label: t( 'colSize' ), cls: 'num' }, t( 'labColUnused' ), { label: t( 'labColRules' ), cls: 'num' } ], shown.map( function ( sheet ) {
				var name = sheet.href ? link( sheet.href, fileName( sheet.href ) || sheet.href, true ) : '<code>' + escapeHtml( sheet.inlineId || t( 'labInlineStyle' ) ) + '</code>';
				var note = '';
				if ( ! sheet.accessible ) {
					note = '<span class="blt-perf-sub is-warn">' + escapeHtml( t( 'labCrossOrigin' ) ) + '</span>';
				} else if ( Number( sheet.otherMediaRules ) > 0 ) {
					note = '<span class="blt-perf-sub">' + escapeHtml( plural( sheet.otherMediaRules, 'labOtherMediaOne', 'labOtherMediaMany' ) ) + '</span>';
				}
				var unusedPct = Number( sheet.bytes ) ? Math.round( ( 100 * ( Number( sheet.unusedBytes ) || 0 ) ) / Number( sheet.bytes ) ) : 0;
				return [
					name + note,
					compChip( labui.component( sheet ) ) || escapeHtml( DASH ),
					bytes( sheet.bytes ),
					sheet.accessible ? { html: barHtml( Number( sheet.unusedBytes ) || 0, max, bytes( sheet.unusedBytes ) + ' · ' + unusedPct + '%' ), cls: 'blt-perf-bar-cell' } : DASH,
					sheet.accessible ? escapeHtml( num( sheet.unusedRules ) + ' / ' + num( sheet.rules ) ) : DASH
				];
			} ), 'blt-lab-css' );
			if ( byUnused.length > shown.length ) {
				html += '<p class="description">' + escapeHtml( tf( 'labMoreSheets', byUnused.length - shown.length ) ) + '</p>';
			}
			return html;
		},

		mainThreadHtml: function ( lab, metrics ) {
			var tbt = lab.tbt || { value: 0, longTasks: [], count: 0 };
			var frames = lab.loaf || [];
			var html = '<h3>' + escapeHtml( t( 'labMainThread' ) ) + '</h3>';

			var longest = ( tbt.longTasks || [] ).reduce( function ( max, task ) {
				return Math.max( max, Number( task.duration ) || 0 );
			}, 0 );
			var blocking = sumBy( frames, 'blocking' );
			html += '<div class="blt-stats blt-perf-summary">' +
				labui.tile( 'tbt', metrics.values.tbt, metrics.ratings.tbt, '' ) +
				statTile( t( 'labLongTasks' ), num( tbt.count || 0 ), longest ? tf( 'labLongest', ms( longest ) ) : '' ) +
				statTile( t( 'labFrames' ), num( frames.length ), frames.length ? tf( 'labFramesBlocking', ms( blocking ) ) : '' ) + '</div>';

			var scripts = [];
			frames.forEach( function ( frame ) {
				( frame.scripts || [] ).forEach( function ( script ) {
					scripts.push( script );
				} );
			} );

			if ( ! scripts.length ) {
				html += '<p class="description">' + escapeHtml( ( lab.warnings || [] ).indexOf( 'no_loaf' ) > -1 ? t( 'labWarn_no_loaf' ) : t( 'labNoScriptTime' ) ) + '</p>';
			} else {
				// Script time per component, then per script.
				var groups = {};
				var perScript = {};
				scripts.forEach( function ( script ) {
					var component = labui.component( { component: script.component, url: script.url } );
					var key = compKey( component );
					groups[ key ] = groups[ key ] || { component: component, ms: 0, forced: 0, count: 0 };
					groups[ key ].ms += Number( script.duration ) || 0;
					groups[ key ].forced += Number( script.forcedLayout ) || 0;
					groups[ key ].count++;
					var id = ( script.url || '' ) + '|' + ( script.fn || '' );
					perScript[ id ] = perScript[ id ] || { script: script, component: component, ms: 0, forced: 0, count: 0 };
					perScript[ id ].ms += Number( script.duration ) || 0;
					perScript[ id ].forced += Number( script.forcedLayout ) || 0;
					perScript[ id ].count++;
				} );
				var groupList = Object.keys( groups ).map( function ( key ) {
					return groups[ key ];
				} ).sort( function ( a, b ) {
					return b.ms - a.ms;
				} );
				var max = groupList[ 0 ].ms;
				html += '<p class="description">' + escapeHtml( t( 'labScriptTimeHint' ) ) + '</p>';
				html += tableHtml( [ t( 'colComponent' ), t( 'labColScriptTime' ), { label: t( 'labColForced' ), cls: 'num' }, { label: t( 'labColCalls' ), cls: 'num' } ], groupList.map( function ( group ) {
					return [
						compChip( group.component ) || escapeHtml( DASH ),
						{ html: barHtml( group.ms, max, ms( group.ms ) ), cls: 'blt-perf-bar-cell' },
						group.forced ? ms( group.forced ) : DASH,
						num( group.count )
					];
				} ), 'blt-lab-main-groups' );

				var scriptList = Object.keys( perScript ).map( function ( key ) {
					return perScript[ key ];
				} ).sort( function ( a, b ) {
					return b.ms - a.ms;
				} ).slice( 0, 15 );
				html += '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labByScript' ) ) + '</h4>';
				html += tableHtml( [ t( 'labColScript' ), t( 'colComponent' ), { label: t( 'labColScriptTime' ), cls: 'num' }, { label: t( 'labColForced' ), cls: 'num' }, t( 'labColTrigger' ) ], scriptList.map( function ( row ) {
					var script = row.script;
					return [
						( script.url ? link( script.url, fileName( script.url ) || script.url, true ) : '<span>' + escapeHtml( t( 'labInline' ) ) + '</span>' ) +
							( script.fn ? '<span class="blt-perf-sub"><code>' + escapeHtml( script.fn ) + '</code></span>' : '' ),
						compChip( row.component ) || escapeHtml( DASH ),
						ms( row.ms ),
						row.forced ? ms( row.forced ) : DASH,
						'<span class="blt-perf-sub">' + escapeHtml( script.invokerType || DASH ) + '</span>'
					];
				} ), 'blt-lab-main-scripts' );
			}

			var tasks = ( tbt.longTasks || [] ).slice( 0, 8 );
			if ( tasks.length ) {
				html += '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labLongTaskList' ) ) + '</h4>';
				html += tableHtml( [ { label: t( 'labColStart' ), cls: 'num' }, { label: t( 'colTime' ), cls: 'num' }, { label: t( 'labColBlocking' ), cls: 'num' } ], tasks.map( function ( task ) {
					return [ ms( task.start ), ms( task.duration ), ms( Math.max( 0, Number( task.duration ) - 50 ) ) ];
				} ), 'blt-lab-tasks' );
			}
			return html;
		},

		imagesHtml: function ( lab ) {
			var dpr = Number( lab.viewport && lab.viewport.dpr ) || 1;
			var sizes = {};
			( lab.resources || [] ).forEach( function ( item ) {
				sizes[ item.url ] = labui.resourceBytes( item );
			} );
			var flagged = [];
			( lab.images || [] ).forEach( function ( image ) {
				var flags = [];
				var size = sizes[ image.src ] || 0;
				var wasted = 0;
				var needed = Number( image.renderedW ) * dpr;
				if ( needed > 0 && Number( image.naturalW ) > 1.5 * needed ) {
					wasted = size * ( 1 - ( needed * needed ) / ( Number( image.naturalW ) * Number( image.naturalW ) ) );
					if ( wasted > 50 * 1024 ) {
						flags.push( [ 'blt-badge-pending', tf( 'labFlagOversized', bytes( wasted ) ) ] );
					}
				}
				if ( ! image.inInitialViewport && 'lazy' !== image.loading && image.complete && Number( image.renderedW ) > 0 ) {
					flags.push( [ 'blt-badge-pending', t( 'labFlagOffscreen' ) ] );
				}
				if ( image.inInitialViewport && 'lazy' === image.loading ) {
					flags.push( [ 'blt-badge-cancelled', t( 'labFlagLazyAbove' ) ] );
				}
				if ( flags.length ) {
					flagged.push( { image: image, size: size, flags: flags, wasted: wasted } );
				}
			} );
			var html = '<h3>' + escapeHtml( t( 'labImages' ) ) + ' <span class="blt-perf-tab-count">' + escapeHtml( num( ( lab.images || [] ).length ) ) + '</span></h3>';
			if ( ! ( lab.images || [] ).length ) {
				return html + '<p class="description">' + escapeHtml( t( 'noImages' ) ) + '</p>';
			}
			if ( ! flagged.length ) {
				return html + '<p class="description">' + escapeHtml( t( 'labImagesOk' ) ) + '</p>';
			}
			flagged.sort( function ( a, b ) {
				return b.size - a.size;
			} );
			html += '<p class="description">' + escapeHtml( t( 'labImagesHint' ) ) + '</p>';
			return html + tableHtml( [ t( 'colImage' ), { label: t( 'colSize' ), cls: 'num' }, { label: t( 'colNatural' ), cls: 'num' }, { label: t( 'labColShownAt' ), cls: 'num' }, { label: '', sr: t( 'colFlags' ) } ], flagged.map( function ( row ) {
				var image = row.image;
				return [
					link( image.src, fileName( image.src ) || image.src, true ) + '<span class="blt-perf-sub"><code>' + escapeHtml( image.selector || '' ) + '</code></span>',
					row.size ? bytes( row.size ) : DASH,
					image.naturalW ? escapeHtml( image.naturalW + '×' + image.naturalH ) : DASH,
					escapeHtml( Math.round( image.renderedW ) + '×' + Math.round( image.renderedH ) + ( dpr > 1 ? ' @' + dpr + '×' : '' ) ),
					row.flags.map( function ( flag ) {
						return badge( flag[ 1 ], flag[ 0 ] );
					} ).join( ' ' )
				];
			} ), 'blt-lab-images' );
		},

		resourcesHtml: function ( lab, summary ) {
			var list = lab.resources || [];
			var html = '<h3>' + escapeHtml( t( 'labResources' ) ) + ' <span class="blt-perf-tab-count">' + escapeHtml( num( list.length ) ) + '</span></h3>';
			if ( ! list.length ) {
				return html + '<p class="description">' + escapeHtml( t( 'labNoResources' ) ) + '</p>';
			}

			var groups = {};
			var kinds = { js: 0, css: 0, img: 0, font: 0, other: 0 };
			var total = 0;
			list.forEach( function ( item ) {
				var component = labui.component( item );
				var key = compKey( component );
				var size = labui.resourceBytes( item );
				var kind = labui.resourceKind( item );
				groups[ key ] = groups[ key ] || { component: component, count: 0, bytes: 0, kinds: {} };
				groups[ key ].count++;
				groups[ key ].bytes += size;
				groups[ key ].kinds[ kind ] = ( groups[ key ].kinds[ kind ] || 0 ) + size;
				kinds[ kind ] += size;
				total += size;
			} );

			// The server summary also counts the HTML document itself.
			var sums = summary && summary.bytes && 'object' === typeof summary.bytes && isNumber( summary.bytes.total ) ? summary.bytes : null;
			var requestCount = sums && isNumber( summary.requests ) ? summary.requests : list.length;
			html += '<div class="blt-stats blt-perf-summary">' +
				statTile( t( 'labWeight' ), bytes( sums ? sums.total : total ), plural( requestCount, 'labRequestOne', 'labRequestMany' ) ) +
				statTile( t( 'tileJs' ), bytes( sums ? sums.js : kinds.js ) ) + statTile( t( 'tileCss' ), bytes( sums ? sums.css : kinds.css ) ) +
				statTile( t( 'tileImages' ), bytes( sums ? sums.img : kinds.img ) ) + statTile( t( 'labFonts' ), bytes( sums ? sums.font : kinds.font ) ) + '</div>';

			var groupList = Object.keys( groups ).map( function ( key ) {
				return groups[ key ];
			} ).sort( function ( a, b ) {
				return b.bytes - a.bytes || b.count - a.count;
			} );
			var max = groupList.length ? groupList[ 0 ].bytes : 0;
			html += '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labByComponent' ) ) + '</h4>' + tableHtml( [ t( 'colComponent' ), { label: t( 'colRequests' ), cls: 'num' }, t( 'labColTransferred' ), t( 'labColKinds' ) ], groupList.map( function ( group ) {
				var parts = [];
				[ 'js', 'css', 'img', 'font', 'other' ].forEach( function ( kind ) {
					if ( group.kinds[ kind ] ) {
						parts.push( t( 'labKind_' + kind ) + ' ' + bytes( group.kinds[ kind ] ) );
					}
				} );
				return [
					compChip( group.component ) || escapeHtml( DASH ),
					num( group.count ),
					{ html: barHtml( group.bytes, max, bytes( group.bytes ) ), cls: 'blt-perf-bar-cell' },
					'<span class="blt-perf-sub">' + escapeHtml( parts.join( ' · ' ) ) + '</span>'
				];
			} ), 'blt-lab-resources' );

			return html + labui.waterfallHtml( lab );
		},

		// The 40 slowest or largest requests against one time axis, with the paint milestones marked.
		waterfallHtml: function ( lab ) {
			var list = ( lab.resources || [] ).map( function ( item, index ) {
				return { item: item, index: index, duration: Math.max( 0, Number( item.end ) - Number( item.start ) ), size: labui.resourceBytes( item ) };
			} );
			var picked = {};
			list.slice().sort( function ( a, b ) {
				return b.duration - a.duration;
			} ).slice( 0, 30 ).forEach( function ( row ) {
				picked[ row.index ] = true;
			} );
			list.slice().sort( function ( a, b ) {
				return b.size - a.size;
			} ).slice( 0, 12 ).forEach( function ( row ) {
				if ( Object.keys( picked ).length < 40 ) {
					picked[ row.index ] = true;
				}
			} );
			var rows = list.filter( function ( row ) {
				return picked[ row.index ];
			} ).sort( function ( a, b ) {
				return Number( a.item.start ) - Number( b.item.start );
			} );

			var nav = lab.nav || {};
			var marks = [];
			if ( isNumber( nav.fcp ) ) {
				marks.push( [ 'fcp', 'FCP', Number( nav.fcp ) ] );
			}
			if ( lab.lcp && isNumber( lab.lcp.time ) ) {
				marks.push( [ 'lcp', 'LCP', Number( lab.lcp.time ) ] );
			}
			if ( isNumber( nav.load ) && Number( nav.load ) > 0 ) {
				marks.push( [ 'load', t( 'labMarkLoad' ), Number( nav.load ) ] );
			}
			var axis = rows.reduce( function ( max, row ) {
				return Math.max( max, Number( row.item.end ) || 0 );
			}, 0 );
			marks.forEach( function ( mark ) {
				axis = Math.max( axis, mark[ 2 ] );
			} );
			if ( ! axis || ! rows.length ) {
				return '';
			}
			axis = Math.ceil( axis / 100 ) * 100;

			function pct( value ) {
				return Math.max( 0, Math.min( 100, ( 100 * value ) / axis ) );
			}

			var html = '<h4 class="blt-lab-h4">' + escapeHtml( t( 'labWaterfall' ) ) + '</h4><p class="description">' + escapeHtml( t( 'labWaterfallHint' ) ) + '</p>';
			html += '<div class="blt-lab-wf"><div class="blt-lab-wf-legend">' +
				'<span><i class="blt-lab-wf-key"></i>' + escapeHtml( t( 'labLegendNormal' ) ) + '</span>' +
				'<span><i class="blt-lab-wf-key is-blocking"></i>' + escapeHtml( t( 'labLegendBlocking' ) ) + '</span>' +
				'<span><i class="blt-lab-wf-key is-cached"></i>' + escapeHtml( t( 'labLegendCached' ) ) + '</span>';
			marks.forEach( function ( mark ) {
				html += '<span><i class="blt-lab-wf-line is-' + mark[ 0 ] + '"></i>' + escapeHtml( mark[ 1 ] + ' ' + ms( mark[ 2 ] ) ) + '</span>';
			} );
			html += '</div><ol class="blt-lab-wf-rows">';
			html += '<li class="blt-lab-wf-axis" aria-hidden="true"><span></span><span class="blt-lab-wf-ticks">';
			[ 0, 25, 50, 75, 100 ].forEach( function ( at ) {
				html += '<span style="left:' + at + '%">' + escapeHtml( ms( ( axis * at ) / 100 ) ) + '</span>';
			} );
			html += '</span><span></span></li>';
			rows.forEach( function ( row ) {
				var item = row.item;
				var start = pct( Number( item.start ) || 0 );
				var width = Math.max( 0.6, pct( Number( item.end ) || 0 ) - start );
				var cls = item.blocking ? ' is-blocking' : ( item.cached ? ' is-cached' : '' );
				var name = fileName( item.url ) || item.url;
				if ( /^i?frame$/.test( item.type ) ) {
					name = ( hostOf( item.url ) || '' ) + '/' + name;
				}
				html += '<li class="blt-lab-wf-row"><span class="blt-lab-wf-name" title="' + escapeHtml( item.url ) + '">' + escapeHtml( name ) + '</span>' +
					'<span class="blt-lab-wf-track">';
				marks.forEach( function ( mark ) {
					html += '<span class="blt-lab-wf-mark is-' + mark[ 0 ] + '" style="left:' + pct( mark[ 2 ] ).toFixed( 2 ) + '%"></span>';
				} );
				html += '<span class="blt-lab-wf-bar' + cls + '" style="left:' + start.toFixed( 2 ) + '%;width:' + width.toFixed( 2 ) + '%" title="' + escapeHtml( ms( item.start ) + ' → ' + ms( item.end ) ) + '"></span></span>' +
					'<span class="blt-lab-wf-size">' + escapeHtml( row.size ? bytes( row.size ) : DASH ) + '</span></li>';
			} );
			return html + '</ol></div>';
		},

		// Secondary tiles: weight, requests, DOM, blocking, unused CSS.
		pageTilesHtml: function ( lab, summary ) {
			summary = summary && 'object' === typeof summary && ! Array.isArray( summary ) ? summary : {};
			var resources = lab.resources || [];
			var weight = summary.bytes && isNumber( summary.bytes.total ) ? Number( summary.bytes.total ) : resources.reduce( function ( sum, item ) {
				return sum + labui.resourceBytes( item );
			}, 0 );
			var blocking = isNumber( summary.blocking_count ) ? Number( summary.blocking_count ) : resources.filter( function ( item ) {
				return item.blocking;
			} ).length;
			var unused = isNumber( summary.unused_css_bytes ) ? Number( summary.unused_css_bytes ) : sumBy( lab.css || [], 'unusedBytes' );
			var nodes = isNumber( summary.dom_nodes ) ? Number( summary.dom_nodes ) : ( lab.dom ? Number( lab.dom.nodes ) : null );
			return '<div class="blt-stats blt-perf-summary">' +
				statTile( t( 'labWeight' ), bytes( weight ), plural( isNumber( summary.requests ) ? summary.requests : resources.length, 'labRequestOne', 'labRequestMany' ) ) +
				statTile( t( 'tileDom' ), num( nodes ), lab.dom && lab.dom.depth ? tf( 'depthSub', lab.dom.depth ) : '' ) +
				statTile( t( 'labBlockingTile' ), num( blocking ), blocking ? t( 'renderBlockingHint' ) : t( 'noneBlocking' ), blocking ? 'is-warn' : 'is-good' ) +
				statTile( t( 'labUnusedTile' ), bytes( unused ), t( 'labUnusedSub' ) ) + '</div>';
		},

		/**
		 * The whole view for one viewport.
		 */
		viewHtml: function ( lab, summary ) {
			var metrics = labui.metrics( lab, summary );
			var html = labui.warningsHtml( lab );
			if ( ! labui.measured( lab ) ) {
				return html;
			}
			html += labui.tilesHtml( metrics );
			html += '<p class="description blt-lab-caveat">' + escapeHtml( t( 'labCaveat' ) ) + '</p>';
			html += labui.pageTilesHtml( lab, summary );
			html += labui.lcpHtml( lab );
			html += labui.shiftsHtml( lab );
			html += labui.blockingHtml( lab );
			html += labui.cssHtml( lab );
			html += labui.mainThreadHtml( lab, metrics );
			html += labui.imagesHtml( lab );
			html += labui.resourcesHtml( lab, summary );
			return html;
		}
	};

	/* ------------------------------------------------------------------ */
	/* Findings: merge lab findings into a list                            */
	/* ------------------------------------------------------------------ */

	var SEVERITY_RANK = { critical: 0, warning: 1, notice: 2, pass: 3 };

	// Same weighing as BLT_Optimized_Findings::sort(): a count is a weaker signal than time or weight.
	function impactWeight( item ) {
		var impact = item.impact || {};
		var value = Number( impact.value ) || 0;
		return 'ms' === impact.type ? value : ( 'bytes' === impact.type ? value / 1024 : ( 'count' === impact.type ? value / 10 : 0 ) );
	}

	function mergeFindings( base, extra ) {
		var byKey = {};
		var list = [];
		( base || [] ).concat( extra || [] ).forEach( function ( item ) {
			if ( item && item.key && byKey[ item.key ] ) {
				list[ list.indexOf( byKey[ item.key ] ) ] = item;
			} else {
				list.push( item );
			}
			if ( item && item.key ) {
				byKey[ item.key ] = item;
			}
		} );
		return list.map( function ( item, index ) {
			return { item: item, index: index };
		} ).sort( function ( a, b ) {
			var ra = undefined === SEVERITY_RANK[ a.item.severity ] ? 9 : SEVERITY_RANK[ a.item.severity ];
			var rb = undefined === SEVERITY_RANK[ b.item.severity ] ? 9 : SEVERITY_RANK[ b.item.severity ];
			return ra - rb || impactWeight( b.item ) - impactWeight( a.item ) || a.index - b.index;
		} ).map( function ( row ) {
			return row.item;
		} );
	}

	function withoutLabFindings( list ) {
		return ( list || [] ).filter( function ( item ) {
			return 'lab' !== item.source;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Performance Scan screen                                             */
	/* ------------------------------------------------------------------ */

	var MAX_RETRIES = 6;
	var STEP_ORDER = [ 'health', 'media', 'pages', 'lab', 'psi', 'compile' ];

	var scan = {
		running: false,
		polling: false,
		failures: 0,
		payload: null,
		sawPsi: false,
		sawLab: false,
		labRunning: false,
		labHandle: null,
		filter: { category: '', severity: '', dismissed: false },
		open: {},
		fixed: {},

		init: function () {
			if ( ! $( '#blt-perf-scan' ).length ) {
				return;
			}

			$( '#blt-perf-start' ).on( 'click', scan.start );
			$( '#blt-perf-results' ).on( 'click', '.blt-perf-run', scan.start );
			$( '#blt-perf-cancel' ).on( 'click', scan.cancel );
			$( '#blt-perf-resume' ).on( 'click', scan.resume );

			var $results = $( '#blt-perf-results' );

			// Category tiles and chips both drive the same filter.
			$results.on( 'click', '.blt-perf-tile', function () {
				var category = $( this ).attr( 'data-category' );
				scan.setFilter( 'category', scan.filter.category === category ? '' : category );
			} );
			$results.on( 'click', '.blt-perf-chip', function () {
				scan.setFilter( $( this ).attr( 'data-filter' ), $( this ).attr( 'data-value' ) );
			} );
			$results.on( 'change', '#blt-perf-show-dismissed', function () {
				scan.filter.dismissed = this.checked;
				scan.renderFindings();
			} );

			scan.loadReport();
			api.post( 'perf_status' )
				.done( function ( response ) {
					if ( response && response.success && 'running' === response.data.status ) {
						// A scan survives a page reload: pick it up where it is.
						// (Mid lab step, the queue left to measure comes with it.)
						scan.setRunning( true );
						scan.showProgress( response.data );
						scan.advance( response.data, true );
					}
				} );
		},

		/* --- Running a scan ---------------------------------------------- */

		start: function () {
			if ( scan.running ) {
				return;
			}
			scan.clearNotices();
			$( '#blt-perf-start, .blt-perf-run' ).prop( 'disabled', true );

			api.post( 'perf_start' )
				.done( function ( response ) {
					if ( response && response.success ) {
						scan.failures = 0;
						scan.setRunning( true );
						scan.showProgress( response.data );
						scan.advance( response.data, true );
						return;
					}
					$( '#blt-perf-start, .blt-perf-run' ).prop( 'disabled', false );
					scan.notice( response && response.data && response.data.message ? response.data.message : t( 'error' ), 'is-error' );
					// "Already in progress" after a reload: adopt the running scan.
					api.post( 'perf_status' ).done( function ( status ) {
						if ( status && status.success && 'running' === status.data.status ) {
							scan.setRunning( true );
							scan.showProgress( status.data );
							scan.advance( status.data, true );
						}
					} );
				} )
				.fail( function () {
					$( '#blt-perf-start, .blt-perf-run' ).prop( 'disabled', false );
					scan.notice( t( 'error' ), 'is-error' );
				} );
		},

		cancel: function () {
			$( '#blt-perf-cancel' ).prop( 'disabled', true );
			scan.stopLab();
			api.post( 'perf_cancel' ).always( function () {
				$( '#blt-perf-cancel' ).prop( 'disabled', false );
				scan.setRunning( false );
				$( '#blt-perf-progress' ).prop( 'hidden', true );
				scan.notice( t( 'scanCancelled' ) );
			} );
		},

		resume: function () {
			scan.failures = 0;
			$( '#blt-perf-resume' ).prop( 'hidden', true );
			scan.clearNotices();
			scan.setRunning( true );
			scan.tick();
		},

		// One time-boxed server slice per request, until the scan reports done.
		tick: function () {
			if ( scan.polling || ! scan.running || scan.labRunning ) {
				return;
			}
			scan.polling = true;

			api.post( 'perf_tick' )
				.done( function ( response ) {
					scan.polling = false;
					if ( ! scan.running ) {
						return;
					}
					if ( ! response || ! response.success ) {
						scan.retry( response && response.data && response.data.message );
						return;
					}
					scan.failures = 0;
					scan.showProgress( response.data );
					scan.advance( response.data, false );
				} )
				.fail( function () {
					scan.polling = false;
					scan.retry();
				} );
		},

		// What to do with a progress payload: finish, hand over to the browser's lab
		// step (the server only waits for it), or keep ticking.
		advance: function ( progress, immediate ) {
			if ( 'running' !== progress.status ) {
				scan.finish( progress );
				return;
			}
			if ( 'lab' === progress.step && progress.labQueue && progress.labQueue.length ) {
				scan.runLabStep( progress );
				return;
			}
			if ( immediate ) {
				scan.tick();
			} else {
				window.setTimeout( scan.tick, 200 );
			}
		},

		/* --- Lab step: this browser measures the pages ------------------- */

		// Runs the queue the server handed over, one page at a time on the mobile
		// viewport, then tells the server it is done and resumes ticking. The queue
		// is whatever is still unmeasured, so a reload mid-step just continues.
		runLabStep: function ( progress ) {
			if ( scan.labRunning ) {
				return;
			}
			var queue = progress.labQueue;
			var total = Number( progress.pagesTotal ) || queue.length;
			var already = Math.max( 0, total - queue.length );
			var index = 0;
			scan.labRunning = true;

			function next() {
				if ( ! scan.running ) {
					scan.labRunning = false;
					return;
				}
				if ( index >= queue.length ) {
					scan.endLabStep();
					return;
				}
				var item = queue[ index ];
				var position = already + index + 1;
				// The lab step takes about a sixth of the bar; creep through it page by page.
				scan.setBar( Math.min( 99, Number( progress.percent ) + Math.floor( ( ( 100 / 6 ) * ( position - 1 ) ) / Math.max( 1, total ) ) ) );
				$( '#blt-perf-label' ).text( tf( 'labScanPage', position, total ) );

				scan.labHandle = labRunner.run( {
					url: item.url,
					viewport: 'mobile',
					scan: true,
					$mount: $( '#blt-perf-lab-stage' ),
					title: ( item.label ? item.label + ' · ' : '' ) + item.url,
					step: position,
					steps: total
				} );
				scan.labHandle.promise
					.done( function () {
						index++;
						next();
					} )
					.fail( function ( error ) {
						if ( error && error.aborted ) {
							scan.labRunning = false;
							return;
						}
						scan.notice( tf( 'labScanSkipped', item.label || item.url, error && error.message ? error.message : t( 'error' ) ), 'is-warning' );
						index++;
						next();
					} );
			}

			next();
		},

		endLabStep: function () {
			labRunner.clearStage( $( '#blt-perf-lab-stage' ) );
			scan.labHandle = null;
			scan.labRunning = false;
			scan.finishLabStep();
		},

		// perf_lab_done moves the server past the lab step; if it cannot be reached,
		// tick() would do the same for an empty queue, so fall back to that.
		finishLabStep: function () {
			api.post( 'perf_lab_done' )
				.done( function ( response ) {
					if ( ! scan.running ) {
						return;
					}
					if ( ! response || ! response.success ) {
						scan.retry( response && response.data && response.data.message );
						return;
					}
					scan.failures = 0;
					scan.showProgress( response.data );
					scan.advance( response.data, true );
				} )
				.fail( function () {
					if ( scan.running ) {
						scan.retry();
					}
				} );
		},

		stopLab: function () {
			if ( scan.labHandle ) {
				scan.labHandle.abort();
			}
			scan.labHandle = null;
			scan.labRunning = false;
			labRunner.clearStage( $( '#blt-perf-lab-stage' ) );
		},

		// Back off on failure; after a few attempts hand control to the user.
		retry: function ( message ) {
			scan.failures++;
			if ( scan.failures > MAX_RETRIES ) {
				scan.setRunning( false );
				$( '#blt-perf-label' ).text( t( 'scanStalled' ) );
				$( '#blt-perf-resume' ).prop( 'hidden', false );
				scan.notice( message || t( 'error' ), 'is-error' );
				return;
			}
			var delay = Math.min( 30000, 1500 * Math.pow( 2, scan.failures - 1 ) );
			$( '#blt-perf-label' ).text( tf( 'retrying', Math.round( delay / 1000 ) ) );
			window.setTimeout( scan.tick, delay );
		},

		finish: function ( progress ) {
			scan.setRunning( false );
			labRunner.clearStage( $( '#blt-perf-lab-stage' ) );
			if ( 'completed' === progress.status ) {
				$( '#blt-perf-label' ).text( t( 'scanComplete' ) );
				scan.setBar( 100 );
				scan.loadReport().always( function () {
					window.setTimeout( function () {
						if ( ! scan.running ) {
							$( '#blt-perf-progress' ).prop( 'hidden', true );
						}
					}, 1200 );
				} );
			} else {
				$( '#blt-perf-progress' ).prop( 'hidden', true );
				scan.notice( t( 'scanCancelled' ) );
			}
		},

		setRunning: function ( running ) {
			scan.running = running;
			$( '#blt-perf-start, .blt-perf-run' ).prop( 'disabled', running );
			$( '#blt-perf-cancel' ).prop( 'hidden', ! running );
			$( '#blt-perf-scan' ).toggleClass( 'is-scanning', running );
		},

		setBar: function ( percent ) {
			percent = Math.max( 0, Math.min( 100, Number( percent ) || 0 ) );
			$( '#blt-perf-bar' ).css( 'width', percent + '%' );
			$( '#blt-perf-progress .blt-progress' ).attr( { role: 'progressbar', 'aria-valuemin': 0, 'aria-valuemax': 100, 'aria-valuenow': percent } );
			$( '#blt-perf-percent' ).text( percent + '%' );
		},

		showProgress: function ( progress ) {
			$( '#blt-perf-progress' ).prop( 'hidden', false );
			$( '#blt-perf-label' ).text( progress.label || t( 'scanning' ) );
			scan.setBar( progress.percent );
			scan.renderSteps( progress.step );
			if ( progress.errors && progress.errors.length ) {
				scan.showErrors( progress.errors );
			}
		},

		// health -> media -> pages -> (lab) -> (psi) -> report, as a row of step chips.
		renderSteps: function ( step ) {
			if ( 'psi' === step ) {
				scan.sawPsi = true;
			}
			if ( 'lab' === step ) {
				scan.sawLab = true;
			}
			var current = STEP_ORDER.indexOf( step );
			var html = '';
			STEP_ORDER.forEach( function ( id, index ) {
				if ( ( 'psi' === id && ! scan.sawPsi ) || ( 'lab' === id && ! scan.sawLab ) ) {
					return;
				}
				var cls = current < 0 || index > current ? '' : ( index === current ? ' is-current' : ' is-done' );
				html += '<li class="blt-perf-step' + cls + '"' + ( index === current ? ' aria-current="step"' : '' ) + '>' + escapeHtml( t( 'step_' + id ) ) + '</li>';
			} );
			$( '#blt-perf-steps' ).html( html );
		},

		/* --- Notices ----------------------------------------------------- */

		notice: function ( message, cls ) {
			$( '#blt-perf-notices' ).append( callout( '', message, cls ) );
		},

		clearNotices: function () {
			$( '#blt-perf-notices' ).empty();
		},

		// Non-fatal problems the scan worked around (a page that failed to load, a quota error).
		showErrors: function ( errors ) {
			$( '#blt-perf-notices .blt-perf-errors' ).remove();
			if ( ! errors || ! errors.length ) {
				return;
			}
			var html = '<div class="blt-callout is-warning blt-perf-errors" role="status"><strong>' + escapeHtml( t( 'scanErrors' ) ) + '</strong>' +
				'<ul class="blt-error-list">';
			errors.forEach( function ( error ) {
				html += '<li>' + escapeHtml( error ) + '</li>';
			} );
			$( '#blt-perf-notices' ).prepend( html + '</ul></div>' );
		},

		/* --- Report ------------------------------------------------------ */

		loadReport: function () {
			return api.post( 'perf_report' )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						scan.notice( t( 'error' ), 'is-error' );
						return;
					}
					scan.payload = response.data;
					state.backupAcked = !! response.data.backupAcked;
					scan.renderReport();
				} )
				.fail( function () {
					scan.notice( t( 'error' ), 'is-error' );
					$( '#blt-perf-results' ).empty();
				} );
		},

		renderReport: function () {
			var data = scan.payload;
			var report = data.report;
			var $results = $( '#blt-perf-results' );
			var $meta = $( '#blt-perf-meta' );

			if ( ! report ) {
				$results.html( '<div class="blt-card"><div class="blt-card-body">' +
					'<div class="blt-empty"><span class="blt-empty-title">' + escapeHtml( t( 'noReportTitle' ) ) + '</span>' +
					'<span>' + escapeHtml( t( 'noReport' ) ) + '</span>' +
					'<button type="button" class="button button-primary blt-perf-run"' + ( scan.running ? ' disabled' : '' ) + '>' + escapeHtml( t( 'runScan' ) ) + '</button></div></div></div>' );
				$( '#blt-perf-export' ).prop( 'hidden', true );
				$meta.empty();
				return;
			}

			var exportUrl = safeUrl( decodeAmp( data.exportUrl ) );
			$( '#blt-perf-export' ).attr( 'href', exportUrl || '#' ).prop( 'hidden', ! exportUrl );

			var metaParts = [ tf( 'lastScan', report.generatedHuman || fmtDate( report.generated_at ) ) ];
			if ( report.duration ) {
				metaParts.push( tf( 'tookSeconds', report.duration ) );
			}
			if ( report.pages && report.pages.length ) {
				metaParts.push( plural( report.pages.length, 'pageProfiledOne', 'pageProfiledMany' ) );
			}
			$meta.text( metaParts.join( ' · ' ) );

			scan.showErrors( report.errors );

			var html = '<div id="blt-perf-score"></div><div id="blt-perf-lab"></div><div id="blt-perf-psi"></div>';
			html += '<div class="blt-card blt-perf-findings-card" id="blt-perf-findings-card">' +
				'<div class="blt-card-header"><h2>' + escapeHtml( t( 'topFixes' ) ) + '</h2>' +
				'<p>' + escapeHtml( t( 'topFixesHint' ) ) + '</p>' +
				'<div class="blt-card-header-badges" id="blt-perf-findings-badges"></div></div>' +
				'<div class="blt-toolbar blt-perf-filters" id="blt-perf-filters"></div>' +
				'<div id="blt-perf-findings"></div></div>';
			html += scan.componentsHtml( report.components );
			html += scan.pagesHtml( report.pages );
			$results.html( html );

			scan.renderScores();
			scan.renderLab();
			scan.renderPsi();
			scan.renderFilters();
			scan.renderFindings();
		},

		/* --- Scores ------------------------------------------------------ */

		renderScores: function () {
			var data = scan.payload;
			var scores = data.report.scores || { overall: 0, categories: {} };
			var categories = data.categories || {};
			var html = '<div class="blt-card blt-perf-scorecard"><div class="blt-card-body"><div class="blt-perf-score">';

			var overall = Number( scores.overall ) || 0;
			html += '<div class="blt-perf-overall">' + ring( overall ) +
				'<span class="blt-perf-overall-label">' + escapeHtml( t( 'overall' ) ) + '</span>' +
				badge( overall >= 90 ? t( 'psiGood' ) : ( overall >= 50 ? t( 'psiMid' ) : t( 'psiPoor' ) ), overall >= 90 ? 'blt-badge-confirmed' : ( overall >= 50 ? 'blt-badge-pending' : 'blt-badge-cancelled' ) ) + '</div>';

			html += '<div class="blt-stats blt-perf-tiles" role="group" aria-label="' + escapeHtml( t( 'filterCategory' ) ) + '">';
			Object.keys( categories ).forEach( function ( category ) {
				var entry = scores.categories && scores.categories[ category ] ? scores.categories[ category ] : { score: 100, critical: 0, warning: 0, notice: 0 };
				var parts = [];
				[ 'critical', 'warning', 'notice' ].forEach( function ( severity ) {
					if ( entry[ severity ] ) {
						parts.push( t( 'sev_' + severity ) + ' ' + entry[ severity ] );
					}
				} );
				var pressed = scan.filter.category === category;
				html += '<button type="button" class="blt-stat blt-perf-tile ' + scoreClass( entry.score ) + '" data-category="' + escapeHtml( category ) + '" aria-pressed="' + ( pressed ? 'true' : 'false' ) + '">' +
					'<span class="blt-stat-label">' + escapeHtml( categories[ category ] ) + '</span>' +
					'<span class="blt-stat-value">' + escapeHtml( entry.score ) + '<small>/100</small></span>' +
					'<span class="blt-stat-sub">' + escapeHtml( parts.length ? parts.join( ' · ' ) : t( 'noIssuesShort' ) ) + '</span></button>';
			} );
			html += '</div></div></div></div>';

			$( '#blt-perf-score' ).html( html );
		},

		// Median lab metrics across the scanned pages (mobile viewport), styled like the PSI tiles.
		renderLab: function () {
			var $mount = $( '#blt-perf-lab' );
			var measured = ( scan.payload.report.pages || [] ).filter( function ( page ) {
				return page.lab && page.lab.mobile && labui.measuredSummary( page.lab.mobile );
			} );
			if ( ! measured.length ) {
				$mount.empty();
				return;
			}
			var html = '<div class="blt-card"><div class="blt-card-header"><h2>' + escapeHtml( t( 'labResultsTitle' ) ) + '</h2>' +
				'<p>' + escapeHtml( tf( 'labResultsHint', plural( measured.length, 'labPageOne', 'labPageMany' ) ) ) + '</p></div><div class="blt-card-body">';
			html += '<div class="blt-stats blt-perf-metrics blt-lab-metrics">';
			LAB_METRICS.forEach( function ( key ) {
				var value = median( measured.map( function ( page ) {
					return page.lab.mobile[ key ];
				} ) );
				html += labui.tile( key, value, labRate( key, value ), '' );
			} );
			html += '</div><p class="description blt-lab-caveat">' + escapeHtml( t( 'labCaveat' ) ) + '</p></div></div>';
			$mount.html( html );
		},

		renderPsi: function () {
			var entries = scan.payload.report.psi;
			var $mount = $( '#blt-perf-psi' );
			if ( ! entries || ! entries.length ) {
				$mount.empty();
				return;
			}
			var html = '<div class="blt-card"><div class="blt-card-header"><h2>' + escapeHtml( t( 'psiTitle' ) ) + '</h2>' +
				'<p>' + escapeHtml( t( 'psiHint' ) ) + '</p></div><div class="blt-card-body">';
			entries.forEach( function ( entry ) {
				html += psi.block( entry );
			} );
			$mount.html( html + '</div></div>' );
		},

		/* --- Filters + findings ------------------------------------------ */

		counts: function () {
			var dismissed = {};
			( scan.payload.dismissed || [] ).forEach( function ( key ) {
				dismissed[ key ] = true;
			} );
			var counts = { category: {}, severity: { critical: 0, warning: 0, notice: 0 }, total: 0, dismissed: 0 };
			scan.payload.report.findings.forEach( function ( item ) {
				if ( 'pass' === item.severity ) {
					return;
				}
				if ( dismissed[ item.key ] ) {
					counts.dismissed++;
					return;
				}
				counts.total++;
				counts.category[ item.category ] = ( counts.category[ item.category ] || 0 ) + 1;
				counts.severity[ item.severity ] = ( counts.severity[ item.severity ] || 0 ) + 1;
			} );
			return counts;
		},

		chip: function ( filter, value, label, count, pressed ) {
			return '<button type="button" class="blt-perf-chip" data-filter="' + filter + '" data-value="' + escapeHtml( value ) + '" aria-pressed="' + ( pressed ? 'true' : 'false' ) + '">' +
				escapeHtml( label ) + ( undefined === count ? '' : ' <span class="blt-perf-chip-count">' + escapeHtml( count ) + '</span>' ) + '</button>';
		},

		renderFilters: function () {
			var counts = scan.counts();
			var categories = scan.payload.categories || {};
			var html = '<div class="blt-perf-filtergroup" role="group" aria-label="' + escapeHtml( t( 'filterCategory' ) ) + '">';
			html += scan.chip( 'category', '', t( 'allCategories' ), counts.total, ! scan.filter.category );
			Object.keys( categories ).forEach( function ( category ) {
				html += scan.chip( 'category', category, categories[ category ], counts.category[ category ] || 0, scan.filter.category === category );
			} );
			html += '</div><div class="blt-perf-filtergroup" role="group" aria-label="' + escapeHtml( t( 'filterSeverity' ) ) + '">';
			html += scan.chip( 'severity', '', t( 'allSeverities' ), undefined, ! scan.filter.severity );
			[ 'critical', 'warning', 'notice' ].forEach( function ( severity ) {
				html += scan.chip( 'severity', severity, t( 'sev_' + severity ), counts.severity[ severity ] || 0, scan.filter.severity === severity );
			} );
			html += '</div><span class="blt-toolbar-spacer"></span>' +
				'<label class="blt-perf-check"><input type="checkbox" id="blt-perf-show-dismissed"' + ( scan.filter.dismissed ? ' checked' : '' ) + ' /> ' +
				escapeHtml( tf( 'showDismissed', counts.dismissed ) ) + '</label>';
			$( '#blt-perf-filters' ).html( html );

			var badges = '';
			[ 'critical', 'warning', 'notice' ].forEach( function ( severity ) {
				if ( counts.severity[ severity ] ) {
					badges += badge( t( 'sev_' + severity ) + ' ' + counts.severity[ severity ], SEVERITY_BADGE[ severity ] );
				}
			} );
			$( '#blt-perf-findings-badges' ).html( badges );
		},

		setFilter: function ( type, value ) {
			scan.filter[ type ] = value;
			scan.renderScores();
			scan.renderFilters();
			scan.renderFindings();
		},

		renderFindings: function () {
			var data = scan.payload;
			findings.mount( $( '#blt-perf-findings' ), {
				list: data.report.findings,
				dismissed: data.dismissed,
				categories: data.categories,
				canDismiss: true,
				filter: scan.filter,
				open: scan.open,
				fixed: scan.fixed,
				fixedLabel: t( 'fixedRescan' ),
				onDismiss: scan.dismiss
			} );
		},

		// Dismissing re-scores from the stored findings, so no re-scan is needed.
		dismiss: function ( key, dismissed, $button ) {
			$button.prop( 'disabled', true );
			api.post( 'perf_dismiss', { key: key, dismissed: dismissed ? 1 : 0 } )
				.done( function ( response ) {
					if ( ! response || ! response.success ) {
						$button.prop( 'disabled', false );
						window.alert( response && response.data && response.data.message ? response.data.message : t( 'error' ) );
						return;
					}
					scan.payload = response.data;
					scan.renderScores();
					scan.renderFilters();
					scan.renderFindings();
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					window.alert( t( 'error' ) );
				} );
		},

		/* --- Who's slowing the site down --------------------------------- */

		componentsHtml: function ( components ) {
			var rows = ( components || [] ).map( function ( row ) {
				return $.extend( {}, row, { total: ( Number( row.load_ms ) || 0 ) + ( Number( row.query_ms ) || 0 ) + ( Number( row.http_ms ) || 0 ) } );
			} );
			rows.sort( function ( a, b ) {
				return b.total - a.total;
			} );
			rows = rows.slice( 0, 15 );

			var html = '<div class="blt-card"><div class="blt-card-header"><h2>' + escapeHtml( t( 'whoSlow' ) ) + '</h2><p>' + escapeHtml( t( 'whoSlowHint' ) ) + '</p></div>';
			if ( ! rows.length ) {
				return html + '<div class="blt-card-body">' + emptyState( t( 'noComponents' ), t( 'noComponentsHint' ) ) + '</div></div>';
			}

			var max = rows[ 0 ].total;
			var body = rows.map( function ( row ) {
				return [
					compChip( row.component ) || escapeHtml( DASH ),
					{ html: barHtml( row.total, max, ms( row.total ) ), cls: 'blt-perf-bar-cell' },
					ms( row.load_ms ),
					twoLine( ms( row.query_ms ), plural( row.query_count || 0, 'queryOne', 'queryMany' ) ),
					ms( row.http_ms ),
					bytes( row.js_bytes ),
					bytes( row.css_bytes ),
					num( row.errors || 0 ),
					num( row.pages || 0 )
				];
			} );

			html += tableHtml( [
				t( 'colComponent' ),
				t( 'colTotalTime' ),
				{ label: t( 'colPluginLoad' ), cls: 'num' },
				{ label: t( 'colQueries' ), cls: 'num' },
				{ label: t( 'colHttp' ), cls: 'num' },
				{ label: 'JS', cls: 'num' },
				{ label: 'CSS', cls: 'num' },
				{ label: t( 'colErrors' ), cls: 'num' },
				{ label: t( 'colPages' ), cls: 'num' }
			], body, 'blt-perf-components', true );
			return html + '</div>';
		},

		/* --- Pages scanned ----------------------------------------------- */

		profileUrl: function ( url ) {
			return bltPerf.profilerUrl + '&url=' + encodeURIComponent( url );
		},

		statusBadge: function ( status ) {
			status = Number( status ) || 0;
			if ( ! status ) {
				return badge( t( 'noResponse' ), 'blt-badge-cancelled' );
			}
			return badge( String( status ), status >= 400 ? 'blt-badge-cancelled' : ( status >= 300 ? 'blt-badge-pending' : 'blt-badge-confirmed' ) );
		},

		pagesHtml: function ( pages ) {
			var html = '<div class="blt-card"><div class="blt-card-header"><h2>' + escapeHtml( t( 'pagesScanned' ) ) + '</h2><p>' + escapeHtml( t( 'pagesScannedHint' ) ) + '</p></div>';
			if ( ! pages || ! pages.length ) {
				return html + '<div class="blt-card-body">' + emptyState( t( 'noPages' ) ) + '</div></div>';
			}

			var errorLabels = { page_cache: t( 'errPageCache' ), not_received: t( 'errNotReceived' ), http_error: t( 'errHttp' ) };
			var hasLab = pages.some( function ( page ) {
				return !! page.lab;
			} );
			var body = pages.map( function ( page ) {
				var label = page.label || page.url;
				var note = '';
				if ( page.error ) {
					note = '<span class="blt-perf-sub is-warn">' + escapeHtml( errorLabels[ page.error ] || page.error ) + '</span>';
				}
				var cells = [
					'<span class="blt-perf-main">' + escapeHtml( label ) + '</span><span class="blt-perf-sub">' + link( page.url, page.url, true ) + '</span>' + note,
					scan.statusBadge( page.status )
				];
				if ( hasLab ) {
					[ 'lcp', 'cls', 'tbt' ].forEach( function ( key ) {
						cells.push( { html: labui.cellHtml( page.lab && page.lab.mobile, key ), cls: 'num' } );
					} );
				}
				return cells.concat( [
					ms( page.ttfb_ms ),
					ms( page.php_ms ),
					twoLine( num( page.queries ), isNumber( page.query_ms ) ? ms( page.query_ms ) : '' ),
					page.profiled ? bytes( page.js_bytes ) : DASH,
					page.profiled ? bytes( page.css_bytes ) : DASH,
					bytes( page.image_bytes ),
					num( page.dom_nodes ),
					num( page.requests ),
					'<a class="button button-small" href="' + escapeHtml( scan.profileUrl( page.url ) ) + '">' + escapeHtml( t( 'profile' ) ) + '</a>'
				] );
			} );

			var headers = [
				t( 'colPage' ),
				t( 'colStatus' )
			];
			if ( hasLab ) {
				headers.push( { label: 'LCP', cls: 'num', title: t( 'labColMobile' ) }, { label: 'CLS', cls: 'num', title: t( 'labColMobile' ) }, { label: 'TBT', cls: 'num', title: t( 'labColMobile' ) } );
			}
			headers = headers.concat( [
				{ label: 'TTFB', cls: 'num' },
				{ label: t( 'colPhp' ), cls: 'num' },
				{ label: t( 'colQueries' ), cls: 'num' },
				{ label: 'JS', cls: 'num' },
				{ label: 'CSS', cls: 'num' },
				{ label: t( 'colImages' ), cls: 'num' },
				{ label: t( 'colDom' ), cls: 'num' },
				{ label: t( 'colRequests' ), cls: 'num' },
				''
			] );
			html += tableHtml( headers, body, 'blt-perf-pages', true );
			if ( hasLab ) {
				html += '<p class="blt-perf-tablenote">' + escapeHtml( t( 'labTableNote' ) ) + '</p>';
			}
			return html + '</div>';
		}
	};

	/* ------------------------------------------------------------------ */
	/* Page Profiler screen                                                */
	/* ------------------------------------------------------------------ */

	var TABS = [ 'lab', 'components', 'queries', 'assets', 'http', 'errors', 'content', 'timeline', 'raw' ];

	var profiler = {
		data: null,
		pages: { samples: [], stored: [] },
		activeTab: 'components',
		tabChosen: false,
		tabs: TABS,
		labView: 'mobile',
		labCache: {},
		labRun: null,
		rendered: {},
		blobUrl: '',
		findingsState: { open: {}, fixed: {} },

		init: function () {
			if ( ! $( '#blt-profiler-url' ).length ) {
				return;
			}

			var requested = queryParam( 'url' );
			$( '#blt-profiler-url' ).val( requested || bltPerf.homeUrl );

			$( '#blt-profiler-run' ).on( 'click', profiler.run );
			$( '#blt-profiler-lab-run' ).on( 'click', profiler.runLab );
			$( '#blt-profiler-psi-run' ).on( 'click', profiler.runPsi );
			$( '#blt-profiler-url' ).on( 'keydown', function ( event ) {
				if ( 13 === event.which ) {
					event.preventDefault();
					profiler.run();
				}
			} );
			$( '#blt-profiler-pick' ).on( 'change', function () {
				if ( this.value ) {
					$( '#blt-profiler-url' ).val( this.value );
				}
			} );

			var $result = $( '#blt-profiler-result' );
			$result.on( 'click', '.blt-perf-subtab', function () {
				profiler.tabChosen = true;
				profiler.selectTab( $( this ).attr( 'data-tab' ), true );
			} );
			$result.on( 'click', '.blt-lab-switch button', function () {
				profiler.labView = $( this ).attr( 'data-view' );
				$( '#blt-panel-lab' ).html( profiler.panel_lab( profiler.data.page || {} ) );
			} );
			$result.on( 'keydown', '.blt-perf-subtab', profiler.tabKeys );

			profiler.loadPages( requested );
		},

		/* --- Page list --------------------------------------------------- */

		// Fill the picker, then show a stored report for the URL (or profile it).
		loadPages: function ( requested ) {
			api.post( 'perf_pages' )
				.done( function ( response ) {
					if ( response && response.success ) {
						profiler.pages = response.data;
						profiler.fillPicker();
					}
				} )
				.always( function () {
					var target = requested || bltPerf.homeUrl;
					var stored = null;
					( profiler.pages.stored || [] ).forEach( function ( item ) {
						if ( ! stored && sameUrl( item.url, target ) ) {
							stored = item;
						}
					} );

					// A ?url= link only pre-fills the field: profiling makes the
					// server request that URL, so it waits for a click.
					if ( stored ) {
						profiler.loadStored( stored.key );
					} else {
						$( '#blt-profiler-result' ).html( '<div class="blt-card"><div class="blt-card-body">' +
							emptyState( t( 'profilerEmptyTitle' ), t( 'profilerEmpty' ) ) + '</div></div>' );
					}
				} );
		},

		fillPicker: function () {
			var seen = {};
			var html = '<option value="">' + escapeHtml( t( 'pickPage' ) ) + '</option>';
			var stored = '';
			var samples = '';

			( profiler.pages.stored || [] ).forEach( function ( item ) {
				seen[ item.url ] = true;
				stored += '<option value="' + escapeHtml( item.url ) + '">' + escapeHtml( ( item.label || item.url ) + ' — ' + item.url ) + '</option>';
			} );
			( profiler.pages.samples || [] ).forEach( function ( item ) {
				if ( ! seen[ item.url ] ) {
					samples += '<option value="' + escapeHtml( item.url ) + '">' + escapeHtml( ( item.label || item.url ) + ' — ' + item.url ) + '</option>';
				}
			} );

			if ( stored ) {
				html += '<optgroup label="' + escapeHtml( t( 'groupProfiled' ) ) + '">' + stored + '</optgroup>';
			}
			if ( samples ) {
				html += '<optgroup label="' + escapeHtml( t( 'groupSamples' ) ) + '">' + samples + '</optgroup>';
			}
			$( '#blt-profiler-pick' ).html( html );
		},

		loadStored: function ( key ) {
			profiler.busy( true, t( 'loadingStored' ) );
			api.post( 'perf_page', { key: key } )
				.done( function ( response ) {
					profiler.busy( false );
					if ( response && response.success ) {
						profiler.show( response.data, false );
					} else {
						profiler.message( response && response.data && response.data.message ? response.data.message : t( 'error' ), 'is-error' );
					}
				} )
				.fail( function () {
					profiler.busy( false );
					profiler.message( t( 'error' ), 'is-error' );
				} );
		},

		/* --- Running ----------------------------------------------------- */

		// Accept a path ("/contact/") as shorthand for a URL on this site.
		urlValue: function () {
			var value = trim( $( '#blt-profiler-url' ).val() );
			if ( 0 === value.indexOf( '/' ) && 0 !== value.indexOf( '//' ) ) {
				value = bltPerf.homeUrl.replace( /\/+$/, '' ) + value;
			}
			return value;
		},

		busy: function ( busy, label ) {
			$( '#blt-profiler-run, #blt-profiler-psi-run, #blt-profiler-lab-run, #blt-lab-device' ).prop( 'disabled', busy );
			$( '#blt-profiler-status' ).html( busy ?
				'<span class="spinner is-active" aria-hidden="true"></span><span>' + escapeHtml( label ) + '</span>' : '' );
			$( '#blt-profiler' ).toggleClass( 'is-busy', busy );
		},

		message: function ( text, cls ) {
			$( '#blt-profiler-status' ).html( callout( '', text, cls ) );
		},

		rememberUrl: function ( url ) {
			if ( window.history && window.history.replaceState ) {
				var base = window.location.href.replace( /[?&]url=[^&#]*/, '' ).replace( /#.*$/, '' );
				window.history.replaceState( null, '', base + ( base.indexOf( '?' ) < 0 ? '?' : '&' ) + 'url=' + encodeURIComponent( url ) );
			}
		},

		run: function () {
			var url = profiler.urlValue();
			if ( ! url ) {
				profiler.message( t( 'enterUrl' ), 'is-error' );
				return;
			}
			var compare = $( '#blt-profiler-compare' ).is( ':checked' );

			profiler.busy( true, compare ? t( 'profilingCompare' ) : t( 'profiling' ) );
			api.post( 'perf_profile', { url: url, compare: compare ? 1 : 0 } )
				.done( function ( response ) {
					profiler.busy( false );
					if ( ! response || ! response.success ) {
						profiler.message( response && response.data && response.data.message ? response.data.message : t( 'error' ), 'is-error' );
						return;
					}
					profiler.rememberUrl( url );
					profiler.show( response.data, compare );
					// The freshly profiled page now belongs in the picker.
					api.post( 'perf_pages' ).done( function ( pages ) {
						if ( pages && pages.success ) {
							profiler.pages = pages.data;
							profiler.fillPicker();
						}
					} );
				} )
				.fail( function () {
					profiler.busy( false );
					profiler.message( t( 'error' ), 'is-error' );
				} );
		},

		/* --- Lab test ---------------------------------------------------- */

		// Normalised key for a page URL (scheme, case and trailing slash ignored).
		urlKey: function ( url ) {
			return String( url || '' ).replace( /#.*$/, '' ).replace( /^https?:\/\//i, '' ).replace( /\/+$/, '' ).toLowerCase();
		},

		// Measure the page in this browser: mobile, desktop or both, one after the other.
		runLab: function () {
			if ( profiler.labRun ) {
				return;
			}
			var url = profiler.urlValue();
			if ( ! url ) {
				profiler.message( t( 'enterUrl' ), 'is-error' );
				return;
			}
			var device = $( '#blt-lab-device' ).val();
			var queue = 'both' === device ? [ 'mobile', 'desktop' ] : [ 'desktop' === device ? 'desktop' : 'mobile' ];
			var results = {};
			var $stage = $( '#blt-profiler-lab-stage' );

			profiler.busy( true, '' );
			$( '#blt-profiler-status' ).empty();

			function finish( failure ) {
				profiler.labRun = null;
				labRunner.clearStage( $stage );
				profiler.busy( false );
				if ( Object.keys( results ).length ) {
					profiler.labDone( url, results );
				}
				if ( failure ) {
					profiler.message( tf( 'labFailed', failure ), 'is-error' );
				}
			}

			function next( index ) {
				if ( index >= queue.length ) {
					finish( '' );
					return;
				}
				profiler.labRun = labRunner.run( { url: url, viewport: queue[ index ], scan: false, $mount: $stage, step: index + 1, steps: queue.length } );
				profiler.labRun.promise
					.done( function ( result ) {
						results[ queue[ index ] ] = result;
						next( index + 1 );
					} )
					.fail( function ( error ) {
						finish( error && error.aborted ? '' : ( error && error.message ) || t( 'error' ) );
					} );
			}

			next( 0 );
		},

		// Show what was measured: keep it for this URL, merge the lab findings in and open the Lab tab.
		labDone: function ( url, results ) {
			var key = profiler.urlKey( url );
			var cache = profiler.labCache[ key ] = profiler.labCache[ key ] || { lab: {}, summary: {}, findings: [] };
			var latest = null;
			Object.keys( results ).forEach( function ( viewport ) {
				var data = results[ viewport ].data || {};
				cache.lab[ viewport ] = data.lab && data.lab.nav !== undefined ? data.lab : results[ viewport ].payload;
				cache.summary[ viewport ] = data.summary || null;
				latest = data;
			} );
			// Each response's findings cover every viewport stored for the page so far.
			cache.findings = latest && latest.findings ? latest.findings : [];

			var base = profiler.data && profiler.data.page && sameUrl( profiler.data.page.url, url ) ? profiler.data : null;
			var data = {
				page: base ? $.extend( {}, base.page ) : { url: url, label: '', fetched_at: Math.round( Date.now() / 1000 ) },
				summary: base ? $.extend( {}, base.summary ) : {},
				findings: base ? base.findings : [],
				baseline: base ? base.baseline : null
			};
			data.page.lab = $.extend( {}, base && base.page.lab, cache.lab );
			data.findings = mergeFindings( withoutLabFindings( data.findings ), cache.findings );

			profiler.activeTab = 'lab';
			profiler.tabChosen = true;
			profiler.labView = results.mobile ? 'mobile' : 'desktop';
			profiler.rememberUrl( url );
			profiler.show( data, false );

			// A page measured here belongs in the picker too.
			api.post( 'perf_pages' ).done( function ( pages ) {
				if ( pages && pages.success ) {
					profiler.pages = pages.data;
					profiler.fillPicker();
				}
			} );
		},

		// A re-profile replaces the stored report (and its lab data): put back what this session measured.
		attachLabCache: function ( data ) {
			var cached = profiler.labCache[ profiler.urlKey( data.page && data.page.url ) ];
			if ( ! cached ) {
				return;
			}
			var page = data.page;
			page.lab = page.lab && 'object' === typeof page.lab && ! Array.isArray( page.lab ) ? page.lab : {};
			Object.keys( cached.lab ).forEach( function ( viewport ) {
				if ( ! page.lab[ viewport ] ) {
					page.lab[ viewport ] = cached.lab[ viewport ];
				}
			} );
			var hasLabFindings = ( data.findings || [] ).some( function ( item ) {
				return 'lab' === item.source;
			} );
			if ( ! hasLabFindings && cached.findings.length ) {
				data.findings = mergeFindings( data.findings, cached.findings );
			}
		},

		// The lab results of a page, per viewport: the report plus its compact summary.
		labViews: function ( page ) {
			var views = {};
			var key = profiler.urlKey( page.url );
			var cached = profiler.labCache[ key ];
			var stored = profiler.data && profiler.data.summary && profiler.data.summary.lab && 'object' === typeof profiler.data.summary.lab ? profiler.data.summary.lab : {};
			[ 'mobile', 'desktop' ].forEach( function ( viewport ) {
				if ( page.lab && page.lab[ viewport ] && 'object' === typeof page.lab[ viewport ] ) {
					views[ viewport ] = { lab: page.lab[ viewport ], summary: ( cached && cached.summary[ viewport ] ) || stored[ viewport ] || null };
				}
			} );
			return views;
		},

		// Nothing but the lab test has run for this page: no server profile, no HTML analysis.
		isLabOnly: function ( page ) {
			return ! page.profile && ! page.html && ! page.http;
		},

		runPsi: function () {
			var url = profiler.urlValue();
			if ( ! url ) {
				profiler.message( t( 'enterUrl' ), 'is-error' );
				return;
			}
			profiler.busy( true, t( 'psiRunning' ) );
			api.post( 'perf_psi', { url: url, strategy: $( '#blt-profiler-strategy' ).val() } )
				.done( function ( response ) {
					profiler.busy( false );
					if ( ! response || ! response.success ) {
						profiler.message( response && response.data && response.data.message ? response.data.message : t( 'error' ), 'is-error' );
						return;
					}
					profiler.showPsi( response.data );
				} )
				.fail( function () {
					profiler.busy( false );
					profiler.message( t( 'error' ), 'is-error' );
				} );
		},

		showPsi: function ( data ) {
			var $mount = $( '#blt-profiler-psi-result' );
			var html = '<div class="blt-card"><div class="blt-card-header"><h2>' + escapeHtml( t( 'psiTitle' ) ) + '</h2><p>' + escapeHtml( t( 'psiHint' ) ) + '</p></div>' +
				'<div class="blt-card-body">' + psi.block( data.psi ) + '</div>';
			if ( data.findings && data.findings.length ) {
				html += '<div class="blt-card-header blt-perf-subhead"><h2>' + escapeHtml( t( 'psiFindings' ) ) + '</h2></div><div id="blt-profiler-psi-findings"></div>';
			}
			$mount.html( html + '</div>' );

			if ( data.findings && data.findings.length ) {
				findings.mount( $( '#blt-profiler-psi-findings' ), {
					list: data.findings,
					dismissed: [],
					categories: categoryLabels,
					canDismiss: false,
					fixedLabel: t( 'fixedReprofile' )
				} );
			}
		},

		/* --- Showing a page report ---------------------------------------- */

		show: function ( data, compareRequested ) {
			profiler.data = data;
			profiler.rendered = {};
			if ( profiler.blobUrl && window.URL && window.URL.revokeObjectURL ) {
				window.URL.revokeObjectURL( profiler.blobUrl );
				profiler.blobUrl = '';
			}

			data.page = data.page || {};
			profiler.attachLabCache( data );
			var page = data.page;
			var summary = data.summary || {};
			var labOnly = profiler.isLabOnly( page );
			$( '#blt-profiler-status' ).empty();

			// A page that only has lab data shows just the Lab tab; a measured page opens on it first.
			profiler.tabs = labOnly ? [ 'lab' ] : TABS;
			if ( labOnly ) {
				profiler.activeTab = 'lab';
			} else if ( ! profiler.tabChosen && Object.keys( profiler.labViews( page ) ).length ) {
				profiler.activeTab = 'lab';
			} else if ( 'lab' === profiler.activeTab && ! Object.keys( profiler.labViews( page ) ).length ) {
				profiler.activeTab = 'components';
			}

			var html = '<div class="blt-card blt-perf-pagecard">' + profiler.headerHtml( page ) + '<div class="blt-card-body">';
			if ( labOnly ) {
				html += callout( t( 'labOnlyTitle' ), t( 'labOnlyHint' ), '' );
			} else {
				html += profiler.noticesHtml( page );
				html += '<div class="blt-stats blt-perf-summary">' + profiler.tilesHtml( page, summary ) + '</div>';
				if ( data.baseline ) {
					html += profiler.compareHtml( summary, data.baseline );
				} else if ( compareRequested ) {
					html += '<div class="blt-stack-top">' + callout( '', t( 'compareFailed' ), '' ) + '</div>';
				}
			}
			html += '</div></div>';

			html += '<div class="blt-card"><div class="blt-card-header"><h2>' + escapeHtml( t( 'pageFindings' ) ) + '</h2><p>' + escapeHtml( t( 'pageFindingsHint' ) ) + '</p></div>' +
				'<div id="blt-profiler-findings"></div></div>';

			html += profiler.tabsHtml( page );

			$( '#blt-profiler-result' ).html( html );

			findings.mount( $( '#blt-profiler-findings' ), {
				list: data.findings || [],
				dismissed: [],
				categories: categoryLabels,
				canDismiss: false,
				fixedLabel: t( 'fixedReprofile' ),
				open: profiler.findingsState.open,
				fixed: profiler.findingsState.fixed
			} );

			profiler.selectTab( profiler.activeTab, false );
		},

		headerHtml: function ( page ) {
			var http = page.http || {};
			var html = '<div class="blt-card-header"><h2>' + ( page.url ? link( page.url, page.url, true ) : '' ) + '</h2>';
			var meta = [];
			if ( page.label ) {
				meta.push( page.label );
			}
			if ( page.profile && page.profile.context ) {
				if ( page.profile.context.template ) {
					meta.push( tf( 'templateIs', page.profile.context.template ) );
				}
			}
			if ( profiler.isLabOnly( page ) ) {
				var measuredAt = 0;
				[ 'mobile', 'desktop' ].forEach( function ( viewport ) {
					measuredAt = Math.max( measuredAt, Number( page.lab && page.lab[ viewport ] && page.lab[ viewport ].measured_at ) || 0 );
				} );
				meta.push( tf( 'labMeasuredAt', fmtDate( measuredAt || page.fetched_at ) ) );
			} else if ( page.fetched_at ) {
				meta.push( tf( 'profiledAt', fmtDate( page.fetched_at ) ) );
			}
			if ( meta.length ) {
				html += '<p>' + escapeHtml( meta.join( ' · ' ) ) + '</p>';
			}
			html += '<div class="blt-card-header-badges">' + ( profiler.isLabOnly( page ) ? '' : scan.statusBadge( http.status ) );
			if ( page.tweaks_off ) {
				html += badge( t( 'tweaksOff' ) );
			}
			if ( page.post && safeUrl( page.post.edit_url ) ) {
				html += '<a class="button button-small" href="' + escapeHtml( safeUrl( page.post.edit_url ) ) + '">' + escapeHtml( t( 'editPost' ) ) + '</a>';
			}
			return html + '</div></div>';
		},

		profileErrorText: function ( code ) {
			var map = {
				page_cache: t( 'profileErrCache' ),
				not_received: t( 'profileErrNotReceived' ),
				http_error: t( 'profileErrHttp' )
			};
			return map[ code ] || t( 'profileErrUnknown' );
		},

		noticesHtml: function ( page ) {
			var html = '';
			var http = page.http || {};
			if ( http.error ) {
				html += '<div class="blt-stack-top">' + callout( t( 'requestFailed' ), http.error, 'is-error' ) + '</div>';
			}
			if ( page.profile_error ) {
				html += '<div class="blt-stack-top">' + callout( t( 'profileUnavailable' ), profiler.profileErrorText( page.profile_error ), 'is-warning' ) + '</div>';
			}
			if ( http.page_cache && http.page_cache.detected ) {
				var detail = http.page_cache.evidence ? tf( 'pageCacheEvidence', http.page_cache.evidence ) : '';
				if ( isNumber( http.cached_ttfb_ms ) ) {
					detail += ' ' + tf( 'pageCacheTtfb', ms( http.cached_ttfb_ms ) );
				}
				html += '<div class="blt-stack-top">' + callout( t( 'pageCacheOn' ), trim( detail ) ) + '</div>';
			}
			return html;
		},

		tilesHtml: function ( page, summary ) {
			var http = page.http || {};
			var profile = page.profile;
			var profiled = !! profile;
			var limit = profile && profile.memory && profile.memory.limit ? tf( 'ofLimit', bytes( profile.memory.limit ) ) : '';

			var ttfb = isNumber( http.ttfb_ms ) ? http.ttfb_ms : summary.ttfb_ms;
			var tiles = [
				[ t( 'tileTtfb' ), ms( ttfb ), isNumber( http.cached_ttfb_ms ) ? tf( 'cachedTtfb', ms( http.cached_ttfb_ms ) ) : t( 'tileTtfbSub' ) ],
				[ t( 'tilePhp' ), profiled ? ms( summary.php_ms ) : DASH, profiled && profile.timing ? tf( 'bootstrapSub', ms( profile.timing.bootstrap_ms ) ) : '' ],
				[ t( 'tileQueries' ), profiled ? num( summary.queries ) : DASH, profiled ? tf( 'queryTimeSub', ms( summary.query_ms ) ) : '' ],
				[ t( 'tileMemory' ), profile && profile.memory ? bytes( profile.memory.peak ) : DASH, limit ],
				[ t( 'tileHtml' ), bytes( summary.html_bytes ), http.encoding ? tf( 'encodingSub', http.encoding ) : t( 'notCompressed' ) ],
				[ t( 'tileJs' ), profiled ? bytes( summary.js_bytes ) : DASH, profiled ? plural( ( profile.assets.scripts || [] ).length, 'fileOne', 'fileMany' ) : '' ],
				[ t( 'tileCss' ), profiled ? bytes( summary.css_bytes ) : DASH, profiled ? plural( ( profile.assets.styles || [] ).length, 'fileOne', 'fileMany' ) : '' ],
				[ t( 'tileImages' ), bytes( summary.image_bytes ), plural( ( page.html && page.html.images || [] ).length, 'imageOne', 'imageMany' ) ],
				[ t( 'tileDom' ), num( summary.dom_nodes ), page.html && page.html.max_depth ? tf( 'depthSub', page.html.max_depth ) : '' ],
				[ t( 'tileRequests' ), num( summary.requests ), t( 'requestsSub' ) ]
			];

			var html = '';
			tiles.forEach( function ( tile ) {
				html += statTile( tile[ 0 ], tile[ 1 ], tile[ 2 ] );
			} );
			return html;
		},

		/* --- A/B: tweaks on vs off ---------------------------------------- */

		compareHtml: function ( current, baseline ) {
			// [ label, summary key, formatter ]
			var metrics = [
				[ t( 'tileTtfb' ), 'ttfb_ms', ms ],
				[ t( 'tilePhp' ), 'php_ms', ms ],
				[ t( 'tileQueries' ), 'queries', num ],
				[ t( 'tileJs' ), 'js_bytes', bytes ],
				[ t( 'tileCss' ), 'css_bytes', bytes ],
				[ t( 'tileRequests' ), 'requests', num ],
				[ t( 'tileDom' ), 'dom_nodes', num ]
			];
			// These only exist when the PHP profile was received on both runs.
			var needsProfile = { php_ms: true, queries: true, js_bytes: true, css_bytes: true };

			var rows = metrics.map( function ( metric ) {
				var key = metric[ 1 ];
				var now = current[ key ];
				var base = baseline[ key ];
				if ( ! isNumber( now ) || ! isNumber( base ) || ( needsProfile[ key ] && ( ! current.profiled || ! baseline.profiled ) ) ) {
					return [ metric[ 0 ], DASH, DASH, DASH ];
				}
				var delta = Number( now ) - Number( base );
				var pct = Number( base ) ? ( 100 * delta ) / Number( base ) : 0;
				var cls = Math.abs( pct ) < 3 ? 'is-same' : ( delta < 0 ? 'is-better' : 'is-worse' );
				var sign = delta > 0 ? '+' : ( delta < 0 ? '\u2212' : '' );
				var text = sign + metric[ 2 ]( Math.abs( delta ) );
				if ( 0 === delta ) {
					text = t( 'noChange' );
				} else if ( Number( base ) ) {
					text += ' (' + sign + Math.abs( Math.round( pct ) ) + '%)';
				}
				return [
					metric[ 0 ],
					metric[ 2 ]( now ),
					metric[ 2 ]( base ),
					{ html: '<span class="blt-perf-delta ' + cls + '">' + escapeHtml( text ) + '</span>' }
				];
			} );

			return '<div class="blt-perf-ab"><h3>' + escapeHtml( t( 'abTitle' ) ) + '</h3><p class="description">' + escapeHtml( t( 'abHint' ) ) + '</p>' +
				tableHtml( [
					t( 'colMetric' ),
					{ label: t( 'colTweaksOn' ), cls: 'num' },
					{ label: t( 'colTweaksOff' ), cls: 'num' },
					{ label: t( 'colChange' ), cls: 'num' }
				], rows, 'blt-perf-abtable' ) + '</div>';
		},

		/* --- Sub-tabs ----------------------------------------------------- */

		tabCount: function ( id, page ) {
			var profile = page.profile;
			var html = page.html || {};
			switch ( id ) {
				case 'queries':
					return profile ? profile.queries.count : null;
				case 'http':
					return profile ? ( profile.http || [] ).length : null;
				case 'errors':
					return profile ? ( profile.errors || [] ).length : null;
				case 'assets':
					return profile ? ( profile.assets.scripts || [] ).length + ( profile.assets.styles || [] ).length : ( html.scripts || [] ).length + ( html.stylesheets || [] ).length;
			}
			return null;
		},

		tabsHtml: function ( page ) {
			var html = '<div class="blt-card blt-perf-details"><div class="blt-perf-subtabs blt-settings-tabs" role="tablist" aria-label="' + escapeHtml( t( 'detailsLabel' ) ) + '">';
			profiler.tabs.forEach( function ( id ) {
				var count = profiler.tabCount( id, page );
				html += '<button type="button" role="tab" class="blt-settings-tab blt-perf-subtab" id="blt-tab-' + id + '" data-tab="' + id + '" aria-controls="blt-panel-' + id + '" aria-selected="false" tabindex="-1">' +
					escapeHtml( t( 'tab_' + id ) ) + ( null !== count ? ' <span class="blt-perf-tab-count">' + escapeHtml( num( count ) ) + '</span>' : '' ) + '</button>';
			} );
			html += '</div>';
			profiler.tabs.forEach( function ( id ) {
				html += '<div role="tabpanel" class="blt-card-body blt-perf-panel" id="blt-panel-' + id + '" aria-labelledby="blt-tab-' + id + '" tabindex="0" hidden></div>';
			} );
			return html + '</div>';
		},

		selectTab: function ( id, focus ) {
			if ( profiler.tabs.indexOf( id ) < 0 ) {
					id = profiler.tabs[ 0 ];
				}
			profiler.activeTab = id;
			$( '#blt-profiler-result .blt-perf-subtab' ).each( function () {
				var active = $( this ).attr( 'data-tab' ) === id;
				$( this ).toggleClass( 'is-active', active ).attr( { 'aria-selected': active ? 'true' : 'false', tabindex: active ? '0' : '-1' } );
				if ( active && focus ) {
					this.focus();
				}
			} );
			$( '#blt-profiler-result .blt-perf-panel' ).prop( 'hidden', true );
			var $panel = $( '#blt-panel-' + id );
			if ( ! profiler.rendered[ id ] ) {
				profiler.rendered[ id ] = true;
				$panel.html( profiler[ 'panel_' + id ]( profiler.data.page || {} ) );
			}
			$panel.prop( 'hidden', false );
		},

		// Arrow keys move between tabs (roving tabindex).
		tabKeys: function ( event ) {
			var tabs = profiler.tabs;
			var index = tabs.indexOf( $( this ).attr( 'data-tab' ) );
			var target = -1;
			if ( 37 === event.which ) {
				target = ( index + tabs.length - 1 ) % tabs.length;
			} else if ( 39 === event.which ) {
				target = ( index + 1 ) % tabs.length;
			} else if ( 36 === event.which ) {
				target = 0;
			} else if ( 35 === event.which ) {
				target = tabs.length - 1;
			}
			if ( target >= 0 ) {
				event.preventDefault();
				profiler.selectTab( tabs[ target ], true );
			}
		},

		unavailable: function ( page ) {
			return callout( t( 'profileUnavailable' ), profiler.profileErrorText( page.profile_error || 'not_received' ), 'is-warning' );
		},

		/* --- Panel: lab (Core Web Vitals) -------------------------------- */

		panel_lab: function ( page ) {
			var views = profiler.labViews( page );
			var available = Object.keys( views );
			if ( ! available.length ) {
				return emptyState( t( 'labEmptyTitle' ), t( 'labEmptyHint' ) );
			}
			var active = views[ profiler.labView ] ? profiler.labView : available[ 0 ];
			var view = views[ active ];
			var lab = view.lab;
			var html = '<div class="blt-lab-bar-row">';

			if ( available.length > 1 ) {
				html += '<div class="blt-lab-switch" role="group" aria-label="' + escapeHtml( t( 'labDevice' ) ) + '">';
				[ 'mobile', 'desktop' ].forEach( function ( viewport ) {
					if ( views[ viewport ] ) {
						html += '<button type="button" class="button' + ( viewport === active ? ' is-active' : '' ) + '" data-view="' + viewport + '" aria-pressed="' + ( viewport === active ? 'true' : 'false' ) + '">' +
							escapeHtml( 'mobile' === viewport ? t( 'strategyMobile' ) : t( 'strategyDesktop' ) ) + '</button>';
					}
				} );
				html += '</div>';
			}

			var meta = [ 'mobile' === active ? t( 'strategyMobile' ) : t( 'strategyDesktop' ) ];
			if ( lab.viewport && lab.viewport.w ) {
				meta.push( lab.viewport.w + ' × ' + lab.viewport.h + ( Number( lab.viewport.dpr ) > 1 ? ' @' + lab.viewport.dpr + '×' : '' ) );
			}
			if ( lab.measured_at ) {
				meta.push( tf( 'labMeasuredAt', fmtDate( lab.measured_at ) ) );
			}
			html += '<span class="blt-lab-meta">' + escapeHtml( meta.join( ' · ' ) ) + '</span></div>';

			return html + labui.viewHtml( lab, view.summary );
		},

		/* --- Panel: components ------------------------------------------- */

		panel_components: function ( page ) {
			var profile = page.profile;
			if ( ! profile ) {
				return profiler.unavailable( page );
			}
			var map = {};
			function slot( component ) {
				var key = compKey( component );
				if ( ! map[ key ] ) {
					map[ key ] = { component: component, load: 0, qCount: 0, qMs: 0, hCount: 0, hMs: 0, files: 0, bytes: 0, inline: 0, errors: 0 };
				}
				return map[ key ];
			}

			( profile.components_load || [] ).forEach( function ( row ) {
				slot( row.component ).load += Number( row.ms ) || 0;
			} );
			( profile.queries.by_component || [] ).forEach( function ( row ) {
				var s = slot( row.component );
				s.qCount += Number( row.count ) || 0;
				s.qMs += Number( row.time_ms ) || 0;
			} );
			( profile.http || [] ).forEach( function ( row ) {
				var s = slot( row.component );
				s.hCount++;
				s.hMs += Number( row.ms ) || 0;
			} );
			( profile.assets.scripts || [] ).concat( profile.assets.styles || [] ).forEach( function ( row ) {
				var s = slot( row.component );
				s.files++;
				s.bytes += Number( row.bytes ) || 0;
				s.inline += Number( row.inline_bytes ) || 0;
			} );
			( profile.errors || [] ).forEach( function ( row ) {
				slot( row.component ).errors += Number( row.count ) || 1;
			} );

			var rows = Object.keys( map ).map( function ( key ) {
				var row = map[ key ];
				row.total = row.load + row.qMs + row.hMs;
				return row;
			} );
			rows.sort( function ( a, b ) {
				return b.total - a.total || b.bytes - a.bytes;
			} );

			var max = rows.length ? rows[ 0 ].total : 0;
			var body = rows.map( function ( row ) {
				return [
					compChip( row.component ) || escapeHtml( DASH ),
					{ html: barHtml( row.total, max, ms( row.total ) ), cls: 'blt-perf-bar-cell' },
					row.load ? ms( row.load ) : DASH,
					row.qCount ? twoLine( ms( row.qMs ), plural( row.qCount, 'queryOne', 'queryMany' ) ) : DASH,
					row.hCount ? twoLine( ms( row.hMs ), plural( row.hCount, 'callOne', 'callMany' ) ) : DASH,
					row.files ? twoLine( bytes( row.bytes ), plural( row.files, 'fileOne', 'fileMany' ) + ( row.inline ? ' · ' + tf( 'inlineBytes', bytes( row.inline ) ) : '' ) ) : DASH,
					row.errors ? num( row.errors ) : DASH
				];
			} );

			var html = '<p class="description">' + escapeHtml( t( 'componentsHint' ) ) + '</p>';
			html += tableHtml( [
				t( 'colComponent' ),
				t( 'colTotalTime' ),
				{ label: t( 'colPluginLoad' ), cls: 'num' },
				{ label: t( 'colQueries' ), cls: 'num' },
				{ label: t( 'colHttp' ), cls: 'num' },
				{ label: t( 'colAssets' ), cls: 'num' },
				{ label: t( 'colErrors' ), cls: 'num' }
			], body, 'blt-perf-components' );
			if ( profile.components_load_note ) {
				html += '<p class="description">' + escapeHtml( profile.components_load_note ) + '</p>';
			}
			return html;
		},

		/* --- Panel: queries ---------------------------------------------- */

		panel_queries: function ( page ) {
			var profile = page.profile;
			if ( ! profile ) {
				return profiler.unavailable( page );
			}
			var queries = profile.queries;
			var html = '';

			if ( ! queries.tracked ) {
				html += callout( t( 'queriesUntracked' ), t( 'queriesUntrackedHint' ), 'is-warning' );
			}

			html += '<div class="blt-stats blt-perf-summary">' +
				statTile( t( 'tileQueries' ), num( queries.count ), queries.tracked ? tf( 'queryTimeSub', ms( queries.time_ms ) ) : '' ) +
				Object.keys( queries.by_type || {} ).map( function ( type ) {
					return statTile( type, num( queries.by_type[ type ] ) );
				} ).join( '' ) + '</div>';

			var byComponent = ( queries.by_component || [] ).map( function ( row ) {
				return [ compChip( row.component ) || escapeHtml( DASH ), num( row.count ), queries.tracked ? ms( row.time_ms ) : DASH ];
			} );
			if ( byComponent.length ) {
				html += '<h3>' + escapeHtml( t( 'queriesByComponent' ) ) + '</h3>' +
					tableHtml( [ t( 'colComponent' ), { label: t( 'colQueries' ), cls: 'num' }, { label: t( 'colTime' ), cls: 'num' } ], byComponent );
			}

			var slow = ( queries.slowest || [] ).map( function ( row ) {
				return {
					cells: [ { html: ms( row.ms ), cls: Number( row.ms ) > 50 ? 'num is-slow' : 'num' }, compChip( row.component ) || escapeHtml( DASH ), '<code>' + escapeHtml( row.caller || '' ) + '</code>', sqlCell( row.sql ) ]
				};
			} );
			if ( slow.length ) {
				html += '<h3>' + escapeHtml( t( 'queriesSlowest' ) ) + '</h3>' +
					tableHtml( [ { label: t( 'colTime' ), cls: 'num' }, t( 'colComponent' ), t( 'colCaller' ), t( 'colSql' ) ], slow, 'blt-perf-queries' );
			}

			var dupes = ( queries.duplicates || [] ).map( function ( row ) {
				return [
					'×' + num( row.count ),
					queries.tracked ? ms( row.time_ms ) : DASH,
					( row.components || [] ).map( compChip ).join( ' ' ) || escapeHtml( DASH ),
					'<code>' + escapeHtml( row.caller || '' ) + '</code>',
					sqlCell( row.sql )
				];
			} );
			if ( dupes.length ) {
				html += '<h3>' + escapeHtml( t( 'queriesDuplicates' ) ) + '</h3><p class="description">' + escapeHtml( t( 'queriesDuplicatesHint' ) ) + '</p>' +
					tableHtml( [ { label: t( 'colRepeats' ), cls: 'num' }, { label: t( 'colTime' ), cls: 'num' }, t( 'colComponent' ), t( 'colCaller' ), t( 'colSql' ) ], dupes, 'blt-perf-queries' );
			}

			var transients = ( profile.transients_set || [] ).map( function ( row ) {
				return [ '<code>' + escapeHtml( row.name ) + '</code>', row.expiration ? escapeHtml( tf( 'expiresIn', row.expiration ) ) : escapeHtml( t( 'noExpiry' ) ), compChip( row.component ) || escapeHtml( DASH ), '×' + num( row.count ) ];
			} );
			if ( transients.length ) {
				html += '<h3>' + escapeHtml( t( 'transientsSet' ) ) + '</h3><p class="description">' + escapeHtml( t( 'transientsSetHint' ) ) + '</p>' +
					tableHtml( [ t( 'colName' ), t( 'colExpiry' ), t( 'colComponent' ), { label: t( 'colRepeats' ), cls: 'num' } ], transients );
			}

			return html;
		},

		/* --- Panel: assets ------------------------------------------------ */

		isBlocking: function ( script ) {
			return !! script.src && ! script.footer && ! script.strategy;
		},

		assetName: function ( row ) {
			var name = '<code>' + escapeHtml( row.handle ) + '</code>';
			var src = safeUrl( row.src );
			return src ? '<a href="' + escapeHtml( src ) + '" target="_blank" rel="noopener noreferrer" title="' + escapeHtml( row.src ) + '">' + name + '</a>' : name;
		},

		panel_assets: function ( page ) {
			var profile = page.profile;
			if ( ! profile ) {
				return profiler.assetsFromHtml( page );
			}
			var scripts = profile.assets.scripts || [];
			var styles = profile.assets.styles || [];
			var blocking = scripts.filter( profiler.isBlocking ).length;

			var html = '<div class="blt-stats blt-perf-summary">' +
				statTile( t( 'tileJs' ), bytes( sumBy( scripts, 'bytes' ) ), plural( scripts.length, 'fileOne', 'fileMany' ) + ' · ' + tf( 'inlineBytes', bytes( sumBy( scripts, 'inline_bytes' ) ) ) ) +
				statTile( t( 'tileCss' ), bytes( sumBy( styles, 'bytes' ) ), plural( styles.length, 'fileOne', 'fileMany' ) + ' · ' + tf( 'inlineBytes', bytes( sumBy( styles, 'inline_bytes' ) ) ) ) +
				statTile( t( 'renderBlockingScripts' ), num( blocking ), blocking ? t( 'renderBlockingHint' ) : t( 'noneBlocking' ), blocking ? 'is-warn' : 'is-good' ) +
				'</div>';

			var scriptRows = scripts.map( function ( row ) {
				var flags = '';
				if ( profiler.isBlocking( row ) ) {
					flags += badge( t( 'renderBlocking' ), 'blt-badge-pending' ) + ' ';
				}
				if ( row.src && ! row.local ) {
					flags += badge( t( 'external' ) );
				}
				return {
					cls: profiler.isBlocking( row ) ? 'is-blocking' : '',
					cells: [
						profiler.assetName( row ),
						compChip( row.component ) || escapeHtml( DASH ),
						escapeHtml( row.src ? ( row.footer ? t( 'footer' ) : t( 'head' ) ) : t( 'inlineOnly' ) ),
						escapeHtml( row.strategy || DASH ),
						row.local ? bytes( row.bytes ) : DASH,
						row.inline_bytes ? bytes( row.inline_bytes ) : DASH,
						flags
					]
				};
			} );
			html += '<h3>' + escapeHtml( t( 'scripts' ) ) + '</h3>';
			html += scriptRows.length ? tableHtml( [
				t( 'colHandle' ), t( 'colComponent' ), t( 'colLocation' ), t( 'colStrategy' ),
				{ label: t( 'colSize' ), cls: 'num' }, { label: t( 'colInline' ), cls: 'num' }, { label: '', sr: t( 'colFlags' ) }
			], scriptRows, 'blt-perf-assets' ) : '<p class="description">' + escapeHtml( t( 'noScripts' ) ) + '</p>';

			var styleRows = styles.map( function ( row ) {
				return [
					profiler.assetName( row ),
					compChip( row.component ) || escapeHtml( DASH ),
					escapeHtml( row.media || 'all' ),
					row.local ? bytes( row.bytes ) : DASH,
					row.inline_bytes ? bytes( row.inline_bytes ) : DASH,
					row.src && ! row.local ? badge( t( 'external' ) ) : ''
				];
			} );
			html += '<h3>' + escapeHtml( t( 'styles' ) ) + '</h3><p class="description">' + escapeHtml( t( 'stylesHint' ) ) + '</p>';
			html += styleRows.length ? tableHtml( [
				t( 'colHandle' ), t( 'colComponent' ), t( 'colMedia' ),
				{ label: t( 'colSize' ), cls: 'num' }, { label: t( 'colInline' ), cls: 'num' }, { label: '', sr: t( 'colFlags' ) }
			], styleRows, 'blt-perf-assets' ) : '<p class="description">' + escapeHtml( t( 'noStyles' ) ) + '</p>';

			return html;
		},

		// Without a PHP profile the HTML still lists what the page loads.
		assetsFromHtml: function ( page ) {
			var html = page.html || {};
			var out = callout( t( 'profileUnavailable' ), t( 'assetsFromHtml' ), 'is-warning' );

			var scripts = ( html.scripts || [] ).map( function ( row ) {
				var blocking = row.in_head && ! row.async && ! row.defer && ! row.module;
				return {
					cls: blocking ? 'is-blocking' : '',
					cells: [
						link( row.src, fileName( row.src ), true ),
						escapeHtml( row.host || DASH ),
						escapeHtml( row.in_head ? t( 'head' ) : t( 'footer' ) ),
						escapeHtml( row.async ? 'async' : ( row.defer ? 'defer' : ( row.module ? 'module' : DASH ) ) ),
						blocking ? badge( t( 'renderBlocking' ), 'blt-badge-pending' ) : ''
					]
				};
			} );
			out += '<h3>' + escapeHtml( t( 'scripts' ) ) + '</h3>';
			out += scripts.length ? tableHtml( [ t( 'colFile' ), t( 'colHost' ), t( 'colLocation' ), t( 'colStrategy' ), { label: '', sr: t( 'colFlags' ) } ], scripts, 'blt-perf-assets' ) : '<p class="description">' + escapeHtml( t( 'noScripts' ) ) + '</p>';

			var styles = ( html.stylesheets || [] ).map( function ( row ) {
				return [ link( row.href, fileName( row.href ), true ), escapeHtml( row.host || DASH ), escapeHtml( row.in_head ? t( 'head' ) : t( 'footer' ) ), escapeHtml( row.media || 'all' ) ];
			} );
			out += '<h3>' + escapeHtml( t( 'styles' ) ) + '</h3>';
			out += styles.length ? tableHtml( [ t( 'colFile' ), t( 'colHost' ), t( 'colLocation' ), t( 'colMedia' ) ], styles, 'blt-perf-assets' ) : '<p class="description">' + escapeHtml( t( 'noStyles' ) ) + '</p>';
			return out;
		},

		/* --- Panel: HTTP calls -------------------------------------------- */

		panel_http: function ( page ) {
			var profile = page.profile;
			if ( ! profile ) {
				return profiler.unavailable( page );
			}
			var calls = profile.http || [];
			if ( ! calls.length ) {
				return emptyState( t( 'noHttp' ), t( 'noHttpHint' ) );
			}
			var rows = calls.map( function ( row ) {
				var failed = row.error || Number( row.status ) >= 400 || ! Number( row.status );
				return {
					cells: [
						escapeHtml( row.method || 'GET' ),
						safeUrl( row.url ) ? '<a href="' + escapeHtml( safeUrl( row.url ) ) + '" target="_blank" rel="noopener noreferrer" class="blt-perf-url">' + escapeHtml( row.url ) + '</a>' : '<code>' + escapeHtml( row.url ) + '</code>',
						row.error ? badge( t( 'failed' ), 'blt-badge-cancelled' ) + ' <span class="blt-perf-sub">' + escapeHtml( row.error ) + '</span>' : badge( String( row.status ), failed ? 'blt-badge-cancelled' : 'blt-badge-confirmed' ),
						{ html: ms( row.ms ), cls: Number( row.ms ) > 200 ? 'num is-slow' : 'num' },
						compChip( row.component ) || escapeHtml( DASH ),
						'<code>' + escapeHtml( row.caller || '' ) + '</code>'
					]
				};
			} );
			return '<p class="description">' + escapeHtml( tf( 'httpSummary', calls.length, ms( sumBy( calls, 'ms' ) ) ) ) + ' ' + escapeHtml( t( 'httpHint' ) ) + '</p>' +
				tableHtml( [ t( 'colMethod' ), 'URL', t( 'colStatus' ), { label: t( 'colTime' ), cls: 'num' }, t( 'colComponent' ), t( 'colCaller' ) ], rows, 'blt-perf-http' );
		},

		/* --- Panel: PHP errors -------------------------------------------- */

		panel_errors: function ( page ) {
			var profile = page.profile;
			if ( ! profile ) {
				return profiler.unavailable( page );
			}
			var errors = profile.errors || [];
			if ( ! errors.length ) {
				return emptyState( t( 'noErrors' ), t( 'noErrorsHint' ) );
			}
			var rows = errors.map( function ( row ) {
				var cls = 'warning' === row.level ? 'blt-badge-pending' : ( 'other' === row.level ? 'blt-badge-cancelled' : '' );
				return [
					badge( t( 'level_' + row.level ) === 'level_' + row.level ? row.level : t( 'level_' + row.level ), cls ),
					'<span class="blt-perf-msg">' + escapeHtml( row.message ) + '</span>',
					'<code>' + escapeHtml( ( row.file || '' ) + ( row.line ? ':' + row.line : '' ) ) + '</code>',
					compChip( row.component ) || escapeHtml( DASH ),
					'×' + num( row.count || 1 )
				];
			} );
			return '<p class="description">' + escapeHtml( t( 'errorsHint' ) ) + '</p>' +
				tableHtml( [ t( 'colLevel' ), t( 'colMessage' ), t( 'colLocation' ), t( 'colComponent' ), { label: t( 'colRepeats' ), cls: 'num' } ], rows, 'blt-perf-errors-table' );
		},

		/* --- Panel: content ------------------------------------------------ */

		panel_content: function ( page ) {
			var html = page.html || {};
			var out = '';
			var images = html.images || [];

			out += '<h3>' + escapeHtml( t( 'images' ) ) + ' <span class="blt-perf-tab-count">' + escapeHtml( num( images.length ) ) + '</span></h3>';
			if ( images.length ) {
				var rows = images.map( function ( image, position ) {
					var warnings = [];
					if ( null === image.attr_w || undefined === image.attr_w || null === image.attr_h || undefined === image.attr_h ) {
						warnings.push( t( 'warnNoDims' ) );
					}
					if ( image.attr_w && image.natural_w > 2 * image.attr_w ) {
						warnings.push( t( 'warnOversized' ) );
					}
					if ( position < 2 && 'lazy' === image.loading ) {
						warnings.push( t( 'warnLazyHero' ) );
					}
					if ( image.local && Number( image.bytes ) > 300 * 1024 ) {
						warnings.push( t( 'warnHeavy' ) );
					}
					return {
						cls: warnings.length ? 'is-warn' : '',
						cells: [
							safeUrl( image.src ) ? '<a href="' + escapeHtml( safeUrl( image.src ) ) + '" target="_blank" rel="noopener noreferrer" title="' + escapeHtml( image.src ) + '">' + escapeHtml( fileName( image.src ) || image.src ) + '</a>' : escapeHtml( fileName( image.src ) ),
							image.local ? bytes( image.bytes ) : DASH,
							image.natural_w ? escapeHtml( image.natural_w + '×' + image.natural_h ) : DASH,
							isNumber( image.attr_w ) && isNumber( image.attr_h ) ? escapeHtml( image.attr_w + '×' + image.attr_h ) : DASH,
							escapeHtml( String( image.format || '' ).toUpperCase() ),
							escapeHtml( image.loading || DASH ),
							image.has_srcset ? t( 'yes' ) : t( 'no' ),
							image.in_content ? t( 'yes' ) : t( 'no' ),
							warnings.map( function ( warning ) {
								return badge( warning, 'blt-badge-pending' );
							} ).join( ' ' )
						]
					};
				} );
				out += tableHtml( [
					t( 'colImage' ), { label: t( 'colSize' ), cls: 'num' }, { label: t( 'colNatural' ), cls: 'num' }, { label: t( 'colDisplayed' ), cls: 'num' },
					t( 'colFormat' ), t( 'colLoading' ), t( 'colSrcset' ), t( 'colInContent' ), { label: '', sr: t( 'colFlags' ) }
				], rows, 'blt-perf-images' );
			} else {
				out += '<p class="description">' + escapeHtml( t( 'noImages' ) ) + '</p>';
			}

			var iframes = ( html.iframes || [] ).map( function ( frame ) {
				return [ escapeHtml( frame.kind || 'other' ), escapeHtml( frame.host || DASH ), escapeHtml( frame.loading || DASH ), link( frame.src, frame.src, true ) ];
			} );
			if ( iframes.length ) {
				out += '<h3>' + escapeHtml( t( 'iframes' ) ) + '</h3>' + tableHtml( [ t( 'colKind' ), t( 'colHost' ), t( 'colLoading' ), 'URL' ], iframes, 'blt-perf-iframes' );
			}

			var third = ( html.third_party || [] ).map( function ( row ) {
				return [
					'<code>' + escapeHtml( row.host ) + '</code>',
					row.known ? escapeHtml( row.known ) : DASH,
					num( row.requests ),
					( row.kinds || [] ).map( function ( kind ) {
						return '<span class="blt-chip">' + escapeHtml( kind ) + '</span>';
					} ).join( ' ' )
				];
			} );
			out += '<h3>' + escapeHtml( t( 'thirdParty' ) ) + '</h3>';
			out += third.length ? tableHtml( [ t( 'colHost' ), t( 'colKnownAs' ), { label: t( 'colRequests' ), cls: 'num' }, t( 'colKinds' ) ], third ) : '<p class="description">' + escapeHtml( t( 'noThirdParty' ) ) + '</p>';

			var hints = html.hints || {};
			var hintRows = [];
			[ [ 'preconnect', t( 'hintPreconnect' ) ], [ 'dns_prefetch', t( 'hintDns' ) ] ].forEach( function ( type ) {
				if ( ( hints[ type[ 0 ] ] || [] ).length ) {
					hintRows.push( [ escapeHtml( type[ 1 ] ), hints[ type[ 0 ] ].map( function ( origin ) {
						return '<code>' + escapeHtml( origin ) + '</code>';
					} ).join( ' ' ) ] );
				}
			} );
			if ( ( hints.preload || [] ).length ) {
				hintRows.push( [ escapeHtml( t( 'hintPreload' ) ), hints.preload.map( function ( item ) {
					return '<code>' + escapeHtml( fileName( item.href ) ) + ( item.as ? ' (' + escapeHtml( item.as ) + ')' : '' ) + '</code>';
				} ).join( ' ' ) ] );
			}
			out += '<h3>' + escapeHtml( t( 'resourceHints' ) ) + '</h3>';
			out += hintRows.length ? tableHtml( [ t( 'colType' ), t( 'colValue' ) ], hintRows ) : '<p class="description">' + escapeHtml( t( 'noHints' ) ) + '</p>';
			if ( html.fonts && html.fonts.google_fonts ) {
				out += '<p class="description">' + escapeHtml( t( 'googleFonts' ) ) + '</p>';
			}

			var inlineScripts = html.inline_scripts || {};
			var inlineStyles = html.inline_styles || {};
			out += '<h3>' + escapeHtml( t( 'inlineCode' ) ) + '</h3><div class="blt-stats blt-perf-summary">' +
				statTile( t( 'inlineScripts' ), bytes( inlineScripts.bytes ), plural( inlineScripts.count || 0, 'blockOne', 'blockMany' ) ) +
				statTile( t( 'inlineStyles' ), bytes( inlineStyles.bytes ), plural( inlineStyles.count || 0, 'blockOne', 'blockMany' ) +
					( inlineStyles.largest && inlineStyles.largest.id ? ' · ' + tf( 'largestIs', inlineStyles.largest.id ) : '' ) ) +
				'</div>';
			return out;
		},

		/* --- Panel: timeline ------------------------------------------------ */

		panel_timeline: function ( page ) {
			var profile = page.profile;
			if ( ! profile ) {
				return profiler.unavailable( page );
			}
			var timing = profile.timing || {};
			var points = [ timing.bootstrap_ms, timing.plugins_loaded_ms, timing.wp_loaded_ms, timing.template_redirect_ms, timing.total_ms ].map( function ( value ) {
				return Number( value ) || 0;
			} );
			var names = [ 'bootstrap', 'plugins_loaded', 'wp_loaded', 'template_redirect', 'render' ];
			var segments = [];
			var previous = 0;
			points.forEach( function ( point, index ) {
				var duration = Math.max( 0, point - previous );
				if ( 4 === index && isNumber( timing.render_ms ) ) {
					duration = Number( timing.render_ms );
				}
				segments.push( { id: names[ index ], ms: duration } );
				previous = Math.max( previous, point );
			} );
			var total = segments.reduce( function ( sum, segment ) {
				return sum + segment.ms;
			}, 0 );

			var html = '<p class="description">' + escapeHtml( t( 'timelineHint' ) ) + '</p>';
			html += '<div class="blt-perf-timeline" role="img" aria-label="' + escapeHtml( tf( 'timelineLabel', ms( timing.total_ms ) ) ) + '">';
			segments.forEach( function ( segment, index ) {
				if ( segment.ms <= 0 ) {
					return;
				}
				var width = total ? ( 100 * segment.ms ) / total : 0;
				html += '<span class="blt-perf-seg blt-perf-seg-' + ( index + 1 ) + '" style="width:' + width.toFixed( 2 ) + '%" title="' + escapeHtml( t( 'phase_' + segment.id ) + ': ' + ms( segment.ms ) ) + '"></span>';
			} );
			html += '</div>';

			html += '<ol class="blt-perf-legend">';
			segments.forEach( function ( segment, index ) {
				html += '<li><span class="blt-perf-swatch blt-perf-seg-' + ( index + 1 ) + '" aria-hidden="true"></span>' +
					'<span class="blt-perf-legend-name"><strong>' + escapeHtml( t( 'phase_' + segment.id ) ) + '</strong><code>' + escapeHtml( 'render' === segment.id ? 'template_redirect → shutdown' : segment.id ) + '</code></span>' +
					'<span class="blt-perf-legend-ms">' + escapeHtml( ms( segment.ms ) ) + '</span>' +
					'<span class="blt-perf-legend-pct">' + escapeHtml( total ? Math.round( ( 100 * segment.ms ) / total ) + '%' : DASH ) + '</span></li>';
			} );
			html += '</ol>';

			var env = profile.env || {};
			var context = profile.context || {};
			var cache = profile.cache || {};
			var facts = [
				[ t( 'factTotal' ), ms( timing.total_ms ) ],
				[ t( 'factPhp' ), env.php ],
				[ t( 'factWp' ), env.wp ],
				[ t( 'factOpcache' ), env.opcache ? t( 'on' ) : t( 'off' ) ],
				[ t( 'factObjectCache' ), env.object_cache ? t( 'on' ) : t( 'off' ) ],
				[ t( 'factTemplate' ), context.template ],
				[ t( 'factType' ), context.type + ( context.post_type ? ' (' + context.post_type + ( context.object_id ? ' #' + context.object_id : '' ) + ')' : '' ) ],
				[ t( 'factPeakMemory' ), profile.memory ? bytes( profile.memory.peak ) + ( profile.memory.limit ? ' / ' + bytes( profile.memory.limit ) : '' ) : DASH ]
			];
			if ( cache && ( isNumber( cache.hits ) || isNumber( cache.misses ) ) ) {
				facts.push( [ t( 'factCache' ), tf( 'cacheHitsMisses', num( cache.hits ), num( cache.misses ) ) ] );
			}
			html += '<h3>' + escapeHtml( t( 'environment' ) ) + '</h3>' + tableHtml( [ t( 'colMetric' ), t( 'colValue' ) ], facts.map( function ( fact ) {
				return [ escapeHtml( fact[ 0 ] ), '<code>' + escapeHtml( undefined === fact[ 1 ] || null === fact[ 1 ] || '' === fact[ 1 ] ? DASH : fact[ 1 ] ) + '</code>' ];
			} ), 'blt-perf-facts' );
			return html;
		},

		/* --- Panel: raw JSON ------------------------------------------------ */

		panel_raw: function ( page ) {
			var json = JSON.stringify( page, null, 2 );
			var slug = ( hostOf( page.url ) + String( page.url || '' ).replace( /^https?:\/\/[^\/]+/i, '' ) ).replace( /[^a-z0-9]+/gi, '-' ).replace( /^-+|-+$/g, '' ) || 'page';
			var html = '<p class="description">' + escapeHtml( t( 'rawHint' ) ) + '</p><p>';

			try {
				var blob = new window.Blob( [ json ], { type: 'application/json' } );
				profiler.blobUrl = window.URL.createObjectURL( blob );
				html += '<a class="button" href="' + escapeHtml( profiler.blobUrl ) + '" download="blt-profile-' + escapeHtml( slug ) + '.json">' + escapeHtml( t( 'downloadJson' ) ) + '</a> ';
			} catch ( e ) {
				profiler.blobUrl = '';
			}
			html += '<span class="blt-perf-sub">' + escapeHtml( bytes( json.length ) ) + '</span></p>';

			var limit = 40000;
			html += '<code class="blt-code blt-perf-raw" tabindex="0">' + escapeHtml( json.length > limit ? json.substr( 0, limit ) : json ) + '</code>';
			if ( json.length > limit ) {
				html += '<p class="description">' + escapeHtml( t( 'rawTruncated' ) ) + '</p>';
			}
			return html;
		}
	};

	/* ------------------------------------------------------------------ */
	/* Speed Tweaks screen: asset rules + reversible changes               */
	/* ------------------------------------------------------------------ */

	var tweaks = {
		rules: [],
		changes: [],
		condLabels: {},

		init: function () {
			if ( ! $( '#blt-rules-list' ).length ) {
				return;
			}

			// Condition labels come from the PHP-rendered template, so the
			// rule table and the form always say the same thing.
			var $template = $( trim( $( '#blt-rule-condition-template' ).html() ) );
			$template.find( 'select option' ).each( function () {
				tweaks.condLabels[ this.value ] = $( this ).text();
			} );

			$( '#blt-rule-mode' ).on( 'change', tweaks.syncMode );
			$( '#blt-rule-add-condition' ).on( 'click', function () {
				tweaks.addCondition();
			} );
			$( '#blt-rule-submit' ).on( 'click', tweaks.submitRule );
			$( '#blt-rule-handle, #blt-rule-note' ).on( 'keydown', function ( event ) {
				if ( 13 === event.which ) {
					event.preventDefault();
					tweaks.submitRule();
				}
			} );

			var $conditions = $( '#blt-rule-conditions' );
			$conditions.on( 'change', '.blt-cond-type', function () {
				tweaks.syncCondition( $( this ).closest( '.blt-cond-row' ) );
			} );
			$conditions.on( 'click', '.blt-cond-remove', function () {
				$( this ).closest( '.blt-cond-row' ).remove();
			} );

			$( '#blt-rules-list' ).on( 'change', '.blt-rule-enabled', function () {
				tweaks.toggleRule( $( this ) );
			} );
			$( '#blt-rules-list' ).on( 'click', '.blt-rule-delete', function () {
				tweaks.deleteRule( $( this ) );
			} );
			$( '#blt-changes-list' ).on( 'click', '.blt-autoload-restore', function () {
				tweaks.restoreAutoload( $( this ) );
			} );

			tweaks.syncMode();
			tweaks.load();
		},

		load: function () {
			api.post( 'tweaks_status' )
				.done( function ( response ) {
					if ( response && response.success ) {
						tweaks.apply( response.data );
					} else {
						tweaks.fail();
					}
				} )
				.fail( tweaks.fail );
		},

		fail: function () {
			$( '#blt-rules-list' ).html( callout( '', t( 'error' ), 'is-error' ) );
			$( '#blt-changes-list' ).html( callout( '', t( 'error' ), 'is-error' ) );
		},

		apply: function ( data ) {
			tweaks.rules = data.rules || [];
			tweaks.changes = data.autoloadChanges || [];
			tweaks.renderRules();
			tweaks.renderChanges();
		},

		/* --- Rules table ------------------------------------------------- */

		conditionText: function ( condition ) {
			var label = tweaks.condLabels[ condition.type ] || condition.type;
			return condition.value ? label + ': “' + condition.value + '”' : label;
		},

		ruleSummary: function ( rule ) {
			if ( 'except' !== rule.mode ) {
				return '<span class="blt-rule-mode">' + escapeHtml( t( 'modeEverywhereShort' ) ) + '</span>';
			}
			var html = '<span class="blt-rule-mode">' + escapeHtml( t( 'modeExceptShort' ) ) + '</span><ul class="blt-rule-conditions">';
			( rule.conditions || [] ).forEach( function ( condition ) {
				html += '<li>' + escapeHtml( tweaks.conditionText( condition ) ) + '</li>';
			} );
			return html + '</ul>';
		},

		renderRules: function () {
			var $list = $( '#blt-rules-list' );
			if ( ! tweaks.rules.length ) {
				$list.html( emptyState( t( 'noRules' ), t( 'noRulesHint' ) ) );
				return;
			}
			var rows = tweaks.rules.map( function ( rule ) {
				return {
					cls: rule.enabled ? '' : 'is-disabled',
					cells: [
						badge( 'script' === rule.type ? t( 'typeScript' ) : t( 'typeStyle' ) ),
						'<code>' + escapeHtml( rule.handle ) + '</code>',
						tweaks.ruleSummary( rule ),
						rule.note ? escapeHtml( rule.note ) : DASH,
						escapeHtml( fmtDay( rule.created ) ),
						'<label class="blt-toggle"><input type="checkbox" class="blt-rule-enabled" data-id="' + escapeHtml( rule.id ) + '"' + ( rule.enabled ? ' checked' : '' ) + ' aria-label="' + escapeHtml( tf( 'ruleEnabledFor', rule.handle ) ) + '" />' +
							'<span class="blt-toggle-track" aria-hidden="true"><span class="blt-toggle-thumb"></span></span></label>',
						'<button type="button" class="button button-small blt-rule-delete" data-id="' + escapeHtml( rule.id ) + '" data-handle="' + escapeHtml( rule.handle ) + '">' + escapeHtml( t( 'delete' ) ) + '</button>'
					]
				};
			} );
			$list.html( tableHtml( [
				t( 'colType' ), t( 'colHandle' ), t( 'colRule' ), t( 'colNote' ), t( 'colCreated' ), t( 'colEnabled' ), ''
			], rows, 'blt-rules-table' ) );
		},

		toggleRule: function ( $input ) {
			var enabled = $input.is( ':checked' );
			$input.prop( 'disabled', true );
			api.post( 'rule_toggle', { id: $input.attr( 'data-id' ), enabled: enabled ? 1 : 0 } )
				.done( function ( response ) {
					if ( response && response.success ) {
						tweaks.apply( response.data );
					} else {
						$input.prop( { checked: ! enabled, disabled: false } );
						tweaks.formMessage( response && response.data && response.data.message ? response.data.message : t( 'error' ), true );
					}
				} )
				.fail( function () {
					$input.prop( { checked: ! enabled, disabled: false } );
					tweaks.formMessage( t( 'error' ), true );
				} );
		},

		deleteRule: function ( $button ) {
			if ( ! window.confirm( t( 'confirmRuleDel' ) ) ) {
				return;
			}
			$button.prop( 'disabled', true );
			api.post( 'rule_delete', { id: $button.attr( 'data-id' ) } )
				.done( function ( response ) {
					if ( response && response.success ) {
						tweaks.apply( response.data );
					} else {
						$button.prop( 'disabled', false );
						tweaks.formMessage( response && response.data && response.data.message ? response.data.message : t( 'error' ), true );
					}
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					tweaks.formMessage( t( 'error' ), true );
				} );
		},

		/* --- Add-rule form ------------------------------------------------ */

		formMessage: function ( text, isError ) {
			$( '#blt-rule-message' ).attr( 'class', 'blt-rule-message' + ( text ? ( isError ? ' is-error' : ' is-ok' ) : '' ) ).text( text || '' );
		},

		// Conditions only matter when the rule keeps the asset on some pages.
		syncMode: function () {
			var except = 'except' === $( '#blt-rule-mode' ).val();
			$( '#blt-rule-conditions-field' ).prop( 'hidden', ! except );
			if ( except && ! $( '#blt-rule-conditions .blt-cond-row' ).length ) {
				tweaks.addCondition();
			}
		},

		addCondition: function () {
			var $row = $( trim( $( '#blt-rule-condition-template' ).html() ) );
			$( '#blt-rule-conditions' ).append( $row );
			tweaks.syncCondition( $row );
			return $row;
		},

		// front_page and woocommerce need no value.
		syncCondition: function ( $row ) {
			var $option = $row.find( '.blt-cond-type option:selected' );
			var needsValue = '1' === $option.attr( 'data-value' );
			$row.find( '.blt-cond-value' )
				.prop( 'hidden', ! needsValue )
				.prop( 'disabled', ! needsValue )
				.attr( 'placeholder', $option.attr( 'data-placeholder' ) || '' );
		},

		submitRule: function () {
			var handle = trim( $( '#blt-rule-handle' ).val() );
			var mode = $( '#blt-rule-mode' ).val();
			var conditions = [];
			var problem = '';

			if ( ! /^[A-Za-z0-9_\-.\/]+$/.test( handle ) ) {
				problem = t( 'ruleBadHandle' );
			}

			if ( ! problem && 'except' === mode ) {
				$( '#blt-rule-conditions .blt-cond-row' ).each( function () {
					var type = $( this ).find( '.blt-cond-type' ).val();
					var needsValue = '1' === $( this ).find( '.blt-cond-type option:selected' ).attr( 'data-value' );
					var value = trim( $( this ).find( '.blt-cond-value' ).val() );
					if ( needsValue && ! value ) {
						problem = t( 'ruleNeedsValue' );
						return false;
					}
					conditions.push( { type: type, value: needsValue ? value : '' } );
				} );
				if ( ! problem && ! conditions.length ) {
					problem = t( 'ruleNeedsCondition' );
				}
			}

			if ( problem ) {
				tweaks.formMessage( problem, true );
				return;
			}

			var $button = $( '#blt-rule-submit' ).prop( 'disabled', true );
			tweaks.formMessage( '', false );

			api.post( 'rule_add', {
				type: $( '#blt-rule-type' ).val(),
				handle: handle,
				mode: mode,
				conditions: JSON.stringify( conditions ),
				note: trim( $( '#blt-rule-note' ).val() )
			} )
				.done( function ( response ) {
					$button.prop( 'disabled', false );
					if ( ! response || ! response.success ) {
						tweaks.formMessage( response && response.data && response.data.message ? response.data.message : t( 'error' ), true );
						return;
					}
					tweaks.apply( response.data );
					$( '#blt-rule-handle, #blt-rule-note' ).val( '' );
					$( '#blt-rule-conditions' ).empty();
					tweaks.syncMode();
					tweaks.formMessage( t( 'ruleAdded' ), false );
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					tweaks.formMessage( t( 'error' ), true );
				} );
		},

		/* --- Reversible changes -------------------------------------------- */

		renderChanges: function () {
			var $list = $( '#blt-changes-list' );
			if ( ! tweaks.changes.length ) {
				$list.html( emptyState( t( 'noChanges' ), t( 'noChangesHint' ) ) );
				return;
			}
			var rows = tweaks.changes.map( function ( change ) {
				return [
					'<code>' + escapeHtml( change.option ) + '</code>',
					escapeHtml( change.bytesHuman || bytes( change.bytes ) ),
					escapeHtml( fmtDate( change.changedAt ) ),
					'<button type="button" class="button blt-autoload-restore" data-option="' + escapeHtml( change.option ) + '">' + escapeHtml( t( 'restoreAutoload' ) ) + '</button><span class="blt-finding-result" role="status" aria-live="polite"></span>'
				];
			} );
			$list.html( tableHtml( [ t( 'colOption' ), { label: t( 'colSize' ), cls: 'num' }, t( 'colChanged' ), '' ], rows, 'blt-changes-table' ) );
		},

		restoreAutoload: function ( $button ) {
			if ( ! window.confirm( t( 'confirmRestore' ) ) ) {
				return;
			}
			var $result = $button.siblings( '.blt-finding-result' );
			$button.prop( 'disabled', true );
			api.post( 'perf_fix', { fix_action: 'restore_autoload', args: JSON.stringify( { option: $button.attr( 'data-option' ) } ) } )
				.done( function ( response ) {
					if ( response && response.success ) {
						tweaks.load();
						return;
					}
					$button.prop( 'disabled', false );
					$result.addClass( 'is-error' ).text( response && response.data && response.data.message ? response.data.message : t( 'error' ) );
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					$result.addClass( 'is-error' ).text( t( 'error' ) );
				} );
		}
	};

	/* ------------------------------------------------------------------ */
	/* Boot                                                                */
	/* ------------------------------------------------------------------ */

	$( function () {
		scan.init();
		profiler.init();
		tweaks.init();
	} );
}( jQuery ) );
