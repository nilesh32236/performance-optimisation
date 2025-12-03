<?php
/**
 * Admin Service Provider
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
 * Admin Service Provider Class
 */
class AdminServiceProvider extends ServiceProvider {

	/**
	 * Services provided by this provider.
	 *
	 * @var array
	 */
	protected array $provides = array(
		'PerformanceOptimisation\\Admin\\Admin',
		'PerformanceOptimisation\\Admin\\Metabox',
		'PerformanceOptimisation\\Frontend\\Frontend',
	);

	/**
	 * {@inheritdoc}
	 */
	public function register( ServiceContainerInterface $container ): void {
		// Register admin and frontend classes as singletons with container injection
		$container->singleton(
			'PerformanceOptimisation\\Admin\\Admin',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Admin\Admin( $c );
			}
		);

		$container->singleton(
			'PerformanceOptimisation\\Admin\\Metabox',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Admin\Metabox( $c );
			}
		);

		$container->singleton(
			'PerformanceOptimisation\\Frontend\\Frontend',
			function ( ServiceContainerInterface $c ) {
				return new \PerformanceOptimisation\Frontend\Frontend( $c );
			}
		);

		// Register convenient aliases
		$container->alias( 'admin', 'PerformanceOptimisation\\Admin\\Admin' );
		$container->alias( 'metabox', 'PerformanceOptimisation\\Admin\\Metabox' );
		$container->alias( 'frontend', 'PerformanceOptimisation\\Frontend\\Frontend' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		parent::boot( $container );

		// Initialize admin components only in admin area
		if ( is_admin() ) {
			$container->get( 'PerformanceOptimisation\\Admin\\Admin' );
			$container->get( 'PerformanceOptimisation\\Admin\\Metabox' );
		}

		// Initialize frontend components only on frontend
		if ( ! is_admin() ) {
			// Instantiate PageCacheService to enable page caching
			try {
				$settings = $container->get( 'settings_service' );
				$logger = $container->get( 'logger' );
				new \PerformanceOptimisation\Services\PageCacheService( $settings, $logger );
			} catch ( \Exception $e ) {
				error_log( 'WPPO: Failed to instantiate PageCacheService: ' . $e->getMessage() );
			}
		}
	}
}
