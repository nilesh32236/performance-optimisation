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
		 * Maximum device × template segments tracked per path bucket.
		 *
		 * Bounds the `lcpSeg` map added for field-LCP p75 routing
		 * (issue #986) so the aggregate option stays within its byte budget.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_LCP_SEGMENTS_PER_PATH = 6;

		/**
		 * Maximum LCP samples retained per device × template segment.
		 *
		 * Capped reservoir (most-recent values) used solely for p75
		 * computation; oldest values are dropped first.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_LCP_SAMPLES_PER_SEGMENT = 100;

		/**
		 * Maximum distinct INP device × template segments tracked per path bucket.
		 *
		 * Bounds the `inpSeg` map added for RUM-gated INP-aware delay
		 * suggestions (issue #1036) so the aggregate option stays within its
		 * byte budget. Mirrors MAX_LCP_SEGMENTS_PER_PATH.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_INP_SEGMENTS_PER_PATH = 6;

		/**
		 * Maximum INP samples retained per device × template segment.
		 *
		 * Capped most-recent reservoir used solely for p75 computation;
		 * oldest values are dropped first. Mirrors MAX_LCP_SAMPLES_PER_SEGMENT.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_INP_SAMPLES_PER_SEGMENT = 100;

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
		 * Per-request memo for the RUM aggregate option.
		 *
		 * The get_field_lcp_url() hot path runs on the frontend output
		 * is called twice per page view (Image_Optimisation). Without a memo
		 * each call deserializes the full wppo_web_vitals_rum aggregate
		 * (bounded only by MAX_OPTION_BYTES). Memoizing collapses both
		 * lookups to a single get_option() per request.
		 *
		 * @since NEXT
		 * @var array|null
		 */
		private static ?array $field_lcp_aggregate = null;

		/**
		 * Whether the aggregate memo has been populated this request.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $field_lcp_loaded = false;

		/**
		 * Per-request memo for resolved field-LCP results, keyed by
		 * normalized path + sample gate.
		 *
		 * @since NEXT
		 * @var array<string, array|null>
		 */
		private static array $field_lcp_result_memo = array();

		/**
		 * Per-request memo for the Web Vitals trends option.
		 *
		 * Queue ordering calls score_url_lcp() once per candidate URL when
		 * ordering the critical-CSS / used-CSS queues (issue #1059 review); without a memo
		 * each call re-reads/deserializes the full wppo_web_vitals_trends
		 * option. An empty array is a valid result, so the loaded flag tracks
		 * fetch state separately from the value.
		 *
		 * @since NEXT
		 * @var array|null
		 */
		private static ?array $score_trends_memo = null;

		/**
		 * Whether the trends memo has been populated this request.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $score_trends_loaded = false;

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
		 * Clear the per-request field-LCP memo.
		 *
		 * Called automatically on update/add/delete of the RUM aggregate
		 * option; exposed publicly so tests can reset isolation between
		 * cases that mutate the option store directly.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function clear_field_lcp_cache(): void {
			self::$field_lcp_aggregate   = null;
			self::$field_lcp_loaded      = false;
			self::$field_lcp_result_memo = array();
			self::$score_trends_memo     = null;
			self::$score_trends_loaded   = false;
		}

		/**
		 * Get the RUM aggregate with a per-request memo.
		 *
		 * @since NEXT
		 * @return array Aggregate data (empty array when missing/invalid).
		 */
		private static function get_memoized_aggregate(): array {
			if ( self::$field_lcp_loaded && is_array( self::$field_lcp_aggregate ) ) {
				return self::$field_lcp_aggregate;
			}
			self::ensure_field_lcp_cache_hook();
			$all = get_option( self::OPTION, array() );
			if ( ! is_array( $all ) ) {
				$all = array();
			}
			self::$field_lcp_aggregate = $all;
			self::$field_lcp_loaded    = true;
			return $all;
		}

		/**
		 * Register invalidation hooks for the RUM aggregate memo (once per request).
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function ensure_field_lcp_cache_hook(): void {
			static $hooked = false;
			if ( $hooked ) {
				return;
			}
			$hooked = true;
			add_action( 'update_option_' . self::OPTION, array( self::class, 'clear_field_lcp_cache' ) );
			add_action( 'add_option_' . self::OPTION, array( self::class, 'clear_field_lcp_cache' ) );
			add_action( 'delete_option_' . self::OPTION, array( self::class, 'clear_field_lcp_cache' ) );
		}

		/**
		 * Migrate the RUM aggregate option to non-autoloading.
		 *
		 * Rows created by older plugin versions defaulted to autoload=yes and
		 * keep loading on every WordPress request via alloptions. Mirrors
		 * Img_Converter::migrate_img_info_autoload().
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function migrate_rum_autoload(): void {
			if ( function_exists( 'wp_set_option_autoload' ) ) {
				wp_set_option_autoload( self::OPTION, false );
			} else {
				global $wpdb;
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$wpdb->options,
					array( 'autoload' => 'no' ),
					array( 'option_name' => self::OPTION )
				);
				wp_cache_delete( self::OPTION, 'options' );
				wp_cache_delete( 'alloptions', 'options' );
			}
			self::clear_field_lcp_cache();
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
			self::clear_field_lcp_cache();
			$data = get_option( self::OPTION, array() );
			return is_array( $data ) ? $data : array();
		}

		/**
		 * Retrieve the aggregated RUM data without side effects (read-only).
		 *
		 * Unlike get_data(), this never flushes the queued-beacon queue and
		 * never touches transients: it serves the per-request memoized
		 * aggregate (a single get_option() deserialization shared with the
		 * segmented field-LCP/INP readers). Intended for frontend hot paths
		 * such as the speculation-rules filter. Fail-open: any failure
		 * returns array().
		 *
		 * @return array Aggregate data (empty array when missing/invalid).
		 * @since NEXT
		 */
		public static function get_aggregate_readonly(): array {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return array();
				}
				$all = self::get_memoized_aggregate();
				return is_array( $all ) ? $all : array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
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

			// Optional template dimension for device × template p75 routing
			// (issue #986). Additive only: omitted when undetectable so the
			// beacon contract stays backward compatible.
			$template = self::detect_template_slug();
			if ( '' !== $template ) {
				$config['template'] = $template;
			}

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
		 * Detect the current template slug for RUM segmentation.
		 *
		 * Fail-open: returns '' when undetectable so the beacon field is
		 * omitted and aggregation falls back to the `unknown` bucket.
		 *
		 * @since NEXT
		 * @return string Template slug (max 64 chars) or ''.
		 */
		private static function detect_template_slug(): string {
			try {
				$slug = '';
				if ( function_exists( 'get_page_template_slug' ) ) {
					$queried = function_exists( 'get_queried_object_id' ) ? get_queried_object_id() : 0;
					$slug    = (string) get_page_template_slug( $queried ? $queried : null );
				}
				if ( '' === $slug && function_exists( 'get_page_template' ) ) {
					$tpl = (string) get_page_template();
					if ( '' !== $tpl ) {
						$slug = (string) basename( $tpl, '.php' );
					}
				}
				if ( '' === $slug ) {
					return '';
				}
				$slug = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $slug ) : $slug;
				$slug = strtolower( substr( $slug, 0, 64 ) );
				$slug = (string) preg_replace( '/[^a-z0-9_-]/', '', $slug );
				return substr( $slug, 0, 64 );
			} catch ( \Throwable $e ) {
				return '';
			}
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

			// Optional device × template segmentation (issue #986). Fail-open:
			// missing/invalid values fall back to `unknown` and never reject
			// the sample — the numeric path above is unchanged.
			$device = 'unknown';
			if ( isset( $params['device'] ) && is_string( $params['device'] ) ) {
				$candidate = strtolower( trim( substr( $params['device'], 0, 16 ) ) );
				if ( 'mobile' === $candidate || 'desktop' === $candidate ) {
					$device = $candidate;
				}
			}
			$sample['device'] = $device;

			$template = 'unknown';
			if ( isset( $params['template'] ) && is_string( $params['template'] ) ) {
				$raw = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $params['template'] ) : $params['template'];
				$raw = strtolower( trim( substr( $raw, 0, 64 ) ) );
				$raw = function_exists( 'preg_replace' ) ? (string) preg_replace( '/[^a-z0-9_-]/', '', $raw ) : $raw;
				if ( '' !== $raw ) {
					$template = substr( $raw, 0, 64 );
				}
			}
			$sample['template'] = $template;

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

					// Device × template LCP segments (issue #986): bounded per-path
					// `lcpSeg` map keyed `{device}|{template}` holding n/sum/
					// min/max plus a capped most-recent reservoir for p75.
					// Evicts the lowest-n segment when over budget. Reuses the
					// existing option byte-budget loop below so the size cap
					// still holds; no new option or transient names.
					if ( isset( $sample['lcp'] ) ) {
						$lcp_value = (float) $sample['lcp'];
						// Re-sanitize even though the beacon sanitizes at
						// intake: the queue transient is user-writable, so
						// allowlist the device and text-sanitize the template
						// before either is persisted into the aggregate option.
						$raw_device = isset( $sample['device'] ) && is_string( $sample['device'] ) ? sanitize_text_field( $sample['device'] ) : '';
						$device     = strtolower( trim( $raw_device ) );
						if ( 'mobile' !== $device && 'desktop' !== $device ) {
							$device = 'unknown';
						}
						$raw_template = isset( $sample['template'] ) && is_string( $sample['template'] ) ? sanitize_text_field( $sample['template'] ) : '';
						$template     = '' !== trim( $raw_template ) ? substr( $raw_template, 0, 64 ) : 'unknown';
						$seg_key      = $device . '|' . $template;
						if ( ! isset( $bucket['lcpSeg'] ) || ! is_array( $bucket['lcpSeg'] ) ) {
							$bucket['lcpSeg'] = array();
						}
						if ( ! isset( $bucket['lcpSeg'][ $seg_key ] ) || ! is_array( $bucket['lcpSeg'][ $seg_key ] ) ) {
							$bucket['lcpSeg'][ $seg_key ] = array(
								'device'   => $device,
								'template' => $template,
								'n'        => 0,
								'sum'      => 0.0,
								'min'      => $lcp_value,
								'max'      => $lcp_value,
								'samples'  => array(),
							);
						}
						++$bucket['lcpSeg'][ $seg_key ]['n'];
						$bucket['lcpSeg'][ $seg_key ]['sum'] += $lcp_value;
						$bucket['lcpSeg'][ $seg_key ]['min']  = min( $bucket['lcpSeg'][ $seg_key ]['min'], $lcp_value );
						$bucket['lcpSeg'][ $seg_key ]['max']  = max( $bucket['lcpSeg'][ $seg_key ]['max'], $lcp_value );
						$samples                              = isset( $bucket['lcpSeg'][ $seg_key ]['samples'] ) && is_array( $bucket['lcpSeg'][ $seg_key ]['samples'] ) ? $bucket['lcpSeg'][ $seg_key ]['samples'] : array();
						$samples[]                            = $lcp_value;
						if ( count( $samples ) > self::MAX_LCP_SAMPLES_PER_SEGMENT ) {
							$samples = array_slice( $samples, -self::MAX_LCP_SAMPLES_PER_SEGMENT );
						}
						$bucket['lcpSeg'][ $seg_key ]['samples'] = array_values( $samples );
						$lcp_seg_count                           = count( $bucket['lcpSeg'] );
						while ( $lcp_seg_count > self::MAX_LCP_SEGMENTS_PER_PATH ) {
							$evict_key = null;
							$evict_n   = null;
							foreach ( $bucket['lcpSeg'] as $key => $entry ) {
								$entry_n = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
								if ( null === $evict_key || $entry_n < $evict_n ) {
									$evict_key = $key;
									$evict_n   = $entry_n;
								}
							}
							if ( null === $evict_key ) {
								break;
							}
							unset( $bucket['lcpSeg'][ $evict_key ] );
							--$lcp_seg_count;
						}
					}

					// Device × template INP segments (issue #1036): bounded per-path
					// `inpSeg` map keyed `{device}|{template}` holding n/sum/
					// min/max plus a capped most-recent reservoir for p75.
					// Mirrors the `lcpSeg` block above; reuses the same device/
					// template sanitization and the existing option byte-budget
					// loop below. No new option or transient names. Fail-open:
					// any malformed queue entry is skipped, never fatal.
					if ( isset( $sample['inp'] ) ) {
						$inp_value      = (float) $sample['inp'];
						$raw_device_inp = isset( $sample['device'] ) && is_string( $sample['device'] ) ? sanitize_text_field( $sample['device'] ) : '';
						$device_inp     = strtolower( trim( $raw_device_inp ) );
						if ( 'mobile' !== $device_inp && 'desktop' !== $device_inp ) {
							$device_inp = 'unknown';
						}
						$raw_template_inp = isset( $sample['template'] ) && is_string( $sample['template'] ) ? sanitize_text_field( $sample['template'] ) : '';
						$template_inp     = '' !== trim( $raw_template_inp ) ? substr( $raw_template_inp, 0, 64 ) : 'unknown';
						$inp_seg_key      = $device_inp . '|' . $template_inp;
						if ( ! isset( $bucket['inpSeg'] ) || ! is_array( $bucket['inpSeg'] ) ) {
							$bucket['inpSeg'] = array();
						}
						if ( ! isset( $bucket['inpSeg'][ $inp_seg_key ] ) || ! is_array( $bucket['inpSeg'][ $inp_seg_key ] ) ) {
							$bucket['inpSeg'][ $inp_seg_key ] = array(
								'device'   => $device_inp,
								'template' => $template_inp,
								'n'        => 0,
								'sum'      => 0.0,
								'min'      => $inp_value,
								'max'      => $inp_value,
								'samples'  => array(),
							);
						}
						++$bucket['inpSeg'][ $inp_seg_key ]['n'];
						$bucket['inpSeg'][ $inp_seg_key ]['sum'] += $inp_value;
						$bucket['inpSeg'][ $inp_seg_key ]['min']  = min( $bucket['inpSeg'][ $inp_seg_key ]['min'], $inp_value );
						$bucket['inpSeg'][ $inp_seg_key ]['max']  = max( $bucket['inpSeg'][ $inp_seg_key ]['max'], $inp_value );
						$inp_samples                              = isset( $bucket['inpSeg'][ $inp_seg_key ]['samples'] ) && is_array( $bucket['inpSeg'][ $inp_seg_key ]['samples'] ) ? $bucket['inpSeg'][ $inp_seg_key ]['samples'] : array();
						$inp_samples[]                            = $inp_value;
						if ( count( $inp_samples ) > self::MAX_INP_SAMPLES_PER_SEGMENT ) {
							$inp_samples = array_slice( $inp_samples, -self::MAX_INP_SAMPLES_PER_SEGMENT );
						}
						$bucket['inpSeg'][ $inp_seg_key ]['samples'] = array_values( $inp_samples );
						$inp_seg_count                               = count( $bucket['inpSeg'] );
						while ( $inp_seg_count > self::MAX_INP_SEGMENTS_PER_PATH ) {
							$evict_key = null;
							$evict_n   = null;
							foreach ( $bucket['inpSeg'] as $key => $entry ) {
								$entry_n = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
								if ( null === $evict_key || $entry_n < $evict_n ) {
									$evict_key = $key;
									$evict_n   = $entry_n;
								}
							}
							if ( null === $evict_key ) {
								break;
							}
							unset( $bucket['inpSeg'][ $evict_key ] );
							--$inp_seg_count;
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
					$total_paths = 0;
					foreach ( $all as $day_bucket ) {
						$total_paths += count( is_array( $day_bucket ) ? $day_bucket : array() );
					}

					$under_path_budget = $total_paths <= self::MAX_TOTAL_PATHS;
					if ( ! $under_path_budget ) {
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
						continue;
					}

					$encoded = wp_json_encode( $all );
					// A failed encode is treated as over budget so the loop
					// makes progress (drops the oldest day) instead of
					// persisting potentially oversized data.
					$under_byte_budget = false !== $encoded && strlen( (string) $encoded ) <= self::MAX_OPTION_BYTES;

					if ( $under_byte_budget ) {
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
				self::clear_field_lcp_cache();
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
				// Per-request memo: the frontend calls this twice per page
				// view, so collapse to one get_option() deserialization.
				$memo_key = $normalized_path . '|' . $min;
				if ( array_key_exists( $memo_key, self::$field_lcp_result_memo ) ) {
					return self::$field_lcp_result_memo[ $memo_key ];
				}
				$all = self::get_memoized_aggregate();
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
					self::$field_lcp_result_memo[ $memo_key ] = null;
					return null;
				}
				$top = null;
				foreach ( $best as $entry ) {
					if ( null === $top || $entry['n'] > $top['n'] ) {
						$top = $entry;
					}
				}
				if ( null === $top || $top['n'] < $min ) {
					self::$field_lcp_result_memo[ $memo_key ] = null;
					return null;
				}
				if ( $top['lastSeen'] <= 0 || ( $now - $top['lastSeen'] ) > self::FIELD_LCP_STALE_TTL ) {
					self::$field_lcp_result_memo[ $memo_key ] = null;
					return null;
				}
				unset( $top['_raw_n'] );
				self::$field_lcp_result_memo[ $memo_key ] = $top;
				return $top;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return null;
			}
		}

		/**
		 * Get the single LCP preload candidate for a page path.
		 *
		 * Field-measured RUM data wins when it passes the sample gate
		 * (see {@see get_field_lcp_url()}); otherwise the stored PageSpeed
		 * candidate for the same page is returned (synthesized as
		 * `array{url,n:0,lastSeen:now}` so callers share one shape).
		 * The `$path` governs both lookups: the singular-post-meta and
		 * front-page-option PageSpeed tiers are current-request context and
		 * are only consulted when `$path` is null, while the transient tier
		 * resolves by the given path (see {@see get_stored_pagespeed_lcp_url()}).
		 * Returns null when neither resolves so callers fall through to the
		 * manual preload-image meta / hero path. Fail-open: any failure
		 * returns null, never fatal.
		 *
		 * @since NEXT
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request path.
		 * @return array{url:string,n:int,lastSeen:int}|null Single LCP candidate or null.
		 */
		public static function get_lcp_preload_candidate( ?string $path = null ): ?array {
			try {
				if ( null === $path ) {
					$path = self::resolve_current_path();
				}
				$field = self::get_field_lcp_url( $path );
				if ( is_array( $field ) && ! empty( $field['url'] ) && is_string( $field['url'] ) ) {
					return $field;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				$fallback = self::get_stored_pagespeed_lcp_url( $path );
				if ( '' !== $fallback ) {
					return array(
						'url'      => $fallback,
						'n'        => 0,
						'lastSeen' => time(),
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return null;
		}

		/**
		 * Resolve the current page path the same way the preload pipeline does.
		 *
		 * Prefers `Util::get_current_url()` (the source `get_current_lcp_url()`
		 * derives its path from) and falls back to null so
		 * `get_field_lcp_url()` resolves `$_SERVER['REQUEST_URI']` itself.
		 * Fail-open: any failure returns null.
		 *
		 * @since NEXT
		 * @return string|null Current page path or null when unresolvable.
		 */
		private static function resolve_current_path(): ?string {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && function_exists( 'wp_parse_url' ) ) {
					$parsed = wp_parse_url( \PerformanceOptimise\Inc\Util::get_current_url(), PHP_URL_PATH );
					if ( is_string( $parsed ) && '' !== $parsed ) {
						return substr( $parsed, 0, 512 );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return null;
		}

		/**
		 * Read-only lookup of the stored PageSpeed LCP candidate for a page.
		 *
		 * Single shared implementation of the PageSpeed priorities used by
		 * both `get_lcp_preload_candidate()` and
		 * `Image_Optimisation::get_current_lcp_url()` (singular post meta
		 * `_wppo_lcp_image_url_{mobile,desktop}`, then front-page option
		 * `wppo_front_page_lcp_{...}`, then the transient keyed by strategy
		 * + URL hash) so strategy order and key formats cannot drift apart.
		 * The post-meta and front-page tiers describe the current request and
		 * are only consulted when `$path` is null; the transient tier always
		 * applies, resolving the URL hash from the current URL by default or
		 * from `home_url() + $path` for an explicit path (which therefore
		 * never mixes two different pages). Multisite-safe via
		 * `Util::transient_key()` (blog-aware keys, no cross-site leakage).
		 * Every WP API is guarded so unit contexts without WP fail open to
		 * an empty string.
		 *
		 * @since NEXT
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request URL.
		 * @return string The PageSpeed LCP image URL, or empty string when none is stored.
		 */
		public static function get_stored_pagespeed_lcp_url( ?string $path = null ): string {
			try {
				$strategies = array( 'mobile', 'desktop' );

				// Priority 1: Singular post — check post meta (mobile first, then desktop).
				// Current-request context only: an explicit path cannot be mapped to a post ID.
				if ( null === $path && function_exists( 'is_singular' ) && function_exists( 'get_the_ID' ) && function_exists( 'get_post_meta' ) ) {
					try {
						if ( is_singular() ) {
							$post_id = get_the_ID();
							if ( ! empty( $post_id ) ) {
								foreach ( $strategies as $strategy ) {
									$meta_lcp = get_post_meta( $post_id, '_wppo_lcp_image_url_' . $strategy, true );
									if ( ! empty( $meta_lcp ) && is_string( $meta_lcp ) ) {
										return $meta_lcp;
									}
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				// Priority 2: Front page — check option (mobile first, then desktop).
				// Current-request context only, for the same reason as Priority 1.
				if ( null === $path && function_exists( 'is_front_page' ) && function_exists( 'get_option' ) ) {
					try {
						if ( is_front_page() ) {
							foreach ( $strategies as $strategy ) {
								$front_lcp = get_option( 'wppo_front_page_lcp_' . $strategy, '' );
								if ( ! empty( $front_lcp ) && is_string( $front_lcp ) ) {
									return $front_lcp;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				// Priority 3: Transient keyed by strategy + URL hash.
				if ( ! function_exists( 'get_transient' ) ) {
					return '';
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return '';
				}
				if ( ! function_exists( 'untrailingslashit' ) || ! function_exists( 'esc_url_raw' ) ) {
					return '';
				}
				try {
					if ( null === $path ) {
						$lookup_url = untrailingslashit( esc_url_raw( \PerformanceOptimise\Inc\Util::get_current_url() ) );
					} else {
						$norm_path  = \PerformanceOptimise\Inc\Util::normalize_rum_path( $path );
						$lookup_url = untrailingslashit( esc_url_raw( \PerformanceOptimise\Inc\Util::cached_home_url() . $norm_path ) );
					}
				} catch ( \Throwable $e ) {
					return '';
				}
				if ( '' === $lookup_url ) {
					return '';
				}
				foreach ( $strategies as $strategy ) {
					$transient_key = \PerformanceOptimise\Inc\Util::transient_key( 'wppo_lcp_url_' . $strategy . '_' . md5( $lookup_url ) );
					try {
						$transient = get_transient( $transient_key );
					} catch ( \Throwable $e ) {
						continue;
					}
					if ( ! empty( $transient ) && is_string( $transient ) ) {
						return $transient;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * Resolve the field-LCP minimum-sample threshold for auto-tune.
		 *
		 * Prefers the additive `ai_adaptive.field_lcp_min_samples` setting,
		 * falls back to the legacy `image_optimisation.fieldLcpMinSamples`
		 * for backward compatibility, then to FIELD_LCP_DEFAULT_MIN_SAMPLES.
		 *
		 * Pure read path: no option or transient writes.
		 *
		 * @since NEXT
		 * @return int Minimum samples (>=1).
		 */
		public static function get_field_lcp_min_samples(): int {
			try {
				$options = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::get_settings() : array();
				if ( isset( $options['ai_adaptive']['field_lcp_min_samples'] ) ) {
					$min = (int) $options['ai_adaptive']['field_lcp_min_samples'];
					if ( $min >= 1 ) {
						return $min;
					}
				}
				if ( isset( $options['image_optimisation']['fieldLcpMinSamples'] ) ) {
					$min = (int) $options['image_optimisation']['fieldLcpMinSamples'];
					if ( $min >= 1 ) {
						return $min;
					}
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
			}
			return self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
		}

		/**
		 * Compute the p75 of a numeric sample list.
		 *
		 * Nearest-rank method: sort ascending, pick index ceil(0.75*n)-1.
		 *
		 * @since NEXT
		 * @param float[] $samples Numeric samples.
		 * @return float p75 value or 0.0 when empty.
		 */
		public static function compute_p75( array $samples ): float {
			$values = array_values( array_filter( $samples, 'is_numeric' ) );
			$count  = count( $values );
			if ( 0 === $count ) {
				return 0.0;
			}
			$values = array_map( 'floatval', $values );
			sort( $values, SORT_NUMERIC );
			$rank = (int) ceil( 0.75 * $count ) - 1;
			$rank = max( 0, min( $count - 1, $rank ) );
			return (float) $values[ $rank ];
		}

		/**
		 * Get field LCP p75 segmented by device × template (read-only).
		 *
		 * Pure read path for AI-Adaptive auto-tune (issue #986): reads the
		 * aggregate option only via get_option() — never flushes the queue
		 * and never calls update_option/set_transient, so the frontend
		 * incurs no new writes. Segments across all retained days are merged
		 * by `{path}|{device}|{template}`; only segments with n >=
		 * $min_samples are returned. Fail-open: any failure returns array().
		 *
		 * @since NEXT
		 * @param int|null $min_samples Minimum samples per segment. Null resolves via get_field_lcp_min_samples().
		 * @return array[] Rows of array(path,device,template,n,p75).
		 */
		public static function get_field_lcp_p75_by_segment( ?int $min_samples = null ): array {
			try {
				$min = null === $min_samples ? self::get_field_lcp_min_samples() : (int) $min_samples;
				if ( $min < 1 ) {
					$min = self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				}
				if ( ! function_exists( 'get_option' ) ) {
					return array();
				}
				// Reuse the per-request memo so repeated calls do not each
				// deserialize the full aggregate option. Read-only: the memo
				// never flushes the queue.
				$all = self::get_memoized_aggregate();
				if ( ! is_array( $all ) || empty( $all ) ) {
					return array();
				}
				$merged = array();
				foreach ( $all as $day_bucket ) {
					if ( ! is_array( $day_bucket ) ) {
						continue;
					}
					foreach ( $day_bucket as $bucket_path => $bucket ) {
						if ( ! is_array( $bucket ) || ! isset( $bucket['lcpSeg'] ) || ! is_array( $bucket['lcpSeg'] ) ) {
							continue;
						}
						$path = (string) $bucket_path;
						foreach ( $bucket['lcpSeg'] as $seg ) {
							if ( ! is_array( $seg ) ) {
								continue;
							}
							$device   = isset( $seg['device'] ) && is_string( $seg['device'] ) ? $seg['device'] : 'unknown';
							$template = isset( $seg['template'] ) && is_string( $seg['template'] ) && '' !== $seg['template'] ? $seg['template'] : 'unknown';
							$key      = $path . '|' . $device . '|' . $template;
							if ( ! isset( $merged[ $key ] ) ) {
								$merged[ $key ] = array(
									'path'     => $path,
									'device'   => $device,
									'template' => $template,
									'n'        => 0,
									'samples'  => array(),
								);
							}
							$merged[ $key ]['n'] += isset( $seg['n'] ) ? (int) $seg['n'] : 0;
							$samples              = isset( $seg['samples'] ) && is_array( $seg['samples'] ) ? $seg['samples'] : array();
							foreach ( $samples as $value ) {
								if ( is_numeric( $value ) ) {
									$merged[ $key ]['samples'][] = (float) $value;
								}
							}
						}
					}
				}
				$rows = array();
				foreach ( $merged as $entry ) {
					$n = (int) $entry['n'];
					// p75 is computed from the capped reservoir, not the
					// unbounded accumulator: never qualify or report more
					// observations than actually back the p75 value.
					$sample_count = count( $entry['samples'] );
					if ( $sample_count < $n ) {
						$n = $sample_count;
					}
					if ( $n < $min ) {
						continue;
					}
					$p75    = self::compute_p75( $entry['samples'] );
					$rows[] = array(
						'path'     => $entry['path'],
						'device'   => $entry['device'],
						'template' => $entry['template'],
						'n'        => $n,
						'p75'      => $p75,
					);
				}
				usort(
					$rows,
					static function ( $a, $b ) {
						$pa = isset( $a['p75'] ) ? (float) $a['p75'] : 0.0;
						$pb = isset( $b['p75'] ) ? (float) $b['p75'] : 0.0;
						if ( $pa === $pb ) {
							return 0;
						}
						return $pa > $pb ? -1 : 1;
					}
				);
				return $rows;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return array();
			}
		}

		/**
		 * Get worst p75 LCP per normalized path for CSS queue prioritization.
		 *
		 * Read-only ordering signal for the critical-CSS / used-CSS queues
		 * (issue #1059): collapses get_field_lcp_p75_by_segment() rows to
		 * `path => max(p75)`. Callers blend this map with the latest
		 * PageSpeed trend LCP snapshot per candidate URL via score_url_lcp()
		 * (resolved via md5(esc_url_raw(url)) keys, max of mobile/desktop).
		 * Local aggregates only — no new external calls, no PII. Fail-open:
		 * any failure returns array(). Multisite-safe: per-site get_option()
		 * reads only.
		 *
		 * @since NEXT
		 * @param int|null $min_samples Minimum samples per segment. Null resolves via get_field_lcp_min_samples().
		 * @return array<string, float> Normalized path => worst p75 LCP in ms, worst-first order.
		 */
		public static function get_path_lcp_priority( ?int $min_samples = null ): array {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return array();
				}
				$scores = array();
				$rows   = self::get_field_lcp_p75_by_segment( $min_samples );
				foreach ( $rows as $row ) {
					if ( ! is_array( $row ) || ! isset( $row['path'], $row['p75'] ) ) {
						continue;
					}
					$path = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( (string) $row['path'] ) : (string) $row['path'];
					$p75  = (float) $row['p75'];
					if ( $p75 <= 0 ) {
						continue;
					}
					if ( ! isset( $scores[ $path ] ) || $p75 > $scores[ $path ] ) {
						$scores[ $path ] = $p75;
					}
				}
				arsort( $scores, SORT_NUMERIC );
				return $scores;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return array();
			}
		}

		/**
		 * Score a queue URL by worst p75 LCP (RUM path + trend blend).
		 *
		 * Resolves $url to its normalized path, looks up the RUM priority
		 * map, and takes the max with the latest stored PageSpeed trend LCP
		 * for md5(esc_url_raw($url))_{mobile,desktop} keys. Returns 0.0 when
		 * no signal exists (caller keeps FIFO order). Read-only, fail-open.
		 *
		 * @since NEXT
		 * @param string               $url Candidate queue URL.
		 * @param array<string, float> $priority Optional pre-loaded get_path_lcp_priority() map.
		 * @param array|null           $trends Optional pre-loaded Pagespeed::get_trends() map. Null loads (and per-request memos) it once.
		 * @return float Worst p75 LCP in ms, or 0.0 when unknown.
		 */
		public static function score_url_lcp( string $url, ?array $priority = null, ?array $trends = null ): float {
			try {
				if ( '' === trim( $url ) ) {
					return 0.0;
				}
				if ( null === $priority ) {
					$priority = self::get_path_lcp_priority();
				}
				$path = '/';
				if ( function_exists( 'wp_parse_url' ) ) {
					$parts = wp_parse_url( $url );
					$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
				} elseif ( function_exists( 'parse_url' ) ) {
					$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
					$path  = isset( $parts['path'] ) && '' !== $parts['path'] ? (string) $parts['path'] : '/';
				}
				$path = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( $path ) : $path;
				$best = isset( $priority[ $path ] ) ? (float) $priority[ $path ] : 0.0;

				if ( class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) && method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) && function_exists( 'esc_url_raw' ) && function_exists( 'get_option' ) ) {
					if ( null === $trends ) {
						// Per-request memo: ordering N queue URLs must not
						// re-read/deserialize the full trends option N times
						// (issue #1059 review). An empty array is a valid result,
						// so track fetch state separately from the value. Reset
						// alongside the aggregate memo via clear_field_lcp_cache().
						if ( ! self::$score_trends_loaded ) {
							self::$score_trends_memo   = \PerformanceOptimise\Inc\Pagespeed::get_trends();
							self::$score_trends_loaded = true;
						}
						$trends = is_array( self::$score_trends_memo ) ? self::$score_trends_memo : array();
					}
					if ( is_array( $trends ) ) {
						$canonical = esc_url_raw( $url );
						foreach ( array( 'mobile', 'desktop' ) as $strategy ) {
							$key = md5( $canonical ) . '_' . $strategy;
							if ( ! isset( $trends[ $key ] ) || ! is_array( $trends[ $key ] ) || empty( $trends[ $key ] ) ) {
								continue;
							}
							$snapshots = $trends[ $key ];
							$last      = end( $snapshots );
							if ( is_array( $last ) && isset( $last['lcp'] ) && is_numeric( $last['lcp'] ) ) {
								$best = max( $best, (float) $last['lcp'] );
							}
						}
					}
				}
				return $best >= 0 ? (float) $best : 0.0;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return 0.0;
			}
		}

		/**
		 * Get field INP p75 segmented by device × template (read-only).
		 *
		 * Pure read path for RUM-gated INP-aware delay suggestions (issue
		 * #1036): reads the aggregate option only via get_option() — never
		 * flushes the queue and never calls update_option/set_transient, so
		 * the frontend incurs no new writes. Mirrors
		 * get_field_lcp_p75_by_segment() over the bounded `inpSeg` reservoir
		 * written by flush_queue(). Segments across all retained days are
		 * merged by `{path}|{device}|{template}`; only segments with n >=
		 * $min_samples are returned, slowest-first. Fail-open: any failure
		 * returns array(). No PII: aggregates only (n/samples), no IP/URL
		 * params stored. Multisite-safe: get_option() is inherently
		 * site-specific; queue/lock keys go through Util::transient_key().
		 *
		 * @since NEXT
		 * @param int|null $min_samples Minimum samples per segment. Null resolves via get_field_lcp_min_samples() (shared gate, default 20).
		 * @return array[] Rows of array(path,device,template,n,p75).
		 */
		public static function get_field_inp_p75_by_segment( ?int $min_samples = null ): array {
			try {
				$min = null === $min_samples ? self::get_field_lcp_min_samples() : (int) $min_samples;
				if ( $min < 1 ) {
					$min = self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				}
				if ( ! function_exists( 'get_option' ) ) {
					return array();
				}
				// Reuse the per-request memo so repeated calls do not each
				// deserialize the full aggregate option. Read-only: the memo
				// never flushes the queue.
				$all = self::get_memoized_aggregate();
				if ( ! is_array( $all ) || empty( $all ) ) {
					return array();
				}
				$merged = array();
				foreach ( $all as $day_bucket ) {
					if ( ! is_array( $day_bucket ) ) {
						continue;
					}
					foreach ( $day_bucket as $bucket_path => $bucket ) {
						if ( ! is_array( $bucket ) || ! isset( $bucket['inpSeg'] ) || ! is_array( $bucket['inpSeg'] ) ) {
							continue;
						}
						$path = (string) $bucket_path;
						foreach ( $bucket['inpSeg'] as $seg ) {
							if ( ! is_array( $seg ) ) {
								continue;
							}
							$device   = isset( $seg['device'] ) && is_string( $seg['device'] ) ? $seg['device'] : 'unknown';
							$template = isset( $seg['template'] ) && is_string( $seg['template'] ) && '' !== $seg['template'] ? $seg['template'] : 'unknown';
							$key      = $path . '|' . $device . '|' . $template;
							if ( ! isset( $merged[ $key ] ) ) {
								$merged[ $key ] = array(
									'path'     => $path,
									'device'   => $device,
									'template' => $template,
									'n'        => 0,
									'samples'  => array(),
								);
							}
							$merged[ $key ]['n'] += isset( $seg['n'] ) ? (int) $seg['n'] : 0;
							$samples              = isset( $seg['samples'] ) && is_array( $seg['samples'] ) ? $seg['samples'] : array();
							foreach ( $samples as $value ) {
								if ( is_numeric( $value ) ) {
									$merged[ $key ]['samples'][] = (float) $value;
								}
							}
						}
					}
				}
				$rows = array();
				foreach ( $merged as $entry ) {
					$n = (int) $entry['n'];
					// p75 is computed from the capped reservoir, not the
					// unbounded accumulator: never qualify or report more
					// observations than actually back the p75 value.
					$sample_count = count( $entry['samples'] );
					if ( $sample_count < $n ) {
						$n = $sample_count;
					}
					if ( $n < $min ) {
						continue;
					}
					$p75    = self::compute_p75( $entry['samples'] );
					$rows[] = array(
						'path'     => $entry['path'],
						'device'   => $entry['device'],
						'template' => $entry['template'],
						'n'        => $n,
						'p75'      => $p75,
					);
				}
				usort(
					$rows,
					static function ( $a, $b ) {
						$pa = isset( $a['p75'] ) ? (float) $a['p75'] : 0.0;
						$pb = isset( $b['p75'] ) ? (float) $b['p75'] : 0.0;
						if ( $pa === $pb ) {
							return 0;
						}
						return $pa > $pb ? -1 : 1;
					}
				);
				return $rows;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return array();
			}
		}
	}
}
