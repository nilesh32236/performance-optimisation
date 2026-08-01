<?php
/**
 * Activate class for the PerformanceOptimise plugin.
 *
 * Handles the activation process by modifying .htaccess and creating static files.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Activate' ) ) {
	/**
	 * Class Activate
	 *
	 * Handles the plugin activation logic.
	 *
	 * @since 1.0.0
	 */
	class Activate {

		/**
		 * Option key storing the plugin version whose upgrade routines have run.
		 *
		 * @var string
		 * @since 1.8.1
		 */
		private const VERSION_OPTION = 'wppo_version';

		/**
		 * Initializes the activation process.
		 *
		 * Includes required files and triggers necessary modifications.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		public static function init(): void {
			$notices = array();

			if ( Advanced_Cache_Handler::foreign_dropin_present() ) {
				$notices[] = 'foreign_dropin';
			} else {
				Advanced_Cache_Handler::create();

				$wp_cache_notice = self::add_wp_cache_constant();
				if ( is_string( $wp_cache_notice ) ) {
					$notices[] = $wp_cache_notice;
				}
			}

			if ( ! empty( $notices ) ) {
				set_transient( Util::transient_key( 'wppo_activation_notices' ), array_unique( $notices ), WEEK_IN_SECONDS );
			} else {
				delete_transient( Util::transient_key( 'wppo_activation_notices' ) );
			}

			if ( ! get_option( 'wppo_activation_time' ) ) {
				update_option( 'wppo_activation_time', time() );
			}

			$options             = get_option( 'wppo_settings', array() );
			$enable_server_rules = isset( $options['file_optimisation']['enableServerRules'] ) ? (bool) $options['file_optimisation']['enableServerRules'] : false;

			if ( $enable_server_rules ) {
				$rules_updated = Htaccess_Handler::update_rules( true );
				if ( ! $rules_updated ) {
					$notices[] = __( 'Failed to update .htaccess rules during activation.', 'performance-optimisation' );
					set_transient( Util::transient_key( 'wppo_activation_notices' ), array_unique( $notices ), WEEK_IN_SECONDS );
				}
			}

			self::create_activity_log_table();
			Img_Converter::migrate_img_info_autoload();
			self::maybe_run_upgrades();
		}

		/**
		 * Runs one-time upgrade routines when the plugin version changes.
		 *
		 * Compares the stored plugin version option against the current constant and
		 * executes version-specific migrations once. On fresh activation the option
		 * is absent, so migrations run immediately. On routine plugin updates the
		 * activation hook does not fire, so Main hooks this into admin_init. The
		 * stored version is only updated after all migrations complete.
		 *
		 * @return void
		 * @since 1.8.1
		 */
		public static function maybe_run_upgrades(): void {
			$stored_version = get_option( self::VERSION_OPTION, '' );

			if ( WPPO_VERSION === $stored_version ) {
				return;
			}

			// One-time eviction of legacy WP 6.9 pre-salt query-group cache keys.
			Cache::flush_legacy_query_cache_keys();

			Log::add( __( 'Plugin upgraded — legacy cache keys flushed.', 'performance-optimisation' ) );

			update_option( self::VERSION_OPTION, WPPO_VERSION, false );
		}

		/**
		 * Adds the WP_CACHE guard block to wp-config.php when the constant is not enabled.
		 *
		 * @return string|null Notice key for the admin layer, or null if nothing to report.
		 * @since 1.0.0
		 */
		public static function add_wp_cache_constant(): ?string {
			global $wp_filesystem;

			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return null;
			}

			Util::init_filesystem();

			if ( ! $wp_filesystem && ! Util::init_filesystem() ) {
				return 'wp_config_fs';
			}

			$wp_config_path = wp_normalize_path( ABSPATH . 'wp-config.php' );

			if ( ! $wp_filesystem->exists( $wp_config_path ) ) {
				$wp_config_path = wp_normalize_path( dirname( ABSPATH ) . '/wp-config.php' );
			}

			if ( ! $wp_filesystem->exists( $wp_config_path ) || ! $wp_filesystem->is_writable( $wp_config_path ) ) {
				return 'wp_config_writable';
			}

			$wp_config_content = $wp_filesystem->get_contents( $wp_config_path );

			if ( ! is_string( $wp_config_content ) ) {
				return 'wp_config_read';
			}

			// If WP_CACHE is defined as false, try to replace it with true.
			if ( defined( 'WP_CACHE' ) && ! WP_CACHE ) {
				$new_content = preg_replace(
					'/define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\);/i',
					"define( 'WP_CACHE', true );",
					$wp_config_content
				);

				if ( null === $new_content || '' === $new_content ) {
					Log::add( __( 'Failed to replace WP_CACHE in wp-config.php', 'performance-optimisation' ) );
					return null;
				}
				$wp_config_content = $new_content;
			} elseif ( false !== strpos( $wp_config_content, 'WP_CACHE' ) ) {
				// Already present but not necessarily true/false as literal (maybe a variable).
				// If it's already there and we reached here, it means defined( 'WP_CACHE' ) is false or not matching our expectations.
				return null;
			} else {
				// Not present at all, add it.
				$constant_code = "/** Enables WordPress Cache */\nif ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true );\n}\n";

				$insert_position = strpos( $wp_config_content, "/* That's all, stop editing!" );

				if ( false !== $insert_position ) {
					$wp_config_content = substr_replace( $wp_config_content, $constant_code, $insert_position, 0 );
				} else {
					$wp_config_content .= $constant_code;
				}
			}

			$ok = $wp_filesystem->put_contents( $wp_config_path, $wp_config_content, FS_CHMOD_FILE );

			return $ok ? null : 'wp_config_write_failed';
		}

		/**
		 * Creates the activity log table in the database if it doesn't exist.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		private static function create_activity_log_table() {
			global $wpdb;

			$table_name      = $wpdb->prefix . 'wppo_activity_logs';
			$charset_collate = $wpdb->get_charset_collate();

			/* phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange */
			// Direct query is required here because WordPress does not offer APIs for custom table creation.
			// This operation is performed during plugin activation, so it does not require caching.
			// Schema changes are necessary during plugin activation to create a custom table for storing plugin-specific data.
			if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) !== $table_name ) {
				$create_table_sql = "CREATE TABLE $table_name (
					id mediumint(9) NOT NULL AUTO_INCREMENT,
					activity varchar(255) NOT NULL,
					created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
					PRIMARY KEY (id)
				) $charset_collate;";

				// Include the required file for dbDelta function.
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/upgrade.php' );
				dbDelta( $create_table_sql );
			}

			/* phpcs:enable */
			Log::add( __( 'Plugin activated', 'performance-optimisation' ) );
		}
	}
}
