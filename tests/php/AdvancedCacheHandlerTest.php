<?php
/**
 * Tests for Advanced_Cache_Handler class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Advanced_Cache_Handler;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Tests for Advanced_Cache_Handler class.
 *
 * @package PerformanceOptimise\Tests
 */
class AdvancedCacheHandlerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Install shared stubs: drop-in create/remove flush the System_Info
	 * drop-in-check transient (audit #888 finding 25). The common function
	 * stubs are re-registered because this setUp shadows the trait's.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->register_common_function_stubs();
		// This setUp shadows the trait's, which normally clears the
		// Util::get_settings() memo — clear it here so per-test get_option
		// stubs are re-read (#902 routes create() through the memo).
		Util::clear_settings_cache();
		Functions\stubs(
			array(
				'get_transient',
				'set_transient',
				'delete_transient',
				// Drop-in mutators bump the System_Info salted-cache salt
				// (issue #882) when the salted family is available.
				'get_option',
				'update_option',
			)
		);
	}

	/**
	 * Reset Brain Monkey and the per-test filesystem mock so the
	 * $GLOBALS['wp_filesystem'] assignment does not leak into later tests in
	 * the same process (Part 2 review round 3).
	 */
	protected function tearDown(): void {
		unset( $GLOBALS['wp_filesystem'] );
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

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
		// Atomic write: content goes to a tmp sibling first, then rename.
		$tmp_path = $fs->get_tmp_path();
		$this->assertStringContainsString( '.tmp.', $tmp_path );
		$this->assertStringNotContainsString( 'advanced-cache.php.wppo-backup', $tmp_path );
		$this->assertTrue( $fs->move_called );
		$this->assertSame( $fs->put_contents, $fs->contents );
		$this->assertStringContainsString( Advanced_Cache_Handler::DROPIN_MARKER, $fs->contents );
		// No previous file, so no backup is kept.
		$this->assertFalse( $fs->copy_called );
	}

	/**
	 * Test that a successful regeneration over an existing drop-in keeps a backup.
	 */
	public function test_create_keeps_backup_of_previous_dropin(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' old';
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

		$this->assertTrue( $fs->move_called );
		$this->assertTrue( $fs->copy_called );
		$backup_path = Advanced_Cache_Handler::get_dropin_backup_path();
		$this->assertArrayHasKey( $backup_path, $fs->copied );
		$this->assertStringContainsString( Advanced_Cache_Handler::DROPIN_MARKER, $fs->copied[ $backup_path ] );
		$this->assertStringContainsString( 'old', $fs->copied[ $backup_path ] );
		$this->assertStringContainsString( Advanced_Cache_Handler::DROPIN_MARKER, $fs->contents );
		// Tmp sibling is gone after the rename.
		$this->assertFalse( $fs->exists( $fs->get_tmp_path() ) );
	}

	/**
	 * Test that a tmp write failure keeps the old file and removes tmp.
	 */
	public function test_create_tmp_write_failure_keeps_old_file(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' old';
		$fs->put_result           = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		// Suppress drop-in issue logging (rate-limit transient hit).
		Functions\when( 'get_transient' )->justReturn( 1 );

		$this->assertFalse( Advanced_Cache_Handler::create() );

		$this->assertFalse( $fs->move_called );
		$this->assertSame( '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' old', $fs->contents );
		$this->assertContains( $fs->get_tmp_path(), $fs->deleted );
	}

	/**
	 * Test that a rename failure keeps the old file and removes tmp.
	 */
	public function test_create_rename_failure_keeps_old_file(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' old';
		$fs->move_result          = false;
		$fs->copy_result          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_transient' )->justReturn( 1 );

		$this->assertFalse( Advanced_Cache_Handler::create() );

		$this->assertTrue( $fs->move_called );
		$this->assertSame( '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' old', $fs->contents );
		$this->assertContains( $fs->get_tmp_path(), $fs->deleted );
	}

	/**
	 * Test that a foreign drop-in appearing mid-write is left untouched.
	 */
	public function test_create_leaves_mid_write_foreign_dropin_untouched(): void {
		$fs                                 = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists                    = false;
		$fs->become_foreign_after_tmp_write = true;
		$GLOBALS['wp_filesystem']           = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_transient' )->justReturn( 1 );

		$this->assertTrue( Advanced_Cache_Handler::create() );

		$this->assertFalse( $fs->move_called );
		$this->assertSame( '<?php // WP Super Cache drop-in', $fs->contents );
		$this->assertContains( $fs->get_tmp_path(), $fs->deleted );
	}

	/**
	 * Test that post-write verification failure restores the backup.
	 */
	public function test_create_post_write_failure_restores_backup(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = true;
		$fs->contents             = '<?php // ' . Advanced_Cache_Handler::DROPIN_MARKER . ' old';
		$fs->corrupt_final_once   = true;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);
		Functions\when( 'get_transient' )->justReturn( 1 );

		$this->assertFalse( Advanced_Cache_Handler::create() );

		// Backup was taken, then a restore from the backup was attempted.
		$backup_path = Advanced_Cache_Handler::get_dropin_backup_path();
		$this->assertArrayHasKey( $backup_path, $fs->copied );
		$restore_calls = array_filter(
			$fs->copy_calls,
			static function ( $call ) use ( $backup_path ) {
				return $backup_path === $call[0];
			}
		);
		$this->assertNotEmpty( $restore_calls );
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
		// LS-403: brotli support — signature now includes $brotli_file_path and $accept_encoding, and fallback contains cache_life.
		$this->assertStringContainsString( '$brotli_file_path', $fs->put_contents );
		$this->assertStringContainsString( '$accept_encoding', $fs->put_contents );
		$this->assertStringContainsString( 'Content-Encoding: br', $fs->put_contents );
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
	 * Test that create bakes the configured WooCommerce paths plus the
	 * session-cookie and add-to-cart guards when woo safe mode is enabled.
	 *
	 * Regression coverage for the coderabbit major on issue #922: a custom
	 * Woo slug (e.g. /basket/) must never reach wppo_serve_cache_file().
	 */
	public function test_create_bakes_configured_woo_paths_when_safe_mode_on(): void {
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
		// Safe mode defaults to enabled when the key is absent (fail-safe).
		// Drive the REAL Util::get_woo_excluded_paths() with stubbed Woo deps so
		// a custom slug (basket) is resolved like it would be on a live install.
		Functions\when( 'wc_get_page_id' )->alias(
			static function ( $key ) {
				$pages = array(
					'cart'      => 10,
					'checkout'  => 20,
					'myaccount' => 30,
				);
				return isset( $pages[ $key ] ) ? $pages[ $key ] : 0;
			}
		);
		Functions\when( 'get_permalink' )->justReturn( 'http://example.com/basket/' );
		Functions\when( 'wp_parse_url' )->justReturn( '/basket/' );

		$this->assertTrue( Advanced_Cache_Handler::create() );

		// Custom slug is folded into the pre-boot URI guard (preg_quote escapes
		// the hyphen in my-account).
		$this->assertStringContainsString( '(?:cart|checkout|my\-account|basket)', $fs->put_contents );
		// Session-cookie guard uses an (array) cast and is present while safe mode is on.
		$this->assertStringContainsString( 'foreach ( (array) $_COOKIE as $k => $v )', $fs->put_contents );
		$this->assertStringContainsString( 'wp_woocommerce_session_', $fs->put_contents );
		// add-to-cart guards are baked in.
		$this->assertStringContainsString( 'add-to-cart', $fs->put_contents );
	}

	/**
	 * Test that create restores pre-#922 drop-in behaviour when woo safe mode
	 * is disabled: the session-cookie and add-to-cart guards are omitted while
	 * the default cart/checkout/my-account protections remain unconditional.
	 */
	public function test_create_skips_woo_safe_guards_when_safe_mode_off(): void {
		$fs                       = new WPPO_AdvancedCache_FS_Mock();
		$fs->file_exists          = false;
		$GLOBALS['wp_filesystem'] = $fs;

		Functions\when( 'wp_normalize_path' )->returnArg();
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'get_option' )->justReturn(
			array(
				'cache_settings' => array( 'wooSafeMode' => false ),
			)
		);
		Functions\when( 'absint' )->alias(
			static function ( $value ) {
				return abs( (int) $value );
			}
		);

		$this->assertTrue( Advanced_Cache_Handler::create() );

		// Guard CODE is omitted (a generated comment may still mention
		// add-to-cart, so assert on the guard expressions themselves).
		$this->assertStringNotContainsString( 'wp_woocommerce_session_', $fs->put_contents );
		$this->assertStringNotContainsString( "isset( \$_GET['add-to-cart'] )", $fs->put_contents );
		$this->assertStringNotContainsString( '(?:^|&)add-to-cart(?:=|&|$)', $fs->put_contents );
		$this->assertStringNotContainsString( 'foreach ( (array) $_COOKIE as $k => $v )', $fs->put_contents );
		// The pre-#922 default URI guard is still baked in.
		$this->assertStringContainsString( '#^/(?:cart|checkout|my\-account)(?:/|$)#i', $fs->put_contents );
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
 * Simulates a tiny in-memory filesystem so the tmp-plus-rename write path
 * can be exercised: writes to tmp paths are stored per-path, move() promotes
 * tmp content to the handler file, and copy()/delete() are tracked.
 *
 * @package PerformanceOptimise\Tests
 */
class WPPO_AdvancedCache_FS_Mock {
	/**
	 * Whether the handler file exists (seed state, updated by move/copy).
	 *
	 * @var bool
	 */
	public $file_exists = false;

	/**
	 * Current handler file contents (seed state, updated by move/copy).
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
	 * Contents keyed by put_contents() path.
	 *
	 * @var array
	 */
	public $put_paths = array();

	/**
	 * Paths passed to failed put_contents() calls.
	 *
	 * @var array
	 */
	public $failed_put_paths = array();

	/**
	 * When false, put_contents() reports failure.
	 *
	 * @var bool
	 */
	public $put_result = true;

	/**
	 * Whether move() was called.
	 *
	 * @var bool
	 */
	public $move_called = false;

	/**
	 * When false, move() reports failure.
	 *
	 * @var bool
	 */
	public $move_result = true;

	/**
	 * List of array( $src, $dst ) move() calls.
	 *
	 * @var array
	 */
	public $moved = array();

	/**
	 * Whether copy() was called.
	 *
	 * @var bool
	 */
	public $copy_called = false;

	/**
	 * When false, copy() reports failure.
	 *
	 * @var bool
	 */
	public $copy_result = true;

	/**
	 * List of array( $src, $dst ) copy() calls.
	 *
	 * @var array
	 */
	public $copy_calls = array();

	/**
	 * Contents keyed by copy() destination path.
	 *
	 * @var array
	 */
	public $copied = array();

	/**
	 * Paths passed to delete().
	 *
	 * @var array
	 */
	public $deleted = array();

	/**
	 * Whether delete() was called.
	 *
	 * @var bool
	 */
	public $delete_called = false;

	/**
	 * When true, the handler flips to foreign content right after the tmp
	 * file is first read (simulates a foreign drop-in appearing mid-write).
	 *
	 * @var bool
	 */
	public $become_foreign_after_tmp_write = false;

	/**
	 * When true, the first handler read after a move/copy returns
	 * marker-less content (simulates post-write verification failure).
	 *
	 * @var bool
	 */
	public $corrupt_final_once = false;

	/**
	 * Whether the handler file was rewritten via move()/copy() since seeding.
	 *
	 * @var bool
	 */
	private $handler_rewritten = false;

	/**
	 * Whether the mid-write foreign flip already happened.
	 *
	 * @var bool
	 */
	private $foreign_flipped = false;

	/**
	 * Whether the one-time corrupt final read was consumed.
	 *
	 * @var bool
	 */
	private $corrupt_consumed = false;

	/**
	 * Whether a path is a tmp sibling.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_tmp_path( $path ): bool {
		return false !== strpos( (string) $path, '.tmp.' );
	}

	/**
	 * Whether a path is the backup sibling.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	private function is_backup_path( $path ): bool {
		return '.wppo-backup' === substr( (string) $path, -13 );
	}

	/**
	 * First tmp path written via put_contents(), or empty string.
	 *
	 * @return string
	 */
	public function get_tmp_path(): string {
		foreach ( $this->put_paths as $path => $ignored ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Key iteration only.
			return (string) $path;
		}
		foreach ( $this->failed_put_paths as $path ) {
			return (string) $path;
		}
		return '';
	}

	/**
	 * Simulate file existence.
	 *
	 * @param string $path File path.
	 * @return bool
	 */
	public function exists( $path ) {
		if ( $this->is_tmp_path( $path ) ) {
			return isset( $this->put_paths[ $path ] ) && ! in_array( $path, $this->deleted, true );
		}
		if ( $this->is_backup_path( $path ) ) {
			return isset( $this->copied[ $path ] ) && ! in_array( $path, $this->deleted, true );
		}
		return $this->file_exists && ! in_array( $path, $this->deleted, true );
	}

	/**
	 * Return file contents per path.
	 *
	 * @param string $path File path.
	 * @return string|false
	 */
	public function get_contents( $path ) {
		if ( $this->is_tmp_path( $path ) ) {
			$tmp = isset( $this->put_paths[ $path ] ) ? $this->put_paths[ $path ] : '';
			if ( $this->become_foreign_after_tmp_write && ! $this->foreign_flipped ) {
				$this->foreign_flipped = true;
				$this->file_exists     = true;
				$this->contents        = '<?php // WP Super Cache drop-in';
			}
			return $tmp;
		}
		if ( $this->is_backup_path( $path ) ) {
			return isset( $this->copied[ $path ] ) ? $this->copied[ $path ] : false;
		}
		if ( $this->corrupt_final_once && ! $this->corrupt_consumed && $this->handler_rewritten ) {
			$this->corrupt_consumed = true;
			return '<?php // corrupt file without the marker';
		}
		return $this->contents;
	}

	/**
	 * Record a write call.
	 *
	 * @param string $path     File path.
	 * @param string $contents Contents to write.
	 * @param int    $chmod    Chmod mode (unused).
	 * @return bool
	 */
	public function put_contents( $path, $contents, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->put_called   = true;
		$this->put_contents = $contents;
		if ( ! $this->put_result ) {
			$this->failed_put_paths[] = $path;
			return false;
		}
		$this->put_paths[ $path ] = $contents;
		if ( ! $this->is_tmp_path( $path ) && ! $this->is_backup_path( $path ) ) {
			$this->contents    = $contents;
			$this->file_exists = true;
		}
		return true;
	}

	/**
	 * Record a move call; promotes tmp content to the destination.
	 *
	 * @param string $src       Source path.
	 * @param string $dst       Destination path.
	 * @param bool   $overwrite Overwrite flag (unused).
	 * @return bool
	 */
	public function move( $src, $dst, $overwrite = false ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->move_called = true;
		if ( ! $this->move_result ) {
			return false;
		}
		$content = isset( $this->put_paths[ $src ] ) ? $this->put_paths[ $src ] : $this->contents;
		if ( $this->is_backup_path( $dst ) ) {
			$this->copied[ $dst ] = $content;
		} else {
			$this->contents          = $content;
			$this->file_exists       = true;
			$this->handler_rewritten = true;
		}
		$this->moved[]   = array( $src, $dst );
		$this->deleted[] = $src;
		return true;
	}

	/**
	 * Record a copy call.
	 *
	 * @param string $src       Source path.
	 * @param string $dst       Destination path.
	 * @param bool   $overwrite Overwrite flag (unused).
	 * @param int    $chmod     Chmod mode (unused).
	 * @return bool
	 */
	public function copy( $src, $dst, $overwrite = false, $chmod = 0 ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->copy_called  = true;
		$this->copy_calls[] = array( $src, $dst );
		if ( ! $this->copy_result ) {
			return false;
		}
		if ( $this->is_tmp_path( $src ) ) {
			$content = isset( $this->put_paths[ $src ] ) ? $this->put_paths[ $src ] : '';
		} elseif ( $this->is_backup_path( $src ) ) {
			$content = isset( $this->copied[ $src ] ) ? $this->copied[ $src ] : '';
		} else {
			$content = $this->contents;
		}
		$this->copied[ $dst ] = $content;
		if ( ! $this->is_backup_path( $dst ) && ! $this->is_tmp_path( $dst ) ) {
			$this->contents          = $content;
			$this->file_exists       = true;
			$this->handler_rewritten = true;
		}
		return true;
	}

	/**
	 * Record a delete call.
	 *
	 * @param string $path File path.
	 * @return true
	 */
	public function delete( $path ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->delete_called = true;
		$this->deleted[]     = $path;
		return true;
	}
}
// phpcs:enable Generic.Files.OneObjectStructurePerFile
