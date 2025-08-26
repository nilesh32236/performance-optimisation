<?php
/**
 * Image Optimisation class for handling image conversion, preloading, and serving optimized images.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimisation\Services\ImageService;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {

	/**
	 * Image Optimisation class.
	 *
	 * @since 1.0.0
	 */
	class Image_Optimisation {

		private ImageService $imageService;

		public function __construct( ImageService $imageService ) {
			$this->imageService = $imageService;
		}

		public function preload_images_on_page_load(): void {
			$this->imageService->preload_images();
		}

		public function maybe_serve_next_gen_images( string $buffer ): string {
			return $this->imageService->enable_lazy_loading( $buffer );
		}
	}
}
