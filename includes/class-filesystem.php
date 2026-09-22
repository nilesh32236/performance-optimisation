<?php
/**
 * Filesystem boundary — directories, local-path resolution, minify path policy,
 * atomic writes, verified PHP writes, and cache-path containment guards.
 *
 * Focused extraction (REF-003) of the filesystem responsibility cluster
 * previously owned by the god utility `Util`. Owns all low-level filesystem
 * logic with byte-identical bodies (the exact native-vs-`$fs` call choices are
 * preserved on purpose — any WP_Filesystem migration is a separate compat
 * decision). `Util::prepare_cache_dir()` and friends remain as one-line facade
 * proxies so all existing callers keep working untouched.
 *
 * Minimal WordPress APIs only: `wp_normalize_path()`, `WP_Filesystem()`,
 * `wp_mkdir_p()` (via callers), `home_url()`, `wp_upload_dir()`, plus the
 * `wppo_minify_allowed_roots` / `wppo_allow_php_lint` filters where already
 * used. Depends on nothing else in the plugin (no cycles, no new state — the
 * normalized-host memo moved here with its owner).
 *
 * Deliberately OUT (REF-012): the rollout/purge-fallback slot helpers
 * (`get_staged_path_for`, `promote_staged_file`, `restore_fallback_file`,
 * `describe_rollout_slot`, `is_purge_fallback_enabled`,
 * `get_purge_fallback_path_for`, `retain_purge_fallback_file`,
 * `is_purge_fallback_payload_valid`, `purge_fallback_should_log`,
 * `css_file_valid`, `log_css_fallback`) and the `$purge_fallback_memo` state,
 * which stay in `Util`.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Filesystem' ) ) {
	/**
	 * Class Filesystem
	 *
	 * Static filesystem boundary. Depends only on the minimal WordPress
	 * APIs required to preserve the existing implementation verbatim
	 * (see file docblock). Depends on nothing else in the plugin.
	 *
	 * @since NEXT
	 */
	final class Filesystem {

		/**
		 * Per-value memo for normalized cache hosts (see normalize_cache_host()).
		 *
		 * Moved with its owner from `Util` (REF-003); the single
		 * per-request memo for host normalization lives here now.
		 *
		 * @var array<string, string>
		 * @since NEXT
		 */
		private static array $normalized_host_cache = array();

		/**
		 * Per-blog memo for minify allowed roots (see get_minify_allowed_roots()).
		 *
		 * `wp_upload_dir()` resolves per blog (multisite-safe) via option
		 * reads; caching per blog ID avoids repeating that work once per
		 * enqueued asset on asset-heavy pages. Bypassed whenever the
		 * `wppo_minify_allowed_roots` filter is present (context-dependent
		 * output must never be memoized). Bounded to avoid unbounded growth
		 * on large multisite networks.
		 *
		 * @var array<int, string[]>
		 * @since NEXT
		 */
		private static array $minify_roots_cache = array();

		/**
		 * Recursively creates cache directory if not exists.
		 *
		 * @param string $cache_dir Path to the cache directory.
		 * @return bool True if created or exists, false otherwise.
		 * @since NEXT
		 */
		public static function prepare_cache_dir( $cache_dir ): bool {
			$fs = self::init_filesystem();

			if ( ! $fs ) {
				return false;
			}

			if ( ! is_string( $cache_dir ) || '' === $cache_dir ) {
				return false;
			}

			$path = wp_normalize_path( $cache_dir );
			// Fail closed on traversal: reject .. segments lexically.
			$segments = explode( '/', trim( $path, '/' ) );
			foreach ( $segments as $segment ) {
				if ( '..' === $segment ) {
					return false;
				}
			}
			// Containment: must live under WP_CONTENT_DIR so a caller
			// forwarding user-influenced input cannot create directories
			// outside the content tree (uploads/cache/fonts subtrees).
			$content = wp_normalize_path( (string) WP_CONTENT_DIR );
			if ( $path !== $content && 0 !== strpos( $path, trailingslashit( $content ) ) ) {
				return false;
			}

			if ( $fs->is_dir( $cache_dir ) ) {
				return true;
			}

			// Build parent directories iteratively to avoid deep recursion.
			$parts = explode( '/', trim( $path, '/' ) );
			$build = '';

			foreach ( $parts as $part ) {
				$build = $build ? $build . '/' . $part : '/' . $part;
				if ( ! $fs->is_dir( $build ) ) {
					if ( ! $fs->mkdir( $build, FS_CHMOD_DIR ) ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Initializes the WP_Filesystem API.
		 *
		 * @return mixed WP_Filesystem_Base|false The filesystem object or false on failure.
		 * @since NEXT
		 */
		public static function init_filesystem() {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
			}

			if ( WP_Filesystem() ) {
				return $wp_filesystem;
			} else {
				return false;
			}
		}

		/**
		 * Gets the local file path from a URL.
		 *
		 * @param string $url The URL to process.
		 * @return string The local file path.
		 * @since NEXT
		 */
		public static function get_local_path( string $url ): string {
			// Reject NUL bytes and stream wrappers before URL parsing so
			// payloads such as "php://filter/..." or "file:///etc/passwd"
			// can never map onto the local tree.
			if ( false !== strpos( $url, "\0" ) ) {
				return '';
			}
			$trimmed_url = ltrim( $url );
			if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#i', $trimmed_url ) ) {
				// Absolute http(s) URLs are legitimate asset sources; any
				// other scheme (php://, file://, expect://, phar://, ...) is
				// a wrapper probe and is refused outright.
				if ( 0 !== stripos( $trimmed_url, 'http://' ) && 0 !== stripos( $trimmed_url, 'https://' ) ) {
					return '';
				}
			} elseif ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#i', $trimmed_url ) && ! (bool) preg_match( '#^[a-zA-Z]:[\\\\/]#', $trimmed_url ) ) {
				// Scheme without "//" (e.g. "data:text/html,...") is never a
				// local asset reference. The drive-letter carve-out keeps
				// Windows paths ("C:\...") from false-positive matching.
				return '';
			}

			// Parse the URL to get the path.
			$parsed_url = wp_parse_url( $url );
			if ( false === $parsed_url ) {
				return '';
			}

			// Get the path from the parsed URL.
			$relative_path = wp_normalize_path( $parsed_url['path'] ?? '' );

			if ( strpos( $relative_path, '..' ) !== false ) {
				return '';
			}

			// Single-decode and re-check so an encoded traversal payload
			// (e.g. "%2e%2e/%2e%2e/etc/passwd") cannot smuggle past the
			// literal ".." check above. Double-encoding stays literal and
			// therefore harmless (it never resolves to ".." on disk).
			$decoded_path = rawurldecode( $relative_path );
			if ( false !== strpos( $decoded_path, "\0" ) || false !== strpos( $decoded_path, '..' ) ) {
				return '';
			}

			// If home_url has a subdirectory path, remove it only from the start.
			$home_path = wp_normalize_path( wp_parse_url( self::home_url_for_local_path(), PHP_URL_PATH ) ?? '' );

			if ( $home_path && '/' !== $home_path ) {
				if ( 0 === strpos( $relative_path, $home_path ) ) {
					$relative_path = substr( $relative_path, strlen( $home_path ) );
				}
			}

			// Build the full local path and verify it stays within ABSPATH.
			$normalized_abspath = wp_normalize_path( ABSPATH );
			$full_path          = wp_normalize_path( ABSPATH . ltrim( $relative_path, '/' ) );

			// Enforce ABSPATH prefix bounds. normalized_abspath ends with a '/', so a
			// 0-offset prefix match guarantees the path is a descendant of ABSPATH
			// (a sibling directory such as "/path/abspath2" cannot prefix-match).
			if ( 0 !== strpos( $full_path, $normalized_abspath ) ) {
				return '';
			}

			return $full_path;
		}

		/**
		 * Gets the allow-listed filesystem roots for minify/combine file serving.
		 *
		 * Defaults to ABSPATH, WP_CONTENT_DIR, and the current site's uploads
		 * basedir (multisite-safe: wp_upload_dir() resolves per blog). Passes
		 * the defaults through the `wppo_minify_allowed_roots` filter when a
		 * listener is registered; invalid or empty filtered values fall back
		 * to the defaults so a poisoned filter can never open the tree.
		 *
		 * Per-request memo: results are cached per blog ID and reused across
		 * calls (validate_minify_path() runs once per enqueued asset). The
		 * memo is bypassed whenever the `wppo_minify_allowed_roots` filter
		 * is present, mirroring the cached_content_url()/cached_home_url()
		 * convention — filtered output may be context-dependent.
		 *
		 * @return string[] Normalized absolute root paths.
		 * @since NEXT
		 */
		public static function get_minify_allowed_roots(): array {
			$has_filter = function_exists( 'has_filter' ) ? has_filter( 'wppo_minify_allowed_roots' ) : false;
			$use_memo   = false === $has_filter;
			$blog_id    = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;

			if ( $use_memo && isset( self::$minify_roots_cache[ $blog_id ] ) ) {
				return self::$minify_roots_cache[ $blog_id ];
			}

			$defaults = array();

			if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH ) {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$defaults[] = wp_normalize_path( ABSPATH );
				} else {
					$defaults[] = str_replace( '\\', '/', (string) ABSPATH );
				}
			}

			if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$defaults[] = wp_normalize_path( WP_CONTENT_DIR );
				} else {
					$defaults[] = str_replace( '\\', '/', (string) WP_CONTENT_DIR );
				}
			}

			if ( function_exists( 'wp_upload_dir' ) ) {
				try {
					$upload_dir = wp_upload_dir();
					if ( is_array( $upload_dir ) && isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] ) && '' !== $upload_dir['basedir'] ) {
						if ( function_exists( 'wp_normalize_path' ) ) {
							$defaults[] = wp_normalize_path( $upload_dir['basedir'] );
						} else {
							$defaults[] = str_replace( '\\', '/', $upload_dir['basedir'] );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$defaults = array_values( array_unique( array_filter( $defaults ) ) );

			if ( function_exists( 'has_filter' ) && has_filter( 'wppo_minify_allowed_roots' ) ) {
				$filtered = apply_filters( 'wppo_minify_allowed_roots', $defaults );
				if ( is_array( $filtered ) && ! empty( $filtered ) ) {
					$sanitized = array();
					foreach ( $filtered as $root ) {
						if ( ! is_string( $root ) || '' === trim( $root ) ) {
							continue;
						}
						if ( function_exists( 'wp_normalize_path' ) ) {
							$sanitized[] = wp_normalize_path( $root );
						} else {
							$sanitized[] = str_replace( '\\', '/', $root );
						}
					}
					$sanitized = array_values( array_unique( array_filter( $sanitized ) ) );
					if ( ! empty( $sanitized ) ) {
						return $sanitized;
					}
				}
			}

			if ( $use_memo ) {
				if ( count( self::$minify_roots_cache ) > 64 ) {
					self::$minify_roots_cache = array();
				}
				self::$minify_roots_cache[ $blog_id ] = $defaults;
			}

			return $defaults;
		}

		/**
		 * Reset the minify-roots memo (testing isolation).
		 *
		 * Called from `Util::reset_runtime_caches()` so the existing
		 * test-isolation entry point keeps clearing per-blog state when
		 * `switch_to_blog()` fixtures change the uploads basedir mid-suite.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_minify_roots_cache(): void {
			self::$minify_roots_cache = array();
		}

		/**
		 * Whether a minify/combine source path is allowed to be read.
		 *
		 * Single auditable gate: rejects non-string/empty input, NUL bytes,
		 * literal "..", stream wrappers (php://, file://, expect://,
		 * phar://, data:, and any other "scheme:" prefix), and ".php"
		 * targets; resolves symlinks via realpath() (guarded) and requires
		 * the resolved path to sit inside one of
		 * {@see Filesystem::get_minify_allowed_roots()} with a trailing-slash
		 * boundary so sibling-prefix directories cannot match.
		 *
		 * Never emits file bytes and never fatals — callers fail open to
		 * uncombined/unoptimised output.
		 *
		 * @param mixed $path Candidate filesystem path.
		 * @return bool True when the path resolves inside an allowed root.
		 * @since NEXT
		 */
		public static function is_minify_path_allowed( $path ): bool {
			return '' !== self::validate_minify_path( $path );
		}

		/**
		 * Validates a minify/combine source path and returns its resolved form.
		 *
		 * Same gate as {@see Filesystem::is_minify_path_allowed()} but returns the
		 * realpath-resolved, normalized path on success or '' on failure.
		 *
		 * @param mixed $path Candidate filesystem path.
		 * @return string Resolved allowed path, or '' when rejected.
		 * @since NEXT
		 */
		public static function validate_minify_path( $path ): string {
			if ( ! is_string( $path ) || '' === $path ) {
				return '';
			}

			if ( false !== strpos( $path, "\0" ) ) {
				return '';
			}

			if ( false !== strpos( $path, '..' ) ) {
				return '';
			}

			$trimmed = ltrim( $path );
			if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#i', $trimmed ) ) {
				return '';
			}
			if ( 0 === stripos( $trimmed, 'data:' ) || 0 === stripos( $trimmed, 'phar:' ) ) {
				return '';
			}
			if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#i', $trimmed ) && ! (bool) preg_match( '#^[a-zA-Z]:[\\\\/]#', $trimmed ) ) {
				return '';
			}

			if ( 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				return '';
			}

			if ( function_exists( 'realpath' ) ) {
				$resolved = realpath( $path );
				if ( false === $resolved ) {
					return '';
				}
			} else {
				$resolved = $path;
			}

			if ( function_exists( 'wp_normalize_path' ) ) {
				$normalized = wp_normalize_path( $resolved );
			} else {
				$normalized = str_replace( '\\', '/', (string) $resolved );
			}

			if ( false !== strpos( $normalized, "\0" ) ) {
				return '';
			}

			foreach ( self::get_minify_allowed_roots() as $root ) {
				if ( ! is_string( $root ) || '' === $root ) {
					continue;
				}
				if ( $normalized === $root || 0 === strpos( $normalized, rtrim( $root, '/' ) . '/' ) ) {
					return (string) $resolved;
				}
			}

			return '';
		}

		/**
		 * Gets the number of minified JS and CSS files.
		 *
		 * @return array Associative array with counts for JS and CSS files.
		 * @since NEXT
		 */
		public static function get_js_css_minified_file() {
			$filesystem = self::init_filesystem();
			if ( ! $filesystem ) {
				return array(
					'js'  => 0,
					'css' => 0,
				);
			}
			$minify_dir = self::min_cache_dir_for_counts();

			$total_js  = 0;
			$total_css = 0;

			$js_files = $filesystem->dirlist( $minify_dir . '/js' );

			if ( ! empty( $js_files ) ) {
				foreach ( $js_files as $js_file ) {
					if ( isset( $js_file['name'] ) && 'js' === pathinfo( $js_file['name'], PATHINFO_EXTENSION ) ) {
						++$total_js;
					}
				}
			}

			$css_files = $filesystem->dirlist( $minify_dir . '/css' );

			if ( ! empty( $css_files ) ) {
				foreach ( $css_files as $css_file ) {
					if ( isset( $css_file['name'] ) && 'css' === pathinfo( $css_file['name'], PATHINFO_EXTENSION ) ) {
						++$total_css;
					}
				}
			}

			return array(
				'js'  => $total_js,
				'css' => $total_css,
			);
		}

		/**
		 * Normalize a raw host value into a safe cache-key domain.
		 *
		 * Lowercases, converts IDN to ASCII, strips any port, and applies the
		 * same allowlist/traversal validation used by the cache constructors so
		 * the canonicalization rule lives in one place. Returns an empty string
		 * when the value is missing or invalid (fail-open signal: callers fall
		 * back to legacy behaviour instead of writing under a forged host).
		 *
		 * Moved here with its memo from `Util` (REF-003) because
		 * {@see Filesystem::sanitize_cache_path()} and
		 * {@see Filesystem::sanitize_cache_url_path()} depend on it;
		 * `Util::normalize_cache_host()` remains as a facade proxy.
		 *
		 * @param string $raw_host Raw host value (e.g. $_SERVER['HTTP_HOST'] or a home_url() host).
		 * @return string Normalized lowercase host, or '' when invalid.
		 * @since NEXT
		 */
		public static function normalize_cache_host( string $raw_host ): string {
			// Static memo: hot callers (sanitize loops, invalidation fan-out)
			// pass the same already-canonical domain repeatedly; skip the
			// trim + port-strip + idn_to_ascii + regex work on repeats.
			if ( isset( self::$normalized_host_cache[ $raw_host ] ) ) {
				return self::$normalized_host_cache[ $raw_host ];
			}
			$domain = trim( $raw_host );
			if ( '' === $domain ) {
				self::$normalized_host_cache[ $raw_host ] = '';
				return '';
			}

			// Strip any port BEFORE IDN conversion: UTS46 rejects
			// 'münchen.de:8080' as a whole, so converting first would fail
			// and force a fail-open '' for IDN hosts with explicit ports.
			// Bracketed IPv6 literals ('[::1]:8080') are unwrapped first;
			// unbracketed multi-colon values are IPv6 literals (no port).
			if ( str_starts_with( $domain, '[' ) ) {
				$bracket_end = strpos( $domain, ']' );
				if ( false === $bracket_end ) {
					return self::memoize_normalized_host( $raw_host, '' );
				}
				// Reject trailing garbage after the bracket (e.g. '[::1]evil'):
				// only '' or a ':port' suffix is a well-formed bracketed host.
				$rest = substr( $domain, $bracket_end + 1 );
				if ( '' !== $rest && ':' !== substr( $rest, 0, 1 ) ) {
					return self::memoize_normalized_host( $raw_host, '' );
				}
				$host = substr( $domain, 1, $bracket_end - 1 );
			} elseif ( substr_count( $domain, ':' ) > 1 ) {
				$host = $domain;
			} else {
				$host = explode( ':', $domain, 2 )[0];
			}
			if ( '' === $host ) {
				return self::memoize_normalized_host( $raw_host, '' );
			}

			if ( function_exists( 'idn_to_ascii' ) ) {
				try {
					$converted = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				} catch ( \Throwable $e ) {
					unset( $e );
					$converted = false;
				}
				if ( false !== $converted && is_string( $converted ) && '' !== $converted ) {
					$host = $converted;
				}
			}

			$valid = ! (
			strpos( $host, '..' ) !== false ||
			strpos( $host, '/' ) !== false ||
			strpos( $host, '\\' ) !== false ||
			! preg_match( '/^[a-z0-9\.\-:]+$/i', $host )
			);

			if ( ! $valid ) {
				return self::memoize_normalized_host( $raw_host, '' );
			}

			return self::memoize_normalized_host( $raw_host, strtolower( $host ) );
		}

		/**
		 * Store a normalized host in the per-value memo (bounded size).
		 *
		 * Moved with its owner from `Util` (REF-003).
		 *
		 * @param string $raw_host Raw input key.
		 * @param string $normalized Normalized result.
		 * @return string The normalized result (passthrough for `return` sites).
		 * @since NEXT
		 */
		private static function memoize_normalized_host( string $raw_host, string $normalized ): string {
			if ( count( self::$normalized_host_cache ) > 64 ) {
				self::$normalized_host_cache = array();
			}
			self::$normalized_host_cache[ $raw_host ] = $normalized;
			return $normalized;
		}

		/**
		 * Reset the normalized-host memo (testing isolation).
		 *
		 * Called from `Util::reset_cached_home_urls()` so the existing
		 * test-isolation entry point keeps clearing host-related state.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_normalized_host_cache(): void {
			self::$normalized_host_cache = array();
		}

		/**
		 * Sanitize a URL path for cache file mapping.
		 *
		 * Shared encoded-sequence normalization for every file-writing
		 * surface (static HTML cache in {@see Cache}, per-page used-CSS in
		 * {@see Used_CSS}): only the PHP_URL_PATH component is used, exactly
		 * one rawurldecode pass is applied (single-decode semantics —
		 * `%252e` stays literal on disk and is never re-decoded, while
		 * single-encoded `%2e%2e` / `%00` decode once and are then rejected),
		 * and null bytes, remaining `..` segments, plus Windows drive (`C:`)
		 * and UNC (`\\`) prefixes are rejected. Absolute-form inputs
		 * (absolute URLs, protocol-relative `//host`, detected on the
		 * authority part before `?`/`#` so query strings carrying URLs never
		 * false-positive) are host-checked when `$allowed_host` is given: a
		 * same-host absolute URL maps to its path, while a foreign-host (or
		 * hostless protocol-relative/drive/UNC) input is refused outright so
		 * direct callers can never map a foreign host onto the local tree.
		 * Without `$allowed_host` the legacy path-only extraction applies.
		 * Returns an empty string for hostile or empty input; callers fail
		 * open (serve dynamic/uncached and log a traversal probe) when the
		 * raw input was non-blank.
		 *
		 * Moved here from `Util` (REF-003) because
		 * {@see Filesystem::sanitize_cache_path()} depends on it;
		 * `Util::sanitize_cache_url_path()` remains as a facade proxy.
		 * Pure static helper: no I/O, no settings reads. Multisite-safe.
		 *
		 * @param string|null $url_path     Raw URL path or URL.
		 * @param string|null $allowed_host Optional canonical host (alias: $domain / $canonical_host at call-sites);
		 *                                  same-host absolute URLs map to their path, others refuse.
		 * @return string Sanitized relative path or empty string.
		 * @since NEXT
		 */
		public static function sanitize_cache_url_path( ?string $url_path, ?string $allowed_host = null ): string {
			$raw_input = (string) $url_path;

			// Reject Windows drive prefixes and UNC roots before URL parsing
			// (parse_url() would otherwise strip `C:` as a scheme and hide
			// the absolute-path smuggling attempt).
			$trimmed_raw = ltrim( $raw_input );
			if ( '' !== $trimmed_raw && ( preg_match( '#^[a-zA-Z]:#', $trimmed_raw ) || 0 === strpos( $trimmed_raw, '\\\\' ) ) ) {
				return '';
			}

			// Absolute-form detection on the authority part only (before
			// `?`/`#`), mirroring Cache::__construct / sanitize_cache_path():
			// a benign relative path whose query/fragment carries a URL
			// (e.g. `/search?redirect=https://other`) must not false-positive.
			// The split uses strcspn() (no process-global strtok() state).
			// Both the raw and the single-decoded authority are inspected so
			// an encoded scheme (`https%3a%2f%2f`) cannot smuggle past. The
			// test is scheme-anchored (parity with Cache::__construct) so a
			// benign segment containing `://` (e.g. `/foo/a://b`) is not
			// misclassified; a lingering `%3a` remnant after one decode
			// (double-encoded scheme) still counts as absolute-form for
			// detection only — the mapped path keeps single-decode semantics.
			$authority = substr( $trimmed_raw, 0, strcspn( $trimmed_raw, '?#' ) );
			// Fast path: 99% of inputs are plain paths with no `%`, so skip
			// the decode and second candidate entirely in that case.
			$decoded_authority = $authority;
			if ( false !== strpos( $authority, '%' ) ) {
				$decoded_authority = rawurldecode( $authority );
			}
			$has_absolute_form = false;
			foreach ( array( $authority, $decoded_authority ) as $candidate ) {
				$candidate_trimmed = ltrim( (string) $candidate );
				if ( '' === $candidate_trimmed ) {
					continue;
				}
				if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $candidate_trimmed ) || 0 === strpos( $candidate_trimmed, '//' ) ) {
					$has_absolute_form = true;
					break;
				}
				// Double-encoded scheme remnant (e.g. `https%253A%252F%252F`
				// decodes once to `https%3A%2F%2F`): treat as absolute-form
				// for detection only so host extraction still runs.
				if ( false !== stripos( $candidate_trimmed, '%3a' ) ) {
					$has_absolute_form = true;
					break;
				}
			}
			// Parse once and reuse the parts for both host and path checks
			// (wp_parse_url re-parses the string on every component call).
			$parts = null;
			if ( $has_absolute_form && null !== $allowed_host ) {
				if ( function_exists( 'wp_parse_url' ) ) {
					$parts = wp_parse_url( $raw_input );
				} else {
					$parts = parse_url( $raw_input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( ! is_array( $parts ) ) {
					$parts = null;
				}
			}
			if ( $has_absolute_form && null !== $allowed_host ) {
				// Host-aware callers (domain context given) admit same-host
				// absolute URLs while refusing foreign-host (or hostless)
				// inputs outright, so a foreign host can never map onto the
				// local tree. Legacy callers pass null and keep the
				// historical path-only extraction below.
				// normalize_cache_host() is statically memoized, so the
				// already-canonical domain hot callers pass costs nothing
				// on repeats.
				$expected = is_string( $allowed_host ) && '' !== $allowed_host ? self::normalize_cache_host( $allowed_host ) : '';
				if ( '' === $expected ) {
					return '';
				}
				$host_raw = is_array( $parts ) ? ( $parts['host'] ?? null ) : null;
				if ( ! is_string( $host_raw ) || '' === $host_raw || self::normalize_cache_host( $host_raw ) !== $expected ) {
					return '';
				}
			}

			if ( is_array( $parts ) && array_key_exists( 'path', $parts ) ) {
				$parsed = $parts['path'];
			} elseif ( function_exists( 'wp_parse_url' ) ) {
				$parsed = wp_parse_url( $raw_input, PHP_URL_PATH );
			} else {
				$parsed = parse_url( $raw_input, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
			}

			// No path component (e.g. 'https://example.com?x=1'): refuse rather
			// than falling back to the raw input, which would let query text
			// influence the mapped cache directory.
			if ( null === $parsed || false === $parsed ) {
				return '';
			}
			$path_component = (string) $parsed;

			$decoded = rawurldecode( $path_component );

			if ( false !== strpos( $decoded, "\0" ) ) {
				return '';
			}

			if ( function_exists( 'wp_normalize_path' ) ) {
				$normalized = wp_normalize_path( trim( $decoded, '/' ) );
			} else {
				$normalized = str_replace( '\\', '/', trim( $decoded, '/' ) );
			}

			// Segment-only dot-dot check (parity with Rest): a benign
			// filename containing `..` (e.g. `my..photo.jpg`) must not be
			// over-blocked while real traversal segments still refuse.
			if ( false !== strpos( $normalized, "\0" ) || 1 === preg_match( '#(^|/)\.\.(/|$)#', $normalized ) ) {
				return '';
			}

			if ( '' !== $normalized && preg_match( '#^[a-zA-Z]:#', $normalized ) ) {
				return '';
			}

			return $normalized;
		}

		/**
		 * Whether an absolute path stays inside the cache tree.
		 *
		 * Centralized dual-prefix containment: the normalized path must start
		 * with both the cache root and the per-domain directory
		 * (trailing-slash aware so `wppo-evil` never prefix-matches `wppo`).
		 * Empty root or domain fails closed. Pure static helper: no I/O.
		 * Multisite-safe: callers pass the per-site canonical domain.
		 *
		 * @param string $cache_root_dir Absolute cache root directory.
		 * @param string $domain Canonical domain directory segment.
		 * @param string $path Absolute file or directory path to check.
		 * @return bool True when contained.
		 * @since NEXT
		 */
		public static function is_cache_path_contained( string $cache_root_dir, string $domain, string $path ): bool {
			if ( '' === $cache_root_dir || '' === $domain || '' === $path ) {
				return false;
			}
			// Fail closed on its own: a future direct caller passing
			// unsanitized input must never prefix-match through. Null bytes
			// and dot-dot segments are refused before the prefix check;
			// current callers only ever pass sanitizer-built paths, so this
			// is defense-in-depth with no benign behavior change.
			if ( false !== strpos( $path, "\0" ) ) {
				return false;
			}
			if ( function_exists( 'wp_normalize_path' ) ) {
				$norm = wp_normalize_path( $path );
				$root = wp_normalize_path( $cache_root_dir );
			} else {
				$norm = str_replace( '\\', '/', $path );
				$root = str_replace( '\\', '/', $cache_root_dir );
			}
			if ( false !== strpos( $norm, "\0" ) || false !== strpos( $norm, '..' ) ) {
				return false;
			}
			$root       = rtrim( $root, '/' ) . '/';
			$domain_dir = $root . trim( $domain, '/' ) . '/';
			return 0 === strpos( $norm, $root ) && 0 === strpos( $norm, $domain_dir );
		}

		/**
		 * Symlink-aware containment check for cache write targets.
		 *
		 * Extends {@see Filesystem::is_cache_path_contained()} with `realpath()` symlink
		 * resolution so a symlink planted inside the cache tree (e.g.
		 * `{root}/{domain}/<segment>` pointing at `/etc`, a symlinked domain
		 * directory itself, or a leaf symlink passed as a directory purge
		 * target) cannot bypass the lexical prefix check at write time
		 * (CVE-2026-18051 class). Both the full normalized target (leaf
		 * included, so leaf symlinks resolve) and the `{root}/{domain}/`
		 * anchor are resolved via {@see Filesystem::resolve_realpath()} (nearest existing
		 * ancestor plus the lexical remainder, so brand-new pages and
		 * symlinked deploy roots keep working) and the resolved target must
		 * sit under the resolved anchor, which itself must sit under the
		 * resolved root (trailing-slash aware so `wppo-evil` never
		 * prefix-matches `wppo`).
		 *
		 * Fail-open applies ONLY when nothing on disk resolves yet (brand-new
		 * page tree): then there is no symlink to follow and the lexical
		 * verdict stands. The same fail-open covers hosts where `realpath()`
		 * itself is unavailable (symlinks cannot be ruled out there, but
		 * refusing every write would break caching entirely, so the lexical
		 * verdict stands and the probe is logged by callers). Empty
		 * root/domain/path, null bytes, `..` segments, and hostile domain
		 * segments (`/`, `\`, `..`, NUL) fail closed. Unexpected throwables
		 * fail closed (skip the write, serve dynamic) while staying
		 * non-fatal. Pure static helper: no I/O beyond `realpath()`, no
		 * settings reads. Multisite-safe: callers pass the per-site
		 * canonical domain.
		 *
		 * The resolved root/anchor pair is memoized per root+domain per
		 * request (invariant across files) so purge loops pay the
		 * ancestor-walk stat cost once, not once per file.
		 *
		 * @param string $cache_root_dir Absolute cache root directory.
		 * @param string $domain Canonical domain directory segment.
		 * @param string $path Absolute file or directory path to check.
		 * @return bool True when contained.
		 * @since NEXT
		 */
		public static function is_realpath_contained( string $cache_root_dir, string $domain, string $path ): bool {
			if ( '' === $cache_root_dir || '' === $domain || '' === $path ) {
				return false;
			}
			if ( false !== strpos( $path, "\0" ) || false !== strpos( $domain, "\0" ) ) {
				return false;
			}
			// Lexical fail-closed first: unsanitized input must never pass
			// on the symlink check alone.
			if ( ! self::is_cache_path_contained( $cache_root_dir, $domain, $path ) ) {
				return false;
			}
			if ( ! function_exists( 'realpath' ) || ! function_exists( 'dirname' ) ) {
				return true;
			}
			try {
				$domain_seg = trim( $domain, '/' );
				// The domain shapes the anchor (root + domain segment), so a
				// hostile segment must fail closed here rather than relying
				// on callers passing a canonical host.
				if ( '' === $domain_seg || false !== strpos( $domain_seg, '..' ) || false !== strpos( $domain_seg, '/' ) || false !== strpos( $domain_seg, '\\' ) ) {
					return false;
				}
				if ( function_exists( 'wp_normalize_path' ) ) {
					$root_norm   = rtrim( wp_normalize_path( $cache_root_dir ), '/' );
					$target_norm = wp_normalize_path( $path );
				} else {
					$root_norm   = rtrim( str_replace( '\\', '/', $cache_root_dir ), '/' );
					$target_norm = str_replace( '\\', '/', $path );
				}
				// Memoized anchor/root pair (per root+domain per request).
				static $anchor_memo = array();
				$memo_key           = $root_norm . "\0" . $domain_seg;
				if ( ! isset( $anchor_memo[ $memo_key ] ) ) {
					// Resolved anchor: the on-disk domain directory when it
					// exists (catches a symlinked domain dir), else the
					// resolved root plus the lexical domain segment (nothing
					// exists to symlink yet).
					$anchor = self::resolve_realpath( $root_norm . '/' . $domain_seg );
					if ( null === $anchor ) {
						$root_resolved = self::resolve_realpath( $root_norm );
						if ( null === $root_resolved ) {
							$anchor_memo[ $memo_key ] = null;
						} else {
							$anchor_memo[ $memo_key ] = array(
								rtrim( $root_resolved, '/' ) . '/' . $domain_seg,
								$root_resolved,
							);
						}
					} else {
						$anchor_memo[ $memo_key ] = array(
							$anchor,
							self::resolve_realpath( $root_norm ),
						);
					}
				}
				$memo = $anchor_memo[ $memo_key ];
				if ( null === $memo ) {
					// Nothing on disk resolves yet: no symlink to follow,
					// so the lexical verdict stands.
					return true;
				}
				list( $anchor, $root_resolved ) = $memo;
				if ( null === $root_resolved ) {
					return true;
				}
				// The anchor itself must live under the resolved root: a
				// symlinked domain directory pointing outside fails closed.
				$anchor_dir = rtrim( $anchor, '/' ) . '/';
				$root_dir   = rtrim( $root_resolved, '/' ) . '/';
				if ( 0 !== strpos( $anchor_dir, $root_dir ) ) {
					return false;
				}
				// Resolved target: the full normalized target first, so a
				// leaf symlink (e.g. `{root}/{domain}/evil-dir -> /etc`
				// passed as a directory purge target, or a trailing-slash
				// directory target whose dirname() would otherwise drop to
				// the parent) resolves and fails closed. Falls back to the
				// target directory for not-yet-existing file leaves.
				$target_resolved = self::resolve_realpath( rtrim( $target_norm, '/' ) );
				if ( null === $target_resolved && function_exists( 'dirname' ) ) {
					$target_resolved = self::resolve_realpath( dirname( $target_norm ) );
				}
				if ( null === $target_resolved ) {
					return true;
				}
				$target_dir_slash = rtrim( $target_resolved, '/' ) . '/';
				return 0 === strpos( $target_dir_slash, $anchor_dir );
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fail closed for security (skip the write, serve dynamic)
				// while staying non-fatal for availability.
				return false;
			}
		}

		/**
		 * Resolve a path via realpath(), keeping the lexical remainder.
		 *
		 * `realpath()` returns false for paths that do not (fully) exist
		 * yet — e.g. a brand-new page directory. This helper walks up to
		 * the nearest existing ancestor, resolves it, and re-appends the
		 * non-existing remainder lexically, so symlinked deploy roots keep
		 * resolving consistently while symlink escapes inside the tree are
		 * still exposed. Returns null when nothing on disk resolves (or
		 * `realpath()` is unavailable), in which case callers fall back to
		 * the lexical verdict. The walk is bounded (64 levels) so
		 * `dirname( '/' ) === '/'` cannot loop forever.
		 *
		 * @param string $lexical_path Normalized absolute path to resolve.
		 * @return string|null Resolved absolute path, or null when unresolvable.
		 * @since NEXT
		 */
		public static function resolve_realpath( string $lexical_path ): ?string {
			if ( '' === $lexical_path || ! function_exists( 'realpath' ) || ! function_exists( 'dirname' ) ) {
				return null;
			}
			try {
				$candidate = $lexical_path;
				$remainder = array();
				$depth     = 0;
				while ( '' !== $candidate && $depth < 64 ) {
					++$depth;
					$resolved = realpath( $candidate );
					if ( false !== $resolved && is_string( $resolved ) && '' !== $resolved ) {
						if ( function_exists( 'wp_normalize_path' ) ) {
							$resolved = wp_normalize_path( $resolved );
						} else {
							$resolved = str_replace( '\\', '/', $resolved );
						}
						if ( array() !== $remainder ) {
							$resolved = rtrim( $resolved, '/' ) . '/' . implode( '/', $remainder );
						}
						return $resolved;
					}
					$parent = dirname( $candidate );
					if ( $parent === $candidate ) {
						return null;
					}
					$segment = substr( $candidate, strlen( $parent ) );
					$segment = ltrim( $segment, '/' );
					if ( '' !== $segment ) {
						array_unshift( $remainder, $segment );
					}
					$candidate = $parent;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
			return null;
		}

		/**
		 * Single-call validator for absolute cache write targets.
		 *
		 * Combines traversal-payload rejection (null bytes, `..` segments)
		 * with lexical ({@see Filesystem::is_cache_path_contained()}) and symlink-aware
		 * ({@see Filesystem::is_realpath_contained()}) containment. The path is absolute
		 * by contract, so drive/UNC/protocol-relative *inputs* are refused
		 * earlier at the sanitize layer; here any target failing containment
		 * fails closed. Callers skip the write and serve dynamically
		 * uncached on false.
		 *
		 * @param string $cache_root_dir Absolute cache root directory.
		 * @param string $domain Canonical domain directory segment.
		 * @param string $path Absolute file path to validate.
		 * @return bool True when the target may be written.
		 * @since NEXT
		 */
		public static function validate_cache_write_path( string $cache_root_dir, string $domain, string $path ): bool {
			if ( '' === $cache_root_dir || '' === $domain || '' === $path ) {
				return false;
			}
			if ( false !== strpos( $path, "\0" ) || false !== strpos( $path, '..' ) ) {
				return false;
			}
			if ( ! self::is_cache_path_contained( $cache_root_dir, $domain, $path ) ) {
				return false;
			}
			return self::is_realpath_contained( $cache_root_dir, $domain, $path );
		}

		/**
		 * Whether an .htaccess target may be written by the plugin.
		 *
		 * Isolation guard keeping htaccess writes out of reach of
		 * cache-path resolution: the basename must be exactly `.htaccess`
		 * and the target must never sit inside the static cache tree
		 * (`{WP_CONTENT_DIR}/cache/wppo/`), lexically or via symlink
		 * resolution (a symlinked `.htaccess` or a symlinked parent inside
		 * the resolved write path resolving into the cache tree fails
		 * closed — same CVE class as the cache write path). Null bytes and
		 * `..` segments fail closed. Fail-open uncached semantics belong to
		 * the caller: false means "do not touch the filesystem".
		 *
		 * @param string $htaccess_file Absolute .htaccess path candidate.
		 * @return bool True when the target may be written.
		 * @since NEXT
		 */
		public static function is_htaccess_path_allowed( string $htaccess_file ): bool {
			if ( '' === $htaccess_file ) {
				return false;
			}
			if ( false !== strpos( $htaccess_file, "\0" ) || false !== strpos( $htaccess_file, '..' ) ) {
				return false;
			}
			try {
				$base = basename( $htaccess_file );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			if ( '.htaccess' !== $base ) {
				return false;
			}
			try {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$norm = wp_normalize_path( $htaccess_file );
				} else {
					$norm = str_replace( '\\', '/', $htaccess_file );
				}
				$cache_root = null;
				if ( defined( 'WP_CONTENT_DIR' ) ) {
					if ( function_exists( 'wp_normalize_path' ) ) {
						$cache_root = rtrim( wp_normalize_path( (string) WP_CONTENT_DIR ), '/' ) . '/cache/wppo/';
					} else {
						$cache_root = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/cache/wppo/';
					}
					if ( 0 === strpos( $norm, $cache_root ) ) {
						return false;
					}
				} elseif ( false !== strpos( $norm, '/cache/wppo/' ) ) {
					return false;
				}
				// Symlink-aware isolation: the resolved parent directory and
				// the resolved leaf itself must not land inside the resolved
				// cache root (lexical check above cannot see through
				// symlinks). Unresolvable paths keep the lexical verdict.
				if ( null !== $cache_root && function_exists( 'dirname' ) ) {
					$cache_resolved = self::resolve_realpath( rtrim( $cache_root, '/' ) );
					if ( null !== $cache_resolved ) {
						$cache_dir       = rtrim( $cache_resolved, '/' ) . '/';
						$candidates      = array();
						$parent_resolved = self::resolve_realpath( dirname( $norm ) );
						if ( null !== $parent_resolved ) {
							$candidates[] = rtrim( $parent_resolved, '/' ) . '/';
						}
						$leaf_resolved = self::resolve_realpath( rtrim( $norm, '/' ) );
						if ( null !== $leaf_resolved ) {
							$candidates[] = rtrim( $leaf_resolved, '/' ) . '/';
							$candidates[] = rtrim( dirname( $leaf_resolved ), '/' ) . '/';
						}
						foreach ( $candidates as $candidate ) {
							if ( 0 === strpos( $candidate, $cache_dir ) ) {
								return false;
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return true;
		}

		/**
		 * Build a contained absolute cache file path from its parts.
		 *
		 * Single auditable containment point for the static HTML cache and
		 * the per-page used-CSS surface: normalizes the host via
		 * {@see Filesystem::normalize_cache_host()}, sanitizes the path via
		 * {@see Filesystem::sanitize_cache_url_path()} (single-decode semantics —
		 * `%252e` stays literal, single-encoded `%2e%2e` / `%00` decode once
		 * and are rejected), refuses absolute-form inputs (scheme `://`,
		 * protocol-relative `//host`, drive `C:`, UNC `\\`, detected on the
		 * authority part before `?`/`#` so query strings carrying URLs never
		 * false-positive) and foreign-host absolute URLs, allowlists the file
		 * name, then enforces dual-prefix containment before returning.
		 * Returns an empty string on any failure; callers fail open (serve
		 * dynamic/uncached, log a probe).
		 *
		 * Pure static helper: no I/O, no settings reads. Multisite-safe: the
		 * per-site canonical domain is passed explicitly, so a secondary
		 * site can never address the primary site tree.
		 *
		 * @param string      $cache_root_dir Absolute cache root directory.
		 * @param string      $domain Canonical domain directory segment.
		 * @param string|null $url_path_or_url Raw URL path or URL.
		 * @param string      $filename File name (e.g. `index.html`).
		 * @return string Contained absolute path, or '' when refused.
		 * @since NEXT
		 */
		public static function sanitize_cache_path( string $cache_root_dir, string $domain, $url_path_or_url, string $filename ): string {
			if ( '' === $cache_root_dir || '' === $filename ) {
				return '';
			}
			$domain = self::normalize_cache_host( $domain );
			if ( '' === $domain ) {
				return '';
			}
			if ( false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) || false !== strpos( $filename, "\0" ) || false !== strpos( $filename, '..' ) ) {
				return '';
			}
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $filename ) || strlen( $filename ) > 64 ) {
				return '';
			}
			$raw_input = (string) $url_path_or_url;
			$trimmed   = ltrim( $raw_input );
			// Inspect only the authority part (before `?`/`#`), matching the
			// Cache constructor: a benign relative path whose query/fragment
			// carries a URL (e.g. `/search?redirect=https://other`) must not
			// be misread as an absolute-form target. The split uses strcspn()
			// (no process-global strtok() state).
			$authority = substr( $trimmed, 0, strcspn( $trimmed, '?#' ) );
			// Scheme-anchored absolute-form test (parity with
			// sanitize_cache_url_path() / Cache::__construct): a benign
			// relative path containing `://` in a segment (e.g.
			// `/foo/a://b`) must not misclassify as absolute-form.
			$authority_trimmed = ltrim( $authority );
			if ( '' !== $authority_trimmed && ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $authority_trimmed ) || 0 === strpos( $authority_trimmed, '//' ) || 0 === strpos( $authority_trimmed, '\\\\' ) || (bool) preg_match( '#^[a-zA-Z]:#', $authority_trimmed ) ) ) {
				// Absolute-form target: only same-host absolute URLs may map
				// to a path; drive/UNC/protocol-relative/foreign-host inputs
				// are refused outright.
				$host_raw = null;
				if ( function_exists( 'wp_parse_url' ) ) {
					$host_raw = wp_parse_url( $raw_input, PHP_URL_HOST );
				} else {
					$host_raw = parse_url( $raw_input, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( is_string( $host_raw ) && '' !== $host_raw ) {
					if ( self::normalize_cache_host( $host_raw ) !== $domain ) {
						return '';
					}
				} else {
					return '';
				}
			}
			$path = self::sanitize_cache_url_path( $raw_input, $domain );
			if ( '' === $path ) {
				$component = null;
				if ( function_exists( 'wp_parse_url' ) ) {
					$component = wp_parse_url( $raw_input, PHP_URL_PATH );
				} else {
					$component = parse_url( $raw_input, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( null === $component || false === $component ) {
					$component = $raw_input;
				}
				if ( '' !== trim( (string) $component, " \t\n\r\0\x0B/" ) ) {
					return '';
				}
			}
			if ( function_exists( 'wp_normalize_path' ) ) {
				$root = wp_normalize_path( $cache_root_dir );
			} else {
				$root = str_replace( '\\', '/', $cache_root_dir );
			}
			$root     = rtrim( $root, '/' );
			$resolved = '' === $path ? "{$root}/{$domain}/{$filename}" : "{$root}/{$domain}/{$path}/{$filename}";
			if ( ! self::is_cache_path_contained( $root, $domain, $resolved ) ) {
				return '';
			}
			return $resolved;
		}

		/**
		 * Build a unique sibling tmp path for atomic writes.
		 *
		 * Wide rand range plus PID/uniqid segments so concurrent writers
		 * never share a tmp name. Uses `wp_rand()` when available with an
		 * `mt_rand()` fallback for very old WP.
		 *
		 * @param string $final_path Final file path the tmp sits beside.
		 * @return string Tmp sibling path ('' when input is empty).
		 * @since NEXT
		 */
		public static function atomic_tmp_path( string $final_path ): string {
			if ( '' === $final_path ) {
				return '';
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback only when wp_rand() is unavailable; uniqueness is all that is needed for the tmp suffix.
			$rand_suffix = mt_rand( 1000000, 9999999 );
			if ( function_exists( 'wp_rand' ) ) {
				try {
					$rand_suffix = wp_rand( 1000000, 9999999 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$pid_part  = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
			$uniq_part = substr( md5( uniqid( (string) microtime( true ), true ) ), 0, 8 );
			return $final_path . '.tmp.' . $rand_suffix . '-' . $pid_part . '-' . $uniq_part;
		}

		/**
		 * Atomically write contents via tmp-file + rename.
		 *
		 * Writes to a unique sibling tmp file in the same directory and then
		 * moves it over the final path, so interrupted writes never leave
		 * partial output behind. A failed move cleans up the tmp file and
		 * reports failure — there is intentionally no non-atomic direct-write
		 * fallback, so readers can never observe a torn file.
		 *
		 * @param mixed  $fs Filesystem object exposing put_contents()/move()/delete().
		 * @param string $path Final file path.
		 * @param string $contents File contents.
		 * @return bool True on success.
		 * @since NEXT
		 */
		public static function atomic_file_put_contents( $fs, string $path, string $contents ): bool {
			if ( '' === $path || ! is_object( $fs ) || ! method_exists( $fs, 'put_contents' ) || ! method_exists( $fs, 'move' ) || ! method_exists( $fs, 'delete' ) ) {
				return false;
			}
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			$tmp   = self::atomic_tmp_path( $path );
			if ( '' === $tmp ) {
				return false;
			}
			try {
				if ( ! $fs->put_contents( $tmp, $contents, $chmod ) ) {
					$fs->delete( $tmp );
					return false;
				}
				$moved = (bool) $fs->move( $tmp, $path, true );
				if ( ! $moved ) {
					$fs->delete( $tmp );
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					$fs->delete( $tmp );
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort tmp cleanup must never throw.
				}
				return false;
			}
			return true;
		}

		/**
		 * Check that PHP code parses without a syntax error.
		 *
		 * Layered, fully guarded so it never fatals on any supported
		 * WP/PHP combination:
		 * - Layer 1: `PhpToken::tokenize()` when the class exists (throws
		 *   `ParseError` on broken syntax).
		 * - Layer 2: optional `php -l` against a sibling tmp file, only when
		 *   an exec function exists, is not disabled, and `PHP_BINARY` is
		 *   defined. Never lints the live file. Gated behind the
		 *   `wppo_allow_php_lint` filter (default true) so hardened hosts
		 *   can disable the system call.
		 * - Layer 3 (always): the code must open with `<?php`.
		 *
		 * @param string $code PHP source to check.
		 * @param string $tmp_file_for_lint Optional tmp file holding $code for `php -l`.
		 * @return bool True when the code looks parseable.
		 * @since NEXT
		 */
		public static function verify_php_syntax( string $code, string $tmp_file_for_lint = '' ): bool {
			if ( '' === $code ) {
				return false;
			}
			$stripped = ltrim( $code );
			$stripped = preg_replace( "/^\xEF\xBB\xBF/", '', $stripped );
			if ( ! is_string( $stripped ) || 0 !== strpos( $stripped, '<?php' ) ) {
				return false;
			}
			if ( class_exists( 'PhpToken' ) ) {
				try {
					$tokens = \PhpToken::tokenize( $code );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
				// PhpToken::tokenize() is lenient (it lexes, not parses), so a
				// truncated file with unbalanced brackets would still lex fine.
				// Count structural brackets outside strings/comments/HTML to
				// catch torn writes that lexing alone would miss.
				if ( ! self::php_brackets_balanced( $tokens ) ) {
					return false;
				}
			} elseif ( function_exists( 'token_get_all' ) ) {
				try {
					$legacy = token_get_all( $code );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
				if ( ! self::php_brackets_balanced( $legacy ) ) {
					return false;
				}
			}
			// Optional `php -l` against the on-disk tmp file only (never the
			// live file). Skipped when the tmp path is not a real readable
			// file — e.g. under WP_Filesystem transports or unit-test mocks.
			// Audit #1490: the exec() path is gated behind wppo_allow_php_lint
			// (default true) so locked-down hosts can disable system calls
			// entirely; the token-based bracket check above stays primary.
			$allow_lint = true;
			if ( function_exists( 'apply_filters' ) ) {
				/** This filter is documented in docs/hooks.md. */
				$allow_lint = (bool) apply_filters( 'wppo_allow_php_lint', true );
			}
			if ( $allow_lint && '' !== $tmp_file_for_lint && function_exists( 'escapeshellarg' ) && defined( 'PHP_BINARY' ) && '' !== (string) constant( 'PHP_BINARY' ) ) {
				$disabled = '';
				if ( function_exists( 'ini_get' ) ) {
					$disabled = (string) ini_get( 'disable_functions' );
				}
				$disabled_list = array_map( 'trim', explode( ',', strtolower( (string) $disabled ) ) );
				$exec_disabled = in_array( 'exec', $disabled_list, true );
				if ( function_exists( 'exec' ) && ! $exec_disabled && is_readable( $tmp_file_for_lint ) ) {
					try {
						$binary = (string) constant( 'PHP_BINARY' );
						// Audit #1329: both parts escapeshellarg()'d; never
						// interpolate unescaped variables here (RCE risk).
						$cmd    = escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $tmp_file_for_lint ) . ' 2>&1';
						$output = array();
						$rc     = 1;
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Guarded php -l syntax check on the tmp file only; never the live file.
						exec( $cmd, $output, $rc );
						if ( 0 !== (int) $rc ) {
							return false;
						}
						$joined = implode( "\n", $output );
						if ( false === stripos( $joined, 'No syntax errors' ) ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
			}
			return true;
		}

		/**
		 * Check that structural brackets are balanced in a token stream.
		 *
		 * Counts `(`, `)`, `{`, `}`, `[`, `]` from single-char tokens only, so
		 * brackets inside strings, comments, and inline HTML are ignored. A
		 * torn write (e.g. a file cut mid-statement) leaves the counts
		 * unbalanced and is rejected.
		 *
		 * Moved with its sole caller from `Util` (REF-003).
		 *
		 * @param array $tokens Token stream from `PhpToken::tokenize()`.
		 * @return bool True when every bracket type is balanced and ordered.
		 * @since NEXT
		 */
		private static function php_brackets_balanced( array $tokens ): bool {
			$pairs = array(
				'(' => ')',
				'{' => '}',
				'[' => ']',
			);
			$stack = array();
			foreach ( $tokens as $token ) {
				$id = null;
				if ( $token instanceof \PhpToken ) {
					$id = $token->id;
				} elseif ( is_array( $token ) && isset( $token[0] ) ) {
					$id = $token[0];
				}
				if ( null !== $id && ( ( defined( 'T_CURLY_OPEN' ) && T_CURLY_OPEN === $id ) || ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $id ) ) ) {
					$stack[] = '{';
					continue;
				}
				$text = $token instanceof \PhpToken ? $token->text : ( is_string( $token ) ? $token : '' );
				if ( 1 !== strlen( $text ) || ( ! isset( $pairs[ $text ] ) && ! in_array( $text, $pairs, true ) ) ) {
					continue;
				}
				if ( isset( $pairs[ $text ] ) ) {
					$stack[] = $text;
					continue;
				}
				$last = array_pop( $stack );
				if ( null === $last || ! isset( $pairs[ $last ] ) || $pairs[ $last ] !== $text ) {
					return false;
				}
			}
			return array() === $stack;
		}

		/**
		 * Atomically write PHP source with syntax verification and rollback.
		 *
		 * Writes `$contents` to a unique sibling tmp file (same directory, so
		 * rename is atomic), verifies the tmp (byte-identical re-read, caller
		 * `$expect` assertion, PHP-parse check), keeps one best-effort backup
		 * generation (`$path . '.wppo-bak'`), renames over the live file, then
		 * re-reads and re-verifies the live file. On post-rename verification
		 * failure the backup (or the in-memory original) is restored so the
		 * live file is never left truncated. On any tmp/rename failure the tmp
		 * is cleaned and the live file is untouched.
		 *
		 * Returns null when the filesystem transport cannot support atomic
		 * writes (missing methods) so callers can fall back to the legacy
		 * direct-write path. Multisite-safe: only touches the single
		 * `wp-config.php` path passed in; no per-site option writes.
		 *
		 * @param mixed         $fs Filesystem object exposing exists()/get_contents()/put_contents()/move()/copy()/delete().
		 * @param string        $path Final file path.
		 * @param string        $contents New file contents.
		 * @param callable|null $expect Optional assertion receiving contents, returning bool.
		 * @return bool|null True on verified success, false on verified failure, null when unsupported.
		 * @since NEXT
		 */
		public static function atomic_write_php_verified( $fs, string $path, string $contents, $expect = null ): ?bool {
			$required = array( 'exists', 'get_contents', 'put_contents', 'move', 'copy', 'delete' );
			foreach ( $required as $method ) {
				if ( ! is_object( $fs ) || ! method_exists( $fs, $method ) ) {
					return null;
				}
			}
			if ( '' === $path || '' === $contents ) {
				return false;
			}
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			try {
				$original = '';
				if ( $fs->exists( $path ) ) {
					$original = $fs->get_contents( $path );
					if ( ! is_string( $original ) ) {
						return false;
					}
				}
				$tmp = self::atomic_tmp_path( $path );
				if ( '' === $tmp ) {
					return false;
				}
				if ( ! $fs->put_contents( $tmp, $contents, $chmod ) ) {
					$fs->delete( $tmp );
					return false;
				}
				$tmp_read = $fs->get_contents( $tmp );
				if ( ! is_string( $tmp_read ) || $tmp_read !== $contents ) {
					$fs->delete( $tmp );
					return false;
				}
				if ( null !== $expect && ! call_user_func( $expect, $tmp_read ) ) {
					$fs->delete( $tmp );
					return false;
				}
				if ( ! self::verify_php_syntax( $tmp_read, $tmp ) ) {
					$fs->delete( $tmp );
					return false;
				}
				if ( '' !== $original ) {
					// Best-effort single backup generation; in-memory
					// $original remains the authoritative restore source.
					$fs->copy( $path, $path . '.wppo-bak', true );
				}
				if ( ! $fs->move( $tmp, $path, true ) ) {
					$fs->delete( $tmp );
					self::delete_php_backup( $fs, $path );
					return false;
				}
				$written = $fs->get_contents( $path );
				if ( ! is_string( $written ) || $written !== $contents ) {
					self::restore_php_backup( $fs, $path, $original, $chmod );
					self::delete_php_backup( $fs, $path );
					$fs->delete( $tmp );
					return false;
				}
				if ( null !== $expect && ! call_user_func( $expect, $written ) ) {
					self::restore_php_backup( $fs, $path, $original, $chmod );
					self::delete_php_backup( $fs, $path );
					$fs->delete( $tmp );
					return false;
				}
				if ( ! self::verify_php_syntax( $written ) ) {
					self::restore_php_backup( $fs, $path, $original, $chmod );
					self::delete_php_backup( $fs, $path );
					$fs->delete( $tmp );
					return false;
				}
				$fs->delete( $tmp );
				// The backup holds full wp-config.php secrets beside the live
				// file in the web root, so remove it once the rename verified.
				// Best-effort: success stands even if the delete fails.
				try {
					$fs->delete( $path . '.wppo-bak' );
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort backup cleanup must never throw.
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					if ( isset( $original ) && is_string( $original ) && '' !== $original ) {
						self::restore_php_backup( $fs, $path, $original, $chmod );
					}
				} catch ( \Throwable $ignored_restore ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort restore must never throw.
				}
				try {
					if ( isset( $tmp ) && is_string( $tmp ) && '' !== $tmp && $fs->exists( $tmp ) ) {
						$fs->delete( $tmp );
					}
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort tmp cleanup must never throw.
				}
				self::delete_php_backup( $fs, $path );
				return false;
			}
		}

		/**
		 * Restore a PHP file from its in-memory original or `.wppo-bak` backup.
		 *
		 * Prefers the in-memory original (the known-good read from this
		 * invocation); falls back to the on-disk backup copy, which may be
		 * stale from a previous run or concurrent writer. Best-effort: never throws.
		 *
		 * Moved with its sole caller from `Util` (REF-003).
		 *
		 * @param mixed  $fs Filesystem object.
		 * @param string $path Final file path.
		 * @param string $original In-memory original contents ('' when none).
		 * @param int    $chmod File mode for a direct-write restore.
		 * @return bool True when a restore write/copy was issued.
		 * @since NEXT
		 */
		private static function restore_php_backup( $fs, string $path, string $original, int $chmod ): bool {
			try {
				if ( '' !== $original ) {
					if ( $fs->put_contents( $path, $original, $chmod ) ) {
						// In-memory restore won: the on-disk backup is stale
						// secrets beside the live file — remove it best-effort.
						self::delete_php_backup( $fs, $path );
						return true;
					}
				}
				if ( $fs->exists( $path . '.wppo-bak' ) ) {
					if ( $fs->copy( $path . '.wppo-bak', $path, true ) ) {
						// Fallback restore won: the backup copy is now
						// redundant — remove it so secrets never linger.
						self::delete_php_backup( $fs, $path );
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Best-effort deletion of the `.wppo-bak` backup beside a PHP file.
		 *
		 * The backup can hold full wp-config.php secrets (DB credentials +
		 * salts) in a web-readable location, so every failure/restore path in
		 * atomic_write_php_verified() must call this. Never throws.
		 *
		 * Moved with its callers from `Util` (REF-003).
		 *
		 * @param mixed  $fs Filesystem object.
		 * @param string $path Final file path (backup is `$path.wppo-bak`).
		 * @return void
		 * @since NEXT
		 */
		private static function delete_php_backup( $fs, string $path ): void {
			try {
				$fs->delete( $path . '.wppo-bak' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Home URL for local-path mapping (no-arg form of Util::cached_home_url()).
		 *
		 * Mirrored from `Util::cached_home_url()` by design (decoupling — the
		 * home-URL memo stays owned by `Util`); keep in sync. Intentionally
		 * unmemoized: `home_url()` resolves via the per-request option cache
		 * in core, so values match the memoized owner on every stable stub
		 * while this class carries no new state.
		 *
		 * @since NEXT
		 * @return string Untrailed home URL, or '' when unavailable.
		 */
		private static function home_url_for_local_path(): string {
			if ( function_exists( 'has_filter' ) && false !== has_filter( 'home_url' ) ) {
				return untrailingslashit( home_url() );
			}
			if ( ! function_exists( 'home_url' ) ) {
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
			return untrailingslashit( $home );
		}

		/**
		 * Current site's blog-scoped minify cache directory.
		 *
		 * Mirrored from `Util::min_cache_dir()` / `Util::min_cache_base_dir()`
		 * by design (decoupling — the asset-dir helpers stay owned by `Util`);
		 * keep in sync.
		 *
		 * @since NEXT
		 * @return string Normalized absolute path to the site-scoped min cache dir.
		 */
		private static function min_cache_dir_for_counts(): string {
			$dir = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min' ) . '/' . get_current_blog_id();
			return wp_normalize_path( $dir );
		}
	}
}
