<?php
/**
 * Page auditor — loopback profiling and HTML analysis of real front-end pages.
 *
 * @package BLT_Optimized
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fetches real pages of this site the way a visitor would and reports what is
 * on them, so findings can name the exact page, post, image or script at fault
 * rather than "the site".
 *
 * Each audit() does up to four things for one URL:
 *
 *  1. A profiled loopback request carrying a one-time token. The front-end
 *     profiler (BLT_Optimized_Profiler) sees the token inside that request and
 *     records queries, plugin load cost, HTTP calls, errors and enqueued assets.
 *     We time the request (wall time and TTFB) and read the profile back.
 *  2. An optional plain request pair (no token) to find out whether a page
 *     cache serves the page — the profiled request deliberately bypasses any
 *     cache, so it can never tell us that.
 *  3. An optional HEAD request for a CSS and a JS file to check browser-cache
 *     headers on static assets.
 *  4. DOM analysis of the HTML: images (with real file sizes), embeds, scripts
 *     and styles in head vs body, inline weight, third-party origins, resource
 *     hints, and the class/id tokens used to prove an asset is (not) needed.
 *
 * Everything stays inside this site's own host. The HTML itself is never
 * stored — only the compact analysis — and every list is capped so a 2 MB page
 * cannot bloat the stored report.
 */
class BLT_Optimized_Page_Auditor {

	/**
	 * Array caps (the report is stored in an option).
	 */
	const MAX_IMAGES  = 200;
	const MAX_IFRAMES = 50;
	const MAX_SCRIPTS = 150;
	const MAX_STYLES  = 150;
	const MAX_HINTS   = 50;
	const MAX_THIRD   = 40;

	/**
	 * Token caps.
	 */
	const MAX_CLASS_TOKENS = 4000;
	const MAX_ID_TOKENS    = 1500;

	/**
	 * Largest HTML body kept (bytes) and the largest part of it that is
	 * handed to libxml. Anything beyond is ignored — such a page already
	 * fails the DOM-size and HTML-size checks.
	 */
	const MAX_BODY_BYTES  = 6291456;
	const MAX_PARSE_BYTES = 3145728;

	/**
	 * Loopback tuning.
	 */
	const TIMEOUT_PROFILED = 45;
	const TIMEOUT_PLAIN    = 20;
	const TIMEOUT_HEAD     = 10;
	const MAX_REDIRECTS    = 3;
	const PROFILE_WAIT     = 3.0;

	/**
	 * A static asset counts as well cached from this lifetime (7 days).
	 */
	const STATIC_CACHE_MIN_AGE = 604800;

	/**
	 * Desktop Chrome user agent, tagged so server logs show who asked.
	 */
	const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36 BLT-Optimized-Profiler';

	/**
	 * cURL handle of the request in flight (for TTFB).
	 *
	 * @var mixed
	 */
	private $curl_handle = null;

	/**
	 * URL the captured cURL handle must belong to.
	 *
	 * @var string
	 */
	private $curl_url = '';

	/**
	 * Memoised host => is-local lookups.
	 *
	 * @var array<string,bool>
	 */
	private $local_hosts = array();

	/**
	 * Memoised known third-party map.
	 *
	 * @var array<string,string>|null
	 */
	private $known_map = null;

	/**
	 * Memoised host/path => known service name lookups.
	 *
	 * @var array<string,string>
	 */
	private $known_cache = array();

	/* ------------------------------------------------------------------ */
	/* Sample URLs                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Representative pages to audit: one of each template type a visitor
	 * meets, plus the user's own extra URLs. Works without WooCommerce, with a
	 * static front page, and on a brand-new site with no content.
	 *
	 * At most 8 entries. User-supplied URLs (setting `audit_urls`, same host
	 * only, max 5) are always kept; when the total would exceed 8 the
	 * lowest-value automatic samples (search, archive, posts page, ...) are
	 * dropped first and the front page never is.
	 *
	 * @return array[] Each { url: string, label: string, type: string }.
	 */
	public function get_sample_urls() {
		$samples = array(); // Keyed by normalized URL so duplicates collapse.

		$front_id = 0;
		$posts_id = 0;
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$front_id = (int) get_option( 'page_on_front' );
			$posts_id = (int) get_option( 'page_for_posts' );
		}

		// 1. Front page.
		$this->add_sample( $samples, home_url( '/' ), __( 'Front page', 'blt-optimized' ), 'front_page' );

		// 2. Blog index, when it is a separate page.
		if ( $posts_id > 0 && 'publish' === get_post_status( $posts_id ) && $posts_id !== $front_id ) {
			$this->add_sample( $samples, get_permalink( $posts_id ), __( 'Posts page', 'blt-optimized' ), 'home' );
		}

		// 3. Latest published post.
		$post_id = $this->latest_post_id( 'post' );
		if ( $post_id > 0 ) {
			$this->add_sample(
				$samples,
				get_permalink( $post_id ),
				/* translators: %s: post title. */
				sprintf( __( 'Latest post: %s', 'blt-optimized' ), $this->short_title( $post_id ) ),
				'post'
			);
		}

		// 4. Latest published page that is not the front page, the posts page,
		// or a WooCommerce / privacy-policy utility page.
		$exclude_pages = array_merge( array( $front_id, $posts_id, (int) get_option( 'wp_page_for_privacy_policy' ) ), $this->woocommerce_page_ids() );
		$page_id       = $this->latest_post_id( 'page', array_filter( array_map( 'intval', $exclude_pages ) ) );
		if ( $page_id > 0 ) {
			$this->add_sample(
				$samples,
				get_permalink( $page_id ),
				/* translators: %s: page title. */
				sprintf( __( 'Latest page: %s', 'blt-optimized' ), $this->short_title( $page_id ) ),
				'page'
			);
		}

