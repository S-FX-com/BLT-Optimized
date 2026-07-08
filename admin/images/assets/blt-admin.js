/**
 * Blt Image Optimizer — admin bulk runner + connection test.
 * Vanilla JS only (no jQuery).
 */
( function () {
	'use strict';

	if ( typeof BltOptimizer === 'undefined' ) {
		return;
	}

	var POLL_INTERVAL = 2500;
	var pollTimer = null;

	/**
	 * POST helper returning a parsed JSON promise.
	 *
	 * @param {string} action admin-ajax action.
	 * @param {Object} extra  Additional form fields.
	 * @return {Promise<Object>}
	 */
	function post( action, extra ) {
		var body = new FormData();
		body.append( 'action', action );
		body.append( 'nonce', BltOptimizer.nonce );

		if ( extra ) {
			Object.keys( extra ).forEach( function ( key ) {
				body.append( key, extra[ key ] );
			} );
		}

		return fetch( BltOptimizer.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: body
		} ).then( function ( res ) {
			return res.json();
		} );
	}

	function el( id ) {
		return document.getElementById( id );
	}

	/* ------------------------------------------------------------------ *
	 * Connection test (Settings page)
	 * ------------------------------------------------------------------ */
	var testBtn = el( 'blt-test-connection' );
	if ( testBtn ) {
		testBtn.addEventListener( 'click', function () {
			var result = el( 'blt-test-result' );
			result.textContent = BltOptimizer.i18n.testing;
			result.className = 'blt-test-result';
			testBtn.disabled = true;

			post( 'blt_test_connection' ).then( function ( res ) {
				var ok = res && res.success;
				result.textContent = ( res && res.data && res.data.message ) || '';
				result.className = 'blt-test-result ' + ( ok ? 'ok' : 'fail' );
			} ).catch( function () {
				result.textContent = 'Request failed.';
				result.className = 'blt-test-result fail';
			} ).finally( function () {
				testBtn.disabled = false;
			} );
		} );
	}

	/* ------------------------------------------------------------------ *
	 * Bulk runner (Bulk page)
	 * ------------------------------------------------------------------ */
	var panel = document.querySelector( '.blt-bulk-panel' );
	if ( ! panel ) {
		return;
	}

	var startBtn = el( 'blt-start' );
	var pauseBtn = el( 'blt-pause' );
	var resumeBtn = el( 'blt-resume' );
	var cancelBtn = el( 'blt-cancel' );
	var fill = panel.querySelector( '.blt-progress-fill' );
	var progressText = el( 'blt-progress-text' );
	var message = el( 'blt-bulk-message' );

	function setMessage( text, type ) {
		message.textContent = text || '';
		message.className = 'blt-bulk-message' + ( type ? ' ' + type : '' );
	}

	function show( node, visible ) {
		if ( node ) {
			node.hidden = ! visible;
		}
	}

	/**
	 * Reflect queue state in the UI.
	 *
	 * @param {Object} state Queue state object.
	 * @param {Object} stats Aggregate stats object.
	 */
	function render( state, stats ) {
		state = state || {};
		var status = state.status || 'idle';
		var totalBatches = state.total_batches || 0;
		var batchesDone = state.batches_done || 0;
		var pct = totalBatches > 0 ? Math.round( ( batchesDone / totalBatches ) * 100 ) : 0;

		if ( status === 'done' ) {
			pct = 100;
		}

		fill.style.width = pct + '%';

		var processed = state.processed || 0;
		var errors = state.errors || 0;
		var skipped = state.skipped || 0;

		switch ( status ) {
			case 'running':
				progressText.textContent = 'Running… ' + processed + ' optimized, ' +
					skipped + ' skipped, ' + errors + ' errors (' + pct + '%).';
				break;
			case 'paused':
				progressText.textContent = 'Paused at ' + pct + '%.';
				break;
			case 'done':
				progressText.textContent = 'Complete — ' + processed + ' optimized, ' +
					skipped + ' skipped, ' + errors + ' errors.';
				break;
			case 'cancelled':
				progressText.textContent = 'Cancelled.';
				break;
			default:
				progressText.textContent = 'Idle — ready to start.';
		}

		var active = status === 'running';
		var paused = status === 'paused';
		show( startBtn, ! active && ! paused );
		show( pauseBtn, active );
		show( resumeBtn, paused );
		show( cancelBtn, active || paused );

		if ( stats ) {
			updateStat( 'done', stats.by_status ? stats.by_status.done : null );
			updateStat( 'errors', stats.by_status ? stats.by_status.error : null );
			updateStat( 'saved_pct', stats.saved_pct != null ? stats.saved_pct + '%' : null );
		}

		if ( active || paused ) {
			startPolling();
		} else {
			stopPolling();
		}
	}

	function updateStat( name, value ) {
		if ( value == null ) {
			return;
		}
		var node = document.querySelector( '[data-blt-stat="' + name + '"]' );
		if ( node ) {
			node.textContent = value;
		}
	}

	function poll() {
		post( 'blt_bulk_status' ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data.state, res.data.stats );
			}
		} );
	}

	function startPolling() {
		if ( ! pollTimer ) {
			pollTimer = window.setInterval( poll, POLL_INTERVAL );
		}
	}

	function stopPolling() {
		if ( pollTimer ) {
			window.clearInterval( pollTimer );
			pollTimer = null;
		}
	}

	if ( startBtn ) {
		startBtn.addEventListener( 'click', function () {
			startBtn.disabled = true;
			progressText.textContent = BltOptimizer.i18n.starting;
			setMessage( '' );

			post( 'blt_bulk_start' ).then( function ( res ) {
				startBtn.disabled = false;
				if ( res && res.success ) {
					setMessage( res.data.queued + ' images queued in ' + res.data.batches + ' batches.', 'success' );
					render( res.data.state );
				} else {
					setMessage( ( res && res.data && res.data.message ) || 'Failed to start.', 'error' );
				}
			} ).catch( function () {
				startBtn.disabled = false;
				setMessage( 'Request failed.', 'error' );
			} );
		} );
	}

	function control( action, confirmText ) {
		if ( confirmText && ! window.confirm( confirmText ) ) {
			return;
		}
		post( 'blt_bulk_control', { control: action } ).then( function ( res ) {
			if ( res && res.success ) {
				render( res.data.state );
			}
		} );
	}

	if ( pauseBtn ) {
		pauseBtn.addEventListener( 'click', function () {
			control( 'pause' );
		} );
	}
	if ( resumeBtn ) {
		resumeBtn.addEventListener( 'click', function () {
			control( 'resume' );
		} );
	}
	if ( cancelBtn ) {
		cancelBtn.addEventListener( 'click', function () {
			control( 'cancel', BltOptimizer.i18n.confirmCancel );
		} );
	}

	// Kick off polling if a run is already active on page load.
	var initialStatus = panel.getAttribute( 'data-status' );
	if ( initialStatus === 'running' || initialStatus === 'paused' ) {
		poll();
	}
} )();
