<?php
/**
 * WP-CLI integration for Performance Optimisation plugin.
 *
 * Provides command line management for cache clearing, database cleanup,
 * settings management, and object cache flushing.
 *
 * @package PerformanceOptimise\Inc
 * @since   1.9.0
 */

namespace PerformanceOptimise\Inc;

use WP_CLI;
use WP_CLI_Command;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\WPPO_CLI_Command' ) ) {

	/**
	 * Manages Performance Optimisation features via WP-CLI.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear static HTML cache
	 *     wp wppo cache clear
	 *
	 *     # Run database cleanup for revisions
	 *     wp wppo database cleanup --type=revisions
	 *
	 *     # Get current file optimization settings
	 *     wp wppo settings get file_optimisation
	 *
	 *     # Flush Redis Object Cache
	 *     wp wppo object-cache flush
	 *
	 * @since 1.9.0
	 */
	class WPPO_CLI_Command extends WP_CLI_Command {

		/**
		 * Manage static HTML cache.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Cache action to perform: clear, preload, or status.
		 *
		 * [--page=<url>]
		 * : Optional specific page URL or relative path to clear cache for.
		 *
		 * ## EXAMPLES
		 *
		 *     # Clear all static HTML cache
		 *     wp wppo cache clear
		 *
		 *     # Clear static HTML cache for a specific page URL
		 *     wp wppo cache clear --page=/sample-page/
		 *
		 *     # Trigger cache preload
		 *     wp wppo cache preload
		 *
		 *     # Show cache status (size, pages, last cleared)
		 *     wp wppo cache status
		 *
		 * @when after_wp_load
		 * @subcommand cache
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function cache( array $args, array $assoc_args ): void {
			$action = $args[0] ?? 'clear';

			if ( 'preload' === $action ) {
				Cron::trigger_preload();
				Log::add( __( 'Cache preload triggered via WP-CLI', 'performance-optimisation' ) );
				WP_CLI::success( __( 'Cache preload initiated. Pages will be generated in batches.', 'performance-optimisation' ) );
				return;
			}

			if ( 'status' === $action ) {
				$stats = Cache::get_cache_stats();
				WP_CLI::log( sprintf( 'Cache size: %s', $stats['size'] ) );
				WP_CLI::log( sprintf( 'Cached pages: %d', $stats['cached_pages'] ) );
				WP_CLI::log( sprintf( 'Last cleared: %s', $stats['last_cleared'] ? $stats['last_cleared'] : 'never' ) );
				return;
			}

			if ( 'clear' !== $action ) {
				/* translators: %s: Cache action name */
				WP_CLI::error( sprintf( __( 'Invalid cache action "%s". Use "clear", "preload", or "status".', 'performance-optimisation' ), $action ) );
				return;
			}

			$page = $assoc_args['page'] ?? null;

			if ( $page ) {
				$path    = wp_normalize_path( trim( (string) wp_parse_url( $page, PHP_URL_PATH ), '/' ) );
				$cleared = Cache::clear_cache( $path );

				if ( $cleared ) {
					/* translators: %s: Page URL */
					Log::add( sprintf( __( 'Clear cache for %s via WP-CLI', 'performance-optimisation' ), $page ) );
					/* translators: %s: Page URL */
					WP_CLI::success( sprintf( __( 'Cache cleared for page: %s', 'performance-optimisation' ), $page ) );
				} else {
					/* translators: %s: Page URL */
					WP_CLI::error( sprintf( __( 'Failed to clear cache for page: %s', 'performance-optimisation' ), $page ) );
				}
				return;
			}

			$cleared = Cache::clear_cache();
			if ( $cleared ) {
				Log::add( __( 'Cleared all static HTML cache via WP-CLI', 'performance-optimisation' ) );
				WP_CLI::success( __( 'Static HTML cache cleared successfully.', 'performance-optimisation' ) );
			} else {
				WP_CLI::error( __( 'Failed to clear static HTML cache.', 'performance-optimisation' ) );
			}
		}

		/**
		 * Perform database cleanup and optimization routines.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Action to perform: cleanup, optimize, or counts.
		 *
		 * [--type=<type>]
		 * : Type of database cleanup routine to run.
		 * ---
		 * default: all
		 * options:
		 *   - revisions
		 *   - auto_drafts
		 *   - trashed_posts
		 *   - spam_comments
		 *   - expired_transients
		 *   - orphan_postmeta
		 *   - all
		 * ---
		 *
		 * [--tables=<tables>]
		 * : Comma-separated table identifiers for optimize action.
		 * ---
		 * default: posts,postmeta,comments,commentmeta,options
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # Clean up post revisions
		 *     wp wppo database cleanup --type=revisions
		 *
		 *     # Run all database cleanup routines
		 *     wp wppo database cleanup --type=all
		 *
		 *     # Optimize database tables
		 *     wp wppo database optimize
		 *
		 *     # Show cleanup counts
		 *     wp wppo database counts
		 *
		 * @when after_wp_load
		 * @subcommand database
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function database( array $args, array $assoc_args ): void {
			$action = $args[0] ?? 'cleanup';

			if ( 'optimize' === $action ) {
				$tables        = $assoc_args['tables'] ?? 'posts,postmeta,comments,commentmeta,options';
				$table_list    = array_map( 'trim', explode( ',', $tables ) );
				$success_count = 0;
				foreach ( $table_list as $table ) {
					$result = Database_Cleanup::optimize_table( $table );
					if ( $result ) {
						++$success_count;
						WP_CLI::log( sprintf( ' - Optimized table: %s', $table ) );
					}
				}
				/* translators: %d: Number of tables optimized */
				Log::add( sprintf( __( 'Database optimize via WP-CLI: %d tables optimized', 'performance-optimisation' ), $success_count ) );
				/* translators: 1: Number of tables optimized, 2: Total tables */
				WP_CLI::success( sprintf( __( 'Database optimization complete: %1$d/%2$d tables optimized.', 'performance-optimisation' ), $success_count, count( $table_list ) ) );
				return;
			}

			if ( 'counts' === $action ) {
				$counts = Database_Cleanup::get_counts();
				WP_CLI::log( (string) wp_json_encode( $counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			if ( 'cleanup' !== $action ) {
				/* translators: %s: Database action name */
				WP_CLI::error( sprintf( __( 'Invalid database action "%s". Use "cleanup", "optimize", or "counts".', 'performance-optimisation' ), $action ) );
				return;
			}

			$type = $assoc_args['type'] ?? 'all';

			if ( 'all' === $type ) {
				$results = Database_Cleanup::clean_all();
				$total   = 0;

				foreach ( $results as $key => $val ) {
					if ( ! is_wp_error( $val ) ) {
						$count  = (int) $val;
						$total += $count;
						WP_CLI::log( sprintf( ' - %s: %d cleaned', $key, $count ) );
					}
				}

				/* translators: %d: Total items removed */
				Log::add( sprintf( __( 'Database cleanup (all via WP-CLI): %d items removed', 'performance-optimisation' ), $total ) );
				/* translators: 1: Cleanup type, 2: Total items removed */
				WP_CLI::success( sprintf( __( 'Database cleanup completed (%1$s): %2$d total items removed.', 'performance-optimisation' ), $type, $total ) );
				return;
			}

			$cleaned_count = 0;

			switch ( $type ) {
				case 'revisions':
					$cleaned_count = Database_Cleanup::clean_revisions();
					break;
				case 'auto_drafts':
				case 'drafts':
					$cleaned_count = Database_Cleanup::clean_auto_drafts();
					break;
				case 'trashed_posts':
				case 'trash':
					$cleaned_count = Database_Cleanup::clean_trashed_posts();
					break;
				case 'spam_comments':
				case 'spam':
					$cleaned_count = Database_Cleanup::clean_spam_comments();
					break;
				case 'expired_transients':
				case 'transients':
					$cleaned_count = Database_Cleanup::clean_expired_transients();
					break;
				case 'orphan_postmeta':
				case 'orphans':
					$cleaned_count = Database_Cleanup::clean_orphan_postmeta();
					break;
				default:
					/* translators: %s: Cleanup type */
					WP_CLI::error( sprintf( __( 'Invalid cleanup type "%s".', 'performance-optimisation' ), $type ) );
					return;
			}

			if ( is_wp_error( $cleaned_count ) ) {
				WP_CLI::error( $cleaned_count->get_error_message() );
				return;
			}

			/* translators: 1: Cleanup type, 2: Number of items removed */
			Log::add( sprintf( __( 'Database cleanup (%1$s via WP-CLI): %2$d items removed', 'performance-optimisation' ), $type, (int) $cleaned_count ) );
			/* translators: 1: Cleanup type, 2: Number of items removed */
			WP_CLI::success( sprintf( __( 'Database cleanup completed for %1$s (%2$d items removed).', 'performance-optimisation' ), $type, (int) $cleaned_count ) );
		}

		/**
		 * Manage image conversion.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Action: convert or status.
		 *
		 * [--format=<format>]
		 * : Conversion format (webp or avif). Default: auto-detected from settings.
		 *
		 * ## EXAMPLES
		 *
		 *     # Convert all pending images to WebP
		 *     wp wppo image convert
		 *
		 *     # Show conversion progress
		 *     wp wppo image status
		 *
		 * @when after_wp_load
		 * @subcommand image
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function image( array $args, array $assoc_args ): void {
			$action = $args[0] ?? 'status';
			$format = $assoc_args['format'] ?? '';

			if ( 'convert' === $action ) {
				$options           = get_option( 'wppo_settings', array() );
				$img_converter     = new Img_Converter( $options );
				$img_info          = Img_Converter::get_img_info();
				$conversion_format = $format ? $format : ( $options['image_optimisation']['conversionFormat'] ?? 'webp' );

				$formats_to_process = array();
				if ( 'both' === $conversion_format ) {
					$formats_to_process = array( 'avif', 'webp' );
				} elseif ( in_array( $conversion_format, array( 'avif', 'webp' ), true ) ) {
					$formats_to_process[] = $conversion_format;
				}

				$normalized_abspath = trailingslashit( wp_normalize_path( ABSPATH ) );
				$converted          = 0;
				$total_pending      = 0;

				foreach ( $formats_to_process as $fmt ) {
					$images         = $img_info['pending'][ $fmt ] ?? array();
					$total_pending += count( $images );
					foreach ( $images as $img ) {
						$source_path = wp_normalize_path( ABSPATH . $img );
						$resolved    = realpath( $source_path );
						if ( false === $resolved || 0 !== strpos( wp_normalize_path( $resolved ), $normalized_abspath ) ) {
							continue;
						}
						$img_converter->convert_image( $source_path, $fmt );
						++$converted;
					}
				}

				/* translators: %d: Number of images processed */
				Log::add( sprintf( __( 'Image conversion via WP-CLI: %d images processed', 'performance-optimisation' ), $converted ) );
				/* translators: 1: Number of images processed, 2: Total pending */
				WP_CLI::success( sprintf( __( 'Image conversion complete: %1$d/%2$d images processed.', 'performance-optimisation' ), $converted, $total_pending ) );
				return;
			}

			if ( 'status' === $action ) {
				$img_info = Img_Converter::get_img_info();
				$output   = array(
					'total_pending'   => 0,
					'total_completed' => 0,
					'pending'         => array(),
					'completed'       => array(),
				);
				foreach ( array( 'webp', 'avif' ) as $fmt ) {
					$pending                     = $img_info['pending'][ $fmt ] ?? array();
					$completed                   = $img_info['completed'][ $fmt ] ?? array();
					$output['total_pending']    += count( $pending );
					$output['total_completed']  += count( $completed );
					$output['pending'][ $fmt ]   = count( $pending );
					$output['completed'][ $fmt ] = count( $completed );
				}
				WP_CLI::log( (string) wp_json_encode( $output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			/* translators: %s: Image action name */
			WP_CLI::error( sprintf( __( 'Invalid image action "%s". Use "convert" or "status".', 'performance-optimisation' ), $action ) );
		}

		/**
		 * View, update, export, or import plugin settings.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Settings action to perform: get, update, export, or import.
		 *
		 * [<tab>]
		 * : Settings tab name (file_optimisation, preload_settings, image_optimisation, database_cleanup, object_cache).
		 *
		 * [--settings=<json>]
		 * : JSON string containing setting key-value pairs (required for update action).
		 *
		 * [--file=<path>]
		 * : File path for export or import actions.
		 *
		 * [--format=<format>]
		 * : Output format for get action.
		 * ---
		 * default: json
		 * options:
		 *   - json
		 *   - yaml
		 * ---
		 *
		 * ## EXAMPLES
		 *
		 *     # View all plugin settings
		 *     wp wppo settings get
		 *
		 *     # View file optimization settings
		 *     wp wppo settings get file_optimisation
		 *
		 *     # Enable HTML minification via settings update
		 *     wp wppo settings update file_optimisation --settings='{"minifyHTML":true}'
		 *
		 *     # Export settings to file
		 *     wp wppo settings export --file=/tmp/wppo-settings.json
		 *
		 *     # Import settings from file
		 *     wp wppo settings import --file=/tmp/wppo-settings.json
		 *
		 * @when after_wp_load
		 * @subcommand settings
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function settings( array $args, array $assoc_args ): void {
			$action = $args[0] ?? 'get';
			$tab    = $args[1] ?? null;

			$options = get_option( 'wppo_settings', array() );

			if ( 'export' === $action ) {
				$export_data = $options;

				// Strip sensitive keys.
				if ( isset( $export_data['object_cache']['password'] ) ) {
					unset( $export_data['object_cache']['password'] );
				}
				if ( isset( $export_data['performance_audit']['pagespeed_api_key'] ) ) {
					unset( $export_data['performance_audit']['pagespeed_api_key'] );
				}

				$file = $assoc_args['file'] ?? null;
				if ( $file ) {
					Util::init_filesystem();
					global $wp_filesystem;
					if ( ! $wp_filesystem ) {
						WP_CLI::error( __( 'Unable to initialize filesystem.', 'performance-optimisation' ) );
						return;
					}
					$json = wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
					if ( ! $wp_filesystem->put_contents( $file, $json, FS_CHMOD_FILE ) ) {
						WP_CLI::error( __( 'Failed to write settings to file.', 'performance-optimisation' ) );
						return;
					}
					/* translators: %s: File path */
					WP_CLI::success( sprintf( __( 'Settings exported to %s', 'performance-optimisation' ), $file ) );
				} else {
					WP_CLI::log( (string) wp_json_encode( $export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				}
				return;
			}

			if ( 'import' === $action ) {
				$file = $assoc_args['file'] ?? null;
				if ( ! $file ) {
					WP_CLI::error( __( 'Please provide a --file=<path> parameter.', 'performance-optimisation' ) );
					return;
				}

				Util::init_filesystem();
				global $wp_filesystem;
				if ( ! $wp_filesystem || ! $wp_filesystem->exists( $file ) ) {
					WP_CLI::error( __( 'File not found or filesystem unavailable.', 'performance-optimisation' ) );
					return;
				}

				$json         = $wp_filesystem->get_contents( $file );
				$new_settings = json_decode( $json, true );
				if ( ! is_array( $new_settings ) ) {
					WP_CLI::error( __( 'Invalid JSON in settings file.', 'performance-optimisation' ) );
					return;
				}

				// Validate allowed keys (mirrors REST endpoint logic).
				$allowed_keys = array( 'file_optimisation', 'preload_settings', 'image_optimisation', 'database_cleanup', 'object_cache', 'performance_audit', 'core_tweaks', 'cache_settings' );
				foreach ( array_keys( $new_settings ) as $key ) {
					if ( ! in_array( $key, $allowed_keys, true ) ) {
						/* translators: %s: Setting key name */
						WP_CLI::error( sprintf( __( 'Invalid setting key "%s" detected.', 'performance-optimisation' ), $key ) );
						return;
					}
				}

				// Handle Redis password.
				if ( isset( $new_settings['object_cache']['password'] ) ) {
					$password_provided = ! empty( $new_settings['object_cache']['password'] );
					unset( $new_settings['object_cache']['password'] );
					if ( $password_provided ) {
						$new_settings['object_cache']['password_set'] = true;
					}
				}
				// Strip API key from audit tab.
				if ( isset( $new_settings['performance_audit'] ) ) {
					unset( $new_settings['performance_audit']['pagespeed_api_key'] );
				}

				$existing_settings = get_option( 'wppo_settings', array() );
				$merged_settings   = array_replace_recursive( $existing_settings, $new_settings );
				update_option( 'wppo_settings', $merged_settings );

				Log::add( __( 'Settings imported via WP-CLI', 'performance-optimisation' ) );
				WP_CLI::success( __( 'Settings imported successfully.', 'performance-optimisation' ) );
				return;
			}

			if ( 'get' === $action ) {
				if ( $tab ) {
					if ( ! isset( $options[ $tab ] ) ) {
						/* translators: 1: Requested tab name, 2: List of available tabs */
						WP_CLI::error( sprintf( __( 'Invalid settings tab "%1$s". Available tabs: %2$s.', 'performance-optimisation' ), $tab, implode( ', ', array_keys( $options ) ) ) );
						return;
					}
					$data = $options[ $tab ];
				} else {
					$data = $options;
				}

				$format = $assoc_args['format'] ?? 'json';

				if ( 'yaml' === $format ) {
					if ( class_exists( 'Spyc' ) ) {
						WP_CLI::log( \Spyc::YAMLDump( $data, 2, 0 ) );
					} elseif ( function_exists( 'yaml_emit' ) ) {
						WP_CLI::log( yaml_emit( $data ) );
					} else {
						WP_CLI::warning( __( 'YAML dumper not available; falling back to JSON format.', 'performance-optimisation' ) );
						WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
					}
				} else {
					WP_CLI::log( (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				}
				return;
			}

			if ( 'update' === $action ) {
				if ( ! $tab ) {
					WP_CLI::error( __( 'Please specify a settings tab name to update (e.g. wp wppo settings update file_optimisation --settings=\'{"minifyHTML":true}\').', 'performance-optimisation' ) );
					return;
				}

				$json = $assoc_args['settings'] ?? null;
				if ( ! $json ) {
					WP_CLI::error( __( 'Please provide a JSON object string via --settings parameter.', 'performance-optimisation' ) );
					return;
				}

				$new_settings = json_decode( $json, true );
				if ( ! is_array( $new_settings ) ) {
					WP_CLI::error( __( 'Invalid JSON settings provided.', 'performance-optimisation' ) );
					return;
				}

				$known_tabs = array( 'file_optimisation', 'preload_settings', 'image_optimisation', 'database_cleanup', 'object_cache', 'performance_audit', 'cache_settings', 'core_tweaks' );
				if ( ! in_array( $tab, $known_tabs, true ) ) {
					/* translators: %s: Settings tab name */
					WP_CLI::warning( sprintf( __( 'Unrecognized settings tab "%s". Settings will be saved but the plugin may not read them.', 'performance-optimisation' ), $tab ) );
				}

				if ( ! isset( $options[ $tab ] ) || ! is_array( $options[ $tab ] ) ) {
					$options[ $tab ] = array();
				}

				$options[ $tab ] = array_merge( $options[ $tab ], $new_settings );
				update_option( 'wppo_settings', $options );

				/* translators: %s: Settings tab name */
				Log::add( sprintf( __( 'Updated plugin settings for tab %s via WP-CLI', 'performance-optimisation' ), $tab ) );
				/* translators: %s: Settings tab name */
				WP_CLI::success( sprintf( __( 'Settings updated successfully for tab "%s".', 'performance-optimisation' ), $tab ) );
				return;
			}

			/* translators: %s: Settings action name */
			WP_CLI::error( sprintf( __( 'Invalid settings action "%s". Use "get", "update", "export", or "import".', 'performance-optimisation' ), $action ) );
		}

		/**
		 * Manage Redis Object Cache.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Object Cache action to perform: status, ping, enable, disable, or flush.
		 *
		 * [--host=<host>]
		 * : Redis server hostname (for ping/enable).
		 *
		 * [--port=<port>]
		 * : Redis server port (for ping/enable).
		 *
		 * [--password=<password>]
		 * : Redis server password (for ping/enable).
		 *
		 * [--database=<database>]
		 * : Redis database index (for ping/enable).
		 *
		 * [--timeout=<timeout>]
		 * : Connection timeout in seconds (for ping/enable).
		 *
		 * [--prefix=<prefix>]
		 * : Cache key prefix (for ping/enable).
		 *
		 * ## EXAMPLES
		 *
		 *     # Show Redis status
		 *     wp wppo object-cache status
		 *
		 *     # Ping Redis server
		 *     wp wppo object-cache ping --host=127.0.0.1 --port=6379
		 *
		 *     # Enable Redis Object Cache
		 *     wp wppo object-cache enable --host=127.0.0.1 --port=6379
		 *
		 *     # Disable Redis Object Cache
		 *     wp wppo object-cache disable
		 *
		 *     # Flush Redis Object Cache
		 *     wp wppo object-cache flush
		 *
		 * @when after_wp_load
		 * @subcommand object-cache
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function object_cache( array $args, array $assoc_args ): void {
			$action  = $args[0] ?? 'status';
			$manager = new Object_Cache();

			switch ( $action ) {
				case 'status':
					$status = $manager->get_status();
					WP_CLI::log( (string) wp_json_encode( $status, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
					return;

				case 'ping':
					$config = self::get_redis_config_from_assoc( $assoc_args );
					$result = $manager->ping( $config );
					if ( is_wp_error( $result ) ) {
						WP_CLI::error( $result->get_error_message() );
					} else {
						WP_CLI::success( __( 'Redis server is reachable.', 'performance-optimisation' ) );
					}
					return;

				case 'enable':
					$config = self::get_redis_config_from_assoc( $assoc_args );
					$result = $manager->enable( $config );
					if ( is_wp_error( $result ) ) {
						WP_CLI::error( $result->get_error_message() );
					} else {
						Log::add( __( 'Redis Object Cache enabled via WP-CLI', 'performance-optimisation' ) );
						WP_CLI::success( __( 'Redis Object Cache enabled successfully.', 'performance-optimisation' ) );
					}
					return;

				case 'disable':
					$result = $manager->disable();
					if ( is_wp_error( $result ) ) {
						WP_CLI::error( $result->get_error_message() );
					} else {
						Log::add( __( 'Redis Object Cache disabled via WP-CLI', 'performance-optimisation' ) );
						WP_CLI::success( __( 'Redis Object Cache disabled successfully.', 'performance-optimisation' ) );
					}
					return;

				case 'flush':
					$success = $manager->flush();
					if ( $success ) {
						Log::add( __( 'Flushed Redis Object Cache via WP-CLI', 'performance-optimisation' ) );
						WP_CLI::success( __( 'Redis Object Cache flushed successfully.', 'performance-optimisation' ) );
					} else {
						WP_CLI::error( __( 'Failed to flush Redis Object Cache.', 'performance-optimisation' ) );
					}
					return;

				default:
					/* translators: %s: Object cache action name */
					WP_CLI::error( sprintf( __( 'Invalid object-cache action "%s". Use "status", "ping", "enable", "disable", or "flush".', 'performance-optimisation' ), $action ) );
			}
		}

		/**
		 * Build a Redis config array from CLI associative arguments.
		 *
		 * @param array $assoc_args The associative arguments from the command.
		 * @return array<string, mixed> Redis connection configuration.
		 */
		private static function get_redis_config_from_assoc( array $assoc_args ): array {
			$config = array();
			foreach ( array( 'host', 'port', 'password', 'database', 'timeout', 'prefix' ) as $key ) {
				if ( isset( $assoc_args[ $key ] ) ) {
					$config[ $key ] = $assoc_args[ $key ];
				}
			}
			return $config;
		}

		/**
		 * Manage Google PageSpeed scans.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Action: scan or results.
		 *
		 * [--url=<url>]
		 * : Page URL to scan.
		 *
		 * [--strategy=<strategy>]
		 * : Device strategy: mobile or desktop. Default: mobile.
		 *
		 * ## EXAMPLES
		 *
		 *     # Queue a PageSpeed scan
		 *     wp wppo pagespeed scan --url=https://example.com
		 *
		 *     # Get PageSpeed results
		 *     wp wppo pagespeed results --url=https://example.com
		 *
		 * @when after_wp_load
		 * @subcommand pagespeed
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function pagespeed( array $args, array $assoc_args ): void {
			$action   = $args[0] ?? 'scan';
			$url      = $assoc_args['url'] ?? home_url();
			$strategy = $assoc_args['strategy'] ?? 'mobile';

			if ( 'scan' === $action ) {
				$job_id = Pagespeed::queue_scan( $url, $strategy );
				/* translators: %s: Scanned URL */
				Log::add( sprintf( __( 'PageSpeed scan queued via WP-CLI for %s', 'performance-optimisation' ), $url ) );
				/* translators: %d: Job ID */
				WP_CLI::success( sprintf( __( 'PageSpeed scan queued. Job ID: %d', 'performance-optimisation' ), $job_id ) );
				return;
			}

			if ( 'results' === $action ) {
				$results = Pagespeed::get_results( $url, $strategy );
				if ( false === $results ) {
					WP_CLI::warning( __( 'No PageSpeed results found for the given URL and strategy.', 'performance-optimisation' ) );
					return;
				}
				WP_CLI::log( (string) wp_json_encode( $results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			/* translators: %s: Pagespeed action name */
			WP_CLI::error( sprintf( __( 'Invalid pagespeed action "%s". Use "scan" or "results".', 'performance-optimisation' ), $action ) );
		}

		/**
		 * Show system information.
		 *
		 * ## OPTIONS
		 *
		 * [<group>]
		 * : Optional group filter: php, database, WordPress, wp_constants, server, cache, infrastructure.
		 *
		 * ## EXAMPLES
		 *
		 *     # Show all system info
		 *     wp wppo system-info
		 *
		 *     # Show only PHP info
		 *     wp wppo system-info php
		 *
		 * @when after_wp_load
		 * @subcommand system-info
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function system_info( array $args, array $assoc_args ): void {
			$all   = System_Info::get_all();
			$group = $args[0] ?? null;

			if ( $group ) {
				if ( ! isset( $all[ $group ] ) ) {
					/* translators: %s: System info group name */
					WP_CLI::error( sprintf( __( 'Invalid system info group "%s".', 'performance-optimisation' ), $group ) );
					return;
				}
				WP_CLI::log( (string) wp_json_encode( $all[ $group ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			WP_CLI::log( (string) wp_json_encode( $all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}
}
