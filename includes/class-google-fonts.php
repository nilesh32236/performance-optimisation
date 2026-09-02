<?php
/**
 * Google Fonts Local Hosting class.
 *
 * Detects Google Fonts loaded from fonts.googleapis.com, downloads the CSS
 * and font files (woff2), and serves them locally to eliminate external
 * DNS lookups, improve GDPR compliance, and apply font-display: swap.
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Google_Fonts' ) ) {

	/**
	 * Class Google_Fonts
	 *
	 * @since NEXT
	 */
	class Google_Fonts {

		/**
		 * Font cache subdirectory under WP_CONTENT_DIR /cache/wppo/.
		 *
		 * @var string
		 * @since NEXT
		 */
		private const FONTS_CACHE_DIR = '/cache/wppo/fonts';

		/**
		 * Chrome 120+ user-agent to request woff2 format from Google Fonts API.
		 *
		 * @var string
		 * @since NEXT
		 */
		private const CHROME_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

		/**
		 * Plugin settings.
		 *
		 * @var array
		 * @since NEXT
		 */
		private array $options;

		/**
		 * Font cache directory path.
		 *
		 * @var string
		 * @since NEXT
		 */
		private string $font_cache_dir;

		/**
		 * Font cache directory URL.
		 *
		 * @var string
		 * @since NEXT
		 */
		private string $font_cache_url;

		/**
		 * Constructor.
		 *
		 * @param array $options Plugin settings array.
		 * @since NEXT
		 */
		public function __construct( array $options ) {
			$this->options  = $options;
			$font_cache_dir = wp_normalize_path( WP_CONTENT_DIR . self::FONTS_CACHE_DIR );
			$font_cache_url = content_url( self::FONTS_CACHE_DIR );

			$this->font_cache_dir = is_string( $font_cache_dir ) ? $font_cache_dir : '';
			$this->font_cache_url = is_string( $font_cache_url ) ? $font_cache_url : '';
		}

		/**
		 * Process a stylesheet <link> tag to replace Google Fonts URL with local cache.
		 *
		 * Hooked to style_loader_tag filter (priority 9, before minify_css at 10).
		 *
		 * @param string $tag    The link tag HTML.
		 * @param string $handle The stylesheet handle.
		 * @param string $href   The stylesheet URL.
		 * @return string Modified link tag with local URL or original tag.
		 * @since NEXT
		 */
		public function process_style_tag( $tag, $handle, $href ) {
			if ( is_admin() ) {
				return $tag;
			}
			if ( ! Util::is_cache_eligible_for_current_user(
				$this->options['cache_settings'] ?? array()
			) ) {
				return $tag;
			}

			$enabled = $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			if ( empty( $enabled ) ) {
				return $tag;
			}

			// Exact host allowlist — not strpos (prevents evil.com/fonts.googleapis.com or fonts.googleapis.com.evil.com).
			// Caller: style_loader_tag filter; $href is the queued stylesheet URL.
			// @since NEXT.
			if ( wp_parse_url( $href, PHP_URL_HOST ) !== 'fonts.googleapis.com' ) {
				return $tag;
			}

			$local_url = $this->download_and_rewrite( $href );
			if ( '' === $local_url ) {
				return $tag;
			}

			return str_replace( $href, $local_url, $tag );
		}

		/**
		 * Process HTML buffer to intercept @import and inline <link> Google Fonts references.
		 *
		 * Catches patterns that bypass style_loader_tag, such as @import in CSS
		 * or inline <link> tags added via wp_head or theme templates.
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string The modified HTML buffer.
		 * @since NEXT
		 */
		public function process_buffer( $buffer ) {
			$enabled = $this->options['file_optimisation']['hostGoogleFontsLocally'] ?? false;
			if ( empty( $enabled ) ) {
				return $buffer;
			}

			// Replace <link> tags with Google Fonts URLs.
			$buffer = preg_replace_callback(
				'#<link\b[^>]*\bhref\s*=\s*["\']([^"\']*fonts\.googleapis\.com[^"\']*)["\'][^>]*>#is',
				function ( $matches ) {
					$local_url = $this->download_and_rewrite( $matches[1] );
					if ( '' !== $local_url ) {
						return str_replace( $matches[1], $local_url, $matches[0] );
					}
					return $matches[0];
				},
				$buffer
			);

			// Replace @import url(...) and @import '...' with Google Fonts URLs.
			$buffer = preg_replace_callback(
				'#@import\s+(?:url\(\s*["\']?|["\'])([^"\';)]*fonts\.googleapis\.com[^"\';)]*)(?:["\']?\)\s*|["\'])\s*;#is',
				function ( $matches ) {
					$local_url = $this->download_and_rewrite( $matches[1] );
					if ( '' !== $local_url ) {
						return str_replace( $matches[1], $local_url, $matches[0] );
					}
					return $matches[0];
				},
				$buffer
			);

			return $buffer;
		}

		/**
		 * Download Google Fonts CSS, fetch font files, rewrite URLs, and cache locally.
		 *
		 * @param string $url The Google Fonts CSS URL.
		 * @return string Local CSS URL on success, empty string on failure.
		 * @since NEXT
		 */
		public function download_and_rewrite( $url ) {
			$url = $this->normalize_google_fonts_url( $url );
			if ( '' === $url ) {
				return '';
			}

			$key      = md5( $url );
			$css_file = $this->font_cache_dir . '/css/' . $key . '.css';
			$css_url  = $this->font_cache_url . '/css/' . $key . '.css';

			// Return cached CSS if it exists.
			if ( file_exists( $css_file ) ) {
				return $css_url;
			}

			// Fetch CSS from Google Fonts API.
			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 20,
					'user-agent' => self::CHROME_UA,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return '';
			}

			$css = wp_remote_retrieve_body( $response );
			if ( empty( $css ) ) {
				return '';
			}

			// Ensure cache directories exist.
			Util::prepare_cache_dir( $this->font_cache_dir . '/css' );
			Util::prepare_cache_dir( $this->font_cache_dir . '/files' );

			// Extract and download font file URLs from @font-face src declarations.
			$css = preg_replace_callback(
				'#(url\()\s*(["\']?)(https://fonts\.gstatic\.com[^"\')]+)\2\s*\)#i',
				function ( $matches ) {
					$file_url = $matches[3];
					$hash     = md5( $file_url );
					$local    = $this->font_cache_dir . '/files/' . $hash . '.woff2';

					if ( ! file_exists( $local ) ) {
						$this->download_font_file( $file_url, $local );
					}

					return 'url(' . $this->font_cache_url . '/files/' . $hash . '.woff2)';
				},
				$css
			);

			if ( null === $css ) {
				return '';
			}

			// Inject font-display: swap.
			$css = Minify\CSS::inject_font_display_swap( $css );

			// Save the rewritten CSS.
			$filesystem = Util::init_filesystem();
			if ( $filesystem ) {
				$filesystem->put_contents( $css_file, $css, FS_CHMOD_FILE );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( $css_file, $css );
			}

			if ( file_exists( $css_file ) ) {
				return $css_url;
			}

			return '';
		}

		/**
		 * Normalize a Google Fonts URL.
		 *
		 * For v1 API URLs (/css), keeps the original URL to avoid format conversion
		 * issues with weight/style syntax. For v2 (/css2), returns as-is.
		 * Exact host check prevents substring bypass (e.g. evil.com/fonts.googleapis.com).
		 *
		 * Callers: {@see download_and_rewrite()} ← {@see process_style_tag()} and {@see process_buffer()}.
		 * Only fonts.googleapis.com is allowed for CSS; fonts.gstatic.com is handled
		 * separately in {@see download_font_file()}.
		 *
		 * @param string $url The raw URL.
		 * @return string Normalized URL or empty string if not a Google Fonts URL.
		 * @since NEXT
		 */
		private function normalize_google_fonts_url( $url ) {
			// Exact host allowlist — replaces strpos substring check.
			// @since NEXT.
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( 'fonts.googleapis.com' !== $host && 'fonts.gstatic.com' !== $host ) {
				return '';
			}
			// CSS endpoint must be googleapis; gstatic URLs are font files, not CSS — reject them here.
			if ( 'fonts.gstatic.com' === $host ) {
				return '';
			}

			// Already css2 format — return as-is.
			if ( false !== strpos( $url, '/css2' ) ) {
				return $url;
			}

			// For v1 URLs, keep using the /css endpoint with the same URL to avoid
			// format conversion issues (v1 uses ':weight' syntax which differs from
			// v2's '@' syntax).
			return $url;
		}

		/**
		 * Download a single font file from Google's CDN.
		 *
		 * @param string $url   The font file URL.
		 * @param string $dest  Local destination path.
		 * @return bool True on success, false on failure.
		 * @since NEXT
		 */
		private function download_font_file( $url, $dest ) {
			// Exact host allowlist — only fonts.gstatic.com may be fetched as a font file.
			// @since NEXT.
			if ( wp_parse_url( $url, PHP_URL_HOST ) !== 'fonts.gstatic.com' ) {
				return false;
			}

			$tmp = $dest . '.tmp.' . wp_rand();

			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 30,
					'user-agent' => self::CHROME_UA,
					'stream'     => true,
					'filename'   => $tmp,
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				return false;
			}

			if ( ! file_exists( $tmp ) || 0 === filesize( $tmp ) ) {
				if ( file_exists( $tmp ) ) {
					wp_delete_file( $tmp );
				}
				return false;
			}

			$result = rename( $tmp, $dest ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			if ( ! $result ) {
				// The file $tmp is guaranteed to exist here due to prior checks,
				// but rename failure means it was not moved. Clean it up unconditionally.
				wp_delete_file( $tmp );
			}

			return file_exists( $dest );
		}

		/**
		 * Generate metric-matched fallback CSS for a font family (size-adjust etc).
		 *
		 * Uses hardcoded metrics for common Google Fonts to reduce CLS vs system fallback.
		 * Filterable via wppo_font_metric_fallback_css.
		 *
		 * @since NEXT
		 * @param string $family Font family name.
		 * @return string Fallback @font-face CSS or empty string.
		 */
		public function generate_metric_fallback( string $family ): string {
			$family_key = strtolower( trim( $family ) );
			// Common metrics table: size-adjust, ascent-override, descent-override, line-gap-override.
			$metrics = array(
				'inter'      => array(
					'size-adjust'       => '107%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
				'roboto'     => array(
					'size-adjust'       => '100%',
					'ascent-override'   => '92%',
					'descent-override'  => '24%',
					'line-gap-override' => '0%',
				),
				'open sans'  => array(
					'size-adjust'       => '105%',
					'ascent-override'   => '88%',
					'descent-override'  => '20%',
					'line-gap-override' => '0%',
				),
				'lato'       => array(
					'size-adjust'       => '100%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
				'montserrat' => array(
					'size-adjust'       => '107%',
					'ascent-override'   => '92%',
					'descent-override'  => '24%',
					'line-gap-override' => '0%',
				),
				'poppins'    => array(
					'size-adjust'       => '105%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
				'nested'     => array(
					'size-adjust'       => '100%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				),
			);
			if ( ! isset( $metrics[ $family_key ] ) ) {
				// Generic fallback for unknown fonts.
				$metrics[ $family_key ] = array(
					'size-adjust'       => '100%',
					'ascent-override'   => '90%',
					'descent-override'  => '22%',
					'line-gap-override' => '0%',
				);
			}
			$m   = $metrics[ $family_key ];
			$css = sprintf(
				"@font-face{font-family:'%s Fallback';src:local('Arial');size-adjust:%s;ascent-override:%s;descent-override:%s;line-gap-override:%s;}",
				esc_html( $family ),
				$m['size-adjust'],
				$m['ascent-override'],
				$m['descent-override'],
				$m['line-gap-override']
			);
			/**
			 * Filters metric fallback CSS.
			 *
			 * @since NEXT
			 * @param string $css    Fallback CSS.
			 * @param string $family Font family.
			 */
			return (string) apply_filters( 'wppo_font_metric_fallback_css', $css, $family );
		}

		/**
		 * Inject metric-matched fallback style into buffer when enabled.
		 *
		 * @since NEXT
		 * @param string $buffer HTML buffer.
		 * @return string Modified buffer.
		 */
		public function inject_metric_fallback( string $buffer ): string {
			if ( empty( $this->options['file_optimisation']['fontMetricFallback'] ) ) {
				return $buffer;
			}
			// Extract font-family names from buffer Google Fonts links or cached CSS references.
			preg_match_all( '/font-family:\s*[\'"]?([^\'";,]+)[\'"]?/i', $buffer, $matches );
			if ( empty( $matches[1] ) ) {
				return $buffer;
			}
			$families     = array_unique( array_map( 'trim', $matches[1] ) );
			$fallback_css = '';
			foreach ( $families as $fam ) {
				$fallback_css .= $this->generate_metric_fallback( $fam ) . "\n";
			}
			if ( '' === $fallback_css ) {
				return $buffer;
			}
			$style_tag = '<style id="wppo-font-fallback">' . $fallback_css . '</style>';
			// Inject before </head> if present, else prepend.
			if ( false !== stripos( $buffer, '</head>' ) ) {
				$buffer = preg_replace( '/<\/head>/i', $style_tag . '</head>', $buffer, 1 );
			} else {
				$buffer = $style_tag . $buffer;
			}
			return $buffer;
		}

		/**
		 * Clear the entire Google Fonts cache directory.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function clear_font_cache() {
			$font_cache_dir = wp_normalize_path( WP_CONTENT_DIR . self::FONTS_CACHE_DIR );

			$filesystem = Util::init_filesystem();
			if ( $filesystem && $filesystem->is_dir( $font_cache_dir ) ) {
				$filesystem->delete( $font_cache_dir, true );
			}

			// Recreate empty directory structure.
			Util::prepare_cache_dir( $font_cache_dir . '/css' );
			Util::prepare_cache_dir( $font_cache_dir . '/files' );

			Log::add( 'Google Fonts cache cleared' );
		}
	}
}
