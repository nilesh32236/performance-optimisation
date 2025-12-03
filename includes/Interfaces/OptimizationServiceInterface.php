<?php
/**
 * Optimization Service Interface
 *
 * @package PerformanceOptimisation\Interfaces
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface OptimizationServiceInterface
 *
 * @package PerformanceOptimisation\Interfaces
 */
interface OptimizationServiceInterface {

	/**
	 * Combine and minify CSS files.
	 *
	 * @return string The URL of the combined CSS file.
	 */
	public function combine_css(): string;

	/**
	 * Generate dynamic static HTML.
	 *
	 * @param string $url The URL to fetch and save as static HTML.
	 * @return bool True on success, false on failure.
	 */
	public function generate_dynamic_static_html( string $url ): bool;
}
