<?php
/**
 * Edge/CDN cache purge coordinator.
 *
 * Fans one successful page-cache clear out to the legacy CDN and edge adapters
 * while suppressing an identical Cloudflare full-zone transport when both
 * adapters resolve the same zone during the same event.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Edge_Purge_Coordinator' ) ) {

	/**
	 * Coordinates legacy CDN and edge-cache purges for one cache-clear event.
	 *
	 * Provider behaviour remains owned by CDN_Purger, Edge_Purger, and
	 * Cloudflare_Purger. This adapter only scopes an in-request transport
	 * de-duplication seam around the two existing calls.
	 *
	 * @since NEXT
	 */
	final class Edge_Purge_Coordinator {

		/**
		 * Route the shared post-clear hook through both existing purgers.
		 *
		 * @param mixed $type     Clear type ('all' or 'single_page').
		 * @param mixed $url_path Page path for a single-page clear.
		 * @return bool True when every available provider path succeeded or no-opped.
		 *
		 * @since NEXT
		 */
		public static function purge_after_cache_clear( $type = 'all', $url_path = null ): bool {
			$seen_cloudflare    = array();
			$pending_cloudflare = array();
			$deduplicator       = static function ( $pre_response, $args, $url ) use ( &$seen_cloudflare, &$pending_cloudflare ) {
				return self::deduplicate_cloudflare_request( $pre_response, $args, $url, $seen_cloudflare, $pending_cloudflare );
			};
			$response_recorder  = static function ( $response, $context, $transport_class, $args, $url ) use ( &$seen_cloudflare, &$pending_cloudflare ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Callback signature must match http_api_debug.
				self::remember_cloudflare_response( $response, $args, $url, $seen_cloudflare, $pending_cloudflare );
			};

			// pre_http_request is the last WordPress HTTP-API seam before the
			// transport. A late callback leaves earlier short-circuit filters in
			// control. http_api_debug records the first transport response so an
			// identical legacy/edge request can reuse it without a second call.
			add_filter( 'pre_http_request', $deduplicator, PHP_INT_MAX, 3 );
			add_action( 'http_api_debug', $response_recorder, PHP_INT_MAX, 5 );

			try {
				$cdn_ok  = class_exists( CDN_Purger::class ) ? CDN_Purger::purge_all( $type, $url_path ) : true;
				$edge_ok = class_exists( Edge_Purger::class ) ? Edge_Purger::purge_all( $type, $url_path ) : true;
			} finally {
				remove_filter( 'pre_http_request', $deduplicator, PHP_INT_MAX );
				remove_action( 'http_api_debug', $response_recorder, PHP_INT_MAX );
			}

			return $cdn_ok && $edge_ok;
		}

		/**
		 * Reuse the first response for an identical Cloudflare full-zone purge.
		 *
		 * @param mixed               $pre_response Response from an earlier pre_http_request listener.
		 * @param mixed               $args         WordPress HTTP request arguments.
		 * @param mixed               $url          WordPress HTTP request URL.
		 * @param array<string,mixed> $seen         Responses already seen during this coordinator event.
		 * @param array<string,bool>  $pending      Requests currently awaiting an http_api_debug response.
		 * @return mixed Unchanged earlier response, or the cached response for a duplicate.
		 *
		 * @since NEXT
		 */
		private static function deduplicate_cloudflare_request( $pre_response, $args, $url, array &$seen, array &$pending ) {
			if ( false !== $pre_response ) {
				return $pre_response;
			}

			$key = self::cloudflare_request_key( $args, $url );
			if ( '' === $key ) {
				return $pre_response;
			}
			if ( array_key_exists( $key, $seen ) ) {
				return $seen[ $key ];
			}

			$pending[ $key ] = true;
			return $pre_response;
		}

		/**
		 * Record a completed transport response for later duplicate suppression.
		 *
		 * @param mixed               $response HTTP API response.
		 * @param mixed               $args     WordPress HTTP request arguments.
		 * @param mixed               $url      WordPress HTTP request URL.
		 * @param array<string,mixed> $seen     Responses already seen during this coordinator event.
		 * @param array<string,bool>  $pending  Requests currently awaiting an http_api_debug response.
		 * @return void
		 *
		 * @since NEXT
		 */
		private static function remember_cloudflare_response( $response, $args, $url, array &$seen, array &$pending ): void {
			$key = self::cloudflare_request_key( $args, $url );
			if ( '' === $key || ! isset( $pending[ $key ] ) ) {
				return;
			}

			$seen[ $key ] = $response;
			unset( $pending[ $key ] );
		}

		/**
		 * Build a stable key for one Cloudflare full-zone purge transport.
		 *
		 * @param mixed $args WordPress HTTP request arguments.
		 * @param mixed $url  WordPress HTTP request URL.
		 * @return string Stable key, or '' for any other request.
		 *
		 * @since NEXT
		 */
		private static function cloudflare_request_key( $args, $url ): string {
			if ( ! is_array( $args ) || ! is_string( $url ) ) {
				return '';
			}

			$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
			$body   = isset( $args['body'] ) && is_string( $args['body'] ) ? $args['body'] : '';
			if ( 'POST' !== $method || '{"purge_everything":true}' !== $body ) {
				return '';
			}
			if ( 1 !== preg_match( '#^https://api\.cloudflare\.com/client/v4/zones/[^/]+/purge_cache$#', $url ) ) {
				return '';
			}

			$authorization = '';
			if ( isset( $args['headers']['Authorization'] ) ) {
				$authorization = (string) $args['headers']['Authorization'];
			}
			return hash( 'sha256', $method . "\n" . $url . "\n" . $body . "\n" . $authorization );
		}
	}
}
