<?php
/**
 * File Cache Provider
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Core\Cache;

use PerformanceOptimisation\Interfaces\CacheInterface;
use PerformanceOptimisation\Interfaces\ConfigInterface;
use PerformanceOptimisation\Exceptions\CacheException;

/**
 * File-based cache implementation
 *
 * @since 1.1.0
 */
class FileCache implements CacheInterface {

	/**
	 * Cache directory path
	 *
	 * @since 1.1.0
	 * @var string
	 */
	private string $cache_dir;

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
	 * @param ConfigInterface $config Configuration manager.
	 * @throws CacheException If cache directory cannot be created.
	 */
	public function __construct( ConfigInterface $config ) {
		$this->config    = $config;
		$this->cache_dir = $this->get_cache_directory();
		$this->ensure_cache_directory();
	}

	/**
	 * Get a cached value
	 *
	 * @since 1.1.0
	 * @param string $key     Cache key.
	 * @param mixed  $default Default value if key doesn't exist.
	 * @return mixed Cached value or default
	 */
	public function get( string $key, $default = null ) {
		$file_path = $this->get_cache_file_path( $key );

		if ( ! file_exists( $file_path ) ) {
			++$this->stats['misses'];
			return $default;
		}

		$data = $this->read_cache_file( $file_path );

		if ( false === $data ) {
			++$this->stats['misses'];
			return $default;
		}

		// Check expiration.
		if ( $data['expires'] > 0 && $data['expires'] < time() ) {
			$this->delete( $key );
			++$this->stats['misses'];
			return $default;
		}

		++$this->stats['hits'];
		return $data['value'];
	}

	/**
	 * Set a cached value
	 *
	 * @since 1.1.0
	 * @param string $key        Cache key.
	 * @param mixed  $value      Value to cache.
	 * @param int    $expiration Expiration time in seconds (0 = no expiration).
	 * @return bool True on success, false on failure
	 */
	public function set( string $key, $value, int $expiration = 0 ): bool {
		$file_path = $this->get_cache_file_path( $key );
		$expires   = $expiration > 0 ? time() + $expiration : 0;

		$data = array(
			'key'     => $key,
			'value'   => $value,
			'expires' => $expires,
			'created' => time(),
		);

		$result = $this->write_cache_file( $file_path, $data );

		if ( $result ) {
			++$this->stats['sets'];
		}

		return $result;
	}

