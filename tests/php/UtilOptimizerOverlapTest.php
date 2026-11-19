<?php
/**
 * Tests for Util::detect_conflicting_optimizers() (issue #1843).
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Feature-level third-party optimizer overlap detection.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilOptimizerOverlapTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Stub the admin-context probe so no entry is detected by default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'is_plugin_active' )->justReturn( false );
	}

	/**
	 * A clean install (no probe-active optimizer) reports no probe-only entry.
	 *
	 * Process-shared constants (e.g. LSCWP_V defined by another suite) can
	 * legitimately surface, so only the constant-free optimizers are asserted.
	 */
	public function test_clean_install_reports_no_probe_detected_optimizers(): void {
		$found = Util::detect_conflicting_optimizers();

		$this->assertArrayNotHasKey( 'autoptimize', $found );
		$this->assertArrayNotHasKey( 'perfmatters', $found );
		$this->assertArrayNotHasKey( 'rapidload', $found );
		$this->assertArrayNotHasKey( 'wp-rocket', $found );
		$this->assertArrayNotHasKey( 'smush', $found );
		$this->assertArrayNotHasKey( 'shortpixel', $found );
	}

	/**
	 * An active Autoptimize install is reported as a CSS/JS overlap.
	 */
	public function test_autoptimize_is_detected_as_css_js_overlap(): void {
		Functions\when( 'is_plugin_active' )->alias(
			static function ( $plugin ) {
				return 'autoptimize/autoptimize.php' === $plugin;
			}
		);

		$found = Util::detect_conflicting_optimizers();

		$this->assertArrayHasKey( 'autoptimize', $found );
		$this->assertSame( 'Autoptimize', $found['autoptimize']['label'] );
		$this->assertSame( 'css_js', $found['autoptimize']['area'] );
	}

	/**
	 * A media optimizer is reported with the media area.
	 */
	public function test_media_optimizer_is_detected_with_media_area(): void {
		Functions\when( 'is_plugin_active' )->alias(
			static function ( $plugin ) {
				return 'wp-smushit/wp-smush.php' === $plugin;
			}
		);

		$found = Util::detect_conflicting_optimizers();

		$this->assertArrayHasKey( 'smush', $found );
		$this->assertSame( 'media', $found['smush']['area'] );
	}

	/**
	 * Detection fails open when the probe function throws.
	 */
	public function test_probe_failure_fails_open(): void {
		Functions\when( 'is_plugin_active' )->alias(
			static function () {
				throw new \RuntimeException( 'probe failed' );
			}
		);

		$found = Util::detect_conflicting_optimizers();

		$this->assertArrayNotHasKey( 'autoptimize', $found );
		$this->assertArrayNotHasKey( 'smush', $found );
	}

	/**
	 * The registry entries carry a label and a valid area.
	 */
	public function test_registry_entries_are_well_formed(): void {
		$allowed = array( 'css_js', 'media', 'cache' );

		foreach ( Util::get_conflicting_optimizer_registry() as $entry ) {
			$this->assertIsArray( $entry );
			$this->assertArrayHasKey( 'label', $entry );
			$this->assertArrayHasKey( 'area', $entry );
			$this->assertArrayHasKey( 'constant', $entry );
			$this->assertArrayHasKey( 'plugin', $entry );
			$this->assertContains( $entry['area'], $allowed );
			$this->assertNotSame( '', $entry['label'] );
		}
	}
}
