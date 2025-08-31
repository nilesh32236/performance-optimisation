<?php
/**
 * Optimizer Interface
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Interfaces;

/**
 * Interface for asset optimizers
 *
 * @since 1.1.0
 */
interface OptimizerInterface {

	/**
	 * Optimize content
	 *
	 * @since 1.1.0
	 * @param string $content Content to optimize
	 * @param array  $options Optimization options
	 * @return string Optimized content
	 */
	public function optimize( string $content, array $options = array() ): string;

	/**
	 * Check if optimizer can handle the content type
	 *
	 * @since 1.1.0
	 * @param string $content_type Content type (css, js, html)
	 * @return bool True if can handle, false otherwise
	 */
	public function can_optimize( string $content_type ): bool;

	/**
	 * Get optimizer name
	 *
	 * @since 1.1.0
	 * @return string Optimizer name
	 */
	public function get_name(): string;

	/**
	 * Get supported content types
	 *
	 * @since 1.1.0
	 * @return array Array of supported content types
	 */
	public function get_supported_types(): array;

	/**
	 * Get optimization statistics
	 *
	 * @since 1.1.0
	 * @return array Optimization statistics
	 */
	public function get_stats(): array;

	/**
	 * Reset optimization statistics
	 *
	 * @since 1.1.0
	 * @return void
	 */
	public function reset_stats(): void;
}
