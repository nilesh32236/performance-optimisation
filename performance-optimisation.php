<?php
/**
 * Main plugin file for Performance Optimisation.
 *
 * @package PerformanceOptimise
 * @since 1.0.0
 *
 * Plugin Name:       Performance Optimisation
 * Description:       Enhances WordPress site speed through various optimization techniques including caching, minification, and image optimization.
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Version:           1.1.0
 * Author:            Nilesh Kanzariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Define plugin constants.
define( 'WPPO_PLUGIN_FILE', __FILE__ );
define( 'WPPO_PLUGIN_PATH', plugin_dir_path( WPPO_PLUGIN_FILE ) );
define( 'WPPO_PLUGIN_URL', plugin_dir_url( WPPO_PLUGIN_FILE ) );

if ( ! function_exists( 'get_plugin_data' ) ) {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
}
$plugin_data = get_plugin_data( WPPO_PLUGIN_FILE );
define( 'WPPO_VERSION', $plugin_data['Version'] ?? '1.1.0' );

/**
 * Includes the main plugin class file and initializes the plugin.
 * This function is hooked to 'plugins_loaded' to ensure all WordPress core functions are available.
 *
 * @since 1.0.0
 */
function wppo_initialize_plugin() {
	$main_class_file = WPPO_PLUGIN_PATH . 'includes/class-main.php';

	if ( file_exists( $main_class_file ) ) {
		require_once $main_class_file;
	} else {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( 'Performance Optimisation: Main class file not found at ' . esc_html( $main_class_file ) );
		}
		return;
	}

	// Initialize the main plugin class.
	\PerformanceOptimise\Inc\Main::get_instance();
}
add_action( 'plugins_loaded', 'wppo_initialize_plugin' );

/**
 * Activation hook callback function.
 * Handles tasks to be performed when the plugin is activated.
 *
 * @since 1.0.0
 */
function wppo_activate_plugin(): void {
	$activate_class_file = WPPO_PLUGIN_PATH . 'includes/class-activate.php';

	if ( file_exists( $activate_class_file ) ) {
		require_once $activate_class_file;
		\PerformanceOptimise\Inc\Activate::init();
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'Performance Optimisation: Activation class file not found at ' . esc_html( $activate_class_file ) );
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
		\PerformanceOptimise\Inc\Deactivate::init();
	} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( 'Performance Optimisation: Deactivation class file not found at ' . esc_html( $deactivate_class_file ) );
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
	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( admin_url( 'admin.php?page=performance-optimisation' ) ),
		esc_html__( 'Settings', 'performance-optimisation' )
	);
	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( WPPO_PLUGIN_FILE ), 'wppo_add_settings_link' );
