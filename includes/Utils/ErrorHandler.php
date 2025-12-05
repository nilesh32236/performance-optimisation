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
 *
 * @since 2.1.0
 */
class ErrorHandler {

	/**
	 * Whether the error handler has been initialized.
	 *
	 * @since 2.1.0
	 * @var bool
	 */
	private static bool $initialized = false;

	/**
	 * Error log storage.
	 *
	 * @since 2.1.0
	 * @var array
	 */
	private static array $error_log = array();

	/**
	 * Initialize error handlers.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function initialize(): void {
		if ( self::$initialized ) {
			return;
		}

		// phpcs:disable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		set_error_handler( array( self::class, 'handle_error' ) );
		set_exception_handler( array( self::class, 'handle_exception' ) );
		register_shutdown_function( array( self::class, 'handle_fatal_error' ) );
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler

		self::$initialized = true;
	}

	/**
	 * Handle PHP errors.
	 *
	 * @since 2.1.0
	 *
	 * @param int    $severity Error severity level.
	 * @param string $message  Error message.
	 * @param string $file     File where error occurred.
	 * @param int    $line     Line number where error occurred.
	 * @return bool True to prevent default error handler.
	 */
	public static function handle_error( int $severity, string $message, string $file, int $line ): bool {
		// phpcs:disable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting
		if ( ! ( error_reporting() & $severity ) ) {
			return false;
		}
		// phpcs:enable WordPress.PHP.DevelopmentFunctions.prevent_path_disclosure_error_reporting
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.runtime_configuration_error_reporting

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
		$error = array(
			'type'      => 'error',
			'severity'  => $severity,
			'message'   => $message,
			'file'      => $file,
			'line'      => $line,
			'timestamp' => current_time( 'mysql' ),
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_debug_backtrace
			'trace'     => debug_backtrace( DEBUG_BACKTRACE_IGNORE_ARGS ),
		);

		self::log_error( $error );

		if ( $severity & ( E_ERROR | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR ) ) {
			self::handle_critical_error( $error );
		}

		return true;
	}

	/**
	 * Handle uncaught exceptions.
	 *
	 * @since 2.1.0
	 *
	 * @param \Throwable $exception The exception to handle.
	 * @return void
	 */
	public static function handle_exception( \Throwable $exception ): void {
		$error = array(
			'type'      => 'exception',
			'class'     => get_class( $exception ),
			'message'   => $exception->getMessage(),
			'file'      => $exception->getFile(),
			'line'      => $exception->getLine(),
			'timestamp' => current_time( 'mysql' ),
			'trace'     => $exception->getTrace(),
		);

		self::log_error( $error );
		self::handle_critical_error( $error );
	}

	/**
	 * Handle fatal errors on shutdown.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function handle_fatal_error(): void {
		$error = error_get_last();

		if ( $error && in_array( $error['type'], array( E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE ), true ) ) {
			$formatted_error = array(
				'type'      => 'fatal',
				'message'   => $error['message'],
				'file'      => $error['file'],
				'line'      => $error['line'],
				'timestamp' => current_time( 'mysql' ),
			);

			self::log_error( $formatted_error );
			self::handle_critical_error( $formatted_error );
		}
	}

	/**
	 * Log error to storage and WordPress debug log.
	 *
	 * @since 2.1.0
	 *
	 * @param array $error Error details array.
	 * @return void
	 */
	private static function log_error( array $error ): void {
		self::$error_log[] = $error;

		// Log to WordPress debug log if enabled.
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
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

		// Log to plugin's logging system.
		LoggingUtil::error( $error['message'], $error );
	}

	/**
	 * Handle critical errors by enabling safe mode.
	 *
	 * @since 2.1.0
	 *
	 * @param array $error Error details array.
	 * @return void
	 */
	private static function handle_critical_error( array $error ): void {
		// Disable plugin features that might be causing issues.
		update_option( 'wppo_safe_mode', true );

		// Send admin notification if configured.
		if ( get_option( 'wppo_error_notifications', false ) ) {
			self::send_error_notification( $error );
		}
	}

	/**
	 * Send error notification email to admin.
	 *
	 * @since 2.1.0
	 *
	 * @param array $error Error details array.
	 * @return void
	 */
	private static function send_error_notification( array $error ): void {
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

	/**
	 * Get the error log.
	 *
	 * @since 2.1.0
	 * @return array Array of logged errors.
	 */
	public static function get_error_log(): array {
		return self::$error_log;
	}

	/**
	 * Clear the error log.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function clear_error_log(): void {
		self::$error_log = array();
	}
}
