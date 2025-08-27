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
	 * @return void
	 */
	public function combine_css(): void;

	/**
	 * Generate dynamic static HTML.
	 *
	 * @return void
	 */
	public function generate_dynamic_static_html(): void;
}
