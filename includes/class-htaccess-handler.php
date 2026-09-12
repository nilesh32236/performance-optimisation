<?php
/**
 * Htaccess_Handler class for the PerformanceOptimise plugin.
 *
 * Handles the generation and insertion of .htaccess rules for performance optimization.
 *
 * @package PerformanceOptimise\Inc
 * @since 1.2.0
 */

namespace PerformanceOptimise\Inc;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'PerformanceOptimise\Inc\Htaccess_Handler' ) ) {
	/**
	 * Class Htaccess_Handler
	 *
	 * Manages .htaccess rules for Gzip and Browser Caching.
	 *
	 * @since 1.2.0
	 */
	class Htaccess_Handler {

		/**
		 * The marker used for .htaccess rules.
		 *
		 * @var string
		 * @since 1.2.0
		 */
		private const MARKER = 'wppo_rules';

		/**
		 * Maximum length of a single .htaccess rule line.
		 *
		 * Lines longer than this are rejected by sanitize_rules() as
		 * probable injection payloads; legitimate plugin rules are short.
		 *
		 * @var int
		 * @since NEXT
		 */
		private const MAX_RULE_LINE_LENGTH = 4096;

		/**
		 * Updates the .htaccess rules based on plugin settings.
		 *
		 * Note on LiteSpeed ordering: `# BEGIN LSCACHE` must stay above
		 * `# BEGIN WordPress` and `# BEGIN wppo_rules`. This method uses
		 * `insert_with_markers()` with the `wppo_rules` marker only, so it
		 * never reorders the LSCACHE block — even on LiteSpeed hosts where
		 * this method is now called. Do not call `insert_with_markers()`
		 * with the `wordpress` marker or otherwise reorder markers.
		 *
		 * LiteSpeed (and OpenLiteSpeed) reads `.htaccess` like Apache. On
		 * OpenLiteSpeed a restart (`systemctl restart lsws`) is required
		 * after changes for the new rules to take effect; LSWS reloads live.
		 *
		 * @param bool $enable Whether to enable or disable the rules.
		 * @return bool True on success, false on failure.
		 * @since 1.2.0
		 */
		public static function update_rules( bool $enable = true ): bool {
			// Server gate: Nginx (including multisite-on-Nginx) ignores
			// .htaccess, so skip the write entirely and report success —
			// there is nothing to write and callers must not roll back the
			// setting. The Nginx snippet is surfaced read-only via
			// Server_Rules::get_nginx_rules() / the server_rules endpoint.
			if ( ! self::supports_htaccess() ) {
				return true;
			}

			if ( ! function_exists( 'insert_with_markers' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/misc.php' );
			}

			if ( ! function_exists( 'get_home_path' ) ) {
				require_once wp_normalize_path( ABSPATH . 'wp-admin/includes/file.php' );
			}

			$htaccess_file = wp_normalize_path( get_home_path() . '.htaccess' );

			$wp_filesystem = Util::init_filesystem();

			if ( ! $wp_filesystem ) {
				return false;
			}

			if ( ! $wp_filesystem->exists( $htaccess_file ) && ! $wp_filesystem->is_writable( dirname( $htaccess_file ) ) ) {
				return false;
			}

			if ( $wp_filesystem->exists( $htaccess_file ) && ! $wp_filesystem->is_writable( $htaccess_file ) ) {
				return false;
			}

			$rules = array();

			if ( $enable ) {
				$rules = self::get_rules();
			}

			// CRLF/response-splitting guard: reject filter-supplied rule
			// input containing embedded newlines before touching the disk.
			// Fail closed — the file stays unchanged.
			$sanitized = self::sanitize_rules( $rules );
			if ( false === $sanitized ) {
				return false;
			}
			$rules = $sanitized;

			// Backup prior rules so a failed write can restore them. Only the
			// settings-save path calls update_rules() (Main::on_settings_update()),
			// so this backup covers exactly the ordered-write contract.
			$backup = self::read_existing_rules( $htaccess_file, $wp_filesystem );

			// Identical-content skip: avoid touching the filesystem when the
			// desired block already matches the existing block (including the
			// disable-when-already-empty case). Prevents mtime churn and
			// narrows the race window for concurrent saves.
			$existing_normalized = self::normalize_rules( null === $backup ? array() : $backup );
			$desired_normalized  = self::normalize_rules( $rules );
			if ( implode( "\n", $existing_normalized ) === implode( "\n", $desired_normalized ) ) {
				return true;
			}

			// Preferred path: atomic temp+rename with post-write verification.
			// Returns null when the filesystem transport cannot support atomic
			// writes (missing methods), in which case fall through to legacy.
			$atomic = self::atomic_write_verified( $htaccess_file, $wp_filesystem, $rules );
			if ( true === $atomic ) {
				return true;
			}
			if ( false === $atomic ) {
				return false;
			}

			$result = insert_with_markers( $htaccess_file, self::MARKER, $rules );

			if ( ! $result && null !== $backup ) {
				insert_with_markers( $htaccess_file, self::MARKER, $backup );
				return false;
			}

			if ( $result && ! self::verify_after_write( $htaccess_file, $wp_filesystem, array() !== $desired_normalized ) ) {
				if ( null !== $backup ) {
					insert_with_markers( $htaccess_file, self::MARKER, $backup );
				}
				return false;
			}

			return (bool) $result;
		}

		/**
		 * Resolve the absolute path to the site .htaccess file.
		 *
		 * Centralizes the get_home_path() lookup (with ABSPATH fallback) so
		 * deactivate/uninstall paths agree on the same file. Fail-open:
		 * returns an empty string when the path cannot be resolved.
		 *
		 * @since 2.0.0
		 *
		 * @return string Absolute .htaccess path, or empty string when unresolvable.
		 */
		public static function get_htaccess_path(): string {
			try {
				if ( function_exists( 'get_home_path' ) ) {
					$home_path = get_home_path();
				} elseif ( defined( 'ABSPATH' ) ) {
					$home_path = ABSPATH;
				} else {
					return '';
				}
				if ( ! is_string( $home_path ) || '' === $home_path ) {
					return '';
				}
				if ( function_exists( 'wp_normalize_path' ) ) {
					return wp_normalize_path( rtrim( $home_path, '/\\' ) . '/.htaccess' );
				}
				return rtrim( $home_path, '/\\' ) . '/.htaccess';
			} catch ( \Throwable $e ) {
				unset( $e );
				return '';
			}
		}

		/**
		 * Whether the wppo_rules marker block is present in .htaccess.
		 *
		 * Fail-open: returns false when the file cannot be read so callers
		 * treat "unknown" as "nothing to remove".
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when the marker block exists.
		 */
		public static function has_rules(): bool {
			try {
				$htaccess_file = self::get_htaccess_path();
				if ( '' === $htaccess_file ) {
					return false;
				}
				$wp_filesystem = Util::init_filesystem();
				if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, 'exists' ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
					return false;
				}
				if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
					return false;
				}
				$contents = $wp_filesystem->get_contents( $htaccess_file );
				if ( ! is_string( $contents ) ) {
					return false;
				}
				return false !== strpos( $contents, '# BEGIN ' . self::MARKER ) || false !== strpos( $contents, '# END ' . self::MARKER );
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Remove the wppo_rules marker block and clean backup artifacts.
		 *
		 * Verified removal: delegates to update_rules(false), asserts the
		 * marker is gone via verify_after_write(), and deletes the
		 * .wppo-bak / .wppo-tmp-* siblings so teardown leaves zero residue.
		 * Fail-open: never throws; a failed delete is safe to retry on the
		 * next deactivate/uninstall.
		 *
		 * @since 2.0.0
		 *
		 * @return bool True when no marker remains (or nothing to remove).
		 */
		public static function remove_rules(): bool {
			try {
				// Server gate (mirrors update_rules()): on Nginx the file
				// is never evaluated, so sweep artifacts and report
				// success without touching it.
				if ( ! self::supports_htaccess() ) {
					self::cleanup_backup_artifacts();
					return true;
				}
				if ( ! self::has_rules() ) {
					self::cleanup_backup_artifacts();
					return true;
				}
				$ok = self::update_rules( false );
				self::cleanup_backup_artifacts();
				if ( ! $ok ) {
					return self::has_rules() ? false : true;
				}
				return self::has_rules() ? false : true;
			} catch ( \Throwable $e ) {
				unset( $e );
				return false;
			}
		}

		/**
		 * Delete the .htaccess backup sibling and orphaned tmp siblings.
		 *
		 * Best-effort only: failures are ignored so teardown never fails
		 * because of stale artifacts. Removes `.htaccess.wppo-bak` (single
		 * backup generation kept by atomic_write_verified()) and any
		 * `.htaccess.wppo-tmp-*` orphans from crashed writes.
		 *
		 * @since 2.0.0
		 *
		 * @return void
		 */
		public static function cleanup_backup_artifacts(): void {
			try {
				$htaccess_file = self::get_htaccess_path();
				if ( '' === $htaccess_file ) {
					return;
				}
				global $wp_filesystem;
				$fs = $wp_filesystem;
				if ( ! is_object( $fs ) ) {
					$fs = Util::init_filesystem();
				}
				if ( ! is_object( $fs ) || ! method_exists( $fs, 'delete' ) ) {
					return;
				}
				$backup_file = $htaccess_file . '.wppo-bak';
				if ( method_exists( $fs, 'exists' ) ) {
					if ( $fs->exists( $backup_file ) ) {
						$fs->delete( $backup_file );
					}
				} else {
					$fs->delete( $backup_file );
				}
				if ( ! method_exists( $fs, 'dirlist' ) || ! method_exists( $fs, 'exists' ) ) {
					return;
				}
				$dir = dirname( $htaccess_file );
				if ( function_exists( 'wp_normalize_path' ) ) {
					$dir = wp_normalize_path( $dir );
				}
				if ( ! $fs->exists( $dir ) ) {
					return;
				}
				$listing = $fs->dirlist( $dir );
				if ( ! is_array( $listing ) ) {
					return;
				}
				$base = basename( $htaccess_file );
				foreach ( $listing as $name => $info ) {
					$name = (string) $name;
					if ( 0 === strpos( $name, $base . '.wppo-tmp-' ) ) {
						$fs->delete( $dir . '/' . $name );
					}
				}
			} catch ( \Throwable $ignored ) {
				unset( $ignored );
			}
		}

		/**
		 * Whether `.htaccess` writes are meaningful on this server.
		 *
		 * Delegates to Server_Rules when available (Apache and
		 * LiteSpeed/OpenLiteSpeed evaluate `.htaccess`; Nginx ignores it,
		 * including multisite-on-Nginx) and fails open to true when the
		 * server cannot be detected, preserving the legacy write behavior.
		 * Multisite-safe: pure server detection, no options or transients
		 * touched, so no cross-site leakage; only the per-site `.htaccess`
		 * path is ever written by update_rules().
		 *
		 * @since NEXT
		 * @return bool True when an `.htaccess` write may proceed.
		 */
		public static function supports_htaccess(): bool {
			try {
				if ( class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'supports_htaccess' ) ) {
					return Server_Rules::supports_htaccess();
				}
			} catch ( \Throwable $e ) {
				unset( $e );
			}
			return true;
		}

