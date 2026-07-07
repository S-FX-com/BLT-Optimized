/**
 * BLT Optimized admin UI: scan polling, folder tree, cleanup previews,
 * DB optimization.
 */
/* global bltOptimized, jQuery */
( function ( $ ) {
	'use strict';

	var api = {
		post: function ( action, data ) {
			return $.post(
				bltOptimized.ajaxUrl,
				$.extend( { action: 'blt_optimized_' + action, nonce: bltOptimized.nonce }, data || {} )
			);
		}
	};

	function escapeHtml( text ) {
		return $( '<span>' ).text( String( text ) ).html();
	}

	/* ------------------------------------------------------------------ */
	/* Disk scan page                                                      */
	/* ------------------------------------------------------------------ */

	var scan = {
		polling: false,

		init: function () {
			if ( ! $( '#blt-scan-start' ).length ) {
				return;
			}
			$( '#blt-scan-start' ).on( 'click', scan.start );
			$( '#blt-scan-cancel' ).on( 'click', scan.cancel );

			api.post( 'scan_status' ).done( function ( response ) {
				if ( response.success && 'running' === response.data.status ) {
					scan.setRunning( true );
					scan.tick();
				} else {
					scan.loadResults();
				}
			} );
		},

		start: function () {
			api.post( 'scan_start' ).done( function ( response ) {
				if ( ! response.success ) {
					window.alert( response.data && response.data.message ? response.data.message : bltOptimized.i18n.error );
					return;
				}
				scan.setRunning( true );
				scan.tick();
			} );
		},

		cancel: function () {
			api.post( 'scan_cancel' ).done( function () {
				scan.setRunning( false );
				$( '#blt-scan-progress' ).text( bltOptimized.i18n.scanCancelled );
			} );
		},

		tick: function () {
			if ( scan.polling ) {
				return;
			}
			scan.polling = true;
			api.post( 'scan_tick' )
				.done( function ( response ) {
					scan.polling = false;
					if ( ! response.success ) {
						scan.setRunning( false );
						return;
					}
					var progress = response.data;
					$( '#blt-scan-progress' ).text(
						bltOptimized.i18n.scanning + ' ' + progress.dirsScanned + ' dirs, ' +
						progress.bytesHuman + ' (' + progress.queueLength + ' queued)'
					);
					if ( 'running' === progress.status ) {
						window.setTimeout( scan.tick, 250 );
					} else {
						scan.setRunning( false );
						$( '#blt-scan-progress' ).text( bltOptimized.i18n.scanComplete );
						scan.loadResults();
					}
				} )
				.fail( function () {
					scan.polling = false;
					window.setTimeout( scan.tick, 3000 );
				} );
		},

		setRunning: function ( running ) {
			$( '#blt-scan-start' ).prop( 'disabled', running );
			$( '#blt-scan-cancel' ).toggle( running );
		},

		loadResults: function () {
			api.post( 'scan_results' ).done( function ( response ) {
				if ( ! response.success || ! response.data.summary ) {
					return;
				}
				scan.renderSummary( response.data.summary );
				scan.renderTree( response.data.rows, response.data.labels );
				scan.renderTopHogs( response.data.topHogs, response.data.labels );
			} );
		},

		renderSummary: function ( summary ) {
			var when = new Date( summary.completed_at * 1000 ).toLocaleString();
			var cards = [
				{ label: 'Last scan', value: when },
				{ label: 'Total scanned', value: scan.human( summary.bytes_seen ) },
				{ label: 'Directories', value: summary.dirs_scanned },
				{ label: 'Files', value: summary.files_seen },
				{ label: 'Registered image sizes', value: summary.image_sizes ? summary.image_sizes.count + '× crops per upload' : '—' }
			];
			var html = cards.map( function ( card ) {
				return '<div class="blt-card"><span class="blt-card-label">' + escapeHtml( card.label ) +
					'</span><span class="blt-card-value">' + escapeHtml( card.value ) + '</span></div>';
			} ).join( '' );
			$( '#blt-scan-summary' ).html( html );
		},

		human: function ( bytes ) {
			var units = [ 'B', 'KB', 'MB', 'GB', 'TB' ];
			var i = 0;
			bytes = Number( bytes ) || 0;
			while ( bytes >= 1024 && i < units.length - 1 ) {
				bytes /= 1024;
				i++;
			}
			return ( i ? bytes.toFixed( 1 ) : bytes ) + ' ' + units[ i ];
		},

		flagBadges: function ( flags, labels ) {
			return ( flags || [] ).map( function ( flag ) {
				var label = labels[ flag ] || flag;
				return '<span class="blt-flag" title="' + escapeHtml( label ) + '">' + escapeHtml( label ) + '</span>';
			} ).join( ' ' );
		},

		renderTree: function ( rows, labels ) {
			var children = {};
			rows.forEach( function ( row ) {
				var parent = row.parent || '';
				if ( ! children[ parent ] ) {
					children[ parent ] = [];
				}
				children[ parent ].push( row );
			} );
			Object.keys( children ).forEach( function ( key ) {
				children[ key ].sort( function ( a, b ) {
					return b.bytes - a.bytes;
				} );
			} );

			var maxBytes = 1;
			( children[ '' ] || [] ).forEach( function ( row ) {
				maxBytes = Math.max( maxBytes, row.bytes );
			} );

			function renderNodes( parent, depth ) {
				var nodes = children[ parent ] || [];
				return nodes.map( function ( row ) {
					var hasKids = !! children[ row.path ];
					var name = row.path.split( '/' ).pop();
					var pct = Math.max( 0.5, ( 100 * row.bytes ) / maxBytes );
					var html = '<div class="blt-node" data-path="' + escapeHtml( row.path ) + '">';
					html += '<div class="blt-node-row" style="padding-left:' + ( depth * 22 ) + 'px">';
					html += hasKids
						? '<button type="button" class="blt-toggle" aria-expanded="false">▸</button>'
						: '<span class="blt-toggle blt-toggle-empty"></span>';
					html += '<span class="blt-node-name ' + ( 'file' === row.type ? 'blt-is-file' : '' ) + '">' + escapeHtml( name ) + '</span>';
					html += '<span class="blt-node-flags">' + scan.flagBadges( row.flags, labels ) + '</span>';
					html += '<span class="blt-node-files">' + escapeHtml( row.files ) + ' files</span>';
					html += '<span class="blt-node-size">' + escapeHtml( row.bytesHuman ) + '</span>';
					html += '<span class="blt-bar"><span class="blt-bar-fill" style="width:' + pct + '%"></span></span>';
					html += '</div>';
					if ( hasKids ) {
						html += '<div class="blt-children" style="display:none">' + renderNodes( row.path, depth + 1 ) + '</div>';
					}
					html += '</div>';
					return html;
				} ).join( '' );
			}

			$( '#blt-tree' ).html( renderNodes( '', 0 ) || '<p class="description">No data.</p>' );

			$( '#blt-tree' ).off( 'click.blt' ).on( 'click.blt', '.blt-toggle:not(.blt-toggle-empty)', function () {
				var $button = $( this );
				var $kids = $button.closest( '.blt-node' ).children( '.blt-children' );
				var open = 'true' === $button.attr( 'aria-expanded' );
				$button.attr( 'aria-expanded', open ? 'false' : 'true' ).text( open ? '▸' : '▾' );
				$kids.toggle( ! open );
			} );
		},

		renderTopHogs: function ( hogs, labels ) {
			if ( ! hogs || ! hogs.length ) {
				$( '#blt-top-hogs' ).html( '<p class="description">—</p>' );
				return;
			}
			var html = '<ol class="blt-hogs">';
			hogs.forEach( function ( hog ) {
				html += '<li><span class="blt-hog-size">' + escapeHtml( hog.bytesHuman ) + '</span> ' +
					'<span class="blt-hog-path">' + escapeHtml( hog.path ) + '</span> ' +
					scan.flagBadges( hog.flags, labels ) + '</li>';
			} );
			html += '</ol>';
			$( '#blt-top-hogs' ).html( html );
		}
	};

	/* ------------------------------------------------------------------ */
	/* Cleanup page                                                        */
	/* ------------------------------------------------------------------ */

	var cleanup = {
		backupAcked: false,

		init: function () {
			if ( ! $( '#blt-cleanup-list' ).length ) {
				return;
			}
			$( '#blt-cleanup-refresh' ).on( 'click', cleanup.load );
			cleanup.load();
		},

		load: function () {
			$( '#blt-cleanup-list' ).html( '<p class="description">…</p>' );
			api.post( 'cleanup_preview' ).done( function ( response ) {
				if ( ! response.success ) {
					return;
				}
				cleanup.backupAcked = !! response.data.backupAcked;
				cleanup.render( response.data.categories );
			} );
		},

		render: function ( categories ) {
			var html = '';
			Object.keys( categories ).forEach( function ( id ) {
				var category = categories[ id ];
				html += '<div class="blt-cleanup-item" data-category="' + escapeHtml( id ) + '">';
				html += '<div class="blt-cleanup-head">';
				html += '<strong>' + escapeHtml( category.label ) + '</strong>';
				html += '<span class="blt-cleanup-count">' + escapeHtml( category.count ) + ' items · ~' + escapeHtml( category.bytesHuman ) + '</span>';
				if ( category.flagOnly ) {
					html += '<span class="blt-flag">flag only — review manually</span>';
				} else if ( category.count > 0 ) {
					html += '<button type="button" class="button blt-cleanup-run">Clean up</button>';
				}
				html += '</div>';
				html += '<p class="description">' + escapeHtml( category.description ) + '</p>';
				if ( category.notes ) {
					html += '<p class="description"><em>' + escapeHtml( category.notes ) + '</em></p>';
				}
				if ( category.items && category.items.length ) {
					html += '<table class="widefat striped blt-cleanup-table"><thead><tr><th>Table</th><th>Size</th><th>Rows (est.)</th></tr></thead><tbody>';
					category.items.forEach( function ( item ) {
						html += '<tr><td><code>' + escapeHtml( item.name ) + '</code></td><td>' + scan.human( item.bytes ) + '</td><td>' + escapeHtml( item.rows_est ) + '</td></tr>';
					} );
					html += '</tbody></table>';
				}
				html += '<div class="blt-cleanup-result"></div>';
				html += '</div>';
			} );
			$( '#blt-cleanup-list' ).html( html );

			$( '.blt-cleanup-run' ).on( 'click', function () {
				cleanup.run( $( this ).closest( '.blt-cleanup-item' ) );
			} );
		},

		run: function ( $item ) {
			var category = $item.data( 'category' );

			if ( ! cleanup.backupAcked ) {
				var acked = window.confirm( bltOptimized.i18n.backupTitle + '\n\n' + bltOptimized.i18n.backupAck );
				if ( ! acked ) {
					return;
				}
			}
			if ( ! window.confirm( bltOptimized.i18n.confirmRun ) ) {
				return;
			}

			var $button = $item.find( '.blt-cleanup-run' ).prop( 'disabled', true );
			api.post( 'cleanup_execute', { category: category, backup_ack: 1 } )
				.done( function ( response ) {
					$button.prop( 'disabled', false );
					if ( ! response.success ) {
						window.alert( response.data && response.data.message ? response.data.message : bltOptimized.i18n.error );
						return;
					}
					cleanup.backupAcked = true;
					$item.find( '.blt-cleanup-result' ).html(
						'<p class="blt-success">✓ ' + escapeHtml( response.data.rows ) + ' rows removed, ~' +
						escapeHtml( response.data.bytesHuman ) + ' reclaimed.</p>'
					);
					$item.find( '.blt-cleanup-count' ).text( '0 items · 0 B' );
					$button.remove();
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					window.alert( bltOptimized.i18n.error );
				} );
		}
	};

	/* ------------------------------------------------------------------ */
	/* DB optimization page                                                */
	/* ------------------------------------------------------------------ */

	var db = {
		tables: [],

		init: function () {
			if ( ! $( '#blt-tables' ).length ) {
				return;
			}
			$( '#blt-optimize-selected' ).on( 'click', db.optimize );
			db.load();
		},

		load: function () {
			api.post( 'db_overview' ).done( function ( response ) {
				if ( ! response.success ) {
					return;
				}
				db.tables = response.data.tables;
				db.renderSummary( response.data );
				db.renderAutoload( response.data.autoload );
				db.renderTables( response.data.tables );
			} );
		},

		renderSummary: function ( data ) {
			var cards = [
				{ label: 'Database size', value: data.dbBytesHuman },
				{ label: 'Tables', value: data.tables.length },
				{ label: 'Autoload payload', value: scan.human( data.autoload.total_bytes ) },
				{ label: 'MyISAM tables', value: data.myisamCount }
			];
			$( '#blt-db-summary' ).html( cards.map( function ( card ) {
				return '<div class="blt-card"><span class="blt-card-label">' + escapeHtml( card.label ) +
					'</span><span class="blt-card-value">' + escapeHtml( card.value ) + '</span></div>';
			} ).join( '' ) );
		},

		renderAutoload: function ( autoload ) {
			var html = '<table class="widefat striped"><thead><tr><th>Option</th><th>Size</th><th></th></tr></thead><tbody>';
			autoload.top.forEach( function ( option ) {
				html += '<tr><td><code>' + escapeHtml( option.name ) + '</code></td><td>' + scan.human( option.bytes ) + '</td><td>' +
					( option.flagged ? '<span class="blt-flag">over 100 KB</span>' : '' ) + '</td></tr>';
			} );
			html += '</tbody></table>';
			$( '#blt-autoload' ).html( html );
		},

		renderTables: function ( tables ) {
			var html = '<table class="widefat striped"><thead><tr>' +
				'<th class="check-column"><input type="checkbox" id="blt-check-all" /></th>' +
				'<th>Table</th><th>Engine</th><th>Rows (est.)</th><th>Size</th><th>Overhead</th><th></th>' +
				'</tr></thead><tbody>';
			tables.forEach( function ( table ) {
				var badges = '';
				if ( table.is_myisam ) {
					badges += '<span class="blt-flag">MyISAM — consider InnoDB</span> ';
				}
				if ( table.innodb_warn ) {
					badges += '<span class="blt-flag blt-flag-warn">large InnoDB — locks briefly</span>';
				}
				html += '<tr>' +
					'<td><input type="checkbox" class="blt-table-check" value="' + escapeHtml( table.name ) + '" data-warn="' + ( table.innodb_warn ? 1 : 0 ) + '" /></td>' +
					'<td><code>' + escapeHtml( table.name ) + '</code></td>' +
					'<td>' + escapeHtml( table.engine ) + '</td>' +
					'<td>' + escapeHtml( table.rows_est ) + '</td>' +
					'<td>' + escapeHtml( table.bytesHuman ) + '</td>' +
					'<td>' + escapeHtml( table.dataFreeHuman ) + '</td>' +
					'<td>' + badges + '</td>' +
					'</tr>';
			} );
			html += '</tbody></table>';
			$( '#blt-tables' ).html( html );
			$( '#blt-check-all' ).on( 'change', function () {
				$( '.blt-table-check' ).prop( 'checked', this.checked );
			} );
		},

		optimize: function () {
			var selected = $( '.blt-table-check:checked' ).map( function () {
				return this.value;
			} ).get();
			if ( ! selected.length ) {
				return;
			}
			var hasWarn = $( '.blt-table-check:checked[data-warn="1"]' ).length > 0;
			if ( hasWarn && ! window.confirm( bltOptimized.i18n.innodbWarn ) ) {
				return;
			}
			var $button = $( '#blt-optimize-selected' ).prop( 'disabled', true );
			api.post( 'optimize_tables', { tables: selected } )
				.done( function ( response ) {
					$button.prop( 'disabled', false );
					if ( ! response.success ) {
						window.alert( response.data && response.data.message ? response.data.message : bltOptimized.i18n.error );
						return;
					}
					window.alert(
						'Optimized ' + response.data.optimized.length + ' tables.\n' +
						'DB size before: ' + response.data.beforeHuman + '\n' +
						'DB size after: ' + response.data.afterHuman
					);
					db.load();
				} )
				.fail( function () {
					$button.prop( 'disabled', false );
					window.alert( bltOptimized.i18n.error );
				} );
		}
	};

	$( function () {
		scan.init();
		cleanup.init();
		db.init();
	} );
}( jQuery ) );
