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
		 * Memoized delay-JS exclusions with `wppo_exclude_delay_js` applied
		 * at call time rather than at bootstrap. Null until first use.
		 *
		 * @var   array|null
		 * @since 2.2.0
		 */
		private ?array $resolved_delay_exclusions = null;

		/**
		 * Per-page compat preset opt-out removal list (issue #1308).
		 *
		 * Computed in apply_per_page_delay_config() (gated on globally-enabled
		 * presets, manual/safe entries protected) and re-applied in
		 * get_delay_exclusions() AFTER the `wppo_exclude_delay_js` filter, so
		 * filter-then-subtract ordering holds on the external path too and a
		 * filter entry matching a preset string cannot silently nullify the
		 * page opt-out. Null when no opt-out applies.
		 *
		 * @var   array|null
		 * @since 2.2.0
		 */
		private ?array $page_preset_opt_out_remove = null;

		/**
		 * Default delay strategy: 'interaction', 'idle', or 'viewport'.
		 *
		 * @var   string
		 * @since 2.0.0
		 */
		private string $delay_js_default_strategy = 'interaction';

		/**
		 * List of script handles/URLs to load via requestIdleCallback.
		 *
		 * @var   array
		 * @since 2.0.0
		 */
		private array $delay_js_idle_list = array();

		/**
		 * List of script handles/URLs to load when in viewport.
		 *
		 * @var   array
		 * @since 2.0.0
		 */
		private array $delay_js_viewport_list = array();

		/**
		 * Handles explicitly pinned to the interaction strategy for the
		 * current page via the Asset Manager `_wppo_delay_strategies` meta.
		 *
		 * The per-page config encodes an interaction override by
		 * removing the handle from the idle/viewport lists, which is
		 * otherwise indistinguishable from "never listed" — so the auto
		 * third-party idle upgrade (#1314) would silently promote a
		 * page-pinned interaction script to idle. This list preserves the
		 * explicit choice so get_delay_strategy_for_handle() can honor
		 * per-page-wins precedence before the auto-idle branch.
		 *
		 * @since 2.2.0
		 * @var array<int, string>
		 */
		private array $delay_js_per_page_interaction = array();

		/**
		 * Map of script handles/URLs to priority ('high', 'normal', 'low').
		 *
		 * @var   array<string, string>
		 * @since 2.0.0
		 */
		private array $delay_js_priority = array();

		/**
		 * Idle callback timeout in milliseconds (default 3000).
		 *
		 * @var   int
		 * @since 2.0.0
		 */
		private int $delay_js_idle_timeout = 3000;

		/**
		 * Whether delay-JS is disabled for the current singular page.
		 *
		 * Set by apply_per_page_delay_config() from the `_wppo_delay_disabled`
		 * post meta escape hatch (issue #966). Checked in add_defer_attribute().
		 *
		 * @var   bool
		 * @since 2.0.0
		 */
		private bool $delay_disabled_for_page = false;

		/**
		 * Whether defer-JS is disabled for the current singular page.
		 *
		 * Set by apply_per_page_delay_config() from the `_wppo_defer_disabled`
		 * post meta escape hatch (issue #1098). Checked in add_defer_strategy()
		 * and add_defer_attribute_legacy().
		 *
		 * @var   bool
		 * @since 2.2.0
		 */
		private bool $defer_disabled_for_page = false;

		/**
		 * Per-request cache for the `_wppo_defer_disabled` kill-switch lookups.
		 *
		 * Keyed by `blog_id:post_id` so multisite `switch_to_blog()` contexts
		 * never leak one site's kill-switch state into another site sharing the
		 * same post ID (#1098). Cleared per key by
		 * {@see invalidate_aggressive_kill_switch_cache()}.
		 *
		 * @var array<string, bool>
		 * @since 2.2.0
		 */
		private static array $defer_disabled_page_cache = array();

		/**
		 * Per-request record of emitted font preload links (normalized URL).
		 *
		 * `add_preload_prefetch_preconnect()` may run more than once per
		 * request; this guard keeps auto-discovered font hints (issue #1216)
		 * to at most one tag per URL. Reset via
		 * {@see reset_font_preload_emitted()} (tests, switch_blog).
		 *
		 * @var array<string,bool>
		 * @since 2.2.0
		 */
		private static array $font_preload_emitted = array();

		/**
		 * Per-request memo of stylesheet file stamps (src => "mtime:size").
		 *
		 * `get_auto_discovered_font_urls()` stats every queued handle to
		 * build the transient key (issue #1216); this memo keeps re-entrant
		 * `wp_head` emissions from repeating realpath/filemtime/filesize
		 * syscalls in one request. Reset via
		 * {@see reset_font_preload_emitted()} (tests, switch_blog).
		 *
		 * @var array<string,string>
		 * @since 2.2.0
		 */
		private static array $font_stamp_memo = array();

		/**
		 * Per-request memo of resolved auto-font lists (cache key => URLs).
		 *
		 * Short-circuits the transient read + re-validation on re-entrant
		 * calls within one request (issue #1216). Keyed by the full
		 * transient key (queue state), so queue changes naturally miss.
		 * Reset via {@see reset_font_preload_emitted()}.
		 *
		 * @var array<string,string[]>
		 * @since 2.2.0
		 */
		private static array $auto_fonts_memo = array();

		/**
		 * Maximum auto-discovered font preloads per page (manual wins).
		 *
		 * @since 2.2.0
		 */
		private const MAX_AUTO_FONT_PRELOADS = 2;

		/**
		 * Associative array of deferred script handles (keyed by handle for O(1) lookups).
		 *
		 * @var   array<string, bool>
		 * @since 2.0.0
		 */
		private array $deferred_handles = array();

		/**
		 * Per-request memo of speculation list URL validity.
		 *
		 * Keyed by URL + home URL + Woo-function fingerprint; avoids
		 * re-running Woo URL lookups and wp_parse_url triplets when the
		 * same candidates are validated by get_speculation_list_urls(),
		 * get_prerender_list_urls(), and the register/filter paths on a
		 * single request. Bounded (200 entries) for long-running
		 * processes. Reset via reset_speculation_url_memo() (tests).
		 *
		 * @since 2.2.0
		 * @var array<string, bool>
		 */
		private static array $speculation_url_validity_memo = array();

		/**
		 * Per-request memo of resolved speculation commerce paths.
		 *
		 * Null = not computed yet. Fingerprinted on Woo-function
		 * availability so test fixtures defining wc_get_* after a first
		 * resolution still see the new paths.
		 *
		 * @since 2.2.0
		 * @var string[]|null
		 */
		private static ?array $speculation_commerce_paths_memo = null;

		/**
		 * Woo-function fingerprint the commerce-path memo was computed for.
		 *
		 * @since 2.2.0
		 * @var string
		 */
		private static string $speculation_commerce_paths_sig = '';

		/**
		 * Per-request memo of normalized speculation URLs.
		 *
		 * @since 2.2.0
		 * @var array<string, string>
		 */
		private static array $speculation_normalize_memo = array();

		/**
		 * Per-request memo of RUM top URLs keyed by fill limit.
		 *
		 * Reset via reset_speculation_url_memo() (tests).
		 *
		 * @since 2.2.0
		 * @var array<int, string[]>
		 */
		private static array $speculation_rum_top_memo = array();

		/**
		 * Whether the object-path prerender rule was already added.
		 *
		 * Idempotency backstop for repeated `wp_load_speculation_rules`
		 * firings on objects without `has_rule()`. Instance state so the
		 * single production instance stays guarded while test instances
		 * stay isolated.
		 *
		 * @since 2.2.0
		 * @var bool
		 */
		private bool $speculation_prerender_object_added = false;

		/**
		 * Cache instance for static HTML cache operations.
		 *
		 * @var   Cache|null
		 * @since 2.0.0
		 */
		private ?Cache $cache = null;

		/**
		 * Filesystem instance for file operations.
		 *
		 * Set to false transiently by Util::init_filesystem() before the
		 * constructor normalizes a failed init to null.
		 *
		 * @var   \WP_Filesystem_Base|false|null
		 * @since 1.0.0
		 */
		private \WP_Filesystem_Base|false|null $filesystem = null;

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
		 * @since 2.0.0
		 */
		private Google_Fonts $google_fonts;

		/**
		 * Options for performance optimisation settings.
		 *
		 * REF-007: lazily resolved by {@see self::get_options()} on first use
		 * instead of eagerly in the constructor. Null until the first read.
		 * Frontend/read paths must use the accessor so `switch_to_blog()`
		 * re-resolution and post-save invalidation apply; only the
		 * `maybe_migrate_*()` admin routines touch this property directly
		 * (same-blog, read-then-persist by design).
		 *
		 * @var   array|null
		 * @since 1.0.0
		 */
		private ?array $options = null;

		/**
		 * Blog ID the memoized `$options` were resolved for.
		 *
		 * Null until the first resolution (or when `$options` was injected
		 * directly, e.g. in tests — adopted on the next accessor read). A
		 * mismatch triggers re-resolution so multisite `switch_to_blog()`
		 * contexts never reuse another site's snapshot; the underlying
		 * `Settings_Store` memo is blog-keyed for the same reason.
		 *
		 * @var   int|null
		 * @since 2.4.0
		 */
		private ?int $options_blog_id = null;

		/**
		 * Lazily-created settings-migration runner (ARCH-004).
		 *
		 * Owns the 17 `maybe_migrate_*()` backfill bodies (see
		 * {@see Settings_Migrations}); `Main` keeps thin proxies so
		 * `Hook_Registry` hook registrations stay byte-identical. Null
		 * until the first migration proxy runs.
		 *
		 * @var   Settings_Migrations|null
		 * @since 2.4.0
		 */
		private ?Settings_Migrations $settings_migrations = null;

		/**
		 * Lazily-created script-strategy runner (ARCH-005).
		 *
		 * Single owner for the defer/delay cluster; every script-strategy
		 * proxy delegates here so `Hook_Registry` callback identity is unchanged.
		 *
		 * @since 2.4.0
		 * @var   Script_Strategy|null
		 */
		private ?Script_Strategy $script_strategy = null;

		/**
		 * Lazily-created minification policy owner (P3-014).
		 *
		 * Main keeps the public hook callbacks and exclusion/optimization state;
		 * the policy owns queue/tag transforms and minified-file checks.
		 *
		 * @var   Minify\Minify_Policy|null
		 * @since NEXT
		 */
		private ?Minify\Minify_Policy $minify_policy = null;

		/**
		 * Lazily-created preload/buffer coordination owner (P3-015).
		 *
		 * Main keeps every public callback signature and Hook_Registry callback
		 * identity while the bounded buffer lifecycle and scheduling seams live
		 * behind a dependency-light coordinator.
		 *
		 * @var   Preload_Buffer_Coordinator|null
		 * @since NEXT
		 */
		private ?Preload_Buffer_Coordinator $preload_buffer_coordinator = null;

		/**
		 * Timestamp (microtime) when the front-end template render started.
		 *
		 * @var   float
		 * @since 1.9.0
		 */
		private float $server_timing_template_start = 0.0;

		/**
		 * The most recently constructed Main instance.
		 *
		 * The plugin bootstraps a single Main per request (`new Main()` in the
		 * plugin entry file); the reference lets the deactivation routine remove
		 * instance-method hooks symmetrically (see
		 * {@see Deactivate::unregister_runtime_hooks()}).
		 *
		 * @var   Main|null
		 * @since 2.0.0
		 */
		private static ?Main $instance = null;

		/**
		 * Per-request cache for the `_wppo_delay_disabled` kill-switch lookups.
		 *
		 * Keyed by `blog_id:post_id` so multisite `switch_to_blog()` contexts
		 * never leak one site's kill-switch state into another site sharing the
		 * same post ID (#1037). Cleared per key by
		 * {@see invalidate_delay_kill_switch_cache()}.
		 *
		 * @var array<string, bool>
		 * @since 2.0.0
		 */
		private static array $delay_disabled_page_cache = array();

		/**
		 * Get the current Main instance (null before construction / in tests).
		 *
		 * @since 2.0.0
		 * @return Main|null
		 */
		public static function get_instance(): ?Main {
			return self::$instance;
		}

		/**
		 * Clear the tracked Main instance (test isolation).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function reset_instance(): void {
			self::$instance = null;
		}

		/**
		 * Get the resolved plugin options (lazy).
		 *
		 * P3-013 delegates effective resolution (stored `wppo_settings` over
		 * canonical defaults plus all historical in-memory backfills) to
		 * {@see Settings_Store::get_resolved_settings()}. Main keeps this public
		 * facade and a local injectable/mutable snapshot for existing migration
		 * and collaborator seams. The result is memoized per blog ID:
		 * `switch_to_blog()` mismatches re-resolve automatically.
		 *
		 * Behavior-preserving relocation (no boot perf claim): the constructor
		 * still calls this once for its collaborators (`Image_Optimisation`,
		 * `Google_Fonts`, `Hook_Registry`, `Core_Tweaks`), which need a resolved
		 * snapshot at registration time — that single call is the only eager
		 * resolution left, and `new Main()` at file load is unchanged (no
		 * lifecycle change). Deferring those collaborators would change the
		 * hook-registration lifecycle and is out of scope.
		 *
		 * @since 2.4.0
		 * @since NEXT Delegates effective resolution to Settings_Store.
		 * @return array Resolved plugin options.
		 */
		public function get_options(): array {
			$blog_id = Settings_Store::current_blog_id();
			if ( null !== $this->options && null === $this->options_blog_id ) {
				// Resolved snapshot injected directly (tests/back-compat):
				// adopt the current blog tag instead of re-resolving.
				$this->options_blog_id = $blog_id;
				return $this->options;
			}
			if ( null === $this->options || $blog_id !== $this->options_blog_id ) {
				$this->options         = Settings_Store::get_resolved_settings();
				$this->options_blog_id = $blog_id;
			}
			return $this->options;
		}

		/**
		 * Invalidate the memoized options so the next read re-resolves.
		 *
		 * Keeps the {@see self::get_options()} memo coherent with settings
		 * writes: {@see self::on_settings_update()} clears the singleton memo
		 * when `wppo_settings` changes, so same-request post-save reads observe
		 * the write instead of the pre-save snapshot. Passing `$settings`
		 * adopts it directly (avoids a re-read); omitting it drops the memo so
		 * the next {@see self::get_options()} call re-resolves (with backfills).
		 *
		 * @since 2.4.0
		 * @param array|null $settings Optional resolved settings to adopt. Null drops the memo.
		 * @return void
		 */
		public function refresh_options( ?array $settings = null ): void {
			Settings_Store::invalidate_resolved_settings();
			if ( null === $settings ) {
				$this->options         = null;
				$this->options_blog_id = null;
				return;
			}
			$this->options         = $settings;
			$this->options_blog_id = Settings_Store::current_blog_id();
		}

		/**
		 * Invalidate the singleton options memo when `wppo_settings` is added.
		 *
		 * First-time seeds (`add_option()`) bypass `update_option_wppo_settings`;
		 * without this the constructor snapshot would shadow the seed for the
		 * rest of the request. Registered in
		 * {@see Hook_Registry::register_invalidation_hooks()}.
		 *
		 * @since 2.4.0
		 * @param string $option Option name.
		 * @param mixed  $value  Option value.
		 * @return void
		 */
		public static function on_settings_add( $option, $value ): void {
			unset( $option, $value );
			Settings_Store::invalidate_resolved_settings();
			if ( null !== self::$instance ) {
				self::$instance->refresh_options();
			}
		}

		/**
		 * Create the static-cache collaborator with a test seam.
		 *
		 * Applies the `wppo_cache_instance` filter so tests (and advanced
		 * integrations) can stub the Cache collaborator. Collaborators are
		 * constructor-injected (REF-006): when live instances are supplied
		 * they are shared by identity; when omitted, the Cache builds the
		 * equivalents lazily from the resolved options on first buffer use,
		 * so purge/read-only callers holding no live instances pay no
		 * construction cost for collaborators they never touch.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Accepts optional collaborator overrides for constructor injection.
		 * @param array                   $options            Plugin options passed to Cache.
		 * @param Image_Optimisation|null $image_optimisation Live collaborator, or null to build the equivalent lazily from the resolved options.
		 * @param Google_Fonts|null       $google_fonts       Live collaborator, or null to build the equivalent lazily from the resolved options.
		 * @return mixed Cache instance (or filtered stub).
		 */
		public static function create_cache( array $options, ?Image_Optimisation $image_optimisation = null, ?Google_Fonts $google_fonts = null ) {
			return Cache_Coordinator::create( $options, $image_optimisation, $google_fonts );
		}

		/**
		 * Constructor.
		 *
		 * Initializes the class by including necessary files and setting up hooks.
		 *
		 * @since 1.0.0
		 */
		public function __construct() {
			// Track the instance so {@see Deactivate::unregister_runtime_hooks()} can
			// perform symmetric remove_action() calls for instance-method callbacks
			// during deactivation (audit #888 finding 1).
			self::$instance = $this;

			// Register the wppo_settings memo invalidation hooks eagerly at plugin
			// boot so cache invalidation is deterministic for the whole request
			// regardless of when get_settings() is first called (audit #888
			// finding 4). get_settings() keeps a lazy backstop registration.
			Settings_Store::register_settings_cache_hooks();

			// REF-007: resolved options come from get_options() (lazy,
			// memoized per blog ID; resolution + backfills moved there verbatim).
			// This single eager resolution stays because the constructor
			// sub-steps below need a resolved snapshot at registration time:
			// Image_Optimisation/Google_Fonts (option-gated hook setup),
			// Hook_Registry (registration gates via setup_hooks()), and
			// Core_Tweaks. Deferring those collaborators would change the
			// hook-registration lifecycle and is out of scope, so this
			// behavior-preserving relocation makes no boot-I/O perf claim.
			$options = $this->get_options();

			$this->includes();
			$this->image_optimisation         = new Image_Optimisation( $options );
			$this->google_fonts               = new Google_Fonts( $options );
			$this->preload_buffer_coordinator = new Preload_Buffer_Coordinator(
				fn(): array => $this->get_options(),
				$this->image_optimisation,
				$this->google_fonts,
				static fn( array $file_optimisation ): bool => self::is_safe_mode_active( $file_optimisation ),
				static fn(): bool => self::is_aggressive_bypass_active()
			);
			$this->setup_hooks();
			$this->filesystem = Util::init_filesystem();
			if ( ! $this->filesystem ) {
				$this->filesystem = null;
			}

			if ( defined( 'WP_ADMIN' ) ) {
				new Admin_Notices();
			}

			$file_optimisation_opts = $options['file_optimisation'] ?? array();
			if ( ! is_array( $file_optimisation_opts ) ) {
				// Normalize the source option too: the preset branch below
				// writes $options['file_optimisation']['heartbeatControl'],
				// which fatals on a corrupted non-array (e.g. string from a bad
				// import) unless the write target is an array as well.
				$file_optimisation_opts       = array();
				$options['file_optimisation'] = array();
				$this->options                = $options;
			}
			// INP-first preset (#932): one-click 60s heartbeat via the existing
			// disable_heartbeat path. In-memory only — an explicit user choice
			// (disable_all/disable_ext) always wins, never overridden.
			if ( ! empty( $file_optimisation_opts['delayJSINPPreset'] ) && 'default' === ( $file_optimisation_opts['heartbeatControl'] ?? 'default' ) ) {
				$options['file_optimisation']['heartbeatControl'] = '60s';
				$file_optimisation_opts['heartbeatControl']       = '60s';
				$this->options                                    = $options;
			}
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
			$options = $this->get_options();
			return Util::is_cache_eligible_for_current_user(
				$options['cache_settings'] ?? array()
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
				$enable = ! empty( $this->get_options()['cache_settings']['enableLoggedInCache'] ?? false );
				if ( $enable ) {
					// is_string guard below: an array-valued cookie
					// (?wppo_role_hash[]=x) would otherwise reach
					// wp_unslash()/sanitize_text_field() as an array and
					// fatal on PHP 8.2+.
					$user        = wp_get_current_user();
					$hash        = Util::get_role_hash( $user );
					$cookie_hash = isset( $_COOKIE['wppo_role_hash'] ) && is_string( $_COOKIE['wppo_role_hash'] ) ? sanitize_text_field( wp_unslash( $_COOKIE['wppo_role_hash'] ) ) : null;
					if ( '' !== $hash && ( null === $cookie_hash || $cookie_hash !== $hash ) ) {
						if ( ! headers_sent() ) {
							setcookie(
								'wppo_role_hash',
								$hash,
								array(
									'expires'  => time() + DAY_IN_SECONDS,
									'path'     => COOKIEPATH,
									'domain'   => COOKIE_DOMAIN,
									'secure'   => is_ssl(),
									'httponly' => true,
									'samesite' => 'Lax',
								)
							);
						}
					}
				}
			}
		}

		/**
		 * Clear the wppo_role_hash cookie on logout.
		 *
		 * @since 1.9.0
		 * @return void
		 */
		public function clear_role_hash_cookie(): void {
			if ( isset( $_COOKIE['wppo_role_hash'] ) && ! headers_sent() ) {
				setcookie(
					'wppo_role_hash',
					'',
					array(
						'expires'  => time() - YEAR_IN_SECONDS,
						'path'     => COOKIEPATH,
						'domain'   => COOKIE_DOMAIN,
						'secure'   => is_ssl(),
						'httponly' => true,
						'samesite' => 'Lax',
					)
				);
			}
		}

		/**
		 * Include required files.
		 *
		 * The Composer autoloader is already loaded by the plugin entry file
		 * (performance-optimisation.php) before Main is instantiated, so only
		 * the plugin class files are included here.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function includes(): void {
			// Loader map first (ARCH-003): the single owner of "which file
			// provides which class". Every path below delegates to it so a
			// future directory move touches exactly one file.
			if ( ! class_exists( 'PerformanceOptimise\Inc\Loader_Map', false ) ) {
				$loader_map_file = WPPO_PLUGIN_PATH . 'includes/Core/class-loader-map.php';
				if ( file_exists( $loader_map_file ) ) {
					require_once $loader_map_file;
				}
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
			}

			// Eager files in Loader_Map order, starting with the WP version
			// gate (REF-010: no dependencies, so every version-gated class
			// below can use it). The vendor Action Scheduler lib above is not
			// under `includes/` so it stays wired here directly.
			foreach ( Loader_Map::eager_files() as $eager_file ) {
				$eager_path = Loader_Map::file_path( $eager_file );
				if ( '' !== $eager_path && file_exists( $eager_path ) ) {
					require_once $eager_path;
				}
			}
			// Modularity (issue #1443): the LiteSpeed-only crawler + ESI stack
			// is parsed only when it can act. Non-LiteSpeed frontend requests
			// never pay the parse + memory cost. Fail-open: when detection is
			// unavailable the stack still loads (today's behaviour). Method-level
			// fail-closed checks (is_litespeed(), is_esi_available()) stay as
			// defence in depth. Server-agnostic classes (AI_Adaptive,
			// Edge_Cache, Edge_Purger, CDN, ...) are always loaded above.
			// The decision stays here in Main; Loader_Map owns only the file list.
			if ( self::should_load_litespeed_stack() ) {
				foreach ( Loader_Map::litespeed_stack_files() as $stack_file ) {
					$stack_path = Loader_Map::file_path( $stack_file );
					if ( '' !== $stack_path && file_exists( $stack_path ) ) {
						require_once $stack_path;
					}
				}
			}

			// Fallback loader for lazily-loaded classes when the Composer
			// classmap is stale or unavailable (partial release builds). Lazy:
			// registered as an spl autoloader so healthy requests pay nothing on
			// the hot path — a file is required only on an actual missing-class
			// failure. Eager classes above are intentionally omitted from the
			// map (except where Loader_Map already lists them) to avoid
			// duplicate probes. LiteSpeed_Crawler + LiteSpeed_ESI stay in the
			// map so late callers (cron, abilities, integration info) still
			// resolve them via autoload when includes() skipped the eager load
			// on non-LiteSpeed requests.
			spl_autoload_register(
				static function ( $class_name ): void {
					$prefix = 'PerformanceOptimise\\Inc\\';
					if ( 0 !== strpos( (string) $class_name, $prefix ) ) {
						return;
					}
					$short = substr( (string) $class_name, strlen( $prefix ) );
					if ( class_exists( $class_name, false ) ) {
						return;
					}
					$fallback_path = Loader_Map::path_for( $short );
					if ( null !== $fallback_path && file_exists( $fallback_path ) ) {
						require_once $fallback_path;
					}
				}
			);

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$cli_file = Loader_Map::cli_file();
				if ( '' !== $cli_file && file_exists( $cli_file ) ) {
					require_once $cli_file;
				}
				if ( class_exists( 'PerformanceOptimise\Inc\WPPO_CLI_Command', false ) ) {
					\WP_CLI::add_command( 'wppo', 'PerformanceOptimise\Inc\WPPO_CLI_Command' );
				}
			}
		}

		/**
		 * Whether the LiteSpeed-only crawler + ESI stack should be loaded.
		 *
		 * Pay-only-for-what-you-use gate for server-specific code: frontend
		 * requests on Apache/Nginx skip parsing the crawler + ESI classes.
		 * Management contexts (WP-CLI, cron, admin, REST) always load so
		 * late callers (cron batches, abilities, integration info) keep
		 * working. Fail-open: any detection error returns true (today's
		 * always-load behaviour) — never fatal. Multisite-safe: pure server
		 * detection, no options or transients touched. Server detection honours
		 * the documented `wppo_litespeed_is_litespeed` filter via
		 * LiteSpeed_Integration::is_litespeed().
		 *
		 * @internal Exposed for Hook_Registry (registration gate); not part
		 *           of the public plugin API.
		 *
		 * @since 2.3.0
		 * @return bool True when the LiteSpeed stack should be required.
		 */
		public static function should_load_litespeed_stack(): bool {
			try {
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					return true;
				}
				if ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() ) {
					return true;
				}
				if ( function_exists( 'is_admin' ) && is_admin() ) {
					return true;
				}
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					return true;
				}
				// Prefer the filtered detector so the documented
				// `wppo_litespeed_is_litespeed` filter keeps working (e.g. proxy-header
				// overrides): LiteSpeed_Integration::is_litespeed() delegates to
				// Server_Rules plus the filter, so default behaviour is unchanged.
				// LiteSpeed_Integration is always required just above.
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_litespeed' ) ) {
					return LiteSpeed_Integration::is_litespeed();
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'is_litespeed' ) ) {
					return Server_Rules::is_litespeed();
				}
				// Fallback when Server_Rules is unavailable: raw
				// SERVER_SOFTWARE substring check (mirrors
				// LiteSpeed_Integration::is_litespeed() fallback).
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized below via wp_unslash()/sanitize_text_field() with function_exists() fallbacks.
				$raw = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
				if ( '' !== $raw && function_exists( 'wp_unslash' ) ) {
					$raw = (string) wp_unslash( $raw );
				}
				if ( '' !== $raw && function_exists( 'sanitize_text_field' ) ) {
					$raw = (string) sanitize_text_field( $raw );
				}
				$s = strtolower( $raw );
				// Note: 'openlitespeed' contains 'litespeed', so a single strpos
				// covers both variants.
				return false !== strpos( $s, 'litespeed' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Setup WordPress hooks.
		 *
		 * Delegates hook registration to {@see Hook_Registry} so the hook
		 * manifest stays auditable in one place (REF-005). Main keeps
		 * collaborator construction, option-state preparation, and every
		 * feature callback; registration order and behavior are unchanged.
		 *
		 * @return void
		 * @since  1.0.0
		 */
		private function setup_hooks(): void {
			( new Hook_Registry( $this, $this->get_options(), $this->image_optimisation, $this->google_fonts ) )->register();
		}

		/**
		 * Get or create the static-HTML cache collaborator for hook registration.
		 *
		 * @internal For Hook_Registry use only. Centralizes the two
		 *           cache-creation sites previously inline in setup_hooks()
		 *           (the unconditional enableCache assignment and the
		 *           combineCSS `if ( ! $this->cache )` fallback). The `$force`
		 *           flag replicates the enableCache branch semantics exactly:
		 *           force recreates (first site), default keeps an existing
		 *           instance (second site).
		 *
		 * @since 2.4.0
		 * @param bool $force Whether to recreate even when a cache exists.
		 * @return mixed Cache instance (or filtered stub).
		 */
		public function ensure_hook_cache_for_registry( bool $force = false ): mixed {
			if ( $force || ! $this->cache ) {
				// Constructor injection (REF-006): share the live collaborator
				// instances by identity so the buffer pipeline can never
				// diverge onto a stale options snapshot.
				$this->cache = self::create_cache( $this->get_options(), $this->image_optimisation, $this->google_fonts );
			}
			return $this->cache;
		}

		/**
		 * Prepare minify exclusion lists for hook registration.
		 *
		 * @internal For Hook_Registry use only. Verbatim relocation of the
		 *           exclusion-list preparation previously inline in
		 *           setup_hooks() (registration itself lives in the registry).
		 *
		 * @since 2.4.0
		 * @param string $kind Either 'js' or 'css'.
		 * @return void
		 */
		public function prepare_minify_excludes_for_registry( string $kind ): void {
			if ( 'css' === $kind ) {
				if ( ! empty( $this->get_options()['file_optimisation']['excludeCSS'] ) ) {
					$exclude_css       = Util::process_urls( $this->get_options()['file_optimisation']['excludeCSS'] );
					$this->exclude_css = array_merge( $this->exclude_css, (array) $exclude_css );
				}
				$cve_handles = $this->get_cve_guard_handles();
				if ( ! empty( $cve_handles ) ) {
					$this->exclude_css = array_values( array_unique( array_merge( $this->exclude_css, $cve_handles ) ) );
				}
				return;
			}

			if ( ! empty( $this->get_options()['file_optimisation']['excludeJS'] ) ) {
				$exclude_js = Util::process_urls( $this->get_options()['file_optimisation']['excludeJS'] );

				$this->exclude_js = array_merge( $this->exclude_js, (array) $exclude_js );
			}
			$cve_handles = $this->get_cve_guard_handles();
			if ( ! empty( $cve_handles ) ) {
				$this->exclude_js = array_values( array_unique( array_merge( $this->exclude_js, $cve_handles ) ) );
			}
		}

		/**
		 * Prepare defer/delay script-exclusion and strategy state for hook registration.
		 *
		 * @internal For Hook_Registry use only. Verbatim relocation of the
		 *           adjacent defer-JS and delay-JS preparation blocks
		 *           previously inline in setup_hooks() (neither block
		 *           registers hooks; registration lives in the registry).
		 *           Runs at the exact original position so filter firing
		 *           order is unchanged.
		 *
		 * @since 2.4.0
		 * @param array $staged_for_registration Staged sandbox settings widening registration.
		 * @return void
		 */
		public function prepare_defer_delay_state_for_registry( array $staged_for_registration ): void {
			if ( ! empty( $this->get_options()['file_optimisation']['deferJS'] ) ) {
				$exclude_js = array( 'wppo-lazyload' );
				if ( ! empty( $this->get_options()['file_optimisation']['excludeDeferJS'] ) ) {
					$exclude_defer          = Util::process_urls( $this->get_options()['file_optimisation']['excludeDeferJS'] );
					$this->exclude_defer_js = array_merge( $exclude_js, (array) $exclude_defer );
				} else {
					$this->exclude_defer_js = $exclude_js;
				}
				// Curated defer preset (issue #1098): jQuery/Elementor/Woo stay
				// un-deferred by default. Filterable via
				// wppo_defer_js_preset_exclusions. Preserves user excludes via
				// array_unique merge.
				$this->exclude_defer_js = array_values( array_unique( array_merge( $this->exclude_defer_js, self::get_defer_js_preset_exclusions() ) ) );
				// Sandbox preview (issue #1163): staged excludeDeferJS lines also
				// suppress defer in preview only. Fail-open: matcher errors keep
				// the production list. Staged presence (no auth gate) widens the
				// list at registration; the per-tag guards enforce preview-only.
				if ( ! empty( $staged_for_registration['excludeDeferJS'] ) ) {
					try {
						$staged_defer_excludes  = Util::process_urls( $staged_for_registration['excludeDeferJS'] );
						$this->exclude_defer_js = array_values( array_unique( array_merge( $this->exclude_defer_js, (array) $staged_defer_excludes ) ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_exclude_defer_js' ) ) {
					try {
						$this->exclude_defer_js = (array) apply_filters( 'wppo_exclude_defer_js', $this->exclude_defer_js );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				} else {
					$this->exclude_defer_js = apply_filters( 'wppo_exclude_defer_js', $this->exclude_defer_js );
				}
				$cve_handles = $this->get_cve_guard_handles();
				if ( ! empty( $cve_handles ) ) {
					$this->exclude_defer_js = array_values( array_unique( array_merge( $this->exclude_defer_js, $cve_handles ) ) );
				}
			}

			if ( ! empty( $this->get_options()['file_optimisation']['delayJS'] ) ) {
				$exclude_js = array( 'wppo-lazyload' );
				if ( ! empty( $this->get_options()['file_optimisation']['excludeDelayJS'] ) ) {
					$exclude_delay          = Util::process_urls( $this->get_options()['file_optimisation']['excludeDelayJS'] );
					$this->exclude_delay_js = array_merge( $exclude_js, (array) $exclude_delay );
				} else {
					$this->exclude_delay_js = $exclude_js;
				}
				// Merge curated preset exclusions (jquery, recaptcha, stripe, analytics, etc.)
				// Filterable via wppo_delay_js_exclusions. Preserves user excludes via array_unique merge.
				$preset                 = $this->get_delay_js_preset_exclusions();
				$this->exclude_delay_js = array_values( array_unique( array_merge( $this->exclude_delay_js, $preset ) ) );

				// When both defer JS and delay JS are active, deferred handles must not
				// be delay-rewritten (wppo-src / wppo/javascript) — otherwise delay JS
				// overrides the native defer strategy on WP 6.3+ and corrupts script
				// attributes. Merge the defer exclusions so add_defer_attribute() and
				// apply_per_page_delay_config() skip deferred scripts entirely.
				if ( ! empty( $this->get_options()['file_optimisation']['deferJS'] ) ) {
					$this->exclude_delay_js = array_merge( $this->exclude_delay_js, $this->exclude_defer_js );
				}
				// Sandbox preview (issue #1163): staged excludeDelayJS lines also
				// suppress the external-script delay rewrite in preview only
				// (the inline path is overlaid in Minify\HTML). Fail-open.
				// Staged presence (no auth gate) widens the list at
				// registration; the per-tag guards enforce preview-only.
				if ( ! empty( $staged_for_registration['excludeDelayJS'] ) ) {
					try {
						$staged_delay_excludes  = Util::process_urls( $staged_for_registration['excludeDelayJS'] );
						$this->exclude_delay_js = array_values( array_unique( array_merge( $this->exclude_delay_js, (array) $staged_delay_excludes ) ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				$this->exclude_delay_js = apply_filters( 'wppo_exclude_delay_js', $this->exclude_delay_js );
				$cve_handles            = $this->get_cve_guard_handles();
				if ( ! empty( $cve_handles ) ) {
					$this->exclude_delay_js = array_values( array_unique( array_merge( $this->exclude_delay_js, $cve_handles ) ) );
				}

				// Parse delay strategy lists.
				$file_opt = $this->get_options()['file_optimisation'];

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

				// INP-first preset (#932): one-click idle-first default so delayed
				// scripts yield to input; an explicit non-interaction manual default
				// wins, and the viewport list is still honored per-handle.
				// In-memory effective value only — the stored option is untouched
				// so disabling the preset falls back to interaction-only.
				if ( ! empty( $file_opt['delayJSINPPreset'] ) ) {
					$stored_default = isset( $file_opt['delayJSDefaultStrategy'] ) ? strtolower( trim( (string) $file_opt['delayJSDefaultStrategy'] ) ) : '';
					if ( '' === $stored_default || 'interaction' === $stored_default ) {
						$this->delay_js_default_strategy = 'idle';
					}
				}
			}
		}

		/**
		 * Instantiate collaborator services (metabox, cron, assets, abilities).
		 *
		 * Extracted from {@see setup_hooks()}; keeps Main as a thin
		 * bootstrapper over collaborator registration.
		 *
		 * @internal For Hook_Registry use only: invoked at the exact
		 *           original position so collaborator hook registration
		 *           order is unchanged.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public function register_collaborators(): void {
			new Metabox();
			new Cron();
			new Asset_Manager();
			new Abilities();
		}
		/**
		 * Lazily-created settings-migration runner (ARCH-004, P3-016).
		 *
		 * Single owner for the 17 migration backfills; every
		 * `maybe_migrate_*()` proxy delegates here so `Hook_Registry`
		 * callback identity is unchanged. The runner receives only the
		 * effective-options reader and invalidation command it needs.
		 *
		 * @since 2.4.0
		 * @return Settings_Migrations Migration runner bound to this application seam.
		 */
		private function migrations(): Settings_Migrations {
			if ( null === $this->settings_migrations ) {
				$this->settings_migrations = new Settings_Migrations(
					function (): array {
						return $this->get_options();
					},
					function ( array $settings ): void {
						$effective = array_replace_recursive( $this->get_options(), $settings );
						$this->refresh_options( $effective );
					}
				);
			}
			return $this->settings_migrations;
		}

		/**
		 * Lazily-created script-strategy runner (ARCH-005).
		 *
		 * Single owner for the defer/delay cluster; every script-strategy
		 * proxy delegates here (instance methods) or directly to
		 * `Script_Strategy` (static methods) so `Hook_Registry` callback
		 * identity is unchanged.
		 *
		 * @since 2.4.0
		 * @return Script_Strategy Strategy runner bound to this instance.
		 */
		private function script_strategy(): Script_Strategy {
			if ( null === $this->script_strategy ) {
				$this->script_strategy = new Script_Strategy( $this );
			}
			return $this->script_strategy;
		}

		/**
		 * Lazily-created minification policy owner (P3-014).
		 *
		 * @since NEXT
		 * @return Minify\Minify_Policy Policy owner bound to this instance.
		 */
		private function minify_policy(): Minify\Minify_Policy {
			if ( null === $this->minify_policy ) {
				$this->minify_policy = new Minify\Minify_Policy( $this );
			}
			return $this->minify_policy;
		}

		/**
		 * Expose the live logged-in optimisation gate to Minify_Policy.
		 *
		 * @internal
		 * @since NEXT
		 * @return bool Whether optimisation applies to this viewer.
		 */
		public function minify_should_optimise_for_logged_in(): bool {
			return $this->should_optimise_for_logged_in();
		}

		/**
		 * Expose the live CSS minification exclusions to Minify_Policy.
		 *
		 * @internal
		 * @since NEXT
		 * @return array<int, string> CSS exclusion handles/URLs.
		 */
		public function minify_css_exclusions(): array {
			return $this->exclude_css;
		}

		/**
		 * Expose the live JS minification exclusions to Minify_Policy.
		 *
		 * @internal
		 * @since NEXT
		 * @return array<int, string> JS exclusion handles/URLs.
		 */
		public function minify_js_exclusions(): array {
			return $this->exclude_js;
		}

		/**
		 * Expose the core on-demand block-asset gate to Minify_Policy.
		 *
		 * @internal
		 * @since NEXT
		 * @param string $handle Registered asset handle.
		 * @return bool Whether core owns this handle conditionally.
		 */
		public function minify_is_core_block_asset_skipped( string $handle ): bool {
			return $this->is_core_block_asset_skipped( $handle );
		}

		/**
		 * Direct reference to script-strategy hook state `$exclude_defer_js` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_exclude_defer_js() {
			return $this->exclude_defer_js;
		}

		/**
		 * Direct reference to script-strategy hook state `$exclude_delay_js` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_exclude_delay_js() {
			return $this->exclude_delay_js;
		}

		/**
		 * Direct reference to script-strategy hook state `$resolved_delay_exclusions` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_resolved_delay_exclusions() {
			return $this->resolved_delay_exclusions;
		}

		/**
		 * Direct reference to script-strategy hook state `$page_preset_opt_out_remove` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_page_preset_opt_out_remove() {
			return $this->page_preset_opt_out_remove;
		}

		/**
		 * Direct reference to script-strategy hook state `$delay_js_default_strategy` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_delay_js_default_strategy() {
			return $this->delay_js_default_strategy;
		}

		/**
		 * Direct reference to script-strategy hook state `$delay_js_idle_list` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_delay_js_idle_list() {
			return $this->delay_js_idle_list;
		}

		/**
		 * Direct reference to script-strategy hook state `$delay_js_viewport_list` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_delay_js_viewport_list() {
			return $this->delay_js_viewport_list;
		}

		/**
		 * Direct reference to script-strategy hook state `$delay_js_per_page_interaction` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_delay_js_per_page_interaction() {
			return $this->delay_js_per_page_interaction;
		}

		/**
		 * Direct reference to script-strategy hook state `$delay_js_priority` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_delay_js_priority() {
			return $this->delay_js_priority;
		}

		/**
		 * Direct reference to script-strategy hook state `$delay_disabled_for_page` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_delay_disabled_for_page() {
			return $this->delay_disabled_for_page;
		}

		/**
		 * Direct reference to script-strategy hook state `$defer_disabled_for_page` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_defer_disabled_for_page() {
			return $this->defer_disabled_for_page;
		}

		/**
		 * Direct reference to script-strategy hook state `$deferred_handles` (ARCH-005 internal bridge).
		 *
		 * Gives {@see Script_Strategy} the same live request-state access the
		 * relocated bodies had on `Main`. Audit note: only `Script_Strategy`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return mixed Reference to the live state.
		 */
		public function &script_state_deferred_handles() {
			return $this->deferred_handles;
		}

		/**
		 * Logged-in optimisation gate for the script-strategy cluster (ARCH-005 internal bridge).
		 *
		 * Same Tahoe semantics as the direct `$this->should_optimise_for_logged_in()`
		 * call the relocated bodies made on `Main`. Only `Script_Strategy`
		 * calls this.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when optimisation applies to the current viewer.
		 */
		public function script_should_optimise_for_logged_in(): bool {
			return $this->should_optimise_for_logged_in();
		}
		/**
		 * One-time upgrade for the block-assets toggle on WP 6.9+.
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_block_assets_setting(): void {
			$this->migrations()->migrate_block_assets_setting( function_exists( 'wp_load_classic_theme_block_styles_on_demand' ) );
		}

		/**
		 * One-time upgrade core for the block-assets toggle on WP 6.9+.
		 *
		 * Backward-compatibility shim (ARCH-004): delegates to
		 * {@see Settings_Migrations::migrate_block_assets_setting()} so reflective
		 * callers observe identical behavior.
		 *
		 * Retention note: intentionally kept despite looking unreachable —
		 * `tests/php/BlockAssetsMigrationTest.php` invokes it via reflection
		 * and external reflective callers may do the same. Do not remove in
		 * dead-code sweeps.
		 *
		 * @since 2.4.0 Delegates to Settings_Migrations (ARCH-004).
		 * @param bool $loads_separate_core_block_assets_on_demand Whether WP 6.9+ is active.
		 * @return void
		 */
		private function migrate_block_assets_setting( bool $loads_separate_core_block_assets_on_demand ): void {
			$this->migrations()->migrate_block_assets_setting( $loads_separate_core_block_assets_on_demand );
		}

		/**
		 * One-time backfill for the CCSS inline size cap.
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_ccss_max_size(): void {
			$this->migrations()->migrate_ccss_max_size();
		}

		/**
		 * One-time backfill for the Critical CSS user safelist (issue #1038).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_ccss_safelist(): void {
			$this->migrations()->migrate_ccss_safelist();
		}

		/**
		 * One-time backfill for the RUM-weighted CSS queue keys (issue #1164).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0 Also backfills the 25s `ccssGenTimeout` generation budget.
		 * @since 2.3.0 Also backfills the #1388 keys (`ccssInlineBudgetKb`, `ccssCommerceExclude`, `ccssChecksumRegen`).
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_css_queue_defaults(): void {
			$this->migrations()->migrate_css_queue_defaults();
		}

		/**
		 * One-time backfill for the RUM-weighted top-URL prefetch cap (issue #1183).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_speculation_top_urls(): void {
			$this->migrations()->migrate_speculation_top_urls();
		}

		/**
		 * One-time backfill for the high-value prerender list toggle (issue #1237).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_speculation_prerender_list(): void {
			$this->migrations()->migrate_speculation_prerender_list();
		}

		/**
		 * One-time backfill for the RUM beacon sample rate (issue #1214).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_rum_sample_rate(): void {
			$this->migrations()->migrate_rum_sample_rate();
		}

		/**
		 * One-time backfill for the missing-alt autofill toggle and the longest-edge downscale cap (issue #985).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_image_alt_edge_defaults(): void {
			$this->migrations()->migrate_image_alt_edge_defaults();
		}

		/**
		 * One-time backfill for the unified safe-mode kill switch (issue #1098).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_safe_mode(): void {
			$this->migrations()->migrate_safe_mode();
		}

		/**
		 * Per-request memo for is_elementor_built_page() (issue #1259).
		 *
		 * Both combine_css() and will_combine_css_inline() run the full
		 * builder detection on the same request; without a memo that is 2x
		 * queried-object lookups, up to 4x get_post_meta, and 2x
		 * is_elementor_context() calls. Keyed by resolved post ID
		 * ('post:{id}') or by queried object ('queried:{id}') when no
		 * explicit post ID was passed.
		 *
		 * @since 2.2.0
		 * @var array<string,bool>
		 */
		private static array $elementor_built_memo = array();

		/**
		 * Reset the Elementor-built memo (for tests).
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_elementor_memo(): void {
			self::$elementor_built_memo = array();
		}

		/**
		 * Native-only Elementor presence pre-gate (issue #1259).
		 *
		 * Centralizes the cheap class/constant/query-var predicate so
		 * combine_css() and will_combine_css_inline() cannot drift apart.
		 * Zero WP calls: class_exists() with autoload disabled, defined(),
		 * and isset() on $_GET only. Callers run the full guarded
		 * detection only when this returns true.
		 *
		 * Note: a bare `?elementor-preview=1` query var forces a skip on
		 * Elementor-active sites for any visitor appending it. Impact is
		 * performance-only (unoptimized markup served, no data or
		 * cache-write exposure); legit preview links need the bypass even
		 * before per-post builder meta is readable, so this stays lenient
		 * by design.
		 *
		 * Fail direction: detection failure degrades to "looks like
		 * Elementor" (true) so the full guarded detection runs and, failing
		 * that, combine is skipped while safe mode is on (perf-only cost
		 * instead of risking broken Elementor layout/FOUC).
		 *
		 * @since 2.2.0
		 * @return bool True when Elementor looks present on this request.
		 */
		public static function looks_like_elementor_request(): bool {
			try {
				if ( class_exists( 'Elementor\Plugin', false ) || defined( 'ELEMENTOR_VERSION' ) ) {
					return true;
				}
				if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Backfill the additive elementorSafeMode key (issue #1259).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_elementor_safe_mode(): void {
			$this->migrations()->migrate_elementor_safe_mode();
		}

		/**
		 * Whether Elementor-safe mode is active (issue #1259).
		 *
		 * When on (default), combine/inline step aside on Elementor-built
		 * pages. Fail-open to enabled when settings are unreadable so
		 * unknown builder markup degrades to uncombined (never broken).
		 * Every builder call is guarded; non-Elementor sites carry zero
		 * weight beyond two cheap array lookups.
		 *
		 * @since 2.2.0
		 *
		 * @param array $file_optimisation Optional `file_optimisation` settings slice.
		 * @return bool True when Elementor-safe mode is on.
		 */
		public static function is_elementor_safe_mode_active( array $file_optimisation = array() ): bool {
			try {
				if ( empty( $file_optimisation ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					try {
						$settings          = (array) Util::get_settings();
						$file_optimisation = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Absent key (pre-migration) means default-on.
				$enabled = ! array_key_exists( 'elementorSafeMode', $file_optimisation ) || ! empty( $file_optimisation['elementorSafeMode'] );
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_elementor_safe_mode_enabled' ) ) {
					try {
						$enabled = (bool) apply_filters( 'wppo_elementor_safe_mode_enabled', $enabled );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return $enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether the current page was built with Elementor (issue #1259).
		 *
		 * Lazy boot: class_exists() / function_exists() guards first so
		 * non-Elementor sites pay nothing. Checks the Elementor plugin class,
		 * version markers, per-post `_elementor_data` / `_elementor_edit_mode`
		 * meta for the resolved post, and `data-elementor-type` is left to
		 * markup-level callers. Detection failure degrades to skip (true)
		 * while safe mode is on (see fail-direction note below).
		 *
		 * Per-request memoized by resolved post ID: combine_css() and
		 * will_combine_css_inline() share one verdict per request.
		 *
		 * Single-post scope: only the resolved post's meta is inspected.
		 * Archives, home, loops (queried ID 0), Elementor Theme Builder
		 * archive/header/footer/popup contexts, and posts rendered inside
		 * another loop are not covered — pass an explicit $post_id for those
		 * contexts instead of relying on the queried-object fallback.
		 * Theme Builder templates, translated copies (different IDs/URLs),
		 * and non-builder consumers of builder templates are a known
		 * limitation: purging a template ID purges the template permalink,
		 * not its consumers. Hosts covering those contexts should pass an
		 * explicit post ID or override via the `wppo_is_elementor_page`
		 * filter (return non-null to force a verdict; receives the resolved
		 * post ID as 2nd arg and the raw caller $post_id as 3rd arg for BC).
		 *
		 * Fail direction: detection failure degrades to skip (true) while
		 * Elementor-safe mode is on — uncombined markup costs perf only,
		 * while combining through a detection failure risks broken
		 * Elementor layout/FOUC. With safe mode off, failure returns false
		 * (optimisations run).
		 *
		 * @since 2.2.0
		 *
		 * @param int|null $post_id Optional post ID (defaults to queried object;
		 *                          falls back to get_the_ID() only on singular
		 *                          views so archives/home never inherit a loop
		 *                          member's builder verdict).
		 * @param array    $file_opt Optional `file_optimisation` settings slice,
		 *                           forwarded to is_elementor_safe_mode_active()
		 *                           in the fail-safe path so a staged
		 *                           elementorSafeMode=off stays previewable.
		 * @return bool True when this looks like an Elementor-built page.
		 */
		public static function is_elementor_built_page( ?int $post_id = null, array $file_opt = array() ): bool {
			try {
				$resolved = self::resolve_elementor_post_id( $post_id );
				// Resolve first, filter second: the common no-ID path must
				// hand post-specific overrides the resolved ID (2nd arg), not
				// null. The raw $post_id rides along as a 3rd arg so legacy
				// two-arg callbacks keep working unchanged.
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_is_elementor_page' ) ) {
					try {
						$filtered = apply_filters( 'wppo_is_elementor_page', null, $resolved ?? $post_id, $post_id );
						if ( null !== $filtered ) {
							return (bool) $filtered;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// The exception path below returns a safe-mode-dependent
				// verdict, so the memo key carries the safe-mode bit: a
				// memoized production verdict must never be reused for a
				// staged elementorSafeMode=off preview (or vice versa).
				$safe_bit = self::is_elementor_safe_mode_active( $file_opt ) ? 's1' : 's0';
				$memo_key = ( null !== $resolved && $resolved > 0 ? 'post:' . $resolved : 'queried:0' ) . ':' . $safe_bit;
				if ( array_key_exists( $memo_key, self::$elementor_built_memo ) ) {
					return self::$elementor_built_memo[ $memo_key ];
				}
				$result                                  = self::detect_elementor_built_page( $resolved, $file_opt );
				self::$elementor_built_memo[ $memo_key ] = $result;
				return $result;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::elementor_safe_fallback( $file_opt );
			}
		}

		/**
		 * Resolve the Elementor post ID from explicit, queried, and loop sources (issue #1259).
		 *
		 * Queried-object ID first; the get_the_ID() loop fallback applies on
		 * singular views only — on archives/home/loop (queried ID 0)
		 * get_the_ID() returns whichever post the loop currently points at,
		 * so inheriting it would skip combine for a whole archive containing
		 * one Elementor post and memoize under a loop-position-dependent key.
		 * Fail-open to null when no ID resolves.
		 *
		 * @since 2.2.0
		 *
		 * @param int|null $post_id Explicit post ID (or null to resolve).
		 * @return int|null Resolved post ID, or null when unknown.
		 */
		private static function resolve_elementor_post_id( ?int $post_id ): ?int {
			if ( null !== $post_id && $post_id > 0 ) {
				return $post_id;
			}
			if ( function_exists( 'get_queried_object_id' ) ) {
				try {
					$qid = (int) get_queried_object_id();
					if ( $qid > 0 ) {
						return $qid;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'is_singular' ) && function_exists( 'get_the_ID' ) ) {
				try {
					if ( is_singular() ) {
						$loop_id = (int) get_the_ID();
						if ( $loop_id > 0 ) {
							return $loop_id;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return null;
		}

		/**
		 * Fail-safe verdict for Elementor detection failures (issue #1259).
		 *
		 * Detection failure degrades to skip (true) while safe mode is on —
		 * uncombined markup costs perf only, while combining through a
		 * detection failure risks broken Elementor layout/FOUC. With safe
		 * mode off, failure returns false (optimisations run).
		 *
		 * @since 2.2.0
		 *
		 * @param array $file_opt Optional `file_optimisation` settings slice.
		 * @return bool Safe-mode-active verdict (true on unreadable settings).
		 */
		private static function elementor_safe_fallback( array $file_opt = array() ): bool {
			try {
				return self::is_elementor_safe_mode_active( $file_opt );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Unmemoized Elementor-built detection (issue #1259).
		 *
		 * Split from is_elementor_built_page() so the memo wrapper stays
		 * trivial. Plugin presence is probed with cheap markers only
		 * (class_exists with autoload disabled, version constants,
		 * builder function markers — zero meta reads), then a SINGLE
		 * `get_post_meta` pair decides the verdict: the tiny
		 * `_elementor_edit_mode` value is checked first and the large
		 * `_elementor_data` blob (100KB–1MB, unserialize + memory spike)
		 * is fetched only on miss, so non-builder pages never pay the
		 * largest meta cost on the combine hot path. The
		 * Critical_CSS::is_elementor_context() precedent is deliberately
		 * not delegated to here: it performs its own meta reads (doubling
		 * memcache/DB payload plus the unserialize cost of the largest
		 * builder meta on the combine hot path) and treats any non-empty
		 * edit mode as a context, while this path requires a strict
		 * `'builder' === (string) $edit_mode` comparison so stale or
		 * third-party `_elementor_edit_mode` values never bypass combine.
		 *
		 * The `?elementor-preview` check applies uniformly (not only when
		 * the Critical_CSS class is loaded) so preview URLs behave the
		 * same in full and minimal boots — but only when Elementor looks
		 * active (cheap markers above): a bare preview query var on a
		 * non-Elementor site must not disable combine for any visitor
		 * appending it (perf-only kill-switch otherwise).
		 *
		 * Fail direction: unexpected failure degrades to skip (true) while
		 * safe mode is on (perf-only cost over broken-layout risk).
		 *
		 * @since 2.2.0
		 *
		 * @param int|null $resolved Resolved post ID (or null when unknown).
		 * @param array    $file_opt Optional `file_optimisation` settings slice,
		 *                           forwarded to is_elementor_safe_mode_active()
		 *                           in the fail-safe path so a staged
		 *                           elementorSafeMode=off stays previewable.
		 * @return bool True when this looks like an Elementor-built page.
		 */
		private static function detect_elementor_built_page( ?int $resolved, array $file_opt = array() ): bool {
			try {
				// Cheap plugin-active probe only — no meta reads, no autoload
				// scan (autoload disabled, matching looks_like_elementor_request()).
				$plugin_active = false;
				try {
					if ( class_exists( 'Elementor\Plugin', false ) || defined( 'ELEMENTOR_VERSION' ) ) {
						$plugin_active = true;
					} elseif ( function_exists( 'elementor_pro_load_plugin' ) ) {
						$plugin_active = true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Single meta-read pair for the resolved post: tiny
				// `_elementor_edit_mode` first, large `_elementor_data`
				// blob only on miss.
				if ( null !== $resolved && $resolved > 0 && function_exists( 'get_post_meta' ) ) {
					try {
						$edit_mode = get_post_meta( $resolved, '_elementor_edit_mode', true );
						if ( 'builder' === (string) $edit_mode ) {
							return true;
						}
						$data = get_post_meta( $resolved, '_elementor_data', true );
						if ( ! empty( $data ) ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return self::elementor_safe_fallback( $file_opt );
					}
					// Plugin active but this post carries no builder meta:
					// not a builder-built page — unless this is a preview.
					if ( ! $plugin_active ) {
						return false;
					}
				}
				// Preview bypass is gated on plugin-active markers: legit
				// Elementor preview links run on Elementor-active sites
				// (markers present), while a bare query var on a
				// non-Elementor site must not disable combine.
				if ( $plugin_active && isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::elementor_safe_fallback( $file_opt );
			}
		}

		/**
		 * Whether combine/inline must be skipped for this request (issue #1259).
		 *
		 * True only when Elementor-safe mode is on AND the current page is
		 * Elementor-built.
		 *
		 * Fail direction: detection failure degrades to skip (true) while
		 * safe mode is on — uncombined markup costs perf only, while
		 * combining through a detection failure risks broken Elementor
		 * layout/FOUC. With safe mode off, failure returns false.
		 *
		 * Single-post scope (inherited from is_elementor_built_page()): pass
		 * an explicit $post_id for archive/loop/Theme Builder contexts where
		 * the queried object is not the Elementor-built post.
		 *
		 * @since 2.2.0
		 *
		 * @param array    $file_optimisation Optional `file_optimisation` settings slice.
		 * @param int|null $post_id           Optional post ID forwarded to is_elementor_built_page().
		 * @return bool True when combine/inline must be skipped.
		 */
		public static function should_skip_combine_for_elementor( array $file_optimisation = array(), ?int $post_id = null ): bool {
			try {
				if ( ! self::is_elementor_safe_mode_active( $file_optimisation ) ) {
					return false;
				}
				return self::is_elementor_built_page( $post_id, $file_optimisation );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::elementor_safe_fallback( $file_optimisation );
			}
		}

		/**
		 * One-time backfill for automatic LCP hero preload + font discovery (issue #1216).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_preload_auto_defaults(): void {
			$this->migrations()->migrate_preload_auto_defaults();
		}

		/**
		 * Backfill the additive Redis outage status flag (issue #1233).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_object_cache_outage_flag(): void {
			$this->migrations()->migrate_object_cache_outage_flag();
		}

		/**
		 * One-time backfill for RUM-segmented speculation auto-tune (issue #1425).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.3.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_ai_speculation_autotune(): void {
			$this->migrations()->migrate_ai_speculation_autotune();
		}

		/**
		 * One-time backfill for anomaly detector v2 keys (issue #1313).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.3.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_ai_anomaly_v2(): void {
			$this->migrations()->migrate_ai_anomaly_v2();
		}

		/**
		 * One-time backfill for comment-image hardening (issue #1271).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_comment_image_hardening(): void {
			$this->migrations()->migrate_comment_image_hardening();
		}

		/**
		 * Backfill the additive builder purge watcher keys (issue #1288).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_builder_watcher(): void {
			$this->migrations()->migrate_builder_watcher();
		}

		/**
		 * Backfill the additive auto third-party delay key (issue #1314).
		 *
		 * Facade proxy (ARCH-004): logic lives in {@see Settings_Migrations};
		 * `Hook_Registry` hook registrations stay byte-identical.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Proxied to Settings_Migrations (ARCH-004).
		 * @return void
		 */
		public function maybe_migrate_third_party_auto(): void {
			$this->migrations()->migrate_third_party_auto();
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

			// One-time repair (audit #1325): legacy wppo_settings rows
			// created with autoload=yes keep the multi-tab array in
			// alloptions on every page load. Flip the flag in place (a
			// value-identical update_option() would early-return without
			// touching autoload, so update the row directly). Rows already
			// at 'no' or 'off' (WP 6.8+ explicit opt-outs) are left alone.
			// On SQL failure the version bump below is skipped so a later
			// request retries the repair instead of marking it done.
			try {
				global $wpdb;
				if ( isset( $wpdb->options ) ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time version-gated repair of a single known option row; value untouched.
					$repaired = $wpdb->query(
						$wpdb->prepare(
							"UPDATE {$wpdb->options} SET autoload = %s WHERE option_name = %s AND autoload NOT IN (%s, %s)",
							'no',
							'wppo_settings',
							'no',
							'off'
						)
					);
					if ( false === $repaired ) {
						return;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return;
			}

			update_option( 'wppo_version', WPPO_VERSION, false );
		}

		/**
		 * Callback for when plugin settings are updated.
		 *
		 * Drops the {@see self::get_options()} memo on the live instance so
		 * same-request post-save reads re-resolve (with backfills) instead of
		 * serving the pre-save snapshot. Covers every save path (REST, CLI,
		 * `Util::save_settings()`) because all of them persist via
		 * `update_option( 'wppo_settings' )`, which fires this hook.
		 *
		 * @param mixed $old_value The old option value.
		 * @param mixed $value     The new option value.
		 * @since 1.2.0
		 * @since 2.4.0 Invalidates the get_options() memo on the live instance.
		 */
		public static function on_settings_update( $old_value, $value ) {
			Settings_Store::invalidate_resolved_settings();
			if ( null !== self::$instance ) {
				self::$instance->refresh_options();
			}
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
			// convertImg gates the next-gen block: toggling it while server
			// rules + next-gen stay on must refresh .htaccess too.
			$old_convert     = isset( $old_value['image_optimisation']['convertImg'] ) ? (bool) $old_value['image_optimisation']['convertImg'] : false;
			$new_convert     = isset( $value['image_optimisation']['convertImg'] ) ? (bool) $value['image_optimisation']['convertImg'] : false;
			$convert_changed = $old_convert !== $new_convert;

			// Server gate: on Nginx (including multisite) .htaccess is
			// never evaluated — skip the write entirely (update_rules()
			// also gates itself) and never roll back the setting. Nginx
			// operators use the read-only snippet from the server_rules
			// REST endpoint instead.
			$skip_htaccess = class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'should_skip_htaccess_write' ) && Server_Rules::should_skip_htaccess_write();

			if ( $old_enable !== $new_enable ) {
				$ok = $skip_htaccess ? true : Htaccess_Handler::update_rules( $new_enable );

				// Log hint for OpenLiteSpeed operators: restart required.
				if ( $ok && $new_enable && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() ) {
					Log::add( __( 'Server rules updated on LiteSpeed — restart OpenLiteSpeed if changes do not appear immediately.', 'performance-optimisation' ) );
				}

				if ( ! $ok ) {
					// Rollback the setting if .htaccess update failed.
					$value['file_optimisation']['enableServerRules'] = $old_enable;

					// Prevent infinite loop by temporary removing the action.
					remove_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10 );
					Settings_Command::save( $value );
					add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );

					add_action( 'admin_notices', array( __CLASS__, 'render_htaccess_failure_notice' ) );
				}
			} elseif ( ( $nextgen_changed || $convert_changed ) && $new_enable ) {
				// Next-gen or convertImg toggle changed while server rules
				// remain enabled — refresh htaccess to add/remove next-gen block.
				// Skipped on Nginx (see the server gate above).
				$ok = $skip_htaccess ? true : Htaccess_Handler::update_rules( true );
				if ( $ok && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() ) {
					Log::add( __( 'Server rules updated on LiteSpeed — restart OpenLiteSpeed if changes do not appear immediately.', 'performance-optimisation' ) );
				}
				if ( ! $ok ) {
					// Failed refresh leaves the prior file intact (atomic
					// backup/restore inside update_rules()) — surface an
					// admin notice so the failure is visible, mirroring the
					// enable/disable branch above.
					add_action( 'admin_notices', array( __CLASS__, 'render_htaccess_failure_notice' ) );
				}
			}

			// Clear Google Fonts cache when the setting toggles.
			$old_gf   = $old_value['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			$new_gf   = $value['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			$old_sub  = $old_value['file_optimisation']['fontSubset'] ?? false;
			$new_sub  = $value['file_optimisation']['fontSubset'] ?? false;
			$old_subs = $old_value['file_optimisation']['fontSubsetSubsets'] ?? 'latin';
			$new_subs = $value['file_optimisation']['fontSubsetSubsets'] ?? 'latin';
			if ( $old_gf !== $new_gf || $old_sub !== $new_sub || $old_subs !== $new_subs ) {
				Google_Fonts::clear_font_cache();
			}
		}

		/**
		 * Render the admin notice for a failed .htaccess rules update.
		 *
		 * Shared by the enable/disable and next-gen-refresh branches so the
		 * message and ARIA contract cannot drift. `role="alert"` +
		 * `aria-live="assertive"` announce the failure immediately, matching
		 * the React NoticeBanner contract used across the SPA.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function render_htaccess_failure_notice(): void {
			echo '<div class="notice notice-error is-dismissible" role="alert" aria-live="assertive"><p>' . esc_html__( 'Performance Optimisation: Failed to update .htaccess rules. Please check file permissions.', 'performance-optimisation' ) . '</p></div>';
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
		 * Auto-purge minify + page cache after any plugin/theme update.
		 *
		 * Hooks `upgrader_process_complete` (priority 20, after the builder
		 * watcher). Only fires for `action=update` + `type=plugin|theme`;
		 * every other upgrade path (core, translation, install) is ignored.
		 * Fail-open: purge failure degrades to the current manual-clear
		 * behavior and is never fatal.
		 *
		 * @since 2.2.0
		 * @param mixed $upgrader   Upgrader instance (unused).
		 * @param mixed $hook_extra Update context (action/type/plugin/plugins/theme/themes).
		 * @return void
		 */
		public static function on_extension_update( $upgrader = null, $hook_extra = null ): void {
			unset( $upgrader );
			try {
				if ( ! is_array( $hook_extra ) ) {
					return;
				}
				if ( 'update' !== ( $hook_extra['action'] ?? '' ) ) {
					return;
				}
				if ( ! in_array( ( $hook_extra['type'] ?? '' ), array( 'plugin', 'theme' ), true ) ) {
					return;
				}
				$has_target = ! empty( $hook_extra['plugin'] ) || ! empty( $hook_extra['plugins'] ) || ! empty( $hook_extra['theme'] ) || ! empty( $hook_extra['themes'] ) || ! empty( $hook_extra['bulk'] );
				if ( ! $has_target ) {
					return;
				}
				self::clear_all_cache();
				// Couple used-CSS regen to theme updates with cooldown plus a
				// bounded targeted requeue (issue #1220). Builder-plugin
				// updates are handled by Builder_Purge_Watcher; theme updates
				// land here. Fail-open: guarded by class/method_exists and
				// internally cooldown-gated.
				try {
					$is_theme = 'theme' === ( $hook_extra['type'] ?? '' );
					if ( $is_theme && class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'request_targeted_regen' ) ) {
						Used_CSS::request_targeted_regen( 'theme-update' );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Queue a bounded targeted used-CSS regen after a theme switch (issue #1220).
		 *
		 * Runs alongside clear_all_cache on `switch_theme`. Cooldown-gated
		 * inside Used_CSS::request_targeted_regen(); no-op when
		 * removeUnusedCSS is off or Action Scheduler is unavailable.
		 * Fail-open: never throws.
		 *
		 * @param string $new_name  New theme name (unused).
		 * @param mixed  $new_theme New theme object (unused).
		 * @param mixed  $old_theme Old theme object (unused).
		 * @return void
		 * @since 2.2.0
		 */
		public static function on_theme_switch_used_css( $new_name = '', $new_theme = null, $old_theme = null ): void {
			unset( $new_name, $new_theme, $old_theme );
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Used_CSS', 'request_targeted_regen' ) ) {
					Used_CSS::request_targeted_regen( 'theme-switch' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Regenerate the advanced-cache.php drop-in when the home or site URL
		 * changes (domain migration).
		 *
		 * The canonical host is baked into the drop-in at create() time; without
		 * a re-bake every request would mismatch the stale host and silently
		 * run uncached. No cache clear here — the old-domain files are keyed
		 * under a different host directory and simply stop being served.
		 *
		 * @param mixed  $old_value Previous option value.
		 * @param mixed  $value     New option value.
		 * @param string $option    Option name.
		 * @return bool True when the drop-in is left in a correct state, false on
		 *              filesystem failure. Skipped (unchanged value, or
		 *              scheme/path-only change with an identical host) returns true.
		 * @since 2.0.0
		 */
		public static function on_site_url_change( $old_value = null, $value = null, $option = '' ): bool {
			if ( $old_value === $value ) {
				return true;
			}
			// Skip needless identical rewrites: a scheme- or path-only change
			// (http->https, trailing slash) leaves the canonical host
			// identical, so the baked drop-in is already correct.
			if ( function_exists( 'wp_parse_url' ) ) {
				$old_host = Util::normalize_cache_host( (string) wp_parse_url( (string) $old_value, PHP_URL_HOST ) );
				$new_host = Util::normalize_cache_host( (string) wp_parse_url( (string) $value, PHP_URL_HOST ) );
				if ( '' !== $old_host && $old_host === $new_host ) {
					return true;
				}
			}
			if ( ! Advanced_Cache_Handler::create() ) {
				do_action( 'wppo_debug_log', 'WPPO advanced-cache.php drop-in regeneration failed after ' . $option . ' change' );
				return false;
			}
			return true;
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

			$options       = Util::get_settings();
			$img_converter = new Img_Converter( $options );

			$source_path = wp_normalize_path( $args['source_path'] );
			$format      = sanitize_text_field( $args['format'] );

			// Allowlist containment for queued jobs: a tampered queue entry
			// must never reach the converter. Fail-open: skip the job, keep
			// the file intact.
			if ( method_exists( 'PerformanceOptimise\Inc\Img_Converter', 'is_path_in_allowlist' ) && ! Img_Converter::is_path_in_allowlist( $source_path ) ) {
				return;
			}

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
			// WooCommerce surgical path (issue #962): product / order / coupon
			// saves purge only affected URLs instead of the smart-purge fan-out.
			$post_type = null;
			if ( is_object( $post ) && isset( $post->post_type ) ) {
				$post_type = $post->post_type;
			} elseif ( function_exists( 'get_post_type' ) ) {
				$post_type = get_post_type( $post_id );
			}
			$woo_kind = null;
			if ( 'product' === $post_type ) {
				$woo_kind = 'product';
			} elseif ( 'product_variation' === $post_type ) {
				// Variation price/stock changes must purge the parent variable
				// product page, not fan out. Parent resolution never fatal.
				$parent_id = 0;
				if ( is_object( $post ) && isset( $post->post_parent ) ) {
					$parent_id = (int) $post->post_parent;
				} elseif ( function_exists( 'wp_get_post_parent_id' ) ) {
					try {
						$parent_id = (int) wp_get_post_parent_id( $post_id );
					} catch ( \Throwable $e ) {
						unset( $e );
						$parent_id = 0;
					}
				}
				if ( $parent_id > 0 ) {
					$woo_kind = 'product';
					$post_id  = $parent_id;
				}
			} elseif ( 'shop_order' === $post_type || 'shop_order_placehold' === $post_type || ( function_exists( 'wc_get_order_types' ) && in_array( $post_type, (array) wc_get_order_types(), true ) ) ) {
				$woo_kind = 'order';
			} elseif ( 'shop_coupon' === $post_type ) {
				$woo_kind = 'coupon';
			}
			if ( null !== $woo_kind && $this->cache && method_exists( $this->cache, 'invalidate_woo_object' ) ) {
				$this->cache->invalidate_woo_object( (int) $post_id, $woo_kind );
				if ( 'order' === $woo_kind || 'coupon' === $woo_kind ) {
					// Order/coupon permalinks are non-public: skip crawler-warm
					// below so no useless preload work is queued for auth-gated URLs.
					return;
				}
				if ( 'product' === $woo_kind ) {
					// Surgical purge already schedules regen for the product
					// permalink; skip the duplicate crawler-warm below.
					return;
				}
			} elseif ( $this->cache ) {
				$this->cache->invalidate_dynamic_static_html( $post_id );
			}

			// Crawler warm on smart purge when preloadSitemap enabled (P4).
			// P3-015: the cache-aware scheduling seam is owned by the dependency-
			// light coordinator; invalidation and callback identity stay on Main.
			$this->preload_buffer_coordinator->queue_crawler_warm_after_cache_invalidation( (int) $post_id );
		}

		/**
		 * Surgically invalidate cache when a WooCommerce product is updated.
		 *
		 * @param int $product_id Product ID.
		 * @return void
		 * @since 2.0.0
		 */
		public function on_woocommerce_product_updated( $product_id ): void {
			if ( ! $this->cache || ! method_exists( $this->cache, 'invalidate_woo_object' ) ) {
				return;
			}
			$this->cache->invalidate_woo_object( (int) $product_id, 'product' );
		}

		/**
		 * Surgically invalidate cache when a WooCommerce order is created/updated.
		 *
		 * Accepts either an order ID or a WC_Order object (hook signatures
		 * differ between `woocommerce_checkout_order_created` and
		 * `woocommerce_update_order` across WC versions).
		 *
		 * @param mixed $order Order ID or WC_Order object.
		 * @return void
		 * @since 2.0.0
		 */
		public function on_woocommerce_order_changed( $order ): void {
			if ( ! $this->cache || ! method_exists( $this->cache, 'invalidate_woo_object' ) ) {
				return;
			}
			$order_id = $order;
			if ( is_object( $order ) && method_exists( $order, 'get_id' ) ) {
				try {
					$order_id = $order->get_id();
				} catch ( \Throwable $e ) {
					unset( $e );
					return;
				}
			}
			$order_id = (int) $order_id;
			if ( $order_id <= 0 ) {
				return;
			}
			$this->cache->invalidate_woo_object( $order_id, 'order' );
		}

		/**
		 * Surgically invalidate cache when a WooCommerce coupon is saved.
		 *
		 * @param mixed $coupon Coupon ID or WC_Coupon object.
		 * @return void
		 * @since 2.0.0
		 */
		public function on_woocommerce_coupon_saved( $coupon ): void {
			if ( ! $this->cache || ! method_exists( $this->cache, 'invalidate_woo_object' ) ) {
				return;
			}
			$coupon_id = $coupon;
			if ( is_object( $coupon ) && method_exists( $coupon, 'get_id' ) ) {
				try {
					$coupon_id = $coupon->get_id();
				} catch ( \Throwable $e ) {
					unset( $e );
					return;
				}
			}
			$coupon_id = (int) $coupon_id;
			if ( $coupon_id <= 0 ) {
				return;
			}
			$this->cache->invalidate_woo_object( $coupon_id, 'coupon' );
		}

		/**
		 * Queue used-CSS generation when post content is saved.
		 *
		 * Skips revisions and autosaves, and checks the removeUnusedCSS setting
		 * before enqueueing. Uses atomic unique enqueue (issue #1310) to
		 * prevent duplicate jobs, with the legacy as_has_scheduled_action()
		 * guard as fallback.
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

			// Checksum-triggered CCSS regen (issue #1388): fires only when
			// the locally-available source CSS changed since generation; a
			// plain post save with unchanged CSS queues nothing. Runs
			// independently of the removeUnusedCSS gate below. Guarded with
			// class/method_exists plus legacy fallback (no-op when the CCSS
			// pipeline is unavailable). Fail-open inside, never fatal.
			if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'maybe_regen_on_save' ) ) {
				try {
					\PerformanceOptimise\Inc\Critical_CSS::maybe_regen_on_save( $post_id, $post );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// P3-015: preserve the post-save callback and critical-CSS order while
			// delegating the setting/Action Scheduler seam to the coordinator.
			$this->preload_buffer_coordinator->queue_used_css_regeneration( (int) $post_id );
		}

		/**
		 * Refresh the matching critical-CSS template after an LCP-triggered used-CSS refresh.
		 *
		 * In-repo consumer for the `wppo_ai_css_refresh_queued` action fired by
		 * AI_Adaptive::maybe_queue_css_refresh() (issue #1407): maps the queued
		 * post to its coarse template (`home` for the front page, `page` for
		 * pages, `single` otherwise) and regenerates that single template via
		 * Critical_CSS::regenerate_single(). Fail-open: any failure (unknown
		 * template, missing scheduler, throwable) is swallowed so the used-CSS
		 * job that already queued is never affected.
		 *
		 * @param string $url regressed URL.
		 * @param int    $post_id Queued post ID.
		 * @param array  $anomaly The firing LCP anomaly.
		 * @return void
		 * @since 2.3.0
		 */
		public function on_ai_css_refresh_queued( $url, $post_id, $anomaly ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			try {
				$post_id = (int) $post_id;
				if ( $post_id <= 0 ) {
					return;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) || ! method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'regenerate_single' ) ) {
					return;
				}
				$template = 'single';
				if ( is_string( $url ) && '' !== $url && class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) && method_exists( 'PerformanceOptimise\Inc\AI_Adaptive', 'is_homepage_url' ) ) {
					try {
						if ( AI_Adaptive::is_homepage_url( $url ) ) {
							$template = 'home';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( 'home' !== $template && function_exists( 'get_post_type' ) ) {
					try {
						if ( 'page' === get_post_type( $post_id ) ) {
							$template = 'page';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				try {
					\PerformanceOptimise\Inc\Critical_CSS::regenerate_single( $template );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
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
			return $this->preload_buffer_coordinator->process_used_css_only( $filtered_output, $output );
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
		 * @since  2.2.0 Streaming tradeoff note and cross-reference.
		 * @return bool True when Server-Timing telemetry is active.
		 */
		public function server_timing_enabled(): bool {
			$enabled = ! empty( $this->get_options()['performance_audit']['server_timing_enabled'] ?? false );
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
		 * @since 2.0.0 Expanded documentation for buffering opt-in context.
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
		 * Performance Lab interop: when the Performance Lab Server-Timing module is
		 * active it owns the Server-Timing header and its default metrics surface as
		 * `wp-before-template`, `wp-template` and `wp-total` (Performance Lab prefixes
		 * every registered metric slug with `wp-`). Emitting our own raw header with
		 * the same metric names would produce duplicate/conflicting entries in
		 * DevTools, so:
		 *
		 * - Performance Lab with output buffering enabled already measures
		 *   before-template + template + total from the same underlying timestamps
		 *   (timestart / template render window), so our emission is suppressed
		 *   entirely and Performance Lab's single header carries the data.
		 * - Performance Lab without output buffering sends its header at
		 *   template_include (before the template renders) and only carries
		 *   `wp-before-template`; the `wp-template` name stays unclaimed, so only
		 *   the template render duration is emitted as a distinct appended entry —
		 *   the duplicate `wp-before-template` is dropped. When the buffering-state
		 *   helper (`perflab_server_timing_use_output_buffer()`) is absent the
		 *   buffering mode is unknown and the emission is suppressed instead.
		 *
		 * When Performance Lab is inactive nothing changes: both
		 * `wp-before-template` and `wp-template` are emitted as before.
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
		 * @since 2.0.0 Performance Lab Server-Timing interop: defer to the
		 *             Performance Lab-owned header (no duplicate/conflicting
		 *             metric names); {@see is_pl_server_timing_active()}.
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

			$now = microtime( true );

			// wp-before-template: request bootstrap to template start. Measured from the
			// captured template-start marker so it is disjoint from wp-template below.
			$before_duration = '';
			if ( $this->server_timing_template_start > $start ) {
				$before_duration = 'wp-before-template;dur=' . round( ( $this->server_timing_template_start - $start ) * 1000, 2 );
			}

			// wp-template: template render duration.
			$template_duration = '';
			if ( $this->server_timing_template_start > 0 ) {
				$render = ( $now - $this->server_timing_template_start ) * 1000;
				if ( $render > 0 ) {
					$template_duration = 'wp-template;dur=' . round( $render, 2 );
				}
			}

			if ( '' === $before_duration && '' === $template_duration ) {
				return;
			}

			if ( $this->is_pl_server_timing_active() ) {
				// Performance Lab owns the Server-Timing header. When it uses
				// output buffering its defaults already carry wp-before-template,
				// wp-template and wp-total measured from the same underlying
				// timestamps — suppress our duplicate emission entirely.
				// When the buffering-state helper itself is absent the buffering
				// mode is unknown, so defer to Performance Lab as well rather
				// than risking a duplicate emission.
				// Without output buffering Performance Lab already sent its header
				// (wp-before-template only) at template_include; emitting only the
				// unclaimed wp-template render duration keeps a single source per
				// metric name (no duplicate/conflicting entries).
				if ( ! function_exists( 'perflab_server_timing_use_output_buffer' ) || perflab_server_timing_use_output_buffer() ) {
					return;
				}
				if ( '' === $template_duration ) {
					return;
				}
				header( 'Server-Timing: ' . $template_duration, false );
				return;
			}

			// Append rather than replace so coexisting Server-Timing entries are preserved.
			header( 'Server-Timing: ' . implode( ', ', array_filter( array( $before_duration, $template_duration ) ) ), false );
		}

		/**
		 * Whether the Performance Lab Server-Timing module is active.
		 *
		 * Detects the canonical Performance Lab Server-Timing API surface
		 * (`perflab_server_timing_register_metric()` / `perflab_wrap_server_timed_call()`).
		 * Performance Lab is a plugin (not core), so this is a function_exists
		 * gate with no WordPress version check. Used by
		 * {@see emit_server_timing_header()} to defer to the Performance Lab-owned
		 * Server-Timing header instead of emitting a second, conflicting one.
		 *
		 * @since 2.0.0
		 * @return bool True when the Performance Lab Server-Timing API is present.
		 */
		public function is_pl_server_timing_active(): bool {
			return function_exists( 'perflab_server_timing_register_metric' ) || function_exists( 'perflab_wrap_server_timed_call' );
		}

		/**
		 * Start output buffer for used-CSS (legacy path, WP &lt; 6.9).
		 *
		 * Tracked by #829: do not remove until minimum supported WP is raised
		 * to 6.9 (`Requires at least: 6.9`).
		 *
		 * @return void
		 * @since 1.9.0
		 */
		public function start_used_css_buffer() {
			$this->preload_buffer_coordinator->start_used_css_buffer();
		}

		/**
		 * Start output buffer for LCP image prioritization (legacy path, WP &lt; 6.9).
		 *
		 * Tracked by #829: do not remove until minimum supported WP is raised
		 * to 6.9 (`Requires at least: 6.9`).
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
			$this->preload_buffer_coordinator->start_lcp_priority_buffer();
		}

		/**
		 * Capture and process buffer for used-CSS.
		 *
		 * Wrapped in try/catch so an unexpected failure inside the used-CSS or
		 * Google Fonts pipeline can never throw out of the output-buffer
		 * callback: the callback must always return a string or the buffered
		 * page output would be lost/corrupted when the buffer is closed
		 * (audit #888 finding 12 — balanced buffer lifecycle).
		 *
		 * @param string $buffer The output buffer content.
		 * @return string The processed buffer.
		 * @since 1.9.0
		 */
		public function process_used_css_capture( $buffer ) {
			return $this->preload_buffer_coordinator->process_used_css_capture( $buffer );
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
				'2.1', // @phpstan-ignore argument.type
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
		 * @since 2.0.0
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
			if ( false !== $resolved && 0 === strpos( $resolved, (string) WPPO_PLUGIN_PATH ) ) {
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

			// Dashboard stats (issue #1464): the cache size is read from the
			// canonical unified stats payload (Cache::get_cache_stats(), keyed
			// via Util::transient_key() so multisite stays isolated) instead of
			// the retired split `wppo_cache_size` transient mirror, which is no
			// longer written or promoted anywhere.
			$cache_stats = Cache::get_cache_stats();
			$cache_size  = isset( $cache_stats['size'] ) ? (string) $cache_stats['size'] : __( 'N/A', 'performance-optimisation' );

			// Salted object-cache reads (WP 6.9+, issue #882): the salt is the
			// current `wppo_cache_last_cleared` option VALUE (bumped by
			// Cache::bump_stats_cache()), not the option key — passing the key
			// made every bump a no-op. Transient fallback keeps multisite
			// key isolation via Util::transient_key().
			// One salt read for both dashboard stats (issue #882 review).
			$cache_salt = Util::cache_salt( 'wppo_cache_last_cleared' );
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				$total_js_css = wp_cache_get_salted( 'wppo_total_js_css', 'wppo', $cache_salt );
				if ( false === $total_js_css ) {
					$total_js_css = Util::get_js_css_minified_file();
					wp_cache_set_salted( 'wppo_total_js_css', $total_js_css, 'wppo', $cache_salt, 15 * MINUTE_IN_SECONDS );
				}
			} else {
				$total_js_css = get_transient( Util::transient_key( 'wppo_total_js_css' ) );
				if ( false === $total_js_css ) {
					$total_js_css = Util::get_js_css_minified_file();
					set_transient( Util::transient_key( 'wppo_total_js_css' ), $total_js_css, 15 * MINUTE_IN_SECONDS );
				}
			}

			// Disk-safe slice (issue #1428): cached-page file count for the
			// dashboard size/count surface. Fail-open to 0; multisite-safe
			// via Util::transient_key().
			$cache_count = 0;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'get_cache_stats' ) ) {
					if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
						$cached_stats = wp_cache_get_salted( 'wppo_cache_stats', 'wppo', $cache_salt );
						if ( is_array( $cached_stats ) && isset( $cached_stats['count'] ) ) {
							$cache_count = (int) $cached_stats['count'];
						} else {
							$stats       = Cache::get_cache_stats();
							$cache_count = (int) ( $stats['cached_pages'] ?? 0 );
						}
					} else {
						$cached_count = get_transient( Util::transient_key( 'wppo_cache_count' ) );
						if ( false !== $cached_count && is_numeric( $cached_count ) ) {
							$cache_count = (int) $cached_count;
						} else {
							$stats       = Cache::get_cache_stats();
							$cache_count = (int) ( $stats['cached_pages'] ?? 0 );
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$cache_count = 0;
			}

			// Clone options and redact sensitive keys before exposing to the client.
			$safe_options = $this->get_options();
			if ( isset( $safe_options['performance_audit']['pagespeed_api_key'] ) ) {
				unset( $safe_options['performance_audit']['pagespeed_api_key'] );
			}
			if ( isset( $safe_options['object_cache']['password'] ) ) {
				unset( $safe_options['object_cache']['password'] );
			}

			// Resolve + sanitize image info once, outside the wp_localize_script
			// array, so the class_exists fallback stays scannable.
			$image_info = class_exists( 'PerformanceOptimise\Inc\Img_Converter' )
				? Img_Converter::get_img_info()
				: get_option( 'wppo_img_info', array() );
			$image_info = $this->sanitize_image_info_for_client( (array) $image_info );

			wp_localize_script(
				'performance-optimisation-script',
				'wppoSettings',
				array(
					'apiUrl'                               => get_rest_url( null, 'performance-optimisation/v1/' ),
					'ajaxUrl'                              => admin_url( 'admin-ajax.php' ),
					'nonce'                                => wp_create_nonce( 'wp_rest' ),
					'nonce_refresh'                        => wp_create_nonce( 'wppo_nonce_refresh' ),
					'version'                              => WPPO_VERSION,
					'settings'                             => $safe_options,
					'show_welcome'                         => ! (bool) get_user_meta( get_current_user_id(), 'wppo_welcome_dismissed', true ),
					'image_info'                           => $image_info,
					'cache_size'                           => $cache_size,
					'cache_count'                          => $cache_count,
					'total_js_css'                         => $total_js_css,
					// Read-only WP 7.1+ client-side media processing state. Evaluated
					// here (after Image_Optimisation has registered the opt-out filter)
					// so it already reflects an enabled "Force Server-Side Conversion"
					// toggle (false when core's in-browser processing is forced off).
					// Guarded by function_exists() for <7.1 (no wasm worker there).
					// WP 7.1 gates the ~13 MB wasm-vips Web Worker on
					// Document-Isolation-Policy / SharedArrayBuffer + >2 GB RAM /
					// ≥2 cores; HEIC→JPEG companions are retained as
					// $metadata['original'] for CDN/Edge purger parity (see
					// Img_Converter::clean_placeholder_on_delete() and
					// maybe_extract_placeholder_for_upload() HEIC early-exit).
					// Trac #64876: client_side_supported_mime_types intersection
					// guard stays until a public filter lands. @since 2.0.0.
					'client_side_media_processing_enabled' => function_exists( 'wp_is_client_side_media_processing_enabled' ) && wp_is_client_side_media_processing_enabled(),
					'performance_audit'                    => array(
						'homeUrl'                   => Util::cached_home_url( '/' ),
						'pagespeedApiKeyConfigured' => ! empty( $this->get_options()['performance_audit']['pagespeed_api_key'] ),
						'highValueUrls'             => $this->get_options()['performance_audit']['high_value_urls'] ?? array(), // High-value URLs from settings (edited in Tools tab; consumed by preload/PageSpeed rescan cron + llms.txt proxy).
						'autoFixEnabled'            => (bool) ( $this->get_options()['performance_audit']['auto_fix_enabled'] ?? false ),
						'autoRescan'                => $this->get_options()['performance_audit']['auto_rescan'] ?? '',
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
						'static_cache_active' => ! empty( $this->get_options()['cache_settings']['enableCache'] ),
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
					// Authoritative one-click preset bundles (issue #1442 review):
					// the SPA prefers these server-localised copies via
					// resolvePresetBundle() and only falls back to its local
					// SAFE_/AGGRESSIVE_PRESET_BUNDLE mirrors when the global
					// is absent, so the two can never drift. Fail-open getters
					// (empty array worst case) so localisation never breaks.
					'presetBundles'                        => array(
						'safe'       => self::get_safe_preset_bundle(),
						'aggressive' => self::get_aggressive_preset_bundle(),
					),
					// Upgrade auto-purge status (issue #1276): SPA-visible
					// last-purge reason + safe-mode preview link bypassing
					// minify (?wppo_nocache=1). Class/method-exists guarded +
					// fail-open so a missing watcher never breaks localisation.
					'upgradePurge'                         => $this->get_upgrade_purge_for_client(),
				),
			);

			// Audit #1333: wppoSettings intentionally carries no translations
			// map — wp_set_script_translations() JSON below is the sole i18n
			// source for the SPA (unlike the admin-bar wppoObject path, which
			// needs its own map because src/main.js has no wp-i18n dep).
			wp_set_script_translations( 'performance-optimisation-script', 'performance-optimisation' );
		}

		/**
		 * Enqueues scripts for performance optimization.
		 *
		 * @since 1.0.0
		 */
		public function enqueue_scripts() {
			if ( is_admin_bar_showing() && current_user_can( 'manage_options' ) ) {
				$this->enqueue_admin_bar_script();
			}

			if ( $this->should_optimise_for_logged_in() ) {
				$lazy_load_images         = ! empty( $this->get_options()['image_optimisation']['lazyLoadImages'] );
				$lazy_load_backgrounds    = ! empty( $this->get_options()['image_optimisation']['lazyLoadBackgroundImages'] );
				$lazy_load_videos         = ! empty( $this->get_options()['image_optimisation']['lazyLoadVideos'] );
				$enable_video_placeholder = ! empty( $this->get_options()['image_optimisation']['enableVideoPlaceholder'] ) && $lazy_load_videos;
				$delay_js                 = ! empty( $this->get_options()['file_optimisation']['delayJS'] );
				$use_native_lazy          = ! empty( $this->get_options()['image_optimisation']['lazyLoadNative'] );

				// When native lazy loading is active, images use native loading="lazy" but iframes may still need JS restoration.
				$needs_script = ( ! $use_native_lazy && $lazy_load_images ) || $lazy_load_backgrounds || $lazy_load_videos || $enable_video_placeholder || $delay_js;

				if ( $needs_script ) {
					// Shared runtime config for the lazyload bundle. Exported to the frontend
					// via the script-module data filter on WP 6.9+, or as classic inline
					// scripts on WP < 6.9 (see the fallback below). Both paths are
					// retained while the floor is 6.2 (issue #1203: removal deferred
					// until the minimum supported WP is raised to 6.9).
					$lazy_config = array();

					if ( $use_native_lazy ) {
						$lazy_config['nativeLazy'] = true;
					}

					// Audit #1354: translated video iframe title for the lazyload
					// bundle (English fallback client-side and on the WP < 6.9 path).
					$lazy_config['videoPlayerLabel'] = __( 'Video player', 'performance-optimisation' );

					if ( $delay_js ) {
						$idle_timeout = ! empty( $this->get_options()['file_optimisation']['delayJSIdleTimeout'] )
						? absint( $this->get_options()['file_optimisation']['delayJSIdleTimeout'] )
						: 3000;
						// Prefer the in-memory effective strategy (INP preset may have
						// flipped interaction → idle in setup_hooks) over the stored
						// option so the frontend loader matches the rewritten tags.
						$default_strategy = in_array( $this->delay_js_default_strategy, array( 'interaction', 'idle', 'viewport' ), true )
						? $this->delay_js_default_strategy
						: 'interaction';

						$lazy_config['delayConfig'] = array(
							'idleTimeout'     => $idle_timeout,
							'defaultStrategy' => $default_strategy,
						);

						// The lazyload bundle validates deferred script src URLs
						// (scheme + same-origin/host allowlist). Additional hosts
						// can be allowlisted via this filter and are mirrored to
						// the client as delayConfig.allowedScriptHosts.
						$allowed_script_hosts = apply_filters( 'wppo_delay_js_allowed_hosts', array() );
						if ( ! empty( $allowed_script_hosts ) ) {
							$validated_hosts = array();
							foreach ( (array) $allowed_script_hosts as $host_entry ) {
								if ( ! is_string( $host_entry ) ) {
									continue;
								}
								// Parse the host first so display-text sanitization cannot
								// mangle full-URL entries before host extraction.
								$candidate = trim( $host_entry );
								if ( '' === $candidate ) {
									continue;
								}
								// A '*' wildcard from the server intentionally disables the
								// deferred-script host allowlist (admin-only debug path,
								// see getScriptSrcHosts() in src/lazyload.js).
								if ( '*' === $candidate ) {
									$validated_hosts[] = '*';
									continue;
								}
								// Accept scheme-relative URLs (//host/path) for parsing.
								if ( 0 === strpos( $candidate, '//' ) ) {
									$candidate = 'https:' . $candidate;
								}
								// Accept bare hostnames or full URLs; reduce URLs to their host part.
								if ( false !== strpos( $candidate, '://' ) ) {
									$parsed_host = wp_parse_url( $candidate, PHP_URL_HOST );
									if ( ! is_string( $parsed_host ) || '' === $parsed_host ) {
										continue;
									}
									$candidate = $parsed_host;
								}
								$candidate = trim( $candidate );
								// Unwrap IPv6 literals, optionally with a port ([::1]:8080).
								if ( 0 === strpos( $candidate, '[' ) ) {
									$bracket_end = strpos( $candidate, ']' );
									if ( false === $bracket_end ) {
										continue;
									}
									$remainder = substr( $candidate, $bracket_end + 1 );
									if ( '' !== $remainder && 1 !== preg_match( '/^:\d+$/', $remainder ) ) {
										continue;
									}
									$candidate = substr( $candidate, 1, $bracket_end - 1 );
								} elseif ( 1 === preg_match( '/^(.*):(\d+)$/', $candidate, $port_match ) && false === strpos( $port_match[1], ':' ) ) {
									// Strip a bare host:port suffix (no :// so the port
									// survived URL parsing); IPv6 contains colons and
									// never matches this branch.
									$candidate = $port_match[1];
								}
								$candidate = sanitize_text_field( strtolower( trim( $candidate, '.' ) ) );
								if ( '' === $candidate ) {
									continue;
								}
								// Accept IP literals (IPv4 + IPv6) that the
								// DNS-label regex below would otherwise reject.
								if ( function_exists( 'filter_var' ) && filter_var( $candidate, FILTER_VALIDATE_IP ) ) {
									$validated_hosts[] = $candidate;
									continue;
								}
								// Map Unicode IDN to punycode for validation when intl
								// is available; the original entry is preserved.
								$ascii_candidate = $candidate;
								if ( function_exists( 'idn_to_ascii' ) && defined( 'INTL_IDNA_VARIANT_UTS46' ) && defined( 'IDNA_DEFAULT' ) && 1 === preg_match( '/[^\x00-\x7F]/', $candidate ) ) {
									$converted = idn_to_ascii( $candidate, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
									if ( ! is_string( $converted ) || '' === $converted ) {
										continue;
									}
									$ascii_candidate = strtolower( $converted );
								}
								if ( 1 !== preg_match( '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $ascii_candidate ) ) {
									continue;
								}
								$validated_hosts[] = $candidate;
							}
							if ( ! empty( $validated_hosts ) ) {
								$lazy_config['delayConfig']['allowedScriptHosts'] = array_values( array_unique( $validated_hosts ) );
							}
						}
					}

					// WP 6.9+ uses native fetchpriority/in_footer via Script Loader / Script Modules.
					// Guard with the canonical supports_native_script_fetchpriority()
					// predicate (version + genuinely-6.9 API probe) so a
					// backported/filtered version string cannot enable native
					// writes where the API is absent; WP <6.9 keeps the legacy path.
					// Note: WP 6.5–6.8 previously enqueued wppo-lazyload as a module with
					// fetchpriority/in_footer args harmlessly ignored by core; now intentionally
					// falls back to classic until 6.9 where the args are natively rendered
					// (Trac #61734, #63486). Documented narrowing for backward compat.
					//
					// @since 2.2.0.
					$use_mod_api   = self::supports_native_script_fetchpriority();
					$lazy_mod_args = array(
						'in_footer'     => true,
						'fetchpriority' => 'low',
					);

					if ( $use_mod_api ) {
						// WP 6.9+: load lazyload as a native script module with fetchpriority low
						// and in_footer true. Modules are always deferred (non-render-blocking)
						// and the native args are rendered by core (Trac #61734, #63486).
						// @since 2.0.0.
						if ( function_exists( 'wp_enqueue_script_module' ) ) {
							wp_enqueue_script_module( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, $lazy_mod_args );
						} elseif ( function_exists( 'wp_register_script_module' ) ) {
							wp_register_script_module( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, $lazy_mod_args );
							if ( function_exists( 'wp_enqueue_script_module' ) ) {
								wp_enqueue_script_module( 'wppo-lazyload' );
							}
						}
						add_filter(
							'script_module_data_wppo-lazyload',
							static function ( array $data ) use ( $lazy_config ) {
								return array_merge( $data, $lazy_config );
							}
						);
					} else {
						// WP <6.9 fallback: classic script enqueued with inline config injection.
						// @since 2.0.0.
						//
						// The window.wppoNativeLazy / window.wppoDelayConfig globals
						// below are consumed once at module init (src/lazyload.js) and
						// are deleted again by window.wppoLazyloadTeardown() (audit
						// #1077 finding 9). If this script element is ever removed
						// dynamically (e.g. by a theme or optimizer detaching the
						// wppo-lazyload <script>), teardown runs automatically via
						// the one-way script-removal guard in src/lazyload.js
						// (audit #1268); calling window.wppoLazyloadTeardown()
						// FIRST (it is idempotent) remains supported for
						// back-compat. See the wppo_delay_js_allowed_hosts docs
						// (docs/hooks.md) for this teardown contract.
						//
						// CSP note: sites with a strict Content-Security-Policy can
						// attach a nonce to these inline config scripts via the core
						// wp_inline_script_attributes filter for handle wppo-lazyload
						// (same escape hatch as the bfcache inline script).
						wp_enqueue_script( 'wppo-lazyload', WPPO_PLUGIN_URL . 'build/lazyload.js', array(), WPPO_VERSION, array( 'in_footer' => true ) );

						if ( $use_native_lazy ) {
							wp_add_inline_script( 'wppo-lazyload', 'window.wppoNativeLazy=true;', 'before' );
						}

						if ( $delay_js ) {
							$delay_config = wp_json_encode( $lazy_config['delayConfig'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP );
							wp_add_inline_script( 'wppo-lazyload', 'window.wppoDelayConfig=' . $delay_config . ';', 'before' );
						}
					}
				}
			}
		}

		/**
		 * Apply defer optimisations to script modules (WP 6.9+).
		 *
		 * Script modules are already deferred by the browser; the remaining wins
		 * are printing them in the footer and lowering their fetch priority so
		 * critical CSS/images win the network queue. Uses the core API when
		 * available (WP 6.9+ native fetchpriority/in_footer via
		 * WP_Script_Modules::set_in_footer / set_fetchpriority or
		 * wp_register_script_module with fetchpriority/in_footer args) and is a
		 * no-op on older core. Guarded by version_compare and
		 * function_exists/class_exists for backward compat.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function apply_module_loading_strategies(): void {
			if ( empty( $this->get_options()['file_optimisation']['deferJS'] ) ) {
				return;
			}
			if ( ! $this->should_optimise_for_logged_in() ) {
				return;
			}
			// WP 6.9+ native fetchpriority/in_footer (Trac #61734, #63486). Gate on
			// the shared supports_native_script_fetchpriority() predicate (version
			// + genuinely-6.9 API probe) instead of a bare version_compare so a
			// filtered version string cannot enable native writes where the API
			// is absent; method_exists per set_in_footer/set_fetchpriority keeps
			// compat if the API is partially present.
			// @since 2.0.0.
			if ( ! self::supports_native_script_fetchpriority() ) {
				return;
			}
			if ( ! function_exists( 'wp_script_modules' ) ) {
				return;
			}
			if ( ! function_exists( 'wp_enqueue_script_module' ) ) {
				return;
			}
			if ( ! class_exists( 'WP_Script_Modules' ) ) {
				return;
			}

			$modules = wp_script_modules();
			if ( ! is_object( $modules ) ) {
				return;
			}

			// Canonical exclusions: the $this->exclude_defer_js property carries the
			// wppo-lazyload default, the wppo_exclude_defer_js filter, and the
			// CVE-guard handles. Merge the raw option as a fallback so instances
			// built without the constructor path stay covered. Fail-open.
			$excluded = is_array( $this->exclude_defer_js ) ? $this->exclude_defer_js : array();
			$raw      = (string) ( $this->get_options()['file_optimisation']['excludeDeferJS'] ?? '' );
			if ( '' !== $raw ) {
				$excluded = array_unique( array_merge( $excluded, Util::process_urls( $raw ) ) );
			}
			// Union the jQuery/commerce/builder preset explicitly: instances built
			// without the setup_hooks() constructor path can miss the preset in
			// $this->exclude_defer_js, but commerce/jQuery must stay excluded in
			// both the classic and module paths. Fail-open append, multisite-safe,
			// no option change.
			//
			// @since 2.2.0.
			$excluded = array_unique( array_merge( $excluded, self::get_defer_js_preset_exclusions() ) );
			if ( ! in_array( 'wppo-lazyload', $excluded, true ) ) {
				$excluded[] = 'wppo-lazyload';
			}
			// Interactivity runtime guard (issue #1095): never deprioritize or
			// move the block-interactivity runtime. Covers the classic handle
			// plus the 6.5+ module ids. Fail-open: unconditional append.
			foreach ( array( 'wp-interactivity', '@wordpress/interactivity', '@wordpress/interactivity-router' ) as $runtime_handle ) {
				if ( ! in_array( $runtime_handle, $excluded, true ) ) {
					$excluded[] = $runtime_handle;
				}
			}

			// Collect registered module ids, tolerating core version differences.
			// Prefer the public get_print_queue() API; fall back to reading the
			// registered store via reflection because WP_Script_Modules::$registered
			// is private in core and isset( $modules->registered ) from outside is
			// always false on production (direct access would fatal on magic-less
			// objects, so never touch ->registered directly).
			$ids = array();
			if ( method_exists( $modules, 'get_print_queue' ) ) {
				$ids = (array) $modules->get_print_queue();
			}
			// Hoisted reflection read (issue #1292 follow-up): reused by
			// get_module_fetchpriority() so the fallback path pays one
			// ReflectionObject instead of one per module. A null store is
			// still "already read" (signalled via func_num_args()) and reused.
			$registered_store   = null;
			$store_already_read = false;
			if ( empty( $ids ) ) {
				$ids                = $this->get_registered_module_ids( $modules, $registered_store );
				$store_already_read = true;
			}

			// Hoist the reflection fallback read once (issue #1292
			// follow-up): get_module_fetchpriority() would otherwise build a
			// new ReflectionObject per module when get_registered() is
			// unavailable. The third/fourth args signal "already read" via
			// func_num_args() so even a null (unreadable) store is reused.
			$fallback_ready = ! method_exists( $modules, 'get_registered' );
			if ( $fallback_ready && ! $store_already_read ) {
				$registered_store = $this->read_private_module_store( $modules, 'registered' );
			}
			// The `all` store backs get_registered_module_ids() when the
			// `registered` store is empty, so thread it as well: IDs sourced
			// from `all` would otherwise always miss in
			// get_module_fetchpriority() and fail open to 'low', clobbering
			// an explicit 'high' on the old-core path.
			$all_store = null;
			if ( $fallback_ready ) {
				$all_store = $this->read_private_module_store( $modules, 'all' );
			}
			foreach ( $ids as $id ) {
				if ( in_array( (string) $id, $excluded, true ) ) {
					continue;
				}
				// Honor the shared in_footer filter (issue #1294): a false
				// return keeps the module in the head (e.g. document.write
				// dependencies). Guarded + fail-open via the shared helper.
				//
				// @since 2.2.0.
				if ( method_exists( $modules, 'set_in_footer' ) ) {
					if ( $this->should_move_deferred_to_footer( (string) $id ) ) {
						$modules->set_in_footer( (string) $id, true );
					}
				}
				if ( method_exists( $modules, 'set_fetchpriority' ) ) {
					// Fill-gaps-only (issue #1218): 'high' and 'low' are preserved;
					// 'auto'/missing/empty counts as a gap and is upgraded to 'low'.
					// Core registers every module with fetchpriority default 'auto'
					// (WP_Script_Modules::register, Trac #61734), so a defaulted
					// module is indistinguishable from an explicitly-'auto' one
					// post-registration — preserving every 'auto' value would make
					// the low-fill a no-op for typical modules. Rendering-wise core
					// only emits fetchpriority for non-'auto' anyway, so auto→low
					// is exactly the deprioritization this pass intends. Reads via
					// the public get_registered() getter when available (WP 7.0+)
					// with a reflection fallback for the private 6.9 store.
					// Fail-open: any unreadable shape falls through to 'low'.
					// Note the deliberate asymmetry with the classic-script path in
					// add_defer_strategy(), which DOES preserve explicit 'auto':
					// classic scripts carry no core default (get_data() returns
					// false when unset), so explicitness is observable there.
					// The filtered value (wppo_deferred_fetchpriority, issue
					// #1294) is honored so an LCP-critical module can stay
					// 'high' and a handle can suppress via falsy; '' skips.
					//
					// @since 2.2.0.
					$existing = $this->get_module_fetchpriority( $modules, (string) $id, $registered_store, $all_store, $fallback_ready );
					if ( is_string( $existing ) && '' !== trim( $existing ) && 'auto' !== strtolower( trim( $existing ) ) ) {
						continue;
					}
					$filtered_priority = $this->get_filtered_deferred_fetchpriority( (string) $id );
					if ( '' === $filtered_priority ) {
						continue;
					}
					$modules->set_fetchpriority( (string) $id, $filtered_priority );
				}
			}
		}

		/**
		 * Read a script module's fetchpriority without touching private state directly.
		 *
		 * Uses the public get_registered() getter when available, otherwise reads
		 * the private $registered store via reflection. Returns null when the
		 * module is unregistered, carries no fetchpriority key, or the store is
		 * unreadable (fail-open: callers treat null as a gap and write 'low').
		 * Never accesses $modules->registered directly: that property is private
		 * in core, so isset()/direct reads from outside are always false and
		 * would silently overwrite explicit values in production.
		 *
		 * @since 2.0.0
		 *
		 * @param object $modules          Script modules instance from wp_script_modules().
		 * @param string $id               Module id.
		 * @param mixed  $registered_store Optional pre-read $registered store (hoisted by the
		 *                                 caller to avoid per-module reflection; pass-through
		 *                                 even when null so an unreadable store is not re-read).
		 * @param mixed  $all_store        Optional pre-read $all store used as a fallback
		 *                                 when the id is absent from the registered store
		 *                                 (mirrors get_registered_module_ids()).
		 * @param bool   $fallback_ready   Hoisted `! method_exists( $modules, 'get_registered' )`
		 *                                 flag so the callee skips the per-module probe.
		 * @return mixed Fetchpriority value, or null when missing/unreadable.
		 */
		private function get_module_fetchpriority( object $modules, string $id, $registered_store = null, $all_store = null, bool $fallback_ready = false ): mixed {
			if ( ! $fallback_ready && method_exists( $modules, 'get_registered' ) ) {
				$entry = $modules->get_registered( $id );
				if ( is_array( $entry ) && array_key_exists( 'fetchpriority', $entry ) ) {
					return $entry['fetchpriority'];
				}
				if ( is_object( $entry ) && isset( $entry->fetchpriority ) ) {
					return $entry->fetchpriority;
				}
				return null;
			}
			$registered = func_num_args() >= 3 ? $registered_store : $this->read_private_module_store( $modules, 'registered' );
			$all        = func_num_args() >= 4 ? $all_store : $this->read_private_module_store( $modules, 'all' );
			foreach ( array( $registered, $all ) as $store ) {
				if ( is_array( $store ) && array_key_exists( $id, $store ) ) {
					$entry = $store[ $id ];
					if ( is_array( $entry ) && array_key_exists( 'fetchpriority', $entry ) ) {
						return $entry['fetchpriority'];
					}
					if ( is_object( $entry ) && isset( $entry->fetchpriority ) ) {
						return $entry->fetchpriority;
					}
				}
			}
			return null;
		}

		/**
		 * Collect registered module ids without touching private state directly.
		 *
		 * Reflection fallback for environments where get_print_queue() is empty or
		 * unavailable; reads the private $registered (then $all) store. Returns an
		 * empty array when the store is unreadable.
		 *
		 * @since 2.0.0
		 *
		 * @param object $modules Script modules instance from wp_script_modules().
		 * @param mixed  $registered_store Optional out-param receiving the already-read
		 *                                 `registered` store (even when null/unreadable)
		 *                                 so callers can hoist it without re-reflecting.
		 * @return string[] Module ids.
		 */
		private function get_registered_module_ids( object $modules, &$registered_store = null ): array {
			$registered_store = $this->read_private_module_store( $modules, 'registered' );
			if ( is_array( $registered_store ) && ! empty( $registered_store ) ) {
				return array_map( 'strval', array_keys( $registered_store ) );
			}
			$all_store = $this->read_private_module_store( $modules, 'all' );
			if ( is_array( $all_store ) && ! empty( $all_store ) ) {
				return array_map( 'strval', array_keys( $all_store ) );
			}
			return array();
		}

		/**
		 * Read a (possibly private) property from the script-modules instance.
		 *
		 * Returns null when the property does not exist or is unreadable instead
		 * of raising. Public properties are read directly; non-public ones go
		 * through reflection (no setAccessible() call: it is deprecated on
		 * PHP 8.5 and a no-op since PHP 8.1, and the plugin requires PHP 8.2+).
		 *
		 * @since 2.0.0
		 *
		 * @param object $modules  Script modules instance.
		 * @param string $property Property name.
		 * @return mixed Property value, or null when unreadable.
		 */
		private function read_private_module_store( object $modules, string $property ): mixed {
			try {
				$reflection = new \ReflectionObject( $modules );
				if ( ! $reflection->hasProperty( $property ) ) {
					return null;
				}
				$prop = $reflection->getProperty( $property );
				return $prop->getValue( $modules );
			} catch ( \Throwable ) {
				return null;
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
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::remove_woocommerce_scripts}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function remove_woocommerce_scripts() {
			return $this->script_strategy()->remove_woocommerce_scripts();
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
		 * @since 2.0.0 Added `manage_options` capability check.
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
		 * Whether the native WP 6.3+ script loading strategy API can be used.
		 *
		 * Canonical predicate for the defer pipeline (issue #1218): the native
		 * `wp_script_add_data( $handle, 'strategy', 'defer' )` path is only
		 * honoured by core since WP 6.3, so both setup_hooks() routing and
		 * add_defer_strategy() fail-open on this helper. Three guards:
		 * version >= 6.3-alpha; `wp_script_add_data()` present (guards a
		 * stripped/missing API — the function itself predates 6.3, so on its
		 * own it cannot detect a backported/filtered version string); and,
		 * when the WP_Scripts class is available, the genuinely-6.3
		 * `WP_Scripts::get_eligible_loading_strategy()` method (changeset
		 * 56033), which IS absent on pre-6.3 core and therefore catches the
		 * inflated-version case. When the class is unavailable (e.g. very
		 * early load), version + function probes decide. Fail-open: false on
		 * any unreadable version or missing API, in which case callers fall
		 * back to the pre-6.3 script_loader_tag regex.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when the native defer strategy path is allowed.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::supports_native_defer_strategy}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function supports_native_defer_strategy(): bool {
			return Script_Strategy::supports_native_defer_strategy();
		}

		/**
		 * Whether the native WP 6.9+ script fetchpriority API can be used.
		 *
		 * Canonical predicate for the fetchpriority pipeline (issue #1218):
		 * native fetchpriority rendering arrived in WP 6.9 (Trac #61734), so
		 * setup_hooks() fallback routing, add_defer_strategy(), and
		 * apply_module_loading_strategies() all fail-open on this helper
		 * instead of a bare version_compare, which a backported/filtered
		 * version string could defeat. Guards: version >= 6.9-alpha plus the
		 * genuinely-6.9 `WP_Script_Modules::set_fetchpriority()` method when
		 * the class is available; when it is not (e.g. unit-test doubles),
		 * the 6.5+ module functions decide alongside the version gate.
		 * Fail-open: false on any unreadable version or missing API, in which
		 * case callers fall back to the pre-6.9 script_loader_tag regex.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when the native fetchpriority path is allowed.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::supports_native_script_fetchpriority}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function supports_native_script_fetchpriority(): bool {
			return Script_Strategy::supports_native_script_fetchpriority();
		}

		/**
		 * Whether a queued handle is eligible for a deferred loading strategy.
		 *
		 * Mirrors core's WP_Scripts::get_eligible_loading_strategy() gate
		 * (changeset 56033, issue #1466) so the native strategy write never
		 * fights core output: core renders a handle blocking when it carries
		 * an inline `after` script, when a blocking queued dependent relies
		 * on it, or when it is a module/import-map script. Core keeps
		 * get_eligible_loading_strategy() private with pre-stamp semantics
		 * ('' when no intended strategy is set), so it can neither be called
		 * nor reused here; eligibility is decided by the manual fallback
		 * below, which is the only path that can run against real core. The
		 * existing supports_native_defer_strategy() method_exists probe
		 * already covers capability detection. Fail-open: true on any
		 * unreadable state so output degrades to the current behaviour, never
		 * fatal or white-screen.
		 *
		 * Dependents are judged against the run's intended set plus live
		 * state (issue #1466 review): a queued dependent carrying no explicit
		 * strategy counts as deferred when this run intends to defer it, so
		 * the verdict never depends on queue order. Transitive poisoning is
		 * handled by recursing into each deferred dependent with a visited
		 * set (mirroring core's $checked param): a dependent that is itself
		 * blocked by a third handle poisons its own dependencies.
		 *
		 * @since 2.3.0
		 *
		 * @param object   $wp_scripts WP_Scripts registry.
		 * @param string   $handle     Script handle.
		 * @param string[] $intended   Handles this run intends to defer (pass
		 *                             the interactivity guard and exclusion
		 *                             list). Defaults to empty for direct calls.
		 * @param bool[]   $checked    Visited handles for the recursion guard
		 *                             (mirrors core's $checked). Callers leave
		 *                             this at its default; a fresh map is used
		 *                             per top-level evaluation.
		 * @return bool True when the handle may receive strategy defer.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_defer_eligible_for_handle}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function is_defer_eligible_for_handle( object $wp_scripts, string $handle, array $intended = array(), array &$checked = array() ): bool {
			return $this->script_strategy()->is_defer_eligible_for_handle( $wp_scripts, $handle, $intended, $checked );
		}

		/**
		 * Whether the WP 6.9+ core template-enhancement buffer should carry plugin post-processing.
		 *
		 * Canonical predicate for the single-buffer routing (issue #1386):
		 * version >= 6.9-alpha plus the genuinely-6.9
		 * `wp_should_output_buffer_template_for_enhancement()` API. This is an
		 * availability probe only: it never calls the predicate itself, so
		 * registration-time routing cannot confuse a mid-request opt-out
		 * (filter returning false) with a missing core. When true,
		 * setup_hooks() registers ONLY the `wp_template_enhancement_output_buffer`
		 * filter / `wp_finalized_template_enhancement_output_buffer` action
		 * callbacks and no private `ob_start()` capture; when false (pre-6.9)
		 * only the legacy `template_redirect` captures are registered.
		 * Honoring a runtime `false` (opt-out / no consumers) means degrading
		 * to uncached streaming output, never opening a private buffer —
		 * intentional, since a private capture would stack on core's buffer
		 * and re-process HTML; caching resumes when core opts back in.
		 * Fail-open: false on any unreadable version or missing API, in which
		 * case callers fall back to the pre-6.9 legacy path.
		 *
		 * @since 2.3.0
		 *
		 * @return bool True when the core template-enhancement buffer path is allowed.
		 * Facade proxy (P3-015): logic lives in {@see Preload_Buffer_Coordinator::should_use_core_template_buffer}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 * @since NEXT Proxied to Preload_Buffer_Coordinator (P3-015).
		 */
		public static function should_use_core_template_buffer(): bool {
			return Preload_Buffer_Coordinator::should_use_core_template_buffer();
		}

		/**
		 * Resolve the filtered fetchpriority for a deferred handle.
		 *
		 * Shared by the native classic path (add_defer_strategy()), the
		 * pre-6.9 regex fallback (add_fetchpriority_to_deferred()), and the
		 * module path (apply_module_loading_strategies()) so all three honor
		 * the same filter contract. Guarded by function_exists + has_filter
		 * so installs without the filter keep the 'low' default with no
		 * extra dispatch; fail-open to 'low' on any throwable and to ''
		 * (suppress) when the filter returns a non-listed value.
		 *
		 * @since 2.2.0
		 *
		 * @param string $handle Script handle or module id.
		 * @return string Validated 'high'|'low'|'auto', or '' to suppress.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_filtered_deferred_fetchpriority}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function get_filtered_deferred_fetchpriority( string $handle ): string {
			return $this->script_strategy()->get_filtered_deferred_fetchpriority( $handle );
		}

		/**
		 * Whether a deferred handle should be moved to the footer.
		 *
		 * Shared by the native classic path (add_defer_strategy()) and the
		 * module path (apply_module_loading_strategies()) so both honor the
		 * same filter contract. Guarded by function_exists + has_filter;
		 * fail-open to true (move) when the filter is absent or throws.
		 *
		 * @since 2.2.0
		 *
		 * @param string $handle Script handle or module id.
		 * @return bool True when the handle should be footer-bound.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::should_move_deferred_to_footer}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function should_move_deferred_to_footer( string $handle ): bool {
			return $this->script_strategy()->should_move_deferred_to_footer( $handle );
		}

		/**
		 * Applies defer strategy to non-logged-in users' scripts using wp_script_add_data.
		 *
		 * Canonical defer path on WP 6.3+ (issue #1218); the pre-6.3
		 * script_loader_tag regex fallback (add_defer_attribute_legacy()) is
		 * never registered on the same request via setup_hooks(). Iterates
		 * `$wp_scripts->queue` in order with no sorting so dependency chain
		 * order is preserved. Fill-gaps-only for the strategy itself: an
		 * explicit `async` strategy is never rewritten to `defer`.
		 *
		 * On WP 6.9+ deferred handles also receive native fetchpriority/in_footer
		 * args via the Script Loader API (Trac #61734 / #63486) so core renders
		 * them with dependency bumping; the regex fallback
		 * add_fetchpriority_to_deferred() stays disabled on 6.9+ via setup_hooks().
		 * The footer move uses the 'group' data key (core maps the in_footer
		 * enqueue arg to group=1; the 'in_footer' data key itself is never read
		 * for classic scripts) and can be disabled per handle via the
		 * `wppo_deferred_in_footer` filter.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::add_defer_strategy}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function add_defer_strategy(): void {
			$this->script_strategy()->add_defer_strategy();
		}

		/**
		 * Whether a script tag's type attribute is executable JavaScript.
		 *
		 * A tag with no `type` attribute is treated as executable because HTML
		 * implies `text/javascript`. Executable JS types (including `module`,
		 * which HTML treats as JavaScript) may be delay-rewritten; every other
		 * type is a data block — `application/json`, `application/ld+json`,
		 * `text/template`, `speculationrules`, and so on — that never executes
		 * and whose `src` must not be moved to `wppo-src`.
		 *
		 * The leading `\s` in the pattern is what keeps `wppo-type="…"` from
		 * matching: the character before `type` there is `-`, not whitespace.
		 *
		 * @since 2.2.0
		 *
		 * @param string $tag The script tag markup.
		 * @return bool True when the tag may be delay-rewritten.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_executable_script_type}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function is_executable_script_type( string $tag ): bool {
			return $this->script_strategy()->is_executable_script_type( $tag );
		}

		/**
		 * Adds defer attribute to non-logged-in users' scripts.
		 *
		 * @since 1.0.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @return string Modified script tag with defer attribute.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::add_defer_attribute}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function add_defer_attribute( $tag, $handle ): string {
			return $this->script_strategy()->add_defer_attribute( $tag, $handle );
		}

		/**
		 * Adds the defer attribute to script tags on WordPress < 6.3.
		 *
		 * WordPress 6.3+ natively honours the 'strategy' script data added via
		 * wp_script_add_data(), so this legacy fallback is only registered on older
		 * core (WP 6.2) where the native strategy is silently ignored. Retained
		 * while the plugin floor is 6.2 (issue #1203: removal deferred until the
		 * minimum supported WP is raised to 6.3; see TODO(#553) in setup_hooks()).
		 * Fill-gaps-only: a tag already carrying `defer`, `async` (core, theme,
		 * or LiteSpeed delay), or `type="module"` is returned untouched so no
		 * `async defer` double-attribute markup is emitted (issue #1218). The
		 * pre-checks are case-insensitive with attribute boundaries and
		 * quote-masking, so DEFER, valued async="async", single-quoted or
		 * spaced type='module', and `async` inside quoted values (ignored)
		 * are all handled.
		 *
		 * @since 1.9.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @return string Modified script tag with the defer attribute.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::add_defer_attribute_legacy}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function add_defer_attribute_legacy( $tag, $handle ): string {
			return $this->script_strategy()->add_defer_attribute_legacy( $tag, $handle );
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
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::matches_delay_pattern}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function matches_delay_pattern( string $handle, string $pattern ): bool {
			return $this->script_strategy()->matches_delay_pattern( $handle, $pattern );
		}

		/**
		 * Build (once per request) a combined alternation regex for a pattern list.
		 *
		 * @since 2.0.0
		 * @param string[] $patterns Pattern list.
		 * @return string Empty string when no usable patterns; otherwise a ready regex.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_patterns_regex}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function get_delay_patterns_regex( array $patterns ): string {
			return Script_Strategy::get_delay_patterns_regex( $patterns );
		}

		/**
		 * Whether a handle matches any pattern in a list via the precompiled alternation.
		 *
		 * Falls back to per-pattern matching only when the combined regex
		 * fails to compile (extremely long lists).
		 *
		 * @since 2.0.0
		 * @param string   $handle   Script handle.
		 * @param string[] $patterns Pattern list.
		 * @return bool True on match.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::matches_any_delay_pattern}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function matches_any_delay_pattern( string $handle, array $patterns ): bool {
			return $this->script_strategy()->matches_any_delay_pattern( $handle, $patterns );
		}

		/**
		 * Whether a script handle is excluded from Delay JS.
		 *
		 * Checks exact membership first, then falls back to
		 * matches_delay_pattern() word-boundary matching so builder-handle
		 * variants (e.g. `oxygen-*` via `oxygen`, `et-*` via `et-core-api`)
		 * stay excluded on the external-script path exactly as the inline
		 * path in Minify\HTML excludes them by substring.
		 *
		 * @since 2.0.0
		 *
		 * @param string $handle The script's registered handle.
		 * @return bool True when the handle must stay un-delayed.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_delay_excluded_handle}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function is_delay_excluded_handle( string $handle ): bool {
			return $this->script_strategy()->is_delay_excluded_handle( $handle );
		}

		/**
		 * Delay-JS exclusions with `wppo_exclude_delay_js` applied on first use.
		 *
		 * `setup_hooks()` applies the filter while the plugin bootstraps, and
		 * plugins load before the active theme. A theme registering an
		 * exclusion from functions.php therefore had no effect on the
		 * handle-level rewrite, which silently leaves its script swapped to
		 * `wppo-src` and never executed — a mobile menu that cannot open, for
		 * instance. Re-applying here (during enqueue, after every plugin and
		 * the theme have loaded) lets those late registrations count. The
		 * result is memoized, so the filter still runs at most once per
		 * request on this path.
		 *
		 * @since 2.2.0
		 *
		 * @return array<int, string>
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function get_delay_exclusions(): array {
			return $this->script_strategy()->get_delay_exclusions();
		}

		/**
		 * Whether the current request must skip delay-JS rewriting.
		 *
		 * Woo guardrail: cart, checkout, and account pages stay excluded from delay
		 * by default, plus wc-ajax and add-to-cart requests. Intentionally narrower
		 * than Cache::is_woo_excluded(): visitor-scoped cookie signals (cart hash,
		 * wp_woocommerce_session_*) are NOT mirrored — disabling delay site-wide
		 * for every shopper would wipe out the INP win on the homepage/blog, and
		 * mini-cart fragments elsewhere are already protected by the per-handle
		 * Woo exclusions in get_delay_js_preset_exclusions(). Likewise there is no
		 * blanket is_woocommerce() gate: shop/product archives rely on the same
		 * per-handle exclusions (wc-add-to-cart, wc-single-product, …).
		 * Builder guardrail: preview/edit contexts only — rendered frontend output
		 * from builders still gets the INP win.
		 * Fail-open: any detection failure returns true (skip delay) so scripts
		 * stay un-delayed, never fatal. A positive match also returns true.
		 *
		 * The cart/checkout/account path fallback matches the slug as a full
		 * path segment anywhere in the request path (covers subdirectory installs and multisite sub-sites) and
		 * additionally resolves custom/translated slugs via wc_get_page_id() when
		 * WooCommerce is active; on non-Woo installs only the default slugs apply.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when delay must be skipped for this request.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_delay_excluded_context}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function is_delay_excluded_context(): bool {
			return Script_Strategy::is_delay_excluded_context();
		}

		/**
		 * Signature of the request inputs that drive the delay-context verdict.
		 *
		 * The verdict depends on the request URI, query string, and query
		 * arguments (plus conditional tags, which a real request does not
		 * change mid-flight). Hashing these lets the memo self-invalidate
		 * when a new logical request reuses the same PHP process.
		 *
		 * @since 2.0.0
		 * @return string Signature string.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::delay_context_request_signature}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function delay_context_request_signature(): string {
			return Script_Strategy::delay_context_request_signature();
		}

		/**
		 * Reset the per-request delay-context memo (for tests).
		 *
		 * @since 2.0.0
		 * @return void
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::reset_delay_context_memo}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function reset_delay_context_memo(): void {
			Script_Strategy::reset_delay_context_memo();
		}

		/**
		 * Compute whether the current request must skip delay-JS rewriting.
		 *
		 * @since 2.0.0
		 * @return bool True when delay must be skipped for this request.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::compute_delay_excluded_context}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function compute_delay_excluded_context(): bool {
			return Script_Strategy::compute_delay_excluded_context();
		}

		/**
		 * Whether a request path belongs to a WooCommerce cart/checkout/account page.
		 *
		 * Matches the slug as a full path segment anywhere in the request path
		 * (fail-safe: covers subdirectory/multisite prefixes such as /shop/checkout
		 * and /subsite/cart; a non-Woo page containing the segment is also
		 * treated as dynamic).
		 * Custom/translated slugs are resolved via wc_get_page_id() when
		 * WooCommerce is active; otherwise only the default slugs apply.
		 *
		 * @since 2.0.0
		 *
		 * @param string $local_path Request path with a leading slash.
		 * @return bool True when the path is a Woo page path.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::matches_woo_page_path}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function matches_woo_page_path( string $local_path ): bool {
			return Script_Strategy::matches_woo_page_path( $local_path );
		}

		/**
		 * Get the delay strategy for a given script handle.
		 *
		 * Checks idle list, viewport list, and then falls back to default strategy.
		 *
		 * Contract: callers must gate on is_delay_third_party_auto_candidate()
		 * first; this resolver does NOT re-check the allowlist or the
		 * builder/commerce exclusions. Pass the gate verdict via
		 * $is_auto_matched to avoid a second pattern scan; null means
		 * "not precomputed" and falls back to matching here.
		 *
		 * @since 1.9.0
		 *
		 * @param string    $handle          The script handle.
		 * @param string    $tag             Optional script tag markup (for auto-mode src matching).
		 * @param bool|null $is_auto_matched Optional precomputed auto-candidate verdict.
		 * @return string The strategy: 'interaction', 'idle', or 'viewport'.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_strategy_for_handle}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function get_delay_strategy_for_handle( string $handle, string $tag = '', ?bool $is_auto_matched = null ): string {
			return $this->script_strategy()->get_delay_strategy_for_handle( $handle, $tag, $is_auto_matched );
		}

		/**
		 * Get the delay priority for a given script handle.
		 *
		 * @since 1.9.0
		 *
		 * @param string $handle The script handle.
		 * @return string The priority: 'high', 'normal', or 'low'.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_priority_for_handle}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function get_delay_priority_for_handle( string $handle ): string {
			return $this->script_strategy()->get_delay_priority_for_handle( $handle );
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
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::apply_per_page_delay_config}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function apply_per_page_delay_config(): void {
			$this->script_strategy()->apply_per_page_delay_config();
		}

		/**
		 * Curated per-builder Delay JS exclusions (issue #966).
		 *
		 * Builder runtimes must stay un-delayed by default — delaying them
		 * breaks Elementor/Divi/Bricks/WPBakery/Oxygen rendering and the
		 * block-interactivity runtime. Only the exclusion list is shared with
		 * Minify\HTML so the lists cannot drift; matching semantics differ by
		 * design. The external path matches handles via
		 * is_delay_excluded_handle() (exact, dash/underscore variants, pure
		 * prefixes, word-boundary fallback) while the inline path intentionally
		 * over-matches by substring over attributes+content (fail-open
		 * direction), so over/under-exclusion can still diverge.
		 *
		 * @since 2.0.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_builder_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_builder_exclusions(): array {
			return Script_Strategy::get_delay_js_builder_exclusions();
		}

		/**
		 * Curated commerce Delay JS exclusions (issue #988).
		 *
		 * The jQuery plus cart-fragments/checkout handles stay un-delayed when
		 * the commerce preset is on so carts and checkouts never break.
		 * Filterable via wppo_delay_js_commerce_exclusions.
		 *
		 * @since 2.0.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_commerce_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_commerce_exclusions(): array {
			return Script_Strategy::get_delay_js_commerce_exclusions();
		}

		/**
		 * Curated slider Delay JS exclusions (issue #988).
		 *
		 * Slider runtimes stay un-delayed with the builder preset so hero
		 * sliders keep working. Filterable via wppo_delay_js_slider_exclusions.
		 *
		 * @since 2.0.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_slider_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_slider_exclusions(): array {
			return Script_Strategy::get_delay_js_slider_exclusions();
		}

		/**
		 * Curated first-click interaction Delay JS exclusions (issue #1055).
		 *
		 * Popup/dialog, mobile-menu, and add-to-cart handles must stay
		 * un-delayed so first-click interactions never need a second click.
		 * Filterable via wppo_delay_js_interaction_exclusions. Merged into
		 * the global preset when `delayJSInteractionPreset` is on (default).
		 * Per-site settings only; multisite-safe.
		 *
		 * @since 2.0.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_interaction_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_interaction_exclusions(): array {
			return Script_Strategy::get_delay_js_interaction_exclusions();
		}

		/**
		 * One-click Delay-JS preset levels (issue #1385).
		 *
		 * Single source of truth for the Safe / Balanced / Aggressive
		 * one-click presets. Builder plus commerce presets are forced ON at
		 * every level so carts, checkouts, and builder runtimes never break.
		 *
		 * @since 2.3.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_preset_levels}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_preset_levels(): array {
			return Script_Strategy::get_delay_js_preset_levels();
		}

		/**
		 * Toggle map applied by a one-click Delay-JS preset level (issue #1385).
		 *
		 * Maps a level to the existing exclusion-getter toggles only — no new
		 * delay semantics. Fail-open: unknown levels degrade to the safe map.
		 * Guarded by function_exists/has_filter plus a legacy fallback so the
		 * current delay path is used when the filter API is unavailable.
		 *
		 * @since 2.3.0
		 *
		 * @param string $level Preset level (safe|balanced|aggressive).
		 * @return array<string, bool>
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_preset_level_settings}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_preset_level_settings( string $level ): array {
			return Script_Strategy::get_delay_js_preset_level_settings( $level );
		}

		/**
		 * Merged exclusion list for a one-click Delay-JS preset level (issue #1385).
		 *
		 * Maps Safe / Balanced / Aggressive to the existing exclusion getters
		 * only (builder, slider, commerce, interaction, consent, analytics,
		 * gallery, jquery plus the always-on base preset). Manual exclusions
		 * and the delayJSThirdPartyAuto patterns are merged by the caller, so
		 * the manual textarea plus filter always win. Fail-open: any failure
		 * degrades to the base preset list (safe direction — pages exclude
		 * more, never delay everything), never fatal.
		 *
		 * @since 2.3.0
		 *
		 * @param string $level Preset level (safe|balanced|aggressive).
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_preset_level_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_preset_level_exclusions( string $level ): array {
			return Script_Strategy::get_delay_js_preset_level_exclusions( $level );
		}

		/**
		 * Apply a preset exclusion filter with fail-open guards (issue #1308).
		 *
		 * Shared by the opt-in compat presets so a misbehaving filter callback
		 * degrades to the curated list, never fatal and never white screen.
		 * Guarded by function_exists/has_filter so behaviour is identical with
		 * and without the filter API (WP 6.2+ always provides it).
		 *
		 * @since 2.2.0
		 *
		 * @param string   $filter Filter hook name.
		 * @param string[] $preset Curated preset exclusions.
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::filter_compat_preset_list}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function filter_compat_preset_list( string $filter, array $preset ): array {
			return Script_Strategy::filter_compat_preset_list( $filter, $preset );
		}

		/**
		 * Curated consent Delay JS exclusions (issue #1308).
		 *
		 * Consent banners and scanners (CookieYes, Cookiebot, Complianz,
		 * Borlabs, OneTrust, …) must stay un-delayed when the consent preset
		 * is on so banners render and scans see the real scripts. Opt-in
		 * (default off); merged additively, never replacing manual excludes.
		 * Filterable via wppo_delay_js_consent_exclusions. Fail-open: any
		 * filter error degrades to the curated list (un-delayed output).
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_consent_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_consent_exclusions(): array {
			return Script_Strategy::get_delay_js_consent_exclusions();
		}

		/**
		 * Curated analytics Delay JS exclusions (issue #1308).
		 *
		 * Analytics beacons (GA4 gtag, Matomo, Plausible, …) must stay
		 * un-delayed when the analytics preset is on so hits are not lost
		 * before interaction. Opt-in (default off); additive merge only.
		 * Filterable via wppo_delay_js_analytics_exclusions. Fail-open to
		 * the curated list on any filter error.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_analytics_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_analytics_exclusions(): array {
			return Script_Strategy::get_delay_js_analytics_exclusions();
		}

		/**
		 * Curated gallery Delay JS exclusions (issue #1308).
		 *
		 * Galleries and lightboxes beyond the slider runtimes (PhotoSwipe,
		 * Fancybox, Envira, FooGallery, …) must stay un-delayed when the
		 * gallery preset is on so they work pre-interaction. Opt-in
		 * (default off); additive merge only. Filterable via
		 * wppo_delay_js_gallery_exclusions. Fail-open to the curated list.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_gallery_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_gallery_exclusions(): array {
			return Script_Strategy::get_delay_js_gallery_exclusions();
		}

		/**
		 * Curated jQuery-legacy Delay JS exclusions (issue #1308).
		 *
		 * Legacy jQuery-dependent widgets on non-Woo sites must stay un-delayed
		 * when the jquery preset is on. The commerce preset already owns the
		 * core jQuery/cart handles for shops; this opt-in preset (default
		 * off) extends cover to jQuery UI/plugins for legacy themes.
		 * Filterable via wppo_delay_js_jquery_exclusions. Fail-open to the
		 * curated list on any filter error.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_jquery_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_jquery_exclusions(): array {
			return Script_Strategy::get_delay_js_jquery_exclusions();
		}

		/**
		 * Compat preset slugs keyed by their settings key (issue #1308).
		 *
		 * Single source of truth for the four opt-in presets: settings key
		 * => preset slug used in per-page opt-out meta.
		 *
		 * @since 2.2.0
		 * @return array<string, string>
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_compat_preset_map}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_compat_preset_map(): array {
			return Script_Strategy::get_delay_js_compat_preset_map();
		}

		/**
		 * Exclusion list for one compat preset slug (issue #1308).
		 *
		 * Lazy-boots only the requested matcher so sites without delay pay
		 * zero cost. Fail-open: unknown slugs return an empty list.
		 *
		 * @since 2.2.0
		 *
		 * @param string $slug Preset slug (consent|analytics|gallery|jquery).
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_compat_preset_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_compat_preset_exclusions( string $slug ): array {
			return Script_Strategy::get_delay_js_compat_preset_exclusions( $slug );
		}

		/**
		 * One-click Safe preset bundle (issue #1442).
		 *
		 * Curated `file_optimisation` settings that enable minify plus defer
		 * plus delay together with the builder, commerce, interaction and
		 * jQuery exclusions pre-applied, so page builders, jQuery widgets and
		 * WooCommerce never break. Returns only pre-existing settings keys:
		 * additive, no schema change, safe-by-default. Consent, analytics and
		 * gallery presets stay off (opt-in), combineCSS stays off (FOUC risk).
		 * Multisite-safe: per-site `wppo_settings` only.
		 *
		 * @since 2.3.0
		 * @return array<string, mixed>
		 */
		public static function get_safe_preset_bundle(): array {
			try {
				return array(
					'minifyJS'                 => true,
					'minifyCSS'                => true,
					'minifyHTML'               => true,
					'deferJS'                  => true,
					'delayJS'                  => true,
					'delayJSBuilderPreset'     => true,
					'delayJSCommercePreset'    => true,
					'delayJSInteractionPreset' => true,
					'delayJSJqueryPreset'      => true,
					'delayJSSafeMode'          => true,
					'elementorSafeMode'        => true,
					'delayJSConsentPreset'     => false,
					'delayJSAnalyticsPreset'   => false,
					'delayJSGalleryPreset'     => false,
					'combineCSS'               => false,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Aggressive preset bundle (issue #1442).
		 *
		 * Same pipelines as the Safe preset but with the builder, commerce,
		 * interaction and jQuery safe presets off plus CSS combining on, for
		 * users who manage exclusions manually. UI-gated behind an explicit
		 * warning with one-click revert via the settings snapshot. Returns
		 * only pre-existing settings keys: additive, no schema change.
		 *
		 * @since 2.3.0
		 * @return array<string, mixed>
		 */
		public static function get_aggressive_preset_bundle(): array {
			try {
				return array(
					'minifyJS'                 => true,
					'minifyCSS'                => true,
					'minifyHTML'               => true,
					'deferJS'                  => true,
					'delayJS'                  => true,
					'delayJSBuilderPreset'     => false,
					'delayJSCommercePreset'    => false,
					'delayJSInteractionPreset' => false,
					'delayJSJqueryPreset'      => false,
					'delayJSSafeMode'          => false,
					'elementorSafeMode'        => false,
					'combineCSS'               => true,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Merge a preset bundle additively into file-optimisation settings (issue #1442).
		 *
		 * Only allowlisted pre-existing keys from the bundle are applied; any
		 * unknown key is skipped so a future bundle can never widen the schema
		 * or persist unexpected values. Fail-open: any failure returns the
		 * input unchanged.
		 *
		 * @since 2.3.0
		 * @param array<string, mixed> $current Current file_optimisation settings.
		 * @param array<string, mixed> $bundle  Preset bundle (e.g. get_safe_preset_bundle()).
		 * @return array<string, mixed> Merged settings.
		 */
		public static function apply_preset_bundle( array $current, array $bundle ): array {
			try {
				$allowed = array(
					'minifyJS',
					'minifyCSS',
					'minifyHTML',
					'deferJS',
					'delayJS',
					'delayJSBuilderPreset',
					'delayJSCommercePreset',
					'delayJSInteractionPreset',
					'delayJSJqueryPreset',
					'delayJSSafeMode',
					'elementorSafeMode',
					'delayJSConsentPreset',
					'delayJSAnalyticsPreset',
					'delayJSGalleryPreset',
					'combineCSS',
				);
				foreach ( $bundle as $key => $value ) {
					if ( ! is_string( $key ) || ! in_array( $key, $allowed, true ) ) {
						continue;
					}
					$current[ $key ] = $value;
				}
				return $current;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $current;
			}
		}

		/**
		 * Whether file-optimisation settings match the Safe preset (issue #1442).
		 *
		 * True when the minify/defer/delay pipelines are on together with all
		 * four safe exclusion presets plus the two safe-mode guards
		 * (`delayJSSafeMode`, `elementorSafeMode`) the bundle applies, and
		 * with `combineCSS` off (the bundle pins it false — FOUC risk —
		 * while Aggressive pins it true), so enabling CSS combining after
		 * applying Safe clears the confirmation instead of overstating
		 * safety. `minifyHTML` is part of the pipeline gate because the
		 * bundle pins it true. Fail-open: any failure returns false.
		 *
		 * @since 2.3.0
		 * @param array<string, mixed> $file_opt file_optimisation settings slice.
		 * @return bool
		 */
		public static function is_safe_preset_active( array $file_opt ): bool {
			try {
				foreach ( array( 'minifyJS', 'minifyCSS', 'minifyHTML', 'deferJS', 'delayJS' ) as $key ) {
					if ( empty( $file_opt[ $key ] ) ) {
						return false;
					}
				}
				$required_safe = array(
					'delayJSBuilderPreset',
					'delayJSCommercePreset',
					'delayJSInteractionPreset',
					'delayJSJqueryPreset',
					'delayJSSafeMode',
					'elementorSafeMode',
				);
				foreach ( $required_safe as $key ) {
					if ( empty( $file_opt[ $key ] ) ) {
						return false;
					}
				}
				if ( ! empty( $file_opt['combineCSS'] ) ) {
					return false;
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Per-page disabled compat presets from post meta (issue #1308).
		 *
		 * Reads `_wppo_delay_presets_off` (array of slugs). A page can opt
		 * out of a globally-enabled preset without touching global settings
		 * so exceptions stay surgical. Multisite-safe: per-site post meta,
		 * no cross-site leakage. Fail-open: any detection failure returns
		 * an empty list (no opt-out applied).
		 *
		 * @since 2.2.0
		 *
		 * @param int $post_id Optional post ID. Defaults to the current post.
		 * @return string[]
		 */
		public static function get_page_disabled_delay_presets( int $post_id = 0 ): array {
			try {
				if ( 0 === $post_id ) {
					if ( ! function_exists( 'get_the_ID' ) ) {
						return array();
					}
					$post_id = (int) get_the_ID();
				}
				if ( $post_id <= 0 ) {
					return array();
				}
				// Blog-scoped memo: the external path and the Minify\HTML
				// constructor read the same meta twice per uncached page.
				static $memo = array();
				$blog_id     = 0;
				if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
					try {
						if ( is_multisite() ) {
							$blog_id = (int) get_current_blog_id();
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$blog_id = 0;
					}
				}
				$memo_key = $blog_id . ':' . $post_id;
				if ( isset( $memo[ $memo_key ] ) ) {
					return $memo[ $memo_key ];
				}
				if ( ! function_exists( 'get_post_meta' ) ) {
					return array();
				}
				$raw = get_post_meta( $post_id, '_wppo_delay_presets_off', true );
				if ( ! is_array( $raw ) ) {
					$memo[ $memo_key ] = array();
					return array();
				}
				$allowed = array( 'consent', 'analytics', 'gallery', 'jquery' );
				$off     = array();
				foreach ( $raw as $slug ) {
					// Skip non-scalars: corrupted nested-array meta must not
					// emit an Array-to-string warning on PHP 8.2+.
					if ( ! is_scalar( $slug ) ) {
						continue;
					}
					$slug = strtolower( trim( (string) $slug ) );
					if ( in_array( $slug, $allowed, true ) && ! in_array( $slug, $off, true ) ) {
						$off[] = $slug;
					}
				}
				$memo[ $memo_key ] = $off;
				return $off;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether the current URL matches a newline-separated exclusion list (issue #988).
		 *
		 * Each non-empty line is a case-insensitive URL-substring match, or a
		 * regex when wrapped in valid delimiters (e.g. `#...#`). Fail-open:
		 * any detection failure returns false (no exclusion).
		 *
		 * @since 2.0.0
		 *
		 * @param string $url_list Newline-separated exclusion list.
		 * @return bool True when the current request URL is excluded.
		 */
		public static function is_url_excluded_by_list( string $url_list ): bool {
			try {
				$url_list = trim( $url_list );
				if ( '' === $url_list ) {
					return false;
				}
				$raw_uri     = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
				$request_uri = sanitize_text_field( (string) $raw_uri );
				if ( function_exists( 'wp_parse_url' ) ) {
					$path = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for wp_parse_url(); WP 6.2+ always provides it.
					$qpos = strpos( $request_uri, '?' );
					$path = false === $qpos ? $request_uri : substr( $request_uri, 0, $qpos );
				}
				$haystack = strtolower( $request_uri . ' ' . $path );
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'process_urls' ) ) {
					$lines = Util::process_urls( $url_list );
				} else {
					$lines = array_values( array_filter( array_map( 'trim', explode( "\n", $url_list ) ) ) );
				}
				foreach ( $lines as $line ) {
					$line = trim( (string) $line );
					if ( '' === $line ) {
						continue;
					}
					// Regex-per-line when wrapped in valid delimiters; invalid regex fails open to substring.
					if ( strlen( $line ) > 2 && '#' === $line[0] && false !== strrpos( $line, '#', 1 ) ) {
						$valid = false;
						set_error_handler( static function () {} ); // phpcs:ignore -- Suppress warnings from user-supplied regex validation.
						try {
							$valid = false !== preg_match( $line, '' );
						} catch ( \Throwable $e ) {
							unset( $e );
							$valid = false;
						}
						restore_error_handler();
						if ( $valid ) {
							set_error_handler( static function () {} ); // phpcs:ignore -- Suppress warnings from user-supplied regex matching.
							try {
								$matched = preg_match( $line . 'i', $request_uri );
							} catch ( \Throwable $e ) {
								unset( $e );
								$matched = false;
							}
							restore_error_handler();
							if ( 1 === $matched ) {
								return true;
							}
							continue;
						}
					}
					if ( false !== stripos( $haystack, strtolower( $line ) ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether used CSS must be skipped for the current URL (issue #988).
		 *
		 * Checks the per-URL `usedCSSExcludeUrls` list plus the `_wppo_used_css_disabled`
		 * per-page kill-switch. Fail-open: any detection failure returns false.
		 *
		 * @since 2.0.0
		 * @return bool True when used CSS must be skipped.
		 */
		public static function is_used_css_excluded_for_url(): bool {
			try {
				$list = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					$list     = isset( $settings['file_optimisation']['usedCSSExcludeUrls'] ) ? (string) $settings['file_optimisation']['usedCSSExcludeUrls'] : '';
				}
				if ( '' !== trim( $list ) && self::is_url_excluded_by_list( $list ) ) {
					return true;
				}
				if ( function_exists( 'is_singular' ) && function_exists( 'get_the_ID' ) && function_exists( 'get_post_meta' ) ) {
					try {
						if ( is_singular() ) {
							$post_id = (int) get_the_ID();
							if ( $post_id > 0 && ! empty( get_post_meta( $post_id, '_wppo_used_css_disabled', true ) ) ) {
								return true;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether Delay JS is disabled for a singular page (issue #966).
		 *
		 * Reads the `_wppo_delay_disabled` post-meta kill-switch. Fail-open:
		 * any detection failure returns false (delay stays enabled) except
		 * unexpected throwables, which return false as well — callers already
		 * fail open to original scripts on rewrite errors.
		 *
		 * @since 2.0.0
		 *
		 * @param int $post_id Optional post ID. Defaults to the current post.
		 * @return bool True when delay must be skipped for this page.
		 */
		public static function is_delay_disabled_for_page( int $post_id = 0 ): bool {
			try {
				if ( function_exists( 'is_singular' ) && ! is_singular() && 0 === $post_id ) {
					return false;
				}
				if ( 0 === $post_id ) {
					if ( ! function_exists( 'get_the_ID' ) ) {
						return false;
					}
					$post_id = (int) get_the_ID();
				}
				if ( $post_id <= 0 ) {
					return false;
				}
				// Multisite-safe request cache (#1037): key by blog ID + post ID
				// so `switch_to_blog()` can never leak one site's kill-switch
				// state into another site sharing the same post ID.
				$blog_id = 0;
				if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
					try {
						if ( is_multisite() ) {
							$blog_id = (int) get_current_blog_id();
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$blog_id = 0;
					}
				}
				$cache_key = $blog_id . ':' . $post_id;
				if ( isset( self::$delay_disabled_page_cache[ $cache_key ] ) ) {
					return self::$delay_disabled_page_cache[ $cache_key ];
				}
				if ( ! function_exists( 'get_post_meta' ) ) {
					return false;
				}
				$disabled                                      = ! empty( get_post_meta( $post_id, '_wppo_delay_disabled', true ) );
				self::$delay_disabled_page_cache[ $cache_key ] = $disabled;
				return $disabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the unified safe-mode kill switch is enabled (issue #1098).
		 *
		 * When on, delay-JS + defer-JS + remove-unused-CSS (and Critical-CSS
		 * stylesheet deferral) are all disabled in one click while the
		 * underlying `delayJS` / `deferJS` / `removeUnusedCSS` settings are
		 * preserved untouched, so turning safe mode back off restores the
		 * previous configuration without re-entering settings (one-click
		 * recovery). Additive `file_optimisation.safeMode` key, defaults to
		 * off. Filterable via `wppo_safe_mode_enabled` (has_filter-guarded,
		 * fail-open to the stored setting). Multisite-safe: per-site settings.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when aggressive optimisations must be skipped.
		 */
		public function is_safe_mode_enabled(): bool {
			return self::is_safe_mode_active( $this->get_options()['file_optimisation'] ?? array() );
		}

		/**
		 * Whether the current request is a valid sandbox asset-preview request.
		 *
		 * Delegates to Sandbox_Preview::is_preview_request() (admin-only query
		 * param + nonce). Fail-open to false when the controller is missing.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when experimental preview output may render.
		 */
		public static function is_sandbox_preview_active(): bool {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'is_preview_request' ) ) {
					return (bool) Sandbox_Preview::is_preview_request();
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Effective file_optimisation slice for this request.
		 *
		 * Visitors get production unchanged; admin preview requests get
		 * production overlaid with staged sandbox values. Fail-open to
		 * production on any error.
		 *
		 * @since 2.2.0
		 *
		 * @param array $file_optimisation Production slice.
		 * @return array Effective slice.
		 */
		public static function get_effective_file_optimisation( array $file_optimisation = array() ): array {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'get_effective_file_optimisation' ) ) {
					return Sandbox_Preview::get_effective_file_optimisation( $file_optimisation );
				}
				return $file_optimisation;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $file_optimisation;
			}
		}

		/**
		 * Static safe-mode predicate shared by Main / Used_CSS / Critical_CSS.
		 *
		 * Reads `file_optimisation.safeMode` from the passed settings (or from
		 * `Util::get_settings()` when empty) so static buffer callbacks that
		 * have no Main instance can gate identically. Any failure fails open
		 * to disabled (optimisations run) except an explicit stored `true`.
		 *
		 * @since 2.2.0
		 *
		 * @param array $file_optimisation Optional `file_optimisation` settings slice.
		 * @return bool True when safe mode is on.
		 */
		public static function is_safe_mode_active( array $file_optimisation = array() ): bool {
			try {
				if ( empty( $file_optimisation ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					try {
						$settings          = (array) Util::get_settings();
						$file_optimisation = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$enabled = ! empty( $file_optimisation['safeMode'] );
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_safe_mode_enabled' ) ) {
					try {
						$filtered = apply_filters( 'wppo_safe_mode_enabled', $enabled );
						return (bool) $filtered;
					} catch ( \Throwable $e ) {
						unset( $e );
						return $enabled;
					}
				}
				return $enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether aggressive optimisations must be bypassed for this request.
		 *
		 * Shared nocache bypass (issue #1098) for delay + defer + used-CSS +
		 * Critical-CSS deferral: `?nocache` / `?wppo_nocache` query args and
		 * preview contexts (`is_preview()` when available,
		 * function_exists-guarded for WP 6.2+ compat). `DONOTCACHEPAGE` is
		 * intentionally NOT checked here: it gates page-cache storage (and
		 * Used_CSS::process_buffer() keeps its own pre-existing explicit
		 * check), while delay/defer rewriting still applies on such pages.
		 * Any detection failure fails open to bypassed (unoptimised output,
		 * never fatal). Multisite-safe: request-local only.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when optimisations must be skipped for this request.
		 */
		public static function is_aggressive_bypass_active(): bool {
			try {
				if ( function_exists( 'is_preview' ) ) {
					try {
						if ( is_preview() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( isset( $_GET['nocache'] ) || isset( $_GET['wppo_nocache'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only bypass check, no state change.
					return true;
				}
				if ( ! empty( $_SERVER['QUERY_STRING'] ) && function_exists( 'wp_unslash' ) ) {
					$qs = (string) wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only bypass check, no output.
					if ( preg_match( '/(?:^|&)(nocache|wppo_nocache)(?:=|&|$)/i', $qs ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Curated defer-JS preset exclusions (issue #1098).
		 *
		 * Built-in jQuery + Elementor/Divi + WooCommerce handles stay un-deferred by
		 * default so carts, checkouts, and builders never break. Filterable
		 * via `wppo_defer_js_preset_exclusions` (has_filter-guarded, fail-open
		 * to the built-in preset). Merged with user `excludeDeferJS` via
		 * array_unique by callers. Per-site settings only; multisite-safe.
		 *
		 * @since 2.2.0
		 *
		 * @return string[]
		 */
		public static function get_defer_js_preset_exclusions(): array {
			$preset = array(
				'jquery',
				'jquery-core',
				'jquery-migrate',
				'elementor-frontend',
				'elementor-pro-frontend',
				'elementor-common',
				'et-core-api',
				'divi-custom-script',
				'wc-cart-fragments',
				'wc-checkout',
				'woocommerce',
				'wc-add-to-cart',
				'add-to-cart',
				'cart-fragments',
				'wc-blocks',
				'wc-store',
				// Interactivity runtime guard (issue #1201): never deprioritize
				// or move the block-interactivity runtime. Covers the classic
				// handle plus the 6.5+ module ids, mirroring
				// apply_module_loading_strategies(). Fail-open append.
				//
				// @since 2.2.0.
				'wp-interactivity',
				'@wordpress/interactivity',
				'@wordpress/interactivity-router',
			);
			/**
			 * Filters defer-JS preset exclusions.
			 *
			 * @since 2.2.0
			 * @param string[] $preset Defer preset exclusions.
			 */
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_defer_js_preset_exclusions' ) ) {
				return $preset;
			}
			try {
				$raw = apply_filters( 'wppo_defer_js_preset_exclusions', $preset );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $preset;
			}
			if ( ! is_array( $raw ) ) {
				return $preset;
			}
			return array_values(
				array_unique(
					array_filter(
						array_map(
							static function ( $val ): string {
								return is_string( $val ) || is_numeric( $val ) ? (string) $val : '';
							},
							$raw
						),
						static function ( $val ): bool {
							return '' !== $val;
						}
					)
				)
			);
		}

		/**
		 * Fragile-handle map for the auto-exclude detector (issue #1465).
		 *
		 * Ordered jQuery first, then cart fragments, then builders so the
		 * detector names the most breakage-prone handle first. Each entry
		 * maps a lowercase handle fragment to its exclude field(s) and a
		 * short human-readable reason. Filterable via
		 * `wppo_fragile_handle_map` (has_filter-guarded, fail-open to the
		 * built-in map). Multisite-safe: static data only.
		 *
		 * @since 2.3.0
		 *
		 * @return array<string,array{fields:string[],reason:string}> Fragment => meta.
		 */
		public static function get_fragile_handle_map(): array {
			$map = array(
				'jquery-core'            => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'jQuery core — deferring or delaying breaks dependent scripts.',
				),
				'jquery-migrate'         => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'jQuery Migrate — deferring or delaying breaks dependent scripts.',
				),
				'jquery'                 => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'jQuery — deferring or delaying breaks dependent scripts.',
				),
				'wc-cart-fragments'      => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce cart fragments — delaying breaks the mini-cart AJAX refresh.',
				),
				'cart-fragments'         => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce cart fragments — delaying breaks the mini-cart AJAX refresh.',
				),
				'wc-checkout'            => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce checkout — deferring breaks payment and validation scripts.',
				),
				'wc-add-to-cart'         => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce add-to-cart — delaying breaks shop interactions.',
				),
				'wc-blocks'              => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce Blocks — deferring breaks Store API interactivity.',
				),
				'wc-store'               => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce Store API — deferring breaks cart and checkout blocks.',
				),
				'woocommerce'            => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'WooCommerce — deferring breaks cart and checkout flows.',
				),
				'elementor-frontend'     => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'Elementor frontend runtime — delaying breaks builder layout and widgets.',
				),
				'elementor-pro-frontend' => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'Elementor Pro runtime — delaying breaks builder widgets.',
				),
				'et-core-api'            => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'Divi builder runtime — delaying breaks builder layout.',
				),
				'divi-custom-script'     => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'Divi custom script — delaying breaks builder layout.',
				),
				'kadence'                => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'Kadence runtime — delaying breaks blocks and layout.',
				),
				'wp-interactivity'       => array(
					'fields' => array( 'excludeDeferJS', 'excludeDelayJS' ),
					'reason' => 'Block interactivity runtime — deferring breaks interactive blocks.',
				),
			);
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_fragile_handle_map' ) ) {
				return $map;
			}
			try {
				$raw = apply_filters( 'wppo_fragile_handle_map', $map );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $map;
			}
			if ( ! is_array( $raw ) ) {
				return $map;
			}
			$sanitized = array();
			foreach ( $raw as $fragment => $meta ) {
				if ( ! is_string( $fragment ) && ! is_numeric( $fragment ) ) {
					continue;
				}
				$fragment = trim( (string) $fragment );
				if ( '' === $fragment || ! is_array( $meta ) ) {
					continue;
				}
				$key = strtolower( $fragment );
				if ( isset( $sanitized[ $key ] ) ) {
					continue;
				}
				$allowed = array( 'excludeDeferJS', 'excludeDelayJS' );
				$fields  = array();
				if ( isset( $meta['fields'] ) && is_array( $meta['fields'] ) ) {
					foreach ( $meta['fields'] as $field ) {
						if ( is_string( $field ) || is_numeric( $field ) ) {
							$field = trim( (string) $field );
							if ( in_array( $field, $allowed, true ) ) {
								$fields[] = $field;
							}
						}
					}
					$fields = array_values( array_unique( $fields ) );
				}
				if ( empty( $fields ) ) {
					$fields = array( 'excludeDeferJS', 'excludeDelayJS' );
				}
				$reason            = isset( $meta['reason'] ) && is_string( $meta['reason'] ) ? $meta['reason'] : '';
				$sanitized[ $key ] = array(
					'fields' => $fields,
					'reason' => $reason,
				);
			}
			return ! empty( $sanitized ) ? $sanitized : $map;
		}

		/**
		 * Map enqueued handles to fragile-handle exclude suggestions (issue #1465).
		 *
		 * Case-insensitive substring match against {@see get_fragile_handle_map()},
		 * jQuery/cart/builders first via map order. Returns at most 20
		 * suggestions, deduped by handle. Fail-open: any failure returns an
		 * empty array (unoptimised guidance only, never fatal).
		 *
		 * @since 2.3.0
		 *
		 * @param string[] $handles Enqueued script/style handles.
		 * @return array<int,array{handle:string,fields:string[],reason:string}> Suggestions.
		 */
		public static function detect_fragile_handles( array $handles ): array {
			try {
				$map = self::get_fragile_handle_map();
				if ( empty( $map ) || empty( $handles ) ) {
					return array();
				}
				$suggestions = array();
				$seen        = array();
				foreach ( $handles as $handle ) {
					if ( ! is_string( $handle ) && ! is_numeric( $handle ) ) {
						continue;
					}
					$handle = (string) $handle;
					if ( '' === $handle || isset( $seen[ strtolower( $handle ) ] ) ) {
						continue;
					}
					$lower = strtolower( $handle );
					foreach ( $map as $fragment => $meta ) {
						$frag = strtolower( (string) $fragment );
						if ( '' === $frag ) {
							continue;
						}
						if ( false !== strpos( $lower, $frag ) ) {
							$fields                        = isset( $meta['fields'] ) && is_array( $meta['fields'] ) ? array_values( $meta['fields'] ) : array( 'excludeDeferJS', 'excludeDelayJS' );
							$reason                        = isset( $meta['reason'] ) && is_string( $meta['reason'] ) ? $meta['reason'] : '';
							$suggestions[]                 = array(
								'handle' => $handle,
								'fields' => $fields,
								'reason' => $reason,
							);
							$seen[ strtolower( $handle ) ] = true;
							break;
						}
					}
					if ( count( $suggestions ) >= 20 ) {
						break;
					}
				}
				return $suggestions;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Current minify/combine/defer stack state for safe mode (issue #1465).
		 *
		 * Single choke point for the detector REST endpoint and the
		 * one-click UI so the stack definition cannot drift between
		 * call sites. Fail-open to all-off on any failure.
		 *
		 * @since 2.3.0
		 *
		 * @param array $file_optimisation Optional `file_optimisation` slice.
		 * @return array{safe_mode:bool,delay_js:bool,defer_js:bool,combine_css:bool,remove_unused_css:bool,stack_enabled:bool} Stack flags.
		 */
		public static function get_safe_mode_stack_state( array $file_optimisation = array() ): array {
			try {
				if ( empty( $file_optimisation ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					try {
						$settings          = (array) Util::get_settings();
						$file_optimisation = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$safe_mode   = self::is_safe_mode_active( $file_optimisation );
				$delay_js    = ! empty( $file_optimisation['delayJS'] );
				$defer_js    = ! empty( $file_optimisation['deferJS'] );
				$combine_css = ! empty( $file_optimisation['combineCSS'] );
				$remove_css  = ! empty( $file_optimisation['removeUnusedCSS'] );
				return array(
					'safe_mode'         => $safe_mode,
					'delay_js'          => $delay_js,
					'defer_js'          => $defer_js,
					'combine_css'       => $combine_css,
					'remove_unused_css' => $remove_css,
					'stack_enabled'     => ( $delay_js || $defer_js || $combine_css ) && ! $safe_mode,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'safe_mode'         => false,
					'delay_js'          => false,
					'defer_js'          => false,
					'combine_css'       => false,
					'remove_unused_css' => false,
					'stack_enabled'     => false,
				);
			}
		}

		/**
		 * Build the one-click safe-mode enable payload (issue #1465).
		 *
		 * Returns the production `file_optimisation` slice with `safeMode`
		 * forced on while every other setting is preserved untouched, so
		 * disabling safe mode later restores the previous configuration
		 * without re-entering settings. Pure function for testability;
		 * persistence lives in the REST handler (per-site wppo_settings).
		 * Fail-open: any failure returns the input unchanged with safeMode on.
		 *
		 * @since 2.3.0
		 *
		 * @param array $file_optimisation Production slice.
		 * @return array Slice with safeMode enabled.
		 */
		public static function build_safe_mode_enable_payload( array $file_optimisation = array() ): array {
			try {
				$file_optimisation['safeMode'] = true;
				return $file_optimisation;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array( 'safeMode' => true );
			}
		}

		/**
		 * Whether defer-JS is disabled for a singular page (issue #1098).
		 *
		 * Reads the `_wppo_defer_disabled` post-meta kill-switch. Mirrors
		 * {@see is_delay_disabled_for_page()} with its own blog-scoped
		 * request cache so delay/defer states never cross-contaminate.
		 * Fail-open: any detection failure returns false (defer stays enabled).
		 *
		 * @since 2.2.0
		 *
		 * @param int $post_id Optional post ID. Defaults to the current post.
		 * @return bool True when defer must be skipped for this page.
		 */
		public static function is_defer_disabled_for_page( int $post_id = 0 ): bool {
			try {
				if ( function_exists( 'is_singular' ) && ! is_singular() && 0 === $post_id ) {
					return false;
				}
				if ( 0 === $post_id ) {
					if ( ! function_exists( 'get_the_ID' ) ) {
						return false;
					}
					$post_id = (int) get_the_ID();
				}
				if ( $post_id <= 0 ) {
					return false;
				}
				$blog_id = 0;
				if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) ) {
					try {
						if ( is_multisite() ) {
							$blog_id = (int) get_current_blog_id();
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$blog_id = 0;
					}
				}
				$cache_key = $blog_id . ':' . $post_id;
				if ( isset( self::$defer_disabled_page_cache[ $cache_key ] ) ) {
					return self::$defer_disabled_page_cache[ $cache_key ];
				}
				if ( ! function_exists( 'get_post_meta' ) ) {
					return false;
				}
				$disabled                                      = ! empty( get_post_meta( $post_id, '_wppo_defer_disabled', true ) );
				self::$defer_disabled_page_cache[ $cache_key ] = $disabled;
				return $disabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Clear the per-page delay kill-switch request cache and purge that URL only.
		 *
		 * Called when the `_wppo_delay_disabled` meta toggles (metabox save or
		 * programmatic meta write) so the next frontend hit for that URL renders
		 * with the new delay state. Purges only the single post URL's static
		 * HTML/CSS sidecars via `Cache::invalidate_single_static_html()` — never
		 * a full-cache wipe. Multisite-safe: per-site post/meta, domain-based
		 * cache paths, no cross-site leakage. Fail-open: any failure is
		 * swallowed so meta saves never fatal.
		 *
		 * @since 2.0.0
		 * @param int $post_id Post ID whose kill-switch changed.
		 * @return void
		 */
		public static function invalidate_delay_kill_switch_cache( int $post_id ): void {
			self::invalidate_aggressive_kill_switch_cache( $post_id );
		}

		/**
		 * Clear per-page aggressive-optimisation caches and purge that URL only.
		 *
		 * Unified single-URL purge (issue #1098) for the `_wppo_delay_disabled`,
		 * `_wppo_defer_disabled`, and `_wppo_used_css_disabled` per-page
		 * kill-switches so per-page state survives cache clears: post meta
		 * itself is never stored in the page cache, and toggling any of the
		 * three metas purges only that post URL's static HTML/CSS/used-CSS
		 * sidecars via `Cache::invalidate_single_static_html()` — never a
		 * full-cache wipe. Multisite-safe: per-site post/meta, domain-based
		 * cache paths, no cross-site leakage. Fail-open: swallowed.
		 *
		 * @since 2.2.0
		 * @param int $post_id Post ID whose kill-switch changed.
		 * @return void
		 */
		public static function invalidate_aggressive_kill_switch_cache( int $post_id ): void {
			try {
				if ( $post_id <= 0 ) {
					return;
				}
				// Bust this request's cached kill-switch entries for the post on
				// every known blog key (at most a handful of entries). Keys are
				// always `blog_id:post_id`, so match on the suffix only.
				foreach ( array_keys( self::$delay_disabled_page_cache ) as $key ) {
					if ( str_ends_with( (string) $key, ':' . (string) $post_id ) ) {
						unset( self::$delay_disabled_page_cache[ $key ] );
					}
				}
				foreach ( array_keys( self::$defer_disabled_page_cache ) as $key ) {
					if ( str_ends_with( (string) $key, ':' . (string) $post_id ) ) {
						unset( self::$defer_disabled_page_cache[ $key ] );
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					try {
						$settings = array();
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
							$settings = (array) Util::get_settings();
						}
						$cache = self::create_cache( $settings );
						if ( is_object( $cache ) && method_exists( $cache, 'invalidate_single_static_html' ) ) {
							$cache->invalidate_single_static_html( $post_id );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Handle aggressive-optimisation meta writes for the per-page kill-switches.
		 *
		 * Wired to `added_post_meta` / `updated_post_meta` / `deleted_post_meta`
		 * in `setup_hooks()` so programmatic meta changes (REST, WP-CLI, imports)
		 * purge the single URL just like the metabox save path. Reacts to the
		 * `_wppo_delay_disabled`, `_wppo_defer_disabled`, and
		 * `_wppo_used_css_disabled` keys; everything else is ignored. Fail-open:
		 * detection or purge failures never fatal the meta write.
		 *
		 * @since 2.2.0
		 * @param mixed  $meta_id  Meta row ID for added/updated hooks, or an array of IDs for deleted_post_meta (unused, required by hook signature).
		 * @param int    $post_id  Post ID the meta belongs to.
		 * @param string $meta_key Meta key that was written.
		 * @return void
		 */
		public function on_aggressive_kill_switch_meta_changed( $meta_id, $post_id, $meta_key ): void {
			try {
				$key = (string) $meta_key;
				if ( '_wppo_delay_disabled' !== $key && '_wppo_defer_disabled' !== $key && '_wppo_used_css_disabled' !== $key ) {
					return;
				}
				self::invalidate_aggressive_kill_switch_cache( (int) $post_id );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Handle `_wppo_delay_disabled` meta writes for the per-page kill-switch.
		 *
		 * Wired to `added_post_meta` / `updated_post_meta` / `deleted_post_meta`
		 * in `setup_hooks()` so programmatic meta changes (REST, WP-CLI, imports)
		 * purge the single URL just like the metabox save path. Only reacts to
		 * the `_wppo_delay_disabled` key; everything else is ignored. Fail-open:
		 * detection or purge failures never fatal the meta write.
		 *
		 * @since 2.0.0
		 * @param mixed  $meta_id  Meta row ID for added/updated hooks, or an array of IDs for deleted_post_meta (unused, required by hook signature).
		 * @param int    $post_id  Post ID the meta belongs to.
		 * @param string $meta_key Meta key that was written.
		 * @return void
		 */
		public function on_delay_kill_switch_meta_changed( $meta_id, $post_id, $meta_key ): void {
			$this->on_aggressive_kill_switch_meta_changed( $meta_id, $post_id, $meta_key );
		}

		/**
		 * Curated third-party Delay-JS denylist (one-click delay, issue #1217).
		 *
		 * Host/keyword fragments that are safe to delay with one click:
		 * analytics, ads, social, chat and video embeds. Payment gateways
		 * (Stripe, PayPal) and consent-management banners (Cookiebot,
		 * OneTrust, TrustArc, Quantcast) are intentionally NOT in this
		 * preset: payment SDKs also run on product pages (express checkout)
		 * and site-wide (fraud detection), and consent banners must stay
		 * eager for GDPR/ePrivacy ordering (consent before trackers). Users
		 * who want them delayed can add them via the extra-denylist
		 * textarea. The user allowlist always wins over this list.
		 * Filterable via `wppo_delay_js_third_party_denylist`. Fail-open:
		 * filter failures fall back to the curated preset.
		 *
		 * Note: no per-request memoization is used on purpose — the filter
		 * call is cheap and caching would go stale on mid-request
		 * add/remove_filter, switch_to_blog, or sequential unit tests.
		 *
		 * Keep-in-sync note: this denylist overlaps ~15 vendor hosts with the
		 * auto-mode preset in get_delay_js_third_party_auto_patterns()
		 * (#1314). The lists are intentionally separate (manual mode pairs
		 * keywords with a generic cross-origin rule; auto mode matches known
		 * vendors only), so adding a vendor may need an edit in both places.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_third_party_denylist}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_third_party_denylist(): array {
			return Script_Strategy::get_delay_js_third_party_denylist();
		}

		/**
		 * Parse the user-configured third-party allowlist for a settings slice.
		 *
		 * Single shared helper for the Main and Minify\HTML auto-delay mirrors
		 * so allowlist semantics stay in one place. Reads
		 * `file_optimisation.delayJSThirdPartyAllowlist` (one entry per line)
		 * plus the `wppo_delay_js_third_party_allowlist` filter. Fail-open:
		 * any failure returns an empty list. Memoized per request keyed by the
		 * raw value when no filter is registered; bypassed when a filter is
		 * present so dynamic callbacks always run.
		 *
		 * @since 2.2.0
		 * @param array $file_opt Effective file_optimisation slice.
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_third_party_allowlist_for_slice}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_third_party_allowlist_for_slice( array $file_opt ): array {
			return Script_Strategy::get_delay_js_third_party_allowlist_for_slice( $file_opt );
		}

		/**
		 * Parse the user-configured third-party allowlist (wins over denylist).
		 *
		 * Reads the effective (sandbox-staged) slice so preview renders staged
		 * edits instead of production values on the script_loader_tag path.
		 * Delegates to get_delay_js_third_party_allowlist_for_slice().
		 * Fail-open: any failure returns an empty list.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_third_party_allowlist}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function get_delay_js_third_party_allowlist(): array {
			return $this->script_strategy()->get_delay_js_third_party_allowlist();
		}

		/**
		 * Whether a script tag/handle is a third-party delay candidate.
		 *
		 * When one-click third-party delay (`delayJSThirdParty`) is on, only
		 * external scripts whose src host differs from the site host — or
		 * whose handle/src matches the curated denylist plus user additions —
		 * are delayed. Inline scripts (no src) are never delayed in this mode.
		 * The user allowlist always wins (returns false). Any detection
		 * failure fails open to false (leave un-delayed).
		 *
		 * Matching semantics: handles use word-boundary matching (consistent
		 * with the rest of delay matching); src/URL matching is substring.
		 *
		 * @since 2.2.0
		 * @param string $tag    Script tag markup.
		 * @param string $handle Script handle.
		 * @return bool True when the script should be delayed in third-party mode.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_delay_third_party_candidate}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function is_delay_third_party_candidate( string $tag, string $handle ): bool {
			return $this->script_strategy()->is_delay_third_party_candidate( $tag, $handle );
		}

		/**
		 * Whether two script hosts belong to the same site (issue #1217 review).
		 *
		 * Hosts are lowercased, trailing dots trimmed, and a leading `www.`
		 * stripped, then compared equal-or-subdomain in either direction, so a
		 * first-party CDN (`cdn.example.com`), `www` vs apex mismatches, and
		 * apex-vs-subdomain pairs stay eager instead of being misclassified as
		 * third-party. Only genuinely foreign hosts auto-qualify. Fail-open to
		 * false (not same-site) on any error.
		 *
		 * Note: sibling subdomains sharing only a parent (e.g.
		 * `shop.example.com` vs `cdn.example.com`) are conservatively treated
		 * as third-party; add the CDN host to the allowlist in that setup.
		 *
		 * @since 2.2.0
		 * @param string $a First host.
		 * @param string $b Second host.
		 * @return bool True when both hosts belong to the same site.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_same_site_script_host}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function is_same_site_script_host( string $a, string $b ): bool {
			return Script_Strategy::is_same_site_script_host( $a, $b );
		}

		/**
		 * Labelled auto third-party vendor categories (issue #1385).
		 *
		 * Single source of truth for the auto detector: analytics, ads, and
		 * social buckets (chat/video/embeds roll into social so every curated
		 * vendor carries exactly one label). The flat pattern list in
		 * get_delay_js_third_party_auto_patterns() merges these buckets, so
		 * the categories can never drift from the matcher. Filterable via
		 * wppo_delay_js_third_party_auto_categories (has_filter-guarded,
		 * fail-open to the curated buckets).
		 *
		 * @since 2.3.0
		 * @return array<string, string[]>
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_third_party_auto_categories}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_third_party_auto_categories(): array {
			return Script_Strategy::get_delay_js_third_party_auto_categories();
		}

		/**
		 * Label a script src/handle with its auto third-party category (issue #1385).
		 *
		 * Returns analytics, ads, or social for curated vendors, or an empty
		 * string when the input is not an auto candidate (including
		 * WooCommerce fragments plus cart AJAX, which are skipped via
		 * get_delay_js_commerce_exclusions()). Fail-open: any failure
		 * returns an empty string (unlabelled, left eager).
		 *
		 * @since 2.3.0
		 *
		 * @param string $src_or_handle Script src URL, tag markup, or handle.
		 * @return string Category label or empty string.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_third_party_auto_label}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_third_party_auto_label( string $src_or_handle ): string {
			return Script_Strategy::get_delay_js_third_party_auto_label( $src_or_handle );
		}

		/**
		 * Reset the auto third-party pattern memo (for tests).
		 *
		 * @since 2.2.0
		 * @return void
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::reset_delay_third_party_auto_cache}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function reset_delay_third_party_auto_cache(): void {
			Script_Strategy::reset_delay_third_party_auto_cache();
		}

		/**
		 * Curated known-vendor URL patterns for auto third-party delay (#1314).
		 *
		 * URL-host-oriented fragments (analytics, ads, social, chat, video
		 * embeds, error tracking) matched as substrings against the script
		 * src. Filterable via `wppo_delay_js_third_party_auto_patterns`
		 * (has_filter-guarded; callbacks should merge/append rather than
		 * replace). Fail-open: non-array or throwing callbacks fall back to
		 * the built-in preset; a valid empty array is honored and disables
		 * auto mode (silent no-op by explicit filter choice). Lazily booted:
		 * callers must only invoke this when Delay-JS (and the auto toggle)
		 * is enabled. Memoized per blog id when no filter is registered so
		 * multisite sites with blog-dependent filters never share memoized
		 * patterns; bypassed when a filter is present so dynamic callbacks
		 * always run.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_third_party_auto_patterns}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_third_party_auto_patterns(): array {
			return Script_Strategy::get_delay_js_third_party_auto_patterns();
		}

		/**
		 * Whether a handle/tag matches the curated auto third-party patterns (#1314).
		 *
		 * Handles use word-boundary matching (consistent with the rest of delay
		 * matching) via a single precompiled alternation; the src extracted
		 * from the tag (or a full tag passed as $tag) uses substring matching
		 * because URLs rarely align on word boundaries. Handle matching uses
		 * the pre-slash segment only (e.g. `linkedin.com` for
		 * `linkedin.com/insight`) because WP handles never contain slashes.
		 * Fail-open to false on any error. The pattern list lazy-boots here,
		 * so callers must gate on the auto toggle first.
		 *
		 * @since 2.2.0
		 * @param string $handle Script handle (may be empty on buffered paths).
		 * @param string $tag    Script tag markup or src URL.
		 * @return bool True on match.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::matches_third_party_auto_pattern}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function matches_third_party_auto_pattern( string $handle, string $tag ): bool {
			return Script_Strategy::matches_third_party_auto_pattern( $handle, $tag );
		}

		/**
		 * Whether a script tag/handle is an auto third-party delay candidate (#1314).
		 *
		 * Builder and commerce exclusions are unconditional: excluded contexts
		 * (cart/checkout/account, builder previews) never auto-delay. The user
		 * allowlist always wins. Manual exclusions and per-page overrides are
		 * applied by the caller (add_defer_attribute) after this gate, so auto
		 * patterns merge additively and never replace them. Any detection
		 * failure fails open to false (leave un-delayed).
		 *
		 * @since 2.2.0
		 * @param string $tag    Script tag markup.
		 * @param string $handle Script handle.
		 * @return bool True when the script should be delayed in auto mode.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_delay_third_party_auto_candidate}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function is_delay_third_party_auto_candidate( string $tag, string $handle ): bool {
			return $this->script_strategy()->is_delay_third_party_auto_candidate( $tag, $handle );
		}

		/**
		 * Inject an attribute string into a script open tag (issue #1217 review).
		 *
		 * Case-insensitive single-occurrence insert that handles `<script>`,
		 * `<script `, `<script\n` (and uppercase `<SCRIPT …>`) variants, so
		 * every delayed tag is stamped even when core emits non-lowercase
		 * markup. Falls back to the original tag when no script open tag is
		 * found or the rewrite fails.
		 *
		 * @since 2.2.0
		 * @param string $tag    Script tag markup.
		 * @param string $insert Attribute string including trailing space, e.g. 'fetchpriority="low" '.
		 * @return string Tag with the attributes injected.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::inject_delay_script_attr}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function inject_delay_script_attr( string $tag, string $insert ): string {
			return Script_Strategy::inject_delay_script_attr( $tag, $insert );
		}

		/**
		 * Base Delay JS preset exclusions (always applied, issue #966).
		 *
		 * Safe-by-default: WooCommerce, Elementor, and form plugins are always
		 * excluded so checkout and forms never break. Shared by
		 * get_delay_js_preset_exclusions() and
		 * get_delay_js_protected_exclusions() so the per-page opt-out
		 * protection set cannot drift from the merged preset.
		 *
		 * @since 2.2.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_base_preset_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public static function get_delay_js_base_preset_exclusions(): array {
			return Script_Strategy::get_delay_js_base_preset_exclusions();
		}

		/**
		 * Manual + safe exclusions a per-page preset opt-out must never strip (issue #1308).
		 *
		 * The removal list for an opted-out compat preset is diffed against
		 * this set first, so overlapping strings (e.g. gtag, jquery) that are
		 * also contributed by manual exclusions or safe presets stay eager.
		 * Fail-open: any detection failure returns an empty list (no
		 * protection), degrading to the previous subtract behavior.
		 *
		 * @since 2.2.0
		 *
		 * @param array $file_opt file_optimisation settings slice.
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_protected_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private static function get_delay_js_protected_exclusions( array $file_opt ): array {
			return Script_Strategy::get_delay_js_protected_exclusions( $file_opt );
		}

		/**
		 * Get curated delay JS preset exclusions (jquery, recaptcha, stripe, analytics, etc.).
		 *
		 * Safe-by-default: WooCommerce, Elementor, and form plugins are always
		 * excluded so checkout and forms never break. Filterable via
		 * wppo_delay_js_exclusions. Preset prevents breakage on 10% sites.
		 *
		 * @since 2.0.0
		 * @return string[]
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::get_delay_js_preset_exclusions}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		private function get_delay_js_preset_exclusions(): array {
			return $this->script_strategy()->get_delay_js_preset_exclusions();
		}

		/**
		 * Whether Delay-JS must be skipped for the current request (fail-open safe context).
		 *
		 * Returns true (serve undeferred) on WooCommerce dynamic pages
		 * (cart/checkout/account/endpoints) or when a known form shortcode/block
		 * is present in the current post content. Any detection failure fails
		 * open to safe (no delay) so interactivity is never broken.
		 *
		 * @since 2.0.0
		 * @return bool True when Delay-JS must be skipped.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::is_delay_js_safe_context}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function is_delay_js_safe_context(): bool {
			return $this->script_strategy()->is_delay_js_safe_context();
		}

		/**
		 * Adds fetchpriority to rendered script tags for deferred handles.
		 *
		 * Pre-6.9 fallback only: on WP 6.9+ the native fetchpriority arg passed via
		 * wp_script_add_data() in add_defer_strategy() is rendered by core
		 * (this filter is not registered there via setup_hooks()). Honors the
		 * shared wppo_deferred_fetchpriority filter so a handle can stay
		 * 'high' or suppress via falsy; '' leaves the tag untouched.
		 * Case-insensitive single-occurrence injection handles `<SCRIPT>`,
		 * `<script\n`, and `<script>` variants via inject_delay_script_attr().
		 *
		 * @since 1.9.0
		 *
		 * @param  string $tag    The script tag HTML.
		 * @param  string $handle The script's registered handle.
		 * @return string Modified script tag with fetchpriority.
		 * Facade proxy (ARCH-005): logic lives in {@see Script_Strategy::add_fetchpriority_to_deferred}.
		 * @since 2.4.0 Proxied to Script_Strategy (ARCH-005).
		 */
		public function add_fetchpriority_to_deferred( $tag, $handle ): string {
			return $this->script_strategy()->add_fetchpriority_to_deferred( $tag, $handle );
		}

		/**
		 * Reset the per-request font preload dedup guard.
		 *
		 * Wired to `switch_blog` in {@see Main::setup_hooks()} alongside
		 * `Image_Optimisation::clear_runtime_caches()` so the dedup map
		 * cannot leak across sites in `switch_to_blog()` requests; also
		 * called directly in tests.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_font_preload_emitted(): void {
			self::$font_preload_emitted = array();
			self::$font_stamp_memo      = array();
			self::$auto_fonts_memo      = array();
		}

		/**
		 * Reset the per-instance LCP memos on the shared image-optimisation instance.
		 *
		 * Wired to `switch_blog` in {@see Main::setup_hooks()} (issue #1216): the
		 * Image_Optimisation instance is long-lived via Main, so its
		 * memoized LCP URLs would otherwise leak across sites in
		 * `switch_to_blog()` requests. Accepts the switch_blog args so the
		 * hook passes ($new_blog_id, $prev_blog_id) without warnings.
		 * Also called directly in tests.
		 *
		 * @since 2.2.0
		 * @param int $new_blog_id New blog ID (unused).
		 * @param int $prev_blog_id Previous blog ID (unused).
		 * @return void
		 */
		public static function reset_image_lcp_memos( $new_blog_id = 0, $prev_blog_id = 0 ): void {
			unset( $new_blog_id, $prev_blog_id );
			try {
				$main = self::get_instance();
				if ( $main instanceof self && isset( $main->image_optimisation ) && $main->image_optimisation instanceof \PerformanceOptimise\Inc\Image_Optimisation ) {
					$main->image_optimisation->clear_instance_lcp_memo();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Extract font file URLs from a CSS string.
		 *
		 * Pure helper (issue #1216): matches `url(...)` values whose path
		 * carries a woff2/woff/ttf extension, drops data:/blob:/javascript:
		 * schemes, trims to 2048 chars, dedups preserving document order
		 * with woff2 preferred within each `@font-face` block (never
		 * reordered across families so a secondary family's woff2 cannot
		 * outrank the primary family's woff under the cap-2 slice).
		 * Never fatals: any failure returns an empty list.
		 *
		 * @since 2.2.0
		 * @param string $css CSS text to scan.
		 * @return string[] Ordered unique font URLs.
		 */
		public static function extract_font_urls_from_css( string $css ): array {
			try {
				if ( '' === trim( $css ) ) {
					return array();
				}
				$css    = substr( $css, 0, 524288 );
				$blocks = array();
				if ( preg_match_all( '/@font-face\s*\{[^}]*\}/is', $css, $block_matches ) && ! empty( $block_matches[0] ) ) {
					$blocks = $block_matches[0];
				} else {
					$blocks = array( $css );
				}
				$found = array();
				foreach ( $blocks as $block ) {
					$block = substr( $block, 0, 524288 );
					if ( ! preg_match_all( '/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $block, $matches ) ) {
						continue;
					}
					$block_urls = array();
					foreach ( $matches[1] as $raw ) {
						$url = trim( (string) $raw );
						if ( '' === $url || strlen( $url ) > 2048 ) {
							continue;
						}
						$lower = strtolower( ltrim( $url ) );
						if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) ) {
							continue;
						}
						$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
						if ( ! is_string( $path ) || '' === $path || 1 !== preg_match( '/\.(woff2|woff|ttf)(\?.*)?$/i', $path ) ) {
							continue;
						}
						$block_urls[] = $url;
					}
					$block_urls = array_values( array_unique( $block_urls ) );
					usort(
						$block_urls,
						static function ( $a, $b ) {
							$rank = static function ( $u ) {
								$p = strtolower( (string) ( function_exists( 'wp_parse_url' ) ? wp_parse_url( $u, PHP_URL_PATH ) : parse_url( $u, PHP_URL_PATH ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
								if ( str_ends_with( $p, '.woff2' ) ) {
									return 0;
								}
								if ( str_ends_with( $p, '.woff' ) ) {
									return 1;
								}
								return 2;
							};
							return $rank( $a ) <=> $rank( $b );
						}
					);
					foreach ( $block_urls as $u ) {
						if ( ! in_array( $u, $found, true ) ) {
							$found[] = $u;
						}
					}
				}
				return $found;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Map a font URL to its preload `type` attribute.
		 *
		 * @since 2.2.0
		 * @param string $font_url Font URL.
		 * @return string MIME type (possibly empty).
		 */
		private function font_type_for_url( string $font_url ): string {
			try {
				$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $font_url, PHP_URL_PATH ) : parse_url( $font_url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
				$ext  = is_string( $path ) ? strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) : '';
				switch ( $ext ) {
					case 'woff2':
						return 'font/woff2';
					case 'woff':
						return 'font/woff';
					case 'ttf':
						return 'font/ttf';
					default:
						return '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether a font candidate URL is same-origin with this site.
		 *
		 * Fail-closed (issue #1216): absolute URLs validate via
		 * `RUM::is_same_origin_url()` when available, else a guarded
		 * home-host comparison; root-relative and bare relative paths are
		 * same-origin by construction. Any failure returns false.
		 *
		 * @since 2.2.0
		 * @param string $url Candidate URL.
		 * @return bool True when the URL may be preloaded.
		 */
		private function is_same_origin_font_url( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				$lower = strtolower( ltrim( $url ) );
				if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) ) {
					return false;
				}
				if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
					return true;
				}
				if ( false === strpos( $url, '://' ) && 0 !== strpos( $url, '//' ) ) {
					$before_slash = strtok( $url, '/\\?#' );
					if ( is_string( $before_slash ) && false !== strpos( $before_slash, ':' ) ) {
						return false;
					}
					return true;
				}
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url_strict' ) ) {
					return \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( $url );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url' ) ) {
					return \PerformanceOptimise\Inc\RUM::is_same_origin_url( $url );
				}
				if ( function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ) {
					$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
					$home_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
					return '' !== $host && $host === $home_host;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Normalize a font URL for manual-wins dedup.
		 *
		 * Builds on `Util::normalize_url()` (host + path, size-suffix aware)
		 * but re-attaches the truncated query string (issue #1216): font
		 * files versioned via `?v=1` vs `?v=2` are distinct resources and must
		 * not collapse to one tag. Long-lived processes (CLI/cron rendering N
		 * pages with one instance) must call {@see reset_font_preload_emitted()}
		 * between pages or the per-request emitted guard skips page-2 repeats.
		 *
		 * @since 2.2.0
		 * @param string $url Font URL.
		 * @return string Dedup key.
		 */
		private function normalize_font_url( string $url ): string {
			try {
				$query = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$q = wp_parse_url( $url, PHP_URL_QUERY );
					if ( is_string( $q ) && '' !== $q ) {
						$query = '?' . substr( $q, 0, 256 );
					}
				} else {
					$qpos = strpos( $url, '?' );
					if ( false !== $qpos ) {
						$query = '?' . substr( substr( $url, $qpos + 1 ), 0, 256 );
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_url' ) ) {
					$norm = Util::normalize_url( $url );
					if ( '' !== $norm ) {
						return $norm . $query;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return strtolower( trim( $url ) );
		}

		/**
		 * Read the manual font preload URL list (resolved to absolute URLs).
		 *
		 * Manual inputs resolve through `resolve_font_url()` with an empty
		 * stylesheet base (issue #1216) — the same resolver auto-discovery
		 * uses — so root-relative refs anchor at `home_url()` and bare
		 * relatives at the home URL, never at `WP_CONTENT_URL`. Shared
		 * resolution keeps the manual-wins normalized-URL dedup comparing
		 * like with like instead of missing across bases.
		 *
		 * @since 2.2.0
		 * @param array $preload_settings Preload settings tab.
		 * @return string[] Absolute manual font URLs.
		 */
		public function get_manual_font_urls( array $preload_settings ): array {
			try {
				if ( empty( $preload_settings['preloadFonts'] ) || empty( $preload_settings['preloadFontsUrls'] ) ) {
					return array();
				}
				$raw  = Util::process_urls( $preload_settings['preloadFontsUrls'] );
				$urls = array();
				foreach ( $raw as $font_url ) {
					$font_url = trim( (string) $font_url );
					if ( '' === $font_url ) {
						continue;
					}
					$resolved = $this->resolve_font_url( $font_url, '' );
					if ( '' === $resolved ) {
						continue;
					}
					$urls[] = substr( $resolved, 0, 2048 );
				}
				return array_values( array_unique( $urls ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Resolve a same-origin stylesheet URL to a contained local path.
		 *
		 * Single shared implementation (issue #1216) for
		 * `collect_enqueued_font_css_chunks()` and `font_stylesheet_stamp()`
		 * so a future hardening fix cannot land in one copy only: maps the
		 * URL path under ABSPATH, canonicalizes with realpath (rejecting
		 * `..` escapes), and refuses paths outside ABSPATH before any
		 * file_exists/filesize/file_get_contents probe. Returns '' when
		 * unresolvable or outside containment. Never fatals.
		 *
		 * @since 2.2.0
		 * @param string $abs_src Absolute same-origin stylesheet URL.
		 * @return string Canonical local path, or ''.
		 */
		private function resolve_local_stylesheet_path( string $abs_src ): string {
			try {
				$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $abs_src, PHP_URL_PATH ) : parse_url( $abs_src, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
				if ( ! is_string( $path ) || '' === $path ) {
					return '';
				}
				$local = ( defined( 'ABSPATH' ) ? (string) ABSPATH : '' ) . ltrim( $path, '/' );
				if ( function_exists( 'wp_normalize_path' ) ) {
					$local = wp_normalize_path( $local );
				}
				$real = realpath( $local );
				if ( ! is_string( $real ) ) {
					return '';
				}
				if ( function_exists( 'wp_normalize_path' ) ) {
					$real = wp_normalize_path( $real );
				}
				$base = ( function_exists( 'wp_normalize_path' ) && defined( 'ABSPATH' ) ) ? wp_normalize_path( (string) ABSPATH ) : (string) ( defined( 'ABSPATH' ) ? ABSPATH : '' );
				if ( '' === $base || 0 !== strpos( $real, rtrim( $base, '/' ) . '/' ) ) {
					return '';
				}
				return $real;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Collect CSS text chunks from enqueued stylesheets (bounded).
		 *
		 * Scans inline `before`/`after` CSS plus same-origin stylesheet file
		 * contents (512 KB per file, 10 handles max). Only queued (actually
		 * printed) handles are scanned so discovery matches the page output.
		 * Each chunk carries its stylesheet base URL so CSS-relative font
		 * refs resolve against the enclosing stylesheet. Fail-open: any
		 * failure returns the chunks collected so far.
		 *
		 * @since 2.2.0
		 * @return array[] Chunks shaped as array{css: string, base: string}.
		 */
		private function collect_enqueued_font_css_chunks(): array {
			$collected = $this->collect_font_css_chunks_and_hashes();
			return $collected['chunks'];
		}

		/**
		 * Collect CSS chunks plus inline-CSS key hashes in one pass.
		 *
		 * Same scan as `collect_enqueued_font_css_chunks()` but additionally
		 * returns md5 hashes of the scanned inline `before`/`after` CSS so
		 * `get_auto_discovered_font_urls()` builds the transient key without
		 * looping the queue twice per request (issue #1216). The key loop
		 * and the chunk loop previously duplicated stripos/implode/md5 work
		 * on the hot path, including on cache hits.
		 *
		 * @since 2.2.0
		 * @return array Shaped as array{chunks: array[], inline_hashes: string[]}.
		 */
		private function collect_font_css_chunks_and_hashes(): array {
			$chunks        = array();
			$inline_hashes = array();
			try {
				if ( ! isset( $GLOBALS['wp_styles'] ) || ! is_object( $GLOBALS['wp_styles'] ) ) {
					return array(
						'chunks'        => $chunks,
						'inline_hashes' => $inline_hashes,
					);
				}
				$registered = $GLOBALS['wp_styles']->registered ?? null;
				if ( ! is_array( $registered ) ) {
					if ( is_object( $registered ) && method_exists( $registered, 'getArrayCopy' ) ) {
						$registered = $registered->getArrayCopy();
					} else {
						return array(
							'chunks'        => $chunks,
							'inline_hashes' => $inline_hashes,
						);
					}
				}
				$queue = ( isset( $GLOBALS['wp_styles']->queue ) && is_array( $GLOBALS['wp_styles']->queue ) ) ? $GLOBALS['wp_styles']->queue : array();
				if ( empty( $queue ) ) {
					return array(
						'chunks'        => $chunks,
						'inline_hashes' => $inline_hashes,
					);
				}
				// Total-bytes budget (issue #1216): a cold miss may probe up to
				// 10 handles x 512KB; stop reading files past ~1MB total so a
				// font-less theme cannot block TTFB on useless disk I/O.
				$total_bytes = 0;
				$scanned     = 0;
				foreach ( array_slice( $queue, 0, 10 ) as $handle ) {
					if ( $scanned >= 10 ) {
						break;
					}
					$style = $registered[ $handle ] ?? null;
					if ( ! is_object( $style ) ) {
						continue;
					}
					$extra = $style->extra ?? array();
					if ( is_array( $extra ) ) {
						foreach ( array( 'after', 'before' ) as $key ) {
							if ( empty( $extra[ $key ] ) ) {
								continue;
							}
							$inline = is_array( $extra[ $key ] ) ? implode( "\n", $extra[ $key ] ) : (string) $extra[ $key ];
							if ( '' !== trim( $inline ) && false !== stripos( $inline, 'font-face' ) ) {
								$chunks[]        = array(
									'css'  => substr( $inline, 0, 524288 ),
									'base' => '',
								);
								$inline_hashes[] = md5( substr( $inline, 0, 524288 ) );
								++$scanned;
								if ( $scanned >= 10 ) {
									break 2;
								}
							}
						}
					}
					$src = is_string( $style->src ?? null ) ? (string) $style->src : '';
					if ( '' !== $src ) {
						$src_path = strtok( $src, '?' );
						if ( ! is_string( $src_path ) || 1 !== preg_match( '/\.css$/i', $src_path ) ) {
							continue;
						}
					}
					if ( '' === $src ) {
						continue;
					}
					$abs_src = preg_match( '/^(?:https?:)?\/\//i', $src ) ? $src : Util::cached_content_url( $src );
					if ( ! $this->is_same_origin_font_url( $abs_src ) ) {
						continue;
					}
					$local = $this->resolve_local_stylesheet_path( $abs_src );
					if ( '' === $local ) {
						continue;
					}
					$size = 0;
					if ( file_exists( $local ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists -- Local read-only size probe; WP_Filesystem init per asset is disproportionate.
						$size = filesize( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Same local probe as above.
					}
					if ( ! is_int( $size ) && ! is_float( $size ) ) {
						continue;
					}
					if ( (int) $size <= 0 || (int) $size > 524288 ) {
						continue;
					}
					$content = false;
					if ( is_object( $this->filesystem ) && method_exists( $this->filesystem, 'get_contents' ) ) {
						$content = $this->filesystem->get_contents( $local );
					}
					if ( ! is_string( $content ) ) {
						$content = file_get_contents( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local stylesheet read with size guard; WP_Filesystem tried first.
					}
					if ( is_string( $content ) && '' !== trim( $content ) && false !== stripos( $content, 'font-face' ) ) {
						$total_bytes += strlen( $content );
						$chunks[]     = array(
							'css'  => substr( $content, 0, 524288 ),
							'base' => $abs_src,
						);
						++$scanned;
						if ( $total_bytes > 1048576 ) {
							break;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return array(
				'chunks'        => $chunks,
				'inline_hashes' => $inline_hashes,
			);
		}

		/**
		 * Resolve a font URL found in CSS against its stylesheet base.
		 *
		 * Absolute URLs pass through unchanged. Root-relative refs
		 * (`/fonts/x.woff2`) resolve against `home_url()` and
		 * stylesheet-relative refs (`../fonts/x.woff2`, `fonts/x.woff2`)
		 * resolve against the enclosing stylesheet directory (issue #1216);
		 * inline `<style>` chunks (empty base) resolve root-relative refs
		 * against `home_url()` and bare relatives against the home URL so
		 * no `wp-content`-based guess can emit a 404 preload. Protocol-
		 * relative URLs (`//host/...`) pass through for the same-origin
		 * guard to judge. Never fatals: any failure returns the trimmed
		 * input unchanged.
		 *
		 * @since 2.2.0
		 * @param string $font_url Font URL as written in CSS.
		 * @param string $base_src Absolute stylesheet URL (or empty for inline CSS).
		 * @return string Resolved absolute-or-relative URL.
		 */
		private function resolve_font_url( string $font_url, string $base_src = '' ): string {
			try {
				$font_url = trim( $font_url );
				if ( '' === $font_url ) {
					return '';
				}
				if ( preg_match( '/^https?:\/\//i', $font_url ) || 0 === strpos( $font_url, '//' ) ) {
					return substr( $font_url, 0, 2048 );
				}
				$is_root_relative = 0 === strpos( $font_url, '/' );
				if ( $is_root_relative && function_exists( 'home_url' ) ) {
					$home = (string) home_url();
					return substr( $this->normalize_font_href( rtrim( $home, '/' ) . $font_url ), 0, 2048 );
				}
				if ( '' !== $base_src ) {
					$base_path = strtok( $base_src, '?#' );
					if ( ! is_string( $base_path ) || '' === $base_path ) {
						$base_path = $base_src;
					}
					$dir = rtrim( dirname( $base_path ), '/' ) . '/';
					return substr( $this->normalize_font_href( $dir . ltrim( $font_url, '/' ) ), 0, 2048 );
				}
				if ( function_exists( 'home_url' ) ) {
					$home = (string) home_url();
					return substr( $this->normalize_font_href( rtrim( $home, '/' ) . '/' . ltrim( $font_url, '/' ) ), 0, 2048 );
				}
				return substr( $this->normalize_font_href( $font_url ), 0, 2048 );
			} catch ( \Throwable $e ) {
				unset( $e );
				return substr( trim( $font_url ), 0, 2048 );
			}
		}

		/**
		 * Canonicalize a font href by resolving dot-segments.
		 *
		 * Resolves `/./` and `/../` against the directory path so
		 * stylesheet-relative refs (`../fonts/x.woff2`) emit canonical
		 * preload hrefs for dedup, caching, and audit tooling (issue
		 * #1216). Query strings and fragments are preserved. Browsers
		 * resolve uncanonical hrefs identically, so this is purely a
		 * canonicalization step. Never fatals: any failure returns the
		 * input unchanged.
		 *
		 * @since 2.2.0
		 * @param string $href Absolute or protocol-relative href.
		 * @return string Canonicalized href.
		 */
		private function normalize_font_href( string $href ): string {
			try {
				$fragment = '';
				$hash_pos = strpos( $href, '#' );
				if ( false !== $hash_pos ) {
					$fragment = substr( $href, $hash_pos );
					$href     = substr( $href, 0, $hash_pos );
				}
				$query = '';
				$q_pos = strpos( $href, '?' );
				if ( false !== $q_pos ) {
					$query = substr( $href, $q_pos );
					$href  = substr( $href, 0, $q_pos );
				}
				if ( preg_match( '#^(https?://[^/]+)(/.*)$#i', $href, $m ) ) {
					return $m[1] . $this->normalize_font_path( $m[2] ) . $query . $fragment;
				}
				if ( 0 === strpos( $href, '//' ) && preg_match( '#^(//[^/]+)(/.*)$#', $href, $m ) ) {
					return $m[1] . $this->normalize_font_path( $m[2] ) . $query . $fragment;
				}
				if ( 0 === strpos( $href, '/' ) ) {
					return $this->normalize_font_path( $href ) . $query . $fragment;
				}
				return $href . $query . $fragment;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $href;
			}
		}

		/**
		 * Resolve dot-segments in a URL path.
		 *
		 * @since 2.2.0
		 * @param string $path URL path starting with `/`.
		 * @return string Normalized path.
		 */
		private function normalize_font_path( string $path ): string {
			$is_absolute = 0 === strpos( $path, '/' );
			$parts       = explode( '/', $path );
			$stack       = array();
			foreach ( $parts as $part ) {
				if ( '' === $part || '.' === $part ) {
					continue;
				}
				if ( '..' === $part ) {
					if ( ! empty( $stack ) ) {
						array_pop( $stack );
					}
					continue;
				}
				$stack[] = $part;
			}
			$normalized = implode( '/', $stack );
			if ( $is_absolute ) {
				$normalized = '/' . $normalized;
			}
			if ( '' !== $normalized && '/' !== $normalized && str_ends_with( $path, '/' ) && ! str_ends_with( $normalized, '/' ) ) {
				$normalized .= '/';
			}
			if ( '' === $normalized && $is_absolute ) {
				$normalized = '/';
			}
			return $normalized;
		}

		/**
		 * Best-effort file stamp (mtime:size) for a stylesheet src.
		 *
		 * Used only for the auto-font transient key so same-ver CSS edits bust
		 * the 12h cache (issue #1216). Shares
		 * {@see resolve_local_stylesheet_path()} containment with the chunk
		 * collector; unresolvable or non-local files yield '' (key falls back
		 * to src|ver). Memoized per request so re-entrant `wp_head`
		 * emissions do not repeat stat syscalls. Never fatals.
		 *
		 * @since 2.2.0
		 * @param string $src Stylesheet src as registered.
		 * @return string Stamp shaped as "mtime:size" or ''.
		 */
		private function font_stylesheet_stamp( string $src ): string {
			try {
				$src = trim( $src );
				if ( '' === $src ) {
					return '';
				}
				if ( isset( self::$font_stamp_memo[ $src ] ) ) {
					return self::$font_stamp_memo[ $src ];
				}
				$stamp   = '';
				$abs_src = preg_match( '/^(?:https?:)?\/\//i', $src ) ? $src : ( class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::cached_content_url( $src ) : $src );
				if ( $this->is_same_origin_font_url( $abs_src ) ) {
					$local = $this->resolve_local_stylesheet_path( $abs_src );
					if ( '' !== $local ) {
						$mtime = filemtime( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filemtime -- Local read-only key stamp; WP_Filesystem init per asset is disproportionate.
						$size  = filesize( $local ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize -- Same local stamp as above.
						if ( false !== $mtime || false !== $size ) {
							$stamp = ( false === $mtime ? '0' : (string) (int) $mtime ) . ':' . ( false === $size ? '0' : (string) (int) $size );
						}
					}
				}
				self::$font_stamp_memo[ $src ] = $stamp;
				return $stamp;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Resolve auto-discovered font preload URLs (capped at 2, manual wins).
		 *
		 * Gated on `preload_settings.autoDiscoverFonts` (off by default).
		 * Candidates come from enqueued stylesheet `@font-face` URLs,
		 * filtered same-origin, minus manual-list overlaps (normalized), then
		 * capped at MAX_AUTO_FONT_PRELOADS. Results are cached in a
		 * blog-aware transient (`Util::transient_key()`, multisite-safe, 12h)
		 * keyed by stylesheet state, plus a per-request in-memory memo so
		 * re-entrant `wp_head` emissions skip the transient round-trip.
		 * Fail-open: any failure returns [].
		 *
		 * Cold-miss cost is bounded (issue #1216): at most 10 queued handles,
		 * 512 KB per file, ~1 MB total before `wp_head` output, with a stat
		 * size probe before every full read. File stamps are memoized per
		 * request and the chunk scan runs once per call (chunks + inline key
		 * hashes collected in a single pass).
		 *
		 * @since 2.2.0
		 * @param string[] $manual_urls Manual font URLs (win on conflict).
		 * @return string[] Auto font URLs (zero to two items).
		 */
		public function get_auto_discovered_font_urls( array $manual_urls = array() ): array {
			try {
				$preload_settings = $this->get_options()['preload_settings'] ?? array();
				if ( empty( $preload_settings['autoDiscoverFonts'] ) ) {
					return array();
				}
				$manual_keys = array();
				foreach ( $manual_urls as $m ) {
					if ( is_string( $m ) && '' !== $m ) {
						$manual_keys[ $this->normalize_font_url( $m ) ] = true;
					}
				}
				// Single scan pass (issue #1216): chunks for extraction and
				// inline hashes for the cache key come from one queue walk,
				// never two.
				$collected     = $this->collect_font_css_chunks_and_hashes();
				$inline_hashes = $collected['inline_hashes'];
				$cache_key     = '';
				try {
					$srcs = array();
					// Home host + scheme bind the key (issue #1216): a
					// poisoned/stale transient (object-cache write, domain
					// migration) must not serve another origin's font list.
					$home_id = function_exists( 'home_url' ) ? strtolower( (string) home_url() ) : '';
					if ( isset( $GLOBALS['wp_styles'] ) && is_object( $GLOBALS['wp_styles'] ) && isset( $GLOBALS['wp_styles']->queue ) && is_array( $GLOBALS['wp_styles']->queue ) ) {
						$handles    = array_slice( $GLOBALS['wp_styles']->queue, 0, 10 );
						$registered = $GLOBALS['wp_styles']->registered ?? array();
						if ( ! is_array( $registered ) ) {
							$registered = array();
						}
						foreach ( $handles as $h ) {
							$s   = $registered[ $h ] ?? null;
							$src = is_object( $s ) ? (string) ( $s->src ?? '' ) : '';
							$ver = is_object( $s ) ? (string) ( $s->ver ?? '' ) : '';
							// Same-ver CSS edits (direct edit, minify/combine
							// rewrite, child-theme override) must bust the 12h
							// cache (issue #1216): fold filemtime + filesize
							// into the key best-effort (memoized per request).
							// Unresolvable files contribute src|ver only
							// (never fatal).
							$stamp  = $this->font_stylesheet_stamp( $src );
							$srcs[] = (string) $h . '|' . $src . '|' . $ver . '|' . $stamp;
						}
					}
					// Sorted, manual-free key (issue #1216): handle registration
					// order must not mint orphan keys, and manual-list edits
					// must not churn the cache — the manual overlap is already
					// re-filtered on every read (transient hit and miss), so
					// including manual keys only adds invalidation churn.
					// Inline before/after CSS is also scanned, so its hashes
					// join the key: editing Customizer additional CSS or inline
					// @font-face rules busts the 12h cache.
					sort( $srcs );
					$cache_key = Util::transient_key( 'wppo_auto_fonts_' . md5( $home_id . '|' . wp_json_encode( $srcs ) . '|' . wp_json_encode( $inline_hashes ) ) );
				} catch ( \Throwable $e ) {
					unset( $e );
					$cache_key = '';
				}
				if ( '' !== $cache_key && isset( self::$auto_fonts_memo[ $cache_key ] ) ) {
					return $this->filter_auto_font_urls( self::$auto_fonts_memo[ $cache_key ], $manual_keys );
				}
				if ( '' !== $cache_key && function_exists( 'get_transient' ) ) {
					try {
						$cached = get_transient( $cache_key );
						if ( is_array( $cached ) ) {
							self::$auto_fonts_memo[ $cache_key ] = $cached;
							return $this->filter_auto_font_urls( $cached, $manual_keys );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$candidates = array();
				foreach ( $collected['chunks'] as $chunk ) {
					$css  = is_array( $chunk ) ? (string) ( $chunk['css'] ?? '' ) : (string) $chunk;
					$base = is_array( $chunk ) ? (string) ( $chunk['base'] ?? '' ) : '';
					foreach ( self::extract_font_urls_from_css( $css ) as $font_url ) {
						$abs = $this->resolve_font_url( $font_url, $base );
						if ( ! $this->is_same_origin_font_url( $abs ) ) {
							continue;
						}
						$key = $this->normalize_font_url( $abs );
						if ( isset( $manual_keys[ $key ] ) || isset( $candidates[ $key ] ) ) {
							continue;
						}
						$candidates[ $key ] = substr( $abs, 0, 2048 );
						if ( count( $candidates ) >= self::MAX_AUTO_FONT_PRELOADS ) {
							break 2;
						}
					}
				}
				$result = array_values( $candidates );
				if ( '' !== $cache_key ) {
					self::$auto_fonts_memo[ $cache_key ] = $result;
					if ( function_exists( 'set_transient' ) ) {
						try {
							set_transient( $cache_key, $result, defined( 'HOUR_IN_SECONDS' ) ? 12 * HOUR_IN_SECONDS : 43200 );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
				return $result;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Filter candidate font URLs to the emission-safe subset (issue #1216).
		 *
		 * Shared by the transient-hit and per-request-memo paths so cached
		 * values are re-validated on every read: a poisoned/stale transient
		 * must not emit cross-origin fonts or shadow the manual list. Trims
		 * to 2048 chars, enforces same-origin, drops manual-list overlaps
		 * (normalized), dedups, and caps at MAX_AUTO_FONT_PRELOADS.
		 * Never fatals: any failure returns [].
		 *
		 * @since 2.2.0
		 * @param mixed[] $urls Candidate URLs (e.g. from the transient).
		 * @param array   $manual_keys Normalized manual-URL keys winning on conflict.
		 * @return string[] Clean auto font URLs (zero to two items).
		 */
		private function filter_auto_font_urls( array $urls, array $manual_keys ): array {
			try {
				$clean = array();
				foreach ( $urls as $cu ) {
					$cu = is_string( $cu ) ? substr( trim( $cu ), 0, 2048 ) : '';
					if ( '' === $cu || ! $this->is_same_origin_font_url( $cu ) ) {
						continue;
					}
					$ck = $this->normalize_font_url( $cu );
					if ( '' === $ck || isset( $manual_keys[ $ck ] ) || isset( $clean[ $ck ] ) ) {
						continue;
					}
					$clean[ $ck ] = $cu;
					if ( count( $clean ) >= self::MAX_AUTO_FONT_PRELOADS ) {
						break;
					}
				}
				return array_values( $clean );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Adds preload, prefetch, and preconnect links to optimize resource loading.
		 *
		 * Image preloads delegate to `Image_Optimisation::preload_images()`,
		 * which emits exactly one `<link rel="preload" as="image"
		 * fetchpriority="high">` per URL for the single RUM-field →
		 * PageSpeed LCP candidate (issue #991; Optimization Detective stays
		 * Priority 0), deduped by normalized URL + query + media with a
		 * per-request emitted guard, and excludes that candidate from lazy
		 * load (gated on the LCP toggles, with normalized size-variant
		 * matching). Core 6.9 `fetchpriority` stamping is never
		 * double-applied (the stamp path only fills gaps via
		 * `function_exists()`-guarded core calls). Manual preload-image meta
		 * and the hero fallback remain when no RUM or PageSpeed candidate
		 * resolves (fail-open).
		 *
		 * Runs on `wp_head` priority 1, before core resource-hints at
		 * priority 2.
		 *
		 * @since 1.0.0
		 */
		public function add_preload_prefetch_preconnect() {
			// Non-frontend contexts never need preload hints (issue #1216):
			// skip the font/settings lookups entirely. Util::generate_preload_link()
			// already suppresses echo in admin/ajax/cron, but the queries
			// (get_manual_font_urls, post meta, front-page options) would
			// still run without this early bail.
			try {
				if ( function_exists( 'is_admin' ) && is_admin() ) {
					return;
				}
				if ( function_exists( 'is_feed' ) && is_feed() ) {
					return;
				}
				if ( function_exists( 'is_embed' ) && is_embed() ) {
					return;
				}
				if ( function_exists( 'is_preview' ) && is_preview() ) {
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$preload_settings = $this->get_options()['preload_settings'] ?? array();

			// Preload fonts (manual lists win; auto-discovery fills the gap).
			$manual_font_urls = $this->get_manual_font_urls( is_array( $preload_settings ) ? $preload_settings : array() );
			foreach ( $manual_font_urls as $font_url ) {
				$dedup_key = $this->normalize_font_url( (string) $font_url );
				if ( '' !== $dedup_key && isset( self::$font_preload_emitted[ $dedup_key ] ) ) {
					continue;
				}
				if ( '' !== $dedup_key ) {
					self::$font_preload_emitted[ $dedup_key ] = true;
				}
				Util::generate_preload_link( $font_url, 'preload', 'font', true, $this->font_type_for_url( (string) $font_url ) );
			}

			// Auto-discovered fonts: capped at 2 with crossorigin, manual wins.
			if ( ! empty( $preload_settings['autoDiscoverFonts'] ) ) {
				try {
					$auto_fonts = $this->get_auto_discovered_font_urls( $manual_font_urls );
				} catch ( \Throwable $e ) {
					unset( $e );
					$auto_fonts = array();
				}
				$count = 0;
				foreach ( $auto_fonts as $font_url ) {
					if ( $count >= self::MAX_AUTO_FONT_PRELOADS ) {
						break;
					}
					if ( ! is_string( $font_url ) || '' === trim( $font_url ) ) {
						continue;
					}
					$dedup_key = $this->normalize_font_url( $font_url );
					if ( '' === $dedup_key || isset( self::$font_preload_emitted[ $dedup_key ] ) ) {
						continue;
					}
					self::$font_preload_emitted[ $dedup_key ] = true;
					Util::generate_preload_link( $font_url, 'preload', 'font', true, $this->font_type_for_url( $font_url ) );
					++$count;
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
			$preload_settings = $this->get_options()['preload_settings'] ?? array();

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
		 * When `wp_get_speculation_rules()` exists (WP 6.8+) the plugin does not emit its own
		 * `<script type="speculationrules">` block. Instead it drives the single core rule set
		 * via the `wp_speculation_rules_configuration` filter so only one document-level rule
		 * is printed (avoids duplicate prefetch/prerender waste when multiple rule sets would append).
		 *
		 * Excludes sensitive/dynamic paths (login, admin, REST API) from all
		 * speculation and pins an explicit configuration whenever the plugin owns
		 * the speculation-rules decision: the user's chosen mode/eagerness when
		 * the UI toggle is on, or the legacy `conservative` default when it is
		 * off (so core's WP 7.1 cached-site escalation cannot change behavior
		 * behind the user's back).
		 *
		 * Effective defaults are `prefetch` + `conservative` unless overridden
		 * via `WP_SPECULATIVE_LOADING_DEFAULT_MODE` / `_EAGERNESS` (WP 7.1,
		 * `wp_get_speculation_rules_default_configuration()`). The
		 * `wp_speculation_rules_configuration` filter (used here) takes precedence
		 * over host constants (see filter_speculation_rules_configuration()).
		 * No auto-elevation to `moderate` is assumed — it must be chosen
		 * explicitly in the UI.
		 *
		 * Host overrides are honored via `WP_SPECULATIVE_LOADING_DEFAULT_*` constants
		 * or environment variables (WP 7.1 #65624); the filter wins over the host.
		 * Mode/eagerness are validated via `WP_Speculation_Rules::is_valid_mode()`
		 * / `is_valid_eagerness()` when the class exists (WP 6.8+), otherwise via
		 * an allowlist fallback. Excludes are merged via `wp_speculation_rules_href_exclude_paths`
		 * (user `speculationExcludeUrls` + WooCommerce cart/checkout/account).
		 *
		 * Backward compatible: on WP <6.8 neither `wp_get_speculation_rules()`
		 * nor `wp_get_speculation_rules_configuration()` exists,
		 * so this method is a no-op and no filter is registered (legacy path).
		 * Fail-open: pre-6.8 output degrades to unoptimised (no speculation
		 * block is printed by this plugin on 6.2-6.7); invalid URLs are
		 * skipped individually and logged-in visitors are always excluded.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public function add_speculation_rules() {
			// WP 6.8+ provides the Speculation Rules API. Gate on the 6.8
			// emitter (`wp_get_speculation_rules`, canonical presence check
			// for the <script type="speculationrules"> block) or the 6.8
			// configuration helper (`wp_get_speculation_rules_configuration`,
			// same introduction) so a backport or partial polyfill exposing
			// only one entry point still defers to core's single block.
			// Keep backward compat for WP <6.8 (no-op, fail-open to unoptimised).
			if ( ! function_exists( 'wp_get_speculation_rules' ) && ! function_exists( 'wp_get_speculation_rules_configuration' ) ) {
				return;
			}

			// Belt-and-braces version guard so WP 6.2-6.7 never registers core
			// filters even if a backported helper exists. Fail-open: read-only,
			// never fatal. An unknown version assumes the newest core (the
			// historic '6.8' fallback, now the Wp_Version default) so managed
			// installs keep speculating. Canonicalization note (REF-010): an
			// explicit '' $wp_version global falls through to get_bloginfo()
			// (majority spelling); the pre-REF-010 bare-isset() cast compared
			// '' as-is and failed this gate. Core never emits ''.
			if ( ! Wp_Version::is_at_least( '6.8', true ) ) {
				return;
			}

			$preload_settings   = $this->get_options()['preload_settings'] ?? array();
			$enable_speculation = ! empty( $preload_settings['enableSpeculationRules'] );

			add_filter(
				'wp_speculation_rules_href_exclude_paths',
				function ( $exclude_paths ) use ( $preload_settings ) {
					if ( ! is_array( $exclude_paths ) ) {
						$exclude_paths = array();
					}
					foreach ( $this->get_speculation_exclude_paths( $preload_settings ) as $exclude ) {
						if ( ! in_array( $exclude, $exclude_paths, true ) ) {
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

			add_filter( 'wp_speculation_rules', array( $this, 'filter_speculation_list_rules' ), 10 );

			// Guarded high-value prerender list (issue #1237): merges into
			// the single core `speculationrules` block on WP 6.8+ via the
			// `wp_load_speculation_rules` action (WP_Speculation_Rules
			// object path in wppo_register_speculation_rules()). Guards and
			// fail-open behavior live in the helper; registering here keeps
			// the single 6.8-guarded registration point above.
			add_action( 'wp_load_speculation_rules', array( $this, 'wppo_register_speculation_rules' ) );
		}

		/**
		 * Canonical speculation-rules href exclusion patterns.
		 *
		 * Merges core safety defaults (auth, admin, REST), generic commerce
		 * paths (cart/checkout/account), WooCommerce dynamic cart/checkout/
		 * account paths, and user-configured `speculationExcludeUrls`.
		 * Fill-gaps-only: callers dedupe against pre-existing core patterns
		 * so the core ruleset is never duplicated.
		 *
		 * Intentionally narrow: nonce/add-to-cart are query-param
		 * actions (`?_wpnonce=`, `?add-to-cart=`) already
		 * excluded by core's `?`-URL handling and by
		 * {@see is_speculation_list_url_valid()}, so no `*substring*`
		 * wildcard is emitted — such wildcards would also block legitimate
		 * slugs (e.g. a post about "add to cart"). The `/logout/*` path
		 * prefix guards document-rule `href_matches` for pretty logout
		 * slugs; `?action=logout` list URLs stay covered by the `?`-URL
		 * rejection in {@see is_speculation_list_url_valid()}.
		 *
		 * @since 2.0.0
		 *
		 * @param array $preload_settings The plugin's preload_settings option value.
		 * @return string[] Exclusion patterns (possibly empty, never fatal).
		 */
		public function get_speculation_exclude_paths( array $preload_settings = array() ): array {
			try {
				$excludes = array(
					'/wp-login*',
					'/wp-admin/*',
					'/wp-json/*',
					'/logout/*',
					'/cart/*',
					'/checkout/*',
					'/my-account/*',
					'/account/*',
				);

				// Canonical Woo exclusion list (issue #1383): inherit
				// Util::get_woo_excluded_paths() so custom/nested/translated
				// slugs resolved via wc_get_page_id() (e.g. shop/basket) stay
				// out of speculation rules. Fail-open: resolution failure keeps
				// the hardcoded seed above (never fatal, 0 queries when Woo is
				// absent). Multisite-safe: per-site page resolution only.
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_woo_excluded_paths' ) ) {
					try {
						foreach ( Util::get_woo_excluded_paths() as $woo_path ) {
							$candidate = strtolower( trim( (string) $woo_path, '/' ) );
							if ( '' === $candidate ) {
								continue;
							}
							$pattern = '/' . $candidate . '/*';
							if ( ! in_array( $pattern, $excludes, true ) ) {
								$excludes[] = $pattern;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				$custom_excludes = ! empty( $preload_settings['speculationExcludeUrls'] )
					? Util::process_urls( $preload_settings['speculationExcludeUrls'] )
					: array();
				foreach ( $custom_excludes as $exclude ) {
					// Convert bare paths to wildcard pattern via WP_URL_Pattern_Prefixer when available (WP 6.8+).
					if ( class_exists( 'WP_URL_Pattern_Prefixer' ) && method_exists( 'WP_URL_Pattern_Prefixer', 'prefix_path_pattern' ) ) {
						// Core helper adds /* for path prefix patterns; guard already wildcarded.
						if ( false === strpos( $exclude, '*' ) && isset( $exclude[0] ) && '/' === $exclude[0] ) {
							$exclude = \WP_URL_Pattern_Prefixer::prefix_path_pattern( $exclude, '/' );
						}
					} elseif ( isset( $exclude[0] ) && '/' === $exclude[0] && false === strpos( $exclude, '*' ) ) {
						// Fallback: ensure wildcard for path prefix.
						$exclude = rtrim( $exclude, '/' ) . '/*';
					}
					if ( ! in_array( $exclude, $excludes, true ) ) {
						$excludes[] = $exclude;
					}
				}

				// Keep in sync with AI_Adaptive::get_commerce_exclude_paths() —
				// both derive the same WooCommerce cart/checkout/account paths.
				if ( function_exists( 'wc_get_checkout_url' ) ) {
					try {
						$checkout_url = wc_get_checkout_url();
						if ( $checkout_url ) {
							$path = wp_parse_url( $checkout_url, PHP_URL_PATH );
							if ( $path && '/' !== $path ) {
								$pattern = trailingslashit( $path ) . '*';
								if ( ! in_array( $pattern, $excludes, true ) ) {
									$excludes[] = $pattern;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'wc_get_cart_url' ) ) {
					try {
						$cart_url = wc_get_cart_url();
						if ( $cart_url ) {
							$path = wp_parse_url( $cart_url, PHP_URL_PATH );
							if ( $path && '/' !== $path ) {
								$pattern = trailingslashit( $path ) . '*';
								if ( ! in_array( $pattern, $excludes, true ) ) {
									$excludes[] = $pattern;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'wc_get_page_permalink' ) ) {
					try {
						$myaccount_url = wc_get_page_permalink( 'myaccount' );
						if ( $myaccount_url ) {
							$path = wp_parse_url( $myaccount_url, PHP_URL_PATH );
							if ( $path && '/' !== $path ) {
								$pattern = trailingslashit( $path ) . '*';
								if ( ! in_array( $pattern, $excludes, true ) ) {
									$excludes[] = $pattern;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters the speculation-rules href exclusion patterns.
					 *
					 * @since 2.0.0
					 * @param string[] $excludes         Canonical exclusion patterns.
					 * @param array    $preload_settings The plugin's preload_settings option value.
					 */
					$filtered = apply_filters( 'wppo_speculation_exclusions', $excludes, $preload_settings );
					if ( is_array( $filtered ) ) {
						$excludes = array_values( array_unique( array_filter( $filtered, 'is_string' ) ) );
					}
				}

				return $excludes;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether prerender speculation mode is allowed for the current request.
		 *
		 * Prerender executes page JavaScript speculatively, so it requires
		 * both guardrails: the plugin's static cache must be active (never
		 * prerender uncached origin responses) and, when RUM gating is
		 * enabled, real-user field data must qualify via
		 * `AI_Adaptive::get_rum_gated_speculation_state()` (good p75).
		 * Gating explicitly disabled honors the user's prerender choice.
		 * Fail-safe: any failure or missing RUM signal means "not allowed",
		 * degrading to conservative prefetch (unoptimised, never fatal).
		 * Multisite-safe: per-site options and per-site RUM aggregates only.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when prerender may be emitted.
		 */
		private function is_prerender_allowed(): bool {
			try {
				if ( empty( $this->get_options()['cache_settings']['enableCache'] ) ) {
					return false;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) ) {
					return false;
				}
				if ( ! method_exists( 'PerformanceOptimise\Inc\AI_Adaptive', 'is_speculation_rum_gating_enabled' )
					|| ! method_exists( 'PerformanceOptimise\Inc\AI_Adaptive', 'get_rum_gated_speculation_state' ) ) {
					return false;
				}
				$gating_enabled = AI_Adaptive::is_speculation_rum_gating_enabled();
				if ( ! $gating_enabled ) {
					return true;
				}
				$state = AI_Adaptive::get_rum_gated_speculation_state( $gating_enabled );
				return ! empty( $state['qualified'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
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
		 * can still pin a different default. The filter wins over the host
		 * override (documented precedence).
		 *
		 * Mode/eagerness are validated via `WP_Speculation_Rules::is_valid_mode()`
		 * / `is_valid_eagerness()` when the class exists (WP 6.8+), otherwise via
		 * an allowlist fallback, with `function_exists`/`class_exists` guards for
		 * backward compat on WP <6.8.
		 *
		 * Excludes are merged via `wp_speculation_rules_href_exclude_paths`
		 * (user `speculationExcludeUrls` + WooCommerce cart/checkout/account via
		 * {@see add_speculation_rules()}).
		 *
		 * Cache awareness: non-cacheable responses (`DONOTCACHEPAGE`,
		 * cart/checkout/account, previews, logged-in visitors) return null so
		 * neither core nor plugin rules prefetch them.
		 *
		 * @since 1.9.0
		 * @since 2.0.0 Honor `wp_get_speculation_rules_default_configuration()` when available (WP 7.1).
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

			// Cache awareness: never speculate non-cacheable responses
			// (DONOTCACHEPAGE / cart/checkout/account / previews). Returning
			// null disables speculation for the request (core convention),
			// so neither core nor plugin rules prefetch the cart.
			if ( $this->is_speculation_suppressed_for_visitor() ) {
				return null;
			}

			if ( $enable_speculation ) {
				$mode      = $preload_settings['speculationMode'] ?? 'prefetch';
				$eagerness = $preload_settings['speculationEagerness'] ?? 'conservative';
				// Validate via WP_Speculation_Rules when available (WP 6.8+), fallback to allowlist.
				// Guards: class_exists + method_exists for backward compat on WP <6.8.
				if ( class_exists( 'WP_Speculation_Rules' ) ) {
					if ( method_exists( 'WP_Speculation_Rules', 'is_valid_mode' ) ) {
						if ( ! \WP_Speculation_Rules::is_valid_mode( $mode ) ) {
							$mode = 'prefetch';
						}
					} elseif ( ! in_array( $mode, array( 'prefetch', 'prerender' ), true ) ) {
						$mode = 'prefetch';
					}
					if ( method_exists( 'WP_Speculation_Rules', 'is_valid_eagerness' ) ) {
						if ( ! \WP_Speculation_Rules::is_valid_eagerness( $eagerness ) ) {
							$eagerness = 'conservative';
						}
					} elseif ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
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
				// Prerender guardrail: prerender executes page JavaScript
				// speculatively and can inflate analytics and origin load on
				// uncached routes, so it stays opt-in behind RUM gating (good
				// real-user p75 qualifies) and the plugin's static cache. An
				// unqualified prerender request degrades to conservative
				// prefetch (unoptimised, never fatal); prefetch modes pass
				// through untouched. Logged-in/no-store visitors already
				// returned null above, so prerender never touches private paths.
				if ( 'prerender' === $mode && ! $this->is_prerender_allowed() ) {
					$mode      = 'prefetch';
					$eagerness = 'conservative';
				}
				$eagerness = $this->maybe_cap_speculation_eagerness( $eagerness );
				// Commerce/auth guardrail (issue #1183): prerender executes
				// page JavaScript even at moderate eagerness, so a commerce
				// context degrades prerender to prefetch (prefetch only,
				// never eager) rather than emitting prerender/moderate.
				if ( 'prerender' === $mode && $this->is_speculation_commerce_or_auth() ) {
					$mode = 'prefetch';
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
			// Host override detection via get_speculation_default_override() honors
			// both the constant/env lookup and WP 7.1 wp_get_speculation_rules_default_configuration() when available.
			$has_host_override = null !== $this->get_speculation_default_override( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' );
			if (
				! empty( $this->get_options()['cache_settings']['enableCache'] ) &&
				'auto' === ( $config['eagerness'] ?? 'auto' ) &&
				! $has_host_override
			) {
				$config['eagerness'] = 'conservative';
			}

			return $config;
		}

		/**
		 * RUM-weighted top-URL prefetch cap (issue #1183).
		 *
		 * Reads `preload_settings.speculationTopUrlsLimit` (default 2,
		 * clamped to 1-5 as the footprint guard). Fail-open: any missing or
		 * malformed value returns 2, never fatal.
		 *
		 * @since 2.2.0
		 *
		 * @return int Capped limit between 1 and 5.
		 */
		private function get_speculation_top_urls_limit(): int {
			try {
				$raw   = $this->get_options()['preload_settings']['speculationTopUrlsLimit'] ?? 2;
				$limit = is_numeric( $raw ) ? (int) $raw : 2;
				if ( $limit < 1 || $limit > 5 ) {
					return 2;
				}
				return $limit;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 2;
			}
		}

		/**
		 * Whether the current request is a commerce/auth context for speculation guardrails.
		 *
		 * Reuses `AI_Adaptive::is_commerce_or_auth_context()` when available
		 * (guarded by class_exists/method_exists for backward compat), with a
		 * conservative local fallback (WooCommerce presence, cart/checkout/
		 * account conditionals, logged-in visitor, cart cookies). Fail-closed:
		 * any throwable means "commerce" so uncertainty suppresses the
		 * highest-risk prerender mode; the outer list builder stays fail-open
		 * (returns empty) for prefetch paths.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when eager speculation must be suppressed.
		 */
		private function is_speculation_commerce_or_auth(): bool {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) && method_exists( 'PerformanceOptimise\Inc\AI_Adaptive', 'is_commerce_or_auth_context' ) ) {
					return (bool) AI_Adaptive::is_commerce_or_auth_context();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( class_exists( 'WooCommerce' ) || function_exists( 'WC' ) || function_exists( 'wc_get_checkout_url' ) ) {
					return true;
				}
				foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $conditional ) {
					if ( function_exists( $conditional ) ) {
						try {
							if ( call_user_func( $conditional ) ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
				if ( function_exists( 'is_user_logged_in' ) ) {
					try {
						// Frontend visitors only (mirrors
						// AI_Adaptive::is_frontend_context()): admin/REST/cron/
						// CLI/AJAX requests run with a logged-in admin present and
						// never represent a visitor seeing speculation rules.
						if ( $this->is_speculation_frontend_context() && is_user_logged_in() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// is_string-guarded like the ESI cookie reads: an array-valued
				// cookie must not force suppression (attacker-influenced
				// cache behavior); only non-empty string values count.
				if ( ( isset( $_COOKIE['woocommerce_items_in_cart'] ) && is_string( $_COOKIE['woocommerce_items_in_cart'] ) && '' !== $_COOKIE['woocommerce_items_in_cart'] ) || ( isset( $_COOKIE['woocommerce_cart_hash'] ) && is_string( $_COOKIE['woocommerce_cart_hash'] ) && '' !== $_COOKIE['woocommerce_cart_hash'] ) ) {
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether the current request looks like a frontend visitor visit.
		 *
		 * Mirrors `AI_Adaptive::is_frontend_context()` for the local
		 * commerce/auth fallback: admin, REST, AJAX, cron, and CLI requests
		 * never represent a visitor seeing speculation rules. All probes are
		 * function_exists-guarded so unit tests and minimal installs default
		 * to frontend (true). Fail-open: any throwable means frontend.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when the request looks like a frontend visit.
		 */
		private function is_speculation_frontend_context(): bool {
			try {
				if ( defined( 'WP_CLI' ) && WP_CLI ) {
					return false;
				}
				if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
					return false;
				}
				if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
					return false;
				}
				if ( function_exists( 'wp_doing_cron' ) ) {
					try {
						if ( wp_doing_cron() ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'is_admin' ) ) {
					try {
						if ( is_admin() ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'wp_doing_ajax' ) ) {
					try {
						if ( wp_doing_ajax() ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Cap a speculation eagerness value in commerce/auth contexts.
		 *
		 * Prerender-risk guardrail (issue #1183): `eager` becomes `moderate`
		 * when {@see is_speculation_commerce_or_auth()} is true; every other
		 * value passes through untouched. Invalid values fall back to
		 * `conservative`. Manual user settings are never persisted — the cap
		 * applies to the emitted rule only.
		 *
		 * @since 2.2.0
		 *
		 * @param string $eagerness Raw eagerness value.
		 * @return string Capped eagerness value.
		 */
		private function maybe_cap_speculation_eagerness( string $eagerness ): string {
			try {
				if ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
					return 'conservative';
				}
				if ( 'eager' === $eagerness && $this->is_speculation_commerce_or_auth() ) {
					return 'moderate';
				}
				return $eagerness;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'conservative';
			}
		}

		/**
		 * RUM-weighted top URLs from the learned AI model (issue #1183).
		 *
		 * Reads the field-weighted model (`avgLCP*log(count)` scoring) via
		 * `AI_Adaptive::get_model()` and sanitizes the full `prefetch_urls`
		 * list here — rather than via `AI_Adaptive::get_prefetch_urls()`,
		 * which pre-slices to 2 before validation, so an invalid entry
		 * cannot waste a fill slot. Each URL is re-validated with
		 * {@see is_speculation_list_url_valid()} (same-origin, no commerce/
		 * admin/query), deduped, and capped at the `speculationTopUrlsLimit`
		 * budget. Empty model, missing class, or any failure returns an empty
		 * array so callers fall back to document-rule-only behavior (fail-open,
		 * never fatal). Multisite-safe: per-site model via per-site options,
		 * same-site host check prevents cross-site leakage.
		 *
		 * @since 2.2.0
		 *
		 * @param int $limit Maximum URLs to return.
		 * @return string[] Validated absolute model URLs (possibly empty).
		 */
		private function get_model_weighted_speculation_urls( int $limit = 2 ): array {
			try {
				if ( $limit < 1 ) {
					return array();
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\AI_Adaptive' ) || ! method_exists( 'PerformanceOptimise\Inc\AI_Adaptive', 'get_model' ) ) {
					return array();
				}
				$model = AI_Adaptive::get_model();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			if ( ! is_array( $model ) ) {
				return array();
			}
			$raw = $model['prefetch_urls'] ?? array();
			if ( ! is_array( $raw ) || empty( $raw ) ) {
				return array();
			}
			try {
				$urls = array();
				foreach ( $raw as $candidate ) {
					if ( ! is_string( $candidate ) || '' === $candidate ) {
						continue;
					}
					$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( $candidate ) ) : trim( $candidate );
					if ( '' === $clean ) {
						continue;
					}
					if ( in_array( $clean, $urls, true ) ) {
						continue;
					}
					if ( ! $this->is_speculation_list_url_valid( $clean ) ) {
						continue;
					}
					$urls[] = $clean;
					if ( count( $urls ) >= $limit ) {
						break;
					}
				}
				return $urls;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Collect high-value same-site URLs for the speculation list rule.
		 *
		 * Source: home URL first, then `performance_audit.high_value_urls`,
		 * then RUM-weighted model top URLs (field-weighted via
		 * {@see get_model_weighted_speculation_urls()}, capped at
		 * `preload_settings.speculationTopUrlsLimit`), then RUM volume
		 * winners via {@see get_rum_top_urls()} (same cap). Each candidate
		 * is normalized via `esc_url_raw(trim())`, deduped, same-site
		 * validated, and capped (keeps the ~1KB footprint). Invalid URLs
		 * are skipped individually (fail-open); an empty array means "emit
		 * nothing".
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Merge RUM-weighted model top URLs within the speculationTopUrlsLimit fill cap.
		 *
		 * @return string[] Validated absolute URLs (possibly empty).
		 */
		public function get_speculation_list_urls(): array {
			if ( function_exists( 'is_admin' ) ) {
				try {
					if ( is_admin() ) {
						return array();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			if ( function_exists( 'get_option' ) ) {
				try {
					$structure = get_option( 'permalink_structure' );
					if ( '' === $structure || false === $structure ) {
						return array();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$candidates = array( Util::cached_home_url( '/' ) );

			$settings   = Util::get_settings();
			$high_value = $settings['performance_audit']['high_value_urls'] ?? array();
			if ( is_string( $high_value ) ) {
				$high_value = preg_split( '/[\r\n,]+/', $high_value );
			}
			if ( is_array( $high_value ) ) {
				// Bound raw user input before validation: a long pasted list
				// must not mean unbounded per-request Woo lookups/parses.
				// 20 raw entries amply cover the 10-URL output budget.
				$high_value = array_slice( array_values( $high_value ), 0, 20 );
				foreach ( $high_value as $high_url ) {
					if ( is_string( $high_url ) && '' !== trim( $high_url ) ) {
						$candidates[] = $high_url;
					}
				}
			}

			// RUM-weighted model winners first (field-weighted top URLs real
			// users visit next, capped at the top-URL limit), then volume-
			// ranked RUM winners fill any remaining fill budget (no extra
			// cron load: opportunistic reads with fail-open empty). The
			// combined RUM-weighted fill never exceeds the limit so home +
			// explicit high-value URLs keep their budget.
			$top_limit = $this->get_speculation_top_urls_limit();
			// Over-fetch the model so a hit duplicating home/high-value URLs
			// does not waste a fill slot (final dedupe would otherwise drop
			// it and under-fill). Only genuinely new URLs count toward the
			// combined RUM-weighted budget.
			$model_urls = $this->get_model_weighted_speculation_urls( $top_limit + count( $candidates ) );
			$seen       = array();
			foreach ( $candidates as $prior ) {
				if ( ! is_string( $prior ) ) {
					continue;
				}
				$clean_prior = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( $prior ) ) : trim( $prior );
				if ( '' !== $clean_prior ) {
					$seen[ $this->normalize_speculation_url( $clean_prior ) ] = true;
				}
			}
			$kept = 0;
			foreach ( $model_urls as $model_url ) {
				if ( isset( $seen[ $this->normalize_speculation_url( $model_url ) ] ) ) {
					continue;
				}
				$seen[ $this->normalize_speculation_url( $model_url ) ] = true;
				$candidates[] = $model_url;
				++$kept;
				if ( $kept >= $top_limit ) {
					break;
				}
			}
			$remaining = $top_limit - $kept;
			if ( $remaining > 0 ) {
				foreach ( $this->get_rum_top_urls( $remaining ) as $rum_url ) {
					$candidates[] = $rum_url;
				}
			}

			$urls    = array();
			$emitted = array();
			$checked = 0;
			foreach ( $candidates as $candidate ) {
				if ( ! is_string( $candidate ) ) {
					continue;
				}
				$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( $candidate ) ) : trim( $candidate );
				if ( '' === $clean ) {
					continue;
				}
				$norm_key = $this->normalize_speculation_url( $clean );
				if ( isset( $emitted[ $norm_key ] ) ) {
					continue;
				}
				// Bound validation attempts: invalid URLs do not count
				// toward the output budget, so cap total checks (3x budget)
				// to keep a hostile/pasted list off the hot path.
				if ( ++$checked > 30 ) {
					break;
				}
				if ( ! $this->is_speculation_list_url_valid( $clean ) ) {
					continue;
				}
				$emitted[ $norm_key ] = true;
				$urls[]               = $clean;
				if ( count( $urls ) >= 10 ) {
					break;
				}
			}

			return $urls;
		}

		/**
		 * Top RUM (real-visit) URLs by visit volume.
		 *
		 * Reads the `wppo_web_vitals_rum` per-day/per-path aggregates via
		 * `RUM::get_aggregate_readonly()` (per-request memoized, no queue
		 * flush, no transient churn — safe for the frontend hot path),
		 * sums sample counts (`max(lcp.n, ttfb.n, ...)`) per
		 * normalized path across days, and resolves the winners to absolute
		 * same-site URLs. Candidates are validated with
		 * {@see is_speculation_list_url_valid()} (cart/checkout/account,
		 * query strings, cross-site excluded) and capped so home +
		 * high-value + RUM total stays within the 10-URL budget.
		 * Per-request memoized keyed by limit.
		 *
		 * Fail-open: any throwable, missing class, or empty RUM returns an
		 * empty array — never fatal, never white-screen.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Accept a fill-budget limit for the RUM-weighted portion.
		 * @since 2.2.0 Read via get_aggregate_readonly() with per-request memo.
		 *
		 * @param int $limit Maximum URLs to return.
		 * @return string[] Validated absolute RUM winner URLs (possibly empty).
		 */
		private function get_rum_top_urls( int $limit = 10 ): array {
			if ( $limit < 1 ) {
				return array();
			}
			if ( $limit > 10 ) {
				$limit = 10;
			}
			if ( isset( self::$speculation_rum_top_memo[ $limit ] ) ) {
				return self::$speculation_rum_top_memo[ $limit ];
			}
			$result = $this->compute_rum_top_urls( $limit );
			if ( count( self::$speculation_rum_top_memo ) > 10 ) {
				self::$speculation_rum_top_memo = array();
			}
			self::$speculation_rum_top_memo[ $limit ] = $result;
			return $result;
		}

		/**
		 * Uncached RUM top-URL computation for {@see get_rum_top_urls()}.
		 *
		 * @since 2.2.0
		 *
		 * @param int $limit Maximum URLs to return.
		 * @return string[] Validated absolute RUM winner URLs (possibly empty).
		 */
		private function compute_rum_top_urls( int $limit ): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_aggregate_readonly' ) ) {
					return array();
				}
				$rum = RUM::get_aggregate_readonly();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}

			if ( ! is_array( $rum ) || empty( $rum ) ) {
				return array();
			}

			try {
				$counts = array();
				foreach ( $rum as $paths ) {
					if ( ! is_array( $paths ) ) {
						continue;
					}
					foreach ( $paths as $path => $metrics ) {
						if ( ! is_string( $path ) || '' === $path || ! is_array( $metrics ) ) {
							continue;
						}
						// RUM buckets store query-less normalized paths already;
						// skip anything carrying a query/fragment defensively.
						if ( false !== strpos( $path, '?' ) || false !== strpos( $path, '#' ) ) {
							continue;
						}
						$count = 0;
						foreach ( $metrics as $metric => $aggregate ) {
							if ( 'lcpUrls' === $metric || ! is_array( $aggregate ) ) {
								continue;
							}
							$n = isset( $aggregate['n'] ) ? (int) $aggregate['n'] : 0;
							if ( $n > $count ) {
								$count = $n;
							}
						}
						if ( $count <= 0 ) {
							continue;
						}
						$normalized = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_rum_path' )
							? Util::normalize_rum_path( $path )
							: $path;
						if ( '/' === $normalized ) {
							continue;
						}
						if ( ! isset( $counts[ $normalized ] ) ) {
							$counts[ $normalized ] = 0;
						}
						$counts[ $normalized ] += $count;
					}
				}

				if ( empty( $counts ) ) {
					return array();
				}

				arsort( $counts );

				$urls = array();
				foreach ( array_keys( $counts ) as $top_path ) {
					// Canonical pretty-permalink form carries a trailing
					// slash (RUM normalization strips it); restored here so
					// winners match the home/high-value URL style.
					if ( '/' !== substr( $top_path, -1 ) ) {
						$top_path .= '/';
					}
					$absolute = Util::cached_home_url( $top_path );
					$clean    = function_exists( 'esc_url_raw' ) ? esc_url_raw( $absolute ) : $absolute;
					if ( ! is_string( $clean ) || '' === $clean ) {
						continue;
					}
					if ( in_array( $clean, $urls, true ) ) {
						continue;
					}
					if ( ! $this->is_speculation_list_url_valid( $clean ) ) {
						continue;
					}
					$urls[] = $clean;
					if ( count( $urls ) >= $limit ) {
						break;
					}
				}

				return $urls;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether speculation output is suppressed for the current visitor.
		 *
		 * Logged-in users stay excluded (mirrors the `null` config
		 * passthrough in {@see filter_speculation_rules_configuration()}).
		 * Cache-aware: pages served with `DONOTCACHEPAGE` / `no-store`
		 * (cart/checkout/account, previews) never speculate — speculating a
		 * non-cacheable URL wastes origin load and risks broken carts.
		 * Fail-closed: any throwable means "suppressed" so uncertainty
		 * disables output (privacy guard must never fail open for
		 * logged-in/DONOTCACHEPAGE visitors).
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when rules must not be emitted.
		 */
		private function is_speculation_suppressed_for_visitor(): bool {
			try {
				if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
					return true;
				}

				// Non-cacheable responses must not speculate.
				if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
					return true;
				}

				// Commerce / preview contexts are served no-store.
				foreach ( array( 'is_cart', 'is_checkout', 'is_account_page', 'is_preview', 'is_customize_preview' ) as $conditional ) {
					if ( function_exists( $conditional ) ) {
						try {
							if ( call_user_func( $conditional ) ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}

				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Eager prerender list rule for the home link on singular views.
		 *
		 * Returns a `{"source":"list"}` rule with `eager` eagerness for the
		 * home URL only when the current view is singular (and the home URL
		 * is present/valid). Commerce/auth contexts degrade to `moderate`
		 * via {@see maybe_cap_speculation_eagerness()} (prefetch only,
		 * never eager). Returns null otherwise (non-singular, no home
		 * link, logged-in visitor, document rules toggled off, or any
		 * failure) — fail-open to "emit nothing", never fatal.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Cap eager to moderate in commerce/auth contexts.
		 *
		 * @return array<string,mixed>|null The singular rule, or null.
		 */
		private function get_singular_home_link_rule(): ?array {
			try {
				if ( $this->is_speculation_suppressed_for_visitor() ) {
					return null;
				}

				$document_rules = $this->get_options()['preload_settings']['speculationDocumentRules'] ?? true;
				if ( ! $document_rules ) {
					return null;
				}

				if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
					return null;
				}

				$home = Util::cached_home_url( '/' );
				if ( ! is_string( $home ) || '' === $home ) {
					return null;
				}
				$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $home ) : $home;
				if ( '' === $clean || ! $this->is_speculation_list_url_valid( $clean ) ) {
					return null;
				}

				return array(
					'source'    => 'list',
					'urls'      => array( $clean ),
					'eagerness' => $this->maybe_cap_speculation_eagerness( 'eager' ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Document rule targeting the first post on archive views.
		 *
		 * Reads the first post URL from the main query (`$wp_query->posts`
		 * via `get_permalink()`, all guarded) and emits a
		 * `{"source":"document"}` rule whose `where` clause pairs an
		 * `href_matches` pattern for that post path with a first-post
		 * `selector_matches`. Returns null when not an archive, when no
		 * first post resolves, for logged-in visitors, when document rules
		 * are toggled off, or on any failure (fail-open, never fatal).
		 *
		 * @since 2.0.0
		 *
		 * @return array<string,mixed>|null The archive document rule, or null.
		 */
		private function get_archive_first_post_rule(): ?array {
			try {
				if ( $this->is_speculation_suppressed_for_visitor() ) {
					return null;
				}

				$document_rules = $this->get_options()['preload_settings']['speculationDocumentRules'] ?? true;
				if ( ! $document_rules ) {
					return null;
				}

				$is_archive_view = ( function_exists( 'is_archive' ) && is_archive() )
					|| ( function_exists( 'is_home' ) && is_home() );
				if ( ! $is_archive_view ) {
					return null;
				}

				$first_post_url = null;
				global $wp_query;
				if ( isset( $wp_query->posts ) && is_array( $wp_query->posts ) && ! empty( $wp_query->posts ) ) {
					$first = $wp_query->posts[0];
					if ( function_exists( 'get_permalink' ) ) {
						$permalink = get_permalink( $first );
						if ( is_string( $permalink ) && '' !== $permalink ) {
							$first_post_url = $permalink;
						}
					}
				}
				if ( ! is_string( $first_post_url ) || '' === $first_post_url ) {
					return null;
				}

				$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( $first_post_url ) : $first_post_url;
				if ( '' === $clean || ! $this->is_speculation_list_url_valid( $clean ) ) {
					return null;
				}

				$path = null;
				if ( function_exists( 'wp_parse_url' ) ) {
					$path = wp_parse_url( $clean, PHP_URL_PATH );
				}
				if ( ! is_string( $path ) || '' === $path ) {
					return null;
				}
				$href_pattern = rtrim( $path, '/' ) . '/*';
				if ( '/' === $path ) {
					return null;
				}

				$preload_settings = $this->get_options()['preload_settings'] ?? array();
				$eagerness        = $preload_settings['speculationEagerness'] ?? 'conservative';
				if ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
					$eagerness = 'conservative';
				}
				$eagerness = $this->maybe_cap_speculation_eagerness( $eagerness );

				return array(
					'source'    => 'document',
					'where'     => array(
						'and' => array(
							array( 'href_matches' => $href_pattern ),
							array( 'selector_matches' => 'main article:first-of-type a, article.post:first-of-type a, .post:first-of-type a' ),
						),
					),
					'eagerness' => $eagerness,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Validate a single speculation list URL.
		 *
		 * Same-site (host must match home host, preventing multisite
		 * cross-site leakage), http(s) only, and rejects admin, login,
		 * REST, commerce (cart/checkout/account) paths, and any URL
		 * carrying a query string or fragment (mirroring core's
		 * `?`-URL exclusion). Same-host different-port URLs and URLs
		 * with userinfo are rejected as cross-origin/unsafe.
		 *
		 * Per-request memoized (URL + home + Woo availability); the same
		 * candidates validated by the list, prerender, and register paths
		 * cost one Woo lookup set per distinct URL.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Memoize per-request results.
		 *
		 * @param string $url Candidate absolute URL.
		 * @return bool True when the URL may be prefetched.
		 */
		private function is_speculation_list_url_valid( string $url ): bool {
			$parts = wp_parse_url( $url );
			if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
				return false;
			}

			$home = Util::cached_home_url();
			$key  = $url . "\0" . $home . "\0" . self::speculation_woo_fingerprint();
			if ( array_key_exists( $key, self::$speculation_url_validity_memo ) ) {
				return self::$speculation_url_validity_memo[ $key ];
			}
			// Bound the memo for long-running processes (Action Scheduler,
			// WP-CLI) where unbounded growth would leak memory.
			if ( count( self::$speculation_url_validity_memo ) > 200 ) {
				self::$speculation_url_validity_memo = array();
			}
			$result                                      = $this->validate_speculation_list_url_uncached( $url, $parts, $home );
			self::$speculation_url_validity_memo[ $key ] = $result;
			return $result;
		}

		/**
		 * Reset the speculation URL validity + commerce-path memos (for tests).
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_speculation_url_memo(): void {
			self::$speculation_url_validity_memo   = array();
			self::$speculation_commerce_paths_memo = null;
			self::$speculation_commerce_paths_sig  = '';
			self::$speculation_normalize_memo      = array();
			self::$speculation_rum_top_memo        = array();
		}

		/**
		 * Fingerprint WooCommerce-function availability for the speculation memos.
		 *
		 * Test fixtures may define wc_get_* after a first resolution; the
		 * fingerprint keeps the cached verdicts keyed on that boundary so a
		 * stale "no Woo" verdict is never reused once Woo helpers appear.
		 *
		 * @since 2.2.0
		 *
		 * @return string '1'/'0' flags for wc_get_checkout_url, wc_get_cart_url, wc_get_page_permalink.
		 */
		private static function speculation_woo_fingerprint(): string {
			return ( function_exists( 'wc_get_checkout_url' ) ? '1' : '0' )
				. ( function_exists( 'wc_get_cart_url' ) ? '1' : '0' )
				. ( function_exists( 'wc_get_page_permalink' ) ? '1' : '0' );
		}

		/**
		 * Resolved speculation commerce paths (per-request memoized).
		 *
		 * Base cart/checkout/account prefixes plus WooCommerce dynamic
		 * paths (checkout/cart/myaccount permalinks). Resolved once per
		 * request instead of once per candidate URL.
		 *
		 * @since 2.2.0
		 *
		 * @return string[] Lowercase path prefixes.
		 */
		private function get_speculation_commerce_paths(): array {
			$sig = self::speculation_woo_fingerprint();
			if ( null !== self::$speculation_commerce_paths_memo && $sig === self::$speculation_commerce_paths_sig ) {
				return self::$speculation_commerce_paths_memo;
			}
			$commerce_paths = array( '/cart', '/checkout', '/my-account', '/account' );
			if ( function_exists( 'wc_get_checkout_url' ) ) {
				try {
					$checkout_url = wc_get_checkout_url();
					if ( $checkout_url ) {
						$p = wp_parse_url( $checkout_url, PHP_URL_PATH );
						if ( is_string( $p ) && '' !== $p && '/' !== $p ) {
							$commerce_paths[] = rtrim( $p, '/' );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'wc_get_cart_url' ) ) {
				try {
					$cart_url = wc_get_cart_url();
					if ( $cart_url ) {
						$p = wp_parse_url( $cart_url, PHP_URL_PATH );
						if ( is_string( $p ) && '' !== $p && '/' !== $p ) {
							$commerce_paths[] = rtrim( $p, '/' );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( function_exists( 'wc_get_page_permalink' ) ) {
				try {
					$myaccount_url = wc_get_page_permalink( 'myaccount' );
					if ( $myaccount_url ) {
						$p = wp_parse_url( $myaccount_url, PHP_URL_PATH );
						if ( is_string( $p ) && '' !== $p && '/' !== $p ) {
							$commerce_paths[] = rtrim( $p, '/' );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			self::$speculation_commerce_paths_memo = array_values( array_unique( array_map( 'strtolower', $commerce_paths ) ) );
			self::$speculation_commerce_paths_sig  = $sig;
			return self::$speculation_commerce_paths_memo;
		}

		/**
		 * Normalize a speculation URL for dedupe/carve-out comparison.
		 *
		 * Lowercases scheme+host, strips default ports, drops trailing-slash
		 * variants, so `https://example.com/post` and
		 * `https://EXAMPLE.com/post/` compare equal (RUM winners restore the
		 * trailing slash while raw high_value_urls input may not carry one).
		 * Query/fragment are preserved as-is: validated URLs never carry
		 * them, and distinct raw inputs must not collapse silently.
		 *
		 * @since 2.2.0
		 *
		 * @param string $url Candidate URL.
		 * @return string Normalized URL (input unchanged when unparseable).
		 */
		private function normalize_speculation_url( string $url ): string {
			if ( isset( self::$speculation_normalize_memo[ $url ] ) ) {
				return self::$speculation_normalize_memo[ $url ];
			}
			$normalized = $url;
			try {
				$parts = wp_parse_url( $url );
				if ( is_array( $parts ) && ! empty( $parts['host'] ) ) {
					$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
					$host   = strtolower( (string) $parts['host'] );
					$port   = isset( $parts['port'] ) ? (int) $parts['port'] : null;
					if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
						$port = null;
					}
					$path = (string) ( $parts['path'] ?? '' );
					if ( function_exists( 'untrailingslashit' ) ) {
						$path = untrailingslashit( $path );
					} else {
						$path = rtrim( $path, '/' );
					}
					$normalized = ( '' !== $scheme ? $scheme . '://' : '//' ) . $host
						. ( null !== $port && $port > 0 ? ':' . $port : '' ) . $path;
					if ( isset( $parts['query'] ) && '' !== (string) $parts['query'] ) {
						$normalized .= '?' . (string) $parts['query'];
					}
					if ( isset( $parts['fragment'] ) && '' !== (string) $parts['fragment'] ) {
						$normalized .= '#' . (string) $parts['fragment'];
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$normalized = $url;
			}
			if ( count( self::$speculation_normalize_memo ) > 200 ) {
				self::$speculation_normalize_memo = array();
			}
			self::$speculation_normalize_memo[ $url ] = $normalized;
			return $normalized;
		}

		/**
		 * Uncached speculation list URL validation.
		 *
		 * Same-site (host must match home host, preventing multisite
		 * cross-site leakage), http(s) only, and rejects admin, login,
		 * REST, commerce (cart/checkout/account) paths, and any URL
		 * carrying a query string or fragment (mirroring core's
		 * `?`-URL exclusion). Same-host different-port URLs and URLs
		 * with userinfo are rejected as cross-origin/unsafe.
		 *
		 * Called once per distinct URL via the
		 * {@see is_speculation_list_url_valid()} memo.
		 *
		 * @since 2.2.0
		 *
		 * @param string               $url   Candidate absolute URL.
		 * @param array<string, mixed> $parts Parsed URL parts.
		 * @param string               $home  Home URL.
		 * @return bool True when the URL may be prefetched.
		 */
		private function validate_speculation_list_url_uncached( string $url, array $parts, string $home ): bool {
			// Query strings and fragments are never speculated: core excludes
			// `?`-URLs by default and dynamic/action URLs must stay excluded.
			// Each invalid URL is skipped individually (fail-open).
			try {
				$query = wp_parse_url( $url, PHP_URL_QUERY );
				if ( is_string( $query ) && '' !== $query ) {
					return false;
				}
				$fragment = wp_parse_url( $url, PHP_URL_FRAGMENT );
				if ( is_string( $fragment ) && '' !== $fragment ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fall back to string checks when wp_parse_url with component fails.
				if ( false !== strpos( $url, '?' ) || false !== strpos( $url, '#' ) ) {
					return false;
				}
			}

			$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
			if ( '' !== $scheme && ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return false;
			}

			$home_host = wp_parse_url( $home, PHP_URL_HOST );
			if ( ! is_string( $home_host ) || '' === $home_host ) {
				return false;
			}
			if ( strtolower( $parts['host'] ) !== strtolower( $home_host ) ) {
				return false;
			}

			// Same-origin means host + port with no userinfo: a same-host
			// different-port URL is cross-origin for speculation, and a
			// user:pass@host URL must never be speculated (credential leak).
			// Default ports (80/443) are treated as equal to an absent port.
			if ( isset( $parts['user'] ) || isset( $parts['pass'] ) ) {
				return false;
			}
			$normalize_port = static function ( $port, $scheme ): ?int {
				if ( null === $port || '' === $port ) {
					return null;
				}
				$port = (int) $port;
				if ( $port <= 0 ) {
					return null;
				}
				if ( ( 'http' === $scheme && 80 === $port ) || ( 'https' === $scheme && 443 === $port ) ) {
					return null;
				}
				return $port;
			};
			try {
				$home_port = wp_parse_url( $home, PHP_URL_PORT );
			} catch ( \Throwable $e ) {
				unset( $e );
				$home_port = null;
			}
			$home_scheme = strtolower( (string) ( wp_parse_url( $home, PHP_URL_SCHEME ) ?? '' ) );
			if ( $normalize_port( $parts['port'] ?? null, $scheme ) !== $normalize_port( $home_port ?? null, $home_scheme ) ) {
				return false;
			}

			$path = strtolower( (string) ( $parts['path'] ?? '/' ) );

			if ( false !== strpos( $path, '/wp-admin' ) || false !== strpos( $path, 'wp-login.php' ) || false !== strpos( $path, '/wp-json' ) ) {
				return false;
			}

			// Nonce-bearing, logout, add-to-cart, and admin-ajax URLs must
			// never be speculated. Scoped to path+query (never scheme+host)
			// so hosts containing these substrings — or legitimate slugs
			// like /add-to-cart-guide/ handled below — are not over-blocked.
			// Query-param forms (preview, customize_changeset) need no check
			// here: any URL carrying a query string already returned false
			// above, mirroring core's `?`-URL exclusion.
			$query_string = '';
			try {
				$parsed_query = wp_parse_url( $url, PHP_URL_QUERY );
				if ( is_string( $parsed_query ) ) {
					$query_string = strtolower( $parsed_query );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$haystack = $path . '?' . $query_string;
			foreach ( array( 'nonce', 'logout', 'add-to-cart', 'admin-ajax' ) as $unsafe ) {
				if ( false !== strpos( $haystack, $unsafe ) ) {
					return false;
				}
			}

			$commerce_paths = $this->get_speculation_commerce_paths();

			$path_trimmed = rtrim( $path, '/' );
			foreach ( $commerce_paths as $commerce_path ) {
				$prefix = strtolower( rtrim( (string) $commerce_path, '/' ) );
				if ( '' === $prefix ) {
					continue;
				}
				if ( $path_trimmed === $prefix || 0 === strpos( $path_trimmed . '/', $prefix . '/' ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Whether the current request must not receive the high-value prerender list (issue #1243).
		 *
		 * Request-scoped counterpart to {@see is_speculation_commerce_or_auth()}: the
		 * site-wide helper returns true on the mere presence of WooCommerce
		 * (correct for global mode/eagerness guardrails), which would keep the
		 * opt-in prerender list dead on every Woo store. The prerender list is
		 * a per-URL feature whose URL safety already comes from the per-URL
		 * {@see is_speculation_list_url_valid()} commerce-path backstop, so
		 * this probe only inspects the *current request*: cart/checkout/
		 * account conditionals, a frontend logged-in visitor, and an active
		 * cart session via `woocommerce_items_in_cart` / `woocommerce_cart_hash`
		 * cookies. Fail-closed: any throwable means "suppressed".
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when the prerender list must not be emitted for this request.
		 */
		private function is_prerender_list_suppressed_for_request(): bool {
			try {
				foreach ( array( 'is_cart', 'is_checkout', 'is_account_page' ) as $conditional ) {
					if ( function_exists( $conditional ) ) {
						try {
							if ( call_user_func( $conditional ) ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
				if ( function_exists( 'is_user_logged_in' ) ) {
					try {
						if ( $this->is_speculation_frontend_context() && is_user_logged_in() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// is_string-guarded like the ESI cookie reads: an array-valued
				// cookie must not force suppression; only non-empty strings.
				if ( ( isset( $_COOKIE['woocommerce_items_in_cart'] ) && is_string( $_COOKIE['woocommerce_items_in_cart'] ) && '' !== $_COOKIE['woocommerce_items_in_cart'] ) || ( isset( $_COOKIE['woocommerce_cart_hash'] ) && is_string( $_COOKIE['woocommerce_cart_hash'] ) && '' !== $_COOKIE['woocommerce_cart_hash'] ) ) {
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * High-value prerender list URLs (issue #1237).
		 *
		 * Builds the high-value URL set (home plus capped RUM top URLs via
		 * {@see get_speculation_list_urls()}, same-site validated with cart,
		 * checkout, account, and query-string URLs excluded) and trims it to
		 * the `speculationTopUrlsLimit` fill cap so the rules JSON stays near
		 * ~0.5 KB. Returns an empty array unless the opt-in
		 * `preload_settings.speculationPrerenderList` toggle is enabled
		 * alongside `enableSpeculationRules`.
		 *
		 * Guards: same-origin enforcement via
		 * {@see is_speculation_list_url_valid()}, request-scoped commerce and
		 * auth exclusion via {@see is_prerender_list_suppressed_for_request()}
		 * (cart/checkout/account conditionals, logged-in visitor, cart
		 * cookies — deliberately not the site-wide
		 * {@see is_speculation_commerce_or_auth()} Woo-presence guard, so the
		 * list still fires on safe pages of Woo stores), logged-in
		 * exclusion via {@see is_speculation_suppressed_for_visitor()}, and
		 * the static-cache + RUM-qualified gate via
		 * {@see is_prerender_allowed()} (matching the document-mode
		 * guardrail: prerender executes page JavaScript, so unqualified
		 * origins get no prerender list).
		 * Fail-open: any empty model, missing toggle, excluded context, or
		 * failure returns an empty array (conservative document-rule-only
		 * behavior), never fatal. Multisite-safe: per-site settings and
		 * model, no cross-site leakage.
		 *
		 * @since 2.2.0
		 *
		 * @param string[]|null $candidates     Optional pre-validated candidates (defaults to get_speculation_list_urls()).
		 * @param array         $existing_rules Existing speculation rules used for list-URL dedupe.
		 * @return string[] Validated prerender URLs (possibly empty).
		 */
		private function get_prerender_list_urls( ?array $candidates = null, array $existing_rules = array() ): array {
			try {
				if ( empty( $this->get_options()['preload_settings']['enableSpeculationRules'] ) ) {
					return array();
				}
				if ( empty( $this->get_options()['preload_settings']['speculationPrerenderList'] ) ) {
					return array();
				}
				// Prerender executes page JavaScript speculatively: only run
				// on origins where prerender is provably safe (static cache
				// active + RUM-qualified), matching the document-mode
				// guardrail. Unqualified origins get no prerender list.
				if ( ! $this->is_prerender_allowed() ) {
					return array();
				}
				if ( $this->is_speculation_suppressed_for_visitor() ) {
					return array();
				}
				// Request-scoped commerce/auth guard (issue #1243): suppress
				// only when the *current request* is commerce/auth (cart,
				// checkout, account, logged-in visitor, active cart cookies).
				// The site-wide is_speculation_commerce_or_auth() Woo-presence
				// guard must not apply here — it would keep the list dead on
				// every Woo store even on safe pages, while per-URL safety
				// already comes from is_speculation_list_url_valid().
				if ( $this->is_prerender_list_suppressed_for_request() ) {
					return array();
				}
				if ( null === $candidates ) {
					$candidates = $this->get_speculation_list_urls();
				}
				if ( empty( $candidates ) ) {
					return array();
				}
				$limit = $this->get_speculation_top_urls_limit();
				$urls  = array();
				$seen  = array();
				foreach ( $candidates as $candidate ) {
					if ( ! is_string( $candidate ) || '' === trim( $candidate ) ) {
						continue;
					}
					$clean = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( $candidate ) ) : trim( $candidate );
					if ( '' === $clean ) {
						continue;
					}
					// Normalization-aware intra-dedupe: RUM winners restore
					// the trailing slash while raw high_value_urls input may
					// not carry one — strict comparison would double-emit.
					$norm_key = $this->normalize_speculation_url( $clean );
					if ( isset( $seen[ $norm_key ] ) ) {
						continue;
					}
					if ( ! $this->is_speculation_list_url_valid( $clean ) ) {
						continue;
					}
					$seen[ $norm_key ] = true;
					$urls[]            = $clean;
					if ( count( $urls ) >= $limit ) {
						break;
					}
				}
				if ( empty( $urls ) ) {
					return array();
				}
				return array_values( $this->dedupe_speculation_urls_against_rules( $urls, $existing_rules ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Normalize post-filter prerender URLs (issue #1237 follow-up).
		 *
		 * Shared by both merge paths in
		 * {@see wppo_register_speculation_rules()}: keeps strings only,
		 * re-validates every URL with
		 * {@see is_speculation_list_url_valid()} (a filter must not inject
		 * commerce/cross-origin/query URLs), dedupes against existing rules
		 * (normalization-aware), and re-slices to
		 * {@see get_speculation_top_urls_limit()} so a filter returning 20
		 * URLs cannot defeat the ~0.5 KB footprint guard.
		 *
		 * @since 2.2.0
		 *
		 * @param array $urls           Post-filter candidate URLs.
		 * @param array $existing_rules Existing speculation rules for dedupe.
		 * @return string[] Normalized prerender URLs (possibly empty).
		 */
		private function normalize_prerender_urls( array $urls, array $existing_rules ): array {
			$urls  = array_values( array_filter( $urls, 'is_string' ) );
			$urls  = array_values(
				array_filter(
					$urls,
					array( $this, 'is_speculation_list_url_valid' )
				)
			);
			$urls  = $this->dedupe_speculation_urls_against_rules( $urls, $existing_rules );
			$limit = $this->get_speculation_top_urls_limit();
			return array_values( array_slice( $urls, 0, $limit ) );
		}

		/**
		 * Validate a post-filter prerender list rule (issue #1237 follow-up).
		 *
		 * Enforces the list-rule schema after the
		 * `wppo_speculation_prerender_list_rule` filter: `source` must stay
		 * `list` (a filter must not morph the rule into a document rule),
		 * `eagerness` must be a known value (invalid values fall back to
		 * `moderate`), and `urls` are re-validated, deduped, and re-sliced
		 * to the top-URL limit. Returns null when the rule must be dropped
		 * (wrong source or no valid URLs left).
		 *
		 * @since 2.2.0
		 *
		 * @param mixed $rule          Post-filter rule candidate.
		 * @param array $existing_rules Existing speculation rules for dedupe.
		 * @return array<string,mixed>|null Validated rule, or null to drop it.
		 */
		private function validate_prerender_rule( $rule, array $existing_rules ): ?array {
			if ( ! is_array( $rule ) ) {
				return null;
			}
			if ( 'list' !== ( $rule['source'] ?? '' ) ) {
				return null;
			}
			$eagerness = $rule['eagerness'] ?? 'moderate';
			if ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
				$eagerness = 'moderate';
			}
			$raw_urls = $rule['urls'] ?? array();
			if ( ! is_array( $raw_urls ) ) {
				return null;
			}
			$urls = $this->normalize_prerender_urls( $raw_urls, $existing_rules );
			if ( empty( $urls ) ) {
				return null;
			}
			$rule['source']    = 'list';
			$rule['eagerness'] = $eagerness;
			$rule['urls']      = array_values( $urls );
			return $rule;
		}

		/**
		 * Validate post-filter speculation rules output (trusted-code-only).
		 *
		 * The `wppo_speculation_*_rules` filters run trusted code, but a
		 * misbehaving callback must not corrupt the emitted
		 * `speculationrules` block: a non-array return falls back to the
		 * pre-filter rules, and non-array entries are dropped. Shape
		 * validation beyond that stays with the rule builders above.
		 *
		 * @since 2.2.0
		 *
		 * @param mixed $filtered  Post-filter rules candidate.
		 * @param array $fallback  Pre-filter rules.
		 * @return array Validated rules.
		 */
		private function validate_speculation_rules_output( $filtered, array $fallback ): array {
			if ( ! is_array( $filtered ) ) {
				return $fallback;
			}
			$clean = array();
			foreach ( $filtered as $entry ) {
				if ( is_array( $entry ) ) {
					$clean[] = $entry;
				}
			}
			return $clean;
		}

		/**
		 * Register the guarded high-value prerender list rule (issue #1237).
		 *
		 * Wires the high-value URL selection ({@see get_prerender_list_urls()},
		 * home plus capped RUM top URLs) to a dedicated prerender list rule
		 * that only fires for safe same-origin candidates: commerce, auth,
		 * and logged-in contexts stay on conservative prefetch or nothing.
		 *
		 * Dual-path merge into the single core `speculationrules` block on
		 * WP 6.8+: when `$rules` is a `WP_Speculation_Rules` object (the
		 * `wp_load_speculation_rules` action path) the rule is added via
		 * `add_rule( 'prerender', 'wppo-high-value-prerender', ... )` with a
		 * `has_rule()` guard so no duplicate is emitted; otherwise (legacy
		 * array path, WP <6.8 or unit-test fixtures) the rule is appended to
		 * the array with dedupe against pre-existing list rules. The WP 6.8+
		 * object path is additionally guarded by `function_exists` on
		 * `wp_get_speculation_rules_configuration` plus `version_compare`,
		 * so pre-6.8 installs fall back to the legacy plugin-owned output.
		 * Both paths apply the same `wppo_speculation_prerender_list_urls`
		 * and `wppo_speculation_prerender_list_rule` filters with identical
		 * post-filter validation ({@see normalize_prerender_urls()},
		 * {@see validate_prerender_rule()}).
		 *
		 * Eagerness is pinned to `moderate` by design (not derived from
		 * `speculationEagerness`): `eager` prerender fires on page load and
		 * would speculatively execute JS for every visitor, while
		 * `conservative` waits for hover and defeats prerender's
		 * near-instant-navigation purpose for high-value URLs; `moderate`
		 * matches core's cached-site default.
		 *
		 * Backward compatible with WP 6.2+ and PHP 8.2+. Fail-open: toggle
		 * off, empty model, commerce context, logged-in visitor, or any
		 * failure returns the input unchanged (current conservative
		 * document-rule-only behavior), never fatal. Multisite-safe:
		 * per-site settings and model, no cross-site leakage.
		 *
		 * @since 2.2.0
		 *
		 * @param mixed         $rules          Speculation rules (WP_Speculation_Rules object or legacy rules array).
		 * @param string[]|null $candidate_urls Optional candidate URLs (defaults to the high-value list selection).
		 * @return mixed Updated rules, or the input unchanged.
		 */
		public function wppo_register_speculation_rules( $rules, ?array $candidate_urls = null ) {
			try {
				if ( is_object( $rules ) && method_exists( $rules, 'add_rule' ) ) {
					if ( ! function_exists( 'wp_get_speculation_rules_configuration' ) && ! function_exists( 'wp_get_speculation_rules' ) ) {
						return $rules;
					}
					// Belt-and-braces version guard (same newest-on-unknown
					// default as the registration site above, via Wp_Version).
					// Canonicalization note (REF-010): an explicit ''
					// $wp_version global falls through to get_bloginfo()
					// (majority spelling); the pre-REF-010 bare-isset() cast
					// compared '' as-is and failed this gate. Core never
					// emits ''.
					if ( ! Wp_Version::is_at_least( '6.8', true ) ) {
						return $rules;
					}
					// Per-request backstop: core 6.8+ exposes has_rule(), but
					// a WP_Speculation_Rules-shaped object without it would
					// duplicate the rule on repeated firings. Instance state
					// (not a method static) so test instances stay isolated
					// while the single production instance stays guarded.
					if ( $this->speculation_prerender_object_added ) {
						return $rules;
					}
					if ( method_exists( $rules, 'has_rule' ) && $rules->has_rule( 'prerender', 'wppo-high-value-prerender' ) ) {
						return $rules;
					}
					$urls = $this->get_prerender_list_urls( $candidate_urls, array() );
					if ( empty( $urls ) ) {
						return $rules;
					}
					if ( function_exists( 'apply_filters' ) ) {
						/**
						 * Filters the high-value prerender list URLs before the rule is registered.
						 *
						 * @since 2.2.0
						 * @param string[] $urls Validated prerender URLs (home + capped RUM top URLs).
						 */
						$filtered = apply_filters( 'wppo_speculation_prerender_list_urls', $urls );
						if ( is_array( $filtered ) ) {
							$urls = $filtered;
						}
					}
					// Object-path dedupe: the WP_Speculation_Rules object
					// does not expose its list URLs for comparison, so
					// dedupe here is intra-list only; cross-contributor
					// duplicates on this path are prevented by the
					// has_rule()/static idempotency guards above.
					$urls = $this->normalize_prerender_urls( $urls, array() );
					if ( empty( $urls ) ) {
						return $rules;
					}
					$rule_args = array(
						'source'    => 'list',
						'urls'      => array_values( $urls ),
						'eagerness' => 'moderate',
					);
					if ( function_exists( 'apply_filters' ) ) {
						/**
						 * Filters the high-value prerender list rule before it is registered.
						 *
						 * @since 2.2.0
						 * @param array $rule_args The prerender list rule arguments.
						 */
						$filtered_rule = apply_filters( 'wppo_speculation_prerender_list_rule', $rule_args );
						if ( is_array( $filtered_rule ) ) {
							$rule_args = $filtered_rule;
						}
					}
					$validated = $this->validate_prerender_rule( $rule_args, array() );
					if ( null === $validated ) {
						return $rules;
					}
					$rules->add_rule(
						'prerender',
						'wppo-high-value-prerender',
						$validated
					);
					$this->speculation_prerender_object_added = true;
					return $rules;
				}

				if ( ! is_array( $rules ) ) {
					return $rules;
				}

				$urls = $this->get_prerender_list_urls( $candidate_urls, $rules );
				if ( empty( $urls ) ) {
					return $rules;
				}

				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters the high-value prerender list URLs before the rule is appended.
					 *
					 * @since 2.2.0
					 * @param string[] $urls Validated prerender URLs (home + capped RUM top URLs).
					 */
					$filtered = apply_filters( 'wppo_speculation_prerender_list_urls', $urls );
					if ( is_array( $filtered ) ) {
						$urls = $filtered;
					}
				}
				$urls = $this->normalize_prerender_urls( $urls, $rules );
				if ( empty( $urls ) ) {
					return $rules;
				}

				$rule = array(
					'source'    => 'list',
					'urls'      => array_values( $urls ),
					'eagerness' => 'moderate',
				);
				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters the high-value prerender list rule before it is appended.
					 *
					 * @since 2.2.0
					 * @param array $rule The prerender list rule.
					 */
					$filtered_rule = apply_filters( 'wppo_speculation_prerender_list_rule', $rule );
					if ( is_array( $filtered_rule ) ) {
						$rule = $filtered_rule;
					}
				}
				$validated = $this->validate_prerender_rule( $rule, $rules );
				if ( null === $validated ) {
					return $rules;
				}
				$rules[] = $validated;

				if ( function_exists( 'apply_filters' ) ) {
					/**
					 * Filters the speculation rules after the high-value prerender list rule is appended.
					 *
					 * Trusted-code-only: non-array returns fall back to the
					 * pre-filter rules and non-array entries are dropped
					 * ({@see validate_speculation_rules_output()}).
					 *
					 * @since 2.2.0
					 * @param array    $rules Updated rules.
					 * @param string[] $urls  Prerender list URLs that were appended.
					 */
					$filtered_rules = apply_filters( 'wppo_speculation_prerender_list_rules', $rules, $urls );
					return $this->validate_speculation_rules_output( $filtered_rules, $rules );
				}
				return $rules;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $rules;
			}
		}

		/**
		 * Append the high-value list rule to the `wp_speculation_rules` array.
		 *
		 * Runs on WP 6.8+ only (registered inside the `wp_get_speculation_rules`
		 * guard in {@see add_speculation_rules()}). Null/non-array config is
		 * returned untouched; speculation is never auto-enabled. Logged-in
		 * visitors are always excluded (input returned unchanged).
		 *
		 * Emits a single core `speculationrules` block contribution: the
		 * high-value/RUM list rule plus contextual rules — an eager
		 * prerender list rule for the home link on singular views
		 * ({@see get_singular_home_link_rule()}) and a first-post selector
		 * document rule on archive views
		 * ({@see get_archive_first_post_rule()}). When the opt-in
		 * `speculationPrerenderList` toggle is enabled, the safest
		 * high-value URLs are carved out of the generic prefetch list and
		 * emitted as a dedicated guarded prerender list rule via
		 * {@see wppo_register_speculation_rules()}. URLs are deduped across
		 * all emitted entries (and against pre-existing list rules) so no
		 * URL is speculated twice.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Carve out the opt-in guarded prerender list via wppo_register_speculation_rules().
		 *
		 * @param mixed $rules Speculation rules array from core.
		 * @return mixed Updated rules, or the input unchanged.
		 */
		public function filter_speculation_list_rules( $rules ) {
			if ( ! is_array( $rules ) ) {
				return $rules;
			}

			if ( empty( $this->get_options()['preload_settings']['enableSpeculationRules'] ) ) {
				return $rules;
			}

			if ( $this->is_speculation_suppressed_for_visitor() ) {
				return $rules;
			}

			$singular_rule = $this->get_singular_home_link_rule();
			$archive_rule  = $this->get_archive_first_post_rule();

			// Collect list-source URLs already present (e.g. an earlier
			// contributor) so this method never re-adds them anywhere.
			$pre_existing = $this->collect_speculation_list_urls( $rules );

			// The singular eager rule must not duplicate a URL already
			// covered by a pre-existing list rule; drop it when empty.
			// Normalization-aware so trailing-slash variants compare equal.
			if ( is_array( $singular_rule ) && ! empty( $singular_rule['urls'] ) && is_array( $singular_rule['urls'] ) ) {
				$singular_rule['urls'] = $this->diff_speculation_urls( $singular_rule['urls'], $pre_existing );
				if ( empty( $singular_rule['urls'] ) ) {
					$singular_rule = null;
				}
			}

			// URLs already covered by the contextual rules are removed from
			// the generic list so the single block carries no duplicates.
			$covered = array();
			if ( is_array( $singular_rule ) && ! empty( $singular_rule['urls'] ) && is_array( $singular_rule['urls'] ) ) {
				foreach ( $singular_rule['urls'] as $covered_url ) {
					if ( is_string( $covered_url ) && '' !== $covered_url ) {
						$covered[] = $covered_url;
					}
				}
			}

			$urls = $this->get_speculation_list_urls();

			/**
			 * Filters the high-value speculation list URLs.
			 *
			 * @since 2.0.0
			 * @param string[] $urls Validated list URLs (home + high-value + RUM winners).
			 */
			$urls = apply_filters( 'wppo_speculation_list_urls', $urls );
			if ( ! is_array( $urls ) ) {
				return $rules;
			}
			$urls = array_values( array_filter( $urls, 'is_string' ) );
			// Re-validate filter output: a filter may inject cart/checkout/
			// account, cross-host, or query-bearing URLs that must never
			// reach the emitted speculationrules block.
			$urls = array_values(
				array_filter(
					$urls,
					array( $this, 'is_speculation_list_url_valid' )
				)
			);
			if ( ! empty( $covered ) ) {
				$urls = $this->diff_speculation_urls( $urls, $covered );
			}
			// Exclude generic-list URLs already targeted by the archive
			// document rule's href_matches pattern, so a user-configured
			// high-value URL equal to the first post does not appear in both
			// the list rule and the document rule.
			if ( is_array( $archive_rule ) ) {
				$document_paths = $this->collect_speculation_document_paths( $archive_rule );
				if ( ! empty( $document_paths ) ) {
					$urls = array_values(
						array_filter(
							$urls,
							static function ( $url ) use ( $document_paths ) {
								$path = wp_parse_url( (string) $url, PHP_URL_PATH );
								if ( ! is_string( $path ) || '' === $path ) {
									return true;
								}
								$normalized = rtrim( untrailingslashit( $path ), '/' );
								if ( '' === $normalized ) {
									$normalized = '/';
								}
								foreach ( $document_paths as $document_path ) {
									if ( $normalized === $document_path ) {
										return false;
									}
								}
								return true;
							}
						)
					);
				}
			}
			// Dedupe against list-source URLs already present (e.g. an
			// earlier contributor) so this method never re-adds them.
			$urls = $this->dedupe_speculation_urls_against_rules( $urls, $rules );

			// Prerender where provably safe (issue #1237): when the opt-in
			// prerender list is enabled, carve its URLs out of the generic
			// prefetch list so the single block carries no duplicates; the
			// dedicated prerender rule is appended below via
			// wppo_register_speculation_rules(). Guards (toggle off,
			// logged-in, commerce/auth) yield an empty set, leaving the
			// generic list untouched (current behavior).
			$prerender_urls = $this->get_prerender_list_urls( $urls, $rules );
			if ( ! empty( $prerender_urls ) ) {
				$urls = $this->diff_speculation_urls( $urls, $prerender_urls );
			}

			$preload_settings = $this->get_options()['preload_settings'] ?? array();
			$eagerness        = $preload_settings['speculationEagerness'] ?? 'conservative';
			if ( class_exists( 'WP_Speculation_Rules' ) && method_exists( 'WP_Speculation_Rules', 'is_valid_eagerness' ) ) {
				if ( ! \WP_Speculation_Rules::is_valid_eagerness( $eagerness ) ) {
					$eagerness = 'conservative';
				}
			} elseif ( ! in_array( $eagerness, array( 'conservative', 'moderate', 'eager' ), true ) ) {
				$eagerness = 'conservative';
			}
			// Prerender-risk guardrail (issue #1183): never eager in
			// commerce/auth contexts — prefetch/document only.
			$eagerness = $this->maybe_cap_speculation_eagerness( $eagerness );

			$new_rules = array();
			if ( ! empty( $urls ) ) {
				$new_rules[] = array(
					'source'    => 'list',
					'urls'      => array_values( $urls ),
					'eagerness' => $eagerness,
				);
			}
			if ( is_array( $singular_rule ) ) {
				$new_rules[] = $singular_rule;
			}
			if ( is_array( $archive_rule ) ) {
				// Fill-gaps-only: never duplicate a document-source rule
				// already contributed by core or another plugin — at most one
				// document rule is emitted per request.
				if ( ! $this->has_document_source_rule( $rules ) ) {
					/**
					 * Filters the archive first-post document rule before it is appended.
					 *
					 * @since 2.0.0
					 * @param array $archive_rule The archive document rule.
					 */
					$archive_rule = apply_filters( 'wppo_speculation_document_rule', $archive_rule );
					if ( is_array( $archive_rule ) ) {
						$new_rules[] = $archive_rule;
					}
				}
			}

			if ( empty( $new_rules ) ) {
				return $rules;
			}

			foreach ( $new_rules as $new_rule ) {
				$rules[] = $new_rule;
			}

			/**
			 * Filters the speculation rules after the high-value list rule is appended.
			 *
			 * Trusted-code-only: a non-array return falls back to the
			 * pre-filter rules and non-array entries are dropped
			 * ({@see validate_speculation_rules_output()}).
			 *
			 * @since 2.0.0
			 * @param array    $rules Updated rules.
			 * @param string[] $urls  List URLs that were appended.
			 */
			$filtered_list_rules = apply_filters( 'wppo_speculation_list_rules', $rules, $urls );
			$rules               = $this->validate_speculation_rules_output( $filtered_list_rules, $rules );

			// Append the guarded prerender list rule (issue #1237, opt-in).
			// The helper re-validates, dedupes against the merged rules
			// (generic list included), and returns the input unchanged when
			// guards fail, so the single block stays duplicate-free.
			if ( ! empty( $prerender_urls ) ) {
				$rules = $this->wppo_register_speculation_rules( $rules, $prerender_urls );
			}

			return $rules;
		}

		/**
		 * Whether any rule in the set uses a document source.
		 *
		 * Shared by the fill-gaps-only document-rule gate in
		 * {@see filter_speculation_list_rules()} so source-checking logic
		 * lives in one place.
		 *
		 * @since 2.0.0
		 *
		 * @param array $rules Existing speculation rules.
		 * @return bool True when a document-source rule is present.
		 */
		private function has_document_source_rule( array $rules ): bool {
			foreach ( $rules as $rule ) {
				if ( is_array( $rule ) && 'document' === ( $rule['source'] ?? '' ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Collect URLs already covered by list-source rules.
		 *
		 * @since 2.0.0
		 *
		 * @param array $rules Existing speculation rules.
		 * @return string[] List-source URLs already present.
		 */
		private function collect_speculation_list_urls( array $rules ): array {
			$existing = array();
			foreach ( $rules as $rule ) {
				if ( ! is_array( $rule ) || ( $rule['source'] ?? '' ) !== 'list' ) {
					continue;
				}
				$rule_urls = $rule['urls'] ?? array();
				if ( ! is_array( $rule_urls ) ) {
					continue;
				}
				foreach ( $rule_urls as $existing_url ) {
					if ( is_string( $existing_url ) && '' !== $existing_url ) {
						$existing[] = $existing_url;
					}
				}
			}
			return $existing;
		}

		/**
		 * Remove URLs already covered by an existing list-source rule.
		 *
		 * Mirrors `AI_Adaptive::dedupe_against_existing_lists()` so this
		 * method's contribution and the priority-20 AI rule can never
		 * re-add the same URL (single block, no duplicates). Comparison is
		 * normalization-aware ({@see normalize_speculation_url()}): a RUM
		 * winner with a restored trailing slash and a raw high-value input
		 * without one count as the same URL.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Normalize before comparing.
		 *
		 * @param string[] $urls  Candidate list URLs.
		 * @param array    $rules Existing speculation rules.
		 * @return string[] Deduped URLs.
		 */
		private function dedupe_speculation_urls_against_rules( array $urls, array $rules ): array {
			$existing = $this->collect_speculation_list_urls( $rules );
			return $this->diff_speculation_urls( $urls, $existing );
		}

		/**
		 * Remove URLs present in an exclusion set (normalization-aware).
		 *
		 * Shared by the singular-rule, covered-URL, and prerender carve-outs
		 * in {@see filter_speculation_list_rules()} so trailing-slash
		 * variants compare equal everywhere. Output preserves the original
		 * (non-normalized) URL strings and order.
		 *
		 * @since 2.2.0
		 *
		 * @param array $urls     Candidate URLs (non-strings dropped).
		 * @param array $excluded URLs to remove.
		 * @return string[] Remaining URLs.
		 */
		private function diff_speculation_urls( array $urls, array $excluded ): array {
			$blocked = array();
			foreach ( $excluded as $excluded_url ) {
				if ( is_string( $excluded_url ) && '' !== $excluded_url ) {
					$blocked[ $this->normalize_speculation_url( $excluded_url ) ] = true;
				}
			}
			if ( empty( $blocked ) ) {
				$clean = array();
				foreach ( $urls as $url ) {
					if ( is_string( $url ) && '' !== $url ) {
						$clean[] = $url;
					}
				}
				return array_values( $clean );
			}
			$remaining = array();
			foreach ( $urls as $url ) {
				if ( ! is_string( $url ) || '' === $url ) {
					continue;
				}
				$key = $this->normalize_speculation_url( $url );
				if ( isset( $blocked[ $key ] ) ) {
					continue;
				}
				$remaining[]     = $url;
				$blocked[ $key ] = true;
			}
			return array_values( $remaining );
		}

		/**
		 * Collect normalized target paths from a document-source rule.
		 *
		 * Reduces each `href_matches` pattern (e.g. `/first-post/*`) to its
		 * path prefix so generic-list URLs can be compared on the same basis.
		 *
		 * @since 2.0.0
		 *
		 * @param array $document_rule Document-source rule.
		 * @return string[] Normalized paths (e.g. `/first-post`).
		 */
		private function collect_speculation_document_paths( array $document_rule ): array {
			$paths = array();
			$where = $document_rule['where'] ?? null;
			if ( ! is_array( $where ) ) {
				return $paths;
			}
			$and = $where['and'] ?? array();
			if ( ! is_array( $and ) ) {
				return $paths;
			}
			foreach ( $and as $condition ) {
				if ( ! is_array( $condition ) || empty( $condition['href_matches'] ) || ! is_string( $condition['href_matches'] ) ) {
					continue;
				}
				$trimmed = rtrim( $condition['href_matches'], '*' );
				$trimmed = rtrim( untrailingslashit( $trimmed ), '/' );
				$paths[] = '' === $trimmed ? '/' : $trimmed;
			}
			return $paths;
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
		 * When `wp_get_speculation_rules_default_configuration()` exists (WP 7.1+)
		 * its effective defaults are also considered: if the host pinned a different
		 * default (e.g. `moderate`) the function will reflect it, and this helper
		 * treats that as an override so the plugin's `conservative` pin does not
		 * fight the host. The `wp_speculation_rules_configuration` filter (used in
		 * {@see filter_speculation_rules_configuration()}) wins over the host in
		 * any case (documented precedence). Validation uses
		 * `WP_Speculation_Rules::is_valid_mode()` / `is_valid_eagerness()` when available.
		 *
		 * @since 1.9.0
		 * @since 2.0.0 Honor `wp_get_speculation_rules_default_configuration()` when available.
		 *
		 * @param string $name Override name, e.g. 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS'.
		 * @return string|null The override value, or null when neither the constant nor the environment variable is set to a valid value.
		 */
		private function get_speculation_default_override( string $name ): ?string {
			// Prefer WP 7.1 core helper when available — it already resolves host overrides.
			// We still validate via WP_Speculation_Rules when available, with allowlist fallback.
			if ( function_exists( 'wp_get_speculation_rules_default_configuration' ) ) {
				$defaults = wp_get_speculation_rules_default_configuration();
				if ( is_array( $defaults ) ) {
					$key = null;
					if ( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' === $name ) {
						$key = 'mode';
					} elseif ( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' === $name ) {
						$key = 'eagerness';
					}
					if ( null !== $key && isset( $defaults[ $key ] ) && is_string( $defaults[ $key ] ) && '' !== $defaults[ $key ] ) {
						$candidate = $defaults[ $key ];
						$is_valid  = true;
						if ( class_exists( 'WP_Speculation_Rules' ) ) {
							if ( 'mode' === $key ) {
								if ( method_exists( 'WP_Speculation_Rules', 'is_valid_mode' ) ) {
									$is_valid = \WP_Speculation_Rules::is_valid_mode( $candidate );
								} else {
									$is_valid = in_array( $candidate, array( 'prefetch', 'prerender' ), true );
								}
							} elseif ( 'eagerness' === $key ) {
								if ( method_exists( 'WP_Speculation_Rules', 'is_valid_eagerness' ) ) {
									$is_valid = \WP_Speculation_Rules::is_valid_eagerness( $candidate );
								} else {
									$is_valid = in_array( $candidate, array( 'conservative', 'moderate', 'eager' ), true );
								}
							}
						} elseif ( 'mode' === $key ) {
							$is_valid = in_array( $candidate, array( 'prefetch', 'prerender' ), true );
						} else {
							$is_valid = in_array( $candidate, array( 'conservative', 'moderate', 'eager' ), true );
						}
						if ( $is_valid ) {
							// Core hardcoded defaults are prefetch and conservative without host override.
							// Only treat as override when effective value differs from those defaults,
							// so vanilla 7.1 install does not look pinned when it is not.
							$hardcoded_default = ( 'mode' === $key ) ? 'prefetch' : 'conservative';
							if ( $candidate !== $hardcoded_default ) {
								return $candidate;
							}
							// If effective value is the hardcoded default, fall through to
							// constant/env check to distinguish "host explicitly pinned to conservative"
							// from "no host pin" — the constant/env path below will still detect an
							// explicit pin even when it equals the hardcoded default.
						}
					}
				}
			}

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

			// Validate via WP_Speculation_Rules when available, else allowlist.
			if ( class_exists( 'WP_Speculation_Rules' ) ) {
				if ( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' === $name && method_exists( 'WP_Speculation_Rules', 'is_valid_mode' ) ) {
					if ( ! \WP_Speculation_Rules::is_valid_mode( $value ) ) {
						return null;
					}
				} elseif ( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' === $name && method_exists( 'WP_Speculation_Rules', 'is_valid_eagerness' ) ) {
					if ( ! \WP_Speculation_Rules::is_valid_eagerness( $value ) ) {
						return null;
					}
				} elseif ( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' === $name ) {
					if ( ! in_array( $value, array( 'prefetch', 'prerender' ), true ) ) {
						return null;
					}
				} elseif ( 'WP_SPECULATIVE_LOADING_DEFAULT_EAGERNESS' === $name ) {
					if ( ! in_array( $value, array( 'conservative', 'moderate', 'eager' ), true ) ) {
						return null;
					}
				}
			} elseif ( 'WP_SPECULATIVE_LOADING_DEFAULT_MODE' === $name ) {
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
		 * Get handles to exclude from optimization via the CVE guard filter.
		 *
		 * Filter-only, S scope (no persistence, no cron). Default empty (disabled).
		 * When a CVE is known for a handle, site operators can auto-exclude it via
		 * `wppo_cve_guard_handles` (alias `wppo_cve_excluded_handles` for BC) without
		 * touching `wppo_settings`. Merged into minify/defer/delay exclude lists with
		 * `array_unique`; respects the existing `litespeed_can_optm` gate (optimization
		 * disabled there anyway).
		 *
		 * @since 2.0.0
		 * @return string[] List of handle strings to exclude.
		 */
		private function get_cve_guard_handles(): array {
			$handles = apply_filters( 'wppo_cve_guard_handles', array() );
			$handles = apply_filters( 'wppo_cve_excluded_handles', $handles );
			if ( ! is_array( $handles ) ) {
				return array();
			}
			$handles = array_filter( $handles, 'is_string' );
			$handles = array_map( 'trim', $handles );
			$handles = array_filter( $handles );
			return array_values( array_unique( $handles ) );
		}

		/**
		 * Whether a style handle is a core block asset owned by core's on-demand loader.
		 *
		 * On WP 6.9+ with separate core block assets active, the combined
		 * stylesheet (`wp-block-library`) and every per-block stylesheet
		 * (`wp-block-cover`, `wp-block-group`, ...) are loaded on demand for the
		 * blocks actually present on the page. On pre-6.9 cores the 6.8 classic
		 * on-demand world (`should_load_block_assets_on_demand` /
		 * `wp_should_load_block_assets_on_demand()`, see
		 * {@see is_classic_block_assets_on_demand_active()}) owns the same
		 * `wp-block-*` family when the operator opted in. Rewriting their `src` (minify) or
		 * folding them into the combined file would re-monolithize what core
		 * ships conditionally, so the minify path skips them in either mode —
		 * mirroring `Cache::is_core_block_asset()`.
		 *
		 * @since 2.0.0
		 *
		 * @param string $handle The registered style handle.
		 * @return bool True when core owns the handle under on-demand mode.
		 */
		private function is_core_block_asset_skipped( $handle ): bool {
			if ( $this->is_core_separate_block_assets_active() ) {
				try {
					return str_starts_with( (string) $handle, 'wp-block-' );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			}
			if ( $this->is_classic_block_assets_on_demand_active() ) {
				try {
					return str_starts_with( (string) $handle, 'wp-block-' );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			}
			return false;
		}

		/**
		 * Whether WP 6.8 classic on-demand block-asset loading is active.
		 *
		 * Covers the pre-6.9 world (`should_load_block_assets_on_demand` filter /
		 * `wp_should_load_block_assets_on_demand()`) that
		 * {@see is_core_separate_block_assets_active()} does not model. Positive
		 * runtime evidence only: the core function wins when present, otherwise
		 * a `has_filter`-guarded `should_load_block_assets_on_demand` read
		 * (which reflects the opt-in registered by
		 * {@see Hook_Registry::register_block_assets_filters()}). The combined monolith escape
		 * hatch (`loadAllCoreBlockAssets` on / `blockAssetsOnDemand` off, see
		 * {@see is_hidden_block_asset_omission_enabled()}) forces false so
		 * operators who opted out keep the legacy monolith. Fail-open: any
		 * throwable, missing API, or absent evidence returns false (legacy path).
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when classic on-demand block assets are active.
		 */
		private function is_classic_block_assets_on_demand_active(): bool {
			try {
				// Classic on-demand is a pre-6.9 world: on 6.9+ (or when the
				// version is unknown, which assumes newest per codebase
				// convention) the separate-assets path owns `wp-block-*`.
				// Gating on version first also keeps this probe-free on 6.9+
				// so exact-count `function_exists` unit expectations stay stable.
				// Version floor lives in Wp_Version (REF-010, $GLOBALS-only read:
				// unknown still assumes newest, no get_bloginfo() fallback).
				if ( Wp_Version::is_global_at_least( '6.9-alpha' ) ) {
					return false;
				}
				if ( ! $this->is_hidden_block_asset_omission_enabled() ) {
					return false;
				}
				if ( function_exists( 'wp_should_load_block_assets_on_demand' ) ) {
					return (bool) wp_should_load_block_assets_on_demand();
				}
				if ( function_exists( 'has_filter' ) && has_filter( 'should_load_block_assets_on_demand' ) ) {
					return (bool) apply_filters( 'should_load_block_assets_on_demand', false );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether WP 6.9+ core reports separate (on-demand) block-asset loading.
		 *
		 * Shared predicate for {@see is_core_block_asset_skipped()} and
		 * {@see is_core_block_hoisting_active()} so the 6.9-alpha floor +
		 * API-exists + fail-open check cannot drift between the two call sites.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when core loads separate core block assets on demand.
		 */
		private function is_core_separate_block_assets_active(): bool {
			// Version floor lives in Wp_Version (REF-010, $GLOBALS-only
			// read: unknown assumes newest, matching the historic isset()
			// spelling). Version-first keeps this probe-free on pre-6.9.
			if ( ! Wp_Version::is_global_at_least( '6.9-alpha' ) ) {
				return false;
			}
			if ( ! function_exists( 'wp_should_load_separate_core_block_assets' ) ) {
				return false;
			}
			try {
				return (bool) wp_should_load_separate_core_block_assets();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the hidden-block-asset omission pass may run.
		 *
		 * Mirrors the opt-out state honored by
		 * {@see Hook_Registry::register_block_assets_filters()}: the omission pass only runs
		 * when on-demand block assets are enabled (`blockAssetsOnDemand` on) and
		 * the combined monolith is not forced (`loadAllCoreBlockAssets` off).
		 * Users who explicitly disabled on-demand assets keep the legacy
		 * monolith untouched.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when hidden block assets may be omitted.
		 */
		public function is_hidden_block_asset_omission_enabled(): bool {
			return ! empty( $this->get_options()['file_optimisation']['blockAssetsOnDemand'] )
			&& empty( $this->get_options()['file_optimisation']['loadAllCoreBlockAssets'] );
		}

		/**
		 * Whether core 6.9+ block-asset hoisting is active on this request.
		 *
		 * True only when {@see is_core_separate_block_assets_active()} holds AND
		 * the template-enhancement buffer API exists. Callers use this to yield
		 * to core's on-demand hoisting instead of duplicating it (e.g.
		 * {@see omit_hidden_block_assets()} returns early when the
		 * template-enhancement buffer exists). Fail-open: any throwable or
		 * missing API returns false (legacy path unchanged).
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when core owns on-demand block-asset hoisting.
		 */
		private function is_core_block_hoisting_active(): bool {
			if ( ! function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
				return false;
			}
			return $this->is_core_separate_block_assets_active();
		}

		/**
		 * Whether a queued style handle is a core per-block stylesheet.
		 *
		 * The `wp-block-*` prefix alone is not proof of core ownership:
		 * third-party or theme stylesheets may share the prefix without a 1:1
		 * block-type mapping. A handle only counts as a core per-block asset
		 * when its registered `src` points at core's block styles
		 * (`wp-includes` + `block-library` or `/blocks/`). Fail-open: an
		 * unregistered handle or an unverifiable `src` returns false (keep the
		 * asset — degrade to unoptimized, never unstyled).
		 *
		 * @since 2.2.0
		 *
		 * @param string $handle Queued style handle e.g. 'wp-block-cover'.
		 * @return bool True when the handle is verifiably a core per-block asset.
		 */
		private function is_core_per_block_style_handle( $handle ): bool {
			try {
				global $wp_styles;
				if ( ! is_object( $wp_styles ) || ! isset( $wp_styles->registered[ $handle ] ) ) {
					return false;
				}
				$src = (string) ( $wp_styles->registered[ $handle ]->src ?? '' );
				if ( '' === $src ) {
					return false;
				}
				if ( false !== strpos( $src, 'block-library' ) ) {
					// Require a core path: a plugin/theme file merely
					// containing 'block-library' in its path (e.g.
					// /plugins/my-block-library/style.css) is not a core
					// asset and must never be omitted.
					return false !== strpos( $src, 'wp-includes' );
				}
				return false !== strpos( $src, 'wp-includes' ) && false !== strpos( $src, '/blocks/' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether singular post content references block sources outside itself.
		 *
		 * Reusable blocks (`wp:block` refs), patterns (`wp:pattern`),
		 * template parts (`wp:template-part`), shortcode blocks
		 * (`wp:shortcode`, generic `[...]` shortcodes, `do_blocks` output),
		 * and similar markers render stylesheets for blocks absent from the
		 * literal post content, so type-absence cannot prove the asset is
		 * unused. Fail-open: any throwable (or a detected marker) reports
		 * unresolvable (the caller bails and keeps every asset).
		 *
		 * @since 2.2.0
		 *
		 * @param string $content Singular post content to check.
		 * @return bool True when the content references out-of-content block sources.
		 */
		private function content_has_unresolvable_block_sources( $content ): bool {
			try {
				$content = (string) $content;
				if ( '' === $content ) {
					return false;
				}
				if ( false !== strpos( $content, '<!-- wp:block ' )
					|| false !== strpos( $content, '<!-- wp:block/' )
					|| false !== strpos( $content, 'wp:pattern' )
					|| false !== strpos( $content, 'wp:template-part' )
					|| false !== strpos( $content, 'wp:shortcode' )
					|| false !== strpos( $content, 'do_blocks' ) ) {
					return true;
				}
				// Generic brackets (e.g. '[hello]', '[2024]') are not proof of
				// a shortcode: only bail when a registered shortcode tag is
				// present. Unregistered/plain-text brackets must not disable
				// the optimization. Fail-open: when the Shortcode API is
				// unavailable the generic match is kept as the bail signal.
				if ( false === strpos( $content, '[' ) ) {
					return false;
				}
				if ( function_exists( 'get_shortcode_regex' ) ) {
					try {
						$pattern = (string) get_shortcode_regex();
						if ( '' === $pattern ) {
							return false;
						}
						if ( 1 !== preg_match_all( '/' . $pattern . '/s', $content, $matches ) || empty( $matches[2] ) ) {
							return false;
						}
						if ( function_exists( 'shortcode_exists' ) ) {
							foreach ( $matches[2] as $tag ) {
								if ( '' !== (string) $tag && shortcode_exists( (string) $tag ) ) {
									return true;
								}
							}
							return false;
						}
						return true;
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				return 1 === preg_match( '/\[[a-zA-Z0-9_-]+(?:\s+[^\]]*)?\/?\]/', $content );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a block name is a registered block type.
		 *
		 * A `wp-block-<slug>` handle does not guarantee a matching
		 * `core/<slug>` block type exists: core-registered handles without a
		 * 1:1 block-type mapping never match {@see Util::content_has_block()}
		 * and would otherwise always be omitted whenever queued. Fail-open in
		 * the omission direction: when the registry API is unavailable or
		 * throws, the type is treated as known so the legacy omission path is
		 * unchanged; only a positive "not registered" answer keeps the asset.
		 *
		 * @since 2.2.0
		 *
		 * @param string $block_name Block name e.g. 'core/cover'.
		 * @return bool True when the type is (or may be) registered.
		 */
		private function is_registered_block_type( $block_name ): bool {
			try {
				if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
					return true;
				}
				if ( ! method_exists( 'WP_Block_Type_Registry', 'get_instance' ) ) {
					return true;
				}
				$registry = \WP_Block_Type_Registry::get_instance(); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
				if ( ! is_object( $registry ) ) {
					return true;
				}
				if ( method_exists( $registry, 'is_registered' ) ) {
					return (bool) $registry->is_registered( (string) $block_name );
				}
				if ( method_exists( $registry, 'get_registered' ) ) {
					return null !== $registry->get_registered( (string) $block_name );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether core 6.9+ wants an empty block's asset kept via its canonical filter.
		 *
		 * Probes core's `enqueue_empty_block_content_assets` filter
		 * (`wp-includes/class-wp-block.php`, `@since 6.9.0`; semantics:
		 * `$enqueue=false` = drop empty-block assets, return `true` = keep
		 * them). See the WP 6.9 frontend-performance field guide. Fail-open:
		 * any doubt returns true (keep the asset — degrade to unoptimized,
		 * never unstyled). On core below 6.9 returns false (no keep-signal)
		 * so the caller falls through to legacy behavior byte-for-byte.
		 *
		 * @since 2.2.0
		 *
		 * @param string $block_name Block name e.g. 'core/cover'.
		 * @return bool True when core wants the asset kept.
		 */
		private function should_keep_empty_block_asset_via_core_filter( $block_name ): bool {
			try {
				// Version floor lives in Wp_Version (REF-010, $GLOBALS-only
				// read: unknown assumes newest, matching the historic isset()
				// spelling verbatim).
				if ( ! Wp_Version::is_global_at_least( '6.9-alpha' ) ) {
					return false;
				}
				if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) ) {
					return true;
				}
				if ( ! has_filter( 'enqueue_empty_block_content_assets' ) ) {
					return false;
				}
				try {
					$enqueue = apply_filters( 'enqueue_empty_block_content_assets', false, (string) $block_name );
				} catch ( \Throwable $e ) {
					unset( $e );
					return true;
				}
				return (bool) $enqueue;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a queued core block asset should be omitted as hidden.
		 *
		 * A block asset counts as hidden when its block type is absent from the
		 * given singular post content (type-absence definition: blocks present in
		 * markup but never rendered still ship their per-block stylesheet, so
		 * the stylesheet is unused by definition). Hidden assets are omitted by
		 * default; a per-block re-enable is available via the
		 * `wppo_allow_hidden_block_asset` filter (return truthy to keep the
		 * asset for that block). On WP 6.9+ core's canonical
		 * `enqueue_empty_block_content_assets` filter is honored first
		 * (return `true` to keep the asset even though empty); either filter
		 * keeping the asset wins. The filters are only applied when a
		 * listener is registered (`has_filter()` guard). Fail-open: missing
		 * content, missing APIs, an unregistered block type, or any throwable
		 * returns false (keep the asset — degrade to unoptimized, never
		 * fatal, never unstyled).
		 * Callers may pass a shared `$presence` map so the content parse in
		 * {@see Util::content_has_block()} runs at most once per block type per
		 * pass instead of once per queued handle.
		 *
		 * @since 2.2.0
		 *
		 * @param string $block_name Block name e.g. 'core/cover'.
		 * @param string $handle     Queued style handle e.g. 'wp-block-cover'.
		 * @param string $content    Singular post content to check against.
		 * @param array  $presence   Optional shared presence cache (block_name => bool), updated by reference.
		 * @return bool True when the asset should be dequeued.
		 */
		private function should_omit_hidden_block_asset( $block_name, $handle, $content, &$presence = array() ): bool {
			try {
				if ( '' === (string) $block_name || '' === (string) $handle ) {
					return false;
				}
				if ( '' === (string) $content ) {
					return false;
				}
				if ( ! $this->is_registered_block_type( (string) $block_name ) ) {
					return false;
				}
				$key = (string) $block_name;
				if ( ! array_key_exists( $key, (array) $presence ) ) {
					$presence[ $key ] = Util::content_has_block( (string) $content, (string) $block_name );
				}
				if ( ! empty( $presence[ $key ] ) ) {
					return false;
				}
				// Core parity first: on WP 6.9+ defer to core's canonical
				// empty-block filter (wp-includes/class-wp-block.php); a
				// filtered-in handle is kept even though the block is hidden.
				if ( $this->should_keep_empty_block_asset_via_core_filter( (string) $block_name ) ) {
					return false;
				}
				$allowed = false;
				if ( function_exists( 'has_filter' ) && has_filter( 'wppo_allow_hidden_block_asset' ) ) {
					try {
						$allowed = apply_filters( 'wppo_allow_hidden_block_asset', false, (string) $block_name, (string) $handle );
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				return empty( $allowed );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Dequeue per-block stylesheets for blocks absent from the content.
		 *
		 * Singular views only: a single `post_content` is authoritative only
		 * there. Archives, blog-home, search, and other non-singular views bail
		 * out immediately so stylesheets needed by other posts in the loop are
		 * never stripped. Block themes bail out as well: header/footer
		 * template parts, site chrome, and widgets render outside
		 * `post_content`, so type-absence cannot prove the asset is unused
		 * there. Singular gating alone does not protect composite
		 * sources: reusable blocks, patterns, template parts, widgets, and
		 * shortcode/`do_blocks`-injected blocks can render stylesheets for
		 * blocks absent from `post_content`, so the pass additionally bails
		 * out entirely when the content references such out-of-content
		 * sources (see {@see content_has_unresolvable_block_sources()}).
		 * Remaining outside-`post_content` rendering on classic themes is a
		 * known limitation — use the `wppo_allow_hidden_block_asset` filter
		 * to keep those assets.
		 *
		 * Runs on `wp_enqueue_scripts` at PHP_INT_MAX - 2: after core enqueues
		 * but before `minify_queued_styles()` and `Cache::combine_css()`, so
		 * omitted handles never enter the minify/combine pipelines and the
		 * cascade order of the surviving stylesheets is preserved (dequeue
		 * only — nothing is re-enqueued or folded into a monolith).
		 *
		 * Defers to core 6.9 hoisting: when {@see is_core_block_hoisting_active()}
		 * is true and the template-enhancement buffer exists
		 * (`wp_should_output_buffer_template_for_enhancement()`), this returns
		 * immediately and core's conditional loading owns the output. The pass
		 * is also skipped when the on-demand opt-out is active
		 * ({@see is_hidden_block_asset_omission_enabled()}). Otherwise the
		 * legacy omission path runs unchanged.
		 *
		 * @since 2.2.0
		 *
		 * @return void
		 */
		public function omit_hidden_block_assets(): void {
			try {
				if ( ! $this->is_hidden_block_asset_omission_enabled() ) {
					return;
				}

				// Only a singular post_content is authoritative. On archives,
				// home, search, and other composite views the queued stylesheet
				// may belong to any post in the loop, so never omit there.
				// Fail-open: when singular cannot be verified (missing API),
				// keep every asset.
				if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
					return;
				}

				// Fail-open for composite rendering contexts: on block themes
				// the header/footer template parts and site chrome never live
				// in post_content, so a queued gallery/cover stylesheet needed
				// by the chrome would be misclassified as hidden. Bail out
				// entirely (keep every asset) when a block theme is active.
				if ( function_exists( 'wp_is_block_theme' ) ) {
					try {
						if ( wp_is_block_theme() ) {
							return;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return;
					}
				}

				// Defer to core instead of duplicating it: when the 6.9
				// template-enhancement buffer exists, core hoists exactly the
				// block assets the template needs.
				if ( $this->is_core_block_hoisting_active() && function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
					try {
						if ( wp_should_output_buffer_template_for_enhancement() ) {
							return;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				global $wp_styles;
				if ( ! is_object( $wp_styles ) || empty( $wp_styles->queue ) || ! is_array( $wp_styles->queue ) ) {
					return;
				}
				if ( ! function_exists( 'wp_dequeue_style' ) ) {
					return;
				}

				// Fetch the singular post content once for the whole pass
				// (fail-open: empty/unresolvable content keeps every asset).
				$content = '';
				if ( function_exists( 'get_the_ID' ) && function_exists( 'get_post_field' ) ) {
					try {
						$post_id = get_the_ID();
						if ( ! empty( $post_id ) ) {
							$content = (string) get_post_field( 'post_content', $post_id );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return;
					}
				}
				if ( '' === $content ) {
					return;
				}

				// Fail-open: reusable blocks, patterns, template parts, and
				// shortcode-injected blocks render stylesheets for blocks
				// absent from the literal post content, so type-absence cannot
				// prove the asset is unused — keep everything on such pages.
				if ( $this->content_has_unresolvable_block_sources( $content ) ) {
					return;
				}

				// Memoize the omit decision per queued handle so the content
				// parse runs at most once per block type per pass. $presence
				// caches Util::content_has_block() results by block name
				// (shared across handles); $decisions stays keyed by handle
				// because the wppo_allow_hidden_block_asset filter takes
				// ($block_name, $handle) and must run per queued handle (the
				// core enqueue_empty_block_content_assets filter takes only
				// $block_name but shares the same per-handle memoization).
				$decisions = array();
				$presence  = array();
				foreach ( $wp_styles->queue as $handle ) {
					if ( ! is_string( $handle ) || 0 !== strpos( $handle, 'wp-block-' ) ) {
						continue;
					}
					// The combined monolith family is owned by core hoisting
					// (and by the combine/minify skips); only per-block handles
					// are candidates for hidden-block omission.
					$slug = substr( $handle, strlen( 'wp-block-' ) );
					if ( '' === $slug || 0 === strpos( $slug, 'library' ) ) {
						continue;
					}
					// Only omit handles verifiably registered as core block
					// styles; third-party/theme handles sharing the prefix are
					// never candidates.
					if ( ! $this->is_core_per_block_style_handle( $handle ) ) {
						continue;
					}
					$block_name = 'core/' . $slug;
					if ( ! array_key_exists( $handle, $decisions ) ) {
						$decisions[ $handle ] = $this->should_omit_hidden_block_asset( $block_name, $handle, $content, $presence );
					}
					if ( $decisions[ $handle ] ) {
						try {
							wp_dequeue_style( $handle );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
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
			$this->minify_policy()->minify_queued_styles();
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
			return $this->minify_policy()->minify_css( $tag, $handle, $href );
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
			return $this->minify_policy()->minify_js( $tag, $handle, $src );
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
		 * Upgrade auto-purge status for the SPA (issue #1276).
		 *
		 * Returns the last derived-cache purge record plus a safe-mode
		 * preview URL (`?wppo_nocache=1`, bypassing minify). Class and
		 * method-exists guarded + fail-open so localisation never fatals.
		 * Localised (not lazy-fetched) intentionally: the banner needs the
		 * seed on first paint and the SPA refreshes via the read-only
		 * upgrade_purge_status endpoint after cache-clearing actions; the
		 * cost is a single non-autoloaded option read on admin pages.
		 *
		 * @since 2.2.0
		 * @return array{last_purge:array{reason:string,time:int},safe_preview_url:string}
		 */
		private function get_upgrade_purge_for_client(): array {
			$fallback = array(
				'last_purge'       => array(
					'reason' => '',
					'time'   => 0,
				),
				'safe_preview_url' => '',
			);
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
					return $fallback;
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher', 'get_last_purge' ) ) {
					$last = Builder_Purge_Watcher::get_last_purge();
					if ( is_array( $last ) ) {
						$fallback['last_purge'] = array(
							'reason' => isset( $last['reason'] ) && is_string( $last['reason'] ) ? $last['reason'] : '',
							'time'   => isset( $last['time'] ) ? (int) $last['time'] : 0,
						);
					}
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher', 'get_safe_preview_url' ) ) {
					$url                          = Builder_Purge_Watcher::get_safe_preview_url();
					$fallback['safe_preview_url'] = is_string( $url ) ? $url : '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $fallback;
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

			if ( false !== $resolved && 0 === strpos( $resolved, (string) WPPO_PLUGIN_PATH ) ) {
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
				'translations'  => array(
					'cacheCleared' => __( 'Cache cleared successfully.', 'performance-optimisation' ),
					'clearFailed'  => __( 'Failed to clear cache.', 'performance-optimisation' ),
					'clearRetry'   => __( 'Failed to clear cache. Please try again.', 'performance-optimisation' ),
					'pageCleared'  => __( 'Page cache cleared successfully.', 'performance-optimisation' ),
					'pageFailed'   => __( 'Failed to clear page cache.', 'performance-optimisation' ),
					'pageRetry'    => __( 'Failed to clear page cache. Please try again.', 'performance-optimisation' ),
					'dismiss'      => __( 'Dismiss', 'performance-optimisation' ),
				),
			);

			wp_add_inline_script(
				'wppo-admin-bar-script',
				'window.wppoObject = ' . wp_json_encode( $data, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP ) . ';',
				'before'
			);

			// Best-effort only: the admin-bar bundle declares no wp-i18n
			// dependency, so window.wp.i18n is typically absent on the
			// frontend. The inline wppoObject.translations map above is the
			// authoritative source; src/main.js consults it first.
			wp_set_script_translations( 'wppo-admin-bar-script', 'performance-optimisation' );
		}
	}
}
