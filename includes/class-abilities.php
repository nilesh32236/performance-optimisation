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
					'id'   => 'performance-optimisation/get-system-info',
					'args' => array(
						'label'               => __( 'Get System Info', 'performance-optimisation' ),
						'description'         => __( 'Get PHP, database, WordPress, server, and cache information.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_system_info' ),
						'meta'                => array(
							'readonly'     => true,
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/run-performance-scan',
					'args' => array(
						'label'               => __( 'Run Performance Scan', 'performance-optimisation' ),
						'description'         => __( 'Run a local performance telemetry scan for a URL.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'url' => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'URL to scan.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_run_performance_scan' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/queue-pagespeed-scan',
					'args' => array(
						'label'               => __( 'Queue PageSpeed Scan', 'performance-optimisation' ),
						'description'         => __( 'Queue a Google PageSpeed Insights scan for a URL.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'url'      => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'URL to scan.', 'performance-optimisation' ),
								),
								'strategy' => array(
									'type'        => 'string',
									'enum'        => array( 'mobile', 'desktop' ),
									'description' => __( 'PageSpeed strategy.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'queued' => array( 'type' => 'boolean' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_queue_pagespeed_scan' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/get-pagespeed-results',
					'args' => array(
						'label'               => __( 'Get PageSpeed Results', 'performance-optimisation' ),
						'description'         => __( 'Get stored PageSpeed Insights results for a URL.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'url'      => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'URL to get results for.', 'performance-optimisation' ),
								),
								'strategy' => array(
									'type'        => 'string',
									'enum'        => array( 'mobile', 'desktop' ),
									'description' => __( 'PageSpeed strategy.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_pagespeed_results' ),
						'meta'                => array(
							'readonly'     => true,
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/get-suggestions',
					'args' => array(
						'label'               => __( 'Get Suggestions', 'performance-optimisation' ),
						'description'         => __( 'Get performance improvement suggestions for a URL.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'url' => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'URL to get suggestions for.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'suggestions' => array( 'type' => 'array' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_suggestions' ),
						'meta'                => array(
							'readonly'     => true,
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/get-database-cleanup-counts',
					'args' => array(
						'label'               => __( 'Get Database Cleanup Counts', 'performance-optimisation' ),
						'description'         => __( 'Get the number of items that can be cleaned for each type.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_database_cleanup_counts' ),
						'meta'                => array(
							'readonly'     => true,
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
					'id'   => 'performance-optimisation/get-image-job-status',
					'args' => array(
						'label'               => __( 'Get Image Job Status', 'performance-optimisation' ),
						'description'         => __( 'Get the status of background image optimisation jobs.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_image_job_status' ),
						'meta'                => array(
							'readonly'     => true,
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/get-page-assets',
					'args' => array(
						'label'               => __( 'Get Page Assets', 'performance-optimisation' ),
						'description'         => __( 'Get captured frontend assets (scripts/styles) for a post.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'post_id' => array(
									'type'        => 'integer',
									'description' => __( 'Post ID.', 'performance-optimisation' ),
								),
							),
							'required'   => array( 'post_id' ),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_page_assets' ),
						'meta'                => array(
							'readonly'     => true,
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/get-autoloaded-options',
					'args' => array(
						'label'               => __( 'Get Autoloaded Options', 'performance-optimisation' ),
						'description'         => __( 'Get the largest autoloaded options for bloat auditing.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_autoloaded_options' ),
						'meta'                => array(
							'readonly'     => true,
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'performance-optimisation/get-rum-data',
					'args' => array(
						'label'               => __( 'Get RUM Data', 'performance-optimisation' ),
						'description'         => __( 'Get aggregated real-user Web Vitals data.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_get_rum_data' ),
						'meta'                => array(
							'readonly'     => true,
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
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['cache_settings']['enableCache'] );
		}

		/**
		 * Execute callback: Image Optimization.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_image_optimization(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['image_optimisation']['convertImg'] );
		}

		/**
		 * Execute callback: CSS Minification.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_css_minification(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['file_optimisation']['minifyCSS'] );
		}

		/**
		 * Execute callback: JS Optimization.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_js_optimization(): bool {
			$options = get_option( 'wppo_settings', array() );
			return ! empty( $options['file_optimisation']['minifyJS'] ) || ! empty( $options['file_optimisation']['deferJS'] ) || ! empty( $options['file_optimisation']['delayJS'] );
		}

		/**
		 * Execute callback: Database Cleanup.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function can_database_cleanup(): bool {
			$options = get_option( 'wppo_settings', array() );
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
		 * Execute callback: Get System Info (operational).
		 *
		 * Delegates to System_Info::get_all().
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array System information.
		 */
		public static function execute_get_system_info( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return System_Info::get_all();
		}

		/**
		 * Execute callback: Run Performance Scan (operational).
		 *
		 * Delegates to Telemetry::scan().
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (url).
		 * @return array Scan results or error.
		 */
		public static function execute_run_performance_scan( array $input ): array {
			$url    = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : Util::cached_home_url( '/' );
			$result = Telemetry::scan( $url, 'manual', false );
			if ( is_wp_error( $result ) ) {
				return array( 'error' => $result->get_error_message() );
			}
			return $result;
		}

		/**
		 * Execute callback: Queue PageSpeed Scan (operational).
		 *
		 * Delegates to Pagespeed::queue_scan().
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (url, strategy).
		 * @return array{queued: bool}
		 */
		public static function execute_queue_pagespeed_scan( array $input ): array {
			$url    = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : Util::cached_home_url( '/' );
			$format = isset( $input['strategy'] ) ? sanitize_text_field( $input['strategy'] ) : 'mobile';
			$job_id = Pagespeed::queue_scan( $url, $format );
			return array( 'queued' => $job_id > 0 );
		}

		/**
		 * Execute callback: Get PageSpeed Results (operational).
		 *
		 * Delegates to Pagespeed::get_results().
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (url, strategy).
		 * @return array PageSpeed results.
		 */
		public static function execute_get_pagespeed_results( array $input ): array {
			$url     = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : Util::cached_home_url( '/' );
			$format  = isset( $input['strategy'] ) ? sanitize_text_field( $input['strategy'] ) : 'mobile';
			$results = Pagespeed::get_results( $url, $format );
			return is_array( $results ) ? $results : array();
		}

		/**
		 * Execute callback: Get Suggestions (operational).
		 *
		 * Delegates to Suggestion_Engine::from_telemetry() using cached scan data.
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (url).
		 * @return array{suggestions: array} Suggestions.
		 */
		public static function execute_get_suggestions( array $input ): array {
			$url           = isset( $input['url'] ) ? esc_url_raw( $input['url'] ) : Util::cached_home_url( '/' );
			$transient_key = Util::transient_key( 'wppo_audit_' . md5( $url ) );
			$telemetry     = get_transient( $transient_key );
			if ( false === $telemetry ) {
				return array( 'suggestions' => array() );
			}
			$suggestions = Suggestion_Engine::from_telemetry( $telemetry );
			if ( class_exists( 'PerformanceOptimise\\Inc\\AI_Adaptive' ) && AI_Adaptive::is_enabled() ) {
				$ai = Suggestion_Engine::from_ai_adaptive();
				if ( ! empty( $ai ) ) {
					$suggestions = array_merge( $suggestions, $ai );
				}
			}
			return array( 'suggestions' => $suggestions );
		}

		/**
		 * Execute callback: Get Database Cleanup Counts (operational).
		 *
		 * Delegates to Database_Cleanup::get_cleanup_counts().
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array Cleanup counts by type.
		 */
		public static function execute_get_database_cleanup_counts( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return Database_Cleanup::get_cleanup_counts();
		}

		/**
		 * Execute callback: Get Image Job Status (operational).
		 *
		 * Delegates to Img_Converter::get_img_info().
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array Image job status.
		 */
		public static function execute_get_image_job_status( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return Img_Converter::get_img_info();
		}

		/**
		 * Execute callback: Get Page Assets (operational).
		 *
		 * Delegates to Asset_Manager::get_page_assets().
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (post_id).
		 * @return array Page assets or empty.
		 */
		public static function execute_get_page_assets( array $input ): array {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;
			if ( $post_id <= 0 ) {
				return array(
					'scripts' => array(),
					'styles'  => array(),
				);
			}
			$assets = Asset_Manager::get_page_assets( $post_id );
			return false !== $assets ? $assets : array(
				'scripts' => array(),
				'styles'  => array(),
			);
		}

		/**
		 * Execute callback: Get Autoloaded Options (operational).
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array Autoloaded options data.
		 */
		public static function execute_get_autoloaded_options( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			global $wpdb;
			$autoloaded    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE autoload = 'yes'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Auditing query, not cached.
			$autoload_size = (int) $wpdb->get_var( "SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Auditing query, not cached.
			return array(
				'autoloaded_count'   => $autoloaded,
				'autoloaded_size'    => $autoload_size,
				'autoloaded_size_mb' => round( $autoload_size / ( 1024 * 1024 ), 2 ),
			);
		}

		/**
		 * Execute callback: Get RUM Data (operational).
		 *
		 * Delegates to RUM::get_data().
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array RUM data.
		 */
		public static function execute_get_rum_data( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			return RUM::get_data();
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

		/**
		 * Get WPPO optimization ability definitions for AI/MCP.
		 *
		 * These are the machine-readable `wppo/*` abilities required by
		 * WP Monitor issue #826. They delegate to the same service classes
		 * as the REST routes (`performance-optimisation/v1`) so REST remains
		 * canonical. Guarded by `function_exists('wp_register_ability')` in
		 * `register_wppo_abilities()` so WP < 6.9 is no-op.
		 *
		 * @since NEXT
		 *
		 * @return array[] WPPO ability definitions.
		 */
		private static function get_wppo_ability_definitions(): array {
			$category = 'performance-optimisation';

			return array(
				array(
					'id'   => 'wppo/clear-cache',
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
					'id'   => 'wppo/regenerate-ccss',
					'args' => array(
						'label'               => __( 'Regenerate Critical CSS', 'performance-optimisation' ),
						'description'         => __( 'Regenerate critical CSS for all templates.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'queued' => array( 'type' => 'integer' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_regenerate_ccss' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'wppo/used-css-regenerate',
					'args' => array(
						'label'               => __( 'Regenerate Used CSS', 'performance-optimisation' ),
						'description'         => __( 'Regenerate used CSS for a post or for all pages.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'post_id' => array(
									'type'        => 'integer',
									'description' => __( 'Post ID to regenerate used CSS for. Omit to regenerate for all pages.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type'       => 'object',
							'properties' => array(
								'queued' => array( 'type' => 'integer' ),
							),
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_used_css_regenerate' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
				array(
					'id'   => 'wppo/crawler',
					'args' => array(
						'label'               => __( 'Run Crawler', 'performance-optimisation' ),
						'description'         => __( 'Crawl a batch of URLs to warm the cache.', 'performance-optimisation' ),
						'category'            => $category,
						'input_schema'        => array(
							'type'       => 'object',
							'properties' => array(
								'url'  => array(
									'type'        => 'string',
									'format'      => 'uri',
									'description' => __( 'Single URL to crawl.', 'performance-optimisation' ),
								),
								'urls' => array(
									'type'        => 'array',
									'items'       => array(
										'type'   => 'string',
										'format' => 'uri',
									),
									'description' => __( 'Batch of URLs to crawl.', 'performance-optimisation' ),
								),
							),
						),
						'output_schema'       => array(
							'type' => 'object',
						),
						'permission_callback' => array( __CLASS__, 'permission_check' ),
						'execute_callback'    => array( __CLASS__, 'execute_crawler' ),
						'meta'                => array(
							'show_in_rest' => true,
						),
					),
				),
			);
		}

		/**
		 * Register WPPO optimization abilities for AI/MCP.
		 *
		 * Exposes `wppo/clear-cache`, `wppo/regenerate-ccss`,
		 * `wppo/used-css-regenerate`, and `wppo/crawler` as discoverable
		 * Abilities on WP 6.9+ (`wp_abilities_api_init`). On WP < 6.9 the
		 * `function_exists` guard makes this a no-op and REST remains
		 * canonical. Enables `auto_fix_enabled` AI Adaptive MCP workflows
		 * without REST round-trips.
		 *
		 * @since NEXT
		 *
		 * @return void
		 */
		public static function register_wppo_abilities(): void {
			if ( ! function_exists( 'wp_register_ability' ) ) {
				return;
			}

			$abilities = self::get_wppo_ability_definitions();

			foreach ( $abilities as $ability ) {
				$result = wp_register_ability( $ability['id'], $ability['args'] );
				// Fallback for the `wp_abilities_init` alias used in the
				// WP Monitor spec: `wp_register_ability()` enforces
				// `doing_action('wp_abilities_api_init')` and returns null
				// with `_doing_it_wrong` when invoked on the alias hook.
				// In that case register directly via the registry to honour
				// the spec without breaking core's enforcement.
				if ( null === $result && ! wp_has_ability( $ability['id'] ) && class_exists( 'WP_Abilities_Registry' ) ) {
					$registry = \WP_Abilities_Registry::get_instance();
					if ( null !== $registry ) {
						$registry->register( $ability['id'], $ability['args'] );
					}
				}
			}
		}

		/**
		 * Execute callback: Regenerate Critical CSS (wppo/regenerate-ccss).
		 *
		 * Delegates to `Critical_CSS::regenerate_all()` — same path as the
		 * REST handler `Rest::regenerate_ccss()`.
		 *
		 * @since NEXT
		 *
		 * @param array $input Unused input data.
		 * @return array{queued: int}
		 */
		public static function execute_regenerate_ccss( array $input = array() ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			$queued = Critical_CSS::regenerate_all();
			return array( 'queued' => (int) $queued );
		}

		/**
		 * Execute callback: Regenerate Used CSS (wppo/used-css-regenerate).
		 *
		 * Mirrors `Rest::used_css_regenerate()` but for the Abilities API
		 * input shape (`post_id` optional). Queues a single post job or
		 * regenerates for all pages.
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (post_id).
		 * @return array{queued: int}
		 */
		public static function execute_used_css_regenerate( array $input = array() ): array {
			$post_id = isset( $input['post_id'] ) ? absint( $input['post_id'] ) : 0;

			if ( $post_id > 0 && function_exists( 'as_enqueue_async_action' ) ) {
				if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'wppo_used_css_generate', array( 'post_id' => $post_id ), 'performance_optimisation' ) ) {
					as_enqueue_async_action(
						'wppo_used_css_generate',
						array( 'post_id' => $post_id ),
						'performance_optimisation'
					);
				}
				return array( 'queued' => 1 );
			}

			$used_css = new Used_CSS();
			$queued   = $used_css->regenerate_all();
			return array( 'queued' => (int) $queued );
		}

		/**
		 * Execute callback: Run Crawler (wppo/crawler).
		 *
		 * Delegates to `LiteSpeed_Crawler::crawl_batch()` when available,
		 * otherwise returns an error payload. Mirrors `Rest::handle_crawler()`
		 * without IP rate-limiting (ability is `manage_options` gated).
		 *
		 * @since NEXT
		 *
		 * @param array $input Input data (url, urls).
		 * @return array Crawler result or error.
		 */
		public static function execute_crawler( array $input = array() ): array {
			$urls = array();

			if ( ! empty( $input['url'] ) ) {
				$urls[] = esc_url_raw( $input['url'] );
			} elseif ( ! empty( $input['urls'] ) && is_array( $input['urls'] ) ) {
				$urls = array_values( array_filter( array_map( 'esc_url_raw', $input['urls'] ) ) );
			} elseif ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
				$urls = LiteSpeed_Crawler::get_urls_to_crawl( 20 );
			}

			if ( empty( $urls ) ) {
				return array( 'error' => __( 'No URLs to crawl.', 'performance-optimisation' ) );
			}

			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Crawler' ) ) {
				$result = LiteSpeed_Crawler::crawl_batch( $urls );
				return is_array( $result ) ? $result : array( 'result' => $result );
			}

			return array( 'error' => __( 'Crawler not available.', 'performance-optimisation' ) );
		}
	}
}
