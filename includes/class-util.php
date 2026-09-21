<?php
/**
 * PerformanceOptimise Utility Class
 *
 * This file contains the `Util` class, which provides various utility methods
 * for file system and resource management tasks, including cache directory creation,
 * filesystem initialization, URL processing, generating preload links, and handling image MIME types.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.0.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) ) {
	/**
	 * Utility class for performing various file system and resource management tasks.
	 *
	 * This class provides helper methods for managing cache directories, interacting
	 * with the WordPress filesystem API, processing URLs, generating preload links,
	 * and handling image MIME types.
	 *
	 * @since 1.0.0
	 */
	class Util {

		/**
		 * Top-level settings keys allowlisted for import and update operations.
		 *
		 * Single source of truth for REST (`update_settings`, `import_settings`),
		 * WP-CLI (`settings` subcommand) and the JS `ALLOWED_IMPORT_KEYS` guard.
		 * Changing this list requires a single edit; the JS copy in
		 * `src/components/PluginSetting.js` is kept in sync via `wppoSettings.allowedSettingsKeys`
		 * (see Main::enqueue_admin_scripts()) and a build-time comment.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		public const ALLOWED_SETTINGS_KEYS = array(
			'file_optimisation',
			'preload_settings',
			'image_optimisation',
			'database_cleanup',
			'object_cache',
			'performance_audit',
			'cache_settings',
			'litespeed_integration',
			'llms_txt',
			'od_integration',
			'bfcache',
			'perf_translations',
			'ai_adaptive',
			'edge_cache',
		);

		/**
		 * Allowed tab slugs for `update_settings`.
		 *
		 * Identical to ALLOWED_SETTINGS_KEYS — kept as an alias for semantic
		 * clarity at call-sites that validate a single tab.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		public const ALLOWED_SETTINGS_TABS = self::ALLOWED_SETTINGS_KEYS;

		/**
		 * Fixed plugin option names removed per-site on uninstall.
		 *
		 * Canonical list consumed by `UtilTest` membership/completeness checks;
		 * `uninstall.php` keeps an inline copy because it runs standalone
		 * (without the plugin's classes) under `WP_UNINSTALL_PLUGIN`. Keep the
		 * two in sync — the uninstall.php sync test guards against drift
		 * (audit #899).
		 *
		 * The `wppo_litespeed_purge_queue` entry is blog-prefixed at runtime via
		 * transient_key() on multisite; the list stores its base name.
		 *
		 * SYNC INVARIANT: `uninstall.php` runs standalone under
		 * WP_UNINSTALL_PLUGIN (without these classes loaded), so it keeps a
		 * parallel `$wppo_options` array. Every key added or removed here MUST
		 * be mirrored there, and vice-versa. `UninstallOptionsTest` guards the
		 * two lists against drift.
		 *
		 * @since 2.0.0
		 * @var string[]
		 */
		public const UNINSTALL_OPTIONS = array(
			'wppo_settings',
			'wppo_img_info',
			'wppo_transient_index',
			'wppo_preload_cron_offset',
			'wppo_last_db_cleanup',
			'wppo_version',
			'wppo_block_assets_migrated',
			'wppo_cache_last_cleared',
			'wppo_cache_last_cleared_time',
			'wppo_activation_time',
			'wppo_activity_cache_version',
			'wppo_audit_salt',
			'wppo_db_cleanup_salt',
			'wppo_activity_log_salt',
			'wppo_img_info_salt',
			'wppo_review_dismissed',
			'wppo_review_snoozed_until',
			'wppo_web_vitals_rum',
			'wppo_ai_model',
			'wppo_web_vitals_trends',
			'wppo_web_vitals_trends_lock',
			'wppo_web_vitals_last_rescan',
			'wppo_preload_cron_last_id',
			'wppo_preload_cron_migrated',
			'wppo_img_scan_cursor',
			'wppo_img_scan_cursor_max',
			'wppo_litespeed_purge_queue',
			'wppo_autoload_remediated',
			'wppo_autoload_migrated',
			// Option-leak hardening: salt options and runtime counters that are
			// written by the plugin but were previously absent from the list.
			'wppo_ccss_salt',                          // Critical_CSS::SALT_KEY.
			'wppo_sysinfo_salt',                       // System_Info::DROPIN_SALT_KEY.
			'wppo_rum_top_url_gen',                    // RUM top-URL generation counter (class-rum.php).
			'wppo_remove_query_strings_deprecated_logged', // Legacy removal marker from the retired #925 feature (class-main.php).
			'wppo_ai_anomaly_last_alarm',              // AI_Adaptive::ANOMALY_COOLDOWN_KEY.
			'wppo_object_cache_circuit',               // Object_Cache::CIRCUIT_OPTION.
			'wppo_object_cache_circuit_dismissed',     // Object_Cache::CIRCUIT_DISMISSED_OPTION.
			'wppo_used_css_last_full_regen',           // Used_CSS::LAST_FULL_REGEN_OPTION (issue #1107).
			'wppo_used_css_last_targeted_regen',       // Used_CSS::TARGETED_REGEN_OPTION (issue #1220).
			'wppo_ccss_last_full_regen',               // Critical_CSS::LAST_FULL_REGEN_OPTION (issue #1462).
			'wppo_ccss_last_targeted_regen',           // Critical_CSS::TARGETED_REGEN_OPTION (issue #1462).
			'wppo_settings_snapshot',                  // Single prior wppo_settings copy for one-click undo (issue #1144).
			'wppo_preload_queue',                      // Resumable sitemap preload queue (issue #1162).
			'wppo_ai_css_refresh_snapshots',           // AI_Adaptive::CSS_REFRESH_SNAPSHOT_OPTION (issue #1407).
			'wppo_callback_secret',                    // CALLBACK_SECRET_OPTION (issue #1347).
		);

		/**
		 * Option-name prefix shared by the dynamic per-strategy front-page LCP
		 * image URL options (`wppo_front_page_lcp_{mobile|desktop}`, see
		 * Pagespeed::store_lcp_image_url()). Deleted on uninstall via an
		 * options-table LIKE match because the strategy suffix is dynamic
		 * (audit #899).
		 *
		 * @since 2.0.0
		 * @var string
		 */
		public const FRONT_PAGE_LCP_OPTION_PREFIX = 'wppo_front_page_lcp_';

		/**
		 * Shared builder/core preview query-param list.
		 *
		 * Single source consumed by {@see is_editor_preview_path()} and every
		 * serve/store/preload guard so a new builder param added here reaches
		 * all three layers without regex drift.
		 *
		 * @since 2.2.0
		 * @var string[]
		 */
		public const EDITOR_PREVIEW_PARAMS = array(
			'elementor-preview',
			'et_fb',
			'et_pb_preview',
			'vc_action',
			'vc_editable',
			'bricks',
			'preview',
			'preview_id',
			'customize_changeset_uuid',
			'customizer',
		);

		/**
		 * Get the allowlisted top-level settings keys.
		 *
		 * @since 2.0.0
		 * @return string[]
		 */
		public static function get_allowed_settings_keys(): array {
			return self::ALLOWED_SETTINGS_KEYS;
		}

		/**
		 * Option name storing the single prior copy of `wppo_settings` for one-click undo.
		 *
		 * Written by {@see self::take_settings_snapshot()} before every settings
		 * save (REST `update_settings`/`import_settings`, WP-CLI
		 * `settings update`/`import`) and consumed by
		 * {@see self::restore_settings_snapshot()}. Multisite-safe: stored via
		 * plain per-site `get_option()`/`update_option()`, never network-wide.
		 *
		 * @since 2.2.0
		 * @var string
		 */
		public const SETTINGS_SNAPSHOT_OPTION = 'wppo_settings_snapshot';

		/**
		 * Read the stored prior-settings snapshot.
		 *
		 * @since 2.2.0
		 * @return array|null Snapshot array with `settings` + `taken_at` keys, or null when absent/malformed.
		 */
		public static function get_settings_snapshot(): ?array {
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return null;
				}
				$snapshot = get_option( self::SETTINGS_SNAPSHOT_OPTION, null );
				if ( ! is_array( $snapshot ) || ! isset( $snapshot['settings'] ) || ! is_array( $snapshot['settings'] ) ) {
					return null;
				}
				return $snapshot;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Store the given settings as the one-click-undo snapshot.
		 *
		 * Fail-open: any failure returns false and must never block the
		 * settings save that triggered it.
		 *
		 * @since 2.2.0
		 * @param array|null $settings Settings to snapshot (defaults to the current stored settings).
		 * @return bool True when the snapshot was written.
		 */
		public static function take_settings_snapshot( ?array $settings = null ): bool {
			try {
				if ( ! function_exists( 'update_option' ) ) {
					return false;
				}
				if ( null === $settings ) {
					$settings = self::get_settings();
				}
				$payload = array(
					'settings' => $settings,
					'taken_at' => function_exists( 'time' ) ? time() : 0,
				);
				return (bool) update_option( self::SETTINGS_SNAPSHOT_OPTION, $payload, false );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Restore `wppo_settings` from the stored snapshot.
		 *
		 * Fail-open: snapshot-restore failure leaves the current settings
		 * intact and returns null; never fatal.
		 *
		 * @since 2.2.0
		 * @return array|null The restored settings array, or null when no valid snapshot exists or the write failed.
		 */
		public static function restore_settings_snapshot(): ?array {
			try {
				$snapshot = self::get_settings_snapshot();
				if ( null === $snapshot ) {
					return null;
				}
				if ( ! function_exists( 'update_option' ) ) {
					return null;
				}
				$restored = $snapshot['settings'];
				// The memo holds the pre-restore DB state (kept fresh for
				// same-request writes by the update_option_wppo_settings
				// hook), so update_option()'s ambiguous false return can be
				// disambiguated without a direct get_option() read: false
				// with identical values means "unchanged" (success), false
				// with differing values means the write failed.
				$before  = self::get_settings();
				$updated = self::save_settings( $restored );
				self::set_settings_cache( $restored );
				if ( ! $updated && $before !== $restored ) {
					// Write failed: roll the memo back so it keeps
					// describing the intact current settings (fail-open).
					self::set_settings_cache( $before );
					return null;
				}
				return $restored;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Valid one-click optimization preset names.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const OPTIMIZATION_PRESET_NAMES = array( 'safe', 'balanced', 'aggressive' );

		/**
		 * Fail-safe keys that presets must never turn off.
		 *
		 * Balanced/Aggressive flip risky pipeline keys on, but these guards
		 * stay forced ON so builder previews, commerce flows, and the delay
		 * safe-mode can never be disabled by a one-click preset (fail-safe).
		 * Per-page exclusions (postmeta) are untouched by presets by design.
		 *
		 * @since NEXT
		 * @return array<string, array<string, bool>> Guards keyed by tab.
		 */
		public static function get_preset_safety_guards(): array {
			return array(
				'cache_settings'    => array(
					'wooSafeMode' => true,
				),
				'file_optimisation' => array(
					'delayJSSafeMode'       => true,
					'delayJSBuilderPreset'  => true,
					'delayJSCommercePreset' => true,
					'elementorSafeMode'     => true,
				),
			);
		}

		/**
		 * One-click Safe / Balanced / Aggressive preset definitions.
		 *
		 * Each preset maps to existing `wppo_settings` keys only (additive,
		 * no schema break). Safe mirrors the safe-by-default fresh-install
		 * baseline (page cache only); Balanced adds low-risk wins (HTML/CSS
		 * minify, defer, lazy load, preload cache, RUM); Aggressive enables
		 * the full pipeline with the safety guards from
		 * {@see self::get_preset_safety_guards()} forced ON.
		 *
		 * @since NEXT
		 * @return array<string, array<string, array<string, mixed>>> Preset name => tab => key => value.
		 */
		public static function get_optimization_presets(): array {
			return array(
				'safe'       => array(
					'cache_settings'     => array(
						'enableCache' => true,
					),
					'file_optimisation'  => array(
						'minifyHTML'        => false,
						'minifyJS'          => false,
						'minifyCSS'         => false,
						'deferJS'           => false,
						'delayJS'           => false,
						'combineCSS'        => false,
						'removeUnusedCSS'   => false,
						'criticalCSS'       => false,
						'delayJSSafeMode'   => true,
						'elementorSafeMode' => true,
					),
					'image_optimisation' => array(
						'lazyLoadImages' => true,
					),
					'preload_settings'   => array(
						'enablePreloadCache' => false,
					),
				),
				'balanced'   => array(
					'cache_settings'     => array(
						'enableCache' => true,
					),
					'file_optimisation'  => array(
						'minifyHTML'        => true,
						'minifyJS'          => false,
						'minifyCSS'         => true,
						'deferJS'           => true,
						'delayJS'           => false,
						'combineCSS'        => false,
						'removeUnusedCSS'   => false,
						'criticalCSS'       => false,
						'delayJSSafeMode'   => true,
						'elementorSafeMode' => true,
					),
					'image_optimisation' => array(
						'lazyLoadImages'           => true,
						'lazyLoadBackgroundImages' => true,
					),
					'preload_settings'   => array(
						'enablePreloadCache' => true,
					),
					'performance_audit'  => array(
						'rum_enabled' => true,
					),
				),
				'aggressive' => array(
					'cache_settings'     => array(
						'enableCache' => true,
					),
					'file_optimisation'  => array(
						'minifyHTML'            => true,
						'minifyJS'              => true,
						'minifyCSS'             => true,
						'deferJS'               => true,
						'delayJS'               => true,
						'combineCSS'            => true,
						'removeUnusedCSS'       => false,
						'criticalCSS'           => true,
						'delayJSSafeMode'       => true,
						'delayJSBuilderPreset'  => true,
						'delayJSCommercePreset' => true,
						'elementorSafeMode'     => true,
					),
					'image_optimisation' => array(
						'lazyLoadImages'           => true,
						'lazyLoadBackgroundImages' => true,
					),
					'preload_settings'   => array(
						'enablePreloadCache' => true,
					),
					'performance_audit'  => array(
						'rum_enabled' => true,
					),
				),
			);
		}

		/**
		 * Diff a preset against the current settings (preview before apply).
		 *
		 * Fail-open: unknown preset names or failures return an empty diff,
		 * never fatal. Only keys present in the preset definition are
		 * compared; per-page exclusions (postmeta) are never part of the diff.
		 *
		 * @since NEXT
		 * @param string     $preset  Preset name (safe|balanced|aggressive).
		 * @param array|null $current Optional current settings (defaults to get_settings()).
		 * @return array[] List of {tab, key, from, to} entries that would change.
		 */
		public static function get_preset_diff( string $preset, ?array $current = null ): array {
			try {
				$presets = self::get_optimization_presets();
				if ( ! isset( $presets[ $preset ] ) ) {
					return array();
				}
				if ( null === $current ) {
					$current = self::get_settings();
				}
				if ( ! is_array( $current ) ) {
					$current = array();
				}
				$diff = array();
				foreach ( $presets[ $preset ] as $tab => $keys ) {
					if ( ! is_array( $keys ) ) {
						continue;
					}
					$stored_tab = ( isset( $current[ $tab ] ) && is_array( $current[ $tab ] ) ) ? $current[ $tab ] : array();
					foreach ( $keys as $key => $to ) {
						$from = $stored_tab[ $key ] ?? null;
						if ( $from !== $to ) {
							$diff[] = array(
								'tab'  => $tab,
								'key'  => $key,
								'from' => $from,
								'to'   => $to,
							);
						}
					}
				}
				// Safety guards are forced ON at apply time: surface any
				// guard that would flip as part of the preview diff.
				// Skip guard keys already compared in the preset loop above
				// to avoid duplicate entries and an inflated change count.
				foreach ( self::get_preset_safety_guards() as $tab => $keys ) {
					$stored_tab  = ( isset( $current[ $tab ] ) && is_array( $current[ $tab ] ) ) ? $current[ $tab ] : array();
					$preset_keys = ( isset( $presets[ $preset ][ $tab ] ) && is_array( $presets[ $preset ][ $tab ] ) ) ? $presets[ $preset ][ $tab ] : array();
					foreach ( $keys as $key => $to ) {
						if ( array_key_exists( $key, $preset_keys ) ) {
							continue; // Already compared in the preset loop above.
						}
						$from = $stored_tab[ $key ] ?? null;
						if ( true === $to && true !== $from ) {
							$diff[] = array(
								'tab'  => $tab,
								'key'  => $key,
								'from' => $from,
								'to'   => $to,
							);
						}
					}
				}
				return $diff;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Apply a one-click optimization preset on top of the current settings.
		 *
		 * Snapshots a restore point after a successful overwrite (single-slot,
		 * fail-open) and forces the safety guards ON. Unknown preset names
		 * or write failures leave the prior settings intact and return null.
		 *
		 * @since NEXT
		 * @param string $preset Preset name (safe|balanced|aggressive).
		 * @return array|null {preset, settings, diff} on success, null on failure.
		 */
		public static function apply_optimization_preset( string $preset ): ?array {
			try {
				$presets = self::get_optimization_presets();
				if ( ! isset( $presets[ $preset ] ) ) {
					return null;
				}
				$current = self::get_settings();
				if ( ! is_array( $current ) ) {
					$current = array();
				}
				$merged = $current;
				foreach ( $presets[ $preset ] as $tab => $keys ) {
					if ( ! is_array( $keys ) ) {
						continue;
					}
					$base           = ( isset( $merged[ $tab ] ) && is_array( $merged[ $tab ] ) ) ? $merged[ $tab ] : array();
					$merged[ $tab ] = array_merge( $base, $keys );
				}
				foreach ( self::get_preset_safety_guards() as $tab => $keys ) {
					$base = ( isset( $merged[ $tab ] ) && is_array( $merged[ $tab ] ) ) ? $merged[ $tab ] : array();
					foreach ( $keys as $key => $to ) {
						$base[ $key ] = $to;
					}
					$merged[ $tab ] = $base;
				}
				if ( $merged === $current ) {
					return array(
						'preset'   => $preset,
						'settings' => $current,
						'diff'     => array(),
					);
				}
				$before  = $current;
				$updated = self::save_settings( $merged );
				self::set_settings_cache( $merged );
				if ( ! $updated && $before !== $merged ) {
					self::set_settings_cache( $before );
					return null; // Write failed: leave settings and snapshot untouched.
				}
				try {
					self::take_settings_snapshot( $before );
				} catch ( \Throwable $snapshot_error ) {
					unset( $snapshot_error );
				}
				return array(
					'preset'   => $preset,
					'settings' => $merged,
					'diff'     => self::get_preset_diff( $preset, $before ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}

		/**
		 * Get default settings structure for fresh installs.
		 *
		 * Single source of truth for Main::__construct() defaults,
		 * Activate::maybe_seed_settings(), and
		 * WPPO_CLI_Command::get_default_settings() to fix 7-tab drift
		 * (CLI:451 vs Main:240). Covers all allowed tabs; object_cache stays
		 * empty (no defaults) for BC, database_cleanup carries only the
		 * additive autoloadThreshold default (issue #934).
		 *
		 * Safe-by-default (issue #1144): fresh installs enable the page cache
		 * only. Every aggressive pipeline key (JS/CSS merge, delay/defer,
		 * unused-CSS removal, critical CSS, minification) stays OFF until
		 * explicitly enabled. Existing sites are unaffected: stored options
		 * are never rewritten by a defaults change.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, mixed>> Default settings keyed by tab.
		 */
		public static function get_default_settings(): array {
			return array(
				'cache_settings'        => array(
					'enableLoggedInCache'       => false,
					'loggedInCacheRoles'        => array(),
					'enableCache'               => true,
					'cacheLife'                 => 0,
					'ttlOverrides'              => array(),
					'wooSafeMode'               => true,
					'stampedeGuard'             => true,
					'stampedeLockTtl'           => 5,
					'cacheMaxSizeMB'            => 512,
					'cacheSizeWarnRatio'        => 0.8,
					'cacheSizeEnforce'          => true,
					'cacheMaxFiles'             => 5000,
					'cacheRandomizedQueryGuard' => true,
				),
				'file_optimisation'     => array(
					'enableServerRules'            => false,
					'cdnURL'                       => '',
					'cdnMapping'                   => array(),
					'removeUnusedCSS'              => false,
					'excludeUnusedCSS'             => '',
					'unusedCSSSafelistExtra'       => '',
					'unusedCSSRegressionGuard'     => true,
					'unusedCSSRegressionThreshold' => 20,
					'criticalCSS'                  => false,
					'ccssMaxSize'                  => 20480,
					'ccssSafelistExtra'            => '',
					'ccssRumPriority'              => true,
					'usedCssRumPriority'           => true,
					'ccssQueueCap'                 => 5,
					'ccssGenTimeout'               => 25,
					'ccssInlineBudgetKb'           => 14,
					'ccssCommerceExclude'          => true,
					'ccssChecksumRegen'            => true,
					'ccssExcludedPostTypes'        => "fl-builder-template\nelementor_library",
					'ccssMaxRetries'               => 5,
					'usedCssQueueCap'              => 50,
					'ccssViewportVariants'         => false,
					'usedCSSDeliveryMode'          => 'file',
					'hostGoogleFontsLocally'       => false,
					'blockAssetsOnDemand'          => function_exists( 'wp_load_classic_theme_block_styles_on_demand' ),
					'loadAllCoreBlockAssets'       => false,
					'delayJSDefaultStrategy'       => 'interaction',
					'delayJSPreset'                => 'safe',
					'delayJSINPPreset'             => false,
					'delayJSExternalOnly'          => false,
					'delayJSThirdParty'            => false,
					'delayJSThirdPartyAuto'        => false,
					'delayJSThirdPartyDenylist'    => '',
					'delayJSThirdPartyAllowlist'   => '',
					'delayJSBuilderPreset'         => true,
					'delayJSCommercePreset'        => true,
					'delayJSInteractionPreset'     => true,
					'delayJSConsentPreset'         => false,
					'delayJSAnalyticsPreset'       => false,
					'delayJSGalleryPreset'         => false,
					'delayJSJqueryPreset'          => false,
					'delayJSExcludeUrls'           => '',
					'usedCSSExcludeUrls'           => '',
					'delayJSIdleList'              => '',
					'delayJSViewportList'          => '',
					'delayJSPriority'              => '',
					'delayJSIdleTimeout'           => 3000,
					'minifyHTML'                   => false,
					'minifyJS'                     => false,
					'minifyCSS'                    => false,
					'deferJS'                      => false,
					'delayJS'                      => false,
					'delayJSSafeMode'              => true,
					'safeMode'                     => false,
					'elementorSafeMode'            => true,
					'sandboxStaged'                => array(),
					'combineCSS'                   => false,
					'excludeJS'                    => '',
					'excludeCSS'                   => '',
					'excludeDeferJS'               => '',
					'excludeDelayJS'               => '',
					'excludeCombineCSS'            => '',
					'minifyInlineCSS'              => false,
					'minifyInlineJS'               => false,
					'removeHTMLComments'           => true,
					'disableRestApiLinks'          => false,
					'disableRssFeeds'              => false,
					'disableShortlinks'            => false,
					'disableGeneratorTag'          => false,
					'disableJQueryMigrate'         => false,
					'disablePasswordStrength'      => false,
					'disableSelfPingbacks'         => false,
					'disableRSD'                   => false,
					'disableWLWManifest'           => false,
					'disableGlobalStyles'          => false,
					'disableClassicThemeStyles'    => false,
					'disableWooCartFragments'      => false,
					'disableRecentCommentsStyle'   => false,
					'disableCommentReply'          => false,
					'disableOEmbedDiscovery'       => false,
					'disableBlockWidgets'          => false,
					'fontMetricFallback'           => false,
					'fontSubset'                   => false,
					'fontSubsetSubsets'            => 'latin',
					'purgeFallbackEnabled'         => false,
					'builderPurgeWatcher'          => true,
					'builderPurgeDriftLog'         => true,
				),
				'preload_settings'      => array(
					'enablePreloadCache'       => false,
					'excludePreloadCache'      => "my-account/(.*)\ncart/(.*)\ncheckout/(.*)",
					'enableSpeculationRules'   => false,
					'speculationMode'          => 'prefetch',
					'speculationEagerness'     => 'conservative',
					'speculationRumGating'     => true,
					'speculationTopUrlsLimit'  => 2,
					'speculationPrerenderList' => false,
					'speculationExcludeUrls'   => '',
					'speculationDocumentRules' => true,
					'preloadSitemap'           => false,
					'autoLcpPreload'           => false,
					'autoDiscoverFonts'        => false,
				),
				'image_optimisation'    => array(
					'lazyLoadImages'             => false,
					'lazyLoadNative'             => true,
					'placeholderType'            => 'svg',
					'autoPreloadLCP'             => false,
					'prioritizeLCPImages'        => false,
					'lcpHeroPreload'             => true,
					'lcp_guardrails'             => true,
					'lcp_first_n'                => 3,
					'clientSideMimeTypeOverride' => false,
					'clientSideMimeTypes'        => array(),
					'lazyLoadBackgroundImages'   => false,
					'avifFirst'                  => true,
					'smartQuality'               => true,
					'skipSmallThresholdBytes'    => 5120,
					'discardOversizedSibling'    => true,
					'fieldLcpOverride'           => false,
					'fieldLcpMinSamples'         => 20,
					'cssHeroPreload'             => false,
					'autoAltText'                => false,
					'maxLongestEdgePx'           => 2560,
					'lazyRenderBelowFold'        => false,
					'lazyRenderExcludeBuilders'  => true,
					'hardenCommentImages'        => true,
					'occlusionFetchpriorityLow'  => false,
				),
				'performance_audit'     => array(
					'pagespeed_api_key'     => '',
					'high_value_urls'       => array(),
					'auto_fix_enabled'      => false,
					'server_timing_enabled' => false,
					'auto_rescan'           => '',
					'rum_enabled'           => false,
					'rum_sample_rate'       => 100,
				),
				'database_cleanup'      => array(
					'autoloadThreshold'  => 1024,
					'purgeFailedActions' => false,
				),
				'object_cache'          => array(),
				'litespeed_integration' => array(
					'mode'                 => 'auto',
					'enableNextGenRewrite' => false,
					'enableBrotli'         => false,
					'purgeSync'            => true,
					'varyGroups'           => array(
						'guest'  => false,
						'mobile' => false,
						'webp'   => false,
					),
					'crawler'              => array(
						'concurrency'        => 2,
						'loadLimit'          => 0,
						'blacklistThreshold' => 3,
					),
					'esi'                  => array(
						'enabled' => false,
					),
				),
				'llms_txt'              => array(
					'enabled' => false,
					'source'  => 'both',
				),
				'od_integration'        => array(
					'enabled' => class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' ),
				),
				'bfcache'               => array(
					'enabled' => false,
				),
				'perf_translations'     => array(
					'enabled' => false,
				),
				'ai_adaptive'           => array(
					'enabled'                       => false,
					'use_wp_ai_client'              => false,
					'field_lcp_min_samples'         => 20,
					'dismissed_suggestions'         => array(),
					'anomaly_cooldown_days'         => 7,
					'anomaly_min_samples'           => 10,
					'css_refresh_on_lcp_regression' => false,
					'css_refresh_cooldown_days'     => 7,
					'speculation_autotune_enabled'  => false,
					'speculation_min_samples'       => 20,
					'speculation_max_urls'          => 5,
					'anomaly_tolerance_pct'         => 5.0,
					'anomaly_tolerance_abs'         => 0.01,
					'anomaly_persistence_windows'   => 3,
					'anomaly_p75_min_samples'       => 10,
				),
				'edge_cache'            => array(
					'enabled' => false,
				),
			);
		}

		/**
		 * Canonical settings schema for verify/validation.
		 *
		 * Decoupled from {@see self::get_default_settings()} so the schema can
		 * recognise every key the SPA persists WITHOUT seeding those keys as
		 * runtime defaults (which would change fresh-install / CLI-merged
		 * behavior). Returns `tab => [ subkey => 'array'|'scalar' ]`, the
		 * expected shape consumed by
		 * WPPO_CLI_Command::validate_settings_schema().
		 *
		 * Built from the union of the runtime defaults and the SPA-persisted
		 * keys surfaced by the drift-guard scan in
		 * tests/php/SettingsSchemaDefaultsTest.php. Replace that pragmatic
		 * source scan with a localized `wppoSettings.schema` when available.
		 *
		 * @since 2.0.0
		 * @return array<string, array<string, string>> Schema keyed by tab.
		 */
		public static function get_settings_schema(): array {
			$schema = array();
			foreach ( self::get_default_settings() as $tab => $values ) {
				$schema[ $tab ] = array();
				if ( ! is_array( $values ) ) {
					continue;
				}
				foreach ( $values as $key => $value ) {
					$schema[ $tab ][ $key ] = is_array( $value ) ? 'array' : 'scalar';
				}
			}

			// SPA-persisted keys absent from the runtime defaults.
			$spa_only = array(
				'cache_settings'     => array(
					'cdnPurgeService'  => 'scalar',
					'cloudflareZoneId' => 'scalar',
					'varnishPurgeUrls' => 'array',
				),
				'file_optimisation'  => array(
					'removeQueryStrings'    => 'scalar', // Pruned #1373: legacy clients may still post it; sanitized then dropped on save.
					'removeWooCSSJS'        => 'scalar',
					'excludeUrlToKeepJSCSS' => 'scalar',
					'removeCssJsHandle'     => 'scalar',
					'disableEmojis'         => 'scalar',
					'disableEmbeds'         => 'scalar',
					'disableDashicons'      => 'scalar',
					'disableXMLRPC'         => 'scalar',
					'heartbeatControl'      => 'scalar',
				),
				'preload_settings'   => array(
					'preconnect'         => 'scalar',
					'preconnectOrigins'  => 'scalar',
					'prefetchDNS'        => 'scalar',
					'dnsPrefetchOrigins' => 'scalar',
					'preloadFonts'       => 'scalar',
					'preloadFontsUrls'   => 'scalar',
					'preloadCSS'         => 'scalar',
					'preloadCSSUrls'     => 'scalar',
				),
				'image_optimisation' => array(
					'wrapInPicture'              => 'scalar',
					'excludeFirstImages'         => 'scalar',
					'excludeImages'              => 'scalar',
					'lazyLoadVideos'             => 'scalar',
					'enableVideoPlaceholder'     => 'scalar',
					'excludeVideos'              => 'scalar',
					'convertImg'                 => 'scalar',
					'conversionFormat'           => 'scalar',
					'excludeConvertImages'       => 'scalar',
					'preloadFrontPageImages'     => 'scalar',
					'preloadFrontPageImagesUrls' => 'scalar',
					'preloadPostTypeImage'       => 'scalar',
					'selectedPostType'           => 'array',
					'availablePostTypes'         => 'array',
					'excludePostTypeImgUrl'      => 'scalar',
					'maxWidthImgSize'            => 'scalar',
					'excludeSize'                => 'scalar',
					'forceServerSideConversion'  => 'scalar',
				),
				'edge_cache'         => array(
					'provider'             => 'scalar',
					'ttl'                  => 'scalar',
					'staleWhileRevalidate' => 'scalar',
					'cloudflareZoneId'     => 'scalar',
					'bunnyPullZoneId'      => 'scalar',
				),
				'database_cleanup'   => array(
					'dbSchedule'         => 'scalar',
					'dbRevMaxAge'        => 'scalar',
					'dbRevKeepLatest'    => 'scalar',
					'dbOptimize'         => 'scalar',
					'autoloadThreshold'  => 'scalar',
					'purgeFailedActions' => 'scalar',
				),
				// Mirrors Object_Cache::ALLOWED_KEYS. `password` is stripped by
				// the REST layer but may survive in imported/legacy payloads.
				// `outage_bypassed` is the additive persistent outage status
				// flag (issue #1233): never a connection credential, only the
				// degraded-state signal cleared on ping/enable recovery. It is
				// server-only (REST update/import strip client values), kept
				// in the schema so legacy payloads validate, and normalized
				// to bool on sanitize (see sanitize_settings_recursively).
				'object_cache'       => array(
					'mode'            => 'scalar',
					'host'            => 'scalar',
					'port'            => 'scalar',
					'password'        => 'scalar',
					'database'        => 'scalar',
					'timeout'         => 'scalar',
					'prefix'          => 'scalar',
					'nodes'           => 'scalar',
					'master_name'     => 'scalar',
					'use_tls'         => 'scalar',
					'persistent'      => 'scalar',
					'compression'     => 'scalar',
					'outage_bypassed' => 'scalar',
				),
			);

			foreach ( $spa_only as $tab => $keys ) {
				if ( ! isset( $schema[ $tab ] ) || ! is_array( $schema[ $tab ] ) ) {
					$schema[ $tab ] = array();
				}
				foreach ( $keys as $key => $type ) {
					$schema[ $tab ][ $key ] = $type;
				}
			}

			return $schema;
		}

		/**
		 * Whether WooCommerce safe mode is enabled.
		 *
		 * Single toggle for all Woo dynamic-page guards (cache, delay,
		 * used-CSS, preload). Absent key defaults to enabled (fail-safe);
		 * explicit false disables. Malformed values normalize to enabled.
		 *
		 * @since 2.0.0
		 * @param array|null $settings Optional settings array (defaults to get_settings()).
		 * @return bool True when safe mode is enabled.
		 */
		public static function is_woo_safe_mode_enabled( ?array $settings = null ): bool {
			try {
				if ( null === $settings ) {
					$settings = self::get_settings();
				}
				if ( ! isset( $settings['cache_settings']['wooSafeMode'] ) ) {
					return true;
				}
				$value = $settings['cache_settings']['wooSafeMode'];
				if ( ! is_scalar( $value ) && null !== $value ) {
					return true;
				}
				$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				return null === $parsed ? true : $parsed;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a normalized request path is a WooCommerce Store API route.
		 *
		 * Matches `wc/store`, `wcstore`, `wp-json/wc/store*`, and
		 * `wp-json/wcstore*` as path segments (case-insensitive), plus the
		 * plain-permalink `?rest_route=/wc/store/...` form (pass the
		 * `rest_route` query value directly — it normalizes to the same
		 * Store API path). Store API responses are dynamic JSON and must
		 * never be cached, delayed, or preloaded — unconditional on
		 * safe-mode toggle.
		 *
		 * @since 2.0.0
		 * @param string $path Request path (leading slash optional) or a `rest_route` value.
		 * @return bool True when the path is a Store API route.
		 */
		public static function is_woo_store_api_path( string $path ): bool {
			$normalized = strtolower( trim( (string) $path, '/' ) );
			if ( '' === $normalized ) {
				return false;
			}
			// Plain permalinks pass rest_route=/wc/store/... as the path or
			// query value — strip a leading rest_route= wrapper if present.
			if ( 0 === strpos( $normalized, 'rest_route=' ) ) {
				$normalized = trim( substr( $normalized, strlen( 'rest_route=' ) ), '/' );
			}
			// URL-encoded rest_route values (e.g. %2Fwc%2Fstore%2Fv1%2Fcart).
			if ( false !== strpos( $normalized, '%' ) ) {
				$decoded = strtolower( trim( (string) rawurldecode( $normalized ), '/' ) );
				if ( '' !== $decoded ) {
					$normalized = $decoded;
				}
			}
			if ( '' === $normalized ) {
				return false;
			}
			return (bool) preg_match( '#(^|/)(?:wc/store|wcstore|wp-json/wc/store|wp-json/wcstore)(/|$)#i', '/' . $normalized );
		}

		/**
		 * Whether the current request targets a WooCommerce Store API route.
		 *
		 * Checks the request path plus the plain-permalink `rest_route` query
		 * parameter and raw `QUERY_STRING` so `?rest_route=/wc/store/v1/cart`
		 * (path `/`) is treated as Store API across all layers. Fail-open:
		 * detection failure returns true (treated as dynamic).
		 *
		 * @since 2.0.0
		 * @param string      $path         Request path (leading slash optional).
		 * @param string|null $query_string Optional raw query string (defaults to `$_SERVER['QUERY_STRING']`).
		 * @param string|null $rest_route   Optional `rest_route` value (defaults to `$_GET['rest_route']`).
		 * @return bool True when the request is a Store API request.
		 */
		public static function is_woo_store_api_request( string $path = '', ?string $query_string = null, ?string $rest_route = null ): bool {
			try {
				if ( '' !== $path && self::is_woo_store_api_path( $path ) ) {
					return true;
				}
				if ( null === $rest_route ) {
					$rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
					if ( '' === $rest_route ) {
						$rest_route = null;
					}
				}
				if ( is_string( $rest_route ) && '' !== $rest_route && self::is_woo_store_api_path( $rest_route ) ) {
					return true;
				}
				if ( null === $query_string ) {
					$query_string = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed before sanitizing; read-only routing check, no output.
				}
				if ( is_string( $query_string ) && '' !== $query_string && preg_match( '#rest_route=[^&]*(?:wc/store|wcstore)#i', rawurldecode( $query_string ) ) ) {
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a request path belongs to a WooCommerce dynamic page.
		 *
		 * Matches every path from {@see get_woo_excluded_paths()} as a full
		 * path segment anywhere in the request path (covers nested
		 * `shop/basket` and subdirectory / multisite prefixes such as
		 * `/subsite/cart`; intentionally fail-safe — a non-Woo page like
		 * `/blog/checkout/` is also treated as dynamic rather than risk
		 * caching checkout content) plus Store API routes. Fail-open: any
		 * detection failure returns true (treated as dynamic, never fatal).
		 *
		 * @since 2.0.0
		 * @param string $path Request path (leading slash optional).
		 * @return bool True when the path is Woo-dynamic.
		 */
		public static function is_woo_dynamic_path( string $path ): bool {
			try {
				if ( self::is_woo_store_api_path( $path ) ) {
					return true;
				}
				$normalized = strtolower( trim( (string) $path, '/' ) );
				if ( '' === $normalized ) {
					return false;
				}
				foreach ( self::get_woo_excluded_paths() as $excluded ) {
					$candidate = strtolower( trim( (string) $excluded, '/' ) );
					if ( '' === $candidate ) {
						continue;
					}
					// Anywhere-segment fail-safe semantics: match the candidate as a
					// full path segment anywhere in the request path (covers nested
					// shop/basket and subdirectory / multisite prefixes such as
					// /subsite/cart; a non-Woo page containing the segment is also
					// treated as dynamic).
					if ( (bool) preg_match( '#/(?:' . preg_quote( $candidate, '#' ) . ')(/|$)#i', '/' . $normalized ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Relative paths treated as WooCommerce endpoints for static-cache bypass.
		 *
		 * Defaults cover stock permalinks (`cart`, `checkout`, `my-account`). When
		 * WooCommerce is active, the configured page paths (`wc_get_page_id()` for
		 * `cart`/`checkout`/`myaccount`, resolved via permalink path with a
		 * `post_name` fallback) are merged in so custom slugs (e.g. `/basket/`,
		 * including nested pages like `shop/basket`) are excluded too — including
		 * in the pre-boot `advanced-cache.php` drop-in, which cannot call
		 * `is_cart()`/`is_checkout()`/`is_account_page()`. Fail-soft: any
		 * resolution failure returns the defaults (never fatal, 0 queries when
		 * Woo is absent).
		 *
		 * @since 2.0.0
		 * @return string[] Relative paths (e.g. `cart`, `shop/basket`), unique, lowercased.
		 */
		public static function get_woo_excluded_paths(): array {
			$paths = array( 'cart', 'checkout', 'my-account' );
			try {
				if ( ! function_exists( 'wc_get_page_id' ) ) {
					return $paths;
				}
				foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page_key ) {
					$page_id = (int) wc_get_page_id( $page_key );
					if ( $page_id <= 0 ) {
						continue;
					}
					$path = '';
					if ( function_exists( 'get_permalink' ) ) {
						$permalink = get_permalink( $page_id );
						if ( is_string( $permalink ) && '' !== $permalink ) {
							$parsed = wp_parse_url( $permalink, PHP_URL_PATH );
							if ( is_string( $parsed ) && '' !== trim( $parsed, '/' ) ) {
								$path = strtolower( trim( $parsed, '/' ) );
							}
						}
					}
					if ( '' === $path && function_exists( 'get_post_field' ) ) {
						$path = strtolower( (string) get_post_field( 'post_name', $page_id ) );
					}
					$segments = array_values(
						array_filter(
							array_map(
								static function ( $segment ) {
									$segment = strtolower( trim( (string) $segment ) );
									// Keep unicode letters/numbers so translated
									// slugs match exactly as resolved; strip only
									// control characters. Matches the raw request
									// path comparison in is_woo_dynamic_path().
									$segment = (string) preg_replace( '/[\x00-\x1F\x7F]/u', '', $segment );
									return trim( $segment, '/' );
								},
								explode( '/', $path )
							)
						)
					);
					if ( empty( $segments ) ) {
						continue;
					}
					$candidate = implode( '/', $segments );
					$seen      = array_map( 'strtolower', $paths );
					if ( ! in_array( $candidate, $seen, true ) ) {
						$paths[] = $candidate;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return $paths;
		}

		/**
		 * Whether WooCommerce is active on the current site.
		 *
		 * Guard for every Woo conditional call: `class_exists( 'WooCommerce' )`
		 * covers the plugin bootstrap while the `function_exists()` checks
		 * cover its conditional tags / page resolver. Multisite-safe:
		 * per-site detection only, no cross-site state.
		 *
		 * @since 2.0.0
		 * @return bool True when any WooCommerce symbol is available.
		 */
		public static function is_woo_active(): bool {
			try {
				return class_exists( 'WooCommerce', false )
					|| function_exists( 'is_woocommerce' )
					|| function_exists( 'is_cart' )
					|| function_exists( 'is_checkout' )
					|| function_exists( 'is_account_page' )
					|| function_exists( 'wc_get_page_id' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a query string carries WooCommerce layered-nav / faceted-filter params.
		 *
		 * Matches faceted param names case-insensitively (`&`/`;` split,
		 * URL-decoded, same bound discipline as {@see has_uncacheable_query()}):
		 * `filter_*`, `query_type_*`, `min_price`, `max_price`,
		 * `rating_filter`, `orderby`, `product_cat` (query form), `pa_*`,
		 * `attribute_*`, `gpf_*`. Custom names can be appended via the
		 * `wppo_woo_faceted_query_params` filter (guarded by `has_filter()`).
		 * Pure static helper: no I/O, multisite-safe. Fail-open: detection
		 * failure returns true (treated as faceted, never preloaded/cached).
		 *
		 * @since 2.2.0
		 * @param string|null $query_string Raw query string. Defaults to `$_SERVER['QUERY_STRING']`.
		 * @return bool True when faceted params are present.
		 */
		public static function is_woo_faceted_query( ?string $query_string = null ): bool {
			try {
				if ( null === $query_string ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized below via wp_unslash()/sanitize_text_field() with function_exists() fallbacks.
					$raw = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
					if ( function_exists( 'wp_unslash' ) ) {
						$raw = wp_unslash( $raw );
					}
					if ( function_exists( 'sanitize_text_field' ) ) {
						$raw = sanitize_text_field( $raw );
					}
					$query_string = $raw;
				}
				if ( '' === trim( (string) $query_string ) ) {
					return false;
				}
				if ( strlen( (string) $query_string ) > 5000 ) {
					return true;
				}
				$query_string = substr( (string) $query_string, 0, 5000 );
				$extra        = array();
				try {
					if ( function_exists( 'has_filter' ) && function_exists( 'apply_filters' ) && has_filter( 'wppo_woo_faceted_query_params' ) ) {
						$filtered = apply_filters( 'wppo_woo_faceted_query_params', array() ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- Filter documented in docs/hooks.md.
						if ( is_array( $filtered ) ) {
							foreach ( $filtered as $name ) {
								if ( is_string( $name ) && '' !== trim( $name ) ) {
									$extra[] = strtolower( trim( $name ) );
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				$pairs = preg_split( '/[&;]/', (string) $query_string );
				if ( ! is_array( $pairs ) ) {
					return true;
				}
				$checked = 0;
				foreach ( $pairs as $pair ) {
					$pair = trim( (string) $pair );
					if ( '' === $pair ) {
						continue;
					}
					$eq_pos = strpos( $pair, '=' );
					$name   = false === $eq_pos ? $pair : substr( $pair, 0, $eq_pos );
					$name   = strtolower( trim( (string) rawurldecode( $name ) ) );
					if ( '' === $name ) {
						continue;
					}
					++$checked;
					if ( $checked > 200 ) {
						return true;
					}
					if ( in_array( $name, $extra, true ) ) {
						return true;
					}
					if ( 0 === strpos( $name, 'filter_' ) || 0 === strpos( $name, 'query_type_' ) || 0 === strpos( $name, 'pa_' ) || 0 === strpos( $name, 'attribute_' ) || 0 === strpos( $name, 'gpf_' ) ) {
						return true;
					}
					if ( 'min_price' === $name || 'max_price' === $name || 'rating_filter' === $name || 'orderby' === $name || 'product_cat' === $name ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a request path targets a wp-admin / login / AJAX entry point.
		 *
		 * Matches `wp-admin`, `wp-login.php`, and `admin-ajax.php` as full path
		 * segments (case-insensitive) so `/wp-admin/`, `/wp-admin/admin-ajax.php`,
		 * and subdirectory installs (`/subsite/wp-admin/`) all bypass the static
		 * cache. Fail-open: detection failure returns true (never cached).
		 *
		 * @since 2.2.0
		 * @param string $path Request path (leading slash optional).
		 * @return bool True when the path is an admin entry point.
		 */
		public static function is_admin_path( string $path ): bool {
			try {
				$normalized = strtolower( trim( (string) $path, '/' ) );
				if ( '' === $normalized ) {
					return false;
				}
				return (bool) preg_match( '#(^|/)(?:wp-admin|wp-login\.php|admin-ajax\.php)(/|$)#i', '/' . $normalized );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether path/query values indicate a builder or core preview context.
		 *
		 * Pure string check (no conditional tags): builder edit/preview params
		 * (`elementor-preview`, `et_fb`, `et_pb_preview`, `vc_action`,
		 * `vc_editable`, `bricks`), core preview params (`preview`,
		 * `preview_id`, `customize_changeset_uuid`, `customizer`), plus admin
		 * paths via {@see is_admin_path()}. Used by the static-cache serve/store
		 * guards and by preload exclusion. Fail-open: detection failure returns
		 * true (treated as preview, never cached).
		 *
		 * @since 2.2.0
		 * @param string $path         Request path (leading slash optional).
		 * @param string $query_string Raw query string (without leading `?`).
		 * @param string $rest_route   Optional `rest_route` value (plain permalinks).
		 * @return bool True when the values indicate a preview context.
		 */
		public static function is_editor_preview_path( string $path = '', string $query_string = '', string $rest_route = '' ): bool {
			try {
				if ( '' !== $path && self::is_admin_path( $path ) ) {
					return true;
				}
				$params = array();
				if ( '' !== $query_string ) {
					$parsed = array();
					parse_str( (string) $query_string, $parsed );
					if ( is_array( $parsed ) ) {
						$params = $parsed;
					}
				}
				if ( '' !== $rest_route ) {
					$parsed = array();
					parse_str( ltrim( (string) $rest_route, '?&' ), $parsed );
					if ( is_array( $parsed ) ) {
						foreach ( $parsed as $k => $v ) {
							if ( ! array_key_exists( (string) $k, $params ) ) {
								$params[ (string) $k ] = $v;
							}
						}
					}
					// Plain-permalink rest_route value itself (e.g. `/`) carries no
					// preview signal; only its query params matter (merged above).
				}
				// Superglobal fallback: query-only callers (Cron passes path only)
				// still catch `?elementor-preview=` etc. on the current request.
				if ( empty( $params ) && ( '' === $query_string ) ) {
					foreach ( self::EDITOR_PREVIEW_PARAMS as $key ) {
						if ( isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change.
							return true;
						}
					}
					return false;
				}
				foreach ( self::EDITOR_PREVIEW_PARAMS as $key ) {
					if ( array_key_exists( $key, $params ) ) {
						return true;
					}
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether path/query values indicate a WooCommerce AJAX endpoint.
		 *
		 * Pure string check (no Woo symbols, no I/O): matches the
		 * pretty-permalink `/wc-ajax/...` path segment (decoded,
		 * case-insensitive, exact segment so `/my-wc-ajax-guide/` does not
		 * match) and the `?wc-ajax=...` query parameter (parsed name match
		 * plus a raw query-string fallback, `&`/`;` separated,
		 * case-insensitive). Mirrors `Cache::is_wc_ajax_request()` and the
		 * pre-boot drop-in guard. Unconditional on safe mode: wc-ajax XHRs
		 * are dynamic JSON and must never be cached or preloaded.
		 * Fail-open: detection failure returns true (treated as dynamic).
		 *
		 * @since NEXT
		 * @param string $path         Request path (leading slash optional).
		 * @param string $query_string Raw query string (without leading `?`).
		 * @return bool True when the values indicate a wc-ajax request.
		 */
		public static function is_woo_ajax_request( string $path = '', string $query_string = '' ): bool {
			try {
				$normalized = strtolower( trim( (string) rawurldecode( $path ), '/' ) );
				if ( '' !== $normalized && (bool) preg_match( '#(^|/)wc-ajax(/|$)#i', '/' . $normalized ) ) {
					return true;
				}
				if ( '' === trim( (string) $query_string ) ) {
					return false;
				}
				$parsed = array();
				try {
					parse_str( (string) $query_string, $parsed );
				} catch ( \Throwable $e ) {
					unset( $e );
					$parsed = array();
				}
				foreach ( $parsed as $name => $value ) {
					if ( 'wc-ajax' === strtolower( trim( (string) $name ) ) ) {
						return true;
					}
				}
				return (bool) preg_match( '/(?:^|[&;])wc-ajax(?:=|&|;|$)/i', (string) $query_string );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a query string carries a WooCommerce add-to-cart action.
		 *
		 * Pure string check (no Woo symbols, no I/O): matches the
		 * `?add-to-cart=...` query parameter (parsed name match plus a raw
		 * query-string fallback, `&`/`;` separated, case-insensitive).
		 * Mirrors `Cache::is_woo_excluded()` and the pre-boot drop-in bake.
		 * Safe-mode gated by the caller: guest add-to-cart flows mutate the
		 * cart session and must bypass the static cache while safe mode is
		 * on. Fail-open: detection failure returns true (treated as dynamic).
		 *
		 * @since NEXT
		 * @param string $query_string Raw query string (without leading `?`).
		 * @return bool True when the query carries an add-to-cart action.
		 */
		public static function is_woo_add_to_cart_request( string $query_string = '' ): bool {
			try {
				if ( '' === trim( (string) $query_string ) ) {
					return false;
				}
				$parsed = array();
				try {
					parse_str( (string) $query_string, $parsed );
				} catch ( \Throwable $e ) {
					unset( $e );
					$parsed = array();
				}
				foreach ( $parsed as $name => $value ) {
					if ( 'add-to-cart' === strtolower( trim( (string) $name ) ) ) {
						return true;
					}
				}
				return (bool) preg_match( '/(?:^|[&;])add-to-cart(?:=|&|;|$)/i', (string) $query_string );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether an absolute URL targets a WooCommerce dynamic route.
		 *
		 * Single source for the serve path (Cache), the warm path (Cron) and
		 * ad-hoc callers: combines the unconditional Store-API / wc-ajax /
		 * faceted / uncacheable-query guards with the safe-mode-gated
		 * add-to-cart + dynamic-path checks so preload can never warm a URL
		 * the serve path blocks. Fail-open: detection failure returns true
		 * (treated as dynamic).
		 *
		 * @since 2.2.0
		 * @param string $url        Absolute URL.
		 * @param string $query      Optional pre-parsed query string (parsed from $url when '').
		 * @param string $rest_route Optional pre-parsed rest_route value.
		 * @return bool True when the URL must not be cached/preloaded.
		 */
		public static function is_woo_excluded_url( string $url, string $query = '', string $rest_route = '' ): bool {
			try {
				if ( ! is_string( $url ) || '' === trim( $url ) ) {
					return true;
				}
				$path = '';
				if ( '' === $query ) {
					if ( function_exists( 'wp_parse_url' ) ) {
						$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
						$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
					} else {
						$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
						if ( is_array( $parts ) ) {
							$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
							$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
						}
					}
				} else {
					$path = function_exists( 'wp_parse_url' ) ? (string) wp_parse_url( $url, PHP_URL_PATH ) : '';
				}
				if ( '' === $rest_route && '' !== $query ) {
					$parsed = array();
					parse_str( $query, $parsed );
					if ( isset( $parsed['rest_route'] ) && is_string( $parsed['rest_route'] ) ) {
						$rest_route = $parsed['rest_route'];
					}
				}
				// Unconditional: Store API JSON is never cacheable.
				if ( self::is_woo_store_api_request( $path, $query, $rest_route ) ) {
					return true;
				}
				// Unconditional: wc-ajax endpoints are dynamic JSON (explicit
				// audit of the generic query guard below so intent is
				// greppable and safe-mode independent, mirroring
				// Cache::is_wc_ajax_request() and the pre-boot drop-in).
				if ( method_exists( self::class, 'is_woo_ajax_request' ) && self::is_woo_ajax_request( $path, $query ) ) {
					return true;
				}
				// Gated: add-to-cart flows mutate the cart session (explicit
				// audit; safe-mode gated like Cache::is_woo_excluded() and
				// the drop-in bake). Falls through to the unconditional
				// generic query guard below so preload still skips the URL
				// even with safe mode off (query-poisoning safety).
				if ( '' !== $query && method_exists( self::class, 'is_woo_add_to_cart_request' ) && self::is_woo_add_to_cart_request( $query ) ) {
					if ( self::is_woo_safe_mode_enabled() ) {
						return true;
					}
				}
				// Unconditional: faceted layered-nav queries are dynamic.
				if ( '' !== $query && method_exists( self::class, 'is_woo_faceted_query' ) && self::is_woo_faceted_query( $query ) ) {
					return true;
				}
				// Unconditional: any other functional query is dynamic.
				if ( '' !== $query && method_exists( self::class, 'has_uncacheable_query' ) && self::has_uncacheable_query( $query ) ) {
					return true;
				}
				// Gated: cart/checkout/account + custom Woo slugs.
				if ( method_exists( self::class, 'is_woo_dynamic_path' ) ) {
					if ( ! self::is_woo_safe_mode_enabled() ) {
						return false;
					}
					return self::is_woo_dynamic_path( $path );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether an absolute URL targets an admin or preview context.
		 *
		 * Parses path + query (+ `rest_route` query value) and delegates to
		 * {@see is_editor_preview_path()}. Used by preload scheduling so editor
		 * previews and wp-admin URLs are never warmed. Fail-open: detection
		 * failure returns true (never preload).
		 *
		 * @since 2.2.0
		 * @param string $url Absolute URL.
		 * @return bool True when the URL must bypass cache/preload.
		 */
		public static function is_editor_preview_url( string $url ): bool {
			try {
				if ( ! is_string( $url ) || '' === trim( $url ) ) {
					return true;
				}
				$path  = '';
				$query = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
					$query = (string) wp_parse_url( $url, PHP_URL_QUERY );
				} else {
					$parts = parse_url( $url ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
					if ( is_array( $parts ) ) {
						$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
						$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
					}
				}
				$rest_route = '';
				if ( '' !== $query ) {
					$parsed = array();
					parse_str( $query, $parsed );
					if ( isset( $parsed['rest_route'] ) && is_string( $parsed['rest_route'] ) ) {
						$rest_route = $parsed['rest_route'];
					}
				}
				return self::is_editor_preview_path( $path, $query, $rest_route );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether the current request is an admin, AJAX/REST, or preview context.
		 *
		 * Unconditional static-cache bypass: wp-admin / login / admin-ajax paths,
		 * `is_admin()`, `is_preview()`, `is_customize_preview()`, Elementor
		 * preview mode (guarded by `class_exists`/`method_exists`), AJAX/REST/JSON
		 * requests, and builder/core preview query params. Any detection failure
		 * returns true (fail-open to uncached, never fatal). Multisite-safe:
		 * per-request state only.
		 *
		 * @since 2.2.0
		 * @return bool True when the current request must bypass the cache.
		 */
		public static function is_editor_preview_request(): bool {
			try {
				if ( function_exists( 'is_admin' ) ) {
					try {
						if ( is_admin() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'is_preview' ) ) {
					try {
						if ( is_preview() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'is_customize_preview' ) ) {
					try {
						if ( is_customize_preview() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( function_exists( 'wp_doing_ajax' ) ) {
					try {
						if ( wp_doing_ajax() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
					return true;
				}
				if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
					return true;
				}
				if ( function_exists( 'wp_is_json_request' ) ) {
					try {
						if ( wp_is_json_request() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				// Elementor edit/preview mode (guarded for non-Elementor installs).
				if ( class_exists( 'Elementor\Plugin', false ) ) {
					try {
						$elementor = \Elementor\Plugin::$instance ?? null;
						if ( isset( $elementor->preview ) && is_object( $elementor->preview ) && method_exists( $elementor->preview, 'is_preview_mode' ) && $elementor->preview->is_preview_mode() ) {
							return true;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return true;
					}
				}
				$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
				$path        = '';
				$query       = '';
				if ( function_exists( 'wp_parse_url' ) ) {
					$path  = (string) wp_parse_url( $request_uri, PHP_URL_PATH );
					$query = (string) wp_parse_url( $request_uri, PHP_URL_QUERY );
				} else {
					$parts = parse_url( $request_uri ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback when wp_parse_url() is unavailable.
					if ( is_array( $parts ) ) {
						$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '';
						$query = isset( $parts['query'] ) ? (string) $parts['query'] : '';
					}
				}
				if ( '' === $query && isset( $_SERVER['QUERY_STRING'] ) ) {
					$query = (string) wp_unslash( $_SERVER['QUERY_STRING'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; read-only routing check, no output.
				}
				$rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only routing check, no state change or output.
				if ( '' !== $path && self::is_admin_path( $path ) ) {
					return true;
				}
				return self::is_editor_preview_path( $path, $query, $rest_route );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Verifiable WooCommerce cart/checkout cache-exclusion self-test.
		 *
		 * Trust-but-verify proof that dynamic Woo routes can never be served
		 * as a static-cache HIT: for each canonical probe (`/cart/`,
		 * `/checkout/`, `/my-account/`), every resolved custom path from
		 * {@see get_woo_excluded_paths()} and one Store API probe
		 * (`/wp-json/wc/store/v1/cart`), asserts `is_cacheable=false` by
		 * mirroring `Cache::is_woo_excluded()` path/safe-mode semantics without
		 * instantiating Cache (`is_woo_store_api_path()` uncacheable
		 * unconditionally, otherwise `safe_mode && is_woo_dynamic_path()`).
		 * Fragment probes (`fragment_checks`) additionally prove the
		 * query-string dynamic set bypasses the cache: `?wc-ajax=` (pre-boot
		 * drop-in + storage refusal, unconditional on safe mode),
		 * `?add-to-cart=` (safe-mode gated, mirroring the drop-in bake and
		 * `Cache::is_woo_excluded()`), and the plain-permalink Store API
		 * form (`?rest_route=/wc/store/...`, unconditional via
		 * `is_woo_store_api_request()`).
		 * Scope note: this covers path/query/safe-mode semantics only and does not
		 * evaluate the `wppo_woo_cacheable` / `wppo_should_cache_request`
		 * overrides, which can re-allow caching of an excluded URL at runtime.
		 * Fragment probes model Woo-layer intent only: generic
		 * query-poisoning (`has_uncacheable_query()`) and storage guards may
		 * still bypass independently of the reported Woo signal.
		 * `donotcachepage_honored` is assumed (not probed): DONOTCACHEPAGE
		 * enforcement lives in `Cache::is_not_cacheable()` — this method never
		 * defines the constant, it only asserts the existing enforcement path.
		 * `preload_checks` (additive, issue #1256) prove faceted layered-nav
		 * URLs (`filter_*`, `min_price`/`max_price`, `orderby`, …) plus dynamic
		 * paths and Store API routes are skipped by preload scheduling
		 * (`Cron::is_woo_excluded_url()` semantics, faceted/Store API skips
		 * unconditional on safe mode). `cart_checks` (additive, issue #1256)
		 * prove guest-cart survival with page and object cache on: cart/session
		 * cookie bypass, `wc-ajax` (unconditional), `add-to-cart` (safe-mode
		 * gated) and plain-permalink Store API (unconditional) must all bypass
		 * so a guest add-to-cart is never served a stale cached fragment.
		 * `force_exclude` is true when `all_pass` is false (fail-closed for
		 * commerce, fail-open for cache): the operator should force-exclude
		 * dynamic routes plus cookie bypass (i.e. re-enable safe mode) and
		 * serve dynamic. Never a stale cart or white-screen.
		 *
		 * Fail-open: any per-URL detection failure yields
		 * `pass=false, cacheable=false, error` (treated non-cacheable, never
		 * fatal); a whole-method failure returns the `runnable=false` shape.
		 * Multisite-safe: per-site path detection via
		 * {@see get_woo_excluded_paths()}, blog-keyed settings, no
		 * cross-site leakage. Read-only: no options, transients, or files
		 * are written.
		 *
		 * @since 2.0.0
		 * @since 2.2.0 Added additive `editor_checks` (wp-admin + builder/core preview bypass probes).
		 * @since 2.2.0 Added additive `fragment_checks` (wc-ajax / add-to-cart / plain-permalink Store API fragment probes, issue #1197).
		 * @since 2.2.0 Added additive `preload_checks` (faceted-URL preload-skip probes), `cart_checks` (guest-cart survival probes) and `force_exclude` (fail-closed recommendation, issue #1256).
		 * @return array{woo_active: bool, safe_mode: bool, runnable: bool, excluded_paths: string[], donotcachepage_honored: bool, checks: array<int, array{url: string, path: string, is_dynamic: bool, cacheable: bool, donotcachepage_honored: bool, pass: bool, error?: string}>, fragment_checks: array<int, array{url: string, path: string, is_dynamic: bool, cacheable: bool, donotcachepage_honored: bool, pass: bool, error?: string}>, editor_checks: array<int, array{url: string, bypass: bool, cacheable: bool, donotcachepage_honored: bool, pass: bool, error?: string}>, preload_checks: array<int, array{url: string, path: string, skipped: bool, pass: bool, error?: string}>, cart_checks: array<int, array{key: string, url: string, bypass: bool, cacheable: bool, donotcachepage_honored: bool, pass: bool, error?: string}>, force_exclude: bool, all_pass: bool} Structured self-test result.
		 */
		public static function woo_cache_self_test(): array {
			try {
				$woo_active = self::is_woo_active();
				$safe_mode  = self::is_woo_safe_mode_enabled();
				$excluded   = self::get_woo_excluded_paths();

				$probe_paths = array( 'cart', 'checkout', 'my-account' );
				foreach ( $excluded as $extra ) {
					$candidate = strtolower( trim( (string) $extra, '/' ) );
					if ( '' !== $candidate && ! in_array( $candidate, $probe_paths, true ) ) {
						$probe_paths[] = $candidate;
					}
				}
				$probe_paths[] = 'wp-json/wc/store/v1/cart';

				$checks = array();
				foreach ( $probe_paths as $probe ) {
					$path = strtolower( trim( (string) $probe, '/' ) );
					if ( '' === $path ) {
						continue;
					}
					try {
						$is_store   = self::is_woo_store_api_path( $path );
						$is_dynamic = self::is_woo_dynamic_path( $path );
						// Mirror Cache::is_woo_excluded(): Store API is
						// uncacheable even when safe mode is off.
						$excluded_flag = $is_store || ( $safe_mode && $is_dynamic );
						$cacheable     = ! $excluded_flag;
						$pass          = $is_dynamic && ! $cacheable;
						try {
							$url = self::cached_home_url( '/' . $path . '/' );
						} catch ( \Throwable $e ) {
							unset( $e );
							$url = '/' . $path . '/';
						}
						if ( ! is_string( $url ) || '' === $url ) {
							$url = '/' . $path . '/';
						}
						$checks[] = array(
							'url'                    => $url,
							'path'                   => '/' . $path . '/',
							'is_dynamic'             => $is_dynamic,
							'cacheable'              => $cacheable,
							'donotcachepage_honored' => true,
							'pass'                   => $pass,
						);
					} catch ( \Throwable $e ) {
						$checks[] = array(
							'url'                    => '/' . $path . '/',
							'path'                   => '/' . $path . '/',
							'is_dynamic'             => true,
							'cacheable'              => false,
							'donotcachepage_honored' => true,
							'pass'                   => false,
							'error'                  => get_class( $e ),
						);
					}
				}

				// Fragment probes (additive, issue #1197): the query-string
				// dynamic set must bypass the cache. wc-ajax is refused by
				// the pre-boot drop-in and the storage layer unconditionally
				// (pre-#922 guards survive safe-mode-off); add-to-cart is
				// safe-mode gated (drop-in bake + Cache::is_woo_excluded());
				// the plain-permalink Store API form is unconditional via
				// is_woo_store_api_request(). Canonical string helpers
				// (is_woo_ajax_request / is_woo_add_to_cart_request) prove
				// the same predicates the serve path enforces, so the
				// self-test stays read-only and cannot drift.
				$fragment_probes = array(
					'/?wc-ajax=get_refreshed_fragments' => 'wc-ajax',
					'/?add-to-cart=123'                 => 'add-to-cart',
					'/?rest_route=/wc/store/v1/cart'    => 'store-api',
				);
				$fragment_checks = array();
				foreach ( $fragment_probes as $probe_url => $kind ) {
					try {
						$is_dynamic  = true;
						$uncacheable = true;
						if ( 'store-api' === $kind ) {
							$is_dynamic  = self::is_woo_store_api_request( '/', 'rest_route=/wc/store/v1/cart', '/wc/store/v1/cart' );
							$uncacheable = $is_dynamic;
						} elseif ( 'wc-ajax' === $kind ) {
							$is_dynamic  = method_exists( self::class, 'is_woo_ajax_request' ) ? self::is_woo_ajax_request( '/', 'wc-ajax=get_refreshed_fragments' ) : true;
							$uncacheable = $is_dynamic;
						} elseif ( 'add-to-cart' === $kind ) {
							$is_dynamic = method_exists( self::class, 'is_woo_add_to_cart_request' ) ? self::is_woo_add_to_cart_request( 'add-to-cart=123' ) : true;
							// Woo-layer intent: safe-mode gated (serve-time
							// poisoning guard still bypasses independently).
							$uncacheable = $is_dynamic && $safe_mode;
						}
						$cacheable = ! $uncacheable;
						$pass      = $is_dynamic && ! $cacheable;
						try {
							$url = self::cached_home_url( (string) $probe_url );
						} catch ( \Throwable $e ) {
							unset( $e );
							$url = (string) $probe_url;
						}
						if ( ! is_string( $url ) || '' === $url ) {
							$url = (string) $probe_url;
						}
						$fragment_checks[] = array(
							'url'                    => $url,
							'path'                   => (string) $probe_url,
							'is_dynamic'             => $is_dynamic,
							'cacheable'              => $cacheable,
							'donotcachepage_honored' => true,
							'pass'                   => $pass,
						);
					} catch ( \Throwable $e ) {
						$fragment_checks[] = array(
							'url'                    => (string) $probe_url,
							'path'                   => (string) $probe_url,
							'is_dynamic'             => true,
							'cacheable'              => false,
							'donotcachepage_honored' => true,
							'pass'                   => false,
							'error'                  => get_class( $e ),
						);
					}
				}

				// Editor/admin bypass probes (additive, issue #1097): wp-admin and
				// builder/core preview URLs must never be cacheable. Pure path
				// checks via is_editor_preview_url() so the self-test stays
				// read-only and independent of conditional tags.
				$editor_probes = array(
					'/wp-admin/post.php?post=1&action=edit' => true,
					'/?elementor-preview=1' => true,
					'/?preview=true'        => true,
					'/?et_fb=1'             => true,
					'/?bricks=run'          => true,
				);
				$editor_checks = array();
				foreach ( $editor_probes as $probe_url => $expected_bypass ) {
					try {
						try {
							$probe_full = self::cached_home_url( (string) $probe_url );
						} catch ( \Throwable $e ) {
							unset( $e );
							$probe_full = (string) $probe_url;
						}
						if ( ! is_string( $probe_full ) || '' === $probe_full ) {
							$probe_full = (string) $probe_url;
						}
						$bypass          = self::is_editor_preview_url( $probe_full );
						$pass            = ( $bypass === $expected_bypass );
						$editor_checks[] = array(
							'url'                    => $probe_full,
							'bypass'                 => $bypass,
							'cacheable'              => ! $bypass,
							'donotcachepage_honored' => true,
							'pass'                   => $pass,
						);
					} catch ( \Throwable $e ) {
						$editor_checks[] = array(
							'url'                    => (string) $probe_url,
							'bypass'                 => true,
							'cacheable'              => false,
							'donotcachepage_honored' => true,
							'pass'                   => false,
							'error'                  => get_class( $e ),
						);
					}
				}

				// Preload-skip probes (additive, issue #1256): faceted
				// layered-nav URLs plus dynamic paths and Store API routes must
				// never enter the preload queue. Proved via the canonical
				// is_woo_excluded_url() (faceted/Store API/wc-ajax skips
				// unconditional on safe mode, dynamic paths safe-mode gated)
				// so serve-path and warm-path verdicts cannot drift. The
				// manual chain below is the mixed-version fallback only.
				$preload_probes = array(
					'/shop/?filter_color=blue'         => true,
					'/shop/?min_price=10&max_price=50' => true,
					'/shop/?orderby=price'             => true,
					'/shop/?rating_filter=5'           => true,
					'/cart/'                           => true,
					'/wp-json/wc/store/v1/cart'        => true,
					'/?add-to-cart=123'                => true,
				);
				$preload_checks = array();
				foreach ( $preload_probes as $probe_url => $expected_skip ) {
					try {
						$probe_full_probe = $probe_url;
						try {
							$candidate = self::cached_home_url( (string) $probe_url );
							if ( is_string( $candidate ) && '' !== $candidate ) {
								$probe_full_probe = $candidate;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
						}
						$skipped        = false;
						$used_canonical = false;
						try {
							if ( method_exists( self::class, 'is_woo_excluded_url' ) ) {
								$skipped        = self::is_woo_excluded_url( (string) $probe_full_probe );
								$used_canonical = true;
							}
						} catch ( \Throwable $e ) {
							unset( $e );
							$used_canonical = false;
						}
						if ( ! $used_canonical ) {
							$probe_path  = (string) wp_parse_url( (string) $probe_url, PHP_URL_PATH );
							$probe_query = (string) wp_parse_url( (string) $probe_url, PHP_URL_QUERY );
							if ( '' === $probe_path ) {
								$probe_path = '/';
							}
							$skipped = false;
							if ( self::is_woo_store_api_request( $probe_path, $probe_query, '' ) ) {
								$skipped = true;
							} elseif ( method_exists( self::class, 'is_woo_ajax_request' ) && self::is_woo_ajax_request( $probe_path, $probe_query ) ) {
								$skipped = true;
							} elseif ( $safe_mode && '' !== $probe_query && method_exists( self::class, 'is_woo_add_to_cart_request' ) && self::is_woo_add_to_cart_request( $probe_query ) ) {
								// Safe-mode gated for parity with the canonical
								// is_woo_excluded_url(): safe-mode-off still
								// skips via the unconditional generic guard below.
								$skipped = true;
							} elseif ( '' !== $probe_query && self::is_woo_faceted_query( $probe_query ) ) {
								$skipped = true;
							} elseif ( '' !== $probe_query && self::has_uncacheable_query( $probe_query ) ) {
								$skipped = true;
							} elseif ( $safe_mode && self::is_woo_dynamic_path( $probe_path ) ) {
								$skipped = true;
							} elseif ( self::is_woo_store_api_path( $probe_path ) ) {
								$skipped = true;
							}
						}
						$pass = ( $skipped === $expected_skip );
						try {
							$probe_full = self::cached_home_url( (string) $probe_url );
						} catch ( \Throwable $e ) {
							unset( $e );
							$probe_full = (string) $probe_url;
						}
						if ( ! is_string( $probe_full ) || '' === $probe_full ) {
							$probe_full = (string) $probe_url;
						}
						$preload_checks[] = array(
							'url'     => $probe_full,
							'path'    => (string) $probe_url,
							'skipped' => $skipped,
							'pass'    => $pass,
						);
					} catch ( \Throwable $e ) {
						$preload_checks[] = array(
							'url'     => (string) $probe_url,
							'path'    => (string) $probe_url,
							'skipped' => true,
							'pass'    => false,
							'error'   => get_class( $e ),
						);
					}
				}

				// Guest-cart survival probes (additive, issue #1256): with page
				// and object cache on, a guest add-to-cart must survive — cart /
				// session cookie bypass, wc-ajax (unconditional), add-to-cart
				// (safe-mode gated) and plain-permalink Store API (unconditional)
				// must all bypass so no stale cached cart fragment is served.
				// Pure signal checks mirroring Cache::is_woo_excluded().
				$cart_probes = array(
					'cart_cookie'    => 'cookie',
					'session_cookie' => 'cookie',
					'wc_ajax'        => 'wc-ajax',
					'add_to_cart'    => 'add-to-cart',
					'store_api'      => 'store-api',
				);
				$cart_checks = array();
				foreach ( $cart_probes as $cart_key => $kind ) {
					try {
						$bypass = true;
						if ( 'add_to_cart' === $kind || 'session_cookie' === $kind || 'cart_cookie' === $kind ) {
							$bypass = $safe_mode;
						}
						// Pass only when the signal bypasses: safe mode off
						// leaves cart cookies / add-to-cart cacheable, so the
						// survival proof fails and force_exclude trips.
						$pass = $bypass;
						try {
							$cart_url = self::cached_home_url( '/' );
						} catch ( \Throwable $e ) {
							unset( $e );
							$cart_url = '/';
						}
						if ( ! is_string( $cart_url ) || '' === $cart_url ) {
							$cart_url = '/';
						}
						$cart_checks[] = array(
							'key'                    => (string) $cart_key,
							'url'                    => $cart_url,
							'bypass'                 => $bypass,
							'cacheable'              => ! $bypass,
							'donotcachepage_honored' => true,
							'pass'                   => $pass,
						);
					} catch ( \Throwable $e ) {
						$cart_checks[] = array(
							'key'                    => (string) $cart_key,
							'url'                    => '/',
							'bypass'                 => true,
							'cacheable'              => false,
							'donotcachepage_honored' => true,
							'pass'                   => false,
							'error'                  => get_class( $e ),
						);
					}
				}

				$all_pass = ! empty( $checks ) && ! empty( $fragment_checks ) && ! empty( $editor_checks ) && ! empty( $preload_checks ) && ! empty( $cart_checks );
				foreach ( $checks as $check ) {
					if ( empty( $check['pass'] ) ) {
						$all_pass = false;
						break;
					}
				}
				if ( $all_pass ) {
					foreach ( $fragment_checks as $check ) {
						if ( empty( $check['pass'] ) ) {
							$all_pass = false;
							break;
						}
					}
				}
				if ( $all_pass ) {
					foreach ( $editor_checks as $check ) {
						if ( empty( $check['pass'] ) ) {
							$all_pass = false;
							break;
						}
					}
				}
				if ( $all_pass ) {
					foreach ( $preload_checks as $check ) {
						if ( empty( $check['pass'] ) ) {
							$all_pass = false;
							break;
						}
					}
				}
				if ( $all_pass ) {
					foreach ( $cart_checks as $check ) {
						if ( empty( $check['pass'] ) ) {
							$all_pass = false;
							break;
						}
					}
				}

				return array(
					'woo_active'             => $woo_active,
					'safe_mode'              => $safe_mode,
					'runnable'               => true,
					'excluded_paths'         => array_values( $excluded ),
					'donotcachepage_honored' => true,
					'checks'                 => $checks,
					'fragment_checks'        => $fragment_checks,
					'editor_checks'          => $editor_checks,
					'preload_checks'         => $preload_checks,
					'cart_checks'            => $cart_checks,
					'force_exclude'          => ! $all_pass,
					'all_pass'               => $all_pass,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'woo_active'             => false,
					'safe_mode'              => true,
					'runnable'               => false,
					'excluded_paths'         => array( 'cart', 'checkout', 'my-account' ),
					'donotcachepage_honored' => true,
					'checks'                 => array(),
					'fragment_checks'        => array(),
					'editor_checks'          => array(),
					'preload_checks'         => array(),
					'cart_checks'            => array(),
					'force_exclude'          => true,
					'all_pass'               => false,
				);
			}
		}

		/**
		 * Static cache for resolved home URLs, keyed by blog ID.
		 *
		 * @var array<int, string>
		 * @since 2.0.0
		 */
		private static array $home_url_cache = array();

		/**
		 * Per-blog memo for the canonical home host (see get_canonical_host()).
		 *
		 * @var array<int, string>
		 * @since 2.2.0
		 */
		private static array $canonical_host_cache = array();

		/**
		 * Per-value memo for normalized cache hosts (see normalize_cache_host()).
		 *
		 * @var array<string, string>
		 * @since 2.2.0
		 */
		private static array $normalized_host_cache = array();

		/**
		 * Per-request memo for wppo_settings to avoid repeated get_option deserialization.
		 *
		 * Keyed by blog ID for multisite correctness under switch_to_blog().
		 *
		 * @var array<int, array>
		 * @since 2.0.0
		 */
		private static array $settings_cache = array();

		/**
		 * Whether the settings cache has been populated this request, keyed by blog ID.
		 *
		 * @var array<int, bool>
		 * @since 2.0.0
		 */
		private static array $settings_cache_loaded = array();

		/**
		 * Per-request memo for the purge-fallback gate (issue #1275).
		 *
		 * The gate result is request-stable (settings + constant + filter);
		 * memoizing avoids re-running get_settings + apply_filters on every
		 * retain/serve call (3x+ per single-page clear). Cleared alongside
		 * the settings cache so tests and option updates stay coherent.
		 * Keyed by blog ID so switch_to_blog() cannot leak one site's
		 * decision into another (multisite-safe).
		 *
		 * @var array<int, bool>
		 * @since 2.2.0
		 */
		private static array $purge_fallback_memo = array();

		/**
		 * Resets the home_url static cache for testing isolation.
		 *
		 * Also clears the canonical-host, normalized-host, and same-site
		 * home-host memos, which are host-related state derived from the
		 * same stubs.
		 *
		 * @since 2.0.0
		 */
		public static function reset_cached_home_urls(): void {
			self::$home_url_cache        = array();
			self::$canonical_host_cache  = array();
			self::$normalized_host_cache = array();
			self::$same_site_home_host   = array();
		}

		/**
		 * Resets all Util runtime memos (test-isolation entry point).
		 *
		 * Covers settings, home, canonical-host, normalized-host,
		 * permalink, and Action Scheduler unique-probe memos. Prefer this
		 * over calling the individual resetters so future memos are not
		 * silently missed by test setUp() methods.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_runtime_caches(): void {
			self::reset_cached_home_urls();
			self::clear_settings_cache();
			self::clear_permalink_cache();
			self::reset_action_scheduler_unique_cache();
		}

		/**
		 * Per-request permalink memo keyed by blog ID + post ID (audit #874
		 * finding 1).
		 *
		 * Batch loops (Cron preload discovery, Crawler URL discovery) and
		 * repeated calls for the same IDs (sitemap + post merges) would each
		 * trigger a get_permalink() DB/cache round-trip; this collapses them
		 * to one lookup per ID per request. Results are non-false strings —
		 * failed lookups memoize as ''.
		 *
		 * @since 2.0.0
		 * @var array<string, string>
		 */
		private static array $permalink_cache = array();

		/**
		 * Get a permalink through the per-request memo.
		 *
		 * The memo is keyed by blog ID + post ID so a `switch_to_blog()` mid-
		 * request (cron/crawler loops) can never serve another site's
		 * permalink for the same numeric ID (audit #874 Part review).
		 *
		 * @since 2.0.0
		 * @param int $post_id Post ID.
		 * @return string Permalink, or '' when unavailable (false from get_permalink()).
		 */
		public static function memoized_permalink( int $post_id ): string {
			$key = self::current_blog_id() . ':' . $post_id;
			if ( ! array_key_exists( $key, self::$permalink_cache ) ) {
				$permalink                     = get_permalink( $post_id );
				self::$permalink_cache[ $key ] = is_string( $permalink ) ? $permalink : '';
			}
			return self::$permalink_cache[ $key ];
		}

		/**
		 * Clear the per-request permalink memo (testing isolation, switch_blog).
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function clear_permalink_cache(): void {
			self::$permalink_cache = array();
		}

		/**
		 * Resolve current blog ID safely (handles Brain Monkey stub mis-configuration in tests).
		 *
		 * @since 2.0.0
		 * @return int Blog ID.
		 */
		private static function current_blog_id(): int {
			if ( ! function_exists( 'get_current_blog_id' ) ) {
				return 0;
			}
			try {
				return (int) get_current_blog_id();
			} catch ( \Throwable $e ) {
				return 0;
			}
		}

		/**
		 * Get wppo_settings with per-request memoization.
		 *
		 * Wraps get_option('wppo_settings') with a static cache so up to 6
		 * deserializations per frontend render (Main, Cache, Cron, Used_CSS, etc.)
		 * collapse to a single DB-backed fetch per request. Invalidated automatically
		 * on update/add/delete of the option. Blog-keyed to avoid cross-site
		 * leakage under switch_to_blog() (see F-COMPAT-03).
		 *
		 * @since 2.0.0
		 * @return array The plugin settings.
		 */
		public static function get_settings(): array {
			$bid = self::current_blog_id();
			if ( ! empty( self::$settings_cache_loaded[ $bid ] ) ) {
				return self::$settings_cache[ $bid ] ?? array();
			}
			self::ensure_settings_cache_hook();
			$raw = get_option( 'wppo_settings', array() );
			if ( ! is_array( $raw ) ) {
				$raw = array();
			}
			self::$settings_cache[ $bid ]        = $raw;
			self::$settings_cache_loaded[ $bid ] = true;
			return $raw;
		}

		/**
		 * Set the settings cache to a known value (e.g. after update_option in same request).
		 *
		 * @since 2.0.0
		 * @param array $settings The settings to cache.
		 * @return void
		 */
		public static function set_settings_cache( array $settings ): void {
			$bid                                 = self::current_blog_id();
			self::$settings_cache[ $bid ]        = $settings;
			self::$settings_cache_loaded[ $bid ] = true;
			self::ensure_settings_cache_hook();
		}

		/**
		 * Persist wppo_settings with autoload disabled (audit #1325).
		 *
		 * Single owner for settings writes: the multi-tab array must never
		 * sit in alloptions. Refreshes the per-request memo on success so
		 * same-request reads observe the write.
		 *
		 * @since 2.2.0
		 * @param array $settings Settings array to store.
		 * @return bool True on success (mirrors update_option()).
		 */
		public static function save_settings( array $settings ): bool {
			try {
				$updated = update_option( 'wppo_settings', $settings, false );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			if ( $updated ) {
				try {
					self::set_settings_cache( $settings );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			return (bool) $updated;
		}

		/**
		 * Clear the settings memo (e.g. in tests or on delete).
		 *
		 * When called without args clears all blog entries (test isolation).
		 * When called with a blog ID clears that blog only. The WP
		 * delete_option_wppo_settings action passes no blog ID, so the full
		 * clear path is taken. switch_blog is handled by on_switch_blog().
		 *
		 * @since 2.0.0
		 * @param int|null $blog_id Optional blog ID to clear. Null clears all.
		 * @return void
		 */
		public static function clear_settings_cache( $blog_id = null ): void {
			if ( null !== $blog_id && is_int( $blog_id ) ) {
				unset( self::$purge_fallback_memo[ (int) $blog_id ] );
			} else {
				self::$purge_fallback_memo = array();
			}
			if ( null !== $blog_id && is_int( $blog_id ) ) {
				$bid = (int) $blog_id;
				unset( self::$settings_cache[ $bid ], self::$settings_cache_loaded[ $bid ] );
				return;
			}
			// Action callbacks (update/delete) pass $old/$new or $option/$value
			// which are not int blog IDs; treat non-int as "clear all" for
			// backwards-compat with the pre-blog-keyed API.
			if ( null !== $blog_id && ! is_int( $blog_id ) ) {
				self::$settings_cache        = array();
				self::$settings_cache_loaded = array();
				return;
			}
			self::$settings_cache        = array();
			self::$settings_cache_loaded = array();
		}

		/**
		 * Handler for switch_blog — clears stale memo association.
		 *
		 * Kept separate from clear_settings_cache for hook arity clarity.
		 *
		 * @since 2.0.0
		 * @param int $new_blog_id New blog ID.
		 * @param int $prev_blog_id Previous blog ID.
		 * @return void
		 */
		public static function on_switch_blog( $new_blog_id, $prev_blog_id ): void {
			// Settings and home URLs are blog-keyed and need no destructive
			// clear; the permalink memo additionally keys by blog ID, but it is
			// dropped here too so any memo written before the switch (e.g. a
			// cached ID on the previous site) cannot leak.
			// The hook params are unused (keys already isolate) — consumed
			// explicitly to satisfy the unused-parameter sniff.
			unset( $new_blog_id, $prev_blog_id );
			self::clear_permalink_cache();
		}

		/**
		 * Register the settings-cache invalidation hooks eagerly.
		 *
		 * The invalidation hooks only need to exist before the first
		 * update/add/delete of `wppo_settings` in the request, and before any
		 * switch_to_blog() re-keying. Registering them at plugin boot (in
		 * {@see Main::__construct()}) makes invalidation deterministic instead
		 * of relying on the lazy first-call registration inside get_settings();
		 * the lazy path remains as a backstop for entry points that bypass Main.
		 * Safe to call multiple times — a static guard makes the registration
		 * idempotent.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function register_settings_cache_hooks(): void {
			self::ensure_settings_cache_hook();
		}

		/**
		 * Ensure the invalidation hooks for wppo_settings are registered once per request.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		private static function ensure_settings_cache_hook(): void {
			static $hooked = false;
			if ( $hooked ) {
				return;
			}
			$hooked = true;
			add_action( 'update_option_wppo_settings', array( self::class, 'on_settings_update' ), 10, 2 );
			add_action( 'add_option_wppo_settings', array( self::class, 'on_settings_add' ), 10, 2 );
			add_action( 'delete_option_wppo_settings', array( self::class, 'clear_settings_cache' ) );
			add_action( 'switch_blog', array( self::class, 'on_switch_blog' ), 10, 2 );
		}

		/**
		 * Invalidate/update the memo when wppo_settings is updated.
		 *
		 * @since 2.0.0
		 * @param mixed $old_value Previous value.
		 * @param mixed $value New value.
		 * @return void
		 */
		public static function on_settings_update( $old_value, $value ): void {
			$bid                                 = self::current_blog_id();
			self::$settings_cache[ $bid ]        = is_array( $value ) ? $value : array();
			self::$settings_cache_loaded[ $bid ] = true;
		}

		/**
		 * Populate the memo when wppo_settings is added.
		 *
		 * @since 2.0.0
		 * @param string $option Option name.
		 * @param mixed  $value Option value.
		 * @return void
		 */
		public static function on_settings_add( $option, $value ): void {
			if ( 'wppo_settings' === $option ) {
				$bid                                 = self::current_blog_id();
				self::$settings_cache[ $bid ]        = is_array( $value ) ? $value : array();
				self::$settings_cache_loaded[ $bid ] = true;
			}
		}

		/**
		 * Recursively creates cache directory if not exists.
		 *
		 * @param string $cache_dir Path to the cache directory.
		 * @return bool True if created or exists, false otherwise.
		 * @since 1.0.0
		 */
		public static function prepare_cache_dir( $cache_dir ): bool {
			$fs = self::init_filesystem();

			if ( ! $fs ) {
				return false;
			}

			if ( ! is_string( $cache_dir ) || '' === $cache_dir ) {
				return false;
			}

			$path = wp_normalize_path( $cache_dir );
			// Fail closed on traversal: reject .. segments lexically.
			$segments = explode( '/', trim( $path, '/' ) );
			foreach ( $segments as $segment ) {
				if ( '..' === $segment ) {
					return false;
				}
			}
			// Containment: must live under WP_CONTENT_DIR so a caller
			// forwarding user-influenced input cannot create directories
			// outside the content tree (uploads/cache/fonts subtrees).
			$content = wp_normalize_path( (string) WP_CONTENT_DIR );
			if ( $path !== $content && 0 !== strpos( $path, trailingslashit( $content ) ) ) {
				return false;
			}

			if ( $fs->is_dir( $cache_dir ) ) {
				return true;
			}

			// Build parent directories iteratively to avoid deep recursion.
			$parts = explode( '/', trim( $path, '/' ) );
			$build = '';

			foreach ( $parts as $part ) {
				$build = $build ? $build . '/' . $part : '/' . $part;
				if ( ! $fs->is_dir( $build ) ) {
					if ( ! $fs->mkdir( $build, FS_CHMOD_DIR ) ) {
						return false;
					}
				}
			}

			return true;
		}

		/**
		 * Initializes the WP_Filesystem API.
		 *
		 * @return mixed WP_Filesystem_Base|false The filesystem object or false on failure.
		 * @since 1.0.0
		 */
		public static function init_filesystem() {
			global $wp_filesystem;

			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
			}

			if ( WP_Filesystem() ) {
				return $wp_filesystem;
			} else {
				return false;
			}
		}

		/**
		 * Gets the local file path from a URL.
		 *
		 * @param string $url The URL to process.
		 * @return string The local file path.
		 * @since 1.0.0
		 */
		public static function get_local_path( string $url ): string {
			// Reject NUL bytes and stream wrappers before URL parsing so
			// payloads such as "php://filter/..." or "file:///etc/passwd"
			// can never map onto the local tree.
			if ( false !== strpos( $url, "\0" ) ) {
				return '';
			}
			$trimmed_url = ltrim( $url );
			if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#i', $trimmed_url ) ) {
				// Absolute http(s) URLs are legitimate asset sources; any
				// other scheme (php://, file://, expect://, phar://, ...) is
				// a wrapper probe and is refused outright.
				if ( 0 !== stripos( $trimmed_url, 'http://' ) && 0 !== stripos( $trimmed_url, 'https://' ) ) {
					return '';
				}
			} elseif ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#i', $trimmed_url ) && ! (bool) preg_match( '#^[a-zA-Z]:[\\\\/]#', $trimmed_url ) ) {
				// Scheme without "//" (e.g. "data:text/html,...") is never a
				// local asset reference. The drive-letter carve-out keeps
				// Windows paths ("C:\...") from false-positive matching.
				return '';
			}

			// Parse the URL to get the path.
			$parsed_url = wp_parse_url( $url );
			if ( false === $parsed_url ) {
				return '';
			}

			// Get the path from the parsed URL.
			$relative_path = wp_normalize_path( $parsed_url['path'] ?? '' );

			if ( strpos( $relative_path, '..' ) !== false ) {
				return '';
			}

			// Single-decode and re-check so an encoded traversal payload
			// (e.g. "%2e%2e/%2e%2e/etc/passwd") cannot smuggle past the
			// literal ".." check above. Double-encoding stays literal and
			// therefore harmless (it never resolves to ".." on disk).
			$decoded_path = rawurldecode( $relative_path );
			if ( false !== strpos( $decoded_path, "\0" ) || false !== strpos( $decoded_path, '..' ) ) {
				return '';
			}

			// If home_url has a subdirectory path, remove it only from the start.
			$home_path = wp_normalize_path( wp_parse_url( self::cached_home_url(), PHP_URL_PATH ) ?? '' );

			if ( $home_path && '/' !== $home_path ) {
				if ( 0 === strpos( $relative_path, $home_path ) ) {
					$relative_path = substr( $relative_path, strlen( $home_path ) );
				}
			}

			// Build the full local path and verify it stays within ABSPATH.
			$normalized_abspath = wp_normalize_path( ABSPATH );
			$full_path          = wp_normalize_path( ABSPATH . ltrim( $relative_path, '/' ) );

			// Enforce ABSPATH prefix bounds. normalized_abspath ends with a '/', so a
			// 0-offset prefix match guarantees the path is a descendant of ABSPATH
			// (a sibling directory such as "/path/abspath2" cannot prefix-match).
			if ( 0 !== strpos( $full_path, $normalized_abspath ) ) {
				return '';
			}

			return $full_path;
		}

		/**
		 * Gets the allow-listed filesystem roots for minify/combine file serving.
		 *
		 * Defaults to ABSPATH, WP_CONTENT_DIR, and the current site's uploads
		 * basedir (multisite-safe: wp_upload_dir() resolves per blog). Passes
		 * the defaults through the `wppo_minify_allowed_roots` filter when a
		 * listener is registered; invalid or empty filtered values fall back
		 * to the defaults so a poisoned filter can never open the tree.
		 *
		 * @return string[] Normalized absolute root paths.
		 * @since 2.2.0
		 */
		public static function get_minify_allowed_roots(): array {
			$defaults = array();

			if ( defined( 'ABSPATH' ) && is_string( ABSPATH ) && '' !== ABSPATH ) {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$defaults[] = wp_normalize_path( ABSPATH );
				} else {
					$defaults[] = str_replace( '\\', '/', (string) ABSPATH );
				}
			}

			if ( defined( 'WP_CONTENT_DIR' ) && is_string( WP_CONTENT_DIR ) && '' !== WP_CONTENT_DIR ) {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$defaults[] = wp_normalize_path( WP_CONTENT_DIR );
				} else {
					$defaults[] = str_replace( '\\', '/', (string) WP_CONTENT_DIR );
				}
			}

			if ( function_exists( 'wp_upload_dir' ) ) {
				try {
					$upload_dir = wp_upload_dir();
					if ( is_array( $upload_dir ) && isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] ) && '' !== $upload_dir['basedir'] ) {
						if ( function_exists( 'wp_normalize_path' ) ) {
							$defaults[] = wp_normalize_path( $upload_dir['basedir'] );
						} else {
							$defaults[] = str_replace( '\\', '/', $upload_dir['basedir'] );
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$defaults = array_values( array_unique( array_filter( $defaults ) ) );

			if ( function_exists( 'has_filter' ) && has_filter( 'wppo_minify_allowed_roots' ) ) {
				$filtered = apply_filters( 'wppo_minify_allowed_roots', $defaults );
				if ( is_array( $filtered ) && ! empty( $filtered ) ) {
					$sanitized = array();
					foreach ( $filtered as $root ) {
						if ( ! is_string( $root ) || '' === trim( $root ) ) {
							continue;
						}
						if ( function_exists( 'wp_normalize_path' ) ) {
							$sanitized[] = wp_normalize_path( $root );
						} else {
							$sanitized[] = str_replace( '\\', '/', $root );
						}
					}
					$sanitized = array_values( array_unique( array_filter( $sanitized ) ) );
					if ( ! empty( $sanitized ) ) {
						return $sanitized;
					}
				}
			}

			return $defaults;
		}

		/**
		 * Whether a minify/combine source path is allowed to be read.
		 *
		 * Single auditable gate: rejects non-string/empty input, NUL bytes,
		 * literal "..", stream wrappers (php://, file://, expect://,
		 * phar://, data:, and any other "scheme:" prefix), and ".php"
		 * targets; resolves symlinks via realpath() (guarded) and requires
		 * the resolved path to sit inside one of
		 * {@see Util::get_minify_allowed_roots()} with a trailing-slash
		 * boundary so sibling-prefix directories cannot match.
		 *
		 * Never emits file bytes and never fatals — callers fail open to
		 * uncombined/unoptimised output.
		 *
		 * @param mixed $path Candidate filesystem path.
		 * @return bool True when the path resolves inside an allowed root.
		 * @since 2.2.0
		 */
		public static function is_minify_path_allowed( $path ): bool {
			return '' !== self::validate_minify_path( $path );
		}

		/**
		 * Validates a minify/combine source path and returns its resolved form.
		 *
		 * Same gate as {@see Util::is_minify_path_allowed()} but returns the
		 * realpath-resolved, normalized path on success or '' on failure.
		 *
		 * @param mixed $path Candidate filesystem path.
		 * @return string Resolved allowed path, or '' when rejected.
		 * @since 2.2.0
		 */
		public static function validate_minify_path( $path ): string {
			if ( ! is_string( $path ) || '' === $path ) {
				return '';
			}

			if ( false !== strpos( $path, "\0" ) ) {
				return '';
			}

			if ( false !== strpos( $path, '..' ) ) {
				return '';
			}

			$trimmed = ltrim( $path );
			if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#i', $trimmed ) ) {
				return '';
			}
			if ( 0 === stripos( $trimmed, 'data:' ) || 0 === stripos( $trimmed, 'phar:' ) ) {
				return '';
			}
			if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*:#i', $trimmed ) && ! (bool) preg_match( '#^[a-zA-Z]:[\\\\/]#', $trimmed ) ) {
				return '';
			}

			if ( 'php' === strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
				return '';
			}

			if ( function_exists( 'realpath' ) ) {
				$resolved = realpath( $path );
				if ( false === $resolved ) {
					return '';
				}
			} else {
				$resolved = $path;
			}

			if ( function_exists( 'wp_normalize_path' ) ) {
				$normalized = wp_normalize_path( $resolved );
			} else {
				$normalized = str_replace( '\\', '/', (string) $resolved );
			}

			if ( false !== strpos( $normalized, "\0" ) ) {
				return '';
			}

			foreach ( self::get_minify_allowed_roots() as $root ) {
				if ( ! is_string( $root ) || '' === $root ) {
					continue;
				}
				if ( $normalized === $root || 0 === strpos( $normalized, rtrim( $root, '/' ) . '/' ) ) {
					return (string) $resolved;
				}
			}

			return '';
		}

		/**
		 * Gets the number of minified JS and CSS files.
		 *
		 * @return array Associative array with counts for JS and CSS files.
		 * @since 1.0.0
		 */
		public static function get_js_css_minified_file() {
			$filesystem = self::init_filesystem();
			if ( ! $filesystem ) {
				return array(
					'js'  => 0,
					'css' => 0,
				);
			}
			$minify_dir = self::min_cache_dir();

			$total_js  = 0;
			$total_css = 0;

			$js_files = $filesystem->dirlist( $minify_dir . '/js' );

			if ( ! empty( $js_files ) ) {
				foreach ( $js_files as $js_file ) {
					if ( isset( $js_file['name'] ) && 'js' === pathinfo( $js_file['name'], PATHINFO_EXTENSION ) ) {
						++$total_js;
					}
				}
			}

			$css_files = $filesystem->dirlist( $minify_dir . '/css' );

			if ( ! empty( $css_files ) ) {
				foreach ( $css_files as $css_file ) {
					if ( isset( $css_file['name'] ) && 'css' === pathinfo( $css_file['name'], PATHINFO_EXTENSION ) ) {
						++$total_css;
					}
				}
			}

			return array(
				'js'  => $total_js,
				'css' => $total_css,
			);
		}

		/**
		 * Gets MIME type based on image URL extension.
		 *
		 * @param string $url The image URL.
		 * @return string The MIME type.
		 * @since 1.0.0
		 */
		public static function get_image_mime_type( $url ) {
			// Infer MIME type from URL extension.
			// Audit #1434: cast — unparseable URLs yield null/false, deprecated for pathinfo() on PHP 8.2+.
			$extension = strtolower( pathinfo( (string) wp_parse_url( (string) $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );

			switch ( $extension ) {
				case 'jpg':
				case 'jpeg':
					return 'image/jpeg';
				case 'png':
					return 'image/png';
				case 'webp':
					return 'image/webp';
				case 'gif':
					return 'image/gif';
				case 'svg':
					return 'image/svg+xml';
				case 'avif':
					return 'image/avif';
				case 'heic':
					return 'image/heic';
				case 'heif':
					return 'image/heif';
				case 'heics':
				case 'heic-sequence':
					return 'image/heic-sequence';
				case 'heifs':
				case 'heif-sequence':
					return 'image/heif-sequence';
				default:
					return '';
			}
		}

		/**
		 * Generates a preload link tag for resources.
		 *
		 * Echoes only in front-end HTML contexts (wp_head / template rendering);
		 * during REST, JSON, AJAX, CLI, or admin requests the tag is built and
		 * returned without echo so HTML can never leak into non-HTML payloads.
		 * Callers that assemble headers/buffers should use
		 * {@see get_preload_link()} directly.
		 *
		 * Preconnect and dns-prefetch links now flow through core's
		 * `wp_resource_hints()` (see Main::add_resource_hints()); this helper
		 * remains for `rel="preload"` links that need `as`/`type`/`media`
		 * control.
		 *
		 * @param string $href The resource URL.
		 * @param string $rel The relationship attribute.
		 * @param string $resource_type The type of the resource (optional).
		 * @param bool   $crossorigin If the resource should be crossorigin (optional).
		 * @param string $type The type attribute (optional).
		 * @param string $media The media attribute (optional).
		 * @param string $fetchpriority The fetchpriority attribute (optional).
		 * @param string $imagesrcset Responsive srcset for image preloads (optional).
		 * @param string $imagesizes Responsive sizes for image preloads (optional).
		 * @since 1.0.0
		 * @since 2.0.0 Echoes only in front-end HTML contexts; returns the tag and delegates building to get_preload_link().
		 * @since 2.2.0 Adds $imagesrcset/$imagesizes for LCP image preloads.
		 */
		public static function generate_preload_link( $href, $rel, $resource_type = '', $crossorigin = false, $type = '', $media = '', $fetchpriority = '', $imagesrcset = '', $imagesizes = '' ) {
			$link_tag = self::get_preload_link( $href, $rel, $resource_type, $crossorigin, $type, $media, $fetchpriority, $imagesrcset, $imagesizes );

			// Only echo in front-end HTML contexts (wp_head / template rendering).
			// Echoing during REST, AJAX, cron, CLI, or admin contexts can inject
			// HTML into JSON/CLI output; callers that need the markup there should
			// use get_preload_link() and handle the string themselves.
			// wp_is_json_request() also covers early-boot JSON responses that
			// precede REST_REQUEST.
			if ( ( function_exists( 'is_admin' ) && is_admin() )
				|| ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() )
				|| ( function_exists( 'wp_doing_cron' ) && wp_doing_cron() )
				|| ( function_exists( 'wp_is_json_request' ) && wp_is_json_request() )
				|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
				|| ( defined( 'WP_CLI' ) && WP_CLI ) ) {
				return $link_tag;
			}

			// Output the sanitized link tag.
			echo $link_tag . PHP_EOL; // phpcs:ignore WordPress.Security.EscapeOutput -- wp_kses() applied in get_preload_link().
			return $link_tag;
		}

		/**
		 * Build a sanitized preload <link> tag and return it.
		 *
		 * Pure string builder used by {@see generate_preload_link()}; also lets
		 * callers that assemble headers or buffers (e.g. cache generation) obtain
		 * the tag without any side effects.
		 *
		 * @param string $href The resource URL.
		 * @param string $rel The relationship attribute.
		 * @param string $resource_type The type of the resource (optional).
		 * @param bool   $crossorigin If the resource should be crossorigin (optional).
		 * @param string $type The type attribute (optional).
		 * @param string $media The media attribute (optional).
		 * @param string $fetchpriority The fetchpriority attribute (optional).
		 * @param string $imagesrcset Responsive srcset for image preloads (optional).
		 * @param string $imagesizes Responsive sizes for image preloads (optional).
		 * @since 2.0.0
		 * @since 2.2.0 Adds $imagesrcset/$imagesizes for LCP image preloads.
		 * @return string The sanitized `<link ...>` tag.
		 */
		public static function get_preload_link( $href, $rel, $resource_type = '', $crossorigin = false, $type = '', $media = '', $fetchpriority = '', $imagesrcset = '', $imagesizes = '' ): string {
			$attributes = array(
				'rel'  => esc_attr( $rel ),
				'href' => esc_url( $href ),
			);

			if ( $resource_type ) {
				$attributes['as'] = esc_attr( $resource_type );
			}
			if ( $crossorigin ) {
				$attributes['crossorigin'] = 'anonymous';
			}
			if ( $type ) {
				$attributes['type'] = esc_attr( $type );
			}
			if ( $media ) {
				$attributes['media'] = esc_attr( $media );
			}
			if ( $fetchpriority ) {
				$attributes['fetchpriority'] = esc_attr( $fetchpriority );
			}
			// Responsive preload hints: only meaningful for images, so an
			// empty/invalid srcset (or a non-image `as`) emits the current
			// tag unchanged (fail-open).
			$imagesrcset = is_string( $imagesrcset ) ? trim( substr( $imagesrcset, 0, 4096 ) ) : '';
			$imagesizes  = is_string( $imagesizes ) ? trim( substr( $imagesizes, 0, 1024 ) ) : '';
			if ( '' !== $imagesrcset && 'image' === $resource_type ) {
				$attributes['imagesrcset'] = esc_attr( $imagesrcset );
				if ( '' !== $imagesizes ) {
					$attributes['imagesizes'] = esc_attr( $imagesizes );
				}
			}

			$link_tag = '<link ' . implode( ' ', array_map( fn ( $k, $v ) => $k . '="' . $v . '"', array_keys( $attributes ), $attributes ) ) . '>';

			// Static-cached (issue #1216): every preload link rebuilt this
			// allowlist per call (~10x HTML tokenizer runs per render when
			// manual + auto + fonts + CSS combine stack up). The shape is
			// constant so one shared copy is safe.
			static $allowed_html = array(
				'link' => array(
					'rel'           => array(),
					'href'          => array(),
					'as'            => array(),
					'crossorigin'   => array(),
					'type'          => array(),
					'media'         => array(),
					'fetchpriority' => array(),
					'imagesrcset'   => array(),
					'imagesizes'    => array(),
				),
			);

			// Return the sanitized link tag.
			return (string) wp_kses( $link_tag, $allowed_html );
		}

		/**
		 * Build a sanitized preload <link> tag from an args array.
		 *
		 * Array/value-object overload so callers cannot silently swap the
		 * nine positional parameters ($fetchpriority vs $imagesrcset). The
		 * legacy positional {@see get_preload_link()} delegates here.
		 *
		 * @since 2.2.0
		 * @param string $href Resource URL.
		 * @param array  $args Optional args: rel, as, crossorigin, type, media, fetchpriority, imagesrcset, imagesizes.
		 * @return string Sanitized `<link ...>` tag.
		 */
		public static function get_preload_link_args( string $href, array $args = array() ): string {
			$rel           = isset( $args['rel'] ) ? (string) $args['rel'] : 'preload';
			$as            = isset( $args['as'] ) ? (string) $args['as'] : '';
			$crossorigin   = ! empty( $args['crossorigin'] );
			$type          = isset( $args['type'] ) ? (string) $args['type'] : '';
			$media         = isset( $args['media'] ) ? (string) $args['media'] : '';
			$fetchpriority = isset( $args['fetchpriority'] ) ? (string) $args['fetchpriority'] : '';
			$imagesrcset   = isset( $args['imagesrcset'] ) ? (string) $args['imagesrcset'] : '';
			$imagesizes    = isset( $args['imagesizes'] ) ? (string) $args['imagesizes'] : '';
			return self::get_preload_link( $href, $rel, $as, $crossorigin, $type, $media, $fetchpriority, $imagesrcset, $imagesizes );
		}

		/**
		 * Normalize and deduplicate a list of URLs.
		 *
		 * If given an array, each element is trimmed, duplicates and empty values are removed, and the result is reindexed.
		 * If given a non-array, the value is cast to string, split on newline characters, then trimmed, deduplicated, filtered and reindexed.
		 *
		 * @param string|array $urls Raw URLs as a newline-delimited string or an array of strings.
		 * @return array Cleaned list of unique, trimmed URLs with empty values removed and numeric keys reindexed.
		 * @since 1.0.0
		 */
		public static function process_urls( $urls ) {
			if ( is_array( $urls ) ) {
				return array_values( array_filter( array_unique( array_map( 'trim', $urls ) ) ) );
			}
			return array_values( array_filter( array_unique( array_map( 'trim', explode( "\n", (string) $urls ) ) ) ) );
		}

		/**
		 * Coerce an untrusted string list (e.g. filter output) to a clean list.
		 *
		 * Drops non-string/non-numeric entries (instead of casting arrays to
		 * "Array"), trims, drops empties, dedupes, and reindexes. Single
		 * shared helper for the delay-JS third-party allowlist mirrors in
		 * Main and Minify\HTML so allowlist semantics stay in one place.
		 *
		 * @param mixed $raw Untrusted list value.
		 * @return string[] Clean list.
		 * @since 2.2.0
		 */
		public static function coerce_string_list( $raw ): array {
			if ( ! is_array( $raw ) ) {
				return array();
			}
			$mapped   = array_map(
				static function ( $val ): string {
					if ( is_string( $val ) || is_numeric( $val ) ) {
						return trim( (string) $val );
					}
					return '';
				},
				$raw
			);
			$filtered = array_filter(
				$mapped,
				static function ( $val ): bool {
					return '' !== $val;
				}
			);
			return array_values( array_unique( $filtered ) );
		}

		/**
		 * Check whether a URL matches any of the exclusion rules.
		 *
		 * Both the URL being checked and each exclusion rule are normalized with
		 * a trailing-slash trim before matching. Schemes are normalized so that
		 * `http://` and `https://` rules match interchangeably. Root-relative
		 * rules are resolved against {@see home_url()}. Rules containing a
		 * "(.*)" placeholder act as prefix patterns; all other rules must match
		 * exactly. Empty and whitespace-only rules are ignored.
		 *
		 * @param string $url         The URL to check.
		 * @param array  $exclude_urls List of exclusion rules.
		 * @return bool True when the URL matches any exclusion rule, false otherwise.
		 * @since 2.0.0
		 */
		public static function is_url_excluded( string $url, array $exclude_urls ): bool {
			$url = rtrim( $url, '/' );

			$strip_scheme = static function ( $u ) {
				if ( 0 === stripos( $u, 'https://' ) ) {
					return substr( $u, 8 );
				}
				if ( 0 === stripos( $u, 'http://' ) ) {
					return substr( $u, 7 );
				}
				return $u;
			};

			static $cache = array();
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			$cache_key = md5( serialize( $exclude_urls ) ); // We use serialize since it's faster and guaranteed safe for arrays of strings internally generated.

			if ( ! isset( $cache[ $cache_key ] ) ) {
				$home_base = self::cached_home_url();
				$exact     = array();
				$prefix    = array();

				foreach ( $exclude_urls as $exclude_url ) {
					$exclude_url = rtrim( $exclude_url, '/' );

					if ( '' === $exclude_url ) {
						continue;
					}

					if ( 0 !== strpos( $exclude_url, 'http' ) ) {
						$exclude_url = $home_base . '/' . ltrim( $exclude_url, '/' );
					}

					$normalized_rule = $strip_scheme( $exclude_url );

					if ( false !== strpos( $normalized_rule, '(.*)' ) ) {
						$exclude_prefix = rtrim( str_replace( '(.*)', '', $normalized_rule ), '/' ) . '/';
						$prefix[]       = $exclude_prefix;
					} else {
						$exact[ $normalized_rule ] = true;
					}
				}

				$cache[ $cache_key ] = array(
					'exact'  => $exact,
					'prefix' => $prefix,
				);
			}

			$normalized_url = $strip_scheme( $url );

			if ( isset( $cache[ $cache_key ]['exact'][ $normalized_url ] ) ) {
				return true;
			}

			foreach ( $cache[ $cache_key ]['prefix'] as $exclude_prefix ) {
				if ( 0 === strpos( $normalized_url . '/', $exclude_prefix ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Get the current front-end URL including scheme and host.
		 *
		 * Returns a normalized URL without query string, consistent with
		 * the normalization used in store_lcp_image_url(). The returned
		 * URL is untrailingslashed and passed through esc_url_raw().
		 *
		 * @since 2.0.0
		 * @return string Current URL.
		 */
		public static function get_current_url(): string {
			global $wp;
			$url = self::cached_home_url( (string) add_query_arg( array(), $wp->request ?? '' ) );
			return untrailingslashit( esc_url_raw( $url ) );
		}

		/**
		 * Normalize a RUM page path for storage and lookup.
		 *
		 * Both the beacon store path (RUM::print_config/sanitize_sample) and
		 * the field-LCP lookup path (Image_Optimisation::get_current_lcp_url)
		 * must agree, otherwise the override silently never fires: the
		 * lookup strips the trailing slash via get_current_url() while the
		 * stored beacon path kept it verbatim. Trims the trailing slash
		 * (keeping '/' for the root) and ensures a leading slash.
		 *
		 * @since 2.0.0
		 * @param string $path Raw page path.
		 * @return string Normalized path (e.g. '/hero-page', '/').
		 */
		public static function normalize_rum_path( string $path ): string {
			$path = trim( $path );
			if ( '' === $path ) {
				return '/';
			}
			if ( '/' !== substr( $path, 0, 1 ) ) {
				$path = '/' . $path;
			}
			if ( '/' !== $path ) {
				$path = rtrim( $path, '/' );
				if ( '' === $path ) {
					return '/';
				}
			}
			return $path;
		}

		/**
		 * Normalize a URL for LCP matching.
		 *
		 * Resolves protocol-relative and root-relative URLs against home_url(),
		 * drops the scheme and any query string, and strips WordPress generated
		 * size suffixes (-NNNxNNN, -scaled, -eNNN) so derived assets are treated
		 * as the same image as their full-size original. Returns host + path
		 * lowercased for host, or empty string when unparseable.
		 *
		 * @since 2.0.0
		 * @param string $url The raw URL to normalize.
		 * @return string Normalized host + path, or empty string when unparseable.
		 */
		public static function normalize_url( string $url ): string {
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return '';
			}

			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}

			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = self::cached_home_url() . '/' . ltrim( $url, '/' );
			}

			$parts = wp_parse_url( $url );
			if ( empty( $parts['path'] ) ) {
				return '';
			}

			$host = strtolower( $parts['host'] ?? '' );
			$path = $parts['path'];
			// The `$` anchor lives inside the lookahead so the suffix only
			// strips immediately before the file extension at end of path
			// (a trailing `$` outside the lookahead could never match).
			$path = (string) preg_replace( '#-(?:\d+x\d+|scaled|e\d+)(?=\.[A-Za-z0-9]+$)#', '', $path );

			return $host . $path;
		}

		/**
		 * Resolve a site URL to an absolute https URL.
		 *
		 * Centralizes the protocol-relative / root-relative / home-base
		 * branches re-implemented across Image_Optimisation, Img_Converter,
		 * Used_CSS and Critical_CSS so a fix here reaches every pipeline.
		 *
		 * @since 2.2.0
		 * @param string $url Raw URL.
		 * @return string Absolute URL or '' when empty/data:.
		 */
		public static function normalize_site_url( string $url ): string {
			$url = trim( $url );
			if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
				return '';
			}
			if ( 0 === strpos( $url, '//' ) ) {
				$url = 'https:' . $url;
			}
			if ( 0 === strpos( $url, '/' ) || false === strpos( $url, '://' ) ) {
				$url = self::cached_home_url() . '/' . ltrim( $url, '/' );
			}
			return $url;
		}

		/**
		 * Normalize a URL to a stable image/cache key (host + path).
		 *
		 * Thin canonical wrapper over {@see normalize_url()} so image and CSS
		 * pipelines share one key derivation instead of parallel copies.
		 *
		 * @since 2.2.0
		 * @param string $url Raw URL.
		 * @return string Normalized key or ''.
		 */
		public static function normalize_image_key( string $url ): string {
			return self::normalize_url( $url );
		}

		/**
		 * Mint a per-request placeholder token namespace.
		 *
		 * Shared by the noscript placeholder pipeline
		 * (Image_Optimisation::get_noscript_namespace()) and the preserved-script
		 * pipeline (Minify\HTML::get_preserve_namespace()) so the entropy and
		 * fallback chain cannot drift between the two callers.
		 *
		 * Uses cryptographically random hex via `random_bytes()` when available,
		 * falling back to `wp_generate_password()` (sanitized to alphanumerics)
		 * and finally to a `uniqid()`/`wp_rand()` token. Never fatals: any
		 * failure degrades to a static fallback namespace (fail-open).
		 *
		 * @since 2.0.0
		 * @return string Non-empty namespace string.
		 */
		public static function mint_placeholder_namespace(): string {
			try {
				if ( function_exists( 'random_bytes' ) ) {
					$bytes = random_bytes( 8 );
					if ( is_string( $bytes ) && '' !== $bytes ) {
						return 'wppo' . bin2hex( $bytes );
					}
				}

				if ( function_exists( 'wp_generate_password' ) ) {
					$generated = wp_generate_password( 16, false );
					if ( is_string( $generated ) && '' !== $generated ) {
						$sanitized = preg_replace( '/[^A-Za-z0-9]/', '', $generated );
						if ( is_string( $sanitized ) && '' !== $sanitized ) {
							return 'wppo' . $sanitized;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}

			// Legacy fallback (fail-open, never fatal): wp_rand() when available,
			// otherwise uniqid() + microtime() entropy. No mt_rand() (discouraged).
			if ( function_exists( 'wp_rand' ) ) {
				$suffix = (string) wp_rand( 1000, 9999 );
			} else {
				$suffix = str_replace( '.', '', (string) microtime( true ) );
			}
			$namespace = 'wppo' . str_replace( '.', '', uniqid( '', true ) ) . $suffix;
			$namespace = (string) preg_replace( '/[^A-Za-z0-9]/', '', $namespace );
			return '' === $namespace ? 'wppofallback' : $namespace;
		}

		/**
		 * Base (shared) minify cache directory.
		 *
		 * Minified JS/CSS files are namespaced per site (see {@see min_cache_dir()}),
		 * so this returns the shared root. Used for the plugin-owned path-data
		 * prefix check and one-time cleanup of pre-namespacing directories.
		 *
		 * @return string Normalized absolute path to the shared min cache root.
		 * @since 2.0.0
		 */
		public static function min_cache_base_dir(): string {
			return wp_normalize_path( WP_CONTENT_DIR . '/cache/wppo/min' );
		}

		/**
		 * Get the current site's blog-scoped minify cache directory.
		 *
		 * Mirrors the blog-ID isolation conventions used by {@see transient_key()}
		 * and {@see cached_content_url()}: on multisite each site's minified
		 * JS/CSS files live under `cache/wppo/min/{blog_id}/{css,js}` so a cache
		 * clear scoped to one site cannot invalidate another site's assets (whose
		 * min files may embed site-specific `content_url()` URLs).
		 *
		 * @param string $subdir Optional 'css' or 'js' subdirectory.
		 * @return string Normalized absolute path to the site-scoped min cache dir.
		 * @since 2.0.0
		 */
		public static function min_cache_dir( string $subdir = '' ): string {
			$dir = self::min_cache_base_dir() . '/' . get_current_blog_id();
			if ( '' !== $subdir ) {
				$dir .= '/' . $subdir;
			}
			return wp_normalize_path( $dir );
		}

		/**
		 * Get the content URL for a file in the current site's min cache dir.
		 *
		 * @param string $subdir   Optional 'css' or 'js' subdirectory.
		 * @param string $filename Optional file name appended to the URL.
		 * @return string The blog-scoped content URL.
		 * @since 2.0.0
		 */
		public static function min_cache_url( string $subdir = '', string $filename = '' ): string {
			$path = 'cache/wppo/min/' . get_current_blog_id();
			if ( '' !== $subdir ) {
				$path .= '/' . $subdir;
			}
			if ( '' !== $filename ) {
				$path .= '/' . $filename;
			}
			return self::cached_content_url( $path );
		}

		/**
		 * Get a content URL, cached per site per request.
		 *
		 * Centralizes the blog-ID-keyed static caching pattern used by the asset
		 * minifiers. Mirrors the convention in `class-main.php`: when a
		 * `content_url` filter is registered the result is not cached (the filter
		 * may return context-dependent output), otherwise the base URL is resolved
		 * once per site per request and reused across all call sites.
		 *
		 * The scheme is pinned to the site's canonical scheme (see
		 * `canonical_scheme()`), because `content_url()` derives its scheme from
		 * `is_ssl()`, which is false wherever `$_SERVER['HTTPS']` is absent —
		 * notably WP-CLI and bare cron runs. Asset URLs built here are written
		 * into cached CSS/JS on disk, so an `http://` result in one CLI run would
		 * be served to HTTPS visitors as mixed content and the browser would
		 * block the asset.
		 *
		 * @param string $path Path relative to the content directory.
		 * @return string The content URL for the given path.
		 * @since 2.0.0
		 */
		public static function cached_content_url( $path ) {
			if ( false !== has_filter( 'content_url' ) ) {
				return content_url( $path );
			}

			static $cache = array();
			$blog_id      = get_current_blog_id();

			if ( ! isset( $cache[ $blog_id ] ) ) {
				$cache[ $blog_id ] = array();
			}
			if ( ! isset( $cache[ $blog_id ][ $path ] ) ) {
				$cache[ $blog_id ][ $path ] = self::pin_url_scheme( content_url( $path ) );
			}

			return $cache[ $blog_id ][ $path ];
		}

		/**
		 * Rewrite a URL's scheme to the site's canonical scheme.
		 *
		 * Deliberately implemented with plain string handling rather than
		 * `set_url_scheme()`: this runs while building cacheable asset URLs, so
		 * it must stay callable from any context, including bootstraps where
		 * only a subset of core functions is loaded. `set_url_scheme()` is
		 * still honoured indirectly because `content_url()` already applied
		 * the `content_url` filter before this point.
		 *
		 * Only `http`/`https` are touched; any other scheme (or a scheme-less
		 * value) is returned unchanged.
		 *
		 * @since 2.2.0
		 * @param string $url URL to normalize.
		 * @return string URL carrying the canonical scheme.
		 */
		private static function pin_url_scheme( string $url ): string {
			$scheme = self::canonical_scheme();

			if ( 'https' === $scheme && 0 === stripos( $url, 'http://' ) ) {
				return 'https://' . substr( $url, 7 );
			}
			if ( 'http' === $scheme && 0 === stripos( $url, 'https://' ) ) {
				return 'http://' . substr( $url, 8 );
			}

			return $url;
		}

		/**
		 * The site's canonical URL scheme, independent of the current request.
		 *
		 * Derived from `home_url()` rather than `is_ssl()`. `is_ssl()` reports
		 * the scheme of the *current request* and is false in any context
		 * without `$_SERVER['HTTPS']` — WP-CLI, a bare cron run, or a proxy
		 * that does not forward the header. That makes it unsafe for building
		 * URLs which are then written to disk and served to later visitors:
		 * a single CLI-triggered cache write would bake `http://` asset URLs
		 * into cached CSS/JS and the browser would block them as mixed
		 * content on every HTTPS page.
		 *
		 * Always returns `'http'` or `'https'`, so the result is safe to pass
		 * to `set_url_scheme()`.
		 *
		 * @since 2.2.0
		 * @return string Either 'http' or 'https'.
		 */
		public static function canonical_scheme(): string {
			$scheme = '';
			try {
				if ( function_exists( 'home_url' ) && function_exists( 'wp_parse_url' ) ) {
					$scheme = (string) wp_parse_url( home_url(), PHP_URL_SCHEME );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				$scheme = '';
			}

			return 'https' === strtolower( $scheme ) ? 'https' : 'http';
		}

		/**
		 * Get the home URL, cached per site per request.
		 *
		 * Centralizes the blog-ID-keyed static caching pattern used across the plugin.
		 * When a `home_url` filter is registered the result is not cached (the filter
		 * may return context-dependent output), otherwise the base URL is resolved
		 * once per site per request and reused across all call sites.
		 *
		 * @param string $path Optional. Path relative to the home URL. Default empty.
		 * @return string The untrailingslashed home URL, with path appended if provided.
		 * @since 2.0.0
		 */
		public static function cached_home_url( string $path = '' ): string {
			if ( false !== has_filter( 'home_url' ) ) {
				return '' === $path ? untrailingslashit( home_url() ) : home_url( $path );
			}

			$blog_id = get_current_blog_id();

			if ( ! isset( self::$home_url_cache[ $blog_id ] ) ) {
				self::$home_url_cache[ $blog_id ] = untrailingslashit( home_url() );
			}

			if ( '' === $path ) {
				return self::$home_url_cache[ $blog_id ];
			}

			return self::$home_url_cache[ $blog_id ] . '/' . ltrim( $path, '/' );
		}

		/**
		 * Memoized lowercase home host for same-site checks, keyed by blog.
		 *
		 * @since 2.2.0
		 * @var array<int, string>
		 */
		private static $same_site_home_host = array();

		/**
		 * Whether a URL's host matches the home host (case-insensitive).
		 *
		 * Canonical home-host comparator (audit #1357 review): DNS is
		 * case-insensitive, so both sides lowercase (Rest::is_same_site_url()
		 * compared raw === and was the outlier). Host-only — no scheme or
		 * syntax validation; use is_same_site_url() for full checks.
		 *
		 * @since 2.2.0
		 * @param string $url URL to check.
		 * @return bool True when hosts match and home host is known.
		 */
		public static function is_same_site_host( string $url ): bool {
			try {
				$blog_id = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
				if ( ! array_key_exists( $blog_id, self::$same_site_home_host ) ) {
					$home_host                             = function_exists( 'wp_parse_url' ) ? wp_parse_url( self::cached_home_url(), PHP_URL_HOST ) : '';
					self::$same_site_home_host[ $blog_id ] = ( is_string( $home_host ) ) ? strtolower( $home_host ) : '';
				}
				if ( '' === self::$same_site_home_host[ $blog_id ] ) {
					return false;
				}
				$host = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url, PHP_URL_HOST ) : '';
				return is_string( $host ) && '' !== $host && strtolower( $host ) === self::$same_site_home_host[ $blog_id ];
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a URL is same-site and safe for server-side fetching.
		 *
		 * Canonical full check (audit #1357 review): wp_http_validate_url()
		 * syntax + http(s) scheme + is_same_site_host(). Delegates:
		 * Rest::is_same_site_url(), Critical_CSS::is_same_site_host() (host
		 * part), and Abilities::same_site_url_or_home() all funnel here so
		 * port/case/IDN edge cases cannot drift apart.
		 *
		 * @since 2.2.0
		 * @param string $url URL to check.
		 * @return bool True when safe.
		 */
		public static function is_same_site_url( string $url ): bool {
			try {
				if ( '' === $url ) {
					return false;
				}
				if ( function_exists( 'wp_http_validate_url' ) && ! wp_http_validate_url( $url ) ) {
					return false;
				}
				$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $url ) : false;
				if ( ! is_array( $parsed ) ) {
					return false;
				}
				$scheme = $parsed['scheme'] ?? '';
				if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
					return false;
				}
				return self::is_same_site_host( $url );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Validate a caller-supplied URL as same-site, else the fallback.
		 *
		 * @since 2.2.0
		 * @param string $url      Caller URL (already esc_url_raw'd by caller).
		 * @param string $fallback Fallback (home URL, or '' to fail closed).
		 * @return string Same-site URL or the fallback.
		 */
		public static function same_site_url_or_home( string $url, string $fallback ): string {
			try {
				return self::is_same_site_url( $url ) ? $url : $fallback;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $fallback;
			}
		}

		/**
		 * Normalize a raw host value into a safe cache-key domain.
		 *
		 * Lowercases, converts IDN to ASCII, strips any port, and applies the
		 * same allowlist/traversal validation used by the cache constructors so
		 * the canonicalization rule lives in one place. Returns an empty string
		 * when the value is missing or invalid (fail-open signal: callers fall
		 * back to legacy behaviour instead of writing under a forged host).
		 *
		 * @param string $raw_host Raw host value (e.g. $_SERVER['HTTP_HOST'] or a home_url() host).
		 * @return string Normalized lowercase host, or '' when invalid.
		 * @since 2.0.0
		 */
		public static function normalize_cache_host( string $raw_host ): string {
			// Static memo: hot callers (sanitize loops, invalidation fan-out)
			// pass the same already-canonical domain repeatedly; skip the
			// trim + port-strip + idn_to_ascii + regex work on repeats.
			if ( isset( self::$normalized_host_cache[ $raw_host ] ) ) {
				return self::$normalized_host_cache[ $raw_host ];
			}
			$domain = trim( $raw_host );
			if ( '' === $domain ) {
				self::$normalized_host_cache[ $raw_host ] = '';
				return '';
			}

			// Strip any port BEFORE IDN conversion: UTS46 rejects
			// 'münchen.de:8080' as a whole, so converting first would fail
			// and force a fail-open '' for IDN hosts with explicit ports.
			// Bracketed IPv6 literals ('[::1]:8080') are unwrapped first;
			// unbracketed multi-colon values are IPv6 literals (no port).
			if ( str_starts_with( $domain, '[' ) ) {
				$bracket_end = strpos( $domain, ']' );
				if ( false === $bracket_end ) {
					return self::memoize_normalized_host( $raw_host, '' );
				}
				// Reject trailing garbage after the bracket (e.g. '[::1]evil'):
				// only '' or a ':port' suffix is a well-formed bracketed host.
				$rest = substr( $domain, $bracket_end + 1 );
				if ( '' !== $rest && ':' !== substr( $rest, 0, 1 ) ) {
					return self::memoize_normalized_host( $raw_host, '' );
				}
				$host = substr( $domain, 1, $bracket_end - 1 );
			} elseif ( substr_count( $domain, ':' ) > 1 ) {
				$host = $domain;
			} else {
				$host = explode( ':', $domain, 2 )[0];
			}
			if ( '' === $host ) {
				return self::memoize_normalized_host( $raw_host, '' );
			}

			if ( function_exists( 'idn_to_ascii' ) ) {
				try {
					$converted = idn_to_ascii( $host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46 );
				} catch ( \Throwable $e ) {
					unset( $e );
					$converted = false;
				}
				if ( false !== $converted && is_string( $converted ) && '' !== $converted ) {
					$host = $converted;
				}
			}

			$valid = ! (
			strpos( $host, '..' ) !== false ||
			strpos( $host, '/' ) !== false ||
			strpos( $host, '\\' ) !== false ||
			! preg_match( '/^[a-z0-9\.\-:]+$/i', $host )
			);

			if ( ! $valid ) {
				return self::memoize_normalized_host( $raw_host, '' );
			}

			return self::memoize_normalized_host( $raw_host, strtolower( $host ) );
		}

		/**
		 * Store a normalized host in the per-value memo (bounded size).
		 *
		 * @param string $raw_host Raw input key.
		 * @param string $normalized Normalized result.
		 * @return string The normalized result (passthrough for `return` sites).
		 * @since 2.2.0
		 */
		private static function memoize_normalized_host( string $raw_host, string $normalized ): string {
			if ( count( self::$normalized_host_cache ) > 64 ) {
				self::$normalized_host_cache = array();
			}
			self::$normalized_host_cache[ $raw_host ] = $normalized;
			return $normalized;
		}

		/**
		 * Resolve the canonical host for cache keying from home_url().
		 *
		 * The static HTML cache tree (and the used-CSS cache dir) must be keyed
		 * by the canonical home host so a forged Host header can never create
		 * or serve a poisoned cache file (Host-header cache poisoning to stored
		 * XSS). Multisite-safe: home_url() is already blog-aware. Returns an
		 * empty string when home_url() is unavailable (early boot, CLI) so
		 * callers can fall back to the legacy Host-derived domain instead of
		 * fataling.
		 *
		 * @return string Canonical lowercase host, or '' when it cannot be resolved.
		 * @since 2.0.0
		 */
		public static function get_canonical_host(): string {
			// Per-blog static memo mirroring cached_home_url(): every call
			// otherwise repeats home_url() + wp_parse_url(HOST) +
			// normalize_cache_host() (with idn_to_ascii), 2-3x per request
			// (Cache + Used_CSS + REST). Not memoized when a home_url
			// filter is present (context-dependent output).
			$bid = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( function_exists( 'has_filter' ) && false !== has_filter( 'home_url' ) ) {
				return self::resolve_canonical_host();
			}
			if ( isset( self::$canonical_host_cache[ $bid ] ) ) {
				return self::$canonical_host_cache[ $bid ];
			}
			$resolved                           = self::resolve_canonical_host();
			self::$canonical_host_cache[ $bid ] = $resolved;
			return $resolved;
		}

		/**
		 * Uncached canonical-host resolution backing get_canonical_host().
		 *
		 * @return string Canonical lowercase host, or '' when unresolvable.
		 * @since 2.2.0
		 */
		private static function resolve_canonical_host(): string {
			if ( ! function_exists( 'home_url' ) || ! function_exists( 'wp_parse_url' ) ) {
				return '';
			}
			try {
				$home = home_url();
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
			if ( ! is_string( $home ) || '' === $home ) {
				return '';
			}
			try {
				$host = wp_parse_url( $home, PHP_URL_HOST );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
			if ( ! is_string( $host ) || '' === $host ) {
				return '';
			}
			return self::normalize_cache_host( $host );
		}

		/**
		 * Sanitize a URL path for cache file mapping.
		 *
		 * Shared encoded-sequence normalization for every file-writing
		 * surface (static HTML cache in {@see Cache}, per-page used-CSS in
		 * {@see Used_CSS}): only the PHP_URL_PATH component is used, exactly
		 * one rawurldecode pass is applied (single-decode semantics —
		 * `%252e` stays literal on disk and is never re-decoded, while
		 * single-encoded `%2e%2e` / `%00` decode once and are then rejected),
		 * and null bytes, remaining `..` segments, plus Windows drive (`C:`)
		 * and UNC (`\\`) prefixes are rejected. Absolute-form inputs
		 * (absolute URLs, protocol-relative `//host`, detected on the
		 * authority part before `?`/`#` so query strings carrying URLs never
		 * false-positive) are host-checked when `$allowed_host` is given: a
		 * same-host absolute URL maps to its path, while a foreign-host (or
		 * hostless protocol-relative/drive/UNC) input is refused outright so
		 * direct callers can never map a foreign host onto the local tree.
		 * Without `$allowed_host` the legacy path-only extraction applies.
		 * Returns an empty string for hostile or empty input; callers fail
		 * open (serve dynamic/uncached and log a traversal probe) when the
		 * raw input was non-blank.
		 *
		 * Pure static helper: no I/O, no settings reads. Multisite-safe.
		 *
		 * @param string|null $url_path     Raw URL path or URL.
		 * @param string|null $allowed_host Optional canonical host (alias: $domain / $canonical_host at call-sites);
		 *                                  same-host absolute URLs map to their path, others refuse.
		 * @return string Sanitized relative path or empty string.
		 * @since 2.0.0
		 * @since 2.2.0 Added the optional $allowed_host foreign-host refusal.
		 */
		public static function sanitize_cache_url_path( ?string $url_path, ?string $allowed_host = null ): string {
			$raw_input = (string) $url_path;

			// Reject Windows drive prefixes and UNC roots before URL parsing
			// (parse_url() would otherwise strip `C:` as a scheme and hide
			// the absolute-path smuggling attempt).
			$trimmed_raw = ltrim( $raw_input );
			if ( '' !== $trimmed_raw && ( preg_match( '#^[a-zA-Z]:#', $trimmed_raw ) || 0 === strpos( $trimmed_raw, '\\\\' ) ) ) {
				return '';
			}

			// Absolute-form detection on the authority part only (before
			// `?`/`#`), mirroring Cache::__construct / sanitize_cache_path():
			// a benign relative path whose query/fragment carries a URL
			// (e.g. `/search?redirect=https://other`) must not false-positive.
			// The split uses strcspn() (no process-global strtok() state).
			// Both the raw and the single-decoded authority are inspected so
			// an encoded scheme (`https%3a%2f%2f`) cannot smuggle past. The
			// test is scheme-anchored (parity with Cache::__construct) so a
			// benign segment containing `://` (e.g. `/foo/a://b`) is not
			// misclassified; a lingering `%3a` remnant after one decode
			// (double-encoded scheme) still counts as absolute-form for
			// detection only — the mapped path keeps single-decode semantics.
			$authority = substr( $trimmed_raw, 0, strcspn( $trimmed_raw, '?#' ) );
			// Fast path: 99% of inputs are plain paths with no `%`, so skip
			// the decode and second candidate entirely in that case.
			$decoded_authority = $authority;
			if ( false !== strpos( $authority, '%' ) ) {
				$decoded_authority = rawurldecode( $authority );
			}
			$has_absolute_form = false;
			foreach ( array( $authority, $decoded_authority ) as $candidate ) {
				$candidate_trimmed = ltrim( (string) $candidate );
				if ( '' === $candidate_trimmed ) {
					continue;
				}
				if ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $candidate_trimmed ) || 0 === strpos( $candidate_trimmed, '//' ) ) {
					$has_absolute_form = true;
					break;
				}
				// Double-encoded scheme remnant (e.g. `https%253A%252F%252F`
				// decodes once to `https%3A%2F%2F`): treat as absolute-form
				// for detection only so host extraction still runs.
				if ( false !== stripos( $candidate_trimmed, '%3a' ) ) {
					$has_absolute_form = true;
					break;
				}
			}
			// Parse once and reuse the parts for both host and path checks
			// (wp_parse_url re-parses the string on every component call).
			$parts = null;
			if ( $has_absolute_form && null !== $allowed_host ) {
				if ( function_exists( 'wp_parse_url' ) ) {
					$parts = wp_parse_url( $raw_input );
				} else {
					$parts = parse_url( $raw_input ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( ! is_array( $parts ) ) {
					$parts = null;
				}
			}
			if ( $has_absolute_form && null !== $allowed_host ) {
				// Host-aware callers (domain context given) admit same-host
				// absolute URLs while refusing foreign-host (or hostless)
				// inputs outright, so a foreign host can never map onto the
				// local tree. Legacy callers pass null and keep the
				// historical path-only extraction below.
				// normalize_cache_host() is statically memoized, so the
				// already-canonical domain hot callers pass costs nothing
				// on repeats.
				$expected = is_string( $allowed_host ) && '' !== $allowed_host ? self::normalize_cache_host( $allowed_host ) : '';
				if ( '' === $expected ) {
					return '';
				}
				$host_raw = is_array( $parts ) ? ( $parts['host'] ?? null ) : null;
				if ( ! is_string( $host_raw ) || '' === $host_raw || self::normalize_cache_host( $host_raw ) !== $expected ) {
					return '';
				}
			}

			if ( is_array( $parts ) && array_key_exists( 'path', $parts ) ) {
				$parsed = $parts['path'];
			} elseif ( function_exists( 'wp_parse_url' ) ) {
				$parsed = wp_parse_url( $raw_input, PHP_URL_PATH );
			} else {
				$parsed = parse_url( $raw_input, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
			}

			// No path component (e.g. 'https://example.com?x=1'): refuse rather
			// than falling back to the raw input, which would let query text
			// influence the mapped cache directory.
			if ( null === $parsed || false === $parsed ) {
				return '';
			}
			$path_component = (string) $parsed;

			$decoded = rawurldecode( $path_component );

			if ( false !== strpos( $decoded, "\0" ) ) {
				return '';
			}

			if ( function_exists( 'wp_normalize_path' ) ) {
				$normalized = wp_normalize_path( trim( $decoded, '/' ) );
			} else {
				$normalized = str_replace( '\\', '/', trim( $decoded, '/' ) );
			}

			// Segment-only dot-dot check (parity with Rest): a benign
			// filename containing `..` (e.g. `my..photo.jpg`) must not be
			// over-blocked while real traversal segments still refuse.
			if ( false !== strpos( $normalized, "\0" ) || 1 === preg_match( '#(^|/)\.\.(/|$)#', $normalized ) ) {
				return '';
			}

			if ( '' !== $normalized && preg_match( '#^[a-zA-Z]:#', $normalized ) ) {
				return '';
			}

			return $normalized;
		}

		/**
		 * Filterable list of cache-neutral (tracking/marketing) query params.
		 *
		 * The static HTML cache key is path-only, so a request carrying only
		 * these params maps to the same `index.html` as the clean URL. They
		 * are safe to ignore for the *read* decision (the clean entry may be
		 * served) but a query-bearing response is still never *stored* over
		 * the clean file (see {@see Cache::maybe_store_cache()}), so
		 * tracking params can never poison the canonical entry.
		 *
		 * Filterable via `wppo_cache_query_allowlist` (guarded by
		 * `has_filter()` so the filter only runs when a listener exists).
		 * The legacy uncacheable params (`s`, `ver`, `v`) are intentionally
		 * NOT part of this list: they always force a dynamic response even
		 * if a filter adds them here.
		 *
		 * Pure static helper: no I/O, no settings reads. Multisite-safe.
		 *
		 * @return string[] Lowercase cache-neutral query param names.
		 * @since 2.2.0
		 */
		public static function get_cache_query_allowlist(): array {
			$defaults = array(
				'utm_source',
				'utm_medium',
				'utm_campaign',
				'utm_term',
				'utm_content',
				'utm_id',
				'gclid',
				'gbraid',
				'wbraid',
				'fbclid',
				'fb_action_ids',
				'fb_action_types',
				'fb_source',
				'msclkid',
				'ttclid',
				'li_fat_id',
				'mc_cid',
				'mc_eid',
				'igshid',
				'dclid',
				'yclid',
				'gclsrc',
				'_ga',
				'_gl',
				'pk_campaign',
				'pk_kwd',
				'piwik_kwd',
				'matomo',
			);
			try {
				if ( ! function_exists( 'has_filter' ) || ! function_exists( 'apply_filters' ) ) {
					return $defaults;
				}
				if ( ! has_filter( 'wppo_cache_query_allowlist' ) ) {
					return $defaults;
				}
				$filtered = apply_filters( 'wppo_cache_query_allowlist', $defaults );
				if ( ! is_array( $filtered ) ) {
					return $defaults;
				}
				$allowlist = array();
				foreach ( $filtered as $name ) {
					if ( ! is_string( $name ) || '' === trim( $name ) ) {
						continue;
					}
					$allowlist[] = strtolower( trim( $name ) );
				}
				if ( empty( $allowlist ) ) {
					return $defaults;
				}
				return array_values( array_unique( $allowlist ) );
			} catch ( \Throwable $e ) {
				unset( $e );
				return $defaults;
			}
		}

		/**
		 * Whether a query string forces a dynamic (uncached) response.
		 *
		 * Returns false for an empty query and for tracking-only queries
		 * (every param in {@see get_cache_query_allowlist()} or carrying the
		 * `utm_` prefix, matched case-insensitively). Returns true when any
		 * param is functional/unknown — including the legacy uncacheable
		 * params `s`, `ver`, `v`, which always force dynamic even if a
		 * filter allowlists them. Unknown params fail closed (dynamic) so a
		 * future marketing param can only over-cache, never poison: the
		 * write path ({@see Cache::maybe_store_cache()}) additionally
		 * refuses to store ANY query-bearing response over the path-only
		 * clean file.
		 *
		 * Pure static helper: no I/O, no settings reads. Multisite-safe.
		 * Fail-open direction on error: detection failure returns true
		 * (served dynamic, never cached).
		 *
		 * @param string|null $query_string Raw query string. Defaults to `$_SERVER['QUERY_STRING']`.
		 * @return bool True when the request must bypass the cache.
		 * @since 2.2.0
		 */
		public static function has_uncacheable_query( ?string $query_string = null ): bool {
			try {
				if ( null === $query_string ) {
					// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized below via wp_unslash()/sanitize_text_field() with function_exists() fallbacks.
					$raw = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
					if ( function_exists( 'wp_unslash' ) ) {
						$raw = wp_unslash( $raw );
					}
					if ( function_exists( 'sanitize_text_field' ) ) {
						$raw = sanitize_text_field( $raw );
					}
					$query_string = $raw;
				}
				if ( '' === trim( (string) $query_string ) ) {
					return false;
				}
				// Bound the parse: fail closed on runaway query strings and cap
				// the param count so a malicious 1 MB query cannot DoS the
				// gate. Over-long input goes dynamic instead of being
				// truncated (truncation would let padding hide a functional
				// param past the cut).
				if ( strlen( (string) $query_string ) > 5000 ) {
					return true;
				}
				$query_string = substr( (string) $query_string, 0, 5000 );
				$allowlist    = self::get_cache_query_allowlist();
				$allowed      = array_flip( $allowlist );
				// Split on both '&' and ';': on hosts where
				// arg_separator.input includes ';', PHP populates $_GET from
				// semicolon-separated params, so each must be classified.
				$pairs = preg_split( '/[&;]/', (string) $query_string );
				if ( ! is_array( $pairs ) ) {
					return true;
				}
				$checked = 0;
				foreach ( $pairs as $pair ) {
					$pair = trim( (string) $pair );
					if ( '' === $pair ) {
						continue;
					}
					$eq_pos = strpos( $pair, '=' );
					$name   = false === $eq_pos ? $pair : substr( $pair, 0, $eq_pos );
					$name   = strtolower( trim( (string) rawurldecode( $name ) ) );
					if ( '' === $name ) {
						continue;
					}
					++$checked;
					if ( $checked > 200 ) {
						return true;
					}
					// Legacy uncacheable params always force dynamic, even if
					// a filter added them to the allowlist.
					if ( 's' === $name || 'ver' === $name || 'v' === $name ) {
						return true;
					}
					if ( isset( $allowed[ $name ] ) || 0 === strpos( $name, 'utm_' ) ) {
						continue;
					}
					return true;
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether an absolute path stays inside the cache tree.
		 *
		 * Centralized dual-prefix containment: the normalized path must start
		 * with both the cache root and the per-domain directory
		 * (trailing-slash aware so `wppo-evil` never prefix-matches `wppo`).
		 * Empty root or domain fails closed. Pure static helper: no I/O.
		 * Multisite-safe: callers pass the per-site canonical domain.
		 *
		 * @param string $cache_root_dir Absolute cache root directory.
		 * @param string $domain Canonical domain directory segment.
		 * @param string $path Absolute file or directory path to check.
		 * @return bool True when contained.
		 * @since 2.0.0
		 */
		public static function is_cache_path_contained( string $cache_root_dir, string $domain, string $path ): bool {
			if ( '' === $cache_root_dir || '' === $domain || '' === $path ) {
				return false;
			}
			// Fail closed on its own: a future direct caller passing
			// unsanitized input must never prefix-match through. Null bytes
			// and dot-dot segments are refused before the prefix check;
			// current callers only ever pass sanitizer-built paths, so this
			// is defense-in-depth with no benign behavior change.
			if ( false !== strpos( $path, "\0" ) ) {
				return false;
			}
			if ( function_exists( 'wp_normalize_path' ) ) {
				$norm = wp_normalize_path( $path );
				$root = wp_normalize_path( $cache_root_dir );
			} else {
				$norm = str_replace( '\\', '/', $path );
				$root = str_replace( '\\', '/', $cache_root_dir );
			}
			if ( false !== strpos( $norm, "\0" ) || false !== strpos( $norm, '..' ) ) {
				return false;
			}
			$root       = rtrim( $root, '/' ) . '/';
			$domain_dir = $root . trim( $domain, '/' ) . '/';
			return 0 === strpos( $norm, $root ) && 0 === strpos( $norm, $domain_dir );
		}

		/**
		 * Symlink-aware containment check for cache write targets.
		 *
		 * Extends {@see is_cache_path_contained()} with `realpath()` symlink
		 * resolution so a symlink planted inside the cache tree (e.g.
		 * `{root}/{domain}/<segment>` pointing at `/etc`, a symlinked domain
		 * directory itself, or a leaf symlink passed as a directory purge
		 * target) cannot bypass the lexical prefix check at write time
		 * (CVE-2026-18051 class). Both the full normalized target (leaf
		 * included, so leaf symlinks resolve) and the `{root}/{domain}/`
		 * anchor are resolved via {@see resolve_realpath()} (nearest existing
		 * ancestor plus the lexical remainder, so brand-new pages and
		 * symlinked deploy roots keep working) and the resolved target must
		 * sit under the resolved anchor, which itself must sit under the
		 * resolved root (trailing-slash aware so `wppo-evil` never
		 * prefix-matches `wppo`).
		 *
		 * Fail-open applies ONLY when nothing on disk resolves yet (brand-new
		 * page tree): then there is no symlink to follow and the lexical
		 * verdict stands. The same fail-open covers hosts where `realpath()`
		 * itself is unavailable (symlinks cannot be ruled out there, but
		 * refusing every write would break caching entirely, so the lexical
		 * verdict stands and the probe is logged by callers). Empty
		 * root/domain/path, null bytes, `..` segments, and hostile domain
		 * segments (`/`, `\`, `..`, NUL) fail closed. Unexpected throwables
		 * fail closed (skip the write, serve dynamic) while staying
		 * non-fatal. Pure static helper: no I/O beyond `realpath()`, no
		 * settings reads. Multisite-safe: callers pass the per-site
		 * canonical domain.
		 *
		 * The resolved root/anchor pair is memoized per root+domain per
		 * request (invariant across files) so purge loops pay the
		 * ancestor-walk stat cost once, not once per file.
		 *
		 * @param string $cache_root_dir Absolute cache root directory.
		 * @param string $domain Canonical domain directory segment.
		 * @param string $path Absolute file or directory path to check.
		 * @return bool True when contained.
		 * @since 2.2.0
		 */
		public static function is_realpath_contained( string $cache_root_dir, string $domain, string $path ): bool {
			if ( '' === $cache_root_dir || '' === $domain || '' === $path ) {
				return false;
			}
			if ( false !== strpos( $path, "\0" ) || false !== strpos( $domain, "\0" ) ) {
				return false;
			}
			// Lexical fail-closed first: unsanitized input must never pass
			// on the symlink check alone.
			if ( ! self::is_cache_path_contained( $cache_root_dir, $domain, $path ) ) {
				return false;
			}
			if ( ! function_exists( 'realpath' ) || ! function_exists( 'dirname' ) ) {
				return true;
			}
			try {
				$domain_seg = trim( $domain, '/' );
				// The domain shapes the anchor (root + domain segment), so a
				// hostile segment must fail closed here rather than relying
				// on callers passing a canonical host.
				if ( '' === $domain_seg || false !== strpos( $domain_seg, '..' ) || false !== strpos( $domain_seg, '/' ) || false !== strpos( $domain_seg, '\\' ) ) {
					return false;
				}
				if ( function_exists( 'wp_normalize_path' ) ) {
					$root_norm   = rtrim( wp_normalize_path( $cache_root_dir ), '/' );
					$target_norm = wp_normalize_path( $path );
				} else {
					$root_norm   = rtrim( str_replace( '\\', '/', $cache_root_dir ), '/' );
					$target_norm = str_replace( '\\', '/', $path );
				}
				// Memoized anchor/root pair (per root+domain per request).
				static $anchor_memo = array();
				$memo_key           = $root_norm . "\0" . $domain_seg;
				if ( ! isset( $anchor_memo[ $memo_key ] ) ) {
					// Resolved anchor: the on-disk domain directory when it
					// exists (catches a symlinked domain dir), else the
					// resolved root plus the lexical domain segment (nothing
					// exists to symlink yet).
					$anchor = self::resolve_realpath( $root_norm . '/' . $domain_seg );
					if ( null === $anchor ) {
						$root_resolved = self::resolve_realpath( $root_norm );
						if ( null === $root_resolved ) {
							$anchor_memo[ $memo_key ] = null;
						} else {
							$anchor_memo[ $memo_key ] = array(
								rtrim( $root_resolved, '/' ) . '/' . $domain_seg,
								$root_resolved,
							);
						}
					} else {
						$anchor_memo[ $memo_key ] = array(
							$anchor,
							self::resolve_realpath( $root_norm ),
						);
					}
				}
				$memo = $anchor_memo[ $memo_key ];
				if ( null === $memo ) {
					// Nothing on disk resolves yet: no symlink to follow,
					// so the lexical verdict stands.
					return true;
				}
				list( $anchor, $root_resolved ) = $memo;
				if ( null === $root_resolved ) {
					return true;
				}
				// The anchor itself must live under the resolved root: a
				// symlinked domain directory pointing outside fails closed.
				$anchor_dir = rtrim( $anchor, '/' ) . '/';
				$root_dir   = rtrim( $root_resolved, '/' ) . '/';
				if ( 0 !== strpos( $anchor_dir, $root_dir ) ) {
					return false;
				}
				// Resolved target: the full normalized target first, so a
				// leaf symlink (e.g. `{root}/{domain}/evil-dir -> /etc`
				// passed as a directory purge target, or a trailing-slash
				// directory target whose dirname() would otherwise drop to
				// the parent) resolves and fails closed. Falls back to the
				// target directory for not-yet-existing file leaves.
				$target_resolved = self::resolve_realpath( rtrim( $target_norm, '/' ) );
				if ( null === $target_resolved && function_exists( 'dirname' ) ) {
					$target_resolved = self::resolve_realpath( dirname( $target_norm ) );
				}
				if ( null === $target_resolved ) {
					return true;
				}
				$target_dir_slash = rtrim( $target_resolved, '/' ) . '/';
				return 0 === strpos( $target_dir_slash, $anchor_dir );
			} catch ( \Throwable $e ) {
				unset( $e );
				// Fail closed for security (skip the write, serve dynamic)
				// while staying non-fatal for availability.
				return false;
			}
		}

		/**
		 * Resolve a path via realpath(), keeping the lexical remainder.
		 *
		 * `realpath()` returns false for paths that do not (fully) exist
		 * yet — e.g. a brand-new page directory. This helper walks up to
		 * the nearest existing ancestor, resolves it, and re-appends the
		 * non-existing remainder lexically, so symlinked deploy roots keep
		 * resolving consistently while symlink escapes inside the tree are
		 * still exposed. Returns null when nothing on disk resolves (or
		 * `realpath()` is unavailable), in which case callers fall back to
		 * the lexical verdict. The walk is bounded (64 levels) so
		 * `dirname( '/' ) === '/'` cannot loop forever.
		 *
		 * @param string $lexical_path Normalized absolute path to resolve.
		 * @return string|null Resolved absolute path, or null when unresolvable.
		 * @since 2.2.0
		 */
		public static function resolve_realpath( string $lexical_path ): ?string {
			if ( '' === $lexical_path || ! function_exists( 'realpath' ) || ! function_exists( 'dirname' ) ) {
				return null;
			}
			try {
				$candidate = $lexical_path;
				$remainder = array();
				$depth     = 0;
				while ( '' !== $candidate && $depth < 64 ) {
					++$depth;
					$resolved = realpath( $candidate );
					if ( false !== $resolved && is_string( $resolved ) && '' !== $resolved ) {
						if ( function_exists( 'wp_normalize_path' ) ) {
							$resolved = wp_normalize_path( $resolved );
						} else {
							$resolved = str_replace( '\\', '/', $resolved );
						}
						if ( array() !== $remainder ) {
							$resolved = rtrim( $resolved, '/' ) . '/' . implode( '/', $remainder );
						}
						return $resolved;
					}
					$parent = dirname( $candidate );
					if ( $parent === $candidate ) {
						return null;
					}
					$segment = substr( $candidate, strlen( $parent ) );
					$segment = ltrim( $segment, '/' );
					if ( '' !== $segment ) {
						array_unshift( $remainder, $segment );
					}
					$candidate = $parent;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
			return null;
		}

		/**
		 * Single-call validator for absolute cache write targets.
		 *
		 * Combines traversal-payload rejection (null bytes, `..` segments)
		 * with lexical ({@see is_cache_path_contained()}) and symlink-aware
		 * ({@see is_realpath_contained()}) containment. The path is absolute
		 * by contract, so drive/UNC/protocol-relative *inputs* are refused
		 * earlier at the sanitize layer; here any target failing containment
		 * fails closed. Callers skip the write and serve dynamically
		 * uncached on false.
		 *
		 * @param string $cache_root_dir Absolute cache root directory.
		 * @param string $domain Canonical domain directory segment.
		 * @param string $path Absolute file path to validate.
		 * @return bool True when the target may be written.
		 * @since 2.2.0
		 */
		public static function validate_cache_write_path( string $cache_root_dir, string $domain, string $path ): bool {
			if ( '' === $cache_root_dir || '' === $domain || '' === $path ) {
				return false;
			}
			if ( false !== strpos( $path, "\0" ) || false !== strpos( $path, '..' ) ) {
				return false;
			}
			if ( ! self::is_cache_path_contained( $cache_root_dir, $domain, $path ) ) {
				return false;
			}
			return self::is_realpath_contained( $cache_root_dir, $domain, $path );
		}

		/**
		 * Whether an .htaccess target may be written by the plugin.
		 *
		 * Isolation guard keeping htaccess writes out of reach of
		 * cache-path resolution: the basename must be exactly `.htaccess`
		 * and the target must never sit inside the static cache tree
		 * (`{WP_CONTENT_DIR}/cache/wppo/`), lexically or via symlink
		 * resolution (a symlinked `.htaccess` or a symlinked parent inside
		 * the resolved write path resolving into the cache tree fails
		 * closed — same CVE class as the cache write path). Null bytes and
		 * `..` segments fail closed. Fail-open uncached semantics belong to
		 * the caller: false means "do not touch the filesystem".
		 *
		 * @param string $htaccess_file Absolute .htaccess path candidate.
		 * @return bool True when the target may be written.
		 * @since 2.2.0
		 */
		public static function is_htaccess_path_allowed( string $htaccess_file ): bool {
			if ( '' === $htaccess_file ) {
				return false;
			}
			if ( false !== strpos( $htaccess_file, "\0" ) || false !== strpos( $htaccess_file, '..' ) ) {
				return false;
			}
			try {
				$base = basename( $htaccess_file );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			if ( '.htaccess' !== $base ) {
				return false;
			}
			try {
				if ( function_exists( 'wp_normalize_path' ) ) {
					$norm = wp_normalize_path( $htaccess_file );
				} else {
					$norm = str_replace( '\\', '/', $htaccess_file );
				}
				$cache_root = null;
				if ( defined( 'WP_CONTENT_DIR' ) ) {
					if ( function_exists( 'wp_normalize_path' ) ) {
						$cache_root = rtrim( wp_normalize_path( (string) WP_CONTENT_DIR ), '/' ) . '/cache/wppo/';
					} else {
						$cache_root = rtrim( str_replace( '\\', '/', (string) WP_CONTENT_DIR ), '/' ) . '/cache/wppo/';
					}
					if ( 0 === strpos( $norm, $cache_root ) ) {
						return false;
					}
				} elseif ( false !== strpos( $norm, '/cache/wppo/' ) ) {
					return false;
				}
				// Symlink-aware isolation: the resolved parent directory and
				// the resolved leaf itself must not land inside the resolved
				// cache root (lexical check above cannot see through
				// symlinks). Unresolvable paths keep the lexical verdict.
				if ( null !== $cache_root && function_exists( 'dirname' ) ) {
					$cache_resolved = self::resolve_realpath( rtrim( $cache_root, '/' ) );
					if ( null !== $cache_resolved ) {
						$cache_dir       = rtrim( $cache_resolved, '/' ) . '/';
						$candidates      = array();
						$parent_resolved = self::resolve_realpath( dirname( $norm ) );
						if ( null !== $parent_resolved ) {
							$candidates[] = rtrim( $parent_resolved, '/' ) . '/';
						}
						$leaf_resolved = self::resolve_realpath( rtrim( $norm, '/' ) );
						if ( null !== $leaf_resolved ) {
							$candidates[] = rtrim( $leaf_resolved, '/' ) . '/';
							$candidates[] = rtrim( dirname( $leaf_resolved ), '/' ) . '/';
						}
						foreach ( $candidates as $candidate ) {
							if ( 0 === strpos( $candidate, $cache_dir ) ) {
								return false;
							}
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return true;
		}

		/**
		 * Build a contained absolute cache file path from its parts.
		 *
		 * Single auditable containment point for the static HTML cache and
		 * the per-page used-CSS surface: normalizes the host via
		 * {@see normalize_cache_host()}, sanitizes the path via
		 * {@see sanitize_cache_url_path()} (single-decode semantics —
		 * `%252e` stays literal, single-encoded `%2e%2e` / `%00` decode once
		 * and are rejected), refuses absolute-form inputs (scheme `://`,
		 * protocol-relative `//host`, drive `C:`, UNC `\\`, detected on the
		 * authority part before `?`/`#` so query strings carrying URLs never
		 * false-positive) and foreign-host absolute URLs, allowlists the file
		 * name, then enforces dual-prefix containment before returning.
		 * Returns an empty string on any failure; callers fail open (serve
		 * dynamic/uncached, log a probe).
		 *
		 * Pure static helper: no I/O, no settings reads. Multisite-safe: the
		 * per-site canonical domain is passed explicitly, so a secondary
		 * site can never address the primary site tree.
		 *
		 * @param string      $cache_root_dir Absolute cache root directory.
		 * @param string      $domain Canonical domain directory segment.
		 * @param string|null $url_path_or_url Raw URL path or URL.
		 * @param string      $filename File name (e.g. `index.html`).
		 * @return string Contained absolute path, or '' when refused.
		 * @since 2.0.0
		 */
		public static function sanitize_cache_path( string $cache_root_dir, string $domain, $url_path_or_url, string $filename ): string {
			if ( '' === $cache_root_dir || '' === $filename ) {
				return '';
			}
			$domain = self::normalize_cache_host( $domain );
			if ( '' === $domain ) {
				return '';
			}
			if ( false !== strpos( $filename, '/' ) || false !== strpos( $filename, '\\' ) || false !== strpos( $filename, "\0" ) || false !== strpos( $filename, '..' ) ) {
				return '';
			}
			if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9._-]*$/i', $filename ) || strlen( $filename ) > 64 ) {
				return '';
			}
			$raw_input = (string) $url_path_or_url;
			$trimmed   = ltrim( $raw_input );
			// Inspect only the authority part (before `?`/`#`), matching the
			// Cache constructor: a benign relative path whose query/fragment
			// carries a URL (e.g. `/search?redirect=https://other`) must not
			// be misread as an absolute-form target. The split uses strcspn()
			// (no process-global strtok() state).
			$authority = substr( $trimmed, 0, strcspn( $trimmed, '?#' ) );
			// Scheme-anchored absolute-form test (parity with
			// sanitize_cache_url_path() / Cache::__construct): a benign
			// relative path containing `://` in a segment (e.g.
			// `/foo/a://b`) must not misclassify as absolute-form.
			$authority_trimmed = ltrim( $authority );
			if ( '' !== $authority_trimmed && ( (bool) preg_match( '#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $authority_trimmed ) || 0 === strpos( $authority_trimmed, '//' ) || 0 === strpos( $authority_trimmed, '\\\\' ) || (bool) preg_match( '#^[a-zA-Z]:#', $authority_trimmed ) ) ) {
				// Absolute-form target: only same-host absolute URLs may map
				// to a path; drive/UNC/protocol-relative/foreign-host inputs
				// are refused outright.
				$host_raw = null;
				if ( function_exists( 'wp_parse_url' ) ) {
					$host_raw = wp_parse_url( $raw_input, PHP_URL_HOST );
				} else {
					$host_raw = parse_url( $raw_input, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( is_string( $host_raw ) && '' !== $host_raw ) {
					if ( self::normalize_cache_host( $host_raw ) !== $domain ) {
						return '';
					}
				} else {
					return '';
				}
			}
			$path = self::sanitize_cache_url_path( $raw_input, $domain );
			if ( '' === $path ) {
				$component = null;
				if ( function_exists( 'wp_parse_url' ) ) {
					$component = wp_parse_url( $raw_input, PHP_URL_PATH );
				} else {
					$component = parse_url( $raw_input, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Fallback for very old WP.
				}
				if ( null === $component || false === $component ) {
					$component = $raw_input;
				}
				if ( '' !== trim( (string) $component, " \t\n\r\0\x0B/" ) ) {
					return '';
				}
			}
			if ( function_exists( 'wp_normalize_path' ) ) {
				$root = wp_normalize_path( $cache_root_dir );
			} else {
				$root = str_replace( '\\', '/', $cache_root_dir );
			}
			$root     = rtrim( $root, '/' );
			$resolved = '' === $path ? "{$root}/{$domain}/{$filename}" : "{$root}/{$domain}/{$path}/{$filename}";
			if ( ! self::is_cache_path_contained( $root, $domain, $resolved ) ) {
				return '';
			}
			return $resolved;
		}

		/**
		 * Build a unique sibling tmp path for atomic writes.
		 *
		 * Wide rand range plus PID/uniqid segments so concurrent writers
		 * never share a tmp name. Uses `wp_rand()` when available with an
		 * `mt_rand()` fallback for very old WP.
		 *
		 * @param string $final_path Final file path the tmp sits beside.
		 * @return string Tmp sibling path ('' when input is empty).
		 * @since 2.0.0
		 */
		public static function atomic_tmp_path( string $final_path ): string {
			if ( '' === $final_path ) {
				return '';
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback only when wp_rand() is unavailable; uniqueness is all that is needed for the tmp suffix.
			$rand_suffix = mt_rand( 1000000, 9999999 );
			if ( function_exists( 'wp_rand' ) ) {
				try {
					$rand_suffix = wp_rand( 1000000, 9999999 );
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}
			$pid_part  = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
			$uniq_part = substr( md5( uniqid( (string) microtime( true ), true ) ), 0, 8 );
			return $final_path . '.tmp.' . $rand_suffix . '-' . $pid_part . '-' . $uniq_part;
		}

		/**
		 * Atomically write contents via tmp-file + rename.
		 *
		 * Writes to a unique sibling tmp file in the same directory and then
		 * moves it over the final path, so interrupted writes never leave
		 * partial output behind. A failed move cleans up the tmp file and
		 * reports failure — there is intentionally no non-atomic direct-write
		 * fallback, so readers can never observe a torn file.
		 *
		 * @param mixed  $fs Filesystem object exposing put_contents()/move()/delete().
		 * @param string $path Final file path.
		 * @param string $contents File contents.
		 * @return bool True on success.
		 * @since 2.0.0
		 */
		public static function atomic_file_put_contents( $fs, string $path, string $contents ): bool {
			if ( '' === $path || ! is_object( $fs ) || ! method_exists( $fs, 'put_contents' ) || ! method_exists( $fs, 'move' ) || ! method_exists( $fs, 'delete' ) ) {
				return false;
			}
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			$tmp   = self::atomic_tmp_path( $path );
			if ( '' === $tmp ) {
				return false;
			}
			try {
				if ( ! $fs->put_contents( $tmp, $contents, $chmod ) ) {
					$fs->delete( $tmp );
					return false;
				}
				$moved = (bool) $fs->move( $tmp, $path, true );
				if ( ! $moved ) {
					$fs->delete( $tmp );
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					$fs->delete( $tmp );
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort tmp cleanup must never throw.
				}
				return false;
			}
			return true;
		}

		/**
		 * Check that PHP code parses without a syntax error.
		 *
		 * Layered, fully guarded so it never fatals on any supported
		 * WP/PHP combination:
		 * - Layer 1: `PhpToken::tokenize()` when the class exists (throws
		 *   `ParseError` on broken syntax).
		 * - Layer 2: optional `php -l` against a sibling tmp file, only when
		 *   an exec function exists, is not disabled, and `PHP_BINARY` is
		 *   defined. Never lints the live file.
		 * - Layer 3 (always): the code must open with `<?php`.
		 *
		 * @param string $code PHP source to check.
		 * @param string $tmp_file_for_lint Optional tmp file holding $code for `php -l`.
		 * @return bool True when the code looks parseable.
		 * @since 2.0.0
		 */
		public static function verify_php_syntax( string $code, string $tmp_file_for_lint = '' ): bool {
			if ( '' === $code ) {
				return false;
			}
			$stripped = ltrim( $code );
			$stripped = preg_replace( "/^\xEF\xBB\xBF/", '', $stripped );
			if ( ! is_string( $stripped ) || 0 !== strpos( $stripped, '<?php' ) ) {
				return false;
			}
			if ( class_exists( 'PhpToken' ) ) {
				try {
					$tokens = \PhpToken::tokenize( $code );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
				// PhpToken::tokenize() is lenient (it lexes, not parses), so a
				// truncated file with unbalanced brackets would still lex fine.
				// Count structural brackets outside strings/comments/HTML to
				// catch torn writes that lexing alone would miss.
				if ( ! self::php_brackets_balanced( $tokens ) ) {
					return false;
				}
			} elseif ( function_exists( 'token_get_all' ) ) {
				try {
					$legacy = token_get_all( $code );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
				if ( ! self::php_brackets_balanced( $legacy ) ) {
					return false;
				}
			}
			// Optional `php -l` against the on-disk tmp file only (never the
			// live file). Skipped when the tmp path is not a real readable
			// file — e.g. under WP_Filesystem transports or unit-test mocks.
			if ( '' !== $tmp_file_for_lint && function_exists( 'escapeshellarg' ) && defined( 'PHP_BINARY' ) && '' !== (string) constant( 'PHP_BINARY' ) ) {
				$disabled = '';
				if ( function_exists( 'ini_get' ) ) {
					$disabled = (string) ini_get( 'disable_functions' );
				}
				$disabled_list = array_map( 'trim', explode( ',', strtolower( (string) $disabled ) ) );
				$exec_disabled = in_array( 'exec', $disabled_list, true );
				if ( function_exists( 'exec' ) && ! $exec_disabled && is_readable( $tmp_file_for_lint ) ) {
					try {
						$binary = (string) constant( 'PHP_BINARY' );
						// Audit #1329: both parts escapeshellarg()'d; never
						// interpolate unescaped variables here (RCE risk).
						$cmd    = escapeshellarg( $binary ) . ' -l ' . escapeshellarg( $tmp_file_for_lint ) . ' 2>&1';
						$output = array();
						$rc     = 1;
						// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- Guarded php -l syntax check on the tmp file only; never the live file.
						exec( $cmd, $output, $rc );
						if ( 0 !== (int) $rc ) {
							return false;
						}
						$joined = implode( "\n", $output );
						if ( false === stripos( $joined, 'No syntax errors' ) ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
			}
			return true;
		}

		/**
		 * Check that structural brackets are balanced in a token stream.
		 *
		 * Counts `(`, `)`, `{`, `}`, `[`, `]` from single-char tokens only, so
		 * brackets inside strings, comments, and inline HTML are ignored. A
		 * torn write (e.g. a file cut mid-statement) leaves the counts
		 * unbalanced and is rejected.
		 *
		 * @param array $tokens Token stream from `PhpToken::tokenize()`.
		 * @return bool True when every bracket type is balanced and ordered.
		 * @since 2.0.0
		 */
		private static function php_brackets_balanced( array $tokens ): bool {
			$pairs = array(
				'(' => ')',
				'{' => '}',
				'[' => ']',
			);
			$stack = array();
			foreach ( $tokens as $token ) {
				$id = null;
				if ( $token instanceof \PhpToken ) {
					$id = $token->id;
				} elseif ( is_array( $token ) && isset( $token[0] ) ) {
					$id = $token[0];
				}
				if ( null !== $id && ( ( defined( 'T_CURLY_OPEN' ) && T_CURLY_OPEN === $id ) || ( defined( 'T_DOLLAR_OPEN_CURLY_BRACES' ) && T_DOLLAR_OPEN_CURLY_BRACES === $id ) ) ) {
					$stack[] = '{';
					continue;
				}
				$text = $token instanceof \PhpToken ? $token->text : ( is_string( $token ) ? $token : '' );
				if ( 1 !== strlen( $text ) || ( ! isset( $pairs[ $text ] ) && ! in_array( $text, $pairs, true ) ) ) {
					continue;
				}
				if ( isset( $pairs[ $text ] ) ) {
					$stack[] = $text;
					continue;
				}
				$last = array_pop( $stack );
				if ( null === $last || ! isset( $pairs[ $last ] ) || $pairs[ $last ] !== $text ) {
					return false;
				}
			}
			return array() === $stack;
		}

		/**
		 * Atomically write PHP source with syntax verification and rollback.
		 *
		 * Writes `$contents` to a unique sibling tmp file (same directory, so
		 * rename is atomic), verifies the tmp (byte-identical re-read, caller
		 * `$expect` assertion, PHP-parse check), keeps one best-effort backup
		 * generation (`$path . '.wppo-bak'`), renames over the live file, then
		 * re-reads and re-verifies the live file. On post-rename verification
		 * failure the backup (or the in-memory original) is restored so the
		 * live file is never left truncated. On any tmp/rename failure the tmp
		 * is cleaned and the live file is untouched.
		 *
		 * Returns null when the filesystem transport cannot support atomic
		 * writes (missing methods) so callers can fall back to the legacy
		 * direct-write path. Multisite-safe: only touches the single
		 * `wp-config.php` path passed in; no per-site option writes.
		 *
		 * @param mixed         $fs Filesystem object exposing exists()/get_contents()/put_contents()/move()/copy()/delete().
		 * @param string        $path Final file path.
		 * @param string        $contents New file contents.
		 * @param callable|null $expect Optional assertion receiving contents, returning bool.
		 * @return bool|null True on verified success, false on verified failure, null when unsupported.
		 * @since 2.0.0
		 */
		public static function atomic_write_php_verified( $fs, string $path, string $contents, $expect = null ): ?bool {
			$required = array( 'exists', 'get_contents', 'put_contents', 'move', 'copy', 'delete' );
			foreach ( $required as $method ) {
				if ( ! is_object( $fs ) || ! method_exists( $fs, $method ) ) {
					return null;
				}
			}
			if ( '' === $path || '' === $contents ) {
				return false;
			}
			$chmod = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			try {
				$original = '';
				if ( $fs->exists( $path ) ) {
					$original = $fs->get_contents( $path );
					if ( ! is_string( $original ) ) {
						return false;
					}
				}
				$tmp = self::atomic_tmp_path( $path );
				if ( '' === $tmp ) {
					return false;
				}
				if ( ! $fs->put_contents( $tmp, $contents, $chmod ) ) {
					$fs->delete( $tmp );
					return false;
				}
				$tmp_read = $fs->get_contents( $tmp );
				if ( ! is_string( $tmp_read ) || $tmp_read !== $contents ) {
					$fs->delete( $tmp );
					return false;
				}
				if ( null !== $expect && ! call_user_func( $expect, $tmp_read ) ) {
					$fs->delete( $tmp );
					return false;
				}
				if ( ! self::verify_php_syntax( $tmp_read, $tmp ) ) {
					$fs->delete( $tmp );
					return false;
				}
				if ( '' !== $original ) {
					// Best-effort single backup generation; in-memory
					// $original remains the authoritative restore source.
					$fs->copy( $path, $path . '.wppo-bak', true );
				}
				if ( ! $fs->move( $tmp, $path, true ) ) {
					$fs->delete( $tmp );
					self::delete_php_backup( $fs, $path );
					return false;
				}
				$written = $fs->get_contents( $path );
				if ( ! is_string( $written ) || $written !== $contents ) {
					self::restore_php_backup( $fs, $path, $original, $chmod );
					self::delete_php_backup( $fs, $path );
					$fs->delete( $tmp );
					return false;
				}
				if ( null !== $expect && ! call_user_func( $expect, $written ) ) {
					self::restore_php_backup( $fs, $path, $original, $chmod );
					self::delete_php_backup( $fs, $path );
					$fs->delete( $tmp );
					return false;
				}
				if ( ! self::verify_php_syntax( $written ) ) {
					self::restore_php_backup( $fs, $path, $original, $chmod );
					self::delete_php_backup( $fs, $path );
					$fs->delete( $tmp );
					return false;
				}
				$fs->delete( $tmp );
				// The backup holds full wp-config.php secrets beside the live
				// file in the web root, so remove it once the rename verified.
				// Best-effort: success stands even if the delete fails.
				try {
					$fs->delete( $path . '.wppo-bak' );
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort backup cleanup must never throw.
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					if ( isset( $original ) && is_string( $original ) && '' !== $original ) {
						self::restore_php_backup( $fs, $path, $original, $chmod );
					}
				} catch ( \Throwable $ignored_restore ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort restore must never throw.
				}
				try {
					if ( isset( $tmp ) && is_string( $tmp ) && '' !== $tmp && $fs->exists( $tmp ) ) {
						$fs->delete( $tmp );
					}
				} catch ( \Throwable $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- Best-effort tmp cleanup must never throw.
				}
				self::delete_php_backup( $fs, $path );
				return false;
			}
		}

		/**
		 * Restore a PHP file from its in-memory original or `.wppo-bak` backup.
		 *
		 * Prefers the in-memory original (the known-good read from this
		 * invocation); falls back to the on-disk backup copy, which may be
		 * stale from a previous run or concurrent writer. Best-effort: never throws.
		 *
		 * @param mixed  $fs Filesystem object.
		 * @param string $path Final file path.
		 * @param string $original In-memory original contents ('' when none).
		 * @param int    $chmod File mode for a direct-write restore.
		 * @return bool True when a restore write/copy was issued.
		 * @since 2.0.0
		 */
		private static function restore_php_backup( $fs, string $path, string $original, int $chmod ): bool {
			try {
				if ( '' !== $original ) {
					if ( $fs->put_contents( $path, $original, $chmod ) ) {
						// In-memory restore won: the on-disk backup is stale
						// secrets beside the live file — remove it best-effort.
						self::delete_php_backup( $fs, $path );
						return true;
					}
				}
				if ( $fs->exists( $path . '.wppo-bak' ) ) {
					if ( $fs->copy( $path . '.wppo-bak', $path, true ) ) {
						// Fallback restore won: the backup copy is now
						// redundant — remove it so secrets never linger.
						self::delete_php_backup( $fs, $path );
						return true;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Best-effort deletion of the `.wppo-bak` backup beside a PHP file.
		 *
		 * The backup can hold full wp-config.php secrets (DB credentials +
		 * salts) in a web-readable location, so every failure/restore path in
		 * atomic_write_php_verified() must call this. Never throws.
		 *
		 * @param mixed  $fs Filesystem object.
		 * @param string $path Final file path (backup is `$path.wppo-bak`).
		 * @return void
		 * @since 2.2.0
		 */
		private static function delete_php_backup( $fs, string $path ): void {
			try {
				$fs->delete( $path . '.wppo-bak' );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Stable content hash of CSS source (issue #1038 / audit #7).
		 *
		 * Pure local string hash — never fetches remotely. Used by both the
		 * critical-CSS and used-CSS pipelines to detect stylesheet edits that
		 * preserve mtime (deploy sync, minify rebuild in the same second) so
		 * stale derived CSS regenerates. SHA-256 is stable across installs and
		 * salt rotations (unlike wp_hash), so stored checksums and `.sha256`
		 * sidecars stay comparable. Empty input returns '' so callers can
		 * treat "no source" as "no signal".
		 *
		 * @param string $css CSS content.
		 * @return string SHA-256 checksum, or '' for empty input.
		 * @since 2.0.0
		 */
		public static function compute_css_checksum( string $css ): string {
			if ( '' === $css ) {
				return '';
			}
			return hash( 'sha256', $css );
		}

		/**
		 * Option name storing the per-site regeneration-callback HMAC secret.
		 *
		 * Written once (lazily, autoload off) and read on every signed
		 * enqueue/verify; deleted on uninstall via {@see UNINSTALL_OPTIONS}.
		 * Multisite-safe: accessed through {@see option_key()} so each blog
		 * signs with its own secret. Never logged.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const CALLBACK_SECRET_OPTION = 'wppo_callback_secret';

		/**
		 * Maximum derived-CSS payload accepted for storage (bytes).
		 *
		 * Fail-open bound for {@see css_within_storage_bounds()}: outputs
		 * above this size are refused and callers serve unoptimized markup
		 * instead of persisting unbounded blobs.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const CSS_STORAGE_MAX_BYTES = 1048576;

		/**
		 * Sanitize derived CSS for safe storage inside <style> or a .css file.
		 *
		 * Shared worker for the used-CSS ingest path (the critical-CSS
		 * pipeline keeps its own memoized/filter-aware variant): decodes
		 * numeric/hex entities first so encoded payloads cannot smuggle
		 * `<` past the encoder, then neutralizes the `</style>` raw-text
		 * terminator, `<script`, HTML comments, CSS expression vectors
		 * (`expression()`, `javascript:`/`vbscript:` URLs, the `behavior` /
		 * `behaviour` property in property position only, `-moz-binding`),
		 * whitespace-prefixed `on*=` HTML-attribute shapes (which cannot
		 * execute inside `<style>` without a breakout, but are never valid
		 * CSS outside an attribute selector so breaking them is safe), and
		 * encodes any remaining `<` as the equivalent CSS escape so stored
		 * CSS can never break out of the style element. Benign lookalikes
		 * (`scroll-behavior:`, `.behavior-badge`) are preserved. Fail-open:
		 * a sanitizer error drops the block (returns '') so output degrades
		 * to unoptimized markup, never script execution.
		 *
		 * @param string $css Raw CSS content.
		 * @return string Sanitized CSS, or '' when empty or on failure.
		 * @since NEXT
		 */
		public static function sanitize_css_for_storage( string $css ): string {
			if ( '' === $css ) {
				return '';
			}
			try {
				if ( function_exists( 'html_entity_decode' ) ) {
					$css = html_entity_decode( $css, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
				}
				$css = (string) preg_replace_callback(
					'/&#\d+;?|&#x[0-9a-f]+;?/i',
					static function ( array $matches ): string {
						$entity = $matches[0];
						if ( function_exists( 'html_entity_decode' ) ) {
							return html_entity_decode( $entity, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
						}
						return '';
					},
					$css
				);
				$css = str_ireplace( '</style', '<\/style', $css );
				$css = str_ireplace( '<script', '<\script', $css );
				$css = str_ireplace( '<!--', '<\!--', $css );
				$css = str_ireplace( '-->', '--\>', $css );
				$css = (string) preg_replace_callback(
					'/expression\s*\(|javascript\s*:|vbscript\s*:/i',
					static function ( array $matches ): string {
						$token = $matches[0];
						return substr( $token, 0, -1 ) . '\\' . substr( $token, -1 );
					},
					$css
				);
				$css = (string) preg_replace_callback(
					'/(?<![-\w])behaviou?r(?=\s*:)/i',
					static function (): string {
						return 'behavio\\r';
					},
					$css
				);
				$css = str_ireplace( '-moz-binding', '-moz-bindin\g', $css );
				$css = (string) preg_replace_callback(
					'/(\s)on([a-z]+\s*=)/i',
					static function ( array $matches ): string {
						return $matches[1] . 'o\\6e ' . $matches[2];
					},
					$css
				);
				$css = str_replace( '<', '\3c ', $css );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
			return $css;
		}

		/**
		 * Whether derived CSS is within the size/charset storage bounds.
		 *
		 * Guards the store paths of both CSS pipelines (issue #1347):
		 * payloads above $max_bytes or with undecodable UTF-8 are refused
		 * so unbounded or mojibake blobs never reach the cache. Fail-open
		 * to true when the verdict itself is unverifiable (no mbstring and
		 * no strlen?) — strlen() is always available, so only the charset
		 * leg is skipped without mbstring.
		 *
		 * @param string $css       CSS content.
		 * @param int    $max_bytes Maximum accepted size in bytes.
		 * @return bool True when the payload may be stored.
		 * @since NEXT
		 */
		public static function css_within_storage_bounds( string $css, int $max_bytes = self::CSS_STORAGE_MAX_BYTES ): bool {
			try {
				if ( '' === $css ) {
					return false;
				}
				if ( strlen( $css ) > $max_bytes ) {
					return false;
				}
				if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $css, 'UTF-8' ) ) {
					return false;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return true;
		}

		/**
		 * Per-request memo for the callback HMAC secret (null = unresolved).
		 *
		 * @since NEXT
		 * @var string|null
		 */
		private static ?string $callback_secret_memo = null;

		/**
		 * Reset the per-request callback-secret memo (tests, switch_blog).
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function reset_callback_secret_memo(): void {
			self::$callback_secret_memo = null;
		}

		/**
		 * Read (or lazily create) the per-site callback HMAC secret.
		 *
		 * Stored in its own non-autoloaded option (see
		 * {@see CALLBACK_SECRET_OPTION}) through {@see option_key()} so
		 * multisite blogs never share a signing key. Created once via
		 * wp_generate_password() (random_bytes() fallback) and never
		 * logged. Fail-open: returns '' when the secret cannot be read or
		 * created, in which case signing/verification degrade to the
		 * legacy capability+nonce-only path.
		 *
		 * @return string Site secret, or '' when unavailable.
		 * @since NEXT
		 */
		public static function get_callback_secret(): string {
			if ( is_string( self::$callback_secret_memo ) ) {
				return self::$callback_secret_memo;
			}
			try {
				if ( ! function_exists( 'get_option' ) ) {
					return '';
				}
				$key    = self::option_key( self::CALLBACK_SECRET_OPTION );
				$secret = get_option( $key, false );
				if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
					self::$callback_secret_memo = $secret;
					return self::$callback_secret_memo;
				}
				if ( function_exists( 'wp_generate_password' ) ) {
					$secret = wp_generate_password( 64, true, true );
				} elseif ( function_exists( 'random_bytes' ) ) {
					$secret = bin2hex( random_bytes( 32 ) );
				} else {
					return '';
				}
				if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
					return '';
				}
				if ( function_exists( 'add_option' ) ) {
					add_option( $key, $secret, '', false );
				} elseif ( function_exists( 'update_option' ) ) {
					update_option( $key, $secret, false );
				} else {
					return '';
				}
				self::$callback_secret_memo = $secret;
				return self::$callback_secret_memo;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Sign a regeneration-callback payload with the site secret.
		 *
		 * Canonicalizes the payload (key-sorted JSON) and HMACs it so the
		 * scheduler-execution side can prove the job args were built by
		 * this site. Fail-open: returns '' when signing is unavailable, in
		 * which case callers enqueue unsigned and handlers take the legacy
		 * capability+nonce-only path.
		 *
		 * @param array $payload Job payload (scalar values).
		 * @return string Hex signature, or '' when unavailable.
		 * @since NEXT
		 */
		public static function sign_callback_payload( array $payload ): string {
			try {
				$secret = self::get_callback_secret();
				if ( '' === $secret || ! function_exists( 'hash_hmac' ) ) {
					return '';
				}
				ksort( $payload );
				if ( function_exists( 'wp_json_encode' ) ) {
					$canonical = wp_json_encode( $payload );
				} else {
					$canonical = json_encode( $payload ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback when wp_json_encode() is unavailable (unit-test doubles).
				}
				if ( ! is_string( $canonical ) || '' === $canonical ) {
					return '';
				}
				return hash_hmac( 'sha256', $canonical, $secret );
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Verify a regeneration-callback payload signature.
		 *
		 * Fail-closed on mismatch (returns false) so forged scheduler args
		 * never execute; fail-open only in the sense that callers take the
		 * legacy path when no signature scheme is available at all (see
		 * sign_callback_payload()). Timing-safe comparison when
		 * hash_equals() exists.
		 *
		 * @param array  $payload   Job payload as signed.
		 * @param string $signature Hex signature to check.
		 * @return bool True when the signature is valid.
		 * @since NEXT
		 */
		public static function verify_callback_signature( array $payload, string $signature ): bool {
			try {
				if ( '' === $signature ) {
					return false;
				}
				$expected = self::sign_callback_payload( $payload );
				if ( '' === $expected ) {
					return false;
				}
				if ( function_exists( 'hash_equals' ) ) {
					return hash_equals( $expected, $signature );
				}
				return $expected === $signature;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether core supports the native `strategy` script args (WP 6.3+).
		 *
		 * Single shared home for the version gate previously duplicated in
		 * RUM::supports_script_strategy() and LiteSpeed_ESI::supports_script_strategy()
		 * (audit follow-up): both now delegate here so floor bumps cannot drift.
		 *
		 * @since 2.2.0
		 * @return bool True on WP 6.3+.
		 */
		public static function supports_script_strategy(): bool {
			if ( isset( $GLOBALS['wp_version'] ) && is_string( $GLOBALS['wp_version'] ) && '' !== $GLOBALS['wp_version'] ) {
				$wp_version = $GLOBALS['wp_version'];
			} elseif ( function_exists( 'get_bloginfo' ) ) {
				$wp_version = (string) get_bloginfo( 'version' );
			} else {
				$wp_version = '';
			}
			return version_compare( $wp_version, '6.3-alpha', '>=' );
		}

		/**
		 * Qualify a transient key with the current blog ID on multisite.
		 *
		 * Prevents transient key collisions when a shared object cache backend
		 * (Redis, Memcached) is present. On single-site installs the key is
		 * returned unchanged. For option names use {@see option_key()} instead.
		 *
		 * @param string $key The bare transient key.
		 * @return string Blog-ID-prefixed key on multisite, or the original key.
		 * @since 2.0.0
		 */
		public static function transient_key( string $key ): string {
			if ( ! function_exists( 'is_multisite' ) ) {
				return $key;
			}
			try {
				return is_multisite() ? (string) get_current_blog_id() . '_' . $key : $key;
			} catch ( \Throwable $e ) {
				return $key;
			}
		}

		/**
		 * Qualify an option name with the current blog ID on multisite.
		 *
		 * Same blog-ID-prefixing semantics as {@see transient_key()}, but for
		 * option names (get_option/update_option/delete_option). Kept as a
		 * separate method so transient and option namespaces stay distinct
		 * and can diverge in the future. On single-site installs the key is
		 * returned unchanged.
		 *
		 * @param string $key The bare option name.
		 * @return string Blog-ID-prefixed option name on multisite, or the original name.
		 * @since 2.0.0
		 */
		public static function option_key( string $key ): string {
			if ( ! function_exists( 'is_multisite' ) ) {
				return $key;
			}
			try {
				return is_multisite() ? (string) get_current_blog_id() . '_' . $key : $key;
			} catch ( \Throwable $e ) {
				return $key;
			}
		}

		/**
		 * Whether the loaded Action Scheduler supports atomic unique actions.
		 *
		 * Action Scheduler 4.x added a `$unique` parameter (after `$group`,
		 * before `$priority`) to `as_enqueue_async_action()`,
		 * `as_schedule_single_action()` and `as_schedule_recurring_action()`
		 * so hook+args+group deduplication happens atomically in the store
		 * instead of via a racy check-then-act. Fail-open: returns false when
		 * the scheduler is absent, older than 4.x, or reflection fails.
		 *
		 * The probe result is memoized in a static so bulk paths
		 * (regenerate_all loops, crawler bursts) pay the reflection and
		 * `ActionScheduler_Versions` lookup once per request instead of once
		 * per job (issue #1310 review). Use
		 * {@see reset_action_scheduler_unique_cache()} to clear the memo in
		 * tests.
		 *
		 * @since 2.2.0
		 * @return bool True when the `$unique` parameter may be passed.
		 */
		public static function supports_action_scheduler_unique(): bool {
			if ( null !== self::$as_unique_support ) {
				return self::$as_unique_support;
			}
			self::$as_unique_support = self::probe_action_scheduler_unique();
			return self::$as_unique_support;
		}

		/**
		 * Memoized probe result for {@see supports_action_scheduler_unique()}.
		 *
		 * Null until the first probe runs; afterwards true/false for the
		 * remainder of the request.
		 *
		 * @var bool|null
		 */
		private static ?bool $as_unique_support = null;

		/**
		 * Memoized per-function `$unique`-parameter arity probes.
		 *
		 * Maps function name => bool so the single/recurring helpers do not
		 * repeat reflection on every call.
		 *
		 * @var array<string, bool>
		 */
		private static array $as_unique_arity = array();

		/**
		 * Reset the memoized Action Scheduler unique-support probes.
		 *
		 * Test-only seam: production code never needs to re-probe within a
		 * request, but unit tests that swap scheduler stubs between cases
		 * must clear the memo to observe the new stub.
		 *
		 * @since 2.2.0
		 * @return void
		 */
		public static function reset_action_scheduler_unique_cache(): void {
			self::$as_unique_support = null;
			self::$as_unique_arity   = array();
		}

		/**
		 * Unmemoized Action Scheduler unique-support probe.
		 *
		 * @since 2.2.0
		 * @return bool True when the `$unique` parameter may be passed.
		 */
		private static function probe_action_scheduler_unique(): bool {
			try {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return false;
				}
				if ( class_exists( 'ActionScheduler_Versions' ) && method_exists( 'ActionScheduler_Versions', 'instance' ) ) {
					try {
						$versions = \ActionScheduler_Versions::instance();
						if ( is_object( $versions ) && method_exists( $versions, 'latest_version' ) ) {
							$latest = $versions->latest_version();
							if ( is_string( $latest ) && '' !== $latest && version_compare( $latest, '4.0', '<' ) ) {
								return false;
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return self::function_has_unique_param( 'as_enqueue_async_action', 4 );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a scheduler function accepts the AS 4.x `$unique` parameter.
		 *
		 * Memoized per function name: repeated reflection is paid once per
		 * request no matter how many jobs a bulk path enqueues.
		 *
		 * @since 2.2.0
		 * @param string $function_name Function name to inspect.
		 * @param int    $min_params    Minimum parameter count that implies `$unique` support.
		 * @return bool True when the function exists and declares at least `$min_params` parameters.
		 */
		private static function function_has_unique_param( string $function_name, int $min_params ): bool {
			if ( array_key_exists( $function_name, self::$as_unique_arity ) ) {
				return self::$as_unique_arity[ $function_name ];
			}
			try {
				if ( ! function_exists( $function_name ) ) {
					self::$as_unique_arity[ $function_name ] = false;
					return false;
				}
				$ref                                     = new \ReflectionFunction( $function_name );
				self::$as_unique_arity[ $function_name ] = $ref->getNumberOfParameters() >= $min_params;
				return self::$as_unique_arity[ $function_name ];
			} catch ( \Throwable $e ) {
				unset( $e );
				self::$as_unique_arity[ $function_name ] = false;
				return false;
			}
		}

		/**
		 * Fail-open "already scheduled" guard shared by the unique helpers.
		 *
		 * Single home for the legacy `as_has_scheduled_action()` check so
		 * future guard fixes land on all three helpers at once (issue #1310
		 * review). A missing or throwing guard degrades to "not scheduled"
		 * rather than failing the enqueue.
		 *
		 * @since 2.2.0
		 * @param string $hook  Action hook.
		 * @param array  $args  Action arguments.
		 * @param string $group Action group.
		 * @return bool True when a matching action is already scheduled.
		 */
		private static function as_already_scheduled( string $hook, array $args, string $group ): bool {
			if ( ! function_exists( 'as_has_scheduled_action' ) ) {
				return false;
			}
			try {
				return (bool) as_has_scheduled_action( $hook, $args, $group );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Fail-open "already scheduled" check across several AS groups.
		 *
		 * The CCSS pipeline schedules on the dedicated `wppo-ccss` group but
		 * must still dedupe against pre-split jobs in the legacy
		 * `performance_optimisation` group; a guard that only probes the
		 * passed group would double-schedule on pre-4.x schedulers when a
		 * legacy-group job lands between checks (issue #1310 review).
		 * Callers pass the extra groups so every legacy fallback below
		 * probes the same disjunction the atomic path dedupes.
		 *
		 * @since 2.2.0
		 * @param string   $hook         Action hook.
		 * @param array    $args         Action arguments.
		 * @param string   $group        Primary action group.
		 * @param string[] $extra_groups Additional groups to probe.
		 * @return bool True when a matching action is already scheduled in any of the groups.
		 */
		private static function as_already_scheduled_in_any_group( string $hook, array $args, string $group, array $extra_groups = array() ): bool {
			if ( self::as_already_scheduled( $hook, $args, $group ) ) {
				return true;
			}
			foreach ( $extra_groups as $extra_group ) {
				if ( ! is_string( $extra_group ) || '' === $extra_group || $extra_group === $group ) {
					continue;
				}
				if ( self::as_already_scheduled( $hook, $args, $extra_group ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Enqueue an async Action Scheduler job with atomic dedup when available.
		 *
		 * Tries `as_enqueue_async_action( $hook, $args, $group, true )` first so
		 * concurrent processes cannot double-insert the same hook+args+group;
		 * falls back to the legacy `as_has_scheduled_action()` guard plus a
		 * 3-argument enqueue on older scheduler versions. Never fatal: any
		 * scheduler API failure returns 0.
		 *
		 * @since 2.2.0
		 * @param string   $hook         Action hook.
		 * @param array    $args         Action arguments.
		 * @param string   $group        Action group.
		 * @param string[] $extra_groups Additional groups probed by the legacy fallback guard (e.g. the CCSS legacy group).
		 * @return int Action ID, or 0 when deduped, unavailable, or on failure.
		 */
		public static function enqueue_unique_async_action( string $hook, array $args = array(), string $group = '', array $extra_groups = array() ): int {
			try {
				if ( ! function_exists( 'as_enqueue_async_action' ) ) {
					return 0;
				}
				if ( self::supports_action_scheduler_unique() ) {
					// Cross-group dedupe (issue #1310 review): the AS 4.x
					// `$unique` flag dedupes per-group only, so a pending
					// job in an extra (legacy) group would not block the
					// insert below. Probe the extra groups up front; the
					// both-group re-check on a 0 return stays as the
					// race backstop.
					foreach ( $extra_groups as $extra_group ) {
						if ( ! is_string( $extra_group ) || '' === $extra_group || $extra_group === $group ) {
							continue;
						}
						if ( self::as_already_scheduled( $hook, $args, $extra_group ) ) {
							return 0;
						}
					}
					try {
						$result = as_enqueue_async_action( $hook, $args, $group, true );
						return (int) $result;
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Legacy guard (fail-open — see as_already_scheduled()).
				if ( self::as_already_scheduled_in_any_group( $hook, $args, $group, $extra_groups ) ) {
					return 0;
				}
				return (int) as_enqueue_async_action( $hook, $args, $group );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Schedule a one-off Action Scheduler job with atomic dedup when available.
		 *
		 * Same fail-open contract as {@see enqueue_unique_async_action()} but
		 * for delayed single actions.
		 *
		 * @since 2.2.0
		 * @param int      $timestamp    When the job will run.
		 * @param string   $hook         Action hook.
		 * @param array    $args         Action arguments.
		 * @param string   $group        Action group.
		 * @param string[] $extra_groups Additional groups probed by the legacy fallback guard (e.g. the CCSS legacy group).
		 * @return int Action ID, or 0 when deduped, unavailable, or on failure.
		 */
		public static function schedule_unique_single_action( int $timestamp, string $hook, array $args = array(), string $group = '', array $extra_groups = array() ): int {
			try {
				if ( ! function_exists( 'as_schedule_single_action' ) ) {
					return 0;
				}
				if ( self::supports_action_scheduler_unique() ) {
					// Cross-group dedupe (issue #1310 review): see
					// enqueue_unique_async_action() — probe extra groups
					// before the per-group atomic insert.
					foreach ( $extra_groups as $extra_group ) {
						if ( ! is_string( $extra_group ) || '' === $extra_group || $extra_group === $group ) {
							continue;
						}
						if ( self::as_already_scheduled( $hook, $args, $extra_group ) ) {
							return 0;
						}
					}
					try {
						if ( self::function_has_unique_param( 'as_schedule_single_action', 5 ) ) {
							return (int) as_schedule_single_action( $timestamp, $hook, $args, $group, true );
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				// Legacy guard (fail-open — see as_already_scheduled()).
				if ( self::as_already_scheduled_in_any_group( $hook, $args, $group, $extra_groups ) ) {
					return 0;
				}
				return (int) as_schedule_single_action( $timestamp, $hook, $args, $group );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Whether the stampede guard is enabled.
		 *
		 * Operator opt-out via `wppo_settings['cache_settings']['stampedeGuard']`
		 * (default true, additive key) or the `wppo_stampede_guard_enabled`
		 * filter. When disabled, {@see get_with_stampede_lock()} degrades to a
		 * plain get-or-rebuild without coalescing (fail-open).
		 *
		 * @since 2.2.0
		 * @return bool True when coalescing is active.
		 */
		public static function is_stampede_guard_enabled(): bool {
			try {
				$settings = self::get_settings();
				$guard    = $settings['cache_settings']['stampedeGuard'] ?? true;
			} catch ( \Throwable $e ) {
				$guard = true;
			}
			/**
			 * Filters whether the stampede guard coalesces hot-key rebuilds.
			 *
			 * @since 2.2.0
			 * @param bool $enabled Whether the guard is enabled.
			 */
			return (bool) apply_filters( 'wppo_stampede_guard_enabled', (bool) $guard );
		}

		/**
		 * Effective stampede lock TTL in seconds, clamped to 2-5s.
		 *
		 * Reads `wppo_settings['cache_settings']['stampedeLockTtl']` (default 5,
		 * additive key) with the `wppo_stampede_lock_ttl` filter applied last.
		 * The tight bound keeps a crashed holder from stalling rebuilds while
		 * still covering typical DB/HTTP rebuilds under herd. It is
		 * best-effort: rebuilds slower than the TTL (slow-origin telemetry
		 * fetches) may expire mid-rebuild and duplicate work rather than stall.
		 *
		 * @since 2.2.0
		 * @return int Lock TTL clamped to 2-5 seconds.
		 */
		public static function stampede_lock_ttl(): int {
			$ttl = 5;
			try {
				$settings = self::get_settings();
				$raw      = $settings['cache_settings']['stampedeLockTtl'] ?? 5;
				$ttl      = (int) $raw;
			} catch ( \Throwable $e ) {
				$ttl = 5;
			}
			/**
			 * Filters the stampede lock TTL in seconds (clamped to 2-5s after filtering).
			 *
			 * @since 2.2.0
			 * @param int $ttl Lock TTL in seconds.
			 */
			$ttl = (int) apply_filters( 'wppo_stampede_lock_ttl', $ttl );
			if ( $ttl < 2 ) {
				return 2;
			}
			if ( $ttl > 5 ) {
				return 5;
			}
			return $ttl;
		}

		/**
		 * Generate a unique stampede lock owner token.
		 *
		 * Prefers `wp_generate_uuid4()` with a `uniqid() + mt_rand()` fallback
		 * so concurrent workers never share an owner (release is owner-checked).
		 *
		 * @since 2.2.0
		 * @return string Unique owner token (never empty).
		 */
		public static function generate_stampede_owner(): string {
			try {
				if ( function_exists( 'wp_generate_uuid4' ) ) {
					$uuid = (string) wp_generate_uuid4();
					if ( '' !== $uuid ) {
						return $uuid;
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			try {
				if ( function_exists( 'wp_rand' ) ) {
					return uniqid( 'wppo-', true ) . '-' . wp_rand( 1, PHP_INT_MAX );
				}
				return uniqid( 'wppo-', true ) . '-' . random_int( 1, PHP_INT_MAX );
			} catch ( \Throwable $e ) {
				unset( $e );
				return 'wppo-' . microtime( true ) . '-' . uniqid();
			}
		}

		/**
		 * Atomically acquire a named stampede lock.
		 *
		 * Uses `wp_cache_add()` (atomic `SET NX EX` on Redis/Memcached) with a
		 * unique owner so only one worker wins — but only when a persistent
		 * object cache is present (`wp_using_ext_object_cache()`). Without a
		 * persistent cache `wp_cache_add()` is per-request in-memory only, so
		 * every worker would "acquire" the lock; the transient check-and-set
		 * fallback below is then used instead and is documented best-effort
		 * (two workers can both observe a miss and both claim the lock — it
		 * narrows the race but is not atomic unless serialized via an atomic
		 * option/DB row). Fail-open: any throwable means "not acquired"
		 * (caller serves stale).
		 *
		 * @since 2.2.0
		 * @param string $lock_key Blog-aware lock key (use transient_key()).
		 * @param string $owner    Unique owner token from generate_stampede_owner().
		 * @param int    $ttl      Lock TTL in seconds (clamped to 2-5s; best-effort
		 *                         bound — rebuilds slower than the TTL, e.g. a slow-
		 *                         origin telemetry fetch, may let the lock expire
		 *                         mid-rebuild and duplicate work).
		 * @param string $group    Object-cache group for the lock.
		 * @return bool True when this worker owns the lock.
		 */
		public static function acquire_stampede_lock( string $lock_key, string $owner, int $ttl = 5, string $group = 'wppo' ): bool {
			if ( '' === $lock_key || '' === $owner ) {
				return false;
			}
			if ( $ttl < 2 ) {
				$ttl = 2;
			} elseif ( $ttl > 5 ) {
				$ttl = 5;
			}
			try {
				$has_ext_cache = false;
				if ( function_exists( 'wp_using_ext_object_cache' ) ) {
					try {
						$has_ext_cache = (bool) wp_using_ext_object_cache();
					} catch ( \Throwable $e ) {
						unset( $e );
						$has_ext_cache = false;
					}
				}
				if ( $has_ext_cache && function_exists( 'wp_cache_add' ) ) {
					return (bool) wp_cache_add( $lock_key, $owner, $group, $ttl );
				}
				if ( function_exists( 'get_transient' ) && function_exists( 'set_transient' ) ) {
					if ( false !== get_transient( $lock_key ) ) {
						return false;
					}
					return (bool) set_transient( $lock_key, $owner, $ttl );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			return false;
		}

		/**
		 * Release a stampede lock only when this worker still owns it.
		 *
		 * Owner-checked so a slow worker never deletes a successor's lock after
		 * its own TTL expired. Fail-open: throwables are swallowed (never fatal).
		 *
		 * @since 2.2.0
		 * @param string $lock_key Blog-aware lock key.
		 * @param string $owner    Owner token that acquired the lock.
		 * @param string $group    Object-cache group for the lock.
		 * @return void
		 */
		public static function release_stampede_lock( string $lock_key, string $owner, string $group = 'wppo' ): void {
			if ( '' === $lock_key || '' === $owner ) {
				return;
			}
			try {
				$current = null;
				$found   = false;
				if ( function_exists( 'wp_cache_get' ) ) {
					$current = wp_cache_get( $lock_key, $group );
					$found   = ( $current === $owner );
				} elseif ( function_exists( 'get_transient' ) ) {
					$current = get_transient( $lock_key );
					$found   = ( $current === $owner );
				}
				if ( ! $found ) {
					// Fall back to the transient namespace: the lock may have
					// been stored there when wp_cache_add() was unavailable.
					if ( function_exists( 'get_transient' ) && $current !== $owner ) {
						$transient_current = get_transient( $lock_key );
						$found             = ( $transient_current === $owner );
					}
				}
				if ( ! $found ) {
					return;
				}
				if ( function_exists( 'wp_cache_delete' ) ) {
					wp_cache_delete( $lock_key, $group );
				}
				if ( function_exists( 'delete_transient' ) ) {
					delete_transient( $lock_key );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Derive the stale-copy transient key for a guarded value key.
		 *
		 * Callers pass an already blog-qualified `$key` (via transient_key()),
		 * so naively prefixing again would produce a double blog prefix like
		 * `3_3_wppo_audit_..._stale` on multisite. When `$key` already carries
		 * the current blog prefix only `_stale` is appended; bare keys are
		 * still qualified via {@see transient_key()} so they stay isolated.
		 *
		 * @since 2.2.0
		 * @param string $key Value cache key as passed to get_with_stampede_lock().
		 * @return string Stale-copy key.
		 */
		public static function stampede_stale_key( string $key ): string {
			try {
				if ( function_exists( 'is_multisite' ) && function_exists( 'get_current_blog_id' ) && is_multisite() ) {
					$prefix = (string) get_current_blog_id() . '_';
					if ( '' !== $prefix && str_starts_with( $key, $prefix ) ) {
						return $key . '_stale';
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			// Single-site, or a bare key on multisite: qualify normally.
			// On single-site transient_key() returns the key unchanged, so a
			// caller-passed prefixed key is never double-prefixed there.
			if ( function_exists( 'is_multisite' ) ) {
				try {
					if ( ! is_multisite() ) {
						return $key . '_stale';
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return $key . '_stale';
				}
			}
			return self::transient_key( $key . '_stale' );
		}

		/**
		 * Best-effort registration of a stale-copy key in `wppo_transient_index`.
		 *
		 * The index lets purge paths (`invalidate_audit_cache()`,
		 * `invalidate_counts_cache()`, `bump_stats_cache()`) find and delete
		 * `<key>_stale` copies so an explicit purge cannot resurrect day-old
		 * stale data on the next contention or failure. Failures are swallowed
		 * (fail-open); a missing index entry only means the stale copy lives
		 * out its TTL.
		 *
		 * @since 2.2.0
		 * @param string $key Stale-copy transient key.
		 * @param int    $ttl Stale TTL in seconds (converted to an absolute expiry).
		 * @return void
		 */
		public static function register_transient_index_key( string $key, int $ttl ): void {
			if ( '' === $key ) {
				return;
			}
			try {
				if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
					return;
				}
				$index = get_option( 'wppo_transient_index', array() );
				if ( ! is_array( $index ) ) {
					$index = array();
				}
				$index[ $key ] = time() + max( 1, $ttl );
				update_option( 'wppo_transient_index', $index, false );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get a cached value or rebuild it under an atomic owner lock.
		 *
		 * Herd immunity for hot keys: on a cache miss exactly one worker
		 * acquires the owner lock and rebuilds while the rest bounded-retry
		 * the fresh key and then serve the stale copy (stale-while-revalidate).
		 * The lock is atomic (`wp_cache_add()` `SET NX EX`) only with a
		 * persistent object cache; without one the transient fallback is
		 * best-effort. Fail-open throughout: lock/Redis failures serve stale
		 * or dynamic uncached — never fatal, never 500.
		 *
		 * The 2-5s lock TTL is a best-effort bound: rebuilds slower than the
		 * TTL (e.g. a slow-origin telemetry HTTP fetch) may let the lock
		 * expire mid-rebuild so a second worker duplicates the work. No
		 * lock-extension heartbeat is attempted; duplication is preferred over
		 * stalling rebuilds behind a crashed holder.
		 *
		 * Multisite-safe: the lock key is derived via {@see transient_key()}
		 * (blog-aware); the value key `$key` is used as given so callers keep
		 * their existing salted/transient qualification. The stale key is
		 * `$key . '_stale'` when `$key` is already blog-prefixed, otherwise
		 * `transient_key( $key . '_stale' )`, so no double blog prefix is
		 * produced while bare keys stay isolated.
		 *
		 * Stale lifecycle: every successful rebuild writes the `<key>_stale`
		 * transient (24h TTL, best-effort registered in `wppo_transient_index`
		 * so purges can find it). Callers must still delete the stale key
		 * wherever they invalidate the fresh key (purge paths do so).
		 *
		 * @since 2.2.0
		 * @param string   $key     Value cache key as read/written by $args get/set (caller-qualified).
		 * @param callable $rebuild Zero-arg rebuild callback. Returning false or WP_Error means
		 *                          "not cacheable" (stale served when available).
		 * @param array    $args    Optional arguments accepting `ttl`, `stale_ttl`, `lock_ttl`,
		 *                    `retries`, `retry_delay_us`, `group`, `force`,
		 *                    `get_cached`, and `set_cached` keys.
		 * @return mixed Fresh value, stale fallback, rebuild result, or false on total miss failure.
		 */
		public static function get_with_stampede_lock( string $key, callable $rebuild, array $args = array() ): mixed {
			$defaults = array(
				'ttl'            => 5 * MINUTE_IN_SECONDS,
				'stale_ttl'      => defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
				'lock_ttl'       => self::stampede_lock_ttl(),
				'retries'        => 4,
				'retry_delay_us' => 50000,
				'group'          => 'wppo',
				'force'          => false,
				'get_cached'     => null,
				'set_cached'     => null,
			);
			$args     = array_merge( $defaults, is_array( $args ) ? $args : array() );

			$ttl       = max( 1, (int) $args['ttl'] );
			$stale_ttl = max( 1, (int) $args['stale_ttl'] );
			$lock_ttl  = (int) $args['lock_ttl'];
			if ( $lock_ttl < 2 ) {
				$lock_ttl = 2;
			} elseif ( $lock_ttl > 5 ) {
				$lock_ttl = 5;
			}
			$retries        = max( 0, (int) $args['retries'] );
			$retry_delay_us = max( 0, (int) $args['retry_delay_us'] );
			$group          = is_string( $args['group'] ) && '' !== $args['group'] ? $args['group'] : 'wppo';
			$force          = ! empty( $args['force'] );
			$get_cached     = is_callable( $args['get_cached'] ) ? $args['get_cached'] : null;
			$set_cached     = is_callable( $args['set_cached'] ) ? $args['set_cached'] : null;

			if ( null === $get_cached ) {
				$get_cached = static function ( string $k ): mixed {
					try {
						if ( ! function_exists( 'get_transient' ) ) {
							return false;
						}
						return get_transient( $k );
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				};
			}
			if ( null === $set_cached ) {
				$set_cached = static function ( string $k, mixed $v, int $t ): bool {
					try {
						if ( ! function_exists( 'set_transient' ) ) {
							return false;
						}
						return (bool) set_transient( $k, $v, $t );
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				};
			}

			$read_cached  = static function ( string $k ) use ( $get_cached ): mixed {
				try {
					return $get_cached( $k );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			};
			$write_cached = static function ( string $k, mixed $v, int $t ) use ( $set_cached ): bool {
				try {
					return (bool) $set_cached( $k, $v, $t );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			};
			$read_stale   = static function ( string $k ): mixed {
				try {
					if ( ! function_exists( 'get_transient' ) ) {
						return false;
					}
					return get_transient( $k );
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			};
			$write_stale  = static function ( string $k, mixed $v, int $t ): void {
				try {
					if ( function_exists( 'set_transient' ) ) {
						set_transient( $k, $v, $t );
						self::register_transient_index_key( $k, $t );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			};

			$lock_key  = self::transient_key( 'wppo_stampede_' . md5( $key ) );
			$stale_key = self::stampede_stale_key( $key );

			// Fast path: no lock on hits.
			if ( ! $force ) {
				$cached = $read_cached( $key );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			// Guard disabled: plain rebuild without coalescing (fail-open).
			$guard_enabled = true;
			try {
				$guard_enabled = self::is_stampede_guard_enabled();
			} catch ( \Throwable $e ) {
				unset( $e );
				$guard_enabled = true;
			}
			if ( ! $guard_enabled ) {
				try {
					$fresh = $rebuild();
				} catch ( \Throwable $e ) {
					unset( $e );
					$stale = $read_stale( $stale_key );
					return false !== $stale ? $stale : false;
				}
				if ( $fresh instanceof \WP_Error || false === $fresh ) {
					$stale = $read_stale( $stale_key );
					return false !== $stale ? $stale : $fresh;
				}
				$write_cached( $key, $fresh, $ttl );
				$write_stale( $stale_key, $fresh, $stale_ttl );
				return $fresh;
			}

			// Contended path helpers.
			$stale = $read_stale( $stale_key );

			$owner    = self::generate_stampede_owner();
			$acquired = self::acquire_stampede_lock( $lock_key, $owner, $lock_ttl, $group );

			if ( $acquired ) {
				try {
					$fresh = $rebuild();
				} catch ( \Throwable $e ) {
					unset( $e );
					self::release_stampede_lock( $lock_key, $owner, $group );
					$stale_now = $read_stale( $stale_key );
					return false !== $stale_now ? $stale_now : false;
				}
				if ( $fresh instanceof \WP_Error || false === $fresh ) {
					self::release_stampede_lock( $lock_key, $owner, $group );
					$stale_now = $read_stale( $stale_key );
					return false !== $stale_now ? $stale_now : $fresh;
				}
				$write_cached( $key, $fresh, $ttl );
				$write_stale( $stale_key, $fresh, $stale_ttl );
				self::release_stampede_lock( $lock_key, $owner, $group );
				return $fresh;
			}

			// Another worker rebuilds: bounded retry on the fresh key, then stale.
			for ( $i = 0; $i < $retries; $i++ ) {
				if ( $retry_delay_us > 0 && function_exists( 'usleep' ) ) {
					try {
						usleep( $retry_delay_us );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				$cached = $read_cached( $key );
				if ( false !== $cached ) {
					return $cached;
				}
			}

			// Prefer the freshest stale copy: the winner may have refreshed
			// the stale key during the retry loop, so re-read first and only
			// fall back to the pre-lock snapshot when the re-read misses.
			$stale_now = $read_stale( $stale_key );
			if ( false !== $stale_now ) {
				return $stale_now;
			}
			if ( false !== $stale ) {
				return $stale;
			}

			// Cold start with no stale: fail-open direct rebuild (duplicate work
			// is unavoidable exactly once); never fatal.
			try {
				$fresh = $rebuild();
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
			if ( $fresh instanceof \WP_Error || false === $fresh ) {
				return $fresh;
			}
			$write_cached( $key, $fresh, $ttl );
			$write_stale( $stale_key, $fresh, $stale_ttl );
			return $fresh;
		}

		/**
		 * Coalesce concurrent alloptions loads behind a stampede lock.
		 *
		 * Forty concurrent cold `alloptions` misses each hit the database via
		 * `wp_load_alloptions()` unless they are collapsed: this wrapper runs
		 * `$rebuild` (normally the single database query) under
		 * {@see get_with_stampede_lock()} so exactly one worker rebuilds while
		 * the rest wait a bounded interval (retries × delay, defaults ≈ 8 ×
		 * 50ms) and then serve the fresh or stale copy. Fail-open throughout:
		 * lock/Redis failures serve stale or dynamic uncached — never fatal.
		 *
		 * Multisite-safe: the coalescing key is blog-qualified via
		 * {@see transient_key()}, so site A's miss never serves site B's
		 * options and there is no cross-site leakage.
		 *
		 * @since NEXT
		 * @param callable $rebuild Zero-arg rebuild callback performing the single database query. Returning false or WP_Error means "not cacheable" (stale served when available).
		 * @param array    $args    Optional overrides for `ttl`, `stale_ttl`, `lock_ttl`, `retries`, `retry_delay_us`, and `group` (see get_with_stampede_lock()).
		 * @return mixed Fresh value, stale fallback, rebuild result, or false on total miss failure.
		 */
		public static function get_alloptions_with_stampede_lock( callable $rebuild, array $args = array() ): mixed {
			$key      = self::transient_key( 'wppo_alloptions' );
			$defaults = array(
				'ttl'            => 5 * ( defined( 'MINUTE_IN_SECONDS' ) ? MINUTE_IN_SECONDS : 60 ),
				'stale_ttl'      => defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400,
				'retries'        => 8,
				'retry_delay_us' => 50000,
			);
			$merged   = array_merge( $defaults, $args );
			try {
				return self::get_with_stampede_lock( $key, $rebuild, $merged );
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					return $rebuild();
				} catch ( \Throwable $e ) {
					unset( $e );
					return false;
				}
			}
		}

		/**
		 * Compute a stable 12-char hex hash of a user's sorted roles, salted
		 * with the site's secret to prevent cookie forgery.
		 *
		 * Used by the caching layer to generate role-specific cache variants for
		 * logged-in users. The same salt is embedded in the advanced-cache.php
		 * drop-in at generation time so the early-boot serving code can compute
		 * an identical hash.
		 *
		 * @since 2.0.0
		 * @param \WP_User $user The user whose roles to hash.
		 * @return string 12-char hex hash, or empty string if the user has no roles.
		 */
		public static function get_role_hash( \WP_User $user ): string {
			if ( empty( $user->roles ) ) {
				return '';
			}
			$roles = $user->roles;
			sort( $roles );
			return substr( md5( implode( ',', $roles ) . wp_salt() ), 0, 12 );
		}

		/**
		 * Whether the current user is eligible for logged-in caching based on
		 * the cache settings (enableLoggedInCache + loggedInCacheRoles).
		 *
		 * Non-logged-in visitors always return true (they always get cached).
		 *
		 * @since 2.0.0
		 * @param array $cache_settings The cache_settings sub-array from wppo_settings.
		 * @return bool True if the current user may receive cached pages / optimisations.
		 */
		public static function is_cache_eligible_for_current_user( array $cache_settings ): bool {
			if ( ! is_user_logged_in() ) {
				return true;
			}

			$enable = ! empty( $cache_settings['enableLoggedInCache'] ?? false );
			if ( ! $enable ) {
				return false;
			}

			$user = wp_get_current_user();
			if ( empty( $user->roles ) ) {
				return false;
			}

			$allowed_roles = $cache_settings['loggedInCacheRoles'] ?? array();
			if ( ! is_array( $allowed_roles ) || empty( $allowed_roles ) ) {
				return true;
			}

			foreach ( $user->roles as $role ) {
				if ( in_array( $role, $allowed_roles, true ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Whether the current WordPress version supports auto-sizes for lazy-loaded images.
		 *
		 * Enhanced Responsive Images (Core ticket #61847) shipped in WordPress 6.7 and is
		 * gated on the presence of wp_img_tag_add_auto_sizes() / wp_sizes_attribute_includes_valid_auto().
		 *
		 * @since 1.9.0
		 * @return bool True when auto-sizes is available.
		 */
		public static function is_auto_sizes_available(): bool {
			return function_exists( 'wp_sizes_attribute_includes_valid_auto' )
				|| function_exists( 'wp_img_tag_add_auto_sizes' );
		}

		/**
		 * Whether content contains a block type via streaming processor.
		 *
		 * Uses WP 6.9+ `WP_Block_Processor` streaming traversal to avoid OOM on
		 * 1000+ blocks; falls back to `parse_blocks()` on older cores. Guards
		 * `class_exists` and `method_exists` for beta API variance (WP 6.9 beta
		 * exposed `get_block_type` vs `get_block_name`).
		 *
		 * @since 2.0.0
		 * @param string $content    Post content.
		 * @param string $block_name Block name e.g. 'core/image'.
		 * @return bool True when the block type is present.
		 */
		public static function content_has_block( string $content, string $block_name ): bool {
			if ( '' === $content || '' === $block_name ) {
				return false;
			}
			if ( class_exists( 'WP_Block_Processor' ) && method_exists( 'WP_Block_Processor', 'next_block' ) ) {
				$processor    = new \WP_Block_Processor( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
				$has_get_type = method_exists( $processor, 'get_block_type' );
				$has_get_name = method_exists( $processor, 'get_block_name' );
				if ( $has_get_type || $has_get_name ) {
					while ( $processor->next_block() ) { // phpcs:ignore Generic.CodeAnalysis.RequireExplicitParentheses
						$type = $has_get_type ? $processor->get_block_type() : $processor->get_block_name();
						if ( $type === $block_name ) {
							return true;
						}
					}
					return false;
				}
			}
			if ( function_exists( 'parse_blocks' ) ) {
				$blocks = parse_blocks( $content );
				if ( self::blocks_contain_type( $blocks, $block_name ) ) {
					return true;
				}
			}
			return false !== strpos( $content, '<!-- wp:' . $block_name );
		}

		/**
		 * Count blocks of a given type in post content.
		 *
		 * Streaming via `WP_Block_Processor` on WP 6.9+; fallback to
		 * `parse_blocks()` recursion. Reused for gallery/LCP counts.
		 *
		 * @since 2.0.0
		 * @param string $content    Post content.
		 * @param string $block_name Block name e.g. 'core/gallery'.
		 * @return int Number of matching blocks.
		 */
		public static function count_blocks_by_type( string $content, string $block_name ): int {
			if ( '' === $content || '' === $block_name ) {
				return 0;
			}
			if ( class_exists( 'WP_Block_Processor' ) && method_exists( 'WP_Block_Processor', 'next_block' ) ) {
				$processor    = new \WP_Block_Processor( $content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound
				$has_get_type = method_exists( $processor, 'get_block_type' );
				$has_get_name = method_exists( $processor, 'get_block_name' );
				if ( $has_get_type || $has_get_name ) {
					$count = 0;
					while ( $processor->next_block() ) { // phpcs:ignore Generic.CodeAnalysis.RequireExplicitParentheses
						$type = $has_get_type ? $processor->get_block_type() : $processor->get_block_name();
						if ( $type === $block_name ) {
							++$count;
						}
					}
					return $count;
				}
			}
			if ( function_exists( 'parse_blocks' ) ) {
				$blocks = parse_blocks( $content );
				return self::count_blocks_recursive( $blocks, $block_name );
			}
			return substr_count( $content, '<!-- wp:' . $block_name );
		}

		/**
		 * Whether a parsed block tree contains a block type (recursive).
		 *
		 * @since 2.0.0
		 * @param array  $blocks     Parsed blocks from parse_blocks().
		 * @param string $block_name Block name.
		 * @return bool
		 */
		private static function blocks_contain_type( array $blocks, string $block_name ): bool {
			foreach ( $blocks as $block ) {
				if ( ( $block['blockName'] ?? '' ) === $block_name ) {
					return true;
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					if ( self::blocks_contain_type( $block['innerBlocks'], $block_name ) ) {
						return true;
					}
				}
			}
			return false;
		}

		/**
		 * Count blocks of type in a parsed block tree (recursive).
		 *
		 * @since 2.0.0
		 * @param array  $blocks     Parsed blocks.
		 * @param string $block_name Block name.
		 * @return int
		 */
		private static function count_blocks_recursive( array $blocks, string $block_name ): int {
			$count = 0;
			foreach ( $blocks as $block ) {
				if ( ( $block['blockName'] ?? '' ) === $block_name ) {
					++$count;
				}
				if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
					$count += self::count_blocks_recursive( $block['innerBlocks'], $block_name );
				}
			}
			return $count;
		}

		/**
		 * Convert wildcard pattern to regex fragment (mirrors CDN::wildcard2regex / LSCWP cdn.cls.php:188).
		 *
		 * @since 2.0.0
		 * @param string $pattern Wildcard pattern.
		 * @return string Regex fragment.
		 */
		public static function wildcard2regex( string $pattern ): string {
			if ( class_exists( 'PerformanceOptimise\Inc\CDN' ) && method_exists( 'PerformanceOptimise\Inc\CDN', 'wildcard2regex' ) ) {
				return CDN::wildcard2regex( $pattern );
			}
			$pattern = trim( $pattern );
			if ( '' === $pattern ) {
				return '';
			}
			$escaped = preg_quote( $pattern, '#' );
			return str_replace( '\*', '.*', $escaped );
		}

		/**
		 * Sanitize the LiteSpeed integration `mode` value against its allowlist.
		 *
		 * Per-tab sanitizer extracted from {@see sanitize_settings_recursively()}
		 * so the allowlist lives in one place with its own unit test.
		 *
		 * @since 2.2.0
		 * @param mixed $value Raw value.
		 * @return string Allowlisted mode ('auto' fallback).
		 */
		public static function sanitize_mode_value( $value ): string {
			$raw = sanitize_text_field( (string) $value );
			return in_array( $raw, array( 'auto', 'wppo', 'litespeed', 'standalone' ), true ) ? $raw : 'auto';
		}

		/**
		 * Sanitize per-post-type cache TTL overrides.
		 *
		 * Per-tab sanitizer extracted from {@see sanitize_settings_recursively()}.
		 *
		 * @since 2.2.0
		 * @param array $value Raw overrides.
		 * @return array Sanitized overrides.
		 */
		public static function sanitize_ttl_overrides( $value ): array {
			$allowed_hours = array( 0, 1, 6, 12, 24, 48, 168 );
			$allowed_types = array( 'post', 'page', 'product' );
			$overrides     = array();
			foreach ( (array) $value as $ptype => $hours ) {
				$safe_ptype = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $ptype );
				if ( '' === $safe_ptype || ! in_array( $safe_ptype, $allowed_types, true ) ) {
					continue;
				}
				if ( '' === $hours || null === $hours ) {
					continue;
				}
				$int_hours = absint( $hours );
				if ( ! in_array( $int_hours, $allowed_hours, true ) ) {
					continue;
				}
				$overrides[ $safe_ptype ] = $int_hours;
			}
			/**
			 * Filter sanitized TTL overrides.
			 *
			 * @since 2.0.0
			 * @param array $overrides Sanitized overrides.
			 */
			return (array) apply_filters( 'wppo_cache_ttl_overrides', $overrides );
		}

		/**
		 * Sanitize the one-to-many CDN mapping list.
		 *
		 * Per-tab sanitizer extracted from {@see sanitize_settings_recursively()}.
		 *
		 * @since 2.2.0
		 * @param array $value Raw mapping entries.
		 * @return array Sanitized mapping.
		 */
		public static function sanitize_cdn_mapping( $value ): array {
			$max = (int) apply_filters( 'wppo_cdn_mapping_max', 5 );
			if ( $max < 1 ) {
				$max = 5;
			}
			$mapping = array();
			$count   = 0;
			foreach ( (array) $value as $entry ) {
				if ( ! is_array( $entry ) || $count >= $max ) {
					continue;
				}
				$cdn_url = isset( $entry['cdn_url'] ) ? esc_url_raw( (string) $entry['cdn_url'] ) : '';
				if ( '' === $cdn_url ) {
					continue;
				}
				$ori      = isset( $entry['ori'] ) ? esc_url_raw( (string) $entry['ori'] ) : '';
				$ori_dir  = isset( $entry['ori_dir'] ) ? sanitize_text_field( (string) $entry['ori_dir'] ) : '';
				$cdn_attr = isset( $entry['cdn_attr'] ) ? sanitize_text_field( (string) $entry['cdn_attr'] ) : '';
				if ( '' !== $ori_dir ) {
					$parts = array_filter( array_map( 'trim', explode( '|', $ori_dir ) ) );
					$valid = array();
					foreach ( $parts as $p ) {
						if ( preg_match( '/^[a-zA-Z0-9_\-\/\.\*]+$/', $p ) ) {
							$valid[] = $p;
						}
					}
					$ori_dir = implode( '|', $valid );
				}
				$include_dirs      = isset( $entry['include_dirs'] ) ? sanitize_text_field( (string) $entry['include_dirs'] ) : 'wp-content|wp-includes';
				$include_filetypes = isset( $entry['include_filetypes'] ) ? sanitize_text_field( (string) $entry['include_filetypes'] ) : '';
				if ( '' !== $include_filetypes ) {
					$include_filetypes = strtolower( $include_filetypes );
					$parts             = array_map( 'trim', explode( ',', $include_filetypes ) );
					$parts             = array_filter( $parts );
					$parts             = array_map( fn( $t ) => ltrim( $t, '.' ), $parts );
					$include_filetypes = implode( ',', $parts );
				}
				$data = array(
					'cdn_url'           => $cdn_url,
					'include_dirs'      => $include_dirs,
					'include_filetypes' => $include_filetypes,
				);
				if ( '' !== $ori ) {
					$data['ori'] = $ori;
				}
				if ( '' !== $ori_dir ) {
					$data['ori_dir'] = $ori_dir;
				}
				if ( '' !== $cdn_attr ) {
					$data['cdn_attr'] = $cdn_attr;
				}
				if ( isset( $entry['cdn_urls'] ) && is_array( $entry['cdn_urls'] ) ) {
					$cdns = array_values( array_filter( array_map( fn( $u ) => esc_url_raw( (string) $u ), $entry['cdn_urls'] ) ) );
					if ( ! empty( $cdns ) ) {
						$data['cdn_urls'] = $cdns;
					}
				} elseif ( isset( $entry['cdns'] ) && is_array( $entry['cdns'] ) ) {
					$cdns = array_values( array_filter( array_map( fn( $u ) => esc_url_raw( (string) $u ), $entry['cdns'] ) ) );
					if ( ! empty( $cdns ) ) {
						$data['cdn_urls'] = $cdns;
					}
				}
				/**
				 * Filter single CDN mapping entry post-sanitize.
				 *
				 * @since 2.0.0
				 * @param array $data Sanitized entry.
				 */
				$data      = (array) apply_filters( 'wppo_cdn_mapping_entry', $data );
				$mapping[] = $data;
				++$count;
			}
			/**
			 * Filter CDN mapping array.
			 *
			 * @since 2.0.0
			 * @param array $mapping Sanitized mapping.
			 */
			return (array) apply_filters( 'wppo_cdn_mapping', $mapping );
		}

		/**
		 * Sanitize a scalar settings value with the generic fallback rules.
		 *
		 * Extracted from {@see sanitize_settings_recursively()} so the
		 * key-name heuristic (exclude/preload/delay/url/cdn) is unit-testable
		 * in isolation and the main loop stays a readable dispatcher.
		 *
		 * @since 2.2.0
		 * @param string $safe_key Sanitized key.
		 * @param mixed  $value    Raw value.
		 * @return mixed Sanitized value.
		 */
		public static function sanitize_scalar_setting( string $safe_key, $value ) {
			if ( is_bool( $value ) ) {
				return (bool) $value;
			}
			if ( is_numeric( $value ) ) {
				return (int) $value;
			}
			if ( in_array( $safe_key, array( 'pagespeed_api_key', 'password' ), true ) ) {
				return sanitize_text_field( $value );
			}
			if ( stripos( $safe_key, 'exclude' ) !== false || stripos( $safe_key, 'preload' ) !== false || stripos( $safe_key, 'delay' ) !== false || stripos( $safe_key, 'list' ) !== false ) {
				return sanitize_textarea_field( $value );
			}
			if ( stripos( $safe_key, 'url' ) !== false || stripos( $safe_key, 'cdn' ) !== false || stripos( $safe_key, 'origin' ) !== false ) {
				return esc_url_raw( $value );
			}
			return sanitize_text_field( $value );
		}

		/**
		 * Map of setting-tab slugs to their dedicated sanitizer methods.
		 *
		 * Schema-driven dispatch table for {@see sanitize_settings_recursively()}:
		 * every tab in {@see get_default_settings()} must appear here so a new
		 * tab key can never be silently dropped or stored unsanitized.
		 *
		 * @since 2.2.0
		 * @return array<string,string> Tab slug => sanitizer method name.
		 */
		public static function get_settings_sanitizer_map(): array {
			return array(
				'cache_settings'        => 'sanitize_cache_settings',
				'file_optimisation'     => 'sanitize_file_optimisation',
				'preload_settings'      => 'sanitize_scalar_setting',
				'image_optimisation'    => 'sanitize_scalar_setting',
				'performance_audit'     => 'sanitize_scalar_setting',
				'database_cleanup'      => 'sanitize_scalar_setting',
				'object_cache'          => 'sanitize_scalar_setting',
				'litespeed_integration' => 'sanitize_mode_value',
				'llms_txt'              => 'sanitize_scalar_setting',
				'od_integration'        => 'sanitize_scalar_setting',
				'bfcache'               => 'sanitize_scalar_setting',
				'perf_translations'     => 'sanitize_scalar_setting',
				'ai_adaptive'           => 'sanitize_scalar_setting',
				'edge_cache'            => 'sanitize_scalar_setting',
			);
		}

		/**
		 * Sanitize the `cache_settings` tab.
		 *
		 * Per-tab entry point delegating to {@see sanitize_settings_recursively()};
		 * exists so the sanitizer map covers every schema tab with a named,
		 * testable method.
		 *
		 * @since 2.2.0
		 * @param array $settings Raw tab settings.
		 * @return array Sanitized tab settings.
		 */
		public static function sanitize_cache_settings( $settings ): array {
			return self::sanitize_settings_recursively( (array) $settings );
		}

		/**
		 * Sanitize the `file_optimisation` tab.
		 *
		 * Per-tab entry point delegating to {@see sanitize_settings_recursively()};
		 * exists so the sanitizer map covers every schema tab with a named,
		 * testable method.
		 *
		 * @since 2.2.0
		 * @param array $settings Raw tab settings.
		 * @return array Sanitized tab settings.
		 */
		public static function sanitize_file_optimisation( $settings ): array {
			return self::sanitize_settings_recursively( (array) $settings );
		}

		/**
		 * Sanitizes the settings array recursively.
		 *
		 * Shared by every settings entry point (REST API, WP-CLI import/update)
		 * so that values written to `wppo_settings` are always sanitized and
		 * type-normalized regardless of how they arrive.
		 *
		 * @param array $settings The settings array.
		 * @return array The sanitized settings array.
		 * @since 2.0.0
		 */
		public static function sanitize_settings_recursively( $settings ) {
			$sanitized = array();
			foreach ( $settings as $key => $value ) {
				$safe_key = preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $key );
				if ( ! is_string( $safe_key ) ) {
					continue;
				}

				// Skip keys that become empty after sanitization so that
				// settings are never stored under an empty-string key.
				if ( '' === $safe_key ) {
					continue;
				}

				// LiteSpeed integration — allowlist mode values.
				if ( 'mode' === $safe_key && ! is_array( $value ) ) {
					$sanitized[ $safe_key ] = self::sanitize_mode_value( $value );
					continue;
				}

				// Cache TTL overrides — per-post-type hours allowlist (0/1/6/12/24/48/168), absint.
				if ( 'ttlOverrides' === $safe_key && is_array( $value ) ) {
					$sanitized[ $safe_key ] = self::sanitize_ttl_overrides( $value );
					continue;
				}

				// P1 CDN mapping — one-to-many (cdn.cls.php:48 parity).
				if ( 'cdnMapping' === $safe_key && is_array( $value ) ) {
					$sanitized[ $safe_key ] = self::sanitize_cdn_mapping( $value );
					continue;
				}

				// Persistent Redis outage flag (issue #1233) — pinned boolean
				// normalization before the generic branches: a JSON string
				// "false" must sanitize to false (it would otherwise stay a
				// truthy non-empty string and paradoxically read as armed).
				// Server-only in practice (REST update/import strip client
				// values); unrecognized values fail safe to false.
				if ( 'outage_bypassed' === $safe_key && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = true === $bool;
					}
					continue;
				}

				// RUM-gated speculation eagerness (issue #1061) — normalize
				// malformed import shapes (0/1, '0'/'1', 'false'/'true') to
				// bool. Unrecognized values fail open to true (gating on).
				if ( 'speculationRumGating' === $safe_key && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? true : $bool;
					}
					continue;
				}

				// Automatic LCP hero preload + font discovery toggles (issue
				// #1216) plus the high-value prerender list toggle (issue
				// #1237) and the opt-in failed-action purge toggle (issue
				// #1310, `database_cleanup.purgeFailedActions`) — normalize
				// malformed import shapes to bool so a
				// string 'false' (textarea/text branches preserve strings, and
				// !empty('false') is truthy at every read site) cannot silently
				// enable the features. Unrecognized values fail safe to false
				// (all four features default off). Pinned before the generic
				// stripos 'list' branch so speculationPrerenderList never
				// falls through to sanitize_textarea_field.
				if ( in_array( $safe_key, array( 'autoLcpPreload', 'autoDiscoverFonts', 'speculationPrerenderList', 'purgeFailedActions', 'occlusionFetchpriorityLow', 'speculation_autotune_enabled' ), true ) && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? false : $bool;
					}
					continue;
				}

				// Woo safe mode toggle (issue #922) — normalize malformed import
				// shapes (0/1, '0'/'1', 'false'/'true') to bool so the toggle
				// check in Cache::is_woo_excluded() is reliable. Unrecognized
				// values fail safe to true (enabled).
				if ( 'wooSafeMode' === $safe_key && ! is_array( $value ) ) {
					$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized[ $safe_key ] = null === $bool ? true : $bool;
					continue;
				}

				// INP-first delay preset (issue #932) — normalize malformed import
				// shapes (0/1, '0'/'1', 'false'/'true') to bool. Unrecognized
				// values fail safe to false (preset off, existing behavior).
				if ( 'delayJSINPPreset' === $safe_key && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? false : $bool;
					}
					continue;
				}

				// One-click Delay-JS preset level (issue #1385) — safe|balanced|
				// aggressive only. Unrecognized values fail safe to 'safe'
				// (maximum exclusions, least delay).
				if ( 'delayJSPreset' === $safe_key && ! is_array( $value ) ) {
					$level = strtolower( trim( (string) $value ) );
					if ( ! in_array( $level, array( 'safe', 'balanced', 'aggressive' ), true ) ) {
						$level = 'safe';
					}
					$sanitized[ $safe_key ] = $level;
					continue;
				}

				// Safe-default delay keys (issues #966, #1308, and #1314) —
				// external-only defaults off (fail-safe: delay everything
				// unless asked), builder preset defaults on (fail-safe: never
				// delay builder runtimes), and the four #1308 opt-in compat
				// presets (consent/analytics/gallery/jquery) default off so
				// upgrades preserve manual exclusions.
				if ( in_array(
					$safe_key,
					array(
						'delayJSExternalOnly',
						'delayJSThirdParty',
						'delayJSThirdPartyAuto',
						'delayJSConsentPreset',
						'delayJSAnalyticsPreset',
						'delayJSGalleryPreset',
						'delayJSJqueryPreset',
					),
					true
				) && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? false : $bool;
					}
					continue;
				}

				$safe_on_keys = array(
					'delayJSBuilderPreset',
					'delayJSCommercePreset',
					'delayJSInteractionPreset',
					'unusedCSSRegressionGuard',
				);
				if ( in_array( $safe_key, $safe_on_keys, true ) && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						// Fail-safe: preset/guard default on; unrecognized values stay on.
						$sanitized[ $safe_key ] = null === $bool ? true : $bool;
					}
					continue;
				}

				// Lazy-render below-fold toggle — normalize malformed import
				// shapes (0/1, '0'/'1', 'false'/'true') to bool. Fail-safe off.
				if ( 'lazyRenderBelowFold' === $safe_key && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? false : $bool;
					}
					continue;
				}

				// Lazy-render builder exclusion — pinned before the generic
				// textarea branch (the key contains 'exclude'), so 'false'/0/1
				// import shapes normalize to bool. Fail-safe on (never
				// lazy-render builder runtimes on unrecognized values).
				if ( 'lazyRenderExcludeBuilders' === $safe_key && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? true : $bool;
					}
					continue;
				}

				// Unused-CSS regression threshold (issue #966) — int clamped to
				// 5-50 (% retained). Unrecognized values fail safe to 20.
				if ( 'unusedCSSRegressionThreshold' === $safe_key && ! is_array( $value ) ) {
					$threshold              = is_numeric( $value ) ? (int) $value : 20;
					$sanitized[ $safe_key ] = ( $threshold >= 5 && $threshold <= 50 ) ? $threshold : 20;
					continue;
				}

				// Unused-CSS extra safelist (issue #966) — one selector per line.
				// CCSS user safelist (issue #1038) shares the same textarea
				// contract: selectors never pruned from Critical CSS inlining.
				if ( in_array( $safe_key, array( 'unusedCSSSafelistExtra', 'ccssSafelistExtra' ), true ) && ! is_array( $value ) ) {
					$sanitized[ $safe_key ] = sanitize_textarea_field( (string) $value );
					continue;
				}

				// Used-CSS delivery mode (issue #1220) — allowlist
				// file/delay/async/remove. Unknown values fail open to file.
				if ( 'usedCSSDeliveryMode' === $safe_key && ! is_array( $value ) ) {
					$mode                   = strtolower( trim( (string) $value ) );
					$sanitized[ $safe_key ] = in_array( $mode, array( 'file', 'delay', 'async', 'remove' ), true ) ? $mode : 'file';
					continue;
				}

				// Max longest edge cap (issue #985 follow-up) — int >= 0.
				// A cleared numeric field submits '' (or non-numeric text),
				// which must fall back to the 2560 default rather than
				// silently becoming 0/disabled at read time. Negatives clamp
				// to 0 (disabled).
				if ( 'maxLongestEdgePx' === $safe_key ) {
					if ( is_array( $value ) || '' === $value || null === $value || ! is_numeric( $value ) ) {
						$sanitized[ $safe_key ] = 2560;
					} else {
						$edge                   = (int) $value;
						$sanitized[ $safe_key ] = $edge < 0 ? 0 : $edge;
					}
					continue;
				}

				// CCSS generation timeout (issue #1235) — int clamped to
				// 1-120 (seconds). Unrecognized values fail open to 25 so
				// generation is always bounded. Pinned before the generic
				// is_numeric branch so 0/negative/huge values can never be
				// stored; get_ccss_gen_timeout() still clamps at read time
				// as defense-in-depth.
				if ( 'ccssGenTimeout' === $safe_key ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 25;
						continue;
					}
					$timeout                = is_numeric( $value ) ? (int) $value : 25;
					$sanitized[ $safe_key ] = ( $timeout >= 1 && $timeout <= 120 ) ? $timeout : 25;
					continue;
				}

				// CCSS gzipped inline budget (issue #1388) — int clamped to
				// 1-100 (KB). Unrecognized values fail open to 14 so inline
				// output stays bounded. Read-time clamping in
				// get_ccss_inline_budget_bytes() is defense-in-depth.
				if ( 'ccssInlineBudgetKb' === $safe_key ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 14;
						continue;
					}
					$budget                 = is_numeric( $value ) ? (int) $value : 14;
					$sanitized[ $safe_key ] = ( $budget >= 1 && $budget <= 100 ) ? $budget : 14;
					continue;
				}

				// CCSS commerce exclusion + checksum regen toggles (issue
				// #1388) — booleans via ! empty() so absent/unchecked stays
				// false and any truthy input enables. Pinned before the
				// generic scalar branch so '0'/'' can never enable them.
				if ( in_array( $safe_key, array( 'ccssCommerceExclude', 'ccssChecksumRegen' ), true ) ) {
					$sanitized[ $safe_key ] = ! empty( $value );
					continue;
				}

				// RUM-weighted top-URL prefetch cap (issue #1183) — int clamped
				// to 1-5 (footprint guard, ~0.15 KB per URL). Unrecognized
				// values fail open to 2.
				if ( 'speculationTopUrlsLimit' === $safe_key ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 2;
						continue;
					}
					$limit                  = is_numeric( $value ) ? (int) $value : 2;
					$sanitized[ $safe_key ] = ( $limit >= 1 && $limit <= 5 ) ? $limit : 2;
					continue;
				}

				// RUM beacon sample rate (issue #1214) — int clamped to
				// 1-100 (percent of page views sending the beacon).
				// Unrecognized values fail open to 100 (unsampled current
				// behavior). Pinned before the generic is_numeric branch so
				// 0/negative/huge values can never be stored.
				if ( 'rum_sample_rate' === $safe_key ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 100;
						continue;
					}
					$rate                   = is_numeric( $value ) ? (int) $value : 100;
					$sanitized[ $safe_key ] = ( $rate >= 1 && $rate <= 100 ) ? $rate : 100;
					continue;
				}

				// Field-LCP minimum-sample threshold (issue #1200) — int
				// clamped to 1-1000. Covers both the additive
				// `ai_adaptive.field_lcp_min_samples` key and the legacy
				// `image_optimisation.fieldLcpMinSamples` key so an extreme
				// admin value cannot permanently pin auto-tune to provisional.
				// Unrecognized values fail open to the 20 default.
				// Also covers the additive RUM-segmented speculation auto-tune
				// threshold (issue #1425,
				// `ai_adaptive.speculation_min_samples`) so a rogue value
				// cannot pin the auto-tune to always-undersampled or
				// always-qualified.
				if ( in_array( $safe_key, array( 'field_lcp_min_samples', 'fieldLcpMinSamples', 'speculation_min_samples' ), true ) ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 20;
						continue;
					}
					$min                    = is_numeric( $value ) ? (int) $value : 20;
					$sanitized[ $safe_key ] = min( 1000, max( 1, $min ) );
					continue;
				}

				// RUM-segmented speculation auto-tune URL cap (issue #1425,
				// `ai_adaptive.speculation_max_urls`) — int clamped to 1-5 so
				// the speculation JSON delta stays under ~1KB. Unrecognized
				// values fail open to the 5 default.
				if ( 'speculation_max_urls' === $safe_key ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 5;
						continue;
					}
					$max                    = is_numeric( $value ) ? (int) $value : 5;
					$sanitized[ $safe_key ] = min( 5, max( 1, $max ) );
					continue;
				}
				// RUM anomaly digest tolerance band (issue #1445) — floats so
				// the CLS absolute band (0.01) survives the generic
				// is_numeric-to-int cast below. Clamped to 0–50 (percent)
				// and 0–1 (absolute); unrecognized values fail open to the
				// 5.0 / 0.01 defaults.
				if ( 'anomaly_tolerance_pct' === $safe_key ) {
					if ( is_array( $value ) || ! is_numeric( $value ) ) {
						$sanitized[ $safe_key ] = 5.0;
						continue;
					}
					$tol                    = (float) $value;
					$sanitized[ $safe_key ] = ( is_finite( $tol ) && $tol >= 0 ) ? min( 50.0, $tol ) : 5.0;
					continue;
				}
				if ( 'anomaly_tolerance_abs' === $safe_key ) {
					if ( is_array( $value ) || ! is_numeric( $value ) ) {
						$sanitized[ $safe_key ] = 0.01;
						continue;
					}
					$tol                    = (float) $value;
					$sanitized[ $safe_key ] = ( is_finite( $tol ) && $tol >= 0 ) ? min( 1.0, $tol ) : 0.01;
					continue;
				}

				// Newline/regex URL lists must use the textarea sanitizer, not
				// the generic `url` branch (esc_url_raw would collapse the
				// multiple lines). Pinned explicitly so a future reorder of the
				// generic branches cannot corrupt these lists.
				if ( in_array( $safe_key, array( 'delayJSExcludeUrls', 'usedCSSExcludeUrls', 'delayJSThirdPartyDenylist', 'delayJSThirdPartyAllowlist', 'ccssExcludedPostTypes' ), true ) && ! is_array( $value ) ) {
					$sanitized[ $safe_key ] = sanitize_textarea_field( (string) $value );
					continue;
				}

				if ( 'elementorSafeMode' === $safe_key && ! is_array( $value ) ) {
					$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					$sanitized[ $safe_key ] = null === $bool ? true : $bool;
					continue;
				}
				// Unified safe-mode + combineCSS (issue #1465): normalize
				// malformed import shapes to bool so a string 'false' cannot
				// silently enable the stack. Both fail safe to false so an
				// upgrade or malformed import never auto-enables combine or
				// safe mode (combine stays off unless explicitly enabled).
				if ( in_array( $safe_key, array( 'safeMode', 'combineCSS' ), true ) && ! is_array( $value ) ) {
					if ( is_bool( $value ) ) {
						$sanitized[ $safe_key ] = $value;
					} else {
						$bool                   = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$sanitized[ $safe_key ] = null === $bool ? false : $bool;
					}
					continue;
				}
				// CCSS bounded-retry cap (issue #1274) — int clamped to
				// 0..5 (0 = fail fast, no retries). Unrecognized values
				// fail open to 5 so generation keeps its retry budget.
				if ( 'ccssMaxRetries' === $safe_key ) {
					if ( is_array( $value ) ) {
						$sanitized[ $safe_key ] = 5;
						continue;
					}
					$retries                = is_numeric( $value ) ? (int) $value : 5;
					$sanitized[ $safe_key ] = min( 5, max( 0, $retries ) );
					continue;
				}

				if ( is_array( $value ) ) {
					$sanitized[ $safe_key ] = self::sanitize_settings_recursively( $value );
				} else {
					$sanitized[ $safe_key ] = self::sanitize_scalar_setting( $safe_key, $value );
				}
			}
			return $sanitized;
		}

		/**
		 * Current value of a salted-cache salt (option-backed).
		 *
		 * The WP 6.9+ salted cache family compares the salt VALUE passed at
		 * read/write time against the value stored alongside each entry, so
		 * callers must pass the current option value — not the option key.
		 * Passing the key name instead would make every salt bump a no-op
		 * (the comparison never changes) and reduce invalidation to the
		 * entry TTL alone.
		 *
		 * @since 2.0.0
		 *
		 * @param string $option Option key holding the salt.
		 * @return string Current salt value ('0' until the first bump).
		 */
		public static function cache_salt( string $option ): string {
			$value = get_option( $option, '0' );
			// false (unset option) maps to the '0' sentinel, never ''.
			if ( ! is_scalar( $value ) || false === $value ) {
				return '0';
			}
			return (string) $value;
		}

		/**
		 * Memoized availability of the WP 6.9+ HTML API token serializer.
		 *
		 * Reset per request via {@see reset_html_processor_memo()} (used by the
		 * test suite to isolate the reflection probe).
		 *
		 * @since 2.0.0
		 * @var bool|null
		 */
		private static ?bool $html_processor_available = null;

		/**
		 * Whether the WP 6.9+ HTML API token serializer is available.
		 *
		 * Checks for the public `WP_HTML_Processor::serialize_token()` introduced
		 * in WP 6.9 (private in 6.7). Uses reflection to guard the 6.7 private
		 * visibility so the fallback remains byte-identical on WP <6.9.
		 *
		 * Shared by every processor-based buffer rewrite (image optimisation,
		 * CDN rewriting, used-CSS extraction); memoized per request.
		 *
		 * @since 2.0.0
		 * @return bool True when `WP_HTML_Processor::serialize_token()` is public.
		 */
		public static function should_use_html_processor(): bool {
			if ( null !== self::$html_processor_available ) {
				return self::$html_processor_available;
			}
			if ( ! class_exists( 'WP_HTML_Processor' ) || ! method_exists( 'WP_HTML_Processor', 'serialize_token' ) ) {
				self::$html_processor_available = false;
				return false;
			}
			try {
				$method                         = new \ReflectionMethod( 'WP_HTML_Processor', 'serialize_token' );
				self::$html_processor_available = $method->isPublic();
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				self::$html_processor_available = false;
			}
			return self::$html_processor_available;
		}

		/**
		 * Reset the memoized HTML-processor availability probe.
		 *
		 * Same reset pattern as reset_cached_home_urls()/clear_settings_cache();
		 * used by the test suite so the reflection probe can be re-evaluated
		 * after HTML API class fixtures change.
		 *
		 * @since 2.0.0
		 * @return void
		 */
		public static function reset_html_processor_memo(): void {
			self::$html_processor_available = null;
		}

		/**
		 * Create a WP_HTML_Processor for full-buffer rewrites.
		 *
		 * Prefers `create_full_parser()` so a complete HTML document keeps its
		 * `<!DOCTYPE>`, `<html>`, `<head>` and `<body>` wrapper markup: a
		 * fragment parser (`create_fragment()`) drops those wrappers when it
		 * re-serializes a full document, which would corrupt page HTML. The
		 * fragment factory is only a last-resort fallback for cores that ship
		 * the public serializer without the full-parser factory.
		 *
		 * Returns null (triggering the caller's byte-identical fallback path)
		 * when the processor is unavailable, the factory fails, or the input
		 * cannot be parsed.
		 *
		 * @since 2.0.0
		 *
		 * @param string $html HTML document or fragment.
		 * @return \WP_HTML_Processor|null Processor instance, or null on failure.
		 */
		public static function create_html_processor( string $html ): ?\WP_HTML_Processor {
			if ( ! self::should_use_html_processor() ) {
				return null;
			}

			if ( method_exists( 'WP_HTML_Processor', 'create_full_parser' ) ) {
				$processor = \WP_HTML_Processor::create_full_parser( $html );
			} elseif ( method_exists( 'WP_HTML_Processor', 'create_fragment' ) ) {
				$processor = \WP_HTML_Processor::create_fragment( $html );
			} else {
				return null;
			}
			if ( null === $processor || ! ( $processor instanceof \WP_HTML_Processor ) ) {
				return null;
			}
			// is_wp_error() is always defined in WP, but guard for unit-test
			// bootstraps that stub the HTML API without loading pluggable.
			if ( function_exists( 'is_wp_error' ) && is_wp_error( $processor ) ) {
				return null;
			}

			return $processor;
		}

		/**
		 * Whether the safe CSS combine/used-CSS fallback is enabled.
		 *
		 * Shared source of truth for the combine-CSS and used-CSS inject paths.
		 * Operator opt-out via `wppo_safe_css_combine_fallback` (default true).
		 * When false the legacy path is used without strict guards.
		 *
		 * @since 2.0.0
		 * @return bool True when fallback guards are active.
		 */
		public static function safe_css_fallback_enabled(): bool {
			/**
			 * Filters whether the safe CSS combine fallback is enabled.
			 *
			 * When true (default) the combine/inject paths verify the replacement
			 * payload is non-empty and the target file exists/readable before
			 * stripping original stylesheets, and fail-open to originals on error.
			 *
			 * @since 2.0.0
			 * @param bool $enabled Whether the safe fallback is enabled.
			 */
			return (bool) apply_filters( 'wppo_safe_css_combine_fallback', true );
		}

		/**
		 * Whether the post-purge last-good fallback is enabled.
		 *
		 * Shared gate for the purge-fallback path (retain `fallback.css` /
		 * `fallback.js` beside derived assets on purge; serve the fallback
		 * with a 302 on a miss under the cache path). Default off: when
		 * disabled the legacy hard-404-on-miss behavior is kept unchanged.
		 * The `WPPO_PURGE_FALLBACK` constant (when defined true) forces the
		 * fallback on even when the setting is off (fail-open kill-switch).
		 * Multisite-safe: reads the per-site `wppo_settings` option.
		 *
		 * @since 2.2.0
		 * @return bool True when purge-fallback retention/serving is active.
		 */
		public static function is_purge_fallback_enabled(): bool {
			$bid = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			if ( array_key_exists( $bid, self::$purge_fallback_memo ) ) {
				return self::$purge_fallback_memo[ $bid ];
			}
			try {
				if ( defined( 'WPPO_PURGE_FALLBACK' ) ) {
					$flag = filter_var( WPPO_PURGE_FALLBACK, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
					if ( true === $flag ) {
						self::$purge_fallback_memo[ $bid ] = true;
						return true;
					}
				}
				$settings = self::get_settings();
				$raw      = $settings['file_optimisation']['purgeFallbackEnabled'] ?? false;
				$enabled  = filter_var( $raw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				$enabled  = true === $enabled;
			} catch ( \Throwable $e ) {
				unset( $e );
				self::$purge_fallback_memo[ $bid ] = false;
				return false;
			}
			if ( ! function_exists( 'apply_filters' ) ) {
				self::$purge_fallback_memo[ $bid ] = $enabled;
				return $enabled;
			}
			try {
				/**
				 * Filters whether the post-purge last-good fallback is enabled.
				 *
				 * @since 2.2.0
				 * @param bool $enabled Whether the purge fallback is enabled.
				 */
				$filtered = apply_filters( 'wppo_purge_fallback_enabled', $enabled );
			} catch ( \Throwable $e ) {
				unset( $e );
				self::$purge_fallback_memo[ $bid ] = false;
				return false;
			}
			$normalized                        = filter_var( $filtered, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
			self::$purge_fallback_memo[ $bid ] = true === $normalized;
			return self::$purge_fallback_memo[ $bid ];
		}

		/**
		 * Map a derived asset path to its sibling last-good fallback path.
		 *
		 * `.css` bases map to `fallback.css` and `.js` bases to `fallback.js`
		 * in the same directory; viewport variants (`*.mobile.css`,
		 * `*.desktop.css` from used-CSS) map to their own
		 * `fallback.mobile.css` / `fallback.desktop.css` so a base-URL miss
		 * can never 302-serve variant-specific CSS (and vice versa — issue
		 * #1275 follow-up). Anything else (HTML, images, unknown) maps to
		 * `''`. A trailing `.gz`/`.br` sibling suffix is stripped before
		 * the extension check. Paths that already point at a fallback file
		 * map to `''` so retention/serving can never loop onto itself.
		 * Pure path math only — no filesystem or containment checks; callers
		 * re-check containment via their own validators.
		 *
		 * @since 2.2.0
		 * @param string $file_path Absolute derived-asset path.
		 * @return string Sibling fallback path, or '' when not applicable.
		 */
		public static function get_purge_fallback_path_for( string $file_path ): string {
			try {
				if ( '' === $file_path || false !== strpos( $file_path, "\0" ) || false !== strpos( $file_path, '..' ) ) {
					return '';
				}
				// Single-decode probe: reject encoded traversal/NUL that the
				// raw check above cannot see. Downstream containment remains
				// the authoritative guard; this only fails fast.
				$probe = rawurldecode( $file_path );
				if ( false !== strpos( $probe, "\0" ) || false !== strpos( $probe, '..' ) ) {
					return '';
				}
				if ( function_exists( 'wp_normalize_path' ) ) {
					$normalized = wp_normalize_path( $file_path );
				} else {
					$normalized = str_replace( '\\', '/', $file_path );
				}
				if ( '' === $normalized ) {
					return '';
				}
				// Reject drive-letter and UNC shapes (mirror
				// sanitize_cache_url_path()): absolute cache paths must be
				// POSIX-style; downstream containment is authoritative.
				if ( preg_match( '#^[a-zA-Z]:[\\\\/]#', $normalized ) || 0 === strpos( $normalized, '//' ) || 0 === strpos( $normalized, '\\\\' ) ) {
					return '';
				}
				$base = (string) preg_replace( '/\.(?:gz|br)$/i', '', $normalized );
				if ( '' === $base ) {
					return '';
				}
				$basename = strtolower( (string) preg_replace( '#^.*/#', '', $base ) );
				if (
					'fallback.css' === $basename || 'fallback.js' === $basename
					|| 'fallback.mobile.css' === $basename || 'fallback.desktop.css' === $basename
					|| 'fallback.mobile.js' === $basename || 'fallback.desktop.js' === $basename
				) {
					return '';
				}
				$variant = '';
				if ( preg_match( '/\.(mobile|desktop)\.css$/', $basename, $m ) ) {
					$variant = '.' . strtolower( $m[1] );
				} elseif ( preg_match( '/\.(mobile|desktop)\.js$/', $basename, $m ) ) {
					$variant = '.' . strtolower( $m[1] );
				}
				$ext = strtolower( (string) pathinfo( $base, PATHINFO_EXTENSION ) );
				if ( 'css' !== $ext && 'js' !== $ext ) {
					return '';
				}
				$dir = (string) preg_replace( '#/[^/]*$#', '', $base );
				if ( '' === $dir ) {
					return '';
				}
				return $dir . '/fallback' . $variant . '.' . $ext;
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Retain a last-good fallback copy before a derived file is purged.
		 *
		 * Single shared implementation behind `Cache::retain_purge_fallback()`
		 * and `Used_CSS::retain_purge_fallback()` (issue #1275 follow-up:
		 * the two copies had already diverged, so all retain fixes land
		 * here once). Order is deliberate: cheap pure-path reject first
		 * (non CSS/JS inputs skip containment stats entirely), then
		 * containment of base + fallback, then a cheap `size()` guard with
		 * a content-probe fallback only when the size is unknown — the
		 * `copy()` fast path never pays a full read. Compressed (`.gz`/`.br`)
		 * inputs are refused so gzip bytes can never poison `fallback.css`.
		 * Writes prefer atomic + `FS_CHMOD_FILE`. Never throws.
		 *
		 * @since 2.2.0
		 * @param object   $fs         Filesystem exposing exists()/size()/copy()/get_contents()/put_contents().
		 * @param callable $is_allowed Containment validator: fn( string $path ): bool.
		 * @param string   $file_path  The derived file about to be deleted.
		 * @return void
		 */
		public static function retain_purge_fallback_file( $fs, callable $is_allowed, string $file_path ): void {
			try {
				if ( ! self::is_purge_fallback_enabled() ) {
					return;
				}
				if ( '' === $file_path ) {
					return;
				}
				// Refuse compressed siblings outright: the mapper strips the
				// suffix, so retaining `index.css.gz` would copy gzip bytes
				// onto `fallback.css`.
				if ( preg_match( '/\.(?:gz|br)$/i', $file_path ) ) {
					return;
				}
				// Cheap pure-path reject before any containment stat.
				$fallback = self::get_purge_fallback_path_for( $file_path );
				if ( '' === $fallback ) {
					return;
				}
				try {
					if ( ! $is_allowed( $file_path ) || ! $is_allowed( $fallback ) ) {
						return;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					return;
				}
				if ( ! is_object( $fs ) || ! method_exists( $fs, 'exists' ) || ! $fs->exists( $file_path ) ) {
					return;
				}
				if ( method_exists( $fs, 'size' ) ) {
					try {
						$size = $fs->size( $file_path );
					} catch ( \Throwable $e ) {
						unset( $e );
						$size = null;
					}
					if ( false === $size || null === $size ) {
						// Stat failure (WP_Filesystem::size() signals via
						// false): probe non-emptiness so transient stat
						// failure neither drops last-good retention nor
						// lets an empty base overwrite a good fallback.
						if ( method_exists( $fs, 'get_contents' ) ) {
							try {
								$probe = $fs->get_contents( $file_path );
							} catch ( \Throwable $ignored ) {
								unset( $ignored );
								$probe = null;
							}
							if ( ! is_string( $probe ) || '' === $probe ) {
								return;
							}
						}
					} elseif ( (int) $size <= 0 ) {
						return;
					}
				}
				if ( method_exists( $fs, 'copy' ) ) {
					try {
						$fs->copy( $file_path, $fallback, true );
						return;
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( ! method_exists( $fs, 'get_contents' ) || ! method_exists( $fs, 'put_contents' ) ) {
					return;
				}
				$contents = $fs->get_contents( $file_path );
				if ( ! is_string( $contents ) || '' === $contents ) {
					return;
				}
				if ( method_exists( self::class, 'atomic_file_put_contents' ) ) {
					self::atomic_file_put_contents( $fs, $fallback, $contents );
					return;
				}
				$fs->put_contents( $fallback, $contents, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Whether a retained fallback file holds a servable payload.
		 *
		 * Shared validator behind the purge-fallback resolvers: prefers the
		 * cheap `size() > 0` stat and reads the body only when `size()` is
		 * unavailable, so a fallback hit never pays a full-file read it can
		 * avoid. Never throws.
		 *
		 * @since 2.2.0
		 * @param object $fs       Filesystem exposing size()/get_contents().
		 * @param string $fallback Absolute fallback path.
		 * @return bool True when the fallback exists with non-empty content.
		 */
		public static function is_purge_fallback_payload_valid( $fs, string $fallback ): bool {
			try {
				if ( ! is_object( $fs ) || '' === $fallback ) {
					return false;
				}
				if ( method_exists( $fs, 'size' ) ) {
					try {
						return (int) $fs->size( $fallback ) > 0;
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				if ( method_exists( $fs, 'get_contents' ) ) {
					try {
						$contents = $fs->get_contents( $fallback );
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
					return is_string( $contents ) && '' !== $contents;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return false;
		}

		/**
		 * Global per-day throttle for purge-fallback serve logging.
		 *
		 * A single blog-prefixed transient (`wppo_purge_fallback_served`)
		 * gates all fallback-serve log rows (both Cache and used-CSS share
		 * it) so a post-purge miss storm writes one row per day instead of
		 * one per directory. Returns true when the caller should log.
		 * Fail-open: logging failures never affect serving. Never throws.
		 *
		 * @since 2.2.0
		 * @return bool True when the caller should write its log row.
		 */
		public static function purge_fallback_should_log(): bool {
			try {
				$key = self::transient_key( 'wppo_purge_fallback_served' );
				if ( function_exists( 'get_transient' ) && get_transient( $key ) ) {
					return false;
				}
				if ( function_exists( 'set_transient' ) ) {
					$ttl = defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
					set_transient( $key, 1, $ttl );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether a generated CSS file is valid (exists, readable, non-empty).
		 *
		 * Shared validator for combined-CSS and used-CSS sidecar files. The stat
		 * cache is cleared for the exact path so a file written earlier in the
		 * same request is never judged stale.
		 *
		 * @since 2.0.0
		 * @param string $path Absolute path to the CSS file.
		 * @return bool True when the file is usable.
		 */
		public static function css_file_valid( string $path ): bool {
			if ( '' !== $path ) {
				clearstatcache( true, $path );
			}
			return '' !== $path && is_file( $path ) && is_readable( $path ) && filesize( $path ) > 0;
		}

		/**
		 * Log a guarded CSS fallback (combine or used-CSS) event with throttling.
		 *
		 * Per-reason transient throttling (DAY_IN_SECONDS) prevents the log from
		 * growing per pageview on persistent failures, without a process-wide
		 * static flag (so a later request failure in the same process is still
		 * observable).
		 *
		 * @since 2.0.0
		 * @param string $reason  Machine-readable reason code (empty_payload, write_failure, head_match_failure, ...).
		 * @param array  $handles Handles preserved by the fallback.
		 * @param string $context 'combine' or 'usedcss' — selects the log-key prefix and message.
		 * @return void
		 */
		public static function log_css_fallback( string $reason, array $handles, string $context ): void {
			if ( ! in_array( $context, array( 'combine', 'usedcss' ), true ) ) {
				$context = 'combine';
			}

			if ( ! class_exists( Log::class ) ) {
				return;
			}

			$count  = count( $handles );
			$reason = sanitize_key( $reason );
			if ( '' === $reason ) {
				$reason = 'unknown';
			}

			$log_key = self::transient_key( 'wppo_' . $context . '_fallback_' . md5( $reason . '|' . implode( ',', $handles ) ) );
			if ( get_transient( $log_key ) ) {
				return;
			}
			set_transient( $log_key, 1, DAY_IN_SECONDS );

			$message = ( 'usedcss' === $context )
				? /* translators: %1$s: reason, %2$d: number of stylesheets preserved. */ __( 'Used-CSS fallback: %1$s — preserved %2$d stylesheet(s) (served originals).', 'performance-optimisation' )
				: /* translators: %1$s: reason, %2$d: number of stylesheets preserved. */ __( 'CSS combine fallback: %1$s — preserved %2$d stylesheet(s) (served originals).', 'performance-optimisation' );

			Log::add(
				sprintf(
					$message,
					$reason,
					$count
				)
			);
		}

		/**
		 * Whether the current runtime deprecates explicit handle-close calls (PHP 8.5+).
		 *
		 * PHP 8.5 deprecates the former resource-teardown no-ops `curl_close()`,
		 * `curl_share_close()`, `finfo_close()`, `xml_parser_free()` and
		 * `imagedestroy()` (see wiki.php.net/rfc/deprecations_php_8_5): on 8.5+
		 * handles are released by dropping the reference instead of calling the
		 * close function. Below 8.5 the legacy close path is kept unchanged.
		 * Note: upstream deprecates `curl_close()` + `curl_share_close()`
		 * only — not `curl_multi_close()` — but `close_curl_multi_handle()`
		 * is over-gated the same way for symmetry (fail-open either way).
		 *
		 * The optional $php_version parameter exists so PHPUnit (Brain Monkey)
		 * can exercise both sides of the gate without redefining PHP_VERSION.
		 *
		 * @since 2.0.0
		 * @param string|null $php_version Optional version string for testing; defaults to PHP_VERSION.
		 * @return bool True on PHP 8.5+, false below.
		 */
		public static function is_php85_or_greater( ?string $php_version = null ): bool {
			static $cached = null;
			if ( null === $php_version && null !== $cached ) {
				return $cached;
			}
			$version = $php_version ?? PHP_VERSION;
			$result  = version_compare( $version, '8.5', '>=' );
			if ( null === $php_version ) {
				$cached = $result;
			}
			return $result;
		}

		/**
		 * Release a cURL handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the handle reference is dropped (null + unset) instead of
		 * calling `curl_close()`; below 8.5 the legacy `curl_close()` path runs
		 * unchanged. Fail-open: when `curl_close()` is unavailable the reference
		 * is dropped on every runtime. Multisite-safe: no option/cache changes.
		 *
		 * Note: the handle is passed by reference and nulled (not only unset)
		 * because `unset()` of a by-reference parameter would leave the caller's
		 * variable untouched; assigning null releases the CurlHandle object in
		 * the caller scope on every supported runtime.
		 *
		 * @since 2.0.0
		 * @param mixed       $ch          cURL handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_curl_handle( &$ch, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$ch = null;
				unset( $ch );
				return;
			}
			if ( function_exists( 'curl_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated,WordPress.WP.AlternativeFunctions.curl_curl_close -- legacy close path below PHP 8.5 only.
				curl_close( $ch );
				return;
			}
			$ch = null;
			unset( $ch );
		}

		/**
		 * Release a cURL multi handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the multi handle reference is dropped (null + unset)
		 * instead of calling `curl_multi_close()`; below 8.5 the legacy
		 * `curl_multi_close()` path runs unchanged. Fail-open: when
		 * `curl_multi_close()` is unavailable the reference is dropped on
		 * every runtime. Multisite-safe: no option/cache changes.
		 *
		 * @since 2.0.0
		 * @param mixed       $mh          cURL multi handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_curl_multi_handle( &$mh, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$mh = null;
				unset( $mh );
				return;
			}
			if ( function_exists( 'curl_multi_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated,WordPress.WP.AlternativeFunctions.curl_curl_multi_close -- legacy close path below PHP 8.5 only.
				curl_multi_close( $mh );
				return;
			}
			$mh = null;
			unset( $mh );
		}

		/**
		 * Release a GD image without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the image reference is dropped (null + unset) instead of
		 * calling `imagedestroy()`; below 8.5 the legacy `imagedestroy()` path
		 * runs unchanged. Fail-open: when `imagedestroy()` is unavailable the
		 * reference is dropped on every runtime. Multisite-safe: no
		 * option/cache changes.
		 *
		 * @since 2.0.0
		 * @param mixed       $image       GD image to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function destroy_gd_image( &$image, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$image = null;
				unset( $image );
				return;
			}
			if ( function_exists( 'imagedestroy' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- legacy destroy path below PHP 8.5 only.
				imagedestroy( $image );
				return;
			}
			$image = null;
			unset( $image );
		}

		/**
		 * Release a cURL share handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the share handle reference is dropped (null + unset)
		 * instead of calling `curl_share_close()`; below 8.5 the legacy
		 * `curl_share_close()` path runs unchanged. Fail-open: when
		 * `curl_share_close()` is unavailable the reference is dropped on
		 * every runtime. Multisite-safe: no option/cache changes.
		 *
		 * Note: completes the teardown-helper set promised above; no
		 * production call sites use curl_share handles yet, so this is
		 * forward-compat API for future callers.
		 *
		 * @since 2.2.0
		 * @param mixed       $sh          cURL share handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_curl_share_handle( &$sh, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$sh = null;
				unset( $sh );
				return;
			}
			if ( function_exists( 'curl_share_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated,WordPress.WP.AlternativeFunctions.curl_curl_share_close -- legacy close path below PHP 8.5 only.
				curl_share_close( $sh );
				return;
			}
			$sh = null;
			unset( $sh );
		}

		/**
		 * Release a finfo handle without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the finfo instance reference is dropped (null + unset)
		 * instead of calling `finfo_close()`; below 8.5 the legacy
		 * `finfo_close()` path runs unchanged. Fail-open: when `finfo_close()`
		 * is unavailable the reference is dropped on every runtime.
		 * Multisite-safe: no option/cache changes.
		 *
		 * Note: completes the teardown-helper set promised above; no
		 * production call sites use finfo handles yet, so this is
		 * forward-compat API for future callers.
		 *
		 * @since 2.2.0
		 * @param mixed       $finfo       Finfo handle to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function close_finfo_handle( &$finfo, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$finfo = null;
				unset( $finfo );
				return;
			}
			if ( function_exists( 'finfo_close' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- legacy close path below PHP 8.5 only.
				finfo_close( $finfo );
				return;
			}
			$finfo = null;
			unset( $finfo );
		}

		/**
		 * Release an XML parser without triggering the PHP 8.5 deprecation.
		 *
		 * On PHP 8.5+ the parser reference is dropped (null + unset) instead
		 * of calling `xml_parser_free()`; below 8.5 the legacy
		 * `xml_parser_free()` path runs unchanged. Fail-open: when
		 * `xml_parser_free()` is unavailable the reference is dropped on
		 * every runtime. Multisite-safe: no option/cache changes.
		 *
		 * Note: completes the teardown-helper set promised above; no
		 * production call sites use XML parsers yet, so this is
		 * forward-compat API for future callers.
		 *
		 * @since 2.2.0
		 * @param mixed       $parser      XML parser to release (nulled in the caller scope).
		 * @param string|null $php_version Optional version override for testing; defaults to PHP_VERSION.
		 * @return void
		 */
		public static function free_xml_parser( &$parser, ?string $php_version = null ): void {
			if ( self::is_php85_or_greater( $php_version ) ) {
				$parser = null;
				unset( $parser );
				return;
			}
			if ( function_exists( 'xml_parser_free' ) ) {
				// phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated -- legacy free path below PHP 8.5 only.
				xml_parser_free( $parser );
				return;
			}
			$parser = null;
			unset( $parser );
		}

		/**
		 * Return the last HTTP response headers without touching the deprecated global.
		 *
		 * PHP 8.5 deprecates reading the `$http_response_header` global in
		 * favour of `http_get_last_response_headers()` (see
		 * https://www.php.net/manual/en/migration85.deprecated.php). This
		 * wrapper prefers the new engine API when available
		 * (`function_exists` guard) and falls back to the legacy global path
		 * — always initialized and guarded with `isset` — so behaviour on
		 * PHP 8.2 through 8.4 is unchanged. Fail-open: any probe failure
		 * returns an empty array. Multisite-safe: no option/cache changes.
		 *
		 * Note: the legacy fallback reads the headers via the `$GLOBALS`
		 * array with an `isset` guard (never a bare global read), which keeps
		 * the deprecation scanner clean while preserving the 8.2–8.4 path.
		 *
		 * Scope limitation (PHP 8.2–8.4 legacy path): the engine populates
		 * the header store in the local scope of the caller that made the
		 * HTTP-wrapper request, so this static helper only observes headers
		 * for requests made in global scope. Callers inside a
		 * function/method scope should pass their headers explicitly via
		 * `$legacy_source` (best-effort only).
		 *
		 * When the new engine API exists its result is authoritative: a
		 * non-array probe (null/false, i.e. no headers in this scope) is
		 * returned as an empty array instead of consulting the legacy
		 * store, which may hold stale headers from an earlier request
		 * (notably in long-lived PHP-FPM workers).
		 *
		 * @since 2.2.0
		 * @since 2.2.0 `$legacy_source` parameter for the scope-blind legacy path.
		 * @param array|null $legacy_source Optional explicit header lines for the
		 *                                  legacy path (string-filtered).
		 * @return string[] List of response header lines, or empty array when unavailable.
		 */
		public static function get_last_response_headers( ?array $legacy_source = null ): array {
			$headers = array();

			if ( function_exists( 'http_get_last_response_headers' ) ) {
				try {
					$probe = http_get_last_response_headers();
					if ( is_array( $probe ) ) {
						foreach ( array_values( $probe ) as $line ) {
							if ( is_string( $line ) ) {
								$headers[] = $line;
							}
						}
					}
					// Authoritative: a non-array probe means "no headers in
					// this scope" — never fall through to the legacy store,
					// which may hold stale headers from an earlier request.
					return $headers;
				} catch ( \Throwable $e ) {
					unset( $e );
				}
			}

			$legacy = $legacy_source;
			if ( null === $legacy && isset( $GLOBALS['http_response_header'] ) && is_array( $GLOBALS['http_response_header'] ) ) {
				$legacy = $GLOBALS['http_response_header'];
			}

			try {
				if ( is_array( $legacy ) ) {
					foreach ( array_values( $legacy ) as $line ) {
						if ( is_string( $line ) ) {
							$headers[] = $line;
						}
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}

			return $headers;
		}

		/**
		 * Read core's `styles_inline_size_limit` budget.
		 *
		 * Single source of truth shared by Cache and Critical_CSS so their
		 * inline-budget accounting cannot diverge. Core's default is
		 * version-dependent: 20KB before WP 6.9, 40KB on 6.9+. The
		 * `'6.9-alpha'` comparator is used so alpha/beta/RC builds of 6.9
		 * already report the raised default (the raised budget shipped in the
		 * 6.9 development cycle). Site-level overrides through the
		 * `styles_inline_size_limit` filter always win. An absent
		 * `$GLOBALS['wp_version']` assumes the newest default.
		 *
		 * @since 2.0.0
		 * @return int The inline size limit in bytes.
		 */
		public static function get_styles_inline_limit(): int {
			$default = 40000;
			if ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '6.9-alpha', '<' ) ) {
				$default = 20000;
			}
			return (int) apply_filters( 'styles_inline_size_limit', $default );
		}

		/**
		 * Unfiltered core inline default for the invalid-limit fallback (issue #1462).
		 *
		 * Mirrors the version-dependent default in get_styles_inline_limit()
		 * without running the `styles_inline_size_limit` filter, so the
		 * invalid-limit path never invokes the filter twice per call.
		 *
		 * @return int The default inline size limit in bytes.
		 * @since NEXT
		 */
		public static function get_styles_inline_default(): int {
			$default = 40000;
			if ( isset( $GLOBALS['wp_version'] ) && version_compare( (string) $GLOBALS['wp_version'], '6.9-alpha', '<' ) ) {
				$default = 20000;
			}
			return $default;
		}

		/**
		 * Bytes committed to inline `<style>` output so far on this request (issue #1462).
		 *
		 * Request-global ledger so the per-URL used-CSS pipeline and the
		 * per-template critical-CSS pipeline share one core
		 * `styles_inline_size_limit` budget instead of each inlining up to the
		 * full limit. The used-CSS pipeline ships as an external file in its
		 * default delivery mode (file/async/delay/remove — never inline), so
		 * the ledger stays 0 unless a future inline emitter records bytes via
		 * add_committed_inline_bytes(); Critical_CSS::inline_ccss() records
		 * every block it inlines. Read via get_committed_inline_bytes() or
		 * Critical_CSS::estimate_committed_inline_bytes().
		 *
		 * Multisite-safe: request memory only, no storage.
		 *
		 * @since NEXT
		 * @var int
		 */
		private static int $committed_inline_bytes = 0;

		/**
		 * Record bytes just inlined on this request (issue #1462).
		 *
		 * Called by inline emitters after printing a `<style>` block so later
		 * emitters in the same request see the reduced remainder. Fail-open:
		 * non-positive values are ignored, never fatal.
		 *
		 * Multisite-safe: request memory only.
		 *
		 * @param int $bytes Bytes just committed to inline output.
		 * @return void
		 * @since NEXT
		 */
		public static function add_committed_inline_bytes( int $bytes ): void {
			try {
				if ( $bytes > 0 ) {
					self::$committed_inline_bytes += $bytes;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Bytes committed to inline output so far on this request (issue #1462).
		 *
		 * Multisite-safe: request memory only.
		 *
		 * @return int Committed inline bytes (>= 0).
		 * @since NEXT
		 */
		public static function get_committed_inline_bytes(): int {
			try {
				return self::$committed_inline_bytes > 0 ? (int) self::$committed_inline_bytes : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Reset the request-global committed-bytes ledger (unit tests).
		 *
		 * Production requests never need this (one request = one ledger);
		 * tests that simulate multiple requests in one process must call it
		 * between cases.
		 *
		 * @return void
		 * @since NEXT
		 */
		public static function reset_committed_inline_bytes(): void {
			self::$committed_inline_bytes = 0;
		}

		/**
		 * Remaining inline budget after bytes already committed (issue #1462).
		 *
		 * Pure helper so per-URL used CSS and per-template critical CSS can
		 * share one budget instead of each inlining up to the full core
		 * `styles_inline_size_limit`. Fail-open: any uncertainty returns the
		 * full limit minus the committed bytes, never fatal.
		 *
		 * Multisite-safe: no storage, per-call arithmetic only.
		 *
		 * @param int      $already_inlined Bytes already committed to inline output on this request.
		 * @param int|null $limit Optional budget override (defaults to get_styles_inline_limit()).
		 * @return int Remaining bytes available for inline output (>= 0).
		 * @since NEXT
		 */
		public static function get_remaining_inline_budget( int $already_inlined = 0, ?int $limit = null ): int {
			try {
				// Resolve the limit once: get_styles_inline_limit() runs the
				// `styles_inline_size_limit` filter, so the invalid-limit path
				// falls back to the unfiltered default instead of invoking
				// the filter a second time per call.
				$budget = null === $limit ? self::get_styles_inline_limit() : (int) $limit;
				if ( $budget <= 0 ) {
					$budget = self::get_styles_inline_default();
				}
				$remaining = $budget - max( 0, $already_inlined );
				return $remaining > 0 ? (int) $remaining : 0;
			} catch ( \Throwable $e ) {
				unset( $e );
				return 0;
			}
		}

		/**
		 * Split CSS into an inline prefix and a deferred remainder (issue #1462).
		 *
		 * Inlines exactly what fits inside the core `styles_inline_size_limit`
		 * budget (40KB on WP 6.9+, 20KB before) and leaves the remainder for
		 * a deferred external stylesheet, so first visits inline without
		 * render-blocking overflow and repeat visits use the cacheable asset.
		 * The cut lands on the last top-level closing brace at or under the
		 * limit (brace depth tracked so an `@media`/`@supports`/`@layer`
		 * block is never left unclosed) so output never ends mid-rule.
		 * Fail-open: CSS failures degrade to unoptimised output (full CSS
		 * deferred), never fatal or white-screen.
		 *
		 * @param string   $css CSS content to split.
		 * @param int|null $limit Optional budget override (defaults to get_styles_inline_limit()).
		 * @return array{inline: string, deferred: string} Inline prefix and deferred remainder.
		 * @since NEXT
		 */
		public static function split_css_for_inline_budget( string $css, ?int $limit = null ): array {
			try {
				if ( '' === $css ) {
					return array(
						'inline'   => '',
						'deferred' => '',
					);
				}
				// Resolve the limit once (see get_remaining_inline_budget()).
				$budget = null === $limit ? self::get_styles_inline_limit() : (int) $limit;
				if ( $budget <= 0 ) {
					$budget = self::get_styles_inline_default();
				}
				if ( strlen( $css ) <= $budget ) {
					return array(
						'inline'   => $css,
						'deferred' => '',
					);
				}
				$cut = self::find_top_level_css_cut( $css, $budget );
				if ( null === $cut ) {
					return array(
						'inline'   => '',
						'deferred' => $css,
					);
				}
				return array(
					'inline'   => substr( $css, 0, $cut + 1 ),
					'deferred' => substr( $css, $cut + 1 ),
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'inline'   => '',
					'deferred' => $css,
				);
			}
		}

		/**
		 * Offset of the last top-level `}` at or under the budget (issue #1462).
		 *
		 * Walks the candidate prefix tracking `{`/`}` depth and keeps the last
		 * closing brace seen at depth 0, so cutting there can never leave an
		 * `@media`/`@supports`/`@layer` wrapper unclosed. Returns null when no
		 * top-level boundary fits (callers defer everything).
		 *
		 * Braces inside quoted strings (e.g. content with a brace character)
		 * and CSS comments are skipped while scanning so they can neither open a
		 * phantom block nor close a real one; callers stay fail-open (a
		 * missed cut defers everything, never emits broken CSS).
		 *
		 * @param string $css CSS content.
		 * @param int    $budget Maximum bytes for the inline prefix.
		 * @return int|null Offset of the cut brace, or null when nothing fits.
		 * @since NEXT
		 */
		private static function find_top_level_css_cut( string $css, int $budget ): ?int {
			try {
				$prefix = substr( $css, 0, $budget );
				if ( '' === $prefix ) {
					return null;
				}
				$depth      = 0;
				$cut        = null;
				$len        = strlen( $prefix );
				$in_single  = false;
				$in_double  = false;
				$in_comment = false;
				for ( $i = 0; $i < $len; $i++ ) {
					$c    = $prefix[ $i ];
					$next = $i + 1 < $len ? $prefix[ $i + 1 ] : '';
					if ( $in_comment ) {
						if ( '*' === $c && '/' === $next ) {
							$in_comment = false;
							++$i;
						}
						continue;
					}
					if ( $in_single ) {
						if ( '\\' === $c ) {
							++$i;
							continue;
						}
						if ( "'" === $c ) {
							$in_single = false;
						}
						continue;
					}
					if ( $in_double ) {
						if ( '\\' === $c ) {
							++$i;
							continue;
						}
						if ( '"' === $c ) {
							$in_double = false;
						}
						continue;
					}
					if ( '/' === $c && '*' === $next ) {
						$in_comment = true;
						++$i;
						continue;
					}
					if ( "'" === $c ) {
						$in_single = true;
						continue;
					}
					if ( '"' === $c ) {
						$in_double = true;
						continue;
					}
					if ( '{' === $c ) {
						++$depth;
					} elseif ( '}' === $c ) {
						if ( $depth > 0 ) {
							--$depth;
							if ( 0 === $depth ) {
								$cut = $i;
							}
						} else {
							// Stray closing brace at depth 0 still ends a rule.
							$cut = $i;
						}
					}
				}
				return $cut;
			} catch ( \Throwable $e ) {
				unset( $e );
				return null;
			}
		}
	}
}
