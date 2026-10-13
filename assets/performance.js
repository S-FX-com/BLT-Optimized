/**
 * BLT Optimized performance UI: the Performance Scan, Page Profiler and Speed
 * Tweaks screens.
 *
 * Modules (same shape as admin.js): util (escaping + formatters), findings
 * (the one finding-row renderer the scan and the profiler share), psi
 * (PageSpeed Insights block), scan, profiler, tweaks.
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
			html += '<th scope="col"' + ( header.cls ? ' class="' + header.cls + '"' : '' ) + '>' +
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
	/* Performance Scan screen                                             */
	/* ------------------------------------------------------------------ */

	var MAX_RETRIES = 6;
	var STEP_ORDER = [ 'health', 'media', 'pages', 'psi', 'compile' ];

	var scan = {
		running: false,
		polling: false,
		failures: 0,
		payload: null,
		sawPsi: false,
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
						scan.setRunning( true );
						scan.showProgress( response.data );
						scan.tick();
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
						scan.tick();
						return;
					}
					$( '#blt-perf-start, .blt-perf-run' ).prop( 'disabled', false );
					scan.notice( response && response.data && response.data.message ? response.data.message : t( 'error' ), 'is-error' );
					// "Already in progress" after a reload: adopt the running scan.
					api.post( 'perf_status' ).done( function ( status ) {
						if ( status && status.success && 'running' === status.data.status ) {
							scan.setRunning( true );
							scan.showProgress( status.data );
							scan.tick();
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
			if ( scan.polling || ! scan.running ) {
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
					if ( 'running' === response.data.status ) {
						window.setTimeout( scan.tick, 200 );
					} else {
						scan.finish( response.data );
					}
				} )
				.fail( function () {
					scan.polling = false;
					scan.retry();
				} );
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

		// health -> media -> pages -> (psi) -> report, as a row of step chips.
		renderSteps: function ( step ) {
			if ( 'psi' === step ) {
				scan.sawPsi = true;
			}
			var current = STEP_ORDER.indexOf( step );
			var html = '';
			STEP_ORDER.forEach( function ( id, index ) {
				if ( 'psi' === id && ! scan.sawPsi ) {
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

			var html = '<div id="blt-perf-score"></div><div id="blt-perf-psi"></div>';
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
			var body = pages.map( function ( page ) {
				var label = page.label || page.url;
				var note = '';
				if ( page.error ) {
					note = '<span class="blt-perf-sub is-warn">' + escapeHtml( errorLabels[ page.error ] || page.error ) + '</span>';
				}
				return [
					'<span class="blt-perf-main">' + escapeHtml( label ) + '</span><span class="blt-perf-sub">' + link( page.url, page.url, true ) + '</span>' + note,
					scan.statusBadge( page.status ),
					ms( page.ttfb_ms ),
					ms( page.php_ms ),
					twoLine( num( page.queries ), isNumber( page.query_ms ) ? ms( page.query_ms ) : '' ),
					page.profiled ? bytes( page.js_bytes ) : DASH,
					page.profiled ? bytes( page.css_bytes ) : DASH,
					bytes( page.image_bytes ),
					num( page.dom_nodes ),
					num( page.requests ),
					'<a class="button button-small" href="' + escapeHtml( scan.profileUrl( page.url ) ) + '">' + escapeHtml( t( 'profile' ) ) + '</a>'
				];
			} );

			html += tableHtml( [
				t( 'colPage' ),
				t( 'colStatus' ),
				{ label: 'TTFB', cls: 'num' },
				{ label: t( 'colPhp' ), cls: 'num' },
				{ label: t( 'colQueries' ), cls: 'num' },
				{ label: 'JS', cls: 'num' },
				{ label: 'CSS', cls: 'num' },
				{ label: t( 'colImages' ), cls: 'num' },
				{ label: t( 'colDom' ), cls: 'num' },
				{ label: t( 'colRequests' ), cls: 'num' },
				''
			], body, 'blt-perf-pages', true );
			return html + '</div>';
		}
	};

	/* ------------------------------------------------------------------ */
	/* Page Profiler screen                                                */
	/* ------------------------------------------------------------------ */

	var TABS = [ 'components', 'queries', 'assets', 'http', 'errors', 'content', 'timeline', 'raw' ];

	var profiler = {
		data: null,
		pages: { samples: [], stored: [] },
		activeTab: 'components',
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
				profiler.selectTab( $( this ).attr( 'data-tab' ), true );
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
			$( '#blt-profiler-run, #blt-profiler-psi-run' ).prop( 'disabled', busy );
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

			var page = data.page || {};
			var summary = data.summary || {};
			$( '#blt-profiler-status' ).empty();

			var html = '<div class="blt-card blt-perf-pagecard">' + profiler.headerHtml( page ) + '<div class="blt-card-body">';
			html += profiler.noticesHtml( page );
			html += '<div class="blt-stats blt-perf-summary">' + profiler.tilesHtml( page, summary ) + '</div>';
			if ( data.baseline ) {
				html += profiler.compareHtml( summary, data.baseline );
			} else if ( compareRequested ) {
				html += '<div class="blt-stack-top">' + callout( '', t( 'compareFailed' ), '' ) + '</div>';
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
			if ( page.fetched_at ) {
				meta.push( tf( 'profiledAt', fmtDate( page.fetched_at ) ) );
			}
			if ( meta.length ) {
				html += '<p>' + escapeHtml( meta.join( ' · ' ) ) + '</p>';
			}
			html += '<div class="blt-card-header-badges">' + scan.statusBadge( http.status );
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
			TABS.forEach( function ( id ) {
				var count = profiler.tabCount( id, page );
				html += '<button type="button" role="tab" class="blt-settings-tab blt-perf-subtab" id="blt-tab-' + id + '" data-tab="' + id + '" aria-controls="blt-panel-' + id + '" aria-selected="false" tabindex="-1">' +
					escapeHtml( t( 'tab_' + id ) ) + ( null !== count ? ' <span class="blt-perf-tab-count">' + escapeHtml( num( count ) ) + '</span>' : '' ) + '</button>';
			} );
			html += '</div>';
			TABS.forEach( function ( id ) {
				html += '<div role="tabpanel" class="blt-card-body blt-perf-panel" id="blt-panel-' + id + '" aria-labelledby="blt-tab-' + id + '" tabindex="0" hidden></div>';
			} );
			return html + '</div>';
		},

		selectTab: function ( id, focus ) {
			if ( TABS.indexOf( id ) < 0 ) {
				id = 'components';
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
			var index = TABS.indexOf( $( this ).attr( 'data-tab' ) );
			var target = -1;
			if ( 37 === event.which ) {
				target = ( index + TABS.length - 1 ) % TABS.length;
			} else if ( 39 === event.which ) {
				target = ( index + 1 ) % TABS.length;
			} else if ( 36 === event.which ) {
				target = 0;
			} else if ( 35 === event.which ) {
				target = TABS.length - 1;
			}
			if ( target >= 0 ) {
				event.preventDefault();
				profiler.selectTab( TABS[ target ], true );
			}
		},

		unavailable: function ( page ) {
			return callout( t( 'profileUnavailable' ), profiler.profileErrorText( page.profile_error || 'not_received' ), 'is-warning' );
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
