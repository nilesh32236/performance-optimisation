<?php
/**
 * Tests for WP-CLI JSON-only format (Phase1 PR-A).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\WPPO_CLI_Command;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Verifies database counts + system-info support json only (REJECT table/csv/yaml).
 *
 * @since NEXT
 */
class WppoCliFormatTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * CLI file contents.
	 *
	 * @var string
	 */
	private string $cli_contents;

	/**
	 * Set up.
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cli_contents = (string) file_get_contents( dirname( __DIR__, 2 ) . '/includes/class-wppo-cli-command.php' );

		// Define WP_CLI stubs if not already defined (CLI tests run without WP-CLI binary).
		if ( ! class_exists( 'WP_CLI' ) ) {
			eval( 'class WP_CLI { public static $logs = array(); public static $warnings = array(); public static $errors = array(); public static $successes = array(); public static $confirms = array(); public static function log($msg){ self::$logs[] = $msg; } public static function warning($msg){ self::$warnings[] = $msg; } public static function error($msg){ self::$errors[] = $msg; throw new RuntimeException($msg); } public static function success($msg){ self::$successes[] = $msg; } public static function confirm($q, $assoc=null){ self::$confirms[]=$q; } public static function reset(){ self::$logs=array(); self::$warnings=array(); self::$errors=array(); self::$successes=array(); self::$confirms=array(); } }' );
		} else {
			if ( ! property_exists( 'WP_CLI', 'confirms' ) ) {
				\WP_CLI::$confirms = array();
			}
			if ( ! method_exists( 'WP_CLI', 'confirm' ) ) {
				// Cannot add method dynamically; ensure stub is used via Confirm test first.
			}
		}
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			eval( 'class WP_CLI_Command {}' );
		}
		if ( ! class_exists( 'WP_CLI\\Utils' ) ) {
			eval( 'namespace WP_CLI; class Utils { public static function get_flag_value($assoc,$key,$default=null){ return $assoc[$key] ?? $default; } }' );
		}

		// Ensure CLI class is loaded.
		if ( ! class_exists( WPPO_CLI_Command::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/class-wppo-cli-command.php';
		}

		\WP_CLI::reset();
	}

	/**
	 * Database counts docblock declares [--format] json default.
	 */
	public function test_database_counts_docblock_has_json_format(): void {
		$this->assertMatchesRegularExpression( '/database.*?\[--format=<format>\].*?default:\s*json/s', $this->cli_contents );
		$this->assertStringContainsString( 'Output format for counts action', $this->cli_contents );
	}

	/**
	 * System-info docblock declares [--format] json default.
	 */
	public function test_system_info_docblock_has_json_format(): void {
		// system_info docblock contains [--format] before @subcommand system-info
		$this->assertStringContainsString( '[--format=<format>]', $this->cli_contents );
		$this->assertMatchesRegularExpression( '/Output format\..*?default:\s*json/s', $this->cli_contents );
		$this->assertGreaterThanOrEqual( 2, substr_count( $this->cli_contents, '[--format=<format>]' ) );
	}

	/**
	 * No Formatter instantiation for database counts / system-info (json-only).
	 */
	public function test_no_formatter_or_yaml_fallback(): void {
		// Settings get legitimately supports yaml via Spyc/yaml_emit, but database/system-info must be json-only.
		// Ensure no Formatter object is instantiated for those commands (comment mentions fallback is allowed).
		$this->assertStringNotContainsString( 'new Formatter', $this->cli_contents );
		$this->assertStringNotContainsString( 'Formatter::', $this->cli_contents );
		// Ensure the json-only comment is present for database counts and system-info.
		$this->assertStringContainsString( 'JSON-only output per FINAL-ADVERSARIAL-REVIEW', $this->cli_contents );
	}

	/**
	 * Database counts outputs valid JSON (json-only, fallback when Formatter absent).
	 */
	public function test_database_counts_outputs_json(): void {
		// wp_cache_get_salted is a real function loaded before Patchwork; do not mock it.
		// The bootstrap wp_object_cache stub already returns cache miss (false).
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );

		// Minimal $wpdb mock that returns 0 for counts queries.
		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public function db_version() { return '8.0.0'; }
		};

		try {
			$cmd = new WPPO_CLI_Command();
			$cmd->database( array( 'counts' ), array() );
			$this->assertNotEmpty( \WP_CLI::$logs );
			$json = end( \WP_CLI::$logs );
			$data = json_decode( $json, true );
			$this->assertIsArray( $data );
			$this->assertArrayHasKey( 'revisions', $data );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Database counts with --format=table still outputs json (fallback).
	 */
	public function test_database_counts_table_format_fallback_to_json(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );

		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public function db_version() { return '8.0.0'; }
		};

		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			$cmd->database( array( 'counts' ), array( 'format' => 'table' ) );
			$json = end( \WP_CLI::$logs );
			$data = json_decode( $json, true );
			$this->assertIsArray( $data );
			$this->assertArrayHasKey( 'revisions', $data );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * System-info outputs valid JSON and respects --format json only.
	 */
	public function test_system_info_outputs_json(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'size_format' )->alias( static function ( $bytes, $decimals = 0 ) { return (string) $bytes . ' B'; } );
		Functions\when( 'number_format_i18n' )->alias( static function ( $number, $decimals = 0 ) { return (string) $number; } );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );

		// Provide minimal WP functions used inside System_Info.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_transient' )->justReturn( false );

		// $wpdb mock with required methods for System_Info.
		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public function db_version() { return '8.0.33'; }
			public function get_row( $query = null, $output = OBJECT, $y = 0 ) { return null; }
			public function get_var( $query = null, $x = 0, $y = 0 ) { return '8.0.33'; }
		};
		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			$cmd->system_info( array(), array( 'format' => 'json' ) );
			$this->assertNotEmpty( \WP_CLI::$logs );
			$json = end( \WP_CLI::$logs );
			$data = json_decode( $json, true );
			$this->assertIsArray( $data );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * System-info with non-json format still outputs json (fallback).
	 */
	public function test_system_info_non_json_fallback(): void {
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( 'get_bloginfo' )->justReturn( '6.8' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_site_option' )->justReturn( array() );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'size_format' )->alias( static function ( $bytes, $decimals = 0 ) { return (string) $bytes . ' B'; } );
		Functions\when( 'number_format_i18n' )->alias( static function ( $number, $decimals = 0 ) { return (string) $number; } );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_filter' )->justReturn( true );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_get_environment_type' )->justReturn( 'production' );
		Functions\when( 'get_transient' )->justReturn( false );
		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public function db_version() { return '8.0.33'; }
			public function get_row( $query = null, $output = OBJECT, $y = 0 ) { return null; }
			public function get_var( $query = null, $x = 0, $y = 0 ) { return '8.0.33'; }
		};
		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			$cmd->system_info( array(), array( 'format' => 'yaml' ) );
			$json = end( \WP_CLI::$logs );
			$data = json_decode( $json, true );
			$this->assertIsArray( $data );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Util::get_default_settings covers all allowed tabs (7-tab drift fixed).
	 */
	public function test_util_get_default_settings_covers_all_tabs(): void {
		$this->assertTrue( method_exists( Util::class, 'get_default_settings' ) );
		$defaults = Util::get_default_settings();
		foreach ( Util::ALLOWED_SETTINGS_KEYS as $key ) {
			$this->assertArrayHasKey( $key, $defaults, "Missing tab {$key} in defaults" );
		}
		// Spot-check file_optimisation keys.
		$this->assertArrayHasKey( 'minifyHTML', $defaults['file_optimisation'] );
		$this->assertArrayHasKey( 'blockAssetsOnDemand', $defaults['file_optimisation'] );
	}

	/**
	 * CLI get_default_settings delegates to Util (single source).
	 */
	public function test_cli_delegates_to_util(): void {
		$ref = new ReflectionMethod( WPPO_CLI_Command::class, 'get_default_settings' );
		$ref->setAccessible( true );
		$cli_defaults  = $ref->invoke( null );
		$util_defaults = Util::get_default_settings();
		$this->assertSame( $util_defaults, $cli_defaults );
	}
}
