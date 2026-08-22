<?php
/**
 * Handles HTML, CSS, and JS minification for improved website performance.
 *
 * This file defines the HTML class, which leverages third-party libraries to minify
 * HTML, inline CSS, and inline JavaScript. It provides functionality to optimize
 * and preserve specific HTML structures, ensuring compatibility with WordPress and
 * other web technologies.
 *
 * @category PerformanceOptimization
 * @package  PerformanceOptimise\Inc\Minify
 * @since    1.0.0
 */

namespace PerformanceOptimise\Inc\Minify;

use voku\helper\HtmlMin;
use MatthiasMullie\Minify\CSS as CSSMinifier;
use MatthiasMullie\Minify\JS as JSMinifier;
use PerformanceOptimise\Inc\Util;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Minify\HTML' ) ) {
	/**
	 * Class HTML
	 *
	 * Handles the minification of HTML, inline CSS, and inline JavaScript.
	 *
	 * @since 1.0.0
	 */
	class HTML {
		/**
		 * Instance of the HtmlMin class used for minifying HTML content.
		 *
		 * @since 1.0.0
		 * @var HtmlMin $html_min
		 */
		private HtmlMin $html_min;

		/**
		 * The resulting minified HTML content after processing.
		 *
		 * @since 1.0.0
		 * @var string $minified_html
		 */
		private string $minified_html;

		/**
		 * Configuration options for minification, including settings for inline CSS and JavaScript.
		 *
		 * @since 1.0.0
		 * @var array $options
		 */
		private array $options;

		/**
		 * Cached array of scripts to exclude from delayJS.
		 *
		 * @since 1.7.0
		 * @var array $exclude_delay_js
		 */
		private array $exclude_delay_js;

		/**
		 * Handles/URLs to load via requestIdleCallback.
		 *
		 * @since NEXT
		 * @var array
		 */
		private array $delay_js_idle_list = array();

		/**
		 * Handles/URLs to load when in viewport.
		 *
		 * @since NEXT
		 * @var array
		 */
		private array $delay_js_viewport_list = array();

		/**
		 * Default delay strategy.
		 *
		 * @since NEXT
		 * @var string
		 */
		private string $delay_js_default_strategy = 'interaction';

		/**
		 * Priority map handle=>level.
		 *
		 * @since NEXT
		 * @var array
		 */
		private array $delay_js_priority = array();

		/**
		 * Constructor to initialize HTML minification.
		 *
		 * @param string $html The HTML content to minify.
		 * @param array  $options Minification options.
		 * @since 1.0.0
		 */
		public function __construct( $html, $options ) {
			$this->options = (array) $options;
			$this->initialize_minification_settings();

			// Cache delay JS exclusions to avoid redundant processing in loops.
			$this->exclude_delay_js = array_merge(
				array( 'wppo-lazyload', 'data-wppo-preserve' ),
				Util::process_urls( $this->options['file_optimisation']['excludeDelayJS'] ?? array() )
			);
			$this->exclude_delay_js = array_values( array_filter( $this->exclude_delay_js, function ( $val ) { return is_string( $val ) && strlen( $val ) > 0; } ) );

			// Cache delay JS strategy lists, filtering empty strings to avoid strpos('', $x) matching everything.
			$this->delay_js_idle_list        = array_values( array_filter( (array) Util::process_urls( $this->options['file_optimisation']['delayJSIdleList'] ?? array() ), function ( $val ) { return is_string( $val ) && strlen( $val ) > 0; } ) );
			$this->delay_js_viewport_list    = array_values( array_filter( (array) Util::process_urls( $this->options['file_optimisation']['delayJSViewportList'] ?? array() ), function ( $val ) { return is_string( $val ) && strlen( $val ) > 0; } ) );
			$this->delay_js_default_strategy = ! empty( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
				? sanitize_text_field( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
				: 'interaction';

			// Parse priority map.
			$this->delay_js_priority = array();
			$priority_raw            = Util::process_urls( $this->options['file_optimisation']['delayJSPriority'] ?? array() );
			foreach ( $priority_raw as $line ) {
				$parts = explode( ':', $line, 2 );
				if ( count( $parts ) === 2 ) {
					$handle = trim( $parts[0] );
					$level  = strtolower( trim( $parts[1] ) );
					if ( in_array( $level, array( 'high', 'normal', 'low' ), true ) ) {
						$this->delay_js_priority[ $handle ] = $level;
					}
				}
			}

			$this->minified_html = $this->minify_html( $html );
		}

		/**
		 * Initialize minification settings.
		 *
		 * @since 1.0.0
		 */
		private function initialize_minification_settings(): void {
			$this->html_min = new HtmlMin();
			// Get the home URL (e.g., http://localhost/awm).
			$home_url = Util::cached_home_url();

			// Parse the home URL and extract just the base domain (e.g., http://localhost).
			$parsed_url = wp_parse_url( $home_url );
			if ( false === $parsed_url || empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
				$parsed_url = wp_parse_url( site_url() );
			}

			// Guard against malformed or relative URLs that have no scheme/host.
			if ( ! is_array( $parsed_url ) || empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
				$base_url = Util::cached_home_url();
			} else {
				$base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];

				if ( ! empty( $parsed_url['port'] ) ) {
					$base_url .= ( ':' . $parsed_url['port'] );
				}
			}

			$remove_comments = ! empty( $this->options['file_optimisation']['removeHTMLComments'] );

			$this->html_min
			->doOptimizeViaHtmlDomParser( true )
			->doRemoveComments( $remove_comments )
			->doSumUpWhitespace( true )
			->doRemoveWhitespaceAroundTags( true )
			->doOptimizeAttributes( true )
			->doRemoveDefaultAttributes( true )
			->doRemoveDeprecatedAnchorName( true )
			->doRemoveDeprecatedScriptCharsetAttribute( true )
			->doRemoveDefaultMediaTypeFromStyleAndLinkTag( true )
			->doRemoveDeprecatedTypeFromScriptTag( true )
			->doRemoveEmptyAttributes( true )
			->doRemoveValueFromEmptyInput( true )
			->doSortCssClassNames( true )
			->doSortHtmlAttributes( true )
			->doRemoveSpacesBetweenTags( true )
			->doRemoveOmittedQuotes( false )
			->doRemoveOmittedHtmlTags( true )
			->doMakeSameDomainsLinksRelative( array( $base_url ) );
		}

		/**
		 * Minify HTML content.
		 *
		 * @param string $html The HTML content to minify.
		 * @return string Minified HTML content.
		 * @since 1.0.0
		 */
		private function minify_html( string $html ): string {
			$html = $this->modify_canonical_link( $html );

			$content_array = $this->extract_and_preserve_scripts_template( $html );
			$html          = $content_array[0];
			$scripts       = $content_array[1];

			if ( ! empty( $this->options['file_optimisation']['minifyInlineCSS'] ) ) {
				$html = $this->minify_inline_css( $html );
			}

			if ( ! empty( $this->options['file_optimisation']['minifyInlineJS'] ) || ! empty( $this->options['file_optimisation']['delayJS'] ) ) {
				$html = $this->minify_inline_js( $html );
			}

			if ( ! empty( $this->options['file_optimisation']['minifyHTML'] ) ) {
				try {
					$html = $this->html_min->minify( $html );
				} catch ( \Exception $e ) {
					do_action( 'wppo_debug_log', 'WPPO HTML minify failed: ' . $e->getMessage(), array( 'exception' => $e ) );
				}
			}

			if ( ! empty( $scripts ) ) {
				$html = $this->restore_preserved_scripts_template( $html, $scripts );
			}

			$html = $this->restore_canonical_link( $html );

			return $html;
		}

		/**
		 * Modify the canonical link in HTML.
		 *
		 * @param string $html The HTML content.
		 * @return string Modified HTML content.
		 * @since 1.0.0
		 */
		private function modify_canonical_link( string $html ): ?string {
			return preg_replace_callback(
				'#<link\b[^>]*\brel=(?:["\']?)(canonical|shortlink)(?:["\']?)[^>]*>#i',
				function ( $matches ) {
					$link_tag = preg_replace( '/\bhref\s*=/i', 'wppo-href=', $matches[0] );

					return $link_tag;
				},
				$html
			);
		}

		/**
		 * Extract and preserve script tags for later restoration.
		 *
		 * @param string $html The HTML content.
		 * @return array Updated HTML and preserved script tags.
		 * @since 1.0.0
		 */
		private function extract_and_preserve_scripts_template( $html ) {
			$scripts = array();

			$html = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $matches ) use ( &$scripts ) {
					$attributes = $matches[1];

					// Support quoted, unquoted, and empty values using regex and fallback extraction.
					if ( preg_match( '/\btype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $type_matches ) ) {
						$type = isset( $type_matches[3] ) ? $type_matches[3] : $type_matches[2];
						$type = strtolower( trim( $type ) );

						$exclude_types = array( 'text/javascript', 'application/ld+json', 'module', 'importmap' );
						if ( ! in_array( $type, $exclude_types, true ) ) {
							$scripts[] = $matches[0];
							return '<script data-wppo-preserve="' . ( count( $scripts ) - 1 ) . '"></script>';
						}
					}

					return $matches[0];
				},
				$html
			);

			return array( $html, $scripts );
		}

		/**
		 * Restore preserved script tags in HTML.
		 *
		 * @param string $html The HTML content.
		 * @param array  $scripts The preserved scripts.
		 * @return string Updated HTML content.
		 * @since 1.0.0
		 */
		private function restore_preserved_scripts_template( $html, $scripts ) {
			foreach ( $scripts as $index => $script ) {
				$html = str_replace( '<script data-wppo-preserve="' . ( $index ) . '"></script>', $script, $html );
			}

			return $html;
		}

		/**
		 * Restore the canonical link in HTML.
		 *
		 * @param string $html The HTML content.
		 * @return string HTML content with the canonical link restored.
		 * @since 1.0.0
		 */
		private function restore_canonical_link( string $html ): string {
			return preg_replace_callback(
				'#<link\b[^>]*\brel=(?:["\']?)(canonical|shortlink)(?:["\']?)[^>]*>#i',
				function ( $matches ) {
					$link_tag = preg_replace( '/\bwppo-href\s*=/i', 'href=', $matches[0] );

					return $link_tag;
				},
				$html
			);
		}

		/**
		 * Minify inline CSS in HTML.
		 *
		 * @param string $html The HTML content containing inline CSS.
		 * @return string HTML content with minified CSS.
		 * @since 1.0.0
		 */
		private function minify_inline_css( string $html ): string {
			$html = preg_replace_callback(
				'#<style\b[^>]*>(.*?)</style>#is',
				function ( $matches ) {
					try {
						$css_minifier = new CSSMinifier( $matches[1] );
						return '<style>' . $css_minifier->minify() . '</style>';
					} catch ( \Exception $e ) {
						// Return original content if there's an error.
						return $matches[0];
					}
				},
				$html
			);

			return $html;
		}

		/**
		 * Minify inline JavaScript in HTML.
		 *
		 * @param string $html The HTML content containing inline JS.
		 * @return string HTML content with minified JS.
		 * @since 1.0.0
		 */
		private function minify_inline_js( string $html ): string {
			return preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $matches ) {
					return $this->safe_minify_js( $matches[1], $matches[2] );
				},
				$html
			);
		}

		/**
		 * Minify inline JavaScript safely.
		 *
		 * @param string $attributes The script attributes.
		 * @param string $content The JavaScript content to minify.
		 * @return string Minified JS or original content if error occurs.
		 * @since 1.0.0
		 */
		private function safe_minify_js( string $attributes, string $content ): string {
			$content = trim( $content );

			// Support quoted, unquoted, and empty values using regex and fallback extraction.
			$type_matches = array();
			$script_type  = '';
			if ( preg_match( '/\btype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $type_matches ) ) {
				$script_type = isset( $type_matches[3] ) ? $type_matches[3] : $type_matches[2];
				$script_type = strtolower( trim( $script_type ) );
			}

			if ( 'application/ld+json' === $script_type || 'application/json' === $script_type ) {
				return $this->preserve_json_ld( $content, $attributes );
			}

			if ( '' !== $script_type && 'text/javascript' !== $script_type ) {
				// If a type attribute exists and is not 'text/javascript', return unmodified content.
				return '<script' . $attributes . '>' . $content . '</script>';
			}

			if ( ! empty( $this->options['file_optimisation']['delayJS'] ) ) {

				$should_exclude = false;
				if ( ! empty( $this->exclude_delay_js ) ) {
					foreach ( $this->exclude_delay_js as $exclude ) {
						if (
						false !== strpos( $attributes, trim( $exclude ) ) ||
						false !== strpos( $content, trim( $exclude ) )
						) {
							$should_exclude = true;
							break;
						}
					}
				}

				if ( ! $should_exclude ) {
					if ( preg_match( '/\btype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $type_matches ) ) {
						// Capture the original quote character or fallback to double quotes.
						$quote = ( ! empty( $type_matches[1] ) ) ? $type_matches[1] : '"';
						// Replace the type attribute unconditionally.
						$attributes = preg_replace(
							'/\btype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i',
							'type=' . $quote . 'wppo/javascript' . $quote . ' wppo-type=' . $quote . 'text/javascript' . $quote,
							$attributes
						);
					} else {
						// If the 'type' attribute doesn't exist, add a new one.
						$attributes .= ' type="wppo/javascript" wppo-type="text/javascript"';
					}

					// Add strategy and priority data attributes for inline scripts.
					$strategy = $this->get_delay_strategy_for_inline( $attributes, $content );
					if ( 'interaction' !== $strategy ) {
						$attributes .= ' data-wppo-delay-strategy="' . esc_attr( $strategy ) . '"';
					}
					$priority = $this->get_delay_priority_for_inline( $attributes, $content );
					if ( 'normal' !== $priority ) {
						$attributes .= ' data-wppo-delay-priority="' . esc_attr( $priority ) . '"';
					}
				}
			}

			if ( ! empty( $this->options['file_optimisation']['minifyInlineJS'] ) ) {
				try {
					$js_minifier = new JSMinifier( $content );
					return '<script' . $attributes . '>' . $js_minifier->minify() . '</script>';
				} catch ( \Exception $e ) {
					// Return original content if there's an error.
					return '<script' . $attributes . '>' . $content . '</script>';
				}
			}

			return '<script' . $attributes . '>' . $content . '</script>';
		}


		/**
		 * Preserve JSON-LD content by passing it through.
		 *
		 * @param string $content The JSON-LD content.
		 * @param string $attributes The script attributes.
		 * @return string Original script tag with content.
		 * @since 1.0.0
		 */
		private function preserve_json_ld( string $content, string $attributes ): string {
			// Pass through JSON-LD as-is; decode+re-encode cycle alters structured data.
			return '<script' . $attributes . '>' . $content . '</script>';
		}

		/**
		 * Determine delay strategy for an inline script.
		 *
		 * @since NEXT
		 *
		 * @param string $attributes Script tag attributes string.
		 * @param string $content    Inline script content.
		 * @return string Strategy: 'interaction', 'idle', or 'viewport'.
		 */
		private function get_delay_strategy_for_inline( string $attributes, string $content ): string {
			$search_in = $attributes . ' ' . $content;
			foreach ( $this->delay_js_idle_list as $pattern ) {
				if ( false !== strpos( $search_in, $pattern ) ) {
					return 'idle';
				}
			}
			foreach ( $this->delay_js_viewport_list as $pattern ) {
				if ( false !== strpos( $search_in, $pattern ) ) {
					return 'viewport';
				}
			}
			return $this->delay_js_default_strategy;
		}

		/**
		 * Determine delay priority for an inline script.
		 *
		 * @since NEXT
		 *
		 * @param string $attributes Script tag attributes string.
		 * @param string $content    Inline script content.
		 * @return string Priority: 'high', 'normal', or 'low'.
		 */
		private function get_delay_priority_for_inline( string $attributes, string $content ): string {
			$search_in = $attributes . ' ' . $content;
			foreach ( $this->delay_js_priority as $pattern => $level ) {
				if ( false !== strpos( $search_in, $pattern ) ) {
					return $level;
				}
			}
			return 'normal';
		}

		/**
		 * Get the minified HTML content.
		 *
		 * @return string Minified HTML content.
		 * @since 1.0.0
		 */
		public function get_minified_html(): string {
			return $this->minified_html;
		}
	}
}
