<?php
/**
 * Critical CSS Generation for above-the-fold optimization.
 *
 * @package PerformanceOptimise\Inc
 * @since   2.0.0
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
		 * Directory for CCSS files.
		 *
		 * @var string
		 * @since 2.0.0
		 */
		private const CCSS_DIR = '/cache/wppo/ccss';

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
		 * Get the CCSS directory path.
		 *
		 * @return string
		 * @since 2.0.0
		 */
		private static function get_ccss_dir(): string {
			return wp_normalize_path( WP_CONTENT_DIR . self::CCSS_DIR );
		}

		/**
		 * Get the CCSS directory URL.
		 *
		 * @return string
		 * @since 2.0.0
		 */
		private static function get_ccss_url(): string {
			return WP_CONTENT_URL . self::CCSS_DIR;
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
			return md5( $template . '-' . get_stylesheet() );
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
		 * Get the CCSS file path for a template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @return string Full file path.
		 * @since 2.0.0
		 */
		private static function get_ccss_file( string $template_hash ): string {
			return self::get_ccss_dir() . '/' . $template_hash . '.css';
		}

		/**
		 * Check if CCSS exists for a template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @return bool
		 * @since 2.0.0
		 */
		public static function ccss_exists( string $template_hash ): bool {
			return file_exists( self::get_ccss_file( $template_hash ) );
		}

		/**
		 * Get all registered template hashes and their status.
		 *
		 * Returns an associative array where keys are template hashes and values
		 * are arrays with 'status' and 'label' keys.
		 *
		 * @return array<string, array{status: string, label: string}> Template hash => status + label.
		 * @since 2.0.0
		 */
		public static function get_status_all(): array {
			$templates = self::get_templates();
			$statuses  = array();

			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				if ( self::ccss_exists( $hash ) ) {
					$statuses[ $hash ] = array(
						'status' => 'ready',
						'label'  => $label,
					);
				} else {
					$cache_status      = get_transient( Util::transient_key( 'wppo_ccss_status_' . $hash ) );
					$statuses[ $hash ] = array(
						'status' => $cache_status ? $cache_status : 'none',
						'label'  => $label,
					);
				}
			}

			return $statuses;
		}

		/**
		 * Get the list of supported templates.
		 *
		 * @return array<string, string> Template identifier => Label.
		 * @since 2.0.0
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
		 * @since 2.0.0
		 */
		private static function get_sample_url( string $template ): string|false {
			switch ( $template ) {
				case 'home':
					return home_url( '/' );
				case 'single':
					$posts = get_posts(
						array(
							'numberposts'  => 1,
							'post_status'  => 'publish',
							'has_password' => false,
							'fields'       => 'ids',
						)
					);
					return ! empty( $posts ) ? get_permalink( $posts[0] ) : false;
				case 'page':
					$pages = get_posts(
						array(
							'post_type'    => 'page',
							'numberposts'  => 1,
							'post_status'  => 'publish',
							'has_password' => false,
							'fields'       => 'ids',
						)
					);
					return ! empty( $pages ) ? get_permalink( $pages[0] ) : home_url( '/' );
				case 'archive':
					$archives = get_posts(
						array(
							'numberposts'  => 1,
							'post_status'  => 'publish',
							'has_password' => false,
							'fields'       => 'ids',
						)
					);
					if ( ! empty( $archives ) ) {
						setup_postdata( $archives[0] );
						$year  = get_the_time( 'Y' );
						$month = get_the_time( 'm' );
						wp_reset_postdata();
						return get_month_link( $year, $month );
					}
					return false;
				default:
					return home_url( '/' );
			}
		}

		/**
		 * Generate critical CSS for a given URL.
		 *
		 * Fetches the HTML, extracts CSS resources and inline styles, downloads
		 * external CSS (resolving @import directives), and applies heuristic
		 * above-fold rule extraction.
		 *
		 * @param string $url The page URL to generate CCSS for.
		 * @return string|false The critical CSS content, or false on failure.
		 * @since 2.0.0
		 */
		public static function generate( string $url ) {
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

			libxml_use_internal_errors( true );
			$dom = new \DOMDocument();
			$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
			libxml_clear_errors();

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
			$link_tags = $xpath->query( '//link[@rel="stylesheet"]' );
			if ( $link_tags ) {
				foreach ( $link_tags as $tag ) {
					$href = $tag->getAttribute( 'href' );
					if ( empty( $href ) ) {
						continue;
					}

					$skip = false;
					foreach ( self::SKIP_DEFER_HANDLES as $handle ) {
						if ( false !== strpos( $href, $handle ) ) {
							$skip = true;
							break;
						}
					}
					if ( $skip ) {
						continue;
					}

					$fetched = self::fetch_stylesheet_with_imports( $href );
					if ( '' !== $fetched ) {
						$css_content .= $fetched . "\n";
					}
				}
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
		 * @since 2.0.0
		 */
		private static function fetch_stylesheet_with_imports( string $url, int $depth = 0 ): string {
			if ( $depth > self::MAX_IMPORT_DEPTH ) {
				return '';
			}

			$response = wp_remote_get(
				$url,
				array(
					'timeout'    => 15,
					'user-agent' => 'WPPO Critical CSS Generator/' . WPPO_VERSION,
				)
			);

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
		 * @since 2.0.0
		 */
		private static function resolve_import_url( string $import_url, string $base_url ): string {
			// If already absolute, return as-is.
			if ( preg_match( '/^https?:\/\//i', $import_url ) ) {
				return $import_url;
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
		 * @since 2.0.0
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
			if ( ! empty( $mobile_queries[0] ) ) {
				foreach ( $mobile_queries[0] as $mq ) {
					$filtered = self::filter_media_query_rules( $mq );
					if ( ! empty( $filtered ) ) {
						$critical_parts[] = $filtered;
					}
				}
			}

			// Extract regular rules using brace-depth-based parsing.
			// Handles minified CSS (single line), multi-line selectors, and nested braces.
			self::parse_regular_rules( $css, $critical_parts );

			return implode( "\n", array_unique( array_filter( $critical_parts ) ) );
		}

		/**
		 * Parse regular (non-at-rule) CSS rules using brace-depth tracking.
		 *
		 * Handles minified CSS, multi-line selectors, and nested braces
		 * (e.g., background: url(data:...{...})).
		 *
		 * @param string $css            Full CSS content.
		 * @param array  $critical_parts Reference to array of extracted critical CSS parts.
		 * @return void
		 * @since 2.0.0
		 */
		private static function parse_regular_rules( string $css, array &$critical_parts ): void {
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
						// Complete rule block.
						if ( '' !== $selector && self::matches_above_fold( $selector ) ) {
							$critical_parts[] = $buffer;
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
		 * @param string $media_query Full media query block.
		 * @return string Filtered media query or empty string.
		 * @since 2.0.0
		 */
		private static function filter_media_query_rules( string $media_query ): string {
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
				if ( self::matches_above_fold( $selector ) ) {
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
		 * @param string $selector The CSS selector string (may contain multiple selectors separated by commas).
		 * @return bool True if any individual selector should be included in critical CSS.
		 * @since 2.0.0
		 */
		private static function matches_above_fold( string $selector ): bool {
			$selector = trim( $selector );

			if ( empty( $selector ) ) {
				return false;
			}

			// Split multi-selector groups on commas (outside parentheses).
			$individual_selectors = preg_split( '/,(?=(?:[^()]*\([^()]*\))*[^()]*$)/', $selector );

			foreach ( $individual_selectors as $single ) {
				$single = trim( $single );
				if ( '' === $single ) {
					continue;
				}
				if ( self::matches_above_fold_single( $single ) ) {
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
		 * @param string $selector A single trimmed CSS selector.
		 * @return bool True if the selector matches.
		 * @since 2.0.0
		 */
		private static function matches_above_fold_single( string $selector ): bool {
			$selector = trim( $selector );

			if ( empty( $selector ) ) {
				return false;
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

			// Split descendant selectors and test last (most specific) part.
			$parts = preg_split( '/\s+/', $clean );
			$last  = end( $parts );

			foreach ( self::ABOVE_FOLD_SELECTORS as $above ) {
				if ( self::token_match( $last, $above ) ) {
					return true;
				}
			}

			return false;
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
		 * Generate and store CCSS for a given template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @param string $template      The template identifier.
		 * @return bool True on success, false on failure.
		 * @since 2.0.0
		 */
		private static function generate_and_store( string $template_hash, string $template ): bool {
			$url = self::get_sample_url( $template );
			if ( ! $url ) {
				set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'failed', DAY_IN_SECONDS );
				return false;
			}

			$critical_css = self::generate( $url );
			if ( false === $critical_css ) {
				set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'failed', DAY_IN_SECONDS );
				return false;
			}

			$dir = self::get_ccss_dir();
			if ( ! wp_mkdir_p( $dir ) ) {
				set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'failed', DAY_IN_SECONDS );
				return false;
			}

			$filesystem = Util::init_filesystem();
			if ( $filesystem ) {
				$filesystem->put_contents( self::get_ccss_file( $template_hash ), $critical_css, FS_CHMOD_FILE );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( self::get_ccss_file( $template_hash ), $critical_css );
			}

			if ( file_exists( self::get_ccss_file( $template_hash ) ) ) {
				set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'ready', WEEK_IN_SECONDS );
				return true;
			}

			set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'failed', DAY_IN_SECONDS );
			return false;
		}

		/**
		 * Inline critical CSS in the <head> for non-logged-in visitors.
		 *
		 * Hooked to wp_head at priority 0. Computes the template hash using
		 * the current template slug (based on WordPress conditional tags)
		 * to match the hashes stored by generate_and_store().
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public static function inline_ccss(): void {
			if ( is_admin() ) {
				return;
			}
			if ( is_user_logged_in() ) {
				$options = get_option( 'wppo_settings', array() );
				$enabled = ! empty( $options['cache_settings']['enableLoggedInCache'] ?? false );
				if ( ! $enabled ) {
					return;
				}
			}

			$template_slug = self::get_current_template_slug();
			$template_hash = self::get_template_hash( $template_slug );
			$file          = self::get_ccss_file( $template_hash );

			if ( file_exists( $file ) ) {
				$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( ! empty( $content ) ) {
					echo '<style id="wppo-critical-css">' . "\n";
					echo $content . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS content generated by plugin from its own cache.
					echo '</style>' . "\n";
				}
			} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
				$hook = 'wppo_generate_ccss';
				if ( ! as_next_scheduled_action( $hook, array( 'template_hash' => $template_hash ), 'performance_optimisation' ) ) {
					as_enqueue_async_action(
						$hook,
						array( 'template_hash' => $template_hash ),
						'performance_optimisation'
					);
					set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'pending', HOUR_IN_SECONDS );
				}
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
			if ( is_user_logged_in() ) {
				$options = get_option( 'wppo_settings', array() );
				$enabled = ! empty( $options['cache_settings']['enableLoggedInCache'] ?? false );
				if ( ! $enabled ) {
					return $tag;
				}
			}

			foreach ( self::SKIP_DEFER_HANDLES as $skip ) {
				if ( $handle === $skip || false !== strpos( $href, $skip ) ) {
					return $tag;
				}
			}

			// Skip if already modified.
			if ( false !== strpos( $tag, 'data-wppo-ccss' ) ) {
				return $tag;
			}

			$new_tag = str_replace(
				'media=\'all\'',
				'media=\'print\' onload=\'this.media="all"\' data-wppo-ccss=\'1\'',
				$tag
			);
			$new_tag = str_replace(
				'media="all"',
				'media="print" onload=\'this.media="all"\' data-wppo-ccss="1"',
				$new_tag
			);

			$noscript = '<noscript>' . $tag . '</noscript>';

			return $new_tag . "\n" . $noscript;
		}

		/**
		 * Background generation callback for Action Scheduler.
		 *
		 * @param array $args Arguments containing 'template_hash'.
		 * @return void
		 * @since 2.0.0
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
				set_transient( Util::transient_key( 'wppo_ccss_status_' . $template_hash ), 'failed', DAY_IN_SECONDS );
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
		 * Regenerate all template CCSS files via Action Scheduler.
		 *
		 * @return int Number of jobs queued.
		 * @since 2.0.0
		 */
		public static function regenerate_all(): int {
			$templates = self::get_templates();
			$queued    = 0;

			self::clear_all();

			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				if ( function_exists( 'as_enqueue_async_action' ) ) {
					$hook = 'wppo_generate_ccss';
					if ( ! as_next_scheduled_action( $hook, array( 'template_hash' => $hash ), 'performance_optimisation' ) ) {
						as_enqueue_async_action(
							$hook,
							array( 'template_hash' => $hash ),
							'performance_optimisation'
						);
						++$queued;
					}
				}
				set_transient( Util::transient_key( 'wppo_ccss_status_' . $hash ), 'pending', HOUR_IN_SECONDS );
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
		 * @since 2.0.0
		 */
		public static function clear_all(): void {
			$dir = self::get_ccss_dir();

			$filesystem = Util::init_filesystem();
			global $wp_filesystem;

			if ( $wp_filesystem && $wp_filesystem->is_dir( $dir ) ) {
				$wp_filesystem->delete( $dir, true );
			}

			// Also clear status transients.
			$templates = self::get_templates();
			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				delete_transient( Util::transient_key( 'wppo_ccss_status_' . $hash ) );
			}
		}
	}
}
