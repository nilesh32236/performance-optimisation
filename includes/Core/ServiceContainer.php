<?php
/**
 * Service Container
 *
 * PSR-11 compatible dependency injection container for managing services and their dependencies.
 *
 * @package PerformanceOptimisation\Core
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Container\ContainerExceptionInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Container Exception
 */
class ServiceContainerException extends \Exception implements ContainerExceptionInterface {}

/**
 * Service Not Found Exception
 */
class ServiceNotFoundException extends \Exception implements NotFoundExceptionInterface {}

/**
 * Service Container Class
 */
class ServiceContainer implements ServiceContainerInterface {

	/**
	 * Service definitions.
	 *
	 * @var array
	 */
	private array $services = array();

	/**
	 * Service instances (singletons).
	 *
	 * @var array
	 */
	private array $instances = array();

	/**
	 * Service factories.
	 *
	 * @var array
	 */
	private array $factories = array();

	/**
	 * Service aliases.
	 *
	 * @var array
	 */
	private array $aliases = array();

	/**
	 * Service tags.
	 *
	 * @var array
	 */
	private array $tags = array();

	/**
	 * Registered service providers.
	 *
	 * @var array
	 */
	private array $providers = array();

	/**
	 * Booted service providers.
	 *
	 * @var array
	 */
	private array $booted_providers = array();

	/**
	 * Container instance (singleton).
	 *
	 * @var ServiceContainer|null
	 */
	private static ?ServiceContainer $instance = null;

