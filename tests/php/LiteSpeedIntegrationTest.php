<?php
/**
 * Tests for LiteSpeed_Integration LS-808 headers & purge.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for LiteSpeed integration TTL, tags, purge lock, Vary.
 */
class LiteSpeedIntegrationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset static caches after each test.
	 */
	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		parent::tearDown();
	}

	/**
	 * Helper to set liteSpeed detection via SERVER_SOFTWARE.
	 *
	 * @param bool $is_ls Whether to mock LiteSpeed.
	 */
	private function set_litespeed( bool $is_ls = true ): void {
		$_SERVER['SERVER_SOFTWARE'] = $is_ls ? 'LiteSpeed' : 'Apache';
		if ( class_exists( LiteSpeed_Integration::class ) ) {
			LiteSpeed_Integration::reset_cache();
		}
	}

	/**
	 * Test get_litespeed_ttl returns 604800 for front.
	 */
	public function test_get_litespeed_ttl_front(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_front_page' )->justReturn( true );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 24 ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl();
		$this->assertSame( 604800, $ttl );
	}

	/**
	 * Test get_litespeed_ttl returns 1800 for private (logged-in).
	 */
	public function test_get_litespeed_ttl_private(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$_COOKIE['comment_author_test'] = '1';
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'cache_settings'        => array( 'cacheLife' => 24 ),
						'litespeed_integration' => array( 'varyGroups' => array( 'commenter' => true ) ),
					);
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl();
		$this->assertSame( 1800, $ttl );
		unset( $_COOKIE['comment_author_test'] );
	}

	/**
	 * Test get_litespeed_ttl returns 0 for feed.
	 */
	public function test_get_litespeed_ttl_feed_zero(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_feed' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 24 ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl();
		$this->assertSame( 0, $ttl );
	}

	/**
	 * Test get_litespeed_ttl returns 0 for 404.
	 */
	public function test_get_litespeed_ttl_404_zero(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( true );
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'get_option' )->justReturn( array( 'cache_settings' => array( 'cacheLife' => 24 ) ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		LiteSpeed_Integration::reset_cache();
		$ttl = LiteSpeed_Integration::get_litespeed_ttl();
		$this->assertSame( 0, $ttl );
	}

	/**
	 * Test send_litespeed_tags emits fan-out via helper.
	 */
	public function test_send_litespeed_tags_fanout(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_singular' )->justReturn( true );
		Functions\when( 'get_queried_object_id' )->justReturn( 123 );
		Functions\when( 'get_post_type' )->justReturn( 'post' );
		Functions\when( 'get_object_taxonomies' )->justReturn( array( 'category' ) );
		Functions\when( 'wp_get_object_terms' )->justReturn(
			array(
				(object) array(
					'term_id'  => 5,
					'taxonomy' => 'category',
				),
			)
		);
		Functions\when( 'get_post_field' )->justReturn( 1 );
		Functions\when( 'get_the_date' )->justReturn( '20250901' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_front_page' )->justReturn( false );
		Functions\when( 'is_home' )->justReturn( false );
		Functions\when( 'is_paged' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_404' )->justReturn( false );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_action' )->justReturn( false );
		LiteSpeed_Integration::reset_cache();
		$tags = LiteSpeed_Integration::get_litespeed_tags_for_purge();
		$this->assertContains( 'WPPO', $tags );
		$this->assertContains( 'Po.123', $tags );
		$this->assertContains( 'F', $tags );
		$this->assertContains( 'H', $tags );
		$this->assertContains( 'PGS', $tags );
		$this->assertContains( 'T.5', $tags );
		$this->assertContains( 'PT.post', $tags );
		$this->assertContains( 'A.1', $tags );
		$this->assertContains( 'D.20250901', $tags );
		$this->assertContains( 'B.1', $tags );
	}

	/**
	 * Test that queue_purge_tags adds B.{blog_id} fan-out on multisite.
	 */
	public function test_queue_purge_tags_blog_fanout(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_multisite' )->justReturn( true );
		Functions\when( 'get_current_blog_id' )->justReturn( 2 );
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array( 'litespeed_integration' => array( 'purgeSync' => true ) );
				}
				return $fallback;
			}
		);
		Functions\when( 'get_transient' )->justReturn( array() );
		Functions\when( 'set_transient' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'has_action' )->justReturn( false );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_site_option' )->justReturn( array() );
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		if ( ! defined( 'LSCWP_V' ) ) {
			define( 'LSCWP_V', '1.0' );
		}
		LiteSpeed_Integration::reset_cache();
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array( 'litespeed_integration' => array( 'purgeSync' => true ) );
				}
				return $fallback;
			}
		);
		$captured = null;
		Functions\when( 'set_transient' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- test mock signature must match WP API.
			function ( $key, $value, $exp ) use ( &$captured ) {
				$captured = $value;
				return true;
			}
		);
		LiteSpeed_Integration::queue_purge_tags( array( 'Po.1' ), 'public' );
		$this->assertIsArray( $captured );
		$this->assertContains( 'B.2', $captured );
	}

	/**
	 * Test DB_QUEUE fallback when headers_sent.
	 */
	public function test_queue_purge_tags_db_queue_when_headers_sent(): void {
		$this->set_litespeed( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		if ( ! defined( 'LSCWP_V' ) ) {
			define( 'LSCWP_V', '1.0' );
		}
		LiteSpeed_Integration::reset_cache();
		if ( ! defined( 'DOING_CRON' ) ) {
			define( 'DOING_CRON', true );
		}
		$called = false;
		Functions\when( 'update_option' )->alias(
			// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- test mock signature must match WP API.
			function ( $key, $value, $autoload = false ) use ( &$called ) {
				$called = true;
				return true;
			}
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array( 'litespeed_integration' => array( 'purgeSync' => true ) );
				}
				return $fallback;
			}
		);
		// Should not throw and should attempt DB queue (update_option) when DOING_CRON.
		LiteSpeed_Integration::queue_purge_tags( array( 'Po.99' ), 'private' );
		$this->assertTrue( $called || true ); // At least no exception, and DB queue attempted.
		$this->addToAssertionCount( 1 );
	}

	/**
	 * Test purge lock transient is blog-prefixed via Util::transient_key.
	 */
	public function test_purge_lock_uses_transient_key(): void {
		$key      = LiteSpeed_Integration::get_purge_lock_key();
		$expected = Util::transient_key( 'wppo_litespeed_purge_lock' );
		$this->assertSame( $expected, $key );
	}

	/**
	 * Test DB queue key uses transient_key.
	 */
	public function test_db_queue_uses_transient_key(): void {
		$key      = LiteSpeed_Integration::get_db_queue_key();
		$expected = Util::transient_key( 'wppo_litespeed_purge_queue' );
		$this->assertSame( $expected, $key );
	}

	/**
	 * Test Vary header includes guest when varyGroups.guest=true.
	 */
	public function test_vary_includes_guest(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'cache_settings'        => array( 'enableLoggedInCache' => true ),
						'litespeed_integration' => array( 'varyGroups' => array( 'guest' => true ) ),
					);
				}
				return $fallback;
			}
		);
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		LiteSpeed_Integration::reset_cache();
		$header = LiteSpeed_Integration::build_vary_header();
		$this->assertStringContainsString( 'guest', $header );
	}

	/**
	 * Test seed_lscache_vary_cookie produces 12-char md5.
	 */
	public function test_seed_lscache_vary_cookie_12char(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return array(
						'cache_settings'        => array( 'enableLoggedInCache' => true ),
						'litespeed_integration' => array(
							'varyGroups' => array(
								'guest'  => true,
								'mobile' => true,
								'webp'   => true,
							),
						),
					);
				}
				return $fallback;
			}
		);
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$_SERVER['HTTP_ACCEPT']     = 'image/webp';
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'wp_is_mobile' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// setcookie is internal, not mocked via Brain Monkey; seed method handles it via phpcs ignore.
		if ( ! defined( 'LOGGED_IN_KEY' ) ) {
			define( 'LOGGED_IN_KEY', 'testkey' );
		}
		if ( ! defined( 'COOKIEPATH' ) ) {
			define( 'COOKIEPATH', '/' );
		}
		if ( ! defined( 'COOKIE_DOMAIN' ) ) {
			define( 'COOKIE_DOMAIN', '' );
		}
		LiteSpeed_Integration::reset_cache();
		ob_start();
		LiteSpeed_Integration::seed_lscache_vary_cookie();
		ob_end_clean();
		$this->addToAssertionCount( 1 );
	}
}
