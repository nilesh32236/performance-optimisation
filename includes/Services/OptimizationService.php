<?php
/**
 * Optimization Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Interfaces\OptimizationServiceInterface;
use PerformanceOptimisation\Optimizers\CssOptimizer;
use PerformanceOptimisation\Optimizers\JsOptimizer;
use PerformanceOptimisation\Optimizers\HtmlOptimizer;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use MatthiasMullie\Minify\CSS as MatthiasCssMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class OptimizationService
 *
 * @package PerformanceOptimisation\Services
 */
class OptimizationService implements OptimizationServiceInterface {

	private CssOptimizer $cssOptimizer;
	private JsOptimizer $jsOptimizer;
	private HtmlOptimizer $htmlOptimizer;
	private array $stats;

	public function __construct( CssOptimizer $cssOptimizer, JsOptimizer $jsOptimizer, HtmlOptimizer $htmlOptimizer ) {
		$this->cssOptimizer  = $cssOptimizer;
		$this->jsOptimizer   = $jsOptimizer;
		$this->htmlOptimizer = $htmlOptimizer;
		$this->stats         = array(
			'css'  => array(
				'files'      => 0,
				'size_saved' => 0,
			),
			'js'   => array(
				'files'      => 0,
				'size_saved' => 0,
			),
			'html' => array(
				'files'      => 0,
				'size_saved' => 0,
			),
		);
	}

	public function optimizeAssets( array $assets ): array {
		$optimized_assets = array();
		foreach ( $assets as $asset ) {
			if ( ! $this->shouldOptimize( $asset['type'], $asset['url'] ) ) {
				$optimized_assets[] = $asset;
				continue;
			}

			try {
				$content = FileSystemUtil::readFile( $asset['path'] );
				if ( ! $content ) {
					LoggingUtil::warning( "Failed to read asset file: {$asset['path']}" );
					$optimized_assets[] = $asset;
					continue;
				}

				// Validate file size
				if ( strlen( $content ) > 10485760 ) { // 10MB limit
					LoggingUtil::warning( "Asset file too large: {$asset['path']}" );
					$optimized_assets[] = $asset;
					continue;
				}
			} catch ( \Exception $e ) {
				LoggingUtil::error( "Asset optimization failed: {$e->getMessage()}" );
				$optimized_assets[] = $asset; // Return original on failure
				continue;
			}

			$original_size     = strlen( $content );
			$optimizer         = $this->getOptimizer( $asset['type'] );
			$optimized_content = $optimizer->optimize( $content, array( 'base_path' => $asset['path'] ) );
			$optimized_size    = strlen( $optimized_content );

			++$this->stats[ $asset['type'] ]['files'];
			$this->stats[ $asset['type'] ]['size_saved'] += ( $original_size - $optimized_size );

			$optimized_assets[] = array(
				'type'    => $asset['type'],
				'url'     => $this->storeOptimizedAsset( $asset['url'], $optimized_content ),
				'content' => $optimized_content,
			);
		}
		return $optimized_assets;
	}

	public function getOptimizationStats(): array {
		return $this->stats;
	}

	public function shouldOptimize( string $assetType, string $url ): bool {
		// Add logic to check against settings and exclusion lists
		return true;
	}

