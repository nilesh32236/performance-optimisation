<?php
/**
 * Tests that Main, Util, and Activate share a single defaults source (#901).
 *
 * @package PerformanceOptimise\Tests
 *
 * @phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */

use PerformanceOptimise\Inc\Activate;
use PerformanceOptimise\Inc\Util;
use Brain\Monkey\Functions;

/**
 * Defaults-parity tests for the canonical Util::get_default_settings().
 *
 * @package PerformanceOptimise\Tests
 */
class DefaultsParityTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Canonical defaults must carry the previously drifted keys.
	 */
	public function test_canonical_defaults_contain_preload_and_cdn_keys(): void {
		$defaults = Util::get_default_settings();

		$this->assertArrayHasKey( 'cdnMapping', $defaults['file_optimisation'] );
		$this->assertSame( array(), $defaults['file_optimisation']['cdnMapping'] );

		$this->assertArrayHasKey( 'enablePreloadCache', $defaults['preload_settings'] );
		$this->assertFalse( $defaults['preload_settings']['enablePreloadCache'] );

		$this->assertArrayHasKey( 'excludePreloadCache', $defaults['preload_settings'] );
		$this->assertSame(
			"my-account/(.*)\ncart/(.*)\ncheckout/(.*)",
			$defaults['preload_settings']['excludePreloadCache'],
			'SPA parity: must match the PreloadSettings.js defaultSettings string'
		);

		// The default must split into the three exclusion rules via process_urls().
		$this->assertSame(
			array( 'my-account/(.*)', 'cart/(.*)', 'checkout/(.*)' ),
			Util::process_urls( $defaults['preload_settings']['excludePreloadCache'] )
		);

		// Dynamic keys evaluate in-process; assert presence, not a hardcoded value.
		$this->assertArrayHasKey( 'blockAssetsOnDemand', $defaults['file_optimisation'] );
		$this->assertArrayHasKey( 'enabled', $defaults['od_integration'] );

		// WooCommerce safe mode default (issue #922) must be enabled + boolean.
		$this->assertArrayHasKey( 'wooSafeMode', $defaults['cache_settings'] );
		$this->assertTrue( $defaults['cache_settings']['wooSafeMode'] );

		// AVIF-first picture output defaults (issue #931): additive keys only.
		$this->assertArrayHasKey( 'avifFirst', $defaults['image_optimisation'] );
		$this->assertTrue( $defaults['image_optimisation']['avifFirst'] );
		$this->assertArrayHasKey( 'smartQuality', $defaults['image_optimisation'] );
		$this->assertTrue( $defaults['image_optimisation']['smartQuality'] );
		$this->assertArrayHasKey( 'skipSmallThresholdBytes', $defaults['image_optimisation'] );
		$this->assertSame( 5120, $defaults['image_optimisation']['skipSmallThresholdBytes'] );

		// Missing-alt autofill + longest-edge cap (issue #985): additive keys only.
		$this->assertArrayHasKey( 'autoAltText', $defaults['image_optimisation'] );
		$this->assertFalse( $defaults['image_optimisation']['autoAltText'] );
		$this->assertArrayHasKey( 'maxLongestEdgePx', $defaults['image_optimisation'] );
		$this->assertSame( 2560, $defaults['image_optimisation']['maxLongestEdgePx'] );

		// AI Adaptive WP-client opt-in (issue #964): additive keys only, off by default.
		// Field-LCP min samples (issue #986): additive key, 20 by default.
		// Anomaly detection (issue #1040): additive keys, 7-day cooldown + 10 min samples.
		$this->assertSame(
			array(
				'enabled'               => false,
				'use_wp_ai_client'      => false,
				'field_lcp_min_samples' => 20,
				'anomaly_cooldown_days' => 7,
				'anomaly_min_samples'   => 10,
			),
			$defaults['ai_adaptive']
		);
	}

	/**
	 * Activate must seed exactly the canonical defaults on fresh installs.
	 */
	public function test_activate_seeds_canonical_defaults(): void {
		Functions\when( 'get_option' )->alias(
			function ( $name, $fallback = false ) {
				if ( 'wppo_settings' === $name ) {
					return null;
				}
				return $fallback;
			}
		);

		$seeded = null;
		Functions\when( 'add_option' )->alias(
			function ( $name, $value, $deprecated = '', $autoload = null ) use ( &$seeded ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
				if ( 'wppo_settings' === $name ) {
					$seeded = $value;
				}
				return true;
			}
		);

		$method = new ReflectionMethod( Activate::class, 'maybe_seed_settings' );
		$method->setAccessible( true );
		$method->invoke( null );

		$this->assertNotNull( $seeded, 'Fresh install must seed wppo_settings' );
		$this->assertSame( Util::get_default_settings(), $seeded );
	}

	/**
	 * Main::__construct() must consume the canonical defaults (no duplicate literal).
	 */
	public function test_main_consumes_canonical_defaults(): void {
		$path = dirname( __DIR__, 2 ) . '/includes/class-main.php';
		$this->assertFileExists( $path );
		$source = file_get_contents( $path );
		$this->assertIsString( $source );

		$this->assertStringContainsString(
			'Util::get_default_settings()',
			$source,
			'Main::__construct() must delegate to Util::get_default_settings()'
		);

		// The old inline literal keyed cache_settings inside __construct must be gone.
		$this->assertSame(
			0,
			substr_count( $source, "'cache_settings'        => array(" ),
			'Main must not carry its own duplicate defaults array'
		);
	}
}
