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

	public function optimize( string $content, array $options = [] ): string {
		try {
			// Update image paths before minification
			if ( isset( $options['base_path'] ) ) {
				$content = $this->update_image_paths( $content, $options['base_path'] );
			}

			$minifier = new MatthiasCssMinifier( $content );
			return $minifier->minify();
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'CSS Minification Error: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}

	private function update_image_paths( string $css_content, string $base_path ): string {
		return preg_replace_callback(
			'/url\s*\(\s*["']?([^'"\)]+)["']?\s*\)/i',
			function ( $matches ) use ( $base_path ) {
				$url = $matches[1];
				if ( 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'http' ) || 0 === strpos( $url, '//' ) ) {
					return $matches[0];
				}

				$absolute_path = realpath( dirname( $base_path ) . '/' . $url );
				if ( false !== $absolute_path ) {
					$document_root = realpath( $_SERVER['DOCUMENT_ROOT'] );
					$web_path      = str_replace( $document_root, '', $absolute_path );
					return 'url(' . str_replace( '\\', '/', $web_path ) . ')';
				}

				return $matches[0];
			},
			$css_content
		);
	}
}
