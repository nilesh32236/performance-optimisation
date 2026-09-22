<?php
/**
 * URL boundary — construction, comparison, normalization, and exclusion.
 *
 * Focused extraction (REF-004) of the URL responsibility cluster previously
 * owned by the god utility `Util`. Owns the hot-path base-URL memos
 * (blog-keyed, `has_filter`-guarded), the same-site/redirect lattice, URL
 * normalization, and exclusion matching (including the md5-rule memo).
 * `Util::cached_home_url()` and friends remain as thin facade proxies so all
 * existing callers keep working untouched.
 *
 * Minimal WordPress APIs only: `home_url()`, `content_url()`,
 * `wp_parse_url()`, `wp_normalize_path()` (via callers), `has_filter()`,
 * `get_current_blog_id()`, `untrailingslashit()`, `esc_url_raw()`,
 * `wp_http_validate_url()`, plus `add_query_arg()` for `get_current_url()`.
 * The single cross-boundary call is `Filesystem::normalize_cache_host()`
 * (canonical-host resolution); `Util` proxies back at call time only via the
 * spl autoloader so there is no load-time cycle.
 *
 * Deliberately OUT (stays in `Util`/`Filesystem`): `normalize_cache_host` /
 * `sanitize_cache_url_path` / `get_local_path` (Filesystem, REF-003);
 * `memoized_permalink` (permalink caching, not URL construction);
 * `generate_preload_link()` / `get_preload_link()` / `get_preload_link_args()`
 * (feature: preload); all `is_woo_*` (REF-013).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Url' ) ) {
	/**
	 * Class Url
	 *
	 * Static URL boundary. Depends only on the minimal WordPress APIs
	 * required to preserve the existing implementation verbatim (see file
	 * docblock) plus the single `Filesystem::normalize_cache_host()` call
	 * for canonical-host resolution. `Util` proxies back at call time only
	 * (autoloader, no load-time cycle).
	 *
	 * @since NEXT
	 */
	final class Url {

		/**
		 * Static cache for resolved home URLs, keyed by blog ID.
		 *
		 * @var array<int, string>
		 * @since NEXT
		 */
		private static array $home_url_cache = array();

		/**
		 * Per-blog memo for the canonical home host (see get_canonical_host()).
		 *
		 * @var array<int, string>
		 * @since NEXT
		 */
		private static array $canonical_host_cache = array();

		/**
		 * Memoized lowercase home host for same-site checks, keyed by blog.
		 *
		 * @since NEXT
		 * @var array<int, string>
		 */
		private static array $same_site_home_host = array();

		/**
		 * Resets the home_url static cache for testing isolation.
		 *
		 * Also clears the canonical-host, normalized-host, and same-site
		 * home-host memos, which are host-related state derived from the
		 * same stubs. The normalized-host memo lives in
		 * {@see \PerformanceOptimise\Inc\Filesystem} (REF-003) and is
		 * cleared via delegation. `Util::reset_cached_home_urls()` delegates
		 * here so the existing test-isolation entry point keeps working.
		 *
		 * @since NEXT
		 */
		public static function reset_cached_home_urls(): void {
			self::$home_url_cache       = array();
			self::$canonical_host_cache = array();
			Filesystem::reset_normalized_host_cache();
			Filesystem::reset_home_url_cache();
			self::$same_site_home_host = array();
		}

		/**
		 * Normalize and deduplicate a list of URLs.
		 *
		 * If given an array, each element is trimmed, duplicates and empty values are removed, and the result is reindexed.
		 * If given a non-array, the value is cast to string, split on newline characters, then trimmed, deduplicated, filtered and reindexed.
		 *
		 * @param string|array $urls Raw URLs as a newline-delimited string or an array of strings.
		 * @return array Cleaned list of unique, trimmed URLs with empty values removed and numeric keys reindexed.
		 * @since NEXT
		 */
		public static function process_urls( $urls ) {
			if ( is_array( $urls ) ) {
				return array_values( array_filter( array_unique( array_map( 'trim', $urls ) ) ) );
			}
			return array_values( array_filter( array_unique( array_map( 'trim', explode( "\n", (string) $urls ) ) ) ) );
		}

		/**
		 * Coerce an untrusted string list (e.g. filter output) to a clean list.
		 *
		 * Drops non-string/non-numeric entries (instead of casting arrays to
		 * "Array"), trims, drops empties, dedupes, and reindexes. Single
		 * shared helper for the delay-JS third-party allowlist mirrors in
		 * Main and Minify\HTML so allowlist semantics stay in one place.
		 *
		 * @param mixed $raw Untrusted list value.
		 * @return string[] Clean list.
		 * @since NEXT
		 */
		public static function coerce_string_list( $raw ): array {
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$mapped   = array_map(
				static function ( $val ): string {
					if ( is_string( $val ) || is_numeric( $val ) ) {
						return trim( (string) $val );
					}
					return '';
				},
				$raw
			);
			$filtered = array_filter(
				$mapped,
				static function ( $val ): bool {
					return '' !== $val;
				}
			);
			return array_values( array_unique( $filtered ) );
		}

		/**
		 * Check whether a URL matches any of the exclusion rules.
		 *
		 * Both the URL being checked and each exclusion rule are normalized with
		 * a trailing-slash trim before matching. Schemes are normalized so that
		 * `http://` and `https://` rules match interchangeably. Root-relative
		 * rules are resolved against {@see home_url()}. Rules containing a
		 * "(.*)" placeholder act as prefix patterns; all other rules must match
		 * exactly. Empty and whitespace-only rules are ignored.
		 *
		 * @param string $url         The URL to check.
		 * @param array  $exclude_urls List of exclusion rules.
		 * @return bool True when the URL matches any exclusion rule, false otherwise.
		 * @since NEXT
		 */
		public static function is_url_excluded( string $url, array $exclude_urls ): bool {
			$url = rtrim( $url, '/' );

			$strip_scheme = static function ( $u ) {
				if ( 0 === stripos( $u, 'https://' ) ) {
					return substr( $u, 8 );
				}
				if ( 0 === stripos( $u, 'http://' ) ) {
					return substr( $u, 7 );
				}
				return $u;
			};

			static $cache = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$cache_key = md5( serialize( $exclude_urls ) ); // We use serialize since it's faster and guaranteed safe for arrays of strings internally generated.

			if ( ! isset( $cache[ $cache_key ] ) ) {
				$home_base = self::cached_home_url();
				$exact     = array();
				$prefix    = array();

				foreach ( $exclude_urls as $exclude_url ) {
					$exclude_url = rtrim( $exclude_url, '/' );

					if ( '' === $exclude_url ) {
						continue;
					}

					if ( 0 !== strpos( $exclude_url, 'http' ) ) {
						$exclude_url = $home_base . '/' . ltrim( $exclude_url, '/' );
					}

					$normalized_rule = $strip_scheme( $exclude_url );

					if ( false !== strpos( $normalized_rule, '(.*)' ) ) {
						$exclude_prefix = rtrim( str_replace( '(.*)', '', $normalized_rule ), '/' ) . '/';
						$prefix[]       = $exclude_prefix;
					} else {
						$exact[ $normalized_rule ] = true;
					}
				}

				$cache[ $cache_key ] = array(
					'exact'  => $exact,
					'prefix' => $prefix,
				);
			}

			$normalized_url = $strip_scheme( $url );

			if ( isset( $cache[ $cache_key ]['exact'][ $normalized_url ] ) ) {
				return true;
			}

			foreach ( $cache[ $cache_key ]['prefix'] as $exclude_prefix ) {
				if ( 0 === strpos( $normalized_url . '/', $exclude_prefix ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get the current front-end URL including scheme and host.
		 *
		 * Returns a normalized URL without query string, consistent with
		 * the normalization used in store_lcp_image_url(). The returned
		 * URL is untrailingslashed and passed through esc_url_raw().
		 *
		 * @since NEXT
		 * @return string Current URL.
		 */
		public static function get_current_url(): string {
			global $wp;
			$url = self::cached_home_url( (string) add_query_arg( array(), $wp->request ?? '' ) );
			return untrailingslashit( esc_url_raw( $url ) );
		}

		/**
		 * Normalize a RUM page path for storage and lookup.
		 *
		 * Both the beacon store path (RUM::print_config/sanitize_sample) and
		 * the field-LCP lookup path (Image_Optimisation::get_current_lcp_url)
		 * must agree, otherwise the override silently never fires: the
		 * lookup strips the trailing slash via get_current_url() while the
		 * stored beacon path kept it verbatim. Trims the trailing slash
		 * (keeping '/' for the root) and ensures a leading slash.
		 *
		 * @since NEXT
		 * @param string $path Raw page path.
		 * @return string Normalized path (e.g. '/hero-page', '/').
		 */
		public static function normalize_rum_path( string $path ): string {
			$path = trim( $path );
			if ( '' === $path ) {
				return '/';
			}
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}
			if ( '/' !== $path ) {
				$path = rtrim( $path, '/' );
				if ( '' === $path ) {
					return '/';
				}
			}
			return $path;
		}

		/**
		 * Normalize a URL for LCP matching.
		 *
		 * Resolves protocol-relative and root-relative URLs against home_url(),
		 * drops the scheme and any query string, and strips WordPress generated
		 * size suffixes (-NNNxNNN, -scaled, -eNNN) so derived assets are treated
		 * as the same image as their full-size original. Returns host + path
		 * lowercased for host, or empty string when unparseable.
		 *
		 * @since NEXT
		 * @param string $url The raw URL to normalize.
		 * @return string Normalized host + path, or empty string when unparseable.
		 */
		public static function normalize_url( string $url ): string {
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return '';
			}

			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = self::cached_home_url() . '/' . ltrim( $url, '/' );
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['path'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] ?? '' );
			$path = $parts['path'];
			// The `$` anchor lives inside the lookahead so the suffix only
			// strips immediately before the file extension at end of path
			// (a trailing `$` outside the lookahead could never match).
			$path = (string) preg_replace( '#-(?:\d+x\d+|scaled|e\d+)(?=\.[A-Za-z0-9]+$)#', '', $path );

			return $host . $path;
		}

		/**
		 * Resolve a site URL to an absolute https URL.
		 *
		 * Centralizes the protocol-relative / root-relative / home-base
		 * branches re-implemented across Image_Optimisation, Img_Converter,
		 * Used_CSS and Critical_CSS so a fix here reaches every pipeline.
		 *
		 * @since NEXT
		 * @param string $url Raw URL.
		 * @return string Absolute URL or '' when empty/data:.
		 */
		public static function normalize_site_url( string $url ): string {
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return '';
			}
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}
			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = self::cached_home_url() . '/' . ltrim( $url, '/' );
			}
			return $url;
		}

		/**
		 * Normalize a URL to a stable image/cache key (host + path).
		 *
		 * Thin canonical wrapper over {@see normalize_url()} so image and CSS
		 * pipelines share one key derivation instead of parallel copies.
		 *
		 * @since NEXT
		 * @param string $url Raw URL.
		 * @return string Normalized key or ''.
		 */
		public static function normalize_image_key( string $url ): string {
			return self::normalize_url( $url );
		}

		/**
		 * Get a content URL, cached per site per request.
		 *
		 * Centralizes the blog-ID-keyed static caching pattern used by the asset
		 * minifiers. Mirrors the convention in `class-main.php`: when a
		 * `content_url` filter is registered the result is not cached (the filter
		 * may return context-dependent output), otherwise the base URL is resolved
		 * once per site per request and reused across all call sites.
		 *
		 * The scheme is pinned to the site's canonical scheme (see
		 * `canonical_scheme()`), because `content_url()` derives its scheme from
		 * `is_ssl()`, which is false wherever `$_SERVER['HTTPS']` is absent —
		 * notably WP-CLI and bare cron runs. Asset URLs built here are written
		 * into cached CSS/JS on disk, so an `http://` result in one CLI run would
		 * be served to HTTPS visitors as mixed content and the browser would
		 * block the asset.
		 *
		 * @param string $path Path relative to the content directory.
		 * @return string The content URL for the given path.
		 * @since NEXT
		 */
		public static function cached_content_url( $path ) {
			if ( false !== has_filter( 'content_url' ) ) {
				return content_url( $path );
			}

			static $cache = array();
			$blog_id      = get_current_blog_id();

			if ( ! isset( $cache[ $blog_id ] ) ) {
				$cache[ $blog_id ] = array();
			}
			if ( ! isset( $cache[ $blog_id ][ $path ] ) ) {
				$cache[ $blog_id ][ $path ] = self::pin_url_scheme( content_url( $path ) );
			}

			return $cache[ $blog_id ][ $path ];
		}

		/**
		 * Rewrite a URL's scheme to the site's canonical scheme.
		 *
		 * Deliberately implemented with plain string handling rather than
		 * `set_url_scheme()`: this runs while building cacheable asset URLs, so
		 * it must stay callable from any context, including bootstraps where
		 * only a subset of core functions is loaded. `set_url_scheme()` is
		 * still honoured indirectly because `content_url()` already applied
		 * the `content_url` filter before this point.
		 *
		 * Only `http`/`https` are touched; any other scheme (or a scheme-less
		 * value) is returned unchanged.
		 *
		 * @since NEXT
		 * @param string $url URL to normalize.
		 * @return string URL carrying the canonical scheme.
		 */
		private static function pin_url_scheme( string $url ): string {
			$scheme = self::canonical_scheme();

			if ( 'https' === $scheme && 0 === stripos( $url, 'http://' ) ) {
				return 'https://' . substr( $url, 7 );
			}
			if ( 'http' === $scheme && 0 === stripos( $url, 'https://' ) ) {
				return 'http://' . substr( $url, 8 );
			}

			return $url;
		}

		/**
		 * The site's canonical URL scheme, independent of the current request.
		 *
		 * Derived from `home_url()` rather than `is_ssl()`. `is_ssl()` reports
		 * the scheme of the *current request* and is false in any context
		 * without `$_SERVER['HTTPS']` — WP-CLI, a bare cron run, or a proxy
		 * that does not forward the header. That makes it unsafe for building
		 * URLs which are then written to disk and served to later visitors:
		 * a single CLI-triggered cache write would bake `http://` asset URLs
		 * into cached CSS/JS and the browser would block them as mixed
		 * content on every HTTPS page.
		 *
		 * Always returns `'http'` or `'https'`, so the result is safe to pass
		 * to `set_url_scheme()`.
		 *
		 * @since NEXT
		 * @return string Either 'http' or 'https'.
		 */
		public static function canonical_scheme(): string {
			$scheme = '';
			try {
				if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
					$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$scheme = '';
			}

			return 'https' === strtolower( $scheme ) ? 'https' : 'http';
		}

		/**
		 * Get the home URL, cached per site per request.
		 *
		 * Centralizes the blog-ID-keyed static caching pattern used across the plugin.
		 * When a `home_url` filter is registered the result is not cached (the filter
		 * may return context-dependent output), otherwise the base URL is resolved
		 * once per site per request and reused across all call sites.
		 *
		 * WPML/Polylang compatibility: when filters are active the `$path`
		 * argument is forwarded to core `home_url( $path )` instead of
		 * concatenating the cached base with the path, so language-aware
		 * filters can rewrite the full URL.
		 *
		 * @param string $path Optional. Path relative to the home URL. Default empty.
		 * @return string The untrailingslashed home URL, with path appended if provided.
		 * @since NEXT
		 */
		public static function cached_home_url( string $path = '' ): string {
			if ( false !== has_filter( 'home_url' ) ) {
				return '' === $path ? untrailingslashit( home_url() ) : home_url( $path );
			}

			$blog_id = get_current_blog_id();

			if ( ! isset( self::$home_url_cache[ $blog_id ] ) ) {
				self::$home_url_cache[ $blog_id ] = untrailingslashit( home_url() );
			}

			if ( '' === $path ) {
				return self::$home_url_cache[ $blog_id ];
			}

			return self::$home_url_cache[ $blog_id ] . '/' . ltrim( $path, '/' );
		}

		/**
		 * Whether a URL's host matches the home host (case-insensitive).
		 *
		 * Canonical home-host comparator (audit #1357 review): DNS is
		 * case-insensitive, so both sides lowercase (Rest::is_same_site_url()
		 * compared raw === and was the outlier). Host-only — no scheme or
		 * syntax validation; use is_same_site_url() for full checks.
		 *
		 * @since NEXT
		 * @param string $url URL to check.
		 * @return bool True when hosts match and home host is known.
		 */
		public static function is_same_site_host( string $url ): bool {
			try {
				$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				if ( ! array_key_exists( $blog_id, self::$same_site_home_host ) ) {
					$home_host = function_exists( 'wp_parse_url' ) ? wp_parse_url( self::cached_home_url(), PHP_URL_HOST ) : '';
					// DNS is case-insensitive and a single trailing dot is the
					// FQDN root form of the same host (audit #1490 review).
					self::$same_site_home_host[ $blog_id ] = ( is_string( $home_host ) ) ? self::normalize_host_for_compare( $home_host ) : '';
				}
				if ( '' === self::$same_site_home_host[ $blog_id ] ) {
					return false;
				}
				$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : '';
				return is_string( $host ) && '' !== $host && self::normalize_host_for_compare( $host ) === self::$same_site_home_host[ $blog_id ];
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Normalize a host for case-insensitive same-site comparison.
		 *
		 * Lowercases and strips a single trailing dot (the DNS FQDN root
		 * form, e.g. `example.com.`), which denotes the same host as the
		 * bare name. Only one dot is stripped so malformed `example.com..`
		 * never canonicalizes to a valid host.
		 *
		 * @since NEXT
		 * @param string $host Raw host value.
		 * @return string Normalized host.
		 */
		private static function normalize_host_for_compare( string $host ): string {
			$host = strtolower( $host );
			if ( str_ends_with( $host, '.' ) ) {
				$host = substr( $host, 0, -1 );
			}
			return $host;
		}

		/**
		 * Whether a URL is same-site and safe for server-side fetching.
		 *
		 * Canonical full check (audit #1357 review): wp_http_validate_url()
		 * syntax + http(s) scheme + is_same_site_host(). Delegates:
		 * Rest::is_same_site_url(), Critical_CSS::is_same_site_host() (host
		 * part), and Abilities::same_site_url_or_home() all funnel here so
		 * port/case/IDN edge cases cannot drift apart.
		 *
		 * Audit #1490 review: the port is pinned as well as the host. A URL
		 * with no explicit port (the scheme default 80/443) always passes
		 * this leg; an explicit nonstandard port must equal the home URL's
		 * effective port (so dev/staging hosts on :8080 keep working while
		 * a redirect hop cannot pivot to a sidecar on another port of the
		 * same host). Residual acceptance: an explicit :80/:443 on the home
		 * host is allowed — those are the standard web ports sharing the
		 * web attack surface, and the host gate still applies.
		 *
		 * @since NEXT
		 * @param string $url URL to check.
		 * @return bool True when safe.
		 */
		public static function is_same_site_url( string $url ): bool {
			try {
				if ( '' === $url ) {
					return false;
				}
				if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
					return false;
				}
				$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : false;
				if ( ! is_array( $parsed ) ) {
					return false;
				}
				$scheme = $parsed['scheme'] ?? '';
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					return false;
				}
				if ( ! self::is_allowed_same_site_port( $parsed ) ) {
					return false;
				}
				return self::is_same_site_host( $url );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a parsed URL's port is allowed by the same-site policy.
		 *
		 * @since NEXT
		 * @param array<string, mixed> $parsed wp_parse_url() output for the candidate URL.
		 * @return bool True when the port leg passes.
		 */
		private static function is_allowed_same_site_port( array $parsed ): bool {
			if ( ! array_key_exists( 'port', $parsed ) || null === $parsed['port'] || '' === $parsed['port'] ) {
				// No explicit port — the scheme default (80/443). Allowed.
				return true;
			}
			$port = (int) $parsed['port'];
			if ( 80 === $port || 443 === $port ) {
				// Explicit standard web ports share the web attack surface.
				return true;
			}
			// Nonstandard explicit port: only the home URL's own effective
			// port may be used (dev/staging hosts on :8080 etc.).
			$home_port = self::home_effective_port();
			return null !== $home_port && $port === $home_port;
		}

		/**
		 * Effective port of the home URL (explicit port or scheme default).
		 *
		 * @since NEXT
		 * @return int|null Effective home port, or null when indeterminable (fail closed).
		 */
		private static function home_effective_port(): ?int {
			if ( ! function_exists( 'wp_parse_url' ) ) {
				return null;
			}
			$home_port = wp_parse_url( self::cached_home_url(), PHP_URL_PORT );
			if ( is_int( $home_port ) || ( is_string( $home_port ) && ctype_digit( $home_port ) ) ) {
				return (int) $home_port;
			}
			$home_scheme = wp_parse_url( self::cached_home_url(), PHP_URL_SCHEME );
			$home_scheme = is_string( $home_scheme ) ? strtolower( $home_scheme ) : '';
			if ( 'http' === $home_scheme ) {
				return 80;
			}
			if ( 'https' === $home_scheme ) {
				return 443;
			}
			return null;
		}

		/**
		 * Validate a caller-supplied URL as same-site, else the fallback.
		 *
		 * @since NEXT
		 * @param string $url      Caller URL (already esc_url_raw'd by caller).
		 * @param string $fallback Fallback (home URL, or '' to fail closed).
		 * @return string Same-site URL or the fallback.
		 */
		public static function same_site_url_or_home( string $url, string $fallback ): string {
			try {
				return self::is_same_site_url( $url ) ? $url : $fallback;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}

		/**
		 * Resolve a redirect Location against the current URL and validate it.
		 *
		 * Single shared SSRF-adjacent resolver (audit #1490 review): absolute
		 * URLs are taken as-is, protocol-relative URLs inherit the current
		 * scheme, and relative URLs resolve against the current URL's
		 * directory with RFC 3986 dot-segment normalization (so `../other`
		 * resolves to a canonical path instead of fetching a literal
		 * `/subdir/../other`). Query-only (`?x=1`) locations keep the
		 * current path, fragment-only (`#frag`) locations re-resolve to the
		 * current URL minus its fragment. The resolved hop must then pass
		 * the same-host policy ({@see is_same_site_url()}) so a same-host
		 * response can never bounce the server into internal endpoints
		 * (e.g. link-local metadata). Used by Telemetry and the LiteSpeed
		 * crawler so the two copies of this logic cannot drift apart.
		 *
		 * @since NEXT
		 * @param string $location    Raw Location header value.
		 * @param string $current_url URL of the response that sent the Location.
		 * @return string|false Absolute validated URL, or false when the hop is not allowed.
		 */
		public static function resolve_same_host_redirect( string $location, string $current_url ): string|false {
			try {
				$location = trim( $location );
				if ( '' === $location ) {
					return false;
				}

				// Absolute URL — take as-is.
				if ( preg_match( '/^https?:\/\//i', $location ) ) {
					$resolved = $location;
				} elseif ( 0 === strpos( $location, '//' ) ) {
					// Protocol-relative — inherit the current scheme.
					$scheme   = function_exists( 'wp_parse_url' ) ? wp_parse_url( $current_url, PHP_URL_SCHEME ) : '';
					$resolved = ( $scheme ? $scheme : 'https' ) . ':' . $location;
				} else {
					// Relative URL — resolve against the current URL's directory.
					$base_parts = function_exists( 'wp_parse_url' ) ? wp_parse_url( $current_url ) : false;
					if ( ! is_array( $base_parts ) || empty( $base_parts['host'] ) ) {
						return false;
					}

					$scheme   = isset( $base_parts['scheme'] ) ? $base_parts['scheme'] : 'https';
					$host     = $base_parts['host'];
					$port     = isset( $base_parts['port'] ) ? ':' . $base_parts['port'] : '';
					$base_dir = dirname( isset( $base_parts['path'] ) && '' !== $base_parts['path'] ? $base_parts['path'] : '/' );

					if ( 0 === strpos( $location, '?' ) ) {
						// Query-only — keep the current path, replace the query.
						$current_path = isset( $base_parts['path'] ) && '' !== $base_parts['path'] ? $base_parts['path'] : '/';
						$resolved     = $scheme . '://' . $host . $port . $current_path . $location;
					} elseif ( 0 === strpos( $location, '#' ) ) {
						// Fragment-only — never sent to the server; re-resolve
						// to the current URL minus its fragment so the hop
						// validates (and fetches) the same canonical page.
						$hash_pos = strpos( $current_url, '#' );
						$resolved = ( false !== $hash_pos ? substr( $current_url, 0, $hash_pos ) : $current_url ) . $location;
					} elseif ( 0 === strpos( $location, '/' ) ) {
						$resolved = $scheme . '://' . $host . $port . self::normalize_redirect_target( $location );
					} else {
						// rtrim — dirname('/') yields '/'.
						$merged   = rtrim( $base_dir, '/' ) . '/' . $location;
						$resolved = $scheme . '://' . $host . $port . self::normalize_redirect_target( $merged );
					}
				}

				// Fail closed without the core URL validator, mirroring the
				// pre-extraction callers which both required it.
				if ( ! function_exists( 'wp_http_validate_url' ) ) {
					return false;
				}

				// Validate the hop with the same SSRF rules as the initial URL.
				if ( ! self::is_same_site_url( $resolved ) ) {
					return false;
				}

				return $resolved;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Normalize a redirect target (path + optional ?query/#fragment).
		 *
		 * Splits off the query/fragment suffix so dot-segment removal
		 * applies to the path part only (audit #1490 review: a bare
		 * concatenation would fetch `/subdir/../other` literally), then
		 * reattaches the suffix untouched.
		 *
		 * @since NEXT
		 * @param string $target Path with optional query/fragment suffix.
		 * @return string Normalized target.
		 */
		private static function normalize_redirect_target( string $target ): string {
			$suffix   = '';
			$hash_pos = strpos( $target, '#' );
			if ( false !== $hash_pos ) {
				$suffix = substr( $target, $hash_pos );
				$target = substr( $target, 0, $hash_pos );
			}
			$query_pos = strpos( $target, '?' );
			if ( false !== $query_pos ) {
				$suffix = substr( $target, $query_pos ) . $suffix;
				$target = substr( $target, 0, $query_pos );
			}
			return self::remove_dot_segments( $target ) . $suffix;
		}

		/**
		 * Remove RFC 3986 section 5.2.4 dot segments from a URL path.
		 *
		 * Collapses `/./` and resolves `/../` lexically (a leading `/..`
		 * that escapes the root is dropped — the host gate applied by the
		 * caller still bounds where the resolved hop may go). A trailing
		 * slash implied by the input (including `/./` and `/../` endings)
		 * is preserved so directory redirects keep their canonical form.
		 *
		 * @since NEXT
		 * @param string $path URL path to normalize.
		 * @return string Normalized path (always starting with '/').
		 */
		private static function remove_dot_segments( string $path ): string {
			$segments = explode( '/', $path );
			$out      = array();
			foreach ( $segments as $segment ) {
				if ( '' === $segment || '.' === $segment ) {
					continue;
				}
				if ( '..' === $segment ) {
					array_pop( $out );
					continue;
				}
				$out[] = $segment;
			}
			$normalized = '/' . implode( '/', $out );
			if ( '/' !== $normalized && ( str_ends_with( $path, '/' ) || str_ends_with( $path, '/.' ) || str_ends_with( $path, '/..' ) ) ) {
				$normalized .= '/';
			}
			return $normalized;
		}

		/**
		 * Resolve the canonical host for cache keying from home_url().
		 *
		 * The static HTML cache tree (and the used-CSS cache dir) must be keyed
		 * by the canonical home host so a forged Host header can never create
		 * or serve a poisoned cache file (Host-header cache poisoning to stored
		 * XSS). Multisite-safe: home_url() is already blog-aware. Returns an
		 * empty string when home_url() is unavailable (early boot, CLI) so
		 * callers can fall back to the legacy Host-derived domain instead of
		 * fataling.
		 *
		 * @return string Canonical lowercase host, or '' when it cannot be resolved.
		 * @since NEXT
		 */
		public static function get_canonical_host(): string {
			// Per-blog static memo mirroring cached_home_url(): every call
			// otherwise repeats home_url() + wp_parse_url(HOST) +
			// normalize_cache_host() (with idn_to_ascii), 2-3x per request
			// (Cache + Used_CSS + REST). Not memoized when a home_url
			// filter is present (context-dependent output).
			$bid = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( function_exists( 'has_filter' ) && false !== has_filter( 'home_url' ) ) {
				return self::resolve_canonical_host();
			}
			if ( isset( self::$canonical_host_cache[ $bid ] ) ) {
				return self::$canonical_host_cache[ $bid ];
			}
			$resolved                           = self::resolve_canonical_host();
			self::$canonical_host_cache[ $bid ] = $resolved;
			return $resolved;
		}

		/**
		 * Uncached canonical-host resolution backing get_canonical_host().
		 *
		 * @return string Canonical lowercase host, or '' when unresolvable.
		 * @since NEXT
		 */
		private static function resolve_canonical_host(): string {
			if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) {
				return '';
			}
			try {
				$home = home_url();
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
			if ( ! is_string( $home ) || '' === $home ) {
				return '';
			}
			try {
				$host = wp_parse_url( $home, PHP_URL_HOST );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
			if ( ! is_string( $host ) || '' === $host ) {
				return '';
			}
			return Filesystem::normalize_cache_host( $host );
		}
	}
}
