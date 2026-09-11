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
use PerformanceOptimise\Inc\Main;

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
		 * Lazily instantiated on first use (audit #888 finding 26): building
		 * and configuring HtmlMin runs parser setup even when minifyHTML is
		 * disabled, so it is deferred until HTML minification actually runs.
		 *
		 * @since 1.0.0
		 * @since NEXT Lazy-instantiated via get_html_min().
		 * @var HtmlMin|null $html_min
		 */
		private ?HtmlMin $html_min = null;

		/**
		 * Base URL used for the same-domain-links-relative HtmlMin option.
		 *
		 * Computed during initialize_minification_settings() and consumed by
		 * get_html_min() when the minifier is first built.
		 *
		 * @since NEXT
		 * @var string
		 */
		private string $html_min_base_url = '';

		/**
		 * Per-request random namespace for preserved-script placeholder tokens.
		 *
		 * In-memory only (never persisted to `wppo_settings`), so it is
		 * multisite-safe by construction: each request/instance mints its own
		 * namespace and only tokens carrying it can be restored.
		 *
		 * @since NEXT
		 * @var string
		 */
		private string $preserve_namespace = '';

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
			// Builder safe preset (#966): mirror Main::get_delay_js_builder_exclusions()
			// via the shared static helper so the lists never drift. Safe-by-default
			// on; missing key backfills to on. See Main::get_delay_js_preset_exclusions().
			$builder_on = ! isset( $this->options['file_optimisation']['delayJSBuilderPreset'] )
				|| ! empty( $this->options['file_optimisation']['delayJSBuilderPreset'] );
			if ( $builder_on && class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_builder_exclusions' ) ) {
				try {
					$this->exclude_delay_js = array_merge( $this->exclude_delay_js, Main::get_delay_js_builder_exclusions() );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( method_exists( Main::class, 'get_delay_js_slider_exclusions' ) ) {
					try {
						$this->exclude_delay_js = array_merge( $this->exclude_delay_js, Main::get_delay_js_slider_exclusions() );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			}
			// Commerce safe preset (#988): mirror Main::get_delay_js_commerce_exclusions()
			// via the shared static helper so the lists never drift. Safe-by-default
			// on; missing key backfills to on.
			$commerce_on = ! isset( $this->options['file_optimisation']['delayJSCommercePreset'] )
				|| ! empty( $this->options['file_optimisation']['delayJSCommercePreset'] );
			if ( $commerce_on && class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_commerce_exclusions' ) ) {
				try {
					$this->exclude_delay_js = array_merge( $this->exclude_delay_js, Main::get_delay_js_commerce_exclusions() );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// Interaction safe preset (#1055): first-click popup/dialog,
			// mobile-menu, and add-to-cart handles. Safe-by-default on;
			// missing key backfills to on.
			$interaction_on = ! isset( $this->options['file_optimisation']['delayJSInteractionPreset'] )
				|| ! empty( $this->options['file_optimisation']['delayJSInteractionPreset'] );
			if ( $interaction_on && class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_interaction_exclusions' ) ) {
				try {
					$this->exclude_delay_js = array_merge( $this->exclude_delay_js, Main::get_delay_js_interaction_exclusions() );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$this->exclude_delay_js = array_values(
				array_unique(
					array_filter(
						$this->exclude_delay_js,
						static function ( mixed $val ): bool {
							return is_string( $val ) && '' !== $val;
						}
					)
				)
			);

			// Cache delay JS strategy lists, filtering empty strings to avoid strpos('', $x) matching everything.
			$this->delay_js_idle_list        = array_values(
				array_filter(
					(array) Util::process_urls( $this->options['file_optimisation']['delayJSIdleList'] ?? array() ),
					static function ( mixed $val ): bool {
						return is_string( $val ) && '' !== $val;
					}
				)
			);
			$this->delay_js_viewport_list    = array_values(
				array_filter(
					(array) Util::process_urls( $this->options['file_optimisation']['delayJSViewportList'] ?? array() ),
					static function ( mixed $val ): bool {
						return is_string( $val ) && '' !== $val;
					}
				)
			);
			$this->delay_js_default_strategy = ! empty( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
				? sanitize_text_field( $this->options['file_optimisation']['delayJSDefaultStrategy'] )
				: 'interaction';

			// INP-first preset (#932): mirror Main::setup_hooks() — an explicit
			// non-interaction manual default wins, otherwise idle-first so inline
			// scripts inherit the preset default while idle/viewport lists still
			// take precedence. In-memory only; stored option untouched.
			if ( ! empty( $this->options['file_optimisation']['delayJSINPPreset'] ) ) {
				$stored_default = isset( $this->options['file_optimisation']['delayJSDefaultStrategy'] ) ? strtolower( trim( (string) $this->options['file_optimisation']['delayJSDefaultStrategy'] ) ) : '';
				if ( '' === $stored_default || 'interaction' === $stored_default ) {
					$this->delay_js_default_strategy = 'idle';
				}
			}

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
			// HtmlMin is lazy-instantiated by get_html_min() (audit #888
			// finding 26) so disabled HTML minification pays no setup cost.

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

			$this->html_min_base_url = $base_url;
		}

		/**
		 * Get (and lazily configure) the HtmlMin instance.
		 *
		 * @since NEXT
		 * @return HtmlMin
		 */
		private function get_html_min(): HtmlMin {
			if ( null !== $this->html_min ) {
				return $this->html_min;
			}

			$html_min = new HtmlMin();

			$remove_comments = ! empty( $this->options['file_optimisation']['removeHTMLComments'] );

			$html_min
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
			->doMakeSameDomainsLinksRelative( array( $this->html_min_base_url ) );

			$this->html_min = $html_min;

			return $this->html_min;
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
					$html = $this->get_html_min()->minify( $html );
				} catch ( \Throwable $e ) {
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
		 * Get (and lazily mint) the per-request preserve namespace.
		 *
		 * Uses cryptographically random hex via `random_bytes()` when available,
		 * falling back to `wp_generate_password()` (sanitized to alphanumerics)
		 * and finally to a `uniqid()`/`wp_rand()` token. Never fatals: any
		 * failure degrades to a static fallback namespace (fail-open).
		 *
		 * @since NEXT
		 * @return string Non-empty namespace string.
		 */
		private function get_preserve_namespace(): string {
			if ( '' !== $this->preserve_namespace ) {
				return $this->preserve_namespace;
			}

			$this->preserve_namespace = Util::mint_placeholder_namespace();

			return $this->preserve_namespace;
		}

		/**
		 * Resolve a single preserved-script placeholder token against the allowlist.
		 *
		 * Strict restore discipline (CVE-2026-3220 shape): the token must carry
		 * this request's namespace (constant-time comparison) and a numeric
		 * index within bounds of `$scripts`. Any anomaly returns null so the
		 * caller emits the node unmodified (fail-open).
		 *
		 * @since NEXT
		 * @param string $token   The matched placeholder tag.
		 * @param array  $scripts The preserved scripts allowlist.
		 * @return string|null Restored script HTML, or null on anomaly.
		 */
		private function resolve_preserved_script( string $token, array $scripts ): ?string {
			if ( 1 !== preg_match( '~^<script\s+data-wppo-preserve=(["\'])([A-Za-z0-9_-]+)-(\d+)\1\s*></script>$~i', $token, $matches ) ) {
				return null;
			}

			$namespace          = $matches[2];
			$index_raw          = $matches[3];
			$namespace_expected = $this->preserve_namespace;

			if ( '' === $namespace_expected || '' === $namespace ) {
				return null;
			}

			if ( strlen( $namespace ) !== strlen( $namespace_expected ) ) {
				return null;
			}

			if ( function_exists( 'hash_equals' ) ) {
				if ( ! hash_equals( $namespace_expected, $namespace ) ) {
					return null;
				}
			} elseif ( $namespace !== $namespace_expected ) {
				return null;
			}

			if ( ! ctype_digit( $index_raw ) ) {
				return null;
			}

			$index = (int) $index_raw;
			if ( $index < 0 || $index >= count( $scripts ) ) {
				return null;
			}

			if ( ! isset( $scripts[ $index ] ) || ! is_string( $scripts[ $index ] ) ) {
				return null;
			}

			return $scripts[ $index ];
		}

		/**
		 * Extract the script type from attributes string.
		 *
		 * @param string $attributes The script attributes string.
		 * @return string The extracted and lowercased script type, or an empty string.
		 * @since NEXT
		 */
		private function get_script_type( string $attributes ): string {
			if ( preg_match( '/\btype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $type_matches ) ) {
				$type = '' !== $type_matches[2] ? $type_matches[2] : ( $type_matches[3] ?? '' );
				return strtolower( trim( $type ) );
			}
			return '';
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
			$scripts   = array();
			$namespace = $this->get_preserve_namespace();

			$extracted = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $matches ) use ( &$scripts, $namespace ) {
					$attributes = $matches[1];

					$type = $this->get_script_type( $attributes );

					// Support quoted, unquoted, and empty values using regex and fallback extraction.
					if ( '' !== $type || preg_match( '/\btype\s*=/i', $attributes ) ) {
						$exclude_types = array( 'text/javascript', 'application/ld+json', 'module', 'importmap' );
						if ( ! in_array( $type, $exclude_types, true ) ) {
							$scripts[] = $matches[0];
							return '<script data-wppo-preserve="' . $namespace . '-' . ( count( $scripts ) - 1 ) . '"></script>';
						}
					}

					return $matches[0];
				},
				$html
			);

			// PCRE failure: keep the original HTML rather than assigning null
			// (which would break downstream string handling). Mirrors the
			// noscript extraction guard.
			if ( null !== $extracted ) {
				$html = $extracted;
			}

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
			if ( ! is_string( $html ) || empty( $scripts ) ) {
				return $html;
			}

			$restored = preg_replace_callback(
				'~<script\s+data-wppo-preserve=(["\'])[^"\']*\1\s*></script>~i',
				function ( $matches ) use ( $scripts ) {
					$resolved = $this->resolve_preserved_script( $matches[0], $scripts );
					// Fail-open: attacker-controlled or out-of-range tokens are
					// emitted unmodified so they stay inert.
					return null !== $resolved ? $resolved : $matches[0];
				},
				$html
			);

			// PCRE failure: degrade to unoptimised markup, never fatal.
			return null !== $restored ? $restored : $html;
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
		 * Minify a single inline CSS block with a fail-open contract.
		 *
		 * Wraps the `matthiasmullie/minify` CSS engine so any engine failure
		 * (`\Exception` or `\Error`/`\Throwable`) degrades to the pristine
		 * `<style…>…</style>` tag instead of fataling or emitting partial output.
		 * No new WP/PHP APIs; safe on WP 6.2+ / PHP 8.2+.
		 *
		 * @since NEXT
		 * @param string $attrs Style tag attributes string (including leading space).
		 * @param string $css   Raw CSS block content.
		 * @return string Minified style tag, or the pristine tag on failure.
		 */
		private function safe_minify_css_block( string $attrs, string $css ): string {
			$pristine = '<style' . $attrs . '>' . $css . '</style>';
			try {
				if ( ! class_exists( CSSMinifier::class ) ) {
					return $pristine;
				}
				$css_minifier = new CSSMinifier( $css );
				return '<style' . $attrs . '>' . $css_minifier->minify() . '</style>';
			} catch ( \Throwable $e ) {
				// Fail-open: return original content, never fatal/white-screen.
				unset( $e );
				return $pristine;
			}
		}

		/**
		 * Minify inline CSS in HTML.
		 *
		 * @param string $html The HTML content containing inline CSS.
		 * @return string HTML content with minified CSS.
		 * @since 1.0.0
		 */
		private function minify_inline_css( string $html ): string {
			$result = preg_replace_callback(
				'#<style\b([^>]*)>(.*?)</style>#is',
				function ( $matches ) {
					return $this->safe_minify_css_block( $matches[1], $matches[2] );
				},
				$html
			);

			// PCRE failure: fail-open to the pristine buffer.
			return null !== $result ? $result : $html;
		}

		/**
		 * Minify inline JavaScript in HTML.
		 *
		 * Fail-open: a PCRE failure returns the pristine buffer unchanged.
		 *
		 * @param string $html The HTML content containing inline JS.
		 * @return string HTML content with minified JS.
		 * @since 1.0.0
		 */
		private function minify_inline_js( string $html ): string {
			$result = preg_replace_callback(
				'#<script\b([^>]*)>(.*?)</script>#is',
				function ( $matches ) {
					return $this->safe_minify_js( $matches[1], $matches[2] );
				},
				$html
			);

			return null !== $result ? $result : $html;
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
			$script_type = $this->get_script_type( $attributes );

			if ( 'application/ld+json' === $script_type || 'application/json' === $script_type ) {
				return $this->preserve_json_ld( $content, $attributes );
			}

			if ( '' !== $script_type && 'text/javascript' !== $script_type ) {
				// If a type attribute exists and is not 'text/javascript', return unmodified content.
				return '<script' . $attributes . '>' . $content . '</script>';
			}

			if ( ! empty( $this->options['file_optimisation']['delayJS'] ) && ! self::is_delay_excluded_context() ) {
				// Per-page kill-switch (#966) and external-only mode only skip
				// the delay rewrite below; inline-JS minification still runs.
				$skip_delay = false;

				// Per-page kill-switch (#966): skip inline delay for this page.
				if ( class_exists( Main::class ) && method_exists( Main::class, 'is_delay_disabled_for_page' ) ) {
					try {
						if ( Main::is_delay_disabled_for_page() ) {
							$skip_delay = true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}

				// External-scripts-only mode (#966): inline scripts (no src
				// attribute) stay un-delayed; only external scripts delay.
				// Fail-open: leave the tag untouched by the delay rewrite.
				if ( ! $skip_delay && ! empty( $this->options['file_optimisation']['delayJSExternalOnly'] ) ) {
					if ( ! preg_match( '/\ssrc\s*=/i', ' ' . (string) $attributes ) ) {
						$skip_delay = true;
					}
				}

				$should_exclude = $skip_delay;
				// Localised/config inline scripts (#1055): `wp_localize_script()`
				// (`id="*-js-extra"`) and `wp_add_inline_script(..., 'before'|'after')`
				// (`id="*-js-before|after"`, CDATA payloads) must stay un-delayed
				// so dependents read their config on first interaction. Fail-open:
				// matcher errors never exclude (delay proceeds).
				if ( ! $skip_delay && ! $should_exclude && self::is_localised_inline_script( (string) $attributes, (string) $content ) ) {
					$should_exclude = true;
				}
				if ( ! $skip_delay && ! empty( $this->exclude_delay_js ) ) {
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
				// Unified fail-open safe-mode (#1037): any engine throwable
				// (`\Exception` or `\Error`) returns the pristine buffer so
				// Elementor/Woo inline scripts survive minify failures.
				try {
					if ( ! class_exists( JSMinifier::class ) ) {
						return '<script' . $attributes . '>' . $content . '</script>';
					}
					$js_minifier = new JSMinifier( $content );
					return '<script' . $attributes . '>' . $js_minifier->minify() . '</script>';
				} catch ( \Throwable $e ) {
					// Return original content if there's an error.
					unset( $e );
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
		 * Whether an inline script is a localised/config payload (issue #1055).
		 *
		 * `wp_localize_script()` emits `id="*-js-extra"` with a CDATA/var
		 * payload and `wp_add_inline_script(..., 'before'|'after')` emits
		 * `id="*-js-before|after"`. Delaying these breaks dependents on
		 * first click, so they stay un-delayed. Fail-open: any error
		 * returns false (delay proceeds) so detection never fatals.
		 *
		 * @since NEXT
		 *
		 * @param string $attributes Script tag attributes string.
		 * @param string $content    Inline script content.
		 * @return bool True when the script must stay un-delayed.
		 */
		private static function is_localised_inline_script( string $attributes, string $content ): bool {
			try {
				$attrs = strtolower( $attributes );
				if ( false !== strpos( $attrs, '-js-extra' ) || false !== strpos( $attrs, '-js-before' ) || false !== strpos( $attrs, '-js-after' ) ) {
					return true;
				}
				if ( false !== stripos( $content, '<![CDATA[' ) || false !== stripos( $content, '/* <![CDATA[ */' ) ) {
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether the current request must skip delay-JS rewriting.
		 *
		 * Mirrors the external-script guardrail in Main::is_delay_excluded_context()
		 * so cart/checkout/account and builder preview/edit contexts skip inline
		 * rewriting too. Delegates to Main when available; fails open to delay
		 * (false) when Main is not loaded so minification never fatals.
		 *
		 * @since NEXT
		 *
		 * @return bool True when delay must be skipped for this request.
		 */
		private static function is_delay_excluded_context(): bool {
			if ( class_exists( Main::class ) && method_exists( Main::class, 'is_delay_excluded_context' ) ) {
				return Main::is_delay_excluded_context();
			}
			return false;
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
