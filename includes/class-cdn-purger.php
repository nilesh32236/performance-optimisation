<?php
/**
 * CDN cache purger (Cloudflare / Varnish).
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'PerformanceOptimise\Inc\CDN_Purger' ) ) {

	/**
	 * Purges third-party caches after the plugin's own cache is cleared.
	 *
	 * Cloudflare uses a Bearer token which is read from the
	 * WPPO_CLOUDFLARE_API_TOKEN constant (never stored in the database, mirroring
	 * the Redis password handling). Varnish is purged by sending PURGE requests
	 * to the configured server URLs.
	 *
	 * @since 2.0.0
	 */
	class CDN_Purger {

		use Purge_Logger;

		/**
		 * Name of the constant holding the Cloudflare API token.
		 *
		 * @var string
		 */
		public const TOKEN_CONSTANT = 'WPPO_CLOUDFLARE_API_TOKEN';

		/**
		 * Purge the configured third-party cache.
		 *
		 * Hooks into wppo_after_cache_clear so a full cache clear also empties
		 * the CDN/edge cache. Single-page clears issue a URL-scoped
		 * Cloudflare purge_files for the page URL and never purge_everything:
		 * wiping the whole zone for one page would be disproportionate.
		 * Varnish single-page clears stay a logged no-op (fail-open) because
		 * the configured endpoints are server-level PURGE targets with no
		 * per-URL mapping. When no zone config exists, returns true with a
		 * logged skip and performs no HTTP.
		 *
		 * The $url_path parameter is deliberately untyped: this method runs
		 * as a WP hook callback (wppo_after_cache_clear via
		 * Cache::clear_cache(), untyped) and must stay tolerant of
		 * non-string payloads instead of throwing a TypeError.
		 *
		 * @param string $type     Clear type ('all' or 'single_page').
		 * @param mixed  $url_path Page path (or absolute URL) for single-page clears.
		 * @return bool True when no purge was needed or all requests succeeded.
		 *
		 * @since 2.0.0 The $type and $url_path parameters were added.
		 */
		public static function purge_all( string $type = 'all', $url_path = null ): bool {
			// LS-203: LiteSpeed purge sync — always attempt before the
			// 'all'-only early return so single-page clears also sync when
			// purgeSync is enabled (loop-safe via Util::transient_key lock).
			self::purge_litespeed( $type, $url_path );

			$options = Util::get_settings();
			$cache   = isset( $options['cache_settings'] ) && is_array( $options['cache_settings'] ) ? $options['cache_settings'] : array();

			$service = isset( $cache['cdnPurgeService'] ) ? sanitize_text_field( (string) $cache['cdnPurgeService'] ) : 'none';

			if ( 'single_page' === $type ) {
				return self::purge_single_page( $cache, $service, $url_path );
			}

			if ( 'all' !== $type ) {
				return true;
			}

			if ( ! self::is_configured( $cache ) ) {
				// Default 'none'/empty service means nothing was ever configured:
				// stay silent instead of emitting a skip line on every clear.
				if ( 'none' !== $service && '' !== $service ) {
					self::log_skip( $service, 'not configured' );
				}
				return true;
			}

			if ( 'cloudflare' === $service ) {
				return self::purge_cloudflare( $cache );
			}
			if ( 'varnish' === $service ) {
				return self::purge_varnish( $cache );
			}

			return true;
		}

		/**
		 * Handle a single-page clear with a scoped purge, never purge-all.
		 *
		 * Cloudflare resolves $url_path to an absolute URL and issues a
		 * purge_files call. Anything unresolvable, unconfigured, or
		 * non-Cloudflare falls back to the previous skip-single-page
		 * behaviour (logged skip, no HTTP, fail-open).
		 *
		 * @since NEXT
		 * @param array  $cache   cache_settings values.
		 * @param string $service Sanitized cdnPurgeService value.
		 * @param mixed  $url_path Page path (or absolute URL) for single-page clears.
		 * @return bool True when skipped or the scoped purge succeeded.
		 */
		private static function purge_single_page( array $cache, string $service, $url_path ): bool {
			if ( 'cloudflare' !== $service ) {
				self::log_skip( $service, 'single-page: no scoped purge for service' );
				return true;
			}
			$page_url = self::resolve_page_url( $url_path );
			if ( '' === $page_url ) {
				self::log_skip( $service, 'single-page: unresolvable URL' );
				return true;
			}
			$zone  = isset( $cache['cloudflareZoneId'] ) ? sanitize_text_field( (string) $cache['cloudflareZoneId'] ) : '';
			$token = defined( self::TOKEN_CONSTANT ) ? (string) constant( self::TOKEN_CONSTANT ) : '';
			if ( '' === $zone || '' === $token ) {
				self::log_skip( $service, 'not configured' );
				return true;
			}
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cloudflare_Purger' ) ) {
				self::log_skip( $service, 'single-page: transport unavailable' );
				return true;
			}
			return Cloudflare_Purger::purge_files( $zone, $token, array( $page_url ), 'cloudflare', 'CDN purge failed' );
		}

		/**
		 * Resolve a single-page purge target to an absolute URL.
		 *
		 * Reuses Edge_Purger::resolve_page_url() when available so both
		 * purgers share one implementation; falls back to a local
		 * home_url() resolution otherwise. Returns '' when unresolvable.
		 *
		 * @since NEXT
		 * @param mixed $url_path Page path or absolute URL.
		 * @return string Absolute URL or ''.
		 */
		private static function resolve_page_url( $url_path ): string {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Edge_Purger' ) && method_exists( 'PerformanceOptimise\Inc\Edge_Purger', 'resolve_page_url' ) ) {
					return (string) Edge_Purger::resolve_page_url( $url_path );
				}
				if ( ! is_string( $url_path ) || '' === trim( $url_path ) ) {
					return '';
				}
				$candidate = trim( $url_path );
				if ( 0 === strpos( $candidate, 'http://' ) || 0 === strpos( $candidate, 'https://' ) ) {
					return esc_url_raw( $candidate );
				}
				if ( function_exists( 'home_url' ) ) {
					return esc_url_raw( home_url( '/' . ltrim( $candidate, '/' ) ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * Purge LiteSpeed/LSCache when purgeSync is enabled with stale handling.
		 *
		 * Delegates to LiteSpeed_Integration's loop-safe sync helpers. Handles
		 * both "all" (→ litespeed_purge_all) and single-page (→
		 * litespeed_purge_url) via home_url(). Also queues stale tags so LS
		 * stale cache is cleared (LS-330 stale/private split).
		 *
		 * @since 2.0.0
		 * @param string      $type     Clear type ('all' or 'single_page').
		 * @param string|null $url_path Page path for single-page clears.
		 * @return void
		 */
		private static function purge_litespeed( string $type, $url_path = null ): void {
			if ( ! class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ) {
				return;
			}

			if ( 'all' === $type ) {
				LiteSpeed_Integration::sync_purge_all_to_litespeed();
				// Stale handling: queue full fan-out as stale for double-cache prevention.
				if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'queue_purge_tags' ) ) {
					$tags = array( 'F', 'H', 'PGS', 'D', 'B', 'C', 'W', 'REST', 'HTTP.404', 'MIN', 'WPPO' );
					LiteSpeed_Integration::queue_purge_tags( $tags, 'stale' );
				}
				return;
			}

			if ( 'single_page' === $type && is_string( $url_path ) && '' !== $url_path ) {
				$path_for_url = '/' . ltrim( $url_path, '/' );
				LiteSpeed_Integration::sync_purge_url_to_litespeed( $path_for_url );
				// Stale/private split for single page.
				$scope = 'public';
				if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_private_request' ) ) {
					try {
						if ( LiteSpeed_Integration::is_private_request() ) {
							$scope = 'private';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'queue_purge_tags' ) ) {
					LiteSpeed_Integration::queue_purge_tags( array( 'D', 'B', 'C', 'W', 'REST' ), $scope );
				}
				return;
			}

			// Fallback: if caller passes raw path with 'all' already handled,
			// any non-empty url_path as single URL.
			if ( is_string( $url_path ) && '' !== $url_path ) {
				$path_for_url = '/' . ltrim( $url_path, '/' );
				LiteSpeed_Integration::sync_purge_url_to_litespeed( $path_for_url );
				if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'queue_purge_tags' ) ) {
					LiteSpeed_Integration::queue_purge_tags( array( 'D', 'B', 'C', 'W', 'REST' ), 'public' );
				}
			}
		}

		/**
		 * Whether the configured purge service has everything it needs.
		 *
		 * Accepts an already-loaded cache_settings slice to avoid re-reading
		 * settings when the caller (purge_all) already has it; falls back to
		 * Util::get_settings() when omitted so existing callers keep working.
		 *
		 * @since 2.0.0
		 * @since NEXT Added optional $cache_settings parameter.
		 * @param array|null $cache_settings Optional cache_settings slice.
		 * @return bool
		 */
		public static function is_configured( ?array $cache_settings = null ): bool {
			if ( null === $cache_settings ) {
				$options        = Util::get_settings();
				$cache_settings = isset( $options['cache_settings'] ) && is_array( $options['cache_settings'] ) ? $options['cache_settings'] : array();
			}
			$cache   = $cache_settings;
			$service = isset( $cache['cdnPurgeService'] ) ? sanitize_text_field( (string) $cache['cdnPurgeService'] ) : 'none';

			if ( 'cloudflare' === $service ) {
				return ! empty( $cache['cloudflareZoneId'] )
					&& defined( self::TOKEN_CONSTANT )
					&& '' !== (string) constant( self::TOKEN_CONSTANT );
			}
			if ( 'varnish' === $service ) {
				return ! empty( $cache['varnishPurgeUrls'] );
			}

			return false;
		}

		/**
		 * Purge everything on Cloudflare for the configured zone.
		 *
		 * Unreachable-by-construction safeguard: purge_all() already gates the
		 * 'all' path on is_configured(), so the empty zone/token early return
		 * below only fires for direct calls. Retained as defense-in-depth.
		 *
		 * @param array $cache cache_settings values.
		 * @return bool
		 */
		private static function purge_cloudflare( array $cache ): bool {
			$zone  = isset( $cache['cloudflareZoneId'] ) ? sanitize_text_field( (string) $cache['cloudflareZoneId'] ) : '';
			$token = defined( self::TOKEN_CONSTANT ) ? (string) constant( self::TOKEN_CONSTANT ) : '';

			if ( '' === $zone || '' === $token ) {
				return false;
			}

			// Defensive: isolated tests or partial-release builds may load
			// this file without the Cloudflare_Purger transport.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Cloudflare_Purger' ) ) {
				return false;
			}
			// Single implementation lives in Cloudflare_Purger::purge().
			return Cloudflare_Purger::purge( $zone, $token, 'cloudflare' );
		}

		/**
		 * Send PURGE requests to the configured Varnish endpoints.
		 *
		 * Unreachable-by-construction safeguard: purge_all() already gates the
		 * 'all' path on is_configured(), so the empty-URL early return below
		 * only fires for direct calls. Retained as defense-in-depth.
		 *
		 * The number of endpoints is capped (default 20, filterable) and the
		 * per-request timeout is short so an unreachable node cannot stall the
		 * cache-clear request.
		 *
		 * @param array $cache cache_settings values.
		 * @return bool
		 */
		private static function purge_varnish( array $cache ): bool {
			$urls = isset( $cache['varnishPurgeUrls'] ) ? (array) $cache['varnishPurgeUrls'] : array();
			if ( empty( $urls ) ) {
				return false;
			}

			$max_urls = max( 1, (int) apply_filters( 'wppo_varnish_purge_max_urls', 20 ) );
			$urls     = array_slice( $urls, 0, $max_urls );

			$ok = true;
			foreach ( $urls as $url ) {
				$clean = esc_url_raw( (string) $url );
				if ( '' === $clean ) {
					continue;
				}

				// Tolerant scheme gate (pure PHP so no WP stub is required):
				// only http(s) URLs are purged; anything else is skipped and
				// logged. Unparseable URLs fail closed here.
				$lower = strtolower( $clean );
				if ( ! str_starts_with( $lower, 'http://' ) && ! str_starts_with( $lower, 'https://' ) ) {
					self::log_failure( 'varnish', $clean );
					$ok = false;
					continue;
				}
				// No reject_unsafe_urls here: the purge endpoint is an
				// admin-configured CDN/Varnish host, which is normally an
				// internal address (10.x/192.168.x) or an internal DNS name.
				// WP's validator rejects every private, loopback and
				// non-dotted host, so enabling it would silently stop all
				// purges on the standard Varnish topology. The scheme gate
				// above is the injection control.
				$response = wp_remote_request(
					$clean,
					array(
						'method'  => 'PURGE',
						'timeout' => 5,
					)
				);

				if ( is_wp_error( $response ) ) {
					self::log_failure( 'varnish', $clean );
					$ok = false;
					continue;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( $code >= 400 ) {
					self::log_failure( 'varnish', $clean . ' (HTTP ' . $code . ')' );
					$ok = false;
				}
			}

			return $ok;
		}

		/**
		 * Surface a failed edge-cache purge through the plugin's debug log.
		 *
		 * @param string $service Provider name.
		 * @param string $detail  Endpoint / reason.
		 * @return void
		 */
		private static function log_failure( string $service, string $detail ): void {
			self::log_purge_failure( $service, $detail, 'CDN purge failed', 'wppo_cdn_purge_log_lock', 60 );
		}

		/**
		 * Surface a skipped purge via the debug log (no HTTP performed).
		 *
		 * @since NEXT
		 * @param string $service Service name.
		 * @param string $detail  Reason.
		 * @return void
		 */
		private static function log_skip( string $service, string $detail ): void {
			self::log_purge_skip( $service, $detail, 'CDN purge skipped' );
		}
	}
}
