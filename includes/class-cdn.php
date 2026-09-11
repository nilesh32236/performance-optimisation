<?php
/**
 * CDN rewrite class (LS-410 parity with LSCWP cdn.cls.php).
 *
 * @package PerformanceOptimise\Inc
 * @since NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\CDN' ) ) {
	/**
	 * Class CDN
	 *
	 * Owns CDN mapping resolution, LITESPEED_BYPASS_CDN guard, wildcard2regex,
	 * buffer rewrite and typed hooks (wp_get_attachment_url, srcset, etc.).
	 *
	 * @since NEXT
	 */
	final class CDN {

		/**
		 * Whether buffer already rewritten this request (idempotency guard).
		 *
		 * @var bool
		 */
		private static bool $buffer_rewritten = false;

		/**
		 * Per-request compiled-wildcard memo for wildcard2regex().
		 *
		 * Keyed by the trimmed pattern string; reset via reset_cache().
		 *
		 * @since NEXT
		 * @var array<string, string>
		 */
		private static array $regex_cache = array();

		/**
		 * Per-request memo of the default get_mappings() result, keyed by blog ID.
		 *
		 * Only used when $options is empty (request-stable settings path).
		 * Explicit-$options calls bypass the cache. Reset via reset_cache().
		 * Bypassed entirely when a mapping filter is present so dynamic /
		 * conditional filters are never frozen for the rest of the request.
		 *
		 * @since NEXT
		 * @var array<int|string, array>
		 */
		private static array $mappings_cache = array();

		/**
		 * Whether bypass constant is active.
		 *
		 * Mirrors LSCWP cdn.cls.php:106.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function should_bypass(): bool {
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				return true;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && ! LiteSpeed_Integration::can_apply_cdn() ) {
				return true;
			}
			if ( ! apply_filters( 'wppo_litespeed_can_cdn', true ) ) {
				return true;
			}
			if ( has_filter( 'litespeed_can_cdn' ) && ! apply_filters( 'litespeed_can_cdn', true ) ) {
				return true;
			}
			return false;
		}

		/**
		 * Convert wildcard pattern to regex fragment (LSCWP cdn.cls.php:188).
		 *
		 * `*` => `.*`, escape regex meta elsewhere.
		 *
		 * @since NEXT
		 * @param string $pattern Wildcard pattern.
		 * @return string Regex fragment.
		 */
		public static function wildcard2regex( string $pattern ): string {
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				return '';
			}

			// Per-request memo (audit #874 finding 5): rewrite_buffer() invokes
			// find_cdn_match() per attribute per asset, and each call re-derives
			// the same ori_dir / include_dirs regexes per mapping — a 40-asset
			// page with 3 mappings recompiled the same patterns ~120x. Pure
			// function of $pattern, so no invalidation is needed (except tests:
			// reset_cache()). Mirrors the static $cache pattern of
			// Util::is_url_excluded(); keyed by the trimmed pattern to keep the hot
			// path hash-free.
			if ( array_key_exists( $pattern, self::$regex_cache ) ) {
				return self::$regex_cache[ $pattern ];
			}

			// Escape then unescape * to .*.
			$escaped = preg_quote( $pattern, '#' );
			$escaped = str_replace( '\*', '.*', $escaped );

			self::$regex_cache[ $pattern ] = $escaped;

			return $escaped;
		}

		/**
		 * Get normalized CDN mappings.
		 *
		 * Parses ori/ori_dir/cdn_attr/cdns, legacy cdnURL fallback, auto filetypes, wildcard2regex.
		 *
		 * @since NEXT
		 * @param array $options Optional plugin options override. When empty, loads from DB.
		 * @return array
		 */
		public static function get_mappings( array $options = array() ): array {
			$uses_default = empty( $options );
			$use_cache    = $uses_default && ! has_filter( 'wppo_cdn_mapping' ) && ! has_filter( 'wppo_cdn_mapping_hosts' ) && ! has_filter( 'wppo_cdn_url' ) && ! has_filter( 'wppo_cdn_auto_filetypes' );
			$blog_id      = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( $use_cache && array_key_exists( $blog_id, self::$mappings_cache ) ) {
				return self::$mappings_cache[ $blog_id ];
			}
			if ( $uses_default ) {
				// Always hydrate settings when no explicit options were passed,
				// even when filters are registered (filter-present runs skip the
				// memo but must still read the stored mappings).
				$options = Util::get_settings();
			}
			$mappings = $options['file_optimisation']['cdnMapping'] ?? array();
			if ( is_array( $mappings ) && ! empty( $mappings ) ) {
				$mappings   = array_values( array_filter( $mappings, fn( $m ) => is_array( $m ) && ! empty( $m['cdn_url'] ) ) );
				$normalized = array();
				foreach ( $mappings as $m ) {
					$cdn_url = rtrim( (string) $m['cdn_url'], '/' );
					if ( '' === $cdn_url ) {
						continue;
					}
					// Support cdns array alias (round-robin).
					$cdn_urls = array( $cdn_url );
					if ( ! empty( $m['cdn_urls'] ) && is_array( $m['cdn_urls'] ) ) {
						$cdn_urls = array_values( array_filter( array_map( fn( $u ) => rtrim( trim( (string) $u ), '/' ), $m['cdn_urls'] ) ) );
						if ( empty( $cdn_urls ) ) {
							$cdn_urls = array( $cdn_url );
						}
					} elseif ( ! empty( $m['cdns'] ) && is_array( $m['cdns'] ) ) {
						$cdn_urls = array_values( array_filter( array_map( fn( $u ) => rtrim( trim( (string) $u ), '/' ), $m['cdns'] ) ) );
						if ( empty( $cdn_urls ) ) {
							$cdn_urls = array( $cdn_url );
						}
					}
					$ori               = isset( $m['ori'] ) ? trim( (string) $m['ori'] ) : '';
					$ori_dir           = isset( $m['ori_dir'] ) ? trim( (string) $m['ori_dir'] ) : '';
					$cdn_attr          = isset( $m['cdn_attr'] ) ? trim( (string) $m['cdn_attr'] ) : '';
					$include_dirs      = isset( $m['include_dirs'] ) ? (string) $m['include_dirs'] : 'wp-content|wp-includes';
					$include_filetypes = isset( $m['include_filetypes'] ) ? (string) $m['include_filetypes'] : '';
					// Auto filetypes when empty: default image set (parity cdn.cls.php:86-95) — filterable.
					if ( '' === $include_filetypes ) {
						$default_types = 'jpg,jpeg,png,gif,webp,avif,svg,css,js';
						/**
						 * Filter auto filetypes for CDN mapping with empty include_filetypes.
						 *
						 * @since NEXT
						 * @param string $default_types Default filetypes.
						 * @param array  $m Original mapping entry.
						 */
						$include_filetypes = (string) apply_filters( 'wppo_cdn_auto_filetypes', $default_types, $m );
					}
					$normalized[] = array(
						'cdn_url'           => $cdn_url,
						'cdn_urls'          => $cdn_urls,
						'ori'               => $ori ? rtrim( $ori, '/' ) : '',
						'ori_dir'           => $ori_dir,
						'cdn_attr'          => $cdn_attr,
						'include_dirs'      => $include_dirs,
						'include_filetypes' => $include_filetypes,
					);
				}
				/**
				 * Filter CDN mapping hosts alias (round-robin hosts).
				 *
				 * @since NEXT
				 * @param array $normalized Mappings.
				 */
				$normalized = (array) apply_filters( 'wppo_cdn_mapping_hosts', $normalized );
				/**
				 * Filter CDN mappings before use.
				 *
				 * @since NEXT
				 * @param array $normalized CDN mappings.
				 */
				$normalized = (array) apply_filters( 'wppo_cdn_mapping', $normalized );
				$normalized = array_map( array( self::class, 'precompute_mapping' ), $normalized );
				if ( $use_cache ) {
					self::$mappings_cache[ $blog_id ] = $normalized;
				}
				return $normalized;
			}
			$cdn_url = $options['file_optimisation']['cdnURL'] ?? '';
			if ( empty( $cdn_url ) ) {
				if ( $use_cache ) {
					self::$mappings_cache[ $blog_id ] = array();
				}
				return array();
			}
			$cdn_url = rtrim( (string) $cdn_url, '/' );
			$cdn_url = (string) apply_filters( 'wppo_cdn_url', $cdn_url );
			$single  = array(
				array(
					'cdn_url'           => $cdn_url,
					'cdn_urls'          => array( $cdn_url ),
					'ori'               => '',
					'ori_dir'           => '',
					'cdn_attr'          => '',
					'include_dirs'      => 'wp-content|wp-includes',
					'include_filetypes' => (string) apply_filters( 'wppo_cdn_auto_filetypes', '', array( 'cdn_url' => $cdn_url ) ),
				),
			);
			$single  = (array) apply_filters( 'wppo_cdn_mapping_hosts', $single );
			$single  = (array) apply_filters( 'wppo_cdn_mapping', $single );
			$single  = array_map( array( self::class, 'precompute_mapping' ), $single );
			if ( $use_cache ) {
				self::$mappings_cache[ $blog_id ] = $single;
			}
			return $single;
		}

		/**
		 * Precompute normalized mapping parts once per mapping.
		 *
		 * The find_cdn_match() hot path runs per attribute per asset inside
		 * output-buffer rewrite path; deriving exploded ori_dir /
		 * include_dirs arrays and the lowercased filetype allowlist on every
		 * call repeats identical string surgery ~120x per page. Computed here
		 * once (get_mappings() is already per-request memoized upstream) and
		 * consumed via the `_ori_dir_parts`, `_include_dirs_regex`, and
		 * `_allowed_filetypes` keys, with a raw-mapping fallback in
		 * find_cdn_match() for direct callers.
		 *
		 * @since NEXT
		 * @param mixed $mapping Raw mapping entry.
		 * @return array Mapping with precomputed keys.
		 */
		private static function precompute_mapping( $mapping ): array {
			if ( ! is_array( $mapping ) ) {
				return array();
			}
			$ori_dir                   = isset( $mapping['ori_dir'] ) ? (string) $mapping['ori_dir'] : '';
			$mapping['_ori_dir_parts'] = '' !== $ori_dir ? array_values( array_filter( array_map( 'trim', explode( '|', $ori_dir ) ) ) ) : array();

			$dirs = isset( $mapping['include_dirs'] ) ? (string) $mapping['include_dirs'] : 'wp-content|wp-includes';
			if ( '' !== $dirs ) {
				$dir_parts   = array_values( array_filter( array_map( 'trim', explode( '|', $dirs ) ) ) );
				$regex_parts = array();
				foreach ( $dir_parts as $dp ) {
					$regex_parts[] = self::wildcard2regex( $dp );
				}
				$mapping['_include_dirs_regex'] = implode( '|', array_filter( $regex_parts ) );
			} else {
				$mapping['_include_dirs_regex'] = '';
			}

			$types = isset( $mapping['include_filetypes'] ) ? (string) $mapping['include_filetypes'] : '';
			if ( '' !== $types ) {
				$allowed                       = array_map( 'trim', explode( ',', strtolower( $types ) ) );
				$allowed                       = array_filter( $allowed );
				$allowed                       = array_map( static fn( $t ) => ltrim( $t, '.' ), $allowed );
				$mapping['_allowed_filetypes'] = array_values( $allowed );
			} else {
				$mapping['_allowed_filetypes'] = array();
			}

			return $mapping;
		}

		/**
		 * Resolve the CDN URL for an asset (first matching mapping).
		 *
		 * Thin wrapper over {@see find_cdn_match()} kept for backwards
		 * compatibility with existing callers and the
		 * `wppo_cdn_url_for_asset` filter contract.
		 *
		 * @since NEXT
		 * @param string $url      Asset URL.
		 * @param array  $mappings Normalized mappings from get_mappings().
		 * @return string|null CDN URL or null when no mapping matches.
		 */
		public static function find_cdn_for_url( string $url, array $mappings ): ?string {
			$match = self::find_cdn_match( $url, $mappings );
			return null === $match ? null : $match['cdn'];
		}

		/**
		 * Resolve the first matching CDN mapping for an asset URL.
		 *
		 * Returns both the CDN URL and the mapping that matched so callers can
		 * honour per-mapping settings such as `cdn_attr` (audit #888
		 * finding 21) instead of a global union of all mappings' restrictions.
		 *
		 * @since NEXT
		 * @param string $url      Asset URL.
		 * @param array  $mappings Normalized mappings from get_mappings().
		 * @return array{cdn:string,mapping:array}|null Match info or null when no mapping matches.
		 */
		public static function find_cdn_match( string $url, array $mappings ): ?array {
			$site_url = Util::cached_home_url();
			foreach ( $mappings as $m ) {
				$cdn_url  = $m['cdn_url'] ?? '';
				$cdn_urls = $m['cdn_urls'] ?? array( $cdn_url );
				if ( empty( $cdn_urls ) ) {
					$cdn_urls = array( $cdn_url );
				}
				// Idempotency: already CDN.
				foreach ( $cdn_urls as $c ) {
					if ( '' !== $c && 0 === strpos( $url, $c ) ) {
						return null;
					}
				}
				// Origin guard: if ori set, require url starts with ori, else with site_url.
				$ori = $m['ori'] ?? '';
				if ( '' !== $ori ) {
					if ( 0 !== strpos( $url, $ori ) ) {
						continue;
					}
				} elseif ( 0 !== strpos( $url, $site_url ) ) {
						continue;
				}
				// ori_dir guard via wildcard2regex (precomputed parts when available).
				$ori_dir = $m['ori_dir'] ?? '';
				if ( '' !== $ori_dir ) {
					$path  = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
					$parts = $m['_ori_dir_parts'] ?? null;
					if ( ! is_array( $parts ) ) {
						$parts = array_filter( array_map( 'trim', explode( '|', $ori_dir ) ) );
					}
					$matched = false;
					foreach ( $parts as $part ) {
						$regex = self::wildcard2regex( $part );
						if ( '' === $regex ) {
							continue;
						}
						if ( preg_match( '#(?:^|/)' . $regex . '(?:/|$)#i', $path ) ) {
							$matched = true;
							break;
						}
					}
					if ( ! $matched ) {
						continue;
					}
				}
				// include_dirs check with wildcard2regex expansion (precomputed regex when available).
				if ( array_key_exists( '_include_dirs_regex', $m ) ) {
					$dirs_regex = (string) $m['_include_dirs_regex'];
					if ( '' !== $dirs_regex && ! preg_match( '#/(?:' . $dirs_regex . ')/#i', $url ) ) {
						continue;
					}
				} else {
					$dirs = $m['include_dirs'] ?? 'wp-content|wp-includes';
					if ( '' !== $dirs ) {
						$dir_parts   = array_filter( array_map( 'trim', explode( '|', $dirs ) ) );
						$regex_parts = array();
						foreach ( $dir_parts as $dp ) {
							$regex_parts[] = self::wildcard2regex( $dp );
						}
						$dirs_regex = implode( '|', array_filter( $regex_parts ) );
						if ( '' !== $dirs_regex && ! preg_match( '#/(?:' . $dirs_regex . ')/#i', $url ) ) {
							continue;
						}
					}
				}
				// include_filetypes (precomputed allowlist when available).
				$types = $m['include_filetypes'] ?? '';
				if ( '' !== $types ) {
					$ext     = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
					$allowed = $m['_allowed_filetypes'] ?? null;
					if ( ! is_array( $allowed ) ) {
						$allowed = array_map( 'trim', explode( ',', strtolower( $types ) ) );
						$allowed = array_filter( $allowed );
						$allowed = array_map( fn( $t ) => ltrim( $t, '.' ), $allowed );
					}
					if ( '' !== $ext && ! in_array( $ext, $allowed, true ) ) {
						continue;
					}
					// If url has no extension, allow through (e.g. versioned urls without ext).
				}
				// Determine CDN via deterministic round-robin when multiple hosts.
				$cdn = $cdn_url;
				if ( count( $cdn_urls ) > 1 ) {
					$idx = abs( crc32( $url ) ) % count( $cdn_urls );
					$cdn = $cdn_urls[ $idx ];
				}
				/**
				 * Filter CDN URL for asset.
				 *
				 * @since NEXT
				 * @param string $cdn CDN URL.
				 * @param string $url Asset URL.
				 * @param array  $m Mapping entry.
				 */
				$cdn = (string) apply_filters( 'wppo_cdn_url_for_asset', $cdn, $url, $m );
				return array(
					'cdn'     => rtrim( $cdn, '/' ),
					'mapping' => $m,
				);
			}
			return null;
		}

		/**
		 * Whether a mapping's `cdn_attr` restriction permits an attribute.
		 *
		 * Per-mapping semantics: each mapping declares which HTML attributes
		 * URLs served by IT may be rewritten from. A mapping with an empty
		 * `cdn_attr` allows the default attribute set. A URL is rewritten in
		 * a given attribute only when ITS OWN mapping allows that attribute —
		 * not the union across mappings (audit #888 finding 21). CSS url()
		 * rewriting in <style> blocks is unaffected: it has no attribute
		 * context and keeps its unrestricted behaviour.
		 *
		 * @since NEXT
		 * @param array  $mapping Mapping entry.
		 * @param string $attr    Attribute name (lowercase, e.g. 'src', 'srcset').
		 * @return bool
		 */
		private static function mapping_allows_attr( array $mapping, string $attr ): bool {
			$cdn_attr = trim( (string) ( $mapping['cdn_attr'] ?? '' ) );
			if ( '' === $cdn_attr ) {
				return true;
			}
			$allowed = array_filter( array_map( 'trim', explode( ',', strtolower( $cdn_attr ) ) ) );
			return in_array( strtolower( $attr ), $allowed, true );
		}

		/**
		 * Rewrite single URL.
		 *
		 * Added optional $mappings pass-down so bulk callers
		 * (e.g. rewrite_srcset()) can resolve mappings once.
		 *
		 * @since NEXT
		 * @param string $url      URL.
		 * @param mixed  $mappings Optional pre-resolved mappings from get_mappings(). When
		 *                         this method is invoked as a `*_loader_src` / `wp_get_attachment_url`
		 *                         filter, WordPress passes the handle/attachment ID as the second
		 *                         argument — any non-array value is ignored and mappings are resolved.
		 * @return string Rewritten or original.
		 */
		public static function rewrite_url( string $url, mixed $mappings = null ): string {
			if ( '' === $url ) {
				return $url;
			}
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				return $url;
			}
			if ( is_admin() ) {
				return $url;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && ! LiteSpeed_Integration::can_apply_cdn() ) {
				return $url;
			}
			if ( ! is_array( $mappings ) ) {
				$mappings = self::get_mappings();
			}
			if ( empty( $mappings ) ) {
				return $url;
			}
			$cdn = self::find_cdn_for_url( $url, $mappings );
			if ( null === $cdn ) {
				return $url;
			}
			$site_url = Util::cached_home_url();
			$ori      = '';
			// Prefer ori if url starts with ori.
			foreach ( $mappings as $m ) {
				if ( ! empty( $m['ori'] ) && 0 === strpos( $url, $m['ori'] ) ) {
					$ori = $m['ori'];
					break;
				}
			}
			$origin = '' !== $ori ? $ori : $site_url;
			if ( 0 === strpos( $url, $origin ) ) {
				return $cdn . substr( $url, strlen( $origin ) );
			}
			return str_replace( $site_url, $cdn, $url );
		}

		/**
		 * Rewrite srcset array (wp_calculate_image_srcset format).
		 *
		 * Added optional $mappings pass-down; resolves once per
		 * call instead of once per srcset candidate.
		 *
		 * @since NEXT
		 * @param array $sources  Srcset sources.
		 * @param mixed $mappings Optional pre-resolved mappings from get_mappings().
		 *                        Non-array values (e.g. a filter's handle or
		 *                        size array argument) are ignored and mappings
		 *                        are resolved via get_mappings().
		 * @return array
		 */
		public static function rewrite_srcset( array $sources, mixed $mappings = null ): array {
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				return $sources;
			}
			if ( is_admin() ) {
				return $sources;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && ! LiteSpeed_Integration::can_apply_cdn() ) {
				return $sources;
			}
			if ( ! apply_filters( 'wppo_litespeed_can_cdn', true ) ) {
				return $sources;
			}
			if ( has_filter( 'litespeed_can_cdn' ) && ! apply_filters( 'litespeed_can_cdn', true ) ) {
				return $sources;
			}
			if ( ! is_array( $mappings ) ) {
				$mappings = self::get_mappings();
			}
			if ( empty( $mappings ) ) {
				return $sources;
			}
			foreach ( $sources as $w => $data ) {
				if ( isset( $data['url'] ) ) {
					$sources[ $w ]['url'] = self::rewrite_url( $data['url'], $mappings );
				}
			}
			return $sources;
		}

		/**
		 * Rewrite buffer (tags + inline url()).
		 *
		 * @since NEXT
		 * @param string $buffer HTML buffer.
		 * @return string
		 */
		public static function rewrite_buffer( string $buffer ): string {
			if ( '' === $buffer ) {
				return $buffer;
			}
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				return $buffer;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && ! LiteSpeed_Integration::can_apply_cdn() ) {
				return $buffer;
			}
			if ( ! apply_filters( 'wppo_litespeed_can_cdn', true ) ) {
				return $buffer;
			}
			if ( has_filter( 'litespeed_can_cdn' ) && ! apply_filters( 'litespeed_can_cdn', true ) ) {
				return $buffer;
			}
			$mappings = self::get_mappings();
			if ( empty( $mappings ) ) {
				return $buffer;
			}
			$site_url       = Util::cached_home_url();
			$site_url_regex = '#^' . preg_quote( $site_url, '#' ) . '(/|$)#';
			// Precompiled once per buffer: the inline style-url() pattern
			// reuses the same quoted site URL instead of preg_quote() per tag.
			$style_url_pattern = '#url\s*\(\s*(["\']?)' . preg_quote( $site_url, '#' ) . '([^"\')\s]*)\1\s*\)#i';

			// Expanded tag list parity: add audio,track,embed,object,iframe,picture,meta.
			$allowed_tags = array( 'img', 'script', 'link', 'source', 'video', 'audio', 'track', 'embed', 'object', 'iframe', 'picture', 'meta' );

			// WP 6.9+ HTML API path (issue #883): full-document parse with
			// depth awareness via WP_HTML_Processor::serialize_token(). Correct
			// on malformed markup (SVG, nested tables, missing closers) where
			// the tag-at-a-time Tag Processor can mis-attribute tokens. Falls
			// back to the Tag Processor loop below when the serializer is
			// unavailable (WP <6.9) or the parse fails.
			$processed = null;
			if ( Util::should_use_html_processor() ) {
				$processed = self::rewrite_buffer_with_processor( $buffer, $mappings, $site_url, $site_url_regex, $allowed_tags, $style_url_pattern );
			}

			if ( null !== $processed ) {
				$buffer = $processed;
			} elseif ( class_exists( '\WP_HTML_Tag_Processor' ) ) {
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				while ( $tags->next_tag() ) {
					self::rewrite_tag_assets( $tags, $mappings, $site_url, $site_url_regex, $allowed_tags, $style_url_pattern );
				}
				$buffer = $tags->get_updated_html();
			}

			// Fallback inline url() rewrite for <style> blocks and remaining style="url(...)" (mirrors LSCWP 394-440).
			//
			// Deliberately unrestricted by cdn_attr (Part 2 review): LSCWP's
			// cdn_attr governs HTML attribute rewriting; CSS url() rewriting in
			// <style> blocks has no attribute context and has always been
			// unconditional, so configs that restrict a mapping to e.g. 'src'
			// still get their stylesheet URLs CDN-rewritten. Inline
			// style="..." attributes ARE attribute context and are gated on
			// the mapping's 'style' allowance above.
			$buffer = preg_replace_callback(
				$style_url_pattern,
				function ( $m ) use ( $mappings, $site_url ) {
					$full_url = $site_url . $m[2];
					$cdn      = self::find_cdn_for_url( $full_url, $mappings );
					if ( null !== $cdn ) {
						return 'url(' . $m[1] . $cdn . $m[2] . $m[1] . ')';
					}
					return $m[0];
				},
				$buffer
			);

			/**
			 * Filter CDN buffer after rewrite.
			 *
			 * @since NEXT
			 * @param string $buffer Rewritten buffer.
			 */
			$buffer = (string) apply_filters( 'wppo_cdn_buffer', $buffer );

			return $buffer;
		}

		/**
		 * Rewrite the buffer's asset URLs via the WP 6.9+ HTML processor.
		 *
		 * Full-document transform using `WP_HTML_Processor` +
		 * `serialize_token()` token streaming (issue #883): handles malformed
		 * markup (SVG/MathML, nested tables, missing closers) with real HTML5
		 * tree knowledge instead of the tag-at-a-time Tag Processor. Attribute
		 * rewriting itself is shared with the Tag Processor fallback through
		 * {@see rewrite_tag_assets()} (WP_HTML_Processor extends
		 * WP_HTML_Tag_Processor), so both paths stay behaviorally identical.
		 *
		 * Returns null to trigger the Tag Processor fallback when the parser
		 * cannot be created or the token stream ended with a parse error.
		 *
		 * @since NEXT
		 *
		 * @param string   $buffer            HTML buffer.
		 * @param array    $mappings          CDN mappings.
		 * @param string   $site_url          Site URL.
		 * @param string   $site_url_regex    Regex matching a site-relative URL start.
		 * @param string[] $allowed_tags      Tag names whose attributes may be rewritten.
		 * @param string   $style_url_pattern Precompiled inline style-url() pattern.
		 * @return string|null Rewritten buffer, or null on failure (fallback).
		 */
		private static function rewrite_buffer_with_processor( string $buffer, array $mappings, string $site_url, string $site_url_regex, array $allowed_tags, string $style_url_pattern = '' ): ?string {
			$processor = Util::create_html_processor( $buffer );
			if ( null === $processor ) {
				return null;
			}

			try {
				$out = '';
				while ( $processor->next_token() ) {
					if ( '#tag' === $processor->get_token_type() && ! $processor->is_tag_closer() ) {
						self::rewrite_tag_assets( $processor, $mappings, $site_url, $site_url_regex, $allowed_tags, $style_url_pattern );
					}
					$out .= $processor->serialize_token();
				}
			} catch ( \Throwable $e ) {
				// A partial/throwing HTML API surface must not fatal inside the
				// output path — fall back to the byte-identical Tag Processor
				// pass (issue #883 review).
				return null;
			}

			if ( null !== $processor->get_last_error() ) {
				return null;
			}

			return $out;
		}

		/**
		 * Rewrite asset URLs on the current tag of a HTML processor instance.
		 *
		 * Shared body of the CDN buffer rewrite, operating on whichever
		 * processor is walking the document — `WP_HTML_Tag_Processor` (WP <6.9
		 * fallback) or `WP_HTML_Processor` (WP 6.9+ full parser), which extends
		 * it. Rewrites `src`, `href`, `data-src`, `content`, `poster`, `srcset`,
		 * `data-srcset` and inline `style="...url(...)"` values that start with
		 * the site URL, honouring each URL's own mapping `cdn_attr` allowance
		 * (audit #888 finding 21).
		 *
		 * @since NEXT
		 *
		 * @param \WP_HTML_Tag_Processor $tags              Processor positioned on the current tag.
		 * @param array                  $mappings          CDN mappings.
		 * @param string                 $site_url          Site URL.
		 * @param string                 $site_url_regex    Regex matching a site-relative URL start.
		 * @param string[]               $allowed_tags      Tag names whose attributes may be rewritten.
		 * @param string                 $style_url_pattern Precompiled inline style-url() pattern.
		 * @return void
		 */
		private static function rewrite_tag_assets( \WP_HTML_Tag_Processor $tags, array $mappings, string $site_url, string $site_url_regex, array $allowed_tags, string $style_url_pattern = '' ): void {
			$tag_name = strtolower( (string) $tags->get_tag() );
			if ( ! in_array( $tag_name, $allowed_tags, true ) ) {
				return;
			}

			// Per-mapping cdn_attr filtering (audit #888 finding 21): each
			// URL is rewritten only when ITS OWN mapping's cdn_attr allows
			// the attribute — a global union across mappings would weaken
			// per-mapping intent (e.g. one mapping restricted to 'src'
			// must not enable 'href' rewriting for another mapping's urls).
			// A mapping with an empty cdn_attr allows the default set.
			$attrs = array( 'src', 'href', 'data-src', 'content', 'poster' );
			foreach ( $attrs as $attr ) {
				$val = $tags->get_attribute( $attr );
				if ( $val && preg_match( $site_url_regex, $val ) ) {
					$match = self::find_cdn_match( $val, $mappings );
					if ( null !== $match && self::mapping_allows_attr( $match['mapping'], $attr ) ) {
						$origin = $match['mapping']['ori'] ?? '';
						if ( '' === $origin ) {
							$origin = $site_url;
						}
						$tags->set_attribute( $attr, $match['cdn'] . substr( $val, strlen( $origin ) ) );
					}
				}
			}

			// srcset handling.
			$srcset_attrs = array( 'srcset', 'data-srcset' );
			foreach ( $srcset_attrs as $attr ) {
				$srcset_attr = $tags->get_attribute( $attr );
				if ( $srcset_attr ) {
					$candidates = explode( ',', $srcset_attr );
					$new_srcset = array();
					foreach ( $candidates as $candidate ) {
						$candidate = trim( $candidate );
						$parts     = preg_split( '/\s+/', $candidate, 2 );
						$url       = $parts[0];
						$suffix    = isset( $parts[1] ) ? ' ' . $parts[1] : '';
						if ( preg_match( $site_url_regex, $url ) ) {
							$match = self::find_cdn_match( $url, $mappings );
							if ( null !== $match && self::mapping_allows_attr( $match['mapping'], $attr ) ) {
								$origin = $match['mapping']['ori'] ?? '';
								if ( '' === $origin ) {
									$origin = $site_url;
								}
								$url = $match['cdn'] . substr( $url, strlen( $origin ) );
							}
						}
						$new_srcset[] = $url . $suffix;
					}
					$tags->set_attribute( $attr, implode( ', ', $new_srcset ) );
				}
			}

			// style attribute url() handling inline (pattern precompiled
			// once per buffer by rewrite_buffer()).
			$style_val = $tags->get_attribute( 'style' );
			if ( $style_val && false !== strpos( $style_val, 'url(' ) ) {
				if ( '' === $style_url_pattern ) {
					$style_url_pattern = '#url\s*\(\s*(["\']?)' . preg_quote( $site_url, '#' ) . '([^"\')\s]*)\1\s*\)#i';
				}
				$new_style = preg_replace_callback(
					$style_url_pattern,
					function ( $m2 ) use ( $mappings, $site_url ) {
						$full_url = $site_url . $m2[2];
						$match    = self::find_cdn_match( $full_url, $mappings );
						if ( null !== $match && self::mapping_allows_attr( $match['mapping'], 'style' ) ) {
							return 'url(' . $m2[1] . $match['cdn'] . $m2[2] . $m2[1] . ')';
						}
						return $m2[0];
					},
					$style_val
				);
				if ( null !== $new_style && $new_style !== $style_val ) {
					$tags->set_attribute( 'style', $new_style );
				}
			}
		}

		/**
		 * Reset idempotency guard (for testing).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_cache(): void {
			self::$buffer_rewritten = false;
			self::$regex_cache      = array();
			self::$mappings_cache   = array();
		}
	}
}
