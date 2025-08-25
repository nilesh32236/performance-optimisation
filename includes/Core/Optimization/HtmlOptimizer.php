<?php
/**
 * HTML Optimizer
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Optimization;

use PerformanceOptimisation\Interfaces\OptimizerInterface;

/**
 * HTML optimization implementation
 *
 * @since 1.1.0
 */
class HtmlOptimizer implements OptimizerInterface {

	/**
	 * Optimizer name
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $name = 'html';

	/**
	 * Supported content types
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $supported_types = array( 'html' );

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
	 * Optimize HTML content
	 *
	 * @since 1.1.0
	 * @param string $content HTML content to optimize
	 * @param array  $options Optimization options
	 * @return string Optimized HTML content
	 */
	public function optimize( string $content, array $options = array() ): string {
		$start_time    = microtime( true );
		$original_size = strlen( $content );

		// Remove HTML comments (but preserve conditional comments)
		$content = $this->remove_comments( $content );

		// Remove unnecessary whitespace
		$content = $this->remove_whitespace( $content );

		// Remove empty attributes
		$content = $this->remove_empty_attributes( $content );

		// Optimize boolean attributes
		$content = $this->optimize_boolean_attributes( $content );

		// Remove optional closing tags (if enabled)
		if ( isset( $options['remove_optional_tags'] ) && $options['remove_optional_tags'] ) {
			$content = $this->remove_optional_closing_tags( $content );
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
	 * Remove HTML comments
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string HTML content without comments
	 */
	private function remove_comments( string $content ): string {
		// Remove HTML comments but preserve conditional comments and important comments
		$content = preg_replace( '/<!--(?!\[if)(?!!)[^>]*-->/', '', $content );

		return $content;
	}

	/**
	 * Remove unnecessary whitespace
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string HTML content without unnecessary whitespace
	 */
	private function remove_whitespace( string $content ): string {
		// Preserve whitespace in <pre>, <code>, <textarea>, and <script> tags
		$preserve_tags     = array( 'pre', 'code', 'textarea', 'script', 'style' );
		$preserved_content = array();
		$placeholder_index = 0;

		foreach ( $preserve_tags as $tag ) {
			$pattern = '/<' . $tag . '[^>]*>.*?<\/' . $tag . '>/is';
			$content = preg_replace_callback(
				$pattern,
				function ( $matches ) use ( &$preserved_content, &$placeholder_index ) {
					$placeholder                       = '<!--WPPO_PRESERVE_' . $placeholder_index . '-->';
					$preserved_content[ $placeholder ] = $matches[0];
					$placeholder_index++;
					return $placeholder;
				},
				$content
			);
		}

		// Remove unnecessary whitespace
		$content = preg_replace( '/\s+/', ' ', $content );
		$content = preg_replace( '/>\s+</', '><', $content );
		$content = trim( $content );

		// Restore preserved content
		foreach ( $preserved_content as $placeholder => $original ) {
			$content = str_replace( $placeholder, $original, $content );
		}

		return $content;
	}

	/**
	 * Remove empty attributes
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string HTML content without empty attributes
	 */
	private function remove_empty_attributes( string $content ): string {
		// Remove attributes with empty values (except for specific cases)
		$safe_empty_attributes = array( 'alt', 'title', 'value' );

		// Remove empty class, id, and style attributes
		$content = preg_replace( '/\s+(class|id|style)=["\']["\']/', '', $content );

		return $content;
	}

	/**
	 * Optimize boolean attributes
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string HTML content with optimized boolean attributes
	 */
	private function optimize_boolean_attributes( string $content ): string {
		$boolean_attributes = array(
			'checked',
			'selected',
			'disabled',
			'readonly',
			'multiple',
			'autofocus',
			'autoplay',
			'controls',
			'defer',
			'hidden',
			'loop',
			'open',
			'required',
			'reversed',
		);

		foreach ( $boolean_attributes as $attr ) {
			// Convert boolean attributes to minimized form
			$content = preg_replace( '/\s+' . $attr . '=["\']' . $attr . '["\']/', ' ' . $attr, $content );
			$content = preg_replace( '/\s+' . $attr . '=["\']["\']/', ' ' . $attr, $content );
		}

		return $content;
	}

	/**
	 * Remove optional closing tags
	 *
	 * @since 1.1.0
	 * @param string $content HTML content
	 * @return string HTML content without optional closing tags
	 */
	private function remove_optional_closing_tags( string $content ): string {
		// Remove optional closing tags (be very careful with this)
		$optional_closing_tags = array( 'p', 'li', 'dt', 'dd', 'option', 'thead', 'tbody', 'tfoot', 'tr', 'td', 'th' );

		foreach ( $optional_closing_tags as $tag ) {
			// This is a simplified implementation - in reality, you'd need proper HTML parsing
			// to determine when closing tags are truly optional

			// Only remove closing </p> tags before block elements
			if ( $tag === 'p' ) {
				$content = preg_replace( '/<\/p>\s*(?=<(?:div|p|h[1-6]|ul|ol|li|blockquote|pre|table|form|fieldset|address|hr))/i', '', $content );
			}
		}

		return $content;
	}
}
