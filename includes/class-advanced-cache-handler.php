<?php
/**
 * Advanced_Cache_Handler class for the PerformanceOptimise plugin.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimisation\Core\Cache\CacheDropin;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler' ) ) {
	/**
	 * Class Advanced_Cache_Handler
	 *
	 * @since 1.0.0
	 */
	class Advanced_Cache_Handler {

		public static function create(): void {
			CacheDropin::create();
		}

		public static function remove(): void {
			CacheDropin::remove();
		}
	}
}
