<?php
/**
 * Neutral drop-in invalidation registry.
 *
 * Keeps drop-in mutators independent from System_Info's reporting and
 * ownership-detection implementation while preserving System_Info as the
 * single owner of its request memo, transient, and salted-cache invalidation.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Dropin_Registry' ) ) {
	/**
	 * Coordinate invalidation after a drop-in mutation.
	 *
	 * @since NEXT
	 */
	final class Dropin_Registry {

		/**
		 * Invalidate cached drop-in observations after a mutation.
		 *
		 * System_Info remains the owner of reporting, path resolution,
		 * ownership detection, and cache storage. This fail-open bridge only
		 * forwards invalidation when that owner is loadable.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function invalidate(): void {
			if ( ! is_callable( array( 'PerformanceOptimise\Inc\System_Info', 'flush_dropin_cache' ) ) ) {
				return;
			}

			System_Info::flush_dropin_cache();
		}
	}
}
