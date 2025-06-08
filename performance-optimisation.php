<?php
/**
 * Main plugin file for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 *
 * Plugin Name:       Performance Optimisation
 * Description:       Enhances WordPress site speed through various optimization techniques including caching, minification, and image optimization.
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Version:           1.0.1
 * Author:            Nilesh Kanzariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 */

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Deactivate;
use PerformanceOptimise\Inc\Main;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
if ( ! defined( 'WPPO_PLUGIN_FILE' ) ) {
	define( 'WPPO_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
	define( 'WPPO_PLUGIN_PATH', plugin_dir_path( WPPO_PLUGIN_FILE ) );
}
if ( ! defined( 'WPPO_PLUGIN_URL' ) ) {
	define( 'WPPO_PLUGIN_URL', plugin_dir_url( WPPO_PLUGIN_FILE ) );
}
if ( ! defined( 'WPPO_VERSION' ) ) {
	if ( ! function_exists( 'get_plugin_data' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	$plugin_data = get_plugin_data( WPPO_PLUGIN_FILE );
	define( 'WPPO_VERSION', $plugin_data['Version'] ?? '1.0.1' );
}


/**
 * Includes the main plugin class file and initializes the plugin.
 * This function is hooked to 'plugins_loaded' to ensure all WordPress core functions are available.
 *
 * @since 1.0.0
 */
function wppo_initialize_plugin() {
	if ( ! class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
		$main_class_file = WPPO_PLUGIN_PATH . 'includes/class-main.php';
		if ( file_exists( $main_class_file ) ) {
			require_once $main_class_file;
		} else {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Performance Optimisation: Main class file not found at ' . $main_class_file );
			}
			return;
		}
	}

	// Initialize the main plugin class.
	new Main();
}
add_action( 'plugins_loaded', 'wppo_initialize_plugin' );


/**
 * Activation hook callback function.
 * Handles tasks to be performed when the plugin is activated.
 *
 * @since 1.0.0
 */
function wppo_activate_plugin(): void {
	// Ensure the activation class file is loaded.
	$activate_class_file = WPPO_PLUGIN_PATH . 'includes/class-activate.php';
	if ( file_exists( $activate_class_file ) ) {
		require_once $activate_class_file;
		Activate::init();
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Performance Optimisation: Activation class file not found at ' . $activate_class_file );
	}
}
register_activation_hook( WPPO_PLUGIN_FILE, 'wppo_activate_plugin' );

/**
 * Deactivation hook callback function.
 * Handles tasks to be performed when the plugin is deactivated.
 *
 * @since 1.0.0
 */
function wppo_deactivate_plugin(): void {
	$deactivate_class_file = WPPO_PLUGIN_PATH . 'includes/class-deactivate.php';
	if ( file_exists( $deactivate_class_file ) ) {
		require_once $deactivate_class_file;
		Deactivate::init();
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Performance Optimisation: Deactivation class file not found at ' . $deactivate_class_file );
	}
}
register_deactivation_hook( WPPO_PLUGIN_FILE, 'wppo_deactivate_plugin' );

/**
 * Load the plugin's text domain for translation.
 * This allows the plugin to be translated into other languages.
 *
 * @since 1.0.0
 */
function wppo_load_textdomain(): void {
	load_plugin_textdomain(
		'performance-optimisation',
		false,
		dirname( plugin_basename( WPPO_PLUGIN_FILE ) ) . '/languages/'
	);
}
add_action( 'init', 'wppo_load_textdomain' );

/**
 * Add a settings link to the plugin entry in the plugins admin page.
 *
 * @since 1.0.0
 * @param array<string,string> $links Existing plugin action links.
 * @return array<string,string> Modified plugin action links.
 */
function wppo_add_settings_link( array $links ): array {
	$settings_link = '<a href="' . esc_url( admin_url( 'admin.php?page=performance-optimisation' ) ) . '">' . esc_html__( 'Settings', 'performance-optimisation' ) . '</a>';
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WPPO_PLUGIN_FILE ), 'wppo_add_settings_link' );
