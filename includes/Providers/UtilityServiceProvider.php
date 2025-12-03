<?php
/**
 * Utility Service Provider
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
 * Utility Service Provider Class
 */
class UtilityServiceProvider extends ServiceProvider {


	/**
	 * Services provided by this provider.
	 *
	 * @var array
	 */
	protected array $provides = array(
		'PerformanceOptimisation\\Utils\\FileSystemUtil',
		'PerformanceOptimisation\\Utils\\CacheUtil',
		'PerformanceOptimisation\\Utils\\LoggingUtil',
		'PerformanceOptimisation\\Utils\\ImageUtil',
		'PerformanceOptimisation\\Utils\\ValidationUtil',
		'PerformanceOptimisation\\Utils\\PerformanceUtil',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register utility classes as singletons
		$container->singleton( 'PerformanceOptimisation\\Utils\\FileSystemUtil', 'PerformanceOptimisation\\Utils\\FileSystemUtil' );
		$container->singleton( 'PerformanceOptimisation\\Utils\\CacheUtil', 'PerformanceOptimisation\\Utils\\CacheUtil' );
		$container->singleton( 'PerformanceOptimisation\\Utils\\LoggingUtil', 'PerformanceOptimisation\\Utils\\LoggingUtil' );
		$container->singleton( 'PerformanceOptimisation\\Utils\\ImageUtil', 'PerformanceOptimisation\\Utils\\ImageUtil' );
		$container->singleton( 'PerformanceOptimisation\\Utils\\ValidationUtil', 'PerformanceOptimisation\\Utils\\ValidationUtil' );
		$container->singleton( 'PerformanceOptimisation\\Utils\\PerformanceUtil', 'PerformanceOptimisation\\Utils\\PerformanceUtil' );

		// Register convenient aliases
		$container->alias( 'filesystem', 'PerformanceOptimisation\\Utils\\FileSystemUtil' );
		$container->alias( 'cache', 'PerformanceOptimisation\\Utils\\CacheUtil' );
		$container->alias( 'logger', 'PerformanceOptimisation\\Utils\\LoggingUtil' );
		$container->alias( 'logging_service', 'PerformanceOptimisation\\Utils\\LoggingUtil' );
		$container->alias( 'image', 'PerformanceOptimisation\\Utils\\ImageUtil' );
		$container->alias( 'validator', 'PerformanceOptimisation\\Utils\\ValidationUtil' );
		$container->alias( 'performance', 'PerformanceOptimisation\\Utils\\PerformanceUtil' );

		// Tag all utilities
		foreach ( $this->provides as $service ) {
			$container->register( $service, $service, array( 'tags' => array( 'utility' ) ) );
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Initialize utilities that need setup
		$container->get( 'PerformanceOptimisation\\Utils\\LoggingUtil' );
		$container->get( 'PerformanceOptimisation\\Utils\\PerformanceUtil' );
	}
}
