<?php
/**
 * Pagespeed Class
 *
 * Integrates with the Google PageSpeed Insights API v5 to retrieve Lighthouse
 * scores, Core Web Vitals, and diagnostic audit data for a given URL.
 *
 * Scans are always run as background jobs via Action Scheduler to prevent
 * admin UI timeouts (the API can take up to 60–90 seconds to respond).
 * Results are cached as WordPress transients for 24 hours.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.6.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) ) {

	/**
	 * Class Pagespeed
	 *
	 * Queues and executes Google PageSpeed Insights API scans via Action Scheduler.
	 * Stores prepared results as transients for instant retrieval by the React UI.
	 *
	 * @since 1.6.0
	 */
	class Pagespeed {

		/**
		 * Google PageSpeed Insights API v5 endpoint.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const API_ENDPOINT = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

		/**
		 * Action Scheduler hook name for background PageSpeed scans.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const AS_HOOK = 'wppo_pagespeed_scan';

		/**
		 * Action Scheduler group name.
		 *
		 * @since 1.6.0
		 * @var string
		 */
		const AS_GROUP = 'performance_optimisation';

		/**
		 * Transient TTL for PageSpeed results (24 hours).
		 *
		 * @since 1.6.0
		 * @var int
		 */
		const TRANSIENT_TTL = DAY_IN_SECONDS;

		/**
		 * Option name holding historical PageSpeed results for trend charts.
		 *
		 * @since NEXT
		 * @var string
		 */
		const TREND_OPTION = 'wppo_web_vitals_trends';

		/**
		 * Maximum number of historical results kept per URL + strategy.
		 *
		 * @since NEXT
		 * @var int
		 */
		const TREND_LIMIT = 30;

		/**
		 * Maximum number of URL + strategy keys retained across the whole trends map.
		 *
		 * Guards against unbounded option growth when many distinct URLs are
		 * scanned over time. Older keys are pruned first.
		 *
		 * @since NEXT
		 * @var int
		 */
		const TREND_MAX_KEYS = 20;

		/**
		 * Option used as the cross-request trend write lock.
		 *
		 * @var string
		 */
		const TREND_LOCK_OPTION = 'wppo_web_vitals_trends_lock';

		/**
		 * Seconds a trend lock may be held before it is considered stale.
		 *
		 * @var int
		 */
		const TREND_LOCK_TTL = 60;

		/**
		 * Queue a PageSpeed scan as an async background job.
		 *
		 * Called from the REST endpoint POST /pagespeed_scan.
		 * Returns the Action Scheduler job ID immediately so the React UI
		 * can poll GET /pagespeed_results until the result is ready.
		 *
		 * @since  1.6.0
		 * @param  string $url      The URL to scan.
		 * @param  string $strategy Either 'mobile' or 'desktop'.
		 * @return int Action Scheduler job ID.
		 */
		public static function queue_scan( string $url, string $strategy = 'mobile' ): int {
			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return 0;
			}
			return (int) as_enqueue_async_action(
				self::AS_HOOK,
				array(
					array(
						'url'      => $url,
						'strategy' => $strategy,
					),
				),
				self::AS_GROUP
			);
		}

		/**
		 * Execute the PageSpeed API call.
		 *
		 * Fired by Action Scheduler when the queued job runs. Reads the API key
		 * from settings, calls the Google API, prepares the response, and stores
		 * it as a transient for retrieval by GET /pagespeed_results.
		 *
		 * @since  1.6.0
		 * @param  array $args { url: string, strategy: string }.
		 * @return void
		 */
		public static function run_scan( array $args ): void {
			$url      = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
			$strategy = isset( $args['strategy'] ) ? sanitize_text_field( $args['strategy'] ) : 'mobile';

			if ( empty( $url ) ) {
				Log::add( __( 'PageSpeed scan skipped: empty URL.', 'performance-optimisation' ) );
				return;
			}

			$api_key = self::get_api_key();
			if ( empty( $api_key ) ) {
				Log::add( __( 'PageSpeed scan skipped: API key not configured.', 'performance-optimisation' ) );
				self::store_failure( $url, $strategy, 'PageSpeed API key is not configured. Add it in the Performance Audit settings.' );
				return;
			}

			$request_url = $url;

			// The Google PageSpeed API rejects localhost or non-public URLs.
			// Use wp_http_validate_url() for robust SSRF protection (rejects loopback,
			// private/reserved IP ranges including IPv6, 0.0.0.0, 10.x, 172.16-31.x, 192.168.x).
			if ( ! wp_http_validate_url( $request_url ) ) {
				Log::add( __( 'PageSpeed scan failed: local URL detected.', 'performance-optimisation' ) );
				self::store_failure( $url, $strategy, 'PageSpeed cannot scan local or non-public URLs. Please use a public URL.' );
				return;
			}

			$query_args = array(
				'url'      => $request_url,
				'key'      => $api_key,
				'strategy' => strtoupper( $strategy ),
			);

			// The PageSpeed API requires repeated `category` params (e.g. category=PERFORMANCE&category=SEO).
			// add_query_arg() serialises arrays as category[0]=... so we build the base URL first,
			// then append each category value manually.
			$base_url   = add_query_arg( $query_args, self::API_ENDPOINT );
			$categories = array( 'PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO' );
			$query_url  = $base_url . '&' . implode( '&', array_map( fn( $cat ) => 'category=' . rawurlencode( $cat ), $categories ) );

			// Build a redacted URL for logging (omit the API key).
			$redacted_base = add_query_arg(
				array(
					'url'      => $request_url,
					'strategy' => strtoupper( $strategy ),
				),
				self::API_ENDPOINT
			);
			$redacted_url  = $redacted_base . '&' . implode( '&', array_map( fn( $cat ) => 'category=' . rawurlencode( $cat ), $categories ) );

			/* translators: %s is the PageSpeed API request URL (API key redacted). */
			Log::add( sprintf( __( 'PageSpeed API request: %s', 'performance-optimisation' ), esc_url( $redacted_url ) ) );

			$response = wp_remote_get(
				$query_url,
				array(
					'timeout'   => 120,
					'sslverify' => true,
				)
			);

			if ( is_wp_error( $response ) ) {
				$clean_error = sanitize_text_field( str_replace( ABSPATH, '', $response->get_error_message() ) );
				// Translators: %s is the error message from the PageSpeed API.
				Log::add( sprintf( __( 'PageSpeed API error: %s', 'performance-optimisation' ), $clean_error ) );
				self::store_failure( $url, $strategy, $clean_error );
				return;
			}

			$http_code = (int) wp_remote_retrieve_response_code( $response );

			if ( 200 !== $http_code ) {
				// Translators: %1$d is the HTTP status code, %2$s is the URL.
				$msg = sprintf( __( 'PageSpeed API returned HTTP %1$d for %2$s.', 'performance-optimisation' ), $http_code, esc_url( $url ) );
				Log::add( $msg );
				self::store_failure( $url, $strategy, $msg );
				return;
			}

			$body = json_decode( wp_remote_retrieve_body( $response ), true );

			if ( ! is_array( $body ) ) {
				$msg = __( 'PageSpeed API error: invalid JSON response.', 'performance-optimisation' );
				Log::add( $msg );
				self::store_failure( $url, $strategy, $msg );
				return;
			}

			if ( isset( $body['error'] ) ) {
				$error_message = $body['error']['message'] ?? 'Unknown API error';
				// Translators: %s is the error message from the PageSpeed API.
				$msg = sprintf( __( 'PageSpeed API error: %s', 'performance-optimisation' ), sanitize_text_field( $error_message ) );
				Log::add( $msg );
				self::store_failure( $url, $strategy, $msg );
				return;
			}

			$prepared      = self::prepare_response( $body );
			$transient_key = self::get_transient_key( $url, $strategy );

			set_transient( $transient_key, $prepared, self::TRANSIENT_TTL );
			Telemetry::register_transient_key( $transient_key );

			self::record_trend( $url, $prepared, $strategy );

			self::store_lcp_image_url( $url, $prepared, $strategy );

			Log::add(
				sprintf(
					/* translators: %1$s is the URL, %2$s is the strategy (mobile/desktop), %3$d is the performance score. */
					__( 'PageSpeed scan completed for %1$s (%2$s). Performance score: %3$d.', 'performance-optimisation' ),
					esc_url( $url ),
					esc_html( $strategy ),
					(int) ( $prepared['scores']['performance'] ?? 0 )
				)
			);
		}

		/**
		 * Retrieve cached PageSpeed results for a URL and strategy.
		 *
		 * Returns the prepared result array if the transient exists, or false
		 * if the background job has not yet completed.
		 *
		 * @since  1.6.0
		 * @param  string $url      The scanned URL.
		 * @param  string $strategy Either 'mobile' or 'desktop'.
		 * @return array|false Prepared result array, or false if not ready.
		 */
		public static function get_results( string $url, string $strategy = 'mobile' ) {
			return get_transient( self::get_transient_key( $url, $strategy ) );
		}

		/**
		 * Store a failure sentinel so the React poller gets a definitive error
		 * instead of polling until MAX_POLL_ATTEMPTS is exhausted.
		 *
		 * The transient value is an array with 'error' => true so the REST handler
		 * can distinguish it from a successful result.
		 *
		 * @since  1.6.0
		 * @param  string $url      The scanned URL.
		 * @param  string $strategy Either 'mobile' or 'desktop'.
		 * @param  string $message  Human-readable error message.
		 * @return void
		 */
		private static function store_failure( string $url, string $strategy, string $message ): void {
			$transient_key = self::get_transient_key( $url, $strategy );
			$payload       = array(
				'error'   => true,
				'message' => $message,
			);
			// Short TTL — 5 minutes is enough for the UI to pick it up.
			set_transient( $transient_key, $payload, 5 * MINUTE_IN_SECONDS );
			Telemetry::register_transient_key( $transient_key );
		}

		/**
		 * Build the transient key for a URL + strategy combination.
		 *
		 * @since  1.6.0
		 * @param  string $url      The scanned URL.
		 * @param  string $strategy Either 'mobile' or 'desktop'.
		 * @return string Transient key.
		 */
		public static function get_transient_key( string $url, string $strategy ): string {
			return Util::transient_key( 'wppo_pagespeed_' . md5( esc_url_raw( $url ) ) . '_' . sanitize_key( $strategy ) );
		}

		/**
		 * Read the PageSpeed API key from plugin settings.
		 *
		 * Reads exclusively from wppo_settings['performance_audit']['pagespeed_api_key'].
		 * Never hardcodes or falls back to a default key.
		 *
		 * @since  1.6.0
		 * @return string API key, or empty string if not configured.
		 */
		private static function get_api_key(): string {
			$options = get_option( 'wppo_settings', array() );
			return (string) ( isset( $options['performance_audit']['pagespeed_api_key'] ) ? $options['performance_audit']['pagespeed_api_key'] : '' );
		}

		/**
		 * Record a PageSpeed result into the Web Vitals trend history.
		 *
		 * Stores a compact snapshot (performance score + core vitals) keyed by
		 * URL + strategy in a single site option, capped at TREND_LIMIT entries
		 * per key. The read-append-write sequence is serialized with a shared
		 * cache lock so concurrent async workers (e.g. mobile + desktop scans
		 * running at the same time) cannot overwrite each other's snapshot.
		 *
		 * @since NEXT
		 * @param  string $url      The scanned URL.
		 * @param  array  $prepared The prepared PageSpeed result array.
		 * @param  string $strategy Either 'mobile' or 'desktop'.
		 * @return void
		 */
		public static function record_trend( string $url, array $prepared, string $strategy = 'mobile' ): void {
			$key = md5( esc_url_raw( $url ) ) . '_' . sanitize_key( $strategy );

			// Serialize read-modify-write across async workers. add_option() is an
			// atomic INSERT in the database, so it works even when the object cache
			// is request-local (no shared Redis/Memcached); stale locks are stolen
			// after a short timeout so a crashed worker cannot wedge the writer.
			if ( ! self::acquire_trend_lock() ) {
				return; // Another worker owns the write; skip to avoid a lost update.
			}

			try {
				// Re-read fresh after acquiring the lock.
				$hist = self::get_trends();

				$current = isset( $hist[ $key ] ) && is_array( $hist[ $key ] ) ? $hist[ $key ] : array();

				$current[] = array(
					'fetched_at'  => isset( $prepared['fetched_at'] ) ? sanitize_text_field( $prepared['fetched_at'] ) : current_time( 'mysql', true ),
					'performance' => (int) ( $prepared['scores']['performance'] ?? 0 ),
					'lcp'         => isset( $prepared['vitals']['lcp']['value'] ) ? (float) $prepared['vitals']['lcp']['value'] : null,
					'cls'         => isset( $prepared['vitals']['cls']['value'] ) ? (float) $prepared['vitals']['cls']['value'] : null,
					'tbt'         => isset( $prepared['vitals']['tbt']['value'] ) ? (float) $prepared['vitals']['tbt']['value'] : null,
				);

				if ( count( $current ) > self::TREND_LIMIT ) {
					$current = array_slice( $current, -self::TREND_LIMIT );
				}

				$hist[ $key ] = $current;

				// Bound total storage, not just per-URL history.
				$hist = self::prune_trends( $hist );

				update_option( self::TREND_OPTION, $hist, false );
			} finally {
				self::release_trend_lock();
			}
		}

		/**
		 * Acquire the cross-request trend write lock.
		 *
		 * The add_option() call only inserts when the key is absent (atomic in
		 * the DB), so a simultaneous worker cannot both hold the lock. Locks
		 * older than TREND_LOCK_TTL are considered stale and stolen.
		 *
		 * @since NEXT
		 * @return bool
		 */
		private static function acquire_trend_lock(): bool {
			if ( add_option( self::TREND_LOCK_OPTION, time(), '', false ) ) {
				return true;
			}

			$held_since = (int) get_option( self::TREND_LOCK_OPTION, 0 );
			if ( $held_since > 0 && ( time() - $held_since ) > self::TREND_LOCK_TTL ) {
				update_option( self::TREND_LOCK_OPTION, time(), false );
				return true;
			}

			return false;
		}

		/**
		 * Release the trend write lock.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function release_trend_lock(): void {
			delete_option( self::TREND_LOCK_OPTION );
		}

		/**
		 * Enforce a global cap on the number of URL + strategy keys.
		 *
		 * When the map grows past TREND_MAX_KEYS the oldest keys (ranked by the
		 * timestamp of their most recent snapshot) are dropped, preserving recent
		 * data while keeping the option bounded.
		 *
		 * @since NEXT
		 * @param  array $hist The full trends map.
		 * @return array The trimmed trends map.
		 */
		private static function prune_trends( array $hist ): array {
			$keys = array_keys( $hist );
			if ( count( $keys ) <= self::TREND_MAX_KEYS ) {
				return $hist;
			}

			$rank = array();
			foreach ( $hist as $trend_key => $snapshots ) {
				$last = 0;
				if ( is_array( $snapshots ) ) {
					$last_snapshot = end( $snapshots );
					if ( is_array( $last_snapshot ) && isset( $last_snapshot['fetched_at'] ) ) {
						$last = (int) strtotime( (string) $last_snapshot['fetched_at'] );
					}
				}
				$rank[ $trend_key ] = $last;
			}
			asort( $rank );

			$total = count( $hist );
			while ( $total > self::TREND_MAX_KEYS ) {
				$oldest = key( $rank );
				if ( null === $oldest ) {
					break;
				}
				unset( $hist[ $oldest ], $rank[ $oldest ] );
				--$total;
			}

			return $hist;
		}

		/**
		 * Retrieve the full Web Vitals trend history.
		 *
		 * @since NEXT
		 * @return array Keyed by md5(url)_strategy, each value a list of snapshots.
		 */
		public static function get_trends(): array {
			$trends = get_option( self::TREND_OPTION, array() );
			return is_array( $trends ) ? $trends : array();
		}

		/**
		 * Extract and normalise the fields we need from the raw Lighthouse response.
		 *
		 * Extracts:
		 * - Lighthouse category scores (performance, accessibility, best-practices, seo)
		 * - Core Web Vitals (FCP, LCP, TBT, CLS, Speed Index, TTI)
		 * - Diagnostic audits (render-blocking-resources, unused-css-rules,
		 *   unused-javascript, unminified-css, unminified-javascript,
		 *   uses-text-compression, server-response-time,
		 *   largest-contentful-paint-element)
		 *
		 * @since  1.6.0
		 * @param  array $response Decoded JSON response from the PageSpeed API.
		 * @return array Prepared result array.
		 */
		private static function prepare_response( array $response ): array {
			$lighthouse = $response['lighthouseResult'] ?? array();
			$categories = $lighthouse['categories'] ?? array();
			$audits     = $lighthouse['audits'] ?? array();

			// --- Category scores (0–100 integers) ---
			$scores = array();
			foreach ( $categories as $key => $cat ) {
				// Normalise key: 'best-practices' → 'best_practices'.
				$normalised_key            = str_replace( '-', '_', $key );
				$scores[ $normalised_key ] = (int) round( ( $cat['score'] ?? 0 ) * 100 );
			}

			// --- Core Web Vitals ---
			$vitals_map = array(
				'first-contentful-paint'   => 'fcp',
				'largest-contentful-paint' => 'lcp',
				'total-blocking-time'      => 'tbt',
				'cumulative-layout-shift'  => 'cls',
				'speed-index'              => 'speed_index',
				'interactive'              => 'tti',
			);

			$vitals = array();
			foreach ( $vitals_map as $audit_id => $key ) {
				$audit          = $audits[ $audit_id ] ?? array();
				$vitals[ $key ] = array(
					'value'         => isset( $audit['numericValue'] ) ? (float) $audit['numericValue'] : null,
					'display_value' => isset( $audit['displayValue'] ) ? sanitize_text_field( $audit['displayValue'] ) : null,
					'score'         => isset( $audit['score'] ) ? (float) $audit['score'] : null,
				);
			}

			// --- Diagnostic audits ---
			$diagnostic_ids = array(
				'render-blocking-resources',
				'unused-css-rules',
				'unused-javascript',
				'unminified-css',
				'unminified-javascript',
				'uses-text-compression',
				'server-response-time',
				'largest-contentful-paint-element',
				'prioritize-lcp-image',
			);

			$diagnostics = array();
			foreach ( $diagnostic_ids as $id ) {
				if ( ! isset( $audits[ $id ] ) ) {
					continue;
				}
				$audit              = $audits[ $id ];
				$diagnostics[ $id ] = array(
					'score'         => isset( $audit['score'] ) ? (float) $audit['score'] : null,
					'display_value' => isset( $audit['displayValue'] ) ? sanitize_text_field( $audit['displayValue'] ) : null,
					'details'       => $audit['details'] ?? array(),
				);
			}

			$return = array(
				'scores'        => $scores,
				'vitals'        => $vitals,
				'diagnostics'   => $diagnostics,
				'strategy'      => sanitize_text_field( $lighthouse['configSettings']['formFactor'] ?? 'unknown' ),
				'fetched_at'    => current_time( 'mysql', true ),
				'lcp_image_url' => null,
			);

			// Try prioritise-lcp-image audit first (structured, more reliable),
			// fall back to largest-contentful-paint-element snippet parsing.
			$lcp = self::extract_lcp_image_url( $diagnostics );
			if ( null !== $lcp ) {
				$return['lcp_image_url'] = $lcp;
			}

			return $return;
		}

		/**
		 * Extract the LCP image URL from PageSpeed diagnostic audit data.
		 *
		 * Tries the newer "prioritize-lcp-image" audit's structured URL first,
		 * then falls back to parsing the "largest-contentful-paint-element" audit's
		 * node.snippet for an <img> src attribute.
		 *
		 * Returns null when no image URL can be identified (text LCP, background-image, etc.).
		 *
		 * @since NEXT
		 * @param array $diagnostics The prepared diagnostics array.
		 * @return string|null The image URL, or null if not found.
		 */
		private static function extract_lcp_image_url( array $diagnostics ): ?string {
			// Priority 1: "prioritize-lcp-image" audit has structured URL.
			$plcp = $diagnostics['prioritize-lcp-image']['details']['items'][0] ?? null;
			if ( is_array( $plcp ) && ! empty( $plcp['url'] ) ) {
				return esc_url_raw( $plcp['url'] );
			}

			// Priority 2: "largest-contentful-paint-element" audit snippet parsing.
			$lcp_element = $diagnostics['largest-contentful-paint-element']['details']['items'][0] ?? null;
			if ( ! is_array( $lcp_element ) ) {
				return null;
			}

			// Check for structured url first.
			if ( ! empty( $lcp_element['url'] ) ) {
				return esc_url_raw( $lcp_element['url'] );
			}

			// Fall back to regex on the node.snippet.
			$snippet = $lcp_element['node']['snippet'] ?? '';
			if ( empty( $snippet ) ) {
				$snippet = $lcp_element['snippet'] ?? '';
			}
			if ( empty( $snippet ) ) {
				return null;
			}

			if ( preg_match( '/<img\s[^>]*src=[\"\']([^\"\']+)[\"\']/i', $snippet, $m ) ) {
				$url = esc_url_raw( $m[1] );
				return ! empty( $url ) ? $url : null;
			}

			return null;
		}

		/**
		 * Store the LCP image URL per page so it can be used for auto-preloading.
		 *
		 * Persists the URL as:
		 * - Post meta for singular posts (by matching URL to a post ID), keyed by strategy
		 * - Site option for the front page, keyed by strategy
		 * - Transient keyed by strategy + URL hash for all other pages
		 *
		 * Does nothing if no LCP image URL was detected.
		 *
		 * @since NEXT
		 * @param string $url      The scanned URL.
		 * @param array  $prepared The prepared PageSpeed result array.
		 * @param string $strategy The scan strategy ('mobile' or 'desktop').
		 * @return void
		 */
		public static function store_lcp_image_url( string $url, array $prepared, string $strategy = 'mobile' ): void {
			$lcp_url = $prepared['lcp_image_url'] ?? null;
			if ( empty( $lcp_url ) ) {
				return;
			}

			$strategy_suffix     = sanitize_key( $strategy );
			$normalised_scan_url = untrailingslashit( esc_url_raw( add_query_arg( array(), $url ) ) );
			$normalised_home     = untrailingslashit( home_url( '/' ) );

			// Case 1: Front page.
			if ( $normalised_scan_url === $normalised_home ) {
				$option_name = 'wppo_front_page_lcp_' . $strategy_suffix;
				$existing    = get_option( $option_name, '' );
				if ( $existing !== $lcp_url ) {
					update_option( $option_name, $lcp_url, false );
				}
				return;
			}

			// Case 2: Singular post — try to resolve URL to post ID.
			$post_id = url_to_postid( $url );
			if ( $post_id > 0 ) {
				update_post_meta( $post_id, '_wppo_lcp_image_url_' . $strategy_suffix, $lcp_url );
				return;
			}

			// Case 3: Arbitrary URL — store in transient keyed by strategy + URL hash.
			$transient_key = Util::transient_key( 'wppo_lcp_url_' . $strategy_suffix . '_' . md5( $normalised_scan_url ) );
			set_transient( $transient_key, $lcp_url, DAY_IN_SECONDS );
		}
	}
}
