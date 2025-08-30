<?php
/**
 * JS Optimizer
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Interfaces\OptimizerInterface;
use PerformanceOptimisation\Utils\LoggingUtil;
use MatthiasMullie\Minify\JS as MatthiasJsMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class JsOptimizer implements OptimizerInterface {

	private array $stats = [
		'total_files'   => 0,
		'total_bytes'   => 0,
		'bytes_saved'   => 0,
		'total_time_ms' => 0,
	];

	public function optimize( string $content, array $options = array() ): string {
		$start_time = microtime( true );
		$original_size = strlen( $content );

		try {
			$minifier = new MatthiasJsMinifier( $content );
			$optimized_content = $minifier->minify();

			$end_time = microtime( true );
			$optimized_size = strlen( $optimized_content );

			// Update stats
			$this->stats['total_files']++;
			$this->stats['total_bytes'] += $original_size;
			$this->stats['bytes_saved'] += ( $original_size - $optimized_size );
			$this->stats['total_time_ms'] += ( $end_time - $start_time ) * 1000;

			return $optimized_content;
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'JS Minification Error: ' . $e->getMessage() );
			return $content; // Return original content on failure
		}
	}

	public function can_optimize( string $content_type ): bool {
		return in_array( $content_type, $this->get_supported_types(), true );
	}

	public function get_name(): string {
		return 'JS Optimizer';
	}

	public function get_supported_types(): array {
		return array( 'js' );
	}

	public function get_stats(): array {
		return $this->stats;
	}

	public function reset_stats(): void {
		$this->stats = [
			'total_files'   => 0,
			'total_bytes'   => 0,
			'bytes_saved'   => 0,
			'total_time_ms' => 0,
		];
	}
}
