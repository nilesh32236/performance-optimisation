<?php
/**
 * Cache class for handling caching functionalities in PerformanceOptimise plugin.
 *
 * This class is responsible for caching tasks such as combining CSS files,
 * generating static HTML files, and managing cache files in the WordPress
 * content directory. It also provides mechanisms to clear the cache and
 * retrieve cached files when necessary.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

use PerformanceOptimise\Inc\Minify\CSS;
use PerformanceOptimise\Inc\Google_Fonts;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
	/**
	 * Class Cache
	 *
	 * Handles caching functionalities such as combining CSS and generating static HTML.
	 *
	 * @since 1.0.0
	 */
	class Cache {
		/**
		 * The directory where cache files are stored.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private const CACHE_DIR = '/cache/wppo';

		/**
		 * Invalidate the cached dashboard stats.
		 *
		 * The unified dashboard stats payload (`wppo_cache_stats`, plus the
		 * standalone `wppo_total_js_css` dashboard cache) carries a 15-minute
		 * TTL; per-page mutations (smart purge via
		 * invalidate_dynamic_static_html(), fresh combine_css generation)
		 * previously left the stats frozen until the TTL ran out (audit #874
		 * finding 6). Mutators call this so the next stats read recomputes.
		 * Deliberately NOT called from save_cache_files(): frontend cache
		 * misses are the normal write path and would defeat the TTL entirely.
		 * The retired split mirrors (`wppo_cache_size` / `wppo_cache_count`)
		 * are no longer written anywhere, so there is nothing to invalidate
		 * for them (issue #1464).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function bump_stats_cache(): void {
			delete_transient( Util::transient_key( 'wppo_cache_stats' ) );
			delete_transient( Util::transient_key( 'wppo_total_js_css' ) );
			// Stampede stale copy (issue #1101): the stats walk writes a 24h
			// `<key>_stale` transient that must not survive an explicit purge.
			delete_transient( Util::transient_key( 'wppo_cache_stats_stale' ) );
			delete_transient( Util::stampede_stale_key( Util::transient_key( 'wppo_cache_stats' ) ) );

			// The salted object-cache layer (WP 6.9+ drop-in) derives entry
			// validity from `wppo_cache_last_cleared`; bump it wherever the
			// stats are bumped so the salted `wppo_cache_stats` /
			// `wppo_total_js_css` entries stay consistent outside clear_cache()
			// (smart purge, combine_css) too.
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				$salt = (int) get_option( 'wppo_cache_last_cleared', 0 ) + 1;
				update_option( 'wppo_cache_last_cleared', $salt, false );
			}
		}

		/**
		 * Whether the depth-guard warning has been logged this request.
		 *
		 * Rate-limits WP_DEBUG logging for depth cap hits to once per request
		 * so a deeply nested cache tree does not flood the error log.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $depth_warning_logged = false;

		/**
		 * The domain name of the site.
		 *
		 * Pinned to the canonical home host (see {@see Util::get_canonical_host()})
		 * so a forged Host header can never create a poisoned cache directory
		 * tree. Falls back to the legacy Host-derived value only when the
		 * canonical host cannot be resolved (early boot, CLI).
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $domain;

		/**
		 * Whether the request Host header differs from the canonical home host.
		 *
		 * When true the request is served uncached (fail-open) and no cache
		 * file is written, so forged hosts can neither poison nor read stored
		 * payloads.
		 *
		 * @var bool
		 * @since 2.0.0
		 */
		private bool $host_mismatch = false;

		/**
		 * The root directory for cache files.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $cache_root_dir;

		/**
		 * The URL to access cache files.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $cache_root_url;

		/**
		 * The URL path for the current request.
		 *
		 * @var string
		 * @since 1.0.0
		 */
		private string $url_path;

		/**
		 * Whether the request URL path was rejected as a traversal probe.
		 *
		 * Set when the raw path component sanitizes to '' while non-blank
		 * (dot-dot, encoded sequences, null bytes, drive/UNC prefixes) or
		 * when the request-target is absolute-form. While true the request
		 * is served dynamic/uncached and {@see get_cache_file_path()}
		 * refuses to build a path (fail-open, never mapping the probe to
		 * the homepage `index.html`).
		 *
		 * @var bool
		 * @since 2.0.0
		 */
		private bool $path_rejected = false;

		/**
		 * Whether the inline-CSS budget prediction drifted from core this request.
		 *
		 * Set when {@see core_will_inline()} finds its legacy accounting disagrees
		 * with a core-faithful re-derivation, e.g. when a queued path-data style
		 * lacks a `src` or exceeds the inline budget. Causes the combined file to be
		 * served externally instead of inlined so core cannot double-inline it.
		 *
		 * @var bool
		 * @since 2.0.0
		 */
		private bool $inline_drift_detected = false;

		/**
		 * Whether the buffer was enhanced (image/CDN/minify passes) this request.
		 *
		 * Nesting balance guard (issue #881): the WP 6.9+ enhancement-buffer
		 * filter ({@see process_buffer_for_cache()}) and the legacy fallback
		 * buffer ({@see start_output_buffer()}) share the
		 * {@see process_buffer_only()} pipeline; when both are registered a
		 * mid-request flip of `wp_should_output_buffer_template_for_enhancement`
		 * could otherwise run the pipeline twice. One-shot per request.
		 *
		 * @var bool
		 * @since 2.0.0
		 */
		private static bool $buffer_enhanced = false;

		/**
		 * Whether the budget-drift notice has been logged this PHP process.
		 *
		 * Rate-limits {@see log_inline_budget_drift()} to a single activity-log
		 * entry per request regardless of how many handles drift during one render.
		 * A condition-keyed transient (see {@see log_inline_budget_drift()})
		 * additionally throttles persistent drift across requests.
		 *
		 * @var bool
		 * @since 2.0.0
		 */
		private static bool $inline_drift_logged = false;

		/**
		 * Cached handle => file-size/readability map for the inline-budget simulation.
		 *
		 * Built lazily on the first {@see core_inline_budget_will_inline()} call of
		 * the request so every simulation reuses a single `is_file()`/`filesize()`
		 * pass over the queue instead of repeating it per handle (up to 6*n per
		 * request via the freshness, generation, and combined-handle loops). The
		 * map is reset whenever a style's `path` data changes, i.e. after
		 * {@see register_combine_css_path()} registers the combined file.
		 *
		 * Each entry stores the file size alongside an `is_readable()` flag so the
		 * core-faithful reference can mirror WP 7.0+ core, which skips unreadable
		 * styles in its budget loop without charging their size.
		 *
		 * @var array<string,array{size:int,readable:bool}>|null
		 * @since 2.0.0
		 */
		private ?array $inline_size_map = null;

		/**
		 * Memoized core_will_inline results per handle per request.
		 *
		 * Avoids running the dual budget simulation (prediction + reference)
		 * twice for the same handle across the eligibility, freshness, and
		 * generation loops (2*3*n simulations → 2*n with memo).
		 *
		 * @var array<string,bool>
		 * @since 2.0.0
		 */
		private array $core_will_inline_memo = array();

		/**
		 * Per-request LRU cache for src file stat (is_readable + filesize).
		 *
		 * @since 2.0.0
		 * @var array<string,array{readable:bool,size:int|false}>
		 */
		private array $src_stat_cache = array();

		/**
		 * The filesystem object used for file operations.
		 *
		 * @var object|null
		 * @since 1.0.0
		 */
		private object|false|null $filesystem = null; // Audit #1434: typed (false on init failure).

		/**
		 * Output-buffer level occupied by the legacy cache buffer (WP < 6.9).
		 *
		 * Set in {@see start_output_buffer()} after ob_start(); used by
		 * {@see maybe_end_output_buffer()} to close the buffer exactly once on
		 * shutdown. Null while no cache buffer is open.
		 *
		 * @var int|null
		 * @since 2.0.0
		 */
		private ?int $cache_ob_level = null;

		/**
		 * Whether the filesystem has been initialized.
		 *
		 * @var bool
		 * @since 1.6.0
		 */
		private bool $fs_initialized = false;

		/**
		 * The sanitized request URI from $_SERVER.
		 *
		 * @var string
		 * @since 1.6.0
		 */
		private string $request_uri;

		/**
		 * The options/settings for the cache system.
		 *
		 * @var array
		 * @since 1.0.0
		 */
		private array $options = array(); // Audit #1434: typed per docblock.

		/**
		 * Image_Optimisation instance for buffer processing.
		 *
		 * @var Image_Optimisation|null
		 * @since 2.0.0
		 */
		private ?Image_Optimisation $image_optimisation = null; // Audit #1434: typed per docblock.

		/**
		 * Google_Fonts instance for buffer-level font interception.
		 *
		 * @var Google_Fonts|null
		 * @since 2.0.0
		 */
		private ?Google_Fonts $google_fonts = null; // Audit #1434: typed per docblock.

		/**
		 * Role hash for the current request, set during buffer processing.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private string $current_role_hash = '';

		/**
		 * Whether the DONOTCACHEPAGE marker has been written for this request.
		 *
		 * Ensures the marker write and stale-file purge happen at most once per request.
		 *
		 * @var bool
		 * @since 1.9.0
		 */
		private bool $no_cache_marker_written = false;

		/**
		 * Preload URL of the combined stylesheet, set during {@see combine_css()}
		 * and emitted on `wp_head` by {@see maybe_preload_combine_css()}.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private string $combine_css_preload_url = '';

		/**
		 * Lazily-created CSS-combine runner (ARCH-006).
		 *
		 * Single owner for the fetch/minify/write/journal/preload/budget/
		 * fallback cluster; every combine proxy delegates here so
		 * `Hook_Registry` callback identity is unchanged.
		 *
		 * @since 2.4.0
		 * @var Css_Combine|null
		 */
		private ?Css_Combine $css_combine = null;

		/**
		 * Lazily-created invalidation/purge runner (ARCH-007).
		 *
		 * Single owner for the invalidate/clear/delete/fallback/swap-purge
		 * cluster; every invalidation proxy delegates here so static callers,
		 * `method_exists()` guards, and `Hook_Registry` callback identity
		 * are unchanged.
		 *
		 * @since 2.4.0
		 * @var Cache_Invalidator|null
		 */
		private ?Cache_Invalidator $cache_invalidator = null;

		/**
		 * Constructor to initialize cache settings and configurations.
		 *
		 * Collaborators are constructor-injected (REF-006): when live
		 * instances are supplied they are shared by identity, so the
		 * instance reuses them instead of diverging onto a stale options
		 * snapshot. Callers that hold no live collaborators should build
		 * through {@see Main::create_cache()}, the preferred path. The
		 * collaborators are optional-nullable for backward compatibility
		 * (direct `new Cache( $options )` keeps working, including
		 * third-party code): omitted collaborators are built lazily from
		 * the resolved options on first buffer use, so purge/read-only
		 * paths never pay for construction they do not need.
		 *
		 * @param array                   $options            Plugin options. Callers pass Util::get_settings(); when empty, loaded from DB via Util::get_settings() for backward compatibility.
		 * @param Image_Optimisation|null $image_optimisation Live image-optimisation collaborator (identity shared with Main), or null to build lazily from the resolved options.
		 * @param Google_Fonts|null       $google_fonts       Live Google-Fonts collaborator (identity shared with Main), or null to build lazily from the resolved options.
		 * @since 1.0.0
		 * @since 2.4.0 Constructor collaborators are optional-nullable with lazy in-class fallback.
		 */
		public function __construct( array $options = array(), ?Image_Optimisation $image_optimisation = null, ?Google_Fonts $google_fonts = null ) {
			$this->image_optimisation = $image_optimisation;
			$this->google_fonts       = $google_fonts;
			$raw_host                 = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$request_host             = Util::normalize_cache_host( $raw_host );
			$canonical                = Util::get_canonical_host();

			if ( '' !== $canonical ) {
				// Pin the cache key to the canonical home host by construction:
				// a forged Host header can never create its own cache tree.
				// An absent/blank request host (CLI/cron, no forgery signal) is
				// not a mismatch so background contexts can still read/write
				// the canonical tree; a presented-but-invalid host that
				// normalizes to '' (e.g. 'evil!/..') is still a mismatch so it
				// cannot poison the canonical file.
				$domain              = $canonical;
				$valid_domain        = true;
				$raw_trimmed         = trim( (string) $raw_host );
				$this->host_mismatch = ( '' === $request_host ? '' !== $raw_trimmed : $request_host !== $canonical );
			} else {
				// Canonical host unavailable (early boot, CLI): legacy
				// Host-derived behaviour so nothing fatals.
				$domain              = $request_host;
				$valid_domain        = ( '' !== $request_host );
				$this->host_mismatch = false;
			}

			$this->domain = $domain;

			// Define cache root directory and URL. Guard empty WP_CONTENT_DIR to prevent writing to filesystem root.
			if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR || ! defined( 'WP_CONTENT_URL' ) ) {
				$this->cache_root_dir = '';
				$this->cache_root_url = '';
			} else {
				$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_DIR );
				$this->cache_root_url = WP_CONTENT_URL . self::CACHE_DIR;
			}

			$this->request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

			// Normalize encoded sequences through the shared helper (single
			// controlled decode pass, null-byte + dotdot + drive/UNC
			// rejection, PHP_URL_PATH extraction). Absolute-form
			// request-targets (`GET https://host/path`, protocol-relative
			// `//host/path`, drive `C:\x`, UNC `\\host`) are refused outright: only the authority part
			// (before `?`/`#`) is inspected so query strings carrying URLs
			// never false-positive. Hostile input sets path_rejected so the
			// probe is never silently mapped to the homepage index.html.
			// The split uses strcspn() (no process-global strtok() state).
			$uri_target         = substr( $this->request_uri, 0, strcspn( $this->request_uri, '?#' ) );
			$uri_target_trimmed = ltrim( $uri_target );
			$is_absolute_form   = (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $uri_target_trimmed ) || 0 === strpos( $uri_target_trimmed, '//' );

			// Reject drive/UNC-prefixed targets before URL parsing strips
			// the evidence (parse_url() reads `C:` as a scheme and hides
			// the absolute-path smuggling attempt).
			$is_drive_or_unc = (bool) preg_match( '#^[a-zA-Z]:#', $uri_target_trimmed ) || 0 === strpos( $uri_target_trimmed, '\\\\' );

			if ( function_exists( 'wp_parse_url' ) ) {
				$raw_component = wp_parse_url( $this->request_uri, PHP_URL_PATH );
			} else {
				$raw_component = parse_url( $this->request_uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
			}
			if ( null === $raw_component || false === $raw_component ) {
				$raw_component = $this->request_uri;
			}

			// $raw_component is already path-only (PHP_URL_PATH extracted
			// above); absolute-form is handled via $is_absolute_form, so no
			// host context is passed here (it could never trigger).
			$url_path = Util::sanitize_cache_url_path( (string) $raw_component );

			if ( $is_absolute_form || $is_drive_or_unc || ( '' === $url_path && '' !== trim( trim( (string) $raw_component ), '/' ) ) ) {
				$this->path_rejected = true;
				// Log only the target before `?`/`#`: the full REQUEST_URI can
				// carry tokens/PII (reset keys, nonces, emails) that must not
				// persist in the activity-log table.
				$this->log_traversal_probe( $uri_target );
				$url_path = '';
			}

			$this->url_path = $url_path;

			// Initialize filesystem lazily via get_filesystem().
			$this->options = ! empty( $options ) ? $options : Util::get_settings();

			if ( ! empty( $this->options['debug'] ) ) {
				if ( $this->host_mismatch ) {
					do_action( 'wppo_debug_log', 'Cache host mismatch: request host differs from canonical home host, serving uncached' );
				} elseif ( ! $valid_domain ) {
					do_action( 'wppo_debug_log', 'Cache domain validation failed' );
				}
			}
		}

		/**
		 * Whether the request Host header mismatched the canonical home host.
		 *
		 * A mismatched request is served uncached (fail-open) and never writes
		 * a cache file, so forged hosts can neither poison nor read stored
		 * payloads.
		 *
		 * @return bool True when the request host differs from the canonical host.
		 * @since 2.0.0
		 */
		public function is_host_mismatched(): bool {
			return $this->host_mismatch;
		}

		/**
		 * Whether the request Host header mismatched the canonical home host.
		 *
		 * Additive alias of {@see is_host_mismatched()} using the issue's
		 * canonical API name (`Cache::host_mismatch`). Pure delegation, no
		 * behaviour change: true means serve dynamic uncached and never
		 * write a cache file.
		 *
		 * @return bool True when the request host differs from the canonical host.
		 * @since 2.3.0
		 */
		public function host_mismatch(): bool {
			return $this->host_mismatch;
		}

		/**
		 * Canonical, query-normalized cache key for the current request.
		 *
		 * The key is pinned to the allowlisted canonical host resolved from
		 * `home_url()` via `wp_parse_url()` (both guarded with
		 * `function_exists()` inside {@see Util::get_canonical_host()}; see
		 * {@see Util::resolve_canonical_host()}): a forged `Host` header can
		 * never create its own key — `$this->domain` is the canonical host,
		 * never the request host, and mismatched requests are served dynamic
		 * uncached (see {@see is_not_cacheable()}) and never stored (see
		 * {@see maybe_store_cache()}). The key itself stays canonical on
		 * mismatch so callers can observe the pinning; servability is decided
		 * by {@see is_host_mismatched()}, not by this key.
		 *
		 * Query-aware by construction: the key is path-only. Known tracking
		 * params (`utm_*`, `gclid`, `fbclid`, … — the `has_filter()`-guarded
		 * `wppo_cache_query_allowlist` filter in
		 * {@see Util::get_cache_query_allowlist()}, classified by
		 * {@see Util::has_uncacheable_query()}) are cache-neutral for the
		 * read decision, while the write path refuses ANY query-bearing
		 * response, so `/?utm_source=x` can never poison the clean-URL entry.
		 *
		 * No absolute URL in the key (or in cached output) is ever built from
		 * `HTTP_HOST`; absolute URLs use `home_url()`/the canonical host with
		 * legacy Host-derived fallback only when the canonical host is
		 * unresolvable (early boot/CLI). Multisite-safe: `home_url()` is
		 * blog-aware, so each site keys its own canonical tree.
		 *
		 * @return string `{canonical-host}/{path}` (homepage: `{host}/`), or '' when refused.
		 * @since 2.2.0
		 */
		public function cache_key(): string {
			if ( '' === $this->domain || $this->path_rejected ) {
				return '';
			}
			if ( '' === $this->url_path ) {
				return $this->domain . '/';
			}
			return $this->domain . '/' . $this->url_path;
		}

		/**
		 * Check whether page caching is allowed for the current (possibly logged-in) user.
		 *
		 * - Not logged in: always allowed.
		 * - Logged in + setting off: not allowed (preserves legacy skip-for-all-logged-in).
		 * - Logged in + setting on + no roles selected: allowed for all logged-in.
		 * - Logged in + setting on + roles selected: only if current user has an allowed role.
		 *
		 * @since 1.9.0
		 * @return bool True if the current user may receive a cached page.
		 */
		private function is_cache_allowed_for_current_user(): bool {
			return Util::is_cache_eligible_for_current_user(
				$this->options['cache_settings'] ?? array()
			);
		}

		/**
		 * Compute a stable 12-char hex hash of the current user's sorted roles.
		 * Returns empty string for visitors (no role to hash).
		 *
		 * @since 1.9.0
		 * @return string Role hash or empty string.
		 */
		private function get_logged_in_role_hash(): string {
			if ( ! is_user_logged_in() ) {
				return '';
			}

			$user = wp_get_current_user();
			return Util::get_role_hash( $user );
		}

		/**
		 * Set the Image_Optimisation instance to reuse instead of creating a new one.
		 *
		 * Deprecated thin proxy (REF-006): collaborators are constructor-injected,
		 * so this only re-points the already-initialized property for legacy
		 * callers. New code must pass the instance to the constructor.
		 *
		 * @deprecated NEXT Use constructor injection instead.
		 * @param Image_Optimisation $image_optimisation The existing instance.
		 * @return void
		 * @since 2.0.0
		 */
		public function set_image_optimisation( Image_Optimisation $image_optimisation ): void {
			$this->image_optimisation = $image_optimisation;
		}

		/**
		 * Set the Google_Fonts instance to reuse instead of creating a new one.
		 *
		 * Deprecated thin proxy (REF-006): collaborators are constructor-injected,
		 * so this only re-points the already-initialized property for legacy
		 * callers. New code must pass the instance to the constructor.
		 *
		 * @deprecated NEXT Use constructor injection instead.
		 * @param Google_Fonts $google_fonts The existing instance.
		 * @return void
		 * @since 2.0.0
		 */
		public function set_google_fonts( Google_Fonts $google_fonts ): void {
			$this->google_fonts = $google_fonts;
		}

		/**
		 * Lazily resolve the Image_Optimisation buffer collaborator.
		 *
		 * Returns the constructor-injected live instance when present;
		 * otherwise builds the equivalent from the resolved options on
		 * first use (and memoizes it) so purge/read-only paths never pay
		 * for construction, while a filtered stub, unserialization, or
		 * reflection that left the property null degrades to the same
		 * equivalent instead of fataling on the hot buffer path.
		 *
		 * @return Image_Optimisation Non-null collaborator.
		 * @since 2.4.0
		 */
		private function get_image_optimisation(): Image_Optimisation {
			if ( null === $this->image_optimisation ) {
				$this->image_optimisation = new Image_Optimisation( $this->options );
			}
			return $this->image_optimisation;
		}

		/**
		 * Lazily resolve the Google_Fonts buffer collaborator.
		 *
		 * Same contract as {@see get_image_optimisation()}: injected live
		 * instance when present, equivalent built from the resolved
		 * options on first use otherwise.
		 *
		 * @return Google_Fonts Non-null collaborator.
		 * @since 2.4.0
		 */
		private function get_google_fonts(): Google_Fonts {
			if ( null === $this->google_fonts ) {
				$this->google_fonts = new Google_Fonts( $this->options );
			}
			return $this->google_fonts;
		}

		/**
		 * Lazily initializes and returns the WP_Filesystem object.
		 *
		 * @return object|null The filesystem object, or null
		 *                     before the first initialization attempt.
		 * @since 1.6.0
		 */
		private function get_filesystem(): object|false|null {
			// Audit #1392: typed per docblock (false on init failure).
			if ( ! $this->fs_initialized ) {
				$this->filesystem     = Util::init_filesystem();
				$this->fs_initialized = true;
			}
			return $this->filesystem;
		}

		/**
		 * Whether WP 6.9+ core is loading separate (on-demand) core block assets.
		 *
		 * Single source of truth for the separate-assets state shared by the
		 * combined-CSS cache filename variant and every combine/preload loop. The
		 * 6.9+ gate keeps pre-6.9 cores (which have no such function) on the
		 * legacy monolith path, including cores with a backported
		 * `wp_should_load_separate_core_block_assets()` symbol. An absent
		 * `$wp_version` assumes the newest core, matching
		 * {@see get_styles_inline_limit()}.
		 *
		 * @return bool True when core loads separate core block assets on demand.
		 * @since 2.0.0
		 */
		private function block_assets_are_separate(): bool {
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
		 * Whether a style handle belongs to the core block-assets family.
		 *
		 * On WP 6.9+ with separate block assets active, the combined stylesheet
		 * (`wp-block-library`) and every per-block stylesheet (`wp-block-cover`,
		 * `wp-block-group`, …) are loaded on demand for the blocks actually present
		 * on the page. Folding them into the combined file would re-monolithize what
		 * core ships conditionally and make the combined file churn as the block set
		 * changes across pages, so they are excluded from combining in that mode.
		 *
		 * @param string $handle                The registered style handle.
		 * @param bool   $separate_block_assets Whether core loads separate block assets.
		 * @return bool True if the handle is a core block asset under separate assets.
		 * @since 2.0.0
		 */
		private function is_core_block_asset( $handle, bool $separate_block_assets ): bool {
			if ( $separate_block_assets && $this->is_combined_core_block_monolith_forced() ) {
				return false;
			}
			return $separate_block_assets && str_starts_with( (string) $handle, 'wp-block-' );
		}

		/**
		 * Whether the operator forced the combined core block-assets monolith.
		 *
		 * Explicit, fail-open escape hatch for the on-demand block-styles
		 * pipeline: when `blockAssetsOnDemand` is off or
		 * `loadAllCoreBlockAssets` is on, core block styles (`wp-block-*`)
		 * stay combinable even if `wp_should_load_separate_core_block_assets()`
		 * reports separate loading. Mirrors the opt-out registered by
		 * `Hook_Registry::register_block_assets_filters()` so `Cache` never depends on
		 * that filter's side effect (which is skipped on block themes and
		 * never runs in unit-test isolation). Any throwable or missing
		 * options structure returns false (legacy separate-assets path).
		 *
		 * @return bool True when the combined monolith is forced.
		 * @since 2.2.0
		 */
		private function is_combined_core_block_monolith_forced(): bool {
			try {
				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					return false;
				}
				$file_opt = $this->options['file_optimisation'];
				return empty( $file_opt['blockAssetsOnDemand'] ) || ! empty( $file_opt['loadAllCoreBlockAssets'] );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Effective separate-assets state after the monolith escape hatch.
		 *
		 * Single funnel for every combine/preload loop: the core
		 * `should_load_separate_core_block_assets` state from
		 * {@see block_assets_are_separate()} forced off when
		 * {@see is_combined_core_block_monolith_forced()} holds, so no
		 * scattered skip site can disagree about block-asset ownership.
		 *
		 * @return bool True when core owns on-demand block styles on this request.
		 * @since 2.2.0
		 */
		private function get_effective_separate_block_assets(): bool {
			if ( $this->is_combined_core_block_monolith_forced() ) {
				return false;
			}
			return $this->block_assets_are_separate();
		}

		/**
		 * Whether WP 6.8 classic on-demand block-asset loading is active.
		 *
		 * Pre-6.9 cores (6.8 `should_load_block_assets_on_demand` filter /
		 * `wp_should_load_block_assets_on_demand()`) load core block styles on
		 * demand when core reports it at runtime. The 6.9+ separate-assets world
		 * is owned by {@see block_assets_are_separate()}; this covers the
		 * classic world so those handles are never folded into the combined
		 * file either. Positive runtime evidence only: the core function wins
		 * when present, then a `has_filter`-guarded
		 * `should_load_block_assets_on_demand` read (which reflects the opt-in
		 * registered by `Hook_Registry::register_block_assets_filters()` on real
		 * requests). The per-site `blockAssetsOnDemand` option alone is never
		 * sufficient here — in unit-test isolation (or when Main never ran) it
		 * would flip legacy combines without core actually loading on demand.
		 * The combined-monolith escape hatch forces false. Fail-open: any
		 * throwable, missing API, or absent evidence returns false (legacy
		 * combine behavior unchanged).
		 *
		 * @return bool True when classic on-demand block assets are active.
		 * @since 2.2.0
		 */
		private function classic_block_assets_on_demand_active(): bool {
			try {
				// Classic on-demand is a pre-6.9 world: on 6.9+ (or when the
				// version is unknown, which assumes newest per codebase
				// convention) the separate-assets path owns `wp-block-*`.
				// Gating on version first also keeps this probe-free on 6.9+
				// so exact-count `function_exists` unit expectations stay stable.
				// Version floor lives in Wp_Version (REF-010, $GLOBALS-only read).
				if ( Wp_Version::is_global_at_least( '6.9-alpha' ) ) {
					return false;
				}
				if ( $this->is_combined_core_block_monolith_forced() ) {
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
		 * Whether a handle is a classic (pre-6.9) on-demand block asset.
		 *
		 * @param string $handle The registered style handle.
		 * @return bool True when the handle must stay out of the combined file.
		 * @since 2.2.0
		 */
		private function is_classic_on_demand_block_asset( $handle ): bool {
			try {
				if ( ! $this->classic_block_assets_on_demand_active() ) {
					return false;
				}
				return str_starts_with( (string) $handle, 'wp-block-' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a queued style handle is a verifiable core per-block asset.
		 *
		 * Defensive `src` check mirroring `Main::is_core_per_block_style_handle()`:
		 * the `wp-block-*` prefix alone is not proof of core ownership. Only
		 * handles whose registered `src` points at core block styles
		 * (`wp-includes` + `block-library` or `/blocks/`) count. Fail-open:
		 * unregistered handles or unverifiable `src` return false (keep the
		 * asset — degrade to unoptimized, never unstyled).
		 *
		 * @param string $handle Queued style handle e.g. 'wp-block-cover'.
		 * @return bool True when the handle is verifiably a core per-block asset.
		 * @since 2.2.0
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
					return false !== strpos( $src, 'wp-includes' );
				}
				return false !== strpos( $src, 'wp-includes' ) && false !== strpos( $src, '/blocks/' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a handle is a hidden block asset that must stay out of the combine.
		 *
		 * Defensive backstop for `Main::omit_hidden_block_assets()`: that pass
		 * dequeues per-block stylesheets for blocks absent from the singular
		 * post content, but it bails on archives, block themes, unresolvable
		 * block sources, or when core 6.9 hoisting owns the output. Any path
		 * where the omit pass bails (or ordering shifts) must still never embed
		 * a hidden handle in the combined file, so the combine pipeline
		 * re-checks type-absence here. Singular classic views only; block
		 * themes, archives, empty/unresolvable content, unverifiable `src`,
		 * and the `wppo_allow_hidden_block_asset` opt-in all fail open (return
		 * false — keep the handle combinable, never unstyled).
		 *
		 * @param string $handle The registered style handle.
		 * @return bool True when the handle is hidden and must not be combined.
		 * @since 2.2.0
		 */
		private function is_hidden_block_asset_for_combine( $handle ): bool {
			try {
				$handle = (string) $handle;
				if ( 0 !== strpos( $handle, 'wp-block-' ) ) {
					return false;
				}
				$slug = substr( $handle, strlen( 'wp-block-' ) );
				if ( '' === $slug || 0 === strpos( $slug, 'library' ) ) {
					return false;
				}
				if ( ! $this->is_core_per_block_style_handle( $handle ) ) {
					return false;
				}
				if ( ! function_exists( 'is_singular' ) || ! is_singular() ) {
					return false;
				}
				if ( function_exists( 'wp_is_block_theme' ) ) {
					try {
						if ( wp_is_block_theme() ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				if ( ! isset( $this->options['file_optimisation'] ) || ! is_array( $this->options['file_optimisation'] ) ) {
					return false;
				}
				$file_opt = $this->options['file_optimisation'];
				if ( empty( $file_opt['blockAssetsOnDemand'] ) || ! empty( $file_opt['loadAllCoreBlockAssets'] ) ) {
					return false;
				}
				$content = '';
				if ( function_exists( 'get_the_ID' ) && function_exists( 'get_post_field' ) ) {
					try {
						$post_id = get_the_ID();
						if ( ! empty( $post_id ) ) {
							$content = (string) get_post_field( 'post_content', $post_id );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				if ( '' === $content ) {
					return false;
				}
				$block_name = 'core/' . $slug;
				if ( Util::content_has_block( $content, $block_name ) ) {
					return false;
				}
				if ( function_exists( 'has_filter' ) && has_filter( 'wppo_allow_hidden_block_asset' ) ) {
					try {
						$allowed = apply_filters( 'wppo_allow_hidden_block_asset', false, $block_name, $handle );
						if ( ! empty( $allowed ) ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a handle is already owned by core output and must never be combined.
		 *
		 * Single dedupe assertion for the combine pipeline: true when core will
		 * inline the handle under its `styles_inline_size_limit` budget
		 * ({@see core_will_inline()}), when core 6.9+ hoisting owns the block
		 * handle ({@see is_core_block_asset()}), when classic 6.8 on-demand
		 * loading owns it ({@see is_classic_on_demand_block_asset()}), or when
		 * it is a hidden block asset absent from the current singular content
		 * ({@see is_hidden_block_asset_for_combine()}). Every combine/preload loop
		 * funnels through this so no scattered skip site can emit duplicate
		 * style output for the same handle.
		 *
		 * @param string $handle                The registered style handle.
		 * @param bool   $separate_block_assets Whether core loads separate block assets.
		 * @return bool True when the handle must stay out of the combined file.
		 * @since 2.0.0
		 */
		private function is_duplicate_of_core_output( $handle, bool $separate_block_assets ): bool {
			if ( $this->is_core_block_asset( $handle, $separate_block_assets ) ) {
				return true;
			}
			if ( $this->is_classic_on_demand_block_asset( $handle ) ) {
				return true;
			}
			if ( $this->is_hidden_block_asset_for_combine( $handle ) ) {
				return true;
			}
			return $this->core_will_inline( $handle );
		}

		/**
		 * Whether WPPO optimisers should be bypassed for LiteSpeed co-existence.
		 *
		 * Centralises the `litespeed_can_optm` gate that was copy-pasted across
		 * combine/minify/CDN paths.
		 *
		 * @since 2.0.0
		 * @return bool True if the current request should bypass WPPO optimisation.
		 */
		private function should_bypass_for_litespeed(): bool {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return true;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Per-request memo for the sandbox-effective slice (issue #1259).
		 *
		 * Runs on both combine_css() and will_combine_css_inline() per
		 * request; the memo collapses the duplicate slice resolution
		 * (is_preview_request + get_settings).
		 *
		 * @since 2.2.0
		 * @var array<string,array>
		 */
		private array $sandbox_effective_file_opt_memo = array();

		/**
		 * Per-request memo for the sandbox preview flag (issue #1465).
		 *
		 * Per-file inline checks resolve
		 * Main::is_sandbox_preview_active() on every call repeats the
		 * Sandbox_Preview::is_preview_request() chain per file. Memoized
		 * here (null = unresolved) so the predicate is evaluated once per
		 * Cache instance lifetime (one request).
		 *
		 * @since 2.3.0
		 * @var bool|null
		 */
		private $sandbox_preview_memo = null;

		/**
		 * Per-request memo for the safe-mode inline bypass (issue #1465).
		 *
		 * Keyed by the production slice hash so repeated per-file calls
		 * with the same options reuse the verdict. Fail-open: unresolved
		 * or failed lookups mean "do not bypass".
		 *
		 * @since 2.3.0
		 * @var array<string,bool>
		 */
		private $safe_mode_inline_memo = array();

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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::get_sandbox_effective_file_opt}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function get_sandbox_effective_file_opt( array $file_opt ): array {
			return $this->css_combine()->get_sandbox_effective_file_opt( $file_opt );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::should_bypass_combine_for_elementor}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function should_bypass_combine_for_elementor( array $file_opt, ?bool $looks_like = null ): bool {
			return $this->css_combine()->should_bypass_combine_for_elementor( $file_opt, $looks_like );
		}

		/**
		 * Lazily-created CSS-combine runner (ARCH-006).
		 *
		 * Single owner for the fetch/minify/write/journal/preload/budget/
		 * fallback cluster; every combine proxy delegates here (instance
		 * methods) so `Hook_Registry` callback identity is unchanged.
		 *
		 * @since 2.4.0
		 * @return Css_Combine Combine runner bound to this instance.
		 */
		private function css_combine(): Css_Combine {
			if ( null === $this->css_combine ) {
				$this->css_combine = new Css_Combine( $this );
			}
			return $this->css_combine;
		}

		/**
		 * Lazily-created invalidation/purge runner (ARCH-007).
		 *
		 * Single owner for the invalidate/clear/delete/fallback/swap-purge
		 * cluster; every invalidation proxy delegates here (instance
		 * methods) so `Hook_Registry` callback identity is unchanged.
		 *
		 * @since 2.4.0
		 * @return Cache_Invalidator Invalidator bound to this instance.
		 */
		private function invalidator(): Cache_Invalidator {
			if ( null === $this->cache_invalidator ) {
				$this->cache_invalidator = new Cache_Invalidator( $this );
			}
			return $this->cache_invalidator;
		}

		/**
		 * Combines all enqueued CSS files into a single file.
		 *
		 * @return void
		 * @since 1.0.0
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::combine_css}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		public function combine_css() {
			$this->css_combine()->combine_css();
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::resolve_eligible_handles}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function resolve_eligible_handles( array $styles, array $exclusions ): array {
			return $this->css_combine()->resolve_eligible_handles( $styles, $exclusions );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::fetch_and_minify_css}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function fetch_and_minify_css( array $eligible_handles ): array {
			return $this->css_combine()->fetch_and_minify_css( $eligible_handles );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::write_combined_file}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function write_combined_file( string $combined_css, string $css_variant ): array {
			return $this->css_combine()->write_combined_file( $combined_css, $css_variant );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::emit_combined_preload_hint}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function emit_combined_preload_hint( $css_url, $version, string $css_file_path ): void {
			$this->css_combine()->emit_combined_preload_hint( $css_url, $version, $css_file_path );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::set_combine_css_preload}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function set_combine_css_preload( $css_url, $version, $css_file_path ): void {
			$this->css_combine()->set_combine_css_preload( $css_url, $version, $css_file_path );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::maybe_preload_combine_css}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		public function maybe_preload_combine_css(): void {
			$this->css_combine()->maybe_preload_combine_css();
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::is_excluded_from_combine}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function is_excluded_from_combine( $handle, $src, array $exclude_combine_css ): bool {
			return $this->css_combine()->is_excluded_from_combine( $handle, $src, $exclude_combine_css );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::get_combined_handles}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function get_combined_handles( $styles, $exclude_combine_css ): array {
			return $this->css_combine()->get_combined_handles( $styles, $exclude_combine_css );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::combined_handles_match}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function combined_handles_match( $css_file_path, array $eligible_handles ): bool {
			return $this->css_combine()->combined_handles_match( $css_file_path, $eligible_handles );
		}

		/**
		 * Persists the set of combined handles next to the combined CSS file.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path  Absolute path to the combined CSS file.
		 * @param array  $eligible_handles The handles combined into the file.
		 * @return void
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::write_combined_handles}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function write_combined_handles( $css_file_path, array $eligible_handles ): void {
			$this->css_combine()->write_combined_handles( $css_file_path, $eligible_handles );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::core_will_inline}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function core_will_inline( $handle ): bool {
			return $this->css_combine()->core_will_inline( $handle );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::core_inline_budget_will_inline}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function core_inline_budget_will_inline( $handle, $limit, $core_faithful ): bool {
			return $this->css_combine()->core_inline_budget_will_inline( $handle, $limit, $core_faithful );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::log_inline_budget_drift}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function log_inline_budget_drift( $handle, $limit ): void {
			$this->css_combine()->log_inline_budget_drift( $handle, $limit );
		}

		/**
		 * Whether the safe CSS combine fallback is enabled.
		 *
		 * Operator opt-out via `wppo_safe_css_combine_fallback` (default true).
		 * When false the legacy combine path is used without strict guards.
		 *
		 * @since 2.0.0
		 * @return bool True when fallback guards are active.
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::is_safe_css_combine_fallback_enabled}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function is_safe_css_combine_fallback_enabled(): bool {
			return $this->css_combine()->is_safe_css_combine_fallback_enabled();
		}

		/**
		 * Whether a combined CSS file is valid (exists, readable, non-empty).
		 *
		 * @since 2.0.0
		 * @param string $path Absolute path to the combined CSS file.
		 * @return bool True when the file is usable.
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::is_combined_css_valid}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function is_combined_css_valid( string $path ): bool {
			return $this->css_combine()->is_combined_css_valid( $path );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::log_combine_fallback}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function log_combine_fallback( string $reason, array $handles ): void {
			$this->css_combine()->log_combine_fallback( $reason, $handles );
		}

		/**
		 * Registers the combined CSS file with `path` data for core's inline pass.
		 *
		 * @since 1.9.0
		 *
		 * @param string $css_file_path Absolute path to the combined CSS file.
		 * @return void
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::register_combine_css_path}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function register_combine_css_path( $css_file_path ): void {
			$this->css_combine()->register_combine_css_path( $css_file_path );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::should_bypass_inline_for_safe_mode}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function should_bypass_inline_for_safe_mode( array $file_opt ): bool {
			return $this->css_combine()->should_bypass_inline_for_safe_mode( $file_opt );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::will_combine_css_inline}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function will_combine_css_inline( $css_file_path ): bool {
			return $this->css_combine()->will_combine_css_inline( $css_file_path );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::get_styles_inline_limit}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function get_styles_inline_limit(): int {
			return $this->css_combine()->get_styles_inline_limit();
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::inline_candidates_require_src}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function inline_candidates_require_src(): bool {
			return $this->css_combine()->inline_candidates_require_src();
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::inline_candidates_require_readable}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function inline_candidates_require_readable(): bool {
			return $this->css_combine()->inline_candidates_require_readable();
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::get_cached_src_stat}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function get_cached_src_stat( string $path ): array {
			return $this->css_combine()->get_cached_src_stat( $path );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::should_skip_combine_for_inline_budget}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function should_skip_combine_for_inline_budget( array $eligible_handles ): bool {
			return $this->css_combine()->should_skip_combine_for_inline_budget( $eligible_handles );
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
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::measure_style_byte_size}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function measure_style_byte_size( $handle ) {
			return $this->css_combine()->measure_style_byte_size( $handle );
		}

		/**
		 * Fetches CSS content from a remote URL or local path.
		 *
		 * @param string $url The URL of the CSS file.
		 * @return string|false The CSS content or false if fetching fails.
		 *
		 * @since 1.0.0
		 * Facade proxy (ARCH-006): logic lives in {@see Css_Combine::fetch_remote_css}.
		 * @since 2.4.0 Proxied to Css_Combine (ARCH-006).
		 */
		private function fetch_remote_css( $url ) {
			return $this->css_combine()->fetch_remote_css( $url );
		}

		/**
		 * Plugin options snapshot for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same options read the relocated bodies
		 * had on `Cache`. Read-only: the service never writes options.
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return array Plugin options snapshot.
		 */
		public function combine_options(): array {
			return $this->options;
		}

		/**
		 * Filesystem for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return object|false|null The filesystem object, or false on init failure.
		 */
		public function combine_filesystem(): object|false|null {
			return $this->get_filesystem();
		}

		/**
		 * Combined-CSS file path for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @param string $variant Cache variant suffix.
		 * @return string Absolute path to the combined CSS file.
		 */
		public function combine_cache_file_path( string $variant ): string {
			return $this->get_cache_file_path( 'css', '', $variant );
		}

		/**
		 * Cache-directory preparation for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when the cache directory is writable.
		 */
		public function combine_prepare_cache_dir(): bool {
			return $this->prepare_cache_dir();
		}

		/**
		 * Persist the combined CSS for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @param string $css  Combined CSS payload.
		 * @param string $path Absolute path to the combined CSS file.
		 * @return void
		 */
		public function combine_save_css( string $css, string $path ): void {
			$this->save_cache_files( $css, $path, 'css' );
		}

		/**
		 * LiteSpeed bypass verdict for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when the current request must bypass WPPO optimisation.
		 */
		public function combine_should_bypass_for_litespeed(): bool {
			return $this->should_bypass_for_litespeed();
		}

		/**
		 * Logged-in cache gate for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when page output may be cached for the current user.
		 */
		public function combine_is_cache_allowed_for_current_user(): bool {
			return $this->is_cache_allowed_for_current_user();
		}

		/**
		 * Cacheability gate for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when the current request must not be cached.
		 */
		public function combine_is_not_cacheable(): bool {
			return $this->is_not_cacheable();
		}

		/**
		 * Effective separate block-assets state for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when core owns on-demand block styles on this request.
		 */
		public function combine_get_effective_separate_block_assets(): bool {
			return $this->get_effective_separate_block_assets();
		}

		/**
		 * Core-output dedupe verdict for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @param string $handle                The registered style handle.
		 * @param bool   $separate_block_assets Whether core loads separate block assets.
		 * @return bool True when the handle must stay out of the combined file.
		 */
		public function combine_is_duplicate_of_core_output( string $handle, bool $separate_block_assets ): bool {
			return $this->is_duplicate_of_core_output( $handle, $separate_block_assets );
		}

		/**
		 * Log throttle for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @param string $throttle_key Transient key guarding the throttle window.
		 * @param int    $ttl          Throttle window in seconds.
		 * @return bool True when the key was already seen inside the window.
		 */
		public function combine_is_throttled( string $throttle_key, int $ttl ): bool {
			return $this->is_throttled( $throttle_key, $ttl );
		}

		/**
		 * Inline-drift flag read for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when the inline-budget prediction drifted from core this request.
		 */
		public function combine_inline_drift_detected(): bool {
			return $this->inline_drift_detected;
		}

		/**
		 * Drift-log process gate read for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool True when the drift notice was already logged this PHP process.
		 */
		public static function combine_inline_drift_already_logged(): bool {
			return self::$inline_drift_logged;
		}

		/**
		 * Drift-log process gate write for the CSS-combine service (ARCH-006 internal bridge).
		 *
		 * Audit note: only `Css_Combine` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return void
		 */
		public static function combine_mark_inline_drift_logged(): void {
			self::$inline_drift_logged = true;
		}

		/**
		 * Direct reference to the combine preload URL (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Audit note: only `Css_Combine`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return string Reference to the live preload URL state.
		 */
		public function &combine_state_preload_url(): string {
			return $this->combine_css_preload_url;
		}

		/**
		 * Direct reference to the inline-budget size map (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Audit note: only `Css_Combine`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return array<string,array{size:int,readable:bool}>|null Reference to the live size-map state.
		 */
		public function &combine_state_inline_size_map(): ?array {
			return $this->inline_size_map;
		}

		/**
		 * Direct reference to the core-will-inline memo (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Audit note: only `Css_Combine`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return array<string,bool> Reference to the live will-inline memo.
		 */
		public function &combine_state_core_will_inline_memo(): array {
			return $this->core_will_inline_memo;
		}

		/**
		 * Direct reference to the src stat LRU (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Audit note: only `Css_Combine`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return array<string,array{readable:bool,size:int|false}> Reference to the live stat cache.
		 */
		public function &combine_state_src_stat_cache(): array {
			return $this->src_stat_cache;
		}

		/**
		 * Direct reference to the sandbox-effective slice memo (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Per-request lifetime keyed by the
		 * production slice hash, so staged preview output and production
		 * output never share a verdict. Audit note: only `Css_Combine` calls
		 * this (no other runtime or test caller exists). Do not call from new
		 * code; the public visibility exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return array<string,array> Reference to the live sandbox-slice memo.
		 */
		public function &combine_state_sandbox_effective_file_opt_memo(): array {
			return $this->sandbox_effective_file_opt_memo;
		}

		/**
		 * Direct reference to the sandbox preview-flag memo (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Audit note: only `Css_Combine`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool|null Reference to the live preview-flag memo (null = unresolved).
		 */
		public function &combine_state_sandbox_preview_memo() {
			return $this->sandbox_preview_memo;
		}

		/**
		 * Direct reference to the safe-mode inline-bypass memo (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Per-request lifetime keyed by the
		 * production slice hash. Audit note: only `Css_Combine` calls this
		 * (no other runtime or test caller exists). Do not call from new code;
		 * the public visibility exists solely for the extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return array<string,bool> Reference to the live safe-mode memo.
		 */
		public function &combine_state_safe_mode_inline_memo(): array {
			return $this->safe_mode_inline_memo;
		}

		/**
		 * Direct reference to the inline-drift flag (ARCH-006 internal bridge).
		 *
		 * Gives {@see Css_Combine} the same live request-state access the
		 * relocated bodies had on `Cache`. Audit note: only `Css_Combine`
		 * calls this (no other runtime or test caller exists). Do not call
		 * from new code; the public visibility exists solely for the
		 * extraction bridge.
		 *
		 * @internal
		 * @since 2.4.0
		 * @return bool Reference to the live drift flag.
		 */
		public function &combine_state_inline_drift_detected(): bool {
			return $this->inline_drift_detected;
		}

		/**
		 * Filesystem for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @return object|false|null The filesystem object, or false on init failure.
		 */
		public function invalidator_filesystem(): object|false|null {
			return $this->get_filesystem();
		}

		/**
		 * Path-containment verdict for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @param string $path Absolute file or directory path.
		 * @return bool True when contained.
		 */
		public function invalidator_is_path_contained( string $path ): bool {
			return $this->is_path_contained( $path );
		}

		/**
		 * Cache file path for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @param string|null $url_path The URL path (optional).
		 * @param string      $type     The file type (default: 'html').
		 * @return string The file path.
		 */
		public function invalidator_get_file_path( ?string $url_path = null, string $type = 'html' ): string {
			return $this->get_file_path( $url_path, $type );
		}

		/**
		 * Traversal-probe log for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @param string $raw_input The hostile input that was rejected.
		 * @return void
		 */
		public function invalidator_log_traversal_probe( string $raw_input ): void {
			$this->log_traversal_probe( $raw_input );
		}

		/**
		 * Cache root directory for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Direct raw read is intentional: no accessor exists for this property,
		 * so the bridge returns the canonical single-source-of-truth value.
		 * If an accessor is introduced later, route this bridge through it.
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @return string Cache root directory.
		 */
		public function invalidator_cache_root_dir(): string {
			return $this->cache_root_dir;
		}

		/**
		 * Cache domain for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Direct raw read is intentional: no accessor exists for this property,
		 * so the bridge returns the canonical single-source-of-truth value.
		 * If an accessor is introduced later, route this bridge through it.
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @return string Cache domain.
		 */
		public function invalidator_domain(): string {
			return $this->domain;
		}

		/**
		 * Cache root URL for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Direct raw read is intentional: no accessor exists for this property,
		 * so the bridge returns the canonical single-source-of-truth value.
		 * If an accessor is introduced later, route this bridge through it.
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @return string Cache root URL.
		 */
		public function invalidator_cache_root_url(): string {
			return $this->cache_root_url;
		}

		/**
		 * Cache directory slug for the invalidation/purge service (ARCH-007 internal bridge).
		 *
		 * Audit note: only `Cache_Invalidator` calls this (no other runtime or test
		 * caller exists). Do not call from new code; the public visibility
		 * exists solely for the extraction bridge. A `_doing_it_wrong()` guard
		 * is deliberately omitted: it would fire on the legitimate internal
		 * caller every request.
		 *
		 * @internal
		 * @access private
		 * @since 2.4.0
		 * @return string Cache directory slug (`CACHE_DIR`).
		 */
		public function invalidator_cache_dir(): string {
			return self::CACHE_DIR;
		}

		/**
		 * Start output buffer for static HTML cache (WP < 6.9 fallback).
		 *
		 * Creates a static HTML version of the page if not logged in and not a 404 page.
		 *
		 * Tracked by #829: do not remove until minimum supported WP is raised
		 * to 6.9 (`Requires at least: 6.9`).
		 *
		 * Buffer lifecycle guarantees (audit #888 finding 6):
		 * - the ob callback is Throwable-safe and always returns a string (the
		 *   original buffer on failure), so the page can never lose output and
		 *   the buffer can always be closed;
		 * - the opened level is tracked and a shutdown safety net (priority 0,
		 *   before core's wp_ob_end_flush_all() at priority 1) flushes the
		 *   buffer exactly once if nothing else closed it. When a third party
		 *   opened a deeper buffer the net stays out of the way — their close
		 *   cascades into ours.
		 *
		 * This hook only fires on template_redirect (front-end HTML), never in
		 * REST/AJAX/admin contexts, so buffered REST/JSON responses are not a
		 * concern by construction.
		 *
		 * @return void
		 *
		 * @since 1.0.0
		 */
		public function start_output_buffer(): void {
			// Single-buffer routing (issue #1386): when the core
			// template-enhancement buffer is available (WP 6.9+), core owns
			// output capture and the filter path
			// ({@see process_buffer_for_cache()}) is responsible for
			// processing. A private buffer here would stack on top of core's
			// and re-process (or cache pre-hoisting) HTML, so it must never
			// open on 6.9+ — a runtime opt-out degrades to uncached streaming
			// output instead.
			$use_core = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'should_use_core_template_buffer' ) ) {
					$use_core = Main::should_use_core_template_buffer();
				} else {
					$use_core = function_exists( 'wp_should_output_buffer_template_for_enhancement' );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$use_core = function_exists( 'wp_should_output_buffer_template_for_enhancement' );
			}
			if ( $use_core ) {
				return;
			}
			// Nesting guard (issue #881, pre-6.9 defense in depth): when the
			// core buffer is already active for this request, the filter path
			// owns processing and a private buffer must not stack on top.
			if ( function_exists( 'wp_should_output_buffer_template_for_enhancement' ) ) {
				try {
					if ( wp_should_output_buffer_template_for_enhancement() ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// Note (see #553, #829): legacy fallback kept until minimum supported WP is raised.
			// Blocked until `Requires at least: 6.9` — keep the legacy fallback.
			if ( ! $this->is_cache_allowed_for_current_user() || $this->is_not_cacheable() ) {
				return;
			}

			// Exactly-once start: a second invocation in the same request (should
			// not happen — the hook is registered once) would stack a duplicate
			// buffer and re-register the shutdown net.
			if ( null !== $this->cache_ob_level ) {
				return;
			}

			$role_hash = $this->get_logged_in_role_hash();
			$file_path = $this->get_cache_file_path( 'html', $role_hash );

			$ob_callback = function ( $buffer ) use ( $file_path ) {
				try {
					$buffer = $this->process_buffer_only( $buffer );
					$this->save_processed_buffer( $buffer, $file_path );
					return $buffer;
				} catch ( \Throwable $e ) {
					// Fail open: serve the page unprocessed instead of
					// throwing out of the buffer callback (which would
					// discard the response and leave the buffer dangling).
					do_action( 'wppo_debug_log', 'WPPO page cache buffer processing failed.', array( 'exception' => $e ) );
					return $buffer;
				}
			};

			// Only track the level and register the shutdown net when a buffer
			// was actually opened — otherwise the net could flush a foreign
			// buffer occupying that level.
			if ( ! ob_start( $ob_callback ) ) {
				do_action( 'wppo_debug_log', 'WPPO page cache could not open output buffer' );
				return;
			}

			// Track the level our buffer occupies for the shutdown safety net.
			$this->cache_ob_level = ob_get_level();
			if ( false === has_action( 'shutdown', array( $this, 'maybe_end_output_buffer' ) ) ) {
				add_action( 'shutdown', array( $this, 'maybe_end_output_buffer' ), 0 );
			}
		}

		/**
		 * Shutdown safety net: close the cache output buffer exactly once.
		 *
		 * Runs at shutdown priority 0, before core's wp_ob_end_flush_all()
		 * (priority 1). Acts only while the tracked buffer is still the
		 * topmost-open level: when a third party opened a deeper buffer, their
		 * close (or core's shutdown flush) cascades into ours and this method
		 * is a no-op. Also serves the "is_not_cacheable flipped mid-request"
		 * case: the save decision is made inside the callback, the (processed)
		 * buffer is always flushed to the client.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public function maybe_end_output_buffer(): void {
			if ( null === $this->cache_ob_level ) {
				return;
			}
			// One-shot: never attempt to close the same buffer twice.
			$level                = $this->cache_ob_level;
			$this->cache_ob_level = null;
			// Mid-template cancel safety (issue #1386): when a third party (or
			// core's own cancel path) already closed our buffer, the level no
			// longer matches and this is a no-op, never fatal. Fail-open on
			// any throwable so shutdown can never white-screen the response.
			try {
				if ( ob_get_level() === $level ) {
					ob_end_flush();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Hoist late-enqueued block stylesheets after the block library (WP 6.9 core parity).
		 *
		 * Core 6.9 hoists late-enqueued block assets into `<head>` behind the
		 * template-enhancement buffer (Trac #43258; 6.9 frontend-performance
		 * field guide). Because this pipeline now runs ON the core buffer
		 * (issue #1386), hoisted styles are already present: this pass is
		 * the fail-open net for markup where they are not: per-block
		 * stylesheet `<link>` tags still sitting after `</head>` (late
		 * `wp_enqueue_style()` calls printed in body/footer) are moved to
		 * directly after the `block-library` stylesheet link, preserving
		 * their relative order so the core cascade (library first, then
		 * per-block overrides) stays intact.
		 *
		 * HTML API only: discovery walks `WP_HTML_Tag_Processor`; the move
		 * itself uses plain string offsets, never regex. Idempotent (a
		 * second pass finds nothing after `</head>` and no-ops) and
		 * fail-open (any unexpected shape returns the input unchanged).
		 *
		 * @since 2.3.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The HTML with late block styles hoisted, or the input unchanged.
		 */
		private function hoist_late_block_styles( $buffer ) {
			try {
				if ( ! is_string( $buffer ) || '' === $buffer ) {
					return $buffer;
				}
				if ( ! class_exists( 'WP_HTML_Tag_Processor' ) ) {
					return $buffer;
				}
				// Cheap pre-gate: skip the processor walk unless the page
				// has a head close, the block library, and a per-block
				// marker. Large cache-miss HTML without block styles exits
				// on substring scans alone.
				if ( false === stripos( $buffer, '</head' )
					|| false === stripos( $buffer, 'block-library' )
					|| ( false === stripos( $buffer, 'wp-block-' ) && false === stripos( $buffer, '/blocks/' ) ) ) {
					return $buffer;
				}
				$head_close = stripos( $buffer, '</head>' );
				if ( false === $head_close ) {
					return $buffer;
				}
				// Discovery via the HTML API: ordered stylesheet hrefs.
				$processor = new \WP_HTML_Tag_Processor( $buffer );
				$hrefs     = array();
				while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
					$rel = $processor->get_attribute( 'rel' );
					if ( ! is_string( $rel ) || false === stripos( $rel, 'stylesheet' ) ) {
						continue;
					}
					$href = $processor->get_attribute( 'href' );
					if ( ! is_string( $href ) || '' === $href ) {
						continue;
					}
					$hrefs[] = $href;
				}
				if ( empty( $hrefs ) ) {
					return $buffer;
				}
				$library_href = null;
				foreach ( $hrefs as $href ) {
					if ( false !== stripos( $href, 'block-library' ) ) {
						$library_href = $href;
						break;
					}
				}
				if ( null === $library_href ) {
					return $buffer;
				}
				// Candidate late links: per-block stylesheets with no genuine
				// `<link>` occurrence in head. Head membership is resolved
				// from tag-verified occurrences (an href substring inside a
				// script string, preload, or comment before `</head>` must
				// not suppress the hoist), never from a raw substring search.
				// An href already present in head still has its late body
				// duplicates cut (without re-insertion) so head+body copies
				// cannot linger as duplicates.
				$candidates  = array();
				$dedupe_only = array();
				foreach ( $hrefs as $href ) {
					if ( $href === $library_href ) {
						continue;
					}
					if ( false !== stripos( $href, 'block-library' ) ) {
						continue;
					}
					if ( false === stripos( $href, 'wp-block-' ) && false === stripos( $href, '/blocks/' ) ) {
						continue;
					}
					if ( isset( $candidates[ $href ] ) || isset( $dedupe_only[ $href ] ) ) {
						continue;
					}
					// Already in head: cut late duplicates only (never re-insert,
					// avoids duplicating the handle).
					if ( $this->is_href_in_head_link( $buffer, $href, $head_close ) ) {
						$dedupe_only[ $href ] = true;
						continue;
					}
					$candidates[ $href ] = true;
				}
				if ( empty( $candidates ) && empty( $dedupe_only ) ) {
					return $buffer;
				}
				// Anchor: resolve the block-library href to its actual
				// `<link>` tag range, so a non-link occurrence (JS string,
				// data attribute) can never misplace the insert.
				$library_range = $this->find_link_tag_for_href( $buffer, $library_href, 0 );
				if ( null === $library_range ) {
					return $buffer;
				}
				// Resolve each candidate href to its late `<link>` tag ranges
				// with a forward-ordered cursor, so duplicate hrefs resolve
				// to distinct tags. Every late occurrence is cut; only the
				// first per href is re-inserted, so a duplicated body tag
				// cannot clone the handle into head.
				$ranges    = array();
				$moved     = array();
				$inserted  = array();
				$all_hrefs = array_merge( array_keys( $candidates ), array_keys( $dedupe_only ) );
				foreach ( $all_hrefs as $href ) {
					$is_dedupe = isset( $dedupe_only[ $href ] );
					$offset    = $head_close;
					$guard     = 0;
					while ( $guard < 50 ) {
						++$guard;
						$range = $this->find_link_tag_for_href( $buffer, $href, $offset );
						if ( null === $range ) {
							break;
						}
						$ranges[] = $range;
						if ( ! $is_dedupe && ! isset( $inserted[ $href ] ) ) {
							$inserted[ $href ] = true;
							$moved[]           = $range[2];
						}
						$offset = $range[1] + 1;
					}
					if ( count( $ranges ) > 100 ) {
						return $buffer;
					}
				}
				if ( empty( $ranges ) ) {
					return $buffer;
				}
				// Single-pass cut + insert: sort ascending, splice once, and
				// place the moved tags directly after the library link tag.
				// The anchor must precede every late range: if a theme prints
				// block-library itself after `</head>`, the splice cursor
				// would skip late ranges before the anchor while inserting
				// moved copies (duplication), so bail out unchanged instead.
				usort(
					$ranges,
					static function ( $a, $b ) {
						if ( $a[0] === $b[0] ) {
							return 0;
						}
						return ( $a[0] < $b[0] ) ? -1 : 1;
					}
				);
				foreach ( $ranges as $range ) {
					if ( $range[0] <= $library_range[1] ) {
						return $buffer;
					}
				}
				$result  = substr( $buffer, 0, $library_range[1] + 1 );
				$result .= implode( '', $moved );
				$cursor  = $library_range[1] + 1;
				foreach ( $ranges as $range ) {
					if ( $range[0] < $cursor ) {
						continue;
					}
					$result .= substr( $buffer, $cursor, $range[0] - $cursor );
					$cursor  = $range[1] + 1;
				}
				$result .= substr( $buffer, $cursor );
				return $result;
			} catch ( \Throwable $e ) {
				unset( $e );
				return is_string( $buffer ) ? $buffer : '';
			}
		}

		/**
		 * Locate the next `<link>` tag containing an href, at/after an offset.
		 *
		 * String-offset companion to the HTML-API discovery in
		 * {@see hoist_late_block_styles()}: occurrences of the href that are
		 * not wrapped in a `<link>` tag (script strings, data attributes,
		 * comments) are skipped, so callers always resolve to a genuine
		 * stylesheet tag. Never uses regex.
		 *
		 * @since 2.3.0
		 *
		 * @param string $buffer The HTML buffer.
		 * @param string $href   The stylesheet href to locate.
		 * @param int    $offset Byte offset to search from.
		 * @return array|null Array of [start, end, tag text], or null when no `<link>` tag carries the href.
		 */
		private function find_link_tag_for_href( $buffer, $href, $offset ) {
			$pos   = (int) $offset;
			$guard = 0;
			while ( $guard < 50 ) {
				++$guard;
				$href_pos = strpos( $buffer, $href, $pos );
				if ( false === $href_pos ) {
					return null;
				}
				// Bounded backward scan: only the last 2KB before the href
				// can hold the opening `<link`; avoids copying the full
				// buffer prefix on every probe for large cache-miss HTML.
				$window_start = ( $href_pos > 2048 ) ? $href_pos - 2048 : 0;
				$window       = substr( $buffer, $window_start, $href_pos - $window_start );
				$rel_pos      = ( '' !== $window ) ? strrpos( $window, '<' ) : false;
				$tag_start    = ( false !== $rel_pos ) ? $window_start + $rel_pos : false;
				$tag_end      = strpos( $buffer, '>', $href_pos );
				if ( false === $tag_start || false === $tag_end || $tag_end <= $tag_start ) {
					return null;
				}
				$tag_text = substr( $buffer, $tag_start, $tag_end - $tag_start + 1 );
				if ( false !== stripos( $tag_text, '<link' ) ) {
					return array( $tag_start, $tag_end, $tag_text );
				}
				$pos = $href_pos + strlen( $href );
			}
			return null;
		}

		/**
		 * Whether an href is carried by a genuine `<link>` tag before `</head>`.
		 *
		 * Tag-verified head membership for {@see hoist_late_block_styles()}:
		 * a bare href substring (script string, preload, comment) before the
		 * head close does not count. Never uses regex.
		 *
		 * @since 2.3.0
		 *
		 * @param string $buffer     The HTML buffer.
		 * @param string $href       The stylesheet href to test.
		 * @param int    $head_close Byte offset of `</head>`.
		 * @return bool True when a `<link>` tag carries the href inside head.
		 */
		private function is_href_in_head_link( $buffer, $href, $head_close ) {
			$range = $this->find_link_tag_for_href( $buffer, $href, 0 );
			return ( null !== $range && $range[0] < $head_close );
		}

		/**
		 * Process the buffer (image optimisation, minification, CDN rewrite) without saving.
		 *
		 * @param string $buffer The content to be processed.
		 * @return string The processed buffer content.
		 *
		 * @since 2.0.0
		 */
		private function process_buffer_only( $buffer ) {
			// Mid-template cancel safety (issue #1386): a cancelled core
			// buffer can deliver a non-string (false/null). This private
			// helper fails open to an empty string; the public wrappers
			// (process_buffer_for_cache / process_used_css_only /
			// prioritize_lcp_in_buffer) own the $output-fallback convention
			// and restore the best-available HTML, so this path must only be
			// reached via those wrappers on the public filter chain.
			if ( ! is_string( $buffer ) ) {
				return '';
			}
			if ( '' === $buffer ) {
				return $buffer;
			}
			// Nesting balance (issue #881): the enhancement-buffer filter ran
			// alongside a legacy fallback buffer before the single-buffer
			// routing (issue #1386) retired the fallback on 6.9+. On 6.9+ only
			// the core filter path is ever registered, so the flag now only
			// guards legacy pre-6.9 double-entry (exactly-once enhancement).
			if ( self::$buffer_enhanced ) {
				return $buffer;
			}
			self::$buffer_enhanced = true;

			// Late-enqueued block styles (issue #1386) hoist first so every
			// downstream pass (minify, used-CSS, CDN) and the cached file see
			// the post-hoisting HTML with the core cascade intact.
			$buffer = $this->hoist_late_block_styles( $buffer );

			// Buffer collaborators are constructor-injected (REF-006): reuse the
			// live instances shared with Main when present, building the
			// equivalents from the resolved options on first use otherwise.
			// The lazy getters guarantee non-null collaborators, so the
			// buffer pipeline can never diverge onto a stale options
			// snapshot nor fatal on a null left by a filtered stub,
			// unserialization, or reflection.
			$image_optimisation = $this->get_image_optimisation();

			$buffer = $image_optimisation->maybe_serve_next_gen_images( $buffer );
			$buffer = $image_optimisation->add_delay_load_img( $buffer );
			$buffer = $image_optimisation->add_delay_load_backgrounds( $buffer );
			$buffer = $image_optimisation->lazy_load_videos( $buffer );
			$buffer = $image_optimisation->lazy_render_elements( $buffer );

			// Host Google Fonts locally via buffer-level interception.
			if ( ! empty( $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false ) ) {
				$buffer = $this->get_google_fonts()->process_buffer( $buffer );
			}

			// Inject metric-matched font fallback when enabled (OMGF Pro parity).
			if ( ! empty( $this->options['file_optimisation']['fontMetricFallback'] ?? false ) ) {
				$buffer = $this->get_google_fonts()->inject_metric_fallback( $buffer );
			}

			$file_opts         = $this->options['file_optimisation'] ?? array();
			$needs_minify_pass = ! empty( $file_opts['minifyHTML'] )
				|| ! empty( $file_opts['delayJS'] )
				|| ! empty( $file_opts['minifyInlineCSS'] )
				|| ! empty( $file_opts['minifyInlineJS'] );

			if ( $needs_minify_pass ) {
				$buffer = $this->minify_buffer( $buffer );
			}

				// Apply used-CSS before CDN so href matching works.
			$buffer = $this->maybe_apply_used_css( $buffer );

			// Apply CDN rewriting.
			$buffer = $this->maybe_apply_cdn( $buffer );

			return $buffer;
		}

		/**
		 * Filter callback for wp_template_enhancement_output_buffer (WP 6.9+).
		 *
		 * Processes the output buffer without saving to cache. Part of the WP 6.9
		 * template-enhancement buffer path adopted in {@see Main::setup_hooks()}:
		 * wp_template_enhancement_output_buffer (filter at priority 10) +
		 * wp_finalized_template_enhancement_output_buffer (action). Registering the
		 * finalized action automatically opts into the buffer (priority 1000 by
		 * default), which disables response streaming — TTFB increases while TTLB
		 * unchanged; see {@see Main::emit_server_timing_header()} for the intentional
		 * tradeoff (Server-Timing keeps disabled by default, emit only on cache-miss).
		 * Returns filtered output via process_buffer_only(); persistence is handled
		 * by {@see Cache::stash_cache()} / save_processed_buffer() to index.html+.gz+.br.
		 *
		 * @param string $filtered_output The filtered output from previous callbacks.
		 * @param string $output          The raw output buffer content.
		 * @return string The processed output buffer.
		 *
		 * @since 2.0.0
		 */
		public function process_buffer_for_cache( $filtered_output, $output ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
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
				if ( ! $this->is_cache_allowed_for_current_user() || $this->is_not_cacheable() ) {
					return $filtered_output;
				}

				$this->current_role_hash = $this->get_logged_in_role_hash();

				return $this->process_buffer_only( $filtered_output );
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO page cache buffer processing failed.', array( 'exception' => $e ) );
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
		 * Action callback for wp_finalized_template_enhancement_output_buffer (WP 6.9+).
		 *
		 * Stashes the processed output to the static cache files. Complements
		 * {@see Cache::process_buffer_for_cache()} on wp_template_enhancement_output_buffer
		 * (filter). Registering this finalized action opts into the template-enhancement
		 * buffer (priority 1000 by default) which disables response streaming; TTFB
		 * tradeoff is documented in {@see Main::emit_server_timing_header()} and
		 * {@see Main::setup_hooks()} Server-Timing block. Persists via
		 * save_processed_buffer() to index.html + .gz + .br.
		 *
		 * @param string $output The finalized output buffer content (alias $final).
		 * @return void
		 *
		 * @since 2.0.0
		 */
		public function stash_cache( $output ) {
			// Mid-template cancel safety (issue #1386): a cancelled core
			// buffer can deliver a non-string (false/null) to the finalized
			// action. Skipping the write degrades to uncached output —
			// never fatal. Persistence failures are best-effort by design.
			if ( ! is_string( $output ) || '' === $output ) {
				return;
			}
			try {
				if ( ! $this->is_cache_allowed_for_current_user() || $this->is_not_cacheable() ) {
					return;
				}

				$role_hash = ! empty( $this->current_role_hash ) ? $this->current_role_hash : $this->get_logged_in_role_hash();
				$file_path = $this->get_cache_file_path( 'html', $role_hash );

				$this->save_processed_buffer( $output, $file_path );
			} catch ( \Throwable $e ) {
				do_action( 'wppo_debug_log', 'WPPO page cache stash failed.', array( 'exception' => $e ) );
			}
		}

		/**
		 * Rewrite local asset URLs via CDN class (LS-410 parity).
		 *
		 * Scans img, script, link, source, and video tags and replaces attribute values that start with the site URL
		 * and contain `/wp-content/` or `/wp-includes/`. The attributes handled are `src`, `href`, `data-src`,
		 * `srcset`, and `data-srcset`. If no CDN is configured the buffer is returned unchanged.
		 *
		 * Delegates to CDN::rewrite_buffer() which handles tag attrs, srcset, inline url() and origin guards.
		 * Constant LITESPEED_BYPASS_CDN short-circuits (LSCWP cdn.cls.php:106).
		 *
		 * @param string $buffer HTML buffer.
		 * @return string The HTML with applicable asset URLs rewritten to the CDN, or the original HTML if no changes were made.
		 * @since 1.2.0
		 * @since 2.0.0 Added LITESPEED_BYPASS_CDN guard and CDN delegation.
		 */
		public function maybe_apply_cdn( string $buffer ): string {
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				return $buffer;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\CDN' ) ) {
				// CDN class owns mapping/bypass/filter gates internally but we also keep Cache::$options sync.
				return CDN::rewrite_buffer( $buffer );
			}
			// Fallback if CDN class not loaded — minimal gate.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && ! LiteSpeed_Integration::can_apply_cdn() ) {
				return $buffer;
			}
			if ( ! apply_filters( 'wppo_litespeed_can_cdn', true ) ) {
				return $buffer;
			}
			if ( has_filter( 'litespeed_can_cdn' ) && ! apply_filters( 'litespeed_can_cdn', true ) ) {
				return $buffer;
			}
			return $buffer;
		}

		/**
		 * Minify the output buffer.
		 *
		 * @param string $buffer The HTML content to be minified.
		 * @return string The minified HTML content.
		 *
		 * @since 1.0.0
		 */
		private function minify_buffer( $buffer ) {
			if ( $this->should_bypass_for_litespeed() ) {
				return $buffer;
			}
			$minifier = new Minify\HTML( $buffer, $this->options );
			$buffer   = $minifier->get_minified_html();

			return $buffer;
		}


		/**
		 * Record the DONOTCACHEPAGE decision on disk and purge stale static files for the current URL.
		 *
		 * Writes a `.wppo-no-cache` marker file next to the cached HTML so the
		 * advanced-cache.php drop-in (which boots before WordPress) can skip serving
		 * a stale static copy. Runs at most once per request. Best-effort: this only
		 * engages for pages that are actually rendered by WordPress at least once
		 * after the constant is set — a page that is already cached and never re-
		 * rendered stays stale until a cache clear, post invalidation, or the
		 * one-time version-upgrade purge removes it.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		private function maybe_mark_page_not_cacheable(): void {
			if ( $this->no_cache_marker_written ) {
				return;
			}
			$this->no_cache_marker_written = true;

			$html_file_path = $this->get_cache_file_path( 'html' );
			if ( '' === $html_file_path ) {
				return;
			}
			$marker_path = trailingslashit( dirname( $html_file_path ) ) . '.wppo-no-cache';
			if ( ! $this->is_path_contained( $marker_path ) ) {
				$this->log_traversal_probe( $this->url_path );
				return;
			}

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}

			// Marker already on disk — write and purge already ran previously.
			if ( $fs->exists( $marker_path ) ) {
				return;
			}

			if ( ! $this->prepare_cache_dir() ) {
				return;
			}

			$written = $fs->put_contents( $marker_path, (string) time(), FS_CHMOD_FILE );
			if ( ! $written ) {
				return;
			}

			// Purge any pre-existing static files for this URL so the marker takes effect immediately.
			$this->delete_cache_files( $html_file_path );
			$this->delete_role_variant_files( dirname( $html_file_path ) );
		}

		/**
		 * Whether the current request is a WooCommerce AJAX endpoint.
		 *
		 * Matches the pretty-permalink /wc-ajax/... path segment (decoded, case-insensitive) and the
		 * ?wc-ajax=... query parameter (via $_GET and the raw query string).
		 * A bare substring (e.g. /my-wc-ajax-guide/) intentionally does NOT
		 * match — only the exact path segment or parameter name bypasses
		 * the cache (issue #907 review).
		 *
		 * Shared by is_not_cacheable() and maybe_store_cache() so the
		 * storage layer refuses wc-ajax XHRs even if is_not_cacheable() is
		 * bypassed via the wppo_should_cache_request filter.
		 *
		 * @since 2.0.0
		 * @return bool True for wc-ajax requests.
		 */
		private function is_wc_ajax_request(): bool {
			$path = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $this->request_uri, PHP_URL_PATH ) ), '/' ) );
			if ( preg_match( '#(^|/)wc-ajax(/|$)#i', $path ) ) {
				return true;
			}
			if ( isset( $_GET['wc-ajax'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
				return true;
			}
			return ! empty( $_SERVER['QUERY_STRING'] ) &&
				(bool) preg_match( '/(?:^|&)(wc-ajax)(?:=|&|$)/i', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) );
		}

		/**
		 * Whether the current request targets a WooCommerce Store API route.
		 *
		 * Store API responses (`wc/store`, `wcstore`, `wp-json/wc/store*`,
		 * `wp-json/wcstore*`) are dynamic JSON and must never be cached —
		 * unconditional on the `wooSafeMode` toggle, mirroring wc-ajax.
		 * Fail-open: detection failure returns true (treated as dynamic, never cached) and
		 * the broader Woo guards still apply.
		 *
		 * @since 2.0.0
		 * @return bool True for Store API requests.
		 */
		private function is_woo_store_api_request(): bool {
			$path = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $this->request_uri, PHP_URL_PATH ) ), '/' ) );
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_request' ) ) {
				try {
					return Util::is_woo_store_api_request( $path );
				} catch ( \Throwable $e ) {
					unset( $e );
					return true;
				}
			}
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_store_api_path' ) ) {
				try {
					if ( Util::is_woo_store_api_path( $path ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Plain-permalink fallback (?rest_route=/wc/store/...) when the
				// newer request helper is unavailable (mixed-version deploys).
				try {
					$rest_route_fb = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					if ( '' !== $rest_route_fb && Util::is_woo_store_api_path( $rest_route_fb ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( (bool) preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . $path ) ) {
				return true;
			}
			$rest_route_raw = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
			if ( '' !== $rest_route_raw && (bool) preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . ltrim( $rest_route_raw, '/' ) ) ) {
				return true;
			}
			return ! empty( $_SERVER['QUERY_STRING'] ) &&
			(bool) preg_match( '#rest_route=[^&]*(?:wc/store|wcstore)#i', rawurldecode( sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) ) );
		}

		/**
		 * Whether the current request should be excluded from static cache due to WooCommerce safe mode.
		 *
		 * Safe-by-default exclusions for WooCommerce: cart/checkout/account,
		 * wc-ajax, add-to-cart, and Woo session/cart cookies. Fail-open: any
		 * detection failure treats the page as non-cacheable (never fatal). When
		 * Woo symbols are missing the conditional-function branch is skipped but
		 * URI/cookie guards remain so hardcoded cart/checkout slugs stay safe even
		 * on non-Woo installs (legacy behaviour preserved, 0 queries).
		 *
		 * @since 2.0.0
		 * @return bool True when the request is Woo-excluded (not cacheable).
		 */
		private function is_woo_excluded(): bool {
			// Store API routes are never cacheable, even when safe mode is off
			// (unconditional, mirroring wc-ajax). Checked before the toggle
			// so wooSafeMode=false cannot re-allow dynamic Store API JSON.
			try {
				if ( $this->is_woo_store_api_request() ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Feature toggle: explicit false disables safe mode; absent key = true for BC.
			// Unified on Util::is_woo_safe_mode_enabled() (absent = on,
			// malformed/non-scalar = on) so serve/store layers match Cron/Main.
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_safe_mode_enabled' ) ) {
				try {
					if ( ! Util::is_woo_safe_mode_enabled( is_array( $this->options ) ? $this->options : null ) ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return true;
				}
			} elseif ( isset( $this->options['cache_settings']['wooSafeMode'] ) && false === $this->options['cache_settings']['wooSafeMode'] ) {
				return false;
			}

			try {
				$excluded = false;

				// Woo endpoint URLs (order-pay, view-order, downloads, …) are dynamic.
				if ( function_exists( 'is_wc_endpoint_url' ) ) {
					try {
						if ( is_wc_endpoint_url() ) {
							$excluded = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$excluded = true;
					}
				}

				$woo_active = function_exists( 'is_cart' ) || function_exists( 'is_checkout' ) || function_exists( 'is_account_page' ) || function_exists( 'is_woocommerce' ) || class_exists( 'WooCommerce', false );

				if ( ! $excluded && $woo_active ) {
					if ( function_exists( 'is_cart' ) && is_cart() ) {
						$excluded = true;
					} elseif ( function_exists( 'is_checkout' ) && is_checkout() ) {
						$excluded = true;
					} elseif ( function_exists( 'is_account_page' ) && is_account_page() ) {
						$excluded = true;
					}
				}

				if ( ! $excluded ) {
					$parsed_path    = wp_parse_url( $this->request_uri, PHP_URL_PATH );
					$local_url_path = wp_normalize_path( trim( rawurldecode( (string) $parsed_path ), '/' ) );
					$matched        = false;
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_dynamic_path' ) ) {
						try {
							// Canonical list: defaults + configured custom/nested
							// Woo slugs (e.g. shop/basket). Store API already
							// handled unconditionally above.
							$matched = Util::is_woo_dynamic_path( $local_url_path );
						} catch ( \Throwable $e ) {
							unset( $e );
							$matched = true;
						}
					} else {
						$matched = (bool) preg_match( '#^/(?:cart|checkout|my-account)(?:/|$)#i', '/' . $local_url_path );
					}
					if ( $matched ) {
						$excluded = true;
					}
				}

				if ( ! $excluded ) {
					if ( ! empty( $_COOKIE['woocommerce_items_in_cart'] ) || ! empty( $_COOKIE['woocommerce_cart_hash'] ) ) {
						$excluded = true;
					}
				}

				if ( ! $excluded && ! empty( $_COOKIE ) && is_array( $_COOKIE ) ) {
					$unslashed = function_exists( 'wp_unslash' ) ? wp_unslash( $_COOKIE ) : $_COOKIE;
					foreach ( $unslashed as $k => $v ) {
						$key = (string) $k;
						if ( function_exists( 'sanitize_key' ) ) {
							$key = sanitize_key( $key );
						}
						if ( 0 === strpos( $key, 'wp_woocommerce_session_' ) && ! empty( $v ) ) {
							$excluded = true;
							break;
						}
					}
				}

				if ( ! $excluded ) {
					if ( $this->is_wc_ajax_request() ) {
						$excluded = true;
					}
				}

				if ( ! $excluded ) {
					if ( isset( $_GET['add-to-cart'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
						$excluded = true;
					} elseif ( ! empty( $_SERVER['QUERY_STRING'] ) && preg_match( '/(?:^|&)(add-to-cart)(?:=|&|$)/i', sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) ) ) {
						$excluded = true;
					}
				}

				if ( ! $excluded ) {
					// Faceted layered-nav queries (issue #1256): filter_*, query_type_*,
					// min/max_price, rating_filter, orderby, pa_*/attribute_*/gpf_*.
					// Safe-mode gated like add-to-cart (safe_mode=false keeps the
					// operator opt-out); serve-time safety without safe mode still
					// holds via the unconditional has_uncacheable_query() gate in
					// is_not_cacheable() plus the any-query store refusal below.
					try {
						if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_faceted_query' ) ) {
							$faceted_qs = (string) wp_parse_url( $this->request_uri, PHP_URL_QUERY );
							if ( '' === $faceted_qs && ! empty( $_SERVER['QUERY_STRING'] ) ) {
								$faceted_qs = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
							}
							if ( '' !== $faceted_qs && Util::is_woo_faceted_query( $faceted_qs ) ) {
								$excluded = true;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$excluded = true;
					}
				}

				if ( ! $excluded ) {
					return false;
				}

				// Allow per-URL override: wppo_woo_cacheable returning true re-allows caching.
				if ( function_exists( 'has_filter' ) && has_filter( 'wppo_woo_cacheable' ) ) {
					$cacheable = (bool) apply_filters( 'wppo_woo_cacheable', false, $this->request_uri ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Filter documented in docs/hooks.md.
					if ( $cacheable ) {
						return false;
					}
				}

				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fail-open: any detection failure treats the page as non-cacheable (never fatal).
				return true;
			}
		}

		/**
		 * Whether the current response carries a Set-Cookie header.
		 *
		 * Commerce-safety store refusal (issue #1307): a response carrying
		 * Set-Cookie (Woo session, auth, consent) is per-visitor dynamic and
		 * must never be persisted to the static file cache. The pre-boot
		 * drop-in cannot inspect response headers at serve time, so this
		 * write-path check is the enforcement point. Cheap: one
		 * function_exists plus one headers_list scan, only on commerce
		 * relevant write attempts after the early-out guards. Fail-open:
		 * any detection failure returns true (refuse the store, dynamic),
		 * never fatal.
		 *
		 * @since 2.2.0
		 * @return bool True when a Set-Cookie response header is present.
		 */
		private function has_set_cookie_response_header(): bool {
			try {
				if ( ! function_exists( 'headers_list' ) ) {
					return false;
				}
				foreach ( headers_list() as $header ) {
					if ( 0 === stripos( (string) $header, 'set-cookie:' ) ) {
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
		 * Whether the current request is an admin/editor-preview context.
		 *
		 * Unconditional static-cache bypass (issue #1097): wp-admin / login /
		 * admin-ajax paths, `is_admin()`, `is_preview()`,
		 * `is_customize_preview()`, Elementor preview mode, AJAX/REST/JSON,
		 * and builder/core preview query params via
		 * `Util::is_editor_preview_request()`. No re-allow filter by design:
		 * editor output must never be written to or served from the static
		 * cache. Fail-open: any detection failure bypasses the cache.
		 *
		 * @since 2.2.0
		 * @return bool True when the request must bypass the cache.
		 */
		private function is_editor_preview_excluded(): bool {
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_editor_preview_request' ) ) {
				try {
					return Util::is_editor_preview_request();
				} catch ( \Throwable $e ) {
					unset( $e );
					return true;
				}
			}
			// Mixed-version fallback: inline the greppable preview signals so
			// editor output still bypasses when the Util helper is unavailable.
			try {
				if ( function_exists( 'is_admin' ) ) {
					try {
						if ( is_admin() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'is_preview' ) ) {
					try {
						if ( is_preview() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'is_customize_preview' ) ) {
					try {
						if ( is_customize_preview() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				$fallback_path = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $this->request_uri, PHP_URL_PATH ) ), '/' ) );
				if ( (bool) preg_match( '#(^|/)(?:wp-admin|wp-login\.php|admin-ajax\.php)(/|$)#i', '/' . $fallback_path ) ) {
					return true;
				}
				$preview_params = class_exists( 'PerformanceOptimise\Inc\Util' ) ? Util::EDITOR_PREVIEW_PARAMS : array( 'elementor-preview', 'et_fb', 'et_pb_preview', 'vc_action', 'vc_editable', 'bricks', 'preview', 'preview_id', 'customize_changeset_uuid', 'customizer' );
				foreach ( $preview_params as $key ) {
					if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
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
		 * Check if the page is not cacheable.
		 *
		 * Note: for pages opted out via the DONOTCACHEPAGE constant this also records
		 * the decision on disk (see maybe_mark_page_not_cacheable()). This coupling is
		 * intentional: every render of an opted-out page runs through this predicate
		 * before any buffer/storage path can react, so it is the only reliable place
		 * to write the marker the drop-in checks. Such pages also skip output-buffer
		 * optimisations, matching how every other non-cacheable page behaves.
		 *
		 * @return bool
		 *
		 * @since 1.0.0
		 */
		private function is_not_cacheable(): bool {
			if ( '' === $this->cache_root_dir ) {
				return true;
			}
			if ( empty( $this->domain ) ) {
				return true;
			}
			// Host-header poisoning guard: a forged Host is served uncached via
			// the canonical fallback and never touches the cache tree.
			if ( $this->host_mismatch ) {
				return true;
			}

			// Core, WooCommerce, and third-party plugins signal dynamic pages via DONOTCACHEPAGE.
			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				$this->maybe_mark_page_not_cacheable();
				return true;
			}

			// Query-param poisoning guard (issue #1141): the cache key is
			// path-only, so a functional query (`?s=`, `?add-to-cart=`, or
			// any unknown param) must never be served the clean-URL file.
			// Tracking-only queries (`utm_*`, `gclid`, … — see
			// Util::get_cache_query_allowlist()) stay servable from the
			// clean entry; the write path below additionally refuses to
			// store ANY query-bearing response, so tracking params can
			// never overwrite the canonical file. Unconditional (runs
			// before wppo_should_cache_request) because cache poisoning is
			// a security property, not a preference. Fail-open: detection
			// failure bypasses the cache (dynamic), never fatal.
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'has_uncacheable_query' ) && Util::has_uncacheable_query() ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}

			/**
			 * Filters whether the current request should be cached.
			 *
			 * Placed after the DONOTCACHEPAGE constant check so the constant
			 * always wins even if the filter returns true. Return false to skip
			 * ob_start and cache storage.
			 *
			 * @since 2.0.0
			 *
			 * @param bool   $should_cache Whether the request should be cached. Default true.
			 * @param string $request_uri  The request URI.
			 * @param bool   $is_mobile    Whether the request is from a mobile device.
			 * @param bool   $is_logged_in Whether the user is logged in.
			 */
			$is_mobile    = function_exists( 'wp_is_mobile' ) ? wp_is_mobile() : false;
			$is_logged_in = function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : false;
			$should_cache = (bool) apply_filters( 'wppo_should_cache_request', true, $this->request_uri, $is_mobile, $is_logged_in );
			if ( ! $should_cache ) {
				return true;
			}

			$parsed_path    = wp_parse_url( $this->request_uri, PHP_URL_PATH );
			$local_url_path = wp_normalize_path( trim( rawurldecode( (string) $parsed_path ), '/' ) );

			if ( false !== strpos( $local_url_path, "\0" ) || false !== strpos( $local_url_path, '..' ) ) {
				return true;
			}

			// Exclude RSS feeds and XML sitemaps.
			if ( function_exists( 'is_feed' ) && is_feed() ) {
				return true;
			}
			if ( preg_match( '/(?:sitemap[^\/]*\.xml|wp-sitemap[^\/]*\.xml|\.xml)$/i', $local_url_path ) ) {
				return true;
			}

			// Editor/admin bypass (issue #1097): wp-admin, login, AJAX/REST,
			// Elementor/Divi/WPBakery/Bricks previews, and core previews are
			// never cacheable — unconditional, no re-allow filter, survives a
			// wppo_should_cache_request bypass above.
			try {
				if ( $this->is_editor_preview_excluded() ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}

			// WooCommerce safe mode: cart/checkout/account, wc-ajax, add-to-cart, session/cart cookies.
			// Filter wppo_woo_cacheable (guarded by has_filter) can re-allow a URL.
			// Explicit Store API guard (greppable intent, survives a future
			// wppo_should_cache_request bypass above).
			if ( $this->is_woo_store_api_request() ) {
				return true;
			}
			if ( $this->is_woo_excluded() ) {
				return true;
			}

			// Note: currency-switcher cookies (WOOCS, wmc-current-currency,
			// Aelia, ...) intentionally do NOT bypass the cache — only the
			// cart-content cookies above imply dynamic cart fragments.
			// Per-currency segmentation is a future enhancement, not new vary
			// logic in this change (see docs/hooks.md, wppo_should_cache_request).

			// ESI punch-holing: when hole active, treat as not cacheable via DONOTCACHEPAGE.
			// No-autoload probe (issue #1443): on non-LiteSpeed requests the ESI
			// class is never loaded, and should_punch_hole() is fail-closed
			// anyway, so skipping here preserves behaviour without parsing ESI.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', false ) ) {
				$needs_hole = false;
				try {
					if ( LiteSpeed_ESI::should_punch_hole( 'cart' ) || LiteSpeed_ESI::should_punch_hole( 'checkout' ) || LiteSpeed_ESI::should_punch_hole( 'account' ) || LiteSpeed_ESI::should_punch_hole( 'adminbar' ) ) {
						$needs_hole = true;
					}
				} catch ( \Throwable $e ) {
					$needs_hole = false;
				}
				if ( $needs_hole ) {
					if ( ! defined( 'DONOTCACHEPAGE' ) ) {
						define( 'DONOTCACHEPAGE', true );
					}
					$this->maybe_mark_page_not_cacheable();
					return true;
				}
			}

			$path_info = pathinfo( $local_url_path, PATHINFO_EXTENSION );
			return is_404() || ! empty( $path_info );
		}

		/**
		 * Get the cache file path based on the URL path.
		 *
		 * @param string $type       The file type (default: 'html').
		 * @param string $role_hash  Optional role hash for logged-in user cache variant.
		 * @param string $variant    Optional variant suffix (e.g. combined-CSS state) baked into the file name.
		 * @return string The cache file path.
		 *
		 * @since 1.0.0
		 */
		private function get_cache_file_path( $type = 'html', string $role_hash = '', string $variant = '' ): string {
			// All containment lives in safe_path_for_url() (single
			// choke-point): role-hash/variant/type allowlists shape the leaf
			// filename, then the choke-point enforces domain, single-decode,
			// and dual-prefix containment. Empty string means refuse.
			if ( '' !== $role_hash && ! preg_match( '/^[a-z0-9-]{1,32}$/i', $role_hash ) ) {
				$this->log_traversal_probe( $role_hash );
				return '';
			}
			if ( '' !== $variant && ! preg_match( '/^[a-z0-9-]{1,32}$/i', $variant ) ) {
				$this->log_traversal_probe( $variant );
				return '';
			}
			$suffix = $role_hash ? "-{$role_hash}" : '';
			if ( $variant ) {
				$suffix .= "-{$variant}";
			}
			if ( ! preg_match( '/^[a-z0-9]+$/i', (string) $type ) ) {
				$this->log_traversal_probe( (string) $type );
				return '';
			}
			$filename = "index{$suffix}.{$type}";
			return $this->safe_path_for_url( $this->url_path, $filename );
		}

		/**
		 * Get the cache file URL based on the URL path.
		 *
		 * @param string $type    The file type (default: 'html').
		 * @param string $variant Optional variant suffix baked into the file name.
		 * @return string The cache file URL.
		 *
		 * @since 1.0.0
		 */
		public function get_cache_file_url( $type = 'html', string $variant = '' ): string {
			if ( '' === $this->cache_root_url ) {
				return '';
			}
			// Variant/type shape the leaf filename; containment parity with
			// get_cache_file_path() comes from the same single choke-point.
			if ( '' !== $variant && ! preg_match( '/^[a-z0-9-]{1,32}$/i', $variant ) ) {
				$this->log_traversal_probe( $variant );
				return '';
			}
			if ( ! preg_match( '/^[a-z0-9]+$/i', (string) $type ) ) {
				$this->log_traversal_probe( (string) $type );
				return '';
			}
			$suffix   = $variant ? "-{$variant}" : '';
			$filename = "index{$suffix}.{$type}";
			$resolved = $this->safe_path_for_url( $this->url_path, $filename );
			if ( '' === $resolved ) {
				return '';
			}
			$relative = ( '' === $this->url_path ? $filename : "{$this->url_path}/{$filename}" );
			return "{$this->cache_root_url}/{$this->domain}/{$relative}";
		}

		/**
		 * Apply used-CSS to the buffer if the setting is enabled.
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The processed buffer.
		 *
		 * @since 1.9.0
		 */
		private function maybe_apply_used_css( string $buffer ): string {
			$used_css = new Used_CSS( $this->options );
			return $used_css->process_buffer( $buffer );
		}

		/**
		 * Prepare the cache directory for storing files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function prepare_cache_dir(): bool {
			// Funnel through the single choke-point: the probe file must
			// resolve inside the cache tree before any directory is created.
			// The directory itself is derived from the contained probe path
			// so it can never escape the root/domain tree.
			$probe = $this->safe_path_for_url( $this->url_path, 'index.html' );
			if ( '' === $probe ) {
				return false;
			}
			if ( function_exists( 'dirname' ) ) {
				$target = dirname( $probe );
			} else {
				$target = "{$this->cache_root_dir}/{$this->domain}/" . ( '' === $this->url_path ? '' : "/{$this->url_path}" );
			}
			if ( ! $this->is_path_contained( trailingslashit( $target ) ) ) {
				$this->log_traversal_probe( $this->url_path );
				return false;
			}
			if ( ! Util::prepare_cache_dir( $target ) ) {
				return false;
			}
			// Post-mkdir re-verification (TOCTOU): a symlink swapped in
			// during the iterative mkdir must not redirect subsequent
			// writes, so containment is re-checked after the directory
			// exists before any put_contents happens.
			if ( ! $this->is_path_contained( trailingslashit( $target ) ) ) {
				$this->log_traversal_probe( $this->url_path );
				return false;
			}
			return true;
		}

		/**
		 * Atomically write contents to a file via tmp+rename.
		 *
		 * Writes to a temporary sibling file in the same directory and then
		 * atomically moves it to the final path, preventing readers from
		 * observing a partially-written cache file during stampede writes.
		 * The final path must originate from {@see safe_path_for_url()}; the
		 * containment pre-check below is defense-in-depth on the resolved path.
		 *
		 * @since 2.0.0
		 * @param string $path Final file path.
		 * @param string $contents File contents.
		 * @return bool True on success.
		 */
		private function atomic_put_contents( string $path, string $contents ): bool {
			// Containment pre-check: never create a tmp sibling outside the
			// cache root/domain tree (fail-closed, no partial file).
			if ( '' === $path || ! $this->is_path_contained( $path ) ) {
				$this->log_traversal_probe( $path );
				return false;
			}
			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return false;
			}
			// Shared tmp+rename helper: unique tmp name, no non-atomic
			// direct-write fallback so interrupted writes leave nothing.
			return Util::atomic_file_put_contents( $fs, $path, $contents );
		}

		/**
		 * Save cache files with optional gzip compression.
		 *
		 * The file path must originate from {@see safe_path_for_url()} (via
		 * `get_cache_file_path()`); the containment guard below plus the
		 * `atomic_put_contents()` re-check on the base and `.gz`/`.br`
		 * siblings are defense-in-depth on resolved paths.
		 *
		 * @param string $buffer The content to save.
		 * @param string $file_path The file path for saving.
		 * @param string $type The file type (default: 'html').
		 * @return void
		 *
		 * @since 2.0.0
		 */
		private function save_cache_files( $buffer, $file_path, $type = 'html' ): void {
			if ( '' === (string) $file_path || ! $this->is_path_contained( (string) $file_path ) ) {
				$this->log_traversal_probe( (string) $file_path );
				return;
			}

			// Only evaluate the storage decision for HTML writes so the DONOTCACHEPAGE
			// side effects never fire for CSS/JS file saves.
			if ( 'html' === $type && ! $this->maybe_store_cache() ) {
				return;
			}

			if ( 'html' === $type ) {
				$current_url = Util::cached_home_url( $this->request_uri );
				$buffer      = apply_filters( 'wppo_cache_page_html', $buffer, $current_url );
			}

			$gzip_file_path = $file_path . '.gz';
			$br_file_path   = $file_path . '.br';

			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return;
			}

			// Stampede protection: atomic owner lock per file path (2-5s TTL via
			// Util::stampede_lock_ttl()). Only the lock owner writes; concurrent
			// racers skip this write instead of interleaving put_contents. The
			// owner check on release means a slow worker never deletes a
			// successor's lock. Fail-open: lock failures skip the write for this
			// request (the next request retries) — never fatal.
			$lock_key   = Util::transient_key( 'wppo_cache_write_' . md5( $file_path ) );
			$lock_owner = Util::generate_stampede_owner();
			$lock_ttl   = Util::stampede_lock_ttl();
			if ( ! Util::acquire_stampede_lock( $lock_key, $lock_owner, $lock_ttl ) ) {
				return;
			}

			try {
				$this->atomic_put_contents( $file_path, $buffer );

				if ( function_exists( 'gzencode' ) ) {
					$gzip_output = gzencode( $buffer, 9 );
					if ( false !== $gzip_output ) {
						$this->atomic_put_contents( $gzip_file_path, $gzip_output );
					}
				}

				// LS-403: Brotli .br alongside .gz — gated by enableBrotli + extension.
				if ( 'html' === $type ) {
					$use_brotli = false;
					if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_brotli_enabled' ) ) {
						$use_brotli = LiteSpeed_Integration::is_brotli_enabled();
					} else {
						$opts       = Util::get_settings();
						$enabled    = ! empty( $opts['litespeed_integration']['enableBrotli'] );
						$has_ext    = extension_loaded( 'brotli' ) || function_exists( 'brotli_compress' );
						$use_brotli = $enabled && $has_ext;
						/**
						 * Filter whether brotli generation is enabled (fallback).
						 *
						 * @since 2.0.0
						 * @param bool $use_brotli Whether brotli is enabled.
						 */
						$use_brotli = (bool) apply_filters( 'wppo_litespeed_brotli', $use_brotli );
					}
					if ( $use_brotli && function_exists( 'brotli_compress' ) ) {
						try {
							$br_output = brotli_compress( $buffer, 4, 0 ); // phpcs:ignore PHPCompatibility.FunctionUse.NewFunctions.brotli_compressFound
							if ( false !== $br_output && is_string( $br_output ) ) {
								$this->atomic_put_contents( $br_file_path, $br_output );
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}

				// A cacheable page was just written: clear any stale DONOTCACHEPAGE marker
				// so static caching resumes automatically once a plugin stops setting the
				// constant (self-healing). A later request that sets the constant again
				// re-creates the marker and purges these files.
				if ( 'html' === $type ) {
					$this->delete_cache_files( trailingslashit( dirname( $file_path ) ) . '.wppo-no-cache' );
				}
			} finally {
				Util::release_stampede_lock( $lock_key, $lock_owner );
			}

			// Bounded cache (issue #1162): warn-before-enforce size cap runs
			// after the write so the frontend is never blocked. Fail-open.
			if ( 'html' === $type ) {
				try {
					self::maybe_enforce_cache_cap();
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
		}

		/**
		 * Save processed buffer with filesystem guard (shared by legacy and WP 6.9+ paths).
		 *
		 * The file path must originate from {@see safe_path_for_url()}; the
		 * containment guard below is defense-in-depth on the resolved path.
		 *
		 * @param string $buffer   The processed buffer content.
		 * @param string $file_path The file path for saving.
		 * @return void
		 *
		 * @since 2.0.0
		 */
		private function save_processed_buffer( string $buffer, string $file_path ): void {
			if ( '' === $file_path || ! $this->is_path_contained( $file_path ) ) {
				$this->log_traversal_probe( $file_path );
				return;
			}
			if ( ! $this->get_filesystem() || ! $this->prepare_cache_dir() ) {
				return;
			}
			$this->save_cache_files( $buffer, $file_path );
		}

		/**
		 * Determine if cache storage is allowed.
		 *
		 * @return bool True if cache can be stored, false otherwise.
		 *
		 * @since 1.0.0
		 */
		private function maybe_store_cache() {
			// ESI punch-holing: skip store when hole-punched. No-autoload
			// probe (issue #1443) — see is_not_cacheable() above.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', false ) ) {
				try {
					if ( LiteSpeed_ESI::should_punch_hole( 'cart' ) || LiteSpeed_ESI::should_punch_hole( 'checkout' ) || LiteSpeed_ESI::should_punch_hole( 'account' ) || LiteSpeed_ESI::should_punch_hole( 'adminbar' ) || LiteSpeed_ESI::should_punch_hole( 'nonce' ) ) {
						if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
							$this->maybe_mark_page_not_cacheable();
						} elseif ( ! defined( 'DONOTCACHEPAGE' ) ) {
							define( 'DONOTCACHEPAGE', true );
							$this->maybe_mark_page_not_cacheable();
						}
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// LS-302: Bypass file cache when LiteSpeed owns cache — keep
			// process_buffer_only() for CDN/used-css but skip save.
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed() && ! LiteSpeed_Integration::is_wppo_cache_owner() ) {
				// For non-cacheable routes LS would otherwise cache, signal
				// no-cache so LS does not cache dynamic pages (LS-302).
				if ( $this->is_not_cacheable() ) {
					if ( has_action( 'litespeed_control_set_nocache' ) ) {
						do_action( 'litespeed_control_set_nocache', 'wppo not cacheable (ls owns)' );
					} elseif ( ! headers_sent() ) {
						header( 'X-LiteSpeed-Cache-Control: no-cache' );
					}
				}
				/**
				 * Filter whether WPPO file cache storage should be bypassed on LiteSpeed.
				 *
				 * @since 2.0.0
				 * @param bool $bypass Whether to bypass file cache.
				 */
				$bypass = (bool) apply_filters( 'wppo_litespeed_bypass_file_cache', true );
				if ( $bypass ) {
					return false;
				}
			}

			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				$this->maybe_mark_page_not_cacheable();
				return false;
			}

			if ( empty( $this->domain ) || $this->host_mismatch || $this->path_rejected || false !== strpos( $this->url_path, "\0" ) || false !== strpos( $this->url_path, '..' ) ) {
				// Prevent empty domain caching which could occur after traversal sanitation.
				// A Host mismatch or a rejected (traversal/absolute-form) path is
				// served uncached and never stored under the forged host.
				return false;
			}

			// Editor/admin storage parity (issue #1097): refuse storage for
			// wp-admin, login, AJAX/REST, and builder/core previews even if
			// is_not_cacheable() is bypassed via the wppo_should_cache_request
			// filter. Unconditional, no re-allow filter.
			try {
				if ( $this->is_editor_preview_excluded() ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}

			// Query-param poisoning guard (issue #1141): the cache key is
			// path-only, so ANY query-bearing response stored here would
			// land on the clean-URL file and poison it for later visitors
			// (e.g. `/?utm_source=x` overwriting `/index.html`). The legacy
			// `s|ver|v` gate is subsumed by the shared helper below (which
			// forces those params dynamic case-insensitively, plus any
			// unknown/functional param), so a single parse covers both.
			// Tracked requests are still servable from the clean entry
			// (read path) but never overwrite it. Fail-open: detection
			// failure refuses the store (dynamic), never fatal.
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'has_uncacheable_query' ) ) {
					if ( Util::has_uncacheable_query() ) {
						return false;
					}
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized below via wp_unslash()/sanitize_text_field() with function_exists() fallbacks.
					$raw_qs = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
					if ( function_exists( 'wp_unslash' ) ) {
						$raw_qs = wp_unslash( $raw_qs );
					}
					if ( function_exists( 'sanitize_text_field' ) ) {
						$raw_qs = sanitize_text_field( $raw_qs );
					}
					if ( '' !== trim( $raw_qs ) ) {
						return false;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}

			// Refuse storage for WooCommerce AJAX endpoints (issue #907
			// defense-in-depth: storage still refuses wc-ajax XHRs even if
			// is_not_cacheable() is bypassed via the wppo_should_cache_request
			// filter; covers pretty-permalink paths with an empty query string; @since 2.0.0).
			if ( $this->is_wc_ajax_request() ) {
				return false;
			}

			// Store API defense-in-depth (issue #962): storage refuses even if
			// is_not_cacheable() is bypassed via filter. Unconditional on
			// wooSafeMode, mirroring wc-ajax.
			if ( $this->is_woo_store_api_request() ) {
				return false;
			}

			// Faceted filter defense-in-depth (issue #1256): storage refuses
			// layered-nav query responses even if is_not_cacheable() is bypassed
			// via filter. Unconditional on safe mode — a filtered URL must never
			// be persisted over the path-only clean file.
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'is_woo_faceted_query' ) ) {
					$faceted_store_qs = (string) wp_parse_url( $this->request_uri, PHP_URL_QUERY );
					if ( '' === $faceted_store_qs && ! empty( $_SERVER['QUERY_STRING'] ) && function_exists( 'wp_unslash' ) && function_exists( 'sanitize_text_field' ) ) {
						$faceted_store_qs = sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) );
					}
					if ( '' !== $faceted_store_qs && Util::is_woo_faceted_query( $faceted_store_qs ) ) {
						return false;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}

			// Set-Cookie store refusal (issue #1307): never persist a response
			// carrying Set-Cookie (Woo session, logged-in auth, consent). The
			// pre-boot drop-in cannot see response headers at serve time, so
			// the write path is the enforcement point. Unconditional on safe
			// mode. Fail-open: detection failure refuses the store (dynamic),
			// never fatal.
			try {
				if ( $this->has_set_cookie_response_header() ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}

			// Woo safe-mode storage parity (issue #922): refuse storage for
			// cart/checkout/account, add-to-cart, and Woo session/cart cookies
			// so HTML that gains a Woo signal after the buffer gate (e.g. Woo
			// session handler populating $_COOKIE mid-request) is never
			// persisted. Honors wooSafeMode=false and wppo_woo_cacheable via
			// is_woo_excluded().
			if ( $this->is_woo_excluded() ) {
				return false;
			}

			if ( ! empty( $this->options['preload_settings']['enablePreloadCache'] ) ) {
				if ( ! empty( $this->options['preload_settings']['excludePreloadCache'] ) ) {
					$exclude_urls = Util::process_urls( $this->options['preload_settings']['excludePreloadCache'] );

					$request_uri = $this->request_uri;
					$home_path   = wp_parse_url( Util::cached_home_url(), PHP_URL_PATH ) ?? '';
					if ( $home_path && '/' !== $home_path && 0 === strpos( $request_uri, $home_path ) ) {
						$request_uri = substr( $request_uri, strlen( $home_path ) );
					}
					$current_url = Util::cached_home_url( $request_uri );

					if ( Util::is_url_excluded( $current_url, $exclude_urls ) ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Whether current page request is cacheable (public wrapper for LiteSpeed).
		 *
		 * Renamed from is_request_cacheable() (issue #905) to avoid confusion
		 * with LiteSpeed_Integration::is_request_cacheable(), which has
		 * different semantics (adds query-string + preload-exclusion gates on
		 * top of this predicate). Mirrors is_not_cacheable() for external
		 * callers (e.g. LiteSpeed header emission) without exposing private
		 * internals. Cheap — creates no I/O beyond what is_not_cacheable()
		 * already does.
		 *
		 * @since 2.0.0
		 * @return bool True if cacheable.
		 */
		public function is_page_cacheable(): bool {
			return ! $this->is_not_cacheable();
		}

		/**
		 * Invalidate dynamic static HTML cache for a specific page and global archives.
		 *
		 * @param int $page_id The page ID.
		 * @return void
		 *
		 * @since 1.0.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::invalidate_dynamic_static_html}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public function invalidate_dynamic_static_html( $page_id ): void {
			$this->invalidator()->invalidate_dynamic_static_html( $page_id );
		}

		/**
		 * Invalidate the static HTML cache for a single post URL only.
		 *
		 * Used by the per-page delay kill-switch (#1037) so toggling
		 * `_wppo_delay_disabled` takes effect on that URL without a full purge
		 * and without the home/archive fan-out of
		 * {@see invalidate_dynamic_static_html()}: only the post permalink's
		 * `index.html` (+ gzip/brotli variants, role variants, no-cache marker,
		 * and css/used-css sidecars) is deleted. Multisite-safe: per-site
		 * `get_permalink()` plus domain-based `get_file_path()`, so no
		 * cross-site leakage. Fail-open: any failure is swallowed — callers must
		 * never fatal a meta save. No new WP/PHP APIs; safe on WP 6.2+ / PHP 8.2+.
		 *
		 * Bulk callers (e.g. Elementor bulk regen, issue #1259) pass
		 * $bump_stats=false and rely on the deferred full purge — which bumps
		 * once — instead of paying 6x delete_transient + get_option +
		 * update_option per post inline.
		 *
		 * @since 2.0.0
		 * @param int  $page_id Post ID whose single URL cache must be purged.
		 * @param bool $bump_stats Whether to bump dashboard stats (6x transient
		 *                         deletes + option write). Default true.
		 * @return void
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::invalidate_single_static_html}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public function invalidate_single_static_html( int $page_id, bool $bump_stats = true ): void {
			$this->invalidator()->invalidate_single_static_html( $page_id, $bump_stats );
		}

		/**
		 * Surgically invalidate cache for a WooCommerce product, order, or coupon.
		 *
		 * Purges only the object's own permalink path (+ css/used-css sidecars)
		 * plus, for products, its product-category/tag archive paths and the
		 * shop page path. Never purges the home page, never calls
		 * clear_cache() (no full-cache wipe), and never schedules preload for
		 * Woo-excluded permalinks. Multisite-safe: per-site get_permalink() +
		 * domain-based get_file_path(), no cross-site purge.
		 *
		 * @since 2.0.0
		 * @param int    $object_id Woo object (product/order/coupon) ID.
		 * @param string $kind      Object kind: 'product', 'order', or 'coupon'.
		 * @return void
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::invalidate_woo_object}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public function invalidate_woo_object( int $object_id, string $kind ): void {
			$this->invalidator()->invalidate_woo_object( $object_id, $kind );
		}

		/**
		 * Whether a traversal probe has been logged this request.
		 *
		 * Rate-limits activity-log writes so a hostile crawler cannot flood
		 * the log table with one entry per request path probe.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $traversal_probe_logged = false;

		/**
		 * Sanitize a URL path for cache file mapping.
		 *
		 * Uses only the PHP_URL_PATH component, applies exactly one
		 * rawurldecode pass (single-decode semantics: `%252e` stays encoded
		 * on disk and is never re-decoded), then rejects null bytes, any
		 * remaining `..` segments, and Windows drive prefixes. Returns an
		 * empty string for hostile or empty input.
		 *
		 * Delegates to the shared {@see Util::sanitize_cache_url_path()}
		 * helper so every file-writing surface normalizes identically.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Added the optional $allowed_host foreign-host refusal.
		 * @param string|null $url_path Raw URL path or URL.
		 * @param string|null $allowed_host Optional canonical host; threaded to the shared helper so
		 *                                  absolute-form callers refuse foreign hosts.
		 * @return string Sanitized relative path or empty string.
		 */
		private static function sanitize_cache_url_path( ?string $url_path, ?string $allowed_host = null ): string {
			return Util::sanitize_cache_url_path( $url_path, $allowed_host );
		}

		/**
		 * Whether an absolute path stays inside the cache tree.
		 *
		 * Dual-prefix containment: the normalized path must start with both
		 * the cache root and the per-domain directory (trailing-slash aware
		 * so `wppo-evil` never prefix-matches `wppo`). Empty root or domain
		 * fails closed. Since NEXT the check is symlink-aware: the resolved
		 * target must also pass {@see Util::is_realpath_contained()} so a
		 * symlink planted inside the cache tree cannot redirect a write
		 * outside the root (CVE-2026-18051 class). On containment failure
		 * callers skip the write and serve dynamically uncached.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Added realpath symlink containment.
		 * @param string $path Absolute file or directory path.
		 * @return bool True when contained.
		 */
		private function is_path_contained( string $path ): bool {
			// Single validator: Util::validate_cache_write_path() owns the
			// lexical + realpath AND so this wrapper cannot drift from the
			// enforced path. Fail closed on any throwable (skip the write,
			// serve dynamic) while staying non-fatal.
			try {
				return Util::validate_cache_write_path( $this->cache_root_dir, $this->domain, $path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Single choke-point mapping a URL path + leaf filename to a contained absolute path.
		 *
		 * The ONLY function that maps a URL path + leaf filename to an absolute
		 * filesystem path under `wp-content/cache/wppo/{domain}/{path}/`. Every
		 * cache write and purge funnels through this gate: `get_cache_file_path()`,
		 * `get_cache_file_url()`, `get_file_path()`, and `prepare_cache_dir()`
		 * delegate here, while `atomic_put_contents()`, `save_cache_files()`,
		 * `save_processed_buffer()`, `delete_cache_files()`,
		 * `delete_no_cache_marker()`, `delete_role_variant_files()`, and the
		 * `clear_cache()` single-page branch re-check containment via
		 * {@see is_path_contained()} as defense-in-depth on already-resolved
		 * paths. Returns an empty string on any rejection; callers fail open
		 * (serve dynamic/uncached) and never touch the filesystem. `.htaccess`
		 * writers are never called from this path.
		 *
		 * Internal steps (single place): fail-closed on empty root/domain or
		 * a construction-time `path_rejected` flag; leaf-filename allowlist
		 * (`index` plus optional `-[a-z0-9-]{1,32}` suffix groups with an
		 * `[a-z0-9]+` extension, optional `.gz`/`.br` sibling suffix, plus the
		 * explicit `used-css.css` alternative; length
		 * <= 64; no `/`, `\`, NUL, or `..`); domain allowlist via
		 * `Util::normalize_cache_host()` (fail-closed on `''`); resolution via
		 * `Util::sanitize_cache_path()` (single-decode + NUL/dot-dot/drive/UNC
		 * rejection + dual-prefix containment); final `is_path_contained()`
		 * re-check; `log_traversal_probe()` on reject (skipped for the benign
		 * homepage so `''`/`'/'` never logs).
		 *
		 * @since 2.0.0
		 * @param string $url_path_or_url Raw URL path or URL.
		 * @param string $filename Leaf filename (e.g. `index.html`).
		 * @return string Contained absolute path, or '' when refused.
		 */
		private function safe_path_for_url( string $url_path_or_url, string $filename ): string {
			$raw_input = (string) $url_path_or_url;
			if ( '' === $this->cache_root_dir || '' === $this->domain ) {
				return '';
			}
			if ( $this->path_rejected ) {
				return '';
			}
			// $this->domain is already pinned canonical in __construct —
			// skip the idn_to_ascii + regex re-normalize per mapping and
			// only guard the allowlist shape here (fail-closed on '').
			if ( '' === $this->domain || false !== strpos( $this->domain, '/' ) || false !== strpos( $this->domain, '\\' ) || false !== strpos( $this->domain, '..' ) ) {
				return '';
			}
			$leaf = (string) $filename;
			if ( '' === $leaf || strlen( $leaf ) > 64 ) {
				$this->log_traversal_probe( $raw_input );
				return '';
			}
			if ( false !== strpos( $leaf, '/' ) || false !== strpos( $leaf, '\\' ) || false !== strpos( $leaf, "\0" ) || false !== strpos( $leaf, '..' ) ) {
				$this->log_traversal_probe( $raw_input );
				return '';
			}
			$is_allowed = false;
			if ( function_exists( 'preg_match' ) ) {
				$is_allowed = (bool) preg_match( '/^(?:index(?:-[a-z0-9-]{1,32})*\.[a-z0-9]+(?:\.(?:gz|br))?|used-css\.css(?:\.(?:gz|br))?)$/i', $leaf );
			} else {
				$is_allowed = ( 'index.html' === $leaf || 'used-css.css' === $leaf );
			}
			if ( ! $is_allowed ) {
				$this->log_traversal_probe( $raw_input );
				return '';
			}
			$resolved = '';
			if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'sanitize_cache_path' ) ) {
				try {
					$resolved = Util::sanitize_cache_path( $this->cache_root_dir, $this->domain, $raw_input, $leaf );
				} catch ( \Throwable $e ) {
					unset( $e );
					$resolved = '';
				}
			}
			if ( '' === $resolved ) {
				$this->log_homepage_aware_probe( $raw_input );
				return '';
			}
			if ( ! $this->is_path_contained( $resolved ) ) {
				$this->log_traversal_probe( $raw_input );
				return '';
			}
			return $resolved;
		}

		/**
		 * Log a rejected path unless it is the benign homepage.
		 *
		 * `Util::sanitize_cache_path()` returns `''` for both the benign
		 * homepage (`''`/`'/'`) and hostile inputs; only the latter is a probe.
		 *
		 * @since 2.0.0
		 * @param string $raw_input The raw input that resolved to ''.
		 * @return void
		 */
		private function log_homepage_aware_probe( string $raw_input ): void {
			$raw_component = null;
			try {
				if ( function_exists( 'wp_parse_url' ) ) {
					$raw_component = wp_parse_url( $raw_input, PHP_URL_PATH );
				} elseif ( function_exists( 'parse_url' ) ) {
					$raw_component = parse_url( $raw_input, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				} else {
					$raw_component = $raw_input;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$raw_component = $raw_input;
			}
			if ( null === $raw_component || false === $raw_component ) {
				$raw_component = $raw_input;
			}
			if ( '' !== trim( trim( (string) $raw_component ), '/' ) ) {
				$this->log_traversal_probe( $raw_input );
			}
		}

		/**
		 * Best-effort cross-request throttle check.
		 *
		 * Returns true only when the transient transport positively reports a
		 * stored value. A missing transport, a miss (false/null), or a
		 * throwing transport all count as "not throttled" so a broken
		 * throttle can never suppress the log it guards (fail-open toward
		 * logging).
		 *
		 * @since 2.2.0
		 * @param string $throttle_key Throttle transient key.
		 * @param int    $ttl          TTL in seconds when recording a fresh hit.
		 * @return bool True when a previous hit is still recorded.
		 */
		private function is_throttled( string $throttle_key, int $ttl ): bool {
			try {
				if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
					return false;
				}
				// Strict miss check: a null return (unstubbed transport
				// in tests) is a miss, not a throttle hit.
				$throttled = get_transient( $throttle_key );
				if ( false !== $throttled && null !== $throttled ) {
					return true;
				}
				try {
					set_transient( $throttle_key, 1, $ttl );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Log a blocked cache path traversal probe (once per request + throttled across requests).
		 *
		 * The once-per-request static gate alone lets an unauthenticated
		 * crawler insert one wppo_activity_logs row per request (log-table
		 * bloat / DB DoS), so a short-TTL transient gate per probe hash
		 * throttles cross-request repeats (mirroring the
		 * log_inline_budget_drift throttle). Never throws: failures degrade
		 * silently to serving uncached.
		 *
		 * @since 2.0.0
		 * @param string $raw_input The hostile input that was rejected.
		 * @return void
		 */
		private function log_traversal_probe( string $raw_input ): void {
			if ( self::$traversal_probe_logged ) {
				return;
			}
			self::$traversal_probe_logged = true;

			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					return;
				}
				// Cross-request throttle: one row per probe hash per hour.
				// Best-effort (see is_throttled()): a throwing transient
				// transport counts as a miss so the probe is still logged.
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$throttle_key = Util::transient_key( 'wppo_probe_' . md5( substr( (string) $raw_input, 0, 64 ) ) );
					$ttl          = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
					if ( $this->is_throttled( $throttle_key, $ttl ) ) {
						return;
					}
				}
				$snippet = str_replace( "\0", '', (string) $raw_input );
				if ( function_exists( 'sanitize_text_field' ) ) {
					$snippet = sanitize_text_field( $snippet );
				}
				$snippet = substr( $snippet, 0, 200 );
				$message = function_exists( '__' ) ? __( 'Blocked cache path traversal probe.', 'performance-optimisation' ) : 'Blocked cache path traversal probe.';
				if ( '' !== $snippet ) {
					$message .= ' ' . $snippet;
				}
				Log::add( $message );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get the file path for a specific page.
		 *
		 * Hardened against cache-key path traversal (CVE-2026-3129 follow-up):
		 * only the PHP_URL_PATH component is used, exactly one rawurldecode
		 * pass is applied, and null bytes plus `..` segments are rejected.
		 * The resolved path must pass dual-prefix containment before it is
		 * returned; otherwise an empty string is returned and the probe is
		 * logged so the request is served uncached.
		 *
		 * @param string|null $url_path The URL path (optional).
		 * @param string      $type The file type (default: 'html').
		 * @return string The file path.
		 *
		 * @since 1.1.1
		 */
		private function get_file_path( ?string $url_path = null, string $type = 'html' ): string {
			$raw_input = (string) $url_path;

			if ( 'used-css' === $type ) {
				$filename = 'used-css.css';
			} else {
				if ( ! preg_match( '/^[a-z0-9]+$/i', (string) $type ) ) {
					$this->log_traversal_probe( $raw_input );
					return '';
				}
				$filename = "index.{$type}";
			}

			// Funnel through the single choke-point (domain + single-decode
			// + filename allowlist + dual-prefix containment). Empty string
			// means refuse; the choke-point already logs hostile probes
			// (homepage-aware, so benign ''/'/' stays silent).
			return $this->safe_path_for_url( $raw_input, $filename );
		}

		/**
		 * Map a derived asset path to its sibling last-good fallback path.
		 *
		 * Thin wrapper over {@see Util::get_purge_fallback_path_for()} so the
		 * purge/miss path can be unit-tested via the Cache surface. Returns
		 * `''` for non CSS/JS paths and for paths that already point at a
		 * fallback file (loop guard).
		 *
		 * @param string $file_path Absolute derived-asset path.
		 * @return string Sibling fallback path, or '' when not applicable.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::get_purge_fallback_path}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public static function get_purge_fallback_path( string $file_path ): string {
			return Cache_Invalidator::get_purge_fallback_path( $file_path );
		}

		/**
		 * Retain a last-good fallback copy before a derived file is purged.
		 *
		 * Thin wrapper over {@see Util::retain_purge_fallback_file()} (the
		 * single shared implementation — see also
		 * `Used_CSS::retain_purge_fallback()`). No-op when disabled, for
		 * non CSS/JS paths, on containment failure, or when the base file
		 * is missing/empty. Never throws.
		 *
		 * @param string $file_path The derived file about to be deleted.
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::retain_purge_fallback}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function retain_purge_fallback( string $file_path ): void {
			$this->invalidator()->retain_purge_fallback( $file_path );
		}

		/**
		 * Resolve a post-purge miss under the cache path to its fallback.
		 *
		 * Returns `served=true` with a redirect status (default 302 via
		 * {@see purge_fallback_redirect_status()}) and the fallback
		 * path/URL only when every guard holds: the gate is on, the requested path is
		 * contained, it is not itself a fallback file (no loops), the base
		 * file is missing, and a non-empty fallback sibling exists. A single
		 * throttled log entry is written per fallback directory per day so a
		 * sustained miss storm cannot flood the activity log. All other cases
		 * return `served=false` with status 404 (legacy hard-404 preserved,
		 * including when the gate is off). Never throws.
		 *
		 * @param string $requested_path Absolute requested derived-asset path.
		 * @return array{served: bool, status: int, fallback_path: string, location: string} Resolution.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::get_purge_fallback_response}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public function get_purge_fallback_response( string $requested_path ): array {
			return $this->invalidator()->get_purge_fallback_response( $requested_path );
		}

		/**
		 * Resolve the redirect status for a fallback serve.
		 *
		 * Filterable via `wppo_purge_fallback_redirect_status` (default
		 * {@see PURGE_FALLBACK_REDIRECT_STATUS}); invalid values fall back
		 * to the constant. Never throws.
		 *
		 * @since 2.2.0
		 * @return int Redirect status code.
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::purge_fallback_redirect_status}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public static function purge_fallback_redirect_status(): int {
			return Cache_Invalidator::purge_fallback_redirect_status();
		}

		/**
		 * Build the headers for a fallback redirect (pure, testable).
		 *
		 * The `Cache-Control: no-store` line is deliberate: without it
		 * browsers/proxies may heuristically cache the 302 and keep
		 * redirecting to `fallback.css` after the base regenerates — a
		 * stale-serve window regeneration cannot clear client-side.
		 *
		 * @since 2.2.0
		 * @param string $location Absolute fallback URL.
		 * @param int    $status   Redirect status code.
		 * @return array{location: string, status: int, headers: string[]} Headers to send.
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::build_purge_fallback_headers}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public static function build_purge_fallback_headers( string $location, int $status ): array {
			return Cache_Invalidator::build_purge_fallback_headers( $location, $status );
		}

		/**
		 * Serve a resolved purge fallback with a redirect.
		 *
		 * Thin sender for {@see get_purge_fallback_response()}: no-op unless
		 * the resolution carries `served=true` with a non-empty location.
		 * Uses `wp_safe_redirect()` when available, `header()` otherwise,
		 * and sends `Cache-Control: no-store` so the redirect itself is
		 * never cached past regeneration. The location is stripped of
		 * literal and encoded CR/LF before sending (defense-in-depth: it is
		 * internally built, but this method is public). Never throws;
		 * terminates the request on success via `exit` (skipped when the
		 * `WPPO_PURGE_FALLBACK_NO_EXIT` test seam is set, returning true).
		 *
		 * @param array{served: bool, status: int, fallback_path: string, location: string} $response Resolver output.
		 * @return bool True when the fallback was served (or would be, under the test seam).
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::serve_purge_fallback_response}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public function serve_purge_fallback_response( array $response ): bool {
			return $this->invalidator()->serve_purge_fallback_response( $response );
		}

		/**
		 * Serve the last-good fallback for Nginx `?wppo_purge_fallback=` misses.
		 *
		 * Paired with the Nginx snippet emitted by
		 * {@see Server_Rules::get_nginx_rules()}: `try_files` falls through to
		 * `index.php?wppo_purge_fallback=$uri` on a miss under the cache path,
		 * and this handler 302s to the sibling fallback when one was retained.
		 * No-op when the gate is off, when the query var is absent, or when no
		 * fallback resolves (legacy 404 flow continues). Never throws.
		 *
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::maybe_serve_purge_fallback}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public function maybe_serve_purge_fallback(): void {
			$this->invalidator()->maybe_serve_purge_fallback();
		}

		/**
		 * Log a purge-fallback serve (global once-per-day throttle).
		 *
		 * Shares the single `wppo_purge_fallback_served` transient via
		 * {@see Util::purge_fallback_should_log()} so a sustained post-purge
		 * miss storm writes one activity-log row per day total (not one per
		 * directory). Fail-open: logging failures never affect serving.
		 *
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::log_purge_fallback}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function log_purge_fallback(): void {
			$this->invalidator()->log_purge_fallback();
		}

		/**
		 * Delete used-CSS file for a specific file path.
		 *
		 * @param string $file_path The used-css file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.9.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::delete_used_css_file}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function delete_used_css_file( string $file_path ): bool {
			return $this->invalidator()->delete_used_css_file( $file_path );
		}

		/**
		 * Delete the DONOTCACHEPAGE marker that lives beside a cached HTML file.
		 *
		 * @param string $html_file_path The HTML cache file path whose directory holds the marker.
		 * @return void
		 *
		 * @since 1.9.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::delete_no_cache_marker}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function delete_no_cache_marker( string $html_file_path ): void {
			$this->invalidator()->delete_no_cache_marker( $html_file_path );
		}

		/**
		 * Delete cache files for a specific file path.
		 *
		 * The file path must originate from {@see safe_path_for_url()}; the
		 * base plus `.gz`/`.br` sibling containment checks below are
		 * defense-in-depth on resolved paths.
		 *
		 * @param string $file_path The file path.
		 * @return bool True if successful (or not exists), false otherwise.
		 *
		 * @since 1.1.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::delete_cache_files}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function delete_cache_files( $file_path ): bool {
			return $this->invalidator()->delete_cache_files( $file_path );
		}

		/**
		 * Delete all index-{hash}.html role-variant cache files in a directory.
		 *
		 * The directory must derive from a {@see safe_path_for_url()}-resolved
		 * path (e.g. `dirname()` of a choke-point result); the directory and
		 * per-file containment checks below are defense-in-depth.
		 *
		 * @param string $dir Directory to scan.
		 * @return void
		 * @since 1.9.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::delete_role_variant_files}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function delete_role_variant_files( string $dir ): void {
			$this->invalidator()->delete_role_variant_files( $dir );
		}

		/**
		 * Clear the cache for a specific page or all pages.
		 *
		 * Also flushes any static HTML pages that speculative prerendering
		 * (speculation rules) may have requested and cached: such requests are
		 * ordinary GETs that produce the same per-URL static files (plus their
		 * `.gz` variants and role variants) served to every other visitor, so
		 * the full clear below removes the whole domain directory and the
		 * single-page clear removes the page's HTML, gzip, and role-variant
		 * copies. A stale prerendered copy is therefore never served after
		 * invalidation.
		 *
		 * @param string|null $url_path The URL path of the page for which to clear the cache. If null, all cache will be cleared.
		 * @return bool True on success, false on failure.
		 *
		 * @since 1.1.1
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::clear_cache}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		public static function clear_cache( $url_path = null ): bool {
			$instance = new self();
			return $instance->invalidator()->clear_cache( $url_path );
		}

		/**
		 * Recursively delete files under an allowlisted swap dir via PHP API.
		 *
		 * @since 2.2.0
		 * @param string $swap_dir Allowlisted directory.
		 * @return void
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::delete_swap_dir_files}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private static function delete_swap_dir_files( string $swap_dir ): void {
			Cache_Invalidator::delete_swap_dir_files( $swap_dir );
		}

		/**
		 * Fallback purge for LiteSpeed/OLS when LSCWP not active (P0).
		 *
		 * Clears allowlisted swap dirs via PHP API and emits
		 * X-LiteSpeed-Purge header. Gated by is_litespeed() and no-op when
		 * has_action('litespeed_purge_all') exists (handled via sync above).
		 * Filterable via wppo_litespeed_swap_purge.
		 *
		 * @since 2.0.0
		 * @param string|null $url_path URL path or null for all.
		 * @return void
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::purge_litespeed_swap_fallback}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private static function purge_litespeed_swap_fallback( $url_path ): void {
			Cache_Invalidator::purge_litespeed_swap_fallback( $url_path );
		}

		/**
		 * Whether a minify-cache directory may be recursively deleted.
		 *
		 * The min dirs live outside the per-domain tree, so
		 * {@see is_path_contained()} does not apply; instead the target must
		 * sit lexically under the plugin-owned min base dir
		 * (`{WP_CONTENT_DIR}/cache/wppo/min/`) and, when resolvable, its
		 * realpath must stay under the resolved base (a symlinked min dir
		 * pointing outside fails closed). Fail closed on any anomaly.
		 *
		 * @since 2.2.0
		 * @param string $dir Absolute directory candidate.
		 * @return bool True when the recursive delete may proceed.
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::is_min_dir_allowed}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function is_min_dir_allowed( string $dir ): bool {
			return $this->invalidator()->is_min_dir_allowed( $dir );
		}

		/**
		 * Whether a file path lives under the min tree (for the PHP resolver).
		 *
		 * The min tree (`{WP_CONTENT_DIR}/cache/wppo/min/...`) sits outside
		 * the per-domain prefix, so {@see is_path_contained()} cannot cover
		 * it; this routes min-tree misses through {@see is_min_dir_allowed()}
		 * on the parent dir instead. Fail-closed. Never throws.
		 *
		 * @since 2.2.0
		 * @param string $path Absolute file candidate.
		 * @return bool True when the path is min-tree-contained.
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::is_min_path}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function is_min_path( string $path ): bool {
			return $this->invalidator()->is_min_path( $path );
		}

		/**
		 * Resolve bounded purge-fallback snapshot limits.
		 *
		 * Defaults come from the `PURGE_FALLBACK_*` constants; the
		 * `wppo_purge_fallback_limits` filter may override any key
		 * (`max_files`, `max_depth`, `max_bytes`, `max_dirs`,
		 * `max_total_bytes`). Fail-closed: missing/invalid values fall back
		 * to the constant defaults, and filter values are clamped to sane
		 * ceilings so a buggy filter cannot force an OOM
		 * (`max_files × max_bytes` is otherwise buffered in memory). Result
		 * is memoized per request. Never throws.
		 *
		 * @since 2.2.0
		 * @return array{max_files: int, max_depth: int, max_bytes: int, max_dirs: int, max_total_bytes: int} Limits.
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::purge_fallback_limits}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private static function purge_fallback_limits(): array {
			return Cache_Invalidator::purge_fallback_limits();
		}

		/**
		 * Throttled log when the snapshot bounds are hit.
		 *
		 * A full wipe restores only the bounded snapshot (see
		 * {@see purge_fallback_limits()}); without a signal larger sites
		 * would silently lose fallbacks. Reuses the per-day transient
		 * throttle so a wipe storm writes a single row. Never throws.
		 *
		 * @since 2.2.0
		 * @return void
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::log_snapshot_cap}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function log_snapshot_cap(): void {
			$this->invalidator()->log_snapshot_cap();
		}

		/**
		 * Snapshot domain-tree fallbacks under a directory slated for wipe.
		 *
		 * Domain-tree entry point over {@see snapshot_purge_fallbacks_worker()}.
		 *
		 * @param string $dir Absolute domain directory about to be deleted.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::snapshot_domain_purge_fallbacks}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function snapshot_domain_purge_fallbacks( string $dir ): array {
			return $this->invalidator()->snapshot_domain_purge_fallbacks( $dir );
		}

		/**
		 * Snapshot min-tree fallbacks under a directory slated for wipe.
		 *
		 * Min-tree entry point over {@see snapshot_purge_fallbacks_worker()}.
		 *
		 * @param string $dir Absolute min directory about to be deleted.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::snapshot_min_purge_fallbacks}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function snapshot_min_purge_fallbacks( string $dir ): array {
			return $this->invalidator()->snapshot_min_purge_fallbacks( $dir );
		}

		/**
		 * Shared snapshot worker behind the domain/min entry points.
		 *
		 * @param string   $dir        Absolute directory about to be deleted.
		 * @param callable $is_allowed Containment validator: fn( string $path ): bool.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::snapshot_purge_fallbacks_worker}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function snapshot_purge_fallbacks_worker( string $dir, callable $is_allowed ): array {
			return $this->invalidator()->snapshot_purge_fallbacks_worker( $dir, $is_allowed );
		}

		/**
		 * Snapshot last-good fallback files under a directory slated for wipe.
		 *
		 * Backward-compatible wrapper over the split domain/min entry
		 * points (kept for existing callers/tests): delegates to
		 * {@see snapshot_domain_purge_fallbacks()} or
		 * {@see snapshot_min_purge_fallbacks()} based on $min_tree.
		 * Never throws.
		 *
		 * @param string $dir      Absolute directory about to be deleted.
		 * @param bool   $min_tree Whether $dir lives under the min tree.
		 * @return array<string, string> Fallback path => file contents.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::snapshot_purge_fallbacks}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function snapshot_purge_fallbacks( string $dir, bool $min_tree = false ): array {
			return $this->invalidator()->snapshot_purge_fallbacks( $dir, $min_tree );
		}

		/**
		 * Retain live bases to sibling fallbacks before a full-tree wipe.
		 *
		 * A full wipe otherwise snapshots only the previous fallback
		 * generation (or nothing on first wipe), defeating the last-good
		 * guarantee. This bounded pre-pass copies each live `.css`/`.js`
		 * base to its sibling fallback via the shared
		 * {@see Util::retain_purge_fallback_file()} helper so the snapshot
		 * that follows captures the current generation. Uses the same
		 * limits (max_dirs walk budget) and never throws.
		 *
		 * @param string   $dir        Absolute directory about to be deleted.
		 * @param callable $is_allowed Containment validator: fn( string $path ): bool.
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::retain_live_bases_for_wipe}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function retain_live_bases_for_wipe( string $dir, callable $is_allowed ): void {
			$this->invalidator()->retain_live_bases_for_wipe( $dir, $is_allowed );
		}


		/**
		 * Restore domain-tree fallbacks after a full-cache wipe.
		 *
		 * @param array<string, string> $snapshot Path => contents from {@see snapshot_purge_fallbacks()}.
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::restore_purge_fallbacks}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function restore_purge_fallbacks( array $snapshot ): void {
			$this->invalidator()->restore_purge_fallbacks( $snapshot );
		}

		/**
		 * Restore min-tree fallbacks after a full-cache wipe.
		 *
		 * @param array<string, string> $snapshot Path => contents from {@see snapshot_purge_fallbacks()}.
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::restore_min_fallbacks}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function restore_min_fallbacks( array $snapshot ): void {
			$this->invalidator()->restore_min_fallbacks( $snapshot );
		}

		/**
		 * Shared restore worker behind the domain/min entry points.
		 *
		 * Recreates parent directories via {@see Util::prepare_cache_dir()}
		 * and rewrites each captured fallback. Skips entries that fail the
		 * basename allowlist or the caller-supplied containment check so a
		 * snapshot can never plant files outside its tree. Never throws.
		 *
		 * @param array<string, string> $snapshot   Path => contents.
		 * @param callable              $is_allowed Containment validator: fn( string $path ): bool.
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::restore_snapshot}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function restore_snapshot( array $snapshot, callable $is_allowed ): void {
			$this->invalidator()->restore_snapshot( $snapshot, $is_allowed );
		}

		/**
		 * Stage a snapshot to a temp dir outside the wipe tree.
		 *
		 * The in-memory snapshot alone is lost on a fatal/OOM/timeout
		 * between `delete()` and restore — exactly the outage the fallback
		 * exists to prevent. Spilling each entry to a temp file before the
		 * delete means the bytes survive the wipe even if this request
		 * dies (a later wipe restores from its own fresh snapshot; stale
		 * temp files are always cleaned up after a successful restore).
		 * Returns original-path => temp-path. Never throws.
		 *
		 * @param array<string, string> $snapshot Path => contents.
		 * @return array<string, string> Original path => staged temp path.
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::stage_snapshot_to_temp}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function stage_snapshot_to_temp( array $snapshot ): array {
			return $this->invalidator()->stage_snapshot_to_temp( $snapshot );
		}

		/**
		 * Restore staged temp files back to their original paths.
		 *
		 * Reads each temp file (bounded by the snapshot limits) and
		 * delegates to {@see restore_snapshot()} with the caller-supplied
		 * validator, then removes the staging dir. Callers fall back to
		 * the in-memory snapshot when staging produced nothing. Never
		 * throws.
		 *
		 * @param array<string, string> $staged     Original path => staged temp path.
		 * @param callable              $is_allowed Containment validator: fn( string $path ): bool.
		 * @return void
		 *
		 * @since 2.2.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::restore_staged_snapshot}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function restore_staged_snapshot( array $staged, callable $is_allowed ): void {
			$this->invalidator()->restore_staged_snapshot( $staged, $is_allowed );
		}

		/**
		 * Delete all cache files.
		 *
		 * @return bool True if successful, false otherwise.
		 *
		 * @since 1.0.0
		 * Facade proxy (ARCH-007): logic lives in {@see Cache_Invalidator::delete_all_cache_files}.
		 * @since 2.4.0 Proxied to Cache_Invalidator (ARCH-007).
		 */
		private function delete_all_cache_files(): bool {
			return $this->invalidator()->delete_all_cache_files();
		}

		/**
		 * Get the size of the cache.
		 *
		 * @return string
		 *
		 * @since 1.0.0
		 */
		public static function get_cache_size(): string {
			$stats = self::get_cache_stats();

			if ( ! isset( $stats['size'] ) || ! is_string( $stats['size'] ) ) {
				return (string) ( $stats['size'] ?? '' );
			}

			$cache_dir = $stats['cache_dir'] ?? '';
			$instance  = new self();

			if ( ! $instance->get_filesystem() ) {
				return '' === $cache_dir ? __( 'Unable to initialize filesystem.', 'performance-optimisation' ) : $stats['size'];
			}

			if ( '' === $cache_dir || ! $instance->filesystem->is_dir( $cache_dir ) ) {
				return __( 'Cache directory does not exist.', 'performance-optimisation' );
			}

			return $stats['size'];
		}

		/**
		 * Get detailed cache statistics.
		 *
		 * Returns size, cached page count, last-cleared timestamp, and cache directory path.
		 * Size and count are cached atomically in a single transient (wppo_cache_stats) to avoid
		 * race conditions where one field is refreshed and the other remains stale.
		 *
		 * @since 2.0.0
		 * @return array{size: string, cached_pages: int, last_cleared: string, cache_dir: string}
		 */
		public static function get_cache_stats(): array {
			$instance = new self();
			$stats    = array(
				'size'         => __( 'N/A', 'performance-optimisation' ),
				'cached_pages' => 0,
				'last_cleared' => '',
				'cache_dir'    => '',
			);

			if ( ! $instance->get_filesystem() ) {
				return $stats;
			}

			$cache_dir          = "{$instance->cache_root_dir}/{$instance->domain}";
			$stats['cache_dir'] = $cache_dir;

			if ( ! $instance->filesystem->is_dir( $cache_dir ) ) {
				return $stats;
			}

			// Salted object-cache layer (WP 6.9+, issue #882): the salt is the
			// `wppo_cache_last_cleared` option VALUE — bump_stats_cache() bumps
			// it on every stats mutation, so salted entries invalidate together
			// with the transient (issue #894 follow-up: pass the value, not the
			// key, or the bump never reaches the comparison).
			$stats_key = Util::transient_key( 'wppo_cache_stats' );
			// Salted layer requires a persistent object cache; the transient
			// fallback keeps the stats across requests otherwise (issue #882 review).
			if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				$cached_stats = wp_cache_get_salted( 'wppo_cache_stats', 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ) );
				// Salted eviction (TTL) without a bump: the unified transient
				// written by store_cache_stats() is still fresh — reuse it
				// instead of rescanning the directory (issue #882 review).
				if ( false === $cached_stats ) {
					$cached_stats = get_transient( $stats_key );
				}
			} else {
				$cached_stats = get_transient( $stats_key );
			}
			if ( is_array( $cached_stats ) && isset( $cached_stats['size'], $cached_stats['count'] ) ) {
				$stats['size']         = (string) $cached_stats['size'];
				$stats['cached_pages'] = (int) $cached_stats['count'];
				$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );
				return $stats;
			}

			// Cache miss: compute size and page count in a single recursive
			// walk so large caches pay one filesystem enumeration, not two.
			// Stampede guard (issue #1101): concurrent misses collapse toward one
			// walk; losers re-read the peer's fresh value, then the 24h stale
			// copy, and only then return N/A without walking. Honors the
			// operator opt-out (is_stampede_guard_enabled()): when disabled the
			// walk runs unguarded. Fail-open: the next request retries.
			$stale_stats_key = Util::stampede_stale_key( $stats_key );
			$guard_enabled   = true;
			try {
				$guard_enabled = Util::is_stampede_guard_enabled();
			} catch ( \Throwable $e ) {
				unset( $e );
				$guard_enabled = true;
			}
			$read_fresh_stats  = static function () use ( $stats_key ): mixed {
				try {
					if ( function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
						$hit = wp_cache_get_salted( 'wppo_cache_stats', 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ) );
						if ( is_array( $hit ) && isset( $hit['size'], $hit['count'] ) ) {
							return $hit;
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				try {
					return get_transient( $stats_key );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			};
			$write_stale_stats = static function ( array $unified ) use ( $stale_stats_key ): void {
				try {
					$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
					set_transient( $stale_stats_key, $unified, $ttl );
					Util::register_transient_index_key( $stale_stats_key, $ttl );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			};
			$stats_lock        = Util::transient_key( 'wppo_stampede_' . md5( $stats_key ) );
			$stats_owner       = Util::generate_stampede_owner();
			$stats_locked      = true;
			if ( $guard_enabled ) {
				$stats_locked = Util::acquire_stampede_lock( $stats_lock, $stats_owner, Util::stampede_lock_ttl() );
			}
			if ( ! $stats_locked ) {
				$recheck = $read_fresh_stats();
				if ( is_array( $recheck ) && isset( $recheck['size'], $recheck['count'] ) ) {
					$stats['size']         = (string) $recheck['size'];
					$stats['cached_pages'] = (int) $recheck['count'];
				} else {
					try {
						$stale_hit = get_transient( $stale_stats_key );
					} catch ( \Throwable $e ) {
						unset( $e );
						$stale_hit = false;
					}
					if ( is_array( $stale_hit ) && isset( $stale_hit['size'], $stale_hit['count'] ) ) {
						$stats['size']         = (string) $stale_hit['size'];
						$stats['cached_pages'] = (int) $stale_hit['count'];
					}
				}
				$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );
				return $stats;
			}
			try {
				$dir_stats             = $instance->calculate_directory_stats( $cache_dir );
				$total_size            = $dir_stats['size'];
				$stats['size']         = size_format( $total_size );
				$stats['cached_pages'] = $dir_stats['count'];
				$unified               = array(
					'size'  => $stats['size'],
					'count' => $stats['cached_pages'],
				);
				self::store_cache_stats( $unified, $stats_key );
				$write_stale_stats( $unified );

				$stats['last_cleared'] = get_option( 'wppo_cache_last_cleared_time', '' );

				return $stats;
			} finally {
				if ( $guard_enabled ) {
					Util::release_stampede_lock( $stats_lock, $stats_owner );
				}
			}
		}

		/**
		 * Store the unified cache-stats payload in the salted object cache
		 * (WP 6.9+) and the transient fallback.
		 *
		 * The salted entry shares the `wppo_cache_last_cleared` salt with
		 * {@see bump_stats_cache()} so any stats mutation invalidates it
		 * immediately (issue #882). The transient write is kept for cores
		 * without the salted cache family and for BC with external consumers.
		 *
		 * @since 2.0.0
		 *
		 * @param array<string,mixed> $unified   Unified stats payload.
		 * @param string              $stats_key Transient key (multisite-prefixed).
		 * @return void
		 */
		private static function store_cache_stats( array $unified, string $stats_key ): void {
			if ( function_exists( 'wp_cache_set_salted' ) && function_exists( 'wp_cache_get_salted' ) && function_exists( 'wp_using_ext_object_cache' ) && wp_using_ext_object_cache() ) {
				wp_cache_set_salted( 'wppo_cache_stats', $unified, 'wppo', Util::cache_salt( 'wppo_cache_last_cleared' ), 15 * MINUTE_IN_SECONDS );
			}
			set_transient( $stats_key, $unified, 15 * MINUTE_IN_SECONDS );
		}

		/**
		 * List direct children of a cache directory via the WP filesystem.
		 *
		 * Single shared `$fs->dirlist()` enumeration point for
		 * {@see calculate_directory_stats()} and
		 * {@see collect_cache_entries_by_age()} so cap accounting and
		 * oldest-entry eviction can never drift apart.
		 *
		 * @since 2.2.0
		 * @param string $directory Directory path.
		 * @return array|null Dirlist entries, or null when unavailable.
		 */
		private function list_cache_children( string $directory ): ?array {
			$fs = $this->get_filesystem();
			if ( ! $fs ) {
				return null;
			}
			$files = $fs->dirlist( $directory );
			if ( ! $files || ! is_array( $files ) ) {
				return null;
			}
			return $files;
		}

		/**
		 * Calculate directory size and cached-page count in a single walk.
		 *
		 * Single recursive `$fs->dirlist()` traversal returning both
		 * aggregates so callers do not enumerate large static caches twice.
		 * Reuses the `size` already reported by `dirlist()` when available
		 * instead of issuing a second `size()` stat per file.
		 *
		 * @param string $directory The path to the directory to scan.
		 * @param int    $depth     Recursion depth guard.
		 * @return array{size:int,count:int} Total bytes and index.html count.
		 *
		 * @since 2.0.0
		 */
		private function calculate_directory_stats( string $directory, int $depth = 0 ): array {
			$empty = array(
				'size'  => 0,
				'count' => 0,
			);
			// Guard against unbounded recursion on very large caches (10k+ pages).
			if ( $depth > 20 ) {
				if ( ! self::$depth_warning_logged && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					self::$depth_warning_logged = true;
					// error_log (not Log::add()) is intentional: this runs during size
					// stats computation where DB writes are undesirable, and filesystem
					// anomalies (symlink loops) are server-ops signal. Fires once per
					// request, strictly WP_DEBUG-gated.
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO: calculate_directory_stats depth cap (20) hit at ' . $directory . ' — stats may be under-reported due to deep nesting or symlink loop.' );
				}
				return $empty;
			}
			$fs = $this->get_filesystem();

			if ( ! $fs ) {
				return $empty;
			}

			$files = $this->list_cache_children( $directory );

			if ( null === $files ) {
				return $empty;
			}

			$size  = 0;
			$count = 0;
			foreach ( $files as $file ) {
				$file_path = trailingslashit( $directory ) . $file['name'];
				if ( 'd' === $file['type'] ) {
					$child  = $this->calculate_directory_stats( $file_path, $depth + 1 );
					$size  += $child['size'];
					$count += $child['count'];
					continue;
				}
				if ( isset( $file['size'] ) && is_numeric( $file['size'] ) && (int) $file['size'] >= 0 ) {
					$size += (int) $file['size'];
				} else {
					$size += (int) $fs->size( $file_path );
				}
				if ( 'index.html' === $file['name'] ) {
					++$count;
				}
			}

			return array(
				'size'  => $size,
				'count' => $count,
			);
		}

		/**
		 * Calculate the size of a directory.
		 *
		 * @param string $directory The path to the directory whose size is to be calculated.
		 * @param int    $depth     Recursion depth guard.
		 * @return int The total size of the directory in bytes.
		 *
		 * @since 1.0.0
		 */
		private function calculate_directory_size( string $directory, int $depth = 0 ): int {
			$stats = $this->calculate_directory_stats( $directory, $depth );
			return $stats['size'];
		}

		/**
		 * Recursively count cached pages by counting index.html files in the cache directory.
		 *
		 * @param string $directory The directory to scan.
		 * @param int    $depth     Recursion depth guard.
		 * @return int Number of index.html files found.
		 *
		 * @since 1.9.0
		 */
		private function count_cached_pages( string $directory, int $depth = 0 ): int {
			$stats = $this->calculate_directory_stats( $directory, $depth );
			return $stats['count'];
		}

		/**
		 * Read the bounded-cache cap settings with fail-safe defaults.
		 *
		 * Additive `cache_settings` keys (issue #1162): `cacheMaxSizeMB`
		 * (default 512), `cacheSizeWarnRatio` (default 0.8),
		 * `cacheSizeEnforce` (default true). Disk-safe slice (issue #1428):
		 * `cacheMaxFiles` (default 5000, clamp 100-100000) and
		 * `cacheRandomizedQueryGuard` (default true). Missing or malformed stored
		 * values fall back to defaults; enforcement never blocks the
		 * frontend — the cap only warns first, then evicts oldest entries.
		 *
		 * @since 2.2.0
		 * @since 2.3.0 Disk-safe slice (issue #1428): `max_files`/`randomized_guard` keys.
		 * @return array{max_mb:int,warn_ratio:float,enforce:bool,max_files:int,randomized_guard:bool}
		 */
		public static function get_cache_cap_settings(): array {
			$defaults = array(
				'max_mb'           => 512,
				'warn_ratio'       => 0.8,
				'enforce'          => true,
				'max_files'        => 5000,
				'randomized_guard' => true,
			);
			try {
				$settings = array();
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$all      = Util::get_settings();
					$settings = isset( $all['cache_settings'] ) && is_array( $all['cache_settings'] ) ? $all['cache_settings'] : array();
				}
				if ( isset( $settings['cacheMaxSizeMB'] ) && is_numeric( $settings['cacheMaxSizeMB'] ) ) {
					$max_mb = (int) $settings['cacheMaxSizeMB'];
					if ( $max_mb > 0 && $max_mb <= 10240 ) {
						$defaults['max_mb'] = $max_mb;
					}
				}
				if ( isset( $settings['cacheSizeWarnRatio'] ) && is_numeric( $settings['cacheSizeWarnRatio'] ) ) {
					$ratio = (float) $settings['cacheSizeWarnRatio'];
					if ( $ratio > 0 && $ratio < 1 ) {
						$defaults['warn_ratio'] = $ratio;
					}
				}
				if ( array_key_exists( 'cacheSizeEnforce', $settings ) ) {
					$parsed = filter_var( $settings['cacheSizeEnforce'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $parsed ) {
						$defaults['enforce'] = $parsed;
					}
				}
				if ( isset( $settings['cacheMaxFiles'] ) && is_numeric( $settings['cacheMaxFiles'] ) ) {
					$max_files             = (int) $settings['cacheMaxFiles'];
					$defaults['max_files'] = max( 100, min( 100000, $max_files ) );
				}
				if ( array_key_exists( 'cacheRandomizedQueryGuard', $settings ) ) {
					$parsed = filter_var( $settings['cacheRandomizedQueryGuard'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( null !== $parsed ) {
						$defaults['randomized_guard'] = $parsed;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $defaults;
		}

		/**
		 * Total static-cache bytes + file count in a single directory walk.
		 *
		 * Shared single-walk helper behind {@see get_cache_size_bytes()},
		 * {@see get_cache_file_count()}, and {@see get_cache_cap_status()}
		 * so status paths enumerate large caches once instead of once per
		 * dimension. Fail-open: returns zeros when the filesystem or
		 * directory is unavailable.
		 *
		 * @since 2.3.0
		 * @return array{bytes:int,files:int} Bytes used and file count.
		 */
		public static function get_cache_bytes_and_files(): array {
			try {
				$instance = new self();
				if ( ! $instance->get_filesystem() ) {
					return array(
						'bytes' => 0,
						'files' => 0,
					);
				}
				$dir = trailingslashit( $instance->cache_root_dir );
				if ( '' !== $instance->domain ) {
					$dir .= $instance->domain;
				}
				if ( ! $instance->filesystem->is_dir( $dir ) ) {
					return array(
						'bytes' => 0,
						'files' => 0,
					);
				}
				$stats = $instance->calculate_directory_stats( $dir );
				return array(
					'bytes' => max( 0, (int) ( $stats['size'] ?? 0 ) ),
					'files' => max( 0, (int) ( $stats['count'] ?? 0 ) ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'bytes' => 0,
					'files' => 0,
				);
			}
		}

		/**
		 * Total static-cache size in bytes for the current domain.
		 *
		 * Fail-open: returns 0 when the filesystem or directory is
		 * unavailable. Uses the single-walk {@see calculate_directory_stats()}
		 * helper so size accounting matches the dashboard stats.
		 *
		 * @since 2.2.0
		 * @return int Bytes used, or 0 on failure.
		 */
		public static function get_cache_size_bytes(): int {
			$stats = self::get_cache_bytes_and_files();
			return max( 0, (int) ( $stats['bytes'] ?? 0 ) );
		}

		/**
		 * Total cached-page file count for the current domain.
		 *
		 * Fail-open: returns 0 when the filesystem or directory is
		 * unavailable. Shares the single-walk {@see calculate_directory_stats()}
		 * enumeration with {@see get_cache_size_bytes()} so cap accounting
		 * and eviction never drift.
		 *
		 * @since 2.3.0
		 * @return int File count, or 0 on failure.
		 */
		public static function get_cache_file_count(): int {
			$stats = self::get_cache_bytes_and_files();
			return max( 0, (int) ( $stats['files'] ?? 0 ) );
		}

		/**
		 * Whether an asset URL carries a randomized per-request query value.
		 *
		 * Matches `?ver=<timestamp|uniqid|rand>`-style churn that would
		 * regenerate combined output on every request and defeat the
		 * file-count cap. Stable content hashes (`md5` 32 / `sha1` 40 /
		 * `sha256` 64 hex chars) are deterministic per file and stay
		 * combinable. Fail-open: any parse failure returns false.
		 * Filterable via `wppo_exclude_randomized_from_combine`.
		 *
		 * @since 2.3.0
		 * @param string $src Asset src URL.
		 * @return bool True when the query looks randomized.
		 */
		public static function is_randomized_query_asset( string $src ): bool {
			try {
				$src = trim( $src );
				if ( '' === $src ) {
					return false;
				}
				$query = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$parts = wp_parse_url( $src, PHP_URL_QUERY );
					$query = is_string( $parts ) ? $parts : '';
				} elseif ( function_exists( 'parse_url' ) ) {
					$parts = parse_url( $src, PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
					$query = is_string( $parts ) ? $parts : '';
				}
				if ( '' === $query ) {
					return false;
				}
				$randomized_keys = array( 'ver', 'version', 'v', 't', 'ts', 'timestamp', 'time', 'rand', 'random', 'nonce', '_' );
				$pairs           = preg_split( '/[&;]/', (string) $query );
				if ( ! is_array( $pairs ) ) {
					$pairs = explode( '&', (string) $query );
				}
				foreach ( $pairs as $pair ) {
					$kv    = explode( '=', $pair, 2 );
					$key   = strtolower( trim( (string) ( $kv[0] ?? '' ) ) );
					$value = trim( (string) ( $kv[1] ?? '' ) );
					if ( '' === $value || ! in_array( $key, $randomized_keys, true ) ) {
						continue;
					}
					$decoded = function_exists( 'urldecode' ) ? urldecode( $value ) : $value;
					// Long digit runs (epoch timestamps, 10+ digits so YYYYMMDD
					// date versions stay combinable), long hex (uniqid-style
					// churn), or mixed alnum tokens.
					if ( 1 === preg_match( '/^\d{10,}$/', $decoded ) ) {
						return true;
					}
					if ( 1 === preg_match( '/^[0-9a-f]{10,}$/i', $decoded ) ) {
						// Stable content hashes (md5 32 / sha1 40 / sha256 64)
						// are deterministic per file content, not per-request
						// churn, so they stay combinable. Non-canonical hex
						// lengths can still opt out via the
						// wppo_exclude_randomized_from_combine filter.
						$hex_len = strlen( $decoded );
						if ( 32 === $hex_len || 40 === $hex_len || 64 === $hex_len ) {
							continue;
						}
						return true;
					}
					if ( 1 === preg_match( '/^[0-9a-z]{12,}$/i', $decoded ) && 1 === preg_match( '/[0-9]/', $decoded ) && 1 === preg_match( '/[a-z]/i', $decoded ) ) {
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
		 * Warn-before-enforce cap status for the current domain cache.
		 *
		 * States: `ok` (under warn threshold), `warn` (over warn threshold
		 * but under cap, or over cap with enforcement off), `over` (over cap
		 * with enforcement on). Never fatal; all failures report `ok`.
		 * Both cap dimensions (bytes + file count) feed the state; either
		 * dimension can push `ok` to `warn`/`over`.
		 *
		 * @since 2.2.0
		 * @since 2.3.0 File-count dimension (issue #1428): `files`/`cap_files`/`warn_files` fields.
		 * @return array{bytes:int,cap_bytes:int,warn_bytes:int,state:string,enforce:bool,max_mb:int,files:int,cap_files:int,warn_files:int}
		 */
		public static function get_cache_cap_status(): array {
			$cap        = self::get_cache_cap_settings();
			$cap_bytes  = $cap['max_mb'] * 1024 * 1024;
			$warn_bytes = (int) ( $cap_bytes * $cap['warn_ratio'] );
			$cap_files  = (int) $cap['max_files'];
			$warn_files = (int) ( $cap_files * $cap['warn_ratio'] );
			$status     = array(
				'bytes'      => 0,
				'cap_bytes'  => $cap_bytes,
				'warn_bytes' => $warn_bytes,
				'state'      => 'ok',
				'enforce'    => $cap['enforce'],
				'max_mb'     => $cap['max_mb'],
				'files'      => 0,
				'cap_files'  => $cap_files,
				'warn_files' => $warn_files,
			);
			try {
				// Single directory walk for both dimensions so accounting
				// and eviction never drift on large caches.
				$both            = self::get_cache_bytes_and_files();
				$bytes           = (int) ( $both['bytes'] ?? 0 );
				$files           = (int) ( $both['files'] ?? 0 );
				$status['bytes'] = $bytes;
				$status['files'] = $files;
				$over_bytes      = $bytes >= $cap_bytes;
				$over_files      = $files >= $cap_files;
				if ( $over_bytes || $over_files ) {
					$status['state'] = $cap['enforce'] ? 'over' : 'warn';
				} elseif ( $bytes >= $warn_bytes || $files >= $warn_files ) {
					$status['state'] = 'warn';
				}
				// Surface a persisted warning flag so the SPA can render it
				// without re-walking the directory on every admin request.
				// Self-healing: when the fresh walk reports `ok`, any stale
				// flag left from an earlier breach is deleted immediately so
				// the SPA stops warning on recovery instead of lingering
				// until the 12h TTL expires.
				$warn_key = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$warn_key = Util::transient_key( 'wppo_cache_size_warning' );
				}
				if ( '' !== $warn_key && function_exists( 'get_transient' ) ) {
					$flag = get_transient( $warn_key );
					if ( 'warn' === $status['state'] || 'over' === $status['state'] ) {
						$status['warning'] = true;
					} elseif ( false !== $flag ) {
						if ( function_exists( 'delete_transient' ) ) {
							try {
								delete_transient( $warn_key );
							} catch ( \Throwable $e ) {
								unset( $e );
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $status;
		}

		/**
		 * Warn-before-enforce size+count cap check after a cache write.
		 *
		 * Throttled to at most one directory walk per 5 minutes via a
		 * transient lock so frontend writes stay cheap. When usage passes
		 * either warn threshold (bytes or file count) a warning transient
		 * is set (honest UI signal + admin alert); when usage passes
		 * either cap and enforcement is on, the oldest entries are evicted
		 * until back under both caps. Never blocks the frontend: every
		 * failure path returns silently.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function maybe_enforce_cache_cap(): void {
			try {
				if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
					return;
				}
				$lock_key = '';
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					$lock_key = Util::transient_key( 'wppo_cache_cap_check_lock' );
				} else {
					return;
				}
				if ( false !== get_transient( $lock_key ) ) {
					return;
				}
				$lock_ttl = defined( 'MINUTE_IN_SECONDS' ) ? 5 * MINUTE_IN_SECONDS : 300;
				set_transient( $lock_key, 1, $lock_ttl );

				$status   = self::get_cache_cap_status();
				$bytes    = (int) $status['bytes'];
				$files    = (int) ( $status['files'] ?? 0 );
				$warn_key = Util::transient_key( 'wppo_cache_size_warning' );
				$warned   = $bytes >= (int) $status['warn_bytes'] || $files >= (int) ( $status['warn_files'] ?? PHP_INT_MAX );
				if ( $warned ) {
					$warn_ttl = defined( 'HOUR_IN_SECONDS' ) ? 12 * HOUR_IN_SECONDS : 43200;
					set_transient( $warn_key, 1, $warn_ttl );
				} else {
					if ( function_exists( 'delete_transient' ) ) {
						delete_transient( $warn_key );
					}
					return;
				}
				if ( $bytes < (int) $status['cap_bytes'] && $files < (int) ( $status['cap_files'] ?? PHP_INT_MAX ) ) {
					return;
				}
				if ( empty( $status['enforce'] ) ) {
					return;
				}
				$evicted = false;
				$to_free = $bytes - (int) $status['cap_bytes'];
				if ( $to_free > 0 ) {
					self::evict_oldest_cache_entries( $to_free );
					$evicted = true;
					// Re-read the file count after byte eviction so the
					// count phase below budgets against post-eviction
					// state instead of over-evicting on a stale value.
					// Fail-open: a 0 re-read only defers count eviction
					// to the next throttled run.
					$files = self::get_cache_file_count();
				}
				$cap_files = (int) ( $status['cap_files'] ?? 0 );
				if ( $cap_files > 0 && $files >= $cap_files ) {
					self::evict_oldest_cache_files_by_count( $files - $cap_files + 1 );
					$evicted = true;
				}
				if ( $evicted ) {
					self::bump_stats_cache();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Evict oldest cached pages until at least the given bytes are freed.
		 *
		 * Deletes `index.html` plus its `.gz`/`.br` siblings, oldest mtime
		 * first, bounded to 2000 entries per run so a single request cannot
		 * stall on a massive cache. Fail-open: filesystem failures stop the
		 * walk silently.
		 *
		 * @since 2.2.0
		 * @param int $bytes_to_free Minimum bytes to reclaim.
		 * @return int Bytes actually freed (best effort).
		 */
		public static function evict_oldest_cache_entries( int $bytes_to_free ): int {
			$freed = 0;
			try {
				$instance = new self();
				if ( ! $instance->get_filesystem() ) {
					return 0;
				}
				$dir = trailingslashit( $instance->cache_root_dir );
				if ( '' !== $instance->domain ) {
					$dir .= $instance->domain;
				}
				$entries = $instance->collect_cache_entries_by_age( $dir );
				if ( empty( $entries ) ) {
					return 0;
				}
				usort(
					$entries,
					static function ( $a, $b ) {
						return ( (int) ( $a['mtime'] ?? 0 ) ) <=> ( (int) ( $b['mtime'] ?? 0 ) );
					}
				);
				$budget = min( count( $entries ), 2000 );
				for ( $i = 0; $i < $budget && $freed < $bytes_to_free; ++$i ) {
					$file = (string) ( $entries[ $i ]['path'] ?? '' );
					if ( '' === $file || ! $instance->is_path_contained( $file ) ) {
						continue;
					}
					$size = (int) ( $entries[ $i ]['size'] ?? 0 );
					if ( $instance->delete_cache_files( $file ) ) {
						$freed += $size;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return max( 0, $freed );
		}

		/**
		 * Evict oldest cached pages until the file count drops by the given amount.
		 *
		 * Oldest-mtime-first via the shared {@see collect_cache_entries_by_age()}
		 * enumeration so byte-cap and file-count-cap eviction can never drift.
		 * Bounded to 2000 deletions per run; large overshoots converge over
		 * multiple throttled runs (see {@see maybe_enforce_cache_cap()}).
		 * Fail-open: filesystem failures stop silently.
		 *
		 * @since 2.3.0
		 * @param int $files_to_free Minimum entries to remove.
		 * @return int Entries actually removed (best effort).
		 */
		public static function evict_oldest_cache_files_by_count( int $files_to_free ): int {
			$removed = 0;
			try {
				if ( $files_to_free <= 0 ) {
					return 0;
				}
				$instance = new self();
				if ( ! $instance->get_filesystem() ) {
					return 0;
				}
				$dir = trailingslashit( $instance->cache_root_dir );
				if ( '' !== $instance->domain ) {
					$dir .= $instance->domain;
				}
				$entries = $instance->collect_cache_entries_by_age( $dir );
				if ( empty( $entries ) ) {
					return 0;
				}
				usort(
					$entries,
					static function ( $a, $b ) {
						return ( (int) ( $a['mtime'] ?? 0 ) ) <=> ( (int) ( $b['mtime'] ?? 0 ) );
					}
				);
				$budget = min( count( $entries ), 2000 );
				for ( $i = 0; $i < $budget && $removed < $files_to_free; ++$i ) {
					$file = (string) ( $entries[ $i ]['path'] ?? '' );
					if ( '' === $file || ! $instance->is_path_contained( $file ) ) {
						continue;
					}
					if ( $instance->delete_cache_files( $file ) ) {
						++$removed;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return max( 0, $removed );
		}

		/**
		 * Collect cache `index.html` entries with mtime + size for eviction.
		 *
		 * Recursive `$fs->dirlist()` walk capped at depth 20 and 5000
		 * entries so eviction stays bounded on huge caches.
		 *
		 * @since 2.2.0
		 * @param string $directory Directory to scan.
		 * @param int    $depth     Recursion depth guard.
		 * @param array  $out       Accumulator (passed by reference).
		 * @return array<int, array{path:string,mtime:int,size:int}> Collected entries.
		 */
		private function collect_cache_entries_by_age( string $directory, int $depth = 0, array &$out = array() ): array {
			try {
				if ( $depth > 20 || count( $out ) >= 5000 ) {
					return $out;
				}
				$files = $this->list_cache_children( $directory );
				if ( null === $files ) {
					return $out;
				}
				$fs = $this->get_filesystem();
				if ( ! $fs ) {
					return $out;
				}
				foreach ( $files as $file ) {
					if ( count( $out ) >= 5000 ) {
						break;
					}
					$file_path = trailingslashit( $directory ) . $file['name'];
					if ( 'd' === $file['type'] ) {
						$this->collect_cache_entries_by_age( $file_path, $depth + 1, $out );
						continue;
					}
					if ( 'index.html' !== $file['name'] ) {
						continue;
					}
					$size  = isset( $file['size'] ) && is_numeric( $file['size'] ) ? (int) $file['size'] : (int) $fs->size( $file_path );
					$mtime = 0;
					if ( isset( $file['lastmodunix'] ) && is_numeric( $file['lastmodunix'] ) ) {
						$mtime = (int) $file['lastmodunix'];
					} else {
						$mtime = (int) $fs->mtime( $file_path );
					}
					$siblings = $size;
					foreach ( array( '.gz', '.br' ) as $suffix ) {
						$sibling = $file_path . $suffix;
						if ( $fs->exists( $sibling ) ) {
							$siblings += (int) $fs->size( $sibling );
						}
					}
					$out[] = array(
						'path'  => $file_path,
						'mtime' => $mtime,
						'size'  => $siblings,
					);
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $out;
		}

		/**
		 * Flush a specific cache group via wp_cache_flush_group().
		 *
		 * Allows targeted flushing of object cache groups (e.g. wppo_minify_check,
		 * wppo_activity_logs) instead of a full cache flush.
		 *
		 * @since 2.0.0
		 *
		 * @param string $group The cache group to flush.
		 * @return bool True if the flush succeeded, false if the cache implementation
		 *              does not support flush_group or the function is unavailable.
		 */
		public static function flush_group( string $group ): bool {
			// WP 6.1+: use wp_cache_supports() to check capability.
			if ( function_exists( 'wp_cache_supports' ) ) {
				if ( ! wp_cache_supports( 'flush_group' ) ) {
					return false;
				}

				return wp_cache_flush_group( $group );
			}

			// Legacy fallback for WP < 6.1.
			if ( function_exists( 'wp_cache_flush_group' ) ) {
				global $wp_object_cache;

				if ( isset( $wp_object_cache ) && method_exists( $wp_object_cache, 'flush_group' ) ) {
					return wp_cache_flush_group( $group );
				}
			}

			return false;
		}

		/**
		 * Evict legacy WP 6.9 pre-salt query-group cache keys.
		 *
		 * Core's 6.9+ single-key-per-group cache leaves unsalted post-queries /
		 * term-queries / comment-queries / user-queries / site-queries / network-queries
		 * keys behind once wp_cache_add_salt() has been called. A full wp_cache_flush()
		 * is the only reliable eviction (flush_group() patterns are salt-prefixed and
		 * miss the legacy unsalted keys). On cores without a persistent object cache
		 * this only flushes the in-memory cache and is harmless.
		 *
		 * @since 1.9.0
		 * @return bool True if the flush ran (or nothing needed evicting on cores
		 *              without the WP 6.9+ salt API), false if the cache API is
		 *              unavailable.
		 */
		public static function flush_legacy_query_cache_keys(): bool {
			// The stale unsalted key layout only exists where core can salt the
			// cache (WP 6.9+). On older cores every key is unsalted and current,
			// so there is nothing stale to evict.
			if ( ! function_exists( 'wp_cache_get_salted' ) ) {
				return true;
			}

			if ( function_exists( 'wp_cache_flush' ) ) {
				return wp_cache_flush();
			}

			return false;
		}

		/**
		 * Flush runtime (in-memory) cache via wp_cache_flush_runtime().
		 *
		 * Avoids unnecessary persistent cache (Redis/Memcached) flushes when only
		 * in-memory cached data has changed (e.g. admin UI settings).
		 *
		 * @since 1.9.0
		 *
		 * @return bool True if the flush succeeded, false if the function is
		 *              unavailable (WP < 6.0).
		 */
		public static function flush_runtime(): bool {
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				return wp_cache_flush_runtime();
			}
			return false;
		}
	}
}
