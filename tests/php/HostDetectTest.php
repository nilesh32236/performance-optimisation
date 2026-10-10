<?php
/**
 * Tests for managed-host detection (issue #911).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Host_Detect;
use Brain\Monkey\Functions;

require_once __DIR__ . '/../../includes/Integrations/class-host-detect.php';

/**
 * Host detection tests.
 */
class HostDetectTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Clean server markers between tests.
	 *
	 * @return void
	 */
	private function clean_server(): void {
		foreach ( array( 'KINSTA_CACHE', 'HTTP_X_KINSTA_CACHE', 'X_WPE_CACHE', 'HTTP_X_WPE_CACHE', 'HTTP_X_SG_CACHE', 'SG_CACHEPRESS' ) as $key ) {
			unset( $_SERVER[ $key ] );
		}
		Host_Detect::reset_cache();
	}

	/**
	 * Install stubs with an optional host override.
	 *
	 * @param string|null $override Slug returned for the adapter filter, or null to keep probe result.
	 * @return void
	 */
	private function install_stubs( ?string $override = null ): void {
		$this->clean_server();
		Functions\stubs( array( 'apply_filters' ) );
		if ( null === $override ) {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) {
					return $value;
				}
			);
		} else {
			Functions\when( 'apply_filters' )->alias(
				static function ( $hook, $value ) use ( $override ) {
					if ( 'wppo_host_adapter' === $hook ) {
						return $override;
					}
					return $value;
				}
			);
		}
	}

	/**
	 * Unknown environments detect as none with no degrade.
	 *
	 * @return void
	 */
	public function test_unknown_returns_none_without_ban(): void {
		$this->install_stubs();
		$this->assertSame( 'none', Host_Detect::detect() );
		$this->assertFalse( Host_Detect::is_banned_conflict() );
		$this->assertTrue( Host_Detect::is_page_cache_allowed() );
		$this->assertSame( '', Host_Detect::get_degrade_reason() );
		$this->clean_server();
	}

	/**
	 * Filter override to Kinsta reports a banned conflict.
	 *
	 * @return void
	 */
	public function test_kinsta_override_is_banned_conflict(): void {
		$this->install_stubs( 'kinsta' );
		$this->assertSame( 'kinsta', Host_Detect::detect() );
		$this->assertTrue( Host_Detect::is_banned_conflict() );
		$this->assertFalse( Host_Detect::is_page_cache_allowed() );
		$this->assertNotSame( '', Host_Detect::get_degrade_reason() );
		$this->clean_server();
	}

	/**
	 * Filter override to SiteGround is known but not banned.
	 *
	 * @return void
	 */
	public function test_siteground_override_is_known_without_ban(): void {
		$this->install_stubs( 'siteground' );
		$this->assertSame( 'siteground', Host_Detect::detect() );
		$this->assertFalse( Host_Detect::is_banned_conflict() );
		$this->assertTrue( Host_Detect::is_page_cache_allowed() );
		$this->clean_server();
	}

	/**
	 * Custom filter slugs survive (filter extensibility); unknown hosts
	 * fail open downstream (no ban, host purger no-ops).
	 *
	 * @return void
	 */
	public function test_custom_filter_slug_survives_fail_open(): void {
		$this->install_stubs( 'acme-host' );
		$this->assertSame( 'acme-host', Host_Detect::detect() );
		$this->assertFalse( Host_Detect::is_banned_conflict() );
		$this->assertTrue( Host_Detect::is_page_cache_allowed() );
		$this->clean_server();
	}

	/**
	 * Server markers detect without constants.
	 *
	 * @return void
	 */
	public function test_server_marker_detects_kinsta(): void {
		$this->install_stubs();
		$_SERVER['KINSTA_CACHE'] = 'HIT';
		$this->assertSame( 'kinsta', Host_Detect::detect() );
		$this->assertTrue( Host_Detect::is_banned_conflict() );
		$this->clean_server();
	}

	/**
	 * Client-controlled request headers never force a host degrade.
	 *
	 * @return void
	 */
	public function test_spoofable_request_headers_are_ignored(): void {
		$this->install_stubs();
		$_SERVER['HTTP_X_KINSTA_CACHE'] = 'HIT';
		$_SERVER['HTTP_X_WPE_CACHE']    = 'HIT';
		$_SERVER['HTTP_X_SG_CACHE']     = 'HIT';
		$this->assertSame( 'none', Host_Detect::detect() );
		$this->assertFalse( Host_Detect::is_banned_conflict() );
		$this->assertTrue( Host_Detect::is_page_cache_allowed() );
		$this->clean_server();
	}

	/**
	 * Detection memoizes per request until reset_cache().
	 *
	 * @return void
	 */
	public function test_detect_memoizes_until_reset(): void {
		$this->install_stubs();
		$this->assertSame( 'none', Host_Detect::detect() );
		$_SERVER['KINSTA_CACHE'] = 'HIT';
		$this->assertSame( 'none', Host_Detect::detect() );
		Host_Detect::reset_cache();
		$this->assertSame( 'kinsta', Host_Detect::detect() );
		$this->clean_server();
	}
}
