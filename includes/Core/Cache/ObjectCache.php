<?php
/**
 * Object Cache Provider
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Cache;

use PerformanceOptimisation\Interfaces\CacheInterface;
use PerformanceOptimisation\Core\Config\ConfigInterface;

/**
 * WordPress object cache implementation
 *
 * @since 1.1.0
 */
class ObjectCache implements CacheInterface
{

	/**
	 * Cache group
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $cache_group = 'wppo';

	/**
	 * Configuration manager
	 *
	 * @since 1.1.0
	 * @var ConfigInterface
	 */
	private ConfigInterface $config;

	/**
	 * Cache statistics
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $stats = array(
		'hits' => 0,
		'misses' => 0,
		'sets' => 0,
		'deletes' => 0,
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param ConfigInterface $config Configuration manager
	 */
	public function __construct(ConfigInterface $config)
	{
		$this->config = $config;
	}

	/**
	 * Get a cached value
	 *
	 * @since 1.1.0
	 * @param string $key     Cache key
	 * @param mixed  $default Default value if key doesn't exist
	 * @return mixed Cached value or default
	 */
	public function get(string $key, $default = null)
	{
		$value = wp_cache_get($key, $this->cache_group);

		if (false === $value) {
			++$this->stats['misses'];
			return $default;
		}

		++$this->stats['hits'];
		return $value;
	}

	/**
	 * Set a cached value
	 *
	 * @since 1.1.0
	 * @param string $key        Cache key
	 * @param mixed  $value      Value to cache
	 * @param int    $expiration Expiration time in seconds (0 = no expiration)
	 * @return bool True on success, false on failure
	 */
	public function set(string $key, $value, int $expiration = 0): bool
	{
		$result = wp_cache_set($key, $value, $this->cache_group, $expiration);

		if ($result) {
			++$this->stats['sets'];
		}

		return $result;
	}

	/**
	 * Delete a cached value
	 *
	 * @since 1.1.0
	 * @param string $key Cache key
	 * @return bool True on success, false on failure
	 */
	public function delete(string $key): bool
	{
		$result = wp_cache_delete($key, $this->cache_group);

		if ($result) {
			++$this->stats['deletes'];
		}

		return $result;
	}

	/**
	 * Check if a cache key exists
	 *
	 * @since 1.1.0
	 * @param string $key Cache key
	 * @return bool True if key exists, false otherwise
	 */
	public function has(string $key): bool
	{
		return false !== wp_cache_get($key, $this->cache_group);
	}

	/**
	 * Clear all cached values
	 *
	 * @since 1.1.0
	 * @return bool True on success, false on failure
	 */
	public function flush(): bool
	{
		return wp_cache_flush();
	}

	/**
	 * Get multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $keys Array of cache keys
	 * @return array Array of key => value pairs
	 */
	public function get_multiple(array $keys): array
	{
		$results = array();

		foreach ($keys as $key) {
			$results[$key] = $this->get($key);
		}

		return $results;
	}

	/**
	 * Set multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $data       Array of key => value pairs
	 * @param int   $expiration Expiration time in seconds (0 = no expiration)
	 * @return bool True on success, false on failure
	 */
	public function set_multiple(array $data, int $expiration = 0): bool
	{
		$success = true;

		foreach ($data as $key => $value) {
			if (!$this->set($key, $value, $expiration)) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Delete multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $keys Array of cache keys
	 * @return bool True on success, false on failure
	 */
	public function delete_multiple(array $keys): bool
	{
		$success = true;

		foreach ($keys as $key) {
			if (!$this->delete($key)) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Increment a numeric cache value
	 *
	 * @since 1.1.0
	 * @param string $key    Cache key
	 * @param int    $offset Increment offset
	 * @return int|false New value on success, false on failure
	 */
	public function increment(string $key, int $offset = 1)
	{
		return wp_cache_incr($key, $offset, $this->cache_group);
	}

	/**
	 * Decrement a numeric cache value
	 *
	 * @since 1.1.0
	 * @param string $key    Cache key
	 * @param int    $offset Decrement offset
	 * @return int|false New value on success, false on failure
	 */
	public function decrement(string $key, int $offset = 1)
	{
		return wp_cache_decr($key, $offset, $this->cache_group);
	}

	/**
	 * Get cache statistics
	 *
	 * @since 1.1.0
	 * @return array Cache statistics
	 */
	public function get_stats(): array
	{
		$wp_object_cache_stats = array();

		// Try to get WordPress object cache stats if available
		global $wp_object_cache;
		if (isset($wp_object_cache) && method_exists($wp_object_cache, 'stats')) {
			$wp_object_cache_stats = $wp_object_cache->stats();
		}

		return array_merge(
			$this->stats,
			array(
				'wp_object_cache' => $wp_object_cache_stats,
				'cache_group' => $this->cache_group,
			)
		);
	}
}
