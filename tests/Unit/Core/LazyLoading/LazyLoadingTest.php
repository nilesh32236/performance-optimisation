<?php
/**
 * LazyLoading Unit Tests
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\LazyLoading;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\LazyLoading\LazyLoading;
use PerformanceOptimisation\Core\Config\ConfigManager;

/**
 * Test cases for the LazyLoading class
 *
 * @since 1.1.0
 */
class LazyLoadingTest extends TestCase {

	/**
	 * LazyLoading instance
	 *
	 * @var LazyLoading
	 */
	private LazyLoading $lazy_loading;

	/**
	 * Mock config manager
	 *
	 * @var ConfigManager
	 */
	private ConfigManager $config;

	/**
	 * Set up test environment
	 *
	 * @since 1.1.0
	 */
	protected function setUp(): void {
		parent::setUp();

		// Mock WordPress functions
		if ( ! function_exists( 'is_admin' ) ) {
			function is_admin() {
				return false;
			}
		}

		if ( ! function_exists( 'is_feed' ) ) {
			function is_feed() {
				return false;
			}
		}

		if ( ! function_exists( 'plugin_dir_url' ) ) {
			function plugin_dir_url( $file ) {
				return 'http://example.com/wp-content/plugins/performance-optimisation/';
			}
		}

		$this->config       = new ConfigManager();
		$this->lazy_loading = new LazyLoading( $this->config );
	}

	/**
	 * Test image processing
	 *
	 * @since 1.1.0
	 */
	public function test_process_images(): void {
		$html      = '<img src="image.jpg" alt="Test Image">';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertStringContains( 'data-src="image.jpg"', $processed );
		$this->assertStringContains( 'class="wppo-lazy"', $processed );
		$this->assertStringContains( 'loading="lazy"', $processed );
	}

	/**
	 * Test iframe processing
	 *
	 * @since 1.1.0
	 */
	public function test_process_iframes(): void {
		$html      = '<iframe src="https://www.youtube.com/embed/video" width="560" height="315"></iframe>';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertStringContains( 'data-src="https://www.youtube.com/embed/video"', $processed );
		$this->assertStringContains( 'class="wppo-lazy"', $processed );
		$this->assertStringContains( 'loading="lazy"', $processed );
	}

	/**
	 * Test video processing
	 *
	 * @since 1.1.0
	 */
	public function test_process_videos(): void {
		$html      = '<video src="video.mp4" controls autoplay></video>';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertStringContains( 'class="wppo-lazy"', $processed );
		$this->assertStringContains( 'preload="none"', $processed );
		$this->assertStringNotContains( 'autoplay', $processed );
	}

	/**
	 * Test skipping elements with skip classes
	 *
	 * @since 1.1.0
	 */
	public function test_skip_elements_with_skip_classes(): void {
		$html      = '<img src="image.jpg" class="no-lazy" alt="Test Image">';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertEquals( $html, $processed );
		$this->assertStringNotContains( 'data-src', $processed );
	}

	/**
	 * Test skipping elements with data-no-lazy attribute
	 *
	 * @since 1.1.0
	 */
	public function test_skip_elements_with_no_lazy_attribute(): void {
		$html      = '<img src="image.jpg" data-no-lazy alt="Test Image">';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertEquals( $html, $processed );
		$this->assertStringNotContains( 'data-src', $processed );
	}

	/**
	 * Test skipping elements that already have lazy loading
	 *
	 * @since 1.1.0
	 */
	public function test_skip_elements_already_lazy(): void {
		$html      = '<img src="placeholder.jpg" data-src="image.jpg" alt="Test Image">';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertEquals( $html, $processed );
	}

	/**
	 * Test configuration management
	 *
	 * @since 1.1.0
	 */
	public function test_configuration_management(): void {
		$config = $this->lazy_loading->get_config();
		$this->assertIsArray( $config );
		$this->assertArrayHasKey( 'images', $config );

		$new_config = array( 'images' => false );
		$this->lazy_loading->set_config( $new_config );

		$this->assertFalse( $this->lazy_loading->is_enabled_for_type( 'images' ) );
	}

	/**
	 * Test statistics tracking
	 *
	 * @since 1.1.0
	 */
	public function test_statistics_tracking(): void {
		$html = '<img src="image.jpg" alt="Test Image">';
		$this->lazy_loading->process_content( $html );

		$stats = $this->lazy_loading->get_stats();
		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'images_processed', $stats );
		$this->assertEquals( 1, $stats['images_processed'] );

		$this->lazy_loading->reset_stats();
		$stats = $this->lazy_loading->get_stats();
		$this->assertEquals( 0, $stats['images_processed'] );
	}

	/**
	 * Test adding lazy attributes to specific element types
	 *
	 * @since 1.1.0
	 */
	public function test_add_lazy_attributes(): void {
		$img_html  = '<img src="image.jpg" alt="Test">';
		$processed = $this->lazy_loading->add_lazy_attributes( $img_html, 'img' );

		$this->assertStringContains( 'data-src="image.jpg"', $processed );
		$this->assertStringContains( 'wppo-lazy', $processed );

		$iframe_html = '<iframe src="video.html"></iframe>';
		$processed   = $this->lazy_loading->add_lazy_attributes( $iframe_html, 'iframe' );

		$this->assertStringContains( 'data-src="video.html"', $processed );
		$this->assertStringContains( 'wppo-lazy', $processed );
	}

	/**
	 * Test empty content handling
	 *
	 * @since 1.1.0
	 */
	public function test_empty_content_handling(): void {
		$empty_content = '';
		$processed     = $this->lazy_loading->process_content( $empty_content );

		$this->assertEquals( $empty_content, $processed );
	}

	/**
	 * Test content without lazy-loadable elements
	 *
	 * @since 1.1.0
	 */
	public function test_content_without_lazy_elements(): void {
		$html      = '<p>This is just text content with no images or iframes.</p>';
		$processed = $this->lazy_loading->process_content( $html );

		$this->assertEquals( $html, $processed );
	}
}
