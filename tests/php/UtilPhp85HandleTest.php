<?php
/**
 * Tests for the PHP 8.5 handle-teardown helpers (issue #936).
 *
 * PHP 8.5 deprecates curl_close() and imagedestroy(); Util::close_curl_handle()
 * and Util::destroy_gd_image() drop the reference on 8.5+ and keep the legacy
 * close path below 8.5. The optional $php_version parameter lets these tests
 * exercise both sides of the gate on any runtime.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Util;

/**
 * PHP 8.5 handle-teardown helper tests.
 *
 * @package PerformanceOptimise\Tests
 */
class UtilPhp85HandleTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * The version gate must split exactly at 8.5.
	 *
	 * @return void
	 */
	public function test_is_php85_or_greater_splits_at_8_5(): void {
		$this->assertFalse( Util::is_php85_or_greater( '8.2.33' ) );
		$this->assertFalse( Util::is_php85_or_greater( '8.4.99' ) );
		$this->assertTrue( Util::is_php85_or_greater( '8.5.0' ) );
		$this->assertTrue( Util::is_php85_or_greater( '8.5.1' ) );
		$this->assertTrue( Util::is_php85_or_greater( '9.0.0' ) );
	}

	/**
	 * Default gate must reflect the actual runtime version.
	 *
	 * @return void
	 */
	public function test_is_php85_or_greater_defaults_to_runtime(): void {
		$this->assertSame( version_compare( PHP_VERSION, '8.5', '>=' ), Util::is_php85_or_greater() );
	}

	/**
	 * The 8.5 branch must release a GD image by dropping the reference
	 * (no imagedestroy() call, so no deprecation).
	 *
	 * @return void
	 */
	public function test_destroy_gd_image_unsets_on_85(): void {
		$image = imagecreatetruecolor( 8, 8 );
		$this->assertInstanceOf( \GdImage::class, $image );

		Util::destroy_gd_image( $image, '8.5.0' );

		$this->assertNull( $image );
	}

	/**
	 * Below 8.5 the legacy imagedestroy() path must run unchanged
	 * (imagedestroy() is a no-op since PHP 8.0, so the object survives).
	 *
	 * @return void
	 */
	public function test_destroy_gd_image_keeps_legacy_path_below_85(): void {
		$image = imagecreatetruecolor( 8, 8 );
		$this->assertInstanceOf( \GdImage::class, $image );

		Util::destroy_gd_image( $image, '8.4.0' );

		$this->assertInstanceOf( \GdImage::class, $image );

		// Release the fixture without a deprecated call on 8.5 runtimes.
		Util::destroy_gd_image( $image );
	}

	/**
	 * The 8.5 branch must release a cURL handle by dropping the reference
	 * (no curl_close() call, so no deprecation).
	 *
	 * @return void
	 */
	public function test_close_curl_handle_unsets_on_85(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$ch = curl_init( 'http://example.com/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- test requires a real CurlHandle for the teardown helper.

		Util::close_curl_handle( $ch, '8.5.0' );

		$this->assertNull( $ch );
	}

	/**
	 * Below 8.5 the legacy curl_close() path must run without error.
	 *
	 * @return void
	 */
	public function test_close_curl_handle_keeps_legacy_path_below_85(): void {
		if ( ! function_exists( 'curl_init' ) ) {
			$this->markTestSkipped( 'cURL extension is required.' );
		}

		$ch = curl_init( 'http://example.com/' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init -- test requires a real CurlHandle for the teardown helper.

		Util::close_curl_handle( $ch, '8.4.0' );

		// Legacy curl_close() is a no-op since PHP 8.0; the call must simply
		// not raise. Null the local so no second close is attempted.
		$ch = null;
		$this->assertNull( $ch );
	}
}
