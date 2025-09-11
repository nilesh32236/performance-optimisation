<?php
/**
 * Service Registry
 *
 * Central registry for managing all service providers and their registration.
 *
 * @package PerformanceOptimisation\Core
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Registry Class
 */
class ServiceRegistry {

	/**
	 * Default service providers.
	 *
	 * @var array
	 */
	private array $default_providers = array(
		'PerformanceOptimisation\\Providers\\UtilityServiceProvider',
		'PerformanceOptimisation\\Providers\\CoreServiceProvider',
		'PerformanceOptimisation\\Providers\\OptimizationServiceProvider',
		'PerformanceOptimisation\\Providers\\AdminServiceProvider',
		'PerformanceOptimisation\\Providers\\CoreComponentsServiceProvider',
	);

	/**
	 * Additional service providers.
	 *
	 * @var array
	 */
	private array $additional_providers = array();

	/**
	 * Service container.
	 *
	 * @var ServiceContainerInterface
	 */
	private ServiceContainerInterface $container;

	/**
	 * Constructor.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 */
	public function __construct( ServiceContainerInterface $container ) {
		$this->container = $container;
	}

	/**
	 * Register all service providers.
	 *
	 * @return self
	 */
	public function registerAll(): self {
		$all_providers = array_merge( $this->default_providers, $this->additional_providers );

		foreach ( $all_providers as $provider ) {
			try {
				$this->container->registerProvider( $provider );
			} catch ( \Exception $e ) {
				LoggingUtil::error( 'Failed to register service provider: ' . $e->getMessage(), array(
					'provider' => $provider,
				) );
			}
		}

		// Boot all providers
		$this->container->bootProviders();

		LoggingUtil::info( 'All service providers registered and booted', array(
			'total_providers' => count( $all_providers ),
			'container_stats' => $this->container->getStats(),
		) );

		return $this;
	}

	/**
	 * Add additional service provider.
	 *
	 * @param string $provider Service provider class name.
	 * @return self
	 */
	public function addProvider( string $provider ): self {
		if ( ! in_array( $provider, $this->additional_providers, true ) ) {
			$this->additional_providers[] = $provider;
		}

		return $this;
	}

	/**
	 * Remove service provider.
	 *
	 * @param string $provider Service provider class name.
	 * @return self
	 */
	public function removeProvider( string $provider ): self {
		$this->additional_providers = array_filter(
			$this->additional_providers,
			function( $p ) use ( $provider ) {
				return $p !== $provider;
			}
		);

		return $this;
	}

	/**
	 * Get all registered providers.
	 *
	 * @return array
	 */
	public function getProviders(): array {
		return array_merge( $this->default_providers, $this->additional_providers );
	}

	/**
	 * Register interface bindings.
	 *
	 * @return self
	 */
	public function registerInterfaceBindings(): self {
		// Bind interfaces to concrete implementations
		$bindings = array(
			'PerformanceOptimisation\\Interfaces\\OptimizerInterface' => array(
				'PerformanceOptimisation\\Optimizers\\CssOptimizer',
				'PerformanceOptimisation\\Optimizers\\JsOptimizer',
				'PerformanceOptimisation\\Optimizers\\HtmlOptimizer',
			),
			'PerformanceOptimisation\\Interfaces\\ServiceContainerInterface' => 'PerformanceOptimisation\\Core\\ServiceContainer',
		);

		foreach ( $bindings as $interface => $implementations ) {
			if ( is_array( $implementations ) ) {
				// Multiple implementations - register each with tags
				foreach ( $implementations as $implementation ) {
					$this->container->bind( $interface, $implementation, array(
						'tags' => array( 'interface_implementation' ),
					) );
				}
			} else {
				// Single implementation
				$this->container->bind( $interface, $implementations );
			}
		}

		LoggingUtil::debug( 'Interface bindings registered', array( 'bindings' => count( $bindings ) ) );

		return $this;
	}

	/**
	 * Register factory services.
	 *
	 * @return self
	 */
	public function registerFactories(): self {
		// Register factory services that should create new instances each time
		$factories = array(
			'temp_file' => function( ServiceContainerInterface $container ) {
				return $container->get( 'filesystem' )->createTempFile();
			},
			'performance_timer' => function( ServiceContainerInterface $container ) {
				return $container->get( 'performance' )->createTimer();
			},
		);

		foreach ( $factories as $id => $factory ) {
			$this->container->factory( $id, $factory );
		}

		LoggingUtil::debug( 'Factory services registered', array( 'factories' => count( $factories ) ) );

		return $this;
	}

	/**
	 * Register conditional services based on environment.
	 *
	 * @return self
	 */
	public function registerConditionalServices(): self {
		// Register services based on WordPress environment
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			$this->container->singleton( 'debug_service', function( ServiceContainerInterface $container ) {
				return new class() {
					public function log( string $message ): void {
						error_log( '[WPPO Debug] ' . $message );
					}
				};
			} );
		}

		// Register services based on capabilities
		if ( function_exists( 'imagecreatefromwebp' ) ) {
			$this->container->register( 'webp_support', function() {
				return true;
			}, array( 'tags' => array( 'feature_support' ) ) );
		}

		if ( function_exists( 'imagecreatefromavif' ) ) {
			$this->container->register( 'avif_support', function() {
				return true;
			}, array( 'tags' => array( 'feature_support' ) ) );
		}

		LoggingUtil::debug( 'Conditional services registered' );

		return $this;
	}

	/**
	 * Validate all registered services.
	 *
	 * @return array Validation results.
	 */
	public function validateServices(): array {
		$results = array(
			'valid' => array(),
			'invalid' => array(),
			'missing_dependencies' => array(),
		);

		$stats = $this->container->getStats();
		
		// Check if core services are registered
		$core_services = array(
			'filesystem',
			'cache',
			'logger',
			'performance',
			'cache_service',
			'image_service',
		);

		foreach ( $core_services as $service ) {
			if ( $this->container->has( $service ) ) {
				try {
					$instance = $this->container->get( $service );
					$results['valid'][] = $service;
				} catch ( \Exception $e ) {
					$results['invalid'][] = array(
						'service' => $service,
						'error' => $e->getMessage(),
					);
				}
			} else {
				$results['missing_dependencies'][] = $service;
			}
		}

		LoggingUtil::info( 'Service validation completed', array(
			'valid_services' => count( $results['valid'] ),
			'invalid_services' => count( $results['invalid'] ),
			'missing_services' => count( $results['missing_dependencies'] ),
			'container_stats' => $stats,
		) );

		return $results;
	}

	/**
	 * Get service dependency graph.
	 *
	 * @return array
	 */
	public function getDependencyGraph(): array {
		$graph = array();
		$services = $this->container->getByTag( 'utility' ) + 
					$this->container->getByTag( 'service' ) + 
					$this->container->getByTag( 'optimizer' );

		foreach ( $services as $service_id => $service ) {
			$reflection = new \ReflectionClass( $service );
			$constructor = $reflection->getConstructor();
			
			$dependencies = array();
			if ( $constructor ) {
				foreach ( $constructor->getParameters() as $parameter ) {
					$type = $parameter->getType();
					if ( $type && ! $type->isBuiltin() ) {
						$dependencies[] = $type->getName();
					}
				}
			}
			
			$graph[ $service_id ] = $dependencies;
		}

		return $graph;
	}
}