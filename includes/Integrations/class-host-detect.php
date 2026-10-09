<?php
/**
 * Host_Detect boundary — managed-host detection for purge fan-out.
 *
 * Detects Kinsta, WP Engine, SiteGround, and Cloudways/Breeze from MU
 * markers and constants without calling host APIs at detect time.
 * Detection is filter-extensible (`wppo_host_adapter`) and fails open to
 * 'none' on unknown hosts so the purge coordinator no-ops safely.
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
		 * (all probed, never called), then the two filters. Never throws.
		 *
		 * @since NEXT
		 * @return string One of 'kinsta', 'wpengine', 'siteground', 'cloudways', 'none'.
		 */
		public static function detect(): string {
			try {
				$slug = self::probe();
			} catch ( \Throwable $e ) {
				unset( $e );
				$slug = 'none';
			}

			try {
				if ( function_exists( 'apply_filters' ) ) {
					$override = apply_filters( self::FILTER_ADAPTER, $slug );
					if ( is_string( $override ) && '' !== $override ) {
						$slug = strtolower( trim( $override ) );
					}
					$slug = apply_filters( self::FILTER_DETECTED, $slug );
					if ( ! is_string( $slug ) || '' === $slug ) {
						return 'none';
					}
					$slug = strtolower( trim( $slug ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( ! in_array( $slug, self::KNOWN_SLUGS, true ) ) {
				return 'none';
			}
			return $slug;
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

			if ( isset( $_SERVER['KINSTA_CACHE'] ) || isset( $_SERVER['HTTP_X_KINSTA_CACHE'] ) ) {
				return 'kinsta';
			}
			if ( isset( $_SERVER['X_WPE_CACHE'] ) || isset( $_SERVER['HTTP_X_WPE_CACHE'] ) ) {
				return 'wpengine';
			}
			if ( isset( $_SERVER['HTTP_X_SG_CACHE'] ) || isset( $_SERVER['SG_CACHEPRESS'] ) ) {
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
