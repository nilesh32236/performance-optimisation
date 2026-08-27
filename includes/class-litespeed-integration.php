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
			if ( is_multisite() ) {
				$network_plugins = array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) );
				$active_plugins  = array_merge( $active_plugins, $network_plugins );
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

			return array(
				'detected'           => self::is_litespeed(),
				'server_type'        => $server_type,
				'lscache_active'     => self::is_lscache_active(),
				'mode'               => self::get_mode(),
				'effective_mode'     => self::effective_mode(),
				'wppo_owns_cache'    => self::is_wppo_cache_owner(),
				'optimizer_disabled' => self::should_disable_wppo_optimizer(),
			);
		}
	}
}
