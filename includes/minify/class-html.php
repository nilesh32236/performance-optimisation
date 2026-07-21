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
		 * Constructor to initialize HTML minification.
		 *
		 * @param string $html The HTML content to minify.
		 * @param array  $options Minification options.
		 * @since 1.0.0
		 */
		public function __construct( $html, $options ) {
			$this->options = (array) $options;
			$this->initialize_minification_settings();
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
			$home_url = home_url();

			// Parse the home URL and extract just the base domain (e.g., http://localhost).
			$parsed_url = wp_parse_url( $home_url );
			if ( false === $parsed_url || empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
				$parsed_url = wp_parse_url( site_url() );
			}

			// Guard against malformed or relative URLs that have no scheme/host.
			if ( ! is_array( $parsed_url ) || empty( $parsed_url['scheme'] ) || empty( $parsed_url['host'] ) ) {
				$base_url = home_url();
			} else {
				$base_url = $parsed_url['scheme'] . '://' . $parsed_url['host'];

				if ( isset( $parsed_url['port'] ) && ! empty( $parsed_url['port'] ) ) {
					$base_url .= ( ':' . $parsed_url['port'] );
				}
			}

			$this->html_min
			->doOptimizeViaHtmlDomParser( true )
			->doRemoveComments( true )
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

			if ( isset( $this->options['file_optimisation']['minifyInlineCSS'] ) && (bool) $this->options['file_optimisation']['minifyInlineCSS'] ) {
				$html = $this->minify_inline_css( $html );
			}

			if ( ( isset( $this->options['file_optimisation']['minifyInlineJS'] ) && (bool) $this->options['file_optimisation']['minifyInlineJS'] ) ||
			( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) ) {
				$html = $this->minify_inline_js( $html );
			}

			if ( isset( $this->options['file_optimisation']['minifyHTML'] ) && (bool) $this->options['file_optimisation']['minifyHTML'] ) {
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
						$type = isset( $type_matches[3] ) ? $type_matches[3] : ( $type_matches[2] ?? '' );
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
					$link_tag = str_replace( 'wppo-href', 'href', $matches[0] );

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
				$script_type = isset( $type_matches[3] ) ? $type_matches[3] : ( $type_matches[2] ?? '' );
				$script_type = strtolower( trim( $script_type ) );
			}

			if ( 'application/ld+json' === $script_type || 'application/json' === $script_type ) {
				return $this->preserve_json_ld( $content, $attributes );
			}

			if ( '' !== $script_type && 'text/javascript' !== $script_type ) {
				// If a type attribute exists and is not 'text/javascript', return unmodified content.
				return '<script' . $attributes . '>' . $content . '</script>';
			}

			if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {

				$exclude_delay = array_merge( array( 'wppo-lazyload', 'data-wppo-preserve' ), Util::process_urls( $this->options['file_optimisation']['excludeDelayJS'] ?? array() ) );
				$exclude_delay = array_values( array_filter( $exclude_delay, 'strlen' ) );

				$should_exclude = false;
				if ( ! empty( $exclude_delay ) ) {
					foreach ( $exclude_delay as $exclude ) {
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
				}
			}

			if ( isset( $this->options['file_optimisation']['minifyInlineJS'] ) && (bool) $this->options['file_optimisation']['minifyInlineJS'] ) {
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
