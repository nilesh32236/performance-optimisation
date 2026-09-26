<?php
/**
 * Cloudflare purge transport — shared helper for CDN_Purger and Edge_Purger.
 *
 * Extracts the ~40-line wp_remote_request transport duplication (D-19).
 * Both purgers previously contained near-identical purge_cloudflare bodies:
 * rawurlencode(zone) + Bearer token + purge_everything:true + wp_remote_request
 * with identical error + status-code checks and wppo_debug_log calls.
 *
 * This class owns the HTTP call. Callers resolve zone/token from their own
 * settings slices and provide a log tag so existing debug-log filters keep
 * working (e.g. 'cloudflare' vs 'cloudflare-edge').
 *
 * @package PerformanceOptimise\Inc
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cloudflare_Purger' ) ) {
	/**
	 * Cloudflare purge transport.
	 *
	 * Stateless helper: given a zone ID and Bearer token, issues a purge_everything
	 * request to Cloudflare and surfaces failures via the wppo_debug_log action
	 * (mirroring CDN_Purger / Edge_Purger log behaviour).
	 *
	 * @since 2.0.0
	 */
	class Cloudflare_Purger {

		/**
		 * Cloudflare purge timeout (seconds).
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private const TIMEOUT = 10;

		/**
		 * Purge everything on Cloudflare for the given zone.
		 *
		 * Behaviourally equivalent to the two inlined purge_cloudflare methods it
		 * replaces. Keeps the same URL shape, Bearer header, JSON body, timeout,
		 * is_wp_error branch and 2xx status check.
		 *
		 * @since 2.0.0
		 * @param string $zone        Cloudflare zone ID (already sanitized).
		 * @param string $token       Cloudflare API token (Bearer).
		 * @param string $log_tag     Provider tag for wppo_debug_log ('cloudflare' or 'cloudflare-edge').
		 * @param string $fail_prefix Log message prefix (callers preserve their historic prefix).
		 * @return bool True on 2xx, false on WP_Error or non-2xx or empty args.
		 */
		public static function purge( string $zone, string $token, string $log_tag = 'cloudflare', string $fail_prefix = 'CDN purge failed' ): bool {
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
					'timeout' => self::TIMEOUT,
				)
			);

			if ( is_wp_error( $response ) ) {
				self::log_failure( $log_tag, $zone . ': ' . $response->get_error_message(), $fail_prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug log, not HTML output.
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				self::log_failure( $log_tag, $zone . ' (HTTP ' . $code . ')', $fail_prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug log, not HTML output.
				return false;
			}
			return true;
		}

		/**
		 * Purge specific files on Cloudflare for the given zone.
		 *
		 * Behaviourally equivalent to the inlined Edge_Purger::purge_cloudflare_files()
		 * method it replaces. Keeps the same URL shape, Bearer header, JSON body
		 * shape ({files: [...]}), timeout, is_wp_error branch and 2xx status check.
		 *
		 * @since 2.2.0
		 * @param string $zone        Cloudflare zone ID (already sanitized).
		 * @param string $token       Cloudflare API token (Bearer).
		 * @param array  $urls        Absolute URLs to purge.
		 * @param string $log_tag     Provider tag for wppo_debug_log ('cloudflare' or 'cloudflare-edge').
		 * @param string $fail_prefix Log message prefix (callers preserve their historic prefix).
		 * @return bool True on 2xx, false on WP_Error or non-2xx or empty args.
		 */
		public static function purge_files( string $zone, string $token, array $urls, string $log_tag = 'cloudflare', string $fail_prefix = 'CDN purge failed' ): bool {
			if ( '' === $zone || '' === $token || empty( $urls ) ) {
				return false;
			}

			$files = array_values(
				array_filter(
					$urls,
					static function ( $url ): bool {
						return is_string( $url ) && '' !== $url;
					}
				)
			);
			if ( empty( $files ) ) {
				return false;
			}

			$body = wp_json_encode( array( 'files' => $files ) );
			if ( false === $body ) {
				self::log_failure( $log_tag, $zone . ': JSON encoding failed', $fail_prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug log, not HTML output.
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
					'body'    => $body,
					'timeout' => self::TIMEOUT,
				)
			);

			if ( is_wp_error( $response ) ) {
				self::log_failure( $log_tag, $zone . ': ' . $response->get_error_message(), $fail_prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug log, not HTML output.
				return false;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( $code < 200 || $code >= 300 ) {
				self::log_failure( $log_tag, $zone . ' (HTTP ' . $code . ')', $fail_prefix ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Debug log, not HTML output.
				return false;
			}
			return true;
		}

		/**
		 * Surface a failed purge via wppo_debug_log.
		 *
		 * Keeps the historic 'CDN purge failed [...]' prefix by default so existing
		 * debug-log filters, tests and runbooks matching that prefix keep
		 * working; the $service tag ('cloudflare' vs 'cloudflare-edge')
		 * still identifies the caller. Edge callers pass their own prefix
		 * ('Edge purge failed') so edge filters keep matching.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Added $fail_prefix parameter.
		 * @param string $service     Log tag (e.g. cloudflare, cloudflare-edge).
		 * @param string $detail      Endpoint / reason.
		 * @param string $fail_prefix Log message prefix.
		 * @return void
		 */
		private static function log_failure( string $service, string $detail, string $fail_prefix = 'CDN purge failed' ): void {
			do_action( 'wppo_debug_log', $fail_prefix . ' [' . $service . ']: ' . $detail );
		}
	}
}
