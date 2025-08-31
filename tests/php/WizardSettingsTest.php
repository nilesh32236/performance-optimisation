<?php
/**
 * Test class for wizard settings mapping functionality
 */

use PHPUnit\Framework\TestCase;

class WizardSettingsTest extends TestCase {

	private $rest_instance;

	public function setUp(): void {
		// Mock WordPress functions
		if ( ! function_exists( '__' ) ) {
			function __( $text, $domain = 'default' ) {
				return $text;
			}
		}

		if ( ! function_exists( 'current_time' ) ) {
			function current_time( $type ) {
				return date( 'Y-m-d H:i:s' );
			}
		}

		if ( ! function_exists( 'update_option' ) ) {
			function update_option( $option, $value ) {
				return true;
			}
		}

		if ( ! function_exists( 'delete_option' ) ) {
			function delete_option( $option ) {
				return true;
			}
		}

		if ( ! function_exists( 'delete_transient' ) ) {
			function delete_transient( $transient ) {
				return true;
			}
		}

		if ( ! function_exists( 'admin_url' ) ) {
			function admin_url( $path ) {
				return 'http://test.com/wp-admin/' . $path;
			}
		}

		// Mock the Rest class
		require_once __DIR__ . '/../../includes/class-rest.php';
		$this->rest_instance = new \PerformanceOptimise\Inc\Rest();
	}

	public function testStandardPresetMapping() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'standard', false, false )
		);

		// Test standard preset settings
		$this->assertTrue( $settings['cache_settings']['enablePageCaching'] );
		$this->assertTrue( $settings['image_optimisation']['lazyLoadImages'] );

		// Should not include advanced features
		$this->assertArrayNotHasKey( 'minifyCSS', $settings['file_optimisation'] );
		$this->assertArrayNotHasKey( 'minifyHTML', $settings['file_optimisation'] );
		$this->assertArrayNotHasKey( 'combineCSS', $settings['file_optimisation'] );
	}

	public function testRecommendedPresetMapping() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'recommended', false, false )
		);

		// Test recommended preset settings
		$this->assertTrue( $settings['cache_settings']['enablePageCaching'] );
		$this->assertTrue( $settings['image_optimisation']['lazyLoadImages'] );
		$this->assertTrue( $settings['file_optimisation']['minifyCSS'] );
		$this->assertTrue( $settings['file_optimisation']['minifyHTML'] );
		$this->assertTrue( $settings['file_optimisation']['combineCSS'] );

		// Should not include aggressive features
		$this->assertArrayNotHasKey( 'minifyJS', $settings['file_optimisation'] );
		$this->assertArrayNotHasKey( 'deferJS', $settings['file_optimisation'] );
		$this->assertArrayNotHasKey( 'delayJS', $settings['file_optimisation'] );
	}

	public function testAggressivePresetMapping() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'aggressive', false, false )
		);

		// Test aggressive preset settings
		$this->assertTrue( $settings['cache_settings']['enablePageCaching'] );
		$this->assertTrue( $settings['image_optimisation']['lazyLoadImages'] );
		$this->assertTrue( $settings['file_optimisation']['minifyCSS'] );
		$this->assertTrue( $settings['file_optimisation']['minifyHTML'] );
		$this->assertTrue( $settings['file_optimisation']['combineCSS'] );
		$this->assertTrue( $settings['file_optimisation']['minifyJS'] );
		$this->assertTrue( $settings['file_optimisation']['deferJS'] );
		$this->assertTrue( $settings['file_optimisation']['delayJS'] );
	}

	public function testPreloadCacheFeature() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'standard', true, false )
		);

		$this->assertTrue( $settings['preload_settings']['enablePreloadCache'] );
		$this->assertTrue( $settings['preload_settings']['enableCronJobs'] );
	}

	public function testImageConversionFeature() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'standard', false, true )
		);

		$this->assertTrue( $settings['image_optimisation']['convertImg'] );
		$this->assertEquals( 'webp', $settings['image_optimisation']['format'] );
	}

	public function testAllFeaturesEnabled() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'aggressive', true, true )
		);

		// Test all features are enabled
		$this->assertTrue( $settings['cache_settings']['enablePageCaching'] );
		$this->assertTrue( $settings['image_optimisation']['lazyLoadImages'] );
		$this->assertTrue( $settings['file_optimisation']['minifyCSS'] );
		$this->assertTrue( $settings['file_optimisation']['minifyHTML'] );
		$this->assertTrue( $settings['file_optimisation']['combineCSS'] );
		$this->assertTrue( $settings['file_optimisation']['minifyJS'] );
		$this->assertTrue( $settings['file_optimisation']['deferJS'] );
		$this->assertTrue( $settings['file_optimisation']['delayJS'] );
		$this->assertTrue( $settings['preload_settings']['enablePreloadCache'] );
		$this->assertTrue( $settings['preload_settings']['enableCronJobs'] );
		$this->assertTrue( $settings['image_optimisation']['convertImg'] );
		$this->assertEquals( 'webp', $settings['image_optimisation']['format'] );
	}

	public function testSettingsStructure() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'recommended', false, false )
		);

		// Test that all required sections exist
		$this->assertArrayHasKey( 'cache_settings', $settings );
		$this->assertArrayHasKey( 'file_optimisation', $settings );
		$this->assertArrayHasKey( 'image_optimisation', $settings );
		$this->assertArrayHasKey( 'preload_settings', $settings );

		// Test that sections are arrays
		$this->assertIsArray( $settings['cache_settings'] );
		$this->assertIsArray( $settings['file_optimisation'] );
		$this->assertIsArray( $settings['image_optimisation'] );
		$this->assertIsArray( $settings['preload_settings'] );
	}

	public function testInvalidPresetHandling() {
		$settings = $this->callPrivateMethod(
			$this->rest_instance,
			'build_wizard_settings',
			array( 'invalid_preset', false, false )
		);

		// Should still have base settings
		$this->assertTrue( $settings['cache_settings']['enablePageCaching'] );
		$this->assertTrue( $settings['image_optimisation']['lazyLoadImages'] );

		// Should not have advanced features for invalid preset
		$this->assertArrayNotHasKey( 'minifyCSS', $settings['file_optimisation'] );
	}

	/**
	 * Helper method to call private methods for testing
	 */
	private function callPrivateMethod( $object, $methodName, $args = array() ) {
		$reflection = new ReflectionClass( $object );
		$method     = $reflection->getMethod( $methodName );
		$method->setAccessible( true );

		return $method->invokeArgs( $object, $args );
	}
}
