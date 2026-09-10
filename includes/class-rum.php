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
		 * Maximum path buckets retained across all days (hard option bound).
		 *
		 * Without this, MAX_DAYS × MAX_PATHS_PER_DAY buckets can push the
		 * aggregate option past 1MB on high-traffic sites (audit #888
		 * finding 10). Oldest days are dropped first on write.
		 *
		 * @since NEXT
		 * @var int
		 */
		const MAX_TOTAL_PATHS = 600;

		/**
		 * Serialized-size budget (bytes) for the aggregate option.
		 *
		 * @since NEXT
		 * @var int
		 */
		const MAX_OPTION_BYTES = 491520;

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
		 * Maximum distinct LCP element URLs tracked per path bucket.
		 *
		 * Bounds the `lcpUrls` map added for field-measured LCP targeting
		 * (issue #935) so the aggregate option stays within its byte budget.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_LCP_URLS_PER_PATH = 10;

		/**
		 * Maximum length (chars) accepted for an LCP element URL.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const LCP_URL_MAX_LENGTH = 2048;

		/**
		 * Default sample gate for the field-measured LCP override.
		 *
		 * The top LCP URL for a path overrides the PageSpeed heuristic only
		 * once it has been observed at least this many times.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const FIELD_LCP_DEFAULT_MIN_SAMPLES = 20;

		/**
		 * Freshness window (seconds) for the field-measured LCP override.
		 *
		 * An override whose top URL was last seen longer ago than this
		 * self-corrects back to the heuristic, so a changed hero recovers
		 * within 24h.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const FIELD_LCP_STALE_TTL = 86400;

		/**
		 * Flush when queue reaches this size.
		 *
		 * @var int
		 * @since NEXT
		 */
		private const FLUSH_THRESHOLD = 20;

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
			// Opportunistically flush queued beacons before reading.
			self::flush_queue();
			$data = get_option( self::OPTION, array() );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Enqueue the frontend beacon script on the public site.
		 *
		 * On WP 6.3+ the beacon uses the native `strategy: defer` script args
		 * so the tag prints render-non-blocking with correct execution order.
		 * On older core the legacy boolean `$in_footer` path is kept (fail-open:
		 * the beacon is still collected, just render-blocking).
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

			if ( self::supports_script_strategy() ) {
				wp_enqueue_script(
					'wppo-rum',
					WPPO_PLUGIN_URL . 'build/rum.js',
					$deps,
					$version,
					array(
						'strategy'  => 'defer',
						'in_footer' => true,
					)
				);
				return;
			}

			wp_enqueue_script( 'wppo-rum', WPPO_PLUGIN_URL . 'build/rum.js', $deps, $version, true );
		}

		/**
		 * Whether core supports the native `strategy` script args (WP 6.3+).
		 *
		 * @since NEXT
		 * @return bool
		 */
		private static function supports_script_strategy(): bool {
			$wp_version = (string) ( $GLOBALS['wp_version'] ?? get_bloginfo( 'version' ) );
			return version_compare( $wp_version, '6.3-alpha', '>=' );
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

			$parsed_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
			// Fallback to strict check to prevent '0' being treated as false.
			// Normalized (trailing slash trimmed, '/' kept for root) so the
			// token scope, the stored bucket key, and the field-LCP lookup
			// in get_field_lcp_url() all agree (issue #935 review).
			$raw_path = is_string( $parsed_path ) && '' !== $parsed_path ? substr( $parsed_path, 0, 512 ) : '/';
			$path     = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( esc_url_raw( $raw_path ) ) : '/';

			$config = array(
				'apiUrl' => esc_url_raw( rest_url( 'performance-optimisation/v1/rum_collect' ) ),
				'token'  => self::token_for( time(), $path ),
				'path'   => $path,
			);

			// JSON_HEX_* flags escape <, >, ', " and & so a crafted REQUEST_URI
			// path can never split out of the <script> element (audit #888
			// finding 9). wp_print_inline_script_tag() handles the surrounding
			// markup + attribute escaping.
			$javascript = 'window.wppoRum=' . wp_json_encode(
				$config,
				JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
			) . ';';

			wp_print_inline_script_tag( $javascript, array( 'id' => 'wppo-rum-config' ) );
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
			// Normalize path so token validation matches sanitize_sample()
			// and print_config() — token scope must match the stored bucket key.
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
				$path = \PerformanceOptimise\Inc\Util::normalize_rum_path( $path );
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
			// Normalize identically to print_config() and get_field_lcp_url()
			// so '/hero-page/' and '/hero-page' share one bucket.
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
				$path = \PerformanceOptimise\Inc\Util::normalize_rum_path( $path );
			}

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

			// Optional field-measured LCP element URL (issue #935). Rides along
			// with a valid numeric sample; never a substitute for one. Rejects
			// data:/javascript:/blob: URIs and caps length so a crafted beacon
			// cannot bloat the aggregate option. Only same-origin URLs are
			// accepted (root-relative or matching the home host) so an
			// anonymous client holding the public per-path page token cannot
			// steer the site's LCP preload to an attacker-chosen host.
			if ( isset( $params['lcpUrl'] ) && is_string( $params['lcpUrl'] ) ) {
				$lcp_url = trim( substr( $params['lcpUrl'], 0, self::LCP_URL_MAX_LENGTH ) );
				if ( '' !== $lcp_url
				&& 0 !== strpos( $lcp_url, 'data:' )
				&& 0 !== stripos( $lcp_url, 'javascript:' )
				&& 0 !== strpos( $lcp_url, 'blob:' )
				&& ( 0 === strpos( $lcp_url, 'http://' ) || 0 === strpos( $lcp_url, 'https://' ) || 0 === strpos( $lcp_url, '/' ) )
				&& self::is_same_origin_lcp_url( $lcp_url )
				) {
					$sample['lcpUrl'] = $lcp_url;
				}
			}

			return $sample;
		}

		/**
		 * Whether an LCP element URL is same-origin with this site.
		 *
		 * Root-relative paths ('/...') are always accepted. Absolute URLs
		 * must carry a host identical (case-insensitive) to the home host;
		 * anything else (including protocol-relative URLs with a foreign
		 * host) is rejected so the public beacon cannot inject a
		 * cross-origin preload target.
		 *
		 * @since NEXT
		 * @param string $lcp_url Candidate LCP URL.
		 * @return bool True when same-origin.
		 */
		private static function is_same_origin_lcp_url( string $lcp_url ): bool {
			if ( 0 === strpos( $lcp_url, '/' ) && 0 !== strpos( $lcp_url, '//' ) ) {
				return true;
			}
			try {
				$host = strtolower( (string) wp_parse_url( $lcp_url, PHP_URL_HOST ) );
				if ( '' === $host ) {
					return false;
				}
				$home_url = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::cached_home_url() : ( function_exists( 'home_url' ) ? home_url() : '' );
				$home     = strtolower( (string) wp_parse_url( $home_url, PHP_URL_HOST ) );
				return '' !== $home && $host === $home;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Buffer a sample to a transient queue and flush periodically.
		 *
		 * Replaces the previous per-beacon get_option+update_option with a
		 * transient queue that is flushed in batches, reducing option
		 * writes from 1 per beacon to ~1 per FLUSH_THRESHOLD beacons.
		 *
		 * @param array $sample Normalized sample.
		 * @return void
		 * @since NEXT
		 */
		private static function store_sample( array $sample ): void {
			// Attach timestamp so flush can bucket by sample day, not flush day.
			$sample['_ts'] = time();
			$queue_key     = Util::transient_key( self::QUEUE_KEY );
			$queue         = get_transient( $queue_key );
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}
			$queue[] = $sample;
			if ( count( $queue ) > self::QUEUE_MAX ) {
				$queue = array_slice( $queue, -self::QUEUE_MAX );
			}
			set_transient( $queue_key, $queue, HOUR_IN_SECONDS );

			if ( count( $queue ) >= self::FLUSH_THRESHOLD ) {
				self::flush_queue();
			} elseif ( 1 === wp_rand( 1, 10 ) ) {
				self::flush_queue();
			} elseif ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
				// Ensure a cron will eventually flush the queue even on low traffic.
				if ( ! wp_next_scheduled( 'wppo_rum_flush' ) ) {
					wp_schedule_single_event( time() + 300, 'wppo_rum_flush' );
				}
			}
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

					// Field-measured LCP element URLs (issue #935): count
					// normalized URLs per path with a bounded map, keeping the
					// first-seen raw URL for preload output. Evicts the
					// lowest-count/oldest entry when over budget.
					if ( isset( $sample['lcpUrl'] ) && is_string( $sample['lcpUrl'] ) && '' !== $sample['lcpUrl'] ) {
						$normalized = ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_url' ) )
						? \PerformanceOptimise\Inc\Util::normalize_url( $sample['lcpUrl'] )
						: '';
						if ( '' !== $normalized ) {
							if ( ! isset( $bucket['lcpUrls'] ) || ! is_array( $bucket['lcpUrls'] ) ) {
								$bucket['lcpUrls'] = array();
							}
							if ( isset( $bucket['lcpUrls'][ $normalized ] ) ) {
								++$bucket['lcpUrls'][ $normalized ]['n'];
								$bucket['lcpUrls'][ $normalized ]['lastSeen'] = $ts;
							} else {
								$bucket['lcpUrls'][ $normalized ] = array(
									'url'      => substr( $sample['lcpUrl'], 0, self::LCP_URL_MAX_LENGTH ),
									'n'        => 1,
									'lastSeen' => $ts,
								);
							}
							$lcp_urls_count = count( $bucket['lcpUrls'] );
							while ( $lcp_urls_count > self::MAX_LCP_URLS_PER_PATH ) {
								$evict_key = null;
								$evict_n   = null;
								$evict_ts  = null;
								foreach ( $bucket['lcpUrls'] as $key => $entry ) {
									$entry_n  = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
									$entry_ts = isset( $entry['lastSeen'] ) ? (int) $entry['lastSeen'] : 0;
									if ( null === $evict_key || $entry_n < $evict_n || ( $entry_n === $evict_n && $entry_ts < $evict_ts ) ) {
										$evict_key = $key;
										$evict_n   = $entry_n;
										$evict_ts  = $entry_ts;
									}
								}
								if ( null === $evict_key ) {
									break;
								}
								unset( $bucket['lcpUrls'][ $evict_key ] );
								--$lcp_urls_count;
							}
						}
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

				// Hard cap the option size (audit #888 finding 10): 14 days ×
				// 200 paths × 5 metrics can exceed 1MB on high-traffic sites.
				// Oldest days (and, when only one day remains, its oldest
				// paths) are dropped until both the bucket count and the
				// serialized size stay under budget, regardless of traffic.
				while ( ! empty( $all ) ) {
					$encoded     = wp_json_encode( $all );
					$total_paths = 0;
					foreach ( $all as $day_bucket ) {
						$total_paths += count( is_array( $day_bucket ) ? $day_bucket : array() );
					}

					$under_path_budget = $total_paths <= self::MAX_TOTAL_PATHS;
					// A failed encode is treated as over budget so the loop
					// makes progress (drops the oldest day) instead of
					// persisting potentially oversized data.
					$under_byte_budget = false !== $encoded && strlen( (string) $encoded ) <= self::MAX_OPTION_BYTES;

					if ( $under_path_budget && $under_byte_budget ) {
						break;
					}

					$oldest_day_key = array_key_first( $all );
					if ( null === $oldest_day_key ) {
						break;
					}

					if ( 1 === count( $all ) && is_array( $all[ $oldest_day_key ] ) ) {
						// Never drop the only day entirely — halve it and stop.
						// Defensive: a single day is already bounded by
						// MAX_PATHS_PER_DAY paths, so the byte budget should
						// hold; this keeps a pathological day from being
						// discarded wholesale before the loop stops.
						$half = array_slice( $all[ $oldest_day_key ], (int) ( count( $all[ $oldest_day_key ] ) / 2 ) );
						if ( ! empty( $half ) ) {
							$all[ $oldest_day_key ] = $half;
						}
						break;
					}
					unset( $all[ $oldest_day_key ] );
				}

				update_option( self::OPTION, $all, false );
			} finally {
				delete_transient( $lock_key );
			}
		}

		/**
		 * Get the field-measured LCP URL for a page path.
		 *
		 * Returns the most-observed LCP element URL for the path only when it
		 * has been seen at least the configured minimum number of times
		 * (defaults to 20) and was last seen within the last 24h, so a
		 * changed hero self-corrects back to the heuristic. Returns null
		 * otherwise so callers fall through to the PageSpeed heuristic.
		 *
		 * @since NEXT
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request path.
		 * @return array{url:string,n:int,lastSeen:int}|null Top LCP URL entry or null.
		 */
		public static function get_field_lcp_url( ?string $path = null ): ?array {
			try {
				if ( null === $path ) {
					$raw_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
					$path     = is_string( $raw_path ) && '' !== $raw_path ? substr( $raw_path, 0, 512 ) : '/';
				}
				if ( '' === $path ) {
					return null;
				}
				// Normalize identically to sanitize_sample()/print_config()
				// so trailing-slash variants share one bucket (issue #935).
				$normalized_path = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( $path ) : $path;
				$options         = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::get_settings() : array();
				$min             = isset( $options['image_optimisation']['fieldLcpMinSamples'] ) ? (int) $options['image_optimisation']['fieldLcpMinSamples'] : self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				if ( $min < 1 ) {
					$min = self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				}
				$all = get_option( self::OPTION, array() );
				if ( ! is_array( $all ) || empty( $all ) ) {
					return null;
				}
				$now  = time();
				$best = array();
				foreach ( $all as $day_bucket ) {
					if ( ! is_array( $day_bucket ) ) {
						continue;
					}
					// Match the normalized path against normalized bucket
					// keys so legacy trailing-slash buckets ('/hero-page/')
					// still resolve after the store side was normalized.
					foreach ( $day_bucket as $bucket_path => $bucket ) {
						if ( ! is_array( $bucket ) ) {
							continue;
						}
						$bucket_norm = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( (string) $bucket_path ) : (string) $bucket_path;
						if ( $bucket_norm !== $normalized_path ) {
							continue;
						}
						$urls = $bucket['lcpUrls'] ?? null;
						if ( ! is_array( $urls ) ) {
							continue;
						}
						foreach ( $urls as $entry ) {
							if ( ! is_array( $entry ) || empty( $entry['url'] ) || ! is_string( $entry['url'] ) ) {
								continue;
							}
							// Aggregate by normalized URL (fall back to raw
							// when unparseable) so http vs https vs
							// root-relative observations of the same image
							// share one candidate and jointly pass the
							// sample gate. Keep the highest-n raw URL for
							// preload output.
							$norm = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_url( $entry['url'] ) : '';
							$key  = ( '' !== $norm ) ? $norm : $entry['url'];
							if ( ! isset( $best[ $key ] ) ) {
								$best[ $key ] = array(
									'url'      => $entry['url'],
									'n'        => 0,
									'lastSeen' => 0,
								);
							}
							$entry_n                  = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
							$best[ $key ]['n']       += $entry_n;
							$best[ $key ]['lastSeen'] = max( $best[ $key ]['lastSeen'], isset( $entry['lastSeen'] ) ? (int) $entry['lastSeen'] : 0 );
							// Prefer the raw URL variant with the most
							// observations for output.
							$best[ $key ]['_raw_n'] = ( $best[ $key ]['_raw_n'] ?? 0 );
							if ( $entry_n >= $best[ $key ]['_raw_n'] ) {
								$best[ $key ]['url']    = $entry['url'];
								$best[ $key ]['_raw_n'] = $entry_n;
							}
						}
					}
				}
				if ( empty( $best ) ) {
					return null;
				}
				$top = null;
				foreach ( $best as $entry ) {
					if ( null === $top || $entry['n'] > $top['n'] ) {
						$top = $entry;
					}
				}
				if ( null === $top || $top['n'] < $min ) {
					return null;
				}
				if ( $top['lastSeen'] <= 0 || ( $now - $top['lastSeen'] ) > self::FIELD_LCP_STALE_TTL ) {
					return null;
				}
				unset( $top['_raw_n'] );
				return $top;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return null;
			}
		}
	}
}
