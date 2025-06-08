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
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc\Minify;

use voku\helper\HtmlMin;
use MatthiasMullie\Minify\CSS as CSSMinifier;
use MatthiasMullie\Minify\JS as JSMinifier;
use PerformanceOptimise\Inc\Util;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * @var HtmlMin
	 */
	private HtmlMin $html_min;

	/**
	 * The resulting minified HTML content after processing.
	 *
	 * @since 1.0.0
	 * @var string
	 */
	private string $minified_html;

	/**
	 * Configuration options for minification, including settings for inline CSS and JavaScript.
	 *
	 * @since 1.0.0
	 * @var array<string, mixed>
	 */
	private array $options;

	/**
	 * Constructor to initialize HTML minification.
	 *
	 * @since 1.0.0
	 * @param string               $html The HTML content to minify.
	 * @param array<string, mixed> $options Minification options.
	 */
	public function __construct( string $html, array $options ) {
		$this->options = $options;
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
		$home_url       = home_url();

		$parsed_url = wp_parse_url( $home_url );
		$base_url   = ( $parsed_url['scheme'] ?? 'http' ) . '://' . ( $parsed_url['host'] ?? '' );

		if ( isset( $parsed_url['port'] ) && ! empty( $parsed_url['port'] ) ) {
			$base_url .= ':' . $parsed_url['port'];
		}

		$this->html_min
			->doOptimizeViaHtmlDomParser( true )
			->doRemoveComments( true )      // This is the culprit for comment-based placeholders.
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
			->doRemoveOmittedQuotes( true )
			->doRemoveOmittedHtmlTags( true )
			->doMakeSameDomainsLinksRelative( array( $base_url ) );
	}

	/**
	 * Minify HTML content.
	 *
	 * @since 1.0.0
	 * @param string $html The HTML content to minify.
	 * @return string Minified HTML content.
	 */
	private function minify_html( string $html ): string {
		$html = $this->modify_canonical_link_for_preservation( $html );

		list($html_before_minify, $preserved_elements) = $this->extract_and_preserve_scripts_template( $html );

		$html_to_process = $html_before_minify;

		if ( ! empty( $this->options['file_optimisation']['minifyInlineCSS'] ) && (bool) $this->options['file_optimisation']['minifyInlineCSS'] ) {
			$html_to_process = $this->minify_inline_css( $html_to_process );
		}
		if ( ! empty( $this->options['file_optimisation']['minifyInlineJS'] ) && (bool) $this->options['file_optimisation']['minifyInlineJS'] ) {
			$html_to_process = $this->minify_inline_js( $html_to_process );
		}

		$minified_html_content = $this->html_min->minify( $html_to_process );

		if ( ! empty( $preserved_elements ) ) {
			$minified_html_content = $this->restore_preserved_scripts_template( $minified_html_content, $preserved_elements );
		}

		$minified_html_content = $this->restore_preserved_canonical_link( $minified_html_content );

		return $minified_html_content;
	}

	/**
	 * Modify the canonical and shortlink href attributes to prevent minifier from altering them.
	 * Uses data-wppo-href for preservation.
	 *
	 * @since 1.0.0
	 * @param string $html The HTML content.
	 * @return string Modified HTML content.
	 */
	private function modify_canonical_link_for_preservation( string $html ): string {
		// This regex tries to be flexible with attributes before/after rel and href.
		return preg_replace_callback(
			'#<link\b(?P<before_rel>[^>]*?)\brel=(?P<quote_rel>["\'])(?P<rel_val>canonical|shortlink)(?P=quote_rel)(?P<between_rel_href>[^>]*?)href=(?P<quote_href>["\'])(?P<href_val>[^"\']+)(?P=quote_href)(?P<after_href>[^>]*?)>#i',
			function ( $matches ) {
				// Reconstruct the tag using data-wppo-href.
				return sprintf(
					'<link %s rel=%s%s%s %s data-wppo-href=%s%s%s %s>',
					trim( $matches['before_rel'] ),
					$matches['quote_rel'],
					$matches['rel_val'],
					$matches['quote_rel'],
					trim( $matches['between_rel_href'] ),
					$matches['quote_href'],
					$matches['href_val'], // Already captured without quotes
					$matches['quote_href'],
					trim( $matches['after_href'] )
				);
			},
			$html
		);
	}

	/**
	 * Restore the canonical and shortlink href attributes.
	 *
	 * @since 1.0.0
	 * @param string $html The HTML content.
	 * @return string HTML content with the canonical link restored.
	 */
	private function restore_preserved_canonical_link( string $html ): string {
		// This regex needs to find data-wppo-href.
		// Minifiers might change attribute order or spacing, so make it flexible.
		return preg_replace_callback(
			'#<link\b(?P<before_data_href>[^>]*?)\bdata-wppo-href=(?P<quote_data_href>["\'])(?P<data_href_val>[^"\']+)(?P=quote_data_href)(?P<after_data_href>[^>]*?)>#i',
			function ( $matches ) {
				// Reconstruct with href.
				return sprintf(
					'<link %s href=%s%s%s %s>',
					trim( $matches['before_data_href'] ),
					$matches['quote_data_href'],
					$matches['data_href_val'],
					$matches['quote_data_href'],
					trim( $matches['after_data_href'] )
				);
			},
			$html
		);
	}


	/**
	 * Extract and preserve script tags with specific types, and pre/code/textarea blocks
	 * using a script tag placeholder.
	 *
	 * @since 1.0.0
	 * @param string $html The HTML content.
	 * @return array{string, array<string>} Updated HTML and preserved elements.
	 */
	private function extract_and_preserve_scripts_template( $html ) {
		$scripts = array();

		$html = preg_replace_callback(
			'#<script\b([^>]*)>(.*?)</script>#is',
			function ( $matches ) use ( &$scripts ) {
				$attributes = $matches[1];

				if ( preg_match( '/type=("|\')([^"\']+)("|\')/', $attributes, $type_matches ) ) {
					$type = $type_matches[2];

					$exclude_types = array( 'text/javascript', 'application/ld+json', 'module', 'importmap' );
					if ( ! in_array( strtolower( $type ), $exclude_types, true ) ) {
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
	 * Restore preserved elements in HTML using script tag placeholders.
	 *
	 * @since 1.0.0
	 * @param string        $html The HTML content (potentially minified).
	 * @param array<string> $scripts The preserved elements.
	 * @return string Updated HTML content.
	 */
	private function restore_preserved_scripts_template( $html, $scripts ) {

		foreach ( $scripts as $index => $script ) {
			$html = str_replace( '<script data-wppo-preserve=' . ( $index ) . '></script>', $script, $html );
		}

		return $html;
	}

	/**
	 * Minify inline CSS in HTML.
	 *
	 * @since 1.0.0
	 * @param string $html The HTML content containing inline CSS.
	 * @return string HTML content with minified CSS.
	 */
	private function minify_inline_css( string $html ): string {
		return preg_replace_callback(
			'#<style\b(?P<attributes>[^>]*)>(?P<content>.*?)</style>#is',
			function ( $matches ) {
				if ( stripos( $matches['attributes'], 'data-wppo-preserve' ) !== false ) {
					return $matches[0];
				}
				try {
					$css_content = trim( $matches['content'] );
					if ( empty( $css_content ) ) {
						return $matches[0];
					}
					$css_minifier = new CSSMinifier( $css_content );
					$minified_css = $css_minifier->minify();
					return '<style' . $matches['attributes'] . '>' . $minified_css . '</style>';
				} catch ( \Exception $e ) {
					if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( 'Inline CSS Minification Error: ' . $e->getMessage() . ' - Content sample: ' . substr( $matches['content'], 0, 100 ) );
					}
					return $matches[0];
				}
			},
			$html
		);
	}

	/**
	 * Minify inline JavaScript in HTML.
	 *
	 * @since 1.0.0
	 * @param string $html The HTML content containing inline JS.
	 * @return string HTML content with minified JS.
	 */
	private function minify_inline_js( string $html ): string {
		return preg_replace_callback(
			'#<script\b(?P<attributes>[^>]*)>(?P<content>.*?)</script>#is',
			function ( $matches ) {
				if ( stripos( $matches['attributes'], 'src=' ) !== false || stripos( $matches['attributes'], 'data-wppo-preserve' ) !== false || stripos( $matches['attributes'], 'data-wppo-placeholder-id=' ) !== false ) {
					return $matches[0]; // Skip external scripts, preserved scripts, and our placeholders.
				}
				return $this->safe_minify_js( $matches['attributes'], $matches['content'] );
			},
			$html
		);
	}

	/**
	 * Minify inline JavaScript safely.
	 *
	 * @since 1.0.0
	 * @param string $attributes The script attributes.
	 * @param string $js_content The JavaScript content to minify.
	 * @return string Minified JS or original content if error occurs.
	 */
	private function safe_minify_js( string $attributes, string $js_content ): string {
		$trimmed_content = trim( $js_content );

		if ( empty( $trimmed_content ) ) {
			return '<script' . $attributes . '>' . $trimmed_content . '</script>';
		}

		$type_matches = array();
		preg_match( '/type=(["\'])(?P<type>[^"\']+)\1/i', $attributes, $type_matches );
		$script_type = strtolower( $type_matches['type'] ?? 'text/javascript' );

		$is_json_char = '{' === $trimmed_content[0] || '[' === $trimmed_content[0];
		if ( 'application/ld+json' === $script_type || ( 'text/javascript' === $script_type && $is_json_char ) ) {
			return $this->safe_json_encode( $trimmed_content, $attributes );
		}

		$allowed_js_types = array( 'text/javascript', 'application/javascript', 'application/ecmascript', 'module' );
		if ( ! in_array( $script_type, $allowed_js_types, true ) ) {
			return '<script ' . $attributes . '>' . $js_content . '</script>';
		}

		$delay_js_enabled = ! empty( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'];
		if ( $delay_js_enabled ) {
			$exclude_delay_js_config = $this->options['file_optimisation']['excludeDelayJS'] ?? '';
			$exclude_delay_keywords  = array_merge( array( 'wppo-lazyload', 'data-wppo-preserve', 'jquery.min.js', 'jquery.js' ), Util::process_urls( (string) $exclude_delay_js_config ) );
			$should_exclude_delay    = false;

			foreach ( $exclude_delay_keywords as $exclude_keyword ) {
				if ( ! empty( $exclude_keyword ) && ( stripos( $attributes, $exclude_keyword ) !== false || stripos( $js_content, $exclude_keyword ) !== false ) ) {
					$should_exclude_delay = true;
					break;
				}
			}

			if ( ! $should_exclude_delay ) {
				$new_type_attr = 'type="wppo/javascript" data-wppo-type="' . esc_attr( $script_type ) . '"';
				if ( isset( $type_matches[0] ) ) {
					$attributes = str_replace( $type_matches[0], $new_type_attr, $attributes );
				} else {
					// Prepend the type attribute to avoid issues if attributes string is empty.
					$attributes = $new_type_attr . ( ! empty( $attributes ) ? ' ' . $attributes : '' );
				}
			}
		}

		try {
			$js_minifier = new JSMinifier( $trimmed_content );
			$minified_js = $js_minifier->minify();
			return '<script ' . $attributes . '>' . $minified_js . '</script>';
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Inline JS Minification Error: ' . $e->getMessage() . ' - Attributes: ' . $attributes . ' - Content sample: ' . substr( $trimmed_content, 0, 100 ) );
			}
			return '<script ' . $attributes . '>' . $js_content . '</script>';
		}
	}

	/**
	 * Safely JSON encode content for ld+json scripts.
	 * It minifies by re-encoding.
	 *
	 * @since 1.0.0
	 * @param string $json_content The JSON-LD content to encode.
	 * @param string $attributes The script attributes.
	 * @return string Encoded JSON-LD or original content if error occurs.
	 */
	private function safe_json_encode( string $json_content, string $attributes ): string {
		try {
			// Basic check for valid JSON start/end.
			$trimmed_json = trim( $json_content );
			if ( ! ( ( str_starts_with( $trimmed_json, '{' ) && str_ends_with( $trimmed_json, '}' ) ) ||
					( str_starts_with( $trimmed_json, '[' ) && str_ends_with( $trimmed_json, ']' ) ) ) ) {
				// Not a typical JSON structure, return as is.
				return '<script' . $attributes . '>' . $json_content . '</script>';
			}

			$decoded_json = json_decode( $trimmed_json, true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return '<script' . $attributes . '>' . wp_json_encode( $decoded_json ) . '</script>';
			}
			// If decoding fails, it might be malformed or contain comments (which PHP json_decode doesn't support).
			// Return original content. A more robust solution might involve a JSON cleaner/minifier library.
			return '<script' . $attributes . '>' . $json_content . '</script>';
		} catch ( \Exception $e ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'JSON-LD Minification Error: ' . $e->getMessage() . ' - Content sample: ' . substr( $json_content, 0, 100 ) );
			}
			return '<script' . $attributes . '>' . $json_content . '</script>';
		}
	}

	/**
	 * Get the minified HTML content.
	 *
	 * @since 1.0.0
	 * @return string Minified HTML content.
	 */
	public function get_minified_html(): string {
		return $this->minified_html;
	}
}
