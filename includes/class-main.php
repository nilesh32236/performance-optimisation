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
		 * @since NEXT
		 */
		private string $delay_js_default_strategy = 'interaction';

		/**
		 * List of script handles/URLs to load via requestIdleCallback.
		 *
		 * @var   array
		 * @since NEXT
		 */
		private array $delay_js_idle_list = array();

		/**
		 * List of script handles/URLs to load when in viewport.
		 *
		 * @var   array
		 * @since NEXT
		 */
		private array $delay_js_viewport_list = array();

		/**
		 * Map of script handles/URLs to priority ('high', 'normal', 'low').
		 *
		 * @var   array<string, string>
		 * @since NEXT
		 */
		private array $delay_js_priority = array();

		/**
		 * Idle callback timeout in milliseconds (default 3000).
		 *
		 * @var   int
		 * @since NEXT
		 */
		private int $delay_js_idle_timeout = 3000;

		/**
		 * Associative array of deferred script handles (keyed by handle for O(1) lookups).
		 *
		 * @var   array<string, bool>
		 * @since NEXT
		 */
		private array $deferred_handles = array();

		/**
		 * Cache instance for static HTML cache operations.
		 *
		 * @var   Cache|null
		 * @since NEXT
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
		 * @since NEXT
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
		 * Timestamp (microtime) when the front-end template render started.
		 *
		 * @var   float
		 * @since 1.9.0
		 */
		private float $server_timing_template_start = 0.0;

		/**
		 * Constructor.
		 *
		 * Initializes the class by including necessary files and setting up hooks.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			$defaults      = array(
				'cache_settings'        => array(
					'enableLoggedInCache' => false,
					'loggedInCacheRoles'  => array(),
				),
				'file_optimisation'     => array(
					'enableServerRules'       => false,
					'cdnURL'                  => '',
					'removeUnusedCSS'         => false,
					'excludeUnusedCSS'        => '',
					'criticalCSS'             => false,
					'hostGoogleFontsLocally'  => false,
					// On WP 6.9+ classic themes load core block assets on demand by default, so
					// the toggle defaults to ON there and only acts as an opt-out. Pre-6.9 cores
					// keep the legacy opt-in default (OFF).
					'blockAssetsOnDemand'     => function_exists( 'wp_load_classic_theme_block_styles_on_demand' ),
					'loadAllCoreBlockAssets'  => false,
					'delayJSDefaultStrategy'  => 'interaction',
					'delayJSIdleList'         => '',
					'delayJSViewportList'     => '',
					'delayJSPriority'         => '',
					'delayJSIdleTimeout'      => 3000,
					'minifyHTML'              => false,
					'minifyJS'                => false,
					'minifyCSS'               => false,
					'deferJS'                 => false,
					'delayJS'                 => false,
					'combineCSS'              => false,
					'excludeJS'               => '',
					'excludeCSS'              => '',
					'excludeDeferJS'          => '',
					'excludeDelayJS'          => '',
					'excludeCombineCSS'       => '',
					'minifyInlineCSS'         => false,
					'minifyInlineJS'          => false,
					'removeHTMLComments'      => true,
					'removeQueryStrings'      => false,
					'disableRestApiLinks'     => false,
					'disableRssFeeds'         => false,
					'disableShortlinks'       => false,
					'disableGeneratorTag'     => false,
					'disableJQueryMigrate'    => false,
					'disablePasswordStrength' => false,
					'disableSelfPingbacks'    => false,
				),
				'preload_settings'      => array(
					'enableSpeculationRules' => false,
					'speculationMode'        => 'prefetch',
					'speculationEagerness'   => 'conservative',
					'speculationExcludeUrls' => '',
					'preloadSitemap'         => false,
				),
				'image_optimisation'    => array(
					'lazyLoadImages'             => false,
					'lazyLoadNative'             => true,
					'placeholderType'            => 'svg',
					'autoPreloadLCP'             => false,
					'prioritizeLCPImages'        => false,
					'clientSideMimeTypeOverride' => false,
					'clientSideMimeTypes'        => array(),
					'lazyLoadBackgroundImages'   => false,
				),
				'performance_audit'     => array(
					'pagespeed_api_key'     => '',
					'high_value_urls'       => array(),
					'auto_fix_enabled'      => false,
					'server_timing_enabled' => false,
					'auto_rescan'           => '',
					'rum_enabled'           => false,
				),
				'litespeed_integration' => array(
					'mode'                 => 'auto',
					'enableNextGenRewrite' => false,
					'enableBrotli'         => false,
					'purgeSync'            => true,
					'cacheVaryGroup'       => false,
					'varyGroups'           => array(
						'guest'     => false,
						'mobile'    => false,
						'webp'      => false,
						'commenter' => false,
						'postpass'  => false,
					),
					'crawler'              => array(
						'concurrency'        => 2,
						'loadLimit'          => 0,
						'blacklistThreshold' => 3,
					),
					'esi'                  => array(
						'enabled' => false,
					),
				),
				'llms_txt'              => array(
					'enabled' => false,
					'source'  => 'both',
				),
				'od_integration'        => array(
					'enabled' => class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' ),
				),
				'bfcache'               => array(
					'enabled' => false,
				),
				'perf_translations'     => array(
					'enabled' => false,
				),
				'ai_adaptive'           => array(
					'enabled' => false,
				),
				'edge_cache'            => array(
					'enabled' => false,
				),
			);
			$stored        = Util::get_settings();
			$this->options = ! empty( $stored ) ? $stored : $defaults;

			// WP 6.9+ loads core block assets on demand in classic themes by default. Existing
			// installs whose stored settings predate the `blockAssetsOnDemand` key inherit that
			// default in-memory here (no database write on front-end requests); the persisted
			// value is backfilled once by maybe_migrate_block_assets_setting() on admin_init.
			if ( function_exists( 'wp_load_classic_theme_block_styles_on_demand' ) ) {
				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					$this->options['file_optimisation'] = array();
				}
				if ( ! isset( $this->options['file_optimisation']['blockAssetsOnDemand'] ) ) {
					$this->options['file_optimisation']['blockAssetsOnDemand'] = true;
				}
			}

			// Native lazy loading is the default path (loading="lazy" + decoding="async"
			// emitted directly, core attributes respected). Existing installs whose stored
			// settings predate the `lazyLoadNative` key inherit the native default in-memory
			// here so the JS data-src swap never runs for them; an explicit stored `false`
			// (user revert to the legacy IntersectionObserver path) is preserved untouched.
			if ( ! isset( $this->options['image_optimisation'] ) || ! is_array( $this->options['image_optimisation'] ) ) {
				$this->options['image_optimisation'] = array();
			}
			if ( ! isset( $this->options['image_optimisation']['lazyLoadNative'] ) ) {
				$this->options['image_optimisation']['lazyLoadNative'] = true;
			}
			if ( ! isset( $this->options['image_optimisation']['lazyLoadImages'] ) ) {
				$this->options['image_optimisation']['lazyLoadImages'] = false;
			}

			if ( ! isset( $this->options['llms_txt'] ) || ! is_array( $this->options['llms_txt'] ) ) {
				$this->options['llms_txt'] = array();
			}
			if ( ! isset( $this->options['llms_txt']['enabled'] ) ) {
				$this->options['llms_txt']['enabled'] = false;
			}
			if ( ! isset( $this->options['llms_txt']['source'] ) ) {
				$this->options['llms_txt']['source'] = 'both';
			}

			if ( ! isset( $this->options['od_integration'] ) || ! is_array( $this->options['od_integration'] ) ) {
				$this->options['od_integration'] = array();
			}
			if ( ! isset( $this->options['od_integration']['enabled'] ) ) {
				$this->options['od_integration']['enabled'] = class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
			}

			if ( ! isset( $this->options['bfcache'] ) || ! is_array( $this->options['bfcache'] ) ) {
				$this->options['bfcache'] = array();
			}
			if ( ! isset( $this->options['bfcache']['enabled'] ) ) {
				$this->options['bfcache']['enabled'] = false;
			}

			if ( ! isset( $this->options['perf_translations'] ) || ! is_array( $this->options['perf_translations'] ) ) {
				$this->options['perf_translations'] = array();
			}
			if ( ! isset( $this->options['perf_translations']['enabled'] ) ) {
				$this->options['perf_translations']['enabled'] = false;
			}

			if ( ! isset( $this->options['ai_adaptive'] ) || ! is_array( $this->options['ai_adaptive'] ) ) {
				$this->options['ai_adaptive'] = array();
			}
			if ( ! isset( $this->options['ai_adaptive']['enabled'] ) ) {
				$this->options['ai_adaptive']['enabled'] = false;
			}

			if ( ! isset( $this->options['edge_cache'] ) || ! is_array( $this->options['edge_cache'] ) ) {
				$this->options['edge_cache'] = array();
			}
			if ( ! isset( $this->options['edge_cache']['enabled'] ) ) {
				$this->options['edge_cache']['enabled'] = false;
			}

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
		 * @since 1.9.0
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
		 * @since 1.9.0
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
		 * @since 1.9.0
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
					// LS-320: cacheVaryGroup admin→99 coalescing.
					$hash = '';
					if ( ! empty( $this->options['litespeed_integration']['cacheVaryGroup'] ?? false ) && in_array( 'administrator', (array) ( $user->roles ?? array() ), true ) ) {
						$hash = substr( md5( '99' . ( function_exists( 'wp_salt' ) ? wp_salt() : '' ) ), 0, 12 );
					} else {
						$hash = Util::get_role_hash( $user );
					}
					if ( '' !== $hash && ( ! isset( $_COOKIE['wppo_role_hash'] ) || $_COOKIE['wppo_role_hash'] !== $hash ) ) {
						$opts = array(
							'expires'  => time() + DAY_IN_SECONDS,
							'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
							'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
							'secure'   => is_ssl(),
							'httponly' => true,
							'samesite' => 'Lax',
						);
						if ( PHP_VERSION_ID >= 70300 ) {
							setcookie( 'wppo_role_hash', $hash, $opts );
						} else {
							setcookie( 'wppo_role_hash', $hash, $opts['expires'], $opts['path'] . '; SameSite=Lax', $opts['domain'], $opts['secure'], true );
						}
					}
				}
			}
			// LS-320: keep _lscache_vary in sync.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'seed_lscache_vary_cookie' ) ) {
				LiteSpeed_Integration::seed_lscache_vary_cookie();
			}
		}

		/**
		 * Clear the wppo_role_hash cookie on logout.
		 *
		 * @since 1.9.0
		 * @return void
		 */
		public function clear_role_hash_cookie(): void {
			if ( isset( $_COOKIE['wppo_role_hash'] ) ) {
				$opts = array(
					'expires'  => time() - YEAR_IN_SECONDS,
					'path'     => defined( 'COOKIEPATH' ) ? COOKIEPATH : '/',
					'domain'   => defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '',
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				);
				if ( PHP_VERSION_ID >= 70300 ) {
					setcookie( 'wppo_role_hash', '', $opts );
				} else {
					setcookie( 'wppo_role_hash', '', $opts['expires'], $opts['path'] . '; SameSite=Lax', $opts['domain'], $opts['secure'], true );
				}
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'clear_lscache_vary_cookie' ) ) {
				LiteSpeed_Integration::clear_lscache_vary_cookie();
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

			// LiteSpeed integration (Phase 1 — safe coexistence). Loaded after
			// Server_Rules so is_litespeed() delegation is available.
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-server-rules.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-server-rules.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-litespeed-integration.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-litespeed-integration.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-litespeed-crawler.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-litespeed-crawler.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-litespeed-esi.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-litespeed-esi.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-llms.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-llms.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-od-bridge.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-od-bridge.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-bfcache.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-bfcache.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-perf-translations.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-perf-translations.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-ai-adaptive.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-ai-adaptive.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-edge-cache.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-edge-cache.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-edge-purger.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-edge-purger.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-cdn.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cdn.php';
			}

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::add_command( 'wppo', 'PerformanceOptimise\Inc\WPPO_CLI_Command' );
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
			add_action( 'admin_init', array( $this, 'maybe_run_upgrades' ) );
			add_action( 'wppo_run_upgrades', array( 'PerformanceOptimise\Inc\Activate', 'maybe_run_upgrades' ) );
			add_action( 'upgrader_process_complete', array( $this, 'maybe_schedule_upgrade_routine' ), 10, 2 );
			add_action( 'admin_init', array( $this, 'maybe_run_version_upgrade' ) );
			add_action( 'upgrader_process_complete', array( $this, 'maybe_run_version_upgrade' ), 10, 0 );
			add_action( 'admin_init', array( $this, 'maybe_migrate_block_assets_setting' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'init', array( $this, 'set_role_hash_cookie' ) );
			add_action( 'wp_logout', array( $this, 'clear_role_hash_cookie' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'apply_module_loading_strategies' ), 10000 );
			$has_delay_js = ! empty( $this->options['file_optimisation']['delayJS'] );
			$has_defer_js = ! empty( $this->options['file_optimisation']['deferJS'] );
			$wp_version   = (string) ( $GLOBALS['wp_version'] ?? get_bloginfo( 'version' ) );
			// The native 'strategy' script data added via wp_script_add_data() is only
			// honoured by core since WP 6.3, so the native defer path is gated to 6.3+
			// and older core (WP 6.2) uses the legacy script_loader_tag fallback.
			// TODO(#553): remove the legacy fallback when minimum supported WP is raised to 6.3.
			$is_wp63_plus = version_compare( $wp_version, '6.3-alpha', '>=' );
			// Pre-release-inclusive floor: '6.9-alpha' also matches alpha/beta/RC builds
			// of 6.9 which already ship the template-enhancement buffer functions.
			// TODO(#553): remove the legacy buffer paths when minimum supported WP is raised to 6.9.
			$is_wp69_plus = version_compare( $wp_version, '6.9-alpha', '>=' );

			// Delay JS: the script_loader_tag filter performs the wppo-src/type rewriting
			// on every supported version, so it is always registered when delay JS is on.
			if ( $has_delay_js ) {
				add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 2 );
				add_action( 'wp', array( $this, 'apply_per_page_delay_config' ) );
			}

			// Defer JS: use the native strategy on WP 6.3+, the script_loader_tag
			// fallback on older core.
			if ( $has_defer_js ) {
				if ( $is_wp63_plus ) {
					add_action( 'wp_enqueue_scripts', array( $this, 'add_defer_strategy' ), 1000 );
				} else {
					add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute_legacy' ), 10, 2 );
				}
				// Native fetchpriority rendering arrived in WP 6.9 (Trac #61734); the
				// regex-based script_loader_tag fallback only runs on older cores.
				if ( ! $is_wp69_plus ) {
					add_filter( 'script_loader_tag', array( $this, 'add_fetchpriority_to_deferred' ), 11, 2 );
				}
			}
			add_action( 'admin_bar_menu', array( $this, 'add_setting_to_admin_bar' ), 100 );

			if ( ! empty( $this->options['file_optimisation']['removeWooCSSJS'] ) ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'remove_woocommerce_scripts' ), 999 );
			}

			if ( ! empty( $this->options['cache_settings']['enableCache'] ) ) {
				$this->cache = new Cache( $this->options );
				$this->cache->set_image_optimisation( $this->image_optimisation );
				$this->cache->set_google_fonts( $this->google_fonts );
				if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $is_wp69_plus ) {
					// WP 6.9+ template enhancement output buffer.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this->cache, 'process_buffer_for_cache' ), 10, 2 );
					add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this->cache, 'stash_cache' ) );
				} else {
					// Legacy path (deprecated) — earmarked for removal when WP 6.9+ becomes the minimum supported version.
					// TODO(#553): remove when minimum supported WP is raised to 6.9.
					add_action( 'template_redirect', array( $this->cache, 'start_output_buffer' ) );
				}
				add_action( 'save_post', array( $this, 'on_save_post_invalidate_cache' ), 10, 3 );
			}

			// Server-Timing (WP 6.9+). Registering wp_finalized_template_enhancement_output_buffer
			// automatically opts into the template-enhancement buffer (priority 1000 by default),
			// which disables response streaming. TTFB increases while TTLB unchanged — intentional
			// when Server-Timing is enabled; keep disabled by default and emit only on cache-miss
			// generation passes (advanced-cache.php serves cached pages without booting WordPress).
			// @since NEXT.
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $this->server_timing_enabled() ) {
				add_action( 'template_redirect', array( $this, 'capture_template_start' ), 0 );
				add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this, 'emit_server_timing_header' ), 0, 1 );
			}

			// Standalone used-CSS output buffer when page cache is disabled.
			if ( empty( $this->options['cache_settings']['enableCache'] ) && ! empty( $this->options['file_optimisation']['removeUnusedCSS'] ) ) {
				if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $is_wp69_plus ) {
					// WP 6.9+ template enhancement output buffer.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'process_used_css_only' ), 20, 2 );
				} else {
					// Legacy path (deprecated) — earmarked for removal when WP 6.9+ becomes the minimum supported version.
					// TODO(#553): remove when minimum supported WP is raised to 6.9.
					add_action( 'template_redirect', array( $this, 'start_used_css_buffer' ) );
				}
			}

			// Optional LCP image prioritization on the finalized HTML (default off).
			if ( ! empty( $this->options['image_optimisation']['prioritizeLCPImages'] ) ) {
				// TODO(#624): when core's Enhanced Responsive Images ships, reassess
				// whether this buffer-level LCP prioritization / fetchpriority stamping
				// can defer to core-provided attributes (wp_get_loading_optimization_attributes()
				// output is already honoured). No runtime change until the core API lands.
				if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
					// WP 6.9+ template enhancement output buffer. Runs after cache (10) and used-CSS (20).
					add_filter( 'wp_template_enhancement_output_buffer', array( $this->image_optimisation, 'prioritize_lcp_in_buffer' ), 30, 2 );
				} else {
					// Legacy path: priority 20 runs AFTER the cache buffer (default priority 10),
					// so its inner callback runs first on the raw buffer and the cache callback
					// then stores the LCP-enhanced HTML — mirroring the 6.9+ flow.
					// TODO(#553): remove the legacy LCP buffer path when minimum supported WP is raised to 6.9.
					add_action( 'template_redirect', array( $this, 'start_lcp_priority_buffer' ), 20 );
				}
			}

			// Invalidate DB cleanup counts when posts are added or removed (for public post types).
			if ( is_admin() ) {
				add_action( 'save_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10, 2 );
				add_action( 'deleted_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10, 2 );
			}
			if ( ! empty( $this->options['file_optimisation']['combineCSS'] ) ) {
				// TODO(#624): when WP 7.2 removes concatenation in favour of preloads,
				// reassess whether combine_css() should defer to core preload emission
				// or become an opt-in legacy toggle. No runtime change until then.
				if ( ! $this->cache ) {
					$this->cache = new Cache( $this->options );
					$this->cache->set_image_optimisation( $this->image_optimisation );
					$this->cache->set_google_fonts( $this->google_fonts );
				}
				add_action( 'wp_enqueue_scripts', array( $this->cache, 'combine_css' ), PHP_INT_MAX );
				// Emit the combined-CSS preload after combine_css() has populated it,
				// before core prints the stylesheet <link> at wp_head priority 8.
				add_action( 'wp_head', array( $this->cache, 'maybe_preload_combine_css' ), 1 );
			}

			$rest = new Rest();
			add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

			// Real-user Web Vitals: enqueue the beacon + bake its config into output
			// (captured by the page cache so cached pages keep beaconing without WP).
			add_action( 'wp_enqueue_scripts', array( 'PerformanceOptimise\Inc\RUM', 'maybe_enqueue_scripts' ), 5 );
			add_action( 'wp_footer', array( 'PerformanceOptimise\Inc\RUM', 'print_config' ), 90 );

			// Edge/CDN cache purge on full cache clear (Cloudflare / Varnish).
			add_action( 'wppo_after_cache_clear', array( 'PerformanceOptimise\Inc\CDN_Purger', 'purge_all' ) );
			// N2 Edge HTML adapter — purge alongside CDN_Purger (stale-while-revalidate).
			if ( class_exists( 'PerformanceOptimise\Inc\Edge_Purger' ) ) {
				add_action( 'wppo_after_cache_clear', array( 'PerformanceOptimise\Inc\Edge_Purger', 'purge_all' ), 20, 2 );
			}

			// LS-410 CDN typed hooks + buffer cooperation (LSCWP cdn.cls.php:195,199,203 + litespeed_buffer_finalize).
			if ( class_exists( 'PerformanceOptimise\Inc\CDN' ) ) {
				add_filter( 'wp_get_attachment_url', array( 'PerformanceOptimise\Inc\CDN', 'rewrite_url' ), 20, 2 );
				add_filter( 'wp_calculate_image_srcset', array( 'PerformanceOptimise\Inc\CDN', 'rewrite_srcset' ), 20, 1 );
				add_filter( 'style_loader_src', array( 'PerformanceOptimise\Inc\CDN', 'rewrite_url' ), 20, 2 );
				add_filter( 'script_loader_src', array( 'PerformanceOptimise\Inc\CDN', 'rewrite_url' ), 20, 2 );
				add_filter( 'litespeed_buffer_finalize', array( 'PerformanceOptimise\Inc\CDN', 'rewrite_buffer' ), 10, 1 );
			}

			// LLMs.txt (N8) — rewrite + template_redirect fallback + Link headers.
			if ( class_exists( 'PerformanceOptimise\Inc\Llms' ) ) {
				add_action( 'init', array( 'PerformanceOptimise\Inc\Llms', 'register_rewrite' ) );
				add_filter( 'query_vars', array( 'PerformanceOptimise\Inc\Llms', 'add_query_vars' ) );
				add_action( 'template_redirect', array( 'PerformanceOptimise\Inc\Llms', 'serve' ), 1 );
				add_action( 'send_headers', array( 'PerformanceOptimise\Inc\Llms', 'emit_link_header' ) );
				add_action( 'wp_head', array( 'PerformanceOptimise\Inc\Llms', 'emit_head_link' ), 1 );
				// Daily generation handled solely by Cron::llms_txt_cron() (gated on enabled) to avoid double dispatch.
				add_action( 'update_option_wppo_settings', array( 'PerformanceOptimise\Inc\Llms', 'on_settings_update' ), 10, 2 );
			}

			// N6 bfcache — Instant Back/Forward (privacy-safe session-token + pageshow).
			if ( class_exists( 'PerformanceOptimise\Inc\Bfcache' ) ) {
				Bfcache::init();
			}

			// N7 Performant Translations — .mo→php compilation.
			if ( class_exists( 'PerformanceOptimise\Inc\Perf_Translations' ) ) {
				Perf_Translations::init();
			}

			// N1 AI Adaptive — RUM → heuristic suggestions + speculation prefetch.
			if ( class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) ) {
				AI_Adaptive::init();
			}

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

				if ( function_exists( 'wp_maybe_inline_styles' ) ) {
					// The inline-styles mechanism (`wp_maybe_inline_styles()` /
					// `styles_inline_size_limit`) exists since WP 5.8; only the default
					// budget changed in 6.9. Rewrite styles at enqueue time and register
					// 'path' data so core can inline minified files within the budget.
					add_action( 'wp_enqueue_scripts', array( $this, 'minify_queued_styles' ), PHP_INT_MAX - 1 );
				}

				add_filter( 'style_loader_tag', array( $this, 'minify_css' ), 10, 3 );
			}

			if ( ! empty( $this->options['file_optimisation']['removeQueryStrings'] ) ) {
				add_filter( 'script_loader_src', array( $this, 'strip_static_query_strings' ), 10, 2 );
				add_filter( 'style_loader_src', array( $this, 'strip_static_query_strings' ), 10, 2 );
			}

			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ) ) {
				add_filter( 'style_loader_tag', array( $this->google_fonts, 'process_style_tag' ), 9, 3 );
			}

			$this->register_block_assets_filters( function_exists( 'wp_load_classic_theme_block_styles_on_demand' ) );

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

				// When both defer JS and delay JS are active, deferred handles must not
				// be delay-rewritten (wppo-src / wppo/javascript) — otherwise delay JS
				// overrides the native defer strategy on WP 6.3+ and corrupts script
				// attributes. Merge the defer exclusions so add_defer_attribute() and
				// apply_per_page_delay_config() skip deferred scripts entirely.
				if ( ! empty( $this->options['file_optimisation']['deferJS'] ) ) {
					$this->exclude_delay_js = array_merge( $this->exclude_delay_js, $this->exclude_defer_js );
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
			add_filter( 'wp_resource_hints', array( $this, 'add_resource_hints' ), 10, 2 );

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

			// LS-202: LiteSpeed → WPPO purge sync (litespeed_purged_all/post/purge_finalize).
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'init' ) ) {
				LiteSpeed_Integration::init();
			}
			// P5 ESI bridge (Enterprise only — OLS has no ESI).
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', 'init' ) ) {
				LiteSpeed_ESI::init();
			}
		}

		/**
		 * Register filters that control when core block assets load.
		 *
		 * On WP 6.9+ classic themes load separate core block assets on demand by default
		 * (`wp_load_classic_theme_block_styles_on_demand()` registers
		 * `should_load_separate_core_block_assets` → `__return_true` at priority 0), so the
		 * toggle becomes an opt-out: when disabled we register
		 * `should_load_separate_core_block_assets` → `__return_false` at priority 10, which
		 * runs after core's priority-0 callback so `apply_filters()` resolves to `false` for
		 * classic themes. Block themes are skipped because core registers `__return_true`
		 * later (via `_add_default_theme_supports()` on `after_setup_theme`) and should keep
		 * the separate-assets default by intent rather than by filter-ordering coincidence.
		 * On pre-6.9 cores the toggle stays an opt-in via the legacy
		 * `should_load_block_assets_on_demand` filter.
		 *
		 * @param bool $loads_separate_core_block_assets_on_demand Whether WP 6.9+ is active
		 *                                                        (core loads separate core
		 *                                                        block assets on demand).
		 * @return void
		 */
		private function register_block_assets_filters( bool $loads_separate_core_block_assets_on_demand ): void {
			if ( $loads_separate_core_block_assets_on_demand ) {
				// WP 6.9+ classic themes load separate core block assets on demand by
				// default. The combined wp-block-library stylesheet is only restored
				// when the user explicitly opts out: blockAssetsOnDemand OFF (the
				// version-aware toggle acts as an opt-out on 6.9+), or the
				// loadAllCoreBlockAssets toggle is enabled. Block themes are always
				// skipped so they keep core's separate-assets default by intent.
				$opt_out_combined = empty( $this->options['file_optimisation']['blockAssetsOnDemand'] )
					|| ! empty( $this->options['file_optimisation']['loadAllCoreBlockAssets'] );

				if ( $opt_out_combined && ! wp_is_block_theme() ) {
					add_filter( 'should_load_separate_core_block_assets', '__return_false', 10 );
				}
			} elseif ( ! empty( $this->options['file_optimisation']['blockAssetsOnDemand'] ) ) {
				// Pre-6.9 cores: opt-in to loading block assets on demand.
				add_filter( 'should_load_block_assets_on_demand', '__return_true' );
			}
		}

		/**
		 * One-time upgrade for the block-assets toggle on WP 6.9+.
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end request never
		 * triggers a settings write. See {@see migrate_block_assets_setting()} for the logic.
		 *
		 * @return void
		 */
		public function maybe_migrate_block_assets_setting(): void {
			$this->migrate_block_assets_setting( function_exists( 'wp_load_classic_theme_block_styles_on_demand' ) );
		}

		/**
		 * One-time upgrade core for the block-assets toggle on WP 6.9+.
		 *
		 * WP 6.9+ loads core block assets on demand in classic themes by default, but older
		 * installs may store a `wppo_settings` array that predates the `blockAssetsOnDemand`
		 * key (the pre-6.9 default was OFF). Without an upgrade those installs would silently
		 * register the opt-out (forcing the combined `wp-block-library` stylesheet) once they
		 * reach WP 6.9+.
		 *
		 * Only installs that never configured the toggle (key absent) are defaulted to `true`
		 * so they inherit core's new default; any stored explicit value (true or false) is
		 * preserved verbatim, and fresh installs with no stored option are skipped because the
		 * constructor defaults already match. The one-time marker keeps later explicit user
		 * choices intact.
		 *
		 * @param bool $loads_separate_core_block_assets_on_demand Whether WP 6.9+ is active
		 *                                                        (core loads separate core
		 *                                                        block assets on demand).
		 * @return void
		 */
		private function migrate_block_assets_setting( bool $loads_separate_core_block_assets_on_demand ): void {
			if ( ! $loads_separate_core_block_assets_on_demand ) {
				return;
			}

			if ( get_option( 'wppo_block_assets_migrated' ) ) {
				return;
			}

			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				// Fresh install (or no stored settings): constructor defaults already match
				// WP 6.9+ behavior, so there is nothing to migrate.
				update_option( 'wppo_block_assets_migrated', 1 );
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( ! array_key_exists( 'blockAssetsOnDemand', $file ) ) {
				$stored['file_optimisation'] = $file + array( 'blockAssetsOnDemand' => true );
				update_option( 'wppo_settings', $stored );

				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					$this->options['file_optimisation'] = array();
				}
				$this->options['file_optimisation']['blockAssetsOnDemand'] = true;

				Log::add( __( 'Enabled on-demand block asset loading to match the WordPress 6.9 default.', 'performance-optimisation' ) );
			}

			update_option( 'wppo_block_assets_migrated', 1 );
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
		 * Runs one-time upgrade routines after a plugin update.
		 *
		 * Routine plugin updates never fire register_activation_hook, so this is
		 * triggered on admin_init. Activate::maybe_run_upgrades() exits early once
		 * the stored plugin version has reached the migration floor.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public function maybe_run_upgrades(): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			Activate::maybe_run_upgrades();
		}

		/**
		 * Schedule the upgrade routine in the background after a plugin update.
		 *
		 * Fires on upgrader_process_complete when this plugin was updated, giving
		 * sites updated via WP-CLI, background auto-updates, or managed-hosting
		 * pipelines a reliable trigger that does not depend on an admin visit.
		 *
		 * @param object $upgrader   The upgrader instance (unused).
		 * @param array  $hook_extra Extra arguments passed to the hook.
		 * @return void
		 * @since 1.9.0
		 */
		public function maybe_schedule_upgrade_routine( $upgrader, $hook_extra ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
			if ( empty( $hook_extra ) || ! is_array( $hook_extra ) ) {
				return;
			}

			$plugin_file = 'performance-optimisation/performance-optimisation.php';

			if ( ! empty( $hook_extra['plugin'] ) && $plugin_file === $hook_extra['plugin'] ) {
				Activate::schedule_upgrade_routine();
				return;
			}

			if ( ! empty( $hook_extra['plugins'] ) && is_array( $hook_extra['plugins'] ) && in_array( $plugin_file, $hook_extra['plugins'], true ) ) {
				Activate::schedule_upgrade_routine();
			}
		}

		/**
		 * Run one-time upgrade routines when the plugin version changes.
		 *
		 * Regenerates the advanced-cache.php drop-in so it honours the
		 * DONOTCACHEPAGE no-cache marker, then clears the full cache once to
		 * remove any pre-existing stale pages the old drop-in would keep serving.
		 * Runs on admin_init and upgrader_process_complete (covering admin-initiated
		 * and CLI updates); the one-time wppo_version gate keeps it idempotent.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public function maybe_run_version_upgrade(): void {
			// Only administrators may trigger the destructive upgrade routine.
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}

			$installed_version = get_option( 'wppo_version', '' );

			if ( version_compare( (string) $installed_version, WPPO_VERSION, '>=' ) ) {
				return;
			}

			// Regenerate the drop-in so it honours the DONOTCACHEPAGE marker. On a
			// transient filesystem failure leave wppo_version unchanged so a later
			// request retries instead of skipping the migration forever.
			if ( ! Advanced_Cache_Handler::create() ) {
				return;
			}

			// Only clear the full cache once our own marker-aware drop-in is actually
			// in place; a foreign drop-in is left untouched and cannot serve markers.
			if ( ! Advanced_Cache_Handler::foreign_dropin_present() ) {
				Cache::clear_cache();
			}

			update_option( 'wppo_version', WPPO_VERSION, false );
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

				// Re-generate the drop-in so values baked into it (e.g. cache
				// life) always match the saved settings.
				Advanced_Cache_Handler::create();
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
			// LiteSpeed and OpenLiteSpeed both read .htaccess (like Apache).
			// Gate still on enableServerRules but allow litespeed to trigger.
			// LS-401: also refresh when litespeed_integration.enableNextGenRewrite toggles while server rules are enabled.
			$old_enable      = isset( $old_value['file_optimisation']['enableServerRules'] ) ? (bool) $old_value['file_optimisation']['enableServerRules'] : false;
			$new_enable      = isset( $value['file_optimisation']['enableServerRules'] ) ? (bool) $value['file_optimisation']['enableServerRules'] : false;
			$old_nextgen     = isset( $old_value['litespeed_integration']['enableNextGenRewrite'] ) ? (bool) $old_value['litespeed_integration']['enableNextGenRewrite'] : false;
			$new_nextgen     = isset( $value['litespeed_integration']['enableNextGenRewrite'] ) ? (bool) $value['litespeed_integration']['enableNextGenRewrite'] : false;
			$nextgen_changed = $old_nextgen !== $new_nextgen;
			$old_vary        = $old_value['litespeed_integration']['varyGroups'] ?? array();
			$new_vary        = $value['litespeed_integration']['varyGroups'] ?? array();
			$vary_changed    = ( ! empty( $old_vary['mobile'] ) !== ! empty( $new_vary['mobile'] ) ) || ( ! empty( $old_vary['webp'] ) !== ! empty( $new_vary['webp'] ) );

			if ( $old_enable !== $new_enable ) {
				$ok = Htaccess_Handler::update_rules( $new_enable );

				// Log hint for OpenLiteSpeed operators: restart required.
				if ( $ok && $new_enable && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() ) {
					Log::add( __( 'Server rules updated on LiteSpeed — restart OpenLiteSpeed if changes do not appear immediately.', 'performance-optimisation' ) );
				}

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
							echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__( 'Performance Optimisation: Failed to update .htaccess rules. Please check file permissions.', 'performance-optimisation' ) . '</p></div>';
						}
					);
				}
			} elseif ( ( $nextgen_changed || $vary_changed ) && $new_enable ) {
				// Next-gen or vary toggle changed while server rules remain enabled — refresh htaccess.
				$ok = Htaccess_Handler::update_rules( true );
				if ( $ok && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() ) {
					Log::add( __( 'Server rules updated on LiteSpeed — restart OpenLiteSpeed if changes do not appear immediately.', 'performance-optimisation' ) );
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
		 * @since NEXT
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
		 * @since 1.9.0
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
		 * @since 1.9.0
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
		 * Whether the Server-Timing debug header is enabled.
		 *
		 * Reads the performance_audit.server_timing_enabled setting (default false, off).
		 * Operators may override it via the wppo_server_timing_enabled filter, e.g. to
		 * restrict emission to logged-in administrators with manage_options capability.
		 *
		 * Enabling forces the template-enhancement output buffer via
		 * wp_finalized_template_enhancement_output_buffer registration in
		 * {@see Main::setup_hooks()} (priority 1000 by default), which disables
		 * response streaming / early flush. TTFB increases while TTLB unchanged —
		 * intentional when Server-Timing is active; keep disabled by default and
		 * emit only on cache-miss generation passes. Cached hits served by
		 * advanced-cache.php never boot WordPress so the header never appears there.
		 * Paired with {@see Main::capture_template_start()} /
		 * {@see Main::emit_server_timing_header()}.
		 *
		 * @since  1.9.0
		 * @since  NEXT Streaming tradeoff note and cross-reference.
		 * @return bool True when Server-Timing telemetry is active.
		 */
		public function server_timing_enabled(): bool {
			$enabled = ! empty( $this->options['performance_audit']['server_timing_enabled'] ?? false );
			return (bool) apply_filters( 'wppo_server_timing_enabled', $enabled );
		}

		/**
		 * Capture the template render start time for Server-Timing telemetry.
		 *
		 * Records microtime(true) at template_redirect:0 before the template is
		 * included, for later duration calculation in
		 * {@see Main::emit_server_timing_header()}. Early bail for admin, AJAX,
		 * REST, or when {@see Main::server_timing_enabled()} is false; stores
		 * the timestamp in {@see Main::$server_timing_template_start}. Paired
		 * with emit_server_timing_header() on
		 * wp_finalized_template_enhancement_output_buffer.
		 *
		 * @since 1.9.0
		 * @since NEXT Expanded documentation for buffering opt-in context.
		 * @return void
		 */
		public function capture_template_start(): void {
			if ( ! $this->server_timing_enabled() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			$this->server_timing_template_start = microtime( true );
		}

		/**
		 * Emit a Server-Timing response header on live front-end renders (WP 6.9+).
		 *
		 * Hook: wp_finalized_template_enhancement_output_buffer (also wp_send_late_headers
		 * alias) — WP 6.9 canonical late-header spot before flush. WP 6.9 standardised
		 * the former ad-hoc ob_start() at template_redirect / template_include into
		 * wp_should_output_buffer_template_for_enhancement() /
		 * wp_start_template_enhancement_output_buffer() /
		 * wp_finalize_template_enhancement_output_buffer() with filter
		 * wp_template_enhancement_output_buffer and action
		 * wp_finalized_template_enhancement_output_buffer ($final) (Trac #64126 / #63636
		 * / #43258; Performance Lab #2225/#2515), try/catch wrapped with WP_DEBUG_DISPLAY
		 * on error.
		 *
		 * Param $output ($final) is the final HTML string passed by Core to the action
		 * (not the filtered value); reserved for future ETag hashing without re-registration
		 * and currently unused.
		 *
		 * Header must be sent via header('Server-Timing: ...', false) before flush; the
		 * second arg false appends to preserve coexisting metrics. Guards headers_sent()
		 * and null === System_Info::get_request_start_microtime() before emitting. Core
		 * wraps this action in try/catch and appends WP_DEBUG_DISPLAY on error, so the
		 * plugin does not add an extra try/catch.
		 *
		 * Streaming tradeoff: registering this action automatically opts into the
		 * template-enhancement buffer (priority 1000 by default), which disables response
		 * streaming / early flush. TTFB increases while TTLB unchanged — intentional when
		 * Server-Timing is enabled; keep disabled by default and emit only on cache-miss
		 * generation passes (advanced-cache.php serves cached pages without booting
		 * WordPress).
		 *
		 * No ETag / 304 computation here — conditional GET (If-Modified-Since /
		 * If-None-Match → 304 with ETag / Last-Modified) is already handled in the
		 * Advanced_Cache_Handler drop-in (advanced-cache.php).
		 *
		 * @param string $output The finalized output buffer content (final HTML string, alias $final).
		 * @return void
		 * @since NEXT
		 */
		public function emit_server_timing_header( string $output = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Reserved for future ETag hashing without re-registration.
			if ( ! $this->server_timing_enabled() || is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			if ( headers_sent() ) {
				return;
			}

			$start = System_Info::get_request_start_microtime();
			if ( null === $start ) {
				return;
			}

			$now     = microtime( true );
			$timings = array();

			// wp-before-template: request bootstrap to template start. Measured from the
			// captured template-start marker so it is disjoint from wp-template below.
			if ( $this->server_timing_template_start > $start ) {
				$timings[] = 'wp-before-template;dur=' . round( ( $this->server_timing_template_start - $start ) * 1000, 2 );
			}

			// wp-template: template render duration.
			if ( $this->server_timing_template_start > 0 ) {
				$render = ( $now - $this->server_timing_template_start ) * 1000;
				if ( $render > 0 ) {
					$timings[] = 'wp-template;dur=' . round( $render, 2 );
				}
			}

			if ( ! empty( $timings ) ) {
				// Append rather than replace so coexisting Server-Timing entries are preserved.
				header( 'Server-Timing: ' . implode( ', ', $timings ), false );
			}
		}

		/**
		 * Start output buffer for used-CSS (legacy path, WP &lt; 6.9).
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public function start_used_css_buffer() {
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return;
			}
			ob_start( array( $this, 'process_used_css_capture' ) );
		}

		/**
		 * Start output buffer for LCP image prioritization (legacy path, WP &lt; 6.9).
		 *
		 * Registers at priority 20, after the cache and used-CSS buffers (default
		 * priority 10), so its inner buffer callback runs first on the raw buffer
		 * and the cache callback then stores the LCP-enhanced HTML. The callback
		 * no-ops when the feature is disabled, the buffer is empty, the request is
		 * non-HTML (feeds, robots, AJAX, REST), or the user is not eligible.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public function start_lcp_priority_buffer() {
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return;
			}
			if ( is_feed() || is_robots() || is_trackback() || is_preview() || is_embed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
				return;
			}
			ob_start( array( $this->image_optimisation, 'prioritize_lcp_in_buffer' ) );
		}

		/**
		 * Capture and process buffer for used-CSS.
		 *
		 * @param string $buffer The output buffer content.
		 * @return string The processed buffer.
		 * @since 1.9.0
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

			if ( ! is_array( $post_types ) ) {
				$post_types = array();
			}

			$excluded            = array( 'attachment' );
			$filtered_post_types = array_keys( array_diff( $post_types, $excluded ) );

			$this->options['image_optimisation']['availablePostTypes'] = $filtered_post_types;
		}

		/**
		 * Extract the active frontend theme's primary color.
		 *
		 * Checks block theme (theme.json) first, then classic theme (customizer).
		 *
		 * @since NEXT
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

			if ( ! $screen || 'toplevel_page_performance-optimisation' !== $screen->base ) {
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
					'apiUrl'                               => get_rest_url( null, 'performance-optimisation/v1/' ),
					'ajaxUrl'                              => admin_url( 'admin-ajax.php' ),
					'nonce'                                => wp_create_nonce( 'wp_rest' ),
					'nonce_refresh'                        => wp_create_nonce( 'wppo_nonce_refresh' ),
					'version'                              => WPPO_VERSION,
					'wpVersion'                            => get_bloginfo( 'version' ),
					'isBlockTheme'                         => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
					'settings'                             => $safe_options,
					'show_welcome'                         => ! (bool) get_user_meta( get_current_user_id(), 'wppo_welcome_dismissed', true ),
					'image_info'                           => $this->sanitize_image_info_for_client( get_option( 'wppo_img_info', array() ) ),
					'cache_size'                           => $cache_size,
					'total_js_css'                         => $total_js_css,
					// Read-only WP 7.1+ client-side media processing state. Evaluated
					// here (after Image_Optimisation has registered the opt-out filter)
					// so it already reflects an enabled "Force Server-Side Conversion"
					// toggle (false when core's in-browser processing is forced off).
					'client_side_media_processing_enabled' => function_exists( 'wp_is_client_side_media_processing_enabled' ) && wp_is_client_side_media_processing_enabled(),
					'performance_audit'                    => array(
						'homeUrl'                   => Util::cached_home_url( '/' ),
						'pagespeedApiKeyConfigured' => ! empty( $this->options['performance_audit']['pagespeed_api_key'] ),
						'highValueUrls'             => $this->options['performance_audit']['high_value_urls'] ?? array(), // Phase 3 will populate this.
						'autoFixEnabled'            => (bool) ( $this->options['performance_audit']['auto_fix_enabled'] ?? false ),
						'autoRescan'                => $this->options['performance_audit']['auto_rescan'] ?? '',
					),
					// Frontend theme colors for accent syncing.
					'themeColors'                          => $this->get_frontend_theme_colors(),
					// Editable user roles for the logged-in cache role selector.
					'userRoles'                            => $this->get_editable_role_names(),
					// Read-only WP 7.1 speculation-rules default overrides so the
					// Preload tab can surface the constant/env escape hatch (#65624)
					// without ever writing it.
					'speculation_rules'                    => array(
						'mode_override'       => $this->get_speculation_default_override( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' ),
						'eagerness_override'  => $this->get_speculation_default_override( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' ),
						'static_cache_active' => ! empty( $this->options['cache_settings']['enableCache'] ),
					),
					// LiteSpeed integration — for SPA banner + mode selector (Phase 1).
					'litespeed'                            => class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) ? LiteSpeed_Integration::get_info() : array(
						'detected'           => false,
						'server_type'        => Server_Rules::get_server_type(),
						'lscache_active'     => false,
						'mode'               => 'auto',
						'effective_mode'     => 'standalone',
						'wppo_owns_cache'    => true,
						'optimizer_disabled' => false,
					),
					// Allowlisted top-level settings keys — single source is Util::ALLOWED_SETTINGS_KEYS
					// (exposed here so JS `ALLOWED_IMPORT_KEYS` can stay in sync without codegen).
					'allowedSettingsKeys'                  => Util::ALLOWED_SETTINGS_KEYS,
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
				$lazy_load_images         = ! empty( $this->options['image_optimisation']['lazyLoadImages'] );
				$lazy_load_backgrounds    = ! empty( $this->options['image_optimisation']['lazyLoadBackgroundImages'] );
				$lazy_load_videos         = ! empty( $this->options['image_optimisation']['lazyLoadVideos'] );
				$enable_video_placeholder = ! empty( $this->options['image_optimisation']['enableVideoPlaceholder'] ) && $lazy_load_videos;
				$delay_js                 = ! empty( $this->options['file_optimisation']['delayJS'] );
				$use_native_lazy          = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

				// When native lazy loading is active, images use native loading="lazy" but iframes may still need JS restoration.
				$needs_script = ( ! $use_native_lazy && $lazy_load_images ) || $lazy_load_backgrounds || $lazy_load_videos || $enable_video_placeholder || $delay_js;

				if ( $needs_script ) {
					// Shared runtime config for the lazyload bundle. Exported to the frontend
					// via the script-module data filter on WP 6.5+, or as classic inline
					// scripts on older versions (see the fallback below).
					$lazy_config = array();

					if ( $use_native_lazy ) {
						$lazy_config['nativeLazy'] = true;
					}

					if ( $delay_js ) {
						$idle_timeout     = ! empty( $this->options['file_optimisation']['delayJSIdleTimeout'] )
							? absint( $this->options['file_optimisation']['delayJSIdleTimeout'] )
							: 3000;
						$default_strategy = ! empty( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
							? sanitize_text_field( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
							: 'interaction';

						$lazy_config['delayConfig'] = array(
							'idleTimeout'     => $idle_timeout,
							'defaultStrategy' => $default_strategy,
						);
					}

					$lazy_args = array(
						'in_footer'     => true,
						'fetchpriority' => 'low',
					);

					if ( function_exists( 'wp_enqueue_script_module' ) ) {
						// WP 6.5+: load lazyload as a native script module. Modules are always
						// deferred (non-render-blocking); the in_footer/fetchpriority args are
						// honoured from WP 6.9 and harmlessly ignored on earlier 6.x releases.
						wp_enqueue_script_module( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, $lazy_args );
						add_filter(
							'script_module_data_wppo-lazyload',
							static function ( array $data ) use ( $lazy_config ) {
								return array_merge( $data, $lazy_config );
							}
						);
					} else {
						// WP < 6.5 fallback: classic script enqueued with inline config injection.
						wp_enqueue_script( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, $lazy_args );

						if ( $use_native_lazy ) {
							wp_add_inline_script( 'wppo-lazyload', 'window.wppoNativeLazy=true;', 'before' );
						}

						if ( $delay_js ) {
							$delay_config = wp_json_encode( $lazy_config['delayConfig'] );
							wp_add_inline_script( 'wppo-lazyload', 'window.wppoDelayConfig=' . $delay_config . ';', 'before' );
						}
					}
				}
			}
		}

		/**
		 * Apply defer optimisations to script modules (WP 6.5+).
		 *
		 * Script modules are already deferred by the browser; the remaining wins
		 * are printing them in the footer and lowering their fetch priority so
		 * critical CSS/images win the network queue. Uses the core API when
		 * available (WP 6.9+) and is a no-op on older core.
		 *
		 * @since NEXT
		 * @return void
		 */
		public function apply_module_loading_strategies(): void {
			if ( empty( $this->options['file_optimisation']['deferJS'] ) ) {
				return;
			}
			if ( ! $this->should_optimise_for_logged_in() ) {
				return;
			}
			if ( ! function_exists( 'wp_script_modules' ) ) {
				return;
			}

			$modules = wp_script_modules();
			if ( ! is_object( $modules ) ) {
				return;
			}

			$excluded = Util::process_urls( (string) ( $this->options['file_optimisation']['excludeDeferJS'] ?? '' ) );

			// Collect registered module ids, tolerating core version differences.
			$ids = array();
			if ( method_exists( $modules, 'get_print_queue' ) ) {
				$ids = (array) $modules->get_print_queue();
			}
			if ( empty( $ids ) && isset( $modules->registered ) ) {
				$ids = array_keys( (array) $modules->registered );
			}
			if ( empty( $ids ) && isset( $modules->all ) ) {
				$ids = array_keys( (array) $modules->all );
			}

			foreach ( $ids as $id ) {
				if ( in_array( (string) $id, $excluded, true ) ) {
					continue;
				}
				if ( method_exists( $modules, 'set_in_footer' ) ) {
					$modules->set_in_footer( (string) $id, true );
				}
				if ( method_exists( $modules, 'set_fetchpriority' ) ) {
					$modules->set_fetchpriority( (string) $id, 'low' );
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
				$base_path   = wp_parse_url( Util::cached_home_url(), PHP_URL_PATH ) ?? '';
				$parsed_uri  = $request_uri;

				if ( '' !== $base_path && '/' !== $base_path && 0 === strpos( $request_uri, $base_path ) ) {
					// Only strip the base path when it forms a real path boundary
					// (followed by '/', '?', or the end of the URI). This prevents
					// /blogger from being mangled when the install base is /blog.
					$next_char = substr( $request_uri, strlen( $base_path ), 1 );
					if ( '' === $next_char || '/' === $next_char || '?' === $next_char ) {
						$parsed_uri = substr( $request_uri, strlen( $base_path ) );
					}
				}
				$current_url = Util::cached_home_url( sanitize_text_field( $parsed_uri ) );

				if ( Util::is_url_excluded( $current_url, $exclude_url_to_keep_js_css ) ) {
					return;
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
		 * Capability-gated: only users with `manage_options` see cache-clear nodes.
		 * The REST handlers behind the nodes also enforce `manage_options` + nonce,
		 * so this is a UI disclosure guard (defence in depth).
		 *
		 * @param \WP_Admin_Bar $wp_admin_bar The WordPress admin bar object used to add nodes and settings.
		 *
		 * @since 1.0.0
		 * @since NEXT Added `manage_options` capability check.
		 */
		public function add_setting_to_admin_bar( $wp_admin_bar ) {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
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
		 * On WP 6.9+ deferred handles also receive native fetchpriority/in_footer
		 * args via the Script Loader API (Trac #61734 / #63486) so core renders
		 * them with dependency bumping; the regex fallback
		 * add_fetchpriority_to_deferred() stays disabled on 6.9+ via setup_hooks().
		 *
		 * @since NEXT
		 *
		 * @return void
		 */
		public function add_defer_strategy(): void {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return;
			}
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

			$is_wp69_plus = version_compare( (string) ( $GLOBALS['wp_version'] ?? get_bloginfo( 'version' ) ), '6.9-alpha', '>=' );

			foreach ( $wp_scripts->queue as $handle ) {
				if ( ! in_array( $handle, $this->exclude_defer_js, true ) ) {
					wp_script_add_data( $handle, 'strategy', 'defer' );
					$this->deferred_handles[ $handle ] = true;
					// Native fetchpriority on WP 6.9+ (Trac #61734); pre-6.9 is handled
					// by the script_loader_tag regex fallback (add_fetchpriority_to_deferred).
					/**
					 * Filters fetchpriority for each deferred handle.
					 *
					 * Default 'low' deprioritises deferred (non-render-blocking)
					 * scripts. Return 'high' for an LCP-critical handle, falsy to
					 * suppress, or 'auto' to defer to browser.
					 *
					 * @since NEXT
					 *
					 * @param string $fetchpriority Fetchpriority value.
					 * @param string $handle        Script handle.
					 */
					$fetchpriority = apply_filters( 'wppo_deferred_fetchpriority', 'low', $handle );
					if ( ! is_string( $fetchpriority ) ) {
						$fetchpriority = '';
					}
					$fetchpriority = strtolower( trim( $fetchpriority ) );
					if ( ! in_array( $fetchpriority, array( 'high', 'low', 'auto' ), true ) ) {
						$fetchpriority = '';
					}
					if ( '' !== $fetchpriority ) {
						wp_script_add_data( $handle, 'fetchpriority', $fetchpriority );
					}
					if ( $is_wp69_plus ) {
						// Optional native in_footer for Script Modules on 6.9+ (Trac #63486).
						// Guarded — no-op on older core or when the API is absent.
						if ( class_exists( 'WP_Script_Modules' ) && method_exists( 'WP_Script_Modules', 'set_in_footer' ) ) {
							// Non-critical deferred handles are safe to move to footer.
							wp_script_add_data( $handle, 'in_footer', true );
						}
					}
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
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->should_optimise_for_logged_in() ) {
				return $tag;
			}

			if ( ! empty( $this->options['file_optimisation']['delayJS'] ) ) {
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
		 * Adds the defer attribute to script tags on WordPress &lt; 6.3.
		 *
		 * WordPress 6.3+ natively honours the 'strategy' script data added via
		 * wp_script_add_data(), so this legacy fallback is only registered on older
		 * core (WP 6.2) where the native strategy is silently ignored.
		 *
		 * @since 1.9.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @return string Modified script tag with the defer attribute.
		 */
		public function add_defer_attribute_legacy( $tag, $handle ): string {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->should_optimise_for_logged_in() ) {
				return $tag;
			}

			if ( in_array( $handle, $this->exclude_defer_js, true ) ) {
				return $tag;
			}

			if ( false !== strpos( $tag, ' defer' ) || false !== strpos( $tag, 'type="module"' ) ) {
				return $tag;
			}

			$this->deferred_handles[ $handle ] = true;

			return str_replace( ' src', ' defer src', $tag );
		}

		/**
		 * Check whether a script handle matches a delay pattern using word boundaries.
		 *
		 * The pattern (a handle or URL) is matched literally between `\b` word
		 * boundaries, preventing partial-word substring false positives such as
		 * `slide` matching the unrelated handle `slider-custom`, while preserving
		 * dash-delimited prefix matches users rely on (`jquery` → `jquery-core`).
		 * URL metacharacters are escaped via preg_quote() so the pattern is matched
		 * literally. Empty patterns are ignored so regex construction stays valid.
		 *
		 * @since 1.9.0
		 *
		 * @param string $handle  The script handle.
		 * @param string $pattern The configured pattern (handle or URL).
		 * @return bool True if the handle matches the pattern.
		 */
		private function matches_delay_pattern( string $handle, string $pattern ): bool {
			if ( '' === $pattern ) {
				return false;
			}
			return (bool) preg_match( '/\b' . preg_quote( $pattern, '/' ) . '\b/', $handle );
		}

		/**
		 * Get the delay strategy for a given script handle.
		 *
		 * Checks idle list, viewport list, and then falls back to default strategy.
		 *
		 * @since 1.9.0
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
				if ( $this->matches_delay_pattern( $handle, $pattern ) ) {
					return 'idle';
				}
			}
			foreach ( $this->delay_js_viewport_list as $pattern ) {
				if ( $this->matches_delay_pattern( $handle, $pattern ) ) {
					return 'viewport';
				}
			}
			return $this->delay_js_default_strategy;
		}

		/**
		 * Get the delay priority for a given script handle.
		 *
		 * @since 1.9.0
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
				if ( $this->matches_delay_pattern( $handle, $pattern ) ) {
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
		 * @since 1.9.0
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
		 * Pre-6.9 fallback only: on WP 6.9+ the native fetchpriority arg passed via
		 * wp_script_add_data() in add_defer_strategy() is rendered by core.
		 *
		 * @since 1.9.0
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

			// Preload fonts.
			if ( ! empty( $preload_settings['preloadFonts'] ) && ! empty( $preload_settings['preloadFontsUrls'] ) ) {
				$preload_fonts_urls = Util::process_urls( $preload_settings['preloadFontsUrls'] );

				foreach ( $preload_fonts_urls as $font_url ) {
					$font_url       = preg_match( '/^https?:\/\//i', $font_url ) ? $font_url : Util::cached_content_url( $font_url );
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
					$css_url = preg_match( '/^https?:\/\//i', $css_url ) ? $css_url : Util::cached_content_url( $css_url );
					Util::generate_preload_link( $css_url, 'preload', 'style' );
				}
			}

			$this->image_optimisation->preload_images();
		}

		/**
		 * Adds preconnect/dns-prefetch origins via core's resource hints API.
		 *
		 * Core's wp_resource_hints() batches, deduplicates, and normalizes
		 * preconnect/dns-prefetch hints, and exposes them through the
		 * `wp_resource_hints` filter for interoperability with other plugins.
		 * Font/CSS/image preload links stay on the raw echo path in
		 * add_preload_prefetch_preconnect() where `as`/`type`/`media` control
		 * is needed.
		 *
		 * Core normalizes preconnect hints to scheme+host and dns-prefetch
		 * hints to protocol-relative `//host`, and emits them on `wp_head` at
		 * priority 2, so they render after the plugin's priority-1 preload
		 * links (browser hint order is not significant).
		 *
		 * @since 1.9.0
		 *
		 * @param array  $urls          URLs to print for resource hints.
		 * @param string $relation_type The relation type (e.g. 'preconnect', 'dns-prefetch').
		 * @return array Filtered URLs.
		 */
		public function add_resource_hints( $urls, $relation_type ) {
			$preload_settings = $this->options['preload_settings'] ?? array();

			if ( 'preconnect' === $relation_type ) {
				if ( ! empty( $preload_settings['preconnect'] ) && ! empty( $preload_settings['preconnectOrigins'] ) ) {
					$preconnect_origins = Util::process_urls( $preload_settings['preconnectOrigins'] );

					// Preserve the crossorigin="anonymous" attribute the legacy echo
					// path emitted. Core renders array hints with their attributes
					// intact (single-quoted, href-first ordering) but normalizes the
					// href to scheme+host, so the output is functionally equivalent
					// rather than byte-identical.
					foreach ( $preconnect_origins as $origin ) {
						$urls[] = array(
							'href'        => $origin,
							'crossorigin' => 'anonymous',
						);
					}
				}
			} elseif ( 'dns-prefetch' === $relation_type ) {
				if ( ! empty( $preload_settings['prefetchDNS'] ) && ! empty( $preload_settings['dnsPrefetchOrigins'] ) ) {
					// The settings UI documents bare hostnames (e.g. "example.com").
					// Core's host guard drops URLs with no parseable host, so normalize
					// scheme-less origins to protocol-relative form before returning.
					$dns_origins = array_map(
						static function ( $origin ) {
							$has_prefix = preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//#i', $origin );

							return $has_prefix ? $origin : '//' . $origin;
						},
						Util::process_urls( $preload_settings['dnsPrefetchOrigins'] )
					);

					$urls = array_merge( $urls, $dns_origins );
				}
			}

			return $urls;
		}

		/**
		 * Adds speculation rules for prefetching/prerendering via the WP 6.8+ Speculation Rules API.
		 *
		 * Excludes sensitive/dynamic paths (login, admin, REST API) from all
		 * speculation and pins an explicit configuration whenever the plugin owns
		 * the speculation-rules decision: the user's chosen mode/eagerness when
		 * the UI toggle is on, or the legacy `conservative` default when it is
		 * off (so core's WP 7.1 cached-site escalation cannot change behavior
		 * behind the user's back).
		 *
		 * Effective defaults are `prefetch` + `conservative` unless overridden
		 * via WP_SPECULATIVE_LOADING_DEFAULT_MODE / _EAGERNESS (WP 7.1,
		 * wp_get_speculation_rules_default_configuration()). The
		 * wp_speculation_rules_configuration filter (used here) takes precedence
		 * over host constants (see filter_speculation_rules_configuration()).
		 * No auto-elevation to `moderate` is assumed — it must be chosen
		 * explicitly in the UI.
		 *
		 * @since NEXT
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
				function ( $exclude_paths ) use ( $preload_settings ) {
					$exclude_paths[] = '/wp-login.php';
					$exclude_paths[] = '/wp-admin/*';
					$exclude_paths[] = '/wp-json/*';

					$custom_excludes = ! empty( $preload_settings['speculationExcludeUrls'] )
						? Util::process_urls( $preload_settings['speculationExcludeUrls'] )
						: array();
					foreach ( $custom_excludes as $exclude ) {
						// Convert bare paths to wildcard pattern via WP_URL_Pattern_Prefixer when available (WP 6.8+).
						if ( class_exists( 'WP_URL_Pattern_Prefixer' ) && method_exists( 'WP_URL_Pattern_Prefixer', 'prefix_path_pattern' ) ) {
							// Core helper adds /* for path prefix patterns; guard already wildcarded.
							if ( false === strpos( $exclude, '*' ) && '/' === $exclude[0] ) {
								$exclude = \WP_URL_Pattern_Prefixer::prefix_path_pattern( $exclude, '/' );
							}
						} elseif ( '/' === $exclude[0] && false === strpos( $exclude, '*' ) ) {
							// Fallback: ensure wildcard for path prefix.
							$exclude = rtrim( $exclude, '/' ) . '/*';
						}
						if ( ! in_array( $exclude, $exclude_paths, true ) ) {
							$exclude_paths[] = $exclude;
						}
					}

					$woocommerce_excludes = array();

					if ( function_exists( 'wc_get_checkout_url' ) ) {
						$checkout_url = wc_get_checkout_url();
						if ( $checkout_url ) {
							$path = wp_parse_url( $checkout_url, PHP_URL_PATH );
							if ( $path && '/' !== $path ) {
								$woocommerce_excludes[] = trailingslashit( $path ) . '*';
							}
						}

						$cart_url = wc_get_cart_url();
						if ( $cart_url ) {
							$path = wp_parse_url( $cart_url, PHP_URL_PATH );
							if ( $path && '/' !== $path ) {
								$woocommerce_excludes[] = trailingslashit( $path ) . '*';
							}
						}

						$myaccount_url = wc_get_page_permalink( 'myaccount' );
						if ( $myaccount_url ) {
							$path = wp_parse_url( $myaccount_url, PHP_URL_PATH );
							if ( $path && '/' !== $path ) {
								$woocommerce_excludes[] = trailingslashit( $path ) . '*';
							}
						}
					}

					foreach ( $woocommerce_excludes as $exclude ) {
						if ( ! in_array( $exclude, $custom_excludes, true ) ) {
							$exclude_paths[] = $exclude;
						}
					}

					return $exclude_paths;
				}
			);

			add_filter(
				'wp_speculation_rules_configuration',
				function ( $config ) use ( $preload_settings, $enable_speculation ) {
					return $this->filter_speculation_rules_configuration( $config, $preload_settings, $enable_speculation );
				}
			);
		}

		/**
		 * Applies the plugin's explicit speculation-rules configuration via the
		 * `wp_speculation_rules_configuration` filter.
		 *
		 * WordPress 7.1 escalates the default eagerness from `conservative` to
		 * `moderate` when it detects a caching solution (#64066). This plugin is
		 * a caching solution, so that escalation could change speculative-loading
		 * behavior behind the user's back. Whenever the plugin owns the
		 * speculation-rules decision it therefore pins an explicit eagerness:
		 * the user's chosen value when the UI toggle is on, or the legacy
		 * `conservative` default when it is off. The explicit
		 * `WP_SPECULATIVE_LOADING_DEFAULT_MODE` /
		 * `WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS` constants or environment
		 * variables introduced in WP 7.1 (#65624) are honored as-is, so hosts
		 * can still pin a different default.
		 *
		 * @since 1.9.0
		 *
		 * @param array<string,string>|null $config            Filter value ('auto' defaults, or null when speculative loading is disabled for the request).
		 * @param array                     $preload_settings  The plugin's preload_settings option value.
		 * @param bool                      $enable_speculation Whether the plugin's speculation-rules UI toggle is on.
		 * @return array<string,string>|null
		 */
		public function filter_speculation_rules_configuration( $config, array $preload_settings, bool $enable_speculation ) {
			if ( ! is_array( $config ) ) {
				return $config;
			}

			if ( $enable_speculation ) {
				$mode      = $preload_settings['speculationMode'] ?? 'prefetch';
				$eagerness = $preload_settings['speculationEagerness'] ?? 'conservative';
				// Validate via WP_Speculation_Rules when available (WP 6.8+), fallback to allowlist.
				if ( class_exists( 'WP_Speculation_Rules' ) ) {
					if ( method_exists( 'WP_Speculation_Rules', 'is_valid_mode' ) && ! \WP_Speculation_Rules::is_valid_mode( $mode ) ) {
						$mode = 'prefetch';
					}
					if ( method_exists( 'WP_Speculation_Rules', 'is_valid_eagerness' ) && ! \WP_Speculation_Rules::is_valid_eagerness( $eagerness ) ) {
						$eagerness = 'conservative';
					}
				} else {
					if ( ! in_array( $mode, array( 'prefetch', 'prerender' ), true ) ) {
						$mode = 'prefetch';
					}
					if ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
						$eagerness = 'conservative';
					}
				}
				$config['mode']      = $mode;
				$config['eagerness'] = $eagerness;
				return $config;
			}

			// Toggle off: preserve the pre-7.1 conservative default so core's
			// cached-site escalation (#64066) cannot override the plugin UI.
			// Only pins when the plugin's own static cache is active — that is
			// the caching solution core's detection heuristic would see, so a
			// site where caching is detected elsewhere keeps core's behavior.
			// A host that explicitly pinned a different default is left alone.
			if (
				! empty( $this->options['cache_settings']['enableCache'] ) &&
				'auto' === ( $config['eagerness'] ?? 'auto' ) &&
				null === $this->get_speculation_default_override( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' )
			) {
				$config['eagerness'] = 'conservative';
			}

			return $config;
		}

		/**
		 * Read-only lookup of a WP 7.1 speculative-loading default override.
		 *
		 * Mirrors core's `wp_get_speculative_loading_override()` precedence
		 * (constant over environment variable) without depending on that private
		 * core function. Reads configuration only; never writes environment
		 * variables or constants.
		 *
		 * Only treats the value as an override when it is one of the values core
		 * accepts for that setting (mirroring the validation in core's
		 * `wp_get_speculation_rules_configuration()`). An invalid or empty value
		 * is treated as absent so the plugin's conservative pin still applies
		 * instead of silently allowing core's cached-site escalation.
		 *
		 * @since 1.9.0
		 *
		 * @param string $name Override name, e.g. 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS'.
		 * @return string|null The override value, or null when neither the constant nor the environment variable is set to a valid value.
		 */
		private function get_speculation_default_override( string $name ): ?string {
			$value = null;

			if ( function_exists( 'getenv' ) ) {
				$env_value = getenv( $name );
				if ( false !== $env_value ) {
					$value = $env_value;
				}
			}

			if ( defined( $name ) ) {
				$const_value = constant( $name );
				if ( is_string( $const_value ) ) {
					$value = $const_value;
				}
			}

			if ( ! is_string( $value ) || '' === $value ) {
				return null;
			}

			if ( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' === $name ) {
				if ( ! in_array( $value, array( 'prefetch', 'prerender' ), true ) ) {
					return null;
				}
			} elseif ( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' === $name ) {
				if ( ! in_array( $value, array( 'conservative', 'moderate', 'eager' ), true ) ) {
					return null;
				}
			}

			return $value;
		}

		/**
		 * Strips version query strings from enqueued static asset URLs.
		 *
		 * Removing `?ver=` from CSS/JS URLs lets proxies, CDNs, and browsers cache
		 * the files more effectively. Cache-busting is retained where the plugin
		 * rewrites URLs (minify/combine) because those already embed the file
		 * modification time; this only affects URLs that still carry a `ver` arg
		 * and are not served from the plugin's own `cache/wppo` directories.
		 *
		 * @since NEXT
		 *
		 * @param string $src    The asset source URL.
		 * @param string $handle The script/style handle.
		 * @return string The URL without its version query string.
		 */
		public function strip_static_query_strings( $src, $handle ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			if ( empty( $src ) ) {
				return $src;
			}

			$parsed = wp_parse_url( $src );
			if ( ! isset( $parsed['query'] ) || '' === $parsed['query'] ) {
				return $src;
			}

			// Never strip the ver arg from the plugin's own cache files (minified
			// or combined assets). Those URLs are versioned with the file mtime at
			// enqueue time, so stripping it would let browsers serve stale copies
			// of regenerated files.
			if ( $this->is_plugin_cache_url( $src ) ) {
				return $src;
			}

			// Remove only the ver component(s) from the raw query, keeping every
			// other part byte-for-byte identical: duplicate keys, percent-encoding
			// and ordering survive (parse_str/http_build_query would collapse
			// duplicates and re-encode values).
			$kept      = array();
			$found_ver = false;
			foreach ( explode( '&', $parsed['query'] ) as $component ) {
				$eq  = strpos( $component, '=' );
				$key = false !== $eq ? substr( $component, 0, $eq ) : $component;
				if ( 'ver' === rawurldecode( $key ) ) {
					$found_ver = true;
					continue;
				}
				$kept[] = $component;
			}

			if ( ! $found_ver ) {
				return $src;
			}

			// Replace only the query portion inside the original string so relative
			// and protocol-relative sources, ports and fragments stay intact.
			$fragment = '';
			$head     = $src;
			$frag_pos = strpos( $src, '#' );
			if ( false !== $frag_pos ) {
				$fragment = substr( $src, $frag_pos );
				$head     = substr( $src, 0, $frag_pos );
			}

			$query_pos = strpos( $head, '?' );
			if ( false === $query_pos ) {
				return $src;
			}

			$base = substr( $head, 0, $query_pos );

			if ( empty( $kept ) ) {
				// No remaining args: drop the '?' entirely.
				return $base . $fragment;
			}

			return $base . '?' . implode( '&', $kept ) . $fragment;
		}

		/**
		 * Whether a URL points at the plugin's own cache directories.
		 *
		 * Origin-aware so a third-party URL whose path merely contains
		 * `/cache/wppo/` is not exempted. Relative and protocol-relative URLs
		 * match on the content path prefix alone (no host to compare).
		 *
		 * This runs on every enqueued JS/CSS file via `script_loader_src` /
		 * `style_loader_src`, so the parsed `content_url()` host/path parts are
		 * statically cached per blog. The cache is bypassed while a `content_url`
		 * filter is registered (the filter may return context-dependent output),
		 * so the caching benefit only applies to installs without such a filter.
		 *
		 * @since NEXT
		 *
		 * @param string $src The asset source URL.
		 * @return bool
		 */
		private function is_plugin_cache_url( string $src ): bool {
			// Skip caching while a content_url filter is registered: the filter may
			// return context-dependent output, so the resolved host/prefix cannot be
			// safely reused (same convention as Util::cached_content_url()).
			if ( false !== has_filter( 'content_url' ) ) {
				return $this->is_plugin_cache_url_uncached( $src );
			}

			static $cache = array();
			$blog_id      = get_current_blog_id();

			if ( ! isset( $cache[ $blog_id ] ) ) {
				// Resolve the base via the shared helper (which also centralizes the
				// has_filter( 'content_url' ) bypass), then cache the parsed parts.
				$content_url  = Util::cached_content_url( '/' );
				$content_path = (string) wp_parse_url( $content_url, PHP_URL_PATH );
				$cache_host   = wp_parse_url( $content_url, PHP_URL_HOST );

				$cache[ $blog_id ] = array(
					'prefix' => rtrim( $content_path, '/' ) . '/cache/wppo',
					'host'   => $cache_host,
				);
			}

			$parsed = wp_parse_url( $src );
			$path   = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';

			if ( isset( $parsed['host'] ) ) {
				if ( null !== $cache[ $blog_id ]['host'] && 0 !== strcasecmp( (string) $parsed['host'], (string) $cache[ $blog_id ]['host'] ) ) {
					return false;
				}
			}

			return 0 === strpos( $path, $cache[ $blog_id ]['prefix'] );
		}

		/**
		 * Filter-respecting variant of {@see is_plugin_cache_url()}.
		 *
		 * Resolves content_url() on every call so a registered `content_url` filter
		 * is honored per invocation. Only used while such a filter is active.
		 *
		 * @since NEXT
		 *
		 * @param string $src The asset source URL.
		 * @return bool
		 */
		private function is_plugin_cache_url_uncached( string $src ): bool {
			$content_url  = Util::cached_content_url( '/' );
			$content_path = (string) wp_parse_url( $content_url, PHP_URL_PATH );
			$prefix       = rtrim( $content_path, '/' ) . '/cache/wppo';

			$parsed = wp_parse_url( $src );
			$path   = isset( $parsed['path'] ) ? (string) $parsed['path'] : '';

			if ( isset( $parsed['host'] ) ) {
				$cache_host = wp_parse_url( $content_url, PHP_URL_HOST );
				if ( null !== $cache_host && 0 !== strcasecmp( (string) $parsed['host'], (string) $cache_host ) ) {
					return false;
				}
			}

			return 0 === strpos( $path, $prefix );
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
		 * Rewrites enqueued styles to their minified versions at enqueue time and
		 * registers the on-disk path so core can inline them.
		 *
		 * The inline-styles `path` data mechanism exists since WordPress 5.8
		 * (`wp_maybe_inline_styles()` / the `styles_inline_size_limit` filter; the
		 * default budget was raised from 20KB to 40KB in 6.9). This runs on
		 * `wp_enqueue_scripts` (before core's inline pass at `wp_head` priority 1)
		 * so minified files can opt in to inlining. Falls back to the
		 * `style_loader_tag` rewriting in {@see minify_css()} on older WordPress
		 * versions.
		 *
		 * @since 1.9.0
		 * @return void
		 */
		public function minify_queued_styles(): void {
			if ( ! function_exists( 'wp_maybe_inline_styles' ) ) {
				return;
			}

			if ( ! $this->should_optimise_for_logged_in() ) {
				return;
			}

			// The combine feature owns the whole pipeline; let it handle these handles.
			if ( ! empty( $this->options['file_optimisation']['combineCSS'] ) ) {
				return;
			}

			global $wp_styles;

			if ( ! is_object( $wp_styles ) ) {
				return;
			}

			foreach ( $wp_styles->queue as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}

				// Preserve auto-sizes containment fix (WP 6.9+) — must not be minified/combined.
				if ( 'wp-img-auto-sizes-contain' === $handle && function_exists( 'wp_enqueue_img_auto_sizes_contain_css_fix' ) ) {
					continue;
				}

				$style_data = $wp_styles->registered[ $handle ];

				// Only external, 'all'-media stylesheets can be rewritten to a file.
				if ( ! isset( $style_data->args ) || 'all' !== $style_data->args ) {
					continue;
				}

				$local_path = $this->get_minifiable_css_path( $handle, $style_data->src );
				if ( false === $local_path ) {
					continue;
				}

				$css_minifier = new Minify\CSS( $local_path, Util::min_cache_dir( 'css' ) );
				$cached_url   = $css_minifier->minify();

				if ( empty( $cached_url ) ) {
					continue;
				}

				$cached_file = $css_minifier->get_cache_file_path();
				if ( empty( $cached_file ) || ! file_exists( $cached_file ) ) {
					continue;
				}

				$wp_styles->registered[ $handle ]->src = $cached_url;
				$wp_styles->registered[ $handle ]->ver = (int) filemtime( $cached_file );

				// Opt the minified file in to core's inline pass.
				wp_style_add_data( $handle, 'path', $cached_file );
			}
		}

		/**
		 * Returns the local path of a style eligible for CSS minification.
		 *
		 * Shared by the enqueue-time rewrite ({@see minify_queued_styles()}) and the
		 * legacy `style_loader_tag` path ({@see minify_css()}) so the eligibility
		 * decision cannot drift between the two. The 'all'-media check only matters
		 * at enqueue time and stays in {@see minify_queued_styles()}; the tag-time
		 * path rewrites every media type as before.
		 *
		 * @since 1.9.0
		 *
		 * @param string $handle Style handle.
		 * @param string $src    Style source URL.
		 * @return string|false The local file path if the style should be minified,
		 *                      false otherwise.
		 */
		private function get_minifiable_css_path( $handle, $src ) {
			if ( empty( $src ) || in_array( $handle, $this->exclude_css, true ) ) {
				return false;
			}

			$local_path = Util::get_local_path( $src );
			if ( empty( $local_path ) ) {
				return false;
			}

			if ( apply_filters( 'wppo_exclude_minification', false, $local_path, $handle, 'css' ) ) {
				return false;
			}

			// Early return if the URL already indicates a minified file.
			if ( $this->is_minified_asset_name( $src, 'css' ) ) {
				return false;
			}

			if ( $this->is_css_minified( $local_path ) ) {
				return false;
			}

			return $local_path;
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
			// Preserve auto-sizes containment fix (WP 6.9+) — must not be minified.
			if ( 'wp-img-auto-sizes-contain' === $handle && function_exists( 'wp_enqueue_img_auto_sizes_contain_css_fix' ) ) {
				return $tag;
			}
			// LiteSpeed safe coexistence — when LSCache owns optimization, skip WPPO minify.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			// Early return for logged-in users (when optimisation not enabled) to avoid
			// the expensive Util::get_local_path() computation.
			if ( ! $this->should_optimise_for_logged_in() ) {
				return $tag;
			}

			// Handles already rewritten at enqueue time carry 'path' data pointing at
			// the plugin's own min cache. Only those are exempt — path data registered
			// by core or third parties must still fall through to legacy minification.
			global $wp_styles;
			if ( isset( $wp_styles ) ) {
				$path_data = $wp_styles->get_data( $handle, 'path' );
				$min_dir   = Util::min_cache_base_dir();
				if ( ! empty( $path_data ) && 0 === strpos( wp_normalize_path( $path_data ), $min_dir ) ) {
					return $tag;
				}
			}

			$local_path = $this->get_minifiable_css_path( $handle, $href );
			if ( false === $local_path ) {
				return $tag;
			}

			$css_minifier = new Minify\CSS( $local_path, Util::min_cache_dir( 'css' ) );
			$cached_file  = $css_minifier->minify();

			if ( $cached_file ) {
				$basename         = basename( $cached_file );
				$content_url      = Util::min_cache_url( 'css', $basename );
				$cached_file_path = $css_minifier->get_cache_file_path();

				if ( empty( $cached_file_path ) || ! file_exists( $cached_file_path ) ) {
					return $tag;
				}

				$file_version = filemtime( $cached_file_path );
				if ( false === $file_version ) {
					return $tag;
				}

				$new_href = $content_url . '?ver=' . $file_version;
				$new_tag  = str_replace( $href, $new_href, $tag );
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
			// LiteSpeed safe coexistence — when LSCache owns optimization, skip WPPO minify.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
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

			$js_minifier = new Minify\JS( $local_path, Util::min_cache_dir( 'js' ) );
			$cached_file = $js_minifier->minify();

			if ( $cached_file ) {
				$basename         = basename( $cached_file );
				$content_url      = Util::min_cache_url( 'js', $basename );
				$cached_file_path = $js_minifier->get_cache_file_path();

				if ( empty( $cached_file_path ) || ! file_exists( $cached_file_path ) ) {
					return $tag;
				}

				$file_version = filemtime( $cached_file_path );
				if ( false === $file_version ) {
					return $tag;
				}

				$new_src = $content_url . '?ver=' . $file_version;
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

			// Acquire a shared read lock to avoid reading a partially-written file.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			if ( ! flock( $handle, LOCK_SH ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
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
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			flock( $handle, LOCK_UN );
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
		 * @since 1.9.0
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