		/**
		 * Sanitize rule lines before an `.htaccess` write.
		 *
		 * Rejects (returns false) any payload that could split the marker
		 * block or smuggle extra directives: non-string lines, embedded
		 * CR/LF/NUL bytes (CRLF/response-splitting injection via the
		 * `wppo_htaccess_rules`, `wppo_htaccess_nextgen_rules`, or
		 * `wppo_htaccess_cache_vary_rules` filters), overlong lines, or
		 * forged `# BEGIN`/`# END wppo_rules` markers that would break the
		 * single-block assertion in verify_htaccess_contents(). Fail closed:
		 * callers must abort the write and leave the file unchanged.
		 *
		 * @since NEXT
		 *
		 * @param array $rules Raw rules lines.
		 * @return array|false The rules unchanged when valid, false when rejected.
		 */
		private static function sanitize_rules( array $rules ): array|false {
			foreach ( $rules as $line ) {
				if ( ! is_string( $line ) ) {
					return false;
				}
				if ( strlen( $line ) > self::MAX_RULE_LINE_LENGTH ) {
					return false;
				}
				if ( false !== strpos( $line, "\r" ) || false !== strpos( $line, "\n" ) || false !== strpos( $line, "\0" ) ) {
					return false;
				}
				if ( 1 === preg_match( '/^\s*#\s*(BEGIN|END)\s+' . preg_quote( self::MARKER, '/' ) . '\b/i', $line ) ) {
					return false;
				}
			}
			return $rules;
		}

