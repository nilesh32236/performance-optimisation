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

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Deactivate;
use PerformanceOptimise\Inc\Main;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! defined( 'QTPO_PLUGIN_PATH' ) ) {
	define( 'QTPO_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'QTPO_PLUGIN_URL' ) ) {
	define( 'QTPO_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}

require_once QTPO_PLUGIN_PATH . 'vendor/autoload.php';
require_once QTPO_PLUGIN_PATH . 'includes/class-main.php';
require_once QTPO_PLUGIN_PATH . 'includes/class-activate.php';
require_once QTPO_PLUGIN_PATH . 'includes/class-htaccess.php';
require_once QTPO_PLUGIN_PATH . 'includes/class-deactivate.php';

new Main();

register_activation_hook(
	__FILE__,
	function () {
		Activate::init();
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		Deactivate::init();
	}
);

