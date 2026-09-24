<?php
/**
 * Minification policy for enqueued CSS and JavaScript assets.
 *
 * P3-014: this dependency-light boundary owns the queue, tag-rewrite, and
 * already-minified-file checks extracted from Main. Main keeps the public
 * callback methods and hook callback identity unchanged; the owner reads the
 * live Main exclusion/optimization state through narrow internal bridges.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc\Minify;

use PerformanceOptimise\Inc\Cache;
use PerformanceOptimise\Inc\LiteSpeed_Integration;
use PerformanceOptimise\Inc\Main;
use PerformanceOptimise\Inc\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Minify\Minify_Policy' ) ) {
	/**
	 * Minification policy owner.
	 *
	 * @since NEXT
	 */
	final class Minify_Policy {

		/**
		 * Main instance owning settings and exclusion state.
		 *
		 * @var Main
		 * @since NEXT
		 */
		private Main $main;

		/**
		 * Construct the policy with the live orchestrator state owner.
		 *
		 * @param Main $main Main instance.
		 * @since NEXT
		 */
		public function __construct( Main $main ) {
			$this->main = $main;
		}

		/**
		 * Rewrites enqueued styles to their minified versions at enqueue time and
		 * registers the on-disk path so core can inline them.
		 *
		 * @since 1.9.0
		 * @return void
		 */
		public function minify_queued_styles(): void {
			if ( ! function_exists( 'wp_maybe_inline_styles' ) ) {
				return;
			}

			$is_preview = Main::is_sandbox_preview_active();
			if ( ! $this->main->minify_should_optimise_for_logged_in() && ! $is_preview ) {
				return;
			}

			$options      = $this->main->get_options();
			$file_options = $is_preview ? Main::get_effective_file_optimisation( $options['file_optimisation'] ?? array() ) : ( $options['file_optimisation'] ?? array() );
			if ( ! empty( $file_options['combineCSS'] ) ) {
				return;
			}

			global $wp_styles;

			if ( ! is_object( $wp_styles ) ) {
				return;
			}

			foreach ( $wp_styles->queue as $handle ) {
				if ( ! isset( $wp_styles->registered[ $handle ] ) ) {
					continue;
				}

				// Preserve auto-sizes containment fix (WP 6.9+) — must not be minified/combined.
				if ( 'wp-img-auto-sizes-contain' === $handle && function_exists( 'wp_enqueue_img_auto_sizes_contain_css_fix' ) ) {
					continue;
				}

				if ( $this->main->minify_is_core_block_asset_skipped( $handle ) ) {
					continue;
				}

				$style_data = $wp_styles->registered[ $handle ];
				if ( ! isset( $style_data->args ) || 'all' !== $style_data->args ) {
					continue;
				}

				$local_path = $this->get_minifiable_css_path( $handle, $style_data->src );
				if ( false === $local_path ) {
					continue;
				}

				$css_minifier = new CSS( $local_path, Util::min_cache_dir( 'css' ) );
				$cached_url   = $css_minifier->minify();
				if ( empty( $cached_url ) ) {
					continue;
				}

				$cached_file = $css_minifier->get_cache_file_path();
				if ( empty( $cached_file ) || ! file_exists( $cached_file ) ) {
					continue;
				}

				$wp_styles->registered[ $handle ]->src = $cached_url;
				$wp_styles->registered[ $handle ]->ver = (int) filemtime( $cached_file );
				wp_style_add_data( $handle, 'path', $cached_file );
			}
		}

		/**
		 * Returns the local path of a style eligible for CSS minification.
		 *
		 * @since 1.9.0
		 * @param string $handle Style handle.
		 * @param string $src Style source URL.
		 * @return string|false Local file path or false.
		 */
		private function get_minifiable_css_path( $handle, $src ) {
			if ( empty( $src ) || in_array( $handle, $this->main->minify_css_exclusions(), true ) ) {
				return false;
			}

			$local_path = Util::get_local_path( $src );
			if ( empty( $local_path ) ) {
				return false;
			}
			if ( apply_filters( 'wppo_exclude_minification', false, $local_path, $handle, 'css' ) ) {
				return false;
			}
			if ( $this->is_minified_asset_name( $src, 'css' ) || $this->is_file_minified( $local_path, 'css' ) ) {
				return false;
			}
			return $local_path;
		}

		/**
		 * Rewrites CSS link tags to use minified versions if they exist.
		 *
		 * @since 1.0.0
		 * @param string $tag Link tag HTML.
		 * @param string $handle CSS file handle.
		 * @param string $href CSS source URL.
		 * @return string Modified link tag with minified CSS.
		 */
		public function minify_css( $tag, $handle, $href ) {
			if ( 'wp-img-auto-sizes-contain' === $handle && function_exists( 'wp_enqueue_img_auto_sizes_contain_css_fix' ) ) {
				return $tag;
			}
			if ( $this->main->minify_is_core_block_asset_skipped( $handle ) ) {
				return $tag;
			}
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->main->minify_should_optimise_for_logged_in() ) {
				return $tag;
			}

			global $wp_styles;
			if ( isset( $wp_styles ) ) {
				$path_data = $wp_styles->get_data( $handle, 'path' );
				$min_dir   = Util::min_cache_base_dir();
				if ( ! empty( $path_data ) && 0 === strpos( wp_normalize_path( $path_data ), $min_dir ) ) {
					return $tag;
				}
			}

			$local_path = $this->get_minifiable_css_path( $handle, $href );
			if ( false === $local_path ) {
				return $tag;
			}

			try {
				$css_minifier = new CSS( $local_path, Util::min_cache_dir( 'css' ) );
				$cached_file  = $css_minifier->minify();
			} catch ( \Throwable $e ) {
				unset( $e );
				return $tag;
			}

			if ( $cached_file ) {
				$basename         = basename( $cached_file );
				$content_url      = Util::min_cache_url( 'css', $basename );
				$cached_file_path = $css_minifier->get_cache_file_path();
				if ( empty( $cached_file_path ) || ! file_exists( $cached_file_path ) ) {
					return $tag;
				}
				$file_version = filemtime( $cached_file_path );
				if ( false === $file_version ) {
					return $tag;
				}
				return str_replace( $href, $content_url . '?ver=' . $file_version, $tag );
			}

			return $tag;
		}

		/**
		 * Rewrites script tags to use minified versions if they exist.
		 *
		 * @since 1.0.0
		 * @param string $tag Script tag HTML.
		 * @param string $handle Script handle.
		 * @param string $src Script source URL.
		 * @return string Modified script tag with minified JavaScript.
		 */
		public function minify_js( $tag, $handle, $src ) {
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::should_disable_wppo_optimizer() ) {
				return $tag;
			}
			if ( has_filter( 'litespeed_can_optm' ) && ! apply_filters( 'litespeed_can_optm', true ) ) {
				return $tag;
			}
			if ( ! $this->main->minify_should_optimise_for_logged_in() || empty( $src ) || in_array( $handle, $this->main->minify_js_exclusions(), true ) ) {
				return $tag;
			}

			try {
				$guard_on = true;
				if ( class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( Cache::class, 'get_cache_cap_settings' ) ) {
					$cap      = Cache::get_cache_cap_settings();
					$guard_on = ! empty( $cap['randomized_guard'] );
				}
				if ( $guard_on && class_exists( 'PerformanceOptimise\Inc\Cache' ) && method_exists( Cache::class, 'is_randomized_query_asset' ) && Cache::is_randomized_query_asset( (string) $src ) ) {
					$excluded = function_exists( 'apply_filters' ) ? (bool) apply_filters( 'wppo_exclude_randomized_from_combine', true, $handle, $src ) : true;
					if ( $excluded ) {
						return $tag;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$local_path = Util::get_local_path( $src );
			if ( empty( $local_path ) ) {
				return $tag;
			}
			if ( apply_filters( 'wppo_exclude_minification', false, $local_path, $handle, 'js' ) ) {
				return $tag;
			}
			if ( $this->is_minified_asset_name( $src, 'js' ) || $this->is_js_minified( $local_path ) ) {
				return $tag;
			}

			try {
				$js_minifier = new JS( $local_path, Util::min_cache_dir( 'js' ) );
				$cached_file = $js_minifier->minify();
			} catch ( \Throwable $e ) {
				unset( $e );
				return $tag;
			}

			if ( $cached_file ) {
				$basename         = basename( $cached_file );
				$content_url      = Util::min_cache_url( 'js', $basename );
				$cached_file_path = $js_minifier->get_cache_file_path();
				if ( empty( $cached_file_path ) || ! file_exists( $cached_file_path ) ) {
					return $tag;
				}
				$file_version = filemtime( $cached_file_path );
				if ( false === $file_version ) {
					return $tag;
				}
				return str_replace( $src, $content_url . '?ver=' . $file_version, $tag );
			}

			return $tag;
		}

		/**
		 * Checks if an asset name indicates it is already minified.
		 *
		 * @since 1.5.1
		 * @param string $url_or_path Asset URL or local path.
		 * @param string $ext Asset extension.
		 * @return bool True when the name indicates minification.
		 */
		private function is_minified_asset_name( string $url_or_path, string $ext ): bool {
			if ( empty( $url_or_path ) ) {
				return false;
			}
			$path = wp_parse_url( $url_or_path, PHP_URL_PATH );
			if ( ! is_string( $path ) ) {
				$path = $url_or_path;
			}
			return (bool) preg_match( '/(\.min|\.bundle|-min)\.' . preg_quote( $ext, '/' ) . '$/i', $path );
		}

		/**
		 * Checks if a local CSS or JavaScript file is already minified.
		 *
		 * @since 1.5.1
		 * @param string $file_path File path.
		 * @param string $type Asset type.
		 * @return bool True when minified or unsafe to inspect.
		 */
		private function is_file_minified( $file_path, $type ) {
			if ( empty( $file_path ) || ! is_string( $file_path ) ) {
				return true;
			}
			$normalized = wp_normalize_path( $file_path );
			$content    = wp_normalize_path( (string) WP_CONTENT_DIR );
			$base       = wp_normalize_path( (string) ABSPATH );
			if ( 0 !== strpos( $normalized, $content . '/' ) && 0 !== strpos( $normalized, $base ) ) {
				return true;
			}
			if ( $this->is_minified_asset_name( $file_path, $type ) || ! file_exists( $file_path ) ) {
				return true;
			}
			$file_size = filesize( $file_path );
			if ( false === $file_size ) {
				return true;
			}
			$file_mtime = filemtime( $file_path );
			if ( false === $file_mtime ) {
				return true;
			}
			$cache_key   = 'min_' . $type . '_' . md5( $file_path . '|' . $file_mtime . '|' . $file_size );
			$cache_group = 'wppo_minify_check';
			$found       = false;
			$cached      = wp_cache_get( $cache_key, $cache_group, false, $found );
			if ( $found ) {
				return (bool) $cached;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$handle = fopen( $file_path, 'r' );
			if ( ! $handle ) {
				return true;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			if ( ! flock( $handle, LOCK_SH ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				fclose( $handle );
				return true;
			}
			$line_count  = 0;
			$total_chars = 0;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
			$line = fgets( $handle );
			while ( false !== $line ) {
				++$line_count;
				$total_chars += strlen( $line );
				if ( $line_count >= 50 ) {
					break;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fgets
				$line = fgets( $handle );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_flock
			flock( $handle, LOCK_UN );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $handle );
			$avg_line_length = $total_chars / max( 1, $line_count );
			$threshold       = 'css' === $type ? 500 : 1000;
			$is_minified     = $line_count <= 1 || ( $line_count <= 3 && $file_size > 1000 ) || $avg_line_length > $threshold;
			wp_cache_set( $cache_key, (int) $is_minified, $cache_group, HOUR_IN_SECONDS );
			return $is_minified;
		}

		/**
		 * Checks if a JavaScript file is already minified.
		 *
		 * @since 1.0.0
		 * @param string $file_path File path.
		 * @return bool True when minified.
		 */
		private function is_js_minified( $file_path ) {
			return $this->is_file_minified( $file_path, 'js' );
		}
	}
}
