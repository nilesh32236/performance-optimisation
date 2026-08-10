<?php
/**
 * Tests for Server_Rules class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Server_Rules;
use Brain\Monkey\Functions;

/**
 * Tests for Server_Rules class.
 *
 * @package PerformanceOptimise\Tests
 */
class ServerRulesTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that get_server_type detects Apache from SERVER_SOFTWARE.
	 */
	public function test_get_server_type_detects_apache(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4.41 (Ubuntu)';
		$this->assertSame( 'apache', Server_Rules::get_server_type() );
	}

	/**
	 * Test that get_server_type detects Nginx from SERVER_SOFTWARE.
	 */
	public function test_get_server_type_detects_nginx(): void {
		$_SERVER['SERVER_SOFTWARE'] = 'nginx/1.18.0';
		$this->assertSame( 'nginx', Server_Rules::get_server_type() );
	}

	/**
	 * Test that get_server_type returns 'other' for unknown servers.
	 */
	public function test_get_server_type_returns_other_for_unknown(): void {
		unset( $_SERVER['SERVER_SOFTWARE'] );
		$this->assertSame( 'other', Server_Rules::get_server_type() );
	}

	/**
	 * Test that get_nginx_rules includes gzip when minify is enabled.
	 */
	public function test_get_nginx_rules_includes_gzip_when_minify_enabled(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'minifyJS' => true,
				),
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rules = Server_Rules::get_nginx_rules();

		$this->assertStringContainsString( 'gzip on;', $rules );
		$this->assertStringContainsString( 'gzip_types', $rules );
		$this->assertStringNotContainsString( '# Browser Caching', $rules );
	}

	/**
	 * Test that get_nginx_rules includes browser caching when enabled.
	 */
	public function test_get_nginx_rules_includes_browser_caching_when_enabled(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'enableServerRules' => true,
				),
			)
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rules = Server_Rules::get_nginx_rules();

		$this->assertStringContainsString( '# Browser Caching', $rules );
		$this->assertStringContainsString( 'expires 365d;', $rules );
		$this->assertStringNotContainsString( 'gzip on;', $rules );
	}

	/**
	 * Test that get_nginx_rules returns an empty string when nothing is enabled.
	 */
	public function test_get_nginx_rules_empty_when_nothing_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$rules = Server_Rules::get_nginx_rules();

		$this->assertSame( '', $rules );
	}

	/**
	 * Test that get_nginx_rules passes rules through the wppo_nginx_rules filter.
	 */
	public function test_get_nginx_rules_applies_filter(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'file_optimisation' => array(
					'minifyCSS' => true,
				),
			)
		);
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wppo_nginx_rules', \Mockery::type( 'string' ) )
			->andReturn( 'filtered' );

		$this->assertSame( 'filtered', Server_Rules::get_nginx_rules() );
	}

	/**
	 * Test that get_apache_rules proxies to Htaccess_Handler.
	 */
	public function test_get_apache_rules_proxies_to_htaccess_handler(): void {
		$rules = Server_Rules::get_apache_rules();

		$this->assertIsString( $rules );
	}

	/**
	 * Test that get_apache_rules includes deflate rules from Htaccess_Handler.
	 */
	public function test_get_apache_rules_includes_deflate_rules(): void {
		$rules = Server_Rules::get_apache_rules();

		$this->assertStringContainsString( 'mod_deflate', $rules );
		$this->assertStringContainsString( 'mod_expires', $rules );
	}
}
