<?php
/**
 * Plugin Name:       Performance Optimisation
 * Description:       A Performance Optimisation plugin for WordPress.
 * Requires at least: 5.5.3
 * Requires PHP:      7.0
 * Version:           0.1.0
 * Author:            Nilesh kanzariya
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       performance-optimisation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! defined( 'QTPM_PLUGIN_PATH' ) ) {
	define( 'QTPM_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'QTPM_PLUGIN_URL' ) ) {
	define( 'QTPM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once QTPM_PLUGIN_PATH . 'vendor/autoload.php';
require_once QTPM_PLUGIN_PATH . 'includes/class-performance-optimisation.php';
require_once QTPM_PLUGIN_PATH . 'includes/class-activate.php';
require_once QTPM_PLUGIN_PATH . 'includes/class-htaccess.php';
require_once QTPM_PLUGIN_PATH . 'includes/class-deactivate.php';

new Performance_Optimisation();

register_activation_hook( __FILE__, array( 'Activate', 'init' ) );
register_deactivation_hook( __FILE__, array( 'Deactivate', 'init' ) );
