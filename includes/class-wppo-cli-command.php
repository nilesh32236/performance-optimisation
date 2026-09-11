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
		 * [<action>]
		 * : Cache action to perform.
		 * ---
		 * default: clear
		 * options:
		 *   - clear
		 *   - preload
		 *   - status
		 * ---
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
		 * [<action>]
		 * : Action to perform.
		 * ---
		 * default: cleanup
		 * options:
		 *   - cleanup
		 *   - optimize
		 *   - counts
		 * ---
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
		 *   - trashed_comments
		 *   - expired_transients
		 *   - orphan_postmeta
		 *   - unattached_media
		 *   - oembed_cache
		 *   - all
		 * ---
		 *
		 * [--tables=<tables>]
		 * : Comma-separated table identifiers for optimize action.
		 * ---
		 * default: posts,postmeta,comments,commentmeta,options
		 * ---
		 *
		 * [--format=<format>]
		 * : Output format for counts action.
		 * ---
		 * default: json
		 * options:
		 *   - json
		 * ---
		 *
		 * [--yes]
		 * : Skip confirmation prompt for --type=all (destructive).
		 *
		 * [--dry-run]
		 * : Preview what would be deleted without executing (logs would_delete JSON, no DELETE/OPTIMIZE).
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

			// Resolve --dry-run via WP_CLI\Utils::get_flag_value when available.
			$dry_run = false;
			if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
				$dry_run = (bool) \WP_CLI\Utils::get_flag_value( $assoc_args, 'dry-run', false );
			} elseif ( isset( $assoc_args['dry-run'] ) ) {
				$dry_run = (bool) $assoc_args['dry-run'];
			}

			if ( 'optimize' === $action ) {
				if ( $dry_run ) {
					$tables     = $assoc_args['tables'] ?? 'posts,postmeta,comments,commentmeta,options';
					$table_list = array_map( 'trim', explode( ',', $tables ) );
					WP_CLI::log( (string) wp_json_encode( array( 'would_optimize' => $table_list ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
					WP_CLI::warning( __( 'Dry run — no tables optimized.', 'performance-optimisation' ) );
					return;
				}
				$tables         = $assoc_args['tables'] ?? 'posts,postmeta,comments,commentmeta,options';
				$table_list     = array_map( 'trim', explode( ',', $tables ) );
				$allowed_tables = array_unique( array_merge( ...array_values( Database_Cleanup::TABLE_MAP ) ) );
				$success_count  = 0;
				foreach ( $table_list as $table ) {
					if ( '' === $table || ! in_array( $table, $allowed_tables, true ) ) {
						WP_CLI::warning( sprintf( ' - Skipped unknown table: %s', $table ) );
						continue;
					}
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
				// JSON-only output per FINAL-ADVERSARIAL-REVIEW: REJECT table/csv/yaml, Spyc.
				// Keep wp_json_encode fallback when WP_CLI\Formatter absent.
				$format = $assoc_args['format'] ?? 'json';
				if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
					$format = \WP_CLI\Utils::get_flag_value( $assoc_args, 'format', 'json' );
				}
				if ( 'json' !== $format ) {
					$format = 'json';
				}
				WP_CLI::log( (string) wp_json_encode( $counts, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				return;
			}

			if ( 'cleanup' !== $action ) {
				/* translators: %s: Database action name */
				WP_CLI::error( sprintf( __( 'Invalid database action "%s". Use "cleanup", "optimize", or "counts".', 'performance-optimisation' ), $action ) );
				return;
			}

			$type = $assoc_args['type'] ?? 'all';

			// --dry-run preview for cleanup (before any DELETE): reuse get_counts().
			if ( $dry_run ) {
				$counts = Database_Cleanup::get_counts();
				if ( 'all' === $type ) {
					$payload = array( 'would_delete' => $counts );
				} elseif ( isset( $counts[ $type ] ) ) {
					$payload = array( 'would_delete' => array( $type => $counts[ $type ] ) );
				} else {
					// Unknown or alias type — show full preview.
					$payload = array( 'would_delete' => $counts );
				}
				WP_CLI::log( (string) wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
				WP_CLI::warning( __( 'Dry run — no rows deleted.', 'performance-optimisation' ) );
				return;
			}

			if ( 'all' === $type ) {
				// --yes gate for destructive all-type cleanup. REJECT --confirm alias.
				$yes = false;
				if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
					$yes = (bool) \WP_CLI\Utils::get_flag_value( $assoc_args, 'yes', false );
				} elseif ( isset( $assoc_args['yes'] ) ) {
					$yes = (bool) $assoc_args['yes'];
				}
				if ( ! $yes ) {
					$is_tty = false;
					if ( function_exists( 'posix_isatty' ) ) {
						$is_tty = @posix_isatty( STDIN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					} elseif ( function_exists( 'stream_isatty' ) ) {
						$is_tty = @stream_isatty( STDIN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					if ( $is_tty ) {
						WP_CLI::confirm( __( 'Are you sure you want to run database cleanup for all types?', 'performance-optimisation' ) );
					}
				}
				$results = Database_Cleanup::clean_all();
				$total   = 0;

				foreach ( $results as $key => $val ) {
					if ( is_wp_error( $val ) ) {
						WP_CLI::warning( sprintf( ' - %s: %s', $key, $val->get_error_message() ) );
						continue;
					}
					$count  = (int) $val;
					$total += $count;
					WP_CLI::log( sprintf( ' - %s: %d cleaned', $key, $count ) );
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
				case 'trashed_comments':
				case 'trashed':
					$cleaned_count = Database_Cleanup::clean_trashed_comments();
					break;
				case 'expired_transients':
				case 'transients':
					$cleaned_count = Database_Cleanup::clean_expired_transients();
					break;
				case 'orphan_postmeta':
				case 'orphans':
					$cleaned_count = Database_Cleanup::clean_orphan_postmeta();
					break;
				case 'unattached_media':
				case 'unattached':
					$cleaned_count = Database_Cleanup::clean_unattached_media();
					break;
				case 'oembed_cache':
				case 'oembed':
					$cleaned_count = Database_Cleanup::clean_oembed_cache();
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

			if ( false === $cleaned_count ) {
				/* translators: %s: Cleanup type */
				WP_CLI::error( sprintf( __( 'Database cleanup failed for type "%s".', 'performance-optimisation' ), $type ) );
				return;
			}

			/* translators: 1: Cleanup type, 2: Number of items removed */
			Log::add( sprintf( __( 'Database cleanup (%1$s via WP-CLI): %2$d items removed', 'performance-optimisation' ), $type, (int) $cleaned_count ) );
			/**
			 * Fires after a per-type database cleanup completes.
			 *
			 * @since 2.0.0
			 * @param string $type  Cleanup type.
			 * @param int    $count Number of rows deleted.
			 */
			do_action( 'wppo_database_cleanup_completed', $type, (int) $cleaned_count );
			/* translators: 1: Cleanup type, 2: Number of items removed */
			WP_CLI::success( sprintf( __( 'Database cleanup completed for %1$s (%2$d items removed).', 'performance-optimisation' ), $type, (int) $cleaned_count ) );
		}

		/**
		 * Manage image conversion.
		 *
		 * ## OPTIONS
		 *
		 * [<action>]
		 * : Action to perform.
		 * ---
		 * default: status
		 * options:
		 *   - convert
		 *   - status
		 * ---
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
				$options           = Util::get_settings();
				$img_converter     = new Img_Converter( $options );
				$img_info          = Img_Converter::get_img_info();
				$conversion_format = $format ? $format : ( $options['image_optimisation']['conversionFormat'] ?? 'webp' );
				$batch_size        = $options['image_optimisation']['batch'] ?? 50;

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
					$counter        = 0;
					foreach ( $images as $img ) {
						if ( $counter >= $batch_size ) {
							break;
						}
						++$counter;
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
		 * Get default settings structure for fresh installs.
		 *
		 * Delegates to Util::get_default_settings() for single-source defaults
		 * (A-01 minimal). Keeps CLI usable before first admin save.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, mixed>> Default settings keyed by tab.
		 */
		private static function get_default_settings(): array {
			return Util::get_default_settings();
		}

		/**
		 * View, update, export, or import plugin settings.
		 *
		 * ## OPTIONS
		 *
		 * [<action>]
		 * : Settings action to perform.
		 * ---
		 * default: get
		 * options:
		 *   - get
		 *   - update
		 *   - export
		 *   - import
		 * ---
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

			$options = Util::get_settings();
			// On fresh installs the option does not exist yet — fall back to
			// defaults so CLI is usable before the first admin save. This
			// mirrors Main::__construct() seeding but without persisting until
			// an explicit update/import.
			if ( empty( $options ) || ! is_array( $options ) ) {
				$options = self::get_default_settings();
			} else {
				// Ensure all known tabs exist even if the stored option is
				// partial (e.g. from an older version or a prior wipe).
				$options = array_replace_recursive( self::get_default_settings(), $options );
			}

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

				// Validate allowed keys (single source: Util::ALLOWED_SETTINGS_KEYS).
				$allowed_keys = Util::ALLOWED_SETTINGS_KEYS;
				foreach ( array_keys( $new_settings ) as $key ) {
					if ( ! in_array( $key, $allowed_keys, true ) ) {
						/* translators: %s: Setting key name */
						WP_CLI::error( sprintf( __( 'Invalid setting key "%s" detected.', 'performance-optimisation' ), $key ) );
						return;
					}
				}

				// Sanitize every value before merging (mirrors REST endpoint logic).
				$new_settings = Util::sanitize_settings_recursively( $new_settings );

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

				$existing_settings = Util::get_settings();
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

				// Sanitize every value before merging (mirrors REST endpoint logic).
				$new_settings = Util::sanitize_settings_recursively( $new_settings );

				$known_tabs = Util::ALLOWED_SETTINGS_TABS;
				if ( ! in_array( $tab, $known_tabs, true ) ) {
					/* translators: %s: Settings tab name */
					WP_CLI::warning( sprintf( __( 'Unrecognized settings tab "%s". Settings will be saved but the plugin may not read them.', 'performance-optimisation' ), $tab ) );
				}

				if ( ! isset( $options[ $tab ] ) || ! is_array( $options[ $tab ] ) ) {
					$options[ $tab ] = array();
				}

				$options[ $tab ] = array_replace_recursive( $options[ $tab ], $new_settings );
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
		 * [<action>]
		 * : Object Cache action to perform.
		 * ---
		 * default: status
		 * options:
		 *   - status
		 *   - ping
		 *   - enable
		 *   - disable
		 *   - flush
		 * ---
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
		 * [--mode=<mode>]
		 * : Redis mode: standalone, sentinel, or cluster (for ping/enable).
		 *
		 * [--nodes=<nodes>]
		 * : Comma-separated Redis nodes for cluster/sentinel (for ping/enable).
		 *
		 * [--master_name=<master_name>]
		 * : Master name for sentinel mode (for ping/enable).
		 *
		 * [--use_tls]
		 * : Use TLS for connections (for ping/enable).
		 *
		 * [--persistent]
		 * : Use persistent connections (for ping/enable).
		 *
		 * [--compression=<compression>]
		 * : Compression algorithm: none, lzf, zstd, lz4 (for ping/enable).
		 *
		 * [--yes]
		 * : Skip confirmation prompt for disable action (destructive).
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
					// --yes gate for destructive disable. REJECT --confirm alias.
					$yes = false;
					if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
						$yes = (bool) \WP_CLI\Utils::get_flag_value( $assoc_args, 'yes', false );
					} elseif ( isset( $assoc_args['yes'] ) ) {
						$yes = (bool) $assoc_args['yes'];
					}
					if ( ! $yes ) {
						$is_tty = false;
						if ( function_exists( 'posix_isatty' ) ) {
							$is_tty = @posix_isatty( STDIN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						} elseif ( function_exists( 'stream_isatty' ) ) {
							$is_tty = @stream_isatty( STDIN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						}
						if ( $is_tty ) {
							WP_CLI::confirm( __( 'Are you sure you want to disable Redis Object Cache?', 'performance-optimisation' ) );
						}
					}
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
		 * Uses Object_Cache::ALLOWED_KEYS as single source (converged 6→10+).
		 *
		 * @since 2.0.0
		 * @param array $assoc_args The associative arguments from the command.
		 * @return array<string, mixed> Redis connection configuration.
		 */
		private static function get_redis_config_from_assoc( array $assoc_args ): array {
			$config = array();
			foreach ( Object_Cache::ALLOWED_KEYS as $key ) {
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
		 * [<action>]
		 * : Action to perform.
		 * ---
		 * default: scan
		 * options:
		 *   - scan
		 *   - results
		 * ---
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
			$url      = $assoc_args['url'] ?? Util::cached_home_url();
			$strategy = $assoc_args['strategy'] ?? 'mobile';

			if ( 'scan' === $action ) {
				$job_id = Pagespeed::queue_scan( $url, $strategy );
				if ( $job_id <= 0 ) {
					WP_CLI::error( __( 'Failed to queue PageSpeed scan. Action Scheduler may be unavailable.', 'performance-optimisation' ) );
					return;
				}
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
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: json
		 * options:
		 *   - json
		 * ---
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
			// JSON-only output per FINAL-ADVERSARIAL-REVIEW: REJECT table/csv/yaml, Spyc.
			$format = $assoc_args['format'] ?? 'json';
			if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
				$format = \WP_CLI\Utils::get_flag_value( $assoc_args, 'format', 'json' );
			}
			if ( 'json' !== $format ) {
				$format = 'json';
			}
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

		/**
		 * Verify live site state (self-verification gate for the autonomous maintenance loop).
		 *
		 * Reads LIVE state only — options, filesystem, drop-ins, Redis, cron —
		 * and never cached transients (`wppo_cache_size`, `wppo_total_js_css`,
		 * System Info drop-in verdict cache). Read-only: never writes options,
		 * files, or schedules.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Output format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 * ---
		 *
		 * [--check=<name>]
		 * : Run a single check only (cache_dirs, dropins, redis, litespeed, settings_schema, cron, uninstall).
		 *
		 * [--severity=<level>]
		 * : Exit-code gate.
		 * ---
		 * default: fail
		 * options:
		 *   - fail
		 *   - warn
		 * ---
		 * When `fail` (default) only `fail` rows exit non-zero; when `warn`,
		 * `warn` rows also exit non-zero.
		 *
		 * ## EXAMPLES
		 *
		 *     # Full live-state verification (table)
		 *     wp wppo verify
		 *
		 *     # Machine-readable output for the maintenance loop
		 *     wp wppo verify --format=json
		 *
		 *     # Single gate
		 *     wp wppo verify --check=settings_schema
		 *
		 * @when after_wp_load
		 * @subcommand verify
		 * @since 2.0.0
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function verify( array $args, array $assoc_args ): void {
			unset( $args );

			$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
			if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
				$format = (string) \WP_CLI\Utils::get_flag_value( $assoc_args, 'format', 'table' );
			}
			if ( ! in_array( $format, array( 'table', 'json' ), true ) ) {
				/* translators: %s: Requested format */
				WP_CLI::error( sprintf( __( 'Invalid verify format "%s". Available formats: table, json.', 'performance-optimisation' ), $format ) );
				return;
			}

			$severity = isset( $assoc_args['severity'] ) ? (string) $assoc_args['severity'] : 'fail';
			if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
				$severity = (string) \WP_CLI\Utils::get_flag_value( $assoc_args, 'severity', 'fail' );
			}
			if ( ! in_array( $severity, array( 'fail', 'warn' ), true ) ) {
				/* translators: %s: Requested severity */
				WP_CLI::error( sprintf( __( 'Invalid verify severity "%s". Available severities: fail, warn.', 'performance-optimisation' ), $severity ) );
				return;
			}

			$only = isset( $assoc_args['check'] ) ? (string) $assoc_args['check'] : '';
			if ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'get_flag_value' ) ) {
				$only_raw = \WP_CLI\Utils::get_flag_value( $assoc_args, 'check', '' );
				$only     = is_string( $only_raw ) ? $only_raw : '';
			}
			if ( '' !== $only && ! in_array( $only, self::get_verify_check_names(), true ) ) {
				/* translators: 1: Requested check name, 2: List of available checks */
				WP_CLI::error( sprintf( __( 'Invalid verify check "%1$s". Available checks: %2$s.', 'performance-optimisation' ), $only, implode( ', ', self::get_verify_check_names() ) ) );
				return;
			}

			// Live settings read (lazy): bust the per-request memo first so a
			// stale in-request write (same process) cannot mask drift. Reading
			// through Util::get_settings() after the clear re-fetches the
			// option row (no transient involved), keeping verify on the
			// canonical read path (settings-read-guard). Deferred until a
			// check that actually needs $stored runs, so --check=cache_dirs
			// and --check=uninstall skip the DB read entirely.
			$stored      = null;
			$load_stored = static function () use ( &$stored ) {
				if ( null === $stored ) {
					Util::clear_settings_cache();
					$fetched = Util::get_settings();
					$stored  = is_array( $fetched ) ? $fetched : array();
				}
				return $stored;
			};

			// Fresh stat cache — CLI is single-request so stale file_exists
			// memos (not transients) are the main staleness risk.
			if ( function_exists( 'clearstatcache' ) ) {
				clearstatcache(); // phpcs:ignore WordPress.WP.AlternativeFunctions.clearstatcache_clearstatcache
			}

			// Only run the requested check so --check avoids unrelated live
			// probes (Redis ping, $wpdb query, filesystem scans).
			$check_map = array(
				'cache_dirs'      => function (): array {
					return $this->check_verify_cache_dirs();
				},
				'dropins'         => function () use ( $load_stored ): array {
					return $this->check_verify_dropins( $load_stored() );
				},
				'redis'           => function () use ( $load_stored ): array {
					return $this->check_verify_redis( $load_stored() );
				},
				'litespeed'       => function () use ( $load_stored ): array {
					return $this->check_verify_litespeed( $load_stored() );
				},
				'settings_schema' => function () use ( $load_stored ): array {
					return self::validate_settings_schema( $load_stored() );
				},
				'cron'            => function () use ( $load_stored ): array {
					return $this->check_verify_cron( $load_stored() );
				},
				'uninstall'       => function (): array {
					return $this->check_verify_uninstall_spot();
				},
			);

			$names = '' !== $only ? array( $only ) : array_keys( $check_map );
			$rows  = array();
			foreach ( $names as $name ) {
				if ( isset( $check_map[ $name ] ) ) {
					$rows[] = $check_map[ $name ]();
				}
			}

			$fail_count = 0;
			$warn_count = 0;
			foreach ( $rows as $row ) {
				if ( isset( $row['status'] ) && 'fail' === $row['status'] ) {
					++$fail_count;
				} elseif ( isset( $row['status'] ) && 'warn' === $row['status'] ) {
					++$warn_count;
				}
			}

			$payload = self::build_verify_payload( $rows, $severity );

			if ( 'json' === $format ) {
				WP_CLI::log(
					(string) wp_json_encode(
						$payload,
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					)
				);
			} elseif ( class_exists( '\\WP_CLI\\Utils' ) && method_exists( '\\WP_CLI\\Utils', 'format_items' ) ) {
					\WP_CLI\Utils::format_items( 'table', $rows, array( 'check', 'status', 'detail' ) );
			} else {
				foreach ( $rows as $row ) {
					WP_CLI::log( sprintf( '%s [%s] %s', $row['check'], $row['status'], $row['detail'] ) );
				}
			}

			// Overall 'fail' <=> non-zero exit by construction (builder uses the
			// same severity gate), so JSON readers and the CLI gate agree.
			$should_fail = 'fail' === $payload['overall'];
			if ( $should_fail ) {
				$gated = 'warn' === $severity ? $fail_count + $warn_count : $fail_count;
				/* translators: 1: Number of non-passing checks, 2: Severity gate */
				WP_CLI::error( sprintf( __( 'Verify failed: %1$d check(s) not passing (severity=%2$s).', 'performance-optimisation' ), $gated, $severity ) );
				return;
			}

			/* translators: 1: Number of passing checks, 2: Total checks */
			WP_CLI::success( sprintf( __( 'Verify passed: %1$d/%2$d checks passing.', 'performance-optimisation' ), count( $rows ) - $fail_count - $warn_count, count( $rows ) ) );
		}

		/**
		 * Canonical verify check names (single source for --check validation).
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_verify_check_names(): array {
			return array( 'cache_dirs', 'dropins', 'redis', 'litespeed', 'settings_schema', 'cron', 'uninstall' );
		}

		/**
		 * Resolve the static-cache domain for a home URL (Cache::__construct convention).
		 *
		 * Mirrors Cache::__construct(): sanitize + IDN-to-ASCII + port-strip +
		 * `^[a-z0-9.\-]+$` allowlist + `..` reject, lowercased. The port is
		 * intentionally stripped (audit C-06: the drop-in keeps `:` while Cache
		 * strips it — verify resolves against the Cache convention).
		 *
		 * @since 2.0.0
		 * @param string $home_url Home URL.
		 * @return string Sanitized domain, or '' when unresolvable.
		 */
		public static function resolve_verify_domain( string $home_url ): string {
			$host = '';
			if ( function_exists( 'wp_parse_url' ) ) {
				$parsed = wp_parse_url( $home_url, PHP_URL_HOST );
				$host   = is_string( $parsed ) ? $parsed : '';
			} else {
				$parsed = parse_url( $home_url, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
				$host   = is_string( $parsed ) ? $parsed : '';
			}

			if ( function_exists( 'sanitize_text_field' ) ) {
				$host = sanitize_text_field( $host );
			}

			if ( '' !== $host && function_exists( 'idn_to_ascii' ) ) {
				$converted = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				if ( false !== $converted && is_string( $converted ) ) {
					$host = $converted;
				}
			}

			// Strip the port before validation (Cache convention). IPv6
			// literals contain multiple colons (or brackets) — never strip
			// there, otherwise "[::1]" would mangle to "[".
			$is_ipv6_literal = ( 0 === strpos( $host, '[' ) ) || ( substr_count( $host, ':' ) > 1 );
			if ( ! $is_ipv6_literal ) {
				$parts = explode( ':', $host, 2 );
				$host  = $parts[0];
			}

			if ( '' === $host || false !== strpos( $host, '..' ) || 1 !== preg_match( '/^[a-z0-9.\-]+$/i', $host ) ) {
				return '';
			}

			return strtolower( $host );
		}

		/**
		 * Build a Redis config array from stored settings (ALLOWED_KEYS allowlist).
		 *
		 * Same key list WPPO_CLI_Command::get_redis_config_from_assoc() uses.
		 *
		 * @since 2.0.0
		 * @param array $stored Raw wppo_settings array.
		 * @return array<string, mixed> Redis connection configuration.
		 */
		public static function build_redis_config_from_settings( array $stored ): array {
			$config = array();
			$oc     = isset( $stored['object_cache'] ) && is_array( $stored['object_cache'] ) ? $stored['object_cache'] : array();
			foreach ( Object_Cache::ALLOWED_KEYS as $key ) {
				if ( isset( $oc[ $key ] ) ) {
					$config[ $key ] = $oc[ $key ];
				}
			}
			return $config;
		}

		/**
		 * Validate stored settings against the canonical schema (read-only).
		 *
		 * Unknown top-level keys → fail (schema drift); unknown nested
		 * sub-keys (one level deep, e.g. `cache_settings.enablCache`)
		 * → fail; tabs present in Util::get_settings_schema() but missing
		 * from storage → warn (fresh/partial install); array-vs-scalar
		 * mismatches vs the schema (one level deep) → fail. The schema is
		 * decoupled from the runtime defaults so SPA-persisted keys are
		 * recognised without seeding them. Never calls update_option().
		 *
		 * @since 2.0.0
		 * @param array         $stored Raw wppo_settings array (live get_option).
		 * @param string[]|null $allowed Optional allowlist override (testing).
		 * @param array|null    $schema  Optional schema override (testing).
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		public static function validate_settings_schema( array $stored, ?array $allowed = null, ?array $schema = null ): array {
			$allowed = is_array( $allowed ) ? $allowed : Util::ALLOWED_SETTINGS_KEYS;
			$schema  = is_array( $schema ) ? $schema : Util::get_settings_schema();

			$unknown = array();
			foreach ( array_keys( $stored ) as $key ) {
				if ( ! in_array( $key, $allowed, true ) ) {
					$unknown[] = (string) $key;
				}
			}

			$missing = array();
			foreach ( array_keys( $schema ) as $tab ) {
				if ( ! array_key_exists( $tab, $stored ) ) {
					$missing[] = (string) $tab;
				}
			}

			$mismatches     = array();
			$unknown_nested = array();
			foreach ( $stored as $tab => $value ) {
				if ( ! array_key_exists( $tab, $schema ) || ! is_array( $schema[ $tab ] ) || ! is_array( $value ) ) {
					continue;
				}
				foreach ( $value as $sub_key => $sub_value ) {
					if ( ! array_key_exists( $sub_key, $schema[ $tab ] ) ) {
						$unknown_nested[] = sprintf( '%s.%s', $tab, $sub_key );
						continue;
					}
					$schema_is_array = 'array' === $schema[ $tab ][ $sub_key ];
					$stored_is_array = is_array( $sub_value );
					if ( $schema_is_array !== $stored_is_array ) {
						$mismatches[] = sprintf(
							'%s.%s expected %s got %s',
							$tab,
							$sub_key,
							$schema_is_array ? 'array' : 'scalar',
							$stored_is_array ? 'array' : 'scalar'
						);
					}
				}
			}
			// Tabs stored as scalars at the top level (schema tabs are all arrays).
			foreach ( $stored as $tab => $value ) {
				if ( array_key_exists( $tab, $schema ) && is_array( $schema[ $tab ] ) && ! is_array( $value ) ) {
					$mismatches[] = sprintf( '%s expected array got scalar', $tab );
				}
			}

			if ( ! empty( $unknown ) || ! empty( $unknown_nested ) || ! empty( $mismatches ) ) {
				$parts = array();
				if ( ! empty( $unknown ) ) {
					$parts[] = 'unknown keys: ' . implode( ', ', $unknown );
				}
				if ( ! empty( $unknown_nested ) ) {
					$parts[] = 'unknown sub-keys: ' . implode( ', ', $unknown_nested );
				}
				if ( ! empty( $mismatches ) ) {
					$parts[] = 'type mismatches: ' . implode( '; ', $mismatches );
				}
				return array(
					'check'  => 'settings_schema',
					'status' => 'fail',
					'detail' => implode( ' | ', $parts ),
				);
			}

			if ( ! empty( $missing ) ) {
				return array(
					'check'  => 'settings_schema',
					'status' => 'warn',
					/* translators: %s: Comma-separated list of missing settings tabs */
					'detail' => sprintf( __( 'Missing tabs (fresh/partial install): %s', 'performance-optimisation' ), implode( ', ', $missing ) ),
				);
			}

			return array(
				'check'  => 'settings_schema',
				'status' => 'pass',
				'detail' => __( 'Settings schema valid.', 'performance-optimisation' ),
			);
		}

		/**
		 * Split found wppo_* cron hooks into orphans vs known-legacy (pure helper).
		 *
		 * The legacy `wppo_img_conversation` misspelling is allowlisted as
		 * known-BC and reported separately (warn, not fail).
		 *
		 * @since 2.0.0
		 * @param string[] $found_hooks Hook names discovered in WP-Cron.
		 * @return array{orphans:string[],legacy:string[]} Orphan and legacy lists.
		 */
		public static function find_orphan_cron_hooks( array $found_hooks ): array {
			$orphans = array();
			$legacy  = array();
			foreach ( $found_hooks as $hook ) {
				if ( 0 !== strpos( $hook, 'wppo_' ) ) {
					continue;
				}
				if ( 'wppo_img_conversation' === $hook ) {
					$legacy[] = $hook;
					continue;
				}
				if ( ! in_array( $hook, Cron::SCHEDULED_HOOKS, true ) ) {
					$orphans[] = $hook;
				}
			}
			return array(
				'orphans' => $orphans,
				'legacy'  => $legacy,
			);
		}

		/**
		 * Evaluate LiteSpeed coherence from live inputs (pure helper, no I/O).
		 *
		 * @since 2.0.0
		 * @param string $raw_mode Raw configured mode string (unvalidated).
		 * @param string $effective Resolved effective mode.
		 * @param bool   $server_ls Whether the server is LiteSpeed.
		 * @param bool   $lscache Whether the LSCache plugin is active.
		 * @param bool   $esi_enabled Whether the ESI bridge setting is on.
		 * @param bool   $esi_available Whether native ESI is available (Enterprise).
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		public static function evaluate_litespeed_state( string $raw_mode, string $effective, bool $server_ls, bool $lscache, bool $esi_enabled, bool $esi_available ): array {
			$allowed = array( 'auto', 'wppo', 'litespeed', 'standalone' );
			$base    = sprintf(
				'mode=%s effective=%s server-ls=%s lscache=%s',
				$raw_mode,
				$effective,
				$server_ls ? 'yes' : 'no',
				$lscache ? 'yes' : 'no'
			);

			if ( ! in_array( $raw_mode, $allowed, true ) ) {
				return array(
					'check'  => 'litespeed',
					'status' => 'fail',
					'detail' => 'unknown mode "' . $raw_mode . '" | ' . $base,
				);
			}

			if ( 'litespeed' === $effective && ! $server_ls && ! $lscache ) {
				return array(
					'check'  => 'litespeed',
					'status' => 'fail',
					'detail' => 'effective litespeed with no LS server and no LSCache plugin | ' . $base,
				);
			}

			$warnings = array();
			if ( 'wppo' === $raw_mode && $server_ls && $lscache ) {
				$warnings[] = 'mode wppo on LS server with LSCache active (double-cache risk)';
			}
			if ( 'standalone' === $raw_mode && $server_ls ) {
				$warnings[] = 'standalone on LS server (intentional but flagged)';
			}
			if ( $esi_enabled && ! $esi_available ) {
				$warnings[] = 'ESI enabled without Enterprise/native ESI';
			}

			if ( ! empty( $warnings ) ) {
				return array(
					'check'  => 'litespeed',
					'status' => 'warn',
					'detail' => implode( '; ', $warnings ) . ' | ' . $base,
				);
			}

			return array(
				'check'  => 'litespeed',
				'status' => 'pass',
				'detail' => $base,
			);
		}

		/**
		 * Find wppo_* options without a known live owner (pure helper).
		 *
		 * Known = Util::UNINSTALL_OPTIONS (blog-prefix stripped) + dynamic
		 * prefixes (front-page LCP, crawler batches, purge queue).
		 *
		 * Object_Cache's circuit options now live in Util::UNINSTALL_OPTIONS
		 * (they gained uninstall rows in the option-leak hardening pass), so
		 * they no longer need a separate runtime whitelist.
		 *
		 * @since 2.0.0
		 * @param string[] $found_names Option names from the options table.
		 * @return string[] Unknown option names.
		 */
		public static function find_unknown_wppo_options( array $found_names ): array {
			$known = array();
			foreach ( Util::UNINSTALL_OPTIONS as $name ) {
				$known[] = $name;
			}

			$prefixes = array(
				Util::FRONT_PAGE_LCP_OPTION_PREFIX,
				'wppo_crawler_batch_',
				'wppo_litespeed_purge_queue',
			);

			$unknown = array();
			foreach ( $found_names as $name ) {
				$name = (string) $name;
				if ( 0 !== strpos( $name, 'wppo_' ) && 0 === preg_match( '/^\d+_wppo_/', $name ) ) {
					continue;
				}
				// Strip a multisite blog-ID prefix for comparison.
				$bare = (string) preg_replace( '/^\d+_/', '', $name );
				if ( in_array( $bare, $known, true ) || in_array( $name, $known, true ) ) {
					continue;
				}
				$matched_prefix = false;
				foreach ( $prefixes as $prefix ) {
					if ( 0 === strpos( $bare, $prefix ) || 0 === strpos( $name, $prefix ) ) {
						$matched_prefix = true;
						break;
					}
				}
				if ( ! $matched_prefix ) {
					$unknown[] = $name;
				}
			}
			return $unknown;
		}

		/**
		 * Build the machine-readable verify payload (pure helper).
		 *
		 * Overall mirrors the verify() exit gate so machine readers stay
		 * consistent: `fail` when any row fails (or any row warns with
		 * `$severity='warn'`), `warn` when rows warn but the gate stays
		 * green, otherwise `pass`.
		 *
		 * @since 2.0.0
		 * @param array  $rows Verify rows.
		 * @param string $severity Exit-code gate ('fail' or 'warn').
		 * @return array{overall:string,checks:array} Payload for --format=json.
		 */
		public static function build_verify_payload( array $rows, string $severity = 'fail' ): array {
			$fail_count = 0;
			$warn_count = 0;
			foreach ( $rows as $row ) {
				if ( isset( $row['status'] ) && 'fail' === $row['status'] ) {
					++$fail_count;
				} elseif ( isset( $row['status'] ) && 'warn' === $row['status'] ) {
					++$warn_count;
				}
			}
			if ( $fail_count > 0 || ( 'warn' === $severity && $warn_count > 0 ) ) {
				$overall = 'fail';
			} elseif ( $warn_count > 0 ) {
				$overall = 'warn';
			} else {
				$overall = 'pass';
			}
			return array(
				'overall' => $overall,
				'checks'  => array_values( $rows ),
			);
		}

		/**
		 * Check 1 — cache directories live (writable + consistent).
		 *
		 * Never calls Cache::get_cache_stats(), Cache::get_cache_size(), or any
		 * wppo_* transient — every probe hits the filesystem directly.
		 *
		 * @since 2.0.0
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		private function check_verify_cache_dirs(): array {
			$fs = Util::init_filesystem();
			if ( ! $fs ) {
				return array(
					'check'  => 'cache_dirs',
					'status' => 'fail',
					'detail' => __( 'WP_Filesystem unavailable — cannot assert cache dirs.', 'performance-optimisation' ),
				);
			}

			$root = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo' );

			// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_is_dir, WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
			$root_is_dir      = is_dir( $root );
			$root_is_writable = $root_is_dir && is_writable( $root );
			// phpcs:enable

			if ( ! $root_is_dir ) {
				return array(
					'check'  => 'cache_dirs',
					'status' => 'fail',
					'detail' => 'cache root missing: ' . $root,
				);
			}

			if ( ! $root_is_writable ) {
				return array(
					'check'  => 'cache_dirs',
					'status' => 'fail',
					'detail' => 'cache root not writable: ' . $root,
				);
			}

			$home_url = function_exists( 'home_url' ) ? home_url() : '';
			if ( '' === $home_url && method_exists( 'PerformanceOptimise\Inc\Util', 'cached_home_url' ) ) {
				try {
					$home_url = Util::cached_home_url();
				} catch ( \Throwable $e ) {
					unset( $e );
					$home_url = '';
				}
			}
			$domain = self::resolve_verify_domain( (string) $home_url );

			$optionals = array(
				'min/js'  => Util::min_cache_dir( 'js' ),
				'min/css' => Util::min_cache_dir( 'css' ),
				'ccss'    => wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/ccss' ),
				'fonts'   => wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/fonts' ),
			);
			if ( '' !== $domain ) {
				$optionals[ 'domain(' . $domain . ')' ] = wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/' . $domain );
			}

			$missing = array();
			foreach ( $optionals as $label => $dir ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_dir
				if ( ! is_dir( $dir ) ) {
					$missing[] = $label;
				}
			}

			$port_note = '';
			if ( false !== strpos( (string) $home_url, ':' ) && 1 === preg_match( '#https?://[^/]+:[0-9]+#', (string) $home_url ) ) {
				$port_note = ' (port stripped per Cache convention)';
			}

			if ( ! empty( $missing ) ) {
				return array(
					'check'  => 'cache_dirs',
					'status' => 'warn',
					/* translators: %s: Comma-separated list of missing optional cache subdirectories */
					'detail' => sprintf( __( 'Root healthy; optional subdirs missing: %s', 'performance-optimisation' ), implode( ', ', $missing ) ) . $port_note,
				);
			}

			if ( '' === $domain ) {
				return array(
					'check'  => 'cache_dirs',
					'status' => 'warn',
					'detail' => 'root writable but home host unresolvable; domain dir unchecked' . $port_note,
				);
			}

			return array(
				'check'  => 'cache_dirs',
				'status' => 'pass',
				'detail' => 'root writable; domain=' . $domain . ' min/ccss/fonts present' . $port_note,
			);
		}

		/**
		 * Check 2 — drop-ins present & intended (incl. foreign).
		 *
		 * @since 2.0.0
		 * @param array $stored Raw wppo_settings array.
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		private function check_verify_dropins( array $stored ): array {
			$notes    = array();
			$has_fail = false;
			$has_warn = false;

			// Advanced-cache drop-in (verdict via is_our_dropin()/foreign_dropin_present()).
			try {
				$is_ours = Advanced_Cache_Handler::is_our_dropin();
			} catch ( \Throwable $e ) {
				unset( $e );
				$is_ours = false;
			}
			try {
				$foreign = Advanced_Cache_Handler::foreign_dropin_present();
			} catch ( \Throwable $e ) {
				unset( $e );
				$foreign = false;
			}
			$wp_cache = defined( 'WP_CACHE' ) && WP_CACHE;

			$cache_enabled = ! empty( $stored['cache_settings']['enableCache'] );

			if ( $is_ours ) {
				$notes[] = 'advanced-cache: ours';
				if ( ! $wp_cache ) {
					$has_warn = true;
					$notes[]  = 'WP_CACHE off while WPPO drop-in installed';
				}
			} elseif ( $foreign ) {
				$notes[] = 'advanced-cache: foreign drop-in owns the slot';
				if ( $cache_enabled ) {
					$has_warn = true;
					$notes[]  = 'WPPO cache enabled but foreign drop-in active';
				}
			} elseif ( $cache_enabled ) {
					$has_fail = true;
					$notes[]  = 'advanced-cache: missing while WPPO cache enabled';
			} else {
				$notes[] = 'advanced-cache: absent (cache disabled)';
			}

			// wp-config.php WP_CACHE scan (only when readable — best effort).
			$wp_config = '';
			if ( defined( 'ABSPATH' ) ) {
				$candidate = wp_normalize_path( ABSPATH . 'wp-config.php' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
				if ( is_readable( $candidate ) ) {
					$wp_config = $candidate;
				} else {
					$parent = wp_normalize_path( dirname( ABSPATH ) . '/wp-config.php' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
					if ( is_readable( $parent ) ) {
						$wp_config = $parent;
					}
				}
			}
			if ( '' !== $wp_config ) {
				$content = $this->read_small_file( $wp_config );
				if ( is_string( $content ) && false === strpos( $content, 'WP_CACHE' ) && $cache_enabled && $is_ours ) {
					$has_warn = true;
					$notes[]  = 'wp-config.php has no WP_CACHE define';
				}
			}

			// Object-cache drop-in.
			try {
				$oc_manager = new Object_Cache();
				$oc_path    = $oc_manager->get_dropin_path();
			} catch ( \Throwable $e ) {
				unset( $e );
				$oc_path = wp_normalize_path( WP_CONTENT_DIR . '/object-cache.php' );
			}
			$oc_content = $this->read_small_file( $oc_path );
			$oc_own     = is_string( $oc_content ) && Object_Cache::is_own_dropin_content( $oc_content );
			// Empty (0-byte) files read back as '' — treat as broken/absent,
			// not foreign, so operators get a distinct note.
			$oc_empty = '' === $oc_content;
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			$oc_exists  = ( is_string( $oc_content ) && '' !== $oc_content ) || ( ! $oc_empty && is_readable( $oc_path ) );
			$oc_foreign = $oc_exists && ! $oc_own && ! $oc_empty;
			$oc_legacy  = is_string( $oc_content )
				&& false === strpos( $oc_content, Object_Cache::DROPIN_MARKER )
				&& false !== strpos( $oc_content, Object_Cache::LEGACY_DROPIN_MARKER );

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			$config_path   = wp_normalize_path( WP_CONTENT_DIR . '/wppo-redis-config.php' );
			$config_exists = is_readable( $config_path );

			$oc_settings = isset( $stored['object_cache'] ) && is_array( $stored['object_cache'] ) ? $stored['object_cache'] : array();
			$oc_implied  = ! empty( $oc_settings['host'] ) || ! empty( $oc_settings['nodes'] );

			if ( $oc_own ) {
				$notes[] = 'object-cache: ours' . ( $oc_legacy ? ' (legacy marker)' : '' );
				if ( $oc_legacy ) {
					$has_warn = true;
				}
				if ( $oc_implied && ! $config_exists ) {
					$has_fail = true;
					$notes[]  = 'wppo-redis-config.php missing while object cache implied enabled';
				}
			} elseif ( $oc_empty ) {
				$notes[]  = 'object-cache: present but empty (broken, not foreign)';
				$has_warn = true;
				if ( $config_exists ) {
					$notes[] = 'wppo-redis-config.php present but drop-in empty';
				}
			} elseif ( $oc_foreign ) {
				$notes[]  = 'object-cache: foreign drop-in owns the slot';
				$has_warn = true;
				if ( $config_exists ) {
					$notes[] = 'wppo-redis-config.php present but drop-in foreign/absent';
				}
			} else {
				$notes[] = 'object-cache: absent';
				if ( $oc_implied ) {
					$has_warn = true;
					$notes[]  = 'object-cache implied enabled in settings but no drop-in';
				}
				if ( $config_exists ) {
					$has_warn = true;
					$notes[]  = 'wppo-redis-config.php present but no drop-in';
				}
			}

			$status = $has_fail ? 'fail' : ( $has_warn ? 'warn' : 'pass' );
			return array(
				'check'  => 'dropins',
				'status' => $status,
				'detail' => implode( '; ', $notes ),
			);
		}

		/**
		 * Check 3 — Redis reachable when enabled (live probe).
		 *
		 * "Enabled" is derived live from stored settings (non-empty host/nodes)
		 * AND own drop-in presence — never from get_status() caches.
		 *
		 * @since 2.0.0
		 * @param array $stored Raw wppo_settings array.
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		private function check_verify_redis( array $stored ): array {
			$oc_settings = isset( $stored['object_cache'] ) && is_array( $stored['object_cache'] ) ? $stored['object_cache'] : array();
			$has_host    = ! empty( $oc_settings['host'] ) || ! empty( $oc_settings['nodes'] );

			$own_dropin = false;
			try {
				$manager    = new Object_Cache();
				$oc_path    = $manager->get_dropin_path();
				$content    = $this->read_small_file( $oc_path );
				$own_dropin = is_string( $content ) && Object_Cache::is_own_dropin_content( $content );
			} catch ( \Throwable $e ) {
				unset( $e );
				$own_dropin = false;
			}

			if ( ! $has_host || ! $own_dropin ) {
				$detail = __( 'Redis disabled — skipped.', 'performance-optimisation' );
				if ( ! class_exists( 'Redis' ) ) {
					$detail .= ' (php-redis missing; needed if enabling)';
				}
				return array(
					'check'  => 'redis',
					'status' => 'pass',
					'detail' => $detail,
				);
			}

			if ( ! class_exists( 'Redis' ) ) {
				return array(
					'check'  => 'redis',
					'status' => 'fail',
					'detail' => __( 'Object cache enabled but the PhpRedis extension is missing.', 'performance-optimisation' ),
				);
			}

			$config = self::build_redis_config_from_settings( $stored );
			try {
				$manager = new Object_Cache();
				$ping    = $manager->ping( $config );
			} catch ( \Throwable $e ) {
				return array(
					'check'  => 'redis',
					'status' => 'fail',
					'detail' => 'ping exception: ' . sanitize_text_field( $e->getMessage() ),
				);
			}

			if ( true === $ping ) {
				return array(
					'check'  => 'redis',
					'status' => 'pass',
					'detail' => __( 'Redis reachable (live ping).', 'performance-optimisation' ),
				);
			}

			$message = __( 'Redis unreachable.', 'performance-optimisation' );
			if ( $ping instanceof \WP_Error ) {
				$message = $ping->get_error_message();
			}
			return array(
				'check'  => 'redis',
				'status' => 'fail',
				'detail' => $message,
			);
		}

		/**
		 * Check 4 — LiteSpeed mode coherent.
		 *
		 * @since 2.0.0
		 * @param array $stored Raw wppo_settings array.
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		private function check_verify_litespeed( array $stored ): array {
			$raw_mode = 'auto';
			if ( isset( $stored['litespeed_integration']['mode'] ) && is_string( $stored['litespeed_integration']['mode'] ) ) {
				$raw_mode = $stored['litespeed_integration']['mode'];
			}

			$effective = 'standalone';
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'effective_mode' ) ) {
					$effective = (string) LiteSpeed_Integration::effective_mode();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$server_ls = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'is_litespeed' ) ) {
					$server_ls = (bool) Server_Rules::is_litespeed();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$lscache = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_lscache_active' ) ) {
					$lscache = (bool) LiteSpeed_Integration::is_lscache_active();
				} else {
					$lscache = class_exists( 'LiteSpeed_Cache_API' ) || ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'litespeed-cache/litespeed-cache.php' ) );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			$esi_enabled   = false;
			$esi_available = false;
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI' ) ) {
					if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', 'is_setting_enabled' ) ) {
						$esi_enabled = (bool) LiteSpeed_ESI::is_setting_enabled();
					}
					if ( method_exists( 'PerformanceOptimise\Inc\LiteSpeed_ESI', 'is_esi_available' ) ) {
						$esi_available = (bool) LiteSpeed_ESI::is_esi_available();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			return self::evaluate_litespeed_state( $raw_mode, $effective, $server_ls, $lscache, $esi_enabled, $esi_available );
		}

		/**
		 * Check 6 — orphaned cron / Action Scheduler events.
		 *
		 * @since 2.0.0
		 * @param array $stored Raw wppo_settings array.
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		private function check_verify_cron( array $stored ): array {
			$notes    = array();
			$has_fail = false;
			$has_warn = false;

			// WP-Cron side: enumerate live via _get_cron_array().
			$found = array();
			try {
				if ( function_exists( '_get_cron_array' ) ) {
					$cron = _get_cron_array();
					if ( is_array( $cron ) ) {
						foreach ( $cron as $timestamp => $hooks ) {
							unset( $timestamp );
							if ( ! is_array( $hooks ) ) {
								continue;
							}
							foreach ( array_keys( $hooks ) as $hook ) {
								$found[] = (string) $hook;
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			$found = array_values( array_unique( $found ) );

			$split = self::find_orphan_cron_hooks( $found );
			if ( ! empty( $split['orphans'] ) ) {
				$has_fail = true;
				$notes[]  = 'orphan cron hooks: ' . implode( ', ', $split['orphans'] );
			} else {
				$notes[] = 'no orphan cron hooks';
			}
			if ( ! empty( $split['legacy'] ) ) {
				$has_warn = true;
				$notes[]  = 'legacy hook present (BC): ' . implode( ', ', $split['legacy'] );
			}

			// Expected-but-missing recurring dispatchers.
			$missing_expected = array();
			if ( function_exists( 'wp_next_scheduled' ) ) {
				try {
					$preload_on = ! empty( $stored['preload_settings']['enablePreloadCache'] );
					if ( $preload_on && ! wp_next_scheduled( 'wppo_page_cron_hook' ) ) {
						$missing_expected[] = 'wppo_page_cron_hook';
					}
					if ( ! wp_next_scheduled( 'wppo_img_conversion' ) ) {
						$missing_expected[] = 'wppo_img_conversion';
					}
					$db_schedule = isset( $stored['database_cleanup']['dbSchedule'] ) ? (string) $stored['database_cleanup']['dbSchedule'] : '';
					if ( '' !== $db_schedule && 'none' !== $db_schedule && ! wp_next_scheduled( 'wppo_database_cleanup_cron' ) ) {
						$missing_expected[] = 'wppo_database_cleanup_cron';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			if ( ! empty( $missing_expected ) ) {
				$has_warn = true;
				$notes[]  = 'expected but unscheduled: ' . implode( ', ', $missing_expected );
			}

			// Action Scheduler side (guarded — missing vendor install degrades to warn).
			if ( function_exists( 'as_get_scheduled_actions' ) ) {
				try {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$actions = as_get_scheduled_actions(
						array(
							'status'   => 'pending',
							'per_page' => 100,
						),
						'ids'
					);
					// phpcs:enable
					$as_hooks = array();
					if ( is_array( $actions ) ) {
						foreach ( $actions as $action_id ) {
							if ( function_exists( 'as_get_scheduled_action' ) ) {
								$action = as_get_scheduled_action( $action_id );
								if ( is_object( $action ) && method_exists( $action, 'get_hook' ) ) {
									$as_hooks[] = (string) $action->get_hook();
								}
							}
						}
					}
					// Fallback: query by hook when the action-object API is unavailable.
					if ( empty( $as_hooks ) && function_exists( 'as_has_scheduled_action' ) ) {
						foreach ( Cron::AS_HOOKS as $hook ) {
							if ( as_has_scheduled_action( $hook ) ) {
								$as_hooks[] = $hook;
							}
						}
					}
					$as_hooks   = array_values( array_unique( $as_hooks ) );
					$as_orphans = array();
					foreach ( $as_hooks as $hook ) {
						if ( 0 === strpos( $hook, 'wppo_' ) && ! in_array( $hook, Cron::AS_HOOKS, true ) ) {
							$as_orphans[] = $hook;
						}
					}
					if ( ! empty( $as_orphans ) ) {
						$has_fail = true;
						$notes[]  = 'orphan AS actions: ' . implode( ', ', $as_orphans );
					} else {
						$notes[] = 'AS queue clean';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					$has_warn = true;
					$notes[]  = 'AS inspection failed (skipped)';
				}
			} else {
				$has_warn = true;
				$notes[]  = 'AS unavailable — skipped';
			}

			$status = $has_fail ? 'fail' : ( $has_warn ? 'warn' : 'pass' );
			return array(
				'check'  => 'cron',
				'status' => $status,
				'detail' => implode( '; ', $notes ),
			);
		}

		/**
		 * Check 7 — uninstall-completeness spot check (read-only).
		 *
		 * Single `wp_options WHERE option_name LIKE '%wppo_%'` query capped at
		 * 51 rows (contains match so multisite blog-prefixed rows like
		 * `2_wppo_settings` are visible to the classifier); never deletes.
		 *
		 * @since 2.0.0
		 * @return array{check:string,status:string,detail:string} Verify row.
		 */
		private function check_verify_uninstall_spot(): array {
			$found   = array();
			$queried = false;
			try {
				global $wpdb;
				if ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->options ) && method_exists( $wpdb, 'get_col' ) && method_exists( $wpdb, 'esc_like' ) ) {
					$like = '%' . $wpdb->esc_like( 'wppo_' ) . '%';
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$found = $wpdb->get_col( $wpdb->prepare( 'SELECT option_name FROM %i WHERE option_name LIKE %s LIMIT 51', $wpdb->options, $like ) );
					// phpcs:enable
					if ( is_array( $found ) ) {
						$queried = true;
					} else {
						$found = array();
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$found   = array();
				$queried = false;
			}

			if ( ! $queried ) {
				return array(
					'check'  => 'uninstall',
					'status' => 'warn',
					'detail' => __( 'Options table unreadable — uninstall state could not be verified.', 'performance-optimisation' ),
				);
			}

			$truncated = count( $found ) >= 51;

			if ( empty( $found ) ) {
				return array(
					'check'  => 'uninstall',
					'status' => 'warn',
					'detail' => __( 'No wppo_* options found — could not corroborate live settings (empty result may indicate a scope/caching issue).', 'performance-optimisation' ),
				);
			}

			$unknown = self::find_unknown_wppo_options( array_map( 'strval', $found ) );
			if ( empty( $unknown ) ) {
				/* translators: %d: Number of wppo_* options found */
				$detail = sprintf( __( 'All %d wppo_* options have a known owner.', 'performance-optimisation' ), count( $found ) );
				if ( $truncated ) {
					$detail .= __( ' (query capped at 51 rows; more may exist)', 'performance-optimisation' );
					return array(
						'check'  => 'uninstall',
						'status' => 'warn',
						'detail' => $detail,
					);
				}
				return array(
					'check'  => 'uninstall',
					'status' => 'pass',
					'detail' => $detail,
				);
			}

			$shown  = array_slice( $unknown, 0, 10 );
			$extra  = count( $unknown ) - count( $shown );
			$detail = 'unknown wppo_* options: ' . implode( ', ', $shown );
			if ( $extra > 0 ) {
				/* translators: %d: Number of additional unknown options not listed */
				$detail .= sprintf( __( ' +%d more', 'performance-optimisation' ), $extra );
			}
			if ( $truncated ) {
				$detail .= __( ' (query capped at 51 rows; more may exist)', 'performance-optimisation' );
			}
			return array(
				'check'  => 'uninstall',
				'status' => 'warn',
				'detail' => $detail,
			);
		}

		/**
		 * Read a small file via WP_Filesystem with a native fallback (size-guarded).
		 *
		 * @since 2.0.0
		 * @param string $path Absolute path.
		 * @return string|null Contents, or null when missing/unreadable/too large.
		 */
		private function read_small_file( string $path ): ?string {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_readable
			if ( ! is_readable( $path ) ) {
				return null;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_filesize
			$size = filesize( $path );
			if ( false === $size || $size < 0 || $size >= 1048576 ) {
				return null;
			}
			if ( 0 === $size ) {
				return '';
			}

			$fs = Util::init_filesystem();
			if ( $fs && is_object( $fs ) && method_exists( $fs, 'get_contents' ) ) {
				try {
					$content = $fs->get_contents( $path );
					return is_string( $content ) ? $content : null;
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$content = file_get_contents( $path );
			return is_string( $content ) ? $content : null;
		}
	}
}
