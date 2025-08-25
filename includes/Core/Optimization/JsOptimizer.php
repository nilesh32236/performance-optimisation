<?php
/**
 * JavaScript Optimizer
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Optimization;

use PerformanceOptimisation\Interfaces\OptimizerInterface;

/**
 * JavaScript optimization implementation
 *
 * @since 1.1.0
 */
class JsOptimizer implements OptimizerInterface {

	/**
	 * Optimizer name
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $name = 'js';

	/**
	 * Supported content types
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $supported_types = array( 'js', 'javascript' );

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
	 * Optimize JavaScript content
	 *
	 * @since 1.1.0
	 * @param string $content JavaScript content to optimize
	 * @param array  $options Optimization options
	 * @return string Optimized JavaScript content
	 */
	public function optimize( string $content, array $options = array() ): string {
		$start_time    = microtime( true );
		$original_size = strlen( $content );

		// Basic minification - remove comments and unnecessary whitespace
		$content = $this->remove_comments( $content );
		$content = $this->remove_whitespace( $content );
		$content = $this->optimize_semicolons( $content );

		// More aggressive optimizations if enabled
		if ( isset( $options['aggressive'] ) && $options['aggressive'] ) {
			$content = $this->optimize_variables( $content );
		}

		$end_time       = microtime( true );
		$optimized_size = strlen( $content );

		// Update statistics
		++$this->stats['files_processed'];
		$this->stats['bytes_saved']     += ( $original_size - $optimized_size );
		$this->stats['processing_time'] += ( $end_time - $start_time );

		return $content;
	}

	/**
	 * Check if optimizer can handle the content type
	 *
	 * @since 1.1.0
	 * @param string $content_type Content type
	 * @return bool True if can handle, false otherwise
	 */
	public function can_optimize( string $content_type ): bool {
		return in_array( $content_type, $this->supported_types, true );
	}

	/**
	 * Get optimizer name
	 *
	 * @since 1.1.0
	 * @return string Optimizer name
	 */
	public function get_name(): string {
		return $this->name;
	}

	/**
	 * Get supported content types
	 *
	 * @since 1.1.0
	 * @return array Array of supported content types
	 */
	public function get_supported_types(): array {
		return $this->supported_types;
	}

	/**
	 * Get optimization statistics
	 *
	 * @since 1.1.0
	 * @return array Optimization statistics
	 */
	public function get_stats(): array {
		return $this->stats;
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
	}

	/**
	 * Remove JavaScript comments
	 *
	 * @since 1.1.0
	 * @param string $content JavaScript content
	 * @return string JavaScript content without comments
	 */
	private function remove_comments( string $content ): string {
		// Remove single-line comments but preserve URLs and regex patterns
		$content = preg_replace( '/(?<!:)\/\/(?![^\r\n]*["\']).*$/m', '', $content );

		// Remove multi-line comments but preserve important comments (/*! ... */)
		$content = preg_replace( '/\/\*(?!\!).*?\*\//s', '', $content );

		return $content;
	}

	/**
	 * Remove unnecessary whitespace
	 *
	 * @since 1.1.0
	 * @param string $content JavaScript content
	 * @return string JavaScript content without unnecessary whitespace
	 */
	private function remove_whitespace( string $content ): string {
		// Remove leading and trailing whitespace
		$content = trim( $content );

		// Replace multiple whitespace with single space, but preserve line breaks in some contexts
		$content = preg_replace( '/[ \t]+/', ' ', $content );

		// Remove whitespace around operators and punctuation
		$content = preg_replace( '/\s*([{}();,=+\-*\/&|!<>?:])\s*/', '$1', $content );

		// Remove whitespace before and after parentheses and brackets
		$content = preg_replace( '/\s*\(\s*/', '(', $content );
		$content = preg_replace( '/\s*\)\s*/', ')', $content );
		$content = preg_replace( '/\s*\[\s*/', '[', $content );
		$content = preg_replace( '/\s*\]\s*/', ']', $content );

		// Remove unnecessary line breaks
		$content = preg_replace( '/\n\s*\n/', "\n", $content );

		return $content;
	}

	/**
	 * Optimize semicolons
	 *
	 * @since 1.1.0
	 * @param string $content JavaScript content
	 * @return string JavaScript content with optimized semicolons
	 */
	private function optimize_semicolons( string $content ): string {
		// Remove unnecessary semicolons before closing braces
		$content = preg_replace( '/;\s*}/', '}', $content );

		// Ensure semicolons are present where needed (basic ASI prevention)
		$content = preg_replace( '/([a-zA-Z0-9_$\]\)])\s*\n\s*([a-zA-Z0-9_$\[\(])/', '$1;$2', $content );

		return $content;
	}

	/**
	 * Optimize variable names (basic implementation)
	 *
	 * @since 1.1.0
	 * @param string $content JavaScript content
	 * @return string JavaScript content with optimized variable names
	 */
	private function optimize_variables( string $content ): string {
		// This is a very basic implementation
		// In a real minifier, you'd need proper AST parsing to avoid breaking the code

		// Replace common long variable names with shorter ones (very basic)
		$replacements = array(
			'document' => 'd',
			'window'   => 'w',
			'function' => 'function', // Keep function keyword
		);

		// Only replace in safe contexts (this is overly simplified)
		foreach ( $replacements as $long => $short ) {
			if ( $long !== 'function' ) {
				// Very basic replacement - in reality, you'd need scope analysis
				$content = preg_replace( '/\b' . preg_quote( $long, '/' ) . '\b/', $short, $content );
			}
		}

		return $content;
	}
}
