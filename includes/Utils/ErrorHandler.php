<?php
/**
 * Error Handler Utility
 *
 * @package PerformanceOptimisation\Utils
 * @since   2.1.0
 */

namespace PerformanceOptimisation\Utils;

/**
 * Error Handler Class
 */
class ErrorHandler {

	private static bool $initialized = false;
	private static array $error_log  = array();

	public static function initialize(): void {
		if ( self::$initialized ) {
			return;
		}

		set_error_handler( array( self::class, 'handleError' ) );
		set_exception_handler( array( self::class, 'handleException' ) );
		register_shutdown_function( array( self::class, 'handleFatalError' ) );

		self::$initialized = true;
	}

	public static function handleError( int $severity, string $message, string $file, int $line ): bool {
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}

		$error = array(
			'type'      => 'error',
			'severity'  => $severity,
			'message'   => $message,
			'file'      => $file,
			'line'      => $line,
			'timestamp' => current_time( 'mysql' ),
			'trace'     => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ),
		);

		self::logError( $error );

		if ( $severity & ( E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR ) ) {
			self::handleCriticalError( $error );
		}

		return true;
	}

	public static function handleException( \Throwable $exception ): void {
		$error = array(
			'type'      => 'exception',
			'class'     => get_class( $exception ),
			'message'   => $exception->getMessage(),
			'file'      => $exception->getFile(),
			'line'      => $exception->getLine(),
			'timestamp' => current_time( 'mysql' ),
			'trace'     => $exception->getTrace(),
		);

		self::logError( $error );
		self::handleCriticalError( $error );
	}

	public static function handleFatalError(): void {
		$error = error_get_last();

		if ( $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ) ) ) {
			$formatted_error = array(
				'type'      => 'fatal',
				'message'   => $error['message'],
				'file'      => $error['file'],
				'line'      => $error['line'],
				'timestamp' => current_time( 'mysql' ),
			);

			self::logError( $formatted_error );
			self::handleCriticalError( $formatted_error );
		}
	}

	private static function logError( array $error ): void {
		self::$error_log[] = $error;

		// Log to WordPress debug log if enabled
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log(
				sprintf(
					'WPPO %s: %s in %s:%d',
					strtoupper( $error['type'] ),
					$error['message'],
					$error['file'],
					$error['line']
				)
			);
		}

		// Log to plugin's logging system
		LoggingUtil::error( $error['message'], $error );
	}

	private static function handleCriticalError( array $error ): void {
		// Disable plugin features that might be causing issues
		update_option( 'wppo_safe_mode', true );

		// Send admin notification if configured
		if ( get_option( 'wppo_error_notifications', false ) ) {
			self::sendErrorNotification( $error );
		}
	}

	private static function sendErrorNotification( array $error ): void {
		$admin_email = get_option( 'admin_email' );
		$site_name   = get_bloginfo( 'name' );

		$subject = sprintf( '[%s] Performance Optimisation Error', $site_name );
		$message = sprintf(
			"A critical error occurred in the Performance Optimisation plugin:\n\n" .
			"Error: %s\n" .
			"File: %s\n" .
			"Line: %d\n" .
			"Time: %s\n\n" .
			'The plugin has been temporarily disabled to prevent further issues.',
			$error['message'],
			$error['file'],
			$error['line'],
			$error['timestamp']
		);

		wp_mail( $admin_email, $subject, $message );
	}

	public static function getErrorLog(): array {
		return self::$error_log;
	}

	public static function clearErrorLog(): void {
		self::$error_log = array();
	}
}
