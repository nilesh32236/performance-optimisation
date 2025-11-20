<?php
/**
 * Cache Utility
 *
 * Provides unified cache management operations for all cache types
 * with proper error handling and performance monitoring.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

use PerformanceOptimisation\Exceptions\CacheException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CacheUtil Class
 *
 * Centralized cache operations with support for multiple cache types,
 * statistics tracking, and intelligent invalidation strategies.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */
class CacheUtil {

	/**
	 * Cache types supported by the plugin.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const CACHE_TYPES = array(
		'page',
		'object',
		'minified',
		'image',
		'database',
		'all',
	);

	/**
	 * Cache directories mapping.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const CACHE_DIRECTORIES = array(
		'page'     => 'cache/wppo/page',
		'minified' => 'cache/wppo/min',
		'image'    => 'wppo',
		'database' => 'cache/wppo/db',
	);

	/**
	 * Clear cache by type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type to clear.
	 * @return bool True on success, false on failure.
	 * @throws CacheException If cache type is invalid or clearing fails.
	 */
	public static function clearCache( string $type = 'all' ): bool {
		if ( ! self::isValidCacheType( $type ) ) {
			throw new CacheException( "Invalid cache type: {$type}" );
		}

		$cleared = false;

		try {
			switch ( $type ) {
				case 'all':
					$cleared = self::clearAllCaches();
					break;

				case 'page':
					$cleared = self::clearPageCache();
					break;

				case 'object':
					$cleared = self::clearObjectCache();
					break;

				case 'minified':
					$cleared = self::clearMinifiedCache();
					break;

				case 'image':
					$cleared = self::clearImageCache();
					break;

				case 'database':
					$cleared = self::clearDatabaseCache();
					break;

				default:
					throw new CacheException( "Unsupported cache type: {$type}" );
			}

			if ( $cleared ) {
				LoggingUtil::info( 'Cache cleared successfully', array( 'type' => $type ) );

				// Fire action for other plugins/themes
				do_action( 'wppo_cache_cleared', $type );
			}

			return $cleared;

		} catch ( \Exception $e ) {
			LoggingUtil::error( "Failed to clear {$type} cache: " . $e->getMessage() );
			throw new CacheException( "Failed to clear {$type} cache", 0, $e );
		}
	}

