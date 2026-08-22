<?php
/**
 * Tests for Advanced_Cache_Handler class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use Brain\Monkey\Functions;

/**
 * Tests for Advanced_Cache_Handler class.
 *
 * @package PerformanceOptimise\Tests
 */
class AdvancedCacheHandlerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Test that get_dropin_path returns the WP_CONTENT_DIR path.
	 */
	public function test_get_dropin_path_returns_content_path(): void {
		Functions\when( 'wp_normalize_path' )->returnArg();
		$this->assertSame(
			'/tmp/wordpress/wp-content/advanced-cache.php',
			Advanced_Cache_Handler::get_dropin_path()
		);
	}

	/**
	 * Test that is_our_dropin returns false when no drop-in file exists.
	 */
	public function test_is_our_dropin_false_when_missing(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertFalse( Advanced_Cache_Handler::is_our_dropin() );
	}

	/**
	 * Test that is_our_dropin detects the plugin marker.
	 */
	public function test_is_our_dropin_detects_marker(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' ...';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertTrue( Advanced_Cache_Handler::is_our_dropin() );
	}

	/**
	 * Test that is_our_dropin detects legacy drop-ins via the function marker.
	 */
	public function test_is_our_dropin_detects_legacy_marker(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php function is_user_logged_in_without_wp( $site_url ) {}';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertTrue( Advanced_Cache_Handler::is_our_dropin() );
	}

	/**
	 * Test that is_our_dropin returns false for a foreign drop-in.
	 */
	public function test_is_our_dropin_false_for_foreign_content(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // WP Super Cache drop-in';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertFalse( Advanced_Cache_Handler::is_our_dropin() );
	}

	/**
	 * Test that foreign_dropin_present is false when no drop-in exists.
	 */
	public function test_foreign_dropin_present_false_when_missing(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertFalse( Advanced_Cache_Handler::foreign_dropin_present() );
	}

	/**
	 * Test that foreign_dropin_present is true for a non-plugin drop-in.
	 */
	public function test_foreign_dropin_present_true_for_foreign(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // WP Super Cache drop-in';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$this->assertTrue( Advanced_Cache_Handler::foreign_dropin_present() );
	}

	/**
	 * Test that create writes the handler file with the plugin marker.
	 */
	public function test_create_writes_handler_with_marker(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$result = Advanced_Cache_Handler::create();

		$this->assertTrue( $result );
		$this->assertTrue( $fs->put_called );
		$this->assertStringContainsString( Advanced_Cache_Handler::DROPIN_MARKER, $fs->put_contents );
	}

	/**
	 * Test that create bakes the configured cache life into the drop-in.
	 */
	public function test_create_bakes_configured_cache_life(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array( 'cacheLife' => 24 ),
			)
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$this->assertTrue( Advanced_Cache_Handler::create() );

		$this->assertStringContainsString( '$cache_life    = 24;', $fs->put_contents );
		$this->assertStringContainsString( '> $cache_life * 3600', $fs->put_contents );
		$this->assertStringContainsString( ', $cache_life );', $fs->put_contents );
	}

	/**
	 * Test that create defaults to a never-expiring cache when unset.
	 */
	public function test_create_defaults_to_never_expiring_cache(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$this->assertTrue( Advanced_Cache_Handler::create() );

		$this->assertStringContainsString( '$cache_life    = 0;', $fs->put_contents );
	}

	/**
	 * Test that create leaves a foreign drop-in untouched.
	 */
	public function test_create_skips_foreign_dropin(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // WP Super Cache drop-in';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();

		$result = Advanced_Cache_Handler::create();

		$this->assertTrue( $result );
		$this->assertFalse( $fs->put_called );
	}

	/**
	 * Test that remove deletes only our own drop-in.
	 */
	public function test_remove_deletes_our_dropin(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' ...';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		Advanced_Cache_Handler::remove();

		$this->assertTrue( $fs->delete_called );
	}

	/**
	 * Test that remove does not delete a foreign drop-in.
	 */
	public function test_remove_skips_foreign_dropin(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // WP Super Cache drop-in';
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'WP_Filesystem' )->justReturn( true );

		Advanced_Cache_Handler::remove();

		$this->assertFalse( $fs->delete_called );
	}
}

// phpcs:disable Generic.Files.OneObjectStructurePerFile

/**
 * Minimal WP_Filesystem stand-in for Advanced_Cache_Handler tests.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_AdvancedCache_FS_Mock {
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
	 * Whether delete() was called.
	 *
	 * @var bool
	 */
	public $delete_called = false;

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
		$this->delete_called = true;
		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
