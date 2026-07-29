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
			'*',
			'*::before',
			'*::after',
		);

		/**
		 * CSS handles to skip during deferral.
		 *
		 * @var string[]
		 * @since 2.0.0
		 */
		private const SKIP_DEFER_HANDLES = array(
			'wppo-combine-css',
			'wppo-critical-css-inline',
			'dashicons',
			'admin-bar',
			'wp-block-library',
			'wc-block-style',
		);

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
		 * Generate a unique template hash for the current theme + template.
		 *
		 * @param string $template Optional template name. Defaults to current template.
		 * @return string MD5 hash.
		 * @since 2.0.0
		 */
		public static function get_template_hash( string $template = '' ): string {
			if ( empty( $template ) ) {
				$template = get_template();
			}
			return md5( $template . '-' . get_stylesheet() );
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
		 * @return array<string, string> Template hash => status ('ready', 'pending', 'failed', 'none').
		 * @since 2.0.0
		 */
		public static function get_status_all(): array {
			$templates = self::get_templates();
			$statuses  = array();

			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				if ( self::ccss_exists( $hash ) ) {
					$statuses[ $hash ] = 'ready';
				} else {
					$cache_status      = get_transient( 'wppo_ccss_status_' . $hash );
					$statuses[ $hash ] = $cache_status ? $cache_status : 'none';
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

			$page_templates = get_page_templates();
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
		private static function get_sample_url( string $template ) {
			switch ( $template ) {
				case 'home':
					return home_url( '/' );
				case 'single':
					$posts = get_posts(
						array(
							'numberposts' => 1,
							'post_status' => 'publish',
							'fields'      => 'ids',
						)
					);
					return ! empty( $posts ) ? get_permalink( $posts[0] ) : false;
				case 'page':
					$pages = get_posts(
						array(
							'post_type'   => 'page',
							'numberposts' => 1,
							'post_status' => 'publish',
							'fields'      => 'ids',
						)
					);
					return ! empty( $pages ) ? get_permalink( $pages[0] ) : home_url( '/' );
				case 'archive':
					$archives = get_posts(
						array(
							'numberposts' => 1,
							'post_status' => 'publish',
							'fields'      => 'ids',
						)
					);
					if ( ! empty( $archives ) ) {
						$year  = get_the_time( 'Y', $archives[0] );
						$month = get_the_time( 'm', $archives[0] );
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
		 * external CSS, and applies heuristic above-fold rule extraction.
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

					$css_response = wp_remote_get(
						$href,
						array(
							'timeout'    => 15,
							'user-agent' => 'WPPO Critical CSS Generator/' . WPPO_VERSION,
						)
					);

					if ( ! is_wp_error( $css_response ) && 200 === wp_remote_retrieve_response_code( $css_response ) ) {
						$css_content .= wp_remote_retrieve_body( $css_response ) . "\n";
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
		 * Extract above-fold CSS rules using heuristic selector matching.
		 *
		 * Always includes @font-face, @keyframes, CSS custom properties (:root),
		 * and rules matching known above-fold selectors.
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
				// Only include if it has custom properties.
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

			// Extract regular rules matching above-fold selectors.
			$lines             = explode( "\n", $css );
			$buffer            = '';
			$in_block          = false;
			$brace_count       = 0;
			$current_selector  = '';
			$in_media          = false;
			$media_buffer      = '';
			$media_brace_count = 0;

			foreach ( $lines as $line ) {
				if ( ! $in_block && ! $in_media ) {
					$trimmed = trim( $line );

					// Skip at-rules already handled.
					if ( 0 === strpos( $trimmed, '@font-face' ) ||
						0 === strpos( $trimmed, '@keyframes' ) ||
						0 === strpos( $trimmed, '@import' ) ||
						0 === strpos( $trimmed, '@charset' ) ||
						0 === strpos( $trimmed, '@namespace' ) ) {
						continue;
					}

					// Skip media queries (handled separately).
					if ( 0 === strpos( $trimmed, '@media' ) ) {
						continue;
					}

					if ( false !== strpos( $trimmed, '{' ) ) {
						$current_selector = $trimmed;
						$in_block         = true;
						$brace_count      = substr_count( $trimmed, '{' ) - substr_count( $trimmed, '}' );
						$buffer           = $trimmed . "\n";
						continue;
					}
				} elseif ( $in_block ) {
					$buffer      .= $line . "\n";
					$brace_count += substr_count( $line, '{' ) - substr_count( $line, '}' );

					if ( $brace_count <= 0 ) {
						$in_block = false;
						// Check if selector matches above-fold selectors.
						if ( self::matches_above_fold( $current_selector ) ) {
							$critical_parts[] = $buffer;
						}
						$buffer           = '';
						$current_selector = '';
					}
				}
			}

			return implode( "\n", array_unique( array_filter( $critical_parts ) ) );
		}

		/**
		 * Filter rules inside a media query to keep only above-fold selectors.
		 *
		 * @param string $media_query Full media query block.
		 * @return string Filtered media query or empty string.
		 * @since 2.0.0
		 */
		private static function filter_media_query_rules( string $media_query ): string {
			// Extract the media query header.
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
		 * @param string $selector The CSS selector string.
		 * @return bool True if the selector should be included in critical CSS.
		 * @since 2.0.0
		 */
		private static function matches_above_fold( string $selector ): bool {
			$selector = trim( $selector );

			if ( empty( $selector ) ) {
				return false;
			}

			// Remove pseudo-classes and pseudo-elements for matching.
			$clean = preg_replace( '/::?[\w-]+(\([^)]*\))?/', '', $selector );
			$clean = preg_replace( '/\[[^\]]*\]/', '', $clean );
			$clean = trim( $clean );

			foreach ( self::ABOVE_FOLD_SELECTORS as $above ) {
				if ( false !== strpos( $clean, $above ) ) {
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
		 * @since 2.0.0
		 */
		private static function generate_and_store( string $template_hash, string $template ): bool {
			$url = self::get_sample_url( $template );
			if ( ! $url ) {
				set_transient( 'wppo_ccss_status_' . $template_hash, 'failed', DAY_IN_SECONDS );
				return false;
			}

			$critical_css = self::generate( $url );
			if ( false === $critical_css ) {
				set_transient( 'wppo_ccss_status_' . $template_hash, 'failed', DAY_IN_SECONDS );
				return false;
			}

			$dir = self::get_ccss_dir();
			if ( ! wp_mkdir_p( $dir ) ) {
				set_transient( 'wppo_ccss_status_' . $template_hash, 'failed', DAY_IN_SECONDS );
				return false;
			}

			Util::init_filesystem();
			global $wp_filesystem;
			if ( $wp_filesystem ) {
				$wp_filesystem->put_contents( self::get_ccss_file( $template_hash ), $critical_css, FS_CHMOD_FILE );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				file_put_contents( self::get_ccss_file( $template_hash ), $critical_css );
			}

			if ( file_exists( self::get_ccss_file( $template_hash ) ) ) {
				set_transient( 'wppo_ccss_status_' . $template_hash, 'ready', WEEK_IN_SECONDS );
				return true;
			}

			set_transient( 'wppo_ccss_status_' . $template_hash, 'failed', DAY_IN_SECONDS );
			return false;
		}

		/**
		 * Inline critical CSS in the <head> for non-logged-in visitors.
		 *
		 * Hooked to wp_head at priority 0.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public static function inline_ccss(): void {
			if ( is_user_logged_in() || is_admin() ) {
				return;
			}

			$template_hash = self::get_template_hash();
			$file          = self::get_ccss_file( $template_hash );

			if ( file_exists( $file ) ) {
				$content = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
				if ( ! empty( $content ) ) {
					echo '<style id="wppo-critical-css">' . "\n";
					echo wp_kses(
						$content,
						array(
							"\n" => array(),
							"\r" => array(),
							"\t" => array(),
						)
					);
					echo "\n" . '</style>' . "\n";
				}
			} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
				$hook = 'wppo_generate_ccss';
				if ( ! as_next_scheduled_action( $hook, array( 'template_hash' => $template_hash ), 'performance_optimisation' ) ) {
					as_enqueue_async_action(
						$hook,
						array( 'template_hash' => $template_hash ),
						'performance_optimisation'
					);
					set_transient( 'wppo_ccss_status_' . $template_hash, 'pending', HOUR_IN_SECONDS );
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
			if ( is_user_logged_in() || is_admin() ) {
				return $tag;
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
				set_transient( 'wppo_ccss_status_' . $template_hash, 'failed', DAY_IN_SECONDS );
				return;
			}

			self::generate_and_store( $template_hash, $found_template );

			Log::add(
				sprintf(
					/* translators: %s: Template hash */
					__( 'Critical CSS generated for template: %s', 'performance-optimisation' ),
					$template_hash
				)
			);
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
				set_transient( 'wppo_ccss_status_' . $hash, 'pending', HOUR_IN_SECONDS );
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

			Util::init_filesystem();
			global $wp_filesystem;

			if ( $wp_filesystem && $wp_filesystem->is_dir( $dir ) ) {
				$wp_filesystem->delete( $dir, true );
			}

			// Also clear status transients.
			$templates = self::get_templates();
			foreach ( $templates as $template => $label ) {
				$hash = self::get_template_hash( $template );
				delete_transient( 'wppo_ccss_status_' . $hash );
			}
		}
	}
}
