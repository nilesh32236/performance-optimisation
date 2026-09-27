<?php
/**
 * Administrative authentication policy for REST and Abilities.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Admin_Auth' ) ) {

	/**
	 * Owns the administrative capability and REST nonce contract.
	 *
	 * This dependency-light policy is shared by REST and Abilities without
	 * changing either adapter's callback signature or public RUM collection.
	 *
	 * @since NEXT
	 */
	final class Admin_Auth {

		/**
		 * Check administrative authentication for a REST-style request.
		 *
		 * A supplied WP_REST_Request is authoritative. Null-request callers
		 * retain the legacy HTTP_X_WP_NONCE server fallback.
		 *
		 * @since NEXT
		 * @param \WP_REST_Request|null $request REST request, when available.
		 * @return bool True only for manage_options with a valid wp_rest nonce.
		 */
		public static function permission_check( ?\WP_REST_Request $request = null ): bool {
			// Fail closed when pluggable auth is unavailable (edge boots,
			// stripped installs, unit tests without pluggables).
			//
			// @since NEXT Fail-closed function_exists guard for current_user_can().
			if ( ! function_exists( 'current_user_can' ) || ! current_user_can( 'manage_options' ) ) {
				return false;
			}

			$nonce = '';
			if ( $request instanceof \WP_REST_Request ) {
				// WP canonicalizes the REST header name. Repeated headers may
				// arrive as an array, so use the first value rather than casting
				// the whole array and emitting a warning.
				$header = $request->get_header( 'X-WP-Nonce' );
				$nonce  = is_array( $header ) ? (string) reset( $header ) : (string) $header;
			}
			if ( null === $request && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
				// Legacy no-request callers receive the raw, possibly slashed
				// server value. wp_unslash() is intentionally limited to this path.
				// Fall back to the raw server value when wp_unslash() is
				// unavailable so auth never fatals; the nonce verify below
				// still fails closed.
				//
				// @since NEXT Fail-open-to-raw fallback for missing wp_unslash().
				if ( function_exists( 'wp_unslash' ) ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
					$nonce = (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
				} else {
					// Raw fallback when wp_unslash() is unavailable: sanitize +
					// verify below still fail closed, so this never fails open.
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Intentional raw fallback when wp_unslash() is undefined; still sanitized + verified below.
					$nonce = (string) $_SERVER['HTTP_X_WP_NONCE'];
				}
			}

			// Missing sanitize/verify helpers must deny, never fail open.
			//
			// @since NEXT Fail-closed function_exists guards for nonce verification.
			if ( ! function_exists( 'sanitize_text_field' ) || ! function_exists( 'wp_verify_nonce' ) ) {
				return false;
			}

			return (bool) wp_verify_nonce( sanitize_text_field( $nonce ), 'wp_rest' );
		}
	}
}
