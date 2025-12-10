<?php
/**
 * Enhanced File System Utility
 *
 * Provides comprehensive file system operations with proper error handling,
 * security checks, and WordPress integration.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

use PerformanceOptimisation\Exceptions\FileSystemException;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enhanced FileSystemUtil Class
 *
 * Centralized file system operations with security, error handling, and performance optimization.
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */
class FileSystemUtil {

	/**
	 * Cached filesystem instance.
	 *
	 * @since 2.0.0
	 * @var \WP_Filesystem_Base|null
	 */
	private static $filesystem = null;

	/**
	 * Maximum file size for operations (100MB).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	private const MAX_FILE_SIZE = 104857600;

	/**
	 * Allowed file extensions for security.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	private const ALLOWED_EXTENSIONS = array(
		'css',
		'js',
		'html',
		'htm',
		'json',
		'txt',
		'log',
		'jpg',
		'jpeg',
		'png',
		'gif',
		'webp',
		'avif',
		'svg',
		'php',
		'htaccess',
	);

	/**
	 * Get WordPress filesystem instance.
	 *
	 * Initializes and returns the WordPress filesystem API instance with proper error handling.
	 *
	 * @since 2.0.0
	 *
	 * @return \WP_Filesystem_Base WordPress filesystem instance.
	 * @throws FileSystemException If filesystem cannot be initialized.
	 */
	public static function getFilesystem(): \WP_Filesystem_Base {
		if ( null !== self::$filesystem ) {
			return self::$filesystem;
		}

		global $wp_filesystem;

		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';

			$credentials = request_filesystem_credentials( '', '', false, false, array() );
			if ( false === $credentials ) {
				throw new FileSystemException( 'Unable to get filesystem credentials' );
			}

			if ( ! WP_Filesystem( $credentials ) ) {
				throw new FileSystemException( 'Failed to initialize WordPress filesystem' );
			}
		}

		if ( ! $wp_filesystem || ! is_object( $wp_filesystem ) ) {
			throw new FileSystemException( 'WordPress filesystem is not available' );
		}

