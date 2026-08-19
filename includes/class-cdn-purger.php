<?php
/**
 * CDN cache purger (Cloudflare / Varnish).
 *
 * @package PerformanceOptimise
 */

namespace PerformanceOptimise\Inc;

if ( ! class_exists( 'PerformanceOptimise\Inc\CDN_Purger' ) ) {

	/**
	 * Purges third-party caches after the plugin's own cache is cleared.
	 *
	 * Cloudflare uses a Bearer token which is read from the
	 * WPPO_CLOUDFLARE_API_TOKEN constant (never stored in the database, mirroring
	 * the Redis password handling). Varnish is purged by sending PURGE requests
	 * to the configured server URLs.
	 *
	 * @since NEXT
	 */
	class CDN_Purger {

		/**
		 * Name of the constant holding the Cloudflare API token.
		 *
		 * @var string
		 */
		const TOKEN_CONSTANT = 'WPPO_CLOUDFLARE_API_TOKEN';

		/**
		 * Purge the configured third-party cache.
		 *
		 * Hooks into wppo_after_cache_clear so a full cache clear also empties
		 * the CDN/edge cache.
		 *
		 * @return bool True when no purge was needed or all requests succeeded.
		 */
		public static function purge_all(): bool {
			$options = get_option( 'wppo_settings', array() );
			$cache   = isset( $options['cache_settings'] ) && is_array( $options['cache_settings'] ) ? $options['cache_settings'] : array();

			$service = isset( $cache['cdnPurgeService'] ) ? sanitize_text_field( (string) $cache['cdnPurgeService'] ) : 'none';

			if ( 'cloudflare' === $service ) {
				return self::purge_cloudflare( $cache );
			}
			if ( 'varnish' === $service ) {
				return self::purge_varnish( $cache );
			}

			return true;
		}

		/**
		 * Whether the configured purge service has everything it needs.
		 *
		 * @return bool
		 */
		public static function is_configured(): bool {
			$options = get_option( 'wppo_settings', array() );
			$cache   = isset( $options['cache_settings'] ) && is_array( $options['cache_settings'] ) ? $options['cache_settings'] : array();
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
		 * @param array $cache cache_settings values.
		 * @return bool
		 */
		private static function purge_cloudflare( array $cache ): bool {
			$zone  = isset( $cache['cloudflareZoneId'] ) ? sanitize_text_field( (string) $cache['cloudflareZoneId'] ) : '';
			$token = defined( self::TOKEN_CONSTANT ) ? (string) constant( self::TOKEN_CONSTANT ) : '';

			if ( '' === $zone || '' === $token ) {
				return false;
			}

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
				self::log_failure( 'cloudflare', $zone . ': ' . $response->get_error_message() );
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				self::log_failure( 'cloudflare', $zone . ' (HTTP ' . $code . ')' );
				return false;
			}
			return true;
		}

		/**
		 * Send PURGE requests to the configured Varnish endpoints.
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
			do_action( 'wppo_debug_log', 'CDN purge failed [' . $service . ']: ' . $detail );
		}
	}
}
