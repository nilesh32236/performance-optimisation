<?php
/**
 * Critical CSS Generation for above-the-fold optimization.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
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
	 * @since NEXT
	 */
	class Critical_CSS {

		/**
		 * Directory for CCSS files.
		 *
		 * @var string
		 * @since NEXT
		 */
		private const CCSS_DIR = '/cache/wppo/ccss';

		/**
		 * Option key of the generation-status cache salt (WP 6.9+ salted
		 * object cache; issue #882). Bumped by clear_all() so every salted
		 * status entry invalidates at once without enumerating hashes.
		 *
		 * @since NEXT
		 * @var string
		 */
		private const SALT_KEY = 'wppo_ccss_salt';

		/**
		 * Per-request CCSS existence memo keyed by template hash (audit #874
		 * finding 7). Reset via reset_ccss_memo() from the mutators.
		 *
		 * @since NEXT
		 * @var array<string, bool>
		 */
		private static array $ccss_exists_cache = array();

		/**
		 * Per-request CCSS content memo keyed by "hash:mtime" (audit #874
		 * finding 7). Reset via reset_ccss_memo() from the mutators.
		 *
		 * @since NEXT
		 * @var array<string, string|null>
		 */
		private static array $ccss_content_cache = array();

		/**
		 * Per-request sample-URL memo keyed by "{blog_id}:{template}" so a
		 * switch_to_blog() mid-request cannot serve the previous blog's URL.
		 * Reset via reset_ccss_memo().
		 *
		 * @since NEXT
		 * @var array<string, string|false>
		 */
		private static array $sample_url_cache = array();

		/**
		 * Above-fold selectors to match during extraction.
		 *
		 * Uses precise token-based matching to avoid false positives.
		 *
		 * @var string[]
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
		 */
		private const MAX_IMPORT_DEPTH = 3;

		/**
		 * Default cap for inlined critical CSS in bytes (20 KB).
		 *
		 * Stays under core's `styles_inline_size_limit` on every supported
		 * core version (20K pre-6.9 / 40K on 6.9+). Overridable per site via
		 * the `file_optimisation.ccssMaxSize` setting.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const DEFAULT_CCSS_MAX_SIZE = 20480;

		/**
		 * Minimum CCSS payload worth inlining.
		 *
		 * Shorter output is treated as a failed extraction: the async loader
		 * stub is printed and background regeneration is queued instead.
		 *
		 * @since NEXT
		 * @var int
		 */
		private const MIN_INLINE_SIZE = 500;

		/**
		 * Get the CCSS directory path.
		 *
		 * @return string
		 * @since NEXT
		 */
		private static function get_ccss_dir(): string {
			if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
				return '';
			}
			return wp_normalize_path( WP_CONTENT_DIR . self::CCSS_DIR );
		}

		/**
		 * Get the CCSS directory URL.
		 *
		 * @return string
		 * @since NEXT
		 */
		private static function get_ccss_url(): string {
			if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
				return '';
			}
			return WP_CONTENT_URL . self::CCSS_DIR;
		}

		/**
		 * Generate a unique template hash for a template slug + stylesheet.
		 *
		 * @param string $template Optional template slug. Defaults to current template via get_current_template_slug().
		 * @return string MD5 hash.
		 * @since NEXT
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
		 * @since NEXT
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
		 * Get the CCSS file path for a template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @return string Full file path.
		 * @since NEXT
		 */
		private static function get_ccss_file( string $template_hash ): string {
			$dir = self::get_ccss_dir();
			if ( '' === $dir ) {
				return '';
			}
			return $dir . '/' . $template_hash . '.css';
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
		 * @since NEXT
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
		 * Cuts at the last closing brace at or under the cap so output never
		 * ends mid-rule. Returns an empty string when no complete rule fits —
		 * callers treat that as over-cap and serve the file variant instead.
		 *
		 * @param string $css CSS content.
		 * @param int    $cap Maximum bytes.
		 * @return string Truncated CSS, or '' when nothing fits.
		 * @since NEXT
		 */
		public static function truncate_to_cap( string $css, int $cap ): string {
			if ( strlen( $css ) <= $cap ) {
				return $css;
			}
			$cut = strrpos( substr( $css, 0, $cap ), '}' );
			if ( false === $cut ) {
				return '';
			}
			return substr( $css, 0, $cut + 1 );
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
		 * @since NEXT
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
			 * @since NEXT
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
		 * @since NEXT
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
		 * Stable content hash of CSS source (issue #1038).
		 *
		 * Thin backward-compatible wrapper around the shared
		 * {@see Util::compute_css_checksum()} (audit #7) so existing callers
		 * and tests keep working while both CSS pipelines share one
		 * implementation.
		 *
		 * @param string $css CSS content.
		 * @return string SHA-256 checksum, or '' for empty input.
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
		 */
		private static function get_source_checksum_ttl(): int {
			/**
			 * Filters how long a Critical CSS source checksum is kept.
			 *
			 * @param int $ttl Time to live in seconds. Default WEEK_IN_SECONDS.
			 * @since NEXT
			 */
			$ttl = function_exists( 'apply_filters' ) ? (int) apply_filters( 'wppo_ccss_checksum_ttl', WEEK_IN_SECONDS ) : WEEK_IN_SECONDS;
			return $ttl > 0 ? $ttl : WEEK_IN_SECONDS;
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @param string[] $urls Ordered stylesheet URLs (document/queue order).
		 * @return string Concatenated source CSS, or '' when none resolve locally.
		 * @since NEXT
		 */
		private static function build_local_source_css( array $urls ): string {
			$combined = '';
			$count    = 0;
			$seen     = array();
			foreach ( $urls as $url ) {
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
			if ( ! self::is_inline_allowed() ) {
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
		 * Inert without a user safelist: the checksum auto-regen is part of the
		 * `ccssSafelistExtra` feature (issue #1038), so an empty safelist keeps
		 * the pre-feature behaviour verbatim — no new regeneration churn.
		 *
		 * @param string $template_hash Template hash.
		 * @return bool True when the stored variant was dropped as stale.
		 * @since NEXT
		 */
		public static function maybe_check_stale_and_requeue( string $template_hash ): bool {
			if ( '' === $template_hash ) {
				return false;
			}
			if ( array_key_exists( $template_hash, self::$stale_probe_memo ) ) {
				return self::$stale_probe_memo[ $template_hash ];
			}
			$result = false;
			try {
				// Fail-open gate: no user safelist configured means the feature
				// is off — preserve the pre-#1038 behaviour (no regeneration).
				if ( array() !== self::get_ccss_safelist() && function_exists( 'get_transient' ) ) {
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
		 * Read core's `styles_inline_size_limit` budget.
		 *
		 * Delegates to the single shared implementation in
		 * {@see Util::get_styles_inline_limit()} so Cache and Critical_CSS
		 * cannot disagree during the 6.9 pre-release window.
		 *
		 * @return int The inline size limit in bytes.
		 * @since NEXT
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
		 * @since NEXT
		 */
		private static function is_inline_allowed(): bool {
			if ( ! function_exists( 'apply_filters' ) ) {
				return true;
			}
			return (bool) apply_filters( 'wppo_inline_combined_css', true );
		}

		/**
		 * File-first CCSS URL with mtime cache busting.
		 *
		 * Per-template variants are already stored as files; this exposes them
		 * for over-cap delivery as `<hash>.css?ver=<mtime>` so a regenerate
		 * automatically busts browser/CDN caches. The URL passes through
		 * CDN::rewrite_url() when a CDN mapping is configured.
		 *
		 * @param string $template_hash Template hash.
		 * @return string File URL with mtime version, or '' when unavailable.
		 * @since NEXT
		 */
		private static function get_ccss_file_url( string $template_hash ): string {
			$file = self::get_ccss_file( $template_hash );
			$base = self::get_ccss_url();
			if ( '' === $file || '' === $base || ! file_exists( $file ) ) {
				return '';
			}
			$mtime = filemtime( $file );
			if ( false === $mtime ) {
				return '';
			}
			$url = $base . '/' . $template_hash . '.css?ver=' . $mtime;
			if ( class_exists( 'PerformanceOptimise\Inc\CDN' ) && method_exists( 'PerformanceOptimise\Inc\CDN', 'rewrite_url' ) ) {
				$url = CDN::rewrite_url( $url );
			}
			return $url;
		}

		/**
		 * Size metadata for a stored CCSS variant.
		 *
		 * Computed live from the file so it is always fresh (no extra
		 * transient to invalidate on regenerate); `truncated` reports whether
		 * the variant exceeds the configured inline cap.
		 *
		 * @param string $template_hash Template hash.
		 * @return array{size: int, truncated: bool, mtime: int} Size in bytes,
		 *                                                      over-cap flag, and file mtime (0 when missing).
		 * @since NEXT
		 */
		public static function get_ccss_meta( string $template_hash ): array {
			$file = self::get_ccss_file( $template_hash );
			if ( '' === $file || ! file_exists( $file ) ) {
				return array(
					'size'      => 0,
					'truncated' => false,
					'mtime'     => 0,
				);
			}
			$size  = filesize( $file );
			$mtime = filemtime( $file );
			$size  = false === $size ? 0 : (int) $size;
			return array(
				'size'      => $size,
				'truncated' => $size > self::get_ccss_max_size(),
				'mtime'     => false === $mtime ? 0 : (int) $mtime,
			);
		}

		/**
		 * Reset the per-request CCSS existence/content memos.
		 *
		 * Called by the mutators (generate_and_store, clear_all) so a
		 * same-request generation or deletion stays visible to ccss_exists()
		 * and get_ccss_content() (audit #874 finding 7).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_ccss_memo(): void {
			self::$ccss_exists_cache  = array();
			self::$ccss_content_cache = array();
			self::$sample_url_cache   = array();
			self::$stale_probe_memo   = array();
		}

		/**
		 * Invalidate the per-request CCSS memos for a single template hash.
		 *
		 * Unlike reset_ccss_memo() this keeps entries for other templates so a
		 * single-template write inside a multi-template loop (get_status_all →
		 * bulk regeneration) does not evict unrelated memo entries.
		 *
		 * @since NEXT
		 * @param string $template_hash The template hash.
		 * @return void
		 */
		private static function invalidate_ccss_memo( string $template_hash ): void {
			unset( self::$ccss_exists_cache[ $template_hash ] );
			foreach ( array_keys( self::$ccss_content_cache ) as $key ) {
				// Content keys are "{hash}:{mtime}:{size}" — drop every
				// versioned entry belonging to this hash.
				if ( 0 === strpos( $key, $template_hash . ':' ) ) {
					unset( self::$ccss_content_cache[ $key ] );
				}
			}
		}

		/**
		 * Check if CCSS exists for a template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @return bool
		 * @since NEXT Per-request memo (audit #874 finding 7), reset via reset_ccss_memo().
		 */
		public static function ccss_exists( string $template_hash ): bool {
			// Per-request memo (audit #874 finding 7): get_status_all() stats
			// one file per template and REST consumers may call it repeatedly
			// within a request. The memo is invalidated by the mutators
			// (reset_ccss_memo from generate_and_store / clear_all), so a
			// same-request generation stays visible.
			if ( array_key_exists( $template_hash, self::$ccss_exists_cache ) ) {
				return self::$ccss_exists_cache[ $template_hash ];
			}

			self::$ccss_exists_cache[ $template_hash ] = file_exists( self::get_ccss_file( $template_hash ) );

			return self::$ccss_exists_cache[ $template_hash ];
		}

		/**
		 * Read the critical CSS for a template hash through a per-request
		 * content memo keyed by mtime.
		 *
		 * Avoids the double stat + read per frontend hit (audit #874 finding
		 * 7): a regenerated file has a new mtime and therefore re-reads, so
		 * the inline contract is unaffected. Returns null when the file is
		 * missing or unreadable.
		 *
		 * @since NEXT
		 * @param string $template_hash Template hash.
		 * @return string|null
		 */
		private static function get_ccss_content( string $template_hash ): ?string {
			$file = self::get_ccss_file( $template_hash );
			if ( ! self::ccss_exists( $template_hash ) ) {
				return null;
			}

			$mtime = filemtime( $file );
			if ( false === $mtime ) {
				return null;
			}

			// mtime has 1-second granularity; include the size so two
			// regenerations within the same second cannot serve stale content
			// for the rest of the request.
			$filesize  = filesize( $file );
			$cache_key = $template_hash . ':' . $mtime . ':' . ( false === $filesize ? -1 : $filesize );
			if ( array_key_exists( $cache_key, self::$ccss_content_cache ) ) {
				return self::$ccss_content_cache[ $cache_key ];
			}

			// Size guard: a corrupted or adversarially large cache file must
			// not be buffered entirely into memory. Treat over-cap files as
			// a cache miss (same 1MB cap pattern as System_Info/Object_Cache).
			if ( false === $filesize || $filesize > 1048576 ) {
				self::$ccss_content_cache[ $cache_key ] = null;
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file outside the WP filesystem abstraction.
			$content = file_get_contents( $file );
			// An empty file is a failed generation: treat it as missing so the
			// caller queues background regeneration instead of looping on the
			// sub-500B guard forever.
			self::$ccss_content_cache[ $cache_key ] = is_string( $content ) && '' !== $content ? $content : null;

			return self::$ccss_content_cache[ $cache_key ];
		}

		/**
		 * Get all registered template hashes and their status.
		 *
		 * Returns an associative array where keys are template hashes and values
		 * are arrays with 'status', 'label', 'size', and 'truncated' keys. The
		 * size fields are computed live from the stored file variants so the
		 * SPA can display the capped state without an extra lookup.
		 *
		 * @return array<string, array{status: string, label: string, size: int, truncated: bool}> Template hash => status + label + size.
		 * @since NEXT
		 */
		public static function get_status_all(): array {
			$templates = self::get_templates();
			$statuses  = array();

			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				if ( self::ccss_exists( $hash ) ) {
					$meta              = self::get_ccss_meta( $hash );
					$statuses[ $hash ] = array(
						'status'    => 'ready',
						'label'     => $label,
						'size'      => $meta['size'],
						'truncated' => $meta['truncated'],
					);
				} else {
					$cache_status      = self::get_status_cache( $hash );
					$statuses[ $hash ] = array(
						'status'    => $cache_status ? $cache_status : 'none',
						'label'     => $label,
						'size'      => 0,
						'truncated' => false,
					);
				}
			}

			return $statuses;
		}

		/**
		 * Get the list of supported templates.
		 *
		 * @return array<string, string> Template identifier => Label.
		 * @since NEXT
		 */
		private static function get_templates(): array {
			$templates = array(
				'index'   => __( 'Default', 'performance-optimisation' ),
				'home'    => __( 'Home', 'performance-optimisation' ),
				'single'  => __( 'Single Post', 'performance-optimisation' ),
				'page'    => __( 'Page', 'performance-optimisation' ),
				'archive' => __( 'Archive', 'performance-optimisation' ),
			);

			$page_templates = function_exists( 'get_page_templates' ) ? get_page_templates() : array();
			if ( ! empty( $page_templates ) ) {
				foreach ( $page_templates as $label => $file ) {
					$templates[ $file ] = $label;
				}
			}

			return $templates;
		}

		/**
		 * Get a sample URL for a given template.
		 *
		 * @param string $template Template identifier.
		 * @return string|false URL or false if not found.
		 * @since NEXT
		 */
		private static function get_sample_url( string $template ): string|false {
			$blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			$cache_key = $blog_id . ':' . $template;
			if ( array_key_exists( $cache_key, self::$sample_url_cache ) ) {
				return self::$sample_url_cache[ $cache_key ];
			}
			switch ( $template ) {
				case 'home':
					self::$sample_url_cache[ $cache_key ] = Util::cached_home_url( '/' );
					break;
				case 'single':
					$posts                                = get_posts(
						array(
							'numberposts'   => 1,
							'post_status'   => 'publish',
							'has_password'  => false,
							'fields'        => 'ids',
							'no_found_rows' => true,
						)
					);
					self::$sample_url_cache[ $cache_key ] = ! empty( $posts ) ? get_permalink( $posts[0] ) : false;
					break;
				case 'page':
					$pages                                = get_posts(
						array(
							'post_type'     => 'page',
							'numberposts'   => 1,
							'post_status'   => 'publish',
							'has_password'  => false,
							'fields'        => 'ids',
							'no_found_rows' => true,
						)
					);
					self::$sample_url_cache[ $cache_key ] = ! empty( $pages ) ? get_permalink( $pages[0] ) : Util::cached_home_url( '/' );
					break;
				case 'archive':
					$archives = get_posts(
						array(
							'numberposts'   => 1,
							'post_status'   => 'publish',
							'has_password'  => false,
							'fields'        => 'ids',
							'no_found_rows' => true,
						)
					);
					if ( ! empty( $archives ) ) {
						setup_postdata( $archives[0] );
						$year  = get_the_time( 'Y' );
						$month = get_the_time( 'm' );
						wp_reset_postdata();
						self::$sample_url_cache[ $cache_key ] = get_month_link( $year, $month );
					} else {
						self::$sample_url_cache[ $cache_key ] = false;
					}
					break;
				default:
					self::$sample_url_cache[ $cache_key ] = Util::cached_home_url( '/' );
					break;
			}
			return self::$sample_url_cache[ $cache_key ];
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
		 * @return string|false The critical CSS content, or false on failure.
		 * @since NEXT
		 */
		public static function generate( string $url, ?string &$source_css = null, ?array &$resolved_urls = null ) {
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 30,
					'user-agent' => 'WPPO Critical CSS Generator/' . WPPO_VERSION,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
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
					// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOMNode property.
					$content = trim( $tag->textContent );
					if ( ! empty( $content ) ) {
						$css_content .= $content . "\n";
					}
				}
			}

			// Extract external stylesheet URLs (skip data-* handles, dashicons, admin-bar).
			$link_tags   = $xpath->query( '//link[@rel="stylesheet"]' );
			$source_urls = array();
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

					$fetched = self::fetch_stylesheet_with_imports( $href );
					if ( '' !== $fetched ) {
						$css_content .= $fetched . "\n";
					}
				}
			}

			// Canonical source domain: the locally-resolvable stylesheets the
			// page emitted, in document order. generate_and_store() baselines
			// the checksum of this string AND persists $source_urls so the
			// frontend probe re-hashes the exact same document-ordered list
			// instead of re-deriving one from $wp_styles order (audit #9), so
			// an unchanged source compares equal instead of churning forever.
			$source_css = self::build_local_source_css( $source_urls );
			if ( null !== $resolved_urls ) {
				$resolved_urls = $source_urls;
			}

			if ( empty( $css_content ) ) {
				return false;
			}

			// Apply heuristic extraction: keep only above-fold rules.
			$critical = self::extract_above_fold_css( $css_content );

			if ( empty( $critical ) ) {
				return false;
			}

			// Minify the critical CSS.
			try {
				$minifier = new CSSMinifier( $critical );
				$critical = $minifier->minify();
			} catch ( \Exception $e ) {
				// Fall back to unminified if minification fails — $critical stays as-is.
				unset( $e );
			}

			return $critical;
		}

		/**
		 * Fetch a stylesheet and recursively resolve @import directives.
		 *
		 * @param string $url   The stylesheet URL.
		 * @param int    $depth Current recursion depth.
		 * @return string The combined CSS content with @imports inlined, or empty string on failure.
		 * @since NEXT
		 */
		private static function fetch_stylesheet_with_imports( string $url, int $depth = 0 ): string {
			if ( $depth > self::MAX_IMPORT_DEPTH ) {
				return '';
			}

			// SSRF guard: refuse invalid or internal targets before any request.
			if ( ! self::is_safe_stylesheet_url( $url ) ) {
				return '';
			}

			$args = array(
				'timeout'    => 15,
				'user-agent' => 'WPPO Critical CSS Generator/' . WPPO_VERSION,
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

			// Resolve @import directives recursively.
			preg_match_all( '/@import\s+(?:url\([\'"]?|[\'"])([^\'";)]+)(?:[\'"]?\))?[\'"]?\s*;/i', $content, $imports );

			if ( ! empty( $imports[1] ) ) {
				foreach ( $imports[1] as $import_url ) {
					$resolved = self::resolve_import_url( trim( $import_url ), $url );
					if ( '' !== $resolved ) {
						$imported = self::fetch_stylesheet_with_imports( $resolved, $depth + 1 );
						if ( '' !== $imported ) {
							$content .= "\n" . $imported;
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
		 * @since NEXT
		 */
		private static function resolve_import_url( string $import_url, string $base_url ): string {
			// If already absolute, only allow safe destinations (defense-in-depth;
			// the fetch layer validates again before requesting).
			if ( preg_match( '/^https?:\/\//i', $import_url ) ) {
				return self::is_safe_stylesheet_url( $import_url ) ? $import_url : '';
			}

			// If protocol-relative, prepend the base scheme.
			if ( 0 === strpos( $import_url, '//' ) ) {
				$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
				return $scheme ? $scheme . ':' . $import_url : 'https:' . $import_url;
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
		 * @since NEXT
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
		 * accepted, restricted to hosts that either pass core validation, belong
		 * to this site, or are explicitly allowlisted via the
		 * wppo_ccss_allowed_stylesheet_host filter.
		 *
		 * @param string $url The stylesheet URL.
		 * @return bool True when safe to fetch.
		 * @since NEXT
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
			 * @since NEXT
			 */
			if ( apply_filters( 'wppo_ccss_allowed_stylesheet_host', false, $host ) ) {
				return true;
			}

			if ( self::is_same_site_host( $url ) ) {
				return true;
			}

			return false !== wp_http_validate_url( $url );
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
		 * @since NEXT
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
		 * @since NEXT
		 */
		private static function contains_unsafe_css_tokens( string $css ): bool {
			$decoded = self::decode_css_entities( $css );
			// Note: behaviou?r only matches in property position (followed
			// by a colon) so benign selectors like .behavior-badge pass.
			return (bool) preg_match( '/<\/style|<script|<!--|-->|expression\s*\(|javascript\s*:|vbscript\s*:|behaviou?r(?=\s*:)|-moz-binding|&(lt|gt|amp|quot|#\d+|#x[0-9a-f]+);?/i', $decoded );
		}

		/**
		 * Sanitize generated critical CSS for safe output inside a <style> tag.
		 *
		 * Token-breaking worker shared by sanitize_inline_css() (applied
		 * both before and after the `wppo_ccss_sanitize_inline` filter so
		 * hooked code cannot reintroduce breakout tokens). Neutralizes the
		 * `</style>` raw-text terminator plus `script`, comment
		 * (`<!--`/`-->`), and CSS expression vectors (`expression()`,
		 * `javascript:`/`vbscript:` URLs, the `behavior` / `behaviour`
		 * property in property position only, `-moz-binding`)
		 * case-insensitively, decodes numeric/hex entities first so encoded
		 * payloads cannot smuggle `<` past the encoder, then encodes any
		 * remaining `<` as the equivalent CSS escape so stored CSS can never
		 * break out of the style element. Fail-closed: a sanitizer error
		 * drops the block (returns '') so output degrades to unoptimized
		 * markup, never script execution.
		 *
		 * @param string $css Raw critical CSS.
		 * @return string Sanitized critical CSS.
		 * @since NEXT
		 */
		private static function sanitize_inline_css_tokens( string $css ): string {
			try {
				$css = self::decode_css_entities( $css );
				$css = str_ireplace( '</style', '<\/style', $css );
				$css = str_ireplace( '<script', '<\script', $css );
				$css = str_ireplace( '<!--', '<\!--', $css );
				$css = str_ireplace( '-->', '--\>', $css );
				// Break CSS expression/URL vectors, tolerating whitespace
				// between the keyword and its delimiter (e.g. 'expression (').
				// A callback builds the replacement so the backslash is never
				// parsed as a PCRE backreference.
				$css = (string) preg_replace_callback(
					'/expression\s*\(|javascript\s*:|vbscript\s*:/i',
					static function ( array $matches ): string {
						$token = $matches[0];
						return substr( $token, 0, -1 ) . '\\' . substr( $token, -1 );
					},
					$css
				);
				// Scope behavior/behaviour to property position (followed by
				// a colon) so benign selectors like .behavior-badge or
				// #behaviour-list keep working. A callback emits the
				// backslash literally instead of a PCRE backreference.
				$css = (string) preg_replace_callback(
					'/behaviou?r(?=\s*:)/i',
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
		 * @since NEXT
		 */
		private static function sanitize_inline_css( string $css ): string {
			$css = self::sanitize_inline_css_tokens( $css );

			/**
			 * Filters the inline critical CSS right before output.
			 *
			 * The return value is passed through the sanitizer again, so
			 * hooked code cannot reintroduce breakout tokens.
			 *
			 * @param string $css Sanitized critical CSS.
			 * @since NEXT
			 */
			if ( function_exists( 'has_filter' ) && has_filter( 'wppo_ccss_sanitize_inline' ) ) {
				$filtered = apply_filters( 'wppo_ccss_sanitize_inline', $css );
				if ( ! is_string( $filtered ) ) {
					return $css;
				}
				return self::sanitize_inline_css_tokens( $filtered );
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
		 * @param string $css Full CSS content.
		 * @return string Extracted critical CSS.
		 * @since NEXT
		 */
		private static function extract_above_fold_css( string $css ): string {
			$critical_parts = array();

			// Extract @font-face blocks.
			preg_match_all( '/@font-face\s*\{[^}]+\}/is', $css, $font_faces );
			if ( ! empty( $font_faces[0] ) ) {
				$critical_parts[] = implode( "\n", $font_faces[0] );
			}

			// Extract @keyframes blocks.
			preg_match_all( '/@keyframes\s+[^\{]+\{(?:[^{}]|\{[^{}]*\})*\}/is', $css, $keyframes );
			if ( ! empty( $keyframes[0] ) ) {
				$critical_parts[] = implode( "\n", $keyframes[0] );
			}

			// Extract CSS custom properties from :root.
			preg_match( '/:root\s*\{([^}]*)\}/i', $css, $root_match );
			if ( ! empty( $root_match[0] ) ) {
				$critical_parts[] = $root_match[0];
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
			// The safelist is fetched once and threaded through so settings
			// are not re-read per rule.
			$safelist = self::get_ccss_safelist();
			if ( ! empty( $mobile_queries[0] ) ) {
				foreach ( $mobile_queries[0] as $mq ) {
					$filtered = self::filter_media_query_rules( $mq, $safelist );
					if ( ! empty( $filtered ) ) {
						$critical_parts[] = $filtered;
					}
				}
			}

			// Extract regular rules using brace-depth-based parsing.
			// Handles minified CSS (single line), multi-line selectors, and nested braces.
			self::parse_regular_rules( $css, $critical_parts, $safelist );

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
		 * @return void
		 * @since NEXT
		 */
		private static function parse_regular_rules( string $css, array &$critical_parts, ?array $safelist = null ): void {
			$safelist = $safelist ?? self::get_ccss_safelist();
			$length   = strlen( $css );
			$depth    = 0;
			$buffer   = '';
			$selector = '';
			$in_rule  = false;

			for ( $i = 0; $i < $length; ++$i ) {
				$char = $css[ $i ];

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
				} elseif ( ! $in_rule ) {
					$buffer .= $char;
				} else {
					$buffer .= $char;
				}
			}
		}

		/**
		 * Filter rules inside a media query to keep only above-fold selectors.
		 *
		 * @param string        $media_query Full media query block.
		 * @param string[]|null $safelist Pre-fetched safelist (null = fetch once here).
		 * @return string Filtered media query or empty string.
		 * @since NEXT
		 */
		private static function filter_media_query_rules( string $media_query, ?array $safelist = null ): string {
			$header_end = strpos( $media_query, '{' );
			if ( false === $header_end ) {
				return '';
			}
			$header = substr( $media_query, 0, $header_end + 1 );
			$body   = substr( $media_query, $header_end + 1, -1 );

			$filtered_rules = array();
			$lines          = explode( '}', $body );

			foreach ( $lines as $rule ) {
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
		 * @since NEXT
		 */
		private static function matches_above_fold( string $selector, ?array $safelist = null ): bool {
			$selector = trim( $selector );

			if ( empty( $selector ) ) {
				return false;
			}

			$safelist = $safelist ?? self::get_ccss_safelist();

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
		 * @since NEXT
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
		 * @since NEXT
		 * @var array{exact: array<string, bool>, regex: string}|null
		 */
		private static ?array $above_fold_matcher = null;

		/**
		 * Build the precompiled above-fold matcher (exact hash sets + one alternation).
		 *
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * Generate and store CCSS for a given template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @param string $template      The template identifier.
		 * @return bool True on success, false on failure.
		 * @since NEXT
		 */
		private static function generate_and_store( string $template_hash, string $template ): bool {
			$url = self::get_sample_url( $template );
			if ( ! $url ) {
				self::set_status_cache( $template_hash, 'failed', DAY_IN_SECONDS );
				return false;
			}

			$source_css    = '';
			$resolved_urls = array();
			$critical_css  = self::generate( $url, $source_css, $resolved_urls );

			// Reject generated CSS that could break out of the <style> context when
			// inlined — a poisoned source stylesheet must never reach the CCSS cache.
			// Fail closed: drop the block instead of caching raw input.
			if ( false === $critical_css || self::contains_unsafe_css_tokens( $critical_css ) ) {
				self::set_status_cache( $template_hash, 'failed', DAY_IN_SECONDS );
				return false;
			}

			$dir = self::get_ccss_dir();
			if ( ! wp_mkdir_p( $dir ) ) {
				self::set_status_cache( $template_hash, 'failed', DAY_IN_SECONDS );
				return false;
			}

			$filesystem = Util::init_filesystem();
			if ( $filesystem ) {
				$filesystem->put_contents( self::get_ccss_file( $template_hash ), $critical_css, FS_CHMOD_FILE );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( self::get_ccss_file( $template_hash ), $critical_css );
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
			if ( '' !== $source_css ) {
				self::store_source_checksum( $template_hash, $source_css );
				self::store_source_urls( $template_hash, $resolved_urls );
			}

			// The memo must reflect the fresh file within this request too;
			// clear PHP's stat cache so file_exists/mtime are not stale. Only
			// this template's memo entries are invalidated — a bulk loop keeps
			// the other templates' memo entries.
			self::invalidate_ccss_memo( $template_hash );
			clearstatcache( true, self::get_ccss_file( $template_hash ) );

			if ( self::ccss_exists( $template_hash ) ) {
				self::set_status_cache( $template_hash, 'ready', WEEK_IN_SECONDS );
				return true;
			}

			self::set_status_cache( $template_hash, 'failed', DAY_IN_SECONDS );
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
		 * 2. Missing/unreadable variant — fail-open: queue background
		 *    generation and print the async loader stub (never fatal).
		 * 3. Content under MIN_INLINE_SIZE — treated as a failed extraction.
		 * 4. Content within the configured cap and core's
		 *    `styles_inline_size_limit` budget — inlined as before.
		 * 5. Over-cap content — file-first delivery: a render-blocking
		 *    `<link>` to the per-template variant with mtime cache busting
		 *    (no FOUC), while the full theme stylesheets are still deferred.
		 *
		 * @return void
		 * @since NEXT
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
			if ( ! self::is_inline_allowed() ) {
				return;
			}

			$template_slug = self::get_current_template_slug();
			$template_hash = self::get_template_hash( $template_slug );

			$content = self::get_ccss_content( $template_hash );
			if ( null !== $content ) {
				// Fallback when CCSS is too short (<500B) — treat as failed and inject async loadCSS guard.
				if ( strlen( $content ) < self::MIN_INLINE_SIZE ) {
					// Timer handles are tracked so the media-swap fallback is
					// cleared on pagehide/beforeunload (audit #1077 finding 5).
					echo '<script>!function(e){"use strict";var T=[],c=function(h){var i=T.indexOf(h);if(i>-1){T.splice(i,1)}clearTimeout(h)},n=function(n,t,o){var r=e.document.createElement("link"),a=t||e.document.getElementsByTagName("script")[0];r.rel="stylesheet",r.href=n,r.media="only x",a.parentNode.insertBefore(r,a);var h=setTimeout(function(){c(h),r.media=o||"all"},0);T.push(h),r.onload=function(){c(h),r.media=o||"all"}};e.wppoLoadCSS=n;var f=function(){for(var i=0;i<T.length;i++){clearTimeout(T[i])}T.length=0};e.addEventListener("pagehide",f),e.addEventListener("beforeunload",f)}(window);</script>' . "\n";
					return;
				}
				$cap   = self::get_ccss_max_size();
				$limit = self::get_styles_inline_limit();
				// Over-cap output (or output beyond core's inline budget) is
				// never inlined: serve the per-template file variant with
				// mtime cache busting instead. The plain stylesheet link is
				// render-blocking, so there is no FOUC; the remaining full
				// stylesheets are still deferred by defer_stylesheets().
				if ( strlen( $content ) > $cap || strlen( $content ) > $limit ) {
					$file_url = self::get_ccss_file_url( $template_hash );
					if ( '' !== $file_url ) {
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Per-template CCSS file variant served directly (no registered handle exists for it).
						echo '<link rel="stylesheet" id="wppo-critical-css" href="' . esc_url( $file_url ) . '" media="all" />' . "\n";
						return;
					}
					// File URL unavailable — fall back to truncated inline
					// output so the response still carries above-fold CSS.
					$truncated = self::truncate_to_cap( $content, min( $cap, $limit ) );
					if ( '' === $truncated ) {
						return;
					}
					echo '<style id="wppo-critical-css" data-truncated="1">' . "\n";
					// Sanitized against HTML breakout tokens; see sanitize_inline_css().
					echo self::sanitize_inline_css( $truncated ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content sanitized for the <style> context by sanitize_inline_css().
					echo '</style>' . "\n";
					return;
				}
				echo '<style id="wppo-critical-css">' . "\n";
				// Sanitized against HTML breakout tokens; see sanitize_inline_css().
				echo self::sanitize_inline_css( $content ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content sanitized for the <style> context by sanitize_inline_css().
				echo '</style>' . "\n";
			} else {
				// No CCSS file yet — queue async generation and never block the
				// response. generate() fetches the page with a 30s timeout, so
				// it must never run synchronously inside wp_head (audit #888
				// finding 2). The payload is wrapped in a single-element array
				// because Action Scheduler and WP-Cron both unpack stored args
				// positionally — background_generate( array $args ) must receive
				// the assoc array as its one argument.
				$hook_args = array( array( 'template_hash' => $template_hash ) );
				$queued    = false;
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$hook = 'wppo_generate_ccss';
					if ( as_next_scheduled_action( $hook, $hook_args, 'performance_optimisation' ) ) {
						$queued = true;
					} else {
						$queued = (bool) as_enqueue_async_action(
							$hook,
							$hook_args,
							'performance_optimisation'
						);
					}
				}

				if ( ! $queued ) {
					// Action Scheduler unavailable or enqueue failed — schedule
					// the same hook via WP-Cron as a fallback. Rendering is
					// never delayed: the async loader below keeps stylesheets
					// loading while the critical CSS is generated in background.
					if ( ! wp_next_scheduled( 'wppo_generate_ccss', $hook_args ) ) {
						$queued = (bool) wp_schedule_single_event(
							time() + MINUTE_IN_SECONDS,
							'wppo_generate_ccss',
							$hook_args
						);
					} else {
						$queued = true;
					}
				}

				if ( $queued ) {
					self::set_status_cache( $template_hash, 'pending', HOUR_IN_SECONDS );
				} else {
					// Nothing could be scheduled (e.g. cron disabled) — surface
					// the failure instead of reporting an hour of fake pending.
					self::set_status_cache( $template_hash, 'failed', DAY_IN_SECONDS );
				}

				// Non-blocking fallback: expose the async loader while the
				// critical CSS is generated in the background. Timer handles
				// are tracked so the media-swap fallback is cleared on
				// pagehide/beforeunload (audit #1077 finding 5).
				echo '<script>!function(e){"use strict";var T=[],c=function(h){var i=T.indexOf(h);if(i>-1){T.splice(i,1)}clearTimeout(h)},n=function(n,t,o){var r=e.document.createElement("link"),a=t||e.document.getElementsByTagName("script")[0];r.rel="stylesheet",r.href=n,r.media="only x",a.parentNode.insertBefore(r,a);var h=setTimeout(function(){c(h),r.media=o||"all"},0);T.push(h),r.onload=function(){c(h),r.media=o||"all"}};e.wppoLoadCSS=n;var f=function(){for(var i=0;i<T.length;i++){clearTimeout(T[i])}T.length=0};e.addEventListener("pagehide",f),e.addEventListener("beforeunload",f)}(window);</script>' . "\n";
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
		 * @since NEXT
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

			// Guard: when JS is deferred/delayed the onload swap never fires until JS runs.
			// This leaves cached pages unstyled (media=print deadlock with removeUnusedCSS + criticalCSS + combineCSS).
			// Keep media=all when either deferJS or delayJS is active so cached HTML stays styled.
			// @since NEXT.
			$fo = $options['file_optimisation'] ?? array();
			if ( ! empty( $fo['deferJS'] ) || ! empty( $fo['delayJS'] ) ) {
				return $tag;
			}

			foreach ( self::SKIP_DEFER_HANDLES as $skip ) {
				if ( $handle === $skip || false !== strpos( $href, $skip ) ) {
					return $tag;
				}
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
			if ( ! self::ccss_exists( self::get_template_hash() ) ) {
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
		 * Background generation callback for Action Scheduler.
		 *
		 * @param array $args Arguments containing 'template_hash'.
		 * @return void
		 * @since NEXT
		 */
		public static function background_generate( array $args ): void {
			$template_hash = $args['template_hash'] ?? '';
			if ( empty( $template_hash ) ) {
				return;
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
				self::set_status_cache( $template_hash, 'failed', DAY_IN_SECONDS );
				return;
			}

			$result = self::generate_and_store( $template_hash, $found_template );

			if ( $result ) {
				Log::add(
					sprintf(
						/* translators: %s: Template hash */
						__( 'Critical CSS generated for template: %s', 'performance-optimisation' ),
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

		/**
		 * Order templates worst-p75 LCP first for CCSS queue prioritization.
		 *
		 * Read-only ordering signal (issue #1059): scores each template's
		 * sample URL via RUM::score_url_lcp() (RUM path p75 + latest
		 * PageSpeed trend LCP blend). Worst p75 first so high-traffic slow
		 * pages get optimized CSS first. Fail-open: missing RUM/trends,
		 * disabled setting, or any failure returns FIFO template order.
		 * Cap, safelist, and purge-coupled invalidation semantics unchanged.
		 * Multisite-safe: per-site option reads only.
		 *
		 * @since NEXT
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
				$scores = array();
				foreach ( $templates as $template => $label ) {
					$url                          = self::get_sample_url( (string) $template );
					$scores[ (string) $template ] = ( is_string( $url ) && '' !== $url ) ? \PerformanceOptimise\Inc\RUM::score_url_lcp( $url, $priority, $trends ) : 0.0;
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
		 * @return int Number of jobs queued.
		 * @since NEXT
		 */
		public static function regenerate_all(): int {
			$templates = self::get_templates();
			$queued    = 0;

			self::clear_all();

			$templates = self::order_templates_by_rum_priority( $templates );

			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$hook = 'wppo_generate_ccss';
					// Wrapped payload: AS unpacks args positionally, so the
					// callback must receive the assoc array as one argument.
					$hook_args = array( array( 'template_hash' => $hash ) );
					if ( ! as_next_scheduled_action( $hook, $hook_args, 'performance_optimisation' ) ) {
						as_enqueue_async_action(
							$hook,
							$hook_args,
							'performance_optimisation'
						);
						++$queued;
					}
				}
				self::set_status_cache( $hash, 'pending', HOUR_IN_SECONDS );
			}

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
		 * Clear all CCSS files and status transients.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function clear_all(): void {
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
			$templates = self::get_templates();
			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				delete_transient( Util::transient_key( 'wppo_ccss_status_' . $hash ) );
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
		 * @since NEXT
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
		 * Store a generation status in the salted object cache (WP 6.9+) or
		 * the transient fallback.
		 *
		 * @since NEXT
		 *
		 * @param string $hash   Template hash.
		 * @param string $status Status value ('ready'|'pending'|'failed').
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
	}
}
