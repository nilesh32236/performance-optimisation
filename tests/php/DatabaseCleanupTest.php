<?php
/**
 * Tests for Database_Cleanup class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Database_Cleanup;
use Brain\Monkey\Functions;

/**
 * Tests for Database_Cleanup class.
 *
 * @package PerformanceOptimise\Tests
 */
class DatabaseCleanupTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that TABLE_MAP contains all expected cleanup types.
	 */
	public function test_table_map_is_correct(): void {
		$map = Database_Cleanup::TABLE_MAP;
		$this->assertArrayHasKey( 'revisions', $map );
		$this->assertArrayHasKey( 'auto_drafts', $map );
		$this->assertArrayHasKey( 'trashed_posts', $map );
		$this->assertArrayHasKey( 'spam_comments', $map );
		$this->assertArrayHasKey( 'trashed_comments', $map );
		$this->assertArrayHasKey( 'expired_transients', $map );
		$this->assertArrayHasKey( 'orphan_postmeta', $map );
		$this->assertArrayHasKey( 'unattached_media', $map );
		$this->assertArrayHasKey( 'oembed_cache', $map );
		$this->assertSame( array( 'posts', 'postmeta' ), $map['revisions'] );
		$this->assertSame( array( 'options' ), $map['expired_transients'] );
	}

	/**
	 * Test that get_autoloaded_options returns rows ordered by size.
	 */
	public function test_get_autoloaded_options_returns_sorted_rows(): void {
		$GLOBALS['wpdb'] = new class() extends WPPO_DB_Mock {
			/**
			 * Return sample autoloaded rows.
			 *
			 * @param string $query SQL query (unused).
			 * @return array
			 */
			public function get_results( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
				return array(
					array(
						'option_name' => 'small_option',
						'opt_size'    => 10,
					),
					array(
						'option_name' => 'big_option',
						'opt_size'    => 5000,
					),
				);
			}
		};

		$result = Database_Cleanup::get_autoloaded_options( 5 );

		$this->assertSame( 'small_option', $result[0]['option_name'] );
		$this->assertSame( 10, $result[0]['size'] );
		$this->assertSame( 'big_option', $result[1]['option_name'] );
		$this->assertSame( 5000, $result[1]['size'] );
	}

	/**
	 * Test that get_autoloaded_options returns an empty array on no rows.
	 */
	public function test_get_autoloaded_options_empty_when_no_rows(): void {
		$GLOBALS['wpdb'] = new WPPO_DB_Mock();

		$result = Database_Cleanup::get_autoloaded_options();

		$this->assertSame( array(), $result );
	}

	/**
	 * Test that clean_revisions returns zero when no revisions exist.
	 */
	public function test_clean_revisions_returns_zero_when_no_revisions(): void {
		$GLOBALS['wpdb'] = new WPPO_DB_Mock();

		Functions\stubs(
			array(
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = Database_Cleanup::clean_revisions();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that clean_auto_drafts returns zero when none exist.
	 */
	public function test_clean_auto_drafts_returns_zero_when_none_exist(): void {
		$GLOBALS['wpdb'] = new WPPO_DB_Mock();

		Functions\stubs(
			array(
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = Database_Cleanup::clean_auto_drafts();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that clean_expired_transients returns zero when none exist.
	 */
	public function test_clean_expired_transients_returns_zero_when_none_exist(): void {
		$GLOBALS['wpdb'] = new WPPO_DB_Mock();

		Functions\stubs(
			array(
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'is_multisite' )->justReturn( false );

		$result = Database_Cleanup::clean_expired_transients();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that expected methods exist on the class.
	 */
	public function test_methods_exist(): void {
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_revisions' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_auto_drafts' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_trashed_posts' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_spam_comments' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_trashed_comments' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_expired_transients' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_orphan_postmeta' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_unattached_media' ) );
		$this->assertTrue( method_exists( Database_Cleanup::class, 'clean_oembed_cache' ) );
	}

	/**
	 * Test that clean_unattached_media returns zero when none exist.
	 */
	public function test_clean_unattached_media_returns_zero_when_none_exist(): void {
		$GLOBALS['wpdb'] = new WPPO_DB_Mock();

		Functions\stubs(
			array(
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = Database_Cleanup::clean_unattached_media();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that clean_oembed_cache returns zero when none exist.
	 */
	public function test_clean_oembed_cache_returns_zero_when_none_exist(): void {
		$GLOBALS['wpdb'] = new WPPO_DB_Mock();

		Functions\stubs(
			array(
				'wp_normalize_path',
				'sanitize_text_field',
				'wp_unslash',
			)
		);
		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$result = Database_Cleanup::clean_oembed_cache();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that TABLE_MAP contains the new cleanup types.
	 */
	public function test_table_map_contains_new_types(): void {
		$map = Database_Cleanup::TABLE_MAP;
		$this->assertArrayHasKey( 'unattached_media', $map );
		$this->assertArrayHasKey( 'oembed_cache', $map );
		$this->assertSame( array( 'posts', 'postmeta' ), $map['unattached_media'] );
		$this->assertSame( array( 'options' ), $map['oembed_cache'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile
// WP core is not loaded in the unit test environment, so a minimal WPDB stand-in
// is required to invoke the Database_Cleanup methods.

if ( ! class_exists( 'WPPO_DB_Mock' ) ) {
	/**
	 * Minimal WPDB mock for Database_Cleanup unit tests.
	 *
	 * @package PerformanceOptimise\Tests
	 */
	class WPPO_DB_Mock {
		/**
		 * Database table prefix.
		 *
		 * @var string
		 */
		public $prefix = 'wp_';

		/**
		 * Last error state.
		 *
		 * @var string
		 */
		public $last_error = '';

		/**
		 * Table name properties used by the plugin's SQL.
		 *
		 * @var string
		 */
		// phpcs:disable Squiz.Commenting.VariableComment -- Table-name stand-ins mirror $wpdb.
		public $posts              = 'wp_posts';
		public $postmeta           = 'wp_postmeta';
		public $comments           = 'wp_comments';
		public $commentmeta        = 'wp_commentmeta';
		public $options            = 'wp_options';
		public $usermeta           = 'wp_usermeta';
		public $users              = 'wp_users';
		public $terms              = 'wp_terms';
		public $term_taxonomy      = 'wp_term_taxonomy';
		public $termmeta           = 'wp_termmeta';
		public $term_relationships = 'wp_term_relationships';
		// phpcs:enable Squiz.Commenting.VariableComment

		/**
		 * Return an empty column list (no rows to clean).
		 *
		 * @param string $query SQL query (unused).
		 * @return array<int, mixed>
		 */
		public function get_col( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}

		/**
		 * Return null scalar (no rows to clean).
		 *
		 * @param string $query SQL query (unused).
		 * @return null
		 */
		public function get_var( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return null;
		}

		/**
		 * Return an empty result set (no rows to clean).
		 *
		 * @param string $query SQL query (unused).
		 * @return array<int, mixed>
		 */
		public function get_results( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		}

		/**
		 * Simulate a successful no-op query.
		 *
		 * @param string $query SQL query (unused).
		 * @return int Number of affected rows.
		 */
		public function query( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return 0;
		}

		/**
		 * Return the query unchanged.
		 *
		 * @param string $query SQL query.
		 * @param mixed  ...$args Prepared arguments (unused).
		 * @return string
		 */
		public function prepare( $query, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			return $query;
		}

		/**
		 * Return the text unchanged.
		 *
		 * @param string $text Text to escape (unused).
		 * @return string
		 */
		public function esc_like( $text ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return $text;
		}
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
