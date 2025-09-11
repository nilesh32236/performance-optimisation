<?php
/**
 * Container Exception
 *
 * @package PerformanceOptimisation\Core\Container
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Core\Container;

use Exception;

/**
 * Container Exception
 *
 * Exception thrown when container operations fail.
 *
 * @since 2.0.0
 */
class ContainerException extends Exception {

	/**
	 * Create exception for service not found.
	 *
	 * @since 2.0.0
	 *
	 * @param string $service The service identifier.
	 * @return ContainerException
	 */
	public static function serviceNotFound( string $service ): ContainerException {
		return new self( "Service '{$service}' not found in container." );
	}

	/**
	 * Create exception for circular dependency.
	 *
	 * @since 2.0.0
	 *
	 * @param string $service The service identifier.
	 * @return ContainerException
	 */
	public static function circularDependency( string $service ): ContainerException {
		return new self( "Circular dependency detected for service '{$service}'." );
	}

	/**
	 * Create exception for invalid service definition.
	 *
	 * @since 2.0.0
	 *
	 * @param string $service The service identifier.
	 * @param string $reason  The reason for invalidity.
	 * @return ContainerException
	 */
	public static function invalidService( string $service, string $reason ): ContainerException {
		return new self( "Invalid service definition for '{$service}': {$reason}" );
	}
}
