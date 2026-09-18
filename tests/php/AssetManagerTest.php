<?php
/**
 * Tests for Asset_Manager size capture and suggestion helpers.
 *
 * Covers the per-page Script Manager gap (issue #1390): captured assets
 * carry a fail-open `size` (int|null, local files only, never remote HTTP),
 * and the suggest-only assistant surfaces heavy non-protected handles
 * without ever writing post meta.
 *
 * @package PerformanceOptimise\Tests
 * @since NEXT
 */

use PerformanceOptimise\Inc\Asset_Manager;
use Brain\Monkey\Functions;

/**
 * Tests for the Asset_Manager class.
 *
 * @package PerformanceOptimise\Tests
 */
class AssetManagerTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		\PerformanceOptimise\Inc\Util::reset_runtime_caches();
		$this->register_common_function_stubs();
		Functions\when( 'has_filter' )->justReturn( false );
		Functions\when( 'is_multisite' )->justReturn( false );
	}

	/**
	 * Tear down Brain Monkey after each test.
	 */
	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Empty and non-string sources resolve to null without any I/O.
	 */
	public function test_resolve_asset_size_returns_null_for_empty_src(): void {
		$this->assertNull( Asset_Manager::resolve_asset_size( '' ) );
	}

	/**
	 * Remote/CDN URLs never trigger remote HTTP and resolve to null when
	 * no local file exists.
	 */
	public function test_resolve_asset_size_returns_null_for_remote_url_without_local_file(): void {
		$this->assertNull( Asset_Manager::resolve_asset_size( 'https://cdn.example.com/assets/heavy-slider.js?ver=1.2' ) );
	}

	/**
	 * A local file under ABSPATH resolves to its real filesize.
	 */
	public function test_resolve_asset_size_returns_filesize_for_local_file(): void {
		$tmp_file = tempnam( sys_get_temp_dir(), 'wppo-asset-' );
		$this->assertNotFalse( $tmp_file );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Test fixture.
		file_put_contents( $tmp_file, str_repeat( 'x', 2048 ) );

		// Map the temp file into ABSPATH so get_local_path() can reach it.
		$relative = 'wppo-test-asset-' . md5( (string) $tmp_file ) . '.js';
		$abspath  = wp_normalize_path( ABSPATH );
		if ( ! is_dir( $abspath ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- Test fixture.
			mkdir( $abspath, 0777, true );
		}
		$dest = $abspath . $relative;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- Test fixture.
		copy( $tmp_file, $dest );

		try {
			$this->assertSame( 2048, Asset_Manager::resolve_asset_size( 'http://example.com/' . $relative ) );
		} finally {
			if ( file_exists( $dest ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $dest );
			}
			if ( file_exists( $tmp_file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Test fixture cleanup.
				unlink( $tmp_file );
			}
		}
	}

	/**
	 * The assistant suggests heavy non-protected handles largest-first,
	 * skips protected handles and light assets, and never writes meta.
	 */
	public function test_get_asset_suggestions_skips_protected_and_light_assets(): void {
		$assets = array(
			'scripts' => array(
				array(
					'handle' => 'heavy-slider',
					'src'    => 'http://example.com/slider.js',
					'size'   => 200000,
				),
				array(
					'handle' => 'jquery',
					'src'    => 'http://example.com/jquery.js',
					'size'   => 300000,
				),
				array(
					'handle' => 'tiny-widget',
					'src'    => 'http://example.com/tiny.js',
					'size'   => 1024,
				),
				array(
					'handle' => 'unknown-size',
					'src'    => 'http://example.com/unknown.js',
					'size'   => null,
				),
			),
			'styles'  => array(
				array(
					'handle' => 'heavy-theme',
					'src'    => 'http://example.com/theme.css',
					'size'   => 150000,
				),
			),
		);

		$suggestions = Asset_Manager::get_asset_suggestions( $assets );

		$handles = array_column( $suggestions, 'handle' );
		$this->assertSame( array( 'heavy-slider', 'heavy-theme' ), $handles );
		foreach ( $suggestions as $suggestion ) {
			$this->assertContains( $suggestion['type'], array( 'script', 'style' ) );
			$this->assertIsInt( $suggestion['size'] );
		}
	}

	/**
	 * Non-array input degrades to an empty suggestion list, never fatal.
	 */
	public function test_get_asset_suggestions_fails_open_on_invalid_input(): void {
		$this->assertSame( array(), Asset_Manager::get_asset_suggestions( false ) );
		$this->assertSame( array(), Asset_Manager::get_asset_suggestions( array() ) );
	}

	/**
	 * Protected handle lists still guard the core handles.
	 */
	public function test_protected_handles_include_core(): void {
		$this->assertContains( 'jquery', Asset_Manager::get_protected_scripts() );
		$this->assertContains( 'admin-bar', Asset_Manager::get_protected_styles() );
	}
}
