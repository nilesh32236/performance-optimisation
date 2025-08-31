<?php
/**
 * Optimization Service Provider
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
 * Optimization Service Provider Class
 */
class OptimizationServiceProvider extends ServiceProvider {

	/**
	 * Services provided by this provider.
	 *
	 * @var array
	 */
	protected array $provides = array(
		'PerformanceOptimisation\\Services\\CacheService',
		'PerformanceOptimisation\\Services\\ImageService',
		'PerformanceOptimisation\\Optimizers\\ModernCssOptimizer',
		'PerformanceOptimisation\\Optimizers\\JsOptimizer',
		'PerformanceOptimisation\\Optimizers\\HtmlOptimizer',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register services as singletons
		$container->singleton( 'PerformanceOptimisation\\Services\\CacheService', 'PerformanceOptimisation\\Services\\CacheService' );
		
		// Register ImageService with factory to handle dependencies
		$container->singleton( 'PerformanceOptimisation\\Services\\ImageService', function( ServiceContainerInterface $c ) {
			$imageProcessor = new \PerformanceOptimisation\Optimizers\ModernImageProcessor( $c );
			$conversionQueue = new \PerformanceOptimisation\Utils\ConversionQueue();
			$settings = get_option( 'wppo_settings', array() );
			return new \PerformanceOptimisation\Services\ImageService( $imageProcessor, $conversionQueue, $settings );
		} );

		// Register optimizers as singletons
		$container->singleton( 'PerformanceOptimisation\\Optimizers\\ModernCssOptimizer', 'PerformanceOptimisation\\Optimizers\\ModernCssOptimizer' );
		$container->singleton( 'PerformanceOptimisation\\Optimizers\\JsOptimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer' );
		$container->singleton( 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' );

		// Register convenient aliases
		$container->alias( 'cache_service', 'PerformanceOptimisation\\Services\\CacheService' );
		$container->alias( 'image_service', 'PerformanceOptimisation\\Services\\ImageService' );
		$container->alias( 'css_optimizer', 'PerformanceOptimisation\\Optimizers\\ModernCssOptimizer' );
		$container->alias( 'js_optimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer' );
		$container->alias( 'html_optimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' );

		// Tag services and optimizers
		$container->register( 'PerformanceOptimisation\\Services\\CacheService', 'PerformanceOptimisation\\Services\\CacheService', array( 'tags' => array( 'service' ) ) );
		// ImageService is already registered with factory above

		$container->register( 'PerformanceOptimisation\\Optimizers\\ModernCssOptimizer', 'PerformanceOptimisation\\Optimizers\\ModernCssOptimizer', array( 'tags' => array( 'optimizer' ) ) );
		$container->register( 'PerformanceOptimisation\\Optimizers\\JsOptimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer', array( 'tags' => array( 'optimizer' ) ) );
		$container->register( 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer', array( 'tags' => array( 'optimizer' ) ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Services will be lazy-loaded when needed
		// No need to initialize them during boot to avoid dependency issues
	}
}