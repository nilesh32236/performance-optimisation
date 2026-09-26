<?php
/**
 * CSS combine service — fetch, minify, write, journal, preload, inline-budget, fallback.
 *
 * ARCH-006: the CSS-combine cluster (`combine_css()`, the fetch/minify/write
 * pipeline, the combined-handles journal, the preload-hint emission, the
 * core inline-budget negotiation, and the safe-mode fallback) previously
 * lived on the `Cache` page-cache orchestrator. The pipeline shares one
 * responsibility (the combined stylesheet), one trigger set (the
 * `wp_enqueue_scripts` / `wp_head` hooks registered on `Cache` callbacks by
 * `Hook_Registry`), and one memo state — so it lives here.
 *
 * `Cache` keeps thin proxies (facade rule) delegating to a lazy instance of
 * this class, so `Hook_Registry` hook registrations (`combine_css` at
 * `PHP_INT_MAX`, `maybe_preload_combine_css` at `wp_head`/1) and every
 * reflection-based caller stay byte-identical with zero caller migration.
 * Service methods are public (widened from private, ARCH-005 precedent) so
 * the private `Cache` proxies can delegate; `Cache` keeps the original
 * visibility contract.
 * Collaboration: settings read through the same path the bodies always used
 * (`Cache::$options` via the owning `Cache` instance — no new write paths);
 * buffer/storage policy (`is_not_cacheable()`, file paths, directory prep,
 * LiteSpeed bypass, block-asset classification) stays on `Cache` and is
 * reached through the `@internal` `combine_*()` bridges.
 *
 * State strategy (Option A — ARCH-004/005 bridge precedent): ALL instance
 * state stays on `Cache` as the single source of truth; this service holds
 * the `Cache` instance by reference target (not a copy) so memo reads/writes
 * land on the live request state exactly as `$this->prop` accesses did
 * before the extraction. No memo semantic change:
 * - `combine_css_preload_url`, `inline_size_map`, `core_will_inline_memo`,
 *   `src_stat_cache` (LRU, 500-entry cap), `inline_drift_detected` keep
 *   per-request lifetimes on the owning `Cache` instance (one instance per
 *   request via `Main::ensure_hook_cache_for_registry()`), reset exactly
 *   where the original bodies reset them (`register_combine_css_path()`
 *   still clears the size map + will-inline memo when `path` data lands).
 * - `sandbox_effective_file_opt_memo` / `sandbox_preview_memo` /
 *   `safe_mode_inline_memo` keep per-request lifetimes keyed by the
 *   production slice hash, so staged preview output and production output
 *   can never share a verdict within one request.
 * - Multisite/blog-keying is unchanged: combined files live under the
 *   domain-keyed cache dir (`Cache::get_cache_file_path()`), the inline
 *   budget reads per-site options through the owning instance, and the
 *   drift throttle keys through `Util::transient_key()` (blog-prefixed on
 *   multisite) exactly as before.
 * - The static drift-log gate (`$inline_drift_logged`) stays a `Cache`
 *   static, reached through the `combine_inline_drift_*()` static bridges.
 *
 * Minification still uses matthiasmullie/minify (unchanged); persist paths,
 * option schema, hook names/priorities, and the `wppo_inline_combined_css`
 * / `wppo_combine_preload_fetchpriority` filter contracts are unchanged.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify\CSS;
use MatthiasMullie\Minify\CSS as CSSMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Css_Combine' ) ) {
	/**
	 * Class Css_Combine
	 *
	 * Owns the CSS fetch/minify/write/journal/preload/budget/fallback
	 * pipeline. Constructed with the `Cache` instance so settings reads,
	 * memo state, and buffer/storage policy keep the exact semantics the
	 * bodies had on `Cache` (instance state stays on `Cache` as the single
	 * source of truth, accessed through the `@internal` `combine_*()`
	 * bridges — never a new write path).
	 *
	 * @since 2.4.0
	 */
	final class Css_Combine {

		/**
		 * Cache instance owning memo state, settings, and storage/policy.
		 *
		 * Held by reference target (not a copy) so state reads/writes land on
		 * the live request state exactly as `$this->prop` accesses did before
		 * the extraction.
		 *
		 * @since 2.4.0
		 * @var   Cache
		 */
		private Cache $cache;

		/**
		 * Constructor.
		 *
		 * @since 2.4.0
		 * @param Cache $cache Cache instance (memo + settings + policy owner).
		 */
		public function __construct( Cache $cache ) {
			$this->cache = $cache;
		}

		/**
		 * Max entries for the src stat LRU.
		 *
		 * Relocated from Cache::SRC_STAT_CACHE_LIMIT (ARCH-006): the sole
		 * user is {@see get_cached_src_stat()}.
		 *
		 * @since 2.0.0
		 */
		private const SRC_STAT_CACHE_LIMIT = 500;

		/**
		 * Sandbox-effective `file_optimisation` slice (issue #1259).
		 *
		 * Single choke point for the Elementor-safe-mode slice resolution
		 * so combine_css() and will_combine_css_inline() cannot drift
		 * apart. Delegates to Main::get_effective_file_optimisation() (the
		 * canonical sandbox-preview resolution) and memos per request keyed
		 * by the production slice. Fail-open to the production slice on any
		 * failure.
		 *
		 * @since 2.2.0
		 *
		 * @param array $file_opt Production `file_optimisation` slice.
		 * @return array Effective slice (staged values merged in preview).
		 * Relocated from Cache::get_sandbox_effective_file_opt() (ARCH-006).
		 */
		public function get_sandbox_effective_file_opt( array $file_opt ): array {
			$opt_memo = &$this->cache->combine_state_sandbox_effective_file_opt_memo();
			try {
				$memo_key = md5( serialize( $file_opt ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
				if ( array_key_exists( $memo_key, $opt_memo ) ) {
					return $opt_memo[ $memo_key ];
				}
				$effective = $file_opt;
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'get_effective_file_optimisation' ) ) {
					$resolved = Main::get_effective_file_optimisation( $file_opt );
					if ( is_array( $resolved ) ) {
						$effective = $resolved;
					}
				}
				$opt_memo[ $memo_key ] = $effective;
				return $effective;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $file_opt;
		}

		/**
		 * Whether CSS combine/inline must be skipped for Elementor-safe mode (issue #1259).
		 *
		 * Single choke point for both combine_css() and
		 * will_combine_css_inline() so the pre-gate, sandbox-effective
		 * slice, and skip predicate cannot drift between call sites.
		 * Fail-closed to skip while safe mode is on: Main::elementor_safe_fallback()
		 * verdict is returned on detection failure (uncombined markup costs
		 * perf only; combining through a failure risks broken Elementor
		 * layout/FOUC).
		 *
		 * @since 2.2.0
		 *
		 * @param array     $file_opt  Sandbox-effective `file_optimisation` slice.
		 * @param bool|null $looks_like Pre-computed Main::looks_like_elementor_request()
		 *                              verdict (null to compute here). Threaded through
		 *                              so will_combine_css_inline() evaluates the
		 *                              pre-gate once instead of twice per request.
		 * @return bool True when combine/inline must be skipped.
		 * Relocated from Cache::should_bypass_combine_for_elementor() (ARCH-006).
		 */
		public function should_bypass_combine_for_elementor( array $file_opt, ?bool $looks_like = null ): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Main' ) || ! method_exists( 'PerformanceOptimise\Inc\Main', 'looks_like_elementor_request' ) || ! method_exists( 'PerformanceOptimise\Inc\Main', 'should_skip_combine_for_elementor' ) ) {
					return false;
				}
				// Centralized native pre-gate (class/constant/query var
				// only — zero WP calls) so non-Elementor sites, and unit
				// tests with strict function_exists() expectations, pay
				// nothing; the full guarded detection (memoized per request
				// in Main::is_elementor_built_page()) runs only when
				// Elementor looks present.
				if ( null === $looks_like ) {
					$looks_like = Main::looks_like_elementor_request();
				}
				if ( ! $looks_like ) {
					return false;
				}
				return Main::should_skip_combine_for_elementor( $file_opt );
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_elementor_safe_mode_active' ) ) {
						return Main::is_elementor_safe_mode_active( $file_opt );
					}
				} catch ( \Throwable $e2 ) {
					unset( $e2 );
				}
				return true;
			}
		}

		/**
		 * Combines all enqueued CSS files into a single file.
		 *
		 * @return void
		 * @since 1.0.0
		 * Relocated from Cache::combine_css() (ARCH-006).
		 */
		public function combine_css() {
			$options = $this->cache->combine_options();
			if ( $this->cache->combine_should_bypass_for_litespeed() ) {
				return;
			}
			// Note (see #624): when core removes script/style concatenation in favour
			// of core preload emission, reassess whether this concat pipeline should
			// be dropped / relegated to an opt-in legacy toggle in favour of core
			// preloads (wp_resource_hints). No runtime change until the core API lands.
			// Sandbox preview (issue #1163): the preview admin renders staged
			// combineCSS even without logged-in cache enabled. is_not_cacheable()
			// is also bypassed: the preview defines DONOTCACHEPAGE and carries a
			// query string by design, both of which force that gate. Visitors
			// keep the production gates. 404s never combine.
			$is_preview = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_sandbox_preview_active' ) ) {
					$is_preview = Main::is_sandbox_preview_active();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( ( ! $this->cache->combine_is_cache_allowed_for_current_user() && ! $is_preview ) || is_404() || ( $this->cache->combine_is_not_cacheable() && ! $is_preview ) ) {
				return;
			}

			global $wp_styles;
			$styles = ( isset( $wp_styles ) && is_object( $wp_styles ) && isset( $wp_styles->queue ) && is_array( $wp_styles->queue ) ) ? $wp_styles->queue : array();

			if ( empty( $styles ) ) {
				return;
			}

			$fs = $this->cache->combine_filesystem();
			if ( ! $fs ) {
				return;
			}

			$exclude_combine_css = array();
			// Sandbox preview (issue #1163): staged excludeCombineCSS lines
			// also exclude in preview only. Util::process_urls() is
			// array-safe (string or array payload).
			$file_opt_for_combine = isset( $options['file_optimisation'] ) && is_array( $options['file_optimisation'] ) ? $options['file_optimisation'] : array();
			if ( $is_preview ) {
				$file_opt_for_combine = $this->get_sandbox_effective_file_opt( $file_opt_for_combine );
			}
			if ( ! empty( $file_opt_for_combine['excludeCombineCSS'] ) ) {
				$exclude_combine_css = Util::process_urls( $file_opt_for_combine['excludeCombineCSS'] );
			}
			// Sandbox preview (issue #1163): a staged combineCSS=off must disable
			// combining in preview (mirror minify_queued_styles logic), otherwise
			// staged-disable can never be previewed.
			if ( $is_preview && empty( $file_opt_for_combine['combineCSS'] ) ) {
				return;
			}
			// Elementor-safe mode (issue #1259): default-off combine/inline for
			// builder-built pages. Uses the sandbox-effective slice so a staged
			// elementorSafeMode=off can be previewed before promote. Single
			// choke point (should_bypass_combine_for_elementor()) shared with
			// will_combine_css_inline() so the two call sites cannot drift.
			if ( $this->should_bypass_combine_for_elementor( is_array( $file_opt_for_combine ) ? $file_opt_for_combine : array() ) ) {
				return;
			}
			// Unified safe-mode kill switch (issue #1465): safe mode disables
			// combine in one click. Preview admins still render staged output
			// (per-tag widening above); visitors and normal requests bail out
			// here. Fail-open: predicate failure means combine proceeds.
			if ( ! $is_preview ) {
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_safe_mode_active' ) ) {
						if ( Main::is_safe_mode_active( is_array( $file_opt_for_combine ) ? $file_opt_for_combine : array() ) ) {
							return;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// On WP 6.9+ with separate (on-demand) core block assets active, never
			// fold any core block-asset stylesheet into the combined file — doing so
			// would force the wp-block-library monolith (or per-block styles for
			// blocks not even on the page) back into the head and fight core's
			// conditional loading. Belt-and-suspenders: these handles are normally
			// not in the queue on 6.9 anyway. The pipeline still minifies eligible
			// non-block handles below but lets core hoist block styles (yield where
			// core wins; combine is the fallback, not a competitor). The
			// `should_load_separate_core_block_assets` opt-out filter is honoured
			// via block_assets_are_separate(): an explicit opt-out restores the
			// legacy monolith path. The operator escape hatch
			// (`blockAssetsOnDemand` off / `loadAllCoreBlockAssets` on) is applied
			// explicitly via get_effective_separate_block_assets() so Cache never
			// depends on Main's filter side effect.
			$separate_block_assets = $this->cache->combine_get_effective_separate_block_assets();

			// The effective separate-assets state is baked into the combined-CSS
			// cache filename, so a 6.8 -> 6.9 upgrade (which flips separate block
			// assets on by default for classic themes) cannot keep serving a stale
			// combined monolith built while wp-block-library was still in the queue.
			// Sandbox preview (issue #1163) gets its own `-preview` variant so
			// staged combine output can never overwrite the production file.
			$css_variant = $separate_block_assets ? 'separate' : '';
			if ( $is_preview ) {
				$css_variant .= '' === $css_variant ? 'preview' : '-preview';
			}

			// The set of handles this request would pull into the combined file. The
			// same skip rules are applied below during generation so the two branches
			// stay consistent about which styles belong in the file.
			$eligible_handles = $this->resolve_eligible_handles( $styles, $exclude_combine_css );

			// On small block-theme bundles core's 40KB inline budget (WP 6.9+)
			// already inlines the eligible styles cheaply — skip creating the
			// combined file and let core inline instead (one fewer request).
			if ( $this->should_skip_combine_for_inline_budget( $eligible_handles ) ) {
				return;
			}

			// Reuse cached CSS only if it is still fresh (no source file is newer and
			// the set of combined handles is unchanged).
			$css_file_path = $this->cache->combine_cache_file_path( $css_variant );

			if ( $fs->exists( $css_file_path ) ) {
				// Strict guard: stale/missing/empty/unreadable cached file must
				// never be served — fall through to regeneration.
				if ( $this->is_safe_css_combine_fallback_enabled() && ! $this->is_combined_css_valid( $css_file_path ) ) {
					// Treat as stale so regeneration path runs; log once per window.
					$this->log_combine_fallback( 'stale_cached_file', $eligible_handles );
				} else {
					$cache_mtime  = (int) $fs->mtime( $css_file_path );
					$source_newer = false;

					// Reuse the pre-classified eligible handles to avoid re-running
					// core_will_inline / block-asset / exclusion checks (triple classify).
					foreach ( $eligible_handles as $handle ) {
						if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
							continue;
						}
						$src      = $wp_styles->registered[ $handle ]->src;
						$src_path = Util::get_local_path( (string) $src );
						if ( '' !== $src_path && $fs->exists( $src_path ) && $fs->mtime( $src_path ) > $cache_mtime ) {
							$source_newer = true;
							break;
						}
					}

					// A combined file built before this inline-CSS support may embed styles
					// that core now inlines, or the set of handles may have changed; such a
					// file would duplicate inlined rules, so regenerate instead of reusing.
					if ( ! $source_newer && ! $this->combined_handles_match( $css_file_path, $eligible_handles ) ) {
						$source_newer = true;
					}

					if ( ! $source_newer ) {
						// Final valid check before enqueueing cached file.
						if ( $this->is_safe_css_combine_fallback_enabled() && ! $this->is_combined_css_valid( $css_file_path ) ) {
							$this->log_combine_fallback( 'invalid_cached_file', $eligible_handles );
						} else {
							$css_url = $this->cache->get_cache_file_url( 'css', $css_variant );
							$version = (string) $cache_mtime;
							wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );
							$this->register_combine_css_path( $css_file_path );
							$this->emit_combined_preload_hint( $css_url, $version, $css_file_path );
							return;
						}
					}
				}
			}

			// Generate from the pre-classified eligible handles to avoid
			// re-classifying each handle (triple classify -> single classify).
			// Dequeue is deferred until the combined payload is verified and
			// written (safe fallback: never strip originals until replacement
			// is confirmed present).
			$fetch_result       = $this->fetch_and_minify_css( $eligible_handles );
			$combined_css       = $fetch_result['css'];
			$successful_handles = $fetch_result['handles'];
			if ( '' !== $fetch_result['error'] ) {
				if ( $this->is_safe_css_combine_fallback_enabled() ) {
					$log_handles = in_array( $fetch_result['error'], array( 'preg_error', 'empty_after_minify' ), true ) ? $successful_handles : $eligible_handles;
					$this->log_combine_fallback( $fetch_result['error'], $log_handles );
				}
				return;
			}

			$write_result  = $this->write_combined_file( $combined_css, $css_variant );
			$css_file_path = $write_result['path'];

			if ( '' === $css_file_path ) {
				if ( $this->is_safe_css_combine_fallback_enabled() ) {
					$this->log_combine_fallback( $write_result['error'], $successful_handles );
				}
				return;
			}

			// Fresh combined CSS changes the total-asset stats — do not let
			// the dashboard show stale numbers until the TTL expires (audit
			// #874 finding 6). Skipped in sandbox preview: preview output must
			// not invalidate production stats.
			if ( ! $is_preview ) {
				Cache::bump_stats_cache();
			}

			foreach ( $successful_handles as $handle ) {
				wp_dequeue_style( $handle );
			}

			$css_url = $this->cache->get_cache_file_url( 'css', $css_variant );

			$version = $fs->mtime( $css_file_path );
			wp_enqueue_style( 'wppo-combine-css', $css_url, array(), $version, 'all' );
			$this->register_combine_css_path( $css_file_path );
			$this->write_combined_handles( $css_file_path, $eligible_handles );

			$this->emit_combined_preload_hint( $css_url, $version, $css_file_path );
		}

		/**
		 * Resolve the handles eligible for CSS combining.
		 *
		 * Named extraction over {@see get_combined_handles()} so the
		 * exclusion / core-block / inline-budget branches of
		 * {@see combine_css()} read as a staged pipeline with isolated tests.
		 *
		 * @since 2.2.0
		 * @param array $styles     Queued handles.
		 * @param array $exclusions Excluded handles/patterns.
		 * @return array Eligible handles.
		 * Relocated from Cache::resolve_eligible_handles() (ARCH-006).
		 */
		public function resolve_eligible_handles( array $styles, array $exclusions ): array {
			return $this->get_combined_handles( $styles, $exclusions );
		}

		/**
		 * Fetch, concatenate and minify eligible stylesheets.
		 *
		 * Extracted from {@see combine_css()} so fetch/minify regressions can
		 * be tested without driving the full enqueue/write pipeline.
		 *
		 * @since 2.2.0
		 * @param array $eligible_handles Eligible handles.
		 * @return array{css:string,handles:array,error:string} Combined CSS + successful handles + error stage ('' on success).
		 * Relocated from Cache::fetch_and_minify_css() (ARCH-006).
		 */
		public function fetch_and_minify_css( array $eligible_handles ): array {
			global $wp_styles;
			$combined_css       = '';
			$successful_handles = array();
			foreach ( $eligible_handles as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$style_data  = $wp_styles->registered[ $handle ];
				$src         = $wp_styles->registered[ $handle ]->src;
				$css_content = $this->fetch_remote_css( $src );
				if ( false === $css_content ) {
					continue;
				}
				if ( ! empty( $style_data->extra['before'] ) ) {
					$combined_css .= implode( "\n", $style_data->extra['before'] ) . "\n";
				}
				if ( ! empty( $css_content ) ) {
					$combined_css .= $css_content . "\n";
				}
				if ( ! empty( $style_data->extra['after'] ) ) {
					$combined_css .= implode( "\n", $style_data->extra['after'] ) . "\n";
				}
				$successful_handles[] = $handle;
			}
			if ( empty( $successful_handles ) || '' === trim( $combined_css ) ) {
				return array(
					'css'     => '',
					'handles' => $successful_handles,
					'error'   => 'empty_payload',
				);
			}
			$font_display = 'swap';
			if ( class_exists( 'PerformanceOptimise\Inc\Google_Fonts' ) && method_exists( 'PerformanceOptimise\Inc\Google_Fonts', 'get_font_display' ) ) {
				try {
					$font_display = Google_Fonts::get_font_display();
				} catch ( \Throwable $e ) {
					unset( $e );
					$font_display = 'swap';
				}
			}
			if ( '' !== $font_display ) {
				$combined_css = preg_replace( '/font-display\s*:\s*block\s*;?/i', 'font-display: ' . $font_display . ';', $combined_css );
				if ( null === $combined_css ) {
					return array(
						'css'     => '',
						'handles' => $successful_handles,
						'error'   => 'preg_error',
					);
				}
				$combined_css = Minify\CSS::inject_font_display_swap( $combined_css, $font_display );
			}
			$css_minifier = new CSSMinifier( $combined_css );
			$combined_css = $css_minifier->minify();
			if ( '' === trim( (string) $combined_css ) ) {
				return array(
					'css'     => '',
					'handles' => $successful_handles,
					'error'   => 'empty_after_minify',
				);
			}
			return array(
				'css'     => (string) $combined_css,
				'handles' => $successful_handles,
				'error'   => '',
			);
		}

		/**
		 * Write the combined CSS file to the cache directory.
		 *
		 * Extracted from {@see combine_css()} so filesystem failures can be
		 * tested without driving fetch/minify.
		 *
		 * @since 2.2.0
		 * @param string $combined_css Combined CSS.
		 * @param string $css_variant  Cache variant suffix.
		 * @return array{path:string,error:string} File path ('' on failure) + error stage.
		 * Relocated from Cache::write_combined_file() (ARCH-006).
		 */
		public function write_combined_file( string $combined_css, string $css_variant ): array {
			$css_file_path = $this->cache->combine_cache_file_path( $css_variant );
			if ( ! $this->cache->combine_prepare_cache_dir() ) {
				return array(
					'path'  => '',
					'error' => 'prepare_dir_failed',
				);
			}
			$this->cache->combine_save_css( $combined_css, $css_file_path );
			if ( $this->is_safe_css_combine_fallback_enabled() && ! $this->is_combined_css_valid( $css_file_path ) ) {
				return array(
					'path'  => '',
					'error' => 'write_failure',
				);
			}
			return array(
				'path'  => $css_file_path,
				'error' => '',
			);
		}

		/**
		 * Emit the preload hint for the combined stylesheet.
		 *
		 * Named extraction over {@see set_combine_css_preload()} keeping the
		 * audit-requested pipeline vocabulary in one place.
		 *
		 * @since 2.2.0
		 * @param string     $css_url       URL of the combined stylesheet.
		 * @param int|string $version       Cache-busting version suffix.
		 * @param string     $css_file_path Absolute path to the combined CSS file.
		 * @return void
		 * Relocated from Cache::emit_combined_preload_hint() (ARCH-006).
		 */
		public function emit_combined_preload_hint( $css_url, $version, string $css_file_path ): void {
			$this->set_combine_css_preload( $css_url, $version, $css_file_path );
		}

		/**
		 * Record the combined-CSS preload URL for emission on `wp_head`.
		 *
		 * Shared by the cached-file and fresh-generation branches of
		 * {@see combine_css()} so the resource hint is emitted for every request
		 * that enqueues the combined stylesheet, not only on regeneration.
		 * Preloading a stylesheet core is about to inline is a wasted request, so
		 * the URL is skipped in that case.
		 *
		 * @param string     $css_url       URL of the combined stylesheet.
		 * @param int|string $version       Cache-busting version suffix.
		 * @param string     $css_file_path Absolute path to the combined CSS file.
		 * @return void
		 * @since 2.0.0
		 * Relocated from Cache::set_combine_css_preload() (ARCH-006).
		 */
		public function set_combine_css_preload( $css_url, $version, $css_file_path ): void {
			$preload_url = &$this->cache->combine_state_preload_url();
			if ( $this->will_combine_css_inline( $css_file_path ) ) {
				return;
			}
			$preload_url = $css_url . '?ver=' . $version;
		}

		/**
		 * Emit the combined-CSS `rel="preload"` resource hint on `wp_head`.
		 *
		 * Core's `wp_resource_hints()` does not emit `preload` relations, so the
		 * hint is printed directly via {@see Util::generate_preload_link()} at
		 * `wp_head` priority 1 — after `wp_enqueue_scripts` has populated the URL
		 * and before core prints the stylesheet `<link>` at priority 8.
		 *
		 * @return void
		 * @since 2.0.0
		 * Relocated from Cache::maybe_preload_combine_css() (ARCH-006).
		 */
		public function maybe_preload_combine_css(): void {
			$preload_url = &$this->cache->combine_state_preload_url();
			// Note (see #553, #829): once core removes concatenation in favour of preloads,
			// reassess whether this plugin-emitted preload should defer to core
			// preload emission (wp_resource_hints) instead. No runtime change.
			if ( '' === $preload_url ) {
				return;
			}
			/**
			 * Filters the fetchpriority for the combined-CSS preload link.
			 *
			 * Default 'high' for external preload (LCP). Return falsy to suppress.
			 *
			 * @since 2.0.0
			 *
			 * @param string $fetchpriority Fetchpriority value ('high'|'low'|'auto').
			 * @param string $url           Preload URL.
			 */
			$fetchpriority = apply_filters( 'wppo_combine_preload_fetchpriority', 'high', $preload_url );
			if ( ! is_string( $fetchpriority ) ) {
				$fetchpriority = '';
			}
			$fetchpriority = strtolower( trim( $fetchpriority ) );
			if ( ! in_array( $fetchpriority, array( 'high', 'low', 'auto' ), true ) ) {
				$fetchpriority = '';
			}
			Util::generate_preload_link( $preload_url, 'preload', 'style', false, '', '', $fetchpriority );
			$preload_url = '';
		}

		/**
		 * Whether a handle should be excluded from CSS combining.
		 *
		 * Checks both exact handle match and URL fragment substring.
		 *
		 * @since 2.0.0
		 * @param string $handle              Handle to check.
		 * @param string $src                 Style src URL.
		 * @param array  $exclude_combine_css Exclusion list.
		 * @return bool True if excluded.
		 * Relocated from Cache::is_excluded_from_combine() (ARCH-006).
		 */
		public function is_excluded_from_combine( $handle, $src, array $exclude_combine_css ): bool {
			// Preserve auto-sizes containment fix (WP 6.9+) — must not be combined.
			if ( 'wp-img-auto-sizes-contain' === $handle && function_exists( 'wp_enqueue_img_auto_sizes_contain_css_fix' ) ) {
				return true;
			}
			// Disk-safe guard (issue #1428): randomized per-request query
			// values (?ver=<timestamp|uniqid|rand>) churn the combined
			// output and can blow the file-count cap — exclude them from
			// combining. Guarded by the additive cacheRandomizedQueryGuard
			// setting (default on) and filterable via
			// wppo_exclude_randomized_from_combine. Fail-open: guard
			// failures fall through to the explicit exclusion list.
			try {
				$guard_on = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( 'PerformanceOptimise\Inc\Cache', 'get_cache_cap_settings' ) ) {
					$cap      = Cache::get_cache_cap_settings();
					$guard_on = ! empty( $cap['randomized_guard'] );
				}
				if ( $guard_on && is_string( $src ) && '' !== $src && Cache::is_randomized_query_asset( $src ) ) {
					$excluded = true;
					if ( function_exists( 'apply_filters' ) ) {
						$excluded = (bool) apply_filters( 'wppo_exclude_randomized_from_combine', true, $handle, $src );
					}
					if ( $excluded ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( empty( $exclude_combine_css ) ) {
				return false;
			}
			if ( in_array( $handle, $exclude_combine_css, true ) ) {
				return true;
			}
			foreach ( $exclude_combine_css as $exclude_css ) {
				if ( '' !== $exclude_css && false !== strpos( $src, $exclude_css ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Computes the set of handles that belong in the combined CSS file.
		 *
		 * Mirrors the skip rules applied in {@see combine_css()} generation: styles
		 * core inlines itself, core block-asset styles under the 6.9+ separate-assets
		 * mode, handles excluded from combining, and non-'all' media styles stay out
		 * of the combined file. Used both to build the file and to detect when a
		 * previously cached file is stale. Classic themes stay on the combine path
		 * (only block themes short-circuit via the inline budget); on 6.9+
		 * classic themes the hoisting-aware dedupe below keeps `wp-block-*`
		 * handles out of the combined file so no duplicate output or FOUC occurs
		 * while non-block CSS still benefits from combining.
		 *
		 * @since 1.9.0
		 *
		 * @param array $styles             The enqueued style handles.
		 * @param array $exclude_combine_css Handles/URL fragments excluded from combining.
		 * @return array The handles that would be combined.
		 * Relocated from Cache::get_combined_handles() (ARCH-006).
		 */
		public function get_combined_handles( $styles, $exclude_combine_css ): array {
			global $wp_styles;

				$separate_block_assets = $this->cache->combine_get_effective_separate_block_assets();

			$handles = array();
			foreach ( $styles as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$style_data = $wp_styles->registered[ $handle ];

				// Single dedupe assertion: never emit combined output for a
				// handle core already hoisted/inlined (block hoisting + the
				// cumulative styles_inline_size_limit budget).
				if ( $this->cache->combine_is_duplicate_of_core_output( $handle, $separate_block_assets ) ) {
					continue;
				}

				if ( $this->is_excluded_from_combine( $handle, (string) $style_data->src, $exclude_combine_css ) ) {
					continue;
				}

				if ( ! isset( $style_data->args ) || 'all' !== $style_data->args ) {
					continue;
				}

				$handles[] = $handle;
			}

			return $handles;
		}

		/**
		 * Whether a cached combined file was generated from the same handle set.
		 *
		 * A missing sidecar (e.g. a combined file built before this inline-CSS
		 * support shipped) is treated as a mismatch so stale files regenerate.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path  Absolute path to the combined CSS file.
		 * @param array  $eligible_handles The handles expected in the combined file.
		 * @return bool True if the cached file matches the current handle set.
		 * Relocated from Cache::combined_handles_match() (ARCH-006).
		 */
		public function combined_handles_match( $css_file_path, array $eligible_handles ): bool {
			$fs     = $this->cache->combine_filesystem();
			$handle = $css_file_path . '.handles';

			if ( ! $fs || ! $fs->exists( $handle ) ) {
				return false;
			}

			$contents = $fs->get_contents( $handle );
			if ( false === $contents ) {
				return false;
			}

			$stored = json_decode( $contents, true );
			return is_array( $stored ) && $stored === $eligible_handles;
		}

		/**
		 * Persists the set of combined handles next to the combined CSS file.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path  Absolute path to the combined CSS file.
		 * @param array  $eligible_handles The handles combined into the file.
		 * @return void
		 * Relocated from Cache::write_combined_handles() (ARCH-006).
		 */
		public function write_combined_handles( $css_file_path, array $eligible_handles ): void {
			$fs = $this->cache->combine_filesystem();
			if ( ! $fs ) {
				return;
			}

			$handle_path = $css_file_path . '.handles';
			$fs->put_contents( $handle_path, wp_json_encode( $eligible_handles ), FS_CHMOD_FILE );
		}

		/**
		 * Whether a queued style will be inlined by core instead of combined.
		 *
		 * Core (WP 5.8+) inlines any enqueued stylesheet that carries `path` data
		 * and fits within the `styles_inline_size_limit` budget (20KB default before
		 * WP 6.9, 40KB on 6.9+). Such styles must be left in the queue for core to
		 * inline at their own position rather than pulled into the combined file
		 * (which would duplicate their rules).
		 *
		 * Core applies the budget cumulatively: path-data styles are sorted
		 * smallest-first and inlined greedily until the running total exceeds the
		 * limit, so a style that individually fits can still be served externally on
		 * style-heavy pages. This helper replicates that accounting.
		 *
		 * @since 1.9.0
		 *
		 * @param string $handle The registered style handle.
		 * @return bool True if core will inline the style, false otherwise.
		 * Relocated from Cache::core_will_inline() (ARCH-006).
		 */
		public function core_will_inline( $handle ): bool {
			$will_inline_memo = &$this->cache->combine_state_core_will_inline_memo();
			$drift_detected   = &$this->cache->combine_state_inline_drift_detected();
			if ( isset( $will_inline_memo[ $handle ] ) ) {
				return $will_inline_memo[ $handle ];
			}

			if ( ! function_exists( 'wp_maybe_inline_styles' ) ) {
				$will_inline_memo[ $handle ] = false;
				return false;
			}

			global $wp_styles;
			if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
				$will_inline_memo[ $handle ] = false;
				return false;
			}

			// Since WP 6.3 core only inlines styles that carry a `src`; on 5.8-6.2
			// any queued style with `path` data was a candidate, so a src-less
			// handle can still be inlined there.
			if ( $this->inline_candidates_require_src() && empty( $wp_styles->registered[ $handle ]->src ) ) {
				$will_inline_memo[ $handle ] = false;
				return false;
			}

			$limit = $this->get_styles_inline_limit();

			// Prediction using the plugin's long-standing budget accounting.
			$prediction = $this->core_inline_budget_will_inline( $handle, $limit, false );

			// Re-derivation of core's own candidate collection and budget pass. Any
			// divergence (a queued path-data style without a `src`, an over-budget
			// sibling, or tie-order differences) means the prediction is unreliable,
			// so the request degrades to the safe outcome below instead.
			$reference = $this->core_inline_budget_will_inline( $handle, $limit, true );

			if ( $prediction !== $reference ) {
				$drift_detected = true;
				$this->log_inline_budget_drift( $handle, $limit );

				// Conservative downgrade: assume core WILL inline the style. Leaving it
				// out of the combined file is safe in every direction — either core
				// inlines it (correct), or it is served as its own external <link>
				// (a minor perf loss, never duplicated rules). Returning false here
				// would risk pulling an inlined style into the combined file.
				$will_inline_memo[ $handle ] = true;
				return true;
			}

			$will_inline_memo[ $handle ] = $prediction;
			return $prediction;
		}

		/**
		 * Simulates core's greedy smallest-first inline-CSS budget for a handle.
		 *
		 * When `$core_faithful` is true the candidate set mirrors core's
		 * `wp_maybe_inline_styles()`: a queued handle only counts when it carries
		 * path data, its file size is within the inline limit, and (since WP 6.3)
		 * it also has a `src`. Styles over the budget are excluded up-front
		 * (equivalent to core's ascending sort + `break` on first overflow). On
		 * WP 7.0+ unreadable styles are skipped inside the budget loop without
		 * charging their size, which mirrors core's own `is_readable()` gate. When
		 * false, the plugin's legacy accounting is reproduced exactly (any
		 * path-data handle with a usable file size counts, regardless of `src`,
		 * readability, or budget).
		 *
		 * The handle => size/readability map is cached for the request (see
		 * {@see $inline_size_map}) so repeated simulations pay the filesystem stat
		 * cost once per queue snapshot instead of once per call.
		 *
		 * @since 2.0.0
		 *
		 * @param string $handle       The registered style handle.
		 * @param int    $limit        The inline size limit in bytes.
		 * @param bool   $core_faithful Whether to mirror core's candidate collection.
		 * @return bool True if core's budget pass would inline the style.
		 * Relocated from Cache::core_inline_budget_will_inline() (ARCH-006).
		 */
		public function core_inline_budget_will_inline( $handle, $limit, $core_faithful ): bool {
			$size_map = &$this->cache->combine_state_inline_size_map();
			global $wp_styles;

			if ( null === $size_map ) {
				$size_map = array();
				foreach ( $wp_styles->queue as $queued_handle ) {
					$path = $wp_styles->get_data( $queued_handle, 'path' );
					if ( empty( $path ) || ! is_file( $path ) ) {
						continue;
					}
					$raw_size = @filesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- guarded with is_int check below; a delete/rename race must not warn.
					if ( ! is_int( $raw_size ) || $raw_size <= 0 ) {
						continue;
					}
					$size_map[ $queued_handle ] = array(
						'size'     => $raw_size,
						'readable' => is_readable( $path ),
					);
				}
			}

			$entries = array();
			foreach ( $size_map as $queued_handle => $entry ) {
				if ( $core_faithful ) {
					// Core skips handles that are not registered.
					if ( ! isset( $wp_styles->registered[ $queued_handle ] ) ) {
						continue;
					}
					$registered = $wp_styles->registered[ $queued_handle ];
					// Core only considers a queued style a candidate when it carries
					// a `src` (the `path && src` gate landed in 6.3) and is found on
					// disk; on 5.8-6.2 path data alone was sufficient. Styles over
					// the limit are excluded up-front — they sort last and would only
					// ever trigger the loop's overflow `break`, which stops nothing
					// that fits the budget.
					if ( ( $this->inline_candidates_require_src() && empty( $registered->src ) ) || $entry['size'] > $limit ) {
						continue;
					}
				}

				$entries[ $queued_handle ] = $entry;
			}

			// Replicate core's greedy smallest-first cumulative budget. Core uses an
			// unstable usort; the stable uasort is retained here because the drift
			// check + conservative downgrade neutralize the residual tie-order
			// ambiguity.
			uasort(
				$entries,
				static function ( $a, $b ) {
					return $a['size'] <=> $b['size'];
				}
			);

			$total = 0;
			foreach ( $entries as $queued_handle => $entry ) {
				$size = $entry['size'];

				// Overflow check first, exactly as core orders it: an unreadable
				// but in-budget file is skipped below without charging its size, but
				// one that would push the running total over the limit still stops
				// the pass.
				if ( $total + $size > $limit ) {
					return false;
				}

				// WP 7.0+ core skips unreadable styles in its budget loop without
				// charging their size; earlier core versions charged them regardless.
				if ( $core_faithful && $this->inline_candidates_require_readable() && ! $entry['readable'] ) {
					continue;
				}

				$total += $size;
				if ( $queued_handle === $handle ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Logs that the inline-CSS budget prediction drifted from core.
		 *
		 * Rate-limited to at most one activity-log entry per PHP process so a single
		 * drifted request cannot flood the log, and — because drift conditions are
		 * deterministic and persistent (e.g. a queued path-data style without a
		 * `src` on WP 6.3+, or an unreadable over-limit peer on WP 7.0+) — at most
		 * one entry per rolling window per drift condition via a transient, so a
		 * persistent drift cannot grow the log by one row per pageview. Cache and
		 * Log share the `PerformanceOptimise\Inc` namespace, so no import is
		 * required.
		 *
		 * @since 2.0.0
		 *
		 * @param string $handle The handle whose prediction drifted.
		 * @param int    $limit  The inline size limit in bytes.
		 * @return void
		 * Relocated from Cache::log_inline_budget_drift() (ARCH-006).
		 */
		public function log_inline_budget_drift( $handle, $limit ): void {
			if ( Cache::combine_inline_drift_already_logged() ) {
				return;
			}
			Cache::combine_mark_inline_drift_logged();

			if ( ! class_exists( Log::class ) ) {
				return;
			}

			// The same drift condition is reproduced on every pageview, so a once
			// per-process flag alone would still append one row per request. Key a
			// transient by the condition (handle + core version) and skip repeats
			// within the rolling window; a change in WP or an operator theme fix
			// re-arms the notice.
			$version = isset( $GLOBALS['wp_version'] ) ? $GLOBALS['wp_version'] : 'unknown';
			$log_key = Util::transient_key( 'wppo_inline_drift_' . md5( $handle . '|' . $version ) );
			// Best-effort throttle (see is_throttled()): a throwing transient
			// transport counts as a miss so the drift notice is still logged.
			if ( $this->cache->combine_is_throttled( $log_key, DAY_IN_SECONDS ) ) {
				return;
			}

			Log::add(
				sprintf(
					/* translators: %1$s: style handle, %2$d: inline size limit in bytes. */
					__( 'Inline-CSS budget prediction drifted from core for %1$s (limit %2$d); degraded to safe fallback.', 'performance-optimisation' ),
					$handle,
					$limit
				)
			);
		}

		/**
		 * Whether the safe CSS combine fallback is enabled.
		 *
		 * Operator opt-out via `wppo_safe_css_combine_fallback` (default true).
		 * When false the legacy combine path is used without strict guards.
		 *
		 * @since 2.0.0
		 * @return bool True when fallback guards are active.
		 * Relocated from Cache::is_safe_css_combine_fallback_enabled() (ARCH-006).
		 */
		public function is_safe_css_combine_fallback_enabled(): bool {
			return Util::safe_css_fallback_enabled();
		}

		/**
		 * Whether a combined CSS file is valid (exists, readable, non-empty).
		 *
		 * @since 2.0.0
		 * @param string $path Absolute path to the combined CSS file.
		 * @return bool True when the file is usable.
		 * Relocated from Cache::is_combined_css_valid() (ARCH-006).
		 */
		public function is_combined_css_valid( string $path ): bool {
			return Util::css_file_valid( $path );
		}

		/**
		 * Log a combine fallback (fail-open) event with throttling.
		 *
		 * Delegates to {@see Util::log_css_fallback()} with the 'combine' context;
		 * per-reason transient throttling (DAY_IN_SECONDS) prevents the log from
		 * growing per pageview on persistent failures.
		 *
		 * @since 2.0.0
		 * @param string $reason  Machine-readable reason (empty_payload, fetch_failure, write_failure, head_match_failure).
		 * @param array  $handles Handles preserved by the fallback.
		 * @return void
		 * Relocated from Cache::log_combine_fallback() (ARCH-006).
		 */
		public function log_combine_fallback( string $reason, array $handles ): void {
			Util::log_css_fallback( $reason, $handles, 'combine' );
		}

		/**
		 * Registers the combined CSS file with `path` data for core's inline pass.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path Absolute path to the combined CSS file.
		 * @return void
		 * Relocated from Cache::register_combine_css_path() (ARCH-006).
		 */
		public function register_combine_css_path( $css_file_path ): void {
			$options          = $this->cache->combine_options();
			$size_map         = &$this->cache->combine_state_inline_size_map();
			$will_inline_memo = &$this->cache->combine_state_core_will_inline_memo();
			if ( ! function_exists( 'wp_maybe_inline_styles' ) || empty( $css_file_path ) || ! is_file( $css_file_path ) ) {
				return;
			}

			// Inlining is disabled while removeUnusedCSS is active because used-CSS
			// reads the combined stylesheet via its `src`; once core inlines a handle
			// it clears `src`, which would silently skip the combined file and ship the
			// full (unpurged) CSS instead.
			if ( ! empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
				return;
			}

			// Site operators can opt out of inlining entirely (e.g. when serving the
			// combined file from a CDN, since inlined CSS bypasses the CDN).
			if ( ! apply_filters( 'wppo_inline_combined_css', true ) ) {
				return;
			}

			// When the inline-CSS budget prediction drifted from core this request,
			// serve the combined file externally rather than register `path` data: a
			// wrongly-registered file would either be unexpectedly inlined or left
			// external despite the preload decision made earlier.
			if ( $this->cache->combine_inline_drift_detected() ) {
				return;
			}

			wp_style_add_data( 'wppo-combine-css', 'path', $css_file_path );

			// The combined handle now carries `path` data, so the size map cached
			// for the inline-budget simulation is stale; rebuild it on the next call.
			$size_map         = null;
			$will_inline_memo = array();
		}

		/**
		 * Whether the combined CSS inline must be skipped for safe mode (issue #1465).
		 *
		 * Hoisted predicate for will_combine_css_inline() (called per CSS
		 * file): the sandbox-preview flag is memoized per request and the
		 * verdict is memoized per production slice hash. Preview admins are
		 * exempt so staged output stays verifiable. Fail-open to false.
		 *
		 * @since 2.3.0
		 *
		 * @param array $file_opt Production `file_optimisation` slice.
		 * @return bool True when inlining must be skipped.
		 * Relocated from Cache::should_bypass_inline_for_safe_mode() (ARCH-006).
		 */
		public function should_bypass_inline_for_safe_mode( array $file_opt ): bool {
			$preview_memo = &$this->cache->combine_state_sandbox_preview_memo();
			$mode_memo    = &$this->cache->combine_state_safe_mode_inline_memo();
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Main' ) || ! method_exists( 'PerformanceOptimise\Inc\Main', 'is_safe_mode_active' ) || ! method_exists( 'PerformanceOptimise\Inc\Main', 'is_sandbox_preview_active' ) ) {
					return false;
				}
				if ( null === $preview_memo ) {
					$preview_memo = (bool) Main::is_sandbox_preview_active();
				}
				if ( $preview_memo ) {
					return false;
				}
				$memo_key = md5( serialize( $file_opt ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
				if ( array_key_exists( $memo_key, $mode_memo ) ) {
					return $mode_memo[ $memo_key ];
				}
				$bypass                 = (bool) Main::is_safe_mode_active( $file_opt );
				$mode_memo[ $memo_key ] = $bypass;
				return $bypass;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the combined CSS file will be inlined by core.
		 *
		 * Delegates to {@see core_will_inline()} so the cumulative, smallest-first
		 * budget core applies across all queued path-data styles is honoured for the
		 * combined file as well. The combined handle must carry `path` data (set by
		 * {@see register_combine_css_path()} before this is called).
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path Absolute path to the combined CSS file.
		 * @return bool True if core will inline the combined file, false otherwise.
		 * Relocated from Cache::will_combine_css_inline() (ARCH-006).
		 */
		public function will_combine_css_inline( $css_file_path ): bool {
			$options = $this->cache->combine_options();
			if ( empty( $css_file_path ) ) {
				return false;
			}

			// Elementor-safe mode (issue #1259): never inline the combined
			// file on builder-built pages. Single choke point shared with
			// combine_css() (see should_bypass_combine_for_elementor()).
			// Cheap native pre-gate first so the sandbox-effective slice
			// is resolved only when Elementor looks present — this is the
			// hottest frontend path for non-Elementor visitors. The pre-gate
			// verdict is threaded into the choke point so it is evaluated
			// exactly once per call instead of twice.
			try {
				$looks_like = class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'looks_like_elementor_request' ) ? Main::looks_like_elementor_request() : false;
				if ( $looks_like ) {
					$file_opt = isset( $options['file_optimisation'] ) && is_array( $options['file_optimisation'] ) ? $options['file_optimisation'] : array();
					$file_opt = $this->get_sandbox_effective_file_opt( $file_opt );
					if ( $this->should_bypass_combine_for_elementor( $file_opt, $looks_like ) ) {
						return false;
					}
				}
				// Unified safe-mode kill switch (issue #1465): never inline
				// the combined file while safe mode is on (defense-in-depth
				// for combine_css()). Preview admins are exempt so staged
				// output can still be verified. Fail-open on any error.
				// Hoisted into a per-request memoized helper so the
				// preview + safe-mode predicates run once per options slice
				// instead of once per CSS file.
				$file_opt_safe = isset( $options['file_optimisation'] ) && is_array( $options['file_optimisation'] ) ? $options['file_optimisation'] : array();
				if ( $this->should_bypass_inline_for_safe_mode( $file_opt_safe ) ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// When the budget prediction drifted this request the combined file is
			// served externally (see register_combine_css_path()), so keep the
			// preload hint for the now-external stylesheet instead of delegating.
			if ( $this->cache->combine_inline_drift_detected() ) {
				return false;
			}

			// core_will_inline() performs the function_exists() gate itself.
			return $this->core_will_inline( 'wppo-combine-css' );
		}

		/**
		 * Reads the core `styles_inline_size_limit` budget.
		 *
		 * Delegates to the single shared implementation in
		 * {@see Util::get_styles_inline_limit()} so this class and Critical_CSS
		 * cannot disagree during the 6.9 pre-release window.
		 *
		 * @since 1.9.0
		 *
		 * @return int The inline size limit in bytes.
		 * Relocated from Cache::get_styles_inline_limit() (ARCH-006).
		 */
		public function get_styles_inline_limit(): int {
			return Util::get_styles_inline_limit();
		}

		/**
		 * Whether inline candidates must carry a `src` on this core version.
		 *
		 * WP 6.3 introduced the `path && src` gate in `wp_maybe_inline_styles()`;
		 * before that (5.8-6.2) any queued style with `path` data was a candidate
		 * regardless of `src`. An absent `$wp_version` assumes the newest behavior,
		 * matching {@see get_styles_inline_limit()}.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when inline candidates must carry a `src`.
		 * Relocated from Cache::inline_candidates_require_src() (ARCH-006).
		 */
		public function inline_candidates_require_src(): bool {
			// Version floor lives in Wp_Version (REF-010, $GLOBALS-only
			// read: unknown assumes newest, matching the historic spelling).
			return Wp_Version::is_global_at_least( '6.3' );
		}

		/**
		 * Whether inline candidates must be readable on this core version.
		 *
		 * WP 7.0 added a `_doing_it_wrong` notice and an unreadable-path skip
		 * (`continue`) inside the budget loop of `wp_maybe_inline_styles()`, so an
		 * unreadable stylesheet no longer consumes the inline budget. On earlier
		 * versions its size was charged regardless of readability, matching the
		 * plugin's legacy accounting. An absent `$wp_version` assumes the newest
		 * behavior.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when the core-faithful pass must skip unreadable styles.
		 * Relocated from Cache::inline_candidates_require_readable() (ARCH-006).
		 */
		public function inline_candidates_require_readable(): bool {
			// Version floor lives in Wp_Version (REF-010, $GLOBALS-only
			// read: unknown assumes newest, matching the historic spelling).
			return Wp_Version::is_global_at_least( '7.0' );
		}

		/**
		 * Retrieve cached src file stat (readable + filesize) with per-request LRU.
		 *
		 * Avoids a second filesize()/is_readable() loop over the same handles in
		 * should_skip_combine_for_inline_budget and reuses filesystem results when
		 * combine_css is invoked multiple times per request.
		 *
		 * @since 2.0.0
		 * @param string $path Absolute filesystem path.
		 * @return array{readable:bool,size:int|false}
		 * Relocated from Cache::get_cached_src_stat() (ARCH-006).
		 */
		public function get_cached_src_stat( string $path ): array {
			$stat_cache = &$this->cache->combine_state_src_stat_cache();
			if ( isset( $stat_cache[ $path ] ) ) {
				return $stat_cache[ $path ];
			}
			// Prefer the WP_Filesystem abstraction (consistent with the rest of
			// the pipeline) so hosts with direct-filesystem restrictions do not
			// disagree with raw is_readable()/filesize(). Fall back to raw stats
			// when the filesystem is unavailable.
			$readable = false;
			$size     = false;
			$used_fs  = false;
			try {
				$fs = $this->cache->combine_filesystem();
				if ( is_object( $fs ) && method_exists( $fs, 'exists' ) && method_exists( $fs, 'size' ) ) {
					$readable = (bool) $fs->exists( $path );
					$size     = $readable ? $fs->size( $path ) : false;
					$used_fs  = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$used_fs = false;
			}
			if ( ! $used_fs ) {
				$readable = is_readable( $path );
				$size     = $readable ? filesize( $path ) : false;
			}
			if ( count( $stat_cache ) >= self::SRC_STAT_CACHE_LIMIT ) {
				array_shift( $stat_cache );
			}
			$stat_cache[ $path ] = array(
				'readable' => $readable,
				'size'     => $size,
			);
			return $stat_cache[ $path ];
		}

		/**
		 * Whether the combined-CSS file should be skipped on small block-theme bundles.
		 *
		 * On block themes with a small total payload (≤ the filtered
		 * `styles_inline_size_limit` budget via {@see get_styles_inline_limit()},
		 * 40KB default on WP 6.9+, 20KB legacy) core's greedy smallest-first
		 * inline budget will already inline the eligible styles at their queue
		 * positions. Creating a combined file would add an extra request
		 * without benefit, so it is skipped and the styles are left enqueued
		 * for core to inline. Sizes are measured with core's own accounting
		 * (`path`-data filesize first, local `src` fallback via
		 * {@see measure_style_byte_size()}) so the skip decision never
		 * disagrees with {@see core_will_inline()}. Classic themes always
		 * combine.
		 *
		 * Guards (issue #880):
		 * - WP 6.9+ only: the 40KB default budget is what makes small bundles
		 *   fully inlineable; on older cores (20KB default) the plugin keeps
		 *   combining — the pre-6.9 behavior is unchanged.
		 * - CDN: when a CDN URL is configured the combined file is served from
		 *   the CDN, so it is never redundant and must still be built.
		 * - `wppo_inline_combined_css`: an operator who disabled plugin inlining
		 *   (typically to serve the combined file externally) still expects the
		 *   combined file to exist; the skip premise — "core inlines everything,
		 *   so the file is redundant" — does not hold.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $eligible_handles Handles that would be combined.
		 * @return bool True when combining should be skipped.
		 * Relocated from Cache::should_skip_combine_for_inline_budget() (ARCH-006).
		 */
		public function should_skip_combine_for_inline_budget( array $eligible_handles ): bool {
			$options = $this->cache->combine_options();
			if ( empty( $eligible_handles ) ) {
				return false;
			}

			// The 40KB inline budget that makes the skip worthwhile is a WP 6.9+
			// default; on older cores the plugin keeps its always-combine
			// behavior. An absent $wp_version assumes the newest core, matching
			// get_styles_inline_limit() — both floors live in Wp_Version
			// (REF-010, $GLOBALS-only read).
			if ( ! Wp_Version::is_global_at_least( '6.9-alpha' ) ) {
				return false;
			}

			// A configured CDN serves the combined file — never redundant.
			// Covers both the legacy cdnURL field and the per-mapping
			// cdnMapping config (plus wppo_cdn_mapping filters) resolved by
			// the CDN class (issue #880 review).
			$cdn_mappings = class_exists( 'PerformanceOptimise\Inc\CDN' ) ? CDN::get_mappings( $options ) : array();
			if ( ! empty( $options['file_optimisation']['cdnURL'] ) || ! empty( $cdn_mappings ) ) {
				return false;
			}

			// Operators who disabled inlining of the combined CSS still expect
			// the combined file (e.g. served externally from a CDN), so the
			// budget skip must not remove it.
			if ( ! apply_filters( 'wppo_inline_combined_css', true ) ) {
				return false;
			}

			if ( ! function_exists( 'wp_is_block_theme' ) || ! wp_is_block_theme() ) {
				// Classic themes intentionally stay on the combine path: core
				// 6.9 on-demand hoisting owns only `wp-block-*` handles (excluded
				// from $eligible_handles via the dedupe above), while the
				// remaining theme CSS still benefits from combining. No FOUC:
				// the combined file holds only non-block handles core never emits.
				return false;
			}
			$limit = $this->get_styles_inline_limit();
			/**
			 * Filter whether to skip the combined-CSS file on small block-theme bundles.
			 *
			 * @since 2.0.0
			 *
			 * @param bool     $skip             Whether to skip combining (default true on block themes with small bundles).
			 * @param string[] $eligible_handles The handles that would be combined.
			 * @param int      $limit            The current styles_inline_size_limit in bytes.
			 */
			if ( ! apply_filters( 'wppo_skip_combine_on_small_block_theme', true, $eligible_handles, $limit ) ) {
				return false;
			}
			global $wp_styles;
			$total = 0;
			foreach ( $eligible_handles as $handle ) {
				$size = $this->measure_style_byte_size( $handle );
				if ( false === $size ) {
					// Unreadable/remote styles cannot be measured — do not skip.
					return false;
				}
				if ( 0 === $size ) {
					continue;
				}
				if ( $size > $limit ) {
					// A single handle exceeds the inline limit — core cannot inline it, so combine remains useful.
					return false;
				}
				$total += (int) $size;
				if ( $total > $limit ) {
					return false;
				}
			}
			return $total > 0 && $total <= $limit;
		}

		/**
		 * Measure a style's byte size using core's inline-budget accounting.
		 *
		 * Core's `wp_maybe_inline_styles()` budgets the `path`-data filesize,
		 * not the `src` URL filesize, so the skip heuristic must read the same
		 * number `core_will_inline()` uses. When the handle carries readable
		 * `path` data its filesize wins; otherwise the local `src` path is
		 * measured as a fallback (pre-`path`-registration queues). Fail-open:
		 * unregistered handles measure 0 (skipped), unmeasurable/remote
		 * styles return false (caller keeps combining, never fatal).
		 *
		 * @param string $handle The registered style handle.
		 * @return int|false Byte size, 0 when the handle contributes nothing, false when unmeasurable.
		 * @since 2.2.0
		 * Relocated from Cache::measure_style_byte_size() (ARCH-006).
		 */
		public function measure_style_byte_size( $handle ) {
			global $wp_styles;
			try {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					return 0;
				}
				$path_data = null;
				if ( is_object( $wp_styles ) && method_exists( $wp_styles, 'get_data' ) ) {
					try {
						$path_data = $wp_styles->get_data( $handle, 'path' );
					} catch ( \Throwable $e ) {
						unset( $e );
						$path_data = null;
					}
				}
				if ( is_string( $path_data ) && '' !== $path_data && is_file( $path_data ) ) {
					$stat = $this->get_cached_src_stat( $path_data );
					if ( ! $stat['readable'] || false === $stat['size'] ) {
						return false;
					}
					return (int) $stat['size'];
				}
				$src = (string) ( $wp_styles->registered[ $handle ]->src ?? '' );
				if ( '' === $src ) {
					return 0;
				}
				$path = Util::get_local_path( $src );
				if ( '' === $path ) {
					return false;
				}
				$stat = $this->get_cached_src_stat( $path );
				if ( ! $stat['readable'] ) {
					return false;
				}
				$size = $stat['size'];
				if ( false === $size ) {
					return false;
				}
				return (int) $size;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Fetches CSS content from a remote URL or local path.
		 *
		 * @param string $url The URL of the CSS file.
		 * @return string|false The CSS content or false if fetching fails.
		 *
		 * @since 1.0.0
		 * Relocated from Cache::fetch_remote_css() (ARCH-006).
		 */
		public function fetch_remote_css( $url ) {
			if ( empty( $url ) ) {
				return '';
			}

			$css_file = Util::get_local_path( $url );
			if ( '' === $css_file ) {
				return false;
			}

			// Second containment check after URL->path mapping (issue #1179):
			// resolve symlinks via realpath() and require containment in an
			// allow-listed root; reject wrappers and .php targets. Fail
			// closed (no bytes) so the combine loop skips the handle and
			// serves the original uncombined stylesheet.
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'validate_minify_path' ) ) {
				$validated = Util::validate_minify_path( $css_file );
				if ( '' === $validated ) {
					return false;
				}
				$css_file = $validated;
			}
			$fs = $this->cache->combine_filesystem();
			if ( $fs ) {
				// Stat first so a single huge theme CSS file is not fully
				// buffered per handle in the combine loop.
				$max_bytes = (int) apply_filters( 'wppo_max_css_bytes', 2 * 1024 * 1024 );
				if ( $max_bytes > 0 ) {
					try {
						$size = $fs->size( $css_file );
						if ( false !== $size && (int) $size > $max_bytes ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$css_content = $fs->get_contents( $css_file );

				if ( false !== $css_content ) {
					$rewritten = CSS::update_image_paths( $css_content, $css_file );
					return null !== $rewritten ? $rewritten : $css_content;
				}
			}

			return false;
		}
	}
}
