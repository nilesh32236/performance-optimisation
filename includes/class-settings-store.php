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
 * Minimal WordPress APIs only: `get_option()`/`update_option()`,
 * `add_action()`, `get_current_blog_id()` for multisite memo keying, the
 * core sanitizers (`sanitize_text_field()`, `sanitize_textarea_field()`,
 * `esc_url_raw()`, `absint()`), and `apply_filters()` for the TTL/CDN
 * mapping filters. Depends on nothing else in the plugin.
 *
 * Owns the settings schema allowlist (`ALLOWED_SETTINGS_KEYS` /
 * `ALLOWED_SETTINGS_TABS`, `get_allowed_settings_keys()`), the per-tab
 * sanitizer map (`get_settings_sanitizer_map()`), and the `sanitize_*`
 * family, moved from the god utility `Util` (REF-011). `Util::` keeps
 * thin facade proxies/aliases so existing callers keep working untouched.
 * Still deliberately OUT: `Main`'s defaults/lazy options.
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
	 * Static settings-state and settings-schema owner. Depends only on the minimal WordPress
	 * APIs required to preserve the existing implementation verbatim:
	 * `get_option()`/`update_option()`, `add_action()`, and
	 * `get_current_blog_id()`. Depends on nothing else in the plugin.
	 *
	 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT Canonical owner (REF-011); Util:: keeps a facade alias.
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
		 * @since NEXT Canonical owner (REF-011); Util:: keeps a facade alias.
		 * @var string[]
		 */
		public const ALLOWED_SETTINGS_TABS = self::ALLOWED_SETTINGS_KEYS;

		/**
		 * Get the allowlisted top-level settings keys.
		 *
		 * @since 2.0.0
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since 2.2.0
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * @since NEXT Moved from Util (REF-011); behavior unchanged.
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
		 * Per-request memo for wppo_settings to avoid repeated get_option deserialization.
		 *
		 * Keyed by blog ID for multisite correctness under switch_to_blog().
		 *
		 * @var array<int, array>
		 * @since NEXT
		 */
		private static array $settings_cache = array();

		/**
		 * Whether the settings cache has been populated this request, keyed by blog ID.
		 *
		 * @var array<int, bool>
		 * @since NEXT
		 */
		private static array $settings_cache_loaded = array();

		/**
		 * Resolve current blog ID safely (handles Brain Monkey stub mis-configuration in tests).
		 *
		 * Mirrored in Util::current_blog_id() by design (decoupling); keep in sync.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
		 * @param int|null $blog_id Optional blog ID to clear. Null clears all.
		 * @return void
		 */
		public static function clear_settings_cache( $blog_id = null ): void {
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
		 * @since NEXT
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
		 * @since NEXT
		 * @return void
		 */
		public static function register_settings_cache_hooks(): void {
			self::ensure_settings_cache_hook();
		}

		/**
		 * Ensure the invalidation hooks for wppo_settings are registered once per request.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
		 * Read the stored prior-settings snapshot.
		 *
		 * @since NEXT
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
		 * @since NEXT
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
		 * @since NEXT
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
