<?php
/**
 * CSS Optimizer
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use MatthiasMullie\Minify\CSS as MatthiasCssMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CssOptimizer implements OptimizerInterface {

	private array $stats = [
		'total_files'   => 0,
		'total_bytes'   => 0,
		'bytes_saved'   => 0,
		'total_time_ms' => 0,
	];

	public function optimize( string $content, array $options = array() ): string {
		$start_time = microtime( true );
		$original_size = strlen( $content );

		try {
			// Update image paths before minification
			if ( isset( $options['base_path'] ) ) {
				$content = $this->update_image_paths( $content, $options['base_path'] );
			}

			$minifier = new MatthiasCssMinifier( $content );
			$optimized_content = $minifier->minify();

			$end_time = microtime( true );
			$optimized_size = strlen( $optimized_content );

			// Update stats
			$this->stats['total_files']++;
			$this->stats['total_bytes'] += $original_size;
			$this->stats['bytes_saved'] += ( $original_size - $optimized_size );
			$this->stats['total_time_ms'] += ( $end_time - $start_time ) * 1000;

			return $optimized_content;
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'CSS Minification Error: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}

	public function can_optimize( string $content_type ): bool {
		return in_array( $content_type, $this->get_supported_types(), true );
	}

	public function get_name(): string {
		return 'CSS Optimizer';
	}

	public function get_supported_types(): array {
		return array( 'css' );
	}

	public function get_stats(): array {
		return $this->stats;
	}

	public function reset_stats(): void {
		$this->stats = [
			'total_files'   => 0,
			'total_bytes'   => 0,
			'bytes_saved'   => 0,
			'total_time_ms' => 0,
		];
	}

	private function update_image_paths( string $css_content, string $base_path ): string {
		return preg_replace_callback(
			'/url\s*\(\s*[\'"]?([^\'"\)]+)[\'"]?\s*\)/i',
			function ( $matches ) use ( $base_path ) {
				$url = $matches[1];

				// Ignore absolute URLs and data URIs
				if ( preg_match( '#^(data:|https?:|//)#i', $url ) ) {
					return $matches[0];
				}

				// Normalize the path
				$resolved_path = realpath( dirname( $base_path ) . DIRECTORY_SEPARATOR . $url );

				if ( $resolved_path === false ) {
					// If realpath fails, return the original URL
					return $matches[0];
				}

				$document_root = realpath( $_SERVER['DOCUMENT_ROOT'] );

				if ( $document_root === false || strpos( $resolved_path, $document_root ) !== 0 ) {
					// If document root is invalid or resolved path is outside it, return original
					return $matches[0];
				}

				// Build web-accessible path
				$web_path = str_replace( '\\', '/', substr( $resolved_path, strlen( $document_root ) ) );

				// Ensure leading slash
				if ( $web_path !== '' && $web_path[0] !== '/' ) {
					$web_path = '/' . $web_path;
				}

				return 'url(' . $web_path . ')';
			},
			$css_content
		);
	}
}
