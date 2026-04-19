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
		 * @return array|\WP_Error   Associative array of metrics, or WP_Error on failure.
		 */
		public static function scan( string $url, string $scan_type = 'manual' ) {
			$transient_key = 'wppo_telemetry_' . md5( $url );
			$cached        = get_transient( $transient_key );

			if ( false !== $cached ) {
				return $cached;
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
				curl_setopt( $ch, CURLOPT_MAXREDIRS, 5 );
				curl_setopt( $ch, CURLOPT_TIMEOUT, 30 );
				curl_setopt(
					$ch,
					CURLOPT_USERAGENT,
					'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url()
				);
				curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
				curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );

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
						'dns'     => round( $info['namelookup_time'] * 1000, 2 ),
						'connect' => round( ( $info['connect_time'] - $info['namelookup_time'] ) * 1000, 2 ),
						'ssl'     => ( $info['appconnect_time'] > 0 )
							? round( ( $info['appconnect_time'] - $info['connect_time'] ) * 1000, 2 )
							: 0,
						'ttfb'    => round( ( $info['starttransfer_time'] - $info['pretransfer_time'] ) * 1000, 2 ),
						'total'   => round( $info['total_time'] * 1000, 2 ),
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
						'sslverify'  => false,
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
			}

			$resources    = self::parse_resources( $body );
			$sizes        = self::calculate_sizes( $resources );
			$lazy_images  = array_filter( $resources['images'], fn( $img ) => true === $img['lazy'] );
			$eager_images = array_filter( $resources['images'], fn( $img ) => false === $img['lazy'] );

			$result = array(
				'page_url'                  => esc_url( $url ),
				'load_time'                 => $load_time,
				'ttfb'                      => isset( $timings['ttfb'] ) ? $timings['ttfb'] : self::measure_ttfb( $url ),
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
				'uses_https'                => self::check_https( $url ),
				'uses_modern_image_formats' => self::check_modern_images( $resources['images'] ),
				'image_alt_attributes'      => self::check_alt_attributes( $resources['images'] ),
				'robots_txt_exists'         => self::check_robots_txt( $url ),
				'gzip_brotli_compression'   => self::check_compression( $headers ),
				'cache_control_headers'     => self::check_cache_control( $headers ),
				'scan_type'                 => sanitize_text_field( $scan_type ),
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
			$css    = array();
			$js     = array();
			$images = array();

			if ( class_exists( 'WP_HTML_Tag_Processor' ) ) {
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
				// Also detect wppo-src: WPPO's Delay JS feature replaces src with wppo-src
				// so the script is not executed until user interaction.
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
				// An image is lazy-loaded when it uses data-src (WPPO lazy-load pattern)
				// OR carries a loading="lazy" attribute (native browser lazy-load).
				$processor = new \WP_HTML_Tag_Processor( $html );
				while ( $processor->next_tag( array( 'tag_name' => 'IMG' ) ) ) {
					$data_src = $processor->get_attribute( 'data-src' );
					$src      = $processor->get_attribute( 'src' );
					$loading  = strtolower( (string) ( $processor->get_attribute( 'loading' ) ?? '' ) );
					$is_lazy  = ( null !== $data_src ) || ( 'lazy' === $loading );

					// Prefer data-src (the real image URL) over src (often a placeholder).
					$resolved_src = $data_src ? (string) $data_src : (string) $src;

					if ( $resolved_src ) {
						$images[] = array(
							'src'  => $resolved_src,
							'alt'  => (string) ( $processor->get_attribute( 'alt' ) ?? '' ),
							'lazy' => $is_lazy,
						);
					}
				}
			} else {
				// --- Fallback: regex-based parsing for WordPress < 6.2 ---
				preg_match_all(
					'/<link\s[^>]*rel=["\']stylesheet["\'][^>]*href=["\']([^"\']+)["\'][^>]*>/i',
					$html,
					$css_matches
				);
				$css = $css_matches[1] ?? array();

				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
				preg_match_all(
					'/<script\s[^>]*\b(?:src|wppo-src)=["\']([^"\']+)["\'][^>]*>/i',
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

					$alt = '';
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

			return compact( 'css', 'js', 'images' );
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
		 * Measure Time to First Byte (TTFB) in milliseconds via HEAD request.
		 *
		 * Used as a fallback when cURL is unavailable.
		 *
		 * @since  1.5.0
		 * @param  string $url The URL to measure.
		 * @return float TTFB in milliseconds, rounded to 2 decimal places.
		 */
		private static function measure_ttfb( string $url ): float {
			$start = microtime( true );
			wp_remote_head(
				$url,
				array(
					'timeout'   => 10,
					'sslverify' => false,
				)
			);
			return round( ( microtime( true ) - $start ) * 1000, 2 );
		}

		/**
		 * Check whether the URL uses HTTPS.
		 *
		 * @since  1.5.0
		 * @param  string $url The URL to check.
		 * @return string 'Enabled' if HTTPS, 'Disabled' otherwise.
		 */
		private static function check_https( string $url ): string {
			return ( 0 === strpos( $url, 'https://' ) )
				? esc_html__( 'Enabled', 'performance-optimisation' )
				: esc_html__( 'Disabled', 'performance-optimisation' );
		}

		/**
		 * Check whether Gzip, Brotli, or Deflate compression is active.
		 *
		 * When cURL is used with CURLOPT_ENCODING, the server sends the original
		 * Content-Encoding header but cURL decodes the body transparently. We check
		 * the raw header value to report the actual compression method in use.
		 *
		 * @since  1.5.0
		 * @param  \Requests_Utility_CaseInsensitiveDictionary|array $headers Response headers.
		 * @return string 'Enabled' if compressed, 'Disabled' otherwise.
		 */
		private static function check_compression( $headers ): string {
			$encoding = '';

			if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
				$encoding = (string) ( $headers['content-encoding'] ?? '' );
			} elseif ( is_array( $headers ) ) {
				$encoding = (string) ( $headers['content-encoding'] ?? $headers['Content-Encoding'] ?? '' );
			}

			return ( false !== stripos( $encoding, 'gzip' )
				|| false !== stripos( $encoding, 'br' )
				|| false !== stripos( $encoding, 'deflate' ) )
				? esc_html__( 'Enabled', 'performance-optimisation' )
				: esc_html__( 'Disabled', 'performance-optimisation' );
		}

		/**
		 * Check whether Cache-Control headers are set for at least one week.
		 *
		 * Looks for a max-age directive of 604800 seconds or greater.
		 *
		 * @since  1.5.0
		 * @param  \Requests_Utility_CaseInsensitiveDictionary|array $headers Response headers.
		 * @return string Human-readable cache-control status.
		 */
		private static function check_cache_control( $headers ): string {
			$cc = '';

			if ( is_object( $headers ) && method_exists( $headers, 'offsetGet' ) ) {
				$cc = (string) ( $headers['cache-control'] ?? '' );
			} elseif ( is_array( $headers ) ) {
				$cc = (string) ( $headers['cache-control'] ?? $headers['Cache-Control'] ?? '' );
			}

			if ( preg_match( '/max-age\s*=\s*(\d+)/i', $cc, $matches ) && (int) $matches[1] >= 604800 ) {
				return esc_html__( 'Set for at least 1 week', 'performance-optimisation' );
			}

			return esc_html__( 'Not set or set for a shorter duration', 'performance-optimisation' );
		}

		/**
		 * Check whether a robots.txt file exists at the given URL's root.
		 *
		 * Issues a secondary wp_remote_get() to {scheme}://{host}/robots.txt.
		 *
		 * @since  1.5.0
		 * @param  string $url The page URL whose host to check.
		 * @return string 'Exists' if robots.txt returns HTTP 200, 'Missing' otherwise.
		 */
		private static function check_robots_txt( string $url ): string {
			$parsed     = wp_parse_url( $url );
			$scheme     = $parsed['scheme'] ?? 'https';
			$host       = $parsed['host'] ?? '';
			$robots_url = $scheme . '://' . $host . '/robots.txt';

			$response = wp_remote_get(
				$robots_url,
				array(
					'timeout'   => 5,
					'sslverify' => false,
				)
			);

			if ( is_wp_error( $response ) ) {
				return esc_html__( 'Missing', 'performance-optimisation' );
			}

			return ( 200 === (int) wp_remote_retrieve_response_code( $response ) )
				? esc_html__( 'Exists', 'performance-optimisation' )
				: esc_html__( 'Missing', 'performance-optimisation' );
		}

		/**
		 * Check whether any images use modern formats (WebP or AVIF).
		 *
		 * @since  1.5.0
		 * @param  array $images Array of image data from parse_resources().
		 * @return string 'Modern formats used' or 'Outdated formats used'.
		 */
		private static function check_modern_images( array $images ): string {
			foreach ( $images as $img ) {
				if ( preg_match( '/\.(webp|avif)(\?.*)?$/i', $img['src'] ) ) {
					return esc_html__( 'Modern formats used', 'performance-optimisation' );
				}
			}
			return esc_html__( 'Outdated formats used', 'performance-optimisation' );
		}

		/**
		 * Check whether all images have non-empty alt attributes.
		 *
		 * @since  1.5.0
		 * @param  array $images Array of image data from parse_resources().
		 * @return string 'All images have alt text' or 'Some or no images have alt text'.
		 */
		private static function check_alt_attributes( array $images ): string {
			if ( empty( $images ) ) {
				return esc_html__( 'All images have alt text', 'performance-optimisation' );
			}

			foreach ( $images as $img ) {
				if ( '' === trim( $img['alt'] ) ) {
					return esc_html__( 'Some or no images have alt text', 'performance-optimisation' );
				}
			}

			return esc_html__( 'All images have alt text', 'performance-optimisation' );
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
			$index   = get_option( 'wppo_transient_index', array() );
			$index[] = $key;
			update_option( 'wppo_transient_index', array_unique( $index ), false );
		}
	}
}