	/**
	 * Invalidate specific cache entry.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Cache path or key to invalidate.
	 * @param string $type Cache type.
	 * @return bool True on success, false on failure.
	 */
	public static function invalidateCache( string $path, string $type = 'page' ): bool {
		try {
			switch ( $type ) {
				case 'page':
					return self::invalidatePageCache( $path );

				case 'object':
					return wp_cache_delete( $path );

				case 'minified':
					return self::invalidateMinifiedCache( $path );

				case 'image':
					return self::invalidateImageCache( $path );

				default:
					LoggingUtil::warning( "Unsupported cache invalidation type: {$type}" );
					return false;
			}
		} catch ( \Exception $e ) {
			LoggingUtil::error( "Failed to invalidate {$type} cache for path {$path}: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get cache size by type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type.
	 * @return string Formatted cache size.
	 */
	public static function getCacheSize( string $type = 'all' ): string {
		try {
			$total_size = 0;

			if ( 'all' === $type ) {
				foreach ( self::CACHE_DIRECTORIES as $cache_type => $dir ) {
					$total_size += self::calculateDirectorySize( $cache_type );
				}
			} else {
				$total_size = self::calculateDirectorySize( $type );
			}

			return FileSystemUtil::formatFileSize( $total_size );

		} catch ( \Exception $e ) {
			LoggingUtil::error( "Failed to get cache size for {$type}: " . $e->getMessage() );
			return '0 B';
		}
	}

	/**
	 * Get comprehensive cache statistics.
	 *
	 * @since 2.0.0
	 *
	 * @return array Cache statistics.
	 */
	public static function getCacheStats(): array {
		$stats = array(
			'total_size'   => 0,
			'types'        => array(),
			'last_cleared' => get_option( 'wppo_cache_last_cleared', '' ),
			'cache_hits'   => get_option( 'wppo_cache_hits', 0 ),
			'cache_misses' => get_option( 'wppo_cache_misses', 0 ),
		);

		try {
			foreach ( self::CACHE_DIRECTORIES as $type => $dir ) {
				$size       = self::calculateDirectorySize( $type );
				$file_count = self::getCacheFileCount( $type );

				$stats['types'][ $type ] = array(
					'size'           => $size,
					'formatted_size' => FileSystemUtil::formatFileSize( $size ),
					'file_count'     => $file_count,
					'enabled'        => self::isCacheEnabled( $type ),
				);

				$stats['total_size'] += $size;
			}

			$stats['formatted_total_size'] = FileSystemUtil::formatFileSize( $stats['total_size'] );
			$stats['hit_ratio']            = self::calculateHitRatio( $stats['cache_hits'], $stats['cache_misses'] );

		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to get cache stats: ' . $e->getMessage() );
		}

		return $stats;
	}

	/**
	 * Check if cache type is enabled.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type.
	 * @return bool True if enabled, false otherwise.
	 */
	public static function isCacheEnabled( string $type ): bool {
		$settings = get_option( 'wppo_settings', array() );

		switch ( $type ) {
			case 'page':
				return ! empty( $settings['caching']['page_cache_enabled'] );

			case 'object':
				return wp_using_ext_object_cache() || ! empty( $settings['caching']['object_cache_enabled'] );

			case 'minified':
				return ! empty( $settings['minification']['minify_css'] ) || ! empty( $settings['minification']['minify_js'] );

			case 'image':
				return ! empty( $settings['images']['convert_to_webp'] );

			case 'database':
				return ! empty( $settings['caching']['database_cache_enabled'] );

			default:
				return false;
		}
	}

	/**
	 * Generate cache key from data.
	 *
	 * @since 2.0.0
	 *
	 * @param mixed  $data Data to generate key from.
	 * @param string $prefix Key prefix.
	 * @return string Generated cache key.
	 */
	public static function generateCacheKey( $data, string $prefix = 'wppo' ): string {
		$serialized = is_string( $data ) ? $data : serialize( $data );
		$hash       = md5( $serialized );
		return $prefix . '_' . $hash;
	}

	/**
	 * Set cache expiry for type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type.
	 * @param int    $seconds Expiry time in seconds.
	 * @return void
	 */
	public static function setCacheExpiry( string $type, int $seconds ): void {
		$expiry_settings          = get_option( 'wppo_cache_expiry', array() );
		$expiry_settings[ $type ] = $seconds;
		update_option( 'wppo_cache_expiry', $expiry_settings );

		LoggingUtil::debug( "Cache expiry set for {$type}", array( 'seconds' => $seconds ) );
	}

	/**
	 * Get cache expiry for type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type.
	 * @return int Expiry time in seconds.
	 */
	public static function getCacheExpiry( string $type ): int {
		$expiry_settings = get_option( 'wppo_cache_expiry', array() );

		if ( isset( $expiry_settings[ $type ] ) ) {
			return (int) $expiry_settings[ $type ];
		}

		// Default expiry times
		$defaults = array(
			'page'     => 3600,      // 1 hour
			'object'   => 1800,    // 30 minutes
			'minified' => 86400, // 24 hours
			'image'    => 604800,   // 1 week
			'database' => 900,   // 15 minutes
		);

		return $defaults[ $type ] ?? 3600;
	}

	/**
	 * Purge cache by pattern.
	 *
	 * @since 2.0.0
	 *
	 * @param string $pattern Pattern to match cache keys/files.
	 * @param string $type Cache type.
	 * @return bool True on success, false on failure.
	 */
	public static function purgeCacheByPattern( string $pattern, string $type = 'page' ): bool {
		try {
			switch ( $type ) {
				case 'page':
					return self::purgePageCacheByPattern( $pattern );

				case 'object':
					return self::purgeObjectCacheByPattern( $pattern );

				case 'minified':
					return self::purgeMinifiedCacheByPattern( $pattern );

				default:
					LoggingUtil::warning( "Unsupported cache purge type: {$type}" );
					return false;
			}
		} catch ( \Exception $e ) {
			LoggingUtil::error( "Failed to purge {$type} cache by pattern {$pattern}: " . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Warm cache for specific URLs.
	 *
	 * @since 2.0.0
	 *
	 * @param array $urls URLs to warm.
	 * @return array Results of cache warming.
	 */
	public static function warmCache( array $urls ): array {
		$results = array();

		foreach ( $urls as $url ) {
			try {
				$response = wp_remote_get(
					$url,
					array(
						'timeout' => 30,
						'headers' => array(
							'User-Agent' => 'WPPO Cache Warmer',
						),
					)
				);

				if ( is_wp_error( $response ) ) {
					$results[ $url ] = array(
						'success' => false,
						'error'   => $response->get_error_message(),
					);
				} else {
					$results[ $url ] = array(
						'success'     => true,
						'status_code' => wp_remote_retrieve_response_code( $response ),
					);
				}
			} catch ( \Exception $e ) {
				$results[ $url ] = array(
					'success' => false,
					'error'   => $e->getMessage(),
				);
			}
		}

		LoggingUtil::info(
			'Cache warming completed',
			array(
				'urls'    => count( $urls ),
				'results' => $results,
			)
		);
		return $results;
	}

	/**
	 * Clear all cache types.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clearAllCaches(): bool {
		$success = true;

		foreach ( array_keys( self::CACHE_DIRECTORIES ) as $type ) {
			if ( ! self::clearCache( $type ) ) {
				$success = false;
			}
		}

		// Clear WordPress object cache
		if ( ! self::clearObjectCache() ) {
			$success = false;
		}

		// Update last cleared timestamp
		update_option( 'wppo_cache_last_cleared', current_time( 'mysql' ) );

		return $success;
	}

	/**
	 * Clear page cache.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clearPageCache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['page'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear
		}

		return FileSystemUtil::deleteDirectory( $cache_dir, true );
	}

	/**
	 * Clear object cache.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clearObjectCache(): bool {
		return wp_cache_flush();
	}

	/**
	 * Clear minified assets cache.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clearMinifiedCache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['minified'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear
		}

		return FileSystemUtil::deleteDirectory( $cache_dir, true );
	}

	/**
	 * Clear image cache.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clearImageCache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['image'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear
		}

		return FileSystemUtil::deleteDirectory( $cache_dir, true );
	}

	/**
	 * Clear database cache.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clearDatabaseCache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['database'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear
		}

		return FileSystemUtil::deleteDirectory( $cache_dir, true );
	}

	/**
	 * Validate cache type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type to validate.
	 * @return bool True if valid, false otherwise.
	 */
	private static function isValidCacheType( string $type ): bool {
		return in_array( $type, self::CACHE_TYPES, true );
	}

	/**
	 * Calculate directory size for cache type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type.
	 * @return int Directory size in bytes.
	 */
	private static function calculateDirectorySize( string $type ): int {
		if ( ! isset( self::CACHE_DIRECTORIES[ $type ] ) ) {
			return 0;
		}

		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES[ $type ] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return 0;
		}

		return FileSystemUtil::getDirectorySize( $cache_dir );
	}

	/**
	 * Get cache file count for type.
	 *
	 * @since 2.0.0
	 *
	 * @param string $type Cache type.
	 * @return int Number of cache files.
	 */
	private static function getCacheFileCount( string $type ): int {
		if ( ! isset( self::CACHE_DIRECTORIES[ $type ] ) ) {
			return 0;
		}

		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES[ $type ] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return 0;
		}

		$files = FileSystemUtil::getFilesInDirectory( $cache_dir, true );
		return count( $files );
	}

	/**
	 * Calculate cache hit ratio.
	 *
	 * @since 2.0.0
	 *
	 * @param int $hits Cache hits.
	 * @param int $misses Cache misses.
	 * @return float Hit ratio as percentage.
	 */
	private static function calculateHitRatio( int $hits, int $misses ): float {
		$total = $hits + $misses;

		if ( 0 === $total ) {
			return 0.0;
		}

		return round( ( $hits / $total ) * 100, 2 );
	}

	/**
	 * Invalidate specific page cache.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Page path or URL.
	 * @return bool True on success, false on failure.
	 */
	private static function invalidatePageCache( string $path ): bool {
		$cache_key  = self::generateCacheKey( $path, 'page' );
		$cache_file = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['page'] . '/' . $cache_key . '.html' );

		if ( FileSystemUtil::fileExists( $cache_file ) ) {
			return FileSystemUtil::deleteFile( $cache_file );
		}

		return true; // File doesn't exist, consider it invalidated
	}

	/**
	 * Invalidate specific minified cache.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Asset path.
	 * @return bool True on success, false on failure.
	 */
	private static function invalidateMinifiedCache( string $path ): bool {
		$cache_key = self::generateCacheKey( $path, 'min' );
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['minified'] );

		// Look for files matching the pattern
		$pattern = $cache_dir . '/' . $cache_key . '*';
		$files   = glob( $pattern );

		$success = true;
		foreach ( $files as $file ) {
			if ( ! FileSystemUtil::deleteFile( $file ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Invalidate specific image cache.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Image path.
	 * @return bool True on success, false on failure.
	 */
	private static function invalidateImageCache( string $path ): bool {
		$cache_dir     = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['image'] );
		$relative_path = str_replace( WP_CONTENT_DIR, '', $path );
		$cache_path    = $cache_dir . $relative_path;

		if ( FileSystemUtil::fileExists( $cache_path ) ) {
			return FileSystemUtil::deleteFile( $cache_path );
		}

		return true; // File doesn't exist, consider it invalidated
	}

	/**
	 * Purge page cache by pattern.
	 *
	 * @since 2.0.0
	 *
	 * @param string $pattern Pattern to match.
	 * @return bool True on success, false on failure.
	 */
	private static function purgePageCacheByPattern( string $pattern ): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['page'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true;
		}

		$files   = glob( $cache_dir . '/' . $pattern );
		$success = true;

		foreach ( $files as $file ) {
			if ( ! FileSystemUtil::deleteFile( $file ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Purge object cache by pattern.
	 *
	 * @param string $pattern Cache key pattern to purge.
	 * @return bool True on success, false on failure.
	 */
	public static function purgeObjectCacheByPattern( string $pattern ): bool {
		global $wp_object_cache;

		if ( ! $wp_object_cache || ! method_exists( $wp_object_cache, 'flush_group' ) ) {
			// Fallback: flush entire cache if pattern purging not supported
			return wp_cache_flush();
		}

		try {
			// For Redis/Memcached with pattern support
			if ( method_exists( $wp_object_cache, 'delete_by_pattern' ) ) {
				return $wp_object_cache->delete_by_pattern( $pattern );
			}

			// Manual pattern matching for basic object cache
			$cache_keys = wp_cache_get( '_cache_keys_registry', 'wppo' ) ?: array();
			$purged     = 0;

			foreach ( $cache_keys as $key ) {
				if ( fnmatch( $pattern, $key ) ) {
					wp_cache_delete( $key );
					++$purged;
				}
			}

			LoggingUtil::info( "Purged {$purged} cache keys matching pattern: {$pattern}" );
			return $purged > 0;

		} catch ( Exception $e ) {
			LoggingUtil::error( 'Cache pattern purge failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Purge minified cache by pattern.
	 *
	 * @since 2.0.0
	 *
	 * @param string $pattern Pattern to match.
	 * @return bool True on success, false on failure.
	 */
	private static function purgeMinifiedCacheByPattern( string $pattern ): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['minified'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true;
		}

		$files   = glob( $cache_dir . '/' . $pattern );
		$success = true;

		foreach ( $files as $file ) {
			if ( ! FileSystemUtil::deleteFile( $file ) ) {
				$success = false;
			}
		}

		return $success;
	}
}
