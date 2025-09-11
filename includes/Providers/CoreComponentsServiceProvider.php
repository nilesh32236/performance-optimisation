<?php
/**
 * Core Components Service Provider
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
 * Core Components Service Provider Class
 */
class CoreComponentsServiceProvider extends ServiceProvider {

	/**
	 * Services provided by this provider.
	 *
	 * @var array
	 */
	protected array $provides = array(
		'PerformanceOptimisation\\Core\\Bootstrap\\Plugin',
		'PerformanceOptimisation\\Core\\Config\\ConfigManager',
		'PerformanceOptimisation\\Core\\API\\RestController',
		'PerformanceOptimisation\\Core\\API\\ApiRouter',
		'PerformanceOptimisation\\Core\\Analytics\\MetricsCollector',
		'PerformanceOptimisation\\Core\\Analytics\\PerformanceAnalyzer',
		'PerformanceOptimisation\\Core\\Cache\\CacheManager',
		'PerformanceOptimisation\\Core\\Security\\SecurityManager',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register Bootstrap components
		$container->singleton( 'PerformanceOptimisation\\Core\\Bootstrap\\Plugin', function( ServiceContainerInterface $c ) {
			// Plugin is a singleton, get existing instance
			return \PerformanceOptimisation\Core\Bootstrap\Plugin::getInstance();
		} );

		// Register Configuration components
		$container->singleton( 'PerformanceOptimisation\\Core\\Config\\ConfigManager', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\Config\ConfigManager();
		} );

		// Register API components
		$container->singleton( 'PerformanceOptimisation\\Core\\API\\RestController', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\API\RestController( $c );
		} );

		$container->singleton( 'PerformanceOptimisation\\Core\\API\\ApiRouter', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\API\ApiRouter( $c );
		} );

		// Register Analytics components
		$container->singleton( 'PerformanceOptimisation\\Core\\Analytics\\MetricsCollector', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\Analytics\MetricsCollector( $c );
		} );

		$container->singleton( 'PerformanceOptimisation\\Core\\Analytics\\PerformanceAnalyzer', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\Analytics\PerformanceAnalyzer( $c );
		} );

		// Register Cache components
		$container->singleton( 'PerformanceOptimisation\\Core\\Cache\\CacheManager', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\Cache\CacheManager( $c );
		} );

		// Register Security components
		$container->singleton( 'PerformanceOptimisation\\Core\\Security\\SecurityManager', function( ServiceContainerInterface $c ) {
			return new \PerformanceOptimisation\Core\Security\SecurityManager( $c );
		} );

		// Register convenient aliases
		$container->alias( 'plugin', 'PerformanceOptimisation\\Core\\Bootstrap\\Plugin' );
		$container->alias( 'config_manager', 'PerformanceOptimisation\\Core\\Config\\ConfigManager' );
		$container->alias( 'rest_controller', 'PerformanceOptimisation\\Core\\API\\RestController' );
		$container->alias( 'api_router', 'PerformanceOptimisation\\Core\\API\\ApiRouter' );
		$container->alias( 'metrics_collector', 'PerformanceOptimisation\\Core\\Analytics\\MetricsCollector' );
		$container->alias( 'performance_analyzer', 'PerformanceOptimisation\\Core\\Analytics\\PerformanceAnalyzer' );
		$container->alias( 'cache_manager', 'PerformanceOptimisation\\Core\\Cache\\CacheManager' );
		$container->alias( 'security_manager', 'PerformanceOptimisation\\Core\\Security\\SecurityManager' );

		// Tag all core components
		foreach ( $this->provides as $service ) {
			$container->register( $service, $service, array( 'tags' => array( 'core_component' ) ) );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Initialize core components that need setup
		if ( ! is_admin() ) {
			// Initialize performance analyzer for frontend
			$container->get( 'PerformanceOptimisation\\Core\\Analytics\\PerformanceAnalyzer' );
		}

		// Initialize security manager
		$container->get( 'PerformanceOptimisation\\Core\\Security\\SecurityManager' );
	}
}