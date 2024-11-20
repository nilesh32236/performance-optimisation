<?php

namespace PerformanceOptimise\Inc\Minify;

use voku\helper\HtmlMin;
use MatthiasMullie\Minify\CSS as CSSMinifier;
use MatthiasMullie\Minify\JS as JSMinifier;

if ( ! defined( 'ABSPATH' ) ) {
	die();
}

/**
 * Class HTML
 *
 * Handles the minification of HTML, inline CSS, and inline JavaScript.
 */
class HTML {
	/**
	 * @var HtmlMin
	 */
	private HtmlMin $html_min;

	/**
	 * @var string
	 */
	private string $minified_html;

	private array $options;
	/**
	 * Minify constructor.
	 */
	public function __construct( $html, $options ) {
		$this->options = (array) $options;
		$this->initialize_minification_settings();
		$this->minified_html = $this->minify_html( $html );
	}

	/**
	 * Initialize minification settings.
	 *
	 * Configure the HTML minification options.
	 *
	 * @return void
	 */
	private function initialize_minification_settings(): void {
		$this->html_min = new HtmlMin();
		$this->html_min->doOptimizeViaHtmlDomParser( true )
			->doRemoveComments( true )
			->doSumUpWhitespace( true )
			->doRemoveWhitespaceAroundTags( true )
			->doOptimizeAttributes( true )
			->doRemoveDefaultAttributes( true )
			->doRemoveDeprecatedAnchorName( true )
			->doRemoveDeprecatedScriptCharsetAttribute( true )
			->doRemoveDefaultMediaTypeFromStyleAndLinkTag( true )
			->doRemoveEmptyAttributes( true )
			->doRemoveValueFromEmptyInput( true )
			->doSortCssClassNames( true )
			->doSortHtmlAttributes( true )
			->doRemoveSpacesBetweenTags( true )
			->doRemoveOmittedQuotes( true )
			->doRemoveOmittedHtmlTags( true )
			->doMakeSameDomainsLinksRelative( array( home_url() ) );
	}

	/**
	 * Minify HTML content.
	 *
	 * @param string $html The HTML content to minify.
	 * @return string The minified HTML content.
	 */
	private function minify_html( string $html ): string {
		$html = $this->modify_canonical_link( $html );

		$html = $this->minify_inline_css( $html );
		$html = $this->minify_inline_js( $html );

		$html = $this->html_min->minify( $html );

		$html = $this->restore_canonical_link( $html );

		return $html;
	}

	/**
	 * Extract the canonical link from the HTML.
	 *
	 * @param string $html The HTML content.
	 * @return string|null The extracted canonical link or null if not found.
	 */
	private function modify_canonical_link( string $html ): ?string {
		return preg_replace_callback(
			'#<link\b[^>]*\brel=["\'](canonical|shortlink)["\'][^>]*>#i',
			function ( $matches ) {
				$link_tag = str_replace( 'href', 'qtpo-href', $matches[0] );

				return $link_tag;
			},
			$html
		);

	}

	/**
	 * Restore the canonical link in the HTML content.
	 *
	 * @param string $html The minified HTML content.
	 * @param string $canonical_link The original canonical link to restore.
	 * @return string The HTML content with the restored canonical link.
	 */
	private function restore_canonical_link( string $html ): string {
		return preg_replace_callback(
			'#<link\b[^>]*\brel=["\'\](canonical|shortlink)["\'\][^>]*>#i',
			function ( $matches ) {
				$link_tag = str_replace( 'qtpo-href', 'href', $matches[0] );

				return $link_tag;
			},
			$html
		);
	}
	/**
	 * Minify inline CSS.
	 *
	 * @param string $html The HTML content containing inline CSS.
	 * @return string The HTML content with minified CSS.
	 */
	private function minify_inline_css( string $html ): string {
		$html = preg_replace_callback(
			'#<style\b[^>]*>(.*?)</style>#is',
			function ( $matches ) {
				try {
					$css_minifier = new CSSMinifier( $matches[1] );
					return '<style>' . $css_minifier->minify() . '</style>';
				} catch ( \Exception $e ) {
					// Log the error (optional)
					error_log( 'CSS minification error: ' . $e->getMessage() );
					// Return original content if there's an error
					return $matches[0];
				}
			},
			$html
		);

		return $html;
	}

