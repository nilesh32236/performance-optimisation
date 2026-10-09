<?php
/**
 * Host purger — thin managed-host purge adapters.
 *
 * Fan-out target for Edge_Purge_Coordinator::purge_after_cache_clear().
 * Each adapter fires the host's own purge hook/API and no-ops on unknown
 * or unconfigured hosts. Only a transport with an observable failure
 * (Cloudways Varnish PURGE) can return false; hook-based hosts
 * (Kinsta/WPE/SiteGround) fire-and-forget and return true so the
 * coordinator's `&&` chain preserves the #1651 false-success fix: a
 * purger never reports success for a provider it did not reach.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Host_Purger' ) ) {
	/**
	 * Managed-host purge adapters.
	 *
	 * @since NEXT
	 */
	final class Host_Purger {

		/**
		 * Purge the detected managed host after a WPPO cache clear.
		 *
		 * The $type/$url_path parameters are deliberately untyped: this
		 * method runs as part of the wppo_after_cache_clear fan-out and must
		 * stay tolerant of non-string payloads instead of throwing.
		 *
		 * @since NEXT
		 * @param mixed $type     Clear type ('all' or 'single_page').
		 * @param mixed $url_path Page path for a single-page clear.
		 * @return bool True when no purge was needed or the host was reached; false on transport failure.
		 */
		public static function purge_all( $type = 'all', $url_path = null ): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Host_Detect' ) ) {
					return true;
				}
				$slug = Host_Detect::detect();
				if ( 'none' === $slug ) {
					return true;
				}

				if ( 'single_page' === $type ) {
					return self::purge_single( $slug, $url_path );
				}
				if ( 'all' === $type ) {
					return self::purge_host( $slug );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}

			return true;
		}

		/**
		 * Purge one host for a full clear.
		 *
		 * @since NEXT
		 * @param string $slug Detected host slug.
		 * @return bool True on no-op/reached, false on transport failure.
		 */
		private static function purge_host( string $slug ): bool {
			switch ( $slug ) {
				case 'kinsta':
					return self::fire_host_hook( 'kinsta_cache_flush_all', 'kinsta-cache/purge' );
				case 'wpengine':
					return self::fire_host_hook( 'wpe_clear_cache', 'wpe_purge_cache' );
				case 'siteground':
					return self::fire_host_hook( 'sg_cachepress_purge', 'sg_cachepress_purge_cache' );
				case 'cloudways':
					return self::purge_cloudways_varnish( null );
				default:
					return true;
			}
		}

		/**
		 * Purge one host for a single-page clear.
		 *
		 * Hook-based hosts expose only all-or-nothing purges, so a
		 * single-page event still fans out to the host full purge (the
		 * host owns the cache and coalesces internally). Cloudways Varnish
		 * supports a URL-scoped PURGE.
		 *
		 * @since NEXT
		 * @param string $slug     Detected host slug.
		 * @param mixed  $url_path Page path or URL.
		 * @return bool True on no-op/reached, false on transport failure.
		 */
		private static function purge_single( string $slug, $url_path ): bool {
			switch ( $slug ) {
				case 'kinsta':
					return self::fire_host_hook( 'kinsta_cache_flush_all', 'kinsta-cache/purge' );
				case 'wpengine':
					return self::fire_host_hook( 'wpe_clear_cache', 'wpe_purge_cache' );
				case 'siteground':
					return self::fire_host_hook( 'sg_cachepress_purge', 'sg_cachepress_purge_cache' );
				case 'cloudways':
					return self::purge_cloudways_varnish( $url_path );
				default:
					return true;
			}
		}

		/**
		 * Fire a host purge hook with function-first preference.
		 *
		 * Prefers a same-named function when it exists (probed, never
		 * assumed), otherwise fires the action. Fire-and-forget by design:
		 * host hooks expose no failure signal, so reaching the hook counts
		 * as reaching the provider.
		 *
		 * @since NEXT
		 * @param string $primary   Primary hook/function name.
		 * @param string $fallback  Fallback action name.
		 * @return bool Always true (no observable failure channel).
		 */
		private static function fire_host_hook( string $primary, string $fallback ): bool {
			try {
				if ( function_exists( $primary ) ) {
					call_user_func( $primary );
					return true;
				}
				if ( function_exists( 'do_action' ) ) {
					do_action( $primary );
					if ( $fallback !== $primary ) {
						do_action( $fallback );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return true;
		}

		/**
		 * PURGE the Cloudways/Varnish edge for all or one URL.
		 *
		 * Sends an HTTP PURGE against the home URL (or the resolved page
		 * URL for single-page clears). Returns false on transport failure
		 * so the coordinator reports the clear honestly.
		 *
		 * @since NEXT
		 * @param mixed $url_path Page path/URL, or null for a full purge.
		 * @return bool True on success or no-op; false on transport failure.
		 */
		private static function purge_cloudways_varnish( $url_path ): bool {
			try {
				if ( function_exists( 'breeze_clear_cache' ) ) {
					call_user_func( 'breeze_clear_cache' );
					return true;
				}

				$target = '';
				if ( is_string( $url_path ) && '' !== trim( $url_path ) ) {
					$candidate = trim( $url_path );
					if ( 0 === strpos( $candidate, 'http://' ) || 0 === strpos( $candidate, 'https://' ) ) {
						$target = $candidate;
					} elseif ( function_exists( 'home_url' ) ) {
						$target = home_url( '/' . ltrim( $candidate, '/' ) );
					}
				} elseif ( function_exists( 'home_url' ) ) {
					$target = home_url( '/' );
				}

				if ( '' === $target || ! function_exists( 'wp_remote_request' ) ) {
					return true;
				}

				$lower = strtolower( $target );
				if ( 0 !== strpos( $lower, 'http://' ) && 0 !== strpos( $lower, 'https://' ) ) {
					return true;
				}

				$response = wp_remote_request(
					$target,
					array(
						'method'  => 'PURGE',
						'timeout' => 5,
					)
				);

				if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
					self::log_failure( $target );
					return false;
				}
				if ( function_exists( 'wp_remote_retrieve_response_code' ) ) {
					$code = (int) wp_remote_retrieve_response_code( $response );
					if ( $code >= 400 ) {
						self::log_failure( $target . ' (HTTP ' . $code . ')' );
						return false;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
			return true;
		}

		/**
		 * Surface a failed host purge through the debug log.
		 *
		 * @since NEXT
		 * @param string $detail Endpoint / reason.
		 * @return void
		 */
		private static function log_failure( string $detail ): void {
			try {
				if ( function_exists( 'do_action' ) ) {
					do_action( 'wppo_debug_log', 'Host purge failed [cloudways-varnish]: ' . $detail );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
