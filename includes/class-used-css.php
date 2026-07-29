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
		 * @var string
		 * @since 1.9.0
		 */
		private string $domain;

		/**
		 * Constructor.
		 *
		 * @param array $options Plugin options.
		 * @since 1.9.0
		 */
		public function __construct( array $options = array() ) {
			$this->options = ! empty( $options ) ? $options : get_option( 'wppo_settings', array() );

			$domain = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';

			if ( function_exists( 'idn_to_ascii' ) ) {
				$converted = idn_to_ascii( $domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( false !== $converted ) {
					$domain = $converted;
				}
			}

			$host = explode( ':', $domain, 2 )[0];
			if ( ! preg_match( '/^[a-z0-9\.\-]+$/i', $host ) ) {
				$host = str_replace( array( '..', '/', '\\' ), '', $host );
			}
			$this->domain = $host;

			$this->cache_root_dir = wp_normalize_path( WP_CONTENT_DIR . self::CACHE_ROOT_DIR );
			$this->cache_root_url = WP_CONTENT_URL . self::CACHE_ROOT_DIR;

			$this->init_safelist();
		}

		/**
		 * Initialize safelist from settings and built-in list.
		 *
		 * @return void
		 * @since 1.9.0
		 */
		private function init_safelist(): void {
			$file_opts = $this->options['file_optimisation'] ?? array();
			$user_list = array();

			if ( ! empty( $file_opts['excludeUnusedCSS'] ) ) {
				$user_list = Util::process_urls( $file_opts['excludeUnusedCSS'] );
			}

			$this->safelist = array_merge( $this->built_in_safelist, $user_list );
			$this->safelist = array_unique( array_filter( $this->safelist ) );
		}

		/**
		 * Extract used selectors from HTML content.
		 *
		 * @param string $html The HTML content.
		 * @return array{tags: array, classes: array, ids: array, attrs: array}
		 * @since 1.9.0
		 */
		public function extract_selectors( string $html ): array {
			$used = array(
				'tags'    => array(),
				'classes' => array(),
				'ids'     => array(),
				'attrs'   => array(),
			);

			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				return $used;
			}

			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag() ) {
				$tag_name                  = strtolower( $tags->get_tag() );
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

				$attributes = array( 'type', 'rel', 'role', 'href', 'src', 'data-' );
				foreach ( $attributes as $attr_name ) {
					$val = $tags->get_attribute( $attr_name );
					if ( null !== $val ) {
						$used['attrs'][ $attr_name ] = true;

						if ( 'href' === $attr_name || 'src' === $attr_name ) {
							$ext = strtolower( pathinfo( wp_parse_url( (string) $val, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
							if ( $ext && in_array( $ext, array( 'css', 'js', 'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif', 'ico', 'woff', 'woff2', 'ttf', 'eot' ), true ) ) {
								$used['attrs'][ '.' . $ext ] = true;
							}
						}
					}
				}
			}

			return $used;
		}

		/**
		 * Parse CSS content into structured rules.
		 *
		 * @param string $css Raw CSS content.
		 * @return array Parsed rules.
		 * @since 1.9.0
		 */
		public function parse_css( string $css ): array {
			$css = preg_replace( '/\/\*.*?\*\//s', '', $css );

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
		 * Parse CSS rules inside a block (e.g., @media).
		 *
		 * @param string $css Block content.
		 * @return array Parsed child rules.
		 * @since 1.9.0
		 */
		private function parse_css_block_rules( string $css ): array {
			$rules  = array();
			$css    = trim( $css );
			$length = strlen( $css );
			$offset = 0;

			while ( $offset < $length ) {
				$brace_pos = strpos( $css, '{', $offset );
				if ( false === $brace_pos ) {
					break;
				}

				$selector = trim( substr( $css, $offset, $brace_pos - $offset ) );
				if ( '' === $selector ) {
					++$offset;
					continue;
				}

				$brace_depth = 1;
				$pos         = $brace_pos + 1;

				while ( $pos < $length && $brace_depth > 0 ) {
					if ( '{' === $css[ $pos ] ) {
						++$brace_depth;
					} elseif ( '}' === $css[ $pos ] ) {
						--$brace_depth;
					}
					++$pos;
				}

				$declaration = trim( substr( $css, $brace_pos + 1, $pos - $brace_pos - 2 ) );
				$original    = substr( $css, $offset, $pos - $offset );
				$original    = trim( $original );

				if ( '' !== $selector && '' !== $original ) {
					$rules[] = array(
						'type'        => 'rule',
						'selector'    => $selector,
						'selectors'   => $this->split_selectors( $selector ),
						'declaration' => $declaration,
						'original'    => $original,
					);
				}

				$offset = $pos;
			}

			return $rules;
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
				if ( substr( $safe, -1 ) === '-' && 0 === strpos( $selector, $safe ) ) {
					return true;
				}
				if ( substr( $safe, -1 ) === '*' && 0 === strpos( $selector, substr( $safe, 0, -1 ) ) ) {
					return true;
				}
			}

			$simple_selectors = $this->extract_simple_selectors( $selector );

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
			$parts = preg_split( '/\s*[\s>+~]\s*/', $selector );
			return array_values( array_filter( array_map( 'trim', $parts ) ) );
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
				$simple = preg_replace( '/::?[a-z\-]+(?:\([^()]*\))?/', '', $simple );
				$simple = trim( $simple );
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
				if ( 0 === strpos( $simple, '[data-' ) ) {
					return true;
				}
				$attr_end = strpos( $simple, ']' );
				if ( false !== $attr_end ) {
					$attr_content = substr( $simple, 1, $attr_end - 1 );
					$attr_name    = preg_replace( '/[=~|^$*].*/', '', $attr_content );
					$attr_name    = trim( $attr_name );
					return isset( $used['attrs'][ $attr_name ] );
				}
				return false;
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
					$kept_children = array();
					foreach ( $rule['children'] as $child ) {
						if ( $this->is_rule_used( $child, $used ) ) {
							$kept_children[] = $child['original'];
						}
					}

					if ( ! empty( $kept_children ) ) {
						$output .= $rule['at_rule'] . '{' . "\n";
						foreach ( $kept_children as $child_css ) {
							$output .= $child_css . "\n";
						}
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
		 * @param string $html       The page HTML.
		 * @param array  $css_assets Array of CSS content strings (keyed by handle).
		 * @return string Purged CSS content.
		 * @since 1.9.0
		 */
		public function generate_used_css( string $html, array $css_assets ): string {
			if ( empty( $html ) || empty( $css_assets ) ) {
				return '';
			}

			$used_selectors = $this->extract_selectors( $html );

			$combined_css = '';
			foreach ( $css_assets as $css_content ) {
				$combined_css .= $css_content . "\n";
			}

			$parsed = $this->parse_css( $combined_css );

			return $this->purge_css( $parsed, $used_selectors );
		}

		/**
		 * Get the used-CSS cache file path for a URL.
		 *
		 * @param string $url The page URL.
		 * @return string The filesystem path.
		 * @since 1.9.0
		 */
		public function get_used_css_path( string $url = '' ): string {
			$path = $this->get_url_path( $url );
			return "{$this->cache_root_dir}/{$this->domain}/{$path}/used-css.css";
		}

		/**
		 * Get the used-CSS cache file URL for a URL.
		 *
		 * @param string $url The page URL.
		 * @return string The public URL.
		 * @since 1.9.0
		 */
		public function get_used_css_url( string $url = '' ): string {
			$path = $this->get_url_path( $url );
			return "{$this->cache_root_url}/{$this->domain}/{$path}/used-css.css";
		}

		/**
		 * Get the normalized URL path for cache storage.
		 *
		 * @param string $url The page URL.
		 * @return string Normalized path.
		 * @since 1.9.0
		 */
		private function get_url_path( string $url = '' ): string {
			if ( '' === $url ) {
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
				$url_path    = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $request_uri, PHP_URL_PATH ) ), '/' ) );
			} else {
				$url_path = wp_normalize_path( trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' ) );
			}

			if ( strpos( $url_path, '..' ) !== false ) {
				$url_path = '';
			}

			return $url_path;
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

			$file_path = $this->get_used_css_path( $url );
			$dir_path  = dirname( $file_path );

			$fs = Util::init_filesystem();
			if ( ! $fs ) {
				return false;
			}

			if ( ! Util::prepare_cache_dir( $dir_path ) ) {
				return false;
			}

			return $fs->put_contents( $file_path, $css, FS_CHMOD_FILE );
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
				$fs = Util::init_filesystem();
				if ( ! $fs ) {
					return false;
				}
				$file_path = $this->get_used_css_path( $url );
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

			$dirs = $fs->dirlist( $root );
			if ( ! $dirs ) {
				return true;
			}

			$success = true;
			foreach ( $dirs as $entry ) {
				if ( 'd' !== $entry['type'] ) {
					continue;
				}
				$used_path = "{$root}/{$entry['name']}/used-css.css";
				if ( $fs->exists( $used_path ) ) {
					if ( ! $fs->delete( $used_path ) ) {
						$success = false;
					}
				}

				$used_dir = "{$root}/{$entry['name']}";
				$entries2 = $fs->dirlist( $used_dir );
				if ( $entries2 ) {
					foreach ( $entries2 as $entry2 ) {
						if ( 'd' !== $entry2['type'] ) {
							continue;
						}
						$nested = "{$used_dir}/{$entry2['name']}/used-css.css";
						if ( $fs->exists( $nested ) ) {
							if ( ! $fs->delete( $nested ) ) {
								$success = false;
							}
						}
					}
				}
			}

			return $success;
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

			$queued = 0;
			$offset = 0;
			$batch  = 200;

			do {
				$args = array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'fields'         => 'ids',
					'orderby'        => 'ID',
					'order'          => 'ASC',
				);

				$post_ids = get_posts( $args );
				if ( empty( $post_ids ) ) {
					break;
				}

				foreach ( $post_ids as $post_id ) {
					as_enqueue_async_action(
						'wppo_used_css_generate',
						array( 'post_id' => $post_id ),
						'performance_optimisation'
					);
					++$queued;
				}

				$offset   = $offset + $batch;
				$has_more = count( $post_ids ) === $batch;
			} while ( $has_more );

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

			$options    = get_option( 'wppo_settings', array() );
			$used_css   = new self( $options );
			$purged_css = $used_css->generate_used_css( $html, $css_assets );

			if ( ! empty( $purged_css ) ) {
				$used_css->save_used_css( $purged_css, $permalink );
			}
		}

		/**
		 * Extract CSS assets from HTML content by finding <link rel="stylesheet"> tags.
		 *
		 * @param string $html The HTML content.
		 * @return array Array of CSS content strings keyed by md5 hash of URL.
		 * @since 1.9.0
		 */
		private static function extract_css_assets_from_html( string $html ): array {
			$assets = array();
			if ( ! class_exists( '\WP_HTML_Tag_Processor' ) ) {
				return $assets;
			}
			$tags = new \WP_HTML_Tag_Processor( $html );
			while ( $tags->next_tag( 'link' ) ) {
				$rel = $tags->get_attribute( 'rel' );
				if ( 'stylesheet' !== $rel ) {
					continue;
				}
				$href = $tags->get_attribute( 'href' );
				if ( ! $href ) {
					continue;
				}
				$content = self::fetch_css_content_static( $href );
				if ( false !== $content ) {
					$assets[ md5( $href ) ] = $content;
				}
			}
			return $assets;
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

			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
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
		 * Fetch CSS content from a URL or local path.
		 *
		 * @param string $url The CSS URL.
		 * @return string|false CSS content or false on failure.
		 * @since 1.9.0
		 */
		private function fetch_css_content( string $url ) {
			$local_path = Util::get_local_path( $url );
			if ( '' !== $local_path ) {
				$fs = Util::init_filesystem();
				if ( $fs && $fs->exists( $local_path ) ) {
					return $fs->get_contents( $local_path );
				}
			}

			$response = wp_remote_get( $url, array( 'timeout' => 15 ) );
			if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) ) {
				return false;
			}

			return wp_remote_retrieve_body( $response );
		}

		/**
		 * Process the HTML buffer to apply used-CSS.
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

			$file_opts = $this->options['file_optimisation'] ?? array();

			if ( empty( $file_opts['removeUnusedCSS'] ) ) {
				return $buffer;
			}

			$css_assets = $this->get_all_css_assets();
			if ( empty( $css_assets ) ) {
				return $buffer;
			}

			$purged_css = $this->generate_used_css( $buffer, $css_assets );
			if ( empty( $purged_css ) ) {
				return $buffer;
			}

			$current_url = home_url( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '' );

			$saved = $this->save_used_css( $purged_css, $current_url );
			if ( ! $saved ) {
				// Filesystem write failed — return original buffer to avoid unstyled page.
				return $buffer;
			}

			$used_css_path = $this->get_used_css_path( $current_url );
			$used_css_url  = $this->get_used_css_url( $current_url );
			$ver           = file_exists( $used_css_path ) ? filemtime( $used_css_path ) : time();
			$used_css_url  = $used_css_url . '?ver=' . $ver;

			// Only remove <link> tags for styles we have captured.
			global $wp_styles;
			if ( $wp_styles && ! empty( $wp_styles->queue ) ) {
				foreach ( $wp_styles->queue as $handle ) {
					if ( isset( $css_assets[ $handle ] ) && isset( $wp_styles->registered[ $handle ] ) ) {
						$src = $wp_styles->registered[ $handle ]->src;
						if ( ! empty( $src ) ) {
							$quoted_src = preg_quote( $src, '/' );
							$buffer     = preg_replace(
								'/<link[^>]*rel=[\'"]stylesheet[\'"][^>]*href=[\'"]' . $quoted_src . '[\'"][^>]*\/?>\s*/i',
								'',
								$buffer
							);
						}
					}
				}
			}

			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			$used_css_tag = '<link id="wppo-used-css" rel="stylesheet" href="' . esc_url( $used_css_url ) . '" media="all">';

			$noscript_fallback = '';
			foreach ( $css_assets as $handle => $content ) {
				if ( isset( $wp_styles->registered[ $handle ] ) ) {
					$original_url = $wp_styles->registered[ $handle ]->src;
					if ( ! empty( $original_url ) ) {
						// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
						$noscript_fallback .= '<link rel="stylesheet" href="' . esc_url( $original_url ) . '" media="all">' . "\n";
					}
				}
			}

			$used_css_tag .= "\n" . '<noscript>' . $noscript_fallback . '</noscript>';

			$buffer = str_replace( '</head>', $used_css_tag . "\n</head>", $buffer );

			return $buffer;
		}
	}
}
