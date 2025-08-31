<?php
/**
 * CacheUtil Unit Tests
 *
 * @package PerformanceOptimisation\Tests\Unit\Utils
 */

namespace PerformanceOptimisation\Tests\Unit\Utils;

use PHPUnit\Framework\TestCase;
use PerformanceOptimisation\Utils\CacheUtil;

class CacheUtilTest extends TestCase {

	protected function setUp(): void {
		// Clear any existing cache
		wp_cache_flush();
	}

	public function test_generate_cache_key(): void {
		$key1 = CacheUtil::generateCacheKey('test_data', 'prefix');
		$key2 = CacheUtil::generateCacheKey('test_data', 'prefix');
		$key3 = CacheUtil::generateCacheKey('different_data', 'prefix');

		$this->assertEquals($key1, $key2);
		$this->assertNotEquals($key1, $key3);
		$this->assertStringStartsWith('prefix_', $key1);
	}

	public function test_set_get_cache(): void {
		$key = 'test_key';
		$value = ['test' => 'data'];
		$group = 'test_group';

		$this->assertTrue(CacheUtil::set($key, $value, 3600, $group));
		$this->assertEquals($value, CacheUtil::get($key, $group));
	}

	public function test_cache_expiry(): void {
		$this->assertEquals(3600, CacheUtil::getCacheExpiry('default'));
		$this->assertEquals(1800, CacheUtil::getCacheExpiry('minified'));
		$this->assertEquals(7200, CacheUtil::getCacheExpiry('page'));
	}

	public function test_delete_cache(): void {
		$key = 'test_delete';
		$value = 'test_value';
		$group = 'test_group';

		CacheUtil::set($key, $value, 3600, $group);
		$this->assertEquals($value, CacheUtil::get($key, $group));

		$this->assertTrue(CacheUtil::delete($key, $group));
		$this->assertNull(CacheUtil::get($key, $group));
	}

	public function test_flush_group(): void {
		$group = 'test_flush_group';
		CacheUtil::set('key1', 'value1', 3600, $group);
		CacheUtil::set('key2', 'value2', 3600, $group);

		$this->assertEquals('value1', CacheUtil::get('key1', $group));
		$this->assertEquals('value2', CacheUtil::get('key2', $group));

		CacheUtil::flushGroup($group);

		$this->assertNull(CacheUtil::get('key1', $group));
		$this->assertNull(CacheUtil::get('key2', $group));
	}

	public function test_cache_stats(): void {
		$stats = CacheUtil::getStats();
		$this->assertIsArray($stats);
		$this->assertArrayHasKey('hits', $stats);
		$this->assertArrayHasKey('misses', $stats);
	}
}
