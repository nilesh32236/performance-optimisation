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
				'url'      => $request_url, // add_query_arg will handle encoding.
				'key'      => $api_key,
				'strategy' => strtoupper( $strategy ),
			);

			// The API accepts multiple category params; add_query_arg does not support
			// repeated keys, so we build the query string manually.
			$categories = array( 'PERFORMANCE', 'ACCESSIBILITY', 'BEST_PRACTICES', 'SEO' );
			foreach ( $categories as $cat ) {
				$query_args['category'][] = $cat;
			}
			$query_url = add_query_arg( $query_args, self::API_ENDPOINT );

			// Security: redact API key from debug logs.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$redacted_url = remove_query_arg( 'key', $query_url );
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
				// Translators: %s is the error message from the PageSpeed API.
				Log::add( sprintf( __( 'PageSpeed API error: %s', 'performance-optimisation' ), $response->get_error_message() ) );
				self::store_failure( $url, $strategy, $response->get_error_message() );
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
			return 'wppo_pagespeed_' . md5( esc_url_raw( $url ) ) . '_' . sanitize_key( $strategy );
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
			return (string) ( $options['performance_audit']['pagespeed_api_key'] ?? '' );
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

			return array(
				'scores'      => $scores,
				'vitals'      => $vitals,
				'diagnostics' => $diagnostics,
				'strategy'    => sanitize_text_field( $lighthouse['configSettings']['formFactor'] ?? 'unknown' ),
				'fetched_at'  => current_time( 'mysql', true ),
			);
		}
	}
}
