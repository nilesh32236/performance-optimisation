<?php
/**
 * Host_Detect boundary — managed-host detection for purge fan-out.
 *
 * Detects Kinsta, WP Engine, SiteGround, and Cloudways/Breeze from MU
 * markers and constants without calling host APIs at detect time.
 * Detection is filter-extensible (`wppo_host_adapter`, which may return
 * custom slugs beyond the known set) and fails open so the purge
 * coordinator no-ops safely on unknown hosts. Only server-set markers
 * are probed — client-controlled request headers are never trusted.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Host_Detect' ) ) {
	/**
	 * Managed-host detection boundary.
	 *
	 * @since NEXT
	 */
	final class Host_Detect {

		/**
		 * Filter overriding the detected host slug.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const FILTER_ADAPTER = 'wppo_host_adapter';

		/**
		 * Filter for the final detected slug.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const FILTER_DETECTED = 'wppo_host_detected';

		/**
		 * Known host slugs.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const KNOWN_SLUGS = array( 'kinsta', 'wpengine', 'siteground', 'cloudways' );

		/**
		 * Per-request memo of the final detected slug.
		 *
		 * The detect() method runs on the hottest frontend path
		 * (Cache::is_not_cacheable) and applies filters on every call;
		 * memoize so one request probes once. Reset via reset_cache()
		 * (unit tests, long-running workers).
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private static $detect_memo = null;

		/**
		 * Hosts whose own page cache bans an overlapping static-file cache.
		 *
		 * Kinsta bans Cache Enabler-style static caches; WP Engine bans
		 * W3TC/WPSC/Hyper-style static caches. On these hosts WPPO degrades
		 * to notify-only: the static drop-in is refused and purges fan out
		 * to the host instead.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const BANNED_SLUGS = array( 'kinsta', 'wpengine' );

		/**
		 * Detect the managed host slug, or 'none' when unknown.
		 *
		 * Probe order is constants → server markers → classes/functions
		 * (all probed, never called), then the two filters. The allowlist
		 * applies to the probe result only: a non-empty slug returned by
		 * either filter is accepted as-is (sanitized) so custom hosts can
		 * extend detection; unknown/custom slugs fail open downstream
		 * (no ban, host purger no-ops). Never throws. Per-request memoized;
		 * use reset_cache() to clear (tests, workers).
		 *
		 * @since NEXT
		 * @return string Detected slug, a custom filter-provided slug, or 'none'.
		 */
		public static function detect(): string {
			if ( null !== self::$detect_memo ) {
				return self::$detect_memo;
			}

			try {
				$slug = self::probe();
			} catch ( \Throwable $e ) {
				unset( $e );
				$slug = 'none';
			}

			if ( ! in_array( $slug, self::KNOWN_SLUGS, true ) ) {
				$slug = 'none';
			}

			try {
				if ( function_exists( 'apply_filters' ) ) {
					$override = apply_filters( self::FILTER_ADAPTER, $slug );
					if ( is_string( $override ) && '' !== trim( $override ) ) {
						$slug = strtolower( trim( $override ) );
					}
					$filtered = apply_filters( self::FILTER_DETECTED, $slug );
					if ( ! is_string( $filtered ) || '' === trim( $filtered ) ) {
						$slug = 'none';
					} else {
						$slug = strtolower( trim( $filtered ) );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( '' === $slug ) {
				$slug = 'none';
			}

			self::$detect_memo = $slug;
			return $slug;
		}

		/**
		 * Clear the per-request detection memo.
		 *
		 * Intended for unit tests and long-running workers where host
		 * markers or filters change mid-process. No-op otherwise.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_cache(): void {
			self::$detect_memo = null;
		}

		/**
		 * Whether the detected host bans an overlapping WPPO page cache.
		 *
		 * @since NEXT
		 * @return bool True on Kinsta / WP Engine.
		 */
		public static function is_banned_conflict(): bool {
			return in_array( self::detect(), self::BANNED_SLUGS, true );
		}

		/**
		 * Human-readable degrade reason, or '' when no degrade applies.
		 *
		 * @since NEXT
		 * @return string Reason string for notices / System Info.
		 */
		public static function get_degrade_reason(): string {
			$slug = self::detect();
			if ( 'kinsta' === $slug ) {
				return __( 'Kinsta manages page cache and bans overlapping static-file caches; WPPO static cache is disabled and purges fan out to Kinsta.', 'performance-optimisation' );
			}
			if ( 'wpengine' === $slug ) {
				return __( 'WP Engine manages page cache and bans overlapping static-file caches; WPPO static cache is disabled and purges fan out to WP Engine.', 'performance-optimisation' );
			}
			return '';
		}

		/**
		 * Whether WPPO may write its static page cache on this host.
		 *
		 * @since NEXT
		 * @return bool False on banned-conflict hosts.
		 */
		public static function is_page_cache_allowed(): bool {
			return ! self::is_banned_conflict();
		}

		/**
		 * Probe MU markers and constants for a known host.
		 *
		 * @since NEXT
		 * @return string Detected slug or 'none'.
		 */
		private static function probe(): string {
			if ( defined( 'KINSTA_CACHE_ZONE' ) || defined( 'KINSTACACHE_PURGE_URL' ) ) {
				return 'kinsta';
			}
			if ( defined( 'WPE_APIKEY' ) || defined( 'WPE_CLUSTER_ID' ) || defined( 'WPE_CLUSTER_TYPE' ) ) {
				return 'wpengine';
			}
			if ( defined( 'SG_CACHEPRESS_VERSION' ) || defined( 'SITEGROUND_CACHE' ) ) {
				return 'siteground';
			}
			if ( defined( 'CLOUDWAYS_CDNI' ) || defined( 'BREEZE_VERSION' ) || defined( 'CW_CLOUDWAYS' ) ) {
				return 'cloudways';
			}

			// Server markers only: HTTP_* entries are client-controlled
			// request headers (spoofable by any visitor) and must never
			// force a banned-conflict degrade. Non-prefixed keys are set
			// by the server environment, not the request.
			if ( isset( $_SERVER['KINSTA_CACHE'] ) ) {
				return 'kinsta';
			}
			if ( isset( $_SERVER['X_WPE_CACHE'] ) ) {
				return 'wpengine';
			}
			if ( isset( $_SERVER['SG_CACHEPRESS'] ) ) {
				return 'siteground';
			}

			try {
				if ( function_exists( 'kinsta_cache_purge_all' ) || function_exists( 'kinsta_cache_purge' ) ) {
					return 'kinsta';
				}
				if ( function_exists( 'wpe_clear_cache' ) || function_exists( 'wpe_purge_cache' ) ) {
					return 'wpengine';
				}
				if ( function_exists( 'sg_cachepress_purge' ) || function_exists( 'sg_cachepress_purge_cache' ) ) {
					return 'siteground';
				}
				if ( function_exists( 'breeze_clear_cache' ) || function_exists( 'breeze_purge_cache' ) ) {
					return 'cloudways';
				}
				if ( class_exists( 'Kinsta\\Cache', false ) || class_exists( 'Kinsta_Cache', false ) ) {
					return 'kinsta';
				}
				if ( class_exists( 'WpeCommon', false ) ) {
					return 'wpengine';
				}
				if ( class_exists( 'SG_CachePress', false ) || class_exists( 'SiteGround_Cache', false ) ) {
					return 'siteground';
				}
				if ( class_exists( 'Breeze_PurgeCache', false ) ) {
					return 'cloudways';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return 'none';
		}
	}
}
