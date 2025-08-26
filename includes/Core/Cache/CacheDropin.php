<?php
/**
 * Cache Dropin
 *
 * @package PerformanceOptimisation\Core\Cache
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core\Cache;

use PerformanceOptimisation\Utils\FileSystemUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class CacheDropin
 *
 * @package PerformanceOptimisation\Core\Cache
 */
class CacheDropin {

	private static string $handler_file_path = '';

	private static function init_paths(): void {
		if ( empty( self::$handler_file_path ) ) {
			self::$handler_file_path = wp_normalize_path( WP_CONTENT_DIR . '/advanced-cache.php' );
		}
	}

	public static function create(): void {
		self::init_paths();
		$wp_filesystem = FileSystemUtil::getFilesystem();

		if ( ! $wp_filesystem ) {
			return;
		}

		$handler_code = self::get_dropin_content();
		$wp_filesystem->put_contents( self::$handler_file_path, $handler_code, FS_CHMOD_FILE );
	}

	public static function remove(): void {
		self::init_paths();
		$wp_filesystem = FileSystemUtil::getFilesystem();

		if ( $wp_filesystem && $wp_filesystem->exists( self::$handler_file_path ) ) {
			$wp_filesystem->delete( self::$handler_file_path );
		}
	}

	private static function get_dropin_content(): string {
		return <<<'PHP'
<?php
// Content of the advanced-cache.php file.
PHP;
	}
}
