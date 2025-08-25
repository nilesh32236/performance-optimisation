<?php
/**
 * Cache Manager
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Cache;

use PerformanceOptimisation\Interfaces\CacheInterface;
use PerformanceOptimisation\Interfaces\ConfigInterface;
use PerformanceOptimisation\Exceptions\CacheException;

/**
 * Cache management class
 *
 * @since 1.1.0
 */
class CacheManager {

	/**
	 * Cache providers
	 *
	 * @since 1.1.0
	 * @var array
	 */
	private array $providers = array();

	/**
	 * Default cache provider
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $default_provider = 'file';

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
		'hits'    => 0,
		'misses'  => 0,
		'sets'    => 0,
		'deletes' => 0,
	);

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param ConfigInterface $config Configuration manager
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config = $config;
		$this->register_default_providers();
	}

	/**
	 * Register a cache provider
	 *
	 * @since 1.1.0
	 * @param string         $name     Provider name
	 * @param CacheInterface $provider Provider instance
	 * @return void
	 */
	public function register_provider( string $name, CacheInterface $provider ): void {
		$this->providers[ $name ] = $provider;
	}

	/**
	 * Get a cache provider
	 *
	 * @since 1.1.0
	 * @param string|null $name Provider name (null for default)
	 * @return CacheInterface Cache provider
	 * @throws CacheException If provider not found
	 */
	public function get_provider( ?string $name = null ): CacheInterface {
		$provider_name = $name ?? $this->default_provider;

		if ( ! isset( $this->providers[ $provider_name ] ) ) {
			throw new CacheException( "Cache provider '{$provider_name}' not found." );
		}

		return $this->providers[ $provider_name ];
	}

	/**
	 * Set default cache provider
	 *
	 * @since 1.1.0
	 * @param string $name Provider name
	 * @return void
	 * @throws CacheException If provider not found
	 */
	public function set_default_provider( string $name ): void {
		if ( ! isset( $this->providers[ $name ] ) ) {
			throw new CacheException( "Cache provider '{$name}' not found." );
		}

		$this->default_provider = $name;
	}

	/**
	 * Get a cached value
	 *
	 * @since 1.1.0
	 * @param string      $key      Cache key
	 * @param mixed       $default  Default value if key doesn't exist
	 * @param string|null $provider Provider name (null for default)
	 * @return mixed Cached value or default
	 */
	public function get( string $key, $default = null, ?string $provider = null ) {
		try {
			$cache_provider = $this->get_provider( $provider );
			$value          = $cache_provider->get( $key, $default );

			if ( $value !== $default ) {
				++$this->stats['hits'];
			} else {
				++$this->stats['misses'];
			}

			return $value;
		} catch ( CacheException $e ) {
			++$this->stats['misses'];
			return $default;
		}
	}

	/**
	 * Set a cached value
	 *
	 * @since 1.1.0
	 * @param string      $key        Cache key
	 * @param mixed       $value      Value to cache
	 * @param int         $expiration Expiration time in seconds (0 = no expiration)
	 * @param string|null $provider   Provider name (null for default)
	 * @return bool True on success, false on failure
	 */
	public function set( string $key, $value, int $expiration = 0, ?string $provider = null ): bool {
		try {
			$cache_provider = $this->get_provider( $provider );
			$result         = $cache_provider->set( $key, $value, $expiration );

			if ( $result ) {
				++$this->stats['sets'];
			}

			return $result;
		} catch ( CacheException $e ) {
			return false;
		}
	}

	/**
	 * Delete a cached value
	 *
	 * @since 1.1.0
	 * @param string      $key      Cache key
	 * @param string|null $provider Provider name (null for default)
	 * @return bool True on success, false on failure
	 */
	public function delete( string $key, ?string $provider = null ): bool {
		try {
			$cache_provider = $this->get_provider( $provider );
			$result         = $cache_provider->delete( $key );

			if ( $result ) {
				++$this->stats['deletes'];
			}

			return $result;
		} catch ( CacheException $e ) {
			return false;
		}
	}

	/**
	 * Check if a cache key exists
	 *
	 * @since 1.1.0
	 * @param string      $key      Cache key
	 * @param string|null $provider Provider name (null for default)
	 * @return bool True if key exists, false otherwise
	 */
	public function has( string $key, ?string $provider = null ): bool {
		try {
			$cache_provider = $this->get_provider( $provider );
			return $cache_provider->has( $key );
		} catch ( CacheException $e ) {
			return false;
		}
	}

	/**
	 * Clear all cached values
	 *
	 * @since 1.1.0
	 * @param string|null $provider Provider name (null for default)
	 * @return bool True on success, false on failure
	 */
	public function flush( ?string $provider = null ): bool {
		try {
			$cache_provider = $this->get_provider( $provider );
			return $cache_provider->flush();
		} catch ( CacheException $e ) {
			return false;
		}
	}

	/**
	 * Warm cache with predefined data
	 *
	 * @since 1.1.0
	 * @param array       $data     Array of key => value pairs
	 * @param int         $expiration Expiration time in seconds
	 * @param string|null $provider Provider name (null for default)
	 * @return bool True on success, false on failure
	 */
	public function warm( array $data, int $expiration = 0, ?string $provider = null ): bool {
		try {
			$cache_provider = $this->get_provider( $provider );
			return $cache_provider->set_multiple( $data, $expiration );
		} catch ( CacheException $e ) {
			return false;
		}
	}

	/**
	 * Invalidate cache by pattern
	 *
	 * @since 1.1.0
	 * @param string      $pattern  Cache key pattern (supports wildcards)
	 * @param string|null $provider Provider name (null for default)
	 * @return bool True on success, false on failure
	 */
	public function invalidate_pattern( string $pattern, ?string $provider = null ): bool {
		try {
			$cache_provider = $this->get_provider( $provider );

			// This is a simplified implementation
			// In a real implementation, you'd need to scan for matching keys
			if ( method_exists( $cache_provider, 'delete_pattern' ) ) {
				return $cache_provider->delete_pattern( $pattern );
			}

			return false;
		} catch ( CacheException $e ) {
			return false;
		}
	}

	/**
	 * Get cache statistics
	 *
	 * @since 1.1.0
	 * @return array Cache statistics
	 */
	public function get_stats(): array {
		$provider_stats = array();

		foreach ( $this->providers as $name => $provider ) {
			try {
				$provider_stats[ $name ] = $provider->get_stats();
			} catch ( CacheException $e ) {
				$provider_stats[ $name ] = array( 'error' => $e->getMessage() );
			}
		}

		return array(
			'global'    => $this->stats,
			'providers' => $provider_stats,
		);
	}

	/**
	 * Get available cache providers
	 *
	 * @since 1.1.0
	 * @return array Array of provider names
	 */
	public function get_available_providers(): array {
		return array_keys( $this->providers );
	}

	/**
	 * Check if caching is enabled
	 *
	 * @since 1.1.0
	 * @return bool True if caching is enabled, false otherwise
	 */
	public function is_enabled(): bool {
		return $this->config->get( 'caching.page_cache_enabled', false );
	}

	/**
	 * Register default cache providers
	 *
	 * @since 1.1.0
	 * @return void
	 */
	private function register_default_providers(): void {
		// Register file cache provider
		$this->register_provider( 'file', new FileCache( $this->config ) );

		// Register object cache provider if available
		if ( wp_using_ext_object_cache() ) {
			$this->register_provider( 'object', new ObjectCache( $this->config ) );
			$this->default_provider = 'object';
		}
	}
}
