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

	public static function create(): bool {
		try {
			// Validate prerequisites
			if ( ! is_writable( WP_CONTENT_DIR ) ) {
				throw new \Exception( 'WP_CONTENT_DIR not writable' );
			}

			if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
				throw new \Exception( 'WP_CACHE constant is false' );
			}

			return CacheDropin::create();
		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'AdvancedCacheHandler::create failed: ' . $e->getMessage() );
			return false;
		}
	}

	public static function remove(): bool {
		try {
			return CacheDropin::remove();
		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'AdvancedCacheHandler::remove failed: ' . $e->getMessage() );
			return false;
		}
	}
}
