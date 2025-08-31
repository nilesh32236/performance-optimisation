<?php
/**
 * FileSystemUtil Unit Tests
 *
 * @package PerformanceOptimisation\Tests\Unit\Utils
 */

namespace PerformanceOptimisation\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Utils\FileSystemUtil;

class FileSystemUtilTest extends TestCase {

	private string $test_dir;
	private string $test_file;

	protected function setUp(): void {
		$this->test_dir = sys_get_temp_dir() . '/wppo_test_' . uniqid();
		$this->test_file = $this->test_dir . '/test.txt';
		mkdir($this->test_dir, 0755, true);
	}

	protected function tearDown(): void {
		if (is_dir($this->test_dir)) {
			$this->removeDirectory($this->test_dir);
		}
	}

	public function test_file_exists(): void {
		file_put_contents($this->test_file, 'test content');
		$this->assertTrue(FileSystemUtil::fileExists($this->test_file));
		$this->assertFalse(FileSystemUtil::fileExists($this->test_file . '_nonexistent'));
	}

	public function test_directory_exists(): void {
		$this->assertTrue(FileSystemUtil::directoryExists($this->test_dir));
		$this->assertFalse(FileSystemUtil::directoryExists($this->test_dir . '_nonexistent'));
	}

	public function test_create_directory(): void {
		$new_dir = $this->test_dir . '/subdir';
		$this->assertTrue(FileSystemUtil::createDirectory($new_dir));
		$this->assertTrue(is_dir($new_dir));
	}

	public function test_read_write_file(): void {
		$content = 'Test file content';
		$this->assertTrue(FileSystemUtil::writeFile($this->test_file, $content));
		$this->assertEquals($content, FileSystemUtil::readFile($this->test_file));
	}

	public function test_get_file_size(): void {
		$content = 'Test content';
		file_put_contents($this->test_file, $content);
		$this->assertEquals(strlen($content), FileSystemUtil::getFileSize($this->test_file));
	}

	public function test_delete_file(): void {
		file_put_contents($this->test_file, 'test');
		$this->assertTrue(FileSystemUtil::deleteFile($this->test_file));
		$this->assertFalse(file_exists($this->test_file));
	}

	public function test_sanitize_path(): void {
		$this->assertEquals('/safe/path', FileSystemUtil::sanitizePath('/safe/path'));
		$this->assertEquals('/safe/path', FileSystemUtil::sanitizePath('/../safe/path'));
		$this->assertEquals('/safe/path', FileSystemUtil::sanitizePath('/safe/../path'));
	}

	public function test_get_file_extension(): void {
		$this->assertEquals('txt', FileSystemUtil::getFileExtension('/path/file.txt'));
		$this->assertEquals('php', FileSystemUtil::getFileExtension('test.php'));
		$this->assertEquals('', FileSystemUtil::getFileExtension('noextension'));
	}

	private function removeDirectory(string $dir): void {
		if (!is_dir($dir)) return;
		$files = array_diff(scandir($dir), ['.', '..']);
		foreach ($files as $file) {
			$path = $dir . '/' . $file;
			is_dir($path) ? $this->removeDirectory($path) : unlink($path);
		}
		rmdir($dir);
	}
}
