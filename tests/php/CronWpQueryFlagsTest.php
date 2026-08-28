<?php
/**
 * Regression tests for P2 WP_Query flags (no_found_rows, update_*).
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Cron;
use Brain\Monkey\Functions;

/**
 * Tests that preload cron and Used_CSS queries avoid SQL_CALC_FOUND_ROWS and cache hydration.
 */
class CronWpQueryFlagsTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Build Cron without constructor.
	 *
	 * @return Cron
	 */
	private function make_cron(): Cron {
		return ( new ReflectionClass( Cron::class ) )->newInstanceWithoutConstructor();
	}

	/**
	 * Test schedule_page_cron_jobs passes no_found_rows and cache flags.
	 */
	public function test_schedule_page_cron_jobs_uses_optimized_query_flags(): void {
		Functions\stubs(
			array(
				'get_option',
				'get_transient',
				'set_transient',
				'delete_transient',
				'delete_option',
				'update_option',
				'get_post_types',
				'home_url',
				'esc_url_raw',
				'wp_remote_request',
				'is_multisite',
				'get_current_blog_id',
				'wp_parse_url',
			)
		);
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_option' )->alias(
			static function ( $name, $fallback = false ) {
				if ( 'wppo_preload_cron_offset' === $name ) {
					return 0;
				}
				if ( 'wppo_settings' === $name ) {
					return array(
						'preload_settings' => array( 'enablePreloadCache' => false ),
						'cache_settings'   => array(),
					);
				}
				return $fallback;
			}
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post', 'page' => 'page' ) );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );

		$captured_args = null;
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args = $args;
				return array(); // Empty to trigger early return.
			}
		);

		$cron = $this->make_cron();
		$method = new ReflectionMethod( Cron::class, 'schedule_page_cron_jobs' );
		$method->setAccessible( true );
		$method->invoke( $cron );

		$this->assertIsArray( $captured_args, 'get_posts must be called' );
		$this->assertArrayHasKey( 'no_found_rows', $captured_args );
		$this->assertTrue( $captured_args['no_found_rows'], 'no_found_rows must be true to avoid SQL_CALC_FOUND_ROWS' );
		$this->assertArrayHasKey( 'update_post_meta_cache', $captured_args );
		$this->assertFalse( $captured_args['update_post_meta_cache'] );
		$this->assertArrayHasKey( 'update_post_term_cache', $captured_args );
		$this->assertFalse( $captured_args['update_post_term_cache'] );
		$this->assertSame( 200, $captured_args['posts_per_page'] );
		$this->assertSame( 'ids', $captured_args['fields'] );
	}

	/**
	 * Test Used_CSS::regenerate_all passes same optimized flags.
	 */
	public function test_used_css_regenerate_all_uses_optimized_query_flags(): void {
		Functions\stubs(
			array(
				'get_post_types',
				'as_has_scheduled_action',
				'as_enqueue_async_action',
				'__',
			)
		);
		Functions\when( 'get_post_types' )->justReturn( array( 'post' => 'post', 'page' => 'page' ) );
		Functions\when( 'as_has_scheduled_action' )->justReturn( false );
		Functions\when( 'as_enqueue_async_action' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();

		$captured_args = array();
		Functions\when( 'get_posts' )->alias(
			static function ( $args ) use ( &$captured_args ) {
				$captured_args[] = $args;
				return array(); // Stop loop after first batch (empty triggers break).
			}
		);

		$used_css = ( new ReflectionClass( \PerformanceOptimise\Inc\Used_CSS::class ) )->newInstanceWithoutConstructor();
		$method = new ReflectionMethod( \PerformanceOptimise\Inc\Used_CSS::class, 'regenerate_all' );
		$method->setAccessible( true );
		$method->invoke( $used_css );

		$this->assertNotEmpty( $captured_args, 'get_posts must be called in Used_CSS::regenerate_all' );
		$first = $captured_args[0];
		$this->assertTrue( $first['no_found_rows'] );
		$this->assertFalse( $first['update_post_meta_cache'] );
		$this->assertFalse( $first['update_post_term_cache'] );
		$this->assertSame( 200, $first['posts_per_page'] );
		$this->assertSame( 'ids', $first['fields'] );
		$this->assertSame( 0, $first['offset'] );
	}
}
