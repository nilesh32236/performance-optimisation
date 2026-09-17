<?php
/**
 * Safe-mode sandbox preview for asset optimization.
 *
 * Visitor-safe experimentation for delay/defer/combine: production markup
 * is served to everyone except an administrator carrying a valid preview
 * query param + nonce. Staged values live in the additive
 * `file_optimisation.sandboxStaged` settings key (defaults to array()).
 * Any preview failure fails open to production markup, never fatal.
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Sandbox_Preview' ) ) {
	/**
	 * Class Sandbox_Preview
	 *
	 * Isolated admin-only preview controller for asset optimization.
	 *
	 * @since NEXT
	 */
	final class Sandbox_Preview {
		/**
		 * Preview query var name.
		 *
		 * @since NEXT
		 * @var string
		 */
		const QUERY_VAR = 'wppo_preview';

		/**
		 * Preview query value enabling the asset sandbox.
		 *
		 * @since NEXT
		 * @var string
		 */
		const PREVIEW_VALUE = 'assets';

		/**
		 * Nonce action for preview links.
		 *
		 * @since NEXT
		 * @var string
		 */
		const NONCE_ACTION = 'wppo_preview_assets';

		/**
		 * Nonce query var name.
		 *
		 * @since NEXT
		 * @var string
		 */
		const NONCE_VAR = '_wppo_preview_nonce';

		/**
		 * Settings key holding staged experimental values.
		 *
		 * @since NEXT
		 * @var string
		 */
		const STAGED_KEY = 'sandboxStaged';

		/**
		 * Asset keys that may be staged (production keys are never renamed).
		 *
		 * Only keys with a preview render path are allowlisted: delay/defer
		 * widen their hook registration and bypass the logged-in/safe-mode
		 * gates for preview admins, combine_css() bypasses its eligibility
		 * gate and overlays staged excludes, minifyJS/minifyCSS/excludeCSS/
		 * excludeJS/cssJsSafeMode overlay the effective file-optimisation
		 * slice read by the combine pipeline (issue #1404), and the HTML
		 * minifier overlays staged delay excludes plus
		 * delayJSExternalOnly/minifyInlineJS. MinifyJS/minifyCSS and their
		 * exclude lists now have a staged preview path via the effective
		 * slice overlay, so they are staged for preview.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		const ALLOWED_STAGED_KEYS = array(
			'delayJS',
			'deferJS',
			'combineCSS',
			'elementorSafeMode',
			'cssJsSafeMode',
			'minifyJS',
			'minifyCSS',
			'excludeCSS',
			'excludeJS',
			'delayJSExternalOnly',
			'minifyInlineJS',
			'excludeDelayJS',
			'excludeDeferJS',
			'excludeCombineCSS',
			'delayJSThirdParty',
			'delayJSThirdPartyAuto',
			'delayJSThirdPartyDenylist',
			'delayJSThirdPartyAllowlist',
		);

		/**
		 * Per-request memo for is_preview_request().
		 *
		 * @since NEXT
		 * @var bool|null
		 */
		private static $preview_memo = null;

		/**
		 * Reset per-request memo (for tests).
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function reset_memo(): void {
			self::$preview_memo = null;
		}

		/**
		 * Whether the current request is a valid admin asset-preview request.
		 *
		 * True only when `?wppo_preview=assets` is present AND the current
		 * user can manage_options AND the preview nonce verifies. Visitors
		 * (no capability / bad nonce) always get false so they never see
		 * experimental markup. Fail-open to false on any error.
		 *
		 * @since NEXT
		 * @return bool True when experimental output may render for this request.
		 */
		public static function is_preview_request(): bool {
			if ( null !== self::$preview_memo ) {
				return self::$preview_memo;
			}
			try {
				if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview gate, no state change.
					self::$preview_memo = false;
					return false;
				}
				$value = isset( $_GET[ self::QUERY_VAR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only preview gate, verified via nonce below.
				if ( self::PREVIEW_VALUE !== $value ) {
					self::$preview_memo = false;
					return false;
				}
				if ( ! function_exists( 'current_user_can' ) || ! function_exists( 'wp_verify_nonce' ) ) {
					// Early boot (pluggable not loaded, user unresolved): the
					// result is indeterminate, so return false WITHOUT memoizing.
					// Memoizing here would poison the per-request cache and make
					// every later template_redirect / per-tag check return stale
					// false even for a valid admin preview.
					return false;
				}
				try {
					if ( ! current_user_can( 'manage_options' ) ) {
						self::$preview_memo = false;
						return false;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
					self::$preview_memo = false;
					return false;
				}
				$nonce = isset( $_GET[ self::NONCE_VAR ] ) ? sanitize_text_field( wp_unslash( (string) $_GET[ self::NONCE_VAR ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce value verified below.
				if ( '' === $nonce ) {
					self::$preview_memo = false;
					return false;
				}
				try {
					$valid = wp_verify_nonce( $nonce, self::NONCE_ACTION );
				} catch ( \Throwable $e ) {
					unset( $e );
					self::$preview_memo = false;
					return false;
				}
				self::$preview_memo = ! empty( $valid );
				return self::$preview_memo;
			} catch ( \Throwable $e ) {
				unset( $e );
				self::$preview_memo = false;
				return false;
			}
		}

		/**
		 * Enforce no-cache semantics for preview requests.
		 *
		 * Defines DONOTCACHEPAGE and sends nocache headers so preview
		 * responses are never stored in the static HTML cache nor served
		 * to other visitors. No-op for non-preview requests. Fail-open:
		 * header failures never fatal.
		 *
		 * @since NEXT
		 * @return void
		 */
		public static function enforce_no_cache(): void {
			try {
				if ( ! self::is_preview_request() ) {
					return;
				}
				if ( ! defined( 'DONOTCACHEPAGE' ) ) {
					define( 'DONOTCACHEPAGE', true );
				}
				if ( function_exists( 'nocache_headers' ) ) {
					try {
						nocache_headers();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * Get staged experimental settings.
		 *
		 * @since NEXT
		 * @return array Staged file_optimisation slice (possibly empty).
		 */
		public static function get_staged_settings(): array {
			try {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					return array();
				}
				$settings = (array) Util::get_settings();
				$file     = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
				$staged   = isset( $file[ self::STAGED_KEY ] ) && is_array( $file[ self::STAGED_KEY ] ) ? $file[ self::STAGED_KEY ] : array();
				return $staged;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Whether staged experimental values exist.
		 *
		 * @since NEXT
		 * @return bool True when at least one staged key is stored.
		 */
		public static function is_staged_available(): bool {
			return ! empty( self::get_staged_settings() );
		}

		/**
		 * Compute effective file_optimisation settings for this request.
		 *
		 * Visitors (non-preview) always get production unchanged. Admin
		 * preview requests get production overlaid with staged values for
		 * the allowlisted asset keys. Fail-open: any error returns
		 * production unchanged.
		 *
		 * @since NEXT
		 * @param array $production Production file_optimisation slice.
		 * @return array Effective slice to render with.
		 */
		public static function get_effective_file_optimisation( array $production = array() ): array {
			try {
				if ( ! self::is_preview_request() ) {
					return $production;
				}
				$staged = self::get_staged_settings();
				if ( empty( $staged ) ) {
					return $production;
				}
				foreach ( self::ALLOWED_STAGED_KEYS as $key ) {
					if ( array_key_exists( $key, $staged ) ) {
						$production[ $key ] = $staged[ $key ];
					}
				}
				return $production;
			} catch ( \Throwable $e ) {
				unset( $e );
				return $production;
			}
		}

		/**
		 * Sanitize a staged payload down to the allowlisted asset keys.
		 *
		 * @since NEXT
		 * @param array $staged Raw staged payload.
		 * @return array Sanitized staged payload.
		 */
		public static function sanitize_staged( array $staged ): array {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'sanitize_settings_recursively' ) ) {
					$staged = (array) Util::sanitize_settings_recursively( $staged );
				}
				// Boolean normalization: a string 'false' (form-encoded or
				// malformed import) would otherwise survive as a truthy
				// non-empty string and later !empty() checks would enable a
				// feature the caller meant to disable. Fail-safe to false,
				// except elementorSafeMode and cssJsSafeMode which fail-safe
				// to true (absent = enabled) matching
				// Util::sanitize_settings_recursively().
				foreach ( array( 'delayJS', 'deferJS', 'combineCSS', 'delayJSExternalOnly', 'minifyInlineJS', 'delayJSThirdParty', 'delayJSThirdPartyAuto', 'minifyJS', 'minifyCSS' ) as $bool_key ) {
					if ( array_key_exists( $bool_key, $staged ) && ! is_bool( $staged[ $bool_key ] ) ) {
						$bool                = filter_var( $staged[ $bool_key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$staged[ $bool_key ] = null === $bool ? false : $bool;
					}
				}
				foreach ( array( 'elementorSafeMode', 'cssJsSafeMode' ) as $bool_key ) {
					if ( array_key_exists( $bool_key, $staged ) && ! is_bool( $staged[ $bool_key ] ) ) {
						$bool                = filter_var( $staged[ $bool_key ], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
						$staged[ $bool_key ] = null === $bool ? true : $bool;
					}
				}
				$clean = array();
				foreach ( self::ALLOWED_STAGED_KEYS as $key ) {
					if ( array_key_exists( $key, $staged ) ) {
						$clean[ $key ] = $staged[ $key ];
					}
				}
				return $clean;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array();
			}
		}

		/**
		 * Persist staged experimental settings (admin only).
		 *
		 * @since NEXT
		 * @param array $staged Raw staged payload.
		 * @return bool True on success.
		 */
		public static function save_staged( array $staged ): bool {
			try {
				if ( function_exists( 'current_user_can' ) ) {
					try {
						if ( ! current_user_can( 'manage_options' ) ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
					return false;
				}
				$clean = self::sanitize_staged( $staged );
				// allowlist(settings-read-guard): deliberate direct read — must distinguish
				// "no stored row" (false) from "stored array".
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					$stored = array();
				}
				if ( ! isset( $stored['file_optimisation'] ) || ! is_array( $stored['file_optimisation'] ) ) {
					$stored['file_optimisation'] = array();
				}
				$stored['file_optimisation'][ self::STAGED_KEY ] = $clean;
				$updated = Util::save_settings( $stored );
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					try {
						Util::set_settings_cache( $stored );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( $updated ) {
					return true;
				}
				// update_option() returns false both on failure and when the
				// stored value is unchanged: read back and treat "staged slot
				// already holds these values" as success so a no-op re-stage
				// is not reported as an error, while a real write failure
				// still returns false.
				try {
					// allowlist(settings-read-guard): deliberate direct read — verify the write above.
					$verify = get_option( 'wppo_settings' );
					if ( is_array( $verify )
					&& isset( $verify['file_optimisation'][ self::STAGED_KEY ] )
					&& $verify['file_optimisation'][ self::STAGED_KEY ] === $clean ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Promote staged settings to production (admin only).
		 *
		 * Copies allowlisted staged keys into production file_optimisation,
		 * clears the staged slot, snapshots prior settings for one-click
		 * undo. Fail-open: returns false without partial writes when
		 * possible; never fatal.
		 *
		 * @since NEXT
		 * @return bool True on success.
		 */
		public static function promote_staged(): bool {
			try {
				if ( function_exists( 'current_user_can' ) ) {
					try {
						if ( ! current_user_can( 'manage_options' ) ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
					return false;
				}
				// allowlist(settings-read-guard): deliberate direct read.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					return false;
				}
				if ( ! isset( $stored['file_optimisation'] ) || ! is_array( $stored['file_optimisation'] ) ) {
					$stored['file_optimisation'] = array();
				}
				$staged = isset( $stored['file_optimisation'][ self::STAGED_KEY ] ) && is_array( $stored['file_optimisation'][ self::STAGED_KEY ] ) ? $stored['file_optimisation'][ self::STAGED_KEY ] : array();
				if ( empty( $staged ) ) {
					return false;
				}
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'take_settings_snapshot' ) ) {
						Util::take_settings_snapshot( $stored );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				foreach ( self::ALLOWED_STAGED_KEYS as $key ) {
					if ( array_key_exists( $key, $staged ) ) {
						$stored['file_optimisation'][ $key ] = $staged[ $key ];
					}
				}
				$stored['file_optimisation'][ self::STAGED_KEY ] = array();
				$updated = Util::save_settings( $stored );
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					try {
						Util::set_settings_cache( $stored );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Telemetry' ) && method_exists( 'PerformanceOptimise\Inc\Telemetry', 'invalidate_audit_cache' ) ) {
					try {
						Telemetry::invalidate_audit_cache();
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( $updated ) {
					return true;
				}
				// update_option() returns false both on failure and when the
				// stored value is unchanged: read back and treat "staged slot
				// already cleared" as success, like save_staged() does, so a
				// failed DB write is never reported as a promotion.
				try {
					// allowlist(settings-read-guard): deliberate direct read — verify the write above.
					$verify = get_option( 'wppo_settings' );
					if ( is_array( $verify )
					&& empty( $verify['file_optimisation'][ self::STAGED_KEY ] ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Discard staged settings (admin only).
		 *
		 * @since NEXT
		 * @return bool True on success.
		 */
		public static function discard_staged(): bool {
			try {
				if ( function_exists( 'current_user_can' ) ) {
					try {
						if ( ! current_user_can( 'manage_options' ) ) {
							return false;
						}
					} catch ( \Throwable $e ) {
						unset( $e );
						return false;
					}
				}
				if ( ! function_exists( 'update_option' ) || ! function_exists( 'get_option' ) ) {
					return false;
				}
				// allowlist(settings-read-guard): deliberate direct read.
				$stored = get_option( 'wppo_settings' );
				if ( ! is_array( $stored ) ) {
					// Nothing staged to discard (e.g. fresh install): treat
					// the no-op as idempotent success, not a 500.
					return true;
				}
				if ( ! isset( $stored['file_optimisation'] ) || ! is_array( $stored['file_optimisation'] ) ) {
					$stored['file_optimisation'] = array();
				}
				$stored['file_optimisation'][ self::STAGED_KEY ] = array();
				$updated = Util::save_settings( $stored );
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'set_settings_cache' ) ) {
					try {
						Util::set_settings_cache( $stored );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( $updated ) {
					return true;
				}
				// update_option() returns false both on failure and when the
				// stored value is unchanged: read back and treat "staged slot
				// already empty" as success, like save_staged() does, so a
				// failed DB write is never reported as a discard.
				try {
					// allowlist(settings-read-guard): deliberate direct read — verify the write above.
					$verify = get_option( 'wppo_settings' );
					if ( ! is_array( $verify )
					|| empty( $verify['file_optimisation'][ self::STAGED_KEY ] ) ) {
						return true;
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return false;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Build an admin-only preview URL for a frontend URL.
		 *
		 * Returns the URL unchanged when a nonce cannot be created (fail-open).
		 *
		 * @since NEXT
		 * @param string $url Frontend URL to preview.
		 * @return string Preview URL with query var + nonce.
		 */
		public static function get_preview_url( string $url = '' ): string {
			try {
				if ( '' === $url && function_exists( 'home_url' ) ) {
					try {
						$url = (string) home_url( '/' );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				if ( '' === $url ) {
					return '';
				}
				if ( ! function_exists( 'wp_create_nonce' ) || ! function_exists( 'add_query_arg' ) ) {
					return (string) $url;
				}
				try {
					$nonce = wp_create_nonce( self::NONCE_ACTION );
				} catch ( \Throwable $e ) {
					unset( $e );
					return (string) $url;
				}
				try {
					return (string) add_query_arg(
						array(
							self::QUERY_VAR => self::PREVIEW_VALUE,
							self::NONCE_VAR => $nonce,
						),
						$url
					);
				} catch ( \Throwable $e ) {
					unset( $e );
					return (string) $url;
				}
			} catch ( \Throwable $e ) {
				unset( $e );
				return (string) $url;
			}
		}
	}
}
