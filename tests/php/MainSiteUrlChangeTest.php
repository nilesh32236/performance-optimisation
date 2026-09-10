<?php
/**
 * Tests for Main::on_site_url_change() drop-in regeneration.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Main::on_site_url_change() drop-in regeneration.
 *
 * @package PerformanceOptimise\Tests
 */
class MainSiteUrlChangeTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Fired do_action hooks captured per test.
	 *
	 * @var array
	 */
	private $fired_actions = array();

	/**
	 * Install shared stubs: mirrors AdvancedCacheHandlerTest so
	 * Advanced_Cache_Handler::create() runs deterministically.
	 *
	 * Note: this setUp shadows the trait's setUp, so it must replicate the
	 * Brain Monkey bootstrap.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		Util::clear_settings_cache();
		Functions\stubs(
			array(
				'get_transient',
				'set_transient',
				'delete_transient',
				'get_option',
				'update_option',
			)
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_option' )->justReturn( array() );
		$fired = &$this->fired_actions;
		Functions\when( 'do_action' )->alias(
			static function ( $hook, $arg = null ) use ( &$fired ) {
				$fired[] = array( $hook, $arg );
			}
		);
	}

	/**
	 * Reset Brain Monkey and the per-test filesystem mock so the
	 * $GLOBALS['wp_filesystem'] assignment does not leak into later tests in
	 * the same process.
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		$this->fired_actions = array();
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Test that an unchanged option value skips the re-bake.
	 */
	public function test_unchanged_value_skips_rebake(): void {
		$fs                       = new WPPO_SiteUrl_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		$this->assertTrue( Main::on_site_url_change( 'http://example.com', 'http://example.com', 'home' ) );
		$this->assertFalse( $fs->put_called );
	}

	/**
	 * Test that a scheme-only change (http->https) skips the re-bake because
	 * the canonical host is identical.
	 */
	public function test_scheme_only_change_skips_rebake(): void {
		$fs                       = new WPPO_SiteUrl_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		$this->assertTrue( Main::on_site_url_change( 'http://example.com', 'https://example.com/', 'home' ) );
		$this->assertFalse( $fs->put_called );
	}

	/**
	 * Test that a trailing-slash-only change skips the re-bake.
	 */
	public function test_trailing_slash_only_change_skips_rebake(): void {
		$fs                       = new WPPO_SiteUrl_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		$this->assertTrue( Main::on_site_url_change( 'http://example.com', 'http://example.com/', 'siteurl' ) );
		$this->assertFalse( $fs->put_called );
	}

	/**
	 * Test that a changed host re-bakes the drop-in with the new canonical.
	 */
	public function test_changed_host_rebakes_dropin(): void {
		$fs                       = new WPPO_SiteUrl_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'home_url' )->justReturn( 'https://newexample.com' );
		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertTrue( Main::on_site_url_change( 'http://example.com', 'https://newexample.com', 'home' ) );
		$this->assertTrue( $fs->put_called );
		$this->assertStringContainsString( "\$canonical_host = 'newexample.com';", $fs->put_contents );
	}

	/**
	 * Test that a drop-in regeneration failure returns false and logs.
	 */
	public function test_create_failure_returns_false_and_logs(): void {
		$fs                       = new WPPO_SiteUrl_Failing_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'home_url' )->justReturn( 'https://newexample.com' );
		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertFalse( Main::on_site_url_change( 'http://example.com', 'https://newexample.com', 'home' ) );

		$hooks = array_column( $this->fired_actions, 0 );
		$this->assertContains( 'wppo_debug_log', $hooks );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * Minimal WP_Filesystem stand-in recording drop-in writes.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_SiteUrl_FS_Mock {
	/**
	 * Whether the file exists.
	 *
	 * @var bool
	 */
	public $file_exists = false;

	/**
	 * File contents returned by get_contents().
	 *
	 * @var string
	 */
	public $contents = '';

	/**
	 * Whether put_contents() was called.
	 *
	 * @var bool
	 */
	public $put_called = false;

	/**
	 * Contents passed to the last put_contents() call.
	 *
	 * @var string
	 */
	public $put_contents = '';

	/**
	 * Simulate file existence.
	 *
	 * @param string $path File path (unused).
	 * @return bool
	 */
	public function exists( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->file_exists;
	}

	/**
	 * Return the configured file contents.
	 *
	 * @param string $path File path (unused).
	 * @return string
	 */
	public function get_contents( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return $this->contents;
	}

	/**
	 * Record a write call.
	 *
	 * @param string $path     File path (unused).
	 * @param string $contents Contents to write.
	 * @param int    $chmod    Chmod mode (unused).
	 * @return true
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_called   = true;
		$this->put_contents = $contents;
		return true;
	}

	/**
	 * Record a delete call.
	 *
	 * @param string $path File path (unused).
	 * @return true
	 */
	public function delete( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return true;
	}
}

/**
 * Filesystem stand-in whose writes fail, driving the create()-failure path.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_SiteUrl_Failing_FS_Mock extends WPPO_SiteUrl_FS_Mock {
	/**
	 * Record a write attempt and report failure.
	 *
	 * @param string $path     File path (unused).
	 * @param string $contents Contents to write (unused).
	 * @param int    $chmod    Chmod mode (unused).
	 * @return false
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_called = true;
		return false;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
