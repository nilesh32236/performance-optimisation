<?php
/**
 * Tests for Main::should_load_litespeed_stack() gate (issue #1443).
 *
 * Covers the fail-open gate matrix: management contexts (WP-CLI, cron,
 * admin, REST) always load, LiteSpeed/OpenLiteSpeed server strings load,
 * Apache/Nginx/missing strings skip, and the documented
 * `wppo_litespeed_is_litespeed` filter override is honoured via the filtered
 * LiteSpeed_Integration::is_litespeed() detector.
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.NamingConventions.ValidFunctionName.MethodNameInvalid
 */

use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Main;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

/**
 * Gate matrix tests for the LiteSpeed-only crawler + ESI stack loader.
 */
class LiteSpeedStackGateTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Reset per-request memos and server string between tests.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		unset( $_SERVER['SERVER_SOFTWARE'] );
		parent::tearDown();
	}

	/**
	 * Stub a plain frontend request: no cron, no admin, passthrough filters.
	 *
	 * @return void
	 */
	private function stub_frontend_request(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	/**
	 * Invoke the private gate with a cleared LiteSpeed_Integration memo.
	 *
	 * @return bool Gate verdict.
	 */
	private function call_gate(): bool {
		if ( class_exists( LiteSpeed_Integration::class ) && method_exists( LiteSpeed_Integration::class, 'reset_cache' ) ) {
			LiteSpeed_Integration::reset_cache();
		}
		$method = new \ReflectionMethod( Main::class, 'should_load_litespeed_stack' );
		return $method->invoke( null );
	}

	/**
	 * Apache frontend requests skip the LiteSpeed stack.
	 *
	 * @return void
	 */
	public function test_returns_false_for_apache(): void {
		$this->stub_frontend_request();
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertFalse( $this->call_gate() );
	}

	/**
	 * Nginx frontend requests skip the LiteSpeed stack.
	 *
	 * @return void
	 */
	public function test_returns_false_for_nginx(): void {
		$this->stub_frontend_request();
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.18.0';
		$this->assertFalse( $this->call_gate() );
	}

	/**
	 * A missing SERVER_SOFTWARE string skips the LiteSpeed stack.
	 *
	 * @return void
	 */
	public function test_returns_false_when_server_software_missing(): void {
		$this->stub_frontend_request();
		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertFalse( $this->call_gate() );
	}

	/**
	 * LiteSpeed frontend requests load the stack.
	 *
	 * @return void
	 */
	public function test_returns_true_for_litespeed(): void {
		$this->stub_frontend_request();
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertTrue( $this->call_gate() );
	}

	/**
	 * OpenLiteSpeed frontend requests load the stack.
	 *
	 * 'OpenLiteSpeed' contains 'litespeed', so a single strpos covers both
	 * variants (guards the redundant-disjunct cleanup).
	 *
	 * @return void
	 */
	public function test_returns_true_for_openlitespeed(): void {
		$this->stub_frontend_request();
		$_SERVER['SERVER_SOFTWARE'] = 'OpenLiteSpeed';
		$this->assertTrue( $this->call_gate() );
	}

	/**
	 * The documented filter override forces the stack on for non-LiteSpeed.
	 *
	 * Mirrors docs/hooks.md: a proxy-header override returning true must load
	 * the stack even when SERVER_SOFTWARE says Apache.
	 *
	 * @return void
	 */
	public function test_filter_override_forces_true_on_apache(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_litespeed_is_litespeed' === $tag ) {
					return true;
				}
				return $value;
			}
		);
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertTrue( $this->call_gate() );
	}

	/**
	 * The documented filter override can force the stack off on LiteSpeed.
	 *
	 * @return void
	 */
	public function test_filter_override_forces_false_on_litespeed(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'wppo_litespeed_is_litespeed' === $tag ) {
					return false;
				}
				return $value;
			}
		);
		$_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
		$this->assertFalse( $this->call_gate() );
	}

	/**
	 * Cron requests always load the stack, even on Apache.
	 *
	 * @return void
	 */
	public function test_cron_forces_true_on_apache(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( true );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertTrue( $this->call_gate() );
	}

	/**
	 * Admin requests always load the stack, even on Apache.
	 *
	 * @return void
	 */
	public function test_admin_forces_true_on_apache(): void {
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertTrue( $this->call_gate() );
	}

	/**
	 * REST requests always load the stack, even on Apache.
	 *
	 * Separate process: the REST_REQUEST constant cannot be undefined once
	 * defined in the shared suite process.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rest_forces_true_on_apache(): void {
		if ( ! defined( 'REST_REQUEST' ) ) {
			define( 'REST_REQUEST', true );
		}
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertTrue( $this->call_gate() );
	}

	/**
	 * WP-CLI always loads the stack, even on Apache.
	 *
	 * Separate process: the WP_CLI constant cannot be undefined once defined
	 * in the shared suite process.
	 *
	 * @return void
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_wp_cli_forces_true_on_apache(): void {
		if ( ! defined( 'WP_CLI' ) ) {
			define( 'WP_CLI', true );
		}
		Functions\when( 'wp_doing_cron' )->justReturn( false );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertTrue( $this->call_gate() );
	}
}
