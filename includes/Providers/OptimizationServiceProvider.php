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
		'PerformanceOptimisation\\Services\\PageCacheService',
		'PerformanceOptimisation\\Services\\BrowserCacheService',
		'PerformanceOptimisation\\Services\\ImageService',
		'PerformanceOptimisation\\Services\\LazyLoadService',
		'PerformanceOptimisation\\Services\\NextGenImageService',
		'PerformanceOptimisation\\Services\\OptimizationService',
		'PerformanceOptimisation\\Services\\AssetOptimizationService',
		'PerformanceOptimisation\\Optimizers\\CssOptimizer',
		'PerformanceOptimisation\\Optimizers\\JsOptimizer',
		'PerformanceOptimisation\\Optimizers\\HtmlOptimizer',
		'PerformanceOptimisation\\Optimizers\\ImageProcessor',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register PageCacheService first (dependency for CacheService)
		$container->singleton(
			'PerformanceOptimisation\\Services\\PageCacheService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\PageCacheService(
					$c->get( 'settings_service' ),
					$c->get( 'logger' )
				);
			}
		);

		// Register CacheService with PageCacheService dependency
		$container->singleton(
			'PerformanceOptimisation\\Services\\CacheService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\CacheService(
					$c->get( 'PerformanceOptimisation\\Services\\PageCacheService' )
				);
			}
		);

		// Register BrowserCacheService with dependencies
		$container->singleton(
			'PerformanceOptimisation\\Services\\BrowserCacheService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\BrowserCacheService(
					$c->get( 'settings_service' ),
					$c->get( 'logger' )
				);
			}
		);

		// Register ImageService with factory to handle dependencies
		$container->singleton(
			'PerformanceOptimisation\\Services\\ImageService',
			function ( ServiceContainerInterface $c ) {
				$imageProcessor  = $c->get( 'PerformanceOptimisation\\Optimizers\\ImageProcessor' );
				$conversionQueue = new \PerformanceOptimisation\Utils\ConversionQueue();
				$settings        = get_option( 'wppo_settings', array() );
				return new \PerformanceOptimisation\Services\ImageService( $imageProcessor, $conversionQueue, $settings );
			}
		);

		// Register QueueProcessorService
		$container->singleton(
			'PerformanceOptimisation\\Services\\QueueProcessorService',
			function ( ServiceContainerInterface $c ) {
				$conversionQueue = new \PerformanceOptimisation\Utils\ConversionQueue();
				$imageService    = $c->get( 'PerformanceOptimisation\\Services\\ImageService' );
				$processor       = new \PerformanceOptimisation\Services\QueueProcessorService( $conversionQueue, $imageService );
				$processor->init(); // Initialize cron hooks
				return $processor;
			}
		);

		// Register ImageProcessor
		$container->singleton(
			'PerformanceOptimisation\\Optimizers\\ImageProcessor',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Optimizers\ImageProcessor( $c );
			}
		);

		// Register LazyLoadService
		$container->singleton(
			'PerformanceOptimisation\\Services\\LazyLoadService',
			function ( ServiceContainerInterface $c ) {
				$settings = get_option( 'wppo_settings', array() );
				return new \PerformanceOptimisation\Services\LazyLoadService( $settings );
			}
		);

		// Register NextGenImageService
		$container->singleton(
			'PerformanceOptimisation\\Services\\NextGenImageService',
			function ( ServiceContainerInterface $c ) {
				$settings        = get_option( 'wppo_settings', array() );
				$conversionQueue = new \PerformanceOptimisation\Utils\ConversionQueue();
				return new \PerformanceOptimisation\Services\NextGenImageService( $settings, $conversionQueue );
			}
		);

		// Register optimizers as singletons
		$container->singleton( 'PerformanceOptimisation\\Optimizers\\CssOptimizer', 'PerformanceOptimisation\\Optimizers\\CssOptimizer' );
		$container->singleton( 'PerformanceOptimisation\\Optimizers\\JsOptimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer' );
		$container->singleton( 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' );

		// Register OptimizationService with factory
		$container->singleton(
			'PerformanceOptimisation\\Services\\OptimizationService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\OptimizationService(
					$c->get( 'PerformanceOptimisation\\Optimizers\\CssOptimizer' ),
					$c->get( 'PerformanceOptimisation\\Optimizers\\JsOptimizer' ),
					$c->get( 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' )
				);
			}
		);

		// Register AssetOptimizationService
		$container->singleton(
			'PerformanceOptimisation\\Services\\AssetOptimizationService',
			function ( ServiceContainerInterface $c ) {
				$service = new \PerformanceOptimisation\Services\AssetOptimizationService(
					$c->get( 'settings_service' ),
					$c->get( 'PerformanceOptimisation\\Optimizers\\CssOptimizer' ),
					$c->get( 'PerformanceOptimisation\\Optimizers\\JsOptimizer' ),
					$c->get( 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' )
				);
				$service->init();
				return $service;
			}
		);

		// Register convenient aliases
		$container->alias( 'cache_service', 'PerformanceOptimisation\\Services\\CacheService' );
		$container->alias( 'page_cache_service', 'PerformanceOptimisation\\Services\\PageCacheService' );
		$container->alias( 'browser_cache_service', 'PerformanceOptimisation\\Services\\BrowserCacheService' );
		$container->alias( 'image_service', 'PerformanceOptimisation\\Services\\ImageService' );
		$container->alias( 'image_processor', 'PerformanceOptimisation\\Optimizers\\ImageProcessor' );
		$container->alias( 'lazy_load_service', 'PerformanceOptimisation\\Services\\LazyLoadService' );
		$container->alias( 'next_gen_image_service', 'PerformanceOptimisation\\Services\\NextGenImageService' );
		$container->alias( 'css_optimizer', 'PerformanceOptimisation\\Optimizers\\CssOptimizer' );
		$container->alias( 'js_optimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer' );
		$container->alias( 'html_optimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' );
		$container->alias( 'asset_optimization_service', 'PerformanceOptimisation\\Services\\AssetOptimizationService' );

		// Tag services and optimizers
		$container->register( 'PerformanceOptimisation\\Services\\CacheService', 'PerformanceOptimisation\\Services\\CacheService', array( 'tags' => array( 'service' ) ) );
		// ImageService is already registered with factory above

		$container->register( 'PerformanceOptimisation\\Optimizers\\CssOptimizer', 'PerformanceOptimisation\\Optimizers\\CssOptimizer', array( 'tags' => array( 'optimizer' ) ) );
		$container->register( 'PerformanceOptimisation\\Optimizers\\JsOptimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer', array( 'tags' => array( 'optimizer' ) ) );
		$container->register( 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer', array( 'tags' => array( 'optimizer' ) ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Initialize AssetOptimizationService to register hooks
		if ( $container->has( 'PerformanceOptimisation\\Services\\AssetOptimizationService' ) ) {
			$container->get( 'PerformanceOptimisation\\Services\\AssetOptimizationService' );
		}
	}
}
