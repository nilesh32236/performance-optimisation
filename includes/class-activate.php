<?php
/**
 * Activate class for the PerformanceOptimise plugin.
 *
 * Handles the activation process by modifying .htaccess and creating static files.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Activate' ) ) {
	/**
	 * Class Activate
	 *
	 * Handles the activation logic for the plugin.
	 */
	class Activate {

		/**
		 * Initialize the activation process.
		 *
		 * This method checks if the necessary classes exist before including them.
		 * Then it triggers the required static file and htaccess modifications.
		 *
		 * @return void
		 */
		public static function init(): void {

			// Check if the Htaccess class exists before including the file.
			if ( ! class_exists( 'Htaccess' ) ) {
				require_once QTPO_PLUGIN_PATH . 'includes/class-htaccess.php';
			}

			// Check if the Static_File_Handler class exists before including the file.
			if ( ! class_exists( 'Static_File_Handler' ) ) {
				require_once QTPO_PLUGIN_PATH . 'includes/class-static-file-handler.php';
			}

			// Modify the .htaccess file if the Htaccess class is available.
			if ( class_exists( 'Htaccess' ) ) {
				Htaccess::modify_htaccess();
			}

			// Create static files if the Static_File_Handler class is available.
			if ( class_exists( 'Static_File_Handler' ) ) {
				Static_File_Handler::create();
			}
		}
	}
}
