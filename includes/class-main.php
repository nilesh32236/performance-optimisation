<?php
/**
 * Performance Optimisation main functionality.
 *
 * This file includes the main class for the performance optimisation plugin,
 * which handles tasks like including necessary files, setting up hooks, and managing
 * image optimisation, JS and CSS minification, and more.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
	/**
	 * Main Class for Performance Optimisation.
	 *
	 * Handles the inclusion of necessary files, setup of hooks, and core functionalities
	 * such as generating and invalidating dynamic static HTML.
	 *
	 * @since 1.0.0
	 */
	class Main {

		/**
		 * List of CSS handles to exclude from combining.
		 *
		 * @var   array
		 * @since 1.0.0
		 */
		private array $exclude_css = array( 'wppo-combine-css' );

		/**
		 * List of JavaScript handles to exclude from minification.
		 *
		 * @var   array
		 * @since 1.0.0
		 */
		private array $exclude_js = array(
			'jquery',
		);

		/**
		 * List of JavaScript handles/URLs to exclude from deferring.
		 *
		 * @var   array
		 * @since 1.1.1
		 */
		private array $exclude_defer_js = array();

		/**
		 * List of JavaScript handles/URLs to exclude from delaying.
		 *
		 * @var   array
		 * @since 1.1.1
		 */
		private array $exclude_delay_js = array();

		/**
		 * Default delay strategy: 'interaction', 'idle', or 'viewport'.
		 *
		 * @var   string
		 * @since 3.8.0
		 */
		private string $delay_js_default_strategy = 'interaction';

		/**
		 * List of script handles/URLs to load via requestIdleCallback.
		 *
		 * @var   array
		 * @since 3.8.0
		 */
		private array $delay_js_idle_list = array();

		/**
		 * List of script handles/URLs to load when in viewport.
		 *
		 * @var   array
		 * @since 3.8.0
		 */
		private array $delay_js_viewport_list = array();

		/**
		 * Map of script handles/URLs to priority ('high', 'normal', 'low').
		 *
		 * @var   array<string, string>
		 * @since 3.8.0
		 */
		private array $delay_js_priority = array();

		/**
		 * Idle callback timeout in milliseconds (default 3000).
		 *
		 * @var   int
		 * @since 3.8.0
		 */
		private int $delay_js_idle_timeout = 3000;

		/**
		 * Associative array of deferred script handles (keyed by handle for O(1) lookups).
		 *
		 * @var   array<string, bool>
		 * @since 2.4.0
		 */
		private array $deferred_handles = array();

		/**
		 * Cache instance for static HTML cache operations.
		 *
		 * @var   Cache|null
		 * @since 2.0.0
		 */
		private $cache;

		/**
		 * Filesystem instance for file operations.
		 *
		 * @var   object
		 * @since 1.0.0
		 */
		private $filesystem;

		/**
		 * Image Optimisation instance for handling image optimization.
		 *
		 * @var   Image_Optimisation
		 * @since 1.0.0
		 */
		private Image_Optimisation $image_optimisation;

		/**
		 * Google_Fonts instance for hosting Google Fonts locally.
		 *
		 * @var   Google_Fonts
		 * @since 2.7.0
		 */
		private Google_Fonts $google_fonts;

		/**
		 * Options for performance optimisation settings.
		 *
		 * @var   array
		 * @since 1.0.0
		 */
		private $options;

		/**
		 * Constructor.
		 *
		 * Initializes the class by including necessary files and setting up hooks.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$this->options = get_option(
				'wppo_settings',
				array(
					'cache_settings'     => array(
						'enableLoggedInCache' => false,
						'loggedInCacheRoles'  => array(),
					),
					'file_optimisation'  => array(
						'enableServerRules'      => false,
						'cdnURL'                 => '',
						'removeUnusedCSS'        => false,
						'excludeUnusedCSS'       => '',
						'criticalCSS'            => false,
						'hostGoogleFontsLocally' => false,
						'blockAssetsOnDemand'    => false,
						'delayJSDefaultStrategy' => 'interaction',
						'delayJSIdleList'        => '',
						'delayJSViewportList'    => '',
						'delayJSPriority'        => '',
						'delayJSIdleTimeout'     => 3000,
					),
					'preload_settings'   => array(
						'enableSpeculationRules' => false,
						'speculationMode'        => 'prerender',
						'speculationEagerness'   => 'moderate',
					),
					'image_optimisation' => array(
						'placeholderType' => 'svg',
					),
					'performance_audit'  => array(
						'pagespeed_api_key' => '',
						'high_value_urls'   => array(),
						'auto_fix_enabled'  => false,
					),
				)
			);

			$this->includes();
			$this->image_optimisation = new Image_Optimisation( $this->options );
			$this->google_fonts       = new Google_Fonts( $this->options );
			$this->setup_hooks();
			$this->filesystem = Util::init_filesystem();
			if ( ! $this->filesystem ) {
				$this->filesystem = null;
			}

			if ( defined( 'WP_ADMIN' ) ) {
				new Admin_Notices();
			}

			$file_optimisation_opts = $this->options['file_optimisation'] ?? array();
			new Core_Tweaks( $file_optimisation_opts );
		}

		/**
		 * Whether front-end optimisations (lazy load, defer, delay, minify, used CSS)
		 * should be applied for the current logged-in user.
		 *
		 * When enableLoggedInCache is on, optimisations run even for logged-in users
		 * so the cached page includes all improvements.
		 *
		 * @since 2.8.0
		 * @return bool True if optimisations may run for the current user.
		 */
		private function should_optimise_for_logged_in(): bool {
			return Util::is_cache_eligible_for_current_user(
				$this->options['cache_settings'] ?? array()
			);
		}

		/**
		 * Get editable role names keyed by slug for the JS role selector.
		 *
		 * @since 2.8.0
		 * @return array<string, string>
		 */
		private function get_editable_role_names(): array {
			if ( ! function_exists( 'get_editable_roles' ) ) {
				return array();
			}
			$roles = get_editable_roles();
			$names = array();
			foreach ( $roles as $slug => $role ) {
				$names[ $slug ] = $role['name'] ?? $slug;
			}
			return $names;
		}

		/**
		 * Set a wppo_role_hash cookie for the current logged-in user so the
		 * advanced-cache.php drop-in can serve role-specific cache variants.
		 *
		 * @since 2.8.0
		 * @return void
		 */
		public function set_role_hash_cookie(): void {
			if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( is_user_logged_in() ) {
				$enable = ! empty( $this->options['cache_settings']['enableLoggedInCache'] ?? false );
				if ( $enable ) {
					$user = wp_get_current_user();
					$hash = Util::get_role_hash( $user );
					if ( '' !== $hash && ( ! isset( $_COOKIE['wppo_role_hash'] ) || $_COOKIE['wppo_role_hash'] !== $hash ) ) {
						setcookie( 'wppo_role_hash', $hash, time() + DAY_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
					}
				}
			}
		}

		/**
		 * Clear the wppo_role_hash cookie on logout.
		 *
		 * @since 2.8.0
		 * @return void
		 */
		public function clear_role_hash_cookie(): void {
			if ( isset( $_COOKIE['wppo_role_hash'] ) ) {
				setcookie( 'wppo_role_hash', '', time() - YEAR_IN_SECONDS, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			}
		}

		/**
		 * Include required files.
		 *
		 * Loads the autoloader and includes other class files needed for the plugin.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function includes(): void {
			require_once WPPO_PLUGIN_PATH . 'vendor/autoload.php';
			if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
			}
			require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-util.php';
			require_once WPPO_PLUGIN_PATH . 'includes/minify/class-html.php';
			require_once WPPO_PLUGIN_PATH . 'includes/minify/class-css.php';
			require_once WPPO_PLUGIN_PATH . 'includes/minify/class-js.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-metabox.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-image-optimisation.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-img-converter.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-cron.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-rest.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-database-cleanup.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-asset-manager.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-htaccess-handler.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-server-rules.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-core-tweaks.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-object-cache.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-used-css.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-critical-css.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-abilities.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-google-fonts.php';

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-wppo-cli-command.php';
				\WP_CLI::add_command( 'wppo', 'PerformanceOptimise\Inc\WPPO_CLI_Command' );
			}

			// Phase 1 & 2 — Diagnostics & PageSpeed (v1.5.0-1.6.0).
			// Load on admin, AJAX, Cron, or REST API requests.
			if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-telemetry.php';
				require_once WPPO_PLUGIN_PATH . 'includes/class-system-info.php';
				require_once WPPO_PLUGIN_PATH . 'includes/class-pagespeed.php';
				require_once WPPO_PLUGIN_PATH . 'includes/class-suggestion-engine.php';

				if ( defined( 'WP_ADMIN' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/class-admin-notices.php';
				}
			}
		}

		/**
		 * Setup WordPress hooks.
		 *
		 * Registers actions and filters used by the plugin.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function setup_hooks(): void {
			add_action( 'admin_menu', array( $this, 'init_menu' ) );
			add_action( 'admin_init', array( $this, 'maybe_fix_wp_cache' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'init', array( $this, 'set_role_hash_cookie' ) );
			add_action( 'wp_logout', array( $this, 'clear_role_hash_cookie' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'add_defer_strategy' ), 1000 );
			$has_delay_js = ! empty( $this->options['file_optimisation']['delayJS'] );
			$has_defer_js = ! empty( $this->options['file_optimisation']['deferJS'] );
			if ( $has_delay_js || ( $has_defer_js && ! function_exists( 'wp_script_add_data' ) ) ) {
				add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 2 );
			}
			if ( $has_defer_js ) {
				add_filter( 'script_loader_tag', array( $this, 'add_fetchpriority_to_deferred' ), 11, 2 );
			}
			if ( $has_delay_js ) {
				add_action( 'wp', array( $this, 'apply_per_page_delay_config' ) );
			}
			add_action( 'admin_bar_menu', array( $this, 'add_setting_to_admin_bar' ), 100 );

			if ( ! empty( $this->options['file_optimisation']['removeWooCSSJS'] ) ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'remove_woocommerce_scripts' ), 999 );
			}

			if ( ! empty( $this->options['cache_settings']['enableCache'] ) ) {
				$this->cache = new Cache( $this->options );
				$this->cache->set_image_optimisation( $this->image_optimisation );
				$this->cache->set_google_fonts( $this->google_fonts );
				if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
					// WP 6.9+ template enhancement output buffer.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this->cache, 'process_buffer_for_cache' ), 10, 2 );
					add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this->cache, 'stash_cache' ) );
				} else {
					// Legacy path (deprecated) — earmarked for removal when WP 6.9+ becomes the minimum supported version.
					add_action( 'template_redirect', array( $this->cache, 'start_output_buffer' ) );
				}
				add_action( 'save_post', array( $this, 'on_save_post_invalidate_cache' ), 10, 3 );
			}

			// Standalone used-CSS output buffer when page cache is disabled.
			if ( empty( $this->options['cache_settings']['enableCache'] ) && ! empty( $this->options['file_optimisation']['removeUnusedCSS'] ) ) {
				if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
					// WP 6.9+ template enhancement output buffer.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'process_used_css_only' ), 20, 2 );
				} else {
					add_action( 'template_redirect', array( $this, 'start_used_css_buffer' ) );
				}
			}

			// Invalidate DB cleanup counts when posts are added or removed (for public post types).
			if ( is_admin() ) {
				add_action( 'save_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10, 2 );
				add_action( 'deleted_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10, 2 );
			}
			if ( ! empty( $this->options['file_optimisation']['combineCSS'] ) ) {
				if ( ! $this->cache ) {
					$this->cache = new Cache( $this->options );
					$this->cache->set_image_optimisation( $this->image_optimisation );
					$this->cache->set_google_fonts( $this->google_fonts );
				}
				add_action( 'wp_enqueue_scripts', array( $this->cache, 'combine_css' ), PHP_INT_MAX );
			}

			$rest = new Rest();
			add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

			if ( ! empty( $this->options['file_optimisation']['minifyJS'] ) ) {
				if ( ! empty( $this->options['file_optimisation']['excludeJS'] ) ) {
					$exclude_js = Util::process_urls( $this->options['file_optimisation']['excludeJS'] );

					$this->exclude_js = array_merge( $this->exclude_js, (array) $exclude_js );
				}

				add_filter( 'script_loader_tag', array( $this, 'minify_js' ), 10, 3 );
			}

			if ( ! empty( $this->options['file_optimisation']['minifyCSS'] ) ) {
				if ( ! empty( $this->options['file_optimisation']['excludeCSS'] ) ) {
					$exclude_css       = Util::process_urls( $this->options['file_optimisation']['excludeCSS'] );
					$this->exclude_css = array_merge( $this->exclude_css, (array) $exclude_css );
				}

				add_filter( 'style_loader_tag', array( $this, 'minify_css' ), 10, 3 );
			}

			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ) ) {
				add_filter( 'style_loader_tag', array( $this->google_fonts, 'process_style_tag' ), 9, 3 );
			}

			if ( ! empty( $this->options['file_optimisation']['blockAssetsOnDemand'] ) && has_filter( 'should_load_block_assets_on_demand' ) ) {
				add_filter( 'should_load_block_assets_on_demand', '__return_true' );
			}

			if ( ! empty( $this->options['file_optimisation']['deferJS'] ) ) {
				$exclude_js = array( 'wppo-lazyload' );
				if ( ! empty( $this->options['file_optimisation']['excludeDeferJS'] ) ) {
					$exclude_defer          = Util::process_urls( $this->options['file_optimisation']['excludeDeferJS'] );
					$this->exclude_defer_js = array_merge( $exclude_js, (array) $exclude_defer );
				} else {
					$this->exclude_defer_js = $exclude_js;
				}
				$this->exclude_defer_js = apply_filters( 'wppo_exclude_defer_js', $this->exclude_defer_js );
			}

			if ( ! empty( $this->options['file_optimisation']['delayJS'] ) ) {
				$exclude_js = array( 'wppo-lazyload' );
				if ( ! empty( $this->options['file_optimisation']['excludeDelayJS'] ) ) {
					$exclude_delay          = Util::process_urls( $this->options['file_optimisation']['excludeDelayJS'] );
					$this->exclude_delay_js = array_merge( $exclude_js, (array) $exclude_delay );
				} else {
					$this->exclude_delay_js = $exclude_js;
				}
				$this->exclude_delay_js = apply_filters( 'wppo_exclude_delay_js', $this->exclude_delay_js );

				// Parse delay strategy lists.
				$file_opt = $this->options['file_optimisation'];

				$this->delay_js_default_strategy = ! empty( $file_opt['delayJSDefaultStrategy'] )
					? sanitize_text_field( $file_opt['delayJSDefaultStrategy'] )
					: 'interaction';

				if ( ! empty( $file_opt['delayJSIdleList'] ) ) {
					$this->delay_js_idle_list = (array) Util::process_urls( $file_opt['delayJSIdleList'] );
				}

				if ( ! empty( $file_opt['delayJSViewportList'] ) ) {
					$this->delay_js_viewport_list = (array) Util::process_urls( $file_opt['delayJSViewportList'] );
				}

				if ( ! empty( $file_opt['delayJSPriority'] ) ) {
					$priority_lines = Util::process_urls( $file_opt['delayJSPriority'] );
					foreach ( $priority_lines as $line ) {
						$parts = explode( ':', $line, 2 );
						if ( count( $parts ) === 2 ) {
							$handle = trim( $parts[0] );
							$level  = strtolower( trim( $parts[1] ) );
							if ( in_array( $level, array( 'high', 'normal', 'low' ), true ) ) {
								$this->delay_js_priority[ $handle ] = $level;
							}
						}
					}
				}

				$this->delay_js_idle_timeout = ! empty( $file_opt['delayJSIdleTimeout'] )
					? absint( $file_opt['delayJSIdleTimeout'] )
					: 3000;
			}

			add_action( 'wp_head', array( $this, 'add_preload_prefetch_preconnect' ), 1 );
			add_action( 'wp_head', array( $this, 'add_speculation_rules' ), 0 );

			new Metabox();
			new Cron();
			new Asset_Manager();
			new Abilities();

			// Critical CSS hooks.
			if ( ! empty( $this->options['file_optimisation']['criticalCSS'] ) ) {
				add_action( 'wp_head', array( 'PerformanceOptimise\Inc\Critical_CSS', 'inline_ccss' ), 0 );
				add_filter( 'style_loader_tag', array( 'PerformanceOptimise\Inc\Critical_CSS', 'defer_stylesheets' ), 10, 3 );
				add_action( 'wppo_generate_ccss', array( 'PerformanceOptimise\Inc\Critical_CSS', 'background_generate' ), 10, 1 );
			}

			// Register Action Scheduler callback for background image processing.
			add_action( 'wppo_convert_image_background', array( $this, 'process_background_image' ), 10, 1 );

			// Phase 2 — Register Action Scheduler callback for background PageSpeed scans.
			add_action( 'wppo_pagespeed_scan', array( 'PerformanceOptimise\Inc\Pagespeed', 'run_scan' ), 10, 1 );

			// Register Action Scheduler callback for background used-CSS generation.
			add_action( 'wppo_used_css_generate', array( 'PerformanceOptimise\Inc\Used_CSS', 'process_background' ), 10, 1 );

			// Queue used-CSS regeneration when post content changes.
			add_action( 'save_post', array( $this, 'on_save_post_queue_used_css' ), 10, 3 );

			// Clear all cache on structural changes that invalidate every cached page.
			add_action( 'update_option_permalink_structure', array( __CLASS__, 'clear_all_cache' ) );
			add_action( 'switch_theme', array( __CLASS__, 'clear_all_cache' ) );
			add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );
			add_action( 'activated_plugin', array( __CLASS__, 'clear_all_cache' ) );
			add_action( 'deactivated_plugin', array( __CLASS__, 'clear_all_cache' ) );

			add_action( 'wp_ajax_wppo_get_nonce', array( $rest, 'ajax_get_nonce' ) );
		}

		/**
		 * Automatically try to fix WP_CACHE if it is missing or disabled.
		 *
		 * Runs on admin_init.
		 *
		 * @return void
		 */
		public function maybe_fix_wp_cache(): void {
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return;
			}

			// Only run this check once per hour to avoid constant I/O.
			if ( get_transient( Util::transient_key( 'wppo_wp_cache_fix_checked' ) ) ) {
				return;
			}

			require_once WPPO_PLUGIN_PATH . 'includes/class-activate.php';
			$notices = Activate::add_wp_cache_constant();

			// Always throttle for 1 hour to avoid constant I/O on failure.
			set_transient( Util::transient_key( 'wppo_wp_cache_fix_checked' ), 1, HOUR_IN_SECONDS );

			if ( ! empty( $notices ) ) {
				// Failure — merge notice keys into existing transient to notify user immediately.
				$existing_notices = get_transient( Util::transient_key( 'wppo_activation_notices' ) );
				$existing_notices = is_array( $existing_notices ) ? $existing_notices : array();
				$new_notices      = array_unique( array_merge( $existing_notices, (array) $notices ) );
				set_transient( Util::transient_key( 'wppo_activation_notices' ), $new_notices, 30 );
			}
		}

		/**
		 * Callback for when plugin settings are updated.
		 *
		 * @param mixed $old_value The old option value.
		 * @param mixed $value     The new option value.
		 * @since 1.2.0
		 */
		public static function on_settings_update( $old_value, $value ) {
			// Only clear cache when tabs that affect HTML output change.
			$cache_relevant_tabs  = array( 'cache_settings', 'file_optimisation', 'image_optimisation', 'preload_settings', 'core_tweaks' );
			$admin_only_tabs      = array( 'database_cleanup', 'object_cache', 'performance_audit' );
			$should_clear         = false;
			$should_runtime_flush = false;

			foreach ( $cache_relevant_tabs as $tab ) {
				$old_tab = isset( $old_value[ $tab ] ) ? $old_value[ $tab ] : null;
				$new_tab = isset( $value[ $tab ] ) ? $value[ $tab ] : null;
				if ( $old_tab !== $new_tab ) {
					$should_clear = true;
					break;
				}
			}

			if ( ! $should_clear ) {
				foreach ( $admin_only_tabs as $tab ) {
					$old_tab = isset( $old_value[ $tab ] ) ? $old_value[ $tab ] : null;
					$new_tab = isset( $value[ $tab ] ) ? $value[ $tab ] : null;
					if ( $old_tab !== $new_tab ) {
						$should_runtime_flush = true;
						break;
					}
				}
			}

			if ( $should_clear ) {
				self::clear_all_cache();
			} elseif ( $should_runtime_flush ) {
				Cache::flush_runtime();
			}

			// Bump image info salt when image settings change.
			$old_img = $old_value['image_optimisation'] ?? array();
			$new_img = $value['image_optimisation'] ?? array();
			if ( $old_img !== $new_img ) {
				Img_Converter::invalidate_img_info_cache();
			}

			// Bump audit salt when performance audit settings change.
			$old_audit = $old_value['performance_audit'] ?? array();
			$new_audit = $value['performance_audit'] ?? array();
			if ( $old_audit !== $new_audit ) {
				Telemetry::invalidate_audit_cache();
			}

			// Handle .htaccess rules update.
			$old_enable = isset( $old_value['file_optimisation']['enableServerRules'] ) ? (bool) $old_value['file_optimisation']['enableServerRules'] : false;
			$new_enable = isset( $value['file_optimisation']['enableServerRules'] ) ? (bool) $value['file_optimisation']['enableServerRules'] : false;

			if ( $old_enable !== $new_enable ) {
				$ok = Htaccess_Handler::update_rules( $new_enable );

				if ( ! $ok ) {
					// Rollback the setting if .htaccess update failed.
					$value['file_optimisation']['enableServerRules'] = $old_enable;

					// Prevent infinite loop by temporary removing the action.
					remove_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10 );
					update_option( 'wppo_settings', $value );
					add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );

					add_action(
						'admin_notices',
						function () {
							echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Performance Optimization: Failed to update .htaccess rules. Please check file permissions.', 'performance-optimisation' ) . '</p></div>';
						}
					);
				}
			}

			// Clear Google Fonts cache when the setting toggles.
			$old_gf = $old_value['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			$new_gf = $value['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			if ( $old_gf !== $new_gf ) {
				Google_Fonts::clear_font_cache();
			}
		}

		/**
		 * Clear the entire plugin cache.
		 *
		 * Called when structural changes (permalink update, theme switch, etc.)
		 * invalidate all cached pages.
		 *
		 * @since 1.1.0
		 */
		public static function clear_all_cache() {
			Cache::clear_cache();
		}

		/**
		 * Process a single image conversion in the background via Action Scheduler.
		 *
		 * @param array $args { source_path, format } for the image to convert.
		 * @since 1.1.0
		 */
		public function process_background_image( $args ) {
			if ( empty( $args['source_path'] ) || empty( $args['format'] ) ) {
				return;
			}

			$options       = get_option( 'wppo_settings', array() );
			$img_converter = new Img_Converter( $options );

			$source_path = wp_normalize_path( $args['source_path'] );
			$format      = sanitize_text_field( $args['format'] );

			if ( file_exists( $source_path ) ) {
				$img_converter->convert_image( $source_path, $format );
			}
		}

		/**
		 * Invalidate cache on save_post, skipping revisions and autosaves.
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post object.
		 * @param bool     $update  Whether this is an existing post being updated.
		 * @since 2.0.0
		 */
		public function on_save_post_invalidate_cache( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found, Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}
			if ( $this->cache ) {
				$this->cache->invalidate_dynamic_static_html( $post_id );
			}
		}

		/**
		 * Queue used-CSS generation when post content is saved.
		 *
		 * Skips revisions and autosaves, and checks the removeUnusedCSS setting
		 * before enqueueing. Uses as_has_scheduled_action() to prevent duplicate jobs.
		 *
		 * @param int      $post_id Post ID.
		 * @param \WP_Post $post    Post object.
		 * @param bool     $update  Whether this is an existing post being updated.
		 * @return void
		 * @since 2.6.0
		 */
		public function on_save_post_queue_used_css( $post_id, $post, $update ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
				return;
			}

			$options = get_option( 'wppo_settings', array() );
			if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
				return;
			}

			if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}

			if ( ! as_has_scheduled_action( 'wppo_used_css_generate', array( 'post_id' => $post_id ), 'performance_optimisation' ) ) {
				as_enqueue_async_action(
					'wppo_used_css_generate',
					array( 'post_id' => $post_id ),
					'performance_optimisation'
				);
			}
		}

		/**
		 * Process used-CSS when cache is disabled.
		 *
		 * @param string $filtered_output The filtered output from previous callbacks.
		 * @param string $output          The raw output buffer content.
		 * @return string The processed output.
		 * @since 2.6.0
		 */
		public function process_used_css_only( $filtered_output, $output ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return $filtered_output;
			}

			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
				$filtered_output = $this->google_fonts->process_buffer( $filtered_output );
			}

			$used_css = new \PerformanceOptimise\Inc\Used_CSS( $this->options );
			return $used_css->process_buffer( $filtered_output );
		}

		/**
		 * Start output buffer for used-CSS (legacy path, WP &lt; 6.9).
		 *
		 * @return void
		 * @since 2.6.0
		 */
		public function start_used_css_buffer() {
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return;
			}
			ob_start( array( $this, 'process_used_css_capture' ) );
		}

		/**
		 * Capture and process buffer for used-CSS.
		 *
		 * @param string $buffer The output buffer content.
		 * @return string The processed buffer.
		 * @since 2.6.0
		 */
		public function process_used_css_capture( $buffer ) {
			if ( empty( $buffer ) ) {
				return $buffer;
			}

			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
				$buffer = $this->google_fonts->process_buffer( $buffer );
			}

			$used_css = new \PerformanceOptimise\Inc\Used_CSS( $this->options );
			return $used_css->process_buffer( $buffer );
		}

		/**
		 * Initialize the admin menu.
		 *
		 * Adds the Performance Optimisation menu to the WordPress admin dashboard.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function init_menu(): void {
			add_menu_page(
				__( 'Performance Optimisation', 'performance-optimisation' ),
				__( 'Performance Optimisation', 'performance-optimisation' ),
				'manage_options',
				'performance-optimisation',
				array( $this, 'admin_page' ),
				'dashicons-admin-post',
				'2.1',
			);
		}

		/**
		 * Display the admin page.
		 *
		 * Includes the admin page template for rendering.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function admin_page(): void {
			require_once WPPO_PLUGIN_PATH . 'templates/app.html';
		}

		/**
		 * Add available post types to options.
		 *
		 * Filters out non-public post types and adds the available post types to options.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function add_available_post_types_to_options() {
			$post_types = get_post_types( array( 'public' => true ), 'names' );

			$excluded            = array( 'attachment' );
			$filtered_post_types = array_keys( array_diff( $post_types, $excluded ) );

			$this->options['image_optimisation']['availablePostTypes'] = $filtered_post_types;
		}

		/**
		 * Extract the active frontend theme's primary color.
		 *
		 * Checks block theme (theme.json) first, then classic theme (customizer).
		 *
		 * @since  2.0.0
		 * @return array{primary?: string, secondary?: string, text?: string}
		 */
		private function get_frontend_theme_colors(): array {
			$colors = array(
				'primary'   => '',
				'secondary' => '',
				'text'      => '',
			);

			// Strategy 1: Block theme — read from theme.json (WP 5.8+).
			if ( function_exists( 'wp_get_global_settings' ) ) {
				$settings = wp_get_global_settings();
				$palette  = $settings['color']['palette']['theme'] ?? array();

				foreach ( $palette as $entry ) {
					$slug = sanitize_title( $entry['slug'] ?? '' );
					$hex  = sanitize_hex_color( $entry['color'] ?? '' );

					if ( ! $hex ) {
						continue;
					}

					if ( in_array( $slug, array( 'primary', 'brand', 'accent' ), true ) ) {
						$colors['primary'] = $hex;
					} elseif ( in_array( $slug, array( 'secondary', 'secondary-brand' ), true ) ) {
						$colors['secondary'] = $hex;
					} elseif ( in_array( $slug, array( 'foreground', 'contrast', 'body-text' ), true ) ) {
						$colors['text'] = $hex;
					}
				}
			}

			// Strategy 2: Classic theme — check Customizer settings.
			if ( empty( $colors['primary'] ) ) {
				$primary = get_theme_mod( 'primary_color', '' );
				if ( empty( $primary ) ) {
					$primary = get_theme_mod( 'accent_color', '' );
				}
				if ( ! empty( $primary ) ) {
					$colors['primary'] = sanitize_hex_color( $primary );
				}
			}

			// Strategy 3: Extract from the theme's header_textcolor.
			if ( empty( $colors['text'] ) ) {
				$header_text_color = get_header_textcolor();
				if ( 'blank' !== $header_text_color && ! empty( $header_text_color ) ) {
					$colors['text'] = '#' . ltrim( sanitize_hex_color_no_hash( $header_text_color ), '#' );
				}
			}

			return array_filter( $colors );
		}

		/**
		 * Enqueue admin scripts and styles.
		 *
		 * Loads CSS and JavaScript files for the admin dashboard page.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		public function admin_enqueue_scripts(): void {
			$screen = get_current_screen();

			if ( 'toplevel_page_performance-optimisation' !== $screen->base ) {
				return;
			}

			$this->enqueue_admin_bar_script();

			$asset_file = WPPO_PLUGIN_PATH . 'build/index.asset.php';
			$resolved   = wp_normalize_path( realpath( $asset_file ) );

			// Validate the resolved path is within the plugin directory before including.
			if ( false !== $resolved && strpos( $resolved, WPPO_PLUGIN_PATH ) === 0 ) {
				$asset_data = require $resolved;
			} else {
				$asset_data = array(
					'dependencies' => array(),
					'version'      => false,
				);
			}

			wp_enqueue_style( 'performance-optimisation-style', WPPO_PLUGIN_URL . 'build/style-index.css', array(), $asset_data['version'], 'all' );
			wp_enqueue_script( 'performance-optimisation-script', WPPO_PLUGIN_URL . 'build/index.js', $asset_data['dependencies'], $asset_data['version'], true );

			$this->add_available_post_types_to_options();

			$cache_salt_key = 'wppo_cache_last_cleared';

			if ( function_exists( 'wp_cache_get_salted' ) ) {
				$cache_size = wp_cache_get_salted( 'wppo_cache_size', 'wppo', $cache_salt_key );
				if ( false === $cache_size ) {
					$cache_size = Cache::get_cache_size();
					wp_cache_set_salted( 'wppo_cache_size', $cache_size, 'wppo', $cache_salt_key );
				}
			} else {
				$cache_size = get_transient( Util::transient_key( 'wppo_cache_size' ) );
				if ( false === $cache_size ) {
					$cache_size = Cache::get_cache_size();
					set_transient( Util::transient_key( 'wppo_cache_size' ), $cache_size, 15 * MINUTE_IN_SECONDS );
				}
			}

			if ( function_exists( 'wp_cache_get_salted' ) ) {
				$total_js_css = wp_cache_get_salted( 'wppo_total_js_css', 'wppo', $cache_salt_key );
				if ( false === $total_js_css ) {
					$total_js_css = Util::get_js_css_minified_file();
					wp_cache_set_salted( 'wppo_total_js_css', $total_js_css, 'wppo', $cache_salt_key );
				}
			} else {
				$total_js_css = get_transient( Util::transient_key( 'wppo_total_js_css' ) );
				if ( false === $total_js_css ) {
					$total_js_css = Util::get_js_css_minified_file();
					set_transient( Util::transient_key( 'wppo_total_js_css' ), $total_js_css, 15 * MINUTE_IN_SECONDS );
				}
			}

			// Clone options and redact sensitive keys before exposing to the client.
			$safe_options = $this->options;
			if ( isset( $safe_options['performance_audit']['pagespeed_api_key'] ) ) {
				unset( $safe_options['performance_audit']['pagespeed_api_key'] );
			}
			if ( isset( $safe_options['object_cache']['password'] ) ) {
				unset( $safe_options['object_cache']['password'] );
			}

			wp_localize_script(
				'performance-optimisation-script',
				'wppoSettings',
				array(
					'apiUrl'            => get_rest_url( null, 'performance-optimisation/v1/' ),
					'ajaxUrl'           => admin_url( 'admin-ajax.php' ),
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'nonce_refresh'     => wp_create_nonce( 'wppo_nonce_refresh' ),
					'version'           => WPPO_VERSION,
					'settings'          => $safe_options,
					'show_welcome'      => ! (bool) get_user_meta( get_current_user_id(), 'wppo_welcome_dismissed', true ),
					'image_info'        => $this->sanitize_image_info_for_client( get_option( 'wppo_img_info', array() ) ),
					'cache_size'        => $cache_size,
					'total_js_css'      => $total_js_css,
					'performance_audit' => array(
						'homeUrl'                   => home_url( '/' ),
						'pagespeedApiKeyConfigured' => ! empty( $this->options['performance_audit']['pagespeed_api_key'] ),
						'highValueUrls'             => $this->options['performance_audit']['high_value_urls'] ?? array(), // Phase 3 will populate this.
						'autoFixEnabled'            => (bool) ( $this->options['performance_audit']['auto_fix_enabled'] ?? false ),
					),
					// Frontend theme colors for accent syncing.
					'themeColors'       => $this->get_frontend_theme_colors(),
					// Editable user roles for the logged-in cache role selector.
					'userRoles'         => $this->get_editable_role_names(),
				),
			);

			wp_set_script_translations( 'performance-optimisation-script', 'performance-optimisation' );
		}

		/**
		 * Enqueues scripts for performance optimization.
		 *
		 * @since 1.0.0
		 */
		public function enqueue_scripts() {
			if ( is_admin_bar_showing() ) {
				$this->enqueue_admin_bar_script();
			}

			if ( $this->should_optimise_for_logged_in() ) {
				$lazy_load_images         = isset( $this->options['image_optimisation']['lazyLoadImages'] ) && (bool) $this->options['image_optimisation']['lazyLoadImages'];
				$lazy_load_videos         = isset( $this->options['image_optimisation']['lazyLoadVideos'] ) && (bool) $this->options['image_optimisation']['lazyLoadVideos'];
				$enable_video_placeholder = ! empty( $this->options['image_optimisation']['enableVideoPlaceholder'] ) && $lazy_load_videos;
				$delay_js                 = isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'];
				$use_native_lazy          = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

				// When native lazy loading is active, images use native loading="lazy" but iframes may still need JS restoration.
				$needs_script = ( ! $use_native_lazy && $lazy_load_images ) || $lazy_load_videos || $enable_video_placeholder || $delay_js;

				if ( $needs_script ) {
					$lazy_args = array(
						'in_footer'     => true,
						'fetchpriority' => 'low',
					);
					wp_enqueue_script( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, $lazy_args );

					if ( $use_native_lazy ) {
						wp_add_inline_script( 'wppo-lazyload', 'window.wppoNativeLazy=true;', 'before' );
					}

					if ( $delay_js ) {
						$idle_timeout     = ! empty( $this->options['file_optimisation']['delayJSIdleTimeout'] )
							? absint( $this->options['file_optimisation']['delayJSIdleTimeout'] )
							: 3000;
						$default_strategy = ! empty( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
							? sanitize_text_field( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
							: 'interaction';
						$delay_config     = wp_json_encode(
							array(
								'idleTimeout'     => $idle_timeout,
								'defaultStrategy' => $default_strategy,
							)
						);
						wp_add_inline_script( 'wppo-lazyload', 'window.wppoDelayConfig=' . $delay_config . ';', 'before' );
					}
				}
			}
		}

		/**
		 * Dequeues configured WooCommerce CSS and JS handles unless the current URL is excluded.
		 *
		 * Reads `file_optimisation.excludeUrlToKeepJSCSS` and, if the current front-end URL matches any entry
		 * (exact match or prefix match when an entry contains the `(.*)` suffix), preserves scripts/styles.
		 * Otherwise reads `file_optimisation.removeCssJsHandle` and dequeues each entry prefixed with
		 * `style:` (dequeues a style handle) or `script:` (dequeues a script handle).
		 *
		 * @since 1.0.0
		 */
		public function remove_woocommerce_scripts() {
			if ( empty( $this->options['file_optimisation']['removeCssJsHandle'] ) ) {
				return;
			}

			$exclude_url_to_keep_js_css = array();
			if ( ! empty( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
				$exclude_url_to_keep_js_css = Util::process_urls( $this->options['file_optimisation']['excludeUrlToKeepJSCSS'] );
			}

			if ( ! empty( $exclude_url_to_keep_js_css ) ) {
				// Safely retrieve and sanitize the current URL.
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$parsed_uri  = str_replace( wp_parse_url( home_url(), PHP_URL_PATH ) ?? '', '', $request_uri );
				$current_url = home_url( sanitize_text_field( $parsed_uri ) );

				foreach ( $exclude_url_to_keep_js_css as $exclude_url ) {
					if ( 0 !== strpos( $exclude_url, 'http' ) ) {
						$exclude_url = home_url( $exclude_url );
					}

					if ( false !== strpos( $exclude_url, '(.*)' ) ) {
						$exclude_prefix = str_replace( '(.*)', '', $exclude_url );

						if ( 0 === strpos( untrailingslashit( $current_url ), untrailingslashit( $exclude_prefix ) ) ) {
							return;
						}
					}

					if ( untrailingslashit( $current_url ) === untrailingslashit( $exclude_url ) ) {
						return;
					}
				}
			}

			$remove_css_js_handle = Util::process_urls( $this->options['file_optimisation']['removeCssJsHandle'] );

			foreach ( $remove_css_js_handle as $handle ) {
				if ( 0 === strpos( $handle, 'style:' ) ) {
					$handle = str_replace( 'style:', '', $handle );
					$handle = trim( $handle );

					wp_dequeue_style( $handle );
				} elseif ( 0 === strpos( $handle, 'script:' ) ) {
					$handle = str_replace( 'script:', '', $handle );
					$handle = trim( $handle );

					wp_dequeue_script( $handle );
				}
			}
		}

		/**
		 * Adds custom settings to the WordPress admin bar.
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar The WordPress admin bar object used to add nodes and settings.
		 *
		 * @since 1.0.0
		 */
		public function add_setting_to_admin_bar( $wp_admin_bar ) {
			$wp_admin_bar->add_node(
				array(
					'id'    => 'wppo_setting',
					'title' => __( 'Performance Optimisation', 'performance-optimisation' ),
					'href'  => admin_url( 'admin.php?page=performance-optimisation' ),
					'meta'  => array(
						'class' => 'performance-optimisation-setting',
						'title' => __( 'Go to Performance Optimisation Setting', 'performance-optimisation' ),
					),
				),
			);

			// Add a submenu under the custom setting.
			$wp_admin_bar->add_node(
				array(
					'id'     => 'wppo_clear_all',
					'parent' => 'wppo_setting',
					'title'  => __( 'Clear All Cache', 'performance-optimisation' ),
					'href'   => '#',
				)
			);

			if ( ! is_admin() ) {
				$current_id = get_the_ID();

				$wp_admin_bar->add_node(
					array(
						'id'     => 'wppo_clear_this_page',
						'parent' => 'wppo_setting',
						'title'  => __( 'Clear This Page Cache', 'performance-optimisation' ),
						'href'   => '#', // You can replace with actual URL or function if needed.
						'meta'   => array(
							'title' => __( 'Clear cache for this specific page or post', 'performance-optimisation' ),
							'class' => 'page-' . $current_id,
						),
					)
				);
			}
		}

		/**
		 * Applies defer strategy to non-logged-in users' scripts using wp_script_add_data.
		 *
		 * @since 2.1.0
		 *
		 * @return void
		 */
		public function add_defer_strategy(): void {
			if ( ! $this->should_optimise_for_logged_in() ) {
				return;
			}

			if ( empty( $this->options['file_optimisation']['deferJS'] ) ) {
				return;
			}

			global $wp_scripts;
			if ( ! $wp_scripts instanceof \WP_Scripts || empty( $wp_scripts->queue ) ) {
				return;
			}

			foreach ( $wp_scripts->queue as $handle ) {
				if ( ! in_array( $handle, $this->exclude_defer_js, true ) ) {
					wp_script_add_data( $handle, 'strategy', 'defer' );
					$this->deferred_handles[ $handle ] = true;
					wp_script_add_data( $handle, 'fetchpriority', 'low' );
				}
			}
		}

		/**
		 * Adds defer attribute to non-logged-in users' scripts.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @return string Modified script tag with defer attribute.
		 */
		public function add_defer_attribute( $tag, $handle ): string {
			if ( ! $this->should_optimise_for_logged_in() ) {
				return $tag;
			}

			if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {
				if ( ! in_array( $handle, $this->exclude_delay_js, true ) ) {
					$tag = str_replace( '<script ', '<script fetchpriority="low" ', $tag );
					$tag = str_replace( ' src', ' wppo-src', $tag );
					$tag = preg_replace(
						'/type=("|\')text\/javascript("|\')/',
						'type="wppo/javascript" wppo-type="text/javascript"',
						$tag
					) ?? $tag;

					// Determine delay strategy for this handle.
					$strategy = $this->get_delay_strategy_for_handle( $handle );
					if ( 'interaction' !== $strategy ) {
						$tag = str_replace(
							'<script ',
							'<script data-wppo-delay-strategy="' . esc_attr( $strategy ) . '" ',
							$tag
						);
					}

					// Determine priority for this handle.
					$priority = $this->get_delay_priority_for_handle( $handle );
					if ( 'normal' !== $priority ) {
						$tag = str_replace(
							'<script ',
							'<script data-wppo-delay-priority="' . esc_attr( $priority ) . '" ',
							$tag
						);
					}
				}
			}

			return $tag;
		}

		/**
		 * Get the delay strategy for a given script handle.
		 *
		 * Checks idle list, viewport list, and then falls back to default strategy.
		 *
		 * @since 3.8.0
		 *
		 * @param string $handle The script handle.
		 * @return string The strategy: 'interaction', 'idle', or 'viewport'.
		 */
		private function get_delay_strategy_for_handle( string $handle ): string {
			if ( in_array( $handle, $this->delay_js_idle_list, true ) ) {
				return 'idle';
			}
			if ( in_array( $handle, $this->delay_js_viewport_list, true ) ) {
				return 'viewport';
			}
			// Also check via URL pattern matching against the handle text (handles often contain the handle name).
			foreach ( $this->delay_js_idle_list as $pattern ) {
				if ( false !== strpos( $handle, $pattern ) ) {
					return 'idle';
				}
			}
			foreach ( $this->delay_js_viewport_list as $pattern ) {
				if ( false !== strpos( $handle, $pattern ) ) {
					return 'viewport';
				}
			}
			return $this->delay_js_default_strategy;
		}

		/**
		 * Get the delay priority for a given script handle.
		 *
		 * @since 3.8.0
		 *
		 * @param string $handle The script handle.
		 * @return string The priority: 'high', 'normal', or 'low'.
		 */
		private function get_delay_priority_for_handle( string $handle ): string {
			if ( isset( $this->delay_js_priority[ $handle ] ) ) {
				return $this->delay_js_priority[ $handle ];
			}
			// Check partial matches.
			foreach ( $this->delay_js_priority as $pattern => $level ) {
				if ( false !== strpos( $handle, $pattern ) ) {
					return $level;
				}
			}
			return 'normal';
		}

		/**
		 * Apply per-page delay configuration overrides from the Asset Manager metabox.
		 *
		 * Runs at `wp` hook to merge per-page strategy/priority overrides into the
		 * global delay lists before the `script_loader_tag` filter fires.
		 *
		 * @since 3.8.0
		 *
		 * @return void
		 */
		public function apply_per_page_delay_config(): void {
			if ( ! is_singular() ) {
				return;
			}

			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return;
			}

			$delay_strategies = get_post_meta( $post_id, '_wppo_delay_strategies', true );
			$delay_priorities = get_post_meta( $post_id, '_wppo_delay_priorities', true );

			if ( is_array( $delay_strategies ) ) {
				foreach ( $delay_strategies as $handle => $strategy ) {
					if ( ! in_array( $handle, $this->exclude_delay_js, true ) ) {
						if ( 'interaction' === $strategy ) {
							// Remove from other lists to make it interaction.
							$this->delay_js_idle_list     = array_diff( $this->delay_js_idle_list, array( $handle ) );
							$this->delay_js_viewport_list = array_diff( $this->delay_js_viewport_list, array( $handle ) );
						} elseif ( 'idle' === $strategy ) {
							if ( ! in_array( $handle, $this->delay_js_idle_list, true ) ) {
								$this->delay_js_idle_list[] = $handle;
							}
							$this->delay_js_viewport_list = array_diff( $this->delay_js_viewport_list, array( $handle ) );
						} elseif ( 'viewport' === $strategy ) {
							if ( ! in_array( $handle, $this->delay_js_viewport_list, true ) ) {
								$this->delay_js_viewport_list[] = $handle;
							}
							$this->delay_js_idle_list = array_diff( $this->delay_js_idle_list, array( $handle ) );
						}
					}
				}
			}

			if ( is_array( $delay_priorities ) ) {
				foreach ( $delay_priorities as $handle => $priority ) {
					if ( in_array( $priority, array( 'high', 'normal', 'low' ), true ) ) {
						$this->delay_js_priority[ $handle ] = $priority;
					}
				}
			}
		}

		/**
		 * Adds fetchpriority="low" to rendered script tags for deferred handles.
		 *
		 * @since 2.4.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @return string Modified script tag with fetchpriority="low".
		 */
		public function add_fetchpriority_to_deferred( $tag, $handle ): string {
			if ( isset( $this->deferred_handles[ $handle ] ) && false === strpos( $tag, 'fetchpriority=' ) ) {
				$tag = str_replace( '<script ', '<script fetchpriority="low" ', $tag );
			}
			return $tag;
		}

		/**
		 * Adds preload, prefetch, and preconnect links to optimize resource loading.
		 *
		 * @since 1.0.0
		 */
		public function add_preload_prefetch_preconnect() {

			$preload_settings = $this->options['preload_settings'] ?? array();

			// Preconnect origins.
			if ( ! empty( $preload_settings['preconnect'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
				$preconnect_origins = Util::process_urls( $preload_settings['preconnectOrigins'] );

				foreach ( $preconnect_origins as $origin ) {
					Util::generate_preload_link( $origin, 'preconnect', '', true );
				}
			}

			// Prefetch DNS origins.
			if ( ! empty( $preload_settings['prefetchDNS'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
				$dns_prefetch_origins = Util::process_urls( $preload_settings['dnsPrefetchOrigins'] );

				foreach ( $dns_prefetch_origins as $origin ) {
					Util::generate_preload_link( $origin, 'dns-prefetch' );
				}
			}

			// Preload fonts.
			if ( ! empty( $preload_settings['preloadFonts'] ) && ! empty( $preload_settings['preloadFontsUrls'] ) ) {
				$preload_fonts_urls = Util::process_urls( $preload_settings['preloadFontsUrls'] );

				foreach ( $preload_fonts_urls as $font_url ) {
					$font_url       = preg_match( '/^https?:\/\//i', $font_url ) ? $font_url : content_url( $font_url );
					$font_extension = pathinfo( wp_parse_url( $font_url, PHP_URL_PATH ), PATHINFO_EXTENSION );
					$font_type      = '';

					switch ( strtolower( $font_extension ) ) {
						case 'woff2':
							$font_type = 'font/woff2';
							break;
						case 'woff':
							$font_type = 'font/woff';
							break;
						case 'ttf':
							$font_type = 'font/ttf';
							break;
					}

					Util::generate_preload_link( $font_url, 'preload', 'font', true, $font_type );
				}
			}

			// Preload CSS.
			if ( ! empty( $preload_settings['preloadCSS'] ) && ! empty( $preload_settings['preloadCSSUrls'] ) ) {
				$preload_css_urls = Util::process_urls( $preload_settings['preloadCSSUrls'] );

				foreach ( $preload_css_urls as $css_url ) {
					$css_url = preg_match( '/^https?:\/\//i', $css_url ) ? $css_url : content_url( $css_url );
					Util::generate_preload_link( $css_url, 'preload', 'style' );
				}
			}

			$this->image_optimisation->preload_images();
		}

		/**
		 * Adds speculation rules for prefetching/prerendering via the WP 6.8+ Speculation Rules API.
		 *
		 * Enhances eagerness when static cache is active and excludes sensitive/dynamic paths
		 * (login, admin, REST API) from all speculation.
		 *
		 * @since 2.2.0
		 *
		 * @return void
		 */
		public function add_speculation_rules() {
			if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
				return;
			}

			$preload_settings   = $this->options['preload_settings'] ?? array();
			$enable_speculation = ! empty( $preload_settings['enableSpeculationRules'] );

			add_filter(
				'wp_speculation_rules_href_exclude_paths',
				function ( $exclude_paths ) {
					$exclude_paths[] = '/wp-login.php';
					$exclude_paths[] = '/wp-admin/*';
					$exclude_paths[] = '/wp-json/*';
					return $exclude_paths;
				}
			);

			if ( $enable_speculation ) {
				add_filter(
					'wp_speculation_rules_configuration',
					function ( $config ) use ( $preload_settings ) {
						if ( is_array( $config ) ) {
							$config['mode']      = $preload_settings['speculationMode'] ?? 'prerender';
							$config['eagerness'] = $preload_settings['speculationEagerness'] ?? 'moderate';
						}
						return $config;
					}
				);
			}
		}

		/**
		 * Checks if an asset name (URL or file path) indicates it is already minified.
		 *
		 * @since 1.5.1
		 *
		 * @param  string $url_or_path The asset URL or local file path.
		 * @param  string $ext         The asset extension (css or js).
		 * @return bool True if the asset name indicates it's minified.
		 */
		private function is_minified_asset_name( string $url_or_path, string $ext ): bool {
			if ( empty( $url_or_path ) ) {
				return false;
			}

			$path = wp_parse_url( $url_or_path, PHP_URL_PATH );
			if ( ! is_string( $path ) ) {
				$path = $url_or_path;
			}

			return (bool) preg_match( '/(\.min|\.bundle|-min)\.' . preg_quote( $ext, '/' ) . '$/i', $path );
		}

		/**
		 * Rewrites CSS link tags to use minified versions if they exist.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $tag    The link tag HTML.
		 * @param  string $handle The CSS file's handle.
		 * @param  string $href   The CSS file's source URL.
		 * @return string Modified link tag with minified CSS.
		 */
		public function minify_css( $tag, $handle, $href ) {
			// Early return for logged-in users (when optimisation not enabled), empty URLs, or excluded handles
			// to avoid the expensive Util::get_local_path() computation.
			if ( ! $this->should_optimise_for_logged_in() || empty( $href ) || in_array( $handle, $this->exclude_css, true ) ) {
				return $tag;
			}

			$local_path = Util::get_local_path( $href );
			if ( empty( $local_path ) ) {
				return $tag;
			}

			if ( apply_filters( 'wppo_exclude_minification', false, $local_path, $handle, 'css' ) ) {
				return $tag;
			}

			// Early return if the URL already indicates a minified file.
			if ( $this->is_minified_asset_name( $href, 'css' ) ) {
				return $tag;
			}

			if ( $this->is_css_minified( $local_path ) ) {
				return $tag;
			}

			$css_minifier = new Minify\CSS( $local_path, wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/css' ) );
			$cached_file  = $css_minifier->minify();

			if ( $cached_file ) {
				$file_version = filemtime( Util::get_local_path( $cached_file ) );
				$new_href     = content_url( 'cache/wppo/min/css/' . basename( $cached_file ) ) . '?ver=' . $file_version;
				$new_tag      = str_replace( $href, $new_href, $tag );
				return $new_tag;
			}

			return $tag;
		}

		/**
		 * Rewrites script tags to use minified versions if they exist.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @param  string $src    The script's source URL.
		 * @return string Modified script tag with minified JavaScript.
		 */
		public function minify_js( $tag, $handle, $src ) {
			// Early return for logged-in users (when optimisation not enabled), empty URLs, or excluded handles
			// to avoid the expensive Util::get_local_path() computation.
			if ( ! $this->should_optimise_for_logged_in() || empty( $src ) || in_array( $handle, $this->exclude_js, true ) ) {
				return $tag;
			}

			$local_path = Util::get_local_path( $src );
			if ( empty( $local_path ) ) {
				return $tag;
			}

			if ( apply_filters( 'wppo_exclude_minification', false, $local_path, $handle, 'js' ) ) {
				return $tag;
			}

			// Early return if the URL already indicates a minified file.
			if ( $this->is_minified_asset_name( $src, 'js' ) ) {
				return $tag;
			}

			if ( $this->is_js_minified( $local_path ) ) {
				return $tag;
			}

			$js_minifier = new Minify\JS( $local_path, wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min/js' ) );
			$cached_file = $js_minifier->minify();

			if ( $cached_file ) {
				$file_version = filemtime( Util::get_local_path( $cached_file ) );

				$new_src = content_url( 'cache/wppo/min/js/' . basename( $cached_file ) ) . '?ver=' . $file_version;
				$new_tag = str_replace( $src, $new_src, $tag );
				return $new_tag;
			}

			return $tag;
		}

		/**
		 * Checks if a file is already minified (shared helper for CSS/JS).
		 *
		 * @since 1.5.1
		 *
		 * @param  string $file_path Path to the file.
		 * @param  string $type      Asset type ('css' or 'js').
		 * @return bool True if the file is minified, false otherwise.
		 */
		private function is_file_minified( $file_path, $type ) {
			if ( empty( $file_path ) ) {
				return true;
			}

			if ( $this->is_minified_asset_name( $file_path, $type ) ) {
				return true;
			}

			$cache_key   = 'min_' . $type . '_' . md5( $file_path );
			$cache_group = 'wppo_minify_check';
			$found       = false;
			$cached      = wp_cache_get( $cache_key, $cache_group, false, $found );

			if ( $found ) {
				return (bool) $cached;
			}

			if ( ! file_exists( $file_path ) ) {
				return true;
			}

			$file_size = filesize( $file_path );
			if ( false === $file_size ) {
				return true;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $file_path, 'r' );
			if ( ! $handle ) {
				return true;
			}

			$line_count  = 0;
			$total_chars = 0;
			$max_lines   = 50;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line = fgets( $handle );
			while ( false !== $line ) {
				++$line_count;
				$total_chars += strlen( $line );
				if ( $line_count >= $max_lines ) {
					break;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				$line = fgets( $handle );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );

			$avg_line_length = $total_chars / max( 1, $line_count );
			$threshold       = 'css' === $type ? 500 : 1000;

			$is_minified = $line_count <= 1
				|| ( $line_count <= 3 && $file_size > 1000 )
				|| $avg_line_length > $threshold;

			wp_cache_set( $cache_key, (int) $is_minified, $cache_group, HOUR_IN_SECONDS );

			return $is_minified;
		}

		/**
		 * Checks if a CSS file is already minified.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $file_path Path to the CSS file.
		 * @return bool True if the file is minified, false otherwise.
		 */
		private function is_css_minified( $file_path ) {
			return $this->is_file_minified( $file_path, 'css' );
		}

		/**
		 * Checks if a JavaScript file is already minified.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $file_path Path to the JavaScript file.
		 * @return bool True if the file is minified, false otherwise.
		 */
		private function is_js_minified( $file_path ) {
			return $this->is_file_minified( $file_path, 'js' );
		}

		/**
		 * Sanitizes image info for client exposure — replaces path arrays with counts.
		 *
		 * Prevents filesystem paths from being visible in wppoSettings via View Page Source.
		 *
		 * @since 1.7.0
		 *
		 * @param  array $img_info Raw image info from wppo_img_info option.
		 * @return array Image info with only counts (no file paths).
		 */
		private function sanitize_image_info_for_client( array $img_info ): array {
			$sanitized = array();
			foreach ( array( 'pending', 'completed', 'failed' ) as $bucket ) {
				$bucket_data          = $img_info[ $bucket ] ?? array();
				$sanitized[ $bucket ] = array(
					'webp' => is_array( $bucket_data['webp'] ?? null ) ? count( $bucket_data['webp'] ) : ( $bucket_data['webp'] ?? 0 ),
					'avif' => is_array( $bucket_data['avif'] ?? null ) ? count( $bucket_data['avif'] ) : ( $bucket_data['avif'] ?? 0 ),
				);
			}
			return $sanitized;
		}

		/**
		 * Enqueues the admin bar cache-clearing script and its data.
		 *
		 * Shared between admin and frontend to ensure consistent wppoObject data.
		 *
		 * @since 2.5.0
		 *
		 * @return void
		 */
		private function enqueue_admin_bar_script(): void {
			$asset_file = WPPO_PLUGIN_PATH . 'build/main.asset.php';
			$resolved   = wp_normalize_path( realpath( $asset_file ) );

			if ( false !== $resolved && strpos( $resolved, WPPO_PLUGIN_PATH ) === 0 ) {
				$asset_data = require $resolved;
			} else {
				$asset_data = array(
					'dependencies' => array(),
					'version'      => WPPO_VERSION,
				);
			}

			wp_enqueue_script(
				'wppo-admin-bar-script',
				WPPO_PLUGIN_URL . 'build/main.js',
				$asset_data['dependencies'],
				$asset_data['version'],
				array(
					'in_footer'     => true,
					'fetchpriority' => 'low',
				)
			);

			$data = array(
				'apiUrl'        => get_rest_url( null, 'performance-optimisation/v1' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'nonce'         => wp_create_nonce( 'wp_rest' ),
				'nonce_refresh' => wp_create_nonce( 'wppo_nonce_refresh' ),
			);

			wp_add_inline_script(
				'wppo-admin-bar-script',
				'const wppoObject = ' . wp_json_encode( $data ) . ';',
				'before'
			);
		}
	}
}