		self::$filesystem = $wp_filesystem;
		return self::$filesystem;
	}

	/**
	 * Check if file or directory exists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File or directory path.
	 * @return bool True if exists, false otherwise.
	 */
	public static function fileExists( string $path ): bool {
		try {
			$path = self::sanitizePath( $path );
			return self::getFilesystem()->exists( $path );
		} catch ( FileSystemException $e ) {
			LoggingUtil::error( 'FileSystem check failed: ' . $e->getMessage(), array( 'path' => $path ) );
			return false;
		}
	}

	/**
	 * Read file contents.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @return string File contents.
	 * @throws FileSystemException If file cannot be read or is too large.
	 */
	public static function readFile( string $path ): string {
		$path = self::sanitizePath( $path );

		if ( ! self::fileExists( $path ) ) {
			throw new FileSystemException( "File does not exist: {$path}" );
		}

		$file_size = self::getFileSize( $path );
		if ( $file_size > self::MAX_FILE_SIZE ) {
			throw new FileSystemException( "File too large: {$path} ({$file_size} bytes)" );
		}

		$contents = self::getFilesystem()->get_contents( $path );
		if ( false === $contents ) {
			throw new FileSystemException( "Failed to read file: {$path}" );
		}

		return $contents;
	}

	/**
	 * Write contents to file.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path     File path.
	 * @param string $contents File contents.
	 * @param bool   $backup   Whether to create backup before writing.
	 * @return bool True on success, false on failure.
	 * @throws FileSystemException If file cannot be written.
	 */
	public static function writeFile( string $path, string $contents, bool $backup = false ): bool {
		$path = self::sanitizePath( $path );

		// Validate file extension for security
		if ( ! self::isAllowedExtension( $path ) ) {
			throw new FileSystemException( "File extension not allowed: {$path}" );
		}

		// Create backup if requested and file exists
		if ( $backup && self::fileExists( $path ) ) {
			$backup_path = $path . '.backup.' . time();
			self::copyFile( $path, $backup_path );
		}

		// Ensure directory exists
		$dir = dirname( $path );
		if ( ! self::fileExists( $dir ) ) {
			self::createDirectory( $dir );
		}

		$result = self::getFilesystem()->put_contents( $path, $contents, FS_CHMOD_FILE );

		if ( ! $result ) {
			throw new FileSystemException( "Failed to write file: {$path}" );
		}

		LoggingUtil::debug(
			'File written successfully',
			array(
				'path' => $path,
				'size' => strlen( $contents ),
			)
		);
		return true;
	}

	/**
	 * Create directory recursively.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Directory path.
	 * @param int    $chmod Directory permissions.
	 * @return bool True on success, false on failure.
	 * @throws FileSystemException If directory cannot be created.
	 */
	public static function createDirectory( string $path, int $chmod = 0755 ): bool {
		$path = self::sanitizePath( $path );

		if ( self::fileExists( $path ) && self::isDirectory( $path ) ) {
			return true; // Directory already exists
		}

		$result = wp_mkdir_p( $path );

		if ( ! $result ) {
			throw new FileSystemException( "Failed to create directory: {$path}" );
		}

		// Set proper permissions
		if ( $chmod !== 0755 ) {
			self::getFilesystem()->chmod( $path, $chmod );
		}

		LoggingUtil::debug( 'Directory created successfully', array( 'path' => $path ) );
		return true;
	}

	/**
	 * Delete file safely.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @param bool   $backup Whether to create backup before deletion.
	 * @return bool True on success, false on failure.
	 * @throws FileSystemException If file cannot be deleted.
	 */
	public static function deleteFile( string $path, bool $backup = false ): bool {
		$path = self::sanitizePath( $path );

		if ( ! self::fileExists( $path ) ) {
			return true; // File doesn't exist, consider it deleted
		}

		// Create backup if requested
		if ( $backup ) {
			$backup_path = $path . '.deleted.' . time();
			self::copyFile( $path, $backup_path );
		}

		$result = self::getFilesystem()->delete( $path );

		if ( ! $result ) {
			throw new FileSystemException( "Failed to delete file: {$path}" );
		}

		LoggingUtil::debug( 'File deleted successfully', array( 'path' => $path ) );
		return true;
	}

	/**
	 * Delete directory and optionally its contents.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path      Directory path.
	 * @param bool   $recursive Whether to delete contents recursively.
	 * @return bool True on success, false on failure.
	 * @throws FileSystemException If directory cannot be deleted.
	 */
	public static function deleteDirectory( string $path, bool $recursive = false ): bool {
		$path = self::sanitizePath( $path );

		if ( ! self::fileExists( $path ) ) {
			return true; // Directory doesn't exist, consider it deleted
		}

		if ( ! self::isDirectory( $path ) ) {
			throw new FileSystemException( "Path is not a directory: {$path}" );
		}

		$result = self::getFilesystem()->rmdir( $path, $recursive );

		if ( ! $result ) {
			throw new FileSystemException( "Failed to delete directory: {$path}" );
		}

		LoggingUtil::debug(
			'Directory deleted successfully',
			array(
				'path'      => $path,
				'recursive' => $recursive,
			)
		);
		return true;
	}

	public static function getDirectorySize( string $path ): int {
		if ( ! self::fileExists( $path ) ) {
			return 0;
		}
		$size  = 0;
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $path ) );
		foreach ( $files as $file ) {
			$size += $file->getSize();
		}
		return $size;
	}

	public static function getFileModificationTime( string $path ): int {
		return self::getFilesystem()->mtime( $path );
	}

	public static function getLocalPath( string $url ): string {
		$path = str_replace( content_url(), WP_CONTENT_DIR, $url );
		return wp_normalize_path( $path );
	}

	/**
	 * Check if path is a directory.
	 *
	 * @param string $path Directory path.
	 * @return bool
	 */
	public static function isDirectory( string $path ): bool {
		return self::getFilesystem()->is_dir( $path );
	}

	/**
	 * Convert URL to local file path.
	 *
	 * @param string $url The URL to convert.
	 * @return string
	 */
	public static function urlToPath( string $url ): string {
		$site_url  = site_url();
		$site_path = wp_normalize_path( ABSPATH );

		// Handle relative URLs
		if ( strpos( $url, '//' ) === false ) {
			$url = $site_url . $url;
		}

		// Convert URL to path
		$path = str_replace( $site_url, $site_path, $url );
		return wp_normalize_path( $path );
	}

	/**
	 * Get file size.
	 *
	 * @param string $path File path.
	 * @return int
	 */
	public static function getFileSize( string $path ): int {
		if ( ! self::fileExists( $path ) ) {
			return 0;
		}
		return self::getFilesystem()->size( $path );
	}

	/**
	 * Check if file/directory is writable.
	 *
	 * @param string $path Path to check.
	 * @return bool
	 */
	public static function isWritable( string $path ): bool {
		return self::getFilesystem()->is_writable( $path );
	}

	/**
	 * Copy file.
	 *
	 * @param string $source Source file path.
	 * @param string $destination Destination file path.
	 * @return bool
	 */
	public static function copyFile( string $source, string $destination ): bool {
		return self::getFilesystem()->copy( $source, $destination );
	}

	/**
	 * Sanitize file path.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Path to sanitize.
	 * @return string Sanitized path.
	 */
	public static function sanitizePath( string $path ): string {
		// Remove any directory traversal attempts
		$path = preg_replace( '/\.\.+/', '.', $path );
		return wp_normalize_path( $path );
	}

	/**
	 * Check if file extension is allowed.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @return bool True if extension is allowed, false otherwise.
	 */
	private static function isAllowedExtension( string $path ): bool {
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		return in_array( $extension, self::ALLOWED_EXTENSIONS, true );
	}

	/**
	 * Get formatted file size.
	 *
	 * @since 2.0.0
	 *
	 * @param int $bytes File size in bytes.
	 * @return string Formatted file size.
	 */
	public static function formatFileSize( int $bytes ): string {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );

		for ( $i = 0; $bytes >= 1024 && $i < count( $units ) - 1; $i++ ) {
			$bytes /= 1024;
		}

		return round( $bytes, 2 ) . ' ' . $units[ $i ];
	}

	/**
	 * Get file extension.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @return string File extension.
	 */
	public static function getFileExtension( string $path ): string {
		return strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	}

	/**
	 * Get file name without extension.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @return string File name without extension.
	 */
	public static function getFileNameWithoutExtension( string $path ): string {
		return pathinfo( $path, PATHINFO_FILENAME );
	}

	/**
	 * Get directory name from path.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path File path.
	 * @return string Directory name.
	 */
	public static function getDirectoryName( string $path ): string {
		return dirname( $path );
	}

	/**
	 * Check if path is readable.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path Path to check.
	 * @return bool True if readable, false otherwise.
	 */
	public static function isReadable( string $path ): bool {
		try {
			return self::getFilesystem()->is_readable( $path );
		} catch ( FileSystemException $e ) {
			LoggingUtil::error( 'Failed to check if path is readable: ' . $e->getMessage(), array( 'path' => $path ) );
			return false;
		}
	}

	/**
	 * Move file from source to destination.
	 *
	 * @since 2.0.0
	 *
	 * @param string $source      Source file path.
	 * @param string $destination Destination file path.
	 * @return bool True on success, false on failure.
	 * @throws FileSystemException If file cannot be moved.
	 */
	public static function moveFile( string $source, string $destination ): bool {
		$source      = self::sanitizePath( $source );
		$destination = self::sanitizePath( $destination );

		if ( ! self::fileExists( $source ) ) {
			throw new FileSystemException( "Source file does not exist: {$source}" );
		}

		// Ensure destination directory exists
		$dest_dir = dirname( $destination );
		if ( ! self::fileExists( $dest_dir ) ) {
			self::createDirectory( $dest_dir );
		}

		$result = self::getFilesystem()->move( $source, $destination );

		if ( ! $result ) {
			throw new FileSystemException( "Failed to move file from {$source} to {$destination}" );
		}

		LoggingUtil::debug(
			'File moved successfully',
			array(
				'source'      => $source,
				'destination' => $destination,
			)
		);
		return true;
	}

	/**
	 * Get files in directory.
	 *
	 * @since 2.0.0
	 *
	 * @param string $path      Directory path.
	 * @param bool   $recursive Whether to search recursively.
	 * @param array  $extensions Allowed file extensions.
	 * @return array Array of file paths.
	 */
	public static function getFilesInDirectory( string $path, bool $recursive = false, array $extensions = array() ): array {
		$path  = self::sanitizePath( $path );
		$files = array();

		if ( ! self::fileExists( $path ) || ! self::isDirectory( $path ) ) {
			return $files;
		}

		try {
			if ( $recursive ) {
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator( $path, \RecursiveDirectoryIterator::SKIP_DOTS )
				);
			} else {
				$iterator = new \DirectoryIterator( $path );
			}

			foreach ( $iterator as $file ) {
				if ( $file->isFile() ) {
					$file_path = $file->getPathname();

					// Filter by extensions if specified
					if ( ! empty( $extensions ) ) {
						$extension = self::getFileExtension( $file_path );
						if ( ! in_array( $extension, $extensions, true ) ) {
							continue;
						}
					}

					$files[] = wp_normalize_path( $file_path );
				}
			}
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Failed to get files in directory: ' . $e->getMessage(), array( 'path' => $path ) );
		}

		return $files;
	}

	/**
	 * Create temporary file.
	 *
	 * @since 2.0.0
	 *
	 * @param string $prefix File prefix.
	 * @param string $suffix File suffix.
	 * @return string Temporary file path.
	 * @throws FileSystemException If temporary file cannot be created.
	 */
	public static function createTempFile( string $prefix = 'wppo_', string $suffix = '.tmp' ): string {
		$temp_dir  = get_temp_dir();
		$temp_file = $temp_dir . $prefix . uniqid() . $suffix;

		if ( ! self::writeFile( $temp_file, '' ) ) {
			throw new FileSystemException( "Failed to create temporary file: {$temp_file}" );
		}

		return $temp_file;
	}

	/**
	 * Clean up temporary files.
	 *
	 * @since 2.0.0
	 *
	 * @param string $pattern File pattern to match.
	 * @return int Number of files cleaned up.
	 */
	public static function cleanupTempFiles( string $pattern = 'wppo_*' ): int {
		$temp_dir = get_temp_dir();
		$files    = glob( $temp_dir . $pattern );
		$cleaned  = 0;

		foreach ( $files as $file ) {
			if ( is_file( $file ) && ( time() - filemtime( $file ) ) > 3600 ) { // 1 hour old
				try {
					self::deleteFile( $file );
					++$cleaned;
				} catch ( FileSystemException $e ) {
					LoggingUtil::warning( 'Failed to cleanup temp file: ' . $e->getMessage(), array( 'file' => $file ) );
				}
			}
		}

		return $cleaned;
	}

	/**
	 * Convert file path to URL.
	 *
	 * @param string $path File path.
	 * @return string URL or empty string if conversion fails.
	 */
	public static function pathToUrl( string $path ): string {
		$upload_dir = wp_upload_dir();
		$base_path  = $upload_dir['basedir'];
		$base_url   = $upload_dir['baseurl'];

		// Normalize paths
		$path      = wp_normalize_path( $path );
		$base_path = wp_normalize_path( $base_path );

		// Check if path is within upload directory
		if ( strpos( $path, $base_path ) === 0 ) {
			$relative_path = substr( $path, strlen( $base_path ) );
			return $base_url . $relative_path;
		}

		// Fallback for other paths
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( strpos( $path, $content_dir ) === 0 ) {
			$relative_path = substr( $path, strlen( $content_dir ) );
			return content_url( $relative_path );
		}

		return '';
	}
}
