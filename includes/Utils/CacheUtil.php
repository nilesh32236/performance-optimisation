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
	public static function clear_cache( string $type = 'all' ): bool {
		if ( ! self::is_valid_cache_type( $type ) ) {
			throw new CacheException( esc_html( "Invalid cache type: {$type}" ) );
		}

		$cleared = false;

		try {
			switch ( $type ) {
				case 'all':
					$cleared = self::clear_all_caches();
					break;

				case 'page':
					$cleared = self::clear_page_cache();
					break;

				case 'object':
					$cleared = self::clear_object_cache();
					break;

				case 'minified':
					$cleared = self::clear_minified_cache();
					break;

				case 'image':
					$cleared = self::clear_image_cache();
					break;

				case 'database':
					$cleared = self::clear_database_cache();
					break;

				default:
					throw new CacheException( "Unsupported cache type: {$type}" );
			}

			if ( $cleared ) {
				LoggingUtil::info( 'Cache cleared successfully', array( 'type' => $type ) );

				// Fire action for other plugins/themes.
				do_action( 'wppo_cache_cleared', $type );
			}

			return $cleared;
		} catch ( \Exception $e ) {
			LoggingUtil::error( "Failed to clear {$type} cache: " . $e->getMessage() );
			// Re-throw with escaped message; original exception for debugging context.
			throw new CacheException(
				esc_html( "Failed to clear {$type} cache" ),
				0,
				$e // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped
			);
		}
	}

	/**
	 * Get cached data.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key  Cache key.
	 * @param string $type Cache type.
	 * @return mixed Cached data or false if not found/expired.
	 */
	public static function get( string $key, string $type = 'page' ) {
		if ( ! self::is_valid_cache_type( $type ) ) {
			return false;
		}

		// Handle object cache separately.
		if ( 'object' === $type ) {
			return wp_cache_get( $key, 'wppo' );
		}

		// File-based cache.
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES[ $type ] );
		$file_ext  = 'minified' === $type ? ( strpos( $key, 'css' ) !== false ? '.css' : '.js' ) : '.html';

		// For minified assets, the key might already include the hash/filename.
		// If the key doesn't look like a filename, key cleanup is handled by naming convention.

		$cache_file = $cache_dir . '/' . $key . $file_ext;

		// If minified, we might be looking for a file that was saved with a specific name.
		if ( 'minified' === $type ) {
			$cache_file = $cache_dir . '/' . $key; // Key is expected to be relative path/filename.
		}

		if ( ! FileSystemUtil::fileExists( $cache_file ) ) {
			return false;
		}

		// Check expiry.
		if ( filemtime( $cache_file ) < ( time() - self::get_cache_expiry( $type ) ) ) {
			FileSystemUtil::deleteFile( $cache_file );
			return false;
		}

		return FileSystemUtil::readFile( $cache_file );
	}

	/**
	 * Set cached data.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key    Cache key.
	 * @param mixed  $data   Data to cache.
	 * @param string $type   Cache type.
	 * @param int    $expiry Expiry time in seconds (0 for default).
	 * @return bool True on success, false on failure.
	 */
	public static function set( string $key, $data, string $type = 'page', int $expiry = 0 ): bool {
		if ( ! self::is_valid_cache_type( $type ) ) {
			return false;
		}

		// Handle object cache separately.
		if ( 'object' === $type ) {
			$cache_expiry = $expiry > 0 ? $expiry : self::get_cache_expiry( $type );
			return wp_cache_set( $key, $data, 'wppo', $cache_expiry );
		}

		// File-based cache.
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES[ $type ] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			if ( ! wp_mkdir_p( $cache_dir ) ) {
				return false;
			}
		}

		$file_ext = '.html';
		if ( 'minified' === $type ) {
			// For minified, key is usually the filename.
			$cache_file = $cache_dir . '/' . $key;
		} else {
			$cache_file = $cache_dir . '/' . $key . $file_ext;
		}

		return FileSystemUtil::writeFile( $cache_file, $data );
	}
	/**
	 * Invalidate cache for a specific path.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Path or URL to invalidate.
	 * @param string $type Cache type.
	 * @return bool True on success, false on failure.
	 */
	public static function invalidate_cache( string $path, string $type = 'page' ): bool {
		try {
			switch ( $type ) {
				case 'page':
					return self::invalidate_page_cache( $path );

				case 'object':
					return wp_cache_delete( $path );

				case 'minified':
					return self::invalidate_minified_cache( $path );

				case 'image':
					return self::invalidate_image_cache( $path );

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
	public static function get_cache_size( string $type = 'all' ): string {
		try {
			$total_size = 0;

			if ( 'all' === $type ) {
				foreach ( self::CACHE_DIRECTORIES as $cache_type => $dir ) {
					$total_size += self::calculate_directory_size( $cache_type );
				}
			} else {
				$total_size = self::calculate_directory_size( $type );
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
	public static function get_cache_stats(): array {
		$stats = array(
			'total_size'   => 0,
			'types'        => array(),
			'last_cleared' => get_option( 'wppo_cache_last_cleared', '' ),
			'cache_hits'   => get_option( 'wppo_cache_hits', 0 ),
			'cache_misses' => get_option( 'wppo_cache_misses', 0 ),
		);

		try {
			foreach ( self::CACHE_DIRECTORIES as $type => $dir ) {
				$size       = self::calculate_directory_size( $type );
				$file_count = self::get_cache_file_count( $type );

				$stats['types'][ $type ] = array(
					'size'           => $size,
					'formatted_size' => FileSystemUtil::formatFileSize( $size ),
					'file_count'     => $file_count,
					'enabled'        => self::is_cache_enabled( $type ),
				);

				$stats['total_size'] += $size;
			}

			$stats['formatted_total_size'] = FileSystemUtil::formatFileSize( $stats['total_size'] );
			$stats['hit_ratio']            = self::calculate_hit_ratio( $stats['cache_hits'], $stats['cache_misses'] );
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
	public static function is_cache_enabled( string $type ): bool {
		$settings = get_option( 'wppo_settings', array() );

		switch ( $type ) {
			case 'page':
				return ! empty( $settings['caching']['page_cache_enabled'] );

			case 'object':
				return wp_using_ext_object_cache() || ! empty( $settings['caching']['object_cache_enabled'] );

			case 'minified':
				$minify_css = ! empty( $settings['minification']['minify_css'] );
				$minify_js  = ! empty( $settings['minification']['minify_js'] );
				return $minify_css || $minify_js;

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
	public static function generate_cache_key( $data, string $prefix = 'wppo' ): string {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Required for generating consistent hash keys.
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
	public static function set_cache_expiry( string $type, int $seconds ): void {
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
	public static function get_cache_expiry( string $type ): int {
		$expiry_settings = get_option( 'wppo_cache_expiry', array() );

		if ( isset( $expiry_settings[ $type ] ) ) {
			return (int) $expiry_settings[ $type ];
		}

		// Default expiry times.
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
	public static function purge_cache_by_pattern( string $pattern, string $type = 'page' ): bool {
		try {
			switch ( $type ) {
				case 'page':
					return self::purge_page_cache_by_pattern( $pattern );

				case 'object':
					return self::purge_object_cache_by_pattern( $pattern );

				case 'minified':
					return self::purge_minified_cache_by_pattern( $pattern );

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
	public static function warm_cache( array $urls ): array {
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
	private static function clear_all_caches(): bool {
		$success = true;

		foreach ( array_keys( self::CACHE_DIRECTORIES ) as $type ) {
			if ( ! self::clear_cache( $type ) ) {
				$success = false;
			}
		}

		// Clear WordPress object cache.
		if ( ! self::clear_object_cache() ) {
			$success = false;
		}

		// Update last cleared timestamp.
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
	private static function clear_page_cache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['page'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear.
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
	private static function clear_object_cache(): bool {
		return wp_cache_flush();
	}

	/**
	 * Clear minified assets cache.
	 *
	 * @since 2.0.0
	 *
	 * @return bool True on success, false on failure.
	 */
	private static function clear_minified_cache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['minified'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear.
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
	private static function clear_image_cache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['image'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear.
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
	private static function clear_database_cache(): bool {
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['database'] );

		if ( ! FileSystemUtil::fileExists( $cache_dir ) ) {
			return true; // No cache to clear.
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
	private static function is_valid_cache_type( string $type ): bool {
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
	private static function calculate_directory_size( string $type ): int {
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
	private static function get_cache_file_count( string $type ): int {
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
	private static function calculate_hit_ratio( int $hits, int $misses ): float {
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
	private static function invalidate_page_cache( string $path ): bool {
		$cache_key  = self::generate_cache_key( $path, 'page' );
		$cache_dir  = self::CACHE_DIRECTORIES['page'];
		$cache_file = wp_normalize_path( WP_CONTENT_DIR . '/' . $cache_dir . '/' . $cache_key . '.html' );

		if ( FileSystemUtil::fileExists( $cache_file ) ) {
			return FileSystemUtil::deleteFile( $cache_file );
		}

		return true; // File doesn't exist, consider it invalidated.
	}

	/**
	 * Invalidate specific minified cache.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Asset path.
	 * @return bool True on success, false on failure.
	 */
	private static function invalidate_minified_cache( string $path ): bool {
		$cache_key = self::generate_cache_key( $path, 'min' );
		$cache_dir = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['minified'] );

		// Look for files matching the pattern.
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
	private static function invalidate_image_cache( string $path ): bool {
		$cache_dir     = wp_normalize_path( WP_CONTENT_DIR . '/' . self::CACHE_DIRECTORIES['image'] );
		$relative_path = str_replace( WP_CONTENT_DIR, '', $path );
		$cache_path    = $cache_dir . $relative_path;

		if ( FileSystemUtil::fileExists( $cache_path ) ) {
			return FileSystemUtil::deleteFile( $cache_path );
		}

		return true; // File doesn't exist, consider it invalidated.
	}

	/**
	 * Purge page cache by pattern.
	 *
	 * @since 2.0.0
	 *
	 * @param string $pattern Pattern to match.
	 * @return bool True on success, false on failure.
	 */
	private static function purge_page_cache_by_pattern( string $pattern ): bool {
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
	public static function purge_object_cache_by_pattern( string $pattern ): bool {
		global $wp_object_cache;

		if ( ! $wp_object_cache || ! method_exists( $wp_object_cache, 'flush_group' ) ) {
			// Fallback: flush entire cache if pattern purging not supported.
			return wp_cache_flush();
		}

		try {
			// For Redis/Memcached with pattern support.
			if ( method_exists( $wp_object_cache, 'delete_by_pattern' ) ) {
				return $wp_object_cache->delete_by_pattern( $pattern );
			}

			// Manual pattern matching for basic object cache.
			$cache_keys = wp_cache_get( '_cache_keys_registry', 'wppo' );
			$cache_keys = is_array( $cache_keys ) ? $cache_keys : array();
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
	private static function purge_minified_cache_by_pattern( string $pattern ): bool {
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
