<?php
/**
 * Blog-scoped runtime state reset registry.
 *
 * @package PerformanceOptimise\Inc
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( __NAMESPACE__ . '\\Runtime_State' ) ) {
	/**
	 * Delegates site-sensitive static state resets to feature owners.
	 *
	 * @since NEXT
	 */
	class Runtime_State {
		/**
		 * Feature owners and their reset methods.
		 *
		 * @return array<string,array{0:string,1:string}>
		 */
		public static function owners(): array {
			return array(
				'RUM'                   => array( __NAMESPACE__ . '\\RUM', 'clear_field_lcp_cache' ),
				'AI_Adaptive'           => array( __NAMESPACE__ . '\\AI_Adaptive', 'reset_runtime_state' ),
				'System_Info'           => array( __NAMESPACE__ . '\\System_Info', 'reset_runtime_state' ),
				'LiteSpeed_Integration' => array( __NAMESPACE__ . '\\LiteSpeed_Integration', 'reset_cache' ),
				'Object_Cache'          => array( __NAMESPACE__ . '\\Object_Cache', 'reset_runtime_state' ),
				'Database_Cleanup'      => array( __NAMESPACE__ . '\\Database_Cleanup', 'reset_runtime_state' ),
			);
		}

		/**
		 * Reset all registered owners, skipping optional or unavailable features.
		 *
		 * @return void
		 */
		public static function reset_all(): void {
			foreach ( self::owners() as $owner ) {
				try {
					if ( class_exists( $owner[0] ) && is_callable( array( $owner[0], $owner[1] ) ) ) {
						call_user_func( array( $owner[0], $owner[1] ) );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		/**
		 * Reset site-sensitive state when WordPress switches blogs.
		 *
		 * @param int $new_blog_id New blog ID.
		 * @param int $prev_blog_id Previous blog ID.
		 * @return void
		 */
		public static function on_switch_blog( $new_blog_id, $prev_blog_id ): void {
			unset( $new_blog_id, $prev_blog_id );
			self::reset_all();
		}
	}
}
