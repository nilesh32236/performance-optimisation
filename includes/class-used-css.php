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
		 * @since NEXT
		 */
		private bool $host_mismatch = false;

		/**
		 * Whether a traversal probe has been logged this request.
		 *
		 * Rate-limits activity-log writes so a hostile crawler cannot flood
		 * the log table with one entry per request path probe. Mirrors
		 * Cache::$traversal_probe_logged.
		 *
		 * @since NEXT
		 * @var bool
		 */
		private static bool $traversal_probe_logged = false;

		/**
		 * Memoized local-source checksum for this instance (issue #1038).
		 *
		 * The freshness probe runs full-content local reads; memoizing per
		 * instance keeps repeated cache-hit calls to a single capped pass.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
		 */
		public static function get_safelist_presets(): array {
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
		 * @since NEXT Added wppo_used_css_safelist filter (has_filter-guarded).
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
				 * @since NEXT
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
		 * @since NEXT Added WP_HTML_Processor path with Tag Processor fallback.
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * Parse CSS content into structured rules.
		 *
		 * @param string $css Raw CSS content.
		 * @return array Parsed rules.
		 * @since 1.9.0
		 */
		public function parse_css( string $css ): array {
			// Strip CSS comments. Note: this simple regex does not handle
			// string contents (e.g. content: "/* not a comment */") correctly.
			// CSS values containing "/*" inside strings would be incorrectly
			// truncated. This is a known v1 limitation.
			$stripped = preg_replace( '/\/\*.*?\*\//s', '', $css );
			if ( null !== $stripped ) {
				$css = $stripped;
			}

			$rules = array();

			$css = trim( $css );
			if ( '' === $css ) {
				return $rules;
			}

			$offset = 0;
			$length = strlen( $css );

			while ( $offset < $length ) {
				if ( '@' === $css[ $offset ] ) {
					$semicolon_pos = strpos( $css, ';', $offset );
					$at_rule_end   = strpos( $css, '{', $offset );

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

					while ( $pos < $length && $brace_depth > 0 ) {
						if ( '{' === $css[ $pos ] ) {
							++$brace_depth;
						} elseif ( '}' === $css[ $pos ] ) {
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
					$rule_end = strpos( $css, '}', $offset );
					if ( false === $rule_end ) {
						$rule_end = $length;
					} else {
						++$rule_end;
					}

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

			if ( in_array( $selector, $this->safelist, true ) ) {
				return true;
			}

			foreach ( $this->safelist as $safe ) {
				if ( '' !== $safe && '[' === $safe[0] ) {
					// Attribute safelist (e.g. [data-elementor-type]): match by
					// attribute-name substring so compound selectors like
					// div[data-elementor-type] or [data-elementor-type="x"] stay
					// kept. Bare [data-*]/[aria-*] selectors are additionally
					// conserved by matches_simple_selector().
					$attr_name = preg_replace( '/[\]=~|^$*"\'].*$/', '', $safe );
					$attr_name = ltrim( trim( (string) $attr_name ), '[' );
					if ( '' !== $attr_name && false !== stripos( $selector, $attr_name ) ) {
						return true;
					}
					continue;
				}
				$last_char = substr( $safe, -1 );
				if ( '-' === $last_char || '_' === $last_char || '*' === $last_char ) {
					$prefix = '*' === $last_char ? substr( $safe, 0, -1 ) : $safe;
					// A bare '*' entry (the universal selector) is handled by
					// the exact-match check above; it must not act as a
					// match-everything wildcard through an empty prefix, which
					// would silently disable all purging (issue #1038).
					// Non-empty prefixes are safe to match.
					if ( '' !== $prefix ) {
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
						foreach ( $this->extract_simple_selectors( $selector ) as $part ) {
							if ( 0 === strpos( $part, $prefix ) || false !== stripos( $part, $prefix ) ) {
								return true;
							}
						}
					}
				}
			}

			$simple_selectors = $this->extract_simple_selectors( $selector );

			// Conservative OR logic: keep the rule if ANY simple selector part
			// exists in the DOM, to avoid breaking descendant selectors like
			// `.sidebar .widget` when only one side is present. This matches
			// the documented behaviour and avoids false-positive purging.
			// @since NEXT Fixed from AND to OR to match docs.
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
				return Util::sanitize_cache_url_path( (string) $raw );
			}

			return Util::sanitize_cache_url_path( $url );
		}

		/**
		 * Whether the raw path component behind a sanitized-'' result is non-blank.
		 *
		 * Distinguishes the benign homepage (`/`, `''`) from a rejected
		 * hostile input (dot-dot, encoded sequences, null bytes, drive/UNC
		 * prefixes): only the latter counts as a traversal probe. Mirrors
		 * the homepage distinction in Cache::get_file_path().
		 *
		 * @since NEXT
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
		 * @since NEXT
		 * @param string $path Absolute file or directory path.
		 * @return bool True when contained.
		 */
		private function is_path_contained( string $path ): bool {
			// Centralized dual-prefix containment lives in
			// Util::is_cache_path_contained(); this wrapper only binds the
			// per-instance root/domain so every call site shares one audit point.
			return Util::is_cache_path_contained( $this->cache_root_dir, $this->domain, $path );
		}

		/**
		 * Log a blocked used-CSS path traversal probe (once per request).
		 *
		 * Never throws: failures degrade silently to serving unoptimized
		 * output. Mirrors Cache::log_traversal_probe().
		 *
		 * @since NEXT
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
			// @since NEXT Empty-domain refusal.
			if ( '' === $this->domain ) {
				return false;
			}

			// A forged host embedded in the explicit $url (e.g. via a filtered
			// permalink) is not covered by the ambient Host check above: refuse
			// when the URL host is present and differs from the canonical domain.
			// An absolute-looking $url whose host normalizes to '' (invalid host
			// such as 'evil..com') is refused as well instead of being treated
			// as a relative URL; relative URLs carry no host and still pass.
			if ( '' !== $url && '' !== $this->domain && function_exists( 'wp_parse_url' ) ) {
				$url_host_raw = wp_parse_url( $url, PHP_URL_HOST );
				if ( is_string( $url_host_raw ) && '' !== $url_host_raw ) {
					$url_host = Util::normalize_cache_host( $url_host_raw );
					if ( $url_host !== $this->domain ) {
						return false;
					}
				} elseif ( false !== strpos( $url, '://' ) || str_starts_with( ltrim( $url ), '//' ) ) {
					return false;
				}
			}

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

			// Atomic write via the shared tmp+rename helper (unique tmp
			// name, no non-atomic fallback) so interrupted writes never
			// leave partial CSS behind.
			return Util::atomic_file_put_contents( $fs, $file_path, $css );
		}

		/**
		 * Stable content hash of CSS source (issue #1038).
		 *
		 * Pure local string hash — never fetches remotely. Used to detect
		 * stylesheet edits that preserve mtime (deploy sync, minify rebuild
		 * in the same second) so stale used-CSS regenerates. SHA-256 is
		 * stable across installs and salt rotations, matching the `.sha256`
		 * sidecar extension.
		 *
		 * @param string $css CSS content.
		 * @return string SHA-256 checksum, or '' for empty input.
		 * @since NEXT
		 */
		public function compute_css_checksum( string $css ): string {
			if ( '' === $css ) {
				return '';
			}
			return hash( 'sha256', $css );
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
		 * @since NEXT
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
		 * @since NEXT
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
					$size = filesize( $local_path );
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * Delete used-CSS files. If URL is provided, delete per-page; otherwise delete all.
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
				// Drop the checksum sidecar alongside the variant so a
				// re-generation re-baselines instead of comparing against a
				// checksum for deleted output (issue #1038).
				$checksum_path = $this->get_checksum_path( $file_path );
				if ( '' !== $checksum_path && $fs->exists( $checksum_path ) ) {
					$fs->delete( $checksum_path );
				}
				if ( $fs->exists( $file_path ) ) {
					return $fs->delete( $file_path );
				}
				return true;
			}

			return self::delete_all_used_css();
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

			$success   = true;
			$dir_queue = array( $root );
			while ( ! empty( $dir_queue ) ) {
				$current = array_shift( $dir_queue );
				$entries = $fs->dirlist( $current );
				if ( ! is_array( $entries ) ) {
					continue;
				}
				foreach ( $entries as $name => $entry ) {
					$full_path = trailingslashit( $current ) . $name;
					if ( ! empty( $entry['type'] ) && 'd' === $entry['type'] ) {
						$dir_queue[] = $full_path;
					} elseif ( self::USED_CSS_FILENAME === $name || self::USED_CSS_FILENAME . '.sha256' === $name ) {
						if ( ! $fs->delete( $full_path ) ) {
							$success = false;
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
		 * @since NEXT
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
		 * Queue used-CSS regeneration for a single post (builder-drift requeue).
		 *
		 * De-duplicates via as_has_scheduled_action(). No-op when
		 * removeUnusedCSS is off or Action Scheduler is unavailable.
		 *
		 * @param int $post_id Post ID to requeue.
		 * @return bool True when a job is queued or already scheduled.
		 * @since NEXT
		 */
		public static function requeue_for_post( int $post_id ): bool {
			if ( $post_id <= 0 ) {
				return false;
			}
			try {
				$options = Util::get_settings();
				if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
					return false;
				}
				if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
					return false;
				}
				$args = array( 'post_id' => $post_id );
				if ( as_has_scheduled_action( 'wppo_used_css_generate', $args, 'performance_optimisation' ) ) {
					return true;
				}
				as_enqueue_async_action( 'wppo_used_css_generate', $args, 'performance_optimisation' );
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Requeue used CSS when builder assets drifted for a post.
		 *
		 * Compares the newest Elementor CSS mtime
		 * (uploads/elementor/css/post-*.css, global css files) against the
		 * post's used-CSS sidecar mtime; when builder assets are newer, the
		 * used CSS is stale and a regeneration job is queued. Fail-open: any
		 * filesystem failure returns false (full CSS keeps serving).
		 *
		 * @param int $post_id Post ID to check.
		 * @return bool True when drift was detected and a requeue was queued.
		 * @since NEXT
		 */
		public static function maybe_requeue_on_builder_drift( int $post_id ): bool {
			if ( $post_id <= 0 ) {
				return false;
			}
			try {
				$options = Util::get_settings();
				if ( empty( $options['file_optimisation']['removeUnusedCSS'] ) ) {
					return false;
				}
				$permalink = function_exists( 'get_permalink' ) ? get_permalink( $post_id ) : '';
				if ( empty( $permalink ) || ! is_string( $permalink ) ) {
					return false;
				}
				$instance      = new self( $options );
				$used_css_path = $instance->get_used_css_path( (string) $permalink );
				if ( '' === $used_css_path || ! file_exists( $used_css_path ) ) {
					return false;
				}
				$used_mtime = filemtime( $used_css_path );
				if ( false === $used_mtime ) {
					return false;
				}
				$newest_asset = self::get_newest_builder_asset_mtime( $post_id );
				if ( false === $newest_asset || $newest_asset <= $used_mtime ) {
					return false;
				}
				return self::requeue_for_post( $post_id );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Newest builder CSS asset mtime for a post (Elementor post/global CSS).
		 *
		 * @param int $post_id Post ID.
		 * @return int|false Newest mtime, or false when no asset found.
		 * @since NEXT
		 */
		private static function get_newest_builder_asset_mtime( int $post_id ) {
			try {
				if ( ! function_exists( 'wp_upload_dir' ) ) {
					return false;
				}
				$upload = wp_upload_dir();
				if ( ! is_array( $upload ) || empty( $upload['basedir'] ) || ! is_string( $upload['basedir'] ) ) {
					return false;
				}
				$base       = wp_normalize_path( $upload['basedir'] ) . '/elementor/css';
				$candidates = array(
					$base . '/post-' . (int) $post_id . '.css',
					$base . '/global.css',
					$base . '/post-' . (int) $post_id . '.min.css',
				);
				$newest     = false;
				foreach ( $candidates as $file ) {
					if ( file_exists( $file ) ) {
						$mtime = filemtime( $file );
						if ( false !== $mtime && ( false === $newest || $mtime > $newest ) ) {
							$newest = $mtime;
						}
					}
				}
				return $newest;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Queue background used-CSS regeneration for all published posts.
		 *
		 * @return int Number of jobs queued.
		 * @since 1.9.0
		 */
		public function regenerate_all(): int {
			if ( ! function_exists( 'as_enqueue_async_action' ) ) {
				return 0;
			}

			$post_types = get_post_types( array( 'public' => true ), 'names' );
			$post_types = array_diff( $post_types, array( 'attachment' ) );

			$queued  = 0;
			$batch   = 200;
			$last_id = 0;

			if ( empty( $post_types ) ) {
				return 0;
			}

			global $wpdb;
			$placeholders = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );

			// Intentional bypass of WP_Query filters (pre_get_posts, language plugins) for performance:
			// direct $wpdb cursor pagination (ID > last_id) avoids OFFSET cost on large sites. Site-specific
			// filtering must be handled separately if needed.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Intentional direct query, $placeholders is count-derived only.
			do {
				// Cursor pagination via ID > last_id avoids O(offset) MySQL scans.
				$prepare_args   = array_values( $post_types );
				$prepare_args[] = $last_id;
				$prepare_args[] = $batch;
				$post_ids       = $wpdb->get_col(
					// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- $placeholders is count-derived; $prepare_args holds post types + 2 ints via spread.
					$wpdb->prepare(
						"SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($placeholders) AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
						...$prepare_args
					)
				);
				if ( empty( $post_ids ) ) {
					break;
				}

				foreach ( $post_ids as $post_id ) {
					if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'wppo_used_css_generate', array( 'post_id' => (int) $post_id ), 'performance_optimisation' ) ) {
						continue;
					}
					as_enqueue_async_action(
						'wppo_used_css_generate',
						array( 'post_id' => (int) $post_id ),
						'performance_optimisation'
					);
					++$queued;
				}

				$last_id = (int) end( $post_ids );
				// Terminate only when last batch was partial; when total is exact multiple of $batch
				// the next SELECT returns empty and breaks at the top of the loop (one wasted query in that edge case).
				// phpcs:ignore Squiz.PHP.DisallowSizeFunctionsInLoops.Found -- count() on batch is intentional for loop termination.
			} while ( count( $post_ids ) === $batch );
			// phpcs:enable

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
		 * Process a single page for used-CSS generation (Action Scheduler callback).
		 *
		 * @param int $post_id The post ID.
		 * @return void
		 * @since 1.9.0
		 */
		public static function process_background( int $post_id ): void {
			$permalink = get_permalink( $post_id );
			if ( ! $permalink ) {
				return;
			}

			$response = wp_remote_get(
				$permalink,
				array(
					'timeout' => 30,
					'headers' => array(
						'X-WPPO-Used-CSS' => '1',
					),
				)
			);

			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					$error_msg = is_wp_error( $response ) ? $response->get_error_message() : 'HTTP status ' . wp_remote_retrieve_response_code( $response );
					// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
					error_log( 'WPPO used-CSS generation failed for post ' . (int) $post_id . ': ' . sanitize_text_field( $error_msg ) );
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
		 * @since NEXT Added WP_HTML_Processor path with Tag Processor fallback.
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
		 * @since NEXT
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
		 * @since NEXT
		 *
		 * @param \WP_HTML_Tag_Processor $tags   Processor positioned on the current tag.
		 * @param array                  $assets Asset accumulator (passed by reference).
		 * @return void
		 */
		private static function collect_css_asset_from_tag( \WP_HTML_Tag_Processor $tags, array &$assets ): void {
			$rel = $tags->get_attribute( 'rel' );
			if ( 'stylesheet' !== $rel ) {
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
		 * @since NEXT
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
		 * Inject used-CSS into the buffer: remove original <link> stylesheets
		 * and insert the used-CSS file with a <noscript> fallback.
		 *
		 * @param string $buffer       The HTML buffer.
		 * @param string $used_css_url The URL of the used-CSS file (with version).
		 * @param array  $handles      Array of style handles to remove and include in fallback.
		 * @return string Modified HTML buffer.
		 * @since 1.9.0
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
			$removal_urls = array();
			foreach ( $handles as $handle ) {
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

			// Build used-CSS link + noscript fallback.
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			$used_css_tag = '<link id="wppo-used-css" rel="stylesheet" href="' . esc_url( $used_css_url ) . '" media="all">';

			$noscript_fallback = '';
			foreach ( $handles as $handle ) {
				if ( isset( $wp_styles->registered[ $handle ] ) ) {
					$original_url   = $wp_styles->registered[ $handle ]->src;
					$original_media = $wp_styles->registered[ $handle ]->args;
					if ( ! empty( $original_url ) ) {
						$media_attr = ! empty( $original_media ) ? $original_media : 'all';
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
						$noscript_fallback .= '<link rel="stylesheet" href="' . esc_url( $original_url ) . '" media="' . esc_attr( $media_attr ) . '">' . "\n";
					}
				}
			}

			$used_css_tag .= "\n" . '<noscript>' . $noscript_fallback . '</noscript>';

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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
