<?php
/**
 * AdvancedCacheHandler class for the PerformanceOptimisation plugin.
 *
 * @package PerformanceOptimisation\Core\Cache
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\Cache;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AdvancedCacheHandler
 *
 * @since 2.0.0
 */
class AdvancedCacheHandler {

	public static function create(): void {
		CacheDropin::create();
	}

	public static function remove(): void {
		CacheDropin::remove();
	}
}
