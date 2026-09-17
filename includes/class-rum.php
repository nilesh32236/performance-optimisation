<?php
/**
 * Real-user Web Vitals (RUM) collection and reporting.
 *
 * @package PerformanceOptimise
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) ) {

	/**
	 * Collects real-visitor Core Web Vitals beacons and stores them as bounded
	 * per-day/per-path aggregates, plus the frontend beacon + admin data API.
	 *
	 * The beacon endpoint is intentionally public (anonymous visitors) so it is
	 * protected with a daily rolling, per-page token and per-IP rate limiting
	 * instead of the manage_options permission used by the admin endpoints.
	 *
	 * @since 2.0.0
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
		 * @since 2.0.0
		 * @var int
		 */
		const MAX_TOTAL_PATHS = 600;

		/**
		 * Serialized-size budget (bytes) for the aggregate option.
		 *
		 * @since 2.0.0
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
		 * Maximum beacons accepted site-wide per minute (distributed-spam backstop).
		 *
		 * @since NEXT
		 * @var int
		 */
		const GLOBAL_RATE_LIMIT_PER_MINUTE = 120;

		/**
		 * Default RUM beacon sample rate (percent of page views sampled).
		 *
		 * 100 keeps the pre-sampling behavior verbatim (every beacon sent
		 * and stored). Stored under `performance_audit.rum_sample_rate`.
		 *
		 * @since NEXT
		 * @var int
		 */
		const RUM_SAMPLE_RATE_DEFAULT = 100;

		/**
		 * Default per-minute collection volume that engages the
		 * high-traffic auto-throttle.
		 *
		 * When the site-wide beacon count for the current minute reaches
		 * this threshold, the effective sample rate is halved (floored at
		 * 1). Filterable via `wppo_rum_throttle_threshold`; a non-positive
		 * value disables the throttle (fail-open). Read from the same
		 * windowed bucket maintained by is_globally_rate_limited() so the
		 * throttle adds no new transient writes.
		 *
		 * @since NEXT
		 * @var int
		 */
		const RUM_THROTTLE_THRESHOLD_DEFAULT = 60;

		/**
		 * Transient key for the RUM sample queue.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private const QUEUE_KEY = 'wppo_rum_queue';

		/**
		 * Transient key for the flush lock.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private const FLUSH_LOCK_KEY = 'wppo_rum_flush_lock';

		/**
		 * Maximum queued samples before forced flush.
		 *
		 * @var int
		 * @since 2.0.0
		 */
		private const QUEUE_MAX = 100;

		/**
		 * Maximum distinct LCP element URLs tracked per path bucket.
		 *
		 * Bounds the `lcpUrls` map added for field-measured LCP targeting
		 * (issue #935) so the aggregate option stays within its byte budget.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const MAX_LCP_URLS_PER_PATH = 10;

		/**
		 * Maximum device × template × connection segments tracked per path bucket.
		 *
		 * Bounds the `lcpSeg` map added for field-LCP p75 routing
		 * (issues #986, #1143) so the aggregate option stays within its byte budget.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const MAX_LCP_SEGMENTS_PER_PATH = 6;

		/**
		 * Maximum LCP samples retained per device × template segment.
		 *
		 * Capped reservoir (most-recent values) used solely for p75
		 * computation; oldest values are dropped first.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const MAX_LCP_SAMPLES_PER_SEGMENT = 100;

		/**
		 * Maximum distinct INP device × template × connection segments tracked per path bucket.
		 *
		 * Bounds the `inpSeg` map added for RUM-gated INP-aware delay
		 * suggestions (issues #1036, #1143) so the aggregate option stays within its
		 * byte budget. Mirrors MAX_LCP_SEGMENTS_PER_PATH.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const MAX_INP_SEGMENTS_PER_PATH = 6;

		/**
		 * Maximum INP samples retained per device × template segment.
		 *
		 * Capped most-recent reservoir used solely for p75 computation;
		 * oldest values are dropped first. Mirrors MAX_LCP_SAMPLES_PER_SEGMENT.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const MAX_INP_SAMPLES_PER_SEGMENT = 100;

		/**
		 * Maximum length (chars) accepted for an LCP element URL.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const LCP_URL_MAX_LENGTH = 2048;

		/**
		 * Maximum length (chars) accepted for an LCP element selector.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const LCP_SELECTOR_MAX_LENGTH = 256;

		/**
		 * Maximum slow-resource entries accepted per beacon sample.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const SLOW_RESOURCES_MAX_COUNT = 5;

		/**
		 * Maximum length (chars) accepted for a slow-resource URL.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const SLOW_RESOURCE_URL_MAX_LENGTH = 2048;

		/**
		 * Maximum duration (ms) accepted for a slow-resource entry.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const SLOW_RESOURCE_MAX_DURATION_MS = 60000;

		/**
		 * Maximum distinct LCP element selectors tracked per path bucket.
		 *
		 * Bounds the `lcpSelectors` map added for LCP-element attribution
		 * (issue #1311) so the aggregate option stays within its byte budget.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_LCP_SELECTORS_PER_PATH = 10;

		/**
		 * Maximum distinct slow-resource URLs tracked per path bucket.
		 *
		 * Bounds the `slowResources` map added for the slow-resource audit
		 * (issue #1311) so the aggregate option stays within its byte budget.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const MAX_SLOW_RESOURCES_PER_PATH = 10;

		/**
		 * Allowlisted slow-resource initiator types.
		 *
		 * Mirrors the client-side allowlist (`RUM_ALLOWED_RESOURCE_TYPES`
		 * in src/rum.js). Anything else is dropped at intake.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const ALLOWED_SLOW_RESOURCE_TYPES = array( 'img', 'script', 'css', 'link', 'font', 'fetch', 'xmlhttprequest', 'iframe' );

		/**
		 * Allowlisted effective connection types for RUM segmentation.
		 *
		 * Mirrors the client-side allowlist (`classifyConnectionType` in
		 * src/rum.js). Anything else buckets as `unknown` so stored
		 * aggregates stay bounded and backward compatible.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const ALLOWED_CONNECTIONS = array( 'slow-2g', '2g', '3g', '4g' );

		/**
		 * Default sample gate for the field-measured LCP override.
		 *
		 * The top LCP URL for a path overrides the PageSpeed heuristic only
		 * once it has been observed at least this many times.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		public const FIELD_LCP_DEFAULT_MIN_SAMPLES = 20;

		/**
		 * Upper bound for the field-LCP minimum-sample threshold.
		 *
		 * The resolver and the settings sanitizer clamp the configured
		 * `ai_adaptive.field_lcp_min_samples` / legacy
		 * `image_optimisation.fieldLcpMinSamples` value to 1–MAX so an
		 * extreme admin value cannot perpetually pin auto-tune to
		 * provisional (feature DoS) while keeping the fail-open default.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const FIELD_LCP_MIN_SAMPLES_MAX = 1000;

		/**
		 * Freshness window (seconds) for the field-measured LCP override.
		 *
		 * An override whose top URL was last seen longer ago than this
		 * self-corrects back to the heuristic, so a changed hero recovers
		 * within 24h.
		 *
		 * @since 2.0.0
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
		 * @since 2.0.0
		 * @var array|null
		 */
		private static ?array $field_lcp_aggregate = null;

		/**
		 * Whether the aggregate memo has been populated this request.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $field_lcp_loaded = false;

		/**
		 * Per-request memo for resolved field-LCP results, keyed by
		 * normalized path + sample gate.
		 *
		 * @since 2.0.0
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
		 * @since 2.0.0
		 * @var array|null
		 */
		private static ?array $score_trends_memo = null;

		/**
		 * Whether the trends memo has been populated this request.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $score_trends_loaded = false;

		/**
		 * Generation counter for the per-path top-URL transient index.
		 *
		 * Bumped on every aggregate flush so stale per-path entries are
		 * never served without enumerating transient keys. Cached per
		 * request; -1 means not loaded yet.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private static int $top_url_generation = -1;

		/**
		 * Per-request memo of resolved PageSpeed LCP URLs.
		 *
		 * Keyed by lookup context (explicit path, or current-request post
		 * ID + front-page flag) so repeat calls within the request skip
		 * the post-meta / option / transient reads. Reset via
		 * clear_field_lcp_cache().
		 *
		 * @since NEXT
		 * @var array<string, string>
		 */
		private static array $stored_lcp_memo = array();

		/**
		 * Flush when queue reaches this size.
		 *
		 * @since 2.0.0
		 * @var int
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
		 * @since 2.0.0
		 * @return void
		 */
		public static function clear_field_lcp_cache(): void {
			self::$field_lcp_aggregate   = null;
			self::$field_lcp_loaded      = false;
			self::$field_lcp_result_memo = array();
			self::$score_trends_memo     = null;
			self::$score_trends_loaded   = false;
			self::$top_url_generation    = -1;
			self::$stored_lcp_memo       = array();
		}

		/**
		 * Current generation for the per-path top-URL index.
		 *
		 * @since 2.0.0
		 * @return int Generation counter.
		 */
		private static function top_url_generation(): int {
			if ( self::$top_url_generation < 0 ) {
				$stored                   = function_exists( 'get_option' ) ? get_option( 'wppo_rum_top_url_gen', 0 ) : 0;
				self::$top_url_generation = max( 0, (int) $stored );
			}
			return self::$top_url_generation;
		}

		/**
		 * Transient key for the per-path top-URL index entry.
		 *
		 * Bounded to one small transient per unique path+gate instead of
		 * deserializing the full aggregate (up to MAX_OPTION_BYTES) per
		 * unique path per request.
		 *
		 * @since 2.0.0
		 * @param string $normalized_path Normalized page path.
		 * @param int    $min             Sample gate.
		 * @return string Transient key (unprefixed; wrap with Util::transient_key()).
		 */
		private static function top_url_cache_key( string $normalized_path, int $min ): string {
			return 'wppo_rum_top_' . self::top_url_generation() . '_' . md5( $normalized_path . '|' . $min );
		}

		/**
		 * Bump the top-URL index generation so flush-invalidated entries expire.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function bump_top_url_generation(): void {
			// Note: previous-generation wppo_rum_top_* transients are
			// intentionally not enumerated/deleted here; they are keyed
			// by generation and expire naturally within HOUR_IN_SECONDS.
			$next                     = self::top_url_generation() + 1;
			self::$top_url_generation = $next;
			if ( function_exists( 'update_option' ) ) {
				update_option( 'wppo_rum_top_url_gen', $next, false );
			}
		}

		/**
		 * Get the RUM aggregate with a per-request memo.
		 *
		 * @since 2.0.0
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
		 * @since 2.0.0
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
			// The stored-LCP memo caches site-scoped postmeta/options/
			// transients, so flush it on site switches too.
			add_action( 'switch_blog', array( self::class, 'clear_field_lcp_cache' ) );
		}

		/**
		 * Migrate the RUM aggregate option to non-autoloading.
		 *
		 * Rows created by older plugin versions defaulted to autoload=yes and
		 * keep loading on every WordPress request via alloptions. Mirrors
		 * Img_Converter::migrate_img_info_autoload().
		 *
		 * @since 2.0.0
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

			// Security (issue #1181): rate limiting trusts REMOTE_ADDR only.
			// X-Forwarded-For / X-Real-IP are attacker-controlled and must
			// never bypass limits.
			$raw_ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
			$ip     = self::normalize_ip( $raw_ip );
			// Fail closed: an empty/unparseable IP falls back to the site-wide
			// bucket instead of skipping the throttle (proxies stripping
			// REMOTE_ADDR must not silently disable rate limiting).
			if ( '' === $ip ) {
				if ( self::is_globally_rate_limited() ) {
					return array(
						'ok'      => false,
						'status'  => 429,
						'message' => __( 'Too many beacons.', 'performance-optimisation' ),
					);
				}
			} elseif ( self::is_rate_limited( $ip ) || self::is_globally_rate_limited() ) {
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
		 * @since 2.0.0
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
				$asset = include $asset_file; // phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable
				if ( is_array( $asset ) ) {
					// Trust-but-verify the build artifact (mirrors
					// LiteSpeed_ESI::enqueue_hydration_client()): entries must
					// be non-empty strings and the version a scalar so a
					// tampered partial artifact cannot widen the dependency
					// trust surface.
					$raw_deps = isset( $asset['dependencies'] ) && is_array( $asset['dependencies'] ) ? $asset['dependencies'] : array();
					$clean    = array();
					foreach ( $raw_deps as $dep ) {
						if ( ! is_scalar( $dep ) ) {
							continue;
						}
						$dep = trim( (string) $dep );
						if ( '' !== $dep ) {
							$clean[] = $dep;
						}
					}
					$deps = array_values( array_unique( $clean ) );
					if ( isset( $asset['version'] ) ) {
						if ( is_string( $asset['version'] ) && '' !== $asset['version'] ) {
							$version = $asset['version'];
						} elseif ( is_int( $asset['version'] ) || is_float( $asset['version'] ) ) {
							$version = (string) $asset['version'];
						}
					}
				}
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
		 * Delegates to Util::supports_script_strategy() — the single shared
		 * home for this gate (also used by LiteSpeed_ESI::supports_script_strategy())
		 * so floor bumps cannot drift between copies.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private static function supports_script_strategy(): bool {
			return Util::supports_script_strategy();
		}

		/**
		 * Print the beacon config inline so it is baked into cached HTML.
		 *
		 * Runs on wp_footer; cached pages generated through WordPress capture it
		 * and the served (cache-hit) HTML therefore keeps working without WP.
		 *
		 * Staleness note: the emitted `sampleRate` is the throttled
		 * effective rate at generation time and is baked into the static
		 * HTML cache, so cached pages may serve a pre-throttle rate until
		 * cache expiry. The client gate is therefore best-effort only;
		 * the live server-side re-roll in store_sample() stays
		 * authoritative and still throttles under load.
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

			// Beacon sampling gate (issue #1214): the client sends only about
			// this percent of page views. Additive only; a legacy cached page
			// without the key behaves as 100 (unsampled). Best-effort: the
			// value is baked into (possibly statically cached) HTML and may
			// go stale under throttle until cache expiry — the server-side
			// store_sample() re-roll stays authoritative.
			$config['sampleRate'] = self::get_effective_sample_rate();

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

			// The plugin floor is WP 6.2, where wp_print_inline_script_tag()
			// always exists, so there is no pre-6.0 echo fallback (audit
			// #1268): the fallback would ship without a core CSP nonce.
			wp_print_inline_script_tag( $javascript, array( 'id' => 'wppo-rum-config' ) );
		}

		/**
		 * Detect the current template slug for RUM segmentation.
		 *
		 * Fail-open: returns '' when undetectable so the beacon field is
		 * omitted and aggregation falls back to the `unknown` bucket.
		 *
		 * @since 2.0.0
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
				unset( $e );
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
		 * @since 2.0.0 The $path parameter was added.
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
		 * @since 2.0.0 The $path parameter was added.
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
			$key   = Util::transient_key( 'wppo_rum_ratelimit_' . md5( strtolower( $ip ) ) );
			$count = (int) get_transient( $key );
			if ( $count >= self::RATE_LIMIT_PER_HOUR ) {
				return true;
			}
			set_transient( $key, $count + 1, HOUR_IN_SECONDS );
			return false;
		}

		/**
		 * Normalize a client IP for rate-limit keying.
		 *
		 * Lowercases, strips IPv6 zone IDs (%eth0), and validates via
		 * filter_var(); returns '' when unparseable so callers fail closed
		 * to the site-wide bucket.
		 *
		 * @since NEXT
		 * @param string $ip Raw IP string.
		 * @return string Normalized IP or ''.
		 */
		private static function normalize_ip( string $ip ): string {
			$ip = strtolower( trim( $ip ) );
			if ( '' === $ip ) {
				return '';
			}
			// Strip IPv6 zone identifier (e.g. fe80::1%eth0).
			$pct = strpos( $ip, '%' );
			if ( false !== $pct ) {
				$ip = substr( $ip, 0, $pct );
			}
			if ( function_exists( 'filter_var' ) ) {
				$valid = filter_var( $ip, FILTER_VALIDATE_IP );
				return false === $valid ? '' : (string) $valid;
			}
			return $ip;
		}

		/**
		 * Whether the site-wide per-minute beacon budget is exhausted.
		 *
		 * Backstop against distributed spam where per-IP limits do not help.
		 * Uses a best-effort atomic-ish increment (add() when available).
		 *
		 * @since NEXT
		 * @return bool
		 */
		private static function is_globally_rate_limited(): bool {
			$key    = Util::transient_key( 'wppo_rum_global' );
			$bucket = get_transient( $key );
			$now    = time();
			// Fixed window: expiry is set only on the first increment; later
			// hits re-store with the remaining TTL instead of extending it.
			if ( ! is_array( $bucket ) || ! isset( $bucket['count'], $bucket['start'] ) || (int) $bucket['start'] > $now || ( $now - (int) $bucket['start'] ) >= MINUTE_IN_SECONDS ) {
				set_transient(
					$key,
					array(
						'count' => 1,
						'start' => $now,
					),
					MINUTE_IN_SECONDS
				);
				return false;
			}
			$count = (int) $bucket['count'];
			if ( $count >= self::GLOBAL_RATE_LIMIT_PER_MINUTE ) {
				return true;
			}
			// Clamp into [1, MINUTE_IN_SECONDS]: a future-dated (corrupted or
			// tampered) start would otherwise persist the bucket past one
			// window. Future starts reset above; the min() cap bounds this
			// call's TTL regardless.
			$elapsed   = $now - (int) $bucket['start'];
			$remaining = max( 1, min( MINUTE_IN_SECONDS, MINUTE_IN_SECONDS - $elapsed ) );
			set_transient(
				$key,
				array(
					'count' => $count + 1,
					'start' => (int) $bucket['start'],
				),
				$remaining
			);
			return false;
		}

		/**
		 * Configured RUM beacon sample rate (percent of page views, 1-100).
		 *
		 * Reads the additive `performance_audit.rum_sample_rate` setting.
		 * Missing, non-numeric, or out-of-range values clamp to
		 * RUM_SAMPLE_RATE_DEFAULT (100) so current behavior is verbatim by
		 * default. Fail-open: any failure returns 100, never 0 (a dropped
		 * beacon degrades to unmeasured, but a misread must never silence
		 * collection entirely).
		 *
		 * @since NEXT
		 * @return int Sample rate in 1-100.
		 */
		public static function get_sample_rate(): int {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return self::RUM_SAMPLE_RATE_DEFAULT;
				}
				$options = \PerformanceOptimise\Inc\Util::get_settings();
				if ( ! isset( $options['performance_audit'] ) || ! is_array( $options['performance_audit'] ) ) {
					return self::RUM_SAMPLE_RATE_DEFAULT;
				}
				$raw = $options['performance_audit']['rum_sample_rate'] ?? self::RUM_SAMPLE_RATE_DEFAULT;
				if ( is_array( $raw ) || ! is_numeric( $raw ) ) {
					return self::RUM_SAMPLE_RATE_DEFAULT;
				}
				$rate = (int) $raw;
				if ( $rate < 1 || $rate > 100 ) {
					return self::RUM_SAMPLE_RATE_DEFAULT;
				}
				return $rate;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::RUM_SAMPLE_RATE_DEFAULT;
			}
		}

		/**
		 * Effective sample rate after the high-traffic auto-throttle.
		 *
		 * When the site-wide per-minute collection volume (the windowed
		 * bucket maintained by is_globally_rate_limited(), so no new
		 * transient writes) reaches the filterable `wppo_rum_throttle_threshold`
		 * (default RUM_THROTTLE_THRESHOLD_DEFAULT), the configured rate is
		 * halved (floored at 1) for the rest of the minute. A non-positive
		 * threshold disables the throttle. The final value passes through
		 * the `wppo_rum_effective_sample_rate` filter. Sampling is a lossy
		 * hint only: fail-open returns the configured rate on any failure.
		 *
		 * Dual-gate compounding note: the client (`shouldSendSample` in
		 * src/rum.js) and the server (`should_keep_sample()` in
		 * store_sample()) each roll independently at this effective rate,
		 * so end-to-end stored volume is approximately rate²/100 (rate 10
		 * stores ~1%, not ~10%). Under throttle each gate halves, so
		 * stored volume quarters. This is intentional: the client gate is
		 * a best-effort bandwidth saver while the server gate is
		 * authoritative (it still throttles when the client rate is stale
		 * in statically cached HTML — see print_config()).
		 *
		 * @since NEXT
		 * @return int Effective rate in 1-100.
		 */
		public static function get_effective_sample_rate(): int {
			$base = self::get_sample_rate();
			try {
				if ( ! function_exists( 'apply_filters' ) || ! function_exists( 'get_transient' ) ) {
					return $base;
				}
				$raw_threshold = apply_filters( 'wppo_rum_throttle_threshold', self::RUM_THROTTLE_THRESHOLD_DEFAULT );
				$threshold     = ( is_scalar( $raw_threshold ) && is_numeric( $raw_threshold ) ) ? (int) $raw_threshold : self::RUM_THROTTLE_THRESHOLD_DEFAULT;
				if ( $threshold < 1 ) {
					return $base;
				}
				$bucket    = get_transient( Util::transient_key( 'wppo_rum_global' ) );
				$count     = ( is_array( $bucket ) && isset( $bucket['count'] ) ) ? (int) $bucket['count'] : 0;
				$effective = $base;
				if ( $count >= $threshold ) {
					$effective = max( 1, (int) floor( $base / 2 ) );
				}
				$filtered = (int) apply_filters( 'wppo_rum_effective_sample_rate', $effective, $base );
				if ( $filtered < 1 || $filtered > 100 ) {
					return $effective;
				}
				return $filtered;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $base;
			}
		}

		/**
		 * Sampling decision for one beacon (lossy hint only, no PII).
		 *
		 * A rate of 100 always keeps (no RNG consumed, deterministic).
		 * Otherwise a uniform roll in 1-100 keeps the sample when
		 * roll <= rate. An explicit $roll makes the decision deterministic
		 * for tests; otherwise wp_rand() (mt_rand() fallback outside WP)
		 * is used. Invalid rates clamp to RUM_SAMPLE_RATE_DEFAULT
		 * (fail-open to unsampled).
		 *
		 * Boundary alignment: the discrete `roll <= rate` here is the
		 * integer-domain equivalent of the client gate
		 * (`roll * 100 <= rate` for a continuous roll in [0, 1) in
		 * src/rum.js) — both keep about `rate` percent, and a roll
		 * exactly on the boundary is kept on both sides.
		 *
		 * Compounding note: the client rolls first and this server gate
		 * re-rolls independently at the same effective rate, so stored
		 * volume is approximately rate²/100. The re-roll is deliberate
		 * so forged/legacy beacons cannot bypass sampling.
		 *
		 * @since NEXT
		 * @param int|null $rate Sample rate in 1-100. Null resolves via get_effective_sample_rate().
		 * @param int|null $roll Deterministic roll in 1-100. Null draws fresh randomness.
		 * @return bool True when the beacon should be sent/stored.
		 */
		public static function should_keep_sample( ?int $rate = null, ?int $roll = null ): bool {
			try {
				if ( null === $rate ) {
					$rate = self::get_effective_sample_rate();
				}
				if ( $rate < 1 || $rate > 100 ) {
					$rate = self::RUM_SAMPLE_RATE_DEFAULT;
				}
				if ( $rate >= 100 ) {
					return true;
				}
				if ( null === $roll ) {
					if ( function_exists( 'wp_rand' ) ) {
						$roll = (int) wp_rand( 1, 100 );
					} else {
						$roll = mt_rand( 1, 100 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
					}
				}
				if ( $roll < 1 || $roll > 100 ) {
					return true;
				}
				return $roll <= $rate;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
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
			// Hardened (issue #1181): a crafted beacon path must never carry
			// markup into the stored bucket key. Reject angle brackets,
			// quotes, backticks, and style/script breakout tokens outright.
			if ( false !== strpbrk( $path, '<>"\'`' ) || 1 === preg_match( '/<\/style|<script|<!--|-->|javascript\s*:|vbscript\s*:|data\s*:/i', $path ) ) {
				return null;
			}

			$ranges = self::get_metric_ranges();

			$sample  = array( 'path' => $path );
			$has_any = false;
			foreach ( array_keys( $ranges ) as $metric ) {
				if ( ! isset( $params[ $metric ] ) ) {
					continue;
				}
				// is_scalar + is_numeric first: casting an array to float
				// yields 1 with a PHP 8 warning and would poison aggregates.
				if ( ! is_scalar( $params[ $metric ] ) || ! is_numeric( $params[ $metric ] ) ) {
					continue;
				}
				$value = (float) $params[ $metric ];
				if ( ! is_finite( $value ) ) {
					return null;
				}
				$sample[ $metric ] = self::clamp_metric_value( $metric, $value );
				$has_any           = true;
			}

			if ( ! $has_any ) {
				return null;
			}

			// Optional field-measured LCP element URL (issue #935). Rides along
			// with a valid numeric sample; never a substitute for one. Hardened
			// (issue #1181, modeled on CVE-2026-5934 / CVE-2026-84761): dangerous
			// schemes are rejected case-insensitively, script-capable SVG data
			// URLs and style/script breakout tokens are dropped, and only
			// same-origin URLs are accepted (root-relative or matching the home
			// host) so an anonymous client holding the public per-path page
			// token cannot steer the site's LCP preload to an attacker host.
			// Stored lcpUrl is escaped downstream via esc_url() in
			// Util::get_preload_link().
			if ( isset( $params['lcpUrl'] ) && is_string( $params['lcpUrl'] ) ) {
				$lcp_url = trim( substr( $params['lcpUrl'], 0, self::LCP_URL_MAX_LENGTH ) );
				if ( '' !== $lcp_url && self::is_safe_lcp_url( $lcp_url ) ) {
					$sample['lcpUrl'] = $lcp_url;
				}
			}

			// Optional device × template segmentation (issue #986). Fail-open:
			// missing/invalid values fall back to `unknown` and never reject
			// the sample — the numeric path above is unchanged.
			$sample['device']   = self::normalize_segment_device( $params['device'] ?? null );
			$sample['template'] = self::normalize_segment_template( $params['template'] ?? null );

			// Optional effective-connection-type segmentation (issue #1143).
			// Fail-open: missing/invalid values bucket as `unknown` and never
			// reject the sample — the numeric path above is unchanged.
			$sample['connection'] = self::normalize_segment_connection( $params['connection'] ?? null );

			// Optional LCP element attribution (issue #1311). Lazily booted:
			// this block only runs when the field is present, so the
			// p75-only path is byte-identical when attribution is absent.
			// Fail-open: invalid values omit the key, never reject the sample.
			if ( array_key_exists( 'lcpSelector', $params ) ) {
				$selector = self::sanitize_lcp_selector( $params['lcpSelector'] );
				if ( '' !== $selector ) {
					$sample['lcpSelector'] = $selector;
				}
			}

			// Optional slow-resource audit (issue #1311). Lazily booted:
			// only parsed when the field is present. Accepts both
			// `slowResources` and the legacy-shaped `slow_resources` key.
			// Fail-open: malformed entries are dropped; an empty result
			// omits the key, never rejecting the sample.
			$slow_raw = null;
			if ( array_key_exists( 'slowResources', $params ) ) {
				$slow_raw = $params['slowResources'];
			} elseif ( array_key_exists( 'slow_resources', $params ) ) {
				$slow_raw = $params['slow_resources'];
			}
			if ( null !== $slow_raw ) {
				$slow_clean = self::sanitize_slow_resources( $slow_raw );
				if ( ! empty( $slow_clean ) ) {
					$sample['slowResources'] = $slow_clean;
				}
			}

			return $sample;
		}

		/**
		 * Shared metric range table for sample validation.
		 *
		 * Single source consumed by {@see sanitize_sample()} (intake) and
		 * {@see flush_queue()} (drain) so a range fix in one path can never
		 * desync the other and corrupt stored aggregates.
		 *
		 * @since NEXT
		 * @return array<string,array{0:float,1:float}> Metric => [min, max].
		 */
		public static function get_metric_ranges(): array {
			return array(
				'ttfb' => array( 0, 60000 ),
				'fcp'  => array( 0, 60000 ),
				'lcp'  => array( 0, 60000 ),
				'inp'  => array( 0, 60000 ),
				'cls'  => array( 0, 1 ),
			);
		}

		/**
		 * Clamp a metric value to its valid range.
		 *
		 * Shared by intake and flush via {@see get_metric_ranges()}.
		 *
		 * @since NEXT
		 * @param string $metric Metric name.
		 * @param float  $value  Raw value (must be finite).
		 * @return float Clamped value.
		 */
		private static function clamp_metric_value( string $metric, float $value ): float {
			$ranges = self::get_metric_ranges();
			if ( ! isset( $ranges[ $metric ] ) ) {
				return $value;
			}
			return max( $ranges[ $metric ][0], min( $ranges[ $metric ][1], $value ) );
		}

		/**
		 * Normalize a raw segment value against an allowlist.
		 *
		 * Single helper behind the device/template/connection normalizers so
		 * length caps and allowlist semantics live in one place.
		 *
		 * @since NEXT
		 * @param mixed         $raw       Raw value.
		 * @param string[]|null $allowlist Allowed values (null = free-form slug).
		 * @param int           $maxlen    Max length before normalization.
		 * @return string Normalized segment or 'unknown'.
		 */
		private static function normalize_segment( $raw, ?array $allowlist, int $maxlen ): string {
			if ( ! is_string( $raw ) ) {
				return 'unknown';
			}
			$cleaned = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : $raw;
			$cleaned = strtolower( trim( substr( $cleaned, 0, $maxlen ) ) );
			if ( null !== $allowlist ) {
				return in_array( $cleaned, $allowlist, true ) ? $cleaned : 'unknown';
			}
			$cleaned = (string) preg_replace( '/[^a-z0-9_-]/', '', $cleaned );
			if ( '' === $cleaned ) {
				return 'unknown';
			}
			return $cleaned;
		}

		/**
		 * Resolve the home host (lowercased) for same-origin checks.
		 *
		 * Shared by the three same-origin matchers so host resolution can
		 * never drift between them. Returns '' when undeterminable.
		 *
		 * @since NEXT
		 * @return string Home host or ''.
		 */
		private static function get_home_host(): string {
			try {
				$home_url = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::cached_home_url() : ( function_exists( 'home_url' ) ? home_url() : '' );
				if ( ! function_exists( 'wp_parse_url' ) ) {
					return '';
				}
				return strtolower( (string) wp_parse_url( $home_url, PHP_URL_HOST ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether a URL is scheme-like (can never be same-origin relative).
		 *
		 * Shared by {@see is_same_origin_url()} and
		 * {@see is_same_origin_url_strict()} so the dangerous-scheme list
		 * cannot drift between the fail-open and strict variants.
		 *
		 * @since NEXT
		 * @param string $url Candidate URL.
		 * @return bool True when scheme-like.
		 */
		private static function is_scheme_like_url( string $url ): bool {
			$lower = strtolower( ltrim( $url ) );
			foreach ( array( 'data:', 'blob:', 'javascript:', 'vbscript:', 'mailto:' ) as $scheme ) {
				if ( str_starts_with( $lower, $scheme ) ) {
					return true;
				}
			}
			if ( 0 === strpos( ltrim( $url ), '//' ) ) {
				return true;
			}
			$before_slash = strtok( $url, '/\\?#' );
			return is_string( $before_slash ) && false !== strpos( $before_slash, ':' );
		}

		/**
		 * Normalize a device value to the segment allowlist.
		 *
		 * Shared by beacon intake and queue flush (the queue transient is
		 * user-writable, so flush re-normalizes instead of trusting it).
		 * Anything outside mobile/desktop buckets as `unknown`.
		 *
		 * @since NEXT
		 * @param mixed $raw Raw device value.
		 * @return string Allowlisted device or 'unknown'.
		 */
		private static function normalize_segment_device( $raw ): string {
			$normalized = self::normalize_segment( $raw, array( 'mobile', 'desktop' ), 16 );
			return $normalized;
		}

		/**
		 * Normalize a template slug to the segment allowlist shape.
		 *
		 * Shared by beacon intake and queue flush (the queue transient is
		 * user-writable, so flush re-normalizes instead of trusting it).
		 *
		 * @since NEXT
		 * @param mixed $raw Raw template value.
		 * @return string Sanitized template slug (max 64 chars) or 'unknown'.
		 */
		private static function normalize_segment_template( $raw ): string {
			return self::normalize_segment( $raw, null, 64 );
		}

		/**
		 * Normalize a queued-sample connection value to the segment allowlist.
		 *
		 * The queue transient is user-writable, so the value is re-sanitized at
		 * flush time even though the beacon sanitizes at intake. Anything
		 * outside the allowlist buckets as `unknown` (backward compatible with
		 * rows stored before the connection dimension shipped).
		 *
		 * @since NEXT
		 * @param mixed $raw Raw connection value from a queued sample.
		 * @return string Allowlisted connection type or 'unknown'.
		 */
		private static function normalize_segment_connection( $raw ): string {
			if ( ! is_string( $raw ) ) {
				return 'unknown';
			}
			return self::normalize_segment( $raw, self::ALLOWED_CONNECTIONS, 16 );
		}

		/**
		 * Sanitize an LCP element selector attribution value.
		 *
		 * Additive beacon field (issue #1311): compact `tag#id`/`.class`
		 * selector only, strict charset + length caps, markup/breakout
		 * tokens rejected mirroring the path gate. Fail-open: returns ''
		 * when invalid so callers omit the key and the p75-only path is
		 * unchanged. Never fatal.
		 *
		 * @since NEXT
		 * @param mixed $raw Raw selector value.
		 * @return string Sanitized selector or ''.
		 */
		private static function sanitize_lcp_selector( $raw ): string {
			try {
				if ( ! is_string( $raw ) ) {
					return '';
				}
				$cleaned = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : $raw;
				$cleaned = trim( substr( $cleaned, 0, self::LCP_SELECTOR_MAX_LENGTH ) );
				if ( '' === $cleaned ) {
					return '';
				}
				if ( 1 !== preg_match( '/^[a-z0-9#\._\-\s:~+\[\]=\']{1,256}$/i', $cleaned ) ) {
					return '';
				}
				// Authoritative markup/breakout rejection (mirrored by
				// sanitizeRumValues() in src/rum.js): angle brackets,
				// double quotes and backticks never pass, so the regex
				// above intentionally omits them.
				if ( false !== strpbrk( $cleaned, '<>"`' ) ) {
					return '';
				}
				if ( false !== stripos( $cleaned, '</style' ) || false !== stripos( $cleaned, '<script' ) || false !== stripos( $cleaned, '<!--' ) || false !== strpos( $cleaned, '-->' ) || false !== stripos( $cleaned, 'javascript:' ) ) {
					return '';
				}
				return $cleaned;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Normalize a slow-resource initiator type to the allowlist.
		 *
		 * @since NEXT
		 * @param mixed $raw Raw initiator type.
		 * @return string Allowlisted type or ''.
		 */
		private static function normalize_slow_resource_type( $raw ): string {
			try {
				if ( ! is_string( $raw ) ) {
					return '';
				}
				$cleaned = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $raw ) : $raw;
				$cleaned = strtolower( trim( substr( $cleaned, 0, 16 ) ) );
				return in_array( $cleaned, self::ALLOWED_SLOW_RESOURCE_TYPES, true ) ? $cleaned : '';
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Sanitize the slow-resource audit payload.
		 *
		 * Additive beacon field (issue #1311): array of at most
		 * SLOW_RESOURCES_MAX_COUNT shaped entries. Per-entry the URL must
		 * pass the same-origin `is_safe_lcp_url()` gate, the type must be
		 * allowlisted, and the duration is clamped to
		 * 0–SLOW_RESOURCE_MAX_DURATION_MS via `clamp_metric_value()`.
		 * Malformed entries are dropped; an empty result omits the key.
		 * Lazily booted: callers only invoke this when the field is present.
		 * Never fatal.
		 *
		 * @since NEXT
		 * @param mixed $raw Raw slowResources value.
		 * @return array Shaped entries (possibly empty).
		 */
		private static function sanitize_slow_resources( $raw ): array {
			$clean = array();
			try {
				if ( ! is_array( $raw ) ) {
					return $clean;
				}
				$sliced = array_slice( $raw, 0, self::SLOW_RESOURCES_MAX_COUNT );
				foreach ( $sliced as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$url_raw = $entry['url'] ?? ( $entry['name'] ?? null );
					if ( ! is_string( $url_raw ) ) {
						continue;
					}
					$url = trim( substr( $url_raw, 0, self::SLOW_RESOURCE_URL_MAX_LENGTH ) );
					if ( '' === $url || ! self::is_safe_lcp_url( $url ) ) {
						continue;
					}
					$type = self::normalize_slow_resource_type( $entry['type'] ?? ( $entry['initiatorType'] ?? null ) );
					if ( '' === $type ) {
						continue;
					}
					$duration_raw = $entry['duration'] ?? null;
					if ( ! is_scalar( $duration_raw ) || ! is_numeric( $duration_raw ) ) {
						continue;
					}
					$duration = (float) $duration_raw;
					if ( ! is_finite( $duration ) ) {
						continue;
					}
					$duration = max( 0.0, min( (float) self::SLOW_RESOURCE_MAX_DURATION_MS, $duration ) );
					$clean[]  = array(
						'url'      => $url,
						'type'     => $type,
						'duration' => $duration,
					);
					if ( count( $clean ) >= self::SLOW_RESOURCES_MAX_COUNT ) {
						break;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $clean;
		}

		/**
		 * Whether a candidate LCP element URL is safe to store.
		 *
		 * Hardened intake gate (issue #1181, modeled on CVE-2026-5934 /
		 * CVE-2026-84761): scheme checks are case-insensitive and strip
		 * leading whitespace/control characters so `  JaVaScRiPt:` cannot
		 * bypass; `data:`/`blob:`/`vbscript:`/`file:`/`expect:` schemes are
		 * rejected outright with an explicit `data:image/svg+xml` guard
		 * (script-capable SVG); style/script breakout tokens and raw
		 * angle brackets/quotes are rejected; only http(s)/root-relative
		 * same-origin targets pass. Fail-closed: anything unexpected is
		 * unsafe. Shared by sanitize_sample() and flush_queue() so a
		 * directly-written queue transient cannot bypass intake.
		 *
		 * @since NEXT
		 * @param string $lcp_url Candidate LCP URL (already trimmed + length-capped).
		 * @return bool True when safe to store.
		 */
		private static function is_safe_lcp_url( string $lcp_url ): bool {
			if ( '' === $lcp_url ) {
				return false;
			}
			// Raw markup can never appear in a stored preload URL.
			if ( false !== strpbrk( $lcp_url, '<>"\'`' ) ) {
				return false;
			}
			if ( false !== stripos( $lcp_url, '</style' ) || false !== stripos( $lcp_url, '<script' ) || false !== stripos( $lcp_url, '<!--' ) || false !== strpos( $lcp_url, '-->' ) ) {
				return false;
			}
			// Strip leading whitespace/control characters before scheme
			// checks so obfuscated `  javascript:` cannot bypass.
			$trimmed = ltrim( $lcp_url, " \t\n\r\0\x0B" );
			if ( '' === $trimmed ) {
				return false;
			}
			if ( 1 === preg_match( '/^(javascript|vbscript|data|blob|file|expect)\s*:/i', $trimmed ) ) {
				return false;
			}
			// Explicit script-capable SVG guard (defense-in-depth: covered
			// by the data: rejection above, kept explicit for auditability).
			if ( false !== stripos( $trimmed, 'data:image/svg' ) ) {
				return false;
			}
			if ( 0 !== stripos( $trimmed, 'http://' ) && 0 !== stripos( $trimmed, 'https://' ) && 0 !== strpos( $trimmed, '/' ) ) {
				return false;
			}
			return self::is_same_origin_lcp_url( $trimmed );
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
		 * @since 2.0.0
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
				$home = self::get_home_host();
				return '' !== $home && $host === $home;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Whether a URL is same-origin with this site (public emission guard).
		 *
		 * Re-validates stored/measured candidates on the LCP preload emission
		 * path (issue #1180): intake-time validation alone cannot cover
		 * legacy aggregate rows or PageSpeed values written before the intake
		 * guard shipped. Root-relative paths are accepted; absolute URLs must
		 * match the home host. Bare relative paths are accepted unless
		 * scheme-like (`data:`, `blob:`, `javascript:`, …, or any value
		 * with a colon before the first slash), which are rejected to
		 * match the intake guard. Fail-open by design: when the origin cannot be
		 * proven either way (missing `wp_parse_url()`, an undeterminable home
		 * host, or any internal failure — e.g. unit contexts without the full
		 * WP API), the candidate is accepted to preserve legacy behaviour.
		 * A provable host mismatch still rejects. Never fatal.
		 *
		 * @since NEXT
		 * @param string $url Candidate URL.
		 * @return bool True when same-origin or unverifiable.
		 */
		public static function is_same_origin_url( string $url ): bool {
			try {
				if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
					return true;
				}
				if ( ! function_exists( 'wp_parse_url' ) ) {
					return true;
				}
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( '' === $host ) {
					// No parseable host: only a bare relative URL (no scheme,
					// no leading `//`) resolves against the home URL and is
					// same-origin by construction. Scheme-like values (`data:`,
					// `blob:`, `javascript:`, `mailto:`, …) carry no host yet
					// never resolve same-origin, matching the intake guard
					// (`is_same_origin_lcp_url()`) which rejects every
					// empty-host URL.
					return ! self::is_scheme_like_url( $url );
				}
				$home = self::get_home_host();
				if ( '' === $home ) {
					// Home host undeterminable: a cross-origin verdict cannot
					// be proven, so fail open to legacy behaviour.
					return true;
				}
				return $host === $home;
			} catch ( \Throwable $e ) {
				return true;
			}
		}

		/**
		 * Whether a URL is same-origin, strict variant for emission paths.
		 *
		 * Wraps {@see is_same_origin_url()} but maps the unverifiable cases to
		 * false (issue #1216): when the home host is undeterminable, when
		 * `wp_parse_url` is unavailable, or on Throwable, the legacy helper
		 * fails open to true for non-emission callers — but a preload `<link>`
		 * must never be emitted on an unverifiable verdict. Emission-path
		 * guards (font/LCP preload) must call this method.
		 *
		 * @since NEXT
		 * @param string $url Candidate URL.
		 * @return bool True only when same-origin is positively proven.
		 */
		public static function is_same_origin_url_strict( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				$lower = strtolower( ltrim( $url ) );
				if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) || str_starts_with( $lower, 'mailto:' ) ) {
					return false;
				}
				// Root-relative and bare relative paths are same-origin by
				// construction (unless scheme-like, checked above).
				if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
					return true;
				}
				// Protocol-relative URLs skip this block: host must be proven below.
				if ( 0 !== strpos( ltrim( $url ), '//' ) && false === strpos( $url, '://' ) ) {
					return ! self::is_scheme_like_url( $url );
				}
				if ( ! function_exists( 'wp_parse_url' ) ) {
					return false;
				}
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( '' === $host ) {
					return false;
				}
				$home = self::get_home_host();
				if ( '' === $home ) {
					return false;
				}
				return $host === $home;
			} catch ( \Throwable $e ) {
				return false;
			}
		}

		/**
		 * Whether a persistent external object cache is available.
		 *
		 * `wp_cache_add()` is only atomic (`SET NX EX`) on a persistent
		 * backend; without one it is per-request memory and every worker
		 * would "win". Fail-open: any failure means no external cache.
		 *
		 * @since NEXT
		 * @return bool True when wp_using_ext_object_cache() reports a persistent cache.
		 */
		private static function has_ext_object_cache(): bool {
			try {
				if ( function_exists( 'wp_using_ext_object_cache' ) ) {
					return (bool) wp_using_ext_object_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Atomically append a sample to the RUM queue.
		 *
		 * With a persistent object cache the append uses an
		 * optimistic-concurrency loop (`wp_cache_get` with `$found` +
		 * `wp_cache_add` for creates / `wp_cache_set` for updates, up to 3
		 * retries) so concurrent beacons stop overwriting each other. The
		 * result is mirrored to the transient so DB-backed readers and the
		 * flush path still see the queue. Without an external cache (or on
		 * any failure) the current `get_transient`/`set_transient` behavior
		 * is kept verbatim: bounded at QUEUE_MAX with eventual consistency.
		 * Fail-open: samples are dropped under pressure, never fatal.
		 *
		 * @since NEXT
		 * @param array $sample Normalized sample (with `_ts` attached).
		 * @return array{0:bool,1:int} Tuple of (was_empty before append, count after append).
		 */
		private static function append_to_queue_atomic( array $sample ): array {
			$queue_key = Util::transient_key( self::QUEUE_KEY );
			$cap       = self::QUEUE_MAX;
			if ( self::has_ext_object_cache() && function_exists( 'wp_cache_add' ) && function_exists( 'wp_cache_get' ) && function_exists( 'wp_cache_set' ) ) {
				try {
					for ( $attempt = 0; $attempt < 3; $attempt++ ) {
						$found = false;
						$queue = wp_cache_get( $queue_key, 'wppo', false, $found );
						if ( ! $found || ! is_array( $queue ) ) {
							// Seed from the durable transient so legacy
							// entries queued before this deploy are not lost
							// when the object-cache copy is cold.
							$seed      = function_exists( 'get_transient' ) ? get_transient( $queue_key ) : false;
							$was_empty = ! is_array( $seed ) || empty( $seed );
							$queue     = is_array( $seed ) ? $seed : array();
							$queue[]   = $sample;
							if ( count( $queue ) > $cap ) {
								$queue = array_slice( $queue, -$cap );
							}
							$count = count( $queue );
							if ( wp_cache_add( $queue_key, $queue, 'wppo', HOUR_IN_SECONDS ) ) {
								if ( function_exists( 'set_transient' ) ) {
									try {
										set_transient( $queue_key, $queue, HOUR_IN_SECONDS );
									} catch ( \Throwable $e ) {
										unset( $e );
									}
								}
								return array( $was_empty, $count );
							}
							continue;
						}
						$was_empty = empty( $queue );
						$queue[]   = $sample;
						if ( count( $queue ) > $cap ) {
							$queue = array_slice( $queue, -$cap );
						}
						$count = count( $queue );
						if ( wp_cache_set( $queue_key, $queue, 'wppo', HOUR_IN_SECONDS ) ) {
							if ( function_exists( 'set_transient' ) ) {
								try {
									set_transient( $queue_key, $queue, HOUR_IN_SECONDS );
								} catch ( \Throwable $e ) {
									unset( $e );
								}
							}
							return array( $was_empty, $count );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$queue = function_exists( 'get_transient' ) ? get_transient( $queue_key ) : false;
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}
			$was_empty = empty( $queue );
			$queue[]   = $sample;
			if ( count( $queue ) > $cap ) {
				$queue = array_slice( $queue, -$cap );
			}
			if ( function_exists( 'set_transient' ) ) {
				try {
					set_transient( $queue_key, $queue, HOUR_IN_SECONDS );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return array( $was_empty, count( $queue ) );
		}

		/**
		 * Atomically acquire the RUM flush lock.
		 *
		 * With a persistent object cache this is `wp_cache_add()` (`SET NX
		 * EX`, 30s TTL) so only one flusher wins. Without one it is a
		 * best-effort transient check-and-set (documented non-atomic: two
		 * flushes can both observe a miss). Fail-open: any failure returns
		 * false (flush skipped), never fatal.
		 *
		 * @since NEXT
		 * @return bool True when this worker owns the lock.
		 */
		private static function acquire_flush_lock(): bool {
			$lock_key = Util::transient_key( self::FLUSH_LOCK_KEY );
			if ( self::has_ext_object_cache() && function_exists( 'wp_cache_add' ) ) {
				try {
					return (bool) wp_cache_add( $lock_key, 1, 'wppo', 30 );
				} catch ( \Throwable $e ) {
					unset( $e );
					// Fall through to the transient check-and-set below:
					// a backend without add() (or a broken one) must not
					// wedge the flush path, it only loses atomicity.
				}
			}
			try {
				if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
					if ( false !== get_transient( $lock_key ) ) {
						return false;
					}
					// Fire-and-forget like the previous implementation:
					// the return value is ignored so a backend reporting
					// failure (or a stub returning null) cannot wedge the
					// flush path. Best-effort and documented non-atomic.
					set_transient( $lock_key, 1, 30 );
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return false;
		}

		/**
		 * Release the RUM flush lock from both namespaces.
		 *
		 * Deletes the object-cache copy and the transient copy so the lock
		 * is always cleared regardless of which path acquired it. Called
		 * from the `finally` block of flush_queue(). Fail-open: throwables
		 * are swallowed, never fatal.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function release_flush_lock(): void {
			$lock_key = Util::transient_key( self::FLUSH_LOCK_KEY );
			try {
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $lock_key, 'wppo' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( $lock_key );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Merge one value into a bounded per-path segment map.
		 *
		 * Unifies the `lcpSeg` / `inpSeg` merge blocks of
		 * {@see flush_queue()} (previously near-clone ~60-line blocks) behind
		 * one reservoir + lowest-n eviction implementation.
		 *
		 * @since NEXT
		 * @param array  $bucket       Bucket to merge into (by ref).
		 * @param string $map_key      Segment map key ('lcpSeg' or 'inpSeg').
		 * @param int    $max_segments Max segments per path.
		 * @param int    $max_samples  Max reservoir samples per segment.
		 * @param string $device       Normalized device.
		 * @param string $template     Normalized template.
		 * @param string $connection   Normalized connection.
		 * @param float  $value        Clamped metric value.
		 * @return void
		 */
		private static function merge_value_segment( array &$bucket, string $map_key, int $max_segments, int $max_samples, string $device, string $template, string $connection, float $value ): void {
			$seg_key = $device . '|' . $template . '|' . $connection;
			if ( ! isset( $bucket[ $map_key ] ) || ! is_array( $bucket[ $map_key ] ) ) {
				$bucket[ $map_key ] = array();
			}
			if ( ! isset( $bucket[ $map_key ][ $seg_key ] ) || ! is_array( $bucket[ $map_key ][ $seg_key ] ) ) {
				$bucket[ $map_key ][ $seg_key ] = array(
					'device'     => $device,
					'template'   => $template,
					'connection' => $connection,
					'n'          => 0,
					'sum'        => 0.0,
					'min'        => $value,
					'max'        => $value,
					'samples'    => array(),
				);
			}
			++$bucket[ $map_key ][ $seg_key ]['n'];
			$bucket[ $map_key ][ $seg_key ]['sum'] += $value;
			$bucket[ $map_key ][ $seg_key ]['min']  = min( $bucket[ $map_key ][ $seg_key ]['min'], $value );
			$bucket[ $map_key ][ $seg_key ]['max']  = max( $bucket[ $map_key ][ $seg_key ]['max'], $value );
			$samples                                = isset( $bucket[ $map_key ][ $seg_key ]['samples'] ) && is_array( $bucket[ $map_key ][ $seg_key ]['samples'] ) ? $bucket[ $map_key ][ $seg_key ]['samples'] : array();
			$samples[]                              = $value;
			if ( count( $samples ) > $max_samples ) {
				$samples = array_slice( $samples, -$max_samples );
			}
			$bucket[ $map_key ][ $seg_key ]['samples'] = array_values( $samples );
			$seg_count                                 = count( $bucket[ $map_key ] );
			while ( $seg_count > $max_segments ) {
				$evict_key = null;
				$evict_n   = null;
				foreach ( $bucket[ $map_key ] as $key => $entry ) {
					$entry_n = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
					if ( null === $evict_key || $entry_n < $evict_n ) {
						$evict_key = $key;
						$evict_n   = $entry_n;
					}
				}
				if ( null === $evict_key ) {
					break;
				}
				unset( $bucket[ $map_key ][ $evict_key ] );
				--$seg_count;
			}
		}

		/**
		 * Validate one queued sample into a merge-ready shape.
		 *
		 * Single Sample validator shared by the flush path: re-validates the
		 * attacker-writable queue transient (path markup gate, range clamp,
		 * segment re-normalization) exactly like {@see sanitize_sample()}.
		 *
		 * @since NEXT
		 * @param mixed $sample Raw queued entry.
		 * @return array{date:string,path:string,ts:int,sample:array}|null Validated sample or null to skip.
		 */
		private static function validate_queued_sample( $sample ): ?array {
			if ( ! is_array( $sample ) ) {
				return null;
			}
			$raw_qpath = isset( $sample['path'] ) && is_string( $sample['path'] ) ? substr( $sample['path'], 0, 512 ) : '';
			if ( '' === $raw_qpath ) {
				return null;
			}
			$qpath = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( $raw_qpath ) : $raw_qpath;
			if ( false !== strpbrk( $qpath, '<>"\'`' ) || 1 === preg_match( '/<\/style|<script|<!--|-->|javascript\s*:|vbscript\s*:|data\s*:/i', $qpath ) ) {
				return null;
			}
			$ts   = isset( $sample['_ts'] ) ? (int) $sample['_ts'] : time();
			$date = gmdate( 'Y-m-d', $ts );
			return array(
				'date'   => $date,
				'path'   => $qpath,
				'ts'     => $ts,
				'sample' => $sample,
			);
		}


		/**
		 * Persist aggregates with retention + size budgets.
		 *
		 * Extracted from {@see flush_queue()}: drops days older than
		 * retention, enforces the path/byte budgets, and writes the option.
		 *
		 * @since NEXT
		 * @param array $all Aggregates.
		 * @return void
		 */
		private static function persist_aggregate( array $all ): void {
			$cutoff = gmdate( 'Y-m-d', time() - ( self::MAX_DAYS * DAY_IN_SECONDS ) );
			foreach ( array_keys( $all ) as $day_key ) {
				if ( $day_key < $cutoff ) {
					unset( $all[ $day_key ] );
				}
			}
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
						$half = array_slice( $all[ $oldest_day_key ], (int) ( count( $all[ $oldest_day_key ] ) / 2 ) );
						if ( ! empty( $half ) ) {
							$all[ $oldest_day_key ] = $half;
						}
						break;
					}
					unset( $all[ $oldest_day_key ] );
					continue;
				}
				$encoded           = wp_json_encode( $all );
				$under_byte_budget = false !== $encoded && strlen( (string) $encoded ) <= self::MAX_OPTION_BYTES;
				if ( $under_byte_budget ) {
					break;
				}
				$oldest_day_key = array_key_first( $all );
				if ( null === $oldest_day_key ) {
					break;
				}
				if ( 1 === count( $all ) && is_array( $all[ $oldest_day_key ] ) ) {
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
			self::bump_top_url_generation();
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
		 * @since 2.0.0
		 */
		private static function store_sample( array $sample ): void {
			// Sampling gate (issue #1214): the server re-rolls at the
			// effective rate even though the client already sampled, so
			// end-to-end stored volume is about rate²/100 (documented on
			// get_effective_sample_rate()). The re-roll is deliberate so
			// forged/legacy beacons cannot bypass the gate. Dropped
			// samples skip the queue transient read+write entirely (the
			// footprint win); aggregates stay bounded by the existing
			// flush caps regardless of traffic. Lossy hint only — a drop
			// degrades to unmeasured, never fatal.
			if ( ! self::should_keep_sample() ) {
				return;
			}
			// Attach timestamp so flush can bucket by sample day, not flush day.
			$sample['_ts']             = time();
			list( $was_empty, $count ) = self::append_to_queue_atomic( $sample );

			if ( $count >= self::FLUSH_THRESHOLD ) {
				self::flush_queue();
			} elseif ( function_exists( 'wp_rand' ) && 1 === wp_rand( 1, 10 ) ) {
				self::flush_queue();
			} elseif ( $was_empty && function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
				// Ensure a cron will eventually flush the queue even on low traffic.
				// Scheduled only on the 0-to-1 queue transition so steady-state
				// beacons skip the extra wp_next_scheduled() DB query.
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
		 * @since 2.0.0
		 * @return void
		 */
		public static function flush_queue(): void {
			if ( ! self::acquire_flush_lock() ) {
				return;
			}
			try {
				$queue_key = Util::transient_key( self::QUEUE_KEY );
				$queue     = false;
				if ( self::has_ext_object_cache() && function_exists( 'wp_cache_get' ) ) {
					try {
						$queue = wp_cache_get( $queue_key, 'wppo' );
					} catch ( \Throwable $e ) {
						unset( $e );
						$queue = false;
					}
				}
				if ( ! is_array( $queue ) || empty( $queue ) ) {
					$queue = function_exists( 'get_transient' ) ? get_transient( $queue_key ) : false;
				}
				if ( empty( $queue ) || ! is_array( $queue ) ) {
					return;
				}
				// Copy and clear queue before processing so new beacons arriving
				// during aggregation queue separately.
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( $queue_key );
				}
				if ( function_exists( 'wp_cache_delete' ) ) {
					try {
						wp_cache_delete( $queue_key, 'wppo' );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				$all = get_option( self::OPTION, array() );
				if ( ! is_array( $all ) ) {
					$all = array();
				}

				$ranges = array(
					'ttfb' => array( 0, 60000 ),
					'fcp'  => array( 0, 60000 ),
					'lcp'  => array( 0, 60000 ),
					'inp'  => array( 0, 60000 ),
					'cls'  => array( 0, 1 ),
				);

				foreach ( $queue as $sample ) {
					// Hardened (issue #1181): the queue transient is
					// attacker-writable, so every queued field is
					// re-validated here — intake validation alone can be
					// bypassed by writing the transient directly. Fail-open:
					// malformed entries are skipped, never fatal.
					$validated = self::validate_queued_sample( $sample );
					if ( null === $validated ) {
						continue;
					}
					$sample = $validated['sample'];
					$ts     = $validated['ts'];
					$date   = $validated['date'];
					$path   = $validated['path'];

					if ( ! isset( $all[ $date ] ) || ! is_array( $all[ $date ] ) ) {
						$all[ $date ] = array();
					}
					$day    = $all[ $date ];
					$bucket = isset( $day[ $path ] ) && is_array( $day[ $path ] ) ? $day[ $path ] : array();

					foreach ( array( 'ttfb', 'fcp', 'lcp', 'inp', 'cls' ) as $metric ) {
						if ( ! isset( $sample[ $metric ] ) || ! is_numeric( $sample[ $metric ] ) ) {
							continue;
						}
						$value = (float) $sample[ $metric ];
						if ( ! is_finite( $value ) ) {
							continue;
						}
						$value = self::clamp_metric_value( $metric, $value );
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
						// Re-validate: the queue transient is user-writable,
						// so a hostile lcpUrl smuggled past intake (or
						// written directly) is dropped here before it can
						// reach the aggregate option / preload output.
						$queued_lcp = trim( substr( $sample['lcpUrl'], 0, self::LCP_URL_MAX_LENGTH ) );
						if ( ! self::is_safe_lcp_url( $queued_lcp ) ) {
							$queued_lcp = '';
						}
						$normalized = ( '' !== $queued_lcp && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_url' ) )
						? \PerformanceOptimise\Inc\Util::normalize_url( $queued_lcp )
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
									'url'      => $queued_lcp,
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

					// LCP element attribution (issue #1311): bounded per-path
					// `lcpSelectors` map counting sanitized hero selectors.
					// Re-validated here: the queue transient is user-writable.
					// Fail-open: malformed entries are skipped, never fatal.
					if ( isset( $sample['lcpSelector'] ) ) {
						$queued_selector = self::sanitize_lcp_selector( $sample['lcpSelector'] );
						if ( '' !== $queued_selector ) {
							if ( ! isset( $bucket['lcpSelectors'] ) || ! is_array( $bucket['lcpSelectors'] ) ) {
								$bucket['lcpSelectors'] = array();
							}
							if ( isset( $bucket['lcpSelectors'][ $queued_selector ] ) && is_array( $bucket['lcpSelectors'][ $queued_selector ] ) ) {
								++$bucket['lcpSelectors'][ $queued_selector ]['n'];
								$bucket['lcpSelectors'][ $queued_selector ]['lastSeen'] = $ts;
							} else {
								$bucket['lcpSelectors'][ $queued_selector ] = array(
									'n'        => 1,
									'lastSeen' => $ts,
								);
							}
							$selector_count = count( $bucket['lcpSelectors'] );
							while ( $selector_count > self::MAX_LCP_SELECTORS_PER_PATH ) {
								$evict_key = null;
								$evict_n   = null;
								$evict_ts  = null;
								foreach ( $bucket['lcpSelectors'] as $key => $entry ) {
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
								unset( $bucket['lcpSelectors'][ $evict_key ] );
								--$selector_count;
							}
						}
					}

					// Slow-resource audit (issue #1311): bounded per-path
					// `slowResources` map counting sanitized sub-resource URLs
					// with cumulative duration + slowest observation. Accepts
					// both `slowResources` and legacy `slow_resources` queue
					// keys. Re-validated here: the queue transient is
					// user-writable. Fail-open: malformed entries skipped.
					$queued_slow = $sample['slowResources'] ?? ( $sample['slow_resources'] ?? null );
					if ( null !== $queued_slow ) {
						$slow_clean = self::sanitize_slow_resources( $queued_slow );
						if ( ! empty( $slow_clean ) ) {
							if ( ! isset( $bucket['slowResources'] ) || ! is_array( $bucket['slowResources'] ) ) {
								$bucket['slowResources'] = array();
							}
							foreach ( $slow_clean as $slow_entry ) {
								$slow_url = isset( $slow_entry['url'] ) ? (string) $slow_entry['url'] : '';
								if ( '' === $slow_url ) {
									continue;
								}
								$slow_norm = ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_url' ) )
									? \PerformanceOptimise\Inc\Util::normalize_url( $slow_url )
									: '';
								$slow_key  = '' !== $slow_norm ? $slow_norm : $slow_url;
								if ( isset( $bucket['slowResources'][ $slow_key ] ) && is_array( $bucket['slowResources'][ $slow_key ] ) ) {
									++$bucket['slowResources'][ $slow_key ]['n'];
									$bucket['slowResources'][ $slow_key ]['totalDuration'] = (float) ( $bucket['slowResources'][ $slow_key ]['totalDuration'] ?? 0.0 ) + (float) $slow_entry['duration'];
									$bucket['slowResources'][ $slow_key ]['maxDuration']   = max( (float) ( $bucket['slowResources'][ $slow_key ]['maxDuration'] ?? 0.0 ), (float) $slow_entry['duration'] );
									$bucket['slowResources'][ $slow_key ]['lastSeen']      = $ts;
									if ( isset( $slow_entry['type'] ) ) {
										$bucket['slowResources'][ $slow_key ]['type'] = $slow_entry['type'];
									}
								} else {
									$bucket['slowResources'][ $slow_key ] = array(
										'url'           => $slow_url,
										'type'          => $slow_entry['type'],
										'n'             => 1,
										'totalDuration' => (float) $slow_entry['duration'],
										'maxDuration'   => (float) $slow_entry['duration'],
										'lastSeen'      => $ts,
									);
								}
							}
							$slow_count = count( $bucket['slowResources'] );
							while ( $slow_count > self::MAX_SLOW_RESOURCES_PER_PATH ) {
								$evict_key = null;
								$evict_n   = null;
								$evict_ts  = null;
								foreach ( $bucket['slowResources'] as $key => $entry ) {
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
								unset( $bucket['slowResources'][ $evict_key ] );
								--$slow_count;
							}
						}
					}

					// Device × template × connection LCP segments (issues #986, #1143):
					// bounded per-path `lcpSeg` map; merged via the shared
					// merge_value_segment() helper (same reservoir + eviction
					// as `inpSeg` below). Rows stored before the connection
					// dimension read back as `unknown`.
					if ( isset( $sample['lcp'] ) && is_numeric( $sample['lcp'] ) ) {
						$lcp_value = (float) $sample['lcp'];
						if ( ! is_finite( $lcp_value ) ) {
							$lcp_value = null;
						} else {
							$lcp_value = self::clamp_metric_value( 'lcp', $lcp_value );
						}
						if ( null !== $lcp_value ) {
							// Re-sanitize even though the beacon sanitizes at
							// intake: the queue transient is user-writable, so
							// reuse the shared normalizers (same as
							// sanitize_sample()) before persisting into the
							// aggregate option.
							self::merge_value_segment(
								$bucket,
								'lcpSeg',
								self::MAX_LCP_SEGMENTS_PER_PATH,
								self::MAX_LCP_SAMPLES_PER_SEGMENT,
								self::normalize_segment_device( $sample['device'] ?? null ),
								self::normalize_segment_template( $sample['template'] ?? null ),
								self::normalize_segment_connection( $sample['connection'] ?? null ),
								$lcp_value
							);
						}
					}

					// Device × template × connection INP segments (issues #1036, #1143):
					// bounded per-path `inpSeg` map; merged via the shared
					// merge_value_segment() helper (same reservoir + eviction
					// as `lcpSeg` above). No new option or transient names.
					// Fail-open: any malformed queue entry is skipped, never fatal.
					if ( isset( $sample['inp'] ) && is_numeric( $sample['inp'] ) ) {
						$inp_value = (float) $sample['inp'];
						if ( ! is_finite( $inp_value ) ) {
							$inp_value = null;
						} else {
							$inp_value = self::clamp_metric_value( 'inp', $inp_value );
						}
						if ( null !== $inp_value ) {
							// Re-sanitize even though the beacon sanitizes at
							// intake: the queue transient is user-writable, so
							// reuse the shared normalizers (same as
							// sanitize_sample() and the lcpSeg block above)
							// before persisting into the aggregate option.
							self::merge_value_segment(
								$bucket,
								'inpSeg',
								self::MAX_INP_SEGMENTS_PER_PATH,
								self::MAX_INP_SAMPLES_PER_SEGMENT,
								self::normalize_segment_device( $sample['device'] ?? null ),
								self::normalize_segment_template( $sample['template'] ?? null ),
								self::normalize_segment_connection( $sample['connection'] ?? null ),
								$inp_value
							);
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

				self::persist_aggregate( $all );
			} finally {
				self::release_flush_lock();
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
		 * @since 2.0.0
		 * @since NEXT Sample gate unified via get_field_lcp_min_samples() so
		 *             `ai_adaptive.field_lcp_min_samples` is honoured.
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
				// Sample gate unification (issue #1200, @since NEXT): resolve via
				// the canonical get_field_lcp_min_samples() so the additive
				// `ai_adaptive.field_lcp_min_samples` setting is honoured here
				// (previously only the legacy `image_optimisation.fieldLcpMinSamples`
				// was read, letting the preload path disagree with the AI path).
				// Fail-open to FIELD_LCP_DEFAULT_MIN_SAMPLES via the resolver.
				$min = self::get_field_lcp_min_samples();
				if ( $min < 1 ) {
					$min = self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				}
				// Per-request memo: the frontend calls this twice per page
				// view, so collapse to one get_option() deserialization.
				$memo_key = $normalized_path . '|' . $min;
				if ( array_key_exists( $memo_key, self::$field_lcp_result_memo ) ) {
					return self::$field_lcp_result_memo[ $memo_key ];
				}
				// Bounded per-path transient index: repeat visitors for a
				// known path skip the full-aggregate scan entirely. Reads and
				// writes are individually guarded: a broken object-cache
				// backend (or a function stubbed out by another test in the
				// same long-running process) must never discard a successful
				// in-request computation.
				$top_key    = self::top_url_cache_key( $normalized_path, $min );
				$cached_top = false;
				if ( function_exists( 'get_transient' ) ) {
					try {
						$cached_top = get_transient( Util::transient_key( $top_key ) );
					} catch ( \Throwable $e ) {
						unset( $e );
						$cached_top = false;
					}
				}
				if ( is_array( $cached_top ) && isset( $cached_top['url'] ) ) {
					$cached_n    = (int) ( $cached_top['n'] ?? 0 );
					$cached_seen = (int) ( $cached_top['lastSeen'] ?? 0 );
					if ( $cached_n >= $min && $cached_seen > 0 && ( time() - $cached_seen ) <= self::FIELD_LCP_STALE_TTL ) {
						// Emission-path origin re-check (issue #1180): cached
						// entries may predate the intake guard. A cross-origin
						// entry falls through to the aggregate scan instead of
						// being served.
						if ( is_string( $cached_top['url'] ) && self::is_same_origin_url( $cached_top['url'] ) ) {
							self::$field_lcp_result_memo[ $memo_key ] = $cached_top;
							return $cached_top;
						}
						// Cross-origin cached entry: evict the stale transient
						// once (best-effort, guarded like the read-through
						// write) so repeat visitors skip the poisoned read and
						// fall straight to the aggregate scan / fresh write.
						if ( function_exists( 'delete_transient' ) && class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
							try {
								delete_transient( Util::transient_key( $top_key ) );
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
					}
					// Stale/under-sampled/cross-origin entry: fall through to the aggregate scan.
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
				// Emission-path origin re-check (issue #1180): aggregate rows
				// written before the intake guard shipped may hold a
				// cross-origin URL. Reject it so only same-origin heroes are
				// ever preloaded.
				if ( ! self::is_same_origin_url( $top['url'] ) ) {
					self::$field_lcp_result_memo[ $memo_key ] = null;
					return null;
				}
				// Read-through cache (issue #1180): the fresh aggregate scan
				// result is written back to the bounded per-path transient so
				// repeat visitors for a known path skip the full-aggregate
				// scan. Guarded and best-effort: a broken object-cache backend
				// must never discard a successful in-request computation.
				self::$field_lcp_result_memo[ $memo_key ] = $top;
				if ( function_exists( 'set_transient' ) && class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					try {
						set_transient( Util::transient_key( $top_key ), $top, HOUR_IN_SECONDS );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
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
		 * @since 2.0.0
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request path.
		 * @return array{url:string,n:int,lastSeen:int}|null Single LCP candidate or null.
		 */
		public static function get_lcp_preload_candidate( ?string $path = null ): ?array {
			try {
				if ( null === $path ) {
					$path = self::resolve_current_path();
				}
				$field = self::get_field_lcp_url( $path );
				if ( is_array( $field ) && ! empty( $field['url'] ) && is_string( $field['url'] ) && self::is_same_origin_url( $field['url'] ) ) {
					return $field;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				$fallback = self::get_stored_pagespeed_lcp_url( $path );
				if ( '' !== $fallback && self::is_same_origin_url( $fallback ) ) {
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
		 * Get the top LCP element selector attribution for a page path.
		 *
		 * Additive read-only lookup (issue #1311) over the `lcpSelectors`
		 * maps stored by {@see flush_queue()}. Returns the most-observed
		 * sanitized selector for the path only when it has been seen at
		 * least `$min` times and was last seen within FIELD_LCP_STALE_TTL,
		 * mirroring {@see get_field_lcp_url()}. Returns null otherwise so
		 * callers fall through to the p75-only path. Fail-open: any failure
		 * returns null, never fatal. No new option or transient names.
		 *
		 * @since NEXT
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request path.
		 * @param int|null    $min  Minimum samples (defaults to get_field_lcp_min_samples()).
		 * @return array{selector:string,n:int,lastSeen:int}|null Top selector or null.
		 */
		public static function get_top_lcp_selector( ?string $path = null, ?int $min = null ): ?array {
			try {
				if ( null === $path ) {
					$path = self::resolve_current_path();
					if ( null === $path ) {
						$raw_path = ( function_exists( 'wp_parse_url' ) && isset( $_SERVER['REQUEST_URI'] ) ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
						$path     = is_string( $raw_path ) && '' !== $raw_path ? substr( $raw_path, 0, 512 ) : '/';
					}
				}
				if ( '' === $path ) {
					return null;
				}
				$normalized_path = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( $path ) : $path;
				if ( null === $min ) {
					$min = self::get_field_lcp_min_samples();
				}
				if ( $min < 1 ) {
					$min = self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				}
				$all = self::get_memoized_aggregate();
				if ( ! is_array( $all ) || empty( $all ) ) {
					return null;
				}
				$now          = function_exists( 'time' ) ? time() : 0;
				$per_selector = array();
				foreach ( $all as $day_bucket ) {
					if ( ! is_array( $day_bucket ) ) {
						continue;
					}
					foreach ( $day_bucket as $bucket_path => $bucket ) {
						if ( ! is_array( $bucket ) ) {
							continue;
						}
						$bucket_norm = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::normalize_rum_path( (string) $bucket_path ) : (string) $bucket_path;
						if ( $bucket_norm !== $normalized_path ) {
							continue;
						}
						$selectors = $bucket['lcpSelectors'] ?? null;
						if ( ! is_array( $selectors ) ) {
							continue;
						}
						foreach ( $selectors as $selector => $entry ) {
							if ( ! is_string( $selector ) || '' === $selector || ! is_array( $entry ) ) {
								continue;
							}
							// Re-sanitize: aggregate rows may predate the intake guard.
							if ( '' === self::sanitize_lcp_selector( $selector ) ) {
								continue;
							}
							$n         = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
							$last_seen = isset( $entry['lastSeen'] ) ? (int) $entry['lastSeen'] : 0;
							if ( ! isset( $per_selector[ $selector ] ) ) {
								$per_selector[ $selector ] = array(
									'selector' => $selector,
									'n'        => 0,
									'lastSeen' => 0,
								);
							}
							$per_selector[ $selector ]['n']       += $n;
							$per_selector[ $selector ]['lastSeen'] = max( $per_selector[ $selector ]['lastSeen'], $last_seen );
						}
					}
				}
				if ( empty( $per_selector ) ) {
					return null;
				}
				// Pick the selector with the highest per-selector count so
				// unrelated selectors can neither inflate the sample gate
				// nor keep a stale winner fresh.
				$best = null;
				foreach ( $per_selector as $candidate ) {
					if ( null === $best || $candidate['n'] > $best['n'] ) {
						$best = $candidate;
					}
				}
				if ( null === $best ) {
					return null;
				}
				if ( $best['n'] < $min ) {
					return null;
				}
				if ( $best['lastSeen'] <= 0 || ( $now - $best['lastSeen'] ) > self::FIELD_LCP_STALE_TTL ) {
					return null;
				}
				return $best;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Get the top slow-resource audit entries across the aggregate.
		 *
		 * Additive read-only lookup (issue #1311) over the `slowResources`
		 * maps stored by {@see flush_queue()}. Aggregates by normalized URL
		 * across all days/paths, ranks by total observed count then average
		 * duration, and returns at most `$limit` entries with same-origin
		 * URLs only. Fail-open: any failure returns an empty array, never
		 * fatal. No new option or transient names.
		 *
		 * @since NEXT
		 * @param int $limit Maximum entries (1–10, defaults to 3).
		 * @return array<int,array{url:string,type:string,n:int,avgDuration:float,maxDuration:float,lastSeen:int}> Top slow resources.
		 */
		public static function get_top_slow_resources( int $limit = 3 ): array {
			try {
				$limit = max( 1, min( 10, $limit ) );
				$all   = self::get_memoized_aggregate();
				if ( ! is_array( $all ) || empty( $all ) ) {
					return array();
				}
				$merged = array();
				foreach ( $all as $day_bucket ) {
					if ( ! is_array( $day_bucket ) ) {
						continue;
					}
					foreach ( $day_bucket as $bucket ) {
						if ( ! is_array( $bucket ) ) {
							continue;
						}
						$entries = $bucket['slowResources'] ?? null;
						if ( ! is_array( $entries ) ) {
							continue;
						}
						foreach ( $entries as $key => $entry ) {
							if ( ! is_array( $entry ) || empty( $entry['url'] ) || ! is_string( $entry['url'] ) ) {
								continue;
							}
							if ( ! self::is_same_origin_url( $entry['url'] ) ) {
								continue;
							}
							$norm    = ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_url' ) )
								? \PerformanceOptimise\Inc\Util::normalize_url( $entry['url'] )
								: '';
							$map_key = '' !== $norm ? $norm : ( is_string( $key ) ? $key : $entry['url'] );
							if ( ! isset( $merged[ $map_key ] ) ) {
								$merged[ $map_key ] = array(
									'url'           => $entry['url'],
									'type'          => isset( $entry['type'] ) && is_string( $entry['type'] ) ? $entry['type'] : 'img',
									'n'             => 0,
									'totalDuration' => 0.0,
									'maxDuration'   => 0.0,
									'lastSeen'      => 0,
								);
							}
							$entry_n                              = isset( $entry['n'] ) ? (int) $entry['n'] : 0;
							$merged[ $map_key ]['n']             += $entry_n;
							$merged[ $map_key ]['totalDuration'] += (float) ( $entry['totalDuration'] ?? 0.0 );
							$merged[ $map_key ]['maxDuration']    = max( (float) $merged[ $map_key ]['maxDuration'], (float) ( $entry['maxDuration'] ?? 0.0 ) );
							$merged[ $map_key ]['lastSeen']       = max( (int) $merged[ $map_key ]['lastSeen'], isset( $entry['lastSeen'] ) ? (int) $entry['lastSeen'] : 0 );
							if ( $entry_n >= ( $merged[ $map_key ]['_raw_n'] ?? 0 ) ) {
								$merged[ $map_key ]['url'] = $entry['url'];
								if ( isset( $entry['type'] ) && is_string( $entry['type'] ) ) {
									$merged[ $map_key ]['type'] = $entry['type'];
								}
								$merged[ $map_key ]['_raw_n'] = $entry_n;
							}
						}
					}
				}
				if ( empty( $merged ) ) {
					return array();
				}
				$rows = array();
				foreach ( $merged as $row ) {
					unset( $row['_raw_n'] );
					$row['avgDuration'] = $row['n'] > 0 ? (float) $row['totalDuration'] / (int) $row['n'] : 0.0;
					unset( $row['totalDuration'] );
					$rows[] = $row;
				}
				usort(
					$rows,
					static function ( $a, $b ) {
						$an = isset( $a['n'] ) ? (int) $a['n'] : 0;
						$bn = isset( $b['n'] ) ? (int) $b['n'] : 0;
						if ( $an !== $bn ) {
							return $bn <=> $an;
						}
						$ad = isset( $a['avgDuration'] ) ? (float) $a['avgDuration'] : 0.0;
						$bd = isset( $b['avgDuration'] ) ? (float) $b['avgDuration'] : 0.0;
						return $bd <=> $ad;
					}
				);
				return array_slice( array_values( $rows ), 0, $limit );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Resolve the current page path the same way the preload pipeline does.
		 *
		 * Prefers `Util::get_current_url()` (the source `get_current_lcp_url()`
		 * derives its path from) and falls back to null so
		 * `get_field_lcp_url()` resolves `$_SERVER['REQUEST_URI']` itself.
		 * Fail-open: any failure returns null.
		 *
		 * @since 2.0.0
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
		 * @since 2.0.0
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request URL.
		 * @return string The PageSpeed LCP image URL, or empty string when none is stored.
		 */
		public static function get_stored_pagespeed_lcp_url( ?string $path = null ): string {
			try {
				$memo_key = self::stored_lcp_memo_key( $path );
				if ( array_key_exists( $memo_key, self::$stored_lcp_memo ) ) {
					return self::$stored_lcp_memo[ $memo_key ];
				}
				$resolved                           = self::resolve_stored_pagespeed_lcp_url( $path );
				self::$stored_lcp_memo[ $memo_key ] = $resolved;
				return $resolved;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * Build the per-request memo key for a stored-LCP lookup.
		 *
		 * Explicit paths key on the path itself; current-request lookups
		 * key on the singular post ID + front-page flag (cheap
		 * conditional tags, no storage I/O).
		 *
		 * @since NEXT
		 * @param string|null $path Page path, or null for the current request.
		 * @return string Memo key.
		 */
		private static function stored_lcp_memo_key( ?string $path ): string {
			// Blog-scoped: postmeta/options/transients are site-scoped, so
			// a mid-request switch_blog() must not serve site A's memoized
			// LCP URL to site B.
			$blog = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( null !== $path ) {
				return 'path:' . $blog . ':' . $path;
			}
			$post_part  = '0';
			$front_part = '0';
			try {
				if ( function_exists( 'is_singular' ) && function_exists( 'get_the_ID' ) && is_singular() ) {
					$post_id = get_the_ID();
					if ( ! empty( $post_id ) ) {
						$post_part = (string) $post_id;
					}
				}
				if ( function_exists( 'is_front_page' ) && is_front_page() ) {
					$front_part = '1';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 'current:' . $blog . ':' . $post_part . ':' . $front_part;
		}

		/**
		 * Resolve the stored PageSpeed LCP image URL without memoization.
		 *
		 * Single home for the three-tier lookup (singular post meta →
		 * front-page option → strategy+URL-hash transient) called once
		 * per lookup context per request by
		 * {@see get_stored_pagespeed_lcp_url()}.
		 *
		 * @since NEXT
		 * @param string|null $path Page path (e.g. "/about/"). Defaults to the current request URL.
		 * @return string The PageSpeed LCP image URL, or empty string when none is stored.
		 */
		private static function resolve_stored_pagespeed_lcp_url( ?string $path = null ): string {
			try {
				$strategies = array( 'mobile', 'desktop' );

				// Priority 1: Singular post — check post meta (mobile first, then desktop).
				// Current-request context only: an explicit path cannot be mapped to a post ID.
				// Both strategy keys are read with a single get_post_meta()
				// call (no per-strategy round trip).
				if ( null === $path && function_exists( 'is_singular' ) && function_exists( 'get_the_ID' ) && function_exists( 'get_post_meta' ) ) {
					try {
						if ( is_singular() ) {
							$post_id = get_the_ID();
							if ( ! empty( $post_id ) ) {
								$handled_single_call = false;
								try {
									$all_meta = get_post_meta( $post_id );
									if ( is_array( $all_meta ) ) {
										// Page-builder posts can carry 50-200KB
										// of postmeta; materializing all of it
										// to read two keys costs more than two
										// narrow keyed reads without a
										// persistent object cache, so fall
										// through to the per-key path.
										if ( count( $all_meta ) <= 100 ) {
											$handled_single_call = true;
											foreach ( $strategies as $strategy ) {
												$values   = $all_meta[ '_wppo_lcp_image_url_' . $strategy ] ?? '';
												$meta_lcp = is_array( $values ) ? ( $values[0] ?? '' ) : $values;
												// Same-origin only (issue #1180): a
												// cross-origin tier value is skipped so a
												// later same-origin tier can still win.
												if ( ! empty( $meta_lcp ) && is_string( $meta_lcp ) && self::is_same_origin_url( $meta_lcp ) ) {
													return $meta_lcp;
												}
											}
										}
									}
								} catch ( \Throwable $e ) {
									unset( $e );
								}
								if ( ! $handled_single_call ) {
									foreach ( $strategies as $strategy ) {
										$meta_lcp = get_post_meta( $post_id, '_wppo_lcp_image_url_' . $strategy, true );
										// Same-origin only (issue #1180): a
										// cross-origin tier value is skipped so a
										// later same-origin tier can still win.
										if ( ! empty( $meta_lcp ) && is_string( $meta_lcp ) && self::is_same_origin_url( $meta_lcp ) ) {
											return $meta_lcp;
										}
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
								if ( ! empty( $front_lcp ) && is_string( $front_lcp ) && self::is_same_origin_url( $front_lcp ) ) {
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
					unset( $e );
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
						unset( $e );
						continue;
					}
					if ( ! empty( $transient ) && is_string( $transient ) && self::is_same_origin_url( $transient ) ) {
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
		 * @since 2.0.0
		 * @since NEXT Return value is clamped to 1–FIELD_LCP_MIN_SAMPLES_MAX.
		 * @return int Minimum samples (>=1).
		 */
		public static function get_field_lcp_min_samples(): int {
			try {
				$options = class_exists( 'PerformanceOptimise\Inc\Util' ) ? \PerformanceOptimise\Inc\Util::get_settings() : array();
				if ( isset( $options['ai_adaptive']['field_lcp_min_samples'] ) ) {
					$min = (int) $options['ai_adaptive']['field_lcp_min_samples'];
					if ( $min >= 1 ) {
						return min( self::FIELD_LCP_MIN_SAMPLES_MAX, $min );
					}
				}
				if ( isset( $options['image_optimisation']['fieldLcpMinSamples'] ) ) {
					$min = (int) $options['image_optimisation']['fieldLcpMinSamples'];
					if ( $min >= 1 ) {
						return min( self::FIELD_LCP_MIN_SAMPLES_MAX, $min );
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
		 * @since 2.0.0
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
		 * Get field p75 segmented by device × template × connection (read-only).
		 *
		 * Parameterized core behind {@see get_field_lcp_p75_by_segment()} and
		 * {@see get_field_inp_p75_by_segment()} so the merge/qualify/sort
		 * pipeline lives in one place instead of two clones.
		 *
		 * @since NEXT
		 * @param string   $seg_key     Segment map key ('lcpSeg' or 'inpSeg').
		 * @param int|null $min_samples Minimum samples per segment. Null resolves via get_field_lcp_min_samples().
		 * @return array[] Rows of array(path,device,template,connection,n,p75).
		 */
		public static function get_field_p75_by_segment( string $seg_key, ?int $min_samples = null ): array {
			try {
				$min = null === $min_samples ? self::get_field_lcp_min_samples() : (int) $min_samples;
				if ( $min < 1 ) {
					$min = self::FIELD_LCP_DEFAULT_MIN_SAMPLES;
				}
				if ( ! function_exists( 'get_option' ) ) {
					return array();
				}
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
						if ( ! is_array( $bucket ) || ! isset( $bucket[ $seg_key ] ) || ! is_array( $bucket[ $seg_key ] ) ) {
							continue;
						}
						$path = (string) $bucket_path;
						foreach ( $bucket[ $seg_key ] as $seg ) {
							if ( ! is_array( $seg ) ) {
								continue;
							}
							$device     = isset( $seg['device'] ) && is_string( $seg['device'] ) ? $seg['device'] : 'unknown';
							$template   = isset( $seg['template'] ) && is_string( $seg['template'] ) && '' !== $seg['template'] ? $seg['template'] : 'unknown';
							$connection = isset( $seg['connection'] ) && is_string( $seg['connection'] ) && in_array( $seg['connection'], self::ALLOWED_CONNECTIONS, true ) ? $seg['connection'] : 'unknown';
							$key        = $path . '|' . $device . '|' . $template . '|' . $connection;
							if ( ! isset( $merged[ $key ] ) ) {
								$merged[ $key ] = array(
									'path'       => $path,
									'device'     => $device,
									'template'   => $template,
									'connection' => $connection,
									'n'          => 0,
									'samples'    => array(),
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
					$n            = (int) $entry['n'];
					$sample_count = count( $entry['samples'] );
					if ( $sample_count < $n ) {
						$n = $sample_count;
					}
					if ( $n < $min ) {
						continue;
					}
					$p75    = self::compute_p75( $entry['samples'] );
					$rows[] = array(
						'path'       => $entry['path'],
						'device'     => $entry['device'],
						'template'   => $entry['template'],
						'connection' => $entry['connection'] ?? 'unknown',
						'n'          => $n,
						'p75'        => $p75,
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
		 * Get field LCP p75 segmented by device × template × connection (read-only).
		 *
		 * Pure read path for AI-Adaptive auto-tune (issues #986, #1143): reads the
		 * aggregate option only via get_option() — never flushes the queue
		 * and never calls update_option/set_transient, so the frontend
		 * incurs no new writes. Segments across all retained days are merged
		 * by `{path}|{device}|{template}|{connection}`; only segments with n >=
		 * $min_samples are returned. Rows stored before the connection
		 * dimension shipped read back with `connection: 'unknown'`.
		 * Fail-open: any failure returns array().
		 *
		 * @since 2.0.0
		 * @since NEXT Added the `connection` segment dimension.
		 * @param int|null $min_samples Minimum samples per segment. Null resolves via get_field_lcp_min_samples().
		 * @return array[] Rows of array(path,device,template,connection,n,p75).
		 */
		public static function get_field_lcp_p75_by_segment( ?int $min_samples = null ): array {
			return self::get_field_p75_by_segment( 'lcpSeg', $min_samples );
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * Get field INP p75 segmented by device × template × connection (read-only).
		 *
		 * Pure read path for RUM-gated INP-aware delay suggestions (issues
		 * #1036, #1143): reads the aggregate option only via get_option() — never
		 * flushes the queue and never calls update_option/set_transient, so
		 * the frontend incurs no new writes. Mirrors
		 * get_field_lcp_p75_by_segment() over the bounded `inpSeg` reservoir
		 * written by flush_queue(). Segments across all retained days are
		 * merged by `{path}|{device}|{template}|{connection}`; only segments with n >=
		 * $min_samples are returned, slowest-first. Rows stored before the
		 * connection dimension shipped read back with `connection: 'unknown'`.
		 * Fail-open: any failure returns array(). No PII: aggregates only
		 * (n/samples), no IP/URL params stored. Multisite-safe: get_option() is
		 * inherently site-specific; queue/lock keys go through
		 * Util::transient_key().
		 *
		 * @since 2.0.0
		 * @since NEXT Added the `connection` segment dimension.
		 * @param int|null $min_samples Minimum samples per segment. Null resolves via get_field_lcp_min_samples() (shared gate, default 20).
		 * @return array[] Rows of array(path,device,template,connection,n,p75).
		 */
		public static function get_field_inp_p75_by_segment( ?int $min_samples = null ): array {
			return self::get_field_p75_by_segment( 'inpSeg', $min_samples );
		}
	}
}