		/**
		 * Normalize a rules array for identical-content comparison.
		 *
		 * Trims trailing empty lines so a stored block ("RuleA\n" split keeps a
		 * trailing "") compares equal to the equivalent get_rules() output.
		 * Internal blank lines and ordering are preserved.
		 *
		 * @since 2.0.0
		 *
		 * @param array $rules Raw rules lines.
		 * @return array Normalized rules lines.
		 */
		private static function normalize_rules( array $rules ): array {
			$normalized = array_values( $rules );
			while ( array() !== $normalized && '' === end( $normalized ) ) {
				array_pop( $normalized );
			}
			return $normalized;
		}

		/**
		 * Serialize a rules array into a full marker block.
		 *
		 * Byte-identical with core insert_with_markers() formatting so the
		 * identical-check, the atomic writer, and the verifier agree.
		 *
		 * @since 2.0.0
		 *
		 * @param array $rules Rules lines.
		 * @return string Marker block, or empty string when there are no rules.
		 */
		private static function build_marker_block( array $rules ): string {
			$normalized = self::normalize_rules( $rules );
			if ( array() === $normalized ) {
				return '';
			}
			return '# BEGIN ' . self::MARKER . "\n" . implode( "\n", $normalized ) . "\n# END " . self::MARKER . "\n";
		}

