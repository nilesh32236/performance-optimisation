<?php
/**
 * Legacy Main Class - Backward Compatibility Layer
 *
 * @package PerformanceOptimisation\Legacy
 * @since   2.0.0
 * @author  Nilesh Kanzariya
 * @license GPL-2.0-or-later
 * @link    https://profiles.wordpress.org/nileshkanzariya/
 */

namespace PerformanceOptimisation\Legacy;

use PerformanceOptimisation\Core\Bootstrap\Plugin;

/**
 * Legacy Main Class
 *
 * Provides backward compatibility for the old Main class structure.
 * This class acts as a bridge between the old and new architecture.
 *
 * @since 2.0.0
 */
class MainLegacy {

	/**
	 * Plugin instance.
	 *
	 * @since 2.0.0
	 * @var Plugin|null
	 */
	private static ?Plugin $_plugin_instance = null;

	/**
	 * Legacy instance.
	 *
	 * @since 2.0.0
	 * @var MainLegacy|null
	 */
	private static ?MainLegacy $_instance = null;

	/**
	 * Get the singleton instance of the legacy class.
	 *
	 * @since 2.0.0
	 *
	 * @return MainLegacy
	 */
	public static function getInstance(): MainLegacy {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Get the plugin instance.
	 *
	 * @since 2.0.0
	 *
	 * @return Plugin
	 */
	public function getPlugin(): Plugin {
		if ( null === self::$_plugin_instance ) {
			self::$_plugin_instance = Plugin::getInstance();
		}
		return self::$_plugin_instance;
	}

	/**
	 * Legacy method mapping for backward compatibility.
	 *
	 * @since 2.0.0
	 *
	 * @param string $method Method name.
	 * @param array  $args   Method arguments.
	 * @return mixed
	 */
	public function __call( string $method, array $args ) {
		// Map legacy method calls to new architecture
		switch ( $method ) {
			case 'get_instance':
				return self::getInstance();

			default:
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( "Performance Optimisation: Legacy method '{$method}' called but not implemented." );
				}
				return null;
		}
	}
}
