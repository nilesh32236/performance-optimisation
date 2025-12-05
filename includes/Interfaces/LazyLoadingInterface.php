<?php
/**
 * Lazy Loading Interface
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Interfaces;

/**
 * Interface for lazy loading implementations
 *
 * @since 1.1.0
 */
interface LazyLoadingInterface {

	/**
	 * Initialize lazy loading
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function initialize(): void;

	/**
	 * Process HTML content for lazy loading
	 *
	 * @since 1.1.0
	 * @param string $content HTML content.
	 * @return string Processed HTML content
	 */
	public function process_content( string $content ): string;

	/**
	 * Add lazy loading attributes to an element
	 *
	 * @since 1.1.0
	 * @param string $element_html Element HTML.
	 * @param string $element_type Element type (img, iframe, video).
	 * @return string Modified element HTML
	 */
	public function add_lazy_attributes( string $element_html, string $element_type ): string;

	/**
	 * Check if lazy loading is enabled for element type
	 *
	 * @since 1.1.0
	 * @param string $element_type Element type.
	 * @return bool True if enabled, false otherwise
	 */
	public function is_enabled_for_type( string $element_type ): bool;

	/**
	 * Get lazy loading configuration
	 *
	 * @since 1.1.0
	 * @return array Configuration array
	 */
	public function get_config(): array;

	/**
	 * Set lazy loading configuration
	 *
	 * @since 1.1.0
	 * @param array $config Configuration array.
	 * @return void
	 */
	public function set_config( array $config ): void;

	/**
	 * Get lazy loading statistics
	 *
	 * @since 1.1.0
	 * @return array Statistics array
	 */
	public function get_stats(): array;

	/**
	 * Reset lazy loading statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void;
}
