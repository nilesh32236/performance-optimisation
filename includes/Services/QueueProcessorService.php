<?php
/**
 * Queue Processor Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Services;

use PerformanceOptimisation\Utils\ConversionQueue;
use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Queue Processor Service
 *
 * Processes image conversion queue in the background using WP Cron.
 */
class QueueProcessorService {

	private const CRON_HOOK = 'wppo_process_conversion_queue';
	private const BATCH_SIZE = 5; // Process 5 images per cron run

	private ConversionQueue $queue;
	private ImageService $imageService;
	private array $settings;

	/**
	 * Constructor
	 *
	 * @param ConversionQueue $queue Queue instance.
	 * @param ImageService    $imageService Image service instance.
	 */
	public function __construct( ConversionQueue $queue, ImageService $imageService ) {
		$this->queue        = $queue;
		$this->imageService = $imageService;
		$this->settings     = get_option( 'wppo_settings', array() );
	}

	/**
	 * Initialize the queue processor
	 *
	 * @return void
	 */
	public function init(): void {
		// Register cron hook
		add_action( self::CRON_HOOK, array( $this, 'process_queue' ) );

		// Schedule cron if not already scheduled
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'wppo_one_minute', self::CRON_HOOK );
		}

		// Register custom cron schedule
		add_filter( 'cron_schedules', array( $this, 'add_cron_schedule' ) );

		LoggingUtil::info( 'Queue processor initialized' );
	}

	/**
	 * Add custom cron schedule (every minute)
	 *
	 * @param array $schedules Existing schedules.
	 * @return array Modified schedules.
	 */
	public function add_cron_schedule( array $schedules ): array {
		$schedules['wppo_one_minute'] = array(
			'interval' => 60,
			'display'  => __( 'Every Minute', 'performance-optimisation' ),
		);
		return $schedules;
	}

	/**
	 * Process the conversion queue
	 *
	 * @return void
	 */
	public function process_queue(): void {
		$items = $this->queue->get_pending_items( self::BATCH_SIZE );

		if ( empty( $items ) ) {
			return;
		}

		LoggingUtil::info( 'Processing queue batch', array(
			'count' => count( $items ),
		) );

		foreach ( $items as $item ) {
			$this->process_item( $item['source_path'], $item['target_format'] );
		}

		// Save queue status after processing
		$this->queue->save();
	}

	/**
	 * Process a single queue item
	 *
	 * @param string $source_path Source image path.
	 * @param string $target_format Target format (webp/avif).
	 * @return void
	 */
	private function process_item( string $source_path, string $target_format ): void {
		try {
			// Check if file still exists
			if ( ! file_exists( $source_path ) ) {
				LoggingUtil::warning( 'Queue item file not found, marking as skipped', array(
					'path' => $source_path,
				) );
				$this->queue->update_status( $source_path, $target_format, 'skipped' );
				return;
			}

			// Perform conversion
			$result = $this->imageService->convert_image( $source_path, $target_format );

			if ( ! empty( $result ) ) {
				LoggingUtil::info( 'Queue item converted successfully', array(
					'source' => $source_path,
					'format' => $target_format,
					'result' => $result,
				) );
				$this->queue->update_status( $source_path, $target_format, 'completed' );
				
				// Handle deletion in Space Saver mode
				$this->delete_original_if_needed( $source_path, $target_format );
			} else {
				LoggingUtil::warning( 'Queue item conversion failed', array(
					'source' => $source_path,
					'format' => $target_format,
				) );
				$this->queue->update_status( $source_path, $target_format, 'failed' );
			}
		} catch ( \Exception $e ) {
			LoggingUtil::error( 'Queue processing exception', array(
				'source'  => $source_path,
				'format'  => $target_format,
				'message' => $e->getMessage(),
			) );
			$this->queue->update_status( $source_path, $target_format, 'failed' );
		}
	}

	/**
	 * Delete original file if Space Saver mode is enabled
	 *
	 * @param string $source_path Original file path.
	 * @param string $target_format Format that was just converted.
	 * @return void
	 */
	private function delete_original_if_needed( string $source_path, string $target_format ): void {
		// Check if Space Saver mode is enabled
		$storage_mode = $this->settings['images']['storage_mode'] 
			?? $this->settings['image_optimization']['storage_mode'] 
			?? 'safe';

		if ( $storage_mode !== 'space_saver' ) {
			return; // Safe mode - keep originals
		}

		// Get enabled formats from settings
		$webp_enabled = ! empty( $this->settings['images']['convert_to_webp'] ) 
			|| ! empty( $this->settings['image_optimization']['webp_conversion'] );
		$avif_enabled = ! empty( $this->settings['images']['convert_to_avif'] ) 
			|| ! empty( $this->settings['image_optimization']['avif_conversion'] );

		// Determine required conversions
		$required_formats = array();
		if ( $webp_enabled ) {
			$required_formats[] = 'webp';
		}
		if ( $avif_enabled ) {
			$required_formats[] = 'avif';
		}

		// Verify ALL required formats have been successfully converted
		if ( ! $this->all_formats_converted( $source_path, $required_formats ) ) {
			LoggingUtil::info( 'Skipping deletion - not all formats converted yet', array(
				'source'           => $source_path,
				'just_converted'   => $target_format,
				'required_formats' => $required_formats,
			) );
			return;
		}

		// Perform safety checks before deletion
		if ( ! $this->is_safe_to_delete( $source_path, $required_formats ) ) {
			LoggingUtil::warning( 'Safety check failed - keeping original', array(
				'source' => $source_path,
			) );
			return;
		}

		// Safe to delete - perform deletion
		$original_size = file_exists( $source_path ) ? filesize( $source_path ) : 0;
		if ( @unlink( $source_path ) ) {
			LoggingUtil::info( 'Original file deleted (Space Saver mode)', array(
				'source'     => $source_path,
				'formats'    => $required_formats,
				'deleted_at' => current_time( 'mysql' ),
				'size'       => $original_size,
			) );

			// Track deletion for  potential restore/audit
			$this->log_deletion( $source_path, $required_formats, $original_size );
		} else {
			LoggingUtil::error( 'Failed to delete original file', array(
				'source' => $source_path,
			) );
		}
	}

	/**
	 * Check if all required formats have been converted
	 *
	 * @param string $source_path Original file path.
	 * @param array  $required_formats List of required formats.
	 * @return bool True if all formats exist and are valid.
	 */
	private function all_formats_converted( string $source_path, array $required_formats ): bool {
		foreach ( $required_formats as $format ) {
			$converted_path = \PerformanceOptimisation\Utils\ImageUtil::optimizeImagePath( $source_path, $format );
			
			if ( ! file_exists( $converted_path ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Perform safety checks before deleting original
	 *
	 * @param string $source_path Original file path.
	 * @param array  $formats Converted formats.
	 * @return bool True if safe to delete.
	 */
	private function is_safe_to_delete( string $source_path, array $formats ): bool {
		// Check 1: Original file must exist
		if ( ! file_exists( $source_path ) ) {
			return false;
		}

		// Check 2: All converted files must be valid (> 100 bytes)
		foreach ( $formats as $format ) {
			$converted_path = \PerformanceOptimisation\Utils\ImageUtil::optimizeImagePath( $source_path, $format );
			
			if ( ! file_exists( $converted_path ) || filesize( $converted_path ) < 100 ) {
				LoggingUtil::warning( 'Converted file too small or missing', array(
					'format' => $format,
					'path'   => $converted_path,
					'size'   => file_exists( $converted_path ) ? filesize( $converted_path ) : 0,
				) );
				return false;
			}
		}

		// Check 3: Don't delete if it's a WordPress-generated thumbnail
		// (Let WordPress manage those)
		$basename = basename( $source_path );
		if ( preg_match( '/-\d+x\d+\.(jpg|jpeg|png|gif)$/i', $basename ) ) {
			// This is a thumbnail - skip for now (WordPress handles these)
			return false;
		}

		return true;
	}

	/**
	 * Log file deletion for audit trail
	 *
	 * @param string $source_path Deleted file path.
	 * @param array  $formats Converted formats.
	 * @return void
	 */
	private function log_deletion( string $source_path, array $formats, int $size = 0 ): void {
		$deletions = get_option( 'wppo_deleted_originals', array() );
		
		$deletions[ $source_path ] = array(
			'deleted_at'    => current_time( 'mysql' ),
			'converted_to'  => $formats,
			'original_size' => $size,
		);

		// Keep only last 1000 deletions to prevent option bloat
		if ( count( $deletions ) > 1000 ) {
			$deletions = array_slice( $deletions, -1000, null, true );
		}

		update_option( 'wppo_deleted_originals', $deletions );
	}

	/**
	 * Get queue statistics
	 *
	 * @return array Queue statistics.
	 */
	public function get_stats(): array {
		return $this->queue->get_stats();
	}

	/**
	 * Clear the queue (for admin use)
	 *
	 * @return void
	 */
	public function clear_queue(): void {
		$this->queue->clear();
		LoggingUtil::info( 'Queue cleared' );
	}
}
