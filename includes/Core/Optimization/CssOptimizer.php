<?php
/**
 * CSS Optimizer
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Optimization;

use PerformanceOptimisation\Interfaces\OptimizerInterface;

/**
 * CSS optimization implementation
 *
 * @since 1.1.0
 */
class CssOptimizer implements OptimizerInterface {

	/**
	 * Optimizer name
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $name = 'css';

	/**
	 * Supported content types
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $supported_types = array( 'css' );

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
	 * Optimize CSS content
	 *
	 * @since 1.1.0
	 * @param string $content CSS content to optimize
	 * @param array  $options Optimization options
	 * @return string Optimized CSS content
	 */
	public function optimize( string $content, array $options = array() ): string {
		$start_time    = microtime( true );
		$original_size = strlen( $content );

		// Remove comments
		$content = $this->remove_comments( $content );

		// Remove unnecessary whitespace
		$content = $this->remove_whitespace( $content );

		// Remove empty rules
		$content = $this->remove_empty_rules( $content );

		// Optimize colors
		$content = $this->optimize_colors( $content );

		// Optimize font weights
		$content = $this->optimize_font_weights( $content );

		// Remove unnecessary semicolons
		$content = $this->remove_unnecessary_semicolons( $content );

		// Optimize zero values
		$content = $this->optimize_zero_values( $content );

		// Optimize shorthand properties
		if ( isset( $options['optimize_shorthand'] ) && $options['optimize_shorthand'] ) {
			$content = $this->optimize_shorthand_properties( $content );
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
	 * Remove CSS comments
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content without comments
	 */
	private function remove_comments( string $content ): string {
		// Remove /* ... */ comments but preserve important comments (/*! ... */)
		return preg_replace( '/\/\*(?!\!).*?\*\//s', '', $content );
	}

	/**
	 * Remove unnecessary whitespace
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content without unnecessary whitespace
	 */
	private function remove_whitespace( string $content ): string {
		// Remove leading and trailing whitespace
		$content = trim( $content );

		// Replace multiple whitespace with single space
		$content = preg_replace( '/\s+/', ' ', $content );

		// Remove whitespace around specific characters
		$content = preg_replace( '/\s*([{}:;,>+~])\s*/', '$1', $content );

		// Remove whitespace before and after parentheses
		$content = preg_replace( '/\s*\(\s*/', '(', $content );
		$content = preg_replace( '/\s*\)\s*/', ')', $content );

		return $content;
	}

	/**
	 * Remove empty CSS rules
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content without empty rules
	 */
	private function remove_empty_rules( string $content ): string {
		// Remove rules with empty declarations
		return preg_replace( '/[^{}]+\{\s*\}/', '', $content );
	}

	/**
	 * Optimize color values
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content with optimized colors
	 */
	private function optimize_colors( string $content ): string {
		// Convert 6-digit hex colors to 3-digit when possible
		$content = preg_replace( '/#([0-9a-f])\1([0-9a-f])\2([0-9a-f])\3/i', '#$1$2$3', $content );

		// Convert named colors to shorter hex values
		$color_map = array(
			'white'   => '#fff',
			'black'   => '#000',
			'red'     => '#f00',
			'green'   => '#008000',
			'blue'    => '#00f',
			'yellow'  => '#ff0',
			'cyan'    => '#0ff',
			'magenta' => '#f0f',
		);

		foreach ( $color_map as $name => $hex ) {
			$content = str_ireplace( $name, $hex, $content );
		}

		return $content;
	}

	/**
	 * Optimize font weights
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content with optimized font weights
	 */
	private function optimize_font_weights( string $content ): string {
		$weight_map = array(
			'normal' => '400',
			'bold'   => '700',
		);

		foreach ( $weight_map as $name => $number ) {
			$content = preg_replace( '/font-weight\s*:\s*' . $name . '/i', 'font-weight:' . $number, $content );
		}

		return $content;
	}

	/**
	 * Remove unnecessary semicolons
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content without unnecessary semicolons
	 */
	private function remove_unnecessary_semicolons( string $content ): string {
		// Remove semicolon before closing brace
		return preg_replace( '/;\s*}/', '}', $content );
	}

	/**
	 * Optimize zero values
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content with optimized zero values
	 */
	private function optimize_zero_values( string $content ): string {
		// Remove units from zero values
		$content = preg_replace( '/\b0(?:px|em|ex|%|in|cm|mm|pt|pc)\b/', '0', $content );

		// Optimize multiple zeros in shorthand properties
		$content = preg_replace( '/\b0 0 0 0\b/', '0', $content );
		$content = preg_replace( '/\b0 0 0\b/', '0 0', $content );

		return $content;
	}

	/**
	 * Optimize shorthand properties
	 *
	 * @since 1.1.0
	 * @param string $content CSS content
	 * @return string CSS content with optimized shorthand properties
	 */
	private function optimize_shorthand_properties( string $content ): string {
		// This is a simplified implementation
		// In a real implementation, you'd need more sophisticated parsing

		// Optimize margin shorthand
		$content = preg_replace( '/margin-top\s*:\s*([^;]+);\s*margin-right\s*:\s*\1;\s*margin-bottom\s*:\s*\1;\s*margin-left\s*:\s*\1/', 'margin:$1', $content );

		// Optimize padding shorthand
		$content = preg_replace( '/padding-top\s*:\s*([^;]+);\s*padding-right\s*:\s*\1;\s*padding-bottom\s*:\s*\1;\s*padding-left\s*:\s*\1/', 'padding:$1', $content );

		return $content;
	}
}
