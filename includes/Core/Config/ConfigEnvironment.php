<?php
/**
 * Configuration Environment
 *
 * @package PerformanceOptimisation\Core\Config
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Core\Config;

use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ConfigEnvironment
 *
 * Handles environment detection and configuration overrides.
 *
 * @package PerformanceOptimisation\Core\Config
 */
class ConfigEnvironment {

	/**
	 * Current environment.
	 *
	 * @var string
	 */
	private string $current_environment;

	/**
	 * Environment configurations.
	 *
	 * @var array
	 */
	private array $environments = array();

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->current_environment = $this->detectEnvironment();
		$this->loadEnvironmentConfigurations();
	}

	/**
	 * Get current environment.
	 *
	 * @return string Current environment.
	 */
	public function getEnvironment(): string {
		return $this->current_environment;
	}

	/**
	 * Set environment.
	 *
	 * @param string $environment Environment name.
	 * @return bool True on success, false on failure.
	 */
	public function setEnvironment( string $environment ): bool {
		if ( ! in_array( $environment, array( 'development', 'staging', 'production' ), true ) ) {
			return false;
		}

		$this->current_environment = $environment;
		LoggingUtil::info( 'Environment changed', array( 'environment' => $environment ) );

		return true;
	}

	/**
	 * Get environment-specific configuration overrides.
	 *
	 * @return array Configuration overrides.
	 */
	public function getEnvironmentOverrides(): array {
		return $this->environments[ $this->current_environment ] ?? array();
	}

	/**
	 * Detect current environment.
	 *
	 * @return string Environment name.
	 */
	private function detectEnvironment(): string {
		// Check for explicit environment setting
		if ( defined( 'WPPO_ENVIRONMENT' ) ) {
			return WPPO_ENVIRONMENT;
		}

		// Check WordPress debug mode
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			return 'development';
		}

		// Check for staging indicators
		$host = $_SERVER['HTTP_HOST'] ?? '';
		if ( strpos( $host, 'staging' ) !== false || strpos( $host, 'dev' ) !== false ) {
			return 'staging';
		}

		// Default to production
		return 'production';
	}

	/**
	 * Load environment-specific configurations.
	 */
	private function loadEnvironmentConfigurations(): void {
		$this->environments = array(
			'development' => array(
				'caching.page_cache_enabled' => false,
				'minification.minify_css'    => false,
				'minification.minify_js'     => false,
				'minification.minify_html'   => false,
			),
			'staging'     => array(
				'caching.page_cache_enabled' => true,
				'caching.cache_ttl'          => 1800,
				'minification.minify_css'    => true,
				'minification.minify_js'     => true,
			),
			'production'  => array(
				'caching.page_cache_enabled' => true,
				'caching.cache_ttl'          => 3600,
				'minification.minify_css'    => true,
				'minification.minify_js'     => true,
				'minification.minify_html'   => true,
				'minification.combine_css'   => true,
				'minification.combine_js'    => true,
			),
		);
	}
}
