<?php
/**
 * Performance Optimisation
 *
 * Comprehensive WordPress performance optimization plugin with caching,
 * minification, image optimization, and advanced analytics.
 *
 * @package PerformanceOptimisation
 * @since   2.0.0
 * @author  Nilesh Kanzariya
 * @license GPL-2.0-or-later
 * @link    https://wordpress.org/plugins/performance-optimisation/
 *
 * Plugin Name:       Performance Optimisation
 * Plugin URI:        https://wordpress.org/plugins/performance-optimisation/
 * Description:       Comprehensive WordPress performance optimization with caching, minification, image optimization, lazy loading, and advanced analytics dashboard. Includes setup wizard and automated recommendations.
 * Version:           2.0.0
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Tested up to:      6.4
 * Author:            Nilesh Kanzariya
 * Author URI:        https://profiles.wordpress.org/nileshkanzariya/
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 * Network:           false
 * Update URI:        https://wordpress.org/plugins/performance-optimisation/
 *
 * Performance Optimisation is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * Performance Optimisation is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Performance Optimisation. If not, see https://www.gnu.org/licenses/gpl-2.0.html.
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
define( 'WPPO_VERSION', $plugin_data['Version'] ?? '2.0.0' );

// Load Composer autoloader.
$autoloader = WPPO_PLUGIN_PATH . 'vendor/autoload.php';
if ( file_exists( $autoloader ) ) {
	require_once $autoloader;
}

use PerformanceOptimisation\Core\Bootstrap\Plugin;

/**
 * Initialize the plugin.
 * This function is hooked to 'plugins_loaded' to ensure all WordPress core functions are available.
 *
 * @since 2.0.0
 *
 * @return void
 */
function wppo_initialize_plugin(): void {
	try {
		$plugin = Plugin::get_instance( WPPO_PLUGIN_FILE, WPPO_VERSION );
		$plugin->initialize();
	} catch ( Exception $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Performance Optimisation initialization error: ' . $e->getMessage() );
		}

		// Show admin notice for initialization failure.
		add_action(
			'admin_notices',
			function () use ( $e ) {
				printf(
					'<div class="notice notice-error"><p>%s</p></div>',
					sprintf(
					/* translators: %s: Error message */
						esc_html__( 'Performance Optimisation plugin failed to initialize: %s', 'performance-optimisation' ),
						esc_html( $e->getMessage() )
					)
				);
			}
		);
	}
}
add_action( 'plugins_loaded', 'wppo_initialize_plugin' );

/**
 * Activation hook callback function.
 * Handles tasks to be performed when the plugin is activated.
 *
 * @since 2.0.0
 *
 * @return void
 */
function wppo_activate_plugin(): void {
	try {
		// Load autoloader if not already loaded
		$autoloader = WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		}

		$plugin = Plugin::get_instance( WPPO_PLUGIN_FILE, WPPO_VERSION );
		$plugin->activate();
	} catch ( Exception $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Performance Optimisation activation error: ' . $e->getMessage() );
		}

		// Deactivate plugin if activation fails
		deactivate_plugins( plugin_basename( WPPO_PLUGIN_FILE ) );
		wp_die(
			sprintf(
				/* translators: %s: Error message */
				esc_html__( 'Performance Optimisation plugin activation failed: %s', 'performance-optimisation' ),
				esc_html( $e->getMessage() )
			)
		);
	}
}
register_activation_hook( WPPO_PLUGIN_FILE, 'wppo_activate_plugin' );

/**
 * Deactivation hook callback function.
 * Handles tasks to be performed when the plugin is deactivated.
 *
 * @since 2.0.0
 *
 * @return void
 */
function wppo_deactivate_plugin(): void {
	try {
		// Load autoloader if not already loaded
		$autoloader = WPPO_PLUGIN_PATH . 'vendor/autoload.php';
		if ( file_exists( $autoloader ) ) {
			require_once $autoloader;
		}

		$plugin = Plugin::get_instance( WPPO_PLUGIN_FILE, WPPO_VERSION );
		$plugin->deactivate();
	} catch ( Exception $e ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( 'Performance Optimisation deactivation error: ' . $e->getMessage() );
		}
	}
}
register_deactivation_hook( WPPO_PLUGIN_FILE, 'wppo_deactivate_plugin' );

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