		/**
		 * Splice a marker block into full .htaccess contents.
		 *
		 * Replaces the existing wppo_rules block in place (preserving all other
		 * markers such as WordPress and LSCACHE and their order), appends when
		 * absent, or removes the block when the new block is empty.
		 *
		 * @since 2.0.0
		 *
		 * @param string $current   Current .htaccess contents.
		 * @param string $new_block New marker block (empty string to remove).
		 * @return string Updated .htaccess contents.
		 */
		private static function splice_block( string $current, string $new_block ): string {
			$begin = '# BEGIN ' . self::MARKER;
			$end   = '# END ' . self::MARKER;

			$start = strpos( $current, $begin );
			$stop  = strpos( $current, $end );

			if ( false !== $start && false !== $stop && $stop > $start ) {
				$line_end = strpos( $current, "\n", $stop );
				$head     = substr( $current, 0, $start );
				$tail     = false === $line_end ? '' : substr( $current, $line_end + 1 );
				if ( '' === $new_block ) {
					return rtrim( $head, "\r\n" ) . ( '' === $tail ? '' : "\n" . ltrim( $tail, "\r\n" ) );
				}
				return $head . $new_block . ltrim( $tail, "\r\n" );
			}

			if ( '' === $new_block ) {
				return $current;
			}

			if ( '' === $current ) {
				return $new_block;
			}

			return rtrim( $current, "\r\n" ) . "\n" . $new_block;
		}

		/**
		 * Verify .htaccess contents after a write.
		 *
		 * Asserts exactly one wppo block (or zero when rules were removed),
		 * BEGIN before END, and balanced <IfModule> tags inside the block.
		 * Pure string check — trivially unit-testable.
		 *
		 * @since 2.0.0
		 *
		 * @param string $contents     Full .htaccess contents.
		 * @param bool   $expect_block Whether a wppo block is expected.
		 * @return bool True when valid.
		 */
		private static function verify_htaccess_contents( string $contents, bool $expect_block ): bool {
			$begin = '# BEGIN ' . self::MARKER;
			$end   = '# END ' . self::MARKER;

			$begin_count = substr_count( $contents, $begin );
			$end_count   = substr_count( $contents, $end );

			if ( ! $expect_block ) {
				return 0 === $begin_count && 0 === $end_count;
			}

			if ( 1 !== $begin_count || 1 !== $end_count ) {
				return false;
			}

			$start = strpos( $contents, $begin );
			$stop  = strpos( $contents, $end );
			if ( false === $start || false === $stop || $stop <= $start ) {
				return false;
			}

			$block = substr( $contents, $start, $stop - $start );
			if ( ! is_string( $block ) ) {
				return false;
			}

			$open  = substr_count( $block, '<IfModule' );
			$close = substr_count( $block, '</IfModule>' );
			if ( $open !== $close ) {
				return false;
			}

			return true;
		}

