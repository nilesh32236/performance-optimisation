<?php
/**
 * Cache capacity/accounting owner.
 *
 * P3-005: owns static-cache statistics, cap policy, single-walk byte/file
 * accounting, randomized-query detection, and oldest-entry eviction. Cache
 * keeps the public compatibility facade. Host/path security, HTML buffering,
 * invalidation, object-cache flush APIs, and storage policy remain on Cache and
 * Cache_Invalidator; this service reaches only narrow @internal owner bridges.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache_Capacity' ) ) {
	/**
	 * Cache statistics, capacity, and eviction service.
	 *
	 * @since NEXT
	 */
	final class Cache_Capacity {

		/**
		 * Cache owner for request-scoped root/domain/filesystem state.
		 *
		 * @var Cache
		 * @since NEXT
		 */
		private Cache $cache;

		/**
		 * Whether the depth-guard warning has been logged this request.
		 *
		 * @var bool
		 * @since NEXT
		 */
		private static bool $depth_warning_logged = false;

		/**
		 * Constructor.
		 *
		 * @param Cache $cache Cache owner providing narrow state/deletion bridges.
		 * @since NEXT
		 */
		public function __construct( Cache $cache ) {
			$this->cache = $cache;
		}

		/**
		 * Get the size of the cache.
		 *
		 * @return string
		 *
		 * @since 1.0.0
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function get_cache_size(): string {
			$stats = $this->get_cache_stats();

			if ( ! isset( $stats['size'] ) || ! is_string( $stats['size'] ) ) {
				return (string) ( $stats['size'] ?? '' );
			}

			$cache_dir = $stats['cache_dir'] ?? '';

			if ( ! $this->cache->capacity_filesystem() ) {
				return '' === $cache_dir ? __( 'Unable to initialize filesystem.', 'performance-optimisation' ) : $stats['size'];
			}

			if ( '' === $cache_dir || ! $this->cache->capacity_filesystem()->is_dir( $cache_dir ) ) {
				return __( 'Cache directory does not exist.', 'performance-optimisation' );
			}

			return $stats['size'];
		}

		/**
		 * Get detailed cache statistics.
		 *
		 * Returns size, cached page count, last-cleared timestamp, and cache directory path.
		 * Size and count are cached atomically in a single transient (wppo_cache_stats) to avoid
		 * race conditions where one field is refreshed and the other remains stale.
		 *
		 * @since 2.0.0
		 * @return array{size: string, cached_pages: int, last_cleared: string, cache_dir: string}
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function get_cache_stats(): array {
			$stats = array(
				'size'         => __( 'N/A', 'performance-optimisation' ),
				'cached_pages' => 0,
				'last_cleared' => '',
				'cache_dir'    => '',
			);

			if ( ! $this->cache->capacity_filesystem() ) {
				return $stats;
			}

			$cache_dir          = "{$this->cache->capacity_cache_root_dir()}/{$this->cache->capacity_domain()}";
			$stats['cache_dir'] = $cache_dir;

			if ( ! $this->cache->capacity_filesystem()->is_dir( $cache_dir ) ) {
				return $stats;
			}

			// Salted object-cache layer (WP 6.9+, issue #882): the salt is the
			// `wppo_cache_last_cleared` option VALUE — bump_stats_cache() bumps
			// it on every stats mutation, so salted entries invalidate together
			// with the transient (issue #894 follow-up: pass the value, not the
			// key, or the bump never reaches the comparison).
			$stats_key = Util::transient_key( 'wppo_cache_stats' );
			// Salted layer requires a persistent object cache; the transient
			// fallback keeps the stats across requests otherwise (issue #882 review).
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				$cached_stats = wp_cache_get_salted( 'wppo_cache_stats', 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ) );
				// Salted eviction (TTL) without a bump: the unified transient
				// written by store_cache_stats() is still fresh — reuse it
				// instead of rescanning the directory (issue #882 review).
				if ( false === $cached_stats ) {
					$cached_stats = get_transient( $stats_key );
				}
			} else {
				$cached_stats = get_transient( $stats_key );
			}
			if ( is_array( $cached_stats ) && isset( $cached_stats['size'], $cached_stats['count'] ) ) {
				$stats['size']         = (string) $cached_stats['size'];
				$stats['cached_pages'] = (int) $cached_stats['count'];
				$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );
				return $stats;
			}

			// Cache miss: compute size and page count in a single recursive
			// walk so large caches pay one filesystem enumeration, not two.
			// Stampede guard (issue #1101): concurrent misses collapse toward one
			// walk; losers re-read the peer's fresh value, then the 24h stale
			// copy, and only then return N/A without walking. Honors the
			// operator opt-out (is_stampede_guard_enabled()): when disabled the
			// walk runs unguarded. Fail-open: the next request retries.
			$stale_stats_key = Util::stampede_stale_key( $stats_key );
			$guard_enabled   = true;
			try {
				$guard_enabled = Util::is_stampede_guard_enabled();
			} catch ( \Throwable $e ) {
				unset( $e );
				$guard_enabled = true;
			}
			$read_fresh_stats  = static function () use ( $stats_key ): mixed {
				try {
					if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
						$hit = wp_cache_get_salted( 'wppo_cache_stats', 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ) );
						if ( is_array( $hit ) && isset( $hit['size'], $hit['count'] ) ) {
							return $hit;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				try {
					return get_transient( $stats_key );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			};
			$write_stale_stats = static function ( array $unified ) use ( $stale_stats_key ): void {
				try {
					$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
					set_transient( $stale_stats_key, $unified, $ttl );
					Util::register_transient_index_key( $stale_stats_key, $ttl );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			};
			$stats_lock        = Util::transient_key( 'wppo_stampede_' . md5( $stats_key ) );
			$stats_owner       = Util::generate_stampede_owner();
			$stats_locked      = true;
			if ( $guard_enabled ) {
				$stats_locked = Util::acquire_stampede_lock( $stats_lock, $stats_owner, Util::stampede_lock_ttl() );
			}
			if ( ! $stats_locked ) {
				$recheck = $read_fresh_stats();
				if ( is_array( $recheck ) && isset( $recheck['size'], $recheck['count'] ) ) {
					$stats['size']         = (string) $recheck['size'];
					$stats['cached_pages'] = (int) $recheck['count'];
				} else {
					try {
						$stale_hit = get_transient( $stale_stats_key );
					} catch ( \Throwable $e ) {
						unset( $e );
						$stale_hit = false;
					}
					if ( is_array( $stale_hit ) && isset( $stale_hit['size'], $stale_hit['count'] ) ) {
						$stats['size']         = (string) $stale_hit['size'];
						$stats['cached_pages'] = (int) $stale_hit['count'];
					}
				}
				$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );
				return $stats;
			}
			try {
				$dir_stats             = $this->calculate_directory_stats( $cache_dir );
				$total_size            = $dir_stats['size'];
				$stats['size']         = size_format( $total_size );
				$stats['cached_pages'] = $dir_stats['count'];
				$unified               = array(
					'size'  => $stats['size'],
					'count' => $stats['cached_pages'],
				);
				$this->store_cache_stats( $unified, $stats_key );
				$write_stale_stats( $unified );

				$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );

				return $stats;
			} finally {
				if ( $guard_enabled ) {
					Util::release_stampede_lock( $stats_lock, $stats_owner );
				}
			}
		}

		/**
		 * Store the unified cache-stats payload in the salted object cache
		 * (WP 6.9+) and the transient fallback.
		 *
		 * The salted entry shares the `wppo_cache_last_cleared` salt with
		 * {@see bump_stats_cache()} so any stats mutation invalidates it
		 * immediately (issue #882). The transient write is kept for cores
		 * without the salted cache family and for BC with external consumers.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $unified   Unified stats payload.
		 * @param string              $stats_key Transient key (multisite-prefixed).
		 * @return void
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function store_cache_stats( array $unified, string $stats_key ): void {
			if ( function_exists( 'wp_cache_set_salted' ) && function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				wp_cache_set_salted( 'wppo_cache_stats', $unified, 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ), 15 * MINUTE_IN_SECONDS );
			}
			set_transient( $stats_key, $unified, 15 * MINUTE_IN_SECONDS );
		}

		/**
		 * List direct children of a cache directory via the WP filesystem.
		 *
		 * Single shared `$fs->dirlist()` enumeration point for
		 * {@see calculate_directory_stats()} and
		 * {@see collect_cache_entries_by_age()} so cap accounting and
		 * oldest-entry eviction can never drift apart.
		 *
		 * @since 2.2.0
		 * @param string $directory Directory path.
		 * @return array|null Dirlist entries, or null when unavailable.
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function list_cache_children( string $directory ): ?array {
			$fs = $this->cache->capacity_filesystem();
			if ( ! $fs ) {
				return null;
			}
			$files = $fs->dirlist( $directory );
			if ( ! $files || ! is_array( $files ) ) {
				return null;
			}
			return $files;
		}

		/**
		 * Calculate directory size and cached-page count in a single walk.
		 *
		 * Single recursive `$fs->dirlist()` traversal returning both
		 * aggregates so callers do not enumerate large static caches twice.
		 * Reuses the `size` already reported by `dirlist()` when available
		 * instead of issuing a second `size()` stat per file.
		 *
		 * @param string $directory The path to the directory to scan.
		 * @param int    $depth     Recursion depth guard.
		 * @return array{size:int,count:int} Total bytes and index.html count.
		 *
		 * @since 2.0.0
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function calculate_directory_stats( string $directory, int $depth = 0 ): array {
			$empty = array(
				'size'  => 0,
				'count' => 0,
			);
			// Guard against unbounded recursion on very large caches (10k+ pages).
			if ( $depth > 20 ) {
				if ( ! self::$depth_warning_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					self::$depth_warning_logged = true;
					// error_log (not Log::add()) is intentional: this runs during size
					// stats computation where DB writes are undesirable, and filesystem
					// anomalies (symlink loops) are server-ops signal. Fires once per
					// request, strictly WP_DEBUG-gated.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO: calculate_directory_stats depth cap (20) hit at ' . $directory . ' — stats may be under-reported due to deep nesting or symlink loop.' );
				}
				return $empty;
			}
			$fs = $this->cache->capacity_filesystem();

			if ( ! $fs ) {
				return $empty;
			}

			$files = $this->list_cache_children( $directory );

			if ( null === $files ) {
				return $empty;
			}

			$size  = 0;
			$count = 0;
			foreach ( $files as $file ) {
				$file_path = trailingslashit( $directory ) . $file['name'];
				if ( 'd' === $file['type'] ) {
					$child  = $this->calculate_directory_stats( $file_path, $depth + 1 );
					$size  += $child['size'];
					$count += $child['count'];
					continue;
				}
				if ( isset( $file['size'] ) && is_numeric( $file['size'] ) && (int) $file['size'] >= 0 ) {
					$size += (int) $file['size'];
				} else {
					$size += (int) $fs->size( $file_path );
				}
				if ( 'index.html' === $file['name'] ) {
					++$count;
				}
			}

			return array(
				'size'  => $size,
				'count' => $count,
			);
		}

		/**
		 * Calculate the size of a directory.
		 *
		 * @param string $directory The path to the directory whose size is to be calculated.
		 * @param int    $depth     Recursion depth guard.
		 * @return int The total size of the directory in bytes.
		 *
		 * @since 1.0.0
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function calculate_directory_size( string $directory, int $depth = 0 ): int {
			$stats = $this->calculate_directory_stats( $directory, $depth );
			return $stats['size'];
		}

		/**
		 * Recursively count cached pages by counting index.html files in the cache directory.
		 *
		 * @param string $directory The directory to scan.
		 * @param int    $depth     Recursion depth guard.
		 * @return int Number of index.html files found.
		 *
		 * @since 1.9.0
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function count_cached_pages( string $directory, int $depth = 0 ): int {
			$stats = $this->calculate_directory_stats( $directory, $depth );
			return $stats['count'];
		}

		/**
		 * Read the bounded-cache cap settings with fail-safe defaults.
		 *
		 * Additive `cache_settings` keys (issue #1162): `cacheMaxSizeMB`
		 * (default 512), `cacheSizeWarnRatio` (default 0.8),
		 * `cacheSizeEnforce` (default true). Disk-safe slice (issue #1428):
		 * `cacheMaxFiles` (default 5000, clamp 100-100000) and
		 * `cacheRandomizedQueryGuard` (default true). Missing or malformed stored
		 * values fall back to defaults; enforcement never blocks the
		 * frontend — the cap only warns first, then evicts oldest entries.
		 *
		 * @since 2.2.0
		 * @since 2.3.0 Disk-safe slice (issue #1428): `max_files`/`randomized_guard` keys.
		 * @return array{max_mb:int,warn_ratio:float,enforce:bool,max_files:int,randomized_guard:bool}
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public static function get_cache_cap_settings(): array {
			$defaults = array(
				'max_mb'           => 512,
				'warn_ratio'       => 0.8,
				'enforce'          => true,
				'max_files'        => 5000,
				'randomized_guard' => true,
			);
			try {
				$settings = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$all      = Util::get_settings();
					$settings = isset( $all['cache_settings'] ) && is_array( $all['cache_settings'] ) ? $all['cache_settings'] : array();
				}
				if ( isset( $settings['cacheMaxSizeMB'] ) && is_numeric( $settings['cacheMaxSizeMB'] ) ) {
					$max_mb = (int) $settings['cacheMaxSizeMB'];
					if ( $max_mb > 0 && $max_mb <= 10240 ) {
						$defaults['max_mb'] = $max_mb;
					}
				}
				if ( isset( $settings['cacheSizeWarnRatio'] ) && is_numeric( $settings['cacheSizeWarnRatio'] ) ) {
					$ratio = (float) $settings['cacheSizeWarnRatio'];
					if ( $ratio > 0 && $ratio < 1 ) {
						$defaults['warn_ratio'] = $ratio;
					}
				}
				if ( array_key_exists( 'cacheSizeEnforce', $settings ) ) {
					$parsed = filter_var( $settings['cacheSizeEnforce'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $parsed ) {
						$defaults['enforce'] = $parsed;
					}
				}
				if ( isset( $settings['cacheMaxFiles'] ) && is_numeric( $settings['cacheMaxFiles'] ) ) {
					$max_files             = (int) $settings['cacheMaxFiles'];
					$defaults['max_files'] = max( 100, min( 100000, $max_files ) );
				}
				if ( array_key_exists( 'cacheRandomizedQueryGuard', $settings ) ) {
					$parsed = filter_var( $settings['cacheRandomizedQueryGuard'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $parsed ) {
						$defaults['randomized_guard'] = $parsed;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $defaults;
		}

		/**
		 * Total static-cache bytes + file count in a single directory walk.
		 *
		 * Shared single-walk helper behind {@see get_cache_size_bytes()},
		 * {@see get_cache_file_count()}, and {@see get_cache_cap_status()}
		 * so status paths enumerate large caches once instead of once per
		 * dimension. Fail-open: returns zeros when the filesystem or
		 * directory is unavailable.
		 *
		 * @since 2.3.0
		 * @return array{bytes:int,files:int} Bytes used and file count.
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function get_cache_bytes_and_files(): array {
			try {
				if ( ! $this->cache->capacity_filesystem() ) {
					return array(
						'bytes' => 0,
						'files' => 0,
					);
				}
				$dir = trailingslashit( $this->cache->capacity_cache_root_dir() );
				if ( '' !== $this->cache->capacity_domain() ) {
					$dir .= $this->cache->capacity_domain();
				}
				if ( ! $this->cache->capacity_filesystem()->is_dir( $dir ) ) {
					return array(
						'bytes' => 0,
						'files' => 0,
					);
				}
				$stats = $this->calculate_directory_stats( $dir );
				return array(
					'bytes' => max( 0, (int) ( $stats['size'] ?? 0 ) ),
					'files' => max( 0, (int) ( $stats['count'] ?? 0 ) ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'bytes' => 0,
					'files' => 0,
				);
			}
		}

		/**
		 * Total static-cache size in bytes for the current domain.
		 *
		 * Fail-open: returns 0 when the filesystem or directory is
		 * unavailable. Uses the single-walk {@see calculate_directory_stats()}
		 * helper so size accounting matches the dashboard stats.
		 *
		 * @since 2.2.0
		 * @return int Bytes used, or 0 on failure.
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function get_cache_size_bytes(): int {
			$stats = $this->get_cache_bytes_and_files();
			return max( 0, (int) ( $stats['bytes'] ?? 0 ) );
		}

		/**
		 * Total cached-page file count for the current domain.
		 *
		 * Fail-open: returns 0 when the filesystem or directory is
		 * unavailable. Shares the single-walk {@see calculate_directory_stats()}
		 * enumeration with {@see get_cache_size_bytes()} so cap accounting
		 * and eviction never drift.
		 *
		 * @since 2.3.0
		 * @return int File count, or 0 on failure.
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function get_cache_file_count(): int {
			$stats = $this->get_cache_bytes_and_files();
			return max( 0, (int) ( $stats['files'] ?? 0 ) );
		}

		/**
		 * Whether an asset URL carries a randomized per-request query value.
		 *
		 * Matches `?ver=<timestamp|uniqid|rand>`-style churn that would
		 * regenerate combined output on every request and defeat the
		 * file-count cap. Stable content hashes (`md5` 32 / `sha1` 40 /
		 * `sha256` 64 hex chars) are deterministic per file and stay
		 * combinable. Fail-open: any parse failure returns false.
		 * Filterable via `wppo_exclude_randomized_from_combine`.
		 *
		 * @since 2.3.0
		 * @param string $src Asset src URL.
		 * @return bool True when the query looks randomized.
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public static function is_randomized_query_asset( string $src ): bool {
			try {
				$src = trim( $src );
				if ( '' === $src ) {
					return false;
				}
				$query = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$parts = wp_parse_url( $src, PHP_URL_QUERY );
					$query = is_string( $parts ) ? $parts : '';
				} elseif ( function_exists( 'parse_url' ) ) {
					$parts = parse_url( $src, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
					$query = is_string( $parts ) ? $parts : '';
				}
				if ( '' === $query ) {
					return false;
				}
				$randomized_keys = array( 'ver', 'version', 'v', 't', 'ts', 'timestamp', 'time', 'rand', 'random', 'nonce', '_' );
				$pairs           = preg_split( '/[&;]/', (string) $query );
				if ( ! is_array( $pairs ) ) {
					$pairs = explode( '&', (string) $query );
				}
				foreach ( $pairs as $pair ) {
					$kv    = explode( '=', $pair, 2 );
					$key   = strtolower( trim( (string) ( $kv[0] ?? '' ) ) );
					$value = trim( (string) ( $kv[1] ?? '' ) );
					if ( '' === $value || ! in_array( $key, $randomized_keys, true ) ) {
						continue;
					}
					$decoded = function_exists( 'urldecode' ) ? urldecode( $value ) : $value;
					// Long digit runs (epoch timestamps, 10+ digits so YYYYMMDD
					// date versions stay combinable), long hex (uniqid-style
					// churn), or mixed alnum tokens.
					if ( 1 === preg_match( '/^\d{10,}$/', $decoded ) ) {
						return true;
					}
					if ( 1 === preg_match( '/^[0-9a-f]{10,}$/i', $decoded ) ) {
						// Stable content hashes (md5 32 / sha1 40 / sha256 64)
						// are deterministic per file content, not per-request
						// churn, so they stay combinable. Non-canonical hex
						// lengths can still opt out via the
						// wppo_exclude_randomized_from_combine filter.
						$hex_len = strlen( $decoded );
						if ( 32 === $hex_len || 40 === $hex_len || 64 === $hex_len ) {
							continue;
						}
						return true;
					}
					if ( 1 === preg_match( '/^[0-9a-z]{12,}$/i', $decoded ) && 1 === preg_match( '/[0-9]/', $decoded ) && 1 === preg_match( '/[a-z]/i', $decoded ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Warn-before-enforce cap status for the current domain cache.
		 *
		 * States: `ok` (under warn threshold), `warn` (over warn threshold
		 * but under cap, or over cap with enforcement off), `over` (over cap
		 * with enforcement on). Never fatal; all failures report `ok`.
		 * Both cap dimensions (bytes + file count) feed the state; either
		 * dimension can push `ok` to `warn`/`over`.
		 *
		 * @since 2.2.0
		 * @since 2.3.0 File-count dimension (issue #1428): `files`/`cap_files`/`warn_files` fields.
		 * @return array{bytes:int,cap_bytes:int,warn_bytes:int,state:string,enforce:bool,max_mb:int,files:int,cap_files:int,warn_files:int}
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function get_cache_cap_status(): array {
			$cap        = self::get_cache_cap_settings();
			$cap_bytes  = $cap['max_mb'] * 1024 * 1024;
			$warn_bytes = (int) ( $cap_bytes * $cap['warn_ratio'] );
			$cap_files  = (int) $cap['max_files'];
			$warn_files = (int) ( $cap_files * $cap['warn_ratio'] );
			$status     = array(
				'bytes'      => 0,
				'cap_bytes'  => $cap_bytes,
				'warn_bytes' => $warn_bytes,
				'state'      => 'ok',
				'enforce'    => $cap['enforce'],
				'max_mb'     => $cap['max_mb'],
				'files'      => 0,
				'cap_files'  => $cap_files,
				'warn_files' => $warn_files,
			);
			try {
				// Single directory walk for both dimensions so accounting
				// and eviction never drift on large caches.
				$both            = $this->get_cache_bytes_and_files();
				$bytes           = (int) ( $both['bytes'] ?? 0 );
				$files           = (int) ( $both['files'] ?? 0 );
				$status['bytes'] = $bytes;
				$status['files'] = $files;
				$over_bytes      = $bytes >= $cap_bytes;
				$over_files      = $files >= $cap_files;
				if ( $over_bytes || $over_files ) {
					$status['state'] = $cap['enforce'] ? 'over' : 'warn';
				} elseif ( $bytes >= $warn_bytes || $files >= $warn_files ) {
					$status['state'] = 'warn';
				}
				// Surface a persisted warning flag so the SPA can render it
				// without re-walking the directory on every admin request.
				// Self-healing: when the fresh walk reports `ok`, any stale
				// flag left from an earlier breach is deleted immediately so
				// the SPA stops warning on recovery instead of lingering
				// until the 12h TTL expires.
				$warn_key = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$warn_key = Util::transient_key( 'wppo_cache_size_warning' );
				}
				if ( '' !== $warn_key && function_exists( 'get_transient' ) ) {
					$flag = get_transient( $warn_key );
					if ( 'warn' === $status['state'] || 'over' === $status['state'] ) {
						$status['warning'] = true;
					} elseif ( false !== $flag ) {
						if ( function_exists( 'delete_transient' ) ) {
							try {
								delete_transient( $warn_key );
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $status;
		}

		/**
		 * Warn-before-enforce size+count cap check after a cache write.
		 *
		 * Throttled to at most one directory walk per 5 minutes via a
		 * transient lock so frontend writes stay cheap. When usage passes
		 * either warn threshold (bytes or file count) a warning transient
		 * is set (honest UI signal + admin alert); when usage passes
		 * either cap and enforcement is on, the oldest entries are evicted
		 * until back under both caps. Never blocks the frontend: every
		 * failure path returns silently.
		 *
		 * @since 2.2.0
		 * @return void
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function maybe_enforce_cache_cap(): void {
			try {
				if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
					return;
				}
				$lock_key = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$lock_key = Util::transient_key( 'wppo_cache_cap_check_lock' );
				} else {
					return;
				}
				if ( false !== get_transient( $lock_key ) ) {
					return;
				}
				$lock_ttl = defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300;
				set_transient( $lock_key, 1, $lock_ttl );

				$status   = $this->get_cache_cap_status();
				$bytes    = (int) $status['bytes'];
				$files    = (int) ( $status['files'] ?? 0 );
				$warn_key = Util::transient_key( 'wppo_cache_size_warning' );
				$warned   = $bytes >= (int) $status['warn_bytes'] || $files >= (int) ( $status['warn_files'] ?? PHP_INT_MAX );
				if ( $warned ) {
					$warn_ttl = defined( 'HOUR_IN_SECONDS' ) ? 12 * HOUR_IN_SECONDS : 43200;
					set_transient( $warn_key, 1, $warn_ttl );
				} else {
					if ( function_exists( 'delete_transient' ) ) {
						delete_transient( $warn_key );
					}
					return;
				}
				if ( $bytes < (int) $status['cap_bytes'] && $files < (int) ( $status['cap_files'] ?? PHP_INT_MAX ) ) {
					return;
				}
				if ( empty( $status['enforce'] ) ) {
					return;
				}
				$evicted = false;
				$to_free = $bytes - (int) $status['cap_bytes'];
				if ( $to_free > 0 ) {
					$this->evict_oldest_cache_entries( $to_free );
					$evicted = true;
					// Re-read the file count after byte eviction so the
					// count phase below budgets against post-eviction
					// state instead of over-evicting on a stale value.
					// Fail-open: a 0 re-read only defers count eviction
					// to the next throttled run.
					$files = $this->get_cache_file_count();
				}
				$cap_files = (int) ( $status['cap_files'] ?? 0 );
				if ( $cap_files > 0 && $files >= $cap_files ) {
					$this->evict_oldest_cache_files_by_count( $files - $cap_files + 1 );
					$evicted = true;
				}
				if ( $evicted ) {
					Cache::bump_stats_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Evict oldest cached pages until at least the given bytes are freed.
		 *
		 * Deletes `index.html` plus its `.gz`/`.br` siblings, oldest mtime
		 * first, bounded to 2000 entries per run so a single request cannot
		 * stall on a massive cache. Fail-open: filesystem failures stop the
		 * walk silently.
		 *
		 * @since 2.2.0
		 * @param int $bytes_to_free Minimum bytes to reclaim.
		 * @return int Bytes actually freed (best effort).
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function evict_oldest_cache_entries( int $bytes_to_free ): int {
			$freed = 0;
			try {
				if ( ! $this->cache->capacity_filesystem() ) {
					return 0;
				}
				$dir = trailingslashit( $this->cache->capacity_cache_root_dir() );
				if ( '' !== $this->cache->capacity_domain() ) {
					$dir .= $this->cache->capacity_domain();
				}
				$entries = $this->collect_cache_entries_by_age( $dir );
				if ( empty( $entries ) ) {
					return 0;
				}
				usort(
					$entries,
					static function ( $a, $b ) {
						return ( (int) ( $a['mtime'] ?? 0 ) ) <=> ( (int) ( $b['mtime'] ?? 0 ) );
					}
				);
				$budget = min( count( $entries ), 2000 );
				for ( $i = 0; $i < $budget && $freed < $bytes_to_free; ++$i ) {
					$file = (string) ( $entries[ $i ]['path'] ?? '' );
					if ( '' === $file || ! $this->cache->capacity_is_path_contained( $file ) ) {
						continue;
					}
					$size = (int) ( $entries[ $i ]['size'] ?? 0 );
					if ( $this->cache->capacity_delete_cache_files( $file ) ) {
						$freed += $size;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return max( 0, $freed );
		}

		/**
		 * Evict oldest cached pages until the file count drops by the given amount.
		 *
		 * Oldest-mtime-first via the shared {@see collect_cache_entries_by_age()}
		 * enumeration so byte-cap and file-count-cap eviction can never drift.
		 * Bounded to 2000 deletions per run; large overshoots converge over
		 * multiple throttled runs (see {@see maybe_enforce_cache_cap()}).
		 * Fail-open: filesystem failures stop silently.
		 *
		 * @since 2.3.0
		 * @param int $files_to_free Minimum entries to remove.
		 * @return int Entries actually removed (best effort).
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function evict_oldest_cache_files_by_count( int $files_to_free ): int {
			$removed = 0;
			try {
				if ( $files_to_free <= 0 ) {
					return 0;
				}
				if ( ! $this->cache->capacity_filesystem() ) {
					return 0;
				}
				$dir = trailingslashit( $this->cache->capacity_cache_root_dir() );
				if ( '' !== $this->cache->capacity_domain() ) {
					$dir .= $this->cache->capacity_domain();
				}
				$entries = $this->collect_cache_entries_by_age( $dir );
				if ( empty( $entries ) ) {
					return 0;
				}
				usort(
					$entries,
					static function ( $a, $b ) {
						return ( (int) ( $a['mtime'] ?? 0 ) ) <=> ( (int) ( $b['mtime'] ?? 0 ) );
					}
				);
				$budget = min( count( $entries ), 2000 );
				for ( $i = 0; $i < $budget && $removed < $files_to_free; ++$i ) {
					$file = (string) ( $entries[ $i ]['path'] ?? '' );
					if ( '' === $file || ! $this->cache->capacity_is_path_contained( $file ) ) {
						continue;
					}
					if ( $this->cache->capacity_delete_cache_files( $file ) ) {
						++$removed;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return max( 0, $removed );
		}

		/**
		 * Collect cache `index.html` entries with mtime + size for eviction.
		 *
		 * Recursive `$fs->dirlist()` walk capped at depth 20 and 5000
		 * entries so eviction stays bounded on huge caches.
		 *
		 * @since 2.2.0
		 * @param string $directory Directory to scan.
		 * @param int    $depth     Recursion depth guard.
		 * @param array  $out       Accumulator (passed by reference).
		 * @return array<int, array{path:string,mtime:int,size:int}> Collected entries.
		 * @since NEXT Extracted with the Cache_Capacity accounting owner.
		 */
		public function collect_cache_entries_by_age( string $directory, int $depth = 0, array &$out = array() ): array {
			try {
				if ( $depth > 20 || count( $out ) >= 5000 ) {
					return $out;
				}
				$files = $this->list_cache_children( $directory );
				if ( null === $files ) {
					return $out;
				}
				$fs = $this->cache->capacity_filesystem();
				if ( ! $fs ) {
					return $out;
				}
				foreach ( $files as $file ) {
					if ( count( $out ) >= 5000 ) {
						break;
					}
					$file_path = trailingslashit( $directory ) . $file['name'];
					if ( 'd' === $file['type'] ) {
						$this->collect_cache_entries_by_age( $file_path, $depth + 1, $out );
						continue;
					}
					if ( 'index.html' !== $file['name'] ) {
						continue;
					}
					$size  = isset( $file['size'] ) && is_numeric( $file['size'] ) ? (int) $file['size'] : (int) $fs->size( $file_path );
					$mtime = 0;
					if ( isset( $file['lastmodunix'] ) && is_numeric( $file['lastmodunix'] ) ) {
						$mtime = (int) $file['lastmodunix'];
					} else {
						$mtime = (int) $fs->mtime( $file_path );
					}
					$siblings = $size;
					foreach ( array( '.gz', '.br' ) as $suffix ) {
						$sibling = $file_path . $suffix;
						if ( $fs->exists( $sibling ) ) {
							$siblings += (int) $fs->size( $sibling );
						}
					}
					$out[] = array(
						'path'  => $file_path,
						'mtime' => $mtime,
						'size'  => $siblings,
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $out;
		}
	}
}
