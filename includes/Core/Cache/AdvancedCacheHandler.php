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

	/**
	 * Create advanced cache drop-in.
	 *
	 * @since 2.0.0
	 * @throws \Exception When prerequisites are not met.
	 * @return bool True on success, false on failure.
	 */
	public static function create(): bool {
		try {
			// Validate prerequisites.
			if ( ! is_writable( WP_CONTENT_DIR ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
				throw new \Exception( 'WP_CONTENT_DIR not writable' );
			}

			if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
				throw new \Exception( 'WP_CACHE constant is false' );
			}

			return CacheDropin::create();
		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error(
				'AdvancedCacheHandler::create failed: ' . $e->getMessage()
			);
			return false;
		}
	}

	/**
	 * Remove advanced cache drop-in.
	 *
	 * @since 2.0.0
	 * @throws \Exception When removal fails.
	 * @return bool True on success, false on failure.
	 */
	public static function remove(): bool {
		try {
			return CacheDropin::remove();
		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error(
				'AdvancedCacheHandler::remove failed: ' . $e->getMessage()
			);
			return false;
		}
	}
}