	/**
	 * Get container instance.
	 *
	 * @return ServiceContainer
	 */
	public static function getInstance(): ServiceContainer {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register a service.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $concrete Service definition (class name, callable, or instance).
	 * @param array  $options  Service options (singleton, tags, etc.).
	 * @return self
	 */
	public function register( string $id, $concrete, array $options = array() ): self {
		$defaults = array(
			'singleton' => true,
			'tags' => array(),
			'dependencies' => array(),
		);

		$options = array_merge( $defaults, $options );

		$this->services[ $id ] = array(
			'concrete' => $concrete,
			'options' => $options,
		);

		// Handle tags
		foreach ( $options['tags'] as $tag ) {
			if ( ! isset( $this->tags[ $tag ] ) ) {
				$this->tags[ $tag ] = array();
			}
			$this->tags[ $tag ][] = $id;
		}

		// LoggingUtil::debug( 'Service registered', array(
		// 	'id' => $id,
		// 	'concrete' => is_string( $concrete ) ? $concrete : gettype( $concrete ),
		// 	'options' => $options,
		// ) );

		return $this;
	}

	/**
	 * Register a factory service (always creates new instances).
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Factory callable.
	 * @return self
	 */
	public function factory( string $id, callable $factory ): self {
		$this->factories[ $id ] = $factory;

		// LoggingUtil::debug( 'Factory service registered', array( 'id' => $id ) );

		return $this;
	}

	/**
	 * Register a service alias.
	 *
	 * @param string $alias   Alias name.
	 * @param string $service Original service identifier.
	 * @return self
	 */
	public function alias( string $alias, string $service ): self {
		$this->aliases[ $alias ] = $service;

		// LoggingUtil::debug( 'Service alias registered', array(
		// 	'alias' => $alias,
		// 	'service' => $service,
		// ) );

		return $this;
	}

	/**
	 * Bind an interface to a concrete implementation.
	 *
	 * @param string $interface Interface name.
	 * @param string $concrete  Concrete class name.
	 * @param array  $options   Service options.
	 * @return self
	 */
	public function bind( string $interface, string $concrete, array $options = array() ): self {
		return $this->register( $interface, $concrete, $options );
	}

	/**
	 * Register a singleton service.
	 *
	 * @param string $id       Service identifier.
	 * @param mixed  $concrete Service definition.
	 * @return self
	 */
	public function singleton( string $id, $concrete ): self {
		return $this->register( $id, $concrete, array( 'singleton' => true ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get( $id ) {
		// Resolve alias
		$id = $this->resolveAlias( $id );

		// Check if it's a factory service
		if ( isset( $this->factories[ $id ] ) ) {
			return $this->createFromFactory( $id );
		}

		// Check if service is registered
		if ( ! $this->has( $id ) ) {
			throw new ServiceNotFoundException( "Service '{$id}' not found in container." );
		}

		$service = $this->services[ $id ];

		// Return existing singleton instance
		if ( $service['options']['singleton'] && isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Create new instance
		$instance = $this->createInstance( $id, $service );

		// Store singleton instance
		if ( $service['options']['singleton'] ) {
			$this->instances[ $id ] = $instance;
		}

		return $instance;
	}

	/**
	 * {@inheritdoc}
	 */
	public function has( $id ): bool {
		$id = $this->resolveAlias( $id );
		return isset( $this->services[ $id ] ) || isset( $this->factories[ $id ] );
	}

	/**
	 * Get services by tag.
	 *
	 * @param string $tag Tag name.
	 * @return array Array of service instances.
	 */
	public function getByTag( string $tag ): array {
		if ( ! isset( $this->tags[ $tag ] ) ) {
			return array();
		}

		$services = array();
		foreach ( $this->tags[ $tag ] as $service_id ) {
			$services[ $service_id ] = $this->get( $service_id );
		}

		return $services;
	}

	/**
	 * Call a method with dependency injection.
	 *
	 * @param callable $callable Callable to invoke.
	 * @param array    $parameters Additional parameters.
	 * @return mixed
	 */
	public function call( callable $callable, array $parameters = array() ) {
		if ( is_array( $callable ) && count( $callable ) === 2 ) {
			list( $class, $method ) = $callable;
			
			if ( is_string( $class ) ) {
				$class = $this->get( $class );
			}
			
			$reflection = new \ReflectionMethod( $class, $method );
		} elseif ( $callable instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $callable );
		} else {
			throw new ServiceContainerException( 'Invalid callable provided.' );
		}

		$dependencies = $this->resolveDependencies( $reflection->getParameters(), $parameters );

		return $reflection->invokeArgs( is_array( $callable ) ? $callable[0] : null, $dependencies );
	}

	/**
	 * Clear all services and instances.
	 *
	 * @return self
	 */
	public function clear(): self {
		$this->services = array();
		$this->instances = array();
		$this->factories = array();
		$this->aliases = array();
		$this->tags = array();
		$this->providers = array();
		$this->booted_providers = array();

		LoggingUtil::info( 'Service container cleared' );

		return $this;
	}

	/**
	 * Register a service provider.
	 *
	 * @param string|object $provider Service provider class name or instance.
	 * @return self
	 */
	public function registerProvider( $provider ): self {
		if ( is_string( $provider ) ) {
			$provider = new $provider();
		}

		if ( ! $provider instanceof \PerformanceOptimisation\Interfaces\ServiceProviderInterface ) {
			throw new ServiceContainerException( 'Provider must implement ServiceProviderInterface.' );
		}

		$provider_class = get_class( $provider );
		
		if ( ! isset( $this->providers[ $provider_class ] ) ) {
			$this->providers[ $provider_class ] = $provider;
			$provider->register( $this );

			// LoggingUtil::debug( 'Service provider registered', array( 'provider' => $provider_class ) );
		}

		return $this;
	}

	/**
	 * Boot all registered service providers.
	 *
	 * @return self
	 */
	public function bootProviders(): self {
		foreach ( $this->providers as $provider_class => $provider ) {
			if ( ! isset( $this->booted_providers[ $provider_class ] ) ) {
				$provider->boot( $this );
				$this->booted_providers[ $provider_class ] = true;

				// LoggingUtil::debug( 'Service provider booted', array( 'provider' => $provider_class ) );
			}
		}

		return $this;
	}

	/**
	 * Get container statistics.
	 *
	 * @return array
	 */
	public function getStats(): array {
		return array(
			'services_registered' => count( $this->services ),
			'instances_created' => count( $this->instances ),
			'factories_registered' => count( $this->factories ),
			'aliases_registered' => count( $this->aliases ),
			'tags_registered' => count( $this->tags ),
			'providers_registered' => count( $this->providers ),
			'providers_booted' => count( $this->booted_providers ),
		);
	}

	/**
	 * Resolve service alias.
	 *
	 * @param string $id Service identifier.
	 * @return string Resolved service identifier.
	 */
	private function resolveAlias( string $id ): string {
		return $this->aliases[ $id ] ?? $id;
	}

	/**
	 * Create instance from factory.
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 */
	private function createFromFactory( string $id ) {
		$factory = $this->factories[ $id ];

		try {
			return $factory( $this );
		} catch ( \Exception $e ) {
			throw new ServiceContainerException( "Failed to create service '{$id}' from factory: " . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Create service instance.
	 *
	 * @param string $id      Service identifier.
	 * @param array  $service Service definition.
	 * @return mixed
	 */
	private function createInstance( string $id, array $service ) {
		$concrete = $service['concrete'];

		try {
			// If concrete is already an instance, return it
			if ( is_object( $concrete ) ) {
				return $concrete;
			}

			// If concrete is a callable, call it
			if ( is_callable( $concrete ) ) {
				return $concrete( $this );
			}

			// If concrete is a class name, instantiate it
			if ( is_string( $concrete ) && class_exists( $concrete ) ) {
				return $this->instantiateClass( $concrete );
			}

			throw new ServiceContainerException( "Invalid service definition for '{$id}'." );

		} catch ( \Exception $e ) {
			throw new ServiceContainerException( "Failed to create service '{$id}': " . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Instantiate a class with dependency injection.
	 *
	 * @param string $class Class name.
	 * @return object
	 */
	private function instantiateClass( string $class ): object {
		$reflection = new \ReflectionClass( $class );

		if ( ! $reflection->isInstantiable() ) {
			throw new ServiceContainerException( "Class '{$class}' is not instantiable." );
		}

		$constructor = $reflection->getConstructor();

		if ( null === $constructor ) {
			return new $class();
		}

		$dependencies = $this->resolveDependencies( $constructor->getParameters() );

		return $reflection->newInstanceArgs( $dependencies );
	}

	/**
	 * Resolve method/constructor dependencies.
	 *
	 * @param array $parameters Reflection parameters.
	 * @param array $provided   Provided parameters.
	 * @return array
	 */
	private function resolveDependencies( array $parameters, array $provided = array() ): array {
		$dependencies = array();

		foreach ( $parameters as $parameter ) {
			$name = $parameter->getName();

			// Use provided parameter if available
			if ( array_key_exists( $name, $provided ) ) {
				$dependencies[] = $provided[ $name ];
				continue;
			}

			// Try to resolve by type hint
			$type = $parameter->getType();
			if ( $type && ! $type->isBuiltin() ) {
				$type_name = $type->getName();
				
				if ( $this->has( $type_name ) ) {
					$dependencies[] = $this->get( $type_name );
					continue;
				}
			}

			// Use default value if available
			if ( $parameter->isDefaultValueAvailable() ) {
				$dependencies[] = $parameter->getDefaultValue();
				continue;
			}

			// Check if parameter is nullable
			if ( $parameter->allowsNull() ) {
				$dependencies[] = null;
				continue;
			}

			throw new ServiceContainerException( "Cannot resolve parameter '{$name}' for dependency injection." );
		}

		return $dependencies;
	}

	/**
	 * Register core services using service registry.
	 *
	 * @return self
	 */
	public function registerCoreServices(): self {
		$registry = new ServiceRegistry( $this );
		
		$registry->registerAll()
				->registerInterfaceBindings()
				->registerFactories()
				->registerConditionalServices();

		// Validate services
		$validation_results = $registry->validateServices();
		
		if ( ! empty( $validation_results['invalid'] ) || ! empty( $validation_results['missing_dependencies'] ) ) {
			LoggingUtil::warning( 'Some services failed validation', $validation_results );
		}

		LoggingUtil::info( 'Core services registered in container', $this->getStats() );

		return $this;
	}

	/**
	 * Get service registry instance.
	 *
	 * @return ServiceRegistry
	 */
	public function getRegistry(): ServiceRegistry {
		return new ServiceRegistry( $this );
	}
}