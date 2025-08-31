<?php
/**
 * File System Exception Class
 *
 * Custom exception for file system operations in the Performance Optimisation plugin.
 * Provides context-aware error handling for file system failures.
 *
 * @package PerformanceOptimisation\Exceptions
 * @since   2.0.0
 */

namespace PerformanceOptimisation\Exceptions;

/**
 * FileSystemException Class
 *
 * Exception thrown when file system operations fail.
 * Extends the base PerformanceOptimisationException with file system specific context.
 *
 * @since 2.0.0
 */
class FileSystemException extends PerformanceOptimisationException {

	/**
	 * File path related to the exception.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private string $filePath;

	/**
	 * Operation that failed.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	private string $operation;

	/**
	 * Constructor.
	 *
	 * @since 2.0.0
	 *
	 * @param string          $message   Exception message.
	 * @param int             $code      Exception code.
	 * @param \Throwable|null $previous  Previous exception.
	 * @param string          $filePath  File path related to the exception.
	 * @param string          $operation Operation that failed.
	 */
	public function __construct(
		string $message = '',
		int $code = 0,
		?\Throwable $previous = null,
		string $filePath = '',
		string $operation = ''
	) {
		parent::__construct( $message, $code, $previous );

		$this->filePath  = $filePath;
		$this->operation = $operation;
		$this->setContext( 'filesystem' );
	}

	/**
	 * Get the file path related to the exception.
	 *
	 * @since 2.0.0
	 *
	 * @return string File path.
	 */
	public function getFilePath(): string {
		return $this->filePath;
	}

	/**
	 * Set the file path related to the exception.
	 *
	 * @since 2.0.0
	 *
	 * @param string $filePath File path.
	 * @return void
	 */
	public function setFilePath( string $filePath ): void {
		$this->filePath = $filePath;
	}

	/**
	 * Get the operation that failed.
	 *
	 * @since 2.0.0
	 *
	 * @return string Operation name.
	 */
	public function getOperation(): string {
		return $this->operation;
	}

	/**
	 * Set the operation that failed.
	 *
	 * @since 2.0.0
	 *
	 * @param string $operation Operation name.
	 * @return void
	 */
	public function setOperation( string $operation ): void {
		$this->operation = $operation;
	}

	/**
	 * Get detailed exception information.
	 *
	 * @since 2.0.0
	 *
	 * @return array<string, mixed> Exception details.
	 */
	public function getDetails(): array {
		return array_merge(
			parent::getDetails(),
			array(
				'file_path' => $this->filePath,
				'operation' => $this->operation,
				'type'      => 'filesystem',
			)
		);
	}

	/**
	 * Convert exception to string with additional context.
	 *
	 * @since 2.0.0
	 *
	 * @return string String representation of the exception.
	 */
	public function __toString(): string {
		$string = parent::__toString();

		if ( ! empty( $this->filePath ) ) {
			$string .= "\nFile Path: " . $this->filePath;
		}

		if ( ! empty( $this->operation ) ) {
			$string .= "\nOperation: " . $this->operation;
		}

		return $string;
	}
}
