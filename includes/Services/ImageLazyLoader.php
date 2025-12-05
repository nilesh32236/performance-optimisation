<?php
/**
 * Image Lazy Loader Service
 *
 * @package PerformanceOptimisation\Services
 * @since 2.1.0
 */

namespace PerformanceOptimisation\Services;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class ImageLazyLoader
 *
 * Handles image lazy loading logic.
 *
 * @package PerformanceOptimisation\Services
 */
class ImageLazyLoader {

	/**
	 * Enable lazy loading for content.
	 *
	 * @param string $content Content to filter.
	 * @return string Filtered content.
	 */
	public function enable_lazy_loading( string $content ): string {
		// A simple lazy loading implementation. More advanced features can be added later.
		$content = preg_replace_callback(
			'/<img([^>]+)src=["\']([^"\\]+)["\'])([^>]*)>/i',
			function ( $matches ) {
				$has_data_src = strpos( $matches[1] . $matches[3], 'data-src' ) !== false;
				$has_lazy_src = strpos( $matches[1] . $matches[3], 'data-lazy-src' ) !== false;
				if ( $has_data_src || $has_lazy_src ) {
					return $matches[0];
				}
				$placeholder    = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
				$new_attributes = ' src="' . $placeholder . '" data-src="' . esc_attr( $matches[2] ) . '"';
				return '<img' . $matches[1] . $new_attributes . $matches[3] . ' class="lazyload">';
			},
			$content
		);

		return $content;
	}
}
