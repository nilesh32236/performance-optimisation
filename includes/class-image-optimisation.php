<?php
/**
 * Image Optimisation class for handling image conversion, preloading, and serving optimized images.
 *
 * This class is responsible for converting images to optimized formats (such as WebP or AVIF),
 * managing image preloading, and serving the optimized images based on the plugin settings.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Image_Optimisation' ) ) {

	/**
	 * Image Optimisation class.
	 *
	 * Handles image conversion, preloading, and serving optimized images.
	 *
	 * @since 1.0.0
	 */
	class Image_Optimisation {

		/**
		 * Default maximum width for preloading images.
		 *
		 * @since 1.5.1
		 */
		private const MAX_PRELOAD_WIDTH = 1478;

		/**
		 * Maximum dimension (px) for the generated SVG placeholder.
		 *
		 * Guards against malformed or extreme width/height attributes so
		 * placeholders cannot bloat memory or break layout.
		 *
		 * @since 2.0.0
		 */
		private const SVG_PLACEHOLDER_MAX_DIMENSION = 4096;

		/**
		 * Maximum number of entries retained in the per-request image-size cache.
		 *
		 * @since 2.0.0
		 */
		private const IMG_SIZE_CACHE_LIMIT = 100;

		/**
		 * Maximum image preload hints emitted per page (manual wins on conflict).
		 *
		 * Manual lists are ordered first in `get_all_preload_data()` so the
		 * slice keeps pinned heroes when auto + manual overlap. Competitor
		 * parity (one hero preload) with a hard cap against preload waste.
		 *
		 * @since NEXT
		 */
		private const MAX_LCP_PRELOADS = 2;

		/**
		 * Tags visited by the comment-image hardening passes.
		 *
		 * Single source of truth for the buffer fast-path, the
		 * quoted-aware opening-tag pass, and both Tag Processor loops
		 * (next-gen + lazy). Includes the `<image>` HTML-parser alias
		 * for `<img>`, plus carriers of denylisted URL attributes
		 * (`use`/`a` for href, `table`/`body`/`td`/`th` for background).
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const HARDENED_TAGS = array( 'img', 'image', 'source', 'video', 'iframe', 'audio', 'embed', 'object', 'svg', 'math', 'use', 'a', 'table', 'body', 'td', 'th' );

		/**
		 * Single-URL attributes routed through the scriptable-URL gate.
		 *
		 * Shared by the regex path and the Tag Processor path so they
		 * stay in parity (issue #1271 follow-up). `srcdoc` is dropped
		 * unconditionally; the rest are removed only when scriptable.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const HARDENED_URL_ATTRS = array( 'src', 'data-src', 'data', 'codebase', 'usemap', 'poster', 'srcdoc', 'background', 'lowsrc', 'href', 'xlink:href', 'action', 'formaction', 'cite', 'longdesc' );

		/**
		 * Srcset-family attributes filtered candidate-by-candidate.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const HARDENED_SRCSET_ATTRS = array( 'srcset', 'data-srcset' );

		/**
		 * Benign `on*`-prefixed attributes that are not event handlers.
		 *
		 * `is_event_attribute_name()` spares these so valid attributes
		 * (`only`, `one`, `online`, `once`, `onto`, `onion`) survive.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		private const BENIGN_ON_PREFIX_ATTRS = array( 'only', 'one', 'online', 'once', 'onto', 'onion' );

		/**
		 * Configuration options for image optimization.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private array $options;

		/**
		 * Counter for picture/LCP prioritization (first image = high, rest = async).
		 *
		 * @since 2.0.0
		 * @var int
		 */
		private int $picture_counter = 0;

		/**
		 * Array of image URLs to exclude from conversion.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_convert_imgs = array();

		/**
		 * Array of image URLs to preload on the front page.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $preload_front_page_urls = array();

		/**
		 * Array of image URLs to exclude from post type preloading.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_post_type_imgs = array();

		/**
		 * Array of image sizes to exclude.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_sizes = array();

		/**
		 * Array of image URLs to exclude from lazy loading.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_lazy_imgs = array();

		/**
		 * Array of video URLs to exclude from lazy loading.
		 *
		 * @var array
		 * @since 1.5.1
		 */
		private array $exclude_lazy_videos = array();

		/**
		 * Cached instance of Img_Converter to avoid repeated parsing of settings.
		 *
		 * @var Img_Converter|null
		 * @since 1.1.2
		 */
		private ?Img_Converter $img_converter = null;

		/**
		 * In-request static map for file_exists results to avoid repeated stat calls per image.
		 *
		 * Keyed by absolute path, value is bool. Bounded by FILE_EXISTS_CACHE_LIMIT
		 * to prevent unbounded growth on pages with many unique images.
		 *
		 * @var array<string,bool>
		 * @since 2.0.0
		 */
		private static array $file_exists_cache = array();

		/**
		 * Maximum entries in the file_exists cache.
		 *
		 * @var int
		 * @since 2.0.0
		 */
		private const FILE_EXISTS_CACHE_LIMIT = 500;

		/**
		 * Per-request record of emitted preload links (normalized URL + query + media).
		 *
		 * `get_all_preload_data()` dedups within one call, but `wp_head` may
		 * invoke `preload_images()` more than once per request; this guard
		 * keeps the single `<link rel="preload" as="image"
		 * fetchpriority="high">` per LCP URL invariant (issue #991) across
		 * repeated calls. Reset with {@see clear_runtime_caches()} (e.g. on
		 * switch_blog) and in tests. Long-lived processes (CLI/cron) that
		 * generate multiple pages in one process must call
		 * {@see clear_runtime_caches()} between pages, otherwise a hero URL
		 * repeated on a later page is skipped as already emitted.
		 *
		 * @var array<string,bool>
		 * @since 2.0.0
		 */
		private static array $preload_emitted = array();

		/**
		 * Per-request raw URLs emitted directly via `generate_img_preload()`.
		 *
		 * The direct path bypasses `get_all_preload_data()`, so its hero is
		 * recorded here (bounded, unique) so `add_delay_load_img()` can
		 * exempt it from lazy-load in the same response.
		 * `preload_images()` URLs are intentionally NOT recorded here — they
		 * already flow through `get_all_preload_data()` into the lazy
		 * exclusion list. Reset with {@see clear_runtime_caches()}.
		 * In-memory only, multisite-safe by construction.
		 *
		 * @var array<string,bool>
		 * @since NEXT
		 */
		private static array $preload_emitted_urls = array();

		/**
		 * In-request LRU map for getimagesize results (see
		 * {@see get_cached_image_size()}).
		 *
		 * Keyed by absolute path. Promoted from a method-level static so
		 * {@see clear_runtime_caches()} can reset it on switch_blog and after
		 * cache clears (audit #888 finding 7). Bounded by IMG_SIZE_CACHE_LIMIT.
		 *
		 * @var array<string,array|false>
		 * @since 2.0.0
		 */
		private static array $img_size_cache = array();

		/**
		 * Per-request random namespace for noscript placeholder tokens.
		 *
		 * In-memory only (never persisted to `wppo_settings`), so it is
		 * multisite-safe by construction: each request/instance mints its own
		 * namespace and only tokens carrying it can be restored.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private string $noscript_namespace = '';

		/**
		 * Memoized LCP-candidate URL excluded from lazy load for this instance.
		 *
		 * `preload_images()` and `add_delay_load_img()` each resolve RUM /
		 * PageSpeed state once per page; this memo (see
		 * {@see get_lazy_lcp_exclusion_url()}) keeps repeated lazy rewrites on
		 * the same instance from re-scanning the RUM aggregate and transients.
		 * Keyed by the normalized current URL (see
		 * `$lazy_lcp_exclusion_url_key`) so a long-lived instance reused
		 * across pages re-resolves per page (issue #1216). Per-instance (not
		 * static): instances are constructed per request with one site's
		 * options, so a memoized URL can never leak across sites or option
		 * sets. Long-lived processes that reuse one instance across pages
		 * should construct a fresh instance per page instead.
		 *
		 * @var string|null Null until resolved, then the candidate URL or ''.
		 * @since 2.0.0
		 */
		private ?string $lazy_lcp_exclusion_url = null;

		/**
		 * Memoized current-page LCP URL for this instance.
		 *
		 * `get_current_lcp_url()` is called up to 7x per frontend render and
		 * each call re-runs the OD lookup plus the RUM / PageSpeed chain
		 * (post meta + options + transients). This memo keeps repeated calls
		 * on the same instance from repeating those lookups. Keyed by the
		 * normalized current URL (see `$current_lcp_url_key`) so a long-lived
		 * instance reused across pages (CLI/cron batch renders) re-resolves
		 * instead of serving the first page's hero everywhere; `switch_blog`
		 * resets via `clear_instance_lcp_memo()` (wired in Main). Fresh
		 * instances per page remain the recommended pattern for batch
		 * renderers. Per-instance (not static): instances are constructed per
		 * request with one site's options, so a memoized URL can never leak
		 * across sites or option sets.
		 *
		 * @var string|null Null until resolved, then the LCP URL or ''.
		 * @since 2.0.0
		 */
		private ?string $current_lcp_url = null;

		/**
		 * Current-URL key the `$current_lcp_url` memo was resolved for.
		 *
		 * Compared on every `get_current_lcp_url()` call: a mismatch drops
		 * the memo and re-resolves (issue #1216). Null until first resolved.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $current_lcp_url_key = null;

		/**
		 * Current-URL key the null-buffer `$lazy_lcp_exclusion_url` memo was resolved for.
		 *
		 * Buffered calls (`$buffer !== null`) bypass the memo and resolve
		 * fresh so the P2 heuristic tier stays in parity with
		 * `maybe_preload_hero_image()`. Null until first null-buffer resolved.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $lazy_lcp_exclusion_url_key = null;

		/**
		 * Memoized LCP candidate for the render-time fetchpriority filter.
		 *
		 * `wppo_add_fetchpriority()` fires per attachment image, so the
		 * manual-picker + Optimization Detective chain
		 * (`resolve_od_only_lcp_url()`) would otherwise re-run N times per
		 * page. This memo resolves it at most once per page: keyed by
		 * `get_lcp_memo_key()` (mirroring `$current_lcp_url`) so a
		 * long-lived instance reused across pages re-resolves instead of
		 * serving a stale hero. Null until first resolved, then the LCP
		 * URL or ''.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $fetchpriority_lcp_url = null;

		/**
		 * Current-URL key the `$fetchpriority_lcp_url` memo was resolved for.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $fetchpriority_lcp_key = null;

		/**
		 * Memoized manual per-post LCP picker URL for this instance.
		 *
		 * `get_manual_lcp_url()` is called on every LCP path (OD-only,
		 * preload data, lazy exclusion); without a memo the same
		 * `_wppo_lcp_preload_url` meta is re-read 3-4x per page. Keyed by
		 * post ID (0 when not singular) so a long-lived instance reused
		 * across pages re-resolves per page. Null until resolved, then
		 * the URL or ''. Reset via `clear_instance_lcp_memo()`.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $manual_lcp_url = null;

		/**
		 * Post-ID key the `$manual_lcp_url` memo was resolved for.
		 *
		 * @var int|null
		 * @since NEXT
		 */
		private ?int $manual_lcp_url_key = null;

		/**
		 * Memoized per-post auto-LCP disable verdict for this instance.
		 *
		 * `is_auto_lcp_disabled_for_post()` runs `is_singular()` +
		 * `get_the_ID()` + `get_post_meta()` on every LCP path (signal
		 * resolve, auto preload data, lazy exclusion); this memo reads
		 * the `_wppo_disable_auto_lcp` meta at most once per post.
		 * Keyed by post ID (0 when not singular). Null until resolved,
		 * then the verdict. Reset via `clear_instance_lcp_memo()`.
		 *
		 * @var bool|null
		 * @since NEXT
		 */
		private ?bool $auto_lcp_disabled = null;

		/**
		 * Post-ID key the `$auto_lcp_disabled` memo was resolved for.
		 *
		 * @var int|null
		 * @since NEXT
		 */
		private ?int $auto_lcp_disabled_key = null;

		/**
		 * Memoized stable signal-only LCP URL for this instance.
		 *
		 * `get_stable_signal_lcp_url()` fans out to the RUM field lookup
		 * and the OD stability gate; without a memo one page resolves it
		 * on every LCP path (preload, generate, lazy exclusion, hero).
		 * Keyed by `get_lcp_memo_key()` so a long-lived instance reused
		 * across pages re-resolves per page. Null until resolved, then
		 * the URL or ''. Reset via `clear_instance_lcp_memo()`.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $stable_signal_lcp_url = null;

		/**
		 * Current-URL key the `$stable_signal_lcp_url` memo was resolved for.
		 *
		 * @var string|null
		 * @since NEXT
		 */
		private ?string $stable_signal_lcp_url_key = null;

		/**
		 * Per-request heuristic LCP memo keyed by buffer hash (issue #1216).
		 *
		 * The P2 DOM-first heuristic re-scans full HTML with
		 * `WP_HTML_Tag_Processor` on each caller (preload data, lazy
		 * exclusion, hero inject); this static memo resolves each distinct
		 * buffer once per request. Bounded: reset once past 30 entries.
		 *
		 * @var array<string, string>
		 * @since NEXT
		 */
		private static $heuristic_lcp_memo = array();

		/**
		 * Deferred alt-map entries buffered for the shutdown commit,
		 * keyed by blog id so mid-request switch_to_blog() cannot leak
		 * one site's titles into another site's map (audit #1338 review).
		 * Each blog bucket is capped at 200 entries (drop-oldest).
		 *
		 * @since NEXT
		 * @var array<int, array<string, string>>
		 */
		private static $deferred_alt_entries = array();

		/**
		 * Per-request memo of the persistent alt map, keyed by blog id
		 * (see get_derived_alt_map).
		 *
		 * @since NEXT
		 * @var array<int, array<string, string>|null>
		 */
		private static $derived_alt_memo = array();

		/**
		 * Whether the shutdown commit is registered for the current
		 * commit cycle. Re-armed whenever the buffer drains so long-lived
		 * processes that buffer after a commit still persist.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static $alt_commit_registered = false;

		/**
		 * Cached placeholder info (dominant color + LQIP) from Img_Converter.
		 *
		 * Class property (not a function-static) so clear_runtime_caches()
		 * can flush it on switch_blog / cache-clear and in tests. Null until
		 * first placeholder lookup per request.
		 *
		 * @var array|null
		 * @since NEXT
		 */
		private static $placeholder_info_cache = null;

		/**
		 * Data-src URL => ABSPATH-relative path cache for placeholder lookups.
		 *
		 * @var array<string, string>
		 * @since NEXT
		 */
		private static $placeholder_path_cache = array();

		/**
		 * Clear the per-request runtime caches (file_exists + image sizes).
		 *
		 * Called on switch_blog (absolute paths from another site must not be
		 * reused) and after cache-clear operations so stale next-gen paths are
		 * re-verified. Bounded by FILE_EXISTS_CACHE_LIMIT / IMG_SIZE_CACHE_LIMIT
		 * eviction per request, so this is a correctness flush, not the growth
		 * bound.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function clear_runtime_caches(): void {
			self::$file_exists_cache      = array();
			self::$img_size_cache         = array();
			self::$preload_emitted        = array();
			self::$preload_emitted_urls   = array();
			self::$placeholder_info_cache = null;
			self::$placeholder_path_cache = array();
			self::$heuristic_lcp_memo     = array();
			// Commit-then-clear (audit #1338 review): long-lived processes
			// that clear between pages must not silently drop buffered alts.
			// Memo resets to array() (never null): get_derived_alt_map()
			// passes it to array_key_exists(), which TypeErrors on null.
			self::commit_derived_alt_map();
			self::$derived_alt_memo      = array();
			self::$deferred_alt_entries  = array();
			self::$alt_commit_registered = false;
			if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) && method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'clear_request_memo' ) ) {
				try {
					\PerformanceOptimise\Inc\OD_Bridge::clear_request_memo();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		/**
		 * Whether a preload hint was already emitted for a URL this request.
		 *
		 * Shared cross-emitter dedup (issue #1255): the Critical-CSS
		 * field-LCP preload (wp_head:0) and this pipeline's preload_images()
		 * (wp_head:1) resolve the same RUM-field → PageSpeed candidate, so
		 * both consult this one per-request set. Keys are built by
		 * {@see build_preload_dedup_key()} — identical to the keys
		 * preload_images() checks — so a URL emitted by either path is
		 * skipped by the other and exactly one hint prints per resource.
		 * Fail-open: any failure returns false (caller emits normally).
		 *
		 * @since NEXT
		 * @param string $url   The raw preload URL.
		 * @param string $media The preload media attribute.
		 * @return bool True when the URL + media pair already emitted.
		 */
		public static function has_emitted_preload( string $url, string $media = '' ): bool {
			try {
				return isset( self::$preload_emitted[ self::build_preload_dedup_key( $url, $media ) ] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a preload hint as emitted for this request.
		 *
		 * Companion to {@see has_emitted_preload()}: lets an external
		 * emitter (the Critical-CSS field-LCP path) claim a URL so this
		 * pipeline's preload_images() skips the duplicate. Reset with
		 * {@see clear_runtime_caches()}. Never fatal.
		 *
		 * @since NEXT
		 * @param string $url   The raw preload URL.
		 * @param string $media The preload media attribute.
		 * @return void
		 */
		public static function mark_preload_emitted( string $url, string $media = '' ): void {
			try {
				self::$preload_emitted[ self::build_preload_dedup_key( $url, $media ) ] = true;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Record a directly-emitted hero URL for same-response lazy exclusion.
		 *
		 * Only `generate_img_preload()` feeds this set: `preload_images()`
		 * URLs already flow through `get_all_preload_data()` into the lazy
		 * exclusion list, so recording them here too would only duplicate
		 * state. Bounded (30 entries), reset with
		 * {@see clear_runtime_caches()}. Never fatal.
		 *
		 * @since NEXT
		 * @param string $url The raw preload URL.
		 * @return void
		 */
		private static function record_direct_preload_url( string $url ): void {
			try {
				$raw = trim( $url );
				if ( '' === $raw ) {
					return;
				}
				self::$preload_emitted_urls[ substr( $raw, 0, 2048 ) ] = true;
				if ( count( self::$preload_emitted_urls ) > 30 ) {
					self::$preload_emitted_urls = array_slice( self::$preload_emitted_urls, -30, null, true );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Normalized forms of the directly-emitted preload URLs.
		 *
		 * `record_direct_preload_url()` stores raw strings (relative or
		 * absolute, possibly size-suffixed), while lazy matching needs
		 * normalized equality (issue #1273): an explicit
		 * `generate_img_preload()` hero must stay eager even when the
		 * markup carries an alias (`/hero.jpg` vs
		 * `https://example.com/hero-300x200.jpg`). Returns the unique
		 * non-empty `Util::normalize_url()` forms. Fail-open to an empty
		 * list. In-memory only.
		 *
		 * @since NEXT
		 * @return string[] Normalized direct-preload URLs.
		 */
		private static function get_direct_preload_normalized_urls(): array {
			$normalized = array();
			try {
				if ( array() === self::$preload_emitted_urls ) {
					return array();
				}
				foreach ( array_keys( self::$preload_emitted_urls ) as $url ) {
					try {
						$norm = Util::normalize_url( (string) $url );
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
					if ( '' !== $norm ) {
						$normalized[] = $norm;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			return array_values( array_unique( $normalized ) );
		}

		/**
		 * Build the dedup key for a preload item (normalized URL + query + media).
		 *
		 * Static twin of the instance get_preload_dedup_key() below so both
		 * the instance pipeline and the public has/mark helpers share one
		 * key space. See that method for the query/media rationale.
		 *
		 * @since NEXT
		 *
		 * @param string $url   The raw preload URL.
		 * @param string $media The preload media attribute.
		 * @return string The dedup key.
		 */
		private static function build_preload_dedup_key( string $url, string $media ): string {
			$normalized = '';
			try {
				$normalized = self::normalize_image_url_static( $url );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$base  = ( '' !== $normalized ) ? $normalized : $url;
			$query = '';
			try {
				if ( function_exists( 'wp_parse_url' ) ) {
					$parsed = wp_parse_url( $url, PHP_URL_QUERY );
					if ( is_string( $parsed ) && '' !== $parsed ) {
						$query = '?' . substr( $parsed, 0, 512 );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $base . $query . '|' . $media;
		}

		/**
		 * Whether any preload was already claimed for a hero URL, any media.
		 *
		 * Cross-path single-preload guard (issue #1312): `preload_images()`
		 * records manual mobile:/desktop: variants with non-empty media strings
		 * while the buffer companions emit with an empty media string, so an
		 * exact media-key lookup misses cross-path duplicates. This scans the
		 * per-request emitted set for the same normalized base + query with any
		 * media suffix, so the hero keeps exactly one preload tag no matter
		 * which emitter ran first. Manual lists stay authoritative because they
		 * run first in `get_all_preload_data()` and claim the slot first.
		 * Fail-open: any failure returns false (caller emits normally).
		 *
		 * @since NEXT
		 * @param string $url The raw hero URL.
		 * @return bool True when the URL already emitted with any media.
		 */
		private static function is_hero_preload_claimed( string $url ): bool {
			try {
				$prefix = self::build_preload_dedup_key( $url, '' );
				if ( '' === $prefix ) {
					return false;
				}
				foreach ( array_keys( self::$preload_emitted ) as $key ) {
					if ( ! is_string( $key ) ) {
						continue;
					}
					if ( 0 === strpos( $key, $prefix ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return false;
		}

		/**
		 * Claim the single hero preload slot for a URL.
		 *
		 * Centralised emission guard (issue #1312) consulted by the buffer
		 * companions (`maybe_preload_hero_image()`,
		 * `maybe_inject_css_hero_preload()`): returns false when the hero was
		 * already emitted this request (any media variant) or already present
		 * in the buffer, otherwise records the claim and returns true so the
		 * caller may emit exactly one `<link rel="preload" as="image">` with
		 * eager plus fetchpriority high. `preload_images()` (wp_head manual
		 * lists) records exact media-key emissions via has/mark and stays
		 * authoritative by run order (wp_head runs before the buffer flush),
		 * so the any-media scan guarantee covers the buffer companions while
		 * manual lists win by order. Fail-open: any failure returns true
		 * (caller emits normally, never a white screen).
		 *
		 * @since NEXT
		 * @param string      $url    The raw hero URL.
		 * @param string      $media  The preload media attribute ('' for buffer companions).
		 * @param string|null $buffer Optional HTML buffer to scan for an existing hint.
		 * @return bool True when the caller may emit (slot claimed), false to skip.
		 */
		private function claim_hero_preload_slot( string $url, string $media = '', ?string $buffer = null ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				if ( self::has_emitted_preload( $url, $media ) || self::is_hero_preload_claimed( $url ) ) {
					return false;
				}
				if ( is_string( $buffer ) && '' !== $buffer && $this->buffer_has_image_preload( $buffer, $url ) ) {
					return false;
				}
				self::mark_preload_emitted( $url, $media );
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Release a previously claimed hero preload slot.
		 *
		 * Companion to `claim_hero_preload_slot()` (issue #1312 review): when
		 * link-tag generation fails after the claim (empty tag), the claim is
		 * released so the sibling emitter may still emit exactly one preload
		 * instead of being suppressed into zero. Fail-open: never fatal.
		 *
		 * @since NEXT
		 * @param string $url   The raw hero URL.
		 * @param string $media The preload media attribute ('' for buffer companions).
		 * @return void
		 */
		private static function release_hero_preload_slot( string $url, string $media = '' ): void {
			try {
				unset( self::$preload_emitted[ self::build_preload_dedup_key( $url, $media ) ] );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Whether a preload candidate lives on the configured CDN.
		 *
		 * Origin allowlist companion to `is_same_origin_preload_url()` (issue
		 * #1312): background heroes are often served from the CDN host while the
		 * page is same-origin, so a proven CDN host also qualifies. Hosts are
		 * derived from `CDN::get_mappings()` (guarded) and compared
		 * case-insensitively against the candidate host via `wp_parse_url()`
		 * (guarded with `function_exists()`). Fail-closed: any failure returns
		 * false (candidate skipped, page fail-open).
		 *
		 * @since NEXT
		 * @param string $url The candidate URL.
		 * @return bool True when the URL host matches a configured CDN host.
		 */
		private function is_cdn_preload_url( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url || ! function_exists( 'wp_parse_url' ) ) {
					return false;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\CDN' ) || ! method_exists( 'PerformanceOptimise\Inc\CDN', 'get_mappings' ) ) {
					return false;
				}
				$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
				if ( '' === $host ) {
					return false;
				}
				$mappings = \PerformanceOptimise\Inc\CDN::get_mappings();
				if ( ! is_array( $mappings ) || array() === $mappings ) {
					return false;
				}
				foreach ( $mappings as $mapping ) {
					if ( ! is_array( $mapping ) ) {
						continue;
					}
					$cdn_urls = array();
					if ( isset( $mapping['cdn_urls'] ) && is_array( $mapping['cdn_urls'] ) ) {
						$cdn_urls = $mapping['cdn_urls'];
					} elseif ( isset( $mapping['cdn_url'] ) && is_string( $mapping['cdn_url'] ) && '' !== $mapping['cdn_url'] ) {
						$cdn_urls = array( $mapping['cdn_url'] );
					}
					foreach ( $cdn_urls as $cdn_url ) {
						if ( ! is_string( $cdn_url ) || '' === $cdn_url ) {
							continue;
						}
						$cdn_host = strtolower( (string) wp_parse_url( $cdn_url, PHP_URL_HOST ) );
						if ( '' !== $cdn_host && $cdn_host === $host ) {
							return true;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return false;
		}

		/**
		 * Whether a hero URL may be preloaded (same-origin or configured CDN).
		 *
		 * Emission-path origin guard (issue #1312): absolute heroes qualify when
		 * positively same-origin (`is_same_origin_preload_url()`) or when served
		 * from a configured CDN host (`is_cdn_preload_url()`); relative URLs are
		 * covered by the same-origin check. Fail-closed per URL, fail-open per
		 * page (caller skips the hint, markup otherwise untouched).
		 *
		 * @since NEXT
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded.
		 */
		private function is_allowed_hero_preload_url( string $url ): bool {
			try {
				if ( $this->is_same_origin_preload_url( $url ) ) {
					return true;
				}
				return $this->is_cdn_preload_url( $url );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the WP HTML API may be used for hero scanning.
		 *
		 * Version-gated companion to the `class_exists()` checks (issue #1312):
		 * the Tag Processor era starts at WordPress 6.2. When the version string
		 * is unreachable (unit contexts) presence of the class alone implies
		 * availability. Fail-open to false is never fatal; callers fall back to
		 * regex scanning.
		 *
		 * @since NEXT
		 * @return bool True when the HTML API may be used.
		 */
		private function is_html_api_available(): bool {
			try {
				if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
					return false;
				}
				if ( ! function_exists( 'get_bloginfo' ) && ! isset( $GLOBALS['wp_version'] ) ) {
					return true;
				}
				$wp_version = '';
				if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
					$wp_version = $GLOBALS['wp_version'];
				} elseif ( function_exists( 'get_bloginfo' ) ) {
					$wp_version = (string) get_bloginfo( 'version' );
				}
				if ( '' === $wp_version ) {
					return true;
				}
				return version_compare( $wp_version, '6.2', '>=' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Server-side computed CSS-hero URL passed via filter.
		 *
		 * Stylesheet-computed context hook (issue #1312): themes/builds that
		 * compute the hero background server side (e.g. from enqueued
		 * stylesheets where no inline `style=""` exists) may pass it via the
		 * `wppo_computed_css_hero_url` filter. The value is validated as an
		 * image on an allowed origin (same-origin or configured CDN) and
		 * capped at 2048 chars; anything else returns ''. Guarded with
		 * `function_exists()`/`has_filter()` so behaviour is unchanged when no
		 * callback is registered. Fail-open to ''.
		 *
		 * @since NEXT
		 * @param string|null $buffer Optional HTML buffer passed to the filter for context.
		 * @return string The computed hero URL, or empty string.
		 */
		private function get_computed_css_hero_url( ?string $buffer = null ): string {
			try {
				if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) ) {
					return '';
				}
				if ( ! has_filter( 'wppo_computed_css_hero_url' ) ) {
					return '';
				}
				$raw = apply_filters( 'wppo_computed_css_hero_url', '', $buffer );
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					return '';
				}
				$url = trim( substr( trim( $raw ), 0, 2048 ) );
				if ( ! $this->is_image_lcp_url( $url ) || ! $this->is_allowed_hero_preload_url( $url ) ) {
					return '';
				}
				return $url;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Force eager on any element already marked fetchpriority high.
		 *
		 * Final lazy/high invariant sweep (issue #1312): `loading="lazy"` plus
		 * `fetchpriority="high"` is an invalid priority signal that delays LCP.
		 * Any `<img>` or `<iframe>` carrying `fetchpriority="high"` is forced
		 * to `loading="eager"` (the high hint wins) so the pair is never
		 * emitted together, on mobile or desktop, without touching layout
		 * (no CLS: only the loading/fetchpriority attributes change).
		 * Fail-open: any failure returns the buffer unchanged.
		 *
		 * @since NEXT
		 * @param string $buffer The HTML buffer.
		 * @return string The buffer with high-priority nodes forced eager.
		 */
		private function sweep_lazy_high_conflicts( string $buffer ): string {
			try {
				if ( '' === $buffer || false === stripos( $buffer, 'fetchpriority' ) ) {
					return $buffer;
				}
				if ( ! $this->is_html_api_available() ) {
					$result = preg_replace_callback(
						'#<(?:img|iframe)\b[^>]*>#i',
						function ( $matches ) {
							$tag = $matches[0];
							if ( false === stripos( $tag, 'fetchpriority' ) ) {
								return $tag;
							}
							if ( 1 !== preg_match( '#fetchpriority\s*=\s*["\']?high["\']?#i', $tag ) ) {
								return $tag;
							}
							if ( 1 !== preg_match( '#loading\s*=\s*["\']?lazy["\']?#i', $tag ) ) {
								return $tag;
							}
							$fixed = (string) preg_replace( '#loading\s*=\s*["\']?lazy["\']?#i', 'loading="eager"', $tag, 1 );
							return $fixed;
						},
						$buffer
					);
					return is_string( $result ) ? $result : $buffer;
				}
				$tags    = new \WP_HTML_Tag_Processor( $buffer );
				$changed = false;
				while ( $tags->next_tag() ) {
					$tag_name = $tags->get_tag();
					if ( ! is_string( $tag_name ) ) {
						continue;
					}
					$tag_name = strtoupper( $tag_name );
					if ( 'IMG' !== $tag_name && 'IFRAME' !== $tag_name ) {
						continue;
					}
					$priority = $tags->get_attribute( 'fetchpriority' );
					if ( ! is_string( $priority ) || 'high' !== strtolower( trim( $priority ) ) ) {
						continue;
					}
					$loading = $tags->get_attribute( 'loading' );
					if ( ! is_string( $loading ) || 'lazy' !== strtolower( trim( $loading ) ) ) {
						continue;
					}
					$tags->set_attribute( 'loading', 'eager' );
					$changed = true;
				}
				if ( ! $changed ) {
					return $buffer;
				}
				$updated = $tags->get_updated_html();
				return is_string( $updated ) ? $updated : $buffer;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $buffer;
			}
		}

		/**
		 * Reset the per-instance LCP memos ($current_lcp_url, $lazy_lcp_exclusion_url).
		 *
		 * The static {@see clear_runtime_caches()} cannot reach instance state,
		 * so long-lived processes (switch_to_blog, CLI/cron rendering N pages
		 * with one shared instance) must call this between sites/pages (issue
		 * #1216); otherwise the second site reuses the first site's memoized
		 * LCP URL. Wired to `switch_blog` via Main alongside
		 * {@see clear_runtime_caches()}. The URL keys are reset too so the
		 * next call re-resolves instead of trusting a same-URL memo from
		 * another site. (The memos are additionally keyed by current URL, so
		 * cross-page reuse within one process self-corrects even without an
		 * explicit reset; the reset remains the guaranteed path.)
		 *
		 * @since NEXT
		 * @return void
		 */
		public function clear_instance_lcp_memo(): void {
			$this->current_lcp_url            = null;
			$this->current_lcp_url_key        = null;
			$this->lazy_lcp_exclusion_url     = null;
			$this->lazy_lcp_exclusion_url_key = null;
			$this->fetchpriority_lcp_url      = null;
			$this->fetchpriority_lcp_key      = null;
			$this->manual_lcp_url             = null;
			$this->manual_lcp_url_key         = null;
			$this->auto_lcp_disabled          = null;
			$this->auto_lcp_disabled_key      = null;
			$this->stable_signal_lcp_url      = null;
			$this->stable_signal_lcp_url_key  = null;
			self::$heuristic_lcp_memo         = array();
		}

		/**
		 * Constructor.
		 *
		 * @since 1.0.0
		 *
		 * @param array $options Configuration options for image optimization.
		 */
		public function __construct( $options ) {
			$this->options = $options;

			// Backward compat: migrate replacePlaceholderWithSVG to placeholderType.
			if ( ! isset( $this->options['image_optimisation']['placeholderType'] ) ) {
				if ( isset( $this->options['image_optimisation']['replacePlaceholderWithSVG'] ) ) {
					$this->options['image_optimisation']['placeholderType'] = (bool) $this->options['image_optimisation']['replacePlaceholderWithSVG'] ? 'svg' : 'none';
				} else {
					$this->options['image_optimisation']['placeholderType'] = 'none';
				}
			}

			$this->exclude_convert_imgs    = Util::process_urls( $this->options['image_optimisation']['excludeConvertImages'] ?? array() );
			$this->preload_front_page_urls = Util::process_urls( $this->options['image_optimisation']['preloadFrontPageImagesUrls'] ?? array() );
			$this->exclude_post_type_imgs  = Util::process_urls( $this->options['image_optimisation']['excludePostTypeImgUrl'] ?? array() );
			$this->exclude_sizes           = array_map( 'absint', array_map( 'trim', explode( ',', ( $this->options['image_optimisation']['excludeSize'] ?? '' ) ) ) );
			$this->exclude_lazy_imgs       = Util::process_urls( $this->options['image_optimisation']['excludeImages'] ?? array() );
			$this->exclude_lazy_videos     = Util::process_urls( $this->options['image_optimisation']['excludeVideos'] ?? array() );

			$this->setup_hooks();
		}

		/**
		 * Sets up hooks for image optimization features.
		 *
		 * @since 1.0.0
		 */
		private function setup_hooks() {
			if ( ! empty( $this->options['image_optimisation']['convertImg'] ) ) {
				$img_converter = $this->get_img_converter();

				// Skip the conversion hook when core handles all formats (get_format() returns 'none').
				$should_hook_conversion = 'none' !== $img_converter->get_format();

				// Still register the metadata filter so placeholder data (dominant
				// color/LQIP) is extracted for new uploads whenever server-side
				// conversion is skipped by design:
				// - WP 7.1+ client-side media processing (get_format() -> 'none'), or
				// - WP 6.7+ core-native next-gen generation (get_format() -> 'none').
				$core_handles_next_gen = $img_converter::core_handles_next_gen();

				if ( $should_hook_conversion || $core_handles_next_gen ) {
					add_filter( 'wp_generate_attachment_metadata', array( $img_converter, 'convert_image_to_next_gen_format' ), 10, 2 );
				}
				add_filter( 'wp_get_attachment_image_src', array( $img_converter, 'maybe_serve_next_gen_image' ) );
			}

			// Clean up placeholder data when images are deleted.
			add_action( 'delete_attachment', array( 'PerformanceOptimise\Inc\Img_Converter', 'clean_placeholder_on_delete' ) );

			// Render-time LCP fetchpriority (issue #1234): stamp
			// fetchpriority=high + loading=eager on the resolved LCP
			// attachment where core builds the <img> tag, so the hint
			// composes with core 6.3+ loading optimization instead of
			// relying solely on output-buffer post-processing.
			// Registered unconditionally (core fires this filter on all
			// supported versions); the callback itself is fail-open and
			// gates on the prioritizeLCPImages toggle, so old cores and
			// disabled features are unaffected.
			add_filter( 'wp_get_attachment_image_attributes', array( $this, 'wppo_add_fetchpriority' ), 10, 3 );

			// Allow the admin toggle to control which MIME types WP 7.1+ client-side
			// media processing handles in the browser (e.g. drop AVIF when the plugin
			// serves it, or add HEIC). Registered only on cores that support it and
			// only when the override is enabled, so older cores are unaffected.
			if ( function_exists( 'wp_is_client_side_media_processing_enabled' )
				&& ! empty( $this->options['image_optimisation']['clientSideMimeTypeOverride'] ) ) {
				add_filter( 'client_side_supported_mime_types', array( $this, 'filter_client_side_supported_mime_types' ) );
			}

			// Allow the "Force Server-Side Conversion" toggle to opt out of WP 7.1+
			// client-side media processing entirely. Core gates its own in-browser
			// conversion on wp_is_client_side_media_processing_enabled(), so forcing
			// this to false stops the browser worker AND lets the plugin's own
			// GD/Imagick pipeline handle conversion without duplicate work. Registered
			// only when the toggle is enabled and only on cores that support it.
			if ( function_exists( 'wp_is_client_side_media_processing_enabled' )
				&& ! empty( $this->options['image_optimisation']['forceServerSideConversion'] ) ) {
				add_filter( 'wp_client_side_media_processing_enabled', '__return_false' );
			}
		}

		/**
		 * Replace the MIME types handled by WP 7.1+ client-side media processing.
		 *
		 * This filter is only registered when the override toggle is enabled.
		 * The stored selection becomes the set of formats the in-browser Web
		 * Worker should process, intersected with the formats core reports it
		 * can support so an unsupported selection (e.g. HEIC/JXL on a build
		 * without a wasm-vips decoder) can never shadow core's authoritative
		 * list. A non-array stored value leaves core's default list untouched
		 * (graceful degradation); an enabled override with an empty selection
		 * returns an empty list, which disables browser-side processing
		 * entirely (core supports empty list). Future decoders (HEIC Sequence,
		 * JPEG XL) are additive via the same intersection — the UI surfaces
		 * them but core's list gates availability.
		 *
		 * Trac #64876 proposes a public `client_side_supported_mime_types`
		 * filter; until it lands the plugin keeps the intersection guard so an
		 * unavailable decoder (e.g. HEIC/JXL without wasm-vips) cannot be added
		 * additively. When the public filter lands, widen to additive
		 * HEIC/JPEG-XL pass-through with documented HEIC/JPEG-XL path.
		 *
		 * Guarded by `function_exists('wp_is_client_side_media_processing_enabled')`
		 * for <7.1 (filter not registered there). Wasm gating: ~13 MB lazy-loaded
		 * wasm-vips gated by Document-Isolation-Policy / SharedArrayBuffer.
		 *
		 * @since 2.0.0
		 *
		 * @param string[] $supported_mime_types The MIME types core supports client-side.
		 * @return string[] The filtered MIME types.
		 */
		public function filter_client_side_supported_mime_types( $supported_mime_types ) {
			$mime_types = $this->options['image_optimisation']['clientSideMimeTypes'] ?? array();

			if ( ! is_array( $mime_types ) ) {
				return $supported_mime_types;
			}

			$mime_types = array_map( 'sanitize_text_field', $mime_types );
			$mime_types = array_filter( $mime_types );
			$mime_types = array_values( array_unique( $mime_types ) );

			$mime_types = array_intersect( $mime_types, array_map( 'sanitize_text_field', (array) $supported_mime_types ) );

			return array_values( $mime_types );
		}

		/**
		 * Preloads images for optimization.
		 *
		 * Emits exactly one `<link rel="preload" as="image"
		 * fetchpriority="high">` per URL: `get_all_preload_data()` dedups by
		 * normalized URL + query + media within one call (so the single
		 * RUM-field → PageSpeed LCP candidate, manual meta, front-page and
		 * post-type items collapse to one tag, while `?v=` variants stay
		 * distinct), and the per-request `$preload_emitted` guard below skips
		 * repeats across repeated `wp_head` invocations.
		 *
		 * No-duplicate note (issue #991): core 6.9 stamps `fetchpriority` on
		 * the `<img>` node itself via `wp_get_loading_optimization_attributes()`
		 * — a separate concern from this early `<link>` hint. The stamp is
		 * never double-applied (see `prioritize_lcp_image()` and
		 * `set_loading_optimization_attributes()`, which only fill gaps via
		 * `function_exists()`-guarded core calls), so this hint and core's
		 * node stamp coexist without fighting.
		 *
		 * @since 1.0.0
		 */
		public function preload_images() {
			$preload_data = $this->get_all_preload_data();

			foreach ( $preload_data as $data ) {
				if ( ! is_array( $data ) || empty( $data['url'] ) || ! is_string( $data['url'] ) ) {
					continue;
				}
				$media = (string) ( $data['media'] ?? '' );
				if ( self::has_emitted_preload( $data['url'], $media ) ) {
					continue;
				}
				self::mark_preload_emitted( $data['url'], $media );
				Util::generate_preload_link(
					$data['url'],
					'preload',
					'image',
					false,
					Util::get_image_mime_type( $data['url'] ),
					$data['media'] ?? '',
					$data['priority'] ?? 'high',
					(string) ( $data['imagesrcset'] ?? '' ),
					(string) ( $data['imagesizes'] ?? '' )
				);
			}
		}

		/**
		 * Get (and lazily mint) the per-request noscript token namespace.
		 *
		 * Uses cryptographically random hex via `random_bytes()` when available,
		 * falling back to `wp_generate_password()` (sanitized to alphanumerics)
		 * and finally to a `uniqid()`/`wp_rand()` token. Never fatals: any
		 * failure degrades to a static fallback namespace (fail-open).
		 *
		 * @since 2.0.0
		 * @return string Non-empty namespace string.
		 */
		private function get_noscript_namespace(): string {
			if ( '' !== $this->noscript_namespace ) {
				return $this->noscript_namespace;
			}

			$this->noscript_namespace = Util::mint_placeholder_namespace();

			return $this->noscript_namespace;
		}

		/**
		 * Resolve a single noscript placeholder token against the allowlist.
		 *
		 * Strict restore discipline (CVE-2026-3220 shape): the token must be an
		 * exact key of `$noscript_tokens`, carry this request's namespace
		 * (constant-time comparison), and reference a bounds-checked index. Any
		 * anomaly returns null so the caller emits the node unmodified
		 * (fail-open).
		 *
		 * @since 2.0.0
		 * @param string $token           The matched placeholder comment.
		 * @param array  $noscript_tokens The exact-token allowlist (token => HTML).
		 * @return string|null Restored HTML, or null on anomaly.
		 */
		private function resolve_noscript_token( string $token, array $noscript_tokens ): ?string {
			if ( ! isset( $noscript_tokens[ $token ] ) || ! is_string( $noscript_tokens[ $token ] ) ) {
				return null;
			}

			if ( 1 !== preg_match( '/^<!--WPPO_NOSCRIPT_([A-Za-z0-9]+)_(\d+)-->$/', $token, $matches ) ) {
				return null;
			}

			$namespace          = $matches[1];
			$index_raw          = $matches[2];
			$namespace_expected = $this->noscript_namespace;

			if ( '' === $namespace_expected || '' === $namespace ) {
				return null;
			}

			if ( strlen( $namespace ) !== strlen( $namespace_expected ) ) {
				return null;
			}

			if ( function_exists( 'hash_equals' ) ) {
				if ( ! hash_equals( $namespace_expected, $namespace ) ) {
					return null;
				}
			} elseif ( $namespace !== $namespace_expected ) {
				return null;
			}

			if ( ! ctype_digit( $index_raw ) ) {
				return null;
			}

			$index = (int) $index_raw;
			if ( $index < 0 || $index >= count( $noscript_tokens ) ) {
				return null;
			}

			return $noscript_tokens[ $token ];
		}

		/**
		 * Restore stashed `<noscript>` blocks via strict allowlist lookup.
		 *
		 * Unknown, foreign-namespace, or out-of-range tokens pass through
		 * unmodified (fail-open) so attacker-controlled markup shaped like a
		 * token stays inert. PCRE failure degrades to the unmodified buffer.
		 *
		 * @since 2.0.0
		 * @param string $buffer          The HTML buffer containing tokens.
		 * @param array  $noscript_tokens The exact-token allowlist (token => HTML).
		 * @return string Buffer with known tokens restored.
		 */
		private function restore_noscript_tokens( string $buffer, array $noscript_tokens ): string {
			if ( array() === $noscript_tokens ) {
				return $buffer;
			}

			$restored = preg_replace_callback(
				'/<!--WPPO_NOSCRIPT_[A-Za-z0-9_-]+_\d+-->/',
				function ( $matches ) use ( $noscript_tokens ) {
					$resolved = $this->resolve_noscript_token( $matches[0], $noscript_tokens );
					if ( null === $resolved ) {
						return $matches[0];
					}
					// Defense-in-depth: sanitize on restore as well as on
					// stash so tokens stashed while hardening was off cannot
					// reintroduce hostile markup.
					return $this->sanitize_comment_images_in_buffer( $resolved );
				},
				$buffer
			);

			return null !== $restored ? $restored : $buffer;
		}

		/**
		 * Whether a lazy `data-src` value is safe to rewrite with a placeholder.
		 *
		 * Fail-open ownership gate: hostile placeholder-shaped input (empty,
		 * oversized, markup-bearing, or dangerous-scheme `data-src`) is not a
		 * locally generated lazy node and must be emitted unmodified without
		 * any placeholder rewrite.
		 *
		 * @since 2.0.0
		 * @param string $data_src The `data-src` URL of the image.
		 * @return bool True when the node may receive a placeholder `src`.
		 */
		private function is_valid_lazy_placeholder_candidate( string $data_src ): bool {
			$data_src = trim( $data_src );
			if ( '' === $data_src ) {
				return false;
			}
			if ( strlen( $data_src ) > 2048 ) {
				return false;
			}
			if ( str_contains( $data_src, '<' ) || str_contains( $data_src, '>' ) ) {
				return false;
			}
			$lower = strtolower( ltrim( $data_src ) );
			if (
				str_starts_with( $lower, 'javascript:' )
				|| str_starts_with( $lower, 'vbscript:' )
			) {
				return false;
			}
			// Only a small allowlist of raster image data URLs may receive a
			// placeholder rewrite. Every other data: payload (text/html,
			// image/svg+xml which can carry script, application/xhtml+xml,
			// …) is refused and emitted unmodified (fail-open).
			if ( str_starts_with( $lower, 'data:' ) ) {
				// Require a `;`/`,` delimiter after the subtype so a
				// prefix-only match like `data:image/pngevil` is rejected.
				return 1 === preg_match( '#^data:image/(?:png|jpe?g|gif|webp|avif)[;,]#i', $lower );
			}
			return true;
		}

		/**
		 * Post-processes the serialized buffer to inject placeholders into lazy-loaded images
		 * that have data-src but no src attribute. Called after the WP_HTML_Tag_Processor pass.
		 *
		 * Duplication note (D-14): `post_process_placeholders`, `post_process_img_dimensions`
		 * and `post_process_auto_sizes` intentionally scan the buffer in three separate
		 * `preg_replace_callback` passes. Each stage mutates a distinct attribute set
		 * (placeholder src, width/height, data-sizes=auto) via the shared
		 * `get_placeholder_src_for_image()` helper and a per-request bounded LRU
		 * (`IMG_SIZE_CACHE_LIMIT` / `FILE_EXISTS_CACHE_LIMIT`). Merging into a single
		 * pass would conflate concerns and break the dimensions→auto-sizes ordering
		 * dependency. The three-pass cost is linear and acceptable (see audit D-14).
		 *
		 * Anomaly gate: candidate nodes failing {@see is_valid_lazy_placeholder_candidate()}
		 * are emitted unmodified without any lazy/placeholder rewrite (fail-open).
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer                  The HTML buffer after WP_HTML_Tag_Processor serialization.
		 * @param bool   $enable_placeholder      Whether placeholders are enabled.
		 * @return string The modified buffer.
		 */
		private function post_process_placeholders( string $buffer, bool $enable_placeholder ): string {
			if ( ! $enable_placeholder ) {
				return $buffer;
			}

			if ( $this->should_use_html_processor() ) {
				$processed = $this->post_process_placeholders_with_processor( $buffer );
				if ( null !== $processed ) {
					return $processed;
				}
			}

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$processed = $this->post_process_placeholders_with_tag_processor( $buffer );
				if ( null !== $processed ) {
					return $processed;
				}
			}

			$result = preg_replace_callback(
				'#<img\b[^>]*\sdata-src=["\']([^"\']+)["\'][^>]*>#i',
				function ( $matches ) {
					$img_tag = $matches[0];
					if ( preg_match( '#\ssrc=#i', $img_tag ) ) {
						return $img_tag;
					}
					$data_src = $matches[1];
					// Fail-open: hostile placeholder-shaped input is emitted
					// unmodified without any lazy/placeholder rewrite.
					if ( ! $this->is_valid_lazy_placeholder_candidate( $data_src ) ) {
						return $img_tag;
					}
					$placeholder = $this->get_placeholder_src_for_image( $img_tag, $data_src );
					if ( ! empty( $placeholder['src'] ) ) {
						$extra_attrs = '';
						foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
							$extra_attrs .= ' ' . $this->normalize_data_attribute_name( $attr_name ) . '="' . esc_attr( $attr_value ) . '"';
						}
						$replaced = preg_replace( '#<img\b#i', '<img src="' . esc_attr( $placeholder['src'] ) . '"' . $extra_attrs, $img_tag, 1 );
						return null !== $replaced ? $replaced : $img_tag;
					}
					return $img_tag;
				},
				$buffer
			);
			return null !== $result ? $result : $buffer;
		}

		/**
		 * Processor-based placeholder injection using WP_HTML_Processor::serialize_token().
		 *
		 * Mirrors the regex fallback byte-for-byte but uses token streaming so
		 * nested <picture>, comments, SVG/mathML and malformed HTML are handled
		 * without PCRE fragility. Falls back to regex on parse errors or when
		 * WP_HTML_Processor is unavailable (WP <6.9 fallback).
		 *
		 * @since 2.0.0
		 * @param string $buffer The HTML buffer.
		 * @return string|null Processed buffer or null on failure (triggers regex fallback).
		 */
		private function post_process_placeholders_with_processor( string $buffer ): ?string {
			$processor = Util::create_html_processor( $buffer );
			if ( null === $processor ) {
				return null;
			}

			$out = '';
			while ( $processor->next_token() ) {
				$type = $processor->get_token_type();
				if ( '#tag' !== $type ) {
					$out .= $processor->serialize_token();
					continue;
				}

				$tag       = $processor->get_tag();
				$is_closer = $processor->is_tag_closer();

				if ( 'IMG' === $tag && ! $is_closer ) {
					$data_src = $processor->get_attribute( 'data-src' );
					$src      = $processor->get_attribute( 'src' );
					if ( null !== $data_src && null === $src ) {
						$tok_html = $processor->serialize_token();
						$decoded  = (string) $data_src;
						// Fail-open: hostile placeholder-shaped input is emitted
						// unmodified without any lazy/placeholder rewrite.
						if ( ! $this->is_valid_lazy_placeholder_candidate( $decoded ) ) {
							$out .= $tok_html;
							continue;
						}
						$placeholder = $this->get_placeholder_src_for_image( $tok_html, $decoded );
						if ( ! empty( $placeholder['src'] ) ) {
							$processor->set_attribute( 'src', $placeholder['src'] );
							foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
								$processor->set_attribute( $this->normalize_data_attribute_name( $attr_name ), $attr_value );
							}
							$serialized = $processor->serialize_token();
							// WP_HTML_Tag_Processor blocks data: URIs in src for security — manual inject when blocked.
							if ( null === $processor->get_attribute( 'src' ) ) {
								$extra = '';
								foreach ( $placeholder['attrs'] as $an => $av ) {
									$extra .= ' ' . $this->normalize_data_attribute_name( $an ) . '="' . esc_attr( $av ) . '"';
								}
								$manual = preg_replace( '#<img\b#i', '<img src="' . esc_attr( $placeholder['src'] ) . '"' . $extra, $tok_html, 1 );
								$out   .= null !== $manual ? $manual : $tok_html;
								continue;
							}
							$out .= $serialized;
							continue;
						}
					}
				}

				$out .= $processor->serialize_token();
			}

			if ( null !== $processor->get_last_error() ) {
				return null;
			}

			return $out;
		}

		/**
		 * Processor-based placeholder injection using WP_HTML_Tag_Processor (WP 6.2+).
		 *
		 * Middle tier between the WP 6.9+ `WP_HTML_Processor::serialize_token()`
		 * fast path and the legacy regex fallback: single-pass `next_tag()`
		 * traversal with `get_attribute()`/`set_attribute()` plus
		 * `get_updated_html()`, so the WP 6.2-6.8 happy path never runs
		 * `preg_replace` on `<img>` tags. Mirrors
		 * `post_process_placeholders_with_processor()` exactly (placeholder
		 * validity gate, extra data attrs). `data:` placeholder sources are
		 * staged through a sentinel URL plus `str_replace()` because the Tag
		 * Processor blocks `data:` URIs in `src`. Fail-open: returns null on
		 * any failure so the caller falls through to the regex fallback.
		 *
		 * @since NEXT
		 * @param string $buffer The HTML buffer.
		 * @return string|null Processed buffer or null on failure (triggers regex fallback).
		 */
		private function post_process_placeholders_with_tag_processor( string $buffer ): ?string {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return null;
			}
			try {
				$tags      = new \WP_HTML_Tag_Processor( $buffer );
				$sentinels = array();
				$seq       = 0;
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$data_src = $tags->get_attribute( 'data-src' );
					$src      = $tags->get_attribute( 'src' );
					if ( null === $data_src || null !== $src ) {
						continue;
					}
					$decoded = (string) $data_src;
					if ( ! $this->is_valid_lazy_placeholder_candidate( $decoded ) ) {
						continue;
					}
					$width  = $tags->get_attribute( 'width' );
					$height = $tags->get_attribute( 'height' );
					$proxy  = '<img';
					if ( is_string( $width ) || is_int( $width ) ) {
						$proxy .= ' width="' . (string) $width . '"';
					}
					if ( is_string( $height ) || is_int( $height ) ) {
						$proxy .= ' height="' . (string) $height . '"';
					}
					$proxy      .= '>';
					$placeholder = $this->get_placeholder_src_for_image( $proxy, $decoded );
					if ( empty( $placeholder['src'] ) ) {
						continue;
					}
					$target = (string) $placeholder['src'];
					if ( 0 === stripos( ltrim( $target ), 'data:' ) ) {
						$sentinel = 'https://wppo.invalid/__wppo_ph_' . $seq . '__';
						++$seq;
						$sentinels[ $sentinel ] = $target;
						$target                 = $sentinel;
					}
					$tags->set_attribute( 'src', $target );
					// Per-tag fail-open (mirrors the _with_processor() manual
					// inject + continue): when the encoder rejects this tag's
					// src (e.g. a blocked data: URI), skip only this tag so
					// prior tag updates are preserved. The src check runs
					// before extra attrs are staged so a skipped tag is left
					// fully untouched rather than partially stamped.
					if ( null === $tags->get_attribute( 'src' ) ) {
						continue;
					}
					foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
						$tags->set_attribute( $this->normalize_data_attribute_name( $attr_name ), $attr_value );
					}
				}
				$updated = $tags->get_updated_html();
				if ( ! is_string( $updated ) ) {
					return null;
				}
				if ( ! empty( $sentinels ) ) {
					foreach ( $sentinels as $sentinel => $actual ) {
						$safe = function_exists( 'esc_attr' ) ? esc_attr( $actual ) : htmlspecialchars( $actual, ENT_QUOTES, 'UTF-8' );
						// Replace only the quoted attribute-value form so a
						// sentinel-looking string in text nodes, comments or
						// scripts can never be rewritten.
						$updated = str_replace( '"' . $sentinel . '"', '"' . $safe . '"', $updated );
						$updated = str_replace( "'" . $sentinel . "'", "'" . $safe . "'", $updated );
					}
				}
				return $updated;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Post-processes the serialized buffer to add missing width/height attributes to lazy-loaded images.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer after WP_HTML_Tag_Processor serialization.
		 * @return string The modified buffer.
		 */
		private function post_process_img_dimensions( string $buffer ): string {
			if ( $this->should_use_html_processor() ) {
				$processed = $this->post_process_img_dimensions_with_processor( $buffer );
				if ( null !== $processed ) {
					return $processed;
				}
			}

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$processed = $this->post_process_img_dimensions_with_tag_processor( $buffer );
				if ( null !== $processed ) {
					return $processed;
				}
			}

			$result = preg_replace_callback(
				'#<img\b[^>]*\sdata-src=["\']([^"\']+)["\'][^>]*>#i',
				function ( $matches ) {
					$img_tag    = $matches[0];
					$data_src   = $matches[1];
					$has_width  = (bool) preg_match( '/\bwidth=["\']\d+["\']/i', $img_tag );
					$has_height = (bool) preg_match( '/\bheight=["\']\d+["\']/i', $img_tag );

					if ( ! $has_width || ! $has_height ) {
						$local_path = Util::get_local_path( $data_src );
						if ( ! empty( $local_path ) && $this->cached_file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
							$size = $this->get_cached_image_size( $local_path );
							if ( is_array( $size ) ) {
								if ( ! $has_width ) {
									$img_tag = preg_replace( '/<img\b/i', '<img width="' . (int) $size[0] . '"', $img_tag, 1 );
								}
								if ( ! $has_height ) {
									$img_tag = preg_replace( '/<img\b/i', '<img height="' . (int) $size[1] . '"', $img_tag, 1 );
								}
							}
						}
					}

					return $img_tag;
				},
				$buffer
			);
			return null !== $result ? $result : $buffer;
		}

		/**
		 * Processor-based dimension injection using serialize_token().
		 *
		 * @since 2.0.0
		 * @param string $buffer The HTML buffer.
		 * @return string|null Processed buffer or null on failure.
		 */
		private function post_process_img_dimensions_with_processor( string $buffer ): ?string {
			$processor = Util::create_html_processor( $buffer );
			if ( null === $processor ) {
				return null;
			}

			$out = '';
			while ( $processor->next_token() ) {
				$type = $processor->get_token_type();
				if ( '#tag' !== $type ) {
					$out .= $processor->serialize_token();
					continue;
				}

				$tag       = $processor->get_tag();
				$is_closer = $processor->is_tag_closer();

				if ( 'IMG' === $tag && ! $is_closer ) {
					$data_src = $processor->get_attribute( 'data-src' );
					if ( null !== $data_src ) {
						$has_width  = null !== $processor->get_attribute( 'width' );
						$has_height = null !== $processor->get_attribute( 'height' );
						if ( ! $has_width || ! $has_height ) {
							$local_path = Util::get_local_path( (string) $data_src );
							if ( ! empty( $local_path ) && $this->cached_file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
								$size = $this->get_cached_image_size( $local_path );
								if ( is_array( $size ) ) {
									if ( ! $has_width ) {
										$processor->set_attribute( 'width', (string) (int) $size[0] );
									}
									if ( ! $has_height ) {
										$processor->set_attribute( 'height', (string) (int) $size[1] );
									}
								}
							}
						}
					}
				}

				$out .= $processor->serialize_token();
			}

			if ( null !== $processor->get_last_error() ) {
				return null;
			}

			return $out;
		}

		/**
		 * Processor-based dimension injection using WP_HTML_Tag_Processor (WP 6.2+).
		 *
		 * Middle tier between the WP 6.9+ serializer fast path and the legacy
		 * regex fallback: single `next_tag()` pass over `<img>` with
		 * `get_attribute()`/`set_attribute()` plus `get_updated_html()`.
		 * Mirrors `post_process_img_dimensions_with_processor()` (cached file
		 * existence + LRU size lookup). Fail-open: returns null so the caller
		 * falls through to the regex fallback.
		 *
		 * @since NEXT
		 * @param string $buffer The HTML buffer.
		 * @return string|null Processed buffer or null on failure.
		 */
		private function post_process_img_dimensions_with_tag_processor( string $buffer ): ?string {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return null;
			}
			try {
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$data_src = $tags->get_attribute( 'data-src' );
					if ( null === $data_src ) {
						continue;
					}
					// Mirror the regex fallback's quoted-numeric gate: empty,
					// boolean or non-numeric values (e.g. width="auto") count
					// as missing so both tiers inject the looked-up size.
					$has_width  = is_numeric( $tags->get_attribute( 'width' ) );
					$has_height = is_numeric( $tags->get_attribute( 'height' ) );
					if ( $has_width && $has_height ) {
						continue;
					}
					$local_path = Util::get_local_path( (string) $data_src );
					if ( empty( $local_path ) || ! $this->cached_file_exists( $local_path ) || ! is_readable( $local_path ) || ! is_file( $local_path ) ) {
						continue;
					}
					$size = $this->get_cached_image_size( $local_path );
					if ( ! is_array( $size ) ) {
						continue;
					}
					if ( ! $has_width ) {
						$tags->set_attribute( 'width', (string) (int) $size[0] );
					}
					if ( ! $has_height ) {
						$tags->set_attribute( 'height', (string) (int) $size[1] );
					}
				}
				$updated = $tags->get_updated_html();
				return is_string( $updated ) ? $updated : null;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Post-processes lazy-loaded images and <picture> sources to enable auto-sizes (WP 6.7+).
		 *
		 * Runs after post_process_img_dimensions() so width/height are guaranteed to be
		 * present. For each lazy tag carrying a srcset the stored `data-sizes` value is
		 * upgraded so supporting browsers can derive the source size from the rendered layout:
		 *  - values that already include `auto` are left untouched,
		 *  - static values get `auto, ` prepended as a progressive enhancement,
		 *  - images without any `data-sizes` (but with srcset + width + height) get a bare `auto`.
		 *
		 * @since 1.8.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The modified buffer.
		 */
		private function post_process_auto_sizes( string $buffer ): string {
			// Note (see #624): when core's Enhanced Responsive Images delivers accurate
			// Gallery-block sizes and native <picture>/srcset handling, re-evaluate
			// this sizes="auto" prefilling for redundancy with core. sizes_attribute_includes_auto()
			// still delegates to wp_sizes_attribute_includes_valid_auto() when present. No runtime change.
			if ( ! Util::is_auto_sizes_available() ) {
				return $buffer;
			}

			if ( $this->should_use_html_processor() ) {
				$processed = $this->post_process_auto_sizes_with_processor( $buffer );
				if ( null !== $processed ) {
					return $processed;
				}
			}

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$processed = $this->post_process_auto_sizes_with_tag_processor( $buffer );
				if ( null !== $processed ) {
					return $processed;
				}
			}

			$result = preg_replace_callback(
				'#<(img|source)\b[^>]*\s(?:data-src|data-srcset)=["\'][^"\']+["\'][^>]*>#i',
				function ( $matches ) {
					$tag        = $matches[0];
					$is_img     = 'img' === strtolower( $matches[1] );
					$has_srcset = (bool) preg_match( '#\b(?:data-)?srcset=["\']#i', $tag );

					if ( ! $has_srcset ) {
						return $tag;
					}

					// Auto-sizes needs explicit dimensions on <img> to prevent CLS;
					// <picture> <source> elements carry no width/height attributes.
					if ( $is_img ) {
						$has_width  = (bool) preg_match( '/\bwidth=["\']\d+["\']/i', $tag );
						$has_height = (bool) preg_match( '/\bheight=["\']\d+["\']/i', $tag );
						if ( ! $has_width || ! $has_height ) {
							return $tag;
						}
					}

					if ( preg_match( '#\bdata-sizes=["\']([^"\']*)["\']#i', $tag, $sizes_matches ) ) {
						$current = $sizes_matches[1];
						if ( $this->sizes_attribute_includes_auto( $current ) ) {
							return $tag;
						}
						$data_sizes = 'auto, ' . $current;
						return preg_replace( '#\bdata-sizes=["\']([^"\']*)["\']#i', 'data-sizes="' . esc_attr( $data_sizes ) . '"', $tag, 1 );
					}

					if ( ! $is_img ) {
						return $tag;
					}

					return preg_replace( '/<img\b/i', '<img data-sizes="auto"', $tag, 1 );
				},
				$buffer
			);
			return null !== $result ? $result : $buffer;
		}

		/**
		 * Processor-based auto-sizes upgrade using serialize_token().
		 *
		 * @since 2.0.0
		 * @param string $buffer The HTML buffer.
		 * @return string|null Processed buffer or null on failure.
		 */
		private function post_process_auto_sizes_with_processor( string $buffer ): ?string {
			$processor = Util::create_html_processor( $buffer );
			if ( null === $processor ) {
				return null;
			}

			$out = '';
			while ( $processor->next_token() ) {
				$type = $processor->get_token_type();
				if ( '#tag' !== $type ) {
					$out .= $processor->serialize_token();
					continue;
				}

				$tag       = $processor->get_tag();
				$is_closer = $processor->is_tag_closer();

				if ( ( 'IMG' === $tag || 'SOURCE' === $tag ) && ! $is_closer ) {
					$has_lazy = null !== $processor->get_attribute( 'data-src' ) || null !== $processor->get_attribute( 'data-srcset' );
					if ( ! $has_lazy ) {
						$out .= $processor->serialize_token();
						continue;
					}

					$has_srcset = null !== $processor->get_attribute( 'srcset' ) || null !== $processor->get_attribute( 'data-srcset' );
					if ( ! $has_srcset ) {
						$out .= $processor->serialize_token();
						continue;
					}

					if ( 'IMG' === $tag ) {
						$has_width  = null !== $processor->get_attribute( 'width' );
						$has_height = null !== $processor->get_attribute( 'height' );
						if ( ! $has_width || ! $has_height ) {
							$out .= $processor->serialize_token();
							continue;
						}
					}

					$current = $processor->get_attribute( 'data-sizes' );
					if ( null !== $current ) {
						if ( $this->sizes_attribute_includes_auto( (string) $current ) ) {
							$out .= $processor->serialize_token();
							continue;
						}
						$processor->set_attribute( 'data-sizes', 'auto, ' . (string) $current );
						$out .= $processor->serialize_token();
						continue;
					}

					if ( 'SOURCE' === $tag ) {
						$out .= $processor->serialize_token();
						continue;
					}

					$processor->set_attribute( 'data-sizes', 'auto' );
				}

				$out .= $processor->serialize_token();
			}

			if ( null !== $processor->get_last_error() ) {
				return null;
			}

			return $out;
		}

		/**
		 * Processor-based auto-sizes upgrade using WP_HTML_Tag_Processor (WP 6.2+).
		 *
		 * Middle tier between the WP 6.9+ serializer fast path and the legacy
		 * regex fallback: one filtered `next_tag()` pass per tag name over
		 * `<img>` and `<source>` with `get_attribute()`/`set_attribute()`
		 * plus `get_updated_html()`.
		 * Mirrors `post_process_auto_sizes_with_processor()` (lazy gate,
		 * srcset presence, `<img>` width/height CLS gate, `data-sizes` auto
		 * handling). Fail-open: returns null so the caller falls through to
		 * the regex fallback.
		 *
		 * @since NEXT
		 * @param string $buffer The HTML buffer.
		 * @return string|null Processed buffer or null on failure.
		 */
		private function post_process_auto_sizes_with_tag_processor( string $buffer ): ?string {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return null;
			}
			try {
				// One filtered pass per tag name so the traversal visits only
				// <img>/<source> nodes instead of every tag in the document;
				// passes chain via the progressively updated buffer.
				foreach ( array( 'img', 'source' ) as $tag_name ) {
					$tags = new \WP_HTML_Tag_Processor( $buffer );
					while ( $tags->next_tag( array( 'tag_name' => $tag_name ) ) ) {
						$this->apply_auto_sizes_to_tag( $tags, 'img' === $tag_name );
					}
					$updated = $tags->get_updated_html();
					if ( ! is_string( $updated ) ) {
						return null;
					}
					$buffer = $updated;
				}
				return $buffer;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Apply the auto-sizes upgrade to the current Tag Processor tag.
		 *
		 * Shared per-tag step for the filtered `<img>`/`<source>` passes in
		 * `post_process_auto_sizes_with_tag_processor()`: lazy gate, srcset
		 * presence, `<img>` width/height CLS gate, then `data-sizes` auto
		 * handling. Mirrors `post_process_auto_sizes_with_processor()` and the
		 * regex fallback (which requires quoted-numeric dimensions, so empty,
		 * boolean or non-numeric values count as missing here too).
		 *
		 * @since NEXT
		 * @param \WP_HTML_Tag_Processor $tags   The tag processor on an `<img>` or `<source>` tag.
		 * @param bool                   $is_img Whether the current tag is an `<img>` (vs `<source>`).
		 * @return void
		 */
		private function apply_auto_sizes_to_tag( $tags, bool $is_img ): void {
			$has_lazy = null !== $tags->get_attribute( 'data-src' ) || null !== $tags->get_attribute( 'data-srcset' );
			if ( ! $has_lazy ) {
				return;
			}
			$has_srcset = null !== $tags->get_attribute( 'srcset' ) || null !== $tags->get_attribute( 'data-srcset' );
			if ( ! $has_srcset ) {
				return;
			}
			if ( $is_img ) {
				$has_width  = is_numeric( $tags->get_attribute( 'width' ) );
				$has_height = is_numeric( $tags->get_attribute( 'height' ) );
				if ( ! $has_width || ! $has_height ) {
					return;
				}
			}
			$current = $tags->get_attribute( 'data-sizes' );
			if ( null !== $current ) {
				if ( $this->sizes_attribute_includes_auto( (string) $current ) ) {
					return;
				}
				$tags->set_attribute( 'data-sizes', 'auto, ' . (string) $current );
				return;
			}
			if ( ! $is_img ) {
				return;
			}
			$tags->set_attribute( 'data-sizes', 'auto' );
		}

		/**
		 * Whether the WP 6.9+ HTML API picture parser is available.
		 *
		 * Delegates to {@see Util::should_use_html_processor()} so every
		 * processor-based rewrite shares one reflection guard for the public
		 * `WP_HTML_Processor::serialize_token()` (WP 6.9).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private function should_use_html_processor(): bool {
			return Util::should_use_html_processor();
		}

		/**
		 * Map a data attribute name via WP 6.9+ helpers when available.
		 *
		 * Guards `wp_html_custom_data_attribute_name()` so data-* mapping
		 * uses core helper on WP 6.9+ without breaking WP <6.9. Falls back
		 * to the raw attribute name.
		 *
		 * @since 2.0.0
		 * @param string $attr Raw attribute name (e.g. `data-wppo-dominant-color`).
		 * @return string Normalized attribute name.
		 */
		private function normalize_data_attribute_name( string $attr ): string {
			if ( function_exists( 'wp_html_custom_data_attribute_name' ) ) {
				// wp_html_custom_data_attribute_name() validates/normalizes custom data attributes on WP 6.9+.
				$normalized = \wp_html_custom_data_attribute_name( $attr ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound
				if ( is_string( $normalized ) && '' !== $normalized ) {
					return $normalized;
				}
			}
			return $attr;
		}

		/**
		 * Processes <picture> blocks using WP_HTML_Processor for reliable block extraction with depth tracking.
		 *
		 * Uses spec-compliant token walking via serialize_token() with manual nesting
		 * tracking so nested <picture>, comments, SVG/mathML and malformed HTML are
		 * handled without the fragility of PCRE.
		 *
		 * Duplication note (D-13): the sibling `process_picture_blocks_regex()` is kept
		 * intentionally as a fallback for hosts without `WP_HTML_Processor` (WP < 6.4)
		 * or when `serialize_token()` is unavailable. Both share `process_picture_tag()`
		 * for the per-picture decision logic; the `srcset` rewriting helpers are
		 * similarly split (TagProcessor vs regex) for the same fallback reason.
		 * Consolidated via shared helpers; no further dedup is safe without losing
		 * the version-gated fallback.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer           The HTML buffer.
		 * @param int    $img_counter      Current image counter.
		 * @param int    $exclude_img_count Number of first images to exclude.
		 * @param array  $exclude_imgs     List of image URLs to exclude.
		 * @return string The modified buffer.
		 */
		private function process_picture_blocks_processor( string $buffer, int $img_counter, int $exclude_img_count, array $exclude_imgs ): string {
			if ( ! $this->should_use_html_processor() ) {
				return $this->process_picture_blocks_regex( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
			}

			$processor = Util::create_html_processor( $buffer );
			if ( null === $processor ) {
				return $this->process_picture_blocks_regex( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
			}

			$out          = '';
			$in_picture   = false;
			$nesting      = 0;
			$picture_html = '';

			try {
				while ( $processor->next_token() ) {
					$type = $processor->get_token_type();

					if ( '#tag' !== $type ) {
						$tok = (string) $processor->serialize_token();
						if ( $in_picture ) {
							$picture_html .= $tok;
						} else {
							$out .= $tok;
						}
						continue;
					}

					$is_closer = $processor->is_tag_closer();
					$tag       = $processor->get_tag();

					if ( ! $in_picture && 'PICTURE' === $tag && ! $is_closer ) {
						$in_picture   = true;
						$nesting      = 1;
						$picture_html = (string) $processor->serialize_token();
						continue;
					}

					if ( $in_picture ) {
						$picture_html .= (string) $processor->serialize_token();

						if ( 'PICTURE' === $tag ) {
							if ( ! $is_closer ) {
								++$nesting;
							} else {
								--$nesting;
								if ( 0 === $nesting ) {
									list( $img_tag, $src ) = $this->extract_picture_img( $picture_html );

									if ( '' !== $img_tag && '' !== $src ) {
										++$img_counter;
										if ( $exclude_img_count >= $img_counter ) {
											$exclude_imgs[] = $src;
										}
										$out .= $this->process_picture_tag( array( $picture_html ), $img_tag, $src, $exclude_imgs );
									} else {
										$out .= $picture_html;
									}

									$in_picture   = false;
									$picture_html = '';
									$nesting      = 0;
								}
							}
						}
						continue;
					}

					$out .= (string) $processor->serialize_token();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return $this->process_picture_blocks_regex( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
			}

			if ( method_exists( $processor, 'get_last_error' ) && null !== $processor->get_last_error() ) {
				return $this->process_picture_blocks_regex( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
			}

			if ( $in_picture && '' !== $picture_html ) {
				$out .= $picture_html;
			}

			return $out;
		}

		/**
		 * Extract the inner `<img>` tag and its src from a `<picture>` block.
		 *
		 * Strict fallback chain (issue #1120): Tag Processor first, then the
		 * regex last-resort. Processor `null` returns cast to empty string so
		 * malformed markup fails open to the unoptimised block, never fatal.
		 * Guards `class_exists('WP_HTML_Tag_Processor')` so WP 6.2 behaviour
		 * stays byte-identical when the Tag Processor is unavailable.
		 *
		 * @since NEXT
		 *
		 * @param string $picture_html Serialized `<picture>...</picture>` block.
		 * @return array{0:string,1:string} Tuple of (img tag, src); empty strings when none found.
		 */
		private function extract_picture_img( string $picture_html ): array {
			$src     = '';
			$img_tag = '';
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				try {
					$tmp = new \WP_HTML_Tag_Processor( $picture_html );
					if ( $tmp->next_tag( array( 'tag_name' => 'img' ) ) ) {
						$maybe_src = $tmp->get_attribute( 'data-src' );
						if ( null === $maybe_src ) {
							$maybe_src = $tmp->get_attribute( 'src' );
						}
						$src = is_string( $maybe_src ) ? $maybe_src : '';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$src = '';
				}
				if ( preg_match( '#<img\b[^>]*>#i', $picture_html, $m ) ) {
					$img_tag = $m[0];
				}
				if ( '' !== $img_tag && '' !== $src ) {
					return array( $img_tag, $src );
				}
			}
			// Regex last-resort (also the WP 6.2 path).
			if ( preg_match( '#<img\b[^>]*?(?:data-)?src=["\']([^"\']+)["\'][^>]*>#i', $picture_html, $img_matches ) ) {
				return array( $img_matches[0], $img_matches[1] );
			}
			if ( '' === $img_tag && preg_match( '#<img\b[^>]*>#i', $picture_html, $m ) ) {
				$img_tag = $m[0];
			}
			return array( $img_tag, $src );
		}

		/**
		 * Processes <picture> blocks using regex fallback when WP_HTML_Processor is unavailable.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer           The HTML buffer.
		 * @param int    $img_counter      Current image counter.
		 * @param int    $exclude_img_count Number of first images to exclude.
		 * @param array  $exclude_imgs     List of image URLs to exclude.
		 * @return string The modified buffer.
		 */
		private function process_picture_blocks_regex( string $buffer, int $img_counter, int $exclude_img_count, array $exclude_imgs ): string {
			$result = preg_replace_callback(
				'#<picture\b[^>]*>.*?</picture>#is',
				function ( $matches ) use ( $img_counter, $exclude_img_count, $exclude_imgs ) {
					preg_match( '#<img\b[^>]*?(?:data-)?src=["\']([^"\']+)["\'][^>]*>#i', $matches[0], $img_matches );
					if ( ! empty( $img_matches ) ) {
						++$img_counter;
						if ( $exclude_img_count >= $img_counter ) {
							$exclude_imgs[] = $img_matches[1];
						}
						return $this->process_picture_tag( $matches, $img_matches[0], $img_matches[1], $exclude_imgs );
					}
					return $matches[0];
				},
				$buffer
			);
			// preg_replace_callback() returns null on regex failure — keep
			// the original buffer so a PCRE error never wipes the page.
			return null !== $result ? $result : $buffer;
		}

		/**
		 * Whether comment-image hardening is enabled (issue #1271).
		 *
		 * Additive `image_optimisation.hardenCommentImages` key; absent key
		 * reads as enabled (fail-safe) so legacy installs get the
		 * denylist/escape gate without a DB write. Explicit `false`
		 * restores the legacy byte-identical rewrite path.
		 *
		 * @since NEXT
		 * @return bool True when comment image markup must be sanitized.
		 */
		private function is_comment_hardening_enabled(): bool {
			$value = $this->options['image_optimisation']['hardenCommentImages'] ?? true;
			return ! empty( $value );
		}

		/**
		 * Whether an image URL value is scriptable and must never be rewritten.
		 *
		 * Rejects `javascript:`/`vbscript:` and `data:` payloads that are not
		 * `data:image/` (e.g. `data:text/html`), plus any other scheme that
		 * is not `http`/`https`. Scheme-less values (relative paths,
		 * root-relative paths, protocol-relative URLs) are not scriptable.
		 * Control/whitespace obfuscation (`java\tscript:`) is normalized
		 * before the scheme check. Fail-open: undecodable input returns
		 * false so the caller keeps its existing validity gate.
		 *
		 * @since NEXT
		 * @param string $url Raw attribute URL value.
		 * @return bool True when the URL is scriptable.
		 */
		private function is_scriptable_image_url( string $url ): bool {
			static $memo = array();
			// Full-string hash key: prefix truncation collides on long
			// control/whitespace-padded URLs (issue #1271 follow-up).
			$memo_key = md5( $url );
			if ( isset( $memo[ $memo_key ] ) ) {
				return $memo[ $memo_key ];
			}
			$result = $this->compute_is_scriptable_image_url( $url );
			if ( count( $memo ) >= 200 ) {
				array_shift( $memo );
			}
			$memo[ $memo_key ] = $result;
			return $result;
		}

		/**
		 * Core scriptable-URL check backing the memoized wrapper.
		 *
		 * Full entity-decode (repeated until stable, bounded at 5 passes)
		 * plus control/whitespace stripping before the scheme regex, so
		 * `&#106;avascript:` / `javascript&colon;` obfuscation cannot
		 * smuggle a scheme past the gate. `data:image/svg+xml` is treated
		 * as scriptable (raster-only allowlist: png/jpeg/gif/webp/avif).
		 *
		 * @since NEXT
		 * @param string $url Raw attribute URL value.
		 * @return bool True when the URL is scriptable.
		 */
		private function compute_is_scriptable_image_url( string $url ): bool {
			try {
				$decoded = trim( $url );
				for ( $i = 0; $i < 5; $i++ ) {
					$next = html_entity_decode( htmlspecialchars_decode( $decoded, ENT_QUOTES ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
					if ( $next === $decoded ) {
						break;
					}
					$decoded = $next;
					if ( strlen( $decoded ) > 4096 ) {
						$decoded = substr( $decoded, 0, 4096 );
						break;
					}
				}
				if ( '' === $decoded ) {
					return false;
				}
				// Strip ASCII control characters + whitespace so
				// `java\tscript:`-style obfuscation cannot smuggle a scheme.
				$compact = (string) preg_replace( '/[\x00-\x20]+/', '', ltrim( $decoded ) );
				if ( '' === $compact || null === $compact ) {
					return false;
				}
				if ( 0 === strpos( $compact, '//' ) ) {
					return false;
				}
				if ( ! preg_match( '/^([a-zA-Z][a-zA-Z0-9+.-]*)\s*:/', $compact, $m ) ) {
					return false;
				}
				$scheme = strtolower( $m[1] );
				if ( 'http' === $scheme || 'https' === $scheme ) {
					return false;
				}
				if ( 'data' === $scheme ) {
					return 1 !== preg_match( '#^data:image/(?:png|jpe?g|gif|webp|avif)[;,]#i', $compact );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a `style` attribute value carries a scriptable payload.
		 *
		 * Single shared gate for the regex and Tag Processor sanitizer
		 * paths so they stay in parity (issue #1271 follow-up).
		 *
		 * @since NEXT
		 * @param string $value Raw style value.
		 * @return bool True when the style value is hostile.
		 */
		private function is_hostile_style_value( string $value ): bool {
			// Stable entity-decode (5 passes) plus control/whitespace
			// compacting — same normalization as the URL gate — so
			// `java&#9;script:`, double-encoded `&amp;#106;...`, and
			// `expression (` / `behavior :` whitespace variants cannot
			// smuggle a payload past the substring checks.
			$decoded = $value;
			for ( $i = 0; $i < 5; $i++ ) {
				$next = html_entity_decode( $decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				if ( $next === $decoded ) {
					break;
				}
				$decoded = $next;
				if ( strlen( $decoded ) > 4096 ) {
					$decoded = substr( $decoded, 0, 4096 );
					break;
				}
			}
			$compact = (string) preg_replace( '/[\x00-\x20]+/', '', strtolower( $decoded ) );
			if ( '' === $compact ) {
				return false;
			}
			return false !== strpos( $compact, 'expression(' ) || false !== strpos( $compact, 'javascript:' ) || false !== strpos( $compact, 'vbscript:' ) || false !== strpos( $compact, 'behaviour:' ) || false !== strpos( $compact, 'behavior:' ) || false !== strpos( $compact, '-moz-binding' );
		}

		/**
		 * Split a `srcset` value into candidates without breaking `data:` URIs.
		 *
		 * `data:image/png;base64,...` contains a comma that a naive
		 * `explode(',', ...)` would split. The negative lookahead keeps
		 * `base64` payload commas intact.
		 *
		 * @since NEXT
		 * @param string $raw Raw srcset attribute value.
		 * @return string[] Trimmed non-empty candidate items.
		 */
		private function split_srcset_candidates( string $raw ): array {
			// Protect `;base64,` payload commas (case-insensitive) before
			// splitting; then re-merge fragments of non-base64
			// `data:image/<raster>,RAWBYTES <descriptor>` candidates whose
			// internal comma was still split.
			$placeholder = ";base64\x00WPPO_COMMA\x00";
			$guarded     = str_ireplace( ';base64,', $placeholder, $raw );
			$items       = explode( ',', $guarded );
			$merged      = array();
			$count       = count( $items );
			for ( $i = 0; $i < $count; $i++ ) {
				$item = trim( (string) $items[ $i ] );
				if ( '' === $item ) {
					continue;
				}
				// A `data:image/<raster>[;,]...` fragment with no whitespace
				// separator (no descriptor yet) was split mid-candidate:
				// re-join with the next chunk.
				if ( 1 === preg_match( '#^data:image/(?:png|jpe?g|gif|webp|avif)[;,][^\\s]*$#i', $item ) && isset( $items[ $i + 1 ] ) ) {
					$item = $item . ',' . trim( (string) $items[ $i + 1 ] );
					++$i;
					$item = trim( $item );
					if ( '' === $item ) {
						continue;
					}
				}
				$merged[] = $item;
			}
			$kept = array();
			foreach ( $merged as $item ) {
				$kept[] = str_replace( $placeholder, ';base64,', $item );
			}
			return $kept;
		}

		/**
		 * Split a srcset candidate item into URL + descriptor without TypeError.
		 *
		 * `preg_split()` returns `false` on PCRE failure; `array_pad(false)`
		 * TypeErrors on PHP 8.2. Shared by the regex and Tag Processor
		 * srcset loops (issue #1271 follow-up).
		 *
		 * @since NEXT
		 * @param string $item Single srcset candidate item.
		 * @return string[] Two-element [url, descriptor] array.
		 */
		private function split_srcset_item( string $item ): array {
			$split = preg_split( '/\s+/', $item, 2 );
			if ( ! is_array( $split ) ) {
				$split = array( $item );
			}
			$padded = array_pad( $split, 2, '' );
			return array( (string) $padded[0], (string) $padded[1] );
		}

		/**
		 * Whether an attribute name is an inline event handler (`on*`).
		 *
		 * Fail-closed for unknown `on*` names but spares benign
		 * non-handler attributes starting with `on` (`only`, `one`,
		 * `online`). Shared by the regex and Tag Processor sanitizer
		 * paths so they stay in parity (issue #1271 follow-up).
		 *
		 * @since NEXT
		 * @param string $name Raw attribute name.
		 * @return bool True when the attribute is an event handler.
		 */
		private function is_event_attribute_name( string $name ): bool {
			$lower = strtolower( $name );
			if ( in_array( $lower, self::BENIGN_ON_PREFIX_ATTRS, true ) ) {
				return false;
			}
			return 1 === preg_match( '/^on[a-z]{2,}$/', $lower );
		}

		/**
		 * Whether an upper-case Tag Processor tag name is hardening-scoped.
		 *
		 * O(1) lookup replacing the inline 15-way `===` chain in the hot
		 * loops. The unfiltered `next_tag()` traversal is kept deliberately:
		 * the multi-tag `tag_names` query filter is unavailable on the
		 * minimum supported WP 6.2 core, and per-tag filtered passes would
		 * re-parse the full buffer N times.
		 *
		 * @since NEXT
		 * @param string $tag_name Upper-case tag name from `get_tag()`.
		 * @return bool True when the tag is in `HARDENED_TAGS`.
		 */
		private function is_hardened_tag( string $tag_name ): bool {
			static $map = null;
			if ( null === $map ) {
				$map = array();
				foreach ( self::HARDENED_TAGS as $tag ) {
					$map[ strtoupper( $tag ) ] = true;
				}
			}
			return isset( $map[ $tag_name ] );
		}

		/**
		 * Regex alternation for the hardening tag scope (e.g. `img|image|...`).
		 *
		 * @since NEXT
		 * @return string Alternation safe for `#<(...)\b` patterns.
		 */
		private function hardened_tag_alternation(): string {
			return implode( '|', self::HARDENED_TAGS );
		}

		/**
		 * Strip hostile attributes from a single opening tag.
		 *
		 * Handles every tag in `HARDENED_TAGS` (img/image/source/video/
		 * iframe/audio/embed/object/svg/math plus `use`/`a`/`table`/`body`/
		 * `td`/`th` carriers of href/background attributes) — not just
		 * img/source/video. Named `sanitize_image_tag_html` for history;
		 * runs site-wide on the full buffer (not comment-only) so hostile
		 * markup can never be laundered into the static cache file.
		 *
		 * Denylist approach: event-handler attributes (`on*`), scriptable
		 * URL attributes, and `style` payloads carrying `expression(` /
		 * `javascript:` / `vbscript:` are removed; safe attributes (`src`,
		 * `srcset`, `alt`, `width`, `height`, `loading`, `decoding`,
		 * `fetchpriority`, `sizes`, `media`, `type`, `class`, `id`, …) are
		 * preserved byte-identical so galleries/`<picture>` fixtures keep
		 * their layout. Regex-based so the legacy WP 6.2 path (no Tag
		 * Processor) gets the same gate. Fail-open: returns the input tag
		 * unchanged on any PCRE failure.
		 *
		 * @since NEXT
		 * @param string $tag Raw opening tag HTML.
		 * @return string Sanitized tag.
		 */
		private function sanitize_image_tag_html( string $tag ): string {
			try {
				// Per-tag fast-path: skip regex steps whose trigger
				// substring is absent so benign gallery imgs avoid all
				// four scans plus per-URL entity decoding.
				$has_on     = false !== stripos( $tag, 'on' );
				$has_url    = false !== stripos( $tag, 'src' ) || false !== stripos( $tag, 'href' ) || false !== stripos( $tag, 'data' ) || false !== stripos( $tag, 'poster' ) || false !== stripos( $tag, 'srcdoc' ) || false !== stripos( $tag, 'background' ) || false !== stripos( $tag, 'lowsrc' ) || false !== stripos( $tag, 'action' ) || false !== stripos( $tag, 'cite' ) || false !== stripos( $tag, 'longdesc' ) || false !== stripos( $tag, 'codebase' ) || false !== stripos( $tag, 'usemap' );
				$has_srcset = false !== stripos( $tag, 'srcset' );
				$has_style  = false !== stripos( $tag, 'style' );
				if ( ! $has_on && ! $has_url && ! $has_srcset && ! $has_style ) {
					return $tag;
				}
				// 1. Strip inline event handlers (onerror, onload, onclick, on*).
				// Slash separator allowed: `<img/onerror=...>` is valid HTML.
				// Callback consults is_event_attribute_name() so benign
				// `only`/`one`/`online`/`once`/`onto`/`onion` attributes survive.
				if ( $has_on ) {
					$cleaned = preg_replace_callback(
						'#[\s/]+(on[a-z]+)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>"\']+)#i',
						function ( $matches ) {
							return $this->is_event_attribute_name( $matches[1] ) ? '' : $matches[0];
						},
						$tag
					);
					if ( null === $cleaned ) {
						return $tag;
					}
					$tag = $cleaned;
				}

				// 2. Drop scriptable single-URL attributes. Slash separator
				// allowed for parity with step 1 (`<img/src="...">`).
				// Covers `data`/`codebase`/`usemap` so `<object data=>`
				// payloads are inspected on both layers. Attribute list
				// mirrors HARDENED_URL_ATTRS (kept as a literal here so
				// the regex stays a single compiled pattern).
				if ( $has_url ) {
					$tag = (string) preg_replace_callback(
						'#[\s/]+(src|data-src|data|codebase|usemap|poster|srcdoc|background|lowsrc|href|xlink:href|action|formaction|cite|longdesc)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\']+))#i',
						function ( $matches ) {
							$attr  = strtolower( $matches[1] );
							$value = '';
							if ( isset( $matches[3] ) && '' !== $matches[3] ) {
								$value = $matches[3];
							} elseif ( isset( $matches[4] ) && '' !== $matches[4] ) {
								$value = $matches[4];
							} elseif ( isset( $matches[5] ) ) {
								$value = $matches[5];
							}
							if ( 'srcdoc' === $attr ) {
								// Inline HTML payload: never legitimate on
								// image-adjacent tags; drop unconditionally.
								return '';
							}
							if ( $this->is_scriptable_image_url( $value ) ) {
								return '';
							}
							return $matches[0];
						},
						$tag
					);
				}

				// 3. Filter srcset/data-srcset item-by-item so one hostile
				// candidate cannot poison the whole attribute; gallery
				// srcsets with only safe candidates stay byte-identical.
				// Slash separator allowed (`<img/srcset="...">`).
				if ( $has_srcset ) {
					$tag = (string) preg_replace_callback(
						'#[\s/]+((?:data-)?srcset)\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\']+))#i',
						function ( $matches ) {
							$attr  = $matches[1];
							$first = substr( $matches[2], 0, 1 );
							$quote = ( '"' === $first || "'" === $first ) ? $first : '"';
							$raw   = '';
							if ( isset( $matches[3] ) && '' !== $matches[3] ) {
								$raw = $matches[3];
							} elseif ( isset( $matches[4] ) && '' !== $matches[4] ) {
								$raw = $matches[4];
							} elseif ( isset( $matches[5] ) ) {
								$raw = $matches[5];
							}
							$kept = array();
							foreach ( $this->split_srcset_candidates( $raw ) as $item ) {
								list( $candidate ) = $this->split_srcset_item( $item );
								if ( $this->is_scriptable_image_url( $candidate ) ) {
									continue;
								}
								$kept[] = $item;
							}
							if ( array() === $kept ) {
								return '';
							}
							$rebuilt = implode( ', ', $kept );
							if ( $rebuilt === $raw ) {
								return $matches[0];
							}
							return ' ' . $attr . '=' . $quote . $rebuilt . $quote;
						},
						$tag
					);
				}

				// 4. Strip style attributes smuggling CSS/script vectors.
				// Accepts quoted and unquoted values (`style=expression(...)`);
				// slash separator allowed for parity with step 1.
				if ( $has_style ) {
					$tag = (string) preg_replace_callback(
						'#[\s/]+style\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s>"\']+))#i',
						function ( $matches ) {
							$value = '';
							if ( isset( $matches[2] ) && '' !== $matches[2] ) {
								$value = $matches[2];
							} elseif ( isset( $matches[3] ) && '' !== $matches[3] ) {
								$value = $matches[3];
							} elseif ( isset( $matches[4] ) ) {
								$value = $matches[4];
							}
							if ( $this->is_hostile_style_value( $value ) ) {
								return '';
							}
							return $matches[0];
						},
						$tag
					);
				}

				return $tag;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $tag;
			}
		}

		/**
		 * Sanitize hardened tags across a full buffer.
		 *
		 * Runs before next-gen/lazy rewriting so hostile comment-authored
		 * markup (`img`/`image` `onerror`, `picture source`, inline `on*`
		 * handlers, scriptable URLs, `<svg>`/`<math>` active content)
		 * renders inert and can never be laundered into the static cache
		 * file. Named `sanitize_comment_images_*` for history; runs
		 * site-wide on the full buffer (not comment-only) because the
		 * cache layer caches full pages. Safe gallery/`<picture>` markup
		 * has no such attributes and passes through unchanged (no layout
		 * regression). Fail-open: returns the input buffer unchanged when
		 * hardening is disabled, the buffer is empty, or PCRE fails.
		 *
		 * @since NEXT
		 * @param string $buffer Full HTML buffer.
		 * @return string Sanitized buffer.
		 */
		private function sanitize_comment_images_in_buffer( string $buffer ): string {
			if ( '' === $buffer || ! $this->is_comment_hardening_enabled() ) {
				return $buffer;
			}
			// Cheap fast-path: one combined pre-check instead of N
			// full-buffer stripos scans (imageless pages pay one scan).
			$alternation = $this->hardened_tag_alternation();
			if ( 1 !== preg_match( '#<(' . $alternation . ')\b#i', $buffer ) ) {
				return $buffer;
			}
			try {
				$result = preg_replace_callback(
					'#<(' . $alternation . ')\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
					function ( $matches ) {
						return $this->sanitize_image_tag_html( $matches[0] );
					},
					$buffer
				);
				$buffer = is_string( $result ) ? $result : $buffer;
				// SVG/math inner active content: the opening-tag pass above
				// cannot neutralize `<svg><script>`, `<animate onbegin>`,
				// `<foreignObject><img onerror>>`, or
				// `<math><mi href="javascript:">` children. Sanitize each
				// svg/math subtree fail-closed, including a trailing
				// unclosed `<svg>`/`<math>` tail (browsers auto-close and
				// execute it). Guarded so buffers without svg/math skip
				// this third full-buffer walk.
				if ( false !== stripos( $buffer, '<svg' ) || false !== stripos( $buffer, '<math' ) ) {
					$buffer = (string) preg_replace_callback(
						'#<(svg|math)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>(.*?)(</\1\s*>|$)#is',
						function ( $matches ) {
							return $this->sanitize_svg_math_block( $matches[0] );
						},
						$buffer
					);
				}
				return $buffer;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $buffer;
			}
		}

		/**
		 * Sanitize an `<svg>...</svg>` / `<math>...</math>` block, including inner content.
		 *
		 * Drops executable inner elements (`script`, `animate`,
		 * `animateTransform`, `foreignObject`, `set`, `discard`) entirely
		 * and strips hostile attributes from the remaining inner tags via
		 * the shared {@see sanitize_image_tag_html()} gate, so nested
		 * `<img onerror>` / `<a xlink:href="javascript:">` /
		 * `<mi href="javascript:">` cannot survive into cached HTML.
		 *
		 * @since NEXT
		 * @param string $block Full svg/math block HTML.
		 * @return string Sanitized block.
		 */
		private function sanitize_svg_math_block( string $block ): string {
			try {
				$cleaned = preg_replace(
					'#<(script|animate|animateTransform|foreignObject|set|discard)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>.*?</\1\s*>#is',
					'',
					$block
				);
				if ( null !== $cleaned ) {
					$block = $cleaned;
				}
				$block  = preg_replace( '#<(script|animate|animateTransform|foreignObject|set|discard)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*/?>#i', '', $block );
				$result = preg_replace_callback(
					'#<(?!/)([a-zA-Z][a-zA-Z0-9:_.-]*)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#',
					function ( $matches ) {
						return $this->sanitize_image_tag_html( $matches[0] );
					},
					$block
				);
				return is_string( $result ) ? $result : $block;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $block;
			}
		}

		/**
		 * Strip hostile attributes from the current Tag Processor tag.
		 *
		 * Defense-in-depth for the `WP_HTML_Tag_Processor` rewrite loops:
		 * the buffer pre-pass already removed `on*`/scriptable attributes,
		 * but a hostile node that survived (e.g. entity-obfuscated input the
		 * regex missed) is neutralized here before `set_attribute()` can
		 * re-emit it into cached HTML. Guards `get_attribute_names()` /
		 * `remove_attribute()` so WP 6.2 cores without those methods stay
		 * byte-identical. Never fatals: any failure leaves the tag
		 * untouched for the caller to skip or fail open.
		 *
		 * @since NEXT
		 * @param object $tags Active `WP_HTML_Tag_Processor` positioned on a tag.
		 * @return void
		 */
		private function sanitize_tag_attributes_processor( $tags ): void {
			try {
				if ( ! $this->is_comment_hardening_enabled() ) {
					return;
				}
				if ( ! is_object( $tags ) || ! method_exists( $tags, 'get_attribute' ) || ! method_exists( $tags, 'remove_attribute' ) ) {
					return;
				}
				$names = array();
				if ( method_exists( $tags, 'get_attribute_names' ) ) {
					$got = $tags->get_attribute_names();
					if ( is_array( $got ) ) {
						$names = $got;
					}
				}
				if ( array() === $names ) {
					// Fallback probe list when the API is unavailable: full
					// event-handler coverage plus URL/style attributes.
					$names = array( 'onerror', 'onload', 'onclick', 'onmouseover', 'onmouseout', 'onmouseenter', 'onmouseleave', 'onmousemove', 'onmousedown', 'onmouseup', 'onfocus', 'onblur', 'onkeydown', 'onkeyup', 'onkeypress', 'onsubmit', 'onchange', 'oninput', 'onanimationend', 'onanimationstart', 'ontoggle', 'onplay', 'onpause', 'onended', 'onpointerover', 'onpointerdown', 'ontouchstart', 'onwheel', 'onscroll', 'ondblclick', 'oncontextmenu', 'ondragstart', 'ondrop', 'onbegin', 'onend', 'onrepeat', 'formaction', 'xlink:href', 'href', 'action', 'src', 'data-src', 'data', 'codebase', 'usemap', 'srcset', 'data-srcset', 'poster', 'srcdoc', 'background', 'lowsrc', 'style' );
				}
				foreach ( $names as $name ) {
					if ( ! is_string( $name ) || '' === $name ) {
						continue;
					}
					$lower = strtolower( $name );
					if ( $this->is_event_attribute_name( $lower ) ) {
						$tags->remove_attribute( $name );
						continue;
					}
					if ( 'srcdoc' === $lower ) {
						$value = $tags->get_attribute( $name );
						if ( null !== $value ) {
							$tags->remove_attribute( $name );
						}
						continue;
					}
					if ( in_array( $lower, self::HARDENED_URL_ATTRS, true ) && 'srcdoc' !== $lower ) {
						$value = $tags->get_attribute( $name );
						if ( is_string( $value ) && $this->is_scriptable_image_url( $value ) ) {
							$tags->remove_attribute( $name );
						}
						continue;
					}
					if ( in_array( $lower, self::HARDENED_SRCSET_ATTRS, true ) ) {
						$value = $tags->get_attribute( $name );
						if ( ! is_string( $value ) || '' === $value ) {
							continue;
						}
						$kept = array();
						foreach ( $this->split_srcset_candidates( $value ) as $item ) {
							list( $candidate ) = $this->split_srcset_item( $item );
							if ( $this->is_scriptable_image_url( $candidate ) ) {
								continue;
							}
							$kept[] = $item;
						}
						if ( array() === $kept ) {
							$tags->remove_attribute( $name );
						} elseif ( implode( ', ', $kept ) !== $value ) {
							$tags->set_attribute( $name, implode( ', ', $kept ) );
						}
						continue;
					}
					if ( 'style' === $lower ) {
						$value = $tags->get_attribute( $name );
						if ( is_string( $value ) && $this->is_hostile_style_value( $value ) ) {
							$tags->remove_attribute( $name );
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Serves next-generation images if supported by the browser.
		 *
		 * @since 1.0.0
		 *
		 * @param string $buffer The HTML content buffer.
		 *
		 * @return string Modified HTML content buffer.
		 */
		public function maybe_serve_next_gen_images( $buffer ) {
			if ( is_string( $buffer ) && '' !== $buffer ) {
				$buffer = $this->sanitize_comment_images_in_buffer( $buffer );
			}
			if ( ! empty( $this->options['image_optimisation']['convertImg'] ) ) {
				$conversion_format = $this->options['image_optimisation']['conversionFormat'] ?? 'webp';

				$exclude_imgs = $this->exclude_convert_imgs;

				$http_accept = isset( $_SERVER['HTTP_ACCEPT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : '';

				$supports_avif = false !== strpos( $http_accept, 'image/avif' );
				$supports_webp = false !== strpos( $http_accept, 'image/webp' );

				if ( ! $supports_avif && ! $supports_webp ) {
					return $buffer;
				}

				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$tags = new \WP_HTML_Tag_Processor( $buffer );

					// Hoisted per-pass: avoids re-reading the hardening flag
					// and re-probing method_exists inside every tag visit.
					// Unfiltered next_tag() is deliberate: the multi-tag
					// `tag_names` query filter needs newer core than the
					// WP 6.2 minimum, and per-tag filtered passes would
					// re-parse the buffer per tag. is_hardened_tag() is an
					// O(1) map lookup (single HARDENED_TAGS source).
					$hardening_on = $this->is_comment_hardening_enabled();
					while ( $tags->next_tag() ) {
						$tag_name = $tags->get_tag();

						if ( $hardening_on && is_string( $tag_name ) && $this->is_hardened_tag( $tag_name ) ) {
							$this->sanitize_tag_attributes_processor( $tags );
						}
						if ( 'IMG' === $tag_name || 'IMAGE' === $tag_name ) {
							$src = $tags->get_attribute( 'src' );
							if ( $src ) {
								$normalized_src = $this->normalize_url( $src );
								if ( $this->is_valid_url( $normalized_src ) && ! $this->is_scriptable_image_url( $normalized_src ) ) {
									$new_src = $this->replace_image_with_next_gen( $normalized_src, $exclude_imgs, $supports_avif, $supports_webp );
									// Only write back if the URL actually changed (i.e. conversion occurred).
									if ( $new_src !== $normalized_src ) {
										$tags->set_attribute( 'src', $new_src );
									}
								}
							}
						}

						if ( 'IMG' === $tag_name || 'IMAGE' === $tag_name || 'SOURCE' === $tag_name ) {
							$srcset = $tags->get_attribute( 'srcset' );
							if ( $srcset ) {
								$new_srcset_parts = array();
								$srcset_items     = $this->split_srcset_candidates( $srcset );

								foreach ( $srcset_items as $srcset_item ) {
									list( $original_token, $descriptor ) = $this->split_srcset_item( trim( $srcset_item ) );
									$normalized_url                      = $this->normalize_url( $original_token );

									if ( $this->is_scriptable_image_url( $original_token ) || $this->is_scriptable_image_url( $normalized_url ) ) {
										// Fail-closed: drop hostile
										// candidates instead of
										// preserving them byte-identical.
										continue;
									}
									if ( $this->is_valid_url( $normalized_url ) ) {
										$new_url = $this->replace_image_with_next_gen( $normalized_url, $exclude_imgs, $supports_avif, $supports_webp );
										// Use the optimized URL if conversion happened, otherwise keep the original token.
										$final_url          = ( $new_url !== $normalized_url ) ? $new_url : $original_token;
										$new_srcset_parts[] = $final_url . ( $descriptor ? " $descriptor" : '' );
									} else {
										$new_srcset_parts[] = $original_token . ( $descriptor ? " $descriptor" : '' );
									}
								}

								$new_srcset = implode( ', ', $new_srcset_parts );
								if ( array() === $new_srcset_parts ) {
									$tags->remove_attribute( 'srcset' );
								} elseif ( $new_srcset !== $srcset ) {
									$tags->set_attribute( 'srcset', $new_srcset );
								}
							}
						} elseif ( 'VIDEO' === $tag_name ) {
							$poster = $tags->get_attribute( 'poster' );
							if ( $poster ) {
								if ( $this->is_scriptable_image_url( $poster ) ) {
									$tags->remove_attribute( 'poster' );
								} else {
									$normalized_poster = $this->normalize_url( $poster );
									if ( $this->is_valid_url( $normalized_poster ) && ! $this->is_scriptable_image_url( $normalized_poster ) ) {
										$new_poster = $this->replace_image_with_next_gen( $normalized_poster, $exclude_imgs, $supports_avif, $supports_webp );
										if ( $new_poster !== $normalized_poster ) {
											$tags->set_attribute( 'poster', $new_poster );
										}
									}
								}
							}
						}
					}

					return $tags->get_updated_html();
				} else {
					// Regex Fallback for hosts without WP_HTML_Tag_Processor.
					// @since 2.0.0 Fixed fallback to handle <source> and <video poster> (previously only <img>).
					// Prefer the TagProcessor path when available; this fallback preserves
					// <source> src/srcset and <video> poster handling for older WP.
					// Quoted-aware matchers (same shape as the sanitizer) so a
					// `>` inside a quoted value cannot truncate the match and
					// hide a later src/srcset/poster on the WP<6.2 path.
					// Covers the `<image>` parser alias for `<img>`.
					$buffer = preg_replace_callback(
						'#<(?:img|image)\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
						function ( $matches ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
							$img_tag = $matches[0];

							$updated_img_tag = preg_replace_callback(
								'#src=["\']([^"\']+)["\']#i',
								function ( $src_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$url = $src_match[1];
									if ( $this->is_scriptable_image_url( $url ) ) {
										// Fail-closed: strip hostile URLs
										// instead of preserving them.
										return '';
									}
									if ( $this->is_valid_url( $url ) ) {
										return 'src="' . $this->replace_image_with_next_gen( $src_match[1], $exclude_imgs, $supports_avif, $supports_webp ) . '"';
									}
									return $src_match[0];
								},
								$img_tag
							);

							$updated_img_tag = preg_replace_callback(
								'#srcset=["\']([^"\']+)["\']#i',
								function ( $srcset_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$srcset = $srcset_match[1];

									$kept = array();
									foreach ( $this->split_srcset_candidates( $srcset ) as $srcset_item ) {
										list( $url, $descriptor ) = $this->split_srcset_item( trim( $srcset_item ) );
										if ( $this->is_scriptable_image_url( $url ) ) {
											continue;
										}
										$new_url = $this->replace_image_with_next_gen( $url, $exclude_imgs, $supports_avif, $supports_webp );
										$kept[]  = $new_url . ( $descriptor ? " $descriptor" : '' );
									}
									if ( array() === $kept ) {
										return '';
									}

									return 'srcset="' . implode( ', ', $kept ) . '"';
								},
								$updated_img_tag
							);

							return $updated_img_tag;
						},
						$buffer
					);

					// Preserve <source> src/srcset when TagProcessor is unavailable.
					$buffer = preg_replace_callback(
						'#<source\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
						function ( $matches ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
							$tag = $matches[0];

							$tag = preg_replace_callback(
								'#\bsrc=["\']([^"\']+)["\']#i',
								function ( $src_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$url = $src_match[1];
									if ( $this->is_scriptable_image_url( $url ) ) {
										return '';
									}
									if ( $this->is_valid_url( $url ) ) {
										return 'src="' . $this->replace_image_with_next_gen( $url, $exclude_imgs, $supports_avif, $supports_webp ) . '"';
									}
									return $src_match[0];
								},
								$tag
							);

							$tag = preg_replace_callback(
								'#\bsrcset=["\']([^"\']+)["\']#i',
								function ( $srcset_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$srcset = $srcset_match[1];
									$kept   = array();
									foreach ( $this->split_srcset_candidates( $srcset ) as $srcset_item ) {
										list( $url, $descriptor ) = $this->split_srcset_item( trim( $srcset_item ) );
										if ( $this->is_scriptable_image_url( $url ) ) {
											continue;
										}
										$new_url = $this->replace_image_with_next_gen( $url, $exclude_imgs, $supports_avif, $supports_webp );
										$kept[]  = $new_url . ( $descriptor ? " $descriptor" : '' );
									}
									if ( array() === $kept ) {
										return '';
									}
									return 'srcset="' . implode( ', ', $kept ) . '"';
								},
								$tag
							);

							return $tag;
						},
						$buffer
					);

					// Preserve <video poster> when TagProcessor is unavailable.
					$buffer = preg_replace_callback(
						'#<video\b(?:[^>"\']|"[^"]*"|\'[^\']*\')*>#i',
						function ( $matches ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
							$tag = $matches[0];
							return preg_replace_callback(
								'#\bposter=["\']([^"\']+)["\']#i',
								function ( $poster_match ) use ( $exclude_imgs, $supports_avif, $supports_webp ) {
									$url = $poster_match[1];
									if ( $this->is_scriptable_image_url( $url ) ) {
										return '';
									}
									if ( $this->is_valid_url( $url ) ) {
										$new_url = $this->replace_image_with_next_gen( $url, $exclude_imgs, $supports_avif, $supports_webp );
										if ( $new_url !== $url ) {
											return 'poster="' . $new_url . '"';
										}
									}
									return $poster_match[0];
								},
								$tag
							);
						},
						$buffer
					);

					return $buffer;
				}
			}

			return $buffer;
		}

		/**
		 * Gets a cached instance of Img_Converter.
		 *
		 * @since 1.1.2
		 *
		 * @return Img_Converter The Img_Converter instance.
		 */
		private function get_img_converter() {
			if ( null === $this->img_converter ) {
				$this->img_converter = new Img_Converter( $this->options );
			}
			return $this->img_converter;
		}

		/**
		 * Cached file_exists check to avoid repeated stat calls per image per request.
		 *
		 * @since 2.0.0
		 * @param string $path Absolute file path.
		 * @return bool Whether the file exists.
		 */
		private function cached_file_exists( string $path ): bool {
			if ( '' === $path ) {
				return false;
			}
			if ( isset( self::$file_exists_cache[ $path ] ) ) {
				return self::$file_exists_cache[ $path ];
			}
			$exists = file_exists( $path );
			// Bound cache size to prevent unbounded growth on pages with many images.
			if ( count( self::$file_exists_cache ) >= self::FILE_EXISTS_CACHE_LIMIT ) {
				// Evict oldest entry (FIFO).
				array_shift( self::$file_exists_cache );
			}
			self::$file_exists_cache[ $path ] = $exists;
			return $exists;
		}

		/**
		 * Clear the file_exists cache (for testing isolation).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function clear_file_exists_cache(): void {
			self::$file_exists_cache = array();
		}

		/**
		 * Get image dimensions with a bounded per-request LRU cache.
		 *
		 * Consolidates the `getimagesize` LRU that was copy-pasted between
		 * `post_process_img_dimensions()` and `add_delay_load_img()` (D-14).
		 *
		 * @since 2.0.0
		 * @param string $local_path Absolute file path.
		 * @return array|false Image size array or false on failure.
		 */
		private function get_cached_image_size( string $local_path ): array|false {
			if ( isset( self::$img_size_cache[ $local_path ] ) ) {
				$size = self::$img_size_cache[ $local_path ];
				unset( self::$img_size_cache[ $local_path ] );
				self::$img_size_cache[ $local_path ] = $size;
				return $size;
			}

			if ( count( self::$img_size_cache ) >= self::IMG_SIZE_CACHE_LIMIT ) {
				array_shift( self::$img_size_cache );
			}
			$size                                = getimagesize( $local_path );
			self::$img_size_cache[ $local_path ] = $size;
			return $size;
		}

		/**
		 * Replaces image URLs with next-generation formats.
		 *
		 * @since 1.0.0
		 *
		 * @param string  $img_url        The image URL.
		 * @param array   $exclude_imgs   Images to exclude.
		 * @param boolean $supports_avif  Whether AVIF is supported.
		 * @param boolean $supports_webp  Whether WebP is supported.
		 *
		 * @return string Updated image URL.
		 */
		private function replace_image_with_next_gen( $img_url, $exclude_imgs, $supports_avif, $supports_webp ) {
			$img_extension = pathinfo( $img_url, PATHINFO_EXTENSION );

			$img_converter     = $this->get_img_converter();
			$conversion_format = $img_converter->get_format();
			if ( 'avif' === $img_extension ) {
				return $img_url;
			}

			if ( ! empty( $exclude_imgs ) ) {
				foreach ( $exclude_imgs as $exclude_img ) {
					if ( false !== strpos( $img_url, $exclude_img ) ) {
						return $img_url;
					}
				}
			}

			$avif_img_path = $img_converter->get_img_path( $img_url, 'avif' );
			$webp_img_path = $img_converter->get_img_path( $img_url, 'webp' );

			// Cache local path lookups to avoid redundant expensive file system checks.
			$source_image_path = null;

			if ( 'avif' === $conversion_format || 'both' === $conversion_format ) {
				// Convert to AVIF if supported and not already converted.
				if ( ! $this->cached_file_exists( $avif_img_path ) ) {
					$source_image_path = Util::get_local_path( $img_url );

					if ( $this->cached_file_exists( $source_image_path ) ) {
						$img_converter->add_img_into_queue( $source_image_path, 'avif' );
					}
				}
			}

			if ( 'webp' === $conversion_format || 'both' === $conversion_format ) {
				// Convert to WebP if supported and not already converted.
				if ( ! $this->cached_file_exists( $webp_img_path ) ) {
					if ( null === $source_image_path ) {
						$source_image_path = Util::get_local_path( $img_url );
					}

					if ( $this->cached_file_exists( $source_image_path ) ) {
						$img_converter->add_img_into_queue( $source_image_path );
					}
				}
			}

			if ( ( 'avif' === $conversion_format || 'both' === $conversion_format ) && $supports_avif && $this->cached_file_exists( $avif_img_path ) ) {
				return $img_converter->get_img_url( $img_url, 'avif' );
			}

			if ( ( 'webp' === $conversion_format || 'both' === $conversion_format ) && $supports_webp && $this->cached_file_exists( $webp_img_path ) ) {
				return $img_converter->get_img_url( $img_url );
			}

			// Fallback to original image URL.
			return $img_url;
		}

		/**
		 * Determine whether a string is a syntactically valid URL.
		 *
		 * @param string $url The URL to validate.
		 * @return bool `true` if the URL is a valid URL string, `false` otherwise.
		 */
		private function is_valid_url( $url ) {
			return false !== filter_var( $url, FILTER_VALIDATE_URL );
		}

		/**
		 * Convert various URL forms into an absolute URL.
		 *
		 * Leaves empty strings and `data:` URLs unchanged. Handles protocol-relative (`//...`), root-relative (`/...`) and relative paths (e.g., `images/foo.jpg`, `../img.jpg`) by resolving them against the site's home URL and the current request path. Returns the original value unchanged when it is already an absolute `http...` URL.
		 *
		 * @since 1.4.0
		 * @param string $url The input URL to normalize.
		 * @return string The normalized absolute URL, or the original value for empty/data URLs.
		 */
		private function normalize_url( string $url ): string {
			if ( empty( $url ) || 0 === strpos( $url, 'data:' ) ) {
				return $url;
			}

			// Cache home_url() once per blog for the request.
			$home_base = Util::cached_home_url();

			// Protocol-relative URLs (e.g., //example.com/image.jpg).
			if ( 0 === strpos( $url, '//' ) ) {
				static $scheme = array();
				$blog_id       = get_current_blog_id();

				if ( ! isset( $scheme[ $blog_id ] ) ) {
					$scheme[ $blog_id ] = wp_parse_url( $home_base, PHP_URL_SCHEME );
					if ( empty( $scheme[ $blog_id ] ) ) {
						$scheme[ $blog_id ] = is_ssl() ? 'https' : 'http';
					}
				}
				return $scheme[ $blog_id ] . ':' . $url;
			}

			// Root-relative paths (e.g., /wp-content/uploads/image.jpg).
			if ( 0 === strpos( $url, '/' ) ) {
				return $home_base . '/' . ltrim( $url, '/' );
			}

			// True relative paths (e.g., images/photo.jpg or ../uploads/img.jpg).
			if ( 0 !== strpos( $url, 'http' ) ) {
				// Get the current URL path to resolve relative paths like ../.
				static $current_url_path = array();
				$blog_id                 = get_current_blog_id();

				if ( ! isset( $current_url_path[ $blog_id ] ) ) {
					$current_url_path[ $blog_id ] = wp_parse_url( add_query_arg( array() ), PHP_URL_PATH );
					if ( empty( $current_url_path[ $blog_id ] ) ) {
						$current_url_path[ $blog_id ] = '/';
					}
				}
				$absolute_path = $this->resolve_relative_path( $current_url_path[ $blog_id ], $url );
				return $home_base . '/' . ltrim( $absolute_path, '/' );
			}

			return $url;
		}

		/**
		 * Resolve a relative path against a base path and return an absolute path starting with '/'.
		 *
		 * The function treats $base_path as a file (removing its final segment) when it has no
		 * trailing slash and the last segment contains a dot. It preserves an absolute input
		 * $relative_path (one that starts with '/') and resolves '.' and '..' segments.
		 *
		 * @since 1.4.0
		 * @param string $base_path Base path to resolve against; may represent a directory (trailing slash) or a file.
		 * @param string $relative_path Relative path to resolve; if it starts with '/' it will be returned unchanged.
		 * @return string The resolved absolute path beginning with '/'.
		 */
		private function resolve_relative_path( string $base_path, string $relative_path ): string {
			if ( 0 === strpos( $relative_path, '/' ) ) {
				return $relative_path;
			}

			$has_trailing_slash = '/' === substr( $base_path, -1 );
			$base_parts         = array_filter(
				explode( '/', $base_path ),
				function ( $val ): bool {
					return is_string( $val ) && '' !== $val;
				}
			);
			$relative_parts     = explode( '/', $relative_path );

			// If the base path is a file (no trailing slash), remove the filename.
			if ( ! $has_trailing_slash && ! empty( $base_parts ) && false !== strpos( end( $base_parts ), '.' ) ) {
				array_pop( $base_parts );
			}

			foreach ( $relative_parts as $part ) {
				if ( '.' === $part || '' === $part ) {
					continue;
				}
				if ( '..' === $part ) {
					array_pop( $base_parts );
				} else {
					$base_parts[] = $part;
				}
			}

			return '/' . implode( '/', $base_parts );
		}

		/**
		 * Retrieves all preloading data from front-page, post meta, and post types.
		 *
		 * @since 1.5.1
		 * @return array List of preload data items.
		 */
		private function get_all_preload_data(): array {
			$image_optimisation = $this->options['image_optimisation'] ?? array();

			$manual = array_merge(
				$this->get_manual_lcp_preload_data(),
				$this->get_front_page_preload_data( $image_optimisation ),
				$this->get_meta_preload_data(),
				$this->get_post_type_preload_data( $image_optimisation )
			);
			$auto   = $this->get_auto_lcp_preload_data();

			// Deduplicate by normalized URL + query + media (issue #935): the
			// field-measured LCP URL may equal a manually configured preload
			// as an absolute URL vs a relative URL (or http vs https), but
			// exactly one link tag must be emitted per resource (query-string
			// versions still count as distinct resources). Manual items are
			// ordered first so they win the dedup. MAX_LCP_PRELOADS is
			// intentionally an auto-tail-only cap (issue #1216): manual/meta
			// preloads are explicit opt-in and are never dropped, while the
			// auto srcset expansion is bounded so one hero cannot fan out to N
			// high-priority hints and contend with the hero fetch.
			$seen   = array();
			$unique = array();
			foreach ( $manual as $item ) {
				if ( ! is_array( $item ) || empty( $item['url'] ) ) {
					continue;
				}
				$key = $this->get_preload_dedup_key( (string) $item['url'], (string) ( $item['media'] ?? '' ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$unique[]     = $item;
			}

			$auto_unique = array();
			foreach ( $auto as $item ) {
				if ( ! is_array( $item ) || empty( $item['url'] ) ) {
					continue;
				}
				$key = $this->get_preload_dedup_key( (string) $item['url'], (string) ( $item['media'] ?? '' ) );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ]  = true;
				$auto_unique[] = $item;
			}

			if ( count( $auto_unique ) > self::MAX_LCP_PRELOADS ) {
				$auto_unique = array_slice( $auto_unique, 0, self::MAX_LCP_PRELOADS );
			}

			return array_merge( $unique, $auto_unique );
		}

		/**
		 * Whether a candidate URL is a plausible LCP image (text-LCP guard).
		 *
		 * Text-only LCP (PageSpeed `largest-contentful-paint-element` without
		 * an image URL) must never produce a preload hint, so candidates are
		 * rejected unless they look like an image: data/blob/javascript URIs
		 * are refused, and the URL must either map to a known image MIME
		 * type, carry an image file extension, or (for extensionless image
		 * CDN URLs) carry image-ish query params. A non-image URL is never
		 * preloaded. Any failure returns false.
		 *
		 * @since NEXT
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded as an image.
		 */
		private function is_image_lcp_url( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				$lower = strtolower( ltrim( $url ) );
				if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) ) {
					return false;
				}
				if ( '' !== Util::get_image_mime_type( $url ) ) {
					return true;
				}
				$path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback only when wp_parse_url() is unavailable (unit contexts).
				if ( is_string( $path ) && '' !== $path && 1 === preg_match( '/\.(jpe?g|png|gif|webp|avif|svg|heic|heif|jxl)$/i', $path ) ) {
					return true;
				}
				// Extensionless image-CDN URLs (Cloudinary fetch, Photon,
				// signed asset URLs): accept when the query carries image-ish
				// params or an image extension so measured OD/PageSpeed heroes
				// are not silently discarded. Generic keys (ssl, url, src,
				// strip) also appear on non-image URLs and must not qualify.
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
		 * Read the manual per-post LCP URL picker value (`_wppo_lcp_preload_url`).
		 *
		 * The manual picker is the fallback path: it wins over auto-detect
		 * (RUM / Optimization Detective / PageSpeed / heuristic) so site owners
		 * can pin the hero before field data exists. Returns an empty string
		 * when not on a singular view, when the meta is absent, or when the
		 * value fails the `is_image_lcp_url()` guard. Fail-open: any failure
		 * returns an empty string, never fatal.
		 *
		 * @since NEXT
		 * @return string The manual LCP image URL, or empty string.
		 */
		private function get_manual_lcp_url(): string {
			try {
				if ( ! function_exists( 'is_singular' ) || ! function_exists( 'get_the_ID' ) || ! function_exists( 'get_post_meta' ) ) {
					return '';
				}
				if ( ! is_singular() ) {
					return '';
				}
				$post_id = (int) get_the_ID();
				if ( null !== $this->manual_lcp_url && $this->manual_lcp_url_key === $post_id ) {
					return $this->manual_lcp_url;
				}
				if ( empty( $post_id ) ) {
					$this->manual_lcp_url     = '';
					$this->manual_lcp_url_key = $post_id;
					return '';
				}
				$raw = get_post_meta( $post_id, '_wppo_lcp_preload_url', true );
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					$this->manual_lcp_url     = '';
					$this->manual_lcp_url_key = $post_id;
					return '';
				}
				$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( substr( $raw, 0, 2048 ) ) ) : trim( substr( $raw, 0, 2048 ) );
				if ( '' === $url || ! $this->is_image_lcp_url( $url ) ) {
					$this->manual_lcp_url     = '';
					$this->manual_lcp_url_key = $post_id;
					return '';
				}
				$this->manual_lcp_url     = $url;
				$this->manual_lcp_url_key = $post_id;
				return $url;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether a preload candidate URL is same-origin with this site.
		 *
		 * Emission-path guard (issue #1180): stored PageSpeed values, OD
		 * real-visit data, and the DOM-first heuristic flow into the single
		 * `<link rel="preload" as="image" fetchpriority="high">` unchecked
		 * today — only the RUM beacon intake validates origin. Absolute URLs
		 * are validated via `RUM::is_same_origin_url()`; root-relative and bare
		 * relative paths resolve against the home URL and are same-origin by
		 * construction, except scheme-like values (`data:`, `blob:`,
		 * `javascript:`, `mailto:`, …) which are rejected. Fail-closed for the
		 * page: any failure (including an unavailable RUM class, consistent
		 * with the catch block below — `resolve_auto_lcp_url()` already
		 * fails open by falling through to the next tier) returns false
		 * (candidate skipped), never fatal.
		 *
		 * @since NEXT
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded.
		 */
		private function is_same_origin_preload_url( string $url ): bool {
			try {
				$url = trim( $url );
				if ( '' === $url ) {
					return false;
				}
				$lower = strtolower( ltrim( $url ) );
				if ( str_starts_with( $lower, 'data:' ) || str_starts_with( $lower, 'blob:' ) || str_starts_with( $lower, 'javascript:' ) || str_starts_with( $lower, 'vbscript:' ) ) {
					return false;
				}
				// Root-relative paths are same-origin by construction.
				if ( 0 === strpos( $url, '/' ) && 0 !== strpos( $url, '//' ) ) {
					return true;
				}
				// Bare relative paths (no scheme, no leading slash) resolve
				// against the home URL — same-origin — unless scheme-like.
				if ( false === strpos( $url, '://' ) && 0 !== strpos( $url, '//' ) ) {
					$before_slash = strtok( $url, '/\\?#' );
					if ( is_string( $before_slash ) && false !== strpos( $before_slash, ':' ) ) {
						return false;
					}
					return true;
				}
				// RUM unavailable: fail closed (reject the absolute URL) so a
				// possibly cross-origin candidate is never preloaded. The
				// caller (`resolve_auto_lcp_url()`) falls through to the next
				// tier, preserving fail-open page behaviour.
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url_strict' ) ) {
					return \PerformanceOptimise\Inc\RUM::is_same_origin_url_strict( $url );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'is_same_origin_url' ) ) {
					return \PerformanceOptimise\Inc\RUM::is_same_origin_url( $url );
				}
				// RUM unavailable: fail closed (reject the absolute URL) so a
				// possibly cross-origin candidate is never preloaded. The
				// caller (`resolve_auto_lcp_url()`) falls through to the next
				// tier, preserving fail-open page behaviour.
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether core's loading-optimization API is available.
		 *
		 * Explicit guard (issue #1180) for the decoding gap-fill: core is
		 * consulted for gap-fill input (`decoding`) only, while the
		 * measured hero keeps `fetchpriority="high"` (field truth beats
		 * the core heuristic) and an already-stamped fetchpriority is
		 * never overridden. Requires
		 * `wp_get_loading_optimization_attributes()` plus WordPress 6.2+
		 * (the HTML API era); every call is guarded with `function_exists()`
		 * and `version_compare()` with a legacy fallback to the unmodified
		 * gap-fill behaviour. Fail-open: any failure returns false.
		 *
		 * @since NEXT
		 * @return bool True when core may be consulted for a node verdict.
		 */
		private function is_core_loading_optimization_available(): bool {
			try {
				if ( ! function_exists( 'wp_get_loading_optimization_attributes' ) ) {
					return false;
				}
				$wp_version = '';
				if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
					$wp_version = $GLOBALS['wp_version'];
				} elseif ( function_exists( 'get_bloginfo' ) ) {
					$wp_version = (string) get_bloginfo( 'version' );
				}
				if ( '' === $wp_version ) {
					// Function presence alone implies availability when the
					// version string is unreachable (e.g. unit contexts).
					return true;
				}
				return version_compare( $wp_version, '6.2', '>=' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Ask core for its loading-optimization verdict on the current tag.
		 *
		 * Gap-fill companion to `is_core_loading_optimization_available()`:
		 * builds the tag-attribute array core expects and returns the
		 * `wp_loading_optimization_attributes` filter verdict
		 * (`decoding` when offered), or null when core is
		 * unavailable, throws, or offers no verdict. The filter — not
		 * `wp_get_loading_optimization_attributes()` — is consulted on
		 * purpose: the latter runs core's stateful per-context image
		 * counter, so a second direct call from the buffer path would
		 * double-count this image and skew core's later lazy/eager
		 * decisions (and core never returns `high` for our synthetic
		 * context anyway). The filter lets hooked optimizers weigh in
		 * without touching the counter; the already-stamped
		 * `fetchpriority` attribute check in callers remains the
		 * authoritative no-double-stamp guard. Callers always stamp the
		 * measured hero `high` (field truth beats the heuristic), while
		 * `decoding` defers to the verdict whenever one is offered.
		 * `fetchpriority` is intentionally not collected: no caller reads
		 * it (the stamp is unconditional when absent), so returning it
		 * would be dead data inviting future misuse.
		 * Fail-open: any failure returns null.
		 *
		 * @since NEXT
		 * @param mixed $tags Tag processor positioned on an `<img>` node.
		 * @return array{decoding?:string}|null Core's verdict, or null.
		 */
		private function get_core_loading_verdict_for_tag( $tags ): ?array {
			if ( ! $this->is_core_loading_optimization_available() ) {
				return null;
			}
			if ( ! function_exists( 'apply_filters' ) ) {
				return null;
			}
			try {
				if ( ! is_object( $tags ) || ! method_exists( $tags, 'get_attribute' ) ) {
					return null;
				}
				$tag_attr = array();
				foreach ( array( 'src', 'width', 'height', 'loading', 'decoding', 'fetchpriority' ) as $attr ) {
					$value = $tags->get_attribute( $attr );
					if ( is_string( $value ) && '' !== $value ) {
						$tag_attr[ $attr ] = ( 'width' === $attr || 'height' === $attr ) && is_numeric( $value ) ? (int) $value : $value;
					}
				}
				$loading_attrs = apply_filters( 'wp_loading_optimization_attributes', array(), 'img', $tag_attr, 'performance_optimisation_lcp' );
				if ( ! is_array( $loading_attrs ) ) {
					return null;
				}
				$verdict = array();
				if ( isset( $loading_attrs['decoding'] ) && is_string( $loading_attrs['decoding'] ) ) {
					$candidate = strtolower( trim( $loading_attrs['decoding'] ) );
					if ( in_array( $candidate, array( 'async', 'sync', 'auto' ), true ) ) {
						$verdict['decoding'] = $candidate;
					}
				}
				return ( array() === $verdict ) ? null : $verdict;
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return null;
		}

		/**
		 * Whether automatic (signal-driven) LCP preload is disabled for the current post.
		 *
		 * Reads the per-post `_wppo_disable_auto_lcp` meta (see Metabox).
		 * The manual picker (`_wppo_lcp_preload_url`) is explicit opt-in and
		 * is unaffected — only the RUM/OD signal tiers are suppressed. Off
		 * (empty meta) by default so existing behaviour is unchanged.
		 * Fail-open: any failure returns false (auto-LCP stays enabled).
		 *
		 * @since NEXT
		 * @return bool True when auto-LCP must be skipped for this post.
		 */
		private function is_auto_lcp_disabled_for_post(): bool {
			try {
				if ( ! function_exists( 'is_singular' ) || ! function_exists( 'get_the_ID' ) || ! function_exists( 'get_post_meta' ) ) {
					return false;
				}
				if ( ! is_singular() ) {
					return false;
				}
				$post_id = (int) get_the_ID();
				if ( null !== $this->auto_lcp_disabled && $this->auto_lcp_disabled_key === $post_id ) {
					return $this->auto_lcp_disabled;
				}
				if ( empty( $post_id ) ) {
					$this->auto_lcp_disabled     = false;
					$this->auto_lcp_disabled_key = $post_id;
					return false;
				}
				$disabled                    = ! empty( get_post_meta( $post_id, '_wppo_disable_auto_lcp', true ) );
				$this->auto_lcp_disabled     = $disabled;
				$this->auto_lcp_disabled_key = $post_id;
				return $disabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve the stable signal-only LCP image URL for the current page.
		 *
		 * Signal-driven subset of `resolve_auto_lcp_url()` (issue #1273):
		 * the RUM field candidate (stable by construction — sample-count
		 * gate via `get_field_lcp_min_samples()`, 24 h freshness TTL, and
		 * same-origin re-check inside `RUM::get_field_lcp_url()`) wins
		 * first, then the stability-gated OD real-visit candidate
		 * (`OD_Bridge::get_stable_lcp_url()` — at least two agreeing
		 * viewport observations, or a single measured group). Manual picker,
		 * stored PageSpeed, and DOM-heuristic tiers are deliberately
		 * excluded here. Every candidate must pass `is_image_lcp_url()` +
		 * `is_allowed_hero_preload_url()` (same-origin or configured CDN).
		 * Returns '' when the per-post
		 * disable meta is set, when no stable signal exists, or on any
		 * failure (fail-open to no-preload, never broken markup).
		 *
		 * @since NEXT
		 * @return string The stable signal LCP image URL, or empty string.
		 */
		private function get_stable_signal_lcp_url(): string {
			$memo_key = $this->get_lcp_memo_key();
			if ( null !== $this->stable_signal_lcp_url && $this->stable_signal_lcp_url_key === $memo_key ) {
				return $this->stable_signal_lcp_url;
			}
			$resolved = '';
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					$this->stable_signal_lcp_url     = '';
					$this->stable_signal_lcp_url_key = $memo_key;
					return '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_url' ) ) {
					// Same explicit path derivation as get_current_lcp_url()
					// so both tiers bucket identically behind
					// proxies/subdirectories.
					$parsed_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( Util::get_current_url(), PHP_URL_PATH ) : '/';
					$raw_path    = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : '/';
					$field_path  = Util::normalize_rum_path( $raw_path );
					$field       = \PerformanceOptimise\Inc\RUM::get_field_lcp_url( $field_path );
					if ( is_array( $field ) && ! empty( $field['url'] ) && is_string( $field['url'] ) ) {
						$candidate = trim( $field['url'] );
						if ( '' !== $candidate && $this->is_image_lcp_url( $candidate ) && $this->is_allowed_hero_preload_url( $candidate ) ) {
							$resolved = $candidate;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( '' === $resolved && class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) ) {
				try {
					$od_available = class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
					if ( $od_available ) {
						$od_url = '';
						if ( method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_stable_lcp_url' ) ) {
							$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_stable_lcp_url();
						} elseif ( method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_lcp_url' ) ) {
							$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_lcp_url();
						}
						if ( is_string( $od_url ) && '' !== $od_url && $this->is_image_lcp_url( $od_url ) && $this->is_allowed_hero_preload_url( $od_url ) ) {
							$resolved = $od_url;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$this->stable_signal_lcp_url     = $resolved;
			$this->stable_signal_lcp_url_key = $memo_key;
			return $resolved;
		}

		/**
		 * Resolve the OD-only LCP image URL (manual picker + OD real-visit data).
		 *
		 * Subset of `resolve_auto_lcp_url()` needing no RUM state (issue
		 * #1216): the manual picker and the Optimization Detective tiers are
		 * guarded (image + allowed origin: same-origin or configured CDN)
		 * and fire no RUM lookups, so they stay
		 * available when the RUM gate is unsatisfied. The OD tier relies on
		 * the stability-gated `OD_Bridge::get_stable_lcp_url()` (issue
		 * #1273 — at least two agreeing viewport observations, or a single
		 * measured group; '' on viewport disagreement so a disagreeing
		 * mobile/desktop pair never preloads the wrong hero), falling back
		 * to `OD_Bridge::get_lcp_url()` only when the stable accessor is
		 * unavailable. Gating lives in `OD_Bridge::is_enabled()` — the
		 * single firing of the `wppo_od_should_optimize` filter
		 * (current-URL context, memoized per request) — so no separate
		 * filter pre-check exists here. The per-post
		 * `_wppo_disable_auto_lcp` meta suppresses the OD tier; the manual
		 * picker is explicit opt-in and still applies. Fail-open: any
		 * failure returns ''.
		 *
		 * @since NEXT
		 * @return string The OD-only LCP image URL, or empty string.
		 */
		private function resolve_od_only_lcp_url(): string {
			try {
				$manual = $this->get_manual_lcp_url();
				if ( '' !== $manual && $this->is_image_lcp_url( $manual ) && $this->is_allowed_hero_preload_url( $manual ) ) {
					return $manual;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					return '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) ) {
				try {
					$od_available = class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
					if ( $od_available ) {
						$od_url = '';
						if ( method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_stable_lcp_url' ) ) {
							$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_stable_lcp_url();
						} elseif ( method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_lcp_url' ) ) {
							$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_lcp_url();
						}
						if ( is_string( $od_url ) && '' !== $od_url && $this->is_image_lcp_url( $od_url ) && $this->is_allowed_hero_preload_url( $od_url ) ) {
							return $od_url;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return '';
		}

		/**
		 * Resolve the single auto-detected LCP image URL for the current page.
		 *
		 * Unified priority chain (fail-open, never fatal):
		 * Manual per-post picker (`_wppo_lcp_preload_url`) wins first, then
		 * P0 stability-gated Optimization Detective real-visit data (shared
		 * `resolve_od_only_lcp_url()` helper — `get_stable_lcp_url()` so a
		 * disagreeing mobile/desktop pair yields '' instead of the wrong
		 * hero; the `wppo_od_should_optimize` filter fires inside
		 * `OD_Bridge::get_stable_lcp_url()` via `is_enabled()` with
		 * current-URL context, memoized per request), then stored PageSpeed
		 * LCP (via `get_current_lcp_url()`, which covers RUM-field override +
		 * post-meta/front-page/transient tiers), then the stability-gated
		 * signal-only tier (`get_stable_signal_lcp_url()` — RUM field +
		 * agreement-gated OD, issue #1273), then the DOM-first heuristic
		 * (first non-trivial `<img src>` in `$buffer` when provided — DOM
		 * order, not viewport-aware, buffer-only, and only a fallback when no
		 * measured/stored data exists). The per-post
		 * `_wppo_disable_auto_lcp` meta suppresses every automatic tier
		 * (P0 OD, P1 stored, P1b signal, P2 heuristic) but never the
		 * manual picker, which is explicit opt-in. Text-only LCP never resolves: every
		 * candidate must pass `is_image_lcp_url()`. Untrusted-origin candidates never resolve either:
		 * every tier must pass `is_allowed_hero_preload_url()` (same-origin
		 * or configured CDN) so at most one allowed-origin
		 * `<link rel="preload" as="image" fetchpriority="high">`
		 * is ever emitted. Multisite-safe: the
		 * stored tier uses `Util::transient_key()` blog-aware keys.
		 *
		 * @since NEXT
		 * @param string|null $buffer Optional HTML buffer for the heuristic fallback.
		 * @return string The LCP image URL, or empty string when none resolves.
		 */
		private function resolve_auto_lcp_url( ?string $buffer = null ): string {
			// Manual picker + P0 stability-gated Optimization Detective via
			// the shared OD-only helper (issues #1216, #1273) so the
			// emission, lazy-exclusion, and RUM-unsatisfied fallback paths
			// cannot drift apart. The OD tier fires the
			// `wppo_od_should_optimize` filter inside
			// `OD_Bridge::get_stable_lcp_url()` via `is_enabled()`
			// (current-URL context, memoized per request); no separate
			// pre-check exists here.
			try {
				$od_only = $this->resolve_od_only_lcp_url();
				if ( '' !== $od_only ) {
					return $od_only;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Per-post kill switch (issue #1273): the manual picker above
			// already returned when pinned, so everything below is
			// automatic and stays suppressed on disabled posts. Gating
			// here (not just in get_auto_lcp_preload_data() /
			// get_lazy_lcp_exclusion_url()) keeps the buffer/filter
			// callers (prioritize_buffer(), prioritize_lcp_image(),
			// maybe_preload_hero_image(), maybe_inject_css_hero_preload(),
			// resolve_fetchpriority_lcp_url()) consistent.
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					return '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// P1: Stored PageSpeed (+ RUM-field override) chain.
			try {
				$stored = $this->get_current_lcp_url();
				if ( is_string( $stored ) && '' !== $stored && $this->is_image_lcp_url( $stored ) && $this->is_allowed_hero_preload_url( $stored ) ) {
					return $stored;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// P1b: Stable signal-only tier (issue #1273) — RUM field +
			// stability-gated OD, without the manual/stored/heuristic tiers.
			// This is the internal caller that makes the signal path
			// automatic: `get_auto_lcp_preload_data()`, the buffer hero
			// path, and the lazy-exclusion path all resolve through here.
			// Already validated + per-post-disable gated inside.
			try {
				$signal = $this->get_stable_signal_lcp_url();
				if ( is_string( $signal ) && '' !== $signal ) {
					return $signal;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// P2: DOM-first heuristic — first non-trivial image in the buffer.
			if ( is_string( $buffer ) && '' !== $buffer && false !== strpos( $buffer, '<img' ) ) {
				try {
					$heuristic = $this->get_heuristic_lcp_url( $buffer );
					if ( '' !== $heuristic && $this->is_image_lcp_url( $heuristic ) && $this->is_allowed_hero_preload_url( $heuristic ) ) {
						return $heuristic;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			return '';
		}

		/**
		 * Resolve responsive srcset/sizes for an LCP URL via the media library.
		 *
		 * Attachment-based lookup for the `wp_head` emission paths (which run
		 * before any HTML buffer exists, so the buffer scanners cannot help):
		 * maps the LCP URL to an attachment via `attachment_url_to_postid()`
		 * and fetches `wp_get_attachment_image_srcset()` /
		 * `wp_get_attachment_image_sizes()` (`full` size). Returns an empty
		 * pair when the URL is not an attachment image, when the WP helpers
		 * are unavailable, or on any failure (fail-open: callers fall back to
		 * a plain `href` preload). Callers must never emit srcset without
		 * sizes: when either value is empty both are treated as empty.
		 *
		 * @since NEXT
		 * @param string $lcp_url The resolved LCP image URL.
		 * @return array{srcset: string, sizes: string} Responsive data (empty strings when unavailable).
		 */
		public static function get_lcp_responsive_data_for_url( string $lcp_url ): array {
			$empty = array(
				'srcset' => '',
				'sizes'  => '',
			);
			try {
				$lcp_url = trim( $lcp_url );
				if ( '' === $lcp_url ) {
					return $empty;
				}
				$lower = strtolower( ltrim( $lcp_url ) );
				if ( 0 === strpos( $lower, 'data:' ) || 0 === strpos( $lower, 'blob:' ) || 0 === strpos( $lower, 'javascript:' ) || 0 === strpos( $lower, 'vbscript:' ) ) {
					return $empty;
				}
				if ( ! function_exists( 'attachment_url_to_postid' ) || ! function_exists( 'wp_get_attachment_image_srcset' ) || ! function_exists( 'wp_get_attachment_image_sizes' ) ) {
					return $empty;
				}
				$attachment_id = (int) attachment_url_to_postid( $lcp_url );
				if ( $attachment_id <= 0 ) {
					return $empty;
				}
				$srcset = wp_get_attachment_image_srcset( $attachment_id, 'full' );
				$sizes  = wp_get_attachment_image_sizes( $attachment_id, 'full' );
				if ( ! is_string( $srcset ) || ! is_string( $sizes ) ) {
					return $empty;
				}
				$srcset = trim( substr( $srcset, 0, 4096 ) );
				$sizes  = trim( substr( $sizes, 0, 1024 ) );
				if ( '' === $srcset || '' === $sizes ) {
					return $empty;
				}
				return array(
					'srcset' => $srcset,
					'sizes'  => $sizes,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $empty;
			}
		}

		/**
		 * Find the responsive srcset for an LCP URL inside an HTML buffer.
		 *
		 * Scans `<img>` tags for the first node whose `src`/`data-src`
		 * matches the LCP URL via normalized-URL equality only (absolute vs
		 * relative and size-suffix variants match; no substring fallback so
		 * a short relative URL cannot attach an unrelated srcset) and
		 * returns its `srcset` (or `data-srcset`) value. Returns an empty
		 * string when no match or no srcset exists. Fail-open: any failure
		 * returns ''.
		 *
		 * @since NEXT
		 * @param string      $lcp_url The resolved LCP image URL.
		 * @param string|null $buffer  Optional HTML buffer to scan.
		 * @return string The srcset value, or empty string.
		 */
		private function get_lcp_srcset_for_url( string $lcp_url, ?string $buffer = null ): string {
			if ( '' === $lcp_url || ! is_string( $buffer ) || '' === $buffer || false === strpos( $buffer, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return '';
			}
			try {
				$needle = $this->normalize_image_url( $lcp_url );
				if ( '' === $needle ) {
					return '';
				}
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$src      = $tags->get_attribute( 'src' );
					$data_src = $tags->get_attribute( 'data-src' );
					$match    = ( is_string( $src ) && '' !== $src ) ? $src : ( is_string( $data_src ) ? $data_src : '' );
					if ( '' === $match ) {
						continue;
					}
					if ( $this->normalize_image_url( (string) $match ) !== $needle ) {
						continue;
					}
					$srcset = $tags->get_attribute( 'srcset' );
					if ( is_string( $srcset ) && '' !== trim( $srcset ) ) {
						return trim( substr( $srcset, 0, 4096 ) );
					}
					$data_srcset = $tags->get_attribute( 'data-srcset' );
					if ( is_string( $data_srcset ) && '' !== trim( $data_srcset ) ) {
						return trim( substr( $data_srcset, 0, 4096 ) );
					}
					return '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * Find the responsive sizes value for an LCP URL inside an HTML buffer.
		 *
		 * Mirrors `get_lcp_srcset_for_url()`: normalized-URL equality only,
		 * `sizes` (then `data-sizes`) of the matching `<img>`. Returns an
		 * empty string when no match or no sizes exists. Fail-open: any
		 * failure returns ''.
		 *
		 * @since NEXT
		 * @param string      $lcp_url The resolved LCP image URL.
		 * @param string|null $buffer  Optional HTML buffer to scan.
		 * @return string The sizes value, or empty string.
		 */
		private function get_lcp_sizes_for_url( string $lcp_url, ?string $buffer = null ): string {
			if ( '' === $lcp_url || ! is_string( $buffer ) || '' === $buffer || false === strpos( $buffer, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return '';
			}
			try {
				$needle = $this->normalize_image_url( $lcp_url );
				if ( '' === $needle ) {
					return '';
				}
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$src      = $tags->get_attribute( 'src' );
					$data_src = $tags->get_attribute( 'data-src' );
					$match    = ( is_string( $src ) && '' !== $src ) ? $src : ( is_string( $data_src ) ? $data_src : '' );
					if ( '' === $match ) {
						continue;
					}
					if ( $this->normalize_image_url( (string) $match ) !== $needle ) {
						continue;
					}
					$sizes = $tags->get_attribute( 'sizes' );
					if ( is_string( $sizes ) && '' !== trim( $sizes ) ) {
						return trim( substr( $sizes, 0, 1024 ) );
					}
					$data_sizes = $tags->get_attribute( 'data-sizes' );
					if ( is_string( $data_sizes ) && '' !== trim( $data_sizes ) ) {
						return trim( substr( $data_sizes, 0, 1024 ) );
					}
					return '';
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * Retrieves the manual per-post LCP image preload item.
		 *
		 * Emits the `_wppo_lcp_preload_url` picker value via
		 * `prepare_preload_item()` so a pinned hero preloads with
		 * fetchpriority high even before auto-detect (RUM / OD / PageSpeed)
		 * has data — and even when the `autoPreloadLCP` toggle is off,
		 * because pinning the URL is explicit opt-in. Ordered first in
		 * `get_all_preload_data()` so it wins the normalized-URL dedup.
		 * Fail-open: any failure returns an empty list.
		 *
		 * @since NEXT
		 * @return array List of preload items (zero or one item).
		 */
		private function get_manual_lcp_preload_data(): array {
			try {
				$manual = $this->get_manual_lcp_url();
				if ( '' === $manual ) {
					return array();
				}
				$responsive = self::get_lcp_responsive_data_for_url( $manual );
				return array( $this->prepare_preload_item( $manual, $responsive['srcset'], $responsive['sizes'] ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Retrieves the single auto-detected LCP image preload item.
		 *
		 * Resolves via the unified `resolve_auto_lcp_url()` chain
		 * (manual picker → P0 Optimization Detective real-visit data → P1
		 * stored PageSpeed/RUM-field → P2 DOM-first heuristic when a buffer is
		 * available). Emits at most one item via `prepare_preload_item()`
		 * so "once per URL" holds; the item is ordered ahead of front-page
		 * and generic meta preloads (after the manual picker item) and
		 * participates in the normalized-URL dedup. The legacy
		 * `image_optimisation.autoPreloadLCP` toggle enables the legacy
		 * path unchanged; the additive `preload_settings.autoLcpPreload`
		 * toggle (issue #1216) enables the same chain but stays off until
		 * RUM-gated (`RUM::is_enabled()`, guarded) with an off switch.
		 *
		 * RUM gates only the RUM-dependent tiers (issue #1216): with the new
		 * toggle on but RUM unsatisfied, the OD-only subset (manual + OD,
		 * needing no RUM state) still resolves instead of dropping OD
		 * optimisation silently. The P2 heuristic is buffer-only by design —
		 * this `wp_head` path passes no buffer, so heuristic heroes never
		 * preload here; the buffer path (`maybe_preload_hero_image()`)
		 * emits the companion preload link in the same pass it marks the
		 * hero eager, keeping exclusion and emission consistent.
		 *
		 * @since 2.0.0
		 * @since NEXT Resolves via the unified `resolve_auto_lcp_url()` chain
		 * (OD → stored PageSpeed → heuristic) with a text-LCP guard; emits at
		 * most one item. Adds the RUM-gated `preload_settings.autoLcpPreload`
		 * path (off by default, manual lists win, never lazy+high).
		 * @since NEXT RUM gates only the RUM-dependent tiers: with RUM
		 * unsatisfied the OD-only subset still resolves.
		 * @return array List of preload items (zero or one item).
		 */
		private function get_auto_lcp_preload_data(): array {
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					return array();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			$preload_settings   = $this->options['preload_settings'] ?? array();
			$legacy_on          = ! empty( $image_optimisation['autoPreloadLCP'] );
			$new_on             = ! empty( $preload_settings['autoLcpPreload'] );
			if ( ! $legacy_on && ! $new_on ) {
				return array();
			}

			if ( $new_on && ! $legacy_on && ! $this->is_auto_lcp_rum_satisfied() ) {
				$lcp_url = $this->resolve_od_only_lcp_url();
				if ( '' === $lcp_url ) {
					return array();
				}
				$responsive = self::get_lcp_responsive_data_for_url( $lcp_url );
				return array( $this->prepare_preload_item( $lcp_url, $responsive['srcset'], $responsive['sizes'] ) );
			}

			$lcp_url = $this->resolve_auto_lcp_url();
			if ( '' === $lcp_url ) {
				return array();
			}

			$responsive = self::get_lcp_responsive_data_for_url( $lcp_url );
			return array( $this->prepare_preload_item( $lcp_url, $responsive['srcset'], $responsive['sizes'] ) );
		}

		/**
		 * Whether the RUM gate for the additive auto-LCP toggle is satisfied.
		 *
		 * The `preload_settings.autoLcpPreload` path (issue #1216) stays off
		 * until real-user measurement is enabled (`RUM::is_enabled()`,
		 * guarded with class_exists/method_exists). Fail-closed when RUM is
		 * unavailable or disabled so detection failure degrades to the
		 * current manual behavior; fail-open only via the legacy
		 * `autoPreloadLCP` path handled by the caller. Never fatal.
		 *
		 * @since NEXT
		 * @return bool True when RUM gating passes.
		 */
		private function is_auto_lcp_rum_satisfied(): bool {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'is_enabled' ) ) {
					return false;
				}
				return (bool) \PerformanceOptimise\Inc\RUM::is_enabled();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolves the currently-detected LCP image URL for the current page.
		 *
		 * Checks mobile strategy first, then desktop, to support responsive sites
		 * that serve different images per viewport. Data sources (in order):
		 *
		 * 1. Singular post meta (`_wppo_lcp_image_url_{strategy}`).
		 * 2. Front-page option (`wppo_front_page_lcp_{strategy}`).
		 * 3. Transient keyed by strategy + current URL hash (`wppo_lcp_url_{strategy}_{md5}`).
		 *
		 * Tiers 1-3 are read via the shared
		 * `RUM::get_stored_pagespeed_lcp_url()` helper so strategy order and
		 * key formats stay in sync with the preload candidate path.
		 *
		 * When the `fieldLcpOverride` toggle is enabled, field-measured RUM data
		 * (issue #935) is consulted between Optimization Detective and the
		 * PageSpeed chain: the top real-user LCP URL for the current path wins
		 * only after enough samples (default 20) and while fresh (<24h).
		 *
		 * @since 2.0.0
		 * @return string The LCP image URL, or empty string when none is stored.
		 */
		private function get_current_lcp_url(): string {
			$memo_key = $this->get_lcp_memo_key();
			if ( null !== $this->current_lcp_url && $this->current_lcp_url_key === $memo_key ) {
				return $this->current_lcp_url;
			}
			$this->current_lcp_url     = '';
			$this->current_lcp_url_key = $memo_key;
			// Per-post kill switch (issue #1273): the stored chain is
			// automatic detection, so it stays suppressed on disabled
			// posts (the manual picker lives outside this chain).
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					return $this->current_lcp_url;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Priority 0: Optimization Detective — stability-gated LCP
			// (issue #1273: `get_stable_lcp_url()` returns '' on viewport
			// disagreement instead of the wrong hero).
			// The `wppo_od_should_optimize` opt-out (issue #1216) is honoured
			// inside the stable accessor via `is_enabled()` (memoized per
			// request, current-URL context) — so a false filter
			// yields '' here exactly as in `resolve_auto_lcp_url()`; no
			// separate pre-check exists (a second firing with a different
			// context arg would give URL-inspecting filters inconsistent
			// inputs). The stored OD URL is validated like every other
			// emission tier (image + same-origin) so a cross-origin OD value
			// never resolves.
			if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) ) {
				try {
					$od_url = '';
					if ( method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_stable_lcp_url' ) ) {
						$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_stable_lcp_url();
					} elseif ( method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_lcp_url' ) ) {
						$od_url = \PerformanceOptimise\Inc\OD_Bridge::get_lcp_url();
					}
					if ( is_string( $od_url ) && '' !== $od_url && $this->is_image_lcp_url( $od_url ) && $this->is_allowed_hero_preload_url( $od_url ) ) {
						$this->current_lcp_url = $od_url;
						return $this->current_lcp_url;
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO Image optimisation OD error: ' . str_replace( ABSPATH, '', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			}
			// Priority 0b: Field-measured LCP (issue #935) - real-user LCP element
			// URLs collected by the RUM beacon override the PageSpeed heuristic
			// only after enough samples (default 20); a stale override (>24h)
			// self-corrects back to the heuristic inside RUM::get_field_lcp_url().
			if ( ! empty( ( $this->options['image_optimisation'] ?? array() )['fieldLcpOverride'] ) ) {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_url' ) ) {
					try {
						$parsed_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( Util::get_current_url(), PHP_URL_PATH ) : '/';
						$raw_path    = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : '/';
						// Normalized identically to the RUM store side so
						// '/hero-page' matches a stored '/hero-page/' bucket.
						$field_path = Util::normalize_rum_path( $raw_path );
						$field      = \PerformanceOptimise\Inc\RUM::get_field_lcp_url( $field_path );
						if ( is_array( $field ) && ! empty( $field['url'] ) && is_string( $field['url'] ) ) {
							$this->current_lcp_url = $field['url'];
							return $this->current_lcp_url;
						}
					} catch ( \Throwable $e ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO Image optimisation field LCP error: ' . str_replace( ABSPATH, '', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}
				}
			}

			// Priorities 1-3: delegate to the shared read-only PageSpeed
			// lookup so this chain and the preload candidate path cannot
			// drift apart. Fail-open: any failure inside returns ''.
			if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_stored_pagespeed_lcp_url' ) ) {
				try {
					$this->current_lcp_url = \PerformanceOptimise\Inc\RUM::get_stored_pagespeed_lcp_url();
					return $this->current_lcp_url;
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO Image optimisation PageSpeed LCP error: ' . str_replace( ABSPATH, '', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			}

			return $this->current_lcp_url;
		}

		/**
		 * Current-URL key for the per-instance LCP memos (issue #1216).
		 *
		 * Returns `Util::get_current_url()` (fail-open to '' when the URL is
		 * unresolvable, e.g. early boot or bare unit contexts): both
		 * `get_current_lcp_url()` and the null-buffer
		 * `get_lazy_lcp_exclusion_url()` memo compare against this key so a
		 * long-lived instance reused across pages re-resolves per page
		 * instead of serving the first page's hero everywhere. Never fatal.
		 *
		 * @since NEXT
		 * @return string Memo key (possibly empty).
		 */
		private function get_lcp_memo_key(): string {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_current_url' ) ) {
					return Util::get_current_url();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * P2 DOM-first heuristic LCP URL, memoized per buffer hash (issue #1216).
		 *
		 * Wraps `get_first_image_src_in_buffer()` so the full-HTML
		 * `WP_HTML_Tag_Processor` scan runs once per distinct buffer per
		 * request no matter how many callers (preload data, lazy exclusion,
		 * hero inject) resolve the same buffer. Buffer-only by design: the
		 * `wp_head` (null-buffer) path never fires the heuristic, so a hero
		 * that is only heuristically detectable is marked eager in the
		 * buffer path and its companion preload link is emitted by
		 * `maybe_preload_hero_image()` in the same pass — emission and
		 * exclusion stay consistent because both resolve with the buffer.
		 * Bounded (reset past 30 entries); fail-open to ''.
		 *
		 * @since NEXT
		 * @param string $buffer HTML buffer to scan.
		 * @return string Heuristic LCP URL, or empty string.
		 */
		private function get_heuristic_lcp_url( string $buffer ): string {
			try {
				if ( '' === $buffer ) {
					return '';
				}
				$hash = md5( $buffer );
				if ( isset( self::$heuristic_lcp_memo[ $hash ] ) ) {
					return self::$heuristic_lcp_memo[ $hash ];
				}
				$resolved                          = $this->get_first_image_src_in_buffer( $buffer );
				self::$heuristic_lcp_memo[ $hash ] = $resolved;
				if ( count( self::$heuristic_lcp_memo ) > 30 ) {
					self::$heuristic_lcp_memo = array();
				}
				return $resolved;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Resolve the LCP-candidate URL excluded from lazy load (memoized per instance).
		 *
		 * Gated on the LCP toggles so default lazy behaviour is unchanged when
		 * all LCP features are off: the field-measured branch needs
		 * `fieldLcpOverride`, the stored-PageSpeed branch (shared read-only
		 * lookup, no new scans) needs `autoPreloadLCP` or `prioritizeLCP`.
		 * The manual per-post picker (`_wppo_lcp_preload_url`) is exempt from
		 * the gate — pinning the hero is explicit opt-in, so the pinned URL
		 * is always excluded from lazy load with width/height preserved.
		 * Returns an empty string when no branch applies or nothing resolves.
		 * Fail-open: any failure returns an empty string, never fatal.
		 *
		 * @since 2.0.0
		 * @since NEXT Resolves via the unified `resolve_auto_lcp_url()` chain
		 * so the never-lazy URL is always the same URL that gets preloaded.
		 * The optional `$buffer` enables the P2 DOM-first heuristic tier so
		 * `add_delay_load_img()` stays in parity with
		 * `maybe_preload_hero_image()` (which resolves with the buffer);
		 * without a buffer only the manual + OD + stored tiers apply.
		 * @param array       $image_optimisation Image optimisation settings.
		 * @param string|null $buffer Optional HTML buffer for the heuristic tier.
		 * @return string The candidate URL, or empty string when none applies.
		 */
		private function get_lazy_lcp_exclusion_url( array $image_optimisation, ?string $buffer = null ): string {
			$memo_key = null === $buffer ? $this->get_lcp_memo_key() : null;
			if ( null === $buffer && null !== $this->lazy_lcp_exclusion_url && $this->lazy_lcp_exclusion_url_key === $memo_key ) {
				return $this->lazy_lcp_exclusion_url;
			}
			$resolved_url = '';
			try {
				// Manual picker bypasses the toggle gate (explicit opt-in).
				try {
					$manual = $this->get_manual_lcp_url();
				} catch ( \Throwable $e ) {
					unset( $e );
					$manual = '';
				}
				if ( '' !== $manual ) {
					if ( null === $buffer ) {
						$this->lazy_lcp_exclusion_url     = $manual;
						$this->lazy_lcp_exclusion_url_key = $memo_key;
					}
					return $manual;
				}
				try {
					if ( $this->is_auto_lcp_disabled_for_post() ) {
						if ( null === $buffer ) {
							$this->lazy_lcp_exclusion_url     = '';
							$this->lazy_lcp_exclusion_url_key = $memo_key;
						}
						return '';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$auto_lcp_on = ! empty( ( $this->options['preload_settings'] ?? array() )['autoLcpPreload'] ) && $this->is_auto_lcp_rum_satisfied();
				$gated       = ! empty( $image_optimisation['fieldLcpOverride'] ) || ! empty( $image_optimisation['autoPreloadLCP'] ) || ! empty( $image_optimisation['prioritizeLCPImages'] ) || $auto_lcp_on;
				if ( ! $gated ) {
					if ( null === $buffer ) {
						$this->lazy_lcp_exclusion_url     = '';
						$this->lazy_lcp_exclusion_url_key = $memo_key;
					}
					return '';
				}
				$resolved = $this->resolve_auto_lcp_url( $buffer );
				if ( '' !== $resolved ) {
					$resolved_url = $resolved;
					if ( null === $buffer ) {
						$this->lazy_lcp_exclusion_url     = $resolved;
						$this->lazy_lcp_exclusion_url_key = $memo_key;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( null === $buffer && '' === $resolved_url ) {
				$this->lazy_lcp_exclusion_url     = '';
				$this->lazy_lcp_exclusion_url_key = $memo_key;
			}
			return $resolved_url;
		}

		/**
		 * Get the effective excludeFirstImages count, preferring OD measured data.
		 *
		 * When OD is available and enabled, returns the measured count (1-3)
		 * from viewport groups; otherwise returns the stored heuristic. The
		 * `lcp_first_n` setting (default 3) takes precedence over the legacy
		 * `excludeFirstImages` key. The result is filterable via
		 * `wppo_lcp_first_n` (manual preload list / lazy-threshold override
		 * when detection is inconclusive) and clamped to 0-10. When the
		 * `lcp_guardrails` kill-switch is explicitly disabled, returns 0 so
		 * the first-N never-lazy pass is skipped. Fail-open: any filter
		 * failure falls back to the unfiltered count.
		 *
		 * @since 2.0.0
		 * @param array $image_optimisation Image optimisation settings.
		 * @return int Exclude count.
		 */
		private function get_effective_exclude_first_images_count( array $image_optimisation ): int {
			if ( array_key_exists( 'lcp_guardrails', $image_optimisation ) && empty( $image_optimisation['lcp_guardrails'] ) ) {
				return 0;
			}
			// An explicit `lcp_first_n = 0` is an intentional disable and wins
			// over OD measurements (OD never returns 0 — stored 0 clamps to 1).
			// Disabling while OD is active otherwise requires
			// `lcp_guardrails = false` or a `wppo_lcp_first_n` filter returning 0.
			if ( array_key_exists( 'lcp_first_n', $image_optimisation ) && 0 === (int) $image_optimisation['lcp_first_n'] ) {
				return 0;
			}
			$count = null;
			if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) ) {
				try {
					if ( \PerformanceOptimise\Inc\OD_Bridge::is_enabled() ) {
						$count = \PerformanceOptimise\Inc\OD_Bridge::get_exclude_first_images_count();
					}
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO Image optimisation OD error: ' . str_replace( ABSPATH, '', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			}
			if ( null === $count ) {
				// No OD measurement: prefer the additive `lcp_first_n` key,
				// fall back to the legacy `excludeFirstImages` key, and
				// finally to 0 (disabled) so option arrays predating the
				// guardrails keep their legacy behaviour. Fresh installs get
				// the default 3 via Util::get_default_settings() and existing
				// installs via the Main migration.
				if ( isset( $image_optimisation['lcp_first_n'] ) ) {
					$count = (int) $image_optimisation['lcp_first_n'];
				} elseif ( isset( $image_optimisation['excludeFirstImages'] ) ) {
					$count = (int) $image_optimisation['excludeFirstImages'];
				} else {
					$count = 0;
				}
			}
			if ( function_exists( 'apply_filters' ) ) {
				try {
					$filtered = apply_filters( 'wppo_lcp_first_n', $count, $image_optimisation );
					if ( is_numeric( $filtered ) ) {
						$count = (int) $filtered;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( $count < 0 ) {
				return 0;
			}
			if ( $count > 10 ) {
				return 10;
			}
			return $count;
		}

		/**
		 * Retrieves front page preload data if enabled.
		 *
		 * @since 1.5.1
		 * @param array $image_optimisation Image optimization configuration.
		 * @return array List of preload items for the front page.
		 */
		private function get_front_page_preload_data( array $image_optimisation ): array {
			if ( empty( $image_optimisation['preloadFrontPageImages'] ) || ! is_front_page() ) {
				return array();
			}

			$urls = $this->preload_front_page_urls;
			return array_map( array( $this, 'prepare_preload_item' ), $urls );
		}

		/**
		 * Retrieves preload data from post meta.
		 *
		 * @since 1.5.1
		 * @return array List of preload items from meta.
		 */
		private function get_meta_preload_data(): array {
			// Skip the post-meta lookup outside singular views (issue #1216):
			// get_the_ID() is meaningless on archives and the meta query
			// would run on every wp_head without this guard.
			if ( function_exists( 'is_singular' ) && ! is_singular() ) {
				return array();
			}
			$page_img_urls = get_post_meta( get_the_ID(), '_wppo_preload_image_url', true );

			if ( empty( $page_img_urls ) ) {
				return array();
			}

			$urls = Util::process_urls( $page_img_urls );
			return array_map( array( $this, 'prepare_preload_item' ), $urls );
		}

		/**
		 * Retrieves preload data for specific post types.
		 *
		 * @since 1.5.1
		 * @param array $image_optimisation Image optimization configuration.
		 * @return array List of preload items for the post type.
		 */
		private function get_post_type_preload_data( array $image_optimisation ): array {
			if ( empty( $image_optimisation['preloadPostTypeImage'] ) ) {
				return array();
			}

			$selected_post_types = (array) ( $image_optimisation['selectedPostType'] ?? array() );

			// P1 Fix: Only proceed if post types are explicitly selected.
			if ( empty( $selected_post_types ) || ! is_singular( $selected_post_types ) || ! has_post_thumbnail() ) {
				return array();
			}

			$thumbnail_id = get_post_thumbnail_id();
			if ( ! $thumbnail_id ) {
				return array();
			}

			$exclude_img_urls = $this->exclude_post_type_imgs;
			$image_url        = $this->get_image_url_by_post_type( $thumbnail_id );

			if ( $this->should_exclude_image( $image_url, $exclude_img_urls ) ) {
				return array();
			}

			$srcset = wp_get_attachment_image_srcset( $thumbnail_id );
			return $this->get_srcset_preload_items( $srcset, $image_url, $image_optimisation );
		}

		/**
		 * Retrieves the URL of the featured image for the current post type.
		 *
		 * @since 1.0.0
		 *
		 * @param int $thumbnail_id The ID of the thumbnail image.
		 * @return string The URL of the image.
		 */
		private function get_image_url_by_post_type( int $thumbnail_id ): string {
			if ( 'product' === get_post_type() && class_exists( 'WooCommerce' ) ) {
				$image_size = apply_filters( 'woocommerce_gallery_image_size', 'woocommerce_single' );
				return wp_get_attachment_image_url( $thumbnail_id, $image_size ) ?? '';
			}

			return wp_get_attachment_image_url( $thumbnail_id, 'blog-single-image' ) ?? '';
		}

		/**
		 * Check if an image should be excluded from preloading or optimization.
		 *
		 * @since 1.0.0
		 *
		 * @param string $image_url The URL of the image.
		 * @param array  $exclude_img_urls Array of URLs to exclude.
		 * @return bool True if the image should be excluded, false otherwise.
		 */
		private function should_exclude_image( string $image_url, array $exclude_img_urls ): bool {
			foreach ( $exclude_img_urls as $url ) {
				if ( str_contains( $image_url, $url ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Parse srcset data from an image tag.
		 *
		 * @since 1.5.1
		 * @param string $srcset             The srcset string from the image tag.
		 * @param array  $image_optimisation Image optimization configuration array.
		 * @return array Array of parsed sources: array( 'url' => string, 'width' => int ).
		 */
		private function parse_srcset_data( $srcset, $image_optimisation ): array {
			if ( ! $srcset ) {
				return array();
			}

			$sources       = array_map( 'trim', explode( ',', $srcset ) );
			$max_width     = (int) ( $image_optimisation['maxWidthImgSize'] ?? self::MAX_PRELOAD_WIDTH );
			$exclude_sizes = $this->exclude_sizes;

			$parsed_sources = array();

			foreach ( $sources as $source ) {
				list( $url, $descriptor ) = array_pad( preg_split( '/\s+/', trim( $source ), 2 ), 2, '' );
				$width                    = (int) rtrim( $descriptor, 'w' );

				if ( in_array( $width, $exclude_sizes, true ) || $width > $max_width ) {
					continue;
				}

				$parsed_sources[] = array(
					'url'   => $url,
					'width' => $width,
				);
			}

			usort( $parsed_sources, fn( $a, $b ) => $a['width'] - $b['width'] );
			return $parsed_sources;
		}

		/**
		 * Retrieves preload data items from an image's srcset.
		 *
		 * Capped at MAX_LCP_PRELOADS (issue #1216) so one post-type hero
		 * can never expand to N media-variant links.
		 *
		 * @since 1.5.1
		 * @since NEXT Keeps the largest MAX_LCP_PRELOADS widths (the likely
		 * hero variants) instead of the smallest; media ranges are generated
		 * after the slice so coverage stays gapless.
		 * @param string $srcset             The srcset string from the image tag.
		 * @param string $default_image      The fallback image URL.
		 * @param array  $image_optimisation Image optimization configuration array.
		 * @return array List of preload items.
		 */
		private function get_srcset_preload_items( $srcset, $default_image, $image_optimisation ): array {
			if ( ! $srcset ) {
				return array( $this->prepare_preload_item( $default_image ) );
			}

			$parsed_sources = $this->parse_srcset_data( $srcset, $image_optimisation );
			if ( empty( $parsed_sources ) ) {
				return array( $this->prepare_preload_item( $default_image ) );
			}

			// Slice parsed sources before generating media ranges so the
			// surviving items keep gapless viewport coverage (issue #1216):
			// slicing after media generation would leave the last kept
			// item's max-width bound computed against a removed variant.
			// `parse_srcset_data()` sorts ascending, so the negative offset
			// keeps the largest widths — the likely hero — instead of
			// thumbnails. The first survivor still opens at min-width 0, so
			// no viewport gap is left below it.
			if ( count( $parsed_sources ) > self::MAX_LCP_PRELOADS ) {
				$parsed_sources = array_values( array_slice( $parsed_sources, -self::MAX_LCP_PRELOADS ) );
			}

			$max_width      = (int) ( $image_optimisation['maxWidthImgSize'] ?? self::MAX_PRELOAD_WIDTH );
			$items          = array();
			$previous_width = 0;

			foreach ( $parsed_sources as $index => $source ) {
				$current_width = $source['width'];
				$next_width    = $parsed_sources[ $index + 1 ]['width'] ?? null;

				$media = "(min-width: {$previous_width}px)";
				if ( $next_width && $next_width <= $max_width ) {
					$media .= " and (max-width: {$current_width}px)";
				}

				$items[]        = array(
					'url'      => $source['url'],
					'media'    => $media,
					'priority' => 'high',
				);
				$previous_width = $current_width + 1;
			}

			return $items;
		}

		/**
		 * Prepares a URL for preloading, handling specific prefixes and resolving relative paths.
		 *
		 * @since 1.5.1
		 * @since NEXT Adds optional $imagesrcset/$imagesizes for responsive LCP heroes.
		 * @param string $img_url The original URL to prepare.
		 * @param string $imagesrcset Optional responsive srcset for the preload link.
		 * @param string $imagesizes Optional sizes for the preload link.
		 * @return array Structured preload item.
		 */
		private function prepare_preload_item( string $img_url, string $imagesrcset = '', string $imagesizes = '' ): array {
			$img_url = trim( $img_url );
			$media   = '';

			// Non-absolute hero URLs resolve via the request-aware base
			// (issue #1216): root-relative (/uploads/hero.jpg) appends to the
			// home URL while page-relative (images/hero.jpg, ../img.jpg)
			// resolves against the current request path with dot-segment
			// normalization — the same base the browser uses for the emitted
			// <img src> — so the preload href never 404s from a wrong base.
			$resolve_relative = function ( string $url ): string {
				$url = trim( $url );
				if ( '' === $url || 0 === strpos( $url, 'http' ) || 0 === strpos( $url, '//' ) || 0 === strpos( $url, 'data:' ) || 0 === strpos( $url, 'blob:' ) ) {
					return $url;
				}
				try {
					return $this->normalize_url( $url );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( function_exists( 'home_url' ) ) {
					$home = rtrim( (string) home_url(), '/' );
					if ( 0 === strpos( $url, '/' ) ) {
						return $home . $url;
					}
					return $home . '/' . ltrim( $url, '/' );
				}
				return Util::cached_content_url( $url );
			};

			if ( 0 === strpos( $img_url, 'mobile:' ) ) {
				$img_url = trim( str_replace( 'mobile:', '', $img_url ) );
				$img_url = $resolve_relative( $img_url );
				$media   = '(max-width: 768px)';
			} elseif ( 0 === strpos( $img_url, 'desktop:' ) ) {
				$img_url = trim( str_replace( 'desktop:', '', $img_url ) );
				$img_url = $resolve_relative( $img_url );
				$media   = '(min-width: 768px)';
			} else {
				$img_url = $resolve_relative( $img_url );
			}

			// Never emit srcset without sizes (preload spec): a half pair
			// degrades to a plain href preload (fail-open).
			if ( '' === trim( $imagesrcset ) || '' === trim( $imagesizes ) ) {
				$imagesrcset = '';
				$imagesizes  = '';
			}

			return array(
				'url'         => $img_url,
				'media'       => $media,
				'priority'    => 'high',
				'imagesrcset' => $imagesrcset,
				'imagesizes'  => $imagesizes,
			);
		}

		/**
		 * Generates a preload link for a given image URL.
		 *
		 * Signal-driven single-preload entry point (issue #1273): with an
		 * empty `$img_url` the stable RUM/OD candidate from
		 * `get_stable_signal_lcp_url()` is used, so exactly one
		 * `<link rel="preload" as="image" fetchpriority="high">` is emitted
		 * per URL per request (shared `has/mark_preload_emitted()` dedup,
		 * also consulted by `preload_images()` and the Critical-CSS
		 * field-LCP path). The emitted URL is recorded in the per-request
		 * emitted set so `add_delay_load_img()` exempts it from lazy-load
		 * in the same response. Guards: per-post `_wppo_disable_auto_lcp`
		 * meta suppresses the signal-resolved path; image-ness and
		 * same-origin validators apply to every candidate; failures emit
		 * nothing (fail-open, never broken markup). The `fetchpriority`
		 * attribute is emitted directly (legacy-safe; no new core API
		 * required — core gap-fill paths stay `function_exists()`-guarded
		 * elsewhere).
		 *
		 * @since 1.0.0
		 * @since NEXT Resolves the stable signal candidate when empty,
		 * enforces per-URL dedup + per-post disable + lazy-exclusion
		 * coupling with `fetchpriority="high"`.
		 *
		 * @param string $img_url The URL of the image to preload. Empty resolves the stable signal candidate.
		 * @return void
		 */
		public function generate_img_preload( $img_url = '' ) {
			try {
				$img_url = is_string( $img_url ) ? trim( $img_url ) : '';
				if ( '' === $img_url ) {
					// Empty input resolves the stable signal candidate;
					// get_stable_signal_lcp_url() already honours the
					// per-post disable. An explicit URL is explicit
					// opt-in and is unaffected by the disable.
					$img_url = $this->get_stable_signal_lcp_url();
				}
				if ( '' === $img_url ) {
					return;
				}
				if ( ! $this->is_image_lcp_url( $img_url ) || ! $this->is_allowed_hero_preload_url( $img_url ) ) {
					return;
				}
				if ( self::has_emitted_preload( $img_url ) ) {
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return;
			}
			$data = $this->prepare_preload_item( $img_url );
			if ( ! is_array( $data ) || empty( $data['url'] ) || ! is_string( $data['url'] ) ) {
				return;
			}
			// Cross-emitter invariant: the prepared (resolved) URL claims
			// the dedup slot too, so a relative-vs-absolute alias of the
			// same hero cannot emit a second tag.
			try {
				if ( self::has_emitted_preload( $data['url'], (string) ( $data['media'] ?? '' ) ) ) {
					return;
				}
				self::mark_preload_emitted( $img_url );
				self::mark_preload_emitted( $data['url'], (string) ( $data['media'] ?? '' ) );
				self::record_direct_preload_url( $img_url );
				self::record_direct_preload_url( $data['url'] );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			Util::generate_preload_link(
				$data['url'],
				'preload',
				'image',
				false,
				Util::get_image_mime_type( $data['url'] ),
				$data['media'] ?? '',
				is_string( $data['priority'] ?? null ) && '' !== ( $data['priority'] ?? '' ) ? (string) $data['priority'] : 'high',
				(string) ( $data['imagesrcset'] ?? '' ),
				(string) ( $data['imagesizes'] ?? '' )
			);
		}

		/**
		 * Whether a `sizes` value already includes the `auto` keyword.
		 *
		 * Uses the Core helper (WP 6.7+) when available and falls back to a regex
		 * mirroring its "auto first in the list" behaviour.
		 *
		 * @since 1.8.0
		 *
		 * @param string $sizes The `sizes` attribute value.
		 * @return bool True when the value already starts with `auto`.
		 */
		private function sizes_attribute_includes_auto( string $sizes ): bool {
			if ( function_exists( 'wp_sizes_attribute_includes_valid_auto' ) ) {
				return wp_sizes_attribute_includes_valid_auto( $sizes );
			}
			return (bool) preg_match( '/^\s*auto\b/i', $sizes );
		}

		/**
		 * Whether an <img> tag qualifies for the auto-sizes enhancement.
		 *
		 * Auto-sizes requires a srcset (so the browser has candidates to choose from)
		 * and explicit dimensions (so layout is stable and CLS is prevented).
		 *
		 * @since 1.8.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags The tag processor instance.
		 * @return bool True when the tag supports auto-sizes.
		 */
		private function tag_supports_auto_sizes( $tags ): bool {
			return ( null !== $tags->get_attribute( 'srcset' ) || null !== $tags->get_attribute( 'data-srcset' ) )
				&& null !== $tags->get_attribute( 'width' )
				&& null !== $tags->get_attribute( 'height' );
		}

		/**
		 * Prepares the value stored in `data-sizes` so auto-sizes can be restored.
		 *
		 * When the current WP version supports auto-sizes and the image qualifies
		 * (srcset + width + height), any static `sizes` value is prefixed with
		 * `auto, ` as a progressive enhancement; values that already include a valid
		 * `auto` keyword (Core's "auto, …" output) are preserved verbatim. Otherwise
		 * the value is returned unchanged so pre-6.7 behaviour is untouched.
		 *
		 * @since 1.8.0
		 *
		 * @param string                 $sizes The original `sizes` attribute value.
		 * @param \WP_HTML_Tag_Processor $tags  The tag processor instance used for the srcset/width/height checks.
		 * @return string The value to store in `data-sizes`.
		 */
		private function prepare_auto_sizes_value( string $sizes, $tags ): string {
			if ( ! Util::is_auto_sizes_available() || ! $this->tag_supports_auto_sizes( $tags ) ) {
				return $sizes;
			}
			if ( $this->sizes_attribute_includes_auto( $sizes ) ) {
				return $sizes;
			}
			return 'auto, ' . $sizes;
		}

		/**
		 * Query core for its loading/fetchpriority/decoding decision for an image.
		 *
		 * Single-sourced wrapper around `wp_get_loading_optimization_attributes()`
		 * (WP 6.3+). All call-sites route through here so core owns the loading
		 * decision (threshold/exception rules included) and the plugin only
		 * fills gaps. Fail-open: returns an empty array when the function is
		 * missing or throws, so callers fall back to internal lazy/high logic
		 * and markup is emitted unoptimised, never fatal. Output transform
		 * only, hence multisite-safe by construction.
		 *
		 * @since NEXT
		 *
		 * @param array  $tag_attr Image attributes (src/width/height/loading/decoding/fetchpriority).
		 * @param string $context  Context string passed to core (kept per call-site:
		 *                         'wp-html-tag-processor', 'regex-fallback', or
		 *                         'performance_optimisation_delay_load').
		 * @return array Core's loading/fetchpriority/decoding/sizes triple (possibly empty).
		 */
		private function merge_core_loading_attributes( array $tag_attr, string $context ): array {
			if ( ! function_exists( 'wp_get_loading_optimization_attributes' ) ) {
				return array();
			}
			try {
				$loading_attrs = wp_get_loading_optimization_attributes( 'img', $tag_attr, $context );
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			if ( ! is_array( $loading_attrs ) ) {
				return array();
			}
			$allowed = array( 'loading', 'fetchpriority', 'decoding', 'sizes' );
			return array_intersect_key( $loading_attrs, array_flip( $allowed ) );
		}

		/**
		 * Enforce one valid loading/fetchpriority/decoding triple per element.
		 *
		 * Core-parity invariant: never pair `loading="lazy"` with
		 * `fetchpriority="high"`. When both are present the high hint is
		 * dropped so the hero gets high+eager and below-fold gets lazy.
		 *
		 * @since NEXT
		 *
		 * @param array $attrs Triple to sanitize (loading/fetchpriority/decoding).
		 * @return array Sanitized triple.
		 */
		private function sanitize_loading_triple( array $attrs ): array {
			if ( isset( $attrs['loading'], $attrs['fetchpriority'] ) && 'lazy' === $attrs['loading'] && 'high' === $attrs['fetchpriority'] ) {
				unset( $attrs['fetchpriority'] );
			}
			return $attrs;
		}

		/**
		 * Sets loading optimization attributes (fetchpriority, decoding) on a tag processor.
		 *
		 * Uses wp_get_loading_optimization_attributes() (WP 6.7+) when available,
		 * falling back to manual attribute assignment. Also handles occluded
		 * detection (Image Prioritizer) when core returns fetchpriority low for
		 * below-fold images.
		 *
		 * @since 2.0.0
		 * @since NEXT Excluded images pass `$allow_lazy = false` so core's
		 * `loading="lazy"` is never stamped on an image the user excluded from
		 * lazy-loading; the exclusion wins and the high-priority default applies.
		 *
		 * @param \WP_HTML_Tag_Processor $tags       The tag processor instance.
		 * @param array                  $defaults   Default attributes to set if core function is unavailable.
		 * @param bool                   $allow_lazy Whether core may contribute `loading="lazy"`.
		 * @return void
		 */
		private function set_loading_optimization_attributes( $tags, array $defaults = array(), bool $allow_lazy = true ): void {
			$loading_attrs = array();
			// Explicit version-gated core guard (issue #1180): consult core
			// only when its loading-optimization API is available.
			if ( $this->is_core_loading_optimization_available() ) {
				$tag_attr = array();
				$src      = $tags->get_attribute( 'src' );
				if ( null !== $src ) {
					$tag_attr['src'] = $src;
				}
				$width = $tags->get_attribute( 'width' );
				if ( null !== $width ) {
					$tag_attr['width'] = (int) $width;
				}
				$height = $tags->get_attribute( 'height' );
				if ( null !== $height ) {
					$tag_attr['height'] = (int) $height;
				}
				$loading = $tags->get_attribute( 'loading' );
				if ( null !== $loading ) {
					$tag_attr['loading'] = $loading;
				}
				$decoding = $tags->get_attribute( 'decoding' );
				if ( null !== $decoding ) {
					$tag_attr['decoding'] = $decoding;
				}
				$fetchpriority = $tags->get_attribute( 'fetchpriority' );
				if ( null !== $fetchpriority ) {
					$tag_attr['fetchpriority'] = $fetchpriority;
				}
				$loading_attrs = $this->merge_core_loading_attributes( $tag_attr, 'wp-html-tag-processor' );
				$loading_attrs = $this->sanitize_loading_triple( $loading_attrs );
				if ( ! $allow_lazy && isset( $loading_attrs['loading'] ) && 'lazy' === $loading_attrs['loading'] ) {
					// Excluded from lazy-loading: drop core's lazy verdict, keep
					// its fetchpriority/decoding hints.
					unset( $loading_attrs['loading'] );
				}
				if ( isset( $loading_attrs['loading'] ) && null === $tags->get_attribute( 'loading' ) ) {
					$tags->set_attribute( 'loading', $loading_attrs['loading'] );
				}
				if ( isset( $loading_attrs['fetchpriority'] ) && null === $tags->get_attribute( 'fetchpriority' ) ) {
					$tags->set_attribute( 'fetchpriority', $loading_attrs['fetchpriority'] );
				}
				if ( isset( $loading_attrs['decoding'] ) && null === $tags->get_attribute( 'decoding' ) ) {
					$tags->set_attribute( 'decoding', $loading_attrs['decoding'] );
				}
				// Belt-and-braces: propagate a `sizes` key if a filter returned one,
				// but only for lazy images and never overriding an explicit value.
				if ( isset( $loading_attrs['sizes'] ) && 'lazy' === $tags->get_attribute( 'loading' ) && null === $tags->get_attribute( 'sizes' ) ) {
					$tags->set_attribute( 'sizes', $loading_attrs['sizes'] );
				}
			}
			if ( isset( $defaults['fetchpriority'] ) && null === $tags->get_attribute( 'fetchpriority' ) ) {
				// Never pair loading=lazy with fetchpriority=high (excluded images
				// for which core decided lazy keep core's loading and skip the high default).
				if ( ! ( 'lazy' === $tags->get_attribute( 'loading' ) && 'high' === $defaults['fetchpriority'] ) ) {
					$tags->set_attribute( 'fetchpriority', $defaults['fetchpriority'] );
				}
			}
			if ( isset( $defaults['decoding'] ) && null === $tags->get_attribute( 'decoding' ) ) {
				$tags->set_attribute( 'decoding', $defaults['decoding'] );
			}
			// Final guard: one valid triple per element, hero high+eager, below-fold lazy.
			if ( 'lazy' === $tags->get_attribute( 'loading' ) && 'high' === $tags->get_attribute( 'fetchpriority' ) ) {
				$tags->remove_attribute( 'fetchpriority' );
			}
		}

		/**
		 * Whether missing-alt autofill is enabled.
		 *
		 * Off by default (fail-open): when disabled `process_img_tag()`
		 * returns byte-identical HTML with respect to `alt`. The value is
		 * filterable via `wppo_auto_alt_enabled` for host-level overrides.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when missing `alt` attributes should be derived.
		 */
		public function is_auto_alt_enabled(): bool {
			$enabled = ! empty( $this->options['image_optimisation']['autoAltText'] );
			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter whether missing-alt autofill is enabled.
				 *
				 * @since 2.0.0
				 * @param bool $enabled Whether autofill is enabled.
				 */
				$enabled = (bool) apply_filters( 'wppo_auto_alt_enabled', $enabled );
			}

			return $enabled;
		}

		/**
		 * Derive a human-readable alt candidate from an image URL filename.
		 *
		 * Deterministic and offline: basename → strip `-{width}x{height}`
		 * thumbnail suffix → replace `-/_/+/.` with spaces → collapse
		 * whitespace → title-case. Returns an empty string when no usable
		 * filename remains (e.g. `data:` URIs, query-only URLs). Makes no
		 * external HTTP requests and no database queries.
		 *
		 * @since 2.0.0
		 *
		 * @param string $src The image `src` URL.
		 * @return string The filename-derived alt, or empty string.
		 */
		private function filename_to_alt( string $src ): string {
			if ( '' === $src || 1 === preg_match( '#^data:image/#i', $src ) ) {
				return '';
			}

			$path = $src;
			if ( function_exists( 'wp_parse_url' ) ) {
				$parsed = wp_parse_url( $src, PHP_URL_PATH );
				if ( is_string( $parsed ) && '' !== $parsed ) {
					$path = $parsed;
				}
			} else {
				// Legacy fallback (pre-4.4 cores): strip query/fragment manually.
				$fragment_pos = strpos( $path, '#' );
				if ( false !== $fragment_pos ) {
					$path = substr( $path, 0, $fragment_pos );
				}
				$query_pos = strpos( $path, '?' );
				if ( false !== $query_pos ) {
					$path = substr( $path, 0, $query_pos );
				}
			}

			$base = basename( (string) $path );
			if ( '' === $base ) {
				return '';
			}

			$filename = pathinfo( $base, PATHINFO_FILENAME );
			if ( ! is_string( $filename ) || '' === $filename ) {
				return '';
			}

			// Strip WordPress thumbnail dimension suffixes (e.g. `-300x200`).
			$filename = (string) preg_replace( '/-\d+x\d+$/', '', $filename );
			$filename = str_replace( array( '-', '_', '+', '.' ), ' ', $filename );
			$filename = trim( (string) preg_replace( '/\s+/', ' ', $filename ) );
			if ( '' === $filename ) {
				return '';
			}

			if ( function_exists( 'sanitize_text_field' ) ) {
				$filename = sanitize_text_field( $filename );
				$filename = trim( $filename );
				if ( '' === $filename ) {
					return '';
				}
			}

			if ( function_exists( 'mb_substr' ) ) {
				$filename = mb_substr( $filename, 0, 125 );
			} else {
				$filename = substr( $filename, 0, 125 );
			}
			$filename = trim( $filename );
			if ( '' === $filename ) {
				return '';
			}

			if ( function_exists( 'mb_convert_case' ) ) {
				return mb_convert_case( mb_strtolower( $filename, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
			}

			return ucwords( strtolower( $filename ) );
		}

		/**
		 * Read the bounded persistent src-to-title map for derived alt text.
		 *
		 * @since 2.0.0
		 * @return array<string, string>
		 */
		private static function get_derived_alt_map(): array {
			try {
				// Audit #1338: per-request memo keyed by blog id (a page with N
				// distinct images calls this N times on the output-buffer path).
				// Reset by the shutdown commit so same-request reads observe
				// the merge.
				$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				if ( array_key_exists( $blog_id, self::$derived_alt_memo ) && is_array( self::$derived_alt_memo[ $blog_id ] ) ) {
					return self::$derived_alt_memo[ $blog_id ];
				}
				// Manual object-cache layer only without a persistent backend:
				// with one, get_transient() already hits wp_cache, so a second
				// namespaced read is a redundant round-trip (audit #1338 review).
				$use_manual_cache = function_exists( 'wp_using_ext_object_cache' ) ? ! wp_using_ext_object_cache() : true;
				if ( $use_manual_cache && function_exists( 'wp_cache_get' ) ) {
					$hit = wp_cache_get( Util::transient_key( 'wppo_derived_alt_map' ), 'wppo' );
					if ( is_array( $hit ) ) {
						self::$derived_alt_memo[ $blog_id ] = $hit;
						return $hit;
					}
				}
				if ( function_exists( 'get_transient' ) ) {
					$map = get_transient( Util::transient_key( 'wppo_derived_alt_map' ) );
					$map = is_array( $map ) ? $map : array();
					// Populate the manual layer on transient hit (same
					// non-persistent-backend condition as above).
					if ( $use_manual_cache && function_exists( 'wp_cache_set' ) ) {
						wp_cache_set( Util::transient_key( 'wppo_derived_alt_map' ), $map, 'wppo', DAY_IN_SECONDS );
					}
					self::$derived_alt_memo[ $blog_id ] = $map;
					return $map;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return array();
		}

		/**
		 * Store one src-to-title entry in the bounded persistent map.
		 *
		 * Deferred (audit #1338): entries buffer per request and persist once
		 * on shutdown, so a page with N new images issues one write instead
		 * of N read-modify-writes on the render path. Capped at 200 entries
		 * (drop-oldest) with a day TTL so the map cannot grow unbounded.
		 *
		 * @since 2.0.0
		 * @param string $src   Image src URL.
		 * @param string $title Resolved title (may be '').
		 * @return void
		 */
		private static function set_derived_alt_map_entry( string $src, string $title ): void {
			try {
				// Truncate frontend-controlled keys/values so 200 multi-KB
				// entries cannot inflate the persistent transient.
				$src   = substr( $src, 0, 2048 );
				$title = substr( $title, 0, 200 );
				if ( '' === $src ) {
					return;
				}
				$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				if ( ! isset( self::$deferred_alt_entries[ $blog_id ] ) || ! is_array( self::$deferred_alt_entries[ $blog_id ] ) ) {
					self::$deferred_alt_entries[ $blog_id ] = array();
					// Cap blog buckets drop-oldest: a long-lived process touching
					// unbounded sites must not grow the buffer per site.
					if ( count( self::$deferred_alt_entries ) > 10 ) {
						self::$deferred_alt_entries = array_slice( self::$deferred_alt_entries, -10, null, true );
					}
				}
				self::$deferred_alt_entries[ $blog_id ][ $src ] = $title;
				// Cap each blog bucket drop-oldest so unbounded galleries cannot
				// grow the request buffer (persist layer caps at 200 on commit).
				if ( count( self::$deferred_alt_entries[ $blog_id ] ) > 200 ) {
					self::$deferred_alt_entries[ $blog_id ] = array_slice( self::$deferred_alt_entries[ $blog_id ], -200, null, true );
				}
				if ( ! self::$alt_commit_registered && function_exists( 'add_action' ) ) {
					add_action( 'shutdown', array( __CLASS__, 'commit_derived_alt_map' ) );
					self::$alt_commit_registered = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Persist buffered alt-map entries (shutdown handler).
		 *
		 * Merges the request buffer into the persistent map in one write.
		 * Fail-open: any failure drops the buffer silently.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function commit_derived_alt_map(): void {
			try {
				// Drain loop: entries buffered mid-commit (another shutdown
				// callback, long-lived worker) re-register below via the
				// setter instead of being stranded with no pending hook.
				do {
					if ( empty( self::$deferred_alt_entries ) ) {
						break;
					}
					$buffered                   = self::$deferred_alt_entries;
					self::$deferred_alt_entries = array();
					// Re-arm: entries buffered after this drain (long-lived
					// processes, manual commits in tests) must re-register.
					self::$alt_commit_registered = false;
					$current_blog                = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
					// Best-effort concurrency note: this is an unlocked
					// read-modify-write like the original per-image writes;
					// two concurrent shutdowns can lost-update, but each
					// carries the full merged map so the loss window is one
					// request, not N.
					foreach ( $buffered as $blog_id => $entries ) {
						if ( ! is_array( $entries ) || empty( $entries ) ) {
							continue;
						}
						$blog_id  = (int) $blog_id;
						$switched = false;
						if ( $blog_id !== $current_blog && function_exists( 'switch_to_blog' ) ) {
							switch_to_blog( $blog_id );
							$switched = function_exists( 'restore_current_blog' );
						}
						try {
							$key = Util::transient_key( 'wppo_derived_alt_map' );
							$map = self::get_derived_alt_map();
							foreach ( $entries as $src => $title ) {
								$map[ $src ] = $title;
							}
							if ( count( $map ) > 200 ) {
								$map = array_slice( $map, -200, 200, true );
							}
							self::$derived_alt_memo[ $blog_id ] = $map;
							$use_manual_cache                   = function_exists( 'wp_using_ext_object_cache' ) ? ! wp_using_ext_object_cache() : true;
							if ( $use_manual_cache && function_exists( 'wp_cache_set' ) ) {
								wp_cache_set( $key, $map, 'wppo', DAY_IN_SECONDS );
							}
							if ( function_exists( 'set_transient' ) ) {
								set_transient( $key, $map, DAY_IN_SECONDS );
							}
						} catch ( \Throwable $inner ) {
							unset( $inner );
						} finally {
							// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore_current_blog() must run even when the per-blog write throws; guarded by function_exists above.
							if ( $switched ) {
								restore_current_blog();
							}
						}
					}
				} while ( ! empty( self::$deferred_alt_entries ) );
				// Late entries re-register through the normal setter path.
				if ( ! empty( self::$deferred_alt_entries ) && ! self::$alt_commit_registered && function_exists( 'add_action' ) ) {
					add_action( 'shutdown', array( __CLASS__, 'commit_derived_alt_map' ) );
					self::$alt_commit_registered = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Derive a deterministic alt for an image `src`.
		 *
		 * Primary source is the sanitized filename (`filename_to_alt()`);
		 * when that yields nothing, falls back to the title of the image
		 * attachment's parent post (resolved from `$src`, not global loop
		 * context, and cached per request so each unique src is looked up at
		 * most once). The result is filterable via `wppo_auto_alt_text` and
		 * always sanitized, trimmed, and capped at 125 chars. Never performs
		 * external HTTP; the title lookup runs only when the filename path
		 * produced nothing. Fail-open: any failure returns an empty string
		 * (caller then leaves the tag untouched).
		 *
		 * @since 2.0.0
		 *
		 * @param string $src The image `src` URL.
		 * @return string The derived alt, or empty string when none applies.
		 */
		public function get_derived_alt( string $src ): string {
			$alt = $this->filename_to_alt( $src );

			if ( '' === $alt && function_exists( 'wp_get_post_parent_id' ) && function_exists( 'get_the_title' ) && function_exists( 'attachment_url_to_postid' ) ) {
				try {
					static $parent_title_cache = array();
					if ( ! array_key_exists( $src, $parent_title_cache ) ) {
						// Check the bounded persistent map first so repeat page
						// views do not re-run attachment lookups per image.
						$persistent = self::get_derived_alt_map();
						if ( array_key_exists( $src, $persistent ) ) {
							$parent_title_cache[ $src ] = $persistent[ $src ];
						} else {
							// Resolve the image's own attachment so the fallback title
							// comes from the attachment's parent post, not the global
							// post loop context (which describes the rendered page).
							$attachment_id = (int) attachment_url_to_postid( $src );
							$parent_id     = $attachment_id > 0 ? (int) wp_get_post_parent_id( $attachment_id ) : 0;
							$title         = $parent_id > 0 ? get_the_title( $parent_id ) : '';
							if ( function_exists( 'sanitize_text_field' ) ) {
								$title = sanitize_text_field( (string) $title );
							}
							$parent_title_cache[ $src ] = is_string( $title ) ? trim( $title ) : '';
							self::set_derived_alt_map_entry( $src, $parent_title_cache[ $src ] );
						}
					}
					if ( '' !== $parent_title_cache[ $src ] ) {
						$alt = $parent_title_cache[ $src ];
					}
				} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fail-open: fall through with empty alt.
				}
			}

			if ( function_exists( 'apply_filters' ) ) {
				/**
				 * Filter the derived alt text for images missing an alt attribute.
				 *
				 * @since 2.0.0
				 * @param string $alt The derived alt text (may be empty).
				 * @param string $src The image `src` URL.
				 */
				$filtered = apply_filters( 'wppo_auto_alt_text', $alt, $src );
				if ( is_string( $filtered ) ) {
					$alt = $filtered;
				}
			}

			// Normalize filter output identically to the filename path:
			// sanitize, trim (rejecting whitespace-only), and cap at 125 chars
			// so an unbounded/blank filter return cannot bypass the length cap
			// or emit alt="   ".
			if ( function_exists( 'sanitize_text_field' ) ) {
				$alt = sanitize_text_field( $alt );
			}
			if ( function_exists( 'mb_substr' ) ) {
				$alt = mb_substr( $alt, 0, 125 );
			} else {
				$alt = substr( $alt, 0, 125 );
			}

			return trim( $alt );
		}

		/**
		 * Autofill a missing `alt` via Tag Processor (fail-open, byte-identical when off).
		 *
		 * Only fills when the toggle is on AND the tag has no `alt` attribute
		 * at all (`get_attribute()` returns `null`). An explicit empty
		 * `alt=""` is treated as an intentional decorative image and left
		 * untouched. Tag Processor escapes the value on serialize.
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags         Processor positioned on the `<img>` tag.
		 * @param string                 $original_src The original image `src` value.
		 * @return void
		 */
		private function maybe_autofill_alt_processor( $tags, string $original_src ): void {
			if ( ! $this->is_auto_alt_enabled() ) {
				return;
			}
			if ( null !== $tags->get_attribute( 'alt' ) ) {
				return;
			}
			$derived = $this->get_derived_alt( $original_src );
			if ( '' !== $derived ) {
				$tags->set_attribute( 'alt', $derived );
			}
		}

		/**
		 * Autofill a missing `alt` via regex fallback (fail-open, byte-identical when off).
		 *
		 * Presence check is `#(?<![\w-])alt\s*=#i`, so both `alt="x"` and decorative
		 * `alt=""` are preserved verbatim while hyphenated `data-alt` attributes
		 * do not count as an `alt`. Escapes at emit because the regex
		 * path concatenates raw strings.
		 *
		 * @since 2.0.0
		 *
		 * @param string $img_tag      The original `<img>` tag HTML.
		 * @param string $original_src The original image `src` value.
		 * @return string The tag with a derived `alt`, or unchanged.
		 */
		private function maybe_autofill_alt_regex( string $img_tag, string $original_src ): string {
			if ( ! $this->is_auto_alt_enabled() ) {
				return $img_tag;
			}
			if ( 1 === preg_match( '#(?<![\w-])alt\s*=#i', $img_tag ) ) {
				return $img_tag;
			}
			$derived = $this->get_derived_alt( $original_src );
			if ( '' === $derived ) {
				return $img_tag;
			}
			$escaped  = function_exists( 'esc_attr' ) ? esc_attr( $derived ) : htmlspecialchars( $derived, ENT_QUOTES, 'UTF-8' );
			$replaced = preg_replace( '#<img\b#i', '<img alt="' . $escaped . '"', $img_tag, 1 );
			return null === $replaced ? $img_tag : $replaced;
		}

		/**
		 * Optimize an <img> tag for lazy loading, placeholders, dimensions, and performance attributes.
		 *
		 * If the image URL matches any exclusion substring, ensures the tag has `decoding="sync"` and
		 * `fetchpriority="high"` (if missing) and returns the tag unchanged otherwise. For non-excluded
		 * images, moves `src` → `data-src`, `srcset` → `data-srcset`, and `sizes` → `data-sizes`
		 * (skipping `data:image/*` sources), optionally replaces `src` with an SVG placeholder, and
		 * populates missing `width`/`height` attributes from the local file when available.
		 *
		 * @since 1.0.0
		 *
		 * @param string   $img_tag       The original <img> tag HTML.
		 * @param string   $original_src  The original value of the image `src` attribute.
		 * @param string[] $exclude_imgs Array of URL substrings; if any is found in `$original_src` the image is treated as excluded.
		 * @return string The modified <img> tag.
		 */
		public function process_img_tag( $img_tag, $original_src, $exclude_imgs ) {
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				if ( ! empty( $exclude_imgs ) ) {
					foreach ( $exclude_imgs as $exclude_img ) {
						if ( '' !== $exclude_img && false !== strpos( $original_src, $exclude_img ) ) {
							$tags = new \WP_HTML_Tag_Processor( $img_tag );
							if ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
								$this->set_loading_optimization_attributes(
									$tags,
									array(
										'fetchpriority' => 'high',
										'decoding'      => 'sync',
									),
									false
								);
								$this->maybe_autofill_alt_processor( $tags, $original_src );
								return $tags->get_updated_html();
							}
							return $img_tag;
						}
					}
				}

				$use_native_lazy = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

				$tags = new \WP_HTML_Tag_Processor( $img_tag );
				if ( ! $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					return $img_tag;
				}

				// If the image does not have 'data-src', replace 'src' with 'data-src'.
				if ( null === $tags->get_attribute( 'data-src' ) ) {
					$original_src_decoded = htmlspecialchars_decode( $original_src, ENT_QUOTES );

					// Skip base64 images to avoid rewriting them.
					if ( ! preg_match( '#^data:image/#i', $original_src_decoded ) ) {
						if ( $use_native_lazy || 'lazy' === $tags->get_attribute( 'loading' ) ) {
							// Native lazy loading or pre-existing core loading="lazy": defer to
							// core's loading decision (WP 6.3+) — first-N/header (hero)
							// images must not be forced lazy. Gaps only are filled.
							if ( null === $tags->get_attribute( 'loading' ) ) {
								$should_lazy = true;
								if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
									$test_attr = array();
									$src_attr  = $tags->get_attribute( 'src' );
									if ( null !== $src_attr ) {
										$test_attr['src'] = $src_attr;
									}
									$w_attr = $tags->get_attribute( 'width' );
									if ( null !== $w_attr ) {
										$test_attr['width'] = (int) $w_attr;
									}
									$h_attr = $tags->get_attribute( 'height' );
									if ( null !== $h_attr ) {
										$test_attr['height'] = (int) $h_attr;
									}
									$core_attrs  = $this->merge_core_loading_attributes( $test_attr, 'wp-html-tag-processor' );
									$should_lazy = isset( $core_attrs['loading'] );
								}
								if ( $should_lazy ) {
									$tags->set_attribute( 'loading', 'lazy' );
								}
							}
							if ( null === $tags->get_attribute( 'decoding' ) ) {
								$tags->set_attribute( 'decoding', 'async' );
							}
							// Occluded: let core decide fetchpriority (low for below-fold/occluded) when available.
							if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
								$this->set_loading_optimization_attributes(
									$tags,
									array(
										'fetchpriority' => 'low',
										'decoding'      => 'async',
									)
								);
								if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
									$tags->set_attribute( 'fetchpriority', 'low' );
								}
							}
							// Native-lazy placeholders (issue #1158): keep the
							// real src and emit only the local placeholder
							// attributes (zero external HTTP, LCP hero
							// excluded from blur via the exclusion list).
							if ( 'none' !== $this->get_placeholder_type() ) {
								$native_attrs = $this->get_native_lazy_placeholder_attrs( $original_src_decoded, $exclude_imgs );
								foreach ( $native_attrs as $native_attr_name => $native_attr_value ) {
									if ( null === $tags->get_attribute( $native_attr_name ) ) {
										$tags->set_attribute( $this->normalize_data_attribute_name( $native_attr_name ), $native_attr_value );
									}
								}
							}
						} else {
							// JS-lazy path: consult core for occluded/fetchpriority before stripping src.
							if ( function_exists( 'wp_get_loading_optimization_attributes' ) && null === $tags->get_attribute( 'fetchpriority' ) ) {
								$this->set_loading_optimization_attributes( $tags );
								if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
									$tags->set_attribute( 'fetchpriority', 'low' );
								}
							} elseif ( null === $tags->get_attribute( 'fetchpriority' ) ) {
								$tags->set_attribute( 'fetchpriority', 'low' );
							}
							// Stored-XSS note (issue #967): passed raw on purpose —
							// WP_HTML_Tag_Processor escapes attribute values on
							// get_updated_html(), so pre-escaping here would
							// double-encode. The client additionally validates
							// data-src via isSafeSubresourceUrl before restoring.
							$tags->set_attribute( 'data-src', $original_src_decoded );

							// WP_HTML_Tag_Processor blocks data: URIs in src for security.
							// Use regex on the serialized HTML to swap src to the placeholder.
							if ( 'none' !== $this->get_placeholder_type() ) {
								$placeholder = $this->get_placeholder_src_for_image( $img_tag, $original_src_decoded );
								if ( ! empty( $placeholder['src'] ) ) {
									$serialized = $tags->get_updated_html();
									// Stored-XSS hardening (issue #967): the placeholder is
									// re-emitted via raw string concatenation (Tag
									// Processor blocks data: URIs in src), so escape
									// at emit — set_attribute() paths below are
									// escaped by Tag Processor on serialize and
									// must stay raw to avoid double-encoding.
									$placeholder_src = function_exists( 'esc_attr' ) ? esc_attr( $placeholder['src'] ) : $placeholder['src'];
									$img_tag         = preg_replace(
										'#(?<!data-)src=(["\'])[^"\']*\1#i',
										'src="' . $placeholder_src . '"',
										$serialized,
										1
									);
									// Guard against null return from preg_replace (PCRE engine failure).
									if ( null === $img_tag ) {
										$img_tag = $serialized;
									}
									$tags = new \WP_HTML_Tag_Processor( $img_tag );
									$tags->next_tag( array( 'tag_name' => 'img' ) );
									// Add extra placeholder data attributes.
									foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
										$tags->set_attribute( $attr_name, $attr_value );
									}
									$img_tag = $tags->get_updated_html();
									$tags    = new \WP_HTML_Tag_Processor( $img_tag );
									$tags->next_tag( array( 'tag_name' => 'img' ) );
								} else {
									$tags->remove_attribute( 'src' );
								}
							} else {
								$tags->remove_attribute( 'src' );
							}

							// Replace 'srcset' with 'data-srcset'.
							$srcset = $tags->get_attribute( 'srcset' );
							if ( $srcset ) {
								$tags->set_attribute( 'data-srcset', $srcset );
								$tags->remove_attribute( 'srcset' );
							}

							// Replace 'sizes' with 'data-sizes' (auto-aware when supported).
							$sizes = $tags->get_attribute( 'sizes' );
							if ( $sizes ) {
								$tags->set_attribute( 'data-sizes', $this->prepare_auto_sizes_value( $sizes, $tags ) );
								$tags->remove_attribute( 'sizes' );
							}
						}
					}
				}

				// Add missing width and height attributes if possible.
				$has_width  = null !== $tags->get_attribute( 'width' );
				$has_height = null !== $tags->get_attribute( 'height' );

				if ( ! $has_width || ! $has_height ) {
					$local_path = Util::get_local_path( $original_src );

					if ( ! empty( $local_path ) && $this->cached_file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
						$size = $this->get_cached_image_size( $local_path );

						if ( is_array( $size ) ) {
							if ( ! $has_width ) {
								$tags->set_attribute( 'width', (string) $size[0] );
							}
							if ( ! $has_height ) {
								$tags->set_attribute( 'height', (string) $size[1] );
							}
						}
					}
				}

				$this->maybe_autofill_alt_processor( $tags, $original_src );

				return $tags->get_updated_html();
			} else {
				// Regex Fallback (Original logic restored from git history).
				if ( ! empty( $exclude_imgs ) ) {
					foreach ( $exclude_imgs as $exclude_img ) {
						if ( '' !== $exclude_img && false !== strpos( $original_src, $exclude_img ) ) {
							if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
								$tag_attr = array( 'src' => $original_src );
								if ( preg_match( '/\bwidth=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['width'] = (int) $m[2];
								}
								if ( preg_match( '/\bheight=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['height'] = (int) $m[2];
								}
								if ( preg_match( '/\bloading=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['loading'] = $m[2];
								}
								if ( preg_match( '/\bdecoding=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['decoding'] = $m[2];
								}
								if ( preg_match( '/\bfetchpriority=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['fetchpriority'] = $m[2];
								}
								$loading_attrs = $this->sanitize_loading_triple( $this->merge_core_loading_attributes( $tag_attr, 'regex-fallback' ) );
								// Excluded from lazy-loading: the exclusion wins over core's
								// lazy verdict; fetchpriority/decoding hints are still merged.
								if ( isset( $loading_attrs['loading'] ) && 'lazy' === $loading_attrs['loading'] ) {
									unset( $loading_attrs['loading'] );
								}
								if ( isset( $loading_attrs['loading'] ) && false === strpos( $img_tag, 'loading' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 loading="' . esc_attr( $loading_attrs['loading'] ) . '"', $img_tag );
								}
								if ( isset( $loading_attrs['decoding'] ) && false === strpos( $img_tag, 'decoding' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 decoding="' . esc_attr( $loading_attrs['decoding'] ) . '"', $img_tag );
								}
								if ( isset( $loading_attrs['fetchpriority'] ) && false === strpos( $img_tag, 'fetchpriority' ) ) {
									// Never emit lazy+high on excluded images: skip a high
									// hint when the tag (or core) already decided lazy.
									$is_lazy = false !== stripos( $img_tag, 'loading="lazy"' ) || false !== stripos( $img_tag, "loading='lazy'" );
									if ( ! ( $is_lazy && 'high' === $loading_attrs['fetchpriority'] ) ) {
										$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="' . esc_attr( $loading_attrs['fetchpriority'] ) . '"', $img_tag );
									}
								}
							} else {
								if ( false === strpos( $img_tag, 'decoding' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 decoding="sync"', $img_tag );
								}

								if ( false === strpos( $img_tag, 'fetchpriority' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="high"', $img_tag );
								}
							}

							return $this->maybe_autofill_alt_regex( $img_tag, $original_src );
						}
					}
				}

				$use_native_lazy = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

				// If the image does not have 'data-src', replace 'src' with 'data-src'.
				if ( false === strpos( $img_tag, 'data-src' ) ) {
					$original_src_decoded = htmlspecialchars_decode( $original_src, ENT_QUOTES );

					// Skip base64 images to avoid rewriting them (alt autofill still applies so both paths agree).
					if ( preg_match( '#^data:image/#i', $original_src_decoded ) ) {
						return $this->maybe_autofill_alt_regex( $img_tag, $original_src );
					}

					if ( $use_native_lazy || 1 === preg_match( '/\bloading=["\']lazy["\']/i', $img_tag ) ) {
						if ( false === stripos( $img_tag, 'loading=' ) ) {
							// Defer to core (WP 6.3+): only stamp lazy when core returns a
							// loading decision; hero (first-N/header) images are left eager.
							$should_lazy = true;
							if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
								$test_attr = array( 'src' => $original_src );
								if ( preg_match( '/\bwidth=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$test_attr['width'] = (int) $m[2];
								}
								if ( preg_match( '/\bheight=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$test_attr['height'] = (int) $m[2];
								}
								$core_attrs  = $this->merge_core_loading_attributes( $test_attr, 'regex-fallback' );
								$should_lazy = isset( $core_attrs['loading'] );
							}
							if ( $should_lazy ) {
								$img_tag = preg_replace( '#<img\b#i', '<img loading="lazy"', $img_tag );
							}
						}
						if ( false === stripos( $img_tag, 'decoding=' ) ) {
							$img_tag = preg_replace( '#<img\b#i', '<img decoding="async"', $img_tag );
						}
						// Occluded: fetchpriority low for below-fold images when not already present.
						if ( false === stripos( $img_tag, 'fetchpriority' ) ) {
							if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
								$tag_attr = array( 'src' => $original_src );
								if ( preg_match( '/\bwidth=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['width'] = (int) $m[2];
								}
								if ( preg_match( '/\bheight=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['height'] = (int) $m[2];
								}
								if ( preg_match( '/\bloading=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['loading'] = $m[2];
								}
								if ( preg_match( '/\bdecoding=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['decoding'] = $m[2];
								}
								$loading_attrs = $this->sanitize_loading_triple( $this->merge_core_loading_attributes( $tag_attr, 'regex-fallback' ) );
								if ( isset( $loading_attrs['fetchpriority'] ) && false === stripos( $img_tag, 'fetchpriority' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="' . esc_attr( $loading_attrs['fetchpriority'] ) . '"', $img_tag );
								}
							}
							if ( false === stripos( $img_tag, 'fetchpriority' ) ) {
								$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="low"', $img_tag );
							}
						}
					} else {
						// JS-lazy path: occluded fetchpriority low before stripping src.
						if ( false === stripos( $img_tag, 'fetchpriority' ) ) {
							if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
								$tag_attr = array( 'src' => $original_src );
								if ( preg_match( '/\bwidth=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['width'] = (int) $m[2];
								}
								if ( preg_match( '/\bheight=(["\'])(\d+)\1/i', $img_tag, $m ) ) {
									$tag_attr['height'] = (int) $m[2];
								}
								if ( preg_match( '/\bloading=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['loading'] = $m[2];
								}
								if ( preg_match( '/\bdecoding=(["\'])([^"\']+)\1/i', $img_tag, $m ) ) {
									$tag_attr['decoding'] = $m[2];
								}
								$loading_attrs = $this->sanitize_loading_triple( $this->merge_core_loading_attributes( $tag_attr, 'regex-fallback' ) );
								if ( isset( $loading_attrs['fetchpriority'] ) && false === stripos( $img_tag, 'fetchpriority' ) ) {
									$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="' . esc_attr( $loading_attrs['fetchpriority'] ) . '"', $img_tag );
								}
							}
							if ( false === stripos( $img_tag, 'fetchpriority' ) ) {
								$img_tag = preg_replace( '#<img\b([^>]*?)#i', '<img $1 fetchpriority="low"', $img_tag );
							}
						}
						$replaced_tag = preg_replace_callback(
							'#src=["\']([^"\']+)["\']#i',
							function () use ( $original_src_decoded ) {
								return 'data-src="' . esc_attr( $original_src_decoded ) . '"';
							},
							$img_tag
						);
						// Guard against null return from preg_replace_callback (PCRE engine failure).
						if ( null !== $replaced_tag ) {
							$img_tag = $replaced_tag;
						}

						// Replace with placeholder if the option is enabled.
						if ( 'none' !== $this->get_placeholder_type() ) {
							$placeholder = $this->get_placeholder_src_for_image( $img_tag, $original_src_decoded );
							if ( ! empty( $placeholder['src'] ) ) {
								$replaced_placeholder = preg_replace_callback(
									'#<img\b([^>]*)#i',
									function ( $matches ) use ( $placeholder ) {
										$extra_attrs = '';
										foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
											$extra_attrs .= ' ' . $attr_name . '="' . esc_attr( $attr_value ) . '"';
										}
										return '<img src="' . esc_attr( $placeholder['src'] ) . '"' . $extra_attrs . $matches[1];
									},
									$img_tag
								);
								if ( null !== $replaced_placeholder ) {
									$img_tag = $replaced_placeholder;
								}
							}
						}

						// Replace 'srcset' with 'data-srcset' if 'srcset' is present.
						if ( preg_match( '#srcset=["\']([^"\']+)["\']#i', $img_tag, $srcset_matches ) ) {
							$img_tag = preg_replace(
								'#srcset=["\']([^"\']+)["\']#i',
								'data-srcset="' . esc_attr( $srcset_matches[1] ) . '"',
								$img_tag
							);
						}

						// Replace 'sizes' with 'data-sizes' if 'sizes' is present.
						if ( preg_match( '#\bsizes=["\']([^"\']+)["\']#i', $img_tag, $sizes_matches ) ) {
							$data_sizes = $sizes_matches[1];
							if ( Util::is_auto_sizes_available() && ! $this->sizes_attribute_includes_auto( $data_sizes ) ) {
								$has_srcset = (bool) preg_match( '#\b(?:data-)?srcset=["\']#i', $img_tag );
								$has_width  = (bool) preg_match( '/\bwidth=["\']\d+["\']/i', $img_tag );
								$has_height = (bool) preg_match( '/\bheight=["\']\d+["\']/i', $img_tag );
								if ( $has_srcset && $has_width && $has_height ) {
									$data_sizes = 'auto, ' . $data_sizes;
								}
							}
							$img_tag = preg_replace(
								'#\bsizes=["\']([^"\']+)["\']#i',
								'data-sizes="' . esc_attr( $data_sizes ) . '"',
								$img_tag
							);
						}
					}
				}

				// Add missing width and height attributes if possible.
				$has_width  = (bool) preg_match( '/\bwidth=["\']\d+["\']/i', $img_tag );
				$has_height = (bool) preg_match( '/\bheight=["\']\d+["\']/i', $img_tag );

				if ( ! $has_width || ! $has_height ) {
					$local_path = Util::get_local_path( $original_src );

					if ( ! empty( $local_path ) && $this->cached_file_exists( $local_path ) && is_readable( $local_path ) && is_file( $local_path ) ) {
						$size = getimagesize( $local_path );

						if ( is_array( $size ) ) {
							if ( ! $has_width ) {
								$img_tag = preg_replace( '/<img\b/i', '<img width="' . (int) $size[0] . '"', $img_tag );
							}
							if ( ! $has_height ) {
								$img_tag = preg_replace( '/<img\b/i', '<img height="' . (int) $size[1] . '"', $img_tag );
							}
						}
					}
				}

				return $this->maybe_autofill_alt_regex( $img_tag, $original_src );
			}
		}

		/**
		 * Extract the YouTube video ID from an iframe src URL.
		 *
		 * @since 2.0.0
		 *
		 * @param string $src The iframe src URL.
		 * @return string The video ID, or empty string if not a YouTube embed.
		 */
		private function get_youtube_video_id( string $src ): string {
			if ( preg_match( '#(?:youtube(?:-nocookie)?\.com/embed/|youtu\.be/)([a-zA-Z0-9_-]{11})#i', $src, $m ) ) {
				return $m[1];
			}
			return '';
		}

		/**
		 * Generate a lightweight video placeholder HTML for a YouTube iframe.
		 *
		 * Replaces the YouTube embed iframe with a static thumbnail and play button.
		 * The actual iframe is loaded only on user click via JavaScript.
		 *
		 * @since 2.0.0
		 *
		 * @param string $iframe_tag   The original <iframe> tag HTML.
		 * @param string $original_src The original src attribute value.
		 * @param string $video_id     Optional pre-extracted YouTube video ID.
		 * @return string The placeholder HTML or the original iframe tag if excluded.
		 */
		private function generate_video_placeholder( string $iframe_tag, string $original_src, string $video_id = '' ): string {
			// Comment-image hardening (issue #1271): sanitize the raw
			// iframe before re-emitting it into the noscript fallback and
			// stored attrs, so a hostile `javascript:` src / `onload` /
			// `srcdoc` can never be laundered into cached placeholder HTML.
			$iframe_tag = $this->sanitize_comment_images_in_buffer( $iframe_tag );
			if ( ! empty( $this->exclude_lazy_videos ) ) {
				foreach ( $this->exclude_lazy_videos as $exclude_video ) {
					if ( false !== strpos( $original_src, $exclude_video ) ) {
						return $iframe_tag;
					}
				}
			}

			$allowed = apply_filters( 'wppo_video_placeholder_allowed', true, $original_src, $iframe_tag );
			if ( ! $allowed ) {
				return $iframe_tag;
			}

			if ( empty( $video_id ) ) {
				$video_id = $this->get_youtube_video_id( $original_src );
			}

			if ( empty( $video_id ) ) {
				return $iframe_tag;
			}

			$video_type             = false !== strpos( $original_src, 'youtube-nocookie.com' ) ? 'youtube-nocookie' : 'youtube';
			$thumbnail_url          = 'https://img.youtube.com/vi/' . $video_id . '/maxresdefault.jpg';
			$fallback_thumbnail_url = 'https://img.youtube.com/vi/' . $video_id . '/hqdefault.jpg';
			// Stored-XSS note (issue #967): the noscript fallback re-emits the
			// source-buffer iframe tag verbatim (not a stored setting) so
			// no-JS visitors keep a working embed; all generated wrapper
			// attributes above/below are esc_url/esc_attr escaped.
			$noscript_iframe = '<noscript>' . $iframe_tag . '</noscript>';

			$play_button = '<button type="button" class="wppo-video-play-btn" aria-label="' . esc_attr__( 'Play video', 'performance-optimisation' ) . '">
				<svg aria-hidden="true" focusable="false" width="68" height="48" viewBox="0 0 68 48">
					<path class="wppo-play-btn-bg" d="M66.52,7.74c-0.78-2.93-2.49-5.41-5.42-6.19C55.79,.13,34,0,34,0S12.21,.13,6.9,1.55 C3.97,2.33,2.27,4.81,1.48,7.74C0.06,13.05,0,24,0,24s0.06,10.95,1.48,16.26c0.78,2.93,2.49,5.41,5.42,6.19 C12.21,47.87,34,48,34,48s21.79-.13,27.1-1.55c2.93-.78,4.64-3.26,5.42-6.19C67.94,34.95,68,24,68,24S67.94,13.05,66.52,7.74z" fill="#f00"></path>
					<path d="M 45,24 27,14 27,34" fill="#fff"></path>
				</svg>
			</button>';

			// Accessibility contract (audit #1133): overrides of this filter must
			// keep an accessible label (e.g. aria-label) on the play button so
			// assistive-tech users can activate the placeholder. Snapshot filter
			// presence BEFORE applying either filter: a once-only self-removing
			// filter must still trigger repair though it already detached, and a
			// late-added filter must not trigger repair on already-final markup.
			$had_play_button_filter = has_filter( 'wppo_video_play_button_html' );
			$had_placeholder_filter = has_filter( 'wppo_video_placeholder_html' );
			$play_button            = apply_filters( 'wppo_video_play_button_html', $play_button, $video_id, $video_type );

			// Note: post-filter a11y repair happens once on the final markup
			// below (gated on override filters), so the button is embedded
			// as-is here — repairing it now would just re-parse the same
			// markup twice per embed.

			// Stored attrs mirror the client IFRAME_ATTR_ALLOWLIST exactly:
			// src/width/height/style are owned by the placeholder (the client
			// sets its own geometry and fullscreen defaults), so storing them
			// would only ship dead payload bytes the client never restores.
			$attrs_to_store = array( 'id', 'class', 'sandbox', 'referrerpolicy', 'title', 'name', 'frameborder', 'allow', 'allowfullscreen' );
			$stored_attrs   = array();

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$tags = new \WP_HTML_Tag_Processor( $iframe_tag );
				if ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
					foreach ( $attrs_to_store as $attr ) {
						$val = $tags->get_attribute( $attr );
						if ( null !== $val ) {
							$stored_attrs[ $attr ] = $val;
						}
					}
				}
			}
			$attrs_json = ! empty( $stored_attrs ) ? wp_json_encode( $stored_attrs ) : '';

			// Translators: %s is the YouTube video ID, used to distinguish multiple embeds for screen-reader users.
			$thumbnail_alt    = sprintf( esc_attr__( 'Video thumbnail (%s)', 'performance-optimisation' ), esc_attr( $video_id ) );
			$placeholder_html = '<div class="wppo-video-placeholder" data-wppo-video-src="' . esc_url( $original_src ) . '" data-wppo-video-type="' . esc_attr( $video_type ) . '"' . ( $attrs_json ? ' data-wppo-iframe-attrs="' . esc_attr( $attrs_json ) . '"' : '' ) . '>
				' . $noscript_iframe . '
				<picture>
					<img src="' . esc_url( $thumbnail_url ) . '" alt="' . $thumbnail_alt . '" width="1280" height="720" loading="lazy" data-wppo-fallback="' . esc_url( $fallback_thumbnail_url ) . '">
				</picture>
				' . $play_button . '
			</div>';

			// Accessibility contract (audit #1133): overrides of this filter must
			// preserve an accessible name for the play control (aria-label or
			// text content) and a meaningful img alt so the placeholder stays
			// operable for assistive-tech users.
			$placeholder_html = apply_filters( 'wppo_video_placeholder_html', $placeholder_html, $video_id, $video_type, $thumbnail_url );

			// Enforce the contract post-filter (audit #1268): same repair as
			// above, applied to the final markup so a placeholder-filter
			// override cannot strip the button label or thumbnail alt. Skipped
			// when no override filter exists — the default markup already
			// carries both, so re-parsing every embed would be pure per-embed
			// overhead in the output-buffer filter. Branches on the pre-apply
			// snapshots above, not a post-apply has_filter() sample.
			if ( $had_placeholder_filter ) {
				$placeholder_html = $this->ensure_video_play_button_label( $placeholder_html );
				$placeholder_html = $this->ensure_video_thumbnail_alt( $placeholder_html, $video_id );
			} elseif ( $had_play_button_filter ) {
				// Only the play-button filter ran: the placeholder embeds the
				// filtered button, so only the button repair needs applying
				// to the final markup (the thumbnail alt is untouched default).
				$placeholder_html = $this->ensure_video_play_button_label( $placeholder_html );
			}

			return $placeholder_html;
		}

		/**
		 * Default accessible name for a video thumbnail image.
		 *
		 * Single home for the sprintf( __( 'Video thumbnail (%s)' ) )
		 * construction used by the placeholder default markup and both
		 * repair paths, so translator comments cannot drift between copies.
		 * Returns the raw translated string — callers escape for their sink
		 * (Tag Processor set_attribute() escapes on output; regex splices
		 * use esc_attr()).
		 *
		 * @since NEXT
		 * @param string $video_id YouTube video ID.
		 * @return string Default thumbnail alt text.
		 */
		private function default_video_thumbnail_alt( string $video_id ): string {
			return sprintf(
				/* translators: %s: YouTube video ID, used to distinguish multiple embeds for screen-reader users. */
				__( 'Video thumbnail (%s)', 'performance-optimisation' ),
				$video_id
			);
		}

		/**
		 * Re-inject the default accessible name on video play buttons.
		 *
		 * Parses the given HTML with WP_HTML_Tag_Processor and repairs every
		 * <button> that lacks both aria-label and aria-labelledby and has no
		 * text content, adding the default aria-label. Markup that already
		 * names the control (either attribute or visible text) is returned
		 * untouched so third-party button HTML survives except the repair.
		 *
		 * Trusted-filter contract: this only re-adds accessible names — it is
		 * not a sanitizer. Filter-supplied HTML (event handlers,
		 * javascript: URLs, inner active content) passes through unchanged;
		 * filters are privileged code, so no new XSS frontier is introduced,
		 * but future untrusted callers must sanitize separately.
		 *
		 * @since NEXT
		 * @param string $html Button or placeholder HTML to validate.
		 * @return string Validated HTML with accessible button names.
		 */
		private function ensure_video_play_button_label( string $html ): string {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return $html;
			}
			// Inner text per button, in document order (matches Tag Processor
			// visit order below). SVG children contribute no text, so the
			// default markup still qualifies for the repair.
			$inner_texts = array();
			if ( preg_match_all( '/<button\b[^>]*>(.*?)<\/button>/is', $html, $matches ) ) {
				foreach ( $matches[1] as $inner ) {
					$inner_texts[] = trim( (string) wp_strip_all_tags( $inner ) );
				}
			}
			// Fail open on count mismatch: the regex above only pairs complete
			// <button>…</button> elements while the Tag Processor below also
			// visits comments-adjacent, unclosed, self-closing, or nested
			// buttons — positional pairing would then misattribute text (a
			// named button gaining a spurious aria-label, or an unnamed one
			// keeping none), so leave the markup untouched instead.
			$open_count = 0;
			if ( preg_match_all( '/<button\b/i', $html, $open_matches ) ) {
				$open_count = count( $open_matches[0] );
			}
			if ( count( $inner_texts ) !== $open_count ) {
				return $html;
			}
			$tags     = new \WP_HTML_Tag_Processor( $html );
			$index    = 0;
			$repaired = false;
			while ( $tags->next_tag( array( 'tag_name' => 'button' ) ) ) {
				$inner_text = $inner_texts[ $index ] ?? '';
				++$index;
				$label      = $tags->get_attribute( 'aria-label' );
				$labelledby = $tags->get_attribute( 'aria-labelledby' );
				if ( ( is_string( $label ) && '' !== trim( $label ) ) || ( is_string( $labelledby ) && '' !== trim( $labelledby ) ) ) {
					continue;
				}
				// Text content inside the button also names the control — only
				// repair truly unnamed buttons.
				if ( '' !== $inner_text ) {
					continue;
				}
				// Raw (unescaped) value: the tag processor escapes on output.
				$tags->set_attribute( 'aria-label', __( 'Play video', 'performance-optimisation' ) );
				$repaired = true;
			}
			return $repaired ? (string) $tags->get_updated_html() : $html;
		}

		/**
		 * Re-inject the default alt on the video thumbnail image.
		 *
		 * Primary target is the placeholder thumbnail (identified by its
		 * data-wppo-fallback attribute) so the verbatim <noscript> embed is
		 * never rewritten. When no marked thumbnail exists — e.g. a
		 * placeholder filter stripped the marker or swapped the thumbnail —
		 * falls back to the first image outside any <noscript> block so the
		 * repair cannot silently no-op. Images with a non-empty alt are left
		 * untouched.
		 *
		 * Trusted-filter contract: this only re-adds the alt text — it is
		 * not a sanitizer (see ensure_video_play_button_label()).
		 *
		 * @since NEXT
		 * @param string $html     Placeholder HTML to validate.
		 * @param string $video_id YouTube video ID used in the default alt.
		 * @return string Validated HTML with a meaningful thumbnail alt.
		 */
		private function ensure_video_thumbnail_alt( string $html, string $video_id ): string {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return $html;
			}
			$original_html   = $html;
			$noscript_tokens = array();
			// Verbatim no-JS guarantee: a filter-injected marker img inside
			// <noscript> must never be rewritten — the same exclusion
			// ensure_first_content_image_alt() applies by byte offset. The
			// Tag Processor exposes no offsets, so noscript blocks are
			// swapped for comment placeholders during the repair and
			// restored afterwards (comments survive Tag Processor
			// re-serialization verbatim and can never match an img tag).
			$working_html = preg_replace_callback(
				'#<noscript\b[^>]*>.*?</noscript>#is',
				static function ( $m ) use ( &$noscript_tokens, $html ) {
					$token                     = '<!--wppo-noscript-' . count( $noscript_tokens ) . '-' . md5( $html . count( $noscript_tokens ) ) . '-->';
					$noscript_tokens[ $token ] = $m[0];
					return $token;
				},
				$html
			);
			if ( is_string( $working_html ) ) {
				$html = $working_html;
			}
			$tags         = new \WP_HTML_Tag_Processor( $html );
			$updated      = false;
			$marker_found = false;
			while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
				// Only the placeholder thumbnail carries this marker; the
				// <noscript> fallback re-emits the source embed verbatim.
				if ( null === $tags->get_attribute( 'data-wppo-fallback' ) ) {
					continue;
				}
				$marker_found = true;
				$alt          = $tags->get_attribute( 'alt' );
				if ( is_string( $alt ) && '' !== trim( $alt ) ) {
					continue;
				}
				$tags->set_attribute( 'alt', $this->default_video_thumbnail_alt( $video_id ) );
				$updated = true;
			}
			if ( $updated || $marker_found ) {
				$result = $updated ? (string) $tags->get_updated_html() : $html;
				if ( ! empty( $noscript_tokens ) ) {
					$result = strtr( $result, $noscript_tokens );
				}
				return $result;
			}
			return $this->ensure_first_content_image_alt( $original_html, $video_id );
		}

		/**
		 * Repair the alt of the first image outside any <noscript> block.
		 *
		 * Fallback for ensure_video_thumbnail_alt() when the placeholder
		 * thumbnail marker is gone. <noscript> ranges and <img> positions are
		 * located by byte offset so the verbatim no-JS embed is never
		 * touched; the repair splices an alt attribute into the first
		 * content image that lacks a non-empty one. Fail-open: any parse
		 * failure returns the input unchanged.
		 *
		 * @since NEXT
		 * @param string $html     Placeholder HTML to validate.
		 * @param string $video_id YouTube video ID used in the default alt.
		 * @return string HTML with the fallback image alt repaired, or unchanged.
		 */
		private function ensure_first_content_image_alt( string $html, string $video_id ): string {
			try {
				$noscript_ranges = array();
				if ( preg_match_all( '#<noscript\b[^>]*>.*?</noscript>#is', $html, $noscript_matches, PREG_OFFSET_BYTES ) ) {
					foreach ( $noscript_matches[0] as $match ) {
						$noscript_ranges[] = array( $match[1], $match[1] + strlen( $match[0] ) );
					}
				}
				if ( ! preg_match_all( '#<img\b[^>]*>#i', $html, $img_matches, PREG_OFFSET_BYTES ) ) {
					return $html;
				}
				foreach ( $img_matches[0] as $match ) {
					$img_tag         = $match[0];
					$offset          = $match[1];
					$inside_noscript = false;
					foreach ( $noscript_ranges as $range ) {
						if ( $offset >= $range[0] && $offset < $range[1] ) {
							$inside_noscript = true;
							break;
						}
					}
					if ( $inside_noscript ) {
						continue;
					}
					// Empty/unquoted-aware alt matching: `alt=` (no value) and
					// bare `alt` are valid HTML for an empty alt and must be
					// replaced in place — the old `[^\s>]+` (>=1 char) pattern
					// fell through to the else branch and prepended a second
					// alt attribute that browsers ignore (first wins). The `=`
					// stays required in the first pattern so an "alt" substring
					// inside a URL can never match; bare `alt` is handled
					// separately with a delimiter lookahead.
					if ( preg_match( '/\balt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)?/i', $img_tag, $alt_match ) ) {
						$alt_value = trim( $alt_match[1] ?? '', "\"' \t\n\r\0\x0B" );
						if ( '' !== $alt_value ) {
							return $html;
						}
						$repaired_tag = preg_replace(
							'/\balt\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]*)?/i',
							'alt="' . esc_attr( $this->default_video_thumbnail_alt( $video_id ) ) . '"',
							$img_tag,
							1
						);
					} elseif ( preg_match( '/\balt(?=\s|\/?>)/i', $img_tag ) ) {
						// Bare `alt` attribute with no value — empty alt.
						$repaired_tag = preg_replace(
							'/\balt(?=\s|\/?>)/i',
							'alt="' . esc_attr( $this->default_video_thumbnail_alt( $video_id ) ) . '"',
							$img_tag,
							1
						);
					} else {
						$repaired_tag = preg_replace(
							'/<img\b/i',
							'<img alt="' . esc_attr( $this->default_video_thumbnail_alt( $video_id ) ) . '"',
							$img_tag,
							1
						);
					}
					if ( ! is_string( $repaired_tag ) ) {
						return $html;
					}
					return substr( $html, 0, $offset ) . $repaired_tag . substr( $html, $offset + strlen( $img_tag ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $html;
		}

		/**
		 * Prepare an <iframe> tag for lazy loading and exclusion-aware optimization.
		 *
		 * If the iframe's source matches any exclusion substring, the tag is returned unchanged.
		 * When native lazy loading is active (lazyLoadNative), the `src` attribute is preserved and
		 * `loading="lazy"` is added so the browser handles deferral (matching how core's
		 * wp_get_loading_optimization_attributes() treats images). Otherwise the function moves
		 * `src` to `data-src`, removes the `src` attribute, and ensures the `wppo-lazyload` class is
		 * present for the JS IntersectionObserver path. Uses WP_HTML_Tag_Processor when available and
		 * falls back to regex-based attribute manipulation.
		 *
		 * @since 1.0.0
		 * @since 2.0.0 Native lazy-load path for iframes.
		 *
		 * @param string   $iframe_tag   The original `<iframe>` tag HTML.
		 * @param string   $original_src The original `src` attribute value (absolute or relative URL).
		 * @param string[] $exclude_imgs List of substrings; if any appear in `$original_src` the tag is left unchanged.
		 * @return string The modified `<iframe>` tag HTML.
		 */
		public function process_iframe_tag( $iframe_tag, $original_src, $exclude_imgs ) {
			if ( ! empty( $exclude_imgs ) ) {
				foreach ( $exclude_imgs as $exclude_img ) {
					if ( '' !== $exclude_img && false !== strpos( $original_src, $exclude_img ) ) {
						return $iframe_tag;
					}
				}
			}

			$allowed = apply_filters( 'wppo_lazyload_iframe_allowed', true, $original_src, $iframe_tag );
			if ( ! $allowed ) {
				return $iframe_tag;
			}

			// High-priority iframes are never lazy (issue #1312 parity with
			// the Tag Processor loop in add_delay_load_img()): an
			// author-marked fetchpriority high hint wins, so force eager and
			// skip deferral instead of emitting lazy+high together.
			if ( 1 === preg_match( '#fetchpriority\s*=\s*["\']?high["\']?#i', $iframe_tag ) ) {
				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					try {
						$high_tags = new \WP_HTML_Tag_Processor( $iframe_tag );
						if ( $high_tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
							$high_loading = $high_tags->get_attribute( 'loading' );
							if ( ( is_string( $high_loading ) && 'lazy' === strtolower( trim( $high_loading ) ) ) || null === $high_loading ) {
								$high_tags->set_attribute( 'loading', 'eager' );
							}
							$updated = $high_tags->get_updated_html();
							if ( is_string( $updated ) && '' !== $updated ) {
								return $updated;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( 1 === preg_match( '#loading\s*=\s*["\']?lazy["\']?#i', $iframe_tag ) ) {
					$fixed = preg_replace( '#loading\s*=\s*["\']?lazy["\']?#i', 'loading="eager"', $iframe_tag, 1 );
					if ( is_string( $fixed ) && '' !== $fixed ) {
						return $fixed;
					}
				} elseif ( false === stripos( $iframe_tag, 'loading=' ) ) {
					$with_eager = preg_replace( '#<iframe\b#i', '<iframe loading="eager"', $iframe_tag, 1 );
					if ( is_string( $with_eager ) && '' !== $with_eager ) {
						return $with_eager;
					}
				}
				return $iframe_tag;
			}

			$use_native_lazy = ! empty( $this->options['image_optimisation']['lazyLoadNative'] );

			if ( $use_native_lazy ) {
				// Native path: keep src, let the browser defer via loading="lazy".
				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$tags = new \WP_HTML_Tag_Processor( $iframe_tag );
					if ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
						if ( null === $tags->get_attribute( 'loading' ) ) {
							$tags->set_attribute( 'loading', 'lazy' );
						}
						$iframe_tag = $tags->get_updated_html();
					}
				} elseif ( false === stripos( $iframe_tag, 'loading=' ) ) {
					$replaced = preg_replace( '#<iframe\b#i', '<iframe loading="lazy"', $iframe_tag );
					// Guard against null return from preg_replace (PCRE engine failure).
					if ( null !== $replaced ) {
						$iframe_tag = $replaced;
					}
				}
				return $iframe_tag;
			}

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				$tags = new \WP_HTML_Tag_Processor( $iframe_tag );
				if ( $tags->next_tag( array( 'tag_name' => 'iframe' ) ) ) {
					// Stored-XSS note (issue #967): passed raw on purpose —
					// Tag Processor escapes on get_updated_html(); the client
					// validates data-src via isSafeSubresourceUrl before
					// restoring it into a live iframe src.
					$tags->set_attribute( 'data-src', $original_src );
					$tags->remove_attribute( 'src' );
					$tags->add_class( 'wppo-lazyload' );
					$iframe_tag = $tags->get_updated_html();
				}
			} else {
				// Regex fallback.
				// Replace src with data-src. Stored-XSS hardening (issue #967):
				// escape the captured value at emit so a hostile stored src
				// (e.g. 'x" onload="alert(1)') can never break out of markup.
				// The Tag-Processor path above needs no escaping here because
				// set_attribute() escapes on get_updated_html().
				$iframe_tag = (string) preg_replace_callback(
					'/\bsrc=["\']([^"\']+)["\']/i',
					static function ( $matches ) {
						// Decode first (mirrors the img regex-fallback path),
						// then escape at emit so the round-trip is exact and a
						// hostile stored src can never break out of markup.
						$value = htmlspecialchars_decode( $matches[1], ENT_QUOTES );
						$value = function_exists( 'esc_attr' ) ? esc_attr( $value ) : $value;
						return 'data-src="' . $value . '"';
					},
					$iframe_tag
				);

				if ( preg_match( '/class=["\']([^"\']+)["\']/', $iframe_tag, $class_matches ) ) {
					$class_value   = htmlspecialchars_decode( $class_matches[1], ENT_QUOTES );
					$class_escaped = function_exists( 'esc_attr' ) ? esc_attr( $class_value ) : $class_value;
					$iframe_tag    = str_replace( $class_matches[0], 'class="' . $class_escaped . ' wppo-lazyload"', $iframe_tag );
				} else {
					$iframe_tag = preg_replace( '/<iframe\b/i', '<iframe class="wppo-lazyload"', $iframe_tag );
				}
			}

			return $iframe_tag;
		}

		/**
		 * Whether the current request advertises AVIF support via Accept header.
		 *
		 * Fail-open contract lives with the caller: when false, AVIF sources
		 * are omitted and WebP/original delivery is used instead.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when the request Accept header allows image/avif.
		 */
		public function client_accepts_avif(): bool {
			if ( ! isset( $_SERVER['HTTP_ACCEPT'] ) ) {
				return false;
			}
			if ( ! function_exists( 'wp_unslash' ) || ! function_exists( 'sanitize_text_field' ) ) {
				return false;
			}
			$http_accept = sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) );
			return false !== strpos( $http_accept, 'image/avif' );
		}

		/**
		 * Build AVIF-first <source> tags for a <picture> wrapper.
		 *
		 * Emits `<source type="image/avif">` first, `<source type="image/webp">`
		 * second, and falls back to a single original-MIME source when no
		 * converted file exists (fail-open, never fatal). The AVIF source is
		 * only emitted when the request Accept header allows AVIF and the
		 * converted `.avif` file exists; the fallback `<img>` (with
		 * width/height intact) is left to the caller. The original source
		 * file is never deleted, so delivery stays restorable.
		 *
		 * Shared by the TagProcessor and regex-fallback wrap paths.
		 *
		 * @since 2.0.0
		 *
		 * @param string $original_src   Original image URL.
		 * @param string $srcset         Raw srcset value from the processed img (may be empty).
		 * @param string $sizes          Raw sizes value from the processed img (may be empty).
		 * @param bool   $is_lazy        Whether lazy attributes (data-srcset/data-sizes) are in use.
		 * @param bool   $should_exclude Whether the image is excluded from conversion.
		 * @return string One or more <source> tags.
		 */
		public function build_avif_first_sources( string $original_src, string $srcset = '', string $sizes = '', bool $is_lazy = false, bool $should_exclude = false ): string {
			$srcset_attr = $is_lazy ? 'data-srcset' : 'srcset';
			$sizes_attr  = $is_lazy ? 'data-sizes' : 'sizes';
			$orig_mime   = Util::get_image_mime_type( $original_src );

			if ( $should_exclude || empty( $this->options['image_optimisation']['convertImg'] ) ) {
				if ( '' !== $srcset ) {
					$tag = '<source type="' . $orig_mime . '" ' . $srcset_attr . '="' . esc_attr( $srcset ) . '"';
					if ( '' !== $sizes ) {
						$tag .= ' ' . $sizes_attr . '="' . esc_attr( $sizes ) . '"';
					}
					return $tag . '>';
				}
				return '<source type="' . $orig_mime . '" ' . $srcset_attr . '="' . esc_attr( $original_src ) . '">';
			}

			$img_converter     = $this->get_img_converter();
			$conversion_format = method_exists( $img_converter, 'get_format' ) ? $img_converter->get_format() : 'webp';
			$avif_first        = ! empty( $this->options['image_optimisation']['avifFirst'] ?? true );
			$accepts_avif      = $this->client_accepts_avif();

			$candidates = array();
			if ( '' !== $srcset ) {
				foreach ( explode( ',', $srcset ) as $item ) {
					$parts = array_pad( preg_split( '/\s+/', trim( $item ), 2 ), 2, '' );
					if ( '' !== $parts[0] ) {
						$candidates[] = array(
							'url'        => $parts[0],
							'descriptor' => $parts[1],
						);
					}
				}
			}
			if ( empty( $candidates ) ) {
				$candidates[] = array(
					'url'        => $original_src,
					'descriptor' => '',
				);
			}

			$sources = '';

			if ( $avif_first && in_array( $conversion_format, array( 'avif', 'both' ), true ) && $accepts_avif ) {
				$avif_items = array();
				$has_avif   = false;
				foreach ( $candidates as $candidate ) {
					$converted_path = $img_converter->get_img_path( $candidate['url'], 'avif' );
					if ( '' !== $converted_path && $this->cached_file_exists( $converted_path ) ) {
						$converted_url = $img_converter->get_img_url( $candidate['url'], 'avif' );
						$avif_items[]  = $converted_url . ( '' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '' );
						$has_avif      = true;
					} else {
						$avif_items[] = $candidate['url'] . ( '' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '' );
					}
				}
				if ( $has_avif ) {
					$sources .= '<source type="image/avif" ' . $srcset_attr . '="' . esc_attr( implode( ', ', $avif_items ) ) . '"';
					if ( '' !== $sizes ) {
						$sources .= ' ' . $sizes_attr . '="' . esc_attr( $sizes ) . '"';
					}
					$sources .= '>';
				}
			}

			if ( in_array( $conversion_format, array( 'webp', 'both' ), true ) ) {
				$webp_items = array();
				$has_webp   = false;
				foreach ( $candidates as $candidate ) {
					$converted_path = $img_converter->get_img_path( $candidate['url'], 'webp' );
					if ( '' !== $converted_path && $this->cached_file_exists( $converted_path ) ) {
						$converted_url = $img_converter->get_img_url( $candidate['url'] );
						$webp_items[]  = $converted_url . ( '' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '' );
						$has_webp      = true;
					} else {
						$webp_items[] = $candidate['url'] . ( '' !== $candidate['descriptor'] ? ' ' . $candidate['descriptor'] : '' );
					}
				}
				if ( $has_webp ) {
					$sources .= '<source type="image/webp" ' . $srcset_attr . '="' . esc_attr( implode( ', ', $webp_items ) ) . '"';
					if ( '' !== $sizes ) {
						$sources .= ' ' . $sizes_attr . '="' . esc_attr( $sizes ) . '"';
					}
					$sources .= '>';
				}
			}

			if ( '' === $sources ) {
				if ( '' !== $srcset ) {
					$tag = '<source type="' . $orig_mime . '" ' . $srcset_attr . '="' . esc_attr( $srcset ) . '"';
					if ( '' !== $sizes ) {
						$tag .= ' ' . $sizes_attr . '="' . esc_attr( $sizes ) . '"';
					}
					return $tag . '>';
				}
				return '<source type="' . $orig_mime . '" ' . $srcset_attr . '="' . esc_attr( $original_src ) . '">';
			}

			return $sources;
		}

		/**
		 * Wraps an image in a <picture> element or updates an existing <picture> by adding appropriate <source>
		 * attributes for optimized delivery and lazy-loading based on current options and exclusions.
		 *
		 * Processes the provided image tag (or the <img> inside an existing <picture>) and returns the resulting
		 * HTML fragment. Honors the configured wrapInPicture option and skips adding <source> descriptors when
		 * the image URL matches any entry in the exclusion list.
		 *
		 * @since 1.0.0
		 *
		 * @param array  $matches      Regex match array containing the matched <img> or <picture> fragment.
		 * @param string $img_tag      The original <img> tag to process.
		 * @param string $original_src The original src attribute value of the image.
		 * @param array  $exclude_imgs List of URL substrings; if any is present in the image URL, source descriptors are not added.
		 * @return string The processed <picture> or <img> HTML fragment (or the original fragment if unchanged).
		 */
		public function process_picture_tag( $matches, $img_tag, $original_src, $exclude_imgs ) {
			// Note (see #624): when core's Enhanced Responsive Images ships native
			// <picture>/srcset handling and accurate Gallery-block sizes, reassess
			// whether this <picture>-wrap remains necessary or should defer to core.
			// No runtime change until the core API lands.
			$should_exclude = false;
			foreach ( $exclude_imgs as $exclude_img ) {
				if ( '' !== $exclude_img && false !== strpos( $original_src, $exclude_img ) ) {
					$should_exclude = true;
					break;
				}
			}

			if ( class_exists( 'WP_HTML_Processor' ) ) {
				$wpp = new \WP_HTML_Processor( $matches[0] );
				if ( null === $wpp->get_last_error() && $wpp->next_tag( array( 'tag_name' => 'picture' ) ) ) {
					$depth = $wpp->get_current_depth();

					// First pass: collect srcset/sizes from inner <img>.
					$inner_img_srcset = null;
					$inner_img_sizes  = null;
					$inner_img_lazy   = false;

					while ( $wpp->next_tag() ) {
						if ( $wpp->get_current_depth() <= $depth ) {
							break;
						}
						if ( 'IMG' === $wpp->get_tag() && ! $wpp->is_tag_closer() ) {
							$inner_img_srcset = $wpp->get_attribute( 'data-srcset' ) ?? $wpp->get_attribute( 'srcset' );
							$inner_img_sizes  = $wpp->get_attribute( 'data-sizes' ) ?? $wpp->get_attribute( 'sizes' );
							$inner_img_lazy   = null !== $wpp->get_attribute( 'data-src' );
						}
					}

					// Second pass: modify <source> attributes.
					$wpp = new \WP_HTML_Processor( $matches[0] );
					$wpp->next_tag( array( 'tag_name' => 'picture' ) );
					$depth = $wpp->get_current_depth();

					while ( $wpp->next_tag() ) {
						if ( $wpp->get_current_depth() <= $depth ) {
							break;
						}
						if ( 'SOURCE' === $wpp->get_tag() && ! $wpp->is_tag_closer() ) {
							$wpp->set_attribute( 'type', Util::get_image_mime_type( $original_src ) );
							if ( ! $should_exclude ) {
								if ( $inner_img_srcset ) {
									$wpp->set_attribute( $inner_img_lazy ? 'data-srcset' : 'srcset', $inner_img_srcset );
								}
								if ( $inner_img_sizes ) {
									$wpp->set_attribute( $inner_img_lazy ? 'data-sizes' : 'sizes', $inner_img_sizes );
								}
							}
						}
					}

					// Process the <img> inside the picture.
					$updated_html = $wpp->get_updated_html();

					if ( preg_match( '#<img\b[^>]*>#i', $matches[0], $img_matches ) ) {
						$img_tag    = $img_matches[0];
						$tags_check = new \WP_HTML_Tag_Processor( $img_tag );

						if ( $tags_check->next_tag( array( 'tag_name' => 'img' ) ) && null !== $tags_check->get_attribute( 'data-src' ) ) {
							if ( 'none' !== $this->get_placeholder_type() ) {
								$original_src = $tags_check->get_attribute( 'data-src' ) ?? '';
								$placeholder  = $this->get_placeholder_src_for_image( $img_tag, htmlspecialchars_decode( $original_src, ENT_QUOTES ) );
								if ( ! empty( $placeholder['src'] ) ) {
									$updated_tags = new \WP_HTML_Tag_Processor( $updated_html );
									if ( $updated_tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
										$updated_tags->set_attribute( 'src', $placeholder['src'] );
										foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
											$updated_tags->set_attribute( $attr_name, $attr_value );
										}
										return $updated_tags->get_updated_html();
									}
								}
							}
							return $updated_html;
						}

						$tags_src = new \WP_HTML_Tag_Processor( $img_tag );
						if ( $tags_src->next_tag( array( 'tag_name' => 'img' ) ) ) {
							$src_val = $tags_src->get_attribute( 'src' );
							if ( $src_val ) {
								$original_src = $src_val;
							}
						}
						$processed_img = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );
						// Set fetchpriority high for first image (LCP candidate), else async/low.
						++$this->picture_counter;
						$proc = new \WP_HTML_Tag_Processor( $processed_img );
						if ( $proc->next_tag( array( 'tag_name' => 'img' ) ) ) {
							if ( 1 === $this->picture_counter ) {
								if ( null === $proc->get_attribute( 'fetchpriority' ) ) {
									$proc->set_attribute( 'fetchpriority', 'high' );
								}
							} else {
								if ( null === $proc->get_attribute( 'decoding' ) ) {
									$proc->set_attribute( 'decoding', 'async' );
								}
								if ( null === $proc->get_attribute( 'fetchpriority' ) ) {
									$proc->set_attribute( 'fetchpriority', 'low' );
								}
							}
							$processed_img = $proc->get_updated_html();
						}
						return preg_replace_callback(
							'#<img\b[^>]*>#i',
							function () use ( $processed_img ) {
								return $processed_img;
							},
							$updated_html,
							1
						);
					}
					return $updated_html;
				}
				// Fall through to WP_HTML_Tag_Processor on bail or non-picture case.
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				if ( ! preg_match( '#<picture\b[^>]*>.*?</picture>#is', $matches[0] ) ) {

					$img_tag = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					if ( ! isset( $this->options['image_optimisation']['wrapInPicture'] ) || (bool) $this->options['image_optimisation']['wrapInPicture'] ) {
						$tags = new \WP_HTML_Tag_Processor( $img_tag );
						if ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
							$srcset = $tags->get_attribute( 'data-srcset' ) ?? $tags->get_attribute( 'srcset' );
							$sizes  = $tags->get_attribute( 'data-sizes' ) ?? $tags->get_attribute( 'sizes' );

							$is_lazy = null !== $tags->get_attribute( 'data-src' );
							// AVIF-first <picture> output: AVIF source, WebP source, original <img> fallback.
							$source_tag = $this->build_avif_first_sources( $original_src, (string) ( $srcset ?? '' ), (string) ( $sizes ?? '' ), $is_lazy, $should_exclude );

							// Hybrid wrapping: Processed <img> tag is wrapped inside <picture>.
							$img_tag = '<picture>' . $source_tag . $img_tag . '</picture>';
						}
					}
					return $img_tag;
				} elseif ( preg_match( '#<img\b[^>]*>#i', $matches[0], $img_matches ) ) {
					// Existing <picture> tag: find the <img> inside and process it.
					$img_tag    = $img_matches[0];
					$tags_check = new \WP_HTML_Tag_Processor( $img_tag );

					if ( $tags_check->next_tag( array( 'tag_name' => 'img' ) ) && null !== $tags_check->get_attribute( 'data-src' ) ) {
						// Already lazy-loaded — only inject placeholder if src is missing.
						if ( 'none' !== $this->get_placeholder_type() ) {
							$original_src = $tags_check->get_attribute( 'data-src' ) ?? '';
							$placeholder  = $this->get_placeholder_src_for_image( $img_tag, htmlspecialchars_decode( $original_src, ENT_QUOTES ) );
							if ( ! empty( $placeholder['src'] ) ) {
								$tags_write = new \WP_HTML_Tag_Processor( $img_tag );
								if ( $tags_write->next_tag( array( 'tag_name' => 'img' ) ) && null === $tags_write->get_attribute( 'src' ) ) {
									$tags_write->set_attribute( 'src', $placeholder['src'] );
									foreach ( $placeholder['attrs'] as $attr_name => $attr_value ) {
										$tags_write->set_attribute( $attr_name, $attr_value );
									}
									return str_replace( $img_tag, $tags_write->get_updated_html(), $matches[0] );
								}
							}
						}
						return $matches[0];
					}

					// img still has src — extract it and run full processing.
					$tags_src = new \WP_HTML_Tag_Processor( $img_tag );
					if ( $tags_src->next_tag( array( 'tag_name' => 'img' ) ) ) {
						$src_val = $tags_src->get_attribute( 'src' );
						if ( $src_val ) {
							$original_src = $src_val;
						}
					}
					$processed_img = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					return str_replace( $img_tag, $processed_img, $matches[0] );
				}

				return $matches[0];
			} else {
				// Regex Fallback (Original logic restored from git history).
				if ( ! preg_match( '#<picture\b[^>]*>.*?</picture>#is', $matches[0] ) ) {

					$img_tag = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

					if ( ! isset( $this->options['image_optimisation']['wrapInPicture'] ) || (bool) $this->options['image_optimisation']['wrapInPicture'] ) {
						$srcset = '';
						if ( preg_match( '#\b(?:data-)?srcset=["\']([^"\']+)["\']#i', $img_tag, $srcset_matches ) ) {
							$srcset = $srcset_matches[1];
						}

						$sizes = '';
						if ( preg_match( '#\b(?:data-)?sizes=["\']([^"\']+)["\']#i', $img_tag, $sizes_matches ) ) {
							$sizes = $sizes_matches[1];
						}

						$is_lazy = (bool) strpos( $img_tag, 'data-src' );
						// AVIF-first <picture> output: AVIF source, WebP source, original <img> fallback.
						$source_tag = $this->build_avif_first_sources( $original_src, (string) $srcset, (string) $sizes, $is_lazy, $should_exclude );

						// Wrap <img> tag inside <picture>.
						$img_tag = '<picture>' . $source_tag . $img_tag . '</picture>';
					}
					return $img_tag;
				} else {
					preg_match( '#<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#i', $matches[0], $img_matches );
					if ( ! empty( $img_matches ) ) {
						$img_tag      = $img_matches[0];
						$original_src = $img_matches[2];
						$img_tag      = $this->process_img_tag( $img_tag, $original_src, $exclude_imgs );

						return preg_replace( '#<img\b[^>]*?>#i', $img_tag, $matches[0] );
					}
				}

				return $matches[0];
			}
		}

		/**
		 * Whether LCP prioritization already ran on this instance's buffer.
		 *
		 * One-shot per instance (issue #881 review): the 6.9+ enhancement
		 * filter and the legacy fallback buffer share this instance via Main;
		 * a mid-request flip of the enhancement check could otherwise run LCP
		 * prioritization twice. The attribute set is idempotent, the scan is
		 * not — the second pass is skipped.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private bool $lcp_priority_applied = false;

		/**
		 * Post-render LCP image prioritization (optional enhancement).
		 *
		 * When the "prioritizeLCPImages" toggle is enabled, this filter callback
		 * runs on the finalized HTML (WP 6.9+ template-enhancement output buffer,
		 * or the legacy outermost output buffer on older WP) and:
		 *
		 * 1. Removes `loading="lazy"` from the first N images (matching the
		 *    `excludeFirstImages` heuristic) so above-the-fold images load eagerly.
		 * 2. Sets `fetchpriority="high"` on the detected LCP <img> unless the
		 *    attribute already exists, preserving core's own loading-optimization
		 *    decisions and the plugin's existing excludeFirstImages handling.
		 *    The matched node never keeps `loading="lazy"` alongside
		 *    `fetchpriority="high"`.
		 * 3. When the `cssHeroPreload` toggle is enabled and the LCP target is
		 *    a CSS background hero (no matching <img>), injects exactly one
		 *    `<link rel="preload" as="image">` tag before `</head>`.
		 *
		 * Uses `WP_HTML_Processor::serialize_token()` (public since WP 6.9) when
		 * available, falling back to `WP_HTML_Tag_Processor` on older versions.
		 *
		 * @since 2.0.0
		 *
		 * @param string $filtered_output The filtered output from previous callbacks.
		 * @param string $output          The raw output buffer content (unused; present
		 *                                for parity with the 6.9 filter signature and
		 *                                safe when used as an ob_start callback).
		 * @return string The processed buffer.
		 */
		public function prioritize_lcp_in_buffer( $filtered_output, $output = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
			// Mid-template cancel safety (issue #1386): a cancelled core
			// buffer can deliver a non-string (false/null) into the filter.
			// An output-buffer callback must always return a string —
			// fail open to '' without consuming the one-shot so a later
			// real pass can still run.
			if ( ! is_string( $filtered_output ) ) {
				return '';
			}
			// One-shot per instance (issue #881 review): the 6.9+ enhancement
			// filter and the legacy fallback buffer both call this method on
			// the shared Main instance; a mid-request flip of the enhancement
			// check could otherwise run LCP prioritization twice.
			if ( $this->lcp_priority_applied ) {
				return $filtered_output;
			}
			$this->lcp_priority_applied = true;

			$image_optimisation  = $this->options['image_optimisation'] ?? array();
			$prioritize_enabled  = ! empty( $image_optimisation['prioritizeLCPImages'] );
			$css_preload_enabled = ! empty( $image_optimisation['cssHeroPreload'] );
			if ( ! $prioritize_enabled && ! $css_preload_enabled ) {
				return $filtered_output;
			}
			if ( is_admin() || empty( $filtered_output ) || ! is_string( $filtered_output ) ) {
				return $filtered_output;
			}
			// Same logged-in eligibility guard as the legacy buffer path so both
			// pipelines behave identically for the current user.
			if ( ! Util::is_cache_eligible_for_current_user( $this->options['cache_settings'] ?? array() ) ) {
				return $filtered_output;
			}
			// CSS-hero pages may contain no <img> tags at all; let those through
			// to Pass C when the CSS hero preload is enabled.
			$css_hero_eligible = $css_preload_enabled && false !== stripos( $filtered_output, 'background' );
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) || ( false === stripos( $filtered_output, '<img' ) && ! $css_hero_eligible ) ) {
				return $filtered_output;
			}

			// Throwable-safe: this method runs as an output-buffer callback (legacy
			// path) and as the wp_template_enhancement_output_buffer filter (WP 6.9+).
			// It must always return a string or the page output would be lost/corrupted
			// (audit #888 finding 12 — balanced buffer lifecycle).
			try {
				$buffer = $filtered_output;

				// Resolve the LCP URL once per buffer so Pass B and Pass C
				// share it instead of re-reading options and the RUM
				// aggregate option on every request. Uses the unified
				// chain (OD → stored PageSpeed → DOM-first heuristic) so a
				// detectable hero still preloads when stored data is absent.
				// Fail-open: detection failure leaves $lcp_url empty and the
				// markup unmodified, never fatal.
				//
				// @since NEXT Unified resolution via resolve_auto_lcp_url().
				$lcp_url = '';
				if ( $prioritize_enabled ) {
					try {
						if ( method_exists( $this, 'resolve_auto_lcp_url' ) ) {
							$lcp_url = $this->resolve_auto_lcp_url( $filtered_output );
						} else {
							$lcp_url = $this->get_current_lcp_url();
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$lcp_url = '';
					}
					if ( ! is_string( $lcp_url ) ) {
						$lcp_url = '';
					}
				}
				if ( $prioritize_enabled ) {
					// Pass A: un-lazy-load the first N above-the-fold images.
					$buffer = $this->unlazyload_first_images( $buffer, $image_optimisation );

					// Pass B: stamp fetchpriority="high" on the detected LCP image.
					// The stamp always removes loading="lazy" from the same node,
					// so fetchpriority="high" and loading="lazy" never combine.
					$buffer = $this->prioritize_lcp_image( $buffer, $lcp_url );
				}

				// Pass C: CSS background hero (issue #935) — exactly one preload
				// link when the hero is a CSS background; no-ops unless the
				// cssHeroPreload toggle is enabled. Shares the unified $lcp_url
				// so all passes stamp/preload the same target.
				$buffer = $this->maybe_inject_css_hero_preload( $buffer, ( $prioritize_enabled ? $lcp_url : null ) );

				// Pass C: hero fallback + companion preload link (fail-open, never lazy).
				// Shares the unified $lcp_url; falls back to buffer resolution
				// when prioritization is off but the hero preload is enabled.
				// Both Pass C emitters share the centralised hero slot so the
				// hero keeps exactly one preload with eager plus high
				// (issue #1312); manual lists already claimed the slot first.
				$buffer = $this->maybe_preload_hero_image( $buffer, $image_optimisation, ( $prioritize_enabled ? $lcp_url : null ) );

				// Pass D: final lazy/high invariant sweep (issue #1312) — any
				// element marked fetchpriority high is forced eager so lazy
				// plus high pairs are never emitted together. Attribute-only
				// rewrite, no layout change (no CLS).
				$buffer = $this->sweep_lazy_high_conflicts( $buffer );

				return $buffer;
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO LCP prioritization failed: ' . $e->getMessage(), array( 'exception' => $e ) );
				return $filtered_output;
			}
		}

		/**
		 * Stamp fetchpriority="high" on the LCP attachment at render time.
		 *
		 * `wp_get_attachment_image_attributes` filter callback (issue #1234):
		 * when the attachment being rendered matches the resolved LCP
		 * candidate, stamps `fetchpriority="high"` with `loading="eager"`
		 * (any `loading="lazy"` is replaced) and `decoding="async"` when
		 * absent, so attachment images rendered by core carry the correct
		 * priority hint without regex post-processing. Core-parity by
		 * delegation: the hint is stamped where core builds the `<img>`
		 * tag, so it composes with core 6.3+ loading optimization output
		 * instead of fighting it.
		 *
		 * The LCP candidate reuses the existing no-new-queries chain:
		 * manual picker + Optimization Detective real-visit data
		 * (`resolve_od_only_lcp_url()`), then the stored chain
		 * (`get_current_lcp_url()` — RUM-field override + stored
		 * PageSpeed). The DOM-heuristic tier is skipped (no buffer in
		 * filter context). The candidate is resolved at most once per
		 * page via the `$fetchpriority_lcp_url` memo (keyed by
		 * `get_lcp_memo_key()`), since this filter fires per image.
		 * Core's stateful
		 * `wp_get_loading_optimization_attributes()` is deliberately not
		 * consulted here: a second direct call would double-count this
		 * image in core's per-context counter and skew core's later
		 * lazy/eager decisions.
		 *
		 * Size-aware matching: the rendered file must correspond to the
		 * LCP candidate exactly (size suffix preserved) before stamping,
		 * so a below-fold thumbnail reuse of the same attachment is left
		 * lazy. The size-suffix-insensitive fallback applies only when
		 * the requested `$size` is `'full'`, where whatever file core
		 * returns for the attachment is the hero itself.
		 *
		 * Fail-open: any failure (unresolvable candidate, missing core
		 * API, unexpected input) returns `$attr` unchanged, never fatal.
		 *
		 * @since NEXT
		 *
		 * @param mixed $attr       Image attributes (expected array).
		 * @param mixed $attachment Attachment post object, ID, or array with ID.
		 * @param mixed $size       Requested image size.
		 * @return mixed The (possibly stamped) attributes, unchanged on miss.
		 */
		public function wppo_add_fetchpriority( $attr, $attachment = null, $size = null ) {
			try {
				if ( ! is_array( $attr ) ) {
					return $attr;
				}
				$image_optimisation = $this->options['image_optimisation'] ?? array();
				if ( empty( $image_optimisation['prioritizeLCPImages'] ) ) {
					return $attr;
				}
				if ( function_exists( 'is_admin' ) ) {
					try {
						if ( is_admin() ) {
							return $attr;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return $attr;
					}
				}
				$lcp_url = '';
				try {
					$lcp_url = $this->resolve_fetchpriority_lcp_url();
				} catch ( \Throwable $e ) {
					unset( $e );
					$lcp_url = '';
				}
				if ( ! is_string( $lcp_url ) || '' === $lcp_url ) {
					return $attr;
				}
				try {
					if ( ! $this->is_image_lcp_url( $lcp_url ) || ! $this->is_allowed_hero_preload_url( $lcp_url ) ) {
						return $attr;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $attr;
				}
				$normalized_lcp = $this->normalize_image_url( $lcp_url );
				if ( '' === $normalized_lcp ) {
					return $attr;
				}
				$exact_lcp = $this->normalize_image_url( $lcp_url, false );
				if ( '' === $exact_lcp ) {
					return $attr;
				}
				$size_is_full  = ( 'full' === $size );
				$is_lcp        = false;
				$attachment_id = 0;
				if ( is_object( $attachment ) && isset( $attachment->ID ) ) {
					$attachment_id = (int) $attachment->ID;
				} elseif ( is_numeric( $attachment ) ) {
					$attachment_id = (int) $attachment;
				} elseif ( is_array( $attachment ) && isset( $attachment['ID'] ) && is_numeric( $attachment['ID'] ) ) {
					$attachment_id = (int) $attachment['ID'];
				}
				if ( $attachment_id > 0 && function_exists( 'wp_get_attachment_image_src' ) ) {
					try {
						$lookup_size = ( null === $size || '' === $size ) ? 'thumbnail' : $size;
						$src_data    = wp_get_attachment_image_src( $attachment_id, $lookup_size );
						$candidate   = '';
						if ( is_array( $src_data ) && isset( $src_data[0] ) && is_string( $src_data[0] ) ) {
							$candidate = $src_data[0];
						} elseif ( is_string( $src_data ) ) {
							$candidate = $src_data;
						}
						if ( '' !== $candidate && $this->fetchpriority_candidate_matches( $candidate, $normalized_lcp, $exact_lcp, $size_is_full ) ) {
							$is_lcp = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( ! $is_lcp ) {
					// Fallback when the attachment ID is unresolvable (bare
					// array context): compare the built src/srcset against
					// the candidate with normalized-URL equality only (no
					// substring fallback, mirroring tag_matches_lcp_url()).
					// The src/data-src entries use the same size-aware rule
					// as the ID path; srcset entries require an exact match
					// (a srcset inherently lists sized variants, so a
					// suffix-insensitive fallback would stamp every image
					// whose srcset merely contains a thumbnail of the hero).
					foreach ( array( 'src', 'data-src' ) as $key ) {
						if ( isset( $attr[ $key ] ) && is_string( $attr[ $key ] ) && '' !== $attr[ $key ] && $this->fetchpriority_candidate_matches( $attr[ $key ], $normalized_lcp, $exact_lcp, $size_is_full ) ) {
							$is_lcp = true;
							break;
						}
					}
					if ( ! $is_lcp && isset( $attr['srcset'] ) && is_string( $attr['srcset'] ) && '' !== $attr['srcset'] ) {
						$candidates = preg_split( '/\s*,\s*/', trim( $attr['srcset'] ) );
						if ( is_array( $candidates ) ) {
							foreach ( $candidates as $candidate ) {
								$parts         = preg_split( '/\s+/', trim( (string) $candidate ), 2 );
								$candidate_url = is_array( $parts ) && isset( $parts[0] ) ? $parts[0] : '';
								if ( '' !== $candidate_url && $this->normalize_image_url( $candidate_url, false ) === $exact_lcp ) {
									$is_lcp = true;
									break;
								}
							}
						}
					}
				}
				if ( ! $is_lcp ) {
					return $attr;
				}
				// Stamp the hero triple: eager first so the core-parity
				// invariant (never lazy + high) always holds, then high,
				// then the decoding default when absent.
				$attr['loading']       = 'eager';
				$attr['fetchpriority'] = 'high';
				if ( ! isset( $attr['decoding'] ) || ! is_string( $attr['decoding'] ) || '' === $attr['decoding'] ) {
					$attr['decoding'] = 'async';
				}
				return $this->sanitize_loading_triple( $attr );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $attr;
			}
		}

		/**
		 * Resolve the LCP candidate for the render-time fetchpriority filter, memoized per page.
		 *
		 * Same chain as the filter needs on every image render (manual
		 * picker + Optimization Detective via `resolve_od_only_lcp_url()`,
		 * then the stored chain via `get_current_lcp_url()`), but resolved
		 * at most once per page: the result is cached in
		 * `$fetchpriority_lcp_url` keyed by `get_lcp_memo_key()` so a page
		 * with N images pays the OD/manual chain once instead of N times.
		 * The DOM-heuristic tier is skipped (no buffer in filter context).
		 * Fail-open to ''.
		 *
		 * @since NEXT
		 * @return string The validated LCP image URL, or empty string.
		 */
		private function resolve_fetchpriority_lcp_url(): string {
			$memo_key = $this->get_lcp_memo_key();
			if ( null !== $this->fetchpriority_lcp_url && $this->fetchpriority_lcp_key === $memo_key ) {
				return $this->fetchpriority_lcp_url;
			}
			$this->fetchpriority_lcp_url = '';
			$this->fetchpriority_lcp_key = $memo_key;
			try {
				$lcp_url = $this->resolve_od_only_lcp_url();
				if ( ! is_string( $lcp_url ) || '' === $lcp_url ) {
					$lcp_url = $this->get_current_lcp_url();
				}
				if ( ! is_string( $lcp_url ) || '' === $lcp_url ) {
					return $this->fetchpriority_lcp_url;
				}
				if ( ! $this->is_image_lcp_url( $lcp_url ) || ! $this->is_allowed_hero_preload_url( $lcp_url ) ) {
					return $this->fetchpriority_lcp_url;
				}
				$this->fetchpriority_lcp_url = $lcp_url;
			} catch ( \Throwable $e ) {
				unset( $e );
				$this->fetchpriority_lcp_url = '';
			}
			return $this->fetchpriority_lcp_url;
		}

		/**
		 * Size-aware LCP candidate comparison for the fetchpriority filter.
		 *
		 * An exact (size-suffix-preserving) normalized match always stamps,
		 * so the hero file itself is recognized at any requested size. A
		 * size-suffix-insensitive match stamps only when the requested
		 * `$size` is `'full'`, where the file core returns for the
		 * attachment is the hero itself — a thumbnail/sidebar reuse of the
		 * same attachment at a smaller size stays lazy. Fail-open to false.
		 *
		 * @since NEXT
		 * @param string $candidate      The rendered file URL to test.
		 * @param string $normalized_lcp Normalized LCP URL (size suffix stripped).
		 * @param string $exact_lcp      Normalized LCP URL (size suffix preserved).
		 * @param bool   $size_is_full   Whether the requested image size is 'full'.
		 * @return bool True when the candidate corresponds to the LCP image.
		 */
		private function fetchpriority_candidate_matches( string $candidate, string $normalized_lcp, string $exact_lcp, bool $size_is_full ): bool {
			try {
				if ( '' === $candidate || '' === $normalized_lcp || '' === $exact_lcp ) {
					return false;
				}
				if ( $this->normalize_image_url( $candidate, false ) === $exact_lcp ) {
					return true;
				}
				if ( $size_is_full && $this->normalize_image_url( $candidate ) === $normalized_lcp ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Remove lazy-loading from the first N images in the buffer.
		 *
		 * Mirrors the excludeFirstImages heuristic used by add_delay_load_img() so
		 * the same count semantics apply to the finalized HTML. Images carrying
		 * either `src` or a JS-lazy `data-src` placeholder are counted, so a
		 * JS-lazy hero is never missed. For each of the first N images the
		 * transform is fail-open per node: `loading="lazy"` is stripped and
		 * replaced with `loading="eager"`, JS-lazy placeholders are restored
		 * (`data-src` to `src`, `data-srcset` to `srcset`, `data-sizes` to
		 * `sizes`), lazy classes are removed, and `decoding="async"` is
		 * stamped when absent. Nodes that fail to parse keep their markup.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer             The HTML buffer.
		 * @param array  $image_optimisation Image optimization settings.
		 * @return string The buffer with lazy-loading removed from the first N images.
		 */
		private function unlazyload_first_images( string $buffer, array $image_optimisation ): string {
			$exclude_img_count = $this->get_effective_exclude_first_images_count( $image_optimisation );
			if ( $exclude_img_count <= 0 ) {
				return $buffer;
			}

			try {
				$tags        = new \WP_HTML_Tag_Processor( $buffer );
				$img_counter = 0;
				$changed     = false;

				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$src      = $tags->get_attribute( 'src' );
					$data_src = $tags->get_attribute( 'data-src' );
					if ( ( null === $src || '' === $src ) && ( null === $data_src || '' === $data_src ) ) {
						continue;
					}

					++$img_counter;
					if ( $img_counter > $exclude_img_count ) {
						break;
					}
					try {
						if ( $this->restore_js_lazy_placeholders( $tags ) ) {
							$changed = true;
						}
						if ( $this->remove_lazy_classes( $tags ) ) {
							$changed = true;
						}
						if ( 'lazy' === $tags->get_attribute( 'loading' ) ) {
							$tags->remove_attribute( 'loading' );
							$changed = true;
						}
						if ( null === $tags->get_attribute( 'loading' ) ) {
							$tags->set_attribute( 'loading', 'eager' );
							$changed = true;
						}
						if ( null === $tags->get_attribute( 'decoding' ) ) {
							$tags->set_attribute( 'decoding', 'async' );
							$changed = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
				}

				$result = $changed ? $tags->get_updated_html() : $buffer;
				return $this->promote_eager_picture_sources( $result );
			} catch ( \Throwable $e ) {
				return $buffer;
			}
		}

		/**
		 * Restore JS-lazy placeholder attributes on the current tag.
		 *
		 * Promotes `data-src` to `src`, `data-srcset` to `srcset` and
		 * `data-sizes` to `sizes` (non-empty values only; empty placeholders
		 * are dropped). Fail-open per attribute: any failure leaves the tag
		 * untouched.
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_HTML_Tag_Processor|\WP_HTML_Processor $tags The tag processor matched on an <img>.
		 * @return bool True when any attribute was changed.
		 */
		private function restore_js_lazy_placeholders( $tags ): bool {
			$changed = false;
			try {
				$data_src = $tags->get_attribute( 'data-src' );
				if ( is_string( $data_src ) && '' !== $data_src ) {
					$tags->set_attribute( 'src', $data_src );
					$tags->remove_attribute( 'data-src' );
					$changed = true;
				}
				$data_srcset = $tags->get_attribute( 'data-srcset' );
				if ( null !== $data_srcset ) {
					if ( '' !== $data_srcset ) {
						$tags->set_attribute( 'srcset', (string) $data_srcset );
					}
					$tags->remove_attribute( 'data-srcset' );
					$changed = true;
				}
				$data_sizes = $tags->get_attribute( 'data-sizes' );
				if ( null !== $data_sizes ) {
					if ( '' !== $data_sizes ) {
						$tags->set_attribute( 'sizes', (string) $data_sizes );
					}
					$tags->remove_attribute( 'data-sizes' );
					$changed = true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $changed;
		}

		/**
		 * Promote lazy `<source>` placeholders inside `<picture>` blocks whose
		 * IMG was stamped eager.
		 *
		 * `WP_HTML_Tag_Processor` is forward-only and exposes no parent node,
		 * so responsive heroes are handled in a second pass: for each
		 * `<picture>` block containing a `loading="eager"` image, sibling
		 * `<source data-srcset>`/`data-sizes` placeholders are promoted to
		 * `srcset`/`sizes`. Fail-open: any parse failure returns the buffer
		 * unchanged.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The buffer with eager-picture sources promoted.
		 */
		private function promote_eager_picture_sources( string $buffer ): string {
			try {
				if ( false === stripos( $buffer, '<picture' ) ) {
					return $buffer;
				}
				// WP 6.9+: extract <picture> blocks via the serialize_token()
				// builder with depth tracking so nested/unbalanced markup is
				// handled without PCRE; inner <source> promotion stays shared.
				if ( $this->should_use_html_processor() ) {
					$via_processor = $this->promote_eager_picture_sources_with_processor( $buffer );
					if ( null !== $via_processor ) {
						return $via_processor;
					}
				}
				$updated = preg_replace_callback(
					'#<picture\b[^>]*>.*?</picture>#is',
					function ( array $m ): string {
						return $this->promote_eager_sources_in_block( $m[0] );
					},
					$buffer
				);
				return is_string( $updated ) ? $updated : $buffer;
			} catch ( \Throwable $e ) {
				return $buffer;
			}
		}

		/**
		 * Promote lazy `<source>` placeholders inside a single `<picture>` block.
		 *
		 * Shared by the `serialize_token()` builder path and the regex fallback
		 * so both stay behaviorally identical: blocks without an eager image
		 * pass through untouched, otherwise sibling `<source data-srcset>` /
		 * `data-sizes` placeholders are promoted. Fail-open per tag.
		 *
		 * @since NEXT
		 *
		 * @param string $block Serialized `<picture>...</picture>` block.
		 * @return string The block with eager-picture sources promoted.
		 */
		private function promote_eager_sources_in_block( string $block ): string {
			if ( false === stripos( $block, 'loading="eager"' ) && false === stripos( $block, "loading='eager'" ) ) {
				return $block;
			}
			$rebuilt = preg_replace_callback(
				'#<source\b[^>]*>#i',
				function ( array $sm ): string {
					try {
						if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
							return $sm[0];
						}
						$source = new \WP_HTML_Tag_Processor( $sm[0] );
						if ( ! $source->next_tag( array( 'tag_name' => 'source' ) ) ) {
							return $sm[0];
						}
						if ( ! $this->restore_js_lazy_placeholders( $source ) ) {
							return $sm[0];
						}
						return $source->get_updated_html();
					} catch ( \Throwable $e ) {
						unset( $e );
						return $sm[0];
					}
				},
				$block
			);
			return is_string( $rebuilt ) ? $rebuilt : $block;
		}

		/**
		 * Promote eager-picture sources via the WP 6.9+ HTML API token stream.
		 *
		 * Walks tokens with `serialize_token()` and depth tracking to extract
		 * each outer `<picture>` block, then delegates per-block promotion to
		 * {@see promote_eager_sources_in_block()}. Returns null when the
		 * processor is unavailable or the token stream ends with a parse
		 * error so the caller falls back to the byte-identical regex path.
		 *
		 * @since NEXT
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string|null The buffer with sources promoted, or null on failure.
		 */
		private function promote_eager_picture_sources_with_processor( string $buffer ): ?string {
			$processor = Util::create_html_processor( $buffer );
			if ( null === $processor ) {
				return null;
			}
			try {
				$out          = '';
				$in_picture   = false;
				$nesting      = 0;
				$picture_html = '';
				while ( $processor->next_token() ) {
					$type = $processor->get_token_type();
					if ( '#tag' !== $type ) {
						$tok = (string) $processor->serialize_token();
						if ( $in_picture ) {
							$picture_html .= $tok;
						} else {
							$out .= $tok;
						}
						continue;
					}
					$is_closer = $processor->is_tag_closer();
					$tag       = $processor->get_tag();
					if ( ! $in_picture && 'PICTURE' === $tag && ! $is_closer ) {
						$in_picture   = true;
						$nesting      = 1;
						$picture_html = (string) $processor->serialize_token();
						continue;
					}
					if ( $in_picture ) {
						$picture_html .= (string) $processor->serialize_token();
						if ( 'PICTURE' === $tag ) {
							if ( ! $is_closer ) {
								++$nesting;
							} else {
								--$nesting;
								if ( 0 === $nesting ) {
									$out         .= $this->promote_eager_sources_in_block( $picture_html );
									$in_picture   = false;
									$picture_html = '';
									$nesting      = 0;
								}
							}
						}
						continue;
					}
					$out .= (string) $processor->serialize_token();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
			if ( method_exists( $processor, 'get_last_error' ) && null !== $processor->get_last_error() ) {
				return null;
			}
			if ( $in_picture && '' !== $picture_html ) {
				$out .= $this->promote_eager_sources_in_block( $picture_html );
			}
			return $out;
		}

		/**
		 * Strip JS-lazy placeholder classes from the current IMG tag.
		 *
		 * Removes `wppo-lazy`, `wppo-lazyload`, `lazyload`, `lazyloaded`,
		 * `lazyloading` and the bare `lazy` token (exact-token match, so
		 * classes like `lazy-button` are preserved) while keeping all other
		 * classes. No-op when the tag carries no class attribute.
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_HTML_Tag_Processor|\WP_HTML_Processor $tags The tag processor matched on an <img>.
		 * @return bool True when a class token was stripped.
		 */
		private function remove_lazy_classes( $tags ): bool {
			$class = $tags->get_attribute( 'class' );
			if ( null === $class ) {
				return false;
			}
			$lazy_tokens = array( 'wppo-lazy', 'wppo-lazyload', 'lazyload', 'lazyloaded', 'lazyloading', 'lazy' );
			$tokens      = preg_split( '/\s+/', (string) $class, -1, PREG_SPLIT_NO_EMPTY );
			if ( ! is_array( $tokens ) ) {
				return false;
			}
			$kept = array_values( array_diff( $tokens, $lazy_tokens ) );
			if ( count( $kept ) === count( $tokens ) ) {
				return false;
			}
			if ( empty( $kept ) ) {
				$tags->remove_attribute( 'class' );
			} else {
				$tags->set_attribute( 'class', implode( ' ', $kept ) );
			}
			return true;
		}

		/**
		 * Stamp the hero (LCP) triple: high fetchpriority + eager loading.
		 *
		 * Merges core's fetchpriority/decoding decision first (gap-fill only,
		 * never core's loading value), then forces eager + high so the hero is
		 * never lazy. Guarantees one valid triple per element (never lazy+high).
		 *
		 * @since NEXT
		 *
		 * @param object $tags Tag/HTML processor positioned on the hero <img>.
		 * @return bool True when any attribute was added, changed, or removed.
		 */
		private function stamp_hero_loading_triple( $tags ): bool {
			$changed = false;
			// Merge core's decision first (gap-fill only, never core's loading
			// value), but stamp in legacy order: fetchpriority, loading, decoding.
			$core_decoding      = null;
			$core_fetchpriority = null;
			if ( function_exists( 'wp_get_loading_optimization_attributes' ) && null === $tags->get_attribute( 'decoding' ) ) {
				$tag_attr = array();
				$src      = $tags->get_attribute( 'src' );
				if ( null === $src ) {
					$src = $tags->get_attribute( 'data-src' );
				}
				if ( null !== $src ) {
					$tag_attr['src'] = $src;
				}
				$core = $this->sanitize_loading_triple( $this->merge_core_loading_attributes( $tag_attr, 'wp-html-tag-processor' ) );
				if ( isset( $core['fetchpriority'] ) && 'high' === $core['fetchpriority'] ) {
					// Core only ever marks the hero high; a non-high hint means
					// core did not recognise this node as LCP, so the hero keeps
					// fetchpriority=high below.
					$core_fetchpriority = 'high';
				}
				if ( isset( $core['decoding'] ) ) {
					$core_decoding = $core['decoding'];
				}
			}
			if ( $this->restore_js_lazy_placeholders( $tags ) ) {
				$changed = true;
			}
			if ( $this->remove_lazy_classes( $tags ) ) {
				$changed = true;
			}
			if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
				$tags->set_attribute( 'fetchpriority', null !== $core_fetchpriority ? $core_fetchpriority : 'high' );
				$changed = true;
			}
			if ( 'lazy' === $tags->get_attribute( 'loading' ) ) {
				$tags->remove_attribute( 'loading' );
				$changed = true;
			}
			if ( null === $tags->get_attribute( 'loading' ) ) {
				$tags->set_attribute( 'loading', 'eager' );
				$changed = true;
			}
			if ( null === $tags->get_attribute( 'decoding' ) ) {
				$tags->set_attribute( 'decoding', null !== $core_decoding ? $core_decoding : 'async' );
				$changed = true;
			}
			return $changed;
		}

		/**
		 * Set fetchpriority="high" on the detected LCP image.
		 *
		 * Stamps `fetchpriority="high"` on the matching <img> only when no fetchpriority
		 * attribute already exists, so core's wp_get_loading_optimization_attributes()
		 * output and the plugin's existing excludeFirstImages high-priority assignment
		 * are never double-applied. The matched LCP image is also un-lazy-loaded so an
		 * in-viewport LCP image is actually fetched eagerly at high priority:
		 * `loading="lazy"` is replaced with `loading="eager"` and
		 * `decoding="async"` is stamped when absent (progressive enhancement,
		 * ignored by old browsers).
		 *
		 * @since 2.0.0
		 * @since NEXT Callers pass the unified `resolve_auto_lcp_url()` target so
		 * the never-lazy/fetchpriority stamp always matches the preloaded URL.
		 *
		 * @param string      $buffer  The HTML buffer.
		 * @param string|null $lcp_url Optional pre-resolved LCP URL. When null the
		 *                             URL is resolved via resolve_auto_lcp_url()
		 *                             (same-origin guarded OD/stored/heuristic
		 *                             chain; the stored tier internally reads
		 *                             get_current_lcp_url()).
		 * @return string The buffer with fetchpriority="high" on the LCP image.
		 */
		private function prioritize_lcp_image( string $buffer, ?string $lcp_url = null ): string {
			if ( null === $lcp_url ) {
				$lcp_url = $this->resolve_auto_lcp_url( $buffer );
			}
			if ( empty( $lcp_url ) || false === stripos( $buffer, '<img' ) ) {
				return $buffer;
			}

			// WP 6.9+: stream tokens via the shared guarded factory and rebuild
			// with the serialize_token() builder. Falls back to the Tag Processor
			// path below when the serializer is unavailable (WP <6.9) or the
			// parse fails, preserving byte-identical legacy output.
			if ( $this->should_use_html_processor() ) {
				$processor = Util::create_html_processor( $buffer );
				if ( null !== $processor ) {
					try {
						$new_html = '';
						$stamped  = false;
						while ( $processor->next_token() ) {
							if (
							'#tag' === $processor->get_token_type() &&
							'IMG' === $processor->get_tag() &&
							! $processor->is_tag_closer() &&
							! $stamped &&
							$this->tag_matches_lcp_url( $processor, $lcp_url )
							) {
								$this->restore_js_lazy_placeholders( $processor );
								$this->remove_lazy_classes( $processor );
								// Explicit core-stamp guard (issue #1180): an
								// already-stamped fetchpriority (core, theme,
								// or prior pass) is never overridden. When
								// absent the measured hero keeps `high`
								// (field truth beats the core heuristic).
								$core_verdict = $this->get_core_loading_verdict_for_tag( $processor );
								if ( null === $processor->get_attribute( 'fetchpriority' ) ) {
									$processor->set_attribute( 'fetchpriority', 'high' );
								}
								if ( 'lazy' === $processor->get_attribute( 'loading' ) ) {
									$processor->remove_attribute( 'loading' );
								}
								if ( null === $processor->get_attribute( 'loading' ) ) {
									$processor->set_attribute( 'loading', 'eager' );
								}
								if ( null === $processor->get_attribute( 'decoding' ) ) {
									$core_decoding = is_array( $core_verdict ) ? ( $core_verdict['decoding'] ?? null ) : null;
									$processor->set_attribute( 'decoding', is_string( $core_decoding ) && '' !== $core_decoding ? $core_decoding : 'async' );
								}
								$stamped = true;
							}
							$new_html .= (string) $processor->serialize_token();
						}

						// Parser bailed on unsupported markup; fall through to the
						// Tag Processor fallback instead of returning corrupt HTML.
						$parse_ok = ! method_exists( $processor, 'get_last_error' ) || null === $processor->get_last_error();
						if ( $parse_ok ) {
							return $stamped ? $this->promote_eager_picture_sources( $new_html ) : $buffer;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}

			// Fallback: WP_HTML_Tag_Processor on all supported versions; fail-open
			// to the unmodified buffer when the Tag Processor is unavailable.
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return $buffer;
			}
			try {
				$tags    = new \WP_HTML_Tag_Processor( $buffer );
				$stamped = false;
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					if ( $this->tag_matches_lcp_url( $tags, $lcp_url ) ) {
						$this->restore_js_lazy_placeholders( $tags );
						$this->remove_lazy_classes( $tags );
						// Explicit core-stamp guard (issue #1180): never
						// override an already-stamped fetchpriority; when
						// absent the measured hero keeps `high`
						// (field truth beats the core heuristic).
						$core_verdict = $this->get_core_loading_verdict_for_tag( $tags );
						if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
							$tags->set_attribute( 'fetchpriority', 'high' );
						}
						if ( 'lazy' === $tags->get_attribute( 'loading' ) ) {
							$tags->remove_attribute( 'loading' );
						}
						if ( null === $tags->get_attribute( 'loading' ) ) {
							$tags->set_attribute( 'loading', 'eager' );
						}
						if ( null === $tags->get_attribute( 'decoding' ) ) {
							$core_decoding = is_array( $core_verdict ) ? ( $core_verdict['decoding'] ?? null ) : null;
							$tags->set_attribute( 'decoding', is_string( $core_decoding ) && '' !== $core_decoding ? $core_decoding : 'async' );
						}
						$stamped = true;
						break;
					}
				}

				$updated = $tags->get_updated_html();
				if ( ! is_string( $updated ) ) {
					return $buffer;
				}
				return $stamped ? $this->promote_eager_picture_sources( $updated ) : $buffer;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $buffer;
			}
		}

		/**
		 * Hero fallback: ensure the first-viewport image preloads with fetchpriority=high and is never lazy.
		 *
		 * When stored LCP data exists the companion preload link is emitted for
		 * it; when detection fails the first <img src> in the buffer is treated
		 * as the hero (eager, fetchpriority high, never data-src lazy). Core's
		 * wp_get_loading_optimization_attributes() decision is honoured — gaps
		 * are only filled. Fail-open: any failure returns the buffer unchanged.
		 *
		 * @since 2.0.0
		 * @since NEXT Resolves via the unified `resolve_auto_lcp_url()` chain
		 * (OD → stored PageSpeed → in-viewport heuristic) and emits at most
		 * one preload link with `imagesrcset` when the hero carries a srcset.
		 * Accepts a pre-resolved LCP URL so all buffer passes share one target.
		 *
		 * @param string      $buffer             The HTML buffer.
		 * @param array       $image_optimisation Image optimisation settings.
		 * @param string|null $lcp_url            Optional pre-resolved LCP URL. When null
		 *                                        the URL is resolved via resolve_auto_lcp_url().
		 * @return string The buffer with hero preload link injected.
		 */
		private function maybe_preload_hero_image( string $buffer, array $image_optimisation, ?string $lcp_url = null ): string {
			try {
				if ( isset( $image_optimisation['lcpHeroPreload'] ) && empty( $image_optimisation['lcpHeroPreload'] ) ) {
					return $buffer;
				}
				if ( false === strpos( $buffer, '<img' ) ) {
					return $buffer;
				}

				if ( null === $lcp_url ) {
					$lcp_url = $this->resolve_auto_lcp_url( $buffer );
				}
				if ( '' === $lcp_url ) {
					return $buffer;
				}

				// Never lazy: strip loading=lazy + stamp fetchpriority high on the hero tag only when absent.
				// Guarded Tag Processor use (WP 6.2+): fail-open to the
				// unmodified buffer when the HTML API is unavailable, so the
				// OD-stamped LCP node is never double-stamped or corrupted.
				if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
					return $buffer;
				}
				$tags    = new \WP_HTML_Tag_Processor( $buffer );
				$changed = false;
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					if ( $this->tag_matches_lcp_url( $tags, $lcp_url ) ) {
						if ( $this->restore_js_lazy_placeholders( $tags ) ) {
							$changed = true;
						}
						if ( $this->remove_lazy_classes( $tags ) ) {
							$changed = true;
						}
						if ( 'lazy' === $tags->get_attribute( 'loading' ) ) {
							$tags->remove_attribute( 'loading' );
							$changed = true;
						}
						if ( null === $tags->get_attribute( 'loading' ) ) {
							$tags->set_attribute( 'loading', 'eager' );
							$changed = true;
						}
						// Explicit core-stamp guard (issue #1180): never
						// override an already-stamped fetchpriority; when
						// absent the measured hero keeps `high`
						// (field truth beats the core heuristic).
						$core_verdict = $this->get_core_loading_verdict_for_tag( $tags );
						if ( null === $tags->get_attribute( 'fetchpriority' ) ) {
							$tags->set_attribute( 'fetchpriority', 'high' );
							$changed = true;
						}
						if ( null === $tags->get_attribute( 'decoding' ) ) {
							$core_decoding = is_array( $core_verdict ) ? ( $core_verdict['decoding'] ?? null ) : null;
							$tags->set_attribute( 'decoding', is_string( $core_decoding ) && '' !== $core_decoding ? $core_decoding : 'async' );
							$changed = true;
						}
						if ( null === $tags->get_attribute( 'data-wppo-hero' ) ) {
							$tags->set_attribute( 'data-wppo-hero', '1' );
							$changed = true;
						}
						break;
					}
				}
				if ( $changed ) {
					$buffer = $this->promote_eager_picture_sources( $tags->get_updated_html() );
				}

				// Companion preload link (fetchpriority=high, exactly one). The
				// centralised slot consults the buffer plus every media
				// variant already emitted this request (wp_head manual +
				// auto, CSS-hero companion), then records this emission so a
				// later pass cannot double-emit the same hero (issue #1312).
				// Manual lists stay authoritative: they claim the slot first
				// in get_all_preload_data(), so automation only fills gaps.
				if ( ! $this->claim_hero_preload_slot( $lcp_url, '', $buffer ) ) {
					return $buffer;
				}
				$imagesrcset = $this->get_lcp_srcset_for_url( $lcp_url, $buffer );
				$imagesizes  = '' !== $imagesrcset ? $this->get_lcp_sizes_for_url( $lcp_url, $buffer ) : '';
				$link_tag    = Util::get_preload_link(
					$lcp_url,
					'preload',
					'image',
					false,
					Util::get_image_mime_type( $lcp_url ),
					'',
					'high',
					$imagesrcset,
					$imagesizes
				);
				if ( '' === $link_tag ) {
					// Link generation failed after the slot claim: release the
					// claim so the sibling emitter may still emit (issue #1312
					// review — claim-before-emit would otherwise suppress the
					// sibling into zero preloads instead of one).
					self::release_hero_preload_slot( $lcp_url, '' );
					return $buffer;
				}
				if ( false !== stripos( $buffer, '</head>' ) ) {
					$buffer = (string) preg_replace( '#</head>#i', $link_tag . "\n</head>", $buffer, 1 );
				} else {
					$buffer = $link_tag . "\n" . $buffer;
				}
				return $buffer;
			} catch ( \Throwable $e ) {
				return $buffer;
			}
		}

		/**
		 * Get the first <img src> URL in the buffer (hero fallback).
		 *
		 * DOM-order only (not viewport-aware): iterates `<img>` tags and
		 * returns the first non-trivial candidate, skipping tracking pixels,
		 * hidden nodes, and tiny dimensions so a logo/pixel does not consume
		 * the preload slot. Used only when no OD/stored LCP data exists.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string First image src, or empty string when none found.
		 */
		private function get_first_image_src_in_buffer( string $buffer ): string {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return '';
			}
			try {
				$tags     = new \WP_HTML_Tag_Processor( $buffer );
				$fallback = '';
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$src = $tags->get_attribute( 'src' );
					if ( ! is_string( $src ) || '' === $src ) {
						$data_src = $tags->get_attribute( 'data-src' );
						$src      = is_string( $data_src ) ? $data_src : '';
					}
					if ( ! is_string( $src ) || '' === trim( $src ) ) {
						continue;
					}
					if ( '' === $fallback ) {
						$fallback = $src;
					}
					if ( $this->is_trivial_heuristic_image( $tags, (string) $src ) ) {
						continue;
					}
					return $src;
				}
				return $fallback;
			} catch ( \Throwable $e ) {
				return '';
			}
		}

		/**
		 * Whether a heuristic `<img>` candidate is trivial (pixel/hidden/tiny).
		 *
		 * Skips tracking pixels (`pixel`/`tracking`/`spacer`/`1x1` in the URL),
		 * hidden nodes (`hidden` attribute or `display:none` /
		 * `visibility:hidden` inline style), and tiny dimensions (`width` /
		 * `height` attributes <= 10px). Fail-open: any failure returns false.
		 *
		 * @since NEXT
		 * @param \WP_HTML_Tag_Processor $tags The tag processor on the candidate `<img>`.
		 * @param string                 $src  The candidate src URL.
		 * @return bool True when the candidate should be skipped.
		 */
		private function is_trivial_heuristic_image( $tags, string $src ): bool {
			try {
				if ( 1 === preg_match( '/pixel|tracking|spacer|transparent|1x1|beacon/i', $src ) ) {
					return true;
				}
				if ( null !== $tags->get_attribute( 'hidden' ) ) {
					return true;
				}
				$style = $tags->get_attribute( 'style' );
				if ( is_string( $style ) && 1 === preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/i', $style ) ) {
					return true;
				}
				foreach ( array( 'width', 'height' ) as $dim ) {
					$val = $tags->get_attribute( $dim );
					if ( ( is_string( $val ) || is_int( $val ) ) && is_numeric( $val ) && (int) $val > 0 && (int) $val <= 10 ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return false;
		}

		/**
		 * Whether the buffer already contains a preload link for the image URL.
		 *
		 * Scans `<link>` tags whose `rel` token list contains `preload` and
		 * whose `as` attribute is either `image` or absent, then compares the
		 * normalized href (absolute-vs-relative agnostic) plus the raw query
		 * string, so versioned assets (`hero.jpg?v=1` vs `hero.jpg?v=2`)
		 * emit distinct hints. WordPress size-suffix variants only collapse
		 * when the requested URL itself carries a size suffix — a
		 * `hero-300x200.jpg` preload never suppresses the full-size
		 * `hero.jpg` hint. Fail-open: any parse failure returns false (emit
		 * the hint) rather than skipping it.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @param string $url    The image URL to look for.
		 * @return bool True when a matching preload link exists.
		 */
		private function buffer_has_image_preload( string $buffer, string $url ): bool {
			try {
				$needle = $this->normalize_image_url( $url );
				if ( '' === $needle ) {
					return false;
				}
				$needle_exact     = $this->normalize_image_url( $url, false );
				$needle_has_sizes = ( '' !== $needle_exact && $needle_exact !== $needle );
				$needle_query     = $this->get_url_query( $url );
				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$found = $this->buffer_has_image_preload_with_tag_processor( $buffer, $needle, $needle_exact, $needle_has_sizes, $needle_query );
					if ( null !== $found ) {
						return $found;
					}
				}
				if ( ! preg_match_all( '#<link\b[^>]*>#i', $buffer, $links ) ) {
					return false;
				}
				foreach ( $links[0] as $link ) {
					if ( ! preg_match( '#rel=["\']([^"\']*)["\']#i', $link, $rm ) ) {
						continue;
					}
					$rel_tokens = preg_split( '/\s+/', strtolower( trim( $rm[1] ) ), -1, PREG_SPLIT_NO_EMPTY );
					if ( ! is_array( $rel_tokens ) || ! in_array( 'preload', $rel_tokens, true ) ) {
						continue;
					}
					if ( preg_match( '#\bas\s*=\s*["\']([^"\']*)["\']#i', $link, $am ) && 'image' !== strtolower( trim( $am[1] ) ) ) {
						continue;
					}
					if ( ! preg_match( '#href=["\']([^"\']+)["\']#i', $link, $hm ) ) {
						continue;
					}
					if ( $this->normalize_image_url( $hm[1] ) !== $needle ) {
						continue;
					}
					if ( ! $needle_has_sizes && $this->normalize_image_url( $hm[1], false ) !== $needle_exact ) {
						continue;
					}
					if ( $this->get_url_query( $hm[1] ) !== $needle_query ) {
						continue;
					}
					return true;
				}
			} catch ( \Throwable $e ) {
				return false;
			}
			return false;
		}

		/**
		 * Tag Processor scan for an existing image preload link (WP 6.2+).
		 *
		 * Single-pass `next_tag()` traversal over `<link>` with
		 * `get_attribute()` reads, so the happy path never runs `preg_replace`
		 * on `<link>` tags. Mirrors the regex fallback matching exactly (rel
		 * token list contains `preload`, any present quoted `as` value —
		 * including an empty string — must equal `image` while an absent or
		 * boolean `as` counts as image-eligible, normalized-href plus
		 * raw-query comparison with size-suffix rules).
		 * Returns null when the processor is unavailable or throws so the
		 * caller falls through to the regex fallback. Fail-open: any parse
		 * failure returns null (caller then runs the legacy scan).
		 *
		 * @since NEXT
		 * @param string $buffer           The HTML buffer.
		 * @param string $needle           Normalized target URL.
		 * @param string $needle_exact     Normalized target URL without size-suffix collapsing.
		 * @param bool   $needle_has_sizes Whether the target itself carries a size suffix.
		 * @param string $needle_query     Raw query string of the target URL.
		 * @return bool|null True/false on success, null on failure (fallback).
		 */
		private function buffer_has_image_preload_with_tag_processor( string $buffer, string $needle, string $needle_exact, bool $needle_has_sizes, string $needle_query ): ?bool {
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return null;
			}
			try {
				if ( false === stripos( $buffer, '<link' ) ) {
					return false;
				}
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				while ( $tags->next_tag( array( 'tag_name' => 'link' ) ) ) {
					$rel = $tags->get_attribute( 'rel' );
					if ( ! is_string( $rel ) || '' === trim( $rel ) ) {
						continue;
					}
					$rel_tokens = preg_split( '/\s+/', strtolower( trim( $rel ) ), -1, PREG_SPLIT_NO_EMPTY );
					if ( ! is_array( $rel_tokens ) || ! in_array( 'preload', $rel_tokens, true ) ) {
						continue;
					}
					$as = $tags->get_attribute( 'as' );
					// Mirror the regex fallback exactly: any present quoted value
					// (including an empty string) must equal 'image', while an
					// absent or boolean `as` counts as image-eligible.
					if ( is_string( $as ) && 'image' !== strtolower( trim( $as ) ) ) {
						continue;
					}
					$href = $tags->get_attribute( 'href' );
					if ( ! is_string( $href ) || '' === $href ) {
						continue;
					}
					if ( $this->normalize_image_url( $href ) !== $needle ) {
						continue;
					}
					if ( ! $needle_has_sizes && $this->normalize_image_url( $href, false ) !== $needle_exact ) {
						continue;
					}
					if ( $this->get_url_query( $href ) !== $needle_query ) {
						continue;
					}
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Get the raw query string of a URL for preload-dedup comparison.
		 *
		 * `normalize_image_url()` deliberately drops the query string for LCP
		 * matching, but preload hints are per-resource: `img.jpg?v=1` and
		 * `img.jpg?v=2` are distinct. Fail-open: any parse failure returns an
		 * empty string.
		 *
		 * @since 2.0.0
		 *
		 * @param string $url The URL to inspect.
		 * @return string The query string without the leading `?`, or empty.
		 */
		private function get_url_query( string $url ): string {
			try {
				if ( function_exists( 'wp_parse_url' ) ) {
					$parsed = wp_parse_url( $url, PHP_URL_QUERY );
					if ( is_string( $parsed ) && '' !== $parsed ) {
						return $parsed;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return '';
		}

		/**
		 * Whether the matched image tag references the given LCP URL.
		 *
		 * Checks src, data-src (JS-lazy placeholder), and srcset attributes. Both
		 * sides are normalized (scheme-relative/relative URLs resolved against
		 * home_url(), query strings and WordPress size suffixes stripped) so that
		 * absolute-vs-relative matches work and derived assets cannot false-positive.
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags    The tag processor matched on an <img>.
		 * @param string                 $lcp_url The detected LCP image URL.
		 * @return bool True if the image references the LCP URL.
		 */
		private function tag_matches_lcp_url( $tags, string $lcp_url ): bool {
			$normalized_lcp = $this->normalize_image_url( $lcp_url );
			if ( '' === $normalized_lcp ) {
				return false;
			}

			foreach ( array( 'src', 'data-src', 'srcset' ) as $attribute ) {
				$value = $tags->get_attribute( $attribute );
				if ( ! is_string( $value ) || '' === $value ) {
					continue;
				}

				if ( 'srcset' === $attribute ) {
					foreach ( preg_split( '/\s*,\s*/', trim( $value ) ) as $candidate ) {
						$candidate_url = preg_split( '/\s+/', trim( $candidate ), 2 )[0];
						if ( '' !== $candidate_url && $this->normalize_image_url( $candidate_url ) === $normalized_lcp ) {
							return true;
						}
					}
					continue;
				}

				if ( $this->normalize_image_url( $value ) === $normalized_lcp ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Normalize an image URL for LCP matching.
		 *
		 * Resolves protocol-relative and root-relative URLs against home_url(),
		 * drops the scheme and any query string, and strips WordPress generated
		 * size suffixes (-NNNxNNN, -scaled, -eNNN) so derived assets are treated
		 * as the same image as their full-size original. Pass
		 * `$strip_size_suffix = false` to keep the suffix (used by preload
		 * dedup, which only collapses size variants when the requested URL
		 * itself carries one).
		 *
		 * @since 2.0.0
		 *
		 * @param string $url               The raw URL to normalize.
		 * @param bool   $strip_size_suffix Whether to strip WordPress size suffixes. Default true.
		 * @return string Normalized host + path, or an empty string when unparseable.
		 */
		private function normalize_image_url( string $url, bool $strip_size_suffix = true ): string {
			return self::normalize_image_url_static( $url, $strip_size_suffix );
		}

		/**
		 * Static normalization behind normalize_image_url().
		 *
		 * The body touches no instance state (only Util helpers and
		 * wp_parse_url()), so it lives here statically for the shared
		 * preload-dedup key builder. Kept private: external callers use
		 * has_emitted_preload()/mark_preload_emitted().
		 *
		 * @since NEXT
		 *
		 * @param string $url The image URL to normalize.
		 * @param bool   $strip_size_suffix Whether to strip WP size suffixes.
		 * @return string Normalized host + path, or empty string.
		 */
		private static function normalize_image_url_static( string $url, bool $strip_size_suffix = true ): string {
			if ( $strip_size_suffix && class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
				// Canonical key derivation lives in Util::normalize_image_key()
				// so image and CSS pipelines share one implementation.
				try {
					return Util::normalize_image_key( $url );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$url = trim( $url );

			if ( '' === $url ) {
				return '';
			}

			// Protocol-relative URL.
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			// Root-relative or bare path — resolve against the site URL.
			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = Util::cached_home_url() . '/' . ltrim( $url, '/' );
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['path'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] ?? '' );
			$path = $parts['path'];

			// Strip WordPress size suffixes, e.g. -1024x1024, -scaled, -e1234567890123.
			// The `$` anchor lives inside the lookahead so the suffix only
			// strips immediately before the file extension at end of path
			// (a trailing `$` outside the lookahead could never match).
			if ( $strip_size_suffix ) {
				$path = (string) preg_replace( '#-(?:\d+x\d+|scaled|e\d+)(?=\.[A-Za-z0-9]+$)#', '', $path );
			}

			return $host . $path;
		}

		/**
		 * Build the dedup key for a preload item (normalized URL + query + media).
		 *
		 * `normalize_image_url()` deliberately drops the scheme and query
		 * string for LCP matching, but `img.jpg?v=1` and `img.jpg?v=2` are
		 * distinct preload resources, so the raw query string is re-attached
		 * here: versioned duplicates each emit their own hint instead of
		 * collapsing to one. The normalized base also strips WordPress size
		 * suffixes, so responsive variants of the same image
		 * (`hero-1024x768.jpg`) intentionally collapse to a single preload
		 * hint alongside the full-size original (`hero.jpg`). Fail-open: any
		 * parse failure falls back to the normalized URL + media key.
		 *
		 * @since 2.0.0
		 * @since NEXT Delegates to build_preload_dedup_key() so the shared
		 * cross-emitter helpers use the identical key space.
		 *
		 * @param string $url   The raw preload URL.
		 * @param string $media The preload media attribute.
		 * @return string The dedup key.
		 */
		private function get_preload_dedup_key( string $url, string $media ): string {
			return self::build_preload_dedup_key( $url, $media );
		}

		/**
		 * Extract the first CSS background-image hero URL from an HTML buffer.
		 *
		 * Scans inline style attributes first (including the background
		 * shorthand) for the first url() candidate, then falls back to
		 * `<style>` blocks so stylesheet-defined heroes are also detected
		 * (issue #1312). Skips data:, blob:, and javascript: URIs and elements
		 * already deferred for lazy backgrounds (data-wppo-bg). Relative URLs
		 * are resolved against the home URL so they compare against the LCP
		 * URL. Any scan failure returns an empty string (fail-open to
		 * heuristic).
		 *
		 * @since 2.0.0
		 * @since NEXT Adds `<style>`-block fallback for stylesheet heroes.
		 * @since NEXT Adds a pre-6.2 regex fallback for inline `style=""`
		 * heroes when the HTML API is unavailable.
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The hero background image URL, or empty string.
		 */
		private function get_css_hero_url_from_buffer( string $buffer ): string {
			if ( '' === $buffer || false === stripos( $buffer, 'background' ) ) {
				return '';
			}
			if ( ! $this->is_html_api_available() && false === strpos( $buffer, 'style=' ) && false === stripos( $buffer, '<style' ) ) {
				return '';
			}
			try {
				if ( $this->is_html_api_available() ) {
					$tags = new \WP_HTML_Tag_Processor( $buffer );
					while ( $tags->next_tag() ) {
						if ( null !== $tags->get_attribute( 'data-wppo-bg' ) ) {
							continue;
						}
						$style = $tags->get_attribute( 'style' );
						if ( ! is_string( $style ) || '' === $style || false === stripos( $style, 'background' ) ) {
							continue;
						}
						$bg_url = '';
						if ( preg_match( '#background(?:-image)?\s*:[^;]*?url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#i', $style, $m ) ) {
							$bg_url = trim( $m[1] );
						}
						if ( '' === $bg_url || 0 === stripos( $bg_url, 'data:' ) || 0 === stripos( $bg_url, 'blob:' ) || 0 === stripos( $bg_url, 'javascript:' ) ) {
							continue;
						}
						if ( 0 === strpos( $bg_url, '//' ) ) {
							$bg_url = 'https:' . $bg_url;
						} elseif ( 0 === strpos( $bg_url, '/' ) || false === strpos( $bg_url, '://' ) ) {
							$bg_url = Util::cached_home_url() . '/' . ltrim( $bg_url, '/' );
						}
						return $bg_url;
					}
				}
				// Pre-6.2 regex fallback (issue #1312 review): when the HTML
				// API is unavailable the Tag Processor scan above is skipped
				// entirely, so inline `style=""` heroes are matched with a
				// lightweight style-attribute scan mirroring the same
				// background url() extraction. Fail-open to the
				// `<style>`-block fallback below.
				if ( ! $this->is_html_api_available() && false !== strpos( $buffer, 'style=' ) ) {
					$inline_styles = array();
					if ( preg_match_all( '#<[^>]+\bstyle\s*=\s*(["\'])(.*?)\1[^>]*>#is', $buffer, $inline_matches ) && isset( $inline_matches[0] ) && isset( $inline_matches[2] ) ) {
						foreach ( $inline_matches[0] as $idx => $tag_html ) {
							// Skip deferred lazy backgrounds (parity with the
							// Tag Processor branch above): preloading them
							// would defeat their lazy deferral.
							if ( false !== stripos( (string) $tag_html, 'data-wppo-bg' ) ) {
								continue;
							}
							$inline_styles[] = $inline_matches[2][ $idx ];
						}
					}
					foreach ( $inline_styles as $style ) {
						if ( ! is_string( $style ) || '' === $style || false === stripos( $style, 'background' ) ) {
							continue;
						}
						$bg_url = '';
						if ( preg_match( '#background(?:-image)?\s*:[^;]*?url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#i', $style, $m ) ) {
							$bg_url = trim( $m[1] );
						}
						if ( '' === $bg_url || 0 === stripos( $bg_url, 'data:' ) || 0 === stripos( $bg_url, 'blob:' ) || 0 === stripos( $bg_url, 'javascript:' ) ) {
							continue;
						}
						if ( 0 === strpos( $bg_url, '//' ) ) {
							$bg_url = 'https:' . $bg_url;
						} elseif ( 0 === strpos( $bg_url, '/' ) || false === strpos( $bg_url, '://' ) ) {
							$bg_url = Util::cached_home_url() . '/' . ltrim( $bg_url, '/' );
						}
						return $bg_url;
					}
				}
				// Stylesheet fallback: first background url() inside <style> blocks.
				if ( function_exists( 'wp_parse_url' ) && false !== stripos( $buffer, '<style' ) ) {
					$style_blocks = array();
					if ( preg_match_all( '#<style\b[^>]*>(.*?)</style>#is', $buffer, $style_matches ) && isset( $style_matches[1] ) ) {
						$style_blocks = $style_matches[1];
					}
					foreach ( $style_blocks as $css ) {
						if ( ! is_string( $css ) || '' === $css || false === stripos( $css, 'background' ) ) {
							continue;
						}
						if ( 1 !== preg_match( '#background(?:-image)?\s*:[^;{]*?url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#i', $css, $m ) ) {
							continue;
						}
						$bg_url = trim( $m[1] );
						if ( '' === $bg_url || 0 === stripos( $bg_url, 'data:' ) || 0 === stripos( $bg_url, 'blob:' ) || 0 === stripos( $bg_url, 'javascript:' ) ) {
							continue;
						}
						if ( 0 === strpos( $bg_url, '//' ) ) {
							$bg_url = 'https:' . $bg_url;
						} elseif ( 0 === strpos( $bg_url, '/' ) || false === strpos( $bg_url, '://' ) ) {
							$bg_url = Util::cached_home_url() . '/' . ltrim( $bg_url, '/' );
						}
						return $bg_url;
					}
				}
			} catch ( \Throwable $e ) {
				return '';
			}
			return '';
		}

		/**
		 * Whether any img element in the buffer references the given LCP URL.
		 *
		 * Used to choose between the img preload path and the CSS-hero
		 * preload path so exactly one preload link is ever emitted.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer  The HTML buffer.
		 * @param string $lcp_url The detected LCP image URL.
		 * @return bool True when an img matches the LCP URL.
		 */
		private function buffer_has_matching_img( string $buffer, string $lcp_url ): bool {
			if ( '' === $lcp_url || false === strpos( $buffer, '<img' ) || ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return false;
			}
			try {
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					if ( $this->tag_matches_lcp_url( $tags, $lcp_url ) ) {
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				return false;
			}
			return false;
		}

		/**
		 * Inject exactly one CSS-hero preload link into the buffer head.
		 *
		 * When the resolved LCP target has no matching img in the buffer but
		 * matches the first CSS background hero (inline `style=""` or
		 * `<style>`-block stylesheet hero, plus the server-side computed
		 * `wppo_computed_css_hero_url` context), a single preload link (as
		 * image with fetchpriority high, eager) is injected before the head
		 * close. Emits nothing when an img hero matches (covered by the img
		 * preload path), when the hero is unrelated to the LCP target, when
		 * the URL is neither same-origin nor on the configured CDN, or when
		 * the single hero slot was already claimed by any emitter
		 * (`preload_images()`, the img companion, or a prior call). Manual
		 * lists stay authoritative: automation only fills the gap.
		 *
		 * @since 2.0.0
		 * @since NEXT Accepts a pre-resolved LCP URL so buffer passes share one
		 * unified target instead of re-resolving stored data per pass.
		 * @since NEXT Uses the centralised hero slot, the same-origin/CDN
		 * allowlist, stylesheet-block heroes, and the computed-URL filter.
		 *
		 * @param string      $buffer  The HTML buffer.
		 * @param string|null $lcp_url Optional pre-resolved LCP URL. When null the
		 *                             URL is resolved via resolve_auto_lcp_url()
		 *                             (same-origin guarded OD/stored/heuristic
		 *                             chain), matching every other emission path.
		 * @return string The buffer with at most one added preload link.
		 */
		private function maybe_inject_css_hero_preload( string $buffer, ?string $lcp_url = null ): string {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			if ( empty( $image_optimisation['cssHeroPreload'] ) ) {
				return $buffer;
			}
			if ( null === $lcp_url ) {
				try {
					if ( method_exists( $this, 'resolve_auto_lcp_url' ) ) {
						$lcp_url = $this->resolve_auto_lcp_url( $buffer );
					} else {
						$lcp_url = $this->get_current_lcp_url();
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $buffer;
				}
			}
			if ( empty( $lcp_url ) ) {
				return $buffer;
			}
			// Allowed-origin parity (issue #1312): a caller-passed legacy URL
			// that bypassed resolve_auto_lcp_url() must still prove itself an
			// image on an allowed origin (same-origin or configured CDN).
			try {
				if ( ! $this->is_image_lcp_url( $lcp_url ) || ! $this->is_allowed_hero_preload_url( $lcp_url ) ) {
					return $buffer;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return $buffer;
			}
			if ( $this->buffer_has_matching_img( $buffer, $lcp_url ) ) {
				return $buffer;
			}
			$hero_url = $this->get_css_hero_url_from_buffer( $buffer );
			// Hoisted computed-URL fetch (issue #1312 review): the filter fires
			// at most once per call — reused for both the empty-scan fallback
			// and the mismatch-override check below instead of invoking
			// side-effecting callbacks twice.
			$computed_url = null;
			if ( '' === $hero_url ) {
				try {
					$computed_url = $this->get_computed_css_hero_url( $buffer );
				} catch ( \Throwable $e ) {
					unset( $e );
					$computed_url = '';
				}
				if ( is_string( $computed_url ) ) {
					$hero_url = $computed_url;
				}
			}
			if ( '' === $hero_url ) {
				return $buffer;
			}
			if ( $this->normalize_image_url( $hero_url ) !== $this->normalize_image_url( $lcp_url ) ) {
				// Computed server-side context wins when it is itself an allowed
				// hero: prefer it over a mismatched buffer scan so stylesheet
				// heroes missed by the scan still preload exactly once.
				if ( null === $computed_url ) {
					try {
						$computed_url = $this->get_computed_css_hero_url( $buffer );
					} catch ( \Throwable $e ) {
						unset( $e );
						$computed_url = '';
					}
				}
				$computed = is_string( $computed_url ) ? $computed_url : '';
				if ( '' === $computed || $this->normalize_image_url( $computed ) !== $this->normalize_image_url( $lcp_url ) ) {
					return $buffer;
				}
			}
			// Centralised single-preload guard: skip when any emitter already
			// claimed this hero (any media) or the buffer already carries it.
			// The claim also records this emission so the img companion path
			// cannot double-emit for the same hero.
			if ( ! $this->claim_hero_preload_slot( $lcp_url, '', $buffer ) ) {
				return $buffer;
			}
			$link_tag = Util::get_preload_link( $lcp_url, 'preload', 'image', false, Util::get_image_mime_type( $lcp_url ), '', 'high' );
			if ( '' === $link_tag ) {
				// Link generation failed after the slot claim: release the claim
				// so the sibling emitter may still emit (issue #1312 review).
				self::release_hero_preload_slot( $lcp_url, '' );
				return $buffer;
			}
			$head_pos = stripos( $buffer, '</head>' );
			if ( false !== $head_pos ) {
				return substr( $buffer, 0, $head_pos ) . $link_tag . chr( 10 ) . substr( $buffer, $head_pos );
			}
			return $link_tag . chr( 10 ) . $buffer;
		}

		/**
		 * Transforms <picture>, <img>, and <iframe> elements in the provided HTML to enable lazy loading and delayed loading based on the image_optimisation options.
		 *
		 * Applies exclusions derived from the options (including preload-selected images and the first N images specified by `excludeFirstImages`) and rewrites matched tags to use data-* attributes and lazy classes when appropriate.
		 * YouTube embed iframes are replaced with lightweight video placeholders when the feature is enabled.
		 *
		 * @since 1.0.0
		 *
		 * @param string $buffer The HTML buffer to process.
		 * @return string The modified HTML buffer with lazy-load and delay-load attributes applied.
		 */
		public function add_delay_load_img( $buffer ) {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			$exclude_img_count  = $this->get_effective_exclude_first_images_count( $image_optimisation );
			$exclude_imgs       = array();

			$enable_video_placeholder = ! empty( $image_optimisation['enableVideoPlaceholder'] );
			$lazy_load_videos_active  = ! empty( $image_optimisation['lazyLoadVideos'] );

			if ( $enable_video_placeholder && $lazy_load_videos_active ) {
				$buffer = preg_replace_callback(
					'#<iframe\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>\s*</iframe>#is',
					function ( $matches ) {
						$video_id = $this->get_youtube_video_id( $matches[2] );
						if ( $video_id ) {
							return $this->generate_video_placeholder( $matches[0], $matches[2], $video_id );
						}
						return $matches[0];
					},
					$buffer
				);
			}

			$noscript_tokens    = array();
			$noscript_namespace = $this->get_noscript_namespace();
			$extracted          = preg_replace_callback(
				'#<noscript>.*?</noscript>#is',
				function ( $m ) use ( &$noscript_tokens, $noscript_namespace ) {
					$token = '<!--WPPO_NOSCRIPT_' . $noscript_namespace . '_' . count( $noscript_tokens ) . '-->';
					// Harden before stashing so `<noscript><img onerror>>`
					// cannot be restored verbatim into cached HTML.
					$noscript_tokens[ $token ] = $this->sanitize_comment_images_in_buffer( $m[0] );
					return $token;
				},
				$buffer
			);
			// PCRE failure: degrade to the unmodified buffer, never fatal.
			if ( null !== $extracted ) {
				$buffer = $extracted;
			}

			// Comment-image hardening (issue #1271): strip hostile
			// comment-authored attributes (on*, scriptable URLs) before
			// lazy rewriting so they can never be laundered into cache.
			if ( is_string( $buffer ) && '' !== $buffer ) {
				$buffer = $this->sanitize_comment_images_in_buffer( $buffer );
			}

			if ( ! empty( $image_optimisation['lazyLoadImages'] ) ) {
				$exclude_imgs = $this->exclude_lazy_imgs;

				$preload_img_urls = $this->get_preload_images_urls();
				$exclude_imgs     = array_unique( array_merge( $exclude_imgs, $preload_img_urls ) );

				// Note: same-response coupling (issue #1273) lives in
				// get_preload_images_urls(), which already merges the
				// generate_img_preload() emitted set — no second merge here.

				// OD bridge: ensure the LCP image (mobile/desktop) is never lazy-loaded.
				// The `wppo_od_should_optimize` opt-out (issue #1216) is
				// honoured inside `OD_Bridge::is_enabled()` (single filter
				// firing, current-URL context), so the lazy-exclusion path
				// stays in parity with
				// `resolve_auto_lcp_url()`/`get_current_lcp_url()`: opting out
				// yields neither preload nor eager exclusion from OD data.
				$od_lcp_normalized = '';
				if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) ) {
					try {
						if ( \PerformanceOptimise\Inc\OD_Bridge::is_enabled() ) {
							$od_lcp = \PerformanceOptimise\Inc\OD_Bridge::get_lcp_url();
							if ( '' !== $od_lcp ) {
								$exclude_imgs[]    = $od_lcp;
								$od_lcp_normalized = Util::normalize_url( $od_lcp );
								if ( '' !== $od_lcp_normalized && ! in_array( $od_lcp_normalized, $exclude_imgs, true ) ) {
									$exclude_imgs[] = $od_lcp_normalized;
								}
								$exclude_imgs = array_unique( $exclude_imgs );
							}
						}
					} catch ( \Throwable $e ) {
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'WPPO Image optimisation OD error: ' . str_replace( ABSPATH, '', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						}
					}
				}

				// Stored LCP + hero fallback (LCP-aware lazy-load, @since 2.0.0).
				// Gated on the LCP feature toggles so default lazy behaviour is
				// unchanged when LCP prioritization is off (backward compat):
				// the stored LCP URL (PageSpeed/post-meta/transient) and, when
				// no stored URL resolves, the first <img src> (hero) are merged
				// into the never-lazy exclusion list. Fail-open: any detection
				// failure leaves the exclusion list untouched.
				$stored_lcp   = '';
				$hero_enabled = ( ! isset( $image_optimisation['lcpHeroPreload'] ) || ! empty( $image_optimisation['lcpHeroPreload'] ) )
					&& ( ! empty( $image_optimisation['prioritizeLCPImages'] ) || ! empty( $image_optimisation['autoPreloadLCP'] ) );
				if ( $hero_enabled ) {
					try {
						$stored_lcp = $this->get_current_lcp_url();
						if ( '' !== $stored_lcp && ! in_array( $stored_lcp, $exclude_imgs, true ) ) {
							$exclude_imgs[] = $stored_lcp;
							$exclude_imgs   = array_unique( $exclude_imgs );
						}
						if ( '' === $stored_lcp ) {
							$first_src = $this->get_first_image_src_in_buffer( $buffer );
							if ( '' !== $first_src && ! in_array( $first_src, $exclude_imgs, true ) ) {
								$exclude_imgs[] = $first_src;
								$exclude_imgs   = array_unique( $exclude_imgs );
							}
						}
					} catch ( \Throwable $e ) {
						do_action( 'wppo_debug_log', 'WPPO hero exclusion failed: ' . $e->getMessage(), array( 'exception' => $e ) );
					}
				}

				// Automatic LCP-candidate lazy exclusion: the unified
				// resolve_auto_lcp_url() candidate is never lazy-loaded.
				// Gated on the LCP toggles (see get_lazy_lcp_exclusion_url())
				// so default lazy behaviour is unchanged when all LCP features
				// are off. The buffer is threaded through so the P2 DOM-first
				// heuristic tier stays in parity with maybe_preload_hero_image().
				// Fail-open: any detection failure leaves the exclusion list
				// untouched.
				$candidate_lcp_normalized = '';
				try {
					$candidate_url = $this->get_lazy_lcp_exclusion_url( $image_optimisation, $buffer );
					if ( '' !== $candidate_url ) {
						if ( ! in_array( $candidate_url, $exclude_imgs, true ) ) {
							$exclude_imgs[] = $candidate_url;
						}
						$candidate_lcp_normalized = Util::normalize_url( $candidate_url );
						if ( '' !== $candidate_lcp_normalized && ! in_array( $candidate_lcp_normalized, $exclude_imgs, true ) ) {
							$exclude_imgs[] = $candidate_lcp_normalized;
						}
						$exclude_imgs = array_unique( $exclude_imgs );
					}
				} catch ( \Throwable $e ) {
					do_action( 'wppo_debug_log', 'WPPO LCP candidate exclusion failed: ' . $e->getMessage(), array( 'exception' => $e ) );
				}

				// Same-response direct-preload heroes (issue #1273): an
				// explicit generate_img_preload() URL must stay eager even
				// on alias/size-variant mismatch, so their normalized
				// forms join the normalized-equality check below (not just
				// the substring list above).
				$direct_lcp_normalized = array();
				try {
					$direct_lcp_normalized = self::get_direct_preload_normalized_urls();
				} catch ( \Throwable $e ) {
					unset( $e );
					$direct_lcp_normalized = array();
				}

				$img_counter = 0;

				$use_native_lazy    = ! empty( $image_optimisation['lazyLoadNative'] );
				$enable_placeholder = 'none' !== ( $image_optimisation['placeholderType'] ?? 'none' );

				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$wppo_tags = new \WP_HTML_Tag_Processor( $buffer );

					// Hoisted per-pass (see maybe_serve_next_gen_images).
					// Unfiltered next_tag() is deliberate here too (WP 6.2
					// minimum lacks the multi-tag `tag_names` filter);
					// is_hardened_tag() keeps the per-node check O(1).
					$hardening_on = $this->is_comment_hardening_enabled();
					while ( $wppo_tags->next_tag() ) {
						$tag_name = $wppo_tags->get_tag();

						if ( $hardening_on && is_string( $tag_name ) && $this->is_hardened_tag( $tag_name ) ) {
							$this->sanitize_tag_attributes_processor( $wppo_tags );
						}

						if ( 'IMG' === $tag_name || 'IMAGE' === $tag_name ) {
							$src      = $wppo_tags->get_attribute( 'src' );
							$data_src = $wppo_tags->get_attribute( 'data-src' );
							// Same src-or-data-src counting as
							// unlazyload_first_images() so input already
							// containing JS-lazy markup stays aligned.
							if ( ( null === $src || '' === $src ) && ( null === $data_src || '' === $data_src ) ) {
								continue;
							}

							++$img_counter;
							if ( $exclude_img_count >= $img_counter ) {
								$count_src = ( is_string( $src ) && '' !== $src ) ? $src : $data_src;
								if ( is_string( $count_src ) && '' !== $count_src ) {
									$exclude_imgs[] = $count_src;
								}
							}

							$should_exclude = false;
							// Normalized LCP matches (OD + RUM-field/PageSpeed
							// candidate + same-response direct preloads):
							// normalized-to-normalized equality covers
							// http/https and WordPress size-suffix variants
							// (e.g. hero-300x200.jpg matches candidate hero.jpg),
							// which substring matching alone would miss.
							$match_src = ( is_string( $src ) && '' !== $src ) ? $src : (string) $data_src;
							if ( '' !== $od_lcp_normalized || '' !== $candidate_lcp_normalized || array() !== $direct_lcp_normalized ) {
								try {
									$src_normalized = Util::normalize_url( $match_src );
									if ( ( '' !== $od_lcp_normalized && $src_normalized === $od_lcp_normalized )
									|| ( '' !== $candidate_lcp_normalized && $src_normalized === $candidate_lcp_normalized )
									|| ( '' !== $src_normalized && in_array( $src_normalized, $direct_lcp_normalized, true ) ) ) {
										$should_exclude = true;
									}
								} catch ( \Throwable $e ) {
									unset( $e );
								}
							}
							if ( ! $should_exclude ) {
								foreach ( $exclude_imgs as $exclude_img ) {
									if ( '' !== $exclude_img && '' !== $match_src && false !== strpos( $match_src, $exclude_img ) ) {
										$should_exclude = true;
										break;
									}
								}
							}
							// High-priority nodes are never lazy (issue #1312):
							// an element already marked fetchpriority high is
							// forced eager and excluded from lazy rewriting so
							// lazy plus high pairs are never emitted together
							// (covers alias/background-only heroes stamped by
							// an earlier pass). Fail-open per node.
							try {
								$existing_priority = $wppo_tags->get_attribute( 'fetchpriority' );
								if ( is_string( $existing_priority ) && 'high' === strtolower( trim( $existing_priority ) ) ) {
									$should_exclude = true;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}

							if ( $should_exclude ) {
								// Force eager when high is present: the high hint
								// wins so the pair is never emitted together.
								try {
									$existing_loading = $wppo_tags->get_attribute( 'loading' );
									if ( is_string( $existing_loading ) && 'lazy' === strtolower( trim( $existing_loading ) ) ) {
										$wppo_tags->set_attribute( 'loading', 'eager' );
									}
								} catch ( \Throwable $e ) {
									unset( $e );
								}
								$this->set_loading_optimization_attributes(
									$wppo_tags,
									array(
										'fetchpriority' => 'high',
										'decoding'      => 'sync',
									),
									false
								);
								$this->maybe_autofill_alt_processor( $wppo_tags, $match_src );
								continue;
							}

							if ( null !== $wppo_tags->get_attribute( 'data-src' ) ) {
								continue;
							}

							$original_src_decoded = htmlspecialchars_decode( $match_src, ENT_QUOTES );

							if ( preg_match( '#^data:image/#i', $original_src_decoded ) ) {
								$this->maybe_autofill_alt_processor( $wppo_tags, $original_src_decoded );
								continue;
							}

							if ( $use_native_lazy || 'lazy' === $wppo_tags->get_attribute( 'loading' ) ) {
								if ( null === $wppo_tags->get_attribute( 'loading' ) ) {
									// Honour Core's loading decision (WP 6.3+) — first N + header images must not be lazy (LCP).
									$should_lazy = true;
									if ( function_exists( 'wp_get_loading_optimization_attributes' ) ) {
										$test_attr = array();
										$src_attr  = $wppo_tags->get_attribute( 'src' );
										if ( null !== $src_attr ) {
											$test_attr['src'] = $src_attr;
										}
										$w_attr = $wppo_tags->get_attribute( 'width' );
										if ( null !== $w_attr ) {
											$test_attr['width'] = (int) $w_attr;
										}
										$h_attr = $wppo_tags->get_attribute( 'height' );
										if ( null !== $h_attr ) {
											$test_attr['height'] = (int) $h_attr;
										}
										$core_attrs = $this->merge_core_loading_attributes( $test_attr, 'performance_optimisation_delay_load' );
										if ( ! isset( $core_attrs['loading'] ) ) {
											$should_lazy = false;
										}
									}
									if ( $should_lazy ) {
										$wppo_tags->set_attribute( 'loading', 'lazy' );
									}
								}
								if ( null === $wppo_tags->get_attribute( 'decoding' ) ) {
									$wppo_tags->set_attribute( 'decoding', 'async' );
								}
								// Occluded: let core decide fetchpriority (low for below-fold/occluded) when available.
								if ( null === $wppo_tags->get_attribute( 'fetchpriority' ) ) {
									$this->set_loading_optimization_attributes(
										$wppo_tags,
										array(
											'fetchpriority' => 'low',
											'decoding' => 'async',
										)
									);
									// If still none (pre-6.7), set low for non-excluded below-fold as progressive enhancement.
									if ( null === $wppo_tags->get_attribute( 'fetchpriority' ) ) {
										$wppo_tags->set_attribute( 'fetchpriority', 'low' );
									}
								}
								// Native-lazy placeholders (issue #1158): the real
								// src is kept (the browser defers it), so only
								// the local placeholder attributes
								// (dominant-color wash / LQIP blur hook) are
								// emitted -- zero external HTTP, LCP hero
								// explicitly excluded from blur.
								if ( $enable_placeholder ) {
									$native_attrs = $this->get_native_lazy_placeholder_attrs( $original_src_decoded, $exclude_imgs, $od_lcp_normalized, $candidate_lcp_normalized );
									foreach ( $native_attrs as $native_attr_name => $native_attr_value ) {
										if ( null === $wppo_tags->get_attribute( $native_attr_name ) ) {
											$wppo_tags->set_attribute( $this->normalize_data_attribute_name( $native_attr_name ), $native_attr_value );
										}
									}
								}
							} else {
								// JS-lazy path: consult core for occluded/fetchpriority before stripping src,
								// so hidden/below-fold images still hint low priority.
								if ( function_exists( 'wp_get_loading_optimization_attributes' ) && null === $wppo_tags->get_attribute( 'fetchpriority' ) ) {
									$this->set_loading_optimization_attributes( $wppo_tags );
									if ( null === $wppo_tags->get_attribute( 'fetchpriority' ) ) {
										$wppo_tags->set_attribute( 'fetchpriority', 'low' );
									}
								} elseif ( null === $wppo_tags->get_attribute( 'fetchpriority' ) ) {
									$wppo_tags->set_attribute( 'fetchpriority', 'low' );
								}
								$wppo_tags->set_attribute( 'data-src', $original_src_decoded );
								$wppo_tags->remove_attribute( 'src' );

								$srcset = $wppo_tags->get_attribute( 'srcset' );
								if ( $srcset ) {
									$wppo_tags->set_attribute( 'data-srcset', $srcset );
									$wppo_tags->remove_attribute( 'srcset' );
								}

								$sizes = $wppo_tags->get_attribute( 'sizes' );
								if ( $sizes ) {
									$wppo_tags->set_attribute( 'data-sizes', $this->prepare_auto_sizes_value( $sizes, $wppo_tags ) );
									$wppo_tags->remove_attribute( 'sizes' );
								}
							}
						} elseif ( 'IFRAME' === $tag_name ) {
							$src = $wppo_tags->get_attribute( 'src' );
							if ( null === $src ) {
								continue;
							}

							$should_exclude = false;
							foreach ( $exclude_imgs as $exclude_img ) {
								if ( false !== strpos( $src, $exclude_img ) ) {
									$should_exclude = true;
									break;
								}
							}

							if ( $should_exclude ) {
								continue;
							}

							$allowed = apply_filters( 'wppo_lazyload_iframe_allowed', true, $src, '' );
							if ( ! $allowed ) {
								continue;
							}

							// High-priority iframes are never lazy (issue #1312
							// parity with the IMG branch above): an author-marked
							// fetchpriority high hint wins, so force eager and skip
							// both the native and JS-lazy deferral paths instead
							// of emitting the invalid lazy+high pair.
							try {
								$iframe_priority = $wppo_tags->get_attribute( 'fetchpriority' );
								if ( is_string( $iframe_priority ) && 'high' === strtolower( trim( $iframe_priority ) ) ) {
									$iframe_loading = $wppo_tags->get_attribute( 'loading' );
									if ( is_string( $iframe_loading ) && 'lazy' === strtolower( trim( $iframe_loading ) ) ) {
										$wppo_tags->set_attribute( 'loading', 'eager' );
									} elseif ( null === $iframe_loading ) {
										$wppo_tags->set_attribute( 'loading', 'eager' );
									}
									continue;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}

							if ( $use_native_lazy ) {
								// Native path: keep src, let the browser defer via loading="lazy".
								if ( null === $wppo_tags->get_attribute( 'loading' ) ) {
									$wppo_tags->set_attribute( 'loading', 'lazy' );
								}
								continue;
							}

							$wppo_tags->set_attribute( 'data-src', $src );
							$wppo_tags->remove_attribute( 'src' );
							$wppo_tags->add_class( 'wppo-lazyload' );
						}
					}

					$buffer = $wppo_tags->get_updated_html();

					$buffer = $this->post_process_placeholders( $buffer, $enable_placeholder );
					$buffer = $this->post_process_img_dimensions( $buffer );
					$buffer = $this->post_process_auto_sizes( $buffer );

					if ( $this->should_use_html_processor() ) {
						$buffer = $this->process_picture_blocks_processor( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
					} else {
						$buffer = $this->process_picture_blocks_regex( $buffer, $img_counter, $exclude_img_count, $exclude_imgs );
					}
				} else {
					$buffer = preg_replace_callback(
						'#<picture\b[^>]*>.*?</picture>|<img\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>|<iframe\b([^>]*?)src=["\']([^"\']+)["\'][^>]*>#is',
						function ( $matches ) use ( &$img_counter, $exclude_img_count, &$exclude_imgs ) {
							if ( isset( $matches[4] ) ) {
								return $this->process_iframe_tag( $matches[0], $matches[4], $exclude_imgs );
							}

							if ( ! isset( $matches[2] ) ) {
								// <picture> alternative: the regex has no capture
								// groups here, so $matches[2] does not exist and
								// reading it directly would warn. Derive the src
								// from the inner <img> instead —
								// process_picture_tag() re-derives it the same way.
								$inner_src = '';
								if ( preg_match( '#<img\b[^>]*?src=["\']([^"\']+)["\']#i', $matches[0], $inner_matches ) ) {
									$inner_src = $inner_matches[1];
								}

								++$img_counter;
								if ( '' !== $inner_src && $exclude_img_count >= $img_counter ) {
									$exclude_imgs[] = $inner_src;
								}

								return $this->process_picture_tag( $matches, $matches[0], $inner_src, $exclude_imgs );
							}

							++$img_counter;

							if ( $exclude_img_count >= $img_counter ) {
								$exclude_imgs[] = $matches[2];
							}

							return $this->process_picture_tag( $matches, $matches[0], $matches[2], $exclude_imgs );
						},
						$buffer
					);
					if ( null !== $buffer ) {
						$buffer = $this->post_process_auto_sizes( $buffer );
					}
				}
			} elseif ( $this->is_auto_alt_enabled() ) {
				// Standalone alt-autofill pass (issue #985 follow-up): when
				// lazy-loading is off, `process_img_tag()` is never reached,
				// so enabling `autoAltText` alone would silently do nothing.
				// This lightweight pass fills only missing `alt` attributes
				// and leaves everything else byte-identical.
				$buffer = $this->autofill_alt_in_buffer( $buffer );
			}

			$buffer = $this->restore_noscript_tokens( $buffer, $noscript_tokens );
			return $buffer;
		}

		/**
		 * Autofill missing `alt` attributes across a full HTML buffer.
		 *
		 * Standalone pass used when lazy-loading is disabled but
		 * `autoAltText` is enabled. Uses `WP_HTML_Tag_Processor` when
		 * available, otherwise a regex fallback. Fail-open: returns the
		 * buffer unchanged when disabled or on any processing failure.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer to process.
		 * @return string The buffer with missing `alt` attributes filled.
		 */
		private function autofill_alt_in_buffer( string $buffer ): string {
			if ( ! $this->is_auto_alt_enabled() ) {
				return $buffer;
			}
			try {
				if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
					$tags = new \WP_HTML_Tag_Processor( $buffer );
					while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
						$src = $tags->get_attribute( 'src' );
						if ( null === $src ) {
							$src = $tags->get_attribute( 'data-src' );
						}
						if ( null === $src || '' === $src ) {
							continue;
						}
						$this->maybe_autofill_alt_processor( $tags, (string) $src );
					}
					$updated = $tags->get_updated_html();
					if ( is_string( $updated ) ) {
						return $updated;
					}
					return $buffer;
				}
				$result = preg_replace_callback(
					'#<img\b[^>]*>#i',
					function ( $matches ) {
						$tag = $matches[0];
						$src = '';
						// Match quoted OR unquoted src values (e.g. src=foo.jpg),
						// so the standalone auto-alt pass covers <img src=...>.
						if ( preg_match( '#(?<![\w-])src\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))#is', $tag, $m ) ) {
							$raw = ( isset( $m[3] ) && '' !== $m[3] ) ? $m[3] : ( $m[2] ?? '' );
							$src = htmlspecialchars_decode( $raw, ENT_QUOTES );
						}
						if ( '' === $src ) {
							return $tag;
						}
						return $this->maybe_autofill_alt_regex( $tag, $src );
					},
					$buffer
				);
				return is_string( $result ) ? $result : $buffer;
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Fail-open: keep the original buffer.
				return $buffer;
			}
		}

		/**
		 * Retrieves URLs of images to preload for lazy-load exclusion.
		 *
		 * @since 1.0.0
		 * @return array List of preload image URLs.
		 */
		private function get_preload_images_urls(): array {
			$preload_data = $this->get_all_preload_data();
			$urls         = array_unique( array_column( $preload_data, 'url' ) );
			try {
				if ( array() !== self::$preload_emitted_urls ) {
					$urls = array_unique( array_merge( $urls, array_keys( self::$preload_emitted_urls ), self::get_direct_preload_normalized_urls() ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $urls;
		}

		/**
		 * Generates a base64-encoded SVG image with the given width and height.
		 *
		 * @since 1.0.0
		 *
		 * @param string $img_attributes The image's attributes (including width and height).
		 * @param string $color          Optional hex fill color. Default '#cfd4db'.
		 * @return string The base64-encoded SVG.
		 */
		private function generate_svg_base64( $img_attributes, $color = '#cfd4db' ) {
			// Match both quoted (width="59") and unquoted (width=59) attribute formats.
			preg_match( '/\bwidth=["\']?(\d+)["\']?/i', $img_attributes, $width_matches );
			preg_match( '/\bheight=["\']?(\d+)["\']?/i', $img_attributes, $height_matches );

			$width  = isset( $width_matches[1] ) ? min( absint( $width_matches[1] ), self::SVG_PLACEHOLDER_MAX_DIMENSION ) : 100;
			$height = isset( $height_matches[1] ) ? min( absint( $height_matches[1] ), self::SVG_PLACEHOLDER_MAX_DIMENSION ) : 100;

			$svg_content = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $width . '" height="' . $height . '" viewBox="0 0 ' . $width . ' ' . $height . '"><rect width="100%" height="100%" fill="' . esc_attr( $color ) . '" /></svg>';

			// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			return 'data:image/svg+xml;base64,' . base64_encode( $svg_content );
			// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		/**
		 * Get the current placeholder type.
		 *
		 * @since 2.0.0
		 *
		 * @return string One of 'none', 'svg', 'dominant_color', 'lqip'.
		 */
		private function get_placeholder_type(): string {
			$type    = $this->options['image_optimisation']['placeholderType'] ?? 'none';
			$allowed = array( 'none', 'svg', 'dominant_color', 'lqip' );
			return in_array( $type, $allowed, true ) ? $type : 'none';
		}

		/**
		 * Get the appropriate placeholder src and extra attributes for a lazy-loaded image.
		 *
		 * Looks up stored placeholder data (dominant color, LQIP) from Img_Converter's
		 * image info by resolving the data-src URL to a local path.
		 *
		 * @since 2.0.0
		 *
		 * @param string $img_tag  The <img> tag HTML.
		 * @param string $data_src The data-src URL of the image.
		 * @return array{src: string, attrs: array<string, string>} Placeholder src and extra attributes.
		 */
		private function get_placeholder_src_for_image( string $img_tag, string $data_src ): array {
			$result = array(
				'src'   => '',
				'attrs' => array(),
			);

			$placeholder_type = $this->get_placeholder_type();

			if ( 'none' === $placeholder_type ) {
				return $result;
			}

			// Resolve data-src to a local path key for looking up placeholder data.
			$rel_path = '';
			if ( ! isset( self::$placeholder_path_cache[ $data_src ] ) ) {
				$local_path = Util::get_local_path( $data_src );
				if ( ! empty( $local_path ) ) {
					self::$placeholder_path_cache[ $data_src ] = str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( $local_path ) );
				} else {
					self::$placeholder_path_cache[ $data_src ] = '';
				}
			}
			$rel_path = self::$placeholder_path_cache[ $data_src ];

			// Load placeholder data from the shared wppo_img_info option.
			if ( null === self::$placeholder_info_cache ) {
				self::$placeholder_info_cache = Img_Converter::get_placeholder_info();
			}
			$placeholder_cache = self::$placeholder_info_cache;

			if ( 'svg' === $placeholder_type ) {
				$result['src'] = $this->generate_svg_base64( $img_tag );
				return $result;
			}

			if ( 'dominant_color' === $placeholder_type ) {
				$dominant_color = $placeholder_cache['dominant_color'][ $rel_path ] ?? '';
				if ( ! empty( $dominant_color ) && preg_match( '/^#[a-f0-9]{6}$/i', $dominant_color ) ) {
					$result['src']                               = 'data:image/svg+xml;charset=UTF-8,%3Csvg%20xmlns%3D%22http%3A%2F%2Fwww.w3.org%2F2000%2Fsvg%22%20width%3D%221%22%20height%3D%221%22%2F%3E';
					$result['attrs']['data-wppo-dominant-color'] = $dominant_color;
				} else {
					$result['src'] = $this->generate_svg_base64( $img_tag );
				}
				return $result;
			}

			if ( 'lqip' === $placeholder_type ) {
				$lqip = $placeholder_cache['lqip'][ $rel_path ] ?? '';
				if ( ! empty( $lqip ) ) {
					$result['src']                     = $lqip;
					$result['attrs']['data-wppo-lqip'] = '1';
				} else {
					$result['src'] = $this->generate_svg_base64( $img_tag );
				}
				return $result;
			}

			return $result;
		}

		/**
		 * Whether a URL is the LCP hero image (explicit blur-exclusion check).
		 *
		 * Centralizes the hero matching used by add_delay_load_img(): substring
		 * membership in the never-lazy exclusion list plus normalized-URL
		 * equality against the OD and candidate LCP URLs (covers http/https
		 * and WordPress size-suffix variants). Used to keep the LCP hero out
		 * of LQIP blur even if it ever reaches the placeholder path.
		 *
		 * @since NEXT
		 *
		 * @param string   $url                  The image URL to test.
		 * @param string[] $exclude_imgs         The never-lazy exclusion list.
		 * @param string   $od_lcp_normalized    Normalized OD LCP URL (or '').
		 * @param string   $candidate_normalized Normalized candidate LCP URL (or '').
		 * @return bool True when the URL is the LCP hero.
		 */
		private function is_lcp_hero_url( string $url, array $exclude_imgs, string $od_lcp_normalized = '', string $candidate_normalized = '' ): bool {
			if ( '' === $url ) {
				return false;
			}

			$direct_normalized = array();
			try {
				$direct_normalized = self::get_direct_preload_normalized_urls();
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( '' !== $od_lcp_normalized || '' !== $candidate_normalized || array() !== $direct_normalized ) {
				try {
					$src_normalized = Util::normalize_url( $url );
					if ( ( '' !== $od_lcp_normalized && $src_normalized === $od_lcp_normalized )
						|| ( '' !== $candidate_normalized && $src_normalized === $candidate_normalized ) ) {
						return true;
					}
					// Same-response direct preloads (issue #1273): explicit
					// generate_img_preload() heroes match on normalized
					// equality so alias/size variants stay blur-free too.
					if ( '' !== $src_normalized && in_array( $src_normalized, $direct_normalized, true ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			foreach ( $exclude_imgs as $exclude_img ) {
				if ( '' !== $exclude_img && false !== strpos( $url, $exclude_img ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Whether the local LQIP placeholder pipeline is enabled.
		 *
		 * Shares the `wppo_smart_pipeline_enabled` kill-switch filter with the
		 * converter's size-compare path (issue #1158) so one filter disables
		 * both features, and defaults from the
		 * `image_optimisation.discardOversizedSibling` setting (like
		 * `Img_Converter::is_smart_compress_enabled()`) so one toggle
		 * disables both. Placeholders are server-side only (inline data-URI /
		 * dominant-color attributes) — zero external HTTP either way.
		 *
		 * @since NEXT
		 *
		 * @return bool True when LQIP placeholder emission is enabled.
		 */
		private function is_local_lqip_pipeline_enabled(): bool {
			$enabled = (bool) ( $this->options['image_optimisation']['discardOversizedSibling'] ?? true );
			if ( function_exists( 'apply_filters' ) && function_exists( 'has_filter' ) && has_filter( 'wppo_smart_pipeline_enabled' ) ) {
				/**
				 * Filter the size-compare smart-compress + local LQIP pipeline.
				 *
				 * @since NEXT
				 * @param bool $enabled Whether the pipeline is enabled.
				 */
				return (bool) apply_filters( 'wppo_smart_pipeline_enabled', $enabled );
			}

			return $enabled;
		}

		/**
		 * Placeholder attributes for a native-lazy (`loading="lazy"`) image.
		 *
		 * The JS-lazy path swaps `src` for a placeholder via
		 * post_process_placeholders(); native-lazy keeps the real `src` (the
		 * browser defers it), so only the extra attributes are emitted:
		 * `data-wppo-dominant-color` (background wash) and `data-wppo-lqip`
		 * (blur hook consumed by lazyload.js). The LCP hero is explicitly
		 * excluded from blur; data: URIs are never touched. Fail-open:
		 * returns an empty array on any failure or when disabled.
		 *
		 * @since NEXT
		 *
		 * @param string   $src_url              The image src URL.
		 * @param string[] $exclude_imgs         The never-lazy exclusion list.
		 * @param string   $od_lcp_normalized    Normalized OD LCP URL (or '').
		 * @param string   $candidate_normalized Normalized candidate LCP URL (or '').
		 * @return array<string, string> Extra attributes (empty when none apply).
		 */
		private function get_native_lazy_placeholder_attrs( string $src_url, array $exclude_imgs, string $od_lcp_normalized = '', string $candidate_normalized = '' ): array {
			try {
				if ( ! $this->is_local_lqip_pipeline_enabled() ) {
					return array();
				}

				if ( 'none' === $this->get_placeholder_type() ) {
					return array();
				}

				if ( 1 === preg_match( '#^data:image/#i', htmlspecialchars_decode( $src_url, ENT_QUOTES ) ) ) {
					return array();
				}

				// Explicit LCP-hero blur exclusion (issue #1158): the hero is
				// normally excluded from lazy rewrites upstream, but this
				// guard keeps it blur-free even if it reaches this path.
				if ( $this->is_lcp_hero_url( $src_url, $exclude_imgs, $od_lcp_normalized, $candidate_normalized ) ) {
					return array();
				}

				$placeholder = $this->get_placeholder_src_for_image( '<img>', $src_url );
				return is_array( $placeholder['attrs'] ?? null ) ? $placeholder['attrs'] : array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Defer inline CSS background-image URLs until the element is near the viewport.
		 *
		 * Moves the `background-image` declaration into a `data-wppo-bg` attribute and
		 * tags the element with the `wppo-lazy-bg` class so the frontend runtime can
		 * restore it on intersection. The first N backgrounds (hero heuristics) and
		 * data: URIs are left untouched.
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The processed buffer.
		 */
		public function add_delay_load_backgrounds( string $buffer ): string {
			$image_optimisation = $this->options['image_optimisation'] ?? array();
			if ( empty( $image_optimisation['lazyLoadBackgroundImages'] ) ) {
				return $buffer;
			}
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return $buffer;
			}

			$exclude_count = $this->get_effective_exclude_first_images_count( $image_optimisation );
			$bg_counter    = 0;

			// Resolved LCP hero background (issue #935): when the CSS hero preload
			// is enabled, the hero keeps its inline background-image so the
			// preloaded hero is never deferred behind an intersection observer.
			$lcp_hero_normalized = '';
			if ( ! empty( $image_optimisation['cssHeroPreload'] ) ) {
				try {
					$lcp_hero_url = $this->get_current_lcp_url();
					if ( '' !== $lcp_hero_url ) {
						$lcp_hero_normalized = $this->normalize_image_url( $lcp_hero_url );
					}
				} catch ( \Throwable $e ) {
					$lcp_hero_normalized = '';
				}
			}

			$tags = new \WP_HTML_Tag_Processor( $buffer );

			while ( $tags->next_tag() ) {
				$style = $tags->get_attribute( 'style' );
				if ( null === $style || false === stripos( $style, 'background-image' ) ) {
					continue;
				}
				if ( null !== $tags->get_attribute( 'data-wppo-bg' ) ) {
					continue;
				}
				if ( ! preg_match( '#background-image\s*:\s*([^;]+)#i', $style, $matches ) ) {
					continue;
				}

				$bg_value = trim( $matches[1] );
				if ( '' === $bg_value || false !== stripos( $bg_value, 'data:' ) ) {
					continue;
				}

				if ( '' !== $lcp_hero_normalized && preg_match( '#url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)#i', $bg_value, $url_match ) ) {
					$bg_candidate = trim( $url_match[1] );
					if ( '' !== $bg_candidate && 0 !== stripos( $bg_candidate, 'data:' ) && $this->normalize_image_url( $bg_candidate ) === $lcp_hero_normalized ) {
						continue;
					}
				}

				// Never defer the first N backgrounds (likely above-the-fold / hero).
				++$bg_counter;
				if ( $bg_counter <= $exclude_count ) {
					continue;
				}

				$class = (string) $tags->get_attribute( 'class' );
				if ( false === strpos( $class, 'wppo-lazy-bg' ) ) {
					$tags->set_attribute( 'class', trim( $class . ' wppo-lazy-bg' ) );
				}
				$tags->set_attribute( 'data-wppo-bg', $bg_value );

				// Drop the background-image declaration(s), keeping other style props.
				$new_style = trim( preg_replace( '#background-image\s*:\s*[^;]+;?#i', '', $style ) );
				if ( '' === $new_style ) {
					$tags->remove_attribute( 'style' );
				} else {
					$tags->set_attribute( 'style', $new_style );
				}
			}

			return $tags->get_updated_html();
		}

		/**
		 * Rewrites <video> elements so their media sources are deferred and restored later for lazy loading.
		 *
		 * Skips videos whose attributes or inner markup match configured exclusion patterns. For processed videos:
		 * - moves `src` attributes to `data-src` (on <video> and inner <source> tags),
		 * - removes `autoplay` and sets `data-wppo-autoplay="1"` when autoplay was present,
		 * - ensures `preload="none"` is set,
		 * - adds the `wppo-lazy-video` class,
		 * - defers `poster` to `data-poster` for core's animated-GIF companion videos (WP 7.1+, the `autoplay` + `loop` + `muted` + `playsinline` + `poster` signature), which the client restores on intersect.
		 *
		 * @since 2.0.0
		 * @since 2.0.0 Defer companion-video `poster` frames to `data-poster`.
		 *
		 * @param string $buffer HTML markup to process.
		 * @return string The HTML with video elements rewritten for lazy loading.
		 */
		public function lazy_load_videos( string $buffer ): string {
			$image_opts = $this->options['image_optimisation'] ?? array();

			if ( empty( $image_opts['lazyLoadVideos'] ) ) {
				return $buffer;
			}

			$exclude_videos = $this->exclude_lazy_videos;

			if ( class_exists( 'WP_HTML_Processor' ) ) {
				$all_processed = true;
				$wpp_result    = preg_replace_callback(
					'#<video\b([^>]*)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_videos, &$all_processed ) {
						$full_tag   = $matches[0];
						$attributes = $matches[1];
						$inner_html = $matches[2];

						// Check exclusions.
						foreach ( $exclude_videos as $exclude ) {
							if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $inner_html, $exclude ) ) {
								return $full_tag;
							}
						}

						$p = new \WP_HTML_Processor( $full_tag );
						if ( null === $p->get_last_error() && $p->next_tag( array( 'tag_name' => 'video' ) ) ) {
							$src = $p->get_attribute( 'src' );
							if ( $src ) {
								$p->set_attribute( 'data-src', $src );
								$p->remove_attribute( 'src' );
							}

							// Detect core's animated-GIF companion videos (WP 7.1):
							// autoplay + loop + muted + playsinline + a poster frame.
							$is_companion_video = null !== $p->get_attribute( 'autoplay' )
								&& null !== $p->get_attribute( 'loop' )
								&& null !== $p->get_attribute( 'muted' )
								&& null !== $p->get_attribute( 'playsinline' );

							$poster = $p->get_attribute( 'poster' );

							if ( null !== $p->get_attribute( 'autoplay' ) ) {
								$p->remove_attribute( 'autoplay' );
								$p->set_attribute( 'data-wppo-autoplay', '1' );
							}

							// Defer the poster so below-the-fold companion videos do not
							// eagerly fetch the GIF/first-frame image.
							if ( $is_companion_video && ! empty( $poster ) ) {
								$p->set_attribute( 'data-poster', $poster );
								$p->remove_attribute( 'poster' );
							}

							$p->set_attribute( 'preload', 'none' );
							$p->add_class( 'wppo-lazy-video' );

							while ( $p->next_tag( array( 'tag_name' => 'source' ) ) ) {
								$src = $p->get_attribute( 'src' );
								if ( $src ) {
									$p->set_attribute( 'data-src', $src );
									$p->remove_attribute( 'src' );
								}
							}
							return $p->get_updated_html();
						}

						$all_processed = false;
						return $full_tag;
					},
					$buffer
				);

				if ( $all_processed ) {
					return $wpp_result;
				}
				// Partial bail — use partially-processed buffer as input to TagProcessor fallback.
				$buffer = $wpp_result;
			}
			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return preg_replace_callback(
					'#<video\b([^>]*)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_videos ) {
						$attributes = $matches[1];
						$inner_html = $matches[2];
						$full_tag   = $matches[0];

						// Check exclusions.
						foreach ( $exclude_videos as $exclude ) {
							if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $inner_html, $exclude ) ) {
								return $full_tag;
							}
						}

						$tags = new \WP_HTML_Tag_Processor( $full_tag );

						// Process <video> tag.
						if ( $tags->next_tag( array( 'tag_name' => 'video' ) ) ) {
							$src = $tags->get_attribute( 'src' );
							if ( $src ) {
								$tags->set_attribute( 'data-src', $src );
								$tags->remove_attribute( 'src' );
							}

							// Detect core's animated-GIF companion videos (WP 7.1).
							$is_companion_video = null !== $tags->get_attribute( 'autoplay' )
								&& null !== $tags->get_attribute( 'loop' )
								&& null !== $tags->get_attribute( 'muted' )
								&& null !== $tags->get_attribute( 'playsinline' );

							$poster = $tags->get_attribute( 'poster' );

							if ( $tags->get_attribute( 'autoplay' ) !== null ) {
								$tags->remove_attribute( 'autoplay' );
								$tags->set_attribute( 'data-wppo-autoplay', '1' );
							}

							// Defer the poster for companion videos (restored on intersect).
							if ( $is_companion_video && ! empty( $poster ) ) {
								$tags->set_attribute( 'data-poster', $poster );
								$tags->remove_attribute( 'poster' );
							}

							$tags->set_attribute( 'preload', 'none' );
							$tags->add_class( 'wppo-lazy-video' );
						}

						// Process <source> tags inside.
						while ( $tags->next_tag( array( 'tag_name' => 'source' ) ) ) {
							$src = $tags->get_attribute( 'src' );
							if ( $src ) {
								$tags->set_attribute( 'data-src', $src );
								$tags->remove_attribute( 'src' );
							}
						}

						return $tags->get_updated_html();
					},
					$buffer
				);
			} else {
				// Regex Fallback (Original logic restored from git history).
				return preg_replace_callback(
					'#<video\b([^>]*)>(.*?)</video>#is',
					function ( $matches ) use ( $exclude_videos ) {
						$attributes = $matches[1];
						$inner_html = $matches[2];

						// Check exclusions against src or inner <source> tags.
						foreach ( $exclude_videos as $exclude ) {
							if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $inner_html, $exclude ) ) {
								return $matches[0];
							}
						}

						// Process <video src="..."> attribute.
						if ( preg_match( '#\bsrc=["\']([^"\']+)["\']#i', $attributes ) ) {
							$attributes = preg_replace( '#\bsrc=["\']([^"\']+)["\']#i', 'data-src="$1"', $attributes );
						}

						// Process inner <source src="..."> tags.
						$inner_html = preg_replace( '#(<source\b[^>]*)\bsrc=["\']([^"\']+)["\']#i', '$1 data-src="$2"', $inner_html );

						$had_autoplay = preg_match( '#\bautoplay\b#i', $attributes );

						// Remove autoplay to prevent the browser from trying to play immediately.
						$attributes = preg_replace( '#\bautoplay(=["\'][^"\']*["\'])?#i', '', $attributes );

						if ( $had_autoplay ) {
							$attributes .= ' data-wppo-autoplay="1"';
						}

						// Defer poster for core's animated-GIF companion videos (WP 7.1):
						// autoplay + loop + muted + playsinline + a poster frame.
						if ( $had_autoplay
							&& preg_match( '#\bloop\b#i', $attributes )
							&& preg_match( '#\bmuted\b#i', $attributes )
							&& preg_match( '#\bplaysinline\b#i', $attributes )
							&& preg_match( '#\bposter=["\']([^"\']+)["\']#i', $attributes, $poster_matches )
						) {
							$attributes = preg_replace( '#\bposter=["\']([^"\']+)["\']#i', 'data-poster="$1"', $attributes );
						}

						// Add preload="none" if not already present.
						if ( false === stripos( $attributes, 'preload' ) ) {
							$attributes .= ' preload="none"';
						} else {
							$attributes = preg_replace( '#\bpreload=["\'][^"\']*["\']#i', 'preload="none"', $attributes );
						}

						// Add a marker class for the IntersectionObserver.
						if ( false === strpos( $attributes, 'wppo-lazy-video' ) ) {
							if ( preg_match( '#\bclass=["\']([^"\']*)["\']#i', $attributes, $class_matches ) ) {
								$attributes = str_replace( $class_matches[0], 'class="' . $class_matches[1] . ' wppo-lazy-video"', $attributes );
							} else {
								$attributes .= ' class="wppo-lazy-video"';
							}
						}

						return "<video $attributes>$inner_html</video>";
					},
					$buffer
				);
			}
		}

		/**
		 * Lazily render below-fold containers via content-visibility.
		 *
		 * Opt-in, CSS-only progressive enhancement: tags below-fold candidate
		 * containers (builder sections, footer widgets, comments) with
		 * `content-visibility:auto` plus a precomputed `contain-intrinsic-size`
		 * reserve so layout stays stable (no CLS) while the browser defers
		 * render work until the node nears the viewport. Unsupported browsers
		 * ignore the declarations. Any failure returns markup unmodified
		 * (fail-open).
		 *
		 * Hero safety: the first matching section stays eager (positional),
		 * and any later section carrying LCP-hero markers (`fetchpriority`
		 * high, `data-lcp`/`data-hero`, hero/LCP class or id, or an LCP
		 * image in its scope) is skipped too, so the LCP hero never gets
		 * `content-visibility` regardless of which section carries it.
		 * Skipping is fail-safe (unoptimised, never fatal).
		 *
		 * @since 2.0.0
		 *
		 * @param string $buffer The HTML buffer to process.
		 * @return string The modified HTML buffer.
		 */
		public function lazy_render_elements( string $buffer ): string {
			$image_opts = $this->options['image_optimisation'] ?? array();

			if ( empty( $image_opts['lazyRenderBelowFold'] ) ) {
				return $buffer;
			}
			if ( ! is_string( $buffer ) || '' === $buffer ) {
				return $buffer;
			}
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return $buffer;
			}
			if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
				return $buffer;
			}
			if ( ( function_exists( 'is_feed' ) && is_feed() )
				|| ( function_exists( 'is_preview' ) && is_preview() )
				|| ( function_exists( 'is_embed' ) && is_embed() )
				|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
				return $buffer;
			}
			if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
				return $buffer;
			}

			try {
				$exclude_builders = ! isset( $image_opts['lazyRenderExcludeBuilders'] ) || ! empty( $image_opts['lazyRenderExcludeBuilders'] );

				$excluded_classes = array( 'elementor-section', 'et_pb_section' );
				if ( function_exists( 'apply_filters' ) ) {
					$filtered = apply_filters( 'wppo_lazy_render_excluded_classes', $excluded_classes );
					if ( is_array( $filtered ) ) {
						$excluded_classes = array_values( array_filter( array_map( 'strval', $filtered ) ) );
					}
				}

				$intrinsic_size = 'auto 600px';
				if ( function_exists( 'apply_filters' ) ) {
					$filtered_size = apply_filters( 'wppo_lazy_render_intrinsic_size', $intrinsic_size );
					if ( is_string( $filtered_size ) && '' !== trim( $filtered_size ) ) {
						$intrinsic_size = trim( $filtered_size );
					}
				}

				$target_classes = array(
					'elementor-section',
					'et_pb_section',
					'wp-block-group',
					'footer-widget',
					'widget-area',
					'comments-area',
					'comment-list',
				);

				$processor = new \WP_HTML_Tag_Processor( $buffer );
				// Per-section LCP windows (section/div document order) so an
				// LCP image nested inside a plain container still marks its
				// section as hero. Fail-open: empty on any failure, which
				// degrades to positional + opening-tag marker detection.
				$lcp_windows  = $this->get_lazy_render_lcp_windows( $buffer );
				$section_seq  = -1;
				$hero_skipped = false;

				while ( $processor->next_tag() ) {
					$tag = strtoupper( $processor->get_tag() ?? '' );
					if ( 'SECTION' !== $tag && 'FOOTER' !== $tag && 'ASIDE' !== $tag && 'DIV' !== $tag ) {
						continue;
					}

					// Sequence among section/div opening tags (all of them,
					// not just targeted ones) to align with $lcp_windows.
					if ( 'SECTION' === $tag || 'DIV' === $tag ) {
						++$section_seq;
					}

					$class_attr = (string) ( $processor->get_attribute( 'class' ) ?? '' );
					$id_attr    = (string) ( $processor->get_attribute( 'id' ) ?? '' );
					$lower_cls  = strtolower( $class_attr . ' ' . $id_attr );

					$is_footer_tag = ( 'FOOTER' === $tag || 'ASIDE' === $tag );
					$is_targeted   = $is_footer_tag;
					if ( ! $is_targeted ) {
						foreach ( $target_classes as $needle ) {
							if ( false !== strpos( $lower_cls, $needle ) ) {
								$is_targeted = true;
								break;
							}
						}
						if ( ! $is_targeted && 'comments' === strtolower( $id_attr ) ) {
							$is_targeted = true;
						}
					}
					if ( ! $is_targeted ) {
						continue;
					}

					if ( $exclude_builders ) {
						$class_tokens = preg_split( '/\s+/', strtolower( $class_attr ), -1, PREG_SPLIT_NO_EMPTY );
						$class_tokens = is_array( $class_tokens ) ? $class_tokens : array();
						foreach ( $excluded_classes as $excluded ) {
							$excluded_token = strtolower( trim( (string) $excluded ) );
							if ( '' !== $excluded_token && in_array( $excluded_token, $class_tokens, true ) ) {
								$is_targeted = false;
								break;
							}
						}
						if ( ! $is_targeted ) {
							continue;
						}
					}

					// Keep hero content eager: the first matching section is
					// always skipped (positional above-the-fold), and any
					// later section carrying LCP-hero markers is skipped too
					// so a hero built from a second section or a Gutenberg
					// group with an LCP image never gets content-visibility.
					// Footer/aside/comment nodes are never the hero: they are
					// tagged whenever targeted (and never consume the hero
					// skip, so a footer-first DOM still skips the hero).
					if ( ! $is_footer_tag
						&& false === strpos( $lower_cls, 'comment' ) && false === strpos( $lower_cls, 'footer' ) ) {
						if ( ! $hero_skipped ) {
							$hero_skipped = true;
							continue;
						}
						if ( $this->is_lazy_render_hero_tag( $processor, $lower_cls ) ) {
							continue;
						}
						if ( isset( $lcp_windows[ $section_seq ] ) && $lcp_windows[ $section_seq ] ) {
							continue;
						}
					}

					$style = (string) ( $processor->get_attribute( 'style' ) ?? '' );
					if ( '' !== $style && preg_match( '#content-visibility\s*:#i', $style ) ) {
						continue;
					}

					$addition = 'content-visibility:auto;contain-intrinsic-size:' . $intrinsic_size;
					$merged   = '' === trim( $style ) ? $addition : rtrim( trim( $style ), ';' ) . ';' . $addition;
					$processor->set_attribute( 'style', $merged );
				}

				$updated = $processor->get_updated_html();
				return is_string( $updated ) && '' !== $updated ? $updated : $buffer;
			} catch ( \Throwable $e ) {
				if ( function_exists( 'do_action' ) ) {
					do_action( 'wppo_debug_log', 'WPPO lazy render failed: ' . $e->getMessage(), array( 'exception' => $e ) );
				}
				return $buffer;
			}
		}

		/**
		 * Whether a lazy-render candidate carries LCP-hero markers on its opening tag.
		 *
		 * Checks `fetchpriority="high"`, `data-lcp`/`data-hero` attributes,
		 * and hero/LCP tokens in the class/id string. Any match means the
		 * node may hold above-fold LCP content and must stay eager.
		 * Fail-safe: any failure returns false (caller falls back to the
		 * positional skip and the scoped window check).
		 *
		 * @since NEXT
		 *
		 * @param mixed  $processor Tag processor positioned on the candidate tag.
		 * @param string $lower_cls Lowercased class + id string of the candidate.
		 * @return bool True when the opening tag itself marks an LCP hero.
		 */
		private function is_lazy_render_hero_tag( $processor, string $lower_cls ): bool {
			try {
				if ( is_object( $processor ) && method_exists( $processor, 'get_attribute' ) ) {
					$fetchpriority = strtolower( (string) ( $processor->get_attribute( 'fetchpriority' ) ?? '' ) );
					if ( 'high' === $fetchpriority ) {
						return true;
					}
					foreach ( array( 'data-lcp', 'data-hero', 'data-od-hero', 'data-wppo-lcp' ) as $attr ) {
						if ( null !== $processor->get_attribute( $attr ) ) {
							return true;
						}
					}
				}
				if ( false !== strpos( $lower_cls, 'hero' ) || false !== strpos( $lower_cls, 'lcp' ) ) {
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Map section/div opening-tag order to scoped LCP presence.
		 *
		 * Walks the same `WP_HTML_Tag_Processor` tag stream the
		 * `lazy_render_elements()` consumer loop walks (plain `next_tag()`,
		 * which skips closers, comments, and RAWTEXT/RCDATA bodies such as
		 * `<script>`/`<style>`/`<textarea>`/`<title>` contents), so the
		 * sequence index cannot diverge from `$section_seq`: index `$i`
		 * always describes the same opening tag in both enumerations.
		 * Each window spans the tokens from one `<section>`/`<div>`
		 * opener up to (but excluding) the next one, unbounded. A window
		 * is marked when its opener or any tag inside it carries an LCP
		 * marker (`fetchpriority="high"`, `data-lcp`, `data-hero`,
		 * `data-od-hero`, `data-wppo-lcp` as real attributes on real
		 * tags). Lets a plain container wrapping an LCP image still count
		 * as hero. Fail-open: any failure returns an empty map (no scoped
		 * skips). Note the marker check is intentionally narrower than a
		 * raw substring search: marker-looking text inside comments,
		 * script bodies, or plain text no longer marks a window, which
		 * only removes false-positive skips (missed optimisation), never
		 * mistags a hero.
		 *
		 * @since NEXT
		 *
		 * @param string $buffer The HTML buffer to scan.
		 * @return bool[] LCP presence by section/div sequence index.
		 */
		private function get_lazy_render_lcp_windows( string $buffer ): array {
			try {
				if ( '' === $buffer ) {
					return array();
				}
				$processor = new \WP_HTML_Tag_Processor( $buffer );
				$map       = array();
				$idx       = -1;
				while ( $processor->next_tag() ) {
					$tag = strtoupper( $processor->get_tag() ?? '' );
					if ( 'SECTION' === $tag || 'DIV' === $tag ) {
						++$idx;
						$map[ $idx ] = $this->processor_tag_has_lcp_marker( $processor );
						continue;
					}
					if ( $idx < 0 || ! empty( $map[ $idx ] ) ) {
						continue;
					}
					if ( $this->processor_tag_has_lcp_marker( $processor ) ) {
						$map[ $idx ] = true;
					}
				}
				return $map;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether the processor's current tag carries an LCP marker attribute.
		 *
		 * Checks `fetchpriority="high"` and the `data-lcp`/`data-hero`/
		 * `data-od-hero`/`data-wppo-lcp` attributes. Only real attributes
		 * on real tags match: marker-looking text inside comments, script
		 * bodies, or attribute values does not count.
		 * Fail-safe: any failure returns false.
		 *
		 * @since NEXT
		 *
		 * @param mixed $processor Tag processor positioned on the current tag.
		 * @return bool True when the current tag carries an LCP marker.
		 */
		private function processor_tag_has_lcp_marker( $processor ): bool {
			try {
				if ( ! is_object( $processor ) || ! method_exists( $processor, 'get_attribute' ) ) {
					return false;
				}
				$fetchpriority = strtolower( (string) ( $processor->get_attribute( 'fetchpriority' ) ?? '' ) );
				if ( 'high' === $fetchpriority ) {
					return true;
				}
				foreach ( array( 'data-lcp', 'data-hero', 'data-od-hero', 'data-wppo-lcp' ) as $attr ) {
					if ( null !== $processor->get_attribute( $attr ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}
	}
}
