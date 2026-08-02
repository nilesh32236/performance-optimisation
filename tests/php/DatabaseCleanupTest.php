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
		$this->assertSame( array( 'posts', 'postmeta' ), $map['revisions'] );
		$this->assertSame( array( 'options' ), $map['expired_transients'] );
	}

	/**
	 * Test that clean_revisions returns zero when no revisions exist.
	 */
	public function test_clean_revisions_returns_zero_when_no_revisions(): void {
		global $wpdb;
		$wpdb->last_error = '';

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

		$wpdb->get_col = function ( $query ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		};

		$result = Database_Cleanup::clean_revisions();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that clean_auto_drafts returns zero when none exist.
	 */
	public function test_clean_auto_drafts_returns_zero_when_none_exist(): void {
		global $wpdb;
		$wpdb->last_error = '';

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

		$wpdb->get_col = function ( $query ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		};

		$result = Database_Cleanup::clean_auto_drafts();
		$this->assertSame( 0, $result );
	}

	/**
	 * Test that clean_expired_transients returns zero when none exist.
	 */
	public function test_clean_expired_transients_returns_zero_when_none_exist(): void {
		global $wpdb;
		$wpdb->last_error = '';

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

		$wpdb->get_col = function ( $query ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return array();
		};

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
	}
}
