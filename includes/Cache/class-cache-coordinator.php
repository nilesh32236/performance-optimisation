<?php
/**
 * Cache creation coordination seam for the plugin lifecycle.
 *
 * Owns the historical Main::create_cache() construction/filter sequence while
 * Main remains the public compatibility facade and orchestrator.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache_Coordinator' ) ) {
	/**
	 * Coordinate Cache construction and the public injection filter.
	 *
	 * This is intentionally one narrow cache-construction policy, not a broad
	 * lifecycle service. Optional live collaborators are passed through by
	 * identity, and the historical `wppo_cache_instance` filter arguments and
	 * return contract remain unchanged.
	 *
	 * @since NEXT
	 */
	final class Cache_Coordinator {

		/**
		 * Create a Cache collaborator and apply the injection filter.
		 *
		 * @since NEXT
		 * @param array                   $options            Effective plugin options.
		 * @param Image_Optimisation|null $image_optimisation Optional live image collaborator.
		 * @param Google_Fonts|null       $google_fonts       Optional live font collaborator.
		 * @return mixed Cache instance or filtered test/integration stub.
		 */
		public static function create( array $options, ?Image_Optimisation $image_optimisation = null, ?Google_Fonts $google_fonts = null ) {
			$cache = new Cache(
				$options,
				$image_optimisation,
				$google_fonts
			);
			/**
			 * Filter the Cache collaborator instance.
			 *
			 * @since 2.2.0
			 * @param mixed $cache   Cache instance.
			 * @param array $options Plugin options.
			 */
			return apply_filters( 'wppo_cache_instance', $cache, $options );
		}
	}
}
