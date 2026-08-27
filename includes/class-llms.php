<?php
/**
 * LLMs.txt auto-generation (N8).
 *
 * Generates /llms.txt + /llms-full.txt virtual files from local data only
 * (no external AI call) — wppo_web_vitals_trends high-value URLs + sitemap
 * + fallback published posts. Served via rewrite + template_redirect fallback
 * with ETag/304, Link header and head <link>.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Llms' ) ) {
	/**
	 * LLMs.txt generator and virtual-file router.
	 *
	 * @since NEXT
	 */
	class Llms {

		const FILE      = 'llms.txt';
		const FILE_FULL = 'llms-full.txt';

		/**
		 * Whether LLMs.txt generation is enabled.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$options = get_option( 'wppo_settings', array() );
			$enabled = ! empty( $options['llms_txt']['enabled'] );
			/**
			 * Filters whether LLMs.txt is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether enabled.
			 */
			return (bool) apply_filters( 'wppo_llms_txt_enabled', $enabled );
		}

		/**
		 * Base directory for LLMs files (blog-scoped on multisite).
		 *
		 * @since NEXT
		 * @return string
		 */
		private static function base_dir(): string {
			$base = WP_CONTENT_DIR . '/cache/wppo';
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				$base .= '/site-' . get_current_blog_id();
			}
			return wp_normalize_path( $base );
		}

		/**
		 * Absolute file path for the requested variant.
		 *
		 * @since NEXT
		 * @param string $which 'llms' or 'full'.
		 * @return string
		 */
		public static function get_file_path( string $which = 'llms' ): string {
			$filename = 'full' === $which ? self::FILE_FULL : self::FILE;
			return trailingslashit( self::base_dir() ) . $filename;
		}

		/**
		 * Register rewrite rules for virtual /llms.txt and /llms-full.txt.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function register_rewrite(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			if ( ! function_exists( 'add_rewrite_rule' ) ) {
				return;
			}
			add_rewrite_rule( '^llms\.txt$', 'index.php?wppo_llms=1', 'top' );
			add_rewrite_rule( '^llms-full\.txt$', 'index.php?wppo_llms_full=1', 'top' );
		}

		/**
		 * Register query vars for the rewrite rules.
		 *
		 * @since NEXT
		 * @param array $vars Existing query vars.
		 * @return array
		 */
		public static function add_query_vars( array $vars ): array {
			$vars[] = 'wppo_llms';
			$vars[] = 'wppo_llms_full';
			return $vars;
		}

		/**
		 * Serve the virtual LLMs file on template_redirect.
		 *
		 * Handles ETag / 304 and generates on-demand if file missing.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function serve(): void {
			$is_llms      = false;
			$is_full      = false;
			$which        = 'llms';
			$request_uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$request_path = wp_parse_url( $request_uri, PHP_URL_PATH );

			// Prefer query var when rewrite flushed; fallback to URI check.
			if ( function_exists( 'get_query_var' ) ) {
				$q_llms      = get_query_var( 'wppo_llms' );
				$q_llms_full = get_query_var( 'wppo_llms_full' );
				if ( ! empty( $q_llms ) ) {
					$is_llms = true;
				}
				if ( ! empty( $q_llms_full ) ) {
					$is_full = true;
				}
			}

			// URI fallback when rewrite not yet flushed.
			if ( ! $is_llms && ! $is_full && is_string( $request_path ) ) {
				$trimmed = trim( $request_path, '/' );
				if ( 'llms.txt' === $trimmed ) {
					$is_llms = true;
				} elseif ( 'llms-full.txt' === $trimmed ) {
					$is_full = true;
				}
			}

			if ( ! $is_llms && ! $is_full ) {
				return;
			}

			if ( ! self::is_enabled() ) {
				return;
			}

			$which = $is_full ? 'full' : 'llms';
			$path  = self::get_file_path( $which );

			// Generate on-demand if missing.
			if ( ! file_exists( $path ) ) {
				self::generate();
			}

			if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
				return;
			}

			// ETag / 304 handling.
			$etag = '"' . md5_file( $path ) . '"';

			if ( headers_sent() ) {
				// Still output content if headers already sent (tests).
				readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
				exit;
			}

			$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			if ( '' !== $if_none_match && ( $if_none_match === $etag || 'W/' . $etag === $if_none_match ) ) {
				status_header( 304 );
				header( 'ETag: ' . $etag );
				exit;
			}

			status_header( 200 );
			header( 'Content-Type: text/markdown; charset=utf-8' );
			header( 'ETag: ' . $etag );
			header( 'Cache-Control: public, max-age=3600' );
			header( 'Content-Length: ' . filesize( $path ) );

			readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
			exit;
		}

		/**
		 * Emit Link header for discovery.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function emit_link_header(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return;
			}
			if ( headers_sent() ) {
				return;
			}
			$url = Util::cached_home_url( '/llms.txt' );
			header( sprintf( 'Link: <%s>; rel="alternate"; type="text/markdown"', esc_url( $url ) ), false );
		}

		/**
		 * Emit <link> in wp_head.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function emit_head_link(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return;
			}
			$url = Util::cached_home_url( '/llms.txt' );
			echo '<link rel="alternate" type="text/markdown" href="' . esc_url( $url ) . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		/**
		 * Generate llms.txt and llms-full.txt files.
		 *
		 * @since NEXT
		 * @return bool True if files written.
		 */
		public static function generate(): bool {
			if ( ! self::is_enabled() ) {
				return false;
			}

			$options = get_option( 'wppo_settings', array() );
			$llms    = isset( $options['llms_txt'] ) && is_array( $options['llms_txt'] ) ? $options['llms_txt'] : array();
			$source  = isset( $llms['source'] ) ? sanitize_text_field( (string) $llms['source'] ) : 'both';
			if ( ! in_array( $source, array( 'both', 'trends', 'sitemap' ), true ) ) {
				$source = 'both';
			}
			$limit = isset( $llms['limit'] ) ? absint( $llms['limit'] ) : 50;
			if ( $limit < 1 || $limit > 200 ) {
				$limit = 50;
			}

			$urls = self::collect_urls( $limit, $source );

			$site_name = get_bloginfo( 'name' );
			$site_desc = get_bloginfo( 'description' );
			$home_url  = Util::cached_home_url( '/' );

			$content      = self::build_markdown( $urls, (string) $site_name, (string) $site_desc, (string) $home_url, false );
			$content_full = self::build_markdown( $urls, (string) $site_name, (string) $site_desc, (string) $home_url, true );

			/**
			 * Filters LLMs.txt content before writing.
			 *
			 * @since NEXT
			 * @param string $content The markdown content.
			 * @param string $which   'llms' or 'llms-full'.
			 */
			$content = (string) apply_filters( 'wppo_llms_txt_content', $content, 'llms' );
			/**
			 * Filters LLMs.txt full content before writing.
			 *
			 * @since NEXT
			 * @param string $content The markdown content.
			 * @param string $which   'llms' or 'llms-full'.
			 */
			$content_full = (string) apply_filters( 'wppo_llms_txt_content', $content_full, 'llms-full' );

			// Cap at 20KB per spec (word boundary).
			$content      = self::cap_content( $content, 20 * 1024 );
			$content_full = self::cap_content( $content_full, 20 * 1024 );

			$dir = self::base_dir();
			$fs  = Util::init_filesystem();
			if ( $fs ) {
				if ( ! $fs->is_dir( $dir ) ) {
					Util::prepare_cache_dir( $dir );
				}
			} elseif ( function_exists( 'wp_mkdir_p' ) ) {
				wp_mkdir_p( $dir );
			} else {
				@mkdir( $dir, 0775, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged
			}

			$path      = self::get_file_path( 'llms' );
			$path_full = self::get_file_path( 'full' );

			$ok = true;
			if ( $fs && method_exists( $fs, 'put_contents' ) ) {
				$ok = $fs->put_contents( $path, $content, FS_CHMOD_FILE ) && $ok;
				$ok = $fs->put_contents( $path_full, $content_full, FS_CHMOD_FILE ) && $ok;
			} else {
				$written = file_put_contents( $path, $content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$ok      = ( false !== $written ) && $ok;
				$written = file_put_contents( $path_full, $content_full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				$ok      = ( false !== $written ) && $ok;
			}

			return (bool) $ok;
		}

		/**
		 * Cap content at word boundary.
		 *
		 * @since NEXT
		 * @param string $content Content.
		 * @param int    $max_bytes Max bytes.
		 * @return string
		 */
		private static function cap_content( string $content, int $max_bytes ): string {
			if ( strlen( $content ) <= $max_bytes ) {
				return $content;
			}
			$truncated = substr( $content, 0, $max_bytes );
			$last_nl   = strrpos( $truncated, "\n" );
			if ( false !== $last_nl && $last_nl > (int) ( $max_bytes * 0.8 ) ) {
				$truncated = substr( $truncated, 0, $last_nl );
			}
			return $truncated;
		}

		/**
		 * Collect top URLs for LLMs digest.
		 *
		 * @since NEXT
		 * @param int    $limit  Max URLs.
		 * @param string $source Source filter.
		 * @return string[]
		 */
		private static function collect_urls( int $limit = 50, string $source = 'both' ): array {
			$urls = array();

			if ( in_array( $source, array( 'both', 'trends' ), true ) ) {
				// From wppo_web_vitals_trends keys.
				$trends = get_option( 'wppo_web_vitals_trends', array() );
				if ( is_array( $trends ) && ! empty( $trends ) ) {
					// Also pull high_value_urls from performance_audit.
					$opts = get_option( 'wppo_settings', array() );
					$high = isset( $opts['performance_audit']['high_value_urls'] ) && is_array( $opts['performance_audit']['high_value_urls'] ) ? $opts['performance_audit']['high_value_urls'] : array(); // phpcs:ignore Generic.Files.LineLength.TooLong
					foreach ( $high as $h ) {
						$clean = esc_url_raw( (string) $h );
						if ( '' !== $clean && ! in_array( $clean, $urls, true ) ) {
							$urls[] = $clean;
						}
					}

					// Decode trends: keys are md5(url)_strategy; we need actual URLs from stored
					// high_value list or fallback to home. Since trends keys are hashed, we
					// cannot reverse — instead use the high_value_urls + home as proxy.
					// Additionally, if trends exist, ensure home is first.
					$home = Util::cached_home_url( '/' );
					if ( ! in_array( $home, $urls, true ) ) {
						array_unshift( $urls, $home );
					}
				} else {
					// No trends yet — still include high_value_urls if any.
					$opts = get_option( 'wppo_settings', array() );
					$high = isset( $opts['performance_audit']['high_value_urls'] ) && is_array( $opts['performance_audit']['high_value_urls'] ) ? $opts['performance_audit']['high_value_urls'] : array(); // phpcs:ignore Generic.Files.LineLength.TooLong
					foreach ( $high as $h ) {
						$clean = esc_url_raw( (string) $h );
						if ( '' !== $clean && ! in_array( $clean, $urls, true ) ) {
							$urls[] = $clean;
						}
					}
					$home = Util::cached_home_url( '/' );
					if ( ! in_array( $home, $urls, true ) ) {
						array_unshift( $urls, $home );
					}
				}
			}

			if ( in_array( $source, array( 'both', 'sitemap' ), true ) ) {
				$sitemap_urls = self::collect_sitemap_urls( $limit );
				foreach ( $sitemap_urls as $s_url ) {
					if ( ! in_array( $s_url, $urls, true ) ) {
						$urls[] = $s_url;
					}
					if ( count( $urls ) >= $limit ) {
						break;
					}
				}
			}

			// Fallback: newest published posts.
			if ( count( $urls ) < $limit ) {
				$needed = $limit - count( $urls );
				if ( function_exists( 'get_posts' ) ) {
					$post_ids = get_posts(
						array(
							'post_type'      => get_post_types( array( 'public' => true ), 'names' ),
							'post_status'    => 'publish',
							'posts_per_page' => $needed,
							'fields'         => 'ids',
							'orderby'        => 'date',
							'order'          => 'DESC',
						)
					);
					if ( is_array( $post_ids ) ) {
						foreach ( $post_ids as $pid ) {
							$perm = function_exists( 'get_permalink' ) ? get_permalink( $pid ) : '';
							if ( ! empty( $perm ) && ! in_array( $perm, $urls, true ) ) {
								$urls[] = $perm;
							}
							if ( count( $urls ) >= $limit ) {
								break;
							}
						}
					}
				}
			}

			// Deduplicate and cap.
			$urls = array_values( array_unique( array_filter( $urls ) ) );
			if ( count( $urls ) > $limit ) {
				$urls = array_slice( $urls, 0, $limit );
			}

			return $urls;
		}

		/**
		 * Collect URLs from sitemap (local only).
		 *
		 * @since NEXT
		 * @param int $cap Max URLs.
		 * @return string[]
		 */
		private static function collect_sitemap_urls( int $cap = 50 ): array {
			$urls      = array();
			$home_host = wp_parse_url( Util::cached_home_url(), PHP_URL_HOST );
			$to_fetch  = array( Util::cached_home_url( '/wp-sitemap.xml' ) );
			$fetched   = array();
			$deadline  = microtime( true ) + 15;
			$url_count = 0;

			while ( ! empty( $to_fetch ) && $url_count < $cap ) {
				if ( microtime( true ) >= $deadline ) {
					break;
				}
				$current = array_shift( $to_fetch );
				if ( isset( $fetched[ $current ] ) ) {
					continue;
				}
				$fetched[ $current ] = true;

				if ( ! function_exists( 'wp_remote_get' ) ) {
					break;
				}
				$response = wp_remote_get( $current, array( 'timeout' => 5 ) );
				if ( is_wp_error( $response ) ) {
					continue;
				}
				if ( function_exists( 'wp_remote_retrieve_response_code' ) && 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
					continue;
				}
				$body = wp_remote_retrieve_body( $response );
				if ( '' === $body ) {
					continue;
				}
				$is_index = ( false !== strpos( $body, '<sitemapindex' ) );
				if ( ! preg_match_all( '#<loc>\s*([^<]+?)\s*</loc>#i', $body, $matches ) ) {
					continue;
				}
				$to_fetch_count = count( $to_fetch );
				foreach ( $matches[1] as $loc ) {
					$loc = esc_url_raw( trim( (string) $loc ) );
					if ( '' === $loc ) {
						continue;
					}
					$loc_host = wp_parse_url( $loc, PHP_URL_HOST );
					if ( $loc_host && $loc_host !== $home_host ) {
						continue;
					}
					if ( $is_index ) {
						if ( isset( $fetched[ $loc ] ) || $to_fetch_count >= 50 ) {
							continue;
						}
						$to_fetch[] = $loc;
						++$to_fetch_count;
						continue;
					}
					$urls[] = $loc;
					++$url_count;
					if ( $url_count >= $cap ) {
						break 2;
					}
				}
			}

			return $urls;
		}

		/**
		 * Build markdown content.
		 *
		 * @since NEXT
		 * @param string[] $urls List of URLs.
		 * @param string   $site_name Site name.
		 * @param string   $site_desc Site description.
		 * @param string   $home_url  Home URL.
		 * @param bool     $is_full   Whether full variant.
		 * @return string
		 */
		private static function build_markdown( array $urls, string $site_name, string $site_desc, string $home_url, bool $is_full = false ): string {
			$lines   = array();
			$lines[] = '# ' . ( '' !== $site_name ? $site_name : $home_url );
			$lines[] = '';
			if ( '' !== $site_desc ) {
				$lines[] = '> ' . $site_desc;
				$lines[] = '';
			}
			$lines[] = 'Home: ' . $home_url;
			$lines[] = '';
			if ( $is_full ) {
				$lines[] = '## Full site digest';
				$lines[] = '';
			} else {
				$lines[] = '## Top URLs';
				$lines[] = '';
			}
			foreach ( $urls as $url ) {
				$lines[] = '- ' . $url;
			}
			if ( empty( $urls ) ) {
				$lines[] = '- ' . $home_url;
			}
			$lines[] = '';

			return implode( "\n", $lines );
		}

		/**
		 * React to wppo_settings changes (flush rewrite, regenerate).
		 *
		 * @since NEXT
		 * @param mixed $old_value Old option value.
		 * @param mixed $new_value New option value.
		 * @return void
		 */
		public static function on_settings_update( $old_value, $new_value ): void {
			$old_enabled = ! empty( $old_value['llms_txt']['enabled'] ?? false );
			$new_enabled = ! empty( $new_value['llms_txt']['enabled'] ?? false );

			if ( $old_enabled !== $new_enabled ) {
				if ( function_exists( 'flush_rewrite_rules' ) ) {
					flush_rewrite_rules( false );
				}
				if ( $new_enabled ) {
					self::generate();
				} else {
					// Remove files when disabled.
					$path      = self::get_file_path( 'llms' );
					$path_full = self::get_file_path( 'full' );
					if ( file_exists( $path ) ) {
						@unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
					}
					if ( file_exists( $path_full ) ) {
						@unlink( $path_full ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				return;
			}

			if ( $new_enabled ) {
				$old_llms = isset( $old_value['llms_txt'] ) && is_array( $old_value['llms_txt'] ) ? $old_value['llms_txt'] : array();
				$new_llms = isset( $new_value['llms_txt'] ) && is_array( $new_value['llms_txt'] ) ? $new_value['llms_txt'] : array();
				if ( $old_llms !== $new_llms ) {
					self::generate();
				}
			}
		}
	}
}
