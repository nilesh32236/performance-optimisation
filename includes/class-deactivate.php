<?php
/**
 * Deactivate class for the PerformanceOptimise plugin.
 *
 * Handles the deactivation process by removing .htaccess modifications
 * and static files created by the plugin.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Deactivate' ) ) {
	/**
	 * Class Deactivate
	 *
	 * Handles the deactivation logic for the plugin.
	 */
	class Deactivate {

		/**
		 * Initialize the deactivation process.
		 *
		 * This method checks if the necessary classes exist before including them.
		 * Then it triggers the removal of static files and htaccess modifications.
		 *
		 * @return void
		 */
		public static function init(): void {

			// Check if the Htaccess class exists before including and using it.
			if ( ! class_exists( 'Htaccess' ) ) {
				require_once QTPO_PLUGIN_PATH . 'includes/class-htaccess.php';
			}

			// Check if the Static_File_Handler class exists before including and using it.
			if ( ! class_exists( 'Static_File_Handler' ) ) {
				require_once QTPO_PLUGIN_PATH . 'includes/class-static-file-handler.php';
			}

			// Remove the .htaccess modifications if the Htaccess class is available.
			if ( class_exists( 'Htaccess' ) ) {
				Htaccess::remove_htaccess();
			}

			// Remove static files if the Static_File_Handler class is available.
			if ( class_exists( 'Static_File_Handler' ) ) {
				Static_File_Handler::remove();
			}
		}
	}
}
