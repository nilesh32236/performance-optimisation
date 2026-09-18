<?php
/**
 * Used_CSS class for removing unused CSS rules per page.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.9.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Used_CSS' ) ) {

	/**
	 * Class Used_CSS
	 *
	 * Parses HTML to extract used selectors, parses CSS, and removes
	 * unused rules. Stores per-page used-CSS files in the cache directory.
	 *
	 * @since 1.9.0
	 */
	class Used_CSS {

		/**
		 * Cache root directory constant.
		 *
		 * @var string
		 * @since 1.9.0
		 */
		private const CACHE_ROOT_DIR = '/cache/wppo';

		/**
		 * Used-CSS filename constant.
		 *
		 * @var string
		 * @since 1.9.0
		 */
		private const USED_CSS_FILENAME = 'used-css.css';

		/**
		 * Option holding the last full-regeneration timestamp (issue #1107).
		 *
		 * Coarse cooldown so regenerate_all() cannot re-queue the whole
		 * site on every call: Action Scheduler de-duplication only covers
		 * pending jobs, so completed work was re-queued unconditionally.
		 * Per-site option (core get_option is multisite-safe).
		 *
		 * @since 2.2.0
		 * @var string
		 */
		public const LAST_FULL_REGEN_OPTION = 'wppo_used_css_last_full_regen';

		/**
		 * Default full-regeneration cooldown in seconds (issue #1107).
		 *
		 * Matches the wppo_used_css_cron every-5-hours schedule; filterable
		 * via wppo_used_css_regen_cooldown.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const FULL_REGEN_COOLDOWN_SECONDS = 18000;

		/**
		 * Option holding the last targeted-regeneration timestamp (issue #1220).
		 *
		 * Builder/theme updates requeue only stale variants (bounded), gated
		 * by this cooldown so a burst of updates cannot flood the scheduler.
		 * Per-site option (core get_option is multisite-safe).
		 *
		 * @since 2.2.0
		 * @var string
		 */
		public const TARGETED_REGEN_OPTION = 'wppo_used_css_last_targeted_regen';

		/**
		 * Default targeted-regeneration cooldown in seconds (issue #1220).
		 *
		 * Filterable via wppo_used_css_targeted_cooldown.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const TARGETED_REGEN_COOLDOWN_SECONDS = 3600;

		/**
		 * Allowed used-CSS delivery modes (issue #1220).
		 *
		 * - file:   render-blocking used-CSS link (current behaviour).
		 * - delay:  used-CSS blocking, full stylesheets load on interaction.
		 * - async:  used-CSS via preload + media swap, full in noscript.
		 * - remove: strip full stylesheets; auto-downgrades to delay when the
		 *           builder smoke check fails (never unstyled, never fatal).
		 *
		 * @since 2.2.0
		 * @var string[]
		 */
		public const DELIVERY_MODES = array( 'file', 'delay', 'async', 'remove' );

		/**
		 * Age in seconds after which used-CSS counts as stale for the UI warning (issue #1220).
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const STALE_THRESHOLD_SECONDS = 86400;

		/**
		 * Default per-run cap for the RUM-weighted used-CSS queue (issue #1164).
		 *
		 * Bounds the 200-row cursor loop per run; RUM-worst-first ordering
		 * ensures the slowest pages are queued first. Overridable per site
		 * via `file_optimisation.usedCssQueueCap`.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const DEFAULT_USED_CSS_QUEUE_CAP = 50;

		/**
		 * Hard upper bound for the used-CSS per-run cap read.
		 *
		 * @since 2.2.0
		 * @var int
		 */
		private const MAX_USED_CSS_QUEUE_CAP = 500;

		/**
		 * Viewport-split variant slugs (issue #1164).
		 *
		 * Stored as `used-css.{variant}.css` next to `used-css.css`. Missing
		 * or stale variant files fall back to the single file (or the
		 * deferred full stylesheet) — never fatal.
		 *
		 * @since 2.2.0
		 * @var string[]
		 */
		public const VIEWPORT_VARIANTS = array( 'mobile', 'desktop' );

		/**
		 * Plugin options.
		 *
		 * @var array
		 * @since 1.9.0
		 */
		private $options;

		/**
		 * Safelist selectors always preserved.
		 *
		 * @var array
		 * @since 1.9.0
		 */
		private array $safelist = array();

		/**
		 * Built-in safelist selectors (merged with user safelist).
		 *
		 * @var array
		 * @since 1.9.0
		 */
		private array $built_in_safelist = array(
			'html',
			'body',
			':root',
			'*',
			'#wpcontent',
			'#wpwrap',
			'#wpadminbar',
			'.ab-',
			'.wp-admin-bar-',
			'.woocommerce-',
			'.wc-',
			'.single-product',
			'.cart-',
			'.current-menu-item',
			'.menu-item-',
			'.page-id-',
			'.postid-',
			'.attachmentid-',
			'.active',
			'.open',
			'.hidden',
			'.visible',
			'.show',
			'.hide',
			'.fade',
			'.collapsed',
			'.selected',
			'.current',
			'.focus',
			'.hover',
			'.visited',
			'.js-',
			'.is-',
			'.has-',
			'.wp-',
			'.admin-bar-',
			'.dashicons-',
			'.customize-',
			// Builder / JS-state selectors (issue #966): builders inject
			// dynamic classes at runtime that the static DOM walk never sees.
			// Prefix entries ending in '-' or '*' match via prefix in
			// is_selector_used(); attribute entries (e.g. [data-elementor-type])
			// match by attribute-name substring so compound selectors stay kept.
			'.elementor-',
			'.e-con*',
			'.et_*',
			'.et_pb_*',
			'.et-pb-',
			'.bricks-',
			'.brx-',
			'.vc_*',
			'.wpb_*',
			'.oxygen-',
			'.oxy-',
			'.no-js',
			'.js-enabled',
			'[data-elementor-type]',
			// Popup/modal selectors (issue #1023): popups render outside the
			// static DOM walk (hidden containers, JS portals), so purge keeps
			// Elementor + generic popup selectors by default.
			'.elementor-popup-',
			'.e-popup-',
			'.dialog-',
			'.popup-',
			'.modal-',
			'.mfp-',
			'.swal2-',
			'[data-elementor-type="popup"]',
		);

		/**
		 * Cache root directory.
		 *
		 * @var string
		 * @since 1.9.0
		 */
		private string $cache_root_dir;

		/**
		 * Cache root URL.
		 *
		 * @var string
		 * @since 1.9.0
		 */
		private string $cache_root_url;

		/**
		 * Domain name.
		 *
		 * Pinned to the canonical home host (see {@see Util::get_canonical_host()})
		 * so a forged Host header can never divert used-CSS reads/writes into a
		 * poisoned directory. Falls back to the legacy Host-derived value only
		 * when the canonical host cannot be resolved (early boot, CLI).
		 *
		 * @var string
		 * @since 1.9.0
		 */
		private string $domain;

		/**
		 * Whether the request Host header differs from the canonical home host.
		 *
		 * When true, used-CSS writes are refused (fail-open: the page is served
		 * without used-CSS optimisation) so forged hosts can never poison the
		 * canonical cache files.
		 *
		 * @var bool
		 * @since 2.0.0
		 */
		private bool $host_mismatch = false;

		/**
		 * Whether a traversal probe has been logged this request.
		 *
		 * Rate-limits activity-log writes so a hostile crawler cannot flood
		 * the log table with one entry per request path probe. Mirrors
		 * Cache::$traversal_probe_logged.
		 *
		 * @since 2.0.0
		 * @var bool
		 */
		private static bool $traversal_probe_logged = false;

		/**
		 * Precomputed safelist index (exact/attr/prefix sets).
		 *
		 * Built once per instance from $safelist so is_selector_used()
		 * avoids the ~60-entry loop with preg_replace per selector per rule.
		 *
		 * @since 2.0.0
		 * @var array{exact: array<string, bool>, attrs: string[], prefixes: string[]}|null
		 */
		private ?array $safelist_index = null;

		/**
		 * Per-instance cache of split selector parts keyed by selector string.
		 *
		 * @since 2.0.0
		 * @var array<string, string[]>
		 */
		private array $selector_split_cache = array();

		/**
		 * Memoized local-source checksum for this instance (issue #1038).
		 *
		 * The freshness probe runs full-content local reads; memoizing per
		 * instance keeps repeated cache-hit calls to a single capped pass.
		 *
		 * @since 2.0.0
		 * @var string|null Null when not yet computed.
		 */
		private ?string $source_checksum_memo = null;

		/**
		 * Constructor.
		 *
		 * @param array $options Plugin options.
		 * @since 1.9.0
		 */
		public function __construct( array $options = array() ) {
			$this->options = ! empty( $options ) ? $options : Util::get_settings();

			$raw_host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
			$request_host = Util::normalize_cache_host( $raw_host );
			$canonical    = Util::get_canonical_host();

			if ( '' !== $canonical ) {
				// Pin to the canonical home host by construction; a forged Host
				// header can never create its own used-CSS cache tree. An
				// absent/blank request host (CLI/cron, e.g. process_background()
				// under Action Scheduler) carries no forgery signal and must not
				// block canonical writes; a presented-but-invalid host that
				// normalizes to '' (e.g. 'evil!/..') is still a mismatch.
				$this->domain        = $canonical;
				$raw_trimmed         = trim( (string) $raw_host );
				$this->host_mismatch = ( '' === $request_host ? '' !== $raw_trimmed : $request_host !== $canonical );
			} else {
				// Canonical host unavailable (early boot, CLI): legacy
				// Host-derived behaviour so nothing fatals.
				$this->domain        = $request_host;
				$this->host_mismatch = false;
			}

			$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_ROOT_DIR );
			$this->cache_root_url = WP_CONTENT_URL . self::CACHE_ROOT_DIR;

			$this->init_safelist();
		}

		/**
		 * Whether the request Host header mismatched the canonical home host.
		 *
		 * @return bool True when the request host differs from the canonical host.
		 * @since 2.0.0
		 */
		public function is_host_mismatched(): bool {
			return $this->host_mismatch;
		}

		/**
		 * Safelist presets shipped safe-by-default (Elementor + popup selectors).
		 *
		 * This is the popup/Elementor subset of the built-in safelist below:
		 * prefix entries ending in '-' or '*' match via prefix in
		 * {@see is_selector_used()}; attribute entries match by attribute-name
		 * substring. Filterable via the `wppo_used_css_safelist` filter.
		 * init_safelist() merges these presets together with the built-in list
		 * (deduplicated), so the two sources cannot drift.
		 *
		 * @return string[]
		 * @since 2.0.0
		 */
		public static function get_safelist_presets(): array {
			if ( class_exists( 'PerformanceOptimise\Inc\Css_Safelist' ) ) {
				return Css_Safelist::get_elementor_presets();
			}
			return array(
				'.elementor-',
				'.elementor-popup-',
				'.e-con*',
				'.e-popup-',
				'.dialog-',
				'.popup-',
				'.modal-',
				'.mfp-',
				'.swal2-',
				'[data-elementor-type]',
				'[data-elementor-type="popup"]',
			);
		}

		/**
		 * Initialize safelist from settings and built-in list.
		 *
		 * @return void
		 * @since 1.9.0
		 * @since 2.0.0 Added wppo_used_css_safelist filter (has_filter-guarded).
		 */
		private function init_safelist(): void {
			$file_opts = $this->options['file_optimisation'] ?? array();
			$user_list = array();

			if ( ! empty( $file_opts['excludeUnusedCSS'] ) ) {
				$user_list = Util::process_urls( $file_opts['excludeUnusedCSS'] );
			}

			// Extra safelist (issue #966): additive user textarea merged
			// alongside the legacy excludeUnusedCSS list.
			if ( ! empty( $file_opts['unusedCSSSafelistExtra'] ) ) {
				$extra     = Util::process_urls( $file_opts['unusedCSSSafelistExtra'] );
				$user_list = array_merge( $user_list, $extra );
			}

			$this->safelist = array_merge( self::get_safelist_presets(), $this->built_in_safelist, $user_list );
			$this->safelist = array_unique( array_filter( $this->safelist ) );

			if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_used_css_safelist' ) ) {
				/**
				 * Filter the used-CSS safelist (issue #1023).
				 *
				 * Lets themes/hosts extend or trim the merged built-in + user
				 * safelist without editing settings.
				 *
				 * @since 2.0.0
				 *
				 * @param string[] $safelist Merged safelist selectors.
				 */
				$filtered = apply_filters( 'wppo_used_css_safelist', $this->safelist );
				if ( is_array( $filtered ) ) {
					$this->safelist = array_values( array_unique( array_filter( array_map( 'strval', $filtered ) ) ) );
				}
			}
		}

		/**
		 * Extract used selectors from HTML content.
		 *
		 * Uses the WP 6.9+ HTML processor with full-document parse awareness
		 * when available (issue #883) — correct on malformed markup (SVG,
		 * nested tables, missing closers) where the tag-at-a-time Tag
		 * Processor can mis-attribute tokens. Falls back to the original
		 * Tag Processor walk on WP <6.9 or when the parse fails.
		 *
		 * @param string $html The HTML content.
		 * @return array{tags: array, classes: array, ids: array, attrs: array}
		 * @since 1.9.0
		 * @since 2.0.0 Added WP_HTML_Processor path with Tag Processor fallback.
		 */
		public function extract_selectors( string $html ): array {
			$used = array(
				'tags'    => array(),
				'classes' => array(),
				'ids'     => array(),
				'attrs'   => array(),
			);

			if ( Util::should_use_html_processor() ) {
				$from_processor = self::extract_selectors_with_processor( $html );
				if ( null !== $from_processor ) {
					return $from_processor;
				}
			}

			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				return $used;
			}

			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag() ) {
				self::collect_selectors_from_tag( $tags, $used );
			}

			return $used;
		}

		/**
		 * Extract used selectors via the WP 6.9+ HTML processor.
		 *
		 * Streams tokens through `WP_HTML_Processor` (read-only walk; the
		 * selector data is collected, the buffer is not rewritten). Returns
		 * null to trigger the Tag Processor fallback when the parser cannot
		 * be created or the token stream ended with a parse error.
		 *
		 * @since 2.0.0
		 *
		 * @param string $html The HTML content.
		 * @return array{tags: array, classes: array, ids: array, attrs: array}|null Selector data, or null on failure.
		 */
		private static function extract_selectors_with_processor( string $html ): ?array {
			$processor = Util::create_html_processor( $html );
			if ( null === $processor ) {
				return null;
			}

			$used = array(
				'tags'    => array(),
				'classes' => array(),
				'ids'     => array(),
				'attrs'   => array(),
			);

			while ( $processor->next_token() ) {
				if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
					self::collect_selectors_from_tag( $processor, $used );
				}
			}

			if ( null !== $processor->get_last_error() ) {
				return null;
			}

			return $used;
		}

		/**
		 * Collect used selectors from the current tag of a HTML processor.
		 *
		 * Shared body of {@see extract_selectors()}, operating on whichever
		 * processor is walking the document (WP_HTML_Processor extends
		 * WP_HTML_Tag_Processor).
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags Processor positioned on the current tag.
		 * @param array                  $used Selector accumulator (passed by reference).
		 * @return void
		 */
		private static function collect_selectors_from_tag( \WP_HTML_Tag_Processor $tags, array &$used ): void {
			$tag_name                  = strtolower( (string) $tags->get_tag() );
			$used['tags'][ $tag_name ] = true;

			$class_attr = $tags->get_attribute( 'class' );
			if ( $class_attr ) {
				$classes = preg_split( '/\s+/', trim( $class_attr ) );
				foreach ( $classes as $cls ) {
					$cls = trim( $cls );
					if ( '' !== $cls ) {
						$used['classes'][ $cls ] = true;
					}
				}
			}

			$id_attr = $tags->get_attribute( 'id' );
			if ( $id_attr ) {
				$used['ids'][ trim( $id_attr ) ] = true;
			}

			$common_attrs = array( 'type', 'rel', 'role', 'href', 'src', 'disabled', 'tabindex', 'target', 'title', 'lang', 'dir', 'hidden', 'contenteditable', 'draggable' );
			foreach ( $common_attrs as $attr_name ) {
				$val = $tags->get_attribute( $attr_name );
				if ( null !== $val ) {
					$used['attrs'][ $attr_name ] = true;

					if ( 'href' === $attr_name || 'src' === $attr_name ) {
						$ext = strtolower( pathinfo( (string) wp_parse_url( (string) $val, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
						if ( $ext && in_array( $ext, array( 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'eot' ), true ) ) {
							$used['attrs'][ '.' . $ext ] = true;
						}
					}
				}
			}

			// Track all available attributes for CSS selector matching (WP 6.5+).
			if ( method_exists( $tags, 'get_attribute_names_include_all_private' ) ) {
				foreach ( $tags->get_attribute_names_include_all_private() as $attr_name ) {
					if ( ! in_array( $attr_name, array( 'class', 'id' ), true ) ) {
						$used['attrs'][ $attr_name ] = true;
					}
				}
			}
		}

		/**
		 * Strip CSS comments while preserving quoted segments.
		 *
		 * A character scanner that tracks single/double-quote state (with
		 * backslash escapes), so comment markers inside strings never open
		 * or close a comment. Unterminated comments are dropped (fail-open).
		 *
		 * @since 2.2.0
		 * @param string $css Raw CSS content.
		 * @return string CSS without comments.
		 */
		private static function strip_css_comments( string $css ): string {
			$length = strlen( $css );
			$out    = '';
			$quote  = null;
			$i      = 0;
			while ( $i < $length ) {
				$char = $css[ $i ];
				if ( null !== $quote ) {
					$out .= $char;
					if ( '\\' === $char && $i + 1 < $length ) {
						$out .= $css[ $i + 1 ];
						$i   += 2;
						continue;
					}
					if ( $char === $quote ) {
						$quote = null;
					}
					++$i;
					continue;
				}
				if ( '"' === $char || "'" === $char ) {
					$quote = $char;
					$out  .= $char;
					++$i;
					continue;
				}
				if ( '/' === $char && $i + 1 < $length && '*' === $css[ $i + 1 ] ) {
					$end = strpos( $css, '*/', $i + 2 );
					if ( false === $end ) {
						break;
					}
					$i = $end + 2;
					continue;
				}
				$out .= $char;
				++$i;
			}
			return $out;
		}

		/**
		 * Parse CSS content into structured rules.
		 *
		 * @param string $css Raw CSS content.
		 * @return array Parsed rules.
		 * @since 1.9.0
		 */
		public function parse_css( string $css ): array {
			// Strip CSS comments with a string-aware scanner: comment markers
			// inside quoted segments (e.g. content: "/* not a comment */")
			// are preserved instead of being treated as comment boundaries.
			$css = self::strip_css_comments( $css );

			$rules = array();

			$css = trim( $css );
			if ( '' === $css ) {
				return $rules;
			}

			$offset = 0;
			$length = strlen( $css );

			while ( $offset < $length ) {
				if ( '@' === $css[ $offset ] ) {
					// Quote-aware prelude scan (mirrors the block scanners
					// below): a naive strpos() for ';'/'{' mis-splits when
					// the prelude holds a quoted string containing either
					// character (e.g. @import url("a;b.css")).
					list( $semicolon_pos, $at_rule_end ) = self::find_at_rule_prelude_end( $css, $offset, $length );

					// Handle semicolon-terminated at-rules (@import, @charset, @namespace).
					if ( false !== $semicolon_pos && ( false === $at_rule_end || $semicolon_pos < $at_rule_end ) ) {
						$rules[] = array(
							'type'    => 'at-rule',
							'content' => substr( $css, $offset, $semicolon_pos - $offset + 1 ),
						);
						$offset  = $semicolon_pos + 1;
						continue;
					}

					if ( false === $at_rule_end ) {
						break;
					}

					$at_rule_name = substr( $css, $offset, $at_rule_end - $offset );
					$at_rule_name = trim( $at_rule_name );

					$brace_depth = 1;
					$block_start = $at_rule_end;
					$pos         = $at_rule_end + 1;
					$scan_quote  = null;

					while ( $pos < $length && $brace_depth > 0 ) {
						$scan_char = $css[ $pos ];
						if ( null !== $scan_quote ) {
							// Inside a quoted segment: braces are literal. A
							// backslash escapes the next char (e.g. content: '}').
							if ( '\\' === $scan_char ) {
								$pos += 2;
								continue;
							}
							if ( $scan_char === $scan_quote ) {
								$scan_quote = null;
							}
						} elseif ( '"' === $scan_char || "'" === $scan_char ) {
							$scan_quote = $scan_char;
						} elseif ( '{' === $scan_char ) {
							++$brace_depth;
						} elseif ( '}' === $scan_char ) {
							--$brace_depth;
						}
						++$pos;
					}

					$block_content = substr( $css, $block_start + 1, $pos - $block_start - 2 );

					if ( 0 === strpos( $at_rule_name, '@font-face' ) ) {
						$rules[] = array(
							'type'     => 'font-face',
							'content'  => $at_rule_name . '{' . $block_content . '}',
							'original' => substr( $css, $offset, $pos - $offset ),
						);
					} elseif ( 0 === strpos( $at_rule_name, '@keyframes' ) ) {
						$rules[] = array(
							'type'     => 'keyframes',
							'content'  => $at_rule_name . '{' . $block_content . '}',
							'original' => substr( $css, $offset, $pos - $offset ),
						);
					} elseif ( 0 === strpos( $at_rule_name, '@media' ) ) {
						$children = $this->parse_css_block_rules( $block_content );
						$rules[]  = array(
							'type'     => 'media',
							'at_rule'  => $at_rule_name,
							'children' => $children,
							'original' => substr( $css, $offset, $pos - $offset ),
						);
					} elseif ( 0 === strpos( $at_rule_name, '@supports' ) ) {
						$children = $this->parse_css_block_rules( $block_content );
						$rules[]  = array(
							'type'     => 'supports',
							'at_rule'  => $at_rule_name,
							'children' => $children,
							'original' => substr( $css, $offset, $pos - $offset ),
						);
					} else {
						$rules[] = array(
							'type'     => 'at-rule',
							'content'  => $at_rule_name . '{' . $block_content . '}',
							'original' => substr( $css, $offset, $pos - $offset ),
						);
					}

					$offset = $pos;
				} else {
					// Quote-aware scan (mirrors the at-rule block scanner
					// above): a naive strpos( '}' ) truncates on a brace
					// inside a quoted value (e.g. content:"}"), shifting the
					// offset and cascading into subsequent rules.
					$rule_end = self::find_rule_end( $css, $offset, $length );

					$rule_text = substr( $css, $offset, $rule_end - $offset );
					$rule_text = trim( $rule_text );

					if ( '' !== $rule_text ) {
						$brace_pos = strpos( $rule_text, '{' );
						if ( false !== $brace_pos ) {
							$selector    = trim( substr( $rule_text, 0, $brace_pos ) );
							$declaration = trim( substr( $rule_text, $brace_pos + 1, -1 ) );

							if ( '' !== $selector ) {
								$rules[] = array(
									'type'        => 'rule',
									'selector'    => $selector,
									'selectors'   => $this->split_selectors( $selector ),
									'declaration' => $declaration,
									'original'    => $rule_text,
								);
							}
						}
					}

					$offset = $rule_end;
				}

				while ( $offset < $length && ctype_space( $css[ $offset ] ) ) {
					++$offset;
				}
			}

			return $rules;
		}

		/**
		 * Find the end of an at-rule prelude, skipping quoted segments.
		 *
		 * Returns the first unquoted ';' or '{' offset (whichever comes
		 * first), so a semicolon/brace inside a quoted prelude string (e.g.
		 * `@import url("a;b.css")`) never terminates the prelude early.
		 * Backslash escapes inside quotes are honoured (e.g. "a\";b").
		 *
		 * @since 2.2.0
		 * @param string $css    Full CSS content.
		 * @param int    $offset At-rule start offset (the '@').
		 * @param int    $length Length of $css.
		 * @return array Indices 0 (semicolon offset or false) and 1 (brace offset or false).
		 */
		private static function find_at_rule_prelude_end( string $css, int $offset, int $length ): array {
			$quote     = null;
			$semicolon = false;
			$brace     = false;
			$pos       = $offset;
			while ( $pos < $length ) {
				$char = $css[ $pos ];
				if ( null !== $quote ) {
					if ( '\\' === $char ) {
						$pos += 2;
						continue;
					}
					if ( $char === $quote ) {
						$quote = null;
					}
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( ';' === $char ) {
					$semicolon = $pos;
					break;
				} elseif ( '{' === $char ) {
					$brace = $pos;
					break;
				}
				++$pos;
			}
			return array( $semicolon, $brace );
		}

		/**
		 * Find the end offset (one past '}') of a regular rule, skipping
		 * quoted segments and backslash escapes.
		 *
		 * @since 2.2.0
		 * @param string $css    Full CSS content.
		 * @param int    $offset Rule start offset.
		 * @param int    $length Length of $css.
		 * @return int Offset one past the closing brace (or $length).
		 */
		private static function find_rule_end( string $css, int $offset, int $length ): int {
			$quote = null;
			$pos   = $offset;
			while ( $pos < $length ) {
				$char = $css[ $pos ];
				if ( null !== $quote ) {
					if ( '\\' === $char ) {
						$pos += 2;
						continue;
					}
					if ( $char === $quote ) {
						$quote = null;
					}
				} elseif ( '"' === $char || "'" === $char ) {
					$quote = $char;
				} elseif ( '}' === $char ) {
					return $pos + 1;
				}
				++$pos;
			}
			return $length;
		}

		/**
		 * Parse CSS rules inside a block (e.g., @media) by delegating to parse_css().
		 *
		 * Uses parse_css() recursively to properly handle nested at-rules
		 * like @supports { @media { ... } } and @container.
		 *
		 * @param string $css Block content.
		 * @return array Parsed child rules.
		 * @since 1.9.0
		 */
		private function parse_css_block_rules( string $css ): array {
			return $this->parse_css( $css );
		}

		/**
		 * Split a comma-separated selector list into individual selectors.
		 *
		 * @param string $selector_list Comma-separated selectors.
		 * @return array Individual selectors.
		 * @since 1.9.0
		 */
		private function split_selectors( string $selector_list ): array {
			$selectors = array();
			$current   = '';
			$depth     = 0;
			$len       = strlen( $selector_list );

			for ( $i = 0; $i < $len; ++$i ) {
				$ch = $selector_list[ $i ];
				if ( '(' === $ch || '[' === $ch ) {
					++$depth;
					$current .= $ch;
				} elseif ( ')' === $ch || ']' === $ch ) {
					--$depth;
					$current .= $ch;
				} elseif ( ',' === $ch && 0 === $depth ) {
					$trimmed = trim( $current );
					if ( '' !== $trimmed ) {
						$selectors[] = $trimmed;
					}
					$current = '';
				} else {
					$current .= $ch;
				}
			}

			$trimmed = trim( $current );
			if ( '' !== $trimmed ) {
				$selectors[] = $trimmed;
			}

			return $selectors;
		}

		/**
		 * Build the precomputed safelist index (once per instance).
		 *
		 * Hoists the per-entry preg_replace out of the per-selector loop:
		 * attribute names and non-empty prefixes are derived a single time.
		 *
		 * @since 2.0.0
		 * @return array{exact: array<string, bool>, attrs: string[], prefixes: string[]}
		 */
		private function get_safelist_index(): array {
			if ( null !== $this->safelist_index ) {
				return $this->safelist_index;
			}
			$exact    = array();
			$attrs    = array();
			$prefixes = array();
			foreach ( $this->safelist as $safe ) {
				$safe = (string) $safe;
				if ( '' === $safe ) {
					continue;
				}
				$exact[ $safe ] = true;
				if ( '[' === $safe[0] ) {
					$attr_name = preg_replace( '/[\]=~|^$*"\'].*$/', '', $safe );
					$attr_name = ltrim( trim( (string) $attr_name ), '[' );
					if ( '' !== $attr_name ) {
						$attrs[] = $attr_name;
					}
					continue;
				}
				$last_char = substr( $safe, -1 );
				if ( '-' === $last_char || '_' === $last_char || '*' === $last_char ) {
					$prefix = '*' === $last_char ? substr( $safe, 0, -1 ) : $safe;
					// A bare '*' entry (the universal selector) is handled by
					// the exact-match check; it must not act as a
					// match-everything wildcard through an empty prefix (issue #1038).
					if ( '' !== $prefix ) {
						$prefixes[] = $prefix;
					}
				}
			}
			$this->safelist_index = array(
				'exact'    => $exact,
				'attrs'    => array_values( array_unique( $attrs ) ),
				'prefixes' => array_values( array_unique( $prefixes ) ),
			);
			return $this->safelist_index;
		}

		/**
		 * Cached split of a selector into simple parts.
		 *
		 * @since 2.0.0
		 * @param string $selector Selector string.
		 * @return string[] Simple selector parts.
		 */
		private function get_cached_simple_selectors( string $selector ): array {
			if ( isset( $this->selector_split_cache[ $selector ] ) ) {
				return $this->selector_split_cache[ $selector ];
			}
			$parts = $this->extract_simple_selectors( $selector );
			if ( count( $this->selector_split_cache ) < 2000 ) {
				$this->selector_split_cache[ $selector ] = $parts;
			}
			return $parts;
		}

		/**
		 * Check if a CSS selector matches any used element in the HTML.
		 *
		 * Note: This method uses a conservative approach for descendant/child
		 * combinators. For selectors like `.sidebar .widget`, it returns true
		 * if EITHER `.sidebar` OR `.widget` exists anywhere in the HTML. This
		 * avoids false positives (broken styles) at the cost of being less
		 * aggressive than PurgeCSS. The claimed 30-80% reduction is based on
		 * this conservative strategy.
		 *
		 * @param string $selector A single CSS selector.
		 * @param array  $used Used selectors from extract_selectors().
		 * @return bool True if the selector is used.
		 * @since 1.9.0
		 */
		public function is_selector_used( string $selector, array $used ): bool {
			$selector = trim( $selector );

			if ( '' === $selector ) {
				return false;
			}

			$index = $this->get_safelist_index();

			if ( isset( $index['exact'][ $selector ] ) ) {
				return true;
			}

			foreach ( $index['attrs'] as $attr_name ) {
				// Attribute safelist (e.g. [data-elementor-type]): match by
				// attribute-name substring so compound selectors like
				// div[data-elementor-type] or [data-elementor-type="x"] stay
				// kept. Bare [data-*]/[aria-*] selectors are additionally
				// conserved by matches_simple_selector().
				if ( false !== stripos( $selector, $attr_name ) ) {
					return true;
				}
			}
			if ( ! empty( $index['prefixes'] ) ) {
				$parts = null;
				foreach ( $index['prefixes'] as $prefix ) {
					if ( 0 === strpos( $selector, $prefix ) ) {
						return true;
					}
					// Compound/descendant selectors (issue #1023): a popup
					// token buried inside a wrapper, portal, or tag-qualified
					// part (e.g. '.foo .popup-bar', 'div.modal-dialog',
					// 'div.elementor-popup-modal', 'button.mfp-close') must
					// keep the rule even though the full string does not
					// start with the prefix. Fail-safe direction: keeping
					// extra CSS can never break styling.
					if ( null === $parts ) {
						$parts = $this->get_cached_simple_selectors( $selector );
					}
					foreach ( $parts as $part ) {
						if ( 0 === strpos( $part, $prefix ) || false !== stripos( $part, $prefix ) ) {
							return true;
						}
					}
				}
			}

			$simple_selectors = $this->get_cached_simple_selectors( $selector );

			// Conservative OR logic: keep the rule if ANY simple selector part
			// exists in the DOM, to avoid breaking descendant selectors like
			// `.sidebar .widget` when only one side is present. This matches
			// the documented behaviour and avoids false-positive purging.
			// @since 2.0.0 Fixed from AND to OR to match docs.
			foreach ( $simple_selectors as $simple ) {
				if ( $this->matches_simple_selector( $simple, $used ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Extract simple selector parts from a compound selector.
		 *
		 * Splits on combinators (whitespace, >, +, ~) and returns each simple selector.
		 *
		 * @param string $selector A CSS selector.
		 * @return array Simple selector parts.
		 * @since 1.9.0
		 */
		private function extract_simple_selectors( string $selector ): array {
			// Split on combinators (whitespace, >, +, ~) but avoid splitting
			// inside pseudo-class arguments like :not(.foo .bar) or :is(.a > .b).
			$parts   = array();
			$current = '';
			$depth   = 0;
			$len     = strlen( $selector );

			for ( $i = 0; $i < $len; ++$i ) {
				$ch = $selector[ $i ];
				if ( '(' === $ch || '[' === $ch ) {
					++$depth;
					$current .= $ch;
				} elseif ( ')' === $ch || ']' === $ch ) {
					--$depth;
					$current .= $ch;
				} elseif ( 0 === $depth && preg_match( '/^[\s>+~]$/', $ch ) ) {
					$trimmed = trim( $current );
					if ( '' !== $trimmed ) {
						$sub_parts = preg_split( '/(?=[.#\[])/', $trimmed );
						foreach ( $sub_parts as $sub ) {
							$sub = trim( $sub );
							if ( '' !== $sub ) {
								$parts[] = $sub;
							}
						}
					}
					$current = '';
				} else {
					$current .= $ch;
				}
			}

			$trimmed = trim( $current );
			if ( '' !== $trimmed ) {
				$sub_parts = preg_split( '/(?=[.#\[])/', $trimmed );
				foreach ( $sub_parts as $sub ) {
					$sub = trim( $sub );
					if ( '' !== $sub ) {
						$parts[] = $sub;
					}
				}
			}

			return $parts;
		}

		/**
		 * Check if a simple selector matches used elements.
		 *
		 * @param string $simple A simple CSS selector (e.g., ".class", "#id", "tag").
		 * @param array  $used Used selectors.
		 * @return bool True if matched.
		 * @since 1.9.0
		 */
		private function matches_simple_selector( string $simple, array $used ): bool {
			if ( '' === $simple ) {
				return false;
			}

			if ( '*' === $simple ) {
				return true;
			}

			if ( ':root' === $simple || ':host' === $simple ) {
				return true;
			}

			if ( false !== strpos( $simple, ':' ) ) {
				// Strip pseudo-classes/elements using a depth-tracking parser
				// that handles nested parentheses like :not(.a:not(.b)).
				$stripped = '';
				$len      = strlen( $simple );
				for ( $i = 0; $i < $len; ++$i ) {
					$ch = $simple[ $i ];
					if ( ':' === $ch ) {
						// Skip everything until the next non-nested separator.
						$paren_depth = 0;
						++$i;
						while ( $i < $len ) {
							$c = $simple[ $i ];
							if ( '(' === $c ) {
								++$paren_depth;
							} elseif ( ')' === $c ) {
								--$paren_depth;
								if ( $paren_depth < 0 ) {
									break;
								}
							} elseif ( 0 === $paren_depth && ( ':' === $c || '.' === $c || '#' === $c || '[' === $c ) ) {
								--$i;
								break;
							}
							++$i;
						}
					} else {
						$stripped .= $ch;
					}
				}
				$simple = trim( $stripped );
			}

			if ( '' === $simple ) {
				return true;
			}

			if ( '#' === $simple[0] ) {
				$id = substr( $simple, 1 );
				return isset( $used['ids'][ $id ] );
			}

			if ( '.' === $simple[0] ) {
				$class = substr( $simple, 1 );
				return isset( $used['classes'][ $class ] );
			}

			if ( '[' === $simple[0] ) {
				// Conservatively allow all data-* attribute selectors — commonly used in Gutenberg blocks.
				if ( 0 === strpos( $simple, '[data-' ) || 0 === strpos( $simple, '[aria-' ) ) {
					return true;
				}
				$attr_end = strpos( $simple, ']' );
				if ( false !== $attr_end ) {
					$attr_content = substr( $simple, 1, $attr_end - 1 );
					$attr_name    = preg_replace( '/[=~|^$*].*/', '', $attr_content );
					$attr_name    = trim( $attr_name );
					return isset( $used['attrs'][ $attr_name ] );
				}
				return true;
			}

			$tag = preg_replace( '/[.#:\[].*$/S', '', $simple );
			$tag = trim( $tag );

			if ( '' !== $tag && isset( $used['tags'][ $tag ] ) ) {
				return true;
			}

			return false;
		}

		/**
		 * Purge unused CSS rules from parsed CSS.
		 *
		 * @param array $parsed_css Parsed CSS from parse_css().
		 * @param array $used Used selectors from extract_selectors().
		 * @return string Purged CSS content.
		 * @since 1.9.0
		 */
		public function purge_css( array $parsed_css, array $used ): string {
			$output = '';

			foreach ( $parsed_css as $rule ) {
				if ( 'font-face' === $rule['type'] || 'keyframes' === $rule['type'] ) {
					$output .= $rule['content'] . "\n";
					continue;
				}

				if ( 'at-rule' === $rule['type'] ) {
					$output .= $rule['content'] . "\n";
					continue;
				}

				if ( 'media' === $rule['type'] || 'supports' === $rule['type'] ) {
					$purged_children = $this->purge_css( $rule['children'], $used );
					if ( '' !== $purged_children ) {
						$output .= $rule['at_rule'] . '{' . "\n";
						$output .= $purged_children . "\n";
						$output .= '}' . "\n";
					}
					continue;
				}

				if ( 'rule' === $rule['type'] ) {
					if ( $this->is_rule_used( $rule, $used ) ) {
						$output .= $rule['original'] . "\n";
					}
					continue;
				}
			}

			return trim( $output );
		}

		/**
		 * Check if a single CSS rule is used.
		 *
		 * @param array $rule A parsed rule.
		 * @param array $used Used selectors.
		 * @return bool True if the rule is used.
		 * @since 1.9.0
		 */
		private function is_rule_used( array $rule, array $used ): bool {
			if ( empty( $rule['selectors'] ) ) {
				return false;
			}

			foreach ( $rule['selectors'] as $selector ) {
				if ( $this->is_selector_used( $selector, $used ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Generate used CSS for a given HTML content and CSS assets.
		 *
		 * Degraded path: when the WP_HTML_Tag_Processor class is unavailable
		 * (WordPress < 6.2) the used-selector set cannot be extracted, so
		 * purging is skipped entirely and the unprocessed (combined) CSS is
		 * returned instead of over-purging every rule and breaking styles
		 * (audit #888 finding 3).
		 *
		 * @param string $html       The page HTML.
		 * @param array  $css_assets Array of CSS content strings (keyed by handle).
		 * @return string Purged CSS content, or the unprocessed combined CSS when purging is unavailable.
		 * @since 1.9.0
		 */
		public function generate_used_css( string $html, array $css_assets ): string {
			if ( empty( $html ) || empty( $css_assets ) ) {
				return '';
			}

			$combined_css = '';
			foreach ( $css_assets as $css_content ) {
				$combined_css .= $css_content . "\n";
			}

			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				// This runs per page view (and per post in bulk regen) — log
				// the degraded mode at most once per request (Part 2 review).
				static $logged_skip = false;
				if ( ! $logged_skip ) {
					$logged_skip = true;
					Log::add(
						__( 'Used-CSS purging skipped: WP_HTML_Tag_Processor is unavailable (requires WordPress 6.2+). Serving unprocessed CSS instead.', 'performance-optimisation' )
					);
				}
				return $combined_css;
			}

			$used_selectors = $this->extract_selectors( $html );

			$parsed = $this->parse_css( $combined_css );

			$purged = $this->purge_css( $parsed, $used_selectors );

			// Visual regression guard (issue #966): an over-aggressive purge
			// (retained-bytes ratio below threshold, or tiny output from a
			// large input) fails back to the full stylesheet, never fatal.
			if ( $this->is_regression_guard_tripped( $combined_css, $purged ) ) {
				$this->log_used_css_fallback( 'regression_guard', array_keys( $css_assets ) );
				return $combined_css;
			}

			return $purged;
		}

		/**
		 * Get the used-CSS cache file path for a URL.
		 *
		 * Dual-prefix containment-checked: returns an empty string (and logs
		 * a traversal probe) when the resolved path would escape the cache
		 * root or the per-domain directory, so callers fail open to
		 * unoptimized output instead of writing outside the cache tree.
		 *
		 * @param string $url The page URL.
		 * @return string The filesystem path, or '' when refused.
		 * @since 1.9.0
		 */
		public function get_used_css_path( string $url = '' ): string {
			if ( '' === $this->cache_root_dir || '' === $this->domain ) {
				return '';
			}
			// Default-'' means the current page: resolve through REQUEST_URI
			// first so per-page sidecars never collide on the homepage file.
			// A hostile REQUEST_URI that sanitizes to '' is a probe, not the
			// homepage — refuse before sanitize_cache_path() maps '' to the
			// homepage file.
			$effective = ( '' === $url ) ? $this->get_url_path( $url ) : $url;
			if ( '' === $url && '' === $effective && $this->is_raw_path_non_blank( $url ) ) {
				$this->log_traversal_probe( $url );
				return '';
			}
			// Single auditable containment point: host normalization, path
			// sanitization, filename allowlist, and dual-prefix containment
			// all live in Util::sanitize_cache_path().
			$candidate = Util::sanitize_cache_path( $this->cache_root_dir, $this->domain, $effective, self::USED_CSS_FILENAME );
			if ( '' === $candidate ) {
				// Distinguish the benign homepage ('/', '') from a rejected
				// hostile input: never map a probe to the homepage file —
				// refuse and log so callers fail open to unoptimized output.
				if ( '' === $effective && ! $this->is_raw_path_non_blank( $url ) ) {
					return '';
				}
				$this->log_traversal_probe( $url );
				return '';
			}
			return $candidate;
		}

		/**
		 * Get the used-CSS cache file URL for a URL.
		 *
		 * Returns an empty string for hostile inputs (never maps a probe to
		 * the homepage file) so callers fail open to original stylesheets.
		 *
		 * @param string $url The page URL.
		 * @return string The public URL, or '' when refused.
		 * @since 1.9.0
		 */
		public function get_used_css_url( string $url = '' ): string {
			if ( '' === $this->cache_root_dir || '' === $this->cache_root_url || '' === $this->domain ) {
				return '';
			}
			// Same effective-path resolution as get_used_css_path(): the
			// containment candidate and the emitted URL derive from the same
			// path so default-'' calls agree instead of checking the homepage
			// while emitting the REQUEST_URI path.
			$effective = ( '' === $url ) ? $this->get_url_path( $url ) : $url;
			if ( '' === $url && '' === $effective && $this->is_raw_path_non_blank( $url ) ) {
				$this->log_traversal_probe( $url );
				return '';
			}
			// Containment parity with get_used_css_path(): resolve the
			// filesystem candidate through the same central helper so the
			// URL and path surfaces refuse the same hostile inputs.
			$candidate = Util::sanitize_cache_path( $this->cache_root_dir, $this->domain, $effective, self::USED_CSS_FILENAME );
			if ( '' === $candidate ) {
				if ( '' === $effective && ! $this->is_raw_path_non_blank( $url ) ) {
					return '';
				}
				$this->log_traversal_probe( $url );
				return '';
			}
			$path        = ( '' === $url ) ? $effective : $this->get_url_path( $url );
			$path_suffix = '' !== $path ? "/{$path}" : '';
			return "{$this->cache_root_url}/{$this->domain}{$path_suffix}/" . self::USED_CSS_FILENAME;
		}

		/**
		 * Get the normalized URL path for cache storage.
		 *
		 * Delegates to the shared {@see Util::sanitize_cache_url_path()}
		 * helper (single controlled decode pass, null-byte + dotdot +
		 * drive/UNC rejection, PHP_URL_PATH extraction) so the used-CSS
		 * surface normalizes identically to the static HTML cache.
		 *
		 * @param string $url The page URL.
		 * @return string Normalized path, or '' when refused/empty.
		 * @since 1.9.0
		 */
		private function get_url_path( string $url = '' ): string {
			if ( '' === $url ) {
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$raw = wp_parse_url( $request_uri, PHP_URL_PATH );
				} else {
					$raw = parse_url( $request_uri, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( null === $raw || false === $raw ) {
					$raw = $request_uri;
				}
				// Path-only input: no host context (same/foreign-host logic
				// could never run on a bare path). Full URLs keep the domain
				// below.
				return Util::sanitize_cache_url_path( (string) $raw );
			}

			return Util::sanitize_cache_url_path( $url, '' !== $this->domain ? $this->domain : null );
		}

		/**
		 * Whether the raw path component behind a sanitized-'' result is non-blank.
		 *
		 * Distinguishes the benign homepage (`/`, `''`) from a rejected
		 * hostile input (dot-dot, encoded sequences, null bytes, drive/UNC
		 * prefixes): only the latter counts as a traversal probe. Mirrors
		 * the homepage distinction in Cache::get_file_path().
		 *
		 * @since 2.0.0
		 * @param string $url The original URL ('' = current REQUEST_URI).
		 * @return bool True when the raw path component is non-blank.
		 */
		private function is_raw_path_non_blank( string $url ): bool {
			$raw = $url;
			if ( '' === $raw ) {
				$raw = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
			}
			if ( function_exists( 'wp_parse_url' ) ) {
				$component = wp_parse_url( $raw, PHP_URL_PATH );
			} else {
				$component = parse_url( $raw, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
			}
			if ( null === $component || false === $component ) {
				$component = $raw;
			}
			return '' !== trim( trim( (string) $component ), '/' );
		}

		/**
		 * Whether an absolute path stays inside the used-CSS cache tree.
		 *
		 * Dual-prefix containment: the normalized path must start with both
		 * the cache root and the per-domain directory (trailing-slash aware
		 * so `wppo-evil` never prefix-matches `wppo`). Empty root or domain
		 * fails closed. Mirrors Cache::is_path_contained().
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Added realpath symlink containment via Util::validate_cache_write_path().
		 * @param string $path Absolute file or directory path.
		 * @return bool True when contained.
		 */
		private function is_path_contained( string $path ): bool {
			// Single validator (mirrors Cache::is_path_contained()):
			// Util::validate_cache_write_path() owns the lexical + realpath
			// AND so the used-CSS writer gets the same symlink containment
			// as the static-HTML write path. Fail closed on any throwable.
			try {
				return Util::validate_cache_write_path( $this->cache_root_dir, $this->domain, $path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Log a blocked used-CSS path traversal probe (once per request).
		 *
		 * Never throws: failures degrade silently to serving unoptimized
		 * output. Mirrors Cache::log_traversal_probe().
		 *
		 * @since 2.0.0
		 * @param string $raw_input The hostile input that was rejected.
		 * @return void
		 */
		private function log_traversal_probe( string $raw_input ): void {
			if ( self::$traversal_probe_logged ) {
				return;
			}
			self::$traversal_probe_logged = true;

			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
					return;
				}
				// Privacy: never log query strings or fragments (tokens, PII).
				$path_only = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $raw_input, PHP_URL_PATH ) : parse_url( (string) $raw_input, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url unavailable.
				if ( is_string( $path_only ) ) {
					$log_input = $path_only;
				} else {
					$parts     = preg_split( '/[?#]/', (string) $raw_input, 2 );
					$log_input = is_array( $parts ) ? (string) $parts[0] : (string) $raw_input;
				}
				$snippet = str_replace( "\0", '', $log_input );
				if ( function_exists( 'sanitize_text_field' ) ) {
					$snippet = sanitize_text_field( $snippet );
				}
				$snippet = function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 200, 'UTF-8' ) : substr( $snippet, 0, 200 );
				$message = function_exists( '__' ) ? __( 'Blocked used-CSS path traversal probe.', 'performance-optimisation' ) : 'Blocked used-CSS path traversal probe.';
				if ( '' !== $snippet ) {
					$message .= ' ' . $snippet;
				}
				Log::add( $message );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Save used-CSS content for a URL.
		 *
		 * @param string $css The purged CSS content.
		 * @param string $url The page URL.
		 * @return bool True on success.
		 * @since 1.9.0
		 */
		public function save_used_css( string $css, string $url = '' ): bool {
			if ( empty( $css ) ) {
				return false;
			}

			// Host-header poisoning guard: never persist derived CSS when the
			// request host mismatched the canonical host (fail-open: the page
			// is served without used-CSS optimisation instead).
			if ( $this->host_mismatch ) {
				return false;
			}

			// Refuse writes when no domain resolved (canonical unavailable and no
			// request host, e.g. early boot/CLI): writing under a host-less dir
			// (cache/wppo//<path>/) would collide across multisite blogs instead
			// of namespacing per site. Mirrors Cache::maybe_store_cache().
			//
			// @since 2.0.0 Empty-domain refusal.
			if ( '' === $this->domain ) {
				return false;
			}

			// A forged host embedded in the explicit $url (e.g. via a filtered
			// permalink) is not covered by the ambient Host check above.
			// Host refusal lives in get_used_css_path() → sanitize_cache_path()
			// (same-host absolute URLs map, foreign/hostless absolute-form
			// refuses), so no separate parse + normalize happens here — that
			// would duplicate the exact same work on the write path.
			$file_path = $this->get_used_css_path( $url );
			if ( '' === $file_path ) {
				// Traversal probe or empty domain/root: refuse the write and
				// serve the unoptimized buffer (fail-open, never fatal).
				// get_used_css_path() already logged the probe.
				return false;
			}
			$dir_path = dirname( $file_path );

			// Dual-prefix containment on both the file and its directory
			// before any mkdir/write, so a crafted path can never escape
			// the cache root or clobber sensitive files.
			if ( ! $this->is_path_contained( $file_path ) || ! $this->is_path_contained( trailingslashit( $dir_path ) ) ) {
				$this->log_traversal_probe( $url );
				return false;
			}

			$fs = Util::init_filesystem();
			if ( ! $fs ) {
				return false;
			}

			if ( ! Util::prepare_cache_dir( $dir_path ) ) {
				return false;
			}

			// Post-mkdir re-verification (TOCTOU): a symlink swapped in
			// during the iterative mkdir must not redirect the write, so
			// containment is re-checked after the directory exists.
			if ( ! $this->is_path_contained( $file_path ) || ! $this->is_path_contained( trailingslashit( $dir_path ) ) ) {
				$this->log_traversal_probe( $url );
				return false;
			}

			// Atomic write via the shared tmp+rename helper (unique tmp
			// name, no non-atomic fallback) so interrupted writes never
			// leave partial CSS behind.
			return Util::atomic_file_put_contents( $fs, $file_path, $css );
		}

		/**
		 * Stable content hash of CSS source (issue #1038).
		 *
		 * Thin backward-compatible wrapper around the shared
		 * {@see Util::compute_css_checksum()} (audit #7) so both CSS
		 * pipelines share one implementation.
		 *
		 * @param string $css CSS content.
		 * @return string SHA-256 checksum, or '' for empty input.
		 * @since 2.0.0
		 */
		public function compute_css_checksum( string $css ): string {
			return Util::compute_css_checksum( $css );
		}

		/**
		 * Sidecar path holding the source checksum for a used-CSS file.
		 *
		 * Lives next to the domain-based used-CSS file, so it inherits the
		 * same multisite-safe namespacing (per-site settings, domain-based
		 * cache paths, blog-aware keys).
		 *
		 * @param string $used_css_path Used-CSS file path.
		 * @return string Checksum sidecar path, or '' when refused.
		 * @since 2.0.0
		 */
		private function get_checksum_path( string $used_css_path ): string {
			if ( '' === $used_css_path ) {
				return '';
			}
			$candidate = $used_css_path . '.sha256';
			if ( ! $this->is_path_contained( $candidate ) ) {
				return '';
			}
			return $candidate;
		}

		/**
		 * Combined checksum of the locally-available queued stylesheets.
		 *
		 * Reads local files only via `Util::get_local_path()` — never
		 * fetches remotely. Bounded and order-stable: handles are sorted
		 * (queue reorder alone never triggers regen), hashed incrementally
		 * (no unbounded concatenation), capped at 20 files / 512 KB per
		 * file / 2 MB total — exceeding a cap yields '' (no checksum signal,
		 * the mtime verdict stands). The verdict is memoized per instance so
		 * repeated cache-hit calls cost one pass. Fail-open: any unreadable
		 * input yields ''.
		 *
		 * @return string Combined checksum, or '' when unavailable.
		 * @since 2.0.0
		 */
		public function compute_local_source_checksum(): string {
			if ( null !== $this->source_checksum_memo ) {
				return $this->source_checksum_memo;
			}
			global $wp_styles;
			if ( ! $wp_styles || empty( $wp_styles->queue ) ) {
				$this->source_checksum_memo = '';
				return '';
			}
			try {
				$handles = array_values( array_unique( array_map( 'strval', (array) $wp_styles->queue ) ) );
				sort( $handles );
				$ctx   = hash_init( 'sha256' );
				$count = 0;
				$total = 0;
				foreach ( $handles as $handle ) {
					if ( $count >= 20 || $total >= 2097152 ) {
						break;
					}
					if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
						continue;
					}
					$src = $wp_styles->registered[ $handle ]->src;
					if ( empty( $src ) ) {
						continue;
					}
					$local_path = Util::get_local_path( (string) $src );
					if ( '' === $local_path || ! file_exists( $local_path ) ) {
						continue;
					}
					$size = @filesize( $local_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- filesize() warns on races; false is already guarded below.
					if ( false === $size || $size <= 0 || $size > 524288 ) {
						continue;
					}
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache-freshness read only, never remote.
					$content = file_get_contents( $local_path );
					if ( ! is_string( $content ) || '' === $content ) {
						continue;
					}
					hash_update( $ctx, substr( $content, 0, 524288 ) . "\n" );
					$total += min( strlen( $content ), 524288 ) + 1;
					++$count;
				}
				if ( 0 === $count ) {
					$this->source_checksum_memo = '';
					return '';
				}
				$this->source_checksum_memo = hash_final( $ctx );
				return $this->source_checksum_memo;
			} catch ( \Throwable $e ) {
				unset( $e );
				$this->source_checksum_memo = '';
				return '';
			}
		}

		/**
		 * Reset the memoized local-source checksum.
		 *
		 * Production flow computes the checksum once per request after the
		 * source is stable, so it never needs to clear the memo itself. This
		 * exists for tests (which mutate fixture files mid-test) and for any
		 * long-running process that rewrites stylesheets in-request.
		 *
		 * @return void
		 * @since 2.0.0
		 */
		public function reset_source_checksum_memo(): void {
			$this->source_checksum_memo = null;
		}

		/**
		 * Whether the cached used-CSS is stale by content checksum.
		 *
		 * Compares the locally-computed source checksum against the sidecar
		 * stored at generation time. Fail-open: missing sidecar, missing
		 * checksum signal, or any read error reports fresh (the mtime
		 * verdict stands) — never fatal, pristine buffer preserved.
		 *
		 * @param string $used_css_path Used-CSS file path.
		 * @return bool True when the source changed since generation.
		 * @since 2.0.0
		 */
		private function is_checksum_stale( string $used_css_path ): bool {
			try {
				$checksum_path = $this->get_checksum_path( $used_css_path );
				if ( '' === $checksum_path || ! file_exists( $checksum_path ) ) {
					return false;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache-freshness read only, never remote.
				$stored = file_get_contents( $checksum_path );
				if ( ! is_string( $stored ) || '' === trim( $stored ) ) {
					return false;
				}
				$current = $this->compute_local_source_checksum();
				if ( '' === $current ) {
					return false;
				}
				return ! hash_equals( trim( $stored ), $current );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Persist the source checksum sidecar after a successful generation.
		 *
		 * Local file write only — no remote fetch. Fail-open: any failure
		 * is silent; the next request simply falls back to mtime freshness.
		 *
		 * @param string $used_css_path Used-CSS file path.
		 * @return void
		 * @since 2.0.0
		 */
		private function persist_source_checksum( string $used_css_path ): void {
			try {
				$checksum_path = $this->get_checksum_path( $used_css_path );
				if ( '' === $checksum_path ) {
					return;
				}
				$checksum = $this->compute_local_source_checksum();
				if ( '' === $checksum ) {
					return;
				}
				$fs = Util::init_filesystem();
				if ( $fs ) {
					$fs->put_contents( $checksum_path, $checksum, FS_CHMOD_FILE );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local checksum sidecar fallback.
					file_put_contents( $checksum_path, $checksum );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Map a used-CSS path to its sibling last-good fallback path.
		 *
		 * Thin wrapper over {@see Util::get_purge_fallback_path_for()} so the
		 * used-CSS purge/miss path can be unit-tested via this surface.
		 * `used-css.css` maps to `fallback.css` in the same directory (and
		 * `used-css.{mobile,desktop}.css` to their own variant fallbacks);
		 * paths that already point at a fallback file map to `''` (loop guard).
		 *
		 * @param string $file_path Absolute used-CSS file path.
		 * @return string Sibling fallback path, or '' when not applicable.
		 *
		 * @since 2.2.0
		 */
		public static function get_purge_fallback_path( string $file_path ): string {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return '';
				}
				return Util::get_purge_fallback_path_for( $file_path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Retain a last-good fallback copy before a used-CSS file is purged.
		 *
		 * Thin wrapper over {@see Util::retain_purge_fallback_file()} (the
		 * single shared implementation — see also
		 * `Cache::retain_purge_fallback()`). Viewport variants
		 * (`used-css.mobile.css` / `used-css.desktop.css`) map to their own
		 * `fallback.mobile.css` / `fallback.desktop.css` so variant misses
		 * never cross-serve. No-op when disabled, on containment failure,
		 * or when the file is missing/empty. Never throws.
		 *
		 * Serving is owned by `Cache::get_purge_fallback_response()` /
		 * `Cache::maybe_serve_purge_fallback()` (used-CSS files live under
		 * the same cache tree, so the Cache resolver covers them); this
		 * class only retains.
		 *
		 * @param string $file_path The used-CSS file about to be deleted.
		 * @return void
		 *
		 * @since 2.2.0
		 */
		private function retain_purge_fallback( string $file_path ): void {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
					return;
				}
				$fs = Util::init_filesystem();
				if ( ! $fs ) {
					return;
				}
				Util::retain_purge_fallback_file(
					$fs,
					function ( string $path ): bool {
						return $this->is_path_contained( $path );
					},
					$file_path
				);
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		// NOTE (issue #1275): miss serving is owned by
		// Cache::get_purge_fallback_response() /
		// Cache::maybe_serve_purge_fallback() — used-CSS files live under
		// the same cache tree, so no second resolver exists here by design
		// (a divergent copy was removed; this class only retains).

		/**
		 * Delete used-CSS file(s) for a URL or all URLs.
		 *
		 * @param string|null $url Optional URL to delete specific page used-CSS.
		 * @return bool True on success.
		 * @since 1.9.0
		 */
		public function delete_used_css( $url = null ): bool {
			if ( null !== $url ) {
				$file_path = $this->get_used_css_path( (string) $url );
				// Refuse deletes for hostile paths (fail-open: nothing
				// deleted, probe already logged by get_used_css_path()).
				// Only log here for non-hostile empty resolutions (empty
				// domain/root): hostile paths were already logged, and
				// blank raw paths are not attack probes.
				if ( '' === $file_path || ! $this->is_path_contained( $file_path ) ) {
					$raw_path = function_exists( 'wp_parse_url' ) ? wp_parse_url( (string) $url, PHP_URL_PATH ) : parse_url( (string) $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url unavailable.
					if ( ! is_string( $raw_path ) ) {
						$split    = preg_split( '/[?#]/', (string) $url, 2 );
						$raw_path = is_array( $split ) ? (string) $split[0] : (string) $url;
					}
					if ( '' !== trim( (string) $raw_path ) ) {
						$this->log_traversal_probe( (string) $url );
					}
					return false;
				}
				$fs = Util::init_filesystem();
				if ( ! $fs ) {
					return false;
				}
				// Post-purge fallback (issue #1275): retain the last-good
				// copy before deleting so a first hit pre-regen stays styled
				// (302). No-op when the gate is off. Bulk deletes
				// (delete_all_used_css) only remove used-css* names, so
				// retained fallback.css siblings survive them untouched.
				$this->retain_purge_fallback( $file_path );
				// Drop the checksum sidecar alongside the variant so a
				// re-generation re-baselines instead of comparing against a
				// checksum for deleted output (issue #1038).
				$checksum_path = $this->get_checksum_path( $file_path );
				if ( '' !== $checksum_path && $fs->exists( $checksum_path ) ) {
					$fs->delete( $checksum_path );
				}
				// Viewport variants (issue #1220): the single-file delete must
				// also invalidate used-css.{mobile,desktop}.css (+ sidecars) or
				// resolve_used_css_path() may keep serving a stale variant.
				$this->delete_variant_files_for_url( (string) $url );
				if ( $fs->exists( $file_path ) ) {
					return $fs->delete( $file_path );
				}
				return true;
			}

			return self::delete_all_used_css();
		}

		/**
		 * Delete the viewport-variant sidecars for a URL (issue #1220).
		 *
		 * Removes `used-css.{mobile,desktop}.css` (+ `.sha256` checksums)
		 * beside the single file so a path-scoped purge cannot leave a stale
		 * variant behind for resolve_used_css_path() to serve. Fail-open:
		 * never throws, missing files are skipped.
		 *
		 * @param string $url Page URL.
		 * @return void
		 * @since 2.2.0
		 */
		private function delete_variant_files_for_url( string $url ): void {
			try {
				$fs = Util::init_filesystem();
				if ( ! $fs ) {
					return;
				}
				foreach ( self::VIEWPORT_VARIANTS as $variant ) {
					try {
						$variant_path = $this->get_used_css_variant_path( $url, $variant );
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
					if ( '' === $variant_path || ! $this->is_path_contained( $variant_path ) ) {
						continue;
					}
					// Post-purge fallback (issue #1275): retain each
					// viewport variant before deleting so variant-URL
					// misses have a retained copy like the single file.
					$this->retain_purge_fallback( $variant_path );
					try {
						$checksum_path = $this->get_checksum_path( $variant_path );
					} catch ( \Throwable $e ) {
						unset( $e );
						$checksum_path = '';
					}
					if ( '' !== $checksum_path && $fs->exists( $checksum_path ) ) {
						$fs->delete( $checksum_path );
					}
					if ( $fs->exists( $variant_path ) ) {
						$fs->delete( $variant_path );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Delete all used-CSS files across all domains.
		 *
		 * @return bool True on success.
		 * @since 1.9.0
		 */
		public static function delete_all_used_css(): bool {
			$fs = Util::init_filesystem();
			if ( ! $fs ) {
				return false;
			}

			$root = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_ROOT_DIR );

			if ( ! $fs->is_dir( $root ) ) {
				return true;
			}

			$success = true;
			// Head-index queue (O(1) dequeue) with a visited-dir budget so
			// a huge tree cannot cost O(n) memmoves per dequeue nor walk
			// unboundedly — mirrors the bounded snapshot queue in Cache.
			$dir_queue   = array( array( $root, 0 ) );
			$head        = 0;
			$visited     = 0;
			$max_dirs    = 2000;
			$max_depth   = 20;
			$root_slash  = rtrim( $root, '/' ) . '/';
			$queue_total = count( $dir_queue );
			while ( $head < $queue_total && $visited < $max_dirs ) {
				$current = $dir_queue[ $head ];
				++$head;
				++$visited;
				$dir   = (string) $current[0];
				$depth = (int) $current[1];
				if ( $depth > $max_depth ) {
					continue;
				}
				$entries = $fs->dirlist( $dir );
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $name => $entry ) {
					$full_path = trailingslashit( $dir ) . $name;
					if ( ! empty( $entry['type'] ) && 'd' === $entry['type'] ) {
						$dir_queue[] = array( $full_path, $depth + 1 );
						$queue_total = count( $dir_queue );
					} else {
						$purge_names = array( self::USED_CSS_FILENAME, self::USED_CSS_FILENAME . '.sha256' );
						foreach ( self::VIEWPORT_VARIANTS as $variant ) {
							$purge_names[] = 'used-css.' . $variant . '.css';
							$purge_names[] = 'used-css.' . $variant . '.css.sha256';
						}
						if ( in_array( $name, $purge_names, true ) ) {
							// Post-purge fallback (issue #1275): retain each
							// live used-css base before deleting so a full
							// wipe keeps last-good instead of only the
							// previous fallback generation. No-op when off.
							if ( '.sha256' !== substr( (string) $name, -7 ) && class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'retain_purge_fallback_file' ) ) {
								try {
									Util::retain_purge_fallback_file(
										$fs,
										function ( string $path ) use ( $root_slash ): bool {
											if ( '' === $path || false !== strpos( $path, "\0" ) || false !== strpos( $path, '..' ) ) {
												return false;
											}
											return 0 === strpos( $path, $root_slash );
										},
										$full_path
									);
								} catch ( \Throwable $e ) {
									unset( $e );
								}
							}
							if ( ! $fs->delete( $full_path ) ) {
								$success = false;
							}
						}
					}
				}
			}

			return $success;
		}

		/**
		 * Purge page cache and used CSS together via a single shared action.
		 *
		 * Fail-open: on any generation/clear failure the full (unoptimised) CSS
		 * keeps serving — never broken styling. Multisite-safe: used-CSS files
		 * live per-site under the domain-based cache tree.
		 *
		 * @param string|null $url_path Optional URL path for a single-page purge; null purges all.
		 * @return array{page_cache: bool, used_css: bool} Per-store results.
		 * @since 2.0.0
		 */
		public static function purge_coupled( $url_path = null ): array {
			$result = array(
				'page_cache' => false,
				'used_css'   => false,
			);

			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) ) {
					$result['page_cache'] = \PerformanceOptimise\Inc\Cache::clear_cache( $url_path );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			try {
				if ( null !== $url_path && '' !== $url_path ) {
					$instance           = new self( Util::get_settings() );
					$result['used_css'] = $instance->delete_used_css( (string) $url_path );
				} else {
					$result['used_css'] = self::delete_all_used_css();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return $result;
		}

		/**
		 * Effective full-regeneration cooldown in seconds (issue #1107).
		 *
		 * Fail-open: any failure returns the default.
		 *
		 * @return int Cooldown seconds (>= 0).
		 * @since 2.2.0
		 */
		private function get_full_regen_cooldown(): int {
			// The class constant is the default (5 hours); the filter below
			// may override it per site.
			$default = self::FULL_REGEN_COOLDOWN_SECONDS;
			try {
				if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_used_css_regen_cooldown' ) ) {
					return (int) $default;
				}
				/**
				 * Filter the used-CSS full-regeneration cooldown (issue #1107).
				 *
				 * Bounds how often regenerate_all() may queue site-wide work
				 * when not forced. Explicit operator paths (builder purge
				 * after a wipe, manual REST/ability triggers) pass $force.
				 *
				 * @since 2.2.0
				 *
				 * @param int $cooldown Cooldown in seconds. Default 5 hours.
				 */
				$cooldown = apply_filters( 'wppo_used_css_regen_cooldown', $default );
				$cooldown = is_numeric( $cooldown ) ? (int) $cooldown : (int) $default;
				return $cooldown >= 0 ? $cooldown : (int) $default;
			} catch ( \Throwable $e ) {
				unset( $e );
				return (int) $default;
			}
		}

		/**
		 * Whether a non-forced full regeneration is inside the cooldown window (issue #1107).
		 *
		 * Fail-open: an unreadable timestamp never blocks work.
		 *
		 * @return bool True when the last full regen is newer than the cooldown.
		 * @since 2.2.0
		 */
		private function is_full_regen_cooled_down(): bool {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$last = (int) get_option( self::LAST_FULL_REGEN_OPTION, 0 );
				if ( $last <= 0 ) {
					return false;
				}
				return ( time() - $last ) < $this->get_full_regen_cooldown();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a completed full-regeneration scan (issue #1107).
		 *
		 * Written whenever regenerate_all() performs a scan — whether it
		 * queued jobs or correctly skipped every post as fresh — so repeat
		 * callers hit the cooldown instead of re-scanning. Fail-open.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		private function mark_full_regen(): void {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::LAST_FULL_REGEN_OPTION, time(), false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Effective used-CSS delivery mode (issue #1220).
		 *
		 * Additive `file_optimisation.usedCSSDeliveryMode` key; unknown or
		 * missing values fail open to 'file' (current render-blocking link).
		 *
		 * @param array|null $file_opts Optional file_optimisation settings (defaults to plugin settings).
		 * @return string One of self::DELIVERY_MODES.
		 * @since 2.2.0
		 */
		public static function get_used_css_delivery_mode( ?array $file_opts = null ): string {
			try {
				if ( null === $file_opts ) {
					$settings  = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ? Util::get_settings() : array();
					$file_opts = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
				}
				$mode = isset( $file_opts['usedCSSDeliveryMode'] ) ? strtolower( trim( (string) $file_opts['usedCSSDeliveryMode'] ) ) : 'file';
				if ( in_array( $mode, self::DELIVERY_MODES, true ) ) {
					return $mode;
				}
				return 'file';
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'file';
			}
		}

		/**
		 * Timestamp of the last full used-CSS regeneration (issue #1220).
		 *
		 * Fail-open: unreadable storage returns 0.
		 *
		 * @return int Unix timestamp, or 0 when never recorded.
		 * @since 2.2.0
		 */
		public static function get_last_full_regen_time(): int {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return 0;
				}
				return (int) get_option( self::LAST_FULL_REGEN_OPTION, 0 );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Staleness signal for the admin UI (issue #1220).
		 *
		 * Surfaces the last-regen time plus a stale flag (older than
		 * STALE_THRESHOLD_SECONDS, or never regenerated while the feature is
		 * on) so operators know when builder/theme edits outran regeneration.
		 * Read-only and fail-open.
		 *
		 * @return array{last_regen:int,last_regen_human:string,is_stale:bool,cooldown_remaining:int,delivery_mode:string}
		 * @since 2.2.0
		 */
		public static function get_staleness_info(): array {
			$info = array(
				'last_regen'         => 0,
				'last_regen_human'   => '',
				'is_stale'           => false,
				'cooldown_remaining' => 0,
				'delivery_mode'      => 'file',
			);
			try {
				$last               = self::get_last_full_regen_time();
				$info['last_regen'] = $last > 0 ? $last : 0;
				if ( $last > 0 && function_exists( 'wp_date' ) ) {
					try {
						$info['last_regen_human'] = (string) wp_date( 'Y-m-d H:i:s', $last );
					} catch ( \Throwable $e ) {
						unset( $e );
						$info['last_regen_human'] = gmdate( 'Y-m-d H:i:s', $last ) . ' UTC';
					}
				} elseif ( $last > 0 ) {
					$info['last_regen_human'] = gmdate( 'Y-m-d H:i:s', $last ) . ' UTC';
				}
				$enabled = false;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					$settings = Util::get_settings();
					$enabled  = ! empty( $settings['file_optimisation']['removeUnusedCSS'] );
				}
				$info['is_stale']      = $enabled && ( $last <= 0 || ( time() - $last ) > self::STALE_THRESHOLD_SECONDS );
				$info['delivery_mode'] = self::get_used_css_delivery_mode();
				if ( function_exists( 'get_option' ) ) {
					$targeted = (int) get_option( self::TARGETED_REGEN_OPTION, 0 );
					if ( $targeted > 0 ) {
						$cooldown                   = self::get_effective_targeted_cooldown();
						$remaining                  = $cooldown - ( time() - $targeted );
						$info['cooldown_remaining'] = $remaining > 0 ? $remaining : 0;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $info;
		}

		/**
		 * Effective targeted-regeneration cooldown in seconds (issue #1220).
		 *
		 * Fail-open: any failure returns the default.
		 *
		 * @return int Cooldown seconds (>= 0).
		 * @since 2.2.0
		 */
		private function get_targeted_regen_cooldown(): int {
			return self::get_effective_targeted_cooldown();
		}

		/**
		 * Static variant of the targeted-regen cooldown (issue #1220).
		 *
		 * Shares the filterable default with the instance gate so static
		 * callers (e.g. get_staleness_info()) display the same remaining
		 * time the gate enforces. Fail-open: any failure returns the default.
		 *
		 * @return int Cooldown seconds (>= 0).
		 * @since 2.2.0
		 */
		private static function get_effective_targeted_cooldown(): int {
			$default = self::TARGETED_REGEN_COOLDOWN_SECONDS;
			try {
				if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) || ! has_filter( 'wppo_used_css_targeted_cooldown' ) ) {
					return (int) $default;
				}
				/**
				 * Filter the used-CSS targeted-regeneration cooldown (issue #1220).
				 *
				 * Bounds how often builder/theme updates may queue bounded
				 * targeted requeues.
				 *
				 * @since 2.2.0
				 *
				 * @param int $cooldown Cooldown in seconds. Default 1 hour.
				 */
				$cooldown = apply_filters( 'wppo_used_css_targeted_cooldown', $default );
				$cooldown = is_numeric( $cooldown ) ? (int) $cooldown : (int) $default;
				return $cooldown >= 0 ? $cooldown : (int) $default;
			} catch ( \Throwable $e ) {
				unset( $e );
				return (int) $default;
			}
		}

		/**
		 * Whether a targeted regeneration is inside the cooldown window (issue #1220).
		 *
		 * Fail-open: an unreadable timestamp never blocks work.
		 *
		 * @return bool True when the last targeted regen is newer than the cooldown.
		 * @since 2.2.0
		 */
		private function is_targeted_regen_cooled_down(): bool {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return false;
				}
				$last = (int) get_option( self::TARGETED_REGEN_OPTION, 0 );
				if ( $last <= 0 ) {
					return false;
				}
				return ( time() - $last ) < $this->get_targeted_regen_cooldown();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Record a targeted-regeneration pass (issue #1220).
		 *
		 * Fail-open.
		 *
		 * @return void
		 * @since 2.2.0
		 */
		private function mark_targeted_regen(): void {
			try {
				if ( function_exists( 'update_option' ) ) {
					update_option( self::TARGETED_REGEN_OPTION, time(), false );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Cooldown-gated targeted requeue after builder/theme updates (issue #1220).
		 *
		 * Requeues only stale variants (bounded by $cap, most-recently-modified
		 * first) instead of the whole site, so a burst of builder or theme
		 * updates cannot flood Action Scheduler. Skipped entirely inside the
		 * targeted cooldown window or when removeUnusedCSS is off. Fail-open:
		 * any uncertainty returns 0, never fatal.
		 *
		 * Multisite-safe: per-site options only.
		 *
		 * @param string $reason Short reason for logging (e.g. 'builder-update').
		 * @param int    $cap    Maximum posts to requeue in this pass.
		 * @return int Number of jobs queued.
		 * @since 2.2.0
		 */
		public static function request_targeted_regen( string $reason = '', int $cap = 20 ): int {
			try {
				$options = class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ? Util::get_settings() : array();
				if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
					return 0;
				}
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return 0;
				}
				// Hoisted scheduler snapshot (issue #1274 review): one
				// as_get_scheduled_actions() query serves the whole
				// targeted pass; requeue_for_post() consults the hint map
				// instead of one as_has_scheduled_action() per post.
				// Fail-open: an unavailable/failed lookup passes null so
				// each post falls back to the per-post check.
				$scheduled_hints = null;
				if ( function_exists( 'as_get_scheduled_actions' ) ) {
					try {
						$batch_actions = as_get_scheduled_actions(
							array(
								'hook'     => 'wppo_used_css_generate',
								'group'    => 'performance_optimisation',
								'per_page' => 1000,
								'status'   => 'pending',
							),
							'ARRAY_A'
						);
						if ( is_array( $batch_actions ) ) {
							$scheduled_hints = array();
							foreach ( $batch_actions as $action ) {
								if ( ! is_array( $action ) ) {
									continue;
								}
								$action_args = $action['args'] ?? null;
								if ( is_string( $action_args ) ) {
									$decoded     = json_decode( $action_args, true );
									$action_args = is_array( $decoded ) ? $decoded : null;
								}
								if ( is_array( $action_args ) && isset( $action_args['post_id'] ) ) {
									$scheduled_hints[ (int) $action_args['post_id'] ] = true;
								}
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						$scheduled_hints = null;
					}
				}
				$instance = new self( $options );
				if ( $instance->is_targeted_regen_cooled_down() ) {
					return 0;
				}
				$cap      = $cap > 0 ? min( $cap, 100 ) : 20;
				$post_ids = array();
				if ( function_exists( 'get_posts' ) ) {
					try {
						$posts = get_posts(
							array(
								'numberposts' => $cap * 2,
								'post_type'   => function_exists( 'get_post_types' ) ? array_values( array_diff( (array) get_post_types( array( 'public' => true ), 'names' ), array( 'attachment' ) ) ) : array( 'post', 'page' ),
								'post_status' => 'publish',
								'orderby'     => 'modified',
								'order'       => 'DESC',
								'fields'      => 'ids',
							)
						);
						if ( is_array( $posts ) ) {
							foreach ( $posts as $post_id ) {
								$post_ids[] = (int) $post_id;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( empty( $post_ids ) ) {
					return 0;
				}
				$queued = 0;
				foreach ( $post_ids as $post_id ) {
					if ( $queued >= $cap ) {
						break;
					}
					if ( $post_id <= 0 ) {
						continue;
					}
					try {
						// Already-vs-new distinction (issue #1310 review):
						// a hint-hit or lost unique-race means the job is
						// pending but not newly scheduled — it must not
						// inflate the queued count, consume cap, or trigger
						// mark_targeted_regen()/logging on its own.
						$already_scheduled = false;
						if ( self::requeue_for_post( $post_id, $scheduled_hints, $instance, $already_scheduled ) && ! $already_scheduled ) {
							++$queued;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( $queued > 0 ) {
					$instance->mark_targeted_regen();
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) ) {
						try {
							Log::add(
								sprintf(
									/* translators: 1: number of jobs, 2: reason */
									__( 'Targeted used-CSS regen queued %1$d jobs (%2$s).', 'performance-optimisation' ),
									$queued,
									'' !== $reason ? $reason : 'update'
								)
							);
						} catch ( \Throwable $e ) {
							unset( $e );
						}
					}
				}
				return $queued;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Whether the stored used-CSS variant is fresh for a post (issue #1107).
		 *
		 * Fresh means: the variant file exists and its mtime is not older
		 * than the post's last modification (post-modification freshness
		 * only). The content-checksum verdict reuses is_checksum_stale(),
		 * which is fail-open on a missing sidecar or missing checksum
		 * signal, matching the frontend hit path in process_buffer().
		 * Source-CSS drift with no post modification is still healed
		 * lazily by process_buffer() on the next visit, not by this scan.
		 * Fail-open: any unresolvable permalink, missing file, stat
		 * failure, or exception reports stale so work is never dropped on
		 * uncertainty.
		 *
		 * @param int         $post_id      Post ID.
		 * @param string      $modified_gmt Post modification time (GMT, Y-m-d H:i:s).
		 * @param string|null $permalink Optional pre-resolved permalink (scan path passes its batch map so the URL is resolved once per post, not twice).
		 * @return bool True when regeneration can be skipped for this post.
		 * @since 2.2.0
		 */
		private function is_variant_fresh_for_post( int $post_id, string $modified_gmt, ?string $permalink = null ): bool {
			try {
				if ( $post_id <= 0 || '' === $modified_gmt || '0000-00-00 00:00:00' === $modified_gmt ) {
					return false;
				}
				if ( null === $permalink ) {
					if ( ! function_exists( 'get_permalink' ) ) {
						return false;
					}
					$permalink = get_permalink( $post_id );
				}
				if ( ! is_string( $permalink ) || '' === $permalink ) {
					return false;
				}
				$variant_path = $this->get_used_css_path( $permalink );
				if ( '' === $variant_path || ! file_exists( $variant_path ) ) {
					return false;
				}
				$mtime = filemtime( $variant_path );
				if ( false === $mtime ) {
					return false;
				}
				$modified = strtotime( $modified_gmt . ' UTC' );
				if ( false === $modified ) {
					return false;
				}
				if ( $mtime < $modified ) {
					return false;
				}
				// Scan-path fast lane: cron/scheduler contexts have no
				// $wp_styles queue (no source signal for this request), so
				// the sidecar checksum probe cannot detect anything the
				// mtime check missed — skip the file_get_contents and let
				// process_buffer() heal source-CSS drift lazily on visit.
				if ( empty( $GLOBALS['wp_styles']->queue ?? array() ) ) {
					return true;
				}
				return ! $this->is_checksum_stale( $variant_path );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a used-CSS generation job is pending or running.
		 *
		 * Single home for the 0-return disambiguation used after a
		 * `Util::enqueue_unique_async_action()` 0 return (issue #1310
		 * review): pending is probed via `as_has_scheduled_action()` and a
		 * winner that already transitioned to running is caught via a
		 * bounded `STATUS_RUNNING` lookup, mirroring the CCSS
		 * `has_pending_ccss_job()` and PageSpeed `find_pending_job_id()`
		 * backstops. Fail-open: returns false when the lookup APIs are
		 * unavailable or throw.
		 *
		 * @since 2.2.0
		 * @param array $args Action arguments.
		 * @return bool True when a matching job is pending or running.
		 */
		private static function is_used_css_job_live( array $args ): bool {
			try {
				if ( function_exists( 'as_has_scheduled_action' ) && (bool) as_has_scheduled_action( 'wppo_used_css_generate', $args, 'performance_optimisation' ) ) {
					return true;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
					return false;
				}
				$running = as_get_scheduled_actions(
					array(
						'hook'     => 'wppo_used_css_generate',
						'args'     => $args,
						'group'    => 'performance_optimisation',
						'status'   => \ActionScheduler_Store::STATUS_RUNNING,
						'per_page' => 1,
					),
					'ids'
				);
				return is_array( $running ) && ! empty( $running );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Queue used-CSS regeneration for a single post (builder-drift requeue).
		 *
		 * De-duplicates via as_has_scheduled_action(). Skips queueing when
		 * the stored variant is already fresh for the post (issue #1107;
		 * post-modification freshness only — source-CSS drift is healed
		 * lazily by process_buffer() on the next visit) — an explicit
		 * editor save bumps post_modified_gmt, so genuine changes still
		 * enqueue. No-op when removeUnusedCSS is off or Action Scheduler
		 * is unavailable.
		 *
		 * @param int        $post_id Post ID to requeue.
		 * @param array|null $scheduled_hints Optional hoisted pending-job map (post ID => true); null falls back to per-post lookup.
		 * @param self|null  $instance Optional hoisted instance (shares parsed safelist/memo across loop calls).
		 * @param bool|null  $already_scheduled Optional out flag: set to true when the job was already pending (hint-hit, pre-check hit, or lost unique-race) rather than newly scheduled; false when a new job was inserted. Untouched (stays false) on skip/failure. Lets bulk callers count only genuinely new jobs (issue #1310).
		 * @return bool True when a job was queued or already scheduled; false when skipped as fresh or on failure.
		 * @since 2.0.0
		 * @since 2.2.0 Optional $scheduled_hints for batched targeted regen.
		 * @since 2.2.0 Optional $instance to avoid per-post re-construction.
		 * @since 2.2.0 Optional $already_scheduled out flag distinguishing already-pending from newly-scheduled.
		 */
		public static function requeue_for_post( int $post_id, ?array $scheduled_hints = null, ?self $instance = null, ?bool &$already_scheduled = null ): bool {
			$already_scheduled = false;
			if ( $post_id <= 0 ) {
				return false;
			}
			try {
				$options = Util::get_settings();
				if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
					return false;
				}
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return false;
				}
				$args = array( 'post_id' => $post_id );
				// Hoisted snapshot hint (issue #1274 review): the targeted
				// regen path passes one as_get_scheduled_actions() snapshot
				// so N posts cost one query instead of N
				// as_has_scheduled_action() reads.
				if ( null !== $scheduled_hints && isset( $scheduled_hints[ $post_id ] ) ) {
					$already_scheduled = true;
					return true;
				}
				// Atomic-first on AS 4.x (issue #1310 review): the unique
				// enqueue below dedupes by itself, so the per-post
				// pre-check SELECT only runs when unique inserts are
				// unsupported (one redundant SELECT saved per post save).
				// Util ships in-repo: no method_exists guard needed.
				$use_unique = Util::supports_action_scheduler_unique();
				if ( ! $use_unique && function_exists( 'as_has_scheduled_action' ) && ( null === $scheduled_hints ) && as_has_scheduled_action( 'wppo_used_css_generate', $args, 'performance_optimisation' ) ) {
					$already_scheduled = true;
					return true;
				}
				try {
					$modified_gmt = '';
					if ( function_exists( 'get_post' ) ) {
						$post = get_post( $post_id );
						if ( is_object( $post ) && isset( $post->post_modified_gmt ) && is_string( $post->post_modified_gmt ) ) {
							$modified_gmt = $post->post_modified_gmt;
						}
					}
					if ( '' !== $modified_gmt && ( $instance ?? new self( $options ) )->is_variant_fresh_for_post( $post_id, $modified_gmt ) ) {
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				// Atomic unique enqueue (issue #1310) closes the
				// check-then-act race above; Util ships in-repo so the
				// helper is called directly — its internal function_exists
				// + supports_* + try/catch already fails open to 0.
				// The return is gated (issue #1310 review): a 0 with no job
				// pending means the enqueue failed, so report false instead
				// of counting a phantom job.
				$job_id = Util::enqueue_unique_async_action( 'wppo_used_css_generate', $args, 'performance_optimisation' );
				if ( 0 === $job_id ) {
					// Strict gate with running backstop (issue #1310
					// review): without a verifiable pending or running job
					// the 0 is a scheduler failure, so report false instead
					// of counting a phantom job. A lost unique-race (job now
					// live, foreign winner) reports true but flags
					// already-scheduled so callers count only new jobs.
					$race_won = self::is_used_css_job_live( $args );
					if ( ! $race_won ) {
						return false;
					}
					$already_scheduled = true;
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Order post IDs worst-p75 LCP first for used-CSS queue prioritization.
		 *
		 * Read-only ordering signal (issue #1059): scores each post's
		 * permalink via RUM::score_url_lcp() (RUM path p75 + latest
		 * PageSpeed trend LCP blend). Worst p75 first so slow pages get
		 * optimized CSS first. Fail-open: missing RUM/trends, disabled
		 * setting, or any failure returns FIFO ID order. Purge-coupled
		 * invalidation semantics unchanged. Multisite-safe: per-site
		 * option reads only.
		 *
		 * @since 2.0.0
		 * @param int[]              $post_ids Post IDs in FIFO order.
		 * @param array<int, string> $permalinks Optional pre-resolved post ID => permalink map (scan path builds it once per batch so ordering + freshness share one get_permalink() per post).
		 * @param array|null         $priority Optional hoisted RUM path-LCP priority map (regenerate_all fetches once per run; null fetches per call).
		 * @param array|null         $trends Optional hoisted PageSpeed trends (null fetches per call).
		 * @return int[] Ordered post IDs (same entries).
		 */
		public static function order_post_ids_by_rum_priority( array $post_ids, array $permalinks = array(), ?array $priority = null, ?array $trends = null ): array {
			try {
				if ( count( $post_ids ) < 2 ) {
					return array_values( array_map( 'intval', $post_ids ) );
				}
				$post_ids = array_values( array_map( 'intval', $post_ids ) );
				$enabled  = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) && function_exists( 'get_option' ) ) {
					$settings = \PerformanceOptimise\Inc\Util::get_settings();
					if ( isset( $settings['file_optimisation']['usedCssRumPriority'] ) ) {
						$enabled = (bool) $settings['file_optimisation']['usedCssRumPriority'];
					}
				}
				if ( ! $enabled ) {
					return $post_ids;
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\RUM' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'get_path_lcp_priority' ) || ! method_exists( 'PerformanceOptimise\Inc\RUM', 'score_url_lcp' ) ) {
					return $post_ids;
				}
				if ( ! function_exists( 'get_permalink' ) ) {
					return $post_ids;
				}
				// Hoisted signals (issue #1274 review): regenerate_all()
				// passes one priority/trends snapshot per run; direct
				// callers passing null still fetch once per call.
				if ( null === $priority ) {
					$priority = \PerformanceOptimise\Inc\RUM::get_path_lcp_priority();
				}
				// Fetch trends once for the whole ordering pass instead of once
				// per post inside score_url_lcp() (issue #1059 review). A
				// trend-only site (no RUM samples yet) must still prioritize,
				// so only fall back to FIFO when both signals are empty.
				if ( null === $trends ) {
					$trends = null;
					if ( class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) && method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) ) {
						$trends = \PerformanceOptimise\Inc\Pagespeed::get_trends();
						if ( ! is_array( $trends ) ) {
							$trends = array();
						}
					}
				}
				if ( empty( $priority ) && empty( $trends ) ) {
					return $post_ids;
				}
				$scores = array();
				foreach ( $post_ids as $post_id ) {
					if ( array_key_exists( $post_id, $permalinks ) ) {
						$permalink = $permalinks[ $post_id ];
					} else {
						$permalink = get_permalink( $post_id );
					}
					$scores[ $post_id ] = ( is_string( $permalink ) && '' !== $permalink ) ? \PerformanceOptimise\Inc\RUM::score_url_lcp( $permalink, $priority, $trends ) : 0.0;
				}
				$has_signal = false;
				foreach ( $scores as $score ) {
					if ( $score > 0 ) {
						$has_signal = true;
						break;
					}
				}
				if ( ! $has_signal ) {
					return $post_ids;
				}
				$order = $post_ids;
				// usort() is not stable: break score ties by original FIFO
				// position so equal-score posts keep a deterministic order.
				$pos = array_flip( array_values( $post_ids ) );
				usort(
					$order,
					static function ( $a, $b ) use ( $scores, $pos ) {
						$sa = $scores[ (int) $a ] ?? 0.0;
						$sb = $scores[ (int) $b ] ?? 0.0;
						if ( $sa === $sb ) {
							return ( $pos[ (int) $a ] ?? 0 ) <=> ( $pos[ (int) $b ] ?? 0 );
						}
						return $sa > $sb ? -1 : 1;
					}
				);
				return array_values( array_map( 'intval', $order ) );
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				return array_values( array_map( 'intval', $post_ids ) );
			}
		}

		/**
		 * Read the configured per-run used-CSS queue cap (issue #1164).
		 *
		 * The single source of truth is `Util::get_default_settings()`
		 * (`file_optimisation.usedCssQueueCap`, default 50). Fail-open: a
		 * missing key falls back to the default; a non-positive / non-numeric
		 * value means uncapped (current behaviour) so a rogue setting can
		 * never starve the queue. Oversized values are clamped to
		 * MAX_USED_CSS_QUEUE_CAP.
		 *
		 * @return int Per-run cap, or PHP_INT_MAX when uncapped.
		 * @since 2.2.0
		 */
		public static function get_used_css_queue_cap(): int {
			try {
				$options = Util::get_settings();
				if ( ! isset( $options['file_optimisation']['usedCssQueueCap'] ) ) {
					return self::DEFAULT_USED_CSS_QUEUE_CAP;
				}
				$raw = $options['file_optimisation']['usedCssQueueCap'];
				if ( ! is_numeric( $raw ) ) {
					return PHP_INT_MAX;
				}
				$cap = (int) $raw;
				if ( $cap <= 0 ) {
					return PHP_INT_MAX;
				}
				return min( $cap, self::MAX_USED_CSS_QUEUE_CAP );
			} catch ( \Throwable $e ) {
				unset( $e );
				return PHP_INT_MAX;
			}
		}

		/**
		 * Whether viewport-split used-CSS variants are enabled (issue #1164).
		 *
		 * Shares the additive `file_optimisation.ccssViewportVariants` flag
		 * with Critical_CSS (default false = current single-file behaviour
		 * verbatim). Fail-open: any error returns false.
		 *
		 * @return bool True when split variants should be emitted/served.
		 * @since 2.2.0
		 */
		public static function is_viewport_variants_enabled(): bool {
			try {
				$options = Util::get_settings();
				$raw     = $options['file_optimisation']['ccssViewportVariants'] ?? false;
				if ( is_array( $raw ) ) {
					return ! empty( $raw );
				}
				return (bool) $raw;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Get the viewport-split variant filename for a base filename.
		 *
		 * Only `mobile` / `desktop` slugs are accepted; anything else returns
		 * ''. `used-css.css` maps to `used-css.{variant}.css`.
		 *
		 * @param string $variant Variant slug ('mobile'|'desktop').
		 * @return string Variant filename, or '' when refused.
		 * @since 2.2.0
		 */
		public static function get_variant_filename( string $variant ): string {
			if ( ! in_array( $variant, self::VIEWPORT_VARIANTS, true ) ) {
				return '';
			}
			return 'used-css.' . $variant . '.css';
		}

		/**
		 * Get the used-CSS variant file path for a URL (issue #1164).
		 *
		 * Fail-open: returns '' when variants are disabled or the URL is
		 * refused, so callers fall back to {@see get_used_css_path()}.
		 *
		 * @param string $url     Page URL.
		 * @param string $variant Variant slug ('mobile'|'desktop').
		 * @return string Filesystem path, or '' when refused/disabled.
		 * @since 2.2.0
		 */
		public function get_used_css_variant_path( string $url = '', string $variant = 'mobile' ): string {
			try {
				if ( ! self::is_viewport_variants_enabled() ) {
					return '';
				}
				$filename = self::get_variant_filename( $variant );
				if ( '' === $filename || '' === $this->cache_root_dir || '' === $this->domain ) {
					return '';
				}
				$effective = ( '' === $url ) ? $this->get_url_path( $url ) : $url;
				return Util::sanitize_cache_path( $this->cache_root_dir, $this->domain, $effective, $filename );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Resolve the usable used-CSS path with fail-open fallback (issue #1164).
		 *
		 * Prefers the `{variant}` sidecar when it exists and is not stale
		 * (mtime >= single-file mtime); otherwise returns the single
		 * `used-css.css` path. Returns '' only when no usable path exists —
		 * callers then serve the deferred full stylesheet (never fatal).
		 *
		 * @param string $url     Page URL.
		 * @param string $variant Variant slug ('mobile'|'desktop').
		 * @return string Usable filesystem path, or '' when none.
		 * @since 2.2.0
		 */
		public function resolve_used_css_path( string $url = '', string $variant = 'mobile' ): string {
			try {
				$single = $this->get_used_css_path( $url );
				if ( ! self::is_viewport_variants_enabled() ) {
					return $single;
				}
				$variant_path = $this->get_used_css_variant_path( $url, $variant );
				if ( '' === $variant_path || ! file_exists( $variant_path ) ) {
					return $single;
				}
				if ( '' !== $single && file_exists( $single ) ) {
					$variant_mtime = filemtime( $variant_path );
					$single_mtime  = filemtime( $single );
					if ( false === $variant_mtime || false === $single_mtime || $variant_mtime < $single_mtime ) {
						return $single;
					}
				}
				if ( ! Util::css_file_valid( $variant_path ) ) {
					return $single;
				}
				return $variant_path;
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					return $this->get_used_css_path( $url );
				} catch ( \Throwable $inner ) {
					unset( $inner );
					return '';
				}
			}
		}

		/**
		 * Whether a stylesheet handle is safe to strip when a block-library
		 * stylesheet is present (issue #1164).
		 *
		 * The core `wp-block-library` handle (and its minified/block variants)
		 * must never be stripped without a verified used-CSS replacement:
		 * otherwise blocks flash unstyled (FOUC). Returns false for the
		 * block-library family so callers keep the original link.
		 *
		 * @param string $handle Stylesheet handle.
		 * @return bool True when stripping is safe; false for block-library.
		 * @since 2.2.0
		 */
		public static function is_safe_to_strip_handle( string $handle ): bool {
			if ( '' === trim( $handle ) ) {
				return true;
			}
			if ( 'wp-block-library' === $handle || 0 === strpos( $handle, 'wp-block-library' ) ) {
				return false;
			}
			if ( false !== strpos( $handle, 'block-library' ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Elementor smoke check for used-CSS output (issue #1164).
		 *
		 * Pages carrying Elementor markers (`data-elementor-type`,
		 * `elementor-widget`) must keep builder coverage in the purged CSS;
		 * otherwise the candidate is rejected and the full stylesheet is
		 * served (no FOUC). Non-builder pages always pass. Fail-open: any
		 * error passes so output is never dropped on uncertainty — except an
		 * empty candidate on a builder page, which fails closed to the full
		 * stylesheet.
		 *
		 * @param string $html       Page HTML.
		 * @param string $purged_css Purged CSS candidate.
		 * @return bool True when it is safe to serve the purged CSS.
		 * @since 2.2.0
		 */
		public static function passes_elementor_smoke( string $html, string $purged_css ): bool {
			if ( class_exists( 'PerformanceOptimise\Inc\Css_Safelist' ) ) {
				return Css_Safelist::passes_elementor_smoke( $html, $purged_css );
			}
			try {
				if ( '' === $html ) {
					return true;
				}
				$has_builder = false !== strpos( $html, 'data-elementor-type' )
					|| false !== strpos( $html, 'elementor-widget' )
					|| false !== strpos( $html, 'elementor-popup' );
				if ( ! $has_builder ) {
					return true;
				}
				if ( '' === trim( $purged_css ) ) {
					return false;
				}
				return false !== strpos( $purged_css, '.elementor' )
					|| false !== strpos( $purged_css, 'elementor-' )
					|| false !== strpos( $purged_css, 'data-elementor-type' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Queue background used-CSS regeneration for all published posts.
		 *
		 * Guards against queue churn (issue #1107): a coarse cooldown skips
		 * repeat full scans within the window, and per-post freshness skips
		 * posts whose stored variant is newer than their last modification
		 * (post-modification freshness only; source-CSS drift with no post
		 * edit is healed lazily by process_buffer() on the next visit).
		 * The pending-action de-duplication is kept for concurrent
		 * queueing. Fail-open: any uncertainty enqueues as before —
		 * freshness never drops work.
		 *
		 * @param bool $force Bypass the cooldown (explicit operator paths:
		 *                    builder purge after a wipe, manual triggers).
		 *                    Per-post freshness still applies.
		 * @return int Number of jobs queued.
		 * @since 1.9.0
		 * @since 2.2.0 Added $force parameter, cooldown, and per-post freshness skip.
		 * @since 2.2.0 Capped per-run queue with RUM-worst-first ordering (issue #1164).
		 */
		public function regenerate_all( bool $force = false ): int {
			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return 0;
			}

			if ( ! $force && $this->is_full_regen_cooled_down() ) {
				return 0;
			}

			// Per-run cap (issue #1164): RUM-worst-first batches are queued
			// only up to the cap so one run cannot flood the scheduler; the
			// next cron run picks up the remainder. Fail-open: an invalid cap
			// enqueues everything (current behaviour).
			$queue_cap = self::get_used_css_queue_cap();

			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$post_types = array_diff( $post_types, array( 'attachment' ) );
			// Builder-template exclusion (issue #1274): library templates
			// are never rendered standalone — skip them so regen never
			// wastes queries + CPU fetching them. Additive setting with
			// filter extensibility; fail-open to previous behaviour.
			try {
				$excluded   = self::get_excluded_post_types();
				$post_types = array_diff( $post_types, $excluded );
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$queued  = 0;
			$batch   = 200;
			$last_id = 0;

			if ( empty( $post_types ) ) {
				$this->mark_full_regen();
				return 0;
			}

			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

			// Intentional bypass of WP_Query filters (pre_get_posts, language plugins) for performance:
			// direct $wpdb cursor pagination (ID > last_id) avoids OFFSET cost on large sites. Site-specific
			// filtering must be handled separately if needed.
			// RUM prioritization is applied per cursor batch (sort-then-enqueue):
			// buffering every post ID plus a get_permalink() per post in one
			// worker risks memory growth/timeouts on large sites (issue #1059
			// review), so each 200-row batch is ordered worst-p75 first and
			// enqueued before the next batch is fetched. Streaming preserves the
			// original memory profile; order_post_ids_by_rum_priority() is a
			// no-op FIFO passthrough when the setting is off or no signal exists.
			// Hoisted scheduler snapshot (paginated to exhaustion) so a 5000-post
			// site issues a bounded set of store queries instead of one per
			// 200-post cursor batch. $lookup_ok disambiguates "none scheduled"
			// from "lookup unavailable/failed", gating the per-post fallback.
			$scheduled = array();
			$lookup_ok = false;
			// Hoisted unique probe first (issue #1310 review): on AS 4.x
			// the atomic unique insert below dedupes by itself, so the
			// full pending snapshot (up to 20 pages x 1000 rows + JSON
			// decode per row) is pure overhead on every cron run — skip
			// it and rely on the per-row 0-return re-check for races.
			// Util ships in-repo: no method_exists guard needed.
			$run_use_unique = Util::supports_action_scheduler_unique();
			if ( ! $run_use_unique && function_exists( 'as_get_scheduled_actions' ) ) {
				try {
					// Audit #1325: the legacy pagination ran up to 20 pages
					// on every cron tick. Capped at 5 pages (5000 rows).
					// Deliberately no transient memo here: a stale snapshot
					// would miss recently-enqueued posts and re-enqueue
					// duplicates while $lookup_ok suppresses the per-row
					// fallback check (review feedback on #1326).
					$as_offset   = 0;
					$as_per_page = 1000;
					// Capped at 5 pages (5000 rows): beyond that the dedup
					// map is already large enough and the unique-insert path
					// handles the rest on AS 4.x.
					// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- bounded pagination loop.
					for ( $page = 0; $page < 5; $page++ ) {
							$batch_actions = as_get_scheduled_actions(
								array(
									'hook'     => 'wppo_used_css_generate',
									'group'    => 'performance_optimisation',
									'status'   => 'pending',
									'per_page' => $as_per_page,
									'offset'   => $as_offset,
								),
								'ARRAY_A'
							);
						if ( ! is_array( $batch_actions ) || empty( $batch_actions ) ) {
							break;
						}
						foreach ( $batch_actions as $action ) {
							if ( ! is_array( $action ) ) {
								continue;
							}
							$action_args = $action['args'] ?? null;
							if ( is_string( $action_args ) ) {
								$decoded     = json_decode( $action_args, true );
								$action_args = is_array( $decoded ) ? $decoded : null;
							}
							if ( is_array( $action_args ) && isset( $action_args['post_id'] ) ) {
								$scheduled[ (int) $action_args['post_id'] ] = true;
							}
						}
						// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- bounded pagination loop.
						if ( count( $batch_actions ) < $as_per_page ) {
							break;
						}
							$as_offset += $as_per_page;
					}
					$lookup_ok = true;
				} catch ( \Throwable ) {
					$scheduled = array();
					$lookup_ok = false;
				}
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Intentional direct query, $placeholders is count-derived only.
			$scan_ok = true;
			$capped  = false;
			// Hoisted RUM/trends snapshot (issue #1274 review): one read
			// per run instead of one per 200-row batch inside the ordering
			// helper. Fail-open: lookup failure passes nulls so the helper
			// falls back to its own per-call fetch/FIFO behaviour.
			$run_priority = null;
			$run_trends   = null;
			$signals_ok   = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\RUM' ) && method_exists( 'PerformanceOptimise\Inc\RUM', 'get_path_lcp_priority' ) ) {
					$run_priority = \PerformanceOptimise\Inc\RUM::get_path_lcp_priority();
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Pagespeed' ) && method_exists( 'PerformanceOptimise\Inc\Pagespeed', 'get_trends' ) ) {
					$run_trends = \PerformanceOptimise\Inc\Pagespeed::get_trends();
					if ( ! is_array( $run_trends ) ) {
						$run_trends = array();
					}
				}
				$signals_ok = true;
			} catch ( \Throwable $e ) {
				unset( $e );
				$signals_ok = false;
			}
			// Hoisted unique probe already computed above (issue #1310
			// review): reused here so the supports_* probe is paid once
			// per run, not once per post on the bulk hot path.
			do {
				// Cursor pagination via ID > last_id avoids O(offset) MySQL scans.
				$prepare_args   = array_values( $post_types );
				$prepare_args[] = $last_id;
				$prepare_args[] = $batch;
				$post_rows      = $wpdb->get_results(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is count-derived; $prepare_args holds post types + 2 ints via spread.
					$wpdb->prepare(
						"SELECT ID, post_modified_gmt FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
						...$prepare_args
					),
					ARRAY_A
				);
				if ( false === $post_rows || null === $post_rows ) {
					$scan_ok = false;
					break;
				}
				if ( empty( $post_rows ) || ! is_array( $post_rows ) ) {
					break;
				}

				$post_ids = array();
				$modified = array();
				foreach ( $post_rows as $post_row ) {
					if ( ! is_array( $post_row ) || ! isset( $post_row['ID'] ) ) {
						continue;
					}
					$row_id              = (int) $post_row['ID'];
					$post_ids[]          = $row_id;
					$modified[ $row_id ] = isset( $post_row['post_modified_gmt'] ) ? (string) $post_row['post_modified_gmt'] : '';
				}
				if ( empty( $post_ids ) ) {
					break;
				}

				// One permalink resolution per post per batch: the map is
				// shared by RUM-priority ordering and the freshness check
				// below instead of each pass calling get_permalink()
				// separately (issue #1274 review). Built lazily (issue
				// #1310 review): with RUM/trends signals empty (the
				// default) ordering is a FIFO no-op, so pre-resolving 200
				// permalinks just for the sort is wasted — the freshness
				// check resolves per-post itself when the map is empty.
				$needs_map     = $signals_ok && ( ! empty( $run_priority ) || ! empty( $run_trends ) );
				$permalink_map = array();
				if ( $needs_map && function_exists( 'get_permalink' ) ) {
					foreach ( $post_ids as $pid ) {
						try {
							$resolved = get_permalink( (int) $pid );
						} catch ( \Throwable $e ) {
							unset( $e );
							$resolved = false;
						}
						$permalink_map[ (int) $pid ] = is_string( $resolved ) ? $resolved : '';
					}
				}
				$batch_ids = $signals_ok
					? self::order_post_ids_by_rum_priority( array_values( array_map( 'intval', $post_ids ) ), $permalink_map, is_array( $run_priority ) ? $run_priority : array(), is_array( $run_trends ) ? $run_trends : array() )
					: self::order_post_ids_by_rum_priority( array_values( array_map( 'intval', $post_ids ) ), $permalink_map );

				foreach ( $batch_ids as $post_id ) {
					// Per-run cap: stop enqueueing once the cap is reached
					// (outer loop breaks below, so no further cursor batches
					// are fetched either).
					if ( $queued >= $queue_cap ) {
						break;
					}
					$post_id = (int) $post_id;
					if ( isset( $scheduled[ $post_id ] ) ) {
						continue;
					}
					// Atomic unique insert (issue #1310) dedupes by itself,
					// so the per-row pre-check below only runs when unique
					// inserts are unsupported (saves N SELECTs per batch).
					$use_unique = $run_use_unique;
					if ( ! $use_unique && ! $lookup_ok && function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_used_css_generate', array( 'post_id' => $post_id ), 'performance_optimisation' ) ) {
						$scheduled[ $post_id ] = true;
						continue;
					}
					if ( isset( $modified[ $post_id ] ) && '' !== $modified[ $post_id ] && $this->is_variant_fresh_for_post( $post_id, $modified[ $post_id ], $permalink_map[ $post_id ] ?? null ) ) {
						continue;
					}
					try {
						// The helper return is gated (issue #1310 review): a
						// 0 from a swallowed failure must not be counted as
						// queued (which would inflate logs and consume
						// queue_cap while blocking real jobs). A 0 with a
						// now-live job means a concurrent process won
						// the race — record it as scheduled WITHOUT
						// counting it as newly queued, so two concurrent
						// runs never double-count the same job. Util ships
						// in-repo: called directly, no method_exists guard.
						$job_id = Util::enqueue_unique_async_action(
							'wppo_used_css_generate',
							array( 'post_id' => $post_id ),
							'performance_optimisation'
						);
						if ( 0 === $job_id ) {
							// Strict gate with running backstop (issue #1310
							// review): without a verifiable pending or
							// running job the 0 is a scheduler failure, so
							// skip instead of counting a phantom job.
							if ( ! self::is_used_css_job_live( array( 'post_id' => $post_id ) ) ) {
								continue;
							}
							$scheduled[ $post_id ] = true;
							continue;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						continue;
					}
					$scheduled[ $post_id ] = true;
					++$queued;
				}

				$last_id = (int) end( $post_ids );
				// Per-run cap reached: stop cursor pagination so the next cron
				// run resumes the remainder (fail-open: uncapped runs paginate
				// to exhaustion as before).
				if ( $queued >= $queue_cap ) {
					$capped = true;
					break;
				}
				// Terminate only when last batch was partial; when total is exact multiple of $batch
				// the next SELECT returns empty and breaks at the top of the loop (one wasted query in that edge case).
				// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- count() on batch is intentional for loop termination.
			} while ( count( $post_ids ) === $batch );
			// phpcs:enable

			if ( $scan_ok && ! $capped ) {
				$this->mark_full_regen();
			}

			if ( $queued > 0 ) {
				Log::add(
					sprintf(
						/* translators: %d: Number of jobs */
						__( 'Queued %d used-CSS regeneration jobs.', 'performance-optimisation' ),
						$queued
					)
				);
			}

			return $queued;
		}

		/**
		 * Builder-template post types excluded from used-CSS generation (issue #1274).
		 *
		 * Shares the additive `file_optimisation.ccssExcludedPostTypes`
		 * setting (and the `wppo_ccss_excluded_post_types` filter) with
		 * Critical_CSS so both pipelines skip the same non-renderable
		 * builder templates — the CCSS-named key/filter is the intentional
		 * shared contract (kept for backward compatibility), not a naming
		 * accident. Fail-open: any error returns the built-in defaults.
		 * Used-CSS retries are intentionally out of scope: exclusion is a
		 * skip-and-continue (never queued), so no retry counter exists on
		 * this pipeline.
		 *
		 * @return string[] Excluded post type slugs.
		 * @since 2.2.0
		 */
		public static function get_excluded_post_types(): array {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'get_excluded_post_types' ) ) {
					return Critical_CSS::get_excluded_post_types();
				}
				$defaults = array( 'fl-builder-template', 'elementor_library' );
				$options  = Util::get_settings();
				$raw      = $options['file_optimisation']['ccssExcludedPostTypes'] ?? null;
				// Thin delegation to the shared parser so validation rules
				// live in exactly one place (issue #1274 review). Raw is
				// passed through so array values are preserved, not dropped.
				$parsed = ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'parse_excluded_slugs' ) )
					? Critical_CSS::parse_excluded_slugs( $raw )
					: array();
				// Additive merge with empty-fallback, mirroring
				// Critical_CSS::get_excluded_post_types().
				return array() !== $parsed ? array_values( array_unique( array_merge( $defaults, $parsed ) ) ) : $defaults;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array( 'fl-builder-template', 'elementor_library' );
			}
		}

		/**
		 * Whether a post ID belongs to an excluded builder-template type (issue #1274).
		 *
		 * Single delegation point so REST and worker call sites never
		 * hand-roll their own strtolower/in_array copies.
		 *
		 * Fail-open: unknown posts, missing APIs, or any error returns
		 * false (generate as before).
		 *
		 * @param int $post_id Post ID.
		 * @return bool True when the post should be skipped.
		 * @since 2.2.0
		 */
		public static function is_excluded_post( int $post_id ): bool {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'is_excluded_post' ) ) {
					return Critical_CSS::is_excluded_post( $post_id );
				}
				if ( $post_id <= 0 || ! function_exists( 'get_post_type' ) ) {
					return false;
				}
				$post_type = get_post_type( $post_id );
				if ( ! is_string( $post_type ) || '' === $post_type ) {
					return false;
				}
				return in_array( strtolower( trim( $post_type ) ), self::get_excluded_post_types(), true );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Process a single page for used-CSS generation (Action Scheduler callback).
		 *
		 * Builder-template post types are skipped without fetching (issue #1274).
		 *
		 * @param int $post_id The post ID.
		 * @return void
		 * @since 1.9.0
		 */
		public static function process_background( int $post_id ): void {
			// Builder-template skip-and-continue (issue #1274): never fetch
			// non-renderable library templates for used CSS.
			try {
				if ( self::is_excluded_post( $post_id ) ) {
					return;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				return;
			}

			// Same-site guard: never let a filtered permalink turn the worker
			// into an off-host fetch. Fail closed on helper errors.
			try {
				if ( function_exists( 'wp_parse_url' ) && function_exists( 'home_url' ) ) {
					$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
					$perm_host = wp_parse_url( $permalink, PHP_URL_HOST );
					if ( '' === $permalink || $perm_host !== $home_host ) {
						return;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return;
			}
			// The same-site guard above is the SSRF control. Do NOT use
			// wp_safe_remote_get()/reject_unsafe_urls here: WP's validator
			// rejects every private, loopback and non-dotted host, which
			// would silently break used-CSS generation on localhost and
			// staging installs (and on any site whose own hostname resolves
			// to a private address).
			$fetch_args = array(
				'timeout' => 15,
				'headers' => array(
					'X-WPPO-Used-CSS' => '1',
				),
			);
			$response   = wp_remote_get( $permalink, $fetch_args );

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$error_msg = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP status ' . wp_remote_retrieve_response_code( $response );
					// Audit #1362: centralized activity log instead of the
					// PHP error log (WP_DEBUG gate retained).
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
						Log::add( 'WPPO used-CSS generation failed for post ' . (int) $post_id . ': ' . sanitize_text_field( $error_msg ) );
					}
				}
				return;
			}

			$html = wp_remote_retrieve_body( $response );
			if ( empty( $html ) ) {
				return;
			}

			$css_assets = self::extract_css_assets_from_html( $html );
			if ( empty( $css_assets ) ) {
				return;
			}

			$options    = Util::get_settings();
			$used_css   = new self( $options );
			$purged_css = $used_css->generate_used_css( $html, $css_assets );

			if ( ! empty( $purged_css ) ) {
				$used_css->save_used_css( $purged_css, $permalink );
			}
		}

		/**
		 * Extract CSS assets from HTML content by finding <link rel="stylesheet"> tags.
		 *
		 * Uses the WP 6.9+ HTML processor when available (issue #883); falls
		 * back to the original Tag Processor walk on WP <6.9 or when the
		 * parse fails.
		 *
		 * @param string $html The HTML content.
		 * @return array Array of CSS content strings keyed by md5 hash of URL.
		 * @since 1.9.0
		 * @since 2.0.0 Added WP_HTML_Processor path with Tag Processor fallback.
		 */
		private static function extract_css_assets_from_html( string $html ): array {
			$assets = array();

			if ( Util::should_use_html_processor() ) {
				$from_processor = self::extract_css_assets_with_processor( $html );
				if ( null !== $from_processor ) {
					return $from_processor;
				}
			}

			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				return $assets;
			}
			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( array( 'tag_name' => 'link' ) ) ) {
				self::collect_css_asset_from_tag( $tags, $assets );
			}
			return $assets;
		}

		/**
		 * Extract stylesheet URLs via the WP 6.9+ HTML processor.
		 *
		 * Read-only token walk; returns null to trigger the Tag Processor
		 * fallback when the parser cannot be created or the token stream
		 * ended with a parse error.
		 *
		 * @since 2.0.0
		 *
		 * @param string $html The HTML content.
		 * @return array<string,string>|null CSS contents keyed by URL hash, or null on failure.
		 */
		private static function extract_css_assets_with_processor( string $html ): ?array {
			$processor = Util::create_html_processor( $html );
			if ( null === $processor ) {
				return null;
			}

			$assets = array();
			while ( $processor->next_token() ) {
				if (
					'#tag' === $processor->get_token_type()
					&& ! $processor->is_tag_closer()
					&& 'link' === strtolower( (string) $processor->get_tag() )
				) {
					self::collect_css_asset_from_tag( $processor, $assets );
				}
			}

			if ( null !== $processor->get_last_error() ) {
				return null;
			}

			return $assets;
		}

		/**
		 * Collect the stylesheet content linked by the current tag.
		 *
		 * Shared body of {@see extract_css_assets_from_html()}, operating on
		 * whichever processor is walking the document (WP_HTML_Processor
		 * extends WP_HTML_Tag_Processor).
		 *
		 * @since 2.0.0
		 *
		 * @param \WP_HTML_Tag_Processor $tags   Processor positioned on the current tag.
		 * @param array                  $assets Asset accumulator (passed by reference).
		 * @return void
		 */
		private static function collect_css_asset_from_tag( \WP_HTML_Tag_Processor $tags, array &$assets ): void {
			$rel = $tags->get_attribute( 'rel' );
			// Token check: rel is a space-separated list ("stylesheet",
			// "alternate stylesheet") and matching is ASCII case-insensitive.
			$is_stylesheet = false;
			if ( is_string( $rel ) ) {
				$tokens = preg_split( '/\s+/', strtolower( $rel ) );
				foreach ( is_array( $tokens ) ? $tokens : array() as $token ) {
					if ( 'stylesheet' === $token ) {
						$is_stylesheet = true;
						break;
					}
				}
			}
			if ( ! $is_stylesheet ) {
				return;
			}
			$href = $tags->get_attribute( 'href' );
			if ( ! $href ) {
				return;
			}
			$content = self::fetch_css_content_static( $href );
			if ( false !== $content ) {
				$assets[ md5( $href ) ] = $content;
			}
		}

		/**
		 * Fetch CSS content from a URL or local path (static version).
		 *
		 * @param string $url The CSS URL.
		 * @return string|false CSS content or false on failure.
		 * @since 1.9.0
		 */
		private static function fetch_css_content_static( string $url ) {
			$local_path = Util::get_local_path( $url );
			if ( '' !== $local_path ) {
				$fs = Util::init_filesystem();
				if ( $fs && $fs->exists( $local_path ) ) {
					return $fs->get_contents( $local_path );
				}
			}

			$response = wp_safe_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			return wp_remote_retrieve_body( $response );
		}

		/**
		 * Get all CSS assets (content) from enqueued styles.
		 *
		 * @return array Array of CSS content strings keyed by handle.
		 * @since 1.9.0
		 */
		public function get_all_css_assets(): array {
			global $wp_styles;
			if ( ! $wp_styles || empty( $wp_styles->queue ) ) {
				return array();
			}

			$assets = array();

			foreach ( $wp_styles->queue as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}

				$src = $wp_styles->registered[ $handle ]->src;
				if ( empty( $src ) ) {
					continue;
				}

				$content = $this->fetch_css_content( $src );
				if ( false !== $content && '' !== $content ) {
					$assets[ $handle ] = $content;
				}
			}

			return $assets;
		}

		/**
		 * Fetch CSS content from a URL or local path (delegates to static helper).
		 *
		 * @param string $url The CSS URL.
		 * @return string|false CSS content or false on failure.
		 * @since 1.9.0
		 */
		private function fetch_css_content( string $url ) {
			return self::fetch_css_content_static( $url );
		}

		/**
		 * Strip stylesheet <link> tags for the given quoted srcs (order-agnostic, bounded).
		 *
		 * Two-pass href-first then rel-first removal with bounded lazy
		 * quantifiers so large bundles cannot trigger catastrophic
		 * backtracking. Each pass is guarded by a null / preg_last_error()
		 * check; on anomaly the caller must fail open (leave CSS loading
		 * normally). Matches real-world <link> tags (far shorter than the
		 * bounds) identically to the previous unbounded pattern.
		 *
		 * The rel matcher is intentionally tolerant: it allows whitespace
		 * around `=` and space-separated rel tokens (e.g. `rel="alternate
		 * stylesheet"` or `rel = "stylesheet"`), while still requiring the
		 * token `stylesheet` so same-URL `preload`/`preconnect` hints are
		 * never stripped.
		 *
		 * @since 2.0.0
		 * @param string $buffer      The HTML buffer.
		 * @param array  $quoted_srcs preg_quote()d src URLs (delimiter '/').
		 * @return array|null Array [ string $stripped, int $strip_count ] or null on PCRE error.
		 */
		private function strip_stylesheet_links( string $buffer, array $quoted_srcs ): ?array {
			if ( empty( $quoted_srcs ) ) {
				return array( $buffer, 0 );
			}

			$alternation = implode( '|', $quoted_srcs );

			// Bounded lazy quantifiers cap backtracking; tag body bound of
			// 2000 and query-string bound of 500 far exceed real <link> tags.
			// Pass 1: href-first (<link ... href="URL" ... rel="stylesheet" ...>).
			$pattern_href_first = '/<link[^>]{0,2000}?href\s*=\s*[\'"](?:' . $alternation . ')(?:\?[^\'"]{0,500})?[\'"][^>]{0,2000}?rel\s*=\s*[\'"][^\'"]*stylesheet[^\'"]*[\'"][^>]{0,2000}?\/?>\s*/i';
			// Pass 2: rel-first (<link ... rel="stylesheet" ... href="URL" ...>).
			$pattern_rel_first = '/<link[^>]{0,2000}?rel\s*=\s*[\'"][^\'"]*stylesheet[^\'"]*[\'"][^>]{0,2000}?href\s*=\s*[\'"](?:' . $alternation . ')(?:\?[^\'"]{0,500})?[\'"][^>]{0,2000}?\/?>\s*/i';

			$total_count = 0;

			$count_href_first = 0;
			$after_href_first = preg_replace( $pattern_href_first, '', $buffer, -1, $count_href_first );
			if ( null === $after_href_first ) {
				return null;
			}
			if ( PREG_NO_ERROR !== preg_last_error() ) {
				return null;
			}
			$total_count += $count_href_first;

			$count_rel_first = 0;
			$after_rel_first = preg_replace( $pattern_rel_first, '', $after_href_first, -1, $count_rel_first );
			if ( null === $after_rel_first ) {
				return null;
			}
			if ( PREG_NO_ERROR !== preg_last_error() ) {
				return null;
			}
			$total_count += $count_rel_first;

			return array( $after_rel_first, $total_count );
		}

		/**
		 * Smoke-gate the remove delivery mode (issue #1220).
		 *
		 * Remove mode strips the full stylesheets entirely, so builder pages
		 * (Elementor, Divi, Beaver Builder, Bricks, Oxygen, WPBakery, and
		 * Gutenberg block markers in the stripped buffer) auto-downgrade to
		 * delay mode instead — the full stylesheets then load on interaction
		 * rather than vanishing. Non-builder pages keep remove. Fail-open:
		 * any error keeps the requested mode. The downgrade is logged via the
		 * safe fallback logger when enabled.
		 *
		 * @param string   $buffer_after_strip Buffer with original links stripped.
		 * @param string   $mode               Requested delivery mode.
		 * @param string[] $handles            Stripped stylesheet handles.
		 * @return string Effective delivery mode.
		 * @since 2.2.0
		 */
		private function resolve_effective_delivery_mode( string $buffer_after_strip, string $mode, array $handles ): string {
			try {
				if ( 'remove' !== $mode ) {
					return $mode;
				}
				$builder_markers = array(
					'data-elementor-type',
					'elementor-widget',
					'elementor-popup',
					'et_pb_',
					'et-pb-',
					'fl-builder',
					'fl-row',
					'bricks-',
					'brxe-',
					'oxy-',
					'oxygen-',
					'wpb_',
					'vc_row',
					'wp-block-',
				);
				$has_builder     = false;
				foreach ( $builder_markers as $marker ) {
					if ( false !== strpos( $buffer_after_strip, $marker ) ) {
						$has_builder = true;
						break;
					}
				}
				if ( ! $has_builder ) {
					return 'remove';
				}
				try {
					if ( $this->is_safe_fallback_enabled() ) {
						$this->log_used_css_fallback( 'remove_downgraded_to_delay', $handles );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return 'delay';
			} catch ( \Throwable $e ) {
				unset( $e );
				return $mode;
			}
		}

		/**
		 * Inline loader that swaps interaction-delayed stylesheets (issue #1220).
		 *
		 * Converts `link[data-wppo-delayed-css]` preloads to stylesheets on
		 * first interaction (pointer/key/touch/scroll) with a 5s backstop so
		 * the full styles always arrive. Tiny, dependency-free, fail-open.
		 *
		 * @return string Inline script tag.
		 * @since 2.2.0
		 */
		private function build_delayed_css_loader(): string {
			// Audit #1325: pagehide/beforeunload clears the 5s backstop (mirrors
			// the critical-css loader), and mouseover joins the trigger set to
			// match the lazyload interaction contract (hover users flush early
			// instead of riding the backstop).
			return '<script data-wppo-delayed-css-loader="1">(function(){var d=false,t=null;function l(){if(d){return;}d=true;if(t!==null){clearTimeout(t);t=null;}var a=document.querySelectorAll(\'link[data-wppo-delayed-css]\');for(var i=0;i<a.length;i++){try{a[i].rel=\'stylesheet\';a[i].media=a[i].getAttribute(\'data-wppo-delayed-media\')||\'all\';a[i].removeAttribute(\'data-wppo-delayed-css\');}catch(e){}}};function b(){l();window.removeEventListener(\'pointerdown\',b);window.removeEventListener(\'keydown\',b);window.removeEventListener(\'touchstart\',b);window.removeEventListener(\'scroll\',b);window.removeEventListener(\'mouseover\',b);}function p(){if(t!==null){clearTimeout(t);t=null;}}window.addEventListener(\'pointerdown\',b,{passive:true});window.addEventListener(\'keydown\',b);window.addEventListener(\'touchstart\',b,{passive:true});window.addEventListener(\'scroll\',b,{passive:true});window.addEventListener(\'mouseover\',b,{passive:true});window.addEventListener(\'pagehide\',p);window.addEventListener(\'beforeunload\',p);t=setTimeout(l,5000);})();</script>';
		}

		/**
		 * Registered stylesheet URL with its version query (issue #1220).
		 *
		 * Delay-mode preloads and the noscript fallback must preserve the
		 * `ver` query so versioned handles keep cache-busting parity with
		 * the original <link> tags. Fail-open: missing registration or
		 * source returns ''.
		 *
		 * @param string $handle Style handle.
		 * @return string Full URL with ver query, or '' when unresolvable.
		 * @since 2.2.0
		 */
		private function get_handle_url_with_ver( string $handle ): string {
			try {
				global $wp_styles;
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					return '';
				}
				$src = (string) ( $wp_styles->registered[ $handle ]->src ?? '' );
				if ( '' === $src ) {
					return '';
				}
				$ver = isset( $wp_styles->registered[ $handle ]->ver ) ? (string) $wp_styles->registered[ $handle ]->ver : '';
				if ( '' === $ver ) {
					return $src;
				}
				return $src . ( false === strpos( $src, '?' ) ? '?' : '&' ) . 'ver=' . $ver;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Registered stylesheet media (issue #1220).
		 *
		 * Preserves per-handle media (e.g. print-only handles) in delay
		 * mode instead of forcing `all`. Fail-open to 'all'.
		 *
		 * @param string $handle Style handle.
		 * @return string Media attribute value.
		 * @since 2.2.0
		 */
		private function get_handle_media( string $handle ): string {
			try {
				global $wp_styles;
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					return 'all';
				}
				$media = $wp_styles->registered[ $handle ]->args ?? 'all';
				if ( is_string( $media ) && '' !== $media ) {
					return $media;
				}
				return 'all';
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'all';
			}
		}

		/**
		 * Whether the response carries a strict CSP blocking inline handlers (issue #1220).
		 *
		 * The async delivery mode swaps via an inline `onload` attribute and
		 * the delay mode via an inline loader script, both of which a
		 * `Content-Security-Policy` without 'unsafe-inline' blocks — leaving
		 * stylesheets never applied. Checks both the `headers_list()` response
		 * headers and a `<meta http-equiv="Content-Security-Policy">` tag in
		 * the (already stripped) buffer. Fail-open: any uncertainty returns false.
		 *
		 * @param string $buffer Optional HTML buffer to scan for a meta CSP tag.
		 * @return bool True when a strict CSP is detected.
		 * @since 2.2.0
		 */
		private function has_strict_csp( string $buffer = '' ): bool {
			try {
				if ( function_exists( 'headers_list' ) ) {
					foreach ( headers_list() as $header ) {
						if ( 0 !== stripos( (string) $header, 'content-security-policy' ) ) {
							continue;
						}
						if ( false === stripos( (string) $header, 'unsafe-inline' ) ) {
							return true;
						}
					}
				}
				if ( '' !== $buffer && false !== stripos( $buffer, 'http-equiv' ) && false !== stripos( $buffer, 'content-security-policy' ) ) {
					if ( 1 === preg_match( '/<meta[^>]+http-equiv\s*=\s*["\']?\s*content-security-policy\s*["\']?[^>]*>/i', $buffer, $matches ) ) {
						if ( false === stripos( $matches[0], 'unsafe-inline' ) ) {
							return true;
						}
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Inject used-CSS into the buffer: remove original <link> stylesheets
		 * and insert the used-CSS file with a <noscript> fallback.
		 *
		 * Delivery modes (issue #1220): file renders a blocking link;
		 * async uses preload + media swap; delay keeps the blocking used-CSS
		 * and interaction-gates the full stylesheets; remove strips the full
		 * stylesheets (smoke-gated with auto-downgrade to delay on builder
		 * pages). Misses never reach here stripped — callers return the full
		 * buffer untouched — so file and delay never serve unstyled pages.
		 *
		 * @param string $buffer       The HTML buffer.
		 * @param string $used_css_url The URL of the used-CSS file (with version).
		 * @param array  $handles      Array of style handles to remove and include in fallback.
		 * @return string Modified HTML buffer.
		 * @since 1.9.0
		 * @since 2.2.0 Added file/delay/async/remove delivery modes.
		 */
		private function inject_used_css( string $buffer, string $used_css_url, array $handles ): string {
			global $wp_styles;

			$original_buffer = $buffer;

			// Strict match guards: never strip until replacement is confirmed.
			if ( '' === trim( $used_css_url ) || empty( $handles ) ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'invalid_payload', $handles );
				}
				return $original_buffer;
			}

			// Build the set of URLs to remove, including minified variants.
			// Block-library FOUC guard (issue #1164): core block styles are
			// never stripped — their <link> stays so blocks never flash
			// unstyled even when a used-CSS sidecar is injected.
			$removal_urls = array();
			foreach ( $handles as $handle ) {
				if ( ! self::is_safe_to_strip_handle( (string) $handle ) ) {
					continue;
				}
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}
				$src = $wp_styles->registered[ $handle ]->src;
				if ( empty( $src ) ) {
					continue;
				}
				$removal_urls[ $src ] = true;

				// When minifyCSS is enabled, the href may have been rewritten to
				// a minified URL. Include that URL in the removal pattern too.
				$file_opts = $this->options['file_optimisation'] ?? array();
				if ( ! empty( $file_opts['minifyCSS'] ) ) {
					$local_path = Util::get_local_path( $src );
					if ( ! empty( $local_path ) ) {
						$min_file = Util::min_cache_dir( 'css' ) . '/' . basename( $local_path );
						if ( file_exists( $min_file ) ) {
							$removal_urls[ Util::min_cache_url( 'css', basename( $min_file ) ) ] = true;
						}
					}
				}
			}

			if ( empty( $removal_urls ) ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'no_handles', $handles );
				}
				return $original_buffer;
			}

			// Remove original <link> tags via two-pass order-agnostic bounded
			// regex (href-first then rel-first) with a backtrack guard.
			// Note: On WP 6.9+, small block styles inlined as <style id="wp-block-*-inline-css">
			// blocks (added when a block renders) are intentionally left in place; only
			// <link> tags are stripped. This is a pre-existing limitation, not a regression.
			$quoted_srcs = array();
			foreach ( array_keys( $removal_urls ) as $url ) {
				$quoted_srcs[] = preg_quote( $url, '/' );
			}
			$strip_result = $this->strip_stylesheet_links( $buffer, $quoted_srcs );

			if ( null === $strip_result ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'preg_error', $handles );
				}
				return $original_buffer;
			}

			list( $stripped, $strip_count ) = $strip_result;

			if ( $this->is_safe_fallback_enabled() && 0 === $strip_count ) {
				$this->log_used_css_fallback( 'no_match', $handles );
				return $original_buffer;
			}

			$buffer_after_strip = $stripped;

			// Delivery modes (issue #1220): file (default, render-blocking),
			// async (preload + media swap), delay (used-CSS blocking, full
			// stylesheets load on first interaction), remove (full stylesheets
			// stripped, smoke-gated with auto-downgrade to delay). On any miss
			// the caller already returned the full buffer untouched, so file and
			// delay modes never serve unstyled pages.
			$file_opts_for_mode = $this->options['file_optimisation'] ?? array();
			$delivery_mode      = self::get_used_css_delivery_mode( is_array( $file_opts_for_mode ) ? $file_opts_for_mode : array() );
			$delivery_mode      = $this->resolve_effective_delivery_mode( $buffer_after_strip, $delivery_mode, $handles );
			// has_strict_csp() only inspects headers_list() plus a meta tag in
			// the buffer, so a CSP enforced at the server/edge layer (htaccess,
			// Nginx, hosting headers) is invisible to it. Hosts with such a
			// server-level CSP can force the file fallback via this filter.
			// The delayed loader stays a raw inline script by design (a nonce
			// must not be baked into cached HTML), so under a strict CSP it
			// would be blocked and delay-mode stylesheets would stay
			// never-applied preloads — hence the downgrade to blocking file
			// mode instead (fail-open, never unstyled).
			/**
			 * Filters whether a server/edge-level strict CSP blocks inline scripts.
			 *
			 * Return true when a Content-Security-Policy without 'unsafe-inline'
			 * is enforced outside PHP (htaccess/Nginx/hosting headers), which
			 * has_strict_csp() cannot detect. Forces async/delay delivery modes
			 * to downgrade to the blocking file mode.
			 *
			 * @since 2.2.0
			 * @param bool $strict_csp Whether a server-level strict CSP is active.
			 */
			$server_strict_csp = (bool) apply_filters( 'wppo_used_css_strict_csp', false );
			if ( in_array( $delivery_mode, array( 'async', 'delay' ), true ) && ( $server_strict_csp || $this->has_strict_csp( $buffer_after_strip ) ) ) {
				// Strict Content-Security-Policy (no 'unsafe-inline') blocks
				// the async onload swap and the delay inline loader below,
				// leaving stylesheets never applied — downgrade to the
				// blocking file mode instead (fail-open, never unstyled).
				// Logged via the safe fallback logger.
				try {
					if ( $this->is_safe_fallback_enabled() ) {
						$this->log_used_css_fallback( $delivery_mode . '_downgraded_to_file_csp', $handles );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$delivery_mode = 'file';
			}

			// Build used-CSS link + noscript fallback.
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			$used_css_tag = '<link id="wppo-used-css" rel="stylesheet" href="' . esc_url( $used_css_url ) . '" media="all">';
			if ( 'async' === $delivery_mode ) {
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
				$used_css_tag = '<link id="wppo-used-css" rel="preload" as="style" href="' . esc_url( $used_css_url ) . '" onload="this.onload=null;this.rel=\'stylesheet\';this.media=\'all\'" data-wppo-used-css-async="1">';
			}

			$noscript_fallback = '';
			foreach ( $handles as $handle ) {
				if ( isset( $wp_styles->registered[ $handle ] ) ) {
					$original_url = $this->get_handle_url_with_ver( (string) $handle );
					$media_attr   = $this->get_handle_media( (string) $handle );
					if ( ! empty( $original_url ) ) {
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
						$noscript_fallback .= '<link rel="stylesheet" href="' . esc_url( $original_url ) . '" media="' . esc_attr( $media_attr ) . '">' . "\n";
					}
				}
			}

			if ( 'async' === $delivery_mode ) {
				// No-JS clients must still get styled content: noscript carries
				// both the used-CSS and the full originals.
				// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
				$used_css_tag .= "\n" . '<noscript><link id="wppo-used-css-noscript" rel="stylesheet" href="' . esc_url( $used_css_url ) . '" media="all">' . $noscript_fallback . '</noscript>';
			} else {
				$used_css_tag .= "\n" . '<noscript>' . $noscript_fallback . '</noscript>';
			}

			if ( 'delay' === $delivery_mode ) {
				// Interaction-gated full stylesheets: the blocking used-CSS above
				// keeps the page styled, so delaying the remainder can never
				// unstyle the page. Preloads + inline loader swap them to
				// stylesheets on first interaction (with a timeout backstop).
				$delayed = '';
				foreach ( $handles as $handle ) {
					if ( isset( $wp_styles->registered[ $handle ] ) ) {
						$original_url = $this->get_handle_url_with_ver( (string) $handle );
						$media_attr   = $this->get_handle_media( (string) $handle );
						if ( ! empty( $original_url ) ) {
							// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
							$delayed .= '<link rel="preload" as="style" href="' . esc_url( $original_url ) . '" media="' . esc_attr( $media_attr ) . '" data-wppo-delayed-css="1" data-wppo-delayed-media="' . esc_attr( $media_attr ) . '">' . "\n";
						}
					}
				}
				if ( '' !== $delayed ) {
					$used_css_tag .= "\n" . $delayed . $this->build_delayed_css_loader();
				}
			}

			// Strict head injection: prefer </head>, fallback to <head> injection,
			// fail-open to originals when neither is present. Only the FIRST
			// </head> is targeted so the tag is never injected multiple times.
			$out      = null;
			$head_pos = stripos( $buffer_after_strip, '</head>' );
			if ( false !== $head_pos ) {
				$out = substr_replace( $buffer_after_strip, $used_css_tag . "\n</head>", $head_pos, strlen( '</head>' ) );
			} else {
				$head_count = 0;
				// preg_replace_callback avoids any $-backreference interpretation
				// of the (esc_url'd) URL inside $used_css_tag.
				$out = preg_replace_callback(
					'/<head(\s[^>]*)?>/i',
					static function ( $m ) use ( $used_css_tag ) {
						return $m[0] . $used_css_tag . "\n";
					},
					$buffer_after_strip,
					1,
					$head_count
				);
				if ( null === $out ) {
					if ( $this->is_safe_fallback_enabled() ) {
						$this->log_used_css_fallback( 'preg_error_head', $handles );
					}
					return $original_buffer;
				}
				if ( 0 === $head_count ) {
					if ( $this->is_safe_fallback_enabled() ) {
						$this->log_used_css_fallback( 'head_match_failure', $handles );
					}
					return $original_buffer;
				}
			}

			if ( false === strpos( $out, 'wppo-used-css' ) ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'inject_failed', $handles );
				}
				return $original_buffer;
			}

			return $out;
		}

		/**
		 * Whether the visual regression guard is enabled (issue #966).
		 *
		 * Safe-by-default on; a missing key backfills to on so old installs
		 * get the fail-open guard without a storage migration (per-site
		 * settings, multisite-safe).
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		public function is_regression_guard_enabled(): bool {
			$file_opts = $this->options['file_optimisation'] ?? array();
			if ( ! array_key_exists( 'unusedCSSRegressionGuard', $file_opts ) ) {
				return true;
			}
			return ! empty( $file_opts['unusedCSSRegressionGuard'] );
		}

		/**
		 * Retained-% threshold below which trimming is deemed a mismatch.
		 *
		 * Clamped to 5-50; unrecognized values fail safe to 20.
		 *
		 * @since 2.0.0
		 * @return int
		 */
		public function get_regression_threshold(): int {
			$file_opts = $this->options['file_optimisation'] ?? array();
			$raw       = $file_opts['unusedCSSRegressionThreshold'] ?? 20;
			$threshold = is_numeric( $raw ) ? (int) $raw : 20;
			if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_unused_css_regression_threshold' ) ) {
				$threshold = (int) apply_filters( 'wppo_unused_css_regression_threshold', $threshold );
			}
			if ( $threshold < 5 || $threshold > 50 ) {
				return 20;
			}
			return $threshold;
		}

		/**
		 * Whether purged output trips the visual regression guard.
		 *
		 * Trips when the retained-bytes ratio falls below the configured
		 * threshold, or when a large input (>10 KB) purges to <1 KB. Empty
		 * inputs never trip (callers handle empties separately).
		 *
		 * @since 2.0.0
		 *
		 * @param string $combined_css Full combined stylesheet.
		 * @param string $purged_css   Purged output.
		 * @return bool True when the guard trips (serve the full stylesheet).
		 */
		public function is_regression_guard_tripped( string $combined_css, string $purged_css ): bool {
			if ( ! $this->is_regression_guard_enabled() ) {
				return false;
			}
			$input_len = strlen( $combined_css );
			if ( 0 === $input_len ) {
				return false;
			}
			if ( '' === trim( $purged_css ) ) {
				return true;
			}
			$output_len = strlen( $purged_css );
			if ( $input_len > 10240 && $output_len < 1024 ) {
				return true;
			}
			$retained = ( $output_len / $input_len ) * 100;
			return $retained < $this->get_regression_threshold();
		}

		/**
		 * Whether the safe CSS combine fallback is enabled.
		 *
		 * @since 2.0.0
		 * @return bool
		 */
		private function is_safe_fallback_enabled(): bool {
			return Util::safe_css_fallback_enabled();
		}

		/**
		 * Log a used-CSS fallback with throttling.
		 *
		 * Delegates to {@see Util::log_css_fallback()} with the 'usedcss' context;
		 * per-reason transient throttling (DAY_IN_SECONDS) prevents the log from
		 * growing per pageview on persistent failures.
		 *
		 * @since 2.0.0
		 * @param string $reason  Reason code.
		 * @param array  $handles Handles involved.
		 * @return void
		 */
		private function log_used_css_fallback( string $reason, array $handles ): void {
			Util::log_css_fallback( $reason, $handles, 'usedcss' );
		}

		/**
		 * Whether a used-CSS file is valid.
		 *
		 * @since 2.0.0
		 * @param string $path Absolute path.
		 * @return bool
		 */
		private function is_used_css_valid( string $path ): bool {
			return Util::css_file_valid( $path );
		}

		/**
		 * Process the HTML buffer to apply used-CSS.
		 *
		 * Checks for a cached used-CSS file first to avoid re-parsing on every
		 * page load. If cached and fresh, injects directly. Otherwise generates
		 * purged CSS, persists it, and injects.
		 *
		 * @param string $buffer The HTML buffer.
		 * @return string Modified HTML buffer.
		 * @since 1.9.0
		 */
		public function process_buffer( string $buffer ): string {
			// Skip processing during background used-CSS regeneration requests to prevent infinite loops.
			if ( isset( $_SERVER['HTTP_X_WPPO_USED_CSS'] ) ) {
				return $buffer;
			}

			// Feature flag first: skip all Woo/DONOTCACHEPAGE detection work
			// (REQUEST_URI parsing, WC conditionals) when remove-unused-CSS is
			// off. The Woo early return below intentionally reuses Main's delay
			// guard (same slug list + endpoint semantics) so checkout keeps full
			// styles; blast radius: future delay-only changes also alter CSS
			// purging on Woo-dynamic pages (fail-safe direction).
			$file_opts = $this->options['file_optimisation'] ?? array();

			if ( empty( $file_opts['removeUnusedCSS'] ) ) {
				return $buffer;
			}

			// Unified safe-mode kill switch (issue #1098): one-click recovery
			// that preserves the removeUnusedCSS setting. Fail open to the
			// full stylesheet.
			if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_safe_mode_active' ) ) {
				try {
					if ( Main::is_safe_mode_active( $file_opts ) ) {
						return $buffer;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $buffer;
				}
			}
			// Shared nocache bypass (issue #1098): ?nocache / DONOTCACHEPAGE /
			// preview skips trimming, never fatal.
			if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_aggressive_bypass_active' ) ) {
				try {
					if ( Main::is_aggressive_bypass_active() ) {
						return $buffer;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $buffer;
				}
			}

			// WooCommerce dynamic pages (issue #962): cart / checkout /
			// account, Store API, and endpoints stay excluded from
			// remove-unused-CSS so checkout keeps full styles.
			// DONOTCACHEPAGE pages opt out too.
			if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
				return $buffer;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_delay_excluded_context' ) ) {
				try {
					if ( Main::is_delay_excluded_context() ) {
						return $buffer;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $buffer;
				}
			}
			// Per-URL + per-page used-CSS disable (#988): skip purging on listed
			// URLs or when `_wppo_used_css_disabled` is set, without disabling
			// the plugin. Fail-open: a matcher error returns the unoptimised
			// buffer (same direction as the delay-guard block above) instead of
			// falling through to purge CSS.
			if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_used_css_excluded_for_url' ) ) {
				try {
					if ( Main::is_used_css_excluded_for_url() ) {
						return $buffer;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $buffer;
				}
			}

			global $wp_styles;
			$current_url   = Util::cached_home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );
			$used_css_path = $this->get_used_css_path( $current_url );
			$used_css_url  = $this->get_used_css_url( $current_url );

			// Cache-read shortcut: if used-CSS exists and source files are not newer, inject directly.
			if ( file_exists( $used_css_path ) ) {
				// Strict guard: empty/unreadable sidecar must not be injected.
				if ( $this->is_safe_fallback_enabled() && ! $this->is_used_css_valid( $used_css_path ) ) {
					$this->log_used_css_fallback( 'invalid_cached_file', array() );
				} else {
					$used_css_mtime = filemtime( $used_css_path );
					$fresh          = ( false !== $used_css_mtime );

					if ( ! $fresh ) {
						// The sidecar vanished between the exists() check and
						// now — treat as stale and fall through to regeneration.
						if ( $this->is_safe_fallback_enabled() ) {
							$this->log_used_css_fallback( 'mtime_failed', array() );
						}
					} elseif ( $wp_styles && ! empty( $wp_styles->queue ) ) {
						foreach ( $wp_styles->queue as $handle ) {
							if ( isset( $wp_styles->registered[ $handle ] ) ) {
								$src = $wp_styles->registered[ $handle ]->src;
								if ( ! empty( $src ) ) {
									$local_path = Util::get_local_path( $src );
									if ( '' !== $local_path && file_exists( $local_path ) && filemtime( $local_path ) > $used_css_mtime ) {
										$fresh = false;
										break;
									}
								}
							}
						}
					}

					// Checksum freshness (issue #1038): content edits that
					// preserve mtime (deploy sync, same-second minify
					// rebuild) still trigger regeneration. Local file reads
					// only — never fetches remotely. Fail-open: no checksum
					// signal keeps the mtime verdict.
					if ( $fresh && $this->is_checksum_stale( $used_css_path ) ) {
						$fresh = false;
					}

					if ( $fresh ) {
						// Defense in depth: re-verify payload before strip/inject.
						if ( $this->is_safe_fallback_enabled() && ! $this->is_used_css_valid( $used_css_path ) ) {
							$this->log_used_css_fallback( 'empty_cached_payload', array() );
						} else {
							$used_css_url = $used_css_url . '?ver=' . $used_css_mtime;

							$handles = array();
							if ( $wp_styles && ! empty( $wp_styles->queue ) ) {
								foreach ( $wp_styles->queue as $handle ) {
									if ( isset( $wp_styles->registered[ $handle ] ) && ! empty( $wp_styles->registered[ $handle ]->src ) ) {
										$handles[] = $handle;
									}
								}
							}
							return $this->inject_used_css( $buffer, $used_css_url, $handles );
						}
					}
				}
			}

			$css_assets = $this->get_all_css_assets();
			if ( empty( $css_assets ) ) {
				return $buffer;
			}

			$purged_css = $this->generate_used_css( $buffer, $css_assets );
			if ( empty( $purged_css ) ) {
				return $buffer;
			}

			// Elementor smoke guard (issue #1164): builder pages whose purged
			// CSS lost builder coverage keep the full stylesheet (no FOUC).
			if ( ! self::passes_elementor_smoke( $buffer, $purged_css ) ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'elementor_smoke', array_keys( $css_assets ) );
				}
				return $buffer;
			}

			$saved = $this->save_used_css( $purged_css, $current_url );
			if ( $saved ) {
				// Baseline the source checksum so later requests detect
				// content edits that preserve mtime (issue #1038). Local
				// writes only — never fetches remotely; failures fall back
				// to mtime freshness silently.
				$this->persist_source_checksum( $used_css_path );
			}
			if ( ! $saved ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'save_failed', array_keys( $css_assets ) );
				}
				return $buffer;
			}

			// Verify written file before stripping originals.
			if ( $this->is_safe_fallback_enabled() && ! $this->is_used_css_valid( $used_css_path ) ) {
				$this->log_used_css_fallback( 'write_failure', array_keys( $css_assets ) );
				return $buffer;
			}

			$ver = filemtime( $used_css_path );
			if ( false === $ver ) {
				if ( $this->is_safe_fallback_enabled() ) {
					$this->log_used_css_fallback( 'mtime_failed', array_keys( $css_assets ) );
				}
				return $buffer;
			}
			$used_css_url = $used_css_url . '?ver=' . $ver;

			$handles = array_keys( $css_assets );
			return $this->inject_used_css( $buffer, $used_css_url, $handles );
		}
	}
}
