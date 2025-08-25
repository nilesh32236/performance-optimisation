<?php
/**
 * Cache Interface
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Interfaces;

/**
 * Interface for cache implementations
 *
 * @since 1.1.0
 */
interface CacheInterface {

	/**
	 * Get a cached value
	 *
	 * @since 1.1.0
	 * @param string $key     Cache key
	 * @param mixed  $default Default value if key doesn't exist
	 * @return mixed Cached value or default
	 */
	public function get( string $key, $default = null );

	/**
	 * Set a cached value
	 *
	 * @since 1.1.0
	 * @param string $key        Cache key
	 * @param mixed  $value      Value to cache
	 * @param int    $expiration Expiration time in seconds (0 = no expiration)
	 * @return bool True on success, false on failure
	 */
	public function set( string $key, $value, int $expiration = 0 ): bool;

	/**
	 * Delete a cached value
	 *
	 * @since 1.1.0
	 * @param string $key Cache key
	 * @return bool True on success, false on failure
	 */
	public function delete( string $key ): bool;

	/**
	 * Check if a cache key exists
	 *
	 * @since 1.1.0
	 * @param string $key Cache key
	 * @return bool True if key exists, false otherwise
	 */
	public function has( string $key ): bool;

	/**
	 * Clear all cached values
	 *
	 * @since 1.1.0
	 * @return bool True on success, false on failure
	 */
	public function flush(): bool;

	/**
	 * Get multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $keys Array of cache keys
	 * @return array Array of key => value pairs
	 */
	public function get_multiple( array $keys ): array;

	/**
	 * Set multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $data       Array of key => value pairs
	 * @param int   $expiration Expiration time in seconds (0 = no expiration)
	 * @return bool True on success, false on failure
	 */
	public function set_multiple( array $data, int $expiration = 0 ): bool;

	/**
	 * Delete multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $keys Array of cache keys
	 * @return bool True on success, false on failure
	 */
	public function delete_multiple( array $keys ): bool;

	/**
	 * Increment a numeric cache value
	 *
	 * @since 1.1.0
	 * @param string $key    Cache key
	 * @param int    $offset Increment offset
	 * @return int|false New value on success, false on failure
	 */
	public function increment( string $key, int $offset = 1 );

	/**
	 * Decrement a numeric cache value
	 *
	 * @since 1.1.0
	 * @param string $key    Cache key
	 * @param int    $offset Decrement offset
	 * @return int|false New value on success, false on failure
	 */
	public function decrement( string $key, int $offset = 1 );

	/**
	 * Get cache statistics
	 *
	 * @since 1.1.0
	 * @return array Cache statistics
	 */
	public function get_stats(): array;
}
