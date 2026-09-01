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
			// Escape then unescape * to .*
			$escaped = preg_quote( $pattern, '#' );
			$escaped = str_replace( '\*', '.*', $escaped );
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
			if ( empty( $options ) ) {
				$options = Util::get_settings();
			}
			$mappings = $options['file_optimisation']['cdnMapping'] ?? array();
			if ( is_array( $mappings ) && ! empty( $mappings ) ) {
				$mappings = array_values( array_filter( $mappings, fn( $m ) => is_array( $m ) && ! empty( $m['cdn_url'] ) ) );
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
					$ori       = isset( $m['ori'] ) ? trim( (string) $m['ori'] ) : '';
					$ori_dir   = isset( $m['ori_dir'] ) ? trim( (string) $m['ori_dir'] ) : '';
					$cdn_attr  = isset( $m['cdn_attr'] ) ? trim( (string) $m['cdn_attr'] ) : '';
					$include_dirs = isset( $m['include_dirs'] ) ? (string) $m['include_dirs'] : 'wp-content|wp-includes';
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
				return $normalized;
			}
			$cdn_url = $options['file_optimisation']['cdnURL'] ?? '';
			if ( empty( $cdn_url ) ) {
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
			$single = (array) apply_filters( 'wppo_cdn_mapping_hosts', $single );
			$single = (array) apply_filters( 'wppo_cdn_mapping', $single );
			return $single;
		}

		/**
		 * Find CDN host for URL.
		 *
		 * @since NEXT
		 * @param string $url Asset URL.
		 * @param array  $mappings Mappings.
		 * @return string|null
		 */
		public static function find_cdn_for_url( string $url, array $mappings ): ?string {
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
				} else {
					if ( 0 !== strpos( $url, $site_url ) ) {
						continue;
					}
				}
				// ori_dir guard via wildcard2regex.
				$ori_dir = $m['ori_dir'] ?? '';
				if ( '' !== $ori_dir ) {
					$path = wp_parse_url( $url, PHP_URL_PATH ) ?? '';
					$parts = array_filter( array_map( 'trim', explode( '|', $ori_dir ) ) );
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
				// include_dirs check with wildcard2regex expansion.
				$dirs = $m['include_dirs'] ?? 'wp-content|wp-includes';
				if ( '' !== $dirs ) {
					$dir_parts = array_filter( array_map( 'trim', explode( '|', $dirs ) ) );
					$regex_parts = array();
					foreach ( $dir_parts as $dp ) {
						$regex_parts[] = self::wildcard2regex( $dp );
					}
					$dirs_regex = implode( '|', array_filter( $regex_parts ) );
					if ( '' !== $dirs_regex && ! preg_match( '#/(?:' . $dirs_regex . ')/#i', $url ) ) {
						continue;
					}
				}
				// include_filetypes.
				$types = $m['include_filetypes'] ?? '';
				if ( '' !== $types ) {
					$ext     = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ) ?? '', PATHINFO_EXTENSION ) );
					$allowed = array_map( 'trim', explode( ',', strtolower( $types ) ) );
					$allowed = array_filter( $allowed );
					$allowed = array_map( fn( $t ) => ltrim( $t, '.' ), $allowed );
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
				return rtrim( $cdn, '/' );
			}
			return null;
		}

		/**
		 * Rewrite single URL.
		 *
		 * @since NEXT
		 * @param string $url URL.
		 * @return string Rewritten or original.
		 */
		public static function rewrite_url( string $url ): string {
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
			$mappings = self::get_mappings();
			if ( empty( $mappings ) ) {
				return $url;
			}
			$cdn = self::find_cdn_for_url( $url, $mappings );
			if ( null === $cdn ) {
				return $url;
			}
			$site_url = Util::cached_home_url();
			$ori = '';
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
		 * @since NEXT
		 * @param array $sources Srcset sources.
		 * @return array
		 */
		public static function rewrite_srcset( array $sources ): array {
			if ( defined( 'LITESPEED_BYPASS_CDN' ) && LITESPEED_BYPASS_CDN ) {
				return $sources;
			}
			if ( is_admin() ) {
				return $sources;
			}
			foreach ( $sources as $w => $data ) {
				if ( isset( $data['url'] ) ) {
					$sources[ $w ]['url'] = self::rewrite_url( $data['url'] );
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
			$site_url = Util::cached_home_url();
			$site_url_regex = '#^' . preg_quote( $site_url, '#' ) . '(/|$)#';

			// Tag processor pass if available.
			if ( class_exists( '\WP_HTML_Tag_Processor' ) ) {
				$tags = new \WP_HTML_Tag_Processor( $buffer );
				// Expanded tag list parity: add audio,track,embed,object,iframe,picture,meta.
				$allowed_tags = array( 'img', 'script', 'link', 'source', 'video', 'audio', 'track', 'embed', 'object', 'iframe', 'picture', 'meta' );
				while ( $tags->next_tag() ) {
					$tag_name = strtolower( $tags->get_tag() );
					if ( ! in_array( $tag_name, $allowed_tags, true ) ) {
						continue;
					}
					// cdn_attr filtering per mapping — for simplicity, respect global attr set if any mapping defines cdn_attr.
					// If none defines, use default attrs.
					$attrs = array( 'src', 'href', 'data-src', 'content', 'poster' );
					// Check if tag has cdn_attr restriction: if any mapping defines cdn_attr, intersect.
					$has_restriction = false;
					$allowed_attrs = array();
					foreach ( $mappings as $mm ) {
						if ( ! empty( $mm['cdn_attr'] ) ) {
							$has_restriction = true;
							$parts = array_map( 'trim', explode( ',', strtolower( $mm['cdn_attr'] ) ) );
							$allowed_attrs = array_merge( $allowed_attrs, $parts );
						}
					}
					if ( $has_restriction ) {
						$allowed_attrs = array_unique( array_filter( $allowed_attrs ) );
						$attrs = array_intersect( $attrs, $allowed_attrs );
						// Also allow srcset attrs if cdn_attr includes srcset.
						if ( in_array( 'srcset', $allowed_attrs, true ) ) {
							$attrs[] = 'srcset';
						}
						if ( in_array( 'data-srcset', $allowed_attrs, true ) ) {
							$attrs[] = 'data-srcset';
						}
						if ( in_array( 'style', $allowed_attrs, true ) ) {
							$attrs[] = 'style';
						}
					}
					foreach ( $attrs as $attr ) {
						$val = $tags->get_attribute( $attr );
						if ( $val && preg_match( $site_url_regex, $val ) ) {
							$cdn = self::find_cdn_for_url( $val, $mappings );
							if ( null !== $cdn ) {
								// Determine origin for this url.
								$ori = '';
								foreach ( $mappings as $mm ) {
									if ( ! empty( $mm['ori'] ) && 0 === strpos( $val, $mm['ori'] ) ) {
										$ori = $mm['ori'];
										break;
									}
								}
								$origin = '' !== $ori ? $ori : $site_url;
								$tags->set_attribute( $attr, $cdn . substr( $val, strlen( $origin ) ) );
							}
						}
					}
					// srcset handling.
					$srcset_attrs = array( 'srcset', 'data-srcset' );
					if ( $has_restriction ) {
						$srcset_attrs = array_intersect( $srcset_attrs, $allowed_attrs );
					}
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
									$cdn = self::find_cdn_for_url( $url, $mappings );
									if ( null !== $cdn ) {
										$ori = '';
										foreach ( $mappings as $mm ) {
											if ( ! empty( $mm['ori'] ) && 0 === strpos( $url, $mm['ori'] ) ) {
												$ori = $mm['ori'];
												break;
											}
										}
										$origin = '' !== $ori ? $ori : $site_url;
										$url = $cdn . substr( $url, strlen( $origin ) );
									}
								}
								$new_srcset[] = $url . $suffix;
							}
							$tags->set_attribute( $attr, implode( ', ', $new_srcset ) );
						}
					}
					// style attribute url() handling inline.
					$style_val = $tags->get_attribute( 'style' );
					if ( $style_val && false !== strpos( $style_val, 'url(' ) ) {
						$new_style = preg_replace_callback(
							'#url\s*\(\s*(["\']?)' . preg_quote( $site_url, '#' ) . '([^"\')\s]*)\1\s*\)#i',
							function ( $m2 ) use ( $mappings, $site_url ) {
								$full_url = $site_url . $m2[2];
								$cdn = self::find_cdn_for_url( $full_url, $mappings );
								if ( null !== $cdn ) {
									return 'url(' . $m2[1] . $cdn . $m2[2] . $m2[1] . ')';
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
				$buffer = $tags->get_updated_html();
			}

			// Fallback inline url() rewrite for <style> blocks and remaining style="url(...)" (mirrors LSCWP 394-440).
			$buffer = preg_replace_callback(
				'#url\s*\(\s*(["\']?)' . preg_quote( $site_url, '#' ) . '([^"\')\s]*)\1\s*\)#i',
				function ( $m ) use ( $mappings, $site_url ) {
					$full_url = $site_url . $m[2];
					$cdn = self::find_cdn_for_url( $full_url, $mappings );
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
		 * Reset idempotency guard (for testing).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_cache(): void {
			self::$buffer_rewritten = false;
		}
	}
}
