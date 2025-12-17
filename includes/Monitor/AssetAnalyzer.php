<?php
/**
 * Asset Analyzer Class
 *
 * Analyzes page assets (CSS, JS, Media) using cURL.
 *
 * @package PerformanceOptimisation\Monitor
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Monitor;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AssetAnalyzer
 */
class AssetAnalyzer {

	/**
	 * Cache transient key prefix.
	 *
	 * @var string
	 */
	private const CACHE_PREFIX = 'wppo_assets_';

	/**
	 * Cache duration in seconds (30 minutes).
	 *
	 * @var int
	 */
	private const CACHE_DURATION = 1800;

	/**
	 * Analyze assets for a given URL.
	 *
	 * @param string $url       URL to analyze.
	 * @param bool   $use_cache Whether to use cached data.
	 *
	 * @return array Asset analysis data.
	 */
	public function analyze( string $url, bool $use_cache = true ): array {
		$cache_key = self::CACHE_PREFIX . md5( $url );

		if ( $use_cache ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$html = $this->fetch_page( $url );

		if ( is_wp_error( $html ) ) {
			return array(
				'success' => false,
				'error'   => $html->get_error_message(),
			);
		}

		$result = array(
			'success'   => true,
			'url'       => $url,
			'timestamp' => current_time( 'mysql' ),
			'css'       => $this->extract_css( $html, $url ),
			'js'        => $this->extract_js( $html, $url ),
			'images'    => $this->extract_images( $html, $url ),
			'summary'   => array(),
		);

		$result['summary'] = array(
			'css_count'        => count( $result['css'] ),
			'css_total_size'   => array_sum( array_column( $result['css'], 'size' ) ),
			'js_count'         => count( $result['js'] ),
			'js_total_size'    => array_sum( array_column( $result['js'], 'size' ) ),
			'image_count'      => count( $result['images'] ),
			'image_total_size' => array_sum( array_column( $result['images'], 'size' ) ),
		);

		$result['summary']['total_assets'] = $result['summary']['css_count'] + $result['summary']['js_count'] + $result['summary']['image_count'];
		$result['summary']['total_size']   = $result['summary']['css_total_size'] + $result['summary']['js_total_size'] + $result['summary']['image_total_size'];

		set_transient( $cache_key, $result, self::CACHE_DURATION );

		return $result;
	}

	/**
	 * Fetch page HTML content.
	 *
	 * @param string $url URL to fetch.
	 *
	 * @return string|\WP_Error HTML content or error.
	 */
	private function fetch_page( string $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'    => 30,
				'user-agent' => 'WordPress/Performance-Optimisation-Plugin',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Extract CSS files from HTML.
	 *
	 * @param string $html    Page HTML.
	 * @param string $base_url Base URL for relative paths.
	 *
	 * @return array CSS file data.
	 */
	private function extract_css( string $html, string $base_url ): array {
		$css_files = array();

		preg_match_all( '/<link[^>]+rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i', $html, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $href ) {
				$full_url = $this->resolve_url( $href, $base_url );
				$size     = $this->get_remote_file_size( $full_url );

				$css_files[] = array(
					'url'  => $full_url,
					'size' => $size,
					'file' => basename( wp_parse_url( $full_url, PHP_URL_PATH ) ),
				);
			}
		}

		return $css_files;
	}

	/**
	 * Extract JS files from HTML.
	 *
	 * @param string $html    Page HTML.
	 * @param string $base_url Base URL for relative paths.
	 *
	 * @return array JS file data.
	 */
	private function extract_js( string $html, string $base_url ): array {
		$js_files = array();

		preg_match_all( '/<script[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $src ) {
				$full_url = $this->resolve_url( $src, $base_url );
				$size     = $this->get_remote_file_size( $full_url );

				$js_files[] = array(
					'url'  => $full_url,
					'size' => $size,
					'file' => basename( wp_parse_url( $full_url, PHP_URL_PATH ) ),
				);
			}
		}

		return $js_files;
	}

	/**
	 * Extract images from HTML.
	 *
	 * @param string $html    Page HTML.
	 * @param string $base_url Base URL for relative paths.
	 *
	 * @return array Image data.
	 */
	private function extract_images( string $html, string $base_url ): array {
		$images = array();

		preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\'][^>]*>/i', $html, $matches );

		if ( ! empty( $matches[1] ) ) {
			foreach ( array_slice( $matches[1], 0, 20 ) as $src ) { // Limit to 20 images
				$full_url = $this->resolve_url( $src, $base_url );
				$size     = $this->get_remote_file_size( $full_url );

				$images[] = array(
					'url'  => $full_url,
					'size' => $size,
					'file' => basename( wp_parse_url( $full_url, PHP_URL_PATH ) ),
				);
			}
		}

		return $images;
	}

	/**
	 * Resolve relative URL to absolute.
	 *
	 * @param string $url      URL (may be relative).
	 * @param string $base_url Base URL.
	 *
	 * @return string Absolute URL.
	 */
	private function resolve_url( string $url, string $base_url ): string {
		if ( strpos( $url, 'http' ) === 0 ) {
			return $url;
		}

		if ( strpos( $url, '//' ) === 0 ) {
			return 'https:' . $url;
		}

		$base_parts = wp_parse_url( $base_url );
		$base       = $base_parts['scheme'] . '://' . $base_parts['host'];

		if ( strpos( $url, '/' ) === 0 ) {
			return $base . $url;
		}

		return $base . '/' . $url;
	}

	/**
	 * Get remote file size using HEAD request.
	 *
	 * @param string $url File URL.
	 *
	 * @return int File size in bytes.
	 */
	private function get_remote_file_size( string $url ): int {
		$response = wp_remote_head( $url, array( 'timeout' => 5 ) );

		if ( is_wp_error( $response ) ) {
			return 0;
		}

		$headers = wp_remote_retrieve_headers( $response );

		return isset( $headers['content-length'] ) ? (int) $headers['content-length'] : 0;
	}

	/**
	 * Clear cached asset data for a URL.
	 *
	 * @param string $url URL to clear cache for.
	 */
	public function clear_cache( string $url ): void {
		delete_transient( self::CACHE_PREFIX . md5( $url ) );
	}
}
