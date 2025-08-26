<?php
/**
 * File System Utility
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class FileSystemUtil
 *
 * @package PerformanceOptimisation\Utils
 */
class FileSystemUtil {

	private static $filesystem;

	private static function getFilesystem() {
		if ( self::$filesystem ) {
			return self::$filesystem;
		}

		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			WP_Filesystem();
		}
		self::$filesystem = $wp_filesystem;
		return self::$filesystem;
	}

	public static function fileExists( string $path ): bool {
		return self::getFilesystem()->exists( $path );
	}

	public static function readFile( string $path ): string {
		return self::getFilesystem()->get_contents( $path );
	}

	public static function writeFile( string $path, string $contents ): bool {
		return self::getFilesystem()->put_contents( $path, $contents, FS_CHMOD_FILE );
	}

	public static function createDirectory( string $path ): bool {
		return wp_mkdir_p( $path );
	}

	public static function deleteFile( string $path ): bool {
		return self::getFilesystem()->delete( $path );
	}

	public static function deleteDirectory( string $path, bool $recursive = false ): bool {
		return self::getFilesystem()->rmdir( $path, $recursive );
	}

	public static function getDirectorySize( string $path ): int {
		if ( ! self::fileExists( $path ) ) {
			return 0;
		}
		$size = 0;
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
}
