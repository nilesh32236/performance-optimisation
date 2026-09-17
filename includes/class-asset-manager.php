<?php
/**
 * Asset Manager functionality.
 *
 * Provides per-page/post control over which scripts and styles are loaded
 * on the frontend. Admins can selectively disable assets from the post
 * editor meta box.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.1.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Asset_Manager' ) ) {
	/**
	 * Class Asset_Manager
	 *
	 * Handles capturing of enqueued assets and dequeuing disabled ones
	 * based on per-post meta data.
	 *
	 * @since 1.1.0
	 */
	class Asset_Manager {
		/**
		 * Prefix for the per-page asset transient key.
		 *
		 * @var   string
		 * @since 1.1.0
		 */
		const TRANSIENT_PREFIX = 'wppo_page_assets_';

		/**
		 * Core WordPress script handles that should never be deregistered.
		 *
		 * @var   array
		 * @since 1.1.0
		 */
		private static array $protected_scripts = array(
			'jquery',
			'jquery-core',
			'jquery-migrate',
			'wp-i18n',
			'wp-hooks',
			'wp-api-fetch',
			'wp-url',
			'wp-polyfill',
			'admin-bar',
			'heartbeat',
		);

		/**
		 * Core WordPress style handles that should never be deregistered.
		 *
		 * @var   array
		 * @since 1.1.0
		 */
		private static array $protected_styles = array(
			'admin-bar',
			'dashicons',
			'wp-block-library',
		);

		/**
		 * Per-request memoization cache for resolved asset sizes, keyed by src.
		 *
		 * Avoids repeating file_exists()+filesize() stat syscalls for the same
		 * src when capture_page_assets() runs on the wp_footer hot path.
		 *
		 * @var   array<string,int|null>
		 * @since NEXT
		 */
		private static array $size_cache = array();

		/**
		 * Constructor.
		 *
		 * Registers the hooks for asset dequeuing and capturing.
		 *
		 * Hooks are frontend-only: in the admin area the metabox reads
		 * captured assets through the static getters, so no instance hooks
		 * are registered there (keeps the manager code off admin hot paths).
		 *
		 * @since 1.1.0
		 */
		public function __construct() {
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				return;
			}
			// Capture assets after they've been printed so $done arrays are populated.
			add_action( 'wp_footer', array( $this, 'capture_page_assets' ), 9999 );

			// Dequeue disabled assets on the frontend at a very late priority.
			add_action( 'wp_enqueue_scripts', array( $this, 'dequeue_selected_assets' ), 9999 );
		}

		/**
		 * Dequeue and deregister assets that the admin has disabled for this post.
		 *
		 * Only runs on the frontend, not in the admin area.
		 *
		 * @since 1.1.0
		 */
		public function dequeue_selected_assets() {
			$is_sandbox_preview = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Main' ) && method_exists( 'PerformanceOptimise\Inc\Main', 'is_sandbox_preview_active' ) ) {
					$is_sandbox_preview = (bool) Main::is_sandbox_preview_active();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$is_sandbox_preview = false;
			}
			if ( is_admin() ) {
				return;
			}
			// Sandbox preview (issue #1163): preview admins render staged
			// dequeue output; every other logged-in user keeps production
			// markup so visitors never see experimental output.
			if ( is_user_logged_in() && ! $is_sandbox_preview ) {
				return;
			}

			if ( ! is_singular() ) {
				return;
			}

			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return;
			}

			$disabled_scripts = get_post_meta( $post_id, '_wppo_disabled_scripts', true );
			$disabled_styles  = get_post_meta( $post_id, '_wppo_disabled_styles', true );

			if ( ! empty( $disabled_scripts ) && is_array( $disabled_scripts ) ) {
				foreach ( $disabled_scripts as $handle ) {
					if ( ! in_array( $handle, self::$protected_scripts, true ) ) {
						wp_dequeue_script( $handle );
						wp_deregister_script( $handle );
					}
				}
			}

			if ( ! empty( $disabled_styles ) && is_array( $disabled_styles ) ) {
				foreach ( $disabled_styles as $handle ) {
					if ( ! in_array( $handle, self::$protected_styles, true ) ) {
						wp_dequeue_style( $handle );
						wp_deregister_style( $handle );
					}
				}
			}
		}

		/**
		 * Capture all enqueued scripts and styles on the current page.
		 *
		 * Stores the list as a transient keyed by post ID so the admin meta box
		 * can display them.
		 *
		 * @since 1.1.0
		 */
		public function capture_page_assets() {
			if ( is_admin() ) {
				return;
			}

			if ( ! is_singular() ) {
				return;
			}

			$post_id = get_the_ID();
			if ( ! $post_id ) {
				return;
			}

			global $wp_scripts, $wp_styles;

			$scripts = array();
			$styles  = array();

			if ( $wp_scripts instanceof \WP_Scripts ) {
				$per_page_strategies = get_post_meta( $post_id, '_wppo_delay_strategies', true );
				$per_page_priorities = get_post_meta( $post_id, '_wppo_delay_priorities', true );

				if ( ! is_array( $per_page_strategies ) ) {
					$per_page_strategies = array();
				}
				if ( ! is_array( $per_page_priorities ) ) {
					$per_page_priorities = array();
				}

				foreach ( $wp_scripts->done as $handle ) {
					if ( isset( $wp_scripts->registered[ $handle ] ) ) {
						$registered = $wp_scripts->registered[ $handle ];
						$src        = $registered->src ? $registered->src : '';
						$scripts[]  = array(
							'handle'         => $handle,
							'src'            => $src,
							'deps'           => $registered->deps,
							'delay_strategy' => $per_page_strategies[ $handle ] ?? null,
							'delay_priority' => $per_page_priorities[ $handle ] ?? null,
							'size'           => self::resolve_asset_size( $src ),
						);
					}
				}
			}

			if ( $wp_styles instanceof \WP_Styles ) {
				foreach ( $wp_styles->done as $handle ) {
					if ( isset( $wp_styles->registered[ $handle ] ) ) {
						$registered = $wp_styles->registered[ $handle ];
						$src        = $registered->src ? $registered->src : '';
						$styles[]   = array(
							'handle' => $handle,
							'src'    => $src,
							'deps'   => $registered->deps,
							'size'   => self::resolve_asset_size( $src ),
						);
					}
				}
			}

			$assets = array(
				'scripts'   => $scripts,
				'styles'    => $styles,
				'timestamp' => time(),
			);

			$existing_assets = self::get_page_assets( $post_id );
			$has_changed     = true;

			if ( is_array( $existing_assets ) && isset( $existing_assets['scripts'], $existing_assets['styles'] ) ) {
				if ( $existing_assets['scripts'] === $scripts && $existing_assets['styles'] === $styles ) {
					$has_changed = false;
				}
			}

			if ( $has_changed ) {
				// Store for 24 hours, keyed by post ID.
				set_transient( Util::transient_key( self::TRANSIENT_PREFIX . $post_id ), $assets, DAY_IN_SECONDS );
			}
		}

		/**
		 * Resolve the on-disk size of an enqueued asset source.
		 *
		 * Fail-open helper for {@see capture_page_assets()}: only local files
		 * are measured (via the existing {@see Util::get_local_path()} helper
		 * so ABSPATH confinement still applies). Remote/CDN URLs, missing
		 * files, and any internal failure resolve to `null` — never a remote
		 * HEAD/HTTP request on the `wp_footer` hot path, never fatal.
		 *
		 * The `wppo_page_asset_size` filter offers an escape hatch; returning
		 * a non-null integer overrides the measured value, returning `null`
		 * keeps the legacy (unknown-size) fallback.
		 *
		 * @param  string $src The registered asset `src` (URL or path).
		 * @since  NEXT
		 * @return int|null Local file size in bytes, or null when unknown.
		 */
		public static function resolve_asset_size( $src ) {
			try {
				if ( ! is_string( $src ) || '' === $src ) {
					return null;
				}
				if ( array_key_exists( $src, self::$size_cache ) ) {
					return self::$size_cache[ $src ];
				}
				if ( function_exists( 'has_filter' ) && has_filter( 'wppo_page_asset_size' ) ) {
					$filtered = apply_filters( 'wppo_page_asset_size', null, $src );
					if ( is_int( $filtered ) && $filtered >= 0 ) {
						self::$size_cache[ $src ] = $filtered;
						return $filtered;
					}
					// Any other non-null filter value (numeric string, false,
					// etc.) is ignored so one misbehaving callback degrades
					// to the local measurement below instead of blanking
					// every size.
				}
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_local_path' ) ) {
					return null;
				}
				$local_path = Util::get_local_path( $src );
				if ( '' === $local_path || ! file_exists( $local_path ) ) {
					self::$size_cache[ $src ] = null;
					return null;
				}
				$size = filesize( $local_path );
				if ( false === $size || $size < 0 ) {
					self::$size_cache[ $src ] = null;
					return null;
				}
				$resolved                 = (int) $size;
				self::$size_cache[ $src ] = $resolved;
				return $resolved;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Suggest heavy, non-protected assets worth reviewing for this page.
		 *
		 * Read-only assistant: returns candidate handles ordered by size
		 * (largest first) so the UI can *suggest* disables. It never writes
		 * post meta and never auto-disables anything — the caller renders
		 * suggestions as text only.
		 *
		 * @param  array $assets Captured page assets (`scripts`/`styles` lists).
		 * @param  int   $threshold Minimum size in bytes for a suggestion. Defaults to 50 KB.
		 * @since  NEXT
		 * @return array List of `array( 'type' => 'script'|'style', 'handle' => string, 'size' => int )`.
		 */
		public static function get_asset_suggestions( $assets, $threshold = 51200 ) {
			$suggestions = array();
			try {
				if ( ! is_array( $assets ) ) {
					return $suggestions;
				}
				$threshold = is_numeric( $threshold ) && (int) $threshold >= 0 ? (int) $threshold : 51200;
				if ( function_exists( 'has_filter' ) && has_filter( 'wppo_asset_suggestion_threshold' ) ) {
					$filtered_threshold = apply_filters( 'wppo_asset_suggestion_threshold', $threshold );
					if ( is_numeric( $filtered_threshold ) && (int) $filtered_threshold >= 0 ) {
						$threshold = (int) $filtered_threshold;
					}
				}
				foreach ( array(
					'scripts' => 'script',
					'styles'  => 'style',
				) as $group => $type ) {
					if ( empty( $assets[ $group ] ) || ! is_array( $assets[ $group ] ) ) {
						continue;
					}
					$protected = 'script' === $type ? self::$protected_scripts : self::$protected_styles;
					foreach ( $assets[ $group ] as $asset ) {
						if ( ! is_array( $asset ) || empty( $asset['handle'] ) || ! is_string( $asset['handle'] ) ) {
							continue;
						}
						if ( in_array( $asset['handle'], $protected, true ) ) {
							continue;
						}
						$size = $asset['size'] ?? null;
						if ( ! is_int( $size ) || $size < $threshold ) {
							continue;
						}
						$suggestions[] = array(
							'type'   => $type,
							'handle' => $asset['handle'],
							'size'   => $size,
						);
					}
				}
				usort(
					$suggestions,
					static function ( $a, $b ) {
						return $b['size'] <=> $a['size'];
					}
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
			return $suggestions;
		}

		/**
		 * Get captured assets for a specific post.
		 *
		 * @param  int $post_id The post ID to get assets for.
		 * @since  1.1.0
		 * @return array|false The captured assets array, or false if not found.
		 */
		public static function get_page_assets( $post_id ) {
			return get_transient( Util::transient_key( self::TRANSIENT_PREFIX . $post_id ) );
		}

		/**
		 * Get the list of protected script handles.
		 *
		 * @since  1.1.0
		 * @return array List of protected script handles.
		 */
		public static function get_protected_scripts() {
			return self::$protected_scripts;
		}

		/**
		 * Get the list of protected style handles.
		 *
		 * @since  1.1.0
		 * @return array List of protected style handles.
		 */
		public static function get_protected_styles() {
			return self::$protected_styles;
		}
	}
}
