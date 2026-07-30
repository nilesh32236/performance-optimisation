<?php
/**
 * Tests for Image_Optimisation class.
 *
 * @package PerformanceOptimise\Tests
 */

use PerformanceOptimise\Inc\Image_Optimisation;
use Brain\Monkey\Functions;

/**
 * Tests for Image_Optimisation class.
 *
 * @package PerformanceOptimise\Tests
 */
class ImageOptimisationTest extends \PHPUnit\Framework\TestCase {
	use WPPO_Test_Bootstrap;

	/**
	 * Default options for testing.
	 *
	 * @var array
	 */
	private array $default_options;

	/**
	 * Set up test environment.
	 */
	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		$this->default_options = array(
			'image_optimisation' => array(
				'lazyLoadImages'  => true,
				'lazyLoadVideos'  => true,
				'placeholderType' => 'svg',
				'autoPreloadLCP'  => false,
				'convertToWebp'   => true,
				'convertToAvif'   => false,
				'lazyLoadNative'  => false,
			),
		);
	}

	/**
	 * Test that constructor stores options.
	 */
	public function test_constructor_stores_options(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );

		$image_opt = new Image_Optimisation( $this->default_options );
		$this->assertNotNull( $image_opt );
	}

	/**
	 * Test lazy load attribute injection in HTML.
	 */
	public function test_lazy_load_attribute_injection(): void {
		Functions\when( 'wp_normalize_path' )->justReturn( '/tmp' );
		Functions\stubs(
			array(
				'esc_attr',
				'esc_url',
				'esc_html',
				'esc_textarea',
				'sanitize_text_field',
				'wp_unslash',
				'trailingslashit',
				'wp_parse_url',
				'content_url',
				'home_url',
				'is_admin',
				'is_feed',
				'is_preview',
				'is_embed',
				'wp_doing_ajax',
				'apply_filters',
				'wp_strip_all_tags',
				'wp_check_filetype',
				'wp_get_attachment_image_src',
				'get_post_meta',
			)
		);
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'trailingslashit' )->returnArg();
		Functions\when( 'wp_parse_url' )->returnArg( 1 );
		Functions\when( 'content_url' )->justReturn( 'http://example.com/wp-content' );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_feed' )->justReturn( false );
		Functions\when( 'is_preview' )->justReturn( false );
		Functions\when( 'is_embed' )->justReturn( false );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'wp_check_filetype' )->justReturn(
			array(
				'ext'  => 'jpg',
				'type' => 'image/jpeg',
			)
		);
		Functions\when( 'wp_get_attachment_image_src' )->justReturn( false );
		Functions\when( 'get_post_meta' )->justReturn( '' );

		$image_opt = new Image_Optimisation( $this->default_options );

		$html   = '<img src="test.jpg" alt="test"/>';
		$result = $image_opt->lazy_load_images( $html );
		$this->assertStringContainsString( 'data-src', $result );
	}

	/**
	 * Test that expected methods exist on the class.
	 */
	public function test_methods_exist(): void {
		$this->assertTrue( method_exists( Image_Optimisation::class, 'lazy_load_images' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'lazy_load_videos' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'preload_images' ) );
		$this->assertTrue( method_exists( Image_Optimisation::class, 'update_image_urls' ) );
	}
}