		// 5. A category archive that has posts (busiest first).
		$terms = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => true,
				'number'     => 1,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) && isset( $terms[0] ) && is_object( $terms[0] ) ) {
			$term_link = get_term_link( $terms[0] );
			if ( is_string( $term_link ) && '' !== $term_link ) {
				$this->add_sample(
					$samples,
					$term_link,
					/* translators: %s: category name. */
					sprintf( __( 'Category archive: %s', 'blt-optimized' ), wp_strip_all_tags( $terms[0]->name ) ),
					'archive'
				);
			}
		}

		// 6. WooCommerce shop + latest product (guarded: Woo may be absent).
		if ( function_exists( 'wc_get_page_id' ) ) {
			$shop_id  = (int) wc_get_page_id( 'shop' );
			$shop_url = '';
			if ( $shop_id > 0 && 'publish' === get_post_status( $shop_id ) ) {
				$shop_url = (string) get_permalink( $shop_id );
			} elseif ( post_type_exists( 'product' ) ) {
				$archive  = get_post_type_archive_link( 'product' );
				$shop_url = is_string( $archive ) ? $archive : '';
			}
			if ( '' !== $shop_url ) {
				$this->add_sample( $samples, $shop_url, __( 'Shop page', 'blt-optimized' ), 'shop' );
			}

			if ( post_type_exists( 'product' ) ) {
				$product_id = $this->latest_post_id( 'product' );
				if ( $product_id > 0 ) {
					$this->add_sample(
						$samples,
						get_permalink( $product_id ),
						/* translators: %s: product name. */
						sprintf( __( 'Latest product: %s', 'blt-optimized' ), $this->short_title( $product_id ) ),
						'product'
					);
				}
			}
		}

		// 7. Search results.
		$this->add_sample( $samples, home_url( '/?s=a' ), __( 'Search results', 'blt-optimized' ), 'search' );

		// 8. The user's own extra URLs (same host only).
		$custom = array();
		foreach ( $this->custom_urls() as $custom_url ) {
			$key = $this->url_key( $custom_url );
			if ( isset( $samples[ $key ] ) || isset( $custom[ $key ] ) ) {
				continue;
			}
			$path            = (string) wp_parse_url( $custom_url, PHP_URL_PATH );
			$query           = (string) wp_parse_url( $custom_url, PHP_URL_QUERY );
			$label           = ( '' === $path ? '/' : $path ) . ( '' !== $query ? '?' . $query : '' );
			$custom[ $key ] = array(
				'url'   => $custom_url,
				'label' => $label,
				'type'  => 'custom',
			);
			if ( count( $custom ) >= 5 ) {
				break;
			}
		}

		$auto = array_values( $samples );
		$max  = 8;
		if ( count( $auto ) + count( $custom ) > $max ) {
			foreach ( array( 'search', 'archive', 'home', 'page', 'product', 'shop', 'post' ) as $drop ) {
				if ( count( $auto ) + count( $custom ) <= $max ) {
					break;
				}
				foreach ( $auto as $i => $sample ) {
					if ( $drop === $sample['type'] ) {
						unset( $auto[ $i ] );
						break;
					}
				}
			}
			$auto = array_values( $auto );
		}

		$urls = array_merge( $auto, array_values( $custom ) );

		/**
		 * Filter the pages the performance scan audits.
		 *
		 * @param array[] $urls Each { url, label, type }.
		 */
		$filtered = apply_filters( 'blt_optimized_sample_urls', $urls );
		if ( ! is_array( $filtered ) ) {
			return $urls;
		}

		$clean = array();
		$seen  = array();
		foreach ( $filtered as $sample ) {
			if ( ! is_array( $sample ) || empty( $sample['url'] ) || ! is_string( $sample['url'] ) ) {
				continue;
			}
			$key = $this->url_key( $sample['url'] );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$clean[]      = array(
				'url'   => $sample['url'],
				'label' => isset( $sample['label'] ) ? (string) $sample['label'] : $sample['url'],
				'type'  => isset( $sample['type'] ) ? (string) $sample['type'] : 'custom',
			);
		}
		return $clean;
	}

	/* ------------------------------------------------------------------ */
	/* Audit                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Audit one page of this site.
	 *
	 * Options:
	 *  - tweaks_off   (bool)   Ask the profiler to bypass BLT speed tweaks for this
	 *                          request, to measure the page "before" tweaks.
	 *  - label        (string) Human label echoed into the report.
	 *  - type         (string) Sample type echoed into the report.
	 *  - check_cache  (bool)   Also fetch the page twice without the profile token
	 *                          (second response is used) to detect page caching and
	 *                          record cached timing in http.cached_ms/cached_ttfb_ms.
	 *                          Costs two extra requests, so the orchestrator sets it
	 *                          for the first (front page) URL only. Default false.
	 *  - check_static (bool)   Also send HEAD requests for the first local CSS and
	 *                          JS file on the page and store their cache headers in
	 *                          http.static_cache. Same rule: first URL only.
	 *                          Default false.
	 *
	 * Redirects are followed by hand (max 3, same host only) so the one-time
	 * profile token is never consumed by a redirect response; the URL finally
	 * served is reported in http.final_url.
	 *
	 * Returns a WP_Error when the page cannot be audited at all: URL on another
	 * host, connection failure, timeout, HTTP 401 (password protected), or a
	 * 403/429 block. Any other HTTP status (404, 500, ...) is returned as a normal
	 * report with profile_error = 'http_error' and http.error describing it.
	 *
	 * @param string $url  Page URL (must be on this site's host).
	 * @param array  $opts Options, see above.
	 * @return array|WP_Error Page report (see the module spec) or error.
	 */
	public function audit( $url, array $opts = array() ) {
		$opts = wp_parse_args(
			$opts,
			array(
				'tweaks_off'   => false,
				'label'        => '',
				'type'         => '',
				'check_cache'  => false,
				'check_static' => false,
			)
		);

		$target = $this->validate_target( $url );
		if ( is_wp_error( $target ) ) {
			return $target;
		}

		@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, Squiz.PHP.DiscouragedFunctions.Discouraged -- Slow hosts need headroom; may be disabled.

		$started = microtime( true );
		$current = $this->normalize_url( $target );
		$first   = $current;

		// 1. Profiled request, following redirects ourselves so the profile
		// belongs to the page that is finally served.
		$token    = '';
		$response = null;
		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$token = $this->new_token( ! empty( $opts['tweaks_off'] ) );
			$args  = array( 'blt_nc' => wp_rand() );
			if ( '' !== $token ) {
				$args[ $this->query_arg() ] = $token;
			}
			$response = $this->fetch(
				add_query_arg( $args, $current ),
				array(
					'timeout'  => self::TIMEOUT_PROFILED,
					'no_cache' => true,
				)
			);
			if ( is_wp_error( $response ) ) {
				return $this->loopback_error( $response, $current );
			}
			if ( $response['status'] >= 300 && $response['status'] < 400 && '' !== $response['location'] ) {
				$next = $this->redirect_target( $response['location'], $current );
				if ( is_wp_error( $next ) ) {
					return $next;
				}
				$this->discard_profile( $token ); // The redirect response consumed the token.
				$current = $next;
				continue;
			}
			break;
		}
		if ( $response['status'] >= 300 && $response['status'] < 400 && '' !== $response['location'] ) {
			return new WP_Error(
				'blt_optimized_redirect_loop',
				sprintf(
					/* translators: %s: URL. */
					__( '%s redirects too many times, so it cannot be audited. Check the redirect rules for this URL (a redirect loop is also a speed problem for visitors).', 'blt-optimized' ),
					$first
				)
			);
		}

		$status = (int) $response['status'];
		if ( 401 === $status ) {
			return $this->auth_error( $current, '' !== $this->auth_header( $current ) );
		}
		if ( 403 === $status || 429 === $status || ( 503 === $status && isset( $response['headers']['cf-mitigated'] ) ) ) {
			return $this->blocked_error( $current, $status );
		}

		// 2. Profile result.
		$headers_all = $response['headers'];
		$profile     = $this->retrieve_profile( $token, isset( $headers_all['x-blt-profiled'] ) ? self::PROFILE_WAIT : 0.5 );
		if ( null !== $profile ) {
			$profile_error = '';
		} elseif ( '' === $token ) {
			$profile_error = 'not_received';
		} elseif ( $status >= 400 ) {
			$profile_error = 'http_error';
		} elseif ( 200 === $status && ! isset( $headers_all['x-blt-profiled'] ) ) {
			$profile_error = 'page_cache';
		} else {
			$profile_error = 'not_received';
		}
		if ( $status >= 400 && '' === $profile_error ) {
			$profile_error = 'http_error';
		}

		// 3. HTTP block.
		$encoding = isset( $headers_all['content-encoding'] ) ? strtolower( trim( $headers_all['content-encoding'] ) ) : '';
		$bytes    = strlen( $response['body'] );
		if ( '' === $encoding && null !== $response['transfer_bytes'] && $bytes > 1024 && $response['transfer_bytes'] < $bytes * 0.9 ) {
			// libcurl decoded the body and the header was not reported.
			$encoding = 'compressed';
		}

		$http = array(
			'status'         => $status,
			'total_ms'       => $response['total_ms'],
			'ttfb_ms'        => $response['ttfb_ms'],
			'bytes'          => $bytes,
			'transfer_bytes' => $response['transfer_bytes'],
			'encoding'       => $encoding,
			'headers'        => $this->header_subset( $headers_all ),
			'page_cache'     => array(
				'detected' => false,
				'evidence' => '',
			),
			'cached_ms'      => null,
			'cached_ttfb_ms' => null,
			'static_cache'   => array(),
			'error'          => $status >= 400
				? sprintf(
					/* translators: 1: HTTP status code, 2: status text. */
					__( 'HTTP %1$d %2$s', 'blt-optimized' ),
					$status,
					get_status_header_desc( $status )
				)
				: '',
			'final_url'      => ( $current !== $first ) ? $current : '',
		);

		// 4. Page cache detection from a plain (token-less) request pair.
		if ( ! empty( $opts['check_cache'] ) && $status < 400 ) {
			$http = $this->detect_cache( $http, $current, $started );
		}

		// 5. Content analysis.
		$post     = $this->resolve_post( $profile, $current );
		$haystack = $post ? $this->post_haystack( $post['id'] ) : '';

		$html = $this->empty_html_report( $bytes );
		if ( $status < 400 && $this->is_html_response( $headers_all ) ) {
			try {
				$html = $this->analyze_html( $response['body'], $current, $haystack, $this->response_charset( $headers_all ) );
			} catch ( \Throwable $e ) {
				$html = $this->empty_html_report( $bytes ); // Odd markup must never break the scan.
			}
		}
		unset( $response['body'] ); // Never keep the HTML.

		// 6. Static asset cache headers.
		if ( ! empty( $opts['check_static'] ) && $status < 400 ) {
			$static = array();
			foreach ( array( $this->first_local( $html['stylesheets'], 'href' ), $this->first_local( $html['scripts'], 'src' ) ) as $asset_url ) {
				if ( '' === $asset_url ) {
					continue;
				}
				$check = $this->check_static_caching( $asset_url );
				if ( $check['status'] >= 200 && $check['status'] < 300 ) {
					$static[] = $check;
				}
			}
			$http['static_cache'] = $static;
		}

		return array(
			'url'           => $target,
			'label'         => '' !== (string) $opts['label'] ? (string) $opts['label'] : (string) wp_parse_url( $target, PHP_URL_PATH ),
			'type'          => (string) $opts['type'],
			'fetched_at'    => time(),
			'tweaks_off'    => ! empty( $opts['tweaks_off'] ),
			'http'          => $http,
			'profile'       => $profile,
			'profile_error' => $profile_error,
			'html'          => $html,
			'post'          => $post,
		);
	}

	/**
	 * Browser-cache headers of one static asset.
	 *
	 * @param string $asset_url Absolute URL (local asset).
	 * @return array { url, status, cache_control, expires, max_age: int|null, ok: bool }
	 *               status 0 means the request failed. max_age is the freshness
	 *               lifetime in seconds from Cache-Control max-age, else from Expires.
	 */
	public function check_static_caching( $asset_url ) {
		$asset_url = (string) $asset_url;
		$result    = array(
			'url'           => $asset_url,
			'status'        => 0,
			'cache_control' => '',
			'expires'       => '',
			'max_age'       => null,
			'ok'            => false,
		);

		// This site only: never send a request (let alone credentials) elsewhere.
		$valid = $this->validate_target( $asset_url );
		if ( is_wp_error( $valid ) ) {
			return $result;
		}
		$asset_url = $valid;

		$headers = array( 'Accept-Encoding' => $this->accept_encoding() );
		$auth    = $this->auth_header( $asset_url );
		if ( '' !== $auth ) {
			$headers['Authorization'] = $auth; // Only for the exact home host + scheme; redirection is 0.
		}
		$args = array(
			'timeout'     => self::TIMEOUT_HEAD,
			'redirection' => 0,
			'sslverify'   => (bool) apply_filters( 'https_local_ssl_verify', false, $asset_url ),
			'user-agent'  => self::USER_AGENT,
			'headers'     => $headers,
		);

		$response = wp_remote_head( $asset_url, $args );
		if ( ! is_wp_error( $response ) && in_array( (int) wp_remote_retrieve_response_code( $response ), array( 405, 501 ), true ) ) {
			// Some servers refuse HEAD; a tiny GET gives the same headers.
			$args['limit_response_size'] = 1024;
			$response                    = wp_remote_get( $asset_url, $args );
		}
		if ( is_wp_error( $response ) ) {
			return $result;
		}

		$result['status']        = (int) wp_remote_retrieve_response_code( $response );
		$result['cache_control'] = strtolower( trim( (string) wp_remote_retrieve_header( $response, 'cache-control' ) ) );
		$result['expires']       = trim( (string) wp_remote_retrieve_header( $response, 'expires' ) );

		$max_age = null;
		if ( preg_match( '/(?:^|[\s,])max-age\s*=\s*"?(\d+)/', $result['cache_control'], $m ) ) {
			$max_age = (int) $m[1];
		} elseif ( preg_match( '/(?:^|[\s,])s-maxage\s*=\s*"?(\d+)/', $result['cache_control'], $m ) ) {
			$max_age = (int) $m[1];
		} elseif ( '' !== $result['expires'] ) {
			$expires = strtotime( $result['expires'] );
			if ( false !== $expires ) {
				$date    = strtotime( (string) wp_remote_retrieve_header( $response, 'date' ) );
				$max_age = max( 0, $expires - ( false !== $date ? $date : time() ) );
			}
		}

		$result['max_age'] = $max_age;
		$result['ok']      = null !== $max_age
			&& $max_age >= self::STATIC_CACHE_MIN_AGE
			&& false === strpos( $result['cache_control'], 'no-store' )
			&& false === strpos( $result['cache_control'], 'no-cache' );

		return $result;
	}

	/**
	 * Hook callback: keep the cURL handle of our own request so TTFB can be
	 * read afterwards. Public only because WordPress calls it.
	 *
	 * @param mixed  $handle      cURL handle.
	 * @param array  $parsed_args Request args (unused).
	 * @param string $url         Request URL.
	 * @return void
	 */
	public function capture_curl_handle( $handle, $parsed_args = array(), $url = '' ) {
		unset( $parsed_args );
		if ( '' === $this->curl_url || (string) $url === $this->curl_url ) {
			$this->curl_handle = $handle;
		}
	}

	/* ------------------------------------------------------------------ */
	/* Sample URL helpers                                                  */
	/* ------------------------------------------------------------------ */

	/**
	 * Add a sample unless its normalized URL is already present.
	 *
	 * @param array  $samples Samples by reference.
	 * @param mixed  $url     URL (false/empty ignored).
	 * @param string $label   Label.
	 * @param string $type    Type.
	 * @return void
	 */
	private function add_sample( array &$samples, $url, $label, $type ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return;
		}
		$key = $this->url_key( $url );
		if ( isset( $samples[ $key ] ) ) {
			return;
		}
		$samples[ $key ] = array(
			'url'   => $url,
			'label' => $label,
			'type'  => $type,
		);
	}

	/**
	 * Comparison key for de-duplication: scheme-insensitive, host lowercased,
	 * trailing slash ignored, query kept.
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private function url_key( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return strtolower( (string) $url );
		}
		$host  = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
		$port  = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path  = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $host . $port . $path . $query;
	}

	/**
	 * Newest published post ID of a type, or 0.
	 *
	 * @param string $post_type Post type.
	 * @param int[]  $exclude   IDs to skip.
	 * @return int
	 */
	private function latest_post_id( $post_type, array $exclude = array() ) {
		$ids = get_posts(
			array(
				'post_type'           => $post_type,
				'post_status'         => 'publish',
				'posts_per_page'      => 1,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'fields'              => 'ids',
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'has_password'        => false,
				'post__not_in'        => $exclude, // phpcs:ignore WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_post__not_in -- A handful of IDs.
			)
		);
		return empty( $ids ) ? 0 : (int) $ids[0];
	}

	/**
	 * Post title, tag-free and shortened for use in a label.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function short_title( $post_id ) {
		$title = wp_strip_all_tags( get_the_title( $post_id ) );
		if ( '' === $title ) {
			/* translators: %d: post ID. */
			return sprintf( __( '#%d', 'blt-optimized' ), $post_id );
		}
		return wp_html_excerpt( $title, 60, '…' );
	}

	/**
	 * WooCommerce utility pages that make poor "latest page" samples.
	 *
	 * @return int[]
	 */
	private function woocommerce_page_ids() {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return array();
		}
		$ids = array();
		foreach ( array( 'shop', 'cart', 'checkout', 'myaccount', 'terms', 'refund_returns' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Extra URLs from the `audit_urls` setting: one per line, same host only
	 * (root-relative paths allowed). The caller de-duplicates and caps them.
	 *
	 * @return string[]
	 */
	private function custom_urls() {
		if ( ! class_exists( 'BLT_Optimized' ) ) {
			return array();
		}
		$settings = BLT_Optimized::get_settings();
		$raw      = isset( $settings['audit_urls'] ) ? (string) $settings['audit_urls'] : '';
		$urls     = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			if ( '/' === $line[0] && 0 !== strpos( $line, '//' ) ) {
				$line = home_url( $line );
			}
			$line = esc_url_raw( $line, array( 'http', 'https' ) );
			if ( '' === $line ) {
				continue;
			}
			$host = (string) wp_parse_url( $line, PHP_URL_HOST );
			if ( '' === $host || ! $this->is_local( $host ) ) {
				continue;
			}
			$urls[] = $line;
			if ( count( $urls ) >= 20 ) {
				break;
			}
		}
		return $urls;
	}

	/* ------------------------------------------------------------------ */
	/* Request plumbing                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The profiler's query-arg name (spec constant, tolerant of the stub).
	 *
	 * @return string
	 */
	private function query_arg() {
		return defined( 'BLT_Optimized_Profiler::QUERY_ARG' ) ? BLT_Optimized_Profiler::QUERY_ARG : 'blt_optimized_profile';
	}

	/**
	 * Ask the profiler for a one-time token. '' when the profiler is missing or
	 * not ready — the audit then degrades to HTML analysis only.
	 *
	 * @param bool $tweaks_off Bypass BLT speed tweaks for the profiled request.
	 * @return string
	 */
	private function new_token( $tweaks_off ) {
		if ( ! class_exists( 'BLT_Optimized_Profiler' ) || ! method_exists( 'BLT_Optimized_Profiler', 'create_token' ) ) {
			return '';
		}
		try {
			$token = BLT_Optimized_Profiler::create_token( array( 'tweaks_off' => (bool) $tweaks_off ) );
		} catch ( \Throwable $e ) {
			return '';
		}
		return ( is_string( $token ) && preg_match( '/^[A-Za-z0-9]{32}$/', $token ) ) ? $token : '';
	}

	/**
	 * Read the profile back. Shutdown work can finish after the body, so wait
	 * a little — but only as long as is plausible.
	 *
	 * @param string $token Token.
	 * @param float  $wait  Seconds to keep trying.
	 * @return array|null
	 */
	private function retrieve_profile( $token, $wait ) {
		if ( '' === $token || ! class_exists( 'BLT_Optimized_Profiler' ) || ! method_exists( 'BLT_Optimized_Profiler', 'get_result' ) ) {
			return null;
		}
		$deadline = microtime( true ) + (float) $wait;
		$attempt  = 0;
		do {
			if ( $attempt > 0 ) {
				// get_option() remembers "this option does not exist" for the rest
				// of the request; without this the transient written by the
				// front-end process would never become visible to us.
				wp_cache_delete( 'notoptions', 'options' );
				wp_cache_delete( '_transient_blt_optimized_profres_' . $token, 'options' );
				wp_cache_delete( '_transient_timeout_blt_optimized_profres_' . $token, 'options' );
			}
			try {
				$result = BLT_Optimized_Profiler::get_result( $token );
			} catch ( \Throwable $e ) {
				$result = null;
			}
			if ( is_array( $result ) && ! empty( $result ) ) {
				return $result;
			}
			++$attempt;
			if ( microtime( true ) >= $deadline ) {
				break;
			}
			usleep( 200000 );
		} while ( true );
		return null;
	}

	/**
	 * Drop the profile left behind by a request that was only a redirect.
	 *
	 * @param string $token Token.
	 * @return void
	 */
	private function discard_profile( $token ) {
		if ( '' === $token || ! class_exists( 'BLT_Optimized_Profiler' ) || ! method_exists( 'BLT_Optimized_Profiler', 'get_result' ) ) {
			return;
		}
		try {
			BLT_Optimized_Profiler::get_result( $token );
		} catch ( \Throwable $e ) {
			return;
		}
	}

	/**
	 * One loopback GET with timing.
	 *
	 * @param string $url  URL.
	 * @param array  $args { timeout: int, no_cache: bool }.
	 * @return array|WP_Error { status, headers (all, lowercased), body, total_ms, ttfb_ms, transfer_bytes, location }
	 */
	private function fetch( $url, array $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'timeout'  => self::TIMEOUT_PLAIN,
				'no_cache' => false,
			)
		);

		$headers = array(
			'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
			'Accept-Language' => 'en-US,en;q=0.9',
			'Accept-Encoding' => $this->accept_encoding(),
		);
		if ( $args['no_cache'] ) {
			$headers['Cache-Control'] = 'no-cache';
			$headers['Pragma']        = 'no-cache';
		}
		$auth = $this->auth_header( $url );
		if ( '' !== $auth ) {
			$headers['Authorization'] = $auth; // Redirects are followed by hand; re-checked per hop.
		}

		$request_args = array(
			'timeout'             => (int) $args['timeout'],
			'redirection'         => 0,
			'sslverify'           => (bool) apply_filters( 'https_local_ssl_verify', false, $url ),
			'user-agent'          => self::USER_AGENT,
			'headers'             => $headers,
			'cookies'             => array(), // A visitor, not a logged-in admin.
			'limit_response_size' => self::MAX_BODY_BYTES,
		);

		$this->curl_handle = null;
		$this->curl_url    = $url;
		add_action( 'http_api_curl', array( $this, 'capture_curl_handle' ), 10, 3 );

		$start    = microtime( true );
		$response = wp_remote_get( $url, $request_args );
		$total_ms = round( ( microtime( true ) - $start ) * 1000, 1 );

		remove_action( 'http_api_curl', array( $this, 'capture_curl_handle' ), 10 );
		$this->curl_url = '';

		$ttfb     = null;
		$transfer = null;
		if ( null !== $this->curl_handle ) {
			try {
				$start_transfer = curl_getinfo( $this->curl_handle, CURLINFO_STARTTRANSFER_TIME );
				if ( is_numeric( $start_transfer ) && $start_transfer > 0 ) {
					$ttfb = round( (float) $start_transfer * 1000, 1 );
				}
				$downloaded = curl_getinfo( $this->curl_handle, CURLINFO_SIZE_DOWNLOAD );
				if ( is_numeric( $downloaded ) && $downloaded >= 0 ) {
					$transfer = (int) $downloaded;
				}
			} catch ( \Throwable $e ) {
				$ttfb     = null;
				$transfer = null;
			}
			$this->curl_handle = null;
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'status'         => (int) wp_remote_retrieve_response_code( $response ),
			'headers'        => $this->collect_headers( $response ),
			'body'           => (string) wp_remote_retrieve_body( $response ),
			'total_ms'       => $total_ms,
			'ttfb_ms'        => $ttfb,
			'transfer_bytes' => $transfer,
			'location'       => trim( (string) wp_remote_retrieve_header( $response, 'location' ) ),
		);
	}

	/**
	 * Accept-Encoding to advertise: only what the transport can decode. Brotli
	 * is offered only when this cURL build decodes it, otherwise a server that
	 * prefers br would hand us undecodable bytes.
	 *
	 * @return string
	 */
	private function accept_encoding() {
		$encoding = 'gzip, deflate';
		if ( function_exists( 'curl_version' ) && defined( 'CURL_VERSION_BROTLI' ) ) {
			$info = curl_version();
			if ( is_array( $info ) && isset( $info['features'] ) && ( (int) $info['features'] & CURL_VERSION_BROTLI ) ) {
				$encoding .= ', br';
			}
		}
		return $encoding;
	}

	/**
	 * HTTP basic-auth credentials of the current admin request, if it carries
	 * any. A staging site behind a server password protects the front end the
	 * same way, so the loopback needs them too. Only ever sent to this site's
	 * own host (redirects are followed manually and validated).
	 *
	 * Credentials only ever go to the site's own front door: the exact scheme,
	 * host and port of home_url(). Never to the content/CDN host (which also
	 * counts as "local" for analysis), and never downgraded from https to http.
	 *
	 * @param string $url URL the request is about to be sent to.
	 * @return string "Basic …" header value or ''.
	 */
	private function auth_header( $url ) {
		$home   = wp_parse_url( home_url() );
		$target = wp_parse_url( (string) $url );
		if ( ! is_array( $home ) || ! is_array( $target )
			|| strtolower( isset( $target['scheme'] ) ? $target['scheme'] : '' ) !== strtolower( isset( $home['scheme'] ) ? $home['scheme'] : '' )
			|| strtolower( isset( $target['host'] ) ? $target['host'] : '' ) !== strtolower( isset( $home['host'] ) ? $home['host'] : '' )
			|| ( isset( $target['port'] ) ? (int) $target['port'] : 0 ) !== ( isset( $home['port'] ) ? (int) $home['port'] : 0 )
		) {
			return '';
		}

		/**
		 * Whether to forward the admin request's HTTP basic-auth credentials to
		 * the loopback requests.
		 *
		 * @param bool $forward Default true.
		 */
		if ( ! apply_filters( 'blt_optimized_forward_basic_auth', true ) ) {
			return '';
		}
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.InputNotValidated, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		if ( ! empty( $_SERVER['PHP_AUTH_USER'] ) ) {
			$user = (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] );
			$pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) : '';
			return 'Basic ' . base64_encode( $user . ':' . $pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- HTTP basic auth encoding.
		}
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && 0 === stripos( (string) $_SERVER[ $key ], 'basic ' ) ) {
				return trim( (string) wp_unslash( $_SERVER[ $key ] ) );
			}
		}
		// phpcs:enable
		return '';
	}

	/**
	 * Response headers, lowercased, multi-value joined; cookie VALUES are never
	 * kept (only the names), since they can be session tokens.
	 *
	 * @param array $response wp_remote_get() response.
	 * @return array<string,string>
	 */
	private function collect_headers( $response ) {
		$raw = wp_remote_retrieve_headers( $response );
		if ( is_object( $raw ) && method_exists( $raw, 'getAll' ) ) {
			$raw = $raw->getAll();
		}
		$out = array();
		foreach ( (array) $raw as $name => $value ) {
			$out[ strtolower( (string) $name ) ] = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
		}
		unset( $out['set-cookie'] );
		$names = array();
		foreach ( (array) wp_remote_retrieve_cookies( $response ) as $cookie ) {
			if ( is_object( $cookie ) && isset( $cookie->name ) ) {
				$names[] = (string) $cookie->name;
			}
		}
		if ( $names ) {
			$out['set-cookie'] = implode( ', ', $names );
		}
		return $out;
	}

	/**
	 * Headers worth keeping in the report: caching, compression, server and
	 * CDN/cache-plugin signals.
	 *
	 * @param array<string,string> $all All response headers (lowercased).
	 * @return array<string,string>
	 */
	private function header_subset( array $all ) {
		$keep = array(
			'content-type',
			'content-encoding',
			'content-length',
			'cache-control',
			'expires',
			'last-modified',
			'etag',
			'age',
			'vary',
			'server',
			'x-powered-by',
			'via',
			'strict-transport-security',
			'set-cookie',
			'x-varnish',
			'x-served-by',
			'x-blt-profiled',
		);
		$out  = array();
		foreach ( $all as $name => $value ) {
			if ( in_array( $name, $keep, true )
				|| preg_match( '/(^|-)cache(-|$)/', $name )
				|| 0 === strpos( $name, 'cf-' )
				|| 0 === strpos( $name, 'x-rocket' )
				|| 0 === strpos( $name, 'x-wp-' )
				|| 0 === strpos( $name, 'x-litespeed' )
				|| 0 === strpos( $name, 'x-kinsta' )
				|| 0 === strpos( $name, 'x-sg-' )
			) {
				$out[ $name ] = substr( $value, 0, 300 );
			}
		}
		return $out;
	}

	/**
	 * Whether the response looks like HTML (or doesn't say).
	 *
	 * @param array<string,string> $headers Headers.
	 * @return bool
	 */
	private function is_html_response( array $headers ) {
		$type = isset( $headers['content-type'] ) ? strtolower( $headers['content-type'] ) : '';
		return '' === $type || false !== strpos( $type, 'html' );
	}

	/**
	 * Charset named by Content-Type, lowercased ('' when absent).
	 *
	 * @param array<string,string> $headers Headers.
	 * @return string
	 */
	private function response_charset( array $headers ) {
		if ( isset( $headers['content-type'] ) && preg_match( '/charset\s*=\s*"?([A-Za-z0-9._:-]+)/i', $headers['content-type'], $m ) ) {
			return strtolower( $m[1] );
		}
		return '';
	}

	/**
	 * Validate an audit target: http(s), this site's host, and one of this
	 * site's ports (the loopback must never become a generic port scanner).
	 *
	 * @param string $url URL as given.
	 * @return string|WP_Error Absolute URL without fragment, or error.
	 */
	private function validate_target( $url ) {
		$url   = BLT_Optimized_Components::absolutize( trim( (string) $url ) );
		$parts = wp_parse_url( $url );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
			return new WP_Error(
				'blt_optimized_bad_url',
				__( 'That is not a valid page URL. Use a full address such as https://example.com/about/.', 'blt-optimized' )
			);
		}

		if ( ! $this->is_local( $parts['host'] ) ) {
			return new WP_Error(
				'blt_optimized_external_url',
				sprintf(
					/* translators: 1: host of the rejected URL, 2: this site's host. */
					__( 'Only pages on this site can be audited. %1$s is not %2$s.', 'blt-optimized' ),
					$parts['host'],
					(string) wp_parse_url( home_url(), PHP_URL_HOST )
				)
			);
		}

		$allowed = array();
		foreach ( array( home_url(), site_url(), content_url() ) as $own ) {
			$own_parts = wp_parse_url( $own );
			if ( ! is_array( $own_parts ) ) {
				continue;
			}
			$scheme = isset( $own_parts['scheme'] ) ? $own_parts['scheme'] : 'http';
			$port   = isset( $own_parts['port'] ) ? (int) $own_parts['port'] : ( 'https' === $scheme ? 443 : 80 );
			if ( 80 === $port || 443 === $port ) {
				$allowed[80]  = true;
				$allowed[443] = true;
			}
			$allowed[ $port ] = true;
		}
		$port = isset( $parts['port'] ) ? (int) $parts['port'] : ( 'https' === strtolower( $parts['scheme'] ) ? 443 : 80 );
		if ( ! isset( $allowed[ $port ] ) ) {
			return new WP_Error(
				'blt_optimized_bad_port',
				sprintf(
					/* translators: %d: port number. */
					__( 'Port %d is not one this site is served on, so that URL cannot be audited.', 'blt-optimized' ),
					$port
				)
			);
		}

		unset( $parts['fragment'] );
		return $this->build_url( $parts );
	}

	/**
	 * Resolve a Location header for the next hop and validate it.
	 *
	 * @param string $location Location header.
	 * @param string $current  URL that redirected.
	 * @return string|WP_Error
	 */
	private function redirect_target( $location, $current ) {
		$next = $this->resolve_url( $location, $current );
		$next = remove_query_arg( array( $this->query_arg(), 'blt_nc' ), $next );
		$host = (string) wp_parse_url( $next, PHP_URL_HOST );
		if ( '' === $host || ! $this->is_local( $host ) ) {
			return new WP_Error(
				'blt_optimized_redirect_external',
				sprintf(
					/* translators: 1: audited URL, 2: redirect destination. */
					__( '%1$s redirects to %2$s, which is not this site, so it cannot be audited. Audit the final address instead (or fix the redirect if it is unintended).', 'blt-optimized' ),
					$current,
					$next
				)
			);
		}
		$valid = $this->validate_target( $next );
		return is_wp_error( $valid ) ? $valid : $this->normalize_url( $valid );
	}

	/**
	 * Add/remove the trailing slash the permalink structure expects, so a
	 * hand-typed "/contact" is requested as "/contact/" and does not cost a
	 * redirect. URLs with a query string or a file extension are left alone.
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	private function normalize_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || ! empty( $parts['query'] ) ) {
			return $url;
		}
		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '' === $path ) {
			$parts['path'] = '/';
		} elseif ( '/' !== $path && false === strpos( (string) wp_basename( $path ), '.' ) && function_exists( 'user_trailingslashit' ) ) {
			$parts['path'] = user_trailingslashit( $path );
		}
		return $this->build_url( $parts );
	}

	/**
	 * Rebuild a URL from wp_parse_url() parts.
	 *
	 * @param array $parts URL parts.
	 * @return string
	 */
	private function build_url( array $parts ) {
		return ( isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '//' )
			. ( isset( $parts['user'] ) ? $parts['user'] . ( isset( $parts['pass'] ) ? ':' . $parts['pass'] : '' ) . '@' : '' )
			. ( isset( $parts['host'] ) ? $parts['host'] : '' )
			. ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' )
			. ( isset( $parts['path'] ) ? $parts['path'] : '' )
			. ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	}

	/**
	 * Memoised BLT_Optimized_Components::is_local_host() (it re-parses three
	 * URLs per call, and a page has hundreds of asset URLs).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private function is_local( $host ) {
		$host = strtolower( (string) $host );
		if ( '' === $host ) {
			return false;
		}
		if ( ! isset( $this->local_hosts[ $host ] ) ) {
			$this->local_hosts[ $host ] = BLT_Optimized_Components::is_local_host( $host );
		}
		return $this->local_hosts[ $host ];
	}

	/* ------------------------------------------------------------------ */
	/* Errors                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Turn a transport error into a message that says what to check.
	 *
	 * @param WP_Error $error Transport error.
	 * @param string   $url   URL that failed.
	 * @return WP_Error
	 */
	private function loopback_error( WP_Error $error, $url ) {
		$message = $error->get_error_message();
		$code    = (string) $error->get_error_code();
		$host    = (string) wp_parse_url( $url, PHP_URL_HOST );
		$curl    = preg_match( '/cURL error (\d+)/i', $message, $m ) ? (int) $m[1] : 0;

		if ( 28 === $curl || false !== stripos( $message, 'timed out' ) || false !== stripos( $message, 'timeout' ) ) {
			$reason = 'timeout';
			$text   = sprintf(
				/* translators: 1: URL, 2: seconds. */
				__( '%1$s did not answer within %2$d seconds. The page is extremely slow, or the server is blocking or throttling requests it makes to itself. Try again; if it keeps happening, the slow response is itself the performance problem.', 'blt-optimized' ),
				$url,
				self::TIMEOUT_PROFILED
			);
		} elseif ( 6 === $curl || false !== stripos( $message, 'could not resolve' ) || false !== stripos( $message, 'getaddrinfo' ) ) {
			$reason = 'dns';
			$text   = sprintf(
				/* translators: %s: host name. */
				__( 'This server could not look up its own address (%s). Loopback requests fail when the hosting environment cannot resolve the site domain from the inside (common behind some proxies and on local or staging setups). Ask your host to allow the server to reach its own domain, or add the domain to the server\'s hosts file.', 'blt-optimized' ),
				$host
			);
		} elseif ( 7 === $curl || false !== stripos( $message, 'connection refused' ) || false !== stripos( $message, 'failed to connect' ) || false !== stripos( $message, 'couldn\'t connect' ) ) {
			$reason = 'refused';
			$text   = sprintf(
				/* translators: %s: URL. */
				__( 'The server refused a connection to itself at %s. A firewall or security plugin may be blocking loopback requests, or the site is only reachable through a proxy or on a different port from the server\'s own point of view. Ask your host to allow requests from the server to its own domain.', 'blt-optimized' ),
				$url
			);
		} elseif ( in_array( $curl, array( 35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91 ), true ) || false !== stripos( $message, 'ssl' ) ) {
			$reason = 'ssl';
			$text   = __( 'The secure (HTTPS) connection to this site\'s own address failed. The certificate may be self-signed, expired or incomplete. Fix the certificate, or for a local/staging site allow it with: add_filter( \'https_local_ssl_verify\', \'__return_false\' );', 'blt-optimized' );
		} elseif ( 'http_request_not_executed' === $code || false !== stripos( $message, 'blocked requests' ) ) {
			$reason = 'blocked';
			$text   = __( 'WordPress is configured to block outgoing HTTP requests (WP_HTTP_BLOCK_EXTERNAL), including the one this scan needs. Add this site\'s host to WP_ACCESSIBLE_HOSTS in wp-config.php.', 'blt-optimized' );
		} else {
			$reason = 'other';
			$text   = sprintf(
				/* translators: %s: URL. */
				__( 'This server could not load %s from itself. A firewall, a security plugin or the hosting setup may be blocking loopback requests.', 'blt-optimized' ),
				$url
			);
		}

		return new WP_Error(
			'blt_optimized_loopback_failed',
			$text . ' ' . sprintf(
				/* translators: %s: technical error message. */
				__( '(Technical detail: %s)', 'blt-optimized' ),
				$message
			),
			array(
				'url'    => $url,
				'reason' => $reason,
			)
		);
	}

	/**
	 * HTTP 401: the site is password protected.
	 *
	 * @param string $url       URL.
	 * @param bool   $forwarded Whether admin credentials were forwarded.
	 * @return WP_Error
	 */
	private function auth_error( $url, $forwarded ) {
		$text = sprintf(
			/* translators: %s: URL. */
			__( '%s answered HTTP 401 (authentication required). The site is probably password protected (a staging password, HTTP basic auth set up by the host, or a maintenance/coming-soon gate) and the scan reads pages from the server itself without logging in.', 'blt-optimized' ),
			$url
		);
		if ( $forwarded ) {
			$text .= ' ' . __( 'The credentials of this admin session were tried and rejected.', 'blt-optimized' );
		}
		$text .= ' ' . __( 'Temporarily lift the password for this server\'s own IP address (or for the audited pages), run the scan, then restore it.', 'blt-optimized' );
		return new WP_Error(
			'blt_optimized_loopback_auth',
			$text,
			array(
				'url'    => $url,
				'status' => 401,
			)
		);
	}

	/**
	 * HTTP 403/429 (or a CDN challenge): something is blocking the scan.
	 *
	 * @param string $url    URL.
	 * @param int    $status Status.
	 * @return WP_Error
	 */
	private function blocked_error( $url, $status ) {
		return new WP_Error(
			'blt_optimized_loopback_blocked',
			sprintf(
				/* translators: 1: URL, 2: HTTP status code. */
				__( '%1$s answered HTTP %2$d, so the page could not be read. A firewall (a security plugin, ModSecurity, or a CDN bot challenge) is most likely blocking or rate-limiting requests this server makes to itself. Allow this server\'s IP address, or the user agent "BLT-Optimized-Profiler", then run the scan again.', 'blt-optimized' ),
				$url,
				(int) $status
			),
			array(
				'url'    => $url,
				'status' => (int) $status,
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Page cache detection                                                */
	/* ------------------------------------------------------------------ */

	/**
	 * Fetch the page twice without the profile token and look for evidence
	 * that something serves it from a cache. The first request warms a page
	 * cache; the second is what a visitor gets, so its timing is the cached
	 * timing. A header-proven hit on the first request ends it early.
	 *
	 * @param array  $http    HTTP block so far.
	 * @param string $url     Final URL (no token).
	 * @param float  $started microtime( true ) when audit() began.
	 * @return array Updated HTTP block.
	 */
	private function detect_cache( array $http, $url, $started ) {
		$plain = $this->fetch( $url, array( 'timeout' => self::TIMEOUT_PLAIN ) );
		if ( is_wp_error( $plain ) || $plain['status'] >= 400 ) {
			return $http;
		}

		$use      = $plain;
		$evidence = $this->cache_header_evidence( $plain['headers'] );
		if ( '' === $evidence['hit'] && ( microtime( true ) - $started ) < 60 ) {
			$second = $this->fetch( $url, array( 'timeout' => self::TIMEOUT_PLAIN ) );
			if ( ! is_wp_error( $second ) && $second['status'] < 400 ) {
				$use      = $second;
				$evidence = $this->cache_header_evidence( $second['headers'] );
			}
		}

		$found = $evidence['hit'];
		if ( '' === $found ) {
			$found = $this->cache_comment_evidence( $use['body'] );
		}

		$http['page_cache'] = array(
			'detected' => '' !== $found,
			'evidence' => '' !== $found ? $found : $evidence['hint'],
		);
		$http['cached_ms']      = $use['total_ms'];
		$http['cached_ttfb_ms'] = $use['ttfb_ms'];
		return $http;
	}

	/**
	 * Evidence of a cache hit in response headers.
	 *
	 * @param array<string,string> $headers Headers (lowercased).
	 * @return array { hit: string, hint: string } hit is '' when not proven;
	 *               hint names a cache layer that was seen but did not hit.
	 */
	private function cache_header_evidence( array $headers ) {
		$hint = '';

		foreach ( $headers as $name => $value ) {
			$shown = $name . ': ' . substr( $value, 0, 80 );

			// Standard "<layer>: HIT" style headers from CDNs, proxies and cache plugins.
			if ( preg_match( '/^(x-[a-z0-9-]*cache[a-z0-9-]*|cf-cache-status|cdn-cache-status|x-qc-cache)$/', $name ) && ! in_array( $name, array( 'x-cache-enabled', 'x-cache-hits' ), true ) ) {
				if ( preg_match( '/\b(hit|stale|updating|revalidated)\b/i', $value ) ) {
					return array(
						'hit'  => $shown,
						'hint' => '',
					);
				}
				if ( '' === $hint ) {
					$hint = sprintf(
						/* translators: %s: header name and value. */
						__( '%s (cache layer present, but this request was not served from cache)', 'blt-optimized' ),
						$shown
					);
				}
			}
		}

		// Fastly: x-cache-hits above zero.
		if ( isset( $headers['x-cache-hits'] ) && preg_match( '/[1-9]/', $headers['x-cache-hits'] ) ) {
			return array(
				'hit'  => 'x-cache-hits: ' . substr( $headers['x-cache-hits'], 0, 40 ),
				'hint' => '',
			);
		}

		// Varnish: two transaction IDs mean "answered from cache".
		if ( isset( $headers['x-varnish'] ) ) {
			if ( preg_match( '/^\s*\d+\s+\d+/', $headers['x-varnish'] ) ) {
				return array(
					'hit'  => 'x-varnish: ' . substr( $headers['x-varnish'], 0, 40 ),
					'hint' => '',
				);
			}
			if ( '' === $hint ) {
				$hint = __( 'x-varnish present, but this request was not served from cache', 'blt-optimized' );
			}
		}

		// SiteGround Optimizer.
		if ( isset( $headers['x-cache-enabled'] ) && 0 === stripos( trim( $headers['x-cache-enabled'] ), 'true' ) ) {
			return array(
				'hit'  => 'x-cache-enabled: ' . trim( $headers['x-cache-enabled'] ),
				'hint' => '',
			);
		}

		// Age header: a shared cache held it for a while.
		if ( isset( $headers['age'] ) && (int) $headers['age'] > 0 ) {
			return array(
				'hit'  => 'age: ' . (int) $headers['age'],
				'hint' => '',
			);
		}

		// WP Super Cache / WP Rocket headers.
		foreach ( $headers as $name => $value ) {
			if ( ( 'x-wp-super-cache' === $name || 0 === strpos( $name, 'x-rocket' ) ) && false === stripos( $name, 'bypass' ) && ! preg_match( '/\b(miss|bypass)\b/i', $value ) ) {
				return array(
					'hit'  => $name . ': ' . substr( $value, 0, 80 ),
					'hint' => '',
				);
			}
		}
		if ( isset( $headers['x-powered-by'] ) && false !== stripos( $headers['x-powered-by'], 'wp rocket' ) ) {
			return array(
				'hit'  => 'x-powered-by: ' . substr( $headers['x-powered-by'], 0, 80 ),
				'hint' => '',
			);
		}

		return array(
			'hit'  => '',
			'hint' => $hint,
		);
	}

	/**
	 * Evidence of a page-cache plugin in the HTML comments page-cache plugins
	 * append to the head or tail of cached pages.
	 *
	 * @param string $html HTML of the second plain request.
	 * @return string Evidence, or ''.
	 */
	private function cache_comment_evidence( $html ) {
		$html = (string) $html;
		if ( '' === $html ) {
			return '';
		}
		$comments = '';
		foreach ( array( substr( $html, 0, 2000 ), substr( $html, -8000 ) ) as $chunk ) {
			if ( preg_match_all( '/<!--(.{0,800}?)-->/s', $chunk, $matches ) ) {
				$comments .= ' ' . implode( ' ', $matches[1] );
			}
		}
		if ( '' === trim( $comments ) ) {
			return '';
		}

		$signatures = array(
			'WP Rocket'        => '/performance optimized by wp rocket|like a rocket, isn.?t it|wp[ -]rocket/i',
			'WP Super Cache'   => '/served from supercache|cached page generated by wp-super-cache/i',
			'W3 Total Cache'   => '/page caching using/i',
			'LiteSpeed Cache'  => '/page cached by litespeed cache|litespeed cache created with/i',
			'WP Fastest Cache' => '/wp fastest cache file was created in/i',
			'Hummingbird'      => '/hummingbird (page )?cache|cached by hummingbird|hummingbird cache file was created/i',
			'SG Optimizer'     => '/sg (optimizer|cache)|siteground optimizer/i',
			'Cache Enabler'    => '/cache enabler by keycdn/i',
			'Breeze'           => '/cache served by breeze/i',
			'WP-Optimize'      => '/cached by wp-optimize/i',
			'Comet Cache'      => '/comet cache|cache file was built/i',
			'Hyper Cache'      => '/hyper cache/i',
			'Cachify'          => '/cachify/i',
		);
		foreach ( $signatures as $label => $pattern ) {
			if ( ! preg_match( $pattern, $comments ) ) {
				continue;
			}
			// W3 Total Cache also comments when a page was refused caching.
			if ( 'W3 Total Cache' === $label && preg_match( '/rejected|not cacheable|disabled|user is logged in/i', $comments ) ) {
				continue;
			}
			return sprintf(
				/* translators: %s: caching plugin name. */
				__( 'HTML comment from %s', 'blt-optimized' ),
				$label
			);
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Post attribution                                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * The queried post, when the page is a singular view.
	 *
	 * @param array|null $profile Profile result (Module A).
	 * @param string     $url     Final URL, used when there is no profile.
	 * @return array|null { id, title, edit_url, type }
	 */
	private function resolve_post( $profile, $url ) {
		$post_id = 0;
		if ( is_array( $profile ) && isset( $profile['context'] ) && is_array( $profile['context'] ) ) {
			$context  = $profile['context'];
			$singular = ( isset( $context['type'] ) && 'singular' === $context['type'] )
				|| ! empty( $context['conditionals']['is_singular'] );
			if ( $singular && ! empty( $context['object_id'] ) ) {
				$post_id = (int) $context['object_id'];
			}
		} else {
			$post_id = (int) url_to_postid( $url );
		}
		if ( $post_id <= 0 ) {
			return null;
		}

		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return null;
		}

		$title    = trim( wp_strip_all_tags( $post->post_title ) );
		$edit_url = get_edit_post_link( $post_id, 'raw' );
		if ( ! is_string( $edit_url ) || '' === $edit_url ) {
			$edit_url = admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		}

		return array(
			'id'       => $post_id,
			'title'    => '' !== $title ? $title : sprintf( '#%d', $post_id ),
			'edit_url' => $edit_url,
			'type'     => $post->post_type,
		);
	}

	/**
	 * Text a post's images and embeds can be searched in: its content plus
	 * Elementor data (builders keep content outside post_content).
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	private function post_haystack( $post_id ) {
		$post = get_post( $post_id );
		if ( ! ( $post instanceof WP_Post ) ) {
			return '';
		}
		$text = (string) $post->post_content;
		$data = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_string( $data ) && '' !== $data ) {
			$text .= ' ' . str_replace( '\\/', '/', $data );
		}

		/**
		 * Filter the text used to decide whether an image or embed on a page is
		 * part of the queried post's own content.
		 *
		 * @param string $text    Haystack.
		 * @param int    $post_id Post ID.
		 */
		return (string) apply_filters( 'blt_optimized_audit_post_haystack', $text, $post_id );
	}

	/* ------------------------------------------------------------------ */
	/* HTML analysis                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * An html block with nothing found (unparseable, empty or skipped pages).
	 *
	 * @param int $bytes HTML size.
	 * @return array
	 */
	private function empty_html_report( $bytes ) {
		return array(
			'bytes'          => (int) $bytes,
			'dom_nodes'      => 0,
			'max_depth'      => 0,
			'images'         => array(),
			'iframes'        => array(),
			'scripts'        => array(),
			'inline_scripts' => array(
				'count' => 0,
				'bytes' => 0,
			),
			'stylesheets'    => array(),
			'inline_styles'  => array(
				'count'   => 0,
				'bytes'   => 0,
				'largest' => array(
					'id'    => '',
					'bytes' => 0,
				),
			),
			'third_party'    => array(),
			'hints'          => array(
				'preconnect'   => array(),
				'dns_prefetch' => array(),
				'preload'      => array(),
			),
			'fonts'          => array( 'google_fonts' => false ),
			'class_tokens'   => array(),
			'id_tokens'      => array(),
		);
	}

	/**
	 * Parse the HTML and extract everything the report needs.
	 *
	 * @param string $html     Raw HTML (not retained).
	 * @param string $page_url Final page URL (base for relative URLs).
	 * @param string $haystack Queried post's content ('' when none).
	 * @param string $charset  Declared charset ('' when unknown).
	 * @return array html block.
	 */
	private function analyze_html( $html, $page_url, $haystack, $charset ) {
		$report = $this->empty_html_report( strlen( $html ) );
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) || ! class_exists( 'DOMXPath' ) ) {
			return $report;
		}

		// libxml needs roughly 25x the markup in memory; on a tight memory limit
		// parse only the part that fits rather than risk a fatal error.
		$parse_limit = self::MAX_PARSE_BYTES;
		$memory      = (int) wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $memory > 0 ) {
			$parse_limit = (int) min( $parse_limit, ( $memory - memory_get_usage( true ) ) / 25 );
		}
		if ( $parse_limit < 20000 ) {
			return $report;
		}
		if ( strlen( $html ) > $parse_limit ) {
			$html = substr( $html, 0, $parse_limit );
		}
		if ( '' !== $charset && ! in_array( $charset, array( 'utf-8', 'utf8' ), true ) && function_exists( 'mb_convert_encoding' ) ) {
			try {
				$html = (string) mb_convert_encoding( $html, 'UTF-8', $charset );
			} catch ( \Throwable $e ) {
				unset( $e ); // Unknown charset: parse as sent.
			}
		}

		$previous = libxml_use_internal_errors( true );
		$doc      = new DOMDocument();
		$loaded   = false;
		try {
			$loaded = $doc->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT );
		} catch ( \Throwable $e ) {
			$loaded = false;
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		unset( $html );

		if ( ! $loaded || ! $doc->documentElement ) {
			return $report;
		}

		$xpath = new DOMXPath( $doc );

		$base      = $page_url;
		$base_node = $xpath->query( '//base[@href]' );
		if ( $base_node && $base_node->length ) {
			$resolved = $this->resolve_url( trim( $base_node->item( 0 )->getAttribute( 'href' ) ), $page_url );
			if ( '' !== $resolved ) {
				$base = $resolved;
			}
		}

		$third = array(); // host => { requests, kinds[] }.

		$report['dom_nodes'] = (int) $xpath->evaluate( 'count(//*)' );
		$report['max_depth'] = $this->max_depth( $doc );

		$report['images']  = $this->collect_images( $xpath, $base, $haystack, $third );
		$report['iframes'] = $this->collect_embeds( $xpath, $base, $haystack, $third );

		$scripts                  = $this->collect_scripts( $xpath, $base, $third );
		$report['scripts']        = $scripts['external'];
		$report['inline_scripts'] = $scripts['inline'];

		$links                   = $this->collect_links( $xpath, $base, $third );
		$report['stylesheets']   = $links['stylesheets'];
		$report['hints']         = $links['hints'];
		$report['inline_styles'] = $this->collect_inline_styles( $xpath, $third );

		// Third-party origins.
		$third_party = array();
		foreach ( $third as $host => $data ) {
			$kinds = array_keys( $data['kinds'] );
			sort( $kinds );
			$third_party[] = array(
				'host'     => $host,
				'requests' => $data['requests'],
				'kinds'    => $kinds,
				'known'    => $this->known_service( $host, $data['path'] ),
			);
		}
		usort(
			$third_party,
			static function ( $a, $b ) {
				return ( $b['requests'] <=> $a['requests'] ) ?: strcmp( $a['host'], $b['host'] );
			}
		);
		$report['third_party'] = array_slice( $third_party, 0, self::MAX_THIRD );

		foreach ( $third_party as $entry ) {
			if ( 'Google Fonts' === $entry['known'] ) {
				$report['fonts']['google_fonts'] = true;
				break;
			}
		}

		$tokens                 = $this->collect_tokens( $xpath );
		$report['class_tokens'] = $tokens['classes'];
		$report['id_tokens']    = $tokens['ids'];

		unset( $xpath, $doc );
		return $report;
	}

	/**
	 * Longest element nesting, by an iterative walk (no recursion: broken
	 * markup can nest thousands deep).
	 *
	 * @param DOMDocument $doc Document.
	 * @return int
	 */
	private function max_depth( DOMDocument $doc ) {
		$root = $doc->documentElement;
		if ( ! $root ) {
			return 0;
		}
		$node    = $root;
		$depth   = 1;
		$max     = 1;
		$visited = 0;
		while ( true ) {
			if ( ++$visited > 200000 ) {
				break;
			}
			$child = $node->firstElementChild;
			if ( $child ) {
				$node = $child;
				++$depth;
				if ( $depth > $max ) {
					$max = $depth;
				}
				continue;
			}
			while ( true ) {
				if ( $node === $root ) {
					break 2;
				}
				$sibling = $node->nextElementSibling;
				if ( $sibling ) {
					$node = $sibling;
					continue 2;
				}
				$node = $node->parentNode;
				--$depth;
				if ( ! $node instanceof DOMElement ) {
					break 2;
				}
			}
		}
		return $max;
	}

	/**
	 * <img> elements, with real file sizes for local files.
	 *
	 * @param DOMXPath $xpath    XPath.
	 * @param string   $base     Base URL.
	 * @param string   $haystack Queried post's content.
	 * @param array    $third    Third-party accumulator.
	 * @return array[]
	 */
	private function collect_images( DOMXPath $xpath, $base, $haystack, array &$third ) {
		$images = array();
		$nodes  = $xpath->query( '//img[not(ancestor::noscript) and not(ancestor::template)]' );
		if ( ! $nodes ) {
			return $images;
		}

		$scanned = 0;
		foreach ( $nodes as $img ) {
			if ( ++$scanned > 1500 || ! $img instanceof DOMElement ) {
				break;
			}

			$source = $this->image_source( $img );
			if ( '' === $source['src'] ) {
				continue;
			}

			$is_data = 0 === stripos( $source['src'], 'data:' );
			$abs     = $is_data ? $source['src'] : $this->resolve_url( $source['src'], $base );
			if ( '' === $abs ) {
				continue;
			}

			$host = $is_data ? '' : (string) wp_parse_url( $abs, PHP_URL_HOST );
			if ( ! $is_data ) {
				$this->note_third_party( $third, $host, (string) wp_parse_url( $abs, PHP_URL_PATH ), 'image' );
			}
			if ( count( $images ) >= self::MAX_IMAGES ) {
				continue; // Keep counting third parties, stop building entries.
			}

			$path    = '';
			$bytes   = 0;
			$natural = array( 0, 0 );
			$format  = 'other';

			if ( $is_data ) {
				$format = $this->data_uri_format( $abs );
				$bytes  = $this->data_uri_bytes( $abs );
				$abs    = substr( $abs, 0, 48 ) . '…';
			} else {
				$format = $this->format_from_url( $abs );
				$path   = $this->local_image_path( $abs, $host );
				if ( '' !== $path ) {
					$size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts warn.
					$bytes = false === $size ? 0 : (int) $size;
					if ( 'svg' !== $format && $bytes > 0 ) {
						$dims = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $path ) : @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						if ( is_array( $dims ) && ! empty( $dims[0] ) && ! empty( $dims[1] ) ) {
							$natural = array( (int) $dims[0], (int) $dims[1] );
						}
					}
				}
			}

			// srcset / <picture><source>.
			$has_srcset     = '' !== $source['srcset'];
			$picture_modern = false;
			$picture        = $this->picture_of( $img );
			if ( $picture ) {
				foreach ( $picture->getElementsByTagName( 'source' ) as $child ) {
					$child_set = trim( $child->getAttribute( 'srcset' ) );
					if ( '' === $child_set ) {
						$child_set = trim( $child->getAttribute( 'data-srcset' ) );
					}
					if ( '' === $child_set ) {
						continue;
					}
					$has_srcset = true;
					$type       = strtolower( trim( $child->getAttribute( 'type' ) ) );
					if ( 'image/webp' === $type || 'image/avif' === $type || preg_match( '/\.(webp|avif)(\s|$|\?|,)/i', $child_set ) ) {
						$picture_modern = true;
					}
				}
			}

			$loading = strtolower( trim( $img->getAttribute( 'loading' ) ) );
			if ( '' === $loading && $source['lazy_js'] ) {
				$loading = 'lazy'; // A lazy-load plugin: not in the first paint either.
			}

			$images[] = array(
				'src'            => $this->cap( $abs, 1000 ),
				'local'          => '' !== $path || ( ! $is_data && $this->is_local( $host ) ),
				'bytes'          => $bytes,
				'natural_w'      => $natural[0],
				'natural_h'      => $natural[1],
				'attr_w'         => $this->dimension_attr( $img->getAttribute( 'width' ) ),
				'attr_h'         => $this->dimension_attr( $img->getAttribute( 'height' ) ),
				'has_srcset'     => $has_srcset,
				'loading'        => $loading,
				'fetchpriority'  => strtolower( trim( $img->getAttribute( 'fetchpriority' ) ) ),
				'format'         => $format,
				'index'          => count( $images ),
				'in_content'     => ! $is_data && $this->stem_in_text( $abs, $haystack ),
				'picture_modern' => $picture_modern,
			);
		}

		return $images;
	}

	/**
	 * The URL an <img> really loads: lazy-load plugins keep a placeholder (or a
	 * data: URI) in src and the real URL in data-src / data-lazy-src.
	 *
	 * @param DOMElement $img Element.
	 * @return array { src: string, srcset: string, lazy_js: bool }
	 */
	private function image_source( DOMElement $img ) {
		$src    = trim( $img->getAttribute( 'src' ) );
		$srcset = trim( $img->getAttribute( 'srcset' ) );
		if ( '' === $srcset ) {
			$srcset = trim( $img->getAttribute( 'data-srcset' ) );
		}
		if ( '' === $srcset ) {
			$srcset = trim( $img->getAttribute( 'data-lazy-srcset' ) );
		}

		$lazy_src = '';
		foreach ( array( 'data-src', 'data-lazy-src', 'data-original', 'data-orig-src' ) as $attr ) {
			$value = trim( $img->getAttribute( $attr ) );
			if ( '' !== $value ) {
				$lazy_src = $value;
				break;
			}
		}

		$lazy_js = false;
		if ( '' !== $lazy_src && $lazy_src !== $src ) {
			$placeholder = '' === $src
				|| 0 === stripos( $src, 'data:' )
				|| preg_match( '/(placeholder|blank|spacer|transparent|1x1|pixel|lazy|loading)[^\/]*$/i', (string) wp_parse_url( $src, PHP_URL_PATH ) )
				|| false !== stripos( ' ' . $img->getAttribute( 'class' ) . ' ', 'lazy' );
			if ( $placeholder ) {
				$src     = $lazy_src;
				$lazy_js = true;
			}
		}

		if ( '' === $src && '' !== $srcset && preg_match( '/^\s*([^\s,]+)/', $srcset, $m ) ) {
			$src = $m[1];
		}

		return array(
			'src'     => $src,
			'srcset'  => $srcset,
			'lazy_js' => $lazy_js,
		);
	}

	/**
	 * Local file behind an image URL. Images served through a CDN host are
	 * still mapped to this site's uploads folder when the same relative path
	 * exists there (CDN rewrites keep the /wp-content/uploads/ path).
	 *
	 * @param string $url  Absolute URL.
	 * @param string $host Host of the URL.
	 * @return string Absolute path or ''.
	 */
	private function local_image_path( $url, $host ) {
		if ( $this->is_local( $host ) ) {
			return BLT_Optimized_Components::url_to_path( $url );
		}
		$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$pos  = strpos( $path, '/uploads/' );
		if ( false === $pos || false !== strpos( $path, '..' ) ) {
			return '';
		}
		$uploads = wp_get_upload_dir();
		if ( empty( $uploads['basedir'] ) ) {
			return '';
		}
		$candidate = wp_normalize_path( trailingslashit( $uploads['basedir'] ) . ltrim( substr( $path, $pos + strlen( '/uploads/' ) ), '/' ) );
		return @is_file( $candidate ) ? $candidate : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- open_basedir hosts warn.
	}

	/**
	 * iframes, <embed> and <object data>: heavy embeds.
	 *
	 * @param DOMXPath $xpath    XPath.
	 * @param string   $base     Base URL.
	 * @param string   $haystack Queried post's content.
	 * @param array    $third    Third-party accumulator.
	 * @return array[]
	 */
	private function collect_embeds( DOMXPath $xpath, $base, $haystack, array &$third ) {
		$embeds = array();
		$nodes  = $xpath->query( '//iframe[not(ancestor::noscript) and not(ancestor::template)] | //embed[not(ancestor::noscript)] | //object[@data and not(ancestor::noscript)]' );
		if ( ! $nodes ) {
			return $embeds;
		}

		$scanned = 0;
		foreach ( $nodes as $node ) {
			if ( ++$scanned > 500 || ! $node instanceof DOMElement ) {
				break;
			}
			$tag     = strtolower( $node->nodeName );
			$src     = trim( $node->getAttribute( 'object' === $tag ? 'data' : 'src' ) );
			$lazy_js = false;
			if ( 'iframe' === $tag ) {
				$lazy = '';
				foreach ( array( 'data-src', 'data-lazy-src', 'data-lazy' ) as $attr ) {
					$lazy = trim( $node->getAttribute( $attr ) );
					if ( '' !== $lazy ) {
						break;
					}
				}
				if ( '' !== $lazy && ( '' === $src || 0 === stripos( $src, 'about:' ) || 0 === stripos( $src, 'data:' ) ) ) {
					$src     = $lazy;
					$lazy_js = true;
				}
			}
			if ( '' === $src ) {
				continue;
			}
			$abs = $this->resolve_url( $src, $base );
			if ( '' === $abs ) {
				continue;
			}

			$host = (string) wp_parse_url( $abs, PHP_URL_HOST );
			$path = (string) wp_parse_url( $abs, PHP_URL_PATH );
			$this->note_third_party( $third, $host, $path, 'iframe' );
			if ( count( $embeds ) >= self::MAX_IFRAMES ) {
				continue;
			}

			$loading = strtolower( trim( $node->getAttribute( 'loading' ) ) );
			if ( '' === $loading && $lazy_js ) {
				$loading = 'lazy';
			}

			$kind     = $this->embed_kind( $host, $path );
			$embeds[] = array(
				'src'        => $this->cap( $abs, 1000 ),
				'host'       => strtolower( $host ),
				'loading'    => $loading,
				'kind'       => $kind,
				'in_content' => $this->embed_in_text( $abs, $kind, $haystack ),
			);
		}
		return $embeds;
	}

	/**
	 * External scripts, plus inline script weight (and the third-party
	 * services inline loaders pull in).
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $base  Base URL.
	 * @param array    $third Third-party accumulator.
	 * @return array { external: array[], inline: { count, bytes } }
	 */
	private function collect_scripts( DOMXPath $xpath, $base, array &$third ) {
		$external = array();
		$count    = 0;
		$bytes    = 0;

		$nodes = $xpath->query( '//script[@src][not(ancestor::noscript) and not(ancestor::template)]' );
		if ( $nodes ) {
			$scanned = 0;
			foreach ( $nodes as $node ) {
				if ( ++$scanned > 1000 || ! $node instanceof DOMElement ) {
					break;
				}
				$abs = $this->resolve_url( trim( $node->getAttribute( 'src' ) ), $base );
				if ( '' === $abs ) {
					continue;
				}
				$host = (string) wp_parse_url( $abs, PHP_URL_HOST );
				$this->note_third_party( $third, $host, (string) wp_parse_url( $abs, PHP_URL_PATH ), 'script' );
				if ( count( $external ) >= self::MAX_SCRIPTS ) {
					continue;
				}
				$external[] = array(
					'src'     => $this->cap( $abs, 1000 ),
					'host'    => strtolower( $host ),
					'in_head' => $this->in_head( $node ),
					'async'   => $node->hasAttribute( 'async' ),
					'defer'   => $node->hasAttribute( 'defer' ),
					'module'  => 'module' === strtolower( trim( $node->getAttribute( 'type' ) ) ),
					'id'      => $this->cap( $node->getAttribute( 'id' ), 200 ),
				);
			}
		}

		$nodes = $xpath->query( '//script[not(@src)][not(ancestor::noscript) and not(ancestor::template)]' );
		if ( $nodes ) {
			$scanned = 0;
			foreach ( $nodes as $node ) {
				if ( ++$scanned > 2000 || ! $node instanceof DOMElement ) {
					break;
				}
				$type = strtolower( trim( $node->getAttribute( 'type' ) ) );
				if ( '' !== $type && ! $this->is_js_type( $type ) ) {
					continue; // JSON-LD, templates, import maps: data, not script.
				}
				$text = $node->textContent;
				$len  = strlen( $text );
				if ( 0 === $len || '' === trim( $text ) ) {
					continue;
				}
				++$count;
				$bytes += $len;
				$this->scan_inline_for_services( $third, $text );
			}
		}

		return array(
			'external' => $external,
			'inline'   => array(
				'count' => $count,
				'bytes' => $bytes,
			),
		);
	}

	/**
	 * <link> elements: stylesheets and resource hints.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param string   $base  Base URL.
	 * @param array    $third Third-party accumulator.
	 * @return array { stylesheets: array[], hints: array }
	 */
	private function collect_links( DOMXPath $xpath, $base, array &$third ) {
		$styles = array();
		$hints  = array(
			'preconnect'   => array(),
			'dns_prefetch' => array(),
			'preload'      => array(),
		);

		$nodes = $xpath->query( '//link[@rel and @href][not(ancestor::noscript) and not(ancestor::template)]' );
		if ( ! $nodes ) {
			return array(
				'stylesheets' => $styles,
				'hints'       => $hints,
			);
		}

		$scanned = 0;
		foreach ( $nodes as $node ) {
			if ( ++$scanned > 1000 || ! $node instanceof DOMElement ) {
				break;
			}
			$rels = preg_split( '/\s+/', strtolower( trim( $node->getAttribute( 'rel' ) ) ), -1, PREG_SPLIT_NO_EMPTY );
			$href = trim( $node->getAttribute( 'href' ) );
			if ( empty( $rels ) || '' === $href || 0 === stripos( $href, 'data:' ) ) {
				continue;
			}
			$abs = $this->resolve_url( $href, $base );
			if ( '' === $abs ) {
				continue;
			}
			$host = (string) wp_parse_url( $abs, PHP_URL_HOST );
			$path = (string) wp_parse_url( $abs, PHP_URL_PATH );

			if ( in_array( 'stylesheet', $rels, true ) && ! in_array( 'alternate', $rels, true ) ) {
				$this->note_third_party( $third, $host, $path, 'style' );
				if ( count( $styles ) < self::MAX_STYLES ) {
					$media    = strtolower( trim( $node->getAttribute( 'media' ) ) );
					$styles[] = array(
						'href'    => $this->cap( $abs, 1000 ),
						'host'    => strtolower( $host ),
						'in_head' => $this->in_head( $node ),
						'media'   => '' !== $media ? $media : 'all',
						'id'      => $this->cap( $node->getAttribute( 'id' ), 200 ),
					);
				}
			} elseif ( in_array( 'preconnect', $rels, true ) ) {
				$this->push_hint( $hints['preconnect'], $abs );
			} elseif ( in_array( 'dns-prefetch', $rels, true ) ) {
				$this->push_hint( $hints['dns_prefetch'], $abs );
			} elseif ( in_array( 'preload', $rels, true ) || in_array( 'modulepreload', $rels, true ) ) {
				$as = strtolower( trim( $node->getAttribute( 'as' ) ) );
				if ( '' === $as && in_array( 'modulepreload', $rels, true ) ) {
					$as = 'script';
				}
				if ( count( $hints['preload'] ) < self::MAX_HINTS ) {
					$hints['preload'][] = array(
						'href' => $this->cap( $abs, 1000 ),
						'as'   => $as,
					);
				}
				if ( 'font' === $as ) {
					$this->note_third_party( $third, $host, $path, 'font' );
				}
			}
		}

		return array(
			'stylesheets' => $styles,
			'hints'       => $hints,
		);
	}

	/**
	 * Inline <style> weight.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @param array    $third Third-party accumulator.
	 * @return array { count, bytes, largest: { id, bytes } }
	 */
	private function collect_inline_styles( DOMXPath $xpath, array &$third ) {
		$out   = array(
			'count'   => 0,
			'bytes'   => 0,
			'largest' => array(
				'id'    => '',
				'bytes' => 0,
			),
		);
		$nodes = $xpath->query( '//style[not(ancestor::noscript) and not(ancestor::template)]' );
		if ( ! $nodes ) {
			return $out;
		}
		$scanned = 0;
		foreach ( $nodes as $node ) {
			if ( ++$scanned > 2000 || ! $node instanceof DOMElement ) {
				break;
			}
			$text = $node->textContent;
			$len  = strlen( $text );
			if ( 0 === $len || '' === trim( $text ) ) {
				continue;
			}
			++$out['count'];
			$out['bytes'] += $len;
			if ( $len > $out['largest']['bytes'] ) {
				$out['largest'] = array(
					'id'    => $this->cap( $node->getAttribute( 'id' ), 200 ),
					'bytes' => $len,
				);
			}
			$this->scan_inline_for_services( $third, $text );
		}
		return $out;
	}

	/**
	 * Unique class names and ids used inside <body> (the body element itself
	 * included), ignoring anything inside <noscript> and <template>. Used by
	 * the asset analysis to prove a plugin's markup is (not) on the page.
	 *
	 * @param DOMXPath $xpath XPath.
	 * @return array { classes: string[], ids: string[] }
	 */
	private function collect_tokens( DOMXPath $xpath ) {
		$classes = array();
		$ids     = array();
		$skip    = '[not(ancestor-or-self::noscript or ancestor-or-self::template or ancestor-or-self::script or ancestor-or-self::style)]';

		$nodes = $xpath->query( '//body/descendant-or-self::*[@class]' . $skip . '/@class' );
		if ( $nodes ) {
			foreach ( $nodes as $attr ) {
				foreach ( preg_split( '/\s+/', trim( (string) $attr->nodeValue ), -1, PREG_SPLIT_NO_EMPTY ) as $token ) {
					if ( strlen( $token ) <= 120 && ! isset( $classes[ $token ] ) && count( $classes ) < self::MAX_CLASS_TOKENS ) {
						$classes[ $token ] = true;
					}
				}
				if ( count( $classes ) >= self::MAX_CLASS_TOKENS ) {
					break;
				}
			}
		}

		$nodes = $xpath->query( '//body/descendant-or-self::*[@id]' . $skip . '/@id' );
		if ( $nodes ) {
			foreach ( $nodes as $attr ) {
				$id = trim( (string) $attr->nodeValue );
				if ( '' !== $id && strlen( $id ) <= 120 && ! isset( $ids[ $id ] ) ) {
					$ids[ $id ] = true;
					if ( count( $ids ) >= self::MAX_ID_TOKENS ) {
						break;
					}
				}
			}
		}

		$class_list = array_map( 'strval', array_keys( $classes ) );
		$id_list    = array_map( 'strval', array_keys( $ids ) );
		sort( $class_list, SORT_STRING );
		sort( $id_list, SORT_STRING );

		return array(
			'classes' => $class_list,
			'ids'     => $id_list,
		);
	}

	/* ------------------------------------------------------------------ */
	/* HTML helpers                                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Whether an element sits in <head> (as opposed to <body>).
	 *
	 * @param DOMNode $node Node.
	 * @return bool
	 */
	private function in_head( DOMNode $node ) {
		$parent = $node->parentNode;
		while ( $parent instanceof DOMElement ) {
			$name = strtolower( $parent->nodeName );
			if ( 'head' === $name ) {
				return true;
			}
			if ( 'body' === $name ) {
				return false;
			}
			$parent = $parent->parentNode;
		}
		return false;
	}

	/**
	 * The <picture> an <img> belongs to. libxml (before 2.14) does not know that
	 * <source> is a void element, so an <img> after it is parsed as the
	 * <source>'s child; walk up through such wrappers.
	 *
	 * @param DOMElement $img Image.
	 * @return DOMElement|null
	 */
	private function picture_of( DOMElement $img ) {
		$node = $img->parentNode;
		for ( $levels = 0; $node instanceof DOMElement && $levels < 6; $levels++ ) {
			$name = strtolower( $node->nodeName );
			if ( 'picture' === $name ) {
				return $node;
			}
			if ( 'source' !== $name ) {
				return null;
			}
			$node = $node->parentNode;
		}
		return null;
	}

	/**
	 * Whether an inline <script> type is executable JavaScript.
	 *
	 * @param string $type Lowercased type attribute.
	 * @return bool
	 */
	private function is_js_type( $type ) {
		return in_array(
			$type,
			array(
				'text/javascript',
				'application/javascript',
				'application/x-javascript',
				'text/ecmascript',
				'application/ecmascript',
				'module',
				'rocketlazyloadscript',
			),
			true
		);
	}

	/**
	 * Resolve a (possibly relative) URL against a base. Non-HTTP schemes
	 * (javascript:, mailto:, about:, blob:) resolve to ''.
	 *
	 * @param string $url  URL as written in the markup.
	 * @param string $base Absolute base URL.
	 * @return string Absolute URL or ''.
	 */
	private function resolve_url( $url, $base ) {
		$url = trim( (string) $url );
		if ( '' === $url || '#' === $url[0] ) {
			return '';
		}
		if ( preg_match( '#^([a-z][a-z0-9+.-]*):#i', $url, $m ) ) {
			return in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ? $url : '';
		}

		$b = wp_parse_url( (string) $base );
		if ( ! is_array( $b ) || empty( $b['host'] ) ) {
			return '';
		}
		$scheme = isset( $b['scheme'] ) ? $b['scheme'] : 'http';
		$origin = $scheme . '://' . $b['host'] . ( isset( $b['port'] ) ? ':' . $b['port'] : '' );

		if ( 0 === strpos( $url, '//' ) ) {
			return $scheme . ':' . $url;
		}
		if ( '/' === $url[0] ) {
			return $origin . $url;
		}
		$base_path = isset( $b['path'] ) ? $b['path'] : '/';
		if ( '?' === $url[0] ) {
			return $origin . $base_path . $url;
		}

		// Relative path: join with the base directory and drop dot segments.
		$suffix = '';
		$cut    = strcspn( $url, '?#' );
		if ( $cut < strlen( $url ) ) {
			$suffix = substr( $url, $cut );
			$url    = substr( $url, 0, $cut );
		}
		$dir      = substr( $base_path, 0, (int) strrpos( $base_path, '/' ) + 1 );
		$segments = array();
		foreach ( explode( '/', $dir . $url ) as $segment ) {
			if ( '..' === $segment ) {
				array_pop( $segments );
			} elseif ( '.' !== $segment ) {
				$segments[] = $segment;
			}
		}
		$joined = implode( '/', $segments );
		return $origin . ( '/' === substr( $joined, 0, 1 ) ? '' : '/' ) . $joined . $suffix;
	}

	/**
	 * Numeric width/height attribute ("300", "300px") or null (percentages,
	 * "auto", empty).
	 *
	 * @param string $value Attribute value.
	 * @return int|null
	 */
	private function dimension_attr( $value ) {
		$value = trim( (string) $value );
		if ( preg_match( '/^(\d{1,5})(?:\.\d+)?(?:px)?$/i', $value, $m ) && (int) $m[1] > 0 ) {
			return (int) $m[1];
		}
		return null;
	}

	/**
	 * Image format from the URL's extension.
	 *
	 * @param string $url URL.
	 * @return string jpg|png|gif|webp|avif|svg|other
	 */
	private function format_from_url( $url ) {
		$ext = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		switch ( $ext ) {
			case 'jpg':
			case 'jpeg':
			case 'jpe':
				return 'jpg';
			case 'png':
			case 'gif':
			case 'webp':
			case 'avif':
			case 'svg':
				return $ext;
		}
		return 'other';
	}

	/**
	 * Image format of a data: URI.
	 *
	 * @param string $uri data: URI.
	 * @return string
	 */
	private function data_uri_format( $uri ) {
		if ( preg_match( '#^data:image/([a-z0-9.+-]+)#i', $uri, $m ) ) {
			$type = strtolower( $m[1] );
			if ( 'jpeg' === $type ) {
				return 'jpg';
			}
			if ( 'svg+xml' === $type ) {
				return 'svg';
			}
			if ( in_array( $type, array( 'png', 'gif', 'webp', 'avif' ), true ) ) {
				return $type;
			}
		}
		return 'other';
	}

	/**
	 * Decoded size of a data: URI.
	 *
	 * @param string $uri data: URI.
	 * @return int
	 */
	private function data_uri_bytes( $uri ) {
		$comma = strpos( $uri, ',' );
		if ( false === $comma ) {
			return 0;
		}
		$payload = strlen( $uri ) - $comma - 1;
		return ( false !== stripos( substr( $uri, 0, $comma ), ';base64' ) ) ? (int) floor( $payload * 3 / 4 ) : $payload;
	}

	/**
	 * Shorten a string.
	 *
	 * @param string $value Value.
	 * @param int    $max   Max length.
	 * @return string
	 */
	private function cap( $value, $max ) {
		$value = (string) $value;
		return strlen( $value ) > $max ? substr( $value, 0, $max ) : $value;
	}

	/**
	 * Image file name without the WordPress-added suffixes: extension(s),
	 * "-WIDTHxHEIGHT", "-scaled" and "-e<timestamp>" (edited copies).
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private function filename_stem( $url ) {
		$base = rawurldecode( (string) wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
		$stem = preg_replace( '/\.[A-Za-z0-9]{2,5}$/', '', $base );
		$stem = preg_replace( '/\.(?:jpe?g|png|gif)$/i', '', (string) $stem ); // Sidecars: photo.jpg.webp.
		$stem = preg_replace( '/-\d{1,5}x\d{1,5}$/', '', (string) $stem );
		$stem = preg_replace( '/-scaled$/', '', (string) $stem );
		$stem = preg_replace( '/-e\d{10,}$/', '', (string) $stem );
		return (string) $stem;
	}

	/**
	 * Whether an image's file name stem appears in the post's content.
	 *
	 * @param string $url      Image URL.
	 * @param string $haystack Post content.
	 * @return bool
	 */
	private function stem_in_text( $url, $haystack ) {
		if ( '' === $haystack ) {
			return false;
		}
		$stem = $this->filename_stem( $url );
		return strlen( $stem ) >= 3 && false !== stripos( $haystack, $stem );
	}

	/**
	 * Whether an embed (by URL, or by video ID for YouTube/Vimeo) is part of the
	 * post's content.
	 *
	 * @param string $url      Embed URL.
	 * @param string $kind     youtube|vimeo|maps|other.
	 * @param string $haystack Post content.
	 * @return bool
	 */
	private function embed_in_text( $url, $kind, $haystack ) {
		if ( '' === $haystack ) {
			return false;
		}
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( 'youtube' === $kind || 'vimeo' === $kind ) {
			$id = '';
			if ( 'youtube' === $kind && preg_match( '/(?:^|&)v=([A-Za-z0-9_-]{6,})/', $query, $m ) ) {
				$id = $m[1];
			} else {
				$segment = trim( (string) wp_basename( $path ) );
				$id      = ( strlen( $segment ) >= 6 || ( 'vimeo' === $kind && ctype_digit( $segment ) && '' !== $segment ) ) ? $segment : '';
			}
			return '' !== $id && false !== strpos( $haystack, $id );
		}

		$needle = $host . $path;
		return strlen( $path ) > 1 && false !== stripos( $haystack, $needle );
	}

	/**
	 * Embed type from host and path.
	 *
	 * @param string $host Host.
	 * @param string $path Path.
	 * @return string youtube|vimeo|maps|other
	 */
	private function embed_kind( $host, $path ) {
		$host = strtolower( $host );
		if ( preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com|youtu\.be)$/', $host ) ) {
			return 'youtube';
		}
		if ( preg_match( '/(^|\.)vimeo\.com$/', $host ) ) {
			return 'vimeo';
		}
		if ( 0 === strpos( $host, 'maps.google.' )
			|| 'maps.googleapis.com' === $host
			|| preg_match( '/(^|\.)openstreetmap\.org$/', $host )
			|| ( preg_match( '/(^|\.)google\.[a-z.]+$/', $host ) && 0 === strpos( $path, '/maps' ) )
		) {
			return 'maps';
		}
		return 'other';
	}

	/**
	 * Append a unique resource-hint URL (capped).
	 *
	 * @param string[] $list List by reference.
	 * @param string   $url  URL.
	 * @return void
	 */
	private function push_hint( array &$list, $url ) {
		$url = $this->cap( $url, 500 );
		if ( count( $list ) < self::MAX_HINTS && ! in_array( $url, $list, true ) ) {
			$list[] = $url;
		}
	}

	/**
	 * The first asset that is served from this site's host.
	 *
	 * @param array[] $items Scripts or stylesheets.
	 * @param string  $key   'src' or 'href'.
	 * @return string URL or ''.
	 */
	private function first_local( array $items, $key ) {
		foreach ( $items as $item ) {
			if ( ! empty( $item[ $key ] ) && ! empty( $item['host'] ) && $this->is_local( $item['host'] ) ) {
				return (string) $item[ $key ];
			}
		}
		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Third parties                                                       */
	/* ------------------------------------------------------------------ */

	/**
	 * Count a request to a foreign host.
	 *
	 * @param array  $third Accumulator by reference: host => { requests, kinds, path }.
	 * @param string $host  Host.
	 * @param string $path  URL path (kept from the first request, for path-keyed services).
	 * @param string $kind  script|style|image|iframe|font|inline (inline loader code).
	 * @return void
	 */
	private function note_third_party( array &$third, $host, $path, $kind ) {
		$host = strtolower( (string) $host );
		if ( '' === $host || $this->is_local( $host ) ) {
			return;
		}
		if ( ! isset( $third[ $host ] ) ) {
			$third[ $host ] = array(
				'requests' => 0,
				'kinds'    => array(),
				'path'     => (string) $path,
			);
		}
		++$third[ $host ]['requests'];
		$third[ $host ]['kinds'][ $kind ] = true;
		// Path-keyed services (www.google.com/recaptcha) need the path that
		// proves the service; prefer a path that matches one.
		if ( '' === $third[ $host ]['path'] && '' !== $path ) {
			$third[ $host ]['path'] = (string) $path;
		}
	}

	/**
	 * Look inside inline JS/CSS for requests to known third-party services — a
	 * GTM or Facebook Pixel snippet is inline code that loads an external
	 * script, which no <script src> reveals.
	 *
	 * @param array  $third Accumulator by reference.
	 * @param string $text  Inline code.
	 * @return void
	 */
	private function scan_inline_for_services( array &$third, $text ) {
		// Loader snippets are small; large inline blobs are data (dependency
		// registries, settings JSON) whose URLs are never requested.
		if ( strlen( $text ) > 40000 ) {
			return;
		}
		$text = str_replace( '\\/', '/', $text );
		if ( false === strpos( $text, '//' ) ) {
			return;
		}
		if ( ! preg_match_all( '#(?:https?:)?//([a-z0-9][a-z0-9.-]*\.[a-z]{2,})(/[^\s\'"\\\\)<>]*)?#i', $text, $matches, PREG_SET_ORDER ) ) {
			return;
		}
		$seen = array();
		foreach ( array_slice( $matches, 0, 300 ) as $match ) {
			$host = strtolower( $match[1] );
			$path = isset( $match[2] ) ? $match[2] : '';
			if ( isset( $seen[ $host ] ) || $this->is_local( $host ) ) {
				continue;
			}
			// Only services that are routinely started from inline code; a bare
			// mention of a CDN or video URL in inline JSON is not a request.
			if ( ! in_array( $this->known_service( $host, $path ), $this->inline_loader_names(), true ) ) {
				continue;
			}
			$seen[ $host ] = true;
			$this->note_third_party( $third, $host, $path, 'inline' );
		}
	}

	/**
	 * Services that are normally started by an inline snippet (tag managers,
	 * analytics and pixels, chat widgets, font loaders).
	 *
	 * @return string[]
	 */
	private function inline_loader_names() {
		return array(
			'Google Tag Manager',
			'Google Analytics',
			'Google Ads',
			'Google Ads (DoubleClick)',
			'Google AdSense',
			'Google Fonts',
			'Adobe Fonts',
			'Font Awesome CDN',
			'Facebook Pixel',
			'Hotjar',
			'Microsoft Clarity',
			'Microsoft Advertising',
			'TikTok Pixel',
			'Snap Pixel',
			'LinkedIn Insight',
			'Pinterest',
			'HubSpot',
			'Intercom',
			'Drift',
			'Tawk.to',
			'Crisp',
			'Zendesk',
			'LiveChat',
			'Segment',
			'Mailchimp',
			'Cookiebot',
			'OneTrust',
			'Trustpilot',
			'Calendly',
			'AddThis',
			'ShareThis',
			'New Relic',
			'Sentry',
			'Cloudflare Web Analytics',
		);
	}

	/**
	 * Name of a known service for a host (+ path), or ''.
	 *
	 * @param string $host Host.
	 * @param string $path URL path.
	 * @return string
	 */
	private function known_service( $host, $path = '' ) {
		$host = strtolower( (string) $host );
		$path = (string) $path;
		$memo = $host . '|' . substr( $path, 0, 40 );
		if ( isset( $this->known_cache[ $memo ] ) ) {
			return $this->known_cache[ $memo ];
		}

		$name = '';
		foreach ( $this->known_map() as $key => $label ) {
			$key_host = (string) $key;
			$key_path = '';
			$slash    = strpos( $key_host, '/' );
			if ( false !== $slash ) {
				$key_path = substr( $key_host, $slash );
				$key_host = substr( $key_host, 0, $slash );
			}
			if ( $host !== $key_host && '.' . $key_host !== substr( $host, -strlen( $key_host ) - 1 ) ) {
				continue;
			}
			if ( '' !== $key_path && 0 !== strpos( $path, $key_path ) ) {
				continue;
			}
			$name = (string) $label;
			break;
		}

		$this->known_cache[ $memo ] = $name;
		return $name;
	}

	/**
	 * Known third-party services. Keys are a host (matches subdomains too) or
	 * host/path-prefix; first match wins, so path-specific keys come first.
	 *
	 * @return array<string,string>
	 */
	private function known_map() {
		if ( null !== $this->known_map ) {
			return $this->known_map;
		}

		$map = array(
			'www.google.com/recaptcha'      => 'reCAPTCHA',
			'www.gstatic.com/recaptcha'     => 'reCAPTCHA',
			'www.recaptcha.net'             => 'reCAPTCHA',
			'recaptcha.net'                 => 'reCAPTCHA',
			'hcaptcha.com'                  => 'hCaptcha',
			'www.google.com/maps'           => 'Google Maps',
			'maps.googleapis.com'           => 'Google Maps',
			'maps.gstatic.com'              => 'Google Maps',
			'googletagmanager.com'          => 'Google Tag Manager',
			'google-analytics.com'          => 'Google Analytics',
			'analytics.google.com'          => 'Google Analytics',
			'googleadservices.com'          => 'Google Ads',
			'googlesyndication.com'         => 'Google AdSense',
			'doubleclick.net'               => 'Google Ads (DoubleClick)',
			'translate.google.com'          => 'Google Translate',
			'translate.googleapis.com'      => 'Google Translate',
			'fonts.googleapis.com'          => 'Google Fonts',
			'fonts.gstatic.com'             => 'Google Fonts',
			'use.typekit.net'               => 'Adobe Fonts',
			'use.fontawesome.com'           => 'Font Awesome CDN',
			'kit.fontawesome.com'           => 'Font Awesome CDN',
			'connect.facebook.net'          => 'Facebook Pixel',
			'www.facebook.com/tr'           => 'Facebook Pixel',
			'static.hotjar.com'             => 'Hotjar',
			'script.hotjar.com'             => 'Hotjar',
			'youtube.com'                   => 'YouTube',
			'youtube-nocookie.com'          => 'YouTube',
			'youtu.be'                      => 'YouTube',
			'ytimg.com'                     => 'YouTube',
			'player.vimeo.com'              => 'Vimeo',
			'vimeo.com'                     => 'Vimeo',
			'vimeocdn.com'                  => 'Vimeo',
			'cdn.jsdelivr.net'              => 'Public CDN',
			'cdnjs.cloudflare.com'          => 'Public CDN',
			'unpkg.com'                     => 'Public CDN',
			'ajax.googleapis.com'           => 'Public CDN',
			'code.jquery.com'               => 'Public CDN',
			'stackpath.bootstrapcdn.com'    => 'Public CDN',
			'maxcdn.bootstrapcdn.com'       => 'Public CDN',
			'js.stripe.com'                 => 'Stripe',
			'js.stripe.network'             => 'Stripe',
			'm.stripe.network'              => 'Stripe',
			'paypalobjects.com'             => 'PayPal',
			'www.paypal.com'                => 'PayPal',
			'platform.twitter.com'          => 'Twitter / X',
			'platform.x.com'                => 'Twitter / X',
			'platform.linkedin.com'         => 'LinkedIn',
			'snap.licdn.com'                => 'LinkedIn Insight',
			'assets.pinterest.com'          => 'Pinterest',
			'ct.pinterest.com'              => 'Pinterest',
			'analytics.tiktok.com'          => 'TikTok Pixel',
			'sc-static.net'                 => 'Snap Pixel',
			'clarity.ms'                    => 'Microsoft Clarity',
			'bat.bing.com'                  => 'Microsoft Advertising',
			'js.hs-scripts.com'             => 'HubSpot',
			'js.hsforms.net'                => 'HubSpot',
			'js.hs-analytics.net'           => 'HubSpot',
			'js.hs-banner.com'              => 'HubSpot',
			'widget.intercom.io'            => 'Intercom',
			'js.intercomcdn.com'            => 'Intercom',
			'embed.tawk.to'                 => 'Tawk.to',
			'js.driftt.com'                 => 'Drift',
			'client.crisp.chat'             => 'Crisp',
			'static.zdassets.com'           => 'Zendesk',
			'cdn.livechatinc.com'           => 'LiveChat',
			'cdn.segment.com'               => 'Segment',
			'chimpstatic.com'               => 'Mailchimp',
			'list-manage.com'               => 'Mailchimp',
			'assets.calendly.com'           => 'Calendly',
			'embed.typeform.com'            => 'Typeform',
			'consent.cookiebot.com'         => 'Cookiebot',
			'cdn.cookielaw.org'             => 'OneTrust',
			'widget.trustpilot.com'         => 'Trustpilot',
			'browser.sentry-cdn.com'        => 'Sentry',
			'js-agent.newrelic.com'         => 'New Relic',
			'static.cloudflareinsights.com' => 'Cloudflare Web Analytics',
			'cloudflareinsights.com'        => 'Cloudflare Web Analytics',
			'secure.gravatar.com'           => 'Gravatar',
			'gravatar.com'                  => 'Gravatar',
			'stats.wp.com'                  => 'Jetpack / WordPress.com Stats',
			'pixel.wp.com'                  => 'Jetpack / WordPress.com Stats',
			'w.soundcloud.com'              => 'SoundCloud',
			'open.spotify.com'              => 'Spotify',
			'fast.wistia.com'               => 'Wistia',
			'fast.wistia.net'               => 'Wistia',
			's7.addthis.com'                => 'AddThis',
			'platform-api.sharethis.com'    => 'ShareThis',
			'www.instagram.com'             => 'Instagram',
		);

		/**
		 * Filter the known third-party services recognised on audited pages.
		 *
		 * @param array<string,string> $map Host (or host/path-prefix) => service name.
		 */
		$filtered        = apply_filters( 'blt_optimized_known_third_parties', $map );
		$this->known_map = is_array( $filtered ) ? $filtered : $map;
		return $this->known_map;
	}
}
