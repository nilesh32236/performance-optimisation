<?php
/**
 * Tests for WP-CLI --dry-run preview (Phase2 PR-B).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\WPPO_CLI_Command;
use Brain\Monkey\Functions;

/**
 * Verifies --dry-run only for database cleanup/optimize via get_counts (REJECT cache dry-run).
 *
 * @since NEXT
 */
class WppoCliDryRunTest extends \PHPUnit\Framework\TestCase {
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

		if ( ! class_exists( 'WP_CLI' ) ) {
			eval( 'class WP_CLI { public static $logs = array(); public static $warnings = array(); public static $errors = array(); public static $successes = array(); public static $confirms = array(); public static function log($msg){ self::$logs[] = $msg; } public static function warning($msg){ self::$warnings[] = $msg; } public static function error($msg){ self::$errors[] = $msg; throw new RuntimeException($msg); } public static function success($msg){ self::$successes[] = $msg; } public static function confirm($q, $assoc=null){ self::$confirms[]=$q; } public static function reset(){ self::$logs=array(); self::$warnings=array(); self::$errors=array(); self::$successes=array(); self::$confirms=array(); } }' );
		}
		if ( ! class_exists( 'WP_CLI_Command' ) ) {
			eval( 'class WP_CLI_Command {}' );
		}
		if ( ! class_exists( 'WP_CLI\\Utils' ) ) {
			eval( 'namespace WP_CLI; class Utils { public static function get_flag_value($assoc,$key,$default=null){ return $assoc[$key] ?? $default; } }' );
		}
		if ( ! class_exists( WPPO_CLI_Command::class ) ) {
			require_once dirname( __DIR__, 2 ) . '/includes/class-wppo-cli-command.php';
		}
		\WP_CLI::reset();
		if ( property_exists( 'WP_CLI', 'confirms' ) ) {
			\WP_CLI::$confirms = array();
		}
	}

	/**
	 * Database docblock contains [--dry-run].
	 */
	public function test_database_docblock_has_dry_run(): void {
		$this->assertStringContainsString( '[--dry-run]', $this->cli_contents );
		$this->assertMatchesRegularExpression( '/database.*?\[--dry-run\]/s', $this->cli_contents );
	}

	/**
	 * Cache clear must NOT have dry-run (REJECT).
	 */
	public function test_cache_no_dry_run(): void {
		$cache_start = strpos( $this->cli_contents, 'Manage static HTML cache' );
		$cache_end   = strpos( $this->cli_contents, 'public function cache(' );
		$database_start = strpos( $this->cli_contents, 'Perform database cleanup' );
		// Slice from cache docblock start to just before database docblock.
		$cache_block = '';
		if ( false !== $cache_start && false !== $database_start ) {
			$cache_block = substr( $this->cli_contents, $cache_start, $database_start - $cache_start );
		} elseif ( false !== $cache_start && false !== $cache_end ) {
			$cache_block = substr( $this->cli_contents, $cache_start, $cache_end - $cache_start + 500 );
		}
		$this->assertNotEmpty( $cache_block, 'Cache block must be extracted' );
		$this->assertStringNotContainsString( '--dry-run', $cache_block );
		$this->assertStringNotContainsString( 'dry-run', $cache_block );
	}

	/**
	 * Dry-run for cleanup --type=all logs would_delete without DELETE.
	 */
	public function test_database_cleanup_dry_run_logs_would_delete(): void {
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'wptests' );
		}
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );

		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public int $query_calls = 0;
			public function get_col( $query = null ) { return array( 1, 2 ); }
			public function get_var( $query = null, $x = 0, $y = 0 ) { return 5; }
			public function query( $query = null ) { ++$this->query_calls; return 1; }
			public function get_results( $query = null, $output = OBJECT ) { return array(); }
		};

		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			$cmd->database( array( 'cleanup' ), array( 'type' => 'all', 'dry-run' => true ) );
			$this->assertNotEmpty( \WP_CLI::$logs );
			$last_log = end( \WP_CLI::$logs );
			$data = json_decode( $last_log, true );
			$this->assertIsArray( $data );
			$this->assertArrayHasKey( 'would_delete', $data );
			$this->assertArrayHasKey( 'revisions', $data['would_delete'] );
			$this->assertNotEmpty( \WP_CLI::$warnings );
			$warning = end( \WP_CLI::$warnings );
			$this->assertStringContainsString( 'Dry run', $warning );
			$this->assertSame( 0, $wpdb->query_calls, 'No DELETE should occur on dry-run' );
			$this->assertEmpty( \WP_CLI::$successes, 'Should not log success on dry-run' );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Dry-run for single type logs would_delete for that type only.
	 */
	public function test_database_cleanup_dry_run_single_type(): void {
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'wptests' );
		}
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'add_action' )->justReturn( true );

		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public function get_var( $query = null, $x = 0, $y = 0 ) { return 3; }
			public function get_results( $query = null, $output = OBJECT ) { return array(); }
		};

		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			$cmd->database( array( 'cleanup' ), array( 'type' => 'revisions', 'dry-run' => true ) );
			$last_log = end( \WP_CLI::$logs );
			$data = json_decode( $last_log, true );
			$this->assertArrayHasKey( 'would_delete', $data );
			$this->assertArrayHasKey( 'revisions', $data['would_delete'] );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Dry-run for optimize logs would_optimize without OPTIMIZE.
	 */
	public function test_database_optimize_dry_run_logs_would_optimize(): void {
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'wptests' );
		}
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( '__' )->returnArg( 1 );

		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public int $query_calls = 0;
			public function query( $query = null ) { ++$this->query_calls; return 1; }
			public function get_var( $query = null, $x = 0, $y = 0 ) { return 0; }
		};

		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			$cmd->database( array( 'optimize' ), array( 'tables' => 'posts,options', 'dry-run' => true ) );
			$last_log = end( \WP_CLI::$logs );
			$data = json_decode( $last_log, true );
			$this->assertArrayHasKey( 'would_optimize', $data );
			$this->assertContains( 'posts', $data['would_optimize'] );
			$this->assertContains( 'options', $data['would_optimize'] );
			$this->assertSame( 0, $wpdb->query_calls );
			$this->assertStringContainsString( 'Dry run', end( \WP_CLI::$warnings ) );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Non-dry-run actually calls optimize (query).
	 */
	public function test_database_optimize_without_dry_run_calls_query(): void {
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'wptests' );
		}
		Functions\when( 'wp_json_encode' )->alias( static function ( $data, $options = 0, $depth = 512 ) { return json_encode( $data, $options, $depth ); } );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'is_wp_error' )->alias( static function ( $thing ) { return $thing instanceof \WP_Error; } );
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );

		global $wpdb;
		$backdrop = $wpdb;
		$wpdb     = new class() extends WPPO_DB_Mock {
			public int $query_calls = 0;
			public $prefix = 'wp_';
			public $posts = 'wp_posts';
			public $options = 'wp_options';
			public function get_var( $query = null, $x = 0, $y = 0 ) { return 1024; } // size <1GB
			public function query( $query = null ) { ++$this->query_calls; return 1; }
			public function get_row( $query = null, $output = OBJECT, $y = 0 ) { return null; }
			public function insert( $table = null, $data = null, $format = null ) { return 1; } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			public function get_col( $query = null ) { return array(); } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			public function prepare( $query, ...$args ) { return $query; } // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		};

		try {
			\WP_CLI::reset();
			$cmd = new WPPO_CLI_Command();
			// Mock get_table_size to avoid information_schema: our mock get_var returns 1024.
			$cmd->database( array( 'optimize' ), array( 'tables' => 'posts', 'dry-run' => false ) );
			$this->assertGreaterThan( 0, $wpdb->query_calls );
			$this->assertNotEmpty( \WP_CLI::$successes );
		} finally {
			$wpdb = $backdrop; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}
	}

	/**
	 * Cache command must not contain network flag (REJECT --network).
	 */
	public function test_no_network_flag(): void {
		$this->assertStringNotContainsString( '--network', $this->cli_contents );
		$this->assertStringNotContainsString( 'get_sites', $this->cli_contents );
	}
}