	/**
	 * Delete a cached value
	 *
	 * @since 1.1.0
	 * @param string $key Cache key.
	 * @return bool True on success, false on failure
	 */
	public function delete( string $key ): bool {
		$file_path = $this->get_cache_file_path( $key );

		if ( ! file_exists( $file_path ) ) {
			return true;
		}

		$result = unlink( $file_path );

		if ( $result ) {
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
	public function has( string $key ): bool {
		$file_path = $this->get_cache_file_path( $key );

		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$data = $this->read_cache_file( $file_path );

		if ( false === $data ) {
			return false;
		}

		// Check expiration.
		if ( $data['expires'] > 0 && $data['expires'] < time() ) {
			$this->delete( $key );
			return false;
		}

		return true;
	}

	/**
	 * Clear all cached values
	 *
	 * @since 1.1.0
	 * @return bool True on success, false on failure
	 */
	public function flush(): bool {
		try {
			$pattern = $this->cache_dir . '*.cache';
			$files   = glob( $pattern );

			if ( $files === false ) {
				throw new CacheException( "Failed to list cache files in {$this->cache_dir}" );
			}

			if ( empty( $files ) ) {
				return true; // No files to delete
			}

			$failed_deletions = 0;
			foreach ( $files as $file ) {
				if ( ! unlink( $file ) ) {
					++$failed_deletions;
					\PerformanceOptimisation\Utils\LoggingUtil::error( "Failed to delete cache file: {$file}" );
				}
			}

			// Consider it successful if most files were deleted
			return $failed_deletions < ( count( $files ) / 2 );

		} catch ( \Exception $e ) {
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'Cache flush failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Get multiple cached values
	 *
	 * @since 1.1.0
	 * @param array $keys Array of cache keys
	 * @return array Array of key => value pairs
	 */
	public function get_multiple( array $keys ): array {
		$results = array();

		foreach ( $keys as $key ) {
			$results[ $key ] = $this->get( $key );
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
	public function set_multiple( array $data, int $expiration = 0 ): bool {
		$success = true;

		foreach ( $data as $key => $value ) {
			if ( ! $this->set( $key, $value, $expiration ) ) {
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
	public function delete_multiple( array $keys ): bool {
		$success = true;

		foreach ( $keys as $key ) {
			if ( ! $this->delete( $key ) ) {
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
	public function increment( string $key, int $offset = 1 ) {
		$current_value = $this->get( $key, 0 );

		if ( ! is_numeric( $current_value ) ) {
			return false;
		}

		$new_value = (int) $current_value + $offset;

		if ( $this->set( $key, $new_value ) ) {
			return $new_value;
		}

		return false;
	}

	/**
	 * Decrement a numeric cache value
	 *
	 * @since 1.1.0
	 * @param string $key    Cache key
	 * @param int    $offset Decrement offset
	 * @return int|false New value on success, false on failure
	 */
	public function decrement( string $key, int $offset = 1 ) {
		return $this->increment( $key, -$offset );
	}

	/**
	 * Get cache statistics
	 *
	 * @since 1.1.0
	 * @return array Cache statistics
	 */
	public function get_stats(): array {
		$cache_files = glob( $this->cache_dir . '*.cache' );
		$total_files = is_array( $cache_files ) ? count( $cache_files ) : 0;
		$total_size  = 0;

		if ( is_array( $cache_files ) ) {
			foreach ( $cache_files as $file ) {
				$total_size += filesize( $file );
			}
		}

		return array_merge(
			$this->stats,
			array(
				'total_files' => $total_files,
				'total_size'  => $total_size,
				'cache_dir'   => $this->cache_dir,
			)
		);
	}

	/**
	 * Get cache directory path
	 *
	 * @since 1.1.0
	 * @return string Cache directory path
	 */
	private function get_cache_directory(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . 'wppo-cache/';
	}

	/**
	 * Ensure cache directory exists
	 *
	 * @since 1.1.0
	 * @return void
	 * @throws CacheException If directory cannot be created
	 */
	private function ensure_cache_directory(): void {
		if ( ! file_exists( $this->cache_dir ) ) {
			if ( ! wp_mkdir_p( $this->cache_dir ) ) {
				throw new CacheException( "Cannot create cache directory: {$this->cache_dir}" );
			}
		}

		if ( ! is_writable( $this->cache_dir ) ) {
			throw new CacheException( "Cache directory is not writable: {$this->cache_dir}" );
		}

		// Create comprehensive .htaccess file
		$htaccess_file = $this->cache_dir . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$htaccess_content = <<<'HTACCESS'
# Performance Optimisation Cache Protection
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>

# Prevent script execution
<Files "*.php">
    Require all denied
</Files>

# Prevent access to cache files
<Files "*.cache">
    Require all denied
</Files>
HTACCESS;

			if ( file_put_contents( $htaccess_file, $htaccess_content ) === false ) {
				throw new CacheException( 'Failed to create .htaccess protection' );
			}
		}

		// Create index.php for additional protection
		$index_file = $this->cache_dir . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '<?php // Silence is golden' );
		}
	}

	/**
	 * Get cache file path for a key
	 *
	 * @since 1.1.0
	 * @param string $key Cache key
	 * @return string Cache file path
	 */
	private function get_cache_file_path( string $key ): string {
		// Validate key
		if ( empty( $key ) || strlen( $key ) > 250 ) {
			throw new CacheException( 'Invalid cache key length' );
		}

		// Prevent directory traversal
		if ( strpos( $key, '..' ) !== false || strpos( $key, '/' ) !== false || strpos( $key, '\\' ) !== false ) {
			throw new CacheException( 'Invalid cache key characters' );
		}

		$hash = hash( 'sha256', $key ); // More secure than md5
		return $this->cache_dir . $hash . '.cache';
	}

	/**
	 * Read cache file
	 *
	 * @since 1.1.0
	 * @param string $file_path Cache file path
	 * @return array|false Cache data or false on failure
	 */
	private function read_cache_file( string $file_path ) {
		$content = file_get_contents( $file_path );

		if ( false === $content ) {
			return false;
		}

		// Validate content size before unserialization
		if ( strlen( $content ) > 10485760 ) { // 10MB limit
			return false;
		}

		try {
			$data = unserialize( $content, array( 'allowed_classes' => false ) );
		} catch ( \Exception $e ) {
			// Log and remove corrupted file
			\PerformanceOptimisation\Utils\LoggingUtil::error( 'Corrupted cache file: ' . $file_path );
			unlink( $file_path );
			return false;
		}

		if ( ! is_array( $data ) || ! isset( $data['key'], $data['value'], $data['expires'] ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Write cache file
	 *
	 * @since 1.1.0
	 * @param string $file_path Cache file path
	 * @param array  $data      Cache data
	 * @return bool True on success, false on failure
	 */
	private function write_cache_file( string $file_path, array $data ): bool {
		$content = serialize( $data );
		return false !== file_put_contents( $file_path, $content, LOCK_EX );
	}
}