	/**
	 * Minify inline JavaScript.
	 *
	 * @param string $html The HTML content containing inline JS.
	 * @return string The HTML content with minified JS.
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
	 * @return string The minified JS or original if an error occurs.
	 */
	private function safe_minify_js( string $attributes, string $content ): string {
		$content = trim( $content );

		if ( empty( $content ) ) {
			return '<script' . $attributes . '></script>'; // Return empty script tag if content is empty
		}

		$is_json = isset( $content[0] ) && ( '{' === $content[0] || '[' === $content[0] );
		if ( $is_json && strpos( $attributes, 'application/ld+json' ) !== false ) {
			return $this->safe_json_encode( $content, $attributes );
		}

		if ( isset( $this->options['file_optimisation']['delayJS'] ) && (bool) $this->options['file_optimisation']['delayJS'] ) {

			$exclude_delay = array();
			if ( isset( $this->options['file_optimisation']['excludeDelayJS'] ) && ! empty( $this->options['file_optimisation']['excludeDelayJS'] ) ) {
				$exclude_delay = explode( "\n", $this->options['file_optimisation']['excludeDelayJS'] );
				$exclude_delay = array_map( 'trim', $exclude_delay );
				$exclude_delay = array_filter( $exclude_delay );
			}

			$should_exclude = false;
			if ( ! empty( $exclude_delay ) ) {
				foreach ( $exclude_delay as $exclude ) {
					if ( false !== strpos( $attributes, trim( $exclude ) ) ) {
						$should_exclude = true;
						break;
					}

					if ( false !== strpos( $content, trim( $exclude ) ) ) {
						$should_exclude = true;
						break;
					}
				}
			}

			if ( ! $should_exclude ) {
				if ( preg_match( '/type=("|\')[^"\']*("|\')/', $attributes ) ) {
					// If the 'type' attribute exists, modify it
					$attributes = preg_replace(
						'/type=("|\')text\/javascript("|\')/',
						'type="qtpo/javascript" qtpo-type="text/javascript"',
						$attributes
					);
				} else {
					// If the 'type' attribute doesn't exist, add a new one
					$attributes .= ' type="qtpo/javascript" qtpo-type="text/javascript"';
				}
			}
		}

		try {
			$js_minifier = new JSMinifier( $content );
			return '<script' . $attributes . '>' . $js_minifier->minify() . '</script>';
		} catch ( \Exception $e ) {
			// Log the error (optional)
			error_log( 'JavaScript minification error: ' . $e->getMessage() );
			// Return original content if there's an error
			return '<script' . $attributes . '>' . $content . '</script>';
		}
	}

	/**
	 * Safely JSON encode the content.
	 *
	 * @param string $content The JSON-LD content to encode.
	 * @param string $attributes The script attributes.
	 * @return string The encoded JSON-LD or original content if an error occurs.
	 */
	private function safe_json_encode( string $content, string $attributes ): string {
		try {
			return '<script' . $attributes . '>' . wp_json_encode( json_decode( $content, true ) ) . '</script>';
		} catch ( \Exception $e ) {
			// Log the error (optional)
			error_log( 'JSON decode error: ' . $e->getMessage() );
			// Return original content if there's an error
			return '<script' . $attributes . '>' . $content . '</script>';
		}
	}

	/**
	 * Get the minified HTML content.
	 *
	 * @return string The minified HTML content.
	 */
	public function get_minified_html(): string {
		return $this->minified_html;
	}
}
