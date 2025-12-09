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
		'PerformanceOptimisation\\Services\\ImagePreloader',
		'PerformanceOptimisation\\Services\\ImageLazyLoader',
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
	 *
	 * @param ServiceContainerInterface $container Container instance.
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register PageCacheService first (dependency for CacheService).
		$container->singleton(
			'PerformanceOptimisation\\Services\\PageCacheService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\PageCacheService(
					$c->get( 'settings_service' ),
					$c->get( 'logger' )
				);
			}
		);

		// Register CacheService with PageCacheService dependency.
		$container->singleton(
			'PerformanceOptimisation\\Services\\CacheService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\CacheService(
					$c->get( 'PerformanceOptimisation\\Services\\PageCacheService' )
				);
			}
		);

		// Register BrowserCacheService with dependencies.
		$container->singleton(
			'PerformanceOptimisation\\Services\\BrowserCacheService',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Services\BrowserCacheService(
					$c->get( 'settings_service' ),
					$c->get( 'logger' )
				);
			}
		);

		// Register ImagePreloader.
		$container->singleton(
			'PerformanceOptimisation\\Services\\ImagePreloader',
			function () {
				$settings = get_option( 'wppo_settings', array() );
				return new \PerformanceOptimisation\Services\ImagePreloader( $settings );
			}
		);

		// Register ImageLazyLoader.
		$container->singleton(
			'PerformanceOptimisation\\Services\\ImageLazyLoader',
			function () {
				return new \PerformanceOptimisation\Services\ImageLazyLoader();
			}
		);

		// Register ImageService with factory to handle dependencies.
		$container->singleton(
			'PerformanceOptimisation\\Services\\ImageService',
			function ( ServiceContainerInterface $c ) {
				$image_processor   = $c->get( 'PerformanceOptimisation\\Optimizers\\ImageProcessor' );
				$conversion_queue  = $c->get( 'PerformanceOptimisation\\Utils\\ConversionQueue' );
				$image_preloader   = $c->get( 'PerformanceOptimisation\\Services\\ImagePreloader' );
				$image_lazy_loader = $c->get( 'PerformanceOptimisation\\Services\\ImageLazyLoader' );
				$settings          = get_option( 'wppo_settings', array() );
				return new \PerformanceOptimisation\Services\ImageService(
					$image_processor,
					$conversion_queue,
					$image_preloader,
					$image_lazy_loader,
					$settings
				);
			}
		);

		// Register QueueProcessorService.
		$container->singleton(
			'PerformanceOptimisation\\Services\\QueueProcessorService',
			function ( ServiceContainerInterface $c ) {
				$conversion_queue = $c->get( 'PerformanceOptimisation\\Utils\\ConversionQueue' );
				$image_service    = $c->get( 'PerformanceOptimisation\\Services\\ImageService' );
				$processor        = new \PerformanceOptimisation\Services\QueueProcessorService(
					$conversion_queue,
					$image_service
				);
				$processor->init(); // Initialize cron hooks.
				return $processor;
			}
		);

		// Register ImageProcessor.
		$container->singleton(
			'PerformanceOptimisation\\Optimizers\\ImageProcessor',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Optimizers\ImageProcessor( $c );
			}
		);

		// Register LazyLoadService.
		$container->singleton(
			'PerformanceOptimisation\\Services\\LazyLoadService',
			function () {
				$settings = get_option( 'wppo_settings', array() );
				return new \PerformanceOptimisation\Services\LazyLoadService( $settings );
			}
		);

		// Register NextGenImageService.
		$container->singleton(
			'PerformanceOptimisation\\Services\\NextGenImageService',
			function ( ServiceContainerInterface $c ) {
				$settings         = get_option( 'wppo_settings', array() );
				$conversion_queue = $c->get( 'PerformanceOptimisation\\Utils\\ConversionQueue' );
				return new \PerformanceOptimisation\Services\NextGenImageService( $settings, $conversion_queue );
			}
		);

		// Register optimizers as singletons.
		$container->singleton(
			'PerformanceOptimisation\\Optimizers\\CssOptimizer',
			'PerformanceOptimisation\\Optimizers\\CssOptimizer'
		);
		$container->singleton(
			'PerformanceOptimisation\\Optimizers\\JsOptimizer',
			'PerformanceOptimisation\\Optimizers\\JsOptimizer'
		);
		$container->singleton(
			'PerformanceOptimisation\\Optimizers\\HtmlOptimizer',
			'PerformanceOptimisation\\Optimizers\\HtmlOptimizer'
		);

		// Register OptimizationService with factory.
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

		// Register AssetOptimizationService.
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

		// Register convenient aliases.
		$container->alias( 'cache_service', 'PerformanceOptimisation\\Services\\CacheService' );
		$container->alias( 'page_cache_service', 'PerformanceOptimisation\\Services\\PageCacheService' );
		$container->alias( 'browser_cache_service', 'PerformanceOptimisation\\Services\\BrowserCacheService' );
		$container->alias( 'image_service', 'PerformanceOptimisation\\Services\\ImageService' );
		$container->alias( 'image_preloader', 'PerformanceOptimisation\\Services\\ImagePreloader' );
		$container->alias( 'image_lazy_loader', 'PerformanceOptimisation\\Services\\ImageLazyLoader' );
		$container->alias( 'image_processor', 'PerformanceOptimisation\\Optimizers\\ImageProcessor' );
		$container->alias( 'lazy_load_service', 'PerformanceOptimisation\\Services\\LazyLoadService' );
		$container->alias( 'next_gen_image_service', 'PerformanceOptimisation\\Services\\NextGenImageService' );
		$container->alias( 'css_optimizer', 'PerformanceOptimisation\\Optimizers\\CssOptimizer' );
		$container->alias( 'js_optimizer', 'PerformanceOptimisation\\Optimizers\\JsOptimizer' );
		$container->alias( 'html_optimizer', 'PerformanceOptimisation\\Optimizers\\HtmlOptimizer' );
		$container->alias(
			'asset_optimization_service',
			'PerformanceOptimisation\\Services\\AssetOptimizationService'
		);

		// Tag services and optimizers.
		$container->register(
			'PerformanceOptimisation\\Services\\CacheService',
			'PerformanceOptimisation\\Services\\CacheService',
			array( 'tags' => array( 'service' ) )
		);
		// ImageService is already registered with factory above.

		$container->register(
			'PerformanceOptimisation\\Optimizers\\CssOptimizer',
			'PerformanceOptimisation\\Optimizers\\CssOptimizer',
			array( 'tags' => array( 'optimizer' ) )
		);
		$container->register(
			'PerformanceOptimisation\\Optimizers\\JsOptimizer',
			'PerformanceOptimisation\\Optimizers\\JsOptimizer',
			array( 'tags' => array( 'optimizer' ) )
		);
		$container->register(
			'PerformanceOptimisation\\Optimizers\\HtmlOptimizer',
			'PerformanceOptimisation\\Optimizers\\HtmlOptimizer',
			array( 'tags' => array( 'optimizer' ) )
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param ServiceContainerInterface $container Container instance.
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Initialize AssetOptimizationService to register hooks.
		if ($container->has('PerformanceOptimisation\\Services\\AssetOptimizationService')) {
			$container->get('PerformanceOptimisation\\Services\\AssetOptimizationService');
		}

		// Initialize PageCacheService to register hooks.
		if ($container->has('PerformanceOptimisation\\Services\\PageCacheService')) {
			$container->get('PerformanceOptimisation\\Services\\PageCacheService');
		}
	}
}
