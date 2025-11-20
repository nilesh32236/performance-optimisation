<?php
/**
 * Core Service Provider
 *
 * @package PerformanceOptimisation\Providers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Providers;

use PerformanceOptimisation\Core\ServiceProvider;
use PerformanceOptimisation\Interfaces\ServiceContainerInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core Service Provider Class
 */
class CoreServiceProvider extends ServiceProvider {

	/**
	 * Services provided by this provider.
	 *
	 * @var array
	 */
	protected array $provides = array(
		'PerformanceOptimisation\\Services\\OptimizationService',
		'PerformanceOptimisation\\Services\\SettingsService',
		'PerformanceOptimisation\\Services\\CronService',
		'PerformanceOptimisation\\Services\\ConfigurationService',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register core services as singletons
		$container->singleton( 'PerformanceOptimisation\\Services\\OptimizationService', 'PerformanceOptimisation\\Services\\OptimizationService' );

		// Register CronService with proper dependency injection
		$container->singleton(
			'PerformanceOptimisation\\Services\\CronService',
			function ( ServiceContainerInterface $c ) {
				$cache_service    = $c->has( 'cache_service' ) ? $c->get( 'cache_service' ) : null;
				$image_service    = $c->has( 'image_service' ) ? $c->get( 'image_service' ) : null;
				$settings_service = $c->has( 'settings_service' ) ? $c->get( 'settings_service' ) : null;

				return new \PerformanceOptimisation\Services\CronService(
					$cache_service,
					$image_service,
					$settings_service
				);
			}
		);

		// Register SettingsService with container injection
		$container->singleton(
			'PerformanceOptimisation\\Services\\SettingsService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\SettingsService( $c );
			}
		);

		// Register ConfigurationService with container injection
		$container->singleton(
			'PerformanceOptimisation\\Services\\ConfigurationService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\ConfigurationService( $c );
			}
		);

		// Register convenient aliases
		$container->alias( 'optimization_service', 'PerformanceOptimisation\\Services\\OptimizationService' );
		$container->alias( 'settings_service', 'PerformanceOptimisation\\Services\\SettingsService' );
		$container->alias( 'cron_service', 'PerformanceOptimisation\\Services\\CronService' );
		$container->alias( 'configuration_service', 'PerformanceOptimisation\\Services\\ConfigurationService' );
		$container->alias( 'config', 'PerformanceOptimisation\\Services\\ConfigurationService' );

		// Tag all core services
		foreach ( $this->provides as $service ) {
			if ( $service === 'PerformanceOptimisation\\Services\\ConfigurationService' ) {
				// ConfigurationService is already registered with custom factory
				continue;
			}
			$container->register( $service, $service, array( 'tags' => array( 'core_service' ) ) );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Services will be lazy-loaded when needed
		// No need to initialize them during boot to avoid circular dependencies
	}
}
