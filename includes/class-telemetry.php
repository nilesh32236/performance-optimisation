<?php
/**
 * Telemetry Class
 *
 * Performs local HTTP-based page analysis. Uses raw cURL when available for
 * granular network timings (DNS, connect, SSL, true TTFB) and automatic
 * gzip/brotli decoding. Falls back to wp_remote_get() when cURL is absent.
 * HTML parsing uses WP_HTML_Tag_Processor (WP 6.2+) with a regex fallback.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.5.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Telemetry' ) ) {

	/**
	 * Class Telemetry
	 *
	 * Scans a URL and returns a structured array of performance metrics including
	 * load time, TTFB, DNS/connect/SSL timings, resource counts, asset sizes,
	 * lazy-load breakdown, compression status, and more.
	 *
	 * @since 1.5.0
	 */
	class Telemetry {

		/**
		 * Scan a URL and return all performance metrics.
		 *
		 * Checks the transient cache first. On a cache miss, fetches the page,
		 * parses the HTML, calculates sizes using local filesystem paths, and
		 * stores the result as a transient.
		 *
		 * @since  1.5.0
		 * @param  string $url       The URL to scan.
		 * @param  string $scan_type Either 'manual' or 'scheduled'.
		 * @param  bool   $force     Whether to bypass cache.
		 * @return array|\WP_Error   Associative array of metrics, or WP_Error on failure.
		 */
		public static function scan( string $url, string $scan_type = 'manual', bool $force = false ): array|\WP_Error {
			$transient_key = 'wppo_audit_' . md5( $url );
			$cached        = get_transient( $transient_key );

			if ( ! $force && false !== $cached ) {
				$cached['is_cached'] = true;
				return $cached;
			}

			// SSRF protection: validate URL before making any network request.
			if ( ! wp_http_validate_url( $url ) ) {
				return new \WP_Error( 'invalid_url', __( 'The provided URL is not allowed.', 'performance-optimisation' ) );
			}
			$parsed_url = wp_parse_url( $url );
			$scheme     = $parsed_url['scheme'] ?? '';
			if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
				return new \WP_Error( 'invalid_url', __( 'Only http and https URLs are allowed.', 'performance-optimisation' ) );
			}

			// SSRF protection: validate that the URL belongs to this website.
			$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
			if ( ( $parsed_url['host'] ?? '' ) !== $home_host ) {
				return new \WP_Error( 'invalid_url', __( 'You can only scan URLs belonging to this website.', 'performance-optimisation' ) );
			}

			$body      = '';
			$headers   = array();
			$timings   = array();
			$load_time = 0;

			// --- Primary fetch: raw cURL for granular network timings ---
			// cURL is used here intentionally because wp_remote_get() does not expose
			// DNS/connect/SSL timing data and does not support automatic content-encoding
			// decoding (CURLOPT_ENCODING), which is required to parse gzip-compressed HTML.
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_setopt
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_exec
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_getinfo
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_errno
			// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_close
			if ( function_exists( 'curl_init' ) ) {
				$ch = curl_init();
				curl_setopt( $ch, CURLOPT_URL, $url );
				curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
				curl_setopt( $ch, CURLOPT_HEADER, true );
				curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
				curl_setopt( $ch, CURLOPT_ENCODING, '' ); // Auto-decode gzip/brotli/deflate.
				curl_setopt( $ch, CURLOPT_MAXREDIRS, 2 );
				curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
				curl_setopt(
					$ch,
					CURLOPT_USERAGENT,
					'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url()
				);
				// SSL verification enabled by default; filterable for local/dev environments.
				$verify_ssl = (bool) apply_filters( 'wppo_telemetry_verify_ssl', true, $url );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $verify_ssl );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $verify_ssl ? 2 : 0 );
				// Restrict to HTTP/HTTPS only — prevent file://, ftp://, etc.
				if ( defined( 'CURLPROTO_HTTP' ) && defined( 'CURLPROTO_HTTPS' ) ) {
					curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
					curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS );
				}

				$raw_response = curl_exec( $ch );
				$info         = curl_getinfo( $ch );
				$curl_error   = curl_errno( $ch );
				// curl_close() is a no-op in PHP 8.0+ but calling it is harmless and
				// keeps compatibility with PHP 7.4 where it still frees resources.
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated
				curl_close( $ch );

				if ( ! $curl_error && 200 === (int) $info['http_code'] && $raw_response ) {
					$header_size = (int) $info['header_size'];
					$header_raw  = substr( $raw_response, 0, $header_size );
					$body        = substr( $raw_response, $header_size );

					// Parse only the final set of headers (after any redirects).
					$header_blocks = explode( "\r\n\r\n", trim( $header_raw ) );
					$last_block    = end( $header_blocks );

					foreach ( explode( "\r\n", $last_block ) as $line ) {
						$parts = explode( ':', $line, 2 );
						if ( 2 === count( $parts ) ) {
							$headers[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
						}
					}

					// Precise network timings from cURL info struct.
					$timings   = array(
						'dns'              => round( $info['namelookup_time'] * 1000, 2 ),
						'connect'          => round( ( $info['connect_time'] - $info['namelookup_time'] ) * 1000, 2 ),
						'ssl'              => ( isset( $info['appconnect_time'] ) && $info['appconnect_time'] > 0 )
							? round( ( $info['appconnect_time'] - $info['connect_time'] ) * 1000, 2 )
							: 0,
						'ttfb'             => round( $info['starttransfer_time'] * 1000, 2 ), // True TTFB.
						'server_wait_time' => round( ( $info['starttransfer_time'] - $info['pretransfer_time'] ) * 1000, 2 ),
						'total'            => round( $info['total_time'] * 1000, 2 ),
					);
					$load_time = round( $info['total_time'], 2 );
				}
			}
			// phpcs:enable

			// --- Fallback: wp_remote_get() when cURL is unavailable or failed ---
			if ( empty( $body ) ) {
				$start    = microtime( true );
				$response = wp_remote_get(
					$url,
					array(
						'timeout'    => 30,
						'user-agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
						'sslverify'  => (bool) apply_filters( 'wppo_telemetry_verify_ssl', true, $url ),
					)
				);

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				$response_code = (int) wp_remote_retrieve_response_code( $response );
				if ( 200 !== $response_code ) {
					return new \WP_Error(
						'scan_failed',
						sprintf(
							/* translators: 1: HTTP status code, 2: URL */
							__( 'HTTP %1$d returned for %2$s', 'performance-optimisation' ),
							$response_code,
							esc_url( $url )
						)
					);
				}

				$load_time = round( microtime( true ) - $start, 2 );
				$body      = wp_remote_retrieve_body( $response );
				$headers   = wp_remote_retrieve_headers( $response );

				// Populate synthetic TTFB for fallback path to avoid second request.
				$timings['ttfb'] = $load_time * 1000;
			}

			$resources    = self::parse_resources( $body );
			$sizes        = self::calculate_sizes( $resources );
			$lazy_images  = array_filter( $resources['images'], fn( $img ) => true === $img['lazy'] );
			$eager_images = array_filter( $resources['images'], fn( $img ) => false === $img['lazy'] );

			$result = array(
				'page_url'                  => esc_url( $url ),
				'load_time'                 => $load_time,
				'ttfb'                      => $timings['ttfb'] ?? 0,
				'server_wait_time'          => $timings['server_wait_time'] ?? 0,
				'dns_lookup_time'           => $timings['dns'] ?? 0,
				'connect_time'              => $timings['connect'] ?? 0,
				'ssl_time'                  => $timings['ssl'] ?? 0,
				'css_count'                 => count( $resources['css'] ),
				'js_count'                  => count( $resources['js'] ),
				'media_count'               => count( $resources['images'] ),
				'lazy_image_count'          => count( $lazy_images ),
				'eager_image_count'         => count( $eager_images ),
				'css_total_size'            => $sizes['css'],
				'js_total_size'             => $sizes['js'],
				'media_total_size'          => $sizes['images'],
				'total_size'                => $sizes['css'] + $sizes['js'] + $sizes['images'],
				// Boolean/enum values — locale-independent so frontend comparisons work on any language.
				'uses_https'                => self::check_https( $url ),
				'uses_modern_image_formats' => self::check_modern_images( $resources['images'] ),
				'image_alt_attributes'      => self::check_alt_attributes( $resources['images'] ),
				'robots_txt_exists'         => self::check_robots_txt( $url ),
				'gzip_brotli_compression'   => self::check_compression( $headers ),
				'compression_value'         => self::get_compression_type( $headers ),
				'cache_control_headers'     => self::check_cache_control( $headers ),
				'cache_control_value'       => self::get_cache_control( $headers ),
				'scan_type'                 => $scan_type,
				// New metrics (Phase 1 refinements).
				'dom_size'                  => $resources['dom_size'],
				'unminified_assets_count'   => $resources['unminified_count'],
				'third_party_scripts_count' => $resources['third_party_count'],
				'is_cached'                 => false,
			);

			set_transient( $transient_key, $result, HOUR_IN_SECONDS );
			self::register_transient_key( $transient_key );

			return $result;
		}

		/**
		 * Parse CSS, JS, and image resources from an HTML string.
		 *
		 * Uses WP_HTML_Tag_Processor on WordPress 6.2+ for accurate, false-positive-free
		 * parsing. Falls back to preg_match_all() on older WordPress versions.
		 *
		 * Each image entry tracks whether it is lazy-loaded:
		 * - data-src present → lazy (WPPO lazy-load pattern)
		 * - loading="lazy" present → lazy (native browser lazy-load)
		 * - wppo-src present → delayed script (WPPO delay JS pattern)
		 *
		 * @since  1.5.0
		 * @param  string $html The raw HTML body to parse.
		 * @return array {
		 *     @type string[] $css    Array of stylesheet href values.
		 *     @type string[] $js     Array of script src values.
		 *     @type array[]  $images Array of image data with 'src', 'alt', and 'lazy' keys.
		 * }
		 */
		private static function parse_resources( string $html ): array {
			$css      = array();
			$js       = array();
			$images   = array();
			$dom_size = 0;

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
				// --- DOM Size ---
				$processor = new \WP_HTML_Tag_Processor( $html );
				while ( $processor->next_tag() ) {
					++$dom_size;
				}

				// --- CSS stylesheets ---
				$processor = new \WP_HTML_Tag_Processor( $html );
				while ( $processor->next_tag( array( 'tag_name' => 'LINK' ) ) ) {
					if ( 'stylesheet' === strtolower( (string) $processor->get_attribute( 'rel' ) ) ) {
						$href = $processor->get_attribute( 'href' );
						if ( $href ) {
							$css[] = (string) $href;
						}
					}
				}

				// --- Scripts ---
				$processor = new \WP_HTML_Tag_Processor( $html );
				while ( $processor->next_tag( array( 'tag_name' => 'SCRIPT' ) ) ) {
					$src = $processor->get_attribute( 'src' );
					if ( ! $src ) {
						$src = $processor->get_attribute( 'wppo-src' );
					}
					if ( $src ) {
						$js[] = (string) $src;
					}
				}

				// --- Images ---
				$processor = new \WP_HTML_Tag_Processor( $html );
				while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
					$data_src = $processor->get_attribute( 'data-src' );
					$src      = $processor->get_attribute( 'src' );
					$loading  = strtolower( (string) ( $processor->get_attribute( 'loading' ) ?? '' ) );
					$is_lazy  = ( null !== $data_src ) || ( 'lazy' === $loading );

					$resolved_src = $data_src ? (string) $data_src : (string) $src;

					if ( $resolved_src ) {
						$images[] = array(
							'src'  => $resolved_src,
							'alt'  => $processor->get_attribute( 'alt' ),
							'lazy' => $is_lazy,
						);
					}
				}
			} else {
				// --- Fallback: regex-based parsing ---
				$dom_size = preg_match_all( '/<[a-zA-Z]/', $html );

				// Match stylesheets with rel="stylesheet" appearing in any order.
				preg_match_all(
					'/<link\b(?=[^>]*\brel=["\']?stylesheet["\']?)[^>]*\bhref=["\']([^"\']+)["\'][^>]*>/i',
					$html,
					$css_matches
				);
				$css = $css_matches[1] ?? array();

				// Match scripts with src or wppo-src appearing in any order.
				preg_match_all(
					'/<script\b[^>]*\b(?:src|wppo-src)=["\']([^"\']+)["\'][^>]*>/i',
					$html,
					$js_matches
				);
				$js = $js_matches[1] ?? array();

				preg_match_all( '/<img\s([^>]*)>/i', $html, $img_tag_matches );
				foreach ( $img_tag_matches[1] ?? array() as $attrs ) {
					$src     = '';
					$is_lazy = false;

					if ( preg_match( '/data-src=["\']([^"\']+)["\']/', $attrs, $ds ) ) {
						$src     = $ds[1];
						$is_lazy = true;
					} elseif ( preg_match( '/\bsrc=["\']([^"\']+)["\']/', $attrs, $s ) ) {
						$src = $s[1];
					}

					if ( ! $src ) {
						continue;
					}

					if ( preg_match( '/\bloading=["\']?lazy["\']?/', $attrs ) ) {
						$is_lazy = true;
					}

					$alt = null;
					if ( preg_match( '/\balt=["\']([^"\']*)["\']/', $attrs, $a ) ) {
						$alt = $a[1];
					}

					$images[] = array(
						'src'  => $src,
						'alt'  => $alt,
						'lazy' => $is_lazy,
					);
				}
			}

			// --- New Metrics Calculation ---
			$unminified_count = 0;
			foreach ( array_merge( $css, $js ) as $asset ) {
				if ( ! preg_match( '/\.min\.(js|css)(\?.*)?$/i', $asset ) ) {
					++$unminified_count;
				}
			}

			$third_party_count = 0;
			$home_host         = wp_parse_url( home_url(), PHP_URL_HOST );
			foreach ( $js as $script ) {
				$host = wp_parse_url( $script, PHP_URL_HOST );
				if ( $host && $host !== $home_host ) {
					++$third_party_count;
				}
			}

			return array(
				'css'               => $css,
				'js'                => $js,
				'images'            => $images,
				'dom_size'          => $dom_size,
				'unminified_count'  => $unminified_count,
				'third_party_count' => $third_party_count,
			);
		}

		/**
		 * Calculate asset sizes using local filesystem paths.
		 *
		 * Uses Util::get_local_path() + filesize() (~0.0001ms per file) instead of
		 * HTTP HEAD requests. External/CDN assets that cannot be resolved to a local
		 * path return 0 — intentional for Phase 1 local telemetry.
		 *
		 * @since  1.5.0
		 * @param  array $resources Parsed resources from parse_resources().
		 * @return array {
		 *     @type int $css    Total CSS size in bytes.
		 *     @type int $js     Total JS size in bytes.
		 *     @type int $images Total image size in bytes.
		 * }
		 */
		private static function calculate_sizes( array $resources ): array {
			$sizes = array(
				'css'    => 0,
				'js'     => 0,
				'images' => 0,
			);

			/**
			 * Resolve a URL to a local filesystem path and return its filesize.
			 *
			 * @param string $url Asset URL.
			 * @return int File size in bytes, or 0 if not resolvable locally.
			 */
			$get_size = function ( string $url ): int {
				$local_path = Util::get_local_path( $url );
				if ( $local_path && file_exists( $local_path ) ) {
					return (int) filesize( $local_path );
				}

				// Only allow HEAD requests to the site's own domain for security.
				$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
				$url_host  = wp_parse_url( $url, PHP_URL_HOST );
				if ( ! $home_host || ! $url_host || $url_host !== $home_host ) {
					return 0;
				}

				// Fallback: Individual HEAD request for same-domain assets.
				$response = wp_remote_head(
					$url,
					array(
						'timeout'   => 5,
						'sslverify' => (bool) apply_filters( 'wppo_telemetry_verify_ssl', true, $url ),
					)
				);

				if ( ! is_wp_error( $response ) ) {
					$content_length = wp_remote_retrieve_header( $response, 'content-length' );
					if ( $content_length ) {
						return (int) $content_length;
					}
				}

				return 0;
			};

			foreach ( $resources['css'] as $src ) {
				$sizes['css'] += $get_size( $src );
			}

			foreach ( $resources['js'] as $src ) {
				$sizes['js'] += $get_size( $src );
			}

			foreach ( $resources['images'] as $img ) {
				$sizes['images'] += $get_size( $img['src'] );
			}

			return $sizes;
		}

		/**
		 * Measure Time to First Byte (TTFB) in milliseconds via GET request.
		 *
		 * Performs a full GET as a more representative TTFB fallback (some servers
		 * bypass full PHP execution for HEAD requests).
		 *
		 * @since  1.5.0
		 * @param  string $url The URL to measure.
		 * @return float TTFB in milliseconds, rounded to 2 decimal places.
		 */
		private static function measure_ttfb( string $url ): float {
			$start = microtime( true );
			wp_remote_get(
				$url,
				array(
					'timeout'   => 10,
					'sslverify' => (bool) apply_filters( 'wppo_telemetry_verify_ssl', true, $url ),
				)
			);
			// TTFB is essentially the time until we start receiving the response body,
			// which wp_remote_get returns after the whole body is fetched, but for a
			// basic fallback, timing the full GET is more representative of rendering
			// path than timing a HEAD request which often bypasses full PHP execution.
			return round( ( microtime( true ) - $start ) * 1000, 2 );
		}

		/**
		 * Check whether the URL uses HTTPS.
		 *
		 * Returns a locale-independent boolean string so frontend comparisons
		 * work correctly on non-English installs.
		 *
		 * @since  1.5.0
		 * @param  string $url The URL to check.
		 * @return bool True if HTTPS, false otherwise.
		 */
		private static function check_https( string $url ): bool {
			return 0 === strpos( $url, 'https://' );
		}

		/**
		 * Check whether Gzip, Brotli, or Deflate compression is active.
		 *
		 * Returns a locale-independent boolean so frontend comparisons work on
		 * non-English installs.
		 *
		 * @since  1.5.0
		 * @param  object|array $headers Response headers.
		 * @return bool True if compressed, false otherwise.
		 */
		private static function check_compression( $headers ): bool {
			$encoding = self::get_header_value( $headers, 'content-encoding' );

			return false !== stripos( $encoding, 'gzip' )
				|| false !== stripos( $encoding, 'br' )
				|| false !== stripos( $encoding, 'zstd' )
				|| false !== stripos( $encoding, 'deflate' );
		}

		/**
		 * Retrieve the raw Content-Encoding header value.
		 *
		 * @since  1.6.0
		 * @param  object|array $headers Response headers.
		 * @return string The raw header value, or 'none' if not set.
		 */
		private static function get_compression_type( $headers ): string {
			$encoding = self::get_header_value( $headers, 'content-encoding' );

			return $encoding ? $encoding : 'none';
		}

		/**
		 * Check whether Cache-Control headers are set for at least one week.
		 *
		 * Returns a locale-independent boolean so frontend comparisons work on
		 * non-English installs.
		 *
		 * @since  1.5.0
		 * @param  object|array $headers Response headers.
		 * @return bool True if max-age >= 604800, false otherwise.
		 */
		private static function check_cache_control( $headers ): bool {
			$cc = self::get_header_value( $headers, 'cache-control' );

			return preg_match( '/max-age\s*=\s*(\d+)/i', $cc, $matches )
				&& (int) $matches[1] >= 604800;
		}

		/**
		 * Retrieve the raw Cache-Control header value.
		 *
		 * @since  1.6.0
		 * @param  object|array $headers Response headers.
		 * @return string The raw header value, or 'none' if not set.
		 */
		private static function get_cache_control( $headers ): string {
			$cc = self::get_header_value( $headers, 'cache-control' );

			return $cc ? $cc : 'none';
		}

		/**
		 * Helper to retrieve a header value from various header structures.
		 *
		 * Handles WP_HTTP_Response object-style (offsetGet) and raw arrays.
		 * Tries both lowercase and Title-Case variations of the header name.
		 *
		 * @since  1.6.0
		 * @param  object|array $headers Response headers.
		 * @param  string       $name    Header name in lowercase (e.g. 'content-encoding').
		 * @return string Header value or empty string if not found.
		 */
		private static function get_header_value( $headers, string $name ): string {
			if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
				return (string) ( $headers[ $name ] ?? '' );
			}

			if ( ! is_array( $headers ) ) {
				return '';
			}

			$title_case = str_replace( ' ', '-', ucwords( str_replace( '-', ' ', $name ) ) );

			return (string) ( $headers[ $name ] ?? $headers[ $title_case ] ?? '' );
		}

		/**
		 * Check whether a robots.txt file exists at the given URL's root.
		 *
		 * Returns a locale-independent boolean so frontend comparisons work on
		 * non-English installs.
		 *
		 * @since  1.5.0
		 * @param  string $url The page URL whose host to check.
		 * @return bool True if robots.txt returns HTTP 200, false otherwise.
		 */
		private static function check_robots_txt( string $url ): bool {
			$parsed     = wp_parse_url( $url );
			$scheme     = $parsed['scheme'] ?? 'https';
			$host       = $parsed['host'] ?? '';
			$robots_url = $scheme . '://' . $host . '/robots.txt';

			// Validate robots.txt URL before fetching.
			if ( ! wp_http_validate_url( $robots_url ) ) {
				return false;
			}

			$response = wp_remote_get(
				$robots_url,
				array(
					'timeout'   => 5,
					'sslverify' => (bool) apply_filters( 'wppo_telemetry_verify_ssl', true, $robots_url ),
				)
			);

			if ( is_wp_error( $response ) ) {
				return false;
			}

			return 200 === (int) wp_remote_retrieve_response_code( $response );
		}

		/**
		 * Check whether any images use modern formats (WebP or AVIF).
		 *
		 * Returns a locale-independent boolean so frontend comparisons work on
		 * non-English installs.
		 *
		 * @since  1.5.0
		 * @param  array $images Array of image data from parse_resources().
		 * @return float Percentage of images using modern formats (0-100).
		 */
		private static function check_modern_images( array $images ): float {
			if ( empty( $images ) ) {
				return 100.0;
			}

			$modern_count = 0;
			foreach ( $images as $img ) {
				if ( preg_match( '/\.(webp|avif)(\?.*)?$/i', $img['src'] ) ) {
					++$modern_count;
				}
			}
			return round( ( $modern_count / count( $images ) ) * 100, 2 );
		}

		/**
		 * Check whether all images have non-empty alt attributes.
		 *
		 * Returns a locale-independent boolean so frontend comparisons work on
		 * non-English installs.
		 *
		 * @since  1.5.0
		 * @param  array $images Array of image data from parse_resources().
		 * @return bool True if all images have alt text, false otherwise.
		 */
		private static function check_alt_attributes( array $images ): bool {
			if ( empty( $images ) ) {
				return true;
			}

			foreach ( $images as $img ) {
				if ( null === $img['alt'] ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Register a transient key in the master index for safe bulk deletion.
		 *
		 * Appends the key to the wppo_transient_index option so that
		 * DELETE /telemetry can call delete_transient() on each key individually,
		 * ensuring compatibility with persistent object caches (Redis, Memcached).
		 *
		 * @since  1.5.0
		 * @param  string $key The transient key to register.
		 * @return void
		 */
		public static function register_transient_key( string $key ): void {
			// Use an associative map keyed by transient name so adding is idempotent.
			// Prune stale entries (transient no longer exists) and cap at 200 to prevent
			// unbounded growth. This also reduces race-condition impact on high-traffic sites.
			$index = get_option( 'wppo_transient_index', array() );
			$now   = time();

			// Add/update this key with its absolute expiry timestamp.
			$index[ $key ] = $now + HOUR_IN_SECONDS;

			// Prune only when the index exceeds the soft cap to avoid unnecessary overhead.
			if ( count( $index ) > 200 ) {
				foreach ( $index as $stored_key => $expiry ) {
					if ( $expiry < $now ) {
						unset( $index[ $stored_key ] );
					}
				}

				// If still over cap after pruning expired, trim the oldest entries.
				if ( count( $index ) > 200 ) {
					asort( $index ); // Sort by expiry ascending (oldest first).
					$index = array_slice( $index, -200, 200, true );
				}
			}

			update_option( 'wppo_transient_index', $index, false );
		}
	}
}
