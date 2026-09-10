<?php
/**
 * Edge cache purger — Cloudflare + Bunny.
 *
 * Mirrors CDN_Purger and LiteSpeed_Integration purge patterns
 * with Util::transient_key lock (60s, blog-prefixed).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Edge_Purger' ) ) {
	/**
	 * Edge cache purger.
	 *
	 * @since NEXT
	 */
	class Edge_Purger {

		/**
		 * Transient lock key.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const PURGE_LOCK = 'wppo_edge_purge_lock';

		/**
		 * Lock TTL seconds.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const PURGE_LOCK_TTL = 60;

		/**
		 * Get blog-prefixed lock key.
		 *
		 * @since NEXT
		 * @return string
		 */
		public static function get_purge_lock_key(): string {
			return Util::transient_key( self::PURGE_LOCK );
		}

		/**
		 * Whether lock is active.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function has_purge_lock(): bool {
			return (bool) get_transient( self::get_purge_lock_key() );
		}

		/**
		 * Set lock.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function set_purge_lock(): void {
			set_transient( self::get_purge_lock_key(), 1, self::PURGE_LOCK_TTL );
		}

		/**
		 * Purge edge caches (Cloudflare and Bunny) after wppo_after_cache_clear.
		 *
		 * When edge_cache.enabled is false, returns true immediately (no-op).
		 * For single_page clears, issues a URL-scoped Cloudflare purge_files
		 * for the page URL. Bunny pull zones expose only an all-or-nothing
		 * purgeCache endpoint, so single-page clears cannot purge Bunny for
		 * one URL — that limitation is documented and the Bunny zone is left
		 * untouched (next full purge clears it). When lock is active, no-op.
		 *
		 * Bunny purge uses WPPO_BUNNY_API_KEY + edge_cache.bunnyPullZoneId
		 * via api.bunny.net/pullzone/{id}/purgeCache POST.
		 * Cloudflare purge reuses WPPO_CLOUDFLARE_API_TOKEN + zoneId via
		 * api.cloudflare.com/client/v4/zones/{id}/purge_cache purge_everything
		 * (or purge_files for single_page).
		 *
		 * @since NEXT
		 * @param string      $type     Clear type ('all' or 'single_page').
		 * @param string|null $url_path Page path (or absolute URL) for single-page clears.
		 * @return bool True when no purge needed or all requests succeeded.
		 */
		public static function purge_all( string $type = 'all', $url_path = null ): bool {
			if ( ! Edge_Cache::is_enabled() ) {
				return true;
			}
			if ( self::has_purge_lock() ) {
				return true;
			}

			$settings       = Util::get_settings();
			$edge           = isset( $settings['edge_cache'] ) && is_array( $settings['edge_cache'] ) ? $settings['edge_cache'] : array();
			$cache_settings = isset( $settings['cache_settings'] ) && is_array( $settings['cache_settings'] ) ? $settings['cache_settings'] : array();

			$cf_zone  = ! empty( $edge['cloudflareZoneId'] ) ? sanitize_text_field( (string) $edge['cloudflareZoneId'] ) : ( ! empty( $cache_settings['cloudflareZoneId'] ) ? sanitize_text_field( (string) $cache_settings['cloudflareZoneId'] ) : '' );
			$cf_token = defined( 'WPPO_CLOUDFLARE_API_TOKEN' ) ? (string) constant( 'WPPO_CLOUDFLARE_API_TOKEN' ) : '';

			$bunny_zone = ! empty( $edge['bunnyPullZoneId'] ) ? sanitize_text_field( (string) $edge['bunnyPullZoneId'] ) : '';
			$bunny_key  = defined( 'WPPO_BUNNY_API_KEY' ) ? (string) constant( 'WPPO_BUNNY_API_KEY' ) : '';

			if ( 'all' !== $type ) {
				// URL-scoped purge: Cloudflare supports purge_files; Bunny
				// pull zones are all-or-nothing (documented above).
				$page_url = self::resolve_page_url( $url_path );
				if ( '' === $page_url ) {
					return true;
				}
				if ( '' === $cf_zone || '' === $cf_token ) {
					return true;
				}
				self::set_purge_lock();
				return self::purge_cloudflare_files( $cf_zone, $cf_token, $page_url );
			}
			self::set_purge_lock();

			$ok = true;

			if ( '' !== $cf_zone && '' !== $cf_token ) {
				$cf_ok = self::purge_cloudflare( $cf_zone, $cf_token );
				if ( ! $cf_ok ) {
					$ok = false;
				}
			}

			if ( '' !== $bunny_zone && '' !== $bunny_key ) {
				$bunny_ok = self::purge_bunny( $bunny_zone, $bunny_key );
				if ( ! $bunny_ok ) {
					$ok = false;
				}
			}

			return $ok;
		}

		/**
		 * Resolve a single-page purge target to an absolute URL.
		 *
		 * Accepts an absolute URL or a path; paths are resolved against the
		 * home URL. Returns '' when unresolvable.
		 *
		 * @since NEXT
		 * @param string|null $url_path Page path or absolute URL.
		 * @return string Absolute URL or ''.
		 */
		private static function resolve_page_url( $url_path ): string {
			try {
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
		 * Purge specific Cloudflare files (URL-scoped single-page purge).
		 *
		 * @since NEXT
		 * @param string $zone Zone ID.
		 * @param string $token API token.
		 * @param string $url Absolute URL to purge.
		 * @return bool
		 */
		private static function purge_cloudflare_files( string $zone, string $token, string $url ): bool {
			$response = wp_remote_request(
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => (string) wp_json_encode( array( 'files' => array( $url ) ) ),
					'timeout' => 10,
				)
			);
			if ( is_wp_error( $response ) ) {
				self::log_failure( 'cloudflare-edge', $zone . ': ' . $response->get_error_message() );
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				self::log_failure( 'cloudflare-edge', $zone . ' (HTTP ' . $code . ')' );
				return false;
			}
			return true;
		}

		/**
		 * Purge Cloudflare zone cache (purge_everything).
		 *
		 * @since NEXT
		 * @param string $zone Zone ID.
		 * @param string $token API token.
		 * @return bool
		 */
		private static function purge_cloudflare( string $zone, string $token ): bool {
			$response = wp_remote_request(
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
				array(
					'method'  => 'POST',
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => (string) wp_json_encode( array( 'purge_everything' => true ) ),
					'timeout' => 10,
				)
			);
			if ( is_wp_error( $response ) ) {
				self::log_failure( 'cloudflare-edge', $zone . ': ' . $response->get_error_message() );
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				self::log_failure( 'cloudflare-edge', $zone . ' (HTTP ' . $code . ')' );
				return false;
			}
			return true;
		}

		/**
		 * Purge Bunny pull zone cache.
		 *
		 * API: POST https://api.bunny.net/pullzone/{id}/purgeCache
		 *
		 * @since NEXT
		 * @param string $zone Pull zone ID.
		 * @param string $api_key API key.
		 * @return bool
		 */
		private static function purge_bunny( string $zone, string $api_key ): bool {
			$response = wp_remote_request(
				'https://api.bunny.net/pullzone/' . rawurlencode( $zone ) . '/purgeCache',
				array(
					'method'  => 'POST',
					'headers' => array(
						'AccessKey'    => $api_key,
						'Content-Type' => 'application/json',
					),
					'timeout' => 10,
				)
			);
			if ( is_wp_error( $response ) ) {
				self::log_failure( 'bunny-edge', $zone . ': ' . $response->get_error_message() );
				return false;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				self::log_failure( 'bunny-edge', $zone . ' (HTTP ' . $code . ')' );
				return false;
			}
			return true;
		}

		/**
		 * Surface failure via debug log.
		 *
		 * @since NEXT
		 * @param string $service Service.
		 * @param string $detail Detail.
		 * @return void
		 */
		private static function log_failure( string $service, string $detail ): void {
			do_action( 'wppo_debug_log', 'Edge purge failed [' . $service . ']: ' . $detail );
		}
	}
}
