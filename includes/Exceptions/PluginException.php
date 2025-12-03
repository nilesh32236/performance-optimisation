<?php
/**
 * Plugin Exception
 *
 * @package PerformanceOptimisation
 * @since 1.0.0
 */

namespace PerformanceOptimisation\Exceptions;

/**
 * Base exception for the plugin
 *
 * @since 1.0.0
 */
class PluginException extends \Exception
{

    /**
     * Constructor
     *
     * @since 1.0.0
     * @param string     $message  Exception message.
     * @param int        $code     Exception code.
     * @param \Throwable $previous Previous exception.
     */
    public function __construct(string $message = '', int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
