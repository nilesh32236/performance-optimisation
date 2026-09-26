<?php
/**
 * Bounded transport policy for Cron cache warmup requests.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Preload_Transport' ) ) {
	/**
	 * Validates and fetches same-host preload targets without implicit redirects.
	 *
	 * @since NEXT
	 */
	class Preload_Transport {
		/**
		 * Maximum redirects accepted for one preload request.
		 *
		 * @var int
		 */
		public const MAX_REDIRECTS = 5;

		/**
		 * Fetch a validated same-host target with explicit redirect handling.
		 *
		 * @param string $url          Target URL.
		 * @param int    $timeout      Request timeout in seconds.
		 * @param int    $max_redirects Maximum redirects allowed.
		 * @return array|\WP_Error Final response or a fail-closed error.
		 */
		public static function get( string $url, int $timeout = 5, int $max_redirects = 3 ) {
			$current       = esc_url_raw( trim( $url ) );
			$max_redirects = max( 0, min( self::MAX_REDIRECTS, $max_redirects ) );
			$redirects     = 0;
			$timeout       = max( 1, $timeout );

			while ( true ) {
				if ( ! self::is_allowed_url( $current ) ) {
					return new \WP_Error( 'preload_invalid_url', __( 'The preload URL is not allowed.', 'performance-optimisation' ) );
				}

				$response = wp_remote_get(
					$current,
					array(
						'timeout'     => $timeout,
						'redirection' => 0,
					)
				);
				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$code = (int) wp_remote_retrieve_response_code( $response );
				if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
					return $response;
				}
				if ( $redirects >= $max_redirects ) {
					return new \WP_Error( 'preload_redirect_limit', __( 'The preload URL redirected too many times.', 'performance-optimisation' ) );
				}

				$location = wp_remote_retrieve_header( $response, 'location' );
				if ( ! is_string( $location ) || '' === trim( $location ) ) {
					return new \WP_Error( 'preload_redirect_missing', __( 'The preload redirect did not provide a location.', 'performance-optimisation' ) );
				}

				$current = self::resolve_redirect( $current, trim( $location ) );
				if ( ! self::is_allowed_url( $current ) ) {
					return new \WP_Error( 'preload_redirect_unsafe', __( 'The preload redirect target is not allowed.', 'performance-optimisation' ) );
				}
				++$redirects;
			}
		}

		/**
		 * Check the initial URL and redirect target policy.
		 *
		 * @param string $url Candidate URL.
		 * @return bool Whether the target is safe for same-host preload transport.
		 */
		public static function is_allowed_url( string $url ): bool {
			$url = esc_url_raw( trim( $url ) );
			if ( '' === $url ) {
				return false;
			}

			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return false;
			}
			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return false;
			}
			if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
				return false;
			}

			$home      = home_url( '/' );
			$home_part = wp_parse_url( $home );
			$home_host = strtolower( (string) ( is_array( $home_part ) ? ( $home_part['host'] ?? '' ) : '' ) );
			$url_host  = strtolower( (string) $parts['host'] );
			if ( '' === $home_host || $url_host !== $home_host ) {
				return false;
			}

			$port = isset( $parts['port'] ) ? (int) $parts['port'] : null;
			if ( null !== $port ) {
				$default_port = 'https' === $scheme ? 443 : 80;
				if ( $port !== $default_port ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Resolve a relative or absolute redirect location against the current URL.
		 *
		 * @param string $base     Current request URL.
		 * @param string $location Redirect location header.
		 * @return string Absolute candidate URL.
		 */
		private static function resolve_redirect( string $base, string $location ): string {
			if ( preg_match( '#^https?://#i', $location ) ) {
				return $location;
			}

			$parts = wp_parse_url( $base );
			if ( ! is_array( $parts ) || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
				return '';
			}
			$scheme = strtolower( (string) $parts['scheme'] );
			$origin = $scheme . '://' . $parts['host'];
			if ( isset( $parts['port'] ) ) {
				$default_port = 'https' === $scheme ? 443 : 80;
				if ( (int) $parts['port'] !== $default_port ) {
					$origin .= ':' . (int) $parts['port'];
				}
			}

			if ( 0 === strpos( $location, '//' ) ) {
				return $scheme . ':' . $location;
			}

			$fragment = '';
			$hash_pos = strpos( $location, '#' );
			if ( false !== $hash_pos ) {
				$fragment = substr( $location, $hash_pos );
				$location = substr( $location, 0, $hash_pos );
			}
			$query     = '';
			$query_pos = strpos( $location, '?' );
			if ( false !== $query_pos ) {
				$query    = substr( $location, $query_pos );
				$location = substr( $location, 0, $query_pos );
			}

			$path = '/' . ltrim( $location, '/' );
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}
			return $origin . $path . $query . $fragment;
		}
	}
}
