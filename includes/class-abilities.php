<?php
/**
 * Abilities Class for registering plugin capabilities with the WP 7.0 Abilities API.
 *
 * Registers performance optimisation abilities for discoverability by AI assistants
 * and other plugins via the WordPress Abilities API.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
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
	 * @since NEXT
	 */
	class Abilities {

		/**
		 * Register hooks for the Abilities API.
		 *
		 * @since NEXT
		 */
		public function __construct() {
			add_action( 'wp_abilities_api_categories_init', array( $this, 'register_categories' ) );
			add_action( 'wp_abilities_api_init', array( $this, 'register_abilities' ) );
		}

		/**
		 * Register the performance-optimisation ability category.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
		 *
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

			$feature_abilities = array(
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
							'label'            => __( 'Image Optimisation', 'performance-optimisation' ),
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
							'label'            => __( 'JS Optimisation', 'performance-optimisation' ),
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

			$operational = $this->get_operational_abilities();

			return array_merge( $feature_abilities, $operational );
		}

		/**
		 * Get operational ability definitions that delegate to REST service methods.
		 *
		 * These abilities are additive wrappers around existing REST routes
		 * (performance-optimisation/v1). The SPA keeps using REST; abilities
		 * expose the same operations via wp-abilities/v1 for Command Palette
		 * and MCP discovery. Guarded by function_exists('wp_register_ability')
		 * in register_abilities() so WP <6.9 is no-op.
		 *
		 * @since NEXT
		 *
		 * @return array[] Operational ability definitions.
		 */
		private function get_operational_abilities(): array {
			$category = 'performance-optimisation';

			return array(
				array(
					'id'   => 'performance-optimisation/clear-cache',
					'args' => array(
						'label'               => __( 'Clear Cache', 'performance-optimisation' ),
						'description'         => __( 'Clear the static HTML cache (all or a single URL).', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'scope' => array(
									'type'        => 'string',
									'enum'        => array( 'all', 'single' ),
									'description' => __( 'Whether to clear all cache or a single URL.', 'performance-optimisation' ),
								),
								'url'   => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'URL to clear when scope is single.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'cleared' => array( 'type' => 'boolean' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_clear_cache' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/optimise-image',
					'args' => array(
						'label'               => __( 'Optimise Image', 'performance-optimisation' ),
						'description'         => __( 'Queue or synchronously convert an image attachment to WebP/AVIF.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'attachment_id' => array(
									'type'        => 'integer',
									'description' => __( 'Attachment ID to optimise.', 'performance-optimisation' ),
								),
								'format'        => array(
									'type'        => 'string',
									'enum'        => array( 'webp', 'avif', 'both' ),
									'description' => __( 'Target format.', 'performance-optimisation' ),
								),
							),
							'required'   => array( 'attachment_id' ),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'queued' => array( 'type' => 'boolean' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_optimise_image' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/run-database-cleanup',
					'args' => array(
						'label'               => __( 'Run Database Cleanup', 'performance-optimisation' ),
						'description'         => __( 'Run a database cleanup operation by type.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'type' => array(
									'type'        => 'string',
									'enum'        => array( 'revisions', 'auto_drafts', 'trashed_posts', 'spam_comments', 'trashed_comments', 'expired_transients', 'orphan_postmeta', 'unattached_media', 'oembed_cache', 'all' ),
									'description' => __( 'Cleanup type.', 'performance-optimisation' ),
								),
							),
							'required'   => array( 'type' ),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'cleaned' => array( 'type' => 'integer' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_database_cleanup' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/flush-object-cache',
					'args' => array(
						'label'               => __( 'Flush Object Cache', 'performance-optimisation' ),
						'description'         => __( 'Flush the Redis object cache.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'flushed' => array( 'type' => 'boolean' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_flush_object_cache' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
			);
		}

		/**
		 * Permission callback for all abilities.
		 *
		 * @since NEXT
		 * @return bool Whether the current user can manage options.
		 */
		public static function permission_check(): bool {
			return current_user_can( 'manage_options' );
		}

		/**
		 * Execute callback: Cache Management.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_cache_management(): bool {
			$options = Util::get_settings();
			return ! empty( $options['cache_settings']['enableCache'] );
		}

		/**
		 * Execute callback: Image Optimization.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_image_optimization(): bool {
			$options = Util::get_settings();
			return ! empty( $options['image_optimisation']['convertImg'] );
		}

		/**
		 * Execute callback: CSS Minification.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_css_minification(): bool {
			$options = Util::get_settings();
			return ! empty( $options['file_optimisation']['minifyCSS'] );
		}

		/**
		 * Execute callback: JS Optimization.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_js_optimization(): bool {
			$options = Util::get_settings();
			return ! empty( $options['file_optimisation']['minifyJS'] ) || ! empty( $options['file_optimisation']['deferJS'] ) || ! empty( $options['file_optimisation']['delayJS'] );
		}

		/**
		 * Execute callback: Database Cleanup.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_database_cleanup(): bool {
			$options = Util::get_settings();
			return ! empty( $options['database_cleanup'] );
		}

		/**
		 * Execute callback: Redis Object Cache.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_redis_object_cache(): bool {
			return wp_using_ext_object_cache();
		}

		/**
		 * Execute callback: Clear Cache (operational).
		 *
		 * Delegates to Cache::clear_cache() — same path as the REST handler.
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (scope, url).
		 * @return array{cleared: bool}
		 */
		public static function execute_clear_cache( array $input = array() ): array {
			$scope = $input['scope'] ?? 'all';
			if ( 'single' === $scope && ! empty( $input['url'] ) ) {
				$url = esc_url_raw( $input['url'] );
				if ( '' !== $url ) {
					$path = wp_parse_url( $url, PHP_URL_PATH );
					Cache::clear_cache( $path );
					return array( 'cleared' => true );
				}
			}
			Main::clear_all_cache();
			return array( 'cleared' => true );
		}

		/**
		 * Execute callback: Optimise Image (operational).
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (attachment_id, format).
		 * @return array{queued: bool}
		 */
		public static function execute_optimise_image( array $input ): array {
			$attachment_id = isset( $input['attachment_id'] ) ? (int) $input['attachment_id'] : 0;
			$format        = isset( $input['format'] ) ? sanitize_text_field( $input['format'] ) : 'webp';
			if ( $attachment_id <= 0 ) {
				return array( 'queued' => false );
			}
			$file = get_attached_file( $attachment_id );
			if ( ! $file || ! file_exists( $file ) ) {
				return array( 'queued' => false );
			}
			$queued = Img_Converter::add_img_into_queue( $file, $format );
			return array( 'queued' => (bool) $queued );
		}

		/**
		 * Execute callback: Run Database Cleanup (operational).
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (type).
		 * @return array{cleaned: int}
		 */
		public static function execute_database_cleanup( array $input ): array {
			$type        = isset( $input['type'] ) ? sanitize_text_field( $input['type'] ) : '';
			$valid_types = Database_Cleanup::get_valid_cleanup_types();
			if ( ! in_array( $type, $valid_types, true ) ) {
				return array( 'cleaned' => 0 );
			}
			if ( 'all' === $type ) {
				$results = Database_Cleanup::clean_all();
				$total   = 0;
				foreach ( $results as $value ) {
					if ( ! is_wp_error( $value ) ) {
						$total += (int) $value;
					}
				}
				return array( 'cleaned' => $total );
			}
			$method_map = Database_Cleanup::CLEANUP_METHOD_MAP;
			$method     = $method_map[ $type ] ?? null;
			if ( ! $method ) {
				return array( 'cleaned' => 0 );
			}
			if ( 'revisions' === $type ) {
				list( $rev_max_age, $rev_keep ) = Database_Cleanup::get_revision_defaults();
				$result                         = Database_Cleanup::invoke_cleanup_method( $method, $rev_max_age, $rev_keep );
			} else {
				$result = Database_Cleanup::invoke_cleanup_method( $method );
			}
			if ( is_wp_error( $result ) ) {
				return array( 'cleaned' => 0 );
			}
			return array( 'cleaned' => (int) $result );
		}

		/**
		 * Execute callback: Flush Object Cache (operational).
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array{flushed: bool}
		 */
		public static function execute_flush_object_cache( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Ability API passes input even when empty.
			$object_cache = new Object_Cache();
			$flushed      = $object_cache->flush();
			return array( 'flushed' => (bool) $flushed );
		}
	}
}
