<?php
/**
 * Safe CSS/JS rollout with dry-run preview, health gate, and rollback.
 *
 * Optimizer deploys like code deploys: new critical-CSS / used-CSS output
 * is staged to a versioned side slot first (`.staged.css`), exposed via a
 * preview + diff/status payload before promote, then promoted to the live
 * slot with a last-good backup (`.last-good.css`). A fail-open health gate
 * probes the promoted payload for missing/empty output (and optional HTTP
 * 404); on breach the last-good slot auto-restores and the cache-hit reason
 * (hit/miss/bypass + slot version) is logged to the activity log.
 *
 * Multisite-safe: rollout state lives in blog-aware transients via
 * Util::transient_key() so slots never leak across sites. No hot-path
 * reads: only the regenerate/apply path consults this class (lazy).
 *
 * @package PerformanceOptimise\Inc
 * @since   NEXT
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Css_Rollout' ) ) {
	/**
	 * Class Css_Rollout
	 *
	 * Single home for the CSS rollout state machine so Critical_CSS and
	 * Used_CSS never drift.
	 *
	 * @since NEXT
	 */
	final class Css_Rollout {

		/**
		 * Staged rollout mode value.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const MODE_STAGED = 'staged';

		/**
		 * Direct-apply (legacy) rollout mode value.
		 *
		 * @since NEXT
		 * @var string
		 */
		public const MODE_DIRECT = 'direct';

		/**
		 * Max preview snippet length in chars (plan: truncated at 5000 chars).
		 *
		 * @since NEXT
		 * @var int
		 */
		public const PREVIEW_SNIPPET_MAX = 5000;

		/**
		 * Rollout state TTL in seconds.
		 *
		 * Short-lived (one day): the SPA only needs recent staged/done /
		 * rolled_back state, and per-page used-CSS transients must not
		 * accumulate hundreds of rows in wp_options when no persistent
		 * object cache is present.
		 *
		 * @since NEXT
		 * @var int
		 */
		public const STATE_TTL = 86400;

		/**
		 * Allowed rollout states.
		 *
		 * @since NEXT
		 * @var string[]
		 */
		public const ALLOWED_STATES = array( 'none', 'staged', 'done', 'rolled_back', 'failed' );

		/**
		 * Sanitize a rollout mode value (allowlist, fail-open to direct).
		 *
		 * @since NEXT
		 * @param mixed $value Raw value.
		 * @return string 'staged' or 'direct'.
		 */
		public static function sanitize_mode( $value ): string {
			try {
				// Non-scalar input (e.g. arrays) must fail open to direct
				// without emitting an "Array to string conversion" warning
				// (a Warning is not caught by the Throwable catch below).
				if ( is_array( $value ) || ( ! is_scalar( $value ) && null !== $value ) ) {
					return self::MODE_DIRECT;
				}
				$mode = strtolower( trim( (string) $value ) );
				return self::MODE_STAGED === $mode ? self::MODE_STAGED : self::MODE_DIRECT;
			} catch ( \Throwable $e ) {
				unset( $e );
				return self::MODE_DIRECT;
			}
		}

		/**
		 * Whether staged (dry-run preview) mode is active.
		 *
		 * Lazy: reads settings only on the regenerate/apply path, never on
		 * the frontend hot path. Fail-open to direct-apply (legacy).
		 *
		 * @since NEXT
		 * @param array|null $settings Optional settings array (defaults to Util::get_settings()).
		 * @return bool True when staged mode is active.
		 */
		public static function is_staged_mode( ?array $settings = null ): bool {
			try {
				if ( null === $settings ) {
					if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
						return false;
					}
					$settings = (array) Util::get_settings();
				}
				$file = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
				return self::MODE_STAGED === self::sanitize_mode( $file['cssRolloutMode'] ?? self::MODE_DIRECT );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Shared fail-safe boolean reader for the rollout toggles.
		 *
		 * Absent key defaults to enabled (fail-safe); explicit false
		 * disables. Mirrors the JS normalizeRolloutBool() allowlist
		 * (true: 1/true/on/yes, false: 0/false/off/no/'', else true) so
		 * the UI toggle and the server never disagree.
		 *
		 * @since NEXT
		 * @param array|null $settings Optional settings array.
		 * @param string     $key      File-optimisation key.
		 * @return bool True when the toggle is enabled.
		 */
		private static function get_rollout_bool( ?array $settings, string $key ): bool {
			if ( null === $settings ) {
				if ( ! class_exists( 'PerformanceOptimise\Inc\Util' ) || ! method_exists( 'PerformanceOptimise\Inc\Util', 'get_settings' ) ) {
					return true;
				}
				$settings = (array) Util::get_settings();
			}
			$file = isset( $settings['file_optimisation'] ) && is_array( $settings['file_optimisation'] ) ? $settings['file_optimisation'] : array();
			if ( ! array_key_exists( $key, $file ) ) {
				return true;
			}
			$value = $file[ $key ];
			if ( is_bool( $value ) ) {
				return $value;
			}
			if ( function_exists( 'filter_var' ) ) {
				$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				return null === $parsed ? true : $parsed;
			}
			return (bool) $value;
		}

		/**
		 * Whether the post-apply health gate is enabled.
		 *
		 * Absent key defaults to enabled (fail-safe); explicit false disables.
		 *
		 * @since NEXT
		 * @param array|null $settings Optional settings array.
		 * @return bool True when the health gate runs after apply.
		 */
		public static function is_health_check_enabled( ?array $settings = null ): bool {
			try {
				return self::get_rollout_bool( $settings, 'cssRolloutHealthCheck' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Whether the last-good backup slot is kept.
		 *
		 * Absent key defaults to enabled (fail-safe); explicit false disables.
		 *
		 * @since NEXT
		 * @param array|null $settings Optional settings array.
		 * @return bool True when the last-good backup is kept.
		 */
		public static function is_keep_last_good_enabled( ?array $settings = null ): bool {
			try {
				return self::get_rollout_bool( $settings, 'cssRolloutKeepLastGood' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Normalize a rollout slot identifier.
		 *
		 * Slots are template hashes (CCSS) or md5(url) keys (used-CSS): word
		 * chars + dash only so a caller passing ../../foo can never escape
		 * the cache dir. Returns '' when refused.
		 *
		 * @since NEXT
		 * @param string $slot Raw slot identifier.
		 * @return string Normalized slot, or '' when refused.
		 */
		public static function normalize_slot( string $slot ): string {
			$slot = trim( $slot );
			if ( '' === $slot || strlen( $slot ) > 128 ) {
				return '';
			}
			return 1 === preg_match( '/^[A-Za-z0-9_-]+$/', $slot ) ? $slot : '';
		}

		/**
		 * Blog-aware transient key for a slot's rollout state.
		 *
		 * @since NEXT
		 * @param string $slot Normalized slot identifier.
		 * @return string Transient key ('' when the slot is invalid).
		 */
		public static function state_key( string $slot ): string {
			$slot = self::normalize_slot( $slot );
			if ( '' === $slot ) {
				return '';
			}
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'transient_key' ) ) {
					return Util::transient_key( 'wppo_css_rollout_' . $slot );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return 'wppo_css_rollout_' . $slot;
		}

		/**
		 * Pure content health check for a CSS payload.
		 *
		 * Fail-closed payload decision, never fatal: empty output, unsafe
		 * breakout tokens (</style, </script, javascript:, expression(),
		 * vbscript:) all fail. Returns a hit-reason triple for logging.
		 *
		 * @since NEXT
		 * @param string $css CSS payload.
		 * @return array{ok: bool, reason: string, hit: string} Health verdict.
		 */
		public static function health_check_content( string $css ): array {
			try {
				if ( '' === trim( $css ) ) {
					return array(
						'ok'     => false,
						'reason' => 'empty payload',
						'hit'    => 'miss (empty)',
					);
				}
				// Single-pass strict gate shared with the pre-cache check:
				// entity-decoding + whitespace-tolerant + scroll-behavior
				// safe (see Critical_CSS::contains_unsafe_css_tokens()).
				// One preg_match runs instead of N stripos full-payload
				// scans, and parity holds by construction.
				$strict_available = class_exists( 'PerformanceOptimise\Inc\Critical_CSS' ) && method_exists( 'PerformanceOptimise\Inc\Critical_CSS', 'contains_unsafe_css_tokens' );
				if ( $strict_available ) {
					try {
						if ( Critical_CSS::contains_unsafe_css_tokens( $css ) ) {
							return array(
								'ok'     => false,
								'reason' => 'unsafe token detected',
								'hit'    => 'bypass (unsafe)',
							);
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
			} elseif ( 1 === preg_match( '/<\/style|<script|<!--|-->|expression\s*\(|javascript\s*:|vbscript\s*:|file\s*:|expect\s*:|data\s*:\s*image\/svg|data\s*:\s*text\/html|-moz-binding|&(lt|gt|amp|quot|#\d+|#x[0-9a-f]+);?/i', $css ) ) {
				// Fallback when the CCSS class is unavailable: mirrors the
				// strict token set (see Critical_CSS::
				// contains_unsafe_css_tokens()) so staged/direct decisions
				// agree by class availability. Single case-insensitive
				// regex pass (no strtolower copy, no per-token scans of
				// healthy payloads).
					return array(
						'ok'     => false,
						'reason' => 'unsafe token detected',
						'hit'    => 'bypass (unsafe)',
					);
				}
				return array(
					'ok'     => true,
					'reason' => 'ok',
					'hit'    => 'hit',
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'ok'     => false,
					'reason' => 'health check error',
					'hit'    => 'bypass (error)',
				);
			}
		}

		/**
		 * Fail-open probe for a promoted live file.
		 *
		 * Checks file existence + non-zero size on disk; optionally issues a
		 * wp_remote_head() against the public URL (function_exists-guarded)
		 * to detect a missing-CSS 404. Never fatal: any failure returns
		 * ok=false with a reason instead of throwing.
		 *
		 * Background generation workers pass $check_http=false: disk
		 * existence + size plus the content gate already prove the write,
		 * and a sync loopback HEAD would stack worker occupancy inside the
		 * 25s generation budget. Explicit admin promote/rollback keeps the
		 * loopback leg ($check_http=true).
		 *
		 * @since NEXT
		 * @param string $live_path  Filesystem path of the live file.
		 * @param string $live_url   Optional public URL of the live file.
		 * @param bool   $check_http Whether to issue the loopback HEAD probe.
		 * @return array{ok: bool, reason: string, hit: string} Health verdict.
		 */
		public static function probe_live_file( string $live_path, string $live_url = '', bool $check_http = true ): array {
			try {
				if ( '' === $live_path || ! file_exists( $live_path ) ) {
					return array(
						'ok'     => false,
						'reason' => 'missing live file (404)',
						'hit'    => 'miss (404)',
					);
				}
				$size = filesize( $live_path );
				if ( false === $size || (int) $size <= 0 ) {
					return array(
						'ok'     => false,
						'reason' => 'empty live payload',
						'hit'    => 'miss (empty)',
					);
				}
				if ( $check_http && '' !== $live_url && function_exists( 'wp_remote_head' ) && function_exists( 'is_wp_error' ) ) {
					try {
						// Short 2s HEAD with no redirects: this runs inside
						// the 25s CCSS generation budget and on promote
						// clicks, so one hung loopback must not consume the
						// budget or stall admin.
						$response = wp_remote_head(
							$live_url,
							array(
								'timeout'     => 2,
								'redirection' => 0,
							)
						);
						if ( ! is_wp_error( $response ) && function_exists( 'wp_remote_retrieve_response_code' ) ) {
							$code = (int) wp_remote_retrieve_response_code( $response );
							if ( 404 === $code || ( $code >= 400 && 0 !== $code ) ) {
								return array(
									'ok'     => false,
									'reason' => 'live URL returned HTTP ' . $code,
									'hit'    => 'miss (' . $code . ')',
								);
							}
						}
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				return array(
					'ok'     => true,
					'reason' => 'ok',
					'hit'    => 'hit',
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'ok'     => false,
					'reason' => 'probe error',
					'hit'    => 'bypass (error)',
				);
			}
		}

		/**
		 * Build a dry-run preview payload (diff/status meta, no file writes).
		 *
		 * Sizes, SHA-256 checksums, byte delta, and a head snippet of the
		 * staged CSS truncated at PREVIEW_SNIPPET_MAX chars.
		 *
		 * @since NEXT
		 * @param string $live_css   Current live CSS (may be '').
		 * @param string $staged_css Staged CSS.
		 * @return array Preview payload with size/sha/delta/snippet/truncated keys.
		 */
		public static function build_preview( string $live_css, string $staged_css ): array {
			try {
				$live_size   = strlen( $live_css );
				$staged_size = strlen( $staged_css );
				$snippet     = $staged_css;
				$truncated   = false;
				if ( strlen( $snippet ) > self::PREVIEW_SNIPPET_MAX ) {
					if ( function_exists( 'mb_substr' ) ) {
						$snippet = mb_substr( $snippet, 0, self::PREVIEW_SNIPPET_MAX, 'UTF-8' );
					} else {
						$snippet = substr( $snippet, 0, self::PREVIEW_SNIPPET_MAX );
					}
					$truncated = true;
				}
				return array(
					'live_size'   => $live_size,
					'live_sha'    => '' !== $live_css ? hash( 'sha256', $live_css ) : '',
					'staged_size' => $staged_size,
					'staged_sha'  => '' !== $staged_css ? hash( 'sha256', $staged_css ) : '',
					'delta_bytes' => $staged_size - $live_size,
					'snippet'     => $snippet,
					'truncated'   => $truncated,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'live_size'   => 0,
					'live_sha'    => '',
					'staged_size' => 0,
					'staged_sha'  => '',
					'delta_bytes' => 0,
					'snippet'     => '',
					'truncated'   => false,
				);
			}
		}

		/**
		 * Get the rollout state for a slot.
		 *
		 * Fail-open to a none-state default on any failure.
		 *
		 * @since NEXT
		 * @param string $slot Slot identifier (hash or md5(url)).
		 * @return array{state: string, reason: string, hit: string, version: int, updated: int} Rollout state.
		 */
		public static function get_state( string $slot ): array {
			$default = array(
				'state'   => 'none',
				'reason'  => '',
				'hit'     => 'bypass (no rollout)',
				'version' => 0,
				'updated' => 0,
			);
			try {
				$key = self::state_key( $slot );
				if ( '' === $key || ! function_exists( 'get_transient' ) ) {
					return $default;
				}
				$stored = get_transient( $key );
				if ( ! is_array( $stored ) ) {
					return $default;
				}
				$state = isset( $stored['state'] ) && is_string( $stored['state'] ) && in_array( $stored['state'], self::ALLOWED_STATES, true ) ? $stored['state'] : 'none';
				return array(
					'state'   => $state,
					'reason'  => isset( $stored['reason'] ) && is_string( $stored['reason'] ) ? substr( $stored['reason'], 0, 255 ) : '',
					'hit'     => isset( $stored['hit'] ) && is_string( $stored['hit'] ) && '' !== $stored['hit'] ? substr( $stored['hit'], 0, 120 ) : $default['hit'],
					'version' => isset( $stored['version'] ) ? max( 0, (int) $stored['version'] ) : 0,
					'updated' => isset( $stored['updated'] ) ? max( 0, (int) $stored['updated'] ) : 0,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $default;
			}
		}

		/**
		 * Record a rollout event: persist state + log the cache-hit reason.
		 *
		 * Best-effort under concurrency: the version is read-then-write
		 * (get_state + 1) with no atomicity, so two concurrent promotes for
		 * the same slot can both persist vN+1 with one event overwriting the
		 * other. Promote/rollback callers are admin-throttled (5/60) and the
		 * last writer wins; the live file itself is written atomically via
		 * the shared tmp+rename helper, so state skew never affects safety.
		 * Never fatal: persistence/logging failures degrade silently.
		 *
		 * @since NEXT
		 * @param string $slot   Slot identifier.
		 * @param string $state  One of ALLOWED_STATES.
		 * @param string $reason Human-readable reason (logged + stored, capped at 255 chars).
		 * @param string $hit    Cache-hit reason (hit/miss/bypass + slot version).
		 * @return array The stored state array.
		 */
		public static function record_event( string $slot, string $state, string $reason = '', string $hit = '' ): array {
			try {
				if ( ! in_array( $state, self::ALLOWED_STATES, true ) ) {
					$state = 'none';
				}
				$slot_norm = self::normalize_slot( $slot );
				$prev      = self::get_state( $slot_norm );
				$version   = isset( $prev['version'] ) ? (int) $prev['version'] + 1 : 1;
				$now       = function_exists( 'time' ) ? time() : 0;
				$stored    = array(
					'state'   => $state,
					'reason'  => is_string( $reason ) ? substr( $reason, 0, 255 ) : '',
					'hit'     => '' !== $hit && is_string( $hit ) ? substr( $hit, 0, 120 ) : 'bypass (no rollout)',
					'version' => $version,
					'updated' => $now,
				);
				$key       = self::state_key( $slot_norm );
				if ( '' !== $key && function_exists( 'set_transient' ) ) {
					try {
						set_transient( $key, $stored, self::STATE_TTL );
					} catch ( \Throwable $e ) {
						unset( $e );
					}
				}
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
						$label = '' !== $slot_norm ? $slot_norm : 'css';
						Log::add( sprintf( 'CSS rollout [%s]: %s — %s (%s, v%d).', $label, $state, '' !== $stored['reason'] ? $stored['reason'] : 'no reason', $stored['hit'], $version ) );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return $stored;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'state'   => 'none',
					'reason'  => '',
					'hit'     => 'bypass (error)',
					'version' => 0,
					'updated' => 0,
				);
			}
		}

		/**
		 * Clear the rollout state for a slot (tests + slot reset).
		 *
		 * @since NEXT
		 * @param string $slot Slot identifier.
		 * @return void
		 */
		public static function clear_state( string $slot ): void {
			try {
				$key = self::state_key( $slot );
				if ( '' !== $key && function_exists( 'delete_transient' ) ) {
					delete_transient( $key );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}
	}
}
