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
		 * Version floor for the one-time legacy cache-key eviction.
		 *
		 * The legacy unsalted query-group keys (#489) can only exist once and were
		 * introduced before this release. Gating the flush on a fixed floor (rather
		 * than WPPO_VERSION) ensures future version bumps never re-run the eviction.
		 *
		 * @var string
		 * @since 1.8.1
		 */
		private const LEGACY_FLUSH_FLOOR = '1.8.1';

		/**
		 * Version floor for the one-time autoload=no backfills.
		 *
		 * The aggregate options (`wppo_img_info`, RUM aggregates, PageSpeed
		 * trends) created by older releases defaulted to autoload=yes. Releases
		 * at/above this floor already create them with autoload=no, so upgrades
		 * from such versions skip the backfill writes entirely instead of
		 * re-running them on every future version bump.
		 *
		 * @var string
		 * @since NEXT
		 */
		private const AUTOLOAD_BACKFILL_FLOOR = '2.0.0';

		/**
		 * Anchored match for an uncommented `define( 'WP_CACHE', false );`.
		 *
		 * Anchored to start-of-line (multiline `^` plus leading horizontal
		 * whitespace only) so commented defines (`// define(...)`,
		 * `# define(...)`, `/* define(...)`, `* define(...)`) can never match.
		 *
		 * @var string
		 * @since 2.2.0
		 */
		private const WP_CACHE_FALSE_PATTERN = '/^[ \t]*define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*false\s*\)\s*;/mi';

		/**
		 * Anchored match for an uncommented `define( 'WP_CACHE', true )`.
		 *
		 * Same anchoring as WP_CACHE_FALSE_PATTERN; used to assert a write
		 * really enabled the constant instead of trusting a bare substring.
		 *
		 * @var string
		 * @since 2.2.0
		 */
		private const WP_CACHE_TRUE_PATTERN = '/^[ \t]*define\(\s*[\'"]WP_CACHE[\'"]\s*,\s*true\s*\)/mi';

		/**
		 * Guarded WP_CACHE enable block appended when the constant is absent.
		 *
		 * @var string
		 * @since 2.2.0
		 */
		private const WP_CACHE_GUARD_BLOCK = "/** Enables WordPress Cache */\nif ( ! defined( 'WP_CACHE' ) ) {\n\tdefine( 'WP_CACHE', true );\n}\n";

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

			// Reinstall existence re-check: sweep orphan backup/tmp siblings
			// left by an incomplete teardown before creating the fresh
			// drop-in, so stale artifacts never survive a reinstall.
			// Fail-open: never blocks activation.
			try {
				if ( method_exists( 'PerformanceOptimise\Inc\Advanced_Cache_Handler', 'cleanup_stale_artifacts' ) ) {
					Advanced_Cache_Handler::cleanup_stale_artifacts();
				}
			} catch ( \Throwable $ignored_artifacts ) {
				unset( $ignored_artifacts );
			}

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

			$has_activation_time = (bool) get_option( 'wppo_activation_time' );
			if ( ! $has_activation_time ) {
				// Audit #1469: explicit no-autoload (write-once timestamp).
				update_option( 'wppo_activation_time', time(), false );
			}

			// Capture the stored version before rolling it forward below, so the
			// reactivation backfills can honor the same AUTOLOAD_BACKFILL_FLOOR
			// gate as maybe_run_upgrades() instead of running unconditionally.
			$stored_version_before_upgrade = get_option( self::VERSION_OPTION, '' );

			// Record the current version so fresh installs skip the one-time
			// version-upgrade routine (drop-in regeneration + full cache clear).
			update_option( 'wppo_version', WPPO_VERSION, false );

			$options             = Util::get_settings();
			$enable_server_rules = isset( $options['file_optimisation']['enableServerRules'] ) ? (bool) $options['file_optimisation']['enableServerRules'] : false;

			// Server gate: on Nginx (including multisite) .htaccess is
			// never evaluated — skip the write (update_rules() also gates
			// itself) instead of surfacing a spurious failure notice.
			$skip_htaccess = class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'should_skip_htaccess_write' ) && Server_Rules::should_skip_htaccess_write();

			if ( $enable_server_rules && ! $skip_htaccess ) {
				$rules_updated = Htaccess_Handler::update_rules( true );
				if ( ! $rules_updated ) {
					$notices[] = __( 'Failed to update .htaccess rules during activation.', 'performance-optimisation' );
					set_transient( Util::transient_key( 'wppo_activation_notices' ), array_unique( $notices ), WEEK_IN_SECONDS );
				}
			}

			self::maybe_seed_settings();
			self::create_activity_log_table();
			// One-time autoload backfills only for pre-existing installs that need
			// them: a fresh activation owns no legacy aggregate rows, so skip the
			// writes entirely (zero migrated rows on fresh installs). Gated on
			// the same AUTOLOAD_BACKFILL_FLOOR as maybe_run_upgrades() so
			// reactivation of a site already at/above the floor pays zero
			// backfill writes (and never re-wipes the field-LCP cache); a
			// missing/garbage stored version fails open and runs the cheap
			// idempotent backfills once, mirroring maybe_run_upgrades().
			$needs_autoload_backfill = (
			! is_string( $stored_version_before_upgrade )
			|| '' === $stored_version_before_upgrade
			|| ! self::is_plausible_version( $stored_version_before_upgrade )
			|| version_compare( $stored_version_before_upgrade, self::AUTOLOAD_BACKFILL_FLOOR, '<' )
			);
			if ( $has_activation_time && $needs_autoload_backfill ) {
				Img_Converter::migrate_img_info_autoload();
				RUM::migrate_rum_autoload();
				Pagespeed::migrate_trends_autoload();
			}
			self::maybe_run_upgrades( ! $has_activation_time );
		}

		/**
		 * Seed wppo_settings with defaults on fresh install so CLI works immediately.
		 *
		 * Without this, `get_option( 'wppo_settings', [] )` returns an empty array
		 * and the CLI reports “Available tabs: .” until the first admin save.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function maybe_seed_settings(): void {
			// allowlist(settings-read-guard): deliberate direct read — must distinguish
			// "no stored row" (null) from "stored value", which Util::get_settings()
			// normalizes to array(). See tests/php/SettingsReadGuardTest.php.
			$existing = get_option( 'wppo_settings', null );
			if ( null !== $existing ) {
				return;
			}

			// Canonical defaults live in Util::get_default_settings() (single source of
			// truth, #901). Previously duplicated here with drift (missing tabs/keys
			// and a hardcoded blockAssetsOnDemand); fresh installs now seed the
			// canonical array so Main, CLI, and activation agree.
			$defaults = Util::get_default_settings();

			add_option( 'wppo_settings', $defaults, '', false );
		}

		/**
		 * Runs one-time upgrade routines when the plugin version changes.
		 *
		 * Compares the stored plugin version option against the current plugin
		 * version and a fixed legacy floor, and executes version-specific
		 * migrations once. On fresh activation the activation hook fires and
		 * $is_fresh_install is true, so the version is recorded without
		 * allocating any migration rows. On routine plugin updates the
		 * activation hook does not fire, so Main hooks this into admin_init
		 * and a background cron event. The one-shot routines are version-gated
		 * (never marker-gated), so fresh installs create zero `*_migrated`
		 * rows and steady-state requests perform zero migration writes. The
		 * stored version is only rolled forward once a migration completes
		 * successfully.
		 *
		 * Fail-open: when the stored version is missing or unparseable on a
		 * non-fresh path, the cheap idempotent autoload backfills still run
		 * (so a legacy install that lost its version row does not keep
		 * autoload=yes bloat forever) while the expensive legacy cache flush
		 * is skipped, and the version still rolls forward rather than
		 * re-running writes. Uses per-site get_option() so multisite sites migrate
		 * independently with no cross-site leakage.
		 *
		 * @param bool $is_fresh_install True when running from a brand-new
		 *                               activation (no prior install on this site).
		 * @return void
		 * @since 1.8.1
		 */
		public static function maybe_run_upgrades( bool $is_fresh_install = false ): void {
			$stored_version = get_option( self::VERSION_OPTION, '' );

			// Brand-new install: no legacy keys can exist yet, so record the
			// version and skip the pointless full flush and log entry.
			// Guarded: never fatal when the version constant is unavailable.
			if ( $is_fresh_install ) {
				if ( defined( 'WPPO_VERSION' ) ) {
					update_option( self::VERSION_OPTION, WPPO_VERSION, false );
				}
				return;
			}

			// Fail-open: without a plausible stored version there is nothing to
			// compare against — run the cheap idempotent autoload backfills (so
			// a legacy install missing its version row still sheds autoload=yes
			// bloat) but skip the expensive legacy flush, then still roll the
			// version forward. Guarded: never fatal when the version constant
			// is unavailable.
			if ( ! is_string( $stored_version ) || '' === $stored_version || ! self::is_plausible_version( $stored_version ) ) {
				Img_Converter::migrate_img_info_autoload();
				RUM::migrate_rum_autoload();
				Pagespeed::migrate_trends_autoload();
				if ( defined( 'WPPO_VERSION' ) ) {
					update_option( self::VERSION_OPTION, WPPO_VERSION, false );
				}
				return;
			}

			// Steady state (fresh installs land here on every admin_init/cron
			// retry after activation recorded the current version): no
			// migration work remains, so return with zero reads beyond the
			// version check and zero writes.
			if ( ! defined( 'WPPO_VERSION' ) || version_compare( $stored_version, WPPO_VERSION, '>=' ) ) {
				return;
			}

			// Genuine upgrade path only: one-time backfill for the large
			// aggregate options created by older releases with autoload=yes.
			// Gated on a fixed floor (releases at/above it already create the
			// rows with autoload=no) so routine future version bumps pay zero
			// backfill writes and never wipe the field-LCP cache again. The
			// helpers are idempotent and the version roll-forward below keeps
			// them one-shot, so no marker option is allocated.
			if ( version_compare( $stored_version, self::AUTOLOAD_BACKFILL_FLOOR, '<' ) ) {
				Img_Converter::migrate_img_info_autoload();
				RUM::migrate_rum_autoload();
				Pagespeed::migrate_trends_autoload();
			}

			// One-time eviction: legacy unsalted keys can only exist once, on
			// installs that predate the release shipping this fix. Gate on a fixed
			// version floor so future version bumps never re-flush the shared cache.
			if ( version_compare( $stored_version, self::LEGACY_FLUSH_FLOOR, '>=' ) ) {
				update_option( self::VERSION_OPTION, WPPO_VERSION, false );
				return;
			}

			$flushed = Cache::flush_legacy_query_cache_keys();

			if ( ! $flushed ) {
				Log::add( __( 'Plugin upgrade: cache flush failed; will retry later.', 'performance-optimisation' ) );
				self::schedule_upgrade_routine( HOUR_IN_SECONDS );
				return;
			}

			Log::add( __( 'Plugin upgraded — legacy cache keys flushed.', 'performance-optimisation' ) );
			update_option( self::VERSION_OPTION, WPPO_VERSION, false );
		}

		/**
		 * Whether a stored version string is plausible enough to gate on.
		 *
		 * Guards the one-shot version gate against empty/garbage rows (e.g. a
		 * manually deleted or corrupted `wppo_version` option) so version
		 * detection failure fails open (cheap backfills only, no flush)
		 * instead of re-running migration writes on every request.
		 *
		 * @param string $stored_version Raw stored version value.
		 * @return bool True when the value looks like a dotted version number,
		 *              optionally followed by a single `-`/`+` prerelease/build
		 *              suffix (dots and hyphens allowed, e.g. `2.0.0-rc-1`).
		 * @since NEXT
		 */
		private static function is_plausible_version( string $stored_version ): bool {
			return (bool) preg_match( '/^\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?$/', $stored_version );
		}

		/**
		 * Schedules the upgrade routine to run in the background via WP-Cron.
		 *
		 * Provides a reliable trigger for sites updated through WP-CLI, background
		 * auto-updates, or managed-hosting pipelines where no admin visit ever
		 * fires admin_init.
		 *
		 * @param int $delay Delay in seconds before the event runs.
		 * @return void
		 * @since 1.8.1
		 */
		public static function schedule_upgrade_routine( int $delay = MINUTE_IN_SECONDS ): void {
			if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
				return;
			}
			if ( ! wp_next_scheduled( 'wppo_run_upgrades' ) ) {
				wp_schedule_single_event( time() + $delay, 'wppo_run_upgrades' );
			}
		}

		/**
		 * Builds the new wp-config.php contents enabling WP_CACHE.
		 *
		 * Pure content transform (no filesystem access) so the decision logic
		 * is unit-testable without defining the runtime WP_CACHE constant.
		 * Returns null when the file must be left untouched (fail-open):
		 * ambiguous content, an unparseable replacement, or a runtime-false
		 * constant with no literal uncommented false define to flip.
		 *
		 * @param string $contents Current wp-config.php contents.
		 * @param bool   $runtime_defined_false Whether WP_CACHE is already defined false at runtime.
		 * @return string|null New file contents, or null when no write should happen.
		 * @since 2.2.0
		 */
		private static function build_wp_cache_contents( string $contents, bool $runtime_defined_false ): ?string {
			// If WP_CACHE is defined as false, try to replace it with true.
			// The pattern is anchored to start-of-line (leading whitespace only)
			// so commented defines are never matched or uncommented.
			if ( $runtime_defined_false ) {
				$replaced    = 0;
				$new_content = preg_replace(
					self::WP_CACHE_FALSE_PATTERN,
					"define( 'WP_CACHE', true );",
					$contents,
					1,
					$replaced
				);

				if ( ! is_string( $new_content ) || '' === $new_content ) {
					Log::add( __( 'Failed to replace WP_CACHE in wp-config.php', 'performance-optimisation' ) );
					return null;
				}

				if ( 0 < $replaced ) { // Audit #1434: Yoda.
					return $new_content;
				}

				// No literal uncommented false define matched: the runtime false
				// comes from a non-literal define (e.g. `0`, a variable), an
				// external definition (mu-plugin, environment), or only a
				// commented occurrence exists. A guarded insert would be dead
				// code — the constant is already defined — and repeat
				// activations would keep appending duplicates, so leave the
				// file untouched (fail-open: cache stays unaccelerated).
				return null;
			}

			if ( false !== strpos( $contents, 'WP_CACHE' ) ) {
				// Already present but not necessarily true/false as literal (maybe a variable).
				// If it's already there and we reached here, it means defined( 'WP_CACHE' ) is false or not matching our expectations.
				// Deliberately fail-open here: a commented-only presence also takes this
				// path so ambiguous files are never touched.
				return null;
			}

			// Not present at all, add it.
			$insert_position = strpos( $contents, "/* That's all, stop editing!" );

			if ( false !== $insert_position ) {
				return substr_replace( $contents, self::WP_CACHE_GUARD_BLOCK, $insert_position, 0 );
			}

			return $contents . self::WP_CACHE_GUARD_BLOCK;
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

			$original_content = $wp_config_content;

			$wp_config_content = self::build_wp_cache_contents( $wp_config_content, defined( 'WP_CACHE' ) && ! WP_CACHE );

			if ( ! is_string( $wp_config_content ) ) {
				// Fail-open: ambiguous or externally-defined state; nothing to do.
				return null;
			}

			// Atomic path: tmp write + verify + backup + rename with rollback,
			// so a kill mid-write or full disk never leaves a truncated
			// wp-config.php. Falls back to the legacy direct write when the
			// filesystem transport cannot support atomic writes.
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'atomic_write_php_verified' ) ) {
				$atomic = Util::atomic_write_php_verified(
					$wp_filesystem,
					$wp_config_path,
					$wp_config_content,
					static function ( $contents ): bool {
						return is_string( $contents ) && (bool) preg_match( self::WP_CACHE_TRUE_PATTERN, $contents );
					}
				);
				if ( true === $atomic ) {
					return null;
				}
				if ( false === $atomic ) {
					return 'wp_config_write_failed';
				}
			}

			// Legacy direct-write fallback for transports without atomic support:
			// re-read and verify byte-identical so a torn write never goes
			// unnoticed. On mismatch restore the in-memory original best-effort
			// and report failure (fail-open: cache stays unaccelerated).
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;

			$restore_and_fail = static function () use ( $wp_filesystem, $wp_config_path, $original_content, $chmod ): string {
				try {
					$wp_filesystem->put_contents( $wp_config_path, $original_content, $chmod );
				} catch ( \Throwable $restore_failed ) {
					unset( $restore_failed );
				}
				return 'wp_config_write_failed';
			};

			$ok = $wp_filesystem->put_contents( $wp_config_path, $wp_config_content, $chmod );

			if ( ! $ok ) {
				return 'wp_config_write_failed';
			}

			$reread = $wp_filesystem->get_contents( $wp_config_path );
			if ( ! is_string( $reread ) || $reread !== $wp_config_content ) {
				return $restore_and_fail();
			}
			if ( ! (bool) preg_match( self::WP_CACHE_TRUE_PATTERN, $reread ) ) {
				return $restore_and_fail();
			}
			if ( method_exists( 'PerformanceOptimise\Inc\Util', 'verify_php_syntax' ) && ! Util::verify_php_syntax( $reread ) ) {
				return $restore_and_fail();
			}

			return null;
		}

		/**
		 * Creates the activity log table in the database if it doesn't exist.
		 *
		 * @return void
		 * @since 1.0.0
		 */
		private static function create_activity_log_table(): void {
			// Audit #1362: return type parity.
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
