<?php
/**
 * Hook registry — WordPress hook registration extracted from Main::setup_hooks().
 *
 * Focused extraction (REF-005) of the hook-registration responsibility cluster
 * previously owned by the `Main` god class (`Main::setup_hooks()` plus the
 * `register_*` group helpers). Hook *registration* only: the same hooks, the
 * same callbacks, the same priorities, and the same order — owned here so the
 * full hook manifest stays auditable in one place without touching feature
 * code. Callbacks stay methods on `Main`/collaborators; option-state
 * preparation (exclusion lists, delay-JS strategy state) and collaborator
 * construction stay on `Main` (see the `@internal` hook-state helpers).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Hook_Registry' ) ) {
	/**
	 * Class Hook_Registry
	 *
	 * Owns WordPress hook registration for the plugin. Constructed with the
	 * `Main` instance (kept as the callback target so hook-callback identity
	 * is unchanged) plus the collaborators it needs as callback targets and
	 * the options snapshot driving the registration gates. `register()` is a
	 * verbatim relocation of `Main::setup_hooks()`; the per-group helpers
	 * keep their names so hook-group changes stay local to one method.
	 *
	 * @since 2.4.0
	 */
	final class Hook_Registry {

		/**
		 * Main instance serving as the hook-callback target.
		 *
		 * Held by reference so `array( $main, 'method' )` callbacks keep the
		 * exact identity they had as `array( $this, 'method' )` on Main
		 * (symmetric removal in Deactivate::unregister_runtime_hooks()
		 * depends on it).
		 *
		 * @since 2.4.0
		 * @var   Main
		 */
		private Main $main;

		/**
		 * Options snapshot driving the registration gates.
		 *
		 * Read-only copy of `Main::$options` taken at construction.
		 * `Main::setup_hooks()` never writes options, so the copy cannot
		 * drift within a registration pass.
		 *
		 * @since 2.4.0
		 * @var   array
		 */
		private array $options;

		/**
		 * Image_Optimisation instance used as a hook-callback target.
		 *
		 * @since 2.4.0
		 * @var   Image_Optimisation
		 */
		private Image_Optimisation $image_optimisation;

		/**
		 * Google_Fonts instance used as a hook-callback target.
		 *
		 * @since 2.4.0
		 * @var   Google_Fonts
		 */
		private Google_Fonts $google_fonts;

		/**
		 * Constructor.
		 *
		 * @since 2.4.0
		 * @param Main               $main               Main instance (callback target).
		 * @param array              $options            Main options snapshot (registration gates only).
		 * @param Image_Optimisation $image_optimisation Image optimisation callback target.
		 * @param Google_Fonts       $google_fonts       Google Fonts callback target.
		 */
		public function __construct( Main $main, array $options, Image_Optimisation $image_optimisation, Google_Fonts $google_fonts ) {
			$this->main               = $main;
			$this->options            = $options;
			$this->image_optimisation = $image_optimisation;
			$this->google_fonts       = $google_fonts;
		}

		/**
		 * Register every plugin hook.
		 *
		 * Verbatim relocation of `Main::setup_hooks()`: hook names,
		 * priorities, accepted-args, conditional gates (version checks,
		 * `is_admin()`, LiteSpeed coexistence branches), and registration
		 * order are byte-identical. Option-state preparation and cache
		 * creation stay on `Main` via its `@internal` hook-state helpers,
		 * called here at the exact positions the code ran before.
		 *
		 * @since 2.4.0
		 * @return void
		 */
		public function register(): void {
			$main    = $this->main;
			$options = $this->options;

			add_action( 'admin_menu', array( $main, 'init_menu' ) );
			add_action( 'admin_init', array( $main, 'maybe_fix_wp_cache' ) );
			add_action( 'admin_init', array( $main, 'maybe_run_upgrades' ) );
			add_action( 'wppo_run_upgrades', array( 'PerformanceOptimise\Inc\Activate', 'maybe_run_upgrades' ) );
			add_action( 'upgrader_process_complete', array( $main, 'maybe_schedule_upgrade_routine' ), 10, 2 );
			add_action( 'admin_init', array( $main, 'maybe_run_version_upgrade' ) );
			add_action( 'upgrader_process_complete', array( $main, 'maybe_run_version_upgrade' ), 10, 0 );
			// Builder-update purge watcher (issue #907): scoped purge of builder
			// CSS + WPPO caches after Elementor/Divi/Bricks/WPBakery updates.
			// No settings UI; the watcher self-gates to builder slugs.
			if ( class_exists( 'PerformanceOptimise\Inc\Builder_Purge_Watcher' ) ) {
				( new Builder_Purge_Watcher() )->register();
			}
			add_action( 'admin_init', array( $main, 'maybe_migrate_block_assets_setting' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_ccss_max_size' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_ccss_safelist' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_css_queue_defaults' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_speculation_top_urls' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_speculation_prerender_list' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_rum_sample_rate' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_safe_mode' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_elementor_safe_mode' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_image_alt_edge_defaults' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_preload_auto_defaults' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_object_cache_outage_flag' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_ai_speculation_autotune' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_ai_anomaly_v2' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_comment_image_hardening' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_builder_watcher' ) );
			add_action( 'admin_init', array( $main, 'maybe_migrate_third_party_auto' ) );
			add_action( 'admin_enqueue_scripts', array( $main, 'admin_enqueue_scripts' ) );
			add_action( 'init', array( $main, 'set_role_hash_cookie' ) );
			add_action( 'wp_logout', array( $main, 'clear_role_hash_cookie' ) );
			add_action( 'wp_enqueue_scripts', array( $main, 'enqueue_scripts' ) );
			add_action( 'wp_enqueue_scripts', array( $main, 'apply_module_loading_strategies' ), 10000 );
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
			$safe_mode_off = ! Main::is_safe_mode_active( $options['file_optimisation'] ?? array() );
			$has_delay_js  = ! empty( $options['file_optimisation']['delayJS'] ) && $safe_mode_off;
			$has_defer_js  = ! empty( $options['file_optimisation']['deferJS'] ) && $safe_mode_off;
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
			// Version gating uses the canonical Main::supports_*() predicates below.
			// The native 'strategy' script data added via wp_script_add_data() is only
			// honoured by core since WP 6.3, so the native defer path is gated to 6.3+
			// and older core (WP 6.2) uses the legacy script_loader_tag fallback.
			// Note(#553): remove the legacy fallback when minimum supported WP is raised to 6.3.
			// Issue #1203 (modularity) proposed removing the shims now; deferred —
			// the floor is still 6.2, so deletion would drop defer-JS on 6.2 with no
			// native replacement. One canonical path per install is already enforced
			// by the version+feature gate below (never both regex passes per request).
			// Issue #1218: version_compare alone can mis-route on a backported or
			// filtered version string, so the canonical predicate is
			// supports_native_defer_strategy() (version + function_exists +
			// a genuinely-6.3 WP_Scripts method probe when the class exists).
			$use_native_defer = Main::supports_native_defer_strategy();
			// Native script fetchpriority capability (issue #1218): version +
			// API probe via supports_native_script_fetchpriority() so a
			// filtered version string cannot enable native fetchpriority
			// writes where the API is absent. Template-enhancement buffer
			// routing below uses the canonical
			// should_use_core_template_buffer() predicate (version + API
			// probe, issue #1386) — never a bare version_compare.
			$supports_fetchpriority = Main::supports_native_script_fetchpriority();
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
			// Note(#553, #829): remove the legacy buffer paths when minimum supported WP is raised to 6.9.
			// Blocked until `Requires at least: 6.9`.
			$use_core_buffer = Main::should_use_core_template_buffer();

			// Delay JS: the script_loader_tag filter performs the wppo-src/type rewriting
			// on every supported version, so it is always registered when delay JS is on.
			// Sandbox preview (issue #1163): the per-page kill-switch hook sets
			// both delay_disabled_for_page and defer_disabled_for_page, so the
			// second disjunct also honors staged defer — otherwise a
			// staged-defer-only preview would defer even on kill-switched pages.
			if ( $has_delay_js || ! empty( $options['file_optimisation']['deferJS'] ) || $staged_defer_js ) {
				add_action( 'wp', array( $main, 'apply_per_page_delay_config' ) );
			}
			if ( $has_delay_js ) {
				add_filter( 'script_loader_tag', array( $main, 'add_defer_attribute' ), 10, 2 );
			}

			// Defer JS: use the native strategy on WP 6.3+, the script_loader_tag
			// fallback on older core.
			if ( $has_defer_js ) {
				if ( $use_native_defer ) {
					add_action( 'wp_enqueue_scripts', array( $main, 'add_defer_strategy' ), 1000 );
				} else {
					add_filter( 'script_loader_tag', array( $main, 'add_defer_attribute_legacy' ), 10, 2 );
				}
				// Native fetchpriority rendering arrived in WP 6.9 (Trac #61734); the
				// regex-based script_loader_tag fallback only runs on older cores.
				if ( ! $supports_fetchpriority ) {
					add_filter( 'script_loader_tag', array( $main, 'add_fetchpriority_to_deferred' ), 11, 2 );
				}
			}
			add_action( 'admin_bar_menu', array( $main, 'add_setting_to_admin_bar' ), 100 );

			if ( ! empty( $options['file_optimisation']['removeWooCSSJS'] ) ) {
				add_action( 'wp_enqueue_scripts', array( $main, 'remove_woocommerce_scripts' ), 999 );
			}

			if ( ! empty( $options['cache_settings']['enableCache'] ) ) {
				$cache = $main->ensure_hook_cache_for_registry( true );
				if ( $use_core_buffer ) {
					// WP 6.9+ core template-enhancement buffer (issue #1386):
					// single-buffer routing — filter processes, finalized action
					// persists. No private ob_start capture is registered, so
					// exactly one core buffer is active when consumers exist and
					// zero when none; a runtime opt-out (filter false) degrades
					// to uncached streaming output. Intentional (see above):
					// caching resumes automatically when core opts back in.
					add_filter( 'wp_template_enhancement_output_buffer', array( $cache, 'process_buffer_for_cache' ), 10, 2 );
					add_action( 'wp_finalized_template_enhancement_output_buffer', array( $cache, 'stash_cache' ) );
				} else {
					// Legacy buffer path (pre-6.9 only): the only cache path on
					// older cores. Retired on 6.9+ (issue #1386) — never
					// registered alongside the core buffer.
					add_action( 'template_redirect', array( $cache, 'start_output_buffer' ) );
				}
				// Post-purge last-good fallback (issue #1275): Nginx falls
				// through to index.php?wppo_purge_fallback=$uri on a miss
				// under the cache path; this 302s to the retained sibling
				// fallback when one exists. Self-gated (no-op when disabled).
				// has_action() guard: the combine-only branch below registers
				// the same callback, and WP would dedupe today but a future
				// second Cache instance or priority change must not double-fire.
				if ( ! has_action( 'template_redirect', array( $cache, 'maybe_serve_purge_fallback' ) ) ) {
					add_action( 'template_redirect', array( $cache, 'maybe_serve_purge_fallback' ), 1 );
				}
				add_action( 'save_post', array( $main, 'on_save_post_invalidate_cache' ), 10, 3 );
				// Per-page delay kill-switch single-URL purge (#1037, extended #1098):
				// programmatic `_wppo_delay_disabled` / `_wppo_defer_disabled` /
				// `_wppo_used_css_disabled` writes (REST, WP-CLI, imports) purge
				// that URL only via invalidate_aggressive_kill_switch_cache() —
				// never a full-cache wipe. The metabox save path is covered by
				// save_post above plus the toggle check in
				// Metabox::save_asset_manager_settings().
				// add_action() on these hooks is harmless when meta is untouched;
				// callbacks ignore every key except the three kill-switches.
				add_action( 'added_post_meta', array( $main, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'updated_post_meta', array( $main, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'deleted_post_meta', array( $main, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				// WooCommerce surgical invalidation (issue #962): product /
				// order / coupon changes purge only affected URLs — never a
				// full-cache wipe. add_action() on unregistered hooks is
				// harmless on non-Woo installs; callbacks guard WC APIs at
				// call time for WP 6.2 / PHP 8.2 compat. @since 2.0.0.
				add_action( 'woocommerce_update_product', array( $main, 'on_woocommerce_product_updated' ), 10, 1 );
				add_action( 'woocommerce_checkout_order_created', array( $main, 'on_woocommerce_order_changed' ), 10, 1 );
				add_action( 'woocommerce_update_order', array( $main, 'on_woocommerce_order_changed' ), 10, 1 );
				add_action( 'woocommerce_coupon_options_save', array( $main, 'on_woocommerce_coupon_saved' ), 10, 1 );
			}

			// Server-Timing (WP 6.9+). Registering wp_finalized_template_enhancement_output_buffer
			// automatically opts into the template-enhancement buffer (priority 1000 by default),
			// which disables response streaming. TTFB increases while TTLB unchanged — intentional
			// when Server-Timing is enabled; keep disabled by default and emit only on cache-miss
			// generation passes (advanced-cache.php serves cached pages without booting WordPress).
			// @since 2.0.0.
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) && $main->server_timing_enabled() ) {
				add_action( 'template_redirect', array( $main, 'capture_template_start' ), 0 );
				add_action( 'wp_finalized_template_enhancement_output_buffer', array( $main, 'emit_server_timing_header' ), 0, 1 );
			}

			// Per-page aggressive kill-switch purge when page cache is off but
			// used-CSS sidecars still exist (issue #1098): register the same
			// single-URL purge outside the enableCache branch so
			// `_wppo_used_css_disabled` / `_wppo_defer_disabled` toggles purge
			// the URL even without the static HTML cache.
			if ( empty( $options['cache_settings']['enableCache'] ) ) {
				add_action( 'added_post_meta', array( $main, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'updated_post_meta', array( $main, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
				add_action( 'deleted_post_meta', array( $main, 'on_aggressive_kill_switch_meta_changed' ), 10, 3 );
			}

			// Standalone used-CSS output buffer when page cache is disabled.
			if ( empty( $options['cache_settings']['enableCache'] ) && ! empty( $options['file_optimisation']['removeUnusedCSS'] ) && $safe_mode_off ) {
				if ( $use_core_buffer ) {
					// WP 6.9+ core template-enhancement buffer (issue #1386):
					// single-buffer routing, no private capture.
					add_filter( 'wp_template_enhancement_output_buffer', array( $main, 'process_used_css_only' ), 20, 2 );
				} else {
					// Legacy fallback buffer (pre-6.9 only, issue #881).
					add_action( 'template_redirect', array( $main, 'start_used_css_buffer' ) );
				}
			}

			// Optional LCP image prioritization on the finalized HTML (default off).
			// The CSS background hero preload (issue #935) and the OD
			// occlusion-aware fetchpriority=low demotion (issue #1426) share
			// this buffer, so the hook is registered when any toggle is enabled.
			if ( ! empty( $options['image_optimisation']['prioritizeLCPImages'] ) || ! empty( $options['image_optimisation']['cssHeroPreload'] ) || ! empty( $options['image_optimisation']['occlusionFetchpriorityLow'] ) ) {
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
					add_action( 'template_redirect', array( $main, 'start_lcp_priority_buffer' ), 20 );
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
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Runtime_State', 'on_switch_blog' ), 10, 2 );
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Image_Optimisation', 'clear_runtime_caches' ) );
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Main', 'reset_font_preload_emitted' ) );
			add_action( 'switch_blog', array( 'PerformanceOptimise\Inc\Main', 'reset_image_lcp_memos' ), 10, 2 );
			add_action( 'wppo_after_cache_clear', array( 'PerformanceOptimise\Inc\Image_Optimisation', 'clear_runtime_caches' ) );
			$combine_for_registration = ! empty( $options['file_optimisation']['combineCSS'] ) && $safe_mode_off;
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
				$cache = $main->ensure_hook_cache_for_registry();
				add_action( 'wp_enqueue_scripts', array( $cache, 'combine_css' ), PHP_INT_MAX );
				// Post-purge last-good fallback (issue #1275) when the page
				// cache itself is off but combined CSS is on: same self-gated
				// miss handler as the enableCache branch above (guarded so
				// both branches never double-register).
				if ( ! has_action( 'template_redirect', array( $cache, 'maybe_serve_purge_fallback' ) ) ) {
					add_action( 'template_redirect', array( $cache, 'maybe_serve_purge_fallback' ), 1 );
				}
				// Emit the combined-CSS preload after combine_css() has populated it,
				// before core prints the stylesheet <link> at wp_head priority 8.
				add_action( 'wp_head', array( $cache, 'maybe_preload_combine_css' ), 1 );
			}

			$rest = new Rest();
			add_action( 'rest_api_init', array( $rest, 'register_routes' ) );

			// Real-user Web Vitals: enqueue the beacon + bake its config into output
			// (captured by the page cache so cached pages keep beaconing without WP).
			add_action( 'wp_enqueue_scripts', array( 'PerformanceOptimise\Inc\RUM', 'maybe_enqueue_scripts' ), 5 );
			add_action( 'wp_footer', array( 'PerformanceOptimise\Inc\RUM', 'print_config' ), 90 );

			// One shared listener fans out to the legacy CDN and edge adapters.
			// The coordinator scopes Cloudflare transport de-duplication to this event.
			add_action( 'wppo_after_cache_clear', array( 'PerformanceOptimise\Inc\Edge_Purge_Coordinator', 'purge_after_cache_clear' ), 10, 2 );

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

			if ( ! empty( $options['file_optimisation']['minifyJS'] ) ) {
				$main->prepare_minify_excludes_for_registry( 'js' );

				add_filter( 'script_loader_tag', array( $main, 'minify_js' ), 10, 3 );
			}

			if ( ! empty( $options['file_optimisation']['minifyCSS'] ) ) {
				$main->prepare_minify_excludes_for_registry( 'css' );

				if ( function_exists( 'wp_maybe_inline_styles' ) ) {
					// The inline-styles mechanism (`wp_maybe_inline_styles()` /
					// `styles_inline_size_limit`) exists since WP 5.8; only the default
					// budget changed in 6.9. Rewrite styles at enqueue time and register
					// 'path' data so core can inline minified files within the budget.
					add_action( 'wp_enqueue_scripts', array( $main, 'minify_queued_styles' ), PHP_INT_MAX - 1 );
				}

				add_filter( 'style_loader_tag', array( $main, 'minify_css' ), 10, 3 );
			}

			// Removed (#925): the legacy file_optimisation.removeQueryStrings path
			// (strip_static_query_strings() on script/style_loader_src) is gone.
			if ( ! empty( $options['file_optimisation']['hostGoogleFontsLocally'] ) ) {
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
			if ( $main->is_hidden_block_asset_omission_enabled() ) {
				add_action( 'wp_enqueue_scripts', array( $main, 'omit_hidden_block_assets' ), PHP_INT_MAX - 2 );
			}

			// Defer/delay exclusion + strategy state lives on Main (feature
			// state, not registration): prepared here at the exact position
			// the code ran before, so filter firing order is unchanged.
			$main->prepare_defer_delay_state_for_registry( $staged_for_registration );

			$this->register_head_hint_hooks();
			$main->register_collaborators();

			// Critical CSS hooks. Safe mode (issue #1098) disables stylesheet
			// deferral in one click while preserving the criticalCSS setting.
			if ( ! empty( $options['file_optimisation']['criticalCSS'] ) && $safe_mode_off ) {
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
		 * Relocated from {@see Main::register_head_hint_hooks()} without
		 * behavior change so hook-group changes stay local to one method.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated from Main to Hook_Registry (REF-005).
		 * @return void
		 */
		public function register_head_hint_hooks(): void {
			add_action( 'wp_head', array( $this->main, 'add_preload_prefetch_preconnect' ), 1 );
			add_action( 'wp_head', array( $this->main, 'add_speculation_rules' ), 0 );
			add_filter( 'wp_resource_hints', array( $this->main, 'add_resource_hints' ), 10, 2 );
		}

		/**
		 * Register background-job hooks (Action Scheduler + save_post queue).
		 *
		 * Relocated from {@see Main::register_background_hooks()} without
		 * behavior change.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated from Main to Hook_Registry (REF-005).
		 * @return void
		 */
		public function register_background_hooks(): void {
			// Register Action Scheduler callback for background image processing.
			add_action( 'wppo_convert_image_background', array( $this->main, 'process_background_image' ), 10, 1 );

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
			add_action( 'wppo_ai_css_refresh_queued', array( $this->main, 'on_ai_css_refresh_queued' ), 10, 3 );

			// Register out-of-band Google Fonts download (keeps the frontend output-buffer hot path non-blocking).
			add_action( 'wppo_google_fonts_download', array( 'PerformanceOptimise\Inc\Google_Fonts', 'handle_queued_download_action' ), 10, 1 );

			// Queue used-CSS regeneration when post content changes.
			add_action( 'save_post', array( $this->main, 'on_save_post_queue_used_css' ), 10, 3 );
		}

		/**
		 * Register cache-invalidation hooks (structural changes).
		 *
		 * Relocated from {@see Main::register_invalidation_hooks()} without
		 * behavior change. `Main::class` is spelled explicitly (instead of
		 * `__CLASS__`) so the callbacks keep resolving to Main after the move.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated from Main to Hook_Registry (REF-005).
		 * @return void
		 */
		public function register_invalidation_hooks(): void {
			// Clear all cache on structural changes that invalidate every cached page.
			add_action( 'update_option_permalink_structure', array( Main::class, 'clear_all_cache' ) );
			add_action( 'switch_theme', array( Main::class, 'clear_all_cache' ) );
			// Couple used-CSS regen to theme switches with cooldown plus a
			// bounded targeted requeue (issue #1220); fail-open inside.
			add_action( 'switch_theme', array( Main::class, 'on_theme_switch_used_css' ), 20, 3 );
			add_action( 'update_option_wppo_settings', array( Main::class, 'on_settings_update' ), 10, 2 );
			// First-time seeds use add_option() (no update hook fires): drop the
			// Main options memo too so the seed is visible this request.
			add_action( 'add_option_wppo_settings', array( Main::class, 'on_settings_add' ), 10, 2 );
			// The canonical host is baked into advanced-cache.php at create()
			// time; re-bake it when the home/site URL changes (domain migration)
			// so the drop-in does not silently run uncached on a stale host.
			add_action( 'update_option_home', array( Main::class, 'on_site_url_change' ), 10, 3 );
			add_action( 'update_option_siteurl', array( Main::class, 'on_site_url_change' ), 10, 3 );
			add_action( 'activated_plugin', array( Main::class, 'clear_all_cache' ) );
			add_action( 'deactivated_plugin', array( Main::class, 'clear_all_cache' ) );
			// Bounded-cache stability (issue #1162): any plugin/theme update
			// auto-purges minify output + page cache so layout never goes
			// stale. Fail-open via on_extension_update(); builder-specific
			// purges stay in Builder_Purge_Watcher.
			add_action( 'upgrader_process_complete', array( Main::class, 'on_extension_update' ), 20, 2 );
		}

		/**
		 * Register third-party integration hooks (LiteSpeed sync, ESI bridge).
		 *
		 * Relocated from {@see Main::register_integration_hooks()} without
		 * behavior change.
		 *
		 * @since 2.2.0
		 * @since 2.4.0 Relocated from Main to Hook_Registry (REF-005).
		 * @return void
		 */
		public function register_integration_hooks(): void {
			// LS-202: LiteSpeed → WPPO purge sync (litespeed_purged_all/post/purge_finalize).
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'init' ) ) {
				LiteSpeed_Integration::init();
			}
			// P5 ESI bridge (Enterprise only — OLS has no ESI). Gated on the
			// LiteSpeed stack check with a no-autoload class probe so a
			// non-LiteSpeed frontend never lazy-loads ESI via the autoloader
			// (issue #1443). LiteSpeed_Integration::init() above stays
			// unconditional: it is always loaded and self-gates internally.
			if ( Main::should_load_litespeed_stack() && class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', false ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', 'init' ) ) {
				LiteSpeed_ESI::init();
			}
		}

		/**
		 * Register filters that control when core block assets load.
		 *
		 * Relocated from Main without behavior change. On WP 6.9+ classic
		 * themes load separate core block assets on demand by default
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
		 * @since 2.4.0 Relocated from Main to Hook_Registry (REF-005).
		 * @param bool $loads_separate_core_block_assets_on_demand Whether WP 6.9+ is active
		 *                                                        (core loads separate core
		 *                                                        block assets on demand).
		 * @return void
		 */
		public function register_block_assets_filters( bool $loads_separate_core_block_assets_on_demand ): void {
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
	}
}
