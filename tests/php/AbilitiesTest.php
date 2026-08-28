<?php
/**
 * Tests for Abilities class — DB ability enum alignment (P1 regression).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Abilities;
use PerformanceOptimise\Inc\Database_Cleanup;
use Brain\Monkey\Functions;

/**
 * Regression tests for the database-cleanup ability enum.
 *
 * The ability's input_schema enum must align with Database_Cleanup's
 * canonical keys (e.g. `trashed_posts`, not `trash`) so that the
 * Abilities API and the REST/DB layer share a single source of truth.
 *
 * @package PerformanceOptimise\Tests
 */
class AbilitiesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that the run-database-cleanup ability enum matches the canonical set.
	 *
	 * @since NEXT
	 */
	public function test_database_cleanup_ability_enum_matches_canonical(): void {
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'add_action' )->justReturn( null );

		$abilities = new Abilities();

		$reflection = new ReflectionMethod( $abilities, 'get_operational_abilities' );
		$reflection->setAccessible( true );
		$operational = $reflection->invoke( $abilities );

		$db_ability = null;
		foreach ( $operational as $ability ) {
			if ( 'performance-optimisation/run-database-cleanup' === $ability['id'] ) {
				$db_ability = $ability;
				break;
			}
		}

		$this->assertNotNull( $db_ability, 'run-database-cleanup ability must be registered' );

		$enum = $db_ability['args']['input_schema']['properties']['type']['enum'];

		// Canonical keys are TABLE_MAP keys + 'all'.
		$expected = array_merge( array_keys( Database_Cleanup::TABLE_MAP ), array( 'all' ) );
		sort( $enum );
		sort( $expected );

		$this->assertSame( $expected, $enum, 'Ability enum must match Database_Cleanup::TABLE_MAP keys + all' );

		// Explicit regression: old value 'trash' must not appear; canonical 'trashed_posts' must.
		$this->assertNotContains( 'trash', $enum, 'Ability enum must not contain legacy "trash" alias' );
		$this->assertContains( 'trashed_posts', $enum, 'Ability enum must contain "trashed_posts"' );
		$this->assertNotContains( 'spam', $enum, 'Ability enum must not contain short "spam" alias' );
		$this->assertContains( 'spam_comments', $enum );
		$this->assertNotContains( 'transients', $enum );
		$this->assertContains( 'expired_transients', $enum );
		$this->assertNotContains( 'orphans', $enum );
		$this->assertContains( 'orphan_postmeta', $enum );
	}

	/**
	 * Test that execute_database_cleanup accepts trashed_posts and rejects the legacy trash alias.
	 *
	 * @since NEXT
	 */
	public function test_execute_database_cleanup_accepts_trashed_posts_rejects_trash(): void {
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'delete_transient' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( time() );

		// Stub $wpdb for clean_trashed_posts: first get_col returns 3 IDs, second returns empty to end loop.
		$GLOBALS['wpdb'] = new WPPO_DB_Mock_Abilities();
		$GLOBALS['wpdb']->query_return = 3;

		$result_canonical = Abilities::execute_database_cleanup( array( 'type' => 'trashed_posts' ) );
		$this->assertSame( 3, $result_canonical['cleaned'], 'trashed_posts must be accepted and cleaned' );

		$result_legacy = Abilities::execute_database_cleanup( array( 'type' => 'trash' ) );
		$this->assertSame( 0, $result_legacy['cleaned'], 'legacy trash alias must be rejected (0 cleaned)' );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

if ( ! class_exists( 'WPPO_DB_Mock_Abilities' ) ) {
	/**
	 * Minimal WPDB mock for Abilities tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WPPO_DB_Mock_Abilities {
		/**
		 * Table names.
		 *
		 * @var string
		 */
		public $posts       = 'wp_posts';
		public $postmeta    = 'wp_postmeta';
		public $comments    = 'wp_comments';
		public $commentmeta = 'wp_commentmeta';
		public $options     = 'wp_options';
		public $prefix      = 'wp_';
		public $last_error  = '';

		/**
		 * Configurable return for query.
		 *
		 * @var int
		 */
		public $query_return = 0;

		/**
		 * Count get_col calls.
		 *
		 * @var int
		 */
		public $get_col_calls = 0;

		/**
		 * Mock get_col — returns 3 IDs on first call, empty thereafter to terminate batch loop.
		 *
		 * @param string $query Query.
		 * @return array
		 */
		public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			++$this->get_col_calls;
			if ( 1 === $this->get_col_calls ) {
				return array( 1, 2, 3 );
			}
			return array();
		}

		/**
		 * Mock query.
		 *
		 * @param string $query Query.
		 * @return int
		 */
		public function query( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $this->query_return;
		}

		/**
		 * Mock prepare.
		 *
		 * @param string $query Query.
		 * @param mixed  ...$args Args.
		 * @return string
		 */
		public function prepare( $query, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return $query;
		}

		/**
		 * Mock esc_like.
		 *
		 * @param string $text Text.
		 * @return string
		 */
		public function esc_like( $text ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $text;
		}

		/**
		 * Mock get_var.
		 *
		 * @param string $query Query.
		 * @return null
		 */
		public function get_var( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return null;
		}

		/**
		 * Mock get_results.
		 *
		 * @param string $query Query.
		 * @return array
		 */
		public function get_results( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
