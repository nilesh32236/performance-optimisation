<?php
/**
 * Service Container Interface
 *
 * @package PerformanceOptimisation\Interfaces
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Interfaces;

use Psr\Container\ContainerInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extended Service Container Interface
 */
interface ServiceContainerInterface extends ContainerInterface {

	/**
	 * Register a service.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $concrete Service definition.
	 * @param array  $options  Service options.
	 * @return self
	 */
	public function register( string $id, $concrete, array $options = array() ): self;

	/**
	 * Register a factory service.
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory callable.
	 * @return self
	 */
	public function factory( string $id, callable $factory ): self;

	/**
	 * Register a service alias.
	 *
	 * @param string $alias   Alias name.
	 * @param string $service Original service identifier.
	 * @return self
	 */
	public function alias( string $alias, string $service ): self;

	/**
	 * Bind an interface to a concrete implementation.
	 *
	 * @param string $interface Interface name.
	 * @param string $concrete  Concrete class name.
	 * @param array  $options   Service options.
	 * @return self
	 */
	public function bind( string $interface, string $concrete, array $options = array() ): self;

	/**
	 * Register a singleton service.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $concrete Service definition.
	 * @return self
	 */
	public function singleton( string $id, $concrete ): self;

	/**
	 * Get services by tag.
	 *
	 * @param string $tag Tag name.
	 * @return array
	 */
	public function getByTag( string $tag ): array;

	/**
	 * Call a method with dependency injection.
	 *
	 * @param callable $callable   Callable to invoke.
	 * @param array    $parameters Additional parameters.
	 * @return mixed
	 */
	public function call( callable $callable, array $parameters = array() );

	/**
	 * Clear all services and instances.
	 *
	 * @return self
	 */
	public function clear(): self;

	/**
	 * Get container statistics.
	 *
	 * @return array
	 */
	public function getStats(): array;
}
