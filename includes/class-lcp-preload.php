<?php
/**
 * LCP/hero-preload service — LCP resolution, preload emission, and dedup/slot state.
 *
 * ARCH-008: the LCP/hero-preload cluster (`has/mark_preload_emitted()`,
 * hero slot `claim/release`, LCP resolution
 * manual > RUM-field > OD > auto > heuristic, responsive/breakpoint preload
 * emission, `fetchpriority` stamping, preload dedup keys, buffer preload
 * companions) previously lived on the `Image_Optimisation` lazy/media
 * orchestrator. The cluster shares one responsibility (decide the LCP hero
 * and emit exactly one preload hint for it), one trigger set (`wp_head`
 * preload, buffer hero companions, attachment `fetchpriority` filter), and
 * one correctness contract (resolution precedence, exactly-once dedup, slot
 * exclusivity, exclusion lists) — so it lives here.
 *
 * `Image_Optimisation` keeps thin same-signature proxies (facade rule)
 * delegating to a lazy instance of this class, so every static caller
 * (`Critical_CSS` field-LCP preload, `Main`, `Deactivate`, `OD_Bridge`),
 * every instance caller (lazy/media pipeline, `Hook_Registry` hook
 * callbacks at identical priorities), and every reflection-based test stays
 * byte-identical with zero caller migration. Service methods are public
 * (widened from private, ARCH-005/006/007 precedent) so the private
 * `Image_Optimisation` proxies can delegate; the widened helpers are
 * `@internal` — not public API. Always call via the `Image_Optimisation`
 * facade proxies, never directly on this service.
 *
 * State strategy (Option A — ARCH-004/005/006/007 bridge precedent): ALL
 * `Image_Optimisation` instance state (options snapshot, LCP memo props,
 * constructor-derived URL lists) stays on `Image_Optimisation` as the single
 * source of truth; this service holds the owning instance and reaches it
 * through the `@internal` `lcp_*()` bridges — never a new write path. The
 * four per-request static memos (`$preload_emitted`,
 * `$preload_emitted_urls`, `$heuristic_lcp_memo`,
 * `$responsive_lcp_preload_emitted`) move here with identical keying; reset
 * wiring (`clear_lcp_preload_caches()`, `clear_heuristic_lcp_memo()`) is
 * invoked from `Image_Optimisation::clear_runtime_caches()` /
 * `clear_instance_lcp_memo()`, which stay wired to `switch_blog` and
 * `wppo_after_cache_clear` exactly as before. The `$lcp_priority_applied`
 * run-once flag moves here; the service is instantiated once per owning
 * `Image_Optimisation`, so its lifecycle matches the pre-extraction
 * instance flag exactly.
 *
 * Multisite: the heuristic memo key is blog-scoped (`blog_id:md5(buffer)`)
 * so same-path-different-blog buffers can never share a verdict, even
 * inside one long-lived request; the dedup/slot sets are per-request and
 * flushed on `switch_blog` via the owner reset above. Options reads flow
 * through the owner (`lcp_get_options()`), so they stay per-site.
 *
 * No persist-path or option changes; hook names, priorities, and callback
 * identity are untouched (callbacks stay on `Image_Optimisation` proxies).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Lcp_Preload' ) ) {

	/**
	 * Class Lcp_Preload
	 *
	 * Owns LCP resolution, hero-preload emission, and preload dedup/slot
	 * state. Constructed with the `Image_Optimisation` instance so options
	 * reads, LCP memos, and lazy/media collaborators keep the exact
	 * semantics the bodies had on `Image_Optimisation` (instance state
	 * stays on the owner as the single source of truth, accessed through
	 * the `@internal` `lcp_*()` bridges — never a new write path).
	 *
	 * @since NEXT
	 */
	final class Lcp_Preload {


		/**
		 * Image_Optimisation instance owning options, LCP memos, and lazy/media collaborators.
		 *
		 * Held by reference target (not a copy) so state reads/writes land on
		 * the live request state exactly as `$this->prop` accesses did before
		 * the extraction.
		 *
		 * @since NEXT
		 * @var   Image_Optimisation
		 */
		private Image_Optimisation $owner;

		/**
		 * Constructor.
		 *
		 * @since NEXT
		 * @param Image_Optimisation $owner Image_Optimisation instance (options + memo + collaborator owner).
		 */
		public function __construct( Image_Optimisation $owner ) {
			$this->owner = $owner;
		}

		/**
		 * Default maximum width for preloading images.
		 *
		 * @since 1.5.1
		 */
		private const MAX_PRELOAD_WIDTH = 1478;

		/**
		 * Maximum image preload hints emitted per page (manual wins on conflict).
		 *
		 * Manual lists are ordered first in `get_all_preload_data()` so the
		 * slice keeps pinned heroes when auto + manual overlap. Competitor
		 * parity (one hero preload) with a hard cap against preload waste.
		 *
		 * @since 2.2.0
		 */
		private const MAX_LCP_PRELOADS = 2;

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
		 * @since 2.2.0
		 */
		private static array $preload_emitted_urls = array();

		/**
		 * Per-request heuristic LCP memo keyed by buffer hash (issue #1216).
		 *
		 * The P2 DOM-first heuristic re-scans full HTML with
		 * `WP_HTML_Tag_Processor` on each caller (preload data, lazy
		 * exclusion, hero inject); this static memo resolves each distinct
		 * buffer once per request. Bounded: reset once past 30 entries.
		 *
		 * @var array<string, string>
		 * @since 2.2.0
		 */
		private static $heuristic_lcp_memo = array();

		/**
		 * Whether a responsive LCP preload already emitted this response.
		 *
		 * Single-high invariant (issue #1429): `emit_responsive_lcp_preload()`
		 * sets this once a `<link ... fetchpriority="high">` is produced so
		 * a second call in the same response degrades to '' instead of a
		 * second high hint. Reset via `clear_runtime_caches()`.
		 *
		 * @since 2.3.0
		 * @var bool
		 */
		private static $responsive_lcp_preload_emitted = false;

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
		 * Clear the per-request LCP/preload static caches.
		 *
		 * Invoked from `Image_Optimisation::clear_runtime_caches()` (wired to
		 * `switch_blog` and `wppo_after_cache_clear`); never call directly.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function clear_lcp_preload_caches(): void {
			self::$preload_emitted                = array();
			self::$preload_emitted_urls           = array();
			self::$heuristic_lcp_memo             = array();
			self::$responsive_lcp_preload_emitted = false;
		}

		/**
		 * Clear the heuristic LCP memo only.
		 *
		 * Invoked from `Image_Optimisation::clear_instance_lcp_memo()`;
		 * never call directly.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function clear_heuristic_lcp_memo(): void {
			self::$heuristic_lcp_memo = array();
		}

		/**
		 * Blog-scoped key for the heuristic LCP memo.
		 *
		 * Multisite hardening (ARCH-008): the pre-extraction memo keyed by
		 * buffer hash alone, so same-path-different-blog pages with identical
		 * markup could share one site's verdict inside a long-lived request.
		 * Prefixing the current blog id keeps the per-buffer dedup while
		 * isolating sites. Internal key only; emitted markup is unchanged.
		 * Fail-open to the unprefixed hash.
		 *
		 * @since NEXT
		 * @param string $buffer HTML buffer.
		 * @return string Memo key.
		 */
		private static function heuristic_memo_key( string $buffer ): string {
			try {
				$hash = md5( $buffer );
				$blog = 0;
				if ( function_exists( 'get_current_blog_id' ) ) {
					$blog = (int) get_current_blog_id();
				}
				return $blog . ':' . $hash;
			} catch ( \Throwable $e ) {
				unset( $e );
				return md5( $buffer );
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
		 * @since 2.2.0
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
		 * @since 2.2.0
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
		 * @since 2.2.0
		 * @param string $url The raw preload URL.
		 * @return void
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public static function record_direct_preload_url( string $url ): void {
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
		 * @since 2.2.0
		 * @return string[] Normalized direct-preload URLs.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public static function get_direct_preload_normalized_urls(): array {
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
		 * @since 2.2.0
		 *
		 * @param string $url   The raw preload URL.
		 * @param string $media The preload media attribute.
		 * @return string The dedup key.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public static function build_preload_dedup_key( string $url, string $media ): string {
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
		 * @since 2.2.0
		 * @param string $url The raw hero URL.
		 * @return bool True when the URL already emitted with any media.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public static function is_hero_preload_claimed( string $url ): bool {
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
		 * @since 2.2.0
		 * @param string      $url    The raw hero URL.
		 * @param string      $media  The preload media attribute ('' for buffer companions).
		 * @param string|null $buffer Optional HTML buffer to scan for an existing hint.
		 * @return bool True when the caller may emit (slot claimed), false to skip.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function claim_hero_preload_slot( string $url, string $media = '', ?string $buffer = null ): bool {
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
		 * @since 2.2.0
		 * @param string $url   The raw hero URL.
		 * @param string $media The preload media attribute ('' for buffer companions).
		 * @return void
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public static function release_hero_preload_slot( string $url, string $media = '' ): void {
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
		 * @since 2.2.0
		 * @param string $url The candidate URL.
		 * @return bool True when the URL host matches a configured CDN host.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_cdn_preload_url( string $url ): bool {
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
		 * @since 2.2.0
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_allowed_hero_preload_url( string $url ): bool {
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
		 * @since 2.2.0
		 * @return bool True when the HTML API may be used.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_html_api_available(): bool {
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
		 * @since 2.2.0
		 * @param string|null $buffer Optional HTML buffer passed to the filter for context.
		 * @return string The computed hero URL, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_computed_css_hero_url( ?string $buffer = null ): string {
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
		 * @since 2.2.0
		 * @param string $buffer The HTML buffer.
		 * @return string The buffer with high-priority nodes forced eager.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function sweep_lazy_high_conflicts( string $buffer ): string {
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
		 * Whether occlusion-aware fetchpriority=low demotion is enabled.
		 *
		 * Additive `image_optimisation.occlusionFetchpriorityLow` flag,
		 * default off (backward compatible). Guarded with
		 * `function_exists()`/`has_filter()` so behaviour is unchanged
		 * when no callback is registered. Fail-open to false.
		 *
		 * @since 2.3.0
		 * @return bool True when occluded nodes should be demoted to low.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_occlusion_fetchpriority_low_enabled(): bool {
		$lcpown_options = $this->owner->lcp_get_options();
			try {
				$image_optimisation = $lcpown_options['image_optimisation'] ?? array();
				$enabled            = ! empty( $image_optimisation['occlusionFetchpriorityLow'] );
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_occlusion_fetchpriority_low_enabled' ) ) {
					/**
					 * Filters whether OD-occluded images are demoted to fetchpriority low.
					 *
					 * @since 2.3.0
					 * @param bool $enabled Whether occlusion demotion is enabled.
					 */
					$enabled = (bool) apply_filters( 'wppo_occlusion_fetchpriority_low_enabled', $enabled );
				}
				return $enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Resolve OD-occluded image URLs for the current request.
		 *
		 * Guarded with `class_exists()`/`method_exists()` plus the OD
		 * availability check inside the bridge; legacy fallback (OD absent
		 * or any failure) is an empty list meaning no attribute change.
		 * Multisite-safe: per-URL metrics only, no cross-site leakage.
		 *
		 * @since 2.3.0
		 * @return string[] Occluded image URLs (may be empty).
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_occluded_image_urls_for_request(): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) || ! method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_occluded_image_urls' ) ) {
					return array();
				}
				$urls = \PerformanceOptimise\Inc\OD_Bridge::get_occluded_image_urls();
				return is_array( $urls ) ? $urls : array();
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Demote OD-occluded in-viewport images to fetchpriority=low.
		 *
		 * Core-parity with the WordPress Performance Team direction
		 * (OD-driven priority): hidden carousels/hidden heroes must not
		 * steal bandwidth from the real LCP. For each `<img>` whose
		 * normalized src/srcset matches an occluded URL, sets
		 * `fetchpriority="low"` only when the attribute is absent (never
		 * overwrites an explicit value, never touches the true-LCP node
		 * matched by `$lcp_url`). Never adds or changes `loading`:
		 * occluded nodes must not gain `loading="lazy"`, and the
		 * pre-existing `sweep_lazy_high_conflicts()` keeps the lazy+high
		 * invariant intact. Uses `WP_HTML_Tag_Processor` when
		 * `is_html_api_available()` (WordPress 6.2+) else the regex
		 * fallback. Fail-open: any failure returns `$buffer` unchanged.
		 *
		 * @since 2.3.0
		 * @param string      $buffer        The HTML buffer.
		 * @param string[]    $occluded_urls Raw occluded image URLs.
		 * @param string|null $lcp_url       Optional true-LCP URL to protect.
		 * @return string The buffer with occluded nodes demoted to low.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function apply_occlusion_fetchpriority_low( string $buffer, array $occluded_urls, ?string $lcp_url = null ): string {
			try {
				if ( '' === $buffer || empty( $occluded_urls ) || false === stripos( $buffer, '<img' ) ) {
					return $buffer;
				}
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_occlusion_fetchpriority_low_urls' ) ) {
					/**
					 * Filters the occluded image URL list before fetchpriority demotion.
					 *
					 * @since 2.3.0
					 * @param string[] $occluded_urls Occluded image URLs.
					 * @param string   $buffer        The HTML buffer being processed.
					 */
					$filtered = apply_filters( 'wppo_occlusion_fetchpriority_low_urls', $occluded_urls, $buffer );
					if ( is_array( $filtered ) ) {
						$occluded_urls = $filtered;
					}
				}
				$occluded_set = array();
				foreach ( $occluded_urls as $u ) {
					if ( ! is_string( $u ) || '' === trim( $u ) ) {
						continue;
					}
					// Parity with OD_Bridge::get_occluded_image_urls(): reject
					// non-http(s) schemes before normalization resolves them
					// against the home URL into a host+path key that could
					// coincidentally match a real <img>.
					$trimmed = trim( $u );
					$scheme  = function_exists( 'wp_parse_url' ) ? wp_parse_url( $trimmed, PHP_URL_SCHEME ) : parse_url( $trimmed, PHP_URL_SCHEME ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
					if ( is_string( $scheme ) && '' !== $scheme && ! in_array( strtolower( $scheme ), array( 'http', 'https' ), true ) ) {
						continue;
					}
					$norm = $this->normalize_image_url( $u );
					if ( '' !== $norm ) {
						$occluded_set[ $norm ] = true;
					}
				}
				if ( empty( $occluded_set ) ) {
					return $buffer;
				}
				$normalized_lcp = '';
				if ( is_string( $lcp_url ) && '' !== $lcp_url ) {
					$normalized_lcp = $this->normalize_image_url( $lcp_url );
				}
				// Per-buffer memo of normalized candidate URLs: the closures
				// below run once per <img> tag, so identical src/srcset values
				// across image-heavy pages normalize once instead of N times.
				$norm_cache           = array();
				$tag_is_protected_lcp = function ( array $candidates ) use ( $normalized_lcp, &$norm_cache ): bool {
					if ( '' === $normalized_lcp ) {
						return false;
					}
					foreach ( $candidates as $candidate ) {
						if ( '' === $candidate ) {
							continue;
						}
						if ( ! array_key_exists( $candidate, $norm_cache ) ) {
							$norm_cache[ $candidate ] = $this->normalize_image_url( $candidate );
						}
						if ( $norm_cache[ $candidate ] === $normalized_lcp ) {
							return true;
						}
					}
					return false;
				};
				$tag_is_occluded      = function ( array $candidates ) use ( $occluded_set, &$norm_cache ): bool {
					foreach ( $candidates as $candidate ) {
						if ( '' === $candidate ) {
							continue;
						}
						if ( ! array_key_exists( $candidate, $norm_cache ) ) {
							$norm_cache[ $candidate ] = $this->normalize_image_url( $candidate );
						}
						if ( isset( $occluded_set[ $norm_cache[ $candidate ] ] ) ) {
							return true;
						}
					}
					return false;
				};
				if ( ! $this->is_html_api_available() ) {
					$result = preg_replace_callback(
						'#<img\b[^>]*>#i',
						function ( $matches ) use ( $tag_is_protected_lcp, $tag_is_occluded ) {
							$tag = $matches[0];
							if ( 1 === preg_match( '#\sfetchpriority\s*=#i', $tag ) ) {
								return $tag;
							}
							$candidates = array();
							if ( 1 === preg_match_all( '#\s(?:src|data-src)\s*=\s*["\']?([^"\'\s>]+)#i', $tag, $m ) && isset( $m[1] ) && is_array( $m[1] ) ) {
								foreach ( $m[1] as $v ) {
									$candidates[] = (string) $v;
								}
							}
							if ( 1 === preg_match_all( '#\ssrcset\s*=\s*["\']([^"\']+)["\']#i', $tag, $sm ) && isset( $sm[1] ) && is_array( $sm[1] ) ) {
								foreach ( $sm[1] as $srcset ) {
									foreach ( $this->owner->lcp_split_srcset_candidates( (string) $srcset ) as $part ) {
										$url = $this->owner->lcp_split_srcset_item( $part )[0];
										if ( '' !== $url ) {
											$candidates[] = $url;
										}
									}
								}
							}
							if ( empty( $candidates ) ) {
								return $tag;
							}
							if ( $tag_is_protected_lcp( $candidates ) ) {
								return $tag;
							}
							if ( ! $tag_is_occluded( $candidates ) ) {
								return $tag;
							}
							return (string) preg_replace( '#<img\b#i', '<img fetchpriority="low"', $tag, 1 );
						},
						$buffer
					);
					return is_string( $result ) ? $result : $buffer;
				}
				$tags    = new \WP_HTML_Tag_Processor( $buffer );
				$changed = false;
				while ( $tags->next_tag( array( 'tag_name' => 'img' ) ) ) {
					$existing = $tags->get_attribute( 'fetchpriority' );
					if ( null !== $existing ) {
						continue;
					}
					$candidates = array();
					foreach ( array( 'src', 'data-src' ) as $attribute ) {
						$value = $tags->get_attribute( $attribute );
						if ( is_string( $value ) && '' !== $value ) {
							$candidates[] = $value;
						}
					}
					$srcset = $tags->get_attribute( 'srcset' );
					if ( is_string( $srcset ) && '' !== $srcset ) {
						foreach ( $this->owner->lcp_split_srcset_candidates( $srcset ) as $part ) {
							$url = $this->owner->lcp_split_srcset_item( $part )[0];
							if ( '' !== $url ) {
								$candidates[] = $url;
							}
						}
					}
					if ( empty( $candidates ) ) {
						continue;
					}
					if ( $tag_is_protected_lcp( $candidates ) ) {
						continue;
					}
					if ( ! $tag_is_occluded( $candidates ) ) {
						continue;
					}
					$tags->set_attribute( 'fetchpriority', 'low' );
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
		 * Retrieves all preloading data from front-page, post meta, and post types.
		 *
		 * @since 1.5.1
		 * @return array List of preload data items.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_all_preload_data(): array {
		$lcpown_options = $this->owner->lcp_get_options();
			$image_optimisation = $lcpown_options['image_optimisation'] ?? array();

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
		 * @since 2.2.0
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded as an image.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_image_lcp_url( string $url ): bool {
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
		 * @since 2.2.0
		 * @return string The manual LCP image URL, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_manual_lcp_url(): string {
		$lcpown_manual_lcp_url =& $this->owner->lcp_state_manual_lcp_url();
		$lcpown_manual_lcp_url_key =& $this->owner->lcp_state_manual_lcp_url_key();
			try {
				if ( ! function_exists( 'is_singular' ) || ! function_exists( 'get_the_ID' ) || ! function_exists( 'get_post_meta' ) ) {
					return '';
				}
				if ( ! is_singular() ) {
					return '';
				}
				$post_id = (int) get_the_ID();
				if ( null !== $lcpown_manual_lcp_url && $lcpown_manual_lcp_url_key === $post_id ) {
					return $lcpown_manual_lcp_url;
				}
				if ( empty( $post_id ) ) {
					$lcpown_manual_lcp_url     = '';
					$lcpown_manual_lcp_url_key = $post_id;
					return '';
				}
				$raw = get_post_meta( $post_id, '_wppo_lcp_preload_url', true );
				if ( ! is_string( $raw ) || '' === trim( $raw ) ) {
					$lcpown_manual_lcp_url     = '';
					$lcpown_manual_lcp_url_key = $post_id;
					return '';
				}
				$url = function_exists( 'esc_url_raw' ) ? esc_url_raw( trim( substr( $raw, 0, 2048 ) ) ) : trim( substr( $raw, 0, 2048 ) );
				if ( '' === $url || ! $this->is_image_lcp_url( $url ) ) {
					$lcpown_manual_lcp_url     = '';
					$lcpown_manual_lcp_url_key = $post_id;
					return '';
				}
				$lcpown_manual_lcp_url     = $url;
				$lcpown_manual_lcp_url_key = $post_id;
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
		 * @since 2.2.0
		 * @param string $url The candidate URL.
		 * @return bool True when the URL may be preloaded.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_same_origin_preload_url( string $url ): bool {
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
		 * @since 2.2.0
		 * @return bool True when core may be consulted for a node verdict.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_core_loading_optimization_available(): bool {
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
		 * @since 2.2.0
		 * @param mixed $tags Tag processor positioned on an `<img>` node.
		 * @return array{decoding?:string}|null Core's verdict, or null.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_core_loading_verdict_for_tag( $tags ): ?array {
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
		 * @since 2.2.0
		 * @return bool True when auto-LCP must be skipped for this post.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_auto_lcp_disabled_for_post(): bool {
		$lcpown_auto_lcp_disabled =& $this->owner->lcp_state_auto_lcp_disabled();
		$lcpown_auto_lcp_disabled_key =& $this->owner->lcp_state_auto_lcp_disabled_key();
			try {
				if ( ! function_exists( 'is_singular' ) || ! function_exists( 'get_the_ID' ) || ! function_exists( 'get_post_meta' ) ) {
					return false;
				}
				if ( ! is_singular() ) {
					return false;
				}
				$post_id = (int) get_the_ID();
				if ( null !== $lcpown_auto_lcp_disabled && $lcpown_auto_lcp_disabled_key === $post_id ) {
					return $lcpown_auto_lcp_disabled;
				}
				if ( empty( $post_id ) ) {
					$lcpown_auto_lcp_disabled     = false;
					$lcpown_auto_lcp_disabled_key = $post_id;
					return false;
				}
				$disabled                    = ! empty( get_post_meta( $post_id, '_wppo_disable_auto_lcp', true ) );
				$lcpown_auto_lcp_disabled     = $disabled;
				$lcpown_auto_lcp_disabled_key = $post_id;
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
		 * @since 2.2.0
		 * @return string The stable signal LCP image URL, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_stable_signal_lcp_url(): string {
		$lcpown_stable_signal_lcp_url =& $this->owner->lcp_state_stable_signal_lcp_url();
		$lcpown_stable_signal_lcp_url_key =& $this->owner->lcp_state_stable_signal_lcp_url_key();
			$memo_key = $this->get_lcp_memo_key();
			if ( null !== $lcpown_stable_signal_lcp_url && $lcpown_stable_signal_lcp_url_key === $memo_key ) {
				return $lcpown_stable_signal_lcp_url;
			}
			$resolved = '';
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					$lcpown_stable_signal_lcp_url     = '';
					$lcpown_stable_signal_lcp_url_key = $memo_key;
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

			$lcpown_stable_signal_lcp_url     = $resolved;
			$lcpown_stable_signal_lcp_url_key = $memo_key;
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
		 * @since 2.2.0
		 * @return string The OD-only LCP image URL, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function resolve_od_only_lcp_url(): string {
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
		 * @since 2.2.0
		 * @param string|null $buffer Optional HTML buffer for the heuristic fallback.
		 * @return string The LCP image URL, or empty string when none resolves.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function resolve_auto_lcp_url( ?string $buffer = null ): string {
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
		 * @since 2.2.0
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
		 * @since 2.2.0
		 * @param string      $lcp_url The resolved LCP image URL.
		 * @param string|null $buffer  Optional HTML buffer to scan.
		 * @return string The srcset value, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_lcp_srcset_for_url( string $lcp_url, ?string $buffer = null ): string {
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
		 * @since 2.2.0
		 * @param string      $lcp_url The resolved LCP image URL.
		 * @param string|null $buffer  Optional HTML buffer to scan.
		 * @return string The sizes value, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_lcp_sizes_for_url( string $lcp_url, ?string $buffer = null ): string {
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
		 * Emit a breakpoint-specific responsive LCP preload.
		 *
		 * Breakpoint-correct single-preload emitter (issue #1429): resolves
		 * OD per-viewport LCP elements first
		 * (`OD_Bridge::get_breakpoint_lcp_elements()`, guarded), RUM
		 * field-LCP second (`RUM::get_field_lcp_url()`, guarded), and emits
		 * exactly one `<link rel="preload" as="image" fetchpriority="high">`
		 * with matching `imagesrcset`+`imagesizes` (escaped via `esc_attr()`
		 * inside `Util::get_preload_link()`). Picture, CSS-background, and
		 * video-poster LCP variants are covered per `type`; art-directed
		 * `picture` entries carrying `media` are skipped fail-open (returns
		 * '') instead of mispredicting. Without OD/RUM data returns '' so
		 * the caller falls back to the legacy single-URL hero preload;
		 * never more than one fetchpriority high per response (per-response
		 * flag + `claim_hero_preload_slot()` + buffer high-hint scan).
		 * Guards OD/RUM/WP calls with `function_exists()` /
		 * `class_exists()` / `has_filter()` / `version_compare()` where
		 * applicable. Multisite-safe: per-site metrics only (current-URL
		 * context, `Util::transient_key()` blog-aware keys downstream).
		 *
		 * Standalone single-emission entry point for direct buffer/`wp_head`
		 * callers needing a self-contained responsive preload (public for
		 * testability, not part of the external plugin API): the existing
		 * `wp_head` (`get_auto_lcp_preload_data()`) and buffer companions
		 * (`maybe_preload_hero_image()`, `maybe_inject_css_hero_preload()`)
		 * share the same resolver via `get_breakpoint_srcset_for_url()` so
		 * their toggles/gates stay unchanged, while direct callers should
		 * prefer this emitter instead of reimplementing the OD → RUM →
		 * single-high flow.
		 *
		 * @since 2.3.0
		 * @param string|null $buffer Optional HTML buffer for responsive fallback scans.
		 * @return string The preload `<link>` tag, or empty string when skipped.
		 */
		public function emit_responsive_lcp_preload( ?string $buffer = null ): string {
			try {
				if ( self::$responsive_lcp_preload_emitted ) {
					return '';
				}
				try {
					if ( $this->is_auto_lcp_disabled_for_post() ) {
						return '';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( $this->response_already_has_high_preload( $buffer ) ) {
					return '';
				}
				$candidate = $this->get_responsive_lcp_candidate( $buffer );
				if ( array() === $candidate || '' === trim( (string) ( $candidate['url'] ?? '' ) ) ) {
					return '';
				}
				$url = trim( (string) $candidate['url'] );
				if ( ! $this->is_image_lcp_url( $url ) || ! $this->is_allowed_hero_preload_url( $url ) ) {
					return '';
				}
				if ( ! $this->claim_hero_preload_slot( $url, '', is_string( $buffer ) ? $buffer : null ) ) {
					return '';
				}
				$srcset = is_string( $candidate['srcset'] ?? '' ) ? trim( (string) $candidate['srcset'] ) : '';
				$sizes  = is_string( $candidate['sizes'] ?? '' ) ? trim( (string) $candidate['sizes'] ) : '';
				if ( '' === $srcset || '' === $sizes ) {
					$srcset = '';
					$sizes  = '';
				}
				$link_tag = '';
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_preload_link' ) ) {
						$link_tag = Util::get_preload_link(
							$url,
							'preload',
							'image',
							false,
							Util::get_image_mime_type( $url ),
							'',
							'high',
							$srcset,
							$sizes
						);
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$link_tag = '';
				}
				if ( ! is_string( $link_tag ) || '' === trim( $link_tag ) ) {
					self::release_hero_preload_slot( $url, '' );
					return '';
				}
				if ( false === strpos( $link_tag, 'fetchpriority="high"' ) && false === strpos( $link_tag, "fetchpriority='high'" ) ) {
					self::release_hero_preload_slot( $url, '' );
					return '';
				}
				self::$responsive_lcp_preload_emitted = true;
				return $link_tag;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Resolve the responsive LCP candidate (OD breakpoints → RUM field).
		 *
		 * Shared resolver for `emit_responsive_lcp_preload()` and the
		 * `wp_head`/buffer wiring: OD breakpoint winner first (with
		 * attachment + buffer gap-fill when the element carries no srcset),
		 * RUM field-LCP second. Returns `array()` when nothing resolves or
		 * when the art-directed case must be skipped. Fail-open: any failure
		 * returns `array()`.
		 *
		 * @since 2.3.0
		 * @param string|null $buffer Optional HTML buffer for fallback scans.
		 * @return array{url: string, srcset: string, sizes: string, type: string, media: string}|array Empty when unresolved.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_responsive_lcp_candidate( ?string $buffer = null ): array {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\OD_Bridge' ) && method_exists( 'PerformanceOptimise\Inc\OD_Bridge', 'get_breakpoint_lcp_elements' ) ) {
					try {
						$entries = \PerformanceOptimise\Inc\OD_Bridge::get_breakpoint_lcp_elements();
						if ( is_array( $entries ) && array() !== $entries ) {
							$winner = $this->pick_breakpoint_winner( $entries, $buffer );
							if ( array() !== $winner && '' !== trim( (string) ( $winner['url'] ?? '' ) ) ) {
								return $winner;
							}
							// OD measured but unusable (e.g. art-directed):
							// fall through to the RUM tier rather than the
							// legacy single URL so a breakpoint-correct hint
							// still wins when field data agrees.
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$rum = $this->resolve_rum_fallback_candidate( $buffer );
				if ( array() !== $rum ) {
					return $rum;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return array();
		}

		/**
		 * Pick the breakpoint winner from OD per-viewport entries.
		 *
		 * Majority-votes the normalized URL (mobile-first tie-break, mirroring
		 * `OD_Bridge::get_lcp_url()`); the winner's `srcset`/`sizes` pair is
		 * kept only when both are non-empty, otherwise gap-filled via the
		 * attachment lookup then the buffer scan. Picture, background, and
		 * video-poster `type` values all qualify (background/poster winners
		 * legitimately carry no srcset and emit a plain href preload).
		 * Art-directed output — distinct viewport URLs where any entry
		 * carries a non-empty `media`, or a picture winner with `media` —
		 * returns `array()` (fail-open skip). Winners failing
		 * `is_image_lcp_url()` / `is_allowed_hero_preload_url()` are
		 * skipped entry by entry (next-most-common) so one poisoned entry
		 * cannot suppress a valid runner-up.
		 *
		 * @since 2.3.0
		 * @param array       $entries OD breakpoint entries.
		 * @param string|null $buffer  Optional HTML buffer for gap-fill.
		 * @return array{url: string, srcset: string, sizes: string, type: string, media: string}|array Winner or empty.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function pick_breakpoint_winner( array $entries, ?string $buffer = null ): array {
			try {
				$usable = array();
				foreach ( $entries as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$url = isset( $entry['url'] ) && is_string( $entry['url'] ) ? trim( $entry['url'] ) : '';
					if ( '' === $url ) {
						continue;
					}
					$usable[] = array(
						'url'    => substr( $url, 0, 2048 ),
						'srcset' => isset( $entry['srcset'] ) && is_string( $entry['srcset'] ) ? trim( substr( $entry['srcset'], 0, 4096 ) ) : '',
						'sizes'  => isset( $entry['sizes'] ) && is_string( $entry['sizes'] ) ? trim( substr( $entry['sizes'], 0, 1024 ) ) : '',
						'media'  => isset( $entry['media'] ) && is_string( $entry['media'] ) ? trim( substr( $entry['media'], 0, 1024 ) ) : '',
						'type'   => isset( $entry['type'] ) && is_string( $entry['type'] ) && '' !== trim( $entry['type'] ) ? strtolower( trim( $entry['type'] ) ) : 'img',
					);
				}
				if ( array() === $usable ) {
					return array();
				}
				// Art-direction skip: distinct viewport URLs with media-gated
				// sources cannot be represented by one imagesrcset preload.
				$norms = array();
				foreach ( $usable as $item ) {
					try {
						$norm = $this->normalize_image_url( $item['url'] );
					} catch ( \Throwable $e ) {
						unset( $e );
						$norm = '';
					}
					$norms[] = '' !== $norm ? $norm : $item['url'];
				}
				$distinct  = array_values( array_unique( $norms ) );
				$has_media = false;
				foreach ( $usable as $item ) {
					if ( '' !== $item['media'] ) {
						$has_media = true;
						break;
					}
				}
				if ( $has_media && count( $distinct ) > 1 ) {
					return array();
				}
				$counts = array_count_values( $norms );
				if ( empty( $counts ) ) {
					return array();
				}
				arsort( $counts );
				$ordered_norms = array_keys( $counts );
				foreach ( $ordered_norms as $norm ) {
					$winner = null;
					foreach ( $usable as $idx => $item ) {
						if ( $norms[ $idx ] === $norm ) {
							$winner = $item;
							break;
						}
					}
					if ( null === $winner ) {
						continue;
					}
					// A lone art-directed picture source is still a
					// mispredict risk: skip instead of emitting.
					if ( 'picture' === $winner['type'] && '' !== $winner['media'] ) {
						continue;
					}
					if ( ! $this->is_image_lcp_url( $winner['url'] ) || ! $this->is_allowed_hero_preload_url( $winner['url'] ) ) {
						continue;
					}
					$srcset = $winner['srcset'];
					$sizes  = $winner['sizes'];
					if ( '' === $srcset || '' === $sizes ) {
						$srcset = '';
						$sizes  = '';
						try {
							$responsive = self::get_lcp_responsive_data_for_url( $winner['url'] );
							if ( '' !== trim( (string) ( $responsive['srcset'] ?? '' ) ) && '' !== trim( (string) ( $responsive['sizes'] ?? '' ) ) ) {
								$srcset = trim( (string) $responsive['srcset'] );
								$sizes  = trim( (string) $responsive['sizes'] );
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
						if ( ( '' === $srcset || '' === $sizes ) && is_string( $buffer ) && '' !== $buffer ) {
							try {
								$buf_srcset = $this->get_lcp_srcset_for_url( $winner['url'], $buffer );
								$buf_sizes  = '' !== $buf_srcset ? $this->get_lcp_sizes_for_url( $winner['url'], $buffer ) : '';
								if ( '' !== $buf_srcset && '' !== $buf_sizes ) {
									$srcset = $buf_srcset;
									$sizes  = $buf_sizes;
								}
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
						if ( '' === $srcset || '' === $sizes ) {
							$srcset = '';
							$sizes  = '';
						}
					}
					return array(
						'url'    => $winner['url'],
						'srcset' => $srcset,
						'sizes'  => $sizes,
						'type'   => $winner['type'],
						'media'  => $winner['media'],
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return array();
		}

		/**
		 * Resolve the RUM field-LCP fallback candidate.
		 *
		 * Second tier behind OD breakpoints (issue #1429): reads
		 * `RUM::get_field_lcp_url()` (guarded) for the current path and
		 * gap-fills srcset/sizes via the attachment lookup then the buffer
		 * scan. Returns `array()` when RUM is unavailable, has no data, or
		 * the candidate fails validation. Fail-open: any failure returns
		 * `array()`.
		 *
		 * @since 2.3.0
		 * @param string|null $buffer Optional HTML buffer for gap-fill.
		 * @return array{url: string, srcset: string, sizes: string, type: string, media: string}|array Candidate or empty.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function resolve_rum_fallback_candidate( ?string $buffer = null ): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_url' ) ) {
					return array();
				}
				$path = '/';
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_current_url' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'normalize_rum_path' ) ) {
						$current     = Util::get_current_url();
						$parsed_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( $current, PHP_URL_PATH ) : '/';
						$raw_path    = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : '/';
						$path        = Util::normalize_rum_path( $raw_path );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				try {
					$field = \PerformanceOptimise\Inc\RUM::get_field_lcp_url( $path );
				} catch ( \Throwable $e ) {
					unset( $e );
					return array();
				}
				if ( ! is_array( $field ) || empty( $field['url'] ) || ! is_string( $field['url'] ) ) {
					return array();
				}
				$url = trim( $field['url'] );
				if ( '' === $url || ! $this->is_image_lcp_url( $url ) || ! $this->is_allowed_hero_preload_url( $url ) ) {
					return array();
				}
				$srcset = '';
				$sizes  = '';
				try {
					$responsive = self::get_lcp_responsive_data_for_url( $url );
					if ( '' !== trim( (string) ( $responsive['srcset'] ?? '' ) ) && '' !== trim( (string) ( $responsive['sizes'] ?? '' ) ) ) {
						$srcset = trim( (string) $responsive['srcset'] );
						$sizes  = trim( (string) $responsive['sizes'] );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( ( '' === $srcset || '' === $sizes ) && is_string( $buffer ) && '' !== $buffer ) {
					try {
						$buf_srcset = $this->get_lcp_srcset_for_url( $url, $buffer );
						$buf_sizes  = '' !== $buf_srcset ? $this->get_lcp_sizes_for_url( $url, $buffer ) : '';
						if ( '' !== $buf_srcset && '' !== $buf_sizes ) {
							$srcset = $buf_srcset;
							$sizes  = $buf_sizes;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( '' === $srcset || '' === $sizes ) {
					$srcset = '';
					$sizes  = '';
				}
				return array(
					'url'    => $url,
					'srcset' => $srcset,
					'sizes'  => $sizes,
					'type'   => 'img',
					'media'  => '',
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether the response already carries a fetchpriority-high hint.
		 *
		 * Single-high guard (issue #1429): true when the responsive
		 * per-response flag is set or when the buffer already contains an
		 * exact `fetchpriority="high"` hint. Slot-claim enforcement
		 * (exact + any-media `has_emitted_preload()` /
		 * `is_hero_preload_claimed()` checks plus buffer URL matching)
		 * lives in `claim_hero_preload_slot()`, not here. Fail-open to
		 * false.
		 *
		 * @since 2.3.0
		 * @param string|null $buffer Optional HTML buffer to inspect.
		 * @return bool True when a high hint already exists.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function response_already_has_high_preload( ?string $buffer = null ): bool {
			try {
				if ( self::$responsive_lcp_preload_emitted ) {
					return true;
				}
				if ( is_string( $buffer ) && '' !== $buffer && false !== stripos( $buffer, 'fetchpriority' ) ) {
					if ( 1 === preg_match( '/fetchpriority\s*=\s*["\']?high(?=["\'\s>\/]|$)/i', $buffer ) ) {
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
		 * @since 2.2.0
		 * @return array List of preload items (zero or one item).
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_manual_lcp_preload_data(): array {
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
		 * @since 2.2.0 Resolves via the unified `resolve_auto_lcp_url()` chain
		 * (OD → stored PageSpeed → heuristic) with a text-LCP guard; emits at
		 * most one item. Adds the RUM-gated `preload_settings.autoLcpPreload`
		 * path (off by default, manual lists win, never lazy+high).
		 * @since 2.2.0 RUM gates only the RUM-dependent tiers: with RUM
		 * unsatisfied the OD-only subset still resolves.
		 * @return array List of preload items (zero or one item).
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_auto_lcp_preload_data(): array {
		$lcpown_options = $this->owner->lcp_get_options();
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					return array();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$image_optimisation = $lcpown_options['image_optimisation'] ?? array();
			$preload_settings   = $lcpown_options['preload_settings'] ?? array();
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
				$responsive = $this->get_breakpoint_srcset_for_url( $lcp_url, null );
				if ( '' === $responsive['srcset'] || '' === $responsive['sizes'] ) {
					$responsive = self::get_lcp_responsive_data_for_url( $lcp_url );
				}
				return array( $this->prepare_preload_item( $lcp_url, $responsive['srcset'], $responsive['sizes'] ) );
			}

			$lcp_url = $this->resolve_auto_lcp_url();
			if ( '' === $lcp_url ) {
				return array();
			}

			$responsive = $this->get_breakpoint_srcset_for_url( $lcp_url, null );
			if ( '' === $responsive['srcset'] || '' === $responsive['sizes'] ) {
				$responsive = self::get_lcp_responsive_data_for_url( $lcp_url );
			}
			return array( $this->prepare_preload_item( $lcp_url, $responsive['srcset'], $responsive['sizes'] ) );
		}

		/**
		 * Look up breakpoint srcset/sizes for a resolved LCP URL.
		 *
		 * Thin wrapper over `get_responsive_lcp_candidate()` (issue #1429)
		 * for the `wp_head` emission path: when OD breakpoints (or RUM
		 * field data) resolve the same normalized URL, the breakpoint pair
		 * wins over the attachment lookup; otherwise returns an empty pair
		 * so callers fall back to the legacy single-href data. Fail-open to
		 * an empty pair on any failure.
		 *
		 * @since 2.3.0
		 * @param string      $lcp_url The resolved LCP image URL.
		 * @param string|null $buffer  Optional HTML buffer for gap-fill.
		 * @return array{srcset: string, sizes: string} Responsive pair.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_breakpoint_srcset_for_url( string $lcp_url, ?string $buffer = null ): array {
			$empty = array(
				'srcset' => '',
				'sizes'  => '',
			);
			try {
				if ( '' === trim( $lcp_url ) ) {
					return $empty;
				}
				$candidate = $this->get_responsive_lcp_candidate( $buffer );
				if ( array() === $candidate || '' === trim( (string) ( $candidate['url'] ?? '' ) ) ) {
					return $empty;
				}
				try {
					$needle = $this->normalize_image_url( $lcp_url );
					$got    = $this->normalize_image_url( (string) $candidate['url'] );
				} catch ( \Throwable $e ) {
					unset( $e );
					return $empty;
				}
				if ( '' === $needle || $needle !== $got ) {
					return $empty;
				}
				$srcset = is_string( $candidate['srcset'] ?? '' ) ? trim( (string) $candidate['srcset'] ) : '';
				$sizes  = is_string( $candidate['sizes'] ?? '' ) ? trim( (string) $candidate['sizes'] ) : '';
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
		 * Whether the RUM gate for the additive auto-LCP toggle is satisfied.
		 *
		 * The `preload_settings.autoLcpPreload` path (issue #1216) stays off
		 * until real-user measurement is enabled (`RUM::is_enabled()`,
		 * guarded with class_exists/method_exists). Fail-closed when RUM is
		 * unavailable or disabled so detection failure degrades to the
		 * current manual behavior; fail-open only via the legacy
		 * `autoPreloadLCP` path handled by the caller. Never fatal.
		 *
		 * @since 2.2.0
		 * @return bool True when RUM gating passes.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_auto_lcp_rum_satisfied(): bool {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_current_lcp_url(): string {
		$lcpown_current_lcp_url =& $this->owner->lcp_state_current_lcp_url();
		$lcpown_current_lcp_url_key =& $this->owner->lcp_state_current_lcp_url_key();
		$lcpown_options = $this->owner->lcp_get_options();
			$memo_key = $this->get_lcp_memo_key();
			if ( null !== $lcpown_current_lcp_url && $lcpown_current_lcp_url_key === $memo_key ) {
				return $lcpown_current_lcp_url;
			}
			$lcpown_current_lcp_url     = '';
			$lcpown_current_lcp_url_key = $memo_key;
			// Per-post kill switch (issue #1273): the stored chain is
			// automatic detection, so it stays suppressed on disabled
			// posts (the manual picker lives outside this chain).
			try {
				if ( $this->is_auto_lcp_disabled_for_post() ) {
					return $lcpown_current_lcp_url;
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
						$lcpown_current_lcp_url = $od_url;
						return $lcpown_current_lcp_url;
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
			if ( ! empty( ( $lcpown_options['image_optimisation'] ?? array() )['fieldLcpOverride'] ) ) {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_field_lcp_url' ) ) {
					try {
						$parsed_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( Util::get_current_url(), PHP_URL_PATH ) : '/';
						$raw_path    = is_string( $parsed_path ) && '' !== $parsed_path ? $parsed_path : '/';
						// Normalized identically to the RUM store side so
						// '/hero-page' matches a stored '/hero-page/' bucket.
						$field_path = Util::normalize_rum_path( $raw_path );
						$field      = \PerformanceOptimise\Inc\RUM::get_field_lcp_url( $field_path );
						if ( is_array( $field ) && ! empty( $field['url'] ) && is_string( $field['url'] ) ) {
							$lcpown_current_lcp_url = $field['url'];
							return $lcpown_current_lcp_url;
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
					$lcpown_current_lcp_url = \PerformanceOptimise\Inc\RUM::get_stored_pagespeed_lcp_url();
					return $lcpown_current_lcp_url;
				} catch ( \Throwable $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						error_log( 'WPPO Image optimisation PageSpeed LCP error: ' . str_replace( ABSPATH, '', $e->getMessage() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					}
				}
			}

			return $lcpown_current_lcp_url;
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
		 * @since 2.2.0
		 * @return string Memo key (possibly empty).
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_lcp_memo_key(): string {
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
		 * @since 2.2.0
		 * @param string $buffer HTML buffer to scan.
		 * @return string Heuristic LCP URL, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_heuristic_lcp_url( string $buffer ): string {
			try {
				if ( '' === $buffer ) {
					return '';
				}
				$hash = self::heuristic_memo_key( $buffer );
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
		 * @since 2.2.0 Resolves via the unified `resolve_auto_lcp_url()` chain
		 * so the never-lazy URL is always the same URL that gets preloaded.
		 * The optional `$buffer` enables the P2 DOM-first heuristic tier so
		 * `add_delay_load_img()` stays in parity with
		 * `maybe_preload_hero_image()` (which resolves with the buffer);
		 * without a buffer only the manual + OD + stored tiers apply.
		 * @param array       $image_optimisation Image optimisation settings.
		 * @param string|null $buffer Optional HTML buffer for the heuristic tier.
		 * @return string The candidate URL, or empty string when none applies.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_lazy_lcp_exclusion_url( array $image_optimisation, ?string $buffer = null ): string {
		$lcpown_lazy_lcp_exclusion_url =& $this->owner->lcp_state_lazy_lcp_exclusion_url();
		$lcpown_lazy_lcp_exclusion_url_key =& $this->owner->lcp_state_lazy_lcp_exclusion_url_key();
		$lcpown_options = $this->owner->lcp_get_options();
			$memo_key = null === $buffer ? $this->get_lcp_memo_key() : null;
			if ( null === $buffer && null !== $lcpown_lazy_lcp_exclusion_url && $lcpown_lazy_lcp_exclusion_url_key === $memo_key ) {
				return $lcpown_lazy_lcp_exclusion_url;
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
						$lcpown_lazy_lcp_exclusion_url     = $manual;
						$lcpown_lazy_lcp_exclusion_url_key = $memo_key;
					}
					return $manual;
				}
				try {
					if ( $this->is_auto_lcp_disabled_for_post() ) {
						if ( null === $buffer ) {
							$lcpown_lazy_lcp_exclusion_url     = '';
							$lcpown_lazy_lcp_exclusion_url_key = $memo_key;
						}
						return '';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$auto_lcp_on = ! empty( ( $lcpown_options['preload_settings'] ?? array() )['autoLcpPreload'] ) && $this->is_auto_lcp_rum_satisfied();
				$gated       = ! empty( $image_optimisation['fieldLcpOverride'] ) || ! empty( $image_optimisation['autoPreloadLCP'] ) || ! empty( $image_optimisation['prioritizeLCPImages'] ) || $auto_lcp_on;
				if ( ! $gated ) {
					if ( null === $buffer ) {
						$lcpown_lazy_lcp_exclusion_url     = '';
						$lcpown_lazy_lcp_exclusion_url_key = $memo_key;
					}
					return '';
				}
				$resolved = $this->resolve_auto_lcp_url( $buffer );
				if ( '' !== $resolved ) {
					$resolved_url = $resolved;
					if ( null === $buffer ) {
						$lcpown_lazy_lcp_exclusion_url     = $resolved;
						$lcpown_lazy_lcp_exclusion_url_key = $memo_key;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			if ( null === $buffer && '' === $resolved_url ) {
				$lcpown_lazy_lcp_exclusion_url     = '';
				$lcpown_lazy_lcp_exclusion_url_key = $memo_key;
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_effective_exclude_first_images_count( array $image_optimisation ): int {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_front_page_preload_data( array $image_optimisation ): array {
		$lcpown_preload_front_page_urls = $this->owner->lcp_get_preload_front_page_urls();
			if ( empty( $image_optimisation['preloadFrontPageImages'] ) || ! is_front_page() ) {
				return array();
			}

			$urls = $lcpown_preload_front_page_urls;
			return array_map( array( $this, 'prepare_preload_item' ), $urls );
		}

		/**
		 * Retrieves preload data from post meta.
		 *
		 * @since 1.5.1
		 * @return array List of preload items from meta.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_meta_preload_data(): array {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_post_type_preload_data( array $image_optimisation ): array {
		$lcpown_exclude_post_type_imgs = $this->owner->lcp_get_exclude_post_type_imgs();
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

			$exclude_img_urls = $lcpown_exclude_post_type_imgs;
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_image_url_by_post_type( int $thumbnail_id ): string {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function should_exclude_image( string $image_url, array $exclude_img_urls ): bool {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function parse_srcset_data( $srcset, $image_optimisation ): array {
		$lcpown_exclude_sizes = $this->owner->lcp_get_exclude_sizes();
			if ( ! $srcset ) {
				return array();
			}

			$sources       = array_map( 'trim', explode( ',', $srcset ) );
			$max_width     = (int) ( $image_optimisation['maxWidthImgSize'] ?? self::MAX_PRELOAD_WIDTH );
			$exclude_sizes = $lcpown_exclude_sizes;

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
		 * @since 2.2.0 Keeps the largest MAX_LCP_PRELOADS widths (the likely
		 * hero variants) instead of the smallest; media ranges are generated
		 * after the slice so coverage stays gapless.
		 * @param string $srcset             The srcset string from the image tag.
		 * @param string $default_image      The fallback image URL.
		 * @param array  $image_optimisation Image optimization configuration array.
		 * @return array List of preload items.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_srcset_preload_items( $srcset, $default_image, $image_optimisation ): array {
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
		 * @since 2.2.0 Adds optional $imagesrcset/$imagesizes for responsive LCP heroes.
		 * @param string $img_url The original URL to prepare.
		 * @param string $imagesrcset Optional responsive srcset for the preload link.
		 * @param string $imagesizes Optional sizes for the preload link.
		 * @return array Structured preload item.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function prepare_preload_item( string $img_url, string $imagesrcset = '', string $imagesizes = '' ): array {
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
					return $this->owner->lcp_normalize_url( $url );
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
		 * @since 2.2.0 Resolves the stable signal candidate when empty,
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
		 * 4. When the `occlusionFetchpriorityLow` toggle is enabled (issue
		 *    #1426), OD-measured occluded in-viewport nodes get
		 *    `fetchpriority="low"` with no `loading` change, skipping the
		 *    true-LCP node so the single-high invariant holds.
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
		$lcpown_options = $this->owner->lcp_get_options();
			// Mid-template cancel safety (issue #1386): a cancelled core
			// buffer can deliver a non-string (false/null) into the filter.
			// An output-buffer callback must always return a string — fail
			// open to the raw $output when it carries page HTML (priority 30
			// runs after cache/used-CSS, so collapsing to '' would wipe
			// output earlier filters already processed), else to '' without
			// consuming the one-shot so a later real pass can still run.
			if ( ! is_string( $filtered_output ) ) {
				if ( is_string( $output ) && '' !== $output ) {
					return $output;
				}
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

			$image_optimisation  = $lcpown_options['image_optimisation'] ?? array();
			$prioritize_enabled  = ! empty( $image_optimisation['prioritizeLCPImages'] );
			$css_preload_enabled = ! empty( $image_optimisation['cssHeroPreload'] );
			$occlusion_enabled   = $this->is_occlusion_fetchpriority_low_enabled();
			if ( ! $prioritize_enabled && ! $css_preload_enabled && ! $occlusion_enabled ) {
				return $filtered_output;
			}
			if ( is_admin() || empty( $filtered_output ) || ! is_string( $filtered_output ) ) {
				return $filtered_output;
			}
			// Same logged-in eligibility guard as the legacy buffer path so both
			// pipelines behave identically for the current user.
			if ( ! Util::is_cache_eligible_for_current_user( $lcpown_options['cache_settings'] ?? array() ) ) {
				return $filtered_output;
			}
			// CSS-hero pages may contain no <img> tags at all; let those through
			// to Pass C when the CSS hero preload is enabled.
			$css_hero_eligible    = $css_preload_enabled && false !== stripos( $filtered_output, 'background' );
			$has_img              = false !== stripos( $filtered_output, '<img' );
			$tag_processor_exists = class_exists( 'WP_HTML_Tag_Processor' );
			if ( ! $tag_processor_exists ) {
				// Occlusion demotion (Pass B2) and the final lazy/high sweep
				// both ship regex fallbacks, so occlusion-only mode can
				// still run on pre-6.2 cores without the HTML API.
				if ( ! $occlusion_enabled || ! $has_img ) {
					return $filtered_output;
				}
			} elseif ( ! $has_img && ! $css_hero_eligible ) {
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
				// markup unmodified, never fatal. When only the occlusion
				// flag is on, the occluded list is fetched first and LCP
				// resolution is skipped when it is empty (no markup change
				// possible, so the OD/RUM/DOM scan cost is avoided).
				//
				// @since 2.2.0 Unified resolution via resolve_auto_lcp_url().
				$lcp_url             = '';
				$occluded_urls_early = null;
				if ( $occlusion_enabled && ! $prioritize_enabled ) {
					try {
						$occluded_urls_early = $this->get_occluded_image_urls_for_request();
					} catch ( \Throwable $e ) {
						unset( $e );
						$occluded_urls_early = array();
					}
					if ( ! is_array( $occluded_urls_early ) ) {
						$occluded_urls_early = array();
					}
				}
				if ( $prioritize_enabled || ( is_array( $occluded_urls_early ) && ! empty( $occluded_urls_early ) ) ) {
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
					$buffer = $this->owner->lcp_unlazyload_first_images( $buffer, $image_optimisation );

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

				// Pass B2: occlusion-aware demotion (issue #1426) — OD-measured
				// occluded (CSS-hidden but in-viewport) nodes get
				// fetchpriority="low" without any loading change, skipping
				// the true-LCP node so the single-high invariant holds.
				if ( $occlusion_enabled ) {
					try {
						$occluded_urls = is_array( $occluded_urls_early ) ? $occluded_urls_early : $this->get_occluded_image_urls_for_request();
						if ( ! empty( $occluded_urls ) ) {
							$buffer = $this->apply_occlusion_fetchpriority_low( $buffer, $occluded_urls, $lcp_url );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				// Pass D: final lazy/high invariant sweep (issue #1312) — any
				// element marked fetchpriority high is forced eager so lazy
				// plus high pairs are never emitted together. Attribute-only
				// rewrite, no layout change (no CLS).
				$buffer = $this->sweep_lazy_high_conflicts( $buffer );

				return $buffer;
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO LCP prioritization failed.', array( 'exception' => $e ) );
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
		 * @since 2.2.0
		 *
		 * @param mixed $attr       Image attributes (expected array).
		 * @param mixed $attachment Attachment post object, ID, or array with ID.
		 * @param mixed $size       Requested image size.
		 * @return mixed The (possibly stamped) attributes, unchanged on miss.
		 */
		public function wppo_add_fetchpriority( $attr, $attachment = null, $size = null ) {
		$lcpown_options = $this->owner->lcp_get_options();
			try {
				if ( ! is_array( $attr ) ) {
					return $attr;
				}
				$image_optimisation = $lcpown_options['image_optimisation'] ?? array();
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
				return $this->owner->lcp_sanitize_loading_triple( $attr );
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
		 * @since 2.2.0
		 * @return string The validated LCP image URL, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function resolve_fetchpriority_lcp_url(): string {
		$lcpown_fetchpriority_lcp_url =& $this->owner->lcp_state_fetchpriority_lcp_url();
		$lcpown_fetchpriority_lcp_key =& $this->owner->lcp_state_fetchpriority_lcp_key();
			$memo_key = $this->get_lcp_memo_key();
			if ( null !== $lcpown_fetchpriority_lcp_url && $lcpown_fetchpriority_lcp_key === $memo_key ) {
				return $lcpown_fetchpriority_lcp_url;
			}
			$lcpown_fetchpriority_lcp_url = '';
			$lcpown_fetchpriority_lcp_key = $memo_key;
			try {
				$lcp_url = $this->resolve_od_only_lcp_url();
				if ( ! is_string( $lcp_url ) || '' === $lcp_url ) {
					$lcp_url = $this->get_current_lcp_url();
				}
				if ( ! is_string( $lcp_url ) || '' === $lcp_url ) {
					return $lcpown_fetchpriority_lcp_url;
				}
				if ( ! $this->is_image_lcp_url( $lcp_url ) || ! $this->is_allowed_hero_preload_url( $lcp_url ) ) {
					return $lcpown_fetchpriority_lcp_url;
				}
				$lcpown_fetchpriority_lcp_url = $lcp_url;
			} catch ( \Throwable $e ) {
				unset( $e );
				$lcpown_fetchpriority_lcp_url = '';
			}
			return $lcpown_fetchpriority_lcp_url;
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
		 * @since 2.2.0
		 * @param string $candidate      The rendered file URL to test.
		 * @param string $normalized_lcp Normalized LCP URL (size suffix stripped).
		 * @param string $exact_lcp      Normalized LCP URL (size suffix preserved).
		 * @param bool   $size_is_full   Whether the requested image size is 'full'.
		 * @return bool True when the candidate corresponds to the LCP image.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function fetchpriority_candidate_matches( string $candidate, string $normalized_lcp, string $exact_lcp, bool $size_is_full ): bool {
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
		 * @since 2.2.0 Callers pass the unified `resolve_auto_lcp_url()` target so
		 * the never-lazy/fetchpriority stamp always matches the preloaded URL.
		 *
		 * @param string      $buffer  The HTML buffer.
		 * @param string|null $lcp_url Optional pre-resolved LCP URL. When null the
		 *                             URL is resolved via resolve_auto_lcp_url()
		 *                             (same-origin guarded OD/stored/heuristic
		 *                             chain; the stored tier internally reads
		 *                             get_current_lcp_url()).
		 * @return string The buffer with fetchpriority="high" on the LCP image.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function prioritize_lcp_image( string $buffer, ?string $lcp_url = null ): string {
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
			if ( $this->owner->lcp_should_use_html_processor() ) {
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
								$this->owner->lcp_restore_js_lazy_placeholders( $processor );
								$this->owner->lcp_remove_lazy_classes( $processor );
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
							return $stamped ? $this->owner->lcp_promote_eager_picture_sources( $new_html ) : $buffer;
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
						$this->owner->lcp_restore_js_lazy_placeholders( $tags );
						$this->owner->lcp_remove_lazy_classes( $tags );
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
				return $stamped ? $this->owner->lcp_promote_eager_picture_sources( $updated ) : $buffer;
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
		 * @since 2.2.0 Resolves via the unified `resolve_auto_lcp_url()` chain
		 * (OD → stored PageSpeed → in-viewport heuristic) and emits at most
		 * one preload link with `imagesrcset` when the hero carries a srcset.
		 * Accepts a pre-resolved LCP URL so all buffer passes share one target.
		 *
		 * @param string      $buffer             The HTML buffer.
		 * @param array       $image_optimisation Image optimisation settings.
		 * @param string|null $lcp_url            Optional pre-resolved LCP URL. When null
		 *                                        the URL is resolved via resolve_auto_lcp_url().
		 * @return string The buffer with hero preload link injected.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function maybe_preload_hero_image( string $buffer, array $image_optimisation, ?string $lcp_url = null ): string {
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
						if ( $this->owner->lcp_restore_js_lazy_placeholders( $tags ) ) {
							$changed = true;
						}
						if ( $this->owner->lcp_remove_lazy_classes( $tags ) ) {
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
						// Stable dimensions for the eager hero (issue #1467):
						// a dimension-less hero causes CLS, so backfill any
						// missing width/height from the local file when the
						// tag carries no numeric value. Resolve from the
						// matched tag's own src (the file actually rendered)
						// first — the LCP URL may be a size-variant alias
						// (size suffix stripped by tag_matches_lcp_url()), so
						// stamping alias dimensions onto a crop distorts it.
						// Fail-open: unknown paths leave the tag untouched.
						try {
							$has_w = is_numeric( $tags->get_attribute( 'width' ) );
							$has_h = is_numeric( $tags->get_attribute( 'height' ) );
							if ( ! $has_w || ! $has_h ) {
								$tag_src = $tags->get_attribute( 'src' );
								if ( ! is_string( $tag_src ) || '' === trim( $tag_src ) || ! $this->owner->lcp_is_dimension_lookup_allowed( (string) $tag_src ) ) {
									$tag_src = $tags->get_attribute( 'data-src' );
								}
								if ( ! is_string( $tag_src ) || '' === trim( $tag_src ) || ! $this->owner->lcp_is_dimension_lookup_allowed( (string) $tag_src ) ) {
									$tag_src = $lcp_url;
								}
								$hero_src = (string) $tag_src;
								if ( '' !== trim( $hero_src ) && $this->owner->lcp_is_dimension_lookup_allowed( $hero_src ) ) {
									$hero_path = Util::get_local_path( $hero_src );
									if ( '' !== $hero_path && $this->owner->lcp_cached_file_exists( $hero_path ) && is_readable( $hero_path ) && is_file( $hero_path ) ) {
										$hero_size = $this->owner->lcp_get_cached_image_size( $hero_path );
										if ( is_array( $hero_size ) && isset( $hero_size[0], $hero_size[1] ) && (int) $hero_size[0] > 0 && (int) $hero_size[1] > 0 ) {
											if ( ! $has_w ) {
												$tags->set_attribute( 'width', (string) (int) $hero_size[0] );
												$changed = true;
											}
											if ( ! $has_h ) {
												$tags->set_attribute( 'height', (string) (int) $hero_size[1] );
												$changed = true;
											}
										}
									}
								}
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
						break;
					}
				}
				if ( $changed ) {
					$buffer = $this->owner->lcp_promote_eager_picture_sources( $tags->get_updated_html() );
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
				// Breakpoint-first srcset (issue #1429): OD per-viewport
				// elements (or RUM field data) for the same hero win over
				// the buffer scan so mobile/desktop fetch the right
				// candidate; the buffer scan stays as the legacy fallback.
				$breakpoint  = $this->get_breakpoint_srcset_for_url( $lcp_url, $buffer );
				$imagesrcset = '' !== $breakpoint['srcset'] ? $breakpoint['srcset'] : $this->get_lcp_srcset_for_url( $lcp_url, $buffer );
				$imagesizes  = '' !== $breakpoint['sizes'] ? $breakpoint['sizes'] : ( '' !== $imagesrcset ? $this->get_lcp_sizes_for_url( $lcp_url, $buffer ) : '' );
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_first_image_src_in_buffer( string $buffer ): string {
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
		 * @since 2.2.0
		 * @param \WP_HTML_Tag_Processor $tags The tag processor on the candidate `<img>`.
		 * @param string                 $src  The candidate src URL.
		 * @return bool True when the candidate should be skipped.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function is_trivial_heuristic_image( $tags, string $src ): bool {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function buffer_has_image_preload( string $buffer, string $url ): bool {
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
		 * @since 2.2.0
		 * @param string $buffer           The HTML buffer.
		 * @param string $needle           Normalized target URL.
		 * @param string $needle_exact     Normalized target URL without size-suffix collapsing.
		 * @param bool   $needle_has_sizes Whether the target itself carries a size suffix.
		 * @param string $needle_query     Raw query string of the target URL.
		 * @return bool|null True/false on success, null on failure (fallback).
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function buffer_has_image_preload_with_tag_processor( string $buffer, string $needle, string $needle_exact, bool $needle_has_sizes, string $needle_query ): ?bool {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_url_query( string $url ): string {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function tag_matches_lcp_url( $tags, string $lcp_url ): bool {
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function normalize_image_url( string $url, bool $strip_size_suffix = true ): string {
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
		 * @since 2.2.0
		 *
		 * @param string $url The image URL to normalize.
		 * @param bool   $strip_size_suffix Whether to strip WP size suffixes.
		 * @return string Normalized host + path, or empty string.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public static function normalize_image_url_static( string $url, bool $strip_size_suffix = true ): string {
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
		 * @since 2.2.0 Delegates to build_preload_dedup_key() so the shared
		 * cross-emitter helpers use the identical key space.
		 *
		 * @param string $url   The raw preload URL.
		 * @param string $media The preload media attribute.
		 * @return string The dedup key.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function get_preload_dedup_key( string $url, string $media ): string {
			return self::build_preload_dedup_key( $url, $media );
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
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function buffer_has_matching_img( string $buffer, string $lcp_url ): bool {
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
		 * @since 2.2.0 Accepts a pre-resolved LCP URL so buffer passes share one
		 * unified target instead of re-resolving stored data per pass.
		 * @since 2.2.0 Uses the centralised hero slot, the same-origin/CDN
		 * allowlist, stylesheet-block heroes, and the computed-URL filter.
		 *
		 * @param string      $buffer  The HTML buffer.
		 * @param string|null $lcp_url Optional pre-resolved LCP URL. When null the
		 *                             URL is resolved via resolve_auto_lcp_url()
		 *                             (same-origin guarded OD/stored/heuristic
		 *                             chain), matching every other emission path.
		 * @return string The buffer with at most one added preload link.
		 * @internal Formerly private on Image_Optimisation (ARCH-008 extraction bridge). Call via the Image_Optimisation facade, never directly.
	 */
		public function maybe_inject_css_hero_preload( string $buffer, ?string $lcp_url = null ): string {
		$lcpown_options = $this->owner->lcp_get_options();
			$image_optimisation = $lcpown_options['image_optimisation'] ?? array();
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
			$hero_url = $this->owner->lcp_get_css_hero_url_from_buffer( $buffer );
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
			// Breakpoint-first srcset (issue #1429): CSS-background heroes
			// share the OD/RUM responsive candidate so the preload carries
			// matching imagesrcset+imagesizes when the measured element
			// provides them; otherwise a plain href preload emits.
			$breakpoint_css = $this->get_breakpoint_srcset_for_url( $lcp_url, $buffer );
			$link_tag       = Util::get_preload_link( $lcp_url, 'preload', 'image', false, Util::get_image_mime_type( $lcp_url ), '', 'high', $breakpoint_css['srcset'], $breakpoint_css['sizes'] );
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

	}
}
