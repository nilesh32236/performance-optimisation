<?php
/**
 * LiteSpeed Crawler — curl_multi variant-matrix preloader.
 *
 * Mirrors LSCWP crawler.cls.php BLACKLIST_THRESHOLD=3, lane throttling,
 * and variant matrix (Accept webp/avif × mobile/desktop × guest/role).
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_multi_init,WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_exec,WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read,WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle,WordPress.WP.AlternativeFunctions.curl_curl_multi_close,WordPress.WP.AlternativeFunctions.curl_curl_close,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_getinfo

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {

	/**
	 * Class LiteSpeed_Crawler
	 *
	 * Provides curl_multi batch preloading with variant matrix and
	 * blacklist handling. All settings are opt-in via litespeed_integration.crawler.
	 *
	 * @since NEXT
	 */
	final class LiteSpeed_Crawler {

		/**
		 * Blacklist threshold (failures before skipping URL).
		 *
		 * Mirrors LSCWP crawler.cls.php:26 BLACKLIST_THRESHOLD=3.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const BLACKLIST_THRESHOLD = 3;

		/**
		 * Default concurrency (2-4).
		 *
		 * @since NEXT
		 * @var int
		 */
		public const DEFAULT_CONCURRENCY = 2;

		/**
		 * Transient prefix for blacklist counts.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const BLACKLIST_PREFIX = 'wppo_crawler_blacklist_';

		/**
		 * Transient key for server IP cache.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const SERVER_IP_KEY = 'wppo_crawler_server_ip';

		/**
		 * Get concurrency (2-4) filtered via wppo_crawler_concurrency.
		 *
		 * Adaptive by load: when overloaded, caller should defer; when load is
		 * moderate, concurrency is capped lower.
		 *
		 * @since NEXT
		 * @return int
		 */
		public static function get_concurrency(): int {
			$options     = get_option( 'wppo_settings', array() );
			$concurrency = isset( $options['litespeed_integration']['crawler']['concurrency'] ) ? (int) $options['litespeed_integration']['crawler']['concurrency'] : self::DEFAULT_CONCURRENCY;
			$concurrency = max( 1, min( 4, $concurrency ) );
			/**
			 * Filter crawler concurrency.
			 *
			 * @since NEXT
			 * @param int $concurrency Concurrency 1-4.
			 */
			$concurrency = (int) apply_filters( 'wppo_crawler_concurrency', $concurrency );
			// Adaptive by load: reduce concurrency when system is under pressure but not over limit.
			if ( self::is_overloaded() ) {
				$concurrency = 1;
			} elseif ( function_exists( 'sys_getloadavg' ) ) {
				$avg = @sys_getloadavg(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional fallback for load check
				if ( is_array( $avg ) && isset( $avg[0] ) ) {
					$load  = (float) $avg[0];
					$limit = self::get_load_limit();
					if ( $load > $limit * 0.7 ) {
						$concurrency = min( $concurrency, 2 );
					}
				}
			}
			return max( 1, min( 4, $concurrency ) );
		}

		/**
		 * Get blacklist threshold filtered via wppo_crawler_blacklist_threshold.
		 *
		 * @since NEXT
		 * @return int
		 */
		public static function get_blacklist_threshold(): int {
			$options   = get_option( 'wppo_settings', array() );
			$threshold = isset( $options['litespeed_integration']['crawler']['blacklistThreshold'] ) ? (int) $options['litespeed_integration']['crawler']['blacklistThreshold'] : self::BLACKLIST_THRESHOLD;
			$threshold = max( 1, $threshold );
			/**
			 * Filter blacklist threshold.
			 *
			 * @since NEXT
			 * @param int $threshold Threshold.
			 */
			$threshold = (int) apply_filters( 'wppo_crawler_blacklist_threshold', $threshold );
			return max( 1, $threshold );
		}

		/**
		 * Get load limit for throttling.
		 *
		 * Reads litespeed_integration.crawler.loadLimit or computes nproc*2 fallback.
		 * Filtered via wppo_crawler_load_limit with default 4.0 (LSCWP crawler.cls.php:684).
		 *
		 * @since NEXT
		 * @return float
		 */
		public static function get_load_limit(): float {
			$options    = get_option( 'wppo_settings', array() );
			$configured = isset( $options['litespeed_integration']['crawler']['loadLimit'] ) ? (float) $options['litespeed_integration']['crawler']['loadLimit'] : 0;
			$limit      = 4.0;
			if ( $configured > 0 ) {
				$limit = $configured;
			} else {
				// nproc*2 fallback when available and not filtered — respects LSCWP crawler.cls.php:684 lane.
				// Default stays 4.0 for backward compat; operators can expose nproc*2 via filter wppo_crawler_load_limit.
				$use_nproc = (bool) apply_filters( 'wppo_crawler_use_nproc', false );
				if ( $use_nproc && function_exists( 'shell_exec' ) ) {
					$nproc_raw = @shell_exec( 'nproc 2>/dev/null' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec -- nproc fallback for load limit
					if ( is_string( $nproc_raw ) && '' !== trim( $nproc_raw ) && is_numeric( trim( $nproc_raw ) ) ) {
						$nproc = (int) trim( $nproc_raw );
						if ( $nproc > 0 ) {
							$limit = (float) $nproc * 2;
						}
					} elseif ( @is_readable( '/proc/cpuinfo' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- procfs check fallback
						$cpuinfo = @file_get_contents( '/proc/cpuinfo' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local procfs read, not remote
						if ( is_string( $cpuinfo ) ) {
							$cnt = substr_count( $cpuinfo, 'processor' );
							if ( $cnt > 0 ) {
								$limit = (float) $cnt * 2;
							}
						}
					}
				}
			}
			/**
			 * Filter crawler load limit.
			 *
			 * @since NEXT
			 * @param float $limit Load limit 1-min avg.
			 */
			$limit = (float) apply_filters( 'wppo_crawler_load_limit', $limit );
			return max( 0.1, $limit );
		}

		/**
		 * Whether system load is over limit (throttler).
		 *
		 * Uses sys_getloadavg 1-min or /proc/loadavg fallback.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function is_overloaded(): bool {
			$limit = self::get_load_limit();
			$load  = null;
			if ( function_exists( 'sys_getloadavg' ) ) {
				$avg = @sys_getloadavg(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- intentional fallback for load check
				if ( is_array( $avg ) && isset( $avg[0] ) && is_numeric( $avg[0] ) ) {
					$load = (float) $avg[0];
				}
			}
			if ( null === $load && @is_readable( '/proc/loadavg' ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- procfs check fallback
				$content = @file_get_contents( '/proc/loadavg' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged,WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local procfs read, not remote
				if ( is_string( $content ) && '' !== $content ) {
					$parts = preg_split( '/\s+/', trim( $content ) );
					if ( isset( $parts[0] ) && is_numeric( $parts[0] ) ) {
						$load = (float) $parts[0];
					}
				}
			}
			if ( null === $load ) {
				return false;
			}
			$overloaded = $load > $limit;
			/**
			 * Filter overload decision for testing / extensibility.
			 *
			 * @since NEXT
			 * @param bool  $overloaded Whether overloaded.
			 * @param float $load Current load.
			 * @param float $limit Load limit.
			 */
			return (bool) apply_filters( 'wppo_crawler_is_overloaded', $overloaded, $load, $limit );
		}

		/**
		 * Get cached server IP for CURLOPT_RESOLVE.
		 *
		 * Resolves home_url host via gethostbyname and caches 1h via
		 * Util::transient_key('wppo_crawler_server_ip').
		 *
		 * @since NEXT
		 * @return string IP or empty string.
		 */
		public static function get_server_ip(): string {
			$key = Util::transient_key( self::SERVER_IP_KEY );
			$ip  = get_transient( $key );
			if ( is_string( $ip ) && '' !== $ip && filter_var( $ip, FILTER_VALIDATE_IP ) ) {
				return $ip;
			}
			$home = Util::cached_home_url();
			$host = wp_parse_url( $home, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host ) {
				return '';
			}
			$resolved = @gethostbyname( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- DNS fallback
			if ( ! is_string( $resolved ) || $resolved === $host || ! filter_var( $resolved, FILTER_VALIDATE_IP ) ) {
				return '';
			}
			set_transient( $key, $resolved, HOUR_IN_SECONDS );
			return $resolved;
		}

		/**
		 * Whether URL is blacklisted (failures >= threshold).
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return bool
		 */
		public static function is_blacklisted( string $url ): bool {
			$key   = Util::transient_key( self::BLACKLIST_PREFIX . md5( $url ) );
			$count = (int) get_transient( $key );
			return $count >= self::get_blacklist_threshold();
		}

		/**
		 * Record failure for URL; increment blacklist counter.
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return void
		 */
		public static function record_failure( string $url ): void {
			$key   = Util::transient_key( self::BLACKLIST_PREFIX . md5( $url ) );
			$count = (int) get_transient( $key );
			set_transient( $key, $count + 1, DAY_IN_SECONDS );
		}

		/**
		 * Clear blacklist for URL on success.
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return void
		 */
		public static function clear_blacklist( string $url ): void {
			$key = Util::transient_key( self::BLACKLIST_PREFIX . md5( $url ) );
			delete_transient( $key );
		}

		/**
		 * Build variant matrix for a URL.
		 *
		 * Variants: Accept webp/avif × mobile/desktop × guest/role_hash.
		 * Filterable via wppo_crawler_variants.
		 *
		 * @since NEXT
		 * @param string $url Base URL.
		 * @return array<int, array{url:string,headers:array<string,string>}>
		 */
		public static function build_variant_matrix( string $url ): array {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				return array();
			}
			$variants = array();

			$accepts = array( 'image/webp', 'image/avif' );
			$mobiles = array( 'desktop', 'mobile' );
			$guests  = array( 'guest', 'role' );

			foreach ( $accepts as $accept ) {
				foreach ( $mobiles as $mobile ) {
					foreach ( $guests as $guest ) {
						$headers = array(
							'Accept'     => $accept,
							'User-Agent' => 'mobile' === $mobile ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
						);
						if ( 'guest' === $guest ) {
							$headers['Cookie'] = '';
						}
						$variants[] = array(
							'url'     => $url,
							'headers' => $headers,
						);
					}
				}
			}

			/**
			 * Filter crawler variant matrix.
			 *
			 * @since NEXT
			 * @param array  $variants Variant list.
			 * @param string $url Base URL.
			 */
			$variants = (array) apply_filters( 'wppo_crawler_variants', $variants, $url );
			return $variants;
		}

		/**
		 * Get variants to warm respecting varyGroups.
		 *
		 * Respects litespeed_integration.varyGroups.{guest,mobile,webp} + enableLoggedInCache (role).
		 * Default guest:false → single primary; guest+mobile+webp+role true → 8 variants.
		 * Filterable via wppo_crawler_variants and wppo_crawler_full_matrix for BC.
		 *
		 * @since NEXT
		 * @param string $url Base URL.
		 * @return array<int, array{url:string,headers:array<string,string>}>
		 */
		public static function get_variants_to_warm( string $url ): array {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				return array();
			}
			// Full matrix opt-in via legacy filter takes precedence.
			if ( (bool) apply_filters( 'wppo_crawler_full_matrix', false ) ) {
				return self::build_variant_matrix( $url );
			}

			$options        = get_option( 'wppo_settings', array() );
			$groups         = $options['litespeed_integration']['varyGroups'] ?? array();
			$cache_settings = $options['cache_settings'] ?? array();

			$guest  = ! empty( $groups['guest'] );
			$mobile = ! empty( $groups['mobile'] );
			$webp   = ! empty( $groups['webp'] );
			$role   = ! empty( $cache_settings['enableLoggedInCache'] );

			// When LiteSpeed_Integration is available, prefer its should_vary_by_role for role flag.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'should_vary_by_role' ) ) {
				try {
					$role = \PerformanceOptimise\Inc\LiteSpeed_Integration::should_vary_by_role();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$guest_variants  = ( $guest || $role ) ? array( 'guest', 'role' ) : array( 'guest' );
			$mobile_variants = $mobile ? array( 'desktop', 'mobile' ) : array( 'desktop' );
			$webp_variants   = $webp ? array( 'image/webp', 'image/avif' ) : array( 'image/webp' );

			$variants = array();
			foreach ( $webp_variants as $accept ) {
				foreach ( $mobile_variants as $mobile_v ) {
					foreach ( $guest_variants as $guest_v ) {
						$headers = array(
							'Accept'     => $accept,
							'User-Agent' => 'mobile' === $mobile_v ? 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile' : 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
						);
						if ( 'guest' === $guest_v ) {
							$headers['Cookie'] = '';
						}
						$variants[] = array(
							'url'     => $url,
							'headers' => $headers,
						);
					}
				}
			}

			/**
			 * Filter crawler variants to warm.
			 *
			 * @since NEXT
			 * @param array  $variants Variants.
			 * @param string $url Base URL.
			 */
			$variants = (array) apply_filters( 'wppo_crawler_variants', $variants, $url );
			return $variants;
		}

		/**
		 * Get URLs to crawl (posts + sitemap merge, 500 cap, 15s budget).
		 *
		 * Merges sitemap URLs (when preloadSitemap enabled) with published post permalinks,
		 * deduplicates, respects wppo_invalidation_urls and wppo_crawler_urls filters,
		 * caps at $cap and respects 15s wall-clock budget.
		 *
		 * @since NEXT
		 * @param int $cap Cap for sitemap + total URLs.
		 * @return string[]
		 */
		public static function get_urls_to_crawl( int $cap = 500 ): array {
			$cap      = max( 1, $cap );
			$deadline = microtime( true ) + 15;
			$options  = Util::get_settings();
			$preload  = $options['preload_settings'] ?? array();
			$urls     = array();

			// Sitemap URLs when preloadSitemap enabled.
			if ( ! empty( $preload['preloadSitemap'] ) ) {
				$sitemap_urls = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Cron' ) ) {
					try {
						$cron = new Cron();
						// Cron::get_sitemap_urls is now public; use it directly.
						if ( method_exists( $cron, 'get_sitemap_urls' ) ) {
							$sitemap_urls = $cron->get_sitemap_urls( $cap );
						}
					} catch ( \Throwable $e ) {
						$sitemap_urls = array();
					}
				}
				/**
				 * Filter sitemap URLs for crawler.
				 *
				 * @since NEXT
				 * @param string[] $sitemap_urls Sitemap URLs.
				 * @param int      $cap Cap.
				 */
				$sitemap_urls = (array) apply_filters( 'wppo_crawler_sitemap_urls', $sitemap_urls, $cap );
				// Enforce cap even if filtered/mocked returns more.
				if ( count( $sitemap_urls ) > $cap ) {
					$sitemap_urls = array_slice( $sitemap_urls, 0, $cap );
				}
				foreach ( $sitemap_urls as $u ) {
					$u = esc_url_raw( (string) $u );
					if ( '' !== $u ) {
						$urls[] = $u;
					}
					if ( microtime( true ) >= $deadline ) {
						break;
					}
					if ( count( $urls ) >= $cap ) {
						break;
					}
				}
			}

			if ( microtime( true ) >= $deadline ) {
				$urls = array_values( array_unique( $urls ) );
				if ( count( $urls ) > $cap ) {
					$urls = array_slice( $urls, 0, $cap );
				}
				/**
				 * Filter final crawler URLs.
				 *
				 * @since NEXT
				 * @param string[] $urls URLs.
				 */
				$urls = (array) apply_filters( 'wppo_crawler_urls', $urls );
				$urls = (array) apply_filters( 'wppo_invalidation_urls', $urls );
				return $urls;
			}

			// Merge post permalinks (up to 200, like Cron::schedule_page_cron_jobs).
			global $wpdb;
			$post_urls = array();
			if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->posts ) ) {
				try {
					$post_types = get_post_types( array( 'public' => true ), 'names' );
					$post_types = array_values( array_diff( $post_types, array( 'attachment' ) ) );
					$post_types = array_unique( array_merge( $post_types, array( 'page', 'post' ) ) );
					if ( ! empty( $post_types ) ) {
						$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
						$args         = array_values( $post_types );
						if ( method_exists( $wpdb, 'get_col' ) ) {
							// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is count-derived.
							$post_ids = $wpdb->get_col(
								// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders count-derived.
								$wpdb->prepare( // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders count-derived
									"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish' ORDER BY ID ASC LIMIT 200", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders count-derived
									...$args
								)
							);
							// phpcs:enable
							if ( is_array( $post_ids ) ) {
								foreach ( $post_ids as $pid ) {
									if ( microtime( true ) >= $deadline ) {
										break;
									}
									$permalink = get_permalink( (int) $pid );
									if ( is_string( $permalink ) && '' !== $permalink ) {
										$post_urls[] = $permalink;
									}
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					$post_urls = array();
				}
			} elseif ( function_exists( 'get_posts' ) ) {
				// Fallback via get_posts when $wpdb not available (tests).
				try {
					$posts = get_posts(
						array(
							'post_type'      => 'any',
							'post_status'    => 'publish',
							'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded sitemap batch 200
							'fields'         => 'ids',
						)
					);
					if ( is_array( $posts ) ) {
						foreach ( $posts as $pid ) {
							$permalink = get_permalink( (int) $pid );
							if ( is_string( $permalink ) && '' !== $permalink ) {
								$post_urls[] = $permalink;
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			foreach ( $post_urls as $u ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$urls[] = $u;
				if ( count( $urls ) >= $cap ) {
					break;
				}
			}

			$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
			if ( count( $urls ) > $cap ) {
				$urls = array_slice( $urls, 0, $cap );
			}

			/**
			 * Filter crawler URLs (post + sitemap merged).
			 *
			 * @since NEXT
			 * @param string[] $urls URLs.
			 */
			$urls = (array) apply_filters( 'wppo_crawler_urls', $urls );
			/**
			 * Filter invalidation URLs (compat).
			 *
			 * @since NEXT
			 * @param string[] $urls URLs.
			 */
			$urls = (array) apply_filters( 'wppo_invalidation_urls', $urls );
			$urls = array_values( array_unique( array_filter( array_map( 'esc_url_raw', $urls ) ) ) );
			if ( count( $urls ) > $cap ) {
				$urls = array_slice( $urls, 0, $cap );
			}
			return $urls;
		}

		/**
		 * Execute curl_multi batch for URLs (variant matrix).
		 *
		 * Each URL is expanded to variant matrix; requests are run via curl_multi
		 * with timeout 5s per handle and 15s wall-clock budget (mirroring get_sitemap_urls:555).
		 * Lane throttling via concurrency param; blacklist threshold 3 skips failing URLs.
		 *
		 * @since NEXT
		 * @param string[] $urls URLs to crawl.
		 * @param int|null $concurrency Concurrency override or null for setting.
		 * @return array{success:int,failed:int,skipped:int}
		 */
		public static function crawl_batch( array $urls, ?int $concurrency = null ): array {
			$urls = array_values( array_filter( array_map( 'esc_url_raw', $urls ) ) );
			if ( empty( $urls ) ) {
				return array(
					'success' => 0,
					'failed'  => 0,
					'skipped' => 0,
				);
			}

			// Load throttler: defer when overloaded (LSCWP crawler.cls.php:684).
			if ( self::is_overloaded() ) {
				if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					Log::add( 'LiteSpeed crawler deferred: high load' );
				}
				if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) ) {
					$delay = (int) ( self::get_load_limit() * 2 );
					if ( $delay < 60 ) {
						$delay = 60;
					}
					if ( ! wp_next_scheduled( 'wppo_litespeed_crawler_batch', array( $urls ) ) ) {
						wp_schedule_single_event( time() + $delay, 'wppo_litespeed_crawler_batch', array( $urls ) );
					}
				}
				return array(
					'success' => 0,
					'failed'  => 0,
					'skipped' => count( $urls ),
				);
			}

			$concurrency = $concurrency ?? self::get_concurrency();
			$concurrency = max( 1, min( 4, $concurrency ) );

			// Expand to variant matrix via varyGroups wiring.
			$requests          = array();
			$skipped_blacklist = 0;
			foreach ( $urls as $url ) {
				if ( self::is_blacklisted( $url ) ) {
					++$skipped_blacklist;
					continue;
				}
				$matrix = self::get_variants_to_warm( $url );
				if ( empty( $matrix ) ) {
					$matrix = self::build_variant_matrix( $url );
					// Fallback to primary only when varyGroups all false should be 1; ensure not empty.
					if ( count( $matrix ) > 1 ) {
						$matrix = array_slice( $matrix, 0, 1 );
					}
				}
				foreach ( $matrix as $variant ) {
					$requests[] = $variant;
				}
			}

			if ( empty( $requests ) ) {
				return array(
					'success' => 0,
					'failed'  => 0,
					'skipped' => $skipped_blacklist > 0 ? $skipped_blacklist : count( $urls ),
				);
			}

			// Prefer curl_multi if available, else fallback to wp_remote_get sequentially.
			/**
			 * Filter to disable curl_multi for testing fallback.
			 *
			 * @since NEXT
			 * @param bool $disable Whether to disable curl_multi.
			 */
			$disable_curl = (bool) apply_filters( 'wppo_crawler_disable_curl', false );
			if ( ! function_exists( 'curl_multi_init' ) || $disable_curl ) {
				$success = 0;
				$failed  = 0;
				foreach ( $requests as $req ) {
					$resp = wp_remote_get(
						$req['url'],
						array(
							'timeout' => 5,
							'headers' => $req['headers'],
						)
					);
					if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
						self::record_failure( $req['url'] );
						++$failed;
					} else {
						self::clear_blacklist( $req['url'] );
						++$success;
					}
				}
				return array(
					'success' => $success,
					'failed'  => $failed,
					'skipped' => $skipped_blacklist,
				);
			}

			$server_ip    = self::get_server_ip();
			$mh           = curl_multi_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_init -- crawler requires curl_multi for variant matrix
			$handles      = array();
			$queue        = $requests;
			$active       = 0;
			$success      = 0;
			$failed       = 0;
			$deadline     = microtime( true ) + 15;
			$index_to_url = array();

			// Helper to add next handle.
			$add_next = function () use ( &$queue, &$handles, &$index_to_url, $mh, $server_ip ) {
				if ( empty( $queue ) ) {
					return;
				}
				$req = array_shift( $queue );
				$ch  = curl_init( $req['url'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- crawler requires curl
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- crawler requires curl
				curl_setopt( $ch, CURLOPT_TIMEOUT, 5 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- crawler requires curl
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- crawler requires curl
				$headers = array();
				foreach ( $req['headers'] as $k => $v ) {
					if ( '' !== $v ) {
						$headers[] = $k . ': ' . $v;
					}
				}
				if ( ! empty( $headers ) ) {
					curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- crawler requires curl
				}
				// CURLOPT_RESOLVE optimization when server IP known.
				if ( '' !== $server_ip && defined( 'CURLOPT_RESOLVE' ) ) {
					$host = wp_parse_url( $req['url'], PHP_URL_HOST );
					if ( is_string( $host ) && '' !== $host ) {
						$resolve = array(
							$host . ':80:' . $server_ip,
							$host . ':443:' . $server_ip,
						);
						curl_setopt( $ch, CURLOPT_RESOLVE, $resolve ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt -- crawler requires curl
					}
				}
				curl_multi_add_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_add_handle -- crawler requires curl_multi
				$handles[]                 = $ch;
				$index_to_url[ (int) $ch ] = $req['url'];
			};

			// Prime initial batch.
			for ( $i = 0; $i < $concurrency && ! empty( $queue ); ++$i ) {
				$add_next();
			}

			do {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$status = curl_multi_exec( $mh, $active ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_exec -- crawler requires curl_multi
				if ( $active ) {
					curl_multi_select( $mh, 1 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_select
				}
				// Process completed handles.
				while ( ( $info = curl_multi_info_read( $mh ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition,WordPress.WP.AlternativeFunctions.curl_curl_multi_info_read -- intentional loop
					$ch   = $info['handle'];
					$code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo -- crawler requires curl
					$err  = curl_error( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
					$url  = $index_to_url[ (int) $ch ] ?? '';
					if ( '' !== $url ) {
						if ( '' !== $err || $code >= 400 || 0 === $code ) {
							self::record_failure( $url );
							++$failed;
						} else {
							self::clear_blacklist( $url );
							++$success;
						}
					}
					curl_multi_remove_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle -- crawler requires curl_multi
					curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- crawler requires curl
					// Remove from handles.
					$key = array_search( $ch, $handles, true );
					if ( false !== $key ) {
						unset( $handles[ $key ] );
					}
					// Add next from queue if within deadline.
					if ( microtime( true ) < $deadline && ! empty( $queue ) ) {
						$add_next();
					}
				}
			} while ( $active && CURLM_OK === $status && microtime( true ) < $deadline );

			// Cleanup remaining.
			foreach ( $handles as $ch ) {
				curl_multi_remove_handle( $mh, $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_remove_handle -- crawler requires curl_multi
				curl_close( $ch ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close -- crawler requires curl
			}
			curl_multi_close( $mh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- crawler requires curl_multi

			return array(
				'success' => $success,
				'failed'  => $failed,
				'skipped' => $skipped_blacklist,
			);
		}

		/**
		 * Lane wrapper for single URL preload (wraps process_url).
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return void
		 */
		public static function crawl_single( string $url ): void {
			$url = esc_url_raw( $url );
			if ( '' === $url ) {
				return;
			}
			if ( self::is_blacklisted( $url ) ) {
				return;
			}
			if ( self::is_overloaded() ) {
				if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					Log::add( 'LiteSpeed crawler single deferred: high load for ' . $url );
				}
				if ( function_exists( 'wp_schedule_single_event' ) && function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( 'wppo_crawler_warm', array( $url ) ) ) {
					wp_schedule_single_event( time() + 60, 'wppo_crawler_warm', array( $url ) );
				}
				return;
			}
			$variants = self::get_variants_to_warm( $url );
			$primary  = $variants[0] ?? array(
				'url'     => $url,
				'headers' => array(),
			);
			// Respect is_litespeed && is_wppo_cache_owner gating for LS headers? Non-LS still warms file cache.
			$resp = wp_remote_get(
				$primary['url'],
				array(
					'timeout' => 5,
					'headers' => $primary['headers'],
				)
			);
			if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) >= 400 ) {
				self::record_failure( $url );
			} else {
				self::clear_blacklist( $url );
			}
		}

		/**
		 * Get crawler queue status.
		 *
		 * @since NEXT
		 * @return array{concurrency:int,load_limit:float,overloaded:bool,server_ip:string}
		 */
		public static function get_status(): array {
			return array(
				'concurrency' => self::get_concurrency(),
				'load_limit'  => self::get_load_limit(),
				'overloaded'  => self::is_overloaded(),
				'server_ip'   => self::get_server_ip(),
			);
		}
	}
}
