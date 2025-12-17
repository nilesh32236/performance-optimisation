<?php
/**
 * PageSpeed Service Class
 *
 * Integrates with Google PageSpeed Insights API to fetch Lighthouse scores.
 *
 * @package PerformanceOptimisation\Monitor
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PageSpeedService
 */
class PageSpeedService {

	/**
	 * Google PageSpeed API URL.
	 *
	 * @var string
	 */
	private const API_URL = 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed';

	/**
	 * Cache transient key prefix.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'wppo_pagespeed_';

	/**
	 * Cache duration in seconds (1 hour).
	 *
	 * @var int
	 */
	private const CACHE_DURATION = 3600;

	/**
	 * Get PageSpeed data for a URL.
	 *
	 * @param string $url       URL to analyze.
	 * @param string $strategy  'mobile' or 'desktop'.
	 * @param bool   $use_cache Whether to use cached data.
	 *
	 * @return array PageSpeed data or error.
	 */
	public function get_pagespeed_data( string $url, string $strategy = 'mobile', bool $use_cache = true ): array {
		$cache_key = self::CACHE_PREFIX . md5( $url . $strategy );

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$api_key = $this->get_api_key();

		$api_url = add_query_arg(
			array(
				'url'      => rawurlencode( $url ),
				'strategy' => $strategy,
				'category' => array( 'performance', 'accessibility', 'best-practices', 'seo' ),
			),
			self::API_URL
		);

		if ( ! empty( $api_key ) ) {
			$api_url = add_query_arg( 'key', $api_key, $api_url );
		}

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'error'   => $response->get_error_message(),
			);
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return array(
				'success' => false,
				'error'   => 'Invalid JSON response from PageSpeed API',
			);
		}

		if ( isset( $data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => $data['error']['message'] ?? 'Unknown API error',
			);
		}

		$result              = $this->parse_pagespeed_response( $data );
		$result['success']   = true;
		$result['strategy']  = $strategy;
		$result['url']       = $url;
		$result['timestamp'] = current_time( 'mysql' );

		set_transient( $cache_key, $result, self::CACHE_DURATION );

		return $result;
	}

	/**
	 * Get PageSpeed data for both mobile and desktop.
	 *
	 * @param string $url       URL to analyze.
	 * @param bool   $use_cache Whether to use cached data.
	 *
	 * @return array Combined data for both strategies.
	 */
	public function get_full_pagespeed_data( string $url, bool $use_cache = true ): array {
		return array(
			'mobile'  => $this->get_pagespeed_data( $url, 'mobile', $use_cache ),
			'desktop' => $this->get_pagespeed_data( $url, 'desktop', $use_cache ),
		);
	}

	/**
	 * Parse PageSpeed API response into structured data.
	 *
	 * @param array $data Raw API response.
	 *
	 * @return array Parsed data.
	 */
	private function parse_pagespeed_response( array $data ): array {
		$lighthouse = $data['lighthouseResult'] ?? array();
		$categories = $lighthouse['categories'] ?? array();
		$audits     = $lighthouse['audits'] ?? array();

		return array(
			'scores'      => array(
				'performance'    => $this->get_score( $categories, 'performance' ),
				'accessibility'  => $this->get_score( $categories, 'accessibility' ),
				'best_practices' => $this->get_score( $categories, 'best-practices' ),
				'seo'            => $this->get_score( $categories, 'seo' ),
			),
			'metrics'     => array(
				'fcp' => array(
					'value'   => $audits['first-contentful-paint']['numericValue'] ?? 0,
					'display' => $audits['first-contentful-paint']['displayValue'] ?? 'N/A',
					'score'   => ( $audits['first-contentful-paint']['score'] ?? 0 ) * 100,
				),
				'lcp' => array(
					'value'   => $audits['largest-contentful-paint']['numericValue'] ?? 0,
					'display' => $audits['largest-contentful-paint']['displayValue'] ?? 'N/A',
					'score'   => ( $audits['largest-contentful-paint']['score'] ?? 0 ) * 100,
				),
				'cls' => array(
					'value'   => $audits['cumulative-layout-shift']['numericValue'] ?? 0,
					'display' => $audits['cumulative-layout-shift']['displayValue'] ?? 'N/A',
					'score'   => ( $audits['cumulative-layout-shift']['score'] ?? 0 ) * 100,
				),
				'tbt' => array(
					'value'   => $audits['total-blocking-time']['numericValue'] ?? 0,
					'display' => $audits['total-blocking-time']['displayValue'] ?? 'N/A',
					'score'   => ( $audits['total-blocking-time']['score'] ?? 0 ) * 100,
				),
				'si'  => array(
					'value'   => $audits['speed-index']['numericValue'] ?? 0,
					'display' => $audits['speed-index']['displayValue'] ?? 'N/A',
					'score'   => ( $audits['speed-index']['score'] ?? 0 ) * 100,
				),
			),
			'diagnostics' => $this->get_diagnostics( $audits ),
		);
	}

	/**
	 * Extract score from category data.
	 *
	 * @param array  $categories Category data.
	 * @param string $key        Category key.
	 *
	 * @return int Score (0-100).
	 */
	private function get_score( array $categories, string $key ): int {
		return isset( $categories[ $key ]['score'] )
			? (int) round( $categories[ $key ]['score'] * 100 )
			: 0;
	}

	/**
	 * Get diagnostic recommendations.
	 *
	 * @param array $audits Audit data.
	 *
	 * @return array Diagnostics.
	 */
	private function get_diagnostics( array $audits ): array {
		$diagnostics     = array();
		$diagnostic_keys = array(
			'render-blocking-resources',
			'unused-css-rules',
			'unused-javascript',
			'modern-image-formats',
			'uses-optimized-images',
			'uses-text-compression',
			'uses-responsive-images',
			'efficient-animated-content',
			'third-party-summary',
		);

		foreach ( $diagnostic_keys as $key ) {
			if ( isset( $audits[ $key ] ) && isset( $audits[ $key ]['score'] ) && $audits[ $key ]['score'] < 1 ) {
				$diagnostics[] = array(
					'id'          => $key,
					'title'       => $audits[ $key ]['title'] ?? $key,
					'description' => $audits[ $key ]['description'] ?? '',
					'score'       => (int) round( ( $audits[ $key ]['score'] ?? 0 ) * 100 ),
					'display'     => $audits[ $key ]['displayValue'] ?? '',
				);
			}
		}

		return $diagnostics;
	}

	/**
	 * Get API key from settings.
	 *
	 * @return string API key or empty string.
	 */
	private function get_api_key(): string {
		$settings = get_option( 'wppo_settings', array() );
		return $settings['pagespeed_api_key'] ?? '';
	}

	/**
	 * Clear cached PageSpeed data for a URL.
	 *
	 * @param string $url URL to clear cache for.
	 */
	public function clear_cache( string $url ): void {
		delete_transient( self::CACHE_PREFIX . md5( $url . 'mobile' ) );
		delete_transient( self::CACHE_PREFIX . md5( $url . 'desktop' ) );
	}
}
