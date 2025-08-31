<?php
/**
 * CacheService Unit Tests
 *
 * @package PerformanceOptimisation\Tests\Unit\Services
 */

namespace PerformanceOptimisation\Tests\Unit\Services;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use PerformanceOptimisation\Services\CacheService;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use PerformanceOptimisation\Utils\FileSystemUtil;

class CacheServiceTest extends TestCase {

	private CacheService $cacheService;
	private MockObject $containerMock;
	private MockObject $loggerMock;
	private MockObject $filesystemMock;

	protected function setUp(): void {
		$this->containerMock = $this->createMock(ServiceContainerInterface::class);
		$this->loggerMock = $this->createMock(LoggingUtil::class);
		$this->filesystemMock = $this->createMock(FileSystemUtil::class);

		$this->containerMock->method('get')
			->willReturnMap([
				['logger', $this->loggerMock],
				['filesystem', $this->filesystemMock],
			]);

		$this->cacheService = new CacheService($this->containerMock);
	}

	public function test_clear_cache_all(): void {
		$this->filesystemMock->expects($this->atLeastOnce())
			->method('directoryExists')
			->willReturn(true);

		$this->filesystemMock->expects($this->atLeastOnce())
			->method('deleteDirectory')
			->willReturn(true);

		$result = $this->cacheService->clearCache('all');
		$this->assertTrue($result);
	}

	public function test_clear_cache_page(): void {
		$this->filesystemMock->expects($this->once())
			->method('directoryExists')
			->willReturn(true);

		$this->filesystemMock->expects($this->once())
			->method('deleteDirectory')
			->willReturn(true);

		$result = $this->cacheService->clearCache('page');
		$this->assertTrue($result);
	}

	public function test_invalidate_cache(): void {
		$url = 'https://example.com/test-page/';
		
		$this->filesystemMock->expects($this->once())
			->method('fileExists')
			->willReturn(true);

		$this->filesystemMock->expects($this->once())
			->method('deleteFile')
			->willReturn(true);

		$result = $this->cacheService->invalidateCache($url);
		$this->assertTrue($result);
	}

	public function test_get_cache_size(): void {
		$this->filesystemMock->expects($this->once())
			->method('getDirectorySize')
			->willReturn(1024000); // 1MB

		$size = $this->cacheService->getCacheSize();
		$this->assertEquals('1.00 MB', $size);
	}

	public function test_preload_cache(): void {
		$urls = ['https://example.com/', 'https://example.com/about/'];
		
		$result = $this->cacheService->preloadCache($urls);
		$this->assertIsArray($result);
		$this->assertArrayHasKey('processed', $result);
		$this->assertArrayHasKey('successful', $result);
	}

	public function test_is_cache_enabled(): void {
		// Mock WordPress functions
		if (!function_exists('get_option')) {
			function get_option($option, $default = false) {
				return $option === 'wppo_settings' ? ['cache' => ['enabled' => true]] : $default;
			}
		}

		$this->assertTrue($this->cacheService->isCacheEnabled());
	}
}
