<?php
/**
 * ModernImageProcessor Unit Tests
 *
 * @package PerformanceOptimisation\Tests\Unit\Optimizers
 */

namespace PerformanceOptimisation\Tests\Unit\Optimizers;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PerformanceOptimisation\Optimizers\ModernImageProcessor;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;

class ModernImageProcessorTest extends TestCase {

	private ModernImageProcessor $processor;
	private MockObject $containerMock;
	private string $test_image;

	protected function setUp(): void {
		$this->containerMock = $this->createMock(ServiceContainerInterface::class);
		
		// Mock all required services
		$this->containerMock->method('get')->willReturn($this->createMock(\stdClass::class));
		
		$this->processor = new ModernImageProcessor($this->containerMock);
		
		// Create a test image
		$this->test_image = sys_get_temp_dir() . '/test_image.jpg';
		$this->createTestImage();
	}

	protected function tearDown(): void {
		if (file_exists($this->test_image)) {
			unlink($this->test_image);
		}
	}

	public function test_can_optimize(): void {
		$this->assertTrue($this->processor->can_optimize('jpg'));
		$this->assertTrue($this->processor->can_optimize('png'));
		$this->assertFalse($this->processor->can_optimize('txt'));
	}

	public function test_get_name(): void {
		$this->assertEquals('Modern Image Processor', $this->processor->get_name());
	}

	public function test_get_supported_types(): void {
		$types = $this->processor->get_supported_types();
		$this->assertContains('jpg', $types);
		$this->assertContains('png', $types);
		$this->assertContains('webp', $types);
	}

	public function test_get_optimal_format(): void {
		// Test Chrome user agent (should support AVIF)
		$chrome_ua = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36';
		$format = $this->processor->getOptimalFormat($chrome_ua);
		$this->assertContains($format, ['avif', 'webp', 'jpeg']);

		// Test old browser (should fallback to JPEG)
		$old_ua = 'Mozilla/4.0 (compatible; MSIE 8.0; Windows NT 6.1)';
		$format = $this->processor->getOptimalFormat($old_ua);
		$this->assertEquals('jpeg', $format);
	}

	public function test_generate_srcset(): void {
		$responsive_images = [
			320 => ['path' => '/path/to/image-320w.jpg'],
			640 => ['path' => '/path/to/image-640w.jpg'],
			1024 => ['path' => '/path/to/image-1024w.jpg'],
		];

		$srcset = $this->processor->generateSrcset($responsive_images);
		$this->assertStringContainsString('320w', $srcset);
		$this->assertStringContainsString('640w', $srcset);
		$this->assertStringContainsString('1024w', $srcset);
	}

	public function test_generate_sizes(): void {
		$sizes = $this->processor->generateSizes();
		$this->assertStringContainsString('100vw', $sizes);
		$this->assertStringContainsString('50vw', $sizes);
	}

	private function createTestImage(): void {
		// Create a simple 1x1 pixel JPEG image
		$image = imagecreate(1, 1);
		imagecolorallocate($image, 255, 255, 255);
		imagejpeg($image, $this->test_image);
		imagedestroy($image);
	}
}
