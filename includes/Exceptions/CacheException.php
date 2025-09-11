<?php
/**
 * Cache Exception
 *
 * @package PerformanceOptimisation
 * @since 1.1.0
 */

namespace PerformanceOptimisation\Exceptions;

/**
 * Exception thrown when cache operations fail
 *
 * @since 1.1.0
 */
class CacheException extends PluginException {

	/**
	 * Constructor
	 *
	 * @since 1.1.0
	 * @param string     $message  Exception message
	 * @param int        $code     Exception code
	 * @param \Throwable $previous Previous exception
	 */
	public function __construct( string $message = '', int $code = 0, \Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}
}
