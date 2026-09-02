<?php
/**
 * LiteSpeed Integration class for the PerformanceOptimise plugin.
 *
 * Provides detection, coexistence mode logic, and optimizer guard helpers
 * for LiteSpeed / OpenLiteSpeed hosts. All methods are cheap and cache
 * per-request results via static properties.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {

	/**
	 * Class LiteSpeed_Integration
	 *
	 * Manages LiteSpeed detection, LSCache coexistence modes, and
	 * optimizer guard decisions.
	 *
	 * @since NEXT
	 */
	final class LiteSpeed_Integration {

		/**
		 * Mode: auto-detect (default).
		 *
		 * @since NEXT
		 * @var string
		 */
		public const MODE_AUTO = 'auto';

		/**
		 * Mode: WPPO owns cache/optimizer.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const MODE_WPPO = 'wppo';

		/**
		 * Mode: LiteSpeed Cache owns cache/optimizer.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const MODE_LITESPEED = 'litespeed';

		/**
		 * Mode: standalone (ignore LiteSpeed server).
		 *
		 * @since NEXT
		 * @var string
		 */
		public const MODE_STANDALONE = 'standalone';

		/**
		 * Allowed modes.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const ALLOWED_MODES = array(
			self::MODE_AUTO,
			self::MODE_WPPO,
			self::MODE_LITESPEED,
			self::MODE_STANDALONE,
		);

		/**
		 * Transient key for purge-loop lock.
		 *
		 * Used to prevent infinite purge loops between WPPO and LSCache
		 * (WPPO→LS→WPPO) when purgeSync is enabled.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const PURGE_LOCK = 'wppo_litespeed_purge_lock';

		/**
		 * TTL for purge-loop lock in seconds.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const PURGE_LOCK_TTL = 60;

		/**
		 * Per-request cached effective mode.
		 *
		 * Null means not yet resolved.
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private static ?string $cached_effective_mode = null;

		/**
		 * Per-request cached get_mode value.
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private static ?string $cached_mode = null;

		/**
		 * Per-request cached litespeed detection.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_is_litespeed = null;

		/**
		 * Per-request cached LSCache active detection.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_is_lscache_active = null;

		/**
		 * Per-request cached LiteSpeed TTL seconds.
		 *
		 * Null means not yet resolved.
		 *
		 * @since NEXT
		 * @var int|null
		 */
		private static ?int $cached_ttl = null;

		/**
		 * Per-request cached cacheability check.
		 *
		 * Null means not yet resolved.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_is_cacheable = null;

		/**
		 * Per-request cached vary-by-role check.
		 *
		 * Null means not yet resolved.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_should_vary = null;

		/**
		 * Per-request cached next-gen rewrite enabled check.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_nextgen = null;

		/**
		 * Per-request cached brotli enabled check.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_brotli = null;

		/**
		 * Per-request cached CDN allowed check.
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static ?bool $cached_can_cdn = null;

		/**
		 * Whether Phase 3 hooks (send_headers, vary) are registered.
		 *
		 * Prevents double-registration when init() is called multiple times.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $hooks_registered = false;

		/**
		 * Whether the current server is LiteSpeed / OpenLiteSpeed.
		 *
		 * Delegates to Server_Rules::is_litespeed() when available.
		 *
		 * @since NEXT
		 * @return bool True if LiteSpeed or OpenLiteSpeed is detected.
		 */
		public static function is_litespeed(): bool {
			if ( null !== self::$cached_is_litespeed ) {
				return self::$cached_is_litespeed;
			}

			if ( class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'is_litespeed' ) ) {
				self::$cached_is_litespeed = Server_Rules::is_litespeed();
			} else {
				// Fallback: raw SERVER_SOFTWARE check (defensive, no dependency).
				$raw                       = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
				$s                         = strtolower( $raw );
				self::$cached_is_litespeed = ( false !== strpos( $s, 'litespeed' ) || false !== strpos( $s, 'openlitespeed' ) );
			}

			/**
			 * Filter whether the current server is detected as LiteSpeed.
			 *
			 * @since NEXT
			 * @param bool $is_litespeed Whether LiteSpeed was detected.
			 */
			self::$cached_is_litespeed = (bool) apply_filters( 'wppo_litespeed_is_litespeed', self::$cached_is_litespeed );

			return self::$cached_is_litespeed;
		}

		/**
		 * Whether the LiteSpeed Cache plugin (LSCWP) is active.
		 *
		 * Checks active_plugins, active_sitewide_plugins, the LSCWP_V constant,
		 * and known LSCWP classes. Cheap — result cached per request.
		 *
		 * @since NEXT
		 * @return bool True if LiteSpeed Cache plugin is active.
		 */
		public static function is_lscache_active(): bool {
			if ( null !== self::$cached_is_lscache_active ) {
				return self::$cached_is_lscache_active;
			}

			// Constant defined by LSCWP (authoritative when present).
			if ( defined( 'LSCWP_V' ) ) {
				self::$cached_is_lscache_active = true;
				return self::$cached_is_lscache_active;
			}

			// Known LSCWP classes (autoloaded).
			if ( class_exists( 'LiteSpeed\Purge' ) || class_exists( 'LiteSpeed\Control' ) || class_exists( 'LiteSpeed\Core' ) ) {
				self::$cached_is_lscache_active = true;
				return self::$cached_is_lscache_active;
			}

			// Check active plugins (including network-activated on multisite).
			$active_plugins = (array) get_option( 'active_plugins', array() );
			if ( function_exists( 'is_multisite' ) ) {
				$is_multisite = false;
				try {
					$is_multisite = is_multisite();
				} catch ( \Throwable $e ) {
					$is_multisite = false;
				}
				if ( $is_multisite ) {
					try {
						$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
						$active_plugins  = array_merge( $active_plugins, $network_plugins );
					} catch ( \Throwable $e ) {
						unset( $e ); // Mock missing get_site_option — treat as no network plugins.
					}
				}
			}

			foreach ( $active_plugins as $plugin_path ) {
				if ( false !== strpos( $plugin_path, 'litespeed-cache' ) ) {
					self::$cached_is_lscache_active = true;
					return self::$cached_is_lscache_active;
				}
			}

			self::$cached_is_lscache_active = false;

			/**
			 * Filter whether LiteSpeed Cache plugin is considered active.
			 *
			 * @since NEXT
			 * @param bool $is_active Whether LSCWP is active.
			 */
			self::$cached_is_lscache_active = (bool) apply_filters( 'wppo_litespeed_is_lscache_active', self::$cached_is_lscache_active );

			return self::$cached_is_lscache_active;
		}

		/**
		 * Get the configured LiteSpeed integration mode.
		 *
		 * Reads from wppo_settings['litespeed_integration']['mode'] with a safe
		 * default of 'auto'. Value is allowlisted.
		 *
		 * @since NEXT
		 * @return string One of self::MODE_* constants.
		 */
		public static function get_mode(): string {
			if ( null !== self::$cached_mode ) {
				return self::$cached_mode;
			}

			$options = get_option( 'wppo_settings', array() );
			$raw     = $options['litespeed_integration']['mode'] ?? self::MODE_AUTO;
			$mode    = is_string( $raw ) ? sanitize_text_field( $raw ) : self::MODE_AUTO;

			if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
				$mode = self::MODE_AUTO;
			}

			/**
			 * Filter the configured LiteSpeed integration mode.
			 *
			 * @since NEXT
			 * @param string $mode The sanitized mode value.
			 */
			$mode = (string) apply_filters( 'wppo_litespeed_mode', $mode );

			if ( ! in_array( $mode, self::ALLOWED_MODES, true ) ) {
				$mode = self::MODE_AUTO;
			}

			self::$cached_mode = $mode;

			return self::$cached_mode;
		}

		/**
		 * Get the effective (resolved) LiteSpeed integration mode.
		 *
		 * Resolves MODE_AUTO to a concrete mode based on server detection
		 * and LSCache activation:
		 *
		 * - Non-LiteSpeed servers → standalone
		 * - MODE_STANDALONE → standalone (ignore LS server)
		 * - Auto + LSCache not active → wppo
		 * - Auto + LSCache active → litespeed
		 * - Explicit wppo/litespeed → as configured
		 *
		 * Result is cached per request.
		 *
		 * @since NEXT
		 * @return string One of self::MODE_* concrete modes (never 'auto').
		 */
		public static function effective_mode(): string {
			if ( null !== self::$cached_effective_mode ) {
				return self::$cached_effective_mode;
			}

			$mode = self::get_mode();

			// Non-LiteSpeed hosts always standalone (hidden UI, no behaviour change).
			if ( ! self::is_litespeed() ) {
				self::$cached_effective_mode = self::MODE_STANDALONE;
				return self::$cached_effective_mode;
			}

			// Explicit standalone on LiteSpeed → ignore LS.
			if ( self::MODE_STANDALONE === $mode ) {
				self::$cached_effective_mode = self::MODE_STANDALONE;
				return self::$cached_effective_mode;
			}

			// Explicit wppo / litespeed.
			if ( self::MODE_WPPO === $mode ) {
				self::$cached_effective_mode = self::MODE_WPPO;
				return self::$cached_effective_mode;
			}

			if ( self::MODE_LITESPEED === $mode ) {
				self::$cached_effective_mode = self::MODE_LITESPEED;
				return self::$cached_effective_mode;
			}

			// Auto → resolve.
			if ( self::is_lscache_active() ) {
				self::$cached_effective_mode = self::MODE_LITESPEED;
			} else {
				self::$cached_effective_mode = self::MODE_WPPO;
			}

			/**
			 * Filter the effective LiteSpeed integration mode.
			 *
			 * @since NEXT
			 * @param string $effective_mode The resolved effective mode.
			 * @param string $configured_mode The raw configured mode before resolution.
			 */
			$filtered = (string) apply_filters( 'wppo_litespeed_effective_mode', self::$cached_effective_mode, $mode );
			if ( in_array( $filtered, array( self::MODE_WPPO, self::MODE_LITESPEED, self::MODE_STANDALONE ), true ) ) {
				self::$cached_effective_mode = $filtered;
			}

			return self::$cached_effective_mode;
		}

		/**
		 * Whether WPPO owns the page cache (is the cache owner).
		 *
		 * True when effective_mode === wppo, or when on non-LiteSpeed hosts
		 * (standalone treated as WPPO-owned for file cache purposes).
		 *
		 * On LiteSpeed, standalone is NOT considered WPPO-owned for UI clarity
		 * (user explicitly said "ignore LS").
		 *
		 * @since NEXT
		 * @return bool True if WPPO is the cache owner.
		 */
		public static function is_wppo_cache_owner(): bool {
			$effective = self::effective_mode();

			// Standalone on LiteSpeed means "ignore LS" → WPPO owns cache.
			// Standalone on non-LiteSpeed → also WPPO owns cache.
			// But the spec says standalone = ignore LS → WPPO cache.
			// So both standalone and wppo are WPPO-owned.
			if ( self::MODE_STANDALONE === $effective ) {
				return true;
			}

			return self::MODE_WPPO === $effective;
		}

		/**
		 * Whether WPPO should disable its optimizer (minify/combine/defer/delay).
		 *
		 * True when LSCache is active and effective_mode !== wppo, i.e.
		 * LiteSpeed Cache owns optimization. Respects the litespeed_can_optm
		 * filter when it exists and returns false.
		 *
		 * @since NEXT
		 * @return bool True if WPPO optimizer should be disabled.
		 */
		public static function should_disable_wppo_optimizer(): bool {
			// No conflict when LSCache not active.
			if ( ! self::is_lscache_active() ) {
				return false;
			}

			$disable = self::MODE_WPPO !== self::effective_mode();

			// Respect LSCWP ecosystem filter: if it says don't optimize this route, we also skip.
			if ( ! $disable && has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				$disable = true;
			}

			/**
			 * Filter whether WPPO optimizer should be disabled on LiteSpeed.
			 *
			 * @since NEXT
			 * @param bool   $disable Whether optimizer should be disabled.
			 * @param string $effective_mode The effective integration mode.
			 */
			$disable = (bool) apply_filters( 'wppo_litespeed_should_disable_optimizer', $disable, self::effective_mode() );

			return $disable;
		}

		/**
		 * Whether purge sync is enabled (WPPO ↔ LSCache).
		 *
		 * Requires LiteSpeed server, LSCache active, and
		 * `litespeed_integration.purgeSync` true (default on). Filterable
		 * via `wppo_litespeed_purge_sync`.
		 *
		 * @since NEXT
		 * @return bool True if purges should be synced.
		 */
		public static function is_purge_sync_enabled(): bool {
			if ( ! self::is_litespeed() || ! self::is_lscache_active() ) {
				return false;
			}

			$options    = get_option( 'wppo_settings', array() );
			$purge_sync = $options['litespeed_integration']['purgeSync'] ?? true;
			$purge_sync = (bool) $purge_sync;

			/**
			 * Filter whether LiteSpeed purge sync is enabled.
			 *
			 * @since NEXT
			 * @param bool $purge_sync Whether purge sync is enabled.
			 */
			$purge_sync = (bool) apply_filters( 'wppo_litespeed_purge_sync', $purge_sync );

			return $purge_sync;
		}

		/**
		 * Get the blog-prefixed purge-lock transient key.
		 *
		 * @since NEXT
		 * @return string Transient key.
		 */
		public static function get_purge_lock_key(): string {
			return Util::transient_key( self::PURGE_LOCK );
		}

		/**
		 * Whether a purge-loop lock is currently active.
		 *
		 * @since NEXT
		 * @return bool True if lock present.
		 */
		public static function has_purge_lock(): bool {
			return (bool) get_transient( self::get_purge_lock_key() );
		}

		/**
		 * Set the purge-loop lock for PURGE_LOCK_TTL seconds.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function set_purge_lock(): void {
			set_transient( self::get_purge_lock_key(), 1, self::PURGE_LOCK_TTL );
		}

		/**
		 * Sync a WPPO "purge all" to LSCache.
		 *
		 * No-op when a lock is active or purgeSync disabled. Sets a 60s
		 * blog-prefixed lock via Util::transient_key() before emitting
		 * `litespeed_purge_all`.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function sync_purge_all_to_litespeed(): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			self::set_purge_lock();
			do_action( 'litespeed_purge_all', 'wppo clear_all' );
		}

		/**
		 * Sync a WPPO single-page purge to LSCache via URL.
		 *
		 * @since NEXT
		 * @param string $url_path URL path (e.g. "/about/").
		 * @return void
		 */
		public static function sync_purge_url_to_litespeed( string $url_path ): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			if ( '' === $url_path ) {
				return;
			}
			$url = home_url( $url_path );
			self::set_purge_lock();
			do_action( 'litespeed_purge_url', $url );
		}

		/**
		 * Sync a WPPO post invalidation to LSCache via post ID.
		 *
		 * @since NEXT
		 * @param int $post_id Post ID.
		 * @return void
		 */
		public static function sync_purge_post_to_litespeed( int $post_id ): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			if ( $post_id <= 0 ) {
				return;
			}
			self::set_purge_lock();
			do_action( 'litespeed_purge_post', $post_id );
		}

		/**
		 * Option key for DB queue fallback when headers_sent (LSCWP purge.cls.php:670).
		 *
		 * @since NEXT
		 * @var string
		 */
		public const DB_QUEUE = 'wppo_litespeed_purge_queue';

		/**
		 * Transient key for tag queue.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const TAG_QUEUE = 'wppo_lscache_tag_queue';

		/**
		 * Max tags in queue before dropping oldest.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const TAG_QUEUE_MAX = 100;

		/**
		 * Whether shutdown flush is hooked.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $queue_shutdown_hooked = false;

		/**
		 * Queue LiteSpeed purge tags (P3).
		 *
		 * Mirrors LSCWP Tag taxonomy (F,H,PGS,Po.{id},PT.{type},T.{id},A.{id},D.,B.{id},W.{id},ESI.,REST,HTTP.{code} + public/private/stale scope).
		 * Stored to blog-prefixed transient with 60s lock fan-out on multisite.
		 *
		 * @since NEXT
		 * @param string[] $tags  Tag strings (e.g. Po.123, T.5, F).
		 * @param string   $scope Scope: public|private|stale.
		 * @return void
		 */
		public static function queue_purge_tags( array $tags, string $scope = 'public' ): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			$tags = array_values( array_filter( array_map( fn( $t ) => preg_replace( '/[^A-Za-z0-9_\.\-]/', '', (string) $t ), $tags ) ) );
			if ( empty( $tags ) ) {
				return;
			}
			$scope = in_array( $scope, array( 'public', 'private', 'stale' ), true ) ? $scope : 'public';

			// Blog_id fan-out on multisite: prefix tags with B.{blog_id} when multisite.
			$use_fanout = false;
			if ( function_exists( 'is_multisite' ) ) {
				try {
					$use_fanout = is_multisite();
				} catch ( \Throwable $e ) {
					$use_fanout = false;
				}
			}
			if ( $use_fanout ) {
				$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				if ( $blog_id > 0 ) {
					$prefix = 'B.' . $blog_id;
					if ( ! in_array( $prefix, $tags, true ) ) {
						$tags[] = $prefix;
					}
				}
			}

			/**
			 * Filter tags before queue.
			 *
			 * @since NEXT
			 * @param string[] $tags Tag list.
			 * @param string   $scope Scope.
			 */
			$tags = (array) apply_filters( 'wppo_litespeed_purge_tags', $tags, $scope );

			$key      = Util::transient_key( self::TAG_QUEUE );
			$existing = get_transient( $key );
			if ( ! is_array( $existing ) ) {
				$existing = array();
			}
			// Merge, dedupe, cap.
			$merged = array_values( array_unique( array_merge( $existing, $tags ) ) );
			if ( count( $merged ) > self::TAG_QUEUE_MAX ) {
				$merged = array_slice( $merged, -self::TAG_QUEUE_MAX );
			}
			// Include scope as pseudo-tags for OLS raw header fallback.
			if ( 'private' === $scope && ! in_array( 'private', $merged, true ) ) {
				$merged[] = 'private';
			}
			if ( 'stale' === $scope && ! in_array( 'stale', $merged, true ) ) {
				$merged[] = 'stale';
			}
			set_transient( $key, $merged, MINUTE_IN_SECONDS * 5 );
			self::maybe_hook_shutdown();
		}

		/**
		 * Ensure shutdown flush is hooked once.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function maybe_hook_shutdown(): void {
			if ( self::$queue_shutdown_hooked ) {
				return;
			}
			self::$queue_shutdown_hooked = true;
			add_action( 'shutdown', array( self::class, 'flush_tag_queue' ), 20 );
		}

		/**
		 * Flush queued tags via X-LiteSpeed-Purge and litespeed_purge action.
		 *
		 * Checks both transient queue and DB_QUEUE fallback (headers_sent / cron).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function flush_tag_queue(): void {
			$key     = Util::transient_key( self::TAG_QUEUE );
			$tags    = get_transient( $key );
			$db_key  = Util::transient_key( self::DB_QUEUE );
			$db_tags = get_option( $db_key, array() );
			if ( ! is_array( $db_tags ) ) {
				$db_tags = array();
			}
			if ( is_array( $tags ) && ! empty( $tags ) ) {
				delete_transient( $key );
			} else {
				$tags = array();
			}
			if ( ! empty( $db_tags ) ) {
				delete_option( $db_key );
				$tags = array_values( array_unique( array_merge( $tags, $db_tags ) ) );
			}
			if ( empty( $tags ) ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			self::set_purge_lock();
			$tag_str = implode( ',', $tags );
			/**
			 * Filter flushed tag string.
			 *
			 * @since NEXT
			 * @param string   $tag_str Tag string.
			 * @param string[] $tags    Tag array.
			 */
			$tag_str = (string) apply_filters( 'wppo_litespeed_purge_tag_string', $tag_str, $tags );
			if ( has_action( 'litespeed_purge' ) ) {
				do_action( 'litespeed_purge', $tags );
			}
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Purge: tag=' . $tag_str, false );
			} else {
				// Fallback: re-queue to DB for next request if headers already sent and lock not set.
				// Already cleared above; if we couldn't send header, persist stale tag with next flush.
				$remaining_key = Util::transient_key( self::DB_QUEUE );
				update_option( $remaining_key, $tags, false );
			}
		}

		/**
		 * Get DB queue option key (blog-prefixed).
		 *
		 * @since NEXT
		 * @return string
		 */
		public static function get_db_queue_key(): string {
			return Util::transient_key( self::DB_QUEUE );
		}

		/**
		 * Initialise LiteSpeed integration hooks.
		 *
		 * Registers purge-sync listeners (LS → WPPO) and Phase 3 server-level
		 * acceleration hooks (send_headers header emission + vary bridge).
		 * Idempotent — safe to call multiple times per request.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function init(): void {
			if ( self::$hooks_registered ) {
				return;
			}
			self::$hooks_registered = true;

			add_action( 'litespeed_purged_all', array( self::class, 'handle_litespeed_purged_all' ) );
			add_action( 'litespeed_purged_post', array( self::class, 'handle_litespeed_purged_post' ), 10, 1 );
			add_action( 'litespeed_purge_finalize', array( self::class, 'handle_litespeed_purge_finalize' ) );

			// Phase 3 — LS-native header emission (LS-301) + vary bridge (LS-303).
			add_action( 'send_headers', array( self::class, 'handle_send_headers' ), 0 );
			add_filter( 'litespeed_vary', array( self::class, 'filter_litespeed_vary' ), 10, 1 );

			// LS-320 — _lscache_vary cookie seeding for LSWS vary handshake.
			add_action( 'init', array( self::class, 'seed_lscache_vary_cookie' ), 1 );
			add_action( 'wp_logout', array( self::class, 'clear_lscache_vary_cookie' ) );
		}

		/**
		 * Handle LSCache "purged all" → clear WPPO file cache.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function handle_litespeed_purged_all(): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			self::set_purge_lock();
			if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				Cache::clear_cache();
			}
		}

		/**
		 * Handle LSCache "purged post" → invalidate WPPO static HTML for post.
		 *
		 * @since NEXT
		 * @param int $post_id Post ID purged by LSCache.
		 * @return void
		 */
		public static function handle_litespeed_purged_post( $post_id ): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			$post_id = (int) $post_id;
			if ( $post_id <= 0 ) {
				return;
			}
			self::set_purge_lock();
			if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				$cache = new Cache();
				$cache->invalidate_dynamic_static_html( $post_id );
			}
		}

		/**
		 * Handle LSCache purge finalize (catch-all) → clear WPPO cache.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function handle_litespeed_purge_finalize(): void {
			if ( ! self::is_purge_sync_enabled() ) {
				return;
			}
			if ( self::has_purge_lock() ) {
				return;
			}
			self::set_purge_lock();
			if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
				Cache::clear_cache();
			}
		}

		/**
		 * Get LiteSpeed cache TTL in seconds mapped from cacheLife hours with per-context overrides.
		 *
		 * Maps `cache_settings.cacheLife` (hours: 0/1/6/12/24/48/168) to LS
		 * `max-age` seconds. File-cache `0` = never expire; LS server layer
		 * cannot store infinite, so `0` maps to 1 week (604800, WEEK_IN_SECONDS)
		 * as an explicit policy change, documented here and in Cache.
		 *
		 * Per-context overrides (LSCWP control.cls.php:514 parity):
		 * - feed / REST / 404 \u2192 0 (no-cache)
		 * - private (commenter/postpass or logged-in) \u2192 1800 (30 min)
		 * - front (is_front_page / is_home) \u2192 604800 (1 week)
		 *
		 * Result is cached per request; filterable via `wppo_litespeed_ttl`.
		 *
		 * @since NEXT
		 * @param string|null $uri     Optional URI for per-page TTL.
		 * @param int|null    $post_id Optional post ID.
		 * @return int TTL seconds (>=0, 0 = no-cache).
		 */
		public static function get_litespeed_ttl( ?string $uri = null, $post_id = null ): int {
			$is_explicit = null !== $uri || null !== $post_id;
			if ( ! $is_explicit && null !== self::$cached_ttl ) {
				return self::$cached_ttl;
			}

			// Resolve URI.
			$resolved_uri = $uri;
			if ( null === $resolved_uri ) {
				$resolved_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotValidated
			}
			if ( '' === $resolved_uri ) {
				$resolved_uri = '/';
			}

			// Resolve post ID when not explicitly provided.
			$resolved_post_id = $post_id;
			if ( null === $resolved_post_id ) {
				$pid = 0;
				if ( function_exists( 'url_to_postid' ) && function_exists( 'home_url' ) ) {
					try {
						$pid = (int) url_to_postid( home_url( $resolved_uri ) );
					} catch ( \Throwable $e ) {
						$pid = 0;
					}
				}
				if ( $pid > 0 ) {
					$resolved_post_id = $pid;
				} elseif ( isset( $GLOBALS['post'] ) && is_object( $GLOBALS['post'] ) && isset( $GLOBALS['post']->ID ) ) {
					$maybe            = (int) $GLOBALS['post']->ID;
					$resolved_post_id = $maybe > 0 ? $maybe : null;
				} else {
					$resolved_post_id = null;
				}
			} elseif ( 0 === $resolved_post_id ) {
				$resolved_post_id = null;
			} else {
				$resolved_post_id = (int) $resolved_post_id;
				if ( $resolved_post_id <= 0 ) {
					$resolved_post_id = null;
				}
			}

			$post_type = null;
			if ( null !== $resolved_post_id && function_exists( 'get_post_type' ) ) {
				try {
					$pt = get_post_type( $resolved_post_id );
					if ( $pt ) {
						$post_type = (string) $pt;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$context = array(
				'uri'       => $resolved_uri,
				'post_id'   => $resolved_post_id,
				'post_type' => $post_type,
			);

			// Per-context overrides: feed / 404 / REST => 0.
			$context_ttl = null;
			try {
				if ( is_feed() ) {
					$context_ttl = 0;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( null === $context_ttl ) {
				try {
					if ( is_404() ) {
						$context_ttl = 0;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( null === $context_ttl && defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				$context_ttl = 0;
			}
			if ( null === $context_ttl && isset( $_SERVER['REQUEST_URI'] ) ) {
				$rq = is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
				if ( false !== strpos( $rq, '/wp-json/' ) || false !== strpos( $rq, 'rest_route' ) ) {
					$context_ttl = 0;
				}
			}
			// Private: commenter/postpass or logged-in => 1800.
			if ( null === $context_ttl ) {
				try {
					if ( self::is_private_request() ) {
						$context_ttl = 1800;
					} else {
						try {
							if ( is_user_logged_in() ) {
								$context_ttl = 1800;
							}
						} catch ( \Throwable $e2 ) {
							unset( $e2 );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// Front: is_front_page / is_home => 604800.
			if ( null === $context_ttl ) {
				$maybe_front = false;
				try {
					if ( is_front_page() ) {
						$maybe_front = true;
					} elseif ( is_home() ) {
						$maybe_front = true;
					}
				} catch ( \Throwable $e ) {
					$maybe_front = false;
				}
				if ( $maybe_front ) {
					$context_ttl = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
				}
			}

			$options = get_option( 'wppo_settings', array() );
			$hours   = isset( $options['cache_settings']['cacheLife'] ) ? absint( $options['cache_settings']['cacheLife'] ) : 0;

			if ( 0 === $hours ) {
				$base_seconds = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
			} else {
				$base_seconds = $hours * ( defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
			}

			// If per-context override exists, use it as base before filters; otherwise use cacheLife mapping.
			$seconds      = null !== $context_ttl ? (int) $context_ttl : (int) $base_seconds;
			$filter_hours = null !== $context_ttl ? -1 : (int) $hours;

			/**
			 * Filter per-page TTL (Tier-1, filter-only).
			 *
			 * @since NEXT
			 * @param int         $seconds TTL seconds.
			 * @param string      $uri     Request URI.
			 * @param int|null    $post_id Resolved post ID or null.
			 */
			$seconds = (int) apply_filters( 'wppo_cache_ttl', $seconds, $resolved_uri, $resolved_post_id );

			/**
			 * Filter LiteSpeed TTL seconds (per-context).
			 *
			 * @since NEXT
			 * @param int   $seconds TTL in seconds (0 = no-cache).
			 * @param int   $hours   Original cacheLife hours (0 = never expire) or -1 for context override.
			 * @param array $context Context array with uri, post_id, post_type.
			 */
			$filtered = (int) apply_filters( 'wppo_litespeed_ttl', (int) $seconds, $filter_hours, $context );
			// For context overrides, allow 0; only fallback for negative. For base mapping, fallback for <=0.
			if ( null !== $context_ttl ) {
				if ( $filtered < 0 ) {
					$filtered = (int) $seconds;
				}
			} elseif ( $filtered <= 0 ) {
				$filtered = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
			}
			$seconds = $filtered;

			if ( ! $is_explicit ) {
				self::$cached_ttl = $seconds;
			}

			return $seconds;
		}

		/**
		 * Whether current request is cacheable for LiteSpeed layer.
		 *
		 * Cheap per-request cached check. Delegates to Cache when available
		 * to mirror is_not_cacheable() without duplicating logic, falling back
		 * to DONOTCACHEPAGE when Cache is unavailable. Filterable via
		 * `wppo_litespeed_is_cacheable`.
		 *
		 * @since NEXT
		 * @return bool True if cacheable.
		 */
		public static function is_request_cacheable(): bool {
			if ( null !== self::$cached_is_cacheable ) {
				return self::$cached_is_cacheable;
			}

			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				self::$cached_is_cacheable = false;
				/**
				 * Filter whether current request is cacheable for LiteSpeed.
				 *
				 * @since NEXT
				 * @param bool $is_cacheable Whether request is cacheable.
				 */
				self::$cached_is_cacheable = (bool) apply_filters( 'wppo_litespeed_is_cacheable', self::$cached_is_cacheable );
				return self::$cached_is_cacheable;
			}

			$cacheable = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					$cache = new Cache();
					if ( method_exists( $cache, 'is_request_cacheable' ) ) {
						$cacheable = $cache->is_request_cacheable();
					} else {
						$ref = new \ReflectionMethod( Cache::class, 'is_not_cacheable' );
						$ref->setAccessible( true );
						$cacheable = ! $ref->invoke( $cache );
					}
				}
			} catch ( \Throwable $e ) {
				$cacheable = false;
			}

			// LS-304: align with maybe_store_cache — query strings s|ver|v are not cacheable.
			if ( $cacheable && ! empty( $_SERVER['QUERY_STRING'] ) ) {
				$qs = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
				if ( preg_match( '/(?:^|&)(s|ver|v)(?:=|&|$)/', $qs ) ) {
					$cacheable = false;
				}
			}

			// LS-304: honor preload_settings.excludePreloadCache when enabled.
			if ( $cacheable ) {
				$options = get_option( 'wppo_settings', array() );
				if ( ! empty( $options['preload_settings']['enablePreloadCache'] ) && ! empty( $options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = Util::process_urls( $options['preload_settings']['excludePreloadCache'] );
					$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
					$home_path    = wp_parse_url( Util::cached_home_url(), PHP_URL_PATH ) ?? '';
					if ( $home_path && '/' !== $home_path && 0 === strpos( $request_uri, $home_path ) ) {
						$request_uri = substr( $request_uri, strlen( $home_path ) );
					}
					$current_url = Util::cached_home_url( $request_uri );
					if ( Util::is_url_excluded( $current_url, $exclude_urls ) ) {
						$cacheable = false;
					}
				}
			}

			self::$cached_is_cacheable = (bool) $cacheable;

			/**
			 * Filter whether current request is cacheable for LiteSpeed.
			 *
			 * @since NEXT
			 * @param bool $is_cacheable Whether request is cacheable.
			 */
			self::$cached_is_cacheable = (bool) apply_filters( 'wppo_litespeed_is_cacheable', self::$cached_is_cacheable );

			return self::$cached_is_cacheable;
		}

		/**
		 * Whether vary-by-role should be added for LiteSpeed.
		 *
		 * True when is_litespeed && is_wppo_cache_owner && enableLoggedInCache.
		 * Cheap per-request cached; filterable via `wppo_litespeed_vary_enabled`.
		 *
		 * @since NEXT
		 * @return bool True if vary bridge should be active.
		 */
		public static function should_vary_by_role(): bool {
			if ( null !== self::$cached_should_vary ) {
				return self::$cached_should_vary;
			}

			if ( ! self::is_litespeed() || ! self::is_wppo_cache_owner() ) {
				self::$cached_should_vary = false;
				return self::$cached_should_vary;
			}

			$options = get_option( 'wppo_settings', array() );
			$enable  = ! empty( $options['cache_settings']['enableLoggedInCache'] );

			/**
			 * Filter whether LiteSpeed vary-by-role is enabled.
			 *
			 * @since NEXT
			 * @param bool $enable Whether vary bridge is enabled.
			 */
			$enable = (bool) apply_filters( 'wppo_litespeed_vary_enabled', $enable );

			self::$cached_should_vary = $enable;

			return self::$cached_should_vary;
		}

		/**
		 * Get active Vary groups (P2).
		 *
		 * Guest/mobile/webp are opt-in via litespeed_integration.varyGroups.
		 * Role vary is derived from enableLoggedInCache. All filtered via
		 * wppo_litespeed_vary_groups.
		 *
		 * @since NEXT
		 * @return array{role:bool,guest:bool,mobile:bool,webp:bool}
		 */
		public static function get_vary_groups(): array {
			$options = get_option( 'wppo_settings', array() );
			$groups  = $options['litespeed_integration']['varyGroups'] ?? array();
			$active  = array(
				'role'      => self::should_vary_by_role(),
				'guest'     => ! empty( $groups['guest'] ),
				'mobile'    => ! empty( $groups['mobile'] ),
				'webp'      => ! empty( $groups['webp'] ),
				'commenter' => ! empty( $groups['commenter'] ),
				'postpass'  => ! empty( $groups['postpass'] ),
			);
			/**
			 * Filter active vary groups.
			 *
			 * @since NEXT
			 * @param array $active Vary groups.
			 */
			$active = (array) apply_filters( 'wppo_litespeed_vary_groups', $active );
			return array(
				'role'      => (bool) ( $active['role'] ?? false ),
				'guest'     => (bool) ( $active['guest'] ?? false ),
				'mobile'    => (bool) ( $active['mobile'] ?? false ),
				'webp'      => (bool) ( $active['webp'] ?? false ),
				'commenter' => (bool) ( $active['commenter'] ?? false ),
				'postpass'  => (bool) ( $active['postpass'] ?? false ),
			);
		}

		/**
		 * Whether current request has a commenter cookie (LS-320).
		 *
		 * Mirrors LSCWP vary.cls.php:261 check_commenter.
		 *
		 * @since NEXT
		 * @return bool True if commenter cookie present.
		 */
		public static function is_commenter_request(): bool {
			if ( empty( $_COOKIE ) ) {
				return false;
			}
			foreach ( $_COOKIE as $key => $value ) {
				if ( is_string( $key ) && 0 === strpos( $key, 'comment_author_' ) ) {
					return '' !== $value;
				}
			}
			return false;
		}

		/**
		 * Whether current request has a post password cookie (LS-320).
		 *
		 * Mirrors LSCWP vary.cls.php:707 post_password handling.
		 *
		 * @since NEXT
		 * @return bool True if postpass cookie present.
		 */
		public static function is_postpass_request(): bool {
			if ( empty( $_COOKIE ) ) {
				return false;
			}
			// Use COOKIEHASH when available; fallback to wp_hash for accuracy.
			if ( defined( 'COOKIEHASH' ) ) {
				$hash = COOKIEHASH;
			} elseif ( function_exists( 'wp_hash' ) ) {
				$hash = wp_hash( 'postpass' );
			} else {
				$hash = md5( wp_parse_url( home_url(), PHP_URL_HOST ) ?? '' );
			}
			foreach ( $_COOKIE as $key => $value ) {
				if ( is_string( $key ) && 0 === strpos( $key, 'wp-postpass_' . $hash ) ) {
					return '' !== $value;
				}
			}
			return false;
		}

		/**
		 * Whether current request should be treated as private/nocache (LS-320).
		 *
		 * True when commenter/postpass cookies present AND corresponding vary
		 * group enabled. Mirrors LSCWP vary.cls.php:261,707.
		 *
		 * @since NEXT
		 * @return bool True if request should be private/nocache.
		 */
		public static function is_private_request(): bool {
			$groups = self::get_vary_groups();
			if ( $groups['commenter'] && self::is_commenter_request() ) {
				return true;
			}
			if ( $groups['postpass'] && self::is_postpass_request() ) {
				return true;
			}
			return false;
		}

		/**
		 * Seed _lscache_vary cookie for LSWS vary handshake (LS-320).
		 *
		 * Called on init priority 1 when is_litespeed && is_wppo_cache_owner.
		 * Builds deterministic hash from active vary groups.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function seed_lscache_vary_cookie(): void {
			if ( ! self::is_litespeed() || ! self::is_wppo_cache_owner() ) {
				return;
			}
			$groups = self::get_vary_groups();
			if ( ! $groups['role'] && ! $groups['guest'] && ! $groups['mobile'] && ! $groups['webp'] ) {
				// No active vary groups — clear stale cookie if present.
				if ( isset( $_COOKIE['_lscache_vary'] ) ) {
					setcookie( '_lscache_vary', '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
				}
				return;
			}
			$salt    = defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '';
			$payload = array(
				'role'      => $groups['role'] ? ( is_user_logged_in() ? self::get_vary_role_value() : 'guest' ) : '',
				'guest'     => $groups['guest'] ? '1' : '',
				'mobile'    => $groups['mobile'] && function_exists( 'wp_is_mobile' ) && wp_is_mobile() ? '1' : '',
				'webp'      => $groups['webp'] && self::client_supports_webp() ? '1' : '',
				'commenter' => $groups['commenter'] && self::is_commenter_request() ? '1' : '',
				'postpass'  => $groups['postpass'] && self::is_postpass_request() ? '1' : '',
			);
			$value   = substr( md5( $salt . wp_json_encode( $payload ) ), 0, 12 );
			/**
			 * Filter the _lscache_vary cookie value.
			 *
			 * @since NEXT
			 * @param string $value 12-char hash.
			 * @param array  $payload Active vary payload.
			 */
			$value   = (string) apply_filters( 'wppo_litespeed_lscache_vary_value', $value, $payload );
			$current = isset( $_COOKIE['_lscache_vary'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['_lscache_vary'] ) ) : '';
			if ( $current !== $value ) {
				setcookie( '_lscache_vary', $value, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
		}

		/**
		 * Get the vary role value — group 99 for admin (LSCWP parity).
		 *
		 * @since NEXT
		 * @return string Role value (99 for admin, role hash otherwise).
		 */
		private static function get_vary_role_value(): string {
			if ( is_multisite() && is_super_admin() ) {
				return '99';
			}
			// Use capability check instead of is_admin() for accuracy (LSCWP parity).
			if ( function_exists( 'current_user_can' ) && current_user_can( 'manage_options' ) ) {
				return '99';
			}
			$user = wp_get_current_user();
			return Util::get_role_hash( $user );
		}

		/**
		 * Whether the current client supports WebP images.
		 *
		 * @since NEXT
		 * @return bool True if Accept header contains image/webp or image/avif.
		 */
		private static function client_supports_webp(): bool {
			$accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';
			return false !== strpos( $accept, 'image/webp' ) || false !== strpos( $accept, 'image/avif' );
		}

		/**
		 * Clear _lscache_vary cookie on logout.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function clear_lscache_vary_cookie(): void {
			if ( isset( $_COOKIE['_lscache_vary'] ) ) {
				setcookie( '_lscache_vary', '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
		}

		/**
		 * Build fallback vary header value from active groups (P2).
		 *
		 * @since NEXT
		 * @return string Vary header value.
		 */
		public static function build_vary_header(): string {
			$groups = self::get_vary_groups();
			$parts  = array();
			if ( $groups['role'] ) {
				$parts[] = 'wppo_role_hash';
			}
			if ( $groups['guest'] ) {
				$parts[] = '_lscache_vary=guest';
			}
			if ( $groups['mobile'] ) {
				$parts[] = '_lscache_vary=mobile';
			}
			if ( $groups['webp'] ) {
				$parts[] = '_lscache_vary=webp';
			}
			if ( empty( $parts ) ) {
				return '';
			}
			$cookie_vary = implode( ',', $parts );
			/**
			 * Filter built vary header.
			 *
			 * @since NEXT
			 * @param string $cookie_vary Vary header.
			 * @param array  $groups Active groups.
			 */
			return (string) apply_filters( 'wppo_litespeed_vary_header', 'cookie=' . $cookie_vary, $groups );
		}

		/**
		 * Handle send_headers — LS-native header emission (LS-301/302/304).
		 *
		 * When is_litespeed && is_wppo_cache_owner && is_cacheable, emits
		 * `X-LiteSpeed-Cache-Control: public,max-age=N` (mapped from cacheLife,
		 * 0→604800 documented) + `X-LiteSpeed-Tag: WPPO` + per-post tag via
		 * is_singular(). Uses do_action('litespeed_control_set_ttl') when hook
		 * exists else raw header. For non-cacheable routes emits no-cache via
		 * litespeed_control_set_nocache. When LSCache owns cache, emits no-cache
		 * for non-cacheable routes only (bypass path, LS-302). Strips generic
		 * Cache-Control when LS public header sent to avoid conflict (LS-304).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function handle_send_headers(): void {
			if ( ! self::is_litespeed() ) {
				return;
			}

			$headers_sent = function_exists( 'headers_sent' ) ? headers_sent() : false;
			$is_owner     = self::is_wppo_cache_owner();
			$is_cacheable = self::is_request_cacheable();

			// LS-302 bypass path: when LSCache owns, only signal no-cache for
			// non-cacheable routes LS would otherwise cache. Cacheable routes
			// are handled by LSCache's own control and need no WPPO header.
			if ( ! $is_owner ) {
				if ( ! $is_cacheable ) {
					if ( $headers_sent ) {
						// DB_QUEUE fallback when headers already sent (cron/early flush, LSCWP purge.cls.php:670).
						self::queue_purge_tags( array( 'WPPO', 'private' ), 'private' );
					}
					self::send_litespeed_nocache( 'wppo not cacheable' );
				}
				return;
			}

			// WPPO owner path.
			if ( ! $is_cacheable ) {
				if ( $headers_sent ) {
					self::queue_purge_tags( array( 'WPPO', 'private' ), 'private' );
				}
				self::send_litespeed_nocache( 'wppo not cacheable' );
				return;
			}

			// LS-320: Commenter/postpass → private, no-cache (LSCWP vary.cls.php:261,707).
			if ( self::is_private_request() ) {
				if ( $headers_sent ) {
					self::queue_purge_tags( array( 'WPPO', 'private' ), 'private' );
				}
				self::send_litespeed_nocache( 'commenter or postpass cookie present' );
				return;
			}

			// Cacheable + WPPO owner — LS-301. Per-type TTL already handles 0 => no-cache.
			$ttl = self::get_litespeed_ttl();
			if ( $ttl <= 0 ) {
				if ( $headers_sent ) {
					self::queue_purge_tags( array( 'WPPO', 'stale' ), 'stale' );
				}
				self::send_litespeed_nocache( 'ttl 0 no-cache' );
				return;
			}
			if ( $headers_sent ) {
				// Fallback: queue tags as stale so next request purges correctly when headers_sent.
				$pending_tags = self::get_litespeed_tags_for_purge();
				self::queue_purge_tags( $pending_tags, 'stale' );
			}
			self::send_litespeed_ttl( $ttl );
			self::send_litespeed_tags();

			// LS-303 + P2 fallback: when LSCWP not active, raw Vary: Cookie + X-LiteSpeed-Vary
			// for role/guest/mobile/webp groups. When LSCWP active, litespeed_vary filter handles it.
			$groups_for_header = self::get_vary_groups();
			$needs_vary        = $groups_for_header['role'] || $groups_for_header['guest'] || $groups_for_header['mobile'] || $groups_for_header['webp'];
			if ( $needs_vary ) {
				$has_external = false;
				if ( function_exists( 'has_filter' ) ) {
					global $wp_filter;
					if ( isset( $wp_filter['litespeed_vary'] ) && is_object( $wp_filter['litespeed_vary'] ) ) {
						$callbacks = $wp_filter['litespeed_vary']->callbacks ?? array();
						$only_self = true;
						foreach ( $callbacks as $prio => $cbs ) {
							foreach ( $cbs as $cb ) {
								$fn = $cb['function'] ?? null;
								if ( is_array( $fn ) && isset( $fn[0], $fn[1] ) && 'PerformanceOptimise\\Inc\\LiteSpeed_Integration' === $fn[0] && 'filter_litespeed_vary' === $fn[1] ) {
									continue;
								}
								$only_self    = false;
								$has_external = true;
							}
						}
						if ( $only_self ) {
							$has_external = false;
						}
					} else {
						$has_external = false;
					}
				}
				if ( ! $has_external && ! $headers_sent ) {
					$has_cookie_vary = $groups_for_header['role'] || $groups_for_header['guest'] || $groups_for_header['mobile'];
					if ( $has_cookie_vary ) {
						header( 'Vary: Cookie', false );
					}
					if ( $groups_for_header['webp'] ) {
						header( 'Vary: Accept', false );
					}
					$vary_header = self::build_vary_header();
					if ( '' !== $vary_header ) {
						header( 'X-LiteSpeed-Vary: ' . $vary_header, false );
					}
					/**
					 * Filter the fallback vary header value when litespeed_vary not present.
					 *
					 * @since NEXT
					 * @param string $vary Fallback vary header.
					 */
					$fallback = (string) apply_filters( 'wppo_litespeed_vary_fallback', $vary_header );
					if ( '' !== $fallback && $vary_header !== $fallback ) {
						header( 'X-LiteSpeed-Vary: ' . $fallback, false );
					}
				}
			}

			// LS-304: strip generic Cache-Control that would conflict with LS public header.
			if ( ! $headers_sent ) {
				self::maybe_strip_generic_cache_control();
			}
		}

		/**
		 * Send LiteSpeed TTL header (public,max-age=N).
		 *
		 * Uses do_action('litespeed_control_set_ttl') when hook exists (bitmask
		 * correct when LSCWP active) else raw header('X-LiteSpeed-Cache-Control').
		 * Filterable via wppo_litespeed_ttl and wppo_litespeed_cache_control_header.
		 *
		 * @since NEXT
		 * @param int $ttl TTL seconds.
		 * @return void
		 */
		private static function send_litespeed_ttl( int $ttl ): void {
			$ttl = (int) apply_filters( 'wppo_litespeed_ttl', $ttl );
			if ( $ttl <= 0 ) {
				$ttl = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
			}

			$header = 'X-LiteSpeed-Cache-Control: public,max-age=' . $ttl;

			/**
			 * Filter the LiteSpeed cache-control header for cacheable routes.
			 *
			 * @since NEXT
			 * @param string $header Header string.
			 * @param int    $ttl    TTL seconds.
			 */
			$header = (string) apply_filters( 'wppo_litespeed_cache_control_header', $header, $ttl );

			if ( has_action( 'litespeed_control_set_ttl' ) ) {
				do_action( 'litespeed_control_set_ttl', $ttl );
			}

			// Always emit raw header as fallback for OLS without LSCWP (OLS honors raw header).
			if ( ! headers_sent() ) {
				header( $header );
			}
		}

		/**
		 * Send LiteSpeed no-cache header for non-cacheable routes.
		 *
		 * Uses do_action('litespeed_control_set_nocache') when hook exists else
		 * raw header('X-LiteSpeed-Cache-Control: no-cache').
		 *
		 * @since NEXT
		 * @param string $reason Reason for nocache (for LSCWP logging).
		 * @return void
		 */
		private static function send_litespeed_nocache( string $reason ): void {
			/**
			 * Filter reason for LiteSpeed no-cache.
			 *
			 * @since NEXT
			 * @param string $reason Reason string.
			 */
			$reason = (string) apply_filters( 'wppo_litespeed_nocache_reason', $reason );

			if ( has_action( 'litespeed_control_set_nocache' ) ) {
				do_action( 'litespeed_control_set_nocache', $reason );
			}

			if ( ! headers_sent() ) {
				$header = 'X-LiteSpeed-Cache-Control: no-cache';
				/**
				 * Filter the LiteSpeed no-cache header.
				 *
				 * @since NEXT
				 * @param string $header Header string.
				 * @param string $reason Reason.
				 */
				$header = (string) apply_filters( 'wppo_litespeed_nocache_header', $header, $reason );
				header( $header );
			}
		}

		/**
		 * Build full LiteSpeed tag fan-out for current request.
		 *
		 * Mirrors LSCWP Tag taxonomy: WPPO, F (front), H (home/blog), PGS (paged),
		 * Po.{id}, PT.{postType}, T.{termId}, A.{authorId}, D.{Ymd}, B.{blogId},
		 * FD (feed), REST, HTTP.404, MIN (combined css), W. (widget) + stale/private scope.
		 * Filterable via wppo_litespeed_tag (single) and wppo_litespeed_purge_tags (array).
		 *
		 * @since NEXT
		 * @return string[]
		 */
		public static function get_litespeed_tags_for_purge(): array {
			$tags = array( 'WPPO' );

			// F: front page.
			if ( function_exists( 'is_front_page' ) ) {
				try {
					if ( is_front_page() ) {
						$tags[] = 'F';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// H: home/blog archive.
			if ( function_exists( 'is_home' ) ) {
				try {
					if ( is_home() ) {
						$tags[] = 'H';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// PGS: paged archives.
			if ( function_exists( 'is_paged' ) ) {
				try {
					if ( is_paged() ) {
						$tags[] = 'PGS';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			} elseif ( ! in_array( 'PGS', $tags, true ) ) { // Default include PGS for purge fan-out (covers paginated archives).
				$tags[] = 'PGS';
			}
			// Ensure F/H/PGS are always in fan-out for singular invalidation (LSCWP src/tag.cls.php:16).
			if ( function_exists( 'is_singular' ) ) {
				try {
					if ( is_singular() ) {
						if ( ! in_array( 'F', $tags, true ) ) {
							$tags[] = 'F';
						}
						if ( ! in_array( 'H', $tags, true ) ) {
							$tags[] = 'H';
						}
						if ( ! in_array( 'PGS', $tags, true ) ) {
							$tags[] = 'PGS';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Singular: Po, PT, T, A, D.
			if ( function_exists( 'is_singular' ) ) {
				$is_singular = false;
				try {
					$is_singular = is_singular();
				} catch ( \Throwable $e ) {
					$is_singular = false;
				}
				if ( $is_singular ) {
					$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
					if ( $post_id > 0 ) {
						$post_id = (int) apply_filters( 'wppo_litespeed_tag_post_id', $post_id );
						if ( $post_id > 0 ) {
							$tags[] = 'Po.' . $post_id;
							// PT.
							$pt = function_exists( 'get_post_type' ) ? get_post_type( $post_id ) : null;
							if ( $pt ) {
								$tags[] = 'PT.' . sanitize_text_field( (string) $pt );
							}
							// T.* taxonomy terms.
							if ( function_exists( 'get_object_taxonomies' ) && function_exists( 'wp_get_object_terms' ) && $pt ) {
								try {
									$taxes = get_object_taxonomies( $pt, 'names' );
									if ( ! empty( $taxes ) ) {
										$terms = wp_get_object_terms( $post_id, $taxes );
										if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
											foreach ( $terms as $term ) {
												if ( isset( $term->term_id ) ) {
													$tags[] = 'T.' . (int) $term->term_id;
												}
											}
										}
									}
								} catch ( \Throwable $e ) {
									unset( $e );
								}
							}
							// A.
							if ( function_exists( 'get_post_field' ) ) {
								try {
									$author = get_post_field( 'post_author', $post_id );
									if ( $author ) {
										$tags[] = 'A.' . (int) $author;
									}
								} catch ( \Throwable $e ) {
									unset( $e );
								}
							}
							// D. date Ymd.
							if ( function_exists( 'get_the_date' ) ) {
								try {
									$date = get_the_date( 'Ymd', $post_id );
									if ( $date ) {
										$tags[] = 'D.' . sanitize_text_field( (string) $date );
									}
								} catch ( \Throwable $e ) {
									unset( $e );
								}
							}
						}
					}
				}
			}

			// B. blog_id fan-out (multisite).
			if ( function_exists( 'is_multisite' ) ) {
				try {
					if ( is_multisite() && function_exists( 'get_current_blog_id' ) ) {
						$bid = (int) get_current_blog_id();
						if ( $bid > 0 ) {
							$tags[] = 'B.' . $bid;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// FD / feed, REST, HTTP.404.
			if ( function_exists( 'is_feed' ) ) {
				try {
					if ( is_feed() ) {
						$tags[] = 'FD';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
				$tags[] = 'REST';
			} elseif ( isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), '/wp-json/' ) ) { // phpcs:ignore
				$tags[] = 'REST';
			}
			if ( function_exists( 'is_404' ) ) {
				try {
					if ( is_404() ) {
						$tags[] = 'HTTP.404';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// MIN: combined/minified assets.
			$tags[] = 'MIN';
			// W.: widget flag.
			$tags[] = 'W.';

			$tags = array_values( array_unique( $tags ) );

			/**
			 * Filter the LiteSpeed tags for current request (fan-out).
			 *
			 * @since NEXT
			 * @param string[] $tags Tag list.
			 */
			$tags = (array) apply_filters( 'wppo_litespeed_purge_tags', $tags, 'public' );

			return $tags;
		}

		/**
		 * Send LiteSpeed tag headers (WPPO + full fan-out).
		 *
		 * Emits X-LiteSpeed-Tag for each tag via do_action + raw header.
		 * Uses DB_QUEUE fallback when headers_sent.
		 * Filterable via wppo_litespeed_tag (single) and wppo_litespeed_purge_tags (array).
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function send_litespeed_tags(): void {
			$tags = self::get_litespeed_tags_for_purge();
			// Also allow singular wppo_litespeed_tag filter for backwards compat on WPPO.
			$first = $tags[0] ?? 'WPPO';
			/**
			 * Filter the LiteSpeed tag for WPPO pages.
			 *
			 * @since NEXT
			 * @param string $tag Tag string.
			 */
			$filtered_first = (string) apply_filters( 'wppo_litespeed_tag', $first );
			if ( '' !== $filtered_first && $filtered_first !== $first ) {
				$tags[0] = $filtered_first;
			}
			$headers_sent = function_exists( 'headers_sent' ) ? headers_sent() : false;
			foreach ( $tags as $tag ) {
				if ( '' === $tag ) {
					continue;
				}
				if ( has_action( 'litespeed_tag' ) ) {
					do_action( 'litespeed_tag', $tag );
				}
				if ( ! $headers_sent ) {
					header( 'X-LiteSpeed-Tag: ' . $tag, false );
				}
			}
			// Singular post tag via litespeed_tag_post for parity (already includes Po.* above, but keep hook).
			if ( function_exists( 'is_singular' ) ) {
				$is_singular = false;
				try {
					$is_singular = is_singular();
				} catch ( \Throwable $e ) {
					$is_singular = false;
				}
				if ( $is_singular ) {
					$post_id = function_exists( 'get_queried_object_id' ) ? (int) get_queried_object_id() : 0;
					if ( $post_id > 0 ) {
						$post_id = (int) apply_filters( 'wppo_litespeed_tag_post_id', $post_id );
						if ( $post_id > 0 && has_action( 'litespeed_tag_post' ) ) {
							do_action( 'litespeed_tag_post', $post_id );
						}
					}
				}
			}
			if ( $headers_sent && ! empty( $tags ) ) {
				// Fallback queue when headers already sent.
				self::queue_purge_tags( $tags, 'public' );
			}
		}

		/**
		 * Filter litespeed_vary — append wppo_role_hash when vary bridge active.
		 *
		 * Uses litespeed_vary (not litespeed_vary_cookies) per LS-303. Only when
		 * is_litespeed && is_wppo_cache_owner && enableLoggedInCache. Filterable
		 * via wppo_litespeed_vary.
		 *
		 * @since NEXT
		 * @param mixed $vary Vary value from LSCWP (array|string).
		 * @return mixed Modified vary value.
		 */
		public static function filter_litespeed_vary( $vary ) {
			$groups  = self::get_vary_groups();
			$has_any = $groups['role'] || $groups['guest'] || $groups['mobile'] || $groups['webp'];
			if ( ! $has_any ) {
				return $vary;
			}

			$to_add = array();
			if ( $groups['role'] ) {
				$to_add['wppo_role_hash'] = 'wppo_role_hash';
			}
			if ( $groups['guest'] ) {
				$to_add['wppo_guest'] = 'wppo_guest';
			}
			if ( $groups['mobile'] ) {
				$to_add['wppo_mobile'] = 'wppo_mobile';
			}
			if ( $groups['webp'] ) {
				$to_add['wppo_webp'] = 'wppo_webp';
			}

			if ( is_array( $vary ) ) {
				foreach ( $to_add as $k => $v ) {
					$vary[ $k ] = $v;
				}
			} elseif ( is_string( $vary ) ) {
				$parts = '' !== $vary ? explode( ',', $vary ) : array();
				$parts = array_map( 'trim', $parts );
				foreach ( $to_add as $v ) {
					if ( ! in_array( $v, $parts, true ) ) {
						$parts[] = $v;
					}
				}
				$vary = implode( ',', $parts );
			} else {
				$vary = $to_add;
			}

			/**
			 * Filter the LiteSpeed vary value after WPPO vary appended.
			 *
			 * @since NEXT
			 * @param mixed $vary Vary value.
			 * @param array $groups Active vary groups.
			 */
			$vary = apply_filters( 'wppo_litespeed_vary', $vary, $groups );

			return $vary;
		}

		/**
		 * Whether next-gen Vary:Accept rewrite is enabled (LS-401).
		 *
		 * Opt-in via litespeed_integration.enableNextGenRewrite (default false).
		 * Gated by is_litespeed() and image_optimisation.convertImg. Filterable
		 * via wppo_litespeed_nextgen_rewrite.
		 *
		 * @since NEXT
		 * @return bool True if next-gen rewrite should be active.
		 */
		public static function is_nextgen_rewrite_enabled(): bool {
			if ( null !== self::$cached_nextgen ) {
				return self::$cached_nextgen;
			}

			// Must be LiteSpeed server — htaccess next-gen is LS-only.
			if ( ! self::is_litespeed() ) {
				self::$cached_nextgen = false;
				return self::$cached_nextgen;
			}

			$options = get_option( 'wppo_settings', array() );
			$enabled = ! empty( $options['litespeed_integration']['enableNextGenRewrite'] );
			// Gate on convertImg (image next-gen conversion must be active).
			$convert = ! empty( $options['image_optimisation']['convertImg'] );
			if ( ! $convert ) {
				$enabled = false;
			}

			/**
			 * Filter whether next-gen Vary:Accept rewrite is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether next-gen rewrite is enabled.
			 */
			$enabled = (bool) apply_filters( 'wppo_litespeed_nextgen_rewrite', $enabled );

			/**
			 * Legacy alias — also check wppo_litespeed_enable_nextgen_rewrite.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether next-gen rewrite is enabled.
			 */
			$enabled = (bool) apply_filters( 'wppo_litespeed_enable_nextgen_rewrite', $enabled );

			self::$cached_nextgen = $enabled;

			return self::$cached_nextgen;
		}

		/**
		 * Whether next-gen rewrite is enabled for Nginx/server_rules context (LS-402).
		 *
		 * For Nginx, is_litespeed is not required — the map is server-agnostic
		 * but still opt-in via enableNextGenRewrite and gated on convertImg.
		 * Filterable via wppo_litespeed_nextgen_rewrite.
		 *
		 * @since NEXT
		 * @return bool True if nginx next-gen map should be included.
		 */
		public static function is_nextgen_rewrite_enabled_for_nginx(): bool {
			$options = get_option( 'wppo_settings', array() );
			$enabled = ! empty( $options['litespeed_integration']['enableNextGenRewrite'] );
			$convert = ! empty( $options['image_optimisation']['convertImg'] );
			if ( ! $convert ) {
				$enabled = false;
			}

			/**
			 * Filter whether next-gen rewrite is enabled for Nginx.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether next-gen rewrite is enabled.
			 */
			$enabled = (bool) apply_filters( 'wppo_litespeed_nextgen_rewrite', $enabled );
			$enabled = (bool) apply_filters( 'wppo_litespeed_enable_nextgen_rewrite', $enabled );

			return $enabled;
		}

		/**
		 * Whether Brotli .br generation is enabled (LS-403).
		 *
		 * Opt-in via litespeed_integration.enableBrotli (default false).
		 * Requires brotli extension (extension_loaded('brotli') or
		 * function_exists('brotli_compress')). Filterable via
		 * wppo_litespeed_brotli and wppo_litespeed_enable_brotli.
		 *
		 * @since NEXT
		 * @return bool True if brotli generation should run.
		 */
		public static function is_brotli_enabled(): bool {
			if ( null !== self::$cached_brotli ) {
				return self::$cached_brotli;
			}

			$options = get_option( 'wppo_settings', array() );
			$enabled = ! empty( $options['litespeed_integration']['enableBrotli'] );

			if ( ! $enabled ) {
				self::$cached_brotli = false;
				return self::$cached_brotli;
			}

			// Require brotli extension.
			$has_brotli = extension_loaded( 'brotli' ) || function_exists( 'brotli_compress' );
			if ( ! $has_brotli ) {
				self::$cached_brotli = false;
				return self::$cached_brotli;
			}

			/**
			 * Filter whether Brotli generation is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether brotli is enabled.
			 */
			$enabled = (bool) apply_filters( 'wppo_litespeed_brotli', $enabled );

			/**
			 * Legacy alias — also check wppo_litespeed_enable_brotli.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether brotli is enabled.
			 */
			$enabled = (bool) apply_filters( 'wppo_litespeed_enable_brotli', $enabled );

			self::$cached_brotli = $enabled;

			return self::$cached_brotli;
		}

		/**
		 * Whether CDN rewriting is allowed by LiteSpeed guard (LS-404).
		 *
		 * Respects wppo_litespeed_can_cdn (our prefix) and litespeed_can_cdn
		 * (LSCWP ecosystem filter). When either returns false, WPPO CDN rewrite
		 * is skipped to avoid double CDN mapping. True by default.
		 *
		 * @since NEXT
		 * @return bool True if CDN rewriting may proceed.
		 */
		public static function can_apply_cdn(): bool {
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				self::$cached_can_cdn = false;
				return self::$cached_can_cdn;
			}
			if ( null !== self::$cached_can_cdn ) {
				return self::$cached_can_cdn;
			}

			/**
			 * Filter whether WPPO CDN rewriting is allowed.
			 *
			 * @since NEXT
			 * @param bool $can_cdn Whether CDN may be applied.
			 */
			$can_cdn = (bool) apply_filters( 'wppo_litespeed_can_cdn', true );

			if ( ! $can_cdn ) {
				self::$cached_can_cdn = false;
				return self::$cached_can_cdn;
			}

			// Respect LSCWP ecosystem filter litespeed_can_cdn when present.
			if ( has_filter( 'litespeed_can_cdn' ) && ! apply_filters( 'litespeed_can_cdn', true ) ) {
				self::$cached_can_cdn = false;
				return self::$cached_can_cdn;
			}

			self::$cached_can_cdn = true;

			return self::$cached_can_cdn;
		}

		/**
		 * Strip generic Cache-Control when LS public header sent (LS-304).
		 *
		 * Prevents Cache-Control: no-cache conflicting with
		 * X-LiteSpeed-Cache-Control: public,max-age=N. Uses header_remove()
		 * when available; respects wppo_litespeed_strip_cache_control filter.
		 *
		 * @since NEXT
		 * @return void
		 */
		private static function maybe_strip_generic_cache_control(): void {
			/**
			 * Filter whether to strip generic Cache-Control when LS header sent.
			 *
			 * @since NEXT
			 * @param bool $strip Whether to strip.
			 */
			$strip = (bool) apply_filters( 'wppo_litespeed_strip_cache_control', true );

			if ( ! $strip || headers_sent() ) {
				return;
			}

			if ( function_exists( 'header_remove' ) ) {
				header_remove( 'Cache-Control' );
				header_remove( 'Pragma' );
			}
		}

		/**
		 * Reset all per-request caches (for testing).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_cache(): void {
			self::$cached_effective_mode    = null;
			self::$cached_mode              = null;
			self::$cached_is_litespeed      = null;
			self::$cached_is_lscache_active = null;
			self::$cached_ttl               = null;
			self::$cached_is_cacheable      = null;
			self::$cached_should_vary       = null;
			self::$cached_nextgen           = null;
			self::$cached_brotli            = null;
			self::$cached_can_cdn           = null;
			self::$hooks_registered         = false;
			self::$queue_shutdown_hooked    = false;
		}

		/**
		 * Get a structured LiteSpeed info array for REST/JS consumption.
		 *
		 * @since NEXT
		 * @return array{
		 *     detected: bool,
		 *     server_type: string,
		 *     lscache_active: bool,
		 *     mode: string,
		 *     effective_mode: string,
		 *     wppo_owns_cache: bool,
		 *     optimizer_disabled: bool
		 * }
		 */
		public static function get_info(): array {
			$server_type = class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'get_server_type' )
				? Server_Rules::get_server_type()
				: 'other';

			$info = array(
				'detected'           => self::is_litespeed(),
				'server_type'        => $server_type,
				'lscache_active'     => self::is_lscache_active(),
				'mode'               => self::get_mode(),
				'effective_mode'     => self::effective_mode(),
				'wppo_owns_cache'    => self::is_wppo_cache_owner(),
				'optimizer_disabled' => self::should_disable_wppo_optimizer(),
				'vary_groups'        => self::get_vary_groups(),
			);
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
				$info['crawler'] = array(
					'concurrency'        => LiteSpeed_Crawler::get_concurrency(),
					'blacklistThreshold' => LiteSpeed_Crawler::get_blacklist_threshold(),
				);
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI' ) ) {
				$info['esi_available'] = LiteSpeed_ESI::is_esi_available();
			}
			return $info;
		}
	}
}
