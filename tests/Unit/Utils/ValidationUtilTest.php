<?php
/**
 * ValidationUtil Unit Tests
 *
 * @package PerformanceOptimisation\Tests\Unit\Utils
 */

namespace PerformanceOptimisation\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Utils\ValidationUtil;

class ValidationUtilTest extends TestCase {

	public function test_sanitize_url(): void {
		$this->assertEquals('https://example.com', ValidationUtil::sanitizeUrl('https://example.com'));
		$this->assertEquals('', ValidationUtil::sanitizeUrl('javascript:alert(1)'));
		$this->assertEquals('', ValidationUtil::sanitizeUrl('invalid-url'));
	}

	public function test_sanitize_path(): void {
		$this->assertEquals('/safe/path', ValidationUtil::sanitizePath('/safe/path'));
		$this->assertEquals('/safe/path', ValidationUtil::sanitizePath('/../safe/path'));
		$this->assertEquals('', ValidationUtil::sanitizePath('../../../etc/passwd'));
	}

	public function test_sanitize_html(): void {
		$input = '<script>alert("xss")</script><p>Safe content</p>';
		$result = ValidationUtil::sanitizeHtml($input);
		$this->assertStringNotContainsString('<script>', $result);
		$this->assertStringContainsString('<p>Safe content</p>', $result);
	}

	public function test_sanitize_js(): void {
		$safe_js = 'console.log("hello");';
		$unsafe_js = 'eval("malicious code");';
		
		$this->assertEquals($safe_js, ValidationUtil::sanitizeJs($safe_js));
		$this->assertNotEquals($unsafe_js, ValidationUtil::sanitizeJs($unsafe_js));
	}

	public function test_validate_email(): void {
		$this->assertTrue(ValidationUtil::validateEmail('test@example.com'));
		$this->assertFalse(ValidationUtil::validateEmail('invalid-email'));
		$this->assertFalse(ValidationUtil::validateEmail(''));
	}

	public function test_validate_numeric(): void {
		$this->assertTrue(ValidationUtil::validateNumeric('123'));
		$this->assertTrue(ValidationUtil::validateNumeric('123.45'));
		$this->assertFalse(ValidationUtil::validateNumeric('abc'));
		$this->assertFalse(ValidationUtil::validateNumeric(''));
	}

	public function test_validate_range(): void {
		$this->assertTrue(ValidationUtil::validateRange(50, 0, 100));
		$this->assertFalse(ValidationUtil::validateRange(150, 0, 100));
		$this->assertFalse(ValidationUtil::validateRange(-10, 0, 100));
	}

	public function test_validate_file_type(): void {
		$allowed = ['jpg', 'png', 'gif'];
		$this->assertTrue(ValidationUtil::validateFileType('image.jpg', $allowed));
		$this->assertFalse(ValidationUtil::validateFileType('script.php', $allowed));
	}

	public function test_validate_array_structure(): void {
		$schema = [
			'name' => 'string',
			'age' => 'integer',
			'active' => 'boolean'
		];
		
		$valid_data = ['name' => 'John', 'age' => 30, 'active' => true];
		$invalid_data = ['name' => 123, 'age' => 'thirty', 'active' => 'yes'];
		
		$this->assertTrue(ValidationUtil::validateArrayStructure($valid_data, $schema));
		$this->assertFalse(ValidationUtil::validateArrayStructure($invalid_data, $schema));
	}
}
