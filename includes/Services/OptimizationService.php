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
		$this->stats         = [
			'css'  => [ 'files' => 0, 'size_saved' => 0 ],
			'js'   => [ 'files' => 0, 'size_saved' => 0 ],
			'html' => [ 'files' => 0, 'size_saved' => 0 ],
		];
	}

	public function optimizeAssets( array $assets ): array {
		$optimized_assets = [];
		foreach ( $assets as $asset ) {
			if ( ! $this->shouldOptimize( $asset['type'], $asset['url'] ) ) {
				$optimized_assets[] = $asset;
				continue;
			}

			$content = FileSystemUtil::readFile( $asset['path'] );
			if ( ! $content ) {
				$optimized_assets[] = $asset;
				continue;
			}

			$original_size = strlen( $content );
			$optimizer     = $this->getOptimizer( $asset['type'] );
			$optimized_content = $optimizer->optimize( $content, [ 'base_path' => $asset['path'] ] );
			$optimized_size = strlen( $optimized_content );

			$this->stats[ $asset['type'] ]['files']++;
			$this->stats[ $asset['type'] ]['size_saved'] += ( $original_size - $optimized_size );

			$optimized_assets[] = [
				'type'    => $asset['type'],
				'url'     => $this->storeOptimizedAsset( $asset['url'], $optimized_content ),
				'content' => $optimized_content,
			];
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
		$filename = md5( $original_url ) . '.' . pathinfo( $original_url, PATHINFO_EXTENSION );
		$type     = pathinfo( $original_url, PATHINFO_EXTENSION );
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
				FileSystemUtil::writeFile( $file_path . ".gz", $gzip_output );
			}
		}
	}
}
