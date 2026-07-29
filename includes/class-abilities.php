<?php
/**
 * Abilities Class for registering plugin capabilities with the WP 7.0 Abilities API.
 *
 * Registers performance optimisation abilities for discoverability by AI assistants
 * and other plugins via the WordPress Abilities API.
 *
 * @package PerformanceOptimise\Inc
 * @since   2.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Abilities' ) ) {
	/**
	 * Class Abilities
	 *
	 * Registers the plugin's performance capabilities with the WP 7.0 Abilities API
	 * so that AI assistants and other plugins can discover WPPO's feature surface.
	 *
	 * @since 2.0.0
	 */
	class Abilities {

		/**
		 * Register hooks for the Abilities API.
		 *
		 * @since 2.0.0
		 */
		public function __construct() {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
			add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		}

		/**
		 * Register the performance-optimisation ability category.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_categories(): void {
			if ( ! function_exists( 'wp_register_ability_category' ) ) {
				return;
			}

			wp_register_ability_category(
				'performance-optimisation',
				array(
					'label'       => __( 'Performance Optimisation', 'performance-optimisation' ),
					'description' => __( 'Performance optimisation capabilities provided by the Performance Optimisation plugin.', 'performance-optimisation' ),
				)
			);
		}

		/**
		 * Register all plugin abilities with the Abilities API.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function register_abilities(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$abilities = $this->get_ability_definitions();

			foreach ( $abilities as $ability ) {
				wp_register_ability( $ability['id'], $ability['args'] );
			}
		}

		/**
		 * Get the definitions for all registered abilities.
		 *
		 * @since 2.0.0
		 * @return array[] Array of ability definition arrays, each with 'id' and 'args'.
		 */
		private function get_ability_definitions(): array {
			$category = 'performance-optimisation';

			$base_args = array(
				'category'            => $category,
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(),
				),
				'output_schema'       => array(
					'type' => 'boolean',
				),
				'permission_callback' => array( __CLASS__, 'permission_check' ),
				'meta'                => array(
					'annotations'  => array(
						'readonly' => true,
					),
					'show_in_rest' => true,
				),
			);

			return array(
				array(
					'id'   => 'performance-optimisation/cache-management',
					'args' => array_merge(
						$base_args,
						array(
							'label'            => __( 'Cache Management', 'performance-optimisation' ),
							'description'      => __( 'Static HTML page caching with smart invalidation.', 'performance-optimisation' ),
							'execute_callback' => array( __CLASS__, 'can_cache_management' ),
						)
					),
				),
				array(
					'id'   => 'performance-optimisation/image-optimisation',
					'args' => array_merge(
						$base_args,
						array(
							'label'            => __( 'Image Optimization', 'performance-optimisation' ),
							'description'      => __( 'WebP/AVIF conversion, lazy loading, responsive images.', 'performance-optimisation' ),
							'execute_callback' => array( __CLASS__, 'can_image_optimization' ),
						)
					),
				),
				array(
					'id'   => 'performance-optimisation/css-minification',
					'args' => array_merge(
						$base_args,
						array(
							'label'            => __( 'CSS Minification', 'performance-optimisation' ),
							'description'      => __( 'Combine and minify CSS files.', 'performance-optimisation' ),
							'execute_callback' => array( __CLASS__, 'can_css_minification' ),
						)
					),
				),
				array(
					'id'   => 'performance-optimisation/js-optimisation',
					'args' => array_merge(
						$base_args,
						array(
							'label'            => __( 'JS Optimization', 'performance-optimisation' ),
							'description'      => __( 'Minify, defer, and delay JavaScript.', 'performance-optimisation' ),
							'execute_callback' => array( __CLASS__, 'can_js_optimization' ),
						)
					),
				),
				array(
					'id'   => 'performance-optimisation/database-cleanup',
					'args' => array_merge(
						$base_args,
						array(
							'label'            => __( 'Database Cleanup', 'performance-optimisation' ),
							'description'      => __( 'Clean revisions, drafts, trash, spam, transients, orphans.', 'performance-optimisation' ),
							'execute_callback' => array( __CLASS__, 'can_database_cleanup' ),
						)
					),
				),
				array(
					'id'   => 'performance-optimisation/redis-object-cache',
					'args' => array_merge(
						$base_args,
						array(
							'label'            => __( 'Redis Object Cache', 'performance-optimisation' ),
							'description'      => __( 'Redis object cache with standalone/sentinel/cluster support.', 'performance-optimisation' ),
							'execute_callback' => array( __CLASS__, 'can_redis_object_cache' ),
						)
					),
				),
			);
		}

		/**
		 * Permission callback for all abilities.
		 *
		 * @since 2.0.0
		 * @return bool Whether the current user can manage options.
		 */
		public static function permission_check(): bool {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Execute callback: Cache Management.
		 *
		 * @since 2.0.0
		 * @return true
		 */
		public static function can_cache_management(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['cache']['enableCache'] );
		}

		/**
		 * Execute callback: Image Optimization.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function can_image_optimization(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['image_optimisation']['convertImg'] );
		}

		/**
		 * Execute callback: CSS Minification.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function can_css_minification(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['file_optimisation']['minifyCSS'] );
		}

		/**
		 * Execute callback: JS Optimization.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function can_js_optimization(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['file_optimisation']['minifyJS'] ) || ! empty( $options['file_optimisation']['deferJS'] ) || ! empty( $options['file_optimisation']['delayJS'] );
		}

		/**
		 * Execute callback: Database Cleanup.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function can_database_cleanup(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['database_cleanup'] );
		}

		/**
		 * Execute callback: Redis Object Cache.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public static function can_redis_object_cache(): bool {
			return wp_using_ext_object_cache();
		}
	}
}
