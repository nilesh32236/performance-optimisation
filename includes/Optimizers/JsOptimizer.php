<?php
/**
 * JS Optimizer
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\CacheUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\PerformanceUtil;
use PerformanceOptimisation\Utils\ValidationUtil;
use MatthiasMullie\Minify\JS as MatthiasJsMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsOptimizer implements OptimizerInterface {

	private array $stats = array(
		'total_files'   => 0,
		'total_bytes'   => 0,
		'bytes_saved'   => 0,
		'total_time_ms' => 0,
	);

	private array $optimization_options = array(
		'enable_minification' => true,
		'enable_combination' => true,
		'enable_tree_shaking' => false, // Advanced feature
		'enable_module_bundling' => false, // Advanced feature
		'preserve_comments' => false,
		'preserve_semicolons' => true,
		'async_loading' => true,
		'defer_loading' => true,
	);

	public function optimize( string $content, array $options = array() ): string {
		$original_size = strlen( $content );
		
		// Performance tracking
		PerformanceUtil::startTimer( 'js_optimization' );
		
		try {
			// Merge options with defaults
			$options = array_merge( $this->optimization_options, $options );
			
			// Validate JavaScript content
			$content = ValidationUtil::sanitizeJs( $content );
			
			// Apply optimization techniques
			$optimized = $this->minify_js( $content, $options );
			$optimized = $this->optimize_syntax( $optimized, $options );
			$optimized = $this->remove_dead_code( $optimized, $options );
			$optimized = $this->optimize_variables( $optimized, $options );
			
			$optimized_size = strlen( $optimized );
			$compression_ratio = $original_size > 0 ? ( 1 - $optimized_size / $original_size ) : 0;
			$duration = PerformanceUtil::endTimer( 'js_optimization' );
			
			// Update stats
			++$this->stats['total_files'];
			$this->stats['total_bytes'] += $original_size;
			$this->stats['bytes_saved'] += ( $original_size - $optimized_size );
			$this->stats['total_time_ms'] += $duration * 1000;
			
			LoggingUtil::info( 'JavaScript optimized successfully', array(
				'original_size' => $original_size,
				'optimized_size' => $optimized_size,
				'compression_ratio' => round( $compression_ratio * 100, 2 ) . '%',
				'duration' => $duration,
			) );
			
			return $optimized;
			
		} catch ( \Exception $e ) {
			PerformanceUtil::endTimer( 'js_optimization' );
			LoggingUtil::error( 'JavaScript optimization failed: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}

	public function can_optimize( string $content_type ): bool {
		return in_array( $content_type, $this->get_supported_types(), true );
	}

	public function get_name(): string {
		return 'JS Optimizer';
	}

	public function get_supported_types(): array {
		return array( 'js' );
	}

	public function get_stats(): array {
		return $this->stats;
	}

	public function reset_stats(): void {
		$this->stats = array(
			'total_files'   => 0,
			'total_bytes'   => 0,
			'bytes_saved'   => 0,
			'total_time_ms' => 0,
		);
	}

	/**
	 * Process JavaScript file with caching.
	 *
	 * @param string $file_path JavaScript file path.
	 * @param array  $options   Optimization options.
	 * @return string Optimized JavaScript content.
	 */
	public function process_file( string $file_path, array $options = array() ): string {
		if ( ! FileSystemUtil::fileExists( $file_path ) ) {
			LoggingUtil::warning( 'JavaScript file not found for processing', array( 'path' => $file_path ) );
			return '';
		}
		
		// Check cache first
		$cache_key = CacheUtil::generateCacheKey( $file_path . filemtime( $file_path ), 'js_opt' );
		$cached_result = wp_cache_get( $cache_key, 'wppo_js_optimization' );
		
		if ( false !== $cached_result ) {
			LoggingUtil::debug( 'JavaScript optimization served from cache', array( 'path' => $file_path ) );
			return $cached_result;
		}
		
		try {
			$content = FileSystemUtil::readFile( $file_path );
			$optimized = $this->optimize( $content, $options );
			
			// Cache the result
			wp_cache_set( $cache_key, $optimized, 'wppo_js_optimization', CacheUtil::getCacheExpiry( 'minified' ) );
			
			return $optimized;
			
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to process JavaScript file: ' . $e->getMessage(), array( 'path' => $file_path ) );
			return '';
		}
	}

	/**
	 * Minify JavaScript content.
	 *
	 * @param string $content JavaScript content.
	 * @param array  $options Minification options.
	 * @return string Minified JavaScript.
	 */
	private function minify_js( string $content, array $options ): string {
		if ( ! $options['enable_minification'] ) {
			return $content;
		}

		try {
			$minifier = new MatthiasJsMinifier( $content );
			return $minifier->minify();
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'JavaScript minification failed: ' . $e->getMessage() );
			return $content;
		}
	}

	/**
	 * Optimize JavaScript syntax and structure.
	 *
	 * @param string $js      JavaScript content.
	 * @param array  $options Optimization options.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_syntax( string $js, array $options ): string {
		// Remove unnecessary semicolons (if option is disabled)
		if ( ! $options['preserve_semicolons'] ) {
			$js = $this->optimize_semicolons( $js );
		}

		// Optimize function declarations
		$js = $this->optimize_functions( $js );

		// Optimize object and array literals
		$js = $this->optimize_literals( $js );

		// Optimize conditional statements
		$js = $this->optimize_conditionals( $js );

		return $js;
	}

	/**
	 * Remove dead code and unused variables.
	 *
	 * @param string $js      JavaScript content.
	 * @param array  $options Optimization options.
	 * @return string Optimized JavaScript.
	 */
	private function remove_dead_code( string $js, array $options ): string {
		// Remove unreachable code after return statements
		$js = preg_replace( '/return[^;]*;[\s\n]*[^}]*(?=})/', 'return$0;', $js );

		// Remove empty blocks
		$js = preg_replace( '/\{\s*\}/', '{}', $js );

		// Remove redundant else statements
		$js = $this->optimize_else_statements( $js );

		return $js;
	}

	/**
	 * Optimize variable names and declarations.
	 *
	 * @param string $js      JavaScript content.
	 * @param array  $options Optimization options.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_variables( string $js, array $options ): string {
		// Combine variable declarations
		$js = $this->combine_var_declarations( $js );

		// Optimize boolean values
		$js = str_replace( array( 'true', 'false' ), array( '!0', '!1' ), $js );

		// Optimize undefined
		$js = preg_replace( '/\bundefined\b/', 'void 0', $js );

		return $js;
	}

	/**
	 * Optimize semicolon usage.
	 *
	 * @param string $js JavaScript content.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_semicolons( string $js ): string {
		// Remove unnecessary semicolons before closing braces
		$js = preg_replace( '/;(\s*})/', '$1', $js );

		// Remove semicolons at end of blocks
		$js = preg_replace( '/;(\s*$)/', '$1', $js );

		return $js;
	}

	/**
	 * Optimize function declarations and expressions.
	 *
	 * @param string $js JavaScript content.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_functions( string $js ): string {
		// Convert function expressions to arrow functions where possible (ES6+)
		$js = preg_replace( '/function\s*\(\s*([^)]*)\s*\)\s*\{\s*return\s+([^;]+);\s*\}/', '($1)=>$2', $js );

		// Optimize empty functions
		$js = preg_replace( '/function\s*\(\s*\)\s*\{\s*\}/', '()=>{}', $js );

		return $js;
	}

	/**
	 * Optimize object and array literals.
	 *
	 * @param string $js JavaScript content.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_literals( string $js ): string {
		// Optimize property access
		$js = preg_replace( '/\[(["\'])([a-zA-Z_$][a-zA-Z0-9_$]*)\1\]/', '.$2', $js );

		// Optimize array creation
		$js = preg_replace( '/new Array\(\)/', '[]', $js );
		$js = preg_replace( '/new Object\(\)/', '{}', $js );

		return $js;
	}

	/**
	 * Optimize conditional statements.
	 *
	 * @param string $js JavaScript content.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_conditionals( string $js ): string {
		// Optimize simple if statements
		$js = preg_replace( '/if\s*\(\s*([^)]+)\s*\)\s*\{\s*([^}]+)\s*\}/', 'if($1)$2', $js );

		// Optimize ternary operators
		$js = preg_replace( '/if\s*\(\s*([^)]+)\s*\)\s*([^;]+);\s*else\s*([^;]+);/', '$1?$2:$3', $js );

		return $js;
	}

	/**
	 * Optimize else statements.
	 *
	 * @param string $js JavaScript content.
	 * @return string Optimized JavaScript.
	 */
	private function optimize_else_statements( string $js ): string {
		// Remove redundant else after return
		$js = preg_replace( '/return[^;]*;\s*}\s*else\s*\{/', 'return$0;}', $js );

		return $js;
	}

	/**
	 * Combine variable declarations.
	 *
	 * @param string $js JavaScript content.
	 * @return string Optimized JavaScript.
	 */
	private function combine_var_declarations( string $js ): string {
		// Combine consecutive var declarations
		$js = preg_replace( '/var\s+([^;]+);\s*var\s+/', 'var $1,', $js );

		// Combine let declarations
		$js = preg_replace( '/let\s+([^;]+);\s*let\s+/', 'let $1,', $js );

		// Combine const declarations
		$js = preg_replace( '/const\s+([^;]+);\s*const\s+/', 'const $1,', $js );

		return $js;
	}

	/**
	 * Combine multiple JavaScript files into one optimized file.
	 *
	 * @param array $file_paths Array of JavaScript file paths.
	 * @param array $options    Combination options.
	 * @return string Combined and optimized JavaScript.
	 */
	public function combineFiles( array $file_paths, array $options = array() ): string {
		$combined_js = '';
		$processed_files = 0;

		PerformanceUtil::startTimer( 'js_combination' );

		foreach ( $file_paths as $file_path ) {
			if ( FileSystemUtil::fileExists( $file_path ) ) {
				try {
					$js_content = FileSystemUtil::readFile( $file_path );
					
					// Add file separator comment
					$combined_js .= "\n/* File: " . basename( $file_path ) . " */\n";
					$combined_js .= $js_content . "\n";
					
					$processed_files++;
				} catch ( \Exception $e ) {
					LoggingUtil::error( 'Failed to read JavaScript file for combination: ' . $e->getMessage(), array( 'path' => $file_path ) );
				}
			} else {
				LoggingUtil::warning( 'JavaScript file not found for combination', array( 'path' => $file_path ) );
			}
		}

		$duration = PerformanceUtil::endTimer( 'js_combination' );
		
		LoggingUtil::info( 'JavaScript files combined', array(
			'total_files' => count( $file_paths ),
			'processed_files' => $processed_files,
			'duration' => $duration,
		) );

		return $this->optimize( $combined_js, $options );
	}

	/**
	 * Generate loading strategy attributes for script tags.
	 *
	 * @param string $script_type Type of script (critical, non-critical, analytics, etc.).
	 * @param array  $options     Loading options.
	 * @return array Script attributes.
	 */
	public function generateLoadingStrategy( string $script_type, array $options = array() ): array {
		$attributes = array();

		switch ( $script_type ) {
			case 'critical':
				// Critical scripts load immediately
				break;

			case 'non-critical':
				if ( $options['defer_loading'] ?? true ) {
					$attributes['defer'] = true;
				}
				break;

			case 'analytics':
			case 'tracking':
				if ( $options['async_loading'] ?? true ) {
					$attributes['async'] = true;
				}
				break;

			case 'social':
			case 'widgets':
				$attributes['defer'] = true;
				$attributes['data-wppo-lazy'] = true;
				break;

			default:
				if ( $options['defer_loading'] ?? true ) {
					$attributes['defer'] = true;
				}
		}

		return $attributes;
	}

	/**
	 * Extract critical JavaScript for above-the-fold functionality.
	 *
	 * @param string $js        JavaScript content.
	 * @param array  $selectors Critical selectors or functions.
	 * @return string Critical JavaScript.
	 */
	public function extractCriticalJS( string $js, array $selectors ): string {
		if ( empty( $selectors ) ) {
			return '';
		}

		$critical_js = '';

		// Extract functions that match critical selectors
		foreach ( $selectors as $selector ) {
			$pattern = '/function\s+' . preg_quote( $selector, '/' ) . '\s*\([^{]*\{[^}]*\}/';
			if ( preg_match( $pattern, $js, $matches ) ) {
				$critical_js .= $matches[0] . "\n";
			}
		}

		// Extract immediately invoked function expressions (IIFE) that might be critical
		$pattern = '/\(function\s*\([^)]*\)\s*\{[^}]*\}\)\s*\([^)]*\);/';
		if ( preg_match_all( $pattern, $js, $matches ) ) {
			foreach ( $matches[0] as $match ) {
				$critical_js .= $match . "\n";
			}
		}

		return $this->optimize( $critical_js );
	}

	/**
	 * Analyze JavaScript dependencies and create loading order.
	 *
	 * @param array $scripts Array of script information.
	 * @return array Optimized loading order.
	 */
	public function analyzeDependencies( array $scripts ): array {
		$dependency_graph = array();
		$loading_order = array();

		// Build dependency graph
		foreach ( $scripts as $handle => $script ) {
			$dependency_graph[ $handle ] = $script['deps'] ?? array();
		}

		// Topological sort to determine loading order
		$visited = array();
		$temp_visited = array();

		foreach ( array_keys( $dependency_graph ) as $handle ) {
			if ( ! isset( $visited[ $handle ] ) ) {
				$this->topological_sort( $handle, $dependency_graph, $visited, $temp_visited, $loading_order );
			}
		}

		return array_reverse( $loading_order );
	}

	/**
	 * Topological sort helper for dependency analysis.
	 *
	 * @param string $handle           Script handle.
	 * @param array  $dependency_graph Dependency graph.
	 * @param array  $visited          Visited nodes.
	 * @param array  $temp_visited     Temporarily visited nodes.
	 * @param array  $loading_order    Loading order result.
	 */
	private function topological_sort( string $handle, array $dependency_graph, array &$visited, array &$temp_visited, array &$loading_order ): void {
		if ( isset( $temp_visited[ $handle ] ) ) {
			// Circular dependency detected
			LoggingUtil::warning( 'Circular dependency detected in JavaScript', array( 'handle' => $handle ) );
			return;
		}

		if ( isset( $visited[ $handle ] ) ) {
			return;
		}

		$temp_visited[ $handle ] = true;

		foreach ( $dependency_graph[ $handle ] ?? array() as $dependency ) {
			if ( isset( $dependency_graph[ $dependency ] ) ) {
				$this->topological_sort( $dependency, $dependency_graph, $visited, $temp_visited, $loading_order );
			}
		}

		unset( $temp_visited[ $handle ] );
		$visited[ $handle ] = true;
		$loading_order[] = $handle;
	}

	/**
	 * Generate module bundling configuration.
	 *
	 * @param array $modules Module configuration.
	 * @return array Bundle configuration.
	 */
	public function generateModuleBundles( array $modules ): array {
		$bundles = array(
			'vendor' => array(), // Third-party libraries
			'common' => array(), // Shared code
			'critical' => array(), // Critical path code
			'lazy' => array(), // Lazy-loaded modules
		);

		foreach ( $modules as $module => $config ) {
			$bundle_type = $this->determineBundleType( $module, $config );
			$bundles[ $bundle_type ][] = $module;
		}

		return $bundles;
	}

	/**
	 * Determine appropriate bundle type for a module.
	 *
	 * @param string $module Module name.
	 * @param array  $config Module configuration.
	 * @return string Bundle type.
	 */
	private function determineBundleType( string $module, array $config ): string {
		// Check if it's a vendor library
		if ( $config['vendor'] ?? false ) {
			return 'vendor';
		}

		// Check if it's critical
		if ( $config['critical'] ?? false ) {
			return 'critical';
		}

		// Check if it's lazy-loaded
		if ( $config['lazy'] ?? false ) {
			return 'lazy';
		}

		// Default to common bundle
		return 'common';
	}
}
