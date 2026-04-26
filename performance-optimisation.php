<?php
/**
 * Plugin Name:       Performance Optimisation
 * Description:       A Performance Optimisation plugin for WordPress.
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Version:           1.7.0
 * Author:            Nilesh kanzariya
 * Author URI:        https://github.com/nilesh32236
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 *
 * @package PerformanceOptimise
 */

// Import required classes.
use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Deactivate;
use PerformanceOptimise\Inc\Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
	define( 'WPPO_PLUGIN_PATH', wp_normalize_path( plugin_dir_path( __FILE__ ) ) );
}

if ( ! defined( 'WPPO_PLUGIN_URL' ) ) {
	define( 'WPPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WPPO_VERSION' ) ) {
	define( 'WPPO_VERSION', '1.7.0' );
}

// Include the main class file.
require_once WPPO_PLUGIN_PATH . 'includes/class-main.php';

// Initialize the main class.
new Main();

/**
 * Run schema upgrades for already-active installs.
 *
 * Called on every page load via plugins_loaded (low priority so all plugins
 * are available). Uses a stored version option to skip the work on every
 * request — dbDelta() is only called when the stored version is behind the
 * current plugin version.
 *
 * @since 1.7.0
 * @return void
 */
function wppo_maybe_upgrade(): void {
	$stored_version = get_option( 'wppo_db_version', '0' );

	if ( version_compare( $stored_version, WPPO_VERSION, '>=' ) ) {
		return;
	}

	require_once WPPO_PLUGIN_PATH . 'includes/class-telemetry-table.php';
	\PerformanceOptimise\Inc\Telemetry_Table::create_table();

	update_option( 'wppo_db_version', WPPO_VERSION );
}
add_action( 'plugins_loaded', 'wppo_maybe_upgrade', 20 );

/**
 * Activation hook callback function.
 *
 * @since 1.0.0
 * Includes the activation class and runs the activation process.
 */
function wppo_activate(): void {
	require_once WPPO_PLUGIN_PATH . 'includes/class-activate.php';
	Activate::init();
}
register_activation_hook( __FILE__, 'wppo_activate' );

/**
 * Deactivation hook callback function.
 *
 * @since 1.0.0
 * Includes the deactivation class and runs the deactivation process.
 */
function wppo_deactivate(): void {
	require_once WPPO_PLUGIN_PATH . 'includes/class-deactivate.php';
	Deactivate::init();
}
register_deactivation_hook( __FILE__, 'wppo_deactivate' );
