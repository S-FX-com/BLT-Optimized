<?php
/**
 * Google PageSpeed Insights — real Lighthouse lab data and Chrome UX field data.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * The profiler and page auditor measure the site from the inside; PageSpeed
 * Insights measures it the way Google (and a visitor on a mid-range phone)
 * sees it: Lighthouse lab metrics (LCP, CLS, TBT, FCP, Speed Index, TTFB),
 * Chrome UX Report field data when Google has enough traffic to report it, the
 * LCP element, and per-file opportunities (unused JavaScript/CSS,
 * render-blocking files, image audits, third parties…).
 *
 * Every opportunity item is a URL, so each one is attributed to the plugin,
 * theme or third party that serves it (BLT_Optimized_Components::from_url())
 * and findings are grouped per component: "Contact Form 7 ships 85 KB of
 * unused JavaScript on the home page" is something a site owner can act on;
 * "Reduce unused JavaScript" is not.
 *
 * The service is opt-in (it sends the page URL to Google) and this class
 * stores nothing itself: run() returns a parsed result, findings() turns
 * parsed results into findings, and the orchestrator persists them. The API
 * key is only ever sent to Google and is never logged or put in an error.
 */
class BLT_Optimized_PSI {

	/**
	 * PageSpeed Insights v5 endpoint.
	 */
	const ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Where to get a free API key.
	 */
	const DOCS_URL = 'https://developers.google.com/speed/docs/insights/v5/get-started';

	/**
	 * Request timeout in seconds (Lighthouse runs can take most of a minute).
	 */
	const TIMEOUT = 90;

	/**
	 * Items kept per audit.
	 */
	const MAX_ITEMS = 25;

	/**
	 * Components that get their own finding per audit (largest first).
	 */
	const MAX_COMPONENTS = 10;

	/**
	 * Per-component thresholds for severity: load-time savings.
	 */
	const WARN_MS = 1000;
	const CRIT_MS = 3000;

	/**
	 * Per-component thresholds for severity: main-thread time (JS run time,
	 * third-party blocking time). Lower than load savings because it is
	 * time the page cannot respond to taps.
	 */
	const WARN_BLOCK_MS = 300;
	const CRIT_BLOCK_MS = 1000;

	/**
	 * Per-component thresholds for severity: bytes.
	 */
	const WARN_BYTES = 512000;
	const CRIT_BYTES = 2097152;

	/**
	 * Components wasting less than this are not worth a finding.
	 */
	const MIN_BYTES = 1024;

	/**
	 * Lab metric key => Lighthouse audit id.
	 */
	const METRIC_AUDITS = array(
		'lcp'  => 'largest-contentful-paint',
		'cls'  => 'cumulative-layout-shift',
		'tbt'  => 'total-blocking-time',
		'fcp'  => 'first-contentful-paint',
		'si'   => 'speed-index',
		'ttfb' => 'server-response-time',
	);

	/**
	 * Lighthouse audits kept in a parsed result (only failing ones).
	 */
	const AUDITS = array(
		'render-blocking-resources',
		'unused-javascript',
		'unused-css-rules',
		'unminified-javascript',
		'unminified-css',
		'uses-optimized-images',
		'modern-image-formats',
		'uses-responsive-images',
		'offscreen-images',
		'efficient-animated-content',
		'server-response-time',
		'uses-text-compression',
		'uses-long-cache-ttl',
		'total-byte-weight',
		'dom-size',
		'bootup-time',
		'mainthread-work-breakdown',
		'third-party-summary',
		'lcp-lazy-loaded',
		'unsized-images',
		'font-display',
		'largest-contentful-paint-element',
		'layout-shifts',
		'layout-shift-elements',
		'legacy-javascript',
		'duplicated-javascript',
	);

	/**
	 * Lighthouse 13 replaced many audits with "insight" audits. When a
	 * response has the insight but not the audit it replaces, the insight is
	 * read as that audit (same item shapes: url, wastedBytes, totalBytes…).
	 * Insight id => audit id.
	 */
	const INSIGHT_ALIASES = array(
		'render-blocking-insight'       => 'render-blocking-resources',
		'image-delivery-insight'        => 'uses-optimized-images',
		'third-parties-insight'         => 'third-party-summary',
		'dom-size-insight'              => 'dom-size',
		'cache-insight'                 => 'uses-long-cache-ttl',
		'document-latency-insight'      => 'server-response-time',
		'legacy-javascript-insight'     => 'legacy-javascript',
		'duplicated-javascript-insight' => 'duplicated-javascript',
		'font-display-insight'          => 'font-display',
		'cls-culprits-insight'          => 'layout-shifts',
	);

	/* ------------------------------------------------------------------ */
	/* Settings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether the site owner opted in to PageSpeed Insights.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$settings = $this->settings();
		return ! empty( $settings['enable_psi'] );
	}

	/**
	 * Plugin settings (empty array before the main class is loaded).
	 *
	 * @return array
	 */
	private function settings() {
		if ( class_exists( 'BLT_Optimized' ) && method_exists( 'BLT_Optimized', 'get_settings' ) ) {
			$settings = BLT_Optimized::get_settings();
			return is_array( $settings ) ? $settings : array();
		}
		return array();
	}

