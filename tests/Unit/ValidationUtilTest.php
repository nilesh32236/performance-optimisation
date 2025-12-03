<?php
namespace PerformanceOptimisation\Tests\Unit;

use PerformanceOptimisation\Utils\ValidationUtil;
use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;

class ValidationUtilTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sanitize_file_path() {
		Functions\when('wp_normalize_path')->returnArg();

		$this->assertEquals(
			'/var/www/html/file.txt',
			ValidationUtil::sanitizeFilePath('/var/www/html/file.txt')
		);
	}

	public function test_sanitize_file_path_traversal() {
		Functions\when('wp_normalize_path')->returnArg();
			
		// The regex in sanitizeFilePath is '/\.\.+/' -> '.'
		// So '../file.txt' becomes './file.txt'
		
		$this->assertEquals(
			'./file.txt',
			ValidationUtil::sanitizeFilePath('../file.txt')
		);
	}

	public function test_is_valid_url() {
		$this->assertTrue(ValidationUtil::isValidUrl('https://example.com'));
		$this->assertFalse(ValidationUtil::isValidUrl('invalid-url'));
		$this->assertFalse(ValidationUtil::isValidUrl('ftp://example.com')); // Only http/https allowed
	}
}
