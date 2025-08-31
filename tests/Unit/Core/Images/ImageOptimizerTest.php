<?php
/**
 * ImageOptimizer Unit Tests
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\Images;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Core\Images\ImageOptimizer;
use PerformanceOptimisation\Core\Config\ConfigManager;
use PerformanceOptimisation\Interfaces\ImageProcessorInterface;
use PerformanceOptimisation\Exceptions\ImageProcessingException;

/**
 * Test cases for the ImageOptimizer class
 *
 * @since 1.1.0
 */
class ImageOptimizerTest extends TestCase {

	/**
	 * ImageOptimizer instance
	 *
	 * @var ImageOptimizer
	 */
	private ImageOptimizer $image_optimizer;

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

		$this->config          = new ConfigManager();
		$this->image_optimizer = new ImageOptimizer( $this->config );
	}

	/**
	 * Test registering an image processor
	 *
	 * @since 1.1.0
	 */
	public function test_register_processor(): void {
		$mock_processor = $this->createMock( ImageProcessorInterface::class );
		$mock_processor->method( 'get_name' )->willReturn( 'test' );

		$this->image_optimizer->register_processor( $mock_processor );

		$processor = $this->image_optimizer->get_processor( 'test' );
		$this->assertSame( $mock_processor, $processor );
	}

	/**
	 * Test getting non-existent processor throws exception
	 *
	 * @since 1.1.0
	 */
	public function test_get_non_existent_processor_throws_exception(): void {
		$this->expectException( ImageProcessingException::class );
		$this->image_optimizer->get_processor( 'non_existent' );
	}

	/**
	 * Test adding image to optimization queue
	 *
	 * @since 1.1.0
	 */
	public function test_add_to_queue(): void {
		$this->image_optimizer->add_to_queue( '/path/to/image.jpg' );

		$stats = $this->image_optimizer->get_stats();
		$this->assertEquals( 1, $stats['global']['queue_size'] );
	}

	/**
	 * Test processing optimization queue
	 *
	 * @since 1.1.0
	 */
	public function test_process_queue(): void {
		// Add some items to queue
		$this->image_optimizer->add_to_queue( '/path/to/image1.jpg' );
		$this->image_optimizer->add_to_queue( '/path/to/image2.jpg' );

		// Process queue (will fail because files don't exist, but tests the mechanism)
		$results = $this->image_optimizer->process_queue( 1 );

		$this->assertIsArray( $results );
		$this->assertCount( 1, $results );

		// Queue size should be reduced
		$stats = $this->image_optimizer->get_stats();
		$this->assertEquals( 1, $stats['global']['queue_size'] );
	}

	/**
	 * Test optimization statistics
	 *
	 * @since 1.1.0
	 */
	public function test_get_stats(): void {
		$stats = $this->image_optimizer->get_stats();

		$this->assertIsArray( $stats );
		$this->assertArrayHasKey( 'global', $stats );
		$this->assertArrayHasKey( 'processors', $stats );
	}

	/**
	 * Test reset statistics
	 *
	 * @since 1.1.0
	 */
	public function test_reset_stats(): void {
		// Add item to queue to generate stats
		$this->image_optimizer->add_to_queue( '/path/to/image.jpg' );

		// Reset stats
		$this->image_optimizer->reset_stats();

		$stats = $this->image_optimizer->get_stats();
		$this->assertEquals( 0, $stats['global']['images_optimized'] );
		$this->assertEquals( 1, $stats['global']['queue_size'] ); // Queue items remain
	}

	/**
	 * Test getting available processors
	 *
	 * @since 1.1.0
	 */
	public function test_get_available_processors(): void {
		$processors = $this->image_optimizer->get_available_processors();

		$this->assertIsArray( $processors );
		// Should contain 'gd' if GD extension is available
	}

	/**
	 * Test is optimization enabled
	 *
	 * @since 1.1.0
	 */
	public function test_is_optimization_enabled(): void {
		$this->assertIsBool( $this->image_optimizer->is_optimization_enabled() );
	}

	/**
	 * Test WebP conversion configuration check
	 *
	 * @since 1.1.0
	 */
	public function test_webp_conversion_config_check(): void {
		// Disable WebP conversion
		$this->config->set( 'images.convert_to_webp', false );

		$result = $this->image_optimizer->convert_to_webp( '/path/to/image.jpg' );
		$this->assertFalse( $result );
	}

	/**
	 * Test AVIF conversion configuration check
	 *
	 * @since 1.1.0
	 */
	public function test_avif_conversion_config_check(): void {
		// AVIF is disabled by default
		$result = $this->image_optimizer->convert_to_avif( '/path/to/image.jpg' );
		$this->assertFalse( $result );
	}

	/**
	 * Test large image resize configuration check
	 *
	 * @since 1.1.0
	 */
	public function test_large_image_resize_config_check(): void {
		// Disable large image resizing
		$this->config->set( 'images.resize_large_images', false );

		$result = $this->image_optimizer->resize_large_image( '/path/to/image.jpg' );
		$this->assertFalse( $result );
	}
}
