<?php
/**
 * Performant Translations — .mo → .php compilation.
 *
 * Compiles .mo files to .php via load_textdomain filter when
 * `wp_cache_get_salted` exists (WP 6.9+ salted cache family), gated by
 * `perf_translations.enabled` (false default). Generated per-locale files live
 * under `wp-content/cache/wppo/lang/` (blog-scoped on multisite) and leverage
 * OPCache. Uses `WP_Translation_File::transform()` when available.
 *
 * Mirrors Performance Lab Performant Translations parity but stores compiled
 * files in the plugin cache directory (not next to source .mo) to avoid
 * writing to plugins/languages. Original .mo remains the fallback.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Perf_Translations' ) ) {
	/**
	 * Perf Translations handler.
	 *
	 * @since NEXT
	 */
	class Perf_Translations {

		/**
		 * Filter for enabling perf translations.
		 *
		 * @since NEXT
		 * @var string
		 */
		const FILTER_ENABLED = 'wppo_perf_translations_enabled';

		/**
		 * Whether perf translations is enabled.
		 *
		 * Requires `perf_translations.enabled` true and `wp_cache_get_salted`
		 * existence (WP 6.9+). Filter `wppo_perf_translations_enabled` may
		 * override.
		 *
		 * @since NEXT
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$settings = Util::get_settings();
			$enabled  = false;
			if ( isset( $settings['perf_translations'] ) && is_array( $settings['perf_translations'] ) && isset( $settings['perf_translations']['enabled'] ) ) {
				$enabled = (bool) $settings['perf_translations']['enabled'];
			}
			/**
			 * Filters whether Performant Translations (.mo→php) is enabled.
			 *
			 * @since NEXT
			 * @param bool $enabled Whether enabled.
			 */
			$enabled = (bool) apply_filters( self::FILTER_ENABLED, $enabled );
			if ( ! $enabled ) {
				return false;
			}
			// Gate on WP 6.9+ salted cache family per spec.
			if ( ! function_exists( 'wp_cache_get_salted' ) ) {
				return false;
			}
			return true;
		}

		/**
		 * Base cache directory for compiled translation files.
		 *
		 * Blog-scoped on multisite (`site-{id}/`) mirroring Llms::base_dir().
		 *
		 * @since NEXT
		 * @return string Normalized path.
		 */
		public static function get_cache_dir(): string {
			$base = WP_CONTENT_DIR . '/cache/wppo/lang';
			if ( function_exists( 'is_multisite' ) && is_multisite() ) {
				$base .= '/site-' . get_current_blog_id();
			}
			return wp_normalize_path( $base );
		}

		/**
		 * Derive a cache file path for a given .mo file and textdomain.
		 *
		 * Uses sanitized domain + locale + hash to guarantee uniqueness across
		 * plugins/themes sharing a basename. Locale is extracted from the .mo
		 * filename suffix (e.g. `my-plugin-de_DE.mo` → `de_DE`). Falls back to
		 * hash-only when extraction fails.
		 *
		 * @since NEXT
		 * @param string $mofile Path to .mo file.
		 * @param string $domain Text domain.
		 * @return string Normalized cache file path (.php).
		 */
		public static function get_cache_file( string $mofile, string $domain ): string {
			$dir      = self::get_cache_dir();
			$basename = basename( $mofile, '.mo' );
			$locale   = '';
			// Extract locale suffix: dash + locale pattern at end.
			if ( preg_match( '/-([a-z]{2,3}(?:_[A-Z]{2})?(?:_[a-z0-9]+)?)$/i', $basename, $m ) ) {
				$locale = $m[1];
			}
			$domain_key = sanitize_key( $domain );
			if ( '' === $domain_key ) {
				$domain_key = 'default';
			}
			$hash = substr( md5( $mofile ), 0, 8 );
			if ( '' !== $locale ) {
				$filename = $domain_key . '-' . sanitize_key( $locale ) . '-' . $hash . '.l10n.php';
			} else {
				$filename = $domain_key . '-' . sanitize_key( $basename ) . '-' . $hash . '.l10n.php';
			}
			return trailingslashit( $dir ) . $filename;
		}

		/**
		 * Filter callback for `load_textdomain_mofile` and `load_translation_file`.
		 *
		 * When enabled and the file is a readable .mo, compiles it to .php in
		 * the cache dir via `WP_Translation_File::transform()` and returns the
		 * cached php path when successful and newer than the source. Otherwise
		 * returns the original path.
		 *
		 * @since NEXT
		 * @param string $file Path to translation file (.mo or .php).
		 * @param string $domain Text domain.
		 * @return string Filtered path.
		 */
		public static function filter_load_file( string $file, string $domain ): string {
			if ( ! self::is_enabled() ) {
				return $file;
			}
			if ( '' === $file || ! str_ends_with( $file, '.mo' ) ) {
				return $file;
			}
			if ( ! file_exists( $file ) || ! is_readable( $file ) ) {
				return $file;
			}
			if ( ! class_exists( 'WP_Translation_File' ) ) {
				return $file;
			}
			$cache_file = self::get_cache_file( $file, $domain );

			// Serve cached if fresh.
			if ( file_exists( $cache_file ) ) {
				$mo_time    = filemtime( $file );
				$cache_time = filemtime( $cache_file );
				if ( false !== $mo_time && false !== $cache_time && $cache_time >= $mo_time ) {
					return $cache_file;
				}
			}

			$contents = \WP_Translation_File::transform( $file, 'php' );
			if ( false === $contents ) {
				return $file;
			}

			$dir     = dirname( $cache_file );
			$fs      = Util::init_filesystem();
			$written = false;
			if ( $fs && method_exists( $fs, 'put_contents' ) ) {
				if ( ! $fs->is_dir( $dir ) ) {
					Util::prepare_cache_dir( $dir );
				}
				$written = $fs->put_contents( $cache_file, $contents, FS_CHMOD_FILE );
			} else {
				if ( ! is_dir( $dir ) ) {
					if ( function_exists( 'wp_mkdir_p' ) ) {
						wp_mkdir_p( $dir );
					} else {
						@mkdir( $dir, 0775, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir,WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
				$written = false !== file_put_contents( $cache_file, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			}

			if ( $written ) {
				if ( function_exists( 'wp_opcache_invalidate' ) ) {
					wp_opcache_invalidate( $cache_file, true );
				} elseif ( function_exists( 'opcache_invalidate' ) ) {
					@opcache_invalidate( $cache_file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}
				/**
				 * Fires after a perf translation file was written.
				 *
				 * @since NEXT
				 * @param string $cache_file Path to the compiled php file.
				 * @param string $mofile Source .mo file.
				 * @param string $domain Text domain.
				 */
				do_action( 'wppo_perf_translations_file_written', $cache_file, $file, $domain );
				return $cache_file;
			}

			return $file;
		}

		/**
		 * Invalidate OPCache after file write (action handler).
		 *
		 * @since NEXT
		 * @param string $file File path.
		 * @return void
		 */
		public static function opcache_invalidate( string $file ): void {
			if ( function_exists( 'wp_opcache_invalidate' ) ) {
				wp_opcache_invalidate( $file, true );
			} elseif ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $file, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		/**
		 * Register hooks.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function init(): void {
			// Both filters are supported: 6.5+ `load_translation_file` and legacy `load_textdomain_mofile`.
			add_filter( 'load_translation_file', array( self::class, 'filter_load_file' ), 10, 2 );
			add_filter( 'load_textdomain_mofile', array( self::class, 'filter_load_file' ), 10, 2 );
			add_action( 'wppo_perf_translations_file_written', array( self::class, 'opcache_invalidate' ) );
			// Regenerate on translation upgrades (language pack updates).
			add_action( 'upgrader_process_complete', array( self::class, 'on_upgrader_complete' ), 10, 2 );
		}

		/**
		 * On language pack upgrades, the compiled cache should be purged so it
		 * regenerates fresh. We delete per-locale cache files matching the
		 * upgraded language; the next load_translation_file call will regenerate.
		 *
		 * @since NEXT
		 * @param mixed $upgrader Upgrader instance.
		 * @param array $hook_extra Extra data.
		 * @return void
		 */
		public static function on_upgrader_complete( $upgrader, $hook_extra ): void {
			if ( ! isset( $hook_extra['type'] ) || 'translation' !== $hook_extra['type'] ) {
				return;
			}
			$dir = self::get_cache_dir();
			if ( ! is_dir( $dir ) ) {
				return;
			}
			// Conservative: clear the whole compiled cache on any translation update.
			$fs = Util::init_filesystem();
			if ( $fs && method_exists( $fs, 'dirlist' ) ) {
				$list = $fs->dirlist( $dir, false, false );
				if ( is_array( $list ) ) {
					foreach ( $list as $name => $info ) {
						$path = trailingslashit( $dir ) . $name;
						if ( is_string( $name ) && str_ends_with( $name, '.php' ) ) {
							$fs->delete( $path, false );
						}
					}
				}
			} else {
				$files = glob( trailingslashit( $dir ) . '*.php' );
				if ( is_array( $files ) ) {
					foreach ( $files as $f ) {
						@unlink( $f ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged
					}
				}
			}
		}
	}
}
