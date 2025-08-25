<?php
/**
 * Asset Optimizer Manager
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Optimization;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Interfaces\ConfigInterface;
use PerformanceOptimisation\Exceptions\OptimizationException;

/**
 * Asset optimization management class
 *
 * @since 1.1.0
 */
class AssetOptimizer {

	/**
	 * Registered optimizers
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $optimizers = array();

	/**
	 * Configuration manager
	 *
	 * @since 1.1.0
	 * @var ConfigInterface
	 */
	private ConfigInterface $config;

	/**
	 * Optimization statistics
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $stats = array(
		'files_processed' => 0,
		'bytes_saved'     => 0,
		'processing_time' => 0,
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param ConfigInterface $config Configuration manager
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config = $config;
		$this->register_default_optimizers();
	}

	/**
	 * Register an optimizer
	 *
	 * @since 1.1.0
	 * @param OptimizerInterface $optimizer Optimizer instance
	 * @return void
	 */
	public function register_optimizer( OptimizerInterface $optimizer ): void {
		$this->optimizers[ $optimizer->get_name() ] = $optimizer;
	}

	/**
	 * Get an optimizer by name
	 *
	 * @since 1.1.0
	 * @param string $name Optimizer name
	 * @return OptimizerInterface Optimizer instance
	 * @throws OptimizationException If optimizer not found
	 */
	public function get_optimizer( string $name ): OptimizerInterface {
		if ( ! isset( $this->optimizers[ $name ] ) ) {
			throw new OptimizationException( "Optimizer '{$name}' not found." );
		}

		return $this->optimizers[ $name ];
	}

	/**
	 * Optimize content by type
	 *
	 * @since 1.1.0
	 * @param string $content      Content to optimize
	 * @param string $content_type Content type (css, js, html)
	 * @param array  $options      Optimization options
	 * @return string Optimized content
	 */
	public function optimize( string $content, string $content_type, array $options = array() ): string {
		$start_time        = microtime( true );
		$original_size     = strlen( $content );
		$optimized_content = $content;

		foreach ( $this->optimizers as $optimizer ) {
			if ( $optimizer->can_optimize( $content_type ) ) {
				$optimized_content = $optimizer->optimize( $optimized_content, $options );
			}
		}

		$end_time       = microtime( true );
		$optimized_size = strlen( $optimized_content );

		// Update statistics
		++$this->stats['files_processed'];
		$this->stats['bytes_saved']     += ( $original_size - $optimized_size );
		$this->stats['processing_time'] += ( $end_time - $start_time );

		return $optimized_content;
	}

	/**
	 * Optimize CSS content
	 *
	 * @since 1.1.0
	 * @param string $css     CSS content
	 * @param array  $options Optimization options
	 * @return string Optimized CSS
	 */
	public function optimize_css( string $css, array $options = array() ): string {
		if ( ! $this->config->get( 'minification.minify_css', true ) ) {
			return $css;
		}

		return $this->optimize( $css, 'css', $options );
	}

	/**
	 * Optimize JavaScript content
	 *
	 * @since 1.1.0
	 * @param string $js      JavaScript content
	 * @param array  $options Optimization options
	 * @return string Optimized JavaScript
	 */
	public function optimize_js( string $js, array $options = array() ): string {
		if ( ! $this->config->get( 'minification.minify_js', true ) ) {
			return $js;
		}

		return $this->optimize( $js, 'js', $options );
	}

	/**
	 * Optimize HTML content
	 *
	 * @since 1.1.0
	 * @param string $html    HTML content
	 * @param array  $options Optimization options
	 * @return string Optimized HTML
	 */
	public function optimize_html( string $html, array $options = array() ): string {
		if ( ! $this->config->get( 'minification.minify_html', false ) ) {
			return $html;
		}

		return $this->optimize( $html, 'html', $options );
	}

	/**
	 * Combine multiple CSS files
	 *
	 * @since 1.1.0
	 * @param array $css_files Array of CSS file paths or contents
	 * @param array $options   Combination options
	 * @return string Combined CSS content
	 */
	public function combine_css( array $css_files, array $options = array() ): string {
		if ( ! $this->config->get( 'minification.combine_css', false ) ) {
			return '';
		}

		$combined_css = '';

		foreach ( $css_files as $css_file ) {
			if ( is_file( $css_file ) ) {
				$css_content = file_get_contents( $css_file );
			} else {
				$css_content = $css_file;
			}

			$combined_css .= $this->optimize_css( $css_content, $options ) . "\n";
		}

		return $combined_css;
	}

	/**
	 * Combine multiple JavaScript files
	 *
	 * @since 1.1.0
	 * @param array $js_files Array of JS file paths or contents
	 * @param array $options  Combination options
	 * @return string Combined JavaScript content
	 */
	public function combine_js( array $js_files, array $options = array() ): string {
		if ( ! $this->config->get( 'minification.combine_js', false ) ) {
			return '';
		}

		$combined_js = '';

		foreach ( $js_files as $js_file ) {
			if ( is_file( $js_file ) ) {
				$js_content = file_get_contents( $js_file );
			} else {
				$js_content = $js_file;
			}

			$combined_js .= $this->optimize_js( $js_content, $options ) . ";\n";
		}

		return $combined_js;
	}

	/**
	 * Generate source map for debugging
	 *
	 * @since 1.1.0
	 * @param string $original_content Original content
	 * @param string $optimized_content Optimized content
	 * @param array  $options Source map options
	 * @return string Source map JSON
	 */
	public function generate_source_map( string $original_content, string $optimized_content, array $options = array() ): string {
		// Simplified source map generation
		// In a real implementation, you'd use a proper source map library
		$source_map = array(
			'version'        => 3,
			'sources'        => array( $options['source_file'] ?? 'original.js' ),
			'names'          => array(),
			'mappings'       => '',
			'sourcesContent' => array( $original_content ),
		);

		return json_encode( $source_map );
	}

	/**
	 * Get optimization statistics
	 *
	 * @since 1.1.0
	 * @return array Optimization statistics
	 */
	public function get_stats(): array {
		$optimizer_stats = array();

		foreach ( $this->optimizers as $name => $optimizer ) {
			$optimizer_stats[ $name ] = $optimizer->get_stats();
		}

		return array(
			'global'     => $this->stats,
			'optimizers' => $optimizer_stats,
		);
	}

	/**
	 * Reset optimization statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void {
		$this->stats = array(
			'files_processed' => 0,
			'bytes_saved'     => 0,
			'processing_time' => 0,
		);

		foreach ( $this->optimizers as $optimizer ) {
			$optimizer->reset_stats();
		}
	}

	/**
	 * Get available optimizers
	 *
	 * @since 1.1.0
	 * @return array Array of optimizer names
	 */
	public function get_available_optimizers(): array {
		return array_keys( $this->optimizers );
	}

	/**
	 * Check if optimization is enabled for content type
	 *
	 * @since 1.1.0
	 * @param string $content_type Content type (css, js, html)
	 * @return bool True if enabled, false otherwise
	 */
	public function is_optimization_enabled( string $content_type ): bool {
		switch ( $content_type ) {
			case 'css':
				return $this->config->get( 'minification.minify_css', true );
			case 'js':
				return $this->config->get( 'minification.minify_js', true );
			case 'html':
				return $this->config->get( 'minification.minify_html', false );
			default:
				return false;
		}
	}

	/**
	 * Register default optimizers
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private function register_default_optimizers(): void {
		$this->register_optimizer( new CssOptimizer() );
		$this->register_optimizer( new JsOptimizer() );
		$this->register_optimizer( new HtmlOptimizer() );
	}
}
