<?php
/**
 * Service Provider Interface
 *
 * @package PerformanceOptimisation\Interfaces
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Interfaces;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Provider Interface
 */
interface ServiceProviderInterface {

	/**
	 * Register services in the container.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 * @return void
	 */
	public function register( ServiceContainerInterface $container ): void;

	/**
	 * Boot services after all providers have been registered.
	 *
	 * @param ServiceContainerInterface $container Service container.
	 * @return void
	 */
	public function boot( ServiceContainerInterface $container ): void;

	/**
	 * Get the services provided by this provider.
	 *
	 * @return array
	 */
	public function provides(): array;
}
