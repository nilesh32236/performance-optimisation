<?php
/**
 * Base Exception
 *
 * @package PerformanceOptimisation\Exceptions
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Exceptions;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Class PerformanceOptimisationException
 *
 * @package PerformanceOptimisation\Exceptions
 */
class PerformanceOptimisationException extends \Exception
{

	/**
	 * Exception context.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	protected string $context = '';

	/**
	 * Set exception context.
	 *
	 * @since 2.0.0
	 *
	 * @param string $context Context name.
	 * @return void
	 */
	public function setContext(string $context): void
	{
		$this->context = $context;
	}

	/**
	 * Get exception details.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> Exception details.
	 */
	public function getDetails(): array
	{
		return array(
			'message' => $this->getMessage(),
			'code' => $this->getCode(),
			'file' => $this->getFile(),
			'line' => $this->getLine(),
			'context' => $this->context,
		);
	}
}
