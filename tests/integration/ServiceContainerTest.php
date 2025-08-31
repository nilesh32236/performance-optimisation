<?php
/**
 * Service Container Integration Tests
 *
 * @package PerformanceOptimisation\Tests\Integration
 */

namespace PerformanceOptimisation\Tests\Integration;

use WP_UnitTestCase;
use PerformanceOptimisation\Core\ServiceContainer;

class ServiceContainerTest extends WP_UnitTestCase {

	private ServiceContainer $container;

	protected function setUp(): void {
		parent::setUp();
		$this->container = ServiceContainer::getInstance();
	}

	public function test_container_singleton(): void {
		$container1 = ServiceContainer::getInstance();
		$container2 = ServiceContainer::getInstance();
		$this->assertSame($container1, $container2);
	}

	public function test_service_registration(): void {
		// Test that core services are registered
		$this->assertTrue($this->container->has('logger'));
		$this->assertTrue($this->container->has('filesystem'));
		$this->assertTrue($this->container->has('cache_service'));
	}

	public function test_service_resolution(): void {
		// Test that services can be resolved
		$logger = $this->container->get('logger');
		$this->assertInstanceOf('PerformanceOptimisation\Utils\LoggingUtil', $logger);

		$filesystem = $this->container->get('filesystem');
		$this->assertInstanceOf('PerformanceOptimisation\Utils\FileSystemUtil', $filesystem);
	}

	public function test_service_dependencies(): void {
		// Test that services with dependencies are properly injected
		$cache_service = $this->container->get('cache_service');
		$this->assertInstanceOf('PerformanceOptimisation\Services\CacheService', $cache_service);
	}

	public function test_circular_dependency_detection(): void {
		// Test that circular dependencies are detected
		$this->expectException(\Exception::class);
		
		// This would create a circular dependency
		$this->container->bind('service_a', function($container) {
			return new class($container->get('service_b')) {};
		});
		
		$this->container->bind('service_b', function($container) {
			return new class($container->get('service_a')) {};
		});
		
		$this->container->get('service_a');
	}

	public function test_service_caching(): void {
		// Test that services are cached (singleton behavior)
		$service1 = $this->container->get('logger');
		$service2 = $this->container->get('logger');
		$this->assertSame($service1, $service2);
	}
}
