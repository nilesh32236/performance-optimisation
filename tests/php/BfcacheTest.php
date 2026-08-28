<?php
/**
 * Regression tests for H-04 bfcache filter_nocache_headers dead branch.
 *
 * Verifies the fix that collapsed `if(null===$token){ dead inner } else` into
 * `if(null===$token) return;` — ensuring no-store is only stripped when a
 * session token exists.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Bfcache;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Bfcache class.
 */
class BfcacheTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * In-memory options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Install common stubs.
	 */
	private function install_stubs(): void {
		Functions\stubs(
			array(
				'get_option',
				'apply_filters',
				'is_user_logged_in',
				'get_current_user_id',
				'wp_get_session_token',
				'is_ssl',
				'wp_parse_url',
				'home_url',
				'get_current_blog_id',
				'is_multisite',
			)
		);
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return $this->options;
				}
				if ( 'home' === $name ) {
					return 'http://example.com';
				}
				return $fallback;
			}
		);
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return $value;
			}
		);
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'get_current_user_id' )->justReturn( 1 );
		Functions\when( 'wp_get_session_token' )->justReturn( 'sess123' );
		Functions\when( 'is_ssl' )->justReturn( false );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_current_blog_id' )->justReturn( 1 );
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	/**
	 * Ensure WP_Session_Tokens stub exists.
	 *
	 * @param string|null $token Token to return from get('sess123') or null for no token.
	 * @return void
	 */
	private function ensure_session_tokens( ?string $token ): void {
		if ( ! class_exists( 'WP_Session_Tokens' ) ) {
			eval(
				'
                class WP_Session_Tokens {
                    private static $instance;
                    public $session_token;
                    public static function get_instance( $user_id ) {
                        if ( null === self::$instance ) {
                            self::$instance = new self();
                        }
                        return self::$instance;
                    }
                    public function get( $token ) {
                        if ( isset($GLOBALS["wppo_bfcache_session"]) && is_array($GLOBALS["wppo_bfcache_session"]) ) {
                            return $GLOBALS["wppo_bfcache_session"];
                        }
                        return array();
                    }
                }
                '
			);
		}
		if ( null === $token ) {
			$GLOBALS['wppo_bfcache_session'] = array();
		} else {
			$GLOBALS['wppo_bfcache_session'] = array( Bfcache::SESSION_KEY => $token );
		}
	}

	/**
	 * Test that filter returns headers unchanged when token is null.
	 */
	public function test_filter_nocache_headers_returns_unchanged_when_token_null(): void {
		$this->install_stubs();
		$this->options = array( 'bfcache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->ensure_session_tokens( null );

		$headers = array( 'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0' );
		$result  = Bfcache::filter_nocache_headers( $headers );
		$this->assertSame( $headers, $result, 'Headers must be unchanged when token is null' );
	}

	/**
	 * Test that filter strips no-store when token exists.
	 */
	public function test_filter_nocache_headers_strips_no_store_when_token_present(): void {
		$this->install_stubs();
		$this->options = array( 'bfcache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->ensure_session_tokens( 'tok1234567890123456789012345678901234567890123' );

		// Prevent cookie repair setcookie path by pre-populating $_COOKIE.
		$cookie_name             = Bfcache::get_cookie_name();
		$_COOKIE[ $cookie_name ] = 'tok1234567890123456789012345678901234567890123';

		$headers = array( 'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0' );
		$result  = Bfcache::filter_nocache_headers( $headers );

		unset( $_COOKIE[ $cookie_name ] );

		$this->assertIsArray( $result );
		$this->assertArrayHasKey( 'Cache-Control', $result );
		$this->assertStringNotContainsString( 'no-store', $result['Cache-Control'] );
		$this->assertStringContainsString( 'private', $result['Cache-Control'] );
		$this->assertStringContainsString( 'no-cache', $result['Cache-Control'] );
		$this->assertStringContainsString( 'max-age=0', $result['Cache-Control'] );
		$this->assertStringContainsString( 'must-revalidate', $result['Cache-Control'] );
	}

	/**
	 * Test that filter returns unchanged when bfcache disabled.
	 */
	public function test_filter_nocache_headers_unchanged_when_disabled(): void {
		$this->install_stubs();
		$this->options = array( 'bfcache' => array( 'enabled' => false ) );
		Util::clear_settings_cache();
		$this->ensure_session_tokens( 'tok1234567890123456789012345678901234567890123' );

		$headers = array( 'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0' );
		$result  = Bfcache::filter_nocache_headers( $headers );
		$this->assertSame( $headers, $result );
	}

	/**
	 * Test that filter does not touch headers when Cache-Control missing or not string.
	 */
	public function test_filter_handles_missing_cache_control(): void {
		$this->install_stubs();
		$this->options = array( 'bfcache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->ensure_session_tokens( 'tok' );

		$this->assertSame( array(), Bfcache::filter_nocache_headers( array() ) );
		$this->assertSame( array( 'Cache-Control' => 123 ), Bfcache::filter_nocache_headers( array( 'Cache-Control' => 123 ) ) );
		// Non-array input coerced to array and returned.
		$this->assertSame( array(), Bfcache::filter_nocache_headers( 'not-array' ) );
	}

	/**
	 * Test that token-less session does not trigger cookie repair.
	 */
	public function test_dead_code_inner_branch_never_executes_when_token_null(): void {
		$this->install_stubs();
		$this->options = array( 'bfcache' => array( 'enabled' => true ) );
		Util::clear_settings_cache();
		$this->ensure_session_tokens( null );

		// Previously dead code had inner `if (null !== $token)` after outer `if(null===$token)`.
		// That inner branch could never execute. After fix, the path is just early return.
		// Verify that headers unchanged and no exception regardless of $_COOKIE state.
		$cookie_name             = Bfcache::get_cookie_name();
		$_COOKIE[ $cookie_name ] = 'some-value';

		$headers = array( 'Cache-Control' => 'no-cache, no-store, must-revalidate, max-age=0' );
		$result  = Bfcache::filter_nocache_headers( $headers );

		unset( $_COOKIE[ $cookie_name ] );

		$this->assertSame( $headers, $result, 'Headers must be unchanged when token null even with cookie present' );
	}
}