		/**
		 * Re-read .htaccess and verify it after a legacy write.
		 *
		 * Fail-open: returns true when verification is impossible (missing
		 * get_contents/exists methods) so untestable transports keep the legacy
		 * behavior instead of fataling.
		 *
		 * @since 2.0.0
		 *
		 * @param string $htaccess_file Absolute path to the .htaccess file.
		 * @param mixed  $wp_filesystem WP_Filesystem instance.
		 * @param bool   $expect_block  Whether a wppo block is expected.
		 * @return bool True when valid or unverifiable.
		 */
		private static function verify_after_write( string $htaccess_file, $wp_filesystem, bool $expect_block ): bool {
			if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, 'exists' ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
				return true;
			}
			if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
				return ! $expect_block;
			}
			$contents = $wp_filesystem->get_contents( $htaccess_file );
			if ( ! is_string( $contents ) ) {
				return true;
			}
			return self::verify_htaccess_contents( $contents, $expect_block );
		}

		/**
		 * Atomically write .htaccess via temp file + rename with verification.
		 *
		 * Writes the spliced contents to a temp file in the same directory (same
		 * filesystem, so rename is atomic), keeps one backup generation
		 * (.wppo-bak), renames over the original, then re-reads and verifies
		 * exactly one wppo block. On verification failure the backup is
		 * restored so prior rules stay intact. On any temp/rename failure
		 * returns null so the caller falls back to insert_with_markers().
		 * Never leaves a missing .htaccess. Multisite-safe: only touches the
		 * per-site ABSPATH .htaccess passed in.
		 *
		 * @since 2.0.0
		 *
		 * @param string $htaccess_file Absolute path to the .htaccess file.
		 * @param mixed  $wp_filesystem WP_Filesystem instance.
		 * @param array  $rules         Desired rules lines.
		 * @return bool|null True on success, false on verified failure, null when atomic write is unsupported.
		 */
		private static function atomic_write_verified( string $htaccess_file, $wp_filesystem, array $rules ): ?bool {
			// Defense-in-depth CRLF guard: update_rules() already sanitizes,
			// but this method is reachable via reflection/tests, so fail
			// closed here too. Return false (verified failure, never null)
			// so callers must NOT fall through to the non-atomic legacy
			// insert_with_markers() path with tainted input.
			$sanitized = self::sanitize_rules( $rules );
			if ( false === $sanitized ) {
				return false;
			}
			$rules = $sanitized;

			$required = array( 'exists', 'get_contents', 'put_contents', 'move', 'copy', 'delete' );
			foreach ( $required as $method ) {
				if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, $method ) ) {
					return null;
				}
			}

			$current = '';
			if ( $wp_filesystem->exists( $htaccess_file ) ) {
				$current = $wp_filesystem->get_contents( $htaccess_file );
				if ( ! is_string( $current ) ) {
					return null;
				}
			}

			$new_block    = self::build_marker_block( $rules );
			$new_contents = self::splice_block( $current, $new_block );

			if ( $new_contents === $current ) {
				return true;
			}

			$expect_block = array() !== self::normalize_rules( $rules );
			$backup_file  = $htaccess_file . '.wppo-bak';

			if ( '' !== $current ) {
				// Best-effort single backup generation; in-memory $current
				// remains the authoritative restore source if copy fails.
				$wp_filesystem->copy( $htaccess_file, $backup_file, true );
			}

			// Unique tmp suffix: wp_rand() is wrapped in try/catch (mirroring
			// Advanced_Cache_Handler::atomic_write_dropin()): under Brain
			// Monkey a stale wp_rand stub from another test can throw once
			// its session tore down, and production filters must never let
			// a suffix RNG failure break the write. PID + uniqid segments
			// widen the suffix space so two concurrent settings saves never
			// share a tmp name and clobber each other (last-writer-wins on
			// the rename is safe: both writers splice from current state
			// and post-write verification checksums the winner).
			if ( function_exists( 'wp_rand' ) ) {
				try {
					$suffix = (string) wp_rand( 100000, 999999 );
				} catch ( \Throwable $ignored_rand ) {
					unset( $ignored_rand );
					if ( function_exists( 'mt_rand' ) ) {
						$suffix = (string) mt_rand( 100000, 999999 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback when wp_rand() is unavailable or throws.
					} else {
						return null;
					}
				}
			} elseif ( function_exists( 'mt_rand' ) ) {
				$suffix = (string) mt_rand( 100000, 999999 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand -- Fallback when wp_rand() is unavailable.
			} else {
				return null;
			}
			$pid_part  = function_exists( 'getmypid' ) ? (int) getmypid() : 0;
			$uniq_part = '';
			try {
				$uniq_part = substr( md5( uniqid( (string) microtime( true ), true ) ), 0, 8 );
			} catch ( \Throwable $ignored_uniq ) {
				unset( $ignored_uniq );
			}
			if ( '' !== $uniq_part ) {
				$suffix .= '-' . $pid_part . '-' . $uniq_part;
			}
			$tmp_file = $htaccess_file . '.wppo-tmp-' . $suffix;

			$mode = defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644;
			if ( ! $wp_filesystem->put_contents( $tmp_file, $new_contents, $mode ) ) {
				if ( $wp_filesystem->exists( $tmp_file ) ) {
					$wp_filesystem->delete( $tmp_file );
				}
				return null;
			}

			if ( ! $wp_filesystem->move( $tmp_file, $htaccess_file, true ) ) {
				if ( $wp_filesystem->exists( $tmp_file ) ) {
					$wp_filesystem->delete( $tmp_file );
				}
				return null;
			}

			$written = $wp_filesystem->get_contents( $htaccess_file );
			if ( ! is_string( $written ) ) {
				$wp_filesystem->put_contents( $htaccess_file, $current, $mode );
				return false;
			}

			// Byte-identical checksum plus structural verification: a torn
			// rename or a concurrent writer interleaving must never leave a
			// truncated or foreign body behind. On mismatch restore the
			// backup (or the in-memory original) so the file stays intact.
			if ( $written !== $new_contents || ! self::verify_htaccess_contents( $written, $expect_block ) ) {
				$restored = false;
				if ( $wp_filesystem->exists( $backup_file ) ) {
					$restored = (bool) $wp_filesystem->copy( $backup_file, $htaccess_file, true );
				}
				if ( ! $restored ) {
					$restored = (bool) $wp_filesystem->put_contents( $htaccess_file, $current, $mode );
				}
				if ( ! $restored ) {
					return false;
				}
				return false;
			}

			return true;
		}

		/**
		 * Read the existing wppo_rules block as a backup.
		 *
		 * Parses the current .htaccess marker block so update_rules() can
		 * restore it when a new write fails. Returns null when the file is
		 * missing or unreadable (nothing to restore).
		 *
		 * @since 2.0.0
		 *
		 * @param string $htaccess_file Absolute path to the .htaccess file.
		 * @param mixed  $wp_filesystem WP_Filesystem instance.
		 * @return array|null Existing rules, or null when unavailable.
		 */
		private static function read_existing_rules( string $htaccess_file, $wp_filesystem ): ?array {
			if ( ! $wp_filesystem || ! method_exists( $wp_filesystem, 'exists' ) || ! method_exists( $wp_filesystem, 'get_contents' ) ) {
				return null;
			}

			if ( ! $wp_filesystem->exists( $htaccess_file ) ) {
				return null;
			}

			$contents = $wp_filesystem->get_contents( $htaccess_file );
			if ( ! is_string( $contents ) || '' === $contents ) {
				return null;
			}

			$start = strpos( $contents, '# BEGIN ' . self::MARKER );
			$end   = strpos( $contents, '# END ' . self::MARKER );
			if ( false === $start || false === $end || $end <= $start ) {
				return null;
			}

			$block = substr( $contents, $start, $end - $start );
			$lines = preg_split( "/\r\n|\n|\r/", $block );
			if ( ! is_array( $lines ) ) {
				return null;
			}

			$rules = array();
			foreach ( $lines as $line ) {
				if ( 0 === strpos( $line, '# BEGIN ' ) || 0 === strpos( $line, '# END ' ) ) {
					continue;
				}
				$rules[] = $line;
			}

			return $rules;
		}

		/**
		 * Retrieve the rules to be added to .htaccess.
		 *
		 * Includes the Accept-aware next-gen AVIF-before-WebP rewrite with
		 * `Vary: Accept` when enableNextGenRewrite is true and convertImg
		 * is enabled. Server-agnostic: plain Apache reads `.htaccess`
		 * rewrites exactly like LiteSpeed, so non-LiteSpeed Apache hosts
		 * get server-level negotiation (Firefox 65-92 matches only the
		 * WebP branch, old Safari matches neither and keeps the original).
		 * Opt-in default false. Filterable via
		 * wppo_litespeed_nextgen_rewrite.
		 *
		 * @return array Array of rules.
		 * @since  1.0.0
		 */
		public static function get_rules(): array {
			$rules = array(
				'<IfModule mod_deflate.c>',
				'    # Compress HTML, CSS, JavaScript, Text, XML, and Fonts',
				'    # NOTE: WOFF2 is omitted intentionally — WOFF2 files are already pre-compressed (Brotli)',
				'    # at the binary level, so DEFLATE would add no benefit (and can bloat output).',
				'    AddOutputFilterByType DEFLATE text/plain',
				'    AddOutputFilterByType DEFLATE text/html',
				'    AddOutputFilterByType DEFLATE text/xml',
				'    AddOutputFilterByType DEFLATE text/css',
				'    AddOutputFilterByType DEFLATE text/javascript',
				'    AddOutputFilterByType DEFLATE application/xml',
				'    AddOutputFilterByType DEFLATE application/xhtml+xml',
				'    AddOutputFilterByType DEFLATE application/rss+xml',
				'    AddOutputFilterByType DEFLATE application/javascript',
				'    AddOutputFilterByType DEFLATE application/x-javascript',
				'    AddOutputFilterByType DEFLATE application/x-font-ttf',
				'    AddOutputFilterByType DEFLATE application/vnd.ms-fontobject',
				'    AddOutputFilterByType DEFLATE font/opentype',
				'    AddOutputFilterByType DEFLATE font/truetype',
				'    AddOutputFilterByType DEFLATE application/x-font-otf',
				'    AddOutputFilterByType DEFLATE application/x-font-opentype',
				'    AddOutputFilterByType DEFLATE image/svg+xml',
				'    AddOutputFilterByType DEFLATE image/x-icon',
				'</IfModule>',
				'',
				'<IfModule mod_expires.c>',
				'    ExpiresActive On',
				'    # Default cache',
				'    ExpiresDefault "access plus 2 days"',
				'    # Dynamic items',
				'    ExpiresByType text/html "access plus 0 seconds"',
				'    # CSS and JS',
				'    ExpiresByType text/css "access plus 1 year"',
				'    ExpiresByType text/javascript "access plus 1 year"',
				'    ExpiresByType application/javascript "access plus 1 year"',
				'    ExpiresByType application/x-javascript "access plus 1 year"',
				'    # Images and Icons',
				'    ExpiresByType image/jpg "access plus 1 year"',
				'    ExpiresByType image/jpeg "access plus 1 year"',
				'    ExpiresByType image/gif "access plus 1 year"',
				'    ExpiresByType image/png "access plus 1 year"',
				'    ExpiresByType image/webp "access plus 1 year"',
				'    ExpiresByType image/avif "access plus 1 year"',
				'    ExpiresByType image/svg+xml "access plus 1 year"',
				'    ExpiresByType image/x-icon "access plus 1 year"',
				'    # Fonts',
				'    ExpiresByType application/vnd.ms-fontobject "access plus 1 year"',
				'    ExpiresByType application/x-font-ttf "access plus 1 year"',
				'    ExpiresByType application/font-woff2 "access plus 1 year"',
				'    ExpiresByType font/opentype "access plus 1 year"',
				'    ExpiresByType font/truetype "access plus 1 year"',
				'    ExpiresByType font/eot "access plus 1 year"',
				'    ExpiresByType font/otf "access plus 1 year"',
				'    ExpiresByType font/woff "access plus 1 year"',
				'    ExpiresByType font/woff2 "access plus 1 year"',
				'</IfModule>',
			);

			// LS-401: Next-gen Vary:Accept rewrite — sibling .webp/.avif.
			// Server-agnostic (Apache + LiteSpeed both read .htaccess):
			// prefer the Apache-aware gate so plain Apache hosts with
			// convertImg on get Accept-aware AVIF-before-WebP delivery.
			$use_nextgen = false;
			if ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_nextgen_rewrite_enabled_for_apache' ) ) {
				$use_nextgen = LiteSpeed_Integration::is_nextgen_rewrite_enabled_for_apache();
			} elseif ( class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && method_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration', 'is_nextgen_rewrite_enabled' ) ) {
				$use_nextgen = LiteSpeed_Integration::is_nextgen_rewrite_enabled();
				if ( ! $use_nextgen && class_exists( 'PerformanceOptimise\Inc\Server_Rules' ) && method_exists( 'PerformanceOptimise\Inc\Server_Rules', 'get_server_type' ) ) {
					// Legacy LiteSpeed-only gate misses plain Apache: fall
					// back to the raw option check for Apache hosts.
					$server = Server_Rules::get_server_type();
					if ( 'apache' === $server ) {
						$opts        = Util::get_settings();
						$use_nextgen = ! empty( $opts['litespeed_integration']['enableNextGenRewrite'] ) && ! empty( $opts['image_optimisation']['convertImg'] );
						/**
						 * Filter whether next-gen rewrite is enabled (fallback).
						 *
						 * @since 2.0.0
						 * @param bool $use_nextgen Whether next-gen rewrite is enabled.
						 */
						$use_nextgen = (bool) apply_filters( 'wppo_litespeed_nextgen_rewrite', $use_nextgen );
					}
				}
			} else {
				// Fallback when LiteSpeed_Integration not loaded: raw option
				// check with filters. Server-agnostic — .htaccess is only
				// read by Apache/LiteSpeed hosts anyway.
				$opts        = Util::get_settings();
				$enabled     = ! empty( $opts['litespeed_integration']['enableNextGenRewrite'] );
				$convert     = ! empty( $opts['image_optimisation']['convertImg'] );
				$use_nextgen = $enabled && $convert;
				/**
				 * Filter whether next-gen rewrite is enabled (fallback).
				 *
				 * @since 2.0.0
				 * @param bool $use_nextgen Whether next-gen rewrite is enabled.
				 */
				$use_nextgen = (bool) apply_filters( 'wppo_litespeed_nextgen_rewrite', $use_nextgen );
			}

			if ( $use_nextgen ) {
				$rules = array_merge(
					$rules,
					array(
						'',
						'# WPPO Next-gen delivery (LiteSpeed/Apache) — sibling .webp/.avif + wppo/ fallback',
						'# Note: WPPO converter writes to wp-content/wppo/{path}.webp; sibling layout (.webp next to original) is also checked.',
						'# This block checks sibling first, then wppo/ directory mirror. Vary: Accept correct per spec.',
						'<IfModule mod_rewrite.c>',
						'    RewriteEngine On',
						'    # Serve .avif first when the client supports it (AVIF-first: Apache applies the first matching rule)',
						'    RewriteCond %{HTTP:Accept} image/avif',
						'    RewriteCond %{REQUEST_FILENAME}.avif -f',
						'    RewriteRule ^(.+)\.(jpe?g|png)$ $1.avif [T=image/avif,E=accept:1]',
						'    # Fallback: wppo/ directory mirror for AVIF',
						'    RewriteCond %{HTTP:Accept} image/avif',
						'    RewriteCond %{DOCUMENT_ROOT}/wp-content/wppo%{REQUEST_URI}.avif -f',
						'    RewriteRule ^wp-content/(.+)\.(jpe?g|png)$ /wp-content/wppo/$1.avif [T=image/avif,E=accept:1]',
						'    # Serve .webp when client supports it and file exists (sibling)',
						'    RewriteCond %{HTTP:Accept} image/webp',
						'    RewriteCond %{REQUEST_FILENAME}.webp -f',
						'    RewriteRule ^(.+)\.(jpe?g|png)$ $1.webp [T=image/webp,E=accept:1]',
						'    # Fallback: wppo/ directory mirror for WebP',
						'    RewriteCond %{HTTP:Accept} image/webp',
						'    RewriteCond %{DOCUMENT_ROOT}/wp-content/wppo%{REQUEST_URI}.webp -f',
						'    RewriteRule ^wp-content/(.+)\.(jpe?g|png)$ /wp-content/wppo/$1.webp [T=image/webp,E=accept:1]',
						'</IfModule>',
						'<IfModule mod_headers.c>',
						'    Header append Vary Accept',
						'</IfModule>',
						'AddType image/webp .webp',
						'AddType image/avif .avif',
					)
				);

				/**
				 * Filter the next-gen htaccess block.
				 *
				 * @since 2.0.0
				 * @param bool $use_nextgen Whether next-gen block was added.
				 */
				$rules = (array) apply_filters( 'wppo_htaccess_nextgen_rules', $rules );
			}

			// LS-320: Cache-Vary bridge for mobile/webp vary groups.
			// Correct wiring: Cache-Vary: ismobile,webp via env, Vary: Accept without env=accept (LSCWP htaccess.cls.php:605 parity).
			// Uses combined E=Cache-Vary:ismobile,webp to avoid overwrite when both match, plus per-group fallbacks.
			$ls_active = class_exists( 'PerformanceOptimise\Inc\LiteSpeed_Integration' ) && LiteSpeed_Integration::is_litespeed();
			if ( $ls_active ) {
				$groups = LiteSpeed_Integration::get_vary_groups();
				if ( $groups['mobile'] || $groups['webp'] ) {
					$cache_vary = array();
					$env_values = array();
					$rules[]    = '';
					$rules[]    = '# WPPO LS-320 Cache-Vary bridge (mobile/webp) — Cache-Vary: ismobile,webp (LSCWP htaccess.cls.php:605)';
					$rules[]    = '<IfModule mod_rewrite.c>';
					$rules[]    = '    RewriteEngine On';
					if ( $groups['mobile'] && $groups['webp'] ) {
						$rules[]    = '    # Both mobile & webp — combined vary (prevents overwrite)';
						$rules[]    = '    RewriteCond %{HTTP_USER_AGENT} "Mobile|Android|Silk|Kindle|BlackBerry|Opera Mini|Opera Mobi" [NC]';
						$rules[]    = '    RewriteCond %{HTTP:Accept} image/webp [NC]';
						$rules[]    = '    RewriteRule .* - [E=Cache-Vary:ismobile,webp]';
						$rules[]    = '    # Mobile only';
						$rules[]    = '    RewriteCond %{HTTP_USER_AGENT} "Mobile|Android|Silk|Kindle|BlackBerry|Opera Mini|Opera Mobi" [NC]';
						$rules[]    = '    RewriteCond %{HTTP:Accept} !image/webp [NC]';
						$rules[]    = '    RewriteRule .* - [E=Cache-Vary:ismobile]';
						$rules[]    = '    # WebP only';
						$rules[]    = '    RewriteCond %{HTTP_USER_AGENT} !"Mobile|Android|Silk|Kindle|BlackBerry|Opera Mini|Opera Mobi" [NC]';
						$rules[]    = '    RewriteCond %{HTTP:Accept} image/webp [NC]';
						$rules[]    = '    RewriteRule .* - [E=Cache-Vary:webp]';
						$cache_vary = array( 'ismobile', 'webp' );
						$env_values = array( 'ismobile', 'webp' );
					} else {
						if ( $groups['mobile'] ) {
							$rules[]      = '    # Mobile detection — set Cache-Vary env for LSWS';
							$rules[]      = '    RewriteCond %{HTTP_USER_AGENT} "Mobile|Android|Silk|Kindle|BlackBerry|Opera Mini|Opera Mobi" [NC]';
							$rules[]      = '    RewriteRule .* - [E=Cache-Vary:ismobile]';
							$cache_vary[] = 'ismobile';
							$env_values[] = 'ismobile';
						}
						if ( $groups['webp'] ) {
							$rules[]      = '    # WebP detection — set Cache-Vary env for LSWS';
							$rules[]      = '    RewriteCond %{HTTP:Accept} image/webp [NC]';
							$rules[]      = '    RewriteRule .* - [E=Cache-Vary:webp]';
							$cache_vary[] = 'webp';
							$env_values[] = 'webp';
						}
					}
					$rules[] = '</IfModule>';
					/**
					 * Filter Cache-Vary htaccess rules.
					 *
					 * @since 2.0.0
					 * @param array $cache_vary Active Cache-Vary groups.
					 */
					$rules = (array) apply_filters( 'wppo_htaccess_cache_vary_rules', $rules, $cache_vary );
				}
			}

			/**
			 * Filter htaccess rules.
			 *
			 * @since 2.0.0
			 * @param array $rules Htaccess rules.
			 */
			$rules = (array) apply_filters( 'wppo_htaccess_rules', $rules );

			return $rules;
		}
	}
}
