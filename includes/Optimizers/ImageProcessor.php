<?php
/**
 * Image Processor
 *
 * @package PerformanceOptimisation\Optimizers
 * @since 2.0.0
 */

namespace PerformanceOptimisation\Optimizers;

use PerformanceOptimisation\Utils\FileSystemUtil;
use PerformanceOptimisation\Utils\LoggingUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImageProcessor {

	public function convert( string $source_image_path, string $target_image_path, string $target_format, int $quality ): bool {
		if ( ! FileSystemUtil::fileExists( $source_image_path ) ) {
			LoggingUtil::error( "Image conversion failed: Source file not found at {$source_image_path}" );
			return false;
		}

		if ( ! function_exists( 'imagewebp' ) && 'webp' === $target_format ) {
			LoggingUtil::error( 'Image conversion failed: WebP support is not available.' );
			return false;
		}

		if ( ! function_exists( 'imageavif' ) && 'avif' === $target_format ) {
			LoggingUtil::error( 'Image conversion failed: AVIF support is not available.' );
			return false;
		}

		$image_info = getimagesize( $source_image_path );
		if ( ! $image_info ) {
			LoggingUtil::error( "Image conversion failed: Could not get image info for {$source_image_path}" );
			return false;
		}

		$image_resource = $this->createImageResource( $source_image_path, $image_info[2] );
		if ( ! $image_resource ) {
			LoggingUtil::error( "Image conversion failed: Could not create image resource for {$source_image_path}" );
			return false;
		}

		$success = false;
		if ( 'webp' === $target_format ) {
			$success = imagewebp( $image_resource, $target_image_path, $quality );
		} elseif ( 'avif' === $target_format ) {
			$success = imageavif( $image_resource, $target_image_path, $quality );
		}

		imagedestroy( $image_resource );

		if ( ! $success ) {
			LoggingUtil::error( "Image conversion failed: Could not save image to {$target_image_path}" );
		}

		return $success;
	}

	private function createImageResource( string $path, int $type ) {
		switch ( $type ) {
			case IMAGETYPE_JPEG:
				return imagecreatefromjpeg( $path );
			case IMAGETYPE_PNG:
				$resource = imagecreatefrompng( $path );
				imagepalettetotruecolor( $resource );
				imagealphablending( $resource, true );
				imagesavealpha( $resource, true );
				return $resource;
			case IMAGETYPE_GIF:
				return imagecreatefromgif( $path );
			case IMAGETYPE_WEBP:
				return imagecreatefromwebp( $path );
			default:
				return null;
		}
	}
}
