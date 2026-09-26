<?php
/**
 * Tests for the Database_Cleanup WP 6.9+ Notes guard (issue #884).
 *
 * WordPress 6.9 stores personal Notes as comments with `comment_type='note'`.
 * The spam/trash comment cleanup SELECTs and the get_counts() counts must both
 * exclude those rows so cleanup never destroys Notes and the advertised counts
 * match what cleanup would actually delete.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Database_Cleanup;
use Brain\Monkey\Functions;

// phpcs:disable WordPress.Files.FileName -- Pulls in the shared WP_Error test stand-in from TelemetryTest.php.

// The plugin constructs core's WP_Error on error paths; the unit environment
// has no WP core, so reuse the guarded stand-in co-located in TelemetryTest.php
// (require_once keeps it a single declaration whichever file loads first).
if ( ! class_exists( 'WP_Error' ) ) {
	require_once __DIR__ . '/TelemetryTest.php';
}

// WPPO_DB_Mock lives in DatabaseCleanupTest.php; guarded require keeps
// standalone runs of this file working (PHPUnit includes every *Test.php in
// a full-suite run, so the guard only matters for filtered runs).
if ( ! class_exists( 'WPPO_DB_Mock' ) ) {
	require_once __DIR__ . '/DatabaseCleanupTest.php';
}

/**
 * Notes-guard tests for spam/trash comment cleanup and counts.
 *
 * @package PerformanceOptimise\Tests
 */
class DatabaseCleanupNotesGuardTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Recorded get_col()/get_var() queries.
	 *
	 * @var string[]
	 */
	private array $queries = array();

	/**
	 * Install a capturing wpdb mock.
	 *
	 * The mock returns one comment ID on the first get_col() call and none
	 * afterwards so delete_in_batches() terminates after one batch; query()
	 * simulates a successful single-row DELETE.
	 *
	 * @return void
	 */
	private function install_capturing_wpdb(): void {
		$queries_ref     = &$this->queries;
		$GLOBALS['wpdb'] = new class( $queries_ref ) extends WPPO_DB_Mock {
			/**
			 * Query recorder (shared with the test case, by reference).
			 *
			 * @var array<string>
			 */
			private $recorded;

			/**
			 * Whether get_col() already returned a row.
			 *
			 * @var bool
			 */
			private $returned_row = false;

			/**
			 * Constructor.
			 *
			 * @param array $recorded Query recorder (by reference).
			 */
			public function __construct( array &$recorded ) {
				$this->recorded = &$recorded;
			}

			/**
			 * Record the SELECT and return one row on the first call.
			 *
			 * @param string $query SQL query.
			 * @return array<int, int>
			 */
			public function get_col( $query = null ) {
				$this->recorded[] = (string) $query;
				if ( $this->returned_row ) {
					return array();
				}
				$this->returned_row = true;
				return array( 101 );
			}

			/**
			 * Record the COUNT query.
			 *
			 * @param string $query SQL query.
			 * @return null
			 */
			public function get_var( $query = null ) {
				$this->recorded[] = (string) $query;
				return null;
			}

			/**
			 * Records the batched UNION ALL counts query and returns zero rows.
			 *
			 * Get_counts() batches all COUNT(*)s into a single UNION ALL
			 * query with k/c label columns (audit #982).
			 *
			 * @param string $query  SQL query.
			 * @param mixed  $output Output type (unused).
			 * @return array<int, array<string, mixed>>
			 */
			public function get_results( $query = null, $output = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				$this->recorded[] = (string) $query;
				return array(
					array(
						'k' => 'spam_comments',
						'c' => 0,
					),
					array(
						'k' => 'trashed_comments',
						'c' => 0,
					),
				);
			}

			/**
			 * Simulate a successful single-row DELETE.
			 *
			 * @param string $query SQL query.
			 * @return int
			 */
			public function query( $query = null ) {
				$this->recorded[] = (string) $query;
				return 1;
			}
		};
	}

	/**
	 * Extract the recorded comment_ID SELECT statements.
	 *
	 * @return string[]
	 */
	private function recorded_comment_selects(): array {
		return array_values(
			array_filter(
				$this->queries,
				static function ( string $query ): bool {
					return 0 === strpos( $query, 'SELECT comment_ID' );
				}
			)
		);
	}

	/**
	 * Test that clean_spam_comments() excludes WP 6.9+ Notes from its SELECT.
	 */
	public function test_clean_spam_comments_select_excludes_notes(): void {
		$this->install_capturing_wpdb();
		Functions\when( 'is_multisite' )->justReturn( false );

		$result = Database_Cleanup::clean_spam_comments();

		$this->assertSame( 1, $result );
		$selects = $this->recorded_comment_selects();
		$this->assertNotEmpty( $selects, 'clean_spam_comments() must run a comment_ID SELECT.' );
		foreach ( $selects as $select ) {
			$this->assertStringContainsString( "comment_approved = 'spam'", $select );
			$this->assertStringContainsString( "COALESCE( comment_type, '' ) != 'note'", $select, 'Spam cleanup must exclude WP 6.9+ Notes (issue #884).' );
		}
	}

	/**
	 * Test that clean_trashed_comments() excludes WP 6.9+ Notes from its SELECT.
	 */
	public function test_clean_trashed_comments_select_excludes_notes(): void {
		$this->install_capturing_wpdb();
		Functions\when( 'is_multisite' )->justReturn( false );

		$result = Database_Cleanup::clean_trashed_comments();

		$this->assertSame( 1, $result );
		$selects = $this->recorded_comment_selects();
		$this->assertNotEmpty( $selects, 'clean_trashed_comments() must run a comment_ID SELECT.' );
		foreach ( $selects as $select ) {
			$this->assertStringContainsString( "comment_approved = 'trash'", $select );
			$this->assertStringContainsString( "COALESCE( comment_type, '' ) != 'note'", $select, 'Trash cleanup must exclude WP 6.9+ Notes (issue #884).' );
		}
	}

	/**
	 * Test that get_counts() spam/trash counts use the same Notes predicate as
	 * cleanup so advertised counts match what cleanup would actually delete.
	 */
	public function test_get_counts_comment_counts_exclude_notes(): void {
		$this->install_capturing_wpdb();

		// Force the transient path so the counts are actually computed:
		// wp_cache_get_salted is declared by the bootstrap, so the salted path
		// must be disabled via wp_using_ext_object_cache().
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->alias(
			static function ( $key, $value, $expiration = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				return true;
			}
		);
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( 0 );
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$counts = Database_Cleanup::get_counts();

		$this->assertArrayHasKey( 'spam_comments', $counts );
		$this->assertArrayHasKey( 'trashed_comments', $counts );
		$this->assertSame( 0, $counts['spam_comments'] );
		$this->assertSame( 0, $counts['trashed_comments'] );

		$count_queries = array_values(
			array_filter(
				$this->queries,
				static function ( string $query ): bool {
					return false !== strpos( $query, 'COUNT(*)' ) && false !== strpos( $query, 'comment_approved' );
				}
			)
		);
		$this->assertCount( 1, $count_queries, 'Spam/trash comment counts are batched into a single UNION ALL query (audit #982).' );
		foreach ( $count_queries as $count_query ) {
			$this->assertStringContainsString( "comment_approved = 'spam'", $count_query );
			$this->assertStringContainsString( "comment_approved = 'trash'", $count_query );
			$this->assertSame( 2, substr_count( $count_query, "COALESCE( comment_type, '' ) != 'note'" ), 'Comment counts must exclude WP 6.9+ Notes with the same predicate as cleanup (issue #884).' );
		}
	}
}
