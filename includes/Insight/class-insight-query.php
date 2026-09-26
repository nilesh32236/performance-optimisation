<?php
/**
 * Insight query read model for cached telemetry and PageSpeed results.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Insight_Query' ) ) {
	/**
	 * Read-only adapter for the plugin's stored insight results.
	 *
	 * Telemetry, PageSpeed, RUM, and AI retain their domain ownership. This
	 * class only delegates domain reads and adds the existing PageSpeed
	 * suggestion projection; it does not execute scans or mutate stored data.
	 *
	 * @since NEXT
	 */
	final class Insight_Query {

		/**
		 * Read a cached telemetry result for a URL.
		 *
		 * @since NEXT
		 * @param string $url Scanned URL.
		 * @return array|false Cached telemetry payload, or false when absent.
		 */
		public static function get_telemetry( string $url ) {
			return Telemetry::get_cached_result( $url );
		}

		/**
		 * Read the raw PageSpeed result for a URL and strategy.
		 *
		 * The strategy allowlist and transient fallback remain owned by
		 * Pagespeed so CLI and Abilities callers keep their exact raw shape.
		 *
		 * @since NEXT
		 * @param string $url Scanned URL.
		 * @param string $strategy Device strategy.
		 * @return array|false Prepared PageSpeed result, or false when absent.
		 */
		public static function get_pagespeed( string $url, string $strategy = 'mobile' ) {
			return Pagespeed::get_results( $url, $strategy );
		}

		/**
		 * Read a PageSpeed result with deterministic suggestion augmentation.
		 *
		 * Failure sentinels remain unchanged so the REST adapter owns the
		 * existing public failure message and HTTP status.
		 *
		 * @since NEXT
		 * @param string $url Scanned URL.
		 * @param string $strategy Device strategy.
		 * @return array|false Augmented PageSpeed result, or false when absent.
		 */
		public static function get_pagespeed_report( string $url, string $strategy = 'mobile' ) {
			$result = self::get_pagespeed( $url, $strategy );
			if ( ! is_array( $result ) || ! empty( $result['error'] ) ) {
				return $result;
			}

			$result['suggestions'] = Suggestion_Engine::from_pagespeed( $result );
			return $result;
		}
	}
}
