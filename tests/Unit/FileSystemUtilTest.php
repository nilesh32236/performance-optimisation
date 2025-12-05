<?php
namespace PerformanceOptimisation\Tests\Unit;

use PerformanceOptimisation\Utils\FileSystemUtil;
use PHPUnit\Framework\TestCase;
use Brain\Monkey\Functions;
use Mockery;



class FileSystemUtilTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		\Brain\Monkey\setUp();
		
		// Reset static $filesystem property
		$ref = new \ReflectionClass(FileSystemUtil::class);
		$prop = $ref->getProperty('filesystem');
		$prop->setAccessible(true);
		$prop->setValue(null, null);
	}

	protected function tearDown(): void {
		\Brain\Monkey\tearDown();
		Mockery::close();
		parent::tearDown();
	}

	public function test_file_exists() {
		Functions\when('wp_normalize_path')->returnArg();
		
		// Mock WP_Filesystem global
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock('WP_Filesystem_Base');
		$wp_filesystem->shouldReceive('exists')
			->once()
			->with('/path/to/file.txt')
			->andReturn(true);

		$this->assertTrue(FileSystemUtil::fileExists('/path/to/file.txt'));
	}

	public function test_read_file_success() {
		Functions\when('wp_normalize_path')->returnArg();
		
		global $wp_filesystem;
		$wp_filesystem = Mockery::mock('WP_Filesystem_Base');
		
		// File exists check - called multiple times
		$wp_filesystem->shouldReceive('exists')
			->atLeast()->once()
			->with('/path/to/file.txt')
			->andReturn(true);
			
		// File size check
		$wp_filesystem->shouldReceive('size')
			->once()
			->with('/path/to/file.txt')
			->andReturn(1024);
			
		// Get contents
		$wp_filesystem->shouldReceive('get_contents')
			->once()
			->with('/path/to/file.txt')
			->andReturn('file content');

		$this->assertEquals(
			'file content',
			FileSystemUtil::readFile('/path/to/file.txt')
		);
	}
}
