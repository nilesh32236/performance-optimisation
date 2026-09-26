<?php
/**
 * Minimal WP-CLI stand-ins for unit tests.
 *
 * WP-CLI is not loaded in the bare PHPUnit environment, but
 * WPPO_CLI_Command extends WP_CLI_Command and calls WP_CLI statics, so the
 * verify tests need recording stand-ins. Loaded via require_once from
 * WppoCliVerifyTest (mirrors tests/php/stubs/wp-html-api.php).
 *
 * @package PerformanceOptimise\Tests
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound, WordPress.WP.GlobalVariablesOverride.Prohibited, Generic.Files.OneObjectStructurePerFile, WordPress.Files.FileName

if ( ! class_exists( 'WP_CLI_Command' ) ) {
	/**
	 * Minimal WP-CLI command stub for unit tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_CLI_Command {}
}

if ( ! class_exists( 'WP_CLI' ) ) {
	/**
	 * Minimal WP-CLI stub recording output for assertions.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WP_CLI {
		/**
		 * Logged lines.
		 *
		 * @var string[]
		 */
		public static $logs = array();

		/**
		 * Success messages.
		 *
		 * @var string[]
		 */
		public static $successes = array();

		/**
		 * Error messages.
		 *
		 * @var string[]
		 */
		public static $errors = array();

		/**
		 * Warning messages.
		 *
		 * @var string[]
		 */
		public static $warnings = array();

		/**
		 * Reset recorded output.
		 *
		 * @return void
		 */
		public static function reset_output(): void {
			self::$logs      = array();
			self::$successes = array();
			self::$errors    = array();
			self::$warnings  = array();
		}

		/**
		 * Record a log line.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function log( $message ): void {
			self::$logs[] = (string) $message;
		}

		/**
		 * Record a success message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function success( $message ): void {
			self::$successes[] = (string) $message;
		}

		/**
		 * Record an error message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function error( $message ): void {
			self::$errors[] = (string) $message;
		}

		/**
		 * Record a warning message.
		 *
		 * @param string $message Message.
		 * @return void
		 */
		public static function warning( $message ): void {
			self::$warnings[] = (string) $message;
		}
	}
}
