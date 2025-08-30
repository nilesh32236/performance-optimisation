<?php
/**
 * Dependency Injection Container
 *
 * @package PerformanceOptimisation\Core\Container
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Container;

use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

/**
 * Container Class
 *
 * Simple dependency injection container implementation.
 *
 * @since 2.0.0
 */
class Container implements ContainerInterface {

	/**
	 * Service bindings.
	 *
	 * @since 2.0.0
	 * @var array<string, mixed>
	 */
	private array $bindings = array();

	/**
	 * Singleton instances.
	 *
	 * @since 2.0.0
	 * @var array<string, mixed>
	 */
	private array $instances = array();

	/**
	 * Services currently being resolved (for circular dependency detection).
	 *
	 * @since 2.0.0
	 * @var array<string, bool>
	 */
	private array $resolving = array();

	/**
	 * Bind a service to the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @param mixed  $concrete The service implementation.
	 * @return void
	 */
	public function bind( string $abstract, $concrete ): void {
		$this->bindings[ $abstract ] = array(
			'concrete'  => $concrete,
			'singleton' => false,
		);

		// Remove existing instance if rebinding
		unset( $this->instances[ $abstract ] );
	}

	/**
	 * Bind a singleton service to the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @param mixed  $concrete The service implementation.
	 * @return void
	 */
	public function singleton( string $abstract, $concrete ): void {
		$this->bindings[ $abstract ] = array(
			'concrete'  => $concrete,
			'singleton' => true,
		);
	}

	/**
	 * Resolve a service from the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return mixed The resolved service instance.
	 * @throws ContainerException If the service cannot be resolved.
	 */
	public function resolve( string $abstract ) {
		// Check for circular dependency
		if ( isset( $this->resolving[ $abstract ] ) ) {
			throw ContainerException::circularDependency( $abstract );
		}

		// Return existing singleton instance
		if ( isset( $this->instances[ $abstract ] ) ) {
			return $this->instances[ $abstract ];
		}

		$this->resolving[ $abstract ] = true;

		try {
			$instance = $this->build( $abstract );

			// Store singleton instance
			if ( isset( $this->bindings[ $abstract ]['singleton'] ) && $this->bindings[ $abstract ]['singleton'] ) {
				$this->instances[ $abstract ] = $instance;
			}

			unset( $this->resolving[ $abstract ] );

			return $instance;
		} catch ( Exception $e ) {
			unset( $this->resolving[ $abstract ] );
			throw $e;
		}
	}

	/**
	 * Check if a service is bound to the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return bool True if the service is bound, false otherwise.
	 */
	public function has( string $abstract ): bool {
		return isset( $this->bindings[ $abstract ] ) || class_exists( $abstract );
	}

	/**
	 * Remove a service binding from the container.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return void
	 */
	public function unbind( string $abstract ): void {
		unset( $this->bindings[ $abstract ], $this->instances[ $abstract ] );
	}

	/**
	 * Get all bound services.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> Array of bound services.
	 */
	public function getBindings(): array {
		return $this->bindings;
	}

	/**
	 * Build a service instance.
	 *
	 * @since 2.0.0
	 *
	 * @param string $abstract The service identifier.
	 * @return mixed The built service instance.
	 * @throws ContainerException If the service cannot be built.
	 */
	private function build( string $abstract ) {
		// Check if service is bound
		if ( isset( $this->bindings[ $abstract ] ) ) {
			$concrete = $this->bindings[ $abstract ]['concrete'];

			if ( $concrete instanceof Closure ) {
				return $concrete( $this );
			}

			if ( is_string( $concrete ) ) {
				// Prevent infinite recursion when concrete is same as abstract
				if ( $concrete === $abstract ) {
					return $this->buildClass( $concrete );
				}
				return $this->build( $concrete );
			}

			return $concrete;
		}

		// Try to auto-resolve class
		if ( class_exists( $abstract ) ) {
			return $this->buildClass( $abstract );
		}

		throw ContainerException::serviceNotFound( $abstract );
	}

	/**
	 * Build a class instance with dependency injection.
	 *
	 * @since 2.0.0
	 *
	 * @param string $class The class name.
	 * @return mixed The built class instance.
	 * @throws ContainerException If the class cannot be built.
	 */
	private function buildClass( string $class ) {
		try {
			$reflection = new ReflectionClass( $class );

			if ( ! $reflection->isInstantiable() ) {
				throw ContainerException::invalidService( $class, 'Class is not instantiable' );
			}

			$constructor = $reflection->getConstructor();

			if ( null === $constructor ) {
				return new $class();
			}

			$dependencies = $this->resolveDependencies( $constructor->getParameters() );

			return $reflection->newInstanceArgs( $dependencies );
		} catch ( ReflectionException $e ) {
			throw ContainerException::invalidService( $class, $e->getMessage() );
		}
	}

	/**
	 * Resolve constructor dependencies.
	 *
	 * @since 2.0.0
	 *
	 * @param ReflectionParameter[] $parameters Constructor parameters.
	 * @return array<mixed> Resolved dependencies.
	 * @throws ContainerException If dependencies cannot be resolved.
	 */
	private function resolveDependencies( array $parameters ): array {
		$dependencies = array();

		foreach ( $parameters as $parameter ) {
			$type = $parameter->getType();

			if ( null === $type || $type->isBuiltin() ) {
				if ( $parameter->isDefaultValueAvailable() ) {
					$dependencies[] = $parameter->getDefaultValue();
				} else {
					throw ContainerException::invalidService(
						$parameter->getDeclaringClass()->getName(),
						"Cannot resolve parameter '{$parameter->getName()}'"
					);
				}
			} else {
				$dependencies[] = $this->resolve( $type->getName() );
			}
		}

		return $dependencies;
	}
}
