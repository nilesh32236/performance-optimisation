<?php
/**
 * Container Unit Tests
 *
 * @package PerformanceOptimisation\Tests\Unit\Core\Container
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Tests\Unit\Core\Container;

use PerformanceOptimisation\Core\Container\Container;
use PerformanceOptimisation\Core\Container\ContainerException;
use PHPUnit\Framework\TestCase;

/**
 * Container Test Class
 *
 * @since 2.0.0
 */
class ContainerTest extends TestCase {

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	private Container $container;

	/**
	 * Set up test environment.
	 *
	 * @since 2.0.0
	 */
	protected function setUp(): void {
		$this->container = new Container();
	}

	/**
	 * Test basic binding and resolving.
	 *
	 * @since 2.0.0
	 */
	public function testBasicBindingAndResolving(): void {
		$this->container->bind( 'test', 'TestValue' );

		$this->assertTrue( $this->container->has( 'test' ) );
		$this->assertEquals( 'TestValue', $this->container->resolve( 'test' ) );
	}

	/**
	 * Test singleton binding.
	 *
	 * @since 2.0.0
	 */
	public function testSingletonBinding(): void {
		$this->container->singleton(
			'singleton_test',
			function () {
				return new \stdClass();
			}
		);

		$instance1 = $this->container->resolve( 'singleton_test' );
		$instance2 = $this->container->resolve( 'singleton_test' );

		$this->assertSame( $instance1, $instance2 );
	}

	/**
	 * Test closure binding.
	 *
	 * @since 2.0.0
	 */
	public function testClosureBinding(): void {
		$this->container->bind(
			'closure_test',
			function ( $container ) {
				return 'Closure Result';
			}
		);

		$result = $this->container->resolve( 'closure_test' );
		$this->assertEquals( 'Closure Result', $result );
	}

	/**
	 * Test service not found exception.
	 *
	 * @since 2.0.0
	 */
	public function testServiceNotFoundException(): void {
		$this->expectException( ContainerException::class );
		$this->container->resolve( 'non_existent_service' );
	}

	/**
	 * Test unbinding service.
	 *
	 * @since 2.0.0
	 */
	public function testUnbindService(): void {
		$this->container->bind( 'test_unbind', 'TestValue' );
		$this->assertTrue( $this->container->has( 'test_unbind' ) );

		$this->container->unbind( 'test_unbind' );
		$this->assertFalse( $this->container->has( 'test_unbind' ) );
	}

	/**
	 * Test getting all bindings.
	 *
	 * @since 2.0.0
	 */
	public function testGetBindings(): void {
		$this->container->bind( 'test1', 'value1' );
		$this->container->bind( 'test2', 'value2' );

		$bindings = $this->container->getBindings();

		$this->assertArrayHasKey( 'test1', $bindings );
		$this->assertArrayHasKey( 'test2', $bindings );
		$this->assertEquals( 'value1', $bindings['test1']['concrete'] );
		$this->assertEquals( 'value2', $bindings['test2']['concrete'] );
	}
}
