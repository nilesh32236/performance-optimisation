<?php
/**
 * Conversion Queue
 *
 * @package PerformanceOptimisation\Utils
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ConversionQueue {

	private const OPTION_NAME = 'wppo_img_info';

	private array $queue;

	public function __construct() {
		$this->queue = get_option( self::OPTION_NAME, [
			'pending'   => [],
			'completed' => [],
			'failed'    => [],
			'skipped'   => [],
		] );
	}

	public function add( string $image_path, string $format ): void {
		$relative_path = $this->get_relative_path( $image_path );
		if ( ! in_array( $relative_path, $this->queue['pending'][ $format ] ?? [], true ) ) {
			$this->queue['pending'][ $format ][] = $relative_path;
		}
	}

	public function get_pending( string $format, int $limit = 10 ): array {
		return array_slice( $this->queue['pending'][ $format ] ?? [], 0, $limit );
	}

	public function update_status( string $image_path, string $format, string $status ): void {
		$relative_path = $this->get_relative_path( $image_path );

		// Remove from pending
		if ( isset( $this->queue['pending'][ $format ] ) ) {
			$this->queue['pending'][ $format ] = array_diff( $this->queue['pending'][ $format ], [ $relative_path ] );
		}

		// Add to new status
		if ( ! in_array( $relative_path, $this->queue[ $status ][ $format ] ?? [], true ) ) {
			$this->queue[ $status ][ $format ][] = $relative_path;
		}
	}

	public function get_stats(): array {
		$stats = [];
		foreach ( $this->queue as $status => $formats ) {
			foreach ( $formats as $format => $images ) {
				$stats[ $format ][ $status ] = count( $images );
			}
		}
		return $stats;
	}

	public function save(): void {
		update_option( self::OPTION_NAME, $this->queue );
	}

	private function get_relative_path( string $path ): string {
		return str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $path ) );
	}
}
