<?php
/**
 * Script loading strategy — defer rendering + delay-JS decisions and data.
 *
 * ARCH-005: the defer-strategy rendering cluster (`add_defer_strategy()`,
 * `add_defer_attribute[_legacy]()`, fetchpriority/footer helpers, eligibility,
 * `remove_woocommerce_scripts()`) and the delay-JS decision/data cluster
 * (pattern matching, exclusions, delay-context verdict + memo, per-page
 * config, strategies/priorities, third-party candidates, and the curated
 * `get_delay_js_*` preset/exclusion providers) previously lived on the `Main`
 * orchestrator. They share one responsibility (script loading strategy), one
 * trigger set (the `script_loader_tag` / `wp_enqueue_scripts` / `wp` hooks
 * registered on `Main` callbacks by `Hook_Registry`), and one memo state —
 * so they live here.
 *
 * `Main` keeps thin proxies (facade rule) delegating to a lazy instance of
 * this class (instance methods) or directly (static methods), so
 * `Hook_Registry` hook registrations and the `Minify\HTML` / `Used_CSS` /
 * `Cache` callers stay byte-identical with zero caller migration.
 * Collaboration: settings read through the same path the bodies always used
 * (`Main::get_options()` via the owning `Main` instance — no new write
 * paths); hooks and option-state preparation stay on `Main`.
 *
 * Multisite correction (ARCH-005): `delay_context_request_signature()` now
 * keys on the blog id in addition to URI + query + GET keys. The previous
 * keying had no blog component while sibling auto-pattern memos are
 * blog-keyed, so a `switch_to_blog()` re-entry within one request could reuse
 * another site's verdict; the memo now self-invalidates across switched-blog
 * re-entry (signature mismatch forces recompute). No `switch_blog` hook
 * registration was needed (`Hook_Registry` untouched by design) because the
 * signature mismatch already forces a recompute. Option reads stay per-site.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Script_Strategy' ) ) {
	/**
	 * Class Script_Strategy
	 *
	 * Owns script defer rendering + delay-JS decisions/data + memo.
	 * Constructed with the `Main` instance so settings reads and hook-state
	 * keep the exact semantics the bodies had on `Main` (instance state stays
	 * on `Main` as the single source of truth, accessed through the
	 * `@internal` `script_state_*()` bridges — never a new write path).
	 *
	 * @since 2.4.0
	 */
	final class Script_Strategy {

		/**
		 * Main instance owning hook-state and settings (single source of truth).
		 *
		 * Held by reference target (not a copy) so state reads/writes land on
		 * the live request state exactly as `$this->prop` accesses did before
		 * the extraction.
		 *
		 * @since 2.4.0
		 * @var   Main
		 */
		private Main $main;

		/**
		 * Constructor.
		 *
		 * @since 2.4.0
		 * @param Main $main Main instance (hook-state + settings owner).
		 */
		public function __construct( Main $main ) {
			$this->main = $main;
		}

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
		 * Dequeues configured WooCommerce CSS and JS handles unless the current URL is excluded.
		 *
		 * Reads `file_optimisation.excludeUrlToKeepJSCSS` and, if the current front-end URL matches any entry
		 * (exact match or prefix match when an entry contains the `(.*)` suffix), preserves scripts/styles.
		 * Otherwise reads `file_optimisation.removeCssJsHandle` and dequeues each entry prefixed with
		 * `style:` (dequeues a style handle) or `script:` (dequeues a script handle).
		 *
		 * @since 1.0.0
		 * Relocated from Main::remove_woocommerce_scripts() (ARCH-005).
		 */
		public function remove_woocommerce_scripts() {
			if ( empty( $this->main->get_options()['file_optimisation']['removeCssJsHandle'] ) ) {
				return;
			}

			$exclude_url_to_keep_js_css = array();
			if ( ! empty( $this->main->get_options()['file_optimisation']['excludeUrlToKeepJSCSS'] ) ) {
				$exclude_url_to_keep_js_css = Util::process_urls( $this->main->get_options()['file_optimisation']['excludeUrlToKeepJSCSS'] );
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

			$remove_css_js_handle = Util::process_urls( $this->main->get_options()['file_optimisation']['removeCssJsHandle'] );

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
		 * Relocated from Main::supports_native_defer_strategy() (ARCH-005).
		 */
		public static function supports_native_defer_strategy(): bool {
			// Version floor lives in Wp_Version (REF-010); the API probes
			// below stay byte-identical.
			if ( ! Wp_Version::is_at_least( '6.3-alpha' ) ) {
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
		 * Relocated from Main::supports_native_script_fetchpriority() (ARCH-005).
		 */
		public static function supports_native_script_fetchpriority(): bool {
			// Version floor lives in Wp_Version (REF-010); the API probes
			// below stay byte-identical.
			if ( ! Wp_Version::is_at_least( '6.9-alpha' ) ) {
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
		 * Relocated from Main::is_defer_eligible_for_handle() (ARCH-005).
		 */
		public function is_defer_eligible_for_handle( object $wp_scripts, string $handle, array $intended = array(), array &$checked = array() ): bool {
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
		 * Relocated to Preload_Buffer_Coordinator in P3-015; retained as a
		 * compatibility proxy for existing Script_Strategy callers.
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
		 * Relocated from Main::get_filtered_deferred_fetchpriority() (ARCH-005).
		 */
		public function get_filtered_deferred_fetchpriority( string $handle ): string {
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
		 * Relocated from Main::should_move_deferred_to_footer() (ARCH-005).
		 */
		public function should_move_deferred_to_footer( string $handle ): bool {
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
		 * Relocated from Main::add_defer_strategy() (ARCH-005).
		 */
		public function add_defer_strategy(): void {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return;
			}
			if ( ! $this->main->script_should_optimise_for_logged_in() && ! Main::is_sandbox_preview_active() ) {
				return;
			}
			// Safe-mode kill switch + nocache bypass (issue #1098): fail open
			// to original scripts, settings preserved. Sandbox preview
			// (issue #1163) bypasses safe mode for preview admins only.
			if ( Main::is_aggressive_bypass_active() ) {
				return;
			}
			if ( ! Main::is_sandbox_preview_active() && Main::is_safe_mode_active( $this->main->get_options()['file_optimisation'] ?? array() ) ) {
				return;
			}
			// Per-page defer kill-switch (#1098).
			if ( $this->main->script_state_defer_disabled_for_page() || Main::is_defer_disabled_for_page() ) {
				return;
			}

			$file_opt_for_defer = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
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
				if ( in_array( $queued_handle, $this->main->script_state_exclude_defer_js(), true ) ) {
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
				if ( in_array( $handle, $this->main->script_state_exclude_defer_js(), true ) ) {
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
				$this->main->script_state_deferred_handles()[ $handle ] = true;
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
		 * Relocated from Main::is_executable_script_type() (ARCH-005).
		 */
		public function is_executable_script_type( string $tag ): bool {
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
		 * Relocated from Main::add_defer_attribute() (ARCH-005).
		 */
		public function add_defer_attribute( $tag, $handle ): string {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->main->script_should_optimise_for_logged_in() && ! Main::is_sandbox_preview_active() ) {
				return $tag;
			}
			// Safe-mode kill switch + nocache bypass (issue #1098): fail open
			// to original scripts, settings preserved for one-click recovery.
			// Sandbox preview (issue #1163): preview admins bypass safe mode
			// so staged delay/defer renders; visitors still gate on safe mode.
			// Aggressive bypass (?nocache) still applies in preview.
			$file_opt_for_gate = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
			if ( Main::is_aggressive_bypass_active() ) {
				return $tag;
			}
			if ( ! Main::is_sandbox_preview_active() && Main::is_safe_mode_active( $this->main->get_options()['file_optimisation'] ?? array() ) ) {
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
				if ( $this->main->script_state_delay_disabled_for_page() || Main::is_delay_disabled_for_page() ) {
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
						$is_native_deferred = self::supports_native_script_fetchpriority() && isset( $this->main->script_state_deferred_handles()[ $handle ] );
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
		 * Relocated from Main::add_defer_attribute_legacy() (ARCH-005).
		 */
		public function add_defer_attribute_legacy( $tag, $handle ): string {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->main->script_should_optimise_for_logged_in() && ! Main::is_sandbox_preview_active() ) {
				return $tag;
			}
			// Safe-mode kill switch + nocache bypass + per-page defer disable
			// (issue #1098): fail open to original tag. Sandbox preview
			// (issue #1163) bypasses safe mode for preview admins only.
			if ( Main::is_aggressive_bypass_active() ) {
				return $tag;
			}
			if ( ! Main::is_sandbox_preview_active() && Main::is_safe_mode_active( $this->main->get_options()['file_optimisation'] ?? array() ) ) {
				return $tag;
			}
			if ( $this->main->script_state_defer_disabled_for_page() || Main::is_defer_disabled_for_page() ) {
				return $tag;
			}
			// Sandbox preview (issue #1163): the legacy path is registered on the
			// production flag, so gate on the effective (production + staged)
			// deferJS flag like the native path does — a staged deferJS=off must
			// disable defer in preview instead of deferring every tag.
			$file_opt_for_legacy = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
			if ( empty( $file_opt_for_legacy['deferJS'] ) ) {
				return $tag;
			}

			if ( in_array( $handle, $this->main->script_state_exclude_defer_js(), true ) ) {
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

			$this->main->script_state_deferred_handles()[ $handle ] = true;

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
		 * Relocated from Main::matches_delay_pattern() (ARCH-005).
		 */
		public function matches_delay_pattern( string $handle, string $pattern ): bool {
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
		 * Relocated from Main::get_delay_patterns_regex() (ARCH-005).
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
		 * Relocated from Main::matches_any_delay_pattern() (ARCH-005).
		 */
		public function matches_any_delay_pattern( string $handle, array $patterns ): bool {
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
		 * Relocated from Main::is_delay_excluded_handle() (ARCH-005).
		 */
		public function is_delay_excluded_handle( string $handle ): bool {
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
		 * Relocated from Main::get_delay_exclusions() (ARCH-005).
		 */
		public function get_delay_exclusions(): array {
			if ( null !== $this->main->script_state_resolved_delay_exclusions() ) {
				return $this->main->script_state_resolved_delay_exclusions();
			}

			$exclusions = $this->main->script_state_exclude_delay_js();

			if ( has_filter( 'wppo_exclude_delay_js' ) ) {
				try {
					$exclusions = (array) apply_filters( 'wppo_exclude_delay_js', $exclusions );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Filter-then-subtract (#1308): the per-page preset opt-out wins
			// over filter re-adds, mirroring the Minify\HTML constructor.
			if ( ! empty( $this->main->script_state_page_preset_opt_out_remove() ) ) {
				try {
					$exclusions = array_values( array_diff( $exclusions, $this->main->script_state_page_preset_opt_out_remove() ) );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$resolved_delay_exclusions_ref =& $this->main->script_state_resolved_delay_exclusions();
			$resolved_delay_exclusions_ref = array_values(
				array_unique(
					array_filter(
						$exclusions,
						static function ( $val ): bool {
							return is_string( $val ) && '' !== $val;
						}
					)
				)
			);

			return $this->main->script_state_resolved_delay_exclusions();
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
		 * Relocated from Main::is_delay_excluded_context() (ARCH-005).
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
		 * Multisite correction (ARCH-005): the blog id leads the signature.
		 * The pre-extraction keying (URI + query + GET keys) had no blog
		 * component while sibling auto-pattern memos are blog-keyed, so a
		 * `switch_to_blog()` re-entry within one request could reuse another
		 * site's verdict for the same URI. Keying the signature with the blog
		 * id makes the memo self-invalidate across switched-blog re-entry
		 * (signature mismatch forces recompute), so no `switch_blog` reset
		 * hook is needed.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Blog-keyed signature (ARCH-005 multisite correction).
		 * @return string Signature string.
		 * Relocated from Main::delay_context_request_signature() (ARCH-005).
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
			$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			return $blog_id . "\n" . $uri . "\n" . $qs . "\n" . implode( ',', $get );
		}

		/**
		 * Reset the per-request delay-context memo (for tests).
		 *
		 * @since 2.0.0
		 * @return void
		 * Relocated from Main::reset_delay_context_memo() (ARCH-005).
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
		 * Relocated from Main::compute_delay_excluded_context() (ARCH-005).
		 */
		private static function compute_delay_excluded_context(): bool {
			try {
				// Store API routes are dynamic JSON: never delay (issue #962).
				// Unconditional on wooSafeMode, mirroring wc-ajax — checked
				// first so safe-mode-off cannot re-allow delaying Store API.
				// Covers plain permalinks via ?rest_route=/wc/store/... too.
				if ( class_exists( 'PerformanceOptimise\Inc\Woo_Detect' ) && method_exists( 'PerformanceOptimise\Inc\Woo_Detect', 'is_woo_store_api_request' ) ) {
					try {
						$uri_for_store = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
						$store_path    = '/' . trim( rawurldecode( (string) wp_parse_url( $uri_for_store, PHP_URL_PATH ) ), '/' );
						if ( Woo_Detect::is_woo_store_api_request( $store_path ) ) {
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
				// (unified with Cache/Cron via Woo_Detect::is_woo_safe_mode_enabled();
				// absent = on, malformed = on). Store API above stays unconditional.
				$woo_safe = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Woo_Detect' ) && method_exists( 'PerformanceOptimise\Inc\Woo_Detect', 'is_woo_safe_mode_enabled' ) ) {
					try {
						$woo_safe = Woo_Detect::is_woo_safe_mode_enabled();
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
					if ( '' !== trim( $delay_exclude_list ) && Main::is_url_excluded_by_list( $delay_exclude_list ) ) {
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
		 * Relocated from Main::matches_woo_page_path() (ARCH-005).
		 */
		private static function matches_woo_page_path( string $local_path ): bool {
			// Canonical path list (issue #962): Woo_Detect::get_woo_excluded_paths()
			// merged with the cart/checkout/my-account defaults so custom /
			// translated / nested slugs stay excluded. Anywhere-segment fail-safe
			// semantics cover subdirectory installs and multisite sub-sites.
			$slugs = array( 'cart', 'checkout', 'my-account' );

			if ( class_exists( 'PerformanceOptimise\Inc\Woo_Detect' ) && method_exists( 'PerformanceOptimise\Inc\Woo_Detect', 'get_woo_excluded_paths' ) ) {
				try {
					foreach ( Woo_Detect::get_woo_excluded_paths() as $woo_path ) {
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
		 * Relocated from Main::get_delay_strategy_for_handle() (ARCH-005).
		 */
		public function get_delay_strategy_for_handle( string $handle, string $tag = '', ?bool $is_auto_matched = null ): string {
			if ( in_array( $handle, $this->main->script_state_delay_js_idle_list(), true ) ) {
				return 'idle';
			}
			if ( in_array( $handle, $this->main->script_state_delay_js_viewport_list(), true ) ) {
				return 'viewport';
			}
			// Also check via URL pattern matching against the handle text (handles often contain the handle name).
			// Precompiled alternation: one regex per list per request instead of per-pattern compiles.
			if ( $this->matches_any_delay_pattern( $handle, $this->main->script_state_delay_js_idle_list() ) ) {
				return 'idle';
			}
			if ( $this->matches_any_delay_pattern( $handle, $this->main->script_state_delay_js_viewport_list() ) ) {
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
			if ( in_array( $handle, $this->main->script_state_delay_js_per_page_interaction(), true ) ) {
				return 'interaction';
			}
			if ( 'interaction' === $this->main->script_state_delay_js_default_strategy() ) {
				// Sandbox preview parity: read the staged (effective) slice,
				// not raw options, so preview with staged auto=true resolves
				// idle exactly like promoted production.
				$file_opt = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
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
			return $this->main->script_state_delay_js_default_strategy();
		}

		/**
		 * Get the delay priority for a given script handle.
		 *
		 * @since 1.9.0
		 *
		 * @param string $handle The script handle.
		 * @return string The priority: 'high', 'normal', or 'low'.
		 * Relocated from Main::get_delay_priority_for_handle() (ARCH-005).
		 */
		public function get_delay_priority_for_handle( string $handle ): string {
			if ( isset( $this->main->script_state_delay_js_priority()[ $handle ] ) ) {
				return $this->main->script_state_delay_js_priority()[ $handle ];
			}
			// Check partial matches via one precompiled alternation, then
			// resolve the winning pattern for the level.
			$patterns = array_keys( $this->main->script_state_delay_js_priority() );
			if ( ! empty( $patterns ) && $this->matches_any_delay_pattern( $handle, $patterns ) ) {
				foreach ( $this->main->script_state_delay_js_priority() as $pattern => $level ) {
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
		 * Relocated from Main::apply_per_page_delay_config() (ARCH-005).
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
			if ( Main::is_delay_disabled_for_page( (int) $post_id ) ) {
				$delay_disabled_for_page_ref =& $this->main->script_state_delay_disabled_for_page();
				$delay_disabled_for_page_ref = true;
				return;
			}

			// Per-page defer kill-switch (#1098): skip all defer rewriting for
			// this request. Post meta survives cache clears; the single-URL
			// purge in invalidate_aggressive_kill_switch_cache() refreshes HTML.
			if ( Main::is_defer_disabled_for_page( (int) $post_id ) ) {
				$defer_disabled_for_page_ref =& $this->main->script_state_defer_disabled_for_page();
				$defer_disabled_for_page_ref = true;
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
			$file_opt = $this->main->get_options()['file_optimisation'] ?? array();
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
			$presets_off = $any_on ? Main::get_page_disabled_delay_presets( (int) $post_id ) : array();
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
							$exclude_delay_js_ref          =& $this->main->script_state_exclude_delay_js();
							$exclude_delay_js_ref          = array_values( array_diff( $this->main->script_state_exclude_delay_js(), $remove ) );
							$resolved_delay_exclusions_ref =& $this->main->script_state_resolved_delay_exclusions();
							$resolved_delay_exclusions_ref = null;
							// Re-applied after the filter in get_delay_exclusions()
							// (filter-then-subtract) so late filter registrations
							// cannot silently nullify the page opt-out.
							$page_preset_opt_out_remove_ref =& $this->main->script_state_page_preset_opt_out_remove();
							$page_preset_opt_out_remove_ref = array_values( $remove );
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
							$delay_js_idle_list_ref     =& $this->main->script_state_delay_js_idle_list();
							$delay_js_idle_list_ref     = array_diff( $this->main->script_state_delay_js_idle_list(), array( $handle ) );
							$delay_js_viewport_list_ref =& $this->main->script_state_delay_js_viewport_list();
							$delay_js_viewport_list_ref = array_diff( $this->main->script_state_delay_js_viewport_list(), array( $handle ) );
							if ( ! in_array( $handle, $this->main->script_state_delay_js_per_page_interaction(), true ) ) {
								$this->main->script_state_delay_js_per_page_interaction()[] = $handle;
							}
						} elseif ( 'idle' === $strategy ) {
							if ( ! in_array( $handle, $this->main->script_state_delay_js_idle_list(), true ) ) {
								$this->main->script_state_delay_js_idle_list()[] = $handle;
							}
							$delay_js_viewport_list_ref =& $this->main->script_state_delay_js_viewport_list();
							$delay_js_viewport_list_ref = array_diff( $this->main->script_state_delay_js_viewport_list(), array( $handle ) );
						} elseif ( 'viewport' === $strategy ) {
							if ( ! in_array( $handle, $this->main->script_state_delay_js_viewport_list(), true ) ) {
								$this->main->script_state_delay_js_viewport_list()[] = $handle;
							}
							$delay_js_idle_list_ref =& $this->main->script_state_delay_js_idle_list();
							$delay_js_idle_list_ref = array_diff( $this->main->script_state_delay_js_idle_list(), array( $handle ) );
						}
					}
				}
			}

			if ( is_array( $delay_priorities ) ) {
				foreach ( $delay_priorities as $handle => $priority ) {
					if ( in_array( $priority, array( 'high', 'normal', 'low' ), true ) ) {
						$this->main->script_state_delay_js_priority()[ $handle ] = $priority;
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
		 * Relocated from Main::get_delay_js_builder_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_commerce_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_slider_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_interaction_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_preset_levels() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_preset_level_settings() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_preset_level_exclusions() (ARCH-005).
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
		 * Relocated from Main::filter_compat_preset_list() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_consent_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_analytics_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_gallery_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_jquery_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_compat_preset_map() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_compat_preset_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_third_party_denylist() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_third_party_allowlist_for_slice() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_third_party_allowlist() (ARCH-005).
		 */
		public function get_delay_js_third_party_allowlist(): array {
			try {
				// Sandbox preview (#1217 review): read staged lists from the
				// effective slice so preview renders staged edits instead of
				// production values on the script_loader_tag path.
				$file_opt = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
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
		 * Relocated from Main::is_delay_third_party_candidate() (ARCH-005).
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
				$file_opt = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
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
		 * Relocated from Main::is_same_site_script_host() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_third_party_auto_categories() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_third_party_auto_label() (ARCH-005).
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
		 * Relocated from Main::reset_delay_third_party_auto_cache() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_third_party_auto_patterns() (ARCH-005).
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
		 * Relocated from Main::matches_third_party_auto_pattern() (ARCH-005).
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
		 * Relocated from Main::is_delay_third_party_auto_candidate() (ARCH-005).
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
				$file_opt_for_allow = Main::get_effective_file_optimisation( $this->main->get_options()['file_optimisation'] ?? array() );
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
		 * Relocated from Main::inject_delay_script_attr() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_base_preset_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_protected_exclusions() (ARCH-005).
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
		 * Relocated from Main::get_delay_js_preset_exclusions() (ARCH-005).
		 */
		public function get_delay_js_preset_exclusions(): array {
			$preset = self::get_delay_js_base_preset_exclusions();
			// Commerce safe preset (#988): safe-by-default on; merges jQuery +
			// cart-fragments/checkout handles unless explicitly disabled.
			// Missing key backfills to on (per-site settings, multisite-safe).
			$commerce_on = ! isset( $this->main->get_options()['file_optimisation']['delayJSCommercePreset'] )
			|| ! empty( $this->main->get_options()['file_optimisation']['delayJSCommercePreset'] );
			if ( $commerce_on ) {
				$preset = array_merge( $preset, self::get_delay_js_commerce_exclusions() );
			}
			// Builder safe preset (#966): safe-by-default on; merges builder
			// runtime handles plus slider runtimes (#988) unless explicitly disabled.
			// Missing key backfills to on (per-site settings, multisite-safe).
			$builder_on = ! isset( $this->main->get_options()['file_optimisation']['delayJSBuilderPreset'] )
			|| ! empty( $this->main->get_options()['file_optimisation']['delayJSBuilderPreset'] );
			if ( $builder_on ) {
				$preset = array_merge( $preset, self::get_delay_js_builder_exclusions(), self::get_delay_js_slider_exclusions() );
			}
			// Interaction safe preset (#1055): first-click popup/dialog,
			// mobile-menu, and add-to-cart handles. Safe-by-default on;
			// missing key backfills to on (per-site settings, multisite-safe).
			$interaction_on = ! isset( $this->main->get_options()['file_optimisation']['delayJSInteractionPreset'] )
			|| ! empty( $this->main->get_options()['file_optimisation']['delayJSInteractionPreset'] );
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
					if ( ! empty( $this->main->get_options()['file_optimisation'][ $setting_key ] ) ) {
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
		 * Relocated from Main::is_delay_js_safe_context() (ARCH-005).
		 */
		public function is_delay_js_safe_context(): bool {
			try {
				// Explicit opt-out (delayJSSafeMode=false) skips all checks and
				// returns false (delay allowed) — safe mode off means no
				// protection is applied. An unset key preserves the legacy
				// behavior of running the checks.
				$safe_mode = $this->main->get_options()['file_optimisation']['delayJSSafeMode'] ?? null;
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
		 * Relocated from Main::add_fetchpriority_to_deferred() (ARCH-005).
		 */
		public function add_fetchpriority_to_deferred( $tag, $handle ): string {
			if ( ! isset( $this->main->script_state_deferred_handles()[ $handle ] ) ) {
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
	}
}
