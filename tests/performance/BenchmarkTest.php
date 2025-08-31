<?php
/**
 * Performance Benchmark Tests
 *
 * @package PerformanceOptimisation\Tests\Performance
 */

namespace PerformanceOptimisation\Tests\Performance;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Optimizers\ModernCssOptimizer;
use PerformanceOptimisation\Optimizers\JsOptimizer;
use PerformanceOptimisation\Optimizers\ModernImageProcessor;

class BenchmarkTest extends TestCase {

	public function test_css_optimization_performance(): void {
		$css_content = $this->generateLargeCssContent();
		$optimizer = $this->createMock(ModernCssOptimizer::class);
		
		$start_time = microtime(true);
		$start_memory = memory_get_usage();
		
		// Simulate CSS optimization
		$result = $this->simulateCssOptimization($css_content);
		
		$end_time = microtime(true);
		$end_memory = memory_get_usage();
		
		$execution_time = $end_time - $start_time;
		$memory_used = $end_memory - $start_memory;
		
		// Assert performance benchmarks
		$this->assertLessThan(2.0, $execution_time, 'CSS optimization should complete within 2 seconds');
		$this->assertLessThan(50 * 1024 * 1024, $memory_used, 'CSS optimization should use less than 50MB memory');
		
		echo "\nCSS Optimization Performance:\n";
		echo "Execution time: " . round($execution_time, 3) . "s\n";
		echo "Memory used: " . round($memory_used / 1024 / 1024, 2) . "MB\n";
	}

	public function test_image_processing_performance(): void {
		$test_image = $this->createTestImage();
		
		$start_time = microtime(true);
		$start_memory = memory_get_usage();
		
		// Simulate image processing
		$result = $this->simulateImageProcessing($test_image);
		
		$end_time = microtime(true);
		$end_memory = memory_get_usage();
		
		$execution_time = $end_time - $start_time;
		$memory_used = $end_memory - $start_memory;
		
		// Assert performance benchmarks
		$this->assertLessThan(5.0, $execution_time, 'Image processing should complete within 5 seconds');
		$this->assertLessThan(100 * 1024 * 1024, $memory_used, 'Image processing should use less than 100MB memory');
		
		echo "\nImage Processing Performance:\n";
		echo "Execution time: " . round($execution_time, 3) . "s\n";
		echo "Memory used: " . round($memory_used / 1024 / 1024, 2) . "MB\n";
		
		unlink($test_image);
	}

	public function test_cache_operations_performance(): void {
		$cache_data = $this->generateLargeCacheData();
		
		$start_time = microtime(true);
		
		// Simulate cache operations
		for ($i = 0; $i < 1000; $i++) {
			wp_cache_set("test_key_$i", $cache_data, 'wppo_benchmark');
			wp_cache_get("test_key_$i", 'wppo_benchmark');
		}
		
		$end_time = microtime(true);
		$execution_time = $end_time - $start_time;
		
		// Assert performance benchmarks
		$this->assertLessThan(1.0, $execution_time, '1000 cache operations should complete within 1 second');
		
		echo "\nCache Operations Performance:\n";
		echo "1000 operations in: " . round($execution_time, 3) . "s\n";
		echo "Operations per second: " . round(1000 / $execution_time) . "\n";
	}

	private function generateLargeCssContent(): string {
		$css = '';
		for ($i = 0; $i < 1000; $i++) {
			$css .= ".class-$i { color: #000; background: #fff; margin: 10px; padding: 5px; }\n";
			$css .= ".class-$i:hover { color: #333; }\n";
			$css .= "@media (max-width: 768px) { .class-$i { display: block; } }\n";
		}
		return $css;
	}

	private function createTestImage(): string {
		$image_path = sys_get_temp_dir() . '/benchmark_test.jpg';
		$image = imagecreate(800, 600);
		$white = imagecolorallocate($image, 255, 255, 255);
		$black = imagecolorallocate($image, 0, 0, 0);
		
		// Add some complexity to the image
		for ($i = 0; $i < 100; $i++) {
			imageline($image, rand(0, 800), rand(0, 600), rand(0, 800), rand(0, 600), $black);
		}
		
		imagejpeg($image, $image_path, 90);
		imagedestroy($image);
		
		return $image_path;
	}

	private function generateLargeCacheData(): array {
		return array_fill(0, 100, [
			'id' => uniqid(),
			'data' => str_repeat('test data ', 100),
			'timestamp' => time(),
			'metadata' => ['key1' => 'value1', 'key2' => 'value2']
		]);
	}

	private function simulateCssOptimization(string $css): string {
		// Simulate CSS optimization operations
		$css = preg_replace('/\s+/', ' ', $css); // Remove extra whitespace
		$css = preg_replace('/;\s*}/', '}', $css); // Remove trailing semicolons
		$css = str_replace([' {', '{ ', ' }', '} '], ['{', '{', '}', '}'], $css); // Remove spaces around braces
		return trim($css);
	}

	private function simulateImageProcessing(string $image_path): bool {
		// Simulate image processing operations
		$image = imagecreatefromjpeg($image_path);
		if (!$image) return false;
		
		// Simulate resizing
		$width = imagesx($image);
		$height = imagesy($image);
		$new_width = $width / 2;
		$new_height = $height / 2;
		
		$resized = imagecreatetruecolor($new_width, $new_height);
		imagecopyresampled($resized, $image, 0, 0, 0, 0, $new_width, $new_height, $width, $height);
		
		imagedestroy($image);
		imagedestroy($resized);
		
		return true;
	}
}
