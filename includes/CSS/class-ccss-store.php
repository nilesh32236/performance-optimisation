<?php
/**
 * Critical-CSS file lifecycle + status store (FUT-001).
 *
 * CSS domain (ARCH-002): owns the storage/staging/health/status cluster
 * extracted verbatim from Critical_CSS (file resolution, containment,
 * stage/promote/rollback, rollout status, health gate, variant files,
 * content access, status shapes, and storage memos). Static-to-static
 * move — `self::` stays valid inside this class. Critical_CSS keeps thin
 * same-signature static proxies (facade rule) so all existing callers,
 * hooks, and method_exists guards behave identically. No option/schema
 * changes; multisite isolation via get_current_blog_id() in the template
 * hash and blog-aware transient keys is preserved.
 *
 * Load order: both classes resolve each other lazily inside method bodies
 * only (never at file scope or in constant expressions), so either class
 * may load first via Loader_Map without a circular-include failure. The
 * generation-status read additionally supports an injected callable seam
 * ({@see set_status_cache_reader()}) so unit tests can break the
 * Ccss_Store status-reader edge entirely.
 *
 * @since convention: methods moved verbatim from Critical_CSS retain their
 * original @since tags to preserve history; only new store infrastructure
 * (class, memos, constants, seams) uses @since 2.4.0.
 *
 * @package PerformanceOptimise\Inc
 * @since 2.4.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Ccss_Store' ) ) {
	/**
	 * Class Ccss_Store
	 *
	 * File lifecycle + status for critical CSS. Generation/parsing/output,
	 * exclusions, retry/budget scheduling, and hook registrations stay on
	 * Critical_CSS.
	 *
	 * @since 2.4.0
	 */
	class Ccss_Store {
		/**
		 * Directory for CCSS files.
		 *
		 * Canonical single source (FUT-001 review): Critical_CSS no longer
		 * declares its own copy; remaining callers resolve via
		 * {@see get_ccss_dir()} / {@see get_ccss_url()}.
		 *
		 * @var string
		 * @since 2.4.0
		 */
		public const CCSS_DIR = '/cache/wppo/ccss';

		/**
		 * Shared traversal-guard pattern for template hashes.
		 *
		 * Canonical single source (FUT-001 review): Critical_CSS no longer
		 * declares its own copy; validation flows through
		 * {@see is_valid_template_hash()} / {@see get_ccss_file()}.
		 *
		 * @since 2.4.0
		 * @var string
		 */
		public const TEMPLATE_HASH_PATTERN = '/^[A-Za-z0-9_\-]{1,128}$/';

		/**
		 * Viewport-split variant slugs.
		 *
		 * Canonical single source (FUT-001 review). Critical_CSS keeps a
		 * public BC alias with the identical value (pinned by
		 * CcssStoreParityTest) while its generation code reads this
		 * constant directly.
		 *
		 * @since 2.4.0
		 * @var string[]
		 */
		public const VIEWPORT_VARIANTS = array( 'mobile', 'desktop' );

		/**
		 * Per-request CCSS existence memo keyed by template hash.
		 *
		 * Moved with ccss_exists() from Critical_CSS. Reset via
		 * reset_ccss_memo().
		 *
		 * @since 2.4.0
		 * @var array<string, bool>
		 */
		private static array $ccss_exists_cache = array();

		/**
		 * Per-request CCSS content memo keyed by "hash:mtime:size".
		 *
		 * Moved with get_ccss_content() from Critical_CSS. Reset via
		 * reset_ccss_memo().
		 *
		 * @since 2.4.0
		 * @var array<string, string|null>
		 */
		private static array $ccss_content_cache = array();

		/**
		 * Per-request sample-URL memo keyed by "{blog_id}:{template}".
		 *
		 * Moved with get_sample_url() from Critical_CSS. Reset via
		 * reset_ccss_memo().
		 *
		 * @since 2.4.0
		 * @var array<string, string|false>
		 */
		private static array $sample_url_cache = array();

		/**
		 * Per-request memo of the theme template list.
		 *
		 * Moved with get_templates() from Critical_CSS. Reset via
		 * reset_ccss_memo().
		 *
		 * @since 2.4.0
		 * @var array<string, string>|null
		 */
		private static ?array $templates_memo = null;

		/**
		 * Injected generation-status reader (FUT-001 review).
		 *
		 * Breaks the Ccss_Store status reader seam for unit isolation: when
		 * set, {@see get_status_all()} calls this instead of the canonical
		 * Ccss_Generator reader. Production leaves it null so the
		 * salted/transient source of truth stays single-sourced on
		 * Ccss_Generator. Not cleared by
		 * {@see reset_ccss_memo()} — reset explicitly with null.
		 *
		 * @since 2.4.0
		 * @var callable|null
		 */
		private static $status_cache_reader = null;

		/**
		 * Inject (or clear) the generation-status reader.
		 *
		 * @internal Test seam to break the Ccss_Store -> Critical_CSS edge.
		 * Pass null to restore the default Critical_CSS bridge.
		 *
		 * @since 2.4.0
		 * @param callable|null $reader Status reader: fn( string $hash ): string|false.
		 * @return void
		 */
		public static function set_status_cache_reader( $reader ): void {
			self::$status_cache_reader = is_callable( $reader ) ? $reader : null;
		}

		/**
		 * Get the CCSS directory path.
		 *
		 * @return string
		 * @since 2.0.0 Moved verbatim from Critical_CSS (FUT-001); original tag retained.
		 */
		public static function get_ccss_dir(): string {
			if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
				return '';
			}
			return wp_normalize_path( WP_CONTENT_DIR . self::CCSS_DIR );
		}

		/**
		 * Get the CCSS directory URL.
		 *
		 * @return string
		 * @since 2.0.0
		 */
		public static function get_ccss_url(): string {
			if ( ! defined( 'WP_CONTENT_DIR' ) || '' === WP_CONTENT_DIR ) {
				return '';
			}
			return WP_CONTENT_URL . self::CCSS_DIR;
		}

		/**
		 * Get the CCSS file path for a template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @return string Full file path.
		 * @since 2.0.0
		 */
		public static function get_ccss_file( string $template_hash ): string {
			// Defense-in-depth: allow word chars + dash only so a caller
			// passing ../../foo can never escape the ccss dir via this
			// delete-capable sink (slashes, dots and NUL are rejected).
			if ( '' === $template_hash || 1 !== preg_match( self::TEMPLATE_HASH_PATTERN, $template_hash ) ) {
				return '';
			}
			$dir = self::get_ccss_dir();
			if ( '' === $dir ) {
				return '';
			}
			return $dir . '/' . $template_hash . '.css';
		}

		/**
		 * Whether a path stays inside the CCSS directory (issue #1348).
		 *
		 * Containment validator for the staged/fallback slot helpers:
		 * the normalized path must live under {@see get_ccss_dir()}.
		 * All slot paths derive from {@see get_ccss_file()} (which
		 * rejects anything but word chars + dash), so this is
		 * defense-in-depth against future callers. Never throws.
		 *
		 * @param string $path Absolute path to check.
		 * @return bool True when contained.
		 * @since 2.3.0
		 */
		public static function is_ccss_path_contained( string $path ): bool {
			try {
				if ( '' === $path || false !== strpos( $path, "\0" ) ) {
					return false;
				}
				$dir = self::get_ccss_dir();
				if ( '' === $dir ) {
					return false;
				}
				if ( function_exists( 'wp_normalize_path' ) ) {
					$path = wp_normalize_path( $path );
					$dir  = wp_normalize_path( $dir );
				}
				return 0 === strpos( $path, rtrim( $dir, '/' ) . '/' );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Whether a template hash is safe to queue, store, or log.
		 *
		 * Scheduler args are DB-backed untrusted input: only word chars
		 * plus dash (1-128) are accepted so traversal payloads can never
		 * pollute the AS/WP-Cron queue or escape the CCSS directory.
		 *
		 * @param mixed $hash Candidate hash.
		 * @return bool True when the hash is a valid template hash.
		 * @since 2.2.0
		 */
		public static function is_valid_template_hash( $hash ): bool {
			return is_string( $hash ) && '' !== $hash && 1 === preg_match( self::TEMPLATE_HASH_PATTERN, $hash );
		}

		/**
		 * Stage critical CSS for a template without going live (issue #1348).
		 *
		 * Sanitizes via the shared {@see Util::sanitize_css_for_storage()}
		 * choke point plus the storage-bounds check (mirroring the live
		 * write path), then persists to the staged sibling instead of the
		 * live file. Returns preview metadata for the status UI. Never
		 * touches variant mirrors, source checksums, or the live file.
		 * Fail-open: any refusal returns `staged => false`. Never throws.
		 *
		 * @param string $template_hash Validated template hash.
		 * @param string $css           Raw critical-CSS content.
		 * @return array{staged: bool, reason: string, bytes: int, checksum: string, live_bytes: int, live_checksum: string, changed: bool} Preview metadata.
		 * @since 2.3.0
		 */
		public static function stage_ccss_for_template( string $template_hash, string $css ): array {
			$refused = static function ( string $reason ): array {
				return array(
					'staged'        => false,
					'reason'        => $reason,
					'bytes'         => 0,
					'checksum'      => '',
					'live_bytes'    => 0,
					'live_checksum' => '',
					'changed'       => false,
				);
			};
			try {
				if ( '' === $css || ! self::is_valid_template_hash( $template_hash ) ) {
					return $refused( '' === $css ? 'empty' : 'hash' );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'sanitize_css_for_storage' ) ) {
					$css = Util::sanitize_css_for_storage( $css );
				}
				if ( '' === $css ) {
					return $refused( 'sanitize' );
				}
				if ( class_exists( 'PerformanceOptimise\Inc\Util' ) && method_exists( 'PerformanceOptimise\Inc\Util', 'css_within_storage_bounds' ) && ! Util::css_within_storage_bounds( $css ) ) {
					return $refused( 'bounds' );
				}
				$live = self::get_ccss_file( $template_hash );
				if ( '' === $live ) {
					return $refused( 'path' );
				}
				$staged = Util::get_staged_path_for( $live );
				if ( '' === $staged || ! self::is_ccss_path_contained( $staged ) ) {
					return $refused( 'path' );
				}
				$filesystem = Util::init_filesystem();
				if ( ! $filesystem ) {
					return $refused( 'filesystem' );
				}
				if ( ! Util::atomic_file_put_contents( $filesystem, $staged, $css ) ) {
					return $refused( 'write' );
				}
				self::invalidate_ccss_memo( $template_hash );
				$slot = Util::describe_rollout_slot( $filesystem, $live );
				return array(
					'staged'        => true,
					'reason'        => '',
					'bytes'         => (int) $slot['staged_bytes'],
					'checksum'      => (string) $slot['staged_checksum'],
					'live_bytes'    => (int) $slot['live_bytes'],
					'live_checksum' => (string) $slot['live_checksum'],
					'changed'       => (bool) $slot['staged_changed'],
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return $refused( 'error' );
			}
		}

		/**
		 * Promote staged critical CSS over the live file (issue #1348).
		 *
		 * The staged sibling replaces the live file via
		 * {@see Util::promote_staged_file()} (last-good retained first),
		 * variant mirrors are refreshed best-effort like the live write
		 * path, and the promotion is activity-logged. Never throws.
		 *
		 * @param string $template_hash Validated template hash.
		 * @return bool True when the staged file replaced the live file.
		 * @since 2.3.0
		 */
		public static function promote_staged_ccss( string $template_hash ): bool {
			try {
				if ( ! self::is_valid_template_hash( $template_hash ) ) {
					return false;
				}
				$live = self::get_ccss_file( $template_hash );
				if ( '' === $live ) {
					return false;
				}
				$filesystem = Util::init_filesystem();
				if ( ! $filesystem ) {
					return false;
				}
				$promoted = Util::promote_staged_file(
					$filesystem,
					static function ( string $path ): bool {
						return self::is_ccss_path_contained( $path );
					},
					$live
				);
				if ( ! $promoted ) {
					return false;
				}
				self::invalidate_ccss_memo( $template_hash );
				if ( function_exists( 'clearstatcache' ) ) {
					clearstatcache( true, $live );
				}
				// Refresh viewport mirrors from the promoted live file
				// (best-effort, mirrors the live write path).
				try {
					if ( Critical_CSS::is_viewport_variants_enabled() && method_exists( $filesystem, 'get_contents' ) ) {
						$content = $filesystem->get_contents( $live );
						if ( is_string( $content ) && '' !== $content ) {
							foreach ( self::VIEWPORT_VARIANTS as $variant ) {
								$variant_file = self::get_ccss_variant_file( $template_hash, $variant );
								if ( '' !== $variant_file ) {
									Util::atomic_file_put_contents( $filesystem, $variant_file, $content );
									if ( function_exists( 'clearstatcache' ) ) {
										clearstatcache( true, $variant_file );
									}
								}
							}
						}
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
						Log::add( 'WPPO critical-CSS staged output promoted for template hash ' . $template_hash . '.' );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Roll back critical CSS to the retained last-good fallback (issue #1348).
		 *
		 * Copies the fallback payload over the live file (fallback kept
		 * for repeated restores) and activity-logs the outcome with its
		 * reason. A missing/empty fallback is a no-op `false`. Never
		 * throws.
		 *
		 * @param string $template_hash Validated template hash.
		 * @param string $reason        Machine-readable reason recorded in the activity log.
		 * @return bool True when the fallback payload replaced the live file.
		 * @since 2.3.0
		 */
		public static function rollback_ccss_to_fallback( string $template_hash, string $reason = 'manual' ): bool {
			try {
				if ( ! self::is_valid_template_hash( $template_hash ) ) {
					return false;
				}
				$live = self::get_ccss_file( $template_hash );
				if ( '' === $live ) {
					return false;
				}
				$filesystem = Util::init_filesystem();
				if ( ! $filesystem ) {
					return false;
				}
				$restored = Util::restore_fallback_file(
					$filesystem,
					static function ( string $path ): bool {
						return self::is_ccss_path_contained( $path );
					},
					$live
				);
				try {
					if ( class_exists( 'PerformanceOptimise\Inc\Log' ) && method_exists( 'PerformanceOptimise\Inc\Log', 'add' ) ) {
						Log::add( 'WPPO critical-CSS rollback for template hash ' . $template_hash . ': ' . ( $restored ? 'restored last-good (' . $reason . ')' : 'no fallback available (' . $reason . ')' ) . '.' );
					}
				} catch ( \Throwable $e ) {
					unset( $e );
				}
				if ( ! $restored ) {
					return false;
				}
				self::invalidate_ccss_memo( $template_hash );
				if ( function_exists( 'clearstatcache' ) ) {
					clearstatcache( true, $live );
				}
				return true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Describe the critical-CSS safe-rollout slot (issue #1348).
		 *
		 * Read-only status payload: live/staged presence with sizes and
		 * checksums, staged-changed flag, fallback availability, plus a
		 * computed health state (`healthy` when live serves,
		 * `restorable` when live is broken but a fallback exists,
		 * `degraded` otherwise). Never restores — use
		 * {@see verify_ccss_health()} for the restoring gate. Never throws.
		 *
		 * @param string $template_hash Validated template hash.
		 * @return array{live_bytes: int, live_checksum: string, staged: bool, staged_bytes: int, staged_checksum: string, staged_changed: bool, fallback: bool, health: string} Slot description.
		 * @since 2.3.0
		 */
		public static function get_ccss_rollout_status( string $template_hash ): array {
			try {
				if ( ! self::is_valid_template_hash( $template_hash ) ) {
					return array(
						'live_bytes'      => 0,
						'live_checksum'   => '',
						'staged'          => false,
						'staged_bytes'    => 0,
						'staged_checksum' => '',
						'staged_changed'  => false,
						'fallback'        => false,
						'health'          => 'degraded',
					);
				}
				$live = self::get_ccss_file( $template_hash );
				if ( '' === $live ) {
					return array(
						'live_bytes'      => 0,
						'live_checksum'   => '',
						'staged'          => false,
						'staged_bytes'    => 0,
						'staged_checksum' => '',
						'staged_changed'  => false,
						'fallback'        => false,
						'health'          => 'degraded',
					);
				}
				$filesystem = Util::init_filesystem();
				if ( ! $filesystem ) {
					return array(
						'live_bytes'      => 0,
						'live_checksum'   => '',
						'staged'          => false,
						'staged_bytes'    => 0,
						'staged_checksum' => '',
						'staged_changed'  => false,
						'fallback'        => false,
						'health'          => 'degraded',
					);
				}
				$slot           = Util::describe_rollout_slot( $filesystem, $live );
				$slot['health'] = (int) $slot['live_bytes'] > 0 ? 'healthy' : ( (bool) $slot['fallback'] ? 'restorable' : 'degraded' );
				return $slot;
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'live_bytes'      => 0,
					'live_checksum'   => '',
					'staged'          => false,
					'staged_bytes'    => 0,
					'staged_checksum' => '',
					'staged_changed'  => false,
					'fallback'        => false,
					'health'          => 'degraded',
				);
			}
		}

		/**
		 * Verify critical-CSS health, auto-restoring last-good (issue #1348).
		 *
		 * Post-apply health gate: a missing/empty live file restores the
		 * retained fallback automatically with reason logging; a healthy
		 * live file reports `healthy` with no writes. Never throws.
		 *
		 * @param string $template_hash Validated template hash.
		 * @return array{status: string, reason: string, bytes: int} One of healthy|restored|degraded.
		 * @since 2.3.0
		 */
		public static function verify_ccss_health( string $template_hash ): array {
			try {
				$slot = self::get_ccss_rollout_status( $template_hash );
				if ( (int) $slot['live_bytes'] > 0 ) {
					return array(
						'status' => 'healthy',
						'reason' => 'live',
						'bytes'  => (int) $slot['live_bytes'],
					);
				}
				$restored = self::rollback_ccss_to_fallback( $template_hash, 'health-gate' );
				if ( $restored ) {
					$after = self::get_ccss_rollout_status( $template_hash );
					return array(
						'status' => 'restored',
						'reason' => 'fallback',
						'bytes'  => (int) $after['live_bytes'],
					);
				}
				return array(
					'status' => 'degraded',
					'reason' => (bool) $slot['fallback'] ? 'restore-failed' : 'no-fallback',
					'bytes'  => 0,
				);
			} catch ( \Throwable $e ) {
				unset( $e );
				return array(
					'status' => 'degraded',
					'reason' => 'error',
					'bytes'  => 0,
				);
			}
		}

		/**
		 * Get the CCSS file path for a viewport-split variant.
		 *
		 * Only `mobile` / `desktop` slugs are accepted; anything else returns
		 * '' so callers fall back to the single variant. Same traversal guard
		 * as {@see get_ccss_file()}.
		 *
		 * @param string $template_hash Template hash.
		 * @param string $variant       Variant slug ('mobile'|'desktop').
		 * @return string Full file path, or '' when refused.
		 * @since 2.2.0
		 */
		public static function get_ccss_variant_file( string $template_hash, string $variant ): string {
			if ( ! in_array( $variant, self::VIEWPORT_VARIANTS, true ) ) {
				return '';
			}
			if ( '' === $template_hash || 1 !== preg_match( self::TEMPLATE_HASH_PATTERN, $template_hash ) ) {
				return '';
			}
			$dir = self::get_ccss_dir();
			if ( '' === $dir ) {
				return '';
			}
			return $dir . '/' . $template_hash . '.' . $variant . '.css';
		}

		/**
		 * Read a viewport-split variant with fail-open fallback (issue #1164).
		 *
		 * When variants are disabled, or the variant file is missing, stale
		 * (older than the single variant), empty, or unreadable, the existing
		 * single CCSS is returned instead. Returns null only when no usable
		 * CSS exists at all — callers then serve the deferred full stylesheet
		 * (never fatal, never white-screen).
		 *
		 * @param string $template_hash Template hash.
		 * @param string $variant       Variant slug ('mobile'|'desktop').
		 * @return string|null Variant or single CCSS content, or null when missing.
		 * @since 2.2.0
		 */
		public static function get_ccss_variant_content( string $template_hash, string $variant ): ?string {
			try {
				$single = self::get_ccss_content( $template_hash );
				if ( ! Critical_CSS::is_viewport_variants_enabled() ) {
					return $single;
				}
				$file = self::get_ccss_variant_file( $template_hash, $variant );
				if ( '' === $file || ! file_exists( $file ) ) {
					return $single;
				}
				if ( self::is_variant_stale( $template_hash, $variant ) ) {
					return $single;
				}
				$size = filesize( $file );
				if ( false === $size || $size <= 0 || $size > 1048576 ) {
					return $single;
				}
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file outside the WP filesystem abstraction.
				$content = file_get_contents( $file );
				if ( ! is_string( $content ) || '' === $content ) {
					return $single;
				}
				return $content;
			} catch ( \Throwable $e ) {
				unset( $e );
				try {
					return self::get_ccss_content( $template_hash );
				} catch ( \Throwable $inner ) {
					unset( $inner );
					return null;
				}
			}
		}

		/**
		 * Whether a viewport-split variant is stale (issue #1164).
		 *
		 * A variant is stale when the single `{hash}.css` file exists and is
		 * newer than the `{hash}.{variant}.css` file, or when the variant is
		 * missing/unstatable. Missing single variant is not stale — there is
		 * nothing to fall back to yet.
		 *
		 * @param string $template_hash Template hash.
		 * @param string $variant       Variant slug ('mobile'|'desktop').
		 * @return bool True when the variant must not be served.
		 * @since 2.2.0
		 */
		public static function is_variant_stale( string $template_hash, string $variant ): bool {
			try {
				$variant_file = self::get_ccss_variant_file( $template_hash, $variant );
				if ( '' === $variant_file || ! file_exists( $variant_file ) ) {
					return true;
				}
				$single_file = self::get_ccss_file( $template_hash );
				if ( '' === $single_file || ! file_exists( $single_file ) ) {
					return false;
				}
				$variant_mtime = filemtime( $variant_file );
				$single_mtime  = filemtime( $single_file );
				if ( false === $variant_mtime || false === $single_mtime ) {
					return true;
				}
				return $variant_mtime < $single_mtime;
			} catch ( \Throwable $e ) {
				unset( $e );
				return true;
			}
		}

		/**
		 * Delete stale viewport-split variants for a template (issue #1164).
		 *
		 * Fail-open: any error leaves files in place; serving still falls back
		 * to the single variant via {@see get_ccss_variant_content()}.
		 *
		 * @param string $template_hash Template hash.
		 * @return void
		 * @since 2.2.0
		 */
		public static function invalidate_stale_variants( string $template_hash ): void {
			try {
				foreach ( self::VIEWPORT_VARIANTS as $variant ) {
					if ( ! self::is_variant_stale( $template_hash, $variant ) ) {
						continue;
					}
					// Only delete a variant that is stale *against* a newer
					// single file; a variant with no single file yet is kept
					// (is_variant_stale returns false there, so unreachable).
					$variant_file = self::get_ccss_variant_file( $template_hash, $variant );
					$single_file  = self::get_ccss_file( $template_hash );
					if ( '' === $variant_file || '' === $single_file || ! file_exists( $single_file ) ) {
						continue;
					}
					if ( ! file_exists( $variant_file ) ) {
						continue;
					}
					$filesystem = Util::init_filesystem();
					if ( $filesystem ) {
						$filesystem->delete( $variant_file );
					} else {
						// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Local cache invalidation fallback.
						unlink( $variant_file );
					}
					self::invalidate_ccss_memo( $template_hash );
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
		}

		/**
		 * File-first CCSS URL with mtime cache busting.
		 *
		 * Per-template variants are already stored as files; this exposes them
		 * for over-cap delivery as `<hash>.css?ver=<mtime>` so a regenerate
		 * automatically busts browser/CDN caches. The URL passes through
		 * CDN::rewrite_url() when a CDN mapping is configured.
		 *
		 * @param string $template_hash Template hash.
		 * @return string File URL with mtime version, or '' when unavailable.
		 * @since 2.0.0
		 */
		public static function get_ccss_file_url( string $template_hash ): string {
			$file = self::get_ccss_file( $template_hash );
			$base = self::get_ccss_url();
			if ( '' === $file || '' === $base || ! file_exists( $file ) ) {
				return '';
			}
			$mtime = filemtime( $file );
			if ( false === $mtime ) {
				return '';
			}
			$url = $base . '/' . $template_hash . '.css?ver=' . $mtime;
			if ( class_exists( 'PerformanceOptimise\Inc\CDN' ) && method_exists( 'PerformanceOptimise\Inc\CDN', 'rewrite_url' ) ) {
				$url = CDN::rewrite_url( $url );
			}
			return $url;
		}

		/**
		 * Size metadata for a stored CCSS variant.
		 *
		 * Computed live from the file so it is always fresh (no extra
		 * transient to invalidate on regenerate); `truncated` reports whether
		 * the variant exceeds the configured inline cap.
		 *
		 * @param string $template_hash Template hash.
		 * @return array{size: int, truncated: bool, mtime: int} Size in bytes,
		 *                                                      over-cap flag, and file mtime (0 when missing).
		 * @since 2.0.0
		 */
		public static function get_ccss_meta( string $template_hash ): array {
			$file = self::get_ccss_file( $template_hash );
			if ( '' === $file || ! file_exists( $file ) ) {
				return array(
					'size'      => 0,
					'truncated' => false,
					'mtime'     => 0,
				);
			}
			$size  = filesize( $file );
			$mtime = filemtime( $file );
			$size  = false === $size ? 0 : (int) $size;
			return array(
				'size'      => $size,
				'truncated' => $size > Critical_CSS::get_ccss_max_size(),
				'mtime'     => false === $mtime ? 0 : (int) $mtime,
			);
		}

		/**
		 * Check if CCSS exists for a template hash.
		 *
		 * @param string $template_hash The template hash.
		 * @return bool
		 * @since 2.0.0 Per-request memo (audit #874 finding 7), reset via reset_ccss_memo().
		 */
		public static function ccss_exists( string $template_hash ): bool {
			// Per-request memo (audit #874 finding 7): get_status_all() stats
			// one file per template and REST consumers may call it repeatedly
			// within a request. The memo is invalidated by the mutators
			// (reset_ccss_memo from generate_and_store / clear_all), so a
			// same-request generation stays visible.
			if ( array_key_exists( $template_hash, self::$ccss_exists_cache ) ) {
				return self::$ccss_exists_cache[ $template_hash ];
			}

			self::$ccss_exists_cache[ $template_hash ] = file_exists( self::get_ccss_file( $template_hash ) );

			return self::$ccss_exists_cache[ $template_hash ];
		}

		/**
		 * Read the critical CSS for a template hash through a per-request
		 * content memo keyed by mtime.
		 *
		 * Avoids the double stat + read per frontend hit (audit #874 finding
		 * 7): a regenerated file has a new mtime and therefore re-reads, so
		 * the inline contract is unaffected. Returns null when the file is
		 * missing or unreadable.
		 *
		 * @since 2.0.0
		 * @param string $template_hash Template hash.
		 * @return string|null
		 */
		public static function get_ccss_content( string $template_hash ): ?string {
			$file = self::get_ccss_file( $template_hash );
			if ( ! self::ccss_exists( $template_hash ) ) {
				return null;
			}

			$mtime = filemtime( $file );
			if ( false === $mtime ) {
				return null;
			}

			// mtime has 1-second granularity; include the size so two
			// regenerations within the same second cannot serve stale content
			// for the rest of the request.
			$filesize  = filesize( $file );
			$cache_key = $template_hash . ':' . $mtime . ':' . ( false === $filesize ? -1 : $filesize );
			if ( array_key_exists( $cache_key, self::$ccss_content_cache ) ) {
				return self::$ccss_content_cache[ $cache_key ];
			}

			// Size guard: a corrupted or adversarially large cache file must
			// not be buffered entirely into memory. Treat over-cap files as
			// a cache miss (same 1MB cap pattern as System_Info/Object_Cache).
			if ( false === $filesize || $filesize > 1048576 ) {
				self::$ccss_content_cache[ $cache_key ] = null;
				return null;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local cache file outside the WP filesystem abstraction.
			$content = file_get_contents( $file );
			// An empty file is a failed generation: treat it as missing so the
			// caller queues background regeneration instead of looping on the
			// sub-500B guard forever.
			self::$ccss_content_cache[ $cache_key ] = is_string( $content ) && '' !== $content ? $content : null;

			return self::$ccss_content_cache[ $cache_key ];
		}

		/**
		 * Get all registered template hashes and their status.
		 *
		 * Returns an associative array where keys are template hashes and values
		 * are arrays with 'status', 'label', 'size', and 'truncated' keys. The
		 * size fields are computed live from the stored file variants so the
		 * SPA can display the capped state without an extra lookup.
		 *
		 * @return array<string, array{status: string, label: string, size: int, truncated: bool}> Template hash => status + label + size.
		 * @since 2.0.0
		 */
		public static function get_status_all(): array {
			$templates = self::get_templates();
			$statuses  = array();

			$allowed = array( 'queued', 'processing', 'done', 'skipped', 'failed', 'rejected', 'ready', 'pending', 'none' );
			foreach ( $templates as $template => $label ) {
				$hash = Critical_CSS::get_template_hash( $template );
				if ( self::ccss_exists( $hash ) ) {
					$meta              = self::get_ccss_meta( $hash );
					$statuses[ $hash ] = array(
						'status'    => 'done',
						'label'     => $label,
						'size'      => $meta['size'],
						'truncated' => $meta['truncated'],
					);
				} else {
					$reader       = self::$status_cache_reader;
					$cache_status = is_callable( $reader ) ? $reader( $hash ) : Ccss_Generator::get_status_cache( $hash );
					if ( ! is_string( $cache_status ) || ! in_array( $cache_status, $allowed, true ) ) {
						$cache_status = 'none';
					} elseif ( 'ready' === $cache_status ) {
						// Legacy alias: pre-#1274 success marker reads as done.
						$cache_status = 'done';
					} elseif ( 'pending' === $cache_status ) {
						// Legacy alias: pre-#1274 queued marker reads as queued.
						$cache_status = 'queued';
					}
					$statuses[ $hash ] = array(
						'status'    => $cache_status,
						'label'     => $label,
						'size'      => 0,
						'truncated' => false,
					);
				}
				// Safe-rollout slot (issue #1348): staged/health truth for
				// the status UI. Read-only stats — never restores; the
				// restoring gate is verify_ccss_health().
				$statuses[ $hash ]['rollout'] = self::get_ccss_rollout_status( $hash );
			}

			return $statuses;
		}

		/**
		 * Get the list of supported templates.
		 *
		 * @return array<string, string> Template identifier => Label.
		 * @since 2.0.0
		 */
		public static function get_templates(): array {
			if ( null !== self::$templates_memo ) {
				return self::$templates_memo;
			}
			$templates = array(
				'index'   => __( 'Default', 'performance-optimisation' ),
				'home'    => __( 'Home', 'performance-optimisation' ),
				'single'  => __( 'Single Post', 'performance-optimisation' ),
				'page'    => __( 'Page', 'performance-optimisation' ),
				'archive' => __( 'Archive', 'performance-optimisation' ),
			);

			$page_templates = function_exists( 'get_page_templates' ) ? get_page_templates() : array();
			if ( ! empty( $page_templates ) ) {
				foreach ( $page_templates as $label => $file ) {
					$templates[ $file ] = $label;
				}
			}

			self::$templates_memo = $templates;
			return $templates;
		}

		/**
		 * Get a sample URL for a given template.
		 *
		 * @param string $template Template identifier.
		 * @return string|false URL or false if not found.
		 * @since 2.0.0
		 */
		public static function get_sample_url( string $template ): string|false {
			$blog_id   = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 0;
			$cache_key = $blog_id . ':' . $template;
			if ( array_key_exists( $cache_key, self::$sample_url_cache ) ) {
				return self::$sample_url_cache[ $cache_key ];
			}
			switch ( $template ) {
				case 'home':
					self::$sample_url_cache[ $cache_key ] = Util::cached_home_url( '/' );
					break;
				case 'single':
					$posts                                = get_posts(
						array(
							'numberposts'   => 1,
							'post_status'   => 'publish',
							'has_password'  => false,
							'fields'        => 'ids',
							'no_found_rows' => true,
						)
					);
					self::$sample_url_cache[ $cache_key ] = ! empty( $posts ) ? get_permalink( $posts[0] ) : false;
					break;
				case 'page':
					$pages                                = get_posts(
						array(
							'post_type'     => 'page',
							'numberposts'   => 1,
							'post_status'   => 'publish',
							'has_password'  => false,
							'fields'        => 'ids',
							'no_found_rows' => true,
						)
					);
					self::$sample_url_cache[ $cache_key ] = ! empty( $pages ) ? get_permalink( $pages[0] ) : Util::cached_home_url( '/' );
					break;
				case 'archive':
					$archives = get_posts(
						array(
							'numberposts'   => 1,
							'post_status'   => 'publish',
							'has_password'  => false,
							'fields'        => 'ids',
							'no_found_rows' => true,
						)
					);
					if ( ! empty( $archives ) ) {
						setup_postdata( $archives[0] );
						$year  = get_the_time( 'Y' );
						$month = get_the_time( 'm' );
						wp_reset_postdata();
						self::$sample_url_cache[ $cache_key ] = get_month_link( $year, $month );
					} else {
						self::$sample_url_cache[ $cache_key ] = false;
					}
					break;
				default:
					self::$sample_url_cache[ $cache_key ] = Util::cached_home_url( '/' );
					break;
			}
			return self::$sample_url_cache[ $cache_key ];
		}

		/**
		 * Reset the per-request CCSS storage memos (existence/content/sample-URL/templates).
		 *
		 * Storage-domain half of {@see \PerformanceOptimise\Inc\Critical_CSS::reset_ccss_memo()}.
		 * The generation-domain half lives on Critical_CSS; its facade resets
		 * both halves so existing callers stay identical.
		 *
		 * @since 2.4.0
		 * @return void
		 */
		public static function reset_ccss_memo(): void {
			self::$ccss_exists_cache  = array();
			self::$ccss_content_cache = array();
			self::$sample_url_cache   = array();
			self::$templates_memo     = null;
		}

		/**
		 * Invalidate the per-request CCSS memos for a single template hash.
		 *
		 * Unlike reset_ccss_memo() this keeps entries for other templates so a
		 * single-template write inside a multi-template loop (get_status_all →
		 * bulk regeneration) does not evict unrelated memo entries.
		 *
		 * @since 2.0.0
		 * @param string $template_hash The template hash.
		 * @return void
		 */
		public static function invalidate_ccss_memo( string $template_hash ): void {
			unset( self::$ccss_exists_cache[ $template_hash ] );
			foreach ( array_keys( self::$ccss_content_cache ) as $key ) {
				// Content keys are "{hash}:{mtime}:{size}" — drop every
				// versioned entry belonging to this hash.
				if ( 0 === strpos( $key, $template_hash . ':' ) ) {
					unset( self::$ccss_content_cache[ $key ] );
				}
			}
		}
	}
}
