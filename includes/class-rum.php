<?php
/**
 * Real-user Web Vitals (RUM) collection and reporting.
 *
 * @package PerformanceOptimise
 */

namespace PerformanceOptimise\Inc;

if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {

	/**
	 * Collects real-visitor Core Web Vitals beacons and stores them as bounded
	 * per-day/per-path aggregates, plus the frontend beacon + admin data API.
	 *
	 * The beacon endpoint is intentionally public (anonymous visitors) so it is
	 * protected with a daily rolling, per-page token and per-IP rate limiting
	 * instead of the manage_options permission used by the admin endpoints.
	 *
	 * @since NEXT
	 */
	class RUM {

		/**
		 * Option storing the aggregated trend data.
		 *
		 * @var string
		 */
		const OPTION = 'wppo_web_vitals_rum';

		/**
		 * How many days of history to retain.
		 *
		 * @var int
		 */
		const MAX_DAYS = 14;

		/**
		 * Maximum paths retained per day.
		 *
		 * @var int
		 */
		const MAX_PATHS_PER_DAY = 200;

		/**
		 * Maximum beacons accepted per IP per hour.
		 *
		 * @var int
		 */
		const RATE_LIMIT_PER_HOUR = 120;

		/**
		 * Transient key for the RUM sample queue.
		 *
		 * @var string
		 * @since NEXT
		 */
		private const QUEUE_KEY = 'wppo_rum_queue';

		/**
		 * Transient key for the flush lock.
		 *
		 * @var string
		 * @since NEXT
		 */
		private const FLUSH_LOCK_KEY = 'wppo_rum_flush_lock';

		/**
		 * Maximum queued samples before forced flush.
		 *
		 * @var int
		 * @since NEXT
		 */
		private const QUEUE_MAX = 100;

		/**
		 * Flush when queue reaches this size.
		 *
		 * @var int
		 * @since NEXT
		 */
		private const FLUSH_THRESHOLD = 20;

		/**
		 * Per-request shutdown buffer for RUM samples.
		 *
		 * Batches multiple beacons arriving in the same PHP request into a
		 * single get/set_transient pair at shutdown, reducing per-beacon object-cache
		 * ops from 2 to ~1/request when keep-alive or HTTP/2 multiplexing delivers
		 * several beacons per worker.
		 *
		 * @var array<int, array>
		 * @since NEXT
		 */
		private static array $shutdown_buffer = array();

		/**
		 * Whether the shutdown handler for the RUM buffer has been registered.
		 *
		 * @var bool
		 * @since NEXT
		 */
		private static bool $shutdown_registered = false;

		/**
		 * Whether RUM collection is enabled.
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$options = Util::get_settings();
			return ! empty( $options['performance_audit']['rum_enabled'] );
		}

		/**
		 * Handle a RUM beacon.
		 *
		 * @param array $params Decoded JSON body from the beacon request.
		 * @return array{ok:bool,status:int,message:string}
		 */
		public static function collect( array $params ): array {
			if ( ! self::is_enabled() ) {
				return array(
					'ok'      => false,
					'status'  => 400,
					'message' => __( 'RUM is disabled.', 'performance-optimisation' ),
				);
			}

			$token = isset( $params['token'] ) ? sanitize_text_field( (string) $params['token'] ) : '';
			$path  = isset( $params['path'] ) ? sanitize_text_field( (string) $params['path'] ) : '/';
			if ( ! self::is_valid_token( $token, $path ) ) {
				return array(
					'ok'      => false,
					'status'  => 401,
					'message' => __( 'Invalid beacon token.', 'performance-optimisation' ),
				);
			}

			$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			if ( '' !== $ip && self::is_rate_limited( $ip ) ) {
				return array(
					'ok'      => false,
					'status'  => 429,
					'message' => __( 'Too many beacons.', 'performance-optimisation' ),
				);
			}

			$sample = self::sanitize_sample( $params );
			if ( null === $sample ) {
				return array(
					'ok'      => false,
					'status'  => 400,
					'message' => __( 'Invalid beacon payload.', 'performance-optimisation' ),
				);
			}

			self::store_sample( $sample );

			return array(
				'ok'      => true,
				'status'  => 200,
				'message' => '',
			);
		}

		/**
		 * Retrieve the aggregated RUM data for the admin dashboard.
		 *
		 * Flushes any queued samples first so the dashboard sees fresh data.
		 *
		 * @return array
		 */
		public static function get_data(): array {
			// Drain the per-request shutdown buffer before flushing so a beacon
			// collected in the same request (e.g. in tests) is visible immediately.
			self::flush_shutdown_buffer();
			// Opportunistically flush queued beacons before reading.
			self::flush_queue();
			$data = get_option( self::OPTION, array() );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Enqueue the frontend beacon script on the public site.
		 *
		 * @return void
		 */
		public static function maybe_enqueue_scripts(): void {
			if ( ! self::is_enabled() || is_admin() ) {
				return;
			}

			$asset_file = WPPO_PLUGIN_PATH . 'build/rum.asset.php';
			$deps       = array();
			$version    = WPPO_VERSION;
			if ( file_exists( $asset_file ) ) {
				$asset   = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				$deps    = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
				$version = isset( $asset['version'] ) ? $asset['version'] : WPPO_VERSION;
			}

			wp_enqueue_script( 'wppo-rum', WPPO_PLUGIN_URL . 'build/rum.js', $deps, $version, true );
		}

		/**
		 * Print the beacon config inline so it is baked into cached HTML.
		 *
		 * Runs on wp_footer; cached pages generated through WordPress capture it
		 * and the served (cache-hit) HTML therefore keeps working without WP.
		 *
		 * @return void
		 */
		public static function print_config(): void {
			if ( ! self::is_enabled() ) {
				return;
			}

			$path = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';

			$config = array(
				'apiUrl' => esc_url_raw( rest_url( 'performance-optimisation/v1/rum_collect' ) ),
				'token'  => self::token_for( time(), $path ),
				'path'   => $path,
			);

			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded config.
			echo '<script id="wppo-rum-config">window.wppoRum=' . wp_json_encode( $config ) . ';</script>' . "\n";
		}

		/**
		 * Rolling daily token for the beacon, scoped to the page path.
		 *
		 * A token minted for one URL path cannot be replayed against another,
		 * so a leaked token can only inflate metrics for its own page.
		 *
		 * @param int    $timestamp Unix timestamp.
		 * @param string $path      Page path the token is minted for.
		 * @return string
		 *
		 * @since NEXT The $path parameter was added.
		 */
		private static function token_for( int $timestamp, string $path = '/' ): string {
			return wp_hash( 'wppo_rum_' . gmdate( 'Ymd', $timestamp ) . '|' . $path );
		}

		/**
		 * Validate the beacon token against today or yesterday for the given path.
		 *
		 * @param string $token Beacon token.
		 * @param string $path  Page path the beacon was served on.
		 * @return bool
		 *
		 * @since NEXT The $path parameter was added.
		 */
		private static function is_valid_token( string $token, string $path = '/' ): bool {
			if ( '' === $token ) {
				return false;
			}
			$now = time();
			foreach ( array( $now, $now - DAY_IN_SECONDS ) as $timestamp ) {
				if ( hash_equals( self::token_for( $timestamp, $path ), $token ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether the given IP has exceeded the hourly beacon budget.
		 *
		 * @param string $ip Client IP address.
		 * @return bool
		 */
		private static function is_rate_limited( string $ip ): bool {
			$key   = Util::transient_key( 'wppo_rum_ratelimit_' . md5( $ip ) );
			$count = (int) get_transient( $key );
			if ( $count >= self::RATE_LIMIT_PER_HOUR ) {
				return true;
			}
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			return false;
		}

		/**
		 * Validate and clamp a beacon sample.
		 *
		 * @param array $params Raw beacon payload.
		 * @return array|null Normalized sample or null when invalid.
		 */
		private static function sanitize_sample( array $params ): ?array {
			$raw_path = isset( $params['path'] ) ? sanitize_text_field( (string) $params['path'] ) : '';
			if ( '' === $raw_path ) {
				return null;
			}

			$parsed_path = wp_parse_url( $raw_path, PHP_URL_PATH );
			$path        = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : '/';
			$path        = substr( $path, 0, 512 );

			$ranges = array(
				'ttfb' => array( 0, 60000 ),
				'fcp'  => array( 0, 60000 ),
				'lcp'  => array( 0, 60000 ),
				'inp'  => array( 0, 60000 ),
				'cls'  => array( 0, 1 ),
			);

			$sample  = array( 'path' => $path );
			$has_any = false;
			foreach ( $ranges as $metric => $range ) {
				if ( ! isset( $params[ $metric ] ) ) {
					continue;
				}
				$value = (float) $params[ $metric ];
				if ( ! is_finite( $value ) ) {
					return null;
				}
				$sample[ $metric ] = max( $range[0], min( $range[1], $value ) );
				$has_any           = true;
			}

			if ( ! $has_any ) {
				return null;
			}

			return $sample;
		}

		/**
		 * Buffer a sample to a transient queue and flush periodically.
		 *
		 * Replaces the previous per-beacon get_option+update_option with a
		 * transient queue that is flushed in batches, reducing option
		 * writes from 1 per beacon to ~1 per FLUSH_THRESHOLD beacons.
		 * Multiple beacons in the same request are coalesced into a single
		 * set_transient at shutdown to avoid per-beacon object-cache churn
		 * on keep-alive/HTTP/2 multiplexed workers.
		 *
		 * @param array $sample Normalized sample.
		 * @return void
		 * @since NEXT
		 */
		private static function store_sample( array $sample ): void {
			// Attach timestamp so flush can bucket by sample day, not flush day.
			$sample['_ts']           = time();
			self::$shutdown_buffer[] = $sample;

			if ( ! self::$shutdown_registered ) {
				self::$shutdown_registered = true;
				if ( function_exists( 'add_action' ) ) {
					add_action( 'shutdown', array( self::class, 'flush_shutdown_buffer' ) );
				}
			}

			// For the common single-beacon-per-request case, avoid an extra
			// get_transient per beacon. Only flush immediately when the
			// per-request buffer itself hits the threshold (e.g. 20 beacons
			// coalesced via keep-alive), otherwise defer to shutdown / cron.
			if ( count( self::$shutdown_buffer ) >= self::FLUSH_THRESHOLD ) {
				self::flush_shutdown_buffer();
				self::flush_queue();
			} elseif ( wp_rand( 1, 10 ) === 1 ) {
				self::flush_shutdown_buffer();
				self::flush_queue();
			} elseif ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
				// Ensure a cron will eventually flush the queue even on low traffic.
				if ( ! wp_next_scheduled( 'wppo_rum_flush' ) ) {
					wp_schedule_single_event( time() + 300, 'wppo_rum_flush' );
				}
			}
		}

		/**
		 * Flush the per-request shutdown buffer to the transient queue.
		 *
		 * Coalesces all samples collected in the current request into a single
		 * get/set_transient pair. Safe to call multiple times (second call is
		 * a no-op when the buffer is empty) and preserves the existing
		 * QUEUE_MAX cap.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function flush_shutdown_buffer(): void {
			if ( empty( self::$shutdown_buffer ) ) {
				return;
			}
			$queue_key = Util::transient_key( self::QUEUE_KEY );
			$queue     = get_transient( $queue_key );
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}
			foreach ( self::$shutdown_buffer as $sample ) {
				$queue[] = $sample;
			}
			if ( count( $queue ) > self::QUEUE_MAX ) {
				$queue = array_slice( $queue, -self::QUEUE_MAX );
			}
			set_transient( $queue_key, $queue, HOUR_IN_SECONDS );
			self::$shutdown_buffer = array();
		}

		/**
		 * Flush queued RUM samples to the persistent aggregate option.
		 *
		 * Batched to perform a single get_option/update_option for up to
		 * QUEUE_MAX samples, with a transient lock to prevent concurrent
		 * flushes from duplicating or losing samples.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function flush_queue(): void {
			// Ensure any samples buffered for this request are materialized
			// before the lock check so a threshold-triggered flush in the same
			// request does not lose data.
			self::flush_shutdown_buffer();

			$lock_key = Util::transient_key( self::FLUSH_LOCK_KEY );
			if ( get_transient( $lock_key ) ) {
				return;
			}
			set_transient( $lock_key, 1, 30 );
			try {
				$queue_key = Util::transient_key( self::QUEUE_KEY );
				$queue     = get_transient( $queue_key );
				if ( empty( $queue ) || ! is_array( $queue ) ) {
					return;
				}
				// Copy and clear queue before processing so new beacons arriving
				// during aggregation queue separately.
				delete_transient( $queue_key );

				$all = get_option( self::OPTION, array() );
				if ( ! is_array( $all ) ) {
					$all = array();
				}

				foreach ( $queue as $sample ) {
					$ts   = isset( $sample['_ts'] ) ? (int) $sample['_ts'] : time();
					$date = gmdate( 'Y-m-d', $ts );
					$path = $sample['path'];

					if ( ! isset( $all[ $date ] ) ) {
						$all[ $date ] = array();
					}
					$day    = $all[ $date ];
					$bucket = isset( $day[ $path ] ) ? $day[ $path ] : array();

					foreach ( array( 'ttfb', 'fcp', 'lcp', 'inp', 'cls' ) as $metric ) {
						if ( ! isset( $sample[ $metric ] ) ) {
							continue;
						}
						$value = (float) $sample[ $metric ];
						if ( ! isset( $bucket[ $metric ] ) ) {
							$bucket[ $metric ] = array(
								'n'   => 0,
								'sum' => 0.0,
								'min' => $value,
								'max' => $value,
							);
						}
						++$bucket[ $metric ]['n'];
						$bucket[ $metric ]['sum'] += $value;
						$bucket[ $metric ]['min']  = min( $bucket[ $metric ]['min'], $value );
						$bucket[ $metric ]['max']  = max( $bucket[ $metric ]['max'], $value );
					}

					$day[ $path ] = $bucket;

					// Bound paths per day.
					$path_total = count( $day );
					while ( $path_total > self::MAX_PATHS_PER_DAY ) {
						array_shift( $day );
						--$path_total;
					}
					$all[ $date ] = $day;
				}

				// Drop days older than retention.
				$cutoff = gmdate( 'Y-m-d', time() - ( self::MAX_DAYS * DAY_IN_SECONDS ) );
				foreach ( array_keys( $all ) as $day_key ) {
					if ( $day_key < $cutoff ) {
						unset( $all[ $day_key ] );
					}
				}

				update_option( self::OPTION, $all, false );
			} finally {
				delete_transient( $lock_key );
			}
		}
	}
}
