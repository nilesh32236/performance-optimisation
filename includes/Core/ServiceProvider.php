<?php
/**
 * Base Service Provider
 *
 * @package PerformanceOptimisation\Core
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Core;

use PerformanceOptimisation\Interfaces\ServiceContainerInterface;
use PerformanceOptimisation\Interfaces\ServiceProviderInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract Service Provider Class
 */
abstract class ServiceProvider implements ServiceProviderInterface {

	/**
	 * Services provided by this provider.
	 *
	 * @var array
	 */
	protected array $provides = array();

	/**
	 * Whether the provider has been booted.
	 *
	 * @var bool
	 */
	protected bool $booted = false;

	/**
	 * {@inheritdoc}
	 */
	public function boot( ServiceContainerInterface $container ): void {
		$this->booted = true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function provides(): array {
		return $this->provides;
	}

	/**
	 * Check if the provider has been booted.
	 *
	 * @return bool
	 */
	public function isBooted(): bool {
		return $this->booted;
	}
}
