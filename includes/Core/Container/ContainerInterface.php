<?php
/**
 * Container Interface for Dependency Injection
 *
 * @package PerformanceOptimisation\Core\Container
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Container;

/**
 * Container Interface
 *
 * Defines the contract for dependency injection container implementations.
 *
 * @since 2.0.0
 */
interface ContainerInterface {

	/**
	 * Bind a service to the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @param mixed  $concrete The service implementation.
	 * @return void
	 */
	public function bind( string $abstract, $concrete ): void;

	/**
	 * Bind a singleton service to the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @param mixed  $concrete The service implementation.
	 * @return void
	 */
	public function singleton( string $abstract, $concrete ): void;

	/**
	 * Resolve a service from the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return mixed The resolved service instance.
	 * @throws ContainerException If the service cannot be resolved.
	 */
	public function resolve( string $abstract );

	/**
	 * Check if a service is bound to the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return bool True if the service is bound, false otherwise.
	 */
	public function has( string $abstract ): bool;

	/**
	 * Remove a service binding from the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return void
	 */
	public function unbind( string $abstract ): void;

	/**
	 * Get all bound services.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> Array of bound services.
	 */
	public function getBindings(): array;
}
