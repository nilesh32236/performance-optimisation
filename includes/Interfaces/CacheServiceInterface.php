<?php
/**
 * Cache Service Interface
 *
 * @package PerformanceOptimisation\Interfaces
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface CacheServiceInterface
 *
 * @package PerformanceOptimisation\Interfaces
 */
interface CacheServiceInterface {

	/**
	 * Clear the cache.
	 *
	 * @param string $type The type of cache to clear (e.g., 'all', 'page', 'minify').
	 * @return bool True on success, false on failure.
	 */
	public function clearCache( string $type = 'all' ): bool;

	/**
	 * Get the size of the cache.
	 *
	 * @param string $type The type of cache to get the size of.
	 * @return string The size of the cache in a human-readable format.
	 */
	public function getCacheSize( string $type = 'all' ): string;

	/**
	 * Preload the cache for a given set of URLs.
	 *
	 * @param array $urls The URLs to preload.
	 * @return void
	 */
	public function preloadCache( array $urls ): void;

	/**
	 * Invalidate cache entries based on a pattern.
	 *
	 * @param string $pattern The pattern to match against cache entries.
	 * @return bool True on success, false on failure.
	 */
	public function invalidateCache( string $pattern ): bool;
}
