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
	exit;
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
		 * @since 2.0.0 Lazy-instantiated via get_html_min().
		 * @var HtmlMin|null $html_min
		 */
		private ?HtmlMin $html_min = null;

		/**
		 * Base URL used for the same-domain-links-relative HtmlMin option.
		 *
		 * Computed during initialize_minification_settings() and consumed by
		 * get_html_min() when the minifier is first built.
		 *
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * Precompiled case-insensitive alternation for $exclude_delay_js.
		 *
		 * Compiled once in the constructor so the per-<script> scan is a
		 * single preg_match instead of O(patterns) strpos calls per tag.
		 * Null when the list is empty or the regex fails to compile (callers
		 * fall back to the strpos loop). Intentionally over-matches generic
		 * fragments (consent, gallery, carousel, _ga, gtm) by substring over
		 * attributes+content — fail-safe direction (keeps scripts eager),
		 * unlike the external path which uses exact/dash/word-boundary
		 * handle matching — so audit parity between the two paths is
		 * approximate by design.
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private ?string $delay_exclude_re = null;

		/**
		 * Precompiled alternations for idle/viewport strategy lists.
		 *
		 * One preg_match per tag replaces the O(list) strpos scans in
		 * get_delay_strategy_for_inline(). Null when the list is empty.
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private ?string $delay_idle_re = null;

		/**
		 * Precompiled alternation for the viewport strategy list.
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private ?string $delay_viewport_re = null;

		/**
		 * Precompiled alternation for all priority keys.
		 *
		 * Quick-reject gate in get_delay_priority_for_inline(): a single
		 * preg_match decides whether the linear level-resolution loop runs.
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private ?string $delay_priority_re = null;

		/**
		 * Handles/URLs to load via requestIdleCallback.
		 *
		 * @since 2.0.0
		 * @var array
		 */
		private array $delay_js_idle_list = array();

		/**
		 * Handles/URLs to load when in viewport.
		 *
		 * @since 2.0.0
		 * @var array
		 */
		private array $delay_js_viewport_list = array();

		/**
		 * Default delay strategy.
		 *
		 * @since 2.0.0
		 * @var string
		 */
		private string $delay_js_default_strategy = 'interaction';

		/**
		 * Priority map handle=>level.
		 *
		 * @since 2.0.0
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
			// on; missing key backfills to on (shared source of truth:
			// Main::get_delay_js_builder_exclusions()).
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
			// Opt-in compat presets (#1308): consent, analytics, gallery, jquery.
			// Off by default (missing key backfills to off) so upgrades preserve
			// manual exclusions. Lazy-booted: matchers run only when delay is
			// enabled and their preset is on; merged additively, never replacing
			// manual exclusions. Fail-open: matcher errors keep eager output.
			// Per-preset try/catch: a throw on one preset must not abort the
			// remaining presets. Chunks merged once instead of array_merge() in
			// a loop. Snapshot the pre-compat list so the per-page opt-out below
			// can protect manual/safe entries.
			$delay_enabled = ! empty( $this->options['file_optimisation']['delayJS'] );
			// Base preset parity (#1308 review): the external path always starts
			// from Main::get_delay_js_base_preset_exclusions() (recaptcha/stripe/
			// gtag/analytics/gtm, …), so the inline path must merge it too —
			// otherwise inline base fragments get delayed while handles stay
			// eager. Merged before the $pre_compat snapshot so opt-outs cannot
			// strip overlapping base strings inline that stay protected
			// externally. Fail-open: errors keep the manual/safe list.
			$has_base_api = class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_base_preset_exclusions' );
			if ( $has_base_api ) {
				try {
					$this->exclude_delay_js = array_merge( $this->exclude_delay_js, Main::get_delay_js_base_preset_exclusions() );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$pre_compat     = $this->exclude_delay_js;
			$has_compat_api = class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_compat_preset_map' ) && method_exists( Main::class, 'get_delay_js_compat_preset_exclusions' );
			if ( $delay_enabled && $has_compat_api ) {
				$chunks = array();
				foreach ( Main::get_delay_js_compat_preset_map() as $setting_key => $slug ) {
					try {
						if ( ! empty( $this->options['file_optimisation'][ $setting_key ] ) ) {
							$chunks[] = Main::get_delay_js_compat_preset_exclusions( (string) $slug );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
				}
				if ( ! empty( $chunks ) ) {
					$merged = array_merge( ...$chunks );
					if ( ! empty( $merged ) ) {
						try {
							$this->exclude_delay_js = array_merge( $this->exclude_delay_js, $merged );
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
			}
			/**
			 * Filters handles and URL fragments excluded from delay JS.
				 *
				 * Main::apply_per_page_delay_config() applies this filter to the
				 * handle-level list it builds, but the rewrite that actually makes
				 * a script inert (`type="wppo/javascript"` + `wppo-src`) happens
				 * here. Without merging the filtered values into this list, an
				 * exclusion registered by a theme or plugin has no effect on the
				 * HTML rewrite: the script is still swapped to an inert type and
				 * the browser never executes it, which silently breaks whichever
				 * behaviour depended on it (a mobile menu, for example).
				 *
				 * Applying the filter to an empty array keeps append-style
				 * callbacks working exactly as they do in Main.
				 *
				 * @since NEXT
				 *
				 * @param array<int, string> $exclusions Handles or URL fragments to keep eager.
				 */
			if ( has_filter( 'wppo_exclude_delay_js' ) ) {
				try {
					$this->exclude_delay_js = array_merge(
						$this->exclude_delay_js,
						(array) apply_filters( 'wppo_exclude_delay_js', array() )
					);
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			// Per-page preset opt-out (#1308): subtract opted-out preset handles
			// AFTER the wppo_exclude_delay_js merge (filter-then-subtract), so a
			// filter entry matching a preset string cannot silently re-add an
			// opted-out entry and nullify the page opt-out. Only globally-enabled
			// presets contribute to the removal list, and strings already present
			// before the compat merge (manual/safe entries) are protected, so
			// overlapping entries (e.g. gtag, jquery) stay eager. Singular-only
			// to match Main::apply_per_page_delay_config() parity: archives/home
			// never re-delay inline scripts while the external path does not.
			$has_opt_out_api = class_exists( Main::class ) && method_exists( Main::class, 'get_page_disabled_delay_presets' ) && method_exists( Main::class, 'get_delay_js_compat_preset_exclusions' );
			if ( $delay_enabled && $has_opt_out_api ) {
				try {
					$is_singular = ! function_exists( 'is_singular' ) || is_singular();
					if ( $is_singular ) {
						$presets_off = Main::get_page_disabled_delay_presets();
						if ( ! empty( $presets_off ) ) {
							$opt_map     = Main::get_delay_js_compat_preset_map();
							$slug_to_key = array_flip( $opt_map );
							$opt_chunks  = array();
							foreach ( $presets_off as $slug ) {
								$slug = (string) $slug;
								if ( ! isset( $slug_to_key[ $slug ] ) || empty( $this->options['file_optimisation'][ $slug_to_key[ $slug ] ] ) ) {
									continue;
								}
								$opt_chunks[] = Main::get_delay_js_compat_preset_exclusions( $slug );
							}
							$remove = $opt_chunks ? array_merge( ...$opt_chunks ) : array();
							if ( ! empty( $remove ) ) {
								$remove = array_values( array_diff( $remove, $pre_compat ) );
								if ( ! empty( $remove ) ) {
									$this->exclude_delay_js = array_values( array_diff( $this->exclude_delay_js, $remove ) );
								}
							}
						}
					}
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

			// Precompile the exclusion alternation once (see $delay_exclude_re).
			$this->delay_exclude_re = self::compile_delay_exclude_re( $this->exclude_delay_js );

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
					if ( '' !== $handle && in_array( $level, array( 'high', 'normal', 'low' ), true ) ) {
						$this->delay_js_priority[ $handle ] = $level;
					}
				}
			}

			// Precompile strategy/priority alternations once (see props above).
			$this->delay_idle_re     = self::compile_delay_exclude_re( $this->delay_js_idle_list );
			$this->delay_viewport_re = self::compile_delay_exclude_re( $this->delay_js_viewport_list );
			$this->delay_priority_re = self::compile_delay_exclude_re( array_keys( $this->delay_js_priority ) );

			$this->minified_html = $this->minify_html( $html );
		}

		/**
		 * Compile a case-insensitive substring alternation for delay exclusions.
		 *
		 * One preg_match per <script> tag replaces the O(patterns) strpos loop.
		 * Fail-open: returns null on any error so callers fall back to strpos.
		 *
		 * @since NEXT
		 *
		 * @param string[] $exclusions Exclusion fragments.
		 * @return string|null Ready regex, or null when unusable.
		 */
		private static function compile_delay_exclude_re( array $exclusions ): ?string {
			try {
				$quoted = array();
				foreach ( $exclusions as $exclude ) {
					if ( ! is_string( $exclude ) || '' === $exclude ) {
						continue;
					}
					$exclude = trim( $exclude );
					if ( '' === $exclude ) {
						continue;
					}
					$quoted[] = preg_quote( $exclude, '/' );
				}
				if ( empty( $quoted ) ) {
					return null;
				}
				$re = '/(?:' . implode( '|', $quoted ) . ')/i';
				// Compile-time validation: alternatives are quoted literals so
				// this cannot backtrack-catastrophically; a false return here
				// (overlong pattern) degrades to the strpos fallback per tag.
				set_error_handler( static function () {} ); // phpcs:ignore -- Suppress warnings from validating the generated alternation.
				try {
					$valid = preg_match( $re, '' );
				} catch ( \Throwable $e ) {
					unset( $e );
					$valid = false;
				}
				restore_error_handler();
				return false === $valid ? null : $re;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Build a per-request cache key for third-party allow/deny settings.
		 *
		 * JSON-based (never serialize()) so the WordPress serialize() sniff
		 * stays clean; nested arrays encode deterministically. Returns null
		 * when the values cannot be encoded (callers skip the cache).
		 *
		 * @since NEXT
		 *
		 * @param mixed $allow_raw Allowlist raw setting value.
		 * @param mixed $extra_raw Denylist raw setting value.
		 * @return string|null Cache key.
		 */
		private static function third_party_settings_key( $allow_raw, $extra_raw ): ?string {
			try {
				$encode = static function ( $v ): string {
					if ( is_string( $v ) ) {
						return 's:' . $v;
					}
					if ( is_array( $v ) ) {
						$flat = array();
						foreach ( $v as $item ) {
							$flat[] = is_string( $item ) || is_numeric( $item ) ? (string) $item : gettype( $item );
						}
						if ( function_exists( 'wp_json_encode' ) ) {
							$json = wp_json_encode( $flat );
							return is_string( $json ) ? 'j:' . $json : 'a:' . implode( "\0", $flat );
						}
						return 'a:' . implode( "\0", $flat );
					}
					return gettype( $v ) . ':' . ( is_scalar( $v ) ? (string) $v : gettype( $v ) );
				};
				return md5( $encode( $allow_raw ) . "\0" . $encode( $extra_raw ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * @since 2.0.0
		 */
		private function get_script_type( string $attributes ): string {
			if ( preg_match( '/\btype\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $type_matches ) ) {
				// The unquoted alternative matches without groups 1-2, so
				// $type_matches[2] can be unset; reading it directly emits an
				// "Undefined array key" warning on PHP 8 for valueless or
				// unquoted type attributes.
				$type = '' !== ( $type_matches[2] ?? '' ) ? $type_matches[2] : ( $type_matches[3] ?? '' );
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
			$restored = preg_replace_callback(
				'#<link\b[^>]*\brel=(?:["\']?)(canonical|shortlink)(?:["\']?)[^>]*>#i',
				function ( $matches ) {
					$link_tag = preg_replace( '/\bwppo-href\s*=/i', 'href=', $matches[0] );

					return null !== $link_tag ? $link_tag : $matches[0];
				},
				$html
			);

			// PCRE failure: degrade to unoptimised markup, never fatal.
			return null !== $restored ? $restored : $html;
		}

		/**
		 * Minify a single inline CSS block with a fail-open contract.
		 *
		 * Wraps the `matthiasmullie/minify` CSS engine so any engine failure
		 * (`\Exception` or `\Error`/`\Throwable`) degrades to the pristine
		 * `<style…>…</style>` tag instead of fataling or emitting partial output.
		 * No new WP/PHP APIs; safe on WP 6.2+ / PHP 8.2+.
		 *
		 * @since 2.0.0
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

			// Sandbox preview (issue #1163): preview admins render staged
			// delayJS; visitors always use production. Fail-open to production.
			// Memoized per request: safe_minify_js runs per <script> tag, so the
			// staged overlay (get_option via Util::get_settings) must happen once.
			static $preview_effective_cache = null;
			$file_opt_for_preview           = isset( $this->options['file_optimisation'] ) && is_array( $this->options['file_optimisation'] ) ? $this->options['file_optimisation'] : array();
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'get_effective_file_optimisation' ) && method_exists( 'PerformanceOptimise\Inc\Sandbox_Preview', 'is_preview_request' ) ) {
					if ( \PerformanceOptimise\Inc\Sandbox_Preview::is_preview_request() ) {
						if ( null === $preview_effective_cache ) {
							$preview_effective_cache = \PerformanceOptimise\Inc\Sandbox_Preview::get_effective_file_optimisation( $file_opt_for_preview );
						}
						$file_opt_for_preview = $preview_effective_cache;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			if ( ! empty( $file_opt_for_preview['delayJS'] ) && ! self::is_delay_excluded_context() ) {
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
				if ( ! $skip_delay && ! empty( $file_opt_for_preview['delayJSExternalOnly'] ) ) {
					if ( ! preg_match( '/\ssrc\s*=/i', ' ' . (string) $attributes ) ) {
						$skip_delay = true;
					}
				}

				// One-click third-party delay (#1217) plus auto third-party
				// delay (#1314): mirror Main — inline scripts (no src) stay
				// eager; external scripts must match the curated denylist/host
				// check (manual mode) or the curated auto patterns (auto mode)
				// and survive the user allowlist. Auto patterns merge
				// additively with manual exclusions and per-page overrides
				// below, never replacing them. Fail-open: detection errors
				// leave the tag untouched.
				$manual_third_party_on = ! empty( $file_opt_for_preview['delayJSThirdParty'] );
				$auto_third_party_on   = ! empty( $file_opt_for_preview['delayJSThirdPartyAuto'] );
				// Auto-match verdict, reused by get_delay_strategy_for_inline()
				// below so each tag pays a single auto-pattern scan (not two:
				// gate + strategy). Null when auto mode is off.
				$auto_matched = null;
				if ( ! $skip_delay && ( $manual_third_party_on || $auto_third_party_on ) ) {
					try {
						$is_candidate = false;
						if ( $manual_third_party_on ) {
							$is_candidate = self::is_third_party_delay_candidate( (string) $attributes, (string) $content, $file_opt_for_preview );
						}
						if ( $auto_third_party_on ) {
							$auto_matched = self::is_third_party_auto_delay_candidate( (string) $attributes, (string) $content, $file_opt_for_preview );
							$is_candidate = $is_candidate || $auto_matched;
						}
						$skip_delay = ! $is_candidate;
					} catch ( \Throwable $e ) {
						unset( $e );
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
					// Fast path: one precompiled alternation per tag instead of
					// O(patterns) strpos calls. Falls back to the strpos loop
					// when the regex is unavailable or fails to run.
					$matched = false;
					if ( null !== $this->delay_exclude_re ) {
						try {
							// Validated at compile time; quoted literals cannot
							// fail to compile here, and a false return (engine
							// limits) falls back to the strpos loop below.
							$re = preg_match( $this->delay_exclude_re, (string) $attributes . "\0" . (string) $content );
							if ( 1 === $re ) {
								$matched = true;
							} elseif ( false === $re ) {
								$matched = null;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
							$matched = null;
						}
					} else {
						$matched = null;
					}
					if ( null === $matched ) {
						foreach ( $this->exclude_delay_js as $exclude ) {
							if (
							false !== strpos( $attributes, trim( $exclude ) ) ||
							false !== strpos( $content, trim( $exclude ) )
							) {
								$matched = true;
								break;
							}
						}
					}
					if ( true === $matched ) {
						$should_exclude = true;
					}
				}
				// Sandbox preview staged excludes (issue #1163): staged
				// excludeDelayJS lines also suppress delay in preview only.
				// Util::process_urls() is array-safe (string or array payload),
				// matching the constructor path for production excludes. Parsed
				// once per request (keyed by the raw staged value) instead of on
				// every <script> tag, since safe_minify_js runs per tag.
				if ( ! $skip_delay && ! $should_exclude && ! empty( $file_opt_for_preview['excludeDelayJS'] ) ) {
					try {
						static $staged_excludes_cache = null;
						static $staged_excludes_key   = null;
						static $staged_excludes_re    = null;
						$staged_raw                   = $file_opt_for_preview['excludeDelayJS'];
						if ( is_string( $staged_raw ) ) {
							$staged_key = $staged_raw;
						} elseif ( is_array( $staged_raw ) ) {
							$staged_key = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $staged_raw ) : implode( "\0", array_map( 'strval', $staged_raw ) );
						} else {
							$staged_key = (string) $staged_raw;
						}
						if ( null === $staged_excludes_cache || $staged_key !== $staged_excludes_key ) {
							$staged_excludes_cache = Util::process_urls( $staged_raw );
							$staged_excludes_key   = $staged_key;
							$staged_excludes_re    = self::compile_delay_exclude_re( (array) $staged_excludes_cache );
						}
						$staged_hit = null;
						if ( null !== $staged_excludes_re ) {
							try {
								$staged_hit = preg_match( $staged_excludes_re, (string) $attributes . "\0" . (string) $content );
							} catch ( \Throwable $e ) {
								unset( $e );
								$staged_hit = false;
							}
							if ( 1 === $staged_hit ) {
								$should_exclude = true;
							}
						}
						if ( ! $should_exclude && ( null === $staged_excludes_re || false === $staged_hit ) ) {
							$staged_lines = $staged_excludes_cache;
							foreach ( $staged_lines as $exclude ) {
								$exclude = trim( (string) $exclude );
								if ( '' === $exclude ) {
									continue;
								}
								if ( false !== strpos( $attributes, $exclude ) || false !== strpos( $content, $exclude ) ) {
									$should_exclude = true;
									break;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
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
					// Execution-order preservation (#1217): carry original
					// async/defer semantics so lazyload.js replays defer in
					// document order. Fill-gaps-only. The attr test runs
					// against the attributes with quoted values stripped so
					// `async`/`defer` inside attribute VALUES never counts as
					// the real boolean attribute (#1217 review).
					if ( false === strpos( $attributes, 'data-wppo-delay-exec' ) ) {
						$attrs_unquoted = preg_replace( '/"[^"]*"|\'[^\']*\'/', '""', $attributes );
						if ( ! is_string( $attrs_unquoted ) ) {
							$attrs_unquoted = $attributes;
						}
						if ( preg_match( '/\sasync(?=[\s=\/>]|$)/i', $attrs_unquoted ) ) {
							$attributes .= ' data-wppo-delay-exec="async"';
						} elseif ( preg_match( '/\sdefer(?=[\s=\/>]|$)/i', $attrs_unquoted ) ) {
							$attributes .= ' data-wppo-delay-exec="defer"';
						}
					}
					// The gate verdict above is reused so auto-matched tags do
					// not pay a second pattern scan here.
					$strategy = $this->get_delay_strategy_for_inline( $attributes, $content, $file_opt_for_preview, $auto_matched );
					if ( 'interaction' !== $strategy ) {
						$attributes .= ' data-wppo-delay-strategy="' . esc_attr( $strategy ) . '"';
					}
					$priority = $this->get_delay_priority_for_inline( $attributes, $content );
					if ( 'normal' !== $priority ) {
						$attributes .= ' data-wppo-delay-priority="' . esc_attr( $priority ) . '"';
					}
				}
			}

			if ( ! empty( $file_opt_for_preview['minifyInlineJS'] ) ) {
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
		 * Contract: callers must gate on is_third_party_auto_delay_candidate()
		 * first; this resolver does NOT re-check the allowlist or the
		 * builder/commerce exclusions. Pass the gate verdict via
		 * $is_auto_matched to avoid a second pattern scan; null means
		 * "not precomputed" and falls back to matching here.
		 *
		 * Per-page limitation: explicit per-page interaction pins
		 * (`_wppo_delay_strategies` meta) are handle-scoped and honored on
		 * the script_loader_tag path (Main) only. This buffered path has no
		 * handle, so an auto-matched tag resolves to idle here even when the
		 * same handle is page-pinned to interaction elsewhere.
		 *
		 * @since 2.0.0
		 *
		 * @param string    $attributes      Script tag attributes string.
		 * @param string    $content         Inline script content.
		 * @param array     $file_opt        Optional effective file_optimisation slice (preview-aware).
		 * @param bool|null $is_auto_matched Optional precomputed auto-candidate verdict.
		 * @return string Strategy: 'interaction', 'idle', or 'viewport'.
		 */
		private function get_delay_strategy_for_inline( string $attributes, string $content, array $file_opt = array(), ?bool $is_auto_matched = null ): string {
			$search_in = $attributes . ' ' . $content;
			if ( null !== $this->delay_idle_re ) {
				try {
					$hit = preg_match( $this->delay_idle_re, $search_in );
				} catch ( \Throwable $e ) {
					unset( $e );
					$hit = false;
				}
				if ( 1 === $hit ) {
					return 'idle';
				}
				if ( false === $hit ) {
					foreach ( $this->delay_js_idle_list as $pattern ) {
						if ( false !== strpos( $search_in, $pattern ) ) {
							return 'idle';
						}
					}
				}
			} else {
				foreach ( $this->delay_js_idle_list as $pattern ) {
					if ( false !== strpos( $search_in, $pattern ) ) {
						return 'idle';
					}
				}
			}
			if ( null !== $this->delay_viewport_re ) {
				try {
					$hit = preg_match( $this->delay_viewport_re, $search_in );
				} catch ( \Throwable $e ) {
					unset( $e );
					$hit = false;
				}
				if ( 1 === $hit ) {
					return 'viewport';
				}
				if ( false === $hit ) {
					foreach ( $this->delay_js_viewport_list as $pattern ) {
						if ( false !== strpos( $search_in, $pattern ) ) {
							return 'viewport';
						}
					}
				}
			} else {
				foreach ( $this->delay_js_viewport_list as $pattern ) {
					if ( false !== strpos( $search_in, $pattern ) ) {
						return 'viewport';
					}
				}
			}
			// Auto third-party mode (#1314): load-when-idle parity — markup
			// matching the curated auto patterns resolves to the idle strategy
			// (same as the manual delayJSIdleList path) instead of the
			// interaction default. Manual idle/viewport lists above win. Only
			// upgrades the interaction default: an explicit viewport default
			// is never loosened. Builder and commerce contexts never reach
			// here (safe_minify_js returns early). Fail-open to the default.
			if ( 'interaction' === $this->delay_js_default_strategy ) {
				try {
					$slice = $file_opt;
					if ( empty( $slice ) && isset( $this->options['file_optimisation'] ) && is_array( $this->options['file_optimisation'] ) ) {
						$slice = $this->options['file_optimisation'];
					}
					if ( ! empty( $slice['delayJSThirdPartyAuto'] ) ) {
						if ( true === $is_auto_matched ) {
							return 'idle';
						}
						if ( null === $is_auto_matched && self::markup_matches_third_party_auto_patterns( $attributes, $content ) ) {
							return 'idle';
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return $this->delay_js_default_strategy;
		}

		/**
		 * Determine delay priority for an inline script.
		 *
		 * @since 2.0.0
		 *
		 * @param string $attributes Script tag attributes string.
		 * @param string $content    Inline script content.
		 * @return string Priority: 'high', 'normal', or 'low'.
		 */
		private function get_delay_priority_for_inline( string $attributes, string $content ): string {
			if ( empty( $this->delay_js_priority ) ) {
				return 'normal';
			}
			$search_in = $attributes . ' ' . $content;
			// Quick-reject gate: skip the linear level-resolution loop when
			// no priority key appears in the tag at all.
			if ( null !== $this->delay_priority_re ) {
				try {
					$hit = preg_match( $this->delay_priority_re, $search_in );
				} catch ( \Throwable $e ) {
					unset( $e );
					$hit = false;
				}
				if ( 0 === $hit ) {
					return 'normal';
				}
			}
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
		 * @since 2.0.0
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
		 * @since 2.0.0
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
		 * Whether an inline-path script is a third-party delay candidate (#1217).
		 *
		 * Mirrors Main::is_delay_third_party_candidate() for buffered HTML:
		 * scripts without src stay eager; otherwise the curated denylist
		 * (Main::get_delay_js_third_party_denylist() when available),
		 * user denylist additions, user allowlist (wins), and cross-origin
		 * host detection decide. Fail-open to false on any error.
		 *
		 * Handle-vs-markup limitation: the buffered path sees only the tag
		 * attributes and inline content — the WP script handle is unavailable
		 * here — so denylist/allowlist entries that match a handle keyword
		 * (e.g. a handle containing 'gtag' with an opaque src) delay via the
		 * `script_loader_tag` filter path but stay eager here. When relying on
		 * handle keywords, also add a matching src/attribute fragment to the
		 * denylist (or filter) so both paths agree.
		 *
		 * @since NEXT
		 * @param string $attributes Script attributes string.
		 * @param string $content    Inline script content.
		 * @param array  $file_opt   Effective file_optimisation slice.
		 * @return bool True when the script should be delayed.
		 */
		private static function is_third_party_delay_candidate( string $attributes, string $content, array $file_opt ): bool {
			try {
				if ( ! preg_match( '/\ssrc\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $matches ) ) {
					return false;
				}
				$src = trim( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
				if ( '' === $src || 0 === strpos( $src, 'data:' ) || 0 === strpos( $src, 'blob:' ) ) {
					return false;
				}
				$haystack = $attributes . ' ' . $content;
				// Per-request parse cache (#1308 review): Util::process_urls()
				// on the allow/deny textarea settings plus home_url()/host
				// parsing ran once per <script> tag (O(T x (P+F+H))). The
				// parsed settings lists are keyed by the raw setting values
				// so identical tags reuse one parse per request; filters and
				// host detection still run per tag (fail-open).
				static $tp_parse_cache = array();
				$allow_raw             = $file_opt['delayJSThirdPartyAllowlist'] ?? '';
				$extra_raw             = $file_opt['delayJSThirdPartyDenylist'] ?? '';
				$tp_key                = self::third_party_settings_key( $allow_raw, $extra_raw );
				if ( null !== $tp_key && isset( $tp_parse_cache[ $tp_key ] ) ) {
					$settings_allowlist = $tp_parse_cache[ $tp_key ]['allow'];
					$extra_parsed       = $tp_parse_cache[ $tp_key ]['extra'];
				} else {
					$settings_allowlist = array();
					if ( is_string( $allow_raw ) && '' !== trim( $allow_raw ) ) {
						// Normalize commas: process_urls() splits on newlines only.
						$allow_normalized   = str_replace( ',', "\n", $allow_raw );
						$settings_allowlist = (array) Util::process_urls( $allow_normalized );
					} elseif ( is_array( $allow_raw ) ) {
						// Shared helper: nested arrays are dropped (never
						// cast to literal "Array"), values trimmed/deduped.
						$settings_allowlist = Util::coerce_string_list( $allow_raw );
					}
					$extra_parsed = array();
					if ( is_string( $extra_raw ) && '' !== trim( $extra_raw ) ) {
						$extra_parsed = (array) Util::process_urls( str_replace( ',', "\n", $extra_raw ) );
					} elseif ( is_array( $extra_raw ) ) {
						$extra_parsed = Util::coerce_string_list( $extra_raw );
					}
					if ( null !== $tp_key ) {
						$tp_parse_cache[ $tp_key ] = array(
							'allow' => $settings_allowlist,
							'extra' => $extra_parsed,
						);
					}
				}
				// User allowlist wins. The settings-derived list is built
				// first and passed into the filter (mirroring
				// Main::get_delay_js_third_party_allowlist()) so filters
				// written as array_merge($list, [...]) keep the user's
				// textarea entries on this path too (#1217 review).
				foreach ( $settings_allowlist as $allowed ) {
					$allowed = trim( (string) $allowed );
					if ( '' !== $allowed && false !== stripos( $haystack, $allowed ) ) {
						return false;
					}
				}
				if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_delay_js_third_party_allowlist' ) ) {
					try {
						$filtered = apply_filters( 'wppo_delay_js_third_party_allowlist', $settings_allowlist );
						if ( is_array( $filtered ) ) {
							// Coerce/dedupe mirroring Main: drop non-string
							// entries instead of casting nested arrays to "Array".
							$coerced = array_values(
								array_filter(
									array_map(
										static function ( $v ): string {
											return is_string( $v ) || is_numeric( $v ) ? (string) $v : '';
										},
										$filtered
									),
									static function ( $v ): bool {
										return '' !== trim( (string) $v );
									}
								)
							);
							foreach ( $coerced as $allowed ) {
								$allowed = trim( (string) $allowed );
								if ( '' !== $allowed && false !== stripos( $haystack, $allowed ) ) {
									return false;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Curated denylist + user additions (user part reused from cache).
				$denylist = array();
				if ( class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_third_party_denylist' ) ) {
					try {
						$denylist = Main::get_delay_js_third_party_denylist();
					} catch ( \Throwable $e ) {
						unset( $e );
						$denylist = array();
					}
				}
				if ( ! empty( $extra_parsed ) ) {
					$denylist = array_merge( $denylist, $extra_parsed );
				}
				foreach ( $denylist as $entry ) {
					$entry = trim( (string) $entry );
					if ( '' !== $entry && false !== stripos( $haystack, $entry ) ) {
						return true;
					}
				}
				// Cross-origin host auto-detection. Same-site hosts (apex, www,
				// first-party subdomains/CDN) stay eager — only genuinely
				// foreign hosts auto-qualify (#1217 review). Delegates to
				// Main::is_same_site_script_host() with an inline fallback.
				$site_host = '';
				$src_host  = '';
				if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
					try {
						$site_host = strtolower( (string) wp_parse_url( (string) home_url(), PHP_URL_HOST ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( function_exists( 'wp_parse_url' ) ) {
					$candidate = $src;
					if ( 0 === strpos( $candidate, '//' ) ) {
						$candidate = 'https:' . $candidate;
					}
					try {
						$src_host = strtolower( (string) wp_parse_url( $candidate, PHP_URL_HOST ) );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( '' === $src_host || '' === $site_host ) {
					return false;
				}
				try {
					if ( class_exists( Main::class ) && method_exists( Main::class, 'is_same_site_script_host' ) ) {
						return ! Main::is_same_site_script_host( $src_host, $site_host );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
				$norm_site = strtolower( (string) preg_replace( '/^www\./', '', rtrim( $site_host, '.' ) ) );
				$norm_src  = strtolower( (string) preg_replace( '/^www\./', '', rtrim( $src_host, '.' ) ) );
				if ( '' === $norm_site || '' === $norm_src ) {
					return false;
				}
				if ( $norm_src === $norm_site ) {
					return false;
				}
				return substr( $norm_src, -strlen( '.' . $norm_site ) ) !== '.' . $norm_site && substr( $norm_site, -strlen( '.' . $norm_src ) ) !== '.' . $norm_src;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether markup matches the curated auto third-party patterns (#1314).
		 *
		 * Matches patterns against the parsed src only (parity with
		 * Main::matches_third_party_auto_pattern()): scripts without src stay
		 * eager, and a vendor string merely mentioned in inline content never
		 * qualifies. Lazily boots the pattern list via Main when available
		 * (single source of truth); fails open to false when Main is
		 * unavailable or on any error. The matcher itself lazy-boots, so
		 * callers must gate on the auto toggle first.
		 *
		 * Handle-vs-markup limitation: the buffered path has no script handle,
		 * so handle-keyword matches only apply on the script_loader_tag path
		 * (Main); buffered matching is src-substring only.
		 *
		 * @since NEXT
		 * @param string $attributes Script attributes string.
		 * @param string $content    Inline script content (ignored; kept for signature parity).
		 * @return bool True on match.
		 */
		private static function markup_matches_third_party_auto_patterns( string $attributes, string $content ): bool {
			try {
				// Quick-reject scripts without src before booting the pattern
				// list or scanning content (pure-inline tags stay eager and
				// never pay the pattern scan; avoids copying large inline
				// bodies into a haystack). Case-insensitive so uppercase
				// <SCRIPT SRC=...> variants match parity with the
				// script_loader_tag path.
				if ( false === stripos( $attributes, 'src' ) ) {
					return false;
				}
				if ( ! preg_match( '/\ssrc\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $matches ) ) {
					return false;
				}
				$src = trim( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
				if ( '' === $src ) {
					return false;
				}
				unset( $content );
				$patterns = array();
				if ( class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_third_party_auto_patterns' ) ) {
					try {
						$patterns = Main::get_delay_js_third_party_auto_patterns();
					} catch ( \Throwable $e ) {
						unset( $e );
						$patterns = array();
					}
				}
				if ( empty( $patterns ) ) {
					return false;
				}
				foreach ( $patterns as $pattern ) {
					$pattern = trim( (string) $pattern );
					if ( '' !== $pattern && false !== stripos( $src, $pattern ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether buffered-path markup is an auto third-party delay candidate (#1314).
		 *
		 * Mirrors Main::is_delay_third_party_auto_candidate() for buffered
		 * HTML: scripts without src stay eager; builder/commerce contexts
		 * never auto-delay (unconditional); the user allowlist (via the shared
		 * Main::get_delay_js_third_party_allowlist_for_slice() helper, so
		 * allowlist semantics stay in one place) always wins against the src;
		 * then the curated auto patterns decide. The buffered path has no
		 * handle, so allowlist/pattern matching is src-substring only (a
		 * handle-only keyword exempts on the script_loader_tag path but not
		 * here — documented limitation). Manual exclusions and per-page
		 * overrides are applied by the caller, so auto patterns merge
		 * additively. Fail-open to false on any error.
		 *
		 * @since NEXT
		 * @param string $attributes Script attributes string.
		 * @param string $content    Inline script content (ignored except for signature parity).
		 * @param array  $file_opt   Effective file_optimisation slice.
		 * @return bool True when the script should be delayed in auto mode.
		 */
		private static function is_third_party_auto_delay_candidate( string $attributes, string $content, array $file_opt ): bool {
			try {
				// Quick-reject scripts without src before allowlist/pattern
				// work (pure-inline tags stay eager). Case-insensitive so
				// uppercase <SCRIPT SRC=...> variants match parity with the
				// script_loader_tag path.
				if ( false === stripos( $attributes, 'src' ) ) {
					return false;
				}
				if ( ! preg_match( '/\ssrc\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $matches ) ) {
					return false;
				}
				$src = trim( ! empty( $matches[2] ) ? $matches[2] : ( $matches[3] ?? '' ) );
				if ( '' === $src || 0 === stripos( $src, 'data:' ) || 0 === stripos( $src, 'blob:' ) ) {
					return false;
				}
				// Builder/commerce guardrail: unconditional, mirrors the
				// safe_minify_js() gate for defence in depth.
				if ( self::is_delay_excluded_context() ) {
					return false;
				}
				unset( $content );
				// User allowlist wins (src-substring on this path). Shared
				// helper keeps semantics identical to Main and memoizes the
				// parse per request keyed by the raw value.
				$allowlist = array();
				if ( class_exists( Main::class ) && method_exists( Main::class, 'get_delay_js_third_party_allowlist_for_slice' ) ) {
					try {
						$allowlist = Main::get_delay_js_third_party_allowlist_for_slice( $file_opt );
					} catch ( \Throwable $e ) {
						unset( $e );
						$allowlist = array();
					}
				} else {
					$allow_raw = $file_opt['delayJSThirdPartyAllowlist'] ?? '';
					if ( is_string( $allow_raw ) && '' !== trim( $allow_raw ) ) {
						$allowlist = (array) Util::process_urls( str_replace( ',', "\n", $allow_raw ) );
					} elseif ( is_array( $allow_raw ) ) {
						$allowlist = Util::coerce_string_list( $allow_raw );
					}
					if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_delay_js_third_party_allowlist' ) ) {
						try {
							$filtered = apply_filters( 'wppo_delay_js_third_party_allowlist', $allowlist );
							if ( is_array( $filtered ) ) {
								$allowlist = Util::coerce_string_list( $filtered );
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					} else {
						$allowlist = Util::coerce_string_list( $allowlist );
					}
				}
				foreach ( $allowlist as $allowed ) {
					$allowed = trim( (string) $allowed );
					if ( '' !== $allowed && false !== stripos( $src, $allowed ) ) {
						return false;
					}
				}
				return self::markup_matches_third_party_auto_patterns( $attributes, '' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
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