	public function combine_css(): string {
		global $wp_styles;

		if ( ! $wp_styles instanceof \WP_Styles ) {
			return '';
		}

		$paths     = array();
		$site_url  = site_url();
		$site_path = wp_normalize_path( ABSPATH );

		// Get all enqueued CSS files
		foreach ( $wp_styles->queue as $handle ) {
			if ( isset( $wp_styles->registered[ $handle ] ) ) {
				$style = $wp_styles->registered[ $handle ];
				if ( $style->src ) {
					$url = $style->src;
					// Convert relative URLs to absolute
					if ( strpos( $url, '//' ) === false ) {
						$url = $site_url . $url;
					}
					$path = str_replace( $site_url, $site_path, $url );
					if ( file_exists( $path ) ) {
						$paths[] = $path;
					}
				}
			}
		}

		if ( empty( $paths ) ) {
			return '';
		}

		try {
			$minifier         = new MatthiasCssMinifier( ...$paths );
			$combined_content = $minifier->minify();

			$filename  = md5( implode( '', $paths ) ) . '.css';
			$file_path = $this->get_cache_file_path_for_combined( $filename, 'css' );
			$this->save_cache_files( $combined_content, $file_path );
			return $this->get_cache_file_url_for_combined( $filename, 'css' );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'CSS Combination Error: ' . $e->getMessage() . ' Files: ' . implode( ', ', $paths ) );
			return '';
		}
	}

	public function generate_dynamic_static_html( string $url ): bool {
		$response = wp_remote_get( $url, array( 'timeout' => 30 ) );

		if ( is_wp_error( $response ) ) {
			LoggingUtil::error( 'Failed to fetch URL for static HTML generation: ' . $response->get_error_message() . ' URL: ' . $url );
			return false;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $response_code ) {
			LoggingUtil::warning(
				'Received non-200 response for static HTML generation. URL: ' . $url . ' Status Code: ' . $response_code
			);
			return false;
		}

		$html_content = wp_remote_retrieve_body( $response );
		if ( empty( $html_content ) ) {
			LoggingUtil::warning( 'Empty body received for static HTML generation. URL: ' . $url );
			return false;
		}

		$url_parts = wp_parse_url( $url );
		$host      = $url_parts['host'] ?? '';
		$path      = $url_parts['path'] ?? '/';

		// Secure path sanitization
		$path = $this->sanitizePath( $path );
		$host = $this->sanitizeHost( $host );

		$base_cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/html/' );
		$file_path      = $this->constructSecureFilePath( $base_cache_dir, $host, $path );

		if ( '/' === substr( $file_path, -1 ) || ! pathinfo( $file_path, PATHINFO_EXTENSION ) ) {
			$file_path = rtrim( $file_path, '/' ) . '/index.html';
		}

		try {
			if ( ! FileSystemUtil::createDirectory( dirname( $file_path ) ) ) {
				LoggingUtil::error( 'Could not create directory for static HTML file. Path: ' . dirname( $file_path ) );
				return false;
			}
			$this->save_cache_files( $html_content, $file_path );
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Exception while saving static HTML file: ' . $e->getMessage() . ' Path: ' . $file_path );
			return false;
		}

		return true;
	}

	private function getOptimizer( string $type ) {
		switch ( $type ) {
			case 'css':
				return $this->cssOptimizer;
			case 'js':
				return $this->jsOptimizer;
			case 'html':
				return $this->htmlOptimizer;
			default:
				throw new \InvalidArgumentException( "Invalid asset type: {$type}" );
		}
	}

	private function storeOptimizedAsset( string $original_url, string $content ): string {
		$filename  = md5( $original_url ) . '.' . pathinfo( $original_url, PATHINFO_EXTENSION );
		$type      = pathinfo( $original_url, PATHINFO_EXTENSION );
		$file_path = $this->get_cache_file_path_for_combined( $filename, $type );
		$this->save_cache_files( $content, $file_path );
		return $this->get_cache_file_url_for_combined( $filename, $type );
	}

	private function get_cache_file_path_for_combined( string $filename, string $type ): string {
		$min_dir = wp_normalize_path( trailingslashit( WP_CONTENT_DIR . '/cache/wppo/' ) . 'min/' . $type . '/' );
		return $min_dir . $filename;
	}

	private function get_cache_file_url_for_combined( string $filename, string $type ): string {
		$min_url_path = trailingslashit( content_url( '/cache/wppo/' ) ) . 'min/' . $type . '/';
		return $min_url_path . $filename;
	}

	private function save_cache_files( string $buffer, string $file_path ): void {
		FileSystemUtil::createDirectory( dirname( $file_path ) );
		FileSystemUtil::writeFile( $file_path, $buffer );

		if ( function_exists( 'gzencode' ) ) {
			$gzip_output = gzencode( $buffer, 9 );
			if ( false !== $gzip_output ) {
				FileSystemUtil::writeFile( $file_path . '.gz', $gzip_output );
			}
		}
	}

	/**
	 * Sanitize path to prevent directory traversal.
	 *
	 * @param string $path Path to sanitize.
	 * @return string Sanitized path.
	 * @throws \Exception If path contains dangerous characters.
	 */
	private function sanitizePath( string $path ): string {
		// Remove any directory traversal attempts
		$path = str_replace( array( '../', '..\\', '../', '..\\' ), '', $path );

		// Normalize path separators
		$path = str_replace( '\\', '/', $path );

		// Remove multiple slashes
		$path = preg_replace( '/\/+/', '/', $path );

		// Trim and validate
		$path = trim( $path, '/' );

		// Validate path doesn't contain dangerous characters
		if ( preg_match( '/[<>:"|?*]/', $path ) ) {
			throw new \Exception( 'Invalid characters in path' );
		}

		return $path;
	}

	/**
	 * Sanitize host name.
	 *
	 * @param string $host Host to sanitize.
	 * @return string Sanitized host.
	 * @throws \Exception If host is invalid.
	 */
	private function sanitizeHost( string $host ): string {
		// Validate host
		if ( ! filter_var( $host, FILTER_VALIDATE_DOMAIN ) ) {
			throw new \Exception( 'Invalid host name' );
		}

		return $host;
	}

	/**
	 * Construct secure file path.
	 *
	 * @param string $base_dir Base directory.
	 * @param string $host Host name.
	 * @param string $path Path.
	 * @return string Secure file path.
	 * @throws \Exception If path is outside allowed directory.
	 */
	private function constructSecureFilePath( string $base_dir, string $host, string $path ): string {
		// Construct file path
		$file_path = $base_dir . $host . '/' . $path;

		// Ensure path is within cache directory
		$real_base = realpath( $base_dir );
		$real_path = realpath( dirname( $file_path ) );

		if ( $real_path && strpos( $real_path, $real_base ) !== 0 ) {
			throw new \Exception( 'Path outside allowed directory' );
		}

		return $file_path;
	}
}
