<?php
/**
 * Performance Optimisation main functionality.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimisation\Core\Bootstrap\Plugin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Class for Performance Optimisation.
 *
 * @since 1.0.0
 */
final class Main {

	/**
	 * The single instance of the class.
	 *
	 * @var Main|null
	 */
	private static ?Main $instance = null;

	/**
	 * Get the singleton instance of the class.
	 *
	 * @return Main
	 */
	public static function get_instance(): Main {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 */
	private function __construct() {
		$this->load_dependencies();
		$plugin = Plugin::getInstance( WPPO_PLUGIN_FILE, WPPO_VERSION );
		$plugin->initialize();
	}

	/**
	 * Load required dependencies.
	 *
	 * @since 1.0.0
	 */
	private function load_dependencies(): void {
		if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
			require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		}

		require_once WPPO_PLUGIN_PATH . 'includes/Core/Bootstrap/Plugin.php';
	}
}