	/* ------------------------------------------------------------------ */
	/* Request                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Ask Google to test a URL and return the parsed result.
	 *
	 * @param string $url      Public URL to test.
	 * @param string $strategy mobile|desktop.
	 * @return array|WP_Error Parsed result (see parse()) or an error with a
	 *                        message a site owner can act on. Error data:
	 *                        { status: int, reason: string }.
	 */
	public function run( $url, $strategy = 'mobile' ) {
		if ( ! $this->is_enabled() ) {
			return new WP_Error(
				'blt_psi_disabled',
				__( 'Google PageSpeed Insights is turned off. Enable it under BLT Optimized → Settings (it sends the page URL to Google).', 'blt-optimized' )
			);
		}

		$url      = trim( (string) $url );
		$strategy = strtolower( trim( (string) $strategy ) );
		if ( ! in_array( $strategy, array( 'mobile', 'desktop' ), true ) ) {
			return new WP_Error( 'blt_psi_bad_strategy', __( 'PageSpeed Insights can test the "mobile" or the "desktop" layout.', 'blt-optimized' ) );
		}

		$parts  = wp_parse_url( $url );
		$scheme = is_array( $parts ) && isset( $parts['scheme'] ) ? strtolower( $parts['scheme'] ) : '';
		$host   = is_array( $parts ) && isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) || '' === $host ) {
			return new WP_Error( 'blt_psi_bad_url', __( 'PageSpeed Insights needs a full http:// or https:// page URL.', 'blt-optimized' ) );
		}
		if ( self::is_unreachable_host( $host ) ) {
			return new WP_Error(
				'blt_psi_local_url',
				sprintf(
					/* translators: %s: host name. */
					__( 'Google can\'t test %s: it is a local or private address, and PageSpeed Insights must be able to load the page from the public internet. Local, staging, password-protected and maintenance-mode sites can\'t be tested — run the scan again on the live site.', 'blt-optimized' ),
					$host
				),
				array(
					'status' => 0,
					'reason' => 'local_host',
				)
			);
		}

		$settings = $this->settings();
		$key      = isset( $settings['psi_api_key'] ) ? trim( (string) $settings['psi_api_key'] ) : '';

		$query = array(
			'url'      => $url,
			'strategy' => $strategy,
			'category' => 'performance',
		);
		if ( '' !== $key ) {
			$query['key'] = $key;
		}
		$request_url = self::ENDPOINT . '?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 );

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 150 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- disabled on some hosts.
		}

		$response = wp_remote_get(
			$request_url,
			array(
				'timeout'     => self::TIMEOUT,
				'redirection' => 2,
				'user-agent'  => 'BLT-Optimized/' . ( defined( 'BLT_OPTIMIZED_VERSION' ) ? BLT_OPTIMIZED_VERSION : '1' ) . '; ' . home_url( '/' ),
				'headers'     => array(
					'Accept'  => 'application/json',
					// Keys restricted by HTTP referrer accept server-side calls that say who they are.
					'Referer' => home_url( '/' ),
				),
			)
		);
		unset( $request_url );

		if ( is_wp_error( $response ) ) {
			return $this->transport_error( $response, $key );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = wp_remote_retrieve_body( $response );
		$json   = '' === $body ? null : json_decode( $body, true );
		unset( $response, $body );

		if ( $status < 200 || $status >= 300 ) {
			return $this->api_error( $status, is_array( $json ) ? $json : array(), $key );
		}

		if ( ! is_array( $json ) ) {
			return new WP_Error(
				'blt_psi_bad_response',
				sprintf(
					/* translators: %d: HTTP status code. */
					__( 'Google answered (HTTP %d) but the response wasn\'t valid PageSpeed Insights data. Try again in a minute.', 'blt-optimized' ),
					$status
				),
				array(
					'status' => $status,
					'reason' => 'invalid_json',
				)
			);
		}

		// A 200 can still carry a Lighthouse runtime error (page never painted…).
		$runtime = isset( $json['lighthouseResult']['runtimeError'] ) && is_array( $json['lighthouseResult']['runtimeError'] )
			? $json['lighthouseResult']['runtimeError']
			: array();
		if ( ! empty( $runtime['code'] ) && 'NO_ERROR' !== $runtime['code'] ) {
			return $this->api_error(
				500,
				array(
					'error' => array(
						'message' => 'Lighthouse returned error: ' . $runtime['code'] . '. ' . ( isset( $runtime['message'] ) ? (string) $runtime['message'] : '' ),
					),
				),
				$key
			);
		}

		if ( ! isset( $json['lighthouseResult']['audits'] ) || ! is_array( $json['lighthouseResult']['audits'] )
			|| ! isset( $json['lighthouseResult']['categories']['performance']['score'] )
			|| ! is_numeric( $json['lighthouseResult']['categories']['performance']['score'] ) ) {
			return new WP_Error(
				'blt_psi_no_score',
				__( 'Google tested the page but Lighthouse didn\'t produce a performance score (usually because the page loaded no visible content for the test browser). Check that the page is public and renders without logging in, then try again.', 'blt-optimized' ),
				array(
					'status' => $status,
					'reason' => 'no_score',
				)
			);
		}

		return $this->parse( $json, $url, $strategy );
	}

	/**
	 * Hosts Google can never reach: localhost, private/reserved IPs and
	 * reserved development TLDs. Saves a 90-second wait and a quota unit.
	 *
	 * @param string $host Lower-case host.
	 * @return bool
	 */
	private static function is_unreachable_host( $host ) {
		$host = trim( $host, '[]' );
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
		}
		if ( 'localhost' === $host || false === strpos( $host, '.' ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(local|localhost|test|invalid|example|internal|lan|home\.arpa)$/', $host );
	}

	/**
	 * Map a wp_remote_get() failure (no HTTP response) to a helpful error.
	 *
	 * @param WP_Error $error Transport error.
	 * @param string   $key   API key, scrubbed from any message.
	 * @return WP_Error
	 */
	private function transport_error( WP_Error $error, $key ) {
		$message = self::scrub( $error->get_error_message(), $key );

		if ( false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) {
			return new WP_Error(
				'blt_psi_timeout',
				sprintf(
					/* translators: %d: seconds. */
					__( 'Google didn\'t answer within %d seconds. PageSpeed Insights can be slow on heavy pages — try again in a minute. If it keeps happening, the site may be slow or blocking Google\'s test browser.', 'blt-optimized' ),
					self::TIMEOUT
				),
				array(
					'status' => 0,
					'reason' => 'timeout',
				)
			);
		}

		return new WP_Error(
			'blt_psi_network',
			sprintf(
				/* translators: %s: low-level error message. */
				__( 'This server couldn\'t reach Google\'s PageSpeed Insights API (%s). Check that outbound HTTPS requests to www.googleapis.com are allowed by your host or firewall.', 'blt-optimized' ),
				$message
			),
			array(
				'status' => 0,
				'reason' => 'network',
			)
		);
	}

	/**
	 * Map an API error response to a helpful error.
	 *
	 * @param int    $status  HTTP status.
	 * @param array  $payload Decoded error body (may be empty).
	 * @param string $key     API key, scrubbed from any message.
	 * @return WP_Error
	 */
	private function api_error( $status, array $payload, $key ) {
		$error   = isset( $payload['error'] ) && is_array( $payload['error'] ) ? $payload['error'] : array();
		$message = self::scrub( isset( $error['message'] ) ? (string) $error['message'] : '', $key );

		$reasons = array();
		foreach ( array( 'errors', 'details' ) as $bag ) {
			if ( isset( $error[ $bag ] ) && is_array( $error[ $bag ] ) ) {
				foreach ( $error[ $bag ] as $entry ) {
					if ( is_array( $entry ) && isset( $entry['reason'] ) ) {
						$reasons[] = (string) $entry['reason'];
					}
				}
			}
		}
		$state    = isset( $error['status'] ) ? (string) $error['status'] : '';
		$haystack = $message . ' ' . implode( ' ', $reasons ) . ' ' . $state;
		$has_key  = '' !== $key;
		$data     = array(
			'status' => (int) $status,
			'reason' => '',
		);

		// Quota.
		if ( 429 === $status || 'RESOURCE_EXHAUSTED' === $state || preg_match( '/rateLimitExceeded|quotaExceeded|RATE_LIMIT_EXCEEDED|dailyLimitExceeded|userRateLimitExceeded|quota/i', $haystack ) ) {
			$data['reason'] = 'quota';
			if ( $has_key ) {
				$text = sprintf(
					/* translators: %s: documentation URL. */
					__( 'Your PageSpeed Insights API key has used up its quota (limits are per minute and per day). Wait a few minutes — or until tomorrow if the daily limit is gone — and run the scan again. You can check usage in the Google Cloud Console. Details: %s', 'blt-optimized' ),
					self::DOCS_URL
				);
			} else {
				$text = sprintf(
					/* translators: %s: documentation URL. */
					__( 'Google\'s PageSpeed Insights quota is used up. Requests without an API key share a very small quota, so add a free API key under BLT Optimized → Settings and run the scan again. Get one here: %s', 'blt-optimized' ),
					self::DOCS_URL
				);
			}
			return new WP_Error( 'blt_psi_quota', $text, $data );
		}

		// Invalid key.
		if ( preg_match( '/API key not valid|API_KEY_INVALID|API key expired|keyInvalid|API key.*(invalid|not found)/i', $haystack ) ) {
			$data['reason'] = 'bad_key';
			return new WP_Error(
				'blt_psi_bad_key',
				sprintf(
					/* translators: %s: documentation URL. */
					__( 'Google rejected the PageSpeed Insights API key. Re-copy it under BLT Optimized → Settings (or clear the field to run without a key). Details: %s', 'blt-optimized' ),
					self::DOCS_URL
				),
				$data
			);
		}

		// Key restrictions / API not enabled.
		if ( 403 === $status || 'PERMISSION_DENIED' === $state || preg_match( '/referer|SERVICE_DISABLED|has not been used in project|API_KEY_HTTP_REFERRER_BLOCKED|API_KEY_SERVICE_BLOCKED/i', $haystack ) ) {
			$data['reason'] = 'permission';
			return new WP_Error(
				'blt_psi_permission',
				sprintf(
					/* translators: 1: Google's error message, 2: documentation URL. */
					__( 'Google refused the PageSpeed Insights request (%1$s). The API key\'s Google Cloud project must have the "PageSpeed Insights API" enabled, and the key must not be restricted to other APIs or to IP addresses. Details: %2$s', 'blt-optimized' ),
					'' !== $message ? self::excerpt( $message, 160 ) : 'HTTP ' . (int) $status,
					self::DOCS_URL
				),
				$data
			);
		}

		// Lighthouse could not test the page.
		if ( preg_match( '/\b(FAILED_DOCUMENT_REQUEST|ERRORED_DOCUMENT_REQUEST|NO_FCP|NO_LCP|NO_DOCUMENT_REQUEST|DNS_FAILURE|INSECURE_DOCUMENT_REQUEST|INVALID_URL)\b/', $haystack, $m ) ) {
			$data['reason'] = strtolower( $m[1] );
			$text           = __( 'Google couldn\'t load the page. It must be publicly reachable: staging, password-protected, maintenance-mode and local sites can\'t be tested, and a firewall or bot-protection rule that blocks Google\'s test browser has the same effect.', 'blt-optimized' );
			if ( preg_match( '/Status code:\s*(\d{3})/i', $message, $sc ) ) {
				$code = (int) $sc[1];
				if ( in_array( $code, array( 401, 403 ), true ) ) {
					/* translators: %d: HTTP status code. */
					$text .= ' ' . sprintf( __( 'Your server answered Google with HTTP %d (access denied), which points to a login wall, firewall or bot protection.', 'blt-optimized' ), $code );
				} elseif ( 404 === $code ) {
					$text .= ' ' . __( 'Your server answered Google with HTTP 404 — check that this exact URL exists.', 'blt-optimized' );
				} elseif ( $code >= 500 ) {
					/* translators: %d: HTTP status code. */
					$text .= ' ' . sprintf( __( 'Your server answered Google with HTTP %d (server error) — check the PHP error log.', 'blt-optimized' ), $code );
				}
			} elseif ( 'no_fcp' === $data['reason'] || 'no_lcp' === $data['reason'] ) {
				$text .= ' ' . __( 'The page loaded but never painted any content in Google\'s test browser (blank page, redirect loop or a "checking your browser" interstitial).', 'blt-optimized' );
			}
			return new WP_Error( 'blt_psi_unreachable_url', $text, $data );
		}

		if ( preg_match( '/\b(PROTOCOL_TIMEOUT|PAGE_HUNG|TIMEOUT|DEADLINE_EXCEEDED)\b/i', $haystack ) ) {
			$data['reason'] = 'lighthouse_timeout';
			return new WP_Error(
				'blt_psi_timeout',
				__( 'Google\'s test browser gave up waiting for the page to finish loading. The page may be very slow or may never stop loading (endless polling, a hanging third-party request). Try again; if it repeats, that is itself a finding worth fixing.', 'blt-optimized' ),
				$data
			);
		}

		if ( preg_match( '/\bNOT_HTML\b/', $haystack ) ) {
			$data['reason'] = 'not_html';
			return new WP_Error( 'blt_psi_not_html', __( 'The URL doesn\'t return an HTML page (it looks like a file or feed), so PageSpeed Insights can\'t test it.', 'blt-optimized' ), $data );
		}

		if ( 400 === $status ) {
			$data['reason'] = 'bad_request';
			return new WP_Error(
				'blt_psi_bad_request',
				sprintf(
					/* translators: %s: Google's error message. */
					__( 'Google rejected the PageSpeed Insights request: %s', 'blt-optimized' ),
					'' !== $message ? self::excerpt( $message, 240 ) : __( 'no details given', 'blt-optimized' )
				),
				$data
			);
		}

		if ( $status >= 500 ) {
			$data['reason'] = 'server_error';
			return new WP_Error(
				'blt_psi_server_error',
				sprintf(
					/* translators: 1: HTTP status code, 2: Google's error message. */
					__( 'Google\'s PageSpeed Insights service had a problem (HTTP %1$d). Try again in a minute. %2$s', 'blt-optimized' ),
					(int) $status,
					'' !== $message ? self::excerpt( $message, 200 ) : ''
				),
				$data
			);
		}

		$data['reason'] = 'http_error';
		return new WP_Error(
			'blt_psi_error',
			sprintf(
				/* translators: 1: HTTP status code, 2: Google's error message. */
				__( 'PageSpeed Insights returned an unexpected response (HTTP %1$d). %2$s', 'blt-optimized' ),
				(int) $status,
				'' !== $message ? self::excerpt( $message, 200 ) : ''
			),
			$data
		);
	}

	/**
	 * Remove the API key from text that may be shown or logged.
	 *
	 * @param string $text Text.
	 * @param string $key  API key.
	 * @return string
	 */
	private static function scrub( $text, $key ) {
		$text = (string) $text;
		if ( '' !== $key ) {
			$text = str_replace( $key, '***', $text );
		}
		return trim( $text );
	}

	/* ------------------------------------------------------------------ */
	/* Parsing                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Reduce a PageSpeed Insights response to what the scan uses.
	 *
	 * Defensive by design: Lighthouse omits keys freely (and renames them
	 * between versions), so every access is checked.
	 *
	 * @param array  $json     Decoded API response.
	 * @param string $url      Tested URL.
	 * @param string $strategy mobile|desktop.
	 * @return array {
	 *     url, strategy, fetched_at, score (int 0-100, null if Lighthouse gave none),
	 *     metrics: { lcp|cls|tbt|fcp|si|ttfb: { value, display, score } },
	 *     field: { overall, lcp_ms, cls, inp_ms, fcp_ms, ttfb_ms, scope } | null,
	 *     lcp_element: { snippet, selector, label } | null,
	 *     audits: { audit id: { title, score, display, savings_ms, savings_bytes, value,
	 *                           items: [ { url, component, wasted_bytes, total_bytes, wasted_ms, label } ] } }
	 * }
	 */
	public function parse( array $json, string $url, string $strategy ): array {
		$lh     = isset( $json['lighthouseResult'] ) && is_array( $json['lighthouseResult'] ) ? $json['lighthouseResult'] : array();
		$audits = isset( $lh['audits'] ) && is_array( $lh['audits'] ) ? $lh['audits'] : array();

		$score = null;
		if ( isset( $lh['categories']['performance']['score'] ) && is_numeric( $lh['categories']['performance']['score'] ) ) {
			$score = (int) round( (float) $lh['categories']['performance']['score'] * 100 );
		}

		// Hosts that are "this site" even when they differ from home_url() (a
		// staging copy, www vs non-www, a redirect the test followed).
		$site_hosts = array();
		foreach ( array( $url, isset( $lh['finalUrl'] ) ? $lh['finalUrl'] : '', isset( $lh['requestedUrl'] ) ? $lh['requestedUrl'] : '' ) as $candidate ) {
			$host = strtolower( (string) wp_parse_url( (string) $candidate, PHP_URL_HOST ) );
			if ( '' !== $host && ! in_array( $host, $site_hosts, true ) ) {
				$site_hosts[] = $host;
			}
		}

		// Lab metrics come only from the real metric audits.
		$metrics = array();
		foreach ( self::METRIC_AUDITS as $key => $audit_id ) {
			if ( ! isset( $audits[ $audit_id ] ) || ! is_array( $audits[ $audit_id ] ) || ! isset( $audits[ $audit_id ]['numericValue'] ) || ! is_numeric( $audits[ $audit_id ]['numericValue'] ) ) {
				continue;
			}
			$audit           = $audits[ $audit_id ];
			$metrics[ $key ] = array(
				'value'   => (float) $audit['numericValue'],
				// The TTFB audit's own text is a sentence ("Root document took 1,240 ms"); show the number.
				'display' => 'ttfb' !== $key && isset( $audit['displayValue'] ) && is_scalar( $audit['displayValue'] ) && '' !== (string) $audit['displayValue'] ? self::excerpt( (string) $audit['displayValue'], 60 ) : self::format_metric( $key, (float) $audit['numericValue'] ),
				'score'   => isset( $audit['score'] ) && is_numeric( $audit['score'] ) ? (float) $audit['score'] : null,
			);
		}

		// Read insight audits as the audits they replaced, when those are absent.
		foreach ( self::INSIGHT_ALIASES as $insight => $legacy ) {
			if ( ! isset( $audits[ $legacy ] ) && isset( $audits[ $insight ] ) ) {
				$audits[ $legacy ] = $audits[ $insight ];
			}
		}

		$env = array(
			'site_hosts' => $site_hosts,
			'entities'   => $this->entity_names( $lh ),
		);

		$kept = array();
		foreach ( self::AUDITS as $audit_id ) {
			if ( ! isset( $audits[ $audit_id ] ) || ! is_array( $audits[ $audit_id ] ) ) {
				continue;
			}
			$parsed = $this->parse_audit( $audit_id, $audits[ $audit_id ], $env );
			if ( null !== $parsed ) {
				$kept[ $audit_id ] = $parsed;
			}
		}

		$lcp_element = isset( $audits['largest-contentful-paint-element'] ) && is_array( $audits['largest-contentful-paint-element'] )
			? $this->find_lcp_element( $audits['largest-contentful-paint-element'] )
			: null;

		return array(
			'url'         => $url,
			'strategy'    => $strategy,
			'fetched_at'  => time(),
			'score'       => $score,
			'metrics'     => $metrics,
			'field'       => $this->parse_field( $json ),
			'lcp_element' => $lcp_element,
			'audits'      => $kept,
		);
	}

	/**
	 * Parse one audit; null when it passes or has nothing to show.
	 *
	 * @param string $audit_id Audit id.
	 * @param array  $audit    Lighthouse audit.
	 * @param array  $env      Attribution environment (site_hosts, entities).
	 * @return array|null
	 */
	private function parse_audit( $audit_id, array $audit, array $env ) {
		$mode = isset( $audit['scoreDisplayMode'] ) ? (string) $audit['scoreDisplayMode'] : '';
		if ( in_array( $mode, array( 'notApplicable', 'error', 'manual' ), true ) ) {
			return null;
		}

		$score = isset( $audit['score'] ) && is_numeric( $audit['score'] ) ? (float) $audit['score'] : null;
		if ( null !== $score && $score >= 0.9 ) {
			return null;
		}

		$details = isset( $audit['details'] ) && is_array( $audit['details'] ) ? $audit['details'] : array();

		$items = array();
		foreach ( $this->flatten_items( $details ) as $raw ) {
			$item = $this->parse_item( $audit_id, $raw, $env );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		// Savings: Lighthouse's own totals first, then the metric savings,
		// then (opportunities only) the audit's numeric value, which is the
		// estimated saving for that type of audit.
		$savings_ms = 0.0;
		if ( isset( $details['overallSavingsMs'] ) && is_numeric( $details['overallSavingsMs'] ) ) {
			$savings_ms = (float) $details['overallSavingsMs'];
		} elseif ( isset( $audit['metricSavings'] ) && is_array( $audit['metricSavings'] ) ) {
			foreach ( $audit['metricSavings'] as $metric => $saved ) {
				if ( 'CLS' !== $metric && is_numeric( $saved ) ) {
					$savings_ms = max( $savings_ms, (float) $saved );
				}
			}
		} elseif ( isset( $details['type'] ) && 'opportunity' === $details['type'] && isset( $audit['numericValue'] ) && is_numeric( $audit['numericValue'] ) ) {
			$savings_ms = (float) $audit['numericValue'];
		}

		if ( isset( $details['overallSavingsBytes'] ) && is_numeric( $details['overallSavingsBytes'] ) ) {
			$savings_bytes = (int) $details['overallSavingsBytes'];
		} else {
			$savings_bytes = (int) array_sum( wp_list_pluck( $items, 'wasted_bytes' ) );
		}

		$value = isset( $audit['numericValue'] ) && is_numeric( $audit['numericValue'] ) ? (float) $audit['numericValue'] : null;

		// "null with items": informative audits only count when they show something.
		if ( null === $score && empty( $items ) && $savings_ms <= 0 && $savings_bytes <= 0 ) {
			return null;
		}

		// Biggest offenders first; usort is stable so equal items keep Lighthouse's order.
		usort(
			$items,
			static function ( $a, $b ) {
				return array( $b['wasted_bytes'], $b['wasted_ms'], $b['total_bytes'] ) <=> array( $a['wasted_bytes'], $a['wasted_ms'], $a['total_bytes'] );
			}
		);

		return array(
			'title'         => isset( $audit['title'] ) && is_scalar( $audit['title'] ) ? self::excerpt( (string) $audit['title'], 160 ) : $audit_id,
			'score'         => $score,
			'display'       => isset( $audit['displayValue'] ) && is_scalar( $audit['displayValue'] ) ? self::excerpt( (string) $audit['displayValue'], 120 ) : '',
			'savings_ms'    => round( $savings_ms, 1 ),
			'savings_bytes' => $savings_bytes,
			'value'         => $value,
			'items'         => array_slice( $items, 0, self::MAX_ITEMS ),
		);
	}

	/**
	 * Rows of an audit's details. Lighthouse 12+ nests rows in sub-tables
	 * ("list" details, insights); flatten those into one row list.
	 *
	 * @param array $details Audit details.
	 * @return array[]
	 */
	private function flatten_items( array $details ) {
		$rows = array();
		if ( ! isset( $details['items'] ) || ! is_array( $details['items'] ) ) {
			return $rows;
		}
		foreach ( $details['items'] as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['type'], $item['items'] ) && in_array( $item['type'], array( 'table', 'list', 'opportunity' ), true ) && is_array( $item['items'] ) ) {
				foreach ( $item['items'] as $sub ) {
					if ( is_array( $sub ) ) {
						$rows[] = $sub;
					}
				}
				continue;
			}
			$rows[] = $item;
		}
		return $rows;
	}

	/**
	 * Parse one details row.
	 *
	 * @param string $audit_id Audit id (some audits name their numbers differently).
	 * @param array  $raw      Row.
	 * @param array  $env      Attribution environment (site_hosts, entities).
	 * @return array|null
	 */
	private function parse_item( $audit_id, array $raw, array $env ) {
		$url   = self::url_of( $raw );
		$entry = '';
		$label = '';

		// Third-party rows are per entity; their files are in subItems.
		if ( '' === $url && isset( $raw['subItems']['items'][0] ) && is_array( $raw['subItems']['items'][0] ) ) {
			$url = self::url_of( $raw['subItems']['items'][0] );
		}

		$node = $this->node_of( $raw );

		switch ( $audit_id ) {
			case 'third-party-summary':
				$entry = self::text_of( isset( $raw['entity'] ) ? $raw['entity'] : '' );
				$main  = self::num( $raw, array( 'mainThreadTime' ) );
				$label = '' !== $entry ? $entry : $label;
				if ( $main > 0 ) {
					/* translators: %s: milliseconds of main-thread time. */
					$label = trim( $label . ' — ' . sprintf( __( 'main thread %s', 'blt-optimized' ), self::format_ms( $main ) ) );
				}
				break;

			case 'bootup-time':
				$scripting = self::num( $raw, array( 'scripting' ) );
				$parse     = self::num( $raw, array( 'scriptParseCompile' ) );
				/* translators: 1: scripting ms, 2: parse/compile ms. */
				$label = sprintf( __( 'run %1$s, parse %2$s', 'blt-optimized' ), self::format_ms( $scripting ), self::format_ms( $parse ) );
				break;

			case 'mainthread-work-breakdown':
				$label = self::text_of( isset( $raw['groupLabel'] ) ? $raw['groupLabel'] : ( isset( $raw['group'] ) ? $raw['group'] : '' ) );
				break;

			case 'uses-long-cache-ttl':
				$ttl = self::num( $raw, array( 'cacheLifetimeMs' ) );
				/* translators: %s: cache lifetime, e.g. "1 hour". */
				$label = $ttl < 1000 ? __( 'not cached', 'blt-optimized' ) : sprintf( __( 'cached for %s', 'blt-optimized' ), self::human_duration( (int) round( $ttl / 1000 ) ) );
				break;

			case 'duplicated-javascript':
				$label = self::text_of( isset( $raw['source'] ) ? $raw['source'] : '' );
				break;

			case 'legacy-javascript':
				$signals = array();
				if ( isset( $raw['subItems']['items'] ) && is_array( $raw['subItems']['items'] ) ) {
					foreach ( $raw['subItems']['items'] as $sub ) {
						if ( is_array( $sub ) && isset( $sub['signal'] ) ) {
							$signals[] = self::text_of( $sub['signal'] );
						}
					}
				}
				$label = implode( ', ', array_slice( array_unique( array_filter( $signals ) ), 0, 4 ) );
				break;

			case 'layout-shifts':
			case 'layout-shift-elements':
				$label = $node['label'];
				if ( isset( $raw['score'] ) && is_numeric( $raw['score'] ) ) {
					$label = trim( $label . ' — CLS ' . number_format_i18n( (float) $raw['score'], 3 ) );
				}
				break;

			case 'dom-size':
				$stat = self::text_of( isset( $raw['statistic'] ) ? $raw['statistic'] : '' );
				$val  = isset( $raw['value'] ) ? ( is_array( $raw['value'] ) && isset( $raw['value']['value'] ) ? $raw['value']['value'] : $raw['value'] ) : null;
				if ( '' !== $stat ) {
					$label = $stat . ( is_numeric( $val ) ? ': ' . number_format_i18n( (float) $val ) : '' );
					if ( '' !== $node['label'] ) {
						$label .= ' (' . $node['label'] . ')';
					}
				}
				break;

			default:
				$label = $node['label'];
		}

		if ( '' === $url ) {
			// "Unattributable"-style rows: keep the text, don't invent a URL.
			$maybe = self::text_of( isset( $raw['url'] ) ? $raw['url'] : '' );
			if ( '' !== $maybe ) {
				$label = '' === $label ? $maybe : $maybe . ' (' . $label . ')';
			}
		}

		$wasted_bytes = (int) self::num( $raw, array( 'wastedBytes' ) );
		$total_bytes  = (int) self::num( $raw, array( 'totalBytes', 'transferSize', 'resourceSize' ) );
		$wasted_ms    = self::num( $raw, array( 'wastedMs', 'blockingTime', 'total', 'duration', 'responseTime' ) );

		// A row with nothing to say (no file, no element, no number) is noise.
		if ( '' === $url && '' === $label && '' === $node['snippet'] && 0 === $wasted_bytes && 0 === $total_bytes && $wasted_ms <= 0 ) {
			return null;
		}

		$component = null;
		if ( 'third-party-summary' === $audit_id && '' !== $entry ) {
			// Rows are per entity; Lighthouse's entity name beats a host name.
			$component = BLT_Optimized_Components::make( 'external', sanitize_title( $entry ), $entry );
		} elseif ( '' !== $url ) {
			$component = $this->attribute( $url, $env );
		}

		if ( '' === $label && '' !== $url ) {
			$label = self::basename_of( $url );
		}

		return array(
			'url'          => $url,
			'component'    => $component,
			'wasted_bytes' => max( 0, $wasted_bytes ),
			'total_bytes'  => max( 0, $total_bytes ),
			'wasted_ms'    => round( max( 0.0, $wasted_ms ), 1 ),
			'label'        => self::excerpt( $label, 200 ),
		);
	}

	/**
	 * Component for an item URL.
	 *
	 * @param string $url URL.
	 * @param array  $env Attribution environment (site_hosts, entities).
	 * @return array|null
	 */
	private function attribute( $url, array $env ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return null;
		}
		$abs  = 0 === strpos( $url, '//' ) ? 'https:' . $url : $url;
		$host = strtolower( (string) wp_parse_url( $abs, PHP_URL_HOST ) );

		// The tested host is this site even when it isn't home_url()'s host:
		// look the file up under home_url() so plugin/theme paths still map.
		if ( '' !== $host && in_array( $host, $env['site_hosts'], true ) && ! BLT_Optimized_Components::is_local_host( $host ) ) {
			$home   = wp_parse_url( home_url() );
			$origin = ( isset( $home['scheme'] ) ? $home['scheme'] : 'http' ) . '://' . ( isset( $home['host'] ) ? $home['host'] : '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' );
			$query  = wp_parse_url( $abs, PHP_URL_QUERY );
			$abs    = $origin . (string) wp_parse_url( $abs, PHP_URL_PATH ) . ( $query ? '?' . $query : '' );
		}

		$component = BLT_Optimized_Components::from_url( $abs );

		// Lighthouse knows who owns many third-party hosts ("Google Fonts",
		// "reCAPTCHA"…): use its name, and group all of that entity's hosts.
		if ( 'external' === $component['type'] && isset( $env['entities'][ $component['slug'] ] ) ) {
			$name      = $env['entities'][ $component['slug'] ];
			$component = BLT_Optimized_Components::make( 'external', sanitize_title( $name ), $name );
		}
		return $component;
	}

	/**
	 * Host => owner name for third-party origins Lighthouse recognised
	 * (lighthouseResult.entities); first-party and unrecognised ones are left out.
	 *
	 * @param array $lh lighthouseResult.
	 * @return array<string,string>
	 */
	private function entity_names( array $lh ) {
		$names = array();
		if ( ! isset( $lh['entities'] ) || ! is_array( $lh['entities'] ) ) {
			return $names;
		}
		foreach ( $lh['entities'] as $entity ) {
			if ( ! is_array( $entity ) || empty( $entity['name'] ) || ! empty( $entity['isFirstParty'] ) || ! empty( $entity['isUnrecognized'] ) || empty( $entity['origins'] ) || ! is_array( $entity['origins'] ) ) {
				continue;
			}
			foreach ( $entity['origins'] as $origin ) {
				$host = strtolower( (string) wp_parse_url( (string) $origin, PHP_URL_HOST ) );
				if ( '' !== $host ) {
					$names[ $host ] = self::excerpt( (string) $entity['name'], 80 );
				}
			}
		}
		return $names;
	}

	/**
	 * The element Lighthouse measured as the Largest Contentful Paint.
	 *
	 * Older Lighthouse: details.items[0].node. Newer (12+): details.items[0]
	 * is a sub-table whose items[0].node holds the element (a second table
	 * holds the LCP phase breakdown). Both are handled.
	 *
	 * @param array $audit largest-contentful-paint-element audit.
	 * @return array|null { snippet, selector, label }
	 */
	private function find_lcp_element( array $audit ) {
		$items = isset( $audit['details']['items'] ) && is_array( $audit['details']['items'] ) ? $audit['details']['items'] : array();

		$node = null;
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			if ( isset( $item['node'] ) && is_array( $item['node'] ) ) {
				$node = $item['node'];
				break;
			}
			if ( isset( $item['items'] ) && is_array( $item['items'] ) ) {
				foreach ( $item['items'] as $sub ) {
					if ( is_array( $sub ) && isset( $sub['node'] ) && is_array( $sub['node'] ) ) {
						$node = $sub['node'];
						break 2;
					}
				}
			}
		}
		if ( null === $node ) {
			return null;
		}

		$snippet  = isset( $node['snippet'] ) ? self::excerpt( (string) $node['snippet'], 400 ) : '';
		$selector = isset( $node['selector'] ) ? self::excerpt( (string) $node['selector'], 200 ) : '';
		if ( '' === $snippet && '' === $selector ) {
			return null;
		}

		return array(
			'snippet'  => $snippet,
			'selector' => $selector,
			'label'    => isset( $node['nodeLabel'] ) ? self::excerpt( (string) $node['nodeLabel'], 120 ) : '',
		);
	}

	/**
	 * Chrome UX Report data: the tested URL's, else its origin's.
	 *
	 * @param array $json API response.
	 * @return array|null
	 */
	private function parse_field( array $json ) {
		foreach ( array(
			'loadingExperience'       => 'url',
			'originLoadingExperience' => 'origin',
		) as $key => $scope ) {
			$experience = isset( $json[ $key ] ) && is_array( $json[ $key ] ) ? $json[ $key ] : array();
			$metrics    = isset( $experience['metrics'] ) && is_array( $experience['metrics'] ) ? $experience['metrics'] : array();
			if ( empty( $metrics ) ) {
				continue;
			}

			$cls = self::percentile( $metrics, array( 'CUMULATIVE_LAYOUT_SHIFT_SCORE' ) );
			$out = array(
				'overall' => isset( $experience['overall_category'] ) && is_string( $experience['overall_category'] ) && '' !== $experience['overall_category'] ? $experience['overall_category'] : null,
				'lcp_ms'  => self::percentile( $metrics, array( 'LARGEST_CONTENTFUL_PAINT_MS' ) ),
				// CrUX reports the CLS percentile multiplied by 100.
				'cls'     => null === $cls ? null : $cls / 100,
				'inp_ms'  => self::percentile( $metrics, array( 'INTERACTION_TO_NEXT_PAINT', 'EXPERIMENTAL_INTERACTION_TO_NEXT_PAINT' ) ),
				'fcp_ms'  => self::percentile( $metrics, array( 'FIRST_CONTENTFUL_PAINT_MS' ) ),
				'ttfb_ms' => self::percentile( $metrics, array( 'EXPERIMENTAL_TIME_TO_FIRST_BYTE', 'TIME_TO_FIRST_BYTE' ) ),
			);

			if ( null === $out['lcp_ms'] && null === $out['cls'] && null === $out['inp_ms'] && null === $out['fcp_ms'] && null === $out['ttfb_ms'] ) {
				continue;
			}

			$out['scope'] = $scope;
			return $out;
		}
		return null;
	}

	/**
	 * 75th-percentile value of the first CrUX metric present.
	 *
	 * @param array    $metrics CrUX metrics.
	 * @param string[] $names   Candidate metric names.
	 * @return float|null
	 */
	private static function percentile( array $metrics, array $names ) {
		foreach ( $names as $name ) {
			if ( isset( $metrics[ $name ]['percentile'] ) && is_numeric( $metrics[ $name ]['percentile'] ) ) {
				return (float) $metrics[ $name ]['percentile'];
			}
		}
		return null;
	}

	/**
	 * Node details of a row.
	 *
	 * @param array $row Row.
	 * @return array { snippet, selector, label }
	 */
	private function node_of( array $row ) {
		$out = array(
			'snippet'  => '',
			'selector' => '',
			'label'    => '',
		);
		if ( isset( $row['node'] ) && is_array( $row['node'] ) ) {
			$node            = $row['node'];
			$out['snippet']  = isset( $node['snippet'] ) ? self::excerpt( (string) $node['snippet'], 300 ) : '';
			$out['selector'] = isset( $node['selector'] ) ? self::excerpt( (string) $node['selector'], 200 ) : '';
			$label           = isset( $node['nodeLabel'] ) ? (string) $node['nodeLabel'] : '';
			if ( '' === $label ) {
				$label = '' !== $out['snippet'] ? $out['snippet'] : $out['selector'];
			}
			$out['label'] = self::excerpt( $label, 140 );
		}
		return $out;
	}

	/* ------------------------------------------------------------------ */
	/* Small parsing helpers                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * A row's URL ('' when it has none or the value isn't a URL).
	 *
	 * @param array $row Row.
	 * @return string
	 */
	private static function url_of( array $row ) {
		foreach ( array( 'url', 'source' ) as $key ) {
			if ( ! isset( $row[ $key ] ) ) {
				continue;
			}
			$candidate = self::text_of( $row[ $key ], true );
			if ( self::looks_like_url( $candidate ) ) {
				return $candidate;
			}
		}
		return '';
	}

	/**
	 * Whether a string is a fetchable URL (not "Unattributable", not data:).
	 *
	 * @param string $value Value.
	 * @return bool
	 */
	private static function looks_like_url( $value ) {
		return (bool) preg_match( '#^(https?:)?//[^\s/]+#i', $value ) || (bool) preg_match( '#^/[^\s]*$#', $value );
	}

	/**
	 * Text of a Lighthouse value: a string, or an object carrying text/url
	 * (entity links, source locations).
	 *
	 * @param mixed $value    Value.
	 * @param bool  $prefer_url Return the object's url before its text.
	 * @return string
	 */
	private static function text_of( $value, $prefer_url = false ) {
		if ( is_string( $value ) ) {
			return trim( $value );
		}
		if ( is_numeric( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$order = $prefer_url ? array( 'url', 'text', 'value' ) : array( 'text', 'value', 'url' );
			foreach ( $order as $key ) {
				if ( isset( $value[ $key ] ) && is_string( $value[ $key ] ) && '' !== trim( $value[ $key ] ) ) {
					return trim( $value[ $key ] );
				}
			}
		}
		return '';
	}

	/**
	 * First numeric value among the keys.
	 *
	 * @param array    $row  Row.
	 * @param string[] $keys Keys in priority order.
	 * @return float
	 */
	private static function num( array $row, array $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $row[ $key ] ) ) {
				$value = is_array( $row[ $key ] ) && isset( $row[ $key ]['value'] ) ? $row[ $key ]['value'] : $row[ $key ];
				if ( is_numeric( $value ) ) {
					return (float) $value;
				}
			}
		}
		return 0.0;
	}

	/**
	 * Shorten text to a maximum length (multibyte safe).
	 *
	 * @param string $text Text.
	 * @param int    $max  Maximum characters.
	 * @return string
	 */
	private static function excerpt( $text, $max ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( function_exists( 'mb_strlen' ) && function_exists( 'mb_substr' ) ) {
			return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max - 1 ) . '…' : $text;
		}
		return strlen( $text ) > $max ? substr( $text, 0, $max - 1 ) . '…' : $text;
	}

	/**
	 * Last path segment of a URL (the file name).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function basename_of( $url ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$base = '' === $path ? '' : basename( $path );
		return '' !== $base ? $base : $url;
	}

	/**
	 * Metric value as text when Lighthouse sent no displayValue.
	 *
	 * @param string $key   Metric key.
	 * @param float  $value Value.
	 * @return string
	 */
	private static function format_metric( $key, $value ) {
		return 'cls' === $key ? number_format_i18n( $value, 3 ) : self::format_ms( $value );
	}

	/**
	 * Milliseconds as "450 ms" or "1.2 s".
	 *
	 * @param float $ms Milliseconds.
	 * @return string
	 */
	private static function format_ms( $ms ) {
		$ms = (float) $ms;
		if ( $ms >= 1000 ) {
			/* translators: %s: seconds. */
			return sprintf( __( '%s s', 'blt-optimized' ), number_format_i18n( $ms / 1000, 1 ) );
		}
		/* translators: %s: milliseconds. */
		return sprintf( __( '%s ms', 'blt-optimized' ), number_format_i18n( $ms ) );
	}

	/**
	 * Seconds as the largest sensible unit.
	 *
	 * @param int $seconds Seconds.
	 * @return string
	 */
	private static function human_duration( $seconds ) {
		if ( $seconds >= DAY_IN_SECONDS ) {
			$n = (int) round( $seconds / DAY_IN_SECONDS );
			/* translators: %s: number of days. */
			return sprintf( _n( '%s day', '%s days', $n, 'blt-optimized' ), number_format_i18n( $n ) );
		}
		if ( $seconds >= HOUR_IN_SECONDS ) {
			$n = (int) round( $seconds / HOUR_IN_SECONDS );
			/* translators: %s: number of hours. */
			return sprintf( _n( '%s hour', '%s hours', $n, 'blt-optimized' ), number_format_i18n( $n ) );
		}
		if ( $seconds >= MINUTE_IN_SECONDS ) {
			$n = (int) round( $seconds / MINUTE_IN_SECONDS );
			/* translators: %s: number of minutes. */
			return sprintf( _n( '%s minute', '%s minutes', $n, 'blt-optimized' ), number_format_i18n( $n ) );
		}
		/* translators: %s: number of seconds. */
		return sprintf( _n( '%s second', '%s seconds', $seconds, 'blt-optimized' ), number_format_i18n( $seconds ) );
	}

	/* ------------------------------------------------------------------ */
	/* Findings                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Turn parsed results into findings.
	 *
	 * @param array $results List of parse() results (one per URL/strategy). A
	 *                       single parse() result is accepted too.
	 * @return array[] Findings (source "psi"), keys unique per URL + strategy.
	 */
	public static function findings( array $results ) {
		if ( isset( $results['audits'] ) || isset( $results['metrics'] ) ) {
			$results = array( $results );
		}

		$by_key = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) || empty( $result['url'] ) ) {
				continue;
			}
			foreach ( self::result_findings( $result ) as $finding ) {
				$by_key[ $finding['key'] ] = $finding; // A newer run replaces an older one.
			}
		}
		return array_values( $by_key );
	}

	/**
	 * Findings for one parsed result.
	 *
	 * @param array $result parse() result.
	 * @return array[]
	 */
	private static function result_findings( array $result ) {
		$ctx    = self::context( $result );
		$audits = isset( $result['audits'] ) && is_array( $result['audits'] ) ? $result['audits'] : array();
		$out    = array();

		// A result with neither a score nor any metric has nothing to report.
		if ( ( isset( $result['score'] ) && is_numeric( $result['score'] ) ) || ! empty( $result['metrics'] ) ) {
			$out[] = self::score_finding( $result, $ctx );
		}
		$lcp = self::lcp_finding( $result, $ctx );
		if ( null !== $lcp ) {
			$out[] = $lcp;
		}

		foreach ( $audits as $audit_id => $audit ) {
			if ( ! is_array( $audit ) ) {
				continue;
			}
			$audit['id'] = $audit_id;
			$built       = self::audit_findings( $audit_id, $audit, $result, $ctx );
			if ( ! empty( $built ) ) {
				$out = array_merge( $out, $built );
			}
		}
		return $out;
	}

	/**
	 * Per-result context used by every finding.
	 *
	 * @param array $result parse() result.
	 * @return array
	 */
	private static function context( array $result ) {
		$url      = (string) $result['url'];
		$strategy = isset( $result['strategy'] ) && 'desktop' === $result['strategy'] ? 'desktop' : 'mobile';
		$hosts    = array();
		foreach ( array( $url, home_url() ) as $candidate ) {
			$host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
			if ( '' !== $host ) {
				$hosts[] = $host;
			}
		}
		$page = self::page_label( $url );

		return array(
			'url'      => $url,
			'strategy' => $strategy,
			'hosts'    => $hosts,
			'page'     => $page,
			/* translators: 1: page ("the home page" or a path), 2: strategy ("mobile" or "desktop"). */
			'on'       => sprintf( __( '%1$s (%2$s)', 'blt-optimized' ), $page, self::strategy_label( $strategy ) ),
		);
	}

	/**
	 * Build a finding with the PSI defaults (source, key, where).
	 *
	 * @param string $id     Finding id.
	 * @param array  $ctx    Context.
	 * @param array  $args   Finding fields.
	 * @param string $suffix Extra key part (e.g. the component key).
	 * @return array
	 */
	private static function build( $id, array $ctx, array $args, $suffix = '' ) {
		$args['id']     = $id;
		$args['key']    = $id . ':' . $ctx['strategy'] . ':' . $ctx['url'] . ( '' !== $suffix ? ':' . $suffix : '' );
		$args['source'] = 'psi';
		if ( ! isset( $args['where'] ) ) {
			$args['where'] = array( $ctx['url'] );
		}
		return BLT_Optimized_Findings::make( $args );
	}

	/* ---- Score + metrics table -------------------------------------- */

	/**
	 * Overall performance score with a lab + field metrics table.
	 *
	 * @param array $result parse() result.
	 * @param array $ctx    Context.
	 * @return array
	 */
	private static function score_finding( array $result, array $ctx ) {
		$score = isset( $result['score'] ) && is_numeric( $result['score'] ) ? (int) $result['score'] : null;
		$field = isset( $result['field'] ) && is_array( $result['field'] ) ? $result['field'] : null;
		$strat = self::strategy_label( $ctx['strategy'] );

		if ( null === $score ) {
			$severity = 'notice';
		} elseif ( $score < 50 ) {
			$severity = 'critical';
		} elseif ( $score < 90 ) {
			$severity = 'warning';
		} else {
			$severity = 'pass';
		}

		$summary = null === $score
			? sprintf(
				/* translators: %s: strategy. */
				__( 'Google tested the page on %s but returned no performance score; the metrics below are what it measured.', 'blt-optimized' ),
				$strat
			)
			: sprintf(
				/* translators: 1: score, 2: strategy. */
				__( 'Google\'s Lighthouse lab test scored this page %1$d/100 on %2$s. The score blends Largest Contentful Paint, Total Blocking Time, Cumulative Layout Shift, First Contentful Paint and Speed Index; the findings below name the plugins, theme files and images holding it back.', 'blt-optimized' ),
				$score,
				$strat
			);
		if ( 'pass' === $severity ) {
			$summary = sprintf(
				/* translators: 1: score, 2: strategy. */
				__( 'Google\'s Lighthouse lab test scored this page %1$d/100 on %2$s (90 and above is good). Metrics below.', 'blt-optimized' ),
				$score,
				$strat
			);
		}

		if ( null !== $field ) {
			$labels   = array(
				'FAST'    => __( 'good', 'blt-optimized' ),
				'AVERAGE' => __( 'needs improvement', 'blt-optimized' ),
				'SLOW'    => __( 'poor', 'blt-optimized' ),
			);
			$overall  = isset( $field['overall'] ) && isset( $labels[ $field['overall'] ] ) ? $labels[ $field['overall'] ] : '';
			$origin   = isset( $field['scope'] ) && 'origin' === $field['scope'];
			$summary .= ' ';
			if ( '' !== $overall ) {
				$summary .= $origin
					/* translators: %s: rating (good / needs improvement / poor). */
					? sprintf( __( 'Chrome UX Report (real visitors across the whole site, 75th percentile) verdict: %s.', 'blt-optimized' ), $overall )
					/* translators: %s: rating (good / needs improvement / poor). */
					: sprintf( __( 'Chrome UX Report (real visitors, 75th percentile) verdict: %s.', 'blt-optimized' ), $overall );
			} else {
				$summary .= __( 'Real-visitor data (Chrome UX Report) is included below.', 'blt-optimized' );
			}
		} else {
			$summary .= ' ' . __( 'Google has no real-visitor data for this page yet (it needs enough Chrome traffic), so only lab numbers are shown.', 'blt-optimized' );
		}

		$link = 'https://pagespeed.web.dev/analysis?url=' . rawurlencode( $ctx['url'] ) . '&form_factor=' . $ctx['strategy'];

		/* translators: 1: page, 2: score, 3: strategy. */
		$title = null === $score
			? sprintf( __( 'PageSpeed Insights results for %1$s on %2$s', 'blt-optimized' ), $ctx['page'], $strat )
			: sprintf( __( 'PageSpeed Insights scores %1$s %2$d/100 on %3$s', 'blt-optimized' ), $ctx['page'], $score, $strat );

		return self::build(
			'psi.score',
			$ctx,
			array(
				'category' => 'server',
				'severity' => $severity,
				'title'    => $title,
				'summary'  => $summary,
				'impact'   => null === $score ? array() : array(
					'type'  => 'score',
					'value' => $score,
				),
				'details'  => self::metrics_table( $result, $field ),
				'fix'      => array(
					'type'  => 'link',
					'label' => __( 'Open the full PageSpeed report', 'blt-optimized' ),
					'url'   => $link,
				),
			)
		);
	}

	/**
	 * Lab + field metrics with ratings.
	 *
	 * @param array      $result parse() result.
	 * @param array|null $field  Field data.
	 * @return array details { columns, rows }
	 */
	private static function metrics_table( array $result, $field ) {
		$metrics = isset( $result['metrics'] ) && is_array( $result['metrics'] ) ? $result['metrics'] : array();
		$names   = array(
			'lcp'  => __( 'Largest Contentful Paint (LCP)', 'blt-optimized' ),
			'cls'  => __( 'Cumulative Layout Shift (CLS)', 'blt-optimized' ),
			'inp'  => __( 'Interaction to Next Paint (INP)', 'blt-optimized' ),
			'tbt'  => __( 'Total Blocking Time (TBT)', 'blt-optimized' ),
			'fcp'  => __( 'First Contentful Paint (FCP)', 'blt-optimized' ),
			'si'   => __( 'Speed Index', 'blt-optimized' ),
			'ttfb' => __( 'Time to First Byte (TTFB)', 'blt-optimized' ),
		);
		$field_keys = array(
			'lcp'  => 'lcp_ms',
			'cls'  => 'cls',
			'inp'  => 'inp_ms',
			'fcp'  => 'fcp_ms',
			'ttfb' => 'ttfb_ms',
		);

		$dash = '—';
		$rows = array();
		foreach ( $names as $key => $name ) {
			$lab_display = $dash;
			$lab_rating  = $dash;
			if ( isset( $metrics[ $key ] ) && is_array( $metrics[ $key ] ) ) {
				$lab_display = isset( $metrics[ $key ]['display'] ) && '' !== $metrics[ $key ]['display'] ? (string) $metrics[ $key ]['display'] : self::format_metric( $key, (float) $metrics[ $key ]['value'] );
				$lab_rating  = self::rating_label( self::rate( $key, isset( $metrics[ $key ]['value'] ) ? $metrics[ $key ]['value'] : null, isset( $metrics[ $key ]['score'] ) ? $metrics[ $key ]['score'] : null ) );
			}

			$field_display = $dash;
			$field_rating  = $dash;
			if ( null !== $field && isset( $field_keys[ $key ] ) && isset( $field[ $field_keys[ $key ] ] ) && is_numeric( $field[ $field_keys[ $key ] ] ) ) {
				$value         = (float) $field[ $field_keys[ $key ] ];
				$field_display = 'cls' === $key ? number_format_i18n( $value, 2 ) : self::format_metric( $key, $value );
				$field_rating  = self::rating_label( self::rate( $key, $value, null ) );
			}

			if ( $dash === $lab_display && $dash === $field_display ) {
				continue;
			}
			$row = array( $name, $lab_display, $lab_rating );
			if ( null !== $field ) {
				$row[] = $field_display;
				$row[] = $field_rating;
			}
			$rows[] = $row;
		}

		$columns = array( __( 'Metric', 'blt-optimized' ), __( 'Lab (Lighthouse)', 'blt-optimized' ), __( 'Lab rating', 'blt-optimized' ) );
		if ( null !== $field ) {
			$columns[] = __( 'Real visitors (75th percentile)', 'blt-optimized' );
			$columns[] = __( 'Field rating', 'blt-optimized' );
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}

	/**
	 * Good / needs improvement / poor for a metric.
	 *
	 * Lab values use Lighthouse's own score when present (it is tuned per
	 * device); everything else uses the published Web Vitals thresholds.
	 *
	 * @param string     $key   Metric key.
	 * @param float|null $value Value (ms, or unitless for cls).
	 * @param float|null $score Lighthouse 0-1 score.
	 * @return string good|ni|poor|''
	 */
	private static function rate( $key, $value, $score ) {
		if ( null !== $score && is_numeric( $score ) ) {
			return $score >= 0.9 ? 'good' : ( $score >= 0.5 ? 'ni' : 'poor' );
		}
		if ( null === $value || ! is_numeric( $value ) ) {
			return '';
		}
		$thresholds = array(
			'lcp'  => array( 2500, 4000 ),
			'cls'  => array( 0.1, 0.25 ),
			'inp'  => array( 200, 500 ),
			'tbt'  => array( 200, 600 ),
			'fcp'  => array( 1800, 3000 ),
			'si'   => array( 3400, 5800 ),
			'ttfb' => array( 800, 1800 ),
		);
		if ( ! isset( $thresholds[ $key ] ) ) {
			return '';
		}
		return $value <= $thresholds[ $key ][0] ? 'good' : ( $value <= $thresholds[ $key ][1] ? 'ni' : 'poor' );
	}

	/**
	 * Translated rating label.
	 *
	 * @param string $rating good|ni|poor|''.
	 * @return string
	 */
	private static function rating_label( $rating ) {
		switch ( $rating ) {
			case 'good':
				return __( 'Good', 'blt-optimized' );
			case 'ni':
				return __( 'Needs improvement', 'blt-optimized' );
			case 'poor':
				return __( 'Poor', 'blt-optimized' );
		}
		return '—';
	}

	/* ---- LCP element ------------------------------------------------- */

	/**
	 * The slowest-painting element, when LCP isn't good.
	 *
	 * @param array $result parse() result.
	 * @param array $ctx    Context.
	 * @return array|null
	 */
	private static function lcp_finding( array $result, array $ctx ) {
		$element = isset( $result['lcp_element'] ) && is_array( $result['lcp_element'] ) ? $result['lcp_element'] : null;
		$metric  = isset( $result['metrics']['lcp'] ) && is_array( $result['metrics']['lcp'] ) ? $result['metrics']['lcp'] : null;
		if ( null === $element || null === $metric ) {
			return null;
		}

		$rating = self::rate( 'lcp', isset( $metric['value'] ) ? $metric['value'] : null, isset( $metric['score'] ) ? $metric['score'] : null );
		if ( 'ni' !== $rating && 'poor' !== $rating ) {
			return null;
		}

		$snippet = isset( $element['snippet'] ) ? (string) $element['snippet'] : '';
		$name    = '' !== $element['label'] ? $element['label'] : ( '' !== $snippet ? $snippet : $element['selector'] );
		$lcp     = '' !== $metric['display'] ? $metric['display'] : self::format_ms( $metric['value'] );
		$is_img  = (bool) preg_match( '/^<img\b/i', $snippet );
		$lazy    = $is_img && (bool) preg_match( '/\bloading\s*=\s*["\']?lazy/i', $snippet );

		// Attribute the element when its source file belongs to a plugin or theme.
		$component = null;
		if ( preg_match( '/\bsrc\s*=\s*["\']([^"\']+)/i', $snippet, $m ) && BLT_Optimized_Components::is_local_host( BLT_Optimized_Components::url_host( $m[1] ) ) ) {
			$guess = BLT_Optimized_Components::from_url( $m[1] );
			if ( in_array( $guess['type'], array( 'plugin', 'mu-plugin', 'theme' ), true ) ) {
				$component = $guess;
			}
		}

		$steps = array();
		if ( $is_img ) {
			if ( $lazy ) {
				$steps[] = __( '1. The image\'s markup contains loading="lazy". Remove it: lazy-loading the main image delays it. (Check your theme, page builder, slider and any lazy-load or optimization plugin settings.)', 'blt-optimized' );
			} else {
				$steps[] = __( '1. Make sure it is not lazy-loaded (no loading="lazy", no lazy-load plugin or page-builder setting applied to it).', 'blt-optimized' );
			}
			$steps[] = __( '2. Tell the browser to fetch it first: add fetchpriority="high" to the <img> (WordPress 6.3+ does this for the first content image automatically; themes and sliders often bypass it).', 'blt-optimized' );
			$steps[] = __( '3. Serve it at the size it is displayed, as WebP or AVIF, and compress it.', 'blt-optimized' );
			$steps[] = __( '4. If it is the first slide of a slider or carousel, show a plain static image instead.', 'blt-optimized' );
		} else {
			$steps[] = __( '1. This element is text or a CSS background, so it waits on what blocks rendering: remove or defer render-blocking CSS and JavaScript (see the findings for this page) and make sure the server responds quickly.', 'blt-optimized' );
			$steps[] = __( '2. If it is text in a web font, preload that font and use font-display: swap.', 'blt-optimized' );
			$steps[] = __( '3. If it is a CSS background image, switch to an <img> tag or preload the image with <link rel="preload" as="image">.', 'blt-optimized' );
		}

		return self::build(
			'psi.lcp_element',
			$ctx,
			array(
				'category'  => 'content',
				'severity'  => 'poor' === $rating ? 'warning' : 'notice',
				/* translators: 1: page, 2: LCP time, 3: element description. */
				'title'     => sprintf( __( 'The slowest element to appear on %1$s paints after %2$s: %3$s', 'blt-optimized' ), $ctx['on'], $lcp, self::excerpt( $name, 90 ) ),
				'summary'   => sprintf(
					/* translators: 1: LCP time, 2: element snippet. */
					__( 'Largest Contentful Paint is %1$s, and Lighthouse identified the element that finishes the paint: %2$s. Visitors judge load speed by when this element appears, so making it load first is the single most effective fix for LCP.', 'blt-optimized' ),
					$lcp,
					self::excerpt( '' !== $snippet ? $snippet : $element['selector'], 160 )
				),
				'impact'    => array(
					'type'  => 'ms',
					'value' => (float) $metric['value'],
				),
				'component' => $component,
				'where'     => array_values( array_filter( array( $ctx['url'], $element['selector'] ) ) ),
				'details'   => array(
					'columns' => array( __( 'Element', 'blt-optimized' ), __( 'CSS selector', 'blt-optimized' ), __( 'HTML', 'blt-optimized' ) ),
					'rows'    => array( array( '' !== $element['label'] ? $element['label'] : '—', '' !== $element['selector'] ? $element['selector'] : '—', '' !== $snippet ? $snippet : '—' ) ),
				),
				'fix'       => array(
					'type'  => 'manual',
					'label' => __( 'How to speed up the LCP element', 'blt-optimized' ),
					'steps' => implode( "\n", $steps ),
				),
			)
		);
	}

	/* ---- Audit dispatch ---------------------------------------------- */

	/**
	 * Findings for one audit.
	 *
	 * @param string $audit_id Audit id.
	 * @param array  $audit    Parsed audit.
	 * @param array  $result   parse() result.
	 * @param array  $ctx      Context.
	 * @return array[]
	 */
	private static function audit_findings( $audit_id, array $audit, array $result, array $ctx ) {
		switch ( $audit_id ) {
			case 'unused-javascript':
			case 'unused-css-rules':
			case 'unminified-javascript':
			case 'unminified-css':
			case 'legacy-javascript':
			case 'duplicated-javascript':
			case 'render-blocking-resources':
			case 'bootup-time':
			case 'third-party-summary':
				return self::component_findings( $audit_id, $audit, $ctx, 'bootup-time' === $audit_id && isset( $result['audits']['third-party-summary'] ) );

			case 'uses-optimized-images':
			case 'modern-image-formats':
			case 'uses-responsive-images':
			case 'offscreen-images':
				return self::image_findings( $audit_id, $audit, $ctx );

			case 'efficient-animated-content':
				return self::animated_finding( $audit, $ctx );
			case 'lcp-lazy-loaded':
				return self::lazy_lcp_finding( $audit, $ctx );
			case 'unsized-images':
				return self::unsized_finding( $audit, $result, $ctx );
			case 'layout-shifts':
			case 'layout-shift-elements':
				return self::layout_shift_finding( $audit_id, $audit, $result, $ctx );
			case 'dom-size':
				return self::dom_finding( $audit, $ctx );
			case 'font-display':
				return self::font_display_finding( $audit, $ctx );
			case 'mainthread-work-breakdown':
				return self::main_thread_finding( $audit, $ctx );
			case 'total-byte-weight':
				return self::page_weight_finding( $audit, $ctx );

			case 'server-response-time':
				return self::server_response_finding( $audit, $result, $ctx );
			case 'uses-text-compression':
				return self::compression_finding( $audit, $ctx );
			case 'uses-long-cache-ttl':
				return self::cache_ttl_finding( $audit, $ctx );
		}
		return array();
	}

	/* ---- Per-component asset findings -------------------------------- */

	/**
	 * Per-audit configuration for component-grouped asset findings. Format
	 * arguments: 1 component name, 2 amount, 3 page, 4 file count, 5 total size.
	 *
	 * @param string $audit_id Audit id.
	 * @return array
	 */
	private static function component_config( $audit_id ) {
		switch ( $audit_id ) {
			case 'unused-javascript':
				return array(
					'id'      => 'psi.unused_js',
					'measure' => 'bytes',
					'rows'    => 'unused',
					'fix'     => 'asset_rule',
					'noun'    => __( 'script', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page. */
					'title'   => __( '%1$s ships %2$s of unused JavaScript on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page, 4: number of files, 5: total size. */
					'summary' => __( 'Lighthouse found %4$s from %1$s where %2$s of the %5$s downloaded is never executed while rendering %3$s. Unused JavaScript is still downloaded, parsed and compiled, which delays interactivity on phones. Loading it only on the pages that need it removes that cost.', 'blt-optimized' ),
				);
			case 'unused-css-rules':
				return array(
					'id'      => 'psi.unused_css',
					'measure' => 'bytes',
					'rows'    => 'unused',
					'fix'     => 'asset_rule',
					'noun'    => __( 'stylesheet', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page. */
					'title'   => __( '%1$s ships %2$s of unused CSS on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page, 4: number of files, 5: total size. */
					'summary' => __( 'Lighthouse found %4$s from %1$s where %2$s of the %5$s downloaded isn\'t used by anything on %3$s. Stylesheets block rendering, so every unused kilobyte delays the first paint. Loading the stylesheet only where it is used removes that cost.', 'blt-optimized' ),
				);
			case 'unminified-javascript':
				return array(
					'id'      => 'psi.unminified_js',
					'measure' => 'bytes',
					'rows'    => 'savings',
					'fix'     => 'minify',
					'noun'    => __( 'script', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page. */
					'title'   => __( '%1$s serves %2$s of JavaScript that isn\'t minified on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page, 4: number of files, 5: total size. */
					'summary' => __( '%4$s from %1$s on %3$s could be %2$s smaller if minified (whitespace and comments stripped). Smaller scripts download and parse faster.', 'blt-optimized' ),
				);
			case 'unminified-css':
				return array(
					'id'      => 'psi.unminified_css',
					'measure' => 'bytes',
					'rows'    => 'savings',
					'fix'     => 'minify',
					'noun'    => __( 'stylesheet', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page. */
					'title'   => __( '%1$s serves %2$s of CSS that isn\'t minified on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page, 4: number of files, 5: total size. */
					'summary' => __( '%4$s from %1$s on %3$s could be %2$s smaller if minified. Smaller stylesheets unblock rendering sooner.', 'blt-optimized' ),
				);
			case 'legacy-javascript':
				return array(
					'id'      => 'psi.legacy_js',
					'measure' => 'bytes',
					'rows'    => 'detail',
					'fix'     => 'update',
					'noun'    => __( 'script', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page. */
					'title'   => __( '%1$s ships %2$s of legacy JavaScript (polyfills for old browsers) on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page, 4: number of files, 5: total size. */
					'summary' => __( '%4$s from %1$s on %3$s include code that translates modern JavaScript for browsers almost nobody uses any more, adding about %2$s every visitor downloads and runs.', 'blt-optimized' ),
				);
			case 'duplicated-javascript':
				return array(
					'id'      => 'psi.duplicated_js',
					'measure' => 'bytes',
					'rows'    => 'detail',
					'fix'     => 'duplicate',
					'noun'    => __( 'script', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page. */
					'title'   => __( '%1$s bundles %2$s of JavaScript that another file on %3$s already loads', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: size, 3: page, 4: number of files, 5: total size. */
					'summary' => __( '%4$s from %1$s on %3$s contain JavaScript modules that other scripts on the page also include, wasting about %2$s. Usually two plugins (or a plugin and the theme) each bundle their own copy of the same library.', 'blt-optimized' ),
				);
			case 'render-blocking-resources':
				return array(
					'id'      => 'psi.render_blocking',
					'measure' => 'ms',
					'rows'    => 'blocking',
					'fix'     => 'render_blocking',
					'noun'    => __( 'file', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: time, 3: page. */
					'title'   => __( '%1$s delays the first paint by up to %2$s on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: time, 3: page, 4: number of files, 5: total size. */
					'summary' => __( '%4$s from %1$s on %3$s must be downloaded and processed before the browser can show anything. Visitors can stare at a blank page for up to %2$s longer than necessary. Deferring scripts and loading non-critical styles later fixes this.', 'blt-optimized' ),
				);
			case 'bootup-time':
				return array(
					'id'      => 'psi.bootup',
					'measure' => 'cpu',
					'rows'    => 'cpu',
					'fix'     => 'cpu',
					'noun'    => __( 'script', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: time, 3: page. */
					'title'   => __( '%1$s keeps the main thread busy for %2$s on %3$s', 'blt-optimized' ),
					/* translators: 1: plugin/theme/host, 2: time, 3: page, 4: number of files, 5: total size. */
					'summary' => __( 'Running and parsing %4$s from %1$s took %2$s of CPU time on Lighthouse\'s test phone. While the main thread is busy the page ignores taps and scrolls, which is what Total Blocking Time and Interaction to Next Paint measure.', 'blt-optimized' ),
				);
			case 'third-party-summary':
				return array(
					'id'      => 'psi.third_party',
					'measure' => 'cpu',
					'rows'    => 'third_party',
					'fix'     => 'third_party',
					'noun'    => __( 'request', 'blt-optimized' ),
					/* translators: 1: third party name, 2: blocking time, 3: page. */
					'title'   => __( '%1$s blocks the main thread for %2$s on %3$s', 'blt-optimized' ),
					/* translators: 1: third party name, 2: blocking time, 3: page, 4: number of files, 5: total size. */
					'summary' => __( '%1$s runs code on %3$s that blocks the main thread for %2$s and downloads %5$s. Third-party tags (analytics, chat, ads, embeds) are outside your control, so the fix is to remove what you don\'t use or load the rest after the page is interactive.', 'blt-optimized' ),
				);
		}
		return array();
	}

	/**
	 * Group an audit's items per component and emit one finding each.
	 *
	 * @param string $audit_id      Audit id.
	 * @param array  $audit         Parsed audit.
	 * @param array  $ctx           Context.
	 * @param bool   $skip_external Leave third parties to the third-party-summary findings.
	 * @return array[]
	 */
	private static function component_findings( $audit_id, array $audit, array $ctx, $skip_external = false ) {
		$cfg = self::component_config( $audit_id );
		if ( empty( $cfg ) || empty( $audit['items'] ) ) {
			return array();
		}

		$measure = $cfg['measure'];
		$groups  = self::group_items( $audit['items'], 'bytes' === $measure ? 'wasted_bytes' : 'wasted_ms' );

		$total_bytes_all = 0;
		foreach ( $groups as $group ) {
			$total_bytes_all += $group['wasted_bytes'];
		}

		$findings = array();
		foreach ( array_slice( $groups, 0, self::MAX_COMPONENTS, true ) as $comp_key => $group ) {
			$component = $group['component'];

			if ( $skip_external && 'external' === $component['type'] ) {
				continue;
			}

			// Floors: skip components too small to matter.
			if ( 'bytes' === $measure && $group['wasted_bytes'] < self::MIN_BYTES ) {
				continue;
			}
			if ( 'cpu' === $measure && $group['wasted_ms'] < ( 'psi.third_party' === $cfg['id'] ? 50 : 100 ) && $group['total_bytes'] < 100 * KB_IN_BYTES ) {
				continue;
			}

			// Time attributable to this component: its share of Lighthouse's overall saving.
			if ( 'bytes' === $measure ) {
				$ms = $total_bytes_all > 0 ? (float) $audit['savings_ms'] * ( $group['wasted_bytes'] / $total_bytes_all ) : 0.0;
				if ( 'psi.unminified_js' === $cfg['id'] || 'psi.unminified_css' === $cfg['id'] ) {
					$ms = 0.0;
				}
				$severity = self::severity( $ms, $group['wasted_bytes'], false );
				$impact   = array(
					'type'  => 'bytes',
					'value' => $group['wasted_bytes'],
				);
				$amount   = size_format( $group['wasted_bytes'], $group['wasted_bytes'] >= MB_IN_BYTES ? 1 : 0 );
			} elseif ( 'ms' === $measure ) {
				// Files load in parallel, so the delay is the slowest one, never the sum.
				$longest  = (float) $group['max_ms'];
				$ms       = (float) $audit['savings_ms'] > 0 ? min( $longest, (float) $audit['savings_ms'] ) : $longest;
				$severity = self::severity( $ms, 0, true );
				$impact   = array(
					'type'  => 'ms',
					'value' => $ms,
				);
				$amount   = self::format_ms( $ms );
			} else {
				$ms       = (float) $group['wasted_ms'];
				$severity = self::severity( $ms, 'psi.third_party' === $cfg['id'] ? $group['total_bytes'] : 0, true );
				$impact   = array(
					'type'  => 'ms',
					'value' => $ms,
				);
				$amount   = self::format_ms( $ms );
			}

			$name  = self::component_name( $component );
			$count = count( $group['items'] );
			/* translators: %d: number of files. */
			$files = sprintf( _n( '%d file', '%d files', $count, 'blt-optimized' ), $count );
			if ( 'psi.third_party' === $cfg['id'] ) {
				$name = isset( $component['name'] ) && '' !== $component['name'] ? $component['name'] : $name;
			}

			$findings[] = self::build(
				$cfg['id'],
				$ctx,
				array(
					'category'  => 'assets',
					'severity'  => $severity,
					'title'     => sprintf( $cfg['title'], $name, $amount, $ctx['on'] ),
					'summary'   => sprintf( $cfg['summary'], $name, $amount, $ctx['on'], $files, size_format( max( 1, $group['total_bytes'] ), 0 ) ),
					'impact'    => $impact,
					'component' => $component,
					'where'     => self::where( $ctx, $group['items'] ),
					'details'   => self::file_table( $cfg['rows'], $group['items'], $ctx ),
					'fix'       => self::component_fix( $cfg['fix'], $component, $group, $cfg, $ctx ),
				),
				$comp_key
			);
		}
		return $findings;
	}

	/**
	 * Items grouped per component, biggest first.
	 *
	 * @param array[] $items   Parsed items.
	 * @param string  $sort_by wasted_bytes|wasted_ms.
	 * @return array[] comp key => { component, items, wasted_bytes, total_bytes, wasted_ms }
	 */
	private static function group_items( array $items, $sort_by ) {
		$groups = array();
		foreach ( $items as $item ) {
			$component = isset( $item['component'] ) && is_array( $item['component'] ) ? $item['component'] : null;
			if ( null === $component ) {
				continue; // Nothing to point at (Lighthouse's "Unattributable" rows).
			}
			$key = BLT_Optimized_Components::key( $component );
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'component'    => $component,
					'items'        => array(),
					'wasted_bytes' => 0,
					'total_bytes'  => 0,
					'wasted_ms'    => 0.0,
					'max_ms'       => 0.0,
				);
			}
			$groups[ $key ]['items'][]       = $item;
			$groups[ $key ]['wasted_bytes'] += (int) $item['wasted_bytes'];
			$groups[ $key ]['total_bytes']  += (int) $item['total_bytes'];
			$groups[ $key ]['wasted_ms']    += (float) $item['wasted_ms'];
			$groups[ $key ]['max_ms']        = max( $groups[ $key ]['max_ms'], (float) $item['wasted_ms'] );
		}
		uasort(
			$groups,
			static function ( $a, $b ) use ( $sort_by ) {
				return $b[ $sort_by ] <=> $a[ $sort_by ];
			}
		);
		return $groups;
	}

	/**
	 * How to fix a component-grouped finding.
	 *
	 * @param string $kind      Fix kind from component_config().
	 * @param array  $component Component.
	 * @param array  $group     Group (items…).
	 * @param array  $cfg       Audit config.
	 * @param array  $ctx       Context.
	 * @return array|null
	 */
	private static function component_fix( $kind, array $component, array $group, array $cfg, array $ctx ) {
		$name  = self::component_name( $component );
		$first = isset( $group['items'][0]['url'] ) ? self::short_url( $group['items'][0]['url'], $ctx, false ) : '';
		$type  = isset( $component['type'] ) ? $component['type'] : 'other';

		switch ( $kind ) {
			case 'asset_rule':
				if ( 'external' === $type ) {
					return self::third_party_fix( $name, $first );
				}
				$steps = array();
				if ( in_array( $type, array( 'plugin', 'mu-plugin' ), true ) ) {
					/* translators: 1: file, 2: plugin name. */
					$steps[] = sprintf( __( '1. Open BLT Optimized → Speed Tweaks → Asset rules and add a rule for the file %1$s, set to load only on the pages that use %2$s. (To find its handle: search the page source for the file name; the tag\'s id is the handle plus "-js" for scripts or "-css" for stylesheets.)', 'blt-optimized' ), $first, $name );
					/* translators: %s: plugin name. */
					$steps[] = sprintf( __( '2. Or look in %s\'s own settings for an option to load its assets only where needed.', 'blt-optimized' ), $name );
					/* translators: %s: plugin name. */
					$steps[] = sprintf( __( '3. If you no longer use %s on the front end, deactivate it.', 'blt-optimized' ), $name );
				} elseif ( 'theme' === $type ) {
					/* translators: 1: file, 2: theme name. */
					$steps[] = sprintf( __( '1. Open BLT Optimized → Speed Tweaks → Asset rules and add a rule for the file %1$s (the theme %2$s loads it everywhere), set to load only where it is used. (To find its handle: search the page source for the file name; the tag\'s id is the handle plus "-js" for scripts or "-css" for stylesheets.)', 'blt-optimized' ), $first, $name );
					$steps[] = __( '2. Or ask the theme developer, or use a child theme, to enqueue it only on the templates that need it.', 'blt-optimized' );
				} else {
					/* translators: %s: file. */
					$steps[] = sprintf( __( '1. Open BLT Optimized → Speed Tweaks → Asset rules and add a rule for the file %s, set to load only on the pages that use it. (To find its handle: search the page source for the file name; the tag\'s id is the handle plus "-js" for scripts or "-css" for stylesheets.)', 'blt-optimized' ), $first );
					$steps[] = __( '2. WordPress core files are only worth restricting when you know a feature is unused (for example, the block library stylesheet on a site built entirely with a page builder).', 'blt-optimized' );
				}
				return array(
					'type'  => 'manual',
					'label' => __( 'Load it only where needed', 'blt-optimized' ),
					'steps' => implode( "\n", $steps ),
				);

			case 'render_blocking':
				return self::render_blocking_fix( $name, $group, $first, $type );

			case 'minify':
				if ( 'external' === $type ) {
					return self::third_party_fix( $name, $first );
				}
				/* translators: 1: file, 2: component name, 3: "script" or "stylesheet". */
				$text = sprintf( __( '%1$s (from %2$s) is served with its original whitespace and comments. Minify it with an optimization/caching plugin (WP Rocket, LiteSpeed Cache, Autoptimize…) or your CDN\'s auto-minify, or ask the author of %2$s to ship a .min version.', 'blt-optimized' ), $first, $name );
				return array(
					'type'  => 'manual',
					'label' => __( 'How to minify', 'blt-optimized' ),
					'steps' => $text,
				);

			case 'update':
				return array(
					'type'  => 'manual',
					'label' => __( 'How to fix', 'blt-optimized' ),
					'steps' => 'external' === $type
						? self::third_party_fix( $name, $first )['steps']
						: implode(
							"\n",
							array(
								/* translators: %s: component name. */
								sprintf( __( '1. Update %s to its latest version: current releases usually stop shipping browser polyfills.', 'blt-optimized' ), $name ),
								__( '2. If it is already current, ask the developer to raise their JavaScript build target (modern browsers only).', 'blt-optimized' ),
							)
						),
				);

			case 'duplicate':
				return array(
					'type'  => 'manual',
					'label' => __( 'How to fix', 'blt-optimized' ),
					'steps' => implode(
						"\n",
						array(
							/* translators: 1: file, 2: component name. */
							sprintf( __( '1. %1$s (from %2$s) contains modules that another script on the page also bundles. Compare the "Duplicated JavaScript" list in the PageSpeed report to see which files overlap.', 'blt-optimized' ), $first, $name ),
							__( '2. Disable the bundled copy in one of the plugins (many have a "load jQuery UI / Font Awesome / Select2 from WordPress" or "don\'t load library" option), or deactivate the plugin that duplicates the other.', 'blt-optimized' ),
						)
					),
				);

			case 'cpu':
				if ( 'external' === $type ) {
					return self::third_party_fix( $name, $first );
				}
				$steps = array(
					/* translators: 1: file, 2: component name. */
					sprintf( __( '1. %1$s (from %2$s) runs a lot of JavaScript on page load. Check whether %2$s has a setting to run it only on the pages that need it.', 'blt-optimized' ), $first, $name ),
					__( '2. Defer it: turn on "Defer JavaScript" in BLT Optimized → Speed Tweaks (WordPress 6.3+), and test the page.', 'blt-optimized' ),
					/* translators: %s: component name. */
					sprintf( __( '3. If you can live without %s on this page, add an asset rule to stop it loading there.', 'blt-optimized' ), $name ),
				);
				return array(
					'type'  => 'manual',
					'label' => __( 'How to reduce it', 'blt-optimized' ),
					'steps' => implode( "\n", $steps ),
				);

			case 'third_party':
				return self::third_party_fix( $name, $first );
		}
		return null;
	}

	/**
	 * Fix for a file or tag served by someone else.
	 *
	 * @param string $name  Third party name.
	 * @param string $first Example file.
	 * @return array
	 */
	private static function third_party_fix( $name, $first ) {
		return array(
			'type'  => 'manual',
			'label' => __( 'How to fix', 'blt-optimized' ),
			'steps' => implode(
				"\n",
				array(
					/* translators: 1: third party name, 2: example file. */
					sprintf( __( '1. Find where %1$s is added to the site (a plugin setting, a theme option, a header/footer script box or Google Tag Manager). Example file: %2$s.', 'blt-optimized' ), $name, $first ),
					/* translators: %s: third party name. */
					sprintf( __( '2. Remove %s if nobody uses what it provides.', 'blt-optimized' ), $name ),
					__( '3. Otherwise load it after the page is interactive (a delay-until-interaction option in your optimization plugin, or a consent/interaction trigger in Tag Manager), and keep it off pages that don\'t need it.', 'blt-optimized' ),
				)
			),
		);
	}

	/**
	 * Fix for render-blocking files: defer scripts where WordPress can.
	 *
	 * @param string $name  Component name.
	 * @param array  $group Group.
	 * @param string $first Example file.
	 * @param string $type  Component type.
	 * @return array
	 */
	private static function render_blocking_fix( $name, array $group, $first, $type ) {
		if ( 'core' === $type ) {
			return array(
				'type'  => 'manual',
				'label' => __( 'How to fix', 'blt-optimized' ),
				'steps' => implode(
					"\n",
					array(
						/* translators: %s: example file. */
						sprintf( __( '1. %s is part of WordPress core (jQuery, block styles…). Core scripts such as jQuery are never deferred because plugins and themes depend on them being ready early.', 'blt-optimized' ), $first ),
						__( '2. Reduce the cost instead: remove plugins and theme features that need jQuery on every page, switch off unused core extras in Speed Tweaks (jQuery Migrate, emojis, embeds), and load the block-library stylesheet only where blocks are used (Speed Tweaks → Asset rules).', 'blt-optimized' ),
					)
				),
			);
		}

		$has_js  = false;
		$has_css = false;
		foreach ( $group['items'] as $item ) {
			$path = (string) wp_parse_url( $item['url'], PHP_URL_PATH );
			if ( preg_match( '/\.js$/i', $path ) ) {
				$has_js = true;
			} else {
				$has_css = true;
			}
		}

		$defer_on = false;
		if ( class_exists( 'BLT_Optimized_Tweaks' ) && method_exists( 'BLT_Optimized_Tweaks', 'get' ) ) {
			$tweaks   = BLT_Optimized_Tweaks::get();
			$defer_on = is_array( $tweaks ) && ! empty( $tweaks['defer_scripts'] );
		}

		if ( $has_js && ! $defer_on && 'external' !== $type && version_compare( get_bloginfo( 'version' ), '6.3', '>=' ) ) {
			return array(
				'type'    => 'action',
				'label'   => __( 'Defer JavaScript', 'blt-optimized' ),
				'action'  => 'tweak',
				'args'    => array(
					'key'   => 'defer_scripts',
					'value' => 1,
				),
				'confirm' => __( 'Defer all front-end scripts that WordPress deems safe to defer? Test your pages afterwards; you can turn this off again in Speed Tweaks.', 'blt-optimized' ),
			);
		}

		if ( 'external' === $type ) {
			return self::third_party_fix( $name, $first );
		}

		$steps = array();
		if ( $has_js ) {
			$steps[] = $defer_on
				? __( '1. "Defer JavaScript" is already on in Speed Tweaks, so WordPress considered these scripts unsafe to defer (inline code depends on them) or they are excluded. Check the exclusion list, or load the dependent inline code after DOMContentLoaded.', 'blt-optimized' )
				: __( '1. Defer these scripts: update to WordPress 6.3+ (then use Speed Tweaks → Defer JavaScript) or use an optimization plugin\'s "defer JavaScript" option.', 'blt-optimized' );
		}
		if ( $has_css ) {
			/* translators: 1: file, 2: component name. */
			$steps[] = sprintf( __( '2. %1$s (from %2$s) is a stylesheet that blocks rendering. Inline the CSS needed for the top of the page and load the rest asynchronously (an optimization plugin\'s "critical CSS" feature does this), or load it only on the pages that use it.', 'blt-optimized' ), $first, $name );
		}
		return array(
			'type'  => 'manual',
			'label' => __( 'How to unblock rendering', 'blt-optimized' ),
			'steps' => implode( "\n", $steps ),
		);
	}

	/* ---- Images ------------------------------------------------------- */

	/**
	 * Image audits (content): one finding for the media library, one per
	 * plugin/theme/third party that serves images.
	 *
	 * @param string $audit_id Audit id.
	 * @param array  $audit    Parsed audit.
	 * @param array  $ctx      Context.
	 * @return array[]
	 */
	private static function image_findings( $audit_id, array $audit, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}

		$cfg = array(
			'uses-optimized-images'  => array(
				'id'      => 'psi.optimized_images',
				/* translators: %1$s: size, %2$s: page. */
				'media'   => __( 'Images on %2$s could be %1$s smaller with better compression', 'blt-optimized' ),
				/* translators: 1: size, 2: page, 3: plugin/theme/host. */
				'named'   => __( '%3$s serves images that could be %1$s smaller with better compression on %2$s', 'blt-optimized' ),
				/* translators: 1: number of images, 2: size, 3: page. */
				'summary' => __( '%1$s on %3$s are saved at a higher quality than needed; recompressing them would save about %2$s without a visible difference.', 'blt-optimized' ),
			),
			'modern-image-formats'   => array(
				'id'      => 'psi.modern_formats',
				/* translators: %1$s: size, %2$s: page. */
				'media'   => __( 'Images on %2$s could be %1$s smaller as WebP or AVIF', 'blt-optimized' ),
				/* translators: 1: size, 2: page, 3: plugin/theme/host. */
				'named'   => __( '%3$s serves JPEG/PNG images that could be %1$s smaller as WebP or AVIF on %2$s', 'blt-optimized' ),
				/* translators: 1: number of images, 2: size, 3: page. */
				'summary' => __( '%1$s on %3$s use older formats (JPEG, PNG or GIF). WebP and AVIF look the same at a fraction of the size, saving about %2$s per visit.', 'blt-optimized' ),
			),
			'uses-responsive-images' => array(
				'id'      => 'psi.responsive_images',
				/* translators: %1$s: size, %2$s: page. */
				'media'   => __( 'Images on %2$s are larger than displayed, wasting %1$s', 'blt-optimized' ),
				/* translators: 1: size, 2: page, 3: plugin/theme/host. */
				'named'   => __( '%3$s serves images larger than they are displayed on %2$s, wasting %1$s', 'blt-optimized' ),
				/* translators: 1: number of images, 2: size, 3: page. */
				'summary' => __( '%1$s on %3$s are downloaded at a much higher resolution than the space they fill, wasting about %2$s. Serving the right size (responsive srcset) lets phones download small versions.', 'blt-optimized' ),
			),
			'offscreen-images'       => array(
				'id'      => 'psi.offscreen_images',
				/* translators: %1$s: size, %2$s: page. */
				'media'   => __( 'Images below the fold on %2$s are loaded up front (%1$s)', 'blt-optimized' ),
				/* translators: 1: size, 2: page, 3: plugin/theme/host. */
				'named'   => __( '%3$s loads images below the fold up front on %2$s (%1$s)', 'blt-optimized' ),
				/* translators: 1: number of images, 2: size, 3: page. */
				'summary' => __( '%1$s on %3$s are far down the page but downloaded immediately, competing with what the visitor sees first (about %2$s). Lazy-loading them speeds up the first view.', 'blt-optimized' ),
			),
		);
		if ( ! isset( $cfg[ $audit_id ] ) ) {
			return array();
		}
		$cfg = $cfg[ $audit_id ];

		// Group: plugin/theme/third party each get a group; uploads and the rest are "media".
		$groups = array();
		foreach ( $audit['items'] as $item ) {
			$component = isset( $item['component'] ) && is_array( $item['component'] ) ? $item['component'] : null;
			$type      = null === $component ? 'other' : $component['type'];
			if ( in_array( $type, array( 'plugin', 'mu-plugin', 'theme', 'external' ), true ) ) {
				$key = BLT_Optimized_Components::key( $component );
			} else {
				$key       = 'media';
				$component = null;
			}
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'component'    => $component,
					'items'        => array(),
					'wasted_bytes' => 0,
					'total_bytes'  => 0,
					'wasted_ms'    => 0.0,
				);
			}
			$groups[ $key ]['items'][]       = $item;
			$groups[ $key ]['wasted_bytes'] += (int) $item['wasted_bytes'];
			$groups[ $key ]['total_bytes']  += (int) $item['total_bytes'];
		}
		uasort(
			$groups,
			static function ( $a, $b ) {
				return $b['wasted_bytes'] <=> $a['wasted_bytes'];
			}
		);

		$all_wasted = max( 1, array_sum( wp_list_pluck( $groups, 'wasted_bytes' ) ) );
		$findings   = array();
		foreach ( array_slice( $groups, 0, self::MAX_COMPONENTS, true ) as $key => $group ) {
			if ( $group['wasted_bytes'] < self::MIN_BYTES ) {
				continue;
			}
			$component = $group['component'];
			$count     = count( $group['items'] );
			/* translators: %d: number of images. */
			$images = sprintf( _n( '%d image', '%d images', $count, 'blt-optimized' ), $count );
			$size   = size_format( $group['wasted_bytes'], $group['wasted_bytes'] >= MB_IN_BYTES ? 1 : 0 );
			$ms     = (float) $audit['savings_ms'] * ( $group['wasted_bytes'] / $all_wasted );
			$name   = null === $component ? '' : self::component_name( $component );

			$findings[] = self::build(
				$cfg['id'],
				$ctx,
				array(
					'category'  => 'content',
					'severity'  => self::severity( $ms, $group['wasted_bytes'], false ),
					'title'     => null === $component ? sprintf( $cfg['media'], $size, $ctx['on'] ) : sprintf( $cfg['named'], $size, $ctx['on'], $name ),
					'summary'   => sprintf( $cfg['summary'], $images, $size, $ctx['on'] ),
					'impact'    => array(
						'type'  => 'bytes',
						'value' => $group['wasted_bytes'],
					),
					'component' => $component,
					'where'     => self::where( $ctx, $group['items'] ),
					'details'   => self::file_table( 'image', $group['items'], $ctx ),
					'fix'       => self::image_fix( $audit_id, $component, $group, $ctx ),
				),
				$key
			);
		}
		return $findings;
	}

	/**
	 * Fix for image findings.
	 *
	 * @param string     $audit_id  Audit id.
	 * @param array|null $component Component (null = media library).
	 * @param array      $group     Group.
	 * @param array      $ctx       Context.
	 * @return array
	 */
	private static function image_fix( $audit_id, $component, array $group, array $ctx ) {
		$first = isset( $group['items'][0]['url'] ) ? self::short_url( $group['items'][0]['url'], $ctx, false ) : '';

		if ( null !== $component && 'external' === $component['type'] ) {
			return array(
				'type'  => 'manual',
				'label' => __( 'How to fix', 'blt-optimized' ),
				/* translators: 1: host name, 2: example image. */
				'steps' => sprintf( __( 'These images are served by %1$s (for example %2$s), so you can\'t recompress them. Request a smaller size from that service (many accept width/quality parameters in the URL) or host the images yourself.', 'blt-optimized' ), self::component_name( $component ), $first ),
			);
		}
		if ( null !== $component ) {
			return array(
				'type'  => 'manual',
				'label' => __( 'How to fix', 'blt-optimized' ),
				/* translators: 1: component name, 2: example image. */
				'steps' => sprintf( __( 'These images ship inside %1$s (for example %2$s). Replace them with optimized files in a child theme or the plugin\'s own image setting, or ask the developer to ship smaller images.', 'blt-optimized' ), self::component_name( $component ), $first ),
			);
		}

		switch ( $audit_id ) {
			case 'modern-image-formats':
			case 'uses-optimized-images':
				$enabled = false;
				if ( class_exists( 'BLT_Optimized' ) && method_exists( 'BLT_Optimized', 'get_settings' ) ) {
					$settings = BLT_Optimized::get_settings();
					$enabled  = ! empty( $settings['enable_images'] );
				}
				return array(
					'type'  => 'link',
					'label' => $enabled ? __( 'Open image optimization', 'blt-optimized' ) : __( 'Enable image optimization', 'blt-optimized' ),
					'url'   => admin_url( $enabled ? 'admin.php?page=blt-optimizer' : 'admin.php?page=blt-optimized-settings' ),
				);

			case 'uses-responsive-images':
				return array(
					'type'  => 'manual',
					'label' => __( 'How to fix', 'blt-optimized' ),
					'steps' => implode(
						"\n",
						array(
							__( '1. Insert images at the size they are displayed (choose "Medium" or "Large" instead of "Full size" in the editor) and avoid hard-coding huge originals in page-builder widgets.', 'blt-optimized' ),
							__( '2. If the theme registers new image sizes, regenerate thumbnails so existing uploads get them.', 'blt-optimized' ),
							__( '3. Make sure the theme outputs images with wp_get_attachment_image() so WordPress adds srcset and sizes.', 'blt-optimized' ),
						)
					),
				);

			case 'offscreen-images':
				return array(
					'type'  => 'manual',
					'label' => __( 'How to fix', 'blt-optimized' ),
					'steps' => implode(
						"\n",
						array(
							__( '1. WordPress lazy-loads images in content automatically. These images are likely added by a theme, slider, gallery or page builder that bypasses it: turn on its own "lazy load images" option.', 'blt-optimized' ),
							/* translators: %s: example image. */
							sprintf( __( '2. Example image: %s. Add loading="lazy" to its <img> tag (but never to the image at the very top of the page).', 'blt-optimized' ), $first ),
						)
					),
				);
		}
		return array(
			'type'  => 'manual',
			'label' => __( 'How to fix', 'blt-optimized' ),
			'steps' => __( 'Recompress and resize these images.', 'blt-optimized' ),
		);
	}

	/**
	 * Animated GIFs that should be video.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function animated_finding( array $audit, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$bytes = max( (int) $audit['savings_bytes'], 0 );
		return array(
			self::build(
				'psi.animated_content',
				$ctx,
				array(
					'category' => 'content',
					'severity' => self::severity( (float) $audit['savings_ms'], $bytes, false ),
					/* translators: 1: size, 2: page. */
					'title'    => sprintf( __( 'Animated GIFs on %2$s could be %1$s smaller as video', 'blt-optimized' ), size_format( max( 1, $bytes ), 0 ), $ctx['on'] ),
					'summary'  => __( 'Animated GIFs are many times larger than the same clip as an MP4 or WebM video. Converting them (and playing them muted, looping, with autoplay) keeps the look and removes most of the download.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => self::where( $ctx, $audit['items'] ),
					'details'  => self::file_table( 'savings', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Convert each GIF to MP4/WebM (for example with ffmpeg or an online converter).', 'blt-optimized' ),
								__( '2. Replace the GIF in the page with a <video autoplay loop muted playsinline> element (the WordPress video block can be set to autoplay, loop and muted).', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * The LCP image is lazy-loaded.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function lazy_lcp_finding( array $audit, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$label = '' !== $audit['items'][0]['label'] ? $audit['items'][0]['label'] : '';
		return array(
			self::build(
				'psi.lcp_lazy_loaded',
				$ctx,
				array(
					'category' => 'content',
					'severity' => 'warning',
					/* translators: %s: page. */
					'title'    => sprintf( __( 'The main image on %s is lazy-loaded, which delays Largest Contentful Paint', 'blt-optimized' ), $ctx['on'] ),
					'summary'  => __( 'The image that fills the most of the first screen has loading="lazy", so the browser waits until layout is done before even starting to download it. Visitors see the page without its main image for longer than necessary.', 'blt-optimized' ),
					'impact'   => (float) $audit['savings_ms'] > 0 ? array(
						'type'  => 'ms',
						'value' => (float) $audit['savings_ms'],
					) : array(),
					'where'    => array( $ctx['url'] ),
					'details'  => self::file_table( 'node', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								/* translators: %s: element description. */
								sprintf( __( '1. Remove loading="lazy" from this image: %s.', 'blt-optimized' ), '' !== $label ? $label : __( 'the first large image on the page', 'blt-optimized' ) ),
								__( '2. Check your theme, page builder, slider and any lazy-load plugin: most have an option to skip lazy-loading for the first image or for images above the fold.', 'blt-optimized' ),
								__( '3. Add fetchpriority="high" to that <img> so the browser downloads it first.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * Images without width/height (layout shift).
	 *
	 * @param array $audit  Parsed audit.
	 * @param array $result parse() result.
	 * @param array $ctx    Context.
	 * @return array[]
	 */
	private static function unsized_finding( array $audit, array $result, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$count = count( $audit['items'] );
		$cls   = isset( $result['metrics']['cls'] ) ? $result['metrics']['cls'] : null;
		$bad   = null !== $cls && 'good' !== self::rate( 'cls', $cls['value'], $cls['score'] );
		return array(
			self::build(
				'psi.unsized_images',
				$ctx,
				array(
					'category' => 'content',
					'severity' => $bad ? 'warning' : 'notice',
					/* translators: 1: number of images, 2: page. */
					'title'    => sprintf( _n( '%1$d image on %2$s has no width and height, so the page jumps as it loads', '%1$d images on %2$s have no width and height, so the page jumps as it loads', $count, 'blt-optimized' ), $count, $ctx['on'] ),
					'summary'  => __( 'Without width and height attributes the browser can\'t reserve space for an image, so text and buttons shift down when it arrives. That is what Cumulative Layout Shift measures, and what visitors experience as the page "jumping".', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $count,
					),
					'where'    => self::where( $ctx, $audit['items'] ),
					'details'  => self::file_table( 'node', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Images inserted through the WordPress editor get width and height automatically. Re-insert (or "Replace") the ones listed in the evidence table so the attributes are added.', 'blt-optimized' ),
								__( '2. For images that come from the theme, a slider or a page builder, add width= and height= attributes to the <img> (or set an aspect-ratio in CSS).', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * Layout shifts: the elements that moved.
	 *
	 * @param string $audit_id Audit id.
	 * @param array  $audit    Parsed audit.
	 * @param array  $result   parse() result.
	 * @param array  $ctx      Context.
	 * @return array[]
	 */
	private static function layout_shift_finding( $audit_id, array $audit, array $result, array $ctx ) {
		// Newer Lighthouse reports both audits; one finding is enough.
		if ( 'layout-shift-elements' === $audit_id && isset( $result['audits']['layout-shifts'] ) ) {
			return array();
		}
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$cls     = isset( $result['metrics']['cls']['value'] ) ? (float) $result['metrics']['cls']['value'] : ( null !== $audit['value'] ? (float) $audit['value'] : 0.0 );
		$display = number_format_i18n( $cls, 3 );
		$top     = '' !== $audit['items'][0]['label'] ? $audit['items'][0]['label'] : '';

		return array(
			self::build(
				'psi.layout_shifts',
				$ctx,
				array(
					'category' => 'content',
					'severity' => $cls > 0.25 ? 'warning' : 'notice',
					/* translators: 1: page, 2: CLS value. */
					'title'    => sprintf( __( 'Content moves around while %1$s loads (layout shift %2$s)', 'blt-optimized' ), $ctx['on'], $display ),
					'summary'  => sprintf(
						/* translators: 1: CLS value, 2: biggest mover. */
						__( 'Cumulative Layout Shift is %1$s (good is under 0.1). The elements below moved after they first appeared, so visitors lose their place or tap the wrong thing. The worst offender: %2$s.', 'blt-optimized' ),
						$display,
						'' !== $top ? $top : __( 'see the table', 'blt-optimized' )
					),
					'impact'   => array(
						'type'  => 'count',
						'value' => count( $audit['items'] ),
						'label' => sprintf(
							/* translators: %s: number of elements. */
							_n( '%s element', '%s elements', count( $audit['items'] ), 'blt-optimized' ),
							number_format_i18n( count( $audit['items'] ) )
						),
					),
					'where'    => self::where( $ctx, array() ),
					'details'  => self::file_table( 'node', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Give images, videos, iframes and ad/embed slots explicit width and height (or an aspect-ratio) so space is reserved before they load.', 'blt-optimized' ),
								__( '2. Avoid inserting banners, cookie notices or sliders above existing content after load; reserve their space in the layout or overlay them instead.', 'blt-optimized' ),
								__( '3. For web fonts, preload the main font and use font-display: swap with a similar fallback font to avoid text reflow.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/* ---- Other content / assets findings ------------------------------ */

	/**
	 * DOM size.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function dom_finding( array $audit, array $ctx ) {
		$elements = null !== $audit['value'] ? (int) round( $audit['value'] ) : 0;
		if ( $elements <= 0 && preg_match( '/([\d.,]+)/', (string) $audit['display'], $m ) ) {
			$elements = (int) preg_replace( '/\D/', '', $m[1] );
		}
		if ( $elements <= 0 ) {
			return array();
		}
		$severity = $elements > 3000 ? 'critical' : ( $elements > 1500 ? 'warning' : 'notice' );

		return array(
			self::build(
				'psi.dom_size',
				$ctx,
				array(
					'category' => 'content',
					'severity' => $severity,
					/* translators: 1: page, 2: number of elements. */
					'title'    => sprintf( __( '%1$s has a very large page structure (%2$s HTML elements)', 'blt-optimized' ), ucfirst( $ctx['on'] ), number_format_i18n( $elements ) ),
					'summary'  => __( 'Every HTML element costs memory and style/layout time, and large pages make every later change (a menu opening, a filter applying) slower on phones. Under about 1,500 elements is comfortable; page builders with deeply nested wrappers are the usual cause.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'count',
						'value' => $elements,
						'label' => sprintf(
							/* translators: %s: number of elements. */
							__( '%s elements', 'blt-optimized' ),
							number_format_i18n( $elements )
						),
					),
					'where'    => array( $ctx['url'] ),
					'details'  => self::file_table( 'dom', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Find the heaviest sections (mega menus, long product or post lists, footers, nested columns) with your browser\'s Elements panel.', 'blt-optimized' ),
								__( '2. Paginate or lazy-load long lists, simplify page-builder layouts (fewer nested sections/columns), and remove widgets or hidden markup you don\'t use.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * Fonts that hide text while loading.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function font_display_finding( array $audit, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$ms = (float) $audit['savings_ms'] > 0 ? (float) $audit['savings_ms'] : (float) array_sum( wp_list_pluck( $audit['items'], 'wasted_ms' ) );
		return array(
			self::build(
				'psi.font_display',
				$ctx,
				array(
					'category' => 'assets',
					'severity' => self::severity( $ms, 0, false ),
					/* translators: %s: page. */
					'title'    => sprintf( __( 'Web fonts hide the text on %s until they download', 'blt-optimized' ), $ctx['on'] ),
					'summary'  => __( 'These font files load without font-display: swap, so the browser shows invisible text until the font arrives. Showing a fallback font immediately makes text readable sooner.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'ms',
						'value' => $ms,
					),
					'where'    => self::where( $ctx, $audit['items'] ),
					'details'  => self::file_table( 'font', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Google Fonts: add &display=swap to the font URL (or turn on the "font-display: swap" option in your theme or font plugin).', 'blt-optimized' ),
								__( '2. Self-hosted fonts: add font-display: swap; to each @font-face rule.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * Main-thread work breakdown.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function main_thread_finding( array $audit, array $ctx ) {
		$ms = null !== $audit['value'] ? (float) $audit['value'] : (float) array_sum( wp_list_pluck( $audit['items'], 'wasted_ms' ) );
		if ( $ms <= 0 ) {
			return array();
		}
		$top  = isset( $audit['items'][0] ) ? $audit['items'][0] : null;
		$busy = null === $top ? '' : sprintf(
			/* translators: 1: kind of work, 2: time. */
			__( 'Most of it is %1$s (%2$s).', 'blt-optimized' ),
			strtolower( $top['label'] ),
			self::format_ms( $top['wasted_ms'] )
		);
		return array(
			self::build(
				'psi.main_thread',
				$ctx,
				array(
					'category' => 'assets',
					'severity' => $ms >= 4000 ? 'critical' : ( $ms >= 2500 ? 'warning' : 'notice' ),
					/* translators: 1: time, 2: page. */
					'title'    => sprintf( __( 'The browser spends %1$s of main-thread work loading %2$s', 'blt-optimized' ), self::format_ms( $ms ), $ctx['on'] ),
					'summary'  => trim(
						sprintf(
							/* translators: %s: the biggest category of work. */
							__( 'This is how long Lighthouse\'s test phone was busy parsing, running scripts, styling and painting before the page settled; the page ignores taps while it works. %s', 'blt-optimized' ),
							$busy
						)
					),
					'impact'   => array(
						'type'  => 'ms',
						'value' => $ms,
					),
					'where'    => array( $ctx['url'] ),
					'details'  => self::file_table( 'work', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => __( 'Script work is usually the biggest slice: see the per-plugin "keeps the main thread busy" findings for this page, remove scripts you don\'t need, and defer the rest (Speed Tweaks → Defer JavaScript).', 'blt-optimized' ),
					),
				)
			),
		);
	}

	/**
	 * Total page weight.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function page_weight_finding( array $audit, array $ctx ) {
		$bytes = null !== $audit['value'] ? (int) round( $audit['value'] ) : (int) array_sum( wp_list_pluck( $audit['items'], 'total_bytes' ) );
		if ( $bytes <= 0 ) {
			return array();
		}
		return array(
			self::build(
				'psi.total_bytes',
				$ctx,
				array(
					'category' => 'assets',
					'severity' => $bytes >= 5 * MB_IN_BYTES ? 'critical' : ( $bytes >= 2.5 * MB_IN_BYTES ? 'warning' : 'notice' ),
					/* translators: 1: page, 2: size. */
					'title'    => sprintf( __( '%1$s downloads %2$s in total', 'blt-optimized' ), ucfirst( $ctx['on'] ), size_format( $bytes, 1 ) ),
					'summary'  => __( 'Every kilobyte is paid for by the visitor on a phone connection. The biggest files are listed below, with who serves each one; images and scripts are the usual culprits.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => self::where( $ctx, $audit['items'] ),
					'details'  => self::file_table( 'weight', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => __( 'Start with the largest files in the table: resize and compress images (WebP/AVIF), remove scripts and fonts you don\'t need, and lazy-load anything below the fold.', 'blt-optimized' ),
					),
				)
			),
		);
	}

	/* ---- Server findings ---------------------------------------------- */

	/**
	 * Server response time (TTFB) as Google measured it.
	 *
	 * @param array $audit  Parsed audit.
	 * @param array $result parse() result.
	 * @param array $ctx    Context.
	 * @return array[]
	 */
	private static function server_response_finding( array $audit, array $result, array $ctx ) {
		$ms = isset( $result['metrics']['ttfb']['value'] ) ? (float) $result['metrics']['ttfb']['value'] : ( null !== $audit['value'] ? (float) $audit['value'] : 0.0 );
		if ( $ms <= 0 && isset( $audit['items'][0]['wasted_ms'] ) ) {
			$ms = (float) $audit['items'][0]['wasted_ms'];
		}
		if ( $ms <= 0 ) {
			return array();
		}
		return array(
			self::build(
				'psi.server_response',
				$ctx,
				array(
					'category' => 'server',
					'severity' => $ms >= 1800 ? 'critical' : ( $ms >= 800 ? 'warning' : 'notice' ),
					/* translators: 1: page, 2: time. */
					'title'    => sprintf( __( 'Google waited %2$s for the server to start answering for %1$s', 'blt-optimized' ), $ctx['on'], self::format_ms( $ms ) ),
					'summary'  => __( 'Time to First Byte is how long the page request takes before any HTML arrives; everything else waits for it. Under about 600 ms is good. It includes network distance from Google\'s test server, so compare it with the PHP generation time in this scan\'s server findings: if that is also high, the site itself is slow (no page cache, slow plugins, slow database); if it is low, the host is far away or the cache isn\'t used for visitors.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'ms',
						'value' => $ms,
					),
					'where'    => array( $ctx['url'] ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to speed up the server', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Turn on full-page caching (your host\'s cache, or a caching plugin such as WP Rocket, LiteSpeed Cache or WP Super Cache) so visitors get a saved copy instead of running PHP.', 'blt-optimized' ),
								__( '2. Make sure PHP is current (8.1+) with OPcache on, and a persistent object cache (Redis/Memcached) if your host offers one.', 'blt-optimized' ),
								__( '3. Put a CDN in front of the site so the first byte comes from a nearby server.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * Uncompressed text resources.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function compression_finding( array $audit, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$bytes = max( (int) $audit['savings_bytes'], 0 );
		$count = count( $audit['items'] );
		return array(
			self::build(
				'psi.text_compression',
				$ctx,
				array(
					'category' => 'server',
					'severity' => self::severity( (float) $audit['savings_ms'], $bytes, false ),
					/* translators: 1: number of files, 2: page, 3: size. */
					'title'    => sprintf( _n( '%1$d file on %2$s is sent uncompressed (%3$s wasted)', '%1$d files on %2$s are sent uncompressed (%3$s wasted)', $count, 'blt-optimized' ), $count, $ctx['on'], size_format( max( 1, $bytes ), 0 ) ),
					'summary'  => __( 'HTML, CSS, JavaScript and fonts such as SVG compress to a quarter of their size with gzip or Brotli, but these files were sent as-is. That is a one-line server setting that speeds up every page.', 'blt-optimized' ),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => self::where( $ctx, $audit['items'] ),
					'details'  => self::file_table( 'compression', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to turn on compression', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Ask your host to enable gzip or Brotli (most control panels have a switch), or use a CDN such as Cloudflare, which compresses automatically.', 'blt-optimized' ),
								__( '2. Apache: enable mod_deflate (AddOutputFilterByType DEFLATE text/html text/css application/javascript …). Nginx: gzip on; gzip_types … in the server block.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/**
	 * Static files without long cache lifetimes.
	 *
	 * @param array $audit Parsed audit.
	 * @param array $ctx   Context.
	 * @return array[]
	 */
	private static function cache_ttl_finding( array $audit, array $ctx ) {
		if ( empty( $audit['items'] ) ) {
			return array();
		}
		$bytes = max( (int) $audit['savings_bytes'], 0 );
		$count = count( $audit['items'] );

		$local = 0;
		foreach ( $audit['items'] as $item ) {
			if ( isset( $item['component']['type'] ) && 'external' !== $item['component']['type'] ) {
				++$local;
			}
		}

		return array(
			self::build(
				'psi.cache_ttl',
				$ctx,
				array(
					'category' => 'server',
					'severity' => self::severity( (float) $audit['savings_ms'], $bytes, false ),
					/* translators: 1: number of files, 2: page. */
					'title'    => sprintf( _n( '%1$d file on %2$s is cached for too short a time', '%1$d files on %2$s are cached for too short a time', $count, 'blt-optimized' ), $count, $ctx['on'] ),
					'summary'  => sprintf(
						/* translators: 1: number of your own files, 2: number of all files. */
						__( 'Without a long browser-cache lifetime, returning visitors download these files again on every visit (%1$d of the %2$d listed are yours; the rest are served by third parties you can\'t change). Static files rarely change, so a lifetime of a year is safe when file URLs carry a version.', 'blt-optimized' ),
						$local,
						$count
					),
					'impact'   => array(
						'type'  => 'bytes',
						'value' => $bytes,
					),
					'where'    => self::where( $ctx, $audit['items'] ),
					'details'  => self::file_table( 'cache', $audit['items'], $ctx ),
					'fix'      => array(
						'type'  => 'manual',
						'label' => __( 'How to fix', 'blt-optimized' ),
						'steps' => implode(
							"\n",
							array(
								__( '1. Ask your host (or set up your caching plugin / CDN) to send Cache-Control: public, max-age=31536000 for CSS, JavaScript, images and fonts.', 'blt-optimized' ),
								__( '2. Apache: mod_expires ("ExpiresByType image/webp \"access plus 1 year\"" and so on). Nginx: expires 1y; in the static-files location block.', 'blt-optimized' ),
								__( '3. Files served by third parties (analytics, fonts, widgets) can\'t be changed from here.', 'blt-optimized' ),
							)
						),
					),
				)
			),
		);
	}

	/* ------------------------------------------------------------------ */
	/* Finding helpers                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Severity from savings: >= 1000 ms (or 500 KB) is a warning; huge is
	 * critical. Main-thread time uses lower time thresholds.
	 *
	 * @param float $ms       Milliseconds saved/blocked.
	 * @param int   $bytes    Bytes saved/transferred.
	 * @param bool  $blocking Whether $ms is main-thread time.
	 * @return string
	 */
	private static function severity( $ms, $bytes, $blocking ) {
		$warn = $blocking ? self::WARN_BLOCK_MS : self::WARN_MS;
		$crit = $blocking ? self::CRIT_BLOCK_MS : self::CRIT_MS;
		if ( $ms >= $crit || $bytes >= self::CRIT_BYTES ) {
			return 'critical';
		}
		if ( $ms >= $warn || $bytes >= self::WARN_BYTES ) {
			return 'warning';
		}
		return 'notice';
	}

	/**
	 * Human name of a component for a sentence.
	 *
	 * @param array $component Component.
	 * @return string
	 */
	private static function component_name( array $component ) {
		$name = isset( $component['name'] ) && '' !== $component['name'] ? $component['name'] : ( isset( $component['slug'] ) ? $component['slug'] : __( 'Unknown', 'blt-optimized' ) );
		switch ( isset( $component['type'] ) ? $component['type'] : 'other' ) {
			case 'theme':
				// URL-only attribution leaves the folder name; prefer the theme's real name.
				if ( isset( $component['slug'] ) && $name === $component['slug'] && function_exists( 'wp_get_theme' ) ) {
					$theme = wp_get_theme( $component['slug'] );
					if ( $theme->exists() ) {
						$name = (string) $theme->get( 'Name' );
					}
				}
				/* translators: %s: theme name. */
				return sprintf( __( 'The %s theme', 'blt-optimized' ), $name );
			case 'mu-plugin':
				/* translators: %s: must-use plugin name. */
				return sprintf( __( 'Must-use plugin %s', 'blt-optimized' ), $name );
			case 'external':
				/* translators: %s: third-party host or service name. */
				return sprintf( __( 'Third-party %s', 'blt-optimized' ), $name );
		}
		return $name;
	}

	/**
	 * "the home page" or the page path.
	 *
	 * @param string $url Page URL.
	 * @return string
	 */
	private static function page_label( $url ) {
		$parts = wp_parse_url( (string) $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$home  = trailingslashit( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ) );

		if ( '' === $query && ( trailingslashit( '' === $path ? '/' : $path ) === $home || '/' === $path ) ) {
			return __( 'the home page', 'blt-optimized' );
		}
		return self::excerpt( $path . $query, 60 );
	}

	/**
	 * Translated strategy name.
	 *
	 * @param string $strategy mobile|desktop.
	 * @return string
	 */
	private static function strategy_label( $strategy ) {
		return 'desktop' === $strategy ? __( 'desktop', 'blt-optimized' ) : __( 'mobile', 'blt-optimized' );
	}

	/**
	 * A file URL for display: path only on this site, host + path elsewhere.
	 *
	 * @param string $url        URL.
	 * @param array  $ctx        Context.
	 * @param bool   $with_query Keep the query string.
	 * @return string
	 */
	private static function short_url( $url, array $ctx, $with_query = true ) {
		$abs   = 0 === strpos( (string) $url, '//' ) ? 'https:' . $url : (string) $url;
		$parts = wp_parse_url( $abs );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return self::excerpt( $url, 90 );
		}
		$path  = ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( $with_query && isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
		$short = in_array( strtolower( $parts['host'] ), $ctx['hosts'], true ) ? $path : $parts['host'] . $path;
		return self::excerpt( $short, 90 );
	}

	/**
	 * "where to look": the page, then up to ten file URLs.
	 *
	 * @param array   $ctx   Context.
	 * @param array[] $items Items.
	 * @return string[]
	 */
	private static function where( array $ctx, array $items ) {
		$where = array( $ctx['url'] );
		foreach ( $items as $item ) {
			if ( ! empty( $item['url'] ) && ! in_array( $item['url'], $where, true ) ) {
				$where[] = $item['url'];
			}
			if ( count( $where ) >= 11 ) {
				break;
			}
		}
		return $where;
	}

	/**
	 * Evidence table.
	 *
	 * @param string  $kind  Table kind.
	 * @param array[] $items Parsed items.
	 * @param array   $ctx   Context.
	 * @return array details { columns, rows }
	 */
	private static function file_table( $kind, array $items, array $ctx ) {
		$dash = '—';
		$size = static function ( $bytes ) use ( $dash ) {
			return $bytes > 0 ? size_format( $bytes, $bytes >= MB_IN_BYTES ? 1 : 0 ) : $dash;
		};
		$time = static function ( $ms ) use ( $dash ) {
			return $ms > 0 ? self::format_ms( $ms ) : $dash;
		};

		$columns = array();
		$rows    = array();
		foreach ( $items as $item ) {
			$file  = '' !== $item['url'] ? self::short_url( $item['url'], $ctx ) : ( '' !== $item['label'] ? $item['label'] : $dash );
			$label = '' !== $item['label'] ? $item['label'] : $dash;
			$owner = ! empty( $item['component'] ) ? BLT_Optimized_Components::label( $item['component'] ) : $dash;

			switch ( $kind ) {
				case 'unused':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Downloaded', 'blt-optimized' ), __( 'Unused', 'blt-optimized' ), __( 'Unused share', 'blt-optimized' ) );
					$rows[]  = array(
						$file,
						$size( $item['total_bytes'] ),
						$size( $item['wasted_bytes'] ),
						$item['total_bytes'] > 0 ? round( 100 * $item['wasted_bytes'] / $item['total_bytes'] ) . '%' : $dash,
					);
					break;
				case 'savings':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Could save', 'blt-optimized' ) );
					$rows[]  = array( $file, $size( $item['total_bytes'] ), $size( $item['wasted_bytes'] ) );
					break;
				case 'detail':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Detail', 'blt-optimized' ), __( 'Could save', 'blt-optimized' ) );
					$rows[]  = array( $file, $label, $size( $item['wasted_bytes'] ) );
					break;
				case 'blocking':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Blocks rendering for', 'blt-optimized' ) );
					$rows[]  = array( $file, $size( $item['total_bytes'] ), $time( $item['wasted_ms'] ) );
					break;
				case 'cpu':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'CPU time', 'blt-optimized' ), __( 'Breakdown', 'blt-optimized' ) );
					$rows[]  = array( $file, $time( $item['wasted_ms'] ), $label );
					break;
				case 'third_party':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Downloaded', 'blt-optimized' ), __( 'Blocking time', 'blt-optimized' ) );
					$rows[]  = array( $file, $size( $item['total_bytes'] ), $time( $item['wasted_ms'] ) );
					break;
				case 'image':
					$columns = array( __( 'Image', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Could save', 'blt-optimized' ), __( 'Element', 'blt-optimized' ) );
					$rows[]  = array( $file, $size( $item['total_bytes'] ), $size( $item['wasted_bytes'] ), $label );
					break;
				case 'node':
					$columns = array( __( 'Element', 'blt-optimized' ), __( 'File', 'blt-optimized' ) );
					$rows[]  = array( $label, '' !== $item['url'] ? self::short_url( $item['url'], $ctx ) : $dash );
					break;
				case 'dom':
					$columns = array( __( 'Statistic', 'blt-optimized' ) );
					$rows[]  = array( $label );
					break;
				case 'font':
					$columns = array( __( 'Font file', 'blt-optimized' ), __( 'Hidden text for', 'blt-optimized' ), __( 'Served by', 'blt-optimized' ) );
					$rows[]  = array( $file, $time( $item['wasted_ms'] ), $owner );
					break;
				case 'work':
					$columns = array( __( 'Kind of work', 'blt-optimized' ), __( 'Time', 'blt-optimized' ) );
					$rows[]  = array( $label, $time( $item['wasted_ms'] ) );
					break;
				case 'weight':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Served by', 'blt-optimized' ) );
					$rows[]  = array( $file, $size( $item['total_bytes'] ), $owner );
					break;
				case 'compression':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Could save', 'blt-optimized' ), __( 'Served by', 'blt-optimized' ) );
					$rows[]  = array( $file, $size( $item['total_bytes'] ), $size( $item['wasted_bytes'] ), $owner );
					break;
				case 'cache':
					$columns = array( __( 'File', 'blt-optimized' ), __( 'Browser cache', 'blt-optimized' ), __( 'Size', 'blt-optimized' ), __( 'Served by', 'blt-optimized' ) );
					$rows[]  = array( $file, $label, $size( $item['total_bytes'] ), $owner );
					break;
			}
		}

		return array(
			'columns' => $columns,
			'rows'    => $rows,
		);
	}
}
