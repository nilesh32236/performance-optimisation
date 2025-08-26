<?php
/**
 * Logging Utility
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class LoggingUtil
 *
 * @package PerformanceOptimisation\Utils
 */
class LoggingUtil {

	public static function info( string $message ): void {
		self::log( 'INFO', $message );
	}

	public static function warning( string $message ): void {
		self::log( 'WARNING', $message );
	}

	public static function error( string $message ): void {
		self::log( 'ERROR', $message );
	}

	private static function log( string $level, string $message ): void {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( "WPPO [{$level}]: {$message}" );
		}
	}
}
