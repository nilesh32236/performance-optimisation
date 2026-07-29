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
		 * : Cache action to perform. Currently supported: clear.
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
		 * @when after_wp_load
		 * @subcommand cache
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function cache( array $args, array $assoc_args ): void {
			$action = $args[0] ?? 'clear';

			if ( 'clear' !== $action ) {
				/* translators: %s: Cache action name */
				WP_CLI::error( sprintf( __( 'Invalid cache action "%s". Did you mean "wp wppo cache clear"?', 'performance-optimisation' ), $action ) );
				return;
			}

			require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-cache.php';

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
		 * Perform database cleanup routines.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Action to perform. Currently supported: cleanup.
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
		 * ## EXAMPLES
		 *
		 *     # Clean up post revisions
		 *     wp wppo database cleanup --type=revisions
		 *
		 *     # Run all database cleanup routines
		 *     wp wppo database cleanup --type=all
		 *
		 * @when after_wp_load
		 * @subcommand database
		 * @param array $args Command positional arguments.
		 * @param array $assoc_args Command associative arguments.
		 * @return void
		 */
		public function database( array $args, array $assoc_args ): void {
			$action = $args[0] ?? 'cleanup';

			if ( 'cleanup' !== $action ) {
				/* translators: %s: Database action name */
				WP_CLI::error( sprintf( __( 'Invalid database action "%s". Did you mean "wp wppo database cleanup"?', 'performance-optimisation' ), $action ) );
				return;
			}

			$type = $assoc_args['type'] ?? 'all';

			require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-database-cleanup.php';

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
		 * View or update plugin settings.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Settings action to perform: get or update.
		 *
		 * [<tab>]
		 * : Settings tab name (file_optimisation, preload_settings, image_optimisation, database_cleanup, object_cache).
		 *
		 * [--settings=<json>]
		 * : JSON string containing setting key-value pairs (required for update action).
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
					if ( function_exists( 'yaml_emit' ) ) {
						WP_CLI::log( yaml_emit( $data ) );
					} else {
						WP_CLI::warning( __( 'PHP yaml extension is not installed; falling back to JSON format.', 'performance-optimisation' ) );
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

				require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';

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
			WP_CLI::error( sprintf( __( 'Invalid settings action "%s". Use "get" or "update".', 'performance-optimisation' ), $action ) );
		}

		/**
		 * Manage Object Cache.
		 *
		 * ## OPTIONS
		 *
		 * <action>
		 * : Object Cache action to perform. Currently supported: flush.
		 *
		 * ## EXAMPLES
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
			$action = $args[0] ?? 'flush';

			if ( 'flush' !== $action ) {
				/* translators: %s: Object cache action name */
				WP_CLI::error( sprintf( __( 'Invalid object-cache action "%s". Did you mean "wp wppo object-cache flush"?', 'performance-optimisation' ), $action ) );
				return;
			}

			require_once WPPO_PLUGIN_PATH . 'includes/class-log.php';
			require_once WPPO_PLUGIN_PATH . 'includes/class-object-cache.php';

			$manager = new Object_Cache();
			$success = $manager->flush();

			if ( $success ) {
				Log::add( __( 'Flushed Redis Object Cache via WP-CLI', 'performance-optimisation' ) );
				WP_CLI::success( __( 'Redis Object Cache flushed successfully.', 'performance-optimisation' ) );
			} else {
				WP_CLI::error( __( 'Failed to flush Redis Object Cache or Redis server is unreachable.', 'performance-optimisation' ) );
			}
		}
	}
}
