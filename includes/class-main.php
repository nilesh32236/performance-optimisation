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
		 * Per-request cache of precompiled delay-pattern alternations.
		 *
		 * Keyed by md5 of the serialized pattern list; avoids rebuilding a
		 * preg_quote()+preg_match() regex per pattern per script tag on the
		 * frontend hot path (O(tags x patterns) compiles). Bounded (200
		 * entries, FIFO eviction) so long-lived processes cycling multisite
		 * blogs with blog-dependent filters cannot accumulate entries
		 * without bound.
		 *
		 * @since 2.0.0
		 * @var array<string, string>
		 */
		private static array $delay_pattern_regex_cache = array();

		/**
		 * Per-request memo for is_delay_excluded_context().
		 *
		 * Null = not computed yet; computed once per request and reused
		 * for every script tag via add_defer_attribute(). The request
		 * signature below guards against reusing a stale verdict in
		 * long-running processes (Action Scheduler, WP-CLI) where the
		 * request superglobals change between logical requests.
		 *
		 * @since 2.0.0
		 * @var bool|null
		 */
		private static ?bool $delay_excluded_context_memo = null;

		/**
		 * Request signature the delay-context memo was computed for.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private static string $delay_excluded_context_memo_sig = '';

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
		 * @var   array
		 * @since 1.0.0
		 */
		private array $options = array();

		/**
		 * Timestamp (microtime) when the front-end template render started.
		 *
		 * @var   float
		 * @since 1.9.0
		 */
		private float $server_timing_template_start = 0.0;

		/**
		 * Whether the used-CSS buffer pipeline ran this request.
		 *
		 * Nesting balance guard (issue #881): the WP 6.9+ enhancement filter
		 * ({@see process_used_css_only()}) and the legacy fallback buffer
		 * ({@see process_used_css_capture()}) share the used-CSS pipeline;
		 * when both are registered a mid-request flip of
		 * `wp_should_output_buffer_template_for_enhancement` could otherwise
		 * run it twice. One-shot per request.
		 *
		 * @var   bool
		 * @since 2.0.0
		 */
		private bool $used_css_buffer_enhanced = false;

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
		 * Create the static-cache collaborator with a test seam.
		 *
		 * Applies the `wppo_cache_instance` filter so tests (and advanced
		 * integrations) can stub the Cache collaborator; defaults to
		 * `new Cache( $options )` preserving current behavior.
		 *
		 * @since 2.2.0
		 * @param array $options Plugin options passed to Cache.
		 * @return mixed Cache instance (or filtered stub).
		 */
		public static function create_cache( array $options ) {
			$cache = new Cache( $options );
			/**
			 * Filter the Cache collaborator instance.
			 *
			 * @since 2.2.0
			 * @param mixed $cache   Cache instance.
			 * @param array $options Plugin options.
			 */
			return apply_filters( 'wppo_cache_instance', $cache, $options );
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
			Util::register_settings_cache_hooks();

			// Canonical defaults live in Util::get_default_settings() (single source of
			// truth, #901). Previously duplicated here; kept in sync via this call.
			$defaults      = Util::get_default_settings();
			$stored        = Util::get_settings();
			$this->options = ! empty( $stored ) ? $stored : $defaults;

			// WooCommerce safe mode (issue #1383): defensive in-memory parity
			// with Util::get_default_settings() (wooSafeMode defaults to on).
			// No behavioral effect on its own — all safe-mode reads go through
			// Util::is_woo_safe_mode_enabled() (absent=ON, fresh get_settings())
			// and Cache keeps its own options copy; this only keeps direct
			// $this->options['cache_settings'] reads consistent. In-memory only
			// here (no front-end DB write); persisted via update_settings/REST.
			// Multisite-safe: per-site wppo_settings only.
			if ( ! isset( $this->options['cache_settings'] ) || ! is_array( $this->options['cache_settings'] ) ) {
				$this->options['cache_settings'] = array();
			}
			if ( ! isset( $this->options['cache_settings']['wooSafeMode'] ) ) {
				$this->options['cache_settings']['wooSafeMode'] = true;
			}

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
			if ( ! isset( $this->options['image_optimisation']['avifFirst'] ) ) {
				$this->options['image_optimisation']['avifFirst'] = true;
			}
			if ( ! isset( $this->options['image_optimisation']['smartQuality'] ) ) {
				$this->options['image_optimisation']['smartQuality'] = true;
			}
			if ( ! isset( $this->options['image_optimisation']['skipSmallThresholdBytes'] ) ) {
				$this->options['image_optimisation']['skipSmallThresholdBytes'] = 5120;
			}
			if ( ! isset( $this->options['image_optimisation']['discardOversizedSibling'] ) ) {
				$this->options['image_optimisation']['discardOversizedSibling'] = true;
			}
			if ( ! isset( $this->options['image_optimisation']['lcpHeroPreload'] ) ) {
				$this->options['image_optimisation']['lcpHeroPreload'] = true;
			}
			if ( ! isset( $this->options['image_optimisation']['lcp_guardrails'] ) ) {
				$this->options['image_optimisation']['lcp_guardrails'] = true;
			}
			if ( ! isset( $this->options['image_optimisation']['lcp_first_n'] ) ) {
				$this->options['image_optimisation']['lcp_first_n'] = 3;
			}
			if ( ! isset( $this->options['image_optimisation']['autoAltText'] ) ) {
				$this->options['image_optimisation']['autoAltText'] = false;
			}
			if ( ! isset( $this->options['image_optimisation']['maxLongestEdgePx'] ) ) {
				$this->options['image_optimisation']['maxLongestEdgePx'] = 2560;
			}
			if ( ! isset( $this->options['image_optimisation']['lazyRenderBelowFold'] ) ) {
				$this->options['image_optimisation']['lazyRenderBelowFold'] = false;
			}
			if ( ! isset( $this->options['image_optimisation']['lazyRenderExcludeBuilders'] ) ) {
				$this->options['image_optimisation']['lazyRenderExcludeBuilders'] = true;
			}
			// Comment-image hardening (issue #1271): additive key, defaults to
			// on so hostile comment markup (img onerror, picture source,
			// inline on* handlers, scriptable URLs) is stripped before
			// next-gen/lazy rewriting. In-memory only here (no front-end DB
			// write); persisted via maybe_migrate_comment_image_hardening().
			if ( ! isset( $this->options['image_optimisation']['hardenCommentImages'] ) ) {
				$this->options['image_optimisation']['hardenCommentImages'] = true;
			}
			// Occlusion-aware fetchpriority=low (issue #1426): additive key,
			// defaults to off so existing installs keep current behaviour.
			// In-memory only here (no front-end DB write); persisted via
			// update_settings/REST. Multisite-safe: per-site wppo_settings.
			if ( ! isset( $this->options['image_optimisation']['occlusionFetchpriorityLow'] ) ) {
				$this->options['image_optimisation']['occlusionFetchpriorityLow'] = false;
			}
			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			if ( ! isset( $this->options['file_optimisation']['delayJSSafeMode'] ) ) {
				$this->options['file_optimisation']['delayJSSafeMode'] = true;
			}
			// Auto third-party delay (issue #1314): additive key, defaults to
			// off so existing installs keep current behaviour. In-memory only
			// here (no front-end DB write); persisted via update_settings/REST
			// and backfilled once by maybe_migrate_third_party_auto().
			// Multisite-safe: per-site wppo_settings only.
			if ( ! isset( $this->options['file_optimisation']['delayJSThirdPartyAuto'] ) ) {
				$this->options['file_optimisation']['delayJSThirdPartyAuto'] = false;
			}
			// One-click Delay-JS preset level (issue #1385): additive key,
			// defaults to safe (maximum exclusions) so existing installs keep
			// the safest behaviour. In-memory only here (no front-end DB
			// write); persisted via update_settings/REST and backfilled once
			// by maybe_migrate_third_party_auto(). Multisite-safe: per-site
			// wppo_settings only.
			if ( ! isset( $this->options['file_optimisation']['delayJSPreset'] ) || ! in_array( strtolower( trim( (string) $this->options['file_optimisation']['delayJSPreset'] ) ), array( 'safe', 'balanced', 'aggressive' ), true ) ) {
				$this->options['file_optimisation']['delayJSPreset'] = 'safe';
			}
			// Unified safe-mode kill switch (issue #1098): additive key, defaults
			// to off so existing installs keep current behaviour. In-memory only
			// here (no front-end DB write); persisted via update_settings/REST.
			if ( ! isset( $this->options['file_optimisation']['safeMode'] ) ) {
				$this->options['file_optimisation']['safeMode'] = false;
			}
			// Elementor-safe mode (issue #1259): additive key, defaults to on
			// (builder-proof by default) so combine/inline step aside on
			// Elementor-built pages. In-memory only here (no front-end DB
			// write); persisted via update_settings/REST. Multisite-safe:
			// per-site wppo_settings only.
			if ( ! isset( $this->options['file_optimisation']['elementorSafeMode'] ) ) {
				$this->options['file_optimisation']['elementorSafeMode'] = true;
			}
			// Sandbox preview staged values (issue #1163): additive key,
			// defaults to empty so existing installs keep current behaviour.
			// In-memory only here (no front-end DB write).
			if ( ! isset( $this->options['file_optimisation']['sandboxStaged'] ) || ! is_array( $this->options['file_optimisation']['sandboxStaged'] ) ) {
				$this->options['file_optimisation']['sandboxStaged'] = array();
			}
			// Font subsetting opt-in (issue #1145): additive keys, off by
			// default so existing installs keep full-unicode behavior.
			// In-memory only here (no front-end DB write).
			if ( ! isset( $this->options['file_optimisation']['fontSubset'] ) ) {
				$this->options['file_optimisation']['fontSubset'] = false;
			}
			if ( ! isset( $this->options['file_optimisation']['fontSubsetSubsets'] ) ) {
				$this->options['file_optimisation']['fontSubsetSubsets'] = 'latin';
			}
			// Builder CSS drift purge watcher (issue #1288): additive keys,
			// default on so existing installs keep the current self-heal
			// behaviour. In-memory only here (no front-end DB write);
			// persisted by maybe_migrate_builder_watcher() on admin_init.
			if ( ! isset( $this->options['file_optimisation']['builderPurgeWatcher'] ) ) {
				$this->options['file_optimisation']['builderPurgeWatcher'] = true;
			}
			if ( ! isset( $this->options['file_optimisation']['builderPurgeDriftLog'] ) ) {
				$this->options['file_optimisation']['builderPurgeDriftLog'] = true;
			}

			// Existing installs whose stored settings predate the
			// speculationRumGating key (issue #1061) inherit the enabled
			// default in-memory here (no database write on front-end
			// requests). Multisite-safe: per-site wppo_settings only.
			if ( ! isset( $this->options['preload_settings'] ) || ! is_array( $this->options['preload_settings'] ) ) {
				$this->options['preload_settings'] = array();
			}
			if ( ! isset( $this->options['preload_settings']['speculationRumGating'] ) ) {
				$this->options['preload_settings']['speculationRumGating'] = true;
			}
			// Existing installs whose stored settings predate the
			// RUM-weighted top-URL cap (issue #1183) inherit the 2-URL
			// default in-memory here (no database write on front-end
			// requests). Multisite-safe: per-site wppo_settings only.
			if ( ! isset( $this->options['preload_settings']['speculationTopUrlsLimit'] ) ) {
				$this->options['preload_settings']['speculationTopUrlsLimit'] = 2;
			}
			// Existing installs whose stored settings predate the
			// high-value prerender list toggle (issue #1237) inherit the
			// off default in-memory here (no database write on front-end
			// requests). Multisite-safe: per-site wppo_settings only.
			if ( ! isset( $this->options['preload_settings']['speculationPrerenderList'] ) ) {
				$this->options['preload_settings']['speculationPrerenderList'] = false;
			}
			// Existing installs whose stored settings predate the
			// speculation-rules eagerness upgrade (issue #1215) inherit the
			// conservative defaults in-memory here (prefetch + conservative,
			// prerender stays opt-in; no database write on front-end
			// requests). Multisite-safe: per-site wppo_settings only.
			if ( ! isset( $this->options['preload_settings']['enableSpeculationRules'] ) ) {
				$this->options['preload_settings']['enableSpeculationRules'] = false;
			}
			if ( ! isset( $this->options['preload_settings']['speculationMode'] ) ) {
				$this->options['preload_settings']['speculationMode'] = 'prefetch';
			}
			if ( ! isset( $this->options['preload_settings']['speculationEagerness'] ) ) {
				$this->options['preload_settings']['speculationEagerness'] = 'conservative';
			}
			if ( ! isset( $this->options['preload_settings']['speculationExcludeUrls'] ) ) {
				$this->options['preload_settings']['speculationExcludeUrls'] = '';
			}
			if ( ! isset( $this->options['preload_settings']['speculationDocumentRules'] ) ) {
				$this->options['preload_settings']['speculationDocumentRules'] = true;
			}
			// Automatic LCP hero preload + automatic font discovery
			// (issue #1216): additive keys, off by default so existing
			// installs keep manual-only behaviour. In-memory only here
			// (no front-end DB write); persisted via update_settings/REST
			// and backfilled once by maybe_migrate_preload_auto_defaults().
			if ( ! isset( $this->options['preload_settings']['autoLcpPreload'] ) ) {
				$this->options['preload_settings']['autoLcpPreload'] = false;
			}
			if ( ! isset( $this->options['preload_settings']['autoDiscoverFonts'] ) ) {
				$this->options['preload_settings']['autoDiscoverFonts'] = false;
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
			if ( ! isset( $this->options['ai_adaptive']['use_wp_ai_client'] ) ) {
				$this->options['ai_adaptive']['use_wp_ai_client'] = false;
			}
			if ( ! isset( $this->options['ai_adaptive']['field_lcp_min_samples'] ) ) {
				$this->options['ai_adaptive']['field_lcp_min_samples'] = 20;
			}
			if ( ! isset( $this->options['ai_adaptive']['dismissed_suggestions'] ) || ! is_array( $this->options['ai_adaptive']['dismissed_suggestions'] ) ) {
				$this->options['ai_adaptive']['dismissed_suggestions'] = array();
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_cooldown_days'] ) ) {
				$this->options['ai_adaptive']['anomaly_cooldown_days'] = 7;
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_min_samples'] ) ) {
				$this->options['ai_adaptive']['anomaly_min_samples'] = 10;
			}
			if ( ! isset( $this->options['ai_adaptive']['css_refresh_on_lcp_regression'] ) ) {
				$this->options['ai_adaptive']['css_refresh_on_lcp_regression'] = false;
			}
			if ( ! isset( $this->options['ai_adaptive']['css_refresh_cooldown_days'] ) ) {
				$this->options['ai_adaptive']['css_refresh_cooldown_days'] = 7;
			}
			// Existing installs whose stored settings predate the RUM-segmented
			// speculation auto-tune keys (issue #1425) inherit the opt-in-off
			// defaults in-memory here (no database write on front-end requests);
			// the persisted values are backfilled once by
			// maybe_migrate_ai_speculation_autotune() on admin_init. Defaults
			// keep current behaviour verbatim (auto-tune off, legacy
			// conservative emission) until explicitly opted in.
			if ( ! isset( $this->options['ai_adaptive']['speculation_autotune_enabled'] ) ) {
				$this->options['ai_adaptive']['speculation_autotune_enabled'] = false;
			}
			if ( ! isset( $this->options['ai_adaptive']['speculation_min_samples'] ) ) {
				$this->options['ai_adaptive']['speculation_min_samples'] = 20;
			}
			if ( ! isset( $this->options['ai_adaptive']['speculation_max_urls'] ) ) {
				$this->options['ai_adaptive']['speculation_max_urls'] = 5;
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_tolerance_pct'] ) ) {
				$this->options['ai_adaptive']['anomaly_tolerance_pct'] = 5.0;
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_tolerance_abs'] ) ) {
				$this->options['ai_adaptive']['anomaly_tolerance_abs'] = 0.01;
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_persistence_windows'] ) ) {
				$this->options['ai_adaptive']['anomaly_persistence_windows'] = 3;
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_p75_min_samples'] ) ) {
				$this->options['ai_adaptive']['anomaly_p75_min_samples'] = 10;
			}
			// Existing installs whose stored settings predate the anomaly
			// detector v2 keys (issue #1313) inherit the defaults in-memory
			// here (no database write on front-end requests); the persisted
			// values are backfilled once by
			// maybe_migrate_ai_anomaly_v2() on admin_init.
			if ( ! isset( $this->options['ai_adaptive']['anomaly_band_window'] ) ) {
				$this->options['ai_adaptive']['anomaly_band_window'] = 10;
			}
			if ( ! isset( $this->options['ai_adaptive']['anomaly_recovery_days'] ) ) {
				$this->options['ai_adaptive']['anomaly_recovery_days'] = 3;
			}
			if ( ! isset( $this->options['ai_adaptive']['deploy_notes'] ) || ! is_array( $this->options['ai_adaptive']['deploy_notes'] ) ) {
				$this->options['ai_adaptive']['deploy_notes'] = array();
			}

			if ( ! isset( $this->options['edge_cache'] ) || ! is_array( $this->options['edge_cache'] ) ) {
				$this->options['edge_cache'] = array();
			}
			if ( ! isset( $this->options['edge_cache']['enabled'] ) ) {
				$this->options['edge_cache']['enabled'] = false;
			}

			// Existing installs whose stored settings predate the ccssMaxSize
			// key inherit the 20 KB default in-memory here (no database write
			// on front-end requests); the persisted value is backfilled once
			// by maybe_migrate_ccss_max_size() on admin_init.
			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			if ( ! isset( $this->options['file_optimisation']['ccssMaxSize'] ) ) {
				$this->options['file_optimisation']['ccssMaxSize'] = 20480;
			}
			// Existing installs whose stored settings predate the
			// ccssSafelistExtra key (issue #1038) inherit the empty default
			// in-memory here (no database write on front-end requests); the
			// persisted value is backfilled once by
			// maybe_migrate_ccss_safelist() on admin_init. Empty keeps the
			// current extraction behaviour verbatim (fail-open).
			if ( ! isset( $this->options['file_optimisation']['ccssSafelistExtra'] ) ) {
				$this->options['file_optimisation']['ccssSafelistExtra'] = '';
			}
			// Existing installs whose stored settings predate the RUM-weighted
			// CSS queue keys (issue #1164) inherit the defaults in-memory here
			// (no database write on front-end requests); the persisted values
			// are backfilled once by maybe_migrate_css_queue_defaults() on
			// admin_init. Defaults keep current behaviour verbatim except the
			// bounded per-run caps (fail-open to uncapped on invalid values).
			if ( ! isset( $this->options['file_optimisation']['ccssQueueCap'] ) ) {
				$this->options['file_optimisation']['ccssQueueCap'] = 5;
			}
			// Existing installs whose stored settings predate the CCSS
			// generation timeout key (issue #1235) inherit the 25s default
			// in-memory here (no database write on front-end requests); the
			// persisted value is backfilled once by
			// maybe_migrate_css_queue_defaults() on admin_init.
			if ( ! isset( $this->options['file_optimisation']['ccssGenTimeout'] ) ) {
				$this->options['file_optimisation']['ccssGenTimeout'] = 25;
			}
			// Existing installs whose stored settings predate the 14 KB
			// gzipped inline-budget guard, the cart/checkout inline
			// exclusion, and the checksum-triggered regen keys (issue #1388)
			// inherit the defaults in-memory here (no database write on
			// front-end requests); the persisted values are backfilled once
			// by maybe_migrate_css_queue_defaults() on admin_init.
			if ( ! isset( $this->options['file_optimisation']['ccssInlineBudgetKb'] ) ) {
				$this->options['file_optimisation']['ccssInlineBudgetKb'] = 14;
			}
			if ( ! isset( $this->options['file_optimisation']['ccssCommerceExclude'] ) ) {
				$this->options['file_optimisation']['ccssCommerceExclude'] = true;
			}
			if ( ! isset( $this->options['file_optimisation']['ccssChecksumRegen'] ) ) {
				$this->options['file_optimisation']['ccssChecksumRegen'] = true;
			}
			if ( ! isset( $this->options['file_optimisation']['usedCssQueueCap'] ) ) {
				$this->options['file_optimisation']['usedCssQueueCap'] = 50;
			}
			if ( ! isset( $this->options['file_optimisation']['ccssViewportVariants'] ) ) {
				$this->options['file_optimisation']['ccssViewportVariants'] = false;
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
			if ( ! is_array( $file_optimisation_opts ) ) {
				// Normalize the source option too: the preset branch below
				// writes $this->options['file_optimisation']['heartbeatControl'],
				// which fatals on a corrupted non-array (e.g. string from a bad
				// import) unless the write target is an array as well.
				$file_optimisation_opts             = array();
				$this->options['file_optimisation'] = array();
			}
			// INP-first preset (#932): one-click 60s heartbeat via the existing
			// disable_heartbeat path. In-memory only — an explicit user choice
			// (disable_all/disable_ext) always wins, never overridden.
			if ( ! empty( $file_optimisation_opts['delayJSINPPreset'] ) && 'default' === ( $file_optimisation_opts['heartbeatControl'] ?? 'default' ) ) {
				$this->options['file_optimisation']['heartbeatControl'] = '60s';
				$file_optimisation_opts['heartbeatControl']             = '60s';
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
			if ( file_exists( WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'vendor/woocommerce/action-scheduler/action-scheduler.php';
			}

			// LiteSpeed integration (Phase 1 — safe coexistence). Loaded after
			// Server_Rules so is_litespeed() delegation is available.
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-server-rules.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-server-rules.php';
			}
			// Header emitter first: LiteSpeed_Integration + ESI delegate to it.
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-header-emitter.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-header-emitter.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-litespeed-integration.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-litespeed-integration.php';
			}
			// Modularity (issue #1443): the LiteSpeed-only crawler + ESI stack
			// is parsed only when it can act. Non-LiteSpeed frontend requests
			// never pay the parse + memory cost. Fail-open: when detection is
			// unavailable the stack still loads (today's behaviour). Method-level
			// fail-closed checks (is_litespeed(), is_esi_available()) stay as
			// defence in depth. Server-agnostic classes (AI_Adaptive,
			// Edge_Cache, Edge_Purger, CDN, ...) are always loaded below.
			if ( self::should_load_litespeed_stack() ) {
				if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-litespeed-crawler.php' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/class-litespeed-crawler.php';
				}
				if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-litespeed-esi.php' ) ) {
					require_once WPPO_PLUGIN_PATH . 'includes/class-litespeed-esi.php';
				}
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
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/trait-purge-logger.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/trait-purge-logger.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-edge-purger.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-edge-purger.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-cdn.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-cdn.php';
			}
			if ( file_exists( WPPO_PLUGIN_PATH . 'includes/class-builder-purge-watcher.php' ) ) {
				require_once WPPO_PLUGIN_PATH . 'includes/class-builder-purge-watcher.php';
			}

			// Fallback loader for the remaining core classes when the Composer
			// classmap is stale or unavailable (partial release builds). Lazy:
			// registered as an spl autoloader so healthy requests pay nothing on
			// the hot path — a file is required only on an actual missing-class
			// failure. Classes already required unconditionally above
			// (Server_Rules, Header_Emitter, LiteSpeed_Integration,
			// Llms, OD_Bridge, Bfcache,
			// AI_Adaptive, Edge_Cache, Edge_Purger, CDN, Builder_Purge_Watcher,
			// Perf_Translations) are intentionally omitted here to avoid
			// duplicate probes. LiteSpeed_Crawler + LiteSpeed_ESI are included
			// below so late callers (cron, abilities, integration info) still
			// resolve them via autoload when includes() skipped the eager load
			// on non-LiteSpeed requests.
			$fallback_map = array(
				'Abilities'              => 'class-abilities.php',
				'Activate'               => 'class-activate.php',
				'Admin_Notices'          => 'class-admin-notices.php',
				'Advanced_Cache_Handler' => 'class-advanced-cache-handler.php',
				'Asset_Manager'          => 'class-asset-manager.php',
				'Cache'                  => 'class-cache.php',
				'Cache_Key'              => 'class-cache-key.php',
				'CDN_Purger'             => 'class-cdn-purger.php',
				'Cloudflare_Purger'      => 'class-cloudflare-purger.php',
				'Core_Tweaks'            => 'class-core-tweaks.php',
				'Critical_CSS'           => 'class-critical-css.php',
				'Css_Safelist'           => 'class-css-safelist.php',
				'Cron'                   => 'class-cron.php',
				'Database_Cleanup'       => 'class-database-cleanup.php',
				'Deactivate'             => 'class-deactivate.php',
				'Google_Fonts'           => 'class-google-fonts.php',
				'Htaccess_Handler'       => 'class-htaccess-handler.php',
				'Image_Optimisation'     => 'class-image-optimisation.php',
				'Img_Converter'          => 'class-img-converter.php',
				'LiteSpeed_Crawler'      => 'class-litespeed-crawler.php',
				'LiteSpeed_ESI'          => 'class-litespeed-esi.php',
				'Log'                    => 'class-log.php',
				'Metabox'                => 'class-metabox.php',
				'Object_Cache'           => 'class-object-cache.php',
				'Pagespeed'              => 'class-pagespeed.php',
				'Purge_Logger'           => 'trait-purge-logger.php',
				'Rest'                   => 'class-rest.php',
				'RUM'                    => 'class-rum.php',
				'Sandbox_Preview'        => 'class-sandbox-preview.php',
				'Settings_Store'         => 'class-settings-store.php',
				'Suggestion_Engine'      => 'class-suggestion-engine.php',
				'System_Info'            => 'class-system-info.php',
				'Telemetry'              => 'class-telemetry.php',
				'Used_CSS'               => 'class-used-css.php',
				'Util'                   => 'class-util.php',
			);
			spl_autoload_register(
				static function ( $class_name ) use ( $fallback_map ): void {
					$prefix = 'PerformanceOptimise\\Inc\\';
					if ( 0 !== strpos( (string) $class_name, $prefix ) ) {
						return;
					}
					$short = substr( (string) $class_name, strlen( $prefix ) );
					if ( ! isset( $fallback_map[ $short ] ) ) {
						return;
					}
					if ( class_exists( $class_name, false ) ) {
						return;
					}
					$fallback_path = WPPO_PLUGIN_PATH . 'includes/' . $fallback_map[ $short ];
					if ( file_exists( $fallback_path ) ) {
						require_once $fallback_path;
					}
				}
			);

			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				$cli_file = WPPO_PLUGIN_PATH . 'includes/class-wppo-cli-command.php';
				if ( file_exists( $cli_file ) ) {
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
		 * @since 2.3.0
		 * @return bool True when the LiteSpeed stack should be required.
		 */
		private static function should_load_litespeed_stack(): bool {
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
			// Builder-update purge watcher (issue #907): scoped purge of builder
			// CSS + WPPO caches after Elementor/Divi/Bricks/WPBakery updates.
			// No settings UI; the watcher self-gates to builder slugs.
			if ( class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
				( new Builder_Purge_Watcher() )->register();
			}
			add_action( 'admin_init', array( $this, 'maybe_migrate_block_assets_setting' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_ccss_max_size' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_ccss_safelist' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_css_queue_defaults' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_speculation_top_urls' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_speculation_prerender_list' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_rum_sample_rate' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_safe_mode' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_elementor_safe_mode' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_image_alt_edge_defaults' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_preload_auto_defaults' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_object_cache_outage_flag' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_ai_speculation_autotune' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_ai_anomaly_v2' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_comment_image_hardening' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_builder_watcher' ) );
			add_action( 'admin_init', array( $this, 'maybe_migrate_third_party_auto' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'init', array( $this, 'set_role_hash_cookie' ) );
			add_action( 'wp_logout', array( $this, 'clear_role_hash_cookie' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $this, 'apply_module_loading_strategies' ), 10000 );
			// Sandbox preview (issue #1163): visitor-safe admin preview honoring
			// DONOTCACHEPAGE. Runs early so Cache::is_not_cacheable() sees it.
			if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
				add_action( 'template_redirect', array( 'PerformanceOptimise\Inc\Sandbox_Preview', 'enforce_no_cache' ), 1 );
			}
			// Unified safe-mode kill switch (issue #1098): one-click recovery
			// that preserves delayJS/deferJS/removeUnusedCSS settings. Effective
			// flags gate hook registration so safe mode disables all three
			// without losing settings; per-tag guards below re-check for
			// mid-request safety.
			$safe_mode_off = ! self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() );
			$has_delay_js  = ! empty( $this->options['file_optimisation']['delayJS'] ) && $safe_mode_off;
			$has_defer_js  = ! empty( $this->options['file_optimisation']['deferJS'] ) && $safe_mode_off;
			// Sandbox preview widens hook registration for the preview admin
			// only: when staged values enable delay/defer, register the same
			// filters so the preview renders experimental output while
			// visitors (is_preview_request() false) keep production markup
			// via the per-tag guards below. Staged presence is read WITHOUT the
			// capability/nonce gate: setup_hooks() runs at plugin-file load
			// before pluggable/auth resolve, when is_preview_request() always
			// returns false — snapshotting it here would never register hooks
			// for a preview-enable (production off, staged on) request.
			// Enforcement stays in the late per-tag/render-time guards.
			$staged_for_registration = array();
			if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'get_staged_settings' ) ) {
				try {
					$staged_for_registration = Sandbox_Preview::get_staged_settings();
					if ( ! is_array( $staged_for_registration ) ) {
						$staged_for_registration = array();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$staged_for_registration = array();
				}
			}
			// Staged defer for the per-page kill-switch registration below.
			$staged_defer_js = ! empty( $staged_for_registration['deferJS'] );
			if ( ! empty( $staged_for_registration['delayJS'] ) ) {
				$has_delay_js = true;
			}
			if ( $staged_defer_js ) {
				$has_defer_js = true;
			}
			$wp_version = (string) ( $GLOBALS['wp_version'] ?? get_bloginfo( 'version' ) );
			// The native 'strategy' script data added via wp_script_add_data() is only
			// honoured by core since WP 6.3, so the native defer path is gated to 6.3+
			// and older core (WP 6.2) uses the legacy script_loader_tag fallback.
			// TODO(#553): remove the legacy fallback when minimum supported WP is raised to 6.3.
			// Issue #1203 (modularity) proposed removing the shims now; deferred —
			// the floor is still 6.2, so deletion would drop defer-JS on 6.2 with no
			// native replacement. One canonical path per install is already enforced
			// by the version+feature gate below (never both regex passes per request).
			// Issue #1218: version_compare alone can mis-route on a backported or
			// filtered version string, so the canonical predicate is
			// supports_native_defer_strategy() (version + function_exists +
			// a genuinely-6.3 WP_Scripts method probe when the class exists).
			$use_native_defer = self::supports_native_defer_strategy();
			// Native script fetchpriority capability (issue #1218): version +
			// API probe via supports_native_script_fetchpriority() so a
			// filtered version string cannot enable native fetchpriority
			// writes where the API is absent. Template-enhancement buffer
			// routing below uses the canonical
			// should_use_core_template_buffer() predicate (version + API
			// probe, issue #1386) — never a bare version_compare.
			$supports_fetchpriority = self::supports_native_script_fetchpriority();
			// Pre-release-inclusive floor: '6.9-alpha' also matches alpha/beta/RC builds
			// of 6.9 which already ship the template-enhancement buffer functions.
			// Single-buffer routing (issue #1386): on 6.9+ post-processing rides
			// the core template-enhancement buffer only (no private ob_start
			// capture); pre-6.9 keeps the legacy template_redirect captures.
			// A runtime opt-out (filter returning false / no consumers) degrades
			// to uncached streaming output — never a private buffer. This is an
			// intentional availability tradeoff: re-arming a private capture on
			// opt-out would stack a second buffer on top of core's and
			// re-process HTML, so caching stays off until core opts back in.
			// TODO(#553, #829): remove the legacy buffer paths when minimum supported WP is raised to 6.9.
			// Blocked until `Requires at least: 6.9`.
			$use_core_buffer = self::should_use_core_template_buffer();

			// Delay JS: the script_loader_tag filter performs the wppo-src/type rewriting
			// on every supported version, so it is always registered when delay JS is on.
			// Sandbox preview (issue #1163): the per-page kill-switch hook sets
			// both delay_disabled_for_page and defer_disabled_for_page, so the
			// second disjunct also honors staged defer — otherwise a
			// staged-defer-only preview would defer even on kill-switched pages.
			if ( $has_delay_js || ! empty( $this->options['file_optimisation']['deferJS'] ) || $staged_defer_js ) {
				add_action( 'wp', array( $this, 'apply_per_page_delay_config' ) );
			}
			if ( $has_delay_js ) {
				add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute' ), 10, 2 );
			}

			// Defer JS: use the native strategy on WP 6.3+, the script_loader_tag
			// fallback on older core.
			if ( $has_defer_js ) {
				if ( $use_native_defer ) {
					add_action( 'wp_enqueue_scripts', array( $this, 'add_defer_strategy' ), 1000 );
				} else {
					add_filter( 'script_loader_tag', array( $this, 'add_defer_attribute_legacy' ), 10, 2 );
				}
				// Native fetchpriority rendering arrived in WP 6.9 (Trac #61734); the
				// regex-based script_loader_tag fallback only runs on older cores.
				if ( ! $supports_fetchpriority ) {
					add_filter( 'script_loader_tag', array( $this, 'add_fetchpriority_to_deferred' ), 11, 2 );
				}
			}
			add_action( 'admin_bar_menu', array( $this, 'add_setting_to_admin_bar' ), 100 );

			if ( ! empty( $this->options['file_optimisation']['removeWooCSSJS'] ) ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'remove_woocommerce_scripts' ), 999 );
			}

			if ( ! empty( $this->options['cache_settings']['enableCache'] ) ) {
				$this->cache = self::create_cache( $this->options );
				if ( is_object( $this->cache ) && method_exists( $this->cache, 'set_image_optimisation' ) ) {
					$this->cache->set_image_optimisation( $this->image_optimisation );
				}
				if ( is_object( $this->cache ) && method_exists( $this->cache, 'set_google_fonts' ) ) {
					$this->cache->set_google_fonts( $this->google_fonts );
				}
				if ( $use_core_buffer ) {
					// WP 6.9+ core template-enhancement buffer (issue #1386):
					// single-buffer routing — filter processes, finalized action
					// persists. No private ob_start capture is registered, so
					// exactly one core buffer is active when consumers exist and
					// zero when none; a runtime opt-out (filter false) degrades
					// to uncached streaming output. Intentional (see above):
					// caching resumes automatically when core opts back in.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this->cache, 'process_buffer_for_cache' ), 10, 2 );
					add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this->cache, 'stash_cache' ) );
				} else {
					// Legacy buffer path (pre-6.9 only): the only cache path on
					// older cores. Retired on 6.9+ (issue #1386) — never
					// registered alongside the core buffer.
					add_action( 'template_redirect', array( $this->cache, 'start_output_buffer' ) );
				}
				// Post-purge last-good fallback (issue #1275): Nginx falls
				// through to index.php?wppo_purge_fallback=$uri on a miss
				// under the cache path; this 302s to the retained sibling
				// fallback when one exists. Self-gated (no-op when disabled).
				// has_action() guard: the combine-only branch below registers
				// the same callback, and WP would dedupe today but a future
				// second Cache instance or priority change must not double-fire.
				if ( ! has_action( 'template_redirect', array( $this->cache, 'maybe_serve_purge_fallback' ) ) ) {
					add_action( 'template_redirect', array( $this->cache, 'maybe_serve_purge_fallback' ), 1 );
				}
				add_action( 'save_post', array( $this, 'on_save_post_invalidate_cache' ), 10, 3 );
				// Per-page delay kill-switch single-URL purge (#1037, extended #1098):
				// programmatic `_wppo_delay_disabled` / `_wppo_defer_disabled` /
				// `_wppo_used_css_disabled` writes (REST, WP-CLI, imports) purge
				// that URL only via invalidate_aggressive_kill_switch_cache() —
				// never a full-cache wipe. The metabox save path is covered by
				// save_post above plus the toggle check in
				// Metabox::save_asset_manager_settings().
				// add_action() on these hooks is harmless when meta is untouched;
				// callbacks ignore every key except the three kill-switches.
				add_action( 'added_post_meta', array( $this, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'updated_post_meta', array( $this, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'deleted_post_meta', array( $this, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				// WooCommerce surgical invalidation (issue #962): product /
				// order / coupon changes purge only affected URLs — never a
				// full-cache wipe. add_action() on unregistered hooks is
				// harmless on non-Woo installs; callbacks guard WC APIs at
				// call time for WP 6.2 / PHP 8.2 compat. @since 2.0.0.
				add_action( 'woocommerce_update_product', array( $this, 'on_woocommerce_product_updated' ), 10, 1 );
				add_action( 'woocommerce_checkout_order_created', array( $this, 'on_woocommerce_order_changed' ), 10, 1 );
				add_action( 'woocommerce_update_order', array( $this, 'on_woocommerce_order_changed' ), 10, 1 );
				add_action( 'woocommerce_coupon_options_save', array( $this, 'on_woocommerce_coupon_saved' ), 10, 1 );
			}

			// Server-Timing (WP 6.9+). Registering wp_finalized_template_enhancement_output_buffer
			// automatically opts into the template-enhancement buffer (priority 1000 by default),
			// which disables response streaming. TTFB increases while TTLB unchanged — intentional
			// when Server-Timing is enabled; keep disabled by default and emit only on cache-miss
			// generation passes (advanced-cache.php serves cached pages without booting WordPress).
			// @since 2.0.0.
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $this->server_timing_enabled() ) {
				add_action( 'template_redirect', array( $this, 'capture_template_start' ), 0 );
				add_action( 'wp_finalized_template_enhancement_output_buffer', array( $this, 'emit_server_timing_header' ), 0, 1 );
			}

			// Per-page aggressive kill-switch purge when page cache is off but
			// used-CSS sidecars still exist (issue #1098): register the same
			// single-URL purge outside the enableCache branch so
			// `_wppo_used_css_disabled` / `_wppo_defer_disabled` toggles purge
			// the URL even without the static HTML cache.
			if ( empty( $this->options['cache_settings']['enableCache'] ) ) {
				add_action( 'added_post_meta', array( $this, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'updated_post_meta', array( $this, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'deleted_post_meta', array( $this, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
			}

			// Standalone used-CSS output buffer when page cache is disabled.
			if ( empty( $this->options['cache_settings']['enableCache'] ) && ! empty( $this->options['file_optimisation']['removeUnusedCSS'] ) && $safe_mode_off ) {
				if ( $use_core_buffer ) {
					// WP 6.9+ core template-enhancement buffer (issue #1386):
					// single-buffer routing, no private capture.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this, 'process_used_css_only' ), 20, 2 );
				} else {
					// Legacy fallback buffer (pre-6.9 only, issue #881).
					add_action( 'template_redirect', array( $this, 'start_used_css_buffer' ) );
				}
			}

			// Optional LCP image prioritization on the finalized HTML (default off).
			// The CSS background hero preload (issue #935) and the OD
			// occlusion-aware fetchpriority=low demotion (issue #1426) share
			// this buffer, so the hook is registered when any toggle is enabled.
			if ( ! empty( $this->options['image_optimisation']['prioritizeLCPImages'] ) || ! empty( $this->options['image_optimisation']['cssHeroPreload'] ) || ! empty( $this->options['image_optimisation']['occlusionFetchpriorityLow'] ) ) {
				// Core-parity by delegation (issue #1182): this buffer-level LCP
				// prioritization stamps fetchpriority=high + loading=eager on the
				// hero node only; all loading/fetchpriority/decoding gap-fills
				// defer to core's wp_get_loading_optimization_attributes() output
				// via Image_Optimisation::merge_core_loading_attributes(), and
				// loading="lazy" + fetchpriority="high" pairs are never emitted.
				if ( $use_core_buffer ) {
					// WP 6.9+ core template-enhancement buffer (issue #1386).
					// Runs after cache (10) and used-CSS (20). No private
					// capture is registered on 6.9+.
					add_filter( 'wp_template_enhancement_output_buffer', array( $this->image_optimisation, 'prioritize_lcp_in_buffer' ), 30, 2 );
				} else {
					// Legacy fallback buffer (pre-6.9 only, issue #881).
					add_action( 'template_redirect', array( $this, 'start_lcp_priority_buffer' ), 20 );
				}
			}

			// Invalidate DB cleanup counts when posts are added or removed (for public post types).
			if ( is_admin() ) {
				add_action( 'save_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10, 2 );
				add_action( 'deleted_post', array( 'PerformanceOptimise\Inc\Database_Cleanup', 'on_post_change' ), 10, 2 );
			}

			// Flush Image_Optimisation per-request stat caches (file_exists + image
			// sizes) on blog switches and after cache clears so paths from another
			// site or pre-clear state are re-verified (audit #888 finding 7).
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Image_Optimisation', 'clear_runtime_caches' ) );
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Main', 'reset_font_preload_emitted' ) );
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Main', 'reset_image_lcp_memos' ), 10, 2 );
			add_action( 'wppo_after_cache_clear', array( 'PerformanceOptimise\Inc\Image_Optimisation', 'clear_runtime_caches' ) );
			$combine_for_registration = ! empty( $this->options['file_optimisation']['combineCSS'] ) && $safe_mode_off;
			// Unified safe-mode kill switch (issue #1465): safe mode also
			// disables combineCSS registration so a single action restores an
			// unbroken render. Staged combine widens registration for the
			// preview admin only (per-tag guards enforce preview-only output).
			if ( ! $combine_for_registration && ! empty( $staged_for_registration['combineCSS'] ) ) {
				$combine_for_registration = true;
			}
			if ( $combine_for_registration ) {
				// NOTE(#624, audit #1392): reassess combine_css() only when WP 7.2
				// concatenation behaviour is confirmed — speculative until then.
				// No runtime change.
				if ( ! $this->cache ) {
					$this->cache = self::create_cache( $this->options );
					if ( is_object( $this->cache ) && method_exists( $this->cache, 'set_image_optimisation' ) ) {
						$this->cache->set_image_optimisation( $this->image_optimisation );
					}
					if ( is_object( $this->cache ) && method_exists( $this->cache, 'set_google_fonts' ) ) {
						$this->cache->set_google_fonts( $this->google_fonts );
					}
				}
				add_action( 'wp_enqueue_scripts', array( $this->cache, 'combine_css' ), PHP_INT_MAX );
				// Post-purge last-good fallback (issue #1275) when the page
				// cache itself is off but combined CSS is on: same self-gated
				// miss handler as the enableCache branch above (guarded so
				// both branches never double-register).
				if ( ! has_action( 'template_redirect', array( $this->cache, 'maybe_serve_purge_fallback' ) ) ) {
					add_action( 'template_redirect', array( $this->cache, 'maybe_serve_purge_fallback' ), 1 );
				}
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
				$cve_handles = $this->get_cve_guard_handles();
				if ( ! empty( $cve_handles ) ) {
					$this->exclude_js = array_values( array_unique( array_merge( $this->exclude_js, $cve_handles ) ) );
				}

				add_filter( 'script_loader_tag', array( $this, 'minify_js' ), 10, 3 );
			}

			if ( ! empty( $this->options['file_optimisation']['minifyCSS'] ) ) {
				if ( ! empty( $this->options['file_optimisation']['excludeCSS'] ) ) {
					$exclude_css       = Util::process_urls( $this->options['file_optimisation']['excludeCSS'] );
					$this->exclude_css = array_merge( $this->exclude_css, (array) $exclude_css );
				}
				$cve_handles = $this->get_cve_guard_handles();
				if ( ! empty( $cve_handles ) ) {
					$this->exclude_css = array_values( array_unique( array_merge( $this->exclude_css, $cve_handles ) ) );
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

			// Removed (#925): the legacy file_optimisation.removeQueryStrings path
			// (strip_static_query_strings() on script/style_loader_src) is gone.
			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ) ) {
				add_filter( 'style_loader_tag', array( $this->google_fonts, 'process_style_tag' ), 9, 3 );
			}

			$this->register_block_assets_filters( function_exists( 'wp_load_classic_theme_block_styles_on_demand' ) );

			// Block-asset on-demand interop (issue #1147): dequeue per-block
			// stylesheets for blocks absent from the current singular post
			// content. Runs late (after core enqueues) but before
			// minify_queued_styles() (PHP_INT_MAX - 1) and Cache::combine_css()
			// (PHP_INT_MAX) so dequeued handles never enter those pipelines
			// (dequeue only, never re-enqueue/combine — cascade order of the
			// survivors is untouched). Only registered when the on-demand
			// opt-out is off (blockAssetsOnDemand on, loadAllCoreBlockAssets
			// off), mirroring register_block_assets_filters(). The callback
			// itself re-checks the gate and defers to core when the 6.9
			// template-enhancement buffer exists, and otherwise runs the
			// legacy omission path.
			if ( $this->is_hidden_block_asset_omission_enabled() ) {
				add_action( 'wp_enqueue_scripts', array( $this, 'omit_hidden_block_assets' ), PHP_INT_MAX - 2 );
			}

			if ( ! empty( $this->options['file_optimisation']['deferJS'] ) ) {
				$exclude_js = array( 'wppo-lazyload' );
				if ( ! empty( $this->options['file_optimisation']['excludeDeferJS'] ) ) {
					$exclude_defer          = Util::process_urls( $this->options['file_optimisation']['excludeDeferJS'] );
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

			if ( ! empty( $this->options['file_optimisation']['delayJS'] ) ) {
				$exclude_js = array( 'wppo-lazyload' );
				if ( ! empty( $this->options['file_optimisation']['excludeDelayJS'] ) ) {
					$exclude_delay          = Util::process_urls( $this->options['file_optimisation']['excludeDelayJS'] );
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
				if ( ! empty( $this->options['file_optimisation']['deferJS'] ) ) {
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

			$this->register_head_hint_hooks();
			$this->register_collaborators();

			// Critical CSS hooks. Safe mode (issue #1098) disables stylesheet
			// deferral in one click while preserving the criticalCSS setting.
			if ( ! empty( $this->options['file_optimisation']['criticalCSS'] ) && $safe_mode_off ) {
				add_action( 'wp_head', array( 'PerformanceOptimise\Inc\Critical_CSS', 'inline_ccss' ), 0 );
				// Checksum auto-regen (issue #1038) must run once $wp_styles->queue
				// is final: the `wp_enqueue_scripts` action fires inside core's
				// `wp_head` priority-1 `wp_enqueue_scripts()` call, AFTER theme and
				// plugin enqueues but BEFORE core's `wp_maybe_inline_styles()`.
				// inline_ccss() at wp_head:0 runs too early (queue still empty) and
				// no longer performs the probe itself.
				add_action( 'wp_enqueue_scripts', array( 'PerformanceOptimise\Inc\Critical_CSS', 'maybe_check_stale_on_enqueue' ), PHP_INT_MAX );
				add_filter( 'style_loader_tag', array( 'PerformanceOptimise\Inc\Critical_CSS', 'defer_stylesheets' ), 10, 3 );
				add_action( 'wppo_generate_ccss', array( 'PerformanceOptimise\Inc\Critical_CSS', 'background_generate' ), 10, 1 );
			}

			$this->register_background_hooks();

			$this->register_invalidation_hooks();

			add_action( 'wp_ajax_wppo_get_nonce', array( $rest, 'ajax_get_nonce' ) );

			$this->register_integration_hooks();
		}

		/**
		 * Register frontend head-hint hooks (preload/preconnect/speculation).
		 *
		 * Extracted from {@see setup_hooks()} so hook-group changes stay
		 * local to one collaborator-concern method.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		private function register_head_hint_hooks(): void {
			add_action( 'wp_head', array( $this, 'add_preload_prefetch_preconnect' ), 1 );
			add_action( 'wp_head', array( $this, 'add_speculation_rules' ), 0 );
			add_filter( 'wp_resource_hints', array( $this, 'add_resource_hints' ), 10, 2 );
		}

		/**
		 * Instantiate collaborator services (metabox, cron, assets, abilities).
		 *
		 * Extracted from {@see setup_hooks()}; keeps Main as a thin
		 * bootstrapper over collaborator registration.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		private function register_collaborators(): void {
			new Metabox();
			new Cron();
			new Asset_Manager();
			new Abilities();
		}

		/**
		 * Register background-job hooks (Action Scheduler + save_post queue).
		 *
		 * Extracted from {@see setup_hooks()}.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		private function register_background_hooks(): void {
			// Register Action Scheduler callback for background image processing.
			add_action( 'wppo_convert_image_background', array( $this, 'process_background_image' ), 10, 1 );

			// Phase 2 — Register Action Scheduler callback for background PageSpeed scans.
			add_action( 'wppo_pagespeed_scan', array( 'PerformanceOptimise\Inc\Pagespeed', 'run_scan' ), 10, 1 );

			// Register Action Scheduler callback for background used-CSS generation.
			// Two accepted args (issue #1347): the optional HMAC signature
			// travels as the second positional scheduler arg; legacy jobs
			// pass one arg and take the legacy path. Signers append `sig`
			// after `post_id` (insertion order) and both sides normalize
			// `post_id` to int, so reordering or int/string drift cannot
			// fail a valid job.
			add_action( 'wppo_used_css_generate', array( 'PerformanceOptimise\Inc\Used_CSS', 'process_background' ), 10, 2 );

			// Self-healing CSS (issue #1407): refresh the matching
			// critical-CSS template when an LCP regression queues a
			// used-CSS regen, so critical-CSS-driven regressions self-heal
			// too. Fail-open inside the handler, never fatal.
			add_action( 'wppo_ai_css_refresh_queued', array( $this, 'on_ai_css_refresh_queued' ), 10, 3 );

			// Register out-of-band Google Fonts download (keeps the frontend output-buffer hot path non-blocking).
			add_action( 'wppo_google_fonts_download', array( 'PerformanceOptimise\Inc\Google_Fonts', 'handle_queued_download_action' ), 10, 1 );

			// Queue used-CSS regeneration when post content changes.
			add_action( 'save_post', array( $this, 'on_save_post_queue_used_css' ), 10, 3 );
		}

		/**
		 * Register cache-invalidation hooks (structural changes).
		 *
		 * Extracted from {@see setup_hooks()}.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		private function register_invalidation_hooks(): void {
			// Clear all cache on structural changes that invalidate every cached page.
			add_action( 'update_option_permalink_structure', array( __CLASS__, 'clear_all_cache' ) );
			add_action( 'switch_theme', array( __CLASS__, 'clear_all_cache' ) );
			// Couple used-CSS regen to theme switches with cooldown plus a
			// bounded targeted requeue (issue #1220); fail-open inside.
			add_action( 'switch_theme', array( __CLASS__, 'on_theme_switch_used_css' ), 20, 3 );
			add_action( 'update_option_wppo_settings', array( __CLASS__, 'on_settings_update' ), 10, 2 );
			// The canonical host is baked into advanced-cache.php at create()
			// time; re-bake it when the home/site URL changes (domain migration)
			// so the drop-in does not silently run uncached on a stale host.
			add_action( 'update_option_home', array( __CLASS__, 'on_site_url_change' ), 10, 3 );
			add_action( 'update_option_siteurl', array( __CLASS__, 'on_site_url_change' ), 10, 3 );
			add_action( 'activated_plugin', array( __CLASS__, 'clear_all_cache' ) );
			add_action( 'deactivated_plugin', array( __CLASS__, 'clear_all_cache' ) );
			// Bounded-cache stability (issue #1162): any plugin/theme update
			// auto-purges minify output + page cache so layout never goes
			// stale. Fail-open via on_extension_update(); builder-specific
			// purges stay in Builder_Purge_Watcher.
			add_action( 'upgrader_process_complete', array( __CLASS__, 'on_extension_update' ), 20, 2 );
		}

		/**
		 * Register third-party integration hooks (LiteSpeed sync, ESI bridge).
		 *
		 * Extracted from {@see setup_hooks()}.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		private function register_integration_hooks(): void {
			// LS-202: LiteSpeed → WPPO purge sync (litespeed_purged_all/post/purge_finalize).
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'init' ) ) {
				LiteSpeed_Integration::init();
			}
			// P5 ESI bridge (Enterprise only — OLS has no ESI). Gated on the
			// LiteSpeed stack check with a no-autoload class probe so a
			// non-LiteSpeed frontend never lazy-loads ESI via the autoloader
			// (issue #1443). LiteSpeed_Integration::init() above stays
			// unconditional: it is always loaded and self-gates internally.
			if ( self::should_load_litespeed_stack() && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', false ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', 'init' ) ) {
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
		 * constructor defaults already match. The check is idempotent (key presence is the
		 * marker), so no extra option row is ever allocated — fresh installs create zero
		 * migration rows and steady-state requests perform zero migration writes.
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

			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				// Fresh install (or no stored settings): constructor defaults already match
				// WP 6.9+ behavior, so there is nothing to migrate and nothing to record.
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( ! array_key_exists( 'blockAssetsOnDemand', $file ) ) {
				$stored['file_optimisation'] = $file + array( 'blockAssetsOnDemand' => true );
				Util::save_settings( $stored );

				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					$this->options['file_optimisation'] = array();
				}
				$this->options['file_optimisation']['blockAssetsOnDemand'] = true;

				Log::add( __( 'Enabled on-demand block asset loading to match the WordPress 6.9 default.', 'performance-optimisation' ) );
			}
		}

		/**
		 * One-time backfill for the CCSS inline size cap.
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `ccssMaxSize` key (key absent) are backfilled
		 * with the 20 KB default; any stored explicit value is preserved
		 * verbatim, and fresh installs with no stored option are skipped
		 * because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public function maybe_migrate_ccss_max_size(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( array_key_exists( 'ccssMaxSize', $file ) ) {
				return;
			}

			$stored['file_optimisation'] = $file + array( 'ccssMaxSize' => 20480 );
			Util::save_settings( $stored );

			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			$this->options['file_optimisation']['ccssMaxSize'] = 20480;

			Log::add( __( 'Added default Critical CSS size cap (20 KB).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the Critical CSS user safelist (issue #1038).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `ccssSafelistExtra` key (key absent) are
		 * backfilled with the empty default; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value. Uses per-site `get_option()` so
		 * multisite sites migrate independently with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public function maybe_migrate_ccss_safelist(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( array_key_exists( 'ccssSafelistExtra', $file ) ) {
				return;
			}

			$stored['file_optimisation'] = $file + array( 'ccssSafelistExtra' => '' );
			Util::save_settings( $stored );

			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			$this->options['file_optimisation']['ccssSafelistExtra'] = '';

			Log::add( __( 'Added default Critical CSS safelist (empty, current behaviour kept).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the RUM-weighted CSS queue keys (issue #1164).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate any of the `ccssQueueCap` / `usedCssQueueCap` /
		 * `ccssViewportVariants` / `usedCSSDeliveryMode` / `ccssGenTimeout` keys
		 * (key absent) are backfilled with the fail-open defaults; any stored
		 * explicit value is preserved verbatim, and fresh installs with no
		 * stored option are skipped because the constructor defaults already
		 * match. The check is idempotent (key presence is the marker), so no
		 * extra option row is needed. In-memory options are synced too so the
		 * current request observes the backfilled values. Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0 Also backfills the 25s `ccssGenTimeout` generation budget.
		 * @since 2.3.0 Also backfills the #1388 keys (`ccssInlineBudgetKb`,
		 *        `ccssCommerceExclude`, `ccssChecksumRegen`).
		 */
		public function maybe_migrate_css_queue_defaults(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			$defaults = array(
				'ccssQueueCap'         => 5,
				'usedCssQueueCap'      => 50,
				'ccssViewportVariants' => false,
				'usedCSSDeliveryMode'  => 'file',
				'ccssGenTimeout'       => 25,
				'ccssInlineBudgetKb'   => 14,
				'ccssCommerceExclude'  => true,
				'ccssChecksumRegen'    => true,
			);
			$changed  = false;
			foreach ( $defaults as $key => $default ) {
				if ( ! array_key_exists( $key, $file ) ) {
					$file[ $key ] = $default;
					$changed      = true;
				}
			}
			if ( ! $changed ) {
				return;
			}

			$stored['file_optimisation'] = $file;
			Util::save_settings( $stored );

			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			foreach ( $defaults as $key => $default ) {
				if ( ! array_key_exists( $key, $this->options['file_optimisation'] ) ) {
					$this->options['file_optimisation'][ $key ] = $default;
				}
			}

			Log::add( __( 'Added default RUM-weighted CSS queue settings, CCSS generation timeout (25s, single-variant behaviour kept), 14 KB inline budget, commerce exclusion, and checksum regen.', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the RUM-weighted top-URL prefetch cap (issue #1183).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `speculationTopUrlsLimit` key (key absent) are
		 * backfilled with the 2-URL default; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value. Uses per-site `get_option()` so
		 * multisite sites migrate independently with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_speculation_top_urls(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$preload = isset( $stored['preload_settings'] ) && is_array( $stored['preload_settings'] ) ? $stored['preload_settings'] : array();

			if ( array_key_exists( 'speculationTopUrlsLimit', $preload ) ) {
				return;
			}

			$preload['speculationTopUrlsLimit'] = 2;
			$stored['preload_settings']         = $preload;
			Util::save_settings( $stored );

			if ( ! isset( $this->options['preload_settings'] ) || ! is_array( $this->options['preload_settings'] ) ) {
				$this->options['preload_settings'] = array();
			}
			if ( ! array_key_exists( 'speculationTopUrlsLimit', $this->options['preload_settings'] ) ) {
				$this->options['preload_settings']['speculationTopUrlsLimit'] = 2;
			}

			Log::add( __( 'Added default RUM-weighted top-URL prefetch limit (2 URLs, prerender stays guarded).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the high-value prerender list toggle (issue #1237).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `speculationPrerenderList` key (key absent) are
		 * backfilled with the off default; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match. The check is
		 * idempotent (key presence is the marker), so no extra option row is
		 * needed. In-memory options are synced too so the current request
		 * observes the backfilled value. Uses per-site `get_option()` so
		 * multisite sites migrate independently with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_speculation_prerender_list(): void {
			if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
				return;
			}
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$preload = isset( $stored['preload_settings'] ) && is_array( $stored['preload_settings'] ) ? $stored['preload_settings'] : array();

			if ( array_key_exists( 'speculationPrerenderList', $preload ) ) {
				return;
			}

			$preload['speculationPrerenderList'] = false;
			$stored['preload_settings']          = $preload;
			Util::save_settings( $stored );

			if ( ! isset( $this->options['preload_settings'] ) || ! is_array( $this->options['preload_settings'] ) ) {
				$this->options['preload_settings'] = array();
			}
			if ( ! array_key_exists( 'speculationPrerenderList', $this->options['preload_settings'] ) ) {
				$this->options['preload_settings']['speculationPrerenderList'] = false;
			}

			Log::add( __( 'Added default high-value prerender list toggle (off, current prefetch behavior kept).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the RUM beacon sample rate (issue #1214).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `rum_sample_rate` key (key absent) are
		 * backfilled with the 100 default (unsampled current behavior); any
		 * stored explicit value is preserved verbatim, and fresh installs with
		 * no stored option are skipped because the constructor defaults already
		 * match. The check is idempotent (key presence is the marker), so no
		 * extra option row is needed. In-memory options are synced too so the
		 * current request observes the backfilled value. Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_rum_sample_rate(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$audit = isset( $stored['performance_audit'] ) && is_array( $stored['performance_audit'] ) ? $stored['performance_audit'] : array();

			if ( array_key_exists( 'rum_sample_rate', $audit ) ) {
				return;
			}

			$default_rate = class_exists( 'PerformanceOptimise\Inc\RUM' ) ? \PerformanceOptimise\Inc\RUM::RUM_SAMPLE_RATE_DEFAULT : 100;

			$audit['rum_sample_rate']    = $default_rate;
			$stored['performance_audit'] = $audit;
			Util::save_settings( $stored );

			if ( ! isset( $this->options['performance_audit'] ) || ! is_array( $this->options['performance_audit'] ) ) {
				$this->options['performance_audit'] = array();
			}
			if ( ! array_key_exists( 'rum_sample_rate', $this->options['performance_audit'] ) ) {
				$this->options['performance_audit']['rum_sample_rate'] = $default_rate;
			}

			Log::add( __( 'Added default RUM beacon sample rate (100 percent, unsampled current behavior kept).', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the missing-alt autofill toggle and the
		 * longest-edge downscale cap (issue #985).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `autoAltText` / `maxLongestEdgePx` keys (key
		 * absent) are backfilled with the fail-open defaults (`false` /
		 * `2560`); any stored explicit value is preserved verbatim, and fresh
		 * installs with no stored option are skipped because the constructor
		 * defaults already match. The check is idempotent (key presence is
		 * the marker), so no extra option row is needed. In-memory options
		 * are synced too so the current request observes the backfilled
		 * values. Uses per-site `get_option()` so multisite sites migrate
		 * independently with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public function maybe_migrate_image_alt_edge_defaults(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$image = isset( $stored['image_optimisation'] ) && is_array( $stored['image_optimisation'] ) ? $stored['image_optimisation'] : array();

			$changed = false;
			if ( ! array_key_exists( 'autoAltText', $image ) ) {
				$image['autoAltText'] = false;
				$changed              = true;
			}
			if ( ! array_key_exists( 'maxLongestEdgePx', $image ) ) {
				$image['maxLongestEdgePx'] = 2560;
				$changed                   = true;
			}

			if ( ! $changed ) {
				return;
			}

			$stored['image_optimisation'] = $image;
			Util::save_settings( $stored );

			if ( ! isset( $this->options['image_optimisation'] ) || ! is_array( $this->options['image_optimisation'] ) ) {
				$this->options['image_optimisation'] = array();
			}
			$this->options['image_optimisation'] = array_merge( $this->options['image_optimisation'], $image );

			Log::add( __( 'Added default image alt autofill and longest-edge cap settings.', 'performance-optimisation' ) );
		}

		/**
		 * One-time backfill for the unified safe-mode kill switch (issue #1098).
		 *
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `safeMode` key (key absent) are backfilled with
		 * the off default (`false`, current behaviour kept); any stored
		 * explicit value is preserved verbatim, and fresh installs with no
		 * stored option are skipped because the constructor defaults already
		 * match. The check is idempotent (key presence is the marker), so no
		 * extra option row is needed. In-memory options are synced too so the
		 * current request observes the backfilled value. Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_safe_mode(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

			if ( array_key_exists( 'safeMode', $file ) ) {
				return;
			}

			$stored['file_optimisation'] = $file + array( 'safeMode' => false );
			Util::save_settings( $stored );

			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			$this->options['file_optimisation']['safeMode'] = false;
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
		 * Runs on admin_init; in-memory default is applied in __construct so
		 * front-end requests never pay for a DB write. Defaults to on
		 * (builder-proof by default). Multisite-safe: per-site
		 * get_option() so sites migrate independently.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_elementor_safe_mode(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array".
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}
			$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();
			if ( array_key_exists( 'elementorSafeMode', $file ) ) {
				return;
			}
			$stored['file_optimisation'] = $file + array( 'elementorSafeMode' => true );
			Util::save_settings( $stored );
			if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
				$this->options['file_optimisation'] = array();
			}
			$this->options['file_optimisation']['elementorSafeMode'] = true;
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
		 * Runs on `admin_init` (not the constructor) so a cacheable front-end
		 * request never triggers a settings write. Only installs whose stored
		 * settings predate the `autoLcpPreload` / `autoDiscoverFonts` keys
		 * (key absent) are backfilled with the off defaults (`false`, manual
		 * lists keep winning); any stored explicit value is preserved
		 * verbatim, and fresh installs with no stored option are skipped
		 * because the constructor defaults already match. Idempotent (key
		 * presence is the marker). Uses per-site `get_option()` so multisite
		 * sites migrate independently with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_preload_auto_defaults(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (false) from "stored array", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$stored = get_option( 'wppo_settings' );
			if ( ! is_array( $stored ) ) {
				return;
			}

			$preload = isset( $stored['preload_settings'] ) && is_array( $stored['preload_settings'] ) ? $stored['preload_settings'] : array();

			$changed = false;
			if ( ! array_key_exists( 'autoLcpPreload', $preload ) ) {
				$preload['autoLcpPreload'] = false;
				$changed                   = true;
			}
			if ( ! array_key_exists( 'autoDiscoverFonts', $preload ) ) {
				$preload['autoDiscoverFonts'] = false;
				$changed                      = true;
			}

			if ( ! $changed ) {
				return;
			}

			$stored['preload_settings'] = $preload;
			Util::save_settings( $stored );

			if ( ! isset( $this->options['preload_settings'] ) || ! is_array( $this->options['preload_settings'] ) ) {
				$this->options['preload_settings'] = array();
			}
			$this->options['preload_settings'] = array_merge( $this->options['preload_settings'], $preload );

			Log::add( __( 'Added default automatic LCP preload and font discovery settings (both off; manual lists keep winning).', 'performance-optimisation' ) );
		}

		/**
		 * Backfill the additive Redis outage status flag (issue #1233).
		 *
		 * Adds `object_cache.outage_bypassed = false` to stored settings that
		 * predate the key. Runs on `admin_init` (not the constructor) so a
		 * cacheable front-end request never triggers a settings write. Fresh
		 * installs with no stored option are skipped (absent key reads as
		 * "not bypassed"). Idempotent (key presence is the marker). Uses
		 * per-site `get_option()` so multisite sites migrate independently
		 * with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_object_cache_outage_flag(): void {
			try {
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// Cheap early-return through the already-loaded memo: after
				// migration completes this avoids one extra option read per
				// admin page.
				if ( isset( $this->options['object_cache'] ) && is_array( $this->options['object_cache'] ) && array_key_exists( 'outage_bypassed', $this->options['object_cache'] ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}

				$oc = isset( $stored['object_cache'] ) && is_array( $stored['object_cache'] ) ? $stored['object_cache'] : array();

				if ( array_key_exists( 'outage_bypassed', $oc ) ) {
					return;
				}

				$stored['object_cache'] = $oc + array( 'outage_bypassed' => false );
				Util::save_settings( $stored );

				if ( ! isset( $this->options['object_cache'] ) || ! is_array( $this->options['object_cache'] ) ) {
					$this->options['object_cache'] = array();
				}
				$this->options['object_cache']['outage_bypassed'] = false;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * One-time backfill for RUM-segmented speculation auto-tune (issue #1425).
		 *
		 * Adds the additive `ai_adaptive.speculation_autotune_enabled`,
		 * `ai_adaptive.speculation_min_samples`, and
		 * `ai_adaptive.speculation_max_urls` keys to stored settings that
		 * predate them. Runs on `admin_init` (not the constructor) so a
		 * cacheable front-end request never triggers a settings write. Only
		 * installs missing a key are backfilled (opt-in off, min 20, max 5);
		 * any stored explicit value is preserved verbatim, and fresh installs
		 * with no stored option are skipped because the constructor defaults
		 * already match. Idempotent (key presence is the marker). Uses
		 * per-site `get_option()` so multisite sites migrate independently
		 * with no cross-site leakage.
		 *
		 * @return void
		 * @since 2.3.0
		 */
		public function maybe_migrate_ai_speculation_autotune(): void {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return;
				}
				// Cheap early-return through the already-loaded memo: after
				// migration completes this avoids one extra option read per
				// admin page.
				if ( isset( $this->options['ai_adaptive'] ) && is_array( $this->options['ai_adaptive'] )
					&& array_key_exists( 'speculation_autotune_enabled', $this->options['ai_adaptive'] )
					&& array_key_exists( 'speculation_min_samples', $this->options['ai_adaptive'] )
					&& array_key_exists( 'speculation_max_urls', $this->options['ai_adaptive'] ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}

				$ai = isset( $stored['ai_adaptive'] ) && is_array( $stored['ai_adaptive'] ) ? $stored['ai_adaptive'] : array();

				$changed = false;
				if ( ! array_key_exists( 'speculation_autotune_enabled', $ai ) ) {
					$ai['speculation_autotune_enabled'] = false;
					$changed                            = true;
				}
				if ( ! array_key_exists( 'speculation_min_samples', $ai ) ) {
					$ai['speculation_min_samples'] = 20;
					$changed                       = true;
				}
				if ( ! array_key_exists( 'speculation_max_urls', $ai ) ) {
					$ai['speculation_max_urls'] = 5;
					$changed                    = true;
				}
				if ( ! $changed ) {
					return;
				}

				$stored['ai_adaptive'] = $ai;
				Util::save_settings( $stored );

				if ( ! isset( $this->options['ai_adaptive'] ) || ! is_array( $this->options['ai_adaptive'] ) ) {
					$this->options['ai_adaptive'] = array();
				}
				foreach ( array(
					'speculation_autotune_enabled' => false,
					'speculation_min_samples'      => 20,
					'speculation_max_urls'         => 5,
				) as $key => $default ) {
					if ( ! array_key_exists( $key, $this->options['ai_adaptive'] ) ) {
						$this->options['ai_adaptive'][ $key ] = $default;
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
				Log::add( __( 'Added default RUM-segmented speculation auto-tune settings (off; manual speculation settings untouched).', 'performance-optimisation' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * One-time backfill for anomaly detector v2 keys (issue #1313).
		 *
		 * Adds the additive `ai_adaptive.anomaly_band_window`,
		 * `ai_adaptive.anomaly_recovery_days`, and
		 * `ai_adaptive.deploy_notes` keys to stored settings that predate
		 * them. Runs on `admin_init` (not the constructor) so a cacheable
		 * front-end request never triggers a settings write. Only installs
		 * missing a key are backfilled; any stored explicit value is
		 * preserved verbatim, and fresh installs with no stored option are
		 * skipped because the constructor defaults already match.
		 * Idempotent (key presence is the marker). Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @return void
		 * @since 2.3.0
		 */
		public function maybe_migrate_ai_anomaly_v2(): void {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return;
				}
				if ( isset( $this->options['ai_adaptive'] ) && is_array( $this->options['ai_adaptive'] )
					&& array_key_exists( 'anomaly_band_window', $this->options['ai_adaptive'] )
					&& array_key_exists( 'anomaly_recovery_days', $this->options['ai_adaptive'] )
					&& array_key_exists( 'deploy_notes', $this->options['ai_adaptive'] ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}

				$ai = isset( $stored['ai_adaptive'] ) && is_array( $stored['ai_adaptive'] ) ? $stored['ai_adaptive'] : array();

				$changed = false;
				if ( ! array_key_exists( 'anomaly_band_window', $ai ) ) {
					$ai['anomaly_band_window'] = 10;
					$changed                   = true;
				}
				if ( ! array_key_exists( 'anomaly_recovery_days', $ai ) ) {
					$ai['anomaly_recovery_days'] = 3;
					$changed                     = true;
				}
				if ( ! array_key_exists( 'deploy_notes', $ai ) ) {
					$ai['deploy_notes'] = array();
					$changed            = true;
				}
				if ( ! $changed ) {
					return;
				}

				$stored['ai_adaptive'] = $ai;
				Util::save_settings( $stored );

				if ( ! isset( $this->options['ai_adaptive'] ) || ! is_array( $this->options['ai_adaptive'] ) ) {
					$this->options['ai_adaptive'] = array();
				}
				foreach ( array(
					'anomaly_band_window'   => 10,
					'anomaly_recovery_days' => 3,
					'deploy_notes'          => array(),
				) as $key => $default ) {
					if ( ! array_key_exists( $key, $this->options['ai_adaptive'] ) ) {
						$this->options['ai_adaptive'][ $key ] = $default;
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
				Log::add( __( 'Added default anomaly detector v2 settings (band window, recovery days, deploy notes).', 'performance-optimisation' ) );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * One-time backfill for comment-image hardening (issue #1271).
		 *
		 * Adds `image_optimisation.hardenCommentImages = true` to stored
		 * settings that predate the key so hostile comment markup (img
		 * onerror, picture source, inline on* handlers, scriptable URLs)
		 * is stripped before next-gen/lazy rewriting. Runs on `admin_init`
		 * (not the constructor) so a cacheable front-end request never
		 * triggers a settings write. Fresh installs with no stored option
		 * are skipped (absent key reads as enabled via the in-memory
		 * default). Idempotent (key presence is the marker). Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_comment_image_hardening(): void {
			try {
				// Capability-gated: the migration performs a settings
				// write plus full local + edge cache purges, so it must
				// only run for administrators (first admin_init by an
				// editor must not trigger it).
				if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// NOTE: no in-memory `$this->options` early-return here —
				// the constructor default (`hardenCommentImages => true`)
				// would make such a guard always hit and the DB backfill
				// dead code. The stored option is the only marker.
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}
				$image = isset( $stored['image_optimisation'] ) && is_array( $stored['image_optimisation'] ) ? $stored['image_optimisation'] : array();

				if ( array_key_exists( 'hardenCommentImages', $image ) ) {
					return;
				}

				$stored['image_optimisation'] = $image + array( 'hardenCommentImages' => true );
				Util::save_settings( $stored );

				// Keep the in-request settings memo in parity (sibling
				// migration paths call set_settings_cache(); without it a
				// get_settings() memo loaded earlier in this admin_init
				// request would stay pre-migration).
				try {
					if ( class_exists( 'PerformanceOptimise\\Inc\\Util' ) ) {
						\PerformanceOptimise\Inc\Util::set_settings_cache( $stored );
					}
				} catch ( \Throwable $cache_error ) {
					unset( $cache_error );
				}

				// First migration only: purge the static HTML cache so
				// pages poisoned before hardening (served verbatim by
				// advanced-cache.php) are regenerated sanitized. Fan out
				// to edge caches (Cloudflare/Bunny/Varnish) so poisoned
				// edge copies do not survive the local purge.
				try {
					if ( class_exists( 'PerformanceOptimise\\Inc\\Cache' ) ) {
						\PerformanceOptimise\Inc\Cache::clear_cache();
					}
				} catch ( \Throwable $purge_error ) {
					unset( $purge_error );
				}
				try {
					if ( class_exists( 'PerformanceOptimise\\Inc\\Edge_Purger' ) ) {
						\PerformanceOptimise\Inc\Edge_Purger::purge_all();
					}
				} catch ( \Throwable $edge_error ) {
					unset( $edge_error );
				}

				if ( ! isset( $this->options['image_optimisation'] ) || ! is_array( $this->options['image_optimisation'] ) ) {
					$this->options['image_optimisation'] = array();
				}
				$this->options['image_optimisation']['hardenCommentImages'] = true;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Backfill the additive builder purge watcher keys (issue #1288).
		 *
		 * Adds `file_optimisation.builderPurgeWatcher = true` and
		 * `file_optimisation.builderPurgeDriftLog = true` to stored settings
		 * that predate the keys. Runs on `admin_init` (not the constructor)
		 * so a cacheable front-end request never triggers a settings write.
		 * Fresh installs with no stored option are skipped (absent keys read
		 * as enabled). Idempotent (key presence is the marker). Uses per-site
		 * `get_option()` so multisite sites migrate independently with no
		 * cross-site leakage.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_builder_watcher(): void {
			try {
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				// Gate on the persisted row (not the in-memory backfill) so the
				// migration branch stays reachable and single-key rows heal.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}
				$file_opts = $stored['file_optimisation'] ?? null;
				if ( is_array( $file_opts ) && array_key_exists( 'builderPurgeWatcher', $file_opts ) && array_key_exists( 'builderPurgeDriftLog', $file_opts ) ) {
					return;
				}

				$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

				$changed = false;
				if ( ! array_key_exists( 'builderPurgeWatcher', $file ) ) {
					$file['builderPurgeWatcher'] = true;
					$changed                     = true;
				}
				if ( ! array_key_exists( 'builderPurgeDriftLog', $file ) ) {
					$file['builderPurgeDriftLog'] = true;
					$changed                      = true;
				}

				if ( ! $changed ) {
					return;
				}

				$stored['file_optimisation'] = $file;
				$updated                     = Util::save_settings( $stored );
				if ( ! $updated ) {
					return;
				}

				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					$this->options['file_optimisation'] = array();
				}
				$this->options['file_optimisation'] = array_merge( $this->options['file_optimisation'], $file );
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Backfill the additive auto third-party delay key (issue #1314).
		 *
		 * Runs on admin_init; in-memory default is applied in __construct so
		 * front-end requests never pay for a DB write. Defaults to off so
		 * upgraded installs keep current behaviour. Also backfills the
		 * additive one-click preset level key (issue #1385, defaults to
		 * safe). Multisite-safe: per-site get_option() so sites migrate
		 * independently. Fail-open: never fatals.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public function maybe_migrate_third_party_auto(): void {
			try {
				// Capability-gated like sibling migrations: the migration
				// performs a settings write, so it must only run for
				// administrators (first admin_init by an editor must not
				// trigger the DB-write path).
				if ( function_exists( 'current_user_can' ) && ! current_user_can( 'manage_options' ) ) {
					return;
				}
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array", which Util::get_settings()
				// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
				// Gate on the persisted row (not the in-memory backfill) so the
				// migration branch stays reachable and single-key rows heal.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return;
				}
				$file_opts = $stored['file_optimisation'] ?? null;
				$auto_ok   = is_array( $file_opts ) && isset( $file_opts['delayJSThirdPartyAuto'] ) && is_bool( $file_opts['delayJSThirdPartyAuto'] );
				$preset_ok = is_array( $file_opts ) && isset( $file_opts['delayJSPreset'] ) && is_string( $file_opts['delayJSPreset'] ) && in_array( strtolower( trim( $file_opts['delayJSPreset'] ) ), array( 'safe', 'balanced', 'aggressive' ), true );
				if ( $auto_ok && $preset_ok ) {
					return;
				}

				$file = isset( $stored['file_optimisation'] ) && is_array( $stored['file_optimisation'] ) ? $stored['file_optimisation'] : array();

				if ( ! $auto_ok ) {
					$file['delayJSThirdPartyAuto'] = false;
				}
				if ( ! $preset_ok ) {
					$file['delayJSPreset'] = 'safe';
				}

				$stored['file_optimisation'] = $file;
				$updated                     = Util::save_settings( $stored );
				if ( ! $updated ) {
					return;
				}

				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					$this->options['file_optimisation'] = array();
				}
				if ( ! $auto_ok ) {
					$this->options['file_optimisation']['delayJSThirdPartyAuto'] = false;
				}
				if ( ! $preset_ok ) {
					$this->options['file_optimisation']['delayJSPreset'] = 'safe';
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					Util::set_settings_cache( $stored );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
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
					Util::save_settings( $value );
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
			$options = Util::get_settings();
			if ( ! empty( $options['preload_settings']['preloadSitemap'] ) && function_exists( 'as_enqueue_async_action' ) && function_exists( 'as_has_scheduled_action' ) ) {
				$url = get_permalink( $post_id );
				if ( is_string( $url ) && '' !== $url ) {
					// Never schedule preload work for Woo dynamic routes.
					// Single source: Util::is_woo_excluded_url() covers cart /
					// checkout / account + custom slugs (safe-mode gated) plus
					// Store API / wc-ajax / add-to-cart / faceted /
					// functional-query URLs (unconditional), so warm-path and
					// serve-path verdicts cannot drift. Fail-closed: any
					// detection failure skips scheduling (never warm dynamic).
					if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_excluded_url' ) ) {
						try {
							if ( Util::is_woo_excluded_url( $url ) ) {
								return;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
							return;
						}
					} else {
						// Mixed-version fallback: mirror the Cron batch
						// fallback — unconditional Store API / wc-ajax /
						// faceted / generic-query guards plus safe-mode-gated
						// dynamic-path, so a stale Util can never warm
						// faceted or wc-ajax URLs.
						try {
							$warm_path       = (string) wp_parse_url( $url, PHP_URL_PATH );
							$warm_qs         = (string) wp_parse_url( $url, PHP_URL_QUERY );
							$warm_rest_route = '';
							if ( '' !== $warm_qs ) {
								$warm_params = array();
								parse_str( $warm_qs, $warm_params );
								if ( isset( $warm_params['rest_route'] ) && is_string( $warm_params['rest_route'] ) ) {
									$warm_rest_route = $warm_params['rest_route'];
								}
							}
							if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_request' ) ) {
								if ( Util::is_woo_store_api_request( $warm_path, $warm_qs, '' ) || ( '' !== $warm_rest_route && Util::is_woo_store_api_request( $warm_path, '', $warm_rest_route ) ) ) {
									return;
								}
							} elseif ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_path' ) && ( Util::is_woo_store_api_path( $warm_path ) || ( '' !== $warm_rest_route && Util::is_woo_store_api_path( $warm_rest_route ) ) ) ) {
								return;
							} elseif ( (bool) preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . ltrim( $warm_path, '/' ) ) || ( '' !== $warm_qs && (bool) preg_match( '#rest_route=[^&]*(?:wc/store|wcstore)#i', rawurldecode( $warm_qs ) ) ) ) {
								return;
							}
							if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_ajax_request' ) ) {
								if ( Util::is_woo_ajax_request( $warm_path, $warm_qs ) ) {
									return;
								}
							} elseif ( (bool) preg_match( '#(^|/)wc-ajax(/|$)#i', '/' . ltrim( (string) rawurldecode( $warm_path ), '/' ) ) || ( '' !== $warm_qs && (bool) preg_match( '/(?:^|[&;])wc-ajax(?:=|&|;|$)/i', $warm_qs ) ) ) {
								return;
							}
							if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_faceted_query' ) ) {
								if ( '' !== $warm_qs && Util::is_woo_faceted_query( $warm_qs ) ) {
									return;
								}
							} elseif ( '' !== $warm_qs && (bool) preg_match( '/(?:^|[&;])(?:filter_[^=&]*|query_type_[^=&]*|min_price|max_price|rating_filter|orderby|product_cat|pa_[^=&]*|attribute_[^=&]*|gpf_[^=&]*)(?:=|&|;|$)/i', $warm_qs ) ) {
								return;
							}
							if ( method_exists( 'PerformanceOptimise\Inc\Util', 'has_uncacheable_query' ) ) {
								if ( '' !== $warm_qs && Util::has_uncacheable_query( $warm_qs ) ) {
									return;
								}
							} elseif ( '' !== $warm_qs ) {
								return;
							}
							if ( method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_dynamic_path' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) ) {
								if ( Util::is_woo_safe_mode_enabled( $options ) && Util::is_woo_dynamic_path( $warm_path ) ) {
									return;
								}
							}
						} catch ( \Throwable $e ) {
							unset( $e );
							return;
						}
					}
					$url = esc_url_raw( $url );
					// Atomic unique enqueue (issue #1310) closes the
					// check-then-act race. Util ships in-repo: called
					// directly (issue #1310 review) — its internal
					// function_exists + supports_* + try/catch already fails
					// open, so no method_exists/legacy branch is needed.
					if ( '' !== $url ) {
						Util::enqueue_unique_async_action( 'wppo_crawler_warm', array( $url ), 'performance_optimisation' );
					}
				}
			}
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

			$options = Util::get_settings();
			if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
				return;
			}

			if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
				return;
			}

			// Util ships in-repo: called directly (issue #1310 review) — its
			// internal function_exists + supports_* + try/catch already
			// fails open, so no method_exists/legacy branch is needed.
			Util::enqueue_unique_async_action(
				'wppo_used_css_generate',
				array( 'post_id' => $post_id ),
				'performance_optimisation'
			);
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
			// Mid-template cancel safety (issue #1386): a cancelled core
			// buffer can deliver a non-string into the filter. Fail open —
			// never fatal, never white-screen. Prefer the raw $output when
			// it carries page HTML so a cancelled $filtered_output can never
			// collapse the chain to a blank page.
			if ( ! is_string( $filtered_output ) ) {
				if ( is_string( $output ) && '' !== $output ) {
					return $output;
				}
				return '';
			}
			try {
				if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
					return $filtered_output;
				}
				// Safe-mode kill switch + nocache bypass (issue #1098): fail open
				// to the full stylesheet, settings preserved.
				if ( self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() ) || self::is_aggressive_bypass_active() ) {
					return $filtered_output;
				}

				// Nesting balance (issue #881): run the used-CSS pipeline at most
				// once per request (see Main::$used_css_buffer_enhanced).
				if ( $this->used_css_buffer_enhanced ) {
					return $filtered_output;
				}
				$this->used_css_buffer_enhanced = true;

				if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
					$filtered_output = $this->google_fonts->process_buffer( $filtered_output );
				}

				$used_css = new \PerformanceOptimise\Inc\Used_CSS( $this->options );
				$result   = $used_css->process_buffer( $filtered_output );
				return is_string( $result ) ? $result : $filtered_output;
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO used-CSS buffer processing failed.', array( 'exception' => $e ) );
				if ( is_string( $filtered_output ) && '' !== $filtered_output ) {
					return $filtered_output;
				}
				if ( is_string( $output ) && '' !== $output ) {
					return $output;
				}
				return '';
			}
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
			// Single-buffer routing (issue #1386): when the core
			// template-enhancement buffer is available (WP 6.9+), core owns
			// output capture and the 6.9+ filter path
			// (process_used_css_only) is responsible. Never open a private
			// buffer on 6.9+ — a runtime opt-out degrades to uncached
			// streaming output.
			try {
				if ( self::should_use_core_template_buffer() ) {
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Nesting guard (issue #881, pre-6.9 defense in depth): when
			// the core buffer is already active for this request, the
			// filter path owns used-CSS and a private buffer must not
			// stack on top.
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
				try {
					if ( wp_should_output_buffer_template_for_enhancement() ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( ! $this->should_optimise_for_logged_in() || is_admin() ) {
				return;
			}
			// Safe-mode kill switch + nocache bypass (issue #1098).
			if ( self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() ) || self::is_aggressive_bypass_active() ) {
				return;
			}
			ob_start( array( $this, 'process_used_css_capture' ) );
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
			// Single-buffer routing (issue #1386): when the core
			// template-enhancement buffer is available (WP 6.9+), the 6.9+
			// filter path (prioritize_lcp_in_buffer on
			// wp_template_enhancement_output_buffer) is responsible.
			// Never open a private buffer on 6.9+ — a runtime opt-out
			// degrades to uncached streaming output.
			try {
				if ( self::should_use_core_template_buffer() ) {
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Nesting guard (issue #881, pre-6.9 defense in depth): when
			// the core buffer is already active for this request, the
			// filter path owns LCP prioritization and a private buffer
			// must not stack on top.
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
				try {
					if ( wp_should_output_buffer_template_for_enhancement() ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
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
			// Mid-template cancel safety (issue #1386): an output-buffer
			// callback must always return a string — a non-string input
			// (false/null from a cancelled buffer) fails open to ''.
			if ( ! is_string( $buffer ) ) {
				return '';
			}
			if ( '' === $buffer ) {
				return $buffer;
			}

			// Fail open returns the PRISTINE input — stash it before mutation.
			$original = $buffer;
			try {
				// Nesting balance (issue #881): run the used-CSS pipeline at
				// most once per request (see Main::$used_css_buffer_enhanced).
				if ( $this->used_css_buffer_enhanced ) {
					return $original;
				}
				$this->used_css_buffer_enhanced = true;

				if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
					$buffer = $this->google_fonts->process_buffer( $buffer );
				}

				$used_css = new \PerformanceOptimise\Inc\Used_CSS( $this->options );
				$result   = $used_css->process_buffer( $buffer );
				return is_string( $result ) ? $result : $original;
			} catch ( \Throwable $e ) {
				// Fail open: return the unprocessed buffer rather than dropping
				// the page content. Mirrors the wppo_debug_log convention used by
				// the HTML minifier and Cloudflare purger.
				do_action( 'wppo_debug_log', 'WPPO used-CSS buffer processing failed.', array( 'exception' => $e ) );
				return $original;
			}
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
			$safe_options = $this->options;
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
						'pagespeedApiKeyConfigured' => ! empty( $this->options['performance_audit']['pagespeed_api_key'] ),
						'highValueUrls'             => $this->options['performance_audit']['high_value_urls'] ?? array(), // High-value URLs from settings (edited in Tools tab; consumed by preload/PageSpeed rescan cron + llms.txt proxy).
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
						$idle_timeout = ! empty( $this->options['file_optimisation']['delayJSIdleTimeout'] )
						? absint( $this->options['file_optimisation']['delayJSIdleTimeout'] )
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
			if ( empty( $this->options['file_optimisation']['deferJS'] ) ) {
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
			$raw      = (string) ( $this->options['file_optimisation']['excludeDeferJS'] ?? '' );
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
		 */
		public static function supports_native_defer_strategy(): bool {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				$wp_version = $GLOBALS['wp_version'];
			} elseif ( function_exists( 'get_bloginfo' ) ) {
				$wp_version = (string) get_bloginfo( 'version' );
			} else {
				return false;
			}
			if ( version_compare( $wp_version, '6.3-alpha', '<' ) ) {
				return false;
			}
			if ( ! function_exists( 'wp_script_add_data' ) ) {
				return false;
			}
			if ( class_exists( 'WP_Scripts' ) && ! method_exists( 'WP_Scripts', 'get_eligible_loading_strategy' ) ) {
				return false;
			}
			return true;
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
		 */
		public static function supports_native_script_fetchpriority(): bool {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				$wp_version = $GLOBALS['wp_version'];
			} elseif ( function_exists( 'get_bloginfo' ) ) {
				$wp_version = (string) get_bloginfo( 'version' );
			} else {
				return false;
			}
			if ( version_compare( $wp_version, '6.9-alpha', '<' ) ) {
				return false;
			}
			if ( class_exists( 'WP_Script_Modules' ) ) {
				return method_exists( 'WP_Script_Modules', 'set_fetchpriority' );
			}
			return function_exists( 'wp_script_modules' ) && function_exists( 'wp_enqueue_script_module' );
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
		 */
		private function is_defer_eligible_for_handle( object $wp_scripts, string $handle, array $intended = array(), array &$checked = array() ): bool {
			if ( '' === $handle ) {
				return true;
			}
			if ( isset( $checked[ $handle ] ) ) {
				return true;
			}
			$checked[ $handle ] = true;

			try {
				if ( ! isset( $wp_scripts->registered ) || ! is_array( $wp_scripts->registered ) ) {
					return true;
				}
				if ( ! isset( $wp_scripts->registered[ $handle ] ) ) {
					return true;
				}
				$registered = $wp_scripts->registered[ $handle ];
				$extra      = null;
				if ( is_object( $registered ) && isset( $registered->extra ) ) {
					$extra = $registered->extra;
				} elseif ( is_array( $registered ) && isset( $registered['extra'] ) ) {
					$extra = $registered['extra'];
				}
				if ( is_array( $extra ) ) {
					if ( ! empty( $extra['after'] ) ) {
						return false;
					}
					if ( isset( $extra['type'] ) && 'module' === strtolower( trim( (string) $extra['type'] ) ) ) {
						return false;
					}
				}

				if ( ! isset( $wp_scripts->queue ) || ! is_array( $wp_scripts->queue ) ) {
					return true;
				}
				$has_get_data = is_callable( array( $wp_scripts, 'get_data' ) );
				foreach ( $wp_scripts->queue as $queued ) {
					$queued = (string) $queued;
					if ( '' === $queued || $queued === $handle ) {
						continue;
					}
					if ( ! isset( $wp_scripts->registered[ $queued ] ) ) {
						continue;
					}
					$dependent = $wp_scripts->registered[ $queued ];
					$deps      = null;
					if ( is_object( $dependent ) ) {
						if ( isset( $dependent->deps ) ) {
							$deps = $dependent->deps;
						} elseif ( isset( $dependent->dependencies ) ) {
							$deps = $dependent->dependencies;
						}
					} elseif ( is_array( $dependent ) ) {
						if ( isset( $dependent['deps'] ) ) {
							$deps = $dependent['deps'];
						} elseif ( isset( $dependent['dependencies'] ) ) {
							$deps = $dependent['dependencies'];
						}
					}
					if ( ! is_array( $deps ) || ! in_array( $handle, $deps, true ) ) {
						continue;
					}
					$strategy = false;
					if ( $has_get_data ) {
						try {
							$strategy = $wp_scripts->get_data( $queued, 'strategy' );
						} catch ( \Throwable $e ) {
							unset( $e );
							$strategy = false;
						}
					}
					if ( false === $strategy || null === $strategy ) {
						$dependent_extra = null;
						if ( is_object( $dependent ) && isset( $dependent->extra ) ) {
							$dependent_extra = $dependent->extra;
						} elseif ( is_array( $dependent ) && isset( $dependent['extra'] ) ) {
							$dependent_extra = $dependent['extra'];
						}
						if ( is_array( $dependent_extra ) && isset( $dependent_extra['strategy'] ) && is_string( $dependent_extra['strategy'] ) ) {
							$strategy = $dependent_extra['strategy'];
						}
					}
					$is_deferred_dependent = is_string( $strategy ) && in_array( strtolower( trim( $strategy ) ), array( 'async', 'defer' ), true );
					if ( ! $is_deferred_dependent && ! in_array( $queued, $intended, true ) ) {
						return false;
					}
					// Transitive gate (issue #1466 review): a deferred (or
					// about-to-defer) dependent that is itself ineligible
					// poisons this handle, mirroring core's
					// filter_eligible_strategies() recursion.
					if ( ! $this->is_defer_eligible_for_handle( $wp_scripts, $queued, $intended, $checked ) ) {
						return false;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
			return true;
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
		 */
		public static function should_use_core_template_buffer(): bool {
			try {
				if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
					$wp_version = $GLOBALS['wp_version'];
				} elseif ( function_exists( 'get_bloginfo' ) ) {
					$wp_version = (string) get_bloginfo( 'version' );
				} else {
					return false;
				}
				if ( '' === $wp_version || version_compare( $wp_version, '6.9-alpha', '<' ) ) {
					return false;
				}
				return function_exists( 'wp_should_output_buffer_template_for_enhancement' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
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
		 */
		private function get_filtered_deferred_fetchpriority( string $handle ): string {
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_deferred_fetchpriority' ) ) {
				return 'low';
			}
			try {
				/**
				 * Filters fetchpriority for each deferred handle.
				 *
				 * Default 'low' deprioritises deferred (non-render-blocking)
				 * scripts. Return 'high' for an LCP-critical handle, falsy to
				 * suppress, or 'auto' to defer to browser.
				 *
				 * @since 2.0.0
				 *
				 * @param string $fetchpriority Fetchpriority value.
				 * @param string $handle        Script handle.
				 */
				$fetchpriority = apply_filters( 'wppo_deferred_fetchpriority', 'low', $handle );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'low';
			}
			if ( ! is_string( $fetchpriority ) ) {
				return '';
			}
			$fetchpriority = strtolower( trim( $fetchpriority ) );
			if ( ! in_array( $fetchpriority, array( 'high', 'low', 'auto' ), true ) ) {
				return '';
			}
			return $fetchpriority;
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
		 */
		private function should_move_deferred_to_footer( string $handle ): bool {
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_deferred_in_footer' ) ) {
				return true;
			}
			try {
				/**
				 * Filters whether deferred classic scripts are moved to the footer.
				 *
				 * Default true on WP 6.9+ (native in_footer for deferred
				 * scripts). Return false for a handle that must stay in
				 * the head (e.g. document.write dependencies).
				 *
				 * @since 2.0.0
				 *
				 * @param bool   $in_footer Whether to set the footer group.
				 * @param string $handle    Script handle.
				 */
				$in_footer = apply_filters( 'wppo_deferred_in_footer', true, $handle );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
			return ! empty( $in_footer );
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
		 */
		public function add_defer_strategy(): void {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return;
			}
			if ( ! $this->should_optimise_for_logged_in() && ! self::is_sandbox_preview_active() ) {
				return;
			}
			// Safe-mode kill switch + nocache bypass (issue #1098): fail open
			// to original scripts, settings preserved. Sandbox preview
			// (issue #1163) bypasses safe mode for preview admins only.
			if ( self::is_aggressive_bypass_active() ) {
				return;
			}
			if ( ! self::is_sandbox_preview_active() && self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() ) ) {
				return;
			}
			// Per-page defer kill-switch (#1098).
			if ( $this->defer_disabled_for_page || self::is_defer_disabled_for_page() ) {
				return;
			}

			$file_opt_for_defer = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
			if ( empty( $file_opt_for_defer['deferJS'] ) ) {
				return;
			}

			// Canonical-path guard (issue #1218): the native strategy API is
			// silently ignored below WP 6.3 or when wp_script_add_data() is
			// unavailable, so fail open to undeferred scripts (the pre-6.3
			// regex fallback owns those installs via setup_hooks()).
			if ( ! self::supports_native_defer_strategy() ) {
				return;
			}

			global $wp_scripts;
			if ( ! $wp_scripts instanceof \WP_Scripts || empty( $wp_scripts->queue ) ) {
				return;
			}

			// Native fetchpriority/in_footer capability (issue #1218): the shared
			// predicate pairs the 6.9-alpha floor with a genuinely-6.9 API probe
			// so a filtered version string cannot enable native writes where
			// the API is absent. Pre-release-inclusive floor matches
			// setup_hooks() so alpha/beta/RC builds already carrying the API
			// are covered.
			$supports_fetchpriority = self::supports_native_script_fetchpriority();

			$interactivity_handles = array( 'wp-interactivity', '@wordpress/interactivity', '@wordpress/interactivity-router' );

			// Intended set, first pass (issue #1466 review): snapshot the handles
			// this run intends to defer — interactivity guard plus the cheap
			// exclusion filter — so eligibility below judges each dependent
			// against intended-plus-stamped strategies instead of mid-loop write
			// order (queue-order independence). Excluded handles stay out, so
			// their dependencies correctly observe them as blocking.
			//
			// @since 2.3.0.
			$intended_defer_handles = array();
			foreach ( $wp_scripts->queue as $queued_handle ) {
				$queued_handle = (string) $queued_handle;
				if ( '' === $queued_handle ) {
					continue;
				}
				if ( in_array( $queued_handle, $interactivity_handles, true ) ) {
					continue;
				}
				if ( in_array( $queued_handle, $this->exclude_defer_js, true ) ) {
					continue;
				}
				$intended_defer_handles[] = $queued_handle;
			}

			foreach ( $wp_scripts->queue as $handle ) {
				// Interactivity runtime guard (issue #1201): never deprioritize or
				// move the block-interactivity runtime, even if a site filters the
				// preset away. Mirrors apply_module_loading_strategies(). Fail-open.
				//
				// @since 2.2.0.
				if ( in_array( (string) $handle, $interactivity_handles, true ) ) {
					continue;
				}
				// Cheap exclusion filter first (issue #1466 review): explicitly
				// excluded handles skip the O(queue) eligibility scan entirely.
				//
				// @since 2.3.0.
				if ( in_array( $handle, $this->exclude_defer_js, true ) ) {
					continue;
				}
				// Eligibility gate (issue #1466): consult core's eligible
				// strategy before stamping defer so blocking dependents,
				// inline-after scripts, and module scripts render blocking.
				// Fail-open inside the helper; ineligible handles stay
				// undeferred (no strategy, no deferred mark, no
				// fetchpriority/group) with queue order untouched.
				//
				// @since 2.3.0.
				if ( ! $this->is_defer_eligible_for_handle( $wp_scripts, (string) $handle, $intended_defer_handles ) ) {
					continue;
				}
				// Fill-gaps-only for the strategy itself (issue #1184): never
				// overwrite an explicit async/defer strategy stamped by core,
				// a theme, or another plugin — rewriting async to defer would
				// change execution semantics. Core stores the strategy via
				// wp_script_add_data( $handle, 'strategy', ... ), so read it
				// back via WP_Scripts::get_data() (guarded; fail-open writes
				// when the store is unreadable). Fetchpriority/in_footer below
				// still apply to the already-deferred handle.
				//
				// @since 2.2.0.
				$existing_strategy = method_exists( $wp_scripts, 'get_data' ) ? $wp_scripts->get_data( $handle, 'strategy' ) : false;
				$has_strategy      = is_string( $existing_strategy ) && in_array( strtolower( trim( $existing_strategy ) ), array( 'async', 'defer' ), true );
				if ( ! $has_strategy ) {
					wp_script_add_data( $handle, 'strategy', 'defer' );
				}
				$this->deferred_handles[ $handle ] = true;
				// Native fetchpriority on WP 6.9+ (Trac #61734); pre-6.9 is handled
				// by the script_loader_tag regex fallback (add_fetchpriority_to_deferred).
				// Fill-gaps-only (issue #1218): never overwrite an explicit
				// fetchpriority value — 'high', 'low', and explicit 'auto'
				// are all preserved; only a missing/empty value counts as a
				// gap. Explicit 'auto' IS distinguishable here (classic
				// scripts carry no core default — get_data() returns false
				// when unset), unlike the module path where core defaults
				// every registration to 'auto'.
				if ( $supports_fetchpriority && function_exists( 'wp_script_add_data' ) ) {
					$existing_fetchpriority = method_exists( $wp_scripts, 'get_data' ) ? $wp_scripts->get_data( $handle, 'fetchpriority' ) : false;
					$is_gap                 = ! is_string( $existing_fetchpriority ) || '' === trim( $existing_fetchpriority );
					if ( $is_gap ) {
						$fetchpriority = $this->get_filtered_deferred_fetchpriority( (string) $handle );
						if ( '' !== $fetchpriority ) {
							wp_script_add_data( $handle, 'fetchpriority', $fetchpriority );
						}
					}
				}
				if ( $supports_fetchpriority ) {
					// Native in_footer for deferred classic scripts on WP 6.9+
					// (Trac #63486). Core reads the 'group' data key for footer
					// placement — wp_enqueue_script()'s args handler
					// (_wp_scripts_add_args_data()) maps in_footer to group=1,
					// and 'in_footer' itself is never read for classic scripts,
					// so set 'group' directly (issue #879 review). Skipped when
					// the handle is already footer-bound and opt-out per handle.
					$in_footer = $this->should_move_deferred_to_footer( (string) $handle );
					$group     = method_exists( $wp_scripts, 'get_data' ) ? $wp_scripts->get_data( $handle, 'group' ) : false;
					if ( $in_footer && 1 !== (int) $group ) {
						wp_script_add_data( $handle, 'group', 1 );
					}
				}
			}
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
		 */
		private function is_executable_script_type( string $tag ): bool {
			if ( ! preg_match( '/\stype\s*=\s*(["\']?)([^"\'>\s]*)\1(?=[\s>\/]|$)/i', $tag, $matches ) ) {
				// No type attribute: HTML defaults to text/javascript.
				return true;
			}

			$type = strtolower( trim( $matches[2] ) );
			if ( '' === $type ) {
				// type="" / type= behaves as the implied default.
				return true;
			}

			return in_array(
				$type,
				array(
					'text/javascript',
					'application/javascript',
					'text/ecmascript',
					'application/ecmascript',
					'module',
				),
				true
			);
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
			if ( ! $this->should_optimise_for_logged_in() && ! self::is_sandbox_preview_active() ) {
				return $tag;
			}
			// Safe-mode kill switch + nocache bypass (issue #1098): fail open
			// to original scripts, settings preserved for one-click recovery.
			// Sandbox preview (issue #1163): preview admins bypass safe mode
			// so staged delay/defer renders; visitors still gate on safe mode.
			// Aggressive bypass (?nocache) still applies in preview.
			$file_opt_for_gate = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
			if ( self::is_aggressive_bypass_active() ) {
				return $tag;
			}
			if ( ! self::is_sandbox_preview_active() && self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() ) ) {
				return $tag;
			}

			if ( ! empty( $file_opt_for_gate['delayJS'] ) ) {
				// Woo / builder-context guardrail (#932): fail open to un-delayed
				// scripts, never fatal. See is_delay_excluded_context().
				if ( self::is_delay_excluded_context() || $this->is_delay_js_safe_context() ) {
					return $tag;
				}
				// Per-page kill-switch (#966): `_wppo_delay_disabled` meta.
				// The instance flag is set at the `wp` hook by
				// apply_per_page_delay_config(); the static call below is
				// request-cached so per-tag lookups stay a single meta read.
				if ( $this->delay_disabled_for_page || self::is_delay_disabled_for_page() ) {
					return $tag;
				}
				// External-scripts-only mode (#966): leave inline scripts
				// (no src attribute) untouched; only external handles delay.
				// Anchored on whitespace so data-src=/wppo-src= don't match.
				if ( ! empty( $file_opt_for_gate['delayJSExternalOnly'] ) ) {
					if ( ! preg_match( '/\ssrc\s*=/i', ' ' . (string) $tag ) ) {
						return $tag;
					}
				}
				// One-click third-party delay (#1217) plus auto third-party
				// delay (#1314): when either is on, only delay third-party
				// candidates (known denylist host/keyword or cross-origin src
				// for the manual mode; curated known-vendor URL patterns for
				// auto mode); first-party scripts stay eager. User allowlist
				// wins on both paths. Manual exclusions and per-page overrides
				// below still apply — auto patterns merge additively, never
				// replacing them. Fail-open: detection errors leave un-delayed.
				// Auto-match verdict, reused by get_delay_strategy_for_handle()
				// below so each tag pays a single auto-pattern scan (not two:
				// gate + strategy). Null when auto mode is off.
				$auto_matched = null;
				if ( ! empty( $file_opt_for_gate['delayJSThirdParty'] ) || ! empty( $file_opt_for_gate['delayJSThirdPartyAuto'] ) ) {
					try {
						$is_candidate = false;
						if ( ! empty( $file_opt_for_gate['delayJSThirdParty'] ) ) {
							$is_candidate = $this->is_delay_third_party_candidate( (string) $tag, (string) $handle );
						}
						if ( ! empty( $file_opt_for_gate['delayJSThirdPartyAuto'] ) ) {
							$auto_matched = $this->is_delay_third_party_auto_candidate( (string) $tag, (string) $handle );
							$is_candidate = $is_candidate || $auto_matched;
						}
						if ( ! $is_candidate ) {
							return $tag;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return $tag;
					}
				}
				if ( ! $this->is_delay_excluded_handle( $handle ) ) {
					// Non-executable <script> types are data blocks
					// (application/json, application/ld+json, text/template,
					// speculationrules, …). They never execute, so delaying
					// them achieves nothing, and moving their src to wppo-src
					// would corrupt the payload. Leave them untouched
					// (issue #1094, item 1).
					if ( ! $this->is_executable_script_type( (string) $tag ) ) {
						return $tag;
					}

					// Fill-gaps-only: never emit a duplicate fetchpriority attribute
					// when core or an earlier filter already stamped one. Anchored
					// on a whitespace boundary so data-*fetchpriority attributes
					// (e.g. data-wp-fetchpriority=, which core emits alongside the
					// real attribute) never count as a real fetchpriority.
					// WP 6.9+ delegation (Trac #61734): when the native
					// fetchpriority API owns this deferred handle, skip the
					// regex stamp so core renders fetchpriority exactly once
					// via the wp_script_add_data() write in
					// add_defer_strategy(). Legacy WP 6.2-6.8, inline/unknown
					// tags, and unavailable native API keep the stamp
					// (fail-open). Delay-JS marker attributes below are
					// preserved untouched.
					//
					// @since 2.2.0.
					if ( ! preg_match( '/\sfetchpriority\s*=/i', (string) $tag ) ) {
						$is_native_deferred = self::supports_native_script_fetchpriority() && isset( $this->deferred_handles[ $handle ] );
						if ( ! $is_native_deferred ) {
							$tag = self::inject_delay_script_attr( $tag, 'fetchpriority="low" ' );
						}
					}
					// Execution-order preservation (#1217): record the original
					// async/defer semantics so lazyload.js can replay them —
					// defer in document order (sequential), async in any order.
					// Fill-gaps-only: never duplicate when already stamped.
					// The attr test runs against the tag with quoted values
					// stripped so `async`/`defer` inside attribute VALUES
					// (e.g. data-x="async loader") never counts as the real
					// boolean attribute (#1217 review).
					if ( false === strpos( $tag, 'data-wppo-delay-exec' ) ) {
						$tag_unquoted = preg_replace( '/"[^"]*"|\'[^\']*\'/', '""', $tag );
						if ( ! is_string( $tag_unquoted ) ) {
							$tag_unquoted = $tag;
						}
						if ( preg_match( '/\sasync(?=[\s=\/>])/i', $tag_unquoted ) ) {
							$tag = self::inject_delay_script_attr( $tag, 'data-wppo-delay-exec="async" ' );
						} elseif ( preg_match( '/\sdefer(?=[\s=\/>])/i', $tag_unquoted ) ) {
							$tag = self::inject_delay_script_attr( $tag, 'data-wppo-delay-exec="defer" ' );
						}
					}
					// Whitespace-anchored and case-insensitive so `<SCRIPT
					// SRC=…>`, `<script\tsrc=…>` and `<script\nsrc=…>` variants
					// rewrite identically to lowercase markup; anchored so
					// `data-src`/`wppo-src` (dash-prefixed) never match, and
					// fill-gaps-safe on re-processing (#1217 review).
					// Quoted attribute VALUES are masked with equal-length spaces
					// first so `src=` inside a value (e.g. data-note="use
					// src=fallback") is never rewritten — only the first real
					// src attribute outside quotes is renamed.
					try {
						$masked = preg_replace_callback(
							'/"[^"]*"|\'[^\']*\'/',
							static function ( $m ): string {
								return str_repeat( ' ', strlen( $m[0] ) );
							},
							$tag
						);
						if ( is_string( $masked ) && 1 === preg_match( '/\ssrc(?=[\s=])/i', $masked, $src_m, PREG_OFFSET_CAPTURE ) ) {
							$src_pos = (int) $src_m[0][1];
							$tag     = substr_replace( $tag, ' wppo-src', $src_pos, 4 );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					// Normalise an executable JS type into the delay marker,
					// carrying the ORIGINAL type through wppo-type so
					// lazyload.js can restore it (type="module" becomes
					// wppo-type="module"). Skipped when the marker is already
					// present, and anchored on a whitespace boundary so
					// wppo-type="text/javascript" cannot match its own value —
					// without both guards, re-processing double-stamped
					// wppo-type and produced duplicate attributes
					// (issue #1094, item 2).
					if ( false === strpos( $tag, 'wppo/javascript' ) ) {
						$tag = preg_replace_callback(
							'/\stype=(["\'])([^"\']*)\1/i',
							static function ( $matches ) {
								return ' type="wppo/javascript" wppo-type="' . $matches[2] . '"';
							},
							$tag
						) ?? $tag;
					}
					// Fill-gaps-only (#1089): WP 6.3+ omits type="text/javascript"
					// for defer/async-strategy scripts (and plain tags may carry
					// no type at all), so the rewrite above leaves those tags
					// with wppo-src but no delay marker. Inject the marker when
					// absent so every delayed tag stays self-describing; the
					// HTML-implied default is text/javascript.
					if ( false !== strpos( $tag, 'wppo-src' )
						&& false === strpos( $tag, 'wppo/javascript' )
					) {
						$tag = self::inject_delay_script_attr( $tag, 'type="wppo/javascript" wppo-type="text/javascript" ' );
					}

					// Determine delay strategy for this handle. The gate
					// verdict above is reused so auto-matched tags do not
					// pay a second pattern scan here.
					$strategy = $this->get_delay_strategy_for_handle( $handle, (string) $tag, $auto_matched );
					if ( 'interaction' !== $strategy ) {
						$tag = self::inject_delay_script_attr(
							$tag,
							'data-wppo-delay-strategy="' . esc_attr( $strategy ) . '" '
						);
					}

						// Determine priority for this handle.
						$priority = $this->get_delay_priority_for_handle( $handle );
					if ( 'normal' !== $priority ) {
						$tag = self::inject_delay_script_attr(
							$tag,
							'data-wppo-delay-priority="' . esc_attr( $priority ) . '" '
						);
					}
				}
			}

			return $tag;
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
		 */
		public function add_defer_attribute_legacy( $tag, $handle ): string {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->should_optimise_for_logged_in() && ! self::is_sandbox_preview_active() ) {
				return $tag;
			}
			// Safe-mode kill switch + nocache bypass + per-page defer disable
			// (issue #1098): fail open to original tag. Sandbox preview
			// (issue #1163) bypasses safe mode for preview admins only.
			if ( self::is_aggressive_bypass_active() ) {
				return $tag;
			}
			if ( ! self::is_sandbox_preview_active() && self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() ) ) {
				return $tag;
			}
			if ( $this->defer_disabled_for_page || self::is_defer_disabled_for_page() ) {
				return $tag;
			}
			// Sandbox preview (issue #1163): the legacy path is registered on the
			// production flag, so gate on the effective (production + staged)
			// deferJS flag like the native path does — a staged deferJS=off must
			// disable defer in preview instead of deferring every tag.
			$file_opt_for_legacy = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
			if ( empty( $file_opt_for_legacy['deferJS'] ) ) {
				return $tag;
			}

			if ( in_array( $handle, $this->exclude_defer_js, true ) ) {
				return $tag;
			}

			// Quote-mask attribute values first so `defer`/`async` inside a value
			// (e.g. data-note="use async fallback") never counts as the real
			// boolean attribute.
			$tag_unquoted = preg_replace( '/"[^"]*"|\'[^\']*\'/', '""', (string) $tag );
			if ( ! is_string( $tag_unquoted ) ) {
				$tag_unquoted = (string) $tag;
			}
			// Fill-gaps-only: a tag already carrying `defer` or `async` (core,
			// theme, or LiteSpeed delay, any case) is returned untouched so no
			// `async defer` double-attribute markup is emitted (issue #1218).
			// Case-insensitive with an attribute boundary so DEFER, valued
			// async="async", and spaced variants are all observed.
			if ( preg_match( '/\s(?:defer|async)(?=[\s=\/>])/i', $tag_unquoted ) ) {
				return $tag;
			}
			// Module scripts are always deferred by the browser; match type=module
			// on the raw tag (masking strips its quotes) tolerating case, quote
			// style, and surrounding whitespace.
			if ( preg_match( '/\stype\s*=\s*(["\']?)module\1(?=[\s>\/]|$)/i', (string) $tag ) ) {
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
		 * Build (once per request) a combined alternation regex for a pattern list.
		 *
		 * @since 2.0.0
		 * @param string[] $patterns Pattern list.
		 * @return string Empty string when no usable patterns; otherwise a ready regex.
		 */
		private static function get_delay_patterns_regex( array $patterns ): string {
			$cleaned = array();
			foreach ( $patterns as $pattern ) {
				$pattern = (string) $pattern;
				if ( '' !== $pattern ) {
					$cleaned[] = $pattern;
				}
			}
			if ( empty( $cleaned ) ) {
				return '';
			}
			$cache_key = md5( function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $cleaned ) : implode( "\0", $cleaned ) );
			if ( isset( self::$delay_pattern_regex_cache[ $cache_key ] ) ) {
				return self::$delay_pattern_regex_cache[ $cache_key ];
			}
			$quoted = array();
			foreach ( $cleaned as $pattern ) {
				$quoted[] = preg_quote( $pattern, '/' );
			}
			$regex = '/\b(?:' . implode( '|', $quoted ) . ')\b/';
			self::$delay_pattern_regex_cache[ $cache_key ] = $regex;
			if ( count( self::$delay_pattern_regex_cache ) > 200 ) {
				self::$delay_pattern_regex_cache = array_slice( self::$delay_pattern_regex_cache, -200, 200, true );
			}
			return $regex;
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
		 */
		private function matches_any_delay_pattern( string $handle, array $patterns ): bool {
			if ( '' === $handle || empty( $patterns ) ) {
				return false;
			}
			$regex = self::get_delay_patterns_regex( $patterns );
			if ( '' === $regex ) {
				return false;
			}
			try {
				$matched = preg_match( $regex, $handle );
				if ( false !== $matched ) {
					return (bool) $matched;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			foreach ( $patterns as $pattern ) {
				if ( $this->matches_delay_pattern( $handle, (string) $pattern ) ) {
					return true;
				}
			}
			return false;
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
		 */
		private function is_delay_excluded_handle( string $handle ): bool {
			$exclusions = $this->get_delay_exclusions();

			if ( in_array( $handle, $exclusions, true ) ) {
				return true;
			}
			// Precomputed dash/underscore variants (#1308 review): the
			// suffixed strings are built once per resolved exclusion list
			// instead of concatenating per handle, and overlong variants are
			// skipped before strpos. Still O(H x P) per handle but without
			// repeated string building.
			static $variant_cache = array();
			static $variant_key   = null;
			$handle_len           = strlen( $handle );
			$list_key             = md5( implode( "\0", array_map( 'strval', (array) $exclusions ) ) );
			if ( null !== $list_key && $list_key === $variant_key && isset( $variant_cache[ $list_key ] ) ) {
				$variants = $variant_cache[ $list_key ];
			} else {
				$variants = array();
				foreach ( $exclusions as $pattern ) {
					$pattern = (string) $pattern;
					if ( '' === $pattern ) {
						continue;
					}
					// Pure-prefix entries (trailing '-' or '_', mirroring the
					// used-CSS safelist): e.g. 'et_' excludes 'et_core_api_shortcodes'.
					$last = substr( $pattern, -1 );
					if ( '_' === $last || '-' === $last ) {
						$variants[] = array( $pattern, false );
						continue;
					}
					// Dash/underscore-delimited variants: 'oxygen' excludes
					// 'oxygen-foo', 'vc_tta' excludes 'vc_tta-custom'. Word
					// boundaries alone cannot express this because '_' is a word
					// character, so the separator check comes first.
					$variants[] = array( $pattern . '-', true );
					$variants[] = array( $pattern . '_', true );
				}
				if ( null !== $list_key ) {
					$variant_cache = array( $list_key => $variants );
					$variant_key   = $list_key;
				}
			}
			foreach ( $variants as $variant ) {
				list( $needle, $delimited ) = $variant;
				if ( strlen( $needle ) > $handle_len ) {
					continue;
				}
				if ( ! $delimited ) {
					if ( 0 === strpos( $handle, $needle ) ) {
						return true;
					}
					continue;
				}
				if ( 0 === strpos( $handle, $needle ) ) {
					return true;
				}
			}
			// Word-boundary matching via one precompiled alternation per
			// request instead of one preg_match compile per pattern per tag.
			if ( $this->matches_any_delay_pattern( $handle, $exclusions ) ) {
				return true;
			}
			return false;
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
		 */
		private function get_delay_exclusions(): array {
			if ( null !== $this->resolved_delay_exclusions ) {
				return $this->resolved_delay_exclusions;
			}

			$exclusions = $this->exclude_delay_js;

			if ( has_filter( 'wppo_exclude_delay_js' ) ) {
				try {
					$exclusions = (array) apply_filters( 'wppo_exclude_delay_js', $exclusions );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Filter-then-subtract (#1308): the per-page preset opt-out wins
			// over filter re-adds, mirroring the Minify\HTML constructor.
			if ( ! empty( $this->page_preset_opt_out_remove ) ) {
				try {
					$exclusions = array_values( array_diff( $exclusions, $this->page_preset_opt_out_remove ) );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$this->resolved_delay_exclusions = array_values(
				array_unique(
					array_filter(
						$exclusions,
						static function ( $val ): bool {
							return is_string( $val ) && '' !== $val;
						}
					)
				)
			);

			return $this->resolved_delay_exclusions;
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
		 */
		public static function is_delay_excluded_context(): bool {
			// Per-request memo: add_defer_attribute() calls this per script
			// tag; REQUEST_URI/settings/slug parsing is done once per request.
			// Reuse only while the request signature is unchanged so a
			// long-running process handling several logical requests cannot
			// serve a stale verdict (and tests can reset explicitly).
			$signature = self::delay_context_request_signature();
			if ( null !== self::$delay_excluded_context_memo && $signature === self::$delay_excluded_context_memo_sig ) {
				return self::$delay_excluded_context_memo;
			}
			$result                                = self::compute_delay_excluded_context();
			self::$delay_excluded_context_memo     = $result;
			self::$delay_excluded_context_memo_sig = $signature;
			return $result;
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
		 */
		private static function delay_context_request_signature(): string {
			$uri = '';
			if ( isset( $_SERVER['REQUEST_URI'] ) ) {
				$uri = (string) wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only request memo key; never output or persisted.
			}
			$qs = '';
			if ( isset( $_SERVER['QUERY_STRING'] ) ) {
				$qs = (string) wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only request memo key; never output or persisted.
			}
			$get = array();
			if ( ! empty( $_GET ) && is_array( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request memo key; no state change.
				$get = array_map( 'strval', array_keys( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only request memo key; no state change.
				sort( $get );
			}
			return $uri . "\n" . $qs . "\n" . implode( ',', $get );
		}

		/**
		 * Reset the per-request delay-context memo (for tests).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function reset_delay_context_memo(): void {
			self::$delay_excluded_context_memo          = null;
			self::$delay_excluded_context_memo_sig      = '';
			self::$delay_pattern_regex_cache            = array();
			self::$delay_js_third_party_auto_cache      = null;
			self::$delay_js_third_party_auto_cache_blog = 0;
			self::$delay_js_auto_label_commerce         = null;
			self::$delay_js_auto_label_categories       = null;
			self::$delay_js_auto_label_blog             = 0;
		}

		/**
		 * Compute whether the current request must skip delay-JS rewriting.
		 *
		 * @since 2.0.0
		 * @return bool True when delay must be skipped for this request.
		 */
		private static function compute_delay_excluded_context(): bool {
			try {
				// Store API routes are dynamic JSON: never delay (issue #962).
				// Unconditional on wooSafeMode, mirroring wc-ajax — checked
				// first so safe-mode-off cannot re-allow delaying Store API.
				// Covers plain permalinks via ?rest_route=/wc/store/... too.
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_request' ) ) {
					try {
						$uri_for_store = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
						$store_path    = '/' . trim( rawurldecode( (string) wp_parse_url( $uri_for_store, PHP_URL_PATH ) ), '/' );
						if ( Util::is_woo_store_api_request( $store_path ) ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				} else {
					$uri_for_store = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed before sanitizing; read-only routing check, no output.
					$store_path    = '/' . trim( rawurldecode( (string) wp_parse_url( $uri_for_store, PHP_URL_PATH ) ), '/' );
					if ( preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', $store_path ) ) {
						return true;
					}
					$rest_route_main = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					if ( '' !== $rest_route_main && preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . ltrim( $rest_route_main, '/' ) ) ) {
						return true;
					}
				}

				// Single safe-mode toggle for the Woo page/endpoint branch below
				// (unified with Cache/Cron via Util::is_woo_safe_mode_enabled();
				// absent = on, malformed = on). Store API above stays unconditional.
				$woo_safe = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) ) {
					try {
						$woo_safe = Util::is_woo_safe_mode_enabled();
					} catch ( \Throwable $e ) {
						unset( $e );
						$woo_safe = true;
					}
				}

				if ( $woo_safe ) {
					// Woo conditional tags (guarded for non-Woo installs / WP 6.2+ compat).
					if ( function_exists( 'is_cart' ) && is_cart() ) {
						return true;
					}
					if ( function_exists( 'is_checkout' ) && is_checkout() ) {
						return true;
					}
					if ( function_exists( 'is_account_page' ) && is_account_page() ) {
						return true;
					}

					// Woo page-slug path fallback (also covers installs where Woo
					// conditional functions are unavailable). Matches the slug as a full
					// path segment anywhere in the request path (fail-safe: covers
					// subdirectory installs (/shop/checkout) and multisite sub-sites
					// (/subsite/cart), so a non-Woo page containing the segment is also
					// treated as dynamic); custom
					// or translated slugs are resolved via wc_get_page_id() when
					// WooCommerce is active.
					// wp_parse_url() exists since WP 4.4; the plugin requires WP 6.2+.
					if ( isset( $_SERVER['REQUEST_URI'] ) ) {
						$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
						$parsed_path = wp_parse_url( (string) $request_uri, PHP_URL_PATH );
						$local_path  = '/' . trim( rawurldecode( (string) $parsed_path ), '/' );
						if ( '/' !== $local_path && self::matches_woo_page_path( $local_path ) ) {
							return true;
						}

						// Woo endpoint URLs (order-pay, view-order, downloads, …).
						if ( function_exists( 'is_wc_endpoint_url' ) ) {
							try {
								if ( is_wc_endpoint_url() ) {
									return true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
								return true;
							}
						}
					}
				}

				if ( isset( $_SERVER['REQUEST_URI'] ) ) {
					$request_uri_for_ajax = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
					$ajax_path            = '/' . trim( rawurldecode( (string) wp_parse_url( (string) $request_uri_for_ajax, PHP_URL_PATH ) ), '/' );
					if ( '/' !== $ajax_path ) {
						// wc-ajax XHR endpoints (?wc-ajax= / /wc-ajax/ path). Unconditional on safe mode.
						if ( preg_match( '#(^|/)wc-ajax(/|$)#i', trim( $ajax_path, '/' ) ) ) {
							return true;
						}
					}
				}

				if ( isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}

				// Add-to-cart requests (?add-to-cart= / query string).
				if ( isset( $_GET['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}
				if ( ! empty( $_SERVER['QUERY_STRING'] ) && preg_match( '/(?:^|&)(add-to-cart|wc-ajax)(?:=|&|$)/i', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed before sanitizing; read-only routing check, no output.
					return true;
				}

				// Per-URL delay disable (#988): skip delay on listed URLs only,
				// without disabling the plugin. Fail-open: matcher errors never exclude.
				try {
					$delay_exclude_list = '';
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
						$delay_settings     = Util::get_settings();
						$delay_exclude_list = isset( $delay_settings['file_optimisation']['delayJSExcludeUrls'] ) ? (string) $delay_settings['file_optimisation']['delayJSExcludeUrls'] : '';
					}
					if ( '' !== trim( $delay_exclude_list ) && self::is_url_excluded_by_list( $delay_exclude_list ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				// Builder preview/edit contexts only (never blanket-disable rendered frontend).
				// Elementor: the preview query var alone must not disable
				// delay-JS for any visitor appending it — gate on the same
				// cheap Elementor markers used by detect_elementor_built_page()
				// (perf-only kill-switch otherwise).
				if ( isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					if ( class_exists( 'Elementor\Plugin', false ) || defined( 'ELEMENTOR_VERSION' ) || function_exists( 'elementor_pro_load_plugin' ) ) {
						return true;
					}
				}
				if ( class_exists( 'Elementor\Plugin' ) ) {
					try {
						$elementor = \Elementor\Plugin::$instance ?? null;
						if ( isset( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
							return true;
						}
					} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fail-open: fall through to the remaining builder checks below.
						unset( $e );
					}
				}
				// Divi (et_fb / et_pb_preview), WPBakery (vc_action / vc_editable), Bricks (bricks=run).
				if ( isset( $_GET['et_fb'] ) || isset( $_GET['et_pb_preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}
				if ( isset( $_GET['vc_action'] ) || isset( $_GET['vc_editable'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}
				if ( isset( $_GET['bricks'] ) && 'run' === sanitize_text_field( wp_unslash( (string) $_GET['bricks'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}

				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fail-open: any detection failure skips delay (un-delayed scripts, never fatal).
				return true;
			}
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
		 */
		private static function matches_woo_page_path( string $local_path ): bool {
			// Canonical path list (issue #962): Util::get_woo_excluded_paths()
			// merged with the cart/checkout/my-account defaults so custom /
			// translated / nested slugs stay excluded. Anywhere-segment fail-safe
			// semantics cover subdirectory installs and multisite sub-sites.
			$slugs = array( 'cart', 'checkout', 'my-account' );

			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_woo_excluded_paths' ) ) {
				try {
					foreach ( Util::get_woo_excluded_paths() as $woo_path ) {
						$candidate = strtolower( trim( (string) $woo_path, '/' ) );
						if ( '' !== $candidate && ! in_array( $candidate, $slugs, true ) ) {
							$slugs[] = $candidate;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			} elseif ( function_exists( 'wc_get_page_id' ) && function_exists( 'get_post_field' ) ) {
				try {
					foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
						$page_id = (int) wc_get_page_id( $page );
						if ( $page_id > 0 ) {
							$slug = get_post_field( 'post_name', $page_id );
							if ( is_string( $slug ) && '' !== $slug ) {
								$slugs[] = $slug;
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$slugs  = array_unique( $slugs );
			$quoted = array();
			foreach ( $slugs as $slug ) {
				$quoted[] = preg_quote( $slug, '#' );
			}
			$pattern = '#/(' . implode( '|', $quoted ) . ')(/|$)#i';

			return (bool) preg_match( $pattern, $local_path );
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
		 */
		private function get_delay_strategy_for_handle( string $handle, string $tag = '', ?bool $is_auto_matched = null ): string {
			if ( in_array( $handle, $this->delay_js_idle_list, true ) ) {
				return 'idle';
			}
			if ( in_array( $handle, $this->delay_js_viewport_list, true ) ) {
				return 'viewport';
			}
			// Also check via URL pattern matching against the handle text (handles often contain the handle name).
			// Precompiled alternation: one regex per list per request instead of per-pattern compiles.
			if ( $this->matches_any_delay_pattern( $handle, $this->delay_js_idle_list ) ) {
				return 'idle';
			}
			if ( $this->matches_any_delay_pattern( $handle, $this->delay_js_viewport_list ) ) {
				return 'viewport';
			}
			// Auto third-party mode (#1314): load-when-idle parity — scripts
			// matching the curated auto patterns resolve to the idle strategy
			// (same as the manual delayJSIdleList path) instead of the
			// interaction default. Manual idle/viewport lists and per-page
			// overrides above win; an explicit per-page interaction pin below
			// also wins. Only upgrades the interaction default: an
			// explicit viewport default is never loosened. Builder and commerce
			// exclusions never reach here (their tags return early). The auto
			// pattern list lazy-boots only on this path, so it costs nothing
			// when the toggle is off.
			// Per-page-wins: a handle explicitly pinned to interaction for
			// this page stays interaction even when it matches an auto
			// vendor pattern.
			if ( in_array( $handle, $this->delay_js_per_page_interaction, true ) ) {
				return 'interaction';
			}
			if ( 'interaction' === $this->delay_js_default_strategy ) {
				// Sandbox preview parity: read the staged (effective) slice,
				// not raw options, so preview with staged auto=true resolves
				// idle exactly like promoted production.
				$file_opt = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
				if ( ! empty( $file_opt['delayJSThirdPartyAuto'] ) ) {
					try {
						if ( true === $is_auto_matched ) {
							return 'idle';
						}
						if ( null === $is_auto_matched && self::matches_third_party_auto_pattern( (string) $handle, (string) $tag ) ) {
							return 'idle';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
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
			// Check partial matches via one precompiled alternation, then
			// resolve the winning pattern for the level.
			$patterns = array_keys( $this->delay_js_priority );
			if ( ! empty( $patterns ) && $this->matches_any_delay_pattern( $handle, $patterns ) ) {
				foreach ( $this->delay_js_priority as $pattern => $level ) {
					if ( $this->matches_delay_pattern( $handle, (string) $pattern ) ) {
						return $level;
					}
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

			// Per-page kill-switch (#966): skip all delay rewriting for this
			// request. Notes field (`_wppo_delay_notes`) is informational only.
			if ( self::is_delay_disabled_for_page( (int) $post_id ) ) {
				$this->delay_disabled_for_page = true;
				return;
			}

			// Per-page defer kill-switch (#1098): skip all defer rewriting for
			// this request. Post meta survives cache clears; the single-URL
			// purge in invalidate_aggressive_kill_switch_cache() refreshes HTML.
			if ( self::is_defer_disabled_for_page( (int) $post_id ) ) {
				$this->defer_disabled_for_page = true;
			}

			// Per-page compat preset opt-out (#1308): a page can opt out of a
			// globally-enabled preset without touching global settings. Only
			// presets that are globally enabled contribute to the removal list,
			// so opting out on a page where the preset is off cannot strip
			// base/manual protections. Manual exclusions and safe presets are
			// re-protected below. Fail-open: meta read errors apply no opt-out.
			// Early-bail: all four presets default off, so the common case skips
			// the post-meta lookup entirely. When delay itself is off, the
			// strategy/priority metas below are irrelevant — skip all reads.
			$file_opt = $this->options['file_optimisation'] ?? array();
			if ( empty( $file_opt['delayJS'] ) ) {
				return;
			}
			$compat_map = self::get_delay_js_compat_preset_map();
			$any_on     = false;
			foreach ( $compat_map as $setting_key => $map_slug ) {
				if ( ! empty( $file_opt[ $setting_key ] ) ) {
					$any_on = true;
					break;
				}
			}
			$presets_off = $any_on ? self::get_page_disabled_delay_presets( (int) $post_id ) : array();
			if ( ! empty( $presets_off ) ) {
				try {
					$slug_to_key = array_flip( $compat_map );
					$chunks      = array();
					foreach ( $presets_off as $slug ) {
						$slug = (string) $slug;
						// Gate on globally-enabled presets only.
						if ( ! isset( $slug_to_key[ $slug ] ) || empty( $file_opt[ $slug_to_key[ $slug ] ] ) ) {
							continue;
						}
						$chunks[] = self::get_delay_js_compat_preset_exclusions( $slug );
					}
					$remove = $chunks ? array_merge( ...$chunks ) : array();
					if ( ! empty( $remove ) ) {
						// Protect manual + base/safe entries: the additive merge
						// above holds a single copy of overlapping strings (e.g.
						// gtag, jquery), so diffing the raw preset list would
						// delete manual/base-added copies too. Subtract the
						// protected set from the removal list first.
						$protected = self::get_delay_js_protected_exclusions( $file_opt );
						if ( ! empty( $protected ) ) {
							$remove = array_values( array_diff( $remove, $protected ) );
						}
						if ( ! empty( $remove ) ) {
							$this->exclude_delay_js          = array_values( array_diff( $this->exclude_delay_js, $remove ) );
							$this->resolved_delay_exclusions = null;
							// Re-applied after the filter in get_delay_exclusions()
							// (filter-then-subtract) so late filter registrations
							// cannot silently nullify the page opt-out.
							$this->page_preset_opt_out_remove = array_values( $remove );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$delay_strategies = get_post_meta( $post_id, '_wppo_delay_strategies', true );
			$delay_priorities = get_post_meta( $post_id, '_wppo_delay_priorities', true );

			if ( is_array( $delay_strategies ) ) {
				$excluded_handles = $this->get_delay_exclusions();
				foreach ( $delay_strategies as $handle => $strategy ) {
					if ( ! in_array( $handle, $excluded_handles, true ) ) {
						if ( 'interaction' === $strategy ) {
							// Remove from other lists to make it interaction,
							// and record the explicit per-page choice so the
							// auto third-party idle upgrade (#1314) does not
							// silently promote it back to idle.
							$this->delay_js_idle_list     = array_diff( $this->delay_js_idle_list, array( $handle ) );
							$this->delay_js_viewport_list = array_diff( $this->delay_js_viewport_list, array( $handle ) );
							if ( ! in_array( $handle, $this->delay_js_per_page_interaction, true ) ) {
								$this->delay_js_per_page_interaction[] = $handle;
							}
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
		 */
		public static function get_delay_js_builder_exclusions(): array {
			$preset = array(
				// Elementor.
				'elementor-frontend',
				'elementor-pro-frontend',
				'elementor-common',
				'e-sticky',
				'elementor-waypoints',
				// Elementor first-click: popups/dialogs/lightbox must stay
				// interactive on first click (issue #1055).
				'elementor-popup',
				'elementor-dialog',
				'elementor-lightbox',
				'e-popup',
				'dialog-',
				// Divi.
				'divi-custom-script',
				'et-core-api',
				'et_',
				'et_pb_custom',
				'divi-builder',
				// Bricks.
				'bricks-scripts',
				'bricks-builder',
				// WPBakery.
				'vc_tta',
				'vc_teaser',
				'wpb_composer_front_js',
				'js_composer_front',
				// Oxygen.
				'oxygen',
				'oxy-',
				'oxy-front-end',
				// Gutenberg / block interactivity.
				'wp-block-library',
				'wp-interactivity',
				'wp-i18n',
			);
			/**
			 * Filters delay JS builder preset exclusions.
			 *
			 * Builder runtimes (Elementor/Divi/Bricks/WPBakery/Oxygen) stay
			 * un-delayed when the builder preset is on so page builders never
			 * break. Merged with `array_unique` by callers.
			 *
			 * @since 2.0.0
			 * @param string[] $preset Builder preset exclusions.
			 */
			return self::filter_compat_preset_list( 'wppo_delay_js_builder_exclusions', $preset );
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
		 */
		public static function get_delay_js_commerce_exclusions(): array {
			$preset = array(
				'jquery',
				'jquery-core',
				'jquery-migrate',
				'wc-cart-fragments',
				'wc-checkout',
				'woocommerce',
				'wc-add-to-cart',
				// Generic first-click add-to-cart cover (issue #1055): matches
				// non-prefixed handles/themes via dash-variant matching.
				'add-to-cart',
				'wc-single-product',
				'cart-fragments',
				'wc-cart',
				'wc-blocks',
				'wc-store',
				'wc-order-attribution',
				'wc-jquery-blockui',
				'wc-address-i18n',
				'wc-enhanced-select',
				'wc-password-strength-meter',
				'wc-geolocation',
			);
			/**
			 * Filters delay JS commerce preset exclusions.
			 *
			 * @since 2.0.0
			 * @param string[] $preset Commerce preset exclusions.
			 */
			return self::filter_compat_preset_list( 'wppo_delay_js_commerce_exclusions', $preset );
		}

		/**
		 * Curated slider Delay JS exclusions (issue #988).
		 *
		 * Slider runtimes stay un-delayed with the builder preset so hero
		 * sliders keep working. Filterable via wppo_delay_js_slider_exclusions.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_delay_js_slider_exclusions(): array {
			$preset = array(
				'revslider',
				'rs6',
				'rs-module',
				'rev-slider',
				'smart-slider',
				'metaslider',
				'soliloquy',
				'swiper',
				'slick',
				'owl-carousel',
				'splide',
				'bxslider',
			);
			/**
			 * Filters delay JS slider preset exclusions.
			 *
			 * @since 2.0.0
			 * @param string[] $preset Slider preset exclusions.
			 */
			return self::filter_compat_preset_list( 'wppo_delay_js_slider_exclusions', $preset );
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
		 */
		public static function get_delay_js_interaction_exclusions(): array {
			$preset = array(
				// Elementor popup/dialog first-click.
				'elementor-popup',
				'elementor-dialog',
				'e-popup',
				'dialog-',
				// Mobile / nav-menu toggles first-click.
				'menu-toggle',
				'mobile-menu',
				'nav-menu',
				'off-canvas',
				'offcanvas',
				'mmenu',
				'slicknav',
				// Woo first-click add-to-cart.
				'add-to-cart',
				'cart-fragments',
			);
			/**
			 * Filters delay JS interaction preset exclusions.
			 *
			 * @since 2.0.0
			 * @param string[] $preset Interaction preset exclusions.
			 */
			return self::filter_compat_preset_list( 'wppo_delay_js_interaction_exclusions', $preset );
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
		 */
		public static function get_delay_js_preset_levels(): array {
			return array( 'safe', 'balanced', 'aggressive' );
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
		 */
		public static function get_delay_js_preset_level_settings( string $level ): array {
			$level = strtolower( trim( $level ) );
			if ( ! in_array( $level, array( 'safe', 'balanced', 'aggressive' ), true ) ) {
				$level = 'safe';
			}
			$map      = array(
				'safe'       => array(
					'delayJSBuilderPreset'     => true,
					'delayJSCommercePreset'    => true,
					'delayJSInteractionPreset' => true,
					'delayJSConsentPreset'     => true,
					'delayJSAnalyticsPreset'   => true,
					'delayJSGalleryPreset'     => true,
					'delayJSJqueryPreset'      => true,
					'delayJSThirdPartyAuto'    => false,
				),
				'balanced'   => array(
					'delayJSBuilderPreset'     => true,
					'delayJSCommercePreset'    => true,
					'delayJSInteractionPreset' => true,
					'delayJSConsentPreset'     => false,
					'delayJSAnalyticsPreset'   => false,
					'delayJSGalleryPreset'     => false,
					'delayJSJqueryPreset'      => false,
					'delayJSThirdPartyAuto'    => false,
				),
				'aggressive' => array(
					'delayJSBuilderPreset'     => true,
					'delayJSCommercePreset'    => true,
					'delayJSInteractionPreset' => false,
					'delayJSConsentPreset'     => false,
					'delayJSAnalyticsPreset'   => false,
					'delayJSGalleryPreset'     => false,
					'delayJSJqueryPreset'      => false,
					'delayJSThirdPartyAuto'    => true,
				),
			);
			$settings = $map[ $level ];
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_delay_js_preset_level_settings' ) ) {
				return $settings;
			}
			try {
				$raw = apply_filters( 'wppo_delay_js_preset_level_settings', $settings, $level ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Intentional curated preset filter.
			} catch ( \Throwable $e ) {
				unset( $e );
				return $settings;
			}
			if ( ! is_array( $raw ) ) {
				return $settings;
			}
			foreach ( $settings as $key => $default ) {
				if ( array_key_exists( $key, $raw ) && ! is_array( $raw[ $key ] ) ) {
					if ( is_bool( $raw[ $key ] ) ) {
						$settings[ $key ] = $raw[ $key ];
						continue;
					}
					$bool = filter_var( $raw[ $key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $bool ) {
						$settings[ $key ] = $bool;
					}
				}
			}
			// Builder plus commerce presets stay forced ON at every level.
			$settings['delayJSBuilderPreset']  = true;
			$settings['delayJSCommercePreset'] = true;
			return $settings;
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
		 */
		public static function get_delay_js_preset_level_exclusions( string $level ): array {
			try {
				$level = strtolower( trim( $level ) );
				if ( ! in_array( $level, array( 'safe', 'balanced', 'aggressive' ), true ) ) {
					$level = 'safe';
				}
				$toggles = self::get_delay_js_preset_level_settings( $level );
				$chunks  = array();
				try {
					$chunks[] = self::get_delay_js_base_preset_exclusions();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Builder plus commerce are forced ON at every level (see
				// get_delay_js_preset_level_settings(), which re-forces both
				// toggles after the filter). The toggle reads below document
				// that mapping; they are always true by design, never dead.
				if ( ! empty( $toggles['delayJSBuilderPreset'] ) ) {
					try {
						$chunks[] = self::get_delay_js_builder_exclusions();
						$chunks[] = self::get_delay_js_slider_exclusions();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( ! empty( $toggles['delayJSCommercePreset'] ) ) {
					try {
						$chunks[] = self::get_delay_js_commerce_exclusions();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( ! empty( $toggles['delayJSInteractionPreset'] ) ) {
					try {
						$chunks[] = self::get_delay_js_interaction_exclusions();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				foreach ( self::get_delay_js_compat_preset_map() as $setting_key => $slug ) {
					try {
						if ( ! empty( $toggles[ $setting_key ] ) ) {
							$chunks[] = self::get_delay_js_compat_preset_exclusions( (string) $slug );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
				}
				$merged = array();
				foreach ( $chunks as $chunk ) {
					if ( is_array( $chunk ) && ! empty( $chunk ) ) {
						$merged = array_merge( $merged, $chunk );
					}
				}
				$merged = array_values(
					array_unique(
						array_filter(
							array_map( 'strval', $merged ),
							static function ( $val ): bool {
								return '' !== $val;
							}
						)
					)
				);
				if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_delay_js_preset_level_exclusions' ) ) {
					return $merged;
				}
				try {
					$raw = apply_filters( 'wppo_delay_js_preset_level_exclusions', $merged, $level ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Intentional curated preset filter.
				} catch ( \Throwable $e ) {
					unset( $e );
					return $merged;
				}
				if ( ! is_array( $raw ) ) {
					return $merged;
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
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fail safe, never aggressive: a catastrophic getter failure
				// must still exclude the base preset, never delay everything.
				try {
					return self::get_delay_js_base_preset_exclusions();
				} catch ( \Throwable $ignored ) {
					unset( $ignored );
					return array();
				}
			}
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
		 */
		private static function filter_compat_preset_list( string $filter, array $preset ): array {
			// Intentionally unmemoized: the result must always reflect the
			// currently registered callbacks so a throwing filter degrades
			// to the curated list on every call (fail-open). The lists are
			// small and the unfiltered fast path short-circuits via
			// has_filter(), so rebuilding twice per uncached page is cheap.
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( $filter ) ) {
				return $preset;
			}
			try {
				$raw = apply_filters( $filter, $preset ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Intentional curated preset filter.
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
		 */
		public static function get_delay_js_consent_exclusions(): array {
			$preset = array(
				'cookieyes',
				'cookiebot',
				'complianz',
				'borlabs',
				'onetrust',
				'optanon',
				'trustarc',
				'iubenda',
				'osano',
				'termly',
				'usercentrics',
				'quantcast-choice',
				'tarteaucitron',
				'axeptio',
				'seers',
				'cookie-notice',
				'cookie-law-info',
				'gdpr',
				'ccpa',
				'consent',
			);
			return self::filter_compat_preset_list( 'wppo_delay_js_consent_exclusions', $preset );
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
		 */
		public static function get_delay_js_analytics_exclusions(): array {
			$preset = array(
				'gtag',
				'googletagmanager',
				'google-analytics',
				'analytics.js',
				'ga.js',
				'_ga',
				'gtm',
				'matomo',
				'piwik',
				'plausible',
				'fathom',
				'umami',
				'clarity',
				'crazyegg',
				'mixpanel',
				'segment',
				'amplitude',
				'posthog',
				'statcounter',
			);
			return self::filter_compat_preset_list( 'wppo_delay_js_analytics_exclusions', $preset );
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
		 */
		public static function get_delay_js_gallery_exclusions(): array {
			$preset = array(
				'photoswipe',
				'lightgallery',
				'fancybox',
				'prettyphoto',
				'magnific-popup',
				'featherlight',
				'justified-gallery',
				'envira',
				'foogallery',
				'nextgen',
				'modula',
				'jetpack-carousel',
				'wp-block-gallery',
				'carousel',
				'gallery',
			);
			return self::filter_compat_preset_list( 'wppo_delay_js_gallery_exclusions', $preset );
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
		 */
		public static function get_delay_js_jquery_exclusions(): array {
			$preset = array(
				'jquery',
				'jquery-core',
				'jquery-migrate',
				'jquery-ui',
				'jquery-ui-core',
				'jquery-blockui',
				'jquery-form',
				'jquery-validate',
				'jquery-cookie',
				'jquery-cycle',
				'jquery-easing',
			);
			return self::filter_compat_preset_list( 'wppo_delay_js_jquery_exclusions', $preset );
		}

		/**
		 * Compat preset slugs keyed by their settings key (issue #1308).
		 *
		 * Single source of truth for the four opt-in presets: settings key
		 * => preset slug used in per-page opt-out meta.
		 *
		 * @since 2.2.0
		 * @return array<string, string>
		 */
		public static function get_delay_js_compat_preset_map(): array {
			return array(
				'delayJSConsentPreset'   => 'consent',
				'delayJSAnalyticsPreset' => 'analytics',
				'delayJSGalleryPreset'   => 'gallery',
				'delayJSJqueryPreset'    => 'jquery',
			);
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
		 */
		public static function get_delay_js_compat_preset_exclusions( string $slug ): array {
			try {
				switch ( $slug ) {
					case 'consent':
						return self::get_delay_js_consent_exclusions();
					case 'analytics':
						return self::get_delay_js_analytics_exclusions();
					case 'gallery':
						return self::get_delay_js_gallery_exclusions();
					case 'jquery':
						return self::get_delay_js_jquery_exclusions();
					default:
						return array();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
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
			return self::is_safe_mode_active( $this->options['file_optimisation'] ?? array() );
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
		 */
		public static function get_delay_js_third_party_denylist(): array {
			$preset = array(
				'googletagmanager.com',
				'google-analytics.com',
				'analytics.google.com',
				'gtag',
				'googletag',
				'googleads',
				'doubleclick.net',
				'facebook.net',
				'fbevents',
				'connect.facebook.net',
				'platform.twitter.com',
				'platform.linkedin.com',
				'linkedin.com/insight',
				'hotjar.com',
				'static.hotjar.com',
				'intercom',
				'hubspot',
				'hs-scripts',
				'clarity.ms',
				'snapchat.com',
				'tiktok.com',
				'pinterest.com',
				'ads-twitter',
				'cdn.mxpnl.com',
				'mixpanel',
				'segment.com',
				'amplitude',
				'fullstory.com',
				'crazyegg.com',
				'optimizely.com',
				'vwo.com',
				'mouseflow.com',
				'luckyorange',
				'zendesk',
				'drift.com',
				'crisp.chat',
				'tawk.to',
				'livechatinc.com',
				'youtube.com/iframe_api',
				'player.vimeo.com',
				'wistia',
				'jwplayer',
				'disqus.com',
				'addthis.com',
				'sharethis.com',
				'quantserve.com',
				'scorecardresearch.com',
				'newrelic.com',
				'nr-data.net',
				'sentry.io',
				'bugsnag',
			);
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_delay_js_third_party_denylist' ) ) {
				return $preset;
			}
			try {
				$raw = apply_filters( 'wppo_delay_js_third_party_denylist', $preset );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $preset;
			}
			if ( ! is_array( $raw ) ) {
				return $preset;
			}
			$filtered = array_values(
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
			return $filtered;
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
		 */
		public static function get_delay_js_third_party_allowlist_for_slice( array $file_opt ): array {
			try {
				$raw              = $file_opt['delayJSThirdPartyAllowlist'] ?? '';
				$has_allow_filter = function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_delay_js_third_party_allowlist' );
				// Memoize per request keyed by the raw value when no filter
				// is registered (20-50 script tags/page would otherwise
				// re-parse + re-filter per tag). Bypassed when a filter is
				// present so dynamic callbacks always run.
				static $allow_cache = null;
				static $allow_key   = null;
				if ( is_string( $raw ) ) {
					$raw_key = 's:' . $raw;
				} elseif ( is_array( $raw ) ) {
					$safe    = array_map(
						static function ( $v ): string {
							return is_string( $v ) || is_numeric( $v ) ? (string) $v : gettype( $v );
						},
						array_values( $raw )
					);
					$raw_key = 'a:' . ( function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $safe ) : implode( "\0", $safe ) );
				} else {
					$raw_key = 'x:' . (string) $raw;
				}
				if ( ! $has_allow_filter && null !== $allow_cache && $raw_key === $allow_key ) {
					return $allow_cache;
				}
				$list = array();
				if ( is_string( $raw ) && '' !== trim( $raw ) ) {
					// process_urls() splits on newlines only; normalize commas
					// first so comma-pasted entries also split (#1217 review).
					$normalized = str_replace( ',', "\n", $raw );
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'process_urls' ) ) {
						$list = (array) Util::process_urls( $normalized );
					} else {
						$split = preg_split( '/[\r\n,]+/', $raw );
						$list  = array_filter( array_map( 'trim', is_array( $split ) ? $split : array() ) );
					}
				} elseif ( is_array( $raw ) ) {
					// Coerce via the shared helper so nested arrays are
					// dropped (not cast to literal 'Array').
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'coerce_string_list' ) ) {
						$list = Util::coerce_string_list( $raw );
					} else {
						$list = array_values(
							array_filter(
								array_map(
									static function ( $v ): string {
										return is_string( $v ) || is_numeric( $v ) ? trim( (string) $v ) : '';
									},
									$raw
								)
							)
						);
					}
				}
				if ( $has_allow_filter ) {
					$filtered = apply_filters( 'wppo_delay_js_third_party_allowlist', $list );
					if ( is_array( $filtered ) ) {
						// Filter output is untrusted: coerce via the shared
						// helper so non-string entries are dropped instead of
						// becoming 'Array'.
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'coerce_string_list' ) ) {
							$list = Util::coerce_string_list( $filtered );
						} else {
							$list = array_values(
								array_unique(
									array_filter(
										array_map(
											static function ( $val ): string {
												return is_string( $val ) || is_numeric( $val ) ? (string) $val : '';
											},
											$filtered
										),
										static function ( $val ): bool {
											return '' !== trim( (string) $val );
										}
									)
								)
							);
						}
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'coerce_string_list' ) ) {
					$list = Util::coerce_string_list( $list );
				} else {
					$list = array_values(
						array_unique(
							array_filter(
								array_map(
									static function ( $val ): string {
										return is_string( $val ) || is_numeric( $val ) ? (string) $val : '';
									},
									(array) $list
								),
								static function ( $val ): bool {
									return '' !== trim( (string) $val );
								}
							)
						)
					);
				}
				if ( ! $has_allow_filter ) {
					$allow_cache = $list;
					$allow_key   = $raw_key;
				}
				return $list;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
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
		 */
		public function get_delay_js_third_party_allowlist(): array {
			try {
				// Sandbox preview (#1217 review): read staged lists from the
				// effective slice so preview renders staged edits instead of
				// production values on the script_loader_tag path.
				$file_opt = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
				return self::get_delay_js_third_party_allowlist_for_slice( $file_opt );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
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
		 */
		public function is_delay_third_party_candidate( string $tag, string $handle ): bool {
			try {
				if ( ! preg_match( '/\ssrc\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $tag, $matches ) ) {
					return false;
				}
				$src = trim( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
				if ( '' === $src || 0 === stripos( $src, 'data:' ) || 0 === stripos( $src, 'blob:' ) ) {
					return false;
				}
				// User allowlist wins over everything. Handle matching uses the
				// word-boundary matcher (consistent with the rest of delay
				// matching) so short entries (gtag, intercom, …) do not match
				// unrelated first-party handles; src matching stays substring
				// because URLs rarely align on word boundaries (#1217 review).
				foreach ( $this->get_delay_js_third_party_allowlist() as $allowed ) {
					$allowed = trim( (string) $allowed );
					if ( '' === $allowed ) {
						continue;
					}
					if ( $this->matches_delay_pattern( (string) $handle, $allowed ) || false !== stripos( $src, $allowed ) ) {
						return false;
					}
				}
				// Curated denylist + user additions match handle or src.
				// Sandbox preview (#1217 review): user additions come from the
				// effective slice so staged edits preview on this path too.
				$denylist = self::get_delay_js_third_party_denylist();
				$file_opt = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
				$extra    = $file_opt['delayJSThirdPartyDenylist'] ?? '';
				if ( is_string( $extra ) && '' !== trim( $extra ) ) {
					// Normalize commas: process_urls() splits on newlines only.
					$extra_normalized = str_replace( ',', "\n", $extra );
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'process_urls' ) ) {
						$denylist = array_merge( $denylist, (array) Util::process_urls( $extra_normalized ) );
					} else {
						$split    = preg_split( '/[\r\n,]+/', $extra );
						$denylist = array_merge( $denylist, array_filter( array_map( 'trim', is_array( $split ) ? $split : array() ) ) );
					}
				} elseif ( is_array( $extra ) ) {
					$extra_list = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'coerce_string_list' ) ? Util::coerce_string_list( $extra ) : array();
					$denylist   = array_merge( $denylist, $extra_list );
				}
				foreach ( $denylist as $entry ) {
					$entry = trim( (string) $entry );
					if ( '' === $entry ) {
						continue;
					}
					if ( $this->matches_delay_pattern( (string) $handle, $entry ) || false !== stripos( $src, $entry ) ) {
						return true;
					}
				}
				// Host-based auto-detection: external host != site host.
				// Same-site hosts (apex, www, first-party subdomains/CDN) stay
				// eager — only genuinely foreign hosts auto-qualify (#1217
				// review). Fail-open: empty/unparseable hosts are not candidates.
				$site_host = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$home = function_exists( 'home_url' ) ? home_url() : '';
					if ( '' !== (string) $home ) {
						$site_host = strtolower( (string) wp_parse_url( (string) $home, PHP_URL_HOST ) );
					}
				}
				$src_host = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$candidate = $src;
					if ( 0 === strpos( $candidate, '//' ) ) {
						$candidate = 'https:' . $candidate;
					}
					$src_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
				}
				if ( '' !== $src_host && '' !== $site_host && ! self::is_same_site_script_host( $src_host, $site_host ) ) {
					return true;
				}
				// Relative src or same host with no denylist hit: not a candidate.
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
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
		 */
		public static function is_same_site_script_host( string $a, string $b ): bool {
			try {
				$normalize = static function ( string $host ): string {
					$host = strtolower( trim( $host ) );
					$host = rtrim( $host, '.' );
					$host = (string) preg_replace( '/^www\./', '', $host );
					return $host;
				};
				$a         = $normalize( $a );
				$b         = $normalize( $b );
				if ( '' === $a || '' === $b ) {
					return false;
				}
				if ( $a === $b ) {
					return true;
				}
				return substr( $a, -strlen( '.' . $b ) ) === '.' . $b || substr( $b, -strlen( '.' . $a ) ) === '.' . $a;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Per-request memo for the curated auto third-party patterns (#1314).
		 *
		 * Lazily booted by get_delay_js_third_party_auto_patterns() only when
		 * delay is enabled, so requests without Delay-JS never pay for the
		 * list build. Reset alongside the delay-context memo in tests.
		 *
		 * @since 2.2.0
		 * @var string[]|null
		 */
		private static ?array $delay_js_third_party_auto_cache = null;

		/**
		 * Blog id the auto-pattern memo was computed for.
		 *
		 * Guards multisite/long-lived workers against reusing site-A
		 * filtered patterns on site-B: the memo is only reused when the
		 * current blog id matches.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private static int $delay_js_third_party_auto_cache_blog = 0;

		/**
		 * Memoized pre-slash handle segments derived from the auto patterns.
		 *
		 * Rebuilt only when the pattern-list signature changes; shared by all
		 * tags on the script_loader_tag hot path. Reset alongside the
		 * auto-pattern memo in reset_delay_third_party_auto_cache().
		 *
		 * @since 2.2.0
		 * @var string[]
		 */
		private static array $delay_js_third_party_auto_handle_segments = array();

		/**
		 * Compiled alternation for the memoized handle segments above.
		 *
		 * @since 2.2.0
		 * @var string
		 */
		private static string $delay_js_third_party_auto_handle_re = '';

		/**
		 * Pattern-list signature the handle-segment memo was built for.
		 *
		 * Null = not built yet.
		 *
		 * @since 2.2.0
		 * @var string|null
		 */
		private static ?string $delay_js_third_party_auto_handle_key = null;

		/**
		 * Memoized commerce exclusions for the per-tag auto label (issue #1385 review).
		 *
		 * Per-tag memo: get_delay_js_third_party_auto_label() runs per script tag; without
		 * a memo it would rebuild the commerce list (with has_filter /
		 * apply_filters machinery) on every tag. Cached per blog id when no
		 * related filter is registered; bypassed when a filter is present so
		 * mid-request add_filter/remove_filter stays visible. Reset with
		 * reset_delay_third_party_auto_cache().
		 *
		 * @since 2.3.0
		 * @var string[]|null
		 */
		private static ?array $delay_js_auto_label_commerce = null;

		/**
		 * Memoized category buckets for the per-tag auto label (issue #1385 review).
		 *
		 * Same memo policy as $delay_js_auto_label_commerce above.
		 *
		 * @since 2.3.0
		 * @var array<string, string[]>|null
		 */
		private static ?array $delay_js_auto_label_categories = null;

		/**
		 * Blog id the auto-label memo was computed for.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private static int $delay_js_auto_label_blog = 0;

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
		 */
		public static function get_delay_js_third_party_auto_categories(): array {
			$categories = array(
				'analytics' => array(
					'googletagmanager.com',
					'google-analytics.com',
					'analytics.google.com',
					'static.hotjar.com',
					'hotjar.com',
					'clarity.ms',
					'cdn.mxpnl.com',
					'cdn.segment.com',
					'segment.io',
					'fullstory.com',
					'optimizely.com',
					'vwo.com',
					'mouseflow.com',
					'newrelic.com',
					'nr-data.net',
					'browser.sentry-cdn.com',
					'sentry.io',
				),
				'ads'       => array(
					'googlesyndication.com',
					'doubleclick.net',
				),
				'social'    => array(
					'connect.facebook.net',
					'facebook.net',
					'platform.twitter.com',
					'platform.linkedin.com',
					'linkedin.com/insight',
					'snap.licdn.com',
					'connect.tiktok.com',
					'platform.pinterest.com',
					'widget.intercom.io',
					'js.intercomcdn.com',
					'js.hs-scripts.com',
					'hs-scripts.com',
					'static.crisp.chat',
					'crisp.chat',
					'tawk.to',
					'youtube.com/iframe_api',
					'player.vimeo.com',
					'fast.wistia.com',
					'disqus.com',
				),
			);
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_delay_js_third_party_auto_categories' ) ) {
				return $categories;
			}
			try {
				$raw = apply_filters( 'wppo_delay_js_third_party_auto_categories', $categories ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Intentional curated preset filter.
			} catch ( \Throwable $e ) {
				unset( $e );
				return $categories;
			}
			if ( ! is_array( $raw ) ) {
				return $categories;
			}
			$filtered = array();
			foreach ( array( 'analytics', 'ads', 'social' ) as $bucket ) {
				if ( ! isset( $raw[ $bucket ] ) || ! is_array( $raw[ $bucket ] ) ) {
					$filtered[ $bucket ] = $categories[ $bucket ];
					continue;
				}
				$list = array();
				foreach ( $raw[ $bucket ] as $val ) {
					if ( is_string( $val ) || is_numeric( $val ) ) {
						$val = trim( (string) $val );
						if ( '' !== $val ) {
							$list[] = $val;
						}
					}
				}
				$filtered[ $bucket ] = ! empty( $list ) ? array_values( array_unique( $list ) ) : $categories[ $bucket ];
			}
			return $filtered;
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
		 */
		public static function get_delay_js_third_party_auto_label( string $src_or_handle ): string {
			try {
				$haystack = trim( $src_or_handle );
				if ( '' === $haystack ) {
					return '';
				}
				// Per-tag memo: rebuilding the commerce list plus the full
				// category buckets (with has_filter/apply_filters machinery)
				// on every script tag is wasteful on script-heavy pages, so
				// reuse the memoized copies when no related filter is
				// registered. Bypassed when a filter is present so dynamic
				// callbacks stay visible; keyed per blog id for multisite.
				$blog_id          = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				$has_label_filter = function_exists( 'has_filter' ) && ( has_filter( 'wppo_delay_js_commerce_exclusions' ) || has_filter( 'wppo_delay_js_third_party_auto_categories' ) );
				if ( ! $has_label_filter && null !== self::$delay_js_auto_label_commerce && null !== self::$delay_js_auto_label_categories && $blog_id === self::$delay_js_auto_label_blog ) {
					$commerce   = self::$delay_js_auto_label_commerce;
					$categories = self::$delay_js_auto_label_categories;
				} else {
					$commerce   = array();
					$categories = array();
					// Commerce skip: WooCommerce fragments plus cart AJAX never
					// carry a third-party label.
					try {
						$commerce = self::get_delay_js_commerce_exclusions();
					} catch ( \Throwable $e ) {
						unset( $e );
						$commerce = array();
					}
					try {
						$categories = self::get_delay_js_third_party_auto_categories();
					} catch ( \Throwable $e ) {
						unset( $e );
						$categories = array();
					}
					if ( ! $has_label_filter ) {
						self::$delay_js_auto_label_commerce   = $commerce;
						self::$delay_js_auto_label_categories = $categories;
						self::$delay_js_auto_label_blog       = $blog_id;
					}
				}
				foreach ( $commerce as $entry ) {
					$entry = trim( (string) $entry );
					if ( '' !== $entry && false !== stripos( $haystack, $entry ) ) {
						return '';
					}
				}
				foreach ( array( 'analytics', 'ads', 'social' ) as $bucket ) {
					if ( empty( $categories[ $bucket ] ) || ! is_array( $categories[ $bucket ] ) ) {
						continue;
					}
					foreach ( $categories[ $bucket ] as $pattern ) {
						$pattern = trim( (string) $pattern );
						if ( '' !== $pattern && false !== stripos( $haystack, $pattern ) ) {
							return $bucket;
						}
					}
				}
				return '';
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Reset the auto third-party pattern memo (for tests).
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_delay_third_party_auto_cache(): void {
			self::$delay_js_third_party_auto_cache           = null;
			self::$delay_js_third_party_auto_cache_blog      = 0;
			self::$delay_js_third_party_auto_handle_segments = array();
			self::$delay_js_third_party_auto_handle_re       = '';
			self::$delay_js_third_party_auto_handle_key      = null;
			self::$delay_js_auto_label_commerce              = null;
			self::$delay_js_auto_label_categories            = null;
			self::$delay_js_auto_label_blog                  = 0;
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
		 */
		public static function get_delay_js_third_party_auto_patterns(): array {
			$blog_id         = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			$has_auto_filter = function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_delay_js_third_party_auto_patterns' );
			// Bypass the memo when a filter is registered so mid-request
			// add_filter/remove_filter (or throwing-callback swaps) stay
			// visible; the no-filter preset path stays memoized per blog.
			if ( ! $has_auto_filter && null !== self::$delay_js_third_party_auto_cache && $blog_id === self::$delay_js_third_party_auto_cache_blog ) {
				return self::$delay_js_third_party_auto_cache;
			}
			// Single source of truth: the flat matcher merges the labelled
			// analytics/ads/social buckets (issue #1385) so categories and
			// patterns can never drift. Legacy fallback keeps the curated
			// flat list when the category API is unavailable.
			$preset = array();
			try {
				$categories = self::get_delay_js_third_party_auto_categories();
				foreach ( array( 'analytics', 'ads', 'social' ) as $bucket ) {
					if ( isset( $categories[ $bucket ] ) && is_array( $categories[ $bucket ] ) ) {
						$preset = array_merge( $preset, $categories[ $bucket ] );
					}
				}
				$preset = array_values( array_unique( $preset ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				$preset = array();
			}
			if ( empty( $preset ) ) {
				$preset = array(
					'googletagmanager.com',
					'google-analytics.com',
					'analytics.google.com',
					'googlesyndication.com',
					'doubleclick.net',
					'connect.facebook.net',
					'facebook.net',
					'platform.twitter.com',
					'platform.linkedin.com',
					'linkedin.com/insight',
					'snap.licdn.com',
					'static.hotjar.com',
					'hotjar.com',
					'clarity.ms',
					'cdn.mxpnl.com',
					'cdn.segment.com',
					'segment.io',
					'fullstory.com',
					'optimizely.com',
					'vwo.com',
					'mouseflow.com',
					'widget.intercom.io',
					'js.intercomcdn.com',
					'js.hs-scripts.com',
					'hs-scripts.com',
					'connect.tiktok.com',
					'platform.pinterest.com',
					'static.crisp.chat',
					'crisp.chat',
					'tawk.to',
					'youtube.com/iframe_api',
					'player.vimeo.com',
					'fast.wistia.com',
					'disqus.com',
					'newrelic.com',
					'nr-data.net',
					'browser.sentry-cdn.com',
					'sentry.io',
				);
			}
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_delay_js_third_party_auto_patterns' ) ) {
				self::$delay_js_third_party_auto_cache      = $preset;
				self::$delay_js_third_party_auto_cache_blog = $blog_id;
				return $preset;
			}
			try {
				$raw = apply_filters( 'wppo_delay_js_third_party_auto_patterns', $preset );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $preset;
			}
			if ( ! is_array( $raw ) ) {
				return $preset;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'coerce_string_list' ) ) {
				$filtered = Util::coerce_string_list( $raw );
			} else {
				$filtered = array_values(
					array_unique(
						array_filter(
							array_map(
								static function ( $val ): string {
									return is_string( $val ) || is_numeric( $val ) ? (string) $val : '';
								},
								$raw
							),
							static function ( $val ): bool {
								return '' !== trim( (string) $val );
							}
						)
					)
				);
			}
			// Empty array is a valid explicit choice: auto mode becomes a
			// silent no-op (documented, pinned by test). Filtered results
			// are never memoized so mid-request callback swaps stay visible.
			return $filtered;
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
		 */
		public static function matches_third_party_auto_pattern( string $handle, string $tag ): bool {
			try {
				$patterns = self::get_delay_js_third_party_auto_patterns();
				if ( empty( $patterns ) ) {
					return false;
				}
				$src = '';
				if ( '' !== $tag ) {
					// Plain-URL passthrough (callers pass an extracted $src):
					// skip the tag regex entirely when the input has no '<'.
					if ( false === strpos( $tag, '<' ) ) {
						$src = trim( $tag );
					} elseif ( preg_match( '/\ssrc\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', ' ' . $tag, $matches ) ) {
						$src = trim( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
					}
				}
				if ( '' !== $handle ) {
					// Memoize the derived pre-slash handle segments plus the
					// compiled alternation keyed by the pattern-list
					// signature, so T=20-50 tags of identical input share one
					// derivation instead of rebuilding per tag. Cleared with
					// the auto-pattern memo in
					// reset_delay_third_party_auto_cache().
					$sig = md5( implode( "\0", $patterns ) );
					if ( null === self::$delay_js_third_party_auto_handle_key || $sig !== self::$delay_js_third_party_auto_handle_key ) {
						$handle_patterns = array();
						foreach ( $patterns as $pattern ) {
							$pattern = trim( (string) $pattern );
							if ( '' === $pattern ) {
								continue;
							}
							$segment = explode( '/', $pattern )[0];
							$segment = trim( (string) $segment );
							if ( '' !== $segment ) {
								$handle_patterns[] = $segment;
							}
						}
						$handle_patterns                                 = array_values( array_unique( $handle_patterns ) );
						self::$delay_js_third_party_auto_handle_segments = $handle_patterns;
						self::$delay_js_third_party_auto_handle_re       = ! empty( $handle_patterns ) ? self::get_delay_patterns_regex( $handle_patterns ) : '';
						self::$delay_js_third_party_auto_handle_key      = $sig;
					}
					$handle_patterns = self::$delay_js_third_party_auto_handle_segments;
					if ( ! empty( $handle_patterns ) ) {
						$re = self::$delay_js_third_party_auto_handle_re;
						if ( '' === $re ) {
							$re                                        = self::get_delay_patterns_regex( $handle_patterns );
							self::$delay_js_third_party_auto_handle_re = $re;
						}
						if ( '' !== $re ) {
							try {
								$hit = preg_match( $re, $handle );
								if ( 1 === $hit ) {
									return true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
							if ( false === $hit ) {
								foreach ( $handle_patterns as $segment ) {
									try {
										if ( preg_match( '/\b' . preg_quote( $segment, '/' ) . '\b/', $handle ) ) {
											return true;
										}
									} catch ( \Throwable $e ) {
										unset( $e );
									}
								}
							}
						}
					}
				}
				if ( '' !== $src ) {
					foreach ( $patterns as $pattern ) {
						$pattern = trim( (string) $pattern );
						if ( '' !== $pattern && false !== stripos( $src, $pattern ) ) {
							return true;
						}
					}
					return false;
				}
				// Buffered-markup fallback: when no src attribute parsed
				// (opaque markup), match the pattern against the raw tag so
				// src-adjacent fragments still qualify. Documented
				// handle-vs-markup limitation: the buffered path has no
				// handle, so handle-keyword matches only apply on the
				// script_loader_tag path.
				if ( '' !== $tag ) {
					foreach ( $patterns as $pattern ) {
						$pattern = trim( (string) $pattern );
						if ( '' !== $pattern && false !== stripos( $tag, $pattern ) ) {
							return true;
						}
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
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
		 */
		public function is_delay_third_party_auto_candidate( string $tag, string $handle ): bool {
			try {
				if ( ! preg_match( '/\ssrc\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $tag, $matches ) ) {
					return false;
				}
				$src = trim( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
				if ( '' === $src || 0 === stripos( $src, 'data:' ) || 0 === stripos( $src, 'blob:' ) ) {
					return false;
				}
				// Builder/commerce guardrail: unconditional, mirrors the
				// add_defer_attribute() gate for defence in depth.
				try {
					if ( self::is_delay_excluded_context() ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
				// Commerce-handle skip (issue #1385): WooCommerce fragments
				// plus cart AJAX (wc-cart-fragments, add-to-cart,
				// cart-fragments) never auto-delay, even outside excluded
				// contexts, via get_delay_js_commerce_exclusions(). Per-tag
				// memo (issue #1385 review): reuse the auto-label commerce
				// memo when no commerce filter is registered so
				// script-heavy pages skip the has_filter/apply_filters
				// machinery per tag; bypassed when the filter is present.
				try {
					$candidate_blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
					$has_commerce_filter = function_exists( 'has_filter' ) && has_filter( 'wppo_delay_js_commerce_exclusions' );
					if ( ! $has_commerce_filter && null !== self::$delay_js_auto_label_commerce && $candidate_blog_id === self::$delay_js_auto_label_blog ) {
						$commerce_excludes = self::$delay_js_auto_label_commerce;
					} else {
						$commerce_excludes = self::get_delay_js_commerce_exclusions();
						if ( ! $has_commerce_filter ) {
							self::$delay_js_auto_label_commerce = $commerce_excludes;
							self::$delay_js_auto_label_blog     = $candidate_blog_id;
						}
					}
					$commerce_haystack = strtolower( (string) $handle . "\0" . $src . "\0" . $tag );
					foreach ( $commerce_excludes as $entry ) {
						$entry = strtolower( trim( (string) $entry ) );
						if ( '' !== $entry && false !== strpos( $commerce_haystack, $entry ) ) {
							return false;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// User allowlist wins over auto patterns (parity with the
				// manual third-party path). Handle matching uses the single
				// precompiled alternation (not one PCRE compile per entry);
				// src matching stays substring. The shared slice helper
				// memoizes the parse per request keyed by the raw value, so
				// per-tag cost is a memo hit, not a process_urls + filter
				// fan-out.
				$file_opt_for_allow = self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() );
				$allowlist          = self::get_delay_js_third_party_allowlist_for_slice( $file_opt_for_allow );
				if ( '' !== (string) $handle && ! empty( $allowlist ) && $this->matches_any_delay_pattern( (string) $handle, $allowlist ) ) {
					return false;
				}
				// Match the allowlist against the full tag as well as src
				// (parity with the manual path): an entry naming an element
				// id/class or inline context must exempt even when it is not
				// a URL substring. NUL-joined so an entry can never span fields.
				$allow_haystack = (string) $tag . "\0" . $src;
				foreach ( $allowlist as $allowed ) {
					$allowed = trim( (string) $allowed );
					if ( '' !== $allowed && false !== stripos( $allow_haystack, $allowed ) ) {
						return false;
					}
				}
				return self::matches_third_party_auto_pattern( (string) $handle, $src );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
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
		 */
		private static function inject_delay_script_attr( string $tag, string $insert ): string {
			try {
				$result = preg_replace( '/<script(?=[\s>])/i', '<script ' . $insert, $tag, 1 );
				return is_string( $result ) ? $result : $tag;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $tag;
			}
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
		 */
		public static function get_delay_js_base_preset_exclusions(): array {
			return array(
				'recaptcha',
				'google-recaptcha',
				'grecaptcha',
				'stripe',
				'gtag',
				'googletagmanager',
				'google-analytics',
				'analytics',
				'gtm',
				'fbevents',
				'facebook',
				'hotjar',
				'intercom',
				'hubspot',
				'linkedin',
				'twitter',
				'paypal',
				// Form plugins safe list.
				'contact-form-7',
				'wpcf7',
				'gravityforms',
				'gform',
				'wpforms',
				'ninja-forms',
				'fluentform',
				// Elementor base handles stay global so builder pages never break
				// even when the builder preset toggle is off.
				'elementor',
				'elementor-frontend',
				'elementor-pro',
			);
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
		 */
		private static function get_delay_js_protected_exclusions( array $file_opt ): array {
			try {
				$protected    = array( 'wppo-lazyload', 'data-wppo-preserve' );
				$has_util_api = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'process_urls' );
				$has_excludes = ! empty( $file_opt['excludeDelayJS'] );
				if ( $has_excludes && $has_util_api ) {
					try {
						$protected = array_merge( $protected, (array) Util::process_urls( $file_opt['excludeDelayJS'] ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$protected  = array_merge( $protected, self::get_delay_js_base_preset_exclusions() );
				$builder_on = ! isset( $file_opt['delayJSBuilderPreset'] ) || ! empty( $file_opt['delayJSBuilderPreset'] );
				if ( $builder_on ) {
					try {
						$protected = array_merge( $protected, self::get_delay_js_builder_exclusions(), self::get_delay_js_slider_exclusions() );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$commerce_on = ! isset( $file_opt['delayJSCommercePreset'] ) || ! empty( $file_opt['delayJSCommercePreset'] );
				if ( $commerce_on ) {
					try {
						$protected = array_merge( $protected, self::get_delay_js_commerce_exclusions() );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$interaction_on = ! isset( $file_opt['delayJSInteractionPreset'] ) || ! empty( $file_opt['delayJSInteractionPreset'] );
				if ( $interaction_on ) {
					try {
						$protected = array_merge( $protected, self::get_delay_js_interaction_exclusions() );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return $protected;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
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
		 */
		private function get_delay_js_preset_exclusions(): array {
			$preset = self::get_delay_js_base_preset_exclusions();
			// Commerce safe preset (#988): safe-by-default on; merges jQuery +
			// cart-fragments/checkout handles unless explicitly disabled.
			// Missing key backfills to on (per-site settings, multisite-safe).
			$commerce_on = ! isset( $this->options['file_optimisation']['delayJSCommercePreset'] )
			|| ! empty( $this->options['file_optimisation']['delayJSCommercePreset'] );
			if ( $commerce_on ) {
				$preset = array_merge( $preset, self::get_delay_js_commerce_exclusions() );
			}
			// Builder safe preset (#966): safe-by-default on; merges builder
			// runtime handles plus slider runtimes (#988) unless explicitly disabled.
			// Missing key backfills to on (per-site settings, multisite-safe).
			$builder_on = ! isset( $this->options['file_optimisation']['delayJSBuilderPreset'] )
			|| ! empty( $this->options['file_optimisation']['delayJSBuilderPreset'] );
			if ( $builder_on ) {
				$preset = array_merge( $preset, self::get_delay_js_builder_exclusions(), self::get_delay_js_slider_exclusions() );
			}
			// Interaction safe preset (#1055): first-click popup/dialog,
			// mobile-menu, and add-to-cart handles. Safe-by-default on;
			// missing key backfills to on (per-site settings, multisite-safe).
			$interaction_on = ! isset( $this->options['file_optimisation']['delayJSInteractionPreset'] )
			|| ! empty( $this->options['file_optimisation']['delayJSInteractionPreset'] );
			if ( $interaction_on ) {
				$preset = array_merge( $preset, self::get_delay_js_interaction_exclusions() );
			}
			// Opt-in compat presets (#1308): consent, analytics, gallery, jquery.
			// Off by default (missing key backfills to off) so upgrades preserve
			// manual exclusions and delay behavior is unchanged. Each active
			// preset merges additively, never replacing manual exclusions.
			// Lazy-booted: matchers run only when their preset is enabled.
			// Per-preset try/catch: a throw on one preset must not abort the
			// remaining presets (inner getters already fail open per preset).
			// Chunk-collect plus a single merge (consistent with the per-page
			// opt-out and Minify\HTML paths) instead of O(k^2) merges.
			$compat_chunks = array();
			foreach ( self::get_delay_js_compat_preset_map() as $setting_key => $slug ) {
				try {
					if ( ! empty( $this->options['file_optimisation'][ $setting_key ] ) ) {
						$compat_chunks[] = self::get_delay_js_compat_preset_exclusions( $slug );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					continue;
				}
			}
			if ( ! empty( $compat_chunks ) ) {
				$preset = array_merge( $preset, ...$compat_chunks );
			}
			// Breaker presets ship deduped via array_unique (#1037) so builder +
			// commerce + user excludes never double-process; string-only values.
			$preset = array_values(
				array_unique(
					array_filter(
						array_map( 'strval', (array) $preset ),
						static function ( $val ): bool {
							return '' !== $val;
						}
					)
				)
			);
			/**
			 * Filters delay JS preset exclusions.
			 *
			 * @since 2.0.0
			 * @param string[] $preset Preset exclusions.
			 */
			if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_delay_js_exclusions' ) ) {
				return $preset;
			}
			// Fail-open (#1037 review): a misbehaving filter must never fatal the
			// frontend script/style path. Guard with is_string/is_numeric checks
			// (no blind strval — objects without __toString would throw Error)
			// and fall back to the preset on any throwable.
			try {
				$raw = apply_filters( 'wppo_delay_js_exclusions', $preset );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $preset;
			}
			if ( ! is_array( $raw ) ) {
				return $preset;
			}
			$filtered = array_values(
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
			return $filtered;
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
		 */
		public function is_delay_js_safe_context(): bool {
			try {
				// Explicit opt-out (delayJSSafeMode=false) skips all checks and
				// returns false (delay allowed) — safe mode off means no
				// protection is applied. An unset key preserves the legacy
				// behavior of running the checks.
				$safe_mode = $this->options['file_optimisation']['delayJSSafeMode'] ?? null;
				if ( false !== $safe_mode ) {
					if ( function_exists( 'is_cart' ) && is_cart() ) {
						return true;
					}
					if ( function_exists( 'is_checkout' ) && is_checkout() ) {
						return true;
					}
					if ( function_exists( 'is_account_page' ) && is_account_page() ) {
						return true;
					}
					if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
						return true;
					}
					if ( function_exists( 'get_the_ID' ) ) {
						$post_id = get_the_ID();
						if ( ! empty( $post_id ) ) {
							$shortcodes = array( 'contact-form-7', 'gravityform', 'wpforms', 'ninja_forms', 'fluentform' );
							foreach ( $shortcodes as $shortcode ) {
								if ( function_exists( 'has_shortcode' ) ) {
									$post_content = '';
									if ( function_exists( 'get_post_field' ) ) {
										$post_content = (string) get_post_field( 'post_content', $post_id );
									}
									if ( '' !== $post_content && has_shortcode( $post_content, $shortcode ) ) {
										return true;
									}
								}
							}
							if ( function_exists( 'has_block' ) ) {
								$blocks = array( 'contact-form-7/contact-form-selector', 'gravityforms/form', 'wpforms/form-selector', 'ninja-forms/form', 'fluentfom/gutenblock', 'elementor-widget/form' );
								foreach ( $blocks as $block ) {
									if ( has_block( $block, $post_id ) ) {
										return true;
									}
								}
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				return true;
			}
			return false;
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
		 */
		public function add_fetchpriority_to_deferred( $tag, $handle ): string {
			if ( ! isset( $this->deferred_handles[ $handle ] ) ) {
				return $tag;
			}
			if ( preg_match( '/\sfetchpriority\s*=/i', (string) $tag ) ) {
				return $tag;
			}
			// @since 2.2.0: filtered value with has_filter guards; fail-open.
			$fetchpriority = $this->get_filtered_deferred_fetchpriority( (string) $handle );
			if ( '' === $fetchpriority ) {
				return $tag;
			}
			return self::inject_delay_script_attr( $tag, 'fetchpriority="' . $fetchpriority . '" ' );
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
				$preload_settings = $this->options['preload_settings'] ?? array();
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

			$preload_settings = $this->options['preload_settings'] ?? array();

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
			// never fatal.
			try {
				if ( isset( $GLOBALS['wp_version'] ) ) {
					$wp_version = (string) $GLOBALS['wp_version'];
				} elseif ( function_exists( 'get_bloginfo' ) ) {
					$wp_version = (string) get_bloginfo( 'version' );
				} else {
					$wp_version = '6.8';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$wp_version = '6.8';
			}
			if ( version_compare( $wp_version, '6.8', '<' ) ) {
				return;
			}

			$preload_settings   = $this->options['preload_settings'] ?? array();
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
				if ( empty( $this->options['cache_settings']['enableCache'] ) ) {
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
				! empty( $this->options['cache_settings']['enableCache'] ) &&
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
				$raw   = $this->options['preload_settings']['speculationTopUrlsLimit'] ?? 2;
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

				$document_rules = $this->options['preload_settings']['speculationDocumentRules'] ?? true;
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

				$document_rules = $this->options['preload_settings']['speculationDocumentRules'] ?? true;
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

				$preload_settings = $this->options['preload_settings'] ?? array();
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
				if ( empty( $this->options['preload_settings']['enableSpeculationRules'] ) ) {
					return array();
				}
				if ( empty( $this->options['preload_settings']['speculationPrerenderList'] ) ) {
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
					try {
						if ( isset( $GLOBALS['wp_version'] ) ) {
							$wp_version = (string) $GLOBALS['wp_version'];
						} elseif ( function_exists( 'get_bloginfo' ) ) {
							$wp_version = (string) get_bloginfo( 'version' );
						} else {
							$wp_version = '6.8';
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$wp_version = '6.8';
					}
					if ( version_compare( $wp_version, '6.8', '<' ) ) {
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

			if ( empty( $this->options['preload_settings']['enableSpeculationRules'] ) ) {
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

			$preload_settings = $this->options['preload_settings'] ?? array();
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
		 * {@see register_block_assets_filters()}). The combined monolith escape
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
				if ( ! isset( $GLOBALS['wp_version'] ) || version_compare( (string) $GLOBALS['wp_version'], '6.9-alpha', '>=' ) ) {
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
			if ( isset( $GLOBALS['wp_version'] ) && version_compare( $GLOBALS['wp_version'], '6.9-alpha', '<' ) ) {
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
		 * {@see register_block_assets_filters()}: the omission pass only runs
		 * when on-demand block assets are enabled (`blockAssetsOnDemand` on) and
		 * the combined monolith is not forced (`loadAllCoreBlockAssets` off).
		 * Users who explicitly disabled on-demand assets keep the legacy
		 * monolith untouched.
		 *
		 * @since 2.2.0
		 *
		 * @return bool True when hidden block assets may be omitted.
		 */
		private function is_hidden_block_asset_omission_enabled(): bool {
			return ! empty( $this->options['file_optimisation']['blockAssetsOnDemand'] )
			&& empty( $this->options['file_optimisation']['loadAllCoreBlockAssets'] );
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
				if ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '6.9-alpha', '<' ) ) {
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
			if ( ! function_exists( 'wp_maybe_inline_styles' ) ) {
				return;
			}

			// Sandbox preview (issue #1163): the preview admin renders staged
			// output even without logged-in cache enabled; visitors keep the
			// production gate.
			$is_preview = self::is_sandbox_preview_active();
			if ( ! $this->should_optimise_for_logged_in() && ! $is_preview ) {
				return;
			}

			// The combine feature owns the whole pipeline; let it handle these handles.
			// In preview the staged combineCSS flag wins so the two pipelines
			// cannot run on the same handles.
			$file_opt_for_minify = $is_preview ? self::get_effective_file_optimisation( $this->options['file_optimisation'] ?? array() ) : ( $this->options['file_optimisation'] ?? array() );
			if ( ! empty( $file_opt_for_minify['combineCSS'] ) ) {
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

				// On WP 6.9+ with separate (on-demand) core block assets active, never
				// rewrite core block-asset stylesheets — core loads them conditionally
				// for the blocks on the page (see is_core_block_asset_skipped()).
				if ( $this->is_core_block_asset_skipped( $handle ) ) {
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
			// On WP 6.9+ with separate (on-demand) core block assets active, never
			// rewrite core block-asset stylesheets — core loads them conditionally
			// for the blocks on the page (see is_core_block_asset_skipped()).
			if ( $this->is_core_block_asset_skipped( $handle ) ) {
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

			// Fail-open safe-mode (#1037): any engine throwable degrades to the
			// pristine tag — engine failure never fatals or white-screens.
			try {
				$css_minifier = new Minify\CSS( $local_path, Util::min_cache_dir( 'css' ) );
				$cached_file  = $css_minifier->minify();
			} catch ( \Throwable $e ) {
				unset( $e );
				return $tag;
			}

			if ( $cached_file ) {
				$basename         = basename( $cached_file );
				$content_url      = Util::min_cache_url( 'css', $basename );
				$cached_file_path = $css_minifier->get_cache_file_path();

				if ( empty( $cached_file_path ) || ! file_exists( $cached_file_path ) ) {
					return $tag;
				}

				// filemtime() returns false (with a warning) on failure — it never
				// throws — so fail open on the false check below.
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

			// Disk-safe guard (issue #1428): randomized per-request query
			// values (?ver=<timestamp|uniqid|rand>) are excluded from the
			// minify pipeline so they cannot churn minified output against
			// the file-count cap. Guarded by cacheRandomizedQueryGuard
			// (default on), filterable via wppo_exclude_randomized_from_combine.
			try {
				$guard_on = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'get_cache_cap_settings' ) ) {
					$cap      = Cache::get_cache_cap_settings();
					$guard_on = ! empty( $cap['randomized_guard'] );
				}
				if ( $guard_on && class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'is_randomized_query_asset' ) && Cache::is_randomized_query_asset( (string) $src ) ) {
					$excluded = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'wppo_exclude_randomized_from_combine', true, $handle, $src ) : true;
					if ( $excluded ) {
						return $tag;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
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

			// Fail-open safe-mode (#1037): any engine throwable degrades to the
			// pristine tag — engine failure never fatals or white-screens.
			try {
				$js_minifier = new Minify\JS( $local_path, Util::min_cache_dir( 'js' ) );
				$cached_file = $js_minifier->minify();
			} catch ( \Throwable $e ) {
				unset( $e );
				return $tag;
			}

			if ( $cached_file ) {
				$basename         = basename( $cached_file );
				$content_url      = Util::min_cache_url( 'js', $basename );
				$cached_file_path = $js_minifier->get_cache_file_path();

				if ( empty( $cached_file_path ) || ! file_exists( $cached_file_path ) ) {
					return $tag;
				}

				// filemtime() returns false (with a warning) on failure — it never
				// throws — so fail open on the false check below.
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
			if ( empty( $file_path ) || ! is_string( $file_path ) ) {
				return true;
			}

			// Containment: only stat files under WP_CONTENT_DIR/ABSPATH so a
			// poisoned wppo_* filter returning an absolute path cannot cause
			// arbitrary local file stat/read.
			$normalized = wp_normalize_path( $file_path );
			$content    = wp_normalize_path( (string) WP_CONTENT_DIR );
			$base       = wp_normalize_path( (string) ABSPATH );
			if ( 0 !== strpos( $normalized, $content . '/' ) && 0 !== strpos( $normalized, $base ) ) {
				return true;
			}

			if ( $this->is_minified_asset_name( $file_path, $type ) ) {
				return true;
			}

			if ( ! file_exists( $file_path ) ) {
				return true;
			}

			$file_size = filesize( $file_path );
			if ( false === $file_size ) {
				return true;
			}
			$file_mtime = filemtime( $file_path );
			if ( false === $file_mtime ) {
				return true;
			}

			// Fold mtime+size into the key so an updated plugin/theme
			// asset cannot serve a stale 'already minified, skip' verdict
			// for up to an hour after the file changes.
			$cache_key   = 'min_' . $type . '_' . md5( $file_path . '|' . $file_mtime . '|' . $file_size );
			$cache_group = 'wppo_minify_check';
			$found       = false;
			$cached      = wp_cache_get( $cache_key, $cache_group, false, $found );

			if ( $found ) {
				return (bool) $cached;
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
