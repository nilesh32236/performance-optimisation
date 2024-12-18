<?php
/**
 * Plugin Name:       Performance Optimisation
 * Description:       A Performance Optimisation plugin for WordPress.
 * Requires at least: 5.5.3
 * Requires PHP:      7.0
 * Version:           1.0.0
 * Author:            Nilesh kanzariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 * Domain Path:       /languages
 */

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Deactivate;
use PerformanceOptimise\Inc\Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

// Define plugin constants.
if ( ! defined( 'WPPO_PLUGIN_PATH' ) ) {
	define( 'WPPO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'WPPO_PLUGIN_URL' ) ) {
	define( 'WPPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

if ( ! defined( 'WPPO_VERSION' ) ) {
	define( 'WPPO_VERSION', '0.1.1' );
}
// Include the main class file.
require_once WPPO_PLUGIN_PATH . 'includes/class-main.php';

// Initialize the main class.
new Main();

/**
 * Activation hook callback function.
 *
 * Includes the activation class and runs the activation process.
 *
 * @return void
 */
function wppo_activate(): void {
	require_once WPPO_PLUGIN_PATH . 'includes/class-activate.php';
	Activate::init();
}
register_activation_hook( __FILE__, 'wppo_activate' );

/**
 * Deactivation hook callback function.
 *
 * Includes the deactivation class and runs the deactivation process.
 *
 * @return void
 */
function wppo_deactivate(): void {
	require_once WPPO_PLUGIN_PATH . 'includes/class-deactivate.php';
	Deactivate::init();
}
register_deactivation_hook( __FILE__, 'wppo_deactivate' );
