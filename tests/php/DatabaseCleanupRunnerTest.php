<?php
/**
 * Database cleanup runner regression tests (P3-010, issue #1585).
 *
 * @package PerformanceOptimise\Tests
 * @since   NEXT
 */

use Brain\Monkey\Functions;
use PerformanceOptimise\Inc\Abilities;
use PerformanceOptimise\Inc\Database_Cleanup;
use PerformanceOptimise\Inc\Database_Cleanup_Runner;
use PerformanceOptimise\Inc\WPPO_CLI_Command;

// Pull in core test doubles without requiring WordPress.
if ( ! class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/stubs/wp-cli.php';
}
if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/TelemetryTest.php';
}

/**
 * Database cleanup application-runner tests.
 *
 * @since NEXT
 */
final class DatabaseCleanupRunnerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Fake database for cleanup execution and log recording.
	 *
	 * @var WPPO_Database_Cleanup_Runner_DB
	 */
	private WPPO_Database_Cleanup_Runner_DB $db;

	/**
	 * Actions fired during the current runner invocation.
	 *
	 * @var array<int,array{hook:string,args:array<int,mixed>}>
	 */
	private array $fired_actions = array();

	/**
	 * Set up the shared WordPress function surface.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->db            = new WPPO_Database_Cleanup_Runner_DB();
		$GLOBALS['wpdb']     = $this->db;
		$this->fired_actions = array();
		if ( ! defined( 'DB_NAME' ) ) {
			define( 'DB_NAME', 'test_database' );
		}

		Functions\when( 'is_wp_error' )->alias(
			static fn( $value ): bool => $value instanceof WP_Error
		);
		Functions\stubs( array( 'apply_filters' ) );
		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ): void {
				$this->fired_actions[] = array(
					'hook' => (string) $hook,
					'args' => $args,
				);
			}
		);
		Functions\when( 'add_action' )->justReturn( null );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
	}

	/**
	 * The canonical allowlist remains sourced from Database_Cleanup.
	 *
	 * @return void
	 */
	public function test_valid_types_match_domain_allowlist(): void {
		$this->assertSame( Database_Cleanup::get_valid_cleanup_types(), Database_Cleanup_Runner::valid_types() );
		$this->assertContains( 'action_scheduler', Database_Cleanup_Runner::valid_types() );
		$this->assertContains( 'all', Database_Cleanup_Runner::valid_types() );
		$this->assertNotContains( 'trash', Database_Cleanup_Runner::valid_types() );
	}

	/**
	 * Invalid types fail before any domain dispatch or activity side effect.
	 *
	 * @return void
	 */
	public function test_invalid_type_returns_stable_invalid_result(): void {
		$result = Database_Cleanup_Runner::run( 'trash', Database_Cleanup_Runner::SOURCE_ABILITY );

		$this->assertFalse( $result['valid'] );
		$this->assertSame( 'trash', $result['requested_type'] );
		$this->assertSame( '', $result['canonical_type'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertSame( array(), $this->db->queries );
		$this->assertSame( array(), $this->db->inserted );
	}

	/**
	 * CLI aliases dispatch through the canonical map and retain alias activity.
	 *
	 * @return void
	 */
	public function test_cli_alias_dispatches_canonical_method_and_preserves_alias_hook(): void {
		$this->db->ids          = array( 1, 2 );
		$this->db->query_result = 2;

		$result = Database_Cleanup_Runner::run( 'trash', Database_Cleanup_Runner::SOURCE_CLI );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'trash', $result['type'] );
		$this->assertSame( 'trashed_posts', $result['canonical_type'] );
		$this->assertSame( 2, $result['deleted'] );
		$this->assertStringContainsString( 'Database cleanup (trash via WP-CLI): 2 items removed', $this->db->inserted[0] );
		$this->assertNotEmpty(
			array_filter(
				$this->db->selected_queries,
				static fn( string $query ): bool => str_contains( $query, "post_status = 'trash'" )
			)
		);
		$this->assertSame(
			array(
				array(
					'hook' => 'wppo_database_cleanup_completed',
					'args' => array( 'trash', 2 ),
				),
			),
			$this->fired_actions
		);
	}

	/**
	 * Abilities continue to reject legacy aliases and return their stable shape.
	 *
	 * @return void
	 */
	public function test_abilities_alias_rejection_shape_is_preserved(): void {
		$this->assertSame(
			array( 'cleaned' => 0 ),
			Abilities::execute_database_cleanup( array( 'type' => 'trash' ) )
		);
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * Canonical revision cleanup consumes settings-derived bounded defaults.
	 *
	 * @return void
	 */
	public function test_revision_cleanup_uses_canonical_defaults(): void {
		\PerformanceOptimise\Inc\Settings_Store::set_settings_cache(
			array(
				'database_cleanup' => array(
					'dbRevMaxAge'     => 77,
					'dbRevKeepLatest' => 9,
				),
			)
		);
		$this->assertSame( array( 77, 9 ), Database_Cleanup::get_revision_defaults() );

		$result = Database_Cleanup_Runner::run( 'revisions', Database_Cleanup_Runner::SOURCE_ABILITY );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 0, $result['deleted'] );
		$flat_args = array();
		foreach ( $this->db->prepared_args as $args ) {
			$flat_args = array_merge( $flat_args, $args );
		}
		$this->assertContains( 9, $flat_args, 'Configured keep-latest value must reach the canonical cleanup method.' );
	}

	/**
	 * REST individual cleanup owns logging and table optimization behavior.
	 *
	 * @return void
	 */
	public function test_rest_context_logs_and_optimizes_after_deletion(): void {
		$this->db->ids          = array( 1 );
		$this->db->query_result = 1;

		$result = Database_Cleanup_Runner::run( 'trashed_posts', Database_Cleanup_Runner::SOURCE_REST );

		$this->assertSame( 1, $result['deleted'] );
		$this->assertStringContainsString( 'Database cleanup (trashed_posts): 1 items removed', $this->db->inserted[0] );
		$this->assertNotEmpty(
			array_filter(
				$this->db->queries,
				static fn( string $query ): bool => str_starts_with( $query, 'OPTIMIZE TABLE ' )
			)
		);
	}

	/**
	 * The all branch delegates its hooks, optimization, and aggregation intact.
	 *
	 * @return void
	 */
	public function test_all_context_returns_domain_results_and_total(): void {
		$result = Database_Cleanup_Runner::run( 'all', Database_Cleanup_Runner::SOURCE_ABILITY );

		$this->assertTrue( $result['valid'] );
		$this->assertSame( 'all', $result['canonical_type'] );
		$this->assertSame( 0, $result['deleted'] );
		$this->assertArrayHasKey( 'action_scheduler', $result['results'] );
		$this->assertSame( array(), $result['failures'] );
		$this->assertCount( count( Database_Cleanup::CLEANUP_METHOD_MAP ) + 2, $this->fired_actions );
		$this->assertSame( array( 'all', 0, $result['results'] ), $this->fired_actions[ count( $this->fired_actions ) - 1 ]['args'] );
	}

	/**
	 * Dry-run previews keep canonical narrowing and alias/unknown full fallback.
	 *
	 * @return void
	 */
	public function test_preview_preserves_alias_and_full_count_shapes(): void {
		$counts = array(
			'revisions'        => 3,
			'trashed_posts'    => 4,
			'action_scheduler' => 0,
		);
		Functions\when( 'get_transient' )->justReturn( $counts );

		$this->assertSame(
			array( 'would_delete' => array( 'trashed_posts' => 4 ) ),
			Database_Cleanup_Runner::preview( 'trashed_posts' )
		);
		$this->assertSame(
			array( 'would_delete' => $counts ),
			Database_Cleanup_Runner::preview( 'trash' )
		);
		$this->assertSame(
			array( 'would_delete' => $counts ),
			Database_Cleanup_Runner::preview( 'unknown' )
		);
	}

	/**
	 * CLI dry-run output and legacy alias preview remain unchanged.
	 *
	 * @return void
	 */
	public function test_cli_dry_run_alias_keeps_full_counts_json(): void {
		$counts = array(
			'revisions'        => 3,
			'trashed_posts'    => 4,
			'action_scheduler' => 0,
		);
		Functions\when( 'wp_json_encode' )->alias(
			static fn( $value, $flags = 0 ): string => (string) json_encode( $value, $flags ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Test stub mirrors wp_json_encode.
		);
		Functions\when( 'get_transient' )->justReturn( $counts );
		WP_CLI::reset_output();

		$command = new WPPO_CLI_Command();
		$command->database(
			array( 'cleanup' ),
			array(
				'type'    => 'trash',
				'dry-run' => true,
			)
		);

		$this->assertStringContainsString( '"trashed_posts": 4', WP_CLI::$logs[0] );
		$this->assertStringContainsString( 'Dry run', WP_CLI::$warnings[0] );
		$this->assertSame( array(), $this->db->queries );
	}

	/**
	 * REST, Abilities, and CLI delegate dispatch while their transport concerns stay put.
	 *
	 * @return void
	 */
	public function test_adapters_delegate_shared_dispatch_without_changing_security_or_confirmation(): void {
		$root = dirname( __DIR__, 2 );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local source-boundary test.
		$rest      = (string) file_get_contents( $root . '/includes/Admin/class-rest.php' );
		$abilities = (string) file_get_contents( $root . '/includes/Admin/class-abilities.php' );
		$cli       = (string) file_get_contents( $root . '/includes/Admin/class-wppo-cli-command.php' );
		$cron      = (string) file_get_contents( $root . '/includes/Scheduler/class-cron.php' );
		$runner    = (string) file_get_contents( $root . '/includes/Database/class-database-cleanup-runner.php' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		foreach ( array( $rest, $abilities, $cli ) as $adapter ) {
			$this->assertStringContainsString( 'Database_Cleanup_Runner::run(', $adapter );
			$this->assertStringNotContainsString( 'Database_Cleanup::invoke_cleanup_method(', $adapter );
			$this->assertStringNotContainsString( 'Database_Cleanup::get_revision_defaults(', $adapter );
			$this->assertStringNotContainsString( 'Database_Cleanup::clean_action_scheduler(', $adapter );
		}
		$this->assertStringNotContainsString( "case 'trashed_posts':", $cli );
		$this->assertStringNotContainsString( 'Database_Cleanup::clean_revisions(', $cli );
		$this->assertStringContainsString( 'WP_CLI::confirm(', $cli );
		$this->assertStringContainsString( 'REJECT --confirm alias', $cli );
		$this->assertStringContainsString( 'current_user_can( \'manage_options\' )', $abilities );
		$this->assertStringContainsString( 'Database_Cleanup::auto_clean(', $cron );
		$this->assertStringContainsString( "'permission_callback' => array( \$this, 'permission_callback' )", $rest );
		$this->assertStringContainsString( 'Database_Cleanup::CLEANUP_METHOD_MAP', $runner );
		$this->assertStringContainsString( 'Database_Cleanup::get_revision_defaults()', $runner );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, Generic.Commenting.DocComment.MissingShort -- Small isolated WPDB fixture for runner tests.

if ( ! class_exists( 'WPPO_Database_Cleanup_Runner_DB' ) ) {
	/**
	 * Minimal WPDB stand-in for runner execution and activity-log assertions.
	 *
	 * @since NEXT
	 */
	class WPPO_Database_Cleanup_Runner_DB {
		/** @var string */
		public $prefix = 'wp_';
		/** @var string */
		public $posts = 'wp_posts';
		/** @var string */
		public $postmeta = 'wp_postmeta';
		/** @var string */
		public $comments = 'wp_comments';
		/** @var string */
		public $commentmeta = 'wp_commentmeta';
		/** @var string */
		public $options = 'wp_options';
		/** @var string */
		public $last_error = '';
		/** @var array<int,int>|null */
		public $ids = null;
		/** @var int */
		public $query_result = 0;
		/** @var int */
		private $get_col_calls = 0;
		/** @var string[] */
		public $selected_queries = array();
		/** @var string[] */
		public $queries = array();
		/** @var array<int,array<int,mixed>> */
		public $prepared_args = array();
		/** @var string[] */
		public $inserted = array();

		/**
		 * Return one configured ID batch, then end the cleanup loop.
		 *
		 * @param string $query Unused query.
		 * @return array<int,int>
		 */
		public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$this->selected_queries[] = (string) $query;
			++$this->get_col_calls;
			if ( 1 === $this->get_col_calls && is_array( $this->ids ) ) {
				return $this->ids;
			}
			return array();
		}

		/** @param string $query Unused query. @return array<int,mixed> */
		public function get_results( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}

		/** @param string $query Unused query. @return null */
		public function get_var( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return null;
		}

		/** @param string $query Unused query. @return null */
		public function get_row( $query = null, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return null;
		}

		/**
		 * Record a query and return the configured mutation count.
		 *
		 * @param string $query Query text.
		 * @return int
		 */
		public function query( $query = null ) {
			$this->queries[] = (string) $query;
			return $this->query_result;
		}

		/**
		 * Record prepare arguments and return the query text.
		 *
		 * @param string $query Query text.
		 * @param mixed  ...$args Prepare arguments.
		 * @return string
		 */
		public function prepare( $query, ...$args ) {
			$this->prepared_args[] = $args;
			return $query;
		}

		/** @param string $text Escape text. @return string */
		public function esc_like( $text ) {
			return (string) $text;
		}

		/**
		 * Record an activity-log insert.
		 *
		 * @param string $table Unused table.
		 * @param array  $data Insert data.
		 * @param array  $format Unused format.
		 * @return int
		 */
		public function insert( $table, $data, $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			$this->inserted[] = (string) ( $data['activity'] ?? '' );
			return 1;
		}
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile, Squiz.Commenting, Generic.Commenting.DocComment.MissingShort
