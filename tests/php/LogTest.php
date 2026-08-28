<?php
/**
 * Tests for Log class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Log;
use Brain\Monkey\Functions;

/**
 * Tests for Log class.
 *
 * @package PerformanceOptimise\Tests
 */
class LogTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that add() inserts a row and bumps the salted cache salt.
	 */
	public function test_add_inserts_row_and_updates_salt(): void {
		$wpdb                = new WPPO_Log_DB_Mock();
		$GLOBALS['wpdb']     = $wpdb;
		$wpdb->insert_result = 1;

		Functions\stubs(
			array(
				'wp_kses_post',
				'update_option',
			)
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'update_option' )->alias(
			static function ( $option, $value, $autoload = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				$GLOBALS['wppo_test_options'][ $option ] = $value;
				return true;
			}
		);
		unset( $GLOBALS['wppo_test_options'] );

		Log::add( 'Page cache cleared' );

		$this->assertSame( 'wp_wppo_activity_logs', $wpdb->insert_table );
		$this->assertSame( 'Page cache cleared', $wpdb->insert_activity );
		$this->assertIsInt( $GLOBALS['wppo_test_options']['wppo_activity_log_salt'] );
	}

	/**
	 * Test that add() does not bump the salt when the insert fails.
	 */
	public function test_add_skips_salt_update_on_failed_insert(): void {
		$wpdb                = new WPPO_Log_DB_Mock();
		$GLOBALS['wpdb']     = $wpdb;
		$wpdb->insert_result = false;

		Functions\stubs(
			array(
				'wp_kses_post',
				'update_option',
			)
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'update_option' )->alias(
			static function () {
				$GLOBALS['wppo_test_options'][] = 'unexpected';
				return true;
			}
		);
		unset( $GLOBALS['wppo_test_options'] );

		Log::add( 'Page cache cleared' );

		$this->assertFalse( $wpdb->insert_result );
		$this->assertFalse( isset( $GLOBALS['wppo_test_options'] ) );
	}

	/**
	 * Test that get_recent_activities returns paginated data from the database.
	 */
	public function test_get_recent_activities_returns_paginated_data(): void {
		$wpdb            = new WPPO_Log_DB_Mock();
		$GLOBALS['wpdb'] = $wpdb;

		$wpdb->count = 25;
		$wpdb->rows  = array(
			array(
				'id'         => 2,
				'activity'   => 'Second',
				'created_at' => '2026-01-01 00:00:02',
			),
			array(
				'id'         => 1,
				'activity'   => 'First',
				'created_at' => '2026-01-01 00:00:01',
			),
		);

		$data = Log::get_recent_activities(
			array(
				'page'     => 1,
				'per_page' => 10,
			)
		);

		$this->assertSame( 2, count( $data['activities'] ) );
		$this->assertSame( 25, $data['total_items'] );
		$this->assertSame( 1, $data['current_page'] );
		$this->assertSame( 3.0, $data['total_pages'] );
		$this->assertSame( 10, $data['per_page'] );
	}

	/**
	 * Test that get_recent_activities returns cached data when present.
	 */
	public function test_get_recent_activities_returns_cached_data(): void {
		$GLOBALS['wpdb'] = new WPPO_Log_DB_Mock();

		// Seed the salted cache directly so the DB is never queried.
		$salt = (string) time();
		wp_cache_set_salted(
			'wppo_activity_logs_1_10',
			array(
				'activities'   => array(),
				'total_items'  => 0,
				'current_page' => 1,
				'total_pages'  => 0,
				'per_page'     => 10,
			),
			'wppo',
			$salt
		);
		Functions\when( 'get_option' )->justReturn( $salt );

		$data = Log::get_recent_activities(
			array(
				'page'     => 1,
				'per_page' => 10,
			)
		);

		$this->assertSame( 0, $data['total_items'] );
		$this->assertSame( 1, $data['current_page'] );
	}

	/**
	 * Test that get_recent_activities clamps per_page and page bounds.
	 */
	public function test_get_recent_activities_clamps_bounds(): void {
		$GLOBALS['wpdb'] = new WPPO_Log_DB_Mock();

		$data = Log::get_recent_activities(
			array(
				'page'     => 0,
				'per_page' => 500,
			)
		);

		$this->assertSame( 1, $data['current_page'] );
		$this->assertSame( 100, $data['per_page'] );
	}

	/**
	 * Test that get_recent_activities uses the versioned fallback cache path.
	 */
	public function test_get_recent_activities_fallback_cache_path(): void {
		$GLOBALS['wpdb'] = new WPPO_Log_DB_Mock();

		// Report wp_cache_get_salted() as unavailable so Log uses the versioned
		// wp_cache_get()/wp_cache_set() fallback, while every other function
		// (including Brain Monkey internals) keeps its real existence check.
		Functions\when( 'function_exists' )->alias(
			static function ( $name ) {
				return 'wp_cache_get_salted' !== $name;
			}
		);
		Functions\when( 'get_option' )->justReturn( 3 );

		$data = Log::get_recent_activities(
			array(
				'page'     => 1,
				'per_page' => 10,
			)
		);

		$this->assertSame( 10, $data['per_page'] );
		$this->assertSame( 1, $data['current_page'] );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * Minimal $wpdb stand-in for Log tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_Log_DB_Mock {
	/**
	 * Database table prefix.
	 *
	 * @var string
	 */
	public $prefix = 'wp_';

	/**
	 * Insert result.
	 *
	 * @var int|false
	 */
	public $insert_result = 1;

	/**
	 * Inserted table name.
	 *
	 * @var string
	 */
	public $insert_table = '';

	/**
	 * Inserted activity value.
	 *
	 * @var string
	 */
	public $insert_activity = '';

	/**
	 * Row count returned by get_var().
	 *
	 * @var int
	 */
	public $count = 0;

	/**
	 * Rows returned by get_results().
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public $rows = array();

	/**
	 * Simulate an insert into the activity log table.
	 *
	 * @param string $table  Table name.
	 * @param array  $data   Data to insert.
	 * @param array  $format Format specifiers (unused).
	 * @return int|false
	 */
	public function insert( $table, $data, $format = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->insert_table    = $table;
		$this->insert_activity = isset( $data['activity'] ) ? $data['activity'] : '';
		return $this->insert_result;
	}

	/**
	 * Return the configured count scalar.
	 *
	 * @param string $query SQL query (unused).
	 * @return int
	 */
	public function get_var( $query = null ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->count;
	}

	/**
	 * Return the configured rows.
	 *
	 * @param string $query SQL query (unused).
	 * @return array<int, array<string, mixed>>
	 */
	public function get_results( $query = null, $output = OBJECT ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->rows;
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
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
