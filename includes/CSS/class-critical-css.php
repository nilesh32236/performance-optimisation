<?php
/**
 * Critical CSS Generation for above-the-fold optimization.
 *
 * @package PerformanceOptimise\Inc
 * @since 2.0.0
 */

namespace PerformanceOptimise\Inc;

use MatthiasMullie\Minify\CSS as CSSMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) ) {

	/**
	 * Class Critical_CSS
	 *
	 * Generates, stores, and inlines critical above-the-fold CSS, then defers
	 * full stylesheets. Uses heuristic PHP-based extraction with no external
	 * dependencies.
	 *
	 * @since 2.0.0
	 */
	class Critical_CSS {

		/**
		 * Option key of the generation-status cache salt (WP 6.9+ salted
		 * object cache; issue #882). Bumped by clear_all() so every salted
		 * status entry invalidates at once without enumerating hashes.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private const SALT_KEY = 'wppo_ccss_salt';

		/**
		 * Per-request field-LCP preload dedup set keyed by normalized URL so
		 * repeated inline_ccss() invocations emit the hint once. Reset via
		 * reset_ccss_memo().
		 *
		 * @since 2.2.0
		 * @var array<string, bool>
		 */
		private static array $lcp_preload_emitted = array();

		/**
		 * Per-request stylesheet-deferral block list keyed by template hash.
		 *
		 * Set by inline_ccss() when over-cap output has no file URL to serve
		 * (issue #1255 review): defer_stylesheets() must then load the full
		 * stylesheets normally instead of deferring them with zero critical
		 * CSS on the page. Reset via reset_ccss_memo().
		 *
		 * @since 2.2.0
		 * @var array<string, bool>
		 */
		private static array $ccss_defer_blocked = array();

		/**
		 * Above-fold selectors to match during extraction.
		 *
		 * Uses precise token-based matching to avoid false positives.
		 *
		 * @var string[]
		 * @since 2.0.0
		 */
		private const ABOVE_FOLD_SELECTORS = array(
			'html',
			'body',
			'header',
			'.header',
			'#header',
			'.site-header',
			'#masthead',
			'nav',
			'.nav',
			'#nav',
			'.menu',
			'.primary-menu',
			'.main-navigation',
			'h1',
			'h2',
			'h3',
			'.hero',
			'.banner',
			'.page-title',
			'.entry-title',
			'.page-header',
			'.entry-header',
			'.logo',
			'.site-logo',
			'.site-branding',
			'img',
			'.wp-block-image',
			'figure',
			'main',
			'.main',
			'.content',
			'.site-content',
			'.container',
			'.wrapper',
			'.row',
			'.section',
			'.top-bar',
			'.topbar',
			'.skip-link',
			'.screen-reader-text',
			':root',
			'p',
			'a',
			'ul',
			'li',
			'button',
			'.btn',
			'.button',
		);

		/**
		 * CSS handles to skip during deferral.
		 *
		 * @var string[]
		 * @since 2.0.0
		 */
		private const SKIP_DEFER_HANDLES = array(
			'wppo-combine-css',
			'dashicons',
			'admin-bar',
			'wp-block-library',
			'wc-block-style',
		);

		/**
		 * Maximum recursion depth for @import resolution.
		 *
		 * @var int
		 * @since 2.0.0
		 */
		private const MAX_IMPORT_DEPTH = 3;

		/**
		 * Default cap for inlined critical CSS in bytes (20 KB).
		 *
		 * Stays under core's `styles_inline_size_limit` on every supported
		 * core version (20K pre-6.9 / 40K on 6.9+). Overridable per site via
		 * the `file_optimisation.ccssMaxSize` setting.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private const DEFAULT_CCSS_MAX_SIZE = 20480;

		/**
		 * Default per-run cap for the RUM-weighted CCSS queue (issue #1164).
		 *
		 * Templates are ~5 + custom page templates, so 5 bounds one cron run
		 * while RUM-worst-first ordering ensures the render-blocking bytes go
		 * first. Overridable per site via `file_optimisation.ccssQueueCap`.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const DEFAULT_CCSS_QUEUE_CAP = 5;

		/**
		 * Hard upper bound for the CCSS per-run cap read.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_QUEUE_CAP = 100;

		/**
		 * Default wall-clock budget in seconds for one CCSS generation run.
		 *
		 * Bounds the fetch-plus-parse phase of generate()/generate_and_store()
		 * so a slow or hung source-CSS fetch can never stall a queue worker
		 * indefinitely (issue #1235). Below the 30s page-fetch timeout so the
		 * budget is the binding constraint; above a healthy 3–6 stylesheet
		 * generation (~5–10s). Overridable per site via
		 * `file_optimisation.ccssGenTimeout`.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const DEFAULT_CCSS_GEN_TIMEOUT = 25;

		/**
		 * Hard upper bound in seconds for the CCSS generation budget read.
		 *
		 * A rogue setting can never mean unbounded: oversized values clamp
		 * here, non-numeric/missing values fall back to the default.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_GEN_TIMEOUT = 120;

		/**
		 * Default gzipped inline budget in bytes for critical CSS (14 KB).
		 *
		 * Bounds the transfer weight of inlined critical CSS (issue #1388):
		 * output whose gzipped size exceeds the budget is never inlined —
		 * the page falls back to the deferred full stylesheet plus used CSS
		 * only. Overridable per site via
		 * `file_optimisation.ccssInlineBudgetKb`.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const DEFAULT_CCSS_INLINE_BUDGET_BYTES = 14336;

		/**
		 * Minimum gzipped inline budget in bytes (1 KB, issue #1388).
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const MIN_CCSS_INLINE_BUDGET_BYTES = 1024;

		/**
		 * Maximum gzipped inline budget in bytes (100 KB, issue #1388).
		 *
		 * A rogue setting can never mean unbounded: oversized values clamp
		 * here, non-numeric/missing values fall back to the default.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const MAX_CCSS_INLINE_BUDGET_BYTES = 102400;

		/**
		 * Hard cap in bytes for the concatenated source CSS scanned in one
		 * generation run (issue #1235 review).
		 *
		 * Bounds the fetch-plus-@import expansion so a 2-10MB theme
		 * stylesheet cannot OOM the worker before the deadline polls run.
		 * Per-stylesheet appends stop once the buffer exceeds this size.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_SOURCE_BYTES = 2097152;

		/**
		 * Hard cap on total stylesheet fetches per top-level generation run
		 * (issue #1235 review).
		 *
		 * Bounds @import fan-out (depth 3 x breadth 10 could otherwise reach
		 * ~1111 sequential fetches) so fast origins cannot burn hundreds of
		 * requests inside one budget.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_FETCHES = 30;

		/**
		 * Per-file cap in bytes applied before regex/recursion (issue #1235).
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_FILE_BYTES = 524288;

		/**
		 * Maximum consecutive timeout retries before a template escalates
		 * to `failed` (issue #1235 review).
		 *
		 * Prevents a permanently-slow origin from burning a full
		 * 25-120s synchronous worker on every cycle forever.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_TIMEOUT_ATTEMPTS = 5;

		/**
		 * Default builder-template post types excluded from CSS generation.
		 *
		 * Builder library templates are never rendered as standalone pages,
		 * so fetching them for critical/used CSS only burns queries + CPU
		 * (issue #1274). Skipped with a `skipped` status, never error-looped.
		 *
		 * @since 2.2.0
		 * @var string[]
		 */
		private const DEFAULT_EXCLUDED_POST_TYPES = array( 'fl-builder-template', 'elementor_library' );

		/**
		 * Default bounded-retry cap for generic generation failures.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const DEFAULT_CCSS_MAX_RETRIES = 5;

		/**
		 * Hard upper bound (and clamp range 0..5) for the bounded-retry cap.
		 *
		 * Dedicated to the `ccssMaxRetries` setting / `wppo_ccss_max_retries`
		 * filter (issue #1274 review) so changing timeout escalation
		 * (MAX_CCSS_TIMEOUT_ATTEMPTS) can never silently change the generic
		 * retry cap.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_CCSS_MAX_RETRIES = 5;

		/**
		 * Dedicated Action Scheduler group for CCSS jobs (issue #1235 review).
		 *
		 * Keeps 25-120s generation runs off the shared
		 * `performance_optimisation` worker so a 5-template burst cannot
		 * starve image/PageSpeed/used-CSS jobs; the 60s stagger in
		 * regenerate_all() spaces start times on top of the separation.
		 *
		 * @since 2.2.0
		 * @var string
		 */
		private const CCSS_AS_GROUP = 'wppo-ccss';

		/**
		 * Option holding the last full-regeneration timestamp (issue #1462).
		 *
		 * Gives regenerate_all() the same 18000s full-regen cooldown the
		 * used-CSS pipeline already has, so builder/theme updates and repeat
		 * triggers cannot re-queue the whole template set on every call.
		 * Per-site option (core get_option is multisite-safe).
		 *
		 * @since 2.3.0
		 * @var string
		 */
		public const LAST_FULL_REGEN_OPTION = 'wppo_ccss_last_full_regen';

		/**
		 * Default full-regeneration cooldown in seconds (issue #1462).
		 *
		 * Matches the used-CSS FULL_REGEN_COOLDOWN_SECONDS so both pipelines
		 * share one cadence; filterable via wppo_ccss_regen_cooldown.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const FULL_REGEN_COOLDOWN_SECONDS = 18000;

		/**
		 * Default cap for targeted CCSS regens (issue #1462).
		 *
		 * Builder/theme updates requeue at most this many templates so one
		 * update cannot flood the scheduler; mirrors the used-CSS
		 * request_targeted_regen() default of 20.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const TARGETED_REGEN_CAP = 20;

		/**
		 * Option holding the last targeted-regeneration timestamp (issue #1462).
		 *
		 * Gives request_targeted_regen() the same burst throttle the used-CSS
		 * pipeline already has (Used_CSS::TARGETED_REGEN_OPTION), so a burst
		 * of builder/theme saves collapses into one bounded pass instead of
		 * stacking up to 20 jobs per save. Per-site option (core get_option
		 * is multisite-safe). Deleted on uninstall.
		 *
		 * @since 2.3.0
		 * @var string
		 */
		public const TARGETED_REGEN_OPTION = 'wppo_ccss_last_targeted_regen';

		/**
		 * Targeted-regeneration burst-throttle window in seconds (issue #1462).
		 *
		 * Matches the used-CSS TARGETED_REGEN_COOLDOWN_SECONDS so both
		 * pipelines share one cadence; filterable via
		 * wppo_ccss_targeted_cooldown.
		 *
		 * @since 2.3.0
		 * @var int
		 */
		private const TARGETED_REGEN_COOLDOWN_SECONDS = 3600;

		/**
		 * Cached microtime() availability probe for the hot deadline path.
		 *
		 * @since 2.2.0
		 * @var bool|null
		 */
		private static ?bool $has_microtime = null;

		/**
		 * Request-lifetime memo of the parsed exclusion list (issue #1274 review).
		 *
		 * The getter runs once per queued job in bulk regens; the setting
		 * read itself is memoized in Util, so only the preg_split +
		 * per-slug parsing is memoized here. The memo is used only when no `wppo_ccss_excluded_post_types` listener is
		 * registered — filtered results are always recomputed so a filter
		 * added mid-request can never serve a stale list. Reset via
		 * reset_excluded_post_types_memo() (unit tests).
		 *
		 * @since 2.2.0
		 * @var string[]|null
		 */
		private static ?array $excluded_memo = null;

		/**
		 * Per-request memo of pending/running CCSS job probes (issue #1310 review).
		 *
		 * The probe fans out to up to 4 store queries per call
		 * and runs on the inline_ccss() frontend hot path plus per-template
		 * loops; the miss case always pays 4 SELECTs before 1 INSERT. Memo
		 * keyed by hook+args absorbs repeats within one request; the
		 * status-cache pending/queued gate already absorbs most repeats.
		 *
		 * @since 2.2.0
		 * @var array<string, bool>
		 */
		private static array $pending_memo = array();

		/**
		 * Per-request memo of gzipped transfer sizes keyed by md5(css).
		 *
		 * Level-9 gzencode() is the most expensive compression level; the
		 * budget guard may probe the same payload twice in one request
		 * (inline + budget helpers), so memoize per content hash. Bounded
		 * (20 entries, FIFO evict) so unbounded CSS cannot grow memory.
		 * Reset via reset_ccss_memo().
		 *
		 * @since 2.3.0
		 * @var array<string, int>
		 */
		private static array $gzip_size_memo = array();

		/**
		 * Per-request memo of the commerce-context verdict (issue #1388 review).
		 *
		 * The is_commerce_context() check fans out to conditional tags + option reads;
		 * defer_stylesheets() called it once per stylesheet tag. Null means
		 * uncomputed. Reset via reset_ccss_memo() (tests reset between cases).
		 *
		 * @since 2.3.0
		 * @var bool|null
		 */
		private static ?bool $commerce_context_memo = null;

		/**
		 * Per-request memo of the combined commerce-exclusion verdict.
		 *
		 * Shared by inline_ccss() and defer_stylesheets() so one request pays
		 * one settings + context evaluation. Null means uncomputed. Reset via
		 * reset_ccss_memo().
		 *
		 * @since 2.3.0
		 * @var bool|null
		 */
		private static ?bool $commerce_excluded_context_memo = null;

		/**
		 * Viewport-split variant slugs (issue #1164).
		 *
		 * BC alias: the canonical value lives on
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::VIEWPORT_VARIANTS};
		 * generation code reads the store constant directly. Pinned equal
		 * by CcssStoreParityTest so the alias can never drift.
		 *
		 * Stored as `{hash}.{variant}.css` next to the single `{hash}.css`
		 * variant. Missing or stale variant files fall back to the single
		 * CCSS (or the deferred full stylesheet) — never fatal.
		 *
		 * @since 2.2.0
		 * @var string[]
		 */
		public const VIEWPORT_VARIANTS = array( 'mobile', 'desktop' );

		/**
		 * Minimum CCSS payload worth inlining.
		 *
		 * Shorter output is treated as a failed extraction: the async loader
		 * stub is printed and background regeneration is queued instead.
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private const MIN_INLINE_SIZE = 500;

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_dir}.
		 *
		 * @since NEXT
		 * @return string Result (see Ccss_Store).
		 */
		private static function get_ccss_dir(): string {
			return Ccss_Store::get_ccss_dir();
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_url}.
		 *
		 * @since NEXT
		 * @return string Result (see Ccss_Store).
		 */
		private static function get_ccss_url(): string {
			return Ccss_Store::get_ccss_url();
		}

		/**
		 * Generate a unique template hash for a template slug + stylesheet.
		 *
		 * @param string $template Optional template slug. Defaults to current template via get_current_template_slug().
		 * @return string MD5 hash.
		 * @since 2.0.0
		 */
		public static function get_template_hash( string $template = '' ): string {
			if ( empty( $template ) ) {
				$template = self::get_current_template_slug();
			}
			return md5( get_current_blog_id() . '-' . $template . '-' . get_stylesheet() );
		}

		/**
		 * Get the current WordPress template slug based on conditional tags.
		 *
		 * Maps WordPress conditional tags to the template slugs used in get_templates().
		 *
		 * @return string Template slug: 'home', 'single', 'page', 'archive', or 'index'.
		 * @since 2.0.0
		 */
		private static function get_current_template_slug(): string {
			if ( is_front_page() || is_home() ) {
				return 'home';
			}
			if ( is_singular( 'post' ) ) {
				return 'single';
			}
			if ( is_page() ) {
				return 'page';
			}
			if ( is_archive() || is_search() || is_404() ) {
				return 'archive';
			}
			return 'index';
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_file}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return string Result (see Ccss_Store).
		 */
		private static function get_ccss_file( string $template_hash ): string {
			return Ccss_Store::get_ccss_file( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::is_ccss_path_contained}.
		 *
		 * @since NEXT
		 * @param string $path Parameter (see Ccss_Store).
		 * @return bool Result (see Ccss_Store).
		 */
		private static function is_ccss_path_contained( string $path ): bool {
			return Ccss_Store::is_ccss_path_contained( $path );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::stage_ccss_for_template}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @param string $css Parameter (see Ccss_Store).
		 * @return array Result (see Ccss_Store).
		 */
		public static function stage_ccss_for_template( string $template_hash, string $css ): array {
			return Ccss_Store::stage_ccss_for_template( $template_hash, $css );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::promote_staged_ccss}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return bool Result (see Ccss_Store).
		 */
		public static function promote_staged_ccss( string $template_hash ): bool {
			return Ccss_Store::promote_staged_ccss( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::rollback_ccss_to_fallback}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @param string $reason Parameter (see Ccss_Store).
		 * @return bool Result (see Ccss_Store).
		 */
		public static function rollback_ccss_to_fallback( string $template_hash, string $reason = 'manual' ): bool {
			return Ccss_Store::rollback_ccss_to_fallback( $template_hash, $reason );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_rollout_status}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return array Result (see Ccss_Store).
		 */
		public static function get_ccss_rollout_status( string $template_hash ): array {
			return Ccss_Store::get_ccss_rollout_status( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::verify_ccss_health}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return array Result (see Ccss_Store).
		 */
		public static function verify_ccss_health( string $template_hash ): array {
			return Ccss_Store::verify_ccss_health( $template_hash );
		}

		/**
		 * Dry-run critical-CSS generation with staged preview (issue #1348).
		 *
		 * Runs the guarded generation pipeline with `$stage_only` so the
		 * output lands on the staged sibling instead of going live, then
		 * reports preview metadata. Timeouts report `timed-out` (the
		 * timed-out run leaves live output untouched per the guard
		 * contract). Never throws.
		 *
		 * @param string $template Template identifier.
		 * @return array{staged: bool, reason: string, bytes: int, checksum: string, live_bytes: int, live_checksum: string, changed: bool, template: string} Preview metadata.
		 * @since 2.3.0
		 */
		public static function generate_preview_for_template( string $template ): array {
			$refused = static function ( string $reason ) use ( $template ): array {
				return array(
					'staged'        => false,
					'reason'        => $reason,
					'bytes'         => 0,
					'checksum'      => '',
					'live_bytes'    => 0,
					'live_checksum' => '',
					'changed'       => false,
					'template'      => (string) $template,
				);
			};
			try {
				$templates = self::get_templates();
				if ( ! is_string( $template ) || '' === $template || ! array_key_exists( $template, $templates ) ) {
					return $refused( 'template' );
				}
				if ( self::is_deferral_suspended_by_js() ) {
					return $refused( 'suspended' );
				}
				$template_hash = self::get_template_hash( $template );
				$timed_out     = null;
				$ok            = self::generate_guarded( $template_hash, $template, $timed_out, true );
				if ( ! $ok ) {
					return $refused( true === $timed_out ? 'timed-out' : 'generate' );
				}
				$slot = self::get_ccss_rollout_status( $template_hash );
				return array(
					'staged'        => (bool) $slot['staged'],
					'reason'        => (bool) $slot['staged'] ? '' : 'stage',
					'bytes'         => (int) $slot['staged_bytes'],
					'checksum'      => (string) $slot['staged_checksum'],
					'live_bytes'    => (int) $slot['live_bytes'],
					'live_checksum' => (string) $slot['live_checksum'],
					'changed'       => (bool) $slot['staged_changed'],
					'template'      => (string) $template,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $refused( 'error' );
			}
		}

		/**
		 * Read the configured CCSS inline size cap in bytes.
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssMaxSize`). Missing or non-positive values
		 * fall back to DEFAULT_CCSS_MAX_SIZE so inline output is always
		 * bounded.
		 *
		 * @return int Cap in bytes.
		 * @since 2.0.0
		 */
		public static function get_ccss_max_size(): int {
			$options = Util::get_settings();
			$raw     = $options['file_optimisation']['ccssMaxSize'] ?? self::DEFAULT_CCSS_MAX_SIZE;
			$cap     = function_exists( 'absint' ) ? absint( $raw ) : abs( (int) $raw );
			return $cap > 0 ? (int) $cap : self::DEFAULT_CCSS_MAX_SIZE;
		}

		/**
		 * Truncate CSS to the cap without breaking a rule.
		 *
		 * Thin wrapper over Util::split_css_for_inline_budget(): the cut
		 * lands on the last top-level closing brace at or under the cap so
		 * output never ends mid-rule or leaves an `@media`/`@supports`/`@layer`
		 * wrapper unclosed (brace depth tracked, braces inside quoted strings
		 * and CSS comments skipped). Returns an empty string when no complete
		 * top-level rule fits — callers treat that as over-cap and serve the
		 * file variant instead. The single scanner lives in
		 * Util::find_top_level_css_cut() so the two call sites cannot drift.
		 *
		 * @param string $css CSS content.
		 * @param int    $cap Maximum bytes.
		 * @return string Truncated CSS, or '' when nothing fits.
		 * @since 2.0.0
		 */
		public static function truncate_to_cap( string $css, int $cap ): string {
			if ( '' === $css || $cap <= 0 ) {
				return strlen( $css ) <= $cap ? $css : '';
			}
			if ( strlen( $css ) <= $cap ) {
				return $css;
			}
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'split_css_for_inline_budget' ) ) {
					$split = Util::split_css_for_inline_budget( $css, $cap );
					return isset( $split['inline'] ) ? (string) $split['inline'] : '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$cut = strrpos( substr( $css, 0, $cap ), '}' );
			if ( false === $cut ) {
				return '';
			}
			return substr( $css, 0, (int) $cut + 1 );
		}

		/**
		 * Effective full-regen cooldown in seconds (issue #1462).
		 *
		 * Filterable via `wppo_ccss_regen_cooldown` when a listener is
		 * registered (repo convention: has_filter() before apply_filters());
		 * invalid filter output is ignored and non-positive values fall back
		 * to FULL_REGEN_COOLDOWN_SECONDS so a rogue filter can never disable
		 * the cooldown. Core's `styles_inline_size_limit` filter is untouched
		 * here — it always runs through Util::get_styles_inline_limit().
		 *
		 * @return int Cooldown in seconds.
		 * @since 2.3.0
		 */
		public static function get_full_regen_cooldown(): int {
			try {
				$cooldown = self::FULL_REGEN_COOLDOWN_SECONDS;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_regen_cooldown' ) ) {
					$filtered = apply_filters( 'wppo_ccss_regen_cooldown', $cooldown );
					if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
						$cooldown = (int) $filtered;
					}
				}
				return $cooldown > 0 ? (int) $cooldown : self::FULL_REGEN_COOLDOWN_SECONDS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::FULL_REGEN_COOLDOWN_SECONDS;
			}
		}

		/**
		 * Read the configured gzipped inline budget in bytes (issue #1388).
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssInlineBudgetKb`, default 14 KB). Missing
		 * or out-of-range values fail open to
		 * DEFAULT_CCSS_INLINE_BUDGET_BYTES so inline output is always
		 * bounded. Filterable via `wppo_ccss_inline_budget` when a listener
		 * is registered; invalid filter output is ignored and oversized
		 * values clamp, so a rogue filter can never uncap inline weight.
		 *
		 * @return int Budget in bytes, clamped to MIN..MAX_CCSS_INLINE_BUDGET_BYTES.
		 * @since 2.3.0
		 * @see Critical_CSS::get_ccss_max_size()
		 */
		public static function get_ccss_inline_budget_bytes(): int {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssInlineBudgetKb'] ?? 14;
				$kb      = function_exists( 'absint' ) ? absint( $raw ) : abs( (int) $raw );
				if ( $kb < 1 || $kb > 100 ) {
					$kb = 14;
				}
				$budget = $kb * 1024;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_inline_budget' ) ) {
					$filtered = apply_filters( 'wppo_ccss_inline_budget', $budget );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$budget = (int) $filtered;
					}
				}
				return min( max( $budget, self::MIN_CCSS_INLINE_BUDGET_BYTES ), self::MAX_CCSS_INLINE_BUDGET_BYTES );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::DEFAULT_CCSS_INLINE_BUDGET_BYTES;
			}
		}

		/**
		 * Whether a non-forced full regeneration is inside the cooldown window (issue #1462).
		 *
		 * Mirrors Used_CSS::is_full_regen_cooled_down(). Fail-open: an
		 * unreadable timestamp never blocks work.
		 *
		 * @return bool True when the last full regen is newer than the cooldown.
		 * @since 2.3.0
		 */
		public static function is_full_regen_cooled_down(): bool {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$last = (int) get_option( self::LAST_FULL_REGEN_OPTION, 0 );
				if ( $last <= 0 ) {
					return false;
				}
				return ( time() - $last ) < self::get_full_regen_cooldown();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a completed full-regeneration scan (issue #1462).
		 *
		 * Written whenever regenerate_all() performs a scan so repeat callers
		 * hit the cooldown instead of re-scanning. The stamp is written
		 * unconditionally — even when zero jobs were queued (empty template
		 * set, all skipped, scheduler unavailable) — matching the Used_CSS
		 * pipeline's documented choice: a scan happened, so the next caller
		 * waits out the window instead of re-scanning in a tight loop.
		 * Fail-open.
		 *
		 * @return void
		 * @since 2.3.0
		 */
		public static function mark_full_regen(): void {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::LAST_FULL_REGEN_OPTION, time(), false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Effective targeted-regeneration cooldown in seconds (issue #1462).
		 *
		 * Filterable via `wppo_ccss_targeted_cooldown` when a listener is
		 * registered (repo convention: has_filter() before apply_filters());
		 * invalid filter output is ignored and negative values heal to the
		 * default so a rogue filter can never corrupt the throttle. A zero
		 * value is honoured (throttle disabled) — unlike the full-regen
		 * cooldown, which must never be disabled.
		 *
		 * @return int Cooldown in seconds (>= 0).
		 * @since 2.3.0
		 */
		public static function get_targeted_regen_cooldown(): int {
			try {
				$cooldown = self::TARGETED_REGEN_COOLDOWN_SECONDS;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_targeted_cooldown' ) ) {
					/**
					 * Filter the critical-CSS targeted-regeneration cooldown (issue #1462).
					 *
					 * Bounds how often builder/theme updates may queue bounded
					 * targeted requeues. Return 0 to disable the throttle.
					 *
					 * @since 2.3.0
					 *
					 * @param int $cooldown Cooldown in seconds. Default 3600.
					 */
					$filtered = apply_filters( 'wppo_ccss_targeted_cooldown', $cooldown );
					if ( is_numeric( $filtered ) && (int) $filtered >= 0 ) {
						$cooldown = (int) $filtered;
					}
				}
				return $cooldown >= 0 ? (int) $cooldown : self::TARGETED_REGEN_COOLDOWN_SECONDS;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::TARGETED_REGEN_COOLDOWN_SECONDS;
			}
		}

		/**
		 * Whether a targeted regeneration is inside the throttle window (issue #1462).
		 *
		 * Mirrors Used_CSS::is_targeted_regen_cooled_down(). Fail-open: an
		 * unreadable timestamp never blocks work.
		 *
		 * @return bool True when the last targeted regen is newer than the cooldown.
		 * @since 2.3.0
		 */
		public static function is_targeted_regen_cooled_down(): bool {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$last = (int) get_option( self::TARGETED_REGEN_OPTION, 0 );
				if ( $last <= 0 ) {
					return false;
				}
				return ( time() - $last ) < self::get_targeted_regen_cooldown();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a targeted-regeneration pass (issue #1462).
		 *
		 * Written only when request_targeted_regen() queued at least one job,
		 * so an empty pass (all skipped, scheduler unavailable) never starts
		 * the throttle window. Fail-open.
		 *
		 * @return void
		 * @since 2.3.0
		 */
		public static function mark_targeted_regen(): void {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::TARGETED_REGEN_OPTION, time(), false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Bytes already committed to inline output on this request (issue #1462).
		 *
		 * Coordination point between the per-URL used-CSS pipeline and the
		 * per-template critical-CSS pipeline: critical CSS gets the remainder
		 * of the core inline budget after prior commits. Sources, in priority
		 * order: the request-global Util ledger (inline_ccss() records every
		 * block it inlines; future inline emitters record via
		 * Util::add_committed_inline_bytes()), then the
		 * `wppo_committed_inline_bytes` filter when a listener is registered
		 * (wins over the ledger so operators can account for bytes committed
		 * outside the plugin). Defaults to 0 — used CSS ships as an external
		 * file in every delivery mode (file/async/delay/remove, never inline),
		 * so nothing is committed in the default configuration.
		 *
		 * @return int Committed inline bytes (>= 0).
		 * @since 2.3.0
		 */
		public static function estimate_committed_inline_bytes(): int {
			try {
				$committed = 0;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_committed_inline_bytes' ) ) {
					try {
						$committed = max( 0, (int) Util::get_committed_inline_bytes() );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_committed_inline_bytes' ) ) {
					$filtered = apply_filters( 'wppo_committed_inline_bytes', $committed );
					if ( is_numeric( $filtered ) && (int) $filtered > 0 ) {
						$committed = (int) $filtered;
					}
				}
				return $committed > 0 ? (int) $committed : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Effective inline budget for critical CSS after prior commits (issue #1462).
		 *
		 * The combined inlined CSS (used CSS already committed + critical CSS)
		 * stays within core's `styles_inline_size_limit` (40KB on WP 6.9+,
		 * 20KB before) and the configured `ccssMaxSize` cap: the tighter of
		 * the two wins, minus already-committed bytes, clamped at zero.
		 * A non-positive core limit (rogue `styles_inline_size_limit` filter)
		 * heals to the unfiltered default via Util::get_styles_inline_default()
		 * so this path agrees with split_css_for_inline_budget() and
		 * get_remaining_inline_budget(). Fail-open: any uncertainty yields 0
		 * (caller serves the file variant), never fatal.
		 *
		 * @param int $already_inlined Bytes already committed to inline output.
		 * @return int Effective budget in bytes (>= 0).
		 * @since 2.3.0
		 */
		public static function get_effective_ccss_budget( int $already_inlined = 0 ): int {
			try {
				$cap   = self::get_ccss_max_size();
				$limit = self::get_styles_inline_limit();
				if ( $limit <= 0 && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_styles_inline_default' ) ) {
					$limit = Util::get_styles_inline_default();
				}
				if ( $limit <= 0 ) {
					$limit = 40000;
				}
				$budget = min( $cap, $limit ) - max( 0, $already_inlined );
				return $budget > 0 ? (int) $budget : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Coordinate used CSS and critical CSS within one inline budget (issue #1462).
		 *
		 * Used CSS (per-URL) is committed first; critical CSS (per-template)
		 * is split so the combined inline output fits core's
		 * `styles_inline_size_limit`. Called live by inline_ccss(): when the
		 * split reports a deferred remainder, inline_ccss() serves the whole
		 * per-template file variant (the cacheable repeat-visit asset) instead
		 * of inlining a truncated prefix — so the `deferred` substring is a
		 * fit signal for the caller, not a second asset that is enqueued.
		 * Fail-open: any uncertainty returns the inputs unmodified for the
		 * caller to handle with its file fallback.
		 *
		 * @param string     $ccss Critical CSS content for the current template.
		 * @param string|int $used_css Used-CSS content already committed inline ('' when file-delivered),
		 *                             or a byte count to avoid materialising a stub string.
		 * @return array{inline: string, deferred: string} Budget-aware CCSS split.
		 * @since 2.3.0
		 */
		public static function coordinate_inline_budgets( string $ccss, string|int $used_css = '' ): array {
			try {
				if ( '' === $ccss ) {
					return array(
						'inline'   => '',
						'deferred' => '',
					);
				}
				$used_len = is_int( $used_css ) ? max( 0, $used_css ) : strlen( $used_css );
				$budget   = self::get_effective_ccss_budget( $used_len );
				if ( strlen( $ccss ) <= $budget ) {
					return array(
						'inline'   => $ccss,
						'deferred' => '',
					);
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'split_css_for_inline_budget' ) ) {
					return Util::split_css_for_inline_budget( $ccss, $budget );
				}
				$truncated = self::truncate_to_cap( $ccss, $budget );
				if ( '' === $truncated ) {
					return array(
						'inline'   => '',
						'deferred' => $ccss,
					);
				}
				return array(
					'inline'   => $truncated,
					'deferred' => substr( $ccss, strlen( $truncated ) ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'inline'   => $ccss,
					'deferred' => '',
				);
			}
		}

		/**
		 * Queue a bounded targeted regen after builder/theme updates (issue #1462).
		 *
		 * Counterpart to Used_CSS::request_targeted_regen(): requeues at most
		 * $cap templates (RUM-worst-first) instead of the full set, so a burst
		 * of builder updates cannot flood the scheduler. A 3600s burst
		 * throttle (TARGETED_REGEN_OPTION, shared cadence with the used-CSS
		 * pipeline) collapses repeat saves into one pass — the throttle stamp
		 * is written only when at least one job was queued, so empty passes
		 * never block legitimate retries. Templates with no renderable sample
		 * URL are marked `skipped`, never queued. Fail-open: any uncertainty
		 * returns 0, never fatal.
		 *
		 * Multisite-safe: per-site options only.
		 *
		 * @param string $reason Short reason for logging (e.g. 'builder-update').
		 * @param int    $cap Maximum templates to requeue in this pass (0 = empty pass, no work; negatives clamp to 0).
		 * @return int Number of jobs queued.
		 * @since 2.3.0
		 */
		public static function request_targeted_regen( string $reason = '', int $cap = 20 ): int {
			try {
				if ( self::is_deferral_suspended_by_js() ) {
					return 0;
				}
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return 0;
				}
				if ( self::is_targeted_regen_cooled_down() ) {
					return 0;
				}
				$cap = max( 0, min( $cap, 100 ) );
				if ( 0 === $cap ) {
					return 0;
				}
				$templates = self::get_templates();
				if ( empty( $templates ) ) {
					return 0;
				}
				$templates = self::order_templates_by_rum_priority( $templates );
				if ( count( $templates ) > $cap ) {
					$templates = array_slice( $templates, 0, $cap, true );
				}
				$queued = 0;
				foreach ( $templates as $template => $label ) {
					if ( $queued >= $cap ) {
						break;
					}
					try {
						if ( ! self::get_sample_url( (string) $template ) ) {
							$hash = self::get_template_hash( (string) $template );
							self::set_status_cache( $hash, 'skipped', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
							self::clear_ccss_retry_state( $hash );
							continue;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					$hash      = self::get_template_hash( (string) $template );
					$hook      = 'wppo_generate_ccss';
					$hook_args = array( array( 'template_hash' => $hash ) );
					$job       = self::schedule_ccss_job( $hook, $hook_args, time() + ( $queued * 60 ) );
					if ( $job['id'] > 0 ) {
						++$queued;
					}
					if ( $job['pending'] ) {
						self::set_status_cache( $hash, 'queued', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
					}
				}
				if ( $queued > 0 ) {
					self::mark_targeted_regen();
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
						try {
							Log::add(
								sprintf(
								/* translators: 1: number of jobs, 2: reason */
									__( 'Targeted critical-CSS regen queued %1$d jobs (%2$s).', 'performance-optimisation' ),
									$queued,
									'' !== $reason ? $reason : 'update'
								)
							);
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
				return $queued;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Measure the gzipped transfer size of CSS output (issue #1388).
		 *
		 * Uses gzencode() level 9 when available so the budget check tracks
		 * what the browser actually downloads; falls back to raw strlen()
		 * when zlib is unavailable (fail-open, never fatal). Results are
		 * memoized per content hash per request (bounded, 20 entries) so
		 * repeated budget probes on the frontend hot path pay compression once.
		 *
		 * @param string $css CSS content.
		 * @return int Gzipped size in bytes, or raw size without zlib.
		 * @since 2.3.0
		 */
		public static function gzipped_size( string $css ): int {
			if ( '' === $css ) {
				return 0;
			}
			try {
				$memo_key = md5( $css );
			} catch ( \Throwable $e ) {
				unset( $e );
				$memo_key = '';
			}
			if ( '' !== $memo_key && array_key_exists( $memo_key, self::$gzip_size_memo ) ) {
				return self::$gzip_size_memo[ $memo_key ];
			}
			$size = strlen( $css );
			try {
				if ( function_exists( 'gzencode' ) ) {
					$encoded = gzencode( $css, 9 );
					if ( is_string( $encoded ) && '' !== $encoded ) {
						$size = strlen( $encoded );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( '' !== $memo_key ) {
				if ( count( self::$gzip_size_memo ) >= 20 ) {
					array_shift( self::$gzip_size_memo );
				}
				self::$gzip_size_memo[ $memo_key ] = $size;
			}
			return $size;
		}

		/**
		 * Whether CSS output exceeds the gzipped inline budget (issue #1388).
		 *
		 * Fail-open: any failure reports over-budget (never inline unbounded
		 * output), except empty input which is never over budget.
		 *
		 * @param string $css CSS content.
		 * @return bool True when the gzipped size exceeds the inline budget.
		 * @since 2.3.0
		 */
		public static function is_over_inline_budget( string $css ): bool {
			if ( '' === $css ) {
				return false;
			}
			try {
				return self::gzipped_size( $css ) > self::get_ccss_inline_budget_bytes();
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether cart/checkout inline critical CSS is excluded (issue #1388).
		 *
		 * Reads `file_optimisation.ccssCommerceExclude` (default true).
		 * Fail-open to excluded when the setting cannot be read, so dynamic
		 * commerce pages never risk stale inlined CSS.
		 *
		 * @return bool True when commerce exclusion is active.
		 * @since 2.3.0
		 */
		public static function is_commerce_excluded(): bool {
			try {
				$options = Util::get_settings();
				if ( ! isset( $options['file_optimisation']['ccssCommerceExclude'] ) ) {
					return true;
				}
				return ! empty( $options['file_optimisation']['ccssCommerceExclude'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether the current request is a cart/checkout context (issue #1388).
		 *
		 * Guards every WooCommerce conditional with function_exists() plus a
		 * legacy fallback (cart/checkout page-ID options) so the exclusion
		 * holds even when conditional tags are unavailable. A cart-session
		 * cookie alone deliberately never marks the request as commerce:
		 * cookies are present on every page (home, blog, product) for any
		 * shopper with items in the cart, so consulting them would disable
		 * critical CSS site-wide for exactly the users being optimized. Only
		 * cart/checkout pages are commerce contexts. Result is memoized per
		 * request (reset via reset_ccss_memo()). Must only be called after
		 * the main query is set up (after parse_query): an early call before
		 * conditional tags resolve would memoize a premature "not commerce"
		 * verdict for the later inline_ccss()/defer_stylesheets() consumers
		 * (which run post-query at wp_head/style_loader_tag). Fail-open to false: any
		 * failure reports "not commerce" and the caller keeps current
		 * behaviour.
		 *
		 * @return bool True on cart/checkout pages.
		 * @since 2.3.0
		 */
		public static function is_commerce_context(): bool {
			if ( null !== self::$commerce_context_memo ) {
				return self::$commerce_context_memo;
			}
			$result = false;
			try {
				if ( function_exists( 'is_cart' ) ) {
					try {
						if ( is_cart() ) {
							$result = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( ! $result && function_exists( 'is_checkout' ) ) {
					try {
						if ( is_checkout() ) {
							$result = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Legacy fallback: WooCommerce cart/checkout page IDs work
				// even when conditional tags are not loaded yet.
				if ( ! $result && function_exists( 'get_option' ) ) {
					try {
						$cart_id     = (int) get_option( 'woocommerce_cart_page_id', 0 );
						$checkout_id = (int) get_option( 'woocommerce_checkout_page_id', 0 );
						if ( ( $cart_id > 0 || $checkout_id > 0 ) ) {
							$current = 0;
							if ( function_exists( 'get_queried_object_id' ) ) {
								try {
									$current = (int) get_queried_object_id();
								} catch ( \Throwable $e ) {
									unset( $e );
									$current = 0;
								}
							}
							if ( 0 === $current && function_exists( 'get_the_ID' ) ) {
								try {
									$current = (int) get_the_ID();
								} catch ( \Throwable $e ) {
									unset( $e );
									$current = 0;
								}
							}
							if ( $current > 0 && ( $current === $cart_id || $current === $checkout_id ) ) {
								$result = true;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$result = false;
			}
			self::$commerce_context_memo = $result;
			return $result;
		}

		/**
		 * Whether commerce exclusion applies to the current request (issue #1388 review).
		 *
		 * Memoized union of is_commerce_excluded() + is_commerce_context()
		 * shared by inline_ccss() and defer_stylesheets() so one request pays
		 * one settings + context evaluation instead of one per stylesheet tag.
		 * Reset via reset_ccss_memo(). Fail-open to false.
		 *
		 * @return bool True when inline/deferral must yield to normal stylesheets.
		 * @since 2.3.0
		 */
		private static function is_commerce_excluded_context(): bool {
			if ( null !== self::$commerce_excluded_context_memo ) {
				return self::$commerce_excluded_context_memo;
			}
			try {
				$result = self::is_commerce_excluded() && self::is_commerce_context();
			} catch ( \Throwable $e ) {
				unset( $e );
				$result = false;
			}
			self::$commerce_excluded_context_memo = $result;
			return $result;
		}

		/**
		 * Human-readable label for the configured gzipped inline budget (issue #1388 review).
		 *
		 * Derived from get_ccss_inline_budget_bytes() so admin notices and
		 * activity-log warnings never hardcode "14 KB" while the budget is
		 * configurable (1–100 KB + wppo_ccss_inline_budget filter).
		 *
		 * @return string e.g. "14 KB" or "2.5 KB".
		 * @since 2.3.0
		 */
		public static function get_ccss_inline_budget_label(): string {
			try {
				$bytes = self::get_ccss_inline_budget_bytes();
				if ( 0 === ( $bytes % 1024 ) ) {
					return sprintf( '%d KB', (int) ( $bytes / 1024 ) );
				}
				$kb = $bytes / 1024;
				return sprintf( '%.1f KB', $kb );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '14 KB';
			}
		}

		/**
		 * Whether checksum-triggered CCSS regen on save is enabled (issue #1388).
		 *
		 * Reads `file_optimisation.ccssChecksumRegen` (default true).
		 * Fail-open to enabled when the setting cannot be read. Set
		 * `file_optimisation.ccssChecksumRegen` to false to opt out: the
		 * frontend stale probe in maybe_check_stale_and_requeue() and the
		 * save_post requeue in maybe_regen_on_save() both stay inert, and
		 * empty-safelist sites keep the pre-#1038 behaviour verbatim (no
		 * per-view checksum file reads for stored variants).
		 *
		 * @return bool True when checksum regen is active.
		 * @since 2.3.0
		 */
		public static function is_checksum_regen_enabled(): bool {
			try {
				$options = Util::get_settings();
				if ( ! isset( $options['file_optimisation']['ccssChecksumRegen'] ) ) {
					return true;
				}
				return ! empty( $options['file_optimisation']['ccssChecksumRegen'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Read the configured per-run CCSS queue cap (issue #1164).
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssQueueCap`, default 5). Fail-open: a missing
		 * key falls back to the default; a non-positive / non-numeric value
		 * means uncapped (current behaviour) so a rogue setting can never
		 * starve the queue. Oversized values are clamped to MAX_CCSS_QUEUE_CAP.
		 * Filterable via `wppo_ccss_queue_cap` when a listener is registered
		 * (issue #1255): invalid filter output is ignored and oversized values
		 * clamp, so a rogue filter can never starve or flood the queue.
		 *
		 * Note: the historic singular-`css` name predates the double-`s`
		 * `get_ccss_queue_cap()` alias; both are kept as-is.
		 *
		 * @return int Per-run cap, or PHP_INT_MAX when uncapped.
		 * @since 2.2.0 Filterable via `wppo_ccss_queue_cap`.
		 * @see Critical_CSS::get_ccss_gen_timeout()
		 * @see Critical_CSS::get_ccss_queue_cap()
		 */
		public static function get_css_queue_cap(): int {
			try {
				$options = Util::get_settings();
				if ( ! isset( $options['file_optimisation']['ccssQueueCap'] ) ) {
					$cap = self::DEFAULT_CCSS_QUEUE_CAP;
				} else {
					$raw = $options['file_optimisation']['ccssQueueCap'];
					if ( ! is_numeric( $raw ) || (int) $raw <= 0 ) {
						$cap = PHP_INT_MAX;
					} else {
						$cap = min( (int) $raw, self::MAX_CCSS_QUEUE_CAP );
					}
				}
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_queue_cap' ) ) {
					$filtered = apply_filters( 'wppo_ccss_queue_cap', $cap );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$cap = min( (int) $filtered, self::MAX_CCSS_QUEUE_CAP );
					}
				}
				return $cap;
			} catch ( \Throwable $e ) {
				unset( $e );
				return PHP_INT_MAX;
			}
		}

		/**
		 * Canonical double-`s` spelling of the per-run CCSS queue cap (issue #1235).
		 *
		 * New code should prefer this name; get_css_queue_cap() remains as a
		 * deprecated alias for backward compatibility.
		 *
		 * @return int Per-run cap, or PHP_INT_MAX when uncapped.
		 * @since 2.2.0
		 * @see Critical_CSS::get_css_queue_cap()
		 */
		public static function get_ccss_queue_cap(): int {
			return self::get_css_queue_cap();
		}

		/**
		 * Read the configured wall-clock budget in seconds for one CCSS
		 * generation run (issue #1235).
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssGenTimeout`, default 25). Fail-open: a
		 * missing or non-numeric value falls back to the default (never
		 * uncapped — an unbounded generation is the failure mode this
		 * budget exists to prevent). Oversized values clamp to
		 * MAX_CCSS_GEN_TIMEOUT. Filterable via `wppo_ccss_generation_timeout`
		 * when a listener is registered.
		 *
		 * @return int Budget in seconds, clamped to 1..MAX_CCSS_GEN_TIMEOUT.
		 * @since 2.2.0
		 */
		public static function get_ccss_gen_timeout(): int {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssGenTimeout'] ?? self::DEFAULT_CCSS_GEN_TIMEOUT;
				if ( ! is_numeric( $raw ) ) {
					$timeout = self::DEFAULT_CCSS_GEN_TIMEOUT;
				} else {
					// (int) cast (not absint()): negatives heal to the default
					// below, matching the write-time clamp in class-rest.php
					// so one stored row yields one budget on every path.
					$timeout = (int) $raw;
					if ( $timeout < 1 ) {
						$timeout = self::DEFAULT_CCSS_GEN_TIMEOUT;
					}
				}
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_generation_timeout' ) ) {
					$filtered = apply_filters( 'wppo_ccss_generation_timeout', $timeout );
					if ( is_numeric( $filtered ) && (int) $filtered >= 1 ) {
						$timeout = (int) $filtered;
					}
				}
				return min( max( $timeout, 1 ), self::MAX_CCSS_GEN_TIMEOUT );
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::DEFAULT_CCSS_GEN_TIMEOUT;
			}
		}

		/**
		 * Normalize raw exclusion slugs (setting lines or filter output).
		 *
		 * Single shared parser (issue #1274 review) so Critical_CSS and the
		 * Used_CSS fallback can never drift on delimiter, case, trim, or
		 * charset rules. Accepts a newline/comma-delimited string or an
		 * array of mixed values; non-string items are dropped.
		 *
		 * @param string|string[]|mixed $raw Raw slugs.
		 * @return string[] Validated lowercase slugs.
		 * @since 2.2.0
		 */
		public static function parse_excluded_slugs( $raw ): array {
			$items = array();
			try {
				if ( is_string( $raw ) ) {
					$split = preg_split( '/[\r\n,]+/', $raw );
					$items = is_array( $split ) ? $split : array();
				} elseif ( is_array( $raw ) ) {
					$items = $raw;
				} else {
					return array();
				}
				$parsed = array();
				foreach ( $items as $item ) {
					if ( ! is_string( $item ) ) {
						continue;
					}
					$slug = strtolower( trim( $item ) );
					if ( '' !== $slug && preg_match( '/^[a-z0-9_\-]{1,64}$/', $slug ) ) {
						$parsed[] = $slug;
					}
				}
				return $parsed;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Builder-template post types excluded from CSS generation (issue #1274).
		 *
		 * Single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssExcludedPostTypes`, newline-separated,
		 * default `fl-builder-template` + `elementor_library`). The setting
		 * is ADDITIVE: configured slugs are merged over the built-in
		 * builder defaults (adding one custom type never re-enables the
		 * builder templates), and an empty setting — or one with no valid
		 * slugs — keeps the defaults. Fail-open: any error returns the
		 * built-in defaults so builder templates are still skipped.
		 * Extensible via the `wppo_ccss_excluded_post_types` filter when a
		 * listener is registered (also additive over the defaults; an
		 * empty filter return is ignored, so there is no opt-out).
		 *
		 * Multisite-safe: per-site option reads only.
		 *
		 * @return string[] Excluded post type slugs.
		 * @since 2.2.0
		 */
		public static function get_excluded_post_types(): array {
			try {
				$has_filter = function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_excluded_post_types' );
				if ( ! $has_filter && null !== self::$excluded_memo ) {
					return self::$excluded_memo;
				}
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssExcludedPostTypes'] ?? null;
				// Pass raw through: the shared parser already handles
				// string|array|mixed, so array values from imports or
				// corrupted options are preserved, not discarded.
				$parsed = self::parse_excluded_slugs( $raw );
				// Additive merge: valid configured slugs join the built-in
				// builder defaults; an empty/invalid setting keeps defaults
				// so protection is never silently disabled.
				$excluded = ! empty( $parsed )
					? array_values( array_unique( array_merge( self::DEFAULT_EXCLUDED_POST_TYPES, $parsed ) ) )
					: self::DEFAULT_EXCLUDED_POST_TYPES;
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && $has_filter ) {
					$filtered = apply_filters( 'wppo_ccss_excluded_post_types', $excluded );
					// Accept array or newline/comma-delimited string filter
					// output via the shared parser (issue #1274 review).
					if ( is_array( $filtered ) || is_string( $filtered ) ) {
						$clean = self::parse_excluded_slugs( $filtered );
						// Always additive: an empty filter return is
						// ignored (no opt-out) so builder-template
						// protection cannot be silently disabled.
						if ( ! empty( $clean ) ) {
							$excluded = array_values( array_unique( array_merge( self::DEFAULT_EXCLUDED_POST_TYPES, $clean ) ) );
						}
					}
				}
				if ( ! $has_filter ) {
					self::$excluded_memo = $excluded;
				}
				return $excluded;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::DEFAULT_EXCLUDED_POST_TYPES;
			}
		}

		/**
		 * Reset the request-lifetime exclusion memo (unit tests).
		 *
		 * Production requests never need this (one request = one settings
		 * snapshot); tests that swap settings mid-process must call it
		 * between cases so the second case re-parses.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		public static function reset_excluded_post_types_memo(): void {
			self::$excluded_memo = null;
		}

		/**
		 * Resolve a template slug or hash to its canonical slug (issue #1274).
		 *
		 * Single shared slug-to-hash resolution loop for
		 * is_known_template() and regenerate_single() so the two can never
		 * drift.
		 *
		 * @param string                    $template Template slug or template hash.
		 * @param array<string,string>|null $templates Optional pre-enumerated template map (reuses the caller's scan).
		 * @return string Canonical slug, or '' when unknown.
		 * @since 2.2.0
		 */
		public static function resolve_template_slug( string $template, ?array $templates = null ): string {
			try {
				$template = trim( $template );
				if ( '' === $template ) {
					return '';
				}
				if ( null === $templates ) {
					$templates = self::get_templates();
				}
				if ( array_key_exists( $template, $templates ) ) {
					return $template;
				}
				foreach ( array_keys( $templates ) as $candidate ) {
					if ( self::get_template_hash( (string) $candidate ) === $template ) {
						return (string) $candidate;
					}
				}
				return '';
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether a template slug or hash is a known template (issue #1274 review).
		 *
		 * Lets the REST layer distinguish an unknown `template` param (404)
		 * from a legitimately skipped one (200, nothing queued) instead of
		 * collapsing both into one ambiguous response.
		 *
		 * @param string                    $template Template slug or template hash.
		 * @param array<string,string>|null $templates Optional pre-enumerated template map (reuses the caller's scan).
		 * @return bool True when the slug or hash resolves to a template.
		 * @since 2.2.0
		 */
		public static function is_known_template( string $template, ?array $templates = null ): bool {
			return '' !== self::resolve_template_slug( $template, $templates );
		}

		/**
		 * Whether a post type is excluded from CSS generation (issue #1274).
		 *
		 * @param string $post_type Post type slug.
		 * @return bool True when excluded.
		 * @since 2.2.0
		 */
		public static function is_post_type_excluded( string $post_type ): bool {
			try {
				$slug = strtolower( trim( $post_type ) );
				if ( '' === $slug ) {
					return false;
				}
				return in_array( $slug, self::get_excluded_post_types(), true );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a post ID belongs to an excluded builder-template type (issue #1274).
		 *
		 * Fail-open: unknown posts, missing APIs, or any error returns false
		 * (generate as before) so exclusion can never starve real content.
		 *
		 * @param int $post_id Post ID.
		 * @return bool True when the post should be skipped.
		 * @since 2.2.0
		 */
		public static function is_excluded_post( int $post_id ): bool {
			try {
				if ( $post_id <= 0 || ! function_exists( 'get_post_type' ) ) {
					return false;
				}
				$post_type = get_post_type( $post_id );
				if ( ! is_string( $post_type ) || '' === $post_type ) {
					return false;
				}
				return self::is_post_type_excluded( $post_type );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Bounded-retry cap for generic generation failures (issue #1274).
		 *
		 * Single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.ccssMaxRetries`, default 5, clamped 0..5).
		 * 0 means fail fast with no retries. Filterable via
		 * `wppo_ccss_max_retries` when a listener is registered.
		 *
		 * @return int Retry cap, 0..MAX_CCSS_MAX_RETRIES.
		 * @since 2.2.0
		 */
		public static function get_ccss_max_retries(): int {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssMaxRetries'] ?? self::DEFAULT_CCSS_MAX_RETRIES;
				$cap     = is_numeric( $raw ) ? (int) $raw : self::DEFAULT_CCSS_MAX_RETRIES;
				$cap     = min( self::MAX_CCSS_MAX_RETRIES, max( 0, $cap ) );
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_max_retries' ) ) {
					$filtered = apply_filters( 'wppo_ccss_max_retries', $cap );
					if ( is_numeric( $filtered ) ) {
						$cap = min( self::MAX_CCSS_MAX_RETRIES, max( 0, (int) $filtered ) );
					}
				}
				return $cap;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::DEFAULT_CCSS_MAX_RETRIES;
			}
		}

		/**
		 * Consecutive generic-failure count for a template (bounded retries).
		 *
		 * Stored as a blog-aware transient so multisite sites count
		 * independently. Missing transient API reads as zero (fail-open).
		 *
		 * @param string $template_hash Template hash.
		 * @return int Consecutive generic failure count.
		 * @since 2.2.0
		 */
		private static function get_ccss_generation_attempts( string $template_hash ): int {
			try {
				if ( ! function_exists( 'get_transient' ) || ! self::is_valid_template_hash( $template_hash ) ) {
					return 0;
				}
				$stored = get_transient( Util::transient_key( 'wppo_ccss_attempts_' . $template_hash ) );
				return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Reset the generic-failure count after a successful generation.
		 *
		 * Best-effort only; a missing transient API is a no-op.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since 2.2.0
		 */
		private static function clear_ccss_generation_attempts( string $template_hash ): void {
			try {
				if ( function_exists( 'delete_transient' ) && self::is_valid_template_hash( $template_hash ) ) {
					delete_transient( Util::transient_key( 'wppo_ccss_attempts_' . $template_hash ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Current wall-clock time in seconds with fractions.
		 *
		 * Falls back to second-resolution time() when microtime() is
		 * unavailable so deadline math never fatals on restricted hosts.
		 * Naming note: the generation_* helpers omit the get_/is_ prefix by
		 * design — they form one deadline-clock family with
		 * generation_deadline()/generation_expired()/request_timeout_for_deadline().
		 *
		 * @return float Now.
		 * @since 2.2.0
		 */
		private static function generation_now(): float {
			if ( null === self::$has_microtime ) {
				self::$has_microtime = function_exists( 'microtime' );
			}
			if ( self::$has_microtime ) {
				$now = microtime( true );
				if ( is_float( $now ) || is_int( $now ) ) {
					return (float) $now;
				}
			}
			return (float) time();
		}

		/**
		 * Absolute deadline for a generation budget starting now.
		 *
		 * @param int $budget Budget in seconds.
		 * @return float Unix timestamp (fractions) when the budget expires.
		 * @since 2.2.0
		 */
		private static function generation_deadline( int $budget ): float {
			return self::generation_now() + (float) max( $budget, 1 );
		}

		/**
		 * Whether a generation deadline has expired.
		 *
		 * A null deadline means "no budget" (legacy uncapped path) and never
		 * reports expired so historical direct callers keep working.
		 *
		 * @param float|null $deadline Absolute deadline, or null when uncapped.
		 * @return bool True when the budget is exhausted.
		 * @since 2.2.0
		 */
		private static function generation_expired( ?float $deadline ): bool {
			if ( null === $deadline ) {
				return false;
			}
			return self::generation_now() >= $deadline;
		}

		/**
		 * Per-request HTTP timeout bounded by the remaining budget.
		 *
		 * Lets a hung source-CSS fetch fail fast instead of consuming the
		 * whole generation budget on one socket: the request timeout is the
		 * smaller of the remaining budget and the historical per-request
		 * default. Uses floor (never ceil) so the socket timeout never
		 * exceeds the true budget, and returns 0 when the budget is already
		 * exhausted so callers skip the request instead of issuing a
		 * blocking fetch past expiry.
		 *
		 * @param float|null $deadline Absolute deadline, or null for the default.
		 * @param int        $fallback Historical per-request timeout in seconds.
		 * @return int Timeout in seconds, 0 when exhausted, otherwise 1..$fallback.
		 * @since 2.2.0
		 */
		private static function request_timeout_for_deadline( ?float $deadline, int $fallback ): int {
			if ( null === $deadline ) {
				return $fallback;
			}
			$remaining = (int) floor( $deadline - self::generation_now() );
			if ( $remaining < 1 ) {
				return 0;
			}
			return min( $remaining, $fallback );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::is_valid_template_hash}.
		 *
		 * @since NEXT
		 * @param mixed $hash Parameter (see Ccss_Store).
		 * @return bool Result (see Ccss_Store).
		 */
		private static function is_valid_template_hash( $hash ): bool {
			return Ccss_Store::is_valid_template_hash( $hash );
		}

		/**
		 * Resolve one generation budget + deadline pair (issue #1235 review).
		 *
		 * Single bootstrap so the logged budget can never diverge from the
		 * deadline budget when a filter changes mid-request.
		 *
		 * @param float|null $deadline Optional caller-supplied deadline.
		 * @return array{0:int,1:float|null} Budget and absolute deadline.
		 * @since 2.2.0
		 */
		private static function resolve_generation_budget( ?float $deadline = null ): array {
			$budget = self::DEFAULT_CCSS_GEN_TIMEOUT;
			try {
				$budget = self::get_ccss_gen_timeout();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( null === $deadline ) {
				try {
					$deadline = self::generation_deadline( $budget );
				} catch ( \Throwable $e ) {
					unset( $e );
					$deadline = null;
				}
			} else {
				// A caller-supplied deadline bounds the run, not the stored
				// setting: clamp it to now + MAX so a far-future value can never
				// smuggle an unbounded budget past the 1..120 contract, then
				// derive the logged budget from the (clamped) span with floor so
				// the log never quotes a second that was never enforced — an
				// already-expired deadline logs 0, not 1 (issue #1235 review).
				// Non-finite callers (NAN/INF) clamp to now + MAX so the
				// predicate cannot stay unbounded (NAN never expires).
				try {
					$now = self::generation_now();
					if ( ! is_finite( $deadline ) ) {
						$deadline = $now + (float) self::MAX_CCSS_GEN_TIMEOUT;
					} else {
						$deadline = min( $deadline, $now + (float) self::MAX_CCSS_GEN_TIMEOUT );
					}
					$span   = (int) floor( $deadline - $now );
					$budget = min( max( $span, 0 ), self::MAX_CCSS_GEN_TIMEOUT );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return array( $budget, $deadline );
		}

		/**
		 * Record a generation failure, routing expired budgets to the
		 * timeout path (pending + retry) and all other failures to the
		 * day-long `failed` state (issue #1235 review).
		 *
		 * Single exit funnel so no false return can leave an expired run
		 * parked in `failed` with no retry while the guard reports timed_out.
		 *
		 * @param string     $template_hash Template hash.
		 * @param int        $budget        Budget in seconds that was exhausted.
		 * @param float|null $deadline      Absolute deadline, or null when uncapped.
		 * @return void
		 * @since 2.2.0
		 */
		private static function record_generation_failure( string $template_hash, int $budget, ?float $deadline ): void {
			try {
				if ( self::generation_expired( $deadline ) ) {
					self::handle_ccss_timeout( $template_hash, $budget );
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Bounded generic retries (issue #1274): count consecutive
			// ordinary failures and escalate to terminal `failed` only at
			// the cap; below the cap stay `queued` with a retry scheduled
			// so one bad origin cannot error-loop forever. Escalation uses
			// the COMBINED generic + timeout count so alternating failure
			// modes still terminate; a cap of 0 means fail fast with no
			// retry scheduled.
			$attempts = 0;
			$cap      = self::DEFAULT_CCSS_MAX_RETRIES;
			try {
				$cap      = self::get_ccss_max_retries();
				$attempts = self::get_ccss_generation_attempts( $template_hash ) + 1;
				if ( function_exists( 'set_transient' ) && self::is_valid_template_hash( $template_hash ) ) {
					set_transient( Util::transient_key( 'wppo_ccss_attempts_' . $template_hash ), $attempts, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Lazy second-counter read: when the generic count alone
			// already reaches the cap there is no need to spend a
			// transient read on the timeout counter.
			$total = $attempts;
			if ( $cap > 0 && $total < max( 1, $cap ) ) {
				try {
					$total = $attempts + self::get_ccss_timeout_attempts( $template_hash );
				} catch ( \Throwable $e ) {
					unset( $e );
					$total = $attempts;
				}
			}
			if ( $cap <= 0 || $total >= max( 1, $cap ) ) {
				try {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Log generic escalation like the timeout path so
				// generic-error loops stay visible (issue #1274 review).
				try {
					if ( class_exists( Log::class ) ) {
						Log::add( sprintf( 'Critical CSS escalated to failed for %s after %d failures.', $template_hash, (int) $total ) );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return;
			}
			// Gate the status on the verified schedule outcome (issue #1310
			// review): only assert `queued` when the retry insert returned an
			// ID or a job is verifiably pending; on scheduler failure mark
			// `failed` instead, otherwise inline_ccss() skips re-queueing for
			// the TTL while no backing job will ever run.
			$retry = self::schedule_ccss_retry( $template_hash, $attempts );
			try {
				if ( $retry['pending'] ) {
					self::set_status_cache( $template_hash, 'queued', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				} else {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Schedule a later retry for a timed-out CCSS generation (issue #1235).
		 *
		 * Reuses the `wppo_generate_ccss` hook payload shape used by
		 * inline_ccss()/regenerate_all() so the normal worker path picks the
		 * template up again. Prefers Action Scheduler, falls back to WP-Cron.
		 * Fail-open: scheduling is best-effort — any error or missing
		 * scheduler leaves the pending status cache behind (which the next
		 * cron run re-queues from) instead of fataling the worker.
		 *
		 * Dedup-wins is intentional (issue #1235 review): when a retry is
		 * already scheduled the existing run keeps its original delay and
		 * the new exponential delay is skipped, so a burst of timeouts
		 * cannot stack duplicate jobs — the higher delay applies to the
		 * next timeout after the pending job runs.
		 *
		 * @param string   $template_hash Template hash to retry.
		 * @param int|null $attempts      Optional pre-read attempt count for the backoff (generic-retry path passes its own counter so generic failures back off exponentially too; null reads the timeout counter).
		 * @return array{id:int,pending:bool} `id` is the new action ID (>0) when a job was inserted; `pending` tells whether a job is now verifiably pending (new insert, lost unique-race, or WP-Cron fallback).
		 * @since 2.2.0
		 */
		private static function schedule_ccss_retry( string $template_hash, ?int $attempts = null ): array {
			$none = array(
				'id'      => 0,
				'pending' => false,
			);
			if ( ! self::is_valid_template_hash( $template_hash ) ) {
				return $none;
			}
			try {
				// Wrapped payload: AS unpacks args positionally, so the
				// callback must receive the assoc array as one argument.
				$hook_args = array( array( 'template_hash' => $template_hash ) );
				if ( null === $attempts ) {
					$attempts = self::get_ccss_timeout_attempts( $template_hash );
				}
				// Exponential backoff: 5min, 10min, 20min, 40min. The shift is
				// already bounded to 0..3 so the result is exactly one of those
				// four steps — no further clamping needed.
				$delay = 300 * ( 1 << min( max( $attempts - 1, 0 ), 3 ) );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					// Single enqueue-gate flow (issue #1310 review): the
					// shared helper owns the atomic-first insert plus the
					// both-group re-check, so this path can never drift
					// from inline/regenerate_all/regenerate_single.
					return self::schedule_ccss_job( 'wppo_generate_ccss', $hook_args, time() + $delay );
				}
				if ( function_exists( 'wp_next_scheduled' ) && function_exists( 'wp_schedule_single_event' ) ) {
					if ( ! wp_next_scheduled( 'wppo_generate_ccss', $hook_args ) ) {
						$scheduled = (bool) wp_schedule_single_event( time() + $delay, 'wppo_generate_ccss', $hook_args );
						return array(
							'id'      => 0,
							'pending' => $scheduled,
						);
					}
					return array(
						'id'      => 0,
						'pending' => true,
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $none;
		}

		/**
		 * Single home for the CCSS Action Scheduler enqueue-gate flow.
		 *
		 * Owns the atomic-first unique insert plus the both-group re-check
		 * so schedule_ccss_retry(), inline_ccss(), regenerate_all() and
		 * regenerate_single() can never drift apart again (issue #1310
		 * review). On AS 4.x the unique insert dedupes by itself, so no
		 * pre-check SELECTs run on the hot path; a 0 return is disambiguated
		 * with one both-group re-check (lost unique-race vs scheduler
		 * failure). On older schedulers the legacy check-then-act probes
		 * both the dedicated and the legacy group before inserting.
		 * Fail-open: any missing API or exception reports no pending job
		 * rather than fataling the caller.
		 *
		 * @since 2.2.0
		 * @param string $hook      Action hook.
		 * @param array  $hook_args Wrapped action arguments.
		 * @param int    $timestamp When the job will run.
		 * @return array{id:int,pending:bool} `id` is the new action ID (>0) for a genuine insert; `pending` tells whether a job is now verifiably pending.
		 */
		private static function schedule_ccss_job( string $hook, array $hook_args, int $timestamp ): array {
			$none = array(
				'id'      => 0,
				'pending' => false,
			);
			try {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return $none;
				}
				$legacy_group = 'performance_optimisation';
				// Util ships in-repo: no method_exists guard needed.
				$use_unique = Util::supports_action_scheduler_unique();
				if ( $use_unique ) {
					// Atomic path first (issue #1310): the insert itself
					// dedupes, so the legacy pre-check below only runs on
					// older schedulers.
					$job_id = Util::schedule_unique_single_action( $timestamp, $hook, $hook_args, self::CCSS_AS_GROUP, array( $legacy_group ) );
					if ( $job_id > 0 ) {
						try {
							self::$pending_memo[ md5( $hook . serialize( $hook_args ) ) ] = true; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
						} catch ( \Throwable $e ) {
							unset( $e );
						}
						return array(
							'id'      => $job_id,
							'pending' => true,
						);
					}
					// A 0 return is ambiguous (deduped race vs scheduler
					// failure): re-check both groups for the winner.
					if ( self::has_pending_ccss_job( $hook, $hook_args ) ) {
						return array(
							'id'      => 0,
							'pending' => true,
						);
					}
					return $none;
				}
				if ( self::has_pending_ccss_job( $hook, $hook_args ) ) {
					return array(
						'id'      => 0,
						'pending' => true,
					);
				}
				$job_id = 0;
				if ( function_exists( 'as_schedule_single_action' ) ) {
					try {
						$job_id = (int) as_schedule_single_action( $timestamp, $hook, $hook_args, self::CCSS_AS_GROUP );
					} catch ( \Throwable $e ) {
						unset( $e );
						$job_id = 0;
					}
				} else {
					try {
						$job_id = (int) as_enqueue_async_action( $hook, $hook_args, self::CCSS_AS_GROUP );
					} catch ( \Throwable $e ) {
						unset( $e );
						$job_id = 0;
					}
				}
				if ( $job_id > 0 ) {
					try {
						self::$pending_memo[ md5( $hook . serialize( $hook_args ) ) ] = true; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					return array(
						'id'      => $job_id,
						'pending' => true,
					);
				}
				if ( self::has_pending_ccss_job( $hook, $hook_args ) ) {
					return array(
						'id'      => 0,
						'pending' => true,
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $none;
		}

		/**
		 * Consecutive timeout count for a template (backoff + escalation).
		 *
		 * Stored as a blog-aware transient so multisite sites back off
		 * independently. Missing transient API reads as zero (fail-open).
		 *
		 * @param string $template_hash Template hash.
		 * @return int Consecutive timeout count.
		 * @since 2.2.0
		 */
		private static function get_ccss_timeout_attempts( string $template_hash ): int {
			try {
				if ( ! function_exists( 'get_transient' ) || ! self::is_valid_template_hash( $template_hash ) ) {
					return 0;
				}
				$stored = get_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ) );
				return is_numeric( $stored ) ? max( 0, (int) $stored ) : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Reset the consecutive timeout count after a successful generation.
		 *
		 * Best-effort only; a missing transient API is a no-op.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since 2.2.0
		 */
		private static function clear_ccss_timeout_attempts( string $template_hash ): void {
			try {
				if ( function_exists( 'delete_transient' ) && self::is_valid_template_hash( $template_hash ) ) {
					delete_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ) );
					delete_transient( Util::transient_key( 'wppo_ccss_timeout_first_' . $template_hash ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Reset all bounded-retry state (generic + timeout) for a template.
		 *
		 * Called when a template is marked `skipped` (it may become
		 * renderable later and must not inherit stale failure counts) and
		 * after a successful generation. Best-effort only.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since 2.2.0
		 */
		private static function clear_ccss_retry_state( string $template_hash ): void {
			try {
				self::clear_ccss_generation_attempts( $template_hash );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				self::clear_ccss_timeout_attempts( $template_hash );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Record a generation timeout: log, mark pending, schedule a retry.
		 *
		 * Fail-open contract (issue #1235): the page keeps its existing
		 * stylesheets untouched, the stored CCSS file (if any) is left in
		 * place, and the template is marked `pending` (not `failed`) so the
		 * next eligible run retries instead of waiting out the day-long
		 * failure TTL. Never fatals; every step is individually guarded.
		 * Multisite-safe: status writes go through the blog-aware
		 * Util::transient_key() path in set_status_cache().
		 *
		 * @param string $template_hash Template hash that timed out.
		 * @param int    $budget        Budget in seconds that was exhausted.
		 * @return void
		 * @since 2.2.0
		 */
		private static function handle_ccss_timeout( string $template_hash, int $budget ): void {
			if ( ! self::is_valid_template_hash( $template_hash ) ) {
				return;
			}
			$attempts = 0;
			try {
				$attempts = self::get_ccss_timeout_attempts( $template_hash ) + 1;
				if ( function_exists( 'set_transient' ) ) {
					set_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ), $attempts, defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}
				// Wall-clock liveness fallback (issue #1235 review): the
				// counter above is best-effort — an evicted transient reads
				// back as 0 and would pin the delay at 5min forever. The
				// first-timeout stamp gets a TTL strictly longer than the
				// escalation window (2 days vs the 1-day check) so the
				// (time() - first) > DAY test can actually fire instead of
				// expiring together with the window it measures.
				if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
					$first_key = Util::transient_key( 'wppo_ccss_timeout_first_' . $template_hash );
					$first     = get_transient( $first_key );
					$day       = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
					if ( ! is_numeric( $first ) ) {
						set_transient( $first_key, time(), 2 * $day );
					} elseif ( ( time() - (int) $first ) > $day ) {
						$attempts = max( $attempts, self::MAX_CCSS_TIMEOUT_ATTEMPTS );
						// Persist the wall-clock bump so the counter and the
						// transient cannot diverge on the next timeout read.
						set_transient( Util::transient_key( 'wppo_ccss_timeout_' . $template_hash ), $attempts, $day );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Bounded retries: after MAX consecutive timeouts escalate to
			// `failed` so a permanently-slow origin stops burning a full
			// worker per cycle. The effective cap also honours the
			// additive `ccssMaxRetries` setting (issue #1274); escalation
			// uses the COMBINED generic + timeout count so alternating
			// failure modes still terminate, and a cap of 0 fails fast
			// with no retry scheduled. Only the escalation is logged —
			// retries below the cap stay silent so timeouts do not cost a
			// Log::add() DB insert per attempt.
			$retry_cap = self::MAX_CCSS_MAX_RETRIES;
			try {
				$retry_cap = self::get_ccss_max_retries(); // 0..5, 0 = immediate failed.
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Lazy second-counter read (see record_generation_failure).
			$total = $attempts;
			if ( $retry_cap > 0 && $total < max( 1, $retry_cap ) ) {
				try {
					$total = $attempts + self::get_ccss_generation_attempts( $template_hash );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( $retry_cap <= 0 || $total >= max( 1, $retry_cap ) ) {
				try {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
						if ( function_exists( '__' ) ) {
							$message = sprintf(
								/* translators: 1: Template hash 2: Consecutive failure count 3: Timeout budget in seconds */
								__( 'Critical CSS generation escalated to failed for template: %1$s after %2$d consecutive failures (timeout budget %3$d seconds).', 'performance-optimisation' ),
								$template_hash,
								$total,
								$budget
							);
						} else {
							$message = sprintf(
								'Critical CSS generation escalated to failed for template: %s after %d consecutive failures (timeout budget %d seconds).',
								$template_hash,
								$total,
								$budget
							);
						}
						Log::add( $message );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return;
			}
			// Below the cap: stay silent (no per-timeout Log::add) and
			// re-queue with backoff. The status is gated on the verified
			// schedule outcome (issue #1310 review): only assert `queued`
			// when a retry job is now pending, else mark `failed` so no
			// phantom queued status survives a scheduler failure.
			$retry = self::schedule_ccss_retry( $template_hash );
			try {
				if ( $retry['pending'] ) {
					self::set_status_cache( $template_hash, 'queued', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				} else {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Guarded CCSS generation wrapper with a wall-clock timeout (issue #1235).
		 *
		 * Time-boxes the fetch-plus-parse phase of generate_and_store() to
		 * the get_ccss_gen_timeout() budget. Fail-open contract: on expiry
		 * the previous stored CSS (if any) is left untouched and no partial
		 * output is ever stored or inlined — the page keeps its existing
		 * stylesheets, the miss is logged, and a retry is scheduled for a
		 * later run. Never fatals; any unexpected error returns false with
		 * the previous file (if any) still in place.
		 *
		 * Timeout ownership: generate_and_store() logs + marks pending +
		 * schedules the retry on every expiry exit, so this guard never
		 * calls handle_ccss_timeout() itself — it only reports timed_out
		 * for the caller's log routing. Soft-budget note: a complete file
		 * committed just past the deadline still reports success (the
		 * output is whole, never partial).
		 *
		 * @param string    $template_hash Template hash to generate.
		 * @param string    $template      Template identifier for the sample URL.
		 * @param bool|null $timed_out     Out-param: true when the run hit the timeout budget.
		 * @param bool      $stage_only    When true (issue #1348 dry-run), the output lands on the staged sibling instead of going live.
		 * @return bool True on success, false on failure or timeout.
		 * @since 2.2.0
		 * @since 2.3.0 Added the $stage_only dry-run flag.
		 */
		public static function generate_guarded( string $template_hash, string $template, ?bool &$timed_out = null, bool $stage_only = false ): bool {
			$timed_out = false;
			$budget    = self::DEFAULT_CCSS_GEN_TIMEOUT;
			$deadline  = null;
			try {
				if ( ! self::is_valid_template_hash( $template_hash ) ) {
					return false;
				}
				list( $budget, $deadline ) = self::resolve_generation_budget();

				$had_file = self::ccss_exists( $template_hash );

				$result = self::generate_and_store( $template_hash, $template, $deadline, $budget, $stage_only );
				if ( $result ) {
					try {
						self::clear_ccss_timeout_attempts( $template_hash );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					try {
						self::clear_ccss_generation_attempts( $template_hash );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					return true;
				}
				if ( ! self::generation_expired( $deadline ) ) {
					return false;
				}
				// Deterministic no-sample-URL failure (issue #1235 review) is
				// never a timeout even past expiry: generate_and_store() marks
				// it failed directly without calling handle_ccss_timeout(), so
				// claiming timed_out here would skip both logs with no retry.
				try {
					if ( ! self::get_sample_url( $template ) ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}

				$timed_out = true;

				// Defensive no-partial guarantee: generate_and_store() only
				// writes complete CSS, so a timed-out run must leave the
				// previous file untouched. If a file appeared where none
				// existed, remove it so a partial can never be served.
				// Direct filesystem check (not ccss_exists()): the memo
				// caches the pre-run verdict and the timeout path never
				// invalidates it, so a memoized re-check would always
				// report the stale value and miss an external file.
				try {
					$file = self::get_ccss_file( $template_hash );
					if ( '' !== $file ) {
						clearstatcache( true, $file );
					}
					if ( ! $had_file && '' !== $file && file_exists( $file ) ) {
						$filesystem = Util::init_filesystem();
						if ( $filesystem ) {
							$filesystem->delete( $file );
						} else {
							// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Local cache invalidation fallback.
							unlink( $file );
						}
						self::invalidate_ccss_memo( $template_hash );
						clearstatcache( true, $file );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}

				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				// Exceptional tail (DOM/minify/filesystem) past expiry must
				// still honor the timeout-to-pending-plus-retry contract so
				// the queue slot is not lost with a stale status and no retry.
				try {
					if ( self::is_valid_template_hash( $template_hash ) ) {
						self::record_generation_failure( $template_hash, $budget, $deadline );
					}
				} catch ( \Throwable $ignored ) {
					unset( $ignored );
				}
				return false;
			}
		}

		/**
		 * Whether viewport-split CCSS variants are enabled (issue #1164).
		 *
		 * Additive `file_optimisation.ccssViewportVariants` setting (bool or
		 * list of variant slugs, default false = current single-variant
		 * behaviour verbatim). Fail-open: any error returns false.
		 *
		 * @return bool True when split variants should be emitted/served.
		 * @since 2.2.0
		 */
		public static function is_viewport_variants_enabled(): bool {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssViewportVariants'] ?? false;
				if ( is_array( $raw ) ) {
					return ! empty( $raw );
				}
				return (bool) $raw;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_variant_file}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @param string $variant Parameter (see Ccss_Store).
		 * @return string Result (see Ccss_Store).
		 */
		public static function get_ccss_variant_file( string $template_hash, string $variant ): string {
			return Ccss_Store::get_ccss_variant_file( $template_hash, $variant );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_variant_content}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @param string $variant Parameter (see Ccss_Store).
		 * @return ?string Result (see Ccss_Store).
		 */
		public static function get_ccss_variant_content( string $template_hash, string $variant ): ?string {
			return Ccss_Store::get_ccss_variant_content( $template_hash, $variant );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::is_variant_stale}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @param string $variant Parameter (see Ccss_Store).
		 * @return bool Result (see Ccss_Store).
		 */
		public static function is_variant_stale( string $template_hash, string $variant ): bool {
			return Ccss_Store::is_variant_stale( $template_hash, $variant );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::invalidate_stale_variants}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 */
		public static function invalidate_stale_variants( string $template_hash ): void {
			Ccss_Store::invalidate_stale_variants( $template_hash );
		}

		/**
		 * Whether a stylesheet handle is exempt from CCSS deferral (issue #1164).
		 *
		 * The combined core block-library stylesheet (`wp-block-library`)
		 * must never be deferred: deferring it flashes unstyled blocks
		 * (FOUC) on first paint before the deferred swap runs. Mirrors the
		 * SKIP_DEFER_HANDLES allowlist so unit tests can assert the guard
		 * without rendering tags.
		 *
		 * @param string $handle Stylesheet handle.
		 * @param string $href   Stylesheet URL (optional, substring match).
		 * @return bool True when the handle must load normally.
		 * @since 2.2.0
		 */
		public static function is_block_library_defer_exempt( string $handle, string $href = '' ): bool {
			foreach ( self::SKIP_DEFER_HANDLES as $skip ) {
				if ( $handle === $skip ) {
					return true;
				}
				if ( '' !== $href && false !== strpos( $href, $skip ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether the current request is an Elementor context (issue #1164).
		 *
		 * Elementor-built pages (per-post `_elementor_edit_mode === 'builder'`
		 * or non-empty `_elementor_data` meta, or a gated editor-preview
		 * request) keep full stylesheets: deferring or purging builder CSS
		 * risks FOUC on popups/dialogs that render in hidden containers.
		 * Fail-closed: any error returns true (exemption = unoptimized
		 * output, never broken builder markup), matching the Main detector's
		 * fail direction.
		 *
		 * Meta verdicts delegate to the memoized
		 * Main::is_elementor_built_page() (issue #1259) so the combine and
		 * CCSS pipelines share one meta-read pair — and one strict
		 * `'builder' === (string) $edit_mode` predicate — instead of each
		 * fetching and unserializing the large `_elementor_data` blob.
		 * Plugin-active alone is NOT a context: every page on an
		 * Elementor-active site would otherwise be exempt, defeating CCSS
		 * deferral on non-builder pages. The `?elementor-preview` bypass
		 * applies only when Elementor looks active (same gating as the Main
		 * detector).
		 *
		 * @param int|null $post_id Optional post ID to inspect.
		 * @return bool True when Elementor handling applies.
		 * @since 2.2.0
		 */
		public static function is_elementor_context( ?int $post_id = null ): bool {
			try {
				// Note: autoload disabled to match Main::looks_like_elementor_request()
				// and Main::detect_elementor_built_page() — an autoloadable but
				// not-yet-loaded Elementor class must not change the verdict
				// between call sites, and the combine hot path must not pay an
				// autoloader scan.
				$plugin_active = false;
				try {
					if ( class_exists( 'Elementor\Plugin', false ) || defined( 'ELEMENTOR_VERSION' ) || function_exists( 'elementor_pro_load_plugin' ) ) {
						$plugin_active = true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( null !== $post_id && $post_id > 0 ) {
					if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_elementor_built_page' ) ) {
						try {
							if ( Main::is_elementor_built_page( $post_id ) ) {
								return true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
							// Fail-closed: a transient meta failure must not
							// optimize through builder markup in one pipeline
							// while the Main detector skips in the other.
							return true;
						}
					} else {
						// Minimal-boot fallback (Main unavailable): consult
						// the documented wppo_is_elementor_page escape hatch
						// first so Theme Builder overrides are honoured in
						// exactly the degraded boot that needs them, then the
						// same strict predicate and tiny-first meta order as
						// the Main detector so verdicts cannot drift.
						if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_is_elementor_page' ) ) {
							try {
								$filtered = apply_filters( 'wppo_is_elementor_page', null, $post_id, $post_id );
								if ( null !== $filtered ) {
									return (bool) $filtered;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
								return true;
							}
						}
						if ( function_exists( 'get_post_meta' ) ) {
							try {
								$edit_mode = get_post_meta( $post_id, '_elementor_edit_mode', true );
								if ( 'builder' === (string) $edit_mode ) {
									return true;
								}
								$data = get_post_meta( $post_id, '_elementor_data', true );
								if ( ! empty( $data ) ) {
									return true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
								return true;
							}
						}
					}
				}
				if ( $plugin_active && isset( $_GET['elementor-preview'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Elementor smoke check: purged CSS must still cover builder markers (issue #1164).
		 *
		 * Scans HTML for Elementor markers (`data-elementor-type`,
		 * `elementor-widget`, `elementor-popup`) and requires the purged CSS
		 * to retain at least one `.elementor` / `[data-elementor-type]` rule.
		 * Pages without builder markup always pass. Fail-open: any error or
		 * empty purged CSS on a non-builder page passes; empty purged CSS on
		 * a builder page fails so callers serve the full stylesheet instead
		 * of flashing unstyled content.
		 *
		 * @param string $html       Page HTML.
		 * @param string $purged_css Purged/used CSS candidate.
		 * @return bool True when it is safe to serve the purged CSS.
		 * @since 2.2.0
		 */
		public static function passes_elementor_smoke( string $html, string $purged_css ): bool {
			if ( class_exists( 'PerformanceOptimise\Inc\Css_Safelist' ) ) {
				return Css_Safelist::passes_elementor_smoke( $html, $purged_css );
			}
			try {
				if ( '' === $html ) {
					return true;
				}
				$has_builder = false !== strpos( $html, 'data-elementor-type' )
					|| false !== strpos( $html, 'elementor-widget' )
					|| false !== strpos( $html, 'elementor-popup' );
				if ( ! $has_builder ) {
					return true;
				}
				if ( '' === trim( $purged_css ) ) {
					return false;
				}
				return false !== strpos( $purged_css, '.elementor' )
					|| false !== strpos( $purged_css, 'elementor-' )
					|| false !== strpos( $purged_css, 'data-elementor-type' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Built-in Critical CSS safelist presets (issue #1102).
		 *
		 * Above-fold extraction can never see hidden/dynamic selectors
		 * (Elementor popups render in hidden containers / JS portals;
		 * dynamic-token carriers only resolve at runtime), so these are
		 * always preserved — even when the user `ccssSafelistExtra` setting
		 * is empty. Mirrors the popup/Elementor subset of
		 * {@see Used_CSS::get_safelist_presets()} so the two pipelines
		 * cannot drift. Prefix entries ending in '-' or '*' match via
		 * prefix in {@see matches_ccss_safelist()}; attribute entries match
		 * by attribute-name substring. Filterable via the
		 * `wppo_ccss_safelist_presets` filter. Local data only — no remote
		 * fetch. Per-site settings make this multisite-safe by construction.
		 *
		 * Kept separate from {@see get_ccss_safelist()} (which stays
		 * user-only) so the checksum freshness probe gate in
		 * {@see maybe_check_stale_and_requeue()} keeps its pre-#1038
		 * no-churn behaviour when no user safelist is configured.
		 *
		 * @return string[]
		 * @since 2.2.0
		 */
		public static function get_ccss_safelist_presets(): array {
			$presets = class_exists( 'PerformanceOptimise\Inc\Css_Safelist' )
				? Css_Safelist::get_elementor_presets()
				: array(
					'.elementor-',
					'.elementor-popup-',
					'.e-con*',
					'.e-popup-',
					'.dialog-',
					'.popup-',
					'.modal-',
					'.mfp-',
					'.swal2-',
					'[data-elementor-type]',
					'[data-elementor-type="popup"]',
				);

			/**
			 * Filters the built-in Critical CSS safelist presets.
			 *
			 * @param string[] $presets Built-in safelisted selectors.
			 * @since 2.2.0
			 */
			if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_ccss_safelist_presets' ) ) {
				$filtered = apply_filters( 'wppo_ccss_safelist_presets', $presets );
				if ( is_array( $filtered ) ) {
					$filtered = array_filter( $filtered, 'is_string' );
					$presets  = array_values( array_filter( array_unique( array_map( 'trim', $filtered ) ) ) );
				}
			}

			return $presets;
		}

		/**
		 * User safelist of selectors always kept in Critical CSS (issue #1038).
		 *
		 * Reads the additive `file_optimisation.ccssSafelistExtra` setting
		 * (one selector per line, default empty = current behaviour). Local
		 * reads only — never fetches remotely. Per-site settings make this
		 * multisite-safe by construction.
		 *
		 * @return string[] Safelisted selectors, trimmed and de-duplicated.
		 * @since 2.0.0
		 */
		public static function get_ccss_safelist(): array {
			$options = Util::get_settings();
			$raw     = $options['file_optimisation']['ccssSafelistExtra'] ?? '';
			if ( is_array( $raw ) ) {
				$raw = implode( "\n", $raw );
			}
			$list = Util::process_urls( (string) $raw );

			/**
			 * Filters the Critical CSS user safelist.
			 *
			 * @param string[] $list Safelisted selectors.
			 * @since 2.0.0
			 */
			if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_ccss_safelist' ) ) {
				$filtered = apply_filters( 'wppo_ccss_safelist', $list );
				if ( is_array( $filtered ) ) {
					// Fail-open: rogue filter output (nested arrays, objects)
					// degrades to ignored entries instead of a trim() fatal
					// inside the wp_head inline path.
					$filtered = array_filter( $filtered, 'is_string' );
					$list     = array_values( array_filter( array_unique( array_map( 'trim', $filtered ) ) ) );
				}
			}

			return $list;
		}

		/**
		 * Whether a selector matches the Critical CSS user safelist.
		 *
		 * Mirrors the Used_CSS safelist semantics: exact match wins;
		 * attribute entries (leading `[`) match by attribute-name substring
		 * so compound selectors stay kept; trailing `-`, `_`, or `*`
		 * entries match by prefix. Otherwise a case-insensitive substring
		 * match keeps hidden/dynamic selectors (e.g. `.modal-open`,
		 * `.sub-menu`) that the static above-fold walk never sees.
		 * Fail-open: an empty safelist never matches.
		 *
		 * Local string comparison only — no remote fetch.
		 *
		 * @param string        $selector CSS selector string (may be a group).
		 * @param string[]|null $safelist Optional pre-fetched safelist; callers
		 *                               walking many rules pass the list in so
		 *                               settings are read once, not per rule.
		 * @return bool True when safelisted.
		 * @since 2.0.0
		 */
		public static function matches_ccss_safelist( string $selector, ?array $safelist = null ): bool {
			$selector = trim( $selector );
			if ( '' === $selector ) {
				return false;
			}

			$list = $safelist ?? self::get_ccss_safelist();
			if ( empty( $list ) ) {
				return false;
			}

			foreach ( $list as $safe ) {
				$safe = trim( (string) $safe );
				if ( '' === $safe ) {
					continue;
				}
				if ( $selector === $safe ) {
					return true;
				}
				if ( '[' === $safe[0] ) {
					$attr_name = preg_replace( '/[\]=~|^$*"\'].*$/', '', $safe );
					$attr_name = ltrim( trim( (string) $attr_name ), '[' );
					if ( '' !== $attr_name && false !== stripos( $selector, $attr_name ) ) {
						return true;
					}
					continue;
				}
				$last = substr( $safe, -1 );
				if ( ( '-' === $last || '_' === $last ) && 0 === strpos( $selector, $safe ) ) {
					return true;
				}
				// Wildcard entries match by non-empty prefix only: a bare
				// '*' covers just the universal selector (exact match above),
				// never every rule.
				$stem = '*' === $last ? substr( $safe, 0, -1 ) : '';
				if ( '' !== $stem && 0 === strpos( $selector, $stem ) ) {
					return true;
				}
				// Substring fallback is token-gated: entries shorter than 3
				// chars (e.g. 'p', 'a') would otherwise match nearly every
				// selector via stripos and silently keep the whole
				// stylesheet. Exact, attribute, and prefix matches above are
				// unaffected — single-char selectors still match exactly.
				if ( strlen( $safe ) < 3 ) {
					continue;
				}
				if ( false !== stripos( $selector, $safe ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Effective Critical CSS safelist: built-in presets + user entries.
		 *
		 * Merges {@see get_ccss_safelist_presets()} with the user
		 * {@see get_ccss_safelist()} list (deduplicated). Extraction checks
		 * both sources via {@see matches_above_fold_single()}; this helper
		 * exposes the merged view for diagnostics and tests. Local data
		 * only — no remote fetch.
		 *
		 * @return string[] Merged safelisted selectors.
		 * @since 2.2.0
		 */
		public static function get_ccss_effective_safelist(): array {
			try {
				$merged = array_merge( self::get_ccss_safelist_presets(), self::get_ccss_safelist() );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			return array_values( array_unique( array_filter( array_map( 'trim', $merged ) ) ) );
		}

		/**
		 * Stable content hash of CSS source (issue #1038).
		 *
		 * Thin backward-compatible wrapper around the shared
		 * {@see Util::compute_css_checksum()} (audit #7) so existing callers
		 * and tests keep working while both CSS pipelines share one
		 * implementation.
		 *
		 * @param string $css CSS content.
		 * @return string SHA-256 checksum, or '' for empty input.
		 * @since 2.0.0
		 */
		public static function compute_css_checksum( string $css ): string {
			return Util::compute_css_checksum( $css );
		}

		/**
		 * Multisite-aware transient key for a template's source checksum.
		 *
		 * Blog-ID prefixing via `Util::transient_key()` keeps per-site
		 * checksums isolated on multisite networks with a shared object
		 * cache backend.
		 *
		 * @param string $template_hash Template hash.
		 * @return string Transient key.
		 * @since 2.0.0
		 */
		private static function get_source_checksum_key( string $template_hash ): string {
			return Util::transient_key( 'wppo_ccss_checksum_' . $template_hash );
		}

		/**
		 * Multisite-aware transient key for a template's canonical source URL list.
		 *
		 * Persisted alongside the source checksum at generation time so the
		 * runtime probe can re-hash exactly the document-ordered URL list the
		 * server-side fetch saw, instead of re-deriving one from
		 * `$wp_styles->queue` that may differ in content or order (audit #9).
		 *
		 * @param string $template_hash Template hash.
		 * @return string Transient key.
		 * @since 2.0.0
		 */
		private static function get_source_urls_key( string $template_hash ): string {
			return Util::transient_key( 'wppo_ccss_sources_' . $template_hash );
		}

		/**
		 * TTL for the per-template source baseline transients.
		 *
		 * Shared by the checksum and URL-list baselines so they expire
		 * together. Filterable via `wppo_ccss_checksum_ttl`.
		 *
		 * @return int TTL in seconds.
		 * @since 2.0.0
		 */
		private static function get_source_checksum_ttl(): int {
			/**
			 * Filters how long a Critical CSS source checksum is kept.
			 *
			 * @param int $ttl Time to live in seconds. Default WEEK_IN_SECONDS.
			 * @since 2.0.0
			 */
			$default = defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
			$ttl     = function_exists( 'apply_filters' ) ? (int) apply_filters( 'wppo_ccss_checksum_ttl', $default ) : $default;
			if ( $ttl <= 0 ) {
				return $default;
			}
			// Upper clamp (issue #1235): a rogue filter must not pin
			// checksum transients for years (stale-CCSS pinning + DB bloat).
			$max = defined( 'YEAR_IN_SECONDS' ) ? YEAR_IN_SECONDS : 31536000;
			return min( $ttl, $max );
		}

		/**
		 * Persist the canonical document-ordered source URL list after generation.
		 *
		 * The list is the exact ordered set of `//link[@rel=stylesheet]`
		 * hrefs the generation fetch resolved (audit #9). The runtime probe
		 * re-hashes this list so it cannot diverge from the generation-time
		 * baseline. Local transient write only — no remote fetch. Fail-open:
		 * missing transient API or an empty list is a no-op.
		 *
		 * @param string   $template_hash Template hash.
		 * @param string[] $source_urls   Document-ordered stylesheet URLs.
		 * @return void
		 * @since 2.0.0
		 */
		public static function store_source_urls( string $template_hash, array $source_urls ): void {
			if ( '' === $template_hash || array() === $source_urls ) {
				return;
			}
			if ( ! function_exists( 'set_transient' ) ) {
				return;
			}
			$urls = array();
			foreach ( $source_urls as $url ) {
				if ( is_string( $url ) && '' !== $url ) {
					$urls[] = $url;
				}
			}
			if ( array() === $urls ) {
				return;
			}
			set_transient( self::get_source_urls_key( $template_hash ), array_values( $urls ), self::get_source_checksum_ttl() );
		}

		/**
		 * Read the persisted canonical source URL list.
		 *
		 * Returns an empty array for already-cached entries generated before
		 * the URL list was persisted (audit #9). Callers must treat that as
		 * "no baseline" and fall back to the previous $wp_styles-derived
		 * behavior rather than treating the entry as stale.
		 *
		 * @param string $template_hash Template hash.
		 * @return string[] Persisted URLs, or array() when none.
		 * @since 2.0.0
		 */
		private static function get_stored_source_urls( string $template_hash ): array {
			if ( '' === $template_hash || ! function_exists( 'get_transient' ) ) {
				return array();
			}
			$stored = get_transient( self::get_source_urls_key( $template_hash ) );
			if ( ! is_array( $stored ) ) {
				return array();
			}
			$urls = array();
			foreach ( $stored as $url ) {
				if ( is_string( $url ) && '' !== $url ) {
					$urls[] = $url;
				}
			}
			return $urls;
		}

		/**
		 * Whether stored source checksum differs (stale CCSS, issue #1038).
		 *
		 * Compares the checksum of locally-available source CSS against the
		 * stored checksum. Local reads only — no remote fetch. Fail-open:
		 * missing stored checksum or unavailable transient API reports stale
		 * only when there is stored output to compare against; an empty
		 * checksum input never reports stale.
		 *
		 * @param string $template_hash Template hash.
		 * @param string $source_css    Locally-available source CSS content.
		 * @return bool True when the source changed since generation.
		 * @since 2.0.0
		 */
		public static function is_source_checksum_stale( string $template_hash, string $source_css ): bool {
			if ( '' === $template_hash || '' === $source_css ) {
				return false;
			}
			if ( ! function_exists( 'get_transient' ) ) {
				return false;
			}
			$stored = get_transient( self::get_source_checksum_key( $template_hash ) );
			if ( ! is_string( $stored ) || '' === $stored ) {
				return false;
			}
			return ! hash_equals( $stored, self::compute_css_checksum( $source_css ) );
		}

		/**
		 * Persist the source checksum after a successful generation.
		 *
		 * Local transient write only — no remote fetch. Fail-open: missing
		 * transient API is a no-op. The TTL is filterable via
		 * `wppo_ccss_checksum_ttl` (default WEEK_IN_SECONDS).
		 *
		 * @param string $template_hash Template hash.
		 * @param string $source_css    Source CSS content that was generated from.
		 * @return void
		 * @since 2.0.0
		 */
		public static function store_source_checksum( string $template_hash, string $source_css ): void {
			if ( '' === $template_hash || '' === $source_css ) {
				return;
			}
			if ( ! function_exists( 'set_transient' ) ) {
				return;
			}
			set_transient( self::get_source_checksum_key( $template_hash ), self::compute_css_checksum( $source_css ), self::get_source_checksum_ttl() );
		}

		/**
		 * Checksum-triggered refresh from locally-available CSS (issue #1038).
		 *
		 * Compares the checksum of the given local source against the source
		 * checksum stored at generation time — same source domain, so an
		 * unchanged page is fresh (no remote fetch on either side). On
		 * mismatch the stored variant is deleted so the next `inline_ccss()`
		 * hit re-queues background generation through the existing path; the
		 * oversize file-first delivery and 20 KB inline cap in `inline_ccss()`
		 * are untouched, so the cap stays honored. Fail-open: any error
		 * returns false and the pristine stored variant is left in place
		 * (never fatal).
		 *
		 * @param string $template_hash Template hash.
		 * @param string $source_css    Locally-available source CSS content.
		 * @return bool True when the stored variant was dropped as stale.
		 * @since 2.0.0
		 */
		public static function maybe_refresh_from_local_css( string $template_hash, string $source_css ): bool {
			if ( '' === $template_hash || '' === $source_css ) {
				return false;
			}
			if ( ! function_exists( 'get_transient' ) || ! function_exists( 'delete_transient' ) ) {
				return false;
			}
			try {
				$key    = self::get_source_checksum_key( $template_hash );
				$stored = get_transient( $key );
				if ( ! is_string( $stored ) || '' === $stored ) {
					// No baseline yet: adopt the current local source so the
					// next content change is detected. Never drops the file.
					self::store_source_checksum( $template_hash, $source_css );
					return false;
				}
				// Same raw local-source domain that generate_and_store()
				// baselined: an unchanged page hashes equal and keeps its
				// generated variant.
				if ( ! self::is_source_checksum_stale( $template_hash, $source_css ) ) {
					return false;
				}
				$file = self::get_ccss_file( $template_hash );
				if ( '' !== $file && file_exists( $file ) ) {
					$filesystem = Util::init_filesystem();
					if ( $filesystem ) {
						$filesystem->delete( $file );
					} else {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Local cache invalidation fallback.
						unlink( $file );
					}
				}
				delete_transient( $key );
				delete_transient( self::get_source_urls_key( $template_hash ) );
				self::invalidate_ccss_memo( $template_hash );
				if ( function_exists( 'clearstatcache' ) ) {
					clearstatcache( true, $file );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Per-request memo for the local-source freshness probe.
		 *
		 * The probe runs at most once per template per request so repeated
		 * `inline_ccss()` calls cost a single capped local-source pass.
		 *
		 * @since 2.0.0
		 * @var array<string, bool>
		 */
		private static array $stale_probe_memo = array();

		/**
		 * Build the canonical local-source CSS string from ordered URLs.
		 *
		 * Shared by the generation-time baseline (`generate()`) and the runtime
		 * freshness probe so both hash the SAME source domain: locally
		 * resolvable external stylesheets concatenated in emission order, read
		 * raw (no @import expansion) so neither side can drift. Duplicates are
		 * collapsed by RESOLVED LOCAL PATH: `defer_stylesheets()` emits the
		 * deferred `<link>` plus a `<noscript>` copy of the original tag, and
		 * the generation-side XPath matches both while the probe reads each
		 * handle once. Bounded (20 files, 512 KB per file, 2 MB total) so the
		 * probe cannot blow memory on large multisheet sites. Fail-open:
		 * unresolvable URLs are skipped.
		 *
		 * @param string[]   $urls Ordered stylesheet URLs (document/queue order).
		 * @param float|null $deadline Optional absolute wall-clock deadline;
		 *                             the file loop breaks early when expired
		 *                             (issue #1235 review).
		 * @return string Concatenated source CSS, or '' when none resolve locally.
		 * @since 2.0.0
		 * @since 2.2.0 Optional deadline-aware early break.
		 */
		private static function build_local_source_css( array $urls, ?float $deadline = null ): string {
			$combined = '';
			$count    = 0;
			$seen     = array();
			foreach ( $urls as $url ) {
				if ( self::generation_expired( $deadline ) ) {
					break;
				}
				if ( $count >= 20 || strlen( $combined ) >= 2097152 ) {
					break;
				}
				$url = (string) $url;
				if ( '' === $url || self::is_skipped_source_url( $url ) ) {
					continue;
				}
				$local_path = Util::get_local_path( $url );
				if ( '' === $local_path || ! file_exists( $local_path ) ) {
					continue;
				}
				// Collapse duplicate emissions (deferred link + <noscript>
				// copy, or the same file enqueued twice) to a single entry so
				// the baseline matches the one-per-handle probe set.
				if ( isset( $seen[ $local_path ] ) ) {
					continue;
				}
				$seen[ $local_path ] = true;

				$size = filesize( $local_path );
				if ( false === $size || $size <= 0 || $size > 524288 ) {
					continue;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache-freshness read only, never remote.
				$content = file_get_contents( $local_path );
				if ( ! is_string( $content ) || '' === $content ) {
					continue;
				}
				$combined .= substr( $content, 0, 524288 ) . "\n";
				++$count;
			}
			return $combined;
		}

		/**
		 * Whether a stylesheet URL is excluded from the CCSS source set.
		 *
		 * Mirrors the generation-time skip list (combined bundle, dashicons,
		 * admin-bar, block-library) so the baseline and the probe hash the
		 * exact same URL set.
		 *
		 * @param string $url Stylesheet URL or handle-like fragment.
		 * @return bool True when skipped.
		 * @since 2.0.0
		 */
		private static function is_skipped_source_url( string $url ): bool {
			foreach ( self::SKIP_DEFER_HANDLES as $handle ) {
				if ( false !== strpos( $url, $handle ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Handles core's `wp_maybe_inline_styles()` will print inline.
		 *
		 * A handle opted into core's inline pass via `wp_style_add_data(
		 * $handle, 'path', ... )` (see Main::minify_queued_styles()) emits an
		 * inline `<style>` on the fetched page instead of a `<link>`, so the
		 * generation-side source set never contains it. The probe runs on the
		 * `wp_enqueue_scripts` action at PHP_INT_MAX — inside core's `wp_head`
		 * priority-1 `wp_enqueue_scripts()` call, after theme/plugin enqueues
		 * but before core's inline pass — so its handles still carry `src` and
		 * it must exclude them explicitly or the two domains diverge
		 * permanently (issue #1038).
		 *
		 * Mirrors core's candidate selection (extra `path` set with a `src`,
		 * path exists) and its size-ordered total limit so the exclusion
		 * tracks what the generation fetch actually inlined.
		 *
		 * @return string[] Handles that core will inline (queue order).
		 * @since 2.0.0
		 */
		private static function get_core_inlined_handles(): array {
			global $wp_styles;
			if ( ! $wp_styles || empty( $wp_styles->queue ) || ! is_array( $wp_styles->queue ) ) {
				return array();
			}

			$candidates = array();
			foreach ( $wp_styles->queue as $handle ) {
				$handle = (string) $handle;
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$style = $wp_styles->registered[ $handle ];
				$src   = $style->src ?? '';
				$extra = ( isset( $style->extra ) && is_array( $style->extra ) ) ? $style->extra : array();
				$path  = $extra['path'] ?? '';
				if ( '' === (string) $src || ! is_string( $path ) || '' === $path || ! file_exists( $path ) ) {
					continue;
				}
				$candidates[] = array(
					'handle' => $handle,
					'path'   => $path,
					'size'   => (int) filesize( $path ),
				);
			}

			if ( empty( $candidates ) ) {
				return array();
			}

			// Core inlines smallest-first until the total budget is reached.
			usort(
				$candidates,
				static function ( array $a, array $b ): int {
					return $a['size'] <=> $b['size'];
				}
			);

			$limit   = self::get_styles_inline_limit();
			$total   = 0;
			$inlined = array();
			foreach ( $candidates as $candidate ) {
				if ( $total + $candidate['size'] > $limit ) {
					break;
				}
				if ( ! is_readable( $candidate['path'] ) ) {
					continue;
				}
				$total    += $candidate['size'];
				$inlined[] = $candidate['handle'];
			}

			return $inlined;
		}

		/**
		 * Aggregate locally-available source CSS from the queued stylesheets.
		 *
		 * Reads local files only via `Util::get_local_path()` — never fetches
		 * remotely. Uses `$wp_styles->queue` order (the page's emission order)
		 * to match the document-order source baselined at generation time, and
		 * skips handles core will inline (no `<link>` at generation time) plus
		 * the shared skip list. Bounded by `build_local_source_css()`.
		 * Fail-open: any error yields '' (no signal).
		 *
		 * @return string Concatenated local source CSS, or '' when unavailable.
		 * @since 2.0.0
		 */
		private static function get_local_source_css(): string {
			global $wp_styles;
			if ( ! $wp_styles || empty( $wp_styles->queue ) || ! is_array( $wp_styles->queue ) ) {
				return '';
			}
			try {
				$inlined = self::get_core_inlined_handles();
				$urls    = array();
				foreach ( $wp_styles->queue as $handle ) {
					$handle = (string) $handle;
					if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
						continue;
					}
					if ( in_array( $handle, $inlined, true ) ) {
						continue;
					}
					$src = $wp_styles->registered[ $handle ]->src ?? '';
					if ( '' === $src ) {
						continue;
					}
					$urls[] = (string) $src;
				}
				return self::build_local_source_css( $urls );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Checksum freshness probe wired into the production path (issue #1038).
		 *
		 * Invoked from the `wp_enqueue_scripts` action at PHP_INT_MAX (see
		 * Main::setup_hooks()) — i.e. inside core's `wp_head` priority-1
		 * `wp_enqueue_scripts()` call, once `$wp_styles->queue` is final and
		 * before core's `wp_maybe_inline_styles()` pass. `inline_ccss()` no
		 * longer calls this at `wp_head` priority 0 because the styles queue
		 * is empty that early, so the probe could never see a source change.
		 *
		 * When a source checksum was baselined at generation time and the
		 * current locally-available stylesheets hash differently, the stale
		 * variant is dropped (via `maybe_refresh_from_local_css()`) so the
		 * NEXT request's `inline_ccss()` (priority 0) finds no variant and
		 * re-queues background generation through the existing path.
		 * Fail-open and cheap: no stored checksum (or no local source) returns
		 * false immediately without local reads, and the verdict is memoized
		 * per template per request.
		 *
		 * Inert without a user safelist: the checksum auto-regen is part of the
		 * `ccssSafelistExtra` feature (issue #1038), so an empty safelist keeps
		 * the pre-feature behaviour verbatim — no new regeneration churn.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public static function maybe_check_stale_on_enqueue(): void {
			if ( is_admin() ) {
				return;
			}
			if ( is_user_logged_in() ) {
				$options = Util::get_settings();
				$enabled = ! empty( $options['cache_settings']['enableLoggedInCache'] ?? false );
				if ( ! $enabled ) {
					return;
				}
			}

			// Mirror inline_ccss(): operators who disabled plugin inlining get
			// no critical-CSS pipeline at all, so skip the freshness probe too.
			// Suspended while deferJS/delayJS is active: deferral is off so
			// emission is off too — skip local-source reads/hashing (issue #1090).
			if ( ! self::is_ccss_effective() ) {
				return;
			}

			try {
				self::maybe_check_stale_and_requeue( self::get_template_hash() );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Checksum freshness probe (issue #1038).
		 *
		 * Called by `maybe_check_stale_on_enqueue()`: when a source checksum
		 * was baselined at generation time and the current locally-available
		 * stylesheets hash differently, the stale variant is dropped (via
		 * `maybe_refresh_from_local_css()`) so the next `inline_ccss()` hit
		 * falls through to the existing background-regen queue. Fail-open and
		 * cheap: no stored checksum (or no local source) returns false
		 * immediately without local reads, and the verdict is memoized per
		 * template per request.
		 *
		 * Inert without a user safelist or checksum regen: the checksum
		 * auto-regen is part of the `ccssSafelistExtra` feature (issue
		 * #1038), so an empty safelist keeps the pre-feature behaviour
		 * verbatim — no new regeneration churn — unless the additive
		 * `ccssChecksumRegen` setting (issue #1388, default true) opts the
		 * site into checksum-triggered regen.
		 *
		 * @param string $template_hash Template hash.
		 * @return bool True when the stored variant was dropped as stale.
		 * @since 2.0.0
		 * @since 2.3.0 Also activates via `ccssChecksumRegen` (issue #1388).
		 */
		public static function maybe_check_stale_and_requeue( string $template_hash ): bool {
			if ( '' === $template_hash ) {
				return false;
			}
			// Suspended while deferJS/delayJS is active: no emission, no
			// freshness probe work (issue #1090).
			if ( self::is_deferral_suspended_by_js() ) {
				return false;
			}
			if ( array_key_exists( $template_hash, self::$stale_probe_memo ) ) {
				return self::$stale_probe_memo[ $template_hash ];
			}
			// Steady-state skip (issue #1235): with no stored variant
			// there is nothing to drop as stale, so skip the up-to-20
			// local file reads plus hashing on every frontend view. A
			// present variant is always probed regardless of mtime — a
			// freshly-written file can still be stale when its sources
			// changed, so an mtime-fresh skip would delay detection.
			try {
				if ( ! self::ccss_exists( $template_hash ) ) {
					self::$stale_probe_memo[ $template_hash ] = false;
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$result = false;
			try {
				// Fail-open gate: no user safelist configured and no
				// checksum-regen opt-in means the feature is off — preserve
				// the pre-#1038 behaviour (no regeneration).
				$checksum_regen = self::is_checksum_regen_enabled();
				if ( ( array() !== self::get_ccss_safelist() || $checksum_regen ) && function_exists( 'get_transient' ) ) {
					$stored = get_transient( self::get_source_checksum_key( $template_hash ) );
					if ( is_string( $stored ) && '' !== $stored ) {
						// Re-hash the exact document-ordered URL list persisted
						// at generation time so the probe cannot diverge from
						// the baseline (audit #9). Already-cached entries that
						// predate the persisted list fall back to the previous
						// $wp_styles-derived domain instead of being treated as
						// stale.
						$stored_urls = self::get_stored_source_urls( $template_hash );
						$source      = array() !== $stored_urls
							? self::build_local_source_css( $stored_urls )
							: self::get_local_source_css();
						if ( '' !== $source ) {
							$result = self::maybe_refresh_from_local_css( $template_hash, $source );
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$result = false;
			}
			self::$stale_probe_memo[ $template_hash ] = $result;
			return $result;
		}

		/**
		 * Checksum-gated CCSS regen on post save (issue #1388).
		 *
		 * Called from the `save_post` path (see Main::on_save_post_queue_used_css()):
		 * for every template with a stored variant AND a baselined source
		 * checksum, the locally-available source CSS (rebuilt from the exact
		 * document-ordered URL list persisted at generation time) is
		 * re-hashed. Only a checksum change drops the stale variant and
		 * queues a regen via regenerate_single(); a plain post save with
		 * unchanged CSS fires nothing. Local reads only — no remote fetch.
		 * Revisions and autosaves are skipped. Fail-open: any error returns
		 * false and stored variants are left in place (never fatal).
		 * Multisite-safe: checksum keys are blog-aware via
		 * Util::transient_key().
		 *
		 * @param int   $post_id Post ID.
		 * @param mixed $post    Post object or null.
		 * @return bool True when at least one stale template was requeued.
		 * @since 2.3.0
		 * @see Critical_CSS::maybe_refresh_from_local_css()
		 * @see Critical_CSS::regenerate_single()
		 */
		public static function maybe_regen_on_save( $post_id, $post = null ): bool {
			try {
				if ( function_exists( 'wp_is_post_revision' ) && wp_is_post_revision( $post_id ) ) {
					return false;
				}
				if ( function_exists( 'wp_is_post_autosave' ) && wp_is_post_autosave( $post_id ) ) {
					return false;
				}
				// Skip post types that can never affect frontend CSS (issue #1388
				// follow-up): media/menu/customizer writes are frequent on busy
				// sites and running the O(templates x source-files) re-hash for
				// them is pure waste. Fail-open: unknown types still probe.
				$post_type = '';
				if ( is_object( $post ) && isset( $post->post_type ) ) {
					$post_type = (string) $post->post_type;
				} elseif ( function_exists( 'get_post_type' ) ) {
					$post_type = (string) get_post_type( $post_id );
				}
				if ( in_array( $post_type, array( 'attachment', 'nav_menu_item', 'customize_changeset' ), true ) ) {
					return false;
				}
				if ( ! self::is_checksum_regen_enabled() ) {
					return false;
				}
				// Suspended while deferJS/delayJS is active: generated
				// variants could not be used, so skip the work (issue #1090).
				if ( self::is_deferral_suspended_by_js() ) {
					return false;
				}
				if ( ! function_exists( 'get_transient' ) ) {
					return false;
				}
				$templates = self::get_templates();
				if ( array() === $templates ) {
					return false;
				}
				$regened = false;
				foreach ( $templates as $template => $label ) {
					try {
						$template = (string) $template;
						$hash     = self::get_template_hash( $template );
						if ( ! self::ccss_exists( $hash ) ) {
							continue;
						}
						$stored = get_transient( self::get_source_checksum_key( $hash ) );
						if ( ! is_string( $stored ) || '' === $stored ) {
							continue;
						}
						// Re-hash the exact document-ordered URL list
						// persisted at generation time so the save-time
						// probe cannot diverge from the baseline.
						$stored_urls = self::get_stored_source_urls( $hash );
						if ( array() === $stored_urls ) {
							continue;
						}
						$source = self::build_local_source_css( $stored_urls );
						if ( '' === $source ) {
							continue;
						}
						if ( self::maybe_refresh_from_local_css( $hash, $source ) ) {
							self::regenerate_single( $template, $templates );
							$regened = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
				}
				return $regened;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Read core's `styles_inline_size_limit` budget.
		 *
		 * Delegates to the single shared implementation in
		 * {@see Util::get_styles_inline_limit()} so Cache and Critical_CSS
		 * cannot disagree during the 6.9 pre-release window.
		 *
		 * @return int The inline size limit in bytes.
		 * @since 2.0.0
		 */
		private static function get_styles_inline_limit(): int {
			return Util::get_styles_inline_limit();
		}

		/**
		 * Whether CCSS may be inlined on this request.
		 *
		 * Yields to the `wppo_inline_combined_css` falsy filter (same contract
		 * as Cache::register_combine_css_path()): operators who disabled
		 * plugin inlining get normally-enqueued stylesheets instead of inline
		 * critical CSS plus deferred stylesheets.
		 *
		 * @return bool True when inlining is allowed.
		 * @since 2.0.0
		 */
		private static function is_inline_allowed(): bool {
			if ( ! function_exists( 'apply_filters' ) ) {
				return true;
			}
			return (bool) apply_filters( 'wppo_inline_combined_css', true );
		}

		/**
		 * Whether stylesheet deferral is suspended by deferred/delayed JS.
		 *
		 * When `file_optimisation.deferJS` or `file_optimisation.delayJS` is
		 * active, the `media=print` + `onload` swap in defer_stylesheets()
		 * never fires until JS runs, so deferral is skipped to keep cached
		 * HTML styled. Critical CSS only helps when deferral is active, so
		 * this predicate gates emission and generation too (issue #1090).
		 *
		 * @return bool True when deferJS or delayJS is enabled.
		 * @since 2.2.0
		 */
		public static function is_deferral_suspended_by_js(): bool {
			$options = Util::get_settings();
			$fo      = $options['file_optimisation'] ?? array();
			return ! empty( $fo['deferJS'] ) || ! empty( $fo['delayJS'] );
		}

		/**
		 * Whether critical CSS is effective on this request.
		 *
		 * Single shared predicate for the Critical-CSS contract: inlining
		 * must be allowed AND deferral must be available. When deferral is
		 * suspended by deferred/delayed JS, emitting critical CSS only adds
		 * redundant weight (issue #1090).
		 *
		 * @return bool True when CCSS emission and deferral should run.
		 * @since 2.2.0
		 */
		public static function is_ccss_effective(): bool {
			return self::is_inline_allowed() && ! self::is_deferral_suspended_by_js();
		}

		/**
		 * Async loadCSS fallback snippet as a script tag (audit #1392).
		 *
		 * Single canonical copy: two output paths shared one hand-maintained
		 * string that drifted independently. Timer handles clear on
		 * pagehide/beforeunload (audit #1077 finding 5).
		 *
		 * @since 2.2.0
		 * @return string Inline loader script tag.
		 */
		private static function loadcss_loader_tag(): string {
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- inline loadCSS polyfill; no registered handle exists.
			return '<script>!function(e){"use strict";var T=[],c=function(h){var i=T.indexOf(h);if(i>-1){T.splice(i,1)}clearTimeout(h)},n=function(n,t,o){var r=e.document.createElement("link"),a=t||e.document.getElementsByTagName("script")[0];r.rel="stylesheet",r.href=n,r.media="only x",a.parentNode.insertBefore(r,a);var h=setTimeout(function(){c(h),r.media=o||"all"},0);T.push(h),r.onload=function(){c(h),r.media=o||"all"}};e.wppoLoadCSS=n;var f=function(){for(var i=0;i<T.length;i++){clearTimeout(T[i])}T.length=0};e.addEventListener("pagehide",f),e.addEventListener("beforeunload",f)}(window);</script>' . "\n";
		}

		/**
		 * Whether a strict Content-Security-Policy blocks raw inline scripts.
		 *
		 * Mirrors Used_CSS::has_strict_csp() for the header layer (audit
		 * #1325): inspects headers_list() for a CSP without 'unsafe-inline'.
		 * A nonce must never be baked here because this output enters the
		 * static page cache — callers downgrade to blocking file delivery
		 * instead (fail-open, never unstyled).
		 *
		 * @since 2.2.0
		 * @return bool True when inline scripts would be blocked.
		 */
		private static function has_strict_csp(): bool {
			try {
				/**
				 * Filters whether a server-level strict CSP is active for CCSS delivery.
				 *
				 * Return true when a Content-Security-Policy without
				 * 'unsafe-inline' is enforced outside PHP
				 * (htaccess/Nginx/hosting headers), which headers_list()
				 * cannot detect.
				 *
				 * @since 2.2.0
				 * @param bool $strict_csp Whether a server-level strict CSP is active.
				 */
				if ( function_exists( 'apply_filters' ) && (bool) apply_filters( 'wppo_critical_css_strict_csp', false ) ) {
					return true;
				}
				if ( function_exists( 'headers_list' ) ) {
					foreach ( headers_list() as $header ) {
						if ( 0 !== stripos( (string) $header, 'content-security-policy' ) ) {
							continue;
						}
						if ( false === stripos( (string) $header, 'unsafe-inline' ) ) {
							return true;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_file_url}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return string Result (see Ccss_Store).
		 */
		private static function get_ccss_file_url( string $template_hash ): string {
			return Ccss_Store::get_ccss_file_url( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_meta}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return array Result (see Ccss_Store).
		 */
		public static function get_ccss_meta( string $template_hash ): array {
			return Ccss_Store::get_ccss_meta( $template_hash );
		}

		/**
		 * Reset the per-request CCSS memos (facade, FUT-001).
		 *
		 * Generation-domain half lives here; storage-domain half lives on
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::reset_ccss_memo()}.
		 * Both halves are cleared so existing callers stay identical.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_ccss_memo(): void {
			self::$stale_probe_memo               = array();
			self::$ccss_presets_memo              = null;
			self::$lcp_preload_emitted            = array();
			self::$ccss_defer_blocked             = array();
			self::$pending_memo                   = array();
			self::$gzip_size_memo                 = array();
			self::$commerce_context_memo          = null;
			self::$commerce_excluded_context_memo = null;
			Ccss_Store::reset_ccss_memo();
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::invalidate_ccss_memo}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 */
		private static function invalidate_ccss_memo( string $template_hash ): void {
			Ccss_Store::invalidate_ccss_memo( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::ccss_exists}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return bool Result (see Ccss_Store).
		 */
		public static function ccss_exists( string $template_hash ): bool {
			return Ccss_Store::ccss_exists( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_ccss_content}.
		 *
		 * @since NEXT
		 * @param string $template_hash Parameter (see Ccss_Store).
		 * @return ?string Result (see Ccss_Store).
		 */
		private static function get_ccss_content( string $template_hash ): ?string {
			return Ccss_Store::get_ccss_content( $template_hash );
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_status_all}.
		 *
		 * @since NEXT
		 * @return array Result (see Ccss_Store).
		 */
		public static function get_status_all(): array {
			return Ccss_Store::get_status_all();
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_templates}.
		 *
		 * @since NEXT
		 * @return array Result (see Ccss_Store).
		 */
		private static function get_templates(): array {
			return Ccss_Store::get_templates();
		}

		/**
		 * Facade proxy (FUT-001): canonical owner is
		 * {@see \PerformanceOptimise\Inc\Ccss_Store::get_sample_url}.
		 *
		 * @since NEXT
		 * @param string $template Parameter (see Ccss_Store).
		 * @return string|false Result (see Ccss_Store).
		 */
		private static function get_sample_url( string $template ): string|false {
			return Ccss_Store::get_sample_url( $template );
		}

		/**
		 * Field-measured LCP image preload target for a page URL (issue #1255).
		 *
		 * Resolves the RUM field-LCP candidate for the page's path via
		 * RUM::get_lcp_preload_candidate(): the top real-user LCP URL wins only
		 * above the minimum sample count and while fresh (both gates are
		 * enforced inside RUM::get_field_lcp_url()); otherwise the stored
		 * PageSpeed heuristic wins. A null $url resolves the current request
		 * path the same way the image pipeline does. Fail-open: any missing
		 * class/method, unparseable URL, unverifiable origin, or internal
		 * failure returns '' so callers emit nothing. Multisite-safe: RUM
		 * aggregates are per-site options and per-path transient keys go
		 * through Util::transient_key() (blog-aware), so no data leaks across
		 * sites. No API keys required; missing RUM data falls back to the
		 * heuristic candidate, never fatal.
		 *
		 * Only the path component of an explicit $url is used (RUM buckets
		 * are per-site): an explicit URL carrying another host resolves to
		 * '' instead of this site's bucket for that path, so future callers
		 * cannot mix two sites' measurements. The returned candidate must
		 * also look like an image (see is_image_preload_url(), mirroring the
		 * image pipeline's text-LCP guard): a same-origin non-image URL is
		 * never preloaded as an image.
		 *
		 * @param string|null $url Page URL. Null resolves the current request path. Only the path is used.
		 * @return string Same-origin LCP image URL, or '' when none resolves.
		 * @since 2.2.0
		 * @see RUM::get_lcp_preload_candidate()
		 * @see RUM::get_field_lcp_url()
		 */
		public static function get_field_lcp_preload_url( ?string $url = null ): string {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_lcp_preload_candidate' ) ) {
					return '';
				}
				$path = null;
				if ( null !== $url ) {
					if ( '' === trim( $url ) ) {
						return '';
					}
					if ( function_exists( 'wp_parse_url' ) ) {
						$parts = wp_parse_url( $url );
					} elseif ( function_exists( 'parse_url' ) ) {
						$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when core's wrapper is unavailable.
					} else {
						return '';
					}
					if ( ! is_array( $parts ) ) {
						return '';
					}
					// Per-site buckets: an explicit URL on another host must
					// not resolve this site's bucket for that path.
					if ( isset( $parts['host'] ) && is_string( $parts['host'] ) && '' !== trim( $parts['host'] ) ) {
						$explicit_host = strtolower( trim( $parts['host'] ) );
						$home_host     = '';
						try {
							if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'cached_home_url' ) && function_exists( 'wp_parse_url' ) ) {
								$home_host = strtolower( (string) wp_parse_url( \PerformanceOptimise\Inc\Util::cached_home_url(), PHP_URL_HOST ) );
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
						if ( '' === $home_host || $explicit_host !== $home_host ) {
							return '';
						}
					}
					$raw_path = isset( $parts['path'] ) && is_string( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';
					$path     = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_rum_path' )
						? \PerformanceOptimise\Inc\Util::normalize_rum_path( substr( $raw_path, 0, 512 ) )
						: $raw_path;
				}
				$candidate = \PerformanceOptimise\Inc\RUM::get_lcp_preload_candidate( $path );
				if ( ! is_array( $candidate ) || empty( $candidate['url'] ) || ! is_string( $candidate['url'] ) ) {
					return '';
				}
				$lcp = trim( $candidate['url'] );
				if ( '' === $lcp ) {
					return '';
				}
				// Emission-path origin proof (strict: unverifiable means reject
				// so a preload <link> is never emitted for a cross-origin or
				// scheme-like URL). The candidate tiers already check the
				// legacy verdict; this re-check covers pre-guard rows.
				if ( method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url_strict' ) ) {
					if ( ! \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( $lcp ) ) {
						return '';
					}
				}
				// Text-LCP guard (issue #1255 review): the stored PageSpeed
				// tiers validate same-origin but never image-ness, so a
				// same-origin non-image candidate must not be preloaded as
				// an image (wasted high-priority fetch).
				if ( ! self::is_image_preload_url( $lcp ) ) {
					return '';
				}
				return $lcp;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether a candidate URL is a plausible LCP image (text-LCP guard).
		 *
		 * Mirrors Image_Optimisation::is_image_lcp_url() (kept local so this
		 * static context never depends on that instance pipeline): data/,
		 * blob: and script-scheme URLs are refused, and the URL must either
		 * map to a known image MIME type, carry an image file extension, or
		 * (for extensionless image-CDN URLs) carry image-ish query params.
		 * Fail-open for the page (returns false so callers emit nothing),
		 * never fatal.
		 *
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded as an image.
		 * @since 2.2.0
		 */
		private static function is_image_preload_url( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				$lower = strtolower( ltrim( $url ) );
				if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) ) {
					return false;
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_image_mime_type' ) ) {
					if ( '' !== \PerformanceOptimise\Inc\Util::get_image_mime_type( $url ) ) {
						return true;
					}
				}
				$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when wp_parse_url() is unavailable (unit contexts).
				if ( is_string( $path ) && '' !== $path && 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|heic|heif|jxl)$/i', $path ) ) {
					return true;
				}
				$query = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_QUERY ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when wp_parse_url() is unavailable (unit contexts).
				if ( is_string( $query ) && '' !== $query ) {
					if ( 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|heic|heif|jxl)/i', $query ) ) {
						return true;
					}
					if ( 1 === preg_match( '/(^|&)(w|h|width|height|format|fit|crop|resize|quality)(=|&|$)/i', $query ) ) {
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
		 * Whether the image pipeline owns LCP preloading on this request.
		 *
		 * The pipeline's wp_head:1 preload (Image_Optimisation::preload_images())
		 * emits the same RUM-field → PageSpeed candidate with responsive
		 * imagesrcset/imagesizes; its auto-LCP path runs only when
		 * `image_optimisation.autoPreloadLCP` or
		 * `preload_settings.autoLcpPreload` is enabled. When either is on,
		 * the CCSS-path hint (wp_head:0) yields so
		 * exactly one preload prints. Fail-open: any failure returns false
		 * (CCSS path emits normally).
		 *
		 * @return bool True when the image pipeline will preload the LCP hero.
		 * @since 2.2.0
		 */
		private static function is_image_pipeline_lcp_preload_active(): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					return false;
				}
				$settings = \PerformanceOptimise\Inc\Util::get_settings();
				if ( ! is_array( $settings ) ) {
					return false;
				}
				$image_optimisation = $settings['image_optimisation'] ?? array();
				$preload_settings   = $settings['preload_settings'] ?? array();
				if ( ! is_array( $image_optimisation ) ) {
					$image_optimisation = array();
				}
				if ( ! is_array( $preload_settings ) ) {
					$preload_settings = array();
				}
				return ! empty( $image_optimisation['autoPreloadLCP'] ) || ! empty( $preload_settings['autoLcpPreload'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the CCSS-path field-LCP preload may fire on this request.
		 *
		 * Operator opt-out for the worst-first hero hint (issue #1255
		 * review): this preload belongs to the critical-CSS feature and
		 * fires independently of the image-pipeline LCP toggles, so owners
		 * who disabled every automatic preload need a kill switch that does
		 * not require disabling critical CSS itself. Filterable via
		 * `wppo_ccss_field_lcp_preload` when a listener is registered
		 * (default true); any filter failure keeps the default.
		 *
		 * @return bool True when the CCSS-path preload may emit.
		 * @since 2.2.0
		 * @see Critical_CSS::maybe_emit_field_lcp_preload()
		 */
		private static function is_ccss_field_lcp_preload_allowed(): bool {
			try {
				if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_field_lcp_preload' ) ) {
					return (bool) apply_filters( 'wppo_ccss_field_lcp_preload', true );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return true;
		}

		/**
		 * Emit the field-measured LCP image preload for the current page.
		 *
		 * Resolves the preload target via get_field_lcp_preload_url() (field
		 * candidate wins over the PageSpeed heuristic only above the minimum
		 * sample count and freshness TTL) and emits one `<link rel="preload"
		 * as="image" fetchpriority="high">` through the shared
		 * Util::generate_preload_link() helper so markup matches the image
		 * pipeline. Emitted at most once per normalized URL per request;
		 * a no-op when no candidate resolves. Fail-open: any failure emits
		 * nothing, never fatal.
		 *
		 * Single-emitter coordination (issue #1255 review): the image
		 * pipeline preloads the same candidate at wp_head:1 with responsive
		 * imagesrcset/imagesizes, so when its auto-LCP path is enabled this
		 * earlier (wp_head:0) path yields and the pipeline owns the hint;
		 * otherwise this path emits and claims the URL in the pipeline's
		 * shared dedup set (Image_Optimisation::has/mark_preload_emitted())
		 * so the later pipeline run skips the duplicate. This hint belongs
		 * to the critical-CSS feature and is independent of the
		 * image-pipeline LCP toggles — disable it via the
		 * `wppo_ccss_field_lcp_preload` filter (see
		 * is_ccss_field_lcp_preload_allowed()).
		 *
		 * @return void
		 * @since 2.2.0
		 * @see Critical_CSS::get_field_lcp_preload_url()
		 * @see Critical_CSS::is_ccss_field_lcp_preload_allowed()
		 */
		private static function maybe_emit_field_lcp_preload(): void {
			try {
				if ( ! self::is_ccss_field_lcp_preload_allowed() ) {
					return;
				}
				if ( self::is_image_pipeline_lcp_preload_active() ) {
					return;
				}
				$lcp = self::get_field_lcp_preload_url();
				if ( '' === $lcp ) {
					return;
				}
				// Shared dedup: the pipeline may already have emitted this
				// URL (manual/meta/front-page preloads run off-toggle), in
				// which case there is nothing left to hint.
				$shared_available = class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' )
					&& method_exists( 'PerformanceOptimise\Inc\Image_Optimisation', 'has_emitted_preload' )
					&& method_exists( 'PerformanceOptimise\Inc\Image_Optimisation', 'mark_preload_emitted' );
				if ( $shared_available && \PerformanceOptimise\Inc\Image_Optimisation::has_emitted_preload( $lcp ) ) {
					return;
				}
				$key = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_url' )
					? \PerformanceOptimise\Inc\Util::normalize_url( $lcp )
					: $lcp;
				$key = is_string( $key ) && '' !== $key ? $key : $lcp;
				if ( isset( self::$lcp_preload_emitted[ $key ] ) ) {
					return;
				}
				self::$lcp_preload_emitted[ $key ] = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'generate_preload_link' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_image_mime_type' ) ) {
					$imagesrcset = '';
					$imagesizes  = '';
					try {
						if ( class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) && method_exists( 'PerformanceOptimise\Inc\Image_Optimisation', 'get_lcp_responsive_data_for_url' ) ) {
							$responsive  = \PerformanceOptimise\Inc\Image_Optimisation::get_lcp_responsive_data_for_url( $lcp );
							$imagesrcset = is_array( $responsive ) ? (string) ( $responsive['srcset'] ?? '' ) : '';
							$imagesizes  = is_array( $responsive ) ? (string) ( $responsive['sizes'] ?? '' ) : '';
							if ( '' === $imagesrcset || '' === $imagesizes ) {
								$imagesrcset = '';
								$imagesizes  = '';
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$imagesrcset = '';
						$imagesizes  = '';
					}
					\PerformanceOptimise\Inc\Util::generate_preload_link(
						$lcp,
						'preload',
						'image',
						false,
						\PerformanceOptimise\Inc\Util::get_image_mime_type( $lcp ),
						'',
						'high',
						$imagesrcset,
						$imagesizes
					);
					if ( $shared_available ) {
						\PerformanceOptimise\Inc\Image_Optimisation::mark_preload_emitted( $lcp );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Extract inline `<style>` bodies and stylesheet hrefs via the HTML API.
		 *
		 * Streaming counterpart of the DOMDocument block inside {@see generate()}:
		 * walks `WP_HTML_Processor` tokens with a deadline poll every 16
		 * tokens (plus one up-front check, so an already-expired budget
		 * aborts without walking) and an HTML5-spec parse of malformed
		 * markup (missing closers, SVG, nested tables). Returns null on any
		 * failure so the caller keeps the unchanged DOMDocument path
		 * (WP 6.2-6.8 parity).
		 *
		 * Only document-ordered discovery happens here; stylesheet fetching
		 * stays in `generate()` so both paths share one fetch budget.
		 * Per-URL/per-template extraction only; no cross-site state.
		 *
		 * Parity notes: `<link>` discovery requires the whole `rel` value
		 * to equal `stylesheet` case-insensitively (trim + single
		 * comparison), mirroring the DOM `//link[@rel="stylesheet"]`
		 * exact-match intent while following the HTML spec (ASCII
		 * case-insensitive `rel`); multi-token values such as
		 * `alternate stylesheet` are therefore excluded on both paths.
		 * The single intentional widening is case-folding: `REL="STYLESHEET"`
		 * is collected by the stream (spec-correct, browsers match it) but
		 * missed by the case-sensitive XPath. Inline `<style>` bodies keep
		 * raw token text on both paths (`<style>` is rawtext: neither the
		 * stream's `serialize_token()` nor DOM `textContent` decodes
		 * entities, and comment markers stay literal), so no
		 * decode/strip step is needed for parity.
		 *
		 * @since 2.3.0
		 *
		 * @param string     $html     Page HTML (already byte-capped by the caller).
		 * @param float|null $deadline Absolute deadline, or null when uncapped.
		 * @return array{inline_css: string, source_urls: string[]}|null Inline CSS plus
		 *                                                            document-ordered hrefs, or null to take the DOM path.
		 */
		private static function extract_css_sources_with_processor( string $html, ?float $deadline ): ?array {
			if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'should_use_html_processor' ) ) {
				return null;
			}
			if ( ! \PerformanceOptimise\Inc\Util::should_use_html_processor() ) {
				return null;
			}
			if ( ! class_exists( 'WP_HTML_Processor' ) ) {
				return null;
			}
			$required = array( 'next_token', 'get_token_type', 'get_tag', 'is_tag_closer', 'get_attribute', 'serialize_token' );
			foreach ( $required as $method ) {
				if ( ! method_exists( 'WP_HTML_Processor', $method ) ) {
					return null;
				}
			}
			if ( strlen( $html ) > self::MAX_CCSS_SOURCE_BYTES ) {
				$html = substr( $html, 0, self::MAX_CCSS_SOURCE_BYTES );
			}
			try {
				$processor = \PerformanceOptimise\Inc\Util::create_html_processor( $html );
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
			if ( null === $processor || ! ( $processor instanceof \WP_HTML_Processor ) ) {
				return null;
			}
			try {
				$inline_css    = '';
				$source_urls   = array();
				$in_style      = false;
				$style_buffer  = '';
				$inline_capped = false;
				// Fail-open up front: an already-exhausted budget never pays
				// for even the streaming walk (mirrors the DOM-path re-check).
				if ( self::generation_expired( $deadline ) ) {
					return null;
				}
				$tokens_seen = 0;
				while ( $processor->next_token() ) {
					// Poll every 16 tokens (counter & mask): deadline precision
					// loss is negligible for a ~120s budget and avoids a
					// microtime() syscall per token on large pages.
					++$tokens_seen;
					if ( 0 === ( $tokens_seen & 15 ) && self::generation_expired( $deadline ) ) {
						return null;
					}
					if ( '#tag' !== $processor->get_token_type() ) {
						if ( $in_style && ! $inline_capped ) {
							$style_buffer .= (string) $processor->serialize_token();
						}
						continue;
					}
					$tag_name  = strtolower( (string) $processor->get_tag() );
					$is_closer = $processor->is_tag_closer();
					if ( 'style' === $tag_name ) {
						if ( ! $is_closer ) {
							$in_style     = true;
							$style_buffer = '';
						} elseif ( $in_style ) {
							// `<style>` is rawtext on both paths: keep the raw
							// token text so it matches DOM `textContent`
							// (neither decodes entities).
							$in_style     = false;
							$content      = trim( $style_buffer );
							$style_buffer = '';
							if ( '' !== $content && ! $inline_capped ) {
								$inline_css .= $content . "\n";
								if ( strlen( $inline_css ) > self::MAX_CCSS_SOURCE_BYTES ) {
									$inline_css    = substr( $inline_css, 0, self::MAX_CCSS_SOURCE_BYTES );
									$inline_capped = true;
								}
							}
						}
						continue;
					}
					if ( 'link' !== $tag_name || $is_closer ) {
						continue;
					}
					$rel = $processor->get_attribute( 'rel' );
					if ( ! is_string( $rel ) || '' === $rel ) {
						continue;
					}
					// Parity with `//link[@rel="stylesheet"]`: the whole rel
					// value must equal `stylesheet` (case-insensitive trim).
					// Multi-token values (`alternate stylesheet`,
					// `stylesheet preload`) are excluded on both paths.
					$rel_norm = strtolower( trim( (string) $rel ) );
					if ( 'stylesheet' !== $rel_norm ) {
						continue;
					}
					$href = $processor->get_attribute( 'href' );
					if ( ! is_string( $href ) || '' === $href ) {
						continue;
					}
					if ( self::is_skipped_source_url( $href ) ) {
						continue;
					}
					$source_urls[] = $href;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
			if ( method_exists( $processor, 'get_last_error' ) && null !== $processor->get_last_error() ) {
				return null;
			}
			// Malformed pages may never close <style>: DOMDocument auto-closes
			// the element, so flush the trailing buffer for parity.
			// Raw text like the closed-block path above (rawtext parity).
			if ( $in_style ) {
				$content = trim( $style_buffer );
				if ( '' !== $content && ! $inline_capped ) {
					$inline_css .= $content . "\n";
					if ( strlen( $inline_css ) > self::MAX_CCSS_SOURCE_BYTES ) {
						$inline_css = substr( $inline_css, 0, self::MAX_CCSS_SOURCE_BYTES );
					}
				}
			}
			return array(
				'inline_css'  => $inline_css,
				'source_urls' => $source_urls,
			);
		}

		/**
		 * Generate critical CSS for a given URL.
		 *
		 * Fetches the HTML, extracts CSS resources and inline styles, downloads
		 * external CSS (resolving @import directives), and applies heuristic
		 * above-fold rule extraction.
		 *
		 * @param string      $url          The page URL to generate CCSS for.
		 * @param string|null $source_css   Out-param: canonical local-source CSS
		 *                                  (locally-resolvable external
		 *                                  stylesheets, document order) used for
		 *                                  the freshness checksum (issue #1038).
		 * @param array|null  $resolved_urls Out-param: the exact document-ordered
		 *                                  `//link[@rel=stylesheet]` href list the
		 *                                  fetch saw, persisted so the runtime
		 *                                  probe re-hashes the same list (audit #9).
		 *                                  Pass a non-null array to receive it;
		 *                                  null opts out and receives nothing.
		 * @param float|null  $deadline      Optional absolute wall-clock deadline
		 *                                  (see generation_deadline()).
		 *                                  Trusted callers only: external
		 *                                  deadlines clamp to now +
		 *                                  MAX_CCSS_GEN_TIMEOUT so a caller
		 *                                  can never smuggle an unbounded
		 *                                  budget. When null a fresh
		 *                                  get_ccss_gen_timeout() budget
		 *                                  applies; expired runs abort fail-open
		 *                                  (issue #1235).
		 * @return string|false The critical CSS content, or false on failure.
		 * @since 2.0.0
		 * @since 2.2.0 Time-boxed by the generation budget with fail-open abort.
		 */
		public static function generate( string $url, ?string &$source_css = null, ?array &$resolved_urls = null, ?float $deadline = null ) {
			// SSRF guard: reuse the stylesheet allowlist (same-site + scheme).
			// function_exists() keeps unit-test doubles working; production
			// core always defines wp_http_validate_url().
			if ( function_exists( 'wp_http_validate_url' ) && ! self::is_safe_stylesheet_url( $url ) ) {
				return false;
			}
			// Generation budget (issue #1235): a slow or hung source-CSS fetch
			// must never stall the caller unboundedly. Direct callers that
			// pass no deadline get a fresh budget; generate_and_store() and
			// generate_guarded() pass their own so one budget spans the run.
			if ( null === $deadline ) {
				try {
					$deadline = self::generation_deadline( self::get_ccss_gen_timeout() );
				} catch ( \Throwable $e ) {
					unset( $e );
					$deadline = null;
				}
			} else {
				// Clamp caller-supplied deadlines so an unbounded external
				// value can never bypass the 1..120 budget (issue #1235 review).
				// Non-finite callers (NAN/INF) clamp to now + MAX: min(NAN, x)
				// stays NAN and now >= NAN is always false, which would
				// otherwise leave the run unbounded.
				try {
					$now = self::generation_now();
					if ( ! is_finite( $deadline ) ) {
						$deadline = $now + (float) self::MAX_CCSS_GEN_TIMEOUT;
					} else {
						$deadline = min( $deadline, $now + (float) self::MAX_CCSS_GEN_TIMEOUT );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( self::generation_expired( $deadline ) ) {
				return false;
			}
			// is_safe_stylesheet_url() above is the SSRF control and is
			// stricter than WP's IP-range check. Do NOT also set
			// reject_unsafe_urls: WP's validator rejects every private,
			// loopback and non-dotted host, so it would break critical CSS
			// generation on localhost/staging installs (and on any site
			// whose own hostname resolves privately) without adding
			// protection the same-site check does not already provide.
			// The page fetch is clamped to the remaining budget so one hung
			// socket cannot consume the whole generation window. A zero
			// timeout means the budget is already exhausted: skip the
			// request instead of blocking past expiry.
			$page_timeout = self::request_timeout_for_deadline( $deadline, 30 );
			if ( 0 === $page_timeout ) {
				return false;
			}
			$response = wp_remote_get(
				$url,
				array(
					'timeout'             => $page_timeout,
					// Bound the HTTP layer so a multi-MB page can never
					// materialize fully in memory before truncation (issue #1235).
					'limit_response_size' => self::MAX_CCSS_SOURCE_BYTES,
					'user-agent'          => 'WPPO Critical CSS Generator/' . WPPO_VERSION,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
				return false;
			}
			// Hard-cap HTML before DOMDocument::loadHTML() (issue #1235):
			// the synchronous parse has no mid-parse deadline poll, so a
			// malformed multi-MB page could otherwise stall seconds before
			// the next generation_expired() check. Known gap: loadHTML()
			// itself remains uninterruptible once started.
			if ( strlen( $html ) > self::MAX_CCSS_SOURCE_BYTES ) {
				$html = substr( $html, 0, self::MAX_CCSS_SOURCE_BYTES );
			}
			if ( self::generation_expired( $deadline ) ) {
				return false;
			}

			// Streaming HTML API path (issue #1430): HTML5-spec token walk
			// with a deadline poll on every token. Null means unavailable or
			// failed: fall through to the unchanged DOMDocument path below
			// (WP 6.2-6.8 parity, byte-identical fallback).
			$processor_sources = self::extract_css_sources_with_processor( $html, $deadline );
			if ( null !== $processor_sources ) {
				$css_content = $processor_sources['inline_css'];
				$source_urls = $processor_sources['source_urls'];
				// Shared @import dedupe + fetch budget for the whole run (issue #1235).
				$import_seen    = array();
				$import_fetches = 0;
				foreach ( $source_urls as $href ) {
					// Budget check per stylesheet (issue #1235): abort the
					// whole run fail-open instead of starting another fetch
					// with no time left to parse its result.
					if ( self::generation_expired( $deadline ) ) {
						return false;
					}
					$fetched = self::fetch_stylesheet_with_imports( $href, 0, $deadline, $import_seen, $import_fetches );
					if ( '' !== $fetched ) {
						$css_content .= $fetched . "\n";
						// Bound the concatenated source (issue #1235 review):
						// stop appending once the scan buffer exceeds 2MB so
						// a huge theme cannot OOM the worker before polling.
						if ( strlen( $css_content ) > self::MAX_CCSS_SOURCE_BYTES ) {
							break;
						}
					}
					// Stop before the buffer grows further past the deadline.
					if ( strlen( $css_content ) > self::MAX_CCSS_SOURCE_BYTES || self::generation_expired( $deadline ) ) {
						break;
					}
				}
			} else {
				if ( self::generation_expired( $deadline ) ) {
					return false;
				}
				// Suppress libxml noise while parsing, but always restore the
				// previous global state afterwards (audit #888 finding 1).
				$prev_libxml = libxml_use_internal_errors( true );

				try {
					$dom = new \DOMDocument();
					$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
				} finally {
					libxml_clear_errors();
					libxml_use_internal_errors( $prev_libxml );
				}

				$xpath = new \DOMXPath( $dom );

				$css_content = '';

				// Extract inline <style> blocks.
				$style_tags = $xpath->query( '//style' );
				if ( $style_tags ) {
					foreach ( $style_tags as $tag ) {
						// Deadline poll (issue #1235 review): many/large inline
						// blocks must not overrun before the next fetch poll.
						if ( self::generation_expired( $deadline ) ) {
							return false;
						}
						// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property.
						$content = trim( $tag->textContent );
						if ( ! empty( $content ) ) {
							$css_content .= $content . "\n";
							if ( strlen( $css_content ) > self::MAX_CCSS_SOURCE_BYTES ) {
								$css_content = substr( $css_content, 0, self::MAX_CCSS_SOURCE_BYTES );
								break;
							}
						}
					}
				}

				// Extract external stylesheet URLs (skip data-* handles, dashicons, admin-bar).
				$link_tags   = $xpath->query( '//link[@rel="stylesheet"]' );
				$source_urls = array();
				// Shared @import dedupe + fetch budget for the whole run (issue #1235).
				$import_seen    = array();
				$import_fetches = 0;
				if ( $link_tags ) {
					foreach ( $link_tags as $tag ) {
						$href = $tag->getAttribute( 'href' );
						if ( empty( $href ) ) {
							continue;
						}
						if ( self::is_skipped_source_url( $href ) ) {
							continue;
						}

						// Record the document-ordered stylesheet set used for the
						// canonical source checksum (issue #1038). Only locally
						// resolvable stylesheets contribute; inline <style> blocks
						// and @import expansion are deliberately excluded so the
						// frontend probe can reproduce this exact domain without a
						// remote fetch. Handles core path-inlines (via
						// wp_style_add_data(...,'path',...)) emit no <link> here and
						// are therefore absent — the probe mirrors that by skipping
						// them in get_local_source_css(). build_local_source_css()
						// then collapses the deferred-link + <noscript> duplicate.
						$source_urls[] = $href;

						// Budget check per stylesheet (issue #1235): abort the
						// whole run fail-open instead of starting another fetch
						// with no time left to parse its result.
						if ( self::generation_expired( $deadline ) ) {
							return false;
						}

						$fetched = self::fetch_stylesheet_with_imports( $href, 0, $deadline, $import_seen, $import_fetches );
						if ( '' !== $fetched ) {
							$css_content .= $fetched . "\n";
							// Bound the concatenated source (issue #1235 review):
							// stop appending once the scan buffer exceeds 2MB so
							// a huge theme cannot OOM the worker before polling.
							if ( strlen( $css_content ) > self::MAX_CCSS_SOURCE_BYTES ) {
								break;
							}
						}
						// Stop before the buffer grows further past the deadline.
						if ( strlen( $css_content ) > self::MAX_CCSS_SOURCE_BYTES || self::generation_expired( $deadline ) ) {
							break;
						}
					}
				}
			} // End DOMDocument fallback for the streaming path above.

			// Canonical source domain: the locally-resolvable stylesheets the
			// page emitted, in document order. generate_and_store() baselines
			// the checksum of this string AND persists $source_urls so the
			// frontend probe re-hashes the exact same document-ordered list
			// instead of re-deriving one from $wp_styles order (audit #9), so
			// an unchanged source compares equal instead of churning forever.
			$source_css = self::build_local_source_css( $source_urls, $deadline );
			if ( null !== $resolved_urls ) {
				$resolved_urls = $source_urls;
			}

			if ( empty( $css_content ) ) {
				return false;
			}
			if ( self::generation_expired( $deadline ) ) {
				return false;
			}

			// Apply heuristic extraction: keep only above-fold rules.
			$critical = self::extract_above_fold_css( $css_content, $deadline );

			if ( empty( $critical ) ) {
				return false;
			}
			// The parse phase may have consumed the rest of the budget — never
			// minify or return output past the deadline (issue #1235).
			if ( self::generation_expired( $deadline ) ) {
				return false;
			}

			// Minify the critical CSS. The CPU-heavy tail stays inside the
			// budget (issue #1235 review): expiry is checked before and
			// after minification so a large payload cannot spend seconds
			// past the deadline holding the worker. CSSMinifier::minify()
			// itself is atomic and uninterruptible, so the input is gated
			// to the scan budget first — a ~2MB buffer is the worst case
			// that can enter the uninterruptible section.
			if ( self::generation_expired( $deadline ) ) {
				return false;
			}
			if ( strlen( $critical ) > self::MAX_CCSS_SOURCE_BYTES ) {
				$critical = substr( $critical, 0, self::MAX_CCSS_SOURCE_BYTES );
			}
			try {
				$minifier = new CSSMinifier( $critical );
				$critical = $minifier->minify();
			} catch ( \Exception $e ) {
				// Fall back to unminified if minification fails — $critical stays as-is.
				unset( $e );
			}
			if ( self::generation_expired( $deadline ) ) {
				return false;
			}

			return $critical;
		}

		/**
		 * Fetch a stylesheet and recursively resolve @import directives.
		 *
		 * Each request is clamped to the remaining generation budget so a
		 * hung stylesheet fails fast; an expired budget returns '' without
		 * any further request (issue #1235).
		 *
		 * @param string     $url      The stylesheet URL.
		 * @param int        $depth    Current recursion depth.
		 * @param float|null $deadline Optional absolute wall-clock deadline.
		 * @param array      $seen     By-ref case-insensitive visited-URL set shared
		 *                             across the whole generation run (cycle dedupe).
		 * @param int        $fetches  By-ref total-fetch counter shared across the
		 *                             whole run, bounded by MAX_CCSS_FETCHES.
		 * @return string The combined CSS content with @imports inlined, or empty string on failure or expiry.
		 * @since 2.0.0
		 * @since 2.2.0 Budget-clamped request timeouts with fail-open abort.
		 */
		private static function fetch_stylesheet_with_imports( string $url, int $depth = 0, ?float $deadline = null, array &$seen = array(), int &$fetches = 0 ): string {
			if ( $depth > self::MAX_IMPORT_DEPTH ) {
				return '';
			}
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}

			// SSRF guard: refuse invalid or internal targets before any request.
			if ( ! self::is_safe_stylesheet_url( $url ) ) {
				return '';
			}
			// Cycle dedupe + total-fetch budget (issue #1235 review): a shared
			// case-insensitive visited set skips repeats, and the run-wide
			// counter caps fan-out at MAX_CCSS_FETCHES so fast origins cannot
			// burn hundreds of requests inside one budget.
			$seen_key = strtolower( $url );
			if ( isset( $seen[ $seen_key ] ) ) {
				return '';
			}
			if ( $fetches >= self::MAX_CCSS_FETCHES ) {
				return '';
			}
			$seen[ $seen_key ] = true;
			++$fetches;

			$request_timeout = self::request_timeout_for_deadline( $deadline, 15 );
			if ( 0 === $request_timeout ) {
				return '';
			}
			$args = array(
				'timeout'             => $request_timeout,
				// Bound the HTTP layer so a multi-MB sheet never materializes
				// fully in memory before truncation (issue #1235).
				'limit_response_size' => self::MAX_CCSS_FILE_BYTES,
				'user-agent'          => 'WPPO Critical CSS Generator/' . WPPO_VERSION,
			);

			// Own-host fetches may legitimately target loopback/private addresses
			// (localhost / private-IP dev sites); every other host goes through
			// the safe API so redirect hops are re-validated against private ranges.
			$response = self::is_same_site_host( $url )
			? wp_remote_get( $url, $args )
			: wp_safe_remote_get( $url, $args );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return '';
			}

			$content = wp_remote_retrieve_body( $response );
			if ( empty( $content ) ) {
				return '';
			}
			// Per-file cap (issue #1235 review): truncate the remote body
			// before any regex/recursion so a multi-megabyte sheet cannot OOM
			// the worker before the deadline polls run.
			if ( strlen( $content ) > self::MAX_CCSS_FILE_BYTES ) {
				$content = substr( $content, 0, self::MAX_CCSS_FILE_BYTES );
			}
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}

			// Resolve @import directives recursively.
			preg_match_all( '/@import\s+(?:url\([\'"]?|[\'"])([^\'";)]+)(?:[\'"]?\))?[\'"]?\s*;/i', $content, $imports );

			if ( ! empty( $imports[1] ) ) {
				// Breadth cap: at most 10 nested imports per file so fan-out
				// stays bounded next to the depth-3 limit.
				$imports_list = array_slice( $imports[1], 0, 10 );
				foreach ( $imports_list as $import_url ) {
					// Stop expanding imports past the deadline (issue #1235):
					// return '' (not the partial buffer) so a direct caller
					// can never mistake the fragment for complete output.
					if ( self::generation_expired( $deadline ) ) {
						return '';
					}
					$resolved = self::resolve_import_url( trim( $import_url ), $url );
					if ( '' !== $resolved ) {
						$imported = self::fetch_stylesheet_with_imports( $resolved, $depth + 1, $deadline, $seen, $fetches );
						if ( '' !== $imported ) {
							$content .= "\n" . $imported;
							// Total-bytes cap: stop expanding once the buffer
							// exceeds the scan budget.
							if ( strlen( $content ) > self::MAX_CCSS_SOURCE_BYTES ) {
								$content = substr( $content, 0, self::MAX_CCSS_SOURCE_BYTES );
								break;
							}
						}
					}
				}
			}

			return $content;
		}

		/**
		 * Resolve a potentially relative @import URL against a base stylesheet URL.
		 *
		 * @param string $import_url The URL from the @import statement.
		 * @param string $base_url   The base stylesheet URL.
		 * @return string The absolute resolved URL, or empty string if unresolvable.
		 * @since 2.0.0
		 */
		private static function resolve_import_url( string $import_url, string $base_url ): string {
			// If already absolute, only allow safe destinations (defense-in-depth;
			// the fetch layer validates again before requesting).
			if ( preg_match( '/^https?:\/\//i', $import_url ) ) {
				return self::is_safe_stylesheet_url( $import_url ) ? $import_url : '';
			}

			// If protocol-relative, prepend the base scheme and re-gate
			// through the SSRF allowlist so `//169.254.169.254/x.css`
			// cannot bypass the absolute-URL check above (issue #1181).
			// The fetch layer validates again before requesting.
			if ( 0 === strpos( $import_url, '//' ) ) {
				$scheme   = wp_parse_url( $base_url, PHP_URL_SCHEME );
				$resolved = $scheme ? $scheme . ':' . $import_url : 'https:' . $import_url;
				return self::is_safe_stylesheet_url( $resolved ) ? $resolved : '';
			}

			// Resolve relative URL against the base URL's directory.
			$base_parts = wp_parse_url( $base_url );
			if ( empty( $base_parts['host'] ) ) {
				return $import_url;
			}

			$scheme   = $base_parts['scheme'] ?? 'https';
			$host     = $base_parts['host'];
			$port     = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';
			$base_dir = dirname( $base_parts['path'] ?? '/' );

			// If import URL starts with /, it's root-relative.
			if ( 0 === strpos( $import_url, '/' ) ) {
				return $scheme . '://' . $host . $port . $import_url;
			}

			return $scheme . '://' . $host . $port . $base_dir . '/' . $import_url;
		}

		/**
		 * Whether the URL points at this WordPress site's own host.
		 *
		 * Allows localhost / private-IP development sites to keep using their
		 * own stylesheets even though core URL validation rejects such hosts.
		 *
		 * @param string $url The URL to inspect.
		 * @return bool True when the URL host matches home_url().
		 * @since 2.0.0
		 */
		private static function is_same_site_host( string $url ): bool {
			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return false;
			}

			$site_host = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );

			return '' !== $site_host && $host === $site_host;
		}

		/**
		 * Whether a stylesheet URL is safe to request server-side.
		 *
		 * Guards the Critical CSS generator against SSRF: only http(s) URLs are
		 * accepted, restricted to hosts that belong to this site or are
		 * explicitly allowlisted via the
		 * wppo_ccss_allowed_stylesheet_host filter (default-deny).
		 *
		 * @param string $url The stylesheet URL.
		 * @return bool True when safe to fetch.
		 * @since 2.0.0
		 */
		private static function is_safe_stylesheet_url( string $url ): bool {
			$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
			if ( 'http' !== $scheme && 'https' !== $scheme ) {
				return false;
			}

			$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			if ( '' === $host ) {
				return false;
			}

			/**
			 * Filters whether an external stylesheet host may be fetched while
			 * generating Critical CSS.
			 *
			 * @param bool   $allowed Whether the host is explicitly allowed. Default false.
			 * @param string $host    Candidate host, lowercased.
			 * @since 2.0.0
			 */
			if ( apply_filters( 'wppo_ccss_allowed_stylesheet_host', false, $host ) ) {
				return true;
			}

			if ( self::is_same_site_host( $url ) ) {
				return true;
			}

			// Audit #1357: no wp_http_validate_url() fallback — syntax
			// validity is not safety. Non-same-site hosts fetch only via
			// the explicit allowlist above (default-deny).
			return false;
		}

		/**
		 * Decode numeric/hex HTML entities so encoded payloads cannot smuggle
		 * `<` past the encoder (e.g. `&#60;script&#62;`, `&#x3c;`, `&lt;`).
		 *
		 * Bounded to two passes so double-encoded input (`&amp;lt;`) is still
		 * caught while triple-encoded remnants are neutralized downstream by
		 * the `&` escape in sanitize_inline_css().
		 *
		 * @param string $css Raw critical CSS.
		 * @return string Entity-decoded CSS.
		 * @since 2.0.0
		 */
		private static function decode_css_entities( string $css ): string {
			for ( $i = 0; $i < 2; ++$i ) {
				$decoded = html_entity_decode( $css, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( ! is_string( $decoded ) ) {
					break;
				}
				if ( $decoded === $css ) {
					break;
				}
				$css = $decoded;
			}
			return $css;
		}

		/**
		 * Whether decoded CSS contains tokens that could break out of a
		 * `<style>` element or execute script when inlined.
		 *
		 * Fail-closed pre-cache gate used by generate_and_store(): a poisoned
		 * source stylesheet must never reach the CCSS cache.
		 *
		 * @param string $css Raw critical CSS.
		 * @return bool True when hostile tokens are present.
		 * @since 2.0.0
		 */
		private static function contains_unsafe_css_tokens( string $css ): bool {
			$decoded = self::decode_css_entities( $css );
			// Note: behaviou?r matches in property position (followed by a
			// colon) AND only when it is the whole property name, so benign
			// selectors like .behavior-badge pass and modern hyphenated
			// properties like `scroll-behavior:` are not mistaken for the
			// legacy IE behavior vector. Without the lookbehind, any
			// stylesheet using `scroll-behavior: smooth` failed this gate
			// closed, which silently disabled critical CSS site-wide.
			return (bool) preg_match( '/<\/style|<script|<!--|-->|expression\s*\(|javascript\s*:|vbscript\s*:|file\s*:|expect\s*:|data\s*:\s*image\/svg|data\s*:\s*text\/html|(?<![-\w])behaviou?r(?=\s*:)|-moz-binding|&(lt|gt|amp|quot|#\d+|#x[0-9a-f]+);?/i', $decoded );
		}

		/**
		 * Sanitize generated critical CSS for safe output inside a <style> tag.
		 *
		 * Token-breaking worker shared by sanitize_inline_css() (applied
		 * both before and after the `wppo_ccss_sanitize_inline` filter so
		 * hooked code cannot reintroduce breakout tokens). Delegates to the
		 * single source of truth in {@see Util::sanitize_css_for_storage()}
		 * (issue #1347) so the used-CSS and critical-CSS token lists can
		 * never drift apart; the local implementation below is retained
		 * only as a fallback when Util is unavailable. See the Util worker
		 * for the neutralized token list.
		 *
		 * @param string $css Raw critical CSS.
		 * @return string Sanitized critical CSS.
		 * @since 2.0.0
		 */
		private static function sanitize_inline_css_tokens( string $css ): string {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'sanitize_css_for_storage' ) ) {
					return Util::sanitize_css_for_storage( $css );
				}
			} catch ( \Throwable $e ) {
				return '';
			}
			try {
				$css = self::decode_css_entities( $css );
				$css = str_ireplace( '</style', '<\/style', $css );
				$css = str_ireplace( '<script', '<\script', $css );
				$css = str_ireplace( '<!--', '<\!--', $css );
				$css = str_ireplace( '-->', '--\>', $css );
				// Fallback path (Util unavailable): mirrors the shared worker
				// token-for-token so behavior stays identical. Break CSS
				// expression/URL vectors, tolerating whitespace
				// between the keyword and its delimiter (e.g. 'expression (').
				// A callback builds the replacement so the backslash is never
				// parsed as a PCRE backreference.
				$css = (string) preg_replace_callback(
					'/expression\s*\(|javascript\s*:|vbscript\s*:|file\s*:|expect\s*:/i',
					static function ( array $matches ): string {
						$token = $matches[0];
						return substr( $token, 0, -1 ) . '\\' . substr( $token, -1 );
					},
					$css
				);
				// Neutralize script-capable data: URLs inside url() so a
				// poisoned stylesheet cannot smuggle `url(data:image/svg…)`
				// or `url(data:text/html…)` past the scheme break above
				// (issue #1181). A callback emits the backslash literally.
				$css = (string) preg_replace_callback(
					'/data\s*:\s*image\/svg|data\s*:\s*text\/html/i',
					static function ( array $matches ): string {
						$token = $matches[0];
						return substr( $token, 0, -1 ) . '\\' . substr( $token, -1 );
					},
					$css
				);
				// Scope behavior/behaviour to property position (followed by
				// a colon) AND to a whole property name, so benign selectors
				// like .behavior-badge or #behaviour-list keep working and
				// modern hyphenated properties such as `scroll-behavior`
				// are not rewritten into invalid CSS. A callback emits the
				// backslash literally instead of a PCRE backreference.
				$css = (string) preg_replace_callback(
					'/(?<![-\w])behaviou?r(?=\s*:)/i',
					static function (): string {
						return 'behavio\\r';
					},
					$css
				);
				$css = str_ireplace( '-moz-binding', '-moz-bindin\g', $css );

				// Neutralize entity remnants that survived decoding (e.g.
				// triple-encoded input): encode the '&' as a CSS escape so
				// '&#60;' / '&lt;' can never decode back to '<' at render.
				// A callback builds the replacement so '\26' is emitted
				// literally instead of being parsed as a backreference.
				$css = (string) preg_replace_callback(
					'/&(?=#\d|#x[0-9a-f]|lt|gt|amp|quot);?/i',
					static function (): string {
						return "\\26 ";
					},
					$css
				);

				// Defense-in-depth: encode every remaining '<' (CSS-valid escape).
				$css = str_replace( '<', '\3c ', $css );
			} catch ( \Throwable $e ) {
				return '';
			}

			return $css;
		}

		/**
		 * Sanitize generated critical CSS for safe output inside a <style> tag.
		 *
		 * Neutralizes the `</style>` raw-text terminator plus `script`,
		 * comment (`<!--`/`-->`), and CSS expression vectors
		 * (`expression()`, `javascript:`/`vbscript:` URLs, the `behavior` /
		 * `behaviour` property, `-moz-binding`) case-insensitively, decodes
		 * numeric/hex entities first so encoded payloads cannot smuggle `<`
		 * past the encoder, then encodes any remaining `<` as the equivalent
		 * CSS escape so stored CSS can never break out of the style element.
		 * Fail-closed: a sanitizer error drops the block (returns '') so
		 * output degrades to unoptimized markup, never script execution.
		 *
		 * Filter output is re-sanitized: `wppo_ccss_sanitize_inline` is a
		 * trusted-code-only hook, and a hooked callback must not be able to
		 * reintroduce `</style>`/`<script>` past the sanitizer and gate.
		 *
		 * @param string $css Raw critical CSS.
		 * @return string Sanitized critical CSS.
		 * @since 2.0.0
		 */
		private static function sanitize_inline_css( string $css ): string {
			// Per-request memo (issue #1235): output was already sanitized
			// at generation time before the atomic write, so the inline_ccss()
			// hot path would otherwise re-run the regex passes on every
			// frontend hit. Bounded to 20 entries so unbounded variants
			// cannot grow memory within a long-lived worker.
			static $memo = array();
			$key         = md5( $css );
			if ( array_key_exists( $key, $memo ) ) {
				return $memo[ $key ];
			}
			$css = self::sanitize_inline_css_tokens( $css );

			/**
			 * Filters the inline critical CSS right before output.
			 *
			 * The return value is passed through the sanitizer again, so
			 * hooked code cannot reintroduce breakout tokens.
			 *
			 * @param string $css Sanitized critical CSS.
			 * @since 2.0.0
			 */
			if ( function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_sanitize_inline' ) ) {
				$filtered = apply_filters( 'wppo_ccss_sanitize_inline', $css );
				if ( ! is_string( $filtered ) ) {
					$memo[ $key ] = $css;
					if ( count( $memo ) > 20 ) {
						array_shift( $memo );
					}
					return $css;
				}
				$css = self::sanitize_inline_css_tokens( $filtered );
			}
			$memo[ $key ] = $css;
			if ( count( $memo ) > 20 ) {
				array_shift( $memo );
			}
			return $css;
		}

		/**
		 * Extract above-fold CSS rules using heuristic selector matching.
		 *
		 * Always includes @font-face, @keyframes, CSS custom properties (:root),
		 * and rules matching known above-fold selectors.
		 *
		 * Uses a brace-depth-based parser that handles minified CSS (single-line)
		 * and multi-line selectors correctly.
		 *
		 * @param string     $css Full CSS content.
		 * @param float|null $deadline Optional absolute wall-clock deadline; the
		 *                             scan stops early when it expires (issue #1235).
		 * @return string Extracted critical CSS.
		 * @since 2.0.0
		 * @since 2.2.0 Deadline-aware early stop for the CPU-bound scan.
		 */
		private static function extract_above_fold_css( string $css, ?float $deadline = null ): string {
			// CPU-bound parse phase (issue #1235): refuse to start past the
			// deadline. Callers re-check expiry afterwards so output produced
			// by an overrun parse is still discarded fail-open.
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}
			// Scan-budget cap (issue #1235 review): truncate oversized input
			// before the regex passes so each preg_match_all duplicates at
			// most 2MB instead of an unbounded theme bundle.
			if ( strlen( $css ) > self::MAX_CCSS_SOURCE_BYTES ) {
				$css = substr( $css, 0, self::MAX_CCSS_SOURCE_BYTES );
			}
			$critical_parts = array();

			// Extract @font-face blocks.
			preg_match_all( '/@font-face\s*\{[^}]+\}/is', $css, $font_faces );
			if ( ! empty( $font_faces[0] ) ) {
				$critical_parts[] = implode( "\n", $font_faces[0] );
			}
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}

			// Extract @keyframes blocks.
			preg_match_all( '/@keyframes\s+[^\{]+\{(?:[^{}]|\{[^{}]*\})*\}/is', $css, $keyframes );
			if ( ! empty( $keyframes[0] ) ) {
				$critical_parts[] = implode( "\n", $keyframes[0] );
			}
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}

			// Extract CSS custom properties from :root.
			preg_match( '/:root\s*\{([^}]*)\}/i', $css, $root_match );
			if ( ! empty( $root_match[0] ) ) {
				$critical_parts[] = $root_match[0];
			}
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}

			// Also extract variables from html selector.
			preg_match( '/html\s*\{([^}]*)\}/i', $css, $html_match );
			if ( ! empty( $html_match[0] ) ) {
				if ( false !== strpos( $html_match[1], '--' ) ) {
					$critical_parts[] = $html_match[0];
				}
			}

			// Extract media queries for mobile-first approach (max-width queries).
			preg_match_all( '/@media\s*\(max-width:[^}]+\{(?:[^{}]|\{[^{}]*\})*\}/is', $css, $mobile_queries );
			if ( self::generation_expired( $deadline ) ) {
				return '';
			}
			// The safelist is fetched once and threaded through so settings
			// are not re-read per rule.
			$safelist = self::get_ccss_safelist();
			if ( ! empty( $mobile_queries[0] ) ) {
				foreach ( $mobile_queries[0] as $mq ) {
					if ( self::generation_expired( $deadline ) ) {
						return '';
					}
					$filtered = self::filter_media_query_rules( $mq, $safelist, $deadline );
					if ( ! empty( $filtered ) ) {
						$critical_parts[] = $filtered;
					}
				}
			}

			// Extract regular rules using brace-depth-based parsing.
			// Handles minified CSS (single line), multi-line selectors, and nested braces.
			self::parse_regular_rules( $css, $critical_parts, $safelist, $deadline );

			return implode( "\n", array_unique( array_filter( $critical_parts ) ) );
		}

		/**
		 * Parse regular (non-at-rule) CSS rules using brace-depth tracking.
		 *
		 * Handles minified CSS, multi-line selectors, and nested braces
		 * (e.g., background: url(data:...{...})).
		 *
		 * Each matched rule is stored as `selector + declarations` (issue
		 * #1038): before this, only the `{declarations}` fragment was kept,
		 * producing selector-less CSS that browsers discard — the emitted
		 * above-fold rules had no effect. This is a correctness fix, not a
		 * formatting preference; it is covered by
		 * CcssSafelistChecksumTest::test_regular_rules_keep_selector_with_declarations().
		 *
		 * @param string        $css            Full CSS content.
		 * @param array         $critical_parts Reference to array of extracted critical CSS parts.
		 * @param string[]|null $safelist Pre-fetched safelist (null = fetch once here).
		 * @param float|null    $deadline Optional absolute wall-clock deadline; the
		 *                                scan stops early when it expires (issue #1235).
		 * @return void
		 * @since 2.0.0
		 * @since 2.2.0 Deadline-aware early stop for the CPU-bound scan.
		 */
		private static function parse_regular_rules( string $css, array &$critical_parts, ?array $safelist = null, ?float $deadline = null ): void {
			$safelist = $safelist ?? self::get_ccss_safelist();
			$length   = strlen( $css );
			$depth    = 0;
			$buffer   = '';
			$selector = '';
			$in_rule  = false;

			// Segment scan (issue #1235 review): append runs between braces via
			// substr() instead of per-char `.=` so a 2MB sheet does ~2K segment
			// appends instead of ~2M reallocating iterations. Semantics match
			// the old char loop exactly; expiry is polled per segment.
			$pos = 0;
			while ( $pos < $length ) {
				if ( self::generation_expired( $deadline ) ) {
					return;
				}
				$run = strcspn( $css, '{}', $pos );
				if ( $run > 0 ) {
					$buffer .= substr( $css, $pos, $run );
					$pos    += $run;
					if ( $pos >= $length ) {
						break;
					}
				}
				$char = $css[ $pos ];
				++$pos;

				if ( '{' === $char ) {
					if ( 0 === $depth && ! $in_rule ) {
						$selector = trim( $buffer );
						$buffer   = '';
						$in_rule  = true;

						// Skip @-rules already handled separately.
						if ( preg_match( '/^@(font-face|keyframes|import|charset|namespace|media)\b/i', $selector ) ) {
							// Consume the entire @-rule block.
							$depth    = 1;
							$buffer   = $selector . '{';
							$selector = '';
							continue;
						}
					}
					++$depth;
					$buffer .= $char;
				} elseif ( '}' === $char ) {
					--$depth;
					$buffer .= $char;
					if ( 0 === $depth && $in_rule ) {
						// Complete rule block. The selector is stored with
						// its declarations (issue #1038): without it the
						// output is invalid CSS that browsers ignore.
						if ( '' !== $selector && self::matches_above_fold( $selector, $safelist ) ) {
							$critical_parts[] = $selector . $buffer;
						}
						$buffer   = '';
						$selector = '';
						$in_rule  = false;
					}
				}
			}
		}

		/**
		 * Filter rules inside a media query to keep only above-fold selectors.
		 *
		 * @param string        $media_query Full media query block.
		 * @param string[]|null $safelist Pre-fetched safelist (null = fetch once here).
		 * @param float|null    $deadline Optional absolute wall-clock deadline; the
		 *                                per-rule loop aborts early when expired (issue #1235).
		 * @return string Filtered media query or empty string.
		 * @since 2.0.0
		 * @since 2.2.0 Deadline-aware early stop inside the per-rule loop.
		 */
		private static function filter_media_query_rules( string $media_query, ?array $safelist = null, ?float $deadline = null ): string {
			$header_end = strpos( $media_query, '{' );
			if ( false === $header_end ) {
				return '';
			}
			$header = substr( $media_query, 0, $header_end + 1 );
			$body   = substr( $media_query, $header_end + 1, -1 );

			$filtered_rules = array();
			$lines          = explode( '}', $body );

			foreach ( $lines as $rule ) {
				if ( self::generation_expired( $deadline ) ) {
					break;
				}
				$rule = trim( $rule );
				if ( empty( $rule ) ) {
					continue;
				}
				$rule        .= '}';
				$selector_end = strpos( $rule, '{' );
				if ( false === $selector_end ) {
					continue;
				}
				$selector = substr( $rule, 0, $selector_end );
				if ( self::matches_above_fold( $selector, $safelist ) ) {
					$filtered_rules[] = $rule;
				}
			}

			if ( empty( $filtered_rules ) ) {
				return '';
			}

			return $header . implode( "\n", $filtered_rules ) . '}';
		}

		/**
		 * Check if a CSS selector matches above-fold selectors.
		 *
		 * Splits multi-selector groups on ',' and tests each individual selector
		 * using token-based matching to prevent false positives (e.g., '.container'
		 * does not match '.container-fluid').
		 *
		 * @param string        $selector The CSS selector string (may contain multiple selectors separated by commas).
		 * @param string[]|null $safelist Pre-fetched safelist (null = fetch once here).
		 * @return bool True if any individual selector should be included in critical CSS.
		 * @since 2.0.0
		 */
		private static function matches_above_fold( string $selector, ?array $safelist = null ): bool {
			$selector = trim( $selector );

			if ( empty( $selector ) ) {
				return false;
			}

			$safelist = $safelist ?? self::get_ccss_safelist();

			// Fast path (issue #1235): the common single-selector case has
			// no comma, so skip the comma-aware preg_split entirely.
			if ( false === strpos( $selector, ',' ) ) {
				return self::matches_above_fold_single( $selector, $safelist );
			}

			// Split multi-selector groups on commas (outside parentheses).
			$individual_selectors = preg_split( '/,(?=(?:[^()]*\([^()]*\))*[^()]*$)/', $selector );

			foreach ( $individual_selectors as $single ) {
				$single = trim( $single );
				if ( '' === $single ) {
					continue;
				}
				if ( self::matches_above_fold_single( $single, $safelist ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check if a single CSS selector matches above-fold selectors using token-based matching.
		 *
		 * Uses word-boundary-aware matching to prevent false positives like
		 * '.container' matching '.container-fluid'.
		 *
		 * @param string        $selector A single trimmed CSS selector.
		 * @param string[]|null $safelist Pre-fetched safelist (null = fetch once here).
		 * @return bool True if the selector matches.
		 * @since 2.0.0
		 */
		private static function matches_above_fold_single( string $selector, ?array $safelist = null ): bool {
			$selector = trim( $selector );

			if ( empty( $selector ) ) {
				return false;
			}

			// User safelist (issue #1038): hidden/dynamic selectors are
			// always kept. Empty safelist keeps current behaviour verbatim.
			if ( self::matches_ccss_safelist( $selector, $safelist ) ) {
				return true;
			}

			// Built-in presets (issue #1102): Elementor/popup/dynamic-token
			// selectors are always preserved, even with an empty user
			// safelist, so capped extraction can never break hidden content
			// (popups, modals, dialogs). Same match semantics as the user
			// safelist; fail-open on any filter failure. Resolved once per
			// request (issue #1235 review) so a 2MB scan does not re-run the
			// filter per rule.
			try {
				if ( null === self::$ccss_presets_memo ) {
					self::$ccss_presets_memo = self::get_ccss_safelist_presets();
				}
				if ( self::matches_ccss_safelist( $selector, self::$ccss_presets_memo ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Remove pseudo-classes and pseudo-elements for matching.
			$clean = preg_replace( '/::?[\w-]+(\([^)]*\))?/', '', $selector );
			$clean = preg_replace( '/\[[^\]]*\]/', '', $clean );
			// Remove combinator characters (>, +, ~) and whitespace around them.
			$clean = preg_replace( '/\s*[>+~]\s*/', ' ', $clean );
			$clean = trim( $clean );

			if ( '' === $clean ) {
				return false;
			}

			// Split descendant selectors and test last (most specific) part
			// via the precompiled matcher (exact sets + one alternation).
			$parts = preg_split( '/\s+/', $clean );
			$last  = end( $parts );

			return self::token_match_precompiled( (string) $last );
		}

		/**
		 * Precompiled above-fold matcher state (exact sets + fallback regex).
		 *
		 * Built once per request so matches_above_fold_single() avoids
		 * rebuilding/running 53 regexes per CSS rule.
		 *
		 * @since 2.0.0
		 * @var array{exact: array<string, bool>, regex: string}|null
		 */
		private static ?array $above_fold_matcher = null;

		/**
		 * Per-request memo for the built-in safelist presets (issue #1235
		 * review).
		 *
		 * The per-rule matcher runs once per CSS rule, so the presets
		 * (settings read + filter) are resolved once per request instead of
		 * once per rule. Reset via reset_ccss_memo().
		 *
		 * @since 2.2.0
		 * @var string[]|null
		 */
		private static ?array $ccss_presets_memo = null;

		/**
		 * Build the precompiled above-fold matcher (exact hash sets + one alternation).
		 *
		 * @since 2.0.0
		 * @return array{exact: array<string, bool>, regex: string}
		 */
		private static function get_above_fold_matcher(): array {
			if ( null !== self::$above_fold_matcher ) {
				return self::$above_fold_matcher;
			}
			$exact  = array();
			$quoted = array();
			foreach ( self::ABOVE_FOLD_SELECTORS as $above ) {
				$exact[ $above ] = true;
				$token           = ltrim( (string) $above, '.' );
				if ( '' !== $token ) {
					$quoted[] = preg_quote( $token, '/' );
				}
			}
			self::$above_fold_matcher = array(
				'exact' => $exact,
				'regex' => '' !== implode( '', $quoted ) ? '/\b(?:' . implode( '|', $quoted ) . ')\b/' : '',
			);
			return self::$above_fold_matcher;
		}

		/**
		 * Token-based selector matching that prevents substring false positives.
		 *
		 * Matches exact class, ID, tag, or attribute names using word boundaries.
		 *
		 * @param string $selector_part A single selector fragment (e.g., '.container', '#header', 'h1').
		 * @param string $above         The above-fold selector pattern to match against.
		 * @return bool True if the selector part matches the pattern.
		 * @since 2.0.0
		 */
		private static function token_match( string $selector_part, string $above ): bool {
			if ( $selector_part === $above ) {
				return true;
			}

			// For class selectors, use word-boundary-aware matching.
			$pattern = '/\b' . preg_quote( ltrim( $above, '.' ), '/' ) . '\b/';

			return (bool) preg_match( $pattern, $selector_part );
		}

		/**
		 * Fast token match using the precompiled above-fold matcher.
		 *
		 * Exact tag/class/id hits resolve via hash lookup; everything else
		 * falls back to the single combined word-boundary alternation.
		 *
		 * @since 2.0.0
		 * @param string $selector_part Single selector fragment (last descendant part).
		 * @return bool True on match.
		 */
		private static function token_match_precompiled( string $selector_part ): bool {
			$matcher = self::get_above_fold_matcher();
			if ( isset( $matcher['exact'][ $selector_part ] ) ) {
				return true;
			}
			if ( '' === $matcher['regex'] ) {
				return false;
			}
			try {
				$matched = preg_match( $matcher['regex'], $selector_part );
				if ( false !== $matched ) {
					return (bool) $matched;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			foreach ( self::ABOVE_FOLD_SELECTORS as $above ) {
				if ( self::token_match( $selector_part, $above ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Generate critical CSS for a template and store it atomically.
		 *
		 * The fetch-plus-parse phase runs under a wall-clock budget (issue
		 * #1235): on expiry the previous stored file is left untouched, no
		 * partial output is stored or inlined, the miss is logged, and a
		 * retry is scheduled — the page keeps its existing stylesheets
		 * (fail-open, never fatal).
		 *
		 * Soft-budget note: once the atomic commit of complete CSS has
		 * happened, the run reports success even if the clock passed the
		 * deadline during the final write — the output is whole, never
		 * partial, so discarding it would only waste the work.
		 *
		 * @param string     $template_hash Template hash.
		 * @param string     $template      Template identifier for the sample URL.
		 * @param float|null $deadline      Optional absolute wall-clock deadline.
		 * @param int|null   $budget        Optional already-resolved budget for logging (avoids a second settings read).
		 * @param bool       $stage_only    When true (issue #1348 dry-run), commit to the staged sibling: no variant mirrors, no source-checksum baselining, live file untouched.
		 * @return bool True on success, false on failure or timeout.
		 * @since 2.0.0
		 * @since 2.2.0 Time-boxed generation with fail-open timeout handling.
		 * @since 2.3.0 Added the $stage_only dry-run flag.
		 */
		private static function generate_and_store( string $template_hash, string $template, ?float $deadline = null, ?int $budget = null, bool $stage_only = false ): bool {
			if ( ! self::is_valid_template_hash( $template_hash ) ) {
				return false;
			}
			if ( null === $deadline && null !== $budget ) {
				// A caller-supplied budget with no deadline derives its own
				// deadline instead of re-reading settings, so the logged
				// budget can never diverge from the enforced one.
				try {
					$deadline = self::generation_deadline( max( 1, min( $budget, self::MAX_CCSS_GEN_TIMEOUT ) ) );
				} catch ( \Throwable $e ) {
					unset( $e );
					$deadline = null;
				}
			} elseif ( null === $budget || null === $deadline ) {
				list( $resolved_budget, $resolved_deadline ) = self::resolve_generation_budget( $deadline );
				if ( null === $budget ) {
					$budget = $resolved_budget;
				}
				if ( null === $deadline ) {
					$deadline = $resolved_deadline;
				}
			}

			$url = self::get_sample_url( $template );
			if ( ! $url ) {
				// Non-renderable template (issue #1274, e.g. builder-template
				// post types with no front-end URL): mark `skipped` with
				// skip-and-continue instead of error-looping — no retry can
				// succeed until renderable content exists. Fail-open to
				// unoptimized (no critical CSS), never broken styling.
				// Retry state is reset so a later renderable run starts
				// from zero instead of inheriting stale failure counts.
				try {
					self::set_status_cache( $template_hash, 'skipped', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				self::clear_ccss_retry_state( $template_hash );
				return false;
			}

			$source_css    = '';
			$resolved_urls = array();
			$critical_css  = self::generate( $url, $source_css, $resolved_urls, $deadline );

			// Reject generated CSS that could break out of the <style> context when
			// inlined — a poisoned source stylesheet must never reach the CCSS cache.
			// Fail closed: drop the block instead of caching raw input.
			// Fail-open rendering: the page serves unoptimised markup, never fatal.
			// Deterministic poison (issue #1235 review) marks failed directly:
			// the failure was already determined, so routing it through the
			// timeout path would burn up to 5 full-budget retries before
			// escalation even though no retry can succeed.
			if ( is_string( $critical_css ) && self::contains_unsafe_css_tokens( $critical_css ) ) {
				try {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Deterministic poison keeps prior generic/timeout counts
				// so alternating poison/ordinary failures still terminate
				// on the combined cap (issue #1274 review).
				return false;
			}
			if ( false === $critical_css ) {
				// Timeout vs. ordinary failure (issue #1235): an expired
				// budget keeps existing stylesheets, logs the miss, and
				// schedules a retry instead of parking the template in the
				// day-long `failed` state with no retry.
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}
			// The sanitize + extraction phases may have consumed the rest of
			// the budget — never commit output past the deadline (issue
			// #1235). The previous file (if any) stays in place untouched.
			if ( self::generation_expired( $deadline ) ) {
				self::handle_ccss_timeout( $template_hash, $budget );
				return false;
			}
			// Defense-in-depth (issue #1181): sanitize before caching so the
			// stored file itself carries no breakout tokens even if the gate
			// above missed a novel vector; output is sanitized again in
			// inline_ccss(). An empty result fails closed like a gate hit.
			$critical_css = self::sanitize_inline_css( $critical_css );
			if ( self::contains_unsafe_css_tokens( $critical_css ) ) {
				try {
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Keep retry counters (see poison branch above).
				return false;
			}
			if ( '' === trim( $critical_css ) ) {
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}

			// The sanitize + extraction phases may have consumed the rest of
			// the budget — never commit output past the deadline (issue
			// #1235). The previous file (if any) stays in place untouched.
			if ( self::generation_expired( $deadline ) ) {
				self::handle_ccss_timeout( $template_hash, $budget );
				return false;
			}

			$dir = self::get_ccss_dir();
			if ( ! wp_mkdir_p( $dir ) ) {
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}

			$filesystem = Util::init_filesystem();
			$live_file  = self::get_ccss_file( $template_hash );
			// Dry-run (issue #1348): commit to the staged sibling so a
			// bad generation can never break styling before promote.
			$file = $stage_only && '' !== $live_file ? Util::get_staged_path_for( $live_file ) : $live_file;
			if ( '' === $file ) {
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}

			// Storage bounds (issue #1347): refuse oversized/undecodable
			// output before the atomic write so unbounded blobs never reach
			// the cache. Fail closed: keep the prior file in place.
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'css_within_storage_bounds' ) && ! Util::css_within_storage_bounds( $critical_css ) ) {
					self::record_generation_failure( $template_hash, $budget, $deadline );
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}

			// Atomic write via the shared tmp+rename helper (unique tmp
			// name, no non-atomic fallback) so interrupted writes never
			// leave a truncated live file behind. Fail closed: keep the
			// prior file in place and mark the status failed.
			$written = $filesystem ? Util::atomic_file_put_contents( $filesystem, $file, $critical_css ) : false;
			if ( ! $written ) {
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}

			// Baseline the canonical SOURCE checksum — not the generated
			// output — so later local-source comparisons
			// (maybe_refresh_from_local_css) hash the exact same domain the
			// frontend probe reproduces. The document-ordered URL list the
			// fetch saw is persisted too, and the probe re-hashes that exact
			// list rather than re-deriving one from $wp_styles (audit #9).
			// This detects stylesheet edits that preserve mtime. Local
			// transient writes only — no remote fetch. An empty source (no
			// locally-resolvable external stylesheets) stores nothing, so the
			// probe stays a no-op and never churns.
			// Staged dry-runs (issue #1348) must not baseline source
			// checksums: the staged output is a candidate, and baselining
			// it would suppress future refresh detection for the live file.
			if ( ! $stage_only && '' !== $source_css ) {
				self::store_source_checksum( $template_hash, $source_css );
				self::store_source_urls( $template_hash, $resolved_urls );
			}

			// The memo must reflect the fresh file within this request too;
			// clear PHP's stat cache so file_exists/mtime are not stale. Only
			// this template's memo entries are invalidated — a bulk loop keeps
			// the other templates' memo entries.
			self::invalidate_ccss_memo( $template_hash );
			clearstatcache( true, $file );

			// Viewport-split variants (issue #1164): when enabled, mirror the
			// single output to `{hash}.mobile.css` / `{hash}.desktop.css` so
			// the first run populates both splits. Later extractions overwrite
			// both; a variant older than the single file is stale and falls
			// back via get_ccss_variant_content(). Fail-open: variant write
			// failures never fail the single-variant store.
			// Complete output committed above is success even past the deadline
			// (soft-budget, issue #1235 review): the file is whole, never
			// partial, so it must not be routed to handle_ccss_timeout() here.
			// Variant mirrors stay best-effort and are skipped past expiry.
			if ( ! $stage_only && ! self::generation_expired( $deadline ) && self::is_viewport_variants_enabled() ) {
				try {
					foreach ( Ccss_Store::VIEWPORT_VARIANTS as $variant ) {
						$variant_file = self::get_ccss_variant_file( $template_hash, $variant );
						if ( '' !== $variant_file && $filesystem ) {
							Util::atomic_file_put_contents( $filesystem, $variant_file, $critical_css );
							clearstatcache( true, $variant_file );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Dry-run success (issue #1348): the staged sibling holds
			// complete output — the live verdict, status cache, and retry
			// state stay untouched so the preview never masquerades as a
			// live deploy. Rollout fields (get_ccss_rollout_status())
			// carry the staged truth to the status UI instead.
			if ( $stage_only ) {
				$staged_ok = false;
				try {
					if ( $filesystem && method_exists( $filesystem, 'exists' ) && $filesystem->exists( $file ) ) {
						if ( method_exists( $filesystem, 'size' ) ) {
							$staged_ok = (int) $filesystem->size( $file ) > 0;
						} elseif ( method_exists( $filesystem, 'get_contents' ) ) {
							$probe     = $filesystem->get_contents( $file );
							$staged_ok = is_string( $probe ) && '' !== $probe;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$staged_ok = false;
				}
				if ( $staged_ok ) {
					return true;
				}
				self::record_generation_failure( $template_hash, $budget, $deadline );
				return false;
			}

			if ( self::ccss_exists( $template_hash ) ) {
				self::set_status_cache( $template_hash, 'done', defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800 );
				try {
					self::clear_ccss_retry_state( $template_hash );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return true;
			}

			self::record_generation_failure( $template_hash, $budget, $deadline );
			return false;
		}

		/**
		 * Inline critical CSS in the <head> for non-logged-in visitors.
		 *
		 * Hooked to wp_head at priority 0. Computes the template hash using
		 * the current template slug (based on WordPress conditional tags)
		 * to match the hashes stored by generate_and_store().
		 *
		 * Output decision, in order:
		 * 1. Yield entirely when the `wppo_inline_combined_css` filter is
		 *    falsy — stylesheets load normally (no inline, no deferral).
		 * 1b. Yield entirely when deferJS or delayJS is active — deferral is
		 *    suspended (media=print deadlock guard) so emitting critical CSS
		 *    would only add redundant weight (issue #1090). No emission, no
		 *    generation queueing, no loader stub.
		 * 1c. Yield entirely on cart/checkout requests when
		 *    `ccssCommerceExclude` is on (issue #1388) — dynamic commerce
		 *    markup must not be styled from a cached snapshot. Stylesheets
		 *    load normally.
		 * 2. Missing/unreadable variant — fail-open: queue background
		 *    generation and print the async loader stub (never fatal).
		 * 3. Content under MIN_INLINE_SIZE — treated as a failed extraction.
		 * 4. Content within the configured cap and core's
		 *    `styles_inline_size_limit` budget — inlined as before.
		 * 5. Over-cap content — file-first delivery: a render-blocking
		 *    `<link>` to the per-template variant with mtime cache busting
		 *    (no FOUC), while the full theme stylesheets are still deferred.
		 *    When the file URL is unavailable (missing/unreadable variant),
		 *    output nothing and defer to the full stylesheet instead of
		 *    inlining a truncated block (issue #1255).
		 * 5b. Content over the configured gzipped inline budget (issue #1388) —
		 *    warn and take the used-CSS fallback: no inline output, the
		 *    prior good file is kept, and the deferred full stylesheet plus
		 *    used CSS styles the page. Oversized inline CSS is never
		 *    emitted.
		 *
		 * Whenever CCSS output is served for the current request, the
		 * field-measured LCP image (issue #1255) is preloaded first via
		 * get_field_lcp_preload_url(): the RUM field candidate wins over the
		 * stored PageSpeed heuristic only above the minimum sample count and
		 * freshness TTL, so worst pages preload the hero real users see.
		 *
		 * @return void
		 * @since 2.0.0
		 * @since 2.2.0 Over-cap output without a file URL defers to the full stylesheet (and blocks deferral for the request).
		 * @since 2.3.0 Commerce exclusion plus gzipped-budget used-CSS fallback (issue #1388).
		 * @since 2.2.0 Field-measured LCP image is preloaded alongside served CCSS.
		 */
		public static function inline_ccss(): void {
			if ( is_admin() ) {
				return;
			}
			if ( is_user_logged_in() ) {
				$options = Util::get_settings();
				$enabled = ! empty( $options['cache_settings']['enableLoggedInCache'] ?? false );
				if ( ! $enabled ) {
					return;
				}
			}

			// Operators who disabled plugin inlining (e.g. serving CSS from a
			// CDN) get normally-enqueued stylesheets: skip inline output and
			// leave deferral to defer_stylesheets(), which yields too.
			// Suspended while deferJS/delayJS is active: deferral is off, so
			// emission would be redundant weight — skip everything (issue #1090).
			if ( ! self::is_ccss_effective() ) {
				return;
			}

			// Commerce exclusion (issue #1388): cart/checkout pages never
			// get inline critical CSS — dynamic commerce markup must not
			// be styled from a cached above-fold snapshot. Fail-open:
			// stylesheets load normally via the deferral skip below.
			// Memoized per request (single settings + context evaluation).
			if ( self::is_commerce_excluded_context() ) {
				return;
			}

			$template_slug = self::get_current_template_slug();
			$template_hash = self::get_template_hash( $template_slug );

			// Worst-first LCP preload (issue #1255): the field-measured hero
			// for the current page is hinted before any CCSS output so the
			// browser discovers it as early as possible. Emitted once per
			// request; a no-op when no candidate resolves (helper fails open
			// to ''). Runs before the content branches so queued (not yet
			// generated) templates still preload their hero.
			self::maybe_emit_field_lcp_preload();

			$content = self::get_ccss_content( $template_hash );
			if ( null !== $content ) {
				// Fallback when CCSS is too short (<500B) — treat as failed and inject async loadCSS guard.
				if ( strlen( $content ) < self::MIN_INLINE_SIZE ) {
					// Strict CSP (audit #1325): the raw loader below would be
					// blocked without 'unsafe-inline', and a nonce must never
					// be baked here (this output enters the static page
					// cache). Downgrade to the blocking file variant instead
					// (fail-open, never unstyled); without a file URL, emit
					// nothing and defer to the full stylesheet.
					if ( self::has_strict_csp() ) {
						$fallback_file_url = self::get_ccss_file_url( $template_hash );
						if ( '' !== $fallback_file_url ) {
							// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Per-template CCSS file variant served directly (no registered handle exists for it).
							echo '<link rel="stylesheet" id="wppo-critical-css" href="' . esc_url( $fallback_file_url ) . '" media="all" />' . "\n";
						}
						return;
					}
					// Timer handles are tracked so the media-swap fallback is
					// cleared on pagehide/beforeunload (audit #1077 finding 5).
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadcss_loader_tag() returns fully static markup with no dynamic parts.
					echo self::loadcss_loader_tag();
					return;
				}
				// Coordinated budget (issue #1462): the combined inlined CSS
				// (used CSS already committed + this CCSS block) stays within
				// core's `styles_inline_size_limit` (40KB on WP 6.9+, 20KB
				// before) and the configured `ccssMaxSize` cap — the tighter
				// wins, minus already-committed bytes. The committed byte
				// count is passed straight through to
				// coordinate_inline_budgets() (no stub string is allocated).
				// Over-budget output is never inlined: serve the per-template
				// file variant with mtime cache busting instead (repeat-visit
				// cacheable asset). The plain stylesheet link is
				// render-blocking, so there is no FOUC; the remaining full
				// stylesheets are still deferred by defer_stylesheets().
				$committed = self::estimate_committed_inline_bytes();
				$split     = self::coordinate_inline_budgets( $content, $committed );
				if ( '' !== $split['deferred'] ) {
					$file_url = self::get_ccss_file_url( $template_hash );
					if ( '' !== $file_url ) {
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Per-template CCSS file variant served directly (no registered handle exists for it).
						echo '<link rel="stylesheet" id="wppo-critical-css" href="' . esc_url( $file_url ) . '" media="all" />' . "\n";
						return;
					}
					// File URL unavailable — defer to the full stylesheet
					// (issue #1255): inlining a truncated block would ship a
					// partial above-fold payload, so output nothing and let
					// the normally-enqueued stylesheets style the page. Record
					// the template so defer_stylesheets() loads those
					// stylesheets normally instead of deferring them with zero
					// critical CSS on the page (review FOUC guard).
					self::$ccss_defer_blocked[ $template_hash ] = true;
					return;
				}
				// Gzipped inline budget guard (issue #1388): output that fits
				// the raw cap but exceeds the gzipped inline budget is never
				// inlined — warn and take the used-CSS fallback instead. The
				// prior good file is kept in place (never overwritten here),
				// no oversized inline CSS is ever emitted, and the deferred
				// full stylesheet plus used CSS styles the page. The warning
				// is throttled to once per template per 12h (transient flag)
				// so the frontend hot path never writes a DB row per pageview.
				// Fail-open by design, never fatal.
				if ( self::is_over_inline_budget( $content ) ) {
					try {
						$warn_key = Util::transient_key( 'wppo_ccss_budget_warn_' . $template_hash );
						$throttle = function_exists( 'get_transient' ) ? get_transient( $warn_key ) : true;
						if ( false === $throttle && class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) && function_exists( '__' ) ) {
							\PerformanceOptimise\Inc\Log::add(
								sprintf(
								/* translators: 1: Inline budget label (e.g. "14 KB"), 2: Template hash */
									__( 'Critical CSS over %1$s gzipped inline budget for template: %2$s. Serving deferred stylesheet plus used CSS only.', 'performance-optimisation' ),
									self::get_ccss_inline_budget_label(),
									$template_hash
								)
							);
							if ( function_exists( 'set_transient' ) ) {
								set_transient( $warn_key, 1, defined( 'HOUR_IN_SECONDS' ) ? 12 * HOUR_IN_SECONDS : 43200 );
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					self::$ccss_defer_blocked[ $template_hash ] = true;
					return;
				}
				echo '<style id="wppo-critical-css">' . "\n";
				// Sanitized against HTML breakout tokens; see sanitize_inline_css().
				// The ledger records the sanitized bytes actually echoed (not
				// the pre-sanitization length): escapes such as '<' -> '\3c '
				// expand output, so only the post-sanitization length keeps a
				// later emitter within the shared 40KB budget.
				$sanitized = self::sanitize_inline_css( $split['inline'] );
				echo $sanitized . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content sanitized for the <style> context by sanitize_inline_css().
				echo '</style>' . "\n";
				// Record the inlined bytes so later emitters in this request
				// see the reduced remainder (see estimate_committed_inline_bytes()).
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'add_committed_inline_bytes' ) ) {
					try {
						Util::add_committed_inline_bytes( strlen( $sanitized ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} else {
				// No CCSS file yet — queue async generation and never block the
				// response. generate() fetches the page with a 30s timeout, so
				// it must never run synchronously inside wp_head (audit #888
				// finding 2). The payload is wrapped in a single-element array
				// because Action Scheduler and WP-Cron both unpack stored args
				// positionally — background_generate( array $args ) must receive
				// the assoc array as its one argument.
				//
				// A template already marked `pending` has a live or scheduled
				// attempt: skip re-queueing so a flooded frontend cannot
				// stack duplicate jobs (issue #1235 review).
				$hook_args = array( array( 'template_hash' => $template_hash ) );
				$queued    = false;
				try {
					// Accept `pending` (frontend gate), `queued`
					// (bulk/retry writers) and `processing` (a live
					// background_generate() run) so every frontend hit
					// during a run hits the guard instead of paying
					// schedule_ccss_job() SELECTs per pageview
					// (issue #1310 review); dedupe alone preserves
					// correctness but not the storm.
					$cached = self::get_status_cache( $template_hash );
					if ( 'pending' === $cached || 'queued' === $cached || 'processing' === $cached ) {
						$queued = true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( ! $queued && function_exists( 'as_enqueue_async_action' ) ) {
					$hook = 'wppo_generate_ccss';
					// Single enqueue-gate flow (issue #1310 review): the
					// shared helper owns the atomic-first insert plus the
					// both-group re-check (dedicated CCSS group plus the
					// legacy shared group, so pre-split jobs still
					// dedupe). A lost unique-race reports pending (skip
					// the WP-Cron fallback, no double-schedule); only a
					// verified scheduler failure falls through to cron.
					$job    = self::schedule_ccss_job( $hook, $hook_args, time() );
					$queued = $job['pending'];
				}

				if ( ! $queued ) {
					// Action Scheduler unavailable or enqueue failed — schedule
					// the same hook via WP-Cron as a fallback. Rendering is
					// never delayed: the async loader below keeps stylesheets
					// loading while the critical CSS is generated in background.
					if ( ! wp_next_scheduled( 'wppo_generate_ccss', $hook_args ) ) {
						$queued = (bool) wp_schedule_single_event(
							time() + ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ),
							'wppo_generate_ccss',
							$hook_args
						);
					} else {
						$queued = true;
					}
				}

				if ( $queued ) {
					self::set_status_cache( $template_hash, 'pending', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				} else {
					// Nothing could be scheduled (e.g. cron disabled) — surface
					// the failure instead of reporting an hour of fake pending.
					self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				}

				// Non-blocking fallback: expose the async loader while the
				// critical CSS is generated in the background. Timer handles
				// are tracked so the media-swap fallback is cleared on
				// pagehide/beforeunload (audit #1077 finding 5).
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- loadcss_loader_tag() returns fully static markup with no dynamic parts.
				echo self::loadcss_loader_tag();
			}
		}

		/**
		 * Defer full stylesheets by adding media="print" onload="this.media='all'".
		 *
		 * Hooked to style_loader_tag filter.
		 *
		 * @param string $tag    The link tag HTML.
		 * @param string $handle The stylesheet handle.
		 * @param string $href   The stylesheet URL.
		 * @return string Modified link tag.
		 * @since 2.0.0
		 */
		public static function defer_stylesheets( string $tag, string $handle, string $href ): string {
			if ( is_admin() ) {
				return $tag;
			}
			$options = Util::get_settings();
			if ( is_user_logged_in() ) {
				$enabled = ! empty( $options['cache_settings']['enableLoggedInCache'] ?? false );
				if ( ! $enabled ) {
					return $tag;
				}
			}

			// Unified safe-mode kill switch + nocache bypass (issue #1098):
			// one-click recovery preserving the criticalCSS setting; fail open
			// to the full stylesheet, never fatal.
			if ( class_exists( 'PerformanceOptimise\Inc\Main' ) ) {
				if ( method_exists( 'PerformanceOptimise\Inc\Main', 'is_safe_mode_active' ) ) {
					try {
						if ( \PerformanceOptimise\Inc\Main::is_safe_mode_active( $options['file_optimisation'] ?? array() ) ) {
							return $tag;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return $tag;
					}
				}
				if ( method_exists( 'PerformanceOptimise\Inc\Main', 'is_aggressive_bypass_active' ) ) {
					try {
						if ( \PerformanceOptimise\Inc\Main::is_aggressive_bypass_active() ) {
							return $tag;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return $tag;
					}
				}
			}

			// Guard: when JS is deferred/delayed the onload swap never fires until JS runs.
			// This leaves cached pages unstyled (media=print deadlock with removeUnusedCSS + criticalCSS + combineCSS).
			// Keep media=all when either deferJS or delayJS is active so cached HTML stays styled.
			// Shared with inline_ccss() via is_deferral_suspended_by_js() (issue #1090).
			// @since 2.0.0.
			if ( self::is_deferral_suspended_by_js() ) {
				return $tag;
			}

			// Commerce exclusion (issue #1388): cart/checkout pages were
			// never given inline critical CSS, so deferring the full
			// stylesheets would leave dynamic commerce markup unstyled
			// until JS runs — load normally instead (fail-open).
			// Memoized per request: one settings + context evaluation no
			// matter how many stylesheet tags render on the page.
			if ( self::is_commerce_excluded_context() ) {
				return $tag;
			}

			foreach ( self::SKIP_DEFER_HANDLES as $skip ) {
				if ( $handle === $skip || false !== strpos( $href, $skip ) ) {
					return $tag;
				}
			}

			// Block-library FOUC guard (issue #1164): the combined core
			// block-library stylesheet must always load normally, even if a
			// future handle rename bypasses the allowlist above.
			if ( self::is_block_library_defer_exempt( $handle, $href ) ) {
				return $tag;
			}

			// Yield when operators disabled plugin inlining via the
			// wppo_inline_combined_css falsy filter: stylesheets load normally
			// (mirrors the inline_ccss() early return).
			if ( ! self::is_inline_allowed() ) {
				return $tag;
			}

			// Missing-variant fail-open: without a CCSS file for the current
			// template, deferring the full stylesheets would leave the page
			// unstyled until JS runs — load normally instead (never fatal).
			// Same when inline_ccss() served over-cap output with no file URL
			// this request (issue #1255 review): the page carries zero
			// critical CSS, so deferral would open a FOUC window the
			// render-blocking stylesheets avoid.
			$template_hash = self::get_template_hash();
			if ( isset( self::$ccss_defer_blocked[ $template_hash ] ) ) {
				return $tag;
			}
			if ( ! self::ccss_exists( $template_hash ) ) {
				return $tag;
			}

			// Skip if already modified.
			if ( false !== strpos( $tag, 'data-wppo-ccss' ) ) {
				return $tag;
			}

			// Single regex avoids matching inside the JS string added by the first pass.
			// Preserve original quote char and handle media as first attribute via \b.
			$new_tag = preg_replace(
				'/\bmedia\s*=\s*([\'"])all\1/i',
				' media=$1print$1 onload="this.media=\'all\'" data-wppo-ccss="1"',
				$tag,
				1
			);

			$noscript = '<noscript>' . $tag . '</noscript>';

			return $new_tag . "\n" . $noscript;
		}

		/**
		 * Purge every stored artifact for a template hash (issue #1347).
		 *
		 * Forgery-response worker shared by the HMAC-mismatch path in
		 * {@see background_generate()}: deletes the single CCSS file plus
		 * the viewport-split variants (`{hash}.mobile.css` /
		 * `{hash}.desktop.css`, mirroring {@see generate_and_store()})
		 * and drops the source checksum/URL baseline transients, so a
		 * rejected job leaves nothing stale servable. Mirrors the
		 * per-template file+transient subset of {@see clear_all()}.
		 * Fail-open: any error leaves files in place; serving still falls
		 * back via {@see get_ccss_variant_content()}.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since 2.3.0
		 */
		private static function purge_template_artifacts( string $template_hash ): void {
			try {
				global $wp_filesystem;
				// Filesystem may be uninitialized in an Action Scheduler
				// background worker — init it (mirroring
				// generate_and_store()) so the forgery purge does not
				// silently no-op. Fail-open: any error leaves files in
				// place; serving still falls back to unoptimized markup.
				// Note: the global itself is never reassigned here
				// (WordPress.Security.EscapeOutput forbids global writes);
				// the initialized handle is used via the local copy.
				$fs_handle = $wp_filesystem;
				try {
					if ( ( ! $fs_handle || ! method_exists( $fs_handle, 'exists' ) ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'init_filesystem' ) ) {
						$fs = Util::init_filesystem();
						if ( $fs ) {
							$fs_handle = $fs;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$files = array();
				try {
					$main = self::get_ccss_file( $template_hash );
					if ( is_string( $main ) && '' !== $main ) {
						$files[] = $main;
					}
					foreach ( Ccss_Store::VIEWPORT_VARIANTS as $variant ) {
						$variant_file = self::get_ccss_variant_file( $template_hash, $variant );
						if ( is_string( $variant_file ) && '' !== $variant_file ) {
							$files[] = $variant_file;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				foreach ( $files as $file ) {
					try {
						if ( $fs_handle && method_exists( $fs_handle, 'exists' ) && method_exists( $fs_handle, 'delete' ) && $fs_handle->exists( $file ) ) {
							$fs_handle->delete( $file );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				try {
					self::invalidate_ccss_memo( $template_hash );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( function_exists( 'clearstatcache' ) ) {
					clearstatcache();
				}
				if ( function_exists( 'delete_transient' ) ) {
					try {
						delete_transient( self::get_source_checksum_key( $template_hash ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					try {
						delete_transient( self::get_source_urls_key( $template_hash ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Background generation callback for Action Scheduler.
		 *
		 * Runs through the time-boxed generate_guarded() wrapper (issue
		 * #1235) so a hung source-CSS fetch can never hold the queue slot
		 * past the generation budget — on timeout the page keeps its
		 * existing stylesheets and a retry is scheduled.
		 *
		 * @param array $args Arguments containing 'template_hash'.
		 * @return void
		 * @since 2.0.0
		 * @since 2.2.0 Routed through the guarded generation wrapper.
		 * @since 2.3.0 HMAC-mismatch path stamps short-lived 'rejected' instead of day-long 'failed'; unsigned jobs logged throttled at WP_DEBUG level.
		 */
		public static function background_generate( array $args ): void {
			// Suspended while deferJS/delayJS is active: generated variants
			// could not be used (no deferral, no emission), so skip the work
			// instead of logging per-template failures (issue #1090).
			if ( self::is_deferral_suspended_by_js() ) {
				return;
			}
			$template_hash = $args['template_hash'] ?? '';
			// Scheduler args are DB-backed untrusted input: reject
			// non-string / malformed hashes before they reach string
			// type-hints and kill the queue worker (issue #1235 review).
			if ( ! self::is_valid_template_hash( $template_hash ) ) {
				return;
			}
			// HMAC-bound jobs (issue #1347): when the scheduler args carry
			// a `sig`, verify it against the signed `template_hash`
			// payload before any fetch — a mismatch purges the template
			// artifacts and aborts so forged job args can never trigger
			// generation. Unsigned jobs (queued before signing or by
			// legacy callers) take the legacy path and still run
			// (fail-open).
			//
			// Residual (documented, not a bypass): the HMAC proves
			// provenance of first-party jobs only — it does not
			// authenticate the `wppo_generate_ccss` hook itself, so any
			// caller that enqueues without a `sig` still triggers
			// generation via the legacy path. Stored-XSS protection does
			// not depend on the HMAC: fetched CSS is sanitized on write
			// and output degrades to unoptimized markup. Unsigned
			// executions are logged at a throttled WP_DEBUG level so
			// unexpected unsigned jobs stay visible.
			if ( isset( $args['sig'] ) && is_string( $args['sig'] ) && '' !== $args['sig'] ) {
				$verified = false;
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'verify_callback_signature' ) ) {
						$verified = Util::verify_callback_signature( array( 'template_hash' => $template_hash ), $args['sig'] );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$verified = false;
				}
				if ( ! $verified ) {
					try {
						if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
							Log::add( 'WPPO critical-CSS job rejected: HMAC mismatch for template hash.' );
						}
						self::purge_template_artifacts( $template_hash );
						// Forgery rejections stamp a distinct short-lived
						// `rejected` status (not day-long `failed`) so a
						// single forged — or secret-rotated, hence
						// unverifiable — job is distinguishable from a
						// genuine generation failure in the status UI and
						// does not block the next legitimate signed retry.
						self::set_status_cache( $template_hash, 'rejected', defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300 );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					return;
				}
			} else {
				// Unsigned legacy job (no `sig`): runs the legacy path
				// (fail-open, see note above). Throttled WP_DEBUG-only
				// visibility so unexpected unsigned executions are
				// observable without spamming the activity log.
				try {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG && function_exists( 'get_transient' ) && function_exists( 'set_transient' ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
						$unsigned_key = Util::transient_key( 'wppo_ccss_unsigned_log' );
						if ( false === get_transient( $unsigned_key ) ) {
							if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
								Log::add( 'WPPO critical-CSS job running unsigned (legacy path) for template hash.' );
							}
							set_transient( $unsigned_key, 1, defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$templates      = self::get_templates();
			$found_template = '';

			foreach ( $templates as $template => $label ) {
				if ( self::get_template_hash( $template ) === $template_hash ) {
					$found_template = $template;
					break;
				}
			}

			if ( empty( $found_template ) ) {
				self::set_status_cache( $template_hash, 'failed', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
				return;
			}

			self::set_status_cache( $template_hash, 'processing', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );

			$timed_out = null;
			$result    = self::generate_guarded( $template_hash, $found_template, $timed_out );

			if ( $result ) {
				Log::add(
					sprintf(
						/* translators: %s: Template hash */
						__( 'Critical CSS generated for template: %s', 'performance-optimisation' ),
						$template_hash
					)
				);
			} elseif ( true !== $timed_out ) {
				// Timeouts are already logged with retry context by
				// handle_ccss_timeout() — only log ordinary failures here.
				// Skipped outcomes (e.g. non-renderable builder templates
				// with no sample URL) are not failures: log them as such
				// so operators never chase phantom failures.
				$is_skipped = false;
				try {
					$is_skipped = ( 'skipped' === self::get_status_cache( $template_hash ) );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( $is_skipped ) {
					Log::add(
						sprintf(
							/* translators: %s: Template hash */
							__( 'Critical CSS generation skipped for template: %s', 'performance-optimisation' ),
							$template_hash
						)
					);
				} else {
					Log::add(
						sprintf(
							/* translators: %s: Template hash */
							__( 'Critical CSS generation failed for template: %s', 'performance-optimisation' ),
							$template_hash
						)
					);
				}
			}
		}

		/**
		 * Order templates worst-p75 LCP first for CCSS queue prioritization.
		 *
		 * Read-only ordering signal (issue #1059): scores each template's
		 * sample URL via RUM::score_url_lcp() (RUM path p75 + latest
		 * PageSpeed trend LCP blend), blended with the template-segment
		 * weight from RUM::get_field_lcp_p75_by_segment() (issue #1388) so
		 * the slowest RUM LCP template dequeues before the fastest even
		 * when two templates share a path bucket. Worst p75 first so
		 * high-traffic slow pages get optimized CSS first. Fail-open:
		 * missing RUM/trends, disabled setting, or any failure returns FIFO
		 * template order. Cap, safelist, and purge-coupled invalidation
		 * semantics unchanged. Multisite-safe: per-site option reads only.
		 *
		 * @since 2.0.0
		 * @since 2.3.0 Blends the template-segment LCP weight (issue #1388).
		 * @param array<string, string> $templates Template identifier => Label.
		 * @return array<string, string> Ordered templates (same entries).
		 */
		public static function order_templates_by_rum_priority( array $templates ): array {
			try {
				if ( count( $templates ) < 2 ) {
					return $templates;
				}
				$enabled = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) && function_exists( 'get_option' ) ) {
					$settings = \PerformanceOptimise\Inc\Util::get_settings();
					if ( isset( $settings['file_optimisation']['ccssRumPriority'] ) ) {
						$enabled = (bool) $settings['file_optimisation']['ccssRumPriority'];
					}
				}
				if ( ! $enabled ) {
					return $templates;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_path_lcp_priority' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'score_url_lcp' ) ) {
					return $templates;
				}
				$priority = \PerformanceOptimise\Inc\RUM::get_path_lcp_priority();
				// Fetch trends once for the whole ordering pass instead of once
				// per template inside score_url_lcp() (issue #1059 review). A
				// trend-only site (no RUM samples yet) must still prioritize,
				// so only fall back to FIFO when both signals are empty.
				$trends = null;
				if ( class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) && method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) ) {
					$trends = \PerformanceOptimise\Inc\Pagespeed::get_trends();
					if ( ! is_array( $trends ) ) {
						$trends = array();
					}
				}
				if ( empty( $priority ) && empty( $trends ) ) {
					return $templates;
				}
				// Template-segment weights (issue #1388): worst p75 LCP per
				// template slug from the RUM `template` segment, so two
				// templates sharing a path bucket are still distinguishable.
				// Guarded with class/method_exists plus a legacy fallback to
				// the URL-only score when the segment API is unavailable.
				$template_weights = array();
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_p75_by_segment' ) ) {
						$rows = \PerformanceOptimise\Inc\RUM::get_field_lcp_p75_by_segment();
						if ( is_array( $rows ) ) {
							foreach ( $rows as $row ) {
								if ( ! is_array( $row ) || ! isset( $row['template'], $row['p75'] ) ) {
									continue;
								}
								$slug = strtolower( trim( (string) $row['template'] ) );
								$p75  = (float) $row['p75'];
								if ( '' === $slug || $p75 <= 0 ) {
									continue;
								}
								if ( ! isset( $template_weights[ $slug ] ) || $p75 > $template_weights[ $slug ] ) {
									$template_weights[ $slug ] = $p75;
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$template_weights = array();
				}
				$scores = array();
				foreach ( $templates as $template => $label ) {
					$url       = self::get_sample_url( (string) $template );
					$url_score = ( is_string( $url ) && '' !== $url ) ? \PerformanceOptimise\Inc\RUM::score_url_lcp( $url, $priority, $trends ) : 0.0;
					// Slowest-LCP-template-first (issue #1388): the template
					// dequeues on the worst of its URL score and its
					// template-segment score, never the best.
					$slug                         = strtolower( trim( (string) $template ) );
					$segment_score                = $template_weights[ $slug ] ?? 0.0;
					$scores[ (string) $template ] = max( (float) $url_score, (float) $segment_score );
				}
				$has_signal = false;
				foreach ( $scores as $score ) {
					if ( $score > 0 ) {
						$has_signal = true;
						break;
					}
				}
				if ( ! $has_signal ) {
					return $templates;
				}
				$order = array_keys( $templates );
				// usort() is not stable: break score ties by original FIFO
				// position so equal-score templates keep a deterministic order.
				$pos = array_flip( array_keys( $templates ) );
				usort(
					$order,
					static function ( $a, $b ) use ( $scores, $pos ) {
						$sa = $scores[ (string) $a ] ?? 0.0;
						$sb = $scores[ (string) $b ] ?? 0.0;
						if ( $sa === $sb ) {
							return ( $pos[ (string) $a ] ?? 0 ) <=> ( $pos[ (string) $b ] ?? 0 );
						}
						return $sa > $sb ? -1 : 1;
					}
				);
				$ordered = array();
				foreach ( $order as $template ) {
					$ordered[ $template ] = $templates[ $template ];
				}
				return $ordered;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return $templates;
			}
		}

		/**
		 * Regenerate all template CCSS files via Action Scheduler.
		 *
		 * Skipped (returns 0, existing variants preserved) while deferJS or
		 * delayJS is active: generated variants could not be used, and
		 * deleting usable variants would be destructive if the operator later
		 * disables deferred/delayed JS (issue #1090).
		 *
		 * The RUM-ordered queue is capped per run (issue #1164): only the
		 * first N RUM-worst templates are queued so one run cannot flood the
		 * scheduler; the next cron run picks up the remainder. Fail-open: an
		 * invalid cap queues everything (current behaviour). Each template
		 * runs under the 25s generation budget (max 120s), so fan-out spans
		 * multiple cron runs rather than one long synchronous loop. Jobs are
		 * staggered 60s apart (issue #1235 review) on the dedicated `wppo-ccss`
		 * AS group so up to 5 full-budget runs never hold the shared
		 * `performance_optimisation` worker back-to-back.
		 *
		 * The 18000s full-regen cooldown (issue #1462) mirrors the used-CSS
		 * pipeline: repeat callers inside the window return 0 without
		 * re-scanning, unless $force bypasses it (explicit operator paths).
		 *
		 * @param bool $force Bypass the cooldown (explicit operator paths:
		 *                    builder purge after a wipe, manual triggers).
		 * @return int Number of jobs queued.
		 * @since 2.0.0
		 * @since 2.2.0 Capped per-run queue with RUM-worst-first ordering.
		 * @since 2.3.0 Added $force parameter and 18000s full-regen cooldown.
		 */
		public static function regenerate_all( bool $force = false ): int {
			if ( self::is_deferral_suspended_by_js() ) {
				return 0;
			}
			if ( ! $force && self::is_full_regen_cooled_down() ) {
				return 0;
			}
			$templates = self::get_templates();
			$queued    = 0;

			// Enumerate once: pass the list through so clear_all() does not
			// re-scan the theme directory (issue #1274 review).
			self::clear_all( $templates );

			$templates = self::order_templates_by_rum_priority( $templates );

			try {
				$cap = self::get_ccss_queue_cap();
				if ( PHP_INT_MAX !== $cap && count( $templates ) > $cap ) {
					$templates = array_slice( $templates, 0, $cap, true );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				// Skip-and-continue (issue #1274): templates with no
				// renderable sample URL (e.g. builder-template-only sites)
				// are marked `skipped`, never queued into an error loop.
				try {
					if ( ! self::get_sample_url( (string) $template ) ) {
						self::set_status_cache( $hash, 'skipped', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
						self::clear_ccss_retry_state( $hash );
						continue;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$pending_now = false;
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$hook = 'wppo_generate_ccss';
					// Wrapped payload: AS unpacks args positionally, so the
					// callback must receive the assoc array as one argument.
					$hook_args = array( array( 'template_hash' => $hash ) );
					// Atomic-first via the shared enqueue-gate helper
					// (issue #1310 review): on AS 4.x the unique insert
					// dedupes by itself, so no pre-check SELECTs run per
					// template — the both-group re-check only fires on a 0
					// return. Only genuine inserts (`id > 0`) advance the
					// 60s stagger and the queued count; a lost unique-race
					// (verifiably pending, foreign winner) marks status
					// without inflating the count or shifting the stagger.
					// Stagger jobs 60s apart (issue #1235 review) so the
					// burst never holds the worker for back-to-back
					// full-budget runs on the dedicated `wppo-ccss` AS
					// group (issue #1235 review).
					$job         = self::schedule_ccss_job( $hook, $hook_args, time() + ( $queued * 60 ) );
					$pending_now = $job['pending'];
					if ( $job['id'] > 0 ) {
						++$queued;
					}
				}
				// Only mark queued when a job is actually pending/scheduled
				// (issue #1274 review): without Action Scheduler the status
				// must not claim queued with zero jobs queued.
				if ( $pending_now ) {
					self::set_status_cache( $hash, 'queued', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				}
			}

			self::mark_full_regen();

			Log::add(
				sprintf(
					/* translators: %d: Number of jobs queued */
					__( 'Critical CSS regeneration: %d jobs queued.', 'performance-optimisation' ),
					$queued
				)
			);

			return $queued;
		}

		/**
		 * Regenerate critical CSS for a single template (issue #1274).
		 *
		 * Per-URL/per-template counterpart to regenerate_all(): resolves the
		 * template slug (or hash) to one job, marks it `queued`, and
		 * schedules it on the dedicated `wppo-ccss` AS group. Templates
		 * with no renderable sample URL are marked `skipped` instead of
		 * queued (skip-and-continue, no error loop). Fail-open: unknown
		 * templates return 0 without queueing.
		 *
		 * @param string                    $template Template slug or template hash.
		 * @param array<string,string>|null $templates Optional pre-enumerated template map (reuses the caller's scan).
		 * @return int 1 when queued, 0 for legit skip/unknown, -1 when the scheduler is unavailable (infra failure, not a benign skip).
		 * @since 2.2.0
		 */
		public static function regenerate_single( string $template, ?array $templates = null ): int {
			try {
				if ( self::is_deferral_suspended_by_js() ) {
					return 0;
				}
				$template = trim( $template );
				if ( '' === $template ) {
					return 0;
				}
				if ( null === $templates ) {
					$templates = self::get_templates();
				}
				$slug = self::resolve_template_slug( $template, $templates );
				if ( '' === $slug ) {
					return 0;
				}
				$hash = self::get_template_hash( $slug );
				try {
					if ( ! self::get_sample_url( $slug ) ) {
						self::set_status_cache( $hash, 'skipped', defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 );
						self::clear_ccss_retry_state( $hash );
						return 0;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$hook      = 'wppo_generate_ccss';
					$hook_args = array( array( 'template_hash' => $hash ) );
					// HMAC-bound jobs (issue #1347): sign the inner payload
					// before scheduling so background_generate() can prove
					// the args were built by this site. Deterministic per
					// payload, so repeat requests still dedupe honestly.
					try {
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'sign_callback_payload' ) ) {
							$job_sig = Util::sign_callback_payload( $hook_args[0] );
							if ( is_string( $job_sig ) && '' !== $job_sig ) {
								$hook_args[0]['sig'] = $job_sig;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
					// Atomic-first via the shared enqueue-gate helper
					// (issue #1310 review): the unique insert dedupes by
					// itself on AS 4.x, so the both-group pending re-check
					// only fires on a 0 return. A 0 with no job pending is
					// a scheduler failure — return 0 instead of claiming
					// queued with zero jobs.
					$job = self::schedule_ccss_job( $hook, $hook_args, time() );
					if ( ! $job['pending'] ) {
						return 0;
					}
				} else {
					// Distinct sentinel (issue #1274 review): a missing
					// scheduler is infra failure, not a benign skip, so
					// the REST layer can return an error instead of a 200.
					return -1;
				}
				self::set_status_cache( $hash, 'queued', defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600 );
				return 1;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Clear all CCSS files and status transients.
		 *
		 * Also drops the bounded-retry counters (generic + timeout) so a
		 * manual regen never inherits stale escalation counts from before
		 * the clear (issue #1274 review).
		 *
		 * @param array<string, string>|null $templates Optional pre-enumerated template list (avoids a second theme-directory scan when the caller already has it).
		 * @return void
		 * @since 2.0.0
		 */
		public static function clear_all( ?array $templates = null ): void {
			$dir = self::get_ccss_dir();

			$filesystem = Util::init_filesystem();
			global $wp_filesystem;

			if ( $wp_filesystem && $wp_filesystem->is_dir( $dir ) ) {
				$wp_filesystem->delete( $dir, true );
			}

			// Files are gone — the per-request existence memo must not keep
			// reporting them (audit #874 finding 7); a plain clearstatcache()
			// drops all per-path stat entries so file_exists cannot serve stale
			// results for individual deleted hash files (admin-only path).
			self::reset_ccss_memo();
			clearstatcache();

			// Also clear status transients (WP <6.9 fallback) and bump the
			// salted-cache salt so every WP 6.9+ salted status entry invalidates
			// at once without enumerating hashes (issue #882).
			if ( null === $templates ) {
				$templates = self::get_templates();
			}
			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				delete_transient( Util::transient_key( 'wppo_ccss_status_' . $hash ) );
				// Drop the bounded-retry counters too so regenerate_all()
				// (which clears then re-queues) starts every template from
				// zero instead of failing fast on a stale count.
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( Util::transient_key( 'wppo_ccss_attempts_' . $hash ) );
					delete_transient( Util::transient_key( 'wppo_ccss_timeout_' . $hash ) );
					delete_transient( Util::transient_key( 'wppo_ccss_timeout_first_' . $hash ) );
				}
				// Drop the source baselines too so a cleared variant cannot
				// keep a stale source domain around for the next generation
				// (audit #9).
				delete_transient( self::get_source_checksum_key( $hash ) );
				delete_transient( self::get_source_urls_key( $hash ) );
			}
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				// Monotonic increment: same-second mutations must produce
				// distinct salts (issue #882 review).
				update_option( self::SALT_KEY, (int) get_option( self::SALT_KEY, 0 ) + 1, false );
			}
		}

		/**
		 * Read a generation status from the salted object cache (WP 6.9+) or
		 * the transient fallback.
		 *
		 * The salt is the current option VALUE (Util::cache_salt) so a
		 * clear_all() bump invalidates every entry at once (issue #882).
		 *
		 * @since 2.0.0
		 *
		 * @param string $hash Template hash.
		 * @return string|false Status string, or false when unset.
		 */
		private static function get_status_cache( string $hash ) {
			$key = 'wppo_ccss_status_' . $hash;
			// Salted layer requires a persistent object cache; the transient
			// fallback keeps the status across requests otherwise (issue #882
			// review). clear_all() deletes the transients when it bumps.
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				// Salted eviction (TTL) without a bump: the transient written
				// by set_status_cache() is still fresh — reuse it instead of
				// reporting 'none' (issue #882 review). clear_all() deletes
				// the transients when it bumps the salt, so a post-bump read
				// cannot resurrect stale status.
				$cached = wp_cache_get_salted( $key, 'wppo', Util::cache_salt( self::SALT_KEY ) );
				return false !== $cached ? $cached : get_transient( Util::transient_key( $key ) );
			}
			return get_transient( Util::transient_key( $key ) );
		}

		/**
		 * Read the generation-status cache for the storage store (FUT-001).
		 *
		 * @internal Bridge for {@see \PerformanceOptimise\Inc\Ccss_Store::get_status_all()}.
		 * Keeps get_status_cache() private while letting the extracted store
		 * share the single salted/transient source of truth.
		 *
		 * @since NEXT
		 * @param string $hash Template hash.
		 * @return string|false Status string, or false when unset.
		 */
		public static function get_status_cache_for_store( string $hash ) {
			return self::get_status_cache( $hash );
		}

		/**
		 * Store a generation status in the salted object cache (WP 6.9+) or
		 * the transient fallback.
		 *
		 * @since 2.0.0
		 *
		 * @param string $hash   Template hash.
		 * @param string $status Status value ('queued'|'processing'|'done'|'skipped'|'failed'|'rejected'; legacy 'ready'|'pending' still accepted on read).
		 * @param int    $ttl    Time to live in seconds.
		 * @return void
		 */
		private static function set_status_cache( string $hash, string $status, int $ttl ): void {
			$key = 'wppo_ccss_status_' . $hash;
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				wp_cache_set_salted( $key, $status, 'wppo', Util::cache_salt( self::SALT_KEY ), $ttl );
			}
			// Always write the transient too: it is the persistence fallback on
			// hosts without an external object cache (issue #882 review).
			set_transient( Util::transient_key( $key ), $status, $ttl );
		}

		/**
		 * Whether a CCSS generation job is pending or running in either AS group.
		 *
		 * Single home for the dedicated-group-plus-legacy-group disjunction
		 * so the pre-check and the post-race re-check can never drift apart
		 * (issue #1310 review): a concurrent winner in the legacy
		 * `performance_optimisation` group must dedupe exactly like one in
		 * `wppo-ccss`, never fall through to a double-schedule. Fail-open:
		 * returns false when the lookup API is unavailable or throws.
		 *
		 * The probe covers pending plus running/claimed (issue #1310
		 * review): a winner that transitioned to running between the 0
		 * return and the re-check must still disambiguate as "job live"
		 * rather than scheduler failure, otherwise the template is marked
		 * failed with a day TTL while its job runs.
		 *
		 * @since 2.2.0
		 * @param string $hook      Action hook.
		 * @param array  $hook_args Action arguments.
		 * @return bool True when a matching action is pending or running in either group.
		 */
		private static function has_pending_ccss_job( string $hook, array $hook_args ): bool {
			if ( ! function_exists( 'as_next_scheduled_action' ) ) {
				return false;
			}
			// Per-request memo (issue #1310 review): the frontend hot path
			// and per-template loops repeat identical probes; the
			// status-cache gate absorbs most repeats but the memo covers
			// the rest within one request.
			try {
				$memo_key = md5( $hook . serialize( $hook_args ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Per-request memo key only.
			} catch ( \Throwable $e ) {
				unset( $e );
				$memo_key = '';
			}
			if ( '' !== $memo_key && array_key_exists( $memo_key, self::$pending_memo ) ) {
				return self::$pending_memo[ $memo_key ];
			}
			$pending = false;
			try {
				if ( (bool) as_next_scheduled_action( $hook, $hook_args, self::CCSS_AS_GROUP )
					|| (bool) as_next_scheduled_action( $hook, $hook_args, 'performance_optimisation' ) ) {
					$pending = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fall through to the running backstop below instead of
				// returning false: a transient pending-lookup failure
				// while the winner is running must not misreport as no
				// job (which would double-schedule plus mark failed with
				// a day TTL while a job runs).
			}
			if ( $pending ) {
				if ( '' !== $memo_key ) {
					self::$pending_memo[ $memo_key ] = true;
				}
				return true;
			}
			// Running/claimed backstop: only when the store lookup API is
			// available; a missing API fails open to the pending result
			// above (false here).
			$result = false;
			try {
				if ( function_exists( 'as_get_scheduled_actions' ) && class_exists( \ActionScheduler_Store::class ) ) {
					foreach ( array( self::CCSS_AS_GROUP, 'performance_optimisation' ) as $ccss_group ) {
						$running = as_get_scheduled_actions(
							array(
								'hook'     => $hook,
								'args'     => $hook_args,
								'group'    => $ccss_group,
								'status'   => \ActionScheduler_Store::STATUS_RUNNING,
								'per_page' => 1,
							),
							'ids'
						);
						if ( is_array( $running ) && ! empty( $running ) ) {
							$result = true;
							break;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$result = false;
			}
			if ( '' !== $memo_key ) {
				self::$pending_memo[ $memo_key ] = $result;
			}
			return $result;
		}
	}
}
