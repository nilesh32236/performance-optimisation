<?php
/**
 * Settings store boundary — wppo_settings memo, writes, hook coherence, snapshots,
 * schema allowlist, and settings sanitization.
 *
 * Focused extraction (REF-002) of the settings-state responsibility cluster
 * previously owned by the god utility `Util`. Owns the per-request
 * `get_option('wppo_settings')` memo (blog-keyed for multisite correctness
 * under `switch_to_blog()`), the `save_settings()` write-through, the
 * `update/add/delete_option_wppo_settings` hook coherence callbacks, and the
 * one-click-undo settings snapshots. `Util::get_settings()` and friends remain
 * as thin facade proxies so all existing callers keep working untouched.
 *
 * Minimal WordPress APIs: `get_option()`/`update_option()`, `add_action()`,
 * `get_current_blog_id()` for multisite memo keying, the core sanitizers
 * (`sanitize_text_field()`, `sanitize_textarea_field()`, `esc_url_raw()`,
 * `absint()`), and `apply_filters()` for the TTL/CDN mapping filters.
 *
 * Owns the settings schema allowlist (`ALLOWED_SETTINGS_KEYS` /
 * `ALLOWED_SETTINGS_TABS`, `get_allowed_settings_keys()`), the per-tab
 * sanitizer map (`get_settings_sanitizer_map()`), and the `sanitize_*`
 * family, moved from the god utility `Util` (REF-011). `Util::` keeps
 * thin facade proxies/aliases so existing callers keep working untouched.
 * P3-013 also owns the effective-options read policy: stored settings over
 * canonical defaults plus every historical in-memory compatibility backfill.
 * Main retains only its public, blog-aware compatibility facade and local
 * injectable snapshot needed by existing collaborators/migrations.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Settings_Store' ) ) {
	/**
	 * Class Settings_Store
	 *
	 * Static settings-state and settings-schema owner. Uses only minimal
	 * WordPress APIs and owns the canonical fresh-install defaults. Effective
	 * settings resolution and invalidation remain bounded here; `Main` is a
	 * compatibility facade and orchestrator.
	 *
	 * @since 2.4.0
	 */
	final class Settings_Store {

		/**
		 * Option name storing the single prior copy of `wppo_settings` for one-click undo.
		 *
		 * Written by {@see self::take_settings_snapshot()} before every settings
		 * save (REST `update_settings`/`import_settings`, WP-CLI
		 * `settings update`/`import`) and consumed by
		 * {@see self::restore_settings_snapshot()}. Multisite-safe: stored via
		 * plain per-site `get_option()`/`update_option()`, never network-wide.
		 *
		 * Mirrored as `Util::SETTINGS_SNAPSHOT_OPTION` for backward
		 * compatibility; the two values must stay identical.
		 *
		 * @since 2.4.0
		 * @var string
		 */
		public const SETTINGS_SNAPSHOT_OPTION = 'wppo_settings_snapshot';

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
		 * @since 2.4.0 Canonical owner (REF-011); Util:: keeps a facade alias.
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
		 * @since 2.4.0 Canonical owner (REF-011); Util:: keeps a facade alias.
		 * @var string[]
		 */
		public const ALLOWED_SETTINGS_TABS = self::ALLOWED_SETTINGS_KEYS;

		/**
		 * Get the allowlisted top-level settings keys.
		 *
		 * @since 2.0.0
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
		 * @return string[]
		 */
		public static function get_allowed_settings_keys(): array {
			return self::ALLOWED_SETTINGS_KEYS;
		}

		/**
		 * Sanitize the LiteSpeed integration `mode` value against its allowlist.
		 *
		 * Per-tab sanitizer extracted from {@see sanitize_settings_recursively()}
		 * so the allowlist lives in one place with its own unit test.
		 *
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * Advisory schema inventory, not a directly dispatchable table:
		 * entries naming sanitize_scalar_setting share signature
		 * (string $safe_key, $value) and are NOT invocable per-tab as
		 * (array $settings). sanitize_settings_recursively() uses hardcoded
		 * branches rather than consulting this map. Every tab in
		 * {@see get_default_settings()} must appear here so a new tab key
		 * can never be silently dropped or stored unsanitized.
		 *
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.4.0 Moved from Util (REF-011); behavior unchanged.
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
		 * Get the canonical default settings structure.
		 *
		 * Owns fresh-install defaults used by activation, CLI, settings reads,
		 * and schema verification. Util::get_default_settings() remains the
		 * backward-compatible facade.
		 *
		 * @since NEXT
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
					'anomaly_band_window'           => 10,
					'anomaly_recovery_days'         => 3,
					'deploy_notes'                  => array(),
				),
				'edge_cache'            => array(
					'enabled' => false,
				),
			);
		}

		/**
		 * Per-request resolved-options memo keyed by blog ID.
		 *
		 * Stores the effective options (canonical defaults or stored settings,
		 * followed by historical in-memory backfills). Kept separate from the
		 * raw option memo so add/update/delete hooks can invalidate the resolved
		 * policy without losing the raw write-through value.
		 *
		 * @var array<int, array>
		 * @since NEXT
		 */
		private static array $resolved_settings_cache = array();

		/**
		 * Blog IDs with a populated resolved-options memo.
		 *
		 * @var array<int, bool>
		 * @since NEXT
		 */
		private static array $resolved_settings_cache_loaded = array();

		/**
		 * Get the effective options for the current blog.
		 *
		 * Stored settings replace the canonical defaults exactly as the historic
		 * Main resolver did, then every compatibility backfill is applied in
		 * memory. No option write occurs on this read path. The result is
		 * memoized per blog ID for switch_to_blog() isolation.
		 *
		 * @since NEXT
		 * @return array Effective settings for the current blog.
		 */
		public static function get_resolved_settings(): array {
			$blog_id = self::current_blog_id();
			if ( ! empty( self::$resolved_settings_cache_loaded[ $blog_id ] ) ) {
				return self::$resolved_settings_cache[ $blog_id ] ?? array();
			}

			$options = self::get_default_settings();
			$stored  = self::get_settings();
			if ( ! empty( $stored ) ) {
				$options = $stored;
			}

			// WooCommerce safe mode (issue #1383): defensive in-memory parity
			// with the canonical defaults (wooSafeMode defaults to on).
			if ( ! isset( $options['cache_settings'] ) || ! is_array( $options['cache_settings'] ) ) {
				$options['cache_settings'] = array();
			}
			if ( ! isset( $options['cache_settings']['wooSafeMode'] ) ) {
				$options['cache_settings']['wooSafeMode'] = true;
			}
			// WP 6.9+ loads core block assets on demand in classic themes by default.
			if ( function_exists( 'wp_load_classic_theme_block_styles_on_demand' ) ) {
				if ( ! isset( $options['file_optimisation'] ) || ! is_array( $options['file_optimisation'] ) ) {
					$options['file_optimisation'] = array();
				}
				if ( ! isset( $options['file_optimisation']['blockAssetsOnDemand'] ) ) {
					$options['file_optimisation']['blockAssetsOnDemand'] = true;
				}
			}
			// Native lazy loading is the default path.
			if ( ! isset( $options['image_optimisation'] ) || ! is_array( $options['image_optimisation'] ) ) {
				$options['image_optimisation'] = array();
			}
			if ( ! isset( $options['image_optimisation']['lazyLoadNative'] ) ) {
				$options['image_optimisation']['lazyLoadNative'] = true;
			}
			if ( ! isset( $options['image_optimisation']['lazyLoadImages'] ) ) {
				$options['image_optimisation']['lazyLoadImages'] = false;
			}
			if ( ! isset( $options['image_optimisation']['avifFirst'] ) ) {
				$options['image_optimisation']['avifFirst'] = true;
			}
			if ( ! isset( $options['image_optimisation']['smartQuality'] ) ) {
				$options['image_optimisation']['smartQuality'] = true;
			}
			if ( ! isset( $options['image_optimisation']['skipSmallThresholdBytes'] ) ) {
				$options['image_optimisation']['skipSmallThresholdBytes'] = 5120;
			}
			if ( ! isset( $options['image_optimisation']['discardOversizedSibling'] ) ) {
				$options['image_optimisation']['discardOversizedSibling'] = true;
			}
			if ( ! isset( $options['image_optimisation']['lcpHeroPreload'] ) ) {
				$options['image_optimisation']['lcpHeroPreload'] = true;
			}
			if ( ! isset( $options['image_optimisation']['lcp_guardrails'] ) ) {
				$options['image_optimisation']['lcp_guardrails'] = true;
			}
			if ( ! isset( $options['image_optimisation']['lcp_first_n'] ) ) {
				$options['image_optimisation']['lcp_first_n'] = 3;
			}
			if ( ! isset( $options['image_optimisation']['autoAltText'] ) ) {
				$options['image_optimisation']['autoAltText'] = false;
			}
			if ( ! isset( $options['image_optimisation']['maxLongestEdgePx'] ) ) {
				$options['image_optimisation']['maxLongestEdgePx'] = 2560;
			}
			if ( ! isset( $options['image_optimisation']['lazyRenderBelowFold'] ) ) {
				$options['image_optimisation']['lazyRenderBelowFold'] = false;
			}
			if ( ! isset( $options['image_optimisation']['lazyRenderExcludeBuilders'] ) ) {
				$options['image_optimisation']['lazyRenderExcludeBuilders'] = true;
			}
			// Comment-image hardening (issue #1271).
			if ( ! isset( $options['image_optimisation']['hardenCommentImages'] ) ) {
				$options['image_optimisation']['hardenCommentImages'] = true;
			}
			// Occlusion-aware fetchpriority=low (issue #1426).
			if ( ! isset( $options['image_optimisation']['occlusionFetchpriorityLow'] ) ) {
				$options['image_optimisation']['occlusionFetchpriorityLow'] = false;
			}
			if ( ! isset( $options['file_optimisation'] ) || ! is_array( $options['file_optimisation'] ) ) {
				$options['file_optimisation'] = array();
			}
			if ( ! isset( $options['file_optimisation']['delayJSSafeMode'] ) ) {
				$options['file_optimisation']['delayJSSafeMode'] = true;
			}
			// Auto third-party delay (issue #1314).
			if ( ! isset( $options['file_optimisation']['delayJSThirdPartyAuto'] ) ) {
				$options['file_optimisation']['delayJSThirdPartyAuto'] = false;
			}
			// One-click Delay-JS preset level (issue #1385).
			if ( ! isset( $options['file_optimisation']['delayJSPreset'] ) || ! in_array( strtolower( trim( (string) $options['file_optimisation']['delayJSPreset'] ) ), array( 'safe', 'balanced', 'aggressive' ), true ) ) {
				$options['file_optimisation']['delayJSPreset'] = 'safe';
			}
			// Unified safe-mode kill switch (issue #1098).
			if ( ! isset( $options['file_optimisation']['safeMode'] ) ) {
				$options['file_optimisation']['safeMode'] = false;
			}
			// Elementor-safe mode (issue #1259).
			if ( ! isset( $options['file_optimisation']['elementorSafeMode'] ) ) {
				$options['file_optimisation']['elementorSafeMode'] = true;
			}
			// Sandbox preview staged values (issue #1163).
			if ( ! isset( $options['file_optimisation']['sandboxStaged'] ) || ! is_array( $options['file_optimisation']['sandboxStaged'] ) ) {
				$options['file_optimisation']['sandboxStaged'] = array();
			}
			// Font subsetting opt-in (issue #1145).
			if ( ! isset( $options['file_optimisation']['fontSubset'] ) ) {
				$options['file_optimisation']['fontSubset'] = false;
			}
			if ( ! isset( $options['file_optimisation']['fontSubsetSubsets'] ) ) {
				$options['file_optimisation']['fontSubsetSubsets'] = 'latin';
			}
			// Builder CSS drift purge watcher (issue #1288).
			if ( ! isset( $options['file_optimisation']['builderPurgeWatcher'] ) ) {
				$options['file_optimisation']['builderPurgeWatcher'] = true;
			}
			if ( ! isset( $options['file_optimisation']['builderPurgeDriftLog'] ) ) {
				$options['file_optimisation']['builderPurgeDriftLog'] = true;
			}
			// Speculation-rules backfills (issues #1061, #1183, #1237, #1215).
			if ( ! isset( $options['preload_settings'] ) || ! is_array( $options['preload_settings'] ) ) {
				$options['preload_settings'] = array();
			}
			if ( ! isset( $options['preload_settings']['speculationRumGating'] ) ) {
				$options['preload_settings']['speculationRumGating'] = true;
			}
			if ( ! isset( $options['preload_settings']['speculationTopUrlsLimit'] ) ) {
				$options['preload_settings']['speculationTopUrlsLimit'] = 2;
			}
			if ( ! isset( $options['preload_settings']['speculationPrerenderList'] ) ) {
				$options['preload_settings']['speculationPrerenderList'] = false;
			}
			if ( ! isset( $options['preload_settings']['enableSpeculationRules'] ) ) {
				$options['preload_settings']['enableSpeculationRules'] = false;
			}
			if ( ! isset( $options['preload_settings']['speculationMode'] ) ) {
				$options['preload_settings']['speculationMode'] = 'prefetch';
			}
			if ( ! isset( $options['preload_settings']['speculationEagerness'] ) ) {
				$options['preload_settings']['speculationEagerness'] = 'conservative';
			}
			if ( ! isset( $options['preload_settings']['speculationExcludeUrls'] ) ) {
				$options['preload_settings']['speculationExcludeUrls'] = '';
			}
			if ( ! isset( $options['preload_settings']['speculationDocumentRules'] ) ) {
				$options['preload_settings']['speculationDocumentRules'] = true;
			}
			// Automatic LCP hero preload + automatic font discovery (issue #1216).
			if ( ! isset( $options['preload_settings']['autoLcpPreload'] ) ) {
				$options['preload_settings']['autoLcpPreload'] = false;
			}
			if ( ! isset( $options['preload_settings']['autoDiscoverFonts'] ) ) {
				$options['preload_settings']['autoDiscoverFonts'] = false;
			}
			if ( ! isset( $options['llms_txt'] ) || ! is_array( $options['llms_txt'] ) ) {
				$options['llms_txt'] = array();
			}
			if ( ! isset( $options['llms_txt']['enabled'] ) ) {
				$options['llms_txt']['enabled'] = false;
			}
			if ( ! isset( $options['llms_txt']['source'] ) ) {
				$options['llms_txt']['source'] = 'both';
			}
			if ( ! isset( $options['od_integration'] ) || ! is_array( $options['od_integration'] ) ) {
				$options['od_integration'] = array();
			}
			if ( ! isset( $options['od_integration']['enabled'] ) ) {
				$options['od_integration']['enabled'] = class_exists( 'OD_URL_Metric' ) || function_exists( 'od_get_url_metrics' );
			}
			if ( ! isset( $options['bfcache'] ) || ! is_array( $options['bfcache'] ) ) {
				$options['bfcache'] = array();
			}
			if ( ! isset( $options['bfcache']['enabled'] ) ) {
				$options['bfcache']['enabled'] = false;
			}
			if ( ! isset( $options['perf_translations'] ) || ! is_array( $options['perf_translations'] ) ) {
				$options['perf_translations'] = array();
			}
			if ( ! isset( $options['perf_translations']['enabled'] ) ) {
				$options['perf_translations']['enabled'] = false;
			}
			if ( ! isset( $options['ai_adaptive'] ) || ! is_array( $options['ai_adaptive'] ) ) {
				$options['ai_adaptive'] = array();
			}
			if ( ! isset( $options['ai_adaptive']['enabled'] ) ) {
				$options['ai_adaptive']['enabled'] = false;
			}
			if ( ! isset( $options['ai_adaptive']['use_wp_ai_client'] ) ) {
				$options['ai_adaptive']['use_wp_ai_client'] = false;
			}
			if ( ! isset( $options['ai_adaptive']['field_lcp_min_samples'] ) ) {
				$options['ai_adaptive']['field_lcp_min_samples'] = 20;
			}
			if ( ! isset( $options['ai_adaptive']['dismissed_suggestions'] ) || ! is_array( $options['ai_adaptive']['dismissed_suggestions'] ) ) {
				$options['ai_adaptive']['dismissed_suggestions'] = array();
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_cooldown_days'] ) ) {
				$options['ai_adaptive']['anomaly_cooldown_days'] = 7;
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_min_samples'] ) ) {
				$options['ai_adaptive']['anomaly_min_samples'] = 10;
			}
			if ( ! isset( $options['ai_adaptive']['css_refresh_on_lcp_regression'] ) ) {
				$options['ai_adaptive']['css_refresh_on_lcp_regression'] = false;
			}
			if ( ! isset( $options['ai_adaptive']['css_refresh_cooldown_days'] ) ) {
				$options['ai_adaptive']['css_refresh_cooldown_days'] = 7;
			}
			// RUM-segmented speculation auto-tune keys (issue #1425).
			if ( ! isset( $options['ai_adaptive']['speculation_autotune_enabled'] ) ) {
				$options['ai_adaptive']['speculation_autotune_enabled'] = false;
			}
			if ( ! isset( $options['ai_adaptive']['speculation_min_samples'] ) ) {
				$options['ai_adaptive']['speculation_min_samples'] = 20;
			}
			if ( ! isset( $options['ai_adaptive']['speculation_max_urls'] ) ) {
				$options['ai_adaptive']['speculation_max_urls'] = 5;
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_tolerance_pct'] ) ) {
				$options['ai_adaptive']['anomaly_tolerance_pct'] = 5.0;
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_tolerance_abs'] ) ) {
				$options['ai_adaptive']['anomaly_tolerance_abs'] = 0.01;
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_persistence_windows'] ) ) {
				$options['ai_adaptive']['anomaly_persistence_windows'] = 3;
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_p75_min_samples'] ) ) {
				$options['ai_adaptive']['anomaly_p75_min_samples'] = 10;
			}
			// Anomaly detector v2 keys (issue #1313).
			if ( ! isset( $options['ai_adaptive']['anomaly_band_window'] ) ) {
				$options['ai_adaptive']['anomaly_band_window'] = 10;
			}
			if ( ! isset( $options['ai_adaptive']['anomaly_recovery_days'] ) ) {
				$options['ai_adaptive']['anomaly_recovery_days'] = 3;
			}
			if ( ! isset( $options['ai_adaptive']['deploy_notes'] ) || ! is_array( $options['ai_adaptive']['deploy_notes'] ) ) {
				$options['ai_adaptive']['deploy_notes'] = array();
			}
			if ( ! isset( $options['edge_cache'] ) || ! is_array( $options['edge_cache'] ) ) {
				$options['edge_cache'] = array();
			}
			if ( ! isset( $options['edge_cache']['enabled'] ) ) {
				$options['edge_cache']['enabled'] = false;
			}
			// CCSS/used-CSS queue keys (issues #1038, #1164, #1235, #1388).
			if ( ! isset( $options['file_optimisation'] ) || ! is_array( $options['file_optimisation'] ) ) {
				$options['file_optimisation'] = array();
			}
			if ( ! isset( $options['file_optimisation']['ccssMaxSize'] ) ) {
				$options['file_optimisation']['ccssMaxSize'] = 20480;
			}
			if ( ! isset( $options['file_optimisation']['ccssSafelistExtra'] ) ) {
				$options['file_optimisation']['ccssSafelistExtra'] = '';
			}
			if ( ! isset( $options['file_optimisation']['ccssQueueCap'] ) ) {
				$options['file_optimisation']['ccssQueueCap'] = 5;
			}
			if ( ! isset( $options['file_optimisation']['ccssGenTimeout'] ) ) {
				$options['file_optimisation']['ccssGenTimeout'] = 25;
			}
			if ( ! isset( $options['file_optimisation']['ccssInlineBudgetKb'] ) ) {
				$options['file_optimisation']['ccssInlineBudgetKb'] = 14;
			}
			if ( ! isset( $options['file_optimisation']['ccssCommerceExclude'] ) ) {
				$options['file_optimisation']['ccssCommerceExclude'] = true;
			}
			if ( ! isset( $options['file_optimisation']['ccssChecksumRegen'] ) ) {
				$options['file_optimisation']['ccssChecksumRegen'] = true;
			}
			if ( ! isset( $options['file_optimisation']['usedCssQueueCap'] ) ) {
				$options['file_optimisation']['usedCssQueueCap'] = 50;
			}
			if ( ! isset( $options['file_optimisation']['ccssViewportVariants'] ) ) {
				$options['file_optimisation']['ccssViewportVariants'] = false;
			}

			self::$resolved_settings_cache[ $blog_id ]        = $options;
			self::$resolved_settings_cache_loaded[ $blog_id ] = true;
			return $options;
		}

		/**
		 * Invalidate one or all resolved-options memo entries.
		 *
		 * Raw settings writes remain owned by Settings_Store::save_settings();
		 * this method only drops effective read snapshots. A blog ID scopes
		 * invalidation, while null clears every site for test isolation and
		 * delete_option compatibility.
		 *
		 * @since NEXT
		 * @param int|null $blog_id Optional blog ID.
		 * @return void
		 */
		public static function invalidate_resolved_settings( ?int $blog_id = null ): void {
			if ( null !== $blog_id ) {
				unset( self::$resolved_settings_cache[ $blog_id ], self::$resolved_settings_cache_loaded[ $blog_id ] );
				return;
			}
			self::$resolved_settings_cache        = array();
			self::$resolved_settings_cache_loaded = array();
		}

		/**
		 * Per-request memo for wppo_settings to avoid repeated get_option deserialization.
		 *
		 * Keyed by blog ID for multisite correctness under switch_to_blog().
		 *
		 * @var array<int, array>
		 * @since 2.4.0
		 */
		private static array $settings_cache = array();

		/**
		 * Whether the settings cache has been populated this request, keyed by blog ID.
		 *
		 * @var array<int, bool>
		 * @since 2.4.0
		 */
		private static array $settings_cache_loaded = array();

		/**
		 * Resolve current blog ID safely (handles Brain Monkey stub mis-configuration in tests).
		 *
		 * Mirrored in Util::current_blog_id() by design (decoupling); keep in sync.
		 *
		 * @since 2.4.0
		 * @since NEXT Public for Main's blog-aware options facade.
		 * @return int Blog ID.
		 */
		public static function current_blog_id(): int {
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
		 * @since 2.4.0
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
		 * @since 2.4.0
		 * @param array $settings The settings to cache.
		 * @return void
		 */
		public static function set_settings_cache( array $settings ): void {
			$bid                                 = self::current_blog_id();
			self::$settings_cache[ $bid ]        = $settings;
			self::$settings_cache_loaded[ $bid ] = true;
			self::invalidate_resolved_settings( $bid );
			self::ensure_settings_cache_hook();
		}

		/**
		 * Persist wppo_settings with autoload disabled (audit #1325).
		 *
		 * Single owner for settings writes: the multi-tab array must never
		 * sit in alloptions. Refreshes the per-request memo on success so
		 * same-request reads observe the write.
		 *
		 * @since 2.4.0
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
		 * Note: the purge-fallback gate memo lives in `Util` (not settings
		 * state); `Util::clear_settings_cache()` clears it alongside this
		 * delegate call.
		 *
		 * @since 2.4.0
		 * @param int|null $blog_id Optional blog ID to clear. Null clears all.
		 * @return void
		 */
		public static function clear_settings_cache( $blog_id = null ): void {
			if ( null !== $blog_id && is_int( $blog_id ) ) {
				$bid = (int) $blog_id;
				unset( self::$settings_cache[ $bid ], self::$settings_cache_loaded[ $bid ] );
				self::invalidate_resolved_settings( $bid );
				return;
			}
			// Action callbacks (update/delete) pass $old/$new or $option/$value
			// which are not int blog IDs; treat non-int as "clear all" for
			// backwards-compat with the pre-blog-keyed API.
			self::$settings_cache        = array();
			self::$settings_cache_loaded = array();
			self::invalidate_resolved_settings();
		}

		/**
		 * Handler for switch_blog — settings side.
		 *
		 * The memo is blog-keyed and needs no destructive clear (keys already
		 * isolate sites); this endpoint exists so `Util::on_switch_blog()`
		 * can delegate the settings part here while keeping the permalink and
		 * callback-secret clears local to `Util` (dependency direction:
		 * features → Settings_Store → WP core; this class references nothing
		 * else in the plugin).
		 *
		 * Kept separate from clear_settings_cache for hook arity clarity.
		 *
		 * @since 2.4.0
		 * @param int $new_blog_id New blog ID.
		 * @param int $prev_blog_id Previous blog ID.
		 * @return void
		 */
		public static function on_switch_blog( $new_blog_id, $prev_blog_id ): void {
			// The hook params are unused (keys already isolate) — consumed
			// explicitly to satisfy the unused-parameter sniff.
			unset( $new_blog_id, $prev_blog_id );
		}

		/**
		 * Register the settings-cache invalidation hooks eagerly.
		 *
		 * The invalidation hooks only need to exist before the first
		 * update/add/delete of `wppo_settings` in the request. Registering
		 * them at plugin boot (in {@see Main::__construct()}) makes
		 * invalidation deterministic instead of relying on the lazy
		 * first-call registration inside get_settings(); the lazy path
		 * remains as a backstop for entry points that bypass Main. Safe to
		 * call multiple times — a static guard makes the registration
		 * idempotent.
		 *
		 * Note: the `switch_blog` hook stays owned by `Util`
		 * (`Util::on_switch_blog()`, which delegates here) because its
		 * callback also clears the permalink and callback-secret memos that
		 * live in `Util`.
		 *
		 * @since 2.4.0
		 * @return void
		 */
		public static function register_settings_cache_hooks(): void {
			self::ensure_settings_cache_hook();
		}

		/**
		 * Ensure the invalidation hooks for wppo_settings are registered once per request.
		 *
		 * @since 2.4.0
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
		}

		/**
		 * Invalidate/update the memo when wppo_settings is updated.
		 *
		 * @since 2.4.0
		 * @param mixed $old_value Previous value.
		 * @param mixed $value New value.
		 * @return void
		 */
		public static function on_settings_update( $old_value, $value ): void {
			$bid                                 = self::current_blog_id();
			self::$settings_cache[ $bid ]        = is_array( $value ) ? $value : array();
			self::$settings_cache_loaded[ $bid ] = true;
			self::invalidate_resolved_settings( $bid );
		}

		/**
		 * Populate the memo when wppo_settings is added.
		 *
		 * @since 2.4.0
		 * @param string $option Option name.
		 * @param mixed  $value Option value.
		 * @return void
		 */
		public static function on_settings_add( $option, $value ): void {
			if ( 'wppo_settings' === $option ) {
				$bid                                 = self::current_blog_id();
				self::$settings_cache[ $bid ]        = is_array( $value ) ? $value : array();
				self::$settings_cache_loaded[ $bid ] = true;
				self::invalidate_resolved_settings( $bid );
			}
		}

		/**
		 * Read the stored prior-settings snapshot.
		 *
		 * @since 2.4.0
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
		 * @since 2.4.0
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
		 * @since 2.4.0
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
				if ( ! $updated && $before === $restored ) {
					self::set_settings_cache( $restored );
				}
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
	}
}
