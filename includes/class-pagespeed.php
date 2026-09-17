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
		 * @since 2.0.0
		 * @var string
		 */
		const TREND_OPTION = 'wppo_web_vitals_trends';

		/**
		 * Maximum number of historical results kept per URL + strategy.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		const TREND_LIMIT = 30;

		/**
		 * Maximum number of URL + strategy keys retained across the whole trends map.
		 *
		 * Guards against unbounded option growth when many distinct URLs are
		 * scanned over time. Older keys are pruned first.
		 *
		 * @since 2.0.0
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
			$args = array(
				array(
					'url'      => $url,
					'strategy' => $strategy,
				),
			);
			// Util ships in-repo: called directly (issue #1310 review) — its
			// internal supports_* probe plus legacy guard already fail open
			// to 0, so no method_exists branch is needed here. A 0 return is
			// ambiguous (deduped race vs scheduler failure): re-query for the
			// concurrent winner's ID so the REST/UI layer can poll the pending
			// job instead of reporting failure.
			$job_id = (int) Util::enqueue_unique_async_action(
				self::AS_HOOK,
				$args,
				self::AS_GROUP
			);
			if ( $job_id > 0 ) {
				return $job_id;
			}
			$winner = self::find_pending_job_id( $args );
			if ( $winner > 0 ) {
				return $winner;
			}
			return 0;
		}

		/**
		 * Find the pending Action Scheduler job ID for the given args.
		 *
		 * Single home for the "re-query the winner" lookup used after a
		 * deduped enqueue so queue_scan() never reports failure while a job is
		 * pending (issue #1310 review). Fail-open: returns 0 when the lookup
		 * API is unavailable or finds nothing.
		 *
		 * @since NEXT
		 * @param array $args Action arguments.
		 * @return int Pending job ID, or 0 when none is found.
		 */
		private static function find_pending_job_id( array $args ): int {
			try {
				if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
					return 0;
				}
				// Bound to 1 row (issue #1310 review): only reset($existing)
				// is used, so retry storms must not materialize N rows.
				$existing = as_get_scheduled_actions(
					array(
						'hook'     => self::AS_HOOK,
						'args'     => $args,
						'group'    => self::AS_GROUP,
						'status'   => \ActionScheduler_Store::STATUS_PENDING,
						'per_page' => 1,
					),
					'ids'
				);
				if ( is_array( $existing ) && ! empty( $existing ) ) {
					return (int) reset( $existing );
				}
				// Running backstop (issue #1310 review): a winner that
				// transitioned to running between the 0 return and the
				// re-query must not be misreported as scheduler failure.
				$running = as_get_scheduled_actions(
					array(
						'hook'     => self::AS_HOOK,
						'args'     => $args,
						'group'    => self::AS_GROUP,
						'status'   => \ActionScheduler_Store::STATUS_RUNNING,
						'per_page' => 1,
					),
					'ids'
				);
				if ( is_array( $running ) && ! empty( $running ) ) {
					return (int) reset( $running );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 0;
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

			/**
			 * Filters the PageSpeed API request timeout in seconds.
			 *
			 * Defaults to 60s (the API commonly takes 30-90s). Long values
			 * risk wedging the AS worker; short values risk false timeouts.
			 *
			 * @since 2.0.0
			 * @param int $timeout Timeout in seconds.
			 */
			$timeout = (int) apply_filters( 'wppo_pagespeed_request_timeout', 60 );
			$timeout = max( 5, min( 300, $timeout ) );

			$response = self::request_pagespeed_api( $query_url, $timeout );

			if ( is_wp_error( $response ) ) {
				// Free the worker slot instead of sleep()+retry inline: yield
				// with a delayed single action, keeping at most one retry.
				$already_retried = ! empty( $args['retry'] );
				if ( ! $already_retried && function_exists( 'as_schedule_single_action' ) ) {
					$delay      = self::get_retry_delay();
					$retry_args = array(
						array(
							'url'      => $url,
							'strategy' => $strategy,
							'retry'    => 1,
						),
					);
					// Atomic unique insert via the shared helper (issue #1310):
					// Util ships in-repo so it is called directly — its
					// internal function_exists + supports_* + try/catch
					// already fails open to 0 with no method_exists branch
					// needed here (issue #1310 review). The return is gated: a
					// 0 with no job pending means the scheduler failed, so fall
					// through to the error handling below instead of logging a
					// phantom 'retry re-queued' with no backing job.
					$retry_id      = Util::schedule_unique_single_action( time() + $delay, self::AS_HOOK, $retry_args, self::AS_GROUP );
					$retry_pending = $retry_id > 0;
					if ( ! $retry_pending ) {
						$retry_pending = self::find_pending_job_id( $retry_args ) > 0;
					}
					if ( $retry_pending ) {
						/* translators: %d is the retry delay in seconds. */
						Log::add( sprintf( __( 'PageSpeed transport error; retry re-queued in %d seconds.', 'performance-optimisation' ), $delay ) );
						return;
					}
				}
				if ( ! $already_retried && ! function_exists( 'as_schedule_single_action' ) ) {
					// No scheduler (e.g. unit tests): one immediate retry
					// without blocking sleep.
					$response = self::request_pagespeed_api( $query_url, $timeout );
				}
				if ( is_wp_error( $response ) ) {
					$clean_error = sanitize_text_field( str_replace( ABSPATH, '', $response->get_error_message() ) );
					// Translators: %s is the error message from the PageSpeed API.
					Log::add( sprintf( __( 'PageSpeed API error: %s', 'performance-optimisation' ), $clean_error ) );
					self::store_failure( $url, $strategy, $clean_error );
					return;
				}
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
		 * Perform the PageSpeed API request (single attempt, no blocking retry).
		 *
		 * A transport failure is returned to the caller so run_scan() can
		 * re-queue with as_schedule_single_action() and free the Action
		 * Scheduler worker slot instead of blocking it with sleep().
		 *
		 * @since 2.0.0
		 * @param string $query_url Fully built API URL.
		 * @param int    $timeout   Request timeout in seconds.
		 * @return array|WP_Error Response array or error object.
		 */
		private static function request_pagespeed_api( string $query_url, int $timeout ) {
			$args = array(
				'timeout'   => $timeout,
				'sslverify' => true,
			);

			return wp_remote_get( $query_url, $args );
		}

		/**
		 * Get the re-queue delay (seconds) after a transport failure.
		 *
		 * @since 2.0.0
		 * @return int Delay clamped to 1-10 seconds.
		 */
		private static function get_retry_delay(): int {
			/**
			 * Filters the backoff delay (seconds) before the PageSpeed retry.
			 *
			 * @since 2.0.0
			 * @param int $retry_after Delay in seconds.
			 */
			$retry_after = (int) apply_filters( 'wppo_pagespeed_retry_delay', 2 );
			return max( 1, min( 10, $retry_after ) );
		}

		/**
		 * Persist a scan failure for later surfacing in the admin UI.
		 *
		 * @since 2.0.0
		 * @param string $url      Scanned URL.
		 * @param string $strategy Strategy slug.
		 * @param string $message  Failure message.
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
			$options = Util::get_settings();
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
		 * @return array Keyed by md5(url)_strategy, each value a list of snapshots.
		 */
		public static function get_trends(): array {
			$trends = get_option( self::TREND_OPTION, array() );
			return is_array( $trends ) ? $trends : array();
		}

		/**
		 * Migrate the trends option to non-autoloading.
		 *
		 * Rows created by older plugin versions defaulted to autoload=yes and
		 * keep loading on every WordPress request via alloptions. Mirrors
		 * Img_Converter::migrate_img_info_autoload().
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function migrate_trends_autoload(): void {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( self::TREND_OPTION, false );
			} else {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->options,
					array( 'autoload' => 'no' ),
					array( 'option_name' => self::TREND_OPTION )
				);
				wp_cache_delete( self::TREND_OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
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
					'details'       => self::sanitize_audit_details( $audit['details'] ?? array() ),
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

			// Cap the serialized payload (50KB) before it reaches set_transient:
			// drop per-audit details first, keeping scores/values intact.
			$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $return ) : false;
			if ( is_string( $encoded ) && strlen( $encoded ) > 50 * 1024 ) {
				foreach ( $return['diagnostics'] as $diag_id => $diag ) {
					$return['diagnostics'][ $diag_id ]['details'] = array();
				}
			}

			return $return;
		}

		/**
		 * Whitelist and slice raw Lighthouse audit details.
		 *
		 * Keeps only the summary plus the first 5 table items with scalar
		 * url/snippet/score fields, so unbounded API payloads (headings,
		 * debugData, full node trees) never bloat the transient.
		 *
		 * @since NEXT
		 * @param mixed $details Raw details array from the API.
		 * @return array Sanitized details.
		 */
		private static function sanitize_audit_details( $details ): array {
			if ( ! is_array( $details ) ) {
				return array();
			}
			$clean = array();
			if ( isset( $details['summary'] ) && is_scalar( $details['summary'] ) ) {
				$clean['summary'] = sanitize_text_field( (string) $details['summary'] );
			}
			if ( isset( $details['items'] ) && is_array( $details['items'] ) ) {
				$items = array_slice( $details['items'], 0, 5 );
				foreach ( $items as $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}
					$row = array();
					foreach ( array( 'snippet', 'score', 'wastedMs', 'wastedBytes' ) as $field ) {
						if ( ! isset( $item[ $field ] ) || ! is_scalar( $item[ $field ] ) ) {
							continue;
						}
						if ( 'snippet' === $field ) {
							$row[ $field ] = self::sanitize_snippet_for_lcp( (string) $item[ $field ] );
						} elseif ( 'wastedMs' === $field || 'wastedBytes' === $field ) {
							// Numeric-only: is_scalar alone admits bools and
							// arbitrary strings into the transient/REST payload.
							if ( is_numeric( $item[ $field ] ) ) {
								$row[ $field ] = 'wastedMs' === $field ? (float) $item[ $field ] : (int) $item[ $field ];
							}
						} elseif ( is_numeric( $item[ $field ] ) ) {
							// score: numeric-only, cast to float (mirrors wastedMs/wastedBytes).
							$row[ $field ] = (float) $item[ $field ];
						}
					}
					if ( isset( $item['url'] ) && is_scalar( $item['url'] ) ) {
						$row['url'] = esc_url_raw( (string) $item['url'] );
					}
					// Preserve node.snippet (scalar-only): extract_lcp_image_url()
					// falls back to parsing it for an <img> src when no
					// structured url is present.
					if ( isset( $item['node'] ) && is_array( $item['node'] ) && isset( $item['node']['snippet'] ) && is_scalar( $item['node']['snippet'] ) ) {
						$row['node'] = array( 'snippet' => self::sanitize_snippet_for_lcp( (string) $item['node']['snippet'] ) );
					}
					if ( array() === $row ) {
						continue;
					}
					$clean['items'][] = $row;
				}
			}
			return $clean;
		}

		/**
		 * Sanitize an LCP snippet while preserving the <img src> the
		 * extract_lcp_image_url() fallback regexes for.
		 *
		 * Plain text sanitization strips all tags, which would make the
		 * Priority-2 snippet fallback unmatchable on sanitized diagnostics.
		 * Allows only <img src> (length-capped) so stored transients stay
		 * bounded without losing the parse target.
		 *
		 * @since NEXT
		 * @param string $snippet Raw snippet from the API.
		 * @return string Sanitized snippet.
		 */
		private static function sanitize_snippet_for_lcp( string $snippet ): string {
			if ( function_exists( 'mb_substr' ) ) {
				$snippet = mb_substr( $snippet, 0, 2000, 'UTF-8' );
			} else {
				$snippet = substr( $snippet, 0, 2000 );
			}
			if ( function_exists( 'wp_kses' ) ) {
				return wp_kses( $snippet, array( 'img' => array( 'src' => true ) ) );
			}
			return sanitize_text_field( $snippet );
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
			$normalised_scan_url = untrailingslashit( self::normalise_url_for_compare( $url ) );
			$normalised_home     = untrailingslashit( self::normalise_url_for_compare( Util::cached_home_url( '/' ) ) );

			// Case 1: Front page.
			if ( $normalised_scan_url === $normalised_home ) {
				$option_name = 'wppo_front_page_lcp_' . $strategy_suffix;
				$existing    = get_option( $option_name, '' );
				if ( $existing !== $lcp_url ) {
					update_option( $option_name, $lcp_url, false );
					RUM::clear_stored_lcp_memo();
				}
				return;
			}

			// Case 2: Singular post — try to resolve URL to post ID.
			$post_id = url_to_postid( $url );
			if ( $post_id > 0 ) {
				update_post_meta( $post_id, '_wppo_lcp_image_url_' . $strategy_suffix, $lcp_url );
				RUM::clear_stored_lcp_memo();
				return;
			}

			// Case 3: Arbitrary URL — store in transient keyed by strategy + URL hash.
			$transient_key = Util::transient_key( 'wppo_lcp_url_' . $strategy_suffix . '_' . md5( $normalised_scan_url ) );
			set_transient( $transient_key, $lcp_url, DAY_IN_SECONDS );
			RUM::clear_stored_lcp_memo();
		}

		/**
		 * Normalise a URL for front-page comparison.
		 *
		 * Drops empty query strings and fragments (e.g. `/?` or `/#top`)
		 * so the scanned URL compares equal to the home URL; the previous
		 * add_query_arg( array(), $url ) normalisation did this implicitly.
		 *
		 * @since NEXT
		 * @param string $url Raw URL.
		 * @return string Sanitised URL without empty query/fragment.
		 */
		private static function normalise_url_for_compare( string $url ): string {
			$clean = esc_url_raw( $url );
			if ( function_exists( 'wp_parse_url' ) ) {
				$parts = wp_parse_url( $clean );
			} else {
				$parts = parse_url( $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Non-WP bootstrap fallback; wp_parse_url() preferred above.
			}
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return $clean;
			}
			if ( isset( $parts['query'] ) && '' === (string) $parts['query'] ) {
				unset( $parts['query'] );
			}
			unset( $parts['fragment'] );
			$rebuilt = strtolower( (string) ( $parts['scheme'] ?? 'https' ) ) . '://' . strtolower( (string) ( $parts['host'] ?? '' ) );
			if ( isset( $parts['port'] ) ) {
				// Strip default ports so http://example.com:80 compares
				// equal to http://example.com (and :443 to https://…).
				$scheme_lc  = strtolower( (string) ( $parts['scheme'] ?? 'https' ) );
				$port       = (int) $parts['port'];
				$is_default = ( 'http' === $scheme_lc && 80 === $port ) || ( 'https' === $scheme_lc && 443 === $port );
				if ( ! $is_default ) {
					$rebuilt .= ':' . $port;
				}
			}
			$rebuilt .= $parts['path'] ?? '';
			if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
				$rebuilt .= '?' . $parts['query'];
			}
			return esc_url_raw( $rebuilt );
		}
	}
}
