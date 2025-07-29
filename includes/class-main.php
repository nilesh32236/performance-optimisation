<?php
/**
 * Performance Optimisation main functionality.
 *
 * This file includes the main class for the performance optimisation plugin,
 * which handles tasks like including necessary files, setting up hooks, and managing
 * image optimisation, JS and CSS minification, and more.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Refactor\Admin_Manager;
use PerformanceOptimise\Inc\Refactor\Asset_Manager;
use PerformanceOptimise\Inc\Refactor\Frontend_Manager;
use PerformanceOptimise\Inc\Minify;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main Class for Performance Optimisation.
 *
 * Handles the inclusion of necessary files, setup of hooks, and core functionalities
 * such as generating and invalidating dynamic static HTML.
 *
 * @since 1.0.0
 */
class Main {

	/**
	 * Options for performance optimisation settings.
	 *
	 * @var array<string, mixed>
	 * @since 1.0.0
	 */
	private array $options;

	/**
	 * Constructor.
	 *
	 * Initializes the class by including necessary files and setting up hooks.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->options = get_option( 'wppo_settings', array() );

		$this->load_dependencies();
		$this->setup_hooks();
	}

	/**
	 * Load required dependencies (classes).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function load_dependencies(): void {
		$base_path = WPPO_PLUGIN_PATH . 'includes/';
		if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/autoload.php' ) ) {
			require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		}

		$classes = array(
			'lib/class-asset-manager.php',
			'lib/class-admin-manager.php',
			'lib/class-frontend-manager.php',
			'lib/class-database.php',
			'lib/class-cdn.php',
			'lib/class-critical-css.php',
			'class-util.php',
			'class-log.php',
			'minify/class-css.php',
			'minify/class-js.php',
			'minify/class-html.php',
			'class-cache.php',
			'class-img-converter.php',
			'class-image-optimisation.php',
			'class-metabox.php',
			'class-cron.php',
			'class-rest.php',
			'class-advanced-cache-handler.php',
		);

		foreach ( $classes as $file ) {
			if ( file_exists( $base_path . $file ) ) {
				require_once $base_path . $file;
			}
		}
	}

	/**
	 * Setup WordPress hooks.
	 *
	 * Registers actions and filters used by the plugin.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	private function setup_hooks(): void {
		$asset_manager = new Asset_Manager( $this->options );
		$asset_manager->register_hooks();

		$admin_manager = new Admin_Manager( $this->options );
		$admin_manager->register_hooks();

		$frontend_manager = new Frontend_Manager( $this->options );
		$frontend_manager->register_hooks();

		$database_manager = new Database( $this->options );
		$database_manager->register_hooks();

		$cdn_manager = new CDN( $this->options );
		$cdn_manager->register_hooks();

		$critical_css_manager = new Critical_CSS( $this->options );
		$critical_css_manager->register_hooks();

		$cache_manager = new Cache();
		add_action( 'template_redirect', array( $cache_manager, 'generate_dynamic_static_html' ), 5 );
		add_action( 'save_post', array( $cache_manager, 'invalidate_dynamic_static_html' ) );

		if ( ! empty( $this->options['file_optimisation']['combineCSS'] ) && (bool) $this->options['file_optimisation']['combineCSS'] ) {
			add_action( 'wp_print_styles', array( $cache_manager, 'combine_css' ), PHP_INT_MAX - 10 );
		}

		$rest_api_handler = new Rest();
		add_action( 'rest_api_init', array( $rest_api_handler, 'register_routes' ) );

		new Metabox();
		new Cron();
	}

}
